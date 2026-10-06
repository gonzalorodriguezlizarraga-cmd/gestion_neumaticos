<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Repositories\AuditoriaRepository;
use App\Repositories\MarcaNeumaticoRepository;
use App\Repositories\ModeloNeumaticoRepository;
use App\Support\ListCriteria;
use App\Support\Transaction;
use App\Validators\ModeloNeumaticoValidator;
use PDOException;

final class ModeloNeumaticoService
{
    public function __construct(
        private readonly ModeloNeumaticoRepository $modelos,
        private readonly MarcaNeumaticoRepository $marcas,
        private readonly AuditoriaRepository $auditoria,
        private readonly AuthorizationService $authorization,
        private readonly ModeloNeumaticoValidator $validator,
        private readonly Transaction $transaction,
    ) {
    }

    /** @param array<string, mixed> $query @return array{rows:list<array<string,mixed>>,total:int} */
    public function listar(int $usuarioId, array $query): array
    {
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::LECTURA);
        $marcaId = $this->filtroMarca($query);

        return $this->modelos->listar(ListCriteria::from($query, [
            'nombre' => 'mo.nombre',
            'creado_en' => 'mo.creado_en',
        ], null, true, true), $marcaId);
    }

    /** @return array<string, mixed> */
    public function obtener(int $usuarioId, int $id): array
    {
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::LECTURA);

        return $this->modelos->presentar($this->exigir($id));
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function crear(int $usuarioId, array $input, Request $request): array
    {
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::OPERACION);
        $data = $this->validator->crear($input);
        $this->exigirMarcaActiva($data['marca_id'], null);

        return $this->transaction->run(function () use ($usuarioId, $data, $request): array {
            try {
                $id = $this->modelos->crear($data);
            } catch (PDOException $error) {
                $this->relanzar($error);
            }
            $row = $this->modelos->presentar($this->exigir($id));
            $this->auditoria->registrar(null, $usuarioId, 'modelos_neumatico', $id, 'MODELO_NEUMATICO_CREATE', null, $row, $request->ip(), $request->userAgent());

            return $row;
        });
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function actualizar(int $usuarioId, int $id, array $input, Request $request): array
    {
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::OPERACION);
        $actual = $this->exigir($id);
        $antes = $this->modelos->presentar($actual);
        $data = $this->validator->actualizar($input);
        $this->exigirMarcaActiva($data['marca_id'], (int) $actual['marca_id']);

        return $this->transaction->run(function () use ($usuarioId, $id, $antes, $data, $request): array {
            try {
                $this->modelos->actualizar($id, $data);
            } catch (PDOException $error) {
                $this->relanzar($error);
            }
            $despues = $this->modelos->presentar($this->exigir($id));
            $this->auditoria->registrar(null, $usuarioId, 'modelos_neumatico', $id, 'MODELO_NEUMATICO_UPDATE', $antes, $despues, $request->ip(), $request->userAgent());

            return $despues;
        });
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function cambiarActivo(int $usuarioId, int $id, array $input, Request $request): array
    {
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::OPERACION);
        $antes = $this->modelos->presentar($this->exigir($id));
        $activo = $this->validator->activo($input);

        return $this->transaction->run(function () use ($usuarioId, $id, $antes, $activo, $request): array {
            $this->modelos->cambiarActivo($id, $activo);
            $despues = $this->modelos->presentar($this->exigir($id));
            $this->auditoria->registrar(null, $usuarioId, 'modelos_neumatico', $id, 'MODELO_NEUMATICO_ESTADO', $antes, $despues, $request->ip(), $request->userAgent());

            return $despues;
        });
    }

    /** @return array<string, mixed> */
    private function exigir(int $id): array
    {
        $row = $this->modelos->find($id);
        if ($row === null) {
            throw new NotFoundException('Modelo no encontrado.');
        }

        return $row;
    }

    private function exigirMarcaActiva(int $marcaId, ?int $actual): void
    {
        if ($actual === $marcaId) {
            return;
        }
        $marca = $this->marcas->find($marcaId);
        if ($marca === null) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'marca_id' => ['La marca no existe.'],
            ]);
        }
        if ((int) $marca['activo'] !== 1) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'marca_id' => ['La marca no está activa.'],
            ]);
        }
    }

    /** @param array<string, mixed> $query */
    private function filtroMarca(array $query): ?int
    {
        if (!array_key_exists('marca_id', $query) || trim((string) $query['marca_id']) === '') {
            return null;
        }
        $text = trim((string) $query['marca_id']);
        if (preg_match('/^[1-9][0-9]*$/', $text) !== 1 || $this->marcas->find((int) $text) === null) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'marca_id' => ['La marca no es válida.'],
            ]);
        }

        return (int) $text;
    }

    private function relanzar(PDOException $error): never
    {
        $state = (string) ($error->errorInfo[0] ?? '');
        if ($state === '23000' && str_contains($error->getMessage(), 'uk_modelos_neumatico_marca_nombre')) {
            throw new ConflictException('El nombre ya está registrado para esta marca.');
        }
        throw $error;
    }
}
