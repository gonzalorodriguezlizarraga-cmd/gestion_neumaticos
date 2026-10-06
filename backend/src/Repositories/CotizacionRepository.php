<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\AsignacionVigente;
use App\Support\ListCriteria;
use PDO;

final class CotizacionRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null */
    public function bloquear(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, cliente_id, oportunidad_id, numero, fecha, estado, moneda, subtotal, total, observacion, creado_por
             FROM cotizaciones
             WHERE id = :id
             FOR UPDATE'
        );
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $data @param list<array<string, mixed>> $detalles */
    public function crear(array $data, array $detalles, string $numero, string $subtotal, string $total, int $usuarioId): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO cotizaciones (
                cliente_id, oportunidad_id, numero, fecha, estado, moneda, subtotal, total, observacion, creado_por
             ) VALUES (
                :cliente, :oportunidad, :numero, :fecha, \'BORRADOR\', :moneda, :subtotal, :total, :observacion, :creado
             )'
        );
        $statement->bindValue('cliente', $data['cliente_id'], PDO::PARAM_INT);
        $statement->bindValue('oportunidad', $data['oportunidad_id'], $data['oportunidad_id'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->bindValue('numero', $numero);
        $statement->bindValue('fecha', $data['fecha']);
        $statement->bindValue('moneda', $data['moneda']);
        $statement->bindValue('subtotal', $subtotal);
        $statement->bindValue('total', $total);
        $this->nulo($statement, 'observacion', $data['observacion']);
        $statement->bindValue('creado', $usuarioId, PDO::PARAM_INT);
        $statement->execute();
        $id = (int) $this->pdo->lastInsertId();
        $this->insertarDetalles($id, $detalles);

        return $id;
    }

    /** @param array<string, mixed> $data @param list<array<string, mixed>> $detalles */
    public function actualizar(int $id, array $data, array $detalles, string $subtotal, string $total): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE cotizaciones
             SET fecha = :fecha, moneda = :moneda, subtotal = :subtotal, total = :total, observacion = :observacion
             WHERE id = :id AND estado = \'BORRADOR\''
        );
        $statement->bindValue('fecha', $data['fecha']);
        $statement->bindValue('moneda', $data['moneda']);
        $statement->bindValue('subtotal', $subtotal);
        $statement->bindValue('total', $total);
        $this->nulo($statement, 'observacion', $data['observacion']);
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
        $borrar = $this->pdo->prepare('DELETE FROM cotizacion_detalles WHERE cotizacion_id = :id');
        $borrar->bindValue('id', $id, PDO::PARAM_INT);
        $borrar->execute();
        $this->insertarDetalles($id, $detalles);
    }

    public function marcarEstado(int $id, string $nuevo, string $actual): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE cotizaciones SET estado = :nuevo WHERE id = :id AND estado = :actual'
        );
        $statement->bindValue('nuevo', $nuevo);
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->bindValue('actual', $actual);
        $statement->execute();

        return $statement->rowCount() === 1;
    }

    /** @return array<string, mixed>|null */
    public function ficha(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT c.id, c.cliente_id, c.oportunidad_id, c.numero, c.fecha, c.estado, c.moneda,
                    c.subtotal, c.total, c.observacion, c.creado_por, c.creado_en,
                    cl.razon_social, cl.nombre_comercial,
                    o.titulo AS oportunidad_titulo, o.responsable_comercial_id, o.valor_estimado
             FROM cotizaciones c
             INNER JOIN clientes cl ON cl.id = c.cliente_id
             LEFT JOIN oportunidades o ON o.id = c.oportunidad_id
             WHERE c.id = :id'
        );
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return list<array<string, mixed>> */
    public function detalles(int $cotizacionId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT d.id, d.descripcion, d.cantidad, d.precio_unitario, d.subtotal,
                    d.modelo_neumatico_id, mo.nombre AS modelo_nombre,
                    d.medida_neumatico_id, me.descripcion AS medida_descripcion
             FROM cotizacion_detalles d
             LEFT JOIN modelos_neumatico mo ON mo.id = d.modelo_neumatico_id
             LEFT JOIN medidas_neumatico me ON me.id = d.medida_neumatico_id
             WHERE d.cotizacion_id = :id
             ORDER BY d.id ASC'
        );
        $statement->bindValue('id', $cotizacionId, PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /** @param array<string, mixed> $filtros @return array{rows:list<array<string,mixed>>,total:int} */
    public function listar(ListCriteria $criteria, array $filtros): array
    {
        $where = ['1 = 1'];
        $params = [];
        if ($filtros['cliente_id'] !== null) {
            $where[] = 'c.cliente_id = :cliente';
            $params['cliente'] = $filtros['cliente_id'];
        }
        if ($filtros['oportunidad_id'] !== null) {
            $where[] = 'c.oportunidad_id = :oportunidad';
            $params['oportunidad'] = $filtros['oportunidad_id'];
        }
        if ($filtros['estado'] !== null) {
            $where[] = 'c.estado = :estado';
            $params['estado'] = $filtros['estado'];
        }
        if ($filtros['usuario_id'] !== null) {
            $where[] = '(o.responsable_comercial_id = :responsable OR (c.oportunidad_id IS NULL AND c.creado_por = :creado))';
            $params['responsable'] = $filtros['usuario_id'];
            $params['creado'] = $filtros['usuario_id'];
        }
        if ($filtros['alcance_usuario'] !== null) {
            $where[] = 'EXISTS (
                SELECT 1 FROM usuario_clientes uc
                WHERE uc.cliente_id = c.cliente_id AND uc.usuario_id = :alcance AND ' . AsignacionVigente::sql('uc') . '
            )';
            $params['alcance'] = $filtros['alcance_usuario'];
        }
        if ($criteria->search !== null) {
            $where[] = '(c.numero LIKE :search_numero ESCAPE \'\\\\\' OR cl.razon_social LIKE :search_razon ESCAPE \'\\\\\')';
            $params['search_numero'] = $criteria->likePattern();
            $params['search_razon'] = $criteria->likePattern();
        }
        $sqlWhere = implode(' AND ', $where);
        $from = 'FROM cotizaciones c
                 INNER JOIN clientes cl ON cl.id = c.cliente_id
                 LEFT JOIN oportunidades o ON o.id = c.oportunidad_id';
        $count = $this->pdo->prepare('SELECT COUNT(*) ' . $from . ' WHERE ' . $sqlWhere);
        $this->bind($count, $params);
        $count->execute();
        $total = (int) $count->fetchColumn();
        $statement = $this->pdo->prepare(
            'SELECT c.id, c.numero, c.fecha, c.estado, c.moneda, c.total, c.oportunidad_id,
                    cl.id AS cliente_id, cl.razon_social, cl.nombre_comercial, o.titulo AS oportunidad_titulo
             ' . $from . '
             WHERE ' . $sqlWhere . '
             ORDER BY c.fecha DESC, c.id DESC
             LIMIT ' . $criteria->limit . ' OFFSET ' . $criteria->offset
        );
        $this->bind($statement, $params);
        $statement->execute();
        $rows = $statement->fetchAll();

        return ['rows' => is_array($rows) ? $rows : [], 'total' => $total];
    }

    /** @return list<array<string, mixed>> */
    public function porOportunidad(int $oportunidadId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, numero, fecha, estado, moneda, subtotal, total
             FROM cotizaciones
             WHERE oportunidad_id = :id
             ORDER BY fecha DESC, id DESC'
        );
        $statement->bindValue('id', $oportunidadId, PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /** @return list<array<string, mixed>> */
    public function recientes(int $clienteId, ?int $responsableId): array
    {
        $extra = $responsableId === null
            ? ''
            : ' AND (o.responsable_comercial_id = :responsable OR (c.oportunidad_id IS NULL AND c.creado_por = :creado))';
        $statement = $this->pdo->prepare(
            'SELECT c.id, c.numero, c.fecha, c.estado, c.moneda, c.total, o.titulo AS oportunidad_titulo
             FROM cotizaciones c
             LEFT JOIN oportunidades o ON o.id = c.oportunidad_id
             WHERE c.cliente_id = :cliente' . $extra . '
             ORDER BY c.fecha DESC, c.id DESC
             LIMIT 5'
        );
        $statement->bindValue('cliente', $clienteId, PDO::PARAM_INT);
        if ($responsableId !== null) {
            $statement->bindValue('responsable', $responsableId, PDO::PARAM_INT);
            $statement->bindValue('creado', $responsableId, PDO::PARAM_INT);
        }
        $statement->execute();
        $rows = $statement->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /** @param list<array<string, mixed>> $detalles */
    private function insertarDetalles(int $cotizacionId, array $detalles): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO cotizacion_detalles (
                cotizacion_id, descripcion, modelo_neumatico_id, medida_neumatico_id, cantidad, precio_unitario, subtotal
             ) VALUES (
                :cotizacion, :descripcion, :modelo, :medida, :cantidad, :precio, :subtotal
             )'
        );
        foreach ($detalles as $detalle) {
            $statement->bindValue('cotizacion', $cotizacionId, PDO::PARAM_INT);
            $statement->bindValue('descripcion', $detalle['descripcion']);
            $statement->bindValue('modelo', $detalle['modelo_neumatico_id'], $detalle['modelo_neumatico_id'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $statement->bindValue('medida', $detalle['medida_neumatico_id'], $detalle['medida_neumatico_id'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $statement->bindValue('cantidad', $detalle['cantidad']);
            $statement->bindValue('precio', $detalle['precio_unitario']);
            $statement->bindValue('subtotal', $detalle['subtotal']);
            $statement->execute();
        }
    }

    private function nulo(\PDOStatement $statement, string $name, mixed $value): void
    {
        if ($value === null) {
            $statement->bindValue($name, null, PDO::PARAM_NULL);
            return;
        }
        $statement->bindValue($name, $value);
    }

    /** @param array<string, mixed> $params */
    private function bind(\PDOStatement $statement, array $params): void
    {
        foreach ($params as $key => $value) {
            if ($key === 'estado' || $key === 'search_numero' || $key === 'search_razon') {
                $statement->bindValue($key, $value);
                continue;
            }
            $statement->bindValue($key, $value, PDO::PARAM_INT);
        }
    }
}
