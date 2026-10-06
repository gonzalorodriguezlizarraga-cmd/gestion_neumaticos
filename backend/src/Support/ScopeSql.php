<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use PDOStatement;

final class ScopeSql
{
    /**
     * @param array<string, mixed> $filtros
     * @param array<string, int|string> $params
     */
    public static function cliente(string $column, array $filtros, array &$params, string $prefijo): string
    {
        $sql = '';
        if ($filtros['usuario_scope'] !== null) {
            $nombre = $prefijo . '_scope';
            $sql .= ' AND EXISTS (SELECT 1 FROM usuario_clientes uc'
                . ' WHERE uc.cliente_id = ' . $column
                . ' AND uc.usuario_id = :' . $nombre
                . ' AND ' . AsignacionVigente::sql('uc') . ')';
            $params[$nombre] = (int) $filtros['usuario_scope'];
        }
        if ($filtros['cliente_id'] !== null) {
            $nombre = $prefijo . '_cliente';
            $sql .= ' AND ' . $column . ' = :' . $nombre;
            $params[$nombre] = (int) $filtros['cliente_id'];
        }

        return $sql;
    }

    /**
     * @param array<string, mixed> $filtros
     * @param array<string, int|string> $params
     */
    public static function sedeUnidad(string $alias, array $filtros, array &$params, string $prefijo): string
    {
        $sql = '';
        if ($filtros['sede_id'] !== null) {
            $nombre = $prefijo . '_sede';
            $sql .= ' AND ' . $alias . '.sede_id = :' . $nombre;
            $params[$nombre] = (int) $filtros['sede_id'];
        }
        if ($filtros['flota_id'] !== null) {
            $nombre = $prefijo . '_flota';
            $sql .= ' AND ' . $alias . '.flota_id = :' . $nombre;
            $params[$nombre] = (int) $filtros['flota_id'];
        }

        return $sql;
    }

    /**
     * @param array<string, mixed> $filtros
     * @param array<string, int|string> $params
     */
    public static function montaje(string $neumaticoId, array $filtros, array &$params, string $prefijo): string
    {
        if ($filtros['sede_id'] === null && $filtros['flota_id'] === null) {
            return '';
        }
        $sede = self::sedeUnidad('uu', $filtros, $params, $prefijo);

        return ' AND EXISTS (
            SELECT 1 FROM montajes_neumatico mo
            INNER JOIN unidades uu ON uu.id = mo.unidad_id
            WHERE mo.neumatico_id = ' . $neumaticoId . ' AND mo.montaje_activo_flag = 1' . $sede . ')';
    }

    /** @param array<string, int|string> $params */
    public static function bind(PDOStatement $statement, array $params): void
    {
        foreach ($params as $name => $value) {
            $statement->bindValue((string) $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
    }

    /** @param array<string, int|string|null> $params */
    public static function bindMixto(PDOStatement $statement, array $params): void
    {
        foreach ($params as $name => $value) {
            if ($value === null) {
                $statement->bindValue((string) $name, null, PDO::PARAM_NULL);
            } else {
                $statement->bindValue((string) $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
        }
    }
}
