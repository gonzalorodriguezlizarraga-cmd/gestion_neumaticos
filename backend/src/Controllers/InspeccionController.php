<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiResponse;
use App\Http\Request;
use App\Services\InspeccionService;
use App\Support\RouteIds;

final class InspeccionController
{
    public function __construct(private readonly InspeccionService $inspecciones)
    {
    }

    public function index(Request $request): ApiResponse
    {
        $resultado = $this->inspecciones->listar($request);

        return ApiResponse::ok($resultado['rows'], $resultado['total']);
    }

    public function store(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->inspecciones->crear($request), 1, 201);
    }

    public function show(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->inspecciones->detalle($request, RouteIds::get($request, 'id')));
    }

    public function update(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->inspecciones->actualizar($request, RouteIds::get($request, 'id')));
    }

    public function finalizar(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->inspecciones->finalizar($request, RouteIds::get($request, 'id')));
    }

    public function storeDetalle(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->inspecciones->agregarDetalle($request, RouteIds::get($request, 'id')), 1, 201);
    }

    public function updateDetalle(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->inspecciones->actualizarDetalle(
            $request,
            RouteIds::get($request, 'id'),
            RouteIds::get($request, 'detalleId'),
        ));
    }

    public function storeDano(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->inspecciones->agregarDano(
            $request,
            RouteIds::get($request, 'id'),
            RouteIds::get($request, 'detalleId'),
        ), 1, 201);
    }

    public function updateDano(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->inspecciones->actualizarDano(
            $request,
            RouteIds::get($request, 'id'),
            RouteIds::get($request, 'detalleId'),
            RouteIds::get($request, 'tipoId'),
        ));
    }

    public function destroyDano(Request $request): ApiResponse
    {
        $this->inspecciones->quitarDano(
            $request,
            RouteIds::get($request, 'id'),
            RouteIds::get($request, 'detalleId'),
            RouteIds::get($request, 'tipoId'),
        );

        return ApiResponse::noContent();
    }

    public function tiposDano(Request $request): ApiResponse
    {
        $filas = $this->inspecciones->tiposDano($request);

        return ApiResponse::ok($filas, count($filas));
    }

    public function storeArchivo(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->inspecciones->subirArchivo($request, RouteIds::get($request, 'id'), null), 1, 201);
    }

    public function storeArchivoDetalle(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->inspecciones->subirArchivo(
            $request,
            RouteIds::get($request, 'id'),
            RouteIds::get($request, 'detalleId'),
        ), 1, 201);
    }

    public function download(Request $request): ApiResponse
    {
        $archivo = $this->inspecciones->descargar($request, RouteIds::get($request, 'id'));

        return ApiResponse::archivo($archivo['contenido'], $archivo['mime'], $archivo['nombre']);
    }

    public function destroyArchivo(Request $request): ApiResponse
    {
        $this->inspecciones->eliminarArchivo($request, RouteIds::get($request, 'id'));

        return ApiResponse::noContent();
    }

    public function deNeumatico(Request $request): ApiResponse
    {
        $resultado = $this->inspecciones->deNeumatico($request, RouteIds::get($request, 'id'));

        return ApiResponse::ok($resultado['rows'], $resultado['total']);
    }

    public function deUnidad(Request $request): ApiResponse
    {
        $resultado = $this->inspecciones->deUnidad($request, RouteIds::get($request, 'id'));

        return ApiResponse::ok($resultado['rows'], $resultado['total']);
    }
}
