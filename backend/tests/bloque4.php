<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Config\Config;
use App\Config\Database;
use App\Http\Kernel;
use App\Http\Request;

$results = [];
$kernel = Kernel::boot(dirname(__DIR__));
$pdo = Database::connection();
$before = counts($pdo);
$hoy = (string) $pdo->query('SELECT CURRENT_DATE')->fetchColumn();
$pdo->beginTransaction();
$failed = false;
$fecha = '2026-10-05 10:00:00';
$fecha2 = '2026-10-05 12:00:00';
$concurrencia = [];

try {
    $password = bin2hex(random_bytes(8));
    $admin = user($pdo, 'Admin', 'B4', 'ADMIN_GENERAL', $password);
    $gestor = user($pdo, 'Gestor', 'B4', 'GESTOR_NEUMATICOS', $password);
    $tecnico = user($pdo, 'Tecnico', 'B4', 'TECNICO_INSPECCION', $password);
    $vendedor = user($pdo, 'Vendedor', 'B4', 'VENDEDOR', $password);
    $adminToken = token($kernel, emailOf($pdo, $admin), $password);
    $gestorToken = token($kernel, emailOf($pdo, $gestor), $password);
    $tecnicoToken = token($kernel, emailOf($pdo, $tecnico), $password);
    $vendedorToken = token($kernel, emailOf($pdo, $vendedor), $password);
    $sufijo = strtoupper(bin2hex(random_bytes(3)));
    $propioId = (int) cliente($kernel, $adminToken, 'B4 ' . $sufijo . ' Propio')['body']['data']['id'];
    $ajenoId = (int) cliente($kernel, $adminToken, 'B4 ' . $sufijo . ' Ajeno')['body']['data']['id'];
    scope($pdo, $gestor, $propioId, $hoy);
    scope($pdo, $tecnico, $propioId, $hoy);
    scope($pdo, $vendedor, $propioId, $hoy);

    $marcaId = (int) callApi($kernel, 'POST', '/api/v1/marcas-neumatico', ['nombre' => 'Marca ' . $sufijo], $adminToken)['body']['data']['id'];
    $modeloId = (int) callApi($kernel, 'POST', '/api/v1/modelos-neumatico', ['marca_id' => $marcaId, 'nombre' => 'Modelo ' . $sufijo], $adminToken)['body']['data']['id'];
    $medidaId = (int) callApi($kernel, 'POST', '/api/v1/medidas-neumatico', ['descripcion' => '295/80 R22.5 ' . $sufijo], $adminToken)['body']['data']['id'];
    $tipo = (int) $pdo->query("SELECT id FROM tipos_unidad WHERE codigo = 'CAMION'")->fetchColumn();
    $config = callApi($kernel, 'POST', '/api/v1/configuraciones-unidad', configuracion('Cfg ' . $sufijo), $adminToken);
    $configId = (int) $config['body']['data']['id'];
    $posiciones = posicionesDe($config['body']['data']);
    $otra = callApi($kernel, 'POST', '/api/v1/configuraciones-unidad', configuracion('Cfg B ' . $sufijo, 'B'), $adminToken);
    $otraId = (int) $otra['body']['data']['id'];
    $posicionAjena = (int) $otra['body']['data']['ejes'][0]['posiciones'][0]['id'];
    $unidad = (int) callApi($kernel, 'POST', '/api/v1/unidades', unidadBody($propioId, $tipo, 'U-' . $sufijo, $configId, ['kilometraje_actual' => 500000]), $adminToken)['body']['data']['id'];
    $unidad2 = (int) callApi($kernel, 'POST', '/api/v1/unidades', unidadBody($propioId, $tipo, 'U2-' . $sufijo, $configId), $adminToken)['body']['data']['id'];
    $puente = (int) callApi($kernel, 'POST', '/api/v1/unidades', unidadBody($propioId, $tipo, 'UP-' . $sufijo, $configId), $adminToken)['body']['data']['id'];
    $unidadAjena = (int) callApi($kernel, 'POST', '/api/v1/unidades', unidadBody($ajenoId, $tipo, 'UA-' . $sufijo, $configId), $adminToken)['body']['data']['id'];
    $inactiva = (int) callApi($kernel, 'POST', '/api/v1/unidades', unidadBody($propioId, $tipo, 'UI-' . $sufijo, $configId), $adminToken)['body']['data']['id'];
    $baja = (int) callApi($kernel, 'POST', '/api/v1/unidades', unidadBody($propioId, $tipo, 'UB-' . $sufijo, $configId), $adminToken)['body']['data']['id'];
    $sinConfig = (int) callApi($kernel, 'POST', '/api/v1/unidades', unidadBody($propioId, $tipo, 'US-' . $sufijo, null), $adminToken)['body']['data']['id'];
    $libre = (int) callApi($kernel, 'POST', '/api/v1/unidades', unidadBody($propioId, $tipo, 'UL-' . $sufijo, $configId), $adminToken)['body']['data']['id'];
    $soloOrigen = (int) callApi($kernel, 'POST', '/api/v1/unidades', unidadBody($propioId, $tipo, 'UO-' . $sufijo, $configId), $adminToken)['body']['data']['id'];
    $soloDestino = (int) callApi($kernel, 'POST', '/api/v1/unidades', unidadBody($propioId, $tipo, 'UD-' . $sufijo, $configId), $adminToken)['body']['data']['id'];
    callApi($kernel, 'PATCH', '/api/v1/unidades/' . $inactiva . '/estado', ['estado' => 'INACTIVA'], $adminToken);
    callApi($kernel, 'PATCH', '/api/v1/unidades/' . $baja . '/estado', ['estado' => 'BAJA'], $adminToken);

    $t1 = neumaticoId($kernel, $adminToken, $propioId, $modeloId, $medidaId, 'T1-' . $sufijo);
    $t2 = neumaticoId($kernel, $adminToken, $propioId, $modeloId, $medidaId, 'T2-' . $sufijo);
    $t3 = neumaticoId($kernel, $adminToken, $propioId, $modeloId, $medidaId, 'T3-' . $sufijo);
    $t4 = neumaticoId($kernel, $adminToken, $propioId, $modeloId, $medidaId, 'T4-' . $sufijo);
    $t5 = neumaticoId($kernel, $adminToken, $propioId, $modeloId, $medidaId, 'T5-' . $sufijo);
    $tajeno = neumaticoId($kernel, $adminToken, $ajenoId, $modeloId, $medidaId, 'TA-' . $sufijo);
    $p1 = $posiciones[0];
    $p2 = $posiciones[1];
    $p3 = $posiciones[2];
    $p4 = $posiciones[3];

    $tecnicoMonta = callApi($kernel, 'POST', '/api/v1/montajes', montaje($t1, $unidad, $p1, $fecha, 125400), $tecnicoToken);
    $alta = callApi($kernel, 'POST', '/api/v1/montajes', montaje($t1, $unidad, $p1, $fecha, 125400), $gestorToken);
    $montajeId = (int) ($alta['body']['data']['id'] ?? 0);
    $movimientoId = (int) ($alta['body']['data']['movimiento_id'] ?? 0);
    $ficha = callApi($kernel, 'GET', '/api/v1/unidades/' . $unidad, null, $adminToken);
    $enlace = enlaceHistorial($pdo, $t1, 'MONTAJE');
    $audit = audit($pdo, 'NEUMATICO_MONTAJE', $t1);
    record('1', 'Montaje válido', $alta['status'] === 201 && $tecnicoMonta['status'] === 403 && $montajeId > 0, codeOf($alta));
    record('2', 'DISPONIBLE a MONTADO', estadoCodigo($pdo, $t1) === 'MONTADO' && ($alta['body']['data']['estado'] ?? '') === 'MONTADO', estadoCodigo($pdo, $t1));
    record('3', 'Movimiento MONTAJE', ($enlace['codigo'] ?? '') === 'MONTAJE' && (int) ($enlace['unidad_destino_id'] ?? 0) === $unidad && (int) ($enlace['posicion_destino_id'] ?? 0) === $p1 && $enlace['unidad_origen_id'] === null, (string) ($enlace['codigo'] ?? ''));
    record('4', 'Historial de montaje', ($enlace['anterior'] ?? '') === 'DISPONIBLE' && ($enlace['nuevo'] ?? '') === 'MONTADO', (string) ($enlace['nuevo'] ?? ''));
    record('5', 'Historial enlazado al movimiento', (int) ($enlace['movimiento_id'] ?? 0) === $movimientoId && $movimientoId > 0, (string) ($enlace['movimiento_id'] ?? ''));
    $activos = callApi($kernel, 'GET', '/api/v1/unidades/' . $unidad . '/montajes-activos', null, $adminToken);
    record('6', 'Montaje activo', $activos['status'] === 200 && ($activos['body']['total'] ?? 0) === 1 && ($activos['body']['data'][0]['neumatico']['id'] ?? 0) === $t1, 'total ' . ($activos['body']['total'] ?? 0));
    record('7', 'Auditoría de montaje', $audit === 1, (string) $audit);
    $repetido = callApi($kernel, 'POST', '/api/v1/montajes', montaje($t1, $unidad, $p2, $fecha, 125400), $adminToken);
    $borrado = callApi($kernel, 'DELETE', '/api/v1/neumaticos/' . $t1, null, $adminToken);
    record('8', 'Neumático ya montado', $repetido['status'] === 409 && ($repetido['body']['err']['code'] ?? '') === 'CONFLICT' && $borrado['status'] === 409, codeOf($repetido));
    $ocupada = callApi($kernel, 'POST', '/api/v1/montajes', montaje($t2, $unidad, $p1, $fecha, 125400), $adminToken);
    record('9', 'Posición ocupada', $ocupada['status'] === 409, codeOf($ocupada));
    $otraPosicion = callApi($kernel, 'POST', '/api/v1/montajes', montaje($t2, $unidad, $posicionAjena, $fecha, 125400), $adminToken);
    record('10', 'Posición de otra configuración', $otraPosicion['status'] === 422, codeOf($otraPosicion));
    $pdo->prepare('UPDATE configuracion_posiciones SET activo = 0 WHERE id = :id')->execute(['id' => $p4]);
    $inactivaPos = callApi($kernel, 'POST', '/api/v1/montajes', montaje($t2, $unidad, $p4, $fecha, 125400), $adminToken);
    $pdo->prepare('UPDATE configuracion_posiciones SET activo = 1 WHERE id = :id')->execute(['id' => $p4]);
    record('11', 'Posición inactiva', $inactivaPos['status'] === 422, codeOf($inactivaPos));
    record('12', 'Unidad inactiva', callApi($kernel, 'POST', '/api/v1/montajes', montaje($t2, $inactiva, $p1, $fecha, 1), $adminToken)['status'] === 422, '422');
    record('13', 'Unidad de baja', callApi($kernel, 'POST', '/api/v1/montajes', montaje($t2, $baja, $p1, $fecha, 1), $adminToken)['status'] === 422, '422');
    record('14', 'Unidad sin configuración', callApi($kernel, 'POST', '/api/v1/montajes', montaje($t2, $sinConfig, $p1, $fecha, 1), $adminToken)['status'] === 422, '422');
    record('15', 'Neumático de otro cliente', callApi($kernel, 'POST', '/api/v1/montajes', montaje($tajeno, $unidad, $p2, $fecha, 1), $adminToken)['status'] === 422, '422');
    record('16', 'Unidad de otro cliente', callApi($kernel, 'POST', '/api/v1/montajes', montaje($t2, $unidadAjena, $p1, $fecha, 1), $adminToken)['status'] === 422, '422');
    $scopeAjeno = callApi($kernel, 'POST', '/api/v1/montajes', montaje($tajeno, $unidadAjena, $p2, $fecha, 1), $gestorToken);
    record('17', 'Scope ajeno', $scopeAjeno['status'] === 403 && ($scopeAjeno['body']['err']['code'] ?? '') === 'CLIENT_SCOPE_FORBIDDEN', codeOf($scopeAjeno));
    record('1b', 'El odómetro no retrocede', (int) ($ficha['body']['data']['kilometraje_actual'] ?? 0) === 500000, (string) ($ficha['body']['data']['kilometraje_actual'] ?? ''));

    $kmMenor = callApi($kernel, 'POST', '/api/v1/montajes/' . $montajeId . '/desmontar', desmontaje($fecha2, 100), $adminToken);
    $fechaMenor = callApi($kernel, 'POST', '/api/v1/montajes/' . $montajeId . '/desmontar', desmontaje('2026-10-05 09:00:00', 130200), $adminToken);
    record('29', 'Kilometraje final menor', $kmMenor['status'] === 422 && estadoCodigo($pdo, $t1) === 'MONTADO', codeOf($kmMenor));
    record('30', 'Fecha final anterior', $fechaMenor['status'] === 422 && estadoCodigo($pdo, $t1) === 'MONTADO', codeOf($fechaMenor));
    $bajaMontaje = callApi($kernel, 'POST', '/api/v1/montajes/' . $montajeId . '/desmontar', desmontaje($fecha2, 130200), $gestorToken);
    $cerrado = montajeRow($pdo, $montajeId);
    $enlaceBaja = enlaceHistorial($pdo, $t1, 'DESMONTAJE');
    record('22', 'Desmontaje válido', $bajaMontaje['status'] === 200, codeOf($bajaMontaje));
    record('23', 'Montaje cerrado', $cerrado['fecha_desmontaje'] !== null && (int) $cerrado['km_desmontaje'] === 130200, (string) $cerrado['fecha_desmontaje']);
    record('24', 'MONTADO a DISPONIBLE', estadoCodigo($pdo, $t1) === 'DISPONIBLE' && ($bajaMontaje['body']['data']['estado'] ?? '') === 'DISPONIBLE', estadoCodigo($pdo, $t1));
    record('25', 'Movimiento DESMONTAJE', ($enlaceBaja['codigo'] ?? '') === 'DESMONTAJE' && (int) ($enlaceBaja['unidad_origen_id'] ?? 0) === $unidad && (int) ($enlaceBaja['posicion_origen_id'] ?? 0) === $p1, (string) ($enlaceBaja['codigo'] ?? ''));
    record('26', 'Historial de desmontaje', ($enlaceBaja['anterior'] ?? '') === 'MONTADO' && ($enlaceBaja['nuevo'] ?? '') === 'DISPONIBLE' && (int) ($enlaceBaja['movimiento_id'] ?? 0) === (int) ($bajaMontaje['body']['data']['movimiento_id'] ?? 0), (string) ($enlaceBaja['nuevo'] ?? ''));
    $librePos = callApi($kernel, 'GET', '/api/v1/unidades/' . $unidad . '/montajes-activos', null, $adminToken);
    $sigueHistorico = callApi($kernel, 'DELETE', '/api/v1/neumaticos/' . $t1, null, $adminToken);
    record('27', 'Posición libre y el histórico impide borrar', ($librePos['body']['total'] ?? 1) === 0 && $sigueHistorico['status'] === 409, 'activos ' . ($librePos['body']['total'] ?? ''));
    $segundaBaja = callApi($kernel, 'POST', '/api/v1/montajes/' . $montajeId . '/desmontar', desmontaje($fecha2, 130200), $adminToken);
    record('28', 'Segundo desmontaje', $segundaBaja['status'] === 409, codeOf($segundaBaja));

    $m1 = (int) callApi($kernel, 'POST', '/api/v1/montajes', montaje($t1, $unidad, $p1, $fecha2, 130200), $adminToken)['body']['data']['id'];
    $m2 = (int) callApi($kernel, 'POST', '/api/v1/montajes', montaje($t2, $unidad, $p2, $fecha2, 130200), $adminToken)['body']['data']['id'];
    $m3 = (int) callApi($kernel, 'POST', '/api/v1/montajes', montaje($t3, $unidad, $p3, $fecha2, 130200), $adminToken)['body']['data']['id'];
    $histAntes = historialCount($pdo, $t1);
    $simple = callApi($kernel, 'POST', '/api/v1/rotaciones', rotacion($unidad, $fecha2, 140000, [['neumatico_id' => $t1, 'posicion_destino_id' => $p4]]), $gestorToken);
    $simpleId = (int) ($simple['body']['data']['montajes'][0] ?? 0);
    $cerradoSimple = montajeRow($pdo, $m1);
    $nuevoSimple = montajeRow($pdo, $simpleId);
    $movSimple = movimientoDe($pdo, (int) ($simple['body']['data']['movimientos'][0] ?? 0));
    record('31', 'Rotación a posición libre', $simple['status'] === 201 && (int) $nuevoSimple['posicion_id'] === $p4, codeOf($simple));
    record('32', 'Sigue montado tras rotar', estadoCodigo($pdo, $t1) === 'MONTADO' && ($simple['body']['data']['estado'] ?? '') === 'MONTADO', estadoCodigo($pdo, $t1));
    record('33', 'Rotación sin historial de estado', historialCount($pdo, $t1) === $histAntes, (string) historialCount($pdo, $t1));
    record('34', 'Cierra el montaje anterior', $cerradoSimple['fecha_desmontaje'] !== null, (string) $cerradoSimple['fecha_desmontaje']);
    record('35', 'Crea el montaje nuevo', $nuevoSimple['fecha_desmontaje'] === null && (int) $nuevoSimple['unidad_id'] === $unidad, (string) $nuevoSimple['id']);
    record('36', 'Movimiento ROTACION', ($movSimple['codigo'] ?? '') === 'ROTACION' && (int) ($movSimple['posicion_origen_id'] ?? 0) === $p1 && (int) ($movSimple['posicion_destino_id'] ?? 0) === $p4, (string) ($movSimple['codigo'] ?? ''));

    $intercambio = callApi($kernel, 'POST', '/api/v1/rotaciones', rotacion($unidad, $fecha2, 141000, [
        ['neumatico_id' => $t1, 'posicion_destino_id' => $p2],
        ['neumatico_id' => $t2, 'posicion_destino_id' => $p4],
    ]), $adminToken);
    $grupos = gruposDe($pdo, $intercambio['body']['data']['movimientos'] ?? []);
    $posT1 = posicionActiva($pdo, $t1);
    $posT2 = posicionActiva($pdo, $t2);
    record('37', 'Intercambio de dos neumáticos', $intercambio['status'] === 201 && $posT1 === $p2 && $posT2 === $p4, $posT1 . '/' . $posT2);
    record('38', 'Mismo grupo de operación', count($grupos) === 1 && strlen((string) ($grupos[0] ?? '')) === 36 && ($intercambio['body']['data']['grupo_operacion'] ?? '') === ($grupos[0] ?? ''), (string) ($grupos[0] ?? ''));

    $ciclo = callApi($kernel, 'POST', '/api/v1/rotaciones', rotacion($unidad, $fecha2, 142000, [
        ['neumatico_id' => $t1, 'posicion_destino_id' => $p3],
        ['neumatico_id' => $t3, 'posicion_destino_id' => $p4],
        ['neumatico_id' => $t2, 'posicion_destino_id' => $p2],
    ]), $adminToken);
    record('39', 'Ciclo de tres neumáticos', $ciclo['status'] === 201 && posicionActiva($pdo, $t1) === $p3 && posicionActiva($pdo, $t3) === $p4 && posicionActiva($pdo, $t2) === $p2 && count(array_unique(gruposDe($pdo, $ciclo['body']['data']['movimientos'] ?? []))) === 1, codeOf($ciclo));
    $antesFallo = (int) $pdo->query('SELECT COUNT(*) FROM movimientos_neumatico')->fetchColumn();
    $idsAntes = idsActivos($pdo, $unidad);
    $duplicado = callApi($kernel, 'POST', '/api/v1/rotaciones', rotacion($unidad, $fecha2, 143000, [
        ['neumatico_id' => $t1, 'posicion_destino_id' => $p1],
        ['neumatico_id' => $t2, 'posicion_destino_id' => $p1],
    ]), $adminToken);
    record('40', 'Destino duplicado', $duplicado['status'] === 422 && (int) $pdo->query('SELECT COUNT(*) FROM movimientos_neumatico')->fetchColumn() === $antesFallo && idsActivos($pdo, $unidad) === $idsAntes, codeOf($duplicado));
    $m4 = (int) callApi($kernel, 'POST', '/api/v1/montajes', montaje($t4, $unidad, $p1, $fecha2, 142000), $adminToken)['body']['data']['id'];
    $externo = callApi($kernel, 'POST', '/api/v1/rotaciones', rotacion($unidad, $fecha2, 143000, [
        ['neumatico_id' => $t2, 'posicion_destino_id' => $p1],
    ]), $adminToken);
    record('41', 'Destino ocupado por un neumático fuera del grupo', $externo['status'] === 409 && posicionActiva($pdo, $t4) === $p1 && posicionActiva($pdo, $t2) === $p2, codeOf($externo));
    record('42', 'La rotación rechazada no deja cambios parciales', count(idsActivos($pdo, $unidad)) === 4 && posicionActiva($pdo, $t4) === $p1 && posicionActiva($pdo, $t2) === $p2 && estadoCodigo($pdo, $t2) === 'MONTADO', 'activos ' . count(idsActivos($pdo, $unidad)));

    $histT5 = historialCount($pdo, $t5);
    $origenT5 = (int) callApi($kernel, 'POST', '/api/v1/montajes', montaje($t5, $unidad2, $p4, $fecha2, 142000), $adminToken)['body']['data']['id'];
    $transferencia = callApi($kernel, 'POST', '/api/v1/transferencias', transferencia($t5, $puente, $p1, $fecha2, 150000), $gestorToken);
    $destinoT5 = (int) ($transferencia['body']['data']['id'] ?? 0);
    $movTransfer = movimientoDe($pdo, (int) ($transferencia['body']['data']['movimiento_id'] ?? 0));
    record('43', 'Transferencia válida', $transferencia['status'] === 201, codeOf($transferencia));
    record('44', 'Sigue montado tras transferir', estadoCodigo($pdo, $t5) === 'MONTADO', estadoCodigo($pdo, $t5));
    record('45', 'Transferencia sin historial de estado', historialCount($pdo, $t5) === $histT5 + 1, (string) historialCount($pdo, $t5));
    record('46', 'Cierra el montaje de origen', montajeRow($pdo, $origenT5)['fecha_desmontaje'] !== null, (string) montajeRow($pdo, $origenT5)['fecha_desmontaje']);
    record('47', 'Crea el montaje de destino', montajeRow($pdo, $destinoT5)['fecha_desmontaje'] === null && (int) montajeRow($pdo, $destinoT5)['unidad_id'] === $puente, (string) $destinoT5);
    record('48', 'Movimiento con origen y destino', ($movTransfer['codigo'] ?? '') === 'TRANSFERENCIA' && (int) ($movTransfer['unidad_origen_id'] ?? 0) === $unidad2 && (int) ($movTransfer['posicion_origen_id'] ?? 0) === $p4 && (int) ($movTransfer['unidad_destino_id'] ?? 0) === $puente && (int) ($movTransfer['posicion_destino_id'] ?? 0) === $p1, (string) ($movTransfer['codigo'] ?? ''));
    record('49', 'Otra unidad del mismo cliente', (int) ($transferencia['body']['data']['unidad_destino_id'] ?? 0) === $puente, (string) $puente);
    $otroCliente = callApi($kernel, 'POST', '/api/v1/transferencias', transferencia($t5, $unidadAjena, $p2, $fecha2, 151000), $adminToken);
    record('50', 'Unidad de otro cliente', $otroCliente['status'] === 422 && (int) montajeRow($pdo, $destinoT5)['unidad_id'] === $puente, codeOf($otroCliente));
    $t6 = neumaticoId($kernel, $adminToken, $propioId, $modeloId, $medidaId, 'T6-' . $sufijo);
    callApi($kernel, 'POST', '/api/v1/montajes', montaje($tajeno, $unidadAjena, $p3, $fecha, 1000), $adminToken);
    callApi($kernel, 'POST', '/api/v1/montajes', montaje($t6, $puente, $p2, $fecha2, 150000), $adminToken);
    $antesTransfer = (int) $pdo->query('SELECT COUNT(*) FROM montajes_neumatico')->fetchColumn();
    $chocada = callApi($kernel, 'POST', '/api/v1/transferencias', transferencia($t5, $puente, $p2, $fecha2, 152000), $adminToken);
    record('51', 'Posición destino ocupada', $chocada['status'] === 409, codeOf($chocada));
    record('52', 'La transferencia rechazada conserva el origen', montajeRow($pdo, $destinoT5)['fecha_desmontaje'] === null && (int) $pdo->query('SELECT COUNT(*) FROM montajes_neumatico')->fetchColumn() === $antesTransfer && posicionActiva($pdo, $t5) === $p1, 'montajes ' . $antesTransfer);

    $cambioConHistoria = callApi($kernel, 'PUT', '/api/v1/unidades/' . $unidad, edicionUnidad($tipo, 'U-' . $sufijo, $otraId), $adminToken);
    record('53', 'Montaje histórico bloquea la configuración', $cambioConHistoria['status'] === 409 && ($cambioConHistoria['body']['err']['code'] ?? '') === 'UNIT_CONFIGURATION_LOCKED', codeOf($cambioConHistoria));
    insertarMovimiento($pdo, $propioId, $t1, $soloOrigen, $p1, null, null, $admin, $fecha);
    $cambioOrigen = callApi($kernel, 'PUT', '/api/v1/unidades/' . $soloOrigen, edicionUnidad($tipo, 'UO-' . $sufijo, $otraId), $adminToken);
    record('54', 'Movimiento con posición origen bloquea la configuración', $cambioOrigen['status'] === 409 && ($cambioOrigen['body']['err']['code'] ?? '') === 'UNIT_CONFIGURATION_LOCKED', codeOf($cambioOrigen));
    insertarMovimiento($pdo, $propioId, $t1, null, null, $soloDestino, $p2, $admin, $fecha);
    $cambioDestino = callApi($kernel, 'PUT', '/api/v1/unidades/' . $soloDestino, edicionUnidad($tipo, 'UD-' . $sufijo, $otraId), $adminToken);
    record('55', 'Movimiento con posición destino bloquea la configuración', $cambioDestino['status'] === 409 && ($cambioDestino['body']['err']['code'] ?? '') === 'UNIT_CONFIGURATION_LOCKED', codeOf($cambioDestino));
    $cambioLibre = callApi($kernel, 'PUT', '/api/v1/unidades/' . $libre, edicionUnidad($tipo, 'UL-' . $sufijo, $otraId), $adminToken);
    record('56', 'Unidad sin histórico puede cambiar de configuración', $cambioLibre['status'] === 200 && (int) ($cambioLibre['body']['data']['configuracion']['id'] ?? 0) === $otraId, codeOf($cambioLibre));

    $tipoMontaje = (int) $pdo->query("SELECT id FROM tipos_movimiento WHERE codigo = 'MONTAJE'")->fetchColumn();
    $lista = callApi($kernel, 'GET', '/api/v1/movimientos-neumatico?limit=20', null, $adminToken);
    $porNeumatico = callApi($kernel, 'GET', '/api/v1/movimientos-neumatico?neumatico_id=' . $t5, null, $adminToken);
    $porUnidad = callApi($kernel, 'GET', '/api/v1/unidades/' . $unidad2 . '/movimientos', null, $gestorToken);
    $porTipo = callApi($kernel, 'GET', '/api/v1/movimientos-neumatico?tipo_movimiento_id=' . $tipoMontaje . '&neumatico_id=' . $t1, null, $adminToken);
    $rango = callApi($kernel, 'GET', '/api/v1/movimientos-neumatico?neumatico_id=' . $t1 . '&fecha_inicio=2026-10-05&fecha_fin=2026-10-05', null, $adminToken);
    $fuera = callApi($kernel, 'GET', '/api/v1/movimientos-neumatico?neumatico_id=' . $t1 . '&fecha_inicio=2020-01-01&fecha_fin=2020-01-02', null, $adminToken);
    $gestorLista = callApi($kernel, 'GET', '/api/v1/movimientos-neumatico?limit=100', null, $gestorToken);
    $codigosGestor = array_map(static fn (array $row): int => (int) $row['neumatico']['id'], $gestorLista['body']['data'] ?? []);
    $clienteAjeno = callApi($kernel, 'GET', '/api/v1/movimientos-neumatico?cliente_id=' . $ajenoId, null, $gestorToken);
    $idor = callApi($kernel, 'GET', '/api/v1/neumaticos/' . $tajeno . '/movimientos', null, $gestorToken);
    $lecturaTecnico = callApi($kernel, 'GET', '/api/v1/neumaticos/' . $t1 . '/montajes', null, $tecnicoToken);
    $lecturaVendedor = callApi($kernel, 'GET', '/api/v1/neumaticos/' . $t1 . '/movimientos', null, $vendedorToken);
    record('57', 'Listado de movimientos', $lista['status'] === 200 && ($lista['body']['total'] ?? 0) >= 1, 'total ' . ($lista['body']['total'] ?? 0));
    record('58', 'Filtro por neumático', $porNeumatico['status'] === 200 && ($porNeumatico['body']['total'] ?? 0) >= 1 && ($porNeumatico['body']['data'][0]['neumatico']['id'] ?? 0) === $t5, 'total ' . ($porNeumatico['body']['total'] ?? 0));
    record('59', 'Filtro por unidad', $porUnidad['status'] === 200 && ($porUnidad['body']['total'] ?? 0) >= 1, 'total ' . ($porUnidad['body']['total'] ?? 0));
    record('60', 'Filtro por tipo', $porTipo['status'] === 200 && ($porTipo['body']['total'] ?? 0) >= 1 && ($porTipo['body']['data'][0]['tipo']['codigo'] ?? '') === 'MONTAJE', (string) ($porTipo['body']['data'][0]['tipo']['codigo'] ?? ''));
    record('61', 'Rango de fechas', $rango['status'] === 200 && ($rango['body']['total'] ?? 0) >= 1 && ($fuera['body']['total'] ?? 1) === 0, 'dentro ' . ($rango['body']['total'] ?? 0));
    record('62', 'Usuario limitado ve solo su scope', $gestorLista['status'] === 200 && !in_array($tajeno, $codigosGestor, true) && $lecturaTecnico['status'] === 200 && $lecturaVendedor['status'] === 200, 'filas ' . count($codigosGestor));
    record('63', 'cliente_id ajeno', $clienteAjeno['status'] === 403 && ($clienteAjeno['body']['err']['code'] ?? '') === 'CLIENT_SCOPE_FORBIDDEN', codeOf($clienteAjeno));
    record('64', 'IDOR de neumático', $idor['status'] === 403 && ($idor['body']['err']['code'] ?? '') === 'CLIENT_SCOPE_FORBIDDEN', codeOf($idor));
    unset($m2, $m3, $m4);
} catch (Throwable $error) {
    $failed = true;
    echo 'EXCEPCION ' . $error->getMessage() . ' en ' . $error->getFile() . ':' . $error->getLine() . PHP_EOL;
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

try {
    $concurrencia = concurrencia($kernel, $pdo);
} catch (Throwable $error) {
    $failed = true;
    echo 'EXCEPCION CONCURRENCIA ' . $error->getMessage() . ' en ' . $error->getFile() . ':' . $error->getLine() . PHP_EOL;
} finally {
    limpiarConcurrencia($pdo, $concurrencia);
    $pdo->exec('SET innodb_lock_wait_timeout = 50');
}

$after = counts($pdo);
record('CLEAN', 'La transacción no dejó datos', $before === $after, json_encode($before) . ' → ' . json_encode($after));

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

/** @return array<string, int> */
function concurrencia(Kernel $kernel, PDO $pdo): array
{
    $password = bin2hex(random_bytes(8));
    $admin = user($pdo, 'Admin', 'B4C', 'ADMIN_GENERAL', $password);
    $token = token($kernel, emailOf($pdo, $admin), $password);
    $sufijo = strtoupper(bin2hex(random_bytes(3)));
    $clienteId = (int) cliente($kernel, $token, 'B4C ' . $sufijo)['body']['data']['id'];
    $marcaId = (int) callApi($kernel, 'POST', '/api/v1/marcas-neumatico', ['nombre' => 'CMarca ' . $sufijo], $token)['body']['data']['id'];
    $modeloId = (int) callApi($kernel, 'POST', '/api/v1/modelos-neumatico', ['marca_id' => $marcaId, 'nombre' => 'CModelo ' . $sufijo], $token)['body']['data']['id'];
    $medidaId = (int) callApi($kernel, 'POST', '/api/v1/medidas-neumatico', ['descripcion' => 'CMedida ' . $sufijo], $token)['body']['data']['id'];
    $tipo = (int) $pdo->query("SELECT id FROM tipos_unidad WHERE codigo = 'CAMION'")->fetchColumn();
    $config = callApi($kernel, 'POST', '/api/v1/configuraciones-unidad', configuracion('CCfg ' . $sufijo), $token);
    $configId = (int) $config['body']['data']['id'];
    $posicion = (int) $config['body']['data']['ejes'][0]['posiciones'][0]['id'];
    $unidad = (int) callApi($kernel, 'POST', '/api/v1/unidades', unidadBody($clienteId, $tipo, 'CU-' . $sufijo, $configId), $token)['body']['data']['id'];
    $primero = neumaticoId($kernel, $token, $clienteId, $modeloId, $medidaId, 'C1-' . $sufijo);
    $segundo = neumaticoId($kernel, $token, $clienteId, $modeloId, $medidaId, 'C2-' . $sufijo);
    $fecha = '2026-10-05 11:00:00';
    $extra = conexionAdicional();
    $extra->beginTransaction();
    $bloqueo = $extra->prepare('SELECT id FROM unidades WHERE id = :id FOR UPDATE');
    $bloqueo->bindValue('id', $unidad, PDO::PARAM_INT);
    $bloqueo->execute();
    $pdo->exec('SET innodb_lock_wait_timeout = 2');
    $espera = callApi($kernel, 'POST', '/api/v1/montajes', montaje($primero, $unidad, $posicion, $fecha, 1000), $token);
    $extra->rollBack();
    $confirmado = callApi($kernel, 'POST', '/api/v1/montajes', montaje($primero, $unidad, $posicion, $fecha, 1000), $token);
    $mismo = callApi($kernel, 'POST', '/api/v1/montajes', montaje($primero, $unidad, $posicion, $fecha, 1000), $token);
    record('18', 'Dos intentos sobre el mismo neumático', $espera['status'] === 409 && $confirmado['status'] === 201 && $mismo['status'] === 409 && ($mismo['body']['err']['code'] ?? '') === 'CONFLICT', codeOf($espera) . ' / ' . codeOf($confirmado));
    $extra->beginTransaction();
    $bloqueo->execute();
    $esperaPosicion = callApi($kernel, 'POST', '/api/v1/montajes', montaje($segundo, $unidad, $posicion, $fecha, 1000), $token);
    $extra->rollBack();
    $ocupada = callApi($kernel, 'POST', '/api/v1/montajes', montaje($segundo, $unidad, $posicion, $fecha, 1000), $token);
    $montado = estadoCodigo($pdo, $primero);
    $libre = estadoCodigo($pdo, $segundo);
    $activos = (int) $pdo->query('SELECT COUNT(*) FROM montajes_neumatico WHERE unidad_id = ' . $unidad . ' AND fecha_desmontaje IS NULL AND anulado = 0')->fetchColumn();
    record('19', 'Dos neumáticos hacia la misma posición', $esperaPosicion['status'] === 409 && $ocupada['status'] === 409, codeOf($esperaPosicion) . ' / ' . codeOf($ocupada));
    record('20', 'El resultado final queda consistente', $montado === 'MONTADO' && $libre === 'DISPONIBLE' && $activos === 1, $montado . '/' . $libre . '/' . $activos);
    record('21', 'La concurrencia no produce error 500', $espera['status'] !== 500 && $mismo['status'] !== 500 && $esperaPosicion['status'] !== 500 && $ocupada['status'] !== 500, 'sin 500');

    return [
        'usuarios' => [$admin],
        'clientes' => [$clienteId],
        'configuraciones' => [$configId],
        'marcas' => [$marcaId],
        'modelos' => [$modeloId],
        'medidas' => [$medidaId],
    ];
}

/** @param array<string, mixed> $creados */
function limpiarConcurrencia(PDO $pdo, array $creados): void
{
    if ($creados === []) {
        return;
    }
    $clientes = implode(',', array_map('intval', $creados['clientes']));
    $configs = implode(',', array_map('intval', $creados['configuraciones']));
    $usuarios = implode(',', array_map('intval', $creados['usuarios']));
    $pdo->exec("DELETE h FROM neumatico_estado_historial h INNER JOIN neumaticos n ON n.id = h.neumatico_id WHERE n.cliente_id IN ($clientes)");
    $pdo->exec("DELETE FROM movimientos_neumatico WHERE cliente_id IN ($clientes)");
    $pdo->exec("DELETE FROM montajes_neumatico WHERE cliente_id IN ($clientes)");
    $pdo->exec("DELETE v FROM neumatico_vidas v INNER JOIN neumaticos n ON n.id = v.neumatico_id WHERE n.cliente_id IN ($clientes)");
    $pdo->exec("DELETE FROM auditoria WHERE cliente_id IN ($clientes) OR usuario_id IN ($usuarios)");
    $pdo->exec("DELETE FROM neumaticos WHERE cliente_id IN ($clientes)");
    $pdo->exec("DELETE FROM unidades WHERE cliente_id IN ($clientes)");
    $pdo->exec("DELETE FROM configuracion_posiciones WHERE configuracion_id IN ($configs)");
    $pdo->exec("DELETE FROM configuracion_ejes WHERE configuracion_id IN ($configs)");
    $pdo->exec("DELETE FROM configuraciones_unidad WHERE id IN ($configs)");
    $pdo->exec("DELETE FROM usuario_clientes WHERE usuario_id IN ($usuarios) OR cliente_id IN ($clientes)");
    $pdo->exec("DELETE FROM usuario_roles WHERE usuario_id IN ($usuarios)");
    $modelos = implode(',', array_map('intval', $creados['modelos'] ?? []));
    $marcas = implode(',', array_map('intval', $creados['marcas'] ?? []));
    $medidas = implode(',', array_map('intval', $creados['medidas'] ?? []));
    if ($modelos !== '') {
        $pdo->exec("DELETE FROM modelos_neumatico WHERE id IN ($modelos)");
    }
    if ($marcas !== '') {
        $pdo->exec("DELETE FROM marcas_neumatico WHERE id IN ($marcas)");
    }
    if ($medidas !== '') {
        $pdo->exec("DELETE FROM medidas_neumatico WHERE id IN ($medidas)");
    }
    $pdo->exec("DELETE FROM clientes WHERE id IN ($clientes)");
    $pdo->exec("DELETE FROM usuarios WHERE id IN ($usuarios)");
}

function conexionAdicional(): PDO
{
    $config = Config::fromEnvFile(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');
    $port = $config->int('DB_PORT', 3306);
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config->get('DB_HOST'), $port, $config->get('DB_DATABASE')),
        $config->get('DB_USERNAME'),
        $config->optional('DB_PASSWORD', ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false],
    );
    $pdo->exec('SET time_zone = ' . $pdo->quote($config->get('APP_TIMEZONE', '-05:00')));

    return $pdo;
}

