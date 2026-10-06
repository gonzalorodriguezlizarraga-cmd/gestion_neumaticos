<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiResponse;
use App\Http\Request;
use App\Services\OperacionNeumaticoService;
use App\Support\RouteIds;

final class OperacionNeumaticoController
{
    public function __construct(private readonly OperacionNeumaticoService $operaciones)
    {
    }

    public function montar(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->operaciones->montar($request->userId(), $request->json(), $request), 1, 201);
    }

    public function desmontar(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->operaciones->desmontar(
            $request->userId(),
            RouteIds::get($request, 'id'),
            $request->json(),
            $request,
        ));
    }

    public function rotar(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->operaciones->rotar($request->userId(), $request->json(), $request), 1, 201);
    }

    public function transferir(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->operaciones->transferir($request->userId(), $request->json(), $request), 1, 201);
    }

    public function movimientos(Request $request): ApiResponse
    {
        $result = $this->operaciones->listarMovimientos($request->userId(), $request->queryParams());

        return ApiResponse::ok($result['rows'], $result['total']);
    }

    public function movimientosNeumatico(Request $request): ApiResponse
    {
        $result = $this->operaciones->movimientosNeumatico($request->userId(), RouteIds::get($request, 'id'), $request->queryParams());

        return ApiResponse::ok($result['rows'], $result['total']);
    }

    public function movimientosUnidad(Request $request): ApiResponse
    {
        $result = $this->operaciones->movimientosUnidad($request->userId(), RouteIds::get($request, 'id'), $request->queryParams());

        return ApiResponse::ok($result['rows'], $result['total']);
    }

    public function montajesActivos(Request $request): ApiResponse
    {
        $result = $this->operaciones->montajesActivos($request->userId(), RouteIds::get($request, 'id'));

        return ApiResponse::ok($result['rows'], $result['total']);
    }

    public function montajesNeumatico(Request $request): ApiResponse
    {
        $result = $this->operaciones->montajesNeumatico($request->userId(), RouteIds::get($request, 'id'));

        return ApiResponse::ok($result['rows'], $result['total']);
    }
}
