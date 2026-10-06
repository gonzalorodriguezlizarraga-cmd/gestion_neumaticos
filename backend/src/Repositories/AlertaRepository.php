<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\AsignacionVigente;
use PDO;

final class AlertaRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function tipos(): array
    {
        return $this->pdo->query('SELECT id, codigo, nombre, descripcion, activo FROM tipos_alerta WHERE activo = 1 ORDER BY nombre ASC, id ASC')->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function estados(): array
    {
        return $this->pdo->query('SELECT id, codigo, nombre, orden, es_final FROM estados_alerta WHERE activo = 1 ORDER BY orden ASC')->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function tipo(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, codigo, nombre, activo FROM tipos_alerta WHERE id = :id LIMIT 1');
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function estadoId(string $codigo): int
    {
        $statement = $this->pdo->prepare('SELECT id FROM estados_alerta WHERE codigo = :codigo LIMIT 1');
        $statement->bindValue('codigo', $codigo);
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    /** @return array<string, mixed>|null */
    public function cliente(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, eliminado FROM clientes WHERE id = :id LIMIT 1');
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function unidad(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, cliente_id, codigo, placa, eliminado FROM unidades WHERE id = :id LIMIT 1');
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function neumatico(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, cliente_id, codigo, eliminado FROM neumaticos WHERE id = :id LIMIT 1');
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @param array<string, mixed> $datos */
    public function crear(array $datos): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO alertas (
                cliente_id, tipo_alerta_id, estado_id, unidad_id, neumatico_id, nivel, titulo,
                descripcion, recomendacion, generada_automaticamente, fecha_generacion
             ) VALUES (
                :cliente_id, :tipo_id, :estado_id, :unidad_id, :neumatico_id, :nivel, :titulo,
                :descripcion, :recomendacion, 0, :fecha
             )'
        );
        $statement->bindValue('cliente_id', $datos['cliente_id'], PDO::PARAM_INT);
        $statement->bindValue('tipo_id', $datos['tipo_alerta_id'], PDO::PARAM_INT);
        $statement->bindValue('estado_id', $datos['estado_id'], PDO::PARAM_INT);
        $this->nulo($statement, 'unidad_id', $datos['unidad_id']);
        $this->nulo($statement, 'neumatico_id', $datos['neumatico_id']);
        $statement->bindValue('nivel', $datos['nivel']);
        $statement->bindValue('titulo', $datos['titulo']);
        $statement->bindValue('descripcion', $datos['descripcion']);
        $this->nulo($statement, 'recomendacion', $datos['recomendacion']);
        $statement->bindValue('fecha', $datos['fecha_generacion']);
        $statement->execute();

        return (int) $this->pdo->lastInsertId();
    }

    public function historial(int $alertaId, ?int $anteriorId, int $nuevoId, int $usuarioId, ?string $observacion, string $fecha): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO alerta_estado_historial (
                alerta_id, estado_anterior_id, estado_nuevo_id, fecha_cambio, usuario_id, observacion
             ) VALUES (
                :alerta_id, :anterior, :nuevo, :fecha, :usuario, :observacion
             )'
        );
        $statement->bindValue('alerta_id', $alertaId, PDO::PARAM_INT);
        $this->nulo($statement, 'anterior', $anteriorId);
        $statement->bindValue('nuevo', $nuevoId, PDO::PARAM_INT);
        $statement->bindValue('fecha', $fecha);
        $statement->bindValue('usuario', $usuarioId, PDO::PARAM_INT);
        $this->nulo($statement, 'observacion', $observacion);
        $statement->execute();
    }

    /** @return array<string, mixed>|null */
    public function bloquear(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT a.id, a.cliente_id, a.estado_id, a.generada_automaticamente, e.codigo AS estado_codigo, e.es_final
             FROM alertas a
             INNER JOIN estados_alerta e ON e.id = a.estado_id
             WHERE a.id = :id
             LIMIT 1
             FOR UPDATE OF a'
        );
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function marcarEstado(int $id, int $nuevoId, int $actualId): int
    {
        $statement = $this->pdo->prepare('UPDATE alertas SET estado_id = :nuevo WHERE id = :id AND estado_id = :actual');
        $statement->bindValue('nuevo', $nuevoId, PDO::PARAM_INT);
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->bindValue('actual', $actualId, PDO::PARAM_INT);
        $statement->execute();

        return $statement->rowCount();
    }

    /** @param array<string, mixed> $filtros @return array{rows:list<array<string,mixed>>,total:int} */
    public function listar(array $filtros): array
    {
        [$where, $params] = $this->where($filtros);
        $sqlWhere = implode(' AND ', $where);
        $from = 'FROM alertas a
             INNER JOIN tipos_alerta t ON t.id = a.tipo_alerta_id
             INNER JOIN estados_alerta e ON e.id = a.estado_id
             INNER JOIN clientes c ON c.id = a.cliente_id
             LEFT JOIN unidades u ON u.id = a.unidad_id
             LEFT JOIN neumaticos n ON n.id = a.neumatico_id';
        $count = $this->pdo->prepare('SELECT COUNT(*) ' . $from . ' WHERE ' . $sqlWhere);
        $this->bind($count, $params);
        $count->execute();
        $total = (int) $count->fetchColumn();
        $statement = $this->pdo->prepare(
            'SELECT a.id, a.fecha_generacion, a.nivel, a.titulo, a.generada_automaticamente,
                    c.id AS cliente_id, c.razon_social, c.nombre_comercial,
                    u.id AS unidad_id, u.codigo AS unidad_codigo, u.placa AS unidad_placa,
                    n.id AS neumatico_id, n.codigo AS neumatico_codigo,
                    t.id AS tipo_id, t.codigo AS tipo_codigo, t.nombre AS tipo_nombre,
                    e.id AS estado_id, e.codigo AS estado_codigo, e.nombre AS estado_nombre, e.es_final
             ' . $from . '
             WHERE ' . $sqlWhere . '
             ORDER BY ' . $filtros['sort'] . ' ' . $filtros['direction'] . ', a.id DESC
             LIMIT ' . (int) $filtros['limit'] . ' OFFSET ' . (int) $filtros['offset']
        );
        $this->bind($statement, $params);
        $statement->execute();

        return ['rows' => $statement->fetchAll(), 'total' => $total];
    }

    /** @return array<string, mixed>|null */
    public function ficha(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT a.id, a.cliente_id, a.fecha_generacion, a.nivel, a.titulo, a.descripcion, a.recomendacion,
                    a.generada_automaticamente, a.inspeccion_id, a.inspeccion_detalle_id,
                    c.razon_social, c.nombre_comercial,
                    u.id AS unidad_id, u.codigo AS unidad_codigo, u.placa AS unidad_placa,
                    n.id AS neumatico_id, n.codigo AS neumatico_codigo,
                    t.id AS tipo_id, t.codigo AS tipo_codigo, t.nombre AS tipo_nombre,
                    e.id AS estado_id, e.codigo AS estado_codigo, e.nombre AS estado_nombre, e.es_final,
                    i.fecha_inspeccion,
                    d.posicion_codigo_snapshot
             FROM alertas a
             INNER JOIN tipos_alerta t ON t.id = a.tipo_alerta_id
             INNER JOIN estados_alerta e ON e.id = a.estado_id
             INNER JOIN clientes c ON c.id = a.cliente_id
             LEFT JOIN unidades u ON u.id = a.unidad_id
             LEFT JOIN neumaticos n ON n.id = a.neumatico_id
             LEFT JOIN inspecciones i ON i.id = a.inspeccion_id
             LEFT JOIN inspeccion_detalles d ON d.id = a.inspeccion_detalle_id
             WHERE a.id = :id
             LIMIT 1'
        );
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    public function historialDe(int $id): array
    {
        $statement = $this->pdo->prepare(
            'SELECT h.id, h.fecha_cambio, h.observacion,
                    ea.codigo AS anterior_codigo, ea.nombre AS anterior_nombre,
                    en.codigo AS nuevo_codigo, en.nombre AS nuevo_nombre,
                    u.nombres, u.apellidos
             FROM alerta_estado_historial h
             LEFT JOIN estados_alerta ea ON ea.id = h.estado_anterior_id
             INNER JOIN estados_alerta en ON en.id = h.estado_nuevo_id
             INNER JOIN usuarios u ON u.id = h.usuario_id
             WHERE h.alerta_id = :id
             ORDER BY h.id ASC'
        );
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function contarPorTipo(int $inspeccionId, string $codigoTipo): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM alertas a
             INNER JOIN tipos_alerta t ON t.id = a.tipo_alerta_id
             WHERE a.inspeccion_id = :id AND t.codigo = :codigo'
        );
        $statement->bindValue('id', $inspeccionId, PDO::PARAM_INT);
        $statement->bindValue('codigo', $codigoTipo);
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    /**
     * @param array<string, mixed> $filtros
     * @return array{0:list<string>,1:array<string,mixed>}
     */
    private function where(array $filtros): array
    {
        $where = ['1 = 1'];
        $params = [];
        if ($filtros['usuario_scope'] !== null) {
            $where[] = 'EXISTS (SELECT 1 FROM usuario_clientes uc WHERE uc.cliente_id = a.cliente_id AND uc.usuario_id = :scope AND ' . AsignacionVigente::sql('uc') . ')';
            $params['scope'] = $filtros['usuario_scope'];
        }
        if ($filtros['ocultar_descartadas']) {
            $where[] = 'e.codigo <> \'DESCARTADA\'';
        }
        foreach ([
            'cliente_id' => 'a.cliente_id',
            'tipo_alerta_id' => 'a.tipo_alerta_id',
            'estado_id' => 'a.estado_id',
            'unidad_id' => 'a.unidad_id',
            'neumatico_id' => 'a.neumatico_id',
        ] as $key => $column) {
            if ($filtros[$key] !== null) {
                $where[] = $column . ' = :' . $key;
                $params[$key] = $filtros[$key];
            }
        }
        if ($filtros['nivel'] !== null) {
            $where[] = 'a.nivel = :nivel';
            $params['nivel'] = $filtros['nivel'];
        }
        if ($filtros['generada_automaticamente'] !== null) {
            $where[] = 'a.generada_automaticamente = :automatica';
            $params['automatica'] = $filtros['generada_automaticamente'];
        }
        if ($filtros['fecha_inicio'] !== null) {
            $where[] = 'a.fecha_generacion >= :desde';
            $params['desde'] = $filtros['fecha_inicio'];
        }
        if ($filtros['fecha_fin'] !== null) {
            $where[] = 'a.fecha_generacion <= :hasta';
            $params['hasta'] = $filtros['fecha_fin'];
        }
        if ($filtros['search'] !== null) {
            $where[] = '(a.titulo LIKE :busca_titulo ESCAPE \'\\\\\' OR a.descripcion LIKE :busca_desc ESCAPE \'\\\\\' OR IFNULL(n.codigo, \'\') LIKE :busca_neumatico ESCAPE \'\\\\\' OR IFNULL(u.codigo, \'\') LIKE :busca_unidad ESCAPE \'\\\\\' OR IFNULL(u.placa, \'\') LIKE :busca_placa ESCAPE \'\\\\\')';
            $params['busca_titulo'] = $filtros['search'];
            $params['busca_desc'] = $filtros['search'];
            $params['busca_neumatico'] = $filtros['search'];
            $params['busca_unidad'] = $filtros['search'];
            $params['busca_placa'] = $filtros['search'];
        }

        return [$where, $params];
    }

    /** @param array<string, mixed> $params */
    private function bind(\PDOStatement $statement, array $params): void
    {
        foreach ($params as $name => $value) {
            $statement->bindValue((string) $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
    }

    private function nulo(\PDOStatement $statement, string $name, mixed $value): void
    {
        if ($value === null) {
            $statement->bindValue($name, null, PDO::PARAM_NULL);
            return;
        }
        $statement->bindValue($name, is_int($value) ? $value : (string) $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
}
