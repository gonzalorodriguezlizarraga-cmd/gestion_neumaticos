<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Http\Request;
use App\Repositories\AuditoriaRepository;
use App\Repositories\MedidaNeumaticoRepository;
use App\Support\ListCriteria;
use App\Support\Transaction;
use App\Validators\MedidaNeumaticoValidator;
use PDOException;

final class MedidaNeumaticoService
{
    public function __construct(
        private readonly MedidaNeumaticoRepository $medidas,
        private readonly AuditoriaRepository $auditoria,
        private readonly AuthorizationService $authorization,
        private readonly MedidaNeumaticoValidator $validator,
        private readonly Transaction $transaction,
    ) {
    }

    /** @param array<string, mixed> $query @return array{rows:list<array<string,mixed>>,total:int} */
    public function listar(int $usuarioId, array $query): array
    {
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::LECTURA);

        return $this->medidas->listar(ListCriteria::from($query, [
            'descripcion' => 'descripcion',
            'creado_en' => 'creado_en',
        ], null, true, true));
    }

    /** @return array<string, mixed> */
    public function obtener(int $usuarioId, int $id): array
    {
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::LECTURA);

        return $this->medidas->presentar($this->exigir($id));
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function crear(int $usuarioId, array $input, Request $request): array
    {
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::OPERACION);
        $data = $this->validator->crear($input);

        return $this->transaction->run(function () use ($usuarioId, $data, $request): array {
            try {
                $id = $this->medidas->crear($data);
            } catch (PDOException $error) {
                $this->relanzar($error);
            }
            $row = $this->medidas->presentar($this->exigir($id));
            $this->auditoria->registrar(null, $usuarioId, 'medidas_neumatico', $id, 'MEDIDA_NEUMATICO_CREATE', null, $row, $request->ip(), $request->userAgent());

            return $row;
        });
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function actualizar(int $usuarioId, int $id, array $input, Request $request): array
    {
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::OPERACION);
        $antes = $this->medidas->presentar($this->exigir($id));
        $data = $this->validator->actualizar($input);

        return $this->transaction->run(function () use ($usuarioId, $id, $antes, $data, $request): array {
            try {
                $this->medidas->actualizar($id, $data);
            } catch (PDOException $error) {
                $this->relanzar($error);
            }
            $despues = $this->medidas->presentar($this->exigir($id));
            $this->auditoria->registrar(null, $usuarioId, 'medidas_neumatico', $id, 'MEDIDA_NEUMATICO_UPDATE', $antes, $despues, $request->ip(), $request->userAgent());

            return $despues;
        });
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function cambiarActivo(int $usuarioId, int $id, array $input, Request $request): array
    {
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::OPERACION);
        $antes = $this->medidas->presentar($this->exigir($id));
        $activo = $this->validator->activo($input);

        return $this->transaction->run(function () use ($usuarioId, $id, $antes, $activo, $request): array {
            $this->medidas->cambiarActivo($id, $activo);
            $despues = $this->medidas->presentar($this->exigir($id));
            $this->auditoria->registrar(null, $usuarioId, 'medidas_neumatico', $id, 'MEDIDA_NEUMATICO_ESTADO', $antes, $despues, $request->ip(), $request->userAgent());

            return $despues;
        });
    }

    /** @return array<string, mixed> */
    private function exigir(int $id): array
    {
        $row = $this->medidas->find($id);
        if ($row === null) {
            throw new NotFoundException('Medida no encontrada.');
        }

        return $row;
    }

    private function relanzar(PDOException $error): never
    {
        $state = (string) ($error->errorInfo[0] ?? '');
        if ($state === '23000' && str_contains($error->getMessage(), 'uk_medidas_neumatico_descripcion')) {
            throw new ConflictException('La descripción de la medida ya está registrada.');
        }
        throw $error;
    }
}
