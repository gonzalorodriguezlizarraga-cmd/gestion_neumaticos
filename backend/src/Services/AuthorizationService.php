<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AuthorizationException;
use App\Exceptions\NotFoundException;
use App\Repositories\ClienteScopeRepository;
use App\Repositories\RolRepository;

final class AuthorizationService
{
    public function __construct(
        private readonly RolRepository $roles,
        private readonly ClienteScopeRepository $clientes,
    ) {
    }

    public function hasRole(int $usuarioId, string ...$codigos): bool
    {
        if ($codigos === []) {
            return false;
        }

        $activos = $this->roles->codigosActivos($usuarioId);

        return array_intersect($codigos, $activos) !== [];
    }

    public function requireRole(int $usuarioId, string ...$codigos): void
    {
        if (!$this->hasRole($usuarioId, ...$codigos)) {
            throw new AuthorizationException('No tiene permiso para realizar esta acción.');
        }
    }

    public function isAdminGeneral(int $usuarioId): bool
    {
        return $this->hasRole($usuarioId, 'ADMIN_GENERAL');
    }

    public function canAccessClient(int $usuarioId, int $clienteId): bool
    {
        if ($clienteId <= 0) {
            return false;
        }
        if ($this->isAdminGeneral($usuarioId)) {
            return $this->clientes->existeOperable($clienteId);
        }

        return $this->clientes->asignacionVigente($usuarioId, $clienteId);
    }

    public function requireClientAccess(int $usuarioId, int $clienteId): void
    {
        if ($clienteId <= 0) {
            throw new AuthorizationException(
                'No tiene acceso a este cliente.',
                'CLIENT_SCOPE_FORBIDDEN'
            );
        }

        if ($this->isAdminGeneral($usuarioId)) {
            if (!$this->clientes->existeOperable($clienteId)) {
                throw new NotFoundException('Cliente no encontrado.');
            }

            return;
        }

        if (!$this->clientes->asignacionVigente($usuarioId, $clienteId)) {
            throw new AuthorizationException(
                'No tiene acceso a este cliente.',
                'CLIENT_SCOPE_FORBIDDEN'
            );
        }
    }
}
