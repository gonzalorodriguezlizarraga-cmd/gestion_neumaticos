<?php

declare(strict_types=1);

namespace App\Http;

use App\Config\Config;
use App\Config\Database;
use App\Controllers\ArchivoController;
use App\Controllers\AuthController;
use App\Controllers\ClienteContactoController;
use App\Controllers\ClienteController;
use App\Controllers\ClienteResponsableController;
use App\Controllers\ConfiguracionUnidadController;
use App\Controllers\EstadoNeumaticoController;
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
use App\Exceptions\NotFoundException;
use App\Middleware\AuthenticationMiddleware;
use App\Middleware\AuthorizationMiddleware;
use App\Middleware\CorsMiddleware;
use App\Middleware\ErrorHandler;
use App\Repositories\AuditoriaRepository;
use App\Repositories\ClienteContactoRepository;
use App\Repositories\ClienteRepository;
use App\Repositories\ClienteResponsableRepository;
use App\Repositories\ClienteScopeRepository;
use App\Repositories\ConfiguracionUnidadRepository;
use App\Repositories\EstadoNeumaticoRepository;
use App\Repositories\InspeccionRepository;
use App\Repositories\MantenimientoRepository;
use App\Repositories\MarcaNeumaticoRepository;
use App\Repositories\MedidaNeumaticoRepository;
use App\Repositories\ModeloNeumaticoRepository;
use App\Repositories\NeumaticoRepository;
use App\Repositories\OperacionNeumaticoRepository;
use App\Repositories\OrganizacionRepository;
use App\Repositories\TipoUnidadRepository;
use App\Repositories\UnidadRepository;
use App\Repositories\RolRepository;
use App\Repositories\UsuarioRepository;
use App\Routes\ApiRoutes;
use App\Services\AuthService;
use App\Services\AuthorizationService;
use App\Services\ClienteContactoService;
use App\Services\ClientePolicy;
use App\Services\ClienteResponsableService;
use App\Services\ClienteService;
use App\Services\ConfiguracionUnidadService;
use App\Services\EstadoNeumaticoService;
use App\Services\InspeccionService;
use App\Services\MantenimientoService;
use App\Services\MarcaNeumaticoService;
use App\Services\MedidaNeumaticoService;
use App\Services\ModeloNeumaticoService;
use App\Services\NeumaticoService;
use App\Services\OperacionNeumaticoService;
use App\Services\OrganizacionService;
use App\Services\TipoUnidadService;
use App\Services\UnidadService;
use App\Support\ArchivoAlmacen;
use App\Support\Jwt;
use App\Support\Transaction;
use App\Validators\ClienteValidator;
use App\Validators\ContactoValidator;
use App\Validators\LoginValidator;
use App\Validators\ConfiguracionUnidadValidator;
use App\Validators\InspeccionValidator;
use App\Validators\MantenimientoValidator;
use App\Validators\MarcaNeumaticoValidator;
use App\Validators\MedidaNeumaticoValidator;
use App\Validators\ModeloNeumaticoValidator;
use App\Validators\NeumaticoValidator;
use App\Validators\OperacionNeumaticoValidator;
use App\Validators\OrganizacionValidator;
use App\Validators\TipoUnidadValidator;
use App\Validators\UnidadValidator;
use App\Validators\ResponsableValidator;
use Throwable;

final class Kernel
{
    public function __construct(
        public readonly Router $router,
        private readonly AuthenticationMiddleware $authentication,
        private readonly AuthorizationMiddleware $authorization,
        private readonly ErrorHandler $errors,
        private readonly CorsMiddleware $cors,
        private readonly Jwt $jwt,
    ) {
    }

