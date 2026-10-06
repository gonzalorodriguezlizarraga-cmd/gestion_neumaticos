<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Exceptions\AuthorizationException;
use App\Http\Request;
use App\Services\AuthorizationService;

final class AuthorizationMiddleware
{
    public function __construct(private readonly AuthorizationService $authorization)
    {
    }

    /** @param list<string> $roles */
    public function enforce(Request $request, array $roles, ?string $clientParam): void
    {
        $userId = $request->userId();
        if ($roles !== []) {
            $this->authorization->requireRole($userId, ...$roles);
        }
        if ($clientParam === null) {
            return;
        }

        $raw = $request->route($clientParam);
        if ($raw === null || !preg_match('/^[1-9][0-9]*$/', $raw)) {
            throw new AuthorizationException(
                'No tiene acceso a este cliente.',
                'CLIENT_SCOPE_FORBIDDEN'
            );
        }

        $this->authorization->requireClientAccess($userId, (int) $raw);
    }
}
