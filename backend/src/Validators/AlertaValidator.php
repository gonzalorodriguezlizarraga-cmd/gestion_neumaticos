<?php

declare(strict_types=1);

namespace App\Validators;

use App\Exceptions\ValidationException;
use App\Support\Fields;

final class AlertaValidator
{
    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function crear(array $input): array
    {
        foreach (['estado_id', 'estado', 'generada_automaticamente', 'fecha_generacion', 'inspeccion_id', 'inspeccion_detalle_id'] as $campo) {
            if (array_key_exists($campo, $input)) {
                throw new ValidationException('Los datos enviados no son válidos.', [
                    $campo => ['El origen, el estado y la fecha los define el sistema.'],
                ]);
            }
        }
        $errors = [];
        $data = [
            'cliente_id' => $this->id($input, 'cliente_id', $errors, 'El cliente'),
            'tipo_alerta_id' => $this->id($input, 'tipo_alerta_id', $errors, 'El tipo de alerta'),
            'nivel' => $this->nivel($input, $errors),
            'unidad_id' => $this->opcional($input, 'unidad_id', $errors, 'La unidad'),
            'neumatico_id' => $this->opcional($input, 'neumatico_id', $errors, 'El neumático'),
            'titulo' => Fields::requiredText($input, 'titulo', 180, $errors, 'El título'),
            'descripcion' => Fields::requiredText($input, 'descripcion', 4000, $errors, 'La descripción'),
            'recomendacion' => Fields::optionalText($input, 'recomendacion', 4000, $errors, 'La recomendación'),
        ];
        $this->finish($errors);

        return $data;
    }

    /** @param array<string, mixed> $input @return array{observacion:?string} */
    public function tomarAtencion(array $input): array
    {
        $errors = [];
        $data = ['observacion' => Fields::optionalText($input, 'observacion', 500, $errors, 'La observación')];
        $this->finish($errors);

        return $data;
    }

    /** @param array<string, mixed> $input @return array{observacion:string} */
    public function cerrar(array $input): array
    {
        $errors = [];
        $observacion = Fields::requiredText($input, 'observacion', 500, $errors, 'La observación');
        $this->finish($errors);

        return ['observacion' => (string) $observacion];
    }

    /** @param array<string, mixed> $input @param array<string, list<string>> $errors */
    private function nivel(array $input, array &$errors): string
    {
        $nivel = strtoupper(trim((string) ($input['nivel'] ?? '')));
        if (!in_array($nivel, ['INFORMATIVA', 'ATENCION', 'CRITICA'], true)) {
            $errors['nivel'][] = 'El nivel debe ser informativa, atención o crítica.';

            return '';
        }

        return $nivel;
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
    private function opcional(array $input, string $field, array &$errors, string $label): ?int
    {
        if (!array_key_exists($field, $input) || $input[$field] === null || trim((string) $input[$field]) === '') {
            return null;
        }
        $texto = trim((string) $input[$field]);
        if (preg_match('/^[1-9][0-9]*$/', $texto) !== 1) {
            $errors[$field][] = $label . ' no es válida.';

            return null;
        }

        return (int) $texto;
    }

    /** @param array<string, list<string>> $errors */
    private function finish(array $errors): void
    {
        if ($errors !== []) {
            throw new ValidationException('Los datos enviados no son válidos.', $errors);
        }
    }
}
