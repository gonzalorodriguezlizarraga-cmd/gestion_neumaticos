<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiResponse;
use App\Http\Request;
use App\Services\AlertaService;
use App\Support\RouteIds;

final class AlertaController
{
    public function __construct(private readonly AlertaService $alertas)
    {
    }

    public function tipos(Request $request): ApiResponse
    {
        $rows = $this->alertas->tipos($request);

        return ApiResponse::ok($rows, count($rows));
    }

    public function estados(Request $request): ApiResponse
    {
        $rows = $this->alertas->estados($request);

        return ApiResponse::ok($rows, count($rows));
    }

    public function index(Request $request): ApiResponse
    {
        $resultado = $this->alertas->listar($request);

        return ApiResponse::ok($resultado['rows'], $resultado['total']);
    }

    public function show(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->alertas->detalle($request, RouteIds::get($request, 'id')));
    }

    public function historial(Request $request): ApiResponse
    {
        $rows = $this->alertas->historial($request, RouteIds::get($request, 'id'));

        return ApiResponse::ok($rows, count($rows));
    }

    public function store(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->alertas->crear($request), 1, 201);
    }

    public function tomarAtencion(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->alertas->tomarAtencion($request, RouteIds::get($request, 'id')));
    }

    public function atender(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->alertas->atender($request, RouteIds::get($request, 'id')));
    }

    public function descartar(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->alertas->descartar($request, RouteIds::get($request, 'id')));
    }
}
