<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\ListCriteria;
use PDO;

final class OrganizacionRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $tabla,
        private readonly string $uniqueKey,
    ) {
    }

    public static function sedes(PDO $pdo): self
    {
        return new self($pdo, 'sedes', 'uk_sedes_cliente_codigo');
    }

    public static function flotas(PDO $pdo): self
    {
        return new self($pdo, 'flotas', 'uk_flotas_cliente_codigo');
    }

    public function uniqueKey(): string
    {
        return $this->uniqueKey;
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function listar(int $clienteId, ListCriteria $criteria): array
    {
        $where = 'cliente_id = :cliente_id AND eliminado = 0';
        if ($criteria->activo !== null) {
            $where .= ' AND activo = :activo';
        }
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM ' . $this->tabla . ' WHERE ' . $where);
        $this->bindScope($count, $clienteId, $criteria);
        $count->execute();

        $columns = $this->tabla === 'flotas'
            ? 'id, cliente_id, codigo, nombre, descripcion, activo, creado_en, actualizado_en'
            : 'id, cliente_id, codigo, nombre, direccion, activo, creado_en, actualizado_en';
        $sql = 'SELECT ' . $columns . ' FROM ' . $this->tabla . '
                WHERE ' . $where . '
                ORDER BY ' . $criteria->sortExpression . ' ' . $criteria->direction . ', id ASC
                LIMIT ' . $criteria->limit . ' OFFSET ' . $criteria->offset;
        $statement = $this->pdo->prepare($sql);
        $this->bindScope($statement, $clienteId, $criteria);
        $statement->execute();

        return [
            'rows' => array_map([$this, 'presentar'], $statement->fetchAll()),
            'total' => (int) $count->fetchColumn(),
        ];
    }

    /** @return array<string, mixed>|null */
    public function find(int $clienteId, int $id, bool $incluirEliminado = false): ?array
    {
        $sql = 'SELECT * FROM ' . $this->tabla . ' WHERE id = :id AND cliente_id = :cliente_id';
        if (!$incluirEliminado) {
            $sql .= ' AND eliminado = 0';
        }
        $sql .= ' LIMIT 1';
        $statement = $this->pdo->prepare($sql);
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->bindValue('cliente_id', $clienteId, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function codigoOcupado(int $clienteId, string $codigo, ?int $exceptoId = null): bool
    {
        $sql = 'SELECT id FROM ' . $this->tabla . ' WHERE cliente_id = :cliente_id AND codigo = :codigo';
        if ($exceptoId !== null) {
            $sql .= ' AND id <> :id';
        }
        $sql .= ' LIMIT 1';
        $statement = $this->pdo->prepare($sql);
        $statement->bindValue('cliente_id', $clienteId, PDO::PARAM_INT);
        $statement->bindValue('codigo', $codigo);
        if ($exceptoId !== null) {
            $statement->bindValue('id', $exceptoId, PDO::PARAM_INT);
        }
        $statement->execute();

        return $statement->fetchColumn() !== false;
    }

    /** @param array<string, mixed> $data */
    public function crear(int $clienteId, array $data): int
    {
        if ($this->tabla === 'flotas') {
            $statement = $this->pdo->prepare(
                'INSERT INTO flotas (cliente_id, codigo, nombre, descripcion, activo, eliminado)
                 VALUES (:cliente_id, :codigo, :nombre, :descripcion, :activo, 0)'
            );
            $this->bindNullable($statement, 'descripcion', $data['descripcion']);
        } else {
            $statement = $this->pdo->prepare(
                'INSERT INTO sedes (cliente_id, codigo, nombre, direccion, activo, eliminado)
                 VALUES (:cliente_id, :codigo, :nombre, :direccion, :activo, 0)'
            );
            $this->bindNullable($statement, 'direccion', $data['direccion']);
        }
        $statement->bindValue('cliente_id', $clienteId, PDO::PARAM_INT);
        $this->bindNullable($statement, 'codigo', $data['codigo']);
        $statement->bindValue('nombre', $data['nombre']);
        $statement->bindValue('activo', $data['activo'] ? 1 : 0, PDO::PARAM_INT);
        $statement->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string, mixed> $data */
    public function actualizar(int $clienteId, int $id, array $data): void
    {
        if ($this->tabla === 'flotas') {
            $statement = $this->pdo->prepare(
                'UPDATE flotas
                 SET codigo = :codigo, nombre = :nombre, descripcion = :descripcion, activo = :activo
                 WHERE id = :id AND cliente_id = :cliente_id AND eliminado = 0'
            );
            $this->bindNullable($statement, 'descripcion', $data['descripcion']);
        } else {
            $statement = $this->pdo->prepare(
                'UPDATE sedes
                 SET codigo = :codigo, nombre = :nombre, direccion = :direccion, activo = :activo
                 WHERE id = :id AND cliente_id = :cliente_id AND eliminado = 0'
            );
            $this->bindNullable($statement, 'direccion', $data['direccion']);
        }
        $this->bindNullable($statement, 'codigo', $data['codigo']);
        $statement->bindValue('nombre', $data['nombre']);
        $statement->bindValue('activo', $data['activo'] ? 1 : 0, PDO::PARAM_INT);
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->bindValue('cliente_id', $clienteId, PDO::PARAM_INT);
        $statement->execute();
    }

    public function cambiarActivo(int $clienteId, int $id, bool $activo): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE ' . $this->tabla . ' SET activo = :activo WHERE id = :id AND cliente_id = :cliente_id AND eliminado = 0'
        );
        $statement->bindValue('activo', $activo ? 1 : 0, PDO::PARAM_INT);
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->bindValue('cliente_id', $clienteId, PDO::PARAM_INT);
        $statement->execute();
    }

    public function eliminar(int $clienteId, int $id): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE ' . $this->tabla . '
             SET eliminado = 1, activo = 0
             WHERE id = :id AND cliente_id = :cliente_id AND eliminado = 0'
        );
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->bindValue('cliente_id', $clienteId, PDO::PARAM_INT);
        $statement->execute();
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public function presentar(array $row): array
    {
        $base = [
            'id' => (int) $row['id'],
            'cliente_id' => (int) $row['cliente_id'],
            'codigo' => $row['codigo'],
            'nombre' => $row['nombre'],
            'activo' => (int) $row['activo'] === 1,
            'creado_en' => $row['creado_en'],
            'actualizado_en' => $row['actualizado_en'],
        ];
        if ($this->tabla === 'flotas') {
            $base['descripcion'] = $row['descripcion'];
        } else {
            $base['direccion'] = $row['direccion'];
        }

        return $base;
    }

    private function bindScope(\PDOStatement $statement, int $clienteId, ListCriteria $criteria): void
    {
        $statement->bindValue('cliente_id', $clienteId, PDO::PARAM_INT);
        if ($criteria->activo !== null) {
            $statement->bindValue('activo', $criteria->activo, PDO::PARAM_INT);
        }
    }

    private function bindNullable(\PDOStatement $statement, string $name, mixed $value): void
    {
        $statement->bindValue($name, $value, $value === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    }
}
