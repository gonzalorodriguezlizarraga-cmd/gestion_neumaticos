<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Http\Request;
use App\Repositories\AuditoriaRepository;
use App\Repositories\TipoUnidadRepository;
use App\Support\ListCriteria;
use App\Support\Transaction;
use App\Validators\TipoUnidadValidator;
use PDOException;

final class TipoUnidadService
{
    public function __construct(
        private readonly TipoUnidadRepository $tipos,
        private readonly AuditoriaRepository $auditoria,
        private readonly AuthorizationService $authorization,
        private readonly TipoUnidadValidator $validator,
        private readonly Transaction $transaction,
    ) {
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function listar(int $usuarioId, array $query): array
    {
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::LECTURA);

        return $this->tipos->listar(ListCriteria::from($query, [
            'codigo' => 'codigo',
            'nombre' => 'nombre',
            'creado_en' => 'creado_en',
        ], null, true, true));
    }

    /** @return array<string, mixed> */
    public function obtener(int $usuarioId, int $id): array
    {
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::LECTURA);

        return $this->tipos->presentar($this->exigir($id), true);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function crear(int $usuarioId, array $input, Request $request): array
    {
        $this->authorization->requireRole($usuarioId, 'ADMIN_GENERAL');
        $data = $this->validator->crear($input);

        return $this->transaction->run(function () use ($usuarioId, $data, $request): array {
            try {
                $id = $this->tipos->crear($data);
            } catch (PDOException $error) {
                $this->relanzarDuplicado($error);
            }
            $row = $this->tipos->presentar($this->exigir($id), true);
            $this->auditar($usuarioId, $id, 'TIPO_UNIDAD_CREATE', null, $row, $request);

            return $row;
        });
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function actualizar(int $usuarioId, int $id, array $input, Request $request): array
    {
        $this->authorization->requireRole($usuarioId, 'ADMIN_GENERAL');
        $actual = $this->exigir($id);
        $data = $this->validator->actualizar($input);
        $cambiarCodigo = $data['codigo'] !== null && $data['codigo'] !== $actual['codigo'];
        if ($cambiarCodigo && $this->tipos->enUso($id)) {
            throw new ConflictException('El código no se puede cambiar porque el tipo ya está en uso.');
        }

        return $this->transaction->run(function () use ($usuarioId, $id, $actual, $data, $cambiarCodigo, $request): array {
            $antes = $this->tipos->presentar($actual, true);
            try {
                $this->tipos->actualizar($id, $data, $cambiarCodigo);
            } catch (PDOException $error) {
                $this->relanzarDuplicado($error);
            }
            $despues = $this->tipos->presentar($this->exigir($id), true);
            $this->auditar($usuarioId, $id, 'TIPO_UNIDAD_UPDATE', $antes, $despues, $request);

            return $despues;
        });
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function cambiarActivo(int $usuarioId, int $id, array $input, Request $request): array
    {
        $this->authorization->requireRole($usuarioId, 'ADMIN_GENERAL');
        $actual = $this->exigir($id);
        $activo = $this->validator->activo($input);

        return $this->transaction->run(function () use ($usuarioId, $id, $actual, $activo, $request): array {
            $antes = $this->tipos->presentar($actual, true);
            $this->tipos->cambiarActivo($id, $activo);
            $despues = $this->tipos->presentar($this->exigir($id), true);
            $this->auditar($usuarioId, $id, 'TIPO_UNIDAD_ESTADO', $antes, $despues, $request);

            return $despues;
        });
    }

    /** @return array<string, mixed> */
    private function exigir(int $id): array
    {
        $row = $this->tipos->find($id);
        if ($row === null) {
            throw new NotFoundException('Tipo de unidad no encontrado.');
        }

        return $row;
    }

    private function relanzarDuplicado(PDOException $error): never
    {
        $state = (string) ($error->errorInfo[0] ?? '');
        if ($state === '23000' && str_contains($error->getMessage(), 'uk_tipos_unidad_codigo')) {
            throw new ConflictException('El código de tipo ya está registrado.');
        }
        throw $error;
    }

    /** @param array<string, mixed>|null $antes @param array<string, mixed> $despues */
    private function auditar(int $usuarioId, int $id, string $accion, ?array $antes, array $despues, Request $request): void
    {
        $this->auditoria->registrar(null, $usuarioId, 'tipos_unidad', $id, $accion, $antes, $despues, $request->ip(), $request->userAgent());
    }
}
