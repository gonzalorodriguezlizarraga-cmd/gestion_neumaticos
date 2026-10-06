<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Config\Database;
use App\Http\Kernel;
use App\Http\Request;

$alertaId = (int) ($argv[1] ?? 0);
$token = (string) ($argv[2] ?? '');
$hold = (string) ($argv[3] ?? '0');
$signal = (string) ($argv[4] ?? '');

if (preg_match('/^[1-9][0-9]{0,3}$/', $hold) === 1) {
    putenv('ALERTA_TRANSITION_HOLD_MS=' . $hold);
}
if (preg_match('/^alerta-hold-[A-Za-z0-9._-]+$/', $signal) === 1) {
    putenv('ALERTA_TRANSITION_HOLD_SIGNAL=' . $signal);
}

try {
    $kernel = Kernel::boot(dirname(__DIR__));
    if ($hold === '0') {
        Database::connection()->exec('SET SESSION innodb_lock_wait_timeout = 15');
    }
    $response = $kernel->handle(Request::fake(
        'POST',
        '/api/v1/alertas/' . $alertaId . '/atender',
        [
            'authorization' => 'Bearer ' . $token,
            'content-type' => 'application/json',
            'user-agent' => 'bloque7-concurrencia',
            'x-client-ip' => '203.0.113.27',
        ],
        json_encode(['observacion' => 'Atención concurrente'], JSON_THROW_ON_ERROR),
    ));
    $body = $response->toArray();
    echo json_encode([
        'status' => $response->status(),
        'code' => $body['err']['code'] ?? null,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    echo json_encode([
        'status' => 500,
        'code' => 'EXCEPTION',
        'message' => $error->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
