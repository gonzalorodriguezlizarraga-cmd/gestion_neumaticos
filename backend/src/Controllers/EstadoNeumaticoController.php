<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiResponse;
use App\Http\Request;
use App\Services\EstadoNeumaticoService;

final class EstadoNeumaticoController
{
    public function __construct(private readonly EstadoNeumaticoService $estados)
    {
    }

    public function index(Request $request): ApiResponse
    {
        $result = $this->estados->listar($request->userId(), $request->queryParams());

        return ApiResponse::ok($result['rows'], $result['total']);
    }
}
