<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AuthorizationException;
use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Repositories\AuditoriaRepository;
use App\Repositories\OperacionNeumaticoRepository;
use App\Support\ListCriteria;
use App\Support\Transaction;
use App\Validators\OperacionNeumaticoValidator;
use PDOException;

/**
 * Operaciones de ubicación del neumático.
 *
 * Orden de bloqueo, siempre dentro de la transacción y por id ascendente:
 * 1. unidades
 * 2. neumaticos
 * 3. montajes activos de esos neumáticos
 * 4. ocupación activa de cada posición destino, por posicion_id
 *
 * No se bloquea la fila del cliente. La unidad serializa las operaciones de
 * esa unidad y el orden por id evita el deadlock entre dos unidades.
 * Los UNIQUE uk_montaje_activo_neumatico y uk_montaje_activo_posicion quedan
 * como defensa final. Duplicate (1062), lock wait (1205) y deadlock (1213)
 * se traducen a 409.
 *
 * Lectura de odómetro: si el valor enviado es mayor o igual al actual de la
 * unidad, se actualiza en la misma transacción. Si es menor, se conserva el
 * actual y el snapshot histórico se guarda solo cuando cierra un montaje sin
 * retroceder su propia lectura. Nunca se reduce kilometraje_actual ni
 * horometro_actual.
 */
final class OperacionNeumaticoService
{
    public const MENSAJE_CONCURRENCIA = 'La posición o el neumático cambió mientras realizabas la operación. Actualiza la información e inténtalo nuevamente.';

