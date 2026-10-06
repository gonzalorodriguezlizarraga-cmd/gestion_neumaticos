<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Config\Database;
use App\Http\Kernel;
use App\Http\Request;

$results = [];
$kernel = Kernel::boot(dirname(__DIR__));
$pdo = Database::connection();
$before = counts($pdo);
$hoy = (string) $pdo->query('SELECT CURRENT_DATE')->fetchColumn();
$ayer = (string) $pdo->query('SELECT DATE_SUB(CURRENT_DATE, INTERVAL 1 DAY)')->fetchColumn();
$pdo->beginTransaction();
$failed = false;

try {
    $password = bin2hex(random_bytes(8));
    $admin = user($pdo, 'Admin', 'Bloque', 'ADMIN_GENERAL', 'ACTIVO', $password);
    $gestor = user($pdo, 'Gestor', 'Bloque', 'GESTOR_NEUMATICOS', 'ACTIVO', $password);
    $tecnico = user($pdo, 'Tecnico', 'Bloque', 'TECNICO_INSPECCION', 'ACTIVO', $password);
    $vendedor = user($pdo, 'Vendedor', 'Bloque', 'VENDEDOR', 'ACTIVO', $password);
    $sinRol = user($pdo, 'Sin', 'Rol', null, 'ACTIVO', $password);
    $adminToken = token($kernel, emailOf($pdo, $admin), $password);
    $gestorToken = token($kernel, emailOf($pdo, $gestor), $password);

    $marca = 'B1' . bin2hex(random_bytes(3));
    $libre = crearCliente($kernel, $adminToken, $marca . ' Libre', 'ACTIVO', '10' . bin2hex(random_bytes(3)));
    $asignado = crearCliente($kernel, $adminToken, $marca . ' Asignado', 'ACTIVO', '20' . bin2hex(random_bytes(3)));
    $libreId = (int) $libre['body']['data']['id'];
    $asignadoId = (int) $asignado['body']['data']['id'];
    scope($pdo, $gestor, $asignadoId, $hoy);

    $adminList = callApi($kernel, 'GET', '/api/v1/clientes?search=' . rawurlencode($marca) . '&limit=1&page=1&sort=razon_social&order=asc', null, $adminToken);
    $gestorList = callApi($kernel, 'GET', '/api/v1/clientes?search=' . rawurlencode($marca), null, $gestorToken);
    $idsGestor = array_column($gestorList['body']['data'] ?? [], 'id');
    record('1', 'ADMIN lista no eliminados', $adminList['status'] === 200 && ($adminList['body']['total'] ?? 0) === 2 && count($adminList['body']['data'] ?? []) === 1, 'total ' . ($adminList['body']['total'] ?? 0));
    record('2', 'Usuario limitado solo su scope', $gestorList['status'] === 200 && $idsGestor === [$asignadoId], 'ids ' . implode(',', $idsGestor));

    $creado = $libre;
    record(
        '3',
        'Crear cliente válido',
        $creado['status'] === 201
            && ($creado['body']['data']['estado'] ?? '') === 'ACTIVO'
            && array_key_exists('fecha_inicio_servicio', $creado['body']['data'] ?? [])
            && $creado['body']['data']['fecha_inicio_servicio'] === null,
        'HTTP ' . $creado['status']
    );

    $ruc = '30' . bin2hex(random_bytes(3));
    crearCliente($kernel, $adminToken, $marca . ' Ruc', 'POTENCIAL', $ruc);
    $dup = crearCliente($kernel, $adminToken, $marca . ' Ruc 2', 'POTENCIAL', $ruc);
    record('4', 'RUC duplicado', $dup['status'] === 409 && ($dup['body']['err']['code'] ?? '') === 'CONFLICT', codeOf($dup));

    $sinRazon = callApi($kernel, 'POST', '/api/v1/clientes', ['estado' => 'POTENCIAL'], $adminToken);
    record('5', 'Sin razón social', $sinRazon['status'] === 422 && isset($sinRazon['body']['err']['details']['razon_social']), codeOf($sinRazon));

    $edit = callApi($kernel, 'PUT', '/api/v1/clientes/' . $asignadoId, ['nombre_comercial' => 'Operativo editado', 'telefono' => '999000'], $adminToken);
    $gestorEdit = callApi($kernel, 'PUT', '/api/v1/clientes/' . $asignadoId, ['direccion' => 'Av. Prueba 100'], $gestorToken);
    $gestorBloqueado = callApi($kernel, 'PUT', '/api/v1/clientes/' . $asignadoId, ['razon_social' => 'No debe cambiar'], $gestorToken);
    record('6', 'Editar cliente', $edit['status'] === 200 && ($edit['body']['data']['nombre_comercial'] ?? '') === 'Operativo editado' && $gestorEdit['status'] === 200 && $gestorBloqueado['status'] === 403, 'admin ' . $edit['status'] . '; gestor ' . $gestorEdit['status'] . '; campo ' . $gestorBloqueado['status']);

    $fuera = callApi($kernel, 'GET', '/api/v1/clientes/' . $libreId, null, $gestorToken);
    $fueraPut = callApi($kernel, 'PUT', '/api/v1/clientes/' . $libreId, ['telefono' => '111'], $gestorToken);
    record('7', 'Fuera de scope', $fuera['status'] === 403 && ($fuera['body']['err']['code'] ?? '') === 'CLIENT_SCOPE_FORBIDDEN' && $fueraPut['status'] === 403, codeOf($fuera));

    $potencial = crearCliente($kernel, $adminToken, $marca . ' Potencial', 'POTENCIAL', '40' . bin2hex(random_bytes(3)));
    $potencialId = (int) $potencial['body']['data']['id'];
    $aActivo = callApi($kernel, 'PATCH', '/api/v1/clientes/' . $potencialId . '/estado', ['estado' => 'ACTIVO'], $adminToken);
    $aInactivo = callApi($kernel, 'PATCH', '/api/v1/clientes/' . $potencialId . '/estado', ['estado' => 'INACTIVO'], $adminToken);
    $aReactivo = callApi($kernel, 'PATCH', '/api/v1/clientes/' . $potencialId . '/estado', ['estado' => 'ACTIVO'], $adminToken);
    record('8', 'POTENCIAL a ACTIVO', $aActivo['status'] === 200 && ($aActivo['body']['data']['estado'] ?? '') === 'ACTIVO', codeOf($aActivo));
    record('9', 'ACTIVO a INACTIVO', $aInactivo['status'] === 200 && ($aInactivo['body']['data']['estado'] ?? '') === 'INACTIVO', codeOf($aInactivo));
    record('10', 'INACTIVO a ACTIVO', $aReactivo['status'] === 200 && ($aReactivo['body']['data']['estado'] ?? '') === 'ACTIVO', codeOf($aReactivo));

    $borrado = callApi($kernel, 'DELETE', '/api/v1/clientes/' . $libreId, null, $adminToken);
    $listaTrasBorrar = callApi($kernel, 'GET', '/api/v1/clientes?search=' . rawurlencode($marca . ' Libre'), null, $adminToken);
    record('11', 'Soft-delete cliente', $borrado['status'] === 200 && ($borrado['body']['data']['eliminado'] ?? false) === true, codeOf($borrado));
    record('12', 'Eliminado fuera del listado', ($listaTrasBorrar['body']['total'] ?? -1) === 0, 'total ' . ($listaTrasBorrar['body']['total'] ?? -1));

    scope($pdo, $gestor, $libreId, $hoy);
    $accesoBorrado = callApi($kernel, 'GET', '/api/v1/clientes/' . $libreId, null, $gestorToken);
    record('13', 'Eliminado no abre acceso', $accesoBorrado['status'] === 403, codeOf($accesoBorrado));

    $contacto = callApi($kernel, 'POST', '/api/v1/clientes/' . $asignadoId . '/contactos', contacto('Ana', 'ana@cliente.test', true), $gestorToken);
    $contactoId = (int) ($contacto['body']['data']['id'] ?? 0);
    record('14', 'Crear contacto', $contacto['status'] === 201 && ($contacto['body']['data']['principal'] ?? false) === true, 'HTTP ' . $contacto['status']);
    $contactoEdit = callApi($kernel, 'PUT', '/api/v1/clientes/' . $asignadoId . '/contactos/' . $contactoId, contacto('Ana María', 'ana@cliente.test', true), $gestorToken);
    record('15', 'Editar contacto', $contactoEdit['status'] === 200 && ($contactoEdit['body']['data']['nombre'] ?? '') === 'Ana María', codeOf($contactoEdit));
    $contactoOff = callApi($kernel, 'PATCH', '/api/v1/clientes/' . $asignadoId . '/contactos/' . $contactoId . '/estado', ['activo' => false], $gestorToken);
    record('16', 'Desactivar contacto', $contactoOff['status'] === 200 && ($contactoOff['body']['data']['activo'] ?? true) === false, codeOf($contactoOff));
    $mail = callApi($kernel, 'POST', '/api/v1/clientes/' . $asignadoId . '/contactos', contacto('Mal', 'no-es-correo', false), $gestorToken);
    record('17', 'Email de contacto inválido', $mail['status'] === 422, codeOf($mail));
    $principal2 = callApi($kernel, 'POST', '/api/v1/clientes/' . $asignadoId . '/contactos', contacto('Beto', 'beto@cliente.test', true), $gestorToken);
    record('18', 'Dos contactos principales', $principal2['status'] === 201 && ($principal2['body']['data']['principal'] ?? false) === true, 'HTTP ' . $principal2['status']);

    $tecnicoResp = callApi($kernel, 'POST', '/api/v1/clientes/' . $asignadoId . '/responsables', responsable($tecnico, 'TECNICO', $hoy, null), $adminToken);
    record('19', 'Asignar técnico', $tecnicoResp['status'] === 201 && ($tecnicoResp['body']['data']['tipo_responsabilidad'] ?? '') === 'TECNICO', 'HTTP ' . $tecnicoResp['status']);
    $comercial = callApi($kernel, 'POST', '/api/v1/clientes/' . $asignadoId . '/responsables', responsable($vendedor, 'COMERCIAL', $hoy, null), $adminToken);
    $comercialId = (int) ($comercial['body']['data']['id'] ?? 0);
    record('20', 'Asignar comercial', $comercial['status'] === 201, 'HTTP ' . $comercial['status']);
    $fechas = callApi($kernel, 'POST', '/api/v1/clientes/' . $asignadoId . '/responsables', responsable($vendedor, 'COMERCIAL', $hoy, $ayer), $adminToken);
    record('21', 'Fecha fin anterior', $fechas['status'] === 422, codeOf($fechas));
    $fantasma = callApi($kernel, 'POST', '/api/v1/clientes/' . $asignadoId . '/responsables', responsable(99999999, 'COMERCIAL', $hoy, null), $adminToken);
    record('22', 'Usuario inexistente', $fantasma['status'] === 422, codeOf($fantasma));
    $inactivoUser = user($pdo, 'Inactivo', 'Resp', 'VENDEDOR', 'INACTIVO', $password);
    $inactivoResp = callApi($kernel, 'POST', '/api/v1/clientes/' . $asignadoId . '/responsables', responsable($inactivoUser, 'COMERCIAL', $hoy, null), $adminToken);
    record('23', 'Usuario inactivo', $inactivoResp['status'] === 422, codeOf($inactivoResp));
    $cierre = callApi($kernel, 'DELETE', '/api/v1/clientes/' . $asignadoId . '/responsables/' . $comercialId, null, $adminToken);
    $sigue = $pdo->prepare('SELECT fecha_fin FROM cliente_responsables WHERE id = :id');
    $sigue->bindValue('id', $comercialId, PDO::PARAM_INT);
    $sigue->execute();
    record('24', 'Cerrar vigencia conserva histórico', $cierre['status'] === 200 && $sigue->fetchColumn() === $hoy, 'fin ' . ($cierre['body']['data']['fecha_fin'] ?? ''));
    $links = $pdo->prepare('SELECT COUNT(*) FROM usuario_clientes WHERE usuario_id = :id');
    $links->bindValue('id', $tecnico, PDO::PARAM_INT);
    $links->execute();
    $tecnicoToken = token($kernel, emailOf($pdo, $tecnico), $password);
    $tecnicoCliente = callApi($kernel, 'GET', '/api/v1/clientes/' . $asignadoId, null, $tecnicoToken);
    record('25', 'Responsable no concede acceso', (int) $links->fetchColumn() === 0 && $tecnicoCliente['status'] === 403, 'acceso HTTP ' . $tecnicoCliente['status']);

    $sede = callApi($kernel, 'POST', '/api/v1/clientes/' . $asignadoId . '/sedes', ['nombre' => 'Base Norte', 'codigo' => 'NORTE'], $gestorToken);
    $sedeId = (int) ($sede['body']['data']['id'] ?? 0);
    record('26', 'Crear sede en activo', $sede['status'] === 201, 'HTTP ' . $sede['status']);
    $sedeDup = callApi($kernel, 'POST', '/api/v1/clientes/' . $asignadoId . '/sedes', ['nombre' => 'Otra', 'codigo' => 'NORTE'], $gestorToken);
    record('27', 'Código de sede duplicado', $sedeDup['status'] === 409, codeOf($sedeDup));
    $otro = crearCliente($kernel, $adminToken, $marca . ' Otro', 'ACTIVO', '50' . bin2hex(random_bytes(3)));
    $otroId = (int) $otro['body']['data']['id'];
    $sedeOtro = callApi($kernel, 'POST', '/api/v1/clientes/' . $otroId . '/sedes', ['nombre' => 'Base Norte', 'codigo' => 'NORTE'], $adminToken);
    record('28', 'Mismo código en otro cliente', $sedeOtro['status'] === 201, 'HTTP ' . $sedeOtro['status']);
    $sedeDel = callApi($kernel, 'DELETE', '/api/v1/clientes/' . $asignadoId . '/sedes/' . $sedeId, null, $gestorToken);
    $sedesLista = callApi($kernel, 'GET', '/api/v1/clientes/' . $asignadoId . '/sedes', null, $gestorToken);
    $sedeVisible = false;
    foreach ($sedesLista['body']['data'] ?? [] as $item) {
        if ((int) $item['id'] === $sedeId) {
            $sedeVisible = true;
        }
    }
    record('29', 'Soft-delete sede', $sedeDel['status'] === 200 && $sedeVisible === false, codeOf($sedeDel));
    $sedeAjena = callApi($kernel, 'POST', '/api/v1/clientes/' . $otroId . '/sedes', ['nombre' => 'Ajena'], $gestorToken);
    record('30', 'Sede fuera de scope', $sedeAjena['status'] === 403, codeOf($sedeAjena));

    $flota = callApi($kernel, 'POST', '/api/v1/clientes/' . $asignadoId . '/flotas', ['nombre' => 'Ruta Sierra', 'codigo' => 'SIERRA'], $gestorToken);
    $flotaId = (int) ($flota['body']['data']['id'] ?? 0);
    record('31', 'Crear flota', $flota['status'] === 201, 'HTTP ' . $flota['status']);
    $flotaDup = callApi($kernel, 'POST', '/api/v1/clientes/' . $asignadoId . '/flotas', ['nombre' => 'Otra ruta', 'codigo' => 'SIERRA'], $gestorToken);
    record('32', 'Código de flota duplicado', $flotaDup['status'] === 409, codeOf($flotaDup));
    $flotaOtro = callApi($kernel, 'POST', '/api/v1/clientes/' . $otroId . '/flotas', ['nombre' => 'Ruta Sierra', 'codigo' => 'SIERRA'], $adminToken);
    record('33', 'Mismo código de flota en otro cliente', $flotaOtro['status'] === 201, 'HTTP ' . $flotaOtro['status']);
    $flotaDel = callApi($kernel, 'DELETE', '/api/v1/clientes/' . $asignadoId . '/flotas/' . $flotaId, null, $gestorToken);
    $flotasLista = callApi($kernel, 'GET', '/api/v1/clientes/' . $asignadoId . '/flotas', null, $gestorToken);
    $flotaVisible = false;
    foreach ($flotasLista['body']['data'] ?? [] as $item) {
        if ((int) $item['id'] === $flotaId) {
            $flotaVisible = true;
        }
    }
    record('34', 'Soft-delete flota', $flotaDel['status'] === 200 && $flotaVisible === false, codeOf($flotaDel));
    $flotaAjena = callApi($kernel, 'GET', '/api/v1/clientes/' . $otroId . '/flotas', null, $gestorToken);
    record('35', 'Flota fuera de scope', $flotaAjena['status'] === 403, codeOf($flotaAjena));

    $potencial2 = crearCliente($kernel, $adminToken, $marca . ' Solo comercial', 'POTENCIAL', '60' . bin2hex(random_bytes(3)));
    $potencial2Id = (int) $potencial2['body']['data']['id'];
    $sedePotencial = callApi($kernel, 'POST', '/api/v1/clientes/' . $potencial2Id . '/sedes', ['nombre' => 'No debe'], $adminToken);
    record('36', 'POTENCIAL no crea sede', $sedePotencial['status'] === 409 && ($sedePotencial['body']['err']['code'] ?? '') === 'CONFLICT', codeOf($sedePotencial));
    callApi($kernel, 'PATCH', '/api/v1/clientes/' . $asignadoId . '/estado', ['estado' => 'INACTIVO'], $adminToken);
    $flotaInactiva = callApi($kernel, 'POST', '/api/v1/clientes/' . $asignadoId . '/flotas', ['nombre' => 'Nueva inactiva'], $adminToken);
    record('37', 'INACTIVO no crea flota', $flotaInactiva['status'] === 409, codeOf($flotaInactiva));
    $reactivado = callApi($kernel, 'PATCH', '/api/v1/clientes/' . $asignadoId . '/estado', ['estado' => 'ACTIVO'], $adminToken);
    record('38', 'ADMIN reactiva', $reactivado['status'] === 200 && ($reactivado['body']['data']['estado'] ?? '') === 'ACTIVO', codeOf($reactivado));

    $idorCliente = callApi($kernel, 'GET', '/api/v1/clientes/' . $otroId . '?cliente_id=' . $asignadoId, null, $gestorToken);
    record('39', 'IDOR cliente', $idorCliente['status'] === 403 && ($idorCliente['body']['data'] ?? null) === null, codeOf($idorCliente));
    $contactoAjeno = (int) $pdo->query('SELECT id FROM cliente_contactos WHERE cliente_id = ' . $asignadoId . ' ORDER BY id DESC LIMIT 1')->fetchColumn();
    $idorContacto = callApi($kernel, 'PUT', '/api/v1/clientes/' . $otroId . '/contactos/' . $contactoAjeno, contacto('Intruso', 'intruso@cliente.test', false), $adminToken);
    $idorContactoScope = callApi($kernel, 'PUT', '/api/v1/clientes/' . $otroId . '/contactos/' . $contactoAjeno, contacto('Intruso', 'intruso@cliente.test', false), $gestorToken);
    record('40', 'IDOR contacto', $idorContacto['status'] === 404 && $idorContactoScope['status'] === 403, 'cruzado ' . $idorContacto['status'] . '; scope ' . $idorContactoScope['status']);
    $idorResp = callApi($kernel, 'DELETE', '/api/v1/clientes/' . $otroId . '/responsables/' . $comercialId, null, $adminToken);
    record('41', 'IDOR responsable', $idorResp['status'] === 404, codeOf($idorResp));
    $sedeDeOtro = (int) ($sedeOtro['body']['data']['id'] ?? 0);
    $idorSede = callApi($kernel, 'DELETE', '/api/v1/clientes/' . $asignadoId . '/sedes/' . $sedeDeOtro, null, $adminToken);
    record('42', 'IDOR sede', $idorSede['status'] === 404, codeOf($idorSede));
    $flotaDeOtro = (int) ($flotaOtro['body']['data']['id'] ?? 0);
    $idorFlota = callApi($kernel, 'DELETE', '/api/v1/clientes/' . $asignadoId . '/flotas/' . $flotaDeOtro, null, $gestorToken);
    record('43', 'IDOR flota', $idorFlota['status'] === 404, codeOf($idorFlota));
    $bodyCruzado = callApi($kernel, 'POST', '/api/v1/clientes/' . $asignadoId . '/contactos', contacto('Camino', 'camino@cliente.test', false) + ['cliente_id' => $otroId], $gestorToken);
    record('44', 'cliente_id del body no evade el path', $bodyCruzado['status'] === 201 && (int) ($bodyCruzado['body']['data']['cliente_id'] ?? 0) === $asignadoId, 'cliente ' . ($bodyCruzado['body']['data']['cliente_id'] ?? ''));
    $sinRolToken = token($kernel, emailOf($pdo, $sinRol), $password);
    $sinPermiso = callApi($kernel, 'GET', '/api/v1/clientes', null, $sinRolToken);
    record('45', 'Usuario sin rol', $sinPermiso['status'] === 403 && ($sinPermiso['body']['err']['code'] ?? '') === 'FORBIDDEN', codeOf($sinPermiso));
    $tokenMal = callApi($kernel, 'GET', '/api/v1/clientes', null, 'token-invalido');
    record('46', 'Token inválido', $tokenMal['status'] === 401 && ($tokenMal['body']['err']['code'] ?? '') === 'AUTH_TOKEN_INVALID', codeOf($tokenMal));

    record('47', 'Auditoría de alta', audit($pdo, 'CLIENTE_CREATE', $asignadoId, '203.0.113.10') >= 1, 'filas ' . audit($pdo, 'CLIENTE_CREATE', $asignadoId, '203.0.113.10'));
    record('48', 'Auditoría de edición', audit($pdo, 'CLIENTE_UPDATE', $asignadoId, '203.0.113.10') >= 1, 'filas ' . audit($pdo, 'CLIENTE_UPDATE', $asignadoId, '203.0.113.10'));
    record('49', 'Auditoría de estado', audit($pdo, 'CLIENTE_ESTADO', $asignadoId, '203.0.113.10') >= 1, 'filas ' . audit($pdo, 'CLIENTE_ESTADO', $asignadoId, '203.0.113.10'));
    record('50', 'Auditoría de baja', audit($pdo, 'CLIENTE_DELETE', $libreId, '203.0.113.10') === 1, 'filas ' . audit($pdo, 'CLIENTE_DELETE', $libreId, '203.0.113.10'));

    $login = callApi($kernel, 'POST', '/api/v1/auth/login', ['email' => emailOf($pdo, $admin), 'password' => $password]);
    $me = callApi($kernel, 'GET', '/api/v1/auth/me', null, $adminToken);
    $cors = callApi($kernel, 'OPTIONS', '/api/v1/clientes', null, null, ['origin' => 'http://127.0.0.1:5173']);
    record('R0', 'Regresión login, me, formato y CORS', $login['status'] === 200 && $me['status'] === 200 && ($me['body']['data']['scope'] ?? '') === 'GLOBAL' && array_keys($sinRazon['body']) === ['data', 'total', 'status', 'err'] && $cors['status'] === 204 && ($cors['headers']['Access-Control-Allow-Origin'] ?? '') === 'http://127.0.0.1:5173', 'login ' . $login['status'] . '; me ' . $me['status']);
} catch (Throwable $error) {
    $failed = true;
    fwrite(STDERR, 'EXCEPCION ' . $error::class . ' ' . $error->getMessage() . PHP_EOL);
    fwrite(STDERR, $error->getFile() . ':' . $error->getLine() . PHP_EOL);
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

$after = counts($pdo);
record('CLEAN', 'La transacción no dejó datos', $before === $after, json_encode($before) . ' → ' . json_encode($after));
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

function record(string $id, string $name, bool $ok, string $evidence): void
{
    global $results;
    $results[] = ['status' => $ok ? 'PASS' : 'FAIL', 'id' => $id, 'name' => $name, 'evidence' => $evidence];
}

/** @return array{status:int,body:array<string,mixed>,headers:array<string,string>} */
function callApi(Kernel $kernel, string $method, string $path, ?array $json = null, ?string $token = null, array $headers = []): array
{
    $headers = array_merge(['user-agent' => 'bloque1-test', 'x-client-ip' => '203.0.113.10'], $headers);
    if ($token !== null) {
        $headers['authorization'] = 'Bearer ' . $token;
    }
    if ($json !== null) {
        $headers['content-type'] = 'application/json';
    }
    $request = Request::fake($method, $path, $headers, $json === null ? null : json_encode($json, JSON_THROW_ON_ERROR));
    $response = $kernel->handle($request);

    return [
        'status' => $response->status(),
        'body' => $response->status() === 204 ? [] : $response->toArray(),
        'headers' => $response->headers(),
    ];
}

function user(PDO $pdo, string $nombres, string $apellidos, ?string $rol, string $estado, string $password): int
{
    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]);
    $statement = $pdo->prepare(
        'INSERT INTO usuarios (nombres, apellidos, email, password_hash, estado, eliminado)
         VALUES (:nombres, :apellidos, :email, :password_hash, :estado, 0)'
    );
    $statement->execute([
        'nombres' => $nombres,
        'apellidos' => $apellidos,
        'email' => 'b1.' . bin2hex(random_bytes(5)) . '@test.local',
        'password_hash' => $hash,
        'estado' => $estado,
    ]);
    $id = (int) $pdo->lastInsertId();
    if ($rol !== null) {
        $role = $pdo->prepare('SELECT id FROM roles WHERE codigo = :codigo');
        $role->execute(['codigo' => $rol]);
        $insert = $pdo->prepare('INSERT INTO usuario_roles (usuario_id, rol_id) VALUES (:usuario, :rol)');
        $insert->bindValue('usuario', $id, PDO::PARAM_INT);
        $insert->bindValue('rol', (int) $role->fetchColumn(), PDO::PARAM_INT);
        $insert->execute();
    }

    return $id;
}

