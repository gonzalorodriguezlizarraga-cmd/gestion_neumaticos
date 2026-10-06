<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class AuditoriaRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string, mixed>|null $antes @param array<string, mixed>|null $despues */
    public function registrar(
        ?int $clienteId,
        int $usuarioId,
        string $entidad,
        int $entidadId,
        string $accion,
        ?array $antes,
        ?array $despues,
        ?string $ip,
        ?string $userAgent,
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO auditoria (
                cliente_id, usuario_id, entidad, entidad_id, accion,
                datos_anteriores, datos_nuevos, ip, user_agent
             ) VALUES (
                :cliente_id, :usuario_id, :entidad, :entidad_id, :accion,
                :datos_anteriores, :datos_nuevos, :ip, :user_agent
             )'
        );
        $statement->bindValue('cliente_id', $clienteId, $clienteId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->bindValue('usuario_id', $usuarioId, PDO::PARAM_INT);
        $statement->bindValue('entidad', $entidad);
        $statement->bindValue('entidad_id', $entidadId, PDO::PARAM_INT);
        $statement->bindValue('accion', $accion);
        $this->bindJson($statement, 'datos_anteriores', $antes);
        $this->bindJson($statement, 'datos_nuevos', $despues);
        $statement->bindValue('ip', $ip === null || $ip === '' ? null : substr($ip, 0, 64), $ip === null || $ip === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $agent = $userAgent === null || $userAgent === '' ? null : substr($userAgent, 0, 500);
        $statement->bindValue('user_agent', $agent, $agent === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->execute();
    }

    /** @param array<string, mixed>|null $payload */
    private function bindJson(\PDOStatement $statement, string $name, ?array $payload): void
    {
        if ($payload === null) {
            $statement->bindValue($name, null, PDO::PARAM_NULL);
            return;
        }
        $statement->bindValue($name, json_encode($this->sanitize($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function sanitize(array $payload): array
    {
        $clean = [];
        foreach ($payload as $key => $value) {
            if (!is_string($key) || in_array($key, ['password', 'password_hash'], true)) {
                continue;
            }
            if (is_array($value)) {
                $clean[$key] = $this->sanitize($value);
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }
}
