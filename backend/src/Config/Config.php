<?php

declare(strict_types=1);

namespace App\Config;

use RuntimeException;

final class Config
{
    /** @param array<string, string> $values */
    private function __construct(private array $values)
    {
    }

    public static function fromEnvFile(string $path): self
    {
        $values = [];
        if (is_file($path)) {
            $lines = file($path, FILE_IGNORE_NEW_LINES);
            if ($lines === false) {
                throw new RuntimeException('No se pudo leer el archivo de entorno.');
            }

            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                $separator = strpos($line, '=');
                if ($separator === false) {
                    continue;
                }
                $key = trim(substr($line, 0, $separator));
                $value = trim(substr($line, $separator + 1));
                if ($key === '') {
                    continue;
                }
                if (
                    (str_starts_with($value, '"') && str_ends_with($value, '"'))
                    || (str_starts_with($value, "'") && str_ends_with($value, "'"))
                ) {
                    $value = substr($value, 1, -1);
                }
                $values[$key] = $value;
            }
        }

        foreach (array_merge(array_keys($values), [
            'APP_ENV',
            'APP_DEBUG',
            'APP_URL',
            'APP_TIMEZONE',
            'DB_HOST',
            'DB_PORT',
            'DB_DATABASE',
            'DB_USERNAME',
            'DB_PASSWORD',
            'JWT_SECRET',
            'JWT_TTL',
            'CORS_ALLOWED_ORIGINS',
        ]) as $key) {
            $fromProcess = getenv($key);
            if ($fromProcess !== false) {
                $values[$key] = $fromProcess;
            }
        }

        return new self($values);
    }

    public function get(string $key, ?string $default = null): string
    {
        if (!array_key_exists($key, $this->values) || $this->values[$key] === '') {
            if ($default === null) {
                throw new RuntimeException('Falta la configuración ' . $key . '.');
            }
            return $default;
        }

        return $this->values[$key];
    }

    public function optional(string $key, string $default = ''): string
    {
        if (!array_key_exists($key, $this->values)) {
            return $default;
        }

        return $this->values[$key];
    }

    public function bool(string $key, bool $default = false): bool
    {
        if (!array_key_exists($key, $this->values) || $this->values[$key] === '') {
            return $default;
        }

        return in_array(strtolower($this->values[$key]), ['1', 'true', 'yes', 'on'], true);
    }

    public function int(string $key, int $default): int
    {
        if (!array_key_exists($key, $this->values) || $this->values[$key] === '') {
            return $default;
        }
        if (!preg_match('/^-?\d+$/', $this->values[$key])) {
            throw new RuntimeException('La configuración ' . $key . ' debe ser entera.');
        }

        return (int) $this->values[$key];
    }

    /** @return list<string> */
    public function csv(string $key): array
    {
        $raw = $this->optional($key, '');
        if ($raw === '') {
            return [];
        }

        $items = [];
        foreach (explode(',', $raw) as $item) {
            $item = trim($item);
            if ($item !== '') {
                $items[] = $item;
            }
        }

        return $items;
    }
}
