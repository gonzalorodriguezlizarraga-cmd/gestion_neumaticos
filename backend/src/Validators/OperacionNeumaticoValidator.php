<?php

declare(strict_types=1);

namespace App\Validators;

use App\Exceptions\ValidationException;
use App\Support\Fields;
use DateTimeImmutable;
use DateTimeZone;

final class OperacionNeumaticoValidator
{
    private const ZONA = 'America/Lima';

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function montaje(array $input): array
    {
        $errors = [];
        $data = [
            'neumatico_id' => $this->id($input, 'neumatico_id', $errors, 'El neumático'),
            'unidad_id' => $this->id($input, 'unidad_id', $errors, 'La unidad'),
            'posicion_id' => $this->id($input, 'posicion_id', $errors, 'La posición'),
            'fecha_montaje' => $this->fecha($input, 'fecha_montaje', $errors, 'La fecha de montaje'),
            'km_montaje' => $this->km($input, 'km_montaje', $errors, 'El kilometraje'),
            'horometro_montaje' => $this->horometro($input, 'horometro_montaje', $errors, 'El horómetro'),
            'observacion' => Fields::optionalText($input, 'observacion', 2000, $errors, 'La observación'),
        ];
        $this->finish($errors);

        return $data;
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function desmontaje(array $input): array
    {
        $errors = [];
        $data = [
            'fecha_desmontaje' => $this->fecha($input, 'fecha_desmontaje', $errors, 'La fecha de desmontaje'),
            'km_desmontaje' => $this->km($input, 'km_desmontaje', $errors, 'El kilometraje'),
            'horometro_desmontaje' => $this->horometro($input, 'horometro_desmontaje', $errors, 'El horómetro'),
            'motivo_desmontaje' => Fields::requiredText($input, 'motivo_desmontaje', 255, $errors, 'El motivo de desmontaje'),
        ];
        $this->finish($errors);

        return $data;
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function rotacion(array $input): array
    {
        $errors = [];
        $cambios = [];
        $vistos = [];
        $destinos = [];
        if (!isset($input['cambios']) || !is_array($input['cambios']) || $input['cambios'] === []) {
            $errors['cambios'][] = 'La rotación necesita al menos un cambio de posición.';
        } else {
            foreach ($input['cambios'] as $indice => $cambio) {
                $campo = 'cambios.' . $indice;
                if (!is_array($cambio)) {
                    $errors['cambios'][] = 'Cada cambio debe indicar neumático y posición destino.';
                    continue;
                }
                $neumatico = $this->id($cambio, 'neumatico_id', $errors, 'El neumático', $campo . '.neumatico_id');
                $destino = $this->id($cambio, 'posicion_destino_id', $errors, 'La posición destino', $campo . '.posicion_destino_id');
                if ($neumatico !== null && isset($vistos[$neumatico])) {
                    $errors['cambios'][] = 'Un neumático no puede repetirse en la rotación.';
                }
                if ($destino !== null && isset($destinos[$destino])) {
                    $errors['cambios'][] = 'Una posición destino no puede repetirse en la rotación.';
                }
                if ($neumatico !== null) {
                    $vistos[$neumatico] = true;
                }
                if ($destino !== null) {
                    $destinos[$destino] = true;
                }
                if ($neumatico !== null && $destino !== null) {
                    $cambios[] = ['neumatico_id' => $neumatico, 'posicion_destino_id' => $destino];
                }
            }
        }
        $data = [
            'unidad_id' => $this->id($input, 'unidad_id', $errors, 'La unidad'),
            'fecha' => $this->fecha($input, 'fecha', $errors, 'La fecha'),
            'km_unidad' => $this->km($input, 'km_unidad', $errors, 'El kilometraje'),
            'horometro_unidad' => $this->horometro($input, 'horometro_unidad', $errors, 'El horómetro'),
            'observacion' => Fields::optionalText($input, 'observacion', 2000, $errors, 'La observación'),
            'cambios' => $cambios,
        ];
        $this->finish($errors);

        return $data;
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function transferencia(array $input): array
    {
        $errors = [];
        $data = [
            'neumatico_id' => $this->id($input, 'neumatico_id', $errors, 'El neumático'),
            'unidad_destino_id' => $this->id($input, 'unidad_destino_id', $errors, 'La unidad destino'),
            'posicion_destino_id' => $this->id($input, 'posicion_destino_id', $errors, 'La posición destino'),
            'fecha' => $this->fecha($input, 'fecha', $errors, 'La fecha'),
            'km_unidad' => $this->km($input, 'km_unidad', $errors, 'El kilometraje'),
            'horometro_unidad' => $this->horometro($input, 'horometro_unidad', $errors, 'El horómetro'),
            'observacion' => Fields::optionalText($input, 'observacion', 2000, $errors, 'La observación'),
        ];
        $this->finish($errors);

        return $data;
    }

    /** @param array<string, mixed> $input @param array<string, list<string>> $errors */
    private function id(array $input, string $field, array &$errors, string $label, ?string $errorField = null): ?int
    {
        $key = $errorField ?? $field;
        if (!array_key_exists($field, $input) || $input[$field] === null || $input[$field] === '') {
            $errors[$key][] = $label . ' es obligatorio.';

            return null;
        }
        $text = trim((string) $input[$field]);
        if (preg_match('/^[1-9][0-9]*$/', $text) !== 1) {
            $errors[$key][] = $label . ' no es válido.';

            return null;
        }

        return (int) $text;
    }

    /** @param array<string, mixed> $input @param array<string, list<string>> $errors */
    private function fecha(array $input, string $field, array &$errors, string $label): ?string
    {
        if (!array_key_exists($field, $input) || trim((string) $input[$field]) === '') {
            $errors[$field][] = $label . ' es obligatoria.';

            return null;
        }
        $normalizada = str_replace('T', ' ', trim((string) $input[$field]));
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $normalizada) === 1) {
            $normalizada .= ':00';
        }
        $zona = new DateTimeZone(self::ZONA);
        $fecha = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $normalizada, $zona);
        $errores = DateTimeImmutable::getLastErrors();
        $invalida = !$fecha instanceof DateTimeImmutable
            || $fecha->format('Y-m-d H:i:s') !== $normalizada
            || ($errores !== false && (($errores['warning_count'] ?? 0) > 0 || ($errores['error_count'] ?? 0) > 0));
        if ($invalida) {
            $errors[$field][] = $label . ' debe tener formato AAAA-MM-DD HH:MM:SS.';

            return null;
        }
        $limite = new DateTimeImmutable('+5 minutes', $zona);
        if ($fecha > $limite) {
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
        $text = trim((string) $input[$field]);
        if (preg_match('/^(?:0|[1-9][0-9]*)$/', $text) !== 1 || strlen($text) > 18) {
            $errors[$field][] = $label . ' debe ser un entero mayor o igual a cero.';

            return null;
        }
        $valor = (int) $text;
        if ((string) $valor !== $text) {
            $errors[$field][] = $label . ' debe ser un entero mayor o igual a cero.';

            return null;
        }

        return $valor;
    }

    /** @param array<string, mixed> $input @param array<string, list<string>> $errors */
    private function horometro(array $input, string $field, array &$errors, string $label): ?string
    {
        if (!array_key_exists($field, $input) || $input[$field] === null || $input[$field] === '') {
            return null;
        }
        $text = trim((string) $input[$field]);
        if (preg_match('/^(?:0|[1-9]\d{0,9})(?:\.\d{1,2})?$/', $text) !== 1) {
            $errors[$field][] = $label . ' debe ser un decimal mayor o igual a cero, con hasta dos decimales.';

            return null;
        }

        return number_format((float) $text, 2, '.', '');
    }

    /** @param array<string, list<string>> $errors */
    private function finish(array $errors): void
    {
        if ($errors !== []) {
            throw new ValidationException('Los datos enviados no son válidos.', $errors);
        }
    }
}
