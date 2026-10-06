<?php

declare(strict_types=1);

namespace App\Routes;

use App\Controllers\AlertaController;
use App\Controllers\ArchivoController;
use App\Controllers\AuthController;
use App\Controllers\ClienteContactoController;
use App\Controllers\ClienteController;
use App\Controllers\ClienteResponsableController;
use App\Controllers\ConfiguracionUnidadController;
use App\Controllers\EstadoNeumaticoController;
use App\Controllers\IndicadorController;
use App\Controllers\InspeccionController;
use App\Controllers\MantenimientoController;
use App\Controllers\MarcaNeumaticoController;
use App\Controllers\MedidaNeumaticoController;
use App\Controllers\ModeloNeumaticoController;
use App\Controllers\NeumaticoController;
use App\Controllers\OperacionNeumaticoController;
use App\Controllers\OrganizacionController;
use App\Controllers\TipoUnidadController;
use App\Controllers\UnidadController;
use App\Http\ApiResponse;
use App\Http\Request;
use App\Http\Router;
use App\Services\ClientePolicy;

final class ApiRoutes
{
    public static function register(
        Router $router,
        AuthController $auth,
        ClienteController $clientes,
        ClienteContactoController $contactos,
        ClienteResponsableController $responsables,
        OrganizacionController $sedes,
        OrganizacionController $flotas,
        TipoUnidadController $tipos,
        ConfiguracionUnidadController $configuraciones,
        UnidadController $unidades,
        MarcaNeumaticoController $marcas,
        ModeloNeumaticoController $modelos,
        MedidaNeumaticoController $medidas,
        EstadoNeumaticoController $estados,
        NeumaticoController $neumaticos,
        OperacionNeumaticoController $operaciones,
        InspeccionController $inspecciones,
        MantenimientoController $mantenimientos,
        ArchivoController $archivos,
        AlertaController $alertas,
        IndicadorController $indicadores,
    ): void {
        $router->post('/api/v1/auth/login', static fn (Request $request): ApiResponse => $auth->login($request));
        $router->get('/api/v1/auth/me', static fn (Request $request): ApiResponse => $auth->me($request), true);
        $router->post('/api/v1/auth/logout', static fn (): ApiResponse => $auth->logout(), true);

        $lectura = ClientePolicy::LECTURA;
        $operacion = ClientePolicy::OPERACION;
        $inspeccion = ClientePolicy::INSPECCION;
        $admin = ['ADMIN_GENERAL'];

        $router->get('/api/v1/clientes', static fn (Request $request): ApiResponse => $clientes->index($request), true, $lectura);
        $router->post('/api/v1/clientes', static fn (Request $request): ApiResponse => $clientes->store($request), true, $admin);
        $router->get('/api/v1/clientes/{id}', static fn (Request $request): ApiResponse => $clientes->show($request), true, $lectura, 'id');
        $router->put('/api/v1/clientes/{id}', static fn (Request $request): ApiResponse => $clientes->update($request), true, $operacion, 'id');
        $router->patch('/api/v1/clientes/{id}/estado', static fn (Request $request): ApiResponse => $clientes->estado($request), true, $admin, 'id');
        $router->delete('/api/v1/clientes/{id}', static fn (Request $request): ApiResponse => $clientes->destroy($request), true, $admin, 'id');

        self::anidar($router, 'contactos', $contactos, $lectura, $operacion);
        self::responsables($router, $responsables, $lectura, $admin);
        self::organizacion($router, 'sedes', $sedes, $lectura, $operacion);
        self::organizacion($router, 'flotas', $flotas, $lectura, $operacion);

        $router->get('/api/v1/tipos-unidad', static fn (Request $request): ApiResponse => $tipos->index($request), true, $lectura);
        $router->post('/api/v1/tipos-unidad', static fn (Request $request): ApiResponse => $tipos->store($request), true, $admin);
        $router->get('/api/v1/tipos-unidad/{id}', static fn (Request $request): ApiResponse => $tipos->show($request), true, $lectura);
        $router->put('/api/v1/tipos-unidad/{id}', static fn (Request $request): ApiResponse => $tipos->update($request), true, $admin);
        $router->patch('/api/v1/tipos-unidad/{id}/estado', static fn (Request $request): ApiResponse => $tipos->estado($request), true, $admin);

        $router->get('/api/v1/configuraciones-unidad', static fn (Request $request): ApiResponse => $configuraciones->index($request), true, $lectura);
        $router->post('/api/v1/configuraciones-unidad', static fn (Request $request): ApiResponse => $configuraciones->store($request), true, $operacion);
        $router->get('/api/v1/configuraciones-unidad/{id}', static fn (Request $request): ApiResponse => $configuraciones->show($request), true, $lectura);
        $router->put('/api/v1/configuraciones-unidad/{id}', static fn (Request $request): ApiResponse => $configuraciones->update($request), true, $operacion);
        $router->post('/api/v1/configuraciones-unidad/{id}/duplicar', static fn (Request $request): ApiResponse => $configuraciones->duplicar($request), true, $operacion);
        $router->patch('/api/v1/configuraciones-unidad/{id}/estado', static fn (Request $request): ApiResponse => $configuraciones->estado($request), true, $operacion);

        $router->get('/api/v1/unidades', static fn (Request $request): ApiResponse => $unidades->index($request), true, $lectura);
        $router->post('/api/v1/unidades', static fn (Request $request): ApiResponse => $unidades->store($request), true, $operacion);
        $router->get('/api/v1/unidades/{id}/inspecciones', static fn (Request $request): ApiResponse => $inspecciones->deUnidad($request), true, $lectura);
        $router->get('/api/v1/unidades/{id}/montajes-activos', static fn (Request $request): ApiResponse => $operaciones->montajesActivos($request), true, $lectura);
        $router->get('/api/v1/unidades/{id}/movimientos', static fn (Request $request): ApiResponse => $operaciones->movimientosUnidad($request), true, $lectura);
        $router->get('/api/v1/unidades/{id}', static fn (Request $request): ApiResponse => $unidades->show($request), true, $lectura);
        $router->put('/api/v1/unidades/{id}', static fn (Request $request): ApiResponse => $unidades->update($request), true, $operacion);
        $router->patch('/api/v1/unidades/{id}/estado', static fn (Request $request): ApiResponse => $unidades->estado($request), true, $operacion);
        $router->delete('/api/v1/unidades/{id}', static fn (Request $request): ApiResponse => $unidades->destroy($request), true, $operacion);

        $router->get('/api/v1/marcas-neumatico', static fn (Request $request): ApiResponse => $marcas->index($request), true, $lectura);
        $router->post('/api/v1/marcas-neumatico', static fn (Request $request): ApiResponse => $marcas->store($request), true, $operacion);
        $router->get('/api/v1/marcas-neumatico/{id}', static fn (Request $request): ApiResponse => $marcas->show($request), true, $lectura);
        $router->put('/api/v1/marcas-neumatico/{id}', static fn (Request $request): ApiResponse => $marcas->update($request), true, $operacion);
        $router->patch('/api/v1/marcas-neumatico/{id}/estado', static fn (Request $request): ApiResponse => $marcas->estado($request), true, $operacion);

        $router->get('/api/v1/modelos-neumatico', static fn (Request $request): ApiResponse => $modelos->index($request), true, $lectura);
        $router->post('/api/v1/modelos-neumatico', static fn (Request $request): ApiResponse => $modelos->store($request), true, $operacion);
        $router->get('/api/v1/modelos-neumatico/{id}', static fn (Request $request): ApiResponse => $modelos->show($request), true, $lectura);
        $router->put('/api/v1/modelos-neumatico/{id}', static fn (Request $request): ApiResponse => $modelos->update($request), true, $operacion);
        $router->patch('/api/v1/modelos-neumatico/{id}/estado', static fn (Request $request): ApiResponse => $modelos->estado($request), true, $operacion);

        $router->get('/api/v1/medidas-neumatico', static fn (Request $request): ApiResponse => $medidas->index($request), true, $lectura);
        $router->post('/api/v1/medidas-neumatico', static fn (Request $request): ApiResponse => $medidas->store($request), true, $operacion);
        $router->get('/api/v1/medidas-neumatico/{id}', static fn (Request $request): ApiResponse => $medidas->show($request), true, $lectura);
        $router->put('/api/v1/medidas-neumatico/{id}', static fn (Request $request): ApiResponse => $medidas->update($request), true, $operacion);
        $router->patch('/api/v1/medidas-neumatico/{id}/estado', static fn (Request $request): ApiResponse => $medidas->estado($request), true, $operacion);

        $router->get('/api/v1/estados-neumatico', static fn (Request $request): ApiResponse => $estados->index($request), true, $lectura);

        $router->get('/api/v1/neumaticos', static fn (Request $request): ApiResponse => $neumaticos->index($request), true, $lectura);
        $router->post('/api/v1/neumaticos', static fn (Request $request): ApiResponse => $neumaticos->store($request), true, $operacion);
        $router->post('/api/v1/montajes', static fn (Request $request): ApiResponse => $operaciones->montar($request), true, $operacion);
        $router->post('/api/v1/montajes/{id}/desmontar', static fn (Request $request): ApiResponse => $operaciones->desmontar($request), true, $operacion);
        $router->post('/api/v1/rotaciones', static fn (Request $request): ApiResponse => $operaciones->rotar($request), true, $operacion);
        $router->post('/api/v1/transferencias', static fn (Request $request): ApiResponse => $operaciones->transferir($request), true, $operacion);
        $router->get('/api/v1/movimientos-neumatico', static fn (Request $request): ApiResponse => $operaciones->movimientos($request), true, $lectura);

        $router->get('/api/v1/tipos-dano', static fn (Request $request): ApiResponse => $inspecciones->tiposDano($request), true, $lectura);
        $router->get('/api/v1/inspecciones', static fn (Request $request): ApiResponse => $inspecciones->index($request), true, $lectura);
        $router->post('/api/v1/inspecciones', static fn (Request $request): ApiResponse => $inspecciones->store($request), true, $inspeccion);
        $router->post('/api/v1/inspecciones/{id}/finalizar', static fn (Request $request): ApiResponse => $inspecciones->finalizar($request), true, $inspeccion);
        $router->post('/api/v1/inspecciones/{id}/detalles/{detalleId}/archivos', static fn (Request $request): ApiResponse => $inspecciones->storeArchivoDetalle($request), true, $inspeccion);
        $router->post('/api/v1/inspecciones/{id}/detalles/{detalleId}/danos', static fn (Request $request): ApiResponse => $inspecciones->storeDano($request), true, $inspeccion);
        $router->put('/api/v1/inspecciones/{id}/detalles/{detalleId}/danos/{tipoId}', static fn (Request $request): ApiResponse => $inspecciones->updateDano($request), true, $inspeccion);
        $router->delete('/api/v1/inspecciones/{id}/detalles/{detalleId}/danos/{tipoId}', static fn (Request $request): ApiResponse => $inspecciones->destroyDano($request), true, $inspeccion);
        $router->post('/api/v1/inspecciones/{id}/detalles', static fn (Request $request): ApiResponse => $inspecciones->storeDetalle($request), true, $inspeccion);
        $router->put('/api/v1/inspecciones/{id}/detalles/{detalleId}', static fn (Request $request): ApiResponse => $inspecciones->updateDetalle($request), true, $inspeccion);
        $router->post('/api/v1/inspecciones/{id}/archivos', static fn (Request $request): ApiResponse => $inspecciones->storeArchivo($request), true, $inspeccion);
        $router->get('/api/v1/inspecciones/{id}', static fn (Request $request): ApiResponse => $inspecciones->show($request), true, $lectura);
        $router->put('/api/v1/inspecciones/{id}', static fn (Request $request): ApiResponse => $inspecciones->update($request), true, $inspeccion);
        $router->get('/api/v1/archivos/{id}/download', static fn (Request $request): ApiResponse => $archivos->download($request), true, $lectura);
        $router->delete('/api/v1/archivos/{id}', static fn (Request $request): ApiResponse => $archivos->destroy($request), true, $inspeccion);
        $router->get('/api/v1/tipos-mantenimiento', static fn (Request $request): ApiResponse => $mantenimientos->tipos($request), true, $lectura);
        $router->get('/api/v1/estados-mantenimiento', static fn (Request $request): ApiResponse => $mantenimientos->estados($request), true, $lectura);
        $router->get('/api/v1/motivos-descarte', static fn (Request $request): ApiResponse => $mantenimientos->motivos($request), true, $lectura);
        $router->get('/api/v1/mantenimientos', static fn (Request $request): ApiResponse => $mantenimientos->index($request), true, $lectura);
        $router->post('/api/v1/mantenimientos', static fn (Request $request): ApiResponse => $mantenimientos->store($request), true, $operacion);
        $router->post('/api/v1/mantenimientos/{id}/enviar', static fn (Request $request): ApiResponse => $mantenimientos->enviar($request), true, $operacion);
        $router->post('/api/v1/mantenimientos/{id}/iniciar', static fn (Request $request): ApiResponse => $mantenimientos->iniciar($request), true, $operacion);
        $router->post('/api/v1/mantenimientos/{id}/finalizar', static fn (Request $request): ApiResponse => $mantenimientos->finalizar($request), true, $operacion);
        $router->post('/api/v1/mantenimientos/{id}/cancelar', static fn (Request $request): ApiResponse => $mantenimientos->cancelar($request), true, $operacion);
        $router->post('/api/v1/mantenimientos/{id}/archivos', static fn (Request $request): ApiResponse => $mantenimientos->storeArchivo($request), true, $operacion);
        $router->get('/api/v1/mantenimientos/{id}', static fn (Request $request): ApiResponse => $mantenimientos->show($request), true, $lectura);
        $router->post('/api/v1/neumaticos/{id}/descartar', static fn (Request $request): ApiResponse => $mantenimientos->descartar($request), true, $operacion);
        $router->get('/api/v1/neumaticos/{id}/descarte', static fn (Request $request): ApiResponse => $mantenimientos->descarte($request), true, $lectura);
        $router->get('/api/v1/neumaticos/{id}/mantenimientos', static fn (Request $request): ApiResponse => $mantenimientos->deNeumatico($request), true, $lectura);

        $router->get('/api/v1/tipos-alerta', static fn (Request $request): ApiResponse => $alertas->tipos($request), true, $lectura);
        $router->get('/api/v1/estados-alerta', static fn (Request $request): ApiResponse => $alertas->estados($request), true, $lectura);
        $router->get('/api/v1/alertas', static fn (Request $request): ApiResponse => $alertas->index($request), true, $lectura);
        $router->post('/api/v1/alertas', static fn (Request $request): ApiResponse => $alertas->store($request), true, $operacion);
        $router->post('/api/v1/alertas/{id}/tomar-atencion', static fn (Request $request): ApiResponse => $alertas->tomarAtencion($request), true, $operacion);
        $router->post('/api/v1/alertas/{id}/atender', static fn (Request $request): ApiResponse => $alertas->atender($request), true, $operacion);
        $router->post('/api/v1/alertas/{id}/descartar', static fn (Request $request): ApiResponse => $alertas->descartar($request), true, $operacion);
        $router->get('/api/v1/alertas/{id}/historial', static fn (Request $request): ApiResponse => $alertas->historial($request), true, $lectura);
        $router->get('/api/v1/alertas/{id}', static fn (Request $request): ApiResponse => $alertas->show($request), true, $lectura);
        $router->get('/api/v1/indicadores/operativos', static fn (Request $request): ApiResponse => $indicadores->operativos($request), true, $lectura);
        $router->get('/api/v1/neumaticos/{id}/indicadores', static fn (Request $request): ApiResponse => $indicadores->deNeumatico($request), true, $lectura);
        $router->get('/api/v1/neumaticos/{id}/historial-estados', static fn (Request $request): ApiResponse => $neumaticos->historial($request), true, $lectura);
        $router->get('/api/v1/neumaticos/{id}/inspecciones', static fn (Request $request): ApiResponse => $inspecciones->deNeumatico($request), true, $lectura);
        $router->get('/api/v1/neumaticos/{id}/montajes', static fn (Request $request): ApiResponse => $operaciones->montajesNeumatico($request), true, $lectura);
        $router->get('/api/v1/neumaticos/{id}/movimientos', static fn (Request $request): ApiResponse => $operaciones->movimientosNeumatico($request), true, $lectura);
        $router->get('/api/v1/neumaticos/{id}/vidas', static fn (Request $request): ApiResponse => $neumaticos->vidas($request), true, $lectura);
        $router->get('/api/v1/neumaticos/{id}', static fn (Request $request): ApiResponse => $neumaticos->show($request), true, $lectura);
        $router->put('/api/v1/neumaticos/{id}', static fn (Request $request): ApiResponse => $neumaticos->update($request), true, $operacion);
        $router->delete('/api/v1/neumaticos/{id}', static fn (Request $request): ApiResponse => $neumaticos->destroy($request), true, $operacion);
    }

