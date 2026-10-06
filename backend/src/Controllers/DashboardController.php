<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\ApiResponse;
use App\Http\Request;
use App\Services\DashboardService;

final class DashboardController
{
    public function __construct(private readonly DashboardService $dashboard)
    {
    }

    public function operador(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->dashboard->operador($request));
    }

    public function cliente(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->dashboard->cliente($request));
    }

    public function comercial(Request $request): ApiResponse
    {
        return ApiResponse::ok($this->dashboard->comercial($request));
    }
}
