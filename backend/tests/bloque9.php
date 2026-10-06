<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Config\Database;
use App\Http\Kernel;
use App\Http\Request;
use App\Support\CsvTabla;
use App\Support\PdfTabla;

$results = [];
$kernel = Kernel::boot(dirname(__DIR__));
$pdo = Database::connection();
$hoy = (string) $pdo->query('SELECT CURRENT_DATE')->fetchColumn();
$antes = (int) $pdo->query('SELECT COUNT(*) FROM neumaticos')->fetchColumn();
$pdo->beginTransaction();

try {
    $password = bin2hex(random_bytes(6));
    $admin = user($pdo, 'Ada', 'Nueve', 'ADMIN_GENERAL', $password);
    $gestor = user($pdo, 'Gael', 'Nueve', 'GESTOR_NEUMATICOS', $password);
    $tecnico = user($pdo, 'Teo', 'Nueve', 'TECNICO_INSPECCION', $password);
    $vendedor = user($pdo, 'Vera', 'Nueve', 'VENDEDOR', $password);
    $portal = user($pdo, 'Ana', 'Cliente', 'ADMIN_CLIENTE', $password);
    $consulta = user($pdo, 'Ciro', 'Consulta', 'CONSULTA_EJECUTIVA', $password);
    $adminToken = token($kernel, emailOf($pdo, $admin), $password);
    $gestorToken = token($kernel, emailOf($pdo, $gestor), $password);
    $tecnicoToken = token($kernel, emailOf($pdo, $tecnico), $password);
    $vendedorToken = token($kernel, emailOf($pdo, $vendedor), $password);
    $portalToken = token($kernel, emailOf($pdo, $portal), $password);
    $consultaToken = token($kernel, emailOf($pdo, $consulta), $password);

    $propio = (int) clienteApi($kernel, $adminToken, 'Cliente B9 ' . bin2hex(random_bytes(2)))['body']['data']['id'];
    $ajeno = (int) clienteApi($kernel, $adminToken, 'Ajeno B9 ' . bin2hex(random_bytes(2)))['body']['data']['id'];
    $limpio = (int) clienteApi($kernel, $adminToken, 'Vacio B9 ' . bin2hex(random_bytes(2)))['body']['data']['id'];
    scope($pdo, $gestor, $propio, $hoy);
    scope($pdo, $tecnico, $propio, $hoy);
    scope($pdo, $portal, $propio, $hoy);
    scope($pdo, $consulta, $propio, $hoy);
    scope($pdo, $vendedor, $propio, $hoy);

    $marca = callApi($kernel, 'POST', '/api/v1/marcas-neumatico', ['nombre' => 'Marca ' . bin2hex(random_bytes(3))], $adminToken);
    $modelo = callApi($kernel, 'POST', '/api/v1/modelos-neumatico', ['marca_id' => (int) $marca['body']['data']['id'], 'nombre' => 'Modelo B9'], $adminToken);
    $medida = callApi($kernel, 'POST', '/api/v1/medidas-neumatico', ['descripcion' => '295/80 R22.5 ' . bin2hex(random_bytes(2))], $adminToken);
    $tipoUnidad = (int) $pdo->query('SELECT id FROM tipos_unidad WHERE activo = 1 ORDER BY id LIMIT 1')->fetchColumn();
    $unidad = callApi($kernel, 'POST', '/api/v1/unidades', [
        'cliente_id' => $propio,
        'tipo_unidad_id' => $tipoUnidad,
        'codigo' => 'U-B9-' . bin2hex(random_bytes(2)),
        'sede_id' => null,
        'flota_id' => null,
        'configuracion_id' => null,
    ], $adminToken);
    $unidadAjena = callApi($kernel, 'POST', '/api/v1/unidades', [
        'cliente_id' => $ajeno,
        'tipo_unidad_id' => $tipoUnidad,
        'codigo' => 'U-AJ-' . bin2hex(random_bytes(2)),
        'sede_id' => null,
        'flota_id' => null,
        'configuracion_id' => null,
    ], $adminToken);
    $neumatico = callApi($kernel, 'POST', '/api/v1/neumaticos', [
        'cliente_id' => $propio,
        'codigo' => 'N-B9-' . bin2hex(random_bytes(2)),
        'modelo_id' => (int) $modelo['body']['data']['id'],
        'medida_id' => (int) $medida['body']['data']['id'],
        'profundidad_minima_mm' => '3.00',
        'profundidad_inicial_mm' => '16.00',
        'numero_serie' => 'SER-B9-' . bin2hex(random_bytes(2)),
    ], $adminToken);
    $neumaticoAjeno = callApi($kernel, 'POST', '/api/v1/neumaticos', [
        'cliente_id' => $ajeno,
        'codigo' => 'N-AJ-' . bin2hex(random_bytes(2)),
        'modelo_id' => (int) $modelo['body']['data']['id'],
        'medida_id' => (int) $medida['body']['data']['id'],
        'profundidad_minima_mm' => '3.00',
    ], $adminToken);
    $unidadId = (int) ($unidad['body']['data']['id'] ?? 0);
    $unidadAjenaId = (int) ($unidadAjena['body']['data']['id'] ?? 0);
    $neuId = (int) ($neumatico['body']['data']['id'] ?? 0);
    $neuAjeno = (int) ($neumaticoAjeno['body']['data']['id'] ?? 0);
    $tipoAlerta = (int) $pdo->query('SELECT id FROM tipos_alerta WHERE activo = 1 ORDER BY id LIMIT 1')->fetchColumn();
    $alerta = callApi($kernel, 'POST', '/api/v1/alertas', [
        'cliente_id' => $propio,
        'tipo_alerta_id' => $tipoAlerta,
        'neumatico_id' => $neuId,
        'nivel' => 'CRITICA',
        'titulo' => '=1+1',
        'descripcion' => '<script>alert(1)</script>',
        'recomendacion' => 'Revisar',
    ], $adminToken);
    $descartada = callApi($kernel, 'POST', '/api/v1/alertas', [
        'cliente_id' => $propio,
        'tipo_alerta_id' => $tipoAlerta,
        'nivel' => 'ATENCION',
        'titulo' => 'Descartar B9',
        'descripcion' => 'No aplica',
        'recomendacion' => 'Ninguna',
    ], $adminToken);
    $descarte = callApi($kernel, 'POST', '/api/v1/alertas/' . (int) ($descartada['body']['data']['id'] ?? 0) . '/descartar', ['observacion' => 'Falso positivo'], $adminToken);
    $sede = callApi($kernel, 'POST', '/api/v1/clientes/' . $propio . '/sedes', ['nombre' => 'Sede B9', 'codigo' => 'SB9'], $adminToken);
    $flota = callApi($kernel, 'POST', '/api/v1/clientes/' . $propio . '/flotas', ['nombre' => 'Flota B9', 'codigo' => 'FB9'], $adminToken);
    $sedeAjena = callApi($kernel, 'POST', '/api/v1/clientes/' . $ajeno . '/sedes', ['nombre' => 'Sede ajena', 'codigo' => 'SAJ'], $adminToken);

    $pdo->prepare('INSERT INTO inspecciones (cliente_id, unidad_id, fecha_inspeccion, tecnico_id, estado, creado_por) VALUES (:cliente, :unidad, NOW(), :tecnico, \'BORRADOR\', :creado)')
        ->execute(['cliente' => $propio, 'unidad' => $unidadId, 'tecnico' => $tecnico, 'creado' => $admin]);
    $pdo->prepare('INSERT INTO inspecciones (cliente_id, unidad_id, fecha_inspeccion, tecnico_id, estado, creado_por) VALUES (:cliente, :unidad, NOW(), :tecnico, \'FINALIZADA\', :creado)')
        ->execute(['cliente' => $propio, 'unidad' => $unidadId, 'tecnico' => $tecnico, 'creado' => $admin]);
    $tipoMov = (int) $pdo->query('SELECT id FROM tipos_movimiento ORDER BY id LIMIT 1')->fetchColumn();
    $pdo->prepare('INSERT INTO movimientos_neumatico (cliente_id, neumatico_id, tipo_movimiento_id, fecha, observacion, usuario_id, anulado) VALUES (:cliente, :neu, :tipo, NOW(), :obs, :usuario, 0)')
        ->execute(['cliente' => $propio, 'neu' => $neuId, 'tipo' => $tipoMov, 'obs' => '+51', 'usuario' => $admin]);
    $motivo = (int) $pdo->query('SELECT id FROM motivos_descarte ORDER BY id LIMIT 1')->fetchColumn();
    $pdo->prepare('INSERT INTO descartes_neumatico (cliente_id, neumatico_id, fecha_descarte, motivo_descarte_id, vida_final, profundidad_final_mm, km_totales, observacion, usuario_id) VALUES (:cliente, :neu, NOW(), :motivo, 1, NULL, NULL, :obs, :usuario)')
        ->execute(['cliente' => $ajeno, 'neu' => $neuAjeno, 'motivo' => $motivo, 'obs' => '@nota', 'usuario' => $admin]);
    $tipoMant = (int) $pdo->query("SELECT id FROM tipos_mantenimiento WHERE codigo = 'REPARACION' LIMIT 1")->fetchColumn();
    $estadoMant = (int) $pdo->query("SELECT id FROM estados_mantenimiento WHERE codigo = 'SOLICITADO' LIMIT 1")->fetchColumn();
    $pdo->prepare('INSERT INTO mantenimientos_neumatico (cliente_id, neumatico_id, tipo_mantenimiento_id, estado_id, fecha_solicitud, costo, moneda, creado_por) VALUES (:cliente, :neu, :tipo, :estado, NOW(), 12.50, \'PEN\', :usuario)')
        ->execute(['cliente' => $propio, 'neu' => $neuId, 'tipo' => $tipoMant, 'estado' => $estadoMant, 'usuario' => $admin]);

    $dash = callApi($kernel, 'GET', '/api/v1/dashboard/operador?cliente_id=' . $propio, null, $adminToken);
    $resumen = $dash['body']['data']['resumen'] ?? [];
    $antesGet = (int) $pdo->query('SELECT COUNT(*) FROM neumaticos')->fetchColumn();
    callApi($kernel, 'GET', '/api/v1/dashboard/operador?cliente_id=' . $propio, null, $adminToken);
    $despuesGet = (int) $pdo->query('SELECT COUNT(*) FROM neumaticos')->fetchColumn();
    record('1-10', 'Tablero operador con conteos y listas', $dash['status'] === 200
        && ($resumen['clientes_activos'] ?? 0) >= 1
        && ($resumen['unidades_operativas'] ?? 0) >= 1
        && ($resumen['neumaticos']['DISPONIBLE'] ?? 0) >= 1
        && ($dash['body']['data']['alertas']['criticas_activas'] ?? 0) >= 1
        && ($dash['body']['data']['inspecciones']['finalizadas_30_dias'] ?? 0) >= 1
        && ($dash['body']['data']['mantenimiento']['SOLICITADO'] ?? 0) >= 1
        && ($resumen['vidas']['vida_1'] ?? 0) >= 1
        && count($dash['body']['data']['listas']['alertas_criticas'] ?? []) >= 1
        && $antesGet === $despuesGet, (string) $dash['status']);

    $sedeId = (int) ($sede['body']['data']['id'] ?? 0);
    $flotaId = (int) ($flota['body']['data']['id'] ?? 0);
    $porSede = callApi($kernel, 'GET', '/api/v1/dashboard/operador?cliente_id=' . $propio . '&sede_id=' . $sedeId, null, $adminToken);
    $porFlota = callApi($kernel, 'GET', '/api/v1/dashboard/operador?cliente_id=' . $propio . '&flota_id=' . $flotaId, null, $adminToken);
    $sedeAjenaId = (int) ($sedeAjena['body']['data']['id'] ?? 0);
    $cruceSede = callApi($kernel, 'GET', '/api/v1/dashboard/operador?cliente_id=' . $propio . '&sede_id=' . $sedeAjenaId, null, $adminToken);
    $ajenoDash = callApi($kernel, 'GET', '/api/v1/dashboard/operador?cliente_id=' . $ajeno, null, $gestorToken);
    $vacio = callApi($kernel, 'GET', '/api/v1/dashboard/operador?cliente_id=' . $limpio, null, $adminToken);
    record('11-15', 'Filtros de sede y flota, alcance y vacío', $porSede['status'] === 200 && $porFlota['status'] === 200 && $cruceSede['status'] === 422 && $ajenoDash['status'] === 403 && $vacio['status'] === 200 && ($vacio['body']['data']['resumen']['neumaticos']['total_operativos'] ?? 1) === 0, $porSede['status'] . '/' . $cruceSede['status'] . '/' . $ajenoDash['status']);

    $clienteDash = callApi($kernel, 'GET', '/api/v1/dashboard/cliente', null, $portalToken);
    $consultaDash = callApi($kernel, 'GET', '/api/v1/dashboard/cliente', null, $consultaToken);
    $portalOperador = callApi($kernel, 'GET', '/api/v1/dashboard/operador', null, $portalToken);
    $sinComercial = !array_key_exists('oportunidades', $clienteDash['body']['data'] ?? []) && !isset($clienteDash['body']['data']['comercial']);
    $titulos = array_column($clienteDash['body']['data']['listas']['alertas_criticas'] ?? [], 'titulo');
    record('16-26', 'Tablero cliente sin descartadas ni comercial', $clienteDash['status'] === 200 && $consultaDash['status'] === 200 && ($consultaDash['body']['data']['resumido'] ?? false) === true && $portalOperador['status'] === 403 && $sinComercial && in_array('=1+1', $titulos, true) && !in_array('Descartar B9', $titulos, true), $clienteDash['status'] . '/' . $portalOperador['status']);

    $comercial = callApi($kernel, 'GET', '/api/v1/dashboard/comercial', null, $vendedorToken);
    $vendedorTecnico = callApi($kernel, 'GET', '/api/v1/dashboard/operador', null, $vendedorToken);
    record('vendedor', 'Vendedor ve tablero comercial y no el técnico', $comercial['status'] === 200 && isset($comercial['body']['data']['resumen']['abiertas']) && $vendedorTecnico['status'] === 403, $comercial['status'] . '/' . $vendedorTecnico['status']);

    $inventario = callApi($kernel, 'GET', '/api/v1/reportes/inventario-neumaticos?cliente_id=' . $propio, null, $adminToken);
    $fila = $inventario['body']['data']['filas'][0] ?? [];
    $claves = array_column($inventario['body']['data']['columnas'] ?? [], 'key');
    record('27-42', 'Inventario y estado sin IDs y con profundidad nula', $inventario['status'] === 200 && ($fila['codigo'] ?? '') !== '' && $fila['profundidad'] === null && ($fila['criticidad'] ?? '') === 'CRITICA' && !in_array('id', $claves, true)
        && callApi($kernel, 'GET', '/api/v1/reportes/estado-neumaticos?cliente_id=' . $propio, null, $adminToken)['body']['data']['filas'][0]['profundidad_actual'] === null, (string) $inventario['status']);

    $marcaId = (int) $marca['body']['data']['id'];
    $filtrado = callApi($kernel, 'GET', '/api/v1/reportes/inventario-neumaticos?cliente_id=' . $propio . '&marca_id=' . $marcaId . '&estado=DISPONIBLE', null, $adminToken);
    $otroEstado = callApi($kernel, 'GET', '/api/v1/reportes/inventario-neumaticos?cliente_id=' . $propio . '&estado=MONTADO', null, $adminToken);
    record('filtros-inventario', 'Filtro de marca y estado', ($filtrado['body']['total'] ?? 0) >= 1 && ($otroEstado['body']['total'] ?? 1) === 0, (string) ($filtrado['body']['total'] ?? 0));

    $movs = callApi($kernel, 'GET', '/api/v1/reportes/movimientos?cliente_id=' . $propio . '&neumatico_id=' . $neuId, null, $adminToken);
    $movAjeno = callApi($kernel, 'GET', '/api/v1/reportes/movimientos?neumatico_id=' . $neuAjeno, null, $gestorToken);
    record('43-48', 'Movimientos y alcance', $movs['status'] === 200 && ($movs['body']['data']['filas'][0]['observacion'] ?? '') === '+51' && $movAjeno['status'] === 403, $movs['status'] . '/' . $movAjeno['status']);

    $inspPortal = callApi($kernel, 'GET', '/api/v1/reportes/inspecciones', null, $portalToken);
    $inspBorrador = callApi($kernel, 'GET', '/api/v1/reportes/inspecciones?estado=BORRADOR', null, $portalToken);
    $estadosInsp = array_column($inspPortal['body']['data']['filas'] ?? [], 'estado');
    record('49-55', 'Inspecciones del cliente sin borrador', $inspPortal['status'] === 200 && $inspBorrador['status'] === 403 && $estadosInsp !== [] && !in_array('BORRADOR', $estadosInsp, true), $inspPortal['status'] . '/' . $inspBorrador['status']);

    $alertasOp = callApi($kernel, 'GET', '/api/v1/reportes/alertas?cliente_id=' . $propio, null, $adminToken);
    $alertasCli = callApi($kernel, 'GET', '/api/v1/reportes/alertas', null, $portalToken);
    $estadosAlertaOp = array_column($alertasOp['body']['data']['filas'] ?? [], 'estado');
    $estadosAlertaCli = array_column($alertasCli['body']['data']['filas'] ?? [], 'estado');
    $descartaCli = callApi($kernel, 'GET', '/api/v1/reportes/alertas?estado=DESCARTADA', null, $portalToken);
    record('56-60', 'Alertas: operador ve descartadas y el cliente no', in_array('DESCARTADA', $estadosAlertaOp, true) && !in_array('DESCARTADA', $estadosAlertaCli, true) && $descartaCli['status'] === 403, implode(',', $estadosAlertaOp));

    $mant = callApi($kernel, 'GET', '/api/v1/reportes/mantenimientos?cliente_id=' . $propio, null, $adminToken);
    $totales = $mant['body']['data']['totales_por_moneda'] ?? [];
    record('61-66', 'Mantenimiento con costo por moneda', $mant['status'] === 200 && ($mant['body']['data']['filas'][0]['moneda'] ?? '') === 'PEN' && ($totales[0]['moneda'] ?? '') === 'PEN', (string) ($totales[0]['total'] ?? ''));

    $vidas = callApi($kernel, 'GET', '/api/v1/reportes/vidas?cliente_id=' . $propio, null, $adminToken);
    $vida = $vidas['body']['data']['filas'][0] ?? [];
    $descartes = callApi($kernel, 'GET', '/api/v1/reportes/descartes?cliente_id=' . $ajeno, null, $adminToken);
    $descarteFila = $descartes['body']['data']['filas'][0] ?? [];
    record('67-73', 'Vidas con km nulo y descarte', ($vida['numero_vida'] ?? 0) === 1 && $vida['km_inicio'] === null && $vida['km_fin'] === null && ($descarteFila['vida_final'] ?? 0) === 1 && $descarteFila['km_totales'] === null && $descarteFila['profundidad_final'] === null, (string) ($vida['reencauches'] ?? ''));

    $csv = callApi($kernel, 'GET', '/api/v1/reportes/alertas/csv?cliente_id=' . $propio, null, $adminToken);
    $csvTexto = (string) ($csv['body']['data'] ?? '');
    $csvMov = (string) (callApi($kernel, 'GET', '/api/v1/reportes/movimientos/csv?cliente_id=' . $propio, null, $adminToken)['body']['data'] ?? '');
    $csvDes = (string) (callApi($kernel, 'GET', '/api/v1/reportes/descartes/csv?cliente_id=' . $ajeno, null, $adminToken)['body']['data'] ?? '');
    record('82-88', 'CSV UTF-8 y fórmulas neutralizadas', $csv['status'] === 200
        && str_starts_with($csvTexto, "\xEF\xBB\xBF")
        && str_contains($csv['headers']['Content-Type'] ?? '', 'text/csv')
        && str_contains($csvTexto, "'=1+1")
        && str_contains($csvMov, "'+51")
        && str_contains($csvDes, "'@nota")
        && CsvTabla::celda('-nota') === "'-nota"
        && CsvTabla::celda('@x') === "'@x", 'csv ' . $csv['status']);

    $pdf = callApi($kernel, 'GET', '/api/v1/reportes/inventario-neumaticos/pdf?cliente_id=' . $propio, null, $adminToken);
    $pdfBin = (string) ($pdf['body']['data'] ?? '');
    $html = PdfTabla::html('Inventario', '06/10/2026 08:00', ['Cliente: demo'], ['Código'], [['<script>alert(1)</script>']], false);
    record('74-81', 'PDF con fecha, escape y paginación interna', $pdf['status'] === 200 && str_starts_with($pdfBin, '%PDF') && str_contains($pdf['headers']['Content-Type'] ?? '', 'pdf') && str_contains($html, '&lt;script&gt;') && !str_contains($html, '<script>'), (string) $pdf['status'] . ' ' . substr($pdfBin, 0, 8));

    $exportAjeno = callApi($kernel, 'GET', '/api/v1/reportes/inventario-neumaticos/csv?cliente_id=' . $ajeno, null, $portalToken);
    $unidadIdor = callApi($kernel, 'GET', '/api/v1/reportes/inspecciones/pdf?unidad_id=' . $unidadAjenaId, null, $gestorToken);
    $neuIdor = callApi($kernel, 'GET', '/api/v1/reportes/movimientos/csv?neumatico_id=' . $neuAjeno, null, $portalToken);
    $tecnicoMant = callApi($kernel, 'GET', '/api/v1/reportes/mantenimientos', null, $tecnicoToken);
    $vendedorRep = callApi($kernel, 'GET', '/api/v1/reportes/inventario-neumaticos', null, $vendedorToken);
    $tipoMal = callApi($kernel, 'GET', '/api/v1/reportes/clientes', null, $adminToken);
    record('89-91', 'IDOR de exportación y roles', $exportAjeno['status'] === 403 && $unidadIdor['status'] === 403 && $neuIdor['status'] === 403 && $tecnicoMant['status'] === 403 && $vendedorRep['status'] === 403 && $tipoMal['status'] === 422, $exportAjeno['status'] . '/' . $unidadIdor['status'] . '/' . $neuIdor['status'] . '/' . $tipoMal['status']);

    $altas = [$marca['status'], $modelo['status'], $medida['status'], $unidad['status'], $neumatico['status'], $alerta['status'], $descarte['status'], $sede['status'], $flota['status']];
    record('altas', 'Datos de prueba creados', !in_array(0, $altas, true) && min($altas) >= 200 && max($altas) < 300, implode(',', $altas));
} catch (Throwable $error) {
    record('EX', 'Excepción', false, $error->getMessage() . ' ' . $error->getFile() . ':' . $error->getLine());
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

$despues = (int) $pdo->query('SELECT COUNT(*) FROM neumaticos')->fetchColumn();
record('CLEAN', 'La transacción no dejó neumáticos', $antes === $despues, $antes . '→' . $despues);

$fail = 0;
foreach ($results as $result) {
    echo $result['status'] . ' ' . $result['id'] . ' ' . $result['name'] . ' | ' . $result['evidence'] . PHP_EOL;
    if ($result['status'] === 'FAIL') {
        $fail++;
    }
}
echo 'RESUMEN pass=' . (count($results) - $fail) . ' fail=' . $fail . PHP_EOL;
exit($fail === 0 ? 0 : 1);

function record(string $id, string $name, bool $ok, string $evidence): void
{
    global $results;
    $results[] = ['status' => $ok ? 'PASS' : 'FAIL', 'id' => $id, 'name' => $name, 'evidence' => $evidence];
}

/** @param array<string, mixed>|null $json @return array{status:int,body:array<string,mixed>,headers:array<string,string>} */
function callApi(Kernel $kernel, string $method, string $path, ?array $json, ?string $token): array
{
    $headers = ['user-agent' => 'bloque9-test', 'x-client-ip' => '203.0.113.29'];
    if ($token !== null) {
        $headers['authorization'] = 'Bearer ' . $token;
    }
    if ($json !== null) {
        $headers['content-type'] = 'application/json';
    }
    $response = $kernel->handle(Request::fake($method, $path, $headers, $json === null ? null : json_encode($json, JSON_THROW_ON_ERROR)));

    return [
        'status' => $response->status(),
        'headers' => $response->headers(),
        'body' => $response->status() === 204 ? [] : $response->toArray(),
    ];
}

function user(PDO $pdo, string $nombres, string $apellidos, string $rol, string $password): int
{
    $statement = $pdo->prepare('INSERT INTO usuarios (nombres, apellidos, email, password_hash, estado, eliminado) VALUES (:nombres, :apellidos, :email, :password_hash, \'ACTIVO\', 0)');
    $statement->execute([
        'nombres' => $nombres,
        'apellidos' => $apellidos,
        'email' => 'b9.' . bin2hex(random_bytes(5)) . '@test.local',
        'password_hash' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]),
    ]);
    $id = (int) $pdo->lastInsertId();
    $role = $pdo->prepare('SELECT id FROM roles WHERE codigo = :codigo');
    $role->execute(['codigo' => $rol]);
    $insert = $pdo->prepare('INSERT INTO usuario_roles (usuario_id, rol_id) VALUES (:usuario, :rol)');
    $insert->bindValue('usuario', $id, PDO::PARAM_INT);
    $insert->bindValue('rol', (int) $role->fetchColumn(), PDO::PARAM_INT);
    $insert->execute();

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
    $response = callApi($kernel, 'POST', '/api/v1/auth/login', ['email' => $email, 'password' => $password], null);
    if ($response['status'] !== 200) {
        throw new RuntimeException('Login ' . $response['status'] . ' ' . json_encode($response['body']));
    }

    return (string) $response['body']['data']['token'];
}

/** @return array{status:int,body:array<string,mixed>,headers:array<string,string>} */
function clienteApi(Kernel $kernel, string $token, string $razon): array
{
    return callApi($kernel, 'POST', '/api/v1/clientes', [
        'razon_social' => $razon,
        'nombre_comercial' => $razon,
        'ruc_documento' => strtoupper(bin2hex(random_bytes(6))),
        'estado' => 'ACTIVO',
    ], $token);
}

function scope(PDO $pdo, int $usuarioId, int $clienteId, string $inicio): void
{
    $statement = $pdo->prepare('INSERT INTO usuario_clientes (usuario_id, cliente_id, fecha_inicio, activo) VALUES (:usuario, :cliente, :inicio, 1)');
    $statement->bindValue('usuario', $usuarioId, PDO::PARAM_INT);
    $statement->bindValue('cliente', $clienteId, PDO::PARAM_INT);
    $statement->bindValue('inicio', $inicio);
    $statement->execute();
}
