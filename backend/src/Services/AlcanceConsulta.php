<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AuthorizationException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Repositories\IndicadorRepository;
use PDO;

final class AlcanceConsulta
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly IndicadorRepository $indicadores,
        private readonly AuthorizationService $authorization,
    ) {
    }

    /** @return array<string, mixed> */
    public function resolver(Request $request): array
    {
        $usuarioId = (int) $request->userId();
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::LECTURA);
        $query = $request->queryParams();
        $clienteId = $this->entero($query['cliente_id'] ?? null, 'cliente_id');
        if ($clienteId !== null) {
            $this->authorization->requireClientAccess($usuarioId, $clienteId);
        }
        $sedeId = $this->entero($query['sede_id'] ?? null, 'sede_id');
        $flotaId = $this->entero($query['flota_id'] ?? null, 'flota_id');
        $this->ubicacion($usuarioId, $clienteId, $sedeId, true);
        $this->ubicacion($usuarioId, $clienteId, $flotaId, false);

        return [
            'usuario_id' => $usuarioId,
            'usuario_scope' => $this->authorization->isAdminGeneral($usuarioId) ? null : $usuarioId,
            'cliente_id' => $clienteId,
            'sede_id' => $sedeId,
            'flota_id' => $flotaId,
            'es_admin' => $this->authorization->isAdminGeneral($usuarioId),
            'es_portal' => $this->authorization->hasRole($usuarioId, 'ADMIN_CLIENTE', 'CONSULTA_EJECUTIVA'),
            'es_tecnico' => $this->authorization->hasRole($usuarioId, 'TECNICO_INSPECCION')
                && !$this->authorization->hasRole($usuarioId, 'ADMIN_GENERAL', 'GESTOR_NEUMATICOS'),
        ];
    }

    public function exigirUnidad(array $filtros, ?int $unidadId): ?int
    {
        return $this->exigirDueno($filtros, $unidadId, 'unidad_id', 'unidades', 'Unidad no encontrada.');
    }

    public function exigirNeumatico(array $filtros, ?int $neumaticoId): ?int
    {
        return $this->exigirDueno($filtros, $neumaticoId, 'neumatico_id', 'neumaticos', 'Neumático no encontrado.');
    }

    /** @param array<string, mixed> $filtros */
    private function exigirDueno(array $filtros, ?int $id, string $campo, string $tabla, string $ausente): ?int
    {
        if ($id === null) {
            return null;
        }
        $statement = $this->pdo->prepare('SELECT cliente_id, eliminado FROM ' . $tabla . ' WHERE id = :id LIMIT 1');
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();
        if ($row === false || (int) $row['eliminado'] === 1) {
            $this->ocultar((int) $filtros['usuario_id'], $ausente);
        }
        $this->authorization->requireClientAccess((int) $filtros['usuario_id'], (int) $row['cliente_id']);
        if ($filtros['cliente_id'] !== null && (int) $row['cliente_id'] !== (int) $filtros['cliente_id']) {
            throw new AuthorizationException('No tiene acceso al cliente solicitado.', 'CLIENT_SCOPE_FORBIDDEN');
        }

        return $id;
    }

    private function ubicacion(int $usuarioId, ?int $clienteId, ?int $id, bool $sede): void
    {
        if ($id === null) {
            return;
        }
        $row = $sede ? $this->indicadores->sede($id) : $this->indicadores->flota($id);
        $campo = $sede ? 'sede_id' : 'flota_id';
        if ($row === null || (int) $row['eliminado'] === 1) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                $campo => ['La ubicación no está disponible.'],
            ]);
        }
        $this->authorization->requireClientAccess($usuarioId, (int) $row['cliente_id']);
        if ($clienteId !== null && (int) $row['cliente_id'] !== $clienteId) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                $campo => ['La ubicación no pertenece al cliente.'],
            ]);
        }
    }

    private function entero(mixed $valor, string $campo): ?int
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        if (preg_match('/^[1-9][0-9]*$/', (string) $valor) !== 1) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                $campo => ['El identificador no es válido.'],
            ]);
        }

        return (int) $valor;
    }

    private function ocultar(int $usuarioId, string $mensaje): never
    {
        if ($this->authorization->isAdminGeneral($usuarioId)) {
            throw new NotFoundException($mensaje);
        }
        throw new AuthorizationException('No tiene acceso al cliente solicitado.', 'CLIENT_SCOPE_FORBIDDEN');
    }
}
