<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiResponse;
use App\Http\Request;
use App\Services\ClienteContactoService;
use App\Support\RouteIds;

final class ClienteContactoController
{
    public function __construct(private readonly ClienteContactoService $contactos)
    {
    }

    public function index(Request $request): ApiResponse
    {
        $result = $this->contactos->listar($request->userId(), RouteIds::get($request, 'clienteId'), $request->queryParams());

        return ApiResponse::ok($result['rows'], $result['total']);
    }

    public function store(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->contactos->crear(
            $request->userId(),
            RouteIds::get($request, 'clienteId'),
            $request->json(),
            $request,
        ), 1, 201);
    }

    public function update(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->contactos->actualizar(
            $request->userId(),
            RouteIds::get($request, 'clienteId'),
            RouteIds::get($request, 'id'),
            $request->json(),
            $request,
        ));
    }

    public function estado(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->contactos->cambiarActivo(
            $request->userId(),
            RouteIds::get($request, 'clienteId'),
            RouteIds::get($request, 'id'),
            $request->json(),
            $request,
        ));
    }
}