    /** @param list<string> $lectura @param list<string> $escritura */
    private static function anidar(
        Router $router,
        string $segmento,
        ClienteContactoController $controller,
        array $lectura,
        array $escritura,
    ): void {
        $base = '/api/v1/clientes/{clienteId}/' . $segmento;
        $router->get($base, static fn (Request $request): ApiResponse => $controller->index($request), true, $lectura, 'clienteId');
        $router->post($base, static fn (Request $request): ApiResponse => $controller->store($request), true, $escritura, 'clienteId');
        $router->put($base . '/{id}', static fn (Request $request): ApiResponse => $controller->update($request), true, $escritura, 'clienteId');
        $router->patch($base . '/{id}/estado', static fn (Request $request): ApiResponse => $controller->estado($request), true, $escritura, 'clienteId');
    }

    /** @param list<string> $lectura @param list<string> $admin */
    private static function responsables(Router $router, ClienteResponsableController $controller, array $lectura, array $admin): void
    {
        $base = '/api/v1/clientes/{clienteId}/responsables';
        $router->get($base, static fn (Request $request): ApiResponse => $controller->index($request), true, $lectura, 'clienteId');
        $router->post($base, static fn (Request $request): ApiResponse => $controller->store($request), true, $admin, 'clienteId');
        $router->put($base . '/{id}', static fn (Request $request): ApiResponse => $controller->update($request), true, $admin, 'clienteId');
        $router->delete($base . '/{id}', static fn (Request $request): ApiResponse => $controller->destroy($request), true, $admin, 'clienteId');
    }

    /** @param list<string> $lectura @param list<string> $escritura */
    private static function organizacion(
        Router $router,
        string $segmento,
        OrganizacionController $controller,
        array $lectura,
        array $escritura,
    ): void {
        $base = '/api/v1/clientes/{clienteId}/' . $segmento;
        $router->get($base, static fn (Request $request): ApiResponse => $controller->index($request), true, $lectura, 'clienteId');
        $router->post($base, static fn (Request $request): ApiResponse => $controller->store($request), true, $escritura, 'clienteId');
        $router->put($base . '/{id}', static fn (Request $request): ApiResponse => $controller->update($request), true, $escritura, 'clienteId');
        $router->patch($base . '/{id}/estado', static fn (Request $request): ApiResponse => $controller->estado($request), true, $escritura, 'clienteId');
        $router->delete($base . '/{id}', static fn (Request $request): ApiResponse => $controller->destroy($request), true, $escritura, 'clienteId');
    }
}
