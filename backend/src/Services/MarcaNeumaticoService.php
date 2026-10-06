<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Http\Request;
use App\Repositories\AuditoriaRepository;
use App\Repositories\MarcaNeumaticoRepository;
use App\Support\ListCriteria;
use App\Support\Transaction;
use App\Validators\MarcaNeumaticoValidator;
use PDOException;

final class MarcaNeumaticoService
{
    public function __construct(
        private readonly MarcaNeumaticoRepository $marcas,
        private readonly AuditoriaRepository $auditoria,
        private readonly AuthorizationService $authorization,
        private readonly MarcaNeumaticoValidator $validator,
        private readonly Transaction $transaction,
    ) {
    }

    /** @param array<string, mixed> $query @return array{rows:list<array<string,mixed>>,total:int} */
    public function listar(int $usuarioId, array $query): array
    {
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::LECTURA);

        return $this->marcas->listar(ListCriteria::from($query, [
            'nombre' => 'nombre',
            'creado_en' => 'creado_en',
        ], null, true, true));
    }

    /** @return array<string, mixed> */
    public function obtener(int $usuarioId, int $id): array
    {
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::LECTURA);

        return $this->marcas->presentar($this->exigir($id));
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function crear(int $usuarioId, array $input, Request $request): array
    {
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::OPERACION);
        $data = $this->validator->crear($input);

        return $this->transaction->run(function () use ($usuarioId, $data, $request): array {
            try {
                $id = $this->marcas->crear($data);
            } catch (PDOException $error) {
                $this->relanzar($error, 'uk_marcas_neumatico_nombre', 'El nombre de marca ya está registrado.');
            }
            $row = $this->marcas->presentar($this->exigir($id));
            $this->auditoria->registrar(null, $usuarioId, 'marcas_neumatico', $id, 'MARCA_NEUMATICO_CREATE', null, $row, $request->ip(), $request->userAgent());

            return $row;
        });
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function actualizar(int $usuarioId, int $id, array $input, Request $request): array
    {
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::OPERACION);
        $antes = $this->marcas->presentar($this->exigir($id));
        $data = $this->validator->actualizar($input);

        return $this->transaction->run(function () use ($usuarioId, $id, $antes, $data, $request): array {
            try {
                $this->marcas->actualizar($id, $data);
            } catch (PDOException $error) {
                $this->relanzar($error, 'uk_marcas_neumatico_nombre', 'El nombre de marca ya está registrado.');
            }
            $despues = $this->marcas->presentar($this->exigir($id));
            $this->auditoria->registrar(null, $usuarioId, 'marcas_neumatico', $id, 'MARCA_NEUMATICO_UPDATE', $antes, $despues, $request->ip(), $request->userAgent());

            return $despues;
        });
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function cambiarActivo(int $usuarioId, int $id, array $input, Request $request): array
    {
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::OPERACION);
        $antes = $this->marcas->presentar($this->exigir($id));
        $activo = $this->validator->activo($input);

        return $this->transaction->run(function () use ($usuarioId, $id, $antes, $activo, $request): array {
            $this->marcas->cambiarActivo($id, $activo);
            $despues = $this->marcas->presentar($this->exigir($id));
            $this->auditoria->registrar(null, $usuarioId, 'marcas_neumatico', $id, 'MARCA_NEUMATICO_ESTADO', $antes, $despues, $request->ip(), $request->userAgent());

            return $despues;
        });
    }

    /** @return array<string, mixed> */
    private function exigir(int $id): array
    {
        $row = $this->marcas->find($id);
        if ($row === null) {
            throw new NotFoundException('Marca no encontrada.');
        }

        return $row;
    }

    private function relanzar(PDOException $error, string $clave, string $mensaje): never
    {
        $state = (string) ($error->errorInfo[0] ?? '');
        if ($state === '23000' && str_contains($error->getMessage(), $clave)) {
            throw new ConflictException($mensaje);
        }
        throw $error;
    }
}
