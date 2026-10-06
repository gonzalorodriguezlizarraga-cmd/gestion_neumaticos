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
use App\Repositories\SeguimientoRepository;
use App\Support\ListCriteria;
use App\Support\Transaction;
use App\Validators\OportunidadValidator;
use DateTimeImmutable;
use DateTimeZone;
use PDOException;

final class OportunidadService
{
    public function __construct(
        private readonly OportunidadRepository $oportunidades,
        private readonly SeguimientoRepository $seguimientos,
        private readonly CotizacionRepository $cotizaciones,
        private readonly AuditoriaRepository $auditoria,
        private readonly AuthorizationService $authorization,
        private readonly ComercialAcceso $acceso,
        private readonly OportunidadValidator $validator,
        private readonly Transaction $transaction,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function estados(Request $request): array
    {
        $this->acceso->exigirLectura((int) $request->userId());

        return array_map(static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'codigo' => $row['codigo'],
                'nombre' => $row['nombre'],
                'es_final' => (int) $row['es_final'] === 1,
            ];
        }, $this->oportunidades->estados());
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function clientes(Request $request): array
    {
        $usuarioId = (int) $request->userId();
        $this->acceso->exigirLectura($usuarioId);
        $query = $request->queryParams();
        $criteria = ListCriteria::from($query, ['razon_social' => 'c.razon_social'], null, false, true);
        $modo = 'admin';
        if (!$this->acceso->esAdmin($usuarioId)) {
            $modo = $this->acceso->esVendedor($usuarioId) ? 'vendedor' : 'gestor';
        }
        $resultado = $this->oportunidades->clientes($criteria, ['modo' => $modo, 'usuario_id' => $usuarioId]);

        return [
            'rows' => array_map(static function (array $row): array {
                return [
                    'id' => (int) $row['id'],
                    'razon_social' => $row['razon_social'],
                    'nombre_comercial' => $row['nombre_comercial'],
                    'estado' => $row['estado'],
                ];
            }, $resultado['rows']),
            'total' => $resultado['total'],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function responsables(Request $request, int $clienteId): array
    {
        $usuarioId = (int) $request->userId();
        $this->acceso->exigirLectura($usuarioId);
        $this->exigirClienteLectura($usuarioId, $clienteId);
        $rows = $this->oportunidades->responsables($clienteId);
        if ($this->acceso->esVendedor($usuarioId) && !$this->acceso->esAdmin($usuarioId)) {
            $rows = array_values(array_filter($rows, static fn (array $row): bool => (int) $row['id'] === $usuarioId));
        }

        return array_map(static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'nombres' => $row['nombres'],
                'apellidos' => $row['apellidos'],
            ];
        }, $rows);
    }

