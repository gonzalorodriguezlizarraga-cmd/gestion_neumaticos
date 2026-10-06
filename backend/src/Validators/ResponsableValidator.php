<?php

declare(strict_types=1);

namespace App\Validators;

use App\Exceptions\ValidationException;
use App\Support\Fields;

final class ResponsableValidator
{
    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function guardar(array $input): array
    {
        $errors = [];
        $usuarioId = $input['usuario_id'] ?? null;
        if (!is_int($usuarioId) && !(is_string($usuarioId) && preg_match('/^[1-9][0-9]*$/', $usuarioId) === 1)) {
            $errors['usuario_id'][] = 'El usuario no es válido.';
            $usuarioId = 0;
        }
        $tipo = strtoupper(trim((string) ($input['tipo_responsabilidad'] ?? '')));
        if (!in_array($tipo, ['TECNICO', 'COMERCIAL'], true)) {
            $errors['tipo_responsabilidad'][] = 'El tipo debe ser TECNICO o COMERCIAL.';
        }
        $inicio = Fields::requiredDate($input, 'fecha_inicio', $errors, 'La fecha de inicio');
        $fin = Fields::optionalDate($input, 'fecha_fin', $errors, 'La fecha de fin');
        if ($inicio !== null && $fin !== null && $fin < $inicio) {
            $errors['fecha_fin'][] = 'La fecha de fin no puede ser anterior a la fecha de inicio.';
        }
        $principal = Fields::bool($input, 'principal', $errors, false, 'El indicador principal');
        if ($errors !== []) {
            throw new ValidationException('Los datos enviados no son válidos.', $errors);
        }

        return [
            'usuario_id' => (int) $usuarioId,
            'tipo_responsabilidad' => $tipo,
            'fecha_inicio' => $inicio,
            'fecha_fin' => $fin,
            'principal' => $principal,
        ];
    }
}
