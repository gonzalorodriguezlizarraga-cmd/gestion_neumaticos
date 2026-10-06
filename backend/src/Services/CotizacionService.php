<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AuthorizationException;
use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Repositories\AuditoriaRepository;
use App\Repositories\CotizacionRepository;
use App\Repositories\OportunidadRepository;
use App\Support\ListCriteria;
use App\Support\Transaction;
use App\Validators\CotizacionValidator;
use DateTimeImmutable;
use DateTimeZone;
use PDOException;

final class CotizacionService
{
    public function __construct(
        private readonly CotizacionRepository $cotizaciones,
        private readonly OportunidadRepository $oportunidades,
        private readonly AuditoriaRepository $auditoria,
        private readonly AuthorizationService $authorization,
        private readonly ComercialAcceso $acceso,
        private readonly CotizacionValidator $validator,
        private readonly Transaction $transaction,
    ) {
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function listar(Request $request): array
    {
        $usuarioId = (int) $request->userId();
        $this->acceso->exigirLectura($usuarioId);
        $query = $request->queryParams();
        $filtros = $this->filtros($usuarioId, $query);
        unset($query['cliente_id'], $query['oportunidad_id'], $query['estado']);
        if (!isset($query['order']) || $query['order'] === '') {
            $query['order'] = 'desc';
        }
        $criteria = ListCriteria::from($query, ['fecha' => 'c.fecha', 'id' => 'c.id'], null, false, true);
        $resultado = $this->cotizaciones->listar($criteria, $filtros);

        return [
            'rows' => array_map(static function (array $row): array {
                return [
                    'id' => (int) $row['id'],
                    'numero' => $row['numero'],
                    'fecha' => $row['fecha'],
                    'estado' => $row['estado'],
                    'moneda' => $row['moneda'],
                    'total' => $row['total'],
                    'cliente' => [
                        'id' => (int) $row['cliente_id'],
                        'razon_social' => $row['razon_social'],
                        'nombre_comercial' => $row['nombre_comercial'],
                    ],
                    'oportunidad' => $row['oportunidad_id'] === null ? null : [
                        'id' => (int) $row['oportunidad_id'],
                        'titulo' => $row['oportunidad_titulo'],
                    ],
                ];
            }, $resultado['rows']),
            'total' => $resultado['total'],
        ];
    }

    /** @return array<string, mixed> */
    public function detalle(Request $request, int $id): array
    {
        $usuarioId = (int) $request->userId();
        $this->acceso->exigirLectura($usuarioId);
        $this->exigirVisible($usuarioId, $id);

        return $this->presentar($id);
    }

    /** @return array<string, mixed> */
    public function crear(Request $request): array
    {
        $usuarioId = (int) $request->userId();
        $this->acceso->exigirEscritura($usuarioId);
        $datos = $this->validator->crear($request->json());
        $id = $this->transaccion(function () use ($request, $usuarioId, $datos): int {
            $this->prepararCliente($usuarioId, (int) $datos['cliente_id']);
            $this->prepararOportunidad($usuarioId, $datos['oportunidad_id'], (int) $datos['cliente_id'], true);
            $this->validarCatalogos($datos['detalles']);
            [$detalles, $subtotal, $total] = $this->calcular($datos['detalles']);
            $id = $this->insertarConNumero($datos, $detalles, $subtotal, $total, $usuarioId);
            $this->auditar($request, (int) $datos['cliente_id'], $usuarioId, $id, 'COTIZACION_CREATE', null, [
                'estado' => 'BORRADOR',
                'total' => $total,
                'moneda' => $datos['moneda'],
            ]);

            return $id;
        }, 'COTIZACION_NUMERO');

        return $this->presentar($id);
    }

    /** @return array<string, mixed> */
    public function editar(Request $request, int $id): array
    {
        $usuarioId = (int) $request->userId();
        $this->acceso->exigirEscritura($usuarioId);
        $datos = $this->validator->editar($request->json());
        $this->transaccion(function () use ($request, $usuarioId, $id, $datos): void {
            $actual = $this->cotizaciones->bloquear($id);
            if ($actual === null || !$this->visible($usuarioId, $this->fila($id))) {
                $this->ausente($usuarioId, $actual === null);
            }
            if ($actual['estado'] !== 'BORRADOR') {
                throw new ConflictException('La cotización ya no se puede editar.', 'COTIZACION_NO_EDITABLE');
            }
            $this->validarCatalogos($datos['detalles']);
            [$detalles, $subtotal, $total] = $this->calcular($datos['detalles']);
            $this->cotizaciones->actualizar($id, $datos, $detalles, $subtotal, $total);
            $this->auditar($request, (int) $actual['cliente_id'], $usuarioId, $id, 'COTIZACION_UPDATE', [
                'total' => $actual['total'],
                'moneda' => $actual['moneda'],
            ], ['total' => $total, 'moneda' => $datos['moneda']]);
        }, 'COTIZACION_NO_EDITABLE');

        return $this->presentar($id);
    }

    /** @return array<string, mixed> */
    public function enviar(Request $request, int $id): array
    {
        return $this->transicionar($request, $id, ['BORRADOR'], 'ENVIADA', 'COTIZACION_ENVIADA', true);
    }

    /** @return array<string, mixed> */
    public function aceptar(Request $request, int $id): array
    {
        return $this->transicionar($request, $id, ['ENVIADA'], 'ACEPTADA', 'COTIZACION_ACEPTADA', false);
    }

    /** @return array<string, mixed> */
    public function rechazar(Request $request, int $id): array
    {
        return $this->transicionar($request, $id, ['ENVIADA'], 'RECHAZADA', 'COTIZACION_RECHAZADA', false);
    }

    /** @return array<string, mixed> */
    public function anular(Request $request, int $id): array
    {
        return $this->transicionar($request, $id, ['BORRADOR', 'ENVIADA'], 'ANULADA', 'COTIZACION_ANULADA', false);
    }

    /** @param list<string> $origenes @return array<string, mixed> */
    private function transicionar(Request $request, int $id, array $origenes, string $destino, string $accion, bool $cotizarOportunidad): array
    {
        $usuarioId = (int) $request->userId();
        $this->acceso->exigirEscritura($usuarioId);
        $this->transaccion(function () use ($request, $usuarioId, $id, $origenes, $destino, $accion, $cotizarOportunidad): void {
            $this->cotizaciones->bloquear($id);
            $this->esperaDePrueba();
            $actual = $this->cotizaciones->bloquear($id);
            if ($actual === null || !$this->visible($usuarioId, $this->fila($id))) {
                $this->ausente($usuarioId, $actual === null);
            }
            if (!in_array($actual['estado'], $origenes, true)) {
                throw new ConflictException('La cotización ya no admite esta transición.', 'COTIZACION_YA_CERRADA');
            }
            if ($actual['oportunidad_id'] !== null && ($destino === 'ENVIADA' || $destino === 'ACEPTADA')) {
                $oportunidad = $this->oportunidades->bloquear((int) $actual['oportunidad_id']);
                if ($oportunidad === null || (int) $oportunidad['es_final'] === 1) {
                    throw new ConflictException('La oportunidad asociada ya está cerrada.', 'OPORTUNIDAD_YA_CERRADA');
                }
            }
            if (!$this->cotizaciones->marcarEstado($id, $destino, (string) $actual['estado'])) {
                throw new ConflictException('La cotización ya no admite esta transición.', 'COTIZACION_YA_CERRADA');
            }
            $this->auditar($request, (int) $actual['cliente_id'], $usuarioId, $id, $accion, [
                'estado' => $actual['estado'],
            ], ['estado' => $destino]);
            if ($actual['oportunidad_id'] !== null && $destino === 'ENVIADA' && $cotizarOportunidad) {
                $this->moverOportunidad($request, $usuarioId, (int) $actual['oportunidad_id'], ['ABIERTA', 'EN_SEGUIMIENTO'], 'COTIZADA', 'OPORTUNIDAD_COTIZADA', 'Cotización ' . $actual['numero'] . ' enviada');
            }
            if ($actual['oportunidad_id'] !== null && $destino === 'ACEPTADA') {
                $this->moverOportunidad($request, $usuarioId, (int) $actual['oportunidad_id'], ['ABIERTA', 'EN_SEGUIMIENTO', 'COTIZADA'], 'GANADA', 'OPORTUNIDAD_GANADA', 'Cotización ' . $actual['numero'] . ' aceptada');
            }
        });

        return $this->presentar($id);
    }

    /** @param list<string> $origenes */
    private function moverOportunidad(Request $request, int $usuarioId, int $oportunidadId, array $origenes, string $destino, string $accion, string $motivo): void
    {
        $actual = $this->oportunidades->bloquear($oportunidadId);
        if ($actual === null) {
            throw new ConflictException('La oportunidad asociada ya está cerrada.', 'OPORTUNIDAD_YA_CERRADA');
        }
        if ($destino === 'COTIZADA' && $actual['estado_codigo'] === 'COTIZADA') {
            return;
        }
        if (!in_array($actual['estado_codigo'], $origenes, true)) {
            throw new ConflictException('La oportunidad asociada ya está cerrada.', 'OPORTUNIDAD_YA_CERRADA');
        }
        $nuevo = $this->oportunidades->estadoId($destino);
        if (!$this->oportunidades->marcarEstado($oportunidadId, $nuevo, (int) $actual['estado_id'], $usuarioId)) {
            throw new ConflictException('La oportunidad asociada ya está cerrada.', 'OPORTUNIDAD_YA_CERRADA');
        }
        $this->oportunidades->historial($oportunidadId, (int) $actual['estado_id'], $nuevo, $usuarioId, $motivo);
        $this->auditoria->registrar(
            (int) $actual['cliente_id'],
            $usuarioId,
            'oportunidades',
            $oportunidadId,
            $accion,
            ['estado' => $actual['estado_codigo']],
            ['estado' => $destino, 'motivo' => $motivo],
            $request->ip(),
            $request->userAgent(),
        );
    }

    /** @param array<string, mixed> $datos @param list<array<string, mixed>> $detalles */
    private function insertarConNumero(array $datos, array $detalles, string $subtotal, string $total, int $usuarioId): int
    {
        $ultimo = null;
        for ($intento = 0; $intento < 5; $intento++) {
            try {
                return $this->cotizaciones->crear($datos, $detalles, $this->numero(), $subtotal, $total, $usuarioId);
            } catch (PDOException $exception) {
                $ultimo = $exception;
                if ((string) ($exception->errorInfo[1] ?? '') !== '1062') {
                    throw $exception;
                }
            }
        }
        throw $ultimo ?? new ConflictException('No se pudo asignar un número de cotización.', 'COTIZACION_NUMERO');
    }

    private function numero(): string
    {
        $fecha = (new DateTimeImmutable('now', new DateTimeZone('America/Lima')))->format('Ymd');

        return 'COT-' . $fecha . '-' . strtoupper(bin2hex(random_bytes(3)));
    }

    /**
     * @param list<array<string, mixed>> $detalles
     * @return array{0:list<array<string,mixed>>,1:string,2:string}
     */
    private function calcular(array $detalles): array
    {
        $total = 0;
        foreach ($detalles as $indice => $detalle) {
            $cantidad = $this->centavos((string) $detalle['cantidad']);
            $precio = $this->centavos((string) $detalle['precio_unitario']);
            $producto = $cantidad * $precio;
            if (!is_int($producto)) {
                throw new ValidationException('Los datos enviados no son válidos.', [
                    'detalles.' . $indice . '.cantidad' => ['El importe del detalle excede el máximo permitido.'],
                ]);
            }
            $subtotal = intdiv($producto, 100);
            $detalles[$indice]['subtotal'] = $this->texto($subtotal);
            $total += $subtotal;
        }

        return [$detalles, $this->texto($total), $this->texto($total)];
    }

    private function prepararCliente(int $usuarioId, int $clienteId): void
    {
        $cliente = $this->oportunidades->cliente($clienteId);
        if ($cliente === null || (int) $cliente['eliminado'] === 1) {
            $this->ausente($usuarioId, true, 'Cliente no encontrado.');
        }
        if (!in_array($cliente['estado'], ['ACTIVO', 'POTENCIAL'], true)) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'cliente_id' => ['Solo un cliente activo o potencial admite una nueva cotización.'],
            ]);
        }
        if (!$this->acceso->esAdmin($usuarioId)) {
            $this->authorization->requireClientAccess($usuarioId, $clienteId);
        }
    }

    private function prepararOportunidad(int $usuarioId, ?int $oportunidadId, int $clienteId, bool $alta): void
    {
        if ($oportunidadId === null) {
            if ($this->acceso->esVendedor($usuarioId) && !$this->acceso->esAdmin($usuarioId) && !$this->oportunidades->comercialVigente($usuarioId, $clienteId)) {
                throw new AuthorizationException('No tiene acceso al cliente solicitado.', 'CLIENT_SCOPE_FORBIDDEN');
            }

            return;
        }
        $row = $this->oportunidades->ficha($oportunidadId);
        if ($row === null || !$this->oportunidadVisible($usuarioId, $row)) {
            $this->ausente($usuarioId, $row === null, 'Oportunidad no encontrada.');
        }
        if ((int) $row['cliente_id'] !== $clienteId) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'oportunidad_id' => ['La oportunidad no pertenece al cliente indicado.'],
            ]);
        }
        if ($alta && in_array($row['estado_codigo'], ['GANADA', 'PERDIDA', 'CANCELADA'], true)) {
            throw new ConflictException('La oportunidad asociada ya está cerrada.', 'OPORTUNIDAD_YA_CERRADA');
        }
    }

    /** @param list<array<string, mixed>> $detalles */
    private function validarCatalogos(array $detalles): void
    {
        $errors = [];
        foreach ($detalles as $indice => $detalle) {
            if ($detalle['modelo_neumatico_id'] !== null && !$this->oportunidades->modeloActivo((int) $detalle['modelo_neumatico_id'])) {
                $errors['detalles.' . $indice . '.modelo_neumatico_id'][] = 'El modelo no está disponible.';
            }
            if ($detalle['medida_neumatico_id'] !== null && !$this->oportunidades->medidaActiva((int) $detalle['medida_neumatico_id'])) {
                $errors['detalles.' . $indice . '.medida_neumatico_id'][] = 'La medida no está disponible.';
            }
        }
        if ($errors !== []) {
            throw new ValidationException('Los datos enviados no son válidos.', $errors);
        }
    }

    private function exigirVisible(int $usuarioId, int $id): void
    {
        $row = $this->cotizaciones->ficha($id);
        if ($row === null || !$this->visible($usuarioId, $row)) {
            $this->ausente($usuarioId, $row === null);
        }
    }

    /** @return array<string, mixed> */
    private function fila(int $id): array
    {
        $row = $this->cotizaciones->ficha($id);

        return is_array($row) ? $row : [];
    }

    /** @param array<string, mixed> $row */
    private function oportunidadVisible(int $usuarioId, array $row): bool
    {
        if ($this->acceso->esAdmin($usuarioId)) {
            return true;
        }
        if (!$this->authorization->canAccessClient($usuarioId, (int) $row['cliente_id'])) {
            return false;
        }
        if ($this->acceso->esVendedor($usuarioId)) {
            return (int) ($row['responsable_comercial_id'] ?? 0) === $usuarioId;
        }

        return $this->acceso->esGestor($usuarioId);
    }

    /** @param array<string, mixed> $row */
    private function visible(int $usuarioId, array $row): bool
    {
        if ($row === []) {
            return false;
        }
        if ($this->acceso->esAdmin($usuarioId)) {
            return true;
        }
        if (!$this->authorization->canAccessClient($usuarioId, (int) $row['cliente_id'])) {
            return false;
        }
        if ($this->acceso->esVendedor($usuarioId)) {
            if ($row['oportunidad_id'] !== null) {
                return (int) $row['responsable_comercial_id'] === $usuarioId;
            }

            return (int) $row['creado_por'] === $usuarioId;
        }

        return $this->acceso->esGestor($usuarioId);
    }

    /** @param array<string, mixed> $query @return array<string, mixed> */
    private function filtros(int $usuarioId, array $query): array
    {
        $clienteId = $this->entero($query['cliente_id'] ?? null, 'cliente_id');
        $oportunidadId = $this->entero($query['oportunidad_id'] ?? null, 'oportunidad_id');
        if ($clienteId !== null && !$this->acceso->esAdmin($usuarioId)) {
            $this->authorization->requireClientAccess($usuarioId, $clienteId);
        }
        if ($oportunidadId !== null) {
            $row = $this->oportunidades->ficha($oportunidadId);
            if ($row === null || !$this->oportunidadVisible($usuarioId, $row)) {
                $this->ausente($usuarioId, $row === null, 'Oportunidad no encontrada.');
            }
        }
        $estado = null;
        if (isset($query['estado']) && trim((string) $query['estado']) !== '') {
            $estado = strtoupper(trim((string) $query['estado']));
            if (!in_array($estado, ['BORRADOR', 'ENVIADA', 'ACEPTADA', 'RECHAZADA', 'ANULADA'], true)) {
                throw new ValidationException('Los datos enviados no son válidos.', [
                    'estado' => ['El estado no es válido.'],
                ]);
            }
        }
        $usuario = null;
        $alcance = null;
        if (!$this->acceso->esAdmin($usuarioId)) {
            if ($this->acceso->esVendedor($usuarioId)) {
                $usuario = $usuarioId;
                $alcance = $usuarioId;
            } else {
                $alcance = $usuarioId;
            }
        }

        return [
            'cliente_id' => $clienteId,
            'oportunidad_id' => $oportunidadId,
            'estado' => $estado,
            'usuario_id' => $usuario,
            'alcance_usuario' => $alcance,
        ];
    }

    /** @return array<string, mixed> */
    private function presentar(int $id): array
    {
        $row = $this->cotizaciones->ficha($id);
        if ($row === null) {
            throw new NotFoundException('Cotización no encontrada.');
        }

        return [
            'id' => (int) $row['id'],
            'numero' => $row['numero'],
            'fecha' => $row['fecha'],
            'estado' => $row['estado'],
            'moneda' => $row['moneda'],
            'subtotal' => $row['subtotal'],
            'total' => $row['total'],
            'observacion' => $row['observacion'],
            'cliente' => [
                'id' => (int) $row['cliente_id'],
                'razon_social' => $row['razon_social'],
                'nombre_comercial' => $row['nombre_comercial'],
            ],
            'oportunidad' => $row['oportunidad_id'] === null ? null : [
                'id' => (int) $row['oportunidad_id'],
                'titulo' => $row['oportunidad_titulo'],
                'valor_estimado' => $row['valor_estimado'],
            ],
            'detalles' => array_map(static function (array $detalle): array {
                return [
                    'id' => (int) $detalle['id'],
                    'descripcion' => $detalle['descripcion'],
                    'cantidad' => $detalle['cantidad'],
                    'precio_unitario' => $detalle['precio_unitario'],
                    'subtotal' => $detalle['subtotal'],
                    'modelo' => $detalle['modelo_neumatico_id'] === null ? null : [
                        'id' => (int) $detalle['modelo_neumatico_id'],
                        'nombre' => $detalle['modelo_nombre'],
                    ],
                    'medida' => $detalle['medida_neumatico_id'] === null ? null : [
                        'id' => (int) $detalle['medida_neumatico_id'],
                        'descripcion' => $detalle['medida_descripcion'],
                    ],
                ];
            }, $this->cotizaciones->detalles($id)),
        ];
    }

    private function centavos(string $monto): int
    {
        [$entero, $fraccion] = array_pad(explode('.', $monto, 2), 2, '00');
        $fraccion = str_pad(substr($fraccion, 0, 2), 2, '0');

        return ((int) $entero * 100) + (int) $fraccion;
    }

    private function texto(int $centavos): string
    {
        return intdiv($centavos, 100) . '.' . str_pad((string) ($centavos % 100), 2, '0', STR_PAD_LEFT);
    }

    private function entero(mixed $valor, string $campo): ?int
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        $texto = trim((string) $valor);
        if (preg_match('/^[1-9][0-9]*$/', $texto) !== 1) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                $campo => ['El identificador no es válido.'],
            ]);
        }

        return (int) $texto;
    }

    private function esperaDePrueba(): void
    {
        $ms = getenv('COTIZACION_TRANSITION_HOLD_MS');
        if (!is_string($ms) || preg_match('/^[1-9][0-9]{0,3}$/', $ms) !== 1) {
            return;
        }
        $signal = getenv('COTIZACION_TRANSITION_HOLD_SIGNAL');
        if (is_string($signal) && preg_match('/^cotizacion-hold-[A-Za-z0-9._-]+$/', $signal) === 1) {
            $directorio = realpath(sys_get_temp_dir());
            if (is_string($directorio)) {
                file_put_contents($directorio . DIRECTORY_SEPARATOR . $signal, '1');
            }
        }
        usleep(min((int) $ms, 5000) * 1000);
    }

    /** @param array<string, mixed>|null $antes @param array<string, mixed>|null $despues */
    private function auditar(Request $request, int $clienteId, int $usuarioId, int $id, string $accion, ?array $antes, ?array $despues): void
    {
        $this->auditoria->registrar($clienteId, $usuarioId, 'cotizaciones', $id, $accion, $antes, $despues, $request->ip(), $request->userAgent());
    }

    private function ausente(int $usuarioId, bool $inexistente, string $mensaje = 'Cotización no encontrada.'): never
    {
        if ($this->acceso->esAdmin($usuarioId) && $inexistente) {
            throw new NotFoundException($mensaje);
        }
        throw new AuthorizationException('No tiene acceso al cliente solicitado.', 'CLIENT_SCOPE_FORBIDDEN');
    }

    /** @template T @param callable():T $callback @return T */
    private function transaccion(callable $callback, ?string $conflicto = null): mixed
    {
        try {
            return $this->transaction->run($callback);
        } catch (PDOException $exception) {
            $codigo = (string) ($exception->errorInfo[1] ?? '');
            if (in_array($codigo, ['1205', '1213'], true) || ($codigo === '1062' && $conflicto !== null)) {
                throw new ConflictException('La cotización ya fue actualizada.', $conflicto ?? 'COTIZACION_YA_CERRADA');
            }
            throw $exception;
        }
    }
}
