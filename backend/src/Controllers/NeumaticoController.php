<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiResponse;
use App\Http\Request;
use App\Services\NeumaticoService;
use App\Support\RouteIds;

final class NeumaticoController
{
    public function __construct(private readonly NeumaticoService $neumaticos)
    {
    }

    public function index(Request $request): ApiResponse
    {
        $result = $this->neumaticos->listar($request->userId(), $request->queryParams());

        return ApiResponse::ok($result['rows'], $result['total']);
    }

    public function show(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->neumaticos->obtener($request->userId(), RouteIds::get($request, 'id')));
    }

    public function historial(Request $request): ApiResponse
    {
        $result = $this->neumaticos->historial($request->userId(), RouteIds::get($request, 'id'));

        return ApiResponse::ok($result['rows'], $result['total']);
    }

    public function vidas(Request $request): ApiResponse
    {
        $result = $this->neumaticos->vidas($request->userId(), RouteIds::get($request, 'id'));

        return ApiResponse::ok($result['rows'], $result['total']);
    }

    public function store(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->neumaticos->crear($request->userId(), $request->json(), $request), 1, 201);
    }

    public function update(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->neumaticos->actualizar($request->userId(), RouteIds::get($request, 'id'), $request->json(), $request));
    }

    public function destroy(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->neumaticos->eliminar($request->userId(), RouteIds::get($request, 'id'), $request));
    }
}
