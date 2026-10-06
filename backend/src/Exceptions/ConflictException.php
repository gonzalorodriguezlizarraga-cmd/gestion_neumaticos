<?php

declare(strict_types=1);

namespace App\Exceptions;

final class ConflictException extends HttpException
{
    public function __construct(string $message, string $code = 'CONFLICT')
    {
        parent::__construct(409, $code, $message);
    }
}
