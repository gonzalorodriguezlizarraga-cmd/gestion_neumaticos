<?php

declare(strict_types=1);

namespace App\Validators;

use App\Exceptions\ValidationException;
use App\Support\Fields;

final class UnidadValidator
{
    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function crear(array $input): array
    {
        $errors = [];
        $this->rechazarBloqueados($input, $errors);
        $data = $this->comun($input, $errors, true);
        $data['cliente_id'] = $this->id($input, 'cliente_id', $errors, 'El cliente', true);
        $data['estado'] = $this->estadoValor($input, $errors, false);
        $this->finish($errors);

        return $data;
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function actualizar(array $input): array
    {
        $errors = [];
        $this->rechazarBloqueados($input, $errors);
        if (array_key_exists('cliente_id', $input)) {
            $errors['cliente_id'][] = 'El cliente de la unidad no se puede cambiar.';
        }
        if (array_key_exists('estado', $input)) {
            $errors['estado'][] = 'El estado se modifica en su propio endpoint.';
        }
        $data = $this->comun($input, $errors, true);
        $this->finish($errors);

        return $data;
    }

    /** @param array<string, mixed> $input */
    public function estado(array $input): string
    {
        $errors = [];
        if (!array_key_exists('estado', $input)) {
            $errors['estado'][] = 'El estado es obligatorio.';
        }
        $estado = $this->estadoValor($input, $errors, true);
        $this->finish($errors);

        return (string) $estado;
    }

    /** @param array<string, mixed> $input @param array<string, list<string>> $errors @return array<string, mixed> */
    private function comun(array $input, array &$errors, bool $codigoObligatorio): array
    {
        return [
            'sede_id' => $this->id($input, 'sede_id', $errors, 'La sede', false),
            'flota_id' => $this->id($input, 'flota_id', $errors, 'La flota', false),
            'tipo_unidad_id' => $this->id($input, 'tipo_unidad_id', $errors, 'El tipo de unidad', true),
            'configuracion_id' => $this->id($input, 'configuracion_id', $errors, 'La configuración', false),
            'codigo' => $this->codigo($input, $errors, $codigoObligatorio),
            'placa' => Fields::optionalText($input, 'placa', 30, $errors, 'La placa'),
            'marca' => Fields::optionalText($input, 'marca', 100, $errors, 'La marca'),
            'modelo' => Fields::optionalText($input, 'modelo', 100, $errors, 'El modelo'),
            'anio' => $this->anio($input, $errors),
            'numero_serie' => Fields::optionalText($input, 'numero_serie', 100, $errors, 'El número de serie'),
            'kilometraje_actual' => $this->enteroNoNegativo($input, 'kilometraje_actual', $errors, 'El kilometraje'),
            'horometro_actual' => $this->decimalNoNegativo($input, $errors),
            'observacion' => Fields::optionalText($input, 'observacion', 4000, $errors, 'La observación'),
        ];
    }

    /** @param array<string, mixed> $input @param array<string, list<string>> $errors */
    private function rechazarBloqueados(array $input, array &$errors): void
    {
        foreach (['id', 'creado_en', 'creado_por', 'actualizado_en', 'actualizado_por', 'eliminado', 'eliminado_en', 'eliminado_por'] as $field) {
            if (array_key_exists($field, $input)) {
                $errors[$field][] = 'Este campo no se puede modificar.';
            }
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
    private function codigo(array $input, array &$errors, bool $required): ?string
    {
        $value = $required
            ? Fields::requiredText($input, 'codigo', 80, $errors, 'El código')
            : Fields::optionalText($input, 'codigo', 80, $errors, 'El código');
        if ($value !== null && preg_match('/^[A-Za-z0-9._\-]+$/', $value) !== 1) {
            $errors['codigo'][] = 'El código solo admite letras, números, punto, guion y guion bajo.';
            return null;
        }

        return $value;
    }

    /** @param array<string, mixed> $input @param array<string, list<string>> $errors */
    private function estadoValor(array $input, array &$errors, bool $required): ?string
    {
        if (!array_key_exists('estado', $input) || $input['estado'] === null || $input['estado'] === '') {
            return $required ? null : 'OPERATIVA';
        }
        $estado = strtoupper(trim((string) $input['estado']));
        if (!in_array($estado, ['OPERATIVA', 'INACTIVA', 'BAJA'], true)) {
            $errors['estado'][] = 'El estado no es válido.';
            return null;
        }

        return $estado;
    }

    /** @param array<string, mixed> $input @param array<string, list<string>> $errors */
    private function anio(array $input, array &$errors): ?int
    {
        if (!array_key_exists('anio', $input) || $input['anio'] === null || $input['anio'] === '') {
            return null;
        }
        $raw = $input['anio'];
        $text = is_int($raw) ? (string) $raw : trim((string) $raw);
        $max = (int) date('Y') + 1;
        if (preg_match('/^[1-9][0-9]*$/', $text) !== 1) {
            $errors['anio'][] = 'El año no es válido.';
            return null;
        }
        $anio = (int) $text;
        if ($anio < 1900 || $anio > $max) {
            $errors['anio'][] = 'El año debe estar entre 1900 y ' . $max . '.';
            return null;
        }

        return $anio;
    }

    /** @param array<string, mixed> $input @param array<string, list<string>> $errors */
    private function enteroNoNegativo(array $input, string $field, array &$errors, string $label): ?int
    {
        if (!array_key_exists($field, $input) || $input[$field] === null || $input[$field] === '') {
            return null;
        }
        $raw = $input[$field];
        if (is_int($raw) && $raw >= 0) {
            return $raw;
        }
        $text = trim((string) $raw);
        if (preg_match('/^\d+$/', $text) !== 1) {
            $errors[$field][] = $label . ' debe ser un entero mayor o igual que cero.';
            return null;
        }

        return (int) $text;
    }

    /** @param array<string, mixed> $input @param array<string, list<string>> $errors */
    private function decimalNoNegativo(array $input, array &$errors): ?string
    {
        if (!array_key_exists('horometro_actual', $input) || $input['horometro_actual'] === null || $input['horometro_actual'] === '') {
            return null;
        }
        $raw = $input['horometro_actual'];
        if (is_int($raw) && $raw >= 0) {
            return (string) $raw;
        }
        if (is_float($raw) && $raw >= 0) {
            $text = rtrim(rtrim(number_format($raw, 2, '.', ''), '0'), '.');
        } else {
            $text = trim((string) $raw);
        }
        if (preg_match('/^\d{1,10}(\.\d{1,2})?$/', $text) !== 1) {
            $errors['horometro_actual'][] = 'El horómetro debe ser un número mayor o igual que cero, con hasta dos decimales.';
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
