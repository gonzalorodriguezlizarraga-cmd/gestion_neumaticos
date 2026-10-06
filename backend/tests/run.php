<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Config\Database;
use App\Http\ApiResponse;
use App\Http\Kernel;
use App\Http\Request;

$results = [];
$kernel = Kernel::boot(dirname(__DIR__));
$pdo = Database::connection();

$kernel->router->get(
    '/api/v1/_probe/gestor',
    static fn (): ApiResponse => ApiResponse::ok(['ok' => true]),
    true,
    ['GESTOR_NEUMATICOS']
);
$kernel->router->get(
    '/api/v1/_probe/clientes/{clienteId}',
    static fn (Request $request): ApiResponse => ApiResponse::ok([
        'cliente_id' => (int) $request->route('clienteId'),
    ]),
    true,
    [],
    'clienteId'
);
$kernel->router->get(
    '/api/v1/_probe/boom',
    static function (): ApiResponse {
        throw new RuntimeException('SQLSTATE[42000] SELECT password_hash FROM usuarios D:\\xampp\\secret');
    }
);

$usersBefore = (int) $pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();
$clientsBefore = (int) $pdo->query('SELECT COUNT(*) FROM clientes')->fetchColumn();
$rolesBefore = $pdo->query('SELECT codigo, activo FROM roles ORDER BY codigo')->fetchAll();

$today = (string) $pdo->query('SELECT CURRENT_DATE')->fetchColumn();
$tomorrow = (string) $pdo->query('SELECT DATE_ADD(CURRENT_DATE, INTERVAL 1 DAY)')->fetchColumn();
$yesterday = (string) $pdo->query('SELECT DATE_SUB(CURRENT_DATE, INTERVAL 1 DAY)')->fetchColumn();
$pastStart = (string) $pdo->query('SELECT DATE_SUB(CURRENT_DATE, INTERVAL 40 DAY)')->fetchColumn();
$pastEnd = (string) $pdo->query('SELECT DATE_SUB(CURRENT_DATE, INTERVAL 10 DAY)')->fetchColumn();

$pdo->beginTransaction();
$failed = false;

