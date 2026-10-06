<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\AsignacionVigente;
use App\Support\ListCriteria;
use PDO;

final class ClienteRepository
{
    /** @var list<string> */
    public const EDITABLES = [
        'razon_social',
        'nombre_comercial',
        'ruc_documento',
        'direccion',
        'telefono',
        'email',
        'fecha_inicio_servicio',
        'estado',
        'observacion',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function listar(ListCriteria $criteria, ?int $usuarioId): array
    {
        [$where, $params] = $this->filters($criteria, $usuarioId);
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM clientes c WHERE ' . $where);
        $this->bind($count, $params);
        $count->execute();
        $total = (int) $count->fetchColumn();

        $sql = 'SELECT c.id, c.razon_social, c.nombre_comercial, c.ruc_documento, c.direccion,
                       c.telefono, c.email, c.fecha_inicio_servicio, c.estado, c.observacion,
                       c.creado_en, c.actualizado_en
                FROM clientes c
                WHERE ' . $where . '
                ORDER BY ' . $criteria->sortExpression . ' ' . $criteria->direction . ', c.id ASC
                LIMIT ' . $criteria->limit . ' OFFSET ' . $criteria->offset;
        $statement = $this->pdo->prepare($sql);
        $this->bind($statement, $params);
        $statement->execute();

        return [
            'rows' => array_map([$this, 'presentar'], $statement->fetchAll()),
            'total' => $total,
        ];
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, razon_social, nombre_comercial, ruc_documento, direccion, telefono, email,
                    fecha_inicio_servicio, estado, observacion, creado_en, creado_por,
                    actualizado_en, actualizado_por, eliminado, eliminado_en, eliminado_por
             FROM clientes
             WHERE id = :id
             LIMIT 1'
        );
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @param array<string, mixed> $data */
    public function crear(array $data, int $actor): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO clientes (
                razon_social, nombre_comercial, ruc_documento, direccion, telefono, email,
                fecha_inicio_servicio, estado, observacion, creado_por, actualizado_por, eliminado
             ) VALUES (
                :razon_social, :nombre_comercial, :ruc_documento, :direccion, :telefono, :email,
                :fecha_inicio_servicio, :estado, :observacion, :creado_por, :actualizado_por, 0
             )'
        );
        $this->bindNullable($statement, 'razon_social', $data['razon_social']);
        $this->bindNullable($statement, 'nombre_comercial', $data['nombre_comercial']);
        $this->bindNullable($statement, 'ruc_documento', $data['ruc_documento']);
        $this->bindNullable($statement, 'direccion', $data['direccion']);
        $this->bindNullable($statement, 'telefono', $data['telefono']);
        $this->bindNullable($statement, 'email', $data['email']);
        $this->bindNullable($statement, 'fecha_inicio_servicio', $data['fecha_inicio_servicio']);
        $this->bindNullable($statement, 'estado', $data['estado']);
        $this->bindNullable($statement, 'observacion', $data['observacion']);
        $statement->bindValue('creado_por', $actor, PDO::PARAM_INT);
        $statement->bindValue('actualizado_por', $actor, PDO::PARAM_INT);
        $statement->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string, mixed> $fields */
    public function actualizar(int $id, array $fields, int $actor): void
    {
        if ($fields === []) {
            $statement = $this->pdo->prepare(
                'UPDATE clientes SET actualizado_por = :actor WHERE id = :id AND eliminado = 0'
            );
            $statement->bindValue('actor', $actor, PDO::PARAM_INT);
            $statement->bindValue('id', $id, PDO::PARAM_INT);
            $statement->execute();
            return;
        }

        $sets = ['actualizado_por = :actualizado_por'];
        foreach (array_keys($fields) as $column) {
            if (!in_array($column, self::EDITABLES, true)) {
                throw new \InvalidArgumentException('Columna de cliente no editable.');
            }
            $sets[] = $column . ' = :' . $column;
        }
        $statement = $this->pdo->prepare(
            'UPDATE clientes SET ' . implode(', ', $sets) . ' WHERE id = :id AND eliminado = 0'
        );
        $statement->bindValue('actualizado_por', $actor, PDO::PARAM_INT);
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        foreach ($fields as $column => $value) {
            $this->bindNullable($statement, $column, $value);
        }
        $statement->execute();
    }

