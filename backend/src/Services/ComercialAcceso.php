<?php

declare(strict_types=1);

namespace App\Services;

final class ComercialAcceso
{
    /** @var list<string> */
    public const LECTURA = ['ADMIN_GENERAL', 'VENDEDOR', 'GESTOR_NEUMATICOS'];

    /** @var list<string> */
    public const ESCRITURA = ['ADMIN_GENERAL', 'VENDEDOR'];

    public function __construct(private readonly AuthorizationService $authorization)
    {
    }

    public function exigirLectura(int $usuarioId): void
    {
        $this->authorization->requireRole($usuarioId, ...self::LECTURA);
    }

    public function exigirEscritura(int $usuarioId): void
    {
        $this->authorization->requireRole($usuarioId, ...self::ESCRITURA);
    }

    public function esAdmin(int $usuarioId): bool
    {
        return $this->authorization->isAdminGeneral($usuarioId);
    }

    public function esVendedor(int $usuarioId): bool
    {
        return $this->authorization->hasRole($usuarioId, 'VENDEDOR');
    }

    public function esGestor(int $usuarioId): bool
    {
        return $this->authorization->hasRole($usuarioId, 'GESTOR_NEUMATICOS');
    }
}
