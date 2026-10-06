<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\ListCriteria;
use PDO;

final class ClienteResponsableRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function listar(int $clienteId, ListCriteria $criteria, string $hoy): array
    {
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM cliente_responsables WHERE cliente_id = :cliente_id');
        $count->bindValue('cliente_id', $clienteId, PDO::PARAM_INT);
        $count->execute();

        $sql = 'SELECT r.id, r.cliente_id, r.usuario_id, r.tipo_responsabilidad, r.fecha_inicio, r.fecha_fin,
                       r.principal, r.creado_en, u.nombres, u.apellidos, u.email
                FROM cliente_responsables r
                INNER JOIN usuarios u ON u.id = r.usuario_id
                WHERE r.cliente_id = :cliente_id
                ORDER BY ' . $criteria->sortExpression . ' ' . $criteria->direction . ', r.id ASC
                LIMIT ' . $criteria->limit . ' OFFSET ' . $criteria->offset;
        $statement = $this->pdo->prepare($sql);
        $statement->bindValue('cliente_id', $clienteId, PDO::PARAM_INT);
        $statement->execute();
        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            $rows[] = $this->presentar($row, $hoy);
        }

        return ['rows' => $rows, 'total' => (int) $count->fetchColumn()];
    }

    /** @return array<string, mixed>|null */
    public function find(int $clienteId, int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT r.id, r.cliente_id, r.usuario_id, r.tipo_responsabilidad, r.fecha_inicio, r.fecha_fin,
                    r.principal, r.creado_en, r.creado_por, u.nombres, u.apellidos, u.email
             FROM cliente_responsables r
             INNER JOIN usuarios u ON u.id = r.usuario_id
             WHERE r.id = :id AND r.cliente_id = :cliente_id
             LIMIT 1'
        );
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->bindValue('cliente_id', $clienteId, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @param array<string, mixed> $data */
    public function crear(int $clienteId, array $data, int $actor): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO cliente_responsables (
                cliente_id, usuario_id, tipo_responsabilidad, fecha_inicio, fecha_fin, principal, creado_por
             ) VALUES (
                :cliente_id, :usuario_id, :tipo, :fecha_inicio, :fecha_fin, :principal, :creado_por
             )'
        );
        $statement->bindValue('cliente_id', $clienteId, PDO::PARAM_INT);
        $statement->bindValue('usuario_id', $data['usuario_id'], PDO::PARAM_INT);
        $statement->bindValue('tipo', $data['tipo_responsabilidad']);
        $statement->bindValue('fecha_inicio', $data['fecha_inicio']);
        $statement->bindValue('fecha_fin', $data['fecha_fin'], $data['fecha_fin'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue('principal', $data['principal'] ? 1 : 0, PDO::PARAM_INT);
        $statement->bindValue('creado_por', $actor, PDO::PARAM_INT);
        $statement->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string, mixed> $data */
    public function actualizar(int $clienteId, int $id, array $data): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE cliente_responsables
             SET usuario_id = :usuario_id, tipo_responsabilidad = :tipo, fecha_inicio = :fecha_inicio,
                 fecha_fin = :fecha_fin, principal = :principal
             WHERE id = :id AND cliente_id = :cliente_id'
        );
        $statement->bindValue('usuario_id', $data['usuario_id'], PDO::PARAM_INT);
        $statement->bindValue('tipo', $data['tipo_responsabilidad']);
        $statement->bindValue('fecha_inicio', $data['fecha_inicio']);
        $statement->bindValue('fecha_fin', $data['fecha_fin'], $data['fecha_fin'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue('principal', $data['principal'] ? 1 : 0, PDO::PARAM_INT);
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->bindValue('cliente_id', $clienteId, PDO::PARAM_INT);
        $statement->execute();
    }

    public function cerrar(int $clienteId, int $id, string $fechaFin): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE cliente_responsables
             SET fecha_fin = :fecha_fin
             WHERE id = :id AND cliente_id = :cliente_id'
        );
        $statement->bindValue('fecha_fin', $fechaFin);
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->bindValue('cliente_id', $clienteId, PDO::PARAM_INT);
        $statement->execute();
    }

    public function eliminarFisico(int $clienteId, int $id): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM cliente_responsables WHERE id = :id AND cliente_id = :cliente_id'
        );
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->bindValue('cliente_id', $clienteId, PDO::PARAM_INT);
        $statement->execute();
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public function presentar(array $row, string $hoy): array
    {
        $fin = $row['fecha_fin'] !== null ? (string) $row['fecha_fin'] : null;
        $inicio = (string) $row['fecha_inicio'];
        $vigente = $inicio <= $hoy && ($fin === null || $fin >= $hoy);

        return [
            'id' => (int) $row['id'],
            'cliente_id' => (int) $row['cliente_id'],
            'usuario' => [
                'id' => (int) $row['usuario_id'],
                'nombres' => $row['nombres'],
                'apellidos' => $row['apellidos'],
                'email' => $row['email'],
            ],
            'tipo_responsabilidad' => $row['tipo_responsabilidad'],
            'fecha_inicio' => $inicio,
            'fecha_fin' => $fin,
            'principal' => (int) $row['principal'] === 1,
            'vigente' => $vigente,
        ];
    }
}
