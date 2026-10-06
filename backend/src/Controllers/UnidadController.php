<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiResponse;
use App\Http\Request;
use App\Services\UnidadService;
use App\Support\RouteIds;

final class UnidadController
{
    public function __construct(private readonly UnidadService $unidades)
    {
    }

    public function index(Request $request): ApiResponse
    {
        $result = $this->unidades->listar($request->userId(), $request->queryParams());

        return ApiResponse::ok($result['rows'], $result['total']);
    }

    public function show(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->unidades->obtener($request->userId(), RouteIds::get($request, 'id')));
    }

    public function store(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->unidades->crear($request->userId(), $request->json(), $request), 1, 201);
    }

    public function update(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->unidades->actualizar($request->userId(), RouteIds::get($request, 'id'), $request->json(), $request));
    }

    public function estado(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->unidades->cambiarEstado($request->userId(), RouteIds::get($request, 'id'), $request->json(), $request));
    }

    public function destroy(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->unidades->eliminar($request->userId(), RouteIds::get($request, 'id'), $request));
    }
}
