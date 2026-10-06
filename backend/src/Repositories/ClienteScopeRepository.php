<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\AsignacionVigente;
use PDO;

final class ClienteScopeRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array{id:int,razon_social:string,nombre_comercial:?string}> */
    public function listarVigentes(int $usuarioId): array
    {
        $vigente = AsignacionVigente::sql('uc');
        $statement = $this->pdo->prepare(
            'SELECT DISTINCT c.id, c.razon_social, c.nombre_comercial
             FROM usuario_clientes uc
             INNER JOIN clientes c ON c.id = uc.cliente_id AND c.eliminado = 0
             WHERE uc.usuario_id = :usuario_id
               AND ' . $vigente . '
             ORDER BY c.razon_social ASC, c.id ASC'
        );
        $statement->bindValue('usuario_id', $usuarioId, PDO::PARAM_INT);
        $statement->execute();

        $clients = [];
        foreach ($statement->fetchAll() as $row) {
            $clients[] = [
                'id' => (int) $row['id'],
                'razon_social' => (string) $row['razon_social'],
                'nombre_comercial' => $row['nombre_comercial'] !== null ? (string) $row['nombre_comercial'] : null,
            ];
        }

        return $clients;
    }

    public function asignacionVigente(int $usuarioId, int $clienteId): bool
    {
        $vigente = AsignacionVigente::sql('uc');
        $statement = $this->pdo->prepare(
            'SELECT EXISTS(
                SELECT 1
                FROM usuario_clientes uc
                INNER JOIN clientes c ON c.id = uc.cliente_id AND c.eliminado = 0
                WHERE uc.usuario_id = :usuario_id
                  AND uc.cliente_id = :cliente_id
                  AND ' . $vigente . '
             )'
        );
        $statement->bindValue('usuario_id', $usuarioId, PDO::PARAM_INT);
        $statement->bindValue('cliente_id', $clienteId, PDO::PARAM_INT);
        $statement->execute();

        return (int) $statement->fetchColumn() === 1;
    }

    public function existeOperable(int $clienteId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT EXISTS(
                SELECT 1
                FROM clientes
                WHERE id = :cliente_id
                  AND eliminado = 0
             )'
        );
        $statement->bindValue('cliente_id', $clienteId, PDO::PARAM_INT);
        $statement->execute();

        return (int) $statement->fetchColumn() === 1;
    }
}
