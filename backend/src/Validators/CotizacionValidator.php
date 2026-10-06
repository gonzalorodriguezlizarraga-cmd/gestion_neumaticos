<?php

declare(strict_types=1);

namespace App\Validators;

use App\Exceptions\ValidationException;
use App\Support\Fields;

final class CotizacionValidator
{
    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function crear(array $input): array
    {
        $this->rechazar($input, ['estado', 'estado_id', 'numero', 'subtotal', 'total', 'creado_por']);

        return $this->cuerpo($input, true);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function editar(array $input): array
    {
        $this->rechazar($input, ['cliente_id', 'oportunidad_id', 'estado', 'estado_id', 'numero', 'subtotal', 'total', 'creado_por']);

        return $this->cuerpo($input, false);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function cuerpo(array $input, bool $alta): array
    {
        $errors = [];
        $cliente = $alta ? $this->id($input, 'cliente_id', true, $errors, 'El cliente') : null;
        $oportunidad = $alta ? $this->id($input, 'oportunidad_id', false, $errors, 'La oportunidad') : null;
        $fecha = Fields::requiredDate($input, 'fecha', $errors, 'La fecha');
        $moneda = strtoupper(trim((string) ($input['moneda'] ?? '')));
        if (preg_match('/^[A-Z]{3}$/', $moneda) !== 1) {
            $errors['moneda'][] = 'La moneda debe tener tres letras mayúsculas.';
            $moneda = '';
        }
        $detalles = $this->detalles($input, $errors);
        if ($detalles === []) {
            $errors['detalles'][] = 'La cotización necesita al menos un detalle.';
        }
        if ($errors !== []) {
            throw new ValidationException('Los datos enviados no son válidos.', $errors);
        }
        $data = [
            'fecha' => (string) $fecha,
            'moneda' => $moneda,
            'observacion' => Fields::optionalText($input, 'observacion', 4000, $errors, 'La observación'),
            'detalles' => $detalles,
        ];
        if ($alta) {
            $data['cliente_id'] = (int) $cliente;
            $data['oportunidad_id'] = $oportunidad;
        }
        if ($errors !== []) {
            throw new ValidationException('Los datos enviados no son válidos.', $errors);
        }

        return $data;
    }

    /** @param array<string, mixed> $input @param list<string> $campos */
    private function rechazar(array $input, array $campos): void
    {
        foreach ($campos as $campo) {
            if (array_key_exists($campo, $input)) {
                throw new ValidationException('Los datos enviados no son válidos.', [
                    $campo => ['Este dato lo calcula o asigna el sistema.'],
                ]);
            }
        }
    }

    /** @param array<string, mixed> $input @param array<string, list<string>> $errors @return list<array<string, mixed>> */
    private function detalles(array $input, array &$errors): array
    {
        if (!isset($input['detalles']) || !is_array($input['detalles'])) {
            $errors['detalles'][] = 'Los detalles no tienen un formato válido.';

            return [];
        }
        $filas = [];
        foreach (array_values($input['detalles']) as $indice => $fila) {
            if (!is_array($fila)) {
                $errors['detalles'][] = 'Cada detalle debe ser un objeto.';
                continue;
            }
            if (array_key_exists('subtotal', $fila) || array_key_exists('total', $fila)) {
                $errors['detalles.' . $indice . '.subtotal'][] = 'El subtotal del detalle lo calcula el sistema.';
                continue;
            }
            $cantidad = trim((string) ($fila['cantidad'] ?? ''));
            $precio = trim((string) ($fila['precio_unitario'] ?? ''));
            if (preg_match('/^(?:0|[1-9]\d{0,9})(?:\.\d{1,2})?$/', $cantidad) !== 1 || (float) $cantidad <= 0) {
                $errors['detalles.' . $indice . '.cantidad'][] = 'La cantidad debe ser mayor que cero.';
                $cantidad = '0.00';
            }
            if (preg_match('/^(?:0|[1-9]\d{0,11})(?:\.\d{1,2})?$/', $precio) !== 1) {
                $errors['detalles.' . $indice . '.precio_unitario'][] = 'El precio unitario debe ser mayor o igual que cero.';
                $precio = '0.00';
            }
            $filas[] = [
                'descripcion' => Fields::requiredText($fila, 'descripcion', 255, $errors, 'La descripción'),
                'modelo_neumatico_id' => $this->id($fila, 'modelo_neumatico_id', false, $errors, 'El modelo', 'detalles.' . $indice . '.modelo_neumatico_id'),
                'medida_neumatico_id' => $this->id($fila, 'medida_neumatico_id', false, $errors, 'La medida', 'detalles.' . $indice . '.medida_neumatico_id'),
                'cantidad' => number_format((float) $cantidad, 2, '.', ''),
                'precio_unitario' => number_format((float) $precio, 2, '.', ''),
            ];
        }

        return $filas;
    }

    /** @param array<string, mixed> $input @param array<string, list<string>> $errors */
    private function id(array $input, string $field, bool $obligatorio, array &$errors, string $label, ?string $clave = null): ?int
    {
        $clave ??= $field;
        if (!$obligatorio && (!array_key_exists($field, $input) || $input[$field] === null || $input[$field] === '')) {
            return null;
        }
        $texto = trim((string) ($input[$field] ?? ''));
        if (preg_match('/^[1-9][0-9]*$/', $texto) !== 1) {
            $errors[$clave][] = $label . ($obligatorio ? ' es obligatorio.' : ' no es válido.');

            return null;
        }

        return (int) $texto;
    }
}
