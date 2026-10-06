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
$clientesArchivo = [];

try {
    $password = bin2hex(random_bytes(8));
    $admin = user($pdo, 'Admin', 'B6', 'ADMIN_GENERAL', $password);
    $gestor = user($pdo, 'Gestor', 'B6', 'GESTOR_NEUMATICOS', $password);
    $tecnico = user($pdo, 'Tecnico', 'B6', 'TECNICO_INSPECCION', $password);
    $vendedor = user($pdo, 'Vendedor', 'B6', 'VENDEDOR', $password);
    $clienteAdmin = user($pdo, 'Cliente', 'B6', 'ADMIN_CLIENTE', $password);
    $consulta = user($pdo, 'Consulta', 'B6', 'CONSULTA_EJECUTIVA', $password);
    $adminToken = token($kernel, emailOf($pdo, $admin), $password);
    $gestorToken = token($kernel, emailOf($pdo, $gestor), $password);
    $tecnicoToken = token($kernel, emailOf($pdo, $tecnico), $password);
    $vendedorToken = token($kernel, emailOf($pdo, $vendedor), $password);
    $clienteToken = token($kernel, emailOf($pdo, $clienteAdmin), $password);
    $consultaToken = token($kernel, emailOf($pdo, $consulta), $password);
    $sufijo = strtoupper(bin2hex(random_bytes(3)));
    $propioId = (int) cliente($kernel, $adminToken, 'B6 ' . $sufijo . ' Propio')['body']['data']['id'];
    $ajenoId = (int) cliente($kernel, $adminToken, 'B6 ' . $sufijo . ' Ajeno')['body']['data']['id'];
    $clientesArchivo = [$propioId, $ajenoId];
    scope($pdo, $gestor, $propioId, $hoy);
    scope($pdo, $tecnico, $propioId, $hoy);
    scope($pdo, $vendedor, $propioId, $hoy);
    scope($pdo, $clienteAdmin, $propioId, $hoy);
    scope($pdo, $consulta, $propioId, $hoy);

    $tipos = callApi($kernel, 'GET', '/api/v1/tipos-mantenimiento', null, $adminToken);
    $estados = callApi($kernel, 'GET', '/api/v1/estados-mantenimiento', null, $adminToken);
    $motivos = callApi($kernel, 'GET', '/api/v1/motivos-descarte', null, $adminToken);
    $tipo = catalogo($tipos['body']['data'] ?? []);
    $motivo = catalogo($motivos['body']['data'] ?? []);
    $codigosEstado = array_map(static fn (array $row): string => (string) $row['codigo'], $estados['body']['data'] ?? []);
    $marca = (int) callApi($kernel, 'POST', '/api/v1/marcas-neumatico', ['nombre' => 'Marca B6 ' . $sufijo], $adminToken)['body']['data']['id'];
    $modelo = (int) callApi($kernel, 'POST', '/api/v1/modelos-neumatico', ['marca_id' => $marca, 'nombre' => 'Modelo B6 ' . $sufijo], $adminToken)['body']['data']['id'];
    $medida = (int) callApi($kernel, 'POST', '/api/v1/medidas-neumatico', ['descripcion' => '11R22.5 B6 ' . $sufijo], $adminToken)['body']['data']['id'];
    $tipoUnidad = (int) $pdo->query("SELECT id FROM tipos_unidad WHERE codigo = 'CAMION'")->fetchColumn();
    $config = callApi($kernel, 'POST', '/api/v1/configuraciones-unidad', configuracion('Cfg B6 ' . $sufijo), $adminToken);
    $configId = (int) $config['body']['data']['id'];
    $posicion = (int) $config['body']['data']['ejes'][0]['posiciones'][0]['id'];
    $posicionLibre = (int) $config['body']['data']['ejes'][0]['posiciones'][1]['id'];
    $unidad = (int) callApi($kernel, 'POST', '/api/v1/unidades', unidadBody($propioId, $tipoUnidad, 'UB6-' . $sufijo, $configId), $adminToken)['body']['data']['id'];

    $reparacion = neumaticoId($kernel, $adminToken, $propioId, $modelo, $medida, 'REP-' . $sufijo);
    $alta = callApi($kernel, 'POST', '/api/v1/mantenimientos', solicitudMant($reparacion, $tipo['REPARACION'], $solicitud), $adminToken);
    $mantId = (int) ($alta['body']['data']['id'] ?? 0);
    $ficha = $alta['body']['data'] ?? [];
    record('1', 'Crear REPARACION', $alta['status'] === 201 && ($ficha['tipo']['codigo'] ?? '') === 'REPARACION', codeOf($alta));
    record('2', 'Estado inicial SOLICITADO', ($ficha['estado']['codigo'] ?? '') === 'SOLICITADO', (string) ($ficha['estado']['codigo'] ?? ''));
    $historialInicial = $ficha['historial'][0] ?? [];
    record('3', 'Historial mantenimiento inicial', count($ficha['historial'] ?? []) === 1 && array_key_exists('anterior', $historialInicial) && $historialInicial['anterior'] === null && ($historialInicial['nuevo'] ?? '') === 'SOLICITADO', json_encode($historialInicial));
    record('4', 'Neumático permanece DISPONIBLE', ($ficha['neumatico']['estado'] ?? '') === 'DISPONIBLE', (string) ($ficha['neumatico']['estado'] ?? ''));
    $segundo = callApi($kernel, 'POST', '/api/v1/mantenimientos', solicitudMant($reparacion, $tipo['REPARACION'], $solicitud), $adminToken);
    record('5', 'Segundo mantenimiento activo incompatible', $segundo['status'] === 409 && ($segundo['body']['err']['code'] ?? '') === 'MANTENIMIENTO_ACTIVO', codeOf($segundo));

    $montado = neumaticoId($kernel, $adminToken, $propioId, $modelo, $medida, 'MON-' . $sufijo);
    $montaje = callApi($kernel, 'POST', '/api/v1/montajes', montaje($montado, $unidad, $posicion, $solicitud, 1000), $adminToken);
    $envioMontado = callApi($kernel, 'POST', '/api/v1/mantenimientos', solicitudMant($montado, $tipo['REPARACION'], $solicitud), $adminToken);
    $libre = neumaticoId($kernel, $adminToken, $propioId, $modelo, $medida, 'ENV-' . $sufijo);
    $altaLibre = callApi($kernel, 'POST', '/api/v1/mantenimientos', solicitudMant($libre, $tipo['REPARACION'], $solicitud), $adminToken);
    $libreId = (int) ($altaLibre['body']['data']['id'] ?? 0);
    $montajeLibre = callApi($kernel, 'POST', '/api/v1/montajes', montaje($libre, $unidad, $posicionLibre, $envio, 1100), $adminToken);
    $enviarMontado = callApi($kernel, 'POST', '/api/v1/mantenimientos/' . $libreId . '/enviar', ['fecha_envio' => $envio], $adminToken);
    $montajeLibreId = (int) ($montajeLibre['body']['data']['id'] ?? 0);
    $desmonte = callApi($kernel, 'POST', '/api/v1/montajes/' . $montajeLibreId . '/desmontar', desmontaje($envio, 1100), $adminToken);
    record('6', 'Neumático MONTADO no se envía', $montaje['status'] === 201 && $envioMontado['status'] === 409 && $enviarMontado['status'] === 409 && $desmonte['status'] === 200, codeOf($enviarMontado));

    $enviado = callApi($kernel, 'POST', '/api/v1/mantenimientos/' . $mantId . '/enviar', ['fecha_envio' => $envio, 'observacion' => 'Sale al taller'], $adminToken);
    $estadoEnvio = $enviado['body']['data']['neumatico']['estado'] ?? '';
    $movEnvio = movimientos($pdo, $reparacion, $mantId);
    $histEnvio = historialNeumatico($pdo, $reparacion);
    record('7', 'Enviar reparación', $enviado['status'] === 200, codeOf($enviado));
    record('8', 'Estado neumático EN_MANTENIMIENTO', $estadoEnvio === 'EN_MANTENIMIENTO', (string) $estadoEnvio);
    record('9', 'Movimiento ENVIO_MANTENIMIENTO', in_array('ENVIO_MANTENIMIENTO', $movEnvio, true), implode(',', $movEnvio));
    record('10', 'Historial neumático', ultimoCambio($histEnvio) === 'DISPONIBLE>EN_MANTENIMIENTO', ultimoCambio($histEnvio));
    record('11', 'Estado mantenimiento ENVIADO', ($enviado['body']['data']['estado']['codigo'] ?? '') === 'ENVIADO', (string) ($enviado['body']['data']['estado']['codigo'] ?? ''));
    $iniciado = callApi($kernel, 'POST', '/api/v1/mantenimientos/' . $mantId . '/iniciar', ['observacion' => 'En taller'], $adminToken);
    record('12', 'Iniciar EN_PROCESO', $iniciado['status'] === 200 && ($iniciado['body']['data']['estado']['codigo'] ?? '') === 'EN_PROCESO' && ($iniciado['body']['data']['neumatico']['estado'] ?? '') === 'EN_MANTENIMIENTO', codeOf($iniciado));
    $forzado = callApi($kernel, 'POST', '/api/v1/mantenimientos/' . $mantId . '/finalizar', ['fecha_retorno' => $retorno, 'inicia_nueva_vida' => true], $adminToken);
    $fin = callApi($kernel, 'POST', '/api/v1/mantenimientos/' . $mantId . '/finalizar', ['fecha_retorno' => $retorno, 'costo' => '180.00', 'moneda' => 'PEN', 'profundidad_despues_mm' => '8.00', 'observacion' => 'Listo'], $adminToken);
    $vidasRep = vidas($pdo, $reparacion);
    $movFin = movimientos($pdo, $reparacion, $mantId);
    record('13', 'Finalizar reparación', $fin['status'] === 200 && ($fin['body']['data']['estado']['codigo'] ?? '') === 'FINALIZADO', codeOf($fin));
    record('14', 'Neumático vuelve DISPONIBLE', ($fin['body']['data']['neumatico']['estado'] ?? '') === 'DISPONIBLE', (string) ($fin['body']['data']['neumatico']['estado'] ?? ''));
    record('15', 'Movimiento RETORNO', in_array('RETORNO_MANTENIMIENTO', $movFin, true), implode(',', $movFin));
    record('16', 'No crea nueva vida', count($vidasRep) === 1 && $vidasRep[0]['fecha_fin'] === null && (int) ($fin['body']['data']['neumatico']['vida_actual'] ?? 0) === 1 && ($fin['body']['data']['inicia_nueva_vida'] ?? true) === false, 'vidas ' . count($vidasRep));
    record('17', 'Auditoría reparación', audit($pdo, 'MANTENIMIENTO_CREATE', $mantId) === 1 && audit($pdo, 'MANTENIMIENTO_SEND', $mantId) === 1 && audit($pdo, 'MANTENIMIENTO_START', $mantId) === 1 && audit($pdo, 'MANTENIMIENTO_FINISH', $mantId) === 1, 'ok');
    record('32', 'Forzar inicia_nueva_vida', $forzado['status'] === 422, codeOf($forzado));

    $reencaucheIdNeum = neumaticoId($kernel, $adminToken, $propioId, $modelo, $medida, 'REE-' . $sufijo);
    $original = (string) scalar($pdo, 'SELECT profundidad_inicial_mm FROM neumaticos WHERE id = :id', $reencaucheIdNeum);
    $altaRee = callApi($kernel, 'POST', '/api/v1/mantenimientos', solicitudMant($reencaucheIdNeum, $tipo['REENCAUCHE'], $solicitud, '350.00'), $gestorToken);
    $reeId = (int) ($altaRee['body']['data']['id'] ?? 0);
    record('18', 'Crear REENCAUCHE', $altaRee['status'] === 201 && ($altaRee['body']['data']['tipo']['codigo'] ?? '') === 'REENCAUCHE', codeOf($altaRee));
    $envioRee = callApi($kernel, 'POST', '/api/v1/mantenimientos/' . $reeId . '/enviar', ['fecha_envio' => $envio], $gestorToken);
    record('19', 'Enviar reencauche', $envioRee['status'] === 200, codeOf($envioRee));
    record('20', 'Estado EN_REENCAUCHE', ($envioRee['body']['data']['neumatico']['estado'] ?? '') === 'EN_REENCAUCHE', (string) ($envioRee['body']['data']['neumatico']['estado'] ?? ''));
    $finRee = callApi($kernel, 'POST', '/api/v1/mantenimientos/' . $reeId . '/finalizar', ['fecha_retorno' => $retorno, 'profundidad_despues_mm' => '15.00', 'observacion' => 'Banda nueva'], $gestorToken);
    $vidasRee = vidas($pdo, $reencaucheIdNeum);
    $cerrada = $vidasRee[0] ?? [];
    $nueva = $vidasRee[1] ?? [];
    record('21', 'Finalizar reencauche', $finRee['status'] === 200, codeOf($finRee));
    record('22', 'Mantenimiento FINALIZADO', ($finRee['body']['data']['estado']['codigo'] ?? '') === 'FINALIZADO' && ($finRee['body']['data']['inicia_nueva_vida'] ?? false) === true, (string) ($finRee['body']['data']['estado']['codigo'] ?? ''));
    record('23', 'Neumático DISPONIBLE', ($finRee['body']['data']['neumatico']['estado'] ?? '') === 'DISPONIBLE', (string) ($finRee['body']['data']['neumatico']['estado'] ?? ''));
    record('24', 'Vida anterior cerrada', ($cerrada['motivo_fin'] ?? '') === 'REENCAUCHE' && $cerrada['fecha_fin'] !== null && $cerrada['km_fin'] === null, (string) ($cerrada['motivo_fin'] ?? ''));
    record('25', 'Vida N+1 creada', (int) ($nueva['numero_vida'] ?? 0) === 2 && $nueva['fecha_fin'] === null, (string) ($nueva['numero_vida'] ?? ''));
    $segundaFin = callApi($kernel, 'POST', '/api/v1/mantenimientos/' . $reeId . '/finalizar', ['fecha_retorno' => $retorno, 'profundidad_despues_mm' => '15.00'], $gestorToken);
    $vidasTrasSegunda = vidas($pdo, $reencaucheIdNeum);
    $vidaTres = 0;
    foreach ($vidasTrasSegunda as $vida) {
        if ((int) ($vida['numero_vida'] ?? 0) === 3) {
            $vidaTres++;
        }
    }
    record('26', 'vida_actual incrementada', (int) ($finRee['body']['data']['neumatico']['vida_actual'] ?? 0) === 2 && (int) ($finRee['body']['data']['vida_nueva'] ?? 0) === 2 && $segundaFin['status'] === 409 && ($segundaFin['body']['err']['code'] ?? '') === 'MANTENIMIENTO_ALREADY_FINISHED' && count($vidasTrasSegunda) === 2 && $vidaTres === 0 && (int) scalar($pdo, 'SELECT vida_actual FROM neumaticos WHERE id = :id', $reencaucheIdNeum) === 2, codeOf($segundaFin));
    record('27', 'mantenimiento_origen_id correcto', (int) ($nueva['mantenimiento_origen_id'] ?? 0) === $reeId, (string) ($nueva['mantenimiento_origen_id'] ?? ''));
    record('28', 'Profundidad nueva guardada', numero($nueva['profundidad_inicial_mm'] ?? null) === '15.00' && numero($original) === numero(scalar($pdo, 'SELECT profundidad_inicial_mm FROM neumaticos WHERE id = :id', $reencaucheIdNeum)), (string) ($nueva['profundidad_inicial_mm'] ?? ''));
    record('29', 'Historial estado reencauche', ultimoCambio(historialNeumatico($pdo, $reencaucheIdNeum)) === 'EN_REENCAUCHE>DISPONIBLE', ultimoCambio(historialNeumatico($pdo, $reencaucheIdNeum)));
    $movRee = movimientos($pdo, $reencaucheIdNeum, $reeId);
    record('30', 'Movimientos envío y retorno', in_array('ENVIO_MANTENIMIENTO', $movRee, true) && in_array('RETORNO_MANTENIMIENTO', $movRee, true) && count($movRee) === 2, implode(',', $movRee));
    record('31', 'Auditoría reencauche', audit($pdo, 'MANTENIMIENTO_FINISH', $reeId) === 1 && audit($pdo, 'REENCAUCHE_NEW_LIFE', $reencaucheIdNeum) === 1, 'ok');

    $cancelable = neumaticoId($kernel, $adminToken, $propioId, $modelo, $medida, 'CAN-' . $sufijo);
    $altaCan = callApi($kernel, 'POST', '/api/v1/mantenimientos', solicitudMant($cancelable, $tipo['OTRO'], $solicitud, null), $adminToken);
    $canId = (int) ($altaCan['body']['data']['id'] ?? 0);
    $cancelSol = callApi($kernel, 'POST', '/api/v1/mantenimientos/' . $canId . '/cancelar', ['observacion' => 'Ya no aplica'], $adminToken);
    record('40', 'Cancelar SOLICITADO', $cancelSol['status'] === 200 && ($cancelSol['body']['data']['estado']['codigo'] ?? '') === 'CANCELADO', codeOf($cancelSol));
    record('41', 'Neumático sigue DISPONIBLE', ($cancelSol['body']['data']['neumatico']['estado'] ?? '') === 'DISPONIBLE' && movimientos($pdo, $cancelable, $canId) === [], (string) ($cancelSol['body']['data']['neumatico']['estado'] ?? ''));

    $enviadoCan = neumaticoId($kernel, $adminToken, $propioId, $modelo, $medida, 'CAE-' . $sufijo);
    $altaCae = callApi($kernel, 'POST', '/api/v1/mantenimientos', solicitudMant($enviadoCan, $tipo['REPARACION'], $solicitud), $adminToken);
    $caeId = (int) ($altaCae['body']['data']['id'] ?? 0);
    callApi($kernel, 'POST', '/api/v1/mantenimientos/' . $caeId . '/enviar', ['fecha_envio' => $envio], $adminToken);
    $cancelEnv = callApi($kernel, 'POST', '/api/v1/mantenimientos/' . $caeId . '/cancelar', ['observacion' => 'Regresa'], $adminToken);
    $otra = callApi($kernel, 'POST', '/api/v1/mantenimientos/' . $caeId . '/cancelar', ['observacion' => 'Otra vez'], $adminToken);
    $vidasCan = vidas($pdo, $enviadoCan);
    $reeCancel = neumaticoId($kernel, $adminToken, $propioId, $modelo, $medida, 'RCA-' . $sufijo);
    $altaReeCancel = callApi($kernel, 'POST', '/api/v1/mantenimientos', solicitudMant($reeCancel, $tipo['REENCAUCHE'], $solicitud, '80.00'), $adminToken);
    $reeCancelId = (int) ($altaReeCancel['body']['data']['id'] ?? 0);
    $envioReeCancel = callApi($kernel, 'POST', '/api/v1/mantenimientos/' . $reeCancelId . '/enviar', ['fecha_envio' => $envio], $adminToken);
    $cancelRee = callApi($kernel, 'POST', '/api/v1/mantenimientos/' . $reeCancelId . '/cancelar', ['observacion' => 'Reencauche cancelado'], $adminToken);
    $vidasReeCancel = vidas($pdo, $reeCancel);
    record('42', 'Cancelar ENVIADO', $cancelEnv['status'] === 200 && ($cancelEnv['body']['data']['estado']['codigo'] ?? '') === 'CANCELADO', codeOf($cancelEnv));
    record('43', 'Neumático vuelve DISPONIBLE', ($cancelEnv['body']['data']['neumatico']['estado'] ?? '') === 'DISPONIBLE', (string) ($cancelEnv['body']['data']['neumatico']['estado'] ?? ''));
    record('44', 'Historial correcto', ultimoCambio(historialNeumatico($pdo, $enviadoCan)) === 'EN_MANTENIMIENTO>DISPONIBLE' && in_array('RETORNO_MANTENIMIENTO', movimientos($pdo, $enviadoCan, $caeId), true), ultimoCambio(historialNeumatico($pdo, $enviadoCan)));
    record('45', 'No crea nueva vida', count($vidasCan) === 1 && $vidasCan[0]['fecha_fin'] === null && (int) ($cancelEnv['body']['data']['neumatico']['vida_actual'] ?? 0) === 1 && $envioReeCancel['status'] === 200 && ($envioReeCancel['body']['data']['neumatico']['estado'] ?? '') === 'EN_REENCAUCHE' && $cancelRee['status'] === 200 && ($cancelRee['body']['data']['neumatico']['estado'] ?? '') === 'DISPONIBLE' && (int) ($cancelRee['body']['data']['neumatico']['vida_actual'] ?? 0) === 1 && count($vidasReeCancel) === 1 && $vidasReeCancel[0]['fecha_fin'] === null && ultimoCambio(historialNeumatico($pdo, $reeCancel)) === 'EN_REENCAUCHE>DISPONIBLE', 'vidas ' . count($vidasCan));
    record('46', 'Segundo cancelar', $otra['status'] === 409 && ($otra['body']['err']['code'] ?? '') === 'MANTENIMIENTO_ALREADY_CLOSED', codeOf($otra));

    $regroove = neumaticoId($kernel, $adminToken, $propioId, $modelo, $medida, 'REG-' . $sufijo);
    $altaReg = callApi($kernel, 'POST', '/api/v1/mantenimientos', solicitudMant($regroove, $tipo['REGRABADO'], $solicitud), $adminToken);
    $regId = (int) ($altaReg['body']['data']['id'] ?? 0);
    $envioReg = callApi($kernel, 'POST', '/api/v1/mantenimientos/' . $regId . '/enviar', ['fecha_envio' => $envio], $adminToken);
    record('47', 'Enviar REGRABADO', $envioReg['status'] === 200, codeOf($envioReg));
    record('48', 'Estado EN_MANTENIMIENTO', ($envioReg['body']['data']['neumatico']['estado'] ?? '') === 'EN_MANTENIMIENTO', (string) ($envioReg['body']['data']['neumatico']['estado'] ?? ''));
    $finReg = callApi($kernel, 'POST', '/api/v1/mantenimientos/' . $regId . '/finalizar', ['fecha_retorno' => $retorno], $adminToken);
    $vidasReg = vidas($pdo, $regroove);
    record('49', 'Finalizar regrabado', $finReg['status'] === 200 && ($finReg['body']['data']['inicia_nueva_vida'] ?? true) === false, codeOf($finReg));
    record('50', 'DISPONIBLE', ($finReg['body']['data']['neumatico']['estado'] ?? '') === 'DISPONIBLE', (string) ($finReg['body']['data']['neumatico']['estado'] ?? ''));
    record('51', 'No abre nueva vida', count($vidasReg) === 1 && $vidasReg[0]['fecha_fin'] === null && (int) ($finReg['body']['data']['neumatico']['vida_actual'] ?? 0) === 1, 'vidas ' . count($vidasReg));

    $descarteNeum = neumaticoId($kernel, $adminToken, $propioId, $modelo, $medida, 'DES-' . $sufijo);
    $desc = callApi($kernel, 'POST', '/api/v1/neumaticos/' . $descarteNeum . '/descartar', [
        'motivo_descarte_id' => $motivo['DANO_IRREPARABLE'],
        'fecha_descarte' => $retorno,
        'profundidad_final_mm' => '1.20',
        'observacion' => 'Daño irreparable',
    ], $adminToken);
    $filaDesc = $desc['body']['data'] ?? [];
    $vidaDesc = vidas($pdo, $descarteNeum);
    $movDesc = movimientos($pdo, $descarteNeum, null);
    record('52', 'Descartar DISPONIBLE', $desc['status'] === 201, codeOf($desc));
    record('53', 'Registro descarte creado', (int) ($filaDesc['id'] ?? 0) > 0 && ($filaDesc['motivo']['codigo'] ?? '') === 'DANO_IRREPARABLE', (string) ($filaDesc['id'] ?? ''));
    record('54', 'Movimiento DESCARTE', in_array('DESCARTE', $movDesc, true), implode(',', $movDesc));
    record('55', 'Vida actual cerrada', ($vidaDesc[0]['motivo_fin'] ?? '') === 'DESCARTE' && $vidaDesc[0]['fecha_fin'] !== null && count($vidaDesc) === 1, (string) ($vidaDesc[0]['motivo_fin'] ?? ''));
    $fichaNeum = callApi($kernel, 'GET', '/api/v1/neumaticos/' . $descarteNeum, null, $adminToken);
    record('56', 'Estado DESCARTADO', ($fichaNeum['body']['data']['estado']['codigo'] ?? '') === 'DESCARTADO', (string) ($fichaNeum['body']['data']['estado']['codigo'] ?? ''));
    record('57', 'Historial estado descarte', ultimoCambio(historialNeumatico($pdo, $descarteNeum)) === 'DISPONIBLE>DESCARTADO', ultimoCambio(historialNeumatico($pdo, $descarteNeum)));
    record('58', 'Auditoría descarte', audit($pdo, 'NEUMATICO_DESCARTE', $descarteNeum) === 1, 'ok');
    record('59', 'vida_final correcta', (int) ($filaDesc['vida_final'] ?? 0) === 1 && $filaDesc['km_totales'] === null, (string) ($filaDesc['vida_final'] ?? ''));
    $descMontado = callApi($kernel, 'POST', '/api/v1/neumaticos/' . $montado . '/descartar', ['motivo_descarte_id' => $motivo['DANO_IRREPARABLE'], 'fecha_descarte' => $retorno], $adminToken);
    $activoNeum = neumaticoId($kernel, $adminToken, $propioId, $modelo, $medida, 'ACT-' . $sufijo);
    $altaAct = callApi($kernel, 'POST', '/api/v1/mantenimientos', solicitudMant($activoNeum, $tipo['OTRO'], $solicitud, null), $adminToken);
    $descActivo = callApi($kernel, 'POST', '/api/v1/neumaticos/' . $activoNeum . '/descartar', ['motivo_descarte_id' => $motivo['OTRO'], 'fecha_descarte' => $retorno], $adminToken);
    record('60', 'Neumático montado', $descMontado['status'] === 409, codeOf($descMontado));
    record('61', 'Mantenimiento activo', $altaAct['status'] === 201 && $descActivo['status'] === 409 && ($descActivo['body']['err']['code'] ?? '') === 'MANTENIMIENTO_ACTIVO', codeOf($descActivo));
    $doble = callApi($kernel, 'POST', '/api/v1/neumaticos/' . $descarteNeum . '/descartar', ['motivo_descarte_id' => $motivo['OTRO'], 'fecha_descarte' => $retorno], $adminToken);
    record('62', 'Doble descarte', $doble['status'] === 409 && ($doble['body']['err']['code'] ?? '') === 'NEUMATICO_ALREADY_DISCARDED', codeOf($doble));
    $montarDesc = callApi($kernel, 'POST', '/api/v1/montajes', montaje($descarteNeum, $unidad, $posicionLibre, $retorno, 1200), $adminToken);
    record('63', 'Montar DESCARTADO', $montarDesc['status'] === 409, codeOf($montarDesc));
    $mantDesc = callApi($kernel, 'POST', '/api/v1/mantenimientos', solicitudMant($descarteNeum, $tipo['REPARACION'], $retorno), $adminToken);
    record('64', 'Mantenimiento DESCARTADO', $mantDesc['status'] === 409 && ($mantDesc['body']['err']['code'] ?? '') === 'NEUMATICO_ALREADY_DISCARDED', codeOf($mantDesc));

    $ajenoNeum = neumaticoId($kernel, $adminToken, $ajenoId, $modelo, $medida, 'AJE-' . $sufijo);
    $altaAjena = callApi($kernel, 'POST', '/api/v1/mantenimientos', solicitudMant($ajenoNeum, $tipo['REPARACION'], $solicitud), $adminToken);
    $ajenaId = (int) ($altaAjena['body']['data']['id'] ?? 0);
    $listaAdmin = callApi($kernel, 'GET', '/api/v1/mantenimientos?cliente_id=' . $ajenoId . '&neumatico_id=' . $ajenoNeum . '&tipo=REPARACION&estado=SOLICITADO&fecha_inicio=' . $hoy . '&fecha_fin=' . $hoy . '&search=AJE-' . $sufijo . '&limit=20&offset=0', null, $adminToken);
    record('65', 'ADMIN_GENERAL global', $listaAdmin['status'] === 200 && ($listaAdmin['body']['total'] ?? 0) >= 1 && $altaAjena['status'] === 201, codeOf($listaAdmin) . ' total ' . ($listaAdmin['body']['total'] ?? 0) . ' alta ' . $altaAjena['status'] . ' ' . json_encode($listaAdmin['body']['err']['details'] ?? $listaAdmin['body']['err']['message'] ?? ''));
    $listaGestor = callApi($kernel, 'GET', '/api/v1/mantenimientos?limit=100', null, $gestorToken);
    $idsGestor = array_map(static fn (array $row): int => (int) $row['id'], $listaGestor['body']['data'] ?? []);
    $gestorCrea = callApi($kernel, 'POST', '/api/v1/mantenimientos', solicitudMant($activoNeum, $tipo['OTRO'], $solicitud, null), $gestorToken);
    record('66', 'GESTOR scope', $listaGestor['status'] === 200 && in_array($mantId, $idsGestor, true) && !in_array($ajenaId, $idsGestor, true) && $gestorCrea['status'] === 409, 'filas ' . count($idsGestor));
    $filtroAjeno = callApi($kernel, 'GET', '/api/v1/mantenimientos?cliente_id=' . $ajenoId, null, $gestorToken);
    record('67', 'Cliente ajeno', $filtroAjeno['status'] === 403 && ($filtroAjeno['body']['err']['code'] ?? '') === 'CLIENT_SCOPE_FORBIDDEN', codeOf($filtroAjeno));
    $idor = callApi($kernel, 'GET', '/api/v1/mantenimientos/' . $ajenaId, null, $gestorToken);
    record('68', 'IDOR mantenimiento', $idor['status'] === 403 && ($idor['body']['err']['code'] ?? '') === 'CLIENT_SCOPE_FORBIDDEN', codeOf($idor));
    $idorDesc = callApi($kernel, 'POST', '/api/v1/neumaticos/' . $ajenoNeum . '/descartar', ['motivo_descarte_id' => $motivo['OTRO'], 'fecha_descarte' => $retorno], $gestorToken);
    record('69', 'IDOR descarte', $idorDesc['status'] === 403 && ($idorDesc['body']['err']['code'] ?? '') === 'CLIENT_SCOPE_FORBIDDEN', codeOf($idorDesc));

    $foto = callFile($kernel, '/api/v1/mantenimientos/' . $libreId . '/archivos', fotoBytes('jpeg'), 'dano.jpg', $adminToken);
    $pdf = callFile($kernel, '/api/v1/mantenimientos/' . $libreId . '/archivos?tipo_archivo=DOCUMENTO', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n", 'orden.pdf', $adminToken);
    $fotoId = (int) ($foto['body']['data']['id'] ?? 0);
    $pdfId = (int) ($pdf['body']['data']['id'] ?? 0);
    $baja = callApi($kernel, 'GET', '/api/v1/archivos/' . $pdfId . '/download', null, $gestorToken);
    $ajenaFoto = callFile($kernel, '/api/v1/mantenimientos/' . $ajenaId . '/archivos', fotoBytes('jpeg'), 'ajeno.jpg', $adminToken);
    $ajenaFotoId = (int) ($ajenaFoto['body']['data']['id'] ?? 0);
    $idorArchivo = callApi($kernel, 'GET', '/api/v1/archivos/' . $ajenaFotoId . '/download', null, $gestorToken);
    $tecnicoPost = callApi($kernel, 'POST', '/api/v1/mantenimientos', solicitudMant($reparacion, $tipo['REPARACION'], $solicitud), $tecnicoToken);
    $vendedorLee = callApi($kernel, 'GET', '/api/v1/mantenimientos/' . $mantId, null, $vendedorToken);
    $clienteLee = callApi($kernel, 'GET', '/api/v1/neumaticos/' . $reparacion . '/mantenimientos', null, $clienteToken);
    $consultaLee = callApi($kernel, 'GET', '/api/v1/motivos-descarte', null, $consultaToken);
    record('70', 'Archivos respetan scope', $foto['status'] === 201 && ($foto['body']['data']['tipo_archivo'] ?? '') === 'FOTO' && $pdf['status'] === 201 && ($pdf['body']['data']['tipo_archivo'] ?? '') === 'DOCUMENTO' && $baja['status'] === 200 && str_starts_with((string) ($baja['body']['data'] ?? ''), '%PDF') && $idorArchivo['status'] === 403 && $tecnicoPost['status'] === 403 && $vendedorLee['status'] === 200 && $clienteLee['status'] === 200 && $consultaLee['status'] === 200 && count($codigosEstado) === 5, codeOf($idorArchivo));
    unset($fotoId);
} catch (Throwable $error) {
    $failed = true;
    echo 'EXCEPCION ' . $error->getMessage() . ' en ' . $error->getFile() . ':' . $error->getLine() . PHP_EOL;
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    foreach ($clientesArchivo as $clienteId) {
        borrarDirectorio(dirname(__DIR__) . '/storage/archivos/' . $clienteId);
    }
}

$after = counts($pdo);
record('CLEAN', 'La transacción no dejó datos', $before === $after, $before === $after ? 'sin cambios' : json_encode(array_diff_assoc($after, $before)));
concurrencia($kernel, $pdo, $retorno);

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

function concurrencia(Kernel $kernel, PDO $pdo, string $retorno): void
{
    $creados = [];
    $signal = 'mantenimiento-hold-' . bin2hex(random_bytes(6));
    $signalPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $signal;
    try {
        $password = bin2hex(random_bytes(8));
        $admin = user($pdo, 'Admin', 'Concurrente', 'ADMIN_GENERAL', $password);
        $creados['usuario'] = $admin;
        $adminToken = token($kernel, emailOf($pdo, $admin), $password);
        $sufijo = strtoupper(bin2hex(random_bytes(3)));
        $clienteId = (int) cliente($kernel, $adminToken, 'B6C ' . $sufijo)['body']['data']['id'];
        $creados['cliente'] = $clienteId;
        $creados['marca'] = (int) callApi($kernel, 'POST', '/api/v1/marcas-neumatico', ['nombre' => 'Marca C ' . $sufijo], $adminToken)['body']['data']['id'];
        $creados['modelo'] = (int) callApi($kernel, 'POST', '/api/v1/modelos-neumatico', ['marca_id' => $creados['marca'], 'nombre' => 'Modelo C ' . $sufijo], $adminToken)['body']['data']['id'];
        $creados['medida'] = (int) callApi($kernel, 'POST', '/api/v1/medidas-neumatico', ['descripcion' => '11R22.5 C ' . $sufijo], $adminToken)['body']['data']['id'];
        $creados['neumatico'] = neumaticoId($kernel, $adminToken, $clienteId, $creados['modelo'], $creados['medida'], 'TC-' . $sufijo);
        $tipos = catalogo(callApi($kernel, 'GET', '/api/v1/tipos-mantenimiento', null, $adminToken)['body']['data'] ?? []);
        $ahora = new DateTimeImmutable('now', new DateTimeZone('America/Lima'));
        $alta = callApi($kernel, 'POST', '/api/v1/mantenimientos', solicitudMant($creados['neumatico'], $tipos['REENCAUCHE'], $ahora->modify('-3 hours')->format('Y-m-d H:i:s'), '200.00'), $adminToken);
        $creados['mantenimiento'] = (int) ($alta['body']['data']['id'] ?? 0);
        $enviado = callApi($kernel, 'POST', '/api/v1/mantenimientos/' . $creados['mantenimiento'] . '/enviar', ['fecha_envio' => $ahora->modify('-2 hours')->format('Y-m-d H:i:s')], $adminToken);
        if (($alta['status'] ?? 0) !== 201 || ($enviado['status'] ?? 0) !== 200 || $creados['mantenimiento'] < 1) {
            throw new RuntimeException('No se pudo preparar el reencauche concurrente HTTP ' . ($alta['status'] ?? 0) . '/' . ($enviado['status'] ?? 0));
        }

        $primera = procesoFinalizar($creados['mantenimiento'], $adminToken, 4000, $signal, $retorno);
        $avisada = false;
        $limite = microtime(true) + 8;
        while (microtime(true) < $limite) {
            if (is_file($signalPath)) {
                $avisada = true;
                break;
            }
            usleep(40000);
        }
        $segunda = procesoFinalizar($creados['mantenimiento'], $adminToken, 0, '', $retorno);
        $ganadora = cerrarProceso($primera);
        $perdedora = cerrarProceso($segunda);
        $estados = [$ganadora['status'], $perdedora['status']];
        sort($estados);
        $codigos = array_values(array_filter([$ganadora['code'], $perdedora['code']]));
        $vidasNuevas = (int) scalar($pdo, 'SELECT COUNT(*) FROM neumatico_vidas WHERE neumatico_id = :id AND numero_vida = 2', $creados['neumatico']);
        $vidaActual = (int) scalar($pdo, 'SELECT vida_actual FROM neumaticos WHERE id = :id', $creados['neumatico']);
        $retornos = (int) scalar($pdo, "SELECT COUNT(*) FROM movimientos_neumatico m INNER JOIN tipos_movimiento t ON t.id = m.tipo_movimiento_id WHERE m.neumatico_id = :id AND t.codigo = 'RETORNO_MANTENIMIENTO'", $creados['neumatico']);
        $historiales = (int) scalar($pdo, "SELECT COUNT(*) FROM neumatico_estado_historial h INNER JOIN estados_neumatico en ON en.id = h.estado_nuevo_id INNER JOIN estados_neumatico ea ON ea.id = h.estado_anterior_id WHERE h.neumatico_id = :id AND ea.codigo = 'EN_REENCAUCHE' AND en.codigo = 'DISPONIBLE'", $creados['neumatico']);
        $auditorias = (int) scalar($pdo, "SELECT COUNT(*) FROM auditoria WHERE accion = 'REENCAUCHE_NEW_LIFE' AND entidad = 'neumaticos' AND entidad_id = :id", $creados['neumatico']);
        $ok = $avisada
            && $estados === [200, 409]
            && $codigos === ['MANTENIMIENTO_ALREADY_FINISHED']
            && $vidasNuevas === 1
            && $vidaActual === 2
            && $retornos === 1
            && $historiales === 1
            && $auditorias === 1
            && $ganadora['status'] !== 500
            && $perdedora['status'] !== 500;
        record('33-39', 'Concurrencia de reencauche', $ok, json_encode([
            'lock' => $avisada,
            'http' => $estados,
            'codigo' => $codigos,
            'vidas' => $vidasNuevas,
            'vida_actual' => $vidaActual,
            'retornos' => $retornos,
            'historiales' => $historiales,
            'auditorias' => $auditorias,
            'stderr' => trim($ganadora['stderr'] . ' ' . $perdedora['stderr']),
        ], JSON_UNESCAPED_UNICODE));
    } catch (Throwable $error) {
        record('33-39', 'Concurrencia de reencauche', false, $error->getMessage());
    } finally {
        if (is_file($signalPath)) {
            unlink($signalPath);
        }
        try {
            limpiarConcurrencia($pdo, $creados);
        } catch (Throwable $error) {
            record('CONCURRENCIA', 'Limpieza de la prueba concurrente', false, $error->getMessage());
        }
    }
}

/** @param array<string, int> $creados */
function limpiarConcurrencia(PDO $pdo, array $creados): void
{
    $mantenimiento = (int) ($creados['mantenimiento'] ?? 0);
    $neumatico = (int) ($creados['neumatico'] ?? 0);
    $cliente = (int) ($creados['cliente'] ?? 0);
    $usuario = (int) ($creados['usuario'] ?? 0);
    if ($mantenimiento > 0) {
        $pdo->prepare('DELETE FROM mantenimiento_estado_historial WHERE mantenimiento_id = :id')->execute(['id' => $mantenimiento]);
    }
    if ($neumatico > 0) {
        $pdo->prepare('DELETE FROM neumatico_estado_historial WHERE neumatico_id = :id')->execute(['id' => $neumatico]);
        $pdo->prepare('DELETE FROM movimientos_neumatico WHERE neumatico_id = :id')->execute(['id' => $neumatico]);
        $pdo->prepare('DELETE FROM neumatico_vidas WHERE neumatico_id = :id')->execute(['id' => $neumatico]);
        $pdo->prepare('DELETE FROM descartes_neumatico WHERE neumatico_id = :id')->execute(['id' => $neumatico]);
    }
    if ($mantenimiento > 0) {
        $pdo->prepare('DELETE FROM mantenimientos_neumatico WHERE id = :id')->execute(['id' => $mantenimiento]);
    }
    if ($neumatico > 0) {
        $pdo->prepare('DELETE FROM neumaticos WHERE id = :id')->execute(['id' => $neumatico]);
    }
    if (($creados['modelo'] ?? 0) > 0) {
        $pdo->prepare('DELETE FROM modelos_neumatico WHERE id = :id')->execute(['id' => $creados['modelo']]);
    }
    if (($creados['marca'] ?? 0) > 0) {
        $pdo->prepare('DELETE FROM marcas_neumatico WHERE id = :id')->execute(['id' => $creados['marca']]);
    }
    if (($creados['medida'] ?? 0) > 0) {
        $pdo->prepare('DELETE FROM medidas_neumatico WHERE id = :id')->execute(['id' => $creados['medida']]);
    }
    if ($usuario > 0 || $cliente > 0) {
        $auditoria = $pdo->prepare('DELETE FROM auditoria WHERE usuario_id = :usuario OR cliente_id = :cliente');
        $auditoria->bindValue('usuario', $usuario, PDO::PARAM_INT);
        $auditoria->bindValue('cliente', $cliente > 0 ? $cliente : null, $cliente > 0 ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $auditoria->execute();
    }
    if ($usuario > 0 && $cliente > 0) {
        $pdo->prepare('DELETE FROM usuario_clientes WHERE usuario_id = :usuario OR cliente_id = :cliente')->execute(['usuario' => $usuario, 'cliente' => $cliente]);
    }
    if ($cliente > 0) {
        $pdo->prepare('DELETE FROM clientes WHERE id = :id')->execute(['id' => $cliente]);
    }
    if ($usuario > 0) {
        $pdo->prepare('DELETE FROM usuario_roles WHERE usuario_id = :id')->execute(['id' => $usuario]);
        $pdo->prepare('DELETE FROM usuarios WHERE id = :id')->execute(['id' => $usuario]);
    }
}

/** @return array{process:resource,pipes:array<int,resource>} */
function procesoFinalizar(int $mantenimientoId, string $token, int $holdMs, string $signal, string $fecha): array
{
    $comando = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/bloque6_finalizar_worker.php')
        . ' ' . $mantenimientoId . ' ' . escapeshellarg($token) . ' ' . $holdMs . ' ' . escapeshellarg($signal) . ' ' . escapeshellarg($fecha);
    $pipes = [];
    $proceso = proc_open($comando, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
    if (!is_resource($proceso)) {
        throw new RuntimeException('No se pudo abrir la conexión de finalización.');
    }

    return ['process' => $proceso, 'pipes' => $pipes];
}

/** @param array{process:resource,pipes:array<int,resource>} $proceso @return array{status:int,code:?string,stderr:string} */
function cerrarProceso(array $proceso): array
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
    $headers = ['user-agent' => 'bloque6-test', 'x-client-ip' => '203.0.113.26'];
    if ($token !== null) {
        $headers['authorization'] = 'Bearer ' . $token;
    }
    if ($json !== null) {
        $headers['content-type'] = 'application/json';
    }
    $response = $kernel->handle(Request::fake($method, $path, $headers, $json === null ? null : json_encode($json, JSON_THROW_ON_ERROR)));

    return ['status' => $response->status(), 'body' => $response->status() === 204 ? [] : $response->toArray()];
}

/** @return array{status:int,body:array<string,mixed>} */
function callFile(Kernel $kernel, string $path, string $contenido, string $nombre, string $token): array
{
    $request = Request::fake('POST', $path, [
        'user-agent' => 'bloque6-test',
        'x-client-ip' => '203.0.113.26',
        'authorization' => 'Bearer ' . $token,
    ], null, ['archivo' => ['contents' => $contenido, 'name' => $nombre]]);
    $response = $kernel->handle($request);

    return ['status' => $response->status(), 'body' => $response->toArray()];
}

function fotoBytes(string $tipo): string
{
    $codigos = [
        'jpeg' => '/9j/4AAQSkZJRgABAQAAAQABAAD/2wCEAAkGBwgHBgkIBwgKCgkLDRYPDQwMDRsUFRAWIB0iIiAdHx8kKDQsJCYxJx8fLT0tMTU3Ojo6Iys/RD84QzQ5OjcBCgoKDQwNGg8PGjclHyU3Nzc3Nzc3Nzc3Nzc3Nzc3Nzc3Nzc3Nzc3Nzc3Nzc3Nzc3Nzc3Nzc3Nzc3Nzc3Nzc3N//AABEIAAcACgMBIgACEQEDEQH/xAAbAAACAwEBAQAAAAAAAAAAAAACAwABBAUGB//EABoQAQEAAgMAAAAAAAAAAAAAAAABAhEDBBT/xAAYAQEBAQEBAAAAAAAAAAAAAAAAAQIDBP/EABQRAQAAAAAAAAAAAAAAAAAAAAD/2gAMAwEAAhEDEQA/AKo6l2m2m2l9k9r9s0f8Q0Y6U6n//Z',
    ];
    $bytes = base64_decode($codigos[$tipo] ?? '', true);
    if (!is_string($bytes) || $bytes === '') {
        throw new RuntimeException('No se pudo preparar la fotografía.');
    }

    return $bytes;
}

function user(PDO $pdo, string $nombres, string $apellidos, string $rol, string $password): int
{
    $statement = $pdo->prepare(
        'INSERT INTO usuarios (nombres, apellidos, email, password_hash, estado, eliminado)
         VALUES (:nombres, :apellidos, :email, :password_hash, \'ACTIVO\', 0)'
    );
    $statement->execute([
        'nombres' => $nombres,
        'apellidos' => $apellidos,
        'email' => 'b6.' . bin2hex(random_bytes(5)) . '@test.local',
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
    $statement = $pdo->prepare(
        'INSERT INTO usuario_clientes (usuario_id, cliente_id, fecha_inicio, fecha_fin, activo)
         VALUES (:usuario, :cliente, :inicio, NULL, 1)'
    );
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
function solicitudMant(int $neumatico, int $tipo, string $fecha, ?string $costo = '120.00'): array
{
    $body = [
        'neumatico_id' => $neumatico,
        'tipo_mantenimiento_id' => $tipo,
        'fecha_solicitud' => $fecha,
        'tercero_nombre' => 'Taller Norte',
        'profundidad_antes_mm' => '3.50',
        'observacion' => 'Solicitud de prueba',
    ];
    if ($costo !== null) {
        $body['costo'] = $costo;
        $body['moneda'] = 'PEN';
    }

    return $body;
}

/** @return array<string, mixed> */
function configuracion(string $nombre): array
{
    return [
        'nombre' => $nombre,
        'descripcion' => 'Plantilla de mantenimiento',
        'ejes' => [[
            'numero_eje' => 1,
            'nombre' => 'Delantero',
            'orden' => 1,
            'posiciones' => [
                ['codigo' => 'E1-I', 'lado' => 'IZQUIERDO', 'ubicacion' => 'SIMPLE', 'orden' => 1],
                ['codigo' => 'E1-D', 'lado' => 'DERECHO', 'ubicacion' => 'SIMPLE', 'orden' => 2],
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
        'sede_id' => null,
        'flota_id' => null,
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
    $vida = Database::connection()->prepare('UPDATE neumatico_vidas SET fecha_inicio = :fecha WHERE neumatico_id = :id AND fecha_fin IS NULL');
    $vida->bindValue('fecha', $inicio);
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
        'horometro_montaje' => null,
        'observacion' => null,
    ];
}

/** @return array<string, mixed> */
function desmontaje(string $fecha, int $km): array
{
    return [
        'fecha_desmontaje' => $fecha,
        'km_desmontaje' => $km,
        'horometro_desmontaje' => null,
        'motivo_desmontaje' => 'Liberar para mantenimiento',
    ];
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
    $statement->execute(['accion' => $accion, 'id' => $entidadId, 'ip' => '203.0.113.26']);

    return (int) $statement->fetchColumn();
}

/** @return list<string> */
function movimientos(PDO $pdo, int $neumaticoId, ?int $mantenimientoId): array
{
    $sql = 'SELECT t.codigo FROM movimientos_neumatico m INNER JOIN tipos_movimiento t ON t.id = m.tipo_movimiento_id WHERE m.neumatico_id = :id';
    if ($mantenimientoId !== null) {
        $sql .= ' AND m.mantenimiento_id = :mantenimiento';
    }
    $sql .= ' ORDER BY m.id';
    $statement = $pdo->prepare($sql);
    $statement->bindValue('id', $neumaticoId, PDO::PARAM_INT);
    if ($mantenimientoId !== null) {
        $statement->bindValue('mantenimiento', $mantenimientoId, PDO::PARAM_INT);
    }
    $statement->execute();

    return array_map(static fn (array $row): string => (string) $row['codigo'], $statement->fetchAll());
}

/** @return list<string> */
function historialNeumatico(PDO $pdo, int $neumaticoId): array
{
    $statement = $pdo->prepare(
        'SELECT ea.codigo AS anterior, en.codigo AS nuevo
         FROM neumatico_estado_historial h
         LEFT JOIN estados_neumatico ea ON ea.id = h.estado_anterior_id
         INNER JOIN estados_neumatico en ON en.id = h.estado_nuevo_id
         WHERE h.neumatico_id = :id
         ORDER BY h.id'
    );
    $statement->bindValue('id', $neumaticoId, PDO::PARAM_INT);
    $statement->execute();
    $cambios = [];
    foreach ($statement->fetchAll() as $row) {
        $cambios[] = ($row['anterior'] ?? 'NULL') . '>' . $row['nuevo'];
    }

    return $cambios;
}

function ultimoCambio(array $cambios): string
{
    return (string) ($cambios[array_key_last($cambios)] ?? '');
}

/** @return list<array<string, mixed>> */
function vidas(PDO $pdo, int $neumaticoId): array
{
    $statement = $pdo->prepare(
        'SELECT numero_vida, fecha_fin, motivo_fin, km_fin, km_inicio, profundidad_inicial_mm, profundidad_final_mm, mantenimiento_origen_id
         FROM neumatico_vidas WHERE neumatico_id = :id ORDER BY numero_vida'
    );
    $statement->bindValue('id', $neumaticoId, PDO::PARAM_INT);
    $statement->execute();

    return $statement->fetchAll();
}

function numero(mixed $valor): string
{
    return number_format((float) $valor, 2, '.', '');
}

/** @return array<string, int> */
function counts(PDO $pdo): array
{
    $tables = [
        'usuarios', 'clientes', 'auditoria', 'usuario_clientes', 'marcas_neumatico', 'modelos_neumatico',
        'medidas_neumatico', 'neumaticos', 'montajes_neumatico', 'movimientos_neumatico', 'neumatico_vidas',
        'neumatico_estado_historial', 'mantenimientos_neumatico', 'mantenimiento_estado_historial',
        'descartes_neumatico', 'archivos', 'unidades', 'configuraciones_unidad',
    ];
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

function borrarDirectorio(string $directorio): void
{
    if (!is_dir($directorio)) {
        return;
    }
    $items = scandir($directorio);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $ruta = $directorio . DIRECTORY_SEPARATOR . $item;
        if (is_dir($ruta)) {
            borrarDirectorio($ruta);
            continue;
        }
        unlink($ruta);
    }
    rmdir($directorio);
}
