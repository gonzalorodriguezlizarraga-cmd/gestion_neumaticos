<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Http\Request;
use App\Repositories\AuditoriaRepository;
use App\Repositories\OrganizacionRepository;
use App\Support\ListCriteria;
use App\Support\Transaction;
use App\Validators\OrganizacionValidator;
use PDOException;

final class OrganizacionService
{
    public function __construct(
        private readonly ClienteService $clientes,
        private readonly OrganizacionRepository $repositorio,
        private readonly AuditoriaRepository $auditoria,
        private readonly ClientePolicy $policy,
        private readonly OrganizacionValidator $validator,
        private readonly Transaction $transaction,
        private readonly string $entidad,
        private readonly string $prefijo,
        private readonly bool $esFlota,
    ) {
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function listar(int $usuarioId, int $clienteId, array $query): array
    {
        $this->policy->exigirCliente($usuarioId, $clienteId);
        $this->clientes->filaVigente($clienteId);
        $criteria = ListCriteria::from($query, [
            'nombre' => 'nombre',
            'codigo' => 'codigo',
            'creado_en' => 'creado_en',
        ], null, true);

        return $this->repositorio->listar($clienteId, $criteria);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function crear(int $usuarioId, int $clienteId, array $input, Request $request): array
    {
        $cliente = $this->clientes->filaVigente($clienteId);
        $this->policy->exigirEstructura($usuarioId, $clienteId, (string) $cliente['estado'], true);
        $data = $this->datos($input);
        $this->verificarCodigo($clienteId, $data['codigo'], null);

        return $this->transaction->run(function () use ($usuarioId, $clienteId, $data, $request): array {
            try {
                $id = $this->repositorio->crear($clienteId, $data);
            } catch (PDOException $error) {
                $this->relanzarDuplicado($error);
            }
            $row = $this->exigir($clienteId, $id);
            $this->auditar($clienteId, $usuarioId, $id, $this->prefijo . '_CREATE', null, $row, $request);

            return $row;
        });
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function actualizar(int $usuarioId, int $clienteId, int $id, array $input, Request $request): array
    {
        $cliente = $this->clientes->filaVigente($clienteId);
        $this->policy->exigirEstructura($usuarioId, $clienteId, (string) $cliente['estado'], false);
        $antes = $this->exigir($clienteId, $id);
        $data = $this->datos($input);
        $this->verificarCodigo($clienteId, $data['codigo'], $id);

        return $this->transaction->run(function () use ($usuarioId, $clienteId, $id, $antes, $data, $request): array {
            try {
                $this->repositorio->actualizar($clienteId, $id, $data);
            } catch (PDOException $error) {
                $this->relanzarDuplicado($error);
            }
            $despues = $this->exigir($clienteId, $id);
            $this->auditar($clienteId, $usuarioId, $id, $this->prefijo . '_UPDATE', $antes, $despues, $request);

            return $despues;
        });
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function cambiarActivo(int $usuarioId, int $clienteId, int $id, array $input, Request $request): array
    {
        $cliente = $this->clientes->filaVigente($clienteId);
        $this->policy->exigirEstructura($usuarioId, $clienteId, (string) $cliente['estado'], false);
        $antes = $this->exigir($clienteId, $id);
        $activo = $this->validator->activo($input);

        return $this->transaction->run(function () use ($usuarioId, $clienteId, $id, $antes, $activo, $request): array {
            $this->repositorio->cambiarActivo($clienteId, $id, $activo);
            $despues = $this->exigir($clienteId, $id);
            $this->auditar($clienteId, $usuarioId, $id, $this->prefijo . '_ESTADO', $antes, $despues, $request);

            return $despues;
        });
    }

    /** @return array<string, mixed> */
    public function eliminar(int $usuarioId, int $clienteId, int $id, Request $request): array
    {
        $cliente = $this->clientes->filaVigente($clienteId);
        $this->policy->exigirEstructura($usuarioId, $clienteId, (string) $cliente['estado'], false);
        $antes = $this->exigir($clienteId, $id);

        return $this->transaction->run(function () use ($usuarioId, $clienteId, $id, $antes, $request): array {
            $this->repositorio->eliminar($clienteId, $id);
            $this->auditar($clienteId, $usuarioId, $id, $this->prefijo . '_DELETE', $antes, [
                'id' => $id,
                'eliminado' => true,
            ], $request);

            return ['id' => $id, 'eliminado' => true];
        });
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function datos(array $input): array
    {
        return $this->esFlota ? $this->validator->flota($input) : $this->validator->sede($input);
    }

    private function verificarCodigo(int $clienteId, ?string $codigo, ?int $exceptoId): void
    {
        if ($codigo === null) {
            return;
        }
        if ($this->repositorio->codigoOcupado($clienteId, $codigo, $exceptoId)) {
            throw new ConflictException('El código ya está registrado para este cliente.');
        }
    }

    /** @return array<string, mixed> */
    private function exigir(int $clienteId, int $id): array
    {
        $row = $this->repositorio->find($clienteId, $id);
        if ($row === null) {
            throw new NotFoundException($this->esFlota ? 'Flota no encontrada.' : 'Sede no encontrada.');
        }

        return $this->repositorio->presentar($row);
    }

    /** @param array<string, mixed>|null $antes @param array<string, mixed> $despues */
    private function auditar(int $clienteId, int $usuarioId, int $id, string $accion, ?array $antes, array $despues, Request $request): void
    {
        $this->auditoria->registrar(
            $clienteId,
            $usuarioId,
            $this->entidad,
            $id,
            $accion,
            $antes,
            $despues,
            $request->ip(),
            $request->userAgent(),
        );
    }

    /**
     * El UNIQUE (cliente_id, codigo) también cubre filas con eliminado = 1.
     * Reutilizar un código eliminado del mismo cliente responde 409.
     */
    private function relanzarDuplicado(PDOException $error): never
    {
        $state = (string) ($error->errorInfo[0] ?? '');
        if ($state === '23000' && str_contains($error->getMessage(), $this->repositorio->uniqueKey())) {
            throw new ConflictException('El código ya está registrado para este cliente.');
        }
        throw $error;
    }
}
