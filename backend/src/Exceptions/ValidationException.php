<?php

declare(strict_types=1);

namespace App\Exceptions;

final class ValidationException extends HttpException
{
    /** @param array<string, list<string>> $details */
    public function __construct(string $message, array $details)
    {
        parent::__construct(422, 'VALIDATION_ERROR', $message, $details);
    }
}
