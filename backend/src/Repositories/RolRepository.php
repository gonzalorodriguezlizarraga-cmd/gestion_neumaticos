<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class RolRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<string> */
    public function codigosActivos(int $usuarioId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT DISTINCT r.codigo
             FROM usuario_roles ur
             INNER JOIN roles r ON r.id = ur.rol_id AND r.activo = 1
             WHERE ur.usuario_id = :usuario_id
             ORDER BY r.codigo ASC'
        );
        $statement->bindValue('usuario_id', $usuarioId, PDO::PARAM_INT);
        $statement->execute();
        $codes = $statement->fetchAll(PDO::FETCH_COLUMN);

        return array_values(array_map(static fn (mixed $code): string => (string) $code, $codes));
    }
}
