<?php

declare(strict_types=1);

namespace App\Validators;

use App\Exceptions\ValidationException;
use App\Support\Fields;

final class MarcaNeumaticoValidator
{
    /** @param array<string, mixed> $input @return array{nombre:string,activo:bool} */
    public function crear(array $input): array
    {
        $errors = [];
        Fields::rejectLocked($input, $errors);
        $data = [
            'nombre' => Fields::requiredText($input, 'nombre', 120, $errors, 'El nombre'),
            'activo' => Fields::bool($input, 'activo', $errors, true, 'El indicador activo'),
        ];
        $this->finish($errors);

        return $data;
    }

    /** @param array<string, mixed> $input @return array{nombre:string} */
    public function actualizar(array $input): array
    {
        $errors = [];
        Fields::rejectLocked($input, $errors);
        if (array_key_exists('activo', $input)) {
            $errors['activo'][] = 'El estado se modifica en su propio endpoint.';
        }
        $data = ['nombre' => Fields::requiredText($input, 'nombre', 120, $errors, 'El nombre')];
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
    private function finish(array $errors): void
    {
        if ($errors !== []) {
            throw new ValidationException('Los datos enviados no son válidos.', $errors);
        }
    }
}
