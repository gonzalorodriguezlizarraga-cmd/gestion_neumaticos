<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\AsignacionVigente;
use App\Support\ListCriteria;
use PDO;

final class OportunidadRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function estados(): array
    {
        $rows = $this->pdo->query(
            'SELECT id, codigo, nombre, orden, es_final FROM estados_oportunidad WHERE activo = 1 ORDER BY orden ASC'
        )->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    public function estadoId(string $codigo): int
    {
        $statement = $this->pdo->prepare('SELECT id FROM estados_oportunidad WHERE codigo = :codigo AND activo = 1');
        $statement->execute(['codigo' => $codigo]);

        return (int) $statement->fetchColumn();
    }

    /** @return array<string, mixed>|null */
    public function cliente(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, razon_social, nombre_comercial, estado, eliminado FROM clientes WHERE id = :id'
        );
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function modeloActivo(int $id): bool
    {
        $statement = $this->pdo->prepare('SELECT id FROM modelos_neumatico WHERE id = :id AND activo = 1');
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();

        return (bool) $statement->fetchColumn();
    }

    public function medidaActiva(int $id): bool
    {
        $statement = $this->pdo->prepare('SELECT id FROM medidas_neumatico WHERE id = :id AND activo = 1');
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();

        return (bool) $statement->fetchColumn();
    }

    public function comercialVigente(int $usuarioId, int $clienteId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT cr.id
             FROM cliente_responsables cr
             INNER JOIN usuarios u ON u.id = cr.usuario_id AND u.eliminado = 0 AND u.estado = \'ACTIVO\'
             INNER JOIN usuario_roles ur ON ur.usuario_id = u.id
             INNER JOIN roles r ON r.id = ur.rol_id AND r.activo = 1 AND r.codigo = \'VENDEDOR\'
             WHERE cr.cliente_id = :cliente
               AND cr.usuario_id = :usuario
               AND cr.tipo_responsabilidad = \'COMERCIAL\'
               AND cr.fecha_inicio <= CURRENT_DATE
               AND (cr.fecha_fin IS NULL OR cr.fecha_fin >= CURRENT_DATE)'
        );
        $statement->bindValue('cliente', $clienteId, PDO::PARAM_INT);
        $statement->bindValue('usuario', $usuarioId, PDO::PARAM_INT);
        $statement->execute();

        return (bool) $statement->fetchColumn();
    }

    /** @return array<string, mixed>|null */
    public function bloquear(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT o.id, o.cliente_id, o.responsable_comercial_id, o.estado_id, o.origen, o.alerta_id,
                    o.titulo, o.descripcion, o.fecha_deteccion, o.fecha_estimada_necesidad, o.valor_estimado, o.moneda,
                    e.codigo AS estado_codigo, e.es_final
             FROM oportunidades o
             INNER JOIN estados_oportunidad e ON e.id = o.estado_id
             WHERE o.id = :id
             FOR UPDATE OF o'
        );
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function bloquearAlerta(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT a.id, a.cliente_id, a.titulo, a.descripcion, e.codigo AS estado_codigo
             FROM alertas a
             INNER JOIN estados_alerta e ON e.id = a.estado_id
             WHERE a.id = :id
             FOR UPDATE OF a'
        );
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function existePorAlerta(int $alertaId): bool
    {
        $statement = $this->pdo->prepare('SELECT id FROM oportunidades WHERE alerta_id = :alerta LIMIT 1');
        $statement->bindValue('alerta', $alertaId, PDO::PARAM_INT);
        $statement->execute();

        return (bool) $statement->fetchColumn();
    }

    /** @param array<string, mixed> $data */
    public function crear(array $data, int $estadoId, string $origen, ?int $alertaId, int $usuarioId): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO oportunidades (
                cliente_id, responsable_comercial_id, estado_id, origen, alerta_id, titulo, descripcion,
                fecha_deteccion, fecha_estimada_necesidad, valor_estimado, moneda, creado_por
             ) VALUES (
                :cliente, :responsable, :estado, :origen, :alerta, :titulo, :descripcion,
                :deteccion, :necesidad, :valor, :moneda, :creado
             )'
        );
        $statement->bindValue('cliente', $data['cliente_id'], PDO::PARAM_INT);
        $statement->bindValue('responsable', $data['responsable_comercial_id'], PDO::PARAM_INT);
        $statement->bindValue('estado', $estadoId, PDO::PARAM_INT);
        $statement->bindValue('origen', $origen);
        $statement->bindValue('alerta', $alertaId, $alertaId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->bindValue('titulo', $data['titulo']);
        $this->nulo($statement, 'descripcion', $data['descripcion']);
        $statement->bindValue('deteccion', $data['fecha_deteccion']);
        $this->nulo($statement, 'necesidad', $data['fecha_estimada_necesidad']);
        $this->nulo($statement, 'valor', $data['valor_estimado']);
        $this->nulo($statement, 'moneda', $data['moneda']);
        $statement->bindValue('creado', $usuarioId, PDO::PARAM_INT);
        $statement->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /** @param list<array<string, mixed>> $detalles */
    public function reemplazarDetalles(int $oportunidadId, array $detalles): void
    {
        $borrar = $this->pdo->prepare('DELETE FROM oportunidad_detalles WHERE oportunidad_id = :id');
        $borrar->bindValue('id', $oportunidadId, PDO::PARAM_INT);
        $borrar->execute();
        $insertar = $this->pdo->prepare(
            'INSERT INTO oportunidad_detalles (oportunidad_id, medida_neumatico_id, modelo_neumatico_id, cantidad, precio_estimado, observacion)
             VALUES (:oportunidad, :medida, :modelo, :cantidad, :precio, :observacion)'
        );
        foreach ($detalles as $detalle) {
            $insertar->bindValue('oportunidad', $oportunidadId, PDO::PARAM_INT);
            $insertar->bindValue('medida', $detalle['medida_neumatico_id'], $detalle['medida_neumatico_id'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $insertar->bindValue('modelo', $detalle['modelo_neumatico_id'], $detalle['modelo_neumatico_id'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
            $insertar->bindValue('cantidad', $detalle['cantidad']);
            $this->nulo($insertar, 'precio', $detalle['precio_estimado']);
            $this->nulo($insertar, 'observacion', $detalle['observacion']);
            $insertar->execute();
        }
    }

    /** @param array<string, mixed> $data */
    public function actualizar(int $id, array $data, int $usuarioId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE oportunidades
             SET titulo = :titulo, descripcion = :descripcion, fecha_estimada_necesidad = :necesidad,
                 valor_estimado = :valor, moneda = :moneda, responsable_comercial_id = :responsable,
                 actualizado_por = :usuario
             WHERE id = :id'
        );
        $statement->bindValue('titulo', $data['titulo']);
        $this->nulo($statement, 'descripcion', $data['descripcion']);
        $this->nulo($statement, 'necesidad', $data['fecha_estimada_necesidad']);
        $this->nulo($statement, 'valor', $data['valor_estimado']);
        $this->nulo($statement, 'moneda', $data['moneda']);
        $statement->bindValue('responsable', $data['responsable_comercial_id'], PDO::PARAM_INT);
        $statement->bindValue('usuario', $usuarioId, PDO::PARAM_INT);
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
    }

    public function marcarEstado(int $id, int $estadoNuevo, int $estadoActual, int $usuarioId): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE oportunidades
             SET estado_id = :nuevo, actualizado_por = :usuario
             WHERE id = :id AND estado_id = :actual'
        );
        $statement->bindValue('nuevo', $estadoNuevo, PDO::PARAM_INT);
        $statement->bindValue('usuario', $usuarioId, PDO::PARAM_INT);
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->bindValue('actual', $estadoActual, PDO::PARAM_INT);
        $statement->execute();

        return $statement->rowCount() === 1;
    }

    public function historial(int $oportunidadId, ?int $anterior, int $nuevo, int $usuarioId, ?string $motivo): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO oportunidad_estado_historial (oportunidad_id, estado_anterior_id, estado_nuevo_id, usuario_id, motivo)
             VALUES (:oportunidad, :anterior, :nuevo, :usuario, :motivo)'
        );
        $statement->bindValue('oportunidad', $oportunidadId, PDO::PARAM_INT);
        $statement->bindValue('anterior', $anterior, $anterior === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->bindValue('nuevo', $nuevo, PDO::PARAM_INT);
        $statement->bindValue('usuario', $usuarioId, PDO::PARAM_INT);
        $this->nulo($statement, 'motivo', $motivo);
        $statement->execute();
    }

    /** @param array<string, mixed> $filtros @return array{rows:list<array<string,mixed>>,total:int} */
    public function listar(ListCriteria $criteria, array $filtros): array
    {
        [$where, $params] = $this->filtros($criteria, $filtros);
        $count = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM oportunidades o
             INNER JOIN clientes c ON c.id = o.cliente_id
             WHERE ' . $where
        );
        $this->bind($count, $params);
        $count->execute();
        $total = (int) $count->fetchColumn();
        $statement = $this->pdo->prepare(
            'SELECT o.id, o.titulo, o.origen, o.fecha_deteccion, o.fecha_estimada_necesidad, o.valor_estimado, o.moneda,
                    c.id AS cliente_id, c.razon_social, c.nombre_comercial,
                    u.id AS responsable_id, u.nombres AS responsable_nombres, u.apellidos AS responsable_apellidos,
                    e.codigo AS estado_codigo, e.nombre AS estado_nombre,
                    (
                        SELECT s.proximo_seguimiento
                        FROM seguimientos_comerciales s
                        WHERE s.oportunidad_id = o.id AND s.proximo_seguimiento IS NOT NULL
                        ORDER BY s.fecha DESC, s.id DESC
                        LIMIT 1
                    ) AS proximo_seguimiento
             FROM oportunidades o
             INNER JOIN clientes c ON c.id = o.cliente_id
             INNER JOIN usuarios u ON u.id = o.responsable_comercial_id
             INNER JOIN estados_oportunidad e ON e.id = o.estado_id
             WHERE ' . $where . '
             ORDER BY ' . $criteria->sortExpression . ' ' . $criteria->direction . ', o.id DESC
             LIMIT ' . $criteria->limit . ' OFFSET ' . $criteria->offset
        );
        $this->bind($statement, $params);
        $statement->execute();
        $rows = $statement->fetchAll();

        return ['rows' => is_array($rows) ? $rows : [], 'total' => $total];
    }

    /** @return array<string, mixed>|null */
    public function ficha(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT o.id, o.cliente_id, o.responsable_comercial_id, o.titulo, o.descripcion, o.origen, o.alerta_id, o.fecha_deteccion,
                    o.fecha_estimada_necesidad, o.valor_estimado, o.moneda, o.creado_en,
                    c.razon_social, c.nombre_comercial, c.estado AS cliente_estado,
                    u.id AS responsable_id, u.nombres AS responsable_nombres, u.apellidos AS responsable_apellidos,
                    e.id AS estado_id, e.codigo AS estado_codigo, e.nombre AS estado_nombre, e.es_final,
                    a.titulo AS alerta_titulo
             FROM oportunidades o
             INNER JOIN clientes c ON c.id = o.cliente_id
             INNER JOIN usuarios u ON u.id = o.responsable_comercial_id
             INNER JOIN estados_oportunidad e ON e.id = o.estado_id
             LEFT JOIN alertas a ON a.id = o.alerta_id
             WHERE o.id = :id'
        );
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    /** @return list<array<string, mixed>> */
    public function detalles(int $oportunidadId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT d.id, d.cantidad, d.precio_estimado, d.observacion,
                    d.modelo_neumatico_id, mo.nombre AS modelo_nombre,
                    d.medida_neumatico_id, me.descripcion AS medida_descripcion
             FROM oportunidad_detalles d
             LEFT JOIN modelos_neumatico mo ON mo.id = d.modelo_neumatico_id
             LEFT JOIN medidas_neumatico me ON me.id = d.medida_neumatico_id
             WHERE d.oportunidad_id = :id
             ORDER BY d.id ASC'
        );
        $statement->bindValue('id', $oportunidadId, PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /** @return list<array<string, mixed>> */
    public function historialDe(int $oportunidadId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT h.id, h.fecha_cambio, h.motivo,
                    ea.codigo AS anterior_codigo, ea.nombre AS anterior_nombre,
                    en.codigo AS nuevo_codigo, en.nombre AS nuevo_nombre,
                    u.nombres, u.apellidos
             FROM oportunidad_estado_historial h
             LEFT JOIN estados_oportunidad ea ON ea.id = h.estado_anterior_id
             INNER JOIN estados_oportunidad en ON en.id = h.estado_nuevo_id
             INNER JOIN usuarios u ON u.id = h.usuario_id
             WHERE h.oportunidad_id = :id
             ORDER BY h.fecha_cambio ASC, h.id ASC'
        );
        $statement->bindValue('id', $oportunidadId, PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /** @param array<string, mixed> $filtros @return array{rows:list<array<string,mixed>>,total:int} */
    public function clientes(ListCriteria $criteria, array $filtros): array
    {
        $params = [];
        $where = ['c.eliminado = 0'];
        if ($filtros['modo'] === 'vendedor') {
            $where[] = 'EXISTS (
                SELECT 1 FROM usuario_clientes uc
                WHERE uc.cliente_id = c.id AND uc.usuario_id = :usuario_scope AND ' . AsignacionVigente::sql('uc') . '
            )';
            $where[] = 'EXISTS (
                SELECT 1 FROM cliente_responsables cr
                INNER JOIN usuarios u ON u.id = cr.usuario_id AND u.eliminado = 0 AND u.estado = \'ACTIVO\'
                INNER JOIN usuario_roles ur ON ur.usuario_id = u.id
                INNER JOIN roles r ON r.id = ur.rol_id AND r.activo = 1 AND r.codigo = \'VENDEDOR\'
                WHERE cr.cliente_id = c.id AND cr.usuario_id = :usuario_comercial
                  AND cr.tipo_responsabilidad = \'COMERCIAL\'
                  AND cr.fecha_inicio <= CURRENT_DATE
                  AND (cr.fecha_fin IS NULL OR cr.fecha_fin >= CURRENT_DATE)
            )';
            $params['usuario_scope'] = $filtros['usuario_id'];
            $params['usuario_comercial'] = $filtros['usuario_id'];
        } elseif ($filtros['modo'] === 'gestor') {
            $where[] = 'EXISTS (
                SELECT 1 FROM usuario_clientes uc
                WHERE uc.cliente_id = c.id AND uc.usuario_id = :usuario_scope AND ' . AsignacionVigente::sql('uc') . '
            )';
            $params['usuario_scope'] = $filtros['usuario_id'];
        }
        if ($criteria->search !== null) {
            $where[] = '(c.razon_social LIKE :search_razon ESCAPE \'\\\\\' OR IFNULL(c.nombre_comercial, \'\') LIKE :search_comercial ESCAPE \'\\\\\')';
            $like = $criteria->likePattern();
            $params['search_razon'] = $like;
            $params['search_comercial'] = $like;
        }
        $sqlWhere = implode(' AND ', $where);
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM clientes c WHERE ' . $sqlWhere);
        $this->bind($count, $params);
        $count->execute();
        $statement = $this->pdo->prepare(
            'SELECT c.id, c.razon_social, c.nombre_comercial, c.estado
             FROM clientes c
             WHERE ' . $sqlWhere . '
             ORDER BY c.razon_social ASC, c.id ASC
             LIMIT ' . $criteria->limit . ' OFFSET ' . $criteria->offset
        );
        $this->bind($statement, $params);
        $statement->execute();
        $rows = $statement->fetchAll();

        return ['rows' => is_array($rows) ? $rows : [], 'total' => (int) $count->fetchColumn()];
    }

    /** @return list<array<string, mixed>> */
    public function responsables(int $clienteId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT u.id, u.nombres, u.apellidos
             FROM cliente_responsables cr
             INNER JOIN usuarios u ON u.id = cr.usuario_id AND u.eliminado = 0 AND u.estado = \'ACTIVO\'
             INNER JOIN usuario_roles ur ON ur.usuario_id = u.id
             INNER JOIN roles r ON r.id = ur.rol_id AND r.activo = 1 AND r.codigo = \'VENDEDOR\'
             WHERE cr.cliente_id = :cliente
               AND cr.tipo_responsabilidad = \'COMERCIAL\'
               AND cr.fecha_inicio <= CURRENT_DATE
               AND (cr.fecha_fin IS NULL OR cr.fecha_fin >= CURRENT_DATE)
             ORDER BY u.apellidos ASC, u.nombres ASC, u.id ASC'
        );
        $statement->bindValue('cliente', $clienteId, PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /** @return array<string, mixed> */
    public function resumen(int $clienteId, ?int $responsableId): array
    {
        $responsable = $responsableId === null ? '' : ' AND o.responsable_comercial_id = :responsable';
        $abiertas = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM oportunidades o
             INNER JOIN estados_oportunidad e ON e.id = o.estado_id AND e.es_final = 0
             WHERE o.cliente_id = :cliente' . $responsable
        );
        $abiertas->bindValue('cliente', $clienteId, PDO::PARAM_INT);
        if ($responsableId !== null) {
            $abiertas->bindValue('responsable', $responsableId, PDO::PARAM_INT);
        }
        $abiertas->execute();
        $actividadSql = $responsableId === null
            ? 'SELECT MAX(fecha) FROM seguimientos_comerciales WHERE cliente_id = :cliente'
            : 'SELECT MAX(s.fecha)
               FROM seguimientos_comerciales s
               LEFT JOIN oportunidades o ON o.id = s.oportunidad_id
               WHERE s.cliente_id = :cliente
                 AND (s.oportunidad_id IS NULL AND s.usuario_id = :responsable OR o.responsable_comercial_id = :responsable_op)';
        $actividad = $this->pdo->prepare($actividadSql);
        $actividad->bindValue('cliente', $clienteId, PDO::PARAM_INT);
        if ($responsableId !== null) {
            $actividad->bindValue('responsable', $responsableId, PDO::PARAM_INT);
            $actividad->bindValue('responsable_op', $responsableId, PDO::PARAM_INT);
        }
        $actividad->execute();
        $proximoSql = $responsableId === null
            ? 'SELECT proximo_seguimiento FROM seguimientos_comerciales
               WHERE cliente_id = :cliente AND proximo_seguimiento IS NOT NULL
               ORDER BY fecha DESC, id DESC LIMIT 1'
            : 'SELECT s.proximo_seguimiento
               FROM seguimientos_comerciales s
               LEFT JOIN oportunidades o ON o.id = s.oportunidad_id
               WHERE s.cliente_id = :cliente AND s.proximo_seguimiento IS NOT NULL
                 AND (s.oportunidad_id IS NULL AND s.usuario_id = :responsable OR o.responsable_comercial_id = :responsable_op)
               ORDER BY s.fecha DESC, s.id DESC LIMIT 1';
        $proximo = $this->pdo->prepare($proximoSql);
        $proximo->bindValue('cliente', $clienteId, PDO::PARAM_INT);
        if ($responsableId !== null) {
            $proximo->bindValue('responsable', $responsableId, PDO::PARAM_INT);
            $proximo->bindValue('responsable_op', $responsableId, PDO::PARAM_INT);
        }
        $proximo->execute();

        return [
            'abiertas' => (int) $abiertas->fetchColumn(),
            'ultima_actividad' => $actividad->fetchColumn() ?: null,
            'proximo' => $proximo->fetchColumn() ?: null,
        ];
    }

    /** @param array<string, mixed> $filtros @return array{0:string,1:array<string,mixed>} */
    private function filtros(ListCriteria $criteria, array $filtros): array
    {
        $where = ['1 = 1'];
        $params = [];
        if ($filtros['responsable_forzado'] !== null) {
            $where[] = 'o.responsable_comercial_id = :responsable_forzado';
            $params['responsable_forzado'] = $filtros['responsable_forzado'];
        } elseif ($filtros['responsable_comercial_id'] !== null) {
            $where[] = 'o.responsable_comercial_id = :responsable';
            $params['responsable'] = $filtros['responsable_comercial_id'];
        }
        if ($filtros['alcance_usuario'] !== null) {
            $where[] = 'EXISTS (
                SELECT 1 FROM usuario_clientes uc
                WHERE uc.cliente_id = o.cliente_id AND uc.usuario_id = :alcance AND ' . AsignacionVigente::sql('uc') . '
            )';
            $params['alcance'] = $filtros['alcance_usuario'];
        }
        if ($filtros['cliente_id'] !== null) {
            $where[] = 'o.cliente_id = :cliente';
            $params['cliente'] = $filtros['cliente_id'];
        }
        if ($filtros['estado_id'] !== null) {
            $where[] = 'o.estado_id = :estado';
            $params['estado'] = $filtros['estado_id'];
        }
        if ($filtros['origen'] !== null) {
            $where[] = 'o.origen = :origen';
            $params['origen'] = $filtros['origen'];
        }
        if ($filtros['alerta_id'] !== null) {
            $where[] = 'o.alerta_id = :alerta';
            $params['alerta'] = $filtros['alerta_id'];
        }
        if ($filtros['moneda'] !== null) {
            $where[] = 'o.moneda = :moneda';
            $params['moneda'] = $filtros['moneda'];
        }
        if ($filtros['fecha_inicio'] !== null) {
            $where[] = 'o.fecha_deteccion >= :fecha_inicio';
            $params['fecha_inicio'] = $filtros['fecha_inicio'];
        }
        if ($filtros['fecha_fin'] !== null) {
            $where[] = 'o.fecha_deteccion <= :fecha_fin';
            $params['fecha_fin'] = $filtros['fecha_fin'];
        }
        if ($filtros['necesidad_inicio'] !== null) {
            $where[] = 'o.fecha_estimada_necesidad >= :necesidad_inicio';
            $params['necesidad_inicio'] = $filtros['necesidad_inicio'];
        }
        if ($filtros['necesidad_fin'] !== null) {
            $where[] = 'o.fecha_estimada_necesidad <= :necesidad_fin';
            $params['necesidad_fin'] = $filtros['necesidad_fin'];
        }
        if ($criteria->search !== null) {
            $where[] = '(o.titulo LIKE :search_titulo ESCAPE \'\\\\\' OR IFNULL(o.descripcion, \'\') LIKE :search_desc ESCAPE \'\\\\\' OR c.razon_social LIKE :search_razon ESCAPE \'\\\\\' OR IFNULL(c.nombre_comercial, \'\') LIKE :search_comercial ESCAPE \'\\\\\')';
            $like = $criteria->likePattern();
            $params['search_titulo'] = $like;
            $params['search_desc'] = $like;
            $params['search_razon'] = $like;
            $params['search_comercial'] = $like;
        }

        return [implode(' AND ', $where), $params];
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
            $tipo = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $statement->bindValue($key, $value, $tipo);
        }
    }
}
