<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Http\Request;
use App\Repositories\AuditoriaRepository;
use App\Repositories\ConfiguracionUnidadRepository;
use App\Support\ListCriteria;
use App\Support\Transaction;
use App\Validators\ConfiguracionUnidadValidator;
use PDOException;

final class ConfiguracionUnidadService
{
    public function __construct(
        private readonly ConfiguracionUnidadRepository $configuraciones,
        private readonly AuditoriaRepository $auditoria,
        private readonly AuthorizationService $authorization,
        private readonly ConfiguracionUnidadValidator $validator,
        private readonly Transaction $transaction,
    ) {
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function listar(int $usuarioId, array $query): array
    {
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::LECTURA);

        return $this->configuraciones->listar(ListCriteria::from($query, [
            'nombre' => 'nombre',
            'cantidad_ejes' => 'cantidad_ejes',
            'cantidad_posiciones' => 'cantidad_posiciones',
            'creado_en' => 'creado_en',
        ], null, true, true));
    }

    /** @return array<string, mixed> */
    public function obtener(int $usuarioId, int $id): array
    {
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::LECTURA);
        $this->exigir($id);

        return $this->configuraciones->detalle($id);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function crear(int $usuarioId, array $input, Request $request): array
    {
        $this->exigirTecnica($usuarioId);
        $data = $this->validator->estructura($input);

        return $this->transaction->run(function () use ($usuarioId, $data, $request): array {
            try {
                $id = $this->configuraciones->crear($data);
            } catch (PDOException $error) {
                $this->relanzarEstructura($error);
            }
            $detalle = $this->configuraciones->detalle($id);
            $this->auditar($usuarioId, $id, 'CONFIGURACION_CREATE', null, $detalle, $request);

            return $detalle;
        });
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function actualizar(int $usuarioId, int $id, array $input, Request $request): array
    {
        $this->exigirTecnica($usuarioId);
        $this->exigir($id);
        $usada = $this->configuraciones->enUso($id);
        if ($usada && array_key_exists('ejes', $input)) {
            throw new ConflictException('La estructura de ejes y posiciones está bloqueada porque la configuración ya fue utilizada.');
        }

        return $this->transaction->run(function () use ($usuarioId, $id, $input, $usada, $request): array {
            $antes = $this->configuraciones->detalle($id);
            try {
                if ($usada) {
                    $this->configuraciones->actualizarDescripcion($id, $this->validator->descriptivo($input));
                } else {
                    $this->configuraciones->reemplazarEstructura($id, $this->validator->estructura($input));
                }
            } catch (PDOException $error) {
                $this->relanzarEstructura($error);
            }
            $despues = $this->configuraciones->detalle($id);
            $this->auditar($usuarioId, $id, 'CONFIGURACION_UPDATE', $antes, $despues, $request);

            return $despues;
        });
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function duplicar(int $usuarioId, int $id, array $input, Request $request): array
    {
        $this->exigirTecnica($usuarioId);
        $origen = $this->configuraciones->detalle($id);
        if ($origen === []) {
            throw new NotFoundException('Configuración no encontrada.');
        }
        $nombre = $this->validator->nombreCopia($input);
        if ($nombre === $origen['nombre']) {
            throw new ValidationException('Los datos enviados no son válidos.', [
                'nombre' => ['Indique un nombre distinto para la copia.'],
            ]);
        }
        $data = [
            'nombre' => $nombre,
            'descripcion' => $origen['descripcion'],
            'cantidad_ejes' => $origen['cantidad_ejes'],
            'cantidad_posiciones' => $origen['cantidad_posiciones'],
            'ejes' => array_map(static function (array $eje): array {
                return [
                    'numero_eje' => $eje['numero_eje'],
                    'nombre' => $eje['nombre'],
                    'orden' => $eje['orden'],
                    'posiciones' => array_map(static function (array $posicion): array {
                        return [
                            'codigo' => $posicion['codigo'],
                            'lado' => $posicion['lado'],
                            'ubicacion' => $posicion['ubicacion'],
                            'orden' => $posicion['orden'],
                            'activo' => $posicion['activo'],
                        ];
                    }, $eje['posiciones']),
                ];
            }, $origen['ejes']),
        ];

        return $this->transaction->run(function () use ($usuarioId, $id, $data, $request): array {
            $nuevo = $this->configuraciones->crear($data);
            $this->configuraciones->cambiarActivo($nuevo, true);
            $detalle = $this->configuraciones->detalle($nuevo);
            $this->auditar($usuarioId, $nuevo, 'CONFIGURACION_DUPLICATE', ['origen_id' => $id], $detalle, $request);

            return $detalle;
        });
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function cambiarActivo(int $usuarioId, int $id, array $input, Request $request): array
    {
        $this->exigirTecnica($usuarioId);
        $this->exigir($id);
        $activo = $this->validator->activo($input);

        return $this->transaction->run(function () use ($usuarioId, $id, $activo, $request): array {
            $antes = $this->configuraciones->detalle($id);
            $this->configuraciones->cambiarActivo($id, $activo);
            $despues = $this->configuraciones->detalle($id);
            $this->auditar($usuarioId, $id, 'CONFIGURACION_ESTADO', $antes, $despues, $request);

            return $despues;
        });
    }

    private function exigirTecnica(int $usuarioId): void
    {
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::OPERACION);
    }

    private function exigir(int $id): void
    {
        if ($this->configuraciones->find($id) === null) {
            throw new NotFoundException('Configuración no encontrada.');
        }
    }

    private function relanzarEstructura(PDOException $error): never
    {
        $state = (string) ($error->errorInfo[0] ?? '');
        $message = $error->getMessage();
        if ($state === '23000' && (str_contains($message, 'uk_config_ejes_') || str_contains($message, 'uk_config_pos_'))) {
            throw new ConflictException('La estructura repite un número, orden o código ya registrado.');
        }
        throw $error;
    }

    /** @param array<string, mixed>|null $antes @param array<string, mixed> $despues */
    private function auditar(int $usuarioId, int $id, string $accion, ?array $antes, array $despues, Request $request): void
    {
        $this->auditoria->registrar(null, $usuarioId, 'configuraciones_unidad', $id, $accion, $antes, $despues, $request->ip(), $request->userAgent());
    }
}
