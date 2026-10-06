<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AuthorizationException;
use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Repositories\AuditoriaRepository;
use App\Repositories\EstadoNeumaticoRepository;
use App\Repositories\MarcaNeumaticoRepository;
use App\Repositories\MedidaNeumaticoRepository;
use App\Repositories\ModeloNeumaticoRepository;
use App\Repositories\NeumaticoRepository;
use App\Support\ListCriteria;
use App\Support\Transaction;
use App\Validators\NeumaticoValidator;
use PDOException;
use RuntimeException;

final class NeumaticoService
{
    public function __construct(
        private readonly NeumaticoRepository $neumaticos,
        private readonly ModeloNeumaticoRepository $modelos,
        private readonly MedidaNeumaticoRepository $medidas,
        private readonly EstadoNeumaticoRepository $estados,
        private readonly MarcaNeumaticoRepository $marcas,
        private readonly AuditoriaRepository $auditoria,
        private readonly AuthorizationService $authorization,
        private readonly NeumaticoValidator $validator,
        private readonly Transaction $transaction,
    ) {
    }

    /** @param array<string, mixed> $query @return array{rows:list<array<string,mixed>>,total:int} */
    public function listar(int $usuarioId, array $query): array
    {
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::LECTURA);
        $criteria = ListCriteria::from($query, [
            'codigo' => 'n.codigo',
            'numero_serie' => 'n.numero_serie',
            'vida_actual' => 'n.vida_actual',
            'creado_en' => 'n.creado_en',
        ], null, false, true);
        $cliente = $this->filtroId($query, 'cliente_id', 'El cliente');
        if ($cliente !== null) {
            $this->authorization->requireClientAccess($usuarioId, $cliente);
        }

