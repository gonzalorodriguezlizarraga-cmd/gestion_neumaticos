<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Http\Request;

final class CorsMiddleware
{
    /** @param list<string> $allowedOrigins */
    public function __construct(private readonly array $allowedOrigins)
    {
    }

    /** @return array<string, string> */
    public function headersFor(Request $request): array
    {
        $headers = [
            'Vary' => 'Origin',
            'Access-Control-Allow-Headers' => 'Authorization, Content-Type, Accept',
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
            'Access-Control-Max-Age' => '600',
        ];

        $origin = $request->header('origin');
        if ($origin !== null && in_array($origin, $this->allowedOrigins, true)) {
            $headers['Access-Control-Allow-Origin'] = $origin;
        }

        return $headers;
    }
}
