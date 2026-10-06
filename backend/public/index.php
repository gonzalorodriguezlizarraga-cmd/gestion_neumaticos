<?php

declare(strict_types=1);

if (PHP_SAPI === 'cli-server') {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $localFile = __DIR__ . $requestPath;
    if ($requestPath !== '/' && is_file($localFile)) {
        return false;
    }
}

require dirname(__DIR__) . '/bootstrap.php';

App\Http\Kernel::boot(dirname(__DIR__))->run();
