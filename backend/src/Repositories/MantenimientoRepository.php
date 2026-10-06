<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\AsignacionVigente;
use PDO;

final class MantenimientoRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null */
    public function bloquearNeumatico(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT n.id, n.cliente_id, n.codigo, n.estado_id, n.eliminado, n.vida_actual,
                    e.codigo AS estado_codigo, e.es_final,
                    c.eliminado AS cliente_eliminado
             FROM neumaticos n
             INNER JOIN estados_neumatico e ON e.id = n.estado_id
             INNER JOIN clientes c ON c.id = n.cliente_id
             WHERE n.id = :id
             FOR UPDATE OF n'
        );
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function neumatico(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT n.id, n.cliente_id, n.codigo, n.eliminado, n.vida_actual, e.codigo AS estado_codigo
             FROM neumaticos n
             INNER JOIN estados_neumatico e ON e.id = n.estado_id
             WHERE n.id = :id
             LIMIT 1'
        );
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function bloquear(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT m.id, m.cliente_id, m.neumatico_id, m.tipo_mantenimiento_id, m.estado_id,
                    m.fecha_solicitud, m.fecha_envio, m.fecha_retorno, m.tercero_nombre, m.costo, m.moneda,
                    m.profundidad_antes_mm, m.profundidad_despues_mm, m.inicia_nueva_vida, m.observacion,
                    t.codigo AS tipo_codigo, e.codigo AS estado_codigo, e.es_final
             FROM mantenimientos_neumatico m
             INNER JOIN tipos_mantenimiento t ON t.id = m.tipo_mantenimiento_id
             INNER JOIN estados_mantenimiento e ON e.id = m.estado_id
             WHERE m.id = :id
             FOR UPDATE OF m'
        );
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function mantenimiento(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT m.id, m.cliente_id, m.neumatico_id, e.codigo AS estado_codigo, t.codigo AS tipo_codigo
             FROM mantenimientos_neumatico m
             INNER JOIN tipos_mantenimiento t ON t.id = m.tipo_mantenimiento_id
             INNER JOIN estados_mantenimiento e ON e.id = m.estado_id
             WHERE m.id = :id
             LIMIT 1'
        );
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function bloquearActivos(int $neumaticoId, ?int $exceptoId = null): int
    {
        $sql = 'SELECT m.id
                FROM mantenimientos_neumatico m
                INNER JOIN estados_mantenimiento e ON e.id = m.estado_id
                WHERE m.neumatico_id = :neumatico AND e.es_final = 0';
        if ($exceptoId !== null) {
            $sql .= ' AND m.id <> :excepto';
        }
        $sql .= ' FOR UPDATE OF m';
        $statement = $this->pdo->prepare($sql);
        $statement->bindValue('neumatico', $neumaticoId, PDO::PARAM_INT);
        if ($exceptoId !== null) {
            $statement->bindValue('excepto', $exceptoId, PDO::PARAM_INT);
        }
        $statement->execute();

        return count($statement->fetchAll());
    }

    public function tieneMontajeActivo(int $neumaticoId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT id FROM montajes_neumatico
             WHERE neumatico_id = :id AND montaje_activo_flag = 1
             LIMIT 1
             FOR UPDATE'
        );
        $statement->bindValue('id', $neumaticoId, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetch() !== false;
    }

    /** @return array<string, mixed>|null */
    public function bloquearVidaAbierta(int $neumaticoId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, numero_vida, fecha_inicio
             FROM neumatico_vidas
             WHERE neumatico_id = :id AND fecha_fin IS NULL
             ORDER BY numero_vida DESC
             LIMIT 1
             FOR UPDATE'
        );
        $statement->bindValue('id', $neumaticoId, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function tipo(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, codigo, nombre, activo FROM tipos_mantenimiento WHERE id = :id LIMIT 1'
        );
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function estadoId(string $codigo): int
    {
        $statement = $this->pdo->prepare(
            'SELECT id FROM estados_mantenimiento WHERE codigo = :codigo AND activo = 1 LIMIT 1'
        );
        $statement->bindValue('codigo', $codigo);
        $statement->execute();
        $id = $statement->fetchColumn();
        if ($id === false) {
            throw new \RuntimeException('El estado de mantenimiento ' . $codigo . ' no está disponible.');
        }

        return (int) $id;
    }

    /** @return array<string, mixed>|null */
    public function motivo(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, codigo, nombre, activo FROM motivos_descarte WHERE id = :id LIMIT 1'
        );
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    public function tipos(): array
    {
        return $this->pdo->query(
            'SELECT id, codigo, nombre, descripcion FROM tipos_mantenimiento WHERE activo = 1 ORDER BY nombre ASC'
        )->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function estados(): array
    {
        return $this->pdo->query(
            'SELECT id, codigo, nombre, orden, es_final FROM estados_mantenimiento WHERE activo = 1 ORDER BY orden ASC'
        )->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function motivos(): array
    {
        return $this->pdo->query(
            'SELECT id, codigo, nombre, descripcion FROM motivos_descarte WHERE activo = 1 ORDER BY nombre ASC'
        )->fetchAll();
    }

    /** @param array<string, mixed> $datos */
    public function crear(array $datos): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO mantenimientos_neumatico (
                cliente_id, neumatico_id, tipo_mantenimiento_id, estado_id, fecha_solicitud,
                tercero_nombre, costo, moneda, profundidad_antes_mm, observacion, creado_por, actualizado_por
             ) VALUES (
                :cliente_id, :neumatico_id, :tipo_id, :estado_id, :fecha_solicitud,
                :tercero, :costo, :moneda, :profundidad, :observacion, :creado_por, :actualizado_por
             )'
        );
        $statement->bindValue('cliente_id', $datos['cliente_id'], PDO::PARAM_INT);
        $statement->bindValue('neumatico_id', $datos['neumatico_id'], PDO::PARAM_INT);
        $statement->bindValue('tipo_id', $datos['tipo_mantenimiento_id'], PDO::PARAM_INT);
        $statement->bindValue('estado_id', $datos['estado_id'], PDO::PARAM_INT);
        $statement->bindValue('fecha_solicitud', $datos['fecha_solicitud']);
        $this->nulo($statement, 'tercero', $datos['tercero_nombre'], false);
        $this->nulo($statement, 'costo', $datos['costo'], false);
        $this->nulo($statement, 'moneda', $datos['moneda'], false);
        $this->nulo($statement, 'profundidad', $datos['profundidad_antes_mm'], false);
        $this->nulo($statement, 'observacion', $datos['observacion'], false);
        $statement->bindValue('creado_por', $datos['creado_por'], PDO::PARAM_INT);
        $statement->bindValue('actualizado_por', $datos['creado_por'], PDO::PARAM_INT);
        $statement->execute();

        return (int) $this->pdo->lastInsertId();
    }

    public function historial(int $mantenimientoId, ?int $anteriorId, int $nuevoId, int $usuarioId, ?string $observacion, ?string $fecha = null): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO mantenimiento_estado_historial (
                mantenimiento_id, estado_anterior_id, estado_nuevo_id, fecha_cambio, usuario_id, observacion
             ) VALUES (
                :mantenimiento_id, :anterior, :nuevo, ' . ($fecha === null ? 'NOW()' : ':fecha') . ', :usuario_id, :observacion
             )'
        );
        $statement->bindValue('mantenimiento_id', $mantenimientoId, PDO::PARAM_INT);
        $this->nulo($statement, 'anterior', $anteriorId, true);
        $statement->bindValue('nuevo', $nuevoId, PDO::PARAM_INT);
        if ($fecha !== null) {
            $statement->bindValue('fecha', $fecha);
        }
        $statement->bindValue('usuario_id', $usuarioId, PDO::PARAM_INT);
        $this->nulo($statement, 'observacion', $observacion, false);
        $statement->execute();
    }

    /** @param array<string, mixed> $datos */
    public function marcarEnviado(int $id, array $datos, int $estadoId, int $usuarioId): int
    {
        $statement = $this->pdo->prepare(
            'UPDATE mantenimientos_neumatico
             SET estado_id = :estado, fecha_envio = :fecha, observacion = COALESCE(:observacion, observacion), actualizado_por = :usuario
             WHERE id = :id AND estado_id = :actual'
        );
        $statement->bindValue('estado', $estadoId, PDO::PARAM_INT);
        $statement->bindValue('fecha', $datos['fecha_envio']);
        $this->nulo($statement, 'observacion', $datos['observacion'], false);
        $statement->bindValue('usuario', $usuarioId, PDO::PARAM_INT);
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->bindValue('actual', $datos['estado_actual_id'], PDO::PARAM_INT);
        $statement->execute();

        return $statement->rowCount();
    }

    public function marcarEstado(int $id, int $estadoNuevo, int $estadoActual, int $usuarioId): int
    {
        $statement = $this->pdo->prepare(
            'UPDATE mantenimientos_neumatico
             SET estado_id = :nuevo, actualizado_por = :usuario
             WHERE id = :id AND estado_id = :actual'
        );
        $statement->bindValue('nuevo', $estadoNuevo, PDO::PARAM_INT);
        $statement->bindValue('usuario', $usuarioId, PDO::PARAM_INT);
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->bindValue('actual', $estadoActual, PDO::PARAM_INT);
        $statement->execute();

        return $statement->rowCount();
    }

    /** @param array<string, mixed> $datos */
    public function marcarFinalizado(int $id, array $datos, int $estadoNuevo, int $estadoActual, int $nuevaVida, int $usuarioId): int
    {
        $statement = $this->pdo->prepare(
            'UPDATE mantenimientos_neumatico
             SET estado_id = :nuevo, fecha_retorno = :fecha,
                 costo = COALESCE(:costo, costo), moneda = COALESCE(:moneda, moneda),
                 profundidad_despues_mm = COALESCE(:profundidad, profundidad_despues_mm),
                 inicia_nueva_vida = :nueva_vida,
                 observacion = COALESCE(:observacion, observacion),
                 actualizado_por = :usuario
             WHERE id = :id AND estado_id = :actual'
        );
        $statement->bindValue('nuevo', $estadoNuevo, PDO::PARAM_INT);
        $statement->bindValue('fecha', $datos['fecha_retorno']);
        $this->nulo($statement, 'costo', $datos['costo'], false);
        $this->nulo($statement, 'moneda', $datos['moneda'], false);
        $this->nulo($statement, 'profundidad', $datos['profundidad_despues_mm'], false);
        $statement->bindValue('nueva_vida', $nuevaVida, PDO::PARAM_INT);
        $this->nulo($statement, 'observacion', $datos['observacion'], false);
        $statement->bindValue('usuario', $usuarioId, PDO::PARAM_INT);
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->bindValue('actual', $estadoActual, PDO::PARAM_INT);
        $statement->execute();

        return $statement->rowCount();
    }

    public function cambiarEstadoNeumatico(int $id, int $estadoId, int $vida, int $usuarioId): int
    {
        $statement = $this->pdo->prepare(
            'UPDATE neumaticos
             SET estado_id = :estado, vida_actual = :vida, actualizado_por = :usuario
             WHERE id = :id AND eliminado = 0 AND vida_actual = :vida_actual'
        );
        $statement->bindValue('estado', $estadoId, PDO::PARAM_INT);
        $statement->bindValue('vida', $vida, PDO::PARAM_INT);
        $statement->bindValue('usuario', $usuarioId, PDO::PARAM_INT);
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->bindValue('vida_actual', $vida, PDO::PARAM_INT);
        $statement->execute();

        return $statement->rowCount();
    }

    public function avanzarVida(int $id, int $vidaActual, int $vidaNueva, int $estadoId, int $usuarioId): int
    {
        $statement = $this->pdo->prepare(
            'UPDATE neumaticos
             SET estado_id = :estado, vida_actual = :nueva, actualizado_por = :usuario
             WHERE id = :id AND eliminado = 0 AND vida_actual = :actual'
        );
        $statement->bindValue('estado', $estadoId, PDO::PARAM_INT);
        $statement->bindValue('nueva', $vidaNueva, PDO::PARAM_INT);
        $statement->bindValue('usuario', $usuarioId, PDO::PARAM_INT);
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->bindValue('actual', $vidaActual, PDO::PARAM_INT);
        $statement->execute();

        return $statement->rowCount();
    }

    public function cerrarVida(int $id, string $fecha, ?string $profundidad, string $motivo): int
    {
        $statement = $this->pdo->prepare(
            'UPDATE neumatico_vidas
             SET fecha_fin = :fecha, profundidad_final_mm = :profundidad, motivo_fin = :motivo
             WHERE id = :id AND fecha_fin IS NULL'
        );
        $statement->bindValue('fecha', $fecha);
        $this->nulo($statement, 'profundidad', $profundidad, false);
        $statement->bindValue('motivo', $motivo);
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();

        return $statement->rowCount();
    }

    /** @param array<string, mixed> $datos */
    public function abrirVida(array $datos): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO neumatico_vidas (
                cliente_id, neumatico_id, numero_vida, fecha_inicio, profundidad_inicial_mm, mantenimiento_origen_id
             ) VALUES (
                :cliente_id, :neumatico_id, :numero, :fecha, :profundidad, :mantenimiento_id
             )'
        );
        $statement->bindValue('cliente_id', $datos['cliente_id'], PDO::PARAM_INT);
        $statement->bindValue('neumatico_id', $datos['neumatico_id'], PDO::PARAM_INT);
        $statement->bindValue('numero', $datos['numero_vida'], PDO::PARAM_INT);
        $statement->bindValue('fecha', $datos['fecha_inicio']);
        $this->nulo($statement, 'profundidad', $datos['profundidad_inicial_mm'], false);
        $statement->bindValue('mantenimiento_id', $datos['mantenimiento_origen_id'], PDO::PARAM_INT);
        $statement->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string, mixed> $datos */
    public function crearDescarte(array $datos): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO descartes_neumatico (
                cliente_id, neumatico_id, fecha_descarte, motivo_descarte_id, vida_final,
                km_totales, profundidad_final_mm, observacion, usuario_id
             ) VALUES (
                :cliente_id, :neumatico_id, :fecha, :motivo, :vida,
                NULL, :profundidad, :observacion, :usuario
             )'
        );
        $statement->bindValue('cliente_id', $datos['cliente_id'], PDO::PARAM_INT);
        $statement->bindValue('neumatico_id', $datos['neumatico_id'], PDO::PARAM_INT);
        $statement->bindValue('fecha', $datos['fecha_descarte']);
        $statement->bindValue('motivo', $datos['motivo_descarte_id'], PDO::PARAM_INT);
        $statement->bindValue('vida', $datos['vida_final'], PDO::PARAM_INT);
        $this->nulo($statement, 'profundidad', $datos['profundidad_final_mm'], false);
        $this->nulo($statement, 'observacion', $datos['observacion'], false);
        $statement->bindValue('usuario', $datos['usuario_id'], PDO::PARAM_INT);
        $statement->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function descarte(int $neumaticoId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT d.id, d.fecha_descarte, d.vida_final, d.km_totales, d.profundidad_final_mm, d.observacion,
                    mo.id AS motivo_id, mo.codigo AS motivo_codigo, mo.nombre AS motivo_nombre
             FROM descartes_neumatico d
             INNER JOIN motivos_descarte mo ON mo.id = d.motivo_descarte_id
             WHERE d.neumatico_id = :id
             LIMIT 1'
        );
        $statement->bindValue('id', $neumaticoId, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    public function historialDe(int $mantenimientoId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT h.id, h.fecha_cambio, h.observacion,
                    a.codigo AS anterior, n.codigo AS nuevo,
                    u.nombres, u.apellidos
             FROM mantenimiento_estado_historial h
             LEFT JOIN estados_mantenimiento a ON a.id = h.estado_anterior_id
             INNER JOIN estados_mantenimiento n ON n.id = h.estado_nuevo_id
             INNER JOIN usuarios u ON u.id = h.usuario_id
             WHERE h.mantenimiento_id = :id
             ORDER BY h.id ASC'
        );
        $statement->bindValue('id', $mantenimientoId, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /** @param array<string, mixed> $filtros @return array{rows:list<array<string, mixed>>, total:int} */
    public function listar(array $filtros): array
    {
        $where = ['1 = 1'];
        $params = [];
        if ($filtros['usuario_scope'] !== null) {
            $where[] = 'EXISTS (SELECT 1 FROM usuario_clientes uc WHERE uc.cliente_id = m.cliente_id AND uc.usuario_id = :scope AND ' . AsignacionVigente::sql('uc') . ')';
            $params['scope'] = $filtros['usuario_scope'];
        }
        if ($filtros['cliente_id'] !== null) {
            $where[] = 'm.cliente_id = :cliente';
            $params['cliente'] = $filtros['cliente_id'];
        }
        if ($filtros['neumatico_id'] !== null) {
            $where[] = 'm.neumatico_id = :neumatico';
            $params['neumatico'] = $filtros['neumatico_id'];
        }
        if ($filtros['tipo'] !== null) {
            $where[] = 't.codigo = :tipo';
            $params['tipo'] = $filtros['tipo'];
        }
        if ($filtros['estado'] !== null) {
            $where[] = 'e.codigo = :estado';
            $params['estado'] = $filtros['estado'];
        }
        if ($filtros['fecha_inicio'] !== null) {
            $where[] = 'm.fecha_solicitud >= :desde';
            $params['desde'] = $filtros['fecha_inicio'];
        }
        if ($filtros['fecha_fin'] !== null) {
            $where[] = 'm.fecha_solicitud <= :hasta';
            $params['hasta'] = $filtros['fecha_fin'];
        }
        if ($filtros['search'] !== null) {
            $where[] = '(n.codigo LIKE :busca_codigo ESCAPE \'\\\\\' OR m.tercero_nombre LIKE :busca_tercero ESCAPE \'\\\\\')';
            $params['busca_codigo'] = $filtros['search'];
            $params['busca_tercero'] = $filtros['search'];
        }
        $sqlWhere = implode(' AND ', $where);
        $count = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM mantenimientos_neumatico m
             INNER JOIN neumaticos n ON n.id = m.neumatico_id
             INNER JOIN tipos_mantenimiento t ON t.id = m.tipo_mantenimiento_id
             INNER JOIN estados_mantenimiento e ON e.id = m.estado_id
             WHERE ' . $sqlWhere
        );
        $this->bind($count, $params);
        $count->execute();
        $total = (int) $count->fetchColumn();
        $statement = $this->pdo->prepare(
            'SELECT m.id, m.fecha_solicitud, m.fecha_envio, m.fecha_retorno, m.tercero_nombre, m.costo, m.moneda,
                    m.inicia_nueva_vida, n.id AS neumatico_id, n.codigo AS neumatico_codigo,
                    c.id AS cliente_id, c.razon_social, c.nombre_comercial,
                    t.codigo AS tipo_codigo, t.nombre AS tipo_nombre,
                    e.codigo AS estado_codigo, e.nombre AS estado_nombre, e.es_final
             FROM mantenimientos_neumatico m
             INNER JOIN neumaticos n ON n.id = m.neumatico_id
             INNER JOIN clientes c ON c.id = m.cliente_id
             INNER JOIN tipos_mantenimiento t ON t.id = m.tipo_mantenimiento_id
             INNER JOIN estados_mantenimiento e ON e.id = m.estado_id
             WHERE ' . $sqlWhere . '
             ORDER BY ' . $filtros['sort'] . ' ' . $filtros['direction'] . ', m.id DESC
             LIMIT ' . (int) $filtros['limit'] . ' OFFSET ' . (int) $filtros['offset']
        );
        $this->bind($statement, $params);
        $statement->execute();

        return ['rows' => $statement->fetchAll(), 'total' => $total];
    }

    /** @return array<string, mixed>|null */
    public function ficha(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT m.id, m.cliente_id, m.neumatico_id, m.fecha_solicitud, m.fecha_envio, m.fecha_retorno,
                    m.tercero_nombre, m.costo, m.moneda, m.profundidad_antes_mm, m.profundidad_despues_mm,
                    m.inicia_nueva_vida, m.observacion, m.creado_en,
                    n.codigo AS neumatico_codigo, n.vida_actual, en.codigo AS neumatico_estado,
                    c.razon_social, c.nombre_comercial,
                    t.id AS tipo_id, t.codigo AS tipo_codigo, t.nombre AS tipo_nombre,
                    e.id AS estado_id, e.codigo AS estado_codigo, e.nombre AS estado_nombre, e.es_final
             FROM mantenimientos_neumatico m
             INNER JOIN neumaticos n ON n.id = m.neumatico_id
             INNER JOIN estados_neumatico en ON en.id = n.estado_id
             INNER JOIN clientes c ON c.id = m.cliente_id
             INNER JOIN tipos_mantenimiento t ON t.id = m.tipo_mantenimiento_id
             INNER JOIN estados_mantenimiento e ON e.id = m.estado_id
             WHERE m.id = :id
             LIMIT 1'
        );
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @param array<string, mixed> $params */
    private function bind(\PDOStatement $statement, array $params): void
    {
        foreach ($params as $name => $value) {
            $statement->bindValue((string) $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
    }

    private function nulo(\PDOStatement $statement, string $name, mixed $value, bool $entero): void
    {
        if ($value === null) {
            $statement->bindValue($name, null, PDO::PARAM_NULL);
            return;
        }
        $statement->bindValue($name, $entero ? (int) $value : (string) $value, $entero ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
}
