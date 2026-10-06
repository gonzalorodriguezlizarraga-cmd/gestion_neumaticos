<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiResponse;
use App\Http\Request;
use App\Services\MantenimientoService;
use App\Support\RouteIds;

final class MantenimientoController
{
    public function __construct(private readonly MantenimientoService $mantenimientos)
    {
    }

    public function tipos(Request $request): ApiResponse
    {
        $rows = $this->mantenimientos->tipos($request);

        return ApiResponse::ok($rows, count($rows));
    }

    public function estados(Request $request): ApiResponse
    {
        $rows = $this->mantenimientos->estados($request);

        return ApiResponse::ok($rows, count($rows));
    }

    public function motivos(Request $request): ApiResponse
    {
        $rows = $this->mantenimientos->motivos($request);

        return ApiResponse::ok($rows, count($rows));
    }

    public function index(Request $request): ApiResponse
    {
        $resultado = $this->mantenimientos->listar($request);

        return ApiResponse::ok($resultado['rows'], $resultado['total']);
    }

    public function store(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->mantenimientos->crear($request), 1, 201);
    }

    public function show(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->mantenimientos->detalle($request, RouteIds::get($request, 'id')));
    }

    public function enviar(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->mantenimientos->enviar($request, RouteIds::get($request, 'id')));
    }

    public function iniciar(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->mantenimientos->iniciar($request, RouteIds::get($request, 'id')));
    }

    public function finalizar(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->mantenimientos->finalizar($request, RouteIds::get($request, 'id')));
    }

    public function cancelar(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->mantenimientos->cancelar($request, RouteIds::get($request, 'id')));
    }

    public function storeArchivo(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->mantenimientos->subirArchivo($request, RouteIds::get($request, 'id')), 1, 201);
    }

    public function deNeumatico(Request $request): ApiResponse
    {
        $resultado = $this->mantenimientos->listar($request, RouteIds::get($request, 'id'));

        return ApiResponse::ok($resultado['rows'], $resultado['total']);
    }

    public function descartar(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->mantenimientos->descartar($request, RouteIds::get($request, 'id')), 1, 201);
    }

    public function descarte(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->mantenimientos->descarteDe($request, RouteIds::get($request, 'id')));
    }
}
