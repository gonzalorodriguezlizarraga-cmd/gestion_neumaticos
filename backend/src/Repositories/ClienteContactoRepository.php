<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\ListCriteria;
use PDO;

final class ClienteContactoRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function listar(int $clienteId, ListCriteria $criteria): array
    {
        $where = 'cliente_id = :cliente_id';
        if ($criteria->activo !== null) {
            $where .= ' AND activo = :activo';
        }
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM cliente_contactos WHERE ' . $where);
        $count->bindValue('cliente_id', $clienteId, PDO::PARAM_INT);
        if ($criteria->activo !== null) {
            $count->bindValue('activo', $criteria->activo, PDO::PARAM_INT);
        }
        $count->execute();

        $sql = 'SELECT id, cliente_id, nombre, cargo, telefono, email, tipo_contacto, principal, activo, creado_en, actualizado_en
                FROM cliente_contactos
                WHERE ' . $where . '
                ORDER BY ' . $criteria->sortExpression . ' ' . $criteria->direction . ', id ASC
                LIMIT ' . $criteria->limit . ' OFFSET ' . $criteria->offset;
        $statement = $this->pdo->prepare($sql);
        $statement->bindValue('cliente_id', $clienteId, PDO::PARAM_INT);
        if ($criteria->activo !== null) {
            $statement->bindValue('activo', $criteria->activo, PDO::PARAM_INT);
        }
        $statement->execute();

        return [
            'rows' => array_map([$this, 'presentar'], $statement->fetchAll()),
            'total' => (int) $count->fetchColumn(),
        ];
    }

    /** @return array<string, mixed>|null */
    public function find(int $clienteId, int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, cliente_id, nombre, cargo, telefono, email, tipo_contacto, principal, activo, creado_en, actualizado_en
             FROM cliente_contactos
             WHERE id = :id AND cliente_id = :cliente_id
             LIMIT 1'
        );
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->bindValue('cliente_id', $clienteId, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @param array<string, mixed> $data */
    public function crear(int $clienteId, array $data): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO cliente_contactos (
                cliente_id, nombre, cargo, telefono, email, tipo_contacto, principal, activo
             ) VALUES (
                :cliente_id, :nombre, :cargo, :telefono, :email, :tipo_contacto, :principal, :activo
             )'
        );
        $statement->bindValue('cliente_id', $clienteId, PDO::PARAM_INT);
        $statement->bindValue('nombre', $data['nombre']);
        $this->bindNullable($statement, 'cargo', $data['cargo']);
        $this->bindNullable($statement, 'telefono', $data['telefono']);
        $this->bindNullable($statement, 'email', $data['email']);
        $this->bindNullable($statement, 'tipo_contacto', $data['tipo_contacto']);
        $statement->bindValue('principal', $data['principal'] ? 1 : 0, PDO::PARAM_INT);
        $statement->bindValue('activo', $data['activo'] ? 1 : 0, PDO::PARAM_INT);
        $statement->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string, mixed> $data */
    public function actualizar(int $clienteId, int $id, array $data): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE cliente_contactos
             SET nombre = :nombre, cargo = :cargo, telefono = :telefono, email = :email,
                 tipo_contacto = :tipo_contacto, principal = :principal, activo = :activo
             WHERE id = :id AND cliente_id = :cliente_id'
        );
        $statement->bindValue('nombre', $data['nombre']);
        $this->bindNullable($statement, 'cargo', $data['cargo']);
        $this->bindNullable($statement, 'telefono', $data['telefono']);
        $this->bindNullable($statement, 'email', $data['email']);
        $this->bindNullable($statement, 'tipo_contacto', $data['tipo_contacto']);
        $statement->bindValue('principal', $data['principal'] ? 1 : 0, PDO::PARAM_INT);
        $statement->bindValue('activo', $data['activo'] ? 1 : 0, PDO::PARAM_INT);
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->bindValue('cliente_id', $clienteId, PDO::PARAM_INT);
        $statement->execute();
    }

    public function cambiarActivo(int $clienteId, int $id, bool $activo): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE cliente_contactos SET activo = :activo WHERE id = :id AND cliente_id = :cliente_id'
        );
        $statement->bindValue('activo', $activo ? 1 : 0, PDO::PARAM_INT);
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->bindValue('cliente_id', $clienteId, PDO::PARAM_INT);
        $statement->execute();
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public function presentar(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'cliente_id' => (int) $row['cliente_id'],
            'nombre' => $row['nombre'],
            'cargo' => $row['cargo'],
            'telefono' => $row['telefono'],
            'email' => $row['email'],
            'tipo_contacto' => $row['tipo_contacto'],
            'principal' => (int) $row['principal'] === 1,
            'activo' => (int) $row['activo'] === 1,
            'creado_en' => $row['creado_en'],
            'actualizado_en' => $row['actualizado_en'],
        ];
    }

    private function bindNullable(\PDOStatement $statement, string $name, mixed $value): void
    {
        $statement->bindValue($name, $value, $value === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    }
}