try {
    $password = bin2hex(random_bytes(12));
    $active = createUser($pdo, 'Activo', 'Prueba', 'ACTIVO', 0, $password);
    assignRole($pdo, $active, 'GESTOR_NEUMATICOS');
    $clientA = createClient($pdo, 'Cliente A Bloque 0');
    $clientB = createClient($pdo, 'Cliente B Bloque 0');
    assignClient($pdo, $active, $clientA, $today, null, 1);

    $login = callApi($kernel, 'POST', '/api/v1/auth/login', [
        'email' => emailOf($pdo, $active),
        'password' => $password,
    ]);
    $token = $login['body']['data']['token'] ?? null;
    $payloadKeys = is_string($token) ? jwtPayloadKeys($token) : [];
    $acceso = $pdo->prepare('SELECT ultimo_acceso FROM usuarios WHERE id = :id');
    $acceso->bindValue('id', $active, PDO::PARAM_INT);
    $acceso->execute();
    $ultimo = $acceso->fetchColumn();
    $loginJson = json_encode($login['body'], JSON_THROW_ON_ERROR);
    record(
        '1',
        'Login válido',
        $login['status'] === 200
            && is_string($token)
            && substr_count($token, '.') === 2
            && $payloadKeys === ['exp', 'iat', 'jti', 'sub']
            && ($login['body']['data']['user']['roles'] ?? null) === ['GESTOR_NEUMATICOS']
            && $ultimo !== null
            && $ultimo !== false
            && !str_contains($loginJson, 'password'),
        'HTTP ' . $login['status'] . '; claims ' . implode(',', $payloadKeys)
    );

    $badPassword = callApi($kernel, 'POST', '/api/v1/auth/login', [
        'email' => emailOf($pdo, $active),
        'password' => $password . '-no',
    ]);
    record(
        '2',
        'Password incorrecto',
        isGenericAuthFailure($badPassword),
        codeOf($badPassword)
    );

    $missingUser = callApi($kernel, 'POST', '/api/v1/auth/login', [
        'email' => 'no-existe-' . bin2hex(random_bytes(4)) . '@test.local',
        'password' => $password,
    ]);
    record(
        '3',
        'Usuario inexistente',
        isGenericAuthFailure($missingUser)
            && ($missingUser['body']['err']['message'] ?? null) === ($badPassword['body']['err']['message'] ?? null),
        codeOf($missingUser)
    );

    $inactive = createUser($pdo, 'Inactivo', 'Prueba', 'INACTIVO', 0, $password);
    $inactiveLogin = callApi($kernel, 'POST', '/api/v1/auth/login', [
        'email' => emailOf($pdo, $inactive),
        'password' => $password,
    ]);
    $inactiveToken = ($kernel->jwt()->sign([
        'sub' => $inactive,
        'iat' => time(),
        'exp' => time() + 600,
        'jti' => 'inactive',
    ]));
    $inactiveMe = callApi($kernel, 'GET', '/api/v1/auth/me', null, $inactiveToken);
    record(
        '4',
        'Usuario INACTIVO',
        isGenericAuthFailure($inactiveLogin)
            && $inactiveMe['status'] === 401
            && !revealsAccountState($inactiveLogin)
            && !revealsAccountState($inactiveMe),
        'login ' . codeOf($inactiveLogin) . '; me HTTP ' . $inactiveMe['status']
    );

    $blocked = createUser($pdo, 'Bloqueado', 'Prueba', 'BLOQUEADO', 0, $password);
    $blockedLogin = callApi($kernel, 'POST', '/api/v1/auth/login', [
        'email' => emailOf($pdo, $blocked),
        'password' => $password,
    ]);
    $blockedToken = $kernel->jwt()->sign([
        'sub' => $blocked,
        'iat' => time(),
        'exp' => time() + 600,
        'jti' => 'blocked',
    ]);
    $blockedMe = callApi($kernel, 'GET', '/api/v1/auth/me', null, $blockedToken);
    record(
        '5',
        'Usuario BLOQUEADO',
        isGenericAuthFailure($blockedLogin)
            && $blockedMe['status'] === 401
            && !revealsAccountState($blockedLogin)
            && !revealsAccountState($blockedMe),
        'login ' . codeOf($blockedLogin) . '; me HTTP ' . $blockedMe['status']
    );

    $deleted = createUser($pdo, 'Eliminado', 'Prueba', 'ACTIVO', 1, $password);
    $deletedLogin = callApi($kernel, 'POST', '/api/v1/auth/login', [
        'email' => emailOf($pdo, $deleted),
        'password' => $password,
    ]);
    $deletedToken = $kernel->jwt()->sign([
        'sub' => $deleted,
        'iat' => time(),
        'exp' => time() + 600,
        'jti' => 'deleted',
    ]);
    $deletedMe = callApi($kernel, 'GET', '/api/v1/auth/me', null, $deletedToken);
    record(
        '6',
        'Usuario eliminado',
        isGenericAuthFailure($deletedLogin)
            && $deletedMe['status'] === 401
            && !revealsAccountState($deletedLogin)
            && !revealsAccountState($deletedMe),
        'login ' . codeOf($deletedLogin) . '; me HTTP ' . $deletedMe['status']
    );

    $noToken = callApi($kernel, 'GET', '/api/v1/auth/me');
    record(
        '7',
        'Endpoint protegido sin token',
        $noToken['status'] === 401 && ($noToken['body']['err']['code'] ?? null) === 'AUTH_TOKEN_MISSING',
        codeOf($noToken)
    );

    $invalid = callApi($kernel, 'GET', '/api/v1/auth/me', null, 'esto-no-es-un-jwt');
    record(
        '8',
        'JWT inválido',
        $invalid['status'] === 401 && ($invalid['body']['err']['code'] ?? null) === 'AUTH_TOKEN_INVALID',
        codeOf($invalid)
    );

    $expiredToken = $kernel->jwt()->sign([
        'sub' => $active,
        'iat' => time() - 120,
        'exp' => time() - 60,
        'jti' => 'expired',
    ]);
    $expired = callApi($kernel, 'GET', '/api/v1/auth/me', null, $expiredToken);
    record(
        '9',
        'JWT expirado',
        $expired['status'] === 401 && ($expired['body']['err']['code'] ?? null) === 'AUTH_TOKEN_EXPIRED',
        codeOf($expired)
    );

    $me = callApi($kernel, 'GET', '/api/v1/auth/me', null, $token);
    record(
        '10',
        '/auth/me válido',
        $me['status'] === 200
            && ($me['body']['data']['id'] ?? null) === $active
            && ($me['body']['data']['email'] ?? null) === emailOf($pdo, $active)
            && ($me['body']['data']['roles'] ?? null) === ['GESTOR_NEUMATICOS']
            && ($me['body']['data']['scope'] ?? null) === 'CLIENTES'
            && count($me['body']['data']['clientes'] ?? []) === 1
            && ($me['body']['data']['clientes'][0]['id'] ?? null) === $clientA
            && !array_key_exists('password_hash', $me['body']['data'] ?? []),
        'HTTP ' . $me['status'] . '; scope ' . ($me['body']['data']['scope'] ?? '')
    );

    $allowedRole = callApi($kernel, 'GET', '/api/v1/_probe/gestor', null, $token);
    record(
        '11',
        'Usuario con rol autorizado',
        $allowedRole['status'] === 200 && ($allowedRole['body']['data']['ok'] ?? null) === true,
        'HTTP ' . $allowedRole['status']
    );

    $seller = createUser($pdo, 'Vendedor', 'Prueba', 'ACTIVO', 0, $password);
    assignRole($pdo, $seller, 'VENDEDOR');
    $sellerLogin = callApi($kernel, 'POST', '/api/v1/auth/login', [
        'email' => emailOf($pdo, $seller),
        'password' => $password,
    ]);
    $sellerToken = $sellerLogin['body']['data']['token'] ?? '';
    $deniedRole = callApi($kernel, 'GET', '/api/v1/_probe/gestor', null, is_string($sellerToken) ? $sellerToken : '');
    record(
        '12',
        'Usuario sin rol necesario',
        $deniedRole['status'] === 403 && ($deniedRole['body']['err']['code'] ?? null) === 'FORBIDDEN',
        codeOf($deniedRole)
    );

    $pdo->prepare('UPDATE roles SET activo = 0 WHERE codigo = :codigo')->execute(['codigo' => 'GESTOR_NEUMATICOS']);
    $inactiveRole = callApi($kernel, 'GET', '/api/v1/_probe/gestor', null, $token);
    $meWithoutRole = callApi($kernel, 'GET', '/api/v1/auth/me', null, $token);
    $pdo->prepare('UPDATE roles SET activo = 1 WHERE codigo = :codigo')->execute(['codigo' => 'GESTOR_NEUMATICOS']);
    $restoredRole = callApi($kernel, 'GET', '/api/v1/_probe/gestor', null, $token);
    record(
        '13',
        'Rol marcado activo = 0',
        $inactiveRole['status'] === 403
            && ($meWithoutRole['body']['data']['roles'] ?? null) === []
            && $restoredRole['status'] === 200,
        'durante inactivo HTTP ' . $inactiveRole['status'] . '; restaurado HTTP ' . $restoredRole['status']
    );

    $pdo->prepare('DELETE FROM usuario_roles WHERE usuario_id = :id')->execute(['id' => $active]);
    $removedRole = callApi($kernel, 'GET', '/api/v1/_probe/gestor', null, $token);
    assignRole($pdo, $active, 'GESTOR_NEUMATICOS');
    $roleBack = callApi($kernel, 'GET', '/api/v1/_probe/gestor', null, $token);
    record(
        '14',
        'Rol retirado después del JWT',
        $removedRole['status'] === 403
            && ($removedRole['body']['err']['code'] ?? null) === 'FORBIDDEN'
            && $roleBack['status'] === 200,
        'retirado HTTP ' . $removedRole['status'] . '; reasignado HTTP ' . $roleBack['status']
    );

    $ownClient = callApi($kernel, 'GET', '/api/v1/_probe/clientes/' . $clientA, null, $token);
    record(
        '15',
        'Cliente con asignación vigente',
        $ownClient['status'] === 200 && ($ownClient['body']['data']['cliente_id'] ?? null) === $clientA,
        'HTTP ' . $ownClient['status']
    );

    $otherClient = callApi($kernel, 'GET', '/api/v1/_probe/clientes/' . $clientB, null, $token);
    record(
        '16',
        'Cliente no asignado',
        $otherClient['status'] === 403 && ($otherClient['body']['err']['code'] ?? null) === 'CLIENT_SCOPE_FORBIDDEN',
        codeOf($otherClient)
    );

    $idor = callApi($kernel, 'GET', '/api/v1/_probe/clientes/' . $clientB . '?cliente_id=' . $clientA, null, $token);
    $unknownClient = callApi($kernel, 'GET', '/api/v1/_probe/clientes/999999999', null, $token);
    record(
        '17',
        'Cambiar cliente_id no permite IDOR',
        $idor['status'] === 403
            && ($idor['body']['err']['code'] ?? null) === 'CLIENT_SCOPE_FORBIDDEN'
            && $unknownClient['status'] === 403
            && ($unknownClient['body']['data'] ?? null) === null,
        'ajeno HTTP ' . $idor['status'] . '; inexistente HTTP ' . $unknownClient['status']
    );

    assignClient($pdo, $active, $clientB, $today, null, 1);
    $bothA = callApi($kernel, 'GET', '/api/v1/_probe/clientes/' . $clientA, null, $token);
    $bothB = callApi($kernel, 'GET', '/api/v1/_probe/clientes/' . $clientB, null, $token);
    $clientC = createClient($pdo, 'Cliente C Bloque 0');
    $bothC = callApi($kernel, 'GET', '/api/v1/_probe/clientes/' . $clientC, null, $token);
    $meBoth = callApi($kernel, 'GET', '/api/v1/auth/me', null, $token);
    $ids = array_map(static fn (array $row): int => (int) $row['id'], $meBoth['body']['data']['clientes'] ?? []);
    sort($ids);
    $expectedIds = [$clientA, $clientB];
    sort($expectedIds);
    record(
        '18',
        'Usuario con múltiples clientes vigentes',
        $bothA['status'] === 200 && $bothB['status'] === 200 && $bothC['status'] === 403 && $ids === $expectedIds,
        'A ' . $bothA['status'] . '; B ' . $bothB['status'] . '; C ' . $bothC['status']
    );

    $inactiveAssignmentUser = createUser($pdo, 'Asignacion', 'Inactiva', 'ACTIVO', 0, $password);
    assignRole($pdo, $inactiveAssignmentUser, 'GESTOR_NEUMATICOS');
    assignClient($pdo, $inactiveAssignmentUser, $clientA, $today, null, 0);
    $inactiveAssignmentLogin = callApi($kernel, 'POST', '/api/v1/auth/login', [
        'email' => emailOf($pdo, $inactiveAssignmentUser),
        'password' => $password,
    ]);
    $inactiveAssignmentToken = $inactiveAssignmentLogin['body']['data']['token'] ?? '';
    $inactiveAssignment = callApi(
        $kernel,
        'GET',
        '/api/v1/_probe/clientes/' . $clientA,
        null,
        is_string($inactiveAssignmentToken) ? $inactiveAssignmentToken : ''
    );
    record(
        '19',
        'Asignación activo = 0',
        $inactiveAssignment['status'] === 403,
        'HTTP ' . $inactiveAssignment['status']
    );

    $futureUser = createUser($pdo, 'Futuro', 'Inicio', 'ACTIVO', 0, $password);
    assignRole($pdo, $futureUser, 'GESTOR_NEUMATICOS');
    assignClient($pdo, $futureUser, $clientA, $tomorrow, null, 1);
    $futureLogin = callApi($kernel, 'POST', '/api/v1/auth/login', [
        'email' => emailOf($pdo, $futureUser),
        'password' => $password,
    ]);
    $futureToken = $futureLogin['body']['data']['token'] ?? '';
    $futureAccess = callApi(
        $kernel,
        'GET',
        '/api/v1/_probe/clientes/' . $clientA,
        null,
        is_string($futureToken) ? $futureToken : ''
    );
    record(
        '20',
        'fecha_inicio futura',
        $futureAccess['status'] === 403 && ($futureLogin['body']['data']['user']['clientes'] ?? null) === [],
        'HTTP ' . $futureAccess['status'] . '; inicio ' . $tomorrow
    );

    $expiredAssignmentUser = createUser($pdo, 'Vencido', 'Fin', 'ACTIVO', 0, $password);
    assignRole($pdo, $expiredAssignmentUser, 'GESTOR_NEUMATICOS');
    assignClient($pdo, $expiredAssignmentUser, $clientA, $pastStart, $yesterday, 1);
    $expiredAssignmentLogin = callApi($kernel, 'POST', '/api/v1/auth/login', [
        'email' => emailOf($pdo, $expiredAssignmentUser),
        'password' => $password,
    ]);
    $expiredAssignmentToken = $expiredAssignmentLogin['body']['data']['token'] ?? '';
    $expiredAssignment = callApi(
        $kernel,
        'GET',
        '/api/v1/_probe/clientes/' . $clientA,
        null,
        is_string($expiredAssignmentToken) ? $expiredAssignmentToken : ''
    );
    record(
        '21',
        'fecha_fin vencida',
        $expiredAssignment['status'] === 403,
        'HTTP ' . $expiredAssignment['status'] . '; fin ' . $yesterday
    );

    $openUser = createUser($pdo, 'Vigente', 'Abierta', 'ACTIVO', 0, $password);
    assignRole($pdo, $openUser, 'GESTOR_NEUMATICOS');
    assignClient($pdo, $openUser, $clientA, $today, null, 1);
    $openLogin = callApi($kernel, 'POST', '/api/v1/auth/login', [
        'email' => emailOf($pdo, $openUser),
        'password' => $password,
    ]);
    $openToken = $openLogin['body']['data']['token'] ?? '';
    $openAccess = callApi(
        $kernel,
        'GET',
        '/api/v1/_probe/clientes/' . $clientA,
        null,
        is_string($openToken) ? $openToken : ''
    );
    record(
        '22',
        'fecha_fin NULL',
        $openAccess['status'] === 200 && ($openLogin['body']['data']['user']['clientes'][0]['id'] ?? null) === $clientA,
        'HTTP ' . $openAccess['status']
    );

    $pdo->prepare(
        'UPDATE usuario_clientes SET activo = 0 WHERE usuario_id = :usuario AND cliente_id = :cliente'
    )->execute([
        'usuario' => $active,
        'cliente' => $clientA,
    ]);
    $revokedA = callApi($kernel, 'GET', '/api/v1/_probe/clientes/' . $clientA, null, $token);
    $stillB = callApi($kernel, 'GET', '/api/v1/_probe/clientes/' . $clientB, null, $token);
    record(
        '23',
        'Cliente retirado después del JWT',
        $revokedA['status'] === 403 && $stillB['status'] === 200,
        'A HTTP ' . $revokedA['status'] . '; B HTTP ' . $stillB['status']
    );

    $historyUser = createUser($pdo, 'Historico', 'Doble', 'ACTIVO', 0, $password);
    assignRole($pdo, $historyUser, 'GESTOR_NEUMATICOS');
    assignClient($pdo, $historyUser, $clientA, $pastStart, $pastEnd, 1);
    assignClient($pdo, $historyUser, $clientA, $today, null, 1);
    $historyLogin = callApi($kernel, 'POST', '/api/v1/auth/login', [
        'email' => emailOf($pdo, $historyUser),
        'password' => $password,
    ]);
    $historyClients = $historyLogin['body']['data']['user']['clientes'] ?? [];
    $historyToken = $historyLogin['body']['data']['token'] ?? '';
    $historyAccess = callApi(
        $kernel,
        'GET',
        '/api/v1/_probe/clientes/' . $clientA,
        null,
        is_string($historyToken) ? $historyToken : ''
    );
    record(
        '24',
        'Dos históricos y una sola autorización',
        $historyAccess['status'] === 200
            && count($historyClients) === 1
            && ($historyClients[0]['id'] ?? null) === $clientA,
        'clientes en perfil ' . count($historyClients) . '; HTTP ' . $historyAccess['status']
    );

    $admin = createUser($pdo, 'Admin', 'Global', 'ACTIVO', 0, $password);
    assignRole($pdo, $admin, 'ADMIN_GENERAL');
    $adminLinks = $pdo->prepare('SELECT COUNT(*) FROM usuario_clientes WHERE usuario_id = :id');
    $adminLinks->bindValue('id', $admin, PDO::PARAM_INT);
    $adminLinks->execute();
    $adminLogin = callApi($kernel, 'POST', '/api/v1/auth/login', [
        'email' => emailOf($pdo, $admin),
        'password' => $password,
    ]);
    $adminToken = $adminLogin['body']['data']['token'] ?? '';
    $adminA = callApi($kernel, 'GET', '/api/v1/_probe/clientes/' . $clientA, null, is_string($adminToken) ? $adminToken : '');
    $adminB = callApi($kernel, 'GET', '/api/v1/_probe/clientes/' . $clientB, null, is_string($adminToken) ? $adminToken : '');
    $adminMissing = callApi($kernel, 'GET', '/api/v1/_probe/clientes/999999999', null, is_string($adminToken) ? $adminToken : '');
    $adminMe = callApi($kernel, 'GET', '/api/v1/auth/me', null, is_string($adminToken) ? $adminToken : '');
    record(
        '25',
        'ADMIN_GENERAL sin usuario_clientes',
        (int) $adminLinks->fetchColumn() === 0
            && ($adminMe['body']['data']['scope'] ?? null) === 'GLOBAL'
            && ($adminMe['body']['data']['clientes'] ?? null) === []
            && $adminA['status'] === 200
            && $adminB['status'] === 200
            && $adminMissing['status'] === 404
            && ($adminMissing['body']['err']['code'] ?? null) === 'NOT_FOUND',
        'scope ' . ($adminMe['body']['data']['scope'] ?? '') . '; A ' . $adminA['status'] . '; inexistente ' . $adminMissing['status']
    );

    $validation = callApi($kernel, 'POST', '/api/v1/auth/login', []);
    record(
        '26',
        'Error de validación con formato estándar',
        $validation['status'] === 422
            && envelope($validation['body'])
            && ($validation['body']['err']['code'] ?? null) === 'VALIDATION_ERROR'
            && array_key_exists('data', $validation['body'])
            && $validation['body']['data'] === null
            && ($validation['body']['total'] ?? -1) === 0
            && isset($validation['body']['err']['details']['email'], $validation['body']['err']['details']['password']),
        codeOf($validation)
    );

    record(
        '27',
        'Error 401 con formato estándar',
        $badPassword['status'] === 401
            && envelope($badPassword['body'])
            && array_key_exists('data', $badPassword['body'])
            && $badPassword['body']['data'] === null,
        'claves ' . implode(',', array_keys($badPassword['body']))
    );

    record(
        '28',
        'Error 403 con formato estándar',
        $deniedRole['status'] === 403 && envelope($deniedRole['body']) && ($deniedRole['body']['err']['message'] ?? '') !== '',
        codeOf($deniedRole)
    );

    $notFound = callApi($kernel, 'GET', '/api/v1/no-existe');
    record(
        '29',
        'Error 404 con formato estándar',
        $notFound['status'] === 404
            && envelope($notFound['body'])
            && ($notFound['body']['err']['code'] ?? null) === 'NOT_FOUND',
        codeOf($notFound)
    );

    $boom = callApi($kernel, 'GET', '/api/v1/_probe/boom');
    $boomJson = json_encode($boom['body'], JSON_THROW_ON_ERROR);
    record(
        '30',
        'Error 500 sin detalles internos',
        $boom['status'] === 500
            && envelope($boom['body'])
            && ($boom['body']['err']['code'] ?? null) === 'INTERNAL_ERROR'
            && ($boom['body']['err']['message'] ?? null) === 'Error interno del servidor.'
            && !str_contains($boomJson, 'SQLSTATE')
            && !str_contains($boomJson, 'password_hash')
            && !str_contains($boomJson, 'xampp')
            && !str_contains($boomJson, 'Stack'),
        codeOf($boom)
    );

    $cors = callApi($kernel, 'OPTIONS', '/api/v1/auth/login', null, null, [
        'origin' => 'http://127.0.0.1:5173',
    ]);
    $corsDenied = callApi($kernel, 'POST', '/api/v1/auth/login', [
        'email' => emailOf($pdo, $active),
        'password' => $password,
    ], null, ['origin' => 'https://evil.example']);
    record(
        'CORS',
        'Origen permitido y origen ajeno',
        $cors['status'] === 204
            && ($cors['headers']['Access-Control-Allow-Origin'] ?? null) === 'http://127.0.0.1:5173'
            && !array_key_exists('Access-Control-Allow-Origin', $corsDenied['headers'])
            && !in_array('*', $cors['headers'], true),
        'preflight ' . $cors['status']
    );

    $deactivated = createUser($pdo, 'Luego', 'Bloqueado', 'ACTIVO', 0, $password);
    assignRole($pdo, $deactivated, 'CONSULTA_EJECUTIVA');
    $deactivatedLogin = callApi($kernel, 'POST', '/api/v1/auth/login', [
        'email' => emailOf($pdo, $deactivated),
        'password' => $password,
    ]);
    $deactivatedToken = $deactivatedLogin['body']['data']['token'] ?? '';
    $pdo->prepare('UPDATE usuarios SET estado = :estado WHERE id = :id')->execute([
        'estado' => 'BLOQUEADO',
        'id' => $deactivated,
    ]);
    $afterBlock = callApi($kernel, 'GET', '/api/v1/auth/me', null, is_string($deactivatedToken) ? $deactivatedToken : '');
    record(
        'EXTRA',
        'Bloqueo posterior al JWT corta el acceso',
        $deactivatedLogin['status'] === 200 && $afterBlock['status'] === 401,
        'login ' . $deactivatedLogin['status'] . '; me ' . $afterBlock['status']
    );
} catch (Throwable $error) {
    $failed = true;
    fwrite(STDERR, 'EXCEPCION ' . $error::class . ' ' . $error->getMessage() . PHP_EOL);
    fwrite(STDERR, $error->getFile() . ':' . $error->getLine() . PHP_EOL);
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

$usersAfter = (int) $pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();
$clientsAfter = (int) $pdo->query('SELECT COUNT(*) FROM clientes')->fetchColumn();
$rolesAfter = $pdo->query('SELECT codigo, activo FROM roles ORDER BY codigo')->fetchAll();
$cleanupOk = $usersBefore === $usersAfter && $clientsBefore === $clientsAfter && $rolesBefore === $rolesAfter;
record('CLEAN', 'La transacción de prueba no dejó datos', $cleanupOk, "usuarios {$usersBefore}→{$usersAfter}; clientes {$clientsBefore}→{$clientsAfter}");

$pass = 0;
$fail = 0;
foreach ($results as $result) {
    echo $result['status'] . ' | ' . $result['id'] . ' | ' . $result['name'] . ' | ' . $result['evidence'] . PHP_EOL;
    if ($result['status'] === 'PASS') {
        $pass++;
    } else {
        $fail++;
    }
}
echo "RESUMEN pass={$pass} fail={$fail}" . PHP_EOL;
exit($failed || $fail > 0 ? 1 : 0);

/** @param array{status:int,body:array<string,mixed>,headers:array<string,string>} $response */
function record(string $id, string $name, bool $ok, string $evidence): void
{
    global $results;
    $results[] = [
        'status' => $ok ? 'PASS' : 'FAIL',
        'id' => $id,
        'name' => $name,
        'evidence' => $evidence,
    ];
}

/** @param array<string, string> $headers
 *  @return array{status:int,body:array<string,mixed>,headers:array<string,string>}
 */
function callApi(Kernel $kernel, string $method, string $path, ?array $json = null, ?string $token = null, array $headers = []): array
{
    if ($token !== null) {
        $headers['authorization'] = 'Bearer ' . $token;
    }
    if ($json !== null) {
        $headers['content-type'] = 'application/json';
    }
    $request = Request::fake(
        $method,
        $path,
        $headers,
        $json === null ? null : json_encode($json, JSON_THROW_ON_ERROR)
    );
    $response = $kernel->handle($request);

    return [
        'status' => $response->status(),
        'body' => $response->status() === 204 ? [] : $response->toArray(),
        'headers' => $response->headers(),
    ];
}

function createUser(PDO $pdo, string $nombres, string $apellidos, string $estado, int $eliminado, string $password): int
{
    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]);
    if ($hash === false) {
        throw new RuntimeException('No se pudo hashear la clave de prueba.');
    }
    $email = 'bloque0.' . bin2hex(random_bytes(6)) . '@test.local';
    $statement = $pdo->prepare(
        'INSERT INTO usuarios (nombres, apellidos, email, password_hash, estado, eliminado, eliminado_en)
         VALUES (:nombres, :apellidos, :email, :password_hash, :estado, :eliminado, :eliminado_en)'
    );
    $statement->bindValue('nombres', $nombres);
    $statement->bindValue('apellidos', $apellidos);
    $statement->bindValue('email', $email);
    $statement->bindValue('password_hash', $hash);
    $statement->bindValue('estado', $estado);
    $statement->bindValue('eliminado', $eliminado, PDO::PARAM_INT);
    if ($eliminado === 1) {
        $statement->bindValue('eliminado_en', date('Y-m-d H:i:s'));
    } else {
        $statement->bindValue('eliminado_en', null, PDO::PARAM_NULL);
    }
    $statement->execute();

    return (int) $pdo->lastInsertId();
}

