<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\ScopeSql;
use PDO;

final class ReporteRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $filtros
     * @return array{total:int,filas:list<array<string,mixed>>}
     */
    public function consultar(string $tipo, array $filtros, int $offset, int $limit): array
    {
        return match ($tipo) {
            'inventario-neumaticos' => $this->inventario($filtros, $offset, $limit, false),
            'estado-neumaticos' => $this->inventario($filtros, $offset, $limit, true),
            'movimientos' => $this->movimientos($filtros, $offset, $limit),
            'inspecciones' => $this->inspecciones($filtros, $offset, $limit),
            'alertas' => $this->alertas($filtros, $offset, $limit),
            'mantenimientos' => $this->mantenimientos($filtros, $offset, $limit),
            'vidas' => $this->vidas($filtros, $offset, $limit),
            'descartes' => $this->descartes($filtros, $offset, $limit),
            default => ['total' => 0, 'filas' => []],
        };
    }

    /** @param array<string, mixed> $filtros @return list<array{moneda:string,total:string}> */
    public function totalesMoneda(array $filtros): array
    {
        $params = [];
        $sql = 'SELECT m.moneda, SUM(m.costo) AS total
             FROM mantenimientos_neumatico m
             INNER JOIN neumaticos n ON n.id = m.neumatico_id
             INNER JOIN tipos_mantenimiento tm ON tm.id = m.tipo_mantenimiento_id
             INNER JOIN estados_mantenimiento e ON e.id = m.estado_id
             WHERE m.costo IS NOT NULL AND m.moneda IS NOT NULL'
            . $this->dondeMantenimiento($filtros, $params)
            . ' GROUP BY m.moneda ORDER BY m.moneda ASC';
        $statement = $this->pdo->prepare($sql);
        ScopeSql::bind($statement, $params);
        $statement->execute();
        $filas = [];
        foreach ($statement->fetchAll() as $row) {
            $filas[] = [
                'moneda' => (string) $row['moneda'],
                'total' => number_format((float) $row['total'], 2, '.', ''),
            ];
        }

        return $filas;
    }

    /** @param array<string, mixed> $filtros @return array{total:int,filas:list<array<string,mixed>>} */
    private function inventario(array $filtros, int $offset, int $limit, bool $condicion): array
    {
        $params = [];
        $medicion = $condicion ? ', ' . $this->medicion() . ' AS medicion, n.profundidad_minima_mm,
            (SELECT COUNT(*) FROM alertas a INNER JOIN estados_alerta ea ON ea.id = a.estado_id
              WHERE a.neumatico_id = n.id AND ea.es_final = 0) AS alertas_activas' : '';
        $select = 'SELECT n.codigo, n.numero_serie, COALESCE(c.nombre_comercial, c.razon_social) AS cliente,
                ma.nombre AS marca, mo.nombre AS modelo, me.descripcion AS medida, e.codigo AS estado, n.vida_actual,
                (SELECT u.codigo FROM montajes_neumatico mx INNER JOIN unidades u ON u.id = mx.unidad_id
                  WHERE mx.neumatico_id = n.id AND mx.montaje_activo_flag = 1 LIMIT 1) AS unidad,
                (SELECT p.codigo FROM montajes_neumatico my INNER JOIN configuracion_posiciones p ON p.id = my.posicion_id
                  WHERE my.neumatico_id = n.id AND my.montaje_activo_flag = 1 LIMIT 1) AS posicion,
                ' . $this->criticidad() . ' AS criticidad' . ($condicion ? '' : ', ' . $this->medicion() . ' AS medicion') . $medicion;
        if ($condicion) {
            $select = 'SELECT n.codigo, COALESCE(c.nombre_comercial, c.razon_social) AS cliente, e.codigo AS estado,
                n.vida_actual, n.profundidad_minima_mm, ' . $this->medicion() . ' AS medicion,
                ' . $this->criticidad() . ' AS criticidad,
                (SELECT COUNT(*) FROM alertas a INNER JOIN estados_alerta ea ON ea.id = a.estado_id
                  WHERE a.neumatico_id = n.id AND ea.es_final = 0) AS alertas_activas,
                (SELECT u.codigo FROM montajes_neumatico mx INNER JOIN unidades u ON u.id = mx.unidad_id
                  WHERE mx.neumatico_id = n.id AND mx.montaje_activo_flag = 1 LIMIT 1) AS unidad,
                (SELECT p.codigo FROM montajes_neumatico my INNER JOIN configuracion_posiciones p ON p.id = my.posicion_id
                  WHERE my.neumatico_id = n.id AND my.montaje_activo_flag = 1 LIMIT 1) AS posicion';
        }
        $from = ' FROM neumaticos n
             INNER JOIN clientes c ON c.id = n.cliente_id
             INNER JOIN modelos_neumatico mo ON mo.id = n.modelo_id
             INNER JOIN marcas_neumatico ma ON ma.id = mo.marca_id
             INNER JOIN medidas_neumatico me ON me.id = n.medida_id
             INNER JOIN estados_neumatico e ON e.id = n.estado_id
             WHERE n.eliminado = 0'
            . ScopeSql::cliente('n.cliente_id', $filtros, $params, 'inv')
            . ScopeSql::montaje('n.id', $filtros, $params, 'invm')
            . $this->catalogo($filtros, $params, 'invc');

        return $this->pagina($select, $from, 'n.codigo ASC', $params, $offset, $limit);
    }

    /** @param array<string, mixed> $filtros @return array{total:int,filas:list<array<string,mixed>>} */
    private function movimientos(array $filtros, int $offset, int $limit): array
    {
        $params = [];
        $select = 'SELECT mv.fecha, n.codigo AS neumatico, tm.nombre AS tipo,
                uo.codigo AS unidad_origen, po.codigo AS posicion_origen,
                ud.codigo AS unidad_destino, pd.codigo AS posicion_destino,
                CONCAT(us.nombres, \' \', us.apellidos) AS usuario, mv.observacion';
        $from = ' FROM movimientos_neumatico mv
             INNER JOIN neumaticos n ON n.id = mv.neumatico_id
             INNER JOIN tipos_movimiento tm ON tm.id = mv.tipo_movimiento_id
             INNER JOIN usuarios us ON us.id = mv.usuario_id
             LEFT JOIN unidades uo ON uo.id = mv.unidad_origen_id
             LEFT JOIN unidades ud ON ud.id = mv.unidad_destino_id
             LEFT JOIN configuracion_posiciones po ON po.id = mv.posicion_origen_id
             LEFT JOIN configuracion_posiciones pd ON pd.id = mv.posicion_destino_id
             WHERE mv.anulado = 0'
            . ScopeSql::cliente('mv.cliente_id', $filtros, $params, 'mov')
            . $this->fechas('mv.fecha', $filtros, $params, 'movf');
        if ($filtros['neumatico_id'] !== null) {
            $from .= ' AND mv.neumatico_id = :mov_neu';
            $params['mov_neu'] = (int) $filtros['neumatico_id'];
        }
        if ($filtros['unidad_id'] !== null) {
            $from .= ' AND (mv.unidad_origen_id = :mov_uo OR mv.unidad_destino_id = :mov_ud)';
            $params['mov_uo'] = (int) $filtros['unidad_id'];
            $params['mov_ud'] = (int) $filtros['unidad_id'];
        }
        if ($filtros['tipo'] !== null) {
            $from .= ' AND tm.codigo = :mov_tipo';
            $params['mov_tipo'] = (string) $filtros['tipo'];
        }

        return $this->pagina($select, $from, 'mv.fecha DESC, mv.id DESC', $params, $offset, $limit);
    }

    /** @param array<string, mixed> $filtros @return array{total:int,filas:list<array<string,mixed>>} */
    private function inspecciones(array $filtros, int $offset, int $limit): array
    {
        $params = [];
        $select = 'SELECT i.fecha_inspeccion AS fecha, u.codigo AS unidad, CONCAT(t.nombres, \' \', t.apellidos) AS tecnico, i.estado,
                (SELECT COUNT(*) FROM inspeccion_detalles d WHERE d.inspeccion_id = i.id) AS inspeccionados,
                (SELECT COUNT(*) FROM inspeccion_detalles d WHERE d.inspeccion_id = i.id AND d.condicion = \'CRITICO\') AS criticos,
                (SELECT COUNT(*) FROM inspeccion_detalles d WHERE d.inspeccion_id = i.id AND d.condicion = \'ATENCION\') AS atencion,
                (SELECT COUNT(*) FROM inspeccion_detalle_danos dn
                    INNER JOIN inspeccion_detalles d ON d.id = dn.inspeccion_detalle_id
                    WHERE d.inspeccion_id = i.id) AS danos';
        $from = ' FROM inspecciones i
             INNER JOIN unidades u ON u.id = i.unidad_id
             INNER JOIN usuarios t ON t.id = i.tecnico_id
             WHERE 1 = 1'
            . ScopeSql::cliente('i.cliente_id', $filtros, $params, 'ins')
            . ScopeSql::sedeUnidad('u', $filtros, $params, 'inss')
            . $this->fechas('i.fecha_inspeccion', $filtros, $params, 'insf');
        if ($filtros['solo_finalizadas'] === true) {
            $from .= ' AND i.estado = \'FINALIZADA\'';
        } elseif ($filtros['estado'] !== null) {
            $from .= ' AND i.estado = :ins_estado';
            $params['ins_estado'] = (string) $filtros['estado'];
        }
        if ($filtros['unidad_id'] !== null) {
            $from .= ' AND i.unidad_id = :ins_unidad';
            $params['ins_unidad'] = (int) $filtros['unidad_id'];
        }
        if ($filtros['tecnico_id'] !== null) {
            $from .= ' AND i.tecnico_id = :ins_tecnico';
            $params['ins_tecnico'] = (int) $filtros['tecnico_id'];
        }

        return $this->pagina($select, $from, 'i.fecha_inspeccion DESC, i.id DESC', $params, $offset, $limit);
    }

    /** @param array<string, mixed> $filtros @return array{total:int,filas:list<array<string,mixed>>} */
    private function alertas(array $filtros, int $offset, int $limit): array
    {
        $params = [];
        $select = 'SELECT a.fecha_generacion AS fecha, ta.nombre AS tipo, a.nivel, e.codigo AS estado,
                u.codigo AS unidad, n.codigo AS neumatico, a.titulo,
                a.generada_automaticamente AS automatica';
        $from = ' FROM alertas a
             INNER JOIN tipos_alerta ta ON ta.id = a.tipo_alerta_id
             INNER JOIN estados_alerta e ON e.id = a.estado_id
             LEFT JOIN unidades u ON u.id = a.unidad_id
             LEFT JOIN neumaticos n ON n.id = a.neumatico_id
             WHERE 1 = 1'
            . ScopeSql::cliente('a.cliente_id', $filtros, $params, 'ale')
            . $this->fechas('a.fecha_generacion', $filtros, $params, 'alef');
        if ($filtros['ocultar_descartadas'] === true) {
            $from .= ' AND e.codigo <> \'DESCARTADA\'';
        } elseif ($filtros['estado'] !== null) {
            $from .= ' AND e.codigo = :ale_estado';
            $params['ale_estado'] = (string) $filtros['estado'];
        }
        if ($filtros['nivel'] !== null) {
            $from .= ' AND a.nivel = :ale_nivel';
            $params['ale_nivel'] = (string) $filtros['nivel'];
        }
        if ($filtros['tipo'] !== null) {
            $from .= ' AND ta.codigo = :ale_tipo';
            $params['ale_tipo'] = (string) $filtros['tipo'];
        }
        if ($filtros['automatica'] !== null) {
            $from .= ' AND a.generada_automaticamente = :ale_auto';
            $params['ale_auto'] = (int) $filtros['automatica'];
        }
        if ($filtros['unidad_id'] !== null) {
            $from .= ' AND a.unidad_id = :ale_unidad';
            $params['ale_unidad'] = (int) $filtros['unidad_id'];
        }
        if ($filtros['neumatico_id'] !== null) {
            $from .= ' AND a.neumatico_id = :ale_neu';
            $params['ale_neu'] = (int) $filtros['neumatico_id'];
        }

        return $this->pagina($select, $from, 'a.fecha_generacion DESC, a.id DESC', $params, $offset, $limit);
    }

    /** @param array<string, mixed> $filtros @return array{total:int,filas:list<array<string,mixed>>} */
    private function mantenimientos(array $filtros, int $offset, int $limit): array
    {
        $params = [];
        $select = 'SELECT n.codigo AS neumatico, tm.nombre AS tipo, e.codigo AS estado,
                m.fecha_solicitud, m.fecha_envio, m.fecha_retorno, m.tercero_nombre,
                m.costo, m.moneda, m.profundidad_antes_mm, m.profundidad_despues_mm';
        $from = ' FROM mantenimientos_neumatico m
             INNER JOIN neumaticos n ON n.id = m.neumatico_id
             INNER JOIN tipos_mantenimiento tm ON tm.id = m.tipo_mantenimiento_id
             INNER JOIN estados_mantenimiento e ON e.id = m.estado_id
             WHERE 1 = 1' . $this->dondeMantenimiento($filtros, $params);

        return $this->pagina($select, $from, 'm.fecha_solicitud DESC, m.id DESC', $params, $offset, $limit);
    }

    /** @param array<string, mixed> $filtros @return array{total:int,filas:list<array<string,mixed>>} */
    private function vidas(array $filtros, int $offset, int $limit): array
    {
        $params = [];
        $select = 'SELECT n.codigo, COALESCE(c.nombre_comercial, c.razon_social) AS cliente, n.vida_actual,
                v.numero_vida, v.fecha_inicio, v.fecha_fin, v.profundidad_inicial_mm, v.profundidad_final_mm,
                v.km_inicio, v.km_fin,
                (SELECT tm.nombre FROM mantenimientos_neumatico mm
                    INNER JOIN tipos_mantenimiento tm ON tm.id = mm.tipo_mantenimiento_id
                    WHERE mm.id = v.mantenimiento_origen_id) AS mantenimiento_origen';
        $from = ' FROM neumatico_vidas v
             INNER JOIN neumaticos n ON n.id = v.neumatico_id
             INNER JOIN clientes c ON c.id = n.cliente_id
             WHERE n.eliminado = 0'
            . ScopeSql::cliente('v.cliente_id', $filtros, $params, 'vid')
            . ScopeSql::montaje('n.id', $filtros, $params, 'vidm');
        if ($filtros['neumatico_id'] !== null) {
            $from .= ' AND v.neumatico_id = :vid_neu';
            $params['vid_neu'] = (int) $filtros['neumatico_id'];
        }

        return $this->pagina($select, $from, 'n.codigo ASC, v.numero_vida ASC', $params, $offset, $limit);
    }

    /** @param array<string, mixed> $filtros @return array{total:int,filas:list<array<string,mixed>>} */
    private function descartes(array $filtros, int $offset, int $limit): array
    {
        $params = [];
        $select = 'SELECT n.codigo AS neumatico, d.fecha_descarte AS fecha, md.nombre AS motivo,
                d.vida_final, d.profundidad_final_mm, d.km_totales, d.observacion';
        $from = ' FROM descartes_neumatico d
             INNER JOIN neumaticos n ON n.id = d.neumatico_id
             INNER JOIN motivos_descarte md ON md.id = d.motivo_descarte_id
             WHERE 1 = 1'
            . ScopeSql::cliente('d.cliente_id', $filtros, $params, 'des')
            . $this->fechas('d.fecha_descarte', $filtros, $params, 'desf');
        if ($filtros['motivo_id'] !== null) {
            $from .= ' AND d.motivo_descarte_id = :des_motivo';
            $params['des_motivo'] = (int) $filtros['motivo_id'];
        }
        if ($filtros['vida_final'] !== null) {
            $from .= ' AND d.vida_final = :des_vida';
            $params['des_vida'] = (int) $filtros['vida_final'];
        }

        return $this->pagina($select, $from, 'd.fecha_descarte DESC, d.id DESC', $params, $offset, $limit);
    }

    public function nombreCliente(int $id): ?string
    {
        $statement = $this->pdo->prepare(
            'SELECT COALESCE(nombre_comercial, razon_social) FROM clientes WHERE id = :id AND eliminado = 0 LIMIT 1'
        );
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
        $nombre = $statement->fetchColumn();

        return $nombre === false ? null : (string) $nombre;
    }

    /** @param array<string, mixed> $filtros @param array<string, int|string> $params */
    private function dondeMantenimiento(array $filtros, array &$params): string
    {
        $sql = ScopeSql::cliente('m.cliente_id', $filtros, $params, 'man')
            . ScopeSql::montaje('m.neumatico_id', $filtros, $params, 'manm')
            . $this->fechas('m.fecha_solicitud', $filtros, $params, 'manf');
        if ($filtros['estado'] !== null) {
            $sql .= ' AND e.codigo = :man_estado';
            $params['man_estado'] = (string) $filtros['estado'];
        }
        if ($filtros['tipo'] !== null) {
            $sql .= ' AND tm.codigo = :man_tipo';
            $params['man_tipo'] = (string) $filtros['tipo'];
        }
        if ($filtros['neumatico_id'] !== null) {
            $sql .= ' AND m.neumatico_id = :man_neu';
            $params['man_neu'] = (int) $filtros['neumatico_id'];
        }

        return $sql;
    }

    /** @param array<string, mixed> $filtros @param array<string, int|string> $params */
    private function catalogo(array $filtros, array &$params, string $prefijo): string
    {
        $sql = '';
        if ($filtros['estado'] !== null) {
            $sql .= ' AND e.codigo = :' . $prefijo . '_estado';
            $params[$prefijo . '_estado'] = (string) $filtros['estado'];
        }
        if ($filtros['marca_id'] !== null) {
            $sql .= ' AND ma.id = :' . $prefijo . '_marca';
            $params[$prefijo . '_marca'] = (int) $filtros['marca_id'];
        }
        if ($filtros['modelo_id'] !== null) {
            $sql .= ' AND mo.id = :' . $prefijo . '_modelo';
            $params[$prefijo . '_modelo'] = (int) $filtros['modelo_id'];
        }
        if ($filtros['medida_id'] !== null) {
            $sql .= ' AND me.id = :' . $prefijo . '_medida';
            $params[$prefijo . '_medida'] = (int) $filtros['medida_id'];
        }

        return $sql;
    }

    /** @param array<string, mixed> $filtros @param array<string, int|string> $params */
    private function fechas(string $column, array $filtros, array &$params, string $prefijo): string
    {
        $sql = '';
        if ($filtros['fecha_inicio'] !== null) {
            $sql .= ' AND ' . $column . ' >= :' . $prefijo . '_ini';
            $params[$prefijo . '_ini'] = (string) $filtros['fecha_inicio'] . ' 00:00:00';
        }
        if ($filtros['fecha_fin'] !== null) {
            $sql .= ' AND ' . $column . ' <= :' . $prefijo . '_fin';
            $params[$prefijo . '_fin'] = (string) $filtros['fecha_fin'] . ' 23:59:59';
        }

        return $sql;
    }

    private function medicion(): string
    {
        return '(SELECT CONCAT(
                IF(d.profundidad_interior_mm IS NULL, \'\', d.profundidad_interior_mm), \'|\',
                IF(d.profundidad_centro_mm IS NULL, \'\', d.profundidad_centro_mm), \'|\',
                IF(d.profundidad_exterior_mm IS NULL, \'\', d.profundidad_exterior_mm), \'|\',
                DATE_FORMAT(i.fecha_inspeccion, \'%Y-%m-%d %H:%i:%s\')
            )
            FROM inspeccion_detalles d
            INNER JOIN inspecciones i ON i.id = d.inspeccion_id
            WHERE d.neumatico_id = n.id AND i.estado = \'FINALIZADA\'
              AND i.fecha_inspeccion >= (
                SELECT v.fecha_inicio FROM neumatico_vidas v
                WHERE v.neumatico_id = n.id AND v.fecha_fin IS NULL
                ORDER BY v.numero_vida ASC LIMIT 1
              )
            ORDER BY i.fecha_inspeccion DESC, i.id DESC, d.id DESC
            LIMIT 1)';
    }

    private function criticidad(): string
    {
        return 'CASE
            WHEN EXISTS (
                SELECT 1 FROM alertas ax INNER JOIN estados_alerta ex ON ex.id = ax.estado_id
                WHERE ax.neumatico_id = n.id AND ex.es_final = 0 AND ax.nivel = \'CRITICA\'
            ) THEN \'CRITICA\'
            WHEN EXISTS (
                SELECT 1 FROM alertas ay INNER JOIN estados_alerta ey ON ey.id = ay.estado_id
                WHERE ay.neumatico_id = n.id AND ey.es_final = 0
            ) THEN \'ATENCION\'
            ELSE \'NORMAL\'
        END';
    }

    /**
     * @param array<string, int|string> $params
     * @return array{total:int,filas:list<array<string,mixed>>}
     */
    private function pagina(string $select, string $from, string $order, array $params, int $offset, int $limit): array
    {
        $conteo = $this->pdo->prepare('SELECT COUNT(*) ' . $from);
        ScopeSql::bind($conteo, $params);
        $conteo->execute();
        $total = (int) $conteo->fetchColumn();
        $limite = max(0, $limit);
        $inicio = max(0, $offset);
        $statement = $this->pdo->prepare($select . $from . ' ORDER BY ' . $order . ' LIMIT ' . $limite . ' OFFSET ' . $inicio);
        ScopeSql::bind($statement, $params);
        $statement->execute();

        return ['total' => $total, 'filas' => $statement->fetchAll()];
    }
}