    public function __construct(
        private readonly OperacionNeumaticoRepository $operaciones,
        private readonly AuditoriaRepository $auditoria,
        private readonly AuthorizationService $authorization,
        private readonly OperacionNeumaticoValidator $validator,
        private readonly Transaction $transaction,
    ) {
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function montar(int $usuarioId, array $input, Request $request): array
    {
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::OPERACION);
        $data = $this->validator->montaje($input);
        $this->exigirNeumatico($usuarioId, $data['neumatico_id']);
        $this->exigirUnidad($usuarioId, $data['unidad_id']);

        return $this->transaccion(function () use ($usuarioId, $data, $request): array {
            $unidad = $this->unidadBloqueada($data['unidad_id']);
            $neumatico = $this->neumaticoBloqueado($data['neumatico_id']);
            $this->mismoCliente($unidad, $neumatico, 'unidad_id');
            $this->unidadOperable($unidad, true);
            $this->clienteActivo((int) $unidad['cliente_id']);
            $this->exigirDisponible($neumatico);
            $ocupado = $this->operaciones->bloquearMontajeActivoNeumatico((int) $neumatico['id']);
            if ($ocupado !== null) {
                throw new ConflictException(self::MENSAJE_CONCURRENCIA);
            }
            $posicion = $this->posicionDeUnidad($data['posicion_id'], $unidad, 'posicion_id');
            $slot = $this->operaciones->bloquearOcupacion((int) $unidad['id'], (int) $posicion['id']);
            if ($slot !== null) {
                throw new ConflictException(self::MENSAJE_CONCURRENCIA);
            }

            $grupo = $this->grupo();
            $montajeId = $this->operaciones->crearMontaje([
                'cliente_id' => (int) $unidad['cliente_id'],
                'neumatico_id' => (int) $neumatico['id'],
                'unidad_id' => (int) $unidad['id'],
                'configuracion_id' => (int) $unidad['configuracion_id'],
                'posicion_id' => (int) $posicion['id'],
                'fecha_montaje' => $data['fecha_montaje'],
                'km_montaje' => $data['km_montaje'],
                'horometro_montaje' => $data['horometro_montaje'],
                'usuario_montaje_id' => $usuarioId,
            ]);
            $movimientoId = $this->operaciones->crearMovimiento([
                'cliente_id' => (int) $unidad['cliente_id'],
                'neumatico_id' => (int) $neumatico['id'],
                'tipo_movimiento_id' => $this->operaciones->tipoMovimientoId('MONTAJE'),
                'fecha' => $data['fecha_montaje'],
                'unidad_origen_id' => null,
                'posicion_origen_id' => null,
                'unidad_destino_id' => (int) $unidad['id'],
                'posicion_destino_id' => (int) $posicion['id'],
                'km_unidad' => $data['km_montaje'],
                'horometro_unidad' => $data['horometro_montaje'],
                'montaje_id' => $montajeId,
                'grupo_operacion' => $grupo,
                'observacion' => $data['observacion'],
                'usuario_id' => $usuarioId,
            ]);
            $montado = $this->operaciones->estadoId('MONTADO');
            $this->operaciones->cambiarEstado((int) $neumatico['id'], $montado, $usuarioId);
            $this->operaciones->historialEstado(
                (int) $unidad['cliente_id'],
                (int) $neumatico['id'],
                (int) $neumatico['estado_id'],
                $montado,
                $movimientoId,
                $usuarioId,
                $data['fecha_montaje'],
                'Montaje en ' . $unidad['id'] . ' / ' . $posicion['codigo'],
            );
            $this->aplicarLectura($unidad, $data['km_montaje'], $data['horometro_montaje']);
            $this->auditar($request, $usuarioId, (int) $unidad['cliente_id'], (int) $neumatico['id'], 'NEUMATICO_MONTAJE', [
                'montaje_id' => $montajeId,
                'movimiento_id' => $movimientoId,
                'grupo_operacion' => $grupo,
                'unidad_id' => (int) $unidad['id'],
                'posicion_id' => (int) $posicion['id'],
            ]);

            return [
                'id' => $montajeId,
                'movimiento_id' => $movimientoId,
                'grupo_operacion' => $grupo,
                'neumatico_id' => (int) $neumatico['id'],
                'unidad_id' => (int) $unidad['id'],
                'posicion_id' => (int) $posicion['id'],
                'estado' => 'MONTADO',
            ];
        });
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function desmontar(int $usuarioId, int $montajeId, array $input, Request $request): array
    {
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::OPERACION);
        $data = $this->validator->desmontaje($input);
        $previo = $this->exigirMontaje($usuarioId, $montajeId);

        return $this->transaccion(function () use ($usuarioId, $montajeId, $data, $request, $previo): array {
            $unidad = $this->unidadBloqueada((int) $previo['unidad_id']);
            $neumatico = $this->neumaticoBloqueado((int) $previo['neumatico_id']);
            $montaje = $this->operaciones->bloquearMontaje($montajeId);
            if ($montaje === null || (int) $montaje['anulado'] === 1 || $montaje['fecha_desmontaje'] !== null) {
                throw new ConflictException('El montaje ya no está activo.');
            }
            $this->mismoCliente($unidad, $neumatico, 'neumatico_id');
            if ((string) $neumatico['estado_codigo'] !== 'MONTADO') {
                throw new ConflictException('El neumático no está montado.');
            }
            $this->lecturaDeCierre($montaje, $data['fecha_desmontaje'], $data['km_desmontaje'], $data['horometro_desmontaje'], 'fecha_desmontaje', 'km_desmontaje', 'horometro_desmontaje');
            $this->operaciones->cerrarMontaje($montajeId, [
                'fecha' => $data['fecha_desmontaje'],
                'km' => $data['km_desmontaje'],
                'horometro' => $data['horometro_desmontaje'],
                'motivo' => $data['motivo_desmontaje'],
                'usuario_id' => $usuarioId,
            ]);
            $grupo = $this->grupo();
            $movimientoId = $this->operaciones->crearMovimiento([
                'cliente_id' => (int) $montaje['cliente_id'],
                'neumatico_id' => (int) $neumatico['id'],
                'tipo_movimiento_id' => $this->operaciones->tipoMovimientoId('DESMONTAJE'),
                'fecha' => $data['fecha_desmontaje'],
                'unidad_origen_id' => (int) $montaje['unidad_id'],
                'posicion_origen_id' => (int) $montaje['posicion_id'],
                'unidad_destino_id' => null,
                'posicion_destino_id' => null,
                'km_unidad' => $data['km_desmontaje'],
                'horometro_unidad' => $data['horometro_desmontaje'],
                'montaje_id' => $montajeId,
                'grupo_operacion' => $grupo,
                'observacion' => $data['motivo_desmontaje'],
                'usuario_id' => $usuarioId,
            ]);
            $disponible = $this->operaciones->estadoId('DISPONIBLE');
            $this->operaciones->cambiarEstado((int) $neumatico['id'], $disponible, $usuarioId);
            $this->operaciones->historialEstado(
                (int) $montaje['cliente_id'],
                (int) $neumatico['id'],
                (int) $neumatico['estado_id'],
                $disponible,
                $movimientoId,
                $usuarioId,
                $data['fecha_desmontaje'],
                $data['motivo_desmontaje'],
            );
            $this->aplicarLectura($unidad, $data['km_desmontaje'], $data['horometro_desmontaje']);
            $this->auditar($request, $usuarioId, (int) $montaje['cliente_id'], (int) $neumatico['id'], 'NEUMATICO_DESMONTAJE', [
                'montaje_id' => $montajeId,
                'movimiento_id' => $movimientoId,
                'grupo_operacion' => $grupo,
            ]);

            return [
                'id' => $montajeId,
                'movimiento_id' => $movimientoId,
                'grupo_operacion' => $grupo,
                'neumatico_id' => (int) $neumatico['id'],
                'estado' => 'DISPONIBLE',
                'activo' => false,
            ];
        });
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function rotar(int $usuarioId, array $input, Request $request): array
    {
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::OPERACION);
        $data = $this->validator->rotacion($input);
        $this->exigirUnidad($usuarioId, $data['unidad_id']);
        foreach ($data['cambios'] as $cambio) {
            $this->exigirNeumatico($usuarioId, $cambio['neumatico_id']);
        }

        return $this->transaccion(function () use ($usuarioId, $data, $request): array {
            $unidad = $this->unidadBloqueada($data['unidad_id']);
            $this->unidadOperable($unidad, true);
            $this->clienteActivo((int) $unidad['cliente_id']);
            $ids = array_map(static fn (array $cambio): int => $cambio['neumatico_id'], $data['cambios']);
            sort($ids, SORT_NUMERIC);
            $neumaticos = [];
            foreach ($ids as $id) {
                $neumaticos[$id] = $this->neumaticoBloqueado($id);
            }
            $montajes = [];
            foreach ($ids as $id) {
                $activo = $this->operaciones->bloquearMontajeActivoNeumatico($id);
                if ($activo === null) {
                    throw new ConflictException(self::MENSAJE_CONCURRENCIA);
                }
                $montajes[$id] = $activo;
            }
            $destinos = array_map(static fn (array $cambio): int => $cambio['posicion_destino_id'], $data['cambios']);
            sort($destinos, SORT_NUMERIC);
            $ocupacion = [];
            foreach ($destinos as $posicionId) {
                $ocupacion[$posicionId] = $this->operaciones->bloquearOcupacion((int) $unidad['id'], $posicionId);
            }
            $permitidos = array_fill_keys($ids, true);
            $grupo = $this->grupo();
            $resultado = [];
            foreach ($data['cambios'] as $cambio) {
                $neumatico = $neumaticos[$cambio['neumatico_id']];
                $montaje = $montajes[$cambio['neumatico_id']];
                $this->mismoCliente($unidad, $neumatico, 'unidad_id');
                if ((string) $neumatico['estado_codigo'] !== 'MONTADO' || (int) $montaje['unidad_id'] !== (int) $unidad['id']) {
                    throw new ValidationException('Los datos enviados no son válidos.', [
                        'cambios' => ['Todos los neumáticos deben estar montados en la unidad indicada.'],
                    ]);
                }
                $posicion = $this->posicionDeUnidad($cambio['posicion_destino_id'], $unidad, 'cambios');
                $ajeno = $ocupacion[(int) $posicion['id']];
                if ($ajeno !== null && !isset($permitidos[(int) $ajeno['neumatico_id']])) {
                    throw new ConflictException('La posición destino está ocupada por un neumático que no forma parte de la rotación.');
                }
                $this->lecturaDeCierre($montaje, $data['fecha'], $data['km_unidad'], $data['horometro_unidad'], 'fecha', 'km_unidad', 'horometro_unidad');
                $resultado[] = ['neumatico' => $neumatico, 'montaje' => $montaje, 'posicion' => $posicion];
            }

            $nuevos = [];
            $movimientos = [];
            foreach ($resultado as $item) {
                $this->operaciones->cerrarMontaje((int) $item['montaje']['id'], [
                    'fecha' => $data['fecha'],
                    'km' => $data['km_unidad'],
                    'horometro' => $data['horometro_unidad'],
                    'motivo' => $this->motivo('Rotación', $data['observacion']),
                    'usuario_id' => $usuarioId,
                ]);
            }
            foreach ($resultado as $item) {
                $montajeId = $this->operaciones->crearMontaje([
                    'cliente_id' => (int) $unidad['cliente_id'],
                    'neumatico_id' => (int) $item['neumatico']['id'],
                    'unidad_id' => (int) $unidad['id'],
                    'configuracion_id' => (int) $unidad['configuracion_id'],
                    'posicion_id' => (int) $item['posicion']['id'],
                    'fecha_montaje' => $data['fecha'],
                    'km_montaje' => $data['km_unidad'],
                    'horometro_montaje' => $data['horometro_unidad'],
                    'usuario_montaje_id' => $usuarioId,
                ]);
                $movimientoId = $this->operaciones->crearMovimiento([
                    'cliente_id' => (int) $unidad['cliente_id'],
                    'neumatico_id' => (int) $item['neumatico']['id'],
                    'tipo_movimiento_id' => $this->operaciones->tipoMovimientoId('ROTACION'),
                    'fecha' => $data['fecha'],
                    'unidad_origen_id' => (int) $item['montaje']['unidad_id'],
                    'posicion_origen_id' => (int) $item['montaje']['posicion_id'],
                    'unidad_destino_id' => (int) $unidad['id'],
                    'posicion_destino_id' => (int) $item['posicion']['id'],
                    'km_unidad' => $data['km_unidad'],
                    'horometro_unidad' => $data['horometro_unidad'],
                    'montaje_id' => $montajeId,
                    'grupo_operacion' => $grupo,
                    'observacion' => $data['observacion'],
                    'usuario_id' => $usuarioId,
                ]);
                $this->auditar($request, $usuarioId, (int) $unidad['cliente_id'], (int) $item['neumatico']['id'], 'NEUMATICO_ROTACION', [
                    'grupo_operacion' => $grupo,
                    'montaje_id' => $montajeId,
                    'movimiento_id' => $movimientoId,
                    'posicion_origen_id' => (int) $item['montaje']['posicion_id'],
                    'posicion_destino_id' => (int) $item['posicion']['id'],
                ]);
                $nuevos[] = $montajeId;
                $movimientos[] = $movimientoId;
            }
            $this->aplicarLectura($unidad, $data['km_unidad'], $data['horometro_unidad']);

            return [
                'grupo_operacion' => $grupo,
                'montajes' => $nuevos,
                'movimientos' => $movimientos,
                'estado' => 'MONTADO',
            ];
        });
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function transferir(int $usuarioId, array $input, Request $request): array
    {
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::OPERACION);
        $data = $this->validator->transferencia($input);
        $this->exigirNeumatico($usuarioId, $data['neumatico_id']);
        $this->exigirUnidad($usuarioId, $data['unidad_destino_id']);

        return $this->transaccion(function () use ($usuarioId, $data, $request): array {
            $vista = $this->operaciones->montajeActivoNeumatico($data['neumatico_id']);
            if ($vista === null) {
                throw new ConflictException(self::MENSAJE_CONCURRENCIA);
            }
            $unidades = [(int) $vista['unidad_id'], $data['unidad_destino_id']];
            sort($unidades, SORT_NUMERIC);
            $bloqueadas = [];
            foreach (array_values(array_unique($unidades)) as $unidadId) {
                $bloqueadas[$unidadId] = $this->unidadBloqueada($unidadId);
            }
            $neumatico = $this->neumaticoBloqueado($data['neumatico_id']);
            $montaje = $this->operaciones->bloquearMontajeActivoNeumatico($data['neumatico_id']);
            if ($montaje === null || !isset($bloqueadas[(int) $montaje['unidad_id']])) {
                throw new ConflictException(self::MENSAJE_CONCURRENCIA);
            }
            $origen = $bloqueadas[(int) $montaje['unidad_id']];
            $destino = $bloqueadas[$data['unidad_destino_id']];
            $this->mismoCliente($origen, $neumatico, 'neumatico_id');
            $this->mismoCliente($destino, $neumatico, 'unidad_destino_id');
            $this->unidadOperable($destino, true);
            $this->clienteActivo((int) $destino['cliente_id']);
            if ((string) $neumatico['estado_codigo'] !== 'MONTADO') {
                throw new ConflictException('El neumático no está montado.');
            }
            if ((int) $origen['id'] === (int) $destino['id'] && (int) $montaje['posicion_id'] === $data['posicion_destino_id']) {
                throw new ValidationException('Los datos enviados no son válidos.', [
                    'posicion_destino_id' => ['El destino no puede ser la misma ubicación actual.'],
                ]);
            }
            $posicion = $this->posicionDeUnidad($data['posicion_destino_id'], $destino, 'posicion_destino_id');
            $slot = $this->operaciones->bloquearOcupacion((int) $destino['id'], (int) $posicion['id']);
            if ($slot !== null) {
                throw new ConflictException(self::MENSAJE_CONCURRENCIA);
            }
            $this->lecturaDeCierre($montaje, $data['fecha'], $data['km_unidad'], $data['horometro_unidad'], 'fecha', 'km_unidad', 'horometro_unidad');
            $this->operaciones->cerrarMontaje((int) $montaje['id'], [
                'fecha' => $data['fecha'],
                'km' => $data['km_unidad'],
                'horometro' => $data['horometro_unidad'],
                'motivo' => $this->motivo('Transferencia', $data['observacion']),
                'usuario_id' => $usuarioId,
            ]);
            $grupo = $this->grupo();
            $montajeId = $this->operaciones->crearMontaje([
                'cliente_id' => (int) $destino['cliente_id'],
                'neumatico_id' => (int) $neumatico['id'],
                'unidad_id' => (int) $destino['id'],
                'configuracion_id' => (int) $destino['configuracion_id'],
                'posicion_id' => (int) $posicion['id'],
                'fecha_montaje' => $data['fecha'],
                'km_montaje' => $data['km_unidad'],
                'horometro_montaje' => $data['horometro_unidad'],
                'usuario_montaje_id' => $usuarioId,
            ]);
            $movimientoId = $this->operaciones->crearMovimiento([
                'cliente_id' => (int) $destino['cliente_id'],
                'neumatico_id' => (int) $neumatico['id'],
                'tipo_movimiento_id' => $this->operaciones->tipoMovimientoId('TRANSFERENCIA'),
                'fecha' => $data['fecha'],
                'unidad_origen_id' => (int) $origen['id'],
                'posicion_origen_id' => (int) $montaje['posicion_id'],
                'unidad_destino_id' => (int) $destino['id'],
                'posicion_destino_id' => (int) $posicion['id'],
                'km_unidad' => $data['km_unidad'],
                'horometro_unidad' => $data['horometro_unidad'],
                'montaje_id' => $montajeId,
                'grupo_operacion' => $grupo,
                'observacion' => $data['observacion'],
                'usuario_id' => $usuarioId,
            ]);
            $this->aplicarLectura($origen, $data['km_unidad'], $data['horometro_unidad']);
            if ((int) $destino['id'] !== (int) $origen['id']) {
                $this->aplicarLectura($destino, $data['km_unidad'], $data['horometro_unidad']);
            }
            $this->auditar($request, $usuarioId, (int) $destino['cliente_id'], (int) $neumatico['id'], 'NEUMATICO_TRANSFERENCIA', [
                'grupo_operacion' => $grupo,
                'montaje_id' => $montajeId,
                'movimiento_id' => $movimientoId,
                'unidad_origen_id' => (int) $origen['id'],
                'posicion_origen_id' => (int) $montaje['posicion_id'],
                'unidad_destino_id' => (int) $destino['id'],
                'posicion_destino_id' => (int) $posicion['id'],
            ]);

            return [
                'id' => $montajeId,
                'movimiento_id' => $movimientoId,
                'grupo_operacion' => $grupo,
                'neumatico_id' => (int) $neumatico['id'],
                'unidad_origen_id' => (int) $origen['id'],
                'posicion_origen_id' => (int) $montaje['posicion_id'],
                'unidad_destino_id' => (int) $destino['id'],
                'posicion_destino_id' => (int) $posicion['id'],
                'estado' => 'MONTADO',
            ];
        });
    }

