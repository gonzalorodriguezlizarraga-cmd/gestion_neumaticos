<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class UsuarioRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null */
    public function findByEmailForLogin(string $email): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, nombres, apellidos, email, password_hash, estado, eliminado
             FROM usuarios
             WHERE email = :email
             LIMIT 1'
        );
        $statement->execute(['email' => $email]);
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, nombres, apellidos, email, estado, eliminado
             FROM usuarios
             WHERE id = :id
             LIMIT 1'
        );
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function touchUltimoAcceso(int $id): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE usuarios
             SET ultimo_acceso = NOW()
             WHERE id = :id
               AND estado = :estado
               AND eliminado = 0'
        );
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->bindValue('estado', 'ACTIVO');
        $statement->execute();
    }
}
