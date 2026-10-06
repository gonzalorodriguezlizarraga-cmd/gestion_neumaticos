<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\AsignacionVigente;
use App\Support\ListCriteria;
use PDO;

final class UnidadRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array{cliente:?int,sede:?int,flota:?int,tipo:?int,usuario:?int} $filtros
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function listar(ListCriteria $criteria, array $filtros): array
    {
        [$where, $params] = $this->filters($criteria, $filtros);
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM unidades u WHERE ' . $where);
        $this->bind($count, $params);
        $count->execute();
        $total = (int) $count->fetchColumn();
        $statement = $this->pdo->prepare(
            'SELECT u.id, u.codigo, u.placa, u.estado,
                    c.razon_social, c.nombre_comercial,
                    t.nombre AS tipo_nombre,
                    s.nombre AS sede_nombre, f.nombre AS flota_nombre
             FROM unidades u
             INNER JOIN clientes c ON c.id = u.cliente_id
             INNER JOIN tipos_unidad t ON t.id = u.tipo_unidad_id
             LEFT JOIN sedes s ON s.id = u.sede_id
             LEFT JOIN flotas f ON f.id = u.flota_id
             WHERE ' . $where . '
             ORDER BY ' . $criteria->sortExpression . ' ' . $criteria->direction . ', u.id ASC
             LIMIT ' . $criteria->limit . ' OFFSET ' . $criteria->offset
        );
        $this->bind($statement, $params);
        $statement->execute();
        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            $rows[] = [
                'id' => (int) $row['id'],
                'codigo' => $row['codigo'],
                'placa' => $row['placa'],
                'estado' => $row['estado'],
                'cliente' => $row['nombre_comercial'] ?: $row['razon_social'],
                'tipo' => $row['tipo_nombre'],
                'sede' => $row['sede_nombre'],
                'flota' => $row['flota_nombre'],
            ];
        }

        return ['rows' => $rows, 'total' => $total];
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT u.id, u.cliente_id, u.sede_id, u.flota_id, u.tipo_unidad_id, u.configuracion_id,
                    u.codigo, u.placa, u.marca, u.modelo, u.anio, u.numero_serie,
                    u.kilometraje_actual, u.horometro_actual, u.estado, u.observacion,
                    u.creado_en, u.actualizado_en, u.eliminado,
                    c.razon_social, c.nombre_comercial, c.estado AS cliente_estado,
                    s.codigo AS sede_codigo, s.nombre AS sede_nombre,
                    f.codigo AS flota_codigo, f.nombre AS flota_nombre,
                    t.codigo AS tipo_codigo, t.nombre AS tipo_nombre, t.activo AS tipo_activo,
                    cfg.nombre AS config_nombre, cfg.cantidad_ejes, cfg.cantidad_posiciones, cfg.activo AS config_activo
             FROM unidades u
             INNER JOIN clientes c ON c.id = u.cliente_id
             INNER JOIN tipos_unidad t ON t.id = u.tipo_unidad_id
             LEFT JOIN sedes s ON s.id = u.sede_id
             LEFT JOIN flotas f ON f.id = u.flota_id
             LEFT JOIN configuraciones_unidad cfg ON cfg.id = u.configuracion_id
             WHERE u.id = :id
             LIMIT 1'
        );
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function cliente(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, estado, eliminado FROM clientes WHERE id = :id LIMIT 1'
        );
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function sede(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, cliente_id, activo, eliminado FROM sedes WHERE id = :id LIMIT 1'
        );
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function flota(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, cliente_id, activo, eliminado FROM flotas WHERE id = :id LIMIT 1'
        );
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Bloquea el cambio de configuracion_id cuando la unidad ya tiene
     * operaciones que dependen de sus posiciones. Hoy cubre montajes e
     * inspecciones. En BLOQUE 4 debe ampliarse a movimientos_neumatico
     * con posicion_origen_id o posicion_destino_id. No consultar movimientos
     * hasta que ese módulo exista.
     */
    public function tieneHistoricoOperativo(int $id): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT (
                (SELECT COUNT(*) FROM montajes_neumatico WHERE unidad_id = :unidad)
                + (SELECT COUNT(*) FROM inspecciones WHERE unidad_id = :unidad_insp)
             )'
        );
        $statement->bindValue('unidad', $id, PDO::PARAM_INT);
        $statement->bindValue('unidad_insp', $id, PDO::PARAM_INT);
        $statement->execute();

        return (int) $statement->fetchColumn() > 0;
    }

    /** @return array{montajes:int,inspecciones:int,movimientos:int} */
    public function dependencias(int $id): array
    {
        $statement = $this->pdo->prepare(
            'SELECT
                (SELECT COUNT(*) FROM montajes_neumatico WHERE unidad_id = :u1) AS montajes,
                (SELECT COUNT(*) FROM inspecciones WHERE unidad_id = :u2) AS inspecciones,
                (SELECT COUNT(*) FROM movimientos_neumatico WHERE unidad_origen_id = :u3 OR unidad_destino_id = :u4) AS movimientos'
        );
        $statement->bindValue('u1', $id, PDO::PARAM_INT);
        $statement->bindValue('u2', $id, PDO::PARAM_INT);
        $statement->bindValue('u3', $id, PDO::PARAM_INT);
        $statement->bindValue('u4', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch() ?: [];

        return [
            'montajes' => (int) ($row['montajes'] ?? 0),
            'inspecciones' => (int) ($row['inspecciones'] ?? 0),
            'movimientos' => (int) ($row['movimientos'] ?? 0),
        ];
    }

    /** @param array<string, mixed> $data */
    public function crear(array $data, int $actor): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO unidades (
                cliente_id, sede_id, flota_id, tipo_unidad_id, configuracion_id, codigo, placa,
                marca, modelo, anio, numero_serie, kilometraje_actual, horometro_actual, estado,
                observacion, creado_por, actualizado_por, eliminado
             ) VALUES (
                :cliente_id, :sede_id, :flota_id, :tipo_unidad_id, :configuracion_id, :codigo, :placa,
                :marca, :modelo, :anio, :numero_serie, :kilometraje_actual, :horometro_actual, :estado,
                :observacion, :creado_por, :actualizado_por, 0
             )'
        );
        $this->bindUnidad($statement, $data);
        $statement->bindValue('creado_por', $actor, PDO::PARAM_INT);
        $statement->bindValue('actualizado_por', $actor, PDO::PARAM_INT);
        $statement->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string, mixed> $data */
    public function actualizar(int $id, array $data, int $actor): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE unidades SET
                sede_id = :sede_id, flota_id = :flota_id, tipo_unidad_id = :tipo_unidad_id,
                configuracion_id = :configuracion_id, codigo = :codigo, placa = :placa,
                marca = :marca, modelo = :modelo, anio = :anio, numero_serie = :numero_serie,
                kilometraje_actual = :kilometraje_actual, horometro_actual = :horometro_actual,
                observacion = :observacion, actualizado_por = :actualizado_por
             WHERE id = :id AND eliminado = 0'
        );
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $this->bindNullable($statement, 'sede_id', $data['sede_id'], true);
        $this->bindNullable($statement, 'flota_id', $data['flota_id'], true);
        $statement->bindValue('tipo_unidad_id', $data['tipo_unidad_id'], PDO::PARAM_INT);
        $this->bindNullable($statement, 'configuracion_id', $data['configuracion_id'], true);
        $statement->bindValue('codigo', $data['codigo']);
        $this->bindNullable($statement, 'placa', $data['placa'], false);
        $this->bindNullable($statement, 'marca', $data['marca'], false);
        $this->bindNullable($statement, 'modelo', $data['modelo'], false);
        $this->bindNullable($statement, 'anio', $data['anio'], true);
        $this->bindNullable($statement, 'numero_serie', $data['numero_serie'], false);
        $this->bindNullable($statement, 'kilometraje_actual', $data['kilometraje_actual'], true);
        $this->bindNullable($statement, 'horometro_actual', $data['horometro_actual'], false);
        $this->bindNullable($statement, 'observacion', $data['observacion'], false);
        $statement->bindValue('actualizado_por', $actor, PDO::PARAM_INT);
        $statement->execute();
    }

    public function cambiarEstado(int $id, string $estado, int $actor): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE unidades SET estado = :estado, actualizado_por = :actor WHERE id = :id AND eliminado = 0'
        );
        $statement->bindValue('estado', $estado);
        $statement->bindValue('actor', $actor, PDO::PARAM_INT);
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
    }

    public function eliminar(int $id, int $actor): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE unidades
             SET eliminado = 1, eliminado_en = NOW(), eliminado_por = :actor, actualizado_por = :actor2
             WHERE id = :id AND eliminado = 0'
        );
        $statement->bindValue('actor', $actor, PDO::PARAM_INT);
        $statement->bindValue('actor2', $actor, PDO::PARAM_INT);
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public function presentar(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'codigo' => $row['codigo'],
            'placa' => $row['placa'],
            'marca' => $row['marca'],
            'modelo' => $row['modelo'],
            'anio' => $row['anio'] === null ? null : (int) $row['anio'],
            'numero_serie' => $row['numero_serie'],
            'kilometraje_actual' => $row['kilometraje_actual'] === null ? null : (int) $row['kilometraje_actual'],
            'horometro_actual' => $row['horometro_actual'] === null ? null : $row['horometro_actual'],
            'estado' => $row['estado'],
            'observacion' => $row['observacion'],
            'creado_en' => $row['creado_en'],
            'actualizado_en' => $row['actualizado_en'],
            'cliente' => [
                'id' => (int) $row['cliente_id'],
                'razon_social' => $row['razon_social'],
                'nombre_comercial' => $row['nombre_comercial'],
                'estado' => $row['cliente_estado'],
            ],
            'sede' => $row['sede_id'] === null ? null : [
                'id' => (int) $row['sede_id'],
                'codigo' => $row['sede_codigo'],
                'nombre' => $row['sede_nombre'],
            ],
            'flota' => $row['flota_id'] === null ? null : [
                'id' => (int) $row['flota_id'],
                'codigo' => $row['flota_codigo'],
                'nombre' => $row['flota_nombre'],
            ],
            'tipo' => [
                'id' => (int) $row['tipo_unidad_id'],
                'codigo' => $row['tipo_codigo'],
                'nombre' => $row['tipo_nombre'],
                'activo' => (int) $row['tipo_activo'] === 1,
            ],
            'configuracion' => $row['configuracion_id'] === null ? null : [
                'id' => (int) $row['configuracion_id'],
                'nombre' => $row['config_nombre'],
                'cantidad_ejes' => (int) $row['cantidad_ejes'],
                'cantidad_posiciones' => (int) $row['cantidad_posiciones'],
                'activo' => (int) $row['config_activo'] === 1,
            ],
        ];
    }

    /**
     * @param array{cliente:?int,sede:?int,flota:?int,tipo:?int,usuario:?int} $filtros
     * @return array{0:string,1:array<string,mixed>}
     */
    private function filters(ListCriteria $criteria, array $filtros): array
    {
        $where = ['u.eliminado = 0'];
        $params = [];
        if ($criteria->estado !== null) {
            $where[] = 'u.estado = :estado';
            $params['estado'] = $criteria->estado;
        }
        $like = $criteria->likePattern();
        if ($like !== null) {
            $where[] = '(u.codigo LIKE :search_codigo OR IFNULL(u.placa, \'\') LIKE :search_placa
                OR IFNULL(u.marca, \'\') LIKE :search_marca OR IFNULL(u.modelo, \'\') LIKE :search_modelo
                OR IFNULL(u.numero_serie, \'\') LIKE :search_serie)';
            $params['search_codigo'] = $like;
            $params['search_placa'] = $like;
            $params['search_marca'] = $like;
            $params['search_modelo'] = $like;
            $params['search_serie'] = $like;
        }
        foreach (['cliente' => 'u.cliente_id', 'sede' => 'u.sede_id', 'flota' => 'u.flota_id', 'tipo' => 'u.tipo_unidad_id'] as $key => $column) {
            if ($filtros[$key] !== null) {
                $where[] = $column . ' = :filtro_' . $key;
                $params['filtro_' . $key] = $filtros[$key];
            }
        }
        if ($filtros['usuario'] !== null) {
            $where[] = 'EXISTS (
                SELECT 1 FROM usuario_clientes uc
                WHERE uc.cliente_id = u.cliente_id
                  AND uc.usuario_id = :usuario_id
                  AND ' . AsignacionVigente::sql('uc') . '
            )';
            $params['usuario_id'] = $filtros['usuario'];
        }

        return [implode(' AND ', $where), $params];
    }

    /** @param array<string, mixed> $data */
    private function bindUnidad(\PDOStatement $statement, array $data): void
    {
        $statement->bindValue('cliente_id', $data['cliente_id'], PDO::PARAM_INT);
        $this->bindNullable($statement, 'sede_id', $data['sede_id'], true);
        $this->bindNullable($statement, 'flota_id', $data['flota_id'], true);
        $statement->bindValue('tipo_unidad_id', $data['tipo_unidad_id'], PDO::PARAM_INT);
        $this->bindNullable($statement, 'configuracion_id', $data['configuracion_id'], true);
        $statement->bindValue('codigo', $data['codigo']);
        $this->bindNullable($statement, 'placa', $data['placa'], false);
        $this->bindNullable($statement, 'marca', $data['marca'], false);
        $this->bindNullable($statement, 'modelo', $data['modelo'], false);
        $this->bindNullable($statement, 'anio', $data['anio'], true);
        $this->bindNullable($statement, 'numero_serie', $data['numero_serie'], false);
        $this->bindNullable($statement, 'kilometraje_actual', $data['kilometraje_actual'], true);
        $this->bindNullable($statement, 'horometro_actual', $data['horometro_actual'], false);
        $statement->bindValue('estado', $data['estado']);
        $this->bindNullable($statement, 'observacion', $data['observacion'], false);
    }

    private function bindNullable(\PDOStatement $statement, string $name, mixed $value, bool $int): void
    {
        if ($value === null) {
            $statement->bindValue($name, null, PDO::PARAM_NULL);
            return;
        }
        $statement->bindValue($name, $int ? (int) $value : $value, $int ? PDO::PARAM_INT : PDO::PARAM_STR);
    }

    /** @param array<string, mixed> $params */
    private function bind(\PDOStatement $statement, array $params): void
    {
        foreach ($params as $name => $value) {
            $statement->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
    }
}
