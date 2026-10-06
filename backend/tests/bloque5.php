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
$pdo->beginTransaction();
$failed = false;
$fecha = '2026-10-05 10:00:00';
$clientesArchivo = [];

try {
    $password = bin2hex(random_bytes(8));
    $admin = user($pdo, 'Admin', 'B5', 'ADMIN_GENERAL', $password);
    $gestor = user($pdo, 'Gestor', 'B5', 'GESTOR_NEUMATICOS', $password);
    $tecnico = user($pdo, 'Tecnico', 'B5', 'TECNICO_INSPECCION', $password);
    $vendedor = user($pdo, 'Vendedor', 'B5', 'VENDEDOR', $password);
    $clienteAdmin = user($pdo, 'Cliente', 'B5', 'ADMIN_CLIENTE', $password);
    $consulta = user($pdo, 'Consulta', 'B5', 'CONSULTA_EJECUTIVA', $password);
    $adminToken = token($kernel, emailOf($pdo, $admin), $password);
    $gestorToken = token($kernel, emailOf($pdo, $gestor), $password);
    $tecnicoToken = token($kernel, emailOf($pdo, $tecnico), $password);
    $vendedorToken = token($kernel, emailOf($pdo, $vendedor), $password);
    $clienteToken = token($kernel, emailOf($pdo, $clienteAdmin), $password);
    $consultaToken = token($kernel, emailOf($pdo, $consulta), $password);
    $sufijo = strtoupper(bin2hex(random_bytes(3)));
    $propioId = (int) cliente($kernel, $adminToken, 'B5 ' . $sufijo . ' Propio')['body']['data']['id'];
    $ajenoId = (int) cliente($kernel, $adminToken, 'B5 ' . $sufijo . ' Ajeno')['body']['data']['id'];
    $clientesArchivo = [$propioId, $ajenoId];
    scope($pdo, $gestor, $propioId, $hoy);
    scope($pdo, $tecnico, $propioId, $hoy);
    scope($pdo, $vendedor, $propioId, $hoy);
    scope($pdo, $clienteAdmin, $propioId, $hoy);
    scope($pdo, $consulta, $propioId, $hoy);

    $marcaId = (int) callApi($kernel, 'POST', '/api/v1/marcas-neumatico', ['nombre' => 'Marca ' . $sufijo], $adminToken)['body']['data']['id'];
    $modeloId = (int) callApi($kernel, 'POST', '/api/v1/modelos-neumatico', ['marca_id' => $marcaId, 'nombre' => 'Modelo ' . $sufijo], $adminToken)['body']['data']['id'];
    $medidaId = (int) callApi($kernel, 'POST', '/api/v1/medidas-neumatico', ['descripcion' => '295/80 R22.5 ' . $sufijo], $adminToken)['body']['data']['id'];
    $tipo = (int) $pdo->query("SELECT id FROM tipos_unidad WHERE codigo = 'CAMION'")->fetchColumn();
    $config = callApi($kernel, 'POST', '/api/v1/configuraciones-unidad', configuracion('Cfg ' . $sufijo), $adminToken);
    $configId = (int) $config['body']['data']['id'];
    $posiciones = posicionesDe($config['body']['data']);
    $otra = callApi($kernel, 'POST', '/api/v1/configuraciones-unidad', configuracion('Cfg B ' . $sufijo, 'B'), $adminToken);
    $posicionAjena = (int) $otra['body']['data']['ejes'][0]['posiciones'][0]['id'];
    $p1 = $posiciones[0];
    $p2 = $posiciones[1];
    $p3 = $posiciones[2];
    $p4 = $posiciones[3];
    $unidad = (int) callApi($kernel, 'POST', '/api/v1/unidades', unidadBody($propioId, $tipo, 'U-' . $sufijo, $configId, ['kilometraje_actual' => 500000]), $adminToken)['body']['data']['id'];
    $profundidad = (int) callApi($kernel, 'POST', '/api/v1/unidades', unidadBody($propioId, $tipo, 'UP-' . $sufijo, $configId), $adminToken)['body']['data']['id'];
    $danosUnidad = (int) callApi($kernel, 'POST', '/api/v1/unidades', unidadBody($propioId, $tipo, 'UD-' . $sufijo, $configId), $adminToken)['body']['data']['id'];
    $unidadAjena = (int) callApi($kernel, 'POST', '/api/v1/unidades', unidadBody($ajenoId, $tipo, 'UA-' . $sufijo, $configId), $adminToken)['body']['data']['id'];
    $inactiva = (int) callApi($kernel, 'POST', '/api/v1/unidades', unidadBody($propioId, $tipo, 'UI-' . $sufijo, $configId), $adminToken)['body']['data']['id'];
    $baja = (int) callApi($kernel, 'POST', '/api/v1/unidades', unidadBody($propioId, $tipo, 'UB-' . $sufijo, $configId), $adminToken)['body']['data']['id'];
    $sinConfig = (int) callApi($kernel, 'POST', '/api/v1/unidades', unidadBody($propioId, $tipo, 'US-' . $sufijo, null), $adminToken)['body']['data']['id'];
    $eliminada = (int) callApi($kernel, 'POST', '/api/v1/unidades', unidadBody($propioId, $tipo, 'UE-' . $sufijo, $configId), $adminToken)['body']['data']['id'];
    callApi($kernel, 'PATCH', '/api/v1/unidades/' . $inactiva . '/estado', ['estado' => 'INACTIVA'], $adminToken);
    callApi($kernel, 'PATCH', '/api/v1/unidades/' . $baja . '/estado', ['estado' => 'BAJA'], $adminToken);
    callApi($kernel, 'DELETE', '/api/v1/unidades/' . $eliminada, null, $adminToken);

    $t1 = neumaticoId($kernel, $adminToken, $propioId, $modeloId, $medidaId, 'T1-' . $sufijo);
    $tajeno = neumaticoId($kernel, $adminToken, $ajenoId, $modeloId, $medidaId, 'TA-' . $sufijo);
    $montajeT1 = (int) callApi($kernel, 'POST', '/api/v1/montajes', montaje($t1, $unidad, $p1, $fecha, 500000), $adminToken)['body']['data']['id'];
    callApi($kernel, 'POST', '/api/v1/montajes', montaje($tajeno, $unidadAjena, $p1, $fecha, 1000), $adminToken);
    $corte = (int) $pdo->query("SELECT id FROM tipos_dano WHERE codigo = 'CORTE'")->fetchColumn();
    $grieta = (int) $pdo->query("SELECT id FROM tipos_dano WHERE codigo = 'GRIETA'")->fetchColumn();
    $punzon = (int) $pdo->query("SELECT id FROM tipos_dano WHERE codigo = 'PUNZON'")->fetchColumn();
    $abultamiento = (int) $pdo->query("SELECT id FROM tipos_dano WHERE codigo = 'ABULTAMIENTO'")->fetchColumn();

    $alta = callApi($kernel, 'POST', '/api/v1/inspecciones', cabecera($unidad, 800000, '50', null, $ajenoId), $tecnicoToken);
    $inspId = (int) ($alta['body']['data']['id'] ?? 0);
    $unidadAlta = callApi($kernel, 'GET', '/api/v1/unidades/' . $unidad, null, $adminToken);
    $inactivaOk = callApi($kernel, 'POST', '/api/v1/inspecciones', cabecera($inactiva, null, null), $tecnicoToken);
    record('1', 'Crear BORRADOR válido', $alta['status'] === 201 && ($alta['body']['data']['estado'] ?? '') === 'BORRADOR' && (int) ($alta['body']['data']['tecnico']['id'] ?? 0) === $tecnico && (int) ($alta['body']['data']['cliente']['id'] ?? 0) === $propioId && $inactivaOk['status'] === 201, codeOf($alta));
    $ajena = callApi($kernel, 'POST', '/api/v1/inspecciones', cabecera($unidadAjena, 1000, null), $gestorToken);
    record('2', 'Unidad otro cliente', $ajena['status'] === 403 && ($ajena['body']['err']['code'] ?? '') === 'CLIENT_SCOPE_FORBIDDEN', codeOf($ajena));
    $borrada = callApi($kernel, 'POST', '/api/v1/inspecciones', cabecera($eliminada, null, null), $adminToken);
    record('3', 'Unidad eliminada', in_array($borrada['status'], [404, 422], true), codeOf($borrada));
    $bajaResp = callApi($kernel, 'POST', '/api/v1/inspecciones', cabecera($baja, null, null), $adminToken);
    record('4', 'Unidad BAJA', $bajaResp['status'] === 422, codeOf($bajaResp));
    $sin = callApi($kernel, 'POST', '/api/v1/inspecciones', cabecera($sinConfig, null, null), $adminToken);
    record('5', 'Unidad sin configuración', $sin['status'] === 422, codeOf($sin));
    $tecnicoInvalido = callApi($kernel, 'POST', '/api/v1/inspecciones', cabecera($unidad, null, null, 999999999), $adminToken);
    record('6', 'Técnico inválido', $tecnicoInvalido['status'] === 422, codeOf($tecnicoInvalido));
    $sinRol = callApi($kernel, 'POST', '/api/v1/inspecciones', cabecera($unidad, null, null, $vendedor), $adminToken);
    record('7', 'Técnico sin rol compatible', $sinRol['status'] === 422, codeOf($sinRol));
    $kmNegativo = callApi($kernel, 'POST', '/api/v1/inspecciones', cabecera($unidad, -1, null), $tecnicoToken);
    record('8', 'Km negativo', $kmNegativo['status'] === 422, codeOf($kmNegativo));
    $horometroNegativo = callApi($kernel, 'POST', '/api/v1/inspecciones', cabecera($unidad, null, '-1'), $tecnicoToken);
    record('9', 'Horómetro negativo', $horometroNegativo['status'] === 422, codeOf($horometroNegativo));
    record('10', 'Lectura mayor actualiza unidad', (int) ($unidadAlta['body']['data']['kilometraje_actual'] ?? 0) === 800000 && (float) ($unidadAlta['body']['data']['horometro_actual'] ?? 0) === 50.0, (string) ($unidadAlta['body']['data']['kilometraje_actual'] ?? ''));
    $menor = callApi($kernel, 'POST', '/api/v1/inspecciones', cabecera($unidad, 100, '1'), $tecnicoToken);
    $borradorId = (int) ($menor['body']['data']['id'] ?? 0);
    $unidadMenor = callApi($kernel, 'GET', '/api/v1/unidades/' . $unidad, null, $adminToken);
    record('11', 'Lectura menor no reduce contador', $menor['status'] === 201 && (int) ($menor['body']['data']['kilometraje'] ?? 0) === 100 && (int) ($unidadMenor['body']['data']['kilometraje_actual'] ?? 0) === 800000 && (float) ($unidadMenor['body']['data']['horometro_actual'] ?? 0) === 50.0, (string) ($unidadMenor['body']['data']['kilometraje_actual'] ?? ''));

    $detalle = callApi($kernel, 'POST', '/api/v1/inspecciones/' . $inspId . '/detalles', medicion($p1, '10', '11', '12', '90', 'NORMAL'), $tecnicoToken);
    $detalleId = (int) ($detalle['body']['data']['id'] ?? 0);
    record('12', 'Crear detalle para posición montada', $detalle['status'] === 201 && $detalleId > 0, codeOf($detalle));
    record('13', 'Backend resuelve neumático correcto', (int) ($detalle['body']['data']['neumatico']['id'] ?? 0) === $t1, (string) ($detalle['body']['data']['neumatico']['id'] ?? ''));
    record('14', 'Backend resuelve montaje correcto', (int) ($detalle['body']['data']['montaje_id'] ?? 0) === $montajeT1, (string) ($detalle['body']['data']['montaje_id'] ?? ''));
    $snap = $detalle['body']['data']['posicion'] ?? [];
    record('15', 'Snapshot correcto', ($snap['codigo'] ?? '') === 'E1-I' && (int) ($snap['eje_numero'] ?? 0) === 1 && ($snap['eje_nombre'] ?? '') === 'Delantero' && ($snap['lado'] ?? '') === 'IZQUIERDO' && ($snap['ubicacion'] ?? '') === 'SIMPLE', (string) ($snap['codigo'] ?? ''));
    $otraPosicion = callApi($kernel, 'POST', '/api/v1/inspecciones/' . $inspId . '/detalles', medicion($posicionAjena, '10', null, null, null, 'NORMAL'), $tecnicoToken);
    record('16', 'Posición de otra configuración', $otraPosicion['status'] === 422, codeOf($otraPosicion));
    $vacia = callApi($kernel, 'POST', '/api/v1/inspecciones/' . $inspId . '/detalles', medicion($p2, '10', null, null, null, 'NORMAL'), $tecnicoToken);
    record('17', 'Posición vacía no genera detalle', $vacia['status'] === 422, codeOf($vacia));
    $profNegativa = callApi($kernel, 'POST', '/api/v1/inspecciones/' . $inspId . '/detalles', medicion($p2, '-1', null, null, null, 'NORMAL'), $tecnicoToken);
    record('18', 'Profundidad negativa', $profNegativa['status'] === 422, codeOf($profNegativa));
    $presionNegativa = callApi($kernel, 'POST', '/api/v1/inspecciones/' . $inspId . '/detalles', medicion($p2, '10', null, null, '-1', 'NORMAL'), $tecnicoToken);
    record('19', 'Presión negativa', $presionNegativa['status'] === 422, codeOf($presionNegativa));
    $condicion = callApi($kernel, 'POST', '/api/v1/inspecciones/' . $inspId . '/detalles', medicion($p2, '10', null, null, null, 'MALO'), $tecnicoToken);
    record('20', 'Condición inválida', $condicion['status'] === 422, codeOf($condicion));
    $mismaPosicion = callApi($kernel, 'POST', '/api/v1/inspecciones/' . $inspId . '/detalles', medicion($p1, '9', null, null, null, 'ATENCION'), $tecnicoToken);
    $pdo->prepare('UPDATE inspeccion_detalles SET posicion_id = :posicion WHERE id = :id')->execute(['posicion' => $p4, 'id' => $detalleId]);
    $mismoNeumatico = callApi($kernel, 'POST', '/api/v1/inspecciones/' . $inspId . '/detalles', medicion($p1, '9', null, null, null, 'NORMAL'), $tecnicoToken);
    $pdo->prepare('UPDATE inspeccion_detalles SET posicion_id = :posicion WHERE id = :id')->execute(['posicion' => $p1, 'id' => $detalleId]);
    record('21', 'Mismo neumático duplicado', $mismoNeumatico['status'] === 409, codeOf($mismoNeumatico));
    record('22', 'Misma posición duplicada', $mismaPosicion['status'] === 409, codeOf($mismaPosicion));

    $dano = callApi($kernel, 'POST', '/api/v1/inspecciones/' . $inspId . '/detalles/' . $detalleId . '/danos', ['tipo_dano_id' => $corte, 'severidad' => 'LEVE', 'observacion' => 'marca'], $tecnicoToken);
    record('23', 'Agregar daño', $dano['status'] === 201, codeOf($dano));
    $tipoMal = callApi($kernel, 'POST', '/api/v1/inspecciones/' . $inspId . '/detalles/' . $detalleId . '/danos', ['tipo_dano_id' => 999999999, 'severidad' => 'LEVE'], $tecnicoToken);
    record('24', 'Tipo inexistente', $tipoMal['status'] === 422, codeOf($tipoMal));
    $severidadMal = callApi($kernel, 'POST', '/api/v1/inspecciones/' . $inspId . '/detalles/' . $detalleId . '/danos', ['tipo_dano_id' => $grieta, 'severidad' => 'GRAVE'], $tecnicoToken);
    record('25', 'Severidad inválida', $severidadMal['status'] === 422, codeOf($severidadMal));
    $editado = callApi($kernel, 'PUT', '/api/v1/inspecciones/' . $inspId . '/detalles/' . $detalleId . '/danos/' . $corte, ['tipo_dano_id' => $corte, 'severidad' => 'MEDIA', 'observacion' => 'revisado'], $tecnicoToken);
    $severidad = null;
    foreach ($editado['body']['data']['detalles'][0]['danos'] ?? [] as $fila) {
        if ((int) $fila['tipo_dano_id'] === $corte) {
            $severidad = $fila['severidad'];
        }
    }
    record('26', 'Editar daño en BORRADOR', $editado['status'] === 200 && $severidad === 'MEDIA', (string) $severidad);
    $quitado = callApi($kernel, 'DELETE', '/api/v1/inspecciones/' . $inspId . '/detalles/' . $detalleId . '/danos/' . $corte, null, $tecnicoToken);
    record('27', 'Quitar daño en BORRADOR', $quitado['status'] === 204, codeOf($quitado));
    callApi($kernel, 'POST', '/api/v1/inspecciones/' . $inspId . '/detalles/' . $detalleId . '/danos', ['tipo_dano_id' => $corte, 'severidad' => 'LEVE'], $tecnicoToken);

    $jpeg = callFile($kernel, '/api/v1/inspecciones/' . $inspId . '/archivos', foto('jpeg'), 'foto.exe', $tecnicoToken);
    $png = callFile($kernel, '/api/v1/inspecciones/' . $inspId . '/detalles/' . $detalleId . '/archivos', foto('png'), '../foto.png', $tecnicoToken);
    $webp = callFile($kernel, '/api/v1/inspecciones/' . $inspId . '/archivos', foto('webp'), 'foto.webp', $tecnicoToken);
    $invalido = callFile($kernel, '/api/v1/inspecciones/' . $inspId . '/archivos', '<?php echo 1;', 'foto.php', $tecnicoToken);
    record('29', 'Subir JPEG', $jpeg['status'] === 201 && ($jpeg['body']['data']['mime_type'] ?? '') === 'image/jpeg', codeOf($jpeg));
    record('30', 'Subir PNG', $png['status'] === 201 && ($png['body']['data']['mime_type'] ?? '') === 'image/png', codeOf($png));
    record('31', 'Subir WEBP', $webp['status'] === 201 && ($webp['body']['data']['mime_type'] ?? '') === 'image/webp', codeOf($webp));
    record('32', 'MIME inválido', $invalido['status'] === 422, codeOf($invalido));
    $maxBytes = \App\Config\Config::fromEnvFile(dirname(__DIR__) . '/.env')->int('ARCHIVO_MAX_BYTES', 5242880);
    $grande = callFile($kernel, '/api/v1/inspecciones/' . $inspId . '/archivos', str_repeat('A', $maxBytes + 1), 'grande.jpg', $tecnicoToken);
    $mensajeTamano = (string) ($grande['body']['err']['details']['archivo'][0] ?? '');
    record('MAX', 'Tamaño máximo configurable', $grande['status'] === 422 && str_contains($mensajeTamano, 'tamaño'), 'ARCHIVO_MAX_BYTES=' . $maxBytes . ' HTTP ' . $grande['status']);
    $jpegId = (int) ($jpeg['body']['data']['id'] ?? 0);
    $inspAjena = callApi($kernel, 'POST', '/api/v1/inspecciones', cabecera($unidadAjena, 1000, null), $adminToken);
    $inspAjenaId = (int) ($inspAjena['body']['data']['id'] ?? 0);
    $detalleAjeno = callApi($kernel, 'POST', '/api/v1/inspecciones/' . $inspAjenaId . '/detalles', medicion($p1, '8', null, null, null, 'NORMAL'), $adminToken);
    $archivoAjeno = callFile($kernel, '/api/v1/inspecciones/' . $inspAjenaId . '/archivos', foto('jpeg'), 'ajena.jpg', $adminToken);
    $archivoAjenoId = (int) ($archivoAjeno['body']['data']['id'] ?? 0);
    $idor = descargar($kernel, $archivoAjenoId, $gestorToken);
    $propio = descargar($kernel, $jpegId, $tecnicoToken);
    $tecnicoAjeno = descargar($kernel, $archivoAjenoId, $tecnicoToken);
    record('33', 'Archivo de otro cliente', $idor['status'] === 403 && ($idor['body']['err']['code'] ?? '') === 'CLIENT_SCOPE_FORBIDDEN', codeOf($idor));
    record('34', 'Download autorizado', $propio['status'] === 200 && str_starts_with((string) $propio['body']['data'], "\xFF\xD8\xFF"), 'HTTP ' . $propio['status']);
    record('35', 'Download no autorizado', $tecnicoAjeno['status'] === 403, codeOf($tecnicoAjeno));
    $ruta = scalar($pdo, 'SELECT ruta FROM archivos WHERE id = :id', $jpegId);
    $bajaArchivo = callApi($kernel, 'DELETE', '/api/v1/archivos/' . $jpegId, null, $tecnicoToken);
    $eliminado = (int) scalar($pdo, 'SELECT eliminado FROM archivos WHERE id = :id', $jpegId);
    $fisico = is_file(dirname(__DIR__) . '/storage/' . str_replace('/', DIRECTORY_SEPARATOR, (string) $ruta));
    $descargaBaja = descargar($kernel, $jpegId, $tecnicoToken);
    record('36', 'Soft-delete conserva metadata y archivo físico', $bajaArchivo['status'] === 204 && $eliminado === 1 && $fisico && $descargaBaja['status'] === 404, 'eliminado ' . $eliminado);

    $final = callApi($kernel, 'POST', '/api/v1/inspecciones/' . $inspId . '/finalizar', [], $tecnicoToken);
    $cerrada = scalar($pdo, 'SELECT finalizada_en FROM inspecciones WHERE id = :id', $inspId);
    $estadoFinal = (string) scalar($pdo, 'SELECT estado FROM inspecciones WHERE id = :id', $inspId);
    $presion = (int) $pdo->query("SELECT COUNT(*) FROM alertas a INNER JOIN tipos_alerta t ON t.id = a.tipo_alerta_id WHERE a.inspeccion_id = $inspId AND t.codigo IN ('PRESION_BAJA','PRESION_ALTA','DESGASTE_IRREGULAR')")->fetchColumn();
    record('38', 'Finalizar inspección válida', $final['status'] === 200 && $presion === 0, codeOf($final));
    record('39', 'Estado pasa FINALIZADA', $estadoFinal === 'FINALIZADA' && ($final['body']['data']['estado'] ?? '') === 'FINALIZADA', $estadoFinal);
    record('40', 'finalizada_en establecido', $cerrada !== null && $cerrada !== '', (string) $cerrada);
    $edicion = callApi($kernel, 'PUT', '/api/v1/inspecciones/' . $inspId, cabeceraEdicion(800000, '50'), $tecnicoToken);
    $medicionFinal = callApi($kernel, 'PUT', '/api/v1/inspecciones/' . $inspId . '/detalles/' . $detalleId, medicion($p1, '1', null, null, null, 'CRITICO'), $tecnicoToken);
    record('41', 'Editar después', $edicion['status'] === 409 && ($edicion['body']['err']['code'] ?? '') === 'INSPECTION_ALREADY_FINALIZED' && $medicionFinal['status'] === 409, codeOf($edicion));
    $segunda = callApi($kernel, 'POST', '/api/v1/inspecciones/' . $inspId . '/finalizar', [], $tecnicoToken);
    record('42', 'Finalizar dos veces', $segunda['status'] === 409 && ($segunda['body']['err']['code'] ?? '') === 'INSPECTION_ALREADY_FINALIZED', codeOf($segunda));
    $sinDetalles = callApi($kernel, 'POST', '/api/v1/inspecciones/' . $borradorId . '/finalizar', [], $tecnicoToken);
    record('43', 'Finalizar sin detalles', $sinDetalles['status'] === 422 && (string) scalar($pdo, 'SELECT estado FROM inspecciones WHERE id = :id', $borradorId) === 'BORRADOR', codeOf($sinDetalles));
    record('44', 'Auditoría generada', audit($pdo, 'INSPECCION_FINALIZE', $inspId) === 1 && audit($pdo, 'INSPECCION_CREATE', $inspId) === 1 && audit($pdo, 'DETALLE_CREATE', $detalleId) === 1, 'finalize ' . audit($pdo, 'INSPECCION_FINALIZE', $inspId));
    $danoFinal = callApi($kernel, 'PUT', '/api/v1/inspecciones/' . $inspId . '/detalles/' . $detalleId . '/danos/' . $corte, ['tipo_dano_id' => $corte, 'severidad' => 'ALTA'], $tecnicoToken);
    $archivoFinal = callFile($kernel, '/api/v1/inspecciones/' . $inspId . '/archivos', foto('jpeg'), 'tarde.jpg', $tecnicoToken);
    record('28', 'Modificar daño en FINALIZADA', $danoFinal['status'] === 409 && ($danoFinal['body']['err']['code'] ?? '') === 'INSPECTION_ALREADY_FINALIZED', codeOf($danoFinal));
    record('37', 'Subir archivo en FINALIZADA', $archivoFinal['status'] === 409 && ($archivoFinal['body']['err']['code'] ?? '') === 'INSPECTION_ALREADY_FINALIZED', codeOf($archivoFinal));

    $tp1 = neumaticoId($kernel, $adminToken, $propioId, $modeloId, $medidaId, 'P1-' . $sufijo);
    $tp2 = neumaticoId($kernel, $adminToken, $propioId, $modeloId, $medidaId, 'P2-' . $sufijo);
    $tp3 = neumaticoId($kernel, $adminToken, $propioId, $modeloId, $medidaId, 'P3-' . $sufijo);
    callApi($kernel, 'POST', '/api/v1/montajes', montaje($tp1, $profundidad, $p1, $fecha, 1000), $adminToken);
    callApi($kernel, 'POST', '/api/v1/montajes', montaje($tp2, $profundidad, $p2, $fecha, 1000), $adminToken);
    callApi($kernel, 'POST', '/api/v1/montajes', montaje($tp3, $profundidad, $p3, $fecha, 1000), $adminToken);
    $inspProf = (int) callApi($kernel, 'POST', '/api/v1/inspecciones', cabecera($profundidad, 1000, null), $tecnicoToken)['body']['data']['id'];
    $dAlta = (int) callApi($kernel, 'POST', '/api/v1/inspecciones/' . $inspProf . '/detalles', medicion($p1, '10', '14', '12', '1', 'CRITICO'), $tecnicoToken)['body']['data']['id'];
    $dIgual = (int) callApi($kernel, 'POST', '/api/v1/inspecciones/' . $inspProf . '/detalles', medicion($p2, null, '3.00', null, null, 'NORMAL'), $tecnicoToken)['body']['data']['id'];
    $dBaja = (int) callApi($kernel, 'POST', '/api/v1/inspecciones/' . $inspProf . '/detalles', medicion($p3, null, null, '2.50', null, 'NORMAL'), $tecnicoToken)['body']['data']['id'];
    $finProf = callApi($kernel, 'POST', '/api/v1/inspecciones/' . $inspProf . '/finalizar', [], $tecnicoToken);
    $alertaAlta = alertasDe($pdo, $dAlta, 'PROFUNDIDAD_CRITICA');
    $alertaIgual = alertasDe($pdo, $dIgual, 'PROFUNDIDAD_CRITICA');
    $alertaBaja = alertasDe($pdo, $dBaja, 'PROFUNDIDAD_CRITICA');
    $otras = (int) $pdo->query("SELECT COUNT(*) FROM alertas a INNER JOIN tipos_alerta t ON t.id = a.tipo_alerta_id WHERE a.inspeccion_detalle_id = $dAlta AND t.codigo <> 'PROFUNDIDAD_CRITICA'")->fetchColumn();
    record('45', 'Profundidad por encima del mínimo', $finProf['status'] === 200 && $alertaAlta === [] && $otras === 0, 'alertas ' . count($alertaAlta));
    record('46', 'Igual al mínimo', count($alertaIgual) === 1 && ($alertaIgual[0]['nivel'] ?? '') === 'CRITICA', (string) ($alertaIgual[0]['nivel'] ?? ''));
    record('47', 'Debajo del mínimo', count($alertaBaja) === 1 && ($alertaBaja[0]['nivel'] ?? '') === 'CRITICA', (string) ($alertaBaja[0]['nivel'] ?? ''));
    $enlace = $alertaIgual[0] ?? [];
    record('48', 'Alerta enlaza cliente, unidad, neumático, inspección y detalle', (int) ($enlace['cliente_id'] ?? 0) === $propioId && (int) ($enlace['unidad_id'] ?? 0) === $profundidad && (int) ($enlace['neumatico_id'] ?? 0) === $tp2 && (int) ($enlace['inspeccion_id'] ?? 0) === $inspProf && (int) ($enlace['inspeccion_detalle_id'] ?? 0) === $dIgual, (string) ($enlace['id'] ?? ''));
    record('49', 'Estado inicial ABIERTA', ($enlace['estado'] ?? '') === 'ABIERTA' && ($alertaBaja[0]['estado'] ?? '') === 'ABIERTA', (string) ($enlace['estado'] ?? ''));
    record('50', 'generada_automaticamente = 1', (int) ($enlace['generada_automaticamente'] ?? 0) === 1, (string) ($enlace['generada_automaticamente'] ?? ''));
    $reintento = callApi($kernel, 'POST', '/api/v1/inspecciones/' . $inspProf . '/finalizar', [], $adminToken);
    record('51', 'Reintento finalizar no duplica', $reintento['status'] === 409 && count(alertasDe($pdo, $dIgual, 'PROFUNDIDAD_CRITICA')) === 1 && count(alertasDe($pdo, $dBaja, 'PROFUNDIDAD_CRITICA')) === 1, codeOf($reintento));

    $td = [];
    foreach (['D1', 'D2', 'D3', 'D4'] as $indice => $codigo) {
        $id = neumaticoId($kernel, $adminToken, $propioId, $modeloId, $medidaId, $codigo . '-' . $sufijo);
        callApi($kernel, 'POST', '/api/v1/montajes', montaje($id, $danosUnidad, $posiciones[$indice], $fecha, 1000), $adminToken);
        $td[] = $id;
    }
    $inspDano = (int) callApi($kernel, 'POST', '/api/v1/inspecciones', cabecera($danosUnidad, 1000, null), $gestorToken)['body']['data']['id'];
    $dd = [];
    foreach ($posiciones as $posicion) {
        $dd[] = (int) callApi($kernel, 'POST', '/api/v1/inspecciones/' . $inspDano . '/detalles', medicion($posicion, '10', '10', '10', '80', 'NORMAL'), $gestorToken)['body']['data']['id'];
    }
    callApi($kernel, 'POST', '/api/v1/inspecciones/' . $inspDano . '/detalles/' . $dd[0] . '/danos', ['tipo_dano_id' => $corte, 'severidad' => 'LEVE'], $gestorToken);
    callApi($kernel, 'POST', '/api/v1/inspecciones/' . $inspDano . '/detalles/' . $dd[1] . '/danos', ['tipo_dano_id' => $grieta, 'severidad' => 'MEDIA'], $gestorToken);
    callApi($kernel, 'POST', '/api/v1/inspecciones/' . $inspDano . '/detalles/' . $dd[2] . '/danos', ['tipo_dano_id' => $punzon, 'severidad' => 'ALTA'], $gestorToken);
    callApi($kernel, 'POST', '/api/v1/inspecciones/' . $inspDano . '/detalles/' . $dd[3] . '/danos', ['tipo_dano_id' => $corte, 'severidad' => 'ALTA'], $gestorToken);
    callApi($kernel, 'POST', '/api/v1/inspecciones/' . $inspDano . '/detalles/' . $dd[3] . '/danos', ['tipo_dano_id' => $abultamiento, 'severidad' => 'CRITICA'], $gestorToken);
    $finDano = callApi($kernel, 'POST', '/api/v1/inspecciones/' . $inspDano . '/finalizar', [], $gestorToken);
    $leve = alertasDe($pdo, $dd[0], 'DANO_DETECTADO');
    $media = alertasDe($pdo, $dd[1], 'DANO_DETECTADO');
    $altaDano = alertasDe($pdo, $dd[2], 'DANO_DETECTADO');
    $criticaDano = alertasDe($pdo, $dd[3], 'DANO_DETECTADO');
    record('52', 'Daño LEVE sin alerta', $finDano['status'] === 200 && $leve === [], 'alertas ' . count($leve));
    record('53', 'Daño MEDIA sin alerta', $media === [], 'alertas ' . count($media));
    record('54', 'Daño ALTA nivel ATENCION', count($altaDano) === 1 && ($altaDano[0]['nivel'] ?? '') === 'ATENCION', (string) ($altaDano[0]['nivel'] ?? ''));
    record('55', 'Daño CRITICA nivel CRITICA', count($criticaDano) === 1 && ($criticaDano[0]['nivel'] ?? '') === 'CRITICA', (string) ($criticaDano[0]['nivel'] ?? ''));
    record('56', 'Varios daños relevantes generan una alerta resumida', count($criticaDano) === 1 && str_contains((string) ($criticaDano[0]['descripcion'] ?? ''), 'Corte') && str_contains((string) ($criticaDano[0]['descripcion'] ?? ''), 'Abultamiento'), (string) ($criticaDano[0]['descripcion'] ?? ''));

    $listaAdmin = callApi($kernel, 'GET', '/api/v1/inspecciones?limit=100&cliente_id=' . $ajenoId, null, $adminToken);
    $verAjena = callApi($kernel, 'GET', '/api/v1/inspecciones/' . $inspAjenaId, null, $adminToken);
    record('57', 'ADMIN_GENERAL ve todo', $listaAdmin['status'] === 200 && ($listaAdmin['body']['total'] ?? 0) >= 1 && $verAjena['status'] === 200, 'total ' . ($listaAdmin['body']['total'] ?? 0));
    $listaGestor = callApi($kernel, 'GET', '/api/v1/inspecciones?limit=100', null, $gestorToken);
    $idsGestor = array_map(static fn (array $row): int => (int) $row['id'], $listaGestor['body']['data'] ?? []);
    record('58', 'GESTOR solo scope', $listaGestor['status'] === 200 && !in_array($inspAjenaId, $idsGestor, true) && in_array($inspId, $idsGestor, true), 'filas ' . count($idsGestor));
    $propia = callApi($kernel, 'POST', '/api/v1/inspecciones', cabecera($profundidad, null, null, $gestor), $tecnicoToken);
    $suya = callApi($kernel, 'POST', '/api/v1/inspecciones', cabecera($profundidad, null, null), $tecnicoToken);
    record('59', 'TECNICO crea la propia y no suplanta', $propia['status'] === 403 && $suya['status'] === 201 && (int) ($suya['body']['data']['tecnico']['id'] ?? 0) === $tecnico, codeOf($propia));
    $ajenaGestor = callApi($kernel, 'POST', '/api/v1/inspecciones', cabecera($unidad, null, null), $gestorToken);
    $ajenaGestorId = (int) ($ajenaGestor['body']['data']['id'] ?? 0);
    $tecnicoEdita = callApi($kernel, 'PUT', '/api/v1/inspecciones/' . $ajenaGestorId, cabeceraEdicion(null, null), $tecnicoToken);
    $tecnicoVe = callApi($kernel, 'GET', '/api/v1/inspecciones/' . $ajenaGestorId, null, $tecnicoToken);
    record('60', 'TECNICO no modifica borrador de otro', $tecnicoEdita['status'] === 403 && $tecnicoVe['status'] === 404, codeOf($tecnicoEdita));
    $clienteBorrador = callApi($kernel, 'GET', '/api/v1/inspecciones/' . $borradorId, null, $clienteToken);
    $clienteFinal = callApi($kernel, 'GET', '/api/v1/inspecciones/' . $inspId, null, $clienteToken);
    record('61', 'ADMIN_CLIENTE solo finalizadas', $clienteBorrador['status'] === 404 && $clienteFinal['status'] === 200 && ($clienteFinal['body']['data']['estado'] ?? '') === 'FINALIZADA', codeOf($clienteBorrador));
    $consultaBorrador = callApi($kernel, 'GET', '/api/v1/inspecciones/' . $borradorId, null, $consultaToken);
    $consultaFinal = callApi($kernel, 'GET', '/api/v1/inspecciones/' . $inspId, null, $consultaToken);
    record('62', 'CONSULTA_EJECUTIVA solo finalizadas', $consultaBorrador['status'] === 404 && $consultaFinal['status'] === 200, codeOf($consultaBorrador));
    $vendedorLee = callApi($kernel, 'GET', '/api/v1/inspecciones/' . $borradorId, null, $vendedorToken);
    $vendedorCrea = callApi($kernel, 'POST', '/api/v1/inspecciones', cabecera($unidad, null, null), $vendedorToken);
    record('63', 'VENDEDOR lectura según scope', $vendedorLee['status'] === 200 && $vendedorCrea['status'] === 403, codeOf($vendedorCrea));
    $filtroAjeno = callApi($kernel, 'GET', '/api/v1/inspecciones?cliente_id=' . $ajenoId, null, $gestorToken);
    record('64', 'cliente_id ajeno', $filtroAjeno['status'] === 403 && ($filtroAjeno['body']['err']['code'] ?? '') === 'CLIENT_SCOPE_FORBIDDEN', codeOf($filtroAjeno));
    $idorInsp = callApi($kernel, 'GET', '/api/v1/inspecciones/' . $inspAjenaId, null, $gestorToken);
    record('65', 'IDOR inspección', $idorInsp['status'] === 403 && ($idorInsp['body']['err']['code'] ?? '') === 'CLIENT_SCOPE_FORBIDDEN', codeOf($idorInsp));
    unset($detalleAjeno, $td);
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
record('CLEAN', 'La transacción no dejó datos', $before === $after, $before === $after ? 'sin cambios' : 'hay diferencias');
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
    $signal = 'inspeccion-hold-' . bin2hex(random_bytes(6));
    $signalPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $signal;
    try {
        $password = bin2hex(random_bytes(8));
        $admin = user($pdo, 'Admin', 'Concurrente', 'ADMIN_GENERAL', $password);
        $creados['usuario'] = $admin;
        $adminToken = token($kernel, emailOf($pdo, $admin), $password);
        $sufijo = strtoupper(bin2hex(random_bytes(3)));
        $clienteId = (int) cliente($kernel, $adminToken, 'B5C ' . $sufijo)['body']['data']['id'];
        $creados['cliente'] = $clienteId;
        $creados['marca'] = (int) callApi($kernel, 'POST', '/api/v1/marcas-neumatico', ['nombre' => 'Marca C ' . $sufijo], $adminToken)['body']['data']['id'];
        $creados['modelo'] = (int) callApi($kernel, 'POST', '/api/v1/modelos-neumatico', ['marca_id' => $creados['marca'], 'nombre' => 'Modelo C ' . $sufijo], $adminToken)['body']['data']['id'];
        $creados['medida'] = (int) callApi($kernel, 'POST', '/api/v1/medidas-neumatico', ['descripcion' => '11R22.5 C ' . $sufijo], $adminToken)['body']['data']['id'];
        $tipo = (int) $pdo->query("SELECT id FROM tipos_unidad WHERE codigo = 'CAMION'")->fetchColumn();
        $config = callApi($kernel, 'POST', '/api/v1/configuraciones-unidad', configuracion('Cfg C ' . $sufijo, 'C'), $adminToken);
        $creados['config'] = (int) $config['body']['data']['id'];
        $posicion = (int) $config['body']['data']['ejes'][0]['posiciones'][0]['id'];
        $creados['unidad'] = (int) callApi($kernel, 'POST', '/api/v1/unidades', unidadBody($clienteId, $tipo, 'UC-' . $sufijo, $creados['config']), $adminToken)['body']['data']['id'];
        $creados['neumatico'] = neumaticoId($kernel, $adminToken, $clienteId, $creados['modelo'], $creados['medida'], 'TC-' . $sufijo);
        callApi($kernel, 'POST', '/api/v1/montajes', montaje($creados['neumatico'], $creados['unidad'], $posicion, '2026-10-05 09:00:00', 1000), $adminToken);
        $alta = callApi($kernel, 'POST', '/api/v1/inspecciones', cabecera($creados['unidad'], 1100, null), $adminToken);
        $creados['inspeccion'] = (int) ($alta['body']['data']['id'] ?? 0);
        $detalle = callApi($kernel, 'POST', '/api/v1/inspecciones/' . $creados['inspeccion'] . '/detalles', medicion($posicion, '2.00', '2.50', null, '90', 'CRITICO'), $adminToken);
        $creados['detalle'] = (int) ($detalle['body']['data']['id'] ?? 0);
        if (($alta['status'] ?? 0) !== 201 || ($detalle['status'] ?? 0) !== 201 || $creados['inspeccion'] < 1 || $creados['detalle'] < 1) {
            throw new RuntimeException('No se pudo preparar la inspección concurrente HTTP ' . ($alta['status'] ?? 0) . '/' . ($detalle['status'] ?? 0));
        }

        $primera = procesoFinalizar($creados['inspeccion'], $adminToken, 4000, $signal);
        $avisada = false;
        $limite = microtime(true) + 8;
        while (microtime(true) < $limite) {
            if (is_file($signalPath)) {
                $avisada = true;
                break;
            }
            usleep(40000);
        }
        $segunda = procesoFinalizar($creados['inspeccion'], $adminToken, 0, '');
        $ganadora = cerrarProceso($primera);
        $perdedora = cerrarProceso($segunda);
        $estados = [$ganadora['status'], $perdedora['status']];
        sort($estados);
        $codigos = array_values(array_filter([$ganadora['code'], $perdedora['code']]));
        $auditorias = (int) scalar($pdo, "SELECT COUNT(*) FROM auditoria WHERE accion = 'INSPECCION_FINALIZE' AND entidad = 'inspecciones' AND entidad_id = :id", $creados['inspeccion']);
        $alertas = (int) scalar($pdo, 'SELECT COUNT(*) FROM alertas WHERE inspeccion_id = :id', $creados['inspeccion']);
        $fila = $pdo->prepare('SELECT estado, finalizada_en FROM inspecciones WHERE id = :id');
        $fila->bindValue('id', $creados['inspeccion'], PDO::PARAM_INT);
        $fila->execute();
        $cerrada = $fila->fetch() ?: ['estado' => '', 'finalizada_en' => null];
        $ok = $avisada
            && $estados === [200, 409]
            && $codigos === ['INSPECTION_ALREADY_FINALIZED']
            && $auditorias === 1
            && $alertas === 1
            && $cerrada['estado'] === 'FINALIZADA'
            && $cerrada['finalizada_en'] !== null
            && $ganadora['status'] !== 500
            && $perdedora['status'] !== 500;
        record('CONCURRENCIA', 'Dos conexiones finalizan una sola vez', $ok, json_encode([
            'lock' => $avisada,
            'http' => $estados,
            'codigo' => $codigos,
            'auditorias' => $auditorias,
            'alertas' => $alertas,
            'estado' => $cerrada['estado'],
            'finalizada_en' => $cerrada['finalizada_en'],
            'stderr' => trim($ganadora['stderr'] . ' ' . $perdedora['stderr']),
        ], JSON_UNESCAPED_UNICODE));
    } catch (Throwable $error) {
        record('CONCURRENCIA', 'Dos conexiones finalizan una sola vez', false, $error->getMessage());
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
    $inspeccion = (int) ($creados['inspeccion'] ?? 0);
    $detalle = (int) ($creados['detalle'] ?? 0);
    $neumatico = (int) ($creados['neumatico'] ?? 0);
    $unidad = (int) ($creados['unidad'] ?? 0);
    $config = (int) ($creados['config'] ?? 0);
    $cliente = (int) ($creados['cliente'] ?? 0);
    $usuario = (int) ($creados['usuario'] ?? 0);
    if ($inspeccion > 0) {
        $pdo->prepare('DELETE FROM alertas WHERE inspeccion_id = :id')->execute(['id' => $inspeccion]);
        $pdo->prepare("DELETE FROM archivos WHERE entidad_tipo = 'INSPECCION' AND entidad_id = :id")->execute(['id' => $inspeccion]);
    }
    if ($detalle > 0) {
        $pdo->prepare('DELETE FROM inspeccion_detalle_danos WHERE inspeccion_detalle_id = :id')->execute(['id' => $detalle]);
        $pdo->prepare("DELETE FROM archivos WHERE entidad_tipo = 'INSPECCION_DETALLE' AND entidad_id = :id")->execute(['id' => $detalle]);
        $pdo->prepare('DELETE FROM inspeccion_detalles WHERE id = :id')->execute(['id' => $detalle]);
    }
    if ($inspeccion > 0) {
        $pdo->prepare('DELETE FROM inspecciones WHERE id = :id')->execute(['id' => $inspeccion]);
    }
    if ($neumatico > 0) {
        $pdo->prepare('DELETE FROM neumatico_estado_historial WHERE neumatico_id = :id')->execute(['id' => $neumatico]);
        $pdo->prepare('DELETE FROM movimientos_neumatico WHERE neumatico_id = :id')->execute(['id' => $neumatico]);
        $pdo->prepare('DELETE FROM montajes_neumatico WHERE neumatico_id = :id')->execute(['id' => $neumatico]);
        $pdo->prepare('DELETE FROM neumatico_vidas WHERE neumatico_id = :id')->execute(['id' => $neumatico]);
        $pdo->prepare('DELETE FROM neumaticos WHERE id = :id')->execute(['id' => $neumatico]);
    }
    if ($unidad > 0) {
        $pdo->prepare('DELETE FROM unidades WHERE id = :id')->execute(['id' => $unidad]);
    }
    if ($config > 0) {
        $pdo->prepare('DELETE FROM configuracion_posiciones WHERE configuracion_id = :id')->execute(['id' => $config]);
        $pdo->prepare('DELETE FROM configuracion_ejes WHERE configuracion_id = :id')->execute(['id' => $config]);
        $pdo->prepare('DELETE FROM configuraciones_unidad WHERE id = :id')->execute(['id' => $config]);
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
function procesoFinalizar(int $inspeccionId, string $token, int $holdMs, string $signal): array
{
    $comando = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/bloque5_finalizar_worker.php')
        . ' ' . $inspeccionId . ' ' . escapeshellarg($token) . ' ' . $holdMs . ' ' . escapeshellarg($signal);
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
    $headers = ['user-agent' => 'bloque5-test', 'x-client-ip' => '203.0.113.21'];
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
        'user-agent' => 'bloque5-test',
        'x-client-ip' => '203.0.113.21',
        'authorization' => 'Bearer ' . $token,
    ], null, ['archivo' => ['contents' => $contenido, 'name' => $nombre]]);
    $response = $kernel->handle($request);

    return ['status' => $response->status(), 'body' => $response->toArray()];
}

/** @return array{status:int,body:array<string,mixed>} */
function descargar(Kernel $kernel, int $id, string $token): array
{
    $request = Request::fake('GET', '/api/v1/archivos/' . $id . '/download', [
        'user-agent' => 'bloque5-test',
        'x-client-ip' => '203.0.113.21',
        'authorization' => 'Bearer ' . $token,
    ]);
    $response = $kernel->handle($request);

    return ['status' => $response->status(), 'body' => $response->toArray()];
}

function foto(string $tipo): string
{
    $codigos = [
        'jpeg' => '/9j/4AAQSkZJRgABAQAAAQABAAD/2wCEAAkGBwgHBgkIBwgKCgkLDRYPDQwMDRsUFRAWIB0iIiAdHx8kKDQsJCYxJx8fLT0tMTU3Ojo6Iys/RD84QzQ5OjcBCgoKDQwNGg8PGjclHyU3Nzc3Nzc3Nzc3Nzc3Nzc3Nzc3Nzc3Nzc3Nzc3Nzc3Nzc3Nzc3Nzc3Nzc3Nzc3Nzc3N//AABEIAAcACgMBIgACEQEDEQH/xAAbAAACAwEBAQAAAAAAAAAAAAACAwABBAUGB//EABoQAQEAAgMAAAAAAAAAAAAAAAABAhEDBBT/xAAYAQEBAQEBAAAAAAAAAAAAAAAAAQIDBP/EABQRAQAAAAAAAAAAAAAAAAAAAAD/2gAMAwEAAhEDEQA/AKo6l2m2m2l9k9r9s0f8Q0Y6U6n//Z',
        'png' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
        'webp' => 'UklGRiQAAABXRUJQVlA4IBgAAAAwAQCdASoBAAEAAwA0JaQAA3AA/vuUAAA=',
    ];
    $bytes = base64_decode($codigos[$tipo] ?? '', true);
    if (!is_string($bytes) || $bytes === '') {
        throw new RuntimeException('No se pudo preparar la fotografía ' . $tipo . '.');
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
        'email' => 'b5.' . bin2hex(random_bytes(5)) . '@test.local',
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

/** @return array<string, mixed> */
function configuracion(string $nombre, string $prefijo = 'E'): array
{
    return [
        'nombre' => $nombre,
        'descripcion' => 'Plantilla de inspección',
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
function cabecera(int $unidad, int|null $km, ?string $horometro, ?int $tecnico = null, ?int $clienteIgnorado = null): array
{
    return [
        'unidad_id' => $unidad,
        'cliente_id' => $clienteIgnorado,
        'fecha_inspeccion' => '2026-10-05 10:00:00',
        'kilometraje' => $km,
        'horometro' => $horometro,
        'observacion_general' => 'Inspección de prueba',
        'tecnico_id' => $tecnico,
    ];
}

/** @return array<string, mixed> */
function cabeceraEdicion(int|null $km, ?string $horometro): array
{
    return [
        'fecha_inspeccion' => '2026-10-05 11:00:00',
        'kilometraje' => $km,
        'horometro' => $horometro,
        'observacion_general' => 'Corrección',
    ];
}

/** @return array<string, mixed> */
function medicion(int $posicion, ?string $interior, ?string $centro, ?string $exterior, ?string $presion, string $condicion): array
{
    return [
        'posicion_id' => $posicion,
        'profundidad_interior_mm' => $interior,
        'profundidad_centro_mm' => $centro,
        'profundidad_exterior_mm' => $exterior,
        'presion_psi' => $presion,
        'condicion' => $condicion,
        'observacion' => null,
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
    $statement->execute(['accion' => $accion, 'id' => $entidadId, 'ip' => '203.0.113.21']);

    return (int) $statement->fetchColumn();
}

/** @return list<array<string, mixed>> */
function alertasDe(PDO $pdo, int $detalleId, string $tipo): array
{
    $statement = $pdo->prepare(
        'SELECT a.id, a.cliente_id, a.unidad_id, a.neumatico_id, a.inspeccion_id, a.inspeccion_detalle_id,
                a.nivel, a.generada_automaticamente, a.descripcion, t.codigo, e.codigo AS estado
         FROM alertas a
         INNER JOIN tipos_alerta t ON t.id = a.tipo_alerta_id
         INNER JOIN estados_alerta e ON e.id = a.estado_id
         WHERE a.inspeccion_detalle_id = :detalle AND t.codigo = :tipo'
    );
    $statement->bindValue('detalle', $detalleId, PDO::PARAM_INT);
    $statement->bindValue('tipo', $tipo);
    $statement->execute();

    return $statement->fetchAll();
}

/** @return array<string, int> */
function counts(PDO $pdo): array
{
    $tables = ['usuarios', 'clientes', 'auditoria', 'usuario_clientes', 'marcas_neumatico', 'modelos_neumatico', 'medidas_neumatico', 'neumaticos', 'montajes_neumatico', 'movimientos_neumatico', 'inspecciones', 'inspeccion_detalles', 'archivos', 'alertas', 'unidades'];
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
        } else {
            unlink($ruta);
        }
    }
    rmdir($directorio);
}
