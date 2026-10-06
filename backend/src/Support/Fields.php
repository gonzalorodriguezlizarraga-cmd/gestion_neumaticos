<?php

declare(strict_types=1);

namespace App\Support;

final class Fields
{
    /** @param array<string, list<string>> $errors */
    public static function requiredText(array $input, string $field, int $max, array &$errors, string $label): ?string
    {
        $value = isset($input[$field]) ? trim((string) $input[$field]) : '';
        if ($value === '') {
            $errors[$field][] = $label . ' es obligatorio.';
            return null;
        }
        if (mb_strlen($value) > $max) {
            $errors[$field][] = $label . ' admite hasta ' . $max . ' caracteres.';
            return null;
        }

        return $value;
    }

    /** @param array<string, list<string>> $errors */
    public static function optionalText(array $input, string $field, int $max, array &$errors, string $label): ?string
    {
        if (!array_key_exists($field, $input) || $input[$field] === null) {
            return null;
        }
        $value = trim((string) $input[$field]);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $max) {
            $errors[$field][] = $label . ' admite hasta ' . $max . ' caracteres.';
            return null;
        }

        return $value;
    }

    /** @param array<string, list<string>> $errors */
    public static function optionalEmail(array $input, string $field, array &$errors): ?string
    {
        $email = self::optionalText($input, $field, 190, $errors, 'El correo');
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[$field][] = 'El correo no es válido.';
            return null;
        }

        return $email;
    }

    /** @param array<string, list<string>> $errors */
    public static function optionalDate(array $input, string $field, array &$errors, string $label): ?string
    {
        if (!array_key_exists($field, $input) || $input[$field] === null || trim((string) $input[$field]) === '') {
            return null;
        }
        $value = trim((string) $input[$field]);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $valid = $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value;
        if (!$valid) {
            $errors[$field][] = $label . ' debe tener formato AAAA-MM-DD.';
            return null;
        }

        return $value;
    }

    /** @param array<string, list<string>> $errors */
    public static function requiredDate(array $input, string $field, array &$errors, string $label): ?string
    {
        if (!array_key_exists($field, $input) || trim((string) $input[$field]) === '') {
            $errors[$field][] = $label . ' es obligatoria.';
            return null;
        }

        return self::optionalDate($input, $field, $errors, $label);
    }

    /** @param array<string, list<string>> $errors */
    public static function bool(array $input, string $field, array &$errors, bool $default, string $label): bool
    {
        if (!array_key_exists($field, $input) || $input[$field] === null || $input[$field] === '') {
            return $default;
        }
        $value = $input[$field];
        if (is_bool($value)) {
            return $value;
        }
        if (in_array($value, [1, 0, '1', '0'], true)) {
            return (int) $value === 1;
        }
        $errors[$field][] = $label . ' debe ser verdadero o falso.';

        return $default;
    }

    /** @param array<string, list<string>> $errors */
    public static function rejectLocked(array $input, array &$errors): void
    {
        foreach ([
            'id',
            'creado_en',
            'creado_por',
            'actualizado_en',
            'actualizado_por',
            'eliminado',
            'eliminado_en',
            'eliminado_por',
            'logo_ruta',
        ] as $field) {
            if (array_key_exists($field, $input)) {
                $errors[$field][] = 'Este campo no se puede modificar.';
            }
        }
    }
}
