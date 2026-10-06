<?php

declare(strict_types=1);

namespace App\Validators;

use App\Exceptions\ValidationException;
use App\Support\Fields;

final class ContactoValidator
{
    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function guardar(array $input, bool $parcial = false): array
    {
        $errors = [];
        $nombre = null;
        if (!$parcial || array_key_exists('nombre', $input)) {
            $nombre = Fields::requiredText($input, 'nombre', 180, $errors, 'El nombre');
        }
        $data = [
            'cargo' => Fields::optionalText($input, 'cargo', 120, $errors, 'El cargo'),
            'telefono' => Fields::optionalText($input, 'telefono', 40, $errors, 'El teléfono'),
            'email' => Fields::optionalEmail($input, 'email', $errors),
            'tipo_contacto' => Fields::optionalText($input, 'tipo_contacto', 80, $errors, 'El tipo de contacto'),
            'principal' => Fields::bool($input, 'principal', $errors, false, 'El indicador principal'),
            'activo' => Fields::bool($input, 'activo', $errors, true, 'El indicador activo'),
        ];
        if ($nombre !== null || !$parcial) {
            $data['nombre'] = $nombre;
        }
        if ($errors !== []) {
            throw new ValidationException('Los datos enviados no son válidos.', $errors);
        }

        return $data;
    }

    /** @param array<string, mixed> $input */
    public function activo(array $input): bool
    {
        $errors = [];
        if (!array_key_exists('activo', $input)) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'activo' => ['El indicador activo es obligatorio.'],
            ]);
        }
        $value = Fields::bool($input, 'activo', $errors, false, 'El indicador activo');
        if ($errors !== []) {
            throw new ValidationException('Los datos enviados no son válidos.', $errors);
        }

        return $value;
    }
}
