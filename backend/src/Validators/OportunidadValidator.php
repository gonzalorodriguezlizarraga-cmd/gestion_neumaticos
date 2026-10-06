<?php

declare(strict_types=1);

namespace App\Validators;

use App\Exceptions\ValidationException;
use App\Support\Fields;

final class OportunidadValidator
{
    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function crear(array $input): array
    {
        $this->rechazar($input, ['estado_id', 'estado', 'origen', 'alerta_id', 'creado_por']);
        $errors = [];
        $data = [
            'cliente_id' => $this->id($input, 'cliente_id', $errors, 'El cliente', true),
            'responsable_comercial_id' => $this->id($input, 'responsable_comercial_id', $errors, 'El responsable comercial', true),
            'titulo' => Fields::requiredText($input, 'titulo', 180, $errors, 'El título'),
            'descripcion' => Fields::optionalText($input, 'descripcion', 4000, $errors, 'La descripción'),
            'fecha_deteccion' => $this->fechaHora($input, 'fecha_deteccion', $errors, true),
            'fecha_estimada_necesidad' => Fields::optionalDate($input, 'fecha_estimada_necesidad', $errors, 'La fecha estimada de necesidad'),
            'valor_estimado' => $this->dinero($input, 'valor_estimado', $errors, false),
            'moneda' => $this->moneda($input, $errors, false),
            'detalles' => $this->detalles($input, $errors),
        ];
        $this->parValor($data, $errors);
        $this->finish($errors);

        return $data;
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function desdeAlerta(array $input): array
    {
        $this->rechazar($input, ['estado_id', 'estado', 'origen', 'alerta_id', 'cliente_id', 'creado_por', 'fecha_deteccion']);
        $errors = [];
        $data = [
            'responsable_comercial_id' => $this->id($input, 'responsable_comercial_id', $errors, 'El responsable comercial', true),
            'titulo' => Fields::requiredText($input, 'titulo', 180, $errors, 'El título'),
            'descripcion' => Fields::optionalText($input, 'descripcion', 4000, $errors, 'La descripción'),
            'fecha_estimada_necesidad' => Fields::optionalDate($input, 'fecha_estimada_necesidad', $errors, 'La fecha estimada de necesidad'),
            'valor_estimado' => $this->dinero($input, 'valor_estimado', $errors, false),
            'moneda' => $this->moneda($input, $errors, false),
            'detalles' => $this->detalles($input, $errors),
        ];
        $this->parValor($data, $errors);
        $this->finish($errors);

        return $data;
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function editar(array $input): array
    {
        $this->rechazar($input, ['cliente_id', 'origen', 'alerta_id', 'estado_id', 'estado', 'creado_por', 'fecha_deteccion']);
        $errors = [];
        $data = [
            'titulo' => Fields::requiredText($input, 'titulo', 180, $errors, 'El título'),
            'descripcion' => Fields::optionalText($input, 'descripcion', 4000, $errors, 'La descripción'),
            'fecha_estimada_necesidad' => Fields::optionalDate($input, 'fecha_estimada_necesidad', $errors, 'La fecha estimada de necesidad'),
            'valor_estimado' => $this->dinero($input, 'valor_estimado', $errors, false),
            'moneda' => $this->moneda($input, $errors, false),
            'responsable_comercial_id' => $this->id($input, 'responsable_comercial_id', $errors, 'El responsable comercial', false),
            'detalles' => $this->detalles($input, $errors),
        ];
        $this->parValor($data, $errors);
        $this->finish($errors);

        return $data;
    }

    /** @param array<string, mixed> $input @return array{motivo:?string} */
    public function motivo(array $input, bool $obligatorio): array
    {
        $errors = [];
        $motivo = $obligatorio
            ? Fields::requiredText($input, 'motivo', 500, $errors, 'El motivo')
            : Fields::optionalText($input, 'motivo', 500, $errors, 'El motivo');
        $this->finish($errors);

        return ['motivo' => $motivo];
    }

    /** @param array<string, mixed> $input @param list<string> $campos */
    private function rechazar(array $input, array $campos): void
    {
        foreach ($campos as $campo) {
            if (array_key_exists($campo, $input)) {
                throw new ValidationException('Los datos enviados no son válidos.', [
                    $campo => ['Este dato lo define el proceso comercial y no puede enviarse.'],
                ]);
            }
        }
    }

    /** @param array<string, mixed> $data @param array<string, list<string>> $errors */
    private function parValor(array $data, array &$errors): void
    {
        if ($data['valor_estimado'] !== null && $data['moneda'] === null) {
            $errors['moneda'][] = 'La moneda es obligatoria cuando hay un valor estimado.';
        }
    }

    /** @param array<string, mixed> $input @param array<string, list<string>> $errors @return list<array<string, mixed>> */
    private function detalles(array $input, array &$errors): array
    {
        if (!array_key_exists('detalles', $input) || $input['detalles'] === null) {
            return [];
        }
        if (!is_array($input['detalles'])) {
            $errors['detalles'][] = 'Los detalles no tienen un formato válido.';

            return [];
        }
        $filas = [];
        foreach (array_values($input['detalles']) as $indice => $fila) {
            if (!is_array($fila)) {
                $errors['detalles'][] = 'Cada detalle debe ser un objeto.';
                continue;
            }
            $cantidad = $this->cantidad($fila, $errors, 'detalles.' . $indice . '.cantidad');
            $precio = $this->dinero($fila, 'precio_estimado', $errors, false, 'detalles.' . $indice . '.precio_estimado');
            $filas[] = [
                'medida_neumatico_id' => $this->id($fila, 'medida_neumatico_id', $errors, 'La medida', false, 'detalles.' . $indice . '.medida_neumatico_id'),
                'modelo_neumatico_id' => $this->id($fila, 'modelo_neumatico_id', $errors, 'El modelo', false, 'detalles.' . $indice . '.modelo_neumatico_id'),
                'cantidad' => $cantidad,
                'precio_estimado' => $precio,
                'observacion' => Fields::optionalText($fila, 'observacion', 500, $errors, 'La observación del detalle'),
            ];
        }

        return $filas;
    }

    /** @param array<string, mixed> $input @param array<string, list<string>> $errors */
    private function id(array $input, string $field, array &$errors, string $label, bool $obligatorio, ?string $clave = null): ?int
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

    /** @param array<string, mixed> $input @param array<string, list<string>> $errors */
    private function dinero(array $input, string $field, array &$errors, bool $obligatorio, ?string $clave = null): ?string
    {
        $clave ??= $field;
        if (!$obligatorio && (!array_key_exists($field, $input) || $input[$field] === null || $input[$field] === '')) {
            return null;
        }
        $texto = trim((string) ($input[$field] ?? ''));
        if (preg_match('/^(?:0|[1-9]\d{0,11})(?:\.\d{1,2})?$/', $texto) !== 1) {
            $errors[$clave][] = 'El importe debe ser un número mayor o igual que cero, con hasta dos decimales.';

            return null;
        }

        return number_format((float) $texto, 2, '.', '');
    }

    /** @param array<string, mixed> $input @param array<string, list<string>> $errors */
    private function cantidad(array $input, array &$errors, string $clave): ?string
    {
        $texto = trim((string) ($input['cantidad'] ?? ''));
        if (preg_match('/^(?:0|[1-9]\d{0,9})(?:\.\d{1,2})?$/', $texto) !== 1 || (float) $texto <= 0) {
            $errors[$clave][] = 'La cantidad debe ser mayor que cero.';

            return null;
        }

        return number_format((float) $texto, 2, '.', '');
    }

    /** @param array<string, mixed> $input @param array<string, list<string>> $errors */
    private function moneda(array $input, array &$errors, bool $obligatoria): ?string
    {
        if (!$obligatoria && (!array_key_exists('moneda', $input) || $input['moneda'] === null || trim((string) $input['moneda']) === '')) {
            return null;
        }
        $moneda = strtoupper(trim((string) ($input['moneda'] ?? '')));
        if (preg_match('/^[A-Z]{3}$/', $moneda) !== 1) {
            $errors['moneda'][] = 'La moneda debe tener tres letras mayúsculas.';

            return null;
        }

        return $moneda;
    }

    /** @param array<string, mixed> $input @param array<string, list<string>> $errors */
    private function fechaHora(array $input, string $field, array &$errors, bool $obligatoria): ?string
    {
        if (!$obligatoria && (!array_key_exists($field, $input) || $input[$field] === null || trim((string) $input[$field]) === '')) {
            return null;
        }
        $texto = trim((string) ($input[$field] ?? ''));
        if ($texto === '') {
            $errors[$field][] = 'La fecha de detección es obligatoria.';

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
            $errors[$field][] = 'La fecha de detección no es válida.';

            return null;
        }

        return $normalizada;
    }

    /** @param array<string, list<string>> $errors */
    private function finish(array $errors): void
    {
        if ($errors !== []) {
            throw new ValidationException('Los datos enviados no son válidos.', $errors);
        }
    }
}
