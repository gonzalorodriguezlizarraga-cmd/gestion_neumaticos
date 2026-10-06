<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AuthorizationException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Repositories\IndicadorRepository;

final class IndicadorService
{
    private const RENDIMIENTO = 'Datos insuficientes para calcular rendimiento kilométrico confiable.';

    public function __construct(
        private readonly IndicadorRepository $indicadores,
        private readonly AuthorizationService $authorization,
    ) {
    }

    /** @return array<string, mixed> */
    public function deNeumatico(Request $request, int $id): array
    {
        $usuarioId = (int) $request->userId();
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::LECTURA);
        $neumatico = $this->indicadores->neumatico($id);
        if ($neumatico === null || (int) $neumatico['eliminado'] === 1) {
            $this->ausente($usuarioId, 'Neumático no encontrado.');
        }
        $this->authorization->requireClientAccess($usuarioId, (int) $neumatico['cliente_id']);
        $vidas = $this->indicadores->vidas($id);
        $abiertas = array_values(array_filter($vidas, static fn (array $vida): bool => $vida['fecha_fin'] === null));
        $observaciones = [];
        if (count($abiertas) > 1) {
            $observaciones[] = 'Hay más de una vida abierta.';
        }
        if ($abiertas === []) {
            $observaciones[] = 'No hay una vida abierta.';
        }
        $vida = $abiertas[0] ?? null;
        if ($vida !== null && (int) $vida['numero_vida'] !== (int) $neumatico['vida_actual']) {
            $observaciones[] = 'vida_actual no coincide con la vida abierta.';
        }
        $numeros = array_map(static fn (array $item): int => (int) $item['numero_vida'], $vidas);
        $maximo = $numeros === [] ? 0 : max($numeros);
        if ($maximo !== count($vidas) || count($vidas) !== (int) $neumatico['vida_actual']) {
            $observaciones[] = 'La secuencia de vidas no coincide con vida_actual.';
        }
        $consistente = $observaciones === [] && $vida !== null;
        $cantidad = $consistente
            ? (int) $neumatico['vida_actual'] - 1
            : count(array_filter($numeros, static fn (int $numero): bool => $numero > 1));
        $inicial = $vida === null || $vida['profundidad_inicial_mm'] === null ? null : $this->decimal((string) $vida['profundidad_inicial_mm']);
        $minima = $this->decimal((string) $neumatico['profundidad_minima_mm']);
        $medicion = null;
        $presion = null;
        if ($vida !== null) {
            $medicion = $this->indicadores->medicionVida($id, (string) $vida['fecha_inicio'], $vida['fecha_fin'] === null ? null : (string) $vida['fecha_fin']);
            $presion = $this->indicadores->presionVida($id, (string) $vida['fecha_inicio'], $vida['fecha_fin'] === null ? null : (string) $vida['fecha_fin']);
        }
        $actual = $medicion === null ? null : $this->minimo([
            $medicion['profundidad_interior_mm'],
            $medicion['profundidad_centro_mm'],
            $medicion['profundidad_exterior_mm'],
        ]);
        $inconsistente = false;
        $desgaste = null;
        $porcentaje = null;
        $margen = null;
        if ($actual !== null && $minima !== null) {
            $margen = $this->restar($actual, $minima);
        }
        if ($actual !== null && $inicial !== null) {
            if ($this->centavos($actual) > $this->centavos($inicial)) {
                $inconsistente = true;
                $observaciones[] = 'La profundidad actual supera la profundidad inicial de la vida.';
            } else {
                $desgaste = $this->restar($inicial, $actual);
                $denominador = $minima === null ? 0 : $this->centavos($inicial) - $this->centavos($minima);
                if ($denominador > 0) {
                    $porcentaje = number_format((($this->centavos($inicial) - $this->centavos($actual)) / $denominador) * 100, 2, '.', '');
                }
            }
        }
        $alertas = $this->indicadores->alertas($id);
        $criticidad = 'NORMAL';
        if ($alertas['criticas_no_finales'] > 0) {
            $criticidad = 'CRITICA';
        } elseif ($alertas['activas'] > 0) {
            $criticidad = 'ATENCION';
        }
        $montaje = $this->indicadores->montajeActivo($id);
        $mantenimientos = $this->indicadores->mantenimientos($id);
        $respuesta = [
            'estado' => ['codigo' => $neumatico['estado_codigo'], 'nombre' => $neumatico['estado_nombre']],
            'vida_actual' => (int) $neumatico['vida_actual'],
            'cantidad_reencauches' => $cantidad,
            'profundidad_actual_mm' => $actual,
            'profundidad_referencia_inicial_vida_mm' => $inicial,
            'profundidad_minima_mm' => $minima,
            'margen_profundidad_mm' => $margen,
            'desgaste_vida_mm' => $desgaste,
            'porcentaje_desgaste_utilizado' => $porcentaje,
            'datos_inconsistentes' => $inconsistente,
            'ultima_presion_psi' => $presion === null ? null : $this->decimal($presion),
            'ultima_inspeccion' => $medicion === null ? null : [
                'id' => (int) $medicion['inspeccion_id'],
                'fecha' => $medicion['fecha_inspeccion'],
                'condicion' => $medicion['condicion'],
                'profundidad_interior_mm' => $medicion['profundidad_interior_mm'],
                'profundidad_centro_mm' => $medicion['profundidad_centro_mm'],
                'profundidad_exterior_mm' => $medicion['profundidad_exterior_mm'],
                'presion_psi' => $medicion['presion_psi'],
                'unidad' => [
                    'id' => (int) $medicion['unidad_id'],
                    'codigo' => $medicion['unidad_codigo'],
                    'placa' => $medicion['unidad_placa'],
                ],
            ],
            'mantenimientos_total' => $mantenimientos['total'],
            'mantenimientos_finalizados' => $mantenimientos['finalizados'],
            'mantenimientos_activos' => $mantenimientos['activos'],
            'costo_mantenimiento_total' => $this->indicadores->costosFinalizados($id),
            'costo_adquisicion' => $neumatico['costo_adquisicion'],
            'moneda_adquisicion' => $neumatico['moneda'],
            'alertas_abiertas' => $alertas['abiertas'],
            'alertas_en_atencion' => $alertas['en_atencion'],
            'alertas_criticas_no_finales' => $alertas['criticas_no_finales'],
            'criticidad' => $criticidad,
            'montado' => $montaje !== null,
            'unidad_actual' => $montaje === null ? null : [
                'id' => (int) $montaje['unidad_id'],
                'codigo' => $montaje['unidad_codigo'],
                'placa' => $montaje['unidad_placa'],
            ],
            'posicion_actual' => $montaje === null ? null : [
                'id' => (int) $montaje['posicion_id'],
                'codigo' => $montaje['posicion_codigo'],
            ],
            'fecha_montaje' => $montaje['fecha_montaje'] ?? null,
            'rendimiento' => [
                'km_vida' => null,
                'costo_por_km' => null,
                'km_por_mm' => null,
                'proyeccion_km_restante' => null,
                'disponible' => false,
                'motivo' => self::RENDIMIENTO,
            ],
            'datos_suficientes' => [
                'profundidad' => $actual !== null,
                'desgaste' => $desgaste !== null,
                'porcentaje' => $porcentaje !== null,
                'rendimiento_km' => false,
            ],
        ];
        if ($this->veIntegridad($usuarioId)) {
            $respuesta['integridad'] = [
                'ok' => $observaciones === [],
                'observaciones' => $observaciones,
            ];
        }

