<?php

declare(strict_types=1);

namespace App\Exceptions;

final class AuthenticationException extends HttpException
{
    public function __construct(string $errorCode, string $message)
    {
        parent::__construct(401, $errorCode, $message);
    }
}
