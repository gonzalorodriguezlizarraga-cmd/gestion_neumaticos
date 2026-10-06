<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AuthenticationException;
use App\Repositories\ClienteScopeRepository;
use App\Repositories\RolRepository;
use App\Repositories\UsuarioRepository;
use App\Support\Jwt;
use App\Support\UserPresenter;

final class AuthService
{
    private const DUMMY_HASH = '$2y$10$YgyY2aPdyEUCZQPdgYKuOuvSMfnlBKY5xXNuTqYc57KmEoG5Iz1NS';

    public function __construct(
        private readonly UsuarioRepository $usuarios,
        private readonly RolRepository $roles,
        private readonly ClienteScopeRepository $clientes,
        private readonly AuthorizationService $authorization,
        private readonly Jwt $jwt,
    ) {
    }

    /** @return array<string, mixed> */
    public function login(string $email, string $password): array
    {
        $user = $this->usuarios->findByEmailForLogin($email);
        $hash = is_array($user) ? (string) $user['password_hash'] : self::DUMMY_HASH;
        $passwordMatches = password_verify($password, $hash);
        $operable = is_array($user)
            && $passwordMatches
            && (string) $user['estado'] === 'ACTIVO'
            && (int) $user['eliminado'] === 0;

        if (!$operable) {
            throw new AuthenticationException('AUTH_INVALID_CREDENTIALS', 'Credenciales inválidas.');
        }

        $userId = (int) $user['id'];
        $this->usuarios->touchUltimoAcceso($userId);
        $issued = $this->jwt->issue($userId);

        return [
            'token' => $issued['token'],
            'token_type' => 'Bearer',
            'expires_in' => $issued['expires_in'],
            'user' => $this->profile($userId),
        ];
    }

    /** @return array<string, mixed> */
    public function authenticateToken(string $token): array
    {
        $claims = $this->jwt->decode($token);
        $user = $this->usuarios->findById((int) $claims['sub']);
        if (
            $user === null
            || (string) $user['estado'] !== 'ACTIVO'
            || (int) $user['eliminado'] !== 0
        ) {
            throw new AuthenticationException('AUTH_TOKEN_INVALID', 'No autorizado.');
        }

        return $user;
    }

    /** @return array<string, mixed> */
    public function profile(int $userId): array
    {
        $user = $this->usuarios->findById($userId);
        if (
            $user === null
            || (string) $user['estado'] !== 'ACTIVO'
            || (int) $user['eliminado'] !== 0
        ) {
            throw new AuthenticationException('AUTH_TOKEN_INVALID', 'No autorizado.');
        }

        $roleCodes = $this->roles->codigosActivos($userId);
        $global = $this->authorization->isAdminGeneral($userId);
        $clientes = $global ? [] : $this->clientes->listarVigentes($userId);

        return UserPresenter::profile(
            $user,
            $roleCodes,
            $global ? 'GLOBAL' : 'CLIENTES',
            $clientes
        );
    }
}
