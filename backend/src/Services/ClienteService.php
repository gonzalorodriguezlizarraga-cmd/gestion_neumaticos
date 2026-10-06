<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AuthorizationException;
use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Http\Request;
use App\Repositories\AuditoriaRepository;
use App\Repositories\ClienteRepository;
use App\Support\ListCriteria;
use App\Support\Transaction;
use App\Validators\ClienteValidator;
use PDOException;

final class ClienteService
{
    /** @var array<string, list<string>> */
    private const TRANSICIONES = [
        'POTENCIAL' => ['ACTIVO', 'INACTIVO'],
        'ACTIVO' => ['INACTIVO'],
        'INACTIVO' => ['ACTIVO'],
    ];

    /** @var list<string> */
    private const CAMPOS_GESTOR = [
        'nombre_comercial',
        'direccion',
        'telefono',
        'email',
        'observacion',
    ];

    public function __construct(
        private readonly ClienteRepository $clientes,
        private readonly AuditoriaRepository $auditoria,
        private readonly ClientePolicy $policy,
        private readonly ClienteValidator $validator,
        private readonly Transaction $transaction,
    ) {
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function listar(int $usuarioId, array $query): array
    {
        $this->policy->exigirLectura($usuarioId);
        $criteria = ListCriteria::from($query, [
            'razon_social' => 'c.razon_social',
            'nombre_comercial' => 'c.nombre_comercial',
            'ruc_documento' => 'c.ruc_documento',
            'estado' => 'c.estado',
            'creado_en' => 'c.creado_en',
        ], ['POTENCIAL', 'ACTIVO', 'INACTIVO'], false, true);
        $alcance = $this->policy->esAdmin($usuarioId) ? null : $usuarioId;

        return $this->clientes->listar($criteria, $alcance);
    }

    /** @return array<string, mixed> */
    public function obtener(int $usuarioId, int $clienteId): array
    {
        $this->policy->exigirCliente($usuarioId, $clienteId);

        return $this->presentarVigente($clienteId);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function crear(int $usuarioId, array $input, Request $request): array
    {
        $this->policy->exigirCrear($usuarioId);
        $data = $this->validator->crear($input);
        if ($this->clientes->rucOcupado($data['ruc_documento'])) {
            throw new ConflictException('El documento ya está registrado.');
        }

        return $this->transaction->run(function () use ($usuarioId, $data, $request): array {
            try {
                $id = $this->clientes->crear($data, $usuarioId);
            } catch (PDOException $error) {
                $this->relanzarDuplicado($error, 'uk_clientes_ruc', 'El documento ya está registrado.');
            }
            $row = $this->clientes->find($id);
            $publico = $this->clientes->presentar($row ?? []);
            $this->auditar($id, $usuarioId, 'CLIENTE_CREATE', null, $publico, $request);

            return $publico;
        });
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function actualizar(int $usuarioId, int $clienteId, array $input, Request $request): array
    {
        $this->policy->exigirEdicion($usuarioId, $clienteId);
        $actual = $this->filaVigente($clienteId);
        $cambios = $this->cambiosPermitidos($usuarioId, $actual, $this->validator->actualizar($input));
        if (array_key_exists('ruc_documento', $cambios) && $this->clientes->rucOcupado($cambios['ruc_documento'], $clienteId)) {
            throw new ConflictException('El documento ya está registrado.');
        }

        return $this->transaction->run(function () use ($usuarioId, $clienteId, $actual, $cambios, $request): array {
            $antes = $this->clientes->presentar($actual);
            try {
                $this->clientes->actualizar($clienteId, $cambios, $usuarioId);
            } catch (PDOException $error) {
                $this->relanzarDuplicado($error, 'uk_clientes_ruc', 'El documento ya está registrado.');
            }
            $despues = $this->presentarVigente($clienteId);
            $this->auditar($clienteId, $usuarioId, 'CLIENTE_UPDATE', $antes, $despues, $request);

            return $despues;
        });
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function cambiarEstado(int $usuarioId, int $clienteId, array $input, Request $request): array
    {
        $this->policy->exigirEstado($usuarioId, $clienteId);
        $destino = $this->validator->estado($input);
        $actual = $this->filaVigente($clienteId);
        $origen = (string) $actual['estado'];
        if ($origen === $destino) {
            throw new ConflictException('El cliente ya tiene ese estado.');
        }
        $permitidos = self::TRANSICIONES[$origen] ?? [];
        if (!in_array($destino, $permitidos, true)) {
            throw new ConflictException('La transición de estado no está permitida.');
        }

        return $this->transaction->run(function () use ($usuarioId, $clienteId, $actual, $destino, $request): array {
            $antes = $this->clientes->presentar($actual);
            $this->clientes->actualizar($clienteId, ['estado' => $destino], $usuarioId);
            $despues = $this->presentarVigente($clienteId);
            $this->auditar($clienteId, $usuarioId, 'CLIENTE_ESTADO', $antes, $despues, $request);

            return $despues;
        });
    }

    /** @return array<string, mixed> */
    public function eliminar(int $usuarioId, int $clienteId, Request $request): array
    {
        $this->policy->exigirEliminar($usuarioId, $clienteId);
        $actual = $this->filaVigente($clienteId);

        return $this->transaction->run(function () use ($usuarioId, $clienteId, $actual, $request): array {
            $antes = $this->clientes->presentar($actual);
            $dependencias = $this->clientes->dependencias($clienteId);
            $this->clientes->eliminar($clienteId, $usuarioId);
            $this->auditar($clienteId, $usuarioId, 'CLIENTE_DELETE', $antes, [
                'id' => $clienteId,
                'eliminado' => true,
                'dependencias' => $dependencias,
            ], $request);

            return [
                'id' => $clienteId,
                'eliminado' => true,
            ];
        });
    }

    /** @return array<string, mixed> */
    public function filaVigente(int $clienteId): array
    {
        $row = $this->clientes->find($clienteId);
        if ($row === null || (int) $row['eliminado'] === 1) {
            throw new NotFoundException('Cliente no encontrado.');
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private function presentarVigente(int $clienteId): array
    {
        return $this->clientes->presentar($this->filaVigente($clienteId));
    }

    /**
     * @param array<string, mixed> $actual
     * @param array<string, mixed> $cambios
     * @return array<string, mixed>
     */
    private function cambiosPermitidos(int $usuarioId, array $actual, array $cambios): array
    {
        if ($this->policy->esAdmin($usuarioId)) {
            return $cambios;
        }
        foreach ($cambios as $campo => $valor) {
            if (!in_array($campo, self::CAMPOS_GESTOR, true)) {
                $anterior = $actual[$campo] ?? null;
                if ($this->normalizar($anterior) !== $this->normalizar($valor)) {
                    throw new AuthorizationException('No puede modificar ' . $campo . '.');
                }
                unset($cambios[$campo]);
            }
        }

        return $cambios;
    }

    private function normalizar(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }
        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }

    /** @param array<string, mixed>|null $antes @param array<string, mixed>|null $despues */
    private function auditar(int $clienteId, int $usuarioId, string $accion, ?array $antes, ?array $despues, Request $request): void
    {
        $this->auditoria->registrar(
            $clienteId,
            $usuarioId,
            'clientes',
            $clienteId,
            $accion,
            $antes,
            $despues,
            $request->ip(),
            $request->userAgent(),
        );
    }

    /**
     * uk_clientes_ruc también cubre filas con eliminado = 1.
     * Reutilizar un documento de un cliente eliminado responde 409 hasta una revisión futura.
     */
    private function relanzarDuplicado(PDOException $error, string $constraint, string $message): never
    {
        $state = (string) ($error->errorInfo[0] ?? '');
        if ($state === '23000' && str_contains($error->getMessage(), $constraint)) {
            throw new ConflictException($message);
        }
        throw $error;
    }
}
