<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\ListCriteria;
use PDO;

final class EstadoNeumaticoRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function listar(ListCriteria $criteria): array
    {
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM estados_neumatico')->fetchColumn();
        $statement = $this->pdo->query(
            'SELECT id, codigo, nombre, descripcion, permite_montaje, es_final, orden, activo
             FROM estados_neumatico
             ORDER BY ' . $criteria->sortExpression . ' ' . $criteria->direction . ', id ASC
             LIMIT ' . $criteria->limit . ' OFFSET ' . $criteria->offset
        );

        return [
            'rows' => array_map(fn (array $row): array => $this->presentar($row), $statement->fetchAll()),
            'total' => $count,
        ];
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, codigo, nombre, descripcion, permite_montaje, es_final, orden, activo
             FROM estados_neumatico WHERE id = :id LIMIT 1'
        );
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function findByCodigo(string $codigo): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, codigo, nombre, descripcion, permite_montaje, es_final, orden, activo
             FROM estados_neumatico WHERE codigo = :codigo LIMIT 1'
        );
        $statement->bindValue('codigo', $codigo);
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public function presentar(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'codigo' => $row['codigo'],
            'nombre' => $row['nombre'],
            'descripcion' => $row['descripcion'],
            'permite_montaje' => (int) $row['permite_montaje'] === 1,
            'es_final' => (int) $row['es_final'] === 1,
            'orden' => (int) $row['orden'],
            'activo' => (int) $row['activo'] === 1,
        ];
    }
}