        return $respuesta;
    }

    /** @return array<string, mixed> */
    public function operativos(Request $request): array
    {
        $usuarioId = (int) $request->userId();
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::LECTURA);
        $query = $request->queryParams();
        $clienteId = $this->entero($query['cliente_id'] ?? null, 'cliente_id');
        if ($clienteId !== null) {
            $this->authorization->requireClientAccess($usuarioId, $clienteId);
        }
        $sedeId = $this->entero($query['sede_id'] ?? null, 'sede_id');
        $flotaId = $this->entero($query['flota_id'] ?? null, 'flota_id');
        $this->exigirUbicacion($usuarioId, $clienteId, $sedeId, true);
        $this->exigirUbicacion($usuarioId, $clienteId, $flotaId, false);
        $filtros = [
            'usuario_scope' => $this->authorization->isAdminGeneral($usuarioId) ? null : $usuarioId,
            'cliente_id' => $clienteId,
            'sede_id' => $sedeId,
            'flota_id' => $flotaId,
        ];
        $neumaticos = $this->indicadores->neumaticos($filtros);
        $alertas = $this->indicadores->alertasOperativas($filtros);
        $mantenimiento = $this->indicadores->mantenimientosOperativos($filtros);
        $inspecciones = $this->indicadores->inspecciones($filtros);

        return [
            'neumaticos' => [
                'total_registrados' => $neumaticos['total_registrados'] ?? 0,
                'total_operativos' => $neumaticos['total_operativos'] ?? 0,
                'DISPONIBLE' => $neumaticos['disponible'] ?? 0,
                'MONTADO' => $neumaticos['montado'] ?? 0,
                'EN_MANTENIMIENTO' => $neumaticos['en_mantenimiento'] ?? 0,
                'EN_REENCAUCHE' => $neumaticos['en_reencauche'] ?? 0,
                'DESCARTADO' => $neumaticos['descartado'] ?? 0,
            ],
            'vidas' => [
                'vida_1' => $neumaticos['vida_1'] ?? 0,
                'vida_2' => $neumaticos['vida_2'] ?? 0,
                'vida_3_mas' => $neumaticos['vida_3_mas'] ?? 0,
            ],
            'alertas' => [
                'ABIERTA' => $alertas['abiertas'] ?? 0,
                'EN_ATENCION' => $alertas['en_atencion'] ?? 0,
                'criticas_activas' => $alertas['criticas_activas'] ?? 0,
            ],
            'mantenimiento' => [
                'SOLICITADO' => $mantenimiento['solicitado'] ?? 0,
                'ENVIADO' => $mantenimiento['enviado'] ?? 0,
                'EN_PROCESO' => $mantenimiento['en_proceso'] ?? 0,
            ],
            'inspecciones' => $inspecciones,
            'rendimiento_km' => [
                'disponible' => false,
                'motivo' => self::RENDIMIENTO,
            ],
        ];
    }

    private function exigirUbicacion(int $usuarioId, ?int $clienteId, ?int $id, bool $sede): void
    {
        if ($id === null) {
            return;
        }
        $row = $sede ? $this->indicadores->sede($id) : $this->indicadores->flota($id);
        $campo = $sede ? 'sede_id' : 'flota_id';
        if ($row === null || (int) $row['eliminado'] === 1) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                $campo => ['La ubicación no está disponible.'],
            ]);
        }
        $this->authorization->requireClientAccess($usuarioId, (int) $row['cliente_id']);
        if ($clienteId !== null && (int) $row['cliente_id'] !== $clienteId) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                $campo => ['La ubicación no pertenece al cliente.'],
            ]);
        }
    }

    private function veIntegridad(int $usuarioId): bool
    {
        return $this->authorization->hasRole($usuarioId, 'ADMIN_GENERAL', 'GESTOR_NEUMATICOS', 'TECNICO_INSPECCION');
    }

    /** @param list<mixed> $valores */
    private function minimo(array $valores): ?string
    {
        $numeros = [];
        foreach ($valores as $valor) {
            if ($valor !== null) {
                $numeros[] = $this->decimal((string) $valor);
            }
        }
        if ($numeros === []) {
            return null;
        }
        $minimo = $numeros[0];
        foreach ($numeros as $numero) {
            if ($this->centavos((string) $numero) < $this->centavos((string) $minimo)) {
                $minimo = $numero;
            }
        }

        return $minimo;
    }

    private function decimal(string $valor): string
    {
        return number_format((float) $valor, 2, '.', '');
    }

    private function restar(string $izquierda, string $derecha): string
    {
        return number_format(($this->centavos($izquierda) - $this->centavos($derecha)) / 100, 2, '.', '');
    }

    private function centavos(string $valor): int
    {
        return (int) round(((float) $valor) * 100);
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

    private function ausente(int $usuarioId, string $mensaje): never
    {
        if ($this->authorization->isAdminGeneral($usuarioId)) {
            throw new NotFoundException($mensaje);
        }
        throw new AuthorizationException('No tiene acceso al cliente solicitado.', 'CLIENT_SCOPE_FORBIDDEN');
    }
}
