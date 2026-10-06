<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\EstadoNeumaticoRepository;
use App\Support\ListCriteria;

final class EstadoNeumaticoService
{
    public function __construct(
        private readonly EstadoNeumaticoRepository $estados,
        private readonly AuthorizationService $authorization,
    ) {
    }

    /** @param array<string, mixed> $query @return array{rows:list<array<string,mixed>>,total:int} */
    public function listar(int $usuarioId, array $query): array
    {
        $this->authorization->requireRole($usuarioId, ...ClientePolicy::LECTURA);

        return $this->estados->listar(ListCriteria::from($query, [
            'orden' => 'orden',
            'codigo' => 'codigo',
            'nombre' => 'nombre',
        ]));
    }
}
