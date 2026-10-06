<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\NotFoundException;
use App\Http\Request;

final class RouteIds
{
    public static function get(Request $request, string $name): int
    {
        $raw = $request->route($name);
        if ($raw === null || preg_match('/^[1-9][0-9]*$/', $raw) !== 1) {
            throw new NotFoundException('Recurso no encontrado.');
        }

        return (int) $raw;
    }
}
