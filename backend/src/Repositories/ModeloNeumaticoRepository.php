<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\ListCriteria;
use PDO;

final class ModeloNeumaticoRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function listar(ListCriteria $criteria, ?int $marcaId): array
    {
        $where = ['1 = 1'];
        $params = [];
        if ($criteria->activo !== null) {
            $where[] = 'mo.activo = :activo';
            $params['activo'] = $criteria->activo;
        }
        if ($marcaId !== null) {
            $where[] = 'mo.marca_id = :marca_id';
            $params['marca_id'] = $marcaId;
        }
        $like = $criteria->likePattern();
        if ($like !== null) {
            $where[] = '(mo.nombre LIKE :search_nombre OR IFNULL(mo.descripcion, \'\') LIKE :search_descripcion)';
            $params['search_nombre'] = $like;
            $params['search_descripcion'] = $like;
        }
        $sqlWhere = implode(' AND ', $where);
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM modelos_neumatico mo WHERE ' . $sqlWhere);
        $this->bind($count, $params);
        $count->execute();
        $total = (int) $count->fetchColumn();
        $statement = $this->pdo->prepare(
            'SELECT mo.id, mo.marca_id, ma.nombre AS marca_nombre, mo.nombre, mo.descripcion, mo.activo, mo.creado_en
             FROM modelos_neumatico mo
             INNER JOIN marcas_neumatico ma ON ma.id = mo.marca_id
             WHERE ' . $sqlWhere . '
             ORDER BY ' . $criteria->sortExpression . ' ' . $criteria->direction . ', mo.id ASC
             LIMIT ' . $criteria->limit . ' OFFSET ' . $criteria->offset
        );
        $this->bind($statement, $params);
        $statement->execute();

        return [
            'rows' => array_map(fn (array $row): array => $this->presentar($row), $statement->fetchAll()),
            'total' => $total,
        ];
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT mo.id, mo.marca_id, ma.nombre AS marca_nombre, ma.activo AS marca_activo,
                    mo.nombre, mo.descripcion, mo.activo, mo.creado_en
             FROM modelos_neumatico mo
             INNER JOIN marcas_neumatico ma ON ma.id = mo.marca_id
             WHERE mo.id = :id LIMIT 1'
        );
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @param array{marca_id:int,nombre:string,descripcion:?string,activo:bool} $data */
    public function crear(array $data): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO modelos_neumatico (marca_id, nombre, descripcion, activo)
             VALUES (:marca_id, :nombre, :descripcion, :activo)'
        );
        $statement->bindValue('marca_id', $data['marca_id'], PDO::PARAM_INT);
        $statement->bindValue('nombre', $data['nombre']);
        $statement->bindValue('descripcion', $data['descripcion'], $data['descripcion'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue('activo', $data['activo'] ? 1 : 0, PDO::PARAM_INT);
        $statement->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array{marca_id:int,nombre:string,descripcion:?string} $data */
    public function actualizar(int $id, array $data): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE modelos_neumatico SET marca_id = :marca_id, nombre = :nombre, descripcion = :descripcion WHERE id = :id'
        );
        $statement->bindValue('marca_id', $data['marca_id'], PDO::PARAM_INT);
        $statement->bindValue('nombre', $data['nombre']);
        $statement->bindValue('descripcion', $data['descripcion'], $data['descripcion'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
    }

    public function cambiarActivo(int $id, bool $activo): void
    {
        $statement = $this->pdo->prepare('UPDATE modelos_neumatico SET activo = :activo WHERE id = :id');
        $statement->bindValue('activo', $activo ? 1 : 0, PDO::PARAM_INT);
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public function presentar(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'marca_id' => (int) $row['marca_id'],
            'marca' => $row['marca_nombre'],
            'nombre' => $row['nombre'],
            'descripcion' => $row['descripcion'],
            'activo' => (int) $row['activo'] === 1,
            'creado_en' => $row['creado_en'],
        ];
    }

    /** @param array<string, mixed> $params */
    private function bind(\PDOStatement $statement, array $params): void
    {
        foreach ($params as $name => $value) {
            $statement->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
    }
}
