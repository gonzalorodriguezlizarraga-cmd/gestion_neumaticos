<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiResponse;
use App\Http\Request;
use App\Services\ConfiguracionUnidadService;
use App\Support\RouteIds;

final class ConfiguracionUnidadController
{
    public function __construct(private readonly ConfiguracionUnidadService $configuraciones)
    {
    }

    public function index(Request $request): ApiResponse
    {
        $result = $this->configuraciones->listar($request->userId(), $request->queryParams());

        return ApiResponse::ok($result['rows'], $result['total']);
    }

    public function show(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->configuraciones->obtener($request->userId(), RouteIds::get($request, 'id')));
    }

    public function store(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->configuraciones->crear($request->userId(), $request->json(), $request), 1, 201);
    }

    public function update(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->configuraciones->actualizar($request->userId(), RouteIds::get($request, 'id'), $request->json(), $request));
    }

    public function duplicar(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->configuraciones->duplicar($request->userId(), RouteIds::get($request, 'id'), $request->json(), $request), 1, 201);
    }

    public function estado(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->configuraciones->cambiarActivo($request->userId(), RouteIds::get($request, 'id'), $request->json(), $request));
    }
}
