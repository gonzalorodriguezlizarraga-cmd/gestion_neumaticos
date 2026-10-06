<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Exceptions\AuthenticationException;
use App\Http\Request;
use App\Services\AuthService;

final class AuthenticationMiddleware
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    public function handle(Request $request): void
    {
        $header = $request->header('authorization');
        if ($header === null || trim($header) === '') {
            throw new AuthenticationException('AUTH_TOKEN_MISSING', 'Se requiere autenticación.');
        }
        if (!preg_match('/^Bearer\s+(\S+)$/i', trim($header), $matches)) {
            throw new AuthenticationException('AUTH_TOKEN_INVALID', 'El token no es válido.');
        }

        $request->setUser($this->auth->authenticateToken($matches[1]));
    }
}