function emailOf(PDO $pdo, int $id): string
{
    $statement = $pdo->prepare('SELECT email FROM usuarios WHERE id = :id');
    $statement->bindValue('id', $id, PDO::PARAM_INT);
    $statement->execute();

    return (string) $statement->fetchColumn();
}

function token(Kernel $kernel, string $email, string $password): string
{
    $response = callApi($kernel, 'POST', '/api/v1/auth/login', ['email' => $email, 'password' => $password]);
    if (($response['body']['data']['token'] ?? null) === null) {
        throw new RuntimeException('No se pudo autenticar ' . $email . ' HTTP ' . $response['status']);
    }

    return (string) $response['body']['data']['token'];
}

/** @return array{status:int,body:array<string,mixed>,headers:array<string,string>} */
function crearCliente(Kernel $kernel, string $token, string $razon, string $estado, string $ruc): array
{
    return callApi($kernel, 'POST', '/api/v1/clientes', [
        'razon_social' => $razon,
        'nombre_comercial' => $razon,
        'ruc_documento' => $ruc,
        'estado' => $estado,
    ], $token);
}

function scope(PDO $pdo, int $usuarioId, int $clienteId, string $inicio): void
{
    $statement = $pdo->prepare(
        'INSERT INTO usuario_clientes (usuario_id, cliente_id, fecha_inicio, fecha_fin, activo)
         VALUES (:usuario, :cliente, :inicio, NULL, 1)'
    );
    $statement->bindValue('usuario', $usuarioId, PDO::PARAM_INT);
    $statement->bindValue('cliente', $clienteId, PDO::PARAM_INT);
    $statement->bindValue('inicio', $inicio);
    $statement->execute();
}

