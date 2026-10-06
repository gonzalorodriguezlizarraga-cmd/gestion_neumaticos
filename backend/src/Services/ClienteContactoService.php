<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\NotFoundException;
use App\Http\Request;
use App\Repositories\AuditoriaRepository;
use App\Repositories\ClienteContactoRepository;
use App\Support\ListCriteria;
use App\Support\Transaction;
use App\Validators\ContactoValidator;

final class ClienteContactoService
{
    public function __construct(
        private readonly ClienteService $clientes,
        private readonly ClienteContactoRepository $contactos,
        private readonly AuditoriaRepository $auditoria,
        private readonly ClientePolicy $policy,
        private readonly ContactoValidator $validator,
        private readonly Transaction $transaction,
    ) {
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function listar(int $usuarioId, int $clienteId, array $query): array
    {
        $this->policy->exigirCliente($usuarioId, $clienteId);
        $this->clientes->filaVigente($clienteId);
        $criteria = ListCriteria::from($query, [
            'nombre' => 'nombre',
            'principal' => 'principal',
            'creado_en' => 'creado_en',
        ], null, true);

        return $this->contactos->listar($clienteId, $criteria);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function crear(int $usuarioId, int $clienteId, array $input, Request $request): array
    {
        $this->policy->exigirContactos($usuarioId, $clienteId);
        $this->clientes->filaVigente($clienteId);
        $data = $this->validator->guardar($input);

        return $this->transaction->run(function () use ($usuarioId, $clienteId, $data, $request): array {
            $id = $this->contactos->crear($clienteId, $data);
            $row = $this->exigir($clienteId, $id);
            $this->auditar($clienteId, $usuarioId, $id, 'CONTACTO_CREATE', null, $row, $request);

            return $row;
        });
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function actualizar(int $usuarioId, int $clienteId, int $id, array $input, Request $request): array
    {
        $this->policy->exigirContactos($usuarioId, $clienteId);
        $this->clientes->filaVigente($clienteId);
        $antes = $this->exigir($clienteId, $id);
        $data = $this->validator->guardar($input);

        return $this->transaction->run(function () use ($usuarioId, $clienteId, $id, $antes, $data, $request): array {
            $this->contactos->actualizar($clienteId, $id, $data);
            $despues = $this->exigir($clienteId, $id);
            $this->auditar($clienteId, $usuarioId, $id, 'CONTACTO_UPDATE', $antes, $despues, $request);

            return $despues;
        });
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function cambiarActivo(int $usuarioId, int $clienteId, int $id, array $input, Request $request): array
    {
        $this->policy->exigirContactos($usuarioId, $clienteId);
        $this->clientes->filaVigente($clienteId);
        $antes = $this->exigir($clienteId, $id);
        $activo = $this->validator->activo($input);

        return $this->transaction->run(function () use ($usuarioId, $clienteId, $id, $antes, $activo, $request): array {
            $this->contactos->cambiarActivo($clienteId, $id, $activo);
            $despues = $this->exigir($clienteId, $id);
            $this->auditar($clienteId, $usuarioId, $id, 'CONTACTO_ESTADO', $antes, $despues, $request);

            return $despues;
        });
    }

    /** @return array<string, mixed> */
    private function exigir(int $clienteId, int $id): array
    {
        $row = $this->contactos->find($clienteId, $id);
        if ($row === null) {
            throw new NotFoundException('Contacto no encontrado.');
        }

        return $this->contactos->presentar($row);
    }

    /** @param array<string, mixed>|null $antes @param array<string, mixed> $despues */
    private function auditar(int $clienteId, int $usuarioId, int $id, string $accion, ?array $antes, array $despues, Request $request): void
    {
        $this->auditoria->registrar(
            $clienteId,
            $usuarioId,
            'cliente_contactos',
            $id,
            $accion,
            $antes,
            $despues,
            $request->ip(),
            $request->userAgent(),
        );
    }
}
