<?php

declare(strict_types=1);

namespace App\Validators;

use App\Exceptions\ValidationException;
use App\Support\Fields;

final class MedidaNeumaticoValidator
{
    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function crear(array $input): array
    {
        $errors = [];
        Fields::rejectLocked($input, $errors);
        $data = $this->comun($input, $errors);
        $data['activo'] = Fields::bool($input, 'activo', $errors, true, 'El indicador activo');
        $this->finish($errors);

        return $data;
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function actualizar(array $input): array
    {
        $errors = [];
        Fields::rejectLocked($input, $errors);
        if (array_key_exists('activo', $input)) {
            $errors['activo'][] = 'El estado se modifica en su propio endpoint.';
        }
        $data = $this->comun($input, $errors);
        $this->finish($errors);

        return $data;
    }

    /** @param array<string, mixed> $input */
    public function activo(array $input): bool
    {
        $errors = [];
        if (!array_key_exists('activo', $input)) {
            $errors['activo'][] = 'El indicador activo es obligatorio.';
        }
        $value = Fields::bool($input, 'activo', $errors, false, 'El indicador activo');
        $this->finish($errors);

        return $value;
    }

    /** @param array<string, mixed> $input @param array<string, list<string>> $errors @return array<string, mixed> */
    private function comun(array $input, array &$errors): array
    {
        return [
            'descripcion' => Fields::requiredText($input, 'descripcion', 80, $errors, 'La descripción'),
            'ancho' => $this->decimal($input, 'ancho', $errors, 'El ancho'),
            'perfil' => $this->decimal($input, 'perfil', $errors, 'El perfil'),
            'construccion' => Fields::optionalText($input, 'construccion', 10, $errors, 'La construcción'),
            'diametro' => $this->decimal($input, 'diametro', $errors, 'El diámetro'),
        ];
    }

    /** @param array<string, mixed> $input @param array<string, list<string>> $errors */
    private function decimal(array $input, string $field, array &$errors, string $label): ?string
    {
        if (!array_key_exists($field, $input) || $input[$field] === null || $input[$field] === '') {
            return null;
        }
        $raw = $input[$field];
        if (is_int($raw) && $raw >= 0) {
            return (string) $raw;
        }
        if (is_float($raw) && $raw >= 0) {
            $text = rtrim(rtrim(number_format($raw, 2, '.', ''), '0'), '.');
        } else {
            $text = trim((string) $raw);
        }
        if (preg_match('/^\d{1,6}(\.\d{1,2})?$/', $text) !== 1) {
            $errors[$field][] = $label . ' debe ser un número mayor o igual que cero, con hasta dos decimales.';
            return null;
        }

        return $text;
    }

    /** @param array<string, list<string>> $errors */
    private function finish(array $errors): void
    {
        if ($errors !== []) {
            throw new ValidationException('Los datos enviados no son válidos.', $errors);
        }
    }
}