function assignRole(PDO $pdo, int $userId, string $codigo): void
{
    $role = $pdo->prepare('SELECT id FROM roles WHERE codigo = :codigo LIMIT 1');
    $role->execute(['codigo' => $codigo]);
    $roleId = $role->fetchColumn();
    if ($roleId === false) {
        throw new RuntimeException('Rol no encontrado: ' . $codigo);
    }
    $insert = $pdo->prepare('INSERT INTO usuario_roles (usuario_id, rol_id) VALUES (:usuario_id, :rol_id)');
    $insert->bindValue('usuario_id', $userId, PDO::PARAM_INT);
    $insert->bindValue('rol_id', (int) $roleId, PDO::PARAM_INT);
    $insert->execute();
}

function createClient(PDO $pdo, string $razon): int
{
    $statement = $pdo->prepare(
        'INSERT INTO clientes (razon_social, nombre_comercial, estado, eliminado)
         VALUES (:razon, :comercial, :estado, 0)'
    );
    $statement->execute([
        'razon' => $razon . ' ' . bin2hex(random_bytes(3)),
        'comercial' => $razon,
        'estado' => 'ACTIVO',
    ]);

    return (int) $pdo->lastInsertId();
}

function assignClient(PDO $pdo, int $userId, int $clienteId, string $inicio, ?string $fin, int $activo): void
{
    $statement = $pdo->prepare(
        'INSERT INTO usuario_clientes (usuario_id, cliente_id, fecha_inicio, fecha_fin, activo)
         VALUES (:usuario_id, :cliente_id, :fecha_inicio, :fecha_fin, :activo)'
    );
    $statement->bindValue('usuario_id', $userId, PDO::PARAM_INT);
    $statement->bindValue('cliente_id', $clienteId, PDO::PARAM_INT);
    $statement->bindValue('fecha_inicio', $inicio);
    if ($fin === null) {
        $statement->bindValue('fecha_fin', null, PDO::PARAM_NULL);
    } else {
        $statement->bindValue('fecha_fin', $fin);
    }
    $statement->bindValue('activo', $activo, PDO::PARAM_INT);
    $statement->execute();
}

