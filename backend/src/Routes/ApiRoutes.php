<?php

declare(strict_types=1);

namespace App\Routes;

use App\Controllers\AuthController;
use App\Http\Request;
use App\Http\Router;

final class ApiRoutes
{
    public static function register(Router $router, AuthController $auth): void
    {
        $router->post('/api/v1/auth/login', static fn (Request $request): \App\Http\ApiResponse => $auth->login($request));
        $router->get('/api/v1/auth/me', static fn (Request $request): \App\Http\ApiResponse => $auth->me($request), true);
        $router->post('/api/v1/auth/logout', static fn (): \App\Http\ApiResponse => $auth->logout(), true);
    }
}
