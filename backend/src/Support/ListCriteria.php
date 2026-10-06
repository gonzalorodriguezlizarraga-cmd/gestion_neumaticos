<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\ValidationException;

final class ListCriteria
{
    /**
     * @param array<string, string> $sortMap
     */
    private function __construct(
        public readonly int $page,
        public readonly int $limit,
        public readonly int $offset,
        public readonly string $sortExpression,
        public readonly string $direction,
        public readonly ?string $search,
        public readonly ?string $estado,
        public readonly ?int $activo,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, string> $sortMap
     * @param list<string>|null $estados
     */
    public static function from(
        array $query,
        array $sortMap,
        ?array $estados = null,
        bool $allowActivo = false,
        bool $allowSearch = false,
    ): self {
        $errors = [];
        $page = self::positiveInt($query['page'] ?? 1, 'page', $errors, 100000);
        $limit = self::positiveInt($query['limit'] ?? 20, 'limit', $errors, 100);
        $sortKey = isset($query['sort']) && $query['sort'] !== '' ? (string) $query['sort'] : array_key_first($sortMap);
        if (!is_string($sortKey) || !isset($sortMap[$sortKey])) {
            $errors['sort'][] = 'El ordenamiento solicitado no es válido.';
            $sortKey = array_key_first($sortMap);
        }
        $direction = strtolower((string) ($query['order'] ?? 'asc'));
        if (!in_array($direction, ['asc', 'desc'], true)) {
            $errors['order'][] = 'El orden debe ser asc o desc.';
            $direction = 'asc';
        }

        $search = null;
        if (self::provided($query, 'search')) {
            if (!$allowSearch) {
                $errors['search'][] = 'Este listado no admite búsqueda.';
            } else {
                $search = trim((string) $query['search']);
                if (mb_strlen($search) > 100) {
                    $errors['search'][] = 'La búsqueda admite hasta 100 caracteres.';
                }
            }
        }

        $estado = null;
        if (self::provided($query, 'estado')) {
            if ($estados === null) {
                $errors['estado'][] = 'Este listado no admite filtro por estado.';
            } else {
                $estado = strtoupper(trim((string) $query['estado']));
                if (!in_array($estado, $estados, true)) {
                    $errors['estado'][] = 'El estado no es válido.';
                }
            }
        }

        $activo = null;
        if (self::provided($query, 'activo')) {
            if (!$allowActivo) {
                $errors['activo'][] = 'Este listado no admite filtro por activo.';
            } elseif (!in_array((string) $query['activo'], ['0', '1'], true)) {
                $errors['activo'][] = 'El indicador activo no es válido.';
            } else {
                $activo = (int) $query['activo'];
            }
        }

        if ($errors !== []) {
            throw new ValidationException('Los datos enviados no son válidos.', $errors);
        }

        $page = $page ?? 1;
        $limit = $limit ?? 20;

        return new self(
            $page,
            $limit,
            ($page - 1) * $limit,
            $sortMap[$sortKey],
            $direction,
            $search,
            $estado,
            $activo,
        );
    }

    /** @param array<string, mixed> $query */
    private static function provided(array $query, string $field): bool
    {
        return isset($query[$field]) && trim((string) $query[$field]) !== '';
    }

    /** @param array<string, list<string>> $errors */
    private static function positiveInt(mixed $value, string $field, array &$errors, int $max): ?int
    {
        if (is_int($value) && $value >= 1 && $value <= $max) {
            return $value;
        }
        $text = trim((string) $value);
        if (!preg_match('/^[1-9][0-9]*$/', $text)) {
            $errors[$field][] = 'Debe ser un entero positivo.';
            return null;
        }
        $number = (int) $text;
        if ($number > $max) {
            $errors[$field][] = 'Supera el máximo permitido (' . $max . ').';
            return null;
        }

        return $number;
    }

    public function likePattern(): ?string
    {
        if ($this->search === null) {
            return null;
        }
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $this->search);

        return '%' . $escaped . '%';
    }
}