function emailOf(PDO $pdo, int $userId): string
{
    $statement = $pdo->prepare('SELECT email FROM usuarios WHERE id = :id');
    $statement->bindValue('id', $userId, PDO::PARAM_INT);
    $statement->execute();
    $email = $statement->fetchColumn();
    if (!is_string($email)) {
        throw new RuntimeException('Usuario de prueba sin correo.');
    }

    return $email;
}

/** @return list<string> */
function jwtPayloadKeys(string $token): array
{
    $parts = explode('.', $token);
    $payload = $parts[1] ?? '';
    $remainder = strlen($payload) % 4;
    if ($remainder > 0) {
        $payload .= str_repeat('=', 4 - $remainder);
    }
    $json = base64_decode(strtr($payload, '-_', '+/'), true);
    $data = is_string($json) ? json_decode($json, true) : null;
    if (!is_array($data)) {
        return [];
    }
    $keys = array_keys($data);
    sort($keys);

    return $keys;
}

/** @param array{status:int,body:array<string,mixed>} $response */
function isGenericAuthFailure(array $response): bool
{
    return $response['status'] === 401
        && ($response['body']['err']['code'] ?? null) === 'AUTH_INVALID_CREDENTIALS'
        && ($response['body']['err']['message'] ?? null) === 'Credenciales inválidas.';
}

/** @param array{status:int,body:array<string,mixed>} $response */
function revealsAccountState(array $response): bool
{
    $json = json_encode($response['body'], JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        return true;
    }
    $upper = strtoupper($json);

    return str_contains($upper, 'INACTIVO')
        || str_contains($upper, 'BLOQUEADO')
        || str_contains($upper, 'ELIMINADO');
}

/** @param array{status:int,body:array<string,mixed>} $response */
function codeOf(array $response): string
{
    return 'HTTP ' . $response['status'] . ' ' . (string) ($response['body']['err']['code'] ?? '');
}

/** @param array<string, mixed> $body */
function envelope(array $body): bool
{
    return array_keys($body) === ['data', 'total', 'status', 'err'];
}
