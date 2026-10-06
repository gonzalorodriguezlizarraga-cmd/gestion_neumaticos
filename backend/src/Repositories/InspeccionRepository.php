<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\AsignacionVigente;
use PDO;

final class InspeccionRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null */
    /** @return array<string, mixed>|null */
    public function neumatico(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, cliente_id, codigo, eliminado
             FROM neumaticos
             WHERE id = :id
             LIMIT 1'
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
            'SELECT u.id, u.cliente_id, u.codigo, u.estado, u.eliminado, u.configuracion_id,
                    u.kilometraje_actual, u.horometro_actual,
                    c.estado AS cliente_estado, c.eliminado AS cliente_eliminado,
                    c.razon_social, c.nombre_comercial
             FROM unidades u
             INNER JOIN clientes c ON c.id = u.cliente_id
             WHERE u.id = :id
             LIMIT 1'
        );
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function bloquearUnidad(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT u.id, u.cliente_id, u.codigo, u.estado, u.eliminado, u.configuracion_id,
                    u.kilometraje_actual, u.horometro_actual,
                    c.estado AS cliente_estado, c.eliminado AS cliente_eliminado,
                    c.razon_social, c.nombre_comercial
             FROM unidades u
             INNER JOIN clientes c ON c.id = u.cliente_id
             WHERE u.id = :id
             FOR UPDATE OF u'
        );
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function usuario(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, nombres, apellidos, estado, eliminado
             FROM usuarios
             WHERE id = :id
             LIMIT 1'
        );
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return list<string> */
    public function rolesActivos(int $usuarioId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT r.codigo
             FROM usuario_roles ur
             INNER JOIN roles r ON r.id = ur.rol_id AND r.activo = 1
             WHERE ur.usuario_id = :usuario'
        );
        $statement->bindValue('usuario', $usuarioId, PDO::PARAM_INT);
        $statement->execute();

        return array_map(static fn (array $row): string => (string) $row['codigo'], $statement->fetchAll());
    }

    /** @param array<string, mixed> $datos */
    public function crear(array $datos): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO inspecciones (
                cliente_id, unidad_id, fecha_inspeccion, tecnico_id, kilometraje, horometro,
                estado, observacion_general, creado_por
             ) VALUES (
                :cliente_id, :unidad_id, :fecha_inspeccion, :tecnico_id, :kilometraje, :horometro,
                \'BORRADOR\', :observacion_general, :creado_por
             )'
        );
        $statement->bindValue('cliente_id', $datos['cliente_id'], PDO::PARAM_INT);
        $statement->bindValue('unidad_id', $datos['unidad_id'], PDO::PARAM_INT);
        $statement->bindValue('fecha_inspeccion', $datos['fecha_inspeccion']);
        $statement->bindValue('tecnico_id', $datos['tecnico_id'], PDO::PARAM_INT);
        $statement->bindValue('kilometraje', $datos['kilometraje'], $datos['kilometraje'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->bindValue('horometro', $datos['horometro'], $datos['horometro'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue('observacion_general', $datos['observacion_general'], $datos['observacion_general'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue('creado_por', $datos['creado_por'], PDO::PARAM_INT);
        $statement->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function bloquear(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT i.id, i.cliente_id, i.unidad_id, i.fecha_inspeccion, i.tecnico_id,
                    i.kilometraje, i.horometro, i.estado, i.observacion_general, i.finalizada_en,
                    u.codigo AS unidad_codigo, u.estado AS unidad_estado, u.configuracion_id, u.eliminado AS unidad_eliminado
             FROM inspecciones i
             INNER JOIN unidades u ON u.id = i.unidad_id
             WHERE i.id = :id
             FOR UPDATE OF i'
        );
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function inspeccion(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT i.id, i.cliente_id, i.unidad_id, i.tecnico_id, i.estado
             FROM inspecciones i
             WHERE i.id = :id
             LIMIT 1'
        );
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @param array<string, mixed> $datos */
    public function actualizarCabecera(int $id, array $datos, int $usuarioId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE inspecciones
             SET fecha_inspeccion = :fecha_inspeccion,
                 tecnico_id = :tecnico_id,
                 kilometraje = :kilometraje,
                 horometro = :horometro,
                 observacion_general = :observacion_general,
                 actualizado_por = :actualizado_por
             WHERE id = :id
               AND estado = \'BORRADOR\''
        );
        $statement->bindValue('fecha_inspeccion', $datos['fecha_inspeccion']);
        $statement->bindValue('tecnico_id', $datos['tecnico_id'], PDO::PARAM_INT);
        $statement->bindValue('kilometraje', $datos['kilometraje'], $datos['kilometraje'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->bindValue('horometro', $datos['horometro'], $datos['horometro'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue('observacion_general', $datos['observacion_general'], $datos['observacion_general'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue('actualizado_por', $usuarioId, PDO::PARAM_INT);
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
    }

    public function finalizar(int $id, int $usuarioId): int
    {
        $statement = $this->pdo->prepare(
            'UPDATE inspecciones
             SET estado = \'FINALIZADA\',
                 finalizada_en = NOW(),
                 actualizado_por = :actualizado_por
             WHERE id = :id
               AND estado = \'BORRADOR\''
        );
        $statement->bindValue('actualizado_por', $usuarioId, PDO::PARAM_INT);
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();

        return $statement->rowCount();
    }

    public function actualizarLectura(int $unidadId, ?int $km, ?string $horometro): void
    {
        if ($km !== null) {
            $statement = $this->pdo->prepare(
                'UPDATE unidades
                 SET kilometraje_actual = :km_nuevo
                 WHERE id = :unidad_km
                   AND (kilometraje_actual IS NULL OR kilometraje_actual <= :km_comparado)'
            );
            $statement->bindValue('km_nuevo', $km, PDO::PARAM_INT);
            $statement->bindValue('unidad_km', $unidadId, PDO::PARAM_INT);
            $statement->bindValue('km_comparado', $km, PDO::PARAM_INT);
            $statement->execute();
        }
        if ($horometro !== null) {
            $statement = $this->pdo->prepare(
                'UPDATE unidades
                 SET horometro_actual = :horometro_nuevo
                 WHERE id = :unidad_h
                   AND (horometro_actual IS NULL OR horometro_actual <= :horometro_comparado)'
            );
            $statement->bindValue('horometro_nuevo', $horometro);
            $statement->bindValue('unidad_h', $unidadId, PDO::PARAM_INT);
            $statement->bindValue('horometro_comparado', $horometro);
            $statement->execute();
        }
    }

    /** @return array<string, mixed>|null */
    public function posicion(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT p.id, p.configuracion_id, p.codigo, p.lado, p.ubicacion, p.activo,
                    e.numero_eje, e.nombre AS eje_nombre
             FROM configuracion_posiciones p
             INNER JOIN configuracion_ejes e ON e.id = p.eje_id
             WHERE p.id = :id
             LIMIT 1'
        );
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function montajeActivo(int $unidadId, int $posicionId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT m.id, m.neumatico_id, m.cliente_id, n.codigo, n.profundidad_minima_mm,
                    med.descripcion AS medida
             FROM montajes_neumatico m
             INNER JOIN neumaticos n ON n.id = m.neumatico_id
             INNER JOIN medidas_neumatico med ON med.id = n.medida_id
             WHERE m.unidad_id = :unidad_id
               AND m.posicion_id = :posicion_id
               AND m.montaje_activo_flag = 1
             LIMIT 1
             FOR UPDATE OF m'
        );
        $statement->bindValue('unidad_id', $unidadId, PDO::PARAM_INT);
        $statement->bindValue('posicion_id', $posicionId, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @param array<string, mixed> $datos */
    public function crearDetalle(array $datos): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO inspeccion_detalles (
                inspeccion_id, cliente_id, posicion_id, neumatico_id, montaje_id,
                posicion_codigo_snapshot, eje_numero_snapshot, eje_nombre_snapshot,
                lado_snapshot, ubicacion_snapshot,
                profundidad_interior_mm, profundidad_centro_mm, profundidad_exterior_mm,
                presion_psi, condicion, observacion
             ) VALUES (
                :inspeccion_id, :cliente_id, :posicion_id, :neumatico_id, :montaje_id,
                :posicion_codigo_snapshot, :eje_numero_snapshot, :eje_nombre_snapshot,
                :lado_snapshot, :ubicacion_snapshot,
                :profundidad_interior_mm, :profundidad_centro_mm, :profundidad_exterior_mm,
                :presion_psi, :condicion, :observacion
             )'
        );
        foreach ([
            'inspeccion_id', 'cliente_id', 'posicion_id', 'neumatico_id', 'montaje_id', 'eje_numero_snapshot',
        ] as $entero) {
            $statement->bindValue($entero, $datos[$entero], $datos[$entero] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        }
        foreach ([
            'posicion_codigo_snapshot', 'eje_nombre_snapshot', 'lado_snapshot', 'ubicacion_snapshot', 'condicion',
        ] as $texto) {
            $statement->bindValue($texto, $datos[$texto]);
        }
        foreach (['profundidad_interior_mm', 'profundidad_centro_mm', 'profundidad_exterior_mm', 'presion_psi', 'observacion'] as $opcional) {
            $statement->bindValue($opcional, $datos[$opcional], $datos[$opcional] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        }
        $statement->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function detalleFilaPorId(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, inspeccion_id, cliente_id, neumatico_id, montaje_id
             FROM inspeccion_detalles
             WHERE id = :id
             LIMIT 1'
        );
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function detallesInconsistentes(int $inspeccionId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM inspeccion_detalles d
             LEFT JOIN neumaticos n
               ON n.id = d.neumatico_id AND n.cliente_id = d.cliente_id AND n.eliminado = 0
             LEFT JOIN montajes_neumatico m
               ON m.id = d.montaje_id AND m.cliente_id = d.cliente_id AND m.neumatico_id = d.neumatico_id
             WHERE d.inspeccion_id = :id
               AND (n.id IS NULL OR m.id IS NULL OR d.montaje_id IS NULL)'
        );
        $statement->bindValue('id', $inspeccionId, PDO::PARAM_INT);
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    /** @return array<string, mixed>|null */
    public function detalleFila(int $inspeccionId, int $detalleId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, inspeccion_id, cliente_id, posicion_id, neumatico_id, montaje_id, condicion
             FROM inspeccion_detalles
             WHERE id = :id
               AND inspeccion_id = :inspeccion_id
             LIMIT 1'
        );
        $statement->bindValue('id', $detalleId, PDO::PARAM_INT);
        $statement->bindValue('inspeccion_id', $inspeccionId, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @param array<string, mixed> $datos */
    public function actualizarDetalle(int $id, array $datos): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE inspeccion_detalles
             SET profundidad_interior_mm = :profundidad_interior_mm,
                 profundidad_centro_mm = :profundidad_centro_mm,
                 profundidad_exterior_mm = :profundidad_exterior_mm,
                 presion_psi = :presion_psi,
                 condicion = :condicion,
                 observacion = :observacion
             WHERE id = :id'
        );
        foreach (['profundidad_interior_mm', 'profundidad_centro_mm', 'profundidad_exterior_mm', 'presion_psi', 'observacion'] as $campo) {
            $statement->bindValue($campo, $datos[$campo], $datos[$campo] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        }
        $statement->bindValue('condicion', $datos['condicion']);
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
    }

    public function contarDetalles(int $inspeccionId): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM inspeccion_detalles WHERE inspeccion_id = :id');
        $statement->bindValue('id', $inspeccionId, PDO::PARAM_INT);
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    public function detallesParaAlertas(int $inspeccionId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT d.id, d.cliente_id, d.neumatico_id, d.profundidad_interior_mm, d.profundidad_centro_mm,
                    d.profundidad_exterior_mm, n.codigo, n.profundidad_minima_mm
             FROM inspeccion_detalles d
             INNER JOIN neumaticos n ON n.id = d.neumatico_id
             WHERE d.inspeccion_id = :id'
        );
        $statement->bindValue('id', $inspeccionId, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function danosDeInspeccion(int $inspeccionId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT g.inspeccion_detalle_id, g.severidad, t.nombre
             FROM inspeccion_detalle_danos g
             INNER JOIN inspeccion_detalles d ON d.id = g.inspeccion_detalle_id
             INNER JOIN tipos_dano t ON t.id = g.tipo_dano_id
             WHERE d.inspeccion_id = :id'
        );
        $statement->bindValue('id', $inspeccionId, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function tipoDano(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, codigo, nombre, activo FROM tipos_dano WHERE id = :id LIMIT 1'
        );
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    public function tiposDano(): array
    {
        $statement = $this->pdo->query(
            'SELECT id, codigo, nombre, descripcion
             FROM tipos_dano
             WHERE activo = 1
             ORDER BY nombre ASC'
        );

        return $statement === false ? [] : $statement->fetchAll();
    }

    /** @param array<string, mixed> $datos */
    public function agregarDano(array $datos): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO inspeccion_detalle_danos (inspeccion_detalle_id, tipo_dano_id, severidad, observacion)
             VALUES (:detalle_id, :tipo_id, :severidad, :observacion)'
        );
        $statement->bindValue('detalle_id', $datos['detalle_id'], PDO::PARAM_INT);
        $statement->bindValue('tipo_id', $datos['tipo_id'], PDO::PARAM_INT);
        $statement->bindValue('severidad', $datos['severidad'], $datos['severidad'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue('observacion', $datos['observacion'], $datos['observacion'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->execute();
    }

    /** @param array<string, mixed> $datos */
    public function actualizarDano(int $detalleId, int $tipoId, array $datos): int
    {
        $statement = $this->pdo->prepare(
            'UPDATE inspeccion_detalle_danos
             SET severidad = :severidad, observacion = :observacion
             WHERE inspeccion_detalle_id = :detalle_id
               AND tipo_dano_id = :tipo_id'
        );
        $statement->bindValue('severidad', $datos['severidad'], $datos['severidad'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue('observacion', $datos['observacion'], $datos['observacion'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue('detalle_id', $detalleId, PDO::PARAM_INT);
        $statement->bindValue('tipo_id', $tipoId, PDO::PARAM_INT);
        $statement->execute();

        return $statement->rowCount();
    }

    public function quitarDano(int $detalleId, int $tipoId): int
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM inspeccion_detalle_danos
             WHERE inspeccion_detalle_id = :detalle_id
               AND tipo_dano_id = :tipo_id'
        );
        $statement->bindValue('detalle_id', $detalleId, PDO::PARAM_INT);
        $statement->bindValue('tipo_id', $tipoId, PDO::PARAM_INT);
        $statement->execute();

        return $statement->rowCount();
    }

    public function alertaAbierta(int $detalleId, int $tipoId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT a.id
             FROM alertas a
             INNER JOIN estados_alerta e ON e.id = a.estado_id
             WHERE a.inspeccion_detalle_id = :detalle_id
               AND a.tipo_alerta_id = :tipo_id
               AND e.codigo = \'ABIERTA\'
             LIMIT 1'
        );
        $statement->bindValue('detalle_id', $detalleId, PDO::PARAM_INT);
        $statement->bindValue('tipo_id', $tipoId, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetch() !== false;
    }

    public function idCatalogo(string $tabla, string $codigo): int
    {
        if (!in_array($tabla, ['tipos_alerta', 'estados_alerta'], true)) {
            throw new \InvalidArgumentException('Catálogo no permitido.');
        }
        $statement = $this->pdo->prepare("SELECT id FROM {$tabla} WHERE codigo = :codigo LIMIT 1");
        $statement->bindValue('codigo', $codigo);
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    /** @param array<string, mixed> $datos */
    public function crearAlerta(array $datos): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO alertas (
                cliente_id, tipo_alerta_id, estado_id, unidad_id, neumatico_id, inspeccion_id,
                inspeccion_detalle_id, nivel, titulo, descripcion, recomendacion, generada_automaticamente
             ) VALUES (
                :cliente_id, :tipo_alerta_id, :estado_id, :unidad_id, :neumatico_id, :inspeccion_id,
                :inspeccion_detalle_id, :nivel, :titulo, :descripcion, :recomendacion, 1
             )'
        );
        foreach (['cliente_id', 'tipo_alerta_id', 'estado_id', 'unidad_id', 'neumatico_id', 'inspeccion_id', 'inspeccion_detalle_id'] as $campo) {
            $statement->bindValue($campo, $datos[$campo], PDO::PARAM_INT);
        }
        $statement->bindValue('nivel', $datos['nivel']);
        $statement->bindValue('titulo', $datos['titulo']);
        $statement->bindValue('descripcion', $datos['descripcion']);
        $statement->bindValue('recomendacion', $datos['recomendacion']);
        $statement->execute();
    }

    /** @param array<string, mixed> $datos */
    public function crearArchivo(array $datos): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO archivos (
                cliente_id, entidad_tipo, entidad_id, tipo_archivo, nombre_original, nombre_archivo,
                ruta, mime_type, tamano_bytes, checksum_sha256, usuario_id
             ) VALUES (
                :cliente_id, :entidad_tipo, :entidad_id, \'FOTO\', :nombre_original, :nombre_archivo,
                :ruta, :mime_type, :tamano_bytes, :checksum_sha256, :usuario_id
             )'
        );
        $statement->bindValue('cliente_id', $datos['cliente_id'], PDO::PARAM_INT);
        $statement->bindValue('entidad_tipo', $datos['entidad_tipo']);
        $statement->bindValue('entidad_id', $datos['entidad_id'], PDO::PARAM_INT);
        $statement->bindValue('nombre_original', $datos['nombre_original']);
        $statement->bindValue('nombre_archivo', $datos['nombre_archivo']);
        $statement->bindValue('ruta', $datos['ruta']);
        $statement->bindValue('mime_type', $datos['mime_type']);
        $statement->bindValue('tamano_bytes', $datos['tamano_bytes'], PDO::PARAM_INT);
        $statement->bindValue('checksum_sha256', $datos['checksum']);
        $statement->bindValue('usuario_id', $datos['usuario_id'], PDO::PARAM_INT);
        $statement->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function archivo(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, cliente_id, entidad_tipo, entidad_id, nombre_original, nombre_archivo,
                    ruta, mime_type, tamano_bytes, eliminado
             FROM archivos
             WHERE id = :id
             LIMIT 1'
        );
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    public function eliminarArchivo(int $id, int $usuarioId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE archivos
             SET eliminado = 1, eliminado_en = NOW(), eliminado_por = :usuario_id
             WHERE id = :id
               AND eliminado = 0'
        );
        $statement->bindValue('usuario_id', $usuarioId, PDO::PARAM_INT);
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
    }

    /**
     * @param array<string, mixed> $filtros
     * @return array{rows:list<array<string, mixed>>, total:int}
     */
    public function listar(array $filtros): array
    {
        $where = [];
        $params = [];
        $this->filtros($filtros, $where, $params);
        $sqlWhere = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);
        $contar = $this->pdo->prepare("SELECT COUNT(*) FROM inspecciones i INNER JOIN unidades u ON u.id = i.unidad_id INNER JOIN usuarios t ON t.id = i.tecnico_id {$sqlWhere}");
        foreach ($params as $nombre => $valor) {
            $contar->bindValue($nombre, $valor, is_int($valor) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $contar->execute();
        $total = (int) $contar->fetchColumn();
        $statement = $this->pdo->prepare(
            "SELECT i.id, i.fecha_inspeccion, i.estado, i.kilometraje, i.horometro, i.finalizada_en,
                    u.id AS unidad_id, u.codigo AS unidad_codigo,
                    c.id AS cliente_id, c.razon_social, c.nombre_comercial,
                    t.id AS tecnico_id, t.nombres AS tecnico_nombres, t.apellidos AS tecnico_apellidos,
                    (SELECT COUNT(*) FROM inspeccion_detalles d WHERE d.inspeccion_id = i.id) AS posiciones,
                    (SELECT COUNT(*) FROM inspeccion_detalles dc WHERE dc.inspeccion_id = i.id AND dc.condicion = 'CRITICO') AS criticos,
                    (SELECT COUNT(*) FROM inspeccion_detalles da WHERE da.inspeccion_id = i.id AND da.condicion = 'ATENCION') AS atencion
             FROM inspecciones i
             INNER JOIN unidades u ON u.id = i.unidad_id
             INNER JOIN clientes c ON c.id = i.cliente_id
             INNER JOIN usuarios t ON t.id = i.tecnico_id
             {$sqlWhere}
             ORDER BY {$filtros['sort']} {$filtros['direction']}, i.id DESC
             LIMIT {$filtros['limit']} OFFSET {$filtros['offset']}"
        );
        foreach ($params as $nombre => $valor) {
            $statement->bindValue($nombre, $valor, is_int($valor) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->execute();

        return ['rows' => $statement->fetchAll(), 'total' => $total];
    }

    /** @return array<string, mixed>|null */
    public function ficha(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT i.id, i.cliente_id, i.unidad_id, i.fecha_inspeccion, i.tecnico_id, i.kilometraje,
                    i.horometro, i.estado, i.observacion_general, i.finalizada_en,
                    u.codigo AS unidad_codigo, u.estado AS unidad_estado, u.configuracion_id,
                    c.razon_social, c.nombre_comercial,
                    t.nombres AS tecnico_nombres, t.apellidos AS tecnico_apellidos
             FROM inspecciones i
             INNER JOIN unidades u ON u.id = i.unidad_id
             INNER JOIN clientes c ON c.id = i.cliente_id
             INNER JOIN usuarios t ON t.id = i.tecnico_id
             WHERE i.id = :id
             LIMIT 1'
        );
        $statement->bindValue('id', $id, PDO::PARAM_INT);
        $statement->execute();
        $row = $statement->fetch();

        return $row === false ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    public function detalles(int $inspeccionId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT d.id, d.posicion_id, d.neumatico_id, d.montaje_id,
                    d.posicion_codigo_snapshot, d.eje_numero_snapshot, d.eje_nombre_snapshot,
                    d.lado_snapshot, d.ubicacion_snapshot,
                    d.profundidad_interior_mm, d.profundidad_centro_mm, d.profundidad_exterior_mm,
                    d.presion_psi, d.condicion, d.observacion,
                    n.codigo AS neumatico_codigo, n.profundidad_minima_mm, med.descripcion AS medida
             FROM inspeccion_detalles d
             INNER JOIN neumaticos n ON n.id = d.neumatico_id
             INNER JOIN medidas_neumatico med ON med.id = n.medida_id
             WHERE d.inspeccion_id = :id
             ORDER BY d.eje_numero_snapshot ASC, d.posicion_codigo_snapshot ASC'
        );
        $statement->bindValue('id', $inspeccionId, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function danosDeDetalle(int $detalleId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT g.tipo_dano_id, g.severidad, g.observacion, t.codigo, t.nombre
             FROM inspeccion_detalle_danos g
             INNER JOIN tipos_dano t ON t.id = g.tipo_dano_id
             WHERE g.inspeccion_detalle_id = :id
             ORDER BY t.nombre ASC'
        );
        $statement->bindValue('id', $detalleId, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function archivosDe(string $entidad, int $entidadId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, nombre_original, mime_type, tamano_bytes, creado_en
             FROM archivos
             WHERE entidad_tipo = :entidad
               AND entidad_id = :entidad_id
               AND eliminado = 0
             ORDER BY id ASC'
        );
        $statement->bindValue('entidad', $entidad);
        $statement->bindValue('entidad_id', $entidadId, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /** @param array<string, mixed> $filtros @param list<string> $where @param array<string, int|string> $params */
    private function filtros(array $filtros, array &$where, array &$params): void
    {
        if ($filtros['usuario_scope'] !== null) {
            $where[] = 'EXISTS (SELECT 1 FROM usuario_clientes uc WHERE uc.usuario_id = :scope_usuario AND uc.cliente_id = i.cliente_id AND ' . AsignacionVigente::sql('uc') . ')';
            $params['scope_usuario'] = $filtros['usuario_scope'];
        }
        if ($filtros['solo_finalizadas']) {
            $where[] = "i.estado = 'FINALIZADA'";
        }
        if ($filtros['tecnico_propietario'] !== null) {
            $where[] = "(i.estado = 'FINALIZADA' OR i.tecnico_id = :tecnico_propio)";
            $params['tecnico_propio'] = $filtros['tecnico_propietario'];
        }
        if ($filtros['cliente_id'] !== null) {
            $where[] = 'i.cliente_id = :cliente_id';
            $params['cliente_id'] = $filtros['cliente_id'];
        }
        if ($filtros['unidad_id'] !== null) {
            $where[] = 'i.unidad_id = :unidad_id';
            $params['unidad_id'] = $filtros['unidad_id'];
        }
        if ($filtros['tecnico_id'] !== null) {
            $where[] = 'i.tecnico_id = :tecnico_id';
            $params['tecnico_id'] = $filtros['tecnico_id'];
        }
        if ($filtros['estado'] !== null) {
            $where[] = 'i.estado = :estado';
            $params['estado'] = $filtros['estado'];
        }
        if ($filtros['fecha_inicio'] !== null) {
            $where[] = 'i.fecha_inspeccion >= :fecha_inicio';
            $params['fecha_inicio'] = $filtros['fecha_inicio'];
        }
        if ($filtros['fecha_fin'] !== null) {
            $where[] = 'i.fecha_inspeccion <= :fecha_fin';
            $params['fecha_fin'] = $filtros['fecha_fin'];
        }
        if ($filtros['neumatico_id'] !== null) {
            $where[] = 'EXISTS (SELECT 1 FROM inspeccion_detalles dn WHERE dn.inspeccion_id = i.id AND dn.neumatico_id = :neumatico_id)';
            $params['neumatico_id'] = $filtros['neumatico_id'];
        }
        if ($filtros['search'] !== null) {
            $where[] = '(u.codigo LIKE :busca_unidad ESCAPE \'\\\\\' OR CONCAT(t.nombres, \' \', t.apellidos) LIKE :busca_tecnico ESCAPE \'\\\\\')';
            $params['busca_unidad'] = $filtros['search'];
            $params['busca_tecnico'] = $filtros['search'];
        }
    }
}
