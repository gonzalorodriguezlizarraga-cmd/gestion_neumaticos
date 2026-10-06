<?php

declare(strict_types=1);

namespace App\Validators;

use App\Exceptions\ValidationException;
use App\Support\Fields;
use DateTimeImmutable;
use DateTimeZone;

final class InspeccionValidator
{
    private const ZONA = 'America/Lima';

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function cabecera(array $input, bool $tecnicoObligatorio = false): array
    {
        $errors = [];
        $data = [
            'unidad_id' => $this->id($input, 'unidad_id', $errors, 'La unidad'),
            'fecha_inspeccion' => $this->fecha($input, 'fecha_inspeccion', $errors, 'La fecha de inspección'),
            'kilometraje' => $this->km($input, 'kilometraje', $errors, 'El kilometraje'),
            'horometro' => $this->decimal($input, 'horometro', $errors, 'El horómetro', 10),
            'observacion_general' => Fields::optionalText($input, 'observacion_general', 2000, $errors, 'La observación'),
            'tecnico_id' => $this->idOpcional($input, 'tecnico_id', $errors, 'El técnico'),
        ];
        if ($tecnicoObligatorio && $data['tecnico_id'] === null && !isset($errors['tecnico_id'])) {
            $errors['tecnico_id'][] = 'El técnico es obligatorio.';
        }
        $this->finish($errors);

        return $data;
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function cabeceraEdicion(array $input): array
    {
        $errors = [];
        $data = [
            'fecha_inspeccion' => $this->fecha($input, 'fecha_inspeccion', $errors, 'La fecha de inspección'),
            'kilometraje' => $this->km($input, 'kilometraje', $errors, 'El kilometraje'),
            'horometro' => $this->decimal($input, 'horometro', $errors, 'El horómetro', 10),
            'observacion_general' => Fields::optionalText($input, 'observacion_general', 2000, $errors, 'La observación'),
            'tecnico_id' => $this->idOpcional($input, 'tecnico_id', $errors, 'El técnico'),
        ];
        $this->finish($errors);

        return $data;
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function detalle(array $input): array
    {
        $errors = [];
        $condicion = isset($input['condicion']) ? strtoupper(trim((string) $input['condicion'])) : '';
        if (!in_array($condicion, ['NORMAL', 'ATENCION', 'CRITICO'], true)) {
            $errors['condicion'][] = 'La condición debe ser NORMAL, ATENCION o CRITICO.';
            $condicion = '';
        }
        $data = [
            'posicion_id' => $this->id($input, 'posicion_id', $errors, 'La posición'),
            'profundidad_interior_mm' => $this->decimal($input, 'profundidad_interior_mm', $errors, 'La profundidad interior', 4),
            'profundidad_centro_mm' => $this->decimal($input, 'profundidad_centro_mm', $errors, 'La profundidad central', 4),
            'profundidad_exterior_mm' => $this->decimal($input, 'profundidad_exterior_mm', $errors, 'La profundidad exterior', 4),
            'presion_psi' => $this->decimal($input, 'presion_psi', $errors, 'La presión', 6),
            'condicion' => $condicion,
            'observacion' => Fields::optionalText($input, 'observacion', 2000, $errors, 'La observación'),
        ];
        $this->finish($errors);

        return $data;
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function detalleEdicion(array $input): array
    {
        $errors = [];
        $condicion = isset($input['condicion']) ? strtoupper(trim((string) $input['condicion'])) : '';
        if (!in_array($condicion, ['NORMAL', 'ATENCION', 'CRITICO'], true)) {
            $errors['condicion'][] = 'La condición debe ser NORMAL, ATENCION o CRITICO.';
            $condicion = '';
        }
        $data = [
            'profundidad_interior_mm' => $this->decimal($input, 'profundidad_interior_mm', $errors, 'La profundidad interior', 4),
            'profundidad_centro_mm' => $this->decimal($input, 'profundidad_centro_mm', $errors, 'La profundidad central', 4),
            'profundidad_exterior_mm' => $this->decimal($input, 'profundidad_exterior_mm', $errors, 'La profundidad exterior', 4),
            'presion_psi' => $this->decimal($input, 'presion_psi', $errors, 'La presión', 6),
            'condicion' => $condicion,
            'observacion' => Fields::optionalText($input, 'observacion', 2000, $errors, 'La observación'),
        ];
        $this->finish($errors);

        return $data;
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function dano(array $input): array
    {
        $errors = [];
        $severidad = null;
        if (array_key_exists('severidad', $input) && $input['severidad'] !== null && trim((string) $input['severidad']) !== '') {
            $severidad = strtoupper(trim((string) $input['severidad']));
            if (!in_array($severidad, ['LEVE', 'MEDIA', 'ALTA', 'CRITICA'], true)) {
                $errors['severidad'][] = 'La severidad debe ser LEVE, MEDIA, ALTA o CRITICA.';
                $severidad = null;
            }
        }
        $data = [
            'tipo_dano_id' => $this->id($input, 'tipo_dano_id', $errors, 'El tipo de daño'),
            'severidad' => $severidad,
            'observacion' => Fields::optionalText($input, 'observacion', 500, $errors, 'La observación'),
        ];
        $this->finish($errors);

        return $data;
    }

    /** @param array<string, mixed> $input @param array<string, list<string>> $errors */
    private function id(array $input, string $field, array &$errors, string $label): ?int
    {
        $valor = $this->idOpcional($input, $field, $errors, $label);
        if ($valor === null && !isset($errors[$field])) {
            $errors[$field][] = $label . ' es obligatorio.';
        }

        return $valor;
    }

    /** @param array<string, mixed> $input @param array<string, list<string>> $errors */
    private function idOpcional(array $input, string $field, array &$errors, string $label): ?int
    {
        if (!array_key_exists($field, $input) || $input[$field] === null || $input[$field] === '') {
            return null;
        }
        $texto = trim((string) $input[$field]);
        if (preg_match('/^[1-9][0-9]*$/', $texto) !== 1) {
            $errors[$field][] = $label . ' no es válido.';

            return null;
        }

        return (int) $texto;
    }

    /** @param array<string, mixed> $input @param array<string, list<string>> $errors */
    private function fecha(array $input, string $field, array &$errors, string $label): ?string
    {
        if (!isset($input[$field]) || trim((string) $input[$field]) === '') {
            $errors[$field][] = $label . ' es obligatoria.';

            return null;
        }
        $normalizada = str_replace('T', ' ', trim((string) $input[$field]));
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $normalizada) === 1) {
            $normalizada .= ':00';
        }
        $zona = new DateTimeZone(self::ZONA);
        $fecha = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $normalizada, $zona);
        $fallos = DateTimeImmutable::getLastErrors();
        $invalida = !$fecha instanceof DateTimeImmutable
            || $fecha->format('Y-m-d H:i:s') !== $normalizada
            || ($fallos !== false && (($fallos['warning_count'] ?? 0) > 0 || ($fallos['error_count'] ?? 0) > 0));
        if ($invalida) {
            $errors[$field][] = $label . ' debe tener formato AAAA-MM-DD HH:MM:SS.';

            return null;
        }
        if ($fecha > new DateTimeImmutable('+5 minutes', $zona)) {
            $errors[$field][] = $label . ' no puede estar en el futuro.';

            return null;
        }

        return $fecha->format('Y-m-d H:i:s');
    }

    /** @param array<string, mixed> $input @param array<string, list<string>> $errors */
    private function km(array $input, string $field, array &$errors, string $label): ?int
    {
        if (!array_key_exists($field, $input) || $input[$field] === null || $input[$field] === '') {
            return null;
        }
        $texto = trim((string) $input[$field]);
        if (preg_match('/^(?:0|[1-9][0-9]*)$/', $texto) !== 1 || strlen($texto) > 18) {
            $errors[$field][] = $label . ' debe ser un entero mayor o igual a cero.';

            return null;
        }
        $valor = (int) $texto;
        if ((string) $valor !== $texto) {
            $errors[$field][] = $label . ' debe ser un entero mayor o igual a cero.';

            return null;
        }

        return $valor;
    }

    /** @param array<string, mixed> $input @param array<string, list<string>> $errors */
    private function decimal(array $input, string $field, array &$errors, string $label, int $enteros): ?string
    {
        if (!array_key_exists($field, $input) || $input[$field] === null || $input[$field] === '') {
            return null;
        }
        $texto = trim((string) $input[$field]);
        $patron = '/^(?:0|[1-9]\d{0,' . ($enteros - 1) . '})(?:\.\d{1,2})?$/';
        if (preg_match($patron, $texto) !== 1) {
            $errors[$field][] = $label . ' debe ser un decimal mayor o igual a cero.';

            return null;
        }

        return number_format((float) $texto, 2, '.', '');
    }

    /** @param array<string, list<string>> $errors */
    private function finish(array $errors): void
    {
        if ($errors !== []) {
            throw new ValidationException('Los datos enviados no son válidos.', $errors);
        }
    }
}
