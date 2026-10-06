<?php

declare(strict_types=1);

namespace App\Validators;

use App\Exceptions\ValidationException;
use App\Support\Fields;

final class ModeloNeumaticoValidator
{
    /** @param array<string, mixed> $input @return array{marca_id:int,nombre:string,descripcion:?string,activo:bool} */
    public function crear(array $input): array
    {
        $errors = [];
        Fields::rejectLocked($input, $errors);
        $data = $this->comun($input, $errors);
        $data['activo'] = Fields::bool($input, 'activo', $errors, true, 'El indicador activo');
        $this->finish($errors);

        return $data;
    }

    /** @param array<string, mixed> $input @return array{marca_id:int,nombre:string,descripcion:?string} */
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

    /** @param array<string, mixed> $input @param array<string, list<string>> $errors @return array{marca_id:int,nombre:string,descripcion:?string} */
    private function comun(array $input, array &$errors): array
    {
        return [
            'marca_id' => $this->id($input, $errors),
            'nombre' => Fields::requiredText($input, 'nombre', 140, $errors, 'El nombre'),
            'descripcion' => Fields::optionalText($input, 'descripcion', 255, $errors, 'La descripción'),
        ];
    }

    /** @param array<string, mixed> $input @param array<string, list<string>> $errors */
    private function id(array $input, array &$errors): int
    {
        $raw = $input['marca_id'] ?? null;
        $text = is_int($raw) ? (string) $raw : trim((string) $raw);
        if (preg_match('/^[1-9][0-9]*$/', $text) !== 1) {
            $errors['marca_id'][] = 'La marca es obligatoria.';
            return 0;
        }

        return (int) $text;
    }

    /** @param array<string, list<string>> $errors */
    private function finish(array $errors): void
    {
        if ($errors !== []) {
            throw new ValidationException('Los datos enviados no son válidos.', $errors);
        }
    }
}
