<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AuthorizationException;
use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Repositories\AuditoriaRepository;
use App\Repositories\InspeccionRepository;
use App\Repositories\MantenimientoRepository;
use App\Repositories\OperacionNeumaticoRepository;
use App\Support\ArchivoAlmacen;
use App\Support\ListCriteria;
use App\Support\Transaction;
use App\Validators\MantenimientoValidator;
use PDOException;

final class MantenimientoService
{
    public function __construct(
        private readonly MantenimientoRepository $mantenimientos,
        private readonly OperacionNeumaticoRepository $operaciones,
        private readonly InspeccionRepository $archivos,
        private readonly AuditoriaRepository $auditoria,
        private readonly AuthorizationService $authorization,
        private readonly MantenimientoValidator $validator,
        private readonly Transaction $transaction,
        private readonly ArchivoAlmacen $almacen,
        private readonly int $maxBytes,
    ) {
    }

    /** @return array<string, mixed> */
    public function crear(Request $request): array
    {
        $usuarioId = (int) $request->userId();
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::OPERACION);
        $datos = $this->validator->crear($request->json() ?? []);
        $previo = $this->exigirNeumatico($usuarioId, $datos['neumatico_id']);
        $tipo = $this->mantenimientos->tipo($datos['tipo_mantenimiento_id']);
        if ($tipo === null || (int) $tipo['activo'] !== 1) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'tipo_mantenimiento_id' => ['El tipo de mantenimiento no está disponible.'],
            ]);
        }
        $id = $this->transaccion(function () use ($request, $usuarioId, $datos, $previo): int {
            $neumatico = $this->exigirDisponibleBloqueado((int) $previo['id']);
            $estadoId = $this->mantenimientos->estadoId('SOLICITADO');
            $id = $this->mantenimientos->crear([
                'cliente_id' => (int) $neumatico['cliente_id'],
                'neumatico_id' => (int) $neumatico['id'],
                'tipo_mantenimiento_id' => $datos['tipo_mantenimiento_id'],
                'estado_id' => $estadoId,
                'fecha_solicitud' => $datos['fecha_solicitud'],
                'tercero_nombre' => $datos['tercero_nombre'],
                'costo' => $datos['costo'],
                'moneda' => $datos['moneda'],
                'profundidad_antes_mm' => $datos['profundidad_antes_mm'],
                'observacion' => $datos['observacion'],
                'creado_por' => $usuarioId,
            ]);
            $this->mantenimientos->historial($id, null, $estadoId, $usuarioId, $datos['observacion'], $datos['fecha_solicitud']);
            $this->auditar($request, (int) $neumatico['cliente_id'], $usuarioId, 'mantenimientos_neumatico', $id, 'MANTENIMIENTO_CREATE', null, [
                'neumatico_id' => (int) $neumatico['id'],
                'estado' => 'SOLICITADO',
            ]);

            return $id;
        });

        return $this->detalle($request, $id);
    }

    /** @return array{rows:list<array<string, mixed>>, total:int} */
    public function listar(Request $request, ?int $neumaticoId = null): array
    {
        $usuarioId = (int) $request->userId();
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::LECTURA);
        if ($neumaticoId !== null) {
            $this->exigirNeumatico($usuarioId, $neumaticoId);
        }
        $resultado = $this->mantenimientos->listar($this->filtros($usuarioId, $request, $neumaticoId));

        return [
            'rows' => array_map(fn (array $row): array => $this->resumen($row), $resultado['rows']),
            'total' => $resultado['total'],
        ];
    }

    /** @return array<string, mixed> */
    public function detalle(Request $request, int $id): array
    {
        $usuarioId = (int) $request->userId();
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::LECTURA);
        $this->exigirVisible($usuarioId, $id);

        return $this->presentar($id);
    }

    /** @return list<array<string, mixed>> */
    public function tipos(Request $request): array
    {
        $this->authorization->requireRole((int) $request->userId(), ...ClientePolicy::LECTURA);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'codigo' => $row['codigo'],
            'nombre' => $row['nombre'],
            'descripcion' => $row['descripcion'],
        ], $this->mantenimientos->tipos());
    }

    /** @return list<array<string, mixed>> */
    public function estados(Request $request): array
    {
        $this->authorization->requireRole((int) $request->userId(), ...ClientePolicy::LECTURA);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'codigo' => $row['codigo'],
            'nombre' => $row['nombre'],
            'orden' => (int) $row['orden'],
            'es_final' => (int) $row['es_final'] === 1,
        ], $this->mantenimientos->estados());
    }

    /** @return list<array<string, mixed>> */
    public function motivos(Request $request): array
    {
        $this->authorization->requireRole((int) $request->userId(), ...ClientePolicy::LECTURA);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'codigo' => $row['codigo'],
            'nombre' => $row['nombre'],
            'descripcion' => $row['descripcion'],
        ], $this->mantenimientos->motivos());
    }

    /** @return array<string, mixed> */
    public function enviar(Request $request, int $id): array
    {
        $usuarioId = (int) $request->userId();
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::OPERACION);
        $datos = $this->validator->enviar($request->json() ?? []);
        $previo = $this->exigirVisible($usuarioId, $id);
        $this->transaccion(function () use ($request, $usuarioId, $id, $datos, $previo): void {
            $neumatico = $this->exigirDisponibleBloqueado((int) $previo['neumatico_id'], $id);
            $mantenimiento = $this->exigirMantenimientoBloqueado($id, (int) $neumatico['cliente_id']);
            if ($mantenimiento['estado_codigo'] !== 'SOLICITADO') {
                throw new ConflictException('El mantenimiento ya no está solicitado.', 'MANTENIMIENTO_ALREADY_CLOSED');
            }
            if ($datos['fecha_envio'] < $mantenimiento['fecha_solicitud']) {
                throw new ValidationException('Los datos enviados no son válidos.', [
                    'fecha_envio' => ['La fecha de envío no puede ser anterior a la solicitud.'],
                ]);
            }
            $estadoNeumatico = $mantenimiento['tipo_codigo'] === 'REENCAUCHE' ? 'EN_REENCAUCHE' : 'EN_MANTENIMIENTO';
            $enviado = $this->mantenimientos->estadoId('ENVIADO');
            if ($this->mantenimientos->marcarEnviado($id, [
                'fecha_envio' => $datos['fecha_envio'],
                'observacion' => $datos['observacion'],
                'estado_actual_id' => (int) $mantenimiento['estado_id'],
            ], $enviado, $usuarioId) !== 1) {
                throw new ConflictException('El mantenimiento ya no está solicitado.', 'MANTENIMIENTO_ALREADY_CLOSED');
            }
            $movimientoId = $this->movimiento($neumatico, 'ENVIO_MANTENIMIENTO', $datos['fecha_envio'], $id, $usuarioId, $datos['observacion']);
            $this->operaciones->cambiarEstado((int) $neumatico['id'], $this->operaciones->estadoId($estadoNeumatico), $usuarioId);
            $this->operaciones->historialEstado(
                (int) $neumatico['cliente_id'],
                (int) $neumatico['id'],
                (int) $neumatico['estado_id'],
                $this->operaciones->estadoId($estadoNeumatico),
                $movimientoId,
                $usuarioId,
                $datos['fecha_envio'],
                'Envío a ' . $mantenimiento['tipo_codigo'],
            );
            $this->mantenimientos->historial($id, (int) $mantenimiento['estado_id'], $enviado, $usuarioId, $datos['observacion'], $datos['fecha_envio']);
            $this->auditar($request, (int) $neumatico['cliente_id'], $usuarioId, 'mantenimientos_neumatico', $id, 'MANTENIMIENTO_SEND', ['estado' => 'SOLICITADO'], [
                'estado' => 'ENVIADO',
                'neumatico_estado' => $estadoNeumatico,
                'movimiento_id' => $movimientoId,
            ]);
        });

        return $this->detalle($request, $id);
    }

    /** @return array<string, mixed> */
    public function iniciar(Request $request, int $id): array
    {
        $usuarioId = (int) $request->userId();
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::OPERACION);
        $datos = $this->validator->iniciar($request->json() ?? []);
        $previo = $this->exigirVisible($usuarioId, $id);
        $this->transaccion(function () use ($request, $usuarioId, $id, $datos, $previo): void {
            $neumatico = $this->mantenimientos->bloquearNeumatico((int) $previo['neumatico_id']);
            $mantenimiento = $this->exigirMantenimientoBloqueado($id, (int) $previo['cliente_id']);
            if ($mantenimiento['estado_codigo'] !== 'ENVIADO') {
                throw new ConflictException('Solo un mantenimiento enviado puede iniciarse.', 'MANTENIMIENTO_ALREADY_CLOSED');
            }
            $proceso = $this->mantenimientos->estadoId('EN_PROCESO');
            if ($this->mantenimientos->marcarEstado($id, $proceso, (int) $mantenimiento['estado_id'], $usuarioId) !== 1) {
                throw new ConflictException('Solo un mantenimiento enviado puede iniciarse.', 'MANTENIMIENTO_ALREADY_CLOSED');
            }
            $this->mantenimientos->historial($id, (int) $mantenimiento['estado_id'], $proceso, $usuarioId, $datos['observacion']);
            $this->auditar($request, (int) $neumatico['cliente_id'], $usuarioId, 'mantenimientos_neumatico', $id, 'MANTENIMIENTO_START', ['estado' => 'ENVIADO'], ['estado' => 'EN_PROCESO']);
        });

        return $this->detalle($request, $id);
    }

    /** @return array<string, mixed> */
    public function finalizar(Request $request, int $id): array
    {
        $usuarioId = (int) $request->userId();
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::OPERACION);
        $datos = $this->validator->finalizar($request->json() ?? []);
        $previo = $this->exigirVisible($usuarioId, $id);
        $vidas = $this->transaccion(function () use ($request, $usuarioId, $id, $datos, $previo): array {
            $neumatico = $this->mantenimientos->bloquearNeumatico((int) $previo['neumatico_id']);
            $mantenimiento = $this->exigirMantenimientoBloqueado($id, (int) $neumatico['cliente_id']);
            $this->esperaDePrueba();
            $mantenimiento = $this->exigirMantenimientoBloqueado($id, (int) $neumatico['cliente_id']);
            if (!in_array($mantenimiento['estado_codigo'], ['ENVIADO', 'EN_PROCESO'], true)) {
                throw new ConflictException('El mantenimiento ya fue finalizado.', 'MANTENIMIENTO_ALREADY_FINISHED');
            }
            if ($datos['fecha_retorno'] < ($mantenimiento['fecha_envio'] ?? $mantenimiento['fecha_solicitud'])) {
                throw new ValidationException('Los datos enviados no son válidos.', [
                    'fecha_retorno' => ['La fecha de retorno no puede ser anterior al envío.'],
                ]);
            }
            $reencauche = $mantenimiento['tipo_codigo'] === 'REENCAUCHE';
            $esperado = $reencauche ? 'EN_REENCAUCHE' : 'EN_MANTENIMIENTO';
            if ($neumatico['estado_codigo'] !== $esperado) {
                throw new ConflictException('El neumático no está en el estado esperado para este retorno.');
            }
            $vida = null;
            if ($reencauche) {
                $vida = $this->mantenimientos->bloquearVidaAbierta((int) $neumatico['id']);
                if ($vida === null || (int) $vida['numero_vida'] !== (int) $neumatico['vida_actual']) {
                    throw new ConflictException('La vida actual del neumático no se puede cerrar.');
                }
            }
            $finalizado = $this->mantenimientos->estadoId('FINALIZADO');
            $disponible = $this->operaciones->estadoId('DISPONIBLE');
            if ($this->mantenimientos->marcarFinalizado($id, $datos, $finalizado, (int) $mantenimiento['estado_id'], $reencauche ? 1 : 0, $usuarioId) !== 1) {
                throw new ConflictException('El mantenimiento ya fue finalizado.', 'MANTENIMIENTO_ALREADY_FINISHED');
            }
            $movimientoId = $this->movimiento($neumatico, 'RETORNO_MANTENIMIENTO', $datos['fecha_retorno'], $id, $usuarioId, $datos['observacion']);
            $this->operaciones->historialEstado(
                (int) $neumatico['cliente_id'],
                (int) $neumatico['id'],
                (int) $neumatico['estado_id'],
                $disponible,
                $movimientoId,
                $usuarioId,
                $datos['fecha_retorno'],
                'Retorno de ' . $mantenimiento['tipo_codigo'],
            );
            $this->mantenimientos->historial($id, (int) $mantenimiento['estado_id'], $finalizado, $usuarioId, $datos['observacion'], $datos['fecha_retorno']);
            $resultado = ['anterior' => null, 'nueva' => null];
            if ($reencauche) {
                $nueva = (int) $neumatico['vida_actual'] + 1;
                if ($this->mantenimientos->cerrarVida((int) $vida['id'], $datos['fecha_retorno'], $mantenimiento['profundidad_antes_mm'], 'REENCAUCHE') !== 1) {
                    throw new ConflictException('La vida actual del neumático no se puede cerrar.');
                }
                $this->mantenimientos->abrirVida([
                    'cliente_id' => (int) $neumatico['cliente_id'],
                    'neumatico_id' => (int) $neumatico['id'],
                    'numero_vida' => $nueva,
                    'fecha_inicio' => $datos['fecha_retorno'],
                    'profundidad_inicial_mm' => $datos['profundidad_despues_mm'],
                    'mantenimiento_origen_id' => $id,
                ]);
                if ($this->mantenimientos->avanzarVida((int) $neumatico['id'], (int) $neumatico['vida_actual'], $nueva, $disponible, $usuarioId) !== 1) {
                    throw new ConflictException('El mantenimiento ya fue finalizado.', 'MANTENIMIENTO_ALREADY_FINISHED');
                }
                $this->auditar($request, (int) $neumatico['cliente_id'], $usuarioId, 'neumaticos', (int) $neumatico['id'], 'REENCAUCHE_NEW_LIFE', ['vida_actual' => (int) $neumatico['vida_actual']], [
                    'vida_actual' => $nueva,
                    'mantenimiento_id' => $id,
                ]);
                $resultado = ['anterior' => (int) $neumatico['vida_actual'], 'nueva' => $nueva];
            } else {
                $this->operaciones->cambiarEstado((int) $neumatico['id'], $disponible, $usuarioId);
            }
            $this->auditar($request, (int) $neumatico['cliente_id'], $usuarioId, 'mantenimientos_neumatico', $id, 'MANTENIMIENTO_FINISH', ['estado' => $mantenimiento['estado_codigo']], [
                'estado' => 'FINALIZADO',
                'movimiento_id' => $movimientoId,
                'nueva_vida' => $reencauche ? 1 : 0,
            ]);

            return $resultado;
        }, 'MANTENIMIENTO_ALREADY_FINISHED');
        $ficha = $this->detalle($request, $id);
        $ficha['vida_anterior'] = $vidas['anterior'];
        $ficha['vida_nueva'] = $vidas['nueva'];

        return $ficha;
    }

    /** @return array<string, mixed> */
    public function cancelar(Request $request, int $id): array
    {
        $usuarioId = (int) $request->userId();
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::OPERACION);
        $datos = $this->validator->cancelar($request->json() ?? []);
        $previo = $this->exigirVisible($usuarioId, $id);
        $this->transaccion(function () use ($request, $usuarioId, $id, $datos, $previo): void {
            $neumatico = $this->mantenimientos->bloquearNeumatico((int) $previo['neumatico_id']);
            $mantenimiento = $this->exigirMantenimientoBloqueado($id, (int) $neumatico['cliente_id']);
            if ((int) $mantenimiento['es_final'] === 1) {
                throw new ConflictException('El mantenimiento ya está cerrado.', 'MANTENIMIENTO_ALREADY_CLOSED');
            }
            $cancelado = $this->mantenimientos->estadoId('CANCELADO');
            if ($this->mantenimientos->marcarEstado($id, $cancelado, (int) $mantenimiento['estado_id'], $usuarioId) !== 1) {
                throw new ConflictException('El mantenimiento ya está cerrado.', 'MANTENIMIENTO_ALREADY_CLOSED');
            }
            $this->mantenimientos->historial($id, (int) $mantenimiento['estado_id'], $cancelado, $usuarioId, $datos['observacion']);
            if (in_array($mantenimiento['estado_codigo'], ['ENVIADO', 'EN_PROCESO'], true)) {
                $disponible = $this->operaciones->estadoId('DISPONIBLE');
                $ahora = (new \DateTimeImmutable('now', new \DateTimeZone('America/Lima')))->format('Y-m-d H:i:s');
                $movimientoId = $this->movimiento($neumatico, 'RETORNO_MANTENIMIENTO', $ahora, $id, $usuarioId, $datos['observacion'] ?? 'Cancelación');
                $this->operaciones->cambiarEstado((int) $neumatico['id'], $disponible, $usuarioId);
                $this->operaciones->historialEstado(
                    (int) $neumatico['cliente_id'],
                    (int) $neumatico['id'],
                    (int) $neumatico['estado_id'],
                    $disponible,
                    $movimientoId,
                    $usuarioId,
                    $ahora,
                    'Cancelación de mantenimiento',
                );
            }
            $this->auditar($request, (int) $neumatico['cliente_id'], $usuarioId, 'mantenimientos_neumatico', $id, 'MANTENIMIENTO_CANCEL', ['estado' => $mantenimiento['estado_codigo']], ['estado' => 'CANCELADO']);
        });

        return $this->detalle($request, $id);
    }

    /** @return array<string, mixed> */
    public function descartar(Request $request, int $neumaticoId): array
    {
        $usuarioId = (int) $request->userId();
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::OPERACION);
        $datos = $this->validator->descartar($request->json() ?? []);
        $previo = $this->exigirNeumatico($usuarioId, $neumaticoId);
        $motivo = $this->mantenimientos->motivo($datos['motivo_descarte_id']);
        if ($motivo === null || (int) $motivo['activo'] !== 1) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'motivo_descarte_id' => ['El motivo de descarte no está disponible.'],
            ]);
        }
        $descarteId = $this->transaccion(function () use ($request, $usuarioId, $datos, $previo): int {
            $neumatico = $this->exigirDisponibleBloqueado((int) $previo['id']);
            if ($neumatico['estado_codigo'] === 'DESCARTADO' || (int) $neumatico['es_final'] === 1) {
                throw new ConflictException('El neumático ya fue descartado.', 'NEUMATICO_ALREADY_DISCARDED');
            }
            $vida = $this->mantenimientos->bloquearVidaAbierta((int) $neumatico['id']);
            if ($vida === null) {
                throw new ConflictException('La vida actual del neumático no se puede cerrar.');
            }
            $descarteId = $this->mantenimientos->crearDescarte([
                'cliente_id' => (int) $neumatico['cliente_id'],
                'neumatico_id' => (int) $neumatico['id'],
                'fecha_descarte' => $datos['fecha_descarte'],
                'motivo_descarte_id' => $datos['motivo_descarte_id'],
                'vida_final' => (int) $neumatico['vida_actual'],
                'profundidad_final_mm' => $datos['profundidad_final_mm'],
                'observacion' => $datos['observacion'],
                'usuario_id' => $usuarioId,
            ]);
            $movimientoId = $this->movimiento($neumatico, 'DESCARTE', $datos['fecha_descarte'], null, $usuarioId, $datos['observacion']);
            if ($this->mantenimientos->cerrarVida((int) $vida['id'], $datos['fecha_descarte'], $datos['profundidad_final_mm'], 'DESCARTE') !== 1) {
                throw new ConflictException('La vida actual del neumático no se puede cerrar.');
            }
            $descartado = $this->operaciones->estadoId('DESCARTADO');
            $this->operaciones->cambiarEstado((int) $neumatico['id'], $descartado, $usuarioId);
            $this->operaciones->historialEstado(
                (int) $neumatico['cliente_id'],
                (int) $neumatico['id'],
                (int) $neumatico['estado_id'],
                $descartado,
                $movimientoId,
                $usuarioId,
                $datos['fecha_descarte'],
                'Descarte',
            );
            $this->auditar($request, (int) $neumatico['cliente_id'], $usuarioId, 'neumaticos', (int) $neumatico['id'], 'NEUMATICO_DESCARTE', ['estado' => 'DISPONIBLE'], [
                'estado' => 'DESCARTADO',
                'descarte_id' => $descarteId,
                'vida_final' => (int) $neumatico['vida_actual'],
            ]);

            return $descarteId;
        }, 'NEUMATICO_ALREADY_DISCARDED');

        return $this->presentarDescarte($this->mantenimientos->descarte($neumaticoId) ?? ['id' => $descarteId]);
    }

    /** @return array<string, mixed>|null */
    public function descarteDe(Request $request, int $neumaticoId): ?array
    {
        $usuarioId = (int) $request->userId();
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::LECTURA);
        $this->exigirNeumatico($usuarioId, $neumaticoId);
        $row = $this->mantenimientos->descarte($neumaticoId);

        return $row === null ? null : $this->presentarDescarte($row);
    }

    /** @return array<string, mixed> */
    public function subirArchivo(Request $request, int $id): array
    {
        $usuarioId = (int) $request->userId();
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::OPERACION);
        $archivo = $request->archivo();
        if ($archivo === null) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'archivo' => ['El archivo es obligatorio.'],
            ]);
        }
        $tipo = strtoupper(trim((string) ($request->queryParams()['tipo_archivo'] ?? '')));
        $documento = $this->clasificar($archivo['contents'], $tipo === '' ? null : $tipo);
        $guardado = null;
        try {
            return $this->transaccion(function () use ($request, $id, $usuarioId, $archivo, $documento, &$guardado): array {
                $mantenimiento = $this->exigirOperable($usuarioId, $id);
                $guardado = $this->almacen->guardar((int) $mantenimiento['cliente_id'], 'MANTENIMIENTO', $id, $archivo['contents'], $documento['extension']);
                $archivoId = $this->archivos->crearArchivo([
                    'cliente_id' => (int) $mantenimiento['cliente_id'],
                    'entidad_tipo' => 'MANTENIMIENTO',
                    'entidad_id' => $id,
                    'tipo_archivo' => $documento['tipo'],
                    'nombre_original' => $this->nombreOriginal($archivo['name'], $documento['extension']),
                    'nombre_archivo' => $guardado['nombre'],
                    'ruta' => $guardado['ruta'],
                    'mime_type' => $documento['mime'],
                    'tamano_bytes' => $guardado['tamano'],
                    'checksum' => $guardado['checksum'],
                    'usuario_id' => $usuarioId,
                ]);
                $this->auditar($request, (int) $mantenimiento['cliente_id'], $usuarioId, 'archivos', $archivoId, 'ARCHIVO_UPLOAD', null, [
                    'entidad_tipo' => 'MANTENIMIENTO',
                    'tipo_archivo' => $documento['tipo'],
                ]);

                return [
                    'id' => $archivoId,
                    'nombre_original' => $this->nombreOriginal($archivo['name'], $documento['extension']),
                    'mime_type' => $documento['mime'],
                    'tipo_archivo' => $documento['tipo'],
                    'tamano_bytes' => $guardado['tamano'],
                ];
            });
        } catch (\Throwable $error) {
            if (is_array($guardado)) {
                $real = realpath(dirname(__DIR__, 2) . '/storage/' . str_replace('/', DIRECTORY_SEPARATOR, $guardado['ruta']));
                if (is_string($real) && is_file($real)) {
                    unlink($real);
                }
            }
            throw $error;
        }
    }

    /** @return array{contenido:string,mime:string,nombre:string} */
    public function descargar(Request $request, int $archivoId): array
    {
        $usuarioId = (int) $request->userId();
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::LECTURA);
        $archivo = $this->exigirArchivo($usuarioId, $archivoId);
        if (!$this->almacen->existe((string) $archivo['ruta'])) {
            throw new NotFoundException('Archivo no encontrado.');
        }

        return [
            'contenido' => $this->almacen->leer((string) $archivo['ruta']),
            'mime' => (string) $archivo['mime_type'],
            'nombre' => (string) $archivo['nombre_original'],
        ];
    }

    public function eliminarArchivo(Request $request, int $archivoId): void
    {
        $usuarioId = (int) $request->userId();
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::OPERACION);
        $this->transaccion(function () use ($request, $archivoId, $usuarioId): void {
            $archivo = $this->exigirArchivo($usuarioId, $archivoId);
            $this->exigirOperable($usuarioId, (int) $archivo['entidad_id']);
            $this->archivos->eliminarArchivo($archivoId, $usuarioId);
            $this->auditar($request, (int) $archivo['cliente_id'], $usuarioId, 'archivos', $archivoId, 'ARCHIVO_DELETE', null, null);
        });
    }

    public function entidadArchivo(int $archivoId): ?string
    {
        $archivo = $this->archivos->archivo($archivoId);

        return $archivo === null ? null : (string) $archivo['entidad_tipo'];
    }

    /** @param array<string, mixed> $neumatico */
    private function movimiento(array $neumatico, string $tipo, string $fecha, ?int $mantenimientoId, int $usuarioId, ?string $observacion): int
    {
        return $this->operaciones->crearMovimiento([
            'cliente_id' => (int) $neumatico['cliente_id'],
            'neumatico_id' => (int) $neumatico['id'],
            'tipo_movimiento_id' => $this->operaciones->tipoMovimientoId($tipo),
            'fecha' => $fecha,
            'unidad_origen_id' => null,
            'posicion_origen_id' => null,
            'unidad_destino_id' => null,
            'posicion_destino_id' => null,
            'km_unidad' => null,
            'horometro_unidad' => null,
            'montaje_id' => null,
            'mantenimiento_id' => $mantenimientoId,
            'grupo_operacion' => null,
            'observacion' => $observacion,
            'usuario_id' => $usuarioId,
        ]);
    }

    /** @return array<string, mixed> */
    private function exigirDisponibleBloqueado(int $id, ?int $exceptoMantenimiento = null): array
    {
        $neumatico = $this->mantenimientos->bloquearNeumatico($id);
        if ($neumatico === null || (int) $neumatico['eliminado'] === 1) {
            throw new NotFoundException('Neumático no encontrado.');
        }
        if ((int) $neumatico['cliente_eliminado'] === 1) {
            throw new ConflictException('El cliente del neumático no está disponible.');
        }
        if ($neumatico['estado_codigo'] === 'DESCARTADO' || (int) $neumatico['es_final'] === 1) {
            throw new ConflictException('El neumático ya fue descartado.', 'NEUMATICO_ALREADY_DISCARDED');
        }
        if ($neumatico['estado_codigo'] !== 'DISPONIBLE') {
            throw new ConflictException('El neumático debe estar disponible.');
        }
        if ($this->mantenimientos->tieneMontajeActivo($id)) {
            throw new ConflictException('El neumático está montado.');
        }
        if ($this->mantenimientos->bloquearActivos($id, $exceptoMantenimiento) > 0) {
            throw new ConflictException('El neumático ya tiene un mantenimiento activo.', 'MANTENIMIENTO_ACTIVO');
        }

        return $neumatico;
    }

    /** @return array<string, mixed> */
    private function exigirMantenimientoBloqueado(int $id, int $clienteId): array
    {
        $mantenimiento = $this->mantenimientos->bloquear($id);
        if ($mantenimiento === null || (int) $mantenimiento['cliente_id'] !== $clienteId) {
            throw new NotFoundException('Mantenimiento no encontrado.');
        }

        return $mantenimiento;
    }

    /** @return array<string, mixed> */
    private function exigirOperable(int $usuarioId, int $id): array
    {
        $previo = $this->exigirVisible($usuarioId, $id);

        return $this->transaccion(function () use ($id, $previo): array {
            $mantenimiento = $this->exigirMantenimientoBloqueado($id, (int) $previo['cliente_id']);
            if ((int) $mantenimiento['es_final'] === 1) {
                throw new ConflictException('El mantenimiento ya está cerrado.', 'MANTENIMIENTO_ALREADY_CLOSED');
            }

            return $mantenimiento;
        });
    }

    /** @return array<string, mixed> */
    private function exigirNeumatico(int $usuarioId, int $id): array
    {
        $neumatico = $this->mantenimientos->neumatico($id);
        if ($neumatico === null || (int) $neumatico['eliminado'] === 1) {
            $this->ausente($usuarioId, 'Neumático no encontrado.');
        }
        $this->authorization->requireClientAccess($usuarioId, (int) $neumatico['cliente_id']);

        return $neumatico;
    }

    /** @return array<string, mixed> */
    private function exigirVisible(int $usuarioId, int $id): array
    {
        $mantenimiento = $this->mantenimientos->mantenimiento($id);
        if ($mantenimiento === null) {
            $this->ausente($usuarioId, 'Mantenimiento no encontrado.');
        }
        $this->authorization->requireClientAccess($usuarioId, (int) $mantenimiento['cliente_id']);

        return $mantenimiento;
    }

    /** @return array<string, mixed> */
    private function exigirArchivo(int $usuarioId, int $archivoId): array
    {
        $archivo = $this->archivos->archivo($archivoId);
        if ($archivo === null || $archivo['entidad_tipo'] !== 'MANTENIMIENTO') {
            $this->ausente($usuarioId, 'Archivo no encontrado.');
        }
        $this->authorization->requireClientAccess($usuarioId, (int) $archivo['cliente_id']);
        if ((int) $archivo['eliminado'] === 1) {
            throw new NotFoundException('Archivo no encontrado.');
        }
        $this->exigirVisible($usuarioId, (int) $archivo['entidad_id']);

        return $archivo;
    }

    private function ausente(int $usuarioId, string $mensaje): never
    {
        if ($this->authorization->isAdminGeneral($usuarioId)) {
            throw new NotFoundException($mensaje);
        }
        throw new AuthorizationException('No tiene acceso al cliente solicitado.', 'CLIENT_SCOPE_FORBIDDEN');
    }

    /** @return array<string, mixed> */
    private function presentar(int $id): array
    {
        $row = $this->mantenimientos->ficha($id);
        if ($row === null) {
            throw new NotFoundException('Mantenimiento no encontrado.');
        }
        $archivos = $this->archivos->archivosDe('MANTENIMIENTO', $id);

        return [
            'id' => (int) $row['id'],
            'fecha_solicitud' => $row['fecha_solicitud'],
            'fecha_envio' => $row['fecha_envio'],
            'fecha_retorno' => $row['fecha_retorno'],
            'tercero_nombre' => $row['tercero_nombre'],
            'costo' => $row['costo'],
            'moneda' => $row['moneda'],
            'profundidad_antes_mm' => $row['profundidad_antes_mm'],
            'profundidad_despues_mm' => $row['profundidad_despues_mm'],
            'inicia_nueva_vida' => (int) $row['inicia_nueva_vida'] === 1,
            'observacion' => $row['observacion'],
            'cliente' => [
                'id' => (int) $row['cliente_id'],
                'razon_social' => $row['razon_social'],
                'nombre_comercial' => $row['nombre_comercial'],
            ],
            'neumatico' => [
                'id' => (int) $row['neumatico_id'],
                'codigo' => $row['neumatico_codigo'],
                'vida_actual' => (int) $row['vida_actual'],
                'estado' => $row['neumatico_estado'],
            ],
            'tipo' => ['id' => (int) $row['tipo_id'], 'codigo' => $row['tipo_codigo'], 'nombre' => $row['tipo_nombre']],
            'estado' => [
                'id' => (int) $row['estado_id'],
                'codigo' => $row['estado_codigo'],
                'nombre' => $row['estado_nombre'],
                'es_final' => (int) $row['es_final'] === 1,
            ],
            'historial' => array_map(static fn (array $item): array => [
                'id' => (int) $item['id'],
                'fecha' => $item['fecha_cambio'],
                'anterior' => $item['anterior'],
                'nuevo' => $item['nuevo'],
                'observacion' => $item['observacion'],
                'usuario' => trim($item['nombres'] . ' ' . $item['apellidos']),
            ], $this->mantenimientos->historialDe($id)),
            'archivos' => array_map(static fn (array $archivo): array => [
                'id' => (int) $archivo['id'],
                'nombre_original' => $archivo['nombre_original'],
                'mime_type' => $archivo['mime_type'],
                'tamano_bytes' => (int) $archivo['tamano_bytes'],
            ], $archivos),
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function presentarDescarte(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'fecha_descarte' => $row['fecha_descarte'] ?? null,
            'vida_final' => isset($row['vida_final']) ? (int) $row['vida_final'] : null,
            'km_totales' => $row['km_totales'] ?? null,
            'profundidad_final_mm' => $row['profundidad_final_mm'] ?? null,
            'observacion' => $row['observacion'] ?? null,
            'motivo' => [
                'id' => (int) ($row['motivo_id'] ?? 0),
                'codigo' => $row['motivo_codigo'] ?? null,
                'nombre' => $row['motivo_nombre'] ?? null,
            ],
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function resumen(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'fecha_solicitud' => $row['fecha_solicitud'],
            'fecha_envio' => $row['fecha_envio'],
            'fecha_retorno' => $row['fecha_retorno'],
            'tercero_nombre' => $row['tercero_nombre'],
            'costo' => $row['costo'],
            'moneda' => $row['moneda'],
            'inicia_nueva_vida' => (int) $row['inicia_nueva_vida'] === 1,
            'cliente' => [
                'id' => (int) $row['cliente_id'],
                'razon_social' => $row['razon_social'],
                'nombre_comercial' => $row['nombre_comercial'],
            ],
            'neumatico' => ['id' => (int) $row['neumatico_id'], 'codigo' => $row['neumatico_codigo']],
            'tipo' => ['codigo' => $row['tipo_codigo'], 'nombre' => $row['tipo_nombre']],
            'estado' => ['codigo' => $row['estado_codigo'], 'nombre' => $row['estado_nombre']],
        ];
    }

    /** @return array<string, mixed> */
    private function filtros(int $usuarioId, Request $request, ?int $neumaticoId): array
    {
        $query = $request->queryParams();
        $clienteId = $this->entero($query['cliente_id'] ?? null, 'cliente_id');
        if ($clienteId !== null) {
            $this->authorization->requireClientAccess($usuarioId, $clienteId);
        }
        $estado = isset($query['estado']) && $query['estado'] !== '' ? strtoupper((string) $query['estado']) : null;
        if ($estado !== null && !in_array($estado, ['SOLICITADO', 'ENVIADO', 'EN_PROCESO', 'FINALIZADO', 'CANCELADO'], true)) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'estado' => ['El estado de mantenimiento no es válido.'],
            ]);
        }
        $tipo = isset($query['tipo']) && $query['tipo'] !== '' ? strtoupper((string) $query['tipo']) : null;
        if ($tipo !== null && !in_array($tipo, ['REPARACION', 'REENCAUCHE', 'REGRABADO', 'OTRO'], true)) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'tipo' => ['El tipo de mantenimiento no es válido.'],
            ]);
        }
        unset($query['estado'], $query['tipo']);
        if (!isset($query['sort']) || $query['sort'] === '') {
            $query['sort'] = 'fecha_solicitud';
        }
        if (!isset($query['order']) || $query['order'] === '') {
            $query['order'] = 'desc';
        }
        $criteria = ListCriteria::from($query, [
            'fecha_solicitud' => 'm.fecha_solicitud',
            'estado' => 'e.codigo',
            'id' => 'm.id',
        ], null, false, true);
        $busqueda = $criteria->search;

        return [
            'usuario_scope' => $this->authorization->isAdminGeneral($usuarioId) ? null : $usuarioId,
            'cliente_id' => $clienteId,
            'neumatico_id' => $neumaticoId ?? $this->entero($query['neumatico_id'] ?? null, 'neumatico_id'),
            'tipo' => $tipo,
            'estado' => $estado,
            'fecha_inicio' => $this->fechaFiltro($query['fecha_inicio'] ?? null, false),
            'fecha_fin' => $this->fechaFiltro($query['fecha_fin'] ?? null, true),
            'search' => $busqueda === null ? null : '%' . addcslashes($busqueda, '\\%_') . '%',
            'sort' => $criteria->sortExpression,
            'direction' => $criteria->direction,
            'limit' => $criteria->limit,
            'offset' => $criteria->offset,
        ];
    }

    /** @return array{tipo:string,mime:string,extension:string} */
    private function clasificar(string $contenido, ?string $tipo): array
    {
        if ($contenido === '' || strlen($contenido) > $this->maxBytes) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'archivo' => ['El archivo supera el tamaño permitido o está vacío.'],
            ]);
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contenido);
        $esPdf = is_string($mime) && $mime === 'application/pdf' && str_starts_with($contenido, '%PDF');
        $fotos = [
            'image/jpeg' => ['jpg', "\xFF\xD8\xFF"],
            'image/png' => ['png', "\x89PNG\r\n\x1A\n"],
            'image/webp' => ['webp', 'RIFF'],
        ];
        $esFoto = is_string($mime) && isset($fotos[$mime]) && str_starts_with($contenido, $fotos[$mime][1])
            && ($mime !== 'image/webp' || substr($contenido, 8, 4) === 'WEBP');
        if ($tipo === 'DOCUMENTO' || ($tipo === null && $esPdf)) {
            if (!$esPdf) {
                throw new ValidationException('Los datos enviados no son válidos.', [
                    'archivo' => ['Un documento debe ser un PDF.'],
                ]);
            }

            return ['tipo' => 'DOCUMENTO', 'mime' => 'application/pdf', 'extension' => 'pdf'];
        }
        if (!$esFoto || $tipo === 'DOCUMENTO') {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'archivo' => ['Solo se permiten fotografías JPEG, PNG o WEBP, o un documento PDF.'],
            ]);
        }

        return ['tipo' => 'FOTO', 'mime' => $mime, 'extension' => $fotos[$mime][0]];
    }

    private function nombreOriginal(string $nombre, string $extension): string
    {
        $base = pathinfo(str_replace(['\\', '/'], '', $nombre), PATHINFO_FILENAME);
        $base = trim(preg_replace('/[^A-Za-z0-9._ -]/', '', $base) ?? '');
        if ($base === '') {
            $base = 'archivo';
        }

        return substr($base, 0, 180) . '.' . $extension;
    }

    private function entero(mixed $valor, string $campo): ?int
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        if (preg_match('/^[1-9][0-9]*$/', (string) $valor) !== 1) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                $campo => ['El identificador no es válido.'],
            ]);
        }

        return (int) $valor;
    }

    private function fechaFiltro(mixed $valor, bool $fin): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        $texto = trim((string) $valor);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $texto) === 1) {
            return $texto . ($fin ? ' 23:59:59' : ' 00:00:00');
        }

        throw new ValidationException('Los datos enviados no son válidos.', [
            'fecha' => ['La fecha del filtro no es válida.'],
        ]);
    }

    private function esperaDePrueba(): void
    {
        $ms = getenv('MANTENIMIENTO_FINISH_HOLD_MS');
        if (!is_string($ms) || preg_match('/^[1-9][0-9]{0,3}$/', $ms) !== 1) {
            return;
        }
        $signal = getenv('MANTENIMIENTO_FINISH_HOLD_SIGNAL');
        if (is_string($signal) && preg_match('/^mantenimiento-hold-[A-Za-z0-9._-]+$/', $signal) === 1) {
            $directorio = realpath(sys_get_temp_dir());
            if (is_string($directorio)) {
                file_put_contents($directorio . DIRECTORY_SEPARATOR . $signal, '1');
            }
        }
        usleep(min((int) $ms, 5000) * 1000);
    }

    /** @param array<string, mixed>|null $antes @param array<string, mixed>|null $despues */
    private function auditar(Request $request, int $clienteId, int $usuarioId, string $entidad, int $entidadId, string $accion, ?array $antes, ?array $despues): void
    {
        $this->auditoria->registrar($clienteId, $usuarioId, $entidad, $entidadId, $accion, $antes, $despues, $request->ip(), $request->userAgent());
    }

    /** @template T @param callable():T $callback @return T */
    private function transaccion(callable $callback, ?string $conflictoLock = null): mixed
    {
        try {
            return $this->transaction->run($callback);
        } catch (PDOException $exception) {
            $codigo = (string) ($exception->errorInfo[1] ?? '');
            if ($conflictoLock !== null && in_array($codigo, ['1062', '1205', '1213'], true)) {
                throw new ConflictException('La operación ya fue registrada.', $conflictoLock);
            }
            if (in_array($codigo, ['1062', '1205', '1213'], true)) {
                throw new ConflictException('El neumático ya tiene un proceso activo.');
            }
            if (in_array($codigo, ['3819', '4025'], true)) {
                throw new ValidationException('Los datos enviados no son válidos.', [
                    'datos' => ['Los datos no cumplen las reglas de la base de datos.'],
                ]);
            }
            throw $exception;
        }
    }
}