    public static function boot(string $basePath): self
    {
        $config = Config::fromEnvFile($basePath . DIRECTORY_SEPARATOR . '.env');
        $pdo = Database::connect($config);
        $usuarios = new UsuarioRepository($pdo);
        $roles = new RolRepository($pdo);
        $clientes = new ClienteScopeRepository($pdo);
        $jwt = new Jwt($config->get('JWT_SECRET'), $config->int('JWT_TTL', 3600));
        $authorization = new AuthorizationService($roles, $clientes);
        $auth = new AuthService($usuarios, $roles, $clientes, $authorization, $jwt);
        $policy = new ClientePolicy($authorization);
        $transaction = new Transaction($pdo);
        $auditoria = new AuditoriaRepository($pdo);
        $clienteRepository = new ClienteRepository($pdo);
        $clienteService = new ClienteService($clienteRepository, $auditoria, $policy, new ClienteValidator(), $transaction);
        $contactoService = new ClienteContactoService(
            $clienteService,
            new ClienteContactoRepository($pdo),
            $auditoria,
            $policy,
            new ContactoValidator(),
            $transaction,
        );
        $responsableService = new ClienteResponsableService(
            $clienteService,
            $clienteRepository,
            new ClienteResponsableRepository($pdo),
            $usuarios,
            $auditoria,
            $policy,
            $authorization,
            new ResponsableValidator(),
            $transaction,
        );
        $organizacionValidator = new OrganizacionValidator();
        $sedes = new OrganizacionService(
            $clienteService,
            OrganizacionRepository::sedes($pdo),
            $auditoria,
            $policy,
            $organizacionValidator,
            $transaction,
            'sedes',
            'SEDE',
            false,
        );
        $flotas = new OrganizacionService(
            $clienteService,
            OrganizacionRepository::flotas($pdo),
            $auditoria,
            $policy,
            $organizacionValidator,
            $transaction,
            'flotas',
            'FLOTA',
            true,
        );
        $tipoRepository = new TipoUnidadRepository($pdo);
        $configuracionRepository = new ConfiguracionUnidadRepository($pdo);
        $tipoService = new TipoUnidadService($tipoRepository, $auditoria, $authorization, new TipoUnidadValidator(), $transaction);
        $configuracionService = new ConfiguracionUnidadService($configuracionRepository, $auditoria, $authorization, new ConfiguracionUnidadValidator(), $transaction);
        $unidadService = new UnidadService(
            new UnidadRepository($pdo),
            $tipoRepository,
            $configuracionRepository,
            $auditoria,
            $authorization,
            new UnidadValidator(),
            $transaction,
        );
        $marcaRepository = new MarcaNeumaticoRepository($pdo);
        $modeloRepository = new ModeloNeumaticoRepository($pdo);
        $medidaRepository = new MedidaNeumaticoRepository($pdo);
        $estadoRepository = new EstadoNeumaticoRepository($pdo);
        $marcaService = new MarcaNeumaticoService($marcaRepository, $auditoria, $authorization, new MarcaNeumaticoValidator(), $transaction);
        $modeloService = new ModeloNeumaticoService($modeloRepository, $marcaRepository, $auditoria, $authorization, new ModeloNeumaticoValidator(), $transaction);
        $medidaService = new MedidaNeumaticoService($medidaRepository, $auditoria, $authorization, new MedidaNeumaticoValidator(), $transaction);
        $estadoService = new EstadoNeumaticoService($estadoRepository, $authorization);
        $neumaticoService = new NeumaticoService(
            new NeumaticoRepository($pdo),
            $modeloRepository,
            $medidaRepository,
            $estadoRepository,
            $marcaRepository,
            $auditoria,
            $authorization,
            new NeumaticoValidator(),
            $transaction,
        );
        $almacen = new ArchivoAlmacen($basePath . DIRECTORY_SEPARATOR . 'storage');
        $inspeccionService = new InspeccionService(
            new InspeccionRepository($pdo),
            $auditoria,
            $authorization,
            new InspeccionValidator(),
            $transaction,
            $almacen,
            $config->int('ARCHIVO_MAX_BYTES', 5242880),
        );
        $operacionRepository = new OperacionNeumaticoRepository($pdo);
        $operacionService = new OperacionNeumaticoService(
            $operacionRepository,
            $auditoria,
            $authorization,
            new OperacionNeumaticoValidator(),
            $transaction,
        );
        $mantenimientoService = new MantenimientoService(
            new MantenimientoRepository($pdo),
            $operacionRepository,
            new InspeccionRepository($pdo),
            $auditoria,
            $authorization,
            new MantenimientoValidator(),
            $transaction,
            $almacen,
            $config->int('ARCHIVO_MAX_BYTES', 5242880),
        );
        $router = new Router();
        ApiRoutes::register(
            $router,
            new AuthController($auth, new LoginValidator()),
            new ClienteController($clienteService),
            new ClienteContactoController($contactoService),
            new ClienteResponsableController($responsableService),
            new OrganizacionController($sedes),
            new OrganizacionController($flotas),
            new TipoUnidadController($tipoService),
            new ConfiguracionUnidadController($configuracionService),
            new UnidadController($unidadService),
            new MarcaNeumaticoController($marcaService),
            new ModeloNeumaticoController($modeloService),
            new MedidaNeumaticoController($medidaService),
            new EstadoNeumaticoController($estadoService),
            new NeumaticoController($neumaticoService),
            new OperacionNeumaticoController($operacionService),
            new InspeccionController($inspeccionService),
            new MantenimientoController($mantenimientoService),
            new ArchivoController($inspeccionService, $mantenimientoService),
        );

        return new self(
            $router,
            new AuthenticationMiddleware($auth),
            new AuthorizationMiddleware($authorization),
            new ErrorHandler($basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'app.log', $config->bool('APP_DEBUG')),
            new CorsMiddleware($config->csv('CORS_ALLOWED_ORIGINS')),
            $jwt,
        );
    }

    public function jwt(): Jwt
    {
        return $this->jwt;
    }

    public function run(): void
    {
        $this->handle(Request::fromGlobals())->send();
    }

    public function handle(Request $request): ApiResponse
    {
        try {
            $response = $this->dispatch($request);
        } catch (Throwable $error) {
            $response = $this->errors->handle($error);
        }

        $response = $response
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Referrer-Policy', 'no-referrer');

        foreach ($this->cors->headersFor($request) as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    private function dispatch(Request $request): ApiResponse
    {
        $match = $this->router->match($request->method(), $request->path());
        if ($match === null) {
            throw new NotFoundException('Recurso no encontrado.');
        }
        if ($request->method() === 'OPTIONS') {
            return ApiResponse::noContent();
        }

        $request->setRouteParams($match['params']);
        if ($match['auth']) {
            $this->authentication->handle($request);
            $this->authorization->enforce($request, $match['roles'], $match['clientParam']);
        } elseif ($match['roles'] !== [] || $match['clientParam'] !== null) {
            throw new \LogicException('La ruta exige autorización sin autenticación.');
        }

        return ($match['handler'])($request);
    }
}
