<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiResponse;
use App\Http\Request;
use App\Services\MarcaNeumaticoService;
use App\Support\RouteIds;

final class MarcaNeumaticoController
{
    public function __construct(private readonly MarcaNeumaticoService $marcas)
    {
    }

    public function index(Request $request): ApiResponse
    {
        $result = $this->marcas->listar($request->userId(), $request->queryParams());

        return ApiResponse::ok($result['rows'], $result['total']);
    }

    public function show(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->marcas->obtener($request->userId(), RouteIds::get($request, 'id')));
    }

    public function store(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->marcas->crear($request->userId(), $request->json(), $request), 1, 201);
    }

    public function update(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->marcas->actualizar($request->userId(), RouteIds::get($request, 'id'), $request->json(), $request));
    }

    public function estado(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->marcas->cambiarActivo($request->userId(), RouteIds::get($request, 'id'), $request->json(), $request));
    }
}
