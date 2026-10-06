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
$anioMax = (int) $pdo->query('SELECT YEAR(CURRENT_DATE)')->fetchColumn() + 1;
$pdo->beginTransaction();
$failed = false;

try {
    $password = bin2hex(random_bytes(8));
    $admin = user($pdo, 'Admin', 'B2', 'ADMIN_GENERAL', $password);
    $gestor = user($pdo, 'Gestor', 'B2', 'GESTOR_NEUMATICOS', $password);
    $tecnico = user($pdo, 'Tecnico', 'B2', 'TECNICO_INSPECCION', $password);
    $vendedor = user($pdo, 'Vendedor', 'B2', 'VENDEDOR', $password);
    $adminCliente = user($pdo, 'Admin', 'Cliente', 'ADMIN_CLIENTE', $password);
    $consulta = user($pdo, 'Consulta', 'B2', 'CONSULTA_EJECUTIVA', $password);
    $adminToken = token($kernel, emailOf($pdo, $admin), $password);
    $gestorToken = token($kernel, emailOf($pdo, $gestor), $password);
    $tecnicoToken = token($kernel, emailOf($pdo, $tecnico), $password);
    $vendedorToken = token($kernel, emailOf($pdo, $vendedor), $password);
    $adminClienteToken = token($kernel, emailOf($pdo, $adminCliente), $password);
    $consultaToken = token($kernel, emailOf($pdo, $consulta), $password);

    $marca = 'B2' . bin2hex(random_bytes(3));
    $propio = cliente($kernel, $adminToken, $marca . ' Propio', 'ACTIVO');
    $ajeno = cliente($kernel, $adminToken, $marca . ' Ajeno', 'ACTIVO');
    $propioId = (int) $propio['body']['data']['id'];
    $ajenoId = (int) $ajeno['body']['data']['id'];
    foreach ([$gestor, $tecnico, $vendedor, $adminCliente, $consulta] as $usuario) {
        scope($pdo, $usuario, $propioId, $hoy);
    }

    $tipos = callApi($kernel, 'GET', '/api/v1/tipos-unidad?limit=20', null, $gestorToken);
    record('1', 'Listar tipos', $tipos['status'] === 200 && ($tipos['body']['total'] ?? 0) >= 9, 'total ' . ($tipos['body']['total'] ?? 0));

    $codigoTipo = 'T' . strtoupper(bin2hex(random_bytes(3)));
    $tipo = callApi($kernel, 'POST', '/api/v1/tipos-unidad', ['codigo' => $codigoTipo, 'nombre' => 'Tipo prueba', 'descripcion' => 'Alta'], $adminToken);
    $tipoId = (int) ($tipo['body']['data']['id'] ?? 0);
    record('2', 'Crear tipo', $tipo['status'] === 201 && ($tipo['body']['data']['codigo'] ?? '') === $codigoTipo, codeOf($tipo));
    $tipoDup = callApi($kernel, 'POST', '/api/v1/tipos-unidad', ['codigo' => $codigoTipo, 'nombre' => 'Otro'], $adminToken);
    record('3', 'Código de tipo duplicado', $tipoDup['status'] === 409 && ($tipoDup['body']['err']['code'] ?? '') === 'CONFLICT', codeOf($tipoDup));
    $tipoEdit = callApi($kernel, 'PUT', '/api/v1/tipos-unidad/' . $tipoId, ['codigo' => $codigoTipo, 'nombre' => 'Tipo editado', 'descripcion' => 'Nueva'], $adminToken);
    record('4', 'Editar nombre y descripción', $tipoEdit['status'] === 200 && ($tipoEdit['body']['data']['nombre'] ?? '') === 'Tipo editado', codeOf($tipoEdit));
    $tipoOff = callApi($kernel, 'PATCH', '/api/v1/tipos-unidad/' . $tipoId . '/estado', ['activo' => false], $adminToken);
    record('5', 'Desactivar tipo', $tipoOff['status'] === 200 && ($tipoOff['body']['data']['activo'] ?? true) === false, codeOf($tipoOff));

    $camion = idTipo($pdo, 'CAMION');
    $unidadTipoInactivo = unidadMinima($kernel, $adminToken, $propioId, $tipoId, 'U-TIPO-OFF');
    record('6', 'Tipo inactivo no seleccionable', $unidadTipoInactivo['status'] === 422, codeOf($unidadTipoInactivo));

    $config = callApi($kernel, 'POST', '/api/v1/configuraciones-unidad', configuracion('Camión prueba ' . $marca), $gestorToken);
    $configId = (int) ($config['body']['data']['id'] ?? 0);
    $tecnicoConfig = callApi($kernel, 'POST', '/api/v1/configuraciones-unidad', configuracion('No tecnica ' . $marca), $tecnicoToken);
    record('7', 'Crear configuración válida', $config['status'] === 201 && $tecnicoConfig['status'] === 403 && ($config['body']['data']['cantidad_ejes'] ?? 0) === 2, codeOf($config));

    $ejesDup = configuracion('Ejes dup ' . $marca);
    $ejesDup['ejes'][1]['numero_eje'] = 1;
    record('8', 'Ejes con número duplicado', callApi($kernel, 'POST', '/api/v1/configuraciones-unidad', $ejesDup, $adminToken)['status'] === 422, '422');
    $ordenDup = configuracion('Orden dup ' . $marca);
    $ordenDup['ejes'][1]['orden'] = 1;
    record('9', 'Órdenes de eje duplicados', callApi($kernel, 'POST', '/api/v1/configuraciones-unidad', $ordenDup, $adminToken)['status'] === 422, '422');
    $lado = configuracion('Lado ' . $marca);
    $lado['ejes'][0]['posiciones'][0]['lado'] = 'ARRIBA';
    record('10', 'Lado inválido', callApi($kernel, 'POST', '/api/v1/configuraciones-unidad', $lado, $adminToken)['status'] === 422, '422');
    $ubicacion = configuracion('Ubicacion ' . $marca);
    $ubicacion['ejes'][0]['posiciones'][0]['ubicacion'] = 'DOBLE';
    record('11', 'Ubicación inválida', callApi($kernel, 'POST', '/api/v1/configuraciones-unidad', $ubicacion, $adminToken)['status'] === 422, '422');
    $codigoPos = configuracion('Codigo pos ' . $marca);
    $codigoPos['ejes'][1]['posiciones'][0]['codigo'] = 'E1-I';
    record('12', 'Código de posición duplicado', callApi($kernel, 'POST', '/api/v1/configuraciones-unidad', $codigoPos, $adminToken)['status'] === 422, '422');
    record('13', 'Cantidad de ejes calculada', ($config['body']['data']['cantidad_ejes'] ?? 0) === 2, (string) ($config['body']['data']['cantidad_ejes'] ?? 0));
    record('14', 'Cantidad de posiciones calculada', ($config['body']['data']['cantidad_posiciones'] ?? 0) === 4, (string) ($config['body']['data']['cantidad_posiciones'] ?? 0));

    $editada = configuracion('Camión editado ' . $marca);
    $editada['ejes'][0]['posiciones'][0]['codigo'] = 'E1-IZQ';
    $configEdit = callApi($kernel, 'PUT', '/api/v1/configuraciones-unidad/' . $configId, $editada, $adminToken);
    record('15', 'Editar configuración sin uso', $configEdit['status'] === 200 && ($configEdit['body']['data']['ejes'][0]['posiciones'][0]['codigo'] ?? '') === 'E1-IZQ', codeOf($configEdit));

    $usadaUnidad = unidadMinima($kernel, $adminToken, $propioId, $camion, 'U-CONFIG', $configId);
    $usadaId = (int) ($usadaUnidad['body']['data']['id'] ?? 0);
    $bloqueo = callApi($kernel, 'PUT', '/api/v1/configuraciones-unidad/' . $configId, configuracion('No debe ' . $marca), $adminToken);
    record('16', 'Configuración usada bloquea estructura', $bloqueo['status'] === 409, codeOf($bloqueo));
    $nombreUsada = callApi($kernel, 'PUT', '/api/v1/configuraciones-unidad/' . $configId, ['nombre' => 'Nombre histórico ' . $marca, 'descripcion' => 'Solo texto'], $adminToken);
    record('17', 'Editar texto de configuración usada', $nombreUsada['status'] === 200 && ($nombreUsada['body']['data']['nombre'] ?? '') === 'Nombre histórico ' . $marca && ($nombreUsada['body']['data']['estructura_bloqueada'] ?? false) === true, codeOf($nombreUsada));

    $copia = callApi($kernel, 'POST', '/api/v1/configuraciones-unidad/' . $configId . '/duplicar', ['nombre' => 'Copia ' . $marca], $gestorToken);
    $copiaId = (int) ($copia['body']['data']['id'] ?? 0);
    $idsDistintos = $copiaId !== $configId && ($copia['body']['data']['ejes'][0]['id'] ?? 0) !== ($nombreUsada['body']['data']['ejes'][0]['id'] ?? 0);
    record('18', 'Duplicar configuración', $copia['status'] === 201 && ($copia['body']['data']['activo'] ?? false) === true, codeOf($copia));
    record('19', 'La copia tiene nuevos identificadores', $idsDistintos && ($copia['body']['data']['cantidad_posiciones'] ?? 0) === 4, 'origen ' . $configId . ' copia ' . $copiaId);

    $off = callApi($kernel, 'PATCH', '/api/v1/configuraciones-unidad/' . $copiaId . '/estado', ['activo' => false], $adminToken);
    record('20', 'Desactivar configuración', $off['status'] === 200 && ($off['body']['data']['activo'] ?? true) === false, codeOf($off));
    $asignarOff = unidadMinima($kernel, $adminToken, $propioId, $camion, 'U-CFG-OFF', $copiaId);
    record('21', 'Configuración inactiva no se asigna', $asignarOff['status'] === 422, codeOf($asignarOff));

    $minima = unidadMinima($kernel, $gestorToken, $propioId, $camion, 'U-MIN');
    $minimaId = (int) ($minima['body']['data']['id'] ?? 0);
    $minimaData = $minima['body']['data'] ?? [];
    record(
        '22',
        'Crear unidad mínima',
        $minima['status'] === 201
            && array_key_exists('sede', $minimaData) && $minimaData['sede'] === null
            && array_key_exists('flota', $minimaData) && $minimaData['flota'] === null
            && array_key_exists('configuracion', $minimaData) && $minimaData['configuracion'] === null,
        codeOf($minima)
    );

    $sede = callApi($kernel, 'POST', '/api/v1/clientes/' . $propioId . '/sedes', ['nombre' => 'Base', 'codigo' => 'BASE'], $gestorToken);
    $sedeId = (int) ($sede['body']['data']['id'] ?? 0);
    $sedeAjena = callApi($kernel, 'POST', '/api/v1/clientes/' . $ajenoId . '/sedes', ['nombre' => 'Ajena', 'codigo' => 'AJENA'], $adminToken);
    $sedeAjenaId = (int) ($sedeAjena['body']['data']['id'] ?? 0);
    $conSede = unidadMinima($kernel, $gestorToken, $propioId, $camion, 'U-SEDE', null, $sedeId);
    record('23', 'Crear unidad con sede válida', $conSede['status'] === 201 && ($conSede['body']['data']['sede']['id'] ?? 0) === $sedeId, codeOf($conSede));
    $flota = callApi($kernel, 'POST', '/api/v1/clientes/' . $propioId . '/flotas', ['nombre' => 'Ruta', 'codigo' => 'RUTA'], $gestorToken);
    $flotaId = (int) ($flota['body']['data']['id'] ?? 0);
    $flotaAjena = callApi($kernel, 'POST', '/api/v1/clientes/' . $ajenoId . '/flotas', ['nombre' => 'Ajena', 'codigo' => 'AJENA'], $adminToken);
    $flotaAjenaId = (int) ($flotaAjena['body']['data']['id'] ?? 0);
    $conFlota = unidadMinima($kernel, $gestorToken, $propioId, $camion, 'U-FLOTA', null, null, $flotaId);
    record('24', 'Crear unidad con flota válida', $conFlota['status'] === 201 && ($conFlota['body']['data']['flota']['id'] ?? 0) === $flotaId, codeOf($conFlota));
    record('25', 'Sede de otro cliente', unidadMinima($kernel, $adminToken, $propioId, $camion, 'U-SEDE-X', null, $sedeAjenaId)['status'] === 422, '422');
    record('26', 'Flota de otro cliente', unidadMinima($kernel, $adminToken, $propioId, $camion, 'U-FLOTA-X', null, null, $flotaAjenaId)['status'] === 422, '422');
    $sedeBorrar = callApi($kernel, 'POST', '/api/v1/clientes/' . $propioId . '/sedes', ['nombre' => 'Borrar', 'codigo' => 'BORRAR'], $adminToken);
    $sedeBorrarId = (int) ($sedeBorrar['body']['data']['id'] ?? 0);
    callApi($kernel, 'DELETE', '/api/v1/clientes/' . $propioId . '/sedes/' . $sedeBorrarId, null, $adminToken);
    record('27', 'Sede eliminada', unidadMinima($kernel, $adminToken, $propioId, $camion, 'U-SEDE-DEL', null, $sedeBorrarId)['status'] === 422, '422');
    $flotaBorrar = callApi($kernel, 'POST', '/api/v1/clientes/' . $propioId . '/flotas', ['nombre' => 'Borrar', 'codigo' => 'BORRAR'], $adminToken);
    $flotaBorrarId = (int) ($flotaBorrar['body']['data']['id'] ?? 0);
    callApi($kernel, 'DELETE', '/api/v1/clientes/' . $propioId . '/flotas/' . $flotaBorrarId, null, $adminToken);
    record('28', 'Flota eliminada', unidadMinima($kernel, $adminToken, $propioId, $camion, 'U-FLOTA-DEL', null, null, $flotaBorrarId)['status'] === 422, '422');

    $potencial = cliente($kernel, $adminToken, $marca . ' Potencial', 'POTENCIAL');
    $inactivo = cliente($kernel, $adminToken, $marca . ' Inactivo', 'ACTIVO');
    $inactivoId = (int) $inactivo['body']['data']['id'];
    callApi($kernel, 'PATCH', '/api/v1/clientes/' . $inactivoId . '/estado', ['estado' => 'INACTIVO'], $adminToken);
    record('29', 'Cliente potencial', unidadMinima($kernel, $adminToken, (int) $potencial['body']['data']['id'], $camion, 'U-POT')['status'] === 409, '409');
    record('30', 'Cliente inactivo', unidadMinima($kernel, $adminToken, $inactivoId, $camion, 'U-INA')['status'] === 409, '409');
    record('31', 'Cliente activo', $minima['status'] === 201, codeOf($minima));
    record('32', 'Tipo inactivo en unidad', $unidadTipoInactivo['status'] === 422, codeOf($unidadTipoInactivo));
    record('33', 'Configuración inactiva en unidad', $asignarOff['status'] === 422, codeOf($asignarOff));
    $codigoDup = unidadMinima($kernel, $gestorToken, $propioId, $camion, 'U-MIN');
    record('34', 'Código duplicado en el cliente', $codigoDup['status'] === 409, codeOf($codigoDup));
    $otroCliente = unidadMinima($kernel, $adminToken, $ajenoId, $camion, 'U-MIN');
    record('35', 'Mismo código en otro cliente', $otroCliente['status'] === 201, codeOf($otroCliente));
    $km = unidadMinima($kernel, $adminToken, $propioId, $camion, 'U-KM');
    $km['body'] = [];
    $kmNeg = callApi($kernel, 'POST', '/api/v1/unidades', unidadBody($propioId, $camion, 'U-KM', ['kilometraje_actual' => -1]), $adminToken);
    $horoNeg = callApi($kernel, 'POST', '/api/v1/unidades', unidadBody($propioId, $camion, 'U-HORO', ['horometro_actual' => -2]), $adminToken);
    $anioMal = callApi($kernel, 'POST', '/api/v1/unidades', unidadBody($propioId, $camion, 'U-ANIO', ['anio' => 1800]), $adminToken);
    $anioFuturo = callApi($kernel, 'POST', '/api/v1/unidades', unidadBody($propioId, $camion, 'U-ANIO2', ['anio' => $anioMax + 5]), $adminToken);
    record('36', 'Kilometraje negativo', $kmNeg['status'] === 422, codeOf($kmNeg));
    record('37', 'Horómetro negativo', $horoNeg['status'] === 422, codeOf($horoNeg));
    record('38', 'Año inválido', $anioMal['status'] === 422 && $anioFuturo['status'] === 422, codeOf($anioMal));

    $editUnidad = callApi($kernel, 'PUT', '/api/v1/unidades/' . $minimaId, unidadEdicion($camion, 'U-MIN', [
        'placa' => 'ABC123',
        'marca' => 'Volvo',
    ]), $gestorToken);
    record('39', 'Editar unidad', $editUnidad['status'] === 200 && ($editUnidad['body']['data']['placa'] ?? '') === 'ABC123', codeOf($editUnidad));

    $otraActiva = callApi($kernel, 'POST', '/api/v1/configuraciones-unidad', configuracion('Alterna ' . $marca), $adminToken);
    $otraId = (int) ($otraActiva['body']['data']['id'] ?? 0);
    $cambio = callApi($kernel, 'PUT', '/api/v1/unidades/' . $usadaId, unidadEdicion($camion, 'U-CONFIG', ['configuracion_id' => $otraId]), $adminToken);
    record('40', 'Cambiar configuración sin histórico', $cambio['status'] === 200 && ($cambio['body']['data']['configuracion']['id'] ?? 0) === $otraId, codeOf($cambio));
    $insp = $pdo->prepare(
        'INSERT INTO inspecciones (cliente_id, unidad_id, fecha_inspeccion, tecnico_id, estado, creado_por)
         VALUES (:cliente, :unidad, NOW(), :tecnico, \'BORRADOR\', :creado)'
    );
    $insp->bindValue('cliente', $propioId, PDO::PARAM_INT);
    $insp->bindValue('unidad', $usadaId, PDO::PARAM_INT);
    $insp->bindValue('tecnico', $admin, PDO::PARAM_INT);
    $insp->bindValue('creado', $admin, PDO::PARAM_INT);
    $insp->execute();
    $bloqueada = callApi($kernel, 'PUT', '/api/v1/unidades/' . $usadaId, unidadEdicion($camion, 'U-CONFIG', ['configuracion_id' => $configId]), $adminToken);
    record('41', 'Cambiar configuración con histórico', $bloqueada['status'] === 409 && ($bloqueada['body']['err']['code'] ?? '') === 'UNIT_CONFIGURATION_LOCKED', codeOf($bloqueada));

    $aInactiva = callApi($kernel, 'PATCH', '/api/v1/unidades/' . $minimaId . '/estado', ['estado' => 'INACTIVA'], $gestorToken);
    $aOperativa = callApi($kernel, 'PATCH', '/api/v1/unidades/' . $minimaId . '/estado', ['estado' => 'OPERATIVA'], $gestorToken);
    record('42', 'Operativa a inactiva', $aInactiva['status'] === 200 && ($aInactiva['body']['data']['estado'] ?? '') === 'INACTIVA', codeOf($aInactiva));
    record('43', 'Inactiva a operativa', $aOperativa['status'] === 200 && ($aOperativa['body']['data']['estado'] ?? '') === 'OPERATIVA', codeOf($aOperativa));
    $gestorBaja = callApi($kernel, 'PATCH', '/api/v1/unidades/' . $minimaId . '/estado', ['estado' => 'BAJA'], $gestorToken);
    $adminBaja = callApi($kernel, 'PATCH', '/api/v1/unidades/' . $minimaId . '/estado', ['estado' => 'BAJA'], $adminToken);
    $gestorReactiva = callApi($kernel, 'PATCH', '/api/v1/unidades/' . $minimaId . '/estado', ['estado' => 'OPERATIVA'], $gestorToken);
    $adminReactiva = callApi($kernel, 'PATCH', '/api/v1/unidades/' . $minimaId . '/estado', ['estado' => 'OPERATIVA'], $adminToken);
    record('44', 'Baja solo administración general', $gestorBaja['status'] === 403 && $adminBaja['status'] === 200 && $gestorReactiva['status'] === 403 && $adminReactiva['status'] === 200, 'gestor ' . $gestorBaja['status'] . '; admin ' . $adminBaja['status']);

    $borrar = callApi($kernel, 'DELETE', '/api/v1/unidades/' . $minimaId, null, $gestorToken);
    $listaBorrada = callApi($kernel, 'GET', '/api/v1/unidades?search=U-MIN&cliente_id=' . $propioId, null, $adminToken);
    $visible = false;
    foreach ($listaBorrada['body']['data'] ?? [] as $item) {
        if ((int) $item['id'] === $minimaId) {
            $visible = true;
        }
    }
    record('45', 'Soft-delete de unidad', $borrar['status'] === 200 && ($borrar['body']['data']['eliminado'] ?? false) === true, codeOf($borrar));
    record('46', 'Unidad eliminada fuera del listado', $visible === false && ($listaBorrada['body']['total'] ?? 1) === 0, 'total ' . ($listaBorrada['body']['total'] ?? ''));

    $adminLista = callApi($kernel, 'GET', '/api/v1/unidades?limit=1', null, $adminToken);
    $gestorLista = callApi($kernel, 'GET', '/api/v1/unidades?search=U-CONFIG', null, $gestorToken);
    $tecnicoLista = callApi($kernel, 'GET', '/api/v1/unidades?search=U-CONFIG', null, $tecnicoToken);
    $vendedorLista = callApi($kernel, 'GET', '/api/v1/unidades?search=U-CONFIG', null, $vendedorToken);
    $adminClienteLista = callApi($kernel, 'GET', '/api/v1/unidades?cliente_id=' . $propioId, null, $adminClienteToken);
    $consultaLista = callApi($kernel, 'GET', '/api/v1/unidades?cliente_id=' . $propioId, null, $consultaToken);
    $ajenaGestor = callApi($kernel, 'GET', '/api/v1/unidades/' . (int) ($otroCliente['body']['data']['id'] ?? 0), null, $gestorToken);
    $filtroAjeno = callApi($kernel, 'GET', '/api/v1/unidades?cliente_id=' . $ajenoId, null, $vendedorToken);
    $sedeAjenaFiltro = callApi($kernel, 'POST', '/api/v1/unidades', unidadBody($propioId, $camion, 'U-MIX', ['sede_id' => $sedeAjenaId, 'flota_id' => $flotaAjenaId]), $gestorToken);
    $tecnicoAlta = callApi($kernel, 'POST', '/api/v1/unidades', unidadBody($propioId, $camion, 'U-TEC'), $tecnicoToken);
    $vendedorAlta = callApi($kernel, 'POST', '/api/v1/unidades', unidadBody($propioId, $camion, 'U-VEN'), $vendedorToken);
    record('47', 'Administración general ve el conjunto', $adminLista['status'] === 200 && ($adminLista['body']['total'] ?? 0) >= 2, 'total ' . ($adminLista['body']['total'] ?? 0));
    record('48', 'Gestor solo su alcance', $gestorLista['status'] === 200 && count($gestorLista['body']['data'] ?? []) === 1, 'filas ' . count($gestorLista['body']['data'] ?? []));
    record('49', 'Técnico solo lectura de su alcance', $tecnicoLista['status'] === 200 && count($tecnicoLista['body']['data'] ?? []) === 1 && $tecnicoAlta['status'] === 403, codeOf($tecnicoAlta));
    record('50', 'Vendedor solo lectura de su alcance', $vendedorLista['status'] === 200 && $vendedorAlta['status'] === 403, codeOf($vendedorAlta));
    record('51', 'Administrador cliente solo su cliente', $adminClienteLista['status'] === 200 && callApi($kernel, 'GET', '/api/v1/unidades?cliente_id=' . $ajenoId, null, $adminClienteToken)['status'] === 403, 'ok');
    record('52', 'Consulta ejecutiva solo su cliente', $consultaLista['status'] === 200 && callApi($kernel, 'GET', '/api/v1/unidades/' . (int) ($otroCliente['body']['data']['id'] ?? 0), null, $consultaToken)['status'] === 403, 'ok');
    record('53', 'IDOR de unidad', $ajenaGestor['status'] === 403 && ($ajenaGestor['body']['err']['code'] ?? '') === 'CLIENT_SCOPE_FORBIDDEN', codeOf($ajenaGestor));
    record('54', 'Filtro de cliente ajeno', $filtroAjeno['status'] === 403, codeOf($filtroAjeno));
    record('55', 'Sede y flota ajenas bloqueadas', $sedeAjenaFiltro['status'] === 422, codeOf($sedeAjenaFiltro));

    record('56', 'Auditoría de alta de unidad', audit($pdo, 'UNIDAD_CREATE', $usadaId) >= 1, 'filas');
    record('57', 'Auditoría de edición de unidad', audit($pdo, 'UNIDAD_UPDATE', $minimaId) >= 1, 'filas');
    record('58', 'Auditoría de estado', audit($pdo, 'UNIDAD_ESTADO', $minimaId) >= 1, 'filas');
    record('59', 'Auditoría de baja lógica', audit($pdo, 'UNIDAD_DELETE', $minimaId) >= 1, 'filas');
    record('60', 'Auditoría de configuración', audit($pdo, 'CONFIGURACION_CREATE', $configId) >= 1, 'filas');
    record('61', 'Auditoría de duplicado', audit($pdo, 'CONFIGURACION_DUPLICATE', $copiaId) >= 1, 'filas');
} catch (Throwable $error) {
    $failed = true;
    record('EX', $error::class, false, $error->getMessage());
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
function callApi(Kernel $kernel, string $method, string $path, ?array $json = null, ?string $token = null): array
{
    $headers = ['user-agent' => 'bloque2-test', 'x-client-ip' => '203.0.113.20'];
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
        'email' => 'b2.' . bin2hex(random_bytes(5)) . '@test.local',
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
function cliente(Kernel $kernel, string $token, string $razon, string $estado): array
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
    $statement = $pdo->prepare(
        'INSERT INTO usuario_clientes (usuario_id, cliente_id, fecha_inicio, fecha_fin, activo)
         VALUES (:usuario, :cliente, :inicio, NULL, 1)'
    );
    $statement->bindValue('usuario', $usuarioId, PDO::PARAM_INT);
    $statement->bindValue('cliente', $clienteId, PDO::PARAM_INT);
    $statement->bindValue('inicio', $inicio);
    $statement->execute();
}

function idTipo(PDO $pdo, string $codigo): int
{
    $statement = $pdo->prepare('SELECT id FROM tipos_unidad WHERE codigo = :codigo');
    $statement->execute(['codigo' => $codigo]);

    return (int) $statement->fetchColumn();
}

/** @return array<string, mixed> */
function configuracion(string $nombre): array
{
    return [
        'nombre' => $nombre,
        'descripcion' => 'Plantilla de prueba',
        'ejes' => [
            [
                'numero_eje' => 1,
                'nombre' => 'Delantero',
                'orden' => 1,
                'posiciones' => [
                    ['codigo' => 'E1-I', 'lado' => 'IZQUIERDO', 'ubicacion' => 'SIMPLE', 'orden' => 1],
                    ['codigo' => 'E1-D', 'lado' => 'DERECHO', 'ubicacion' => 'SIMPLE', 'orden' => 2],
                ],
            ],
            [
                'numero_eje' => 2,
                'nombre' => 'Posterior',
                'orden' => 2,
                'posiciones' => [
                    ['codigo' => 'E2-I', 'lado' => 'IZQUIERDO', 'ubicacion' => 'EXTERIOR', 'orden' => 3],
                    ['codigo' => 'E2-D', 'lado' => 'DERECHO', 'ubicacion' => 'EXTERIOR', 'orden' => 4],
                ],
            ],
        ],
    ];
}

/** @param array<string, mixed> $extra @return array<string, mixed> */
function unidadEdicion(int $tipoId, string $codigo, array $extra = []): array
{
    return array_merge([
        'tipo_unidad_id' => $tipoId,
        'codigo' => $codigo,
        'sede_id' => null,
        'flota_id' => null,
        'configuracion_id' => null,
    ], $extra);
}

/** @param array<string, mixed> $extra @return array<string, mixed> */
function unidadBody(int $clienteId, int $tipoId, string $codigo, array $extra = []): array
{
    return array_merge([
        'cliente_id' => $clienteId,
        'tipo_unidad_id' => $tipoId,
        'codigo' => $codigo,
        'sede_id' => null,
        'flota_id' => null,
        'configuracion_id' => null,
    ], $extra);
}

/** @return array{status:int,body:array<string,mixed>,headers:array<string,string>} */
function unidadMinima(Kernel $kernel, string $token, int $clienteId, int $tipoId, string $codigo, ?int $configuracionId = null, ?int $sedeId = null, ?int $flotaId = null): array
{
    return callApi($kernel, 'POST', '/api/v1/unidades', unidadBody($clienteId, $tipoId, $codigo, [
        'sede_id' => $sedeId,
        'flota_id' => $flotaId,
        'configuracion_id' => $configuracionId,
    ]), $token);
}

function audit(PDO $pdo, string $accion, int $entidadId): int
{
    $statement = $pdo->prepare('SELECT COUNT(*) FROM auditoria WHERE accion = :accion AND entidad_id = :id AND ip = :ip');
    $statement->execute(['accion' => $accion, 'id' => $entidadId, 'ip' => '203.0.113.20']);

    return (int) $statement->fetchColumn();
}

/** @return array<string, int> */
function counts(PDO $pdo): array
{
    $tables = ['usuarios', 'clientes', 'cliente_contactos', 'cliente_responsables', 'sedes', 'flotas', 'auditoria', 'usuario_clientes', 'tipos_unidad', 'configuraciones_unidad', 'configuracion_ejes', 'configuracion_posiciones', 'unidades', 'inspecciones'];
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
