<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Repositories\AuditoriaRepository;
use App\Repositories\ClienteRepository;
use App\Repositories\ClienteResponsableRepository;
use App\Repositories\UsuarioRepository;
use App\Support\ListCriteria;
use App\Support\Transaction;
use App\Validators\ResponsableValidator;

final class ClienteResponsableService
{
    public function __construct(
        private readonly ClienteService $clientes,
        private readonly ClienteRepository $clienteRepository,
        private readonly ClienteResponsableRepository $responsables,
        private readonly UsuarioRepository $usuarios,
        private readonly AuditoriaRepository $auditoria,
        private readonly ClientePolicy $policy,
        private readonly AuthorizationService $authorization,
        private readonly ResponsableValidator $validator,
        private readonly Transaction $transaction,
    ) {
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function listar(int $usuarioId, int $clienteId, array $query): array
    {
        $this->policy->exigirCliente($usuarioId, $clienteId);
        $this->clientes->filaVigente($clienteId);
        $criteria = ListCriteria::from($query, [
            'fecha_inicio' => 'r.fecha_inicio',
            'tipo_responsabilidad' => 'r.tipo_responsabilidad',
            'id' => 'r.id',
        ]);

        return $this->responsables->listar($clienteId, $criteria, $this->clienteRepository->hoy());
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function crear(int $usuarioId, int $clienteId, array $input, Request $request): array
    {
        $this->policy->exigirResponsables($usuarioId, $clienteId);
        $cliente = $this->clientes->filaVigente($clienteId);
        $data = $this->validator->guardar($input);
        $this->policy->exigirNuevaAsignacion((string) $cliente['estado'], $data['tipo_responsabilidad']);
        $this->validarUsuario($data['usuario_id'], $data['tipo_responsabilidad']);

        return $this->transaction->run(function () use ($usuarioId, $clienteId, $data, $request): array {
            $id = $this->responsables->crear($clienteId, $data, $usuarioId);
            $row = $this->exigir($clienteId, $id);
            $this->auditar($clienteId, $usuarioId, $id, 'RESPONSABLE_CREATE', null, $row, $request);

            return $row;
        });
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function actualizar(int $usuarioId, int $clienteId, int $id, array $input, Request $request): array
    {
        $this->policy->exigirResponsables($usuarioId, $clienteId);
        $this->clientes->filaVigente($clienteId);
        $antes = $this->exigir($clienteId, $id);
        $data = $this->validator->guardar($input);
        $this->validarUsuario($data['usuario_id'], $data['tipo_responsabilidad']);

        return $this->transaction->run(function () use ($usuarioId, $clienteId, $id, $antes, $data, $request): array {
            $this->responsables->actualizar($clienteId, $id, $data);
            $despues = $this->exigir($clienteId, $id);
            $this->auditar($clienteId, $usuarioId, $id, 'RESPONSABLE_UPDATE', $antes, $despues, $request);

            return $despues;
        });
    }

    /** @return array<string, mixed> */
    public function cerrar(int $usuarioId, int $clienteId, int $id, Request $request): array
    {
        $this->policy->exigirResponsables($usuarioId, $clienteId);
        $this->clientes->filaVigente($clienteId);
        $crudo = $this->responsables->find($clienteId, $id);
        if ($crudo === null) {
            throw new NotFoundException('Responsable no encontrado.');
        }
        $hoy = $this->clienteRepository->hoy();
        $inicio = (string) $crudo['fecha_inicio'];
        $fin = $crudo['fecha_fin'] !== null ? (string) $crudo['fecha_fin'] : null;
        if ($fin !== null && $fin < $hoy) {
            throw new ConflictException('La asignación ya está cerrada.');
        }

        return $this->transaction->run(function () use ($usuarioId, $clienteId, $id, $crudo, $inicio, $hoy, $request): array {
            $antes = $this->responsables->presentar($crudo, $hoy);
            if ($inicio > $hoy) {
                $this->auditar($clienteId, $usuarioId, $id, 'RESPONSABLE_END', $antes, [
                    'id' => $id,
                    'cancelada' => true,
                ], $request);
                $this->responsables->eliminarFisico($clienteId, $id);

                return [
                    'id' => $id,
                    'cancelada' => true,
                ];
            }
            // La columna es DATE. fecha_fin = hoy sigue vigente el resto del día
            // porque la regla de vigencia exige fecha_fin >= CURRENT_DATE.
            $cierre = $hoy;
            $this->responsables->cerrar($clienteId, $id, $cierre);
            $despues = $this->exigir($clienteId, $id);
            $this->auditar($clienteId, $usuarioId, $id, 'RESPONSABLE_END', $antes, $despues, $request);

            return $despues;
        });
    }

    private function validarUsuario(int $usuarioId, string $tipo): void
    {
        $usuario = $this->usuarios->findById($usuarioId);
        if ($usuario === null || (string) $usuario['estado'] !== 'ACTIVO' || (int) $usuario['eliminado'] !== 0) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'usuario_id' => ['El usuario no existe o no está activo.'],
            ]);
        }
        $valido = $tipo === 'TECNICO'
            ? $this->authorization->hasRole($usuarioId, 'TECNICO_INSPECCION', 'GESTOR_NEUMATICOS')
            : $this->authorization->hasRole($usuarioId, 'VENDEDOR');
        if (!$valido) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'usuario_id' => [$tipo === 'TECNICO'
                    ? 'El responsable técnico debe tener rol TECNICO_INSPECCION o GESTOR_NEUMATICOS.'
                    : 'El responsable comercial debe tener rol VENDEDOR.'],
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function exigir(int $clienteId, int $id): array
    {
        $row = $this->responsables->find($clienteId, $id);
        if ($row === null) {
            throw new NotFoundException('Responsable no encontrado.');
        }

        return $this->responsables->presentar($row, $this->clienteRepository->hoy());
    }

    /** @param array<string, mixed>|null $antes @param array<string, mixed> $despues */
    private function auditar(int $clienteId, int $usuarioId, int $id, string $accion, ?array $antes, array $despues, Request $request): void
    {
        $this->auditoria->registrar(
            $clienteId,
            $usuarioId,
            'cliente_responsables',
            $id,
            $accion,
            $antes,
            $despues,
            $request->ip(),
            $request->userAgent(),
        );
    }
}
