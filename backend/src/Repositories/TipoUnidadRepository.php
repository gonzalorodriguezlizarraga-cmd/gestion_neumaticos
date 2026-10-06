<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\ListCriteria;
use PDO;

final class TipoUnidadRepository
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
            $where[] = '(codigo LIKE :search_codigo OR nombre LIKE :search_nombre)';
            $params['search_codigo'] = $like;
            $params['search_nombre'] = $like;
        }
        $sqlWhere = implode(' AND ', $where);
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM tipos_unidad WHERE ' . $sqlWhere);
        $this->bind($count, $params);
        $count->execute();
        $total = (int) $count->fetchColumn();

        $statement = $this->pdo->prepare(
            'SELECT id, codigo, nombre, descripcion, activo, creado_en
             FROM tipos_unidad
             WHERE ' . $sqlWhere . '
             ORDER BY ' . $criteria->sortExpression . ' ' . $criteria->direction . ', id ASC
             LIMIT ' . $criteria->limit . ' OFFSET ' . $criteria->offset
        );
        $this->bind($statement, $params);
        $statement->execute();

        return [
            'rows' => array_map(fn (array $row): array => $this->presentar($row, false), $statement->fetchAll()),
            'total' => $total,
        ];
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, codigo, nombre, descripcion, activo, creado_en FROM tipos_unidad WHERE id = :id LIMIT 1'
        );
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function enUso(int $id): bool
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM unidades WHERE tipo_unidad_id = :id');
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();

        return (int) $statement->fetchColumn() > 0;
    }

    /** @param array{codigo:string,nombre:string,descripcion:?string,activo:bool} $data */
    public function crear(array $data): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO tipos_unidad (codigo, nombre, descripcion, activo) VALUES (:codigo, :nombre, :descripcion, :activo)'
        );
        $statement->bindValue('codigo', $data['codigo']);
        $statement->bindValue('nombre', $data['nombre']);
        $statement->bindValue('descripcion', $data['descripcion']);
        $statement->bindValue('activo', $data['activo'] ? 1 : 0, PDO::PARAM_INT);
        $statement->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array{codigo:?string,nombre:string,descripcion:?string} $data */
    public function actualizar(int $id, array $data, bool $cambiarCodigo): void
    {
        $sql = $cambiarCodigo
            ? 'UPDATE tipos_unidad SET codigo = :codigo, nombre = :nombre, descripcion = :descripcion WHERE id = :id'
            : 'UPDATE tipos_unidad SET nombre = :nombre, descripcion = :descripcion WHERE id = :id';
        $statement = $this->pdo->prepare($sql);
        if ($cambiarCodigo) {
            $statement->bindValue('codigo', $data['codigo']);
        }
        $statement->bindValue('nombre', $data['nombre']);
        $statement->bindValue('descripcion', $data['descripcion']);
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
    }

    public function cambiarActivo(int $id, bool $activo): void
    {
        $statement = $this->pdo->prepare('UPDATE tipos_unidad SET activo = :activo WHERE id = :id');
        $statement->bindValue('activo', $activo ? 1 : 0, PDO::PARAM_INT);
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public function presentar(array $row, bool $enUso): array
    {
        $data = [
            'id' => (int) $row['id'],
            'codigo' => $row['codigo'],
            'nombre' => $row['nombre'],
            'descripcion' => $row['descripcion'],
            'activo' => (int) $row['activo'] === 1,
            'creado_en' => $row['creado_en'],
        ];
        if ($enUso) {
            $data['en_uso'] = $this->enUso((int) $row['id']);
        }

        return $data;
    }

    /** @param array<string, mixed> $params */
    private function bind(\PDOStatement $statement, array $params): void
    {
        foreach ($params as $name => $value) {
            $statement->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
    }
}
