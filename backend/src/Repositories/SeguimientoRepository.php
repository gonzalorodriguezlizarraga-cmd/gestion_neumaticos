<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\AsignacionVigente;
use App\Support\ListCriteria;
use PDO;

final class SeguimientoRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string, mixed> $data */
    public function crear(array $data, int $usuarioId): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO seguimientos_comerciales (
                cliente_id, oportunidad_id, usuario_id, tipo, fecha, resultado, proximo_seguimiento, observacion
             ) VALUES (
                :cliente, :oportunidad, :usuario, :tipo, :fecha, :resultado, :proximo, :observacion
             )'
        );
        $statement->bindValue('cliente', $data['cliente_id'], PDO::PARAM_INT);
        $statement->bindValue('oportunidad', $data['oportunidad_id'], $data['oportunidad_id'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->bindValue('usuario', $usuarioId, PDO::PARAM_INT);
        $statement->bindValue('tipo', $data['tipo']);
        $statement->bindValue('fecha', $data['fecha']);
        $this->nulo($statement, 'resultado', $data['resultado']);
        $this->nulo($statement, 'proximo', $data['proximo_seguimiento']);
        $this->nulo($statement, 'observacion', $data['observacion']);
        $statement->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /** @return list<array<string, mixed>> */
    public function deOportunidad(int $oportunidadId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT s.id, s.tipo, s.fecha, s.resultado, s.proximo_seguimiento, s.observacion,
                    u.id AS usuario_id, u.nombres, u.apellidos
             FROM seguimientos_comerciales s
             INNER JOIN usuarios u ON u.id = s.usuario_id
             WHERE s.oportunidad_id = :id
             ORDER BY s.fecha ASC, s.id ASC'
        );
        $statement->bindValue('id', $oportunidadId, PDO::PARAM_INT);
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
            $where[] = 's.cliente_id = :cliente';
            $params['cliente'] = $filtros['cliente_id'];
        }
        if ($filtros['oportunidad_id'] !== null) {
            $where[] = 's.oportunidad_id = :oportunidad';
            $params['oportunidad'] = $filtros['oportunidad_id'];
        }
        if ($filtros['tipo'] !== null) {
            $where[] = 's.tipo = :tipo';
            $params['tipo'] = $filtros['tipo'];
        }
        if ($filtros['usuario_id'] !== null) {
            $where[] = '(s.oportunidad_id IS NULL AND s.usuario_id = :usuario_general OR o.responsable_comercial_id = :usuario_op)';
            $params['usuario_general'] = $filtros['usuario_id'];
            $params['usuario_op'] = $filtros['usuario_id'];
        }
        if ($filtros['alcance_usuario'] !== null) {
            $where[] = 'EXISTS (
                SELECT 1 FROM usuario_clientes uc
                WHERE uc.cliente_id = s.cliente_id AND uc.usuario_id = :alcance AND ' . AsignacionVigente::sql('uc') . '
            )';
            $params['alcance'] = $filtros['alcance_usuario'];
        }
        $sqlWhere = implode(' AND ', $where);
        $from = 'FROM seguimientos_comerciales s
                 INNER JOIN clientes c ON c.id = s.cliente_id
                 INNER JOIN usuarios u ON u.id = s.usuario_id
                 LEFT JOIN oportunidades o ON o.id = s.oportunidad_id';
        $count = $this->pdo->prepare('SELECT COUNT(*) ' . $from . ' WHERE ' . $sqlWhere);
        $this->bind($count, $params);
        $count->execute();
        $total = (int) $count->fetchColumn();
        $statement = $this->pdo->prepare(
            'SELECT s.id, s.tipo, s.fecha, s.resultado, s.proximo_seguimiento, s.observacion, s.oportunidad_id,
                    c.id AS cliente_id, c.razon_social, c.nombre_comercial,
                    u.nombres, u.apellidos, o.titulo AS oportunidad_titulo
             ' . $from . '
             WHERE ' . $sqlWhere . '
             ORDER BY s.fecha DESC, s.id DESC
             LIMIT ' . $criteria->limit . ' OFFSET ' . $criteria->offset
        );
        $this->bind($statement, $params);
        $statement->execute();
        $rows = $statement->fetchAll();

        return ['rows' => is_array($rows) ? $rows : [], 'total' => $total];
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
            $statement->bindValue($key, $value, $key === 'tipo' ? PDO::PARAM_STR : PDO::PARAM_INT);
        }
    }
}
