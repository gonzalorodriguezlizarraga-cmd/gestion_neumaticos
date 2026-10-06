<?php

declare(strict_types=1);

namespace App\Http;

final class ApiResponse
{
    /** @var array<string, string> */
    private array $headers;

    /** @param array<string, mixed>|null $error */
    private function __construct(
        private readonly int $status,
        private readonly mixed $data,
        private readonly int $total,
        private readonly ?array $error,
        array $headers = [],
        private readonly bool $binario = false,
    ) {
        $this->headers = $headers;
    }

    public static function ok(mixed $data, int $total = 1, int $status = 200): self
    {
        return new self($status, $data, $total, null);
    }

    /** @param array<string, list<string>>|null $details */
    public static function error(int $status, string $code, string $message, ?array $details = null): self
    {
        $error = [
            'code' => $code,
            'message' => $message,
        ];
        if ($details !== null) {
            $error['details'] = $details;
        }

        return new self($status, null, 0, $error);
    }

    public static function noContent(): self
    {
        return new self(204, null, 0, null);
    }

    public static function archivo(string $contenido, string $mime, string $nombre): self
    {
        $limpio = preg_replace('/[^A-Za-z0-9._-]/', '_', $nombre) ?: 'archivo';

        return new self(200, $contenido, 1, null, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="' . $limpio . '"',
        ], true);
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    /** @return array{data:mixed,total:int,status:int,err:array<string,mixed>|null} */
    public function toArray(): array
    {
        return [
            'data' => $this->data,
            'total' => $this->total,
            'status' => $this->status,
            'err' => $this->error,
        ];
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;

        return $clone;
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        if ($this->status === 204) {
            return;
        }
        if ($this->binario) {
            echo is_string($this->data) ? $this->data : '';

            return;
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            $this->toArray(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }
}
