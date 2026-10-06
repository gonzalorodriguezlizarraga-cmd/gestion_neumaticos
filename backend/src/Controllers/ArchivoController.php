<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiResponse;
use App\Http\Request;
use App\Services\InspeccionService;
use App\Services\MantenimientoService;
use App\Support\RouteIds;

final class ArchivoController
{
    public function __construct(
        private readonly InspeccionService $inspecciones,
        private readonly MantenimientoService $mantenimientos,
    ) {
    }

    public function download(Request $request): ApiResponse
    {
        $id = RouteIds::get($request, 'id');
        $archivo = $this->mantenimientos->entidadArchivo($id) === 'MANTENIMIENTO'
            ? $this->mantenimientos->descargar($request, $id)
            : $this->inspecciones->descargar($request, $id);

        return ApiResponse::archivo($archivo['contenido'], $archivo['mime'], $archivo['nombre']);
    }

    public function destroy(Request $request): ApiResponse
    {
        $id = RouteIds::get($request, 'id');
        if ($this->mantenimientos->entidadArchivo($id) === 'MANTENIMIENTO') {
            $this->mantenimientos->eliminarArchivo($request, $id);
        } else {
            $this->inspecciones->eliminarArchivo($request, $id);
        }

        return ApiResponse::noContent();
    }
}
