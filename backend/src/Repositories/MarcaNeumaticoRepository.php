<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\ListCriteria;
use PDO;

final class MarcaNeumaticoRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function listar(ListCriteria $criteria): array
    {
        $where = ['1 = 1'];
        $params = [];
        if ($criteria->activo !== null) {
            $where[] = 'activo = :activo';
            $params['activo'] = $criteria->activo;
        }
        $like = $criteria->likePattern();
        if ($like !== null) {
            $where[] = 'nombre LIKE :search_nombre';
            $params['search_nombre'] = $like;
        }
        $sqlWhere = implode(' AND ', $where);
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM marcas_neumatico WHERE ' . $sqlWhere);
        $this->bind($count, $params);
        $count->execute();
        $total = (int) $count->fetchColumn();
        $statement = $this->pdo->prepare(
            'SELECT id, nombre, activo, creado_en FROM marcas_neumatico WHERE ' . $sqlWhere
            . ' ORDER BY ' . $criteria->sortExpression . ' ' . $criteria->direction . ', id ASC'
            . ' LIMIT ' . $criteria->limit . ' OFFSET ' . $criteria->offset
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
        $statement = $this->pdo->prepare('SELECT id, nombre, activo, creado_en FROM marcas_neumatico WHERE id = :id LIMIT 1');
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @param array{nombre:string,activo:bool} $data */
    public function crear(array $data): int
    {
        $statement = $this->pdo->prepare('INSERT INTO marcas_neumatico (nombre, activo) VALUES (:nombre, :activo)');
        $statement->bindValue('nombre', $data['nombre']);
        $statement->bindValue('activo', $data['activo'] ? 1 : 0, PDO::PARAM_INT);
        $statement->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array{nombre:string} $data */
    public function actualizar(int $id, array $data): void
    {
        $statement = $this->pdo->prepare('UPDATE marcas_neumatico SET nombre = :nombre WHERE id = :id');
        $statement->bindValue('nombre', $data['nombre']);
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
    }

    public function cambiarActivo(int $id, bool $activo): void
    {
        $statement = $this->pdo->prepare('UPDATE marcas_neumatico SET activo = :activo WHERE id = :id');
        $statement->bindValue('activo', $activo ? 1 : 0, PDO::PARAM_INT);
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public function presentar(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'nombre' => $row['nombre'],
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
