<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\AsignacionVigente;
use App\Support\ListCriteria;
use PDO;

final class OperacionNeumaticoRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null */
    public function bloquearUnidad(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, cliente_id, configuracion_id, estado, eliminado, kilometraje_actual, horometro_actual
             FROM unidades WHERE id = :id FOR UPDATE'
        );
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function bloquearNeumatico(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT n.id, n.cliente_id, n.codigo, n.estado_id, n.eliminado, e.codigo AS estado_codigo
             FROM neumaticos n
             INNER JOIN estados_neumatico e ON e.id = n.estado_id
             WHERE n.id = :id
             FOR UPDATE OF n'
        );
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function montajeActivoNeumatico(int $neumaticoId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, unidad_id, posicion_id FROM montajes_neumatico
             WHERE neumatico_id = :id AND montaje_activo_flag = 1 LIMIT 1'
        );
        $statement->bindValue('id', $neumaticoId, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function bloquearMontajeActivoNeumatico(int $neumaticoId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, cliente_id, neumatico_id, unidad_id, configuracion_id, posicion_id,
                    fecha_montaje, km_montaje, horometro_montaje, anulado, fecha_desmontaje
             FROM montajes_neumatico
             WHERE neumatico_id = :id AND montaje_activo_flag = 1
             FOR UPDATE'
        );
        $statement->bindValue('id', $neumaticoId, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function bloquearMontaje(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, cliente_id, neumatico_id, unidad_id, configuracion_id, posicion_id,
                    fecha_montaje, km_montaje, horometro_montaje, anulado, fecha_desmontaje
             FROM montajes_neumatico WHERE id = :id FOR UPDATE'
        );
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function bloquearOcupacion(int $unidadId, int $posicionId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, neumatico_id, unidad_id, posicion_id
             FROM montajes_neumatico
             WHERE unidad_id = :unidad AND posicion_id = :posicion AND montaje_activo_flag = 1
             FOR UPDATE'
        );
        $statement->bindValue('unidad', $unidadId, PDO::PARAM_INT);
        $statement->bindValue('posicion', $posicionId, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function montaje(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, cliente_id, neumatico_id, unidad_id, posicion_id, anulado, fecha_desmontaje
             FROM montajes_neumatico WHERE id = :id LIMIT 1'
        );
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function unidad(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, cliente_id, configuracion_id, estado, eliminado FROM unidades WHERE id = :id LIMIT 1'
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
            'SELECT id, cliente_id, eliminado FROM neumaticos WHERE id = :id LIMIT 1'
        );
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function cliente(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, estado, eliminado FROM clientes WHERE id = :id LIMIT 1'
        );
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function posicion(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, configuracion_id, codigo, activo FROM configuracion_posiciones WHERE id = :id LIMIT 1'
        );
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function estadoId(string $codigo): int
    {
        $statement = $this->pdo->prepare(
            'SELECT id FROM estados_neumatico WHERE codigo = :codigo AND activo = 1 LIMIT 1'
        );
        $statement->bindValue('codigo', $codigo);
        $statement->execute();
        $id = $statement->fetchColumn();
        if ($id === false) {
            throw new \RuntimeException('El estado ' . $codigo . ' no está disponible.');
        }

        return (int) $id;
    }

    public function tipoMovimientoExiste(int $id): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM tipos_movimiento WHERE id = :id LIMIT 1');
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchColumn() !== false;
    }

    public function tipoMovimientoId(string $codigo): int
    {
        $statement = $this->pdo->prepare(
            'SELECT id FROM tipos_movimiento WHERE codigo = :codigo AND activo = 1 LIMIT 1'
        );
        $statement->bindValue('codigo', $codigo);
        $statement->execute();
        $id = $statement->fetchColumn();
        if ($id === false) {
            throw new \RuntimeException('El tipo de movimiento ' . $codigo . ' no está disponible.');
        }

        return (int) $id;
    }

    /** @param array<string, mixed> $data */
    public function crearMontaje(array $data): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO montajes_neumatico (
                cliente_id, neumatico_id, unidad_id, configuracion_id, posicion_id,
                fecha_montaje, km_montaje, horometro_montaje, usuario_montaje_id, anulado
             ) VALUES (
                :cliente_id, :neumatico_id, :unidad_id, :configuracion_id, :posicion_id,
                :fecha_montaje, :km_montaje, :horometro_montaje, :usuario_montaje_id, 0
             )'
        );
        $statement->bindValue('cliente_id', $data['cliente_id'], PDO::PARAM_INT);
        $statement->bindValue('neumatico_id', $data['neumatico_id'], PDO::PARAM_INT);
        $statement->bindValue('unidad_id', $data['unidad_id'], PDO::PARAM_INT);
        $statement->bindValue('configuracion_id', $data['configuracion_id'], PDO::PARAM_INT);
        $statement->bindValue('posicion_id', $data['posicion_id'], PDO::PARAM_INT);
        $statement->bindValue('fecha_montaje', $data['fecha_montaje']);
        $this->nulo($statement, 'km_montaje', $data['km_montaje'], true);
        $this->nulo($statement, 'horometro_montaje', $data['horometro_montaje'], false);
        $statement->bindValue('usuario_montaje_id', $data['usuario_montaje_id'], PDO::PARAM_INT);
        $statement->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string, mixed> $data */
    public function cerrarMontaje(int $id, array $data): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE montajes_neumatico
             SET fecha_desmontaje = :fecha, km_desmontaje = :km, horometro_desmontaje = :horometro,
                 motivo_desmontaje = :motivo, usuario_desmontaje_id = :usuario
             WHERE id = :id AND fecha_desmontaje IS NULL AND anulado = 0'
        );
        $statement->bindValue('fecha', $data['fecha']);
        $this->nulo($statement, 'km', $data['km'], true);
        $this->nulo($statement, 'horometro', $data['horometro'], false);
        $statement->bindValue('motivo', $data['motivo']);
        $statement->bindValue('usuario', $data['usuario_id'], PDO::PARAM_INT);
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
    }

    /** @param array<string, mixed> $data */
    public function crearMovimiento(array $data): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO movimientos_neumatico (
                cliente_id, neumatico_id, tipo_movimiento_id, fecha,
                unidad_origen_id, posicion_origen_id, unidad_destino_id, posicion_destino_id,
                km_unidad, horometro_unidad, montaje_id, grupo_operacion, observacion, usuario_id, anulado
             ) VALUES (
                :cliente_id, :neumatico_id, :tipo_movimiento_id, :fecha,
                :unidad_origen_id, :posicion_origen_id, :unidad_destino_id, :posicion_destino_id,
                :km_unidad, :horometro_unidad, :montaje_id, :grupo_operacion, :observacion, :usuario_id, 0
             )'
        );
        $statement->bindValue('cliente_id', $data['cliente_id'], PDO::PARAM_INT);
        $statement->bindValue('neumatico_id', $data['neumatico_id'], PDO::PARAM_INT);
        $statement->bindValue('tipo_movimiento_id', $data['tipo_movimiento_id'], PDO::PARAM_INT);
        $statement->bindValue('fecha', $data['fecha']);
        $this->nulo($statement, 'unidad_origen_id', $data['unidad_origen_id'], true);
        $this->nulo($statement, 'posicion_origen_id', $data['posicion_origen_id'], true);
        $this->nulo($statement, 'unidad_destino_id', $data['unidad_destino_id'], true);
        $this->nulo($statement, 'posicion_destino_id', $data['posicion_destino_id'], true);
        $this->nulo($statement, 'km_unidad', $data['km_unidad'], true);
        $this->nulo($statement, 'horometro_unidad', $data['horometro_unidad'], false);
        $statement->bindValue('montaje_id', $data['montaje_id'], PDO::PARAM_INT);
        $statement->bindValue('grupo_operacion', $data['grupo_operacion']);
        $this->nulo($statement, 'observacion', $data['observacion'], false);
        $statement->bindValue('usuario_id', $data['usuario_id'], PDO::PARAM_INT);
        $statement->execute();

        return (int) $this->pdo->lastInsertId();
    }

    public function cambiarEstado(int $neumaticoId, int $estadoId, int $actor): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE neumaticos SET estado_id = :estado, actualizado_por = :actor WHERE id = :id AND eliminado = 0'
        );
        $statement->bindValue('estado', $estadoId, PDO::PARAM_INT);
        $statement->bindValue('actor', $actor, PDO::PARAM_INT);
        $statement->bindValue('id', $neumaticoId, PDO::PARAM_INT);
        $statement->execute();
    }

    public function historialEstado(
        int $clienteId,
        int $neumaticoId,
        ?int $anteriorId,
        int $nuevoId,
        int $movimientoId,
        int $usuarioId,
        string $fecha,
        string $motivo,
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO neumatico_estado_historial (
                cliente_id, neumatico_id, estado_anterior_id, estado_nuevo_id,
                fecha_cambio, movimiento_id, usuario_id, motivo
             ) VALUES (
                :cliente_id, :neumatico_id, :anterior, :nuevo,
                :fecha, :movimiento_id, :usuario_id, :motivo
             )'
        );
        $statement->bindValue('cliente_id', $clienteId, PDO::PARAM_INT);
        $statement->bindValue('neumatico_id', $neumaticoId, PDO::PARAM_INT);
        $this->nulo($statement, 'anterior', $anteriorId, true);
        $statement->bindValue('nuevo', $nuevoId, PDO::PARAM_INT);
        $statement->bindValue('fecha', $fecha);
        $statement->bindValue('movimiento_id', $movimientoId, PDO::PARAM_INT);
        $statement->bindValue('usuario_id', $usuarioId, PDO::PARAM_INT);
        $statement->bindValue('motivo', $motivo);
        $statement->execute();
    }

    public function actualizarLectura(int $unidadId, ?int $km, ?string $horometro): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE unidades
             SET kilometraje_actual = :km, horometro_actual = :horometro
             WHERE id = :id'
        );
        $this->nulo($statement, 'km', $km, true);
        $this->nulo($statement, 'horometro', $horometro, false);
        $statement->bindValue('id', $unidadId, PDO::PARAM_INT);
        $statement->execute();
    }

    /** @return list<array<string, mixed>> */
    public function montajesActivos(int $unidadId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT m.id AS montaje_id, m.fecha_montaje, m.km_montaje, m.horometro_montaje,
                    p.id AS posicion_id, p.codigo AS posicion_codigo, p.lado, p.ubicacion,
                    e.id AS eje_id, e.numero_eje, e.nombre AS eje_nombre,
                    n.id AS neumatico_id, n.codigo AS neumatico_codigo,
                    n.profundidad_inicial_mm, me.descripcion AS medida
             FROM montajes_neumatico m
             INNER JOIN configuracion_posiciones p ON p.id = m.posicion_id
             INNER JOIN configuracion_ejes e ON e.id = p.eje_id
             INNER JOIN neumaticos n ON n.id = m.neumatico_id
             INNER JOIN medidas_neumatico me ON me.id = n.medida_id
             WHERE m.unidad_id = :unidad AND m.montaje_activo_flag = 1
             ORDER BY e.orden ASC, p.orden ASC, m.id ASC'
        );
        $statement->bindValue('unidad', $unidadId, PDO::PARAM_INT);
        $statement->execute();
        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            $rows[] = [
                'montaje_id' => (int) $row['montaje_id'],
                'fecha_montaje' => $row['fecha_montaje'],
                'km_montaje' => $row['km_montaje'] === null ? null : (int) $row['km_montaje'],
                'horometro_montaje' => $row['horometro_montaje'],
                'posicion' => [
                    'id' => (int) $row['posicion_id'],
                    'codigo' => $row['posicion_codigo'],
                    'lado' => $row['lado'],
                    'ubicacion' => $row['ubicacion'],
                    'eje' => [
                        'id' => (int) $row['eje_id'],
                        'numero_eje' => (int) $row['numero_eje'],
                        'nombre' => $row['eje_nombre'],
                    ],
                ],
                'neumatico' => [
                    'id' => (int) $row['neumatico_id'],
                    'codigo' => $row['neumatico_codigo'],
                    'medida' => $row['medida'],
                    'profundidad_inicial_mm' => $row['profundidad_inicial_mm'],
                ],
            ];
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    public function montajesNeumatico(int $neumaticoId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT m.id, m.fecha_montaje, m.fecha_desmontaje, m.km_montaje, m.km_desmontaje,
                    m.horometro_montaje, m.horometro_desmontaje, m.motivo_desmontaje, m.anulado,
                    m.montaje_activo_flag, u.id AS unidad_id, u.codigo AS unidad_codigo,
                    p.id AS posicion_id, p.codigo AS posicion_codigo
             FROM montajes_neumatico m
             INNER JOIN unidades u ON u.id = m.unidad_id
             INNER JOIN configuracion_posiciones p ON p.id = m.posicion_id
             WHERE m.neumatico_id = :id
             ORDER BY m.fecha_montaje DESC, m.id DESC'
        );
        $statement->bindValue('id', $neumaticoId, PDO::PARAM_INT);
        $statement->execute();
        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            $rows[] = [
                'id' => (int) $row['id'],
                'fecha_montaje' => $row['fecha_montaje'],
                'fecha_desmontaje' => $row['fecha_desmontaje'],
                'km_montaje' => $row['km_montaje'] === null ? null : (int) $row['km_montaje'],
                'km_desmontaje' => $row['km_desmontaje'] === null ? null : (int) $row['km_desmontaje'],
                'horometro_montaje' => $row['horometro_montaje'],
                'horometro_desmontaje' => $row['horometro_desmontaje'],
                'motivo_desmontaje' => $row['motivo_desmontaje'],
                'activo' => $row['montaje_activo_flag'] !== null,
                'anulado' => (int) $row['anulado'] === 1,
                'unidad' => ['id' => (int) $row['unidad_id'], 'codigo' => $row['unidad_codigo']],
                'posicion' => ['id' => (int) $row['posicion_id'], 'codigo' => $row['posicion_codigo']],
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $filtros
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function movimientos(ListCriteria $criteria, array $filtros): array
    {
        [$where, $params] = $this->filtrosMovimiento($filtros);
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM movimientos_neumatico m WHERE ' . $where);
        $this->bind($count, $params);
        $count->execute();
        $total = (int) $count->fetchColumn();
        $statement = $this->pdo->prepare(
            'SELECT m.id, m.fecha, m.observacion, m.grupo_operacion, m.km_unidad, m.horometro_unidad,
                    t.id AS tipo_id, t.codigo AS tipo_codigo, t.nombre AS tipo_nombre,
                    n.id AS neumatico_id, n.codigo AS neumatico_codigo,
                    uo.id AS origen_unidad_id, uo.codigo AS origen_unidad_codigo,
                    ud.id AS destino_unidad_id, ud.codigo AS destino_unidad_codigo,
                    po.id AS origen_posicion_id, po.codigo AS origen_posicion_codigo,
                    pd.id AS destino_posicion_id, pd.codigo AS destino_posicion_codigo,
                    us.nombres, us.apellidos
             FROM movimientos_neumatico m
             INNER JOIN tipos_movimiento t ON t.id = m.tipo_movimiento_id
             INNER JOIN neumaticos n ON n.id = m.neumatico_id
             INNER JOIN usuarios us ON us.id = m.usuario_id
             LEFT JOIN unidades uo ON uo.id = m.unidad_origen_id
             LEFT JOIN unidades ud ON ud.id = m.unidad_destino_id
             LEFT JOIN configuracion_posiciones po ON po.id = m.posicion_origen_id
             LEFT JOIN configuracion_posiciones pd ON pd.id = m.posicion_destino_id
             WHERE ' . $where . '
             ORDER BY ' . $criteria->sortExpression . ' ' . $criteria->direction . ', m.id DESC
             LIMIT ' . $criteria->limit . ' OFFSET ' . $criteria->offset
        );
        $this->bind($statement, $params);
        $statement->execute();
        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            $rows[] = $this->presentarMovimiento($row);
        }

        return ['rows' => $rows, 'total' => $total];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function presentarMovimiento(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'fecha' => $row['fecha'],
            'observacion' => $row['observacion'],
            'grupo_operacion' => $row['grupo_operacion'],
            'km_unidad' => $row['km_unidad'] === null ? null : (int) $row['km_unidad'],
            'horometro_unidad' => $row['horometro_unidad'],
            'tipo' => [
                'id' => (int) $row['tipo_id'],
                'codigo' => $row['tipo_codigo'],
                'nombre' => $row['tipo_nombre'],
            ],
            'neumatico' => [
                'id' => (int) $row['neumatico_id'],
                'codigo' => $row['neumatico_codigo'],
            ],
            'unidad_origen' => $row['origen_unidad_id'] === null ? null : [
                'id' => (int) $row['origen_unidad_id'],
                'codigo' => $row['origen_unidad_codigo'],
            ],
            'posicion_origen' => $row['origen_posicion_id'] === null ? null : [
                'id' => (int) $row['origen_posicion_id'],
                'codigo' => $row['origen_posicion_codigo'],
            ],
            'unidad_destino' => $row['destino_unidad_id'] === null ? null : [
                'id' => (int) $row['destino_unidad_id'],
                'codigo' => $row['destino_unidad_codigo'],
            ],
            'posicion_destino' => $row['destino_posicion_id'] === null ? null : [
                'id' => (int) $row['destino_posicion_id'],
                'codigo' => $row['destino_posicion_codigo'],
            ],
            'usuario' => trim($row['nombres'] . ' ' . $row['apellidos']),
        ];
    }

    /**
     * @param array<string, mixed> $filtros
     * @return array{0:string,1:list<mixed>}
     */
    private function filtrosMovimiento(array $filtros): array
    {
        $where = ['1 = 1'];
        $params = [];
        if ($filtros['cliente'] !== null) {
            $where[] = 'm.cliente_id = ?';
            $params[] = $filtros['cliente'];
        }
        if ($filtros['neumatico'] !== null) {
            $where[] = 'm.neumatico_id = ?';
            $params[] = $filtros['neumatico'];
        }
        if ($filtros['unidad'] !== null) {
            $where[] = '(m.unidad_origen_id = ? OR m.unidad_destino_id = ?)';
            $params[] = $filtros['unidad'];
            $params[] = $filtros['unidad'];
        }
        if ($filtros['tipo'] !== null) {
            $where[] = 'm.tipo_movimiento_id = ?';
            $params[] = $filtros['tipo'];
        }
        if ($filtros['desde'] !== null) {
            $where[] = 'm.fecha >= ?';
            $params[] = $filtros['desde'];
        }
        if ($filtros['hasta'] !== null) {
            $where[] = 'm.fecha <= ?';
            $params[] = $filtros['hasta'];
        }
        if ($filtros['grupo'] !== null) {
            $where[] = 'm.grupo_operacion = ?';
            $params[] = $filtros['grupo'];
        }
        if ($filtros['usuario'] !== null) {
            $where[] = 'EXISTS (
                SELECT 1 FROM usuario_clientes uc
                WHERE uc.usuario_id = ? AND uc.cliente_id = m.cliente_id AND ' . AsignacionVigente::sql('uc') . '
            )';
            $params[] = $filtros['usuario'];
        }

        return [implode(' AND ', $where), $params];
    }

    /** @param list<mixed> $params */
    private function bind(\PDOStatement $statement, array $params): void
    {
        foreach ($params as $index => $value) {
            $statement->bindValue($index + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
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
