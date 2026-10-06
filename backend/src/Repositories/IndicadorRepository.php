<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\AsignacionVigente;
use PDO;

final class IndicadorRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null */
    public function neumatico(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT n.id, n.cliente_id, n.codigo, n.eliminado, n.vida_actual, n.profundidad_inicial_mm, n.profundidad_minima_mm,
                    n.costo_adquisicion, n.moneda, e.codigo AS estado_codigo, e.nombre AS estado_nombre,
                    c.razon_social, c.nombre_comercial, c.eliminado AS cliente_eliminado
             FROM neumaticos n
             INNER JOIN estados_neumatico e ON e.id = n.estado_id
             INNER JOIN clientes c ON c.id = n.cliente_id
             WHERE n.id = :id
             LIMIT 1'
        );
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    public function vidas(int $neumaticoId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, numero_vida, fecha_inicio, fecha_fin, profundidad_inicial_mm, km_inicio, km_fin
             FROM neumatico_vidas
             WHERE neumatico_id = :id
             ORDER BY numero_vida ASC'
        );
        $statement->bindValue('id', $neumaticoId, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function medicionVida(int $neumaticoId, string $inicio, ?string $fin): ?array
    {
        $limite = $fin === null ? '' : ' AND i.fecha_inspeccion <= :fin';
        $statement = $this->pdo->prepare(
            'SELECT d.profundidad_interior_mm, d.profundidad_centro_mm, d.profundidad_exterior_mm,
                    d.presion_psi, d.condicion, i.id AS inspeccion_id, i.fecha_inspeccion,
                    u.id AS unidad_id, u.codigo AS unidad_codigo, u.placa AS unidad_placa
             FROM inspeccion_detalles d
             INNER JOIN inspecciones i ON i.id = d.inspeccion_id
             INNER JOIN unidades u ON u.id = i.unidad_id
             WHERE d.neumatico_id = :id
               AND i.estado = \'FINALIZADA\'
               AND i.fecha_inspeccion >= :inicio' . $limite . '
             ORDER BY i.fecha_inspeccion DESC, i.id DESC
             LIMIT 1'
        );
        $statement->bindValue('id', $neumaticoId, PDO::PARAM_INT);
        $statement->bindValue('inicio', $inicio);
        if ($fin !== null) {
            $statement->bindValue('fin', $fin);
        }
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function presionVida(int $neumaticoId, string $inicio, ?string $fin): ?string
    {
        $limite = $fin === null ? '' : ' AND i.fecha_inspeccion <= :fin';
        $statement = $this->pdo->prepare(
            'SELECT d.presion_psi
             FROM inspeccion_detalles d
             INNER JOIN inspecciones i ON i.id = d.inspeccion_id
             WHERE d.neumatico_id = :id
               AND i.estado = \'FINALIZADA\'
               AND d.presion_psi IS NOT NULL
               AND i.fecha_inspeccion >= :inicio' . $limite . '
             ORDER BY i.fecha_inspeccion DESC, i.id DESC
             LIMIT 1'
        );
        $statement->bindValue('id', $neumaticoId, PDO::PARAM_INT);
        $statement->bindValue('inicio', $inicio);
        if ($fin !== null) {
            $statement->bindValue('fin', $fin);
        }
        $statement->execute();
        $valor = $statement->fetchColumn();

        return $valor === false || $valor === null ? null : (string) $valor;
    }

    /** @return array{total:int,finalizados:int,activos:int,reencauches_finalizados:int} */
    public function mantenimientos(int $neumaticoId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) AS total,
                    SUM(e.codigo = \'FINALIZADO\') AS finalizados,
                    SUM(e.es_final = 0) AS activos,
                    SUM(e.codigo = \'FINALIZADO\' AND t.codigo = \'REENCAUCHE\') AS reencauches
             FROM mantenimientos_neumatico m
             INNER JOIN estados_mantenimiento e ON e.id = m.estado_id
             INNER JOIN tipos_mantenimiento t ON t.id = m.tipo_mantenimiento_id
             WHERE m.neumatico_id = :id'
        );
        $statement->bindValue('id', $neumaticoId, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch() ?: [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'finalizados' => (int) ($row['finalizados'] ?? 0),
            'activos' => (int) ($row['activos'] ?? 0),
            'reencauches_finalizados' => (int) ($row['reencauches'] ?? 0),
        ];
    }

    /** @return array<string, string> */
    public function costosFinalizados(int $neumaticoId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT m.moneda, SUM(m.costo) AS total
             FROM mantenimientos_neumatico m
             INNER JOIN estados_mantenimiento e ON e.id = m.estado_id
             WHERE m.neumatico_id = :id
               AND e.codigo = \'FINALIZADO\'
               AND m.costo IS NOT NULL
               AND m.moneda IS NOT NULL
             GROUP BY m.moneda
             ORDER BY m.moneda ASC'
        );
        $statement->bindValue('id', $neumaticoId, PDO::PARAM_INT);
        $statement->execute();
        $costos = [];
        foreach ($statement->fetchAll() as $row) {
            $costos[(string) $row['moneda']] = number_format((float) $row['total'], 2, '.', '');
        }

        return $costos;
    }

    /** @return array{abiertas:int,en_atencion:int,criticas_no_finales:int,atencion_no_finales:int,activas:int} */
    public function alertas(int $neumaticoId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT SUM(e.codigo = \'ABIERTA\') AS abiertas,
                    SUM(e.codigo = \'EN_ATENCION\') AS en_atencion,
                    SUM(e.es_final = 0 AND a.nivel = \'CRITICA\') AS criticas,
                    SUM(e.es_final = 0 AND a.nivel = \'ATENCION\') AS atencion,
                    SUM(e.es_final = 0) AS activas
             FROM alertas a
             INNER JOIN estados_alerta e ON e.id = a.estado_id
             WHERE a.neumatico_id = :id'
        );
        $statement->bindValue('id', $neumaticoId, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch() ?: [];

        return [
            'abiertas' => (int) ($row['abiertas'] ?? 0),
            'en_atencion' => (int) ($row['en_atencion'] ?? 0),
            'criticas_no_finales' => (int) ($row['criticas'] ?? 0),
            'atencion_no_finales' => (int) ($row['atencion'] ?? 0),
            'activas' => (int) ($row['activas'] ?? 0),
        ];
    }

    /** @return array<string, mixed>|null */
    public function montajeActivo(int $neumaticoId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT m.fecha_montaje, u.id AS unidad_id, u.codigo AS unidad_codigo, u.placa AS unidad_placa,
                    p.id AS posicion_id, p.codigo AS posicion_codigo
             FROM montajes_neumatico m
             INNER JOIN unidades u ON u.id = m.unidad_id
             INNER JOIN configuracion_posiciones p ON p.id = m.posicion_id
             WHERE m.neumatico_id = :id AND m.montaje_activo_flag = 1
             LIMIT 1'
        );
        $statement->bindValue('id', $neumaticoId, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function sede(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, cliente_id, eliminado FROM sedes WHERE id = :id LIMIT 1');
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function flota(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, cliente_id, eliminado FROM flotas WHERE id = :id LIMIT 1');
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @param array<string, mixed> $filtros @return array<string, int> */
    public function neumaticos(array $filtros): array
    {
        [$extra, $params] = $this->ubicacionNeumatico($filtros);
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) AS total_registrados,
                    SUM(e.codigo <> \'DESCARTADO\') AS total_operativos,
                    SUM(e.codigo = \'DISPONIBLE\') AS disponible,
                    SUM(e.codigo = \'MONTADO\') AS montado,
                    SUM(e.codigo = \'EN_MANTENIMIENTO\') AS en_mantenimiento,
                    SUM(e.codigo = \'EN_REENCAUCHE\') AS en_reencauche,
                    SUM(e.codigo = \'DESCARTADO\') AS descartado,
                    SUM(n.vida_actual = 1) AS vida_1,
                    SUM(n.vida_actual = 2) AS vida_2,
                    SUM(n.vida_actual >= 3) AS vida_3_mas
             FROM neumaticos n
             INNER JOIN estados_neumatico e ON e.id = n.estado_id
             WHERE n.eliminado = 0' . $this->scope('n.cliente_id', $filtros, $params) . $extra
        );
        $this->bind($statement, $params);
        $statement->execute();

        return $this->enteros($statement->fetch() ?: []);
    }

    /** @param array<string, mixed> $filtros @return array<string, int> */
    public function alertasOperativas(array $filtros): array
    {
        [$extra, $params] = $this->ubicacionAlerta($filtros);
        $statement = $this->pdo->prepare(
            'SELECT SUM(e.codigo = \'ABIERTA\') AS abiertas,
                    SUM(e.codigo = \'EN_ATENCION\') AS en_atencion,
                    SUM(e.es_final = 0 AND a.nivel = \'CRITICA\') AS criticas_activas
             FROM alertas a
             INNER JOIN estados_alerta e ON e.id = a.estado_id
             WHERE 1 = 1' . $this->scope('a.cliente_id', $filtros, $params) . $extra
        );
        $this->bind($statement, $params);
        $statement->execute();

        return $this->enteros($statement->fetch() ?: []);
    }

    /** @param array<string, mixed> $filtros @return array<string, int> */
    public function mantenimientosOperativos(array $filtros): array
    {
        [$extra, $params] = $this->ubicacionMantenimiento($filtros);
        $statement = $this->pdo->prepare(
            'SELECT SUM(e.codigo = \'SOLICITADO\') AS solicitado,
                    SUM(e.codigo = \'ENVIADO\') AS enviado,
                    SUM(e.codigo = \'EN_PROCESO\') AS en_proceso
             FROM mantenimientos_neumatico m
             INNER JOIN estados_mantenimiento e ON e.id = m.estado_id
             WHERE 1 = 1' . $this->scope('m.cliente_id', $filtros, $params) . $extra
        );
        $this->bind($statement, $params);
        $statement->execute();

        return $this->enteros($statement->fetch() ?: []);
    }

    /** @param array<string, mixed> $filtros @return array{finalizadas_30_dias:int,criticos_30_dias:int} */
    public function inspecciones(array $filtros): array
    {
        $params = [];
        $sede = $this->sedeSql('u', $filtros, $params, 'insp');
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM inspecciones i
             INNER JOIN unidades u ON u.id = i.unidad_id
             WHERE i.estado = \'FINALIZADA\'
               AND i.fecha_inspeccion >= DATE_SUB(NOW(), INTERVAL 30 DAY)'
            . $this->scope('i.cliente_id', $filtros, $params) . $sede
        );
        $this->bind($statement, $params);
        $statement->execute();
        $finalizadas = (int) $statement->fetchColumn();
        $params = [];
        $sede = $this->sedeSql('u', $filtros, $params, 'crit');
        $criticos = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM inspeccion_detalles d
             INNER JOIN inspecciones i ON i.id = d.inspeccion_id
             INNER JOIN unidades u ON u.id = i.unidad_id
             WHERE i.estado = \'FINALIZADA\'
               AND d.condicion = \'CRITICO\'
               AND i.fecha_inspeccion >= DATE_SUB(NOW(), INTERVAL 30 DAY)'
            . $this->scope('i.cliente_id', $filtros, $params) . $sede
        );
        $this->bind($criticos, $params);
        $criticos->execute();

        return [
            'finalizadas_30_dias' => $finalizadas,
            'criticos_30_dias' => (int) $criticos->fetchColumn(),
        ];
    }

    /** @param array<string, mixed> $filtros @param array<string, mixed> $params */
    private function scope(string $column, array $filtros, array &$params): string
    {
        $sql = '';
        if ($filtros['usuario_scope'] !== null) {
            $sql .= ' AND EXISTS (SELECT 1 FROM usuario_clientes uc WHERE uc.cliente_id = ' . $column . ' AND uc.usuario_id = :scope AND ' . AsignacionVigente::sql('uc') . ')';
            $params['scope'] = $filtros['usuario_scope'];
        }
        if ($filtros['cliente_id'] !== null) {
            $sql .= ' AND ' . $column . ' = :cliente';
            $params['cliente'] = $filtros['cliente_id'];
        }

        return $sql;
    }

    /**
     * @param array<string, mixed> $filtros
     * @return array{0:string,1:array<string,mixed>}
     */
    private function ubicacionNeumatico(array $filtros): array
    {
        if ($filtros['sede_id'] === null && $filtros['flota_id'] === null) {
            return ['', []];
        }
        $params = [];
        $sql = ' AND EXISTS (
            SELECT 1 FROM montajes_neumatico mo
            INNER JOIN unidades uu ON uu.id = mo.unidad_id
            WHERE mo.neumatico_id = n.id AND mo.montaje_activo_flag = 1' . $this->sedeSql('uu', $filtros, $params, 'neu') . ')';

        return [$sql, $params];
    }

    /**
     * @param array<string, mixed> $filtros
     * @return array{0:string,1:array<string,mixed>}
     */
    private function ubicacionAlerta(array $filtros): array
    {
        if ($filtros['sede_id'] === null && $filtros['flota_id'] === null) {
            return ['', []];
        }
        $unidad = [];
        $montaje = [];
        $unidadSql = $this->sedeSql('uu', $filtros, $unidad, 'alu');
        $montajeSql = $this->sedeSql('um', $filtros, $montaje, 'alm');
        $sql = ' AND (
            EXISTS (SELECT 1 FROM unidades uu WHERE uu.id = a.unidad_id' . $unidadSql . ')
            OR EXISTS (
                SELECT 1 FROM montajes_neumatico mo
                INNER JOIN unidades um ON um.id = mo.unidad_id
                WHERE mo.neumatico_id = a.neumatico_id AND mo.montaje_activo_flag = 1' . $montajeSql . '
            )
        )';

        return [$sql, $unidad + $montaje];
    }

    /**
     * @param array<string, mixed> $filtros
     * @return array{0:string,1:array<string,mixed>}
     */
    private function ubicacionMantenimiento(array $filtros): array
    {
        if ($filtros['sede_id'] === null && $filtros['flota_id'] === null) {
            return ['', []];
        }
        $params = [];
        $sql = ' AND EXISTS (
            SELECT 1 FROM montajes_neumatico mo
            INNER JOIN unidades uu ON uu.id = mo.unidad_id
            WHERE mo.neumatico_id = m.neumatico_id AND mo.montaje_activo_flag = 1' . $this->sedeSql('uu', $filtros, $params, 'man') . ')';

        return [$sql, $params];
    }

    /** @param array<string, mixed> $filtros @param array<string, mixed> $params */
    private function sedeSql(string $alias, array $filtros, array &$params, string $prefijo): string
    {
        $sql = '';
        if ($filtros['sede_id'] !== null) {
            $nombre = $prefijo . '_sede';
            $sql .= ' AND ' . $alias . '.sede_id = :' . $nombre;
            $params[$nombre] = $filtros['sede_id'];
        }
        if ($filtros['flota_id'] !== null) {
            $nombre = $prefijo . '_flota';
            $sql .= ' AND ' . $alias . '.flota_id = :' . $nombre;
            $params[$nombre] = $filtros['flota_id'];
        }

        return $sql;
    }

    /** @param array<string, mixed> $row @return array<string, int> */
    private function enteros(array $row): array
    {
        $salida = [];
        foreach ($row as $clave => $valor) {
            $salida[(string) $clave] = (int) $valor;
        }

        return $salida;
    }

    /** @param array<string, mixed> $params */
    private function bind(\PDOStatement $statement, array $params): void
    {
        foreach ($params as $name => $value) {
            $statement->bindValue((string) $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
    }
}
