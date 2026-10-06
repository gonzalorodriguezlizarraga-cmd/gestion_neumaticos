<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiResponse;
use App\Http\Request;
use App\Services\ClienteService;
use App\Support\RouteIds;

final class ClienteController
{
    public function __construct(private readonly ClienteService $clientes)
    {
    }

    public function index(Request $request): ApiResponse
    {
        $result = $this->clientes->listar($request->userId(), $request->queryParams());

        return ApiResponse::ok($result['rows'], $result['total']);
    }

    public function show(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->clientes->obtener($request->userId(), RouteIds::get($request, 'id')));
    }

    public function store(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->clientes->crear($request->userId(), $request->json(), $request), 1, 201);
    }

    public function update(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->clientes->actualizar(
            $request->userId(),
            RouteIds::get($request, 'id'),
            $request->json(),
            $request,
        ));
    }

    public function estado(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->clientes->cambiarEstado(
            $request->userId(),
            RouteIds::get($request, 'id'),
            $request->json(),
            $request,
        ));
    }

    public function destroy(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->clientes->eliminar($request->userId(), RouteIds::get($request, 'id'), $request));
    }
}
