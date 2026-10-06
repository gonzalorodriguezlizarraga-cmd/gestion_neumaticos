<?php

declare(strict_types=1);

namespace App\Validators;

use App\Exceptions\ValidationException;
use App\Support\Fields;

final class NeumaticoValidator
{
    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function crear(array $input): array
    {
        $errors = [];
        $this->rechazarInmutables($input, $errors, true);
        $data = $this->comun($input, $errors);
        $data['cliente_id'] = $this->id($input, 'cliente_id', $errors, 'El cliente', true);
        $data['codigo'] = $this->codigo($input, $errors);
        $this->coherencia($data, $errors);
        $this->finish($errors);

        return $data;
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function actualizar(array $input): array
    {
        $errors = [];
        $this->rechazarInmutables($input, $errors, false);
        $data = $this->comun($input, $errors);
        $this->coherencia($data, $errors);
        $this->finish($errors);

        return $data;
    }

    /** @param array<string, mixed> $input @param array<string, list<string>> $errors @return array<string, mixed> */
    private function comun(array $input, array &$errors): array
    {
        return [
            'numero_serie' => Fields::optionalText($input, 'numero_serie', 120, $errors, 'El número de serie'),
            'modelo_id' => $this->id($input, 'modelo_id', $errors, 'El modelo', true),
            'medida_id' => $this->id($input, 'medida_id', $errors, 'La medida', true),
            'fecha_adquisicion' => $this->fecha($input, $errors),
            'costo_adquisicion' => $this->decimal($input, 'costo_adquisicion', $errors, 'El costo', 12),
            'moneda' => $this->moneda($input, $errors),
            'profundidad_inicial_mm' => $this->decimal($input, 'profundidad_inicial_mm', $errors, 'La profundidad inicial', 4),
            'profundidad_minima_mm' => $this->decimal($input, 'profundidad_minima_mm', $errors, 'La profundidad mínima', 4),
            'observacion' => Fields::optionalText($input, 'observacion', 4000, $errors, 'La observación'),
        ];
    }

    /** @param array<string, mixed> $data @param array<string, list<string>> $errors */
    private function coherencia(array $data, array &$errors): void
    {
        if ($data['profundidad_minima_mm'] === null && !isset($errors['profundidad_minima_mm'])) {
            $errors['profundidad_minima_mm'][] = 'La profundidad mínima es obligatoria.';
        }
        if (
            $data['profundidad_inicial_mm'] !== null
            && $data['profundidad_minima_mm'] !== null
            && (float) $data['profundidad_inicial_mm'] < (float) $data['profundidad_minima_mm']
        ) {
            $errors['profundidad_inicial_mm'][] = 'La profundidad inicial no puede ser menor que la mínima.';
        }
        if ($data['costo_adquisicion'] !== null && $data['moneda'] === null && !isset($errors['moneda'])) {
            $errors['moneda'][] = 'La moneda es obligatoria cuando se indica un costo.';
        }
    }

    /** @param array<string, mixed> $input @param array<string, list<string>> $errors */
    private function rechazarInmutables(array $input, array &$errors, bool $alta): void
    {
        foreach (['id', 'creado_en', 'creado_por', 'actualizado_en', 'actualizado_por', 'eliminado', 'eliminado_en', 'eliminado_por', 'estado_id', 'vida_actual'] as $field) {
            if (array_key_exists($field, $input)) {
                $errors[$field][] = $field === 'estado_id' || $field === 'vida_actual'
                    ? 'Este dato lo establece el sistema y no se puede enviar.'
                    : 'Este campo no se puede modificar.';
            }
        }
        if (!$alta && array_key_exists('cliente_id', $input)) {
            $errors['cliente_id'][] = 'El cliente del neumático no se puede cambiar.';
        }
        if (!$alta && array_key_exists('codigo', $input)) {
            $errors['codigo'][] = 'El código del neumático no se puede cambiar.';
        }
    }

    /** @param array<string, mixed> $input @param array<string, list<string>> $errors */
    private function id(array $input, string $field, array &$errors, string $label, bool $required): ?int
    {
        if (!array_key_exists($field, $input) || $input[$field] === null || $input[$field] === '') {
            if ($required) {
                $errors[$field][] = $label . ' es obligatorio.';
            }
            return null;
        }
        $raw = $input[$field];
        $text = is_int($raw) ? (string) $raw : trim((string) $raw);
        if (preg_match('/^[1-9][0-9]*$/', $text) !== 1) {
            $errors[$field][] = $label . ' no es válido.';
            return null;
        }

        return (int) $text;
    }

    /** @param array<string, mixed> $input @param array<string, list<string>> $errors */
    private function codigo(array $input, array &$errors): ?string
    {
        $value = Fields::requiredText($input, 'codigo', 80, $errors, 'El código');
        if ($value !== null && preg_match('/^[A-Za-z0-9._\-]+$/', $value) !== 1) {
            $errors['codigo'][] = 'El código solo admite letras, números, punto, guion y guion bajo.';
            return null;
        }

        return $value;
    }

    /** @param array<string, mixed> $input @param array<string, list<string>> $errors */
    private function fecha(array $input, array &$errors): ?string
    {
        $value = Fields::optionalDate($input, 'fecha_adquisicion', $errors, 'La fecha de adquisición');
        if ($value !== null && $value > date('Y-m-d')) {
            $errors['fecha_adquisicion'][] = 'La fecha de adquisición no puede ser posterior a hoy.';
            return null;
        }

        return $value;
    }

    /** @param array<string, mixed> $input @param array<string, list<string>> $errors */
    private function moneda(array $input, array &$errors): ?string
    {
        $value = Fields::optionalText($input, 'moneda', 3, $errors, 'La moneda');
        if ($value !== null && preg_match('/^[A-Z]{3}$/', $value) !== 1) {
            $errors['moneda'][] = 'La moneda debe ser un código de tres letras mayúsculas.';
            return null;
        }

        return $value;
    }

    /** @param array<string, mixed> $input @param array<string, list<string>> $errors */
    private function decimal(array $input, string $field, array &$errors, string $label, int $enteros): ?string
    {
        if (!array_key_exists($field, $input) || $input[$field] === null || $input[$field] === '') {
            return null;
        }
        $raw = $input[$field];
        if (is_int($raw) && $raw >= 0) {
            $text = (string) $raw;
        } elseif (is_float($raw) && $raw >= 0) {
            $text = rtrim(rtrim(number_format($raw, 2, '.', ''), '0'), '.');
        } else {
            $text = trim((string) $raw);
        }
        if (preg_match('/^\d{1,' . $enteros . '}(\.\d{1,2})?$/', $text) !== 1) {
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
