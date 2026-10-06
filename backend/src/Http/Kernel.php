<?php

declare(strict_types=1);

namespace App\Http;

use App\Config\Config;
use App\Config\Database;
use App\Controllers\AuthController;
use App\Exceptions\NotFoundException;
use App\Middleware\AuthenticationMiddleware;
use App\Middleware\AuthorizationMiddleware;
use App\Middleware\CorsMiddleware;
use App\Middleware\ErrorHandler;
use App\Repositories\ClienteScopeRepository;
use App\Repositories\RolRepository;
use App\Repositories\UsuarioRepository;
use App\Routes\ApiRoutes;
use App\Services\AuthService;
use App\Services\AuthorizationService;
use App\Support\Jwt;
use App\Validators\LoginValidator;
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
        $controller = new AuthController($auth, new LoginValidator());
        $router = new Router();
        ApiRoutes::register($router, $controller);

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
