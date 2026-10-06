<?php

declare(strict_types=1);

namespace App\Validators;

use App\Exceptions\ValidationException;
use App\Support\Fields;

final class SeguimientoValidator
{
    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function crear(array $input): array
    {
        if (array_key_exists('usuario_id', $input)) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'usuario_id' => ['El seguimiento queda a nombre del usuario autenticado.'],
            ]);
        }
        $errors = [];
        $cliente = $this->id($input, 'cliente_id', true, $errors, 'El cliente');
        $oportunidad = $this->id($input, 'oportunidad_id', false, $errors, 'La oportunidad');
        $tipo = strtoupper(trim((string) ($input['tipo'] ?? '')));
        if (!in_array($tipo, ['LLAMADA', 'VISITA', 'REUNION', 'CORREO', 'OTRO'], true)) {
            $errors['tipo'][] = 'El tipo de seguimiento no es válido.';
            $tipo = '';
        }
        $fecha = $this->fechaHora($input, 'fecha', true, $errors, 'La fecha');
        $proximo = $this->fechaHora($input, 'proximo_seguimiento', false, $errors, 'El próximo seguimiento');
        if ($fecha !== null && $proximo !== null && $proximo < $fecha) {
            $errors['proximo_seguimiento'][] = 'El próximo seguimiento no puede ser anterior a la fecha del seguimiento.';
        }
        $resultado = Fields::optionalText($input, 'resultado', 4000, $errors, 'El resultado');
        $observacion = Fields::optionalText($input, 'observacion', 4000, $errors, 'La observación');
        if ($errors !== []) {
            throw new ValidationException('Los datos enviados no son válidos.', $errors);
        }

        return [
            'cliente_id' => (int) $cliente,
            'oportunidad_id' => $oportunidad,
            'tipo' => $tipo,
            'fecha' => (string) $fecha,
            'resultado' => $resultado,
            'proximo_seguimiento' => $proximo,
            'observacion' => $observacion,
        ];
    }

    /** @param array<string, mixed> $input @param array<string, list<string>> $errors */
    private function id(array $input, string $field, bool $obligatorio, array &$errors, string $label): ?int
    {
        if (!$obligatorio && (!array_key_exists($field, $input) || $input[$field] === null || $input[$field] === '')) {
            return null;
        }
        $texto = trim((string) ($input[$field] ?? ''));
        if (preg_match('/^[1-9][0-9]*$/', $texto) !== 1) {
            $errors[$field][] = $label . ($obligatorio ? ' es obligatorio.' : ' no es válido.');

            return null;
        }

        return (int) $texto;
    }

    /** @param array<string, mixed> $input @param array<string, list<string>> $errors */
    private function fechaHora(array $input, string $field, bool $obligatorio, array &$errors, string $label): ?string
    {
        if (!$obligatorio && (!array_key_exists($field, $input) || $input[$field] === null || trim((string) $input[$field]) === '')) {
            return null;
        }
        $texto = trim((string) ($input[$field] ?? ''));
        if ($texto === '') {
            $errors[$field][] = $label . ' es obligatoria.';

            return null;
        }
        $normalizada = str_replace('T', ' ', $texto);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $normalizada) === 1) {
            $normalizada .= ' 00:00:00';
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $normalizada) === 1) {
            $normalizada .= ':00';
        }
        $fecha = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $normalizada);
        if (!$fecha instanceof \DateTimeImmutable || $fecha->format('Y-m-d H:i:s') !== $normalizada) {
            $errors[$field][] = $label . ' no es válida.';

            return null;
        }

        return $normalizada;
    }
}
