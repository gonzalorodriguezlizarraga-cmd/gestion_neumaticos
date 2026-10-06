<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\ScopeSql;
use PDO;

final class DashboardRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string, mixed> $filtros */
    public function clientesActivos(array $filtros): int
    {
        $params = [];
        $sql = 'SELECT COUNT(*) FROM clientes c WHERE c.eliminado = 0 AND c.estado = \'ACTIVO\''
            . ScopeSql::cliente('c.id', $filtros, $params, 'cli');
        if ($filtros['sede_id'] !== null || $filtros['flota_id'] !== null) {
            $sql .= ' AND EXISTS (SELECT 1 FROM unidades u WHERE u.cliente_id = c.id AND u.eliminado = 0'
                . ScopeSql::sedeUnidad('u', $filtros, $params, 'cliu') . ')';
        }
        $statement = $this->pdo->prepare($sql);
        ScopeSql::bind($statement, $params);
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    /** @param array<string, mixed> $filtros */
    public function unidadesOperativas(array $filtros): int
    {
        $params = [];
        $sql = 'SELECT COUNT(*) FROM unidades u WHERE u.eliminado = 0 AND u.estado = \'OPERATIVA\''
            . ScopeSql::cliente('u.cliente_id', $filtros, $params, 'uni')
            . ScopeSql::sedeUnidad('u', $filtros, $params, 'unif');
        $statement = $this->pdo->prepare($sql);
        ScopeSql::bind($statement, $params);
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    /** @param array<string, mixed> $filtros @return array{NORMAL:int,ATENCION:int,CRITICA:int} */
    public function criticidad(array $filtros): array
    {
        $params = [];
        $sql = 'SELECT
                SUM(' . $this->nivel() . ' = \'NORMAL\') AS normal,
                SUM(' . $this->nivel() . ' = \'ATENCION\') AS atencion,
                SUM(' . $this->nivel() . ' = \'CRITICA\') AS critica
             FROM neumaticos n
             WHERE n.eliminado = 0'
            . ScopeSql::cliente('n.cliente_id', $filtros, $params, 'cri')
            . ScopeSql::montaje('n.id', $filtros, $params, 'crim');
        $statement = $this->pdo->prepare($sql);
        ScopeSql::bind($statement, $params);
        $statement->execute();
        $row = $statement->fetch() ?: [];

        return [
            'NORMAL' => (int) ($row['normal'] ?? 0),
            'ATENCION' => (int) ($row['atencion'] ?? 0),
            'CRITICA' => (int) ($row['critica'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $filtros
     * @return list<array<string, mixed>>
     */
    public function alertasRecientes(array $filtros, bool $ocultarDescartadas): array
    {
        $params = [];
        $extra = $ocultarDescartadas ? ' AND e.codigo <> \'DESCARTADA\'' : '';
        $sql = 'SELECT a.id, a.titulo, a.nivel, a.fecha_generacion, e.codigo AS estado,
                    c.nombre_comercial, c.razon_social, n.codigo AS neumatico, u.codigo AS unidad
             FROM alertas a
             INNER JOIN estados_alerta e ON e.id = a.estado_id
             INNER JOIN clientes c ON c.id = a.cliente_id
             LEFT JOIN neumaticos n ON n.id = a.neumatico_id
             LEFT JOIN unidades u ON u.id = a.unidad_id
             WHERE e.es_final = 0 AND a.nivel = \'CRITICA\'' . $extra
            . ScopeSql::cliente('a.cliente_id', $filtros, $params, 'alr')
            . $this->ubicacionAlerta($filtros, $params)
            . ' ORDER BY a.fecha_generacion DESC, a.id DESC LIMIT 5';
        $statement = $this->pdo->prepare($sql);
        ScopeSql::bind($statement, $params);
        $statement->execute();

        return $statement->fetchAll();
    }

    /** @param array<string, mixed> $filtros @return list<array<string, mixed>> */
    public function inspeccionesRecientes(array $filtros): array
    {
        $params = [];
        $sql = 'SELECT i.id, i.fecha_inspeccion, i.estado, u.codigo AS unidad, u.placa,
                    CONCAT(t.nombres, \' \', t.apellidos) AS tecnico
             FROM inspecciones i
             INNER JOIN unidades u ON u.id = i.unidad_id
             INNER JOIN usuarios t ON t.id = i.tecnico_id
             WHERE i.estado = \'FINALIZADA\''
            . ScopeSql::cliente('i.cliente_id', $filtros, $params, 'inr')
            . ScopeSql::sedeUnidad('u', $filtros, $params, 'inrs')
            . ' ORDER BY i.fecha_inspeccion DESC, i.id DESC LIMIT 5';
        $statement = $this->pdo->prepare($sql);
        ScopeSql::bind($statement, $params);
        $statement->execute();

        return $statement->fetchAll();
    }

    /** @param array<string, mixed> $filtros @return list<array<string, mixed>> */
    public function mantenimientosRecientes(array $filtros): array
    {
        $params = [];
        $sql = 'SELECT m.id, m.fecha_solicitud, e.codigo AS estado, tm.codigo AS tipo, n.codigo AS neumatico
             FROM mantenimientos_neumatico m
             INNER JOIN estados_mantenimiento e ON e.id = m.estado_id
             INNER JOIN tipos_mantenimiento tm ON tm.id = m.tipo_mantenimiento_id
             INNER JOIN neumaticos n ON n.id = m.neumatico_id
             WHERE e.codigo IN (\'SOLICITADO\', \'ENVIADO\', \'EN_PROCESO\')'
            . ScopeSql::cliente('m.cliente_id', $filtros, $params, 'mar')
            . ScopeSql::montaje('m.neumatico_id', $filtros, $params, 'marm')
            . ' ORDER BY m.fecha_solicitud DESC, m.id DESC LIMIT 5';
        $statement = $this->pdo->prepare($sql);
        ScopeSql::bind($statement, $params);
        $statement->execute();

        return $statement->fetchAll();
    }

    /** @param array<string, mixed> $filtros @return list<array<string, mixed>> */
    public function neumaticosCriticos(array $filtros): array
    {
        $params = [];
        $sql = 'SELECT n.id, n.codigo, c.nombre_comercial, c.razon_social, e.codigo AS estado
             FROM neumaticos n
             INNER JOIN clientes c ON c.id = n.cliente_id
             INNER JOIN estados_neumatico e ON e.id = n.estado_id
             WHERE n.eliminado = 0 AND ' . $this->nivel() . ' = \'CRITICA\''
            . ScopeSql::cliente('n.cliente_id', $filtros, $params, 'ncr')
            . ScopeSql::montaje('n.id', $filtros, $params, 'ncrm')
            . ' ORDER BY n.codigo ASC LIMIT 5';
        $statement = $this->pdo->prepare($sql);
        ScopeSql::bind($statement, $params);
        $statement->execute();

        return $statement->fetchAll();
    }

    /** @param array<string, mixed> $filtros @return list<array<string, mixed>> */
    public function clientesActividad(array $filtros): array
    {
        if ($filtros['usuario_scope'] !== null || $filtros['cliente_id'] !== null) {
            return [];
        }
        $statement = $this->pdo->query(
            'SELECT c.id, c.nombre_comercial, c.razon_social,
                (SELECT COUNT(*) FROM unidades u WHERE u.cliente_id = c.id AND u.eliminado = 0 AND u.estado = \'OPERATIVA\') AS unidades,
                (SELECT COUNT(*) FROM neumaticos n WHERE n.cliente_id = c.id AND n.eliminado = 0 AND n.estado_id <> (
                    SELECT id FROM estados_neumatico WHERE codigo = \'DESCARTADO\' LIMIT 1
                )) AS neumaticos,
                (SELECT COUNT(*) FROM alertas a INNER JOIN estados_alerta ea ON ea.id = a.estado_id
                    WHERE a.cliente_id = c.id AND ea.es_final = 0 AND a.nivel = \'CRITICA\') AS criticas
             FROM clientes c
             WHERE c.eliminado = 0 AND c.estado = \'ACTIVO\'
             ORDER BY criticas DESC, neumaticos DESC, c.razon_social ASC
             LIMIT 5'
        );

        return $statement->fetchAll();
    }

    /** @param array<string, mixed> $filtros @return array<string, mixed>|null */
    public function identidad(array $filtros): ?array
    {
        if ($filtros['cliente_id'] !== null) {
            return $this->cliente((int) $filtros['cliente_id']);
        }
        if ($filtros['usuario_scope'] === null) {
            return null;
        }
        $statement = $this->pdo->prepare(
            'SELECT c.id, c.razon_social, c.nombre_comercial, c.ruc_documento, c.estado
             FROM clientes c
             INNER JOIN usuario_clientes uc ON uc.cliente_id = c.id
             WHERE uc.usuario_id = :usuario AND ' . \App\Support\AsignacionVigente::sql('uc') . ' AND c.eliminado = 0
             ORDER BY c.razon_social ASC'
        );
        $statement->bindValue('usuario', (int) $filtros['usuario_scope'], PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll();
        if (count($rows) !== 1) {
            return null;
        }

        return $rows[0];
    }

    /** @return list<array<string, mixed>> */
    public function clientesPermitidos(int $usuarioId, bool $admin): array
    {
        if ($admin) {
            return [];
        }
        $statement = $this->pdo->prepare(
            'SELECT c.id, c.razon_social, c.nombre_comercial
             FROM clientes c
             INNER JOIN usuario_clientes uc ON uc.cliente_id = c.id
             WHERE uc.usuario_id = :usuario AND ' . \App\Support\AsignacionVigente::sql('uc') . ' AND c.eliminado = 0
             ORDER BY c.razon_social ASC'
        );
        $statement->bindValue('usuario', $usuarioId, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /** @return array<string, int> */
    public function comercial(int $usuarioId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT
                SUM(e.codigo = \'ABIERTA\') AS abiertas,
                SUM(e.codigo = \'EN_SEGUIMIENTO\') AS en_seguimiento,
                SUM(e.codigo = \'COTIZADA\') AS cotizadas,
                SUM(e.codigo = \'GANADA\') AS ganadas
             FROM oportunidades o
             INNER JOIN estados_oportunidad e ON e.id = o.estado_id
             WHERE o.responsable_comercial_id = :usuario'
        );
        $statement->bindValue('usuario', $usuarioId, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch() ?: [];
        $pendientes = $this->pdo->prepare(
            'SELECT COUNT(*) FROM cotizaciones c
             LEFT JOIN oportunidades o ON o.id = c.oportunidad_id
             WHERE c.estado IN (\'BORRADOR\', \'ENVIADA\')
               AND (c.creado_por = :usuario OR o.responsable_comercial_id = :responsable)'
        );
        $pendientes->bindValue('usuario', $usuarioId, PDO::PARAM_INT);
        $pendientes->bindValue('responsable', $usuarioId, PDO::PARAM_INT);
        $pendientes->execute();
        $proximo = $this->pdo->prepare(
            'SELECT s.proximo_seguimiento
             FROM seguimientos_comerciales s
             LEFT JOIN oportunidades o ON o.id = s.oportunidad_id
             WHERE s.proximo_seguimiento IS NOT NULL
               AND (o.responsable_comercial_id = :usuario OR (s.oportunidad_id IS NULL AND s.usuario_id = :general))
             ORDER BY s.fecha DESC, s.id DESC
             LIMIT 1'
        );
        $proximo->bindValue('usuario', $usuarioId, PDO::PARAM_INT);
        $proximo->bindValue('general', $usuarioId, PDO::PARAM_INT);
        $proximo->execute();

        return [
            'abiertas' => (int) ($row['abiertas'] ?? 0),
            'en_seguimiento' => (int) ($row['en_seguimiento'] ?? 0),
            'cotizadas' => (int) ($row['cotizadas'] ?? 0),
            'ganadas' => (int) ($row['ganadas'] ?? 0),
            'cotizaciones_pendientes' => (int) $pendientes->fetchColumn(),
            'proximo_seguimiento' => $proximo->fetchColumn() ?: null,
        ];
    }

    /** @return array<string, mixed>|null */
    private function cliente(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, razon_social, nombre_comercial, ruc_documento, estado
             FROM clientes WHERE id = :id AND eliminado = 0 LIMIT 1'
        );
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @param array<string, mixed> $filtros @param array<string, int|string> $params */
    private function ubicacionAlerta(array $filtros, array &$params): string
    {
        if ($filtros['sede_id'] === null && $filtros['flota_id'] === null) {
            return '';
        }
        $unidad = ScopeSql::sedeUnidad('uu', $filtros, $params, 'alu');
        $montaje = ScopeSql::sedeUnidad('um', $filtros, $params, 'alm');

        return ' AND (
            EXISTS (SELECT 1 FROM unidades uu WHERE uu.id = a.unidad_id' . $unidad . ')
            OR EXISTS (
                SELECT 1 FROM montajes_neumatico mo
                INNER JOIN unidades um ON um.id = mo.unidad_id
                WHERE mo.neumatico_id = a.neumatico_id AND mo.montaje_activo_flag = 1' . $montaje . '
            )
        )';
    }

    private function nivel(): string
    {
        return 'CASE
            WHEN EXISTS (
                SELECT 1 FROM alertas ax
                INNER JOIN estados_alerta ex ON ex.id = ax.estado_id
                WHERE ax.neumatico_id = n.id AND ex.es_final = 0 AND ax.nivel = \'CRITICA\'
            ) THEN \'CRITICA\'
            WHEN EXISTS (
                SELECT 1 FROM alertas ay
                INNER JOIN estados_alerta ey ON ey.id = ay.estado_id
                WHERE ay.neumatico_id = n.id AND ey.es_final = 0
            ) THEN \'ATENCION\'
            ELSE \'NORMAL\'
        END';
    }
}