        return $this->neumaticos->listar($criteria, [
            'cliente' => $cliente,
            'marca' => $this->filtroCatalogo($query, 'marca_id', 'La marca', $this->marcas),
            'modelo' => $this->filtroCatalogo($query, 'modelo_id', 'El modelo', $this->modelos),
            'medida' => $this->filtroCatalogo($query, 'medida_id', 'La medida', $this->medidas),
            'estado' => $this->filtroCatalogo($query, 'estado_id', 'El estado', $this->estados),
            'vida' => $this->filtroVida($query),
            'usuario' => $this->authorization->isAdminGeneral($usuarioId) ? null : $usuarioId,
        ]);
    }

    /** @return array<string, mixed> */
    public function obtener(int $usuarioId, int $id): array
    {
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::LECTURA);

        return $this->neumaticos->presentar($this->exigir($usuarioId, $id));
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function historial(int $usuarioId, int $id): array
    {
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::LECTURA);
        $this->exigir($usuarioId, $id);
        $rows = $this->neumaticos->historial($id);

        return ['rows' => $rows, 'total' => count($rows)];
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function vidas(int $usuarioId, int $id): array
    {
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::LECTURA);
        $this->exigir($usuarioId, $id);
        $rows = $this->neumaticos->vidas($id);

        return ['rows' => $rows, 'total' => count($rows)];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function crear(int $usuarioId, array $input, Request $request): array
    {
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::OPERACION);
        $data = $this->validator->crear($input);
        $this->authorization->requireClientAccess($usuarioId, $data['cliente_id']);
        $this->exigirClienteActivo($data['cliente_id']);
        $this->resolverModelo($data['modelo_id'], null);
        $this->resolverMedida($data['medida_id'], null);

        return $this->transaction->run(function () use ($usuarioId, $data, $request): array {
            $estado = $this->estados->findByCodigo('DISPONIBLE');
            if ($estado === null || (int) $estado['activo'] !== 1) {
                throw new RuntimeException('El estado DISPONIBLE no está disponible.');
            }
            $estadoId = (int) $estado['id'];
            try {
                $id = $this->neumaticos->crear($data, $estadoId, $usuarioId);
                $this->neumaticos->crearVidaInicial($data['cliente_id'], $id, $data['profundidad_inicial_mm']);
                $this->neumaticos->crearHistorialInicial($data['cliente_id'], $id, $estadoId, $usuarioId);
            } catch (PDOException $error) {
                $this->relanzarUnico($error);
            }
            $row = $this->neumaticos->presentar($this->exigir($usuarioId, $id));
            $this->auditoria->registrar($data['cliente_id'], $usuarioId, 'neumaticos', $id, 'NEUMATICO_CREATE', null, $row, $request->ip(), $request->userAgent());

            return $row;
        });
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function actualizar(int $usuarioId, int $id, array $input, Request $request): array
    {
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::OPERACION);
        $actual = $this->exigir($usuarioId, $id);
        $data = $this->validator->actualizar($input);
        $this->resolverModelo($data['modelo_id'], (int) $actual['modelo_id']);
        $this->resolverMedida($data['medida_id'], (int) $actual['medida_id']);

        return $this->transaction->run(function () use ($usuarioId, $id, $actual, $data, $request): array {
            $antes = $this->neumaticos->presentar($actual);
            try {
                $this->neumaticos->actualizar($id, $data, $usuarioId);
            } catch (PDOException $error) {
                $this->relanzarUnico($error);
            }
            $despues = $this->neumaticos->presentar($this->exigir($usuarioId, $id));
            $this->auditoria->registrar((int) $actual['cliente_id'], $usuarioId, 'neumaticos', $id, 'NEUMATICO_UPDATE', $antes, $despues, $request->ip(), $request->userAgent());

            return $despues;
        });
    }

    /** @return array{id:int,eliminado:bool} */
    public function eliminar(int $usuarioId, int $id, Request $request): array
    {
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::OPERACION);
        $actual = $this->exigir($usuarioId, $id);
        $dependencias = $this->neumaticos->dependencias($id);
        $tieneRelaciones = array_sum($dependencias) > 0;
        if ((string) $actual['estado_codigo'] !== 'DISPONIBLE' || $tieneRelaciones) {
            throw new ConflictException('El neumático no se puede retirar porque no está disponible o tiene relaciones operativas.');
        }

        return $this->transaction->run(function () use ($usuarioId, $id, $actual, $dependencias, $request): array {
            $antes = $this->neumaticos->presentar($actual);
            $this->neumaticos->eliminar($id, $usuarioId);
            $this->auditoria->registrar((int) $actual['cliente_id'], $usuarioId, 'neumaticos', $id, 'NEUMATICO_DELETE', $antes, [
                'id' => $id,
                'eliminado' => true,
                'dependencias' => $dependencias,
            ], $request->ip(), $request->userAgent());

            return ['id' => $id, 'eliminado' => true];
        });
    }

    /** @return array<string, mixed> */
    private function exigir(int $usuarioId, int $id): array
    {
        $row = $this->neumaticos->find($id);
        if ($row === null || (int) $row['eliminado'] === 1) {
            if ($this->authorization->isAdminGeneral($usuarioId)) {
                throw new NotFoundException('Neumático no encontrado.');
            }
            throw new AuthorizationException('No tiene acceso a este neumático.', 'CLIENT_SCOPE_FORBIDDEN');
        }
        $this->authorization->requireClientAccess($usuarioId, (int) $row['cliente_id']);

        return $row;
    }

    private function exigirClienteActivo(int $clienteId): void
    {
        $cliente = $this->neumaticos->cliente($clienteId);
        if ($cliente === null || (int) $cliente['eliminado'] === 1 || (string) $cliente['estado'] !== 'ACTIVO') {
            throw new ConflictException('Solo un cliente activo puede recibir nuevos neumáticos.');
        }
    }

    private function resolverModelo(int $modeloId, ?int $actual): void
    {
        $modelo = $this->modelos->find($modeloId);
        if ($modelo === null) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'modelo_id' => ['El modelo no existe.'],
            ]);
        }
        if ($actual === $modeloId) {
            return;
        }
        if ((int) $modelo['activo'] !== 1) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'modelo_id' => ['El modelo no está activo.'],
            ]);
        }
        if ((int) $modelo['marca_activo'] !== 1) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'modelo_id' => ['La marca del modelo no está activa.'],
            ]);
        }
    }

    private function resolverMedida(int $medidaId, ?int $actual): void
    {
        $medida = $this->medidas->find($medidaId);
        if ($medida === null) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'medida_id' => ['La medida no existe.'],
            ]);
        }
        if ($actual !== $medidaId && (int) $medida['activo'] !== 1) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'medida_id' => ['La medida no está activa.'],
            ]);
        }
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
    private function filtroCatalogo(array $query, string $field, string $label, object $repositorio): ?int
    {
        $id = $this->filtroId($query, $field, $label);
        if ($id !== null && $repositorio->find($id) === null) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                $field => [$label . ' no existe.'],
            ]);
        }

        return $id;
    }

    /** @param array<string, mixed> $query */
    private function filtroVida(array $query): ?int
    {
        $id = $this->filtroId($query, 'vida_actual', 'La vida');
        if ($id !== null && $id > 65535) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'vida_actual' => ['La vida no es válida.'],
            ]);
        }

        return $id;
    }

    /**
     * UNIQUE (cliente_id, codigo) sigue ocupado con eliminado = 1.
     * El código se trata como identificador histórico no reutilizable dentro del mismo cliente.
     */
    private function relanzarUnico(PDOException $error): never
    {
        $state = (string) ($error->errorInfo[0] ?? '');
        $message = $error->getMessage();
        if ($state === '23000' && str_contains($message, 'uk_neumaticos_cliente_codigo')) {
            throw new ConflictException('El código ya está registrado para este cliente.');
        }
        if ($state === '23000' && str_contains($message, 'uk_neumaticos_cliente_serie')) {
            throw new ConflictException('El número de serie ya está registrado para este cliente.');
        }
        throw $error;
    }
}
