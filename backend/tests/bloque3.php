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

try {
    $password = bin2hex(random_bytes(8));
    $admin = user($pdo, 'Admin', 'B3', 'ADMIN_GENERAL', $password);
    $gestor = user($pdo, 'Gestor', 'B3', 'GESTOR_NEUMATICOS', $password);
    $tecnico = user($pdo, 'Tecnico', 'B3', 'TECNICO_INSPECCION', $password);
    $vendedor = user($pdo, 'Vendedor', 'B3', 'VENDEDOR', $password);
    $adminCliente = user($pdo, 'Admin', 'Cliente', 'ADMIN_CLIENTE', $password);
    $consulta = user($pdo, 'Consulta', 'B3', 'CONSULTA_EJECUTIVA', $password);
    $adminToken = token($kernel, emailOf($pdo, $admin), $password);
    $gestorToken = token($kernel, emailOf($pdo, $gestor), $password);
    $tecnicoToken = token($kernel, emailOf($pdo, $tecnico), $password);
    $vendedorToken = token($kernel, emailOf($pdo, $vendedor), $password);
    $adminClienteToken = token($kernel, emailOf($pdo, $adminCliente), $password);
    $consultaToken = token($kernel, emailOf($pdo, $consulta), $password);

    $sufijo = strtoupper(bin2hex(random_bytes(3)));
    $propio = cliente($kernel, $adminToken, 'B3 ' . $sufijo . ' Propio', 'ACTIVO');
    $ajeno = cliente($kernel, $adminToken, 'B3 ' . $sufijo . ' Ajeno', 'ACTIVO');
    $potencial = cliente($kernel, $adminToken, 'B3 ' . $sufijo . ' Potencial', 'POTENCIAL');
    $inactivo = cliente($kernel, $adminToken, 'B3 ' . $sufijo . ' Inactivo', 'ACTIVO');
    $propioId = (int) $propio['body']['data']['id'];
    $ajenoId = (int) $ajeno['body']['data']['id'];
    $potencialId = (int) $potencial['body']['data']['id'];
    $inactivoId = (int) $inactivo['body']['data']['id'];
    callApi($kernel, 'PATCH', '/api/v1/clientes/' . $inactivoId . '/estado', ['estado' => 'INACTIVO'], $adminToken);
    foreach ([$gestor, $tecnico, $vendedor, $adminCliente, $consulta] as $usuario) {
        scope($pdo, $usuario, $propioId, $hoy);
    }

    $lista = callApi($kernel, 'GET', '/api/v1/marcas-neumatico?limit=20', null, $gestorToken);
    record('1', 'Listar marcas', $lista['status'] === 200 && is_array($lista['body']['data'] ?? null), 'total ' . ($lista['body']['total'] ?? ''));

    $nombre = 'Marca ' . $sufijo;
    $marca = callApi($kernel, 'POST', '/api/v1/marcas-neumatico', ['nombre' => $nombre], $gestorToken);
    $marcaId = (int) ($marca['body']['data']['id'] ?? 0);
    $tecnicoMarca = callApi($kernel, 'POST', '/api/v1/marcas-neumatico', ['nombre' => 'No ' . $sufijo], $tecnicoToken);
    record('2', 'Crear marca', $marca['status'] === 201 && $tecnicoMarca['status'] === 403, codeOf($marca));
    $dup = callApi($kernel, 'POST', '/api/v1/marcas-neumatico', ['nombre' => $nombre], $adminToken);
    record('3', 'Marca duplicada', $dup['status'] === 409 && ($dup['body']['err']['code'] ?? '') === 'CONFLICT', codeOf($dup));
    $edit = callApi($kernel, 'PUT', '/api/v1/marcas-neumatico/' . $marcaId, ['nombre' => $nombre . ' editada'], $adminToken);
    record('4', 'Editar marca', $edit['status'] === 200 && ($edit['body']['data']['nombre'] ?? '') === $nombre . ' editada', codeOf($edit));
    $off = callApi($kernel, 'PATCH', '/api/v1/marcas-neumatico/' . $marcaId . '/estado', ['activo' => false], $gestorToken);
    record('5', 'Desactivar marca', $off['status'] === 200 && ($off['body']['data']['activo'] ?? true) === false, codeOf($off));
    $modeloOff = callApi($kernel, 'POST', '/api/v1/modelos-neumatico', ['marca_id' => $marcaId, 'nombre' => 'X ' . $sufijo], $adminToken);
    record('6', 'Marca inactiva no permite nuevo modelo', $modeloOff['status'] === 422, codeOf($modeloOff));

    $activa = callApi($kernel, 'POST', '/api/v1/marcas-neumatico', ['nombre' => 'Activa ' . $sufijo], $adminToken);
    $activaId = (int) $activa['body']['data']['id'];
    $otra = callApi($kernel, 'POST', '/api/v1/marcas-neumatico', ['nombre' => 'Otra ' . $sufijo], $adminToken);
    $otraId = (int) $otra['body']['data']['id'];
    $modelo = callApi($kernel, 'POST', '/api/v1/modelos-neumatico', ['marca_id' => $activaId, 'nombre' => 'Modelo ' . $sufijo, 'descripcion' => 'Banda'], $gestorToken);
    $modeloId = (int) ($modelo['body']['data']['id'] ?? 0);
    record('7', 'Crear modelo', $modelo['status'] === 201 && ($modelo['body']['data']['marca_id'] ?? 0) === $activaId, codeOf($modelo));
    $sinMarca = callApi($kernel, 'POST', '/api/v1/modelos-neumatico', ['marca_id' => 99999999, 'nombre' => 'Fantasma'], $adminToken);
    record('8', 'Marca inexistente', $sinMarca['status'] === 422, codeOf($sinMarca));
    $marcaCorta = callApi($kernel, 'POST', '/api/v1/marcas-neumatico', ['nombre' => 'Corta ' . $sufijo], $adminToken);
    $marcaCortaId = (int) $marcaCorta['body']['data']['id'];
    callApi($kernel, 'PATCH', '/api/v1/marcas-neumatico/' . $marcaCortaId . '/estado', ['activo' => false], $adminToken);
    $modeloInactivo = callApi($kernel, 'POST', '/api/v1/modelos-neumatico', ['marca_id' => $marcaCortaId, 'nombre' => 'No nace'], $adminToken);
    record('9', 'Marca inactiva', $modeloInactivo['status'] === 422, codeOf($modeloInactivo));
    $modeloDup = callApi($kernel, 'POST', '/api/v1/modelos-neumatico', ['marca_id' => $activaId, 'nombre' => 'Modelo ' . $sufijo], $adminToken);
    record('10', 'Modelo duplicado en la marca', $modeloDup['status'] === 409, codeOf($modeloDup));
    $modeloOtra = callApi($kernel, 'POST', '/api/v1/modelos-neumatico', ['marca_id' => $otraId, 'nombre' => 'Modelo ' . $sufijo], $adminToken);
    record('11', 'Mismo nombre en otra marca', $modeloOtra['status'] === 201, codeOf($modeloOtra));
    $modeloEstado = callApi($kernel, 'PATCH', '/api/v1/modelos-neumatico/' . $modeloOtra['body']['data']['id'] . '/estado', ['activo' => false], $adminToken);
    record('12', 'Desactivar modelo', $modeloEstado['status'] === 200 && ($modeloEstado['body']['data']['activo'] ?? true) === false, codeOf($modeloEstado));

    $medida = callApi($kernel, 'POST', '/api/v1/medidas-neumatico', ['descripcion' => '295/80 R22.5 ' . $sufijo, 'ancho' => '295', 'perfil' => '80', 'construccion' => 'R', 'diametro' => '22.5'], $gestorToken);
    $medidaId = (int) ($medida['body']['data']['id'] ?? 0);
    record('14', 'Crear medida', $medida['status'] === 201, codeOf($medida));
    $medidaDup = callApi($kernel, 'POST', '/api/v1/medidas-neumatico', ['descripcion' => '295/80 R22.5 ' . $sufijo], $adminToken);
    record('15', 'Descripción de medida duplicada', $medidaDup['status'] === 409, codeOf($medidaDup));
    $negativa = callApi($kernel, 'POST', '/api/v1/medidas-neumatico', ['descripcion' => 'Neg ' . $sufijo, 'ancho' => -1], $adminToken);
    record('16', 'Valores negativos de medida', $negativa['status'] === 422, codeOf($negativa));
    $medidaOffRow = callApi($kernel, 'POST', '/api/v1/medidas-neumatico', ['descripcion' => 'Off ' . $sufijo], $adminToken);
    $medidaOffId = (int) $medidaOffRow['body']['data']['id'];
    $medidaOff = callApi($kernel, 'PATCH', '/api/v1/medidas-neumatico/' . $medidaOffId . '/estado', ['activo' => false], $adminToken);
    record('17', 'Desactivar medida', $medidaOff['status'] === 200 && ($medidaOff['body']['data']['activo'] ?? true) === false, codeOf($medidaOff));

    $base = neumatico($propioId, $modeloId, $medidaId, 'N-' . $sufijo);
    $alta = callApi($kernel, 'POST', '/api/v1/neumaticos', $base, $gestorToken);
    $neumaticoId = (int) ($alta['body']['data']['id'] ?? 0);
    $data = $alta['body']['data'] ?? [];
    record('19', 'Crear neumático válido', $alta['status'] === 201 && ($data['codigo'] ?? '') === 'N-' . $sufijo, codeOf($alta));
    record('20', 'Estado inicial disponible', ($data['estado']['codigo'] ?? '') === 'DISPONIBLE', (string) ($data['estado']['codigo'] ?? ''));
    record('21', 'Vida actual inicial', ($data['vida_actual'] ?? 0) === 1, (string) ($data['vida_actual'] ?? ''));
    $vidas = callApi($kernel, 'GET', '/api/v1/neumaticos/' . $neumaticoId . '/vidas', null, $adminToken);
    $vida = $vidas['body']['data'][0] ?? [];
    record('22', 'Se crea Vida 1', ($vidas['body']['total'] ?? 0) === 1 && ($vida['numero_vida'] ?? 0) === 1 && array_key_exists('fecha_fin', $vida) && $vida['fecha_fin'] === null, 'total ' . ($vidas['body']['total'] ?? 0));
    $hist = callApi($kernel, 'GET', '/api/v1/neumaticos/' . $neumaticoId . '/historial-estados', null, $adminToken);
    $evento = $hist['body']['data'][0] ?? [];
    record('23', 'Se crea historial inicial', ($hist['body']['total'] ?? 0) === 1 && array_key_exists('estado_anterior', $evento) && $evento['estado_anterior'] === null && ($evento['estado_nuevo']['codigo'] ?? '') === 'DISPONIBLE' && ($evento['motivo'] ?? '') === 'Alta inicial del neumático', (string) ($evento['motivo'] ?? ''));
    $antesVidas = (int) $pdo->query('SELECT COUNT(*) FROM neumatico_vidas')->fetchColumn();
    $fallo = callApi($kernel, 'POST', '/api/v1/neumaticos', $base, $adminToken);
    $despuesVidas = (int) $pdo->query('SELECT COUNT(*) FROM neumatico_vidas')->fetchColumn();
    record('24', 'Alta transaccional', $fallo['status'] === 409 && $antesVidas === $despuesVidas && (int) $vida['numero_vida'] === 1, 'vidas ' . $antesVidas . '→' . $despuesVidas);
    $conEstado = $base;
    $conEstado['codigo'] = 'N-EST-' . $sufijo;
    $conEstado['estado_id'] = 1;
    record('25', 'Estado enviado manualmente', callApi($kernel, 'POST', '/api/v1/neumaticos', $conEstado, $adminToken)['status'] === 422, '422');
    $conVida = $base;
    $conVida['codigo'] = 'N-VIDA-' . $sufijo;
    $conVida['vida_actual'] = 2;
    record('26', 'Vida enviada manualmente', callApi($kernel, 'POST', '/api/v1/neumaticos', $conVida, $adminToken)['status'] === 422, '422');
    record('27', 'Cliente potencial', callApi($kernel, 'POST', '/api/v1/neumaticos', neumatico($potencialId, $modeloId, $medidaId, 'N-POT-' . $sufijo), $adminToken)['status'] === 409, '409');
    record('28', 'Cliente inactivo', callApi($kernel, 'POST', '/api/v1/neumaticos', neumatico($inactivoId, $modeloId, $medidaId, 'N-INA-' . $sufijo), $adminToken)['status'] === 409, '409');
    record('29', 'Cliente activo', $alta['status'] === 201, codeOf($alta));
    record('30', 'Código duplicado en el cliente', $fallo['status'] === 409 && ($fallo['body']['err']['code'] ?? '') === 'CONFLICT', codeOf($fallo));
    $otroCliente = callApi($kernel, 'POST', '/api/v1/neumaticos', neumatico($ajenoId, $modeloId, $medidaId, 'N-' . $sufijo), $adminToken);
    record('31', 'Mismo código en otro cliente', $otroCliente['status'] === 201, codeOf($otroCliente));
    $serie = neumatico($propioId, $modeloId, $medidaId, 'N-SER-1-' . $sufijo, ['numero_serie' => 'SER-' . $sufijo]);
    $serieAlta = callApi($kernel, 'POST', '/api/v1/neumaticos', $serie, $adminToken);
    $serieDup = callApi($kernel, 'POST', '/api/v1/neumaticos', neumatico($propioId, $modeloId, $medidaId, 'N-SER-2-' . $sufijo, ['numero_serie' => 'SER-' . $sufijo]), $adminToken);
    record('32', 'Serie duplicada en el cliente', $serieAlta['status'] === 201 && $serieDup['status'] === 409, codeOf($serieDup));
    $nulaA = callApi($kernel, 'POST', '/api/v1/neumaticos', neumatico($propioId, $modeloId, $medidaId, 'N-NULL-A-' . $sufijo), $adminToken);
    $nulaB = callApi($kernel, 'POST', '/api/v1/neumaticos', neumatico($propioId, $modeloId, $medidaId, 'N-NULL-B-' . $sufijo, ['numero_serie' => '']), $adminToken);
    $serieNula = $nulaB['body']['data'] ?? [];
    record('33', 'Serie vacía repetida', $nulaA['status'] === 201 && $nulaB['status'] === 201 && array_key_exists('numero_serie', $serieNula) && $serieNula['numero_serie'] === null, codeOf($nulaB));
    $modeloInactivoId = (int) $modeloOtra['body']['data']['id'];
    $rechazoModelo = callApi($kernel, 'POST', '/api/v1/neumaticos', neumatico($propioId, $modeloInactivoId, $medidaId, 'N-MOD-' . $sufijo), $adminToken);
    record('13', 'Modelo inactivo no permite nuevo neumático', $rechazoModelo['status'] === 422, codeOf($rechazoModelo));
    record('34', 'Modelo inactivo', $rechazoModelo['status'] === 422, codeOf($rechazoModelo));
    $rechazoMedida = callApi($kernel, 'POST', '/api/v1/neumaticos', neumatico($propioId, $modeloId, $medidaOffId, 'N-MED-' . $sufijo), $adminToken);
    record('18', 'Medida inactiva no permite nuevo neumático', $rechazoMedida['status'] === 422, codeOf($rechazoMedida));
    record('35', 'Medida inactiva', $rechazoMedida['status'] === 422, codeOf($rechazoMedida));
    $marcaParaCerrar = callApi($kernel, 'POST', '/api/v1/marcas-neumatico', ['nombre' => 'Cierra ' . $sufijo], $adminToken);
    $modeloCierra = callApi($kernel, 'POST', '/api/v1/modelos-neumatico', ['marca_id' => $marcaParaCerrar['body']['data']['id'], 'nombre' => 'Cierra ' . $sufijo], $adminToken);
    callApi($kernel, 'PATCH', '/api/v1/marcas-neumatico/' . $marcaParaCerrar['body']['data']['id'] . '/estado', ['activo' => false], $adminToken);
    $rechazoMarca = callApi($kernel, 'POST', '/api/v1/neumaticos', neumatico($propioId, (int) $modeloCierra['body']['data']['id'], $medidaId, 'N-MAR-' . $sufijo), $adminToken);
    record('36', 'Marca inactiva para nuevo neumático', $rechazoMarca['status'] === 422, codeOf($rechazoMarca));
    record('37', 'Profundidad negativa', callApi($kernel, 'POST', '/api/v1/neumaticos', neumatico($propioId, $modeloId, $medidaId, 'N-PROF-' . $sufijo, ['profundidad_minima_mm' => -1]), $adminToken)['status'] === 422, '422');
    record('38', 'Inicial menor que mínima', callApi($kernel, 'POST', '/api/v1/neumaticos', neumatico($propioId, $modeloId, $medidaId, 'N-CMP-' . $sufijo, ['profundidad_inicial_mm' => 2, 'profundidad_minima_mm' => 4]), $adminToken)['status'] === 422, '422');
    record('39', 'Costo negativo', callApi($kernel, 'POST', '/api/v1/neumaticos', neumatico($propioId, $modeloId, $medidaId, 'N-COS-' . $sufijo, ['costo_adquisicion' => -5, 'moneda' => 'PEN']), $adminToken)['status'] === 422, '422');
    record('40', 'Moneda inválida', callApi($kernel, 'POST', '/api/v1/neumaticos', neumatico($propioId, $modeloId, $medidaId, 'N-MON-' . $sufijo, ['costo_adquisicion' => 10, 'moneda' => 'pen']), $adminToken)['status'] === 422, '422');

    $edicion = callApi($kernel, 'PUT', '/api/v1/neumaticos/' . $neumaticoId, edicion($modeloId, $medidaId, ['observacion' => 'Revisado', 'numero_serie' => 'EDIT-' . $sufijo]), $gestorToken);
    record('41', 'Editar neumático', $edicion['status'] === 200 && ($edicion['body']['data']['observacion'] ?? '') === 'Revisado' && ($edicion['body']['data']['codigo'] ?? '') === 'N-' . $sufijo, codeOf($edicion));
    $cambiaCodigo = edicion($modeloId, $medidaId);
    $cambiaCodigo['codigo'] = 'OTRO';
    record('42', 'Cambiar código', callApi($kernel, 'PUT', '/api/v1/neumaticos/' . $neumaticoId, $cambiaCodigo, $adminToken)['status'] === 422, '422');
    $cambiaCliente = edicion($modeloId, $medidaId);
    $cambiaCliente['cliente_id'] = $ajenoId;
    record('43', 'Cambiar cliente', callApi($kernel, 'PUT', '/api/v1/neumaticos/' . $neumaticoId, $cambiaCliente, $adminToken)['status'] === 422, '422');
    $cambiaEstado = edicion($modeloId, $medidaId);
    $cambiaEstado['estado_id'] = 2;
    record('44', 'Cambiar estado', callApi($kernel, 'PUT', '/api/v1/neumaticos/' . $neumaticoId, $cambiaEstado, $adminToken)['status'] === 422, '422');
    $cambiaVida = edicion($modeloId, $medidaId);
    $cambiaVida['vida_actual'] = 2;
    record('45', 'Cambiar vida', callApi($kernel, 'PUT', '/api/v1/neumaticos/' . $neumaticoId, $cambiaVida, $adminToken)['status'] === 422, '422');

    $dependiente = callApi($kernel, 'POST', '/api/v1/neumaticos', neumatico($propioId, $modeloId, $medidaId, 'N-DEP-' . $sufijo), $adminToken);
    $dependienteId = (int) $dependiente['body']['data']['id'];
    $tipoMov = (int) $pdo->query("SELECT id FROM tipos_movimiento WHERE codigo = 'INGRESO'")->fetchColumn();
    $mov = $pdo->prepare('INSERT INTO movimientos_neumatico (cliente_id, neumatico_id, tipo_movimiento_id, fecha, usuario_id) VALUES (:cliente, :neumatico, :tipo, NOW(), :usuario)');
    $mov->bindValue('cliente', $propioId, PDO::PARAM_INT);
    $mov->bindValue('neumatico', $dependienteId, PDO::PARAM_INT);
    $mov->bindValue('tipo', $tipoMov, PDO::PARAM_INT);
    $mov->bindValue('usuario', $admin, PDO::PARAM_INT);
    $mov->execute();
    $deleteDep = callApi($kernel, 'DELETE', '/api/v1/neumaticos/' . $dependienteId, null, $adminToken);
    $montado = callApi($kernel, 'POST', '/api/v1/neumaticos', neumatico($propioId, $modeloId, $medidaId, 'N-MON-' . $sufijo), $adminToken);
    $montadoId = (int) $montado['body']['data']['id'];
    $estadoMontado = (int) $pdo->query("SELECT id FROM estados_neumatico WHERE codigo = 'MONTADO'")->fetchColumn();
    $cambia = $pdo->prepare('UPDATE neumaticos SET estado_id = :estado WHERE id = :id');
    $cambia->bindValue('estado', $estadoMontado, PDO::PARAM_INT);
    $cambia->bindValue('id', $montadoId, PDO::PARAM_INT);
    $cambia->execute();
    $deleteMontado = callApi($kernel, 'DELETE', '/api/v1/neumaticos/' . $montadoId, null, $gestorToken);
    $limpio = callApi($kernel, 'POST', '/api/v1/neumaticos', neumatico($propioId, $modeloId, $medidaId, 'N-DEL-' . $sufijo), $gestorToken);
    $limpioId = (int) $limpio['body']['data']['id'];
    $deleteOk = callApi($kernel, 'DELETE', '/api/v1/neumaticos/' . $limpioId, null, $gestorToken);
    record('46', 'Soft-delete disponible sin dependencias', $deleteDep['status'] === 409 && $deleteMontado['status'] === 409 && $deleteOk['status'] === 200 && ($deleteOk['body']['data']['eliminado'] ?? false) === true, codeOf($deleteOk));
    $listaBaja = callApi($kernel, 'GET', '/api/v1/neumaticos?search=N-DEL-' . $sufijo, null, $adminToken);
    record('47', 'Eliminado fuera del listado', $listaBaja['status'] === 200 && ($listaBaja['body']['total'] ?? 1) === 0, 'total ' . ($listaBaja['body']['total'] ?? ''));
    $reuso = callApi($kernel, 'POST', '/api/v1/neumaticos', neumatico($propioId, $modeloId, $medidaId, 'N-DEL-' . $sufijo), $adminToken);
    record('48', 'Código eliminado sigue ocupado', $reuso['status'] === 409, codeOf($reuso));

    $histFinal = callApi($kernel, 'GET', '/api/v1/neumaticos/' . $neumaticoId . '/historial-estados', null, $tecnicoToken);
    record('49', 'Historial de estados', $histFinal['status'] === 200 && ($histFinal['body']['data'][0]['motivo'] ?? '') === 'Alta inicial del neumático', codeOf($histFinal));
    $escribeHist = callApi($kernel, 'POST', '/api/v1/neumaticos/' . $neumaticoId . '/historial-estados', ['motivo' => 'manual'], $adminToken);
    record('50', 'Sin escritura manual de historial', $escribeHist['status'] === 404, codeOf($escribeHist));
    $vidasFinal = callApi($kernel, 'GET', '/api/v1/neumaticos/' . $neumaticoId . '/vidas', null, $vendedorToken);
    record('51', 'Vidas', $vidasFinal['status'] === 200 && ($vidasFinal['body']['data'][0]['numero_vida'] ?? 0) === 1, 'total ' . ($vidasFinal['body']['total'] ?? 0));
    $escribeVida = callApi($kernel, 'POST', '/api/v1/neumaticos/' . $neumaticoId . '/vidas', ['numero_vida' => 2], $adminToken);
    record('52', 'Sin CRUD manual de vidas', $escribeVida['status'] === 404 && callApi($kernel, 'PUT', '/api/v1/neumaticos/' . $neumaticoId . '/vidas', ['numero_vida' => 2], $adminToken)['status'] === 404, codeOf($escribeVida));

    $adminLista = callApi($kernel, 'GET', '/api/v1/neumaticos?limit=100', null, $adminToken);
    $idsAdmin = array_map(static fn (array $row): int => (int) $row['id'], $adminLista['body']['data'] ?? []);
    $ajenoNeumatico = (int) $otroCliente['body']['data']['id'];
    record('53', 'Administración general ve el conjunto', $adminLista['status'] === 200 && in_array($neumaticoId, $idsAdmin, true) && in_array($ajenoNeumatico, $idsAdmin, true), 'total ' . ($adminLista['body']['total'] ?? 0));
    $gestorLista = callApi($kernel, 'GET', '/api/v1/neumaticos?limit=100', null, $gestorToken);
    $idsGestor = array_map(static fn (array $row): int => (int) $row['id'], $gestorLista['body']['data'] ?? []);
    record('54', 'Gestor solo su alcance', $gestorLista['status'] === 200 && in_array($neumaticoId, $idsGestor, true) && !in_array($ajenoNeumatico, $idsGestor, true), 'filas ' . count($idsGestor));
    $tecnicoLee = callApi($kernel, 'GET', '/api/v1/neumaticos/' . $neumaticoId, null, $tecnicoToken);
    $tecnicoEscribe = callApi($kernel, 'POST', '/api/v1/neumaticos', neumatico($propioId, $modeloId, $medidaId, 'N-TEC-' . $sufijo), $tecnicoToken);
    record('55', 'Técnico solo lectura de su alcance', $tecnicoLee['status'] === 200 && $tecnicoEscribe['status'] === 403, codeOf($tecnicoEscribe));
    $vendedorLee = callApi($kernel, 'GET', '/api/v1/neumaticos/' . $neumaticoId, null, $vendedorToken);
    $vendedorEscribe = callApi($kernel, 'PUT', '/api/v1/neumaticos/' . $neumaticoId, edicion($modeloId, $medidaId), $vendedorToken);
    record('56', 'Vendedor solo lectura de su alcance', $vendedorLee['status'] === 200 && $vendedorEscribe['status'] === 403, codeOf($vendedorEscribe));
    $adminClienteLee = callApi($kernel, 'GET', '/api/v1/neumaticos/' . $neumaticoId, null, $adminClienteToken);
    $adminClienteAjeno = callApi($kernel, 'GET', '/api/v1/neumaticos/' . $ajenoNeumatico, null, $adminClienteToken);
    record('57', 'Administrador cliente solo su cliente', $adminClienteLee['status'] === 200 && $adminClienteAjeno['status'] === 403, codeOf($adminClienteAjeno));
    $consultaLee = callApi($kernel, 'GET', '/api/v1/neumaticos/' . $neumaticoId, null, $consultaToken);
    $consultaEscribe = callApi($kernel, 'DELETE', '/api/v1/neumaticos/' . $neumaticoId, null, $consultaToken);
    record('58', 'Consulta ejecutiva solo su cliente', $consultaLee['status'] === 200 && $consultaEscribe['status'] === 403, codeOf($consultaEscribe));
    $idor = callApi($kernel, 'GET', '/api/v1/neumaticos/' . $ajenoNeumatico, null, $gestorToken);
    record('59', 'IDOR de neumático', $idor['status'] === 403 && ($idor['body']['err']['code'] ?? '') === 'CLIENT_SCOPE_FORBIDDEN', codeOf($idor));
    $filtroAjeno = callApi($kernel, 'GET', '/api/v1/neumaticos?cliente_id=' . $ajenoId, null, $gestorToken);
    record('60', 'Filtro de cliente ajeno', $filtroAjeno['status'] === 403 && ($filtroAjeno['body']['err']['code'] ?? '') === 'CLIENT_SCOPE_FORBIDDEN', codeOf($filtroAjeno));

    record('61', 'Auditoría de alta', audit($pdo, 'NEUMATICO_CREATE', $neumaticoId) >= 1, 'filas');
    record('62', 'Auditoría de edición', audit($pdo, 'NEUMATICO_UPDATE', $neumaticoId) >= 1, 'filas');
    record('63', 'Auditoría de baja lógica', audit($pdo, 'NEUMATICO_DELETE', $limpioId) >= 1, 'filas');

    $prefijo = 'ZZ' . $sufijo;
    $marcaLote = $pdo->prepare('INSERT INTO marcas_neumatico (nombre, activo) VALUES (:nombre, 1)');
    for ($i = 0; $i < 101; $i++) {
        $marcaLote->execute(['nombre' => sprintf('%s-%03d%s', $prefijo, $i, $i === 100 ? '-OBJETIVO' : '')]);
    }
    $objetivo = sprintf('%s-100-OBJETIVO', $prefijo);
    $primera = callApi($kernel, 'GET', '/api/v1/marcas-neumatico?search=' . rawurlencode($prefijo) . '&limit=20&page=1&sort=nombre&order=asc', null, $adminToken);
    $nombres = array_column($primera['body']['data'] ?? [], 'nombre');
    $hallada = callApi($kernel, 'GET', '/api/v1/marcas-neumatico?search=' . rawurlencode($objetivo) . '&limit=20&sort=nombre&order=asc', null, $adminToken);
    record(
        '78',
        'Búsqueda de marca más allá de la primera página',
        $primera['status'] === 200
            && ($primera['body']['total'] ?? 0) === 101
            && count($nombres) === 20
            && !in_array($objetivo, $nombres, true)
            && $hallada['status'] === 200
            && ($hallada['body']['total'] ?? 0) === 1
            && ($hallada['body']['data'][0]['nombre'] ?? '') === $objetivo,
        'total ' . ($primera['body']['total'] ?? 0) . '; hallada ' . ($hallada['body']['total'] ?? 0)
    );

    $modeloLote = $pdo->prepare('INSERT INTO modelos_neumatico (marca_id, nombre, activo) VALUES (:marca, :nombre, 1)');
    $modeloPrefijo = 'ZZM' . $sufijo;
    for ($i = 0; $i < 101; $i++) {
        $modeloLote->bindValue('marca', $activaId, PDO::PARAM_INT);
        $modeloLote->bindValue('nombre', sprintf('%s-%03d%s', $modeloPrefijo, $i, $i === 100 ? '-OBJETIVO' : ''));
        $modeloLote->execute();
    }
    $modeloObjetivo = sprintf('%s-100-OBJETIVO', $modeloPrefijo);
    $modelosPrimera = callApi($kernel, 'GET', '/api/v1/modelos-neumatico?marca_id=' . $activaId . '&search=' . rawurlencode($modeloPrefijo) . '&limit=20&page=1&sort=nombre&order=asc', null, $adminToken);
    $modelosNombres = array_column($modelosPrimera['body']['data'] ?? [], 'nombre');
    $modeloHallado = callApi($kernel, 'GET', '/api/v1/modelos-neumatico?marca_id=' . $activaId . '&search=' . rawurlencode($modeloObjetivo) . '&limit=20&sort=nombre&order=asc', null, $adminToken);
    $modeloAjeno = callApi($kernel, 'GET', '/api/v1/modelos-neumatico?marca_id=' . $otraId . '&search=' . rawurlencode($modeloObjetivo) . '&limit=20', null, $adminToken);
    record(
        '79',
        'Búsqueda de modelo por marca más allá de la primera página',
        $modelosPrimera['status'] === 200
            && ($modelosPrimera['body']['total'] ?? 0) === 101
            && !in_array($modeloObjetivo, $modelosNombres, true)
            && ($modeloHallado['body']['total'] ?? 0) === 1
            && ($modeloAjeno['body']['total'] ?? 0) === 0,
        'total ' . ($modelosPrimera['body']['total'] ?? 0)
    );
} catch (Throwable $error) {
    $failed = true;
    echo 'EXCEPCION ' . $error->getMessage() . ' en ' . $error->getFile() . ':' . $error->getLine() . PHP_EOL;
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
    $headers = ['user-agent' => 'bloque3-test', 'x-client-ip' => '203.0.113.20'];
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
        'email' => 'b3.' . bin2hex(random_bytes(5)) . '@test.local',
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

/** @param array<string, mixed> $extra @return array<string, mixed> */
function neumatico(int $clienteId, int $modeloId, int $medidaId, string $codigo, array $extra = []): array
{
    return array_merge([
        'cliente_id' => $clienteId,
        'codigo' => $codigo,
        'modelo_id' => $modeloId,
        'medida_id' => $medidaId,
        'profundidad_minima_mm' => '3',
        'profundidad_inicial_mm' => '16',
    ], $extra);
}

/** @param array<string, mixed> $extra @return array<string, mixed> */
function edicion(int $modeloId, int $medidaId, array $extra = []): array
{
    return array_merge([
        'modelo_id' => $modeloId,
        'medida_id' => $medidaId,
        'profundidad_minima_mm' => '3',
        'profundidad_inicial_mm' => '16',
    ], $extra);
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
    $tables = ['usuarios', 'clientes', 'auditoria', 'usuario_clientes', 'marcas_neumatico', 'modelos_neumatico', 'medidas_neumatico', 'estados_neumatico', 'neumaticos', 'neumatico_estado_historial', 'neumatico_vidas', 'movimientos_neumatico'];
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
