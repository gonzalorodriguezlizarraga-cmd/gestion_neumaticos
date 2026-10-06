<?php

declare(strict_types=1);

namespace App\Exceptions;

final class AuthorizationException extends HttpException
{
    public function __construct(string $message, string $errorCode = 'FORBIDDEN')
    {
        parent::__construct(403, $errorCode, $message);
    }
}
