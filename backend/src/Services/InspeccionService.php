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
use App\Support\ArchivoAlmacen;
use App\Support\ListCriteria;
use App\Support\Transaction;
use App\Validators\InspeccionValidator;
use PDOException;

final class InspeccionService
{
    private const ROLES_TECNICO = ['ADMIN_GENERAL', 'GESTOR_NEUMATICOS', 'TECNICO_INSPECCION'];

    public function __construct(
        private readonly InspeccionRepository $inspecciones,
        private readonly AuditoriaRepository $auditoria,
        private readonly AuthorizationService $authorization,
        private readonly InspeccionValidator $validator,
        private readonly Transaction $transaction,
        private readonly ArchivoAlmacen $almacen,
        private readonly int $maxBytes,
    ) {
    }

    /** @return array<string, mixed> */
    public function crear(Request $request): array
    {
        $usuarioId = (int) $request->userId();
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::INSPECCION);
        $datos = $this->validator->cabecera($request->json() ?? []);
        $unidad = $this->exigirUnidad($usuarioId, (int) $datos['unidad_id']);
        $this->validarUnidadInspeccionable($unidad);
        $tecnicoId = $this->resolverTecnico($usuarioId, $datos['tecnico_id'], (int) $unidad['cliente_id']);
        $id = $this->transaccion(function () use ($datos, $unidad, $tecnicoId, $usuarioId, $request): int {
            $bloqueada = $this->inspecciones->bloquearUnidad((int) $unidad['id']);
            if ($bloqueada === null) {
                throw new NotFoundException('Unidad no encontrada.');
            }
            $this->validarUnidadInspeccionable($bloqueada);
            $id = $this->inspecciones->crear([
                'cliente_id' => (int) $bloqueada['cliente_id'],
                'unidad_id' => (int) $bloqueada['id'],
                'fecha_inspeccion' => $datos['fecha_inspeccion'],
                'tecnico_id' => $tecnicoId,
                'kilometraje' => $datos['kilometraje'],
                'horometro' => $datos['horometro'],
                'observacion_general' => $datos['observacion_general'],
                'creado_por' => $usuarioId,
            ]);
            $this->inspecciones->actualizarLectura((int) $bloqueada['id'], $datos['kilometraje'], $datos['horometro']);
            $this->auditar($request, (int) $bloqueada['cliente_id'], $usuarioId, 'inspecciones', $id, 'INSPECCION_CREATE', null, [
                'unidad_id' => (int) $bloqueada['id'],
                'tecnico_id' => $tecnicoId,
            ]);

            return $id;
        });

