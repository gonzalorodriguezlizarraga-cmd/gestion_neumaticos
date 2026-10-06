<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiResponse;
use App\Http\Request;
use App\Services\ReporteService;

final class ReporteController
{
    public function __construct(private readonly ReporteService $reportes)
    {
    }

    public function consultar(Request $request): ApiResponse
    {
        [$datos, $total] = $this->reportes->consultar($request, $this->tipo($request));

        return ApiResponse::ok($datos, $total);
    }

    public function csv(Request $request): ApiResponse
    {
        $archivo = $this->reportes->csv($request, $this->tipo($request));

        return ApiResponse::archivo($archivo['contenido'], $archivo['mime'], $archivo['nombre']);
    }

    public function pdf(Request $request): ApiResponse
    {
        $archivo = $this->reportes->pdf($request, $this->tipo($request));

        return ApiResponse::archivo($archivo['contenido'], $archivo['mime'], $archivo['nombre']);
    }

    private function tipo(Request $request): string
    {
        $tipo = (string) ($request->route('tipo') ?? '');
        if (preg_match('/^[a-z0-9-]+$/', $tipo) !== 1) {
            return 'invalido';
        }

        return $tipo;
    }
}
