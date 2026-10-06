<?php

declare(strict_types=1);

namespace App\Routes;

use App\Controllers\AuthController;
use App\Controllers\ClienteContactoController;
use App\Controllers\ClienteController;
use App\Controllers\ClienteResponsableController;
use App\Controllers\ConfiguracionUnidadController;
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
    ): void {
        $router->post('/api/v1/auth/login', static fn (Request $request): ApiResponse => $auth->login($request));
        $router->get('/api/v1/auth/me', static fn (Request $request): ApiResponse => $auth->me($request), true);
        $router->post('/api/v1/auth/logout', static fn (): ApiResponse => $auth->logout(), true);

        $lectura = ClientePolicy::LECTURA;
        $operacion = ClientePolicy::OPERACION;
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
        $router->get('/api/v1/unidades/{id}', static fn (Request $request): ApiResponse => $unidades->show($request), true, $lectura);
        $router->put('/api/v1/unidades/{id}', static fn (Request $request): ApiResponse => $unidades->update($request), true, $operacion);
        $router->patch('/api/v1/unidades/{id}/estado', static fn (Request $request): ApiResponse => $unidades->estado($request), true, $operacion);
        $router->delete('/api/v1/unidades/{id}', static fn (Request $request): ApiResponse => $unidades->destroy($request), true, $operacion);
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
