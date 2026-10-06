<?php

declare(strict_types=1);

namespace App\Validators;

use App\Exceptions\ValidationException;
use App\Support\Fields;

final class OrganizacionValidator
{
    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function sede(array $input): array
    {
        return $this->base($input, 'direccion', 255, 'La dirección');
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function flota(array $input): array
    {
        return $this->base($input, 'descripcion', 255, 'La descripción');
    }

    /** @param array<string, mixed> $input */
    public function activo(array $input): bool
    {
        if (!array_key_exists('activo', $input)) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'activo' => ['El indicador activo es obligatorio.'],
            ]);
        }
        $errors = [];
        $value = Fields::bool($input, 'activo', $errors, true, 'El indicador activo');
        if ($errors !== []) {
            throw new ValidationException('Los datos enviados no son válidos.', $errors);
        }

        return $value;
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function base(array $input, string $extra, int $extraMax, string $extraLabel): array
    {
        $errors = [];
        $nombre = Fields::requiredText($input, 'nombre', 160, $errors, 'El nombre');
        $codigo = Fields::optionalText($input, 'codigo', 60, $errors, 'El código');
        if ($codigo !== null && preg_match('/^[A-Za-z0-9._\-]+$/', $codigo) !== 1) {
            $errors['codigo'][] = 'El código contiene caracteres no permitidos.';
        }
        $data = [
            'nombre' => $nombre,
            'codigo' => $codigo,
            $extra => Fields::optionalText($input, $extra, $extraMax, $errors, $extraLabel),
            'activo' => Fields::bool($input, 'activo', $errors, true, 'El indicador activo'),
        ];
        if ($errors !== []) {
            throw new ValidationException('Los datos enviados no son válidos.', $errors);
        }

        return $data;
    }
}