function record(string $id, string $name, bool $ok, string $evidence): void
{
    global $results;
    $results[] = ['status' => $ok ? 'PASS' : 'FAIL', 'id' => $id, 'name' => $name, 'evidence' => $evidence];
}

/** @return array{status:int,body:array<string,mixed>,headers:array<string,string>} */
function callApi(Kernel $kernel, string $method, string $path, ?array $json = null, ?string $token = null): array
{
    $headers = ['user-agent' => 'bloque4-test', 'x-client-ip' => '203.0.113.21'];
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

function user(PDO $pdo, string $nombres, string $apellidos, string $rol, string $password): int
{
    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]);
    $statement = $pdo->prepare(
        'INSERT INTO usuarios (nombres, apellidos, email, password_hash, estado, eliminado)
         VALUES (:nombres, :apellidos, :email, :password_hash, \'ACTIVO\', 0)'
    );
    $statement->execute([
        'nombres' => $nombres,
        'apellidos' => $apellidos,
        'email' => 'b4.' . bin2hex(random_bytes(5)) . '@test.local',
        'password_hash' => $hash,
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
    $response = callApi($kernel, 'POST', '/api/v1/auth/login', ['email' => $email, 'password' => $password]);
    if (($response['body']['data']['token'] ?? null) === null) {
        throw new RuntimeException('No se pudo autenticar ' . $email . ' HTTP ' . $response['status']);
    }

    return (string) $response['body']['data']['token'];
}

/** @return array{status:int,body:array<string,mixed>,headers:array<string,string>} */
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

/** @return array<string, mixed> */
function configuracion(string $nombre, string $prefijo = 'E'): array
{
    return [
        'nombre' => $nombre,
        'descripcion' => 'Plantilla de operación',
        'ejes' => [
            [
                'numero_eje' => 1,
                'nombre' => 'Delantero',
                'orden' => 1,
                'posiciones' => [
                    ['codigo' => $prefijo . '1-I', 'lado' => 'IZQUIERDO', 'ubicacion' => 'SIMPLE', 'orden' => 1],
                    ['codigo' => $prefijo . '1-D', 'lado' => 'DERECHO', 'ubicacion' => 'SIMPLE', 'orden' => 2],
                ],
            ],
            [
                'numero_eje' => 2,
                'nombre' => 'Posterior',
                'orden' => 2,
                'posiciones' => [
                    ['codigo' => $prefijo . '2-I', 'lado' => 'IZQUIERDO', 'ubicacion' => 'EXTERIOR', 'orden' => 3],
                    ['codigo' => $prefijo . '2-D', 'lado' => 'DERECHO', 'ubicacion' => 'EXTERIOR', 'orden' => 4],
                ],
            ],
        ],
    ];
}

/** @param array<string, mixed> $config @return list<int> */
function posicionesDe(array $config): array
{
    $ids = [];
    foreach ($config['ejes'] as $eje) {
        foreach ($eje['posiciones'] as $posicion) {
            $ids[] = (int) $posicion['id'];
        }
    }

    return $ids;
}

/** @param array<string, mixed> $extra @return array<string, mixed> */
function unidadBody(int $clienteId, int $tipoId, string $codigo, ?int $configuracionId, array $extra = []): array
{
    return array_merge([
        'cliente_id' => $clienteId,
        'tipo_unidad_id' => $tipoId,
        'codigo' => $codigo,
        'sede_id' => null,
        'flota_id' => null,
        'configuracion_id' => $configuracionId,
    ], $extra);
}

/** @return array<string, mixed> */
function edicionUnidad(int $tipoId, string $codigo, int $configuracionId): array
{
    return [
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
        throw new RuntimeException('No se pudo crear el neumático ' . $codigo . ' HTTP ' . $response['status']);
    }

    return (int) $response['body']['data']['id'];
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
        'motivo_desmontaje' => 'Rotación programada',
    ];
}

/** @param list<array{neumatico_id:int,posicion_destino_id:int}> $cambios @return array<string, mixed> */
function rotacion(int $unidad, string $fecha, int $km, array $cambios): array
{
    return [
        'unidad_id' => $unidad,
        'fecha' => $fecha,
        'km_unidad' => $km,
        'horometro_unidad' => null,
        'cambios' => $cambios,
        'observacion' => 'Rotación preventiva',
    ];
}

/** @return array<string, mixed> */
function transferencia(int $neumatico, int $unidad, int $posicion, string $fecha, int $km): array
{
    return [
        'neumatico_id' => $neumatico,
        'unidad_destino_id' => $unidad,
        'posicion_destino_id' => $posicion,
        'fecha' => $fecha,
        'km_unidad' => $km,
        'horometro_unidad' => null,
        'observacion' => 'Cambio de unidad',
    ];
}

function estadoCodigo(PDO $pdo, int $id): string
{
    $statement = $pdo->prepare(
        'SELECT e.codigo FROM neumaticos n INNER JOIN estados_neumatico e ON e.id = n.estado_id WHERE n.id = :id'
    );
    $statement->bindValue('id', $id, PDO::PARAM_INT);
    $statement->execute();

    return (string) $statement->fetchColumn();
}

/** @return array<string, mixed> */
function enlaceHistorial(PDO $pdo, int $neumaticoId, string $tipo): array
{
    $statement = $pdo->prepare(
        'SELECT h.movimiento_id, ea.codigo AS anterior, en.codigo AS nuevo, tm.codigo,
                m.unidad_origen_id, m.posicion_origen_id, m.unidad_destino_id, m.posicion_destino_id
         FROM neumatico_estado_historial h
         INNER JOIN movimientos_neumatico m ON m.id = h.movimiento_id
         INNER JOIN tipos_movimiento tm ON tm.id = m.tipo_movimiento_id
         INNER JOIN estados_neumatico en ON en.id = h.estado_nuevo_id
         LEFT JOIN estados_neumatico ea ON ea.id = h.estado_anterior_id
         WHERE h.neumatico_id = :id AND tm.codigo = :tipo
         ORDER BY h.id DESC LIMIT 1'
    );
    $statement->bindValue('id', $neumaticoId, PDO::PARAM_INT);
    $statement->bindValue('tipo', $tipo);
    $statement->execute();
    $row = $statement->fetch();

    return $row === false ? [] : $row;
}

/** @return array<string, mixed> */
function montajeRow(PDO $pdo, int $id): array
{
    $statement = $pdo->prepare(
        'SELECT id, unidad_id, posicion_id, fecha_desmontaje, km_desmontaje FROM montajes_neumatico WHERE id = :id'
    );
    $statement->bindValue('id', $id, PDO::PARAM_INT);
    $statement->execute();
    $row = $statement->fetch();

    return $row === false ? [] : $row;
}

/** @return array<string, mixed> */
function movimientoDe(PDO $pdo, int $id): array
{
    $statement = $pdo->prepare(
        'SELECT tm.codigo, m.unidad_origen_id, m.posicion_origen_id, m.unidad_destino_id, m.posicion_destino_id, m.grupo_operacion
         FROM movimientos_neumatico m INNER JOIN tipos_movimiento tm ON tm.id = m.tipo_movimiento_id WHERE m.id = :id'
    );
    $statement->bindValue('id', $id, PDO::PARAM_INT);
    $statement->execute();
    $row = $statement->fetch();

    return $row === false ? [] : $row;
}

function historialCount(PDO $pdo, int $neumaticoId): int
{
    $statement = $pdo->prepare('SELECT COUNT(*) FROM neumatico_estado_historial WHERE neumatico_id = :id');
    $statement->bindValue('id', $neumaticoId, PDO::PARAM_INT);
    $statement->execute();

    return (int) $statement->fetchColumn();
}

function posicionActiva(PDO $pdo, int $neumaticoId): int
{
    $statement = $pdo->prepare(
        'SELECT posicion_id FROM montajes_neumatico WHERE neumatico_id = :id AND fecha_desmontaje IS NULL AND anulado = 0'
    );
    $statement->bindValue('id', $neumaticoId, PDO::PARAM_INT);
    $statement->execute();

    return (int) $statement->fetchColumn();
}

/** @return list<int> */
function idsActivos(PDO $pdo, int $unidadId): array
{
    $statement = $pdo->prepare(
        'SELECT id FROM montajes_neumatico WHERE unidad_id = :id AND fecha_desmontaje IS NULL AND anulado = 0 ORDER BY id'
    );
    $statement->bindValue('id', $unidadId, PDO::PARAM_INT);
    $statement->execute();

    return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
}

/** @param list<int> $neumaticos @return list<int> */
function idsActivosEsperados(PDO $pdo, array $neumaticos): array
{
    $ids = [];
    foreach ($neumaticos as $neumatico) {
        $id = posicionActiva($pdo, $neumatico);
        if ($id > 0) {
            $ids[] = $neumatico;
        }
    }
    sort($ids);

    return $ids;
}

/** @param list<int> $movimientos @return list<string> */
function gruposDe(PDO $pdo, array $movimientos): array
{
    $grupos = [];
    foreach ($movimientos as $id) {
        $row = movimientoDe($pdo, (int) $id);
        if (($row['grupo_operacion'] ?? null) !== null) {
            $grupos[] = (string) $row['grupo_operacion'];
        }
    }

    return array_values(array_unique($grupos));
}

function insertarMovimiento(
    PDO $pdo,
    int $clienteId,
    int $neumaticoId,
    ?int $unidadOrigen,
    ?int $posicionOrigen,
    ?int $unidadDestino,
    ?int $posicionDestino,
    int $usuarioId,
    string $fecha,
): void {
    $tipo = (int) $pdo->query("SELECT id FROM tipos_movimiento WHERE codigo = 'ROTACION'")->fetchColumn();
    $statement = $pdo->prepare(
        'INSERT INTO movimientos_neumatico (
            cliente_id, neumatico_id, tipo_movimiento_id, fecha, unidad_origen_id, posicion_origen_id,
            unidad_destino_id, posicion_destino_id, usuario_id, anulado
         ) VALUES (
            :cliente, :neumatico, :tipo, :fecha, :origen_u, :origen_p, :destino_u, :destino_p, :usuario, 0
         )'
    );
    $statement->bindValue('cliente', $clienteId, PDO::PARAM_INT);
    $statement->bindValue('neumatico', $neumaticoId, PDO::PARAM_INT);
    $statement->bindValue('tipo', $tipo, PDO::PARAM_INT);
    $statement->bindValue('fecha', $fecha);
    $statement->bindValue('origen_u', $unidadOrigen, $unidadOrigen === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $statement->bindValue('origen_p', $posicionOrigen, $posicionOrigen === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $statement->bindValue('destino_u', $unidadDestino, $unidadDestino === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $statement->bindValue('destino_p', $posicionDestino, $posicionDestino === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $statement->bindValue('usuario', $usuarioId, PDO::PARAM_INT);
    $statement->execute();
}

function audit(PDO $pdo, string $accion, int $entidadId): int
{
    $statement = $pdo->prepare('SELECT COUNT(*) FROM auditoria WHERE accion = :accion AND entidad_id = :id AND ip = :ip');
    $statement->execute(['accion' => $accion, 'id' => $entidadId, 'ip' => '203.0.113.21']);

    return (int) $statement->fetchColumn();
}

/** @return array<string, int> */
function counts(PDO $pdo): array
{
    $tables = ['usuarios', 'clientes', 'auditoria', 'usuario_clientes', 'marcas_neumatico', 'modelos_neumatico', 'medidas_neumatico', 'estados_neumatico', 'neumaticos', 'neumatico_estado_historial', 'neumatico_vidas', 'montajes_neumatico', 'movimientos_neumatico', 'configuraciones_unidad', 'unidades'];
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
