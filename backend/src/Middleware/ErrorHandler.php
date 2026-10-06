<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Exceptions\HttpException;
use App\Http\ApiResponse;
use Throwable;

final class ErrorHandler
{
    public function __construct(
        private readonly string $logFile,
        private readonly bool $debug,
    ) {
    }

    public function handle(Throwable $error): ApiResponse
    {
        if ($error instanceof HttpException) {
            return ApiResponse::error(
                $error->status,
                $error->errorCode,
                $error->getMessage(),
                $error->details
            );
        }

        $this->log($error);

        return ApiResponse::error(500, 'INTERNAL_ERROR', 'Error interno del servidor.');
    }

    private function log(Throwable $error): void
    {
        $directory = dirname($this->logFile);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            return;
        }

        $entry = [
            'time' => date('c'),
            'class' => $error::class,
            'message' => $error->getMessage(),
            'file' => $error->getFile(),
            'line' => $error->getLine(),
        ];
        if ($this->debug) {
            $entry['trace'] = $error->getTraceAsString();
        }

        $encoded = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($encoded === false) {
            return;
        }

        file_put_contents($this->logFile, $encoded . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
