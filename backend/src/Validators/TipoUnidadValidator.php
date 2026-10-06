<?php

declare(strict_types=1);

namespace App\Validators;

use App\Exceptions\ValidationException;
use App\Support\Fields;

final class TipoUnidadValidator
{
    /** @param array<string, mixed> $input @return array{codigo:string,nombre:string,descripcion:?string,activo:bool} */
    public function crear(array $input): array
    {
        $errors = [];
        Fields::rejectLocked($input, $errors);
        $data = [
            'codigo' => $this->codigo($input, $errors, true),
            'nombre' => Fields::requiredText($input, 'nombre', 120, $errors, 'El nombre'),
            'descripcion' => Fields::optionalText($input, 'descripcion', 255, $errors, 'La descripción'),
            'activo' => Fields::bool($input, 'activo', $errors, true, 'El indicador activo'),
        ];
        $this->finish($errors);

        return $data;
    }

    /** @param array<string, mixed> $input @return array{codigo:?string,nombre:string,descripcion:?string} */
    public function actualizar(array $input): array
    {
        $errors = [];
        Fields::rejectLocked($input, $errors);
        if (array_key_exists('activo', $input)) {
            $errors['activo'][] = 'El estado se modifica en su propio endpoint.';
        }
        $codigo = array_key_exists('codigo', $input) ? $this->codigo($input, $errors, true) : null;
        $data = [
            'codigo' => $codigo,
            'nombre' => Fields::requiredText($input, 'nombre', 120, $errors, 'El nombre'),
            'descripcion' => Fields::optionalText($input, 'descripcion', 255, $errors, 'La descripción'),
        ];
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

    /** @param array<string, list<string>> $errors */
    private function codigo(array $input, array &$errors, bool $required): ?string
    {
        $value = $required
            ? Fields::requiredText($input, 'codigo', 60, $errors, 'El código')
            : Fields::optionalText($input, 'codigo', 60, $errors, 'El código');
        if ($value !== null && preg_match('/^[A-Za-z0-9._\-]+$/', $value) !== 1) {
            $errors['codigo'][] = 'El código solo admite letras, números, punto, guion y guion bajo.';
            return null;
        }

        return $value;
    }

    /** @param array<string, list<string>> $errors */
    private function finish(array $errors): void
    {
        if ($errors !== []) {
            throw new ValidationException('Los datos enviados no son válidos.', $errors);
        }
    }
}
