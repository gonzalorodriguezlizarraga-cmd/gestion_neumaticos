<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\AsignacionVigente;
use App\Support\ListCriteria;
use PDO;

final class NeumaticoRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array{cliente:?int,marca:?int,modelo:?int,medida:?int,estado:?int,vida:?int,usuario:?int} $filtros
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function listar(ListCriteria $criteria, array $filtros): array
    {
        [$where, $params] = $this->filters($criteria, $filtros);
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM neumaticos n INNER JOIN modelos_neumatico mo ON mo.id = n.modelo_id WHERE ' . $where);
        $this->bind($count, $params);
        $count->execute();
        $total = (int) $count->fetchColumn();
        $statement = $this->pdo->prepare(
            'SELECT n.id, n.codigo, n.numero_serie, n.vida_actual, n.profundidad_inicial_mm, n.profundidad_minima_mm,
                    c.nombre_comercial, c.razon_social,
                    ma.nombre AS marca_nombre, mo.nombre AS modelo_nombre,
                    me.descripcion AS medida_descripcion,
                    e.codigo AS estado_codigo, e.nombre AS estado_nombre
             FROM neumaticos n
             INNER JOIN clientes c ON c.id = n.cliente_id
             INNER JOIN modelos_neumatico mo ON mo.id = n.modelo_id
             INNER JOIN marcas_neumatico ma ON ma.id = mo.marca_id
             INNER JOIN medidas_neumatico me ON me.id = n.medida_id
             INNER JOIN estados_neumatico e ON e.id = n.estado_id
             WHERE ' . $where . '
             ORDER BY ' . $criteria->sortExpression . ' ' . $criteria->direction . ', n.id ASC
             LIMIT ' . $criteria->limit . ' OFFSET ' . $criteria->offset
        );
        $this->bind($statement, $params);
        $statement->execute();
        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            $rows[] = [
                'id' => (int) $row['id'],
                'codigo' => $row['codigo'],
                'numero_serie' => $row['numero_serie'],
                'cliente' => $row['nombre_comercial'] ?: $row['razon_social'],
                'marca' => $row['marca_nombre'],
                'modelo' => $row['modelo_nombre'],
                'medida' => $row['medida_descripcion'],
                'estado' => $row['estado_nombre'],
                'estado_codigo' => $row['estado_codigo'],
                'vida_actual' => (int) $row['vida_actual'],
                'profundidad_inicial_mm' => $row['profundidad_inicial_mm'],
                'profundidad_minima_mm' => $row['profundidad_minima_mm'],
            ];
        }

        return ['rows' => $rows, 'total' => $total];
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT n.id, n.cliente_id, n.codigo, n.numero_serie, n.modelo_id, n.medida_id, n.estado_id,
                    n.fecha_adquisicion, n.costo_adquisicion, n.moneda, n.profundidad_inicial_mm, n.profundidad_minima_mm,
                    n.vida_actual, n.observacion, n.creado_en, n.actualizado_en, n.eliminado,
                    c.razon_social, c.nombre_comercial, c.estado AS cliente_estado,
                    ma.id AS marca_id, ma.nombre AS marca_nombre, ma.activo AS marca_activo,
                    mo.nombre AS modelo_nombre, mo.activo AS modelo_activo,
                    me.descripcion AS medida_descripcion, me.activo AS medida_activo,
                    e.codigo AS estado_codigo, e.nombre AS estado_nombre
             FROM neumaticos n
             INNER JOIN clientes c ON c.id = n.cliente_id
             INNER JOIN modelos_neumatico mo ON mo.id = n.modelo_id
             INNER JOIN marcas_neumatico ma ON ma.id = mo.marca_id
             INNER JOIN medidas_neumatico me ON me.id = n.medida_id
             INNER JOIN estados_neumatico e ON e.id = n.estado_id
             WHERE n.id = :id LIMIT 1'
        );
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function cliente(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, estado, eliminado FROM clientes WHERE id = :id LIMIT 1');
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @param array<string, mixed> $data */
    public function crear(array $data, int $estadoId, int $actor): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO neumaticos (
                cliente_id, codigo, numero_serie, modelo_id, medida_id, estado_id,
                fecha_adquisicion, costo_adquisicion, moneda,
                profundidad_inicial_mm, profundidad_minima_mm, vida_actual, observacion,
                creado_por, actualizado_por
             ) VALUES (
                :cliente_id, :codigo, :numero_serie, :modelo_id, :medida_id, :estado_id,
                :fecha_adquisicion, :costo_adquisicion, :moneda,
                :profundidad_inicial_mm, :profundidad_minima_mm, 1, :observacion,
                :creado_por, :actualizado_por
             )'
        );
        $statement->bindValue('cliente_id', $data['cliente_id'], PDO::PARAM_INT);
        $statement->bindValue('codigo', $data['codigo']);
        $this->nullable($statement, 'numero_serie', $data['numero_serie']);
        $statement->bindValue('modelo_id', $data['modelo_id'], PDO::PARAM_INT);
        $statement->bindValue('medida_id', $data['medida_id'], PDO::PARAM_INT);
        $statement->bindValue('estado_id', $estadoId, PDO::PARAM_INT);
        $this->nullable($statement, 'fecha_adquisicion', $data['fecha_adquisicion']);
        $this->nullable($statement, 'costo_adquisicion', $data['costo_adquisicion']);
        $this->nullable($statement, 'moneda', $data['moneda']);
        $this->nullable($statement, 'profundidad_inicial_mm', $data['profundidad_inicial_mm']);
        $statement->bindValue('profundidad_minima_mm', $data['profundidad_minima_mm']);
        $this->nullable($statement, 'observacion', $data['observacion']);
        $statement->bindValue('creado_por', $actor, PDO::PARAM_INT);
        $statement->bindValue('actualizado_por', $actor, PDO::PARAM_INT);
        $statement->execute();

        return (int) $this->pdo->lastInsertId();
    }

    public function crearVidaInicial(int $clienteId, int $neumaticoId, ?string $profundidad): void
    {
        $creado = $this->pdo->prepare('SELECT creado_en FROM neumaticos WHERE id = :id');
        $creado->bindValue('id', $neumaticoId, PDO::PARAM_INT);
        $creado->execute();
        $statement = $this->pdo->prepare(
            'INSERT INTO neumatico_vidas (
                cliente_id, neumatico_id, numero_vida, fecha_inicio, profundidad_inicial_mm
             ) VALUES (:cliente_id, :neumatico_id, 1, :fecha_inicio, :profundidad)'
        );
        $statement->bindValue('cliente_id', $clienteId, PDO::PARAM_INT);
        $statement->bindValue('neumatico_id', $neumaticoId, PDO::PARAM_INT);
        $statement->bindValue('fecha_inicio', (string) $creado->fetchColumn());
        $this->nullable($statement, 'profundidad', $profundidad);
        $statement->execute();
    }

    public function crearHistorialInicial(int $clienteId, int $neumaticoId, int $estadoId, int $usuarioId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO neumatico_estado_historial (
                cliente_id, neumatico_id, estado_anterior_id, estado_nuevo_id, fecha_cambio, usuario_id, motivo
             ) VALUES (
                :cliente_id, :neumatico_id, NULL, :estado_id, NOW(), :usuario_id, :motivo
             )'
        );
        $statement->bindValue('cliente_id', $clienteId, PDO::PARAM_INT);
        $statement->bindValue('neumatico_id', $neumaticoId, PDO::PARAM_INT);
        $statement->bindValue('estado_id', $estadoId, PDO::PARAM_INT);
        $statement->bindValue('usuario_id', $usuarioId, PDO::PARAM_INT);
        $statement->bindValue('motivo', 'Alta inicial del neumático');
        $statement->execute();
    }

    /** @param array<string, mixed> $data */
    public function actualizar(int $id, array $data, int $actor): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE neumaticos SET
                numero_serie = :numero_serie, modelo_id = :modelo_id, medida_id = :medida_id,
                fecha_adquisicion = :fecha_adquisicion, costo_adquisicion = :costo_adquisicion, moneda = :moneda,
                profundidad_inicial_mm = :profundidad_inicial_mm, profundidad_minima_mm = :profundidad_minima_mm,
                observacion = :observacion, actualizado_por = :actor
             WHERE id = :id AND eliminado = 0'
        );
        $this->nullable($statement, 'numero_serie', $data['numero_serie']);
        $statement->bindValue('modelo_id', $data['modelo_id'], PDO::PARAM_INT);
        $statement->bindValue('medida_id', $data['medida_id'], PDO::PARAM_INT);
        $this->nullable($statement, 'fecha_adquisicion', $data['fecha_adquisicion']);
        $this->nullable($statement, 'costo_adquisicion', $data['costo_adquisicion']);
        $this->nullable($statement, 'moneda', $data['moneda']);
        $this->nullable($statement, 'profundidad_inicial_mm', $data['profundidad_inicial_mm']);
        $statement->bindValue('profundidad_minima_mm', $data['profundidad_minima_mm']);
        $this->nullable($statement, 'observacion', $data['observacion']);
        $statement->bindValue('actor', $actor, PDO::PARAM_INT);
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
    }

    public function eliminar(int $id, int $actor): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE neumaticos
             SET eliminado = 1, eliminado_en = NOW(), eliminado_por = :actor, actualizado_por = :actor2
             WHERE id = :id AND eliminado = 0'
        );
        $statement->bindValue('actor', $actor, PDO::PARAM_INT);
        $statement->bindValue('actor2', $actor, PDO::PARAM_INT);
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
    }

    /** @return array{montajes:int,movimientos:int,inspecciones:int,mantenimientos:int,descartes:int,alertas:int} */
    public function dependencias(int $id): array
    {
        $statement = $this->pdo->prepare(
            'SELECT
                (SELECT COUNT(*) FROM montajes_neumatico WHERE neumatico_id = :a) AS montajes,
                (SELECT COUNT(*) FROM movimientos_neumatico WHERE neumatico_id = :b) AS movimientos,
                (SELECT COUNT(*) FROM inspeccion_detalles WHERE neumatico_id = :c) AS inspecciones,
                (SELECT COUNT(*) FROM mantenimientos_neumatico WHERE neumatico_id = :d) AS mantenimientos,
                (SELECT COUNT(*) FROM descartes_neumatico WHERE neumatico_id = :e) AS descartes,
                (SELECT COUNT(*) FROM alertas WHERE neumatico_id = :f) AS alertas'
        );
        foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $name) {
            $statement->bindValue($name, $id, PDO::PARAM_INT);
        }
        $statement->execute();
        $row = $statement->fetch() ?: [];

        return [
            'montajes' => (int) ($row['montajes'] ?? 0),
            'movimientos' => (int) ($row['movimientos'] ?? 0),
            'inspecciones' => (int) ($row['inspecciones'] ?? 0),
            'mantenimientos' => (int) ($row['mantenimientos'] ?? 0),
            'descartes' => (int) ($row['descartes'] ?? 0),
            'alertas' => (int) ($row['alertas'] ?? 0),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function historial(int $id): array
    {
        $statement = $this->pdo->prepare(
            'SELECT h.id, h.fecha_cambio, h.motivo,
                    ea.codigo AS anterior_codigo, ea.nombre AS anterior_nombre,
                    en.codigo AS nuevo_codigo, en.nombre AS nuevo_nombre,
                    u.nombres, u.apellidos
             FROM neumatico_estado_historial h
             INNER JOIN estados_neumatico en ON en.id = h.estado_nuevo_id
             LEFT JOIN estados_neumatico ea ON ea.id = h.estado_anterior_id
             INNER JOIN usuarios u ON u.id = h.usuario_id
             WHERE h.neumatico_id = :id
             ORDER BY h.fecha_cambio DESC, h.id DESC'
        );
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            $rows[] = [
                'id' => (int) $row['id'],
                'fecha_cambio' => $row['fecha_cambio'],
                'motivo' => $row['motivo'],
                'estado_anterior' => $row['anterior_codigo'] === null ? null : [
                    'codigo' => $row['anterior_codigo'],
                    'nombre' => $row['anterior_nombre'],
                ],
                'estado_nuevo' => [
                    'codigo' => $row['nuevo_codigo'],
                    'nombre' => $row['nuevo_nombre'],
                ],
                'usuario' => trim($row['nombres'] . ' ' . $row['apellidos']),
            ];
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    public function vidas(int $id): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, numero_vida, fecha_inicio, fecha_fin, km_inicio, km_fin,
                    profundidad_inicial_mm, profundidad_final_mm, motivo_fin
             FROM neumatico_vidas
             WHERE neumatico_id = :id
             ORDER BY numero_vida ASC, id ASC'
        );
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            $rows[] = [
                'id' => (int) $row['id'],
                'numero_vida' => (int) $row['numero_vida'],
                'fecha_inicio' => $row['fecha_inicio'],
                'fecha_fin' => $row['fecha_fin'],
                'km_inicio' => $row['km_inicio'] === null ? null : (int) $row['km_inicio'],
                'km_fin' => $row['km_fin'] === null ? null : (int) $row['km_fin'],
                'profundidad_inicial_mm' => $row['profundidad_inicial_mm'],
                'profundidad_final_mm' => $row['profundidad_final_mm'],
                'motivo_fin' => $row['motivo_fin'],
            ];
        }

        return $rows;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public function presentar(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'codigo' => $row['codigo'],
            'numero_serie' => $row['numero_serie'],
            'fecha_adquisicion' => $row['fecha_adquisicion'],
            'costo_adquisicion' => $row['costo_adquisicion'],
            'moneda' => $row['moneda'],
            'profundidad_inicial_mm' => $row['profundidad_inicial_mm'],
            'profundidad_minima_mm' => $row['profundidad_minima_mm'],
            'vida_actual' => (int) $row['vida_actual'],
            'observacion' => $row['observacion'],
            'creado_en' => $row['creado_en'],
            'actualizado_en' => $row['actualizado_en'],
            'cliente' => [
                'id' => (int) $row['cliente_id'],
                'razon_social' => $row['razon_social'],
                'nombre_comercial' => $row['nombre_comercial'],
                'estado' => $row['cliente_estado'],
            ],
            'marca' => [
                'id' => (int) $row['marca_id'],
                'nombre' => $row['marca_nombre'],
                'activo' => (int) $row['marca_activo'] === 1,
            ],
            'modelo' => [
                'id' => (int) $row['modelo_id'],
                'nombre' => $row['modelo_nombre'],
                'activo' => (int) $row['modelo_activo'] === 1,
            ],
            'medida' => [
                'id' => (int) $row['medida_id'],
                'descripcion' => $row['medida_descripcion'],
                'activo' => (int) $row['medida_activo'] === 1,
            ],
            'estado' => [
                'id' => (int) $row['estado_id'],
                'codigo' => $row['estado_codigo'],
                'nombre' => $row['estado_nombre'],
            ],
        ];
    }

    /**
     * @param array{cliente:?int,marca:?int,modelo:?int,medida:?int,estado:?int,vida:?int,usuario:?int} $filtros
     * @return array{0:string,1:array<string,mixed>}
     */
    private function filters(ListCriteria $criteria, array $filtros): array
    {
        $where = ['n.eliminado = 0'];
        $params = [];
        $like = $criteria->likePattern();
        if ($like !== null) {
            $where[] = '(n.codigo LIKE :search_codigo OR IFNULL(n.numero_serie, \'\') LIKE :search_serie)';
            $params['search_codigo'] = $like;
            $params['search_serie'] = $like;
        }
        foreach ([
            'cliente' => 'n.cliente_id',
            'marca' => 'mo.marca_id',
            'modelo' => 'n.modelo_id',
            'medida' => 'n.medida_id',
            'estado' => 'n.estado_id',
            'vida' => 'n.vida_actual',
        ] as $key => $column) {
            if ($filtros[$key] !== null) {
                $where[] = $column . ' = :filtro_' . $key;
                $params['filtro_' . $key] = $filtros[$key];
            }
        }
        if ($filtros['usuario'] !== null) {
            $where[] = 'EXISTS (
                SELECT 1 FROM usuario_clientes uc
                WHERE uc.cliente_id = n.cliente_id
                  AND uc.usuario_id = :usuario_id
                  AND ' . AsignacionVigente::sql('uc') . '
            )';
            $params['usuario_id'] = $filtros['usuario'];
        }

        return [implode(' AND ', $where), $params];
    }

    /** @param array<string, mixed> $params */
    private function bind(\PDOStatement $statement, array $params): void
    {
        foreach ($params as $name => $value) {
            $statement->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
    }

    private function nullable(\PDOStatement $statement, string $name, mixed $value): void
    {
        $statement->bindValue($name, $value, $value === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    }
}
