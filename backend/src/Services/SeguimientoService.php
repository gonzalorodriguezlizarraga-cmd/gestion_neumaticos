<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AuthorizationException;
use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Http\Request;
use App\Repositories\AuditoriaRepository;
use App\Repositories\OportunidadRepository;
use App\Repositories\SeguimientoRepository;
use App\Support\ListCriteria;
use App\Support\Transaction;
use App\Validators\SeguimientoValidator;

final class SeguimientoService
{
    public function __construct(
        private readonly SeguimientoRepository $seguimientos,
        private readonly OportunidadRepository $oportunidades,
        private readonly AuditoriaRepository $auditoria,
        private readonly AuthorizationService $authorization,
        private readonly ComercialAcceso $acceso,
        private readonly SeguimientoValidator $validator,
        private readonly Transaction $transaction,
    ) {
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function listar(Request $request): array
    {
        $usuarioId = (int) $request->userId();
        $this->acceso->exigirLectura($usuarioId);
        $query = $request->queryParams();
        $filtros = [
            'cliente_id' => $this->entero($query['cliente_id'] ?? null, 'cliente_id'),
            'oportunidad_id' => $this->entero($query['oportunidad_id'] ?? null, 'oportunidad_id'),
            'tipo' => $this->tipo($query['tipo'] ?? null),
            'usuario_id' => null,
            'alcance_usuario' => null,
        ];
        if ($filtros['cliente_id'] !== null) {
            $this->exigirCliente($usuarioId, $filtros['cliente_id']);
        }
        if ($filtros['oportunidad_id'] !== null) {
            $this->exigirOportunidad($usuarioId, $filtros['oportunidad_id'], $filtros['cliente_id']);
        }
        if ($this->acceso->esAdmin($usuarioId)) {
            $filtros['usuario_id'] = null;
        } elseif ($this->acceso->esVendedor($usuarioId)) {
            $filtros['usuario_id'] = $usuarioId;
            $filtros['alcance_usuario'] = $usuarioId;
        } else {
            $filtros['alcance_usuario'] = $usuarioId;
        }
        unset($query['cliente_id'], $query['oportunidad_id'], $query['tipo'], $query['usuario_id']);
        if (!isset($query['order']) || $query['order'] === '') {
            $query['order'] = 'desc';
        }
        $criteria = ListCriteria::from($query, ['fecha' => 's.fecha', 'id' => 's.id'], null, false, false);
        $resultado = $this->seguimientos->listar($criteria, $filtros);

        return [
            'rows' => array_map(static function (array $row): array {
                return [
                    'id' => (int) $row['id'],
                    'tipo' => $row['tipo'],
                    'fecha' => $row['fecha'],
                    'resultado' => $row['resultado'],
                    'proximo_seguimiento' => $row['proximo_seguimiento'],
                    'observacion' => $row['observacion'],
                    'oportunidad' => $row['oportunidad_id'] === null ? null : [
                        'id' => (int) $row['oportunidad_id'],
                        'titulo' => $row['oportunidad_titulo'],
                    ],
                    'cliente' => [
                        'id' => (int) $row['cliente_id'],
                        'razon_social' => $row['razon_social'],
                        'nombre_comercial' => $row['nombre_comercial'],
                    ],
                    'usuario' => trim($row['nombres'] . ' ' . $row['apellidos']),
                ];
            }, $resultado['rows']),
            'total' => $resultado['total'],
        ];
    }

    /** @return array<string, mixed> */
    public function crear(Request $request): array
    {
        $usuarioId = (int) $request->userId();
        $this->acceso->exigirEscritura($usuarioId);
        $datos = $this->validator->crear($request->json());
        $id = $this->transaction->run(function () use ($request, $usuarioId, $datos): int {
            $this->exigirCliente($usuarioId, (int) $datos['cliente_id']);
            if ($this->acceso->esVendedor($usuarioId) && !$this->acceso->esAdmin($usuarioId) && !$this->oportunidades->comercialVigente($usuarioId, (int) $datos['cliente_id'])) {
                throw new AuthorizationException('No tiene acceso al cliente solicitado.', 'CLIENT_SCOPE_FORBIDDEN');
            }
            if ($datos['oportunidad_id'] !== null) {
                $oportunidad = $this->exigirOportunidad($usuarioId, (int) $datos['oportunidad_id'], (int) $datos['cliente_id']);
                if ((int) $oportunidad['es_final'] === 1) {
                    throw new ConflictException('Una oportunidad final no admite un seguimiento nuevo.', 'OPORTUNIDAD_YA_CERRADA');
                }
            }
            $id = $this->seguimientos->crear($datos, $usuarioId);
            $this->auditoria->registrar(
                (int) $datos['cliente_id'],
                $usuarioId,
                'seguimientos_comerciales',
                $id,
                'SEGUIMIENTO_CREATE',
                null,
                [
                    'tipo' => $datos['tipo'],
                    'oportunidad_id' => $datos['oportunidad_id'],
                    'fecha' => $datos['fecha'],
                ],
                $request->ip(),
                $request->userAgent(),
            );

            return $id;
        });

        return [
            'id' => $id,
            'cliente_id' => (int) $datos['cliente_id'],
            'oportunidad_id' => $datos['oportunidad_id'],
            'usuario_id' => $usuarioId,
            'tipo' => $datos['tipo'],
            'fecha' => $datos['fecha'],
            'resultado' => $datos['resultado'],
            'proximo_seguimiento' => $datos['proximo_seguimiento'],
            'observacion' => $datos['observacion'],
        ];
    }

    private function exigirCliente(int $usuarioId, int $clienteId): void
    {
        $cliente = $this->oportunidades->cliente($clienteId);
        if ($cliente === null || (int) $cliente['eliminado'] === 1) {
            if ($this->acceso->esAdmin($usuarioId)) {
                throw new NotFoundException('Cliente no encontrado.');
            }
            throw new AuthorizationException('No tiene acceso al cliente solicitado.', 'CLIENT_SCOPE_FORBIDDEN');
        }
        if (!$this->acceso->esAdmin($usuarioId)) {
            $this->authorization->requireClientAccess($usuarioId, $clienteId);
        }
    }

    /** @return array<string, mixed> */
    private function exigirOportunidad(int $usuarioId, int $oportunidadId, ?int $clienteEsperado): array
    {
        $row = $this->oportunidades->ficha($oportunidadId);
        if ($row === null || !$this->visible($usuarioId, $row)) {
            if ($this->acceso->esAdmin($usuarioId) && $row === null) {
                throw new NotFoundException('Oportunidad no encontrada.');
            }
            throw new AuthorizationException('No tiene acceso al cliente solicitado.', 'CLIENT_SCOPE_FORBIDDEN');
        }
        if ($clienteEsperado !== null && (int) $row['cliente_id'] !== $clienteEsperado) {
            throw new \App\Exceptions\ValidationException('Los datos enviados no son válidos.', [
                'oportunidad_id' => ['La oportunidad no pertenece al cliente indicado.'],
            ]);
        }

        return $row;
    }

    /** @param array<string, mixed> $row */
    private function visible(int $usuarioId, array $row): bool
    {
        if ($this->acceso->esAdmin($usuarioId)) {
            return true;
        }
        if (!$this->authorization->canAccessClient($usuarioId, (int) $row['cliente_id'])) {
            return false;
        }
        if ($this->acceso->esVendedor($usuarioId)) {
            return (int) $row['responsable_comercial_id'] === $usuarioId;
        }

        return $this->acceso->esGestor($usuarioId);
    }

    private function entero(mixed $valor, string $campo): ?int
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        $texto = trim((string) $valor);
        if (preg_match('/^[1-9][0-9]*$/', $texto) !== 1) {
            throw new \App\Exceptions\ValidationException('Los datos enviados no son válidos.', [
                $campo => ['El identificador no es válido.'],
            ]);
        }

        return (int) $texto;
    }

    private function tipo(mixed $valor): ?string
    {
        if ($valor === null || trim((string) $valor) === '') {
            return null;
        }
        $tipo = strtoupper(trim((string) $valor));
        if (!in_array($tipo, ['LLAMADA', 'VISITA', 'REUNION', 'CORREO', 'OTRO'], true)) {
            throw new \App\Exceptions\ValidationException('Los datos enviados no son válidos.', [
                'tipo' => ['El tipo de seguimiento no es válido.'],
            ]);
        }

        return $tipo;
    }
}
