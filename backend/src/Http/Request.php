<?php

declare(strict_types=1);

namespace App\Http;

use App\Exceptions\ValidationException;

final class Request
{
    /** @var array<string, string> */
    private array $routeParams = [];

    /** @var array<string, mixed>|null */
    private ?array $user = null;

    /**
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     */
    private function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query,
        private readonly array $headers,
        private readonly ?string $rawBody,
    ) {
    }

    public static function fromGlobals(): self
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $base = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
        if ($base !== '' && $base !== '/' && $base !== '.' && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base)) ?: '/';
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE']) && is_string($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }
        $authorization = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? null;
        if (is_string($authorization) && $authorization !== '') {
            $headers['authorization'] = $authorization;
        }

        $raw = file_get_contents('php://input');

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $uri === '' ? '/' : $uri,
            $_GET,
            $headers,
            $raw === false ? null : $raw,
        );
    }

    /** @param array<string, string> $headers */
    public static function fake(string $method, string $path, array $headers = [], ?string $rawBody = null): self
    {
        $parts = parse_url($path);
        $pathOnly = $parts['path'] ?? '/';
        $query = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower($name)] = $value;
        }

        return new self(strtoupper($method), $pathOnly === '' ? '/' : $pathOnly, $query, $normalized, $rawBody);
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function header(string $name): ?string
    {
        $key = strtolower($name);

        return $this->headers[$key] ?? null;
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        if ($this->rawBody === null || trim($this->rawBody) === '') {
            return [];
        }

        $decoded = json_decode($this->rawBody, true);
        if (!is_array($decoded)) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'body' => ['El cuerpo debe ser un objeto JSON.'],
            ]);
        }

        return $decoded;
    }

    /** @param array<string, string> $params */
    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    public function route(string $name): ?string
    {
        return $this->routeParams[$name] ?? null;
    }

    /** @param array<string, mixed> $user */
    public function setUser(array $user): void
    {
        $this->user = $user;
    }

    public function userId(): int
    {
        if ($this->user === null || !isset($this->user['id'])) {
            throw new \LogicException('Usuario no autenticado.');
        }

        return (int) $this->user['id'];
    }
}
