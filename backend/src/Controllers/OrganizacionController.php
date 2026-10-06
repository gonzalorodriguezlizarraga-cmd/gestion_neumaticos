<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiResponse;
use App\Http\Request;
use App\Services\OrganizacionService;
use App\Support\RouteIds;

final class OrganizacionController
{
    public function __construct(private readonly OrganizacionService $servicio)
    {
    }

    public function index(Request $request): ApiResponse
    {
        $result = $this->servicio->listar($request->userId(), RouteIds::get($request, 'clienteId'), $request->queryParams());

        return ApiResponse::ok($result['rows'], $result['total']);
    }

    public function store(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->servicio->crear(
            $request->userId(),
            RouteIds::get($request, 'clienteId'),
            $request->json(),
            $request,
        ), 1, 201);
    }

    public function update(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->servicio->actualizar(
            $request->userId(),
            RouteIds::get($request, 'clienteId'),
            RouteIds::get($request, 'id'),
            $request->json(),
            $request,
        ));
    }

    public function estado(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->servicio->cambiarActivo(
            $request->userId(),
            RouteIds::get($request, 'clienteId'),
            RouteIds::get($request, 'id'),
            $request->json(),
            $request,
        ));
    }

    public function destroy(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->servicio->eliminar(
            $request->userId(),
            RouteIds::get($request, 'clienteId'),
            RouteIds::get($request, 'id'),
            $request,
        ));
    }
}