    /** @param array<string, mixed> $query @return array{rows:list<array<string,mixed>>,total:int} */
    public function listarMovimientos(int $usuarioId, array $query): array
    {
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::LECTURA);

        return $this->operaciones->movimientos($this->criterios($query), $this->filtros($usuarioId, $query));
    }

    /** @param array<string, mixed> $query @return array{rows:list<array<string,mixed>>,total:int} */
    public function movimientosNeumatico(int $usuarioId, int $neumaticoId, array $query): array
    {
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::LECTURA);
        $this->exigirNeumatico($usuarioId, $neumaticoId);
        $query['neumatico_id'] = (string) $neumaticoId;

        return $this->operaciones->movimientos($this->criterios($query), $this->filtros($usuarioId, $query));
    }

    /** @param array<string, mixed> $query @return array{rows:list<array<string,mixed>>,total:int} */
    public function movimientosUnidad(int $usuarioId, int $unidadId, array $query): array
    {
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::LECTURA);
        $this->exigirUnidad($usuarioId, $unidadId);
        $query['unidad_id'] = (string) $unidadId;

        return $this->operaciones->movimientos($this->criterios($query), $this->filtros($usuarioId, $query));
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function montajesActivos(int $usuarioId, int $unidadId): array
    {
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::LECTURA);
        $this->exigirUnidad($usuarioId, $unidadId);
        $rows = $this->operaciones->montajesActivos($unidadId);

        return ['rows' => $rows, 'total' => count($rows)];
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function montajesNeumatico(int $usuarioId, int $neumaticoId): array
    {
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::LECTURA);
        $this->exigirNeumatico($usuarioId, $neumaticoId);
        $rows = $this->operaciones->montajesNeumatico($neumaticoId);

        return ['rows' => $rows, 'total' => count($rows)];
    }

    /** @param array<string, mixed> $query @return array<string, mixed> */
    private function filtros(int $usuarioId, array $query): array
    {
        $cliente = $this->enteroFiltro($query, 'cliente_id', 'El cliente');
        if ($cliente !== null) {
            $this->authorization->requireClientAccess($usuarioId, $cliente);
        }
        $neumatico = $this->enteroFiltro($query, 'neumatico_id', 'El neumático');
        if ($neumatico !== null) {
            $this->exigirNeumatico($usuarioId, $neumatico);
        }
        $unidad = $this->enteroFiltro($query, 'unidad_id', 'La unidad');
        if ($unidad !== null) {
            $this->exigirUnidad($usuarioId, $unidad);
        }
        $tipo = $this->enteroFiltro($query, 'tipo_movimiento_id', 'El tipo de movimiento');
        if ($tipo !== null && !$this->operaciones->tipoMovimientoExiste($tipo)) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'tipo_movimiento_id' => ['El tipo de movimiento no existe.'],
            ]);
        }
        $desde = $this->fechaFiltro($query, 'fecha_inicio', false);
        $hasta = $this->fechaFiltro($query, 'fecha_fin', true);
        if ($desde !== null && $hasta !== null && $hasta < $desde) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'fecha_fin' => ['La fecha final no puede ser anterior a la inicial.'],
            ]);
        }
        $grupo = null;
        if (isset($query['grupo_operacion']) && trim((string) $query['grupo_operacion']) !== '') {
            $grupo = trim((string) $query['grupo_operacion']);
            if (preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $grupo) !== 1) {
                throw new ValidationException('Los datos enviados no son válidos.', [
                    'grupo_operacion' => ['El grupo de operación no es válido.'],
                ]);
            }
        }

        return [
            'cliente' => $cliente,
            'neumatico' => $neumatico,
            'unidad' => $unidad,
            'tipo' => $tipo,
            'desde' => $desde,
            'hasta' => $hasta,
            'grupo' => $grupo,
            'usuario' => $this->authorization->isAdminGeneral($usuarioId) ? null : $usuarioId,
        ];
    }

    /** @param array<string, mixed> $query */
    private function criterios(array $query): ListCriteria
    {
        if (!isset($query['sort']) || $query['sort'] === '') {
            $query['sort'] = 'fecha';
        }
        if (!isset($query['order']) || $query['order'] === '') {
            $query['order'] = 'desc';
        }

        return ListCriteria::from($query, [
            'fecha' => 'm.fecha',
            'id' => 'm.id',
        ]);
    }

    /** @return array<string, mixed> */
    private function exigirNeumatico(int $usuarioId, int $id): array
    {
        $row = $this->operaciones->neumatico($id);
        if ($row === null || (int) $row['eliminado'] === 1) {
            if ($this->authorization->isAdminGeneral($usuarioId)) {
                throw new NotFoundException('Neumático no encontrado.');
            }
            throw new AuthorizationException('No tiene acceso a este neumático.', 'CLIENT_SCOPE_FORBIDDEN');
        }
        $this->authorization->requireClientAccess($usuarioId, (int) $row['cliente_id']);

        return $row;
    }

    /** @return array<string, mixed> */
    private function exigirUnidad(int $usuarioId, int $id): array
    {
        $row = $this->operaciones->unidad($id);
        if ($row === null || (int) $row['eliminado'] === 1) {
            if ($this->authorization->isAdminGeneral($usuarioId)) {
                throw new NotFoundException('Unidad no encontrada.');
            }
            throw new AuthorizationException('No tiene acceso a esta unidad.', 'CLIENT_SCOPE_FORBIDDEN');
        }
        $this->authorization->requireClientAccess($usuarioId, (int) $row['cliente_id']);

        return $row;
    }

    /** @return array<string, mixed> */
    private function exigirMontaje(int $usuarioId, int $id): array
    {
        $row = $this->operaciones->montaje($id);
        if ($row === null) {
            if ($this->authorization->isAdminGeneral($usuarioId)) {
                throw new NotFoundException('Montaje no encontrado.');
            }
            throw new AuthorizationException('No tiene acceso a este montaje.', 'CLIENT_SCOPE_FORBIDDEN');
        }
        $this->authorization->requireClientAccess($usuarioId, (int) $row['cliente_id']);

        return $row;
    }

    /** @return array<string, mixed> */
    private function unidadBloqueada(int $id): array
    {
        $row = $this->operaciones->bloquearUnidad($id);
        if ($row === null || (int) $row['eliminado'] === 1) {
            throw new NotFoundException('Unidad no encontrada.');
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private function neumaticoBloqueado(int $id): array
    {
        $row = $this->operaciones->bloquearNeumatico($id);
        if ($row === null || (int) $row['eliminado'] === 1) {
            throw new NotFoundException('Neumático no encontrado.');
        }

        return $row;
    }

    /** @param array<string, mixed> $unidad @param array<string, mixed> $neumatico */
    private function mismoCliente(array $unidad, array $neumatico, string $campo): void
    {
        if ((int) $unidad['cliente_id'] !== (int) $neumatico['cliente_id']) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                $campo => ['El neumático y la unidad deben pertenecer al mismo cliente.'],
            ]);
        }
    }

    /** @param array<string, mixed> $unidad */
    private function unidadOperable(array $unidad, bool $exigeConfiguracion): void
    {
        if ((string) $unidad['estado'] !== 'OPERATIVA') {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'unidad_id' => ['La unidad debe estar operativa.'],
            ]);
        }
        if ($exigeConfiguracion && ($unidad['configuracion_id'] === null || (int) $unidad['configuracion_id'] === 0)) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'unidad_id' => ['La unidad no tiene configuración.'],
            ]);
        }
    }

    private function clienteActivo(int $clienteId): void
    {
        $cliente = $this->operaciones->cliente($clienteId);
        if ($cliente === null || (int) $cliente['eliminado'] === 1 || (string) $cliente['estado'] !== 'ACTIVO') {
            throw new ConflictException('Solo un cliente activo admite esta operación.');
        }
    }

    /** @param array<string, mixed> $neumatico */
    private function exigirDisponible(array $neumatico): void
    {
        if ((string) $neumatico['estado_codigo'] !== 'DISPONIBLE') {
            throw new ConflictException('El neumático no está disponible para montaje.');
        }
    }

    /** @param array<string, mixed> $unidad @return array<string, mixed> */
    private function posicionDeUnidad(int $posicionId, array $unidad, string $campo): array
    {
        $posicion = $this->operaciones->posicion($posicionId);
        if ($posicion === null || (int) $posicion['configuracion_id'] !== (int) $unidad['configuracion_id']) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                $campo => ['La posición no pertenece a la configuración actual de la unidad.'],
            ]);
        }
        if ((int) $posicion['activo'] !== 1) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                $campo => ['La posición no está activa.'],
            ]);
        }

        return $posicion;
    }

    /** @param array<string, mixed> $montaje */
    private function lecturaDeCierre(
        array $montaje,
        string $fecha,
        ?int $km,
        ?string $horometro,
        string $campoFecha,
        string $campoKm,
        string $campoHorometro,
    ): void {
        $errores = [];
        if ($fecha < (string) $montaje['fecha_montaje']) {
            $errores[$campoFecha][] = 'La fecha no puede ser anterior al montaje activo.';
        }
        if ($km !== null && $montaje['km_montaje'] !== null && $km < (int) $montaje['km_montaje']) {
            $errores[$campoKm][] = 'La lectura no puede ser menor que la del montaje activo.';
        }
        if ($horometro !== null && $montaje['horometro_montaje'] !== null) {
            $anterior = number_format((float) $montaje['horometro_montaje'], 2, '.', '');
            if ($this->compararDecimal($horometro, $anterior) < 0) {
                $errores[$campoHorometro][] = 'La lectura no puede ser menor que la del montaje activo.';
            }
        }
        if ($errores !== []) {
            throw new ValidationException('Los datos enviados no son válidos.', $errores);
        }
    }

    /** @param array<string, mixed> $unidad */
    private function aplicarLectura(array $unidad, ?int $km, ?string $horometro): void
    {
        $actualKm = $unidad['kilometraje_actual'] === null ? null : (int) $unidad['kilometraje_actual'];
        $nuevoKm = $actualKm;
        if ($km !== null && ($actualKm === null || $km >= $actualKm)) {
            $nuevoKm = $km;
        }
        $actualHorometro = $unidad['horometro_actual'] === null ? null : number_format((float) $unidad['horometro_actual'], 2, '.', '');
        $nuevoHorometro = $actualHorometro;
        if ($horometro !== null && ($actualHorometro === null || $this->compararDecimal($horometro, $actualHorometro) >= 0)) {
            $nuevoHorometro = $horometro;
        }
        if ($nuevoKm === $actualKm && $nuevoHorometro === $actualHorometro) {
            return;
        }
        $this->operaciones->actualizarLectura((int) $unidad['id'], $nuevoKm, $nuevoHorometro);
    }

    private function compararDecimal(string $izquierda, string $derecha): int
    {
        if (function_exists('bccomp')) {
            return bccomp($izquierda, $derecha, 2);
        }

        return $izquierda <=> $derecha;
    }

    private function motivo(string $base, ?string $observacion): string
    {
        $texto = $observacion !== null && $observacion !== '' ? $observacion : $base;

        return mb_substr($texto, 0, 255);
    }

    private function grupo(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }

    /** @param array<string, mixed> $despues */
    private function auditar(Request $request, int $usuarioId, int $clienteId, int $neumaticoId, string $accion, array $despues): void
    {
        $this->auditoria->registrar(
            $clienteId,
            $usuarioId,
            'neumaticos',
            $neumaticoId,
            $accion,
            null,
            $despues,
            $request->ip(),
            $request->userAgent(),
        );
    }

    private function transaccion(callable $callback): mixed
    {
        try {
            return $this->transaction->run($callback);
        } catch (PDOException $error) {
            $codigo = (int) ($error->errorInfo[1] ?? 0);
            if (in_array($codigo, [1062, 1205, 1213], true)) {
                throw new ConflictException(self::MENSAJE_CONCURRENCIA);
            }
            if (in_array($codigo, [3819, 4025], true)) {
                throw new ValidationException('Los datos enviados no son válidos.', [
                    'operacion' => ['La operación no cumple las reglas de fecha o lectura.'],
                ]);
            }
            throw $error;
        }
    }

    /** @param array<string, mixed> $query */
    private function enteroFiltro(array $query, string $campo, string $etiqueta): ?int
    {
        if (!isset($query[$campo]) || trim((string) $query[$campo]) === '') {
            return null;
        }
        $texto = trim((string) $query[$campo]);
        if (preg_match('/^[1-9][0-9]*$/', $texto) !== 1) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                $campo => [$etiqueta . ' no es válido.'],
            ]);
        }

        return (int) $texto;
    }

    /** @param array<string, mixed> $query */
    private function fechaFiltro(array $query, string $campo, bool $finDeDia): ?string
    {
        if (!isset($query[$campo]) || trim((string) $query[$campo]) === '') {
            return null;
        }
        $texto = trim((string) $query[$campo]);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $texto) === 1) {
            return $texto . ($finDeDia ? ' 23:59:59' : ' 00:00:00');
        }
        $normalizada = str_replace('T', ' ', $texto);
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $normalizada) === 1) {
            $normalizada .= ':00';
        }
        $fecha = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $normalizada);
        if (!$fecha instanceof \DateTimeImmutable || $fecha->format('Y-m-d H:i:s') !== $normalizada) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                $campo => ['La fecha no es válida.'],
            ]);
        }

        return $normalizada;
    }
}
