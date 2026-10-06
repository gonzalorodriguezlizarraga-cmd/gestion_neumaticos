<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AuthorizationException;
use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Repositories\AuditoriaRepository;
use App\Repositories\ConfiguracionUnidadRepository;
use App\Repositories\TipoUnidadRepository;
use App\Repositories\UnidadRepository;
use App\Support\ListCriteria;
use App\Support\Transaction;
use App\Validators\UnidadValidator;
use PDOException;

final class UnidadService
{
    /** @var array<string, list<string>> */
    private const TRANSICIONES_ADMIN = [
        'OPERATIVA' => ['INACTIVA', 'BAJA'],
        'INACTIVA' => ['OPERATIVA', 'BAJA'],
        'BAJA' => ['OPERATIVA', 'INACTIVA'],
    ];

    /** @var array<string, list<string>> */
    private const TRANSICIONES_GESTOR = [
        'OPERATIVA' => ['INACTIVA'],
        'INACTIVA' => ['OPERATIVA'],
        'BAJA' => [],
    ];

    public function __construct(
        private readonly UnidadRepository $unidades,
        private readonly TipoUnidadRepository $tipos,
        private readonly ConfiguracionUnidadRepository $configuraciones,
        private readonly AuditoriaRepository $auditoria,
        private readonly AuthorizationService $authorization,
        private readonly UnidadValidator $validator,
        private readonly Transaction $transaction,
    ) {
    }