    /** @return array<string, mixed> */
    public function resumen(Request $request, int $clienteId): array
    {
        $usuarioId = (int) $request->userId();
        $this->acceso->exigirLectura($usuarioId);
        $this->exigirClienteLectura($usuarioId, $clienteId);
        $responsable = $this->acceso->esVendedor($usuarioId) && !$this->acceso->esAdmin($usuarioId) ? $usuarioId : null;
        $datos = $this->oportunidades->resumen($clienteId, $responsable);

        return [
            'oportunidades_abiertas' => $datos['abiertas'],
            'ultima_actividad' => $datos['ultima_actividad'],
            'proximo_seguimiento' => $this->indicador($datos['proximo'] === false ? null : $datos['proximo']),
            'cotizaciones_recientes' => array_map(static function (array $row): array {
                return [
                    'id' => (int) $row['id'],
                    'numero' => $row['numero'],
                    'fecha' => $row['fecha'],
                    'estado' => $row['estado'],
                    'moneda' => $row['moneda'],
                    'total' => $row['total'],
                    'oportunidad' => $row['oportunidad_titulo'],
                ];
            }, $this->cotizaciones->recientes($clienteId, $responsable)),
        ];
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function listar(Request $request): array
    {
        $usuarioId = (int) $request->userId();
        $this->acceso->exigirLectura($usuarioId);
        $query = $request->queryParams();
        $filtros = $this->filtros($usuarioId, $query);
        unset(
            $query['cliente_id'],
            $query['responsable_comercial_id'],
            $query['estado_id'],
            $query['origen'],
            $query['alerta_id'],
            $query['moneda'],
            $query['fecha_inicio'],
            $query['fecha_fin'],
            $query['fecha_necesidad_inicio'],
            $query['fecha_necesidad_fin'],
        );
        if (!isset($query['sort']) || $query['sort'] === '') {
            $query['sort'] = 'fecha_deteccion';
        }
        if (!isset($query['order']) || $query['order'] === '') {
            $query['order'] = 'desc';
        }
        $criteria = ListCriteria::from($query, [
            'fecha_deteccion' => 'o.fecha_deteccion',
            'titulo' => 'o.titulo',
            'valor_estimado' => 'o.valor_estimado',
            'fecha_estimada_necesidad' => 'o.fecha_estimada_necesidad',
            'id' => 'o.id',
        ], null, false, true);
        $resultado = $this->oportunidades->listar($criteria, $filtros);

        return [
            'rows' => array_map(fn (array $row): array => $this->resumenFila($row), $resultado['rows']),
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

    /** @return list<array<string, mixed>> */
    public function historial(Request $request, int $id): array
    {
        $usuarioId = (int) $request->userId();
        $this->acceso->exigirLectura($usuarioId);
        $this->exigirVisible($usuarioId, $id);

        return $this->presentar($id)['historial'];
    }

    /** @return list<array<string, mixed>> */
    public function seguimientos(Request $request, int $id): array
    {
        $usuarioId = (int) $request->userId();
        $this->acceso->exigirLectura($usuarioId);
        $this->exigirVisible($usuarioId, $id);

        return $this->presentar($id)['seguimientos'];
    }

    /** @return array<string, mixed> */
    public function crear(Request $request): array
    {
        $usuarioId = (int) $request->userId();
        $this->acceso->exigirEscritura($usuarioId);
        $datos = $this->validator->crear($request->json());
        $this->prepararAlta($usuarioId, $datos);
        $id = $this->transaccion(function () use ($request, $usuarioId, $datos): int {
            $abierta = $this->oportunidades->estadoId('ABIERTA');
            $id = $this->oportunidades->crear($datos, $abierta, 'MANUAL', null, $usuarioId);
            $this->validarDetalles($datos['detalles']);
            $this->oportunidades->reemplazarDetalles($id, $datos['detalles']);
            $this->oportunidades->historial($id, null, $abierta, $usuarioId, null);
            $this->auditar($request, $datos['cliente_id'], $usuarioId, $id, 'OPORTUNIDAD_CREATE', null, [
                'origen' => 'MANUAL',
                'estado' => 'ABIERTA',
            ]);

            return $id;
        });

        return $this->presentar($id);
    }

    /** @return array<string, mixed> */
    public function desdeAlerta(Request $request, int $alertaId): array
    {
        $usuarioId = (int) $request->userId();
        $this->acceso->exigirEscritura($usuarioId);
        $datos = $this->validator->desdeAlerta($request->json());
        $id = $this->transaccion(function () use ($request, $usuarioId, $alertaId, $datos): int {
            $alerta = $this->oportunidades->bloquearAlerta($alertaId);
            if ($alerta === null) {
                $this->ausente($usuarioId, 'Alerta no encontrada.');
            }
            $this->authorization->requireClientAccess($usuarioId, (int) $alerta['cliente_id']);
            $datos['cliente_id'] = (int) $alerta['cliente_id'];
            $datos['fecha_deteccion'] = (new DateTimeImmutable('now', new DateTimeZone('America/Lima')))->format('Y-m-d H:i:s');
            $this->prepararAlta($usuarioId, $datos, false);
            if ($this->oportunidades->existePorAlerta($alertaId)) {
                throw new ConflictException('Esta alerta ya tiene una oportunidad vinculada.', 'OPORTUNIDAD_ALERTA_EXISTENTE');
            }
            $abierta = $this->oportunidades->estadoId('ABIERTA');
            $id = $this->oportunidades->crear($datos, $abierta, 'ALERTA', $alertaId, $usuarioId);
            $this->validarDetalles($datos['detalles']);
            $this->oportunidades->reemplazarDetalles($id, $datos['detalles']);
            $this->oportunidades->historial($id, null, $abierta, $usuarioId, null);
            $this->auditar($request, (int) $alerta['cliente_id'], $usuarioId, $id, 'OPORTUNIDAD_CREATE', null, [
                'origen' => 'ALERTA',
                'alerta_id' => $alertaId,
                'estado' => 'ABIERTA',
            ]);

            return $id;
        }, 'OPORTUNIDAD_ALERTA_EXISTENTE');

        return $this->presentar($id);
    }

    /** @return array<string, mixed> */
    public function editar(Request $request, int $id): array
    {
        $usuarioId = (int) $request->userId();
        $this->acceso->exigirEscritura($usuarioId);
        $datos = $this->validator->editar($request->json());
        $this->transaccion(function () use ($request, $usuarioId, $id, $datos): void {
            $actual = $this->oportunidades->bloquear($id);
            if ($actual === null || !$this->puedeVer($usuarioId, $actual)) {
                $this->ausente($usuarioId, 'Oportunidad no encontrada.');
            }
            if ((int) $actual['es_final'] === 1) {
                throw new ConflictException('Una oportunidad final no se edita.', 'OPORTUNIDAD_YA_CERRADA');
            }
            $responsable = $datos['responsable_comercial_id'] ?? (int) $actual['responsable_comercial_id'];
            if ($this->acceso->esVendedor($usuarioId) && !$this->acceso->esAdmin($usuarioId) && (int) $responsable !== $usuarioId) {
                throw new ValidationException('Los datos enviados no son válidos.', [
                    'responsable_comercial_id' => ['El vendedor solo puede mantenerse como responsable.'],
                ]);
            }
            if (!$this->oportunidades->comercialVigente((int) $responsable, (int) $actual['cliente_id'])) {
                throw new ValidationException('Los datos enviados no son válidos.', [
                    'responsable_comercial_id' => ['El responsable no tiene una asignación comercial vigente.'],
                ]);
            }
            $datos['responsable_comercial_id'] = (int) $responsable;
            $this->validarDetalles($datos['detalles']);
            $this->oportunidades->actualizar($id, $datos, $usuarioId);
            $this->oportunidades->reemplazarDetalles($id, $datos['detalles']);
            $this->auditar($request, (int) $actual['cliente_id'], $usuarioId, $id, 'OPORTUNIDAD_UPDATE', [
                'titulo' => $actual['titulo'],
            ], ['titulo' => $datos['titulo']]);
        });

        return $this->presentar($id);
    }

    /** @return array<string, mixed> */
    public function iniciarSeguimiento(Request $request, int $id): array
    {
        return $this->transicionar($request, $id, ['ABIERTA'], 'EN_SEGUIMIENTO', false, 'OPORTUNIDAD_EN_SEGUIMIENTO');
    }

    /** @return array<string, mixed> */
    public function marcarCotizada(Request $request, int $id): array
    {
        return $this->transicionar($request, $id, ['ABIERTA', 'EN_SEGUIMIENTO'], 'COTIZADA', false, 'OPORTUNIDAD_COTIZADA');
    }

    /** @return array<string, mixed> */
    public function ganar(Request $request, int $id): array
    {
        return $this->transicionar($request, $id, ['EN_SEGUIMIENTO', 'COTIZADA'], 'GANADA', false, 'OPORTUNIDAD_GANADA');
    }

    /** @return array<string, mixed> */
    public function perder(Request $request, int $id): array
    {
        return $this->transicionar($request, $id, ['ABIERTA', 'EN_SEGUIMIENTO', 'COTIZADA'], 'PERDIDA', true, 'OPORTUNIDAD_PERDIDA');
    }

    /** @return array<string, mixed> */
    public function cancelar(Request $request, int $id): array
    {
        return $this->transicionar($request, $id, ['ABIERTA', 'EN_SEGUIMIENTO', 'COTIZADA'], 'CANCELADA', true, 'OPORTUNIDAD_CANCELADA');
    }

    /** @param list<string> $origenes @return array<string, mixed> */
    private function transicionar(Request $request, int $id, array $origenes, string $destino, bool $motivoObligatorio, string $accion): array
    {
        $usuarioId = (int) $request->userId();
        $this->acceso->exigirEscritura($usuarioId);
        $motivo = $this->validator->motivo($request->json(), $motivoObligatorio)['motivo'];
        $this->transaccion(function () use ($request, $usuarioId, $id, $origenes, $destino, $motivo, $accion): void {
            $this->oportunidades->bloquear($id);
            $this->esperaDePrueba();
            $actual = $this->oportunidades->bloquear($id);
            if ($actual === null || !$this->puedeVer($usuarioId, $actual)) {
                $this->ausente($usuarioId, 'Oportunidad no encontrada.');
            }
            if (!in_array($actual['estado_codigo'], $origenes, true)) {
                throw new ConflictException('La oportunidad ya no admite esta transición.', 'OPORTUNIDAD_YA_CERRADA');
            }
            $nuevo = $this->oportunidades->estadoId($destino);
            if (!$this->oportunidades->marcarEstado($id, $nuevo, (int) $actual['estado_id'], $usuarioId)) {
                throw new ConflictException('La oportunidad ya no admite esta transición.', 'OPORTUNIDAD_YA_CERRADA');
            }
            $this->oportunidades->historial($id, (int) $actual['estado_id'], $nuevo, $usuarioId, $motivo);
            $this->auditar($request, (int) $actual['cliente_id'], $usuarioId, $id, $accion, [
                'estado' => $actual['estado_codigo'],
            ], ['estado' => $destino, 'motivo' => $motivo]);
        }, 'OPORTUNIDAD_YA_CERRADA');

        return $this->presentar($id);
    }

    /** @param array<string, mixed> $datos */
    private function prepararAlta(int $usuarioId, array $datos, bool $exigirScope = true): void
    {
        $cliente = $this->oportunidades->cliente((int) $datos['cliente_id']);
        if ($cliente === null || (int) $cliente['eliminado'] === 1) {
            $this->ausente($usuarioId, 'Cliente no encontrado.');
        }
        if (!in_array($cliente['estado'], ['ACTIVO', 'POTENCIAL'], true)) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'cliente_id' => ['Solo un cliente activo o potencial admite una nueva oportunidad.'],
            ]);
        }
        if ($exigirScope || !$this->acceso->esAdmin($usuarioId)) {
            $this->authorization->requireClientAccess($usuarioId, (int) $cliente['id']);
        }
        if ($this->acceso->esVendedor($usuarioId) && !$this->acceso->esAdmin($usuarioId) && (int) $datos['responsable_comercial_id'] !== $usuarioId) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'responsable_comercial_id' => ['El vendedor solo puede crear oportunidades a su nombre.'],
            ]);
        }
        if (!$this->oportunidades->comercialVigente((int) $datos['responsable_comercial_id'], (int) $cliente['id'])) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'responsable_comercial_id' => ['El responsable no tiene una asignación comercial vigente con este cliente.'],
            ]);
        }
    }

    /** @param list<array<string, mixed>> $detalles */
    private function validarDetalles(array $detalles): void
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

    private function exigirClienteLectura(int $usuarioId, int $clienteId): void
    {
        $cliente = $this->oportunidades->cliente($clienteId);
        if ($cliente === null || (int) $cliente['eliminado'] === 1) {
            $this->ausente($usuarioId, 'Cliente no encontrado.');
        }
        if (!$this->acceso->esAdmin($usuarioId)) {
            $this->authorization->requireClientAccess($usuarioId, $clienteId);
        }
        if ($this->acceso->esVendedor($usuarioId) && !$this->acceso->esAdmin($usuarioId) && !$this->oportunidades->comercialVigente($usuarioId, $clienteId)) {
            throw new AuthorizationException('No tiene acceso al cliente solicitado.', 'CLIENT_SCOPE_FORBIDDEN');
        }
    }

    /** @return array<string, mixed> */
    private function exigirVisible(int $usuarioId, int $id): array
    {
        $row = $this->oportunidades->ficha($id);
        if ($row === null || !$this->puedeVer($usuarioId, $row)) {
            $this->ausente($usuarioId, 'Oportunidad no encontrada.');
        }

        return $row;
    }

    /** @param array<string, mixed> $row */
    private function puedeVer(int $usuarioId, array $row): bool
    {
        if ($this->acceso->esAdmin($usuarioId)) {
            return true;
        }
        if ($this->acceso->esVendedor($usuarioId) && (int) $row['responsable_comercial_id'] !== $usuarioId && (int) ($row['responsable_id'] ?? 0) !== $usuarioId) {
            return false;
        }
        if (!$this->authorization->canAccessClient($usuarioId, (int) $row['cliente_id'])) {
            return false;
        }
        if ($this->acceso->esVendedor($usuarioId)) {
            return (int) ($row['responsable_comercial_id'] ?? $row['responsable_id'] ?? 0) === $usuarioId;
        }

        return $this->acceso->esGestor($usuarioId);
    }

    /** @param array<string, mixed> $query @return array<string, mixed> */
    private function filtros(int $usuarioId, array $query): array
    {
        $responsableForzado = null;
        $alcance = null;
        if ($this->acceso->esAdmin($usuarioId)) {
            $responsableForzado = null;
        } elseif ($this->acceso->esVendedor($usuarioId)) {
            $responsableForzado = $usuarioId;
            $alcance = $usuarioId;
            if (isset($query['responsable_comercial_id']) && (string) $query['responsable_comercial_id'] !== '' && (int) $query['responsable_comercial_id'] !== $usuarioId) {
                throw new AuthorizationException('No tiene acceso al responsable solicitado.', 'CLIENT_SCOPE_FORBIDDEN');
            }
        } else {
            $alcance = $usuarioId;
        }
        $clienteId = $this->entero($query['cliente_id'] ?? null, 'cliente_id');
        if ($clienteId !== null && !$this->acceso->esAdmin($usuarioId)) {
            $this->authorization->requireClientAccess($usuarioId, $clienteId);
        }
        $origen = null;
        if (isset($query['origen']) && trim((string) $query['origen']) !== '') {
            $origen = strtoupper(trim((string) $query['origen']));
            if (!in_array($origen, ['MANUAL', 'ALERTA', 'PROYECCION'], true)) {
                throw new ValidationException('Los datos enviados no son válidos.', [
                    'origen' => ['El origen no es válido.'],
                ]);
            }
        }
        $moneda = null;
        if (isset($query['moneda']) && trim((string) $query['moneda']) !== '') {
            $moneda = strtoupper(trim((string) $query['moneda']));
            if (preg_match('/^[A-Z]{3}$/', $moneda) !== 1) {
                throw new ValidationException('Los datos enviados no son válidos.', [
                    'moneda' => ['La moneda debe tener tres letras.'],
                ]);
            }
        }

        return [
            'responsable_forzado' => $responsableForzado,
            'responsable_comercial_id' => $responsableForzado === null ? $this->entero($query['responsable_comercial_id'] ?? null, 'responsable_comercial_id') : null,
            'alcance_usuario' => $alcance,
            'cliente_id' => $clienteId,
            'estado_id' => $this->entero($query['estado_id'] ?? null, 'estado_id'),
            'origen' => $origen,
            'alerta_id' => $this->entero($query['alerta_id'] ?? null, 'alerta_id'),
            'moneda' => $moneda,
            'fecha_inicio' => $this->fechaFiltro($query['fecha_inicio'] ?? null, false),
            'fecha_fin' => $this->fechaFiltro($query['fecha_fin'] ?? null, true),
            'necesidad_inicio' => $this->fechaDia($query['fecha_necesidad_inicio'] ?? null),
            'necesidad_fin' => $this->fechaDia($query['fecha_necesidad_fin'] ?? null),
        ];
    }

    /** @return array<string, mixed> */
    private function presentar(int $id): array
    {
        $row = $this->oportunidades->ficha($id);
        if ($row === null) {
            throw new NotFoundException('Oportunidad no encontrada.');
        }
        $proximo = null;
        foreach (array_reverse($this->seguimientos->deOportunidad($id)) as $seguimiento) {
            if ($seguimiento['proximo_seguimiento'] !== null) {
                $proximo = $seguimiento['proximo_seguimiento'];
                break;
            }
        }

        return [
            'id' => (int) $row['id'],
            'titulo' => $row['titulo'],
            'descripcion' => $row['descripcion'],
            'origen' => $row['origen'],
            'fecha_deteccion' => $row['fecha_deteccion'],
            'fecha_estimada_necesidad' => $row['fecha_estimada_necesidad'],
            'valor_estimado' => $row['valor_estimado'],
            'moneda' => $row['moneda'],
            'cliente' => [
                'id' => (int) $row['cliente_id'],
                'razon_social' => $row['razon_social'],
                'nombre_comercial' => $row['nombre_comercial'],
                'estado' => $row['cliente_estado'],
            ],
            'responsable' => [
                'id' => (int) $row['responsable_id'],
                'nombres' => $row['responsable_nombres'],
                'apellidos' => $row['responsable_apellidos'],
            ],
            'estado' => [
                'id' => (int) $row['estado_id'],
                'codigo' => $row['estado_codigo'],
                'nombre' => $row['estado_nombre'],
                'es_final' => (int) $row['es_final'] === 1,
            ],
            'alerta' => $row['alerta_id'] === null ? null : [
                'id' => (int) $row['alerta_id'],
                'titulo' => $row['alerta_titulo'],
            ],
            'proximo_seguimiento' => $this->indicador($proximo),
            'detalles' => array_map(static function (array $detalle): array {
                return [
                    'id' => (int) $detalle['id'],
                    'cantidad' => $detalle['cantidad'],
                    'precio_estimado' => $detalle['precio_estimado'],
                    'observacion' => $detalle['observacion'],
                    'modelo' => $detalle['modelo_neumatico_id'] === null ? null : [
                        'id' => (int) $detalle['modelo_neumatico_id'],
                        'nombre' => $detalle['modelo_nombre'],
                    ],
                    'medida' => $detalle['medida_neumatico_id'] === null ? null : [
                        'id' => (int) $detalle['medida_neumatico_id'],
                        'descripcion' => $detalle['medida_descripcion'],
                    ],
                ];
            }, $this->oportunidades->detalles($id)),
            'seguimientos' => array_map(static function (array $item): array {
                return [
                    'id' => (int) $item['id'],
                    'tipo' => $item['tipo'],
                    'fecha' => $item['fecha'],
                    'resultado' => $item['resultado'],
                    'proximo_seguimiento' => $item['proximo_seguimiento'],
                    'observacion' => $item['observacion'],
                    'usuario' => trim($item['nombres'] . ' ' . $item['apellidos']),
                ];
            }, $this->seguimientos->deOportunidad($id)),
            'cotizaciones' => array_map(static function (array $item): array {
                return [
                    'id' => (int) $item['id'],
                    'numero' => $item['numero'],
                    'fecha' => $item['fecha'],
                    'estado' => $item['estado'],
                    'moneda' => $item['moneda'],
                    'subtotal' => $item['subtotal'],
                    'total' => $item['total'],
                ];
            }, $this->cotizaciones->porOportunidad($id)),
            'historial' => array_map(static function (array $item): array {
                return [
                    'id' => (int) $item['id'],
                    'fecha' => $item['fecha_cambio'],
                    'motivo' => $item['motivo'],
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
            }, $this->oportunidades->historialDe($id)),
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function resumenFila(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'titulo' => $row['titulo'],
            'origen' => $row['origen'],
            'fecha_deteccion' => $row['fecha_deteccion'],
            'fecha_estimada_necesidad' => $row['fecha_estimada_necesidad'],
            'valor_estimado' => $row['valor_estimado'],
            'moneda' => $row['moneda'],
            'cliente' => [
                'id' => (int) $row['cliente_id'],
                'razon_social' => $row['razon_social'],
                'nombre_comercial' => $row['nombre_comercial'],
            ],
            'responsable' => [
                'id' => (int) $row['responsable_id'],
                'nombres' => $row['responsable_nombres'],
                'apellidos' => $row['responsable_apellidos'],
            ],
            'estado' => [
                'codigo' => $row['estado_codigo'],
                'nombre' => $row['estado_nombre'],
            ],
            'proximo_seguimiento' => $this->indicador($row['proximo_seguimiento'] === null ? null : (string) $row['proximo_seguimiento']),
        ];
    }

    /** @return array{fecha:string,indicador:string}|null */
    private function indicador(?string $fecha): ?array
    {
        if ($fecha === null || $fecha === '') {
            return null;
        }
        $dia = substr($fecha, 0, 10);
        $hoy = (new DateTimeImmutable('now', new DateTimeZone('America/Lima')))->format('Y-m-d');
        $marca = $dia < $hoy ? 'vencido' : ($dia === $hoy ? 'hoy' : 'proximo');

        return ['fecha' => $fecha, 'indicador' => $marca];
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

    private function fechaFiltro(mixed $valor, bool $fin): ?string
    {
        $dia = $this->fechaDia($valor);
        if ($dia === null) {
            return null;
        }

        return $dia . ($fin ? ' 23:59:59' : ' 00:00:00');
    }

    private function fechaDia(mixed $valor): ?string
    {
        if ($valor === null || trim((string) $valor) === '') {
            return null;
        }
        $texto = substr(trim((string) $valor), 0, 10);
        $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', $texto);
        if (!$fecha instanceof DateTimeImmutable || $fecha->format('Y-m-d') !== $texto) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'fecha' => ['La fecha del filtro no es válida.'],
            ]);
        }

        return $texto;
    }

    private function esperaDePrueba(): void
    {
        $ms = getenv('OPORTUNIDAD_TRANSITION_HOLD_MS');
        if (!is_string($ms) || preg_match('/^[1-9][0-9]{0,3}$/', $ms) !== 1) {
            return;
        }
        $signal = getenv('OPORTUNIDAD_TRANSITION_HOLD_SIGNAL');
        if (is_string($signal) && preg_match('/^oportunidad-hold-[A-Za-z0-9._-]+$/', $signal) === 1) {
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
        $this->auditoria->registrar($clienteId, $usuarioId, 'oportunidades', $id, $accion, $antes, $despues, $request->ip(), $request->userAgent());
    }

    private function ausente(int $usuarioId, string $mensaje): never
    {
        if ($this->acceso->esAdmin($usuarioId)) {
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
                throw new ConflictException('La oportunidad ya fue actualizada.', $conflicto ?? 'OPORTUNIDAD_YA_CERRADA');
            }
            throw $exception;
        }
    }
}
