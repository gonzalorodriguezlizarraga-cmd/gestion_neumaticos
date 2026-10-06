<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AuthorizationException;
use App\Http\Request;
use App\Repositories\DashboardRepository;

final class DashboardService
{
    public function __construct(
        private readonly DashboardRepository $dashboard,
        private readonly IndicadorService $indicadores,
        private readonly AlcanceConsulta $alcance,
        private readonly AuthorizationService $authorization,
    ) {
    }

    /** @return array<string, mixed> */
    public function operador(Request $request): array
    {
        $usuarioId = (int) $request->userId();
        $this->authorization->requireRole($usuarioId, 'ADMIN_GENERAL', 'GESTOR_NEUMATICOS', 'TECNICO_INSPECCION');
        $filtros = $this->alcance->resolver($request);
        $base = $this->indicadores->operativos($request);
        $reducido = (bool) $filtros['es_tecnico'];

        return [
            'vista' => $reducido ? 'tecnico' : 'operador',
            'resumen' => [
                'clientes_activos' => $reducido ? null : $this->dashboard->clientesActivos($filtros),
                'unidades_operativas' => $this->dashboard->unidadesOperativas($filtros),
                'neumaticos' => $base['neumaticos'],
                'vidas' => $base['vidas'],
                'criticidad' => $this->dashboard->criticidad($filtros),
            ],
            'alertas' => $base['alertas'],
            'inspecciones' => $base['inspecciones'],
            'mantenimiento' => $base['mantenimiento'],
            'listas' => [
                'alertas_criticas' => $this->presentarAlertas($this->dashboard->alertasRecientes($filtros, false)),
                'inspecciones_recientes' => $this->presentarInspecciones($this->dashboard->inspeccionesRecientes($filtros)),
                'mantenimientos_activos' => $reducido ? [] : $this->presentarMantenimientos($this->dashboard->mantenimientosRecientes($filtros)),
                'neumaticos_criticos' => $this->presentarNeumaticos($this->dashboard->neumaticosCriticos($filtros)),
            ],
            'clientes_actividad' => $reducido ? [] : $this->presentarActividad($this->dashboard->clientesActividad($filtros)),
            'rendimiento_km' => $base['rendimiento_km'],
        ];
    }

    /** @return array<string, mixed> */
    public function cliente(Request $request): array
    {
        $usuarioId = (int) $request->userId();
        $this->authorization->requireRole($usuarioId, 'ADMIN_CLIENTE', 'CONSULTA_EJECUTIVA');
        $filtros = $this->alcance->resolver($request);
        $base = $this->indicadores->operativos($request);
        $identidad = $this->dashboard->identidad($filtros);

        return [
            'vista' => 'cliente',
            'resumido' => $this->authorization->hasRole($usuarioId, 'CONSULTA_EJECUTIVA')
                && !$this->authorization->hasRole($usuarioId, 'ADMIN_CLIENTE'),
            'cliente' => $identidad === null ? null : $this->presentarCliente($identidad),
            'clientes_permitidos' => array_map(
                fn (array $row): array => [
                    'id' => (int) $row['id'],
                    'nombre' => $row['nombre_comercial'] ?: $row['razon_social'],
                ],
                $this->dashboard->clientesPermitidos($usuarioId, false),
            ),
            'resumen' => [
                'unidades_operativas' => $this->dashboard->unidadesOperativas($filtros),
                'neumaticos' => $base['neumaticos'],
                'vidas' => $base['vidas'],
                'criticidad' => $this->dashboard->criticidad($filtros),
            ],
            'alertas' => $base['alertas'],
            'inspecciones' => $base['inspecciones'],
            'mantenimiento' => $base['mantenimiento'],
            'listas' => [
                'alertas_criticas' => $this->presentarAlertas($this->dashboard->alertasRecientes($filtros, true)),
                'inspecciones_recientes' => $this->presentarInspecciones($this->dashboard->inspeccionesRecientes($filtros)),
                'mantenimientos_activos' => $this->presentarMantenimientos($this->dashboard->mantenimientosRecientes($filtros)),
                'neumaticos_criticos' => $this->presentarNeumaticos($this->dashboard->neumaticosCriticos($filtros)),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function comercial(Request $request): array
    {
        $usuarioId = (int) $request->userId();
        if (!$this->authorization->hasRole($usuarioId, 'VENDEDOR') || $this->authorization->hasRole($usuarioId, 'ADMIN_GENERAL', 'GESTOR_NEUMATICOS')) {
            if (!$this->authorization->hasRole($usuarioId, 'VENDEDOR')) {
                throw new AuthorizationException('No tiene permiso para el tablero comercial.');
            }
        }
        $this->authorization->requireRole($usuarioId, 'VENDEDOR');

        return [
            'vista' => 'comercial',
            'resumen' => $this->dashboard->comercial($usuarioId),
        ];
    }

    /** @param list<array<string, mixed>> $rows @return list<array<string, mixed>> */
    private function presentarAlertas(array $rows): array
    {
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'titulo' => $row['titulo'],
            'nivel' => $row['nivel'],
            'estado' => $row['estado'],
            'fecha' => $row['fecha_generacion'],
            'cliente' => $row['nombre_comercial'] ?: $row['razon_social'],
            'neumatico' => $row['neumatico'],
            'unidad' => $row['unidad'],
        ], $rows);
    }

    /** @param list<array<string, mixed>> $rows @return list<array<string, mixed>> */
    private function presentarInspecciones(array $rows): array
    {
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'fecha' => $row['fecha_inspeccion'],
            'unidad' => $row['unidad'],
            'placa' => $row['placa'],
            'tecnico' => trim((string) $row['tecnico']),
            'estado' => $row['estado'],
        ], $rows);
    }

    /** @param list<array<string, mixed>> $rows @return list<array<string, mixed>> */
    private function presentarMantenimientos(array $rows): array
    {
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'fecha' => $row['fecha_solicitud'],
            'estado' => $row['estado'],
            'tipo' => $row['tipo'],
            'neumatico' => $row['neumatico'],
        ], $rows);
    }

    /** @param list<array<string, mixed>> $rows @return list<array<string, mixed>> */
    private function presentarNeumaticos(array $rows): array
    {
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'codigo' => $row['codigo'],
            'cliente' => $row['nombre_comercial'] ?: $row['razon_social'],
            'estado' => $row['estado'],
            'criticidad' => 'CRITICA',
        ], $rows);
    }

    /** @param list<array<string, mixed>> $rows @return list<array<string, mixed>> */
    private function presentarActividad(array $rows): array
    {
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'cliente' => $row['nombre_comercial'] ?: $row['razon_social'],
            'unidades' => (int) $row['unidades'],
            'neumaticos' => (int) $row['neumaticos'],
            'criticas' => (int) $row['criticas'],
        ], $rows);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function presentarCliente(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'razon_social' => $row['razon_social'],
            'nombre_comercial' => $row['nombre_comercial'],
            'documento' => $row['ruc_documento'],
            'estado' => $row['estado'],
        ];
    }
}