        return $this->detalle($request, $id);
    }

    /** @return array{rows:list<array<string, mixed>>, total:int} */
    public function listar(Request $request): array
    {
        $usuarioId = (int) $request->userId();
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::LECTURA);
        $criteria = $this->criterios($request);
        $filtros = $this->filtros($usuarioId, $request, $criteria, null, null);
        $resultado = $this->inspecciones->listar($filtros);

        return ['rows' => array_map(fn (array $row): array => $this->resumen($row), $resultado['rows']), 'total' => $resultado['total']];
    }

    /** @return array<string, mixed> */
    public function detalle(Request $request, int $id): array
    {
        $usuarioId = (int) $request->userId();
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::LECTURA);
        $this->exigirVisible($usuarioId, $id);
        $ficha = $this->inspecciones->ficha($id);
        if ($ficha === null) {
            throw new NotFoundException('Inspección no encontrada.');
        }
        $detalles = [];
        foreach ($this->inspecciones->detalles($id) as $detalle) {
            $detalleId = (int) $detalle['id'];
            $detalles[] = [
                'id' => $detalleId,
                'posicion' => [
                    'id' => (int) $detalle['posicion_id'],
                    'codigo' => $detalle['posicion_codigo_snapshot'],
                    'eje_numero' => (int) $detalle['eje_numero_snapshot'],
                    'eje_nombre' => $detalle['eje_nombre_snapshot'],
                    'lado' => $detalle['lado_snapshot'],
                    'ubicacion' => $detalle['ubicacion_snapshot'],
                ],
                'neumatico' => [
                    'id' => (int) $detalle['neumatico_id'],
                    'codigo' => $detalle['neumatico_codigo'],
                    'medida' => $detalle['medida'],
                    'profundidad_minima_mm' => $detalle['profundidad_minima_mm'],
                ],
                'montaje_id' => (int) $detalle['montaje_id'],
                'profundidad_interior_mm' => $detalle['profundidad_interior_mm'],
                'profundidad_centro_mm' => $detalle['profundidad_centro_mm'],
                'profundidad_exterior_mm' => $detalle['profundidad_exterior_mm'],
                'presion_psi' => $detalle['presion_psi'],
                'condicion' => $detalle['condicion'],
                'observacion' => $detalle['observacion'],
                'danos' => array_map(static fn (array $dano): array => [
                    'tipo_dano_id' => (int) $dano['tipo_dano_id'],
                    'codigo' => $dano['codigo'],
                    'nombre' => $dano['nombre'],
                    'severidad' => $dano['severidad'],
                    'observacion' => $dano['observacion'],
                ], $this->inspecciones->danosDeDetalle($detalleId)),
                'archivos' => $this->archivosPublicos('INSPECCION_DETALLE', $detalleId),
            ];
        }

        return [
            'id' => (int) $ficha['id'],
            'fecha_inspeccion' => $ficha['fecha_inspeccion'],
            'estado' => $ficha['estado'],
            'kilometraje' => $ficha['kilometraje'] === null ? null : (int) $ficha['kilometraje'],
            'horometro' => $ficha['horometro'],
            'observacion_general' => $ficha['observacion_general'],
            'finalizada_en' => $ficha['finalizada_en'],
            'cliente' => [
                'id' => (int) $ficha['cliente_id'],
                'razon_social' => $ficha['razon_social'],
                'nombre_comercial' => $ficha['nombre_comercial'],
            ],
            'unidad' => [
                'id' => (int) $ficha['unidad_id'],
                'codigo' => $ficha['unidad_codigo'],
                'estado' => $ficha['unidad_estado'],
            ],
            'tecnico' => [
                'id' => (int) $ficha['tecnico_id'],
                'nombre' => trim($ficha['tecnico_nombres'] . ' ' . $ficha['tecnico_apellidos']),
            ],
            'detalles' => $detalles,
            'archivos' => $this->archivosPublicos('INSPECCION', $id),
        ];
    }

    /** @return array<string, mixed> */
    public function actualizar(Request $request, int $id): array
    {
        $usuarioId = (int) $request->userId();
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::INSPECCION);
        $datos = $this->validator->cabeceraEdicion($request->json() ?? []);
        $this->transaccion(function () use ($request, $id, $usuarioId, $datos): void {
            $inspeccion = $this->exigirBorradorEditable($usuarioId, $id);
            $tecnicoId = (int) $inspeccion['tecnico_id'];
            if ($datos['tecnico_id'] !== null && (int) $datos['tecnico_id'] !== $tecnicoId) {
                $tecnicoId = $this->resolverTecnico($usuarioId, (int) $datos['tecnico_id'], (int) $inspeccion['cliente_id']);
            }
            $this->inspecciones->actualizarCabecera($id, [
                'fecha_inspeccion' => $datos['fecha_inspeccion'],
                'tecnico_id' => $tecnicoId,
                'kilometraje' => $datos['kilometraje'],
                'horometro' => $datos['horometro'],
                'observacion_general' => $datos['observacion_general'],
            ], $usuarioId);
            $this->inspecciones->bloquearUnidad((int) $inspeccion['unidad_id']);
            $this->inspecciones->actualizarLectura((int) $inspeccion['unidad_id'], $datos['kilometraje'], $datos['horometro']);
            $this->auditar($request, (int) $inspeccion['cliente_id'], $usuarioId, 'inspecciones', $id, 'INSPECCION_UPDATE', null, null);
        });

        return $this->detalle($request, $id);
    }

    /** @return array<string, mixed> */
    public function agregarDetalle(Request $request, int $id): array
    {
        $usuarioId = (int) $request->userId();
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::INSPECCION);
        $datos = $this->validator->detalle($request->json() ?? []);
        $detalleId = $this->transaccion(function () use ($request, $id, $usuarioId, $datos): int {
            $inspeccion = $this->exigirBorradorEditable($usuarioId, $id);
            $posicion = $this->inspecciones->posicion((int) $datos['posicion_id']);
            if ($posicion === null || (int) $posicion['configuracion_id'] !== (int) $inspeccion['configuracion_id'] || (int) $posicion['activo'] !== 1) {
                throw new ValidationException('Los datos enviados no son válidos.', [
                    'posicion_id' => ['La posición no pertenece a la configuración de la unidad.'],
                ]);
            }
            $montaje = $this->inspecciones->montajeActivo((int) $inspeccion['unidad_id'], (int) $posicion['id']);
            if ($montaje === null) {
                throw new ValidationException('Los datos enviados no son válidos.', [
                    'posicion_id' => ['La posición no tiene un neumático montado.'],
                ]);
            }
            $detalleId = $this->inspecciones->crearDetalle([
                'inspeccion_id' => $id,
                'cliente_id' => (int) $inspeccion['cliente_id'],
                'posicion_id' => (int) $posicion['id'],
                'neumatico_id' => (int) $montaje['neumatico_id'],
                'montaje_id' => (int) $montaje['id'],
                'posicion_codigo_snapshot' => $posicion['codigo'],
                'eje_numero_snapshot' => (int) $posicion['numero_eje'],
                'eje_nombre_snapshot' => $posicion['eje_nombre'],
                'lado_snapshot' => $posicion['lado'],
                'ubicacion_snapshot' => $posicion['ubicacion'],
                'profundidad_interior_mm' => $datos['profundidad_interior_mm'],
                'profundidad_centro_mm' => $datos['profundidad_centro_mm'],
                'profundidad_exterior_mm' => $datos['profundidad_exterior_mm'],
                'presion_psi' => $datos['presion_psi'],
                'condicion' => $datos['condicion'],
                'observacion' => $datos['observacion'],
            ]);
            $this->auditar($request, (int) $inspeccion['cliente_id'], $usuarioId, 'inspeccion_detalles', $detalleId, 'DETALLE_CREATE', null, [
                'inspeccion_id' => $id,
                'neumatico_id' => (int) $montaje['neumatico_id'],
            ]);

            return $detalleId;
        });
        $detalle = $this->detalle($request, $id);
        foreach ($detalle['detalles'] as $item) {
            if ((int) $item['id'] === $detalleId) {
                return $item;
            }
        }
        throw new NotFoundException('Detalle de inspección no encontrado.');
    }

    /** @return array<string, mixed> */
    public function actualizarDetalle(Request $request, int $id, int $detalleId): array
    {
        $usuarioId = (int) $request->userId();
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::INSPECCION);
        $datos = $this->validator->detalleEdicion($request->json() ?? []);
        $this->transaccion(function () use ($request, $id, $detalleId, $usuarioId, $datos): void {
            $inspeccion = $this->exigirBorradorEditable($usuarioId, $id);
            if ($this->inspecciones->detalleFila($id, $detalleId) === null) {
                throw new NotFoundException('Detalle de inspección no encontrado.');
            }
            $this->inspecciones->actualizarDetalle($detalleId, $datos);
            $this->auditar($request, (int) $inspeccion['cliente_id'], $usuarioId, 'inspeccion_detalles', $detalleId, 'DETALLE_UPDATE', null, null);
        });
        $detalle = $this->detalle($request, $id);
        foreach ($detalle['detalles'] as $item) {
            if ((int) $item['id'] === $detalleId) {
                return $item;
            }
        }
        throw new NotFoundException('Detalle de inspección no encontrado.');
    }

    /** @return array<string, mixed> */
    public function agregarDano(Request $request, int $id, int $detalleId): array
    {
        return $this->mutarDano($request, $id, $detalleId, 'DANO_ADD');
    }

    /** @return array<string, mixed> */
    public function actualizarDano(Request $request, int $id, int $detalleId, int $tipoId): array
    {
        return $this->mutarDano($request, $id, $detalleId, 'DANO_UPDATE', $tipoId);
    }

    public function quitarDano(Request $request, int $id, int $detalleId, int $tipoId): void
    {
        $usuarioId = (int) $request->userId();
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::INSPECCION);
        $this->transaccion(function () use ($request, $id, $detalleId, $tipoId, $usuarioId): void {
            $inspeccion = $this->exigirBorradorEditable($usuarioId, $id);
            if ($this->inspecciones->detalleFila($id, $detalleId) === null) {
                throw new NotFoundException('Detalle de inspección no encontrado.');
            }
            if ($this->inspecciones->quitarDano($detalleId, $tipoId) === 0) {
                throw new NotFoundException('Daño no encontrado.');
            }
            $this->auditar($request, (int) $inspeccion['cliente_id'], $usuarioId, 'inspeccion_detalles', $detalleId, 'DANO_REMOVE', null, [
                'tipo_dano_id' => $tipoId,
            ]);
        });
    }

    /** @return list<array<string, mixed>> */
    public function tiposDano(Request $request): array
    {
        $this->authorization->requireRole((int) $request->userId(), ...ClientePolicy::LECTURA);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'codigo' => $row['codigo'],
            'nombre' => $row['nombre'],
            'descripcion' => $row['descripcion'],
        ], $this->inspecciones->tiposDano());
    }

    /** @return array<string, mixed> */
    public function finalizar(Request $request, int $id): array
    {
        $usuarioId = (int) $request->userId();
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::INSPECCION);
        $this->transaccion(function () use ($request, $id, $usuarioId): void {
            $this->exigirBorradorEditable($usuarioId, $id);
            $this->esperaDePrueba();
            $inspeccion = $this->exigirBorradorEditable($usuarioId, $id);
            if ($this->inspecciones->contarDetalles($id) < 1) {
                throw new ValidationException('Los datos enviados no son válidos.', [
                    'detalles' => ['La inspección debe tener al menos un detalle.'],
                ]);
            }
            if ($this->inspecciones->detallesInconsistentes($id) > 0) {
                throw new ConflictException('La inspección tiene un detalle que ya no puede verificarse.');
            }
            $this->inspecciones->bloquearUnidad((int) $inspeccion['unidad_id']);
            $this->inspecciones->actualizarLectura(
                (int) $inspeccion['unidad_id'],
                $inspeccion['kilometraje'] === null ? null : (int) $inspeccion['kilometraje'],
                $inspeccion['horometro'] === null ? null : (string) $inspeccion['horometro'],
            );
            if ($this->inspecciones->finalizar($id, $usuarioId) !== 1) {
                throw new ConflictException('La inspección ya fue finalizada.', 'INSPECTION_ALREADY_FINALIZED');
            }
            $this->generarAlertas($inspeccion);
            $this->auditar($request, (int) $inspeccion['cliente_id'], $usuarioId, 'inspecciones', $id, 'INSPECCION_FINALIZE', ['estado' => 'BORRADOR'], ['estado' => 'FINALIZADA']);
        }, 'INSPECTION_ALREADY_FINALIZED');

        return $this->detalle($request, $id);
    }

    /** @return array<string, mixed> */
    public function subirArchivo(Request $request, int $id, ?int $detalleId): array
    {
        $usuarioId = (int) $request->userId();
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::INSPECCION);
        $archivo = $request->archivo();
        if ($archivo === null) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'archivo' => ['La fotografía es obligatoria.'],
            ]);
        }
        $imagen = $this->imagen($archivo['contents']);
        $guardado = null;
        try {
            return $this->transaccion(function () use ($request, $id, $detalleId, $usuarioId, $archivo, $imagen, &$guardado): array {
                $inspeccion = $this->exigirBorradorEditable($usuarioId, $id);
                $entidadId = $id;
                $entidad = 'INSPECCION';
                if ($detalleId !== null) {
                    if ($this->inspecciones->detalleFila($id, $detalleId) === null) {
                        throw new NotFoundException('Detalle de inspección no encontrado.');
                    }
                    $entidad = 'INSPECCION_DETALLE';
                    $entidadId = $detalleId;
                }
                $guardado = $this->almacen->guardar((int) $inspeccion['cliente_id'], $entidad, $entidadId, $archivo['contents'], $imagen['extension']);
                $archivoId = $this->inspecciones->crearArchivo([
                    'cliente_id' => (int) $inspeccion['cliente_id'],
                    'entidad_tipo' => $entidad,
                    'entidad_id' => $entidadId,
                    'nombre_original' => $this->nombreOriginal($archivo['name'], $imagen['extension']),
                    'nombre_archivo' => $guardado['nombre'],
                    'ruta' => $guardado['ruta'],
                    'mime_type' => $imagen['mime'],
                    'tamano_bytes' => $guardado['tamano'],
                    'checksum' => $guardado['checksum'],
                    'usuario_id' => $usuarioId,
                ]);
                $this->auditar($request, (int) $inspeccion['cliente_id'], $usuarioId, 'archivos', $archivoId, 'ARCHIVO_UPLOAD', null, [
                    'entidad_tipo' => $entidad,
                    'entidad_id' => $entidadId,
                ]);

                return [
                    'id' => $archivoId,
                    'nombre_original' => $this->nombreOriginal($archivo['name'], $imagen['extension']),
                    'mime_type' => $imagen['mime'],
                    'tamano_bytes' => $guardado['tamano'],
                ];
            });
        } catch (\Throwable $error) {
            if (is_array($guardado)) {
                $this->descartarFisico($guardado['ruta']);
            }
            throw $error;
        }
    }

    /** @return array{contenido:string,mime:string,nombre:string} */
    public function descargar(Request $request, int $archivoId): array
    {
        $usuarioId = (int) $request->userId();
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::LECTURA);
        $archivo = $this->exigirArchivoVisible($usuarioId, $archivoId);
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
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::INSPECCION);
        $this->transaccion(function () use ($request, $archivoId, $usuarioId): void {
            $archivo = $this->inspecciones->archivo($archivoId);
            if ($archivo === null) {
                $this->ausente($usuarioId, 'Archivo no encontrado.');
            }
            $this->authorization->requireClientAccess($usuarioId, (int) $archivo['cliente_id']);
            if ((int) $archivo['eliminado'] === 1) {
                throw new NotFoundException('Archivo no encontrado.');
            }
            $inspeccion = $this->exigirBorradorEditable($usuarioId, $this->inspeccionDelArchivo($archivo));
            $this->inspecciones->eliminarArchivo($archivoId, $usuarioId);
            $this->auditar($request, (int) $inspeccion['cliente_id'], $usuarioId, 'archivos', $archivoId, 'ARCHIVO_DELETE', null, null);
        });
    }

    /** @return array{rows:list<array<string, mixed>>, total:int} */
    public function deNeumatico(Request $request, int $neumaticoId): array
    {
        $usuarioId = (int) $request->userId();
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::LECTURA);
        $neumatico = $this->inspecciones->neumatico($neumaticoId);
        $this->exigirEntidadCliente($usuarioId, $neumatico, 'Neumático no encontrado.');

        return $this->listarFiltrado($request, $neumaticoId, null);
    }

    /** @return array{rows:list<array<string, mixed>>, total:int} */
    public function deUnidad(Request $request, int $unidadId): array
    {
        $usuarioId = (int) $request->userId();
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::LECTURA);
        $unidad = $this->inspecciones->unidad($unidadId);
        $this->exigirEntidadCliente($usuarioId, $unidad, 'Unidad no encontrada.');

        return $this->listarFiltrado($request, null, $unidadId);
    }

    /** @return array{rows:list<array<string, mixed>>, total:int} */
    private function listarFiltrado(Request $request, ?int $neumaticoId, ?int $unidadId): array
    {
        $usuarioId = $request->userId();
        $criteria = $this->criterios($request);
        $resultado = $this->inspecciones->listar($this->filtros($usuarioId, $request, $criteria, $neumaticoId, $unidadId));

        return ['rows' => array_map(fn (array $row): array => $this->resumen($row), $resultado['rows']), 'total' => $resultado['total']];
    }

    /** @return array<string, mixed> */
    private function mutarDano(Request $request, int $id, int $detalleId, string $accion, ?int $tipoRuta = null): array
    {
        $usuarioId = (int) $request->userId();
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::INSPECCION);
        $datos = $this->validator->dano($request->json() ?? []);
        if ($tipoRuta !== null && $datos['tipo_dano_id'] !== $tipoRuta) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'tipo_dano_id' => ['El tipo de daño no coincide con la ruta.'],
            ]);
        }
        $this->transaccion(function () use ($request, $id, $detalleId, $usuarioId, $datos, $accion): void {
            $inspeccion = $this->exigirBorradorEditable($usuarioId, $id);
            if ($this->inspecciones->detalleFila($id, $detalleId) === null) {
                throw new NotFoundException('Detalle de inspección no encontrado.');
            }
            $tipo = $this->inspecciones->tipoDano((int) $datos['tipo_dano_id']);
            if ($tipo === null || (int) $tipo['activo'] !== 1) {
                throw new ValidationException('Los datos enviados no son válidos.', [
                    'tipo_dano_id' => ['El tipo de daño no existe o no está activo.'],
                ]);
            }
            if ($accion === 'DANO_ADD') {
                $this->inspecciones->agregarDano([
                    'detalle_id' => $detalleId,
                    'tipo_id' => (int) $tipo['id'],
                    'severidad' => $datos['severidad'],
                    'observacion' => $datos['observacion'],
                ]);
            } elseif ($this->inspecciones->actualizarDano($detalleId, (int) $tipo['id'], $datos) === 0) {
                throw new NotFoundException('Daño no encontrado.');
            }
            $this->auditar($request, (int) $inspeccion['cliente_id'], $usuarioId, 'inspeccion_detalles', $detalleId, $accion, null, [
                'tipo_dano_id' => (int) $tipo['id'],
            ]);
        });

        return $this->detalle($request, $id);
    }

    /** @param array<string, mixed> $inspeccion */
    private function generarAlertas(array $inspeccion): void
    {
        $abierta = $this->inspecciones->idCatalogo('estados_alerta', 'ABIERTA');
        $tipoProfundidad = $this->inspecciones->idCatalogo('tipos_alerta', 'PROFUNDIDAD_CRITICA');
        $tipoDano = $this->inspecciones->idCatalogo('tipos_alerta', 'DANO_DETECTADO');
        $danos = [];
        foreach ($this->inspecciones->danosDeInspeccion((int) $inspeccion['id']) as $dano) {
            $danos[(int) $dano['inspeccion_detalle_id']][] = $dano;
        }
        foreach ($this->inspecciones->detallesParaAlertas((int) $inspeccion['id']) as $detalle) {
            $minima = $this->minimaMedida($detalle);
            if ($minima !== null && $detalle['profundidad_minima_mm'] !== null && $this->comparar($minima, (string) $detalle['profundidad_minima_mm']) <= 0 && !$this->inspecciones->alertaAbierta((int) $detalle['id'], $tipoProfundidad)) {
                $this->inspecciones->crearAlerta([
                    'cliente_id' => (int) $inspeccion['cliente_id'],
                    'tipo_alerta_id' => $tipoProfundidad,
                    'estado_id' => $abierta,
                    'unidad_id' => (int) $inspeccion['unidad_id'],
                    'neumatico_id' => (int) $detalle['neumatico_id'],
                    'inspeccion_id' => (int) $inspeccion['id'],
                    'inspeccion_detalle_id' => (int) $detalle['id'],
                    'nivel' => 'CRITICA',
                    'titulo' => 'Profundidad crítica en ' . $detalle['codigo'],
                    'descripcion' => 'La menor profundidad medida es ' . $minima . ' mm y el mínimo del neumático es ' . $detalle['profundidad_minima_mm'] . ' mm.',
                    'recomendacion' => 'Retirar o programar el reemplazo del neumático antes de continuar operando.',
                ]);
            }
            $relevantes = array_values(array_filter(
                $danos[(int) $detalle['id']] ?? [],
                static fn (array $dano): bool => in_array($dano['severidad'], ['ALTA', 'CRITICA'], true),
            ));
            if ($relevantes !== [] && !$this->inspecciones->alertaAbierta((int) $detalle['id'], $tipoDano)) {
                $critico = array_filter($relevantes, static fn (array $dano): bool => $dano['severidad'] === 'CRITICA');
                $nombres = implode(', ', array_map(static fn (array $dano): string => (string) $dano['nombre'], $relevantes));
                $this->inspecciones->crearAlerta([
                    'cliente_id' => (int) $inspeccion['cliente_id'],
                    'tipo_alerta_id' => $tipoDano,
                    'estado_id' => $abierta,
                    'unidad_id' => (int) $inspeccion['unidad_id'],
                    'neumatico_id' => (int) $detalle['neumatico_id'],
                    'inspeccion_id' => (int) $inspeccion['id'],
                    'inspeccion_detalle_id' => (int) $detalle['id'],
                    'nivel' => $critico === [] ? 'ATENCION' : 'CRITICA',
                    'titulo' => 'Daño detectado en ' . $detalle['codigo'],
                    'descripcion' => 'Daños relevantes: ' . $nombres . '.',
                    'recomendacion' => 'Evaluar el neumático y definir si continúa en servicio.',
                ]);
            }
        }
    }

    /** @param array<string, mixed> $detalle */
    private function minimaMedida(array $detalle): ?string
    {
        $valores = [];
        foreach (['profundidad_interior_mm', 'profundidad_centro_mm', 'profundidad_exterior_mm'] as $campo) {
            if ($detalle[$campo] !== null) {
                $valores[] = number_format((float) $detalle[$campo], 2, '.', '');
            }
        }
        if ($valores === []) {
            return null;
        }
        $minima = $valores[0];
        foreach ($valores as $valor) {
            if ($this->comparar($valor, $minima) < 0) {
                $minima = $valor;
            }
        }

        return $minima;
    }

    private function comparar(string $izquierda, string $derecha): int
    {
        return (int) round(((float) $izquierda) * 100) <=> (int) round(((float) $derecha) * 100);
    }

    /** @param array<string, mixed> $archivo */
    private function inspeccionDelArchivo(array $archivo): int
    {
        if ($archivo['entidad_tipo'] === 'INSPECCION') {
            return (int) $archivo['entidad_id'];
        }
        if ($archivo['entidad_tipo'] !== 'INSPECCION_DETALLE') {
            throw new NotFoundException('Archivo no encontrado.');
        }

        return $this->detalleInspeccion((int) $archivo['entidad_id']);
    }

    /** @return array<string, mixed> */
    private function exigirArchivoVisible(int $usuarioId, int $archivoId): array
    {
        $archivo = $this->inspecciones->archivo($archivoId);
        if ($archivo === null) {
            $this->ausente($usuarioId, 'Archivo no encontrado.');
        }
        $this->authorization->requireClientAccess($usuarioId, (int) $archivo['cliente_id']);
        if ((int) $archivo['eliminado'] === 1) {
            throw new NotFoundException('Archivo no encontrado.');
        }
        $this->exigirVisible($usuarioId, $this->inspeccionDelArchivo($archivo));

        return $archivo;
    }

    private function ausente(int $usuarioId, string $mensaje): never
    {
        if ($this->authorization->isAdminGeneral($usuarioId)) {
            throw new NotFoundException($mensaje);
        }
        throw new AuthorizationException('No tiene acceso al cliente solicitado.', 'CLIENT_SCOPE_FORBIDDEN');
    }

    /** @param array<string, mixed>|null $antes @param array<string, mixed>|null $despues */
    private function auditar(Request $request, int $clienteId, int $usuarioId, string $entidad, int $entidadId, string $accion, ?array $antes, ?array $despues): void
    {
        $this->auditoria->registrar($clienteId, $usuarioId, $entidad, $entidadId, $accion, $antes, $despues, $request->ip(), $request->userAgent());
    }

    private function criterios(Request $request): ListCriteria
    {
        $query = $request->queryParams();
        unset($query['estado']);
        if (!isset($query['sort']) || $query['sort'] === '') {
            $query['sort'] = 'fecha_inspeccion';
        }
        if (!isset($query['order']) || $query['order'] === '') {
            $query['order'] = 'desc';
        }

        return ListCriteria::from($query, [
            'fecha_inspeccion' => 'i.fecha_inspeccion',
            'estado' => 'i.estado',
            'id' => 'i.id',
        ], null, false, true);
    }

    private function detalleInspeccion(int $detalleId): int
    {
        $fila = $this->inspecciones->detalleFilaPorId($detalleId);
        if ($fila === null) {
            throw new NotFoundException('Archivo no encontrado.');
        }

        return (int) $fila['inspeccion_id'];
    }

    /** @return list<array<string, mixed>> */
    private function archivosPublicos(string $entidad, int $entidadId): array
    {
        return array_map(static fn (array $archivo): array => [
            'id' => (int) $archivo['id'],
            'nombre_original' => $archivo['nombre_original'],
            'mime_type' => $archivo['mime_type'],
            'tamano_bytes' => (int) $archivo['tamano_bytes'],
            'creado_en' => $archivo['creado_en'],
        ], $this->inspecciones->archivosDe($entidad, $entidadId));
    }

    /** @param array<string, mixed> $unidad */
    private function validarUnidadInspeccionable(array $unidad): void
    {
        if ((int) $unidad['eliminado'] === 1 || (int) $unidad['cliente_eliminado'] === 1) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'unidad_id' => ['La unidad no admite inspecciones.'],
            ]);
        }
        if (!in_array($unidad['cliente_estado'], ['ACTIVO', 'INACTIVO'], true)) {
            throw new ConflictException('El cliente no admite inspecciones.');
        }
        if ($unidad['estado'] === 'BAJA') {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'unidad_id' => ['Una unidad en baja no admite inspecciones.'],
            ]);
        }
        if ($unidad['configuracion_id'] === null) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'unidad_id' => ['La unidad no tiene una configuración asignada.'],
            ]);
        }
    }

    private function resolverTecnico(int $usuarioId, ?int $tecnicoId, int $clienteId): int
    {
        $soloPropio = !$this->authorization->hasRole($usuarioId, 'ADMIN_GENERAL', 'GESTOR_NEUMATICOS');
        if ($tecnicoId === null || $tecnicoId === $usuarioId) {
            $this->validarTecnico($usuarioId, $clienteId);

            return $usuarioId;
        }
        if ($soloPropio) {
            throw new AuthorizationException('No puede asignar la inspección a otro técnico.', 'CLIENT_SCOPE_FORBIDDEN');
        }
        $this->validarTecnico($tecnicoId, $clienteId);

        return $tecnicoId;
    }

    private function validarTecnico(int $tecnicoId, int $clienteId): void
    {
        $tecnico = $this->inspecciones->usuario($tecnicoId);
        if ($tecnico === null || (int) $tecnico['eliminado'] === 1) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'tecnico_id' => ['El técnico no existe.'],
            ]);
        }
        if ($tecnico['estado'] !== 'ACTIVO') {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'tecnico_id' => ['El técnico no está activo.'],
            ]);
        }
        $roles = $this->inspecciones->rolesActivos($tecnicoId);
        if (array_intersect($roles, self::ROLES_TECNICO) === []) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'tecnico_id' => ['El técnico no tiene un rol compatible.'],
            ]);
        }
        if (!in_array('ADMIN_GENERAL', $roles, true) && !$this->authorization->canAccessClient($tecnicoId, $clienteId)) {
            throw new AuthorizationException('El técnico no tiene acceso al cliente.', 'CLIENT_SCOPE_FORBIDDEN');
        }
    }

    /** @return array<string, mixed> */
    private function exigirUnidad(int $usuarioId, int $unidadId): array
    {
        $unidad = $this->inspecciones->unidad($unidadId);
        if ($unidad === null || (int) $unidad['eliminado'] === 1) {
            if ($this->authorization->isAdminGeneral($usuarioId)) {
                throw new NotFoundException('Unidad no encontrada.');
            }
            throw new AuthorizationException('No tiene acceso al cliente solicitado.', 'CLIENT_SCOPE_FORBIDDEN');
        }
        $this->authorization->requireClientAccess($usuarioId, (int) $unidad['cliente_id']);

        return $unidad;
    }

    /** @param array<string, mixed>|null $entidad */
    private function exigirEntidadCliente(int $usuarioId, ?array $entidad, string $mensaje): void
    {
        if ($entidad === null || (int) $entidad['eliminado'] === 1) {
            if ($this->authorization->isAdminGeneral($usuarioId)) {
                throw new NotFoundException($mensaje);
            }
            throw new AuthorizationException('No tiene acceso al cliente solicitado.', 'CLIENT_SCOPE_FORBIDDEN');
        }
        $this->authorization->requireClientAccess($usuarioId, (int) $entidad['cliente_id']);
    }

    /** @return array<string, mixed> */
    private function exigirVisible(int $usuarioId, int $id): array
    {
        $inspeccion = $this->inspecciones->inspeccion($id);
        if ($inspeccion === null) {
            if ($this->authorization->isAdminGeneral($usuarioId)) {
                throw new NotFoundException('Inspección no encontrada.');
            }
            throw new AuthorizationException('No tiene acceso al cliente solicitado.', 'CLIENT_SCOPE_FORBIDDEN');
        }
        $this->authorization->requireClientAccess($usuarioId, (int) $inspeccion['cliente_id']);
        $alcance = $this->alcance($usuarioId);
        $oculta = ($alcance['solo_finalizadas'] && $inspeccion['estado'] !== 'FINALIZADA')
            || ($alcance['tecnico_propietario'] !== null && $inspeccion['estado'] !== 'FINALIZADA' && (int) $inspeccion['tecnico_id'] !== $usuarioId);
        if ($oculta) {
            throw new NotFoundException('Inspección no encontrada.');
        }

        return $inspeccion;
    }

    /** @return array<string, mixed> */
    private function exigirBorradorEditable(int $usuarioId, int $id): array
    {
        $inspeccion = $this->inspecciones->bloquear($id);
        if ($inspeccion === null) {
            if ($this->authorization->isAdminGeneral($usuarioId)) {
                throw new NotFoundException('Inspección no encontrada.');
            }
            throw new AuthorizationException('No tiene acceso al cliente solicitado.', 'CLIENT_SCOPE_FORBIDDEN');
        }
        $this->authorization->requireClientAccess($usuarioId, (int) $inspeccion['cliente_id']);
        if ($inspeccion['estado'] !== 'BORRADOR') {
            throw new ConflictException('La inspección ya fue finalizada.', 'INSPECTION_ALREADY_FINALIZED');
        }
        $propietario = $this->authorization->hasRole($usuarioId, 'ADMIN_GENERAL', 'GESTOR_NEUMATICOS')
            || ((int) $inspeccion['tecnico_id'] === $usuarioId && $this->authorization->hasRole($usuarioId, 'TECNICO_INSPECCION'));
        if (!$propietario) {
            throw new AuthorizationException('No puede modificar una inspección de otro técnico.', 'CLIENT_SCOPE_FORBIDDEN');
        }

        return $inspeccion;
    }

    /**
     * @return array<string, mixed>
     */
    private function filtros(int $usuarioId, Request $request, ListCriteria $criteria, ?int $neumaticoId, ?int $unidadId): array
    {
        $query = $request->queryParams();
        $clienteId = $this->entero($query['cliente_id'] ?? null, 'cliente_id');
        if ($clienteId !== null) {
            $this->authorization->requireClientAccess($usuarioId, $clienteId);
        }
        $estado = isset($query['estado']) && $query['estado'] !== '' ? strtoupper((string) $query['estado']) : null;
        if ($estado !== null && !in_array($estado, ['BORRADOR', 'FINALIZADA', 'ANULADA'], true)) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'estado' => ['El estado de inspección no es válido.'],
            ]);
        }
        $alcance = $this->alcance($usuarioId);
        $busqueda = $criteria->search;

        return [
            'usuario_scope' => $alcance['usuario_scope'],
            'solo_finalizadas' => $alcance['solo_finalizadas'],
            'tecnico_propietario' => $alcance['tecnico_propietario'],
            'cliente_id' => $clienteId,
            'unidad_id' => $unidadId ?? $this->entero($query['unidad_id'] ?? null, 'unidad_id'),
            'tecnico_id' => $this->entero($query['tecnico_id'] ?? null, 'tecnico_id'),
            'estado' => $estado,
            'fecha_inicio' => $this->fechaFiltro($query['fecha_inicio'] ?? null, false),
            'fecha_fin' => $this->fechaFiltro($query['fecha_fin'] ?? null, true),
            'neumatico_id' => $neumaticoId,
            'search' => $busqueda === null ? null : '%' . addcslashes($busqueda, '\\%_') . '%',
            'sort' => $criteria->sortExpression,
            'direction' => $criteria->direction,
            'limit' => $criteria->limit,
            'offset' => $criteria->offset,
        ];
    }

    /** @return array{usuario_scope:?int, solo_finalizadas:bool, tecnico_propietario:?int} */
    private function alcance(int $usuarioId): array
    {
        if ($this->authorization->isAdminGeneral($usuarioId)) {
            return ['usuario_scope' => null, 'solo_finalizadas' => false, 'tecnico_propietario' => null];
        }
        if ($this->authorization->hasRole($usuarioId, 'GESTOR_NEUMATICOS', 'VENDEDOR')) {
            return ['usuario_scope' => $usuarioId, 'solo_finalizadas' => false, 'tecnico_propietario' => null];
        }
        if ($this->authorization->hasRole($usuarioId, 'TECNICO_INSPECCION')) {
            return ['usuario_scope' => $usuarioId, 'solo_finalizadas' => false, 'tecnico_propietario' => $usuarioId];
        }

        return ['usuario_scope' => $usuarioId, 'solo_finalizadas' => true, 'tecnico_propietario' => null];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function resumen(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'fecha_inspeccion' => $row['fecha_inspeccion'],
            'estado' => $row['estado'],
            'kilometraje' => $row['kilometraje'] === null ? null : (int) $row['kilometraje'],
            'horometro' => $row['horometro'],
            'finalizada_en' => $row['finalizada_en'],
            'unidad' => ['id' => (int) $row['unidad_id'], 'codigo' => $row['unidad_codigo']],
            'cliente' => [
                'id' => (int) $row['cliente_id'],
                'razon_social' => $row['razon_social'],
                'nombre_comercial' => $row['nombre_comercial'],
            ],
            'tecnico' => [
                'id' => (int) $row['tecnico_id'],
                'nombre' => trim($row['tecnico_nombres'] . ' ' . $row['tecnico_apellidos']),
            ],
            'resumen' => [
                'posiciones' => (int) $row['posiciones'],
                'criticos' => (int) $row['criticos'],
                'atencion' => (int) $row['atencion'],
            ],
        ];
    }

    /** @return array{mime:string, extension:string} */
    private function imagen(string $contenido): array
    {
        if ($contenido === '' || strlen($contenido) > $this->maxBytes) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'archivo' => ['La fotografía supera el tamaño permitido o está vacía.'],
            ]);
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contenido);
        $permitidos = [
            'image/jpeg' => ['jpg', "\xFF\xD8\xFF"],
            'image/png' => ['png', "\x89PNG\r\n\x1A\n"],
            'image/webp' => ['webp', 'RIFF'],
        ];
        if (!is_string($mime) || !isset($permitidos[$mime]) || !str_starts_with($contenido, $permitidos[$mime][1])) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'archivo' => ['Solo se permiten fotografías JPEG, PNG o WEBP.'],
            ]);
        }
        if ($mime === 'image/webp' && substr($contenido, 8, 4) !== 'WEBP') {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'archivo' => ['Solo se permiten fotografías JPEG, PNG o WEBP.'],
            ]);
        }

        return ['mime' => $mime, 'extension' => $permitidos[$mime][0]];
    }

    private function nombreOriginal(string $nombre, string $extension): string
    {
        $base = pathinfo(str_replace(['\\', '/'], '', $nombre), PATHINFO_FILENAME);
        $base = preg_replace('/[^A-Za-z0-9._ -]/', '', $base) ?? '';
        $base = trim($base);
        if ($base === '') {
            $base = 'fotografia';
        }

        return substr($base, 0, 240) . '.' . $extension;
    }

    private function descartarFisico(string $ruta): void
    {
        if ($this->almacen->existe($ruta)) {
            $real = realpath(dirname(__DIR__, 2) . '/storage/' . str_replace('/', DIRECTORY_SEPARATOR, $ruta));
            if (is_string($real) && is_file($real)) {
                unlink($real);
            }
        }
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
        $normalizada = str_replace('T', ' ', $texto);
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $normalizada) === 1) {
            $normalizada .= ':00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $normalizada) !== 1) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'fecha' => ['La fecha del filtro no es válida.'],
            ]);
        }

        return $normalizada;
    }

    /**
     * La variable INSPECCION_FINALIZE_HOLD_MS solo existe para la prueba de
     * concurrencia. En operación normal no está definida y esta espera no corre.
     */
    private function esperaDePrueba(): void
    {
        $ms = getenv('INSPECCION_FINALIZE_HOLD_MS');
        if (!is_string($ms) || preg_match('/^[1-9][0-9]{0,3}$/', $ms) !== 1) {
            return;
        }
        $signal = getenv('INSPECCION_FINALIZE_HOLD_SIGNAL');
        if (is_string($signal) && preg_match('/^inspeccion-hold-[A-Za-z0-9._-]+$/', $signal) === 1) {
            $directorio = realpath(sys_get_temp_dir());
            if (is_string($directorio)) {
                file_put_contents($directorio . DIRECTORY_SEPARATOR . $signal, '1');
            }
        }
        usleep(min((int) $ms, 5000) * 1000);
    }

    /** @template T @param callable():T $callback @return T */
    private function transaccion(callable $callback, ?string $conflictoLock = null): mixed
    {
        try {
            return $this->transaction->run($callback);
        } catch (PDOException $exception) {
            $codigo = (string) ($exception->errorInfo[1] ?? '');
            if ($conflictoLock !== null && in_array($codigo, ['1205', '1213'], true)) {
                throw new ConflictException('La inspección ya fue finalizada.', $conflictoLock);
            }
            if (in_array($codigo, ['1062', '1205', '1213'], true)) {
                throw new ConflictException('La posición, el neumático o el daño ya está registrado en esta inspección.');
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
