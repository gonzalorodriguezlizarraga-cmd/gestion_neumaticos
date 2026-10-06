<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AuthorizationException;
use App\Exceptions\ConflictException;

final class ClientePolicy
{
    /** @var list<string> */
    public const LECTURA = [
        'ADMIN_GENERAL',
        'GESTOR_NEUMATICOS',
        'TECNICO_INSPECCION',
        'VENDEDOR',
        'ADMIN_CLIENTE',
        'CONSULTA_EJECUTIVA',
    ];

    /** @var list<string> */
    public const OPERACION = [
        'ADMIN_GENERAL',
        'GESTOR_NEUMATICOS',
    ];

    /** @var list<string> */
    public const INSPECCION = [
        'ADMIN_GENERAL',
        'GESTOR_NEUMATICOS',
        'TECNICO_INSPECCION',
    ];

    public function __construct(private readonly AuthorizationService $authorization)
    {
    }

    public function esAdmin(int $usuarioId): bool
    {
        return $this->authorization->isAdminGeneral($usuarioId);
    }

    public function exigirLectura(int $usuarioId): void
    {
        $this->authorization->requireRole($usuarioId, ...self::LECTURA);
    }

    public function exigirCliente(int $usuarioId, int $clienteId): void
    {
        $this->exigirLectura($usuarioId);
        $this->authorization->requireClientAccess($usuarioId, $clienteId);
    }

    public function exigirCrear(int $usuarioId): void
    {
        if (!$this->esAdmin($usuarioId)) {
            throw new AuthorizationException('No tiene permiso para crear clientes.');
        }
    }

    public function exigirEstado(int $usuarioId, int $clienteId): void
    {
        $this->authorization->requireClientAccess($usuarioId, $clienteId);
        if (!$this->esAdmin($usuarioId)) {
            throw new AuthorizationException('No tiene permiso para cambiar el estado del cliente.');
        }
    }

    public function exigirEliminar(int $usuarioId, int $clienteId): void
    {
        $this->authorization->requireClientAccess($usuarioId, $clienteId);
        if (!$this->esAdmin($usuarioId)) {
            throw new AuthorizationException('No tiene permiso para eliminar clientes.');
        }
    }

    public function exigirEdicion(int $usuarioId, int $clienteId): void
    {
        $this->authorization->requireClientAccess($usuarioId, $clienteId);
        $this->authorization->requireRole($usuarioId, ...self::OPERACION);
    }

    public function exigirContactos(int $usuarioId, int $clienteId): void
    {
        $this->exigirEdicion($usuarioId, $clienteId);
    }

    public function exigirResponsables(int $usuarioId, int $clienteId): void
    {
        $this->authorization->requireClientAccess($usuarioId, $clienteId);
        if (!$this->esAdmin($usuarioId)) {
            throw new AuthorizationException('No tiene permiso para administrar responsables.');
        }
    }

    public function exigirEstructura(int $usuarioId, int $clienteId, string $estado, bool $alta): void
    {
        $this->exigirEdicion($usuarioId, $clienteId);
        if ($alta && $estado !== 'ACTIVO') {
            throw new ConflictException('El cliente no admite nueva estructura organizativa en su estado actual.');
        }
    }

    public function exigirNuevaAsignacion(string $estadoCliente, string $tipo): void
    {
        if ($estadoCliente === 'INACTIVO') {
            throw new ConflictException('Un cliente inactivo no admite nuevas asignaciones.');
        }
        if ($estadoCliente === 'POTENCIAL' && $tipo === 'TECNICO') {
            throw new ConflictException('Un cliente potencial solo admite responsables comerciales.');
        }
    }
}
