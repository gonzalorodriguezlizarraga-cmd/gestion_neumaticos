<?php

declare(strict_types=1);

namespace App\Support;

final class AsignacionVigente
{
    /**
     * Única definición de asignación usuario-cliente vigente.
     * El alias lo fija el código; nunca proviene de la petición.
     */
    public static function sql(string $alias = 'uc'): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias)) {
            throw new \InvalidArgumentException('Alias SQL no permitido.');
        }

        return $alias . '.activo = 1'
            . ' AND ' . $alias . '.fecha_inicio <= CURRENT_DATE'
            . ' AND (' . $alias . '.fecha_fin IS NULL OR ' . $alias . '.fecha_fin >= CURRENT_DATE)';
    }
}
