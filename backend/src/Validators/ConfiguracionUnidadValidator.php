<?php

declare(strict_types=1);

namespace App\Validators;

use App\Exceptions\ValidationException;
use App\Support\Fields;

final class ConfiguracionUnidadValidator
{
    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function estructura(array $input): array
    {
        $errors = [];
        $this->rechazarCalculados($input, $errors);
        $nombre = Fields::requiredText($input, 'nombre', 160, $errors, 'El nombre');
        $descripcion = Fields::optionalText($input, 'descripcion', 255, $errors, 'La descripción');
        $ejes = $this->ejes($input['ejes'] ?? null, $errors);
        $this->finish($errors);

        $posiciones = 0;
        foreach ($ejes as $eje) {
            foreach ($eje['posiciones'] as $posicion) {
                if ($posicion['activo']) {
                    $posiciones++;
                }
            }
        }
        if ($posiciones < 1) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'ejes' => ['La configuración debe tener al menos una posición activa.'],
            ]);
        }

        return [
            'nombre' => $nombre,
            'descripcion' => $descripcion,
            'cantidad_ejes' => count($ejes),
            'cantidad_posiciones' => $posiciones,
            'ejes' => $ejes,
        ];
    }

    /** @param array<string, mixed> $input @return array{nombre:string,descripcion:?string} */
    public function descriptivo(array $input): array
    {
        $errors = [];
        $this->rechazarCalculados($input, $errors);
        $data = [
            'nombre' => Fields::requiredText($input, 'nombre', 160, $errors, 'El nombre'),
            'descripcion' => Fields::optionalText($input, 'descripcion', 255, $errors, 'La descripción'),
        ];
        $this->finish($errors);

        return $data;
    }

    /** @param array<string, mixed> $input */
    public function nombreCopia(array $input): string
    {
        $errors = [];
        $nombre = Fields::requiredText($input, 'nombre', 160, $errors, 'El nombre');
        $this->finish($errors);

        return (string) $nombre;
    }

    /** @param array<string, mixed> $input */
    public function activo(array $input): bool
    {
        $errors = [];
        if (!array_key_exists('activo', $input)) {
            $errors['activo'][] = 'El indicador activo es obligatorio.';
        }
        $value = Fields::bool($input, 'activo', $errors, false, 'El indicador activo');
        $this->finish($errors);

        return $value;
    }

    /** @param array<string, mixed> $input @param array<string, list<string>> $errors */
    private function rechazarCalculados(array $input, array &$errors): void
    {
        foreach (['cantidad_ejes', 'cantidad_posiciones', 'id', 'activo', 'creado_en', 'actualizado_en'] as $field) {
            if (array_key_exists($field, $input)) {
                $errors[$field][] = 'Este campo no se envía. La estructura lo determina.';
            }
        }
    }

    /**
     * @param array<string, list<string>> $errors
     * @return list<array<string, mixed>>
     */
    private function ejes(mixed $value, array &$errors): array
    {
        if (!is_array($value) || $value === [] || array_is_list($value) === false) {
            $errors['ejes'][] = 'Debe incluir al menos un eje.';
            return [];
        }
        $ejes = [];
        $numeros = [];
        $ordenes = [];
        $codigos = [];
        $ordenesPosicion = [];
        foreach ($value as $index => $eje) {
            if (!is_array($eje)) {
                $errors['ejes'][] = 'Cada eje debe ser un objeto.';
                continue;
            }
            $numero = $this->positivo($eje, 'numero_eje', $errors, 'ejes.' . $index . '.numero_eje', 'El número de eje');
            $orden = $this->positivo($eje, 'orden', $errors, 'ejes.' . $index . '.orden', 'El orden del eje');
            $nombre = Fields::requiredText($eje, 'nombre', 100, $errors, 'El nombre del eje');
            if ($numero !== null && isset($numeros[$numero])) {
                $errors['ejes.' . $index . '.numero_eje'][] = 'El número de eje está repetido.';
            }
            if ($orden !== null && isset($ordenes[$orden])) {
                $errors['ejes.' . $index . '.orden'][] = 'El orden del eje está repetido.';
            }
            if ($numero !== null) {
                $numeros[$numero] = true;
            }
            if ($orden !== null) {
                $ordenes[$orden] = true;
            }
            $posiciones = $this->posiciones($eje['posiciones'] ?? null, $index, $codigos, $ordenesPosicion, $errors);
            if ($numero === null || $orden === null || $nombre === null) {
                continue;
            }
            $ejes[] = [
                'numero_eje' => $numero,
                'nombre' => $nombre,
                'orden' => $orden,
                'posiciones' => $posiciones,
            ];
        }

        return $ejes;
    }

    /**
     * @param array<int, true> $codigos
     * @param array<int, true> $ordenes
     * @param array<string, list<string>> $errors
     * @return list<array<string, mixed>>
     */
    private function posiciones(mixed $value, int $ejeIndex, array &$codigos, array &$ordenes, array &$errors): array
    {
        $key = 'ejes.' . $ejeIndex . '.posiciones';
        if (!is_array($value) || $value === [] || array_is_list($value) === false) {
            $errors[$key][] = 'Cada eje debe incluir al menos una posición.';
            return [];
        }
        $posiciones = [];
        foreach ($value as $index => $posicion) {
            if (!is_array($posicion)) {
                $errors[$key][] = 'Cada posición debe ser un objeto.';
                continue;
            }
            $path = $key . '.' . $index;
            $codigo = Fields::requiredText($posicion, 'codigo', 20, $errors, 'El código de posición');
            if ($codigo !== null && preg_match('/^[A-Za-z0-9._\-]+$/', $codigo) !== 1) {
                $errors[$path . '.codigo'][] = 'El código de posición solo admite letras, números, punto, guion y guion bajo.';
                $codigo = null;
            }
            $lado = $this->enum($posicion, 'lado', ['IZQUIERDO', 'DERECHO', 'CENTRO'], $errors, $path . '.lado', 'El lado');
            $ubicacion = $this->enum($posicion, 'ubicacion', ['INTERIOR', 'EXTERIOR', 'SIMPLE', 'CENTRAL'], $errors, $path . '.ubicacion', 'La ubicación');
            $orden = $this->positivo($posicion, 'orden', $errors, $path . '.orden', 'El orden de la posición');
            $activo = Fields::bool($posicion, 'activo', $errors, true, 'El indicador activo');
            if ($codigo !== null && isset($codigos[$codigo])) {
                $errors[$path . '.codigo'][] = 'El código de posición está repetido en la configuración.';
            }
            if ($orden !== null && isset($ordenes[$orden])) {
                $errors[$path . '.orden'][] = 'El orden de la posición está repetido en la configuración.';
            }
            if ($codigo !== null) {
                $codigos[$codigo] = true;
            }
            if ($orden !== null) {
                $ordenes[$orden] = true;
            }
            if ($codigo === null || $lado === null || $ubicacion === null || $orden === null) {
                continue;
            }
            $posiciones[] = [
                'codigo' => $codigo,
                'lado' => $lado,
                'ubicacion' => $ubicacion,
                'orden' => $orden,
                'activo' => $activo,
            ];
        }

        return $posiciones;
    }

    /** @param array<string, mixed> $input @param list<string> $allowed @param array<string, list<string>> $errors */
    private function enum(array $input, string $field, array $allowed, array &$errors, string $path, string $label): ?string
    {
        $value = strtoupper(trim((string) ($input[$field] ?? '')));
        if ($value === '' || !in_array($value, $allowed, true)) {
            $errors[$path][] = $label . ' no es válido.';
            return null;
        }

        return $value;
    }

    /** @param array<string, mixed> $input @param array<string, list<string>> $errors */
    private function positivo(array $input, string $field, array &$errors, string $path, string $label): ?int
    {
        $raw = $input[$field] ?? null;
        if (is_int($raw) && $raw > 0 && $raw <= 65535) {
            return $raw;
        }
        $text = trim((string) $raw);
        if (preg_match('/^[1-9][0-9]*$/', $text) !== 1) {
            $errors[$path][] = $label . ' debe ser un entero mayor que cero.';
            return null;
        }
        $number = (int) $text;
        if ($number > 65535) {
            $errors[$path][] = $label . ' supera el máximo permitido.';
            return null;
        }

        return $number;
    }

    /** @param array<string, list<string>> $errors */
    private function finish(array $errors): void
    {
        if ($errors !== []) {
            throw new ValidationException('Los datos enviados no son válidos.', $errors);
        }
    }
}
