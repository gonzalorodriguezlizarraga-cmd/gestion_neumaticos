<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiResponse;
use App\Http\Request;
use App\Services\TipoUnidadService;
use App\Support\RouteIds;

final class TipoUnidadController
{
    public function __construct(private readonly TipoUnidadService $tipos)
    {
    }

    public function index(Request $request): ApiResponse
    {
        $result = $this->tipos->listar($request->userId(), $request->queryParams());

        return ApiResponse::ok($result['rows'], $result['total']);
    }

    public function show(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->tipos->obtener($request->userId(), RouteIds::get($request, 'id')));
    }

    public function store(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->tipos->crear($request->userId(), $request->json(), $request), 1, 201);
    }

    public function update(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->tipos->actualizar($request->userId(), RouteIds::get($request, 'id'), $request->json(), $request));
    }

    public function estado(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->tipos->cambiarActivo($request->userId(), RouteIds::get($request, 'id'), $request->json(), $request));
    }
}
