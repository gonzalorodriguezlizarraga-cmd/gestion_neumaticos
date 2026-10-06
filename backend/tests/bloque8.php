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
$ahora = new DateTimeImmutable('now', new DateTimeZone('America/Lima'));
$deteccion = $ahora->format('Y-m-d H:i:s');
$pdo->beginTransaction();
$failed = false;

try {
    $password = bin2hex(random_bytes(8));
    $admin = user($pdo, 'Admin', 'B8', 'ADMIN_GENERAL', $password);
    $gestor = user($pdo, 'Gestor', 'B8', 'GESTOR_NEUMATICOS', $password);
    $tecnico = user($pdo, 'Tecnico', 'B8', 'TECNICO_INSPECCION', $password);
    $vendedor = user($pdo, 'Vendedor', 'B8', 'VENDEDOR', $password);
    $otro = user($pdo, 'Otro', 'B8', 'VENDEDOR', $password);
    $clienteAdmin = user($pdo, 'Cliente', 'B8', 'ADMIN_CLIENTE', $password);
    $consulta = user($pdo, 'Consulta', 'B8', 'CONSULTA_EJECUTIVA', $password);
    $adminToken = token($kernel, emailOf($pdo, $admin), $password);
    $gestorToken = token($kernel, emailOf($pdo, $gestor), $password);
    $tecnicoToken = token($kernel, emailOf($pdo, $tecnico), $password);
    $vendedorToken = token($kernel, emailOf($pdo, $vendedor), $password);
    $otroToken = token($kernel, emailOf($pdo, $otro), $password);
    $clienteToken = token($kernel, emailOf($pdo, $clienteAdmin), $password);
    $consultaToken = token($kernel, emailOf($pdo, $consulta), $password);
    $sufijo = strtoupper(bin2hex(random_bytes(3)));
    $propio = (int) clienteApi($kernel, $adminToken, 'B8 ' . $sufijo, 'ACTIVO')['body']['data']['id'];
    $ajeno = (int) clienteApi($kernel, $adminToken, 'B8 Ajeno ' . $sufijo, 'ACTIVO')['body']['data']['id'];
    $potencial = (int) clienteApi($kernel, $adminToken, 'B8 Potencial ' . $sufijo, 'POTENCIAL')['body']['data']['id'];
    $inactivo = (int) clienteApi($kernel, $adminToken, 'B8 Inactivo ' . $sufijo, 'ACTIVO')['body']['data']['id'];
    $borrado = (int) clienteApi($kernel, $adminToken, 'B8 Borrado ' . $sufijo, 'ACTIVO')['body']['data']['id'];
    foreach ([$vendedor, $otro, $gestor, $tecnico, $clienteAdmin, $consulta] as $usuario) {
        scope($pdo, $usuario, $propio, $hoy);
    }
    scope($pdo, $vendedor, $potencial, $hoy);
    scope($pdo, $vendedor, $inactivo, $hoy);
    scope($pdo, $otro, $ajeno, $hoy);
    asignar($kernel, $adminToken, $propio, $vendedor, $hoy);
    asignar($kernel, $adminToken, $propio, $otro, $hoy);
    asignar($kernel, $adminToken, $potencial, $vendedor, $hoy);
    asignar($kernel, $adminToken, $inactivo, $vendedor, $hoy);
    asignar($kernel, $adminToken, $borrado, $vendedor, $hoy);
    asignar($kernel, $adminToken, $ajeno, $otro, $hoy);
    callApi($kernel, 'PATCH', '/api/v1/clientes/' . $inactivo . '/estado', ['estado' => 'INACTIVO'], $adminToken);
    callApi($kernel, 'DELETE', '/api/v1/clientes/' . $borrado, null, $adminToken);

    $estados = catalogo(callApi($kernel, 'GET', '/api/v1/estados-oportunidad', null, $adminToken)['body']['data'] ?? []);
    $catalogoPost = callApi($kernel, 'POST', '/api/v1/estados-oportunidad', ['codigo' => 'X'], $adminToken);
    record('5', 'Catálogo de estados solo lectura', isset($estados['ABIERTA'], $estados['EN_SEGUIMIENTO'], $estados['COTIZADA'], $estados['GANADA'], $estados['PERDIDA'], $estados['CANCELADA']) && $catalogoPost['status'] === 404, (string) $catalogoPost['status']);

    $marca = (int) callApi($kernel, 'POST', '/api/v1/marcas-neumatico', ['nombre' => 'Marca B8 ' . $sufijo], $adminToken)['body']['data']['id'];
    $modelo = (int) callApi($kernel, 'POST', '/api/v1/modelos-neumatico', ['marca_id' => $marca, 'nombre' => 'Modelo B8 ' . $sufijo], $adminToken)['body']['data']['id'];
    $medida = (int) callApi($kernel, 'POST', '/api/v1/medidas-neumatico', ['descripcion' => '295/80 R22.5 B8 ' . $sufijo], $adminToken)['body']['data']['id'];
    $tiposAlerta = catalogo(callApi($kernel, 'GET', '/api/v1/tipos-alerta', null, $adminToken)['body']['data'] ?? []);
    $alerta = callApi($kernel, 'POST', '/api/v1/alertas', alertaBody($propio, $tiposAlerta['MANTENIMIENTO_RECOMENDADO'], 'ATENCION', 'Necesidad ' . $sufijo), $adminToken);
    $alertaId = (int) ($alerta['body']['data']['id'] ?? 0);
    $alertaEstado = (string) ($alerta['body']['data']['estado']['codigo'] ?? '');
    $alertaAjena = (int) (callApi($kernel, 'POST', '/api/v1/alertas', alertaBody($ajeno, $tiposAlerta['MANTENIMIENTO_RECOMENDADO'], 'ATENCION', 'Ajena ' . $sufijo), $adminToken)['body']['data']['id'] ?? 0);

    $alta = callApi($kernel, 'POST', '/api/v1/oportunidades', oportunidad($propio, $vendedor, 'Renovación ' . $sufijo, $deteccion, $modelo, $medida), $vendedorToken);
    $opId = (int) ($alta['body']['data']['id'] ?? 0);
    $hist = $alta['body']['data']['historial'][0] ?? [];
    $altaOk = $alta['status'] === 201
        && ($alta['body']['data']['estado']['codigo'] ?? '') === 'ABIERTA'
        && ($alta['body']['data']['origen'] ?? '') === 'MANUAL'
        && array_key_exists('alerta', $alta['body']['data']) && $alta['body']['data']['alerta'] === null
        && array_key_exists('anterior', $hist) && $hist['anterior'] === null
        && ($hist['nuevo']['codigo'] ?? '') === 'ABIERTA'
        && (int) ($alta['body']['data']['responsable']['id'] ?? 0) === $vendedor;
    record('1-4', 'Alta manual ABIERTA con historial', $altaOk, $altaOk ? 'ABIERTA MANUAL' : json_encode(['status' => $alta['status'], 'estado' => $alta['body']['data']['estado']['codigo'] ?? null, 'origen' => $alta['body']['data']['origen'] ?? null, 'alerta' => array_key_exists('alerta', $alta['body']['data'] ?? []) ? $alta['body']['data']['alerta'] : 'ausente', 'hist' => $hist, 'responsable' => $alta['body']['data']['responsable']['id'] ?? null, 'vendedor' => $vendedor], JSON_UNESCAPED_UNICODE));
    $ajenoNombre = callApi($kernel, 'POST', '/api/v1/oportunidades', oportunidad($propio, $otro, 'A nombre de otro', $deteccion, null, null), $vendedorToken);
    record('5b', 'Vendedor no asigna a otro vendedor', $ajenoNombre['status'] === 422, codeOf($ajenoNombre));
    $activaOk = $alta['status'] === 201;
    $potencialOk = callApi($kernel, 'POST', '/api/v1/oportunidades', oportunidad($potencial, $vendedor, 'Potencial ' . $sufijo, $deteccion, null, null), $vendedorToken);
    $inactivoOk = callApi($kernel, 'POST', '/api/v1/oportunidades', oportunidad($inactivo, $vendedor, 'Inactivo', $deteccion, null, null), $vendedorToken);
    $borradoOk = callApi($kernel, 'POST', '/api/v1/oportunidades', oportunidad($borrado, $vendedor, 'Borrado', $deteccion, null, null), $adminToken);
    record('6-9', 'Cliente activo, potencial, inactivo y eliminado', $activaOk && $potencialOk['status'] === 201 && $inactivoOk['status'] === 422 && $borradoOk['status'] === 404, $potencialOk['status'] . '/' . $inactivoOk['status'] . '/' . $borradoOk['status']);
    $conEstado = oportunidad($propio, $vendedor, 'Estado manual', $deteccion, null, null);
    $conEstado['estado_id'] = $estados['GANADA'];
    $conOrigen = oportunidad($propio, $vendedor, 'Origen manual', $deteccion, null, null);
    $conOrigen['origen'] = 'MANUAL';
    $conAlerta = oportunidad($propio, $vendedor, 'Alerta manual', $deteccion, null, null);
    $conAlerta['alerta_id'] = $alertaId;
    $estadoRechazo = callApi($kernel, 'POST', '/api/v1/oportunidades', $conEstado, $vendedorToken);
    $origenRechazo = callApi($kernel, 'POST', '/api/v1/oportunidades', $conOrigen, $vendedorToken);
    $alertaRechazo = callApi($kernel, 'POST', '/api/v1/oportunidades', $conAlerta, $vendedorToken);
    record('10-12', 'estado, origen y alerta no se aceptan en el alta manual', $estadoRechazo['status'] === 422 && $origenRechazo['status'] === 422 && $alertaRechazo['status'] === 422, $estadoRechazo['status'] . '/' . $origenRechazo['status'] . '/' . $alertaRechazo['status']);
    $detalleMal = oportunidad($propio, $vendedor, 'Cantidad', $deteccion, null, null);
    $detalleMal['detalles'] = [['cantidad' => '0', 'precio_estimado' => '10']];
    $precioMal = oportunidad($propio, $vendedor, 'Precio', $deteccion, null, null);
    $precioMal['detalles'] = [['cantidad' => '1', 'precio_estimado' => '-1']];
    $modeloMal = oportunidad($propio, $vendedor, 'Modelo', $deteccion, 999999999, null);
    record('13-16', 'Detalle válido y rechazos de catálogo', count($alta['body']['data']['detalles'] ?? []) === 1 && callApi($kernel, 'POST', '/api/v1/oportunidades', $detalleMal, $vendedorToken)['status'] === 422 && callApi($kernel, 'POST', '/api/v1/oportunidades', $precioMal, $vendedorToken)['status'] === 422 && callApi($kernel, 'POST', '/api/v1/oportunidades', $modeloMal, $vendedorToken)['status'] === 422, 'detalle ' . count($alta['body']['data']['detalles'] ?? []));

    $desde = callApi($kernel, 'POST', '/api/v1/alertas/' . $alertaId . '/crear-oportunidad', ['responsable_comercial_id' => $vendedor, 'titulo' => 'Desde alerta ' . $sufijo, 'detalles' => []], $vendedorToken);
    $desdeId = (int) ($desde['body']['data']['id'] ?? 0);
    $alertaDespues = callApi($kernel, 'GET', '/api/v1/alertas/' . $alertaId, null, $adminToken);
    $otraVez = callApi($kernel, 'POST', '/api/v1/alertas/' . $alertaId . '/crear-oportunidad', ['responsable_comercial_id' => $vendedor, 'titulo' => 'Duplicada', 'detalles' => []], $adminToken);
    $cruce = callApi($kernel, 'POST', '/api/v1/alertas/' . $alertaAjena . '/crear-oportunidad', ['responsable_comercial_id' => $vendedor, 'titulo' => 'Cruce', 'detalles' => []], $vendedorToken);
    record('17-23', 'Oportunidad desde alerta, una sola y sin cerrar la alerta', $desde['status'] === 201 && ($desde['body']['data']['origen'] ?? '') === 'ALERTA' && (int) ($desde['body']['data']['alerta']['id'] ?? 0) === $alertaId && (int) ($desde['body']['data']['cliente']['id'] ?? 0) === $propio && $otraVez['status'] === 409 && codeOf($otraVez) === 'OPORTUNIDAD_ALERTA_EXISTENTE' && $cruce['status'] === 403 && ($alertaDespues['body']['data']['estado']['codigo'] ?? '') === $alertaEstado, $desde['status'] . '/' . $otraVez['status'] . '/' . $cruce['status'] . '/' . ($alertaDespues['body']['data']['estado']['codigo'] ?? ''));

    $seg = transicion($kernel, $vendedorToken, $opId, 'iniciar-seguimiento');
    $cot = transicion($kernel, $vendedorToken, $opId, 'marcar-cotizada');
    $gan = transicion($kernel, $vendedorToken, $opId, 'ganar', ['motivo' => 'Cierra con observación']);
    $directa = crearOp($kernel, $vendedorToken, $propio, $vendedor, 'Directa cotizada ' . $sufijo, $deteccion);
    $directaCot = transicion($kernel, $vendedorToken, $directa, 'marcar-cotizada');
    $salta = crearOp($kernel, $vendedorToken, $propio, $vendedor, 'Salto ganada ' . $sufijo, $deteccion);
    transicion($kernel, $vendedorToken, $salta, 'iniciar-seguimiento');
    $saltaGan = transicion($kernel, $vendedorToken, $salta, 'ganar');
    $perdida = crearOp($kernel, $vendedorToken, $propio, $vendedor, 'Perdida ' . $sufijo, $deteccion);
    $perdidaOk = transicion($kernel, $vendedorToken, $perdida, 'perder', ['motivo' => 'Precio']);
    $sinMotivo = transicion($kernel, $vendedorToken, $perdida, 'perder', []);
    $cancelada = crearOp($kernel, $vendedorToken, $propio, $vendedor, 'Cancelada ' . $sufijo, $deteccion);
    transicion($kernel, $vendedorToken, $cancelada, 'marcar-cotizada');
    $cancelOk = transicion($kernel, $vendedorToken, $cancelada, 'cancelar', ['motivo' => 'El cliente desistió']);
    $sinCancel = transicion($kernel, $vendedorToken, $cancelada, 'cancelar', []);
    $reabre = transicion($kernel, $vendedorToken, $opId, 'iniciar-seguimiento');
    $historial = callApi($kernel, 'GET', '/api/v1/oportunidades/' . $opId . '/historial', null, $vendedorToken);
    $codigosHist = array_map(static function (array $fila): string {
        $anterior = is_array($fila['anterior'] ?? null) ? $fila['anterior']['codigo'] : 'NULL';

        return $anterior . '>' . $fila['nuevo']['codigo'];
    }, $historial['body']['data'] ?? []);
    $audCreate = audit($pdo, 'OPORTUNIDAD_CREATE', $opId);
    $audGan = audit($pdo, 'OPORTUNIDAD_GANADA', $opId);
    record('24-35', 'Workflow, motivo, historial y auditoría', $seg['body']['data']['estado']['codigo'] === 'EN_SEGUIMIENTO' && $cot['body']['data']['estado']['codigo'] === 'COTIZADA' && $gan['body']['data']['estado']['codigo'] === 'GANADA' && $directaCot['body']['data']['estado']['codigo'] === 'COTIZADA' && $saltaGan['body']['data']['estado']['codigo'] === 'GANADA' && $perdidaOk['body']['data']['estado']['codigo'] === 'PERDIDA' && $sinMotivo['status'] === 422 && $cancelOk['body']['data']['estado']['codigo'] === 'CANCELADA' && $sinCancel['status'] === 422 && $reabre['status'] === 409 && $codigosHist === ['NULL>ABIERTA', 'ABIERTA>EN_SEGUIMIENTO', 'EN_SEGUIMIENTO>COTIZADA', 'COTIZADA>GANADA'] && $audCreate === 1 && $audGan === 1, implode(',', $codigosHist) . ' aud ' . $audCreate . '/' . $audGan);

    $editable = crearOp($kernel, $adminToken, $propio, $vendedor, 'Editable ' . $sufijo, $deteccion);
    $edit = callApi($kernel, 'PUT', '/api/v1/oportunidades/' . $editable, [
        'titulo' => 'Editable ajustado',
        'descripcion' => 'Nueva nota',
        'fecha_estimada_necesidad' => '2026-11-10',
        'valor_estimado' => '100.00',
        'moneda' => 'PEN',
        'responsable_comercial_id' => $vendedor,
        'detalles' => [['cantidad' => '2', 'observacion' => 'Genérico']],
    ], $adminToken);
    $fechaFija = callApi($kernel, 'PUT', '/api/v1/oportunidades/' . $editable, ['titulo' => 'X', 'fecha_deteccion' => $deteccion, 'detalles' => []], $adminToken);
    $editFinal = callApi($kernel, 'PUT', '/api/v1/oportunidades/' . $opId, ['titulo' => 'No', 'detalles' => []], $adminToken);
    record('14b', 'Edición abierta y bloqueo de fecha o estado final', $edit['status'] === 200 && ($edit['body']['data']['titulo'] ?? '') === 'Editable ajustado' && count($edit['body']['data']['detalles'] ?? []) === 1 && $fechaFija['status'] === 422 && $editFinal['status'] === 409, $edit['status'] . '/' . $fechaFija['status'] . '/' . $editFinal['status']);

    $baseSeg = crearOp($kernel, $vendedorToken, $propio, $vendedor, 'Seguimiento ' . $sufijo, $deteccion);
    $tiposOk = true;
    foreach (['LLAMADA', 'VISITA', 'REUNION', 'CORREO', 'OTRO'] as $tipo) {
        $altaSeg = callApi($kernel, 'POST', '/api/v1/seguimientos-comerciales', seguimiento($propio, $baseSeg, $tipo, $deteccion, $deteccion), $vendedorToken);
        $tiposOk = $tiposOk && $altaSeg['status'] === 201 && (int) ($altaSeg['body']['data']['usuario_id'] ?? 0) === $vendedor;
    }
    $tipoMal = callApi($kernel, 'POST', '/api/v1/seguimientos-comerciales', seguimiento($propio, $baseSeg, 'FAX', $deteccion, null), $vendedorToken);
    $otroCliente = seguimiento($ajeno, $baseSeg, 'LLAMADA', $deteccion, null);
    $cruceSeg = callApi($kernel, 'POST', '/api/v1/seguimientos-comerciales', $otroCliente, $adminToken);
    $general = seguimiento($propio, null, 'LLAMADA', $deteccion, null);
    $generalOk = callApi($kernel, 'POST', '/api/v1/seguimientos-comerciales', $general, $vendedorToken);
    $suplanta = $general;
    $suplanta['usuario_id'] = $otro;
    $suplantaOk = callApi($kernel, 'POST', '/api/v1/seguimientos-comerciales', $suplanta, $vendedorToken);
    $antes = seguimiento($propio, null, 'LLAMADA', $deteccion, $ahora->modify('-1 day')->format('Y-m-d H:i:s'));
    $antesOk = callApi($kernel, 'POST', '/api/v1/seguimientos-comerciales', $antes, $vendedorToken);
    $borraSeg = callApi($kernel, 'DELETE', '/api/v1/seguimientos-comerciales/1', null, $adminToken);
    $finalSeg = seguimiento($propio, $opId, 'LLAMADA', $deteccion, null);
    $finalSegOk = callApi($kernel, 'POST', '/api/v1/seguimientos-comerciales', $finalSeg, $vendedorToken);
    $listaSeg = callApi($kernel, 'GET', '/api/v1/oportunidades/' . $baseSeg . '/seguimientos', null, $vendedorToken);
    record('42-54', 'Seguimientos, trazabilidad y oportunidad final', $tiposOk && $tipoMal['status'] === 422 && $cruceSeg['status'] === 422 && $generalOk['status'] === 201 && $generalOk['body']['data']['oportunidad_id'] === null && $suplantaOk['status'] === 422 && $antesOk['status'] === 422 && $borraSeg['status'] === 404 && $finalSegOk['status'] === 409 && count($listaSeg['body']['data'] ?? []) === 5, $tipoMal['status'] . '/' . $cruceSeg['status'] . '/' . $finalSegOk['status']);

    $opCot = crearOp($kernel, $vendedorToken, $propio, $vendedor, 'Cotizar ' . $sufijo, $deteccion);
    $cotizacion = callApi($kernel, 'POST', '/api/v1/cotizaciones', cotizacion($propio, $opCot, [['descripcion' => 'Neumático', 'modelo_neumatico_id' => $modelo, 'medida_neumatico_id' => $medida, 'cantidad' => '4', 'precio_unitario' => '1150']]), $vendedorToken);
    $cotId = (int) ($cotizacion['body']['data']['id'] ?? 0);
    $numero = (string) ($cotizacion['body']['data']['numero'] ?? '');
    $linea = $cotizacion['body']['data']['detalles'][0] ?? [];
    $segunda = callApi($kernel, 'POST', '/api/v1/cotizaciones', cotizacion($propio, null, [['descripcion' => 'Servicio', 'cantidad' => '1', 'precio_unitario' => '10.50'], ['descripcion' => 'Extra', 'cantidad' => '2', 'precio_unitario' => '3']]), $vendedorToken);
    $manualEstado = cotizacion($propio, null, [['descripcion' => 'X', 'cantidad' => '1', 'precio_unitario' => '1']]);
    $manualEstado['estado'] = 'ENVIADA';
    $manualSub = cotizacion($propio, null, [['descripcion' => 'X', 'cantidad' => '1', 'precio_unitario' => '1']]);
    $manualSub['subtotal'] = '1.00';
    $manualTot = cotizacion($propio, null, [['descripcion' => 'X', 'cantidad' => '1', 'precio_unitario' => '1']]);
    $manualTot['total'] = '1.00';
    $cantMal = cotizacion($propio, null, [['descripcion' => 'X', 'cantidad' => '0', 'precio_unitario' => '1']]);
    $precioCot = cotizacion($propio, null, [['descripcion' => 'X', 'cantidad' => '1', 'precio_unitario' => '-5']]);
    $monedaMal = cotizacion($propio, null, [['descripcion' => 'X', 'cantidad' => '1', 'precio_unitario' => '1']]);
    $monedaMal['moneda'] = 'peso';
    $editCot = callApi($kernel, 'PUT', '/api/v1/cotizaciones/' . $cotId, [
        'fecha' => '2026-10-06',
        'moneda' => 'USD',
        'observacion' => 'Ajustada',
        'detalles' => [['descripcion' => 'Neumático', 'cantidad' => '2', 'precio_unitario' => '100']],
    ], $vendedorToken);
    record('55-68', 'Cotización, número, cálculo y edición de borrador', $cotizacion['status'] === 201 && ($cotizacion['body']['data']['estado'] ?? '') === 'BORRADOR' && preg_match('/^COT-\d{8}-[A-F0-9]{6}$/', $numero) === 1 && $numero !== ($segunda['body']['data']['numero'] ?? '') && (string) ($linea['subtotal'] ?? '') === '4600.00' && (string) ($cotizacion['body']['data']['subtotal'] ?? '') === '4600.00' && (string) ($cotizacion['body']['data']['total'] ?? '') === '4600.00' && (string) ($segunda['body']['data']['total'] ?? '') === '16.50' && callApi($kernel, 'POST', '/api/v1/cotizaciones', $manualEstado, $vendedorToken)['status'] === 422 && callApi($kernel, 'POST', '/api/v1/cotizaciones', $manualSub, $vendedorToken)['status'] === 422 && callApi($kernel, 'POST', '/api/v1/cotizaciones', $manualTot, $vendedorToken)['status'] === 422 && callApi($kernel, 'POST', '/api/v1/cotizaciones', $cantMal, $vendedorToken)['status'] === 422 && callApi($kernel, 'POST', '/api/v1/cotizaciones', $precioCot, $vendedorToken)['status'] === 422 && callApi($kernel, 'POST', '/api/v1/cotizaciones', $monedaMal, $vendedorToken)['status'] === 422 && $editCot['status'] === 200 && (string) ($editCot['body']['data']['total'] ?? '') === '200.00', $numero . ' ' . ($linea['subtotal'] ?? '') . ' edit ' . $editCot['status']);

    $enviada = callApi($kernel, 'POST', '/api/v1/cotizaciones/' . $cotId . '/enviar', [], $vendedorToken);
    $opTrasEnvio = callApi($kernel, 'GET', '/api/v1/oportunidades/' . $opCot, null, $vendedorToken);
    $histCot = array_map(static fn (array $fila): string => (string) $fila['nuevo']['codigo'], $opTrasEnvio['body']['data']['historial'] ?? []);
    $noEdita = callApi($kernel, 'PUT', '/api/v1/cotizaciones/' . $cotId, ['fecha' => '2026-10-07', 'moneda' => 'USD', 'detalles' => [['descripcion' => 'X', 'cantidad' => '1', 'precio_unitario' => '1']]], $vendedorToken);
    $opRechazo = crearOp($kernel, $vendedorToken, $propio, $vendedor, 'Rechazo ' . $sufijo, $deteccion);
    $cotRechazo = (int) (callApi($kernel, 'POST', '/api/v1/cotizaciones', cotizacion($propio, $opRechazo, [['descripcion' => 'Alt', 'cantidad' => '1', 'precio_unitario' => '20']]), $vendedorToken)['body']['data']['id'] ?? 0);
    callApi($kernel, 'POST', '/api/v1/cotizaciones/' . $cotRechazo . '/enviar', [], $vendedorToken);
    $rechazada = callApi($kernel, 'POST', '/api/v1/cotizaciones/' . $cotRechazo . '/rechazar', [], $vendedorToken);
    $opTrasRechazo = callApi($kernel, 'GET', '/api/v1/oportunidades/' . $opRechazo, null, $vendedorToken);
    $opAnula = crearOp($kernel, $vendedorToken, $propio, $vendedor, 'Anula borrador ' . $sufijo, $deteccion);
    $cotBorrador = (int) (callApi($kernel, 'POST', '/api/v1/cotizaciones', cotizacion($propio, $opAnula, [['descripcion' => 'B', 'cantidad' => '1', 'precio_unitario' => '5']]), $vendedorToken)['body']['data']['id'] ?? 0);
    $anuladaB = callApi($kernel, 'POST', '/api/v1/cotizaciones/' . $cotBorrador . '/anular', [], $vendedorToken);
    $opAnulaEnv = crearOp($kernel, $vendedorToken, $propio, $vendedor, 'Anula enviada ' . $sufijo, $deteccion);
    $cotEnv = (int) (callApi($kernel, 'POST', '/api/v1/cotizaciones', cotizacion($propio, $opAnulaEnv, [['descripcion' => 'E', 'cantidad' => '1', 'precio_unitario' => '5']]), $vendedorToken)['body']['data']['id'] ?? 0);
    callApi($kernel, 'POST', '/api/v1/cotizaciones/' . $cotEnv . '/enviar', [], $vendedorToken);
    $anuladaE = callApi($kernel, 'POST', '/api/v1/cotizaciones/' . $cotEnv . '/anular', [], $vendedorToken);
    $opAnulaEstado = callApi($kernel, 'GET', '/api/v1/oportunidades/' . $opAnulaEnv, null, $vendedorToken);
    $aceptada = callApi($kernel, 'POST', '/api/v1/cotizaciones/' . $cotId . '/aceptar', [], $vendedorToken);
    $opGanada = callApi($kernel, 'GET', '/api/v1/oportunidades/' . $opCot, null, $vendedorToken);
    $ganadas = array_values(array_filter($opGanada['body']['data']['historial'] ?? [], static fn (array $fila): bool => ($fila['nuevo']['codigo'] ?? '') === 'GANADA'));
    $otraAceptacion = callApi($kernel, 'POST', '/api/v1/cotizaciones/' . $cotId . '/aceptar', [], $adminToken);
    $valor = (string) scalar($pdo, 'SELECT valor_estimado FROM oportunidades WHERE id = :id', $opCot);
    record('69-78', 'Estados de cotización e integración con la oportunidad', $enviada['status'] === 200 && ($opTrasEnvio['body']['data']['estado']['codigo'] ?? '') === 'COTIZADA' && in_array('COTIZADA', $histCot, true) && $noEdita['status'] === 409 && $rechazada['body']['data']['estado'] === 'RECHAZADA' && ($opTrasRechazo['body']['data']['estado']['codigo'] ?? '') === 'COTIZADA' && $anuladaB['body']['data']['estado'] === 'ANULADA' && $anuladaE['body']['data']['estado'] === 'ANULADA' && ($opAnulaEstado['body']['data']['estado']['codigo'] ?? '') === 'COTIZADA' && $aceptada['body']['data']['estado'] === 'ACEPTADA' && ($opGanada['body']['data']['estado']['codigo'] ?? '') === 'GANADA' && count($ganadas) === 1 && $otraAceptacion['status'] === 409 && $valor === '8500.00' && audit($pdo, 'COTIZACION_ACEPTADA', $cotId) === 1 && audit($pdo, 'OPORTUNIDAD_GANADA', $opCot) === 1, ($opTrasEnvio['body']['data']['estado']['codigo'] ?? '') . '/' . ($opGanada['body']['data']['estado']['codigo'] ?? '') . '/' . $valor);

    $listaAdmin = callApi($kernel, 'GET', '/api/v1/oportunidades?search=' . rawurlencode($sufijo) . '&limit=50', null, $adminToken);
    $listaVend = callApi($kernel, 'GET', '/api/v1/oportunidades?limit=50', null, $vendedorToken);
    $deOtro = crearOp($kernel, $otroToken, $ajeno, $otro, 'Ajena ' . $sufijo, $deteccion);
    $veAjena = callApi($kernel, 'GET', '/api/v1/oportunidades/' . $deOtro, null, $vendedorToken);
    $cotAjena = (int) (callApi($kernel, 'POST', '/api/v1/cotizaciones', cotizacion($ajeno, $deOtro, [['descripcion' => 'Ajena', 'cantidad' => '1', 'precio_unitario' => '9']]), $otroToken)['body']['data']['id'] ?? 0);
    $veCot = callApi($kernel, 'GET', '/api/v1/cotizaciones/' . $cotAjena, null, $vendedorToken);
    $gestorLee = callApi($kernel, 'GET', '/api/v1/oportunidades/' . $editable, null, $gestorToken);
    $gestorEscribe = callApi($kernel, 'POST', '/api/v1/oportunidades', oportunidad($propio, $vendedor, 'Gestor', $deteccion, null, null), $gestorToken);
    $tecnicoLee = callApi($kernel, 'GET', '/api/v1/oportunidades', null, $tecnicoToken);
    $clienteLee = callApi($kernel, 'GET', '/api/v1/cotizaciones', null, $clienteToken);
    $consultaLee = callApi($kernel, 'GET', '/api/v1/seguimientos-comerciales', null, $consultaToken);
    $clienteAjeno = callApi($kernel, 'POST', '/api/v1/oportunidades', oportunidad($ajeno, $vendedor, 'Fuera', $deteccion, null, null), $vendedorToken);
    $montaje = callApi($kernel, 'POST', '/api/v1/montajes', ['cliente_id' => $propio], $vendedorToken);
    $idsVend = array_map(static fn (array $fila): int => (int) $fila['responsable']['id'], $listaVend['body']['data'] ?? []);
    record('86-96', 'Scope, IDOR y sin acceso técnico extra', $listaAdmin['status'] === 200 && ($listaAdmin['body']['total'] ?? 0) >= 2 && $listaVend['status'] === 200 && $idsVend !== [] && count(array_unique($idsVend)) === 1 && $idsVend[0] === $vendedor && $veAjena['status'] === 403 && $veCot['status'] === 403 && $gestorLee['status'] === 200 && $gestorEscribe['status'] === 403 && $tecnicoLee['status'] === 403 && $clienteLee['status'] === 403 && $consultaLee['status'] === 403 && $clienteAjeno['status'] === 403 && $montaje['status'] === 403, $veAjena['status'] . '/' . $gestorLee['status'] . '/' . $tecnicoLee['status'] . '/' . $montaje['status']);

    $clientesVend = callApi($kernel, 'GET', '/api/v1/comercial/clientes?limit=50', null, $vendedorToken);
    $idsClientes = array_map(static fn (array $fila): int => (int) $fila['id'], $clientesVend['body']['data'] ?? []);
    $resumen = callApi($kernel, 'GET', '/api/v1/comercial/clientes/' . $propio . '/resumen', null, $vendedorToken);
    record('clientes', 'Clientes comerciales vigentes y resumen', $clientesVend['status'] === 200 && in_array($propio, $idsClientes, true) && in_array($potencial, $idsClientes, true) && in_array($inactivo, $idsClientes, true) && !in_array($ajeno, $idsClientes, true) && $resumen['status'] === 200 && isset($resumen['body']['data']['oportunidades_abiertas']), implode(',', $idsClientes));
} catch (Throwable $error) {
    $failed = true;
    echo 'EXCEPCION ' . $error->getMessage() . ' en ' . $error->getFile() . ':' . $error->getLine() . PHP_EOL;
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

$after = counts($pdo);
record('CLEAN', 'La transacción no dejó datos', $before === $after, $before === $after ? 'sin cambios' : json_encode(['antes' => $before, 'despues' => $after]));
concurrenciaOportunidad($kernel, $pdo);
concurrenciaCotizacion($kernel, $pdo);

$pass = 0;
$fail = 0;
foreach ($results as $result) {
    echo $result['status'] . ' ' . $result['id'] . ' ' . $result['name'] . ' | ' . $result['evidence'] . PHP_EOL;
    if ($result['status'] === 'PASS') {
        $pass++;
    } else {
        $fail++;
    }
}
echo "RESUMEN pass=$pass fail=$fail" . PHP_EOL;
exit($failed || $fail > 0 ? 1 : 0);

function concurrenciaOportunidad(Kernel $kernel, PDO $pdo): void
{
    $creados = [];
    $signal = 'oportunidad-hold-' . bin2hex(random_bytes(6));
    $signalPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $signal;
    try {
        $password = bin2hex(random_bytes(8));
        $admin = user($pdo, 'Admin', 'Conc', 'ADMIN_GENERAL', $password);
        $vendedor = user($pdo, 'Vend', 'Conc', 'VENDEDOR', $password);
        $creados = ['admin' => $admin, 'vendedor' => $vendedor];
        $adminToken = token($kernel, emailOf($pdo, $admin), $password);
        $vendedorToken = token($kernel, emailOf($pdo, $vendedor), $password);
        $clienteId = (int) clienteApi($kernel, $adminToken, 'B8C ' . strtoupper(bin2hex(random_bytes(3))), 'ACTIVO')['body']['data']['id'];
        $creados['cliente'] = $clienteId;
        scope($pdo, $vendedor, $clienteId, (string) $pdo->query('SELECT CURRENT_DATE')->fetchColumn());
        asignar($kernel, $adminToken, $clienteId, $vendedor, (string) $pdo->query('SELECT CURRENT_DATE')->fetchColumn());
        $id = crearOp($kernel, $adminToken, $clienteId, $vendedor, 'Concurrente', (new DateTimeImmutable('now', new DateTimeZone('America/Lima')))->format('Y-m-d H:i:s'));
        $creados['oportunidad'] = $id;
        $primera = proceso('bloque8_cerrar_worker.php', $id, $adminToken, 4000, $signal);
        $avisada = esperar($signalPath);
        $segunda = proceso('bloque8_cerrar_worker.php', $id, $adminToken, 0, '');
        $ganadora = cerrar($primera);
        $perdedora = cerrar($segunda);
        $estados = [$ganadora['status'], $perdedora['status']];
        sort($estados);
        $codigos = array_values(array_filter([$ganadora['code'], $perdedora['code']]));
        $historial = (int) scalar($pdo, "SELECT COUNT(*) FROM oportunidad_estado_historial h INNER JOIN estados_oportunidad en ON en.id = h.estado_nuevo_id WHERE h.oportunidad_id = :id AND en.codigo = 'CANCELADA'", $id);
        $auditorias = audit($pdo, 'OPORTUNIDAD_CANCELADA', $id);
        $ok = $avisada && $estados === [200, 409] && $codigos === ['OPORTUNIDAD_YA_CERRADA'] && $historial === 1 && $auditorias === 1;
        record('36-41', 'Concurrencia al cerrar oportunidad', $ok, json_encode(['lock' => $avisada, 'http' => $estados, 'codigo' => $codigos, 'historial' => $historial, 'auditorias' => $auditorias, 'stderr' => trim($ganadora['stderr'] . ' ' . $perdedora['stderr'])], JSON_UNESCAPED_UNICODE));
    } catch (Throwable $error) {
        record('36-41', 'Concurrencia al cerrar oportunidad', false, $error->getMessage());
    } finally {
        if (is_file($signalPath)) {
            unlink($signalPath);
        }
        limpiar($pdo, $creados);
    }
}

function concurrenciaCotizacion(Kernel $kernel, PDO $pdo): void
{
    $creados = [];
    $signal = 'cotizacion-hold-' . bin2hex(random_bytes(6));
    $signalPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $signal;
    try {
        $password = bin2hex(random_bytes(8));
        $admin = user($pdo, 'Admin', 'Cot', 'ADMIN_GENERAL', $password);
        $vendedor = user($pdo, 'Vend', 'Cot', 'VENDEDOR', $password);
        $creados = ['admin' => $admin, 'vendedor' => $vendedor];
        $adminToken = token($kernel, emailOf($pdo, $admin), $password);
        $clienteId = (int) clienteApi($kernel, $adminToken, 'B8Q ' . strtoupper(bin2hex(random_bytes(3))), 'ACTIVO')['body']['data']['id'];
        $creados['cliente'] = $clienteId;
        scope($pdo, $vendedor, $clienteId, (string) $pdo->query('SELECT CURRENT_DATE')->fetchColumn());
        asignar($kernel, $adminToken, $clienteId, $vendedor, (string) $pdo->query('SELECT CURRENT_DATE')->fetchColumn());
        $op = crearOp($kernel, $adminToken, $clienteId, $vendedor, 'Cot concurrente', (new DateTimeImmutable('now', new DateTimeZone('America/Lima')))->format('Y-m-d H:i:s'));
        $creados['oportunidad'] = $op;
        $cot = callApi($kernel, 'POST', '/api/v1/cotizaciones', cotizacion($clienteId, $op, [['descripcion' => 'Item', 'cantidad' => '1', 'precio_unitario' => '15']]), $adminToken);
        $cotId = (int) ($cot['body']['data']['id'] ?? 0);
        $creados['cotizacion'] = $cotId;
        callApi($kernel, 'POST', '/api/v1/cotizaciones/' . $cotId . '/enviar', [], $adminToken);
        $primera = proceso('bloque8_aceptar_worker.php', $cotId, $adminToken, 4000, $signal);
        $avisada = esperar($signalPath);
        $segunda = proceso('bloque8_aceptar_worker.php', $cotId, $adminToken, 0, '');
        $ganadora = cerrar($primera);
        $perdedora = cerrar($segunda);
        $estados = [$ganadora['status'], $perdedora['status']];
        sort($estados);
        $codigos = array_values(array_filter([$ganadora['code'], $perdedora['code']]));
        $historial = (int) scalar($pdo, "SELECT COUNT(*) FROM oportunidad_estado_historial h INNER JOIN estados_oportunidad en ON en.id = h.estado_nuevo_id WHERE h.oportunidad_id = :id AND en.codigo = 'GANADA'", $op);
        $auditorias = audit($pdo, 'COTIZACION_ACEPTADA', $cotId);
        $ok = $avisada && $estados === [200, 409] && $codigos === ['COTIZACION_YA_CERRADA'] && $historial === 1 && $auditorias === 1 && $ganadora['status'] !== 500 && $perdedora['status'] !== 500;
        record('79-85', 'Concurrencia al aceptar cotización', $ok, json_encode(['lock' => $avisada, 'http' => $estados, 'codigo' => $codigos, 'historial' => $historial, 'auditorias' => $auditorias], JSON_UNESCAPED_UNICODE));
    } catch (Throwable $error) {
        record('79-85', 'Concurrencia al aceptar cotización', false, $error->getMessage());
    } finally {
        if (is_file($signalPath)) {
            unlink($signalPath);
        }
        limpiar($pdo, $creados);
    }
}

/** @param array<string, int> $creados */
function limpiar(PDO $pdo, array $creados): void
{
    $cotizacion = (int) ($creados['cotizacion'] ?? 0);
    $oportunidad = (int) ($creados['oportunidad'] ?? 0);
    $cliente = (int) ($creados['cliente'] ?? 0);
    if ($cotizacion > 0) {
        $pdo->prepare('DELETE FROM cotizacion_detalles WHERE cotizacion_id = :id')->execute(['id' => $cotizacion]);
        $pdo->prepare('DELETE FROM cotizaciones WHERE id = :id')->execute(['id' => $cotizacion]);
    }
    if ($oportunidad > 0) {
        $pdo->prepare('DELETE FROM seguimientos_comerciales WHERE oportunidad_id = :id')->execute(['id' => $oportunidad]);
        $pdo->prepare('DELETE FROM oportunidad_detalles WHERE oportunidad_id = :id')->execute(['id' => $oportunidad]);
        $pdo->prepare('DELETE FROM oportunidad_estado_historial WHERE oportunidad_id = :id')->execute(['id' => $oportunidad]);
        $pdo->prepare('DELETE FROM cotizacion_detalles WHERE cotizacion_id IN (SELECT id FROM cotizaciones WHERE oportunidad_id = :id)')->execute(['id' => $oportunidad]);
        $pdo->prepare('DELETE FROM cotizaciones WHERE oportunidad_id = :id')->execute(['id' => $oportunidad]);
        $pdo->prepare('DELETE FROM oportunidades WHERE id = :id')->execute(['id' => $oportunidad]);
    }
    if ($cliente > 0) {
        $pdo->prepare('DELETE FROM auditoria WHERE cliente_id = :id')->execute(['id' => $cliente]);
        $pdo->prepare('DELETE FROM cliente_responsables WHERE cliente_id = :id')->execute(['id' => $cliente]);
        $pdo->prepare('DELETE FROM usuario_clientes WHERE cliente_id = :id')->execute(['id' => $cliente]);
        $pdo->prepare('DELETE FROM clientes WHERE id = :id')->execute(['id' => $cliente]);
    }
    foreach (['admin', 'vendedor'] as $clave) {
        $usuario = (int) ($creados[$clave] ?? 0);
        if ($usuario > 0) {
            $pdo->prepare('DELETE FROM usuario_roles WHERE usuario_id = :id')->execute(['id' => $usuario]);
            $pdo->prepare('DELETE FROM usuarios WHERE id = :id')->execute(['id' => $usuario]);
        }
    }
}

function esperar(string $signalPath): bool
{
    $limite = microtime(true) + 8;
    while (microtime(true) < $limite) {
        if (is_file($signalPath)) {
            return true;
        }
        usleep(40000);
    }

    return false;
}

/** @return array{process:resource,pipes:array<int,resource>} */
function proceso(string $script, int $id, string $token, int $holdMs, string $signal): array
{
    $comando = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/' . $script)
        . ' ' . $id . ' ' . escapeshellarg($token) . ' ' . $holdMs . ' ' . escapeshellarg($signal);
    $pipes = [];
    $proceso = proc_open($comando, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
    if (!is_resource($proceso)) {
        throw new RuntimeException('No se pudo abrir el proceso concurrente.');
    }

    return ['process' => $proceso, 'pipes' => $pipes];
}

/** @param array{process:resource,pipes:array<int,resource>} $proceso @return array{status:int,code:?string,stderr:string} */
function cerrar(array $proceso): array
{
    fclose($proceso['pipes'][0]);
    $salida = stream_get_contents($proceso['pipes'][1]);
    $error = stream_get_contents($proceso['pipes'][2]);
    fclose($proceso['pipes'][1]);
    fclose($proceso['pipes'][2]);
    proc_close($proceso['process']);
    $json = json_decode((string) $salida, true);

    return [
        'status' => (int) ($json['status'] ?? 0),
        'code' => isset($json['code']) ? (string) $json['code'] : null,
        'stderr' => (string) $error . (isset($json['message']) ? ' ' . $json['message'] : ''),
    ];
}

function record(string $id, string $name, bool $ok, string $evidence): void
{
    global $results;
    $results[] = ['status' => $ok ? 'PASS' : 'FAIL', 'id' => $id, 'name' => $name, 'evidence' => $evidence];
}

/** @param array<string, mixed>|null $json @return array{status:int,body:array<string,mixed>} */
function callApi(Kernel $kernel, string $method, string $path, ?array $json, ?string $token): array
{
    $headers = ['user-agent' => 'bloque8-test', 'x-client-ip' => '203.0.113.27'];
    if ($token !== null) {
        $headers['authorization'] = 'Bearer ' . $token;
    }
    if ($json !== null) {
        $headers['content-type'] = 'application/json';
    }
    $response = $kernel->handle(Request::fake($method, $path, $headers, $json === null ? null : json_encode($json, JSON_THROW_ON_ERROR)));

    return ['status' => $response->status(), 'body' => $response->status() === 204 ? [] : $response->toArray()];
}

function user(PDO $pdo, string $nombres, string $apellidos, string $rol, string $password): int
{
    $statement = $pdo->prepare('INSERT INTO usuarios (nombres, apellidos, email, password_hash, estado, eliminado) VALUES (:nombres, :apellidos, :email, :password_hash, \'ACTIVO\', 0)');
    $statement->execute([
        'nombres' => $nombres,
        'apellidos' => $apellidos,
        'email' => 'b8.' . bin2hex(random_bytes(5)) . '@test.local',
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
        throw new RuntimeException('No se pudo autenticar ' . $email . ' HTTP ' . $response['status'] . ' ' . json_encode($response['body']));
    }

    return (string) $response['body']['data']['token'];
}

/** @return array{status:int,body:array<string,mixed>} */
function clienteApi(Kernel $kernel, string $token, string $razon, string $estado = 'ACTIVO'): array
{
    return callApi($kernel, 'POST', '/api/v1/clientes', [
        'razon_social' => $razon,
        'nombre_comercial' => $razon,
        'ruc_documento' => strtoupper(bin2hex(random_bytes(6))),
        'estado' => $estado,
    ], $token);
}

function scope(PDO $pdo, int $usuarioId, int $clienteId, string $inicio): void
{
    $statement = $pdo->prepare('INSERT INTO usuario_clientes (usuario_id, cliente_id, fecha_inicio, fecha_fin, activo) VALUES (:usuario, :cliente, :inicio, NULL, 1)');
    $statement->bindValue('usuario', $usuarioId, PDO::PARAM_INT);
    $statement->bindValue('cliente', $clienteId, PDO::PARAM_INT);
    $statement->bindValue('inicio', $inicio);
    $statement->execute();
}

function asignar(Kernel $kernel, string $token, int $clienteId, int $usuarioId, string $inicio): void
{
    $response = callApi($kernel, 'POST', '/api/v1/clientes/' . $clienteId . '/responsables', [
        'usuario_id' => $usuarioId,
        'tipo_responsabilidad' => 'COMERCIAL',
        'fecha_inicio' => $inicio,
        'principal' => false,
    ], $token);
    if ($response['status'] !== 201) {
        throw new RuntimeException('No se pudo asignar comercial HTTP ' . $response['status'] . ' ' . json_encode($response['body']));
    }
}

/** @param list<array<string, mixed>> $rows @return array<string, int> */
function catalogo(array $rows): array
{
    $mapa = [];
    foreach ($rows as $row) {
        $mapa[(string) $row['codigo']] = (int) $row['id'];
    }

    return $mapa;
}

/** @return array<string, mixed> */
function alertaBody(int $cliente, int $tipo, string $nivel, string $titulo): array
{
    return [
        'cliente_id' => $cliente,
        'tipo_alerta_id' => $tipo,
        'nivel' => $nivel,
        'titulo' => $titulo,
        'descripcion' => 'Descripción de ' . $titulo,
        'recomendacion' => 'Revisar comercialmente',
    ];
}

/** @return array<string, mixed> */
function oportunidad(int $cliente, int $responsable, string $titulo, string $fecha, ?int $modelo, ?int $medida): array
{
    $detalle = ['cantidad' => '4', 'precio_estimado' => '1200.00', 'observacion' => 'Eje delantero'];
    if ($modelo !== null) {
        $detalle['modelo_neumatico_id'] = $modelo;
    }
    if ($medida !== null) {
        $detalle['medida_neumatico_id'] = $medida;
    }

    return [
        'cliente_id' => $cliente,
        'responsable_comercial_id' => $responsable,
        'titulo' => $titulo,
        'descripcion' => 'Descripción de ' . $titulo,
        'fecha_deteccion' => $fecha,
        'fecha_estimada_necesidad' => '2026-11-10',
        'valor_estimado' => '8500.00',
        'moneda' => 'PEN',
        'detalles' => [$detalle],
    ];
}

function crearOp(Kernel $kernel, string $token, int $cliente, int $responsable, string $titulo, string $fecha): int
{
    $response = callApi($kernel, 'POST', '/api/v1/oportunidades', oportunidad($cliente, $responsable, $titulo, $fecha, null, null), $token);
    if ($response['status'] !== 201) {
        throw new RuntimeException('No se pudo crear la oportunidad HTTP ' . $response['status'] . ' ' . json_encode($response['body']));
    }

    return (int) $response['body']['data']['id'];
}

/** @param array<string, mixed> $body @return array{status:int,body:array<string,mixed>} */
function transicion(Kernel $kernel, string $token, int $id, string $accion, array $body = []): array
{
    return callApi($kernel, 'POST', '/api/v1/oportunidades/' . $id . '/' . $accion, $body, $token);
}

/** @return array<string, mixed> */
function seguimiento(int $cliente, ?int $oportunidad, string $tipo, string $fecha, ?string $proximo): array
{
    return [
        'cliente_id' => $cliente,
        'oportunidad_id' => $oportunidad,
        'tipo' => $tipo,
        'fecha' => $fecha,
        'resultado' => 'Contacto registrado',
        'proximo_seguimiento' => $proximo,
        'observacion' => 'Nota',
    ];
}

/** @param list<array<string, mixed>> $detalles @return array<string, mixed> */
function cotizacion(int $cliente, ?int $oportunidad, array $detalles): array
{
    return [
        'cliente_id' => $cliente,
        'oportunidad_id' => $oportunidad,
        'fecha' => '2026-10-06',
        'moneda' => 'PEN',
        'observacion' => 'Propuesta',
        'detalles' => $detalles,
    ];
}

function audit(PDO $pdo, string $accion, int $id): int
{
    $statement = $pdo->prepare('SELECT COUNT(*) FROM auditoria WHERE accion = :accion AND entidad_id = :id');
    $statement->bindValue('accion', $accion);
    $statement->bindValue('id', $id, PDO::PARAM_INT);
    $statement->execute();

    return (int) $statement->fetchColumn();
}

function scalar(PDO $pdo, string $sql, int $id): mixed
{
    $statement = $pdo->prepare($sql);
    $statement->bindValue('id', $id, PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchColumn();
}

function codeOf(array $response): string
{
    return (string) ($response['body']['err']['code'] ?? $response['status']);
}

/** @return array<string, int> */
function counts(PDO $pdo): array
{
    $tablas = ['usuarios', 'clientes', 'oportunidades', 'cotizaciones', 'seguimientos_comerciales', 'auditoria', 'alertas', 'cliente_responsables'];
    $conteo = [];
    foreach ($tablas as $tabla) {
        $conteo[$tabla] = (int) $pdo->query('SELECT COUNT(*) FROM ' . $tabla)->fetchColumn();
    }

    return $conteo;
}
