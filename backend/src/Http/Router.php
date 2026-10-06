<?php

declare(strict_types=1);

namespace App\Http;

final class Router
{
    /** @var list<array{method:string,regex:string,handler:callable,auth:bool,roles:list<string>,clientParam:?string}> */
    private array $routes = [];

    /** @param list<string> $roles */
    public function get(string $path, callable $handler, bool $auth = false, array $roles = [], ?string $clientParam = null): void
    {
        $this->add('GET', $path, $handler, $auth, $roles, $clientParam);
    }

    /** @param list<string> $roles */
    public function post(string $path, callable $handler, bool $auth = false, array $roles = [], ?string $clientParam = null): void
    {
        $this->add('POST', $path, $handler, $auth, $roles, $clientParam);
    }

    /** @param list<string> $roles */
    public function add(
        string $method,
        string $path,
        callable $handler,
        bool $auth = false,
        array $roles = [],
        ?string $clientParam = null,
    ): void {
        $pattern = preg_replace('#\{([A-Za-z_][A-Za-z0-9_]*)\}#', '(?P<$1>[^/]+)', $path);
        $this->routes[] = [
            'method' => strtoupper($method),
            'regex' => '#^' . $pattern . '$#',
            'handler' => $handler,
            'auth' => $auth,
            'roles' => array_values($roles),
            'clientParam' => $clientParam,
        ];
    }

    /** @return array{handler:callable,params:array<string,string>,auth:bool,roles:list<string>,clientParam:?string}|null */
    public function match(string $method, string $path): ?array
    {
        $method = strtoupper($method);
        $samePath = null;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }
            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = (string) $value;
                }
            }
            $found = [
                'handler' => $route['handler'],
                'params' => $params,
                'auth' => $route['auth'],
                'roles' => $route['roles'],
                'clientParam' => $route['clientParam'],
            ];
            if ($route['method'] === $method) {
                return $found;
            }
            $samePath ??= $found;
        }

        if ($method === 'OPTIONS' && $samePath !== null) {
            return $samePath;
        }

        return null;
    }
}