    /** @param array<string, mixed> $query @return array{rows:list<array<string,mixed>>,total:int} */
    public function listar(int $usuarioId, array $query): array
    {
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::LECTURA);
        $criteria = ListCriteria::from($query, [
            'codigo' => 'u.codigo',
            'placa' => 'u.placa',
            'estado' => 'u.estado',
            'marca' => 'u.marca',
            'creado_en' => 'u.creado_en',
        ], ['OPERATIVA', 'INACTIVA', 'BAJA'], false, true);
        $cliente = $this->filtroId($query, 'cliente_id', 'El cliente');
        if ($cliente !== null) {
            $this->authorization->requireClientAccess($usuarioId, $cliente);
        }
        $sede = $this->filtroOrganizacion($usuarioId, $query, 'sede_id', true);
        $flota = $this->filtroOrganizacion($usuarioId, $query, 'flota_id', false);
        $tipo = $this->filtroId($query, 'tipo_unidad_id', 'El tipo de unidad');
        if ($tipo !== null && $this->tipos->find($tipo) === null) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'tipo_unidad_id' => ['El tipo de unidad no existe.'],
            ]);
        }

        return $this->unidades->listar($criteria, [
            'cliente' => $cliente,
            'sede' => $sede,
            'flota' => $flota,
            'tipo' => $tipo,
            'usuario' => $this->authorization->isAdminGeneral($usuarioId) ? null : $usuarioId,
        ]);
    }

    /** @return array<string, mixed> */
    public function obtener(int $usuarioId, int $id): array
    {
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::LECTURA);

        return $this->unidades->presentar($this->exigir($usuarioId, $id));
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function crear(int $usuarioId, array $input, Request $request): array
    {
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::OPERACION);
        $data = $this->validator->crear($input);
        $this->authorization->requireClientAccess($usuarioId, $data['cliente_id']);
        $this->exigirClienteActivo($data['cliente_id']);
        $this->exigirBaja($usuarioId, $data['estado']);
        $data['sede_id'] = $this->resolverSede($data['sede_id'], $data['cliente_id'], null);
        $data['flota_id'] = $this->resolverFlota($data['flota_id'], $data['cliente_id'], null);
        $data['tipo_unidad_id'] = $this->resolverTipo($data['tipo_unidad_id'], null);
        $data['configuracion_id'] = $this->resolverConfiguracion($data['configuracion_id'], null, null);

        return $this->transaction->run(function () use ($usuarioId, $data, $request): array {
            try {
                $id = $this->unidades->crear($data, $usuarioId);
            } catch (PDOException $error) {
                $this->relanzarCodigo($error);
            }
            $row = $this->unidades->presentar($this->exigir($usuarioId, $id));
            $this->auditar($data['cliente_id'], $usuarioId, $id, 'UNIDAD_CREATE', null, $row, $request);

            return $row;
        });
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function actualizar(int $usuarioId, int $id, array $input, Request $request): array
    {
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::OPERACION);
        $actual = $this->exigir($usuarioId, $id);
        $data = $this->validator->actualizar($input);
        $clienteId = (int) $actual['cliente_id'];
        $data['sede_id'] = $this->resolverSede($data['sede_id'], $clienteId, $actual['sede_id'] === null ? null : (int) $actual['sede_id']);
        $data['flota_id'] = $this->resolverFlota($data['flota_id'], $clienteId, $actual['flota_id'] === null ? null : (int) $actual['flota_id']);
        $data['tipo_unidad_id'] = $this->resolverTipo($data['tipo_unidad_id'], (int) $actual['tipo_unidad_id']);
        $data['configuracion_id'] = $this->resolverConfiguracion(
            $data['configuracion_id'],
            $actual['configuracion_id'] === null ? null : (int) $actual['configuracion_id'],
            $id,
        );

        return $this->transaction->run(function () use ($usuarioId, $id, $actual, $data, $clienteId, $request): array {
            $antes = $this->unidades->presentar($actual);
            try {
                $this->unidades->actualizar($id, $data, $usuarioId);
            } catch (PDOException $error) {
                $this->relanzarCodigo($error);
            }
            $despues = $this->unidades->presentar($this->exigir($usuarioId, $id));
            $this->auditar($clienteId, $usuarioId, $id, 'UNIDAD_UPDATE', $antes, $despues, $request);

            return $despues;
        });
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function cambiarEstado(int $usuarioId, int $id, array $input, Request $request): array
    {
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::OPERACION);
        $actual = $this->exigir($usuarioId, $id);
        $destino = $this->validator->estado($input);
        $origen = (string) $actual['estado'];
        if ($origen === 'BAJA' && !$this->authorization->isAdminGeneral($usuarioId)) {
            throw new AuthorizationException('Solo administración general puede cambiar una unidad dada de baja.');
        }
        $permitidos = $this->authorization->isAdminGeneral($usuarioId)
            ? (self::TRANSICIONES_ADMIN[$origen] ?? [])
            : (self::TRANSICIONES_GESTOR[$origen] ?? []);
        if ($destino === 'BAJA' && !$this->authorization->isAdminGeneral($usuarioId)) {
            throw new AuthorizationException('Solo administración general puede dar de baja una unidad.');
        }
        if (!in_array($destino, $permitidos, true)) {
            throw new ConflictException('El cambio de estado no está permitido.');
        }

        return $this->transaction->run(function () use ($usuarioId, $id, $actual, $destino, $request): array {
            $antes = $this->unidades->presentar($actual);
            $this->unidades->cambiarEstado($id, $destino, $usuarioId);
            $despues = $this->unidades->presentar($this->exigir($usuarioId, $id));
            $this->auditar((int) $actual['cliente_id'], $usuarioId, $id, 'UNIDAD_ESTADO', $antes, $despues, $request);

            return $despues;
        });
    }

    /** @return array<string, mixed> */
    public function eliminar(int $usuarioId, int $id, Request $request): array
    {
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::OPERACION);
        $actual = $this->exigir($usuarioId, $id);

        return $this->transaction->run(function () use ($usuarioId, $id, $actual, $request): array {
            $antes = $this->unidades->presentar($actual);
            $dependencias = $this->unidades->dependencias($id);
            $this->unidades->eliminar($id, $usuarioId);
            $this->auditar((int) $actual['cliente_id'], $usuarioId, $id, 'UNIDAD_DELETE', $antes, [
                'id' => $id,
                'eliminado' => true,
                'dependencias' => $dependencias,
            ], $request);

            return ['id' => $id, 'eliminado' => true];
        });
    }

    /** @return array<string, mixed> */
    private function exigir(int $usuarioId, int $id): array
    {
        $row = $this->unidades->find($id);
        if ($row === null || (int) $row['eliminado'] === 1) {
            if ($this->authorization->isAdminGeneral($usuarioId)) {
                throw new NotFoundException('Unidad no encontrada.');
            }
            throw new AuthorizationException('No tiene acceso a esta unidad.', 'CLIENT_SCOPE_FORBIDDEN');
        }
        $this->authorization->requireClientAccess($usuarioId, (int) $row['cliente_id']);

        return $row;
    }

    private function exigirClienteActivo(int $clienteId): void
    {
        $cliente = $this->unidades->cliente($clienteId);
        if ($cliente === null || (int) $cliente['eliminado'] === 1 || (string) $cliente['estado'] !== 'ACTIVO') {
            throw new ConflictException('Solo un cliente activo puede recibir nuevas unidades.');
        }
    }

    private function exigirBaja(int $usuarioId, string $estado): void
    {
        if ($estado === 'BAJA' && !$this->authorization->isAdminGeneral($usuarioId)) {
            throw new AuthorizationException('Solo administración general puede dar de baja una unidad.');
        }
    }

    private function resolverSede(?int $sedeId, int $clienteId, ?int $actual): ?int
    {
        return $this->resolverOrganizacion($sedeId, $clienteId, $actual, true);
    }

    private function resolverFlota(?int $flotaId, int $clienteId, ?int $actual): ?int
    {
        return $this->resolverOrganizacion($flotaId, $clienteId, $actual, false);
    }

    private function resolverOrganizacion(?int $id, int $clienteId, ?int $actual, bool $sede): ?int
    {
        if ($id === null) {
            return null;
        }
        $row = $sede ? $this->unidades->sede($id) : $this->unidades->flota($id);
        $campo = $sede ? 'sede_id' : 'flota_id';
        $nombre = $sede ? 'La sede' : 'La flota';
        if ($row === null || (int) $row['cliente_id'] !== $clienteId) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                $campo => [$nombre . ' no pertenece al cliente.'],
            ]);
        }
        if ($actual !== $id && (int) $row['eliminado'] === 1) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                $campo => [$nombre . ' está eliminada.'],
            ]);
        }
        if ($actual !== $id && (int) $row['activo'] !== 1) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                $campo => [$nombre . ' no está activa.'],
            ]);
        }

        return $id;
    }

    private function resolverTipo(int $tipoId, ?int $actual): int
    {
        $tipo = $this->tipos->find($tipoId);
        if ($tipo === null) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'tipo_unidad_id' => ['El tipo de unidad no existe.'],
            ]);
        }
        if ($actual !== $tipoId && (int) $tipo['activo'] !== 1) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'tipo_unidad_id' => ['El tipo de unidad no está activo.'],
            ]);
        }

        return $tipoId;
    }

    private function resolverConfiguracion(?int $configuracionId, ?int $actual, ?int $unidadId): ?int
    {
        if ($configuracionId === $actual) {
            return $configuracionId;
        }
        if ($unidadId !== null && $this->unidades->tieneHistoricoOperativo($unidadId)) {
            throw new ConflictException(
                'La configuración no se puede cambiar porque la unidad ya tiene histórico operativo.',
                'UNIT_CONFIGURATION_LOCKED'
            );
        }
        if ($configuracionId === null) {
            return null;
        }
        $configuracion = $this->configuraciones->find($configuracionId);
        if ($configuracion === null) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'configuracion_id' => ['La configuración no existe.'],
            ]);
        }
        if ((int) $configuracion['activo'] !== 1) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'configuracion_id' => ['La configuración no está activa.'],
            ]);
        }

        return $configuracionId;
    }

    /** @param array<string, mixed> $query */
    private function filtroId(array $query, string $field, string $label): ?int
    {
        if (!array_key_exists($field, $query) || trim((string) $query[$field]) === '') {
            return null;
        }
        $text = trim((string) $query[$field]);
        if (preg_match('/^[1-9][0-9]*$/', $text) !== 1) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                $field => [$label . ' no es válido.'],
            ]);
        }

        return (int) $text;
    }

    /** @param array<string, mixed> $query */
    private function filtroOrganizacion(int $usuarioId, array $query, string $field, bool $sede): ?int
    {
        $id = $this->filtroId($query, $field, $sede ? 'La sede' : 'La flota');
        if ($id === null) {
            return null;
        }
        $row = $sede ? $this->unidades->sede($id) : $this->unidades->flota($id);
        if ($row === null) {
            if ($this->authorization->isAdminGeneral($usuarioId)) {
                throw new ValidationException('Los datos enviados no son válidos.', [
                    $field => ['El filtro no existe.'],
                ]);
            }
            throw new AuthorizationException('No tiene acceso a este cliente.', 'CLIENT_SCOPE_FORBIDDEN');
        }
        $this->authorization->requireClientAccess($usuarioId, (int) $row['cliente_id']);

        return $id;
    }

    /**
     * UNIQUE (cliente_id, codigo) sigue ocupado con eliminado = 1.
     * El código de unidad se trata como identificador histórico no
     * reutilizable dentro del mismo cliente mientras no se apruebe otra política.
     */
    private function relanzarCodigo(PDOException $error): never
    {
        $state = (string) ($error->errorInfo[0] ?? '');
        if ($state === '23000' && str_contains($error->getMessage(), 'uk_unidades_cliente_codigo')) {
            throw new ConflictException('El código ya está registrado para este cliente.');
        }
        throw $error;
    }

    /** @param array<string, mixed>|null $antes @param array<string, mixed> $despues */
    private function auditar(int $clienteId, int $usuarioId, int $id, string $accion, ?array $antes, array $despues, Request $request): void
    {
        $this->auditoria->registrar($clienteId, $usuarioId, 'unidades', $id, $accion, $antes, $despues, $request->ip(), $request->userAgent());
    }
}
