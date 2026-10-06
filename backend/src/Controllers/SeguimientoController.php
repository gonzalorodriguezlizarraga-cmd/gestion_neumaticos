<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiResponse;
use App\Http\Request;
use App\Services\SeguimientoService;

final class SeguimientoController
{
    public function __construct(private readonly SeguimientoService $seguimientos)
    {
    }

    public function index(Request $request): ApiResponse
    {
        $resultado = $this->seguimientos->listar($request);

        return ApiResponse::ok($resultado['rows'], $resultado['total']);
    }

    public function store(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->seguimientos->crear($request), 1, 201);
    }
}
