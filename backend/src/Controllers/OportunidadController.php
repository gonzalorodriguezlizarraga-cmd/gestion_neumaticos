<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiResponse;
use App\Http\Request;
use App\Services\OportunidadService;
use App\Support\RouteIds;

final class OportunidadController
{
    public function __construct(private readonly OportunidadService $oportunidades)
    {
    }

    public function estados(Request $request): ApiResponse
    {
        $rows = $this->oportunidades->estados($request);

        return ApiResponse::ok($rows, count($rows));
    }

    public function clientes(Request $request): ApiResponse
    {
        $resultado = $this->oportunidades->clientes($request);

        return ApiResponse::ok($resultado['rows'], $resultado['total']);
    }

    public function responsables(Request $request): ApiResponse
    {
        $rows = $this->oportunidades->responsables($request, RouteIds::get($request, 'id'));

        return ApiResponse::ok($rows, count($rows));
    }

    public function resumen(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->oportunidades->resumen($request, RouteIds::get($request, 'id')));
    }

    public function index(Request $request): ApiResponse
    {
        $resultado = $this->oportunidades->listar($request);

        return ApiResponse::ok($resultado['rows'], $resultado['total']);
    }

    public function show(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->oportunidades->detalle($request, RouteIds::get($request, 'id')));
    }

    public function historial(Request $request): ApiResponse
    {
        $rows = $this->oportunidades->historial($request, RouteIds::get($request, 'id'));

        return ApiResponse::ok($rows, count($rows));
    }

    public function seguimientos(Request $request): ApiResponse
    {
        $rows = $this->oportunidades->seguimientos($request, RouteIds::get($request, 'id'));

        return ApiResponse::ok($rows, count($rows));
    }

    public function store(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->oportunidades->crear($request), 1, 201);
    }

    public function desdeAlerta(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->oportunidades->desdeAlerta($request, RouteIds::get($request, 'id')), 1, 201);
    }

    public function update(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->oportunidades->editar($request, RouteIds::get($request, 'id')));
    }

    public function iniciarSeguimiento(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->oportunidades->iniciarSeguimiento($request, RouteIds::get($request, 'id')));
    }

    public function marcarCotizada(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->oportunidades->marcarCotizada($request, RouteIds::get($request, 'id')));
    }

    public function ganar(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->oportunidades->ganar($request, RouteIds::get($request, 'id')));
    }

    public function perder(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->oportunidades->perder($request, RouteIds::get($request, 'id')));
    }

    public function cancelar(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->oportunidades->cancelar($request, RouteIds::get($request, 'id')));
    }
}
