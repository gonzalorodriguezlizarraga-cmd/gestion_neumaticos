<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiResponse;
use App\Http\Request;
use App\Services\AuthService;
use App\Validators\LoginValidator;

final class AuthController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly LoginValidator $validator,
    ) {
    }

    public function login(Request $request): ApiResponse
    {
        $credentials = $this->validator->validate($request->json());
        $session = $this->auth->login($credentials['email'], $credentials['password']);

        return ApiResponse::ok($session);
    }

    public function me(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->auth->profile($request->userId()));
    }

    public function logout(): ApiResponse
    {
        return ApiResponse::ok([
            'revoked' => false,
            'message' => 'No hay revocación de token en el servidor. Elimine la credencial en el cliente.',
        ]);
    }
}
