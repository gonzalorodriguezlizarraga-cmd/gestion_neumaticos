<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\ListCriteria;
use PDO;

final class ConfiguracionUnidadRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{rows:list<array<string,mixed>>,total:int} */
    public function listar(ListCriteria $criteria): array
    {
        $where = ['1 = 1'];
        $params = [];
        if ($criteria->activo !== null) {
            $where[] = 'activo = :activo';
            $params['activo'] = $criteria->activo;
        }
        $like = $criteria->likePattern();
        if ($like !== null) {
            $where[] = '(nombre LIKE :search_nombre OR IFNULL(descripcion, \'\') LIKE :search_descripcion)';
            $params['search_nombre'] = $like;
            $params['search_descripcion'] = $like;
        }
        $sqlWhere = implode(' AND ', $where);
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM configuraciones_unidad WHERE ' . $sqlWhere);
        $this->bind($count, $params);
        $count->execute();
        $total = (int) $count->fetchColumn();
        $statement = $this->pdo->prepare(
            'SELECT id, nombre, descripcion, cantidad_ejes, cantidad_posiciones, activo, creado_en, actualizado_en
             FROM configuraciones_unidad
             WHERE ' . $sqlWhere . '
             ORDER BY ' . $criteria->sortExpression . ' ' . $criteria->direction . ', id ASC
             LIMIT ' . $criteria->limit . ' OFFSET ' . $criteria->offset
        );
        $this->bind($statement, $params);
        $statement->execute();

        return [
            'rows' => array_map(fn (array $row): array => $this->presentar($row, false), $statement->fetchAll()),
            'total' => $total,
        ];
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, nombre, descripcion, cantidad_ejes, cantidad_posiciones, activo, creado_en, actualizado_en
             FROM configuraciones_unidad WHERE id = :id LIMIT 1'
        );
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function enUso(int $id): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT (
                (SELECT COUNT(*) FROM unidades WHERE configuracion_id = :id)
                + (SELECT COUNT(*) FROM montajes_neumatico WHERE configuracion_id = :id_montaje)
             )'
        );
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->bindValue('id_montaje', $id, PDO::PARAM_INT);
        $statement->execute();

        return (int) $statement->fetchColumn() > 0;
    }

    /** @param array<string, mixed> $data */
    public function crear(array $data): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO configuraciones_unidad (nombre, descripcion, cantidad_ejes, cantidad_posiciones, activo)
             VALUES (:nombre, :descripcion, :cantidad_ejes, :cantidad_posiciones, 1)'
        );
        $statement->bindValue('nombre', $data['nombre']);
        $statement->bindValue('descripcion', $data['descripcion']);
        $statement->bindValue('cantidad_ejes', $data['cantidad_ejes'], PDO::PARAM_INT);
        $statement->bindValue('cantidad_posiciones', $data['cantidad_posiciones'], PDO::PARAM_INT);
        $statement->execute();
        $id = (int) $this->pdo->lastInsertId();
        $this->insertarEstructura($id, $data['ejes']);

        return $id;
    }

    /** @param array{nombre:string,descripcion:?string} $data */
    public function actualizarDescripcion(int $id, array $data): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE configuraciones_unidad SET nombre = :nombre, descripcion = :descripcion WHERE id = :id'
        );
        $statement->bindValue('nombre', $data['nombre']);
        $statement->bindValue('descripcion', $data['descripcion']);
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
    }

    /** @param array<string, mixed> $data */
    public function reemplazarEstructura(int $id, array $data): void
    {
        $this->actualizarDescripcion($id, $data);
        $counts = $this->pdo->prepare(
            'UPDATE configuraciones_unidad
             SET cantidad_ejes = :ejes, cantidad_posiciones = :posiciones
             WHERE id = :id'
        );
        $counts->bindValue('ejes', $data['cantidad_ejes'], PDO::PARAM_INT);
        $counts->bindValue('posiciones', $data['cantidad_posiciones'], PDO::PARAM_INT);
        $counts->bindValue('id', $id, PDO::PARAM_INT);
        $counts->execute();
        $borrarPosiciones = $this->pdo->prepare('DELETE FROM configuracion_posiciones WHERE configuracion_id = :id');
        $borrarPosiciones->bindValue('id', $id, PDO::PARAM_INT);
        $borrarPosiciones->execute();
        $borrarEjes = $this->pdo->prepare('DELETE FROM configuracion_ejes WHERE configuracion_id = :id');
        $borrarEjes->bindValue('id', $id, PDO::PARAM_INT);
        $borrarEjes->execute();
        $this->insertarEstructura($id, $data['ejes']);
    }

    public function cambiarActivo(int $id, bool $activo): void
    {
        $statement = $this->pdo->prepare('UPDATE configuraciones_unidad SET activo = :activo WHERE id = :id');
        $statement->bindValue('activo', $activo ? 1 : 0, PDO::PARAM_INT);
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
    }

    /** @param list<array<string, mixed>> $ejes */
    public function insertarEstructura(int $configuracionId, array $ejes): void
    {
        $ejeSql = $this->pdo->prepare(
            'INSERT INTO configuracion_ejes (configuracion_id, numero_eje, nombre, orden)
             VALUES (:configuracion_id, :numero_eje, :nombre, :orden)'
        );
        $posicionSql = $this->pdo->prepare(
            'INSERT INTO configuracion_posiciones (configuracion_id, eje_id, codigo, lado, ubicacion, orden, activo)
             VALUES (:configuracion_id, :eje_id, :codigo, :lado, :ubicacion, :orden, :activo)'
        );
        foreach ($ejes as $eje) {
            $ejeSql->bindValue('configuracion_id', $configuracionId, PDO::PARAM_INT);
            $ejeSql->bindValue('numero_eje', $eje['numero_eje'], PDO::PARAM_INT);
            $ejeSql->bindValue('nombre', $eje['nombre']);
            $ejeSql->bindValue('orden', $eje['orden'], PDO::PARAM_INT);
            $ejeSql->execute();
            $ejeId = (int) $this->pdo->lastInsertId();
            foreach ($eje['posiciones'] as $posicion) {
                $posicionSql->bindValue('configuracion_id', $configuracionId, PDO::PARAM_INT);
                $posicionSql->bindValue('eje_id', $ejeId, PDO::PARAM_INT);
                $posicionSql->bindValue('codigo', $posicion['codigo']);
                $posicionSql->bindValue('lado', $posicion['lado']);
                $posicionSql->bindValue('ubicacion', $posicion['ubicacion']);
                $posicionSql->bindValue('orden', $posicion['orden'], PDO::PARAM_INT);
                $posicionSql->bindValue('activo', $posicion['activo'] ? 1 : 0, PDO::PARAM_INT);
                $posicionSql->execute();
            }
        }
    }

    /** @return array<string, mixed> */
    public function detalle(int $id): array
    {
        $cabecera = $this->find($id);
        if ($cabecera === null) {
            return [];
        }
        $ejes = $this->pdo->prepare(
            'SELECT id, numero_eje, nombre, orden FROM configuracion_ejes
             WHERE configuracion_id = :id ORDER BY orden ASC, id ASC'
        );
        $ejes->bindValue('id', $id, PDO::PARAM_INT);
        $ejes->execute();
        $posiciones = $this->pdo->prepare(
            'SELECT id, eje_id, codigo, lado, ubicacion, orden, activo
             FROM configuracion_posiciones
             WHERE configuracion_id = :id
             ORDER BY orden ASC, id ASC'
        );
        $posiciones->bindValue('id', $id, PDO::PARAM_INT);
        $posiciones->execute();
        $porEje = [];
        foreach ($posiciones->fetchAll() as $posicion) {
            $porEje[(int) $posicion['eje_id']][] = [
                'id' => (int) $posicion['id'],
                'codigo' => $posicion['codigo'],
                'lado' => $posicion['lado'],
                'ubicacion' => $posicion['ubicacion'],
                'orden' => (int) $posicion['orden'],
                'activo' => (int) $posicion['activo'] === 1,
            ];
        }
        $arbol = [];
        foreach ($ejes->fetchAll() as $eje) {
            $arbol[] = [
                'id' => (int) $eje['id'],
                'numero_eje' => (int) $eje['numero_eje'],
                'nombre' => $eje['nombre'],
                'orden' => (int) $eje['orden'],
                'posiciones' => $porEje[(int) $eje['id']] ?? [],
            ];
        }
        $publico = $this->presentar($cabecera, true);
        $publico['ejes'] = $arbol;

        return $publico;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public function presentar(array $row, bool $conUso): array
    {
        $data = [
            'id' => (int) $row['id'],
            'nombre' => $row['nombre'],
            'descripcion' => $row['descripcion'],
            'cantidad_ejes' => (int) $row['cantidad_ejes'],
            'cantidad_posiciones' => (int) $row['cantidad_posiciones'],
            'activo' => (int) $row['activo'] === 1,
            'creado_en' => $row['creado_en'],
            'actualizado_en' => $row['actualizado_en'],
        ];
        if ($conUso) {
            $data['estructura_bloqueada'] = $this->enUso((int) $row['id']);
        }

        return $data;
    }

    /** @param array<string, mixed> $params */
    private function bind(\PDOStatement $statement, array $params): void
    {
        foreach ($params as $name => $value) {
            $statement->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
    }
}
