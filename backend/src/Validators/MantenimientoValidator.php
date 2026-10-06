<?php

declare(strict_types=1);

namespace App\Validators;

use App\Exceptions\ValidationException;
use App\Support\Fields;
use DateTimeImmutable;
use DateTimeZone;

final class MantenimientoValidator
{
    private const ZONA = 'America/Lima';

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function crear(array $input): array
    {
        if (array_key_exists('estado_id', $input) || array_key_exists('estado', $input) || array_key_exists('inicia_nueva_vida', $input)) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'estado' => ['El estado y la nueva vida los define el sistema.'],
            ]);
        }
        $errors = [];
        $costo = $this->decimal($input, 'costo', $errors, 'El costo', 12);
        $data = [
            'neumatico_id' => $this->id($input, 'neumatico_id', $errors, 'El neumático'),
            'tipo_mantenimiento_id' => $this->id($input, 'tipo_mantenimiento_id', $errors, 'El tipo de mantenimiento'),
            'fecha_solicitud' => $this->fecha($input, 'fecha_solicitud', $errors, 'La fecha de solicitud'),
            'tercero_nombre' => Fields::optionalText($input, 'tercero_nombre', 180, $errors, 'El tercero'),
            'costo' => $costo,
            'moneda' => $this->moneda($input, $costo, $errors),
            'profundidad_antes_mm' => $this->decimal($input, 'profundidad_antes_mm', $errors, 'La profundidad anterior', 4),
            'observacion' => Fields::optionalText($input, 'observacion', 2000, $errors, 'La observación'),
        ];
        $this->finish($errors);

        return $data;
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function enviar(array $input): array
    {
        $errors = [];
        $data = [
            'fecha_envio' => $this->fecha($input, 'fecha_envio', $errors, 'La fecha de envío'),
            'observacion' => Fields::optionalText($input, 'observacion', 500, $errors, 'La observación'),
        ];
        $this->finish($errors);

        return $data;
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function iniciar(array $input): array
    {
        $errors = [];
        $data = [
            'observacion' => Fields::optionalText($input, 'observacion', 500, $errors, 'La observación'),
        ];
        $this->finish($errors);

        return $data;
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function finalizar(array $input): array
    {
        if (array_key_exists('inicia_nueva_vida', $input)) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'inicia_nueva_vida' => ['La nueva vida la determina el tipo de mantenimiento.'],
            ]);
        }
        $errors = [];
        $costo = $this->decimal($input, 'costo', $errors, 'El costo', 12);
        $data = [
            'fecha_retorno' => $this->fecha($input, 'fecha_retorno', $errors, 'La fecha de retorno'),
            'costo' => $costo,
            'moneda' => $this->moneda($input, $costo, $errors),
            'profundidad_despues_mm' => $this->decimal($input, 'profundidad_despues_mm', $errors, 'La profundidad posterior', 4),
            'observacion' => Fields::optionalText($input, 'observacion', 2000, $errors, 'La observación'),
        ];
        $this->finish($errors);

        return $data;
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function cancelar(array $input): array
    {
        $errors = [];
        $data = [
            'observacion' => Fields::optionalText($input, 'observacion', 500, $errors, 'La observación'),
        ];
        $this->finish($errors);

        return $data;
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function descartar(array $input): array
    {
        $errors = [];
        $data = [
            'motivo_descarte_id' => $this->id($input, 'motivo_descarte_id', $errors, 'El motivo'),
            'fecha_descarte' => $this->fecha($input, 'fecha_descarte', $errors, 'La fecha de descarte'),
            'profundidad_final_mm' => $this->decimal($input, 'profundidad_final_mm', $errors, 'La profundidad final', 4),
            'observacion' => Fields::optionalText($input, 'observacion', 2000, $errors, 'La observación'),
        ];
        $this->finish($errors);

        return $data;
    }

    /** @param array<string, mixed> $input @param array<string, list<string>> $errors */
    private function id(array $input, string $field, array &$errors, string $label): int
    {
        $texto = trim((string) ($input[$field] ?? ''));
        if (preg_match('/^[1-9][0-9]*$/', $texto) !== 1) {
            $errors[$field][] = $label . ' es obligatorio.';

            return 0;
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

    /** @param array<string, mixed> $input @param array<string, list<string>> $errors */
    private function moneda(array $input, ?string $costo, array &$errors): ?string
    {
        $vacio = !array_key_exists('moneda', $input) || $input['moneda'] === null || trim((string) $input['moneda']) === '';
        if ($vacio) {
            if ($costo !== null) {
                $errors['moneda'][] = 'La moneda es obligatoria cuando hay costo.';
            }

            return null;
        }
        $moneda = strtoupper(trim((string) $input['moneda']));
        if (preg_match('/^[A-Z]{3}$/', $moneda) !== 1) {
            $errors['moneda'][] = 'La moneda debe tener tres letras.';

            return null;
        }

        return $moneda;
    }

    /** @param array<string, list<string>> $errors */
    private function finish(array $errors): void
    {
        if ($errors !== []) {
            throw new ValidationException('Los datos enviados no son válidos.', $errors);
        }
    }
}