/** @return array<string, mixed> */
function contacto(string $nombre, string $email, bool $principal): array
{
    return [
        'nombre' => $nombre,
        'cargo' => 'Operaciones',
        'telefono' => '999111222',
        'email' => $email,
        'tipo_contacto' => 'OPERATIVO',
        'principal' => $principal,
        'activo' => true,
    ];
}

/** @return array<string, mixed> */
function responsable(int $usuarioId, string $tipo, string $inicio, ?string $fin): array
{
    return [
        'usuario_id' => $usuarioId,
        'tipo_responsabilidad' => $tipo,
        'fecha_inicio' => $inicio,
        'fecha_fin' => $fin,
        'principal' => false,
    ];
}

function audit(PDO $pdo, string $accion, int $entidadId, string $ip): int
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM auditoria WHERE accion = :accion AND entidad_id = :id AND ip = :ip'
    );
    $statement->execute(['accion' => $accion, 'id' => $entidadId, 'ip' => $ip]);

    return (int) $statement->fetchColumn();
}

/** @return array<string, int> */
function counts(PDO $pdo): array
{
    $tables = ['usuarios', 'clientes', 'cliente_contactos', 'cliente_responsables', 'sedes', 'flotas', 'auditoria', 'usuario_clientes'];
    $counts = [];
    foreach ($tables as $table) {
        $counts[$table] = (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }

    return $counts;
}

/** @param array{status:int,body:array<string,mixed>} $response */
function codeOf(array $response): string
{
    return 'HTTP ' . $response['status'] . ' ' . (string) ($response['body']['err']['code'] ?? '');
}
