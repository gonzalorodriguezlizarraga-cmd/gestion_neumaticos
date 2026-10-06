<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiResponse;
use App\Http\Request;
use App\Services\CotizacionService;
use App\Support\RouteIds;

final class CotizacionController
{
    public function __construct(private readonly CotizacionService $cotizaciones)
    {
    }

    public function index(Request $request): ApiResponse
    {
        $resultado = $this->cotizaciones->listar($request);

        return ApiResponse::ok($resultado['rows'], $resultado['total']);
    }

    public function show(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->cotizaciones->detalle($request, RouteIds::get($request, 'id')));
    }

    public function store(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->cotizaciones->crear($request), 1, 201);
    }

    public function update(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->cotizaciones->editar($request, RouteIds::get($request, 'id')));
    }

    public function enviar(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->cotizaciones->enviar($request, RouteIds::get($request, 'id')));
    }

    public function aceptar(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->cotizaciones->aceptar($request, RouteIds::get($request, 'id')));
    }

    public function rechazar(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->cotizaciones->rechazar($request, RouteIds::get($request, 'id')));
    }

    public function anular(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->cotizaciones->anular($request, RouteIds::get($request, 'id')));
    }
}
