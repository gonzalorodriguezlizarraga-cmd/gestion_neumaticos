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
$solicitud = $ahora->modify('-3 hours')->format('Y-m-d H:i:s');
$envio = $ahora->modify('-2 hours')->format('Y-m-d H:i:s');
$retorno = $ahora->modify('-1 hour')->format('Y-m-d H:i:s');
$pdo->beginTransaction();
$failed = false;

try {
    $password = bin2hex(random_bytes(8));
    $admin = user($pdo, 'Admin', 'B7', 'ADMIN_GENERAL', $password);
    $gestor = user($pdo, 'Gestor', 'B7', 'GESTOR_NEUMATICOS', $password);
    $tecnico = user($pdo, 'Tecnico', 'B7', 'TECNICO_INSPECCION', $password);
    $vendedor = user($pdo, 'Vendedor', 'B7', 'VENDEDOR', $password);
    $clienteAdmin = user($pdo, 'Cliente', 'B7', 'ADMIN_CLIENTE', $password);
    $consulta = user($pdo, 'Consulta', 'B7', 'CONSULTA_EJECUTIVA', $password);
    $adminToken = token($kernel, emailOf($pdo, $admin), $password);
    $gestorToken = token($kernel, emailOf($pdo, $gestor), $password);
    $tecnicoToken = token($kernel, emailOf($pdo, $tecnico), $password);
    $vendedorToken = token($kernel, emailOf($pdo, $vendedor), $password);
    $clienteToken = token($kernel, emailOf($pdo, $clienteAdmin), $password);
    $consultaToken = token($kernel, emailOf($pdo, $consulta), $password);
    $sufijo = strtoupper(bin2hex(random_bytes(3)));
    $propioId = (int) cliente($kernel, $adminToken, 'B7 ' . $sufijo . ' Propio')['body']['data']['id'];
    $ajenoId = (int) cliente($kernel, $adminToken, 'B7 ' . $sufijo . ' Ajeno')['body']['data']['id'];
    scope($pdo, $gestor, $propioId, $hoy);
    scope($pdo, $tecnico, $propioId, $hoy);
    scope($pdo, $vendedor, $propioId, $hoy);
    scope($pdo, $clienteAdmin, $propioId, $hoy);
    scope($pdo, $consulta, $propioId, $hoy);
    $tipos = catalogo(callApi($kernel, 'GET', '/api/v1/tipos-alerta', null, $adminToken)['body']['data'] ?? []);
    $estados = catalogo(callApi($kernel, 'GET', '/api/v1/estados-alerta', null, $adminToken)['body']['data'] ?? []);
    $marca = (int) callApi($kernel, 'POST', '/api/v1/marcas-neumatico', ['nombre' => 'Marca B7 ' . $sufijo], $adminToken)['body']['data']['id'];
    $modelo = (int) callApi($kernel, 'POST', '/api/v1/modelos-neumatico', ['marca_id' => $marca, 'nombre' => 'Modelo B7 ' . $sufijo], $adminToken)['body']['data']['id'];
    $medida = (int) callApi($kernel, 'POST', '/api/v1/medidas-neumatico', ['descripcion' => '11R22.5 B7 ' . $sufijo], $adminToken)['body']['data']['id'];
    $tipoUnidad = (int) $pdo->query("SELECT id FROM tipos_unidad WHERE codigo = 'CAMION'")->fetchColumn();
    $config = callApi($kernel, 'POST', '/api/v1/configuraciones-unidad', configuracion('Cfg B7 ' . $sufijo), $adminToken);
    $configId = (int) $config['body']['data']['id'];
    $posicion = (int) $config['body']['data']['ejes'][0]['posiciones'][0]['id'];
    $unidad = (int) callApi($kernel, 'POST', '/api/v1/unidades', unidadBody($propioId, $tipoUnidad, 'UB7-' . $sufijo, $configId), $adminToken)['body']['data']['id'];
    $unidadAjena = (int) callApi($kernel, 'POST', '/api/v1/unidades', unidadBody($ajenoId, $tipoUnidad, 'UA7-' . $sufijo, $configId), $adminToken)['body']['data']['id'];

    $listaVacia = callApi($kernel, 'GET', '/api/v1/alertas?cliente_id=' . $propioId . '&limit=5&page=1', null, $adminToken);
    $manual = callApi($kernel, 'POST', '/api/v1/alertas', alertaBody($propioId, $tipos['MANTENIMIENTO_RECOMENDADO'], 'ATENCION', 'Revision ' . $sufijo, null, null), $gestorToken);
    $alertaId = (int) ($manual['body']['data']['id'] ?? 0);
    $historial = $manual['body']['data']['historial'][0] ?? [];
    record('1-5', 'Alta manual ABIERTA y no automática', $listaVacia['status'] === 200 && $manual['status'] === 201 && ($manual['body']['data']['estado']['codigo'] ?? '') === 'ABIERTA' && ($manual['body']['data']['generada_automaticamente'] ?? true) === false && ($manual['body']['data']['origen'] ?? '') === 'MANUAL' && array_key_exists('anterior', $historial) && $historial['anterior'] === null, codeOf($manual));
    $ajena = callApi($kernel, 'POST', '/api/v1/alertas', alertaBody($ajenoId, $tipos['MANTENIMIENTO_RECOMENDADO'], 'ATENCION', 'Ajena ' . $sufijo, null, null), $gestorToken);
    $unidadCruzada = callApi($kernel, 'POST', '/api/v1/alertas', alertaBody($propioId, $tipos['MANTENIMIENTO_RECOMENDADO'], 'ATENCION', 'Unidad ajena', $unidadAjena, null), $adminToken);
    $neumAjeno = neumaticoId($kernel, $adminToken, $ajenoId, $modelo, $medida, 'AJE-' . $sufijo);
    $neumCruzado = callApi($kernel, 'POST', '/api/v1/alertas', alertaBody($propioId, $tipos['MANTENIMIENTO_RECOMENDADO'], 'ATENCION', 'Neumático ajeno', null, $neumAjeno), $adminToken);
    $tipoInactivo = (int) $pdo->query('SELECT id FROM tipos_alerta WHERE activo = 1 LIMIT 1')->fetchColumn();
    $pdo->prepare('UPDATE tipos_alerta SET activo = 0 WHERE id = :id')->execute(['id' => $tipoInactivo]);
    $tipoMal = callApi($kernel, 'POST', '/api/v1/alertas', alertaBody($propioId, $tipoInactivo, 'ATENCION', 'Tipo inactivo', null, null), $adminToken);
    $pdo->prepare('UPDATE tipos_alerta SET activo = 1 WHERE id = :id')->execute(['id' => $tipoInactivo]);
    $nivelMal = callApi($kernel, 'POST', '/api/v1/alertas', alertaBody($propioId, $tipos['OTRO'] ?? $tipos['MANTENIMIENTO_RECOMENDADO'], 'URGENTE', 'Nivel', null, null), $adminToken);
    $estadoLibre = callApi($kernel, 'POST', '/api/v1/alertas', alertaBody($propioId, $tipos['MANTENIMIENTO_RECOMENDADO'], 'ATENCION', 'Estado libre', null, null) + ['estado_id' => 1], $adminToken);
    $autoLibre = callApi($kernel, 'POST', '/api/v1/alertas', alertaBody($propioId, $tipos['MANTENIMIENTO_RECOMENDADO'], 'ATENCION', 'Auto libre', null, null) + ['generada_automaticamente' => 1], $adminToken);
    record('6-12', 'Validaciones de alta', $ajena['status'] === 403 && $unidadCruzada['status'] === 422 && $neumCruzado['status'] === 422 && $tipoMal['status'] === 422 && $nivelMal['status'] === 422 && $estadoLibre['status'] === 422 && $autoLibre['status'] === 422, codeOf($ajena) . '/' . codeOf($unidadCruzada) . '/' . codeOf($tipoMal));

    $atencion = callApi($kernel, 'POST', '/api/v1/alertas/' . $alertaId . '/tomar-atencion', ['observacion' => 'Se programó revisión técnica'], $gestorToken);
    $histAtencion = callApi($kernel, 'GET', '/api/v1/alertas/' . $alertaId . '/historial', null, $gestorToken);
    $cambios = $histAtencion['body']['data'] ?? [];
    $ultimo = $cambios[count($cambios) - 1] ?? [];
    record('13-14', 'ABIERTA a EN_ATENCION', $atencion['status'] === 200 && ($atencion['body']['data']['estado']['codigo'] ?? '') === 'EN_ATENCION' && ($ultimo['anterior']['codigo'] ?? '') === 'ABIERTA' && ($ultimo['nuevo']['codigo'] ?? '') === 'EN_ATENCION', codeOf($atencion));
    $sinNota = callApi($kernel, 'POST', '/api/v1/alertas/' . $alertaId . '/atender', [], $gestorToken);
    $atendida = callApi($kernel, 'POST', '/api/v1/alertas/' . $alertaId . '/atender', ['observacion' => 'Se reemplazó el neumático por profundidad mínima'], $gestorToken);
    record('15-16', 'EN_ATENCION a ATENDIDA exige observación', $sinNota['status'] === 422 && $atendida['status'] === 200 && ($atendida['body']['data']['estado']['codigo'] ?? '') === 'ATENDIDA', codeOf($sinNota) . '/' . codeOf($atendida));
    $directa = callApi($kernel, 'POST', '/api/v1/alertas', alertaBody($propioId, $tipos['MANTENIMIENTO_RECOMENDADO'], 'INFORMATIVA', 'Directa ' . $sufijo, null, null), $adminToken);
    $directaId = (int) ($directa['body']['data']['id'] ?? 0);
    $directaFin = callApi($kernel, 'POST', '/api/v1/alertas/' . $directaId . '/atender', ['observacion' => 'Resuelta sin toma previa'], $adminToken);
    $descarte = callApi($kernel, 'POST', '/api/v1/alertas', alertaBody($propioId, $tipos['MANTENIMIENTO_RECOMENDADO'], 'ATENCION', 'Descartar ' . $sufijo, null, null), $adminToken);
    $descarteId = (int) ($descarte['body']['data']['id'] ?? 0);
    $sinDescarte = callApi($kernel, 'POST', '/api/v1/alertas/' . $descarteId . '/descartar', [], $adminToken);
    $descartada = callApi($kernel, 'POST', '/api/v1/alertas/' . $descarteId . '/descartar', ['observacion' => 'Falso positivo'], $adminToken);
    $enCurso = callApi($kernel, 'POST', '/api/v1/alertas', alertaBody($propioId, $tipos['MANTENIMIENTO_RECOMENDADO'], 'ATENCION', 'Curso ' . $sufijo, null, null), $adminToken);
    $enCursoId = (int) ($enCurso['body']['data']['id'] ?? 0);
    callApi($kernel, 'POST', '/api/v1/alertas/' . $enCursoId . '/tomar-atencion', [], $adminToken);
    $descartaCurso = callApi($kernel, 'POST', '/api/v1/alertas/' . $enCursoId . '/descartar', ['observacion' => 'No aplica'], $adminToken);
    $otra = callApi($kernel, 'POST', '/api/v1/alertas/' . $alertaId . '/descartar', ['observacion' => 'Otra vez'], $adminToken);
    $reabrirAtendida = callApi($kernel, 'POST', '/api/v1/alertas/' . $alertaId . '/tomar-atencion', ['observacion' => 'Reabrir'], $adminToken);
    $reabrirDescartada = callApi($kernel, 'POST', '/api/v1/alertas/' . $descarteId . '/atender', ['observacion' => 'Reabrir'], $adminToken);
    record('17-21', 'Atender directa, descartar y estado final', $directaFin['status'] === 200 && $sinDescarte['status'] === 422 && $descartada['status'] === 200 && ($descartada['body']['data']['estado']['codigo'] ?? '') === 'DESCARTADA' && $descartaCurso['status'] === 200 && $otra['status'] === 409 && ($otra['body']['err']['code'] ?? '') === 'ALERTA_ALREADY_CLOSED' && $reabrirAtendida['status'] === 409 && ($reabrirAtendida['body']['err']['code'] ?? '') === 'ALERTA_ALREADY_CLOSED' && $reabrirDescartada['status'] === 409 && ($reabrirDescartada['body']['err']['code'] ?? '') === 'ALERTA_ALREADY_CLOSED', codeOf($otra) . '/' . codeOf($reabrirDescartada));
    record('22', 'Auditorías', audit($pdo, 'ALERTA_CREATE', $alertaId) === 1 && audit($pdo, 'ALERTA_EN_ATENCION', $alertaId) === 1 && audit($pdo, 'ALERTA_ATENDIDA', $alertaId) === 1 && audit($pdo, 'ALERTA_DESCARTADA', $descarteId) === 1, 'ok');
    $filtros = callApi($kernel, 'GET', '/api/v1/alertas?cliente_id=' . $propioId . '&tipo_alerta_id=' . $tipos['MANTENIMIENTO_RECOMENDADO'] . '&estado_id=' . $estados['ATENDIDA'] . '&nivel=ATENCION&generada_automaticamente=0&fecha_inicio=' . $hoy . '&fecha_fin=' . $hoy . '&search=Revision', null, $adminToken);
    record('2', 'Filtros del listado', $filtros['status'] === 200 && ($filtros['body']['total'] ?? 0) === 1, 'total ' . ($filtros['body']['total'] ?? 0));

    $montado = neumaticoId($kernel, $adminToken, $propioId, $modelo, $medida, 'MON-' . $sufijo);
    $montaje = callApi($kernel, 'POST', '/api/v1/montajes', montaje($montado, $unidad, $posicion, $envio, 1000), $adminToken);
    $inspeccion = callApi($kernel, 'POST', '/api/v1/inspecciones', [
        'unidad_id' => $unidad,
        'fecha_inspeccion' => $retorno,
        'kilometraje' => 1200,
        'observacion_general' => 'Profundidad',
    ], $adminToken);
    $inspeccionId = (int) ($inspeccion['body']['data']['id'] ?? 0);
    $detalle = callApi($kernel, 'POST', '/api/v1/inspecciones/' . $inspeccionId . '/detalles', [
        'posicion_id' => $posicion,
        'profundidad_interior_mm' => '2.00',
        'profundidad_centro_mm' => '8.00',
        'profundidad_exterior_mm' => '8.00',
        'presion_psi' => '40',
        'condicion' => 'CRITICO',
    ], $adminToken);
    $detalleId = (int) ($detalle['body']['data']['id'] ?? $detalle['body']['data']['detalles'][0]['id'] ?? 0);
    if ($detalleId === 0) {
        $detalleId = (int) scalar($pdo, 'SELECT id FROM inspeccion_detalles WHERE inspeccion_id = :id', $inspeccionId);
    }
    $tipoCorte = (int) $pdo->query("SELECT id FROM tipos_dano WHERE codigo = 'CORTE'")->fetchColumn();
    $dano = callApi($kernel, 'POST', '/api/v1/inspecciones/' . $inspeccionId . '/detalles/' . $detalleId . '/danos', [
        'tipo_dano_id' => $tipoCorte,
        'severidad' => 'CRITICA',
        'observacion' => 'Corte de prueba',
    ], $adminToken);
    $finInsp = callApi($kernel, 'POST', '/api/v1/inspecciones/' . $inspeccionId . '/finalizar', [], $adminToken);
    $auto = callApi($kernel, 'GET', '/api/v1/alertas?cliente_id=' . $propioId . '&neumatico_id=' . $montado . '&generada_automaticamente=1', null, $adminToken);
    $codigosAuto = array_map(static fn (array $row): string => (string) $row['tipo']['codigo'], $auto['body']['data'] ?? []);
    $critica = null;
    foreach ($auto['body']['data'] ?? [] as $row) {
        if (($row['tipo']['codigo'] ?? '') === 'PROFUNDIDAD_CRITICA') {
            $critica = $row;
        }
    }
    $antesOrigen = $critica['generada_automaticamente'] ?? false;
    $atendidaAuto = $critica === null ? ['status' => 0, 'body' => []] : callApi($kernel, 'POST', '/api/v1/alertas/' . $critica['id'] . '/atender', ['observacion' => 'Se programó el reemplazo'], $adminToken);
    $despues = $critica === null ? [] : callApi($kernel, 'GET', '/api/v1/alertas/' . $critica['id'], null, $adminToken)['body']['data'];
    $presion = (int) scalar($pdo, "SELECT COUNT(*) FROM alertas a INNER JOIN tipos_alerta t ON t.id = a.tipo_alerta_id WHERE a.inspeccion_id = :id AND t.codigo = 'PRESION_BAJA'", $inspeccionId);
    $irregular = (int) scalar($pdo, "SELECT COUNT(*) FROM alertas a INNER JOIN tipos_alerta t ON t.id = a.tipo_alerta_id WHERE a.inspeccion_id = :id AND t.codigo = 'DESGASTE_IRREGULAR'", $inspeccionId);
    record('23-30', 'Automáticas existentes y sin reglas inventadas', $montaje['status'] === 201 && $detalle['status'] === 201 && $dano['status'] === 201 && $finInsp['status'] === 200 && in_array('PROFUNDIDAD_CRITICA', $codigosAuto, true) && in_array('DANO_DETECTADO', $codigosAuto, true) && $antesOrigen === true && $atendidaAuto['status'] === 200 && ($despues['generada_automaticamente'] ?? false) === true && ($despues['origen'] ?? '') === 'AUTOMATICA' && (int) ($despues['inspeccion']['id'] ?? 0) === $inspeccionId && $presion === 0 && $irregular === 0, codeOf($dano) . '/' . codeOf($finInsp) . ' tipos ' . implode(',', $codigosAuto));

    $base = neumaticoId($kernel, $adminToken, $propioId, $modelo, $medida, 'PRF-' . $sufijo);
    $inicioVida = (string) scalar($pdo, 'SELECT fecha_inicio FROM neumatico_vidas WHERE neumatico_id = :id AND fecha_fin IS NULL', $base);
    $antesVida = (new DateTimeImmutable($inicioVida, new DateTimeZone('America/Lima')))->modify('-1 hour')->format('Y-m-d H:i:s');
    $dentro = (new DateTimeImmutable($inicioVida, new DateTimeZone('America/Lima')))->modify('+30 minutes')->format('Y-m-d H:i:s');
    $reciente = (new DateTimeImmutable($inicioVida, new DateTimeZone('America/Lima')))->modify('+90 minutes')->format('Y-m-d H:i:s');
    inspeccionSql($pdo, $propioId, $unidad, $admin, $base, $posicion, $antesVida, 'FINALIZADA', '1.00', '1.00', '1.00', '90', 'CRITICO');
    inspeccionSql($pdo, $propioId, $unidad, $admin, $base, $posicion, $dentro, 'BORRADOR', '1.50', '1.50', '1.50', '91', 'CRITICO');
    inspeccionSql($pdo, $propioId, $unidad, $admin, $base, $posicion, $reciente, 'FINALIZADA', '8.00', '9.00', '7.00', null, 'ATENCION');
    inspeccionSql($pdo, $propioId, $unidad, $admin, $base, $posicion, $dentro, 'FINALIZADA', '6.00', '6.00', '6.00', '105', 'ATENCION');
    $ind = callApi($kernel, 'GET', '/api/v1/neumaticos/' . $base . '/indicadores', null, $adminToken)['body']['data'];
    $ultima = is_array($ind['ultima_inspeccion'] ?? null) ? $ind['ultima_inspeccion'] : [];
    record('37-47', 'Profundidad, margen, desgaste y porcentaje', ($ind['profundidad_actual_mm'] ?? '') === '7.00' && ($ind['margen_profundidad_mm'] ?? '') === '4.00' && ($ind['desgaste_vida_mm'] ?? '') === '9.00' && ($ind['porcentaje_desgaste_utilizado'] ?? '') === '69.23' && ($ind['ultima_presion_psi'] ?? '') === '105.00' && array_key_exists('presion_psi', $ultima) && $ultima['presion_psi'] === null && ($ind['datos_inconsistentes'] ?? true) === false, ($ind['profundidad_actual_mm'] ?? '') . '/' . ($ind['porcentaje_desgaste_utilizado'] ?? ''));
    $bajo = neumaticoId($kernel, $adminToken, $propioId, $modelo, $medida, 'BAJ-' . $sufijo);
    $inicioBajo = (string) scalar($pdo, 'SELECT fecha_inicio FROM neumatico_vidas WHERE neumatico_id = :id AND fecha_fin IS NULL', $bajo);
    inspeccionSql($pdo, $propioId, $unidad, $admin, $bajo, $posicion, (new DateTimeImmutable($inicioBajo))->modify('+20 minutes')->format('Y-m-d H:i:s'), 'FINALIZADA', '2.00', '4.00', '4.00', null, 'CRITICO');
    $indBajo = callApi($kernel, 'GET', '/api/v1/neumaticos/' . $bajo . '/indicadores', null, $adminToken)['body']['data'];
    $vacio = neumaticoId($kernel, $adminToken, $propioId, $modelo, $medida, 'VAC-' . $sufijo);
    $indVacio = callApi($kernel, 'GET', '/api/v1/neumaticos/' . $vacio . '/indicadores', null, $adminToken)['body']['data'];
    $raro = neumaticoId($kernel, $adminToken, $propioId, $modelo, $medida, 'RAR-' . $sufijo);
    $inicioRaro = (string) scalar($pdo, 'SELECT fecha_inicio FROM neumatico_vidas WHERE neumatico_id = :id AND fecha_fin IS NULL', $raro);
    inspeccionSql($pdo, $propioId, $unidad, $admin, $raro, $posicion, (new DateTimeImmutable($inicioRaro))->modify('+20 minutes')->format('Y-m-d H:i:s'), 'FINALIZADA', '18.00', '18.00', '18.00', null, 'NORMAL');
    $indRaro = callApi($kernel, 'GET', '/api/v1/neumaticos/' . $raro . '/indicadores', null, $adminToken)['body']['data'];
    record('41-45', 'Sin medición, margen negativo e inconsistencia', $indVacio['profundidad_actual_mm'] === null && $indBajo['margen_profundidad_mm'] === '-1.00' && (float) $indBajo['porcentaje_desgaste_utilizado'] > 100 && $indRaro['desgaste_vida_mm'] === null && $indRaro['porcentaje_desgaste_utilizado'] === null && $indRaro['datos_inconsistentes'] === true, (string) $indBajo['porcentaje_desgaste_utilizado']);

    $tiposMant = catalogo(callApi($kernel, 'GET', '/api/v1/tipos-mantenimiento', null, $adminToken)['body']['data'] ?? []);
    $ree = neumaticoId($kernel, $adminToken, $propioId, $modelo, $medida, 'REE-' . $sufijo);
    $original = (string) scalar($pdo, 'SELECT profundidad_inicial_mm FROM neumaticos WHERE id = :id', $ree);
    $altaRee = callApi($kernel, 'POST', '/api/v1/mantenimientos', solicitud($ree, $tiposMant['REENCAUCHE'], $solicitud, '350.00', 'PEN'), $adminToken);
    $reeId = (int) ($altaRee['body']['data']['id'] ?? 0);
    callApi($kernel, 'POST', '/api/v1/mantenimientos/' . $reeId . '/enviar', ['fecha_envio' => $envio], $adminToken);
    $finRee = callApi($kernel, 'POST', '/api/v1/mantenimientos/' . $reeId . '/finalizar', ['fecha_retorno' => $retorno, 'profundidad_despues_mm' => '14.00'], $adminToken);
    $vida1 = $pdo->prepare('SELECT fecha_inicio, fecha_fin FROM neumatico_vidas WHERE neumatico_id = :id AND numero_vida = 1');
    $vida1->execute(['id' => $ree]);
    $filaVida = $vida1->fetch();
    $medioVida1 = (new DateTimeImmutable((string) $filaVida['fecha_inicio']))->modify('+30 minutes')->format('Y-m-d H:i:s');
    inspeccionSql($pdo, $propioId, $unidad, $admin, $ree, $posicion, $medioVida1, 'FINALIZADA', '4.00', '4.00', '4.00', '80', 'ATENCION');
    $despuesRetorno = (new DateTimeImmutable($retorno))->modify('+10 minutes')->format('Y-m-d H:i:s');
    inspeccionSql($pdo, $propioId, $unidad, $admin, $ree, $posicion, $despuesRetorno, 'FINALIZADA', '10.00', '12.00', '11.00', '110', 'NORMAL');
    $indRee = callApi($kernel, 'GET', '/api/v1/neumaticos/' . $ree . '/indicadores', null, $adminToken)['body']['data'];
    $originalDespues = (string) scalar($pdo, 'SELECT profundidad_inicial_mm FROM neumaticos WHERE id = :id', $ree);
    record('48-53', 'Vida 2 usa su propia profundidad', $finRee['status'] === 200 && (int) $indRee['vida_actual'] === 2 && (int) $indRee['cantidad_reencauches'] === 1 && $indRee['profundidad_referencia_inicial_vida_mm'] === '14.00' && $indRee['profundidad_actual_mm'] === '10.00' && $originalDespues === $original && $original === '16.00', (string) $indRee['profundidad_actual_mm']);

    $costo = neumaticoId($kernel, $adminToken, $propioId, $modelo, $medida, 'COS-' . $sufijo);
    $rep = callApi($kernel, 'POST', '/api/v1/mantenimientos', solicitud($costo, $tiposMant['REPARACION'], $solicitud, '100.00', 'PEN'), $adminToken);
    $repId = (int) ($rep['body']['data']['id'] ?? 0);
    callApi($kernel, 'POST', '/api/v1/mantenimientos/' . $repId . '/enviar', ['fecha_envio' => $envio], $adminToken);
    callApi($kernel, 'POST', '/api/v1/mantenimientos/' . $repId . '/finalizar', ['fecha_retorno' => $retorno, 'costo' => '180.00', 'moneda' => 'PEN'], $adminToken);
    $usd = callApi($kernel, 'POST', '/api/v1/mantenimientos', solicitud($costo, $tiposMant['REGRABADO'], $solicitud, '40.00', 'USD'), $adminToken);
    $usdId = (int) ($usd['body']['data']['id'] ?? 0);
    callApi($kernel, 'POST', '/api/v1/mantenimientos/' . $usdId . '/enviar', ['fecha_envio' => $envio], $adminToken);
    callApi($kernel, 'POST', '/api/v1/mantenimientos/' . $usdId . '/finalizar', ['fecha_retorno' => $retorno], $adminToken);
    callApi($kernel, 'POST', '/api/v1/mantenimientos', solicitud($costo, $tiposMant['OTRO'], $solicitud, null, null), $adminToken);
    $indCosto = callApi($kernel, 'GET', '/api/v1/neumaticos/' . $costo . '/indicadores', null, $adminToken)['body']['data'];
    record('54-60', 'Costos separados y regrabado no es reencauche', (int) $indCosto['mantenimientos_total'] === 3 && (int) $indCosto['mantenimientos_finalizados'] === 2 && (int) $indCosto['mantenimientos_activos'] === 1 && ($indCosto['costo_mantenimiento_total']['PEN'] ?? '') === '180.00' && ($indCosto['costo_mantenimiento_total']['USD'] ?? '') === '40.00' && !isset($indCosto['tco']) && (int) $indCosto['cantidad_reencauches'] === 0, json_encode($indCosto['costo_mantenimiento_total']));

    $normal = callApi($kernel, 'GET', '/api/v1/neumaticos/' . $vacio . '/indicadores', null, $adminToken)['body']['data'];
    $aviso = callApi($kernel, 'POST', '/api/v1/alertas', alertaBody($propioId, $tipos['MANTENIMIENTO_RECOMENDADO'], 'ATENCION', 'Aviso ' . $sufijo, null, $vacio), $adminToken);
    $avisoId = (int) ($aviso['body']['data']['id'] ?? 0);
    $conAviso = callApi($kernel, 'GET', '/api/v1/neumaticos/' . $vacio . '/indicadores', null, $adminToken)['body']['data'];
    callApi($kernel, 'POST', '/api/v1/alertas/' . $avisoId . '/atender', ['observacion' => 'Ya fue revisada'], $adminToken);
    $trasAtender = callApi($kernel, 'GET', '/api/v1/neumaticos/' . $vacio . '/indicadores', null, $adminToken)['body']['data'];
    $criticaManual = callApi($kernel, 'POST', '/api/v1/alertas', alertaBody($propioId, $tipos['MANTENIMIENTO_RECOMENDADO'], 'CRITICA', 'Critica ' . $sufijo, $unidad, $montado), $adminToken);
    $montadoInd = callApi($kernel, 'GET', '/api/v1/neumaticos/' . $montado . '/indicadores', null, $adminToken)['body']['data'];
    $descartaAviso = callApi($kernel, 'POST', '/api/v1/alertas', alertaBody($propioId, $tipos['MANTENIMIENTO_RECOMENDADO'], 'ATENCION', 'Ya no ' . $sufijo, null, $base), $adminToken);
    callApi($kernel, 'POST', '/api/v1/alertas/' . (int) $descartaAviso['body']['data']['id'] . '/descartar', ['observacion' => 'Duplicada'], $adminToken);
    $trasDescarte = callApi($kernel, 'GET', '/api/v1/neumaticos/' . $base . '/indicadores', null, $adminToken)['body']['data'];
    record('61-66', 'Criticidad independiente del estado', $normal['criticidad'] === 'NORMAL' && $conAviso['criticidad'] === 'ATENCION' && $trasAtender['criticidad'] === 'NORMAL' && $criticaManual['status'] === 201 && $montadoInd['criticidad'] === 'CRITICA' && $montadoInd['estado']['codigo'] === 'MONTADO' && $montadoInd['montado'] === true && $trasDescarte['alertas_criticas_no_finales'] === 0, $montadoInd['estado']['codigo'] . '/' . $montadoInd['criticidad']);
    record('67-69', 'Montaje real y no inferido', $montadoInd['unidad_actual']['id'] === $unidad && $montadoInd['posicion_actual']['id'] === $posicion && $indVacio['unidad_actual'] === null && $indVacio['montado'] === false, (string) ($montadoInd['posicion_actual']['codigo'] ?? ''));
    $estadoMontado = (int) $pdo->query("SELECT id FROM estados_neumatico WHERE codigo = 'MONTADO'")->fetchColumn();
    $pdo->prepare('UPDATE neumaticos SET estado_id = :estado WHERE id = :id')->execute(['estado' => $estadoMontado, 'id' => $vacio]);
    $inferido = callApi($kernel, 'GET', '/api/v1/neumaticos/' . $vacio . '/indicadores', null, $adminToken)['body']['data'];
    record('69b', 'Estado MONTADO sin montaje activo', $inferido['estado']['codigo'] === 'MONTADO' && $inferido['montado'] === false && $inferido['unidad_actual'] === null, (string) $inferido['montado']);
    record('70-73', 'Rendimiento no disponible', $ind['rendimiento']['km_vida'] === null && $ind['rendimiento']['costo_por_km'] === null && $ind['rendimiento']['km_por_mm'] === null && $ind['rendimiento']['proyeccion_km_restante'] === null && $ind['rendimiento']['disponible'] === false && $ind['datos_suficientes']['rendimiento_km'] === false && str_contains((string) $ind['rendimiento']['motivo'], 'Datos insuficientes'), (string) $ind['rendimiento']['motivo']);

    $descartado = neumaticoId($kernel, $adminToken, $propioId, $modelo, $medida, 'DES-' . $sufijo);
    $motivo = (int) $pdo->query("SELECT id FROM motivos_descarte WHERE codigo = 'OTRO'")->fetchColumn();
    callApi($kernel, 'POST', '/api/v1/neumaticos/' . $descartado . '/descartar', ['motivo_descarte_id' => $motivo, 'fecha_descarte' => $retorno], $adminToken);
    $ops = callApi($kernel, 'GET', '/api/v1/indicadores/operativos?cliente_id=' . $propioId, null, $adminToken)['body']['data'];
    $registrados = (int) scalar($pdo, 'SELECT COUNT(*) FROM neumaticos WHERE cliente_id = :id AND eliminado = 0', $propioId);
    $descCount = (int) scalar($pdo, "SELECT COUNT(*) FROM neumaticos n INNER JOIN estados_neumatico e ON e.id = n.estado_id WHERE n.cliente_id = :id AND n.eliminado = 0 AND e.codigo = 'DESCARTADO'", $propioId);
    record('74-82', 'Agregados operativos', $ops['neumaticos']['total_registrados'] === $registrados && $ops['neumaticos']['total_operativos'] === $registrados - $descCount && $ops['neumaticos']['DESCARTADO'] === $descCount && $ops['alertas']['ABIERTA'] >= 0 && isset($ops['alertas']['EN_ATENCION'], $ops['alertas']['criticas_activas'], $ops['mantenimiento']['SOLICITADO'], $ops['vidas']['vida_1'], $ops['vidas']['vida_2']) && $ops['rendimiento_km']['disponible'] === false, 'reg ' . $ops['neumaticos']['total_registrados'] . ' op ' . $ops['neumaticos']['total_operativos']);
    $sedeId = insertar($pdo, 'INSERT INTO sedes (cliente_id, codigo, nombre) VALUES (:cliente, :codigo, :nombre)', ['cliente' => $propioId, 'codigo' => 'S' . $sufijo, 'nombre' => 'Sede ' . $sufijo]);
    $flotaId = insertar($pdo, 'INSERT INTO flotas (cliente_id, codigo, nombre) VALUES (:cliente, :codigo, :nombre)', ['cliente' => $propioId, 'codigo' => 'F' . $sufijo, 'nombre' => 'Flota ' . $sufijo]);
    $pdo->prepare('UPDATE unidades SET sede_id = :sede, flota_id = :flota WHERE id = :id')->execute(['sede' => $sedeId, 'flota' => $flotaId, 'id' => $unidad]);
    $opsSede = callApi($kernel, 'GET', '/api/v1/indicadores/operativos?cliente_id=' . $propioId . '&sede_id=' . $sedeId . '&flota_id=' . $flotaId, null, $adminToken)['body']['data'];
    $ajenoOps = callApi($kernel, 'GET', '/api/v1/indicadores/operativos?cliente_id=' . $ajenoId, null, $gestorToken);
    record('83-85', 'Scope y filtro de sede/flota', $opsSede['neumaticos']['MONTADO'] === 1 && $opsSede['neumaticos']['total_registrados'] === 1 && $ajenoOps['status'] === 403, 'montados ' . $opsSede['neumaticos']['MONTADO']);

    $tecnicoPost = callApi($kernel, 'POST', '/api/v1/alertas', alertaBody($propioId, $tipos['MANTENIMIENTO_RECOMENDADO'], 'ATENCION', 'Tecnico', null, null), $tecnicoToken);
    $tecnicoLee = callApi($kernel, 'GET', '/api/v1/alertas/' . $alertaId, null, $tecnicoToken);
    $vendedorLee = callApi($kernel, 'GET', '/api/v1/alertas?cliente_id=' . $propioId . '&limit=5', null, $vendedorToken);
    $clienteLee = callApi($kernel, 'GET', '/api/v1/alertas?cliente_id=' . $propioId . '&estado_id=' . $estados['DESCARTADA'] . '&limit=50', null, $clienteToken);
    $clienteAbiertas = callApi($kernel, 'GET', '/api/v1/alertas?cliente_id=' . $propioId . '&estado_id=' . $estados['ABIERTA'] . '&limit=50', null, $clienteToken);
    $idsCliente = array_map(static fn (array $row): string => (string) $row['estado']['codigo'], $clienteLee['body']['data'] ?? []);
    $clienteDesc = callApi($kernel, 'GET', '/api/v1/alertas/' . $descarteId, null, $clienteToken);
    $consultaLee = callApi($kernel, 'GET', '/api/v1/neumaticos/' . $base . '/indicadores', null, $consultaToken);
    $idor = callApi($kernel, 'GET', '/api/v1/alertas/' . $alertaId, null, token($kernel, emailOf($pdo, user($pdo, 'Otro', 'Gestor', 'GESTOR_NEUMATICOS', $password)), $password));
    $gestorAjeno = callApi($kernel, 'GET', '/api/v1/alertas/' . (int) (callApi($kernel, 'POST', '/api/v1/alertas', alertaBody($ajenoId, $tipos['MANTENIMIENTO_RECOMENDADO'], 'ATENCION', 'Oculta ' . $sufijo, null, null), $adminToken)['body']['data']['id'] ?? 0), null, $gestorToken);
    $idorInd = callApi($kernel, 'GET', '/api/v1/neumaticos/' . $neumAjeno . '/indicadores', null, $gestorToken);
    $adminGlobal = callApi($kernel, 'GET', '/api/v1/alertas?cliente_id=' . $ajenoId, null, $adminToken);
    record('86-94', 'Roles, descarte oculto e IDOR', $tecnicoPost['status'] === 403 && $tecnicoLee['status'] === 200 && $vendedorLee['status'] === 200 && $clienteLee['status'] === 200 && ($clienteLee['body']['total'] ?? 1) === 0 && ($clienteAbiertas['body']['total'] ?? 0) >= 1 && !in_array('DESCARTADA', $idsCliente, true) && $clienteDesc['status'] === 403 && $consultaLee['status'] === 200 && !isset($consultaLee['body']['data']['integridad']) && $gestorAjeno['status'] === 403 && $idorInd['status'] === 403 && $adminGlobal['status'] === 200 && $idor['status'] === 403, codeOf($clienteDesc) . '/' . codeOf($idorInd));
    $listaNeum = callApi($kernel, 'GET', '/api/v1/neumaticos?cliente_id=' . $propioId . '&search=MON-' . $sufijo, null, $adminToken);
    $fila = $listaNeum['body']['data'][0] ?? [];
    record('35', 'Listado trae criticidad sin consulta por fila', $listaNeum['status'] === 200 && ($fila['criticidad'] ?? '') === 'CRITICA' && (int) ($fila['alertas_activas'] ?? 0) >= 1 && array_key_exists('profundidad_actual_mm', $fila), (string) ($fila['criticidad'] ?? ''));
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
concurrencia($kernel, $pdo);

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

function concurrencia(Kernel $kernel, PDO $pdo): void
{
    $creados = [];
    $signal = 'alerta-hold-' . bin2hex(random_bytes(6));
    $signalPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $signal;
    try {
        $password = bin2hex(random_bytes(8));
        $admin = user($pdo, 'Admin', 'Concurrente', 'ADMIN_GENERAL', $password);
        $creados['usuario'] = $admin;
        $adminToken = token($kernel, emailOf($pdo, $admin), $password);
        $clienteId = (int) cliente($kernel, $adminToken, 'B7C ' . strtoupper(bin2hex(random_bytes(3))))['body']['data']['id'];
        $creados['cliente'] = $clienteId;
        $tipos = catalogo(callApi($kernel, 'GET', '/api/v1/tipos-alerta', null, $adminToken)['body']['data'] ?? []);
        $alta = callApi($kernel, 'POST', '/api/v1/alertas', alertaBody($clienteId, $tipos['MANTENIMIENTO_RECOMENDADO'], 'CRITICA', 'Concurrente', null, null), $adminToken);
        $creados['alerta'] = (int) ($alta['body']['data']['id'] ?? 0);
        if (($alta['status'] ?? 0) !== 201 || $creados['alerta'] < 1) {
            throw new RuntimeException('No se pudo preparar la alerta concurrente HTTP ' . ($alta['status'] ?? 0));
        }
        $primera = proceso($creados['alerta'], $adminToken, 4000, $signal);
        $avisada = false;
        $limite = microtime(true) + 8;
        while (microtime(true) < $limite) {
            if (is_file($signalPath)) {
                $avisada = true;
                break;
            }
            usleep(40000);
        }
        $segunda = proceso($creados['alerta'], $adminToken, 0, '');
        $ganadora = cerrar($primera);
        $perdedora = cerrar($segunda);
        $estados = [$ganadora['status'], $perdedora['status']];
        sort($estados);
        $codigos = array_values(array_filter([$ganadora['code'], $perdedora['code']]));
        $historial = (int) scalar($pdo, "SELECT COUNT(*) FROM alerta_estado_historial h INNER JOIN estados_alerta ea ON ea.id = h.estado_anterior_id INNER JOIN estados_alerta en ON en.id = h.estado_nuevo_id WHERE h.alerta_id = :id AND ea.codigo = 'ABIERTA' AND en.codigo = 'ATENDIDA'", $creados['alerta']);
        $auditorias = (int) scalar($pdo, "SELECT COUNT(*) FROM auditoria WHERE accion = 'ALERTA_ATENDIDA' AND entidad = 'alertas' AND entidad_id = :id", $creados['alerta']);
        $ok = $avisada && $estados === [200, 409] && $codigos === ['ALERTA_ALREADY_CLOSED'] && $historial === 1 && $auditorias === 1 && $ganadora['status'] !== 500 && $perdedora['status'] !== 500;
        record('31-36', 'Concurrencia al atender', $ok, json_encode([
            'lock' => $avisada,
            'http' => $estados,
            'codigo' => $codigos,
            'historial' => $historial,
            'auditorias' => $auditorias,
            'stderr' => trim($ganadora['stderr'] . ' ' . $perdedora['stderr']),
        ], JSON_UNESCAPED_UNICODE));
    } catch (Throwable $error) {
        record('31-36', 'Concurrencia al atender', false, $error->getMessage());
    } finally {
        if (is_file($signalPath)) {
            unlink($signalPath);
        }
        $alerta = (int) ($creados['alerta'] ?? 0);
        $cliente = (int) ($creados['cliente'] ?? 0);
        $usuario = (int) ($creados['usuario'] ?? 0);
        if ($alerta > 0) {
            $pdo->prepare('DELETE FROM alerta_estado_historial WHERE alerta_id = :id')->execute(['id' => $alerta]);
            $pdo->prepare('DELETE FROM auditoria WHERE entidad = \'alertas\' AND entidad_id = :id')->execute(['id' => $alerta]);
            $pdo->prepare('DELETE FROM alertas WHERE id = :id')->execute(['id' => $alerta]);
        }
        if ($cliente > 0) {
            $pdo->prepare('DELETE FROM auditoria WHERE cliente_id = :id')->execute(['id' => $cliente]);
            $pdo->prepare('DELETE FROM clientes WHERE id = :id')->execute(['id' => $cliente]);
        }
        if ($usuario > 0) {
            $pdo->prepare('DELETE FROM usuario_roles WHERE usuario_id = :id')->execute(['id' => $usuario]);
            $pdo->prepare('DELETE FROM usuarios WHERE id = :id')->execute(['id' => $usuario]);
        }
    }
}

/** @return array{process:resource,pipes:array<int,resource>} */
function proceso(int $alertaId, string $token, int $holdMs, string $signal): array
{
    $comando = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/bloque7_atender_worker.php')
        . ' ' . $alertaId . ' ' . escapeshellarg($token) . ' ' . $holdMs . ' ' . escapeshellarg($signal);
    $pipes = [];
    $proceso = proc_open($comando, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
    if (!is_resource($proceso)) {
        throw new RuntimeException('No se pudo abrir la conexión de atención.');
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
    $json = json_decode(is_string($salida) ? $salida : '', true);

    return [
        'status' => (int) ($json['status'] ?? 0),
        'code' => isset($json['code']) ? (string) $json['code'] : null,
        'stderr' => is_string($error) ? $error : '',
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
    $headers = ['user-agent' => 'bloque7-test', 'x-client-ip' => '203.0.113.27'];
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
        'email' => 'b7.' . bin2hex(random_bytes(5)) . '@test.local',
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
        throw new RuntimeException('No se pudo autenticar ' . $email . ' HTTP ' . $response['status']);
    }

    return (string) $response['body']['data']['token'];
}

/** @return array{status:int,body:array<string,mixed>} */
function cliente(Kernel $kernel, string $token, string $razon): array
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
    $statement = $pdo->prepare('INSERT INTO usuario_clientes (usuario_id, cliente_id, fecha_inicio, fecha_fin, activo) VALUES (:usuario, :cliente, :inicio, NULL, 1)');
    $statement->bindValue('usuario', $usuarioId, PDO::PARAM_INT);
    $statement->bindValue('cliente', $clienteId, PDO::PARAM_INT);
    $statement->bindValue('inicio', $inicio);
    $statement->execute();
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
function alertaBody(int $cliente, int $tipo, string $nivel, string $titulo, ?int $unidad, ?int $neumatico): array
{
    $body = [
        'cliente_id' => $cliente,
        'tipo_alerta_id' => $tipo,
        'nivel' => $nivel,
        'titulo' => $titulo,
        'descripcion' => 'Descripción de ' . $titulo,
        'recomendacion' => 'Revisar en patio',
    ];
    if ($unidad !== null) {
        $body['unidad_id'] = $unidad;
    }
    if ($neumatico !== null) {
        $body['neumatico_id'] = $neumatico;
    }

    return $body;
}

/** @return array<string, mixed> */
function configuracion(string $nombre): array
{
    return [
        'nombre' => $nombre,
        'descripcion' => 'Plantilla de alertas',
        'ejes' => [[
            'numero_eje' => 1,
            'nombre' => 'Delantero',
            'orden' => 1,
            'posiciones' => [
                ['codigo' => 'E1-I', 'lado' => 'IZQUIERDO', 'ubicacion' => 'SIMPLE', 'orden' => 1],
            ],
        ]],
    ];
}

/** @return array<string, mixed> */
function unidadBody(int $clienteId, int $tipoId, string $codigo, int $configuracionId): array
{
    return [
        'cliente_id' => $clienteId,
        'tipo_unidad_id' => $tipoId,
        'codigo' => $codigo,
        'configuracion_id' => $configuracionId,
    ];
}

function neumaticoId(Kernel $kernel, string $token, int $clienteId, int $modeloId, int $medidaId, string $codigo): int
{
    $response = callApi($kernel, 'POST', '/api/v1/neumaticos', [
        'cliente_id' => $clienteId,
        'codigo' => $codigo,
        'modelo_id' => $modeloId,
        'medida_id' => $medidaId,
        'profundidad_minima_mm' => '3',
        'profundidad_inicial_mm' => '16',
    ], $token);
    if ($response['status'] !== 201) {
        throw new RuntimeException('No se pudo crear el neumático ' . $codigo . ' HTTP ' . $response['status'] . ' ' . json_encode($response['body']));
    }
    $id = (int) $response['body']['data']['id'];
    $inicio = (new DateTimeImmutable('now', new DateTimeZone('America/Lima')))->modify('-6 hours')->format('Y-m-d H:i:s');
    $vida = Database::connection()->prepare('UPDATE neumatico_vidas SET fecha_inicio = :fecha, profundidad_inicial_mm = :profundidad WHERE neumatico_id = :id AND fecha_fin IS NULL');
    $vida->bindValue('fecha', $inicio);
    $vida->bindValue('profundidad', '16.00');
    $vida->bindValue('id', $id, PDO::PARAM_INT);
    $vida->execute();

    return $id;
}

/** @return array<string, mixed> */
function montaje(int $neumatico, int $unidad, int $posicion, string $fecha, int $km): array
{
    return [
        'neumatico_id' => $neumatico,
        'unidad_id' => $unidad,
        'posicion_id' => $posicion,
        'fecha_montaje' => $fecha,
        'km_montaje' => $km,
    ];
}

/** @return array<string, mixed> */
function solicitud(int $neumatico, int $tipo, string $fecha, ?string $costo, ?string $moneda): array
{
    $body = [
        'neumatico_id' => $neumatico,
        'tipo_mantenimiento_id' => $tipo,
        'fecha_solicitud' => $fecha,
        'profundidad_antes_mm' => '3.50',
    ];
    if ($costo !== null) {
        $body['costo'] = $costo;
        $body['moneda'] = $moneda;
    }

    return $body;
}

function inspeccionSql(PDO $pdo, int $cliente, int $unidad, int $tecnico, int $neumatico, int $posicion, string $fecha, string $estado, string $interior, string $centro, string $exterior, ?string $presion, string $condicion): void
{
    $statement = $pdo->prepare(
        'INSERT INTO inspecciones (cliente_id, unidad_id, fecha_inspeccion, tecnico_id, estado, creado_por)
         VALUES (:cliente, :unidad, :fecha, :tecnico, :estado, :creado)'
    );
    $statement->bindValue('cliente', $cliente, PDO::PARAM_INT);
    $statement->bindValue('unidad', $unidad, PDO::PARAM_INT);
    $statement->bindValue('fecha', $fecha);
    $statement->bindValue('tecnico', $tecnico, PDO::PARAM_INT);
    $statement->bindValue('estado', $estado);
    $statement->bindValue('creado', $tecnico, PDO::PARAM_INT);
    $statement->execute();
    $id = (int) $pdo->lastInsertId();
    $detalle = $pdo->prepare(
        'INSERT INTO inspeccion_detalles (
            inspeccion_id, cliente_id, posicion_id, neumatico_id, posicion_codigo_snapshot, eje_numero_snapshot,
            eje_nombre_snapshot, lado_snapshot, ubicacion_snapshot, profundidad_interior_mm, profundidad_centro_mm,
            profundidad_exterior_mm, presion_psi, condicion
         ) VALUES (
            :inspeccion, :cliente, :posicion, :neumatico, \'E1-I\', 1, \'Delantero\', \'IZQUIERDO\', \'SIMPLE\',
            :interior, :centro, :exterior, :presion, :condicion
         )'
    );
    $detalle->bindValue('inspeccion', $id, PDO::PARAM_INT);
    $detalle->bindValue('cliente', $cliente, PDO::PARAM_INT);
    $detalle->bindValue('posicion', $posicion, PDO::PARAM_INT);
    $detalle->bindValue('neumatico', $neumatico, PDO::PARAM_INT);
    $detalle->bindValue('interior', $interior);
    $detalle->bindValue('centro', $centro);
    $detalle->bindValue('exterior', $exterior);
    if ($presion === null) {
        $detalle->bindValue('presion', null, PDO::PARAM_NULL);
    } else {
        $detalle->bindValue('presion', $presion);
    }
    $detalle->bindValue('condicion', $condicion);
    $detalle->execute();
}

/** @param array<string, mixed> $params */
function insertar(PDO $pdo, string $sql, array $params): int
{
    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    return (int) $pdo->lastInsertId();
}

function scalar(PDO $pdo, string $sql, int $id): mixed
{
    $statement = $pdo->prepare($sql);
    $statement->bindValue('id', $id, PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchColumn();
}

function audit(PDO $pdo, string $accion, int $entidadId): int
{
    $statement = $pdo->prepare('SELECT COUNT(*) FROM auditoria WHERE accion = :accion AND entidad_id = :id AND ip = :ip');
    $statement->execute(['accion' => $accion, 'id' => $entidadId, 'ip' => '203.0.113.27']);

    return (int) $statement->fetchColumn();
}

function codeOf(array $response): string
{
    return 'HTTP ' . $response['status'] . ' ' . (string) ($response['body']['err']['code'] ?? '');
}

/** @return array<string, int> */
function counts(PDO $pdo): array
{
    $tablas = ['usuarios', 'clientes', 'alertas', 'alerta_estado_historial', 'neumaticos', 'auditoria', 'usuario_clientes', 'inspecciones'];
    $conteo = [];
    foreach ($tablas as $tabla) {
        $conteo[$tabla] = (int) $pdo->query('SELECT COUNT(*) FROM ' . $tabla)->fetchColumn();
    }

    return $conteo;
}