    public function eliminar(int $id, int $actor): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE clientes
             SET eliminado = 1, eliminado_en = NOW(), eliminado_por = :actor, actualizado_por = :actualizado
             WHERE id = :id AND eliminado = 0'
        );
        $statement->bindValue('actor', $actor, PDO::PARAM_INT);
        $statement->bindValue('actualizado', $actor, PDO::PARAM_INT);
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
    }

    public function rucOcupado(?string $ruc, ?int $exceptoId = null): bool
    {
        if ($ruc === null || $ruc === '') {
            return false;
        }
        $sql = 'SELECT id FROM clientes WHERE ruc_documento = :ruc';
        if ($exceptoId !== null) {
            $sql .= ' AND id <> :id';
        }
        $sql .= ' LIMIT 1';
        $statement = $this->pdo->prepare($sql);
        $statement->bindValue('ruc', $ruc);
        if ($exceptoId !== null) {
            $statement->bindValue('id', $exceptoId, PDO::PARAM_INT);
        }
        $statement->execute();

        return $statement->fetchColumn() !== false;
    }

    /** @return array<string, int> */
    public function dependencias(int $id): array
    {
        $statement = $this->pdo->prepare(
            'SELECT
                (SELECT COUNT(*) FROM sedes WHERE cliente_id = :id) AS sedes,
                (SELECT COUNT(*) FROM flotas WHERE cliente_id = :id2) AS flotas,
                (SELECT COUNT(*) FROM unidades WHERE cliente_id = :id3) AS unidades,
                (SELECT COUNT(*) FROM neumaticos WHERE cliente_id = :id4) AS neumaticos'
        );
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->bindValue('id2', $id, PDO::PARAM_INT);
        $statement->bindValue('id3', $id, PDO::PARAM_INT);
        $statement->bindValue('id4', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch() ?: [];

        return [
            'sedes' => (int) ($row['sedes'] ?? 0),
            'flotas' => (int) ($row['flotas'] ?? 0),
            'unidades' => (int) ($row['unidades'] ?? 0),
            'neumaticos' => (int) ($row['neumaticos'] ?? 0),
        ];
    }

    public function hoy(): string
    {
        return (string) $this->pdo->query('SELECT CURRENT_DATE')->fetchColumn();
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public function presentar(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'razon_social' => $row['razon_social'],
            'nombre_comercial' => $row['nombre_comercial'],
            'ruc_documento' => $row['ruc_documento'],
            'direccion' => $row['direccion'],
            'telefono' => $row['telefono'],
            'email' => $row['email'],
            'fecha_inicio_servicio' => $row['fecha_inicio_servicio'],
            'estado' => $row['estado'],
            'observacion' => $row['observacion'],
            'creado_en' => $row['creado_en'],
            'actualizado_en' => $row['actualizado_en'],
        ];
    }

    /**
     * @return array{0:string,1:array<string,mixed>}
     */
    private function filters(ListCriteria $criteria, ?int $usuarioId): array
    {
        $where = ['c.eliminado = 0'];
        $params = [];
        if ($criteria->estado !== null) {
            $where[] = 'c.estado = :estado';
            $params['estado'] = $criteria->estado;
        }
        $like = $criteria->likePattern();
        if ($like !== null) {
            $where[] = '(c.razon_social LIKE :search_razon OR IFNULL(c.nombre_comercial, \'\') LIKE :search_comercial OR IFNULL(c.ruc_documento, \'\') LIKE :search_ruc)';
            $params['search_razon'] = $like;
            $params['search_comercial'] = $like;
            $params['search_ruc'] = $like;
        }
        if ($usuarioId !== null) {
            $where[] = 'EXISTS (
                SELECT 1 FROM usuario_clientes uc
                WHERE uc.cliente_id = c.id
                  AND uc.usuario_id = :usuario_id
                  AND ' . AsignacionVigente::sql('uc') . '
            )';
            $params['usuario_id'] = $usuarioId;
        }

        return [implode(' AND ', $where), $params];
    }

    /** @param array<string, mixed> $params */
    private function bind(\PDOStatement $statement, array $params): void
    {
        foreach ($params as $name => $value) {
            if ($name === 'usuario_id') {
                $statement->bindValue($name, $value, PDO::PARAM_INT);
                continue;
            }
            $statement->bindValue($name, $value);
        }
    }

    private function bindNullable(\PDOStatement $statement, string $name, mixed $value): void
    {
        if ($value === null) {
            $statement->bindValue($name, null, PDO::PARAM_NULL);
            return;
        }
        $statement->bindValue($name, $value);
    }
}
