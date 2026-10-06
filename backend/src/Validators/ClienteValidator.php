<?php

declare(strict_types=1);

namespace App\Validators;

use App\Exceptions\ValidationException;
use App\Support\Fields;

final class ClienteValidator
{
    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function crear(array $input): array
    {
        $errors = [];
        Fields::rejectLocked($input, $errors);
        $razon = Fields::requiredText($input, 'razon_social', 200, $errors, 'La razón social');
        $estado = strtoupper(trim((string) ($input['estado'] ?? '')));
        if (!in_array($estado, ['POTENCIAL', 'ACTIVO'], true)) {
            $errors['estado'][] = 'El estado inicial debe ser POTENCIAL o ACTIVO.';
        }
        $data = $this->opcionales($input, $errors);
        $data['razon_social'] = $razon;
        $data['estado'] = $estado;
        $this->throwIf($errors);

        return $data;
    }

    /**
     * Devuelve solo los campos presentes y permitidos.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function actualizar(array $input): array
    {
        $errors = [];
        Fields::rejectLocked($input, $errors);
        $data = [];
        if (array_key_exists('estado', $input)) {
            $errors['estado'][] = 'El estado se modifica en su propio endpoint.';
        }
        if (array_key_exists('razon_social', $input)) {
            $data['razon_social'] = Fields::requiredText($input, 'razon_social', 200, $errors, 'La razón social');
        }
        foreach (['nombre_comercial' => 200, 'direccion' => 255, 'telefono' => 40, 'observacion' => 65535] as $field => $max) {
            if (array_key_exists($field, $input)) {
                $label = match ($field) {
                    'nombre_comercial' => 'El nombre comercial',
                    'direccion' => 'La dirección',
                    'telefono' => 'El teléfono',
                    default => 'La observación',
                };
                $data[$field] = Fields::optionalText($input, $field, $max, $errors, $label);
            }
        }
        if (array_key_exists('email', $input)) {
            $data['email'] = Fields::optionalEmail($input, 'email', $errors);
        }
        if (array_key_exists('ruc_documento', $input)) {
            $data['ruc_documento'] = $this->ruc($input, $errors);
        }
        if (array_key_exists('fecha_inicio_servicio', $input)) {
            $data['fecha_inicio_servicio'] = Fields::optionalDate($input, 'fecha_inicio_servicio', $errors, 'La fecha de inicio');
        }
        $this->throwIf($errors);

        return $data;
    }

    /** @param array<string, mixed> $input */
    public function estado(array $input): string
    {
        $estado = strtoupper(trim((string) ($input['estado'] ?? '')));
        if (!in_array($estado, ['POTENCIAL', 'ACTIVO', 'INACTIVO'], true)) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'estado' => ['El estado no es válido.'],
            ]);
        }

        return $estado;
    }

    /** @param array<string, mixed> $input @param array<string, list<string>> $errors @return array<string, mixed> */
    private function opcionales(array $input, array &$errors): array
    {
        return [
            'nombre_comercial' => Fields::optionalText($input, 'nombre_comercial', 200, $errors, 'El nombre comercial'),
            'ruc_documento' => $this->ruc($input, $errors),
            'direccion' => Fields::optionalText($input, 'direccion', 255, $errors, 'La dirección'),
            'telefono' => Fields::optionalText($input, 'telefono', 40, $errors, 'El teléfono'),
            'email' => Fields::optionalEmail($input, 'email', $errors),
            'fecha_inicio_servicio' => Fields::optionalDate($input, 'fecha_inicio_servicio', $errors, 'La fecha de inicio'),
            'observacion' => Fields::optionalText($input, 'observacion', 65535, $errors, 'La observación'),
        ];
    }

    /** @param array<string, mixed> $input @param array<string, list<string>> $errors */
    private function ruc(array $input, array &$errors): ?string
    {
        $value = Fields::optionalText($input, 'ruc_documento', 30, $errors, 'El documento');
        if ($value !== null && preg_match('/^[A-Za-z0-9.\-]+$/', $value) !== 1) {
            $errors['ruc_documento'][] = 'El documento contiene caracteres no permitidos.';
            return null;
        }

        return $value;
    }

    /** @param array<string, list<string>> $errors */
    private function throwIf(array $errors): void
    {
        if ($errors !== []) {
            throw new ValidationException('Los datos enviados no son válidos.', $errors);
        }
    }
}
