<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiResponse;
use App\Http\Request;
use App\Services\IndicadorService;
use App\Support\RouteIds;

final class IndicadorController
{
    public function __construct(private readonly IndicadorService $indicadores)
    {
    }

    public function operativos(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->indicadores->operativos($request));
    }

    public function deNeumatico(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->indicadores->deNeumatico($request, RouteIds::get($request, 'id')));
    }
}
