<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AuthorizationException;
use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Repositories\AlertaRepository;
use App\Repositories\AuditoriaRepository;
use App\Support\ListCriteria;
use App\Support\Transaction;
use App\Validators\AlertaValidator;
use DateTimeImmutable;
use DateTimeZone;
use PDOException;

final class AlertaService
{
    private const NIVELES = ['INFORMATIVA', 'ATENCION', 'CRITICA'];

    public function __construct(
        private readonly AlertaRepository $alertas,
        private readonly AuditoriaRepository $auditoria,
        private readonly AuthorizationService $authorization,
        private readonly AlertaValidator $validator,
        private readonly Transaction $transaction,
    ) {
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
        ], $this->alertas->tipos());
    }

    /** @return list<array<string, mixed>> */
    public function estados(Request $request): array
    {
        $this->authorization->requireRole((int) $request->userId(), ...ClientePolicy::LECTURA);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'codigo' => $row['codigo'],
            'nombre' => $row['nombre'],
            'es_final' => (int) $row['es_final'] === 1,
        ], $this->alertas->estados());
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function listar(Request $request): array
    {
        $usuarioId = (int) $request->userId();
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::LECTURA);
        $resultado = $this->alertas->listar($this->filtros($usuarioId, $request));

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
    public function historial(Request $request, int $id): array
    {
        $usuarioId = (int) $request->userId();
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::LECTURA);
        $this->exigirVisible($usuarioId, $id);

        return $this->presentar($id)['historial'];
    }

    /** @return array<string, mixed> */
    public function crear(Request $request): array
    {
        $usuarioId = (int) $request->userId();
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::OPERACION);
        $datos = $this->validator->crear($request->json() ?? []);
        $cliente = $this->alertas->cliente($datos['cliente_id']);
        if ($cliente === null) {
            $this->ausente($usuarioId, 'Cliente no encontrado.');
        }
        $this->authorization->requireClientAccess($usuarioId, $datos['cliente_id']);
        if ((int) $cliente['eliminado'] === 1) {
            throw new ConflictException('El cliente no está disponible.');
        }
        $tipo = $this->alertas->tipo($datos['tipo_alerta_id']);
        if ($tipo === null || (int) $tipo['activo'] !== 1) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'tipo_alerta_id' => ['El tipo de alerta no está disponible.'],
            ]);
        }
        $this->exigirMismoCliente($datos['unidad_id'], $datos['cliente_id'], true);
        $this->exigirMismoCliente($datos['neumatico_id'], $datos['cliente_id'], false);
        $fecha = (new DateTimeImmutable('now', new DateTimeZone('America/Lima')))->format('Y-m-d H:i:s');
        $abierta = $this->alertas->estadoId('ABIERTA');
        $id = $this->transaccion(function () use ($request, $usuarioId, $datos, $abierta, $fecha): int {
            $id = $this->alertas->crear($datos + ['estado_id' => $abierta, 'fecha_generacion' => $fecha]);
            $this->alertas->historial($id, null, $abierta, $usuarioId, null, $fecha);
            $this->auditar($request, $datos['cliente_id'], $usuarioId, $id, 'ALERTA_CREATE', null, [
                'estado' => 'ABIERTA',
                'nivel' => $datos['nivel'],
                'tipo_alerta_id' => $datos['tipo_alerta_id'],
                'generada_automaticamente' => 0,
            ]);

            return $id;
        });

        return $this->presentar($id);
    }

    /** @return array<string, mixed> */
    public function tomarAtencion(Request $request, int $id): array
    {
        return $this->transicionar($request, $id, ['ABIERTA'], 'EN_ATENCION', false, 'ALERTA_EN_ATENCION');
    }

    /** @return array<string, mixed> */
    public function atender(Request $request, int $id): array
    {
        return $this->transicionar($request, $id, ['ABIERTA', 'EN_ATENCION'], 'ATENDIDA', true, 'ALERTA_ATENDIDA');
    }

    /** @return array<string, mixed> */
    public function descartar(Request $request, int $id): array
    {
        return $this->transicionar($request, $id, ['ABIERTA', 'EN_ATENCION'], 'DESCARTADA', true, 'ALERTA_DESCARTADA');
    }

    /** @param list<string> $origenes @return array<string, mixed> */
    private function transicionar(Request $request, int $id, array $origenes, string $destino, bool $obligatoria, string $accion): array
    {
        $usuarioId = (int) $request->userId();
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::OPERACION);
        $datos = $obligatoria ? $this->validator->cerrar($request->json() ?? []) : $this->validator->tomarAtencion($request->json() ?? []);
        $previo = $this->exigirVisible($usuarioId, $id);
        $this->transaccion(function () use ($request, $usuarioId, $id, $datos, $previo, $origenes, $destino, $accion): void {
            $alerta = $this->exigirBloqueada($id, (int) $previo['cliente_id']);
            $this->esperaDePrueba();
            $alerta = $this->exigirBloqueada($id, (int) $previo['cliente_id']);
            if (!in_array($alerta['estado_codigo'], $origenes, true) || (int) $alerta['es_final'] === 1) {
                throw new ConflictException('La alerta ya no admite esta transición.', 'ALERTA_ALREADY_CLOSED');
            }
            $nuevo = $this->alertas->estadoId($destino);
            $fecha = (new DateTimeImmutable('now', new DateTimeZone('America/Lima')))->format('Y-m-d H:i:s');
            if ($this->alertas->marcarEstado($id, $nuevo, (int) $alerta['estado_id']) !== 1) {
                throw new ConflictException('La alerta ya no admite esta transición.', 'ALERTA_ALREADY_CLOSED');
            }
            $this->alertas->historial($id, (int) $alerta['estado_id'], $nuevo, $usuarioId, $datos['observacion'], $fecha);
            $this->auditar($request, (int) $alerta['cliente_id'], $usuarioId, $id, $accion, ['estado' => $alerta['estado_codigo']], [
                'estado' => $destino,
            ]);
        }, 'ALERTA_ALREADY_CLOSED');

        return $this->presentar($id);
    }

    private function exigirMismoCliente(?int $id, int $clienteId, bool $unidad): void
    {
        if ($id === null) {
            return;
        }
        $row = $unidad ? $this->alertas->unidad($id) : $this->alertas->neumatico($id);
        $campo = $unidad ? 'unidad_id' : 'neumatico_id';
        $etiqueta = $unidad ? 'La unidad' : 'El neumático';
        if ($row === null || (int) $row['eliminado'] === 1 || (int) $row['cliente_id'] !== $clienteId) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                $campo => [$etiqueta . ' no pertenece al cliente.'],
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function exigirVisible(int $usuarioId, int $id): array
    {
        $alerta = $this->alertas->ficha($id);
        if ($alerta === null || ($this->ocultaDescartadas($usuarioId) && $alerta['estado_codigo'] === 'DESCARTADA')) {
            $this->ausente($usuarioId, 'Alerta no encontrada.');
        }
        $this->authorization->requireClientAccess($usuarioId, (int) $alerta['cliente_id']);

        return $alerta;
    }

    /** @return array<string, mixed> */
    private function exigirBloqueada(int $id, int $clienteId): array
    {
        $alerta = $this->alertas->bloquear($id);
        if ($alerta === null || (int) $alerta['cliente_id'] !== $clienteId) {
            throw new NotFoundException('Alerta no encontrada.');
        }

        return $alerta;
    }

    private function ocultaDescartadas(int $usuarioId): bool
    {
        if ($this->authorization->hasRole($usuarioId, 'ADMIN_GENERAL', 'GESTOR_NEUMATICOS', 'TECNICO_INSPECCION', 'VENDEDOR')) {
            return false;
        }

        return $this->authorization->hasRole($usuarioId, 'ADMIN_CLIENTE', 'CONSULTA_EJECUTIVA');
    }

    /** @return array<string, mixed> */
    private function presentar(int $id): array
    {
        $row = $this->alertas->ficha($id);
        if ($row === null) {
            throw new NotFoundException('Alerta no encontrada.');
        }
        $historial = array_map(static function (array $item): array {
            return [
                'id' => (int) $item['id'],
                'fecha' => $item['fecha_cambio'],
                'observacion' => $item['observacion'],
                'anterior' => $item['anterior_codigo'] === null ? null : [
                    'codigo' => $item['anterior_codigo'],
                    'nombre' => $item['anterior_nombre'],
                ],
                'nuevo' => [
                    'codigo' => $item['nuevo_codigo'],
                    'nombre' => $item['nuevo_nombre'],
                ],
                'usuario' => trim($item['nombres'] . ' ' . $item['apellidos']),
            ];
        }, $this->alertas->historialDe($id));

        return $this->resumen($row) + [
            'descripcion' => $row['descripcion'],
            'recomendacion' => $row['recomendacion'],
            'inspeccion' => $row['inspeccion_id'] === null ? null : [
                'id' => (int) $row['inspeccion_id'],
                'fecha' => $row['fecha_inspeccion'],
            ],
            'detalle' => $row['inspeccion_detalle_id'] === null ? null : [
                'id' => (int) $row['inspeccion_detalle_id'],
                'posicion' => $row['posicion_codigo_snapshot'],
            ],
            'historial' => $historial,
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function resumen(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'fecha_generacion' => $row['fecha_generacion'],
            'nivel' => $row['nivel'],
            'titulo' => $row['titulo'],
            'origen' => (int) $row['generada_automaticamente'] === 1 ? 'AUTOMATICA' : 'MANUAL',
            'generada_automaticamente' => (int) $row['generada_automaticamente'] === 1,
            'cliente' => [
                'id' => (int) $row['cliente_id'],
                'razon_social' => $row['razon_social'],
                'nombre_comercial' => $row['nombre_comercial'],
            ],
            'unidad' => $row['unidad_id'] === null ? null : [
                'id' => (int) $row['unidad_id'],
                'codigo' => $row['unidad_codigo'],
                'placa' => $row['unidad_placa'],
            ],
            'neumatico' => $row['neumatico_id'] === null ? null : [
                'id' => (int) $row['neumatico_id'],
                'codigo' => $row['neumatico_codigo'],
            ],
            'tipo' => [
                'id' => (int) $row['tipo_id'],
                'codigo' => $row['tipo_codigo'],
                'nombre' => $row['tipo_nombre'],
            ],
            'estado' => [
                'id' => (int) $row['estado_id'],
                'codigo' => $row['estado_codigo'],
                'nombre' => $row['estado_nombre'],
                'es_final' => (int) $row['es_final'] === 1,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function filtros(int $usuarioId, Request $request): array
    {
        $query = $request->queryParams();
        $clienteId = $this->entero($query['cliente_id'] ?? null, 'cliente_id');
        if ($clienteId !== null) {
            $this->authorization->requireClientAccess($usuarioId, $clienteId);
        }
        $nivel = isset($query['nivel']) && $query['nivel'] !== '' ? strtoupper((string) $query['nivel']) : null;
        if ($nivel !== null && !in_array($nivel, self::NIVELES, true)) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'nivel' => ['El nivel no es válido.'],
            ]);
        }
        $automatica = $this->bandera($query['generada_automaticamente'] ?? null);
        unset($query['nivel'], $query['generada_automaticamente'], $query['cliente_id'], $query['tipo_alerta_id'], $query['estado_id'], $query['unidad_id'], $query['neumatico_id'], $query['fecha_inicio'], $query['fecha_fin']);
        if (!isset($query['sort']) || $query['sort'] === '') {
            $query['sort'] = 'fecha_generacion';
        }
        if (!isset($query['order']) || $query['order'] === '') {
            $query['order'] = 'desc';
        }
        $criteria = ListCriteria::from($query, [
            'fecha_generacion' => 'a.fecha_generacion',
            'nivel' => 'a.nivel',
            'id' => 'a.id',
        ], null, false, true);
        $busqueda = $criteria->search;

        return [
            'usuario_scope' => $this->authorization->isAdminGeneral($usuarioId) ? null : $usuarioId,
            'ocultar_descartadas' => $this->ocultaDescartadas($usuarioId),
            'cliente_id' => $clienteId,
            'tipo_alerta_id' => $this->entero($request->queryParams()['tipo_alerta_id'] ?? null, 'tipo_alerta_id'),
            'estado_id' => $this->entero($request->queryParams()['estado_id'] ?? null, 'estado_id'),
            'unidad_id' => $this->entero($request->queryParams()['unidad_id'] ?? null, 'unidad_id'),
            'neumatico_id' => $this->entero($request->queryParams()['neumatico_id'] ?? null, 'neumatico_id'),
            'nivel' => $nivel,
            'generada_automaticamente' => $automatica,
            'fecha_inicio' => $this->fechaFiltro($request->queryParams()['fecha_inicio'] ?? null, false),
            'fecha_fin' => $this->fechaFiltro($request->queryParams()['fecha_fin'] ?? null, true),
            'search' => $busqueda === null ? null : '%' . addcslashes($busqueda, '\\%_') . '%',
            'sort' => $criteria->sortExpression,
            'direction' => $criteria->direction,
            'limit' => $criteria->limit,
            'offset' => $criteria->offset,
        ];
    }

    private function bandera(mixed $valor): ?int
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        $texto = (string) $valor;
        if (!in_array($texto, ['0', '1'], true)) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'generada_automaticamente' => ['El origen debe ser automático o manual.'],
            ]);
        }

        return (int) $texto;
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
        $ms = getenv('ALERTA_TRANSITION_HOLD_MS');
        if (!is_string($ms) || preg_match('/^[1-9][0-9]{0,3}$/', $ms) !== 1) {
            return;
        }
        $signal = getenv('ALERTA_TRANSITION_HOLD_SIGNAL');
        if (is_string($signal) && preg_match('/^alerta-hold-[A-Za-z0-9._-]+$/', $signal) === 1) {
            $directorio = realpath(sys_get_temp_dir());
            if (is_string($directorio)) {
                file_put_contents($directorio . DIRECTORY_SEPARATOR . $signal, '1');
            }
        }
        usleep(min((int) $ms, 5000) * 1000);
    }

    /** @param array<string, mixed>|null $antes @param array<string, mixed>|null $despues */
    private function auditar(Request $request, int $clienteId, int $usuarioId, int $alertaId, string $accion, ?array $antes, ?array $despues): void
    {
        $this->auditoria->registrar($clienteId, $usuarioId, 'alertas', $alertaId, $accion, $antes, $despues, $request->ip(), $request->userAgent());
    }

    private function ausente(int $usuarioId, string $mensaje): never
    {
        if ($this->authorization->isAdminGeneral($usuarioId)) {
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
            if (in_array($codigo, ['1062', '1205', '1213'], true)) {
                throw new ConflictException('La alerta ya fue actualizada.', $conflicto ?? 'ALERTA_ALREADY_CLOSED');
            }
            throw $exception;
        }
    }
}
