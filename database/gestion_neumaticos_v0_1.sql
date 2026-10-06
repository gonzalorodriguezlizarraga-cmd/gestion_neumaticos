-- ============================================================
-- GESTION DE NEUMATICOS - MODELO FISICO V0.1
-- Motor objetivo: MySQL 8.0+
-- Enfoque: multicliente, trazabilidad historica, soft-delete,
-- estados controlados, montajes temporales y auditoria.
-- ============================================================

CREATE DATABASE IF NOT EXISTS gestion_neumaticos
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_0900_ai_ci;
USE gestion_neumaticos;
SET NAMES utf8mb4;

-- A. SEGURIDAD
CREATE TABLE usuarios (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombres VARCHAR(120) NOT NULL,
    apellidos VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    telefono VARCHAR(40) NULL,
    estado VARCHAR(20) NOT NULL DEFAULT 'ACTIVO',
    ultimo_acceso DATETIME NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    eliminado TINYINT(1) NOT NULL DEFAULT 0,
    eliminado_en DATETIME NULL,
    CONSTRAINT uk_usuarios_email UNIQUE (email),
    CONSTRAINT ck_usuarios_estado CHECK (estado IN ('ACTIVO','INACTIVO','BLOQUEADO')),
    CONSTRAINT ck_usuarios_eliminado CHECK (eliminado IN (0,1))
) ENGINE=InnoDB;

CREATE TABLE roles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(60) NOT NULL,
    nombre VARCHAR(120) NOT NULL,
    descripcion VARCHAR(255) NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_roles_codigo UNIQUE (codigo),
    CONSTRAINT ck_roles_activo CHECK (activo IN (0,1))
) ENGINE=InnoDB;

CREATE TABLE usuario_roles (
    usuario_id BIGINT UNSIGNED NOT NULL,
    rol_id BIGINT UNSIGNED NOT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (usuario_id, rol_id),
    CONSTRAINT fk_usuario_roles_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
    CONSTRAINT fk_usuario_roles_rol FOREIGN KEY (rol_id) REFERENCES roles(id)
) ENGINE=InnoDB;

-- B. CLIENTES Y ORGANIZACION
CREATE TABLE clientes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    razon_social VARCHAR(200) NOT NULL,
    nombre_comercial VARCHAR(200) NULL,
    ruc_documento VARCHAR(30) NULL,
    direccion VARCHAR(255) NULL,
    telefono VARCHAR(40) NULL,
    email VARCHAR(190) NULL,
    logo_ruta VARCHAR(500) NULL,
    fecha_inicio_servicio DATE NULL,
    estado VARCHAR(20) NOT NULL DEFAULT 'ACTIVO',
    observacion TEXT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    creado_por BIGINT UNSIGNED NULL,
    actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    actualizado_por BIGINT UNSIGNED NULL,
    eliminado TINYINT(1) NOT NULL DEFAULT 0,
    eliminado_en DATETIME NULL,
    eliminado_por BIGINT UNSIGNED NULL,
    CONSTRAINT uk_clientes_ruc UNIQUE (ruc_documento),
    CONSTRAINT ck_clientes_estado CHECK (estado IN ('ACTIVO','INACTIVO','POTENCIAL')),
    CONSTRAINT ck_clientes_eliminado CHECK (eliminado IN (0,1)),
    CONSTRAINT fk_clientes_creado_por FOREIGN KEY (creado_por) REFERENCES usuarios(id),
    CONSTRAINT fk_clientes_actualizado_por FOREIGN KEY (actualizado_por) REFERENCES usuarios(id),
    CONSTRAINT fk_clientes_eliminado_por FOREIGN KEY (eliminado_por) REFERENCES usuarios(id)
) ENGINE=InnoDB;

CREATE TABLE cliente_contactos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cliente_id BIGINT UNSIGNED NOT NULL,
    nombre VARCHAR(180) NOT NULL,
    cargo VARCHAR(120) NULL,
    telefono VARCHAR(40) NULL,
    email VARCHAR(190) NULL,
    tipo_contacto VARCHAR(80) NULL,
    principal TINYINT(1) NOT NULL DEFAULT 0,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_cliente_contactos_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id),
    CONSTRAINT ck_cliente_contactos_principal CHECK (principal IN (0,1)),
    CONSTRAINT ck_cliente_contactos_activo CHECK (activo IN (0,1)),
    INDEX ix_cliente_contactos_cliente (cliente_id)
) ENGINE=InnoDB;

CREATE TABLE cliente_responsables (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cliente_id BIGINT UNSIGNED NOT NULL,
    usuario_id BIGINT UNSIGNED NOT NULL,
    tipo_responsabilidad VARCHAR(20) NOT NULL,
    fecha_inicio DATE NOT NULL,
    fecha_fin DATE NULL,
    principal TINYINT(1) NOT NULL DEFAULT 0,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    creado_por BIGINT UNSIGNED NULL,
    CONSTRAINT fk_cliente_responsables_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id),
    CONSTRAINT fk_cliente_responsables_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
    CONSTRAINT fk_cliente_responsables_creado_por FOREIGN KEY (creado_por) REFERENCES usuarios(id),
    CONSTRAINT ck_cliente_responsables_tipo CHECK (tipo_responsabilidad IN ('TECNICO','COMERCIAL')),
    CONSTRAINT ck_cliente_responsables_principal CHECK (principal IN (0,1)),
    CONSTRAINT ck_cliente_responsables_fechas CHECK (fecha_fin IS NULL OR fecha_fin >= fecha_inicio),
    INDEX ix_cliente_responsables_cliente (cliente_id),
    INDEX ix_cliente_responsables_usuario (usuario_id)
) ENGINE=InnoDB;

CREATE TABLE sedes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cliente_id BIGINT UNSIGNED NOT NULL,
    codigo VARCHAR(60) NULL,
    nombre VARCHAR(160) NOT NULL,
    direccion VARCHAR(255) NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    eliminado TINYINT(1) NOT NULL DEFAULT 0,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_sedes_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id),
    CONSTRAINT uk_sedes_cliente_codigo UNIQUE (cliente_id, codigo),
    CONSTRAINT uk_sedes_id_cliente UNIQUE (id, cliente_id),
    CONSTRAINT ck_sedes_activo CHECK (activo IN (0,1)),
    CONSTRAINT ck_sedes_eliminado CHECK (eliminado IN (0,1))
) ENGINE=InnoDB;

CREATE TABLE flotas (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cliente_id BIGINT UNSIGNED NOT NULL,
    codigo VARCHAR(60) NULL,
    nombre VARCHAR(160) NOT NULL,
    descripcion VARCHAR(255) NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    eliminado TINYINT(1) NOT NULL DEFAULT 0,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_flotas_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id),
    CONSTRAINT uk_flotas_cliente_codigo UNIQUE (cliente_id, codigo),
    CONSTRAINT uk_flotas_id_cliente UNIQUE (id, cliente_id),
    CONSTRAINT ck_flotas_activo CHECK (activo IN (0,1)),
    CONSTRAINT ck_flotas_eliminado CHECK (eliminado IN (0,1))
) ENGINE=InnoDB;

CREATE TABLE usuario_clientes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id BIGINT UNSIGNED NOT NULL,
    cliente_id BIGINT UNSIGNED NOT NULL,
    fecha_inicio DATE NOT NULL,
    fecha_fin DATE NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    creado_por BIGINT UNSIGNED NULL,
    CONSTRAINT fk_usuario_clientes_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
    CONSTRAINT fk_usuario_clientes_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id),
    CONSTRAINT fk_usuario_clientes_creado_por FOREIGN KEY (creado_por) REFERENCES usuarios(id),
    CONSTRAINT ck_usuario_clientes_activo CHECK (activo IN (0,1)),
    CONSTRAINT ck_usuario_clientes_fechas CHECK (fecha_fin IS NULL OR fecha_fin >= fecha_inicio),
    INDEX ix_usuario_clientes_usuario (usuario_id, activo),
    INDEX ix_usuario_clientes_cliente (cliente_id, activo)
) ENGINE=InnoDB;

-- C. UNIDADES Y CONFIGURACION
CREATE TABLE tipos_unidad (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(60) NOT NULL,
    nombre VARCHAR(120) NOT NULL,
    descripcion VARCHAR(255) NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_tipos_unidad_codigo UNIQUE (codigo),
    CONSTRAINT ck_tipos_unidad_activo CHECK (activo IN (0,1))
) ENGINE=InnoDB;

CREATE TABLE configuraciones_unidad (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(160) NOT NULL,
    descripcion VARCHAR(255) NULL,
    cantidad_ejes SMALLINT UNSIGNED NOT NULL,
    cantidad_posiciones SMALLINT UNSIGNED NOT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT ck_config_unidad_ejes CHECK (cantidad_ejes > 0),
    CONSTRAINT ck_config_unidad_posiciones CHECK (cantidad_posiciones > 0),
    CONSTRAINT ck_config_unidad_activo CHECK (activo IN (0,1))
) ENGINE=InnoDB;

CREATE TABLE configuracion_ejes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    configuracion_id BIGINT UNSIGNED NOT NULL,
    numero_eje SMALLINT UNSIGNED NOT NULL,
    nombre VARCHAR(100) NOT NULL,
    orden SMALLINT UNSIGNED NOT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_config_ejes_config FOREIGN KEY (configuracion_id) REFERENCES configuraciones_unidad(id),
    CONSTRAINT uk_config_ejes_numero UNIQUE (configuracion_id, numero_eje),
    CONSTRAINT uk_config_ejes_orden UNIQUE (configuracion_id, orden),
    CONSTRAINT uk_config_ejes_id_config UNIQUE (id, configuracion_id)
) ENGINE=InnoDB;

CREATE TABLE configuracion_posiciones (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    configuracion_id BIGINT UNSIGNED NOT NULL,
    eje_id BIGINT UNSIGNED NOT NULL,
    codigo VARCHAR(20) NOT NULL,
    lado VARCHAR(20) NOT NULL,
    ubicacion VARCHAR(20) NOT NULL,
    orden SMALLINT UNSIGNED NOT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_config_pos_config FOREIGN KEY (configuracion_id) REFERENCES configuraciones_unidad(id),
    CONSTRAINT fk_config_pos_eje_config FOREIGN KEY (eje_id, configuracion_id) REFERENCES configuracion_ejes(id, configuracion_id),
    CONSTRAINT uk_config_pos_codigo UNIQUE (configuracion_id, codigo),
    CONSTRAINT uk_config_pos_orden UNIQUE (configuracion_id, orden),
    CONSTRAINT uk_config_pos_id_config UNIQUE (id, configuracion_id),
    CONSTRAINT ck_config_pos_lado CHECK (lado IN ('IZQUIERDO','DERECHO','CENTRO')),
    CONSTRAINT ck_config_pos_ubicacion CHECK (ubicacion IN ('INTERIOR','EXTERIOR','SIMPLE','CENTRAL')),
    CONSTRAINT ck_config_pos_activo CHECK (activo IN (0,1))
) ENGINE=InnoDB;

CREATE TABLE unidades (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cliente_id BIGINT UNSIGNED NOT NULL,
    sede_id BIGINT UNSIGNED NULL,
    flota_id BIGINT UNSIGNED NULL,
    tipo_unidad_id BIGINT UNSIGNED NOT NULL,
    configuracion_id BIGINT UNSIGNED NULL,
    codigo VARCHAR(80) NOT NULL,
    placa VARCHAR(30) NULL,
    marca VARCHAR(100) NULL,
    modelo VARCHAR(100) NULL,
    anio SMALLINT UNSIGNED NULL,
    numero_serie VARCHAR(100) NULL,
    kilometraje_actual BIGINT UNSIGNED NULL,
    horometro_actual DECIMAL(12,2) NULL,
    estado VARCHAR(20) NOT NULL DEFAULT 'OPERATIVA',
    observacion TEXT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    creado_por BIGINT UNSIGNED NULL,
    actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    actualizado_por BIGINT UNSIGNED NULL,
    eliminado TINYINT(1) NOT NULL DEFAULT 0,
    eliminado_en DATETIME NULL,
    eliminado_por BIGINT UNSIGNED NULL,
    CONSTRAINT fk_unidades_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id),
    CONSTRAINT fk_unidades_sede_cliente FOREIGN KEY (sede_id, cliente_id) REFERENCES sedes(id, cliente_id),
    CONSTRAINT fk_unidades_flota_cliente FOREIGN KEY (flota_id, cliente_id) REFERENCES flotas(id, cliente_id),
    CONSTRAINT fk_unidades_tipo FOREIGN KEY (tipo_unidad_id) REFERENCES tipos_unidad(id),
    CONSTRAINT fk_unidades_config FOREIGN KEY (configuracion_id) REFERENCES configuraciones_unidad(id),
    CONSTRAINT fk_unidades_creado_por FOREIGN KEY (creado_por) REFERENCES usuarios(id),
    CONSTRAINT fk_unidades_actualizado_por FOREIGN KEY (actualizado_por) REFERENCES usuarios(id),
    CONSTRAINT fk_unidades_eliminado_por FOREIGN KEY (eliminado_por) REFERENCES usuarios(id),
    CONSTRAINT uk_unidades_cliente_codigo UNIQUE (cliente_id, codigo),
    CONSTRAINT uk_unidades_id_cliente UNIQUE (id, cliente_id),
    CONSTRAINT uk_unidades_id_config UNIQUE (id, configuracion_id),
    CONSTRAINT ck_unidades_estado CHECK (estado IN ('OPERATIVA','INACTIVA','BAJA')),
    CONSTRAINT ck_unidades_eliminado CHECK (eliminado IN (0,1)),
    INDEX ix_unidades_cliente (cliente_id, eliminado),
    INDEX ix_unidades_placa (placa)
) ENGINE=InnoDB;

-- D. CATALOGOS TECNICOS
CREATE TABLE marcas_neumatico (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(120) NOT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_marcas_neumatico_nombre UNIQUE (nombre),
    CONSTRAINT ck_marcas_neumatico_activo CHECK (activo IN (0,1))
) ENGINE=InnoDB;

CREATE TABLE modelos_neumatico (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    marca_id BIGINT UNSIGNED NOT NULL,
    nombre VARCHAR(140) NOT NULL,
    descripcion VARCHAR(255) NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_modelos_neumatico_marca FOREIGN KEY (marca_id) REFERENCES marcas_neumatico(id),
    CONSTRAINT uk_modelos_neumatico_marca_nombre UNIQUE (marca_id, nombre),
    CONSTRAINT ck_modelos_neumatico_activo CHECK (activo IN (0,1))
) ENGINE=InnoDB;

CREATE TABLE medidas_neumatico (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    descripcion VARCHAR(80) NOT NULL,
    ancho DECIMAL(8,2) NULL,
    perfil DECIMAL(8,2) NULL,
    construccion VARCHAR(10) NULL,
    diametro DECIMAL(8,2) NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_medidas_neumatico_descripcion UNIQUE (descripcion),
    CONSTRAINT ck_medidas_neumatico_activo CHECK (activo IN (0,1))
) ENGINE=InnoDB;

CREATE TABLE estados_neumatico (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(60) NOT NULL,
    nombre VARCHAR(120) NOT NULL,
    descripcion VARCHAR(255) NULL,
    permite_montaje TINYINT(1) NOT NULL DEFAULT 0,
    es_final TINYINT(1) NOT NULL DEFAULT 0,
    orden SMALLINT UNSIGNED NOT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_estados_neumatico_codigo UNIQUE (codigo),
    CONSTRAINT uk_estados_neumatico_orden UNIQUE (orden),
    CONSTRAINT ck_estados_neumatico_permite CHECK (permite_montaje IN (0,1)),
    CONSTRAINT ck_estados_neumatico_final CHECK (es_final IN (0,1)),
    CONSTRAINT ck_estados_neumatico_activo CHECK (activo IN (0,1))
) ENGINE=InnoDB;

-- E. NEUMATICOS
CREATE TABLE neumaticos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cliente_id BIGINT UNSIGNED NOT NULL,
    codigo VARCHAR(80) NOT NULL,
    numero_serie VARCHAR(120) NULL,
    modelo_id BIGINT UNSIGNED NOT NULL,
    medida_id BIGINT UNSIGNED NOT NULL,
    estado_id BIGINT UNSIGNED NOT NULL,
    fecha_adquisicion DATE NULL,
    costo_adquisicion DECIMAL(14,2) NULL,
    moneda CHAR(3) NULL,
    profundidad_inicial_mm DECIMAL(6,2) NULL,
    profundidad_minima_mm DECIMAL(6,2) NOT NULL,
    vida_actual SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    observacion TEXT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    creado_por BIGINT UNSIGNED NULL,
    actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    actualizado_por BIGINT UNSIGNED NULL,
    eliminado TINYINT(1) NOT NULL DEFAULT 0,
    eliminado_en DATETIME NULL,
    eliminado_por BIGINT UNSIGNED NULL,
    CONSTRAINT fk_neumaticos_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id),
    CONSTRAINT fk_neumaticos_modelo FOREIGN KEY (modelo_id) REFERENCES modelos_neumatico(id),
    CONSTRAINT fk_neumaticos_medida FOREIGN KEY (medida_id) REFERENCES medidas_neumatico(id),
    CONSTRAINT fk_neumaticos_estado FOREIGN KEY (estado_id) REFERENCES estados_neumatico(id),
    CONSTRAINT fk_neumaticos_creado_por FOREIGN KEY (creado_por) REFERENCES usuarios(id),
    CONSTRAINT fk_neumaticos_actualizado_por FOREIGN KEY (actualizado_por) REFERENCES usuarios(id),
    CONSTRAINT fk_neumaticos_eliminado_por FOREIGN KEY (eliminado_por) REFERENCES usuarios(id),
    CONSTRAINT uk_neumaticos_cliente_codigo UNIQUE (cliente_id, codigo),
    CONSTRAINT uk_neumaticos_cliente_serie UNIQUE (cliente_id, numero_serie),
    CONSTRAINT uk_neumaticos_id_cliente UNIQUE (id, cliente_id),
    CONSTRAINT ck_neumaticos_profundidad_min CHECK (profundidad_minima_mm >= 0),
    CONSTRAINT ck_neumaticos_profundidad_ini CHECK (profundidad_inicial_mm IS NULL OR profundidad_inicial_mm >= 0),
    CONSTRAINT ck_neumaticos_vida CHECK (vida_actual >= 1),
    CONSTRAINT ck_neumaticos_eliminado CHECK (eliminado IN (0,1)),
    INDEX ix_neumaticos_cliente_estado (cliente_id, estado_id)
) ENGINE=InnoDB;

-- F. MONTAJES
CREATE TABLE montajes_neumatico (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cliente_id BIGINT UNSIGNED NOT NULL,
    neumatico_id BIGINT UNSIGNED NOT NULL,
    unidad_id BIGINT UNSIGNED NOT NULL,
    configuracion_id BIGINT UNSIGNED NOT NULL,
    posicion_id BIGINT UNSIGNED NOT NULL,
    fecha_montaje DATETIME NOT NULL,
    km_montaje BIGINT UNSIGNED NULL,
    horometro_montaje DECIMAL(12,2) NULL,
    fecha_desmontaje DATETIME NULL,
    km_desmontaje BIGINT UNSIGNED NULL,
    horometro_desmontaje DECIMAL(12,2) NULL,
    motivo_desmontaje VARCHAR(255) NULL,
    usuario_montaje_id BIGINT UNSIGNED NOT NULL,
    usuario_desmontaje_id BIGINT UNSIGNED NULL,
    anulado TINYINT(1) NOT NULL DEFAULT 0,
    anulado_en DATETIME NULL,
    anulado_por BIGINT UNSIGNED NULL,
    motivo_anulacion VARCHAR(255) NULL,
    montaje_activo_flag TINYINT GENERATED ALWAYS AS (
        IF(fecha_desmontaje IS NULL AND anulado = 0, 1, NULL)
    ) STORED,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_montajes_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id),
    CONSTRAINT fk_montajes_neumatico_cliente FOREIGN KEY (neumatico_id, cliente_id) REFERENCES neumaticos(id, cliente_id),
    CONSTRAINT fk_montajes_unidad_cliente FOREIGN KEY (unidad_id, cliente_id) REFERENCES unidades(id, cliente_id),
    CONSTRAINT fk_montajes_unidad_config FOREIGN KEY (unidad_id, configuracion_id) REFERENCES unidades(id, configuracion_id),
    CONSTRAINT fk_montajes_posicion_config FOREIGN KEY (posicion_id, configuracion_id) REFERENCES configuracion_posiciones(id, configuracion_id),
    CONSTRAINT fk_montajes_usuario_montaje FOREIGN KEY (usuario_montaje_id) REFERENCES usuarios(id),
    CONSTRAINT fk_montajes_usuario_desmontaje FOREIGN KEY (usuario_desmontaje_id) REFERENCES usuarios(id),
    CONSTRAINT fk_montajes_anulado_por FOREIGN KEY (anulado_por) REFERENCES usuarios(id),
    CONSTRAINT uk_montajes_id_cliente UNIQUE (id, cliente_id),
    CONSTRAINT uk_montaje_activo_neumatico UNIQUE (neumatico_id, montaje_activo_flag),
    CONSTRAINT uk_montaje_activo_posicion UNIQUE (unidad_id, posicion_id, montaje_activo_flag),
    CONSTRAINT ck_montajes_fechas CHECK (fecha_desmontaje IS NULL OR fecha_desmontaje >= fecha_montaje),
    CONSTRAINT ck_montajes_km CHECK (km_desmontaje IS NULL OR km_montaje IS NULL OR km_desmontaje >= km_montaje),
    CONSTRAINT ck_montajes_horometro CHECK (horometro_desmontaje IS NULL OR horometro_montaje IS NULL OR horometro_desmontaje >= horometro_montaje),
    CONSTRAINT ck_montajes_anulado CHECK (anulado IN (0,1)),
    INDEX ix_montajes_cliente_unidad (cliente_id, unidad_id),
    INDEX ix_montajes_neumatico (neumatico_id, fecha_montaje)
) ENGINE=InnoDB;

-- G. MANTENIMIENTO
CREATE TABLE tipos_mantenimiento (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(60) NOT NULL,
    nombre VARCHAR(120) NOT NULL,
    descripcion VARCHAR(255) NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_tipos_mantenimiento_codigo UNIQUE (codigo),
    CONSTRAINT ck_tipos_mantenimiento_activo CHECK (activo IN (0,1))
) ENGINE=InnoDB;

CREATE TABLE estados_mantenimiento (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(60) NOT NULL,
    nombre VARCHAR(120) NOT NULL,
    orden SMALLINT UNSIGNED NOT NULL,
    es_final TINYINT(1) NOT NULL DEFAULT 0,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_estados_mantenimiento_codigo UNIQUE (codigo),
    CONSTRAINT uk_estados_mantenimiento_orden UNIQUE (orden),
    CONSTRAINT ck_estados_mantenimiento_final CHECK (es_final IN (0,1)),
    CONSTRAINT ck_estados_mantenimiento_activo CHECK (activo IN (0,1))
) ENGINE=InnoDB;

CREATE TABLE mantenimientos_neumatico (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cliente_id BIGINT UNSIGNED NOT NULL,
    neumatico_id BIGINT UNSIGNED NOT NULL,
    tipo_mantenimiento_id BIGINT UNSIGNED NOT NULL,
    estado_id BIGINT UNSIGNED NOT NULL,
    fecha_solicitud DATETIME NOT NULL,
    fecha_envio DATETIME NULL,
    fecha_retorno DATETIME NULL,
    tercero_nombre VARCHAR(180) NULL,
    costo DECIMAL(14,2) NULL,
    moneda CHAR(3) NULL,
    profundidad_antes_mm DECIMAL(6,2) NULL,
    profundidad_despues_mm DECIMAL(6,2) NULL,
    inicia_nueva_vida TINYINT(1) NOT NULL DEFAULT 0,
    observacion TEXT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    creado_por BIGINT UNSIGNED NOT NULL,
    actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    actualizado_por BIGINT UNSIGNED NULL,
    CONSTRAINT fk_mant_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id),
    CONSTRAINT fk_mant_neumatico_cliente FOREIGN KEY (neumatico_id, cliente_id) REFERENCES neumaticos(id, cliente_id),
    CONSTRAINT fk_mant_tipo FOREIGN KEY (tipo_mantenimiento_id) REFERENCES tipos_mantenimiento(id),
    CONSTRAINT fk_mant_estado FOREIGN KEY (estado_id) REFERENCES estados_mantenimiento(id),
    CONSTRAINT fk_mant_creado_por FOREIGN KEY (creado_por) REFERENCES usuarios(id),
    CONSTRAINT fk_mant_actualizado_por FOREIGN KEY (actualizado_por) REFERENCES usuarios(id),
    CONSTRAINT ck_mant_fechas_envio CHECK (fecha_envio IS NULL OR fecha_envio >= fecha_solicitud),
    CONSTRAINT ck_mant_fechas_retorno CHECK (fecha_retorno IS NULL OR fecha_envio IS NULL OR fecha_retorno >= fecha_envio),
    CONSTRAINT uk_mant_id_cliente UNIQUE (id, cliente_id),
    CONSTRAINT ck_mant_nueva_vida CHECK (inicia_nueva_vida IN (0,1)),
    INDEX ix_mant_cliente_estado (cliente_id, estado_id),
    INDEX ix_mant_neumatico_fecha (neumatico_id, fecha_solicitud)
) ENGINE=InnoDB;

CREATE TABLE mantenimiento_estado_historial (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    mantenimiento_id BIGINT UNSIGNED NOT NULL,
    estado_anterior_id BIGINT UNSIGNED NULL,
    estado_nuevo_id BIGINT UNSIGNED NOT NULL,
    fecha_cambio DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    usuario_id BIGINT UNSIGNED NOT NULL,
    observacion VARCHAR(500) NULL,
    CONSTRAINT fk_mant_hist_mant FOREIGN KEY (mantenimiento_id) REFERENCES mantenimientos_neumatico(id),
    CONSTRAINT fk_mant_hist_estado_ant FOREIGN KEY (estado_anterior_id) REFERENCES estados_mantenimiento(id),
    CONSTRAINT fk_mant_hist_estado_nuevo FOREIGN KEY (estado_nuevo_id) REFERENCES estados_mantenimiento(id),
    CONSTRAINT fk_mant_hist_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;

-- H. MOVIMIENTOS
CREATE TABLE tipos_movimiento (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(60) NOT NULL,
    nombre VARCHAR(120) NOT NULL,
    descripcion VARCHAR(255) NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_tipos_movimiento_codigo UNIQUE (codigo),
    CONSTRAINT ck_tipos_movimiento_activo CHECK (activo IN (0,1))
) ENGINE=InnoDB;

CREATE TABLE movimientos_neumatico (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cliente_id BIGINT UNSIGNED NOT NULL,
    neumatico_id BIGINT UNSIGNED NOT NULL,
    tipo_movimiento_id BIGINT UNSIGNED NOT NULL,
    fecha DATETIME NOT NULL,
    unidad_origen_id BIGINT UNSIGNED NULL,
    posicion_origen_id BIGINT UNSIGNED NULL,
    unidad_destino_id BIGINT UNSIGNED NULL,
    posicion_destino_id BIGINT UNSIGNED NULL,
    km_unidad BIGINT UNSIGNED NULL,
    horometro_unidad DECIMAL(12,2) NULL,
    montaje_id BIGINT UNSIGNED NULL,
    mantenimiento_id BIGINT UNSIGNED NULL,
    grupo_operacion CHAR(36) NULL,
    observacion TEXT NULL,
    usuario_id BIGINT UNSIGNED NOT NULL,
    anulado TINYINT(1) NOT NULL DEFAULT 0,
    anulado_en DATETIME NULL,
    anulado_por BIGINT UNSIGNED NULL,
    motivo_anulacion VARCHAR(255) NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_mov_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id),
    CONSTRAINT fk_mov_neumatico_cliente FOREIGN KEY (neumatico_id, cliente_id) REFERENCES neumaticos(id, cliente_id),
    CONSTRAINT fk_mov_tipo FOREIGN KEY (tipo_movimiento_id) REFERENCES tipos_movimiento(id),
    CONSTRAINT fk_mov_unidad_origen_cliente FOREIGN KEY (unidad_origen_id, cliente_id) REFERENCES unidades(id, cliente_id),
    CONSTRAINT fk_mov_pos_origen FOREIGN KEY (posicion_origen_id) REFERENCES configuracion_posiciones(id),
    CONSTRAINT fk_mov_unidad_destino_cliente FOREIGN KEY (unidad_destino_id, cliente_id) REFERENCES unidades(id, cliente_id),
    CONSTRAINT fk_mov_pos_destino FOREIGN KEY (posicion_destino_id) REFERENCES configuracion_posiciones(id),
    CONSTRAINT fk_mov_montaje_cliente FOREIGN KEY (montaje_id, cliente_id) REFERENCES montajes_neumatico(id, cliente_id),
    CONSTRAINT fk_mov_mantenimiento_cliente FOREIGN KEY (mantenimiento_id, cliente_id) REFERENCES mantenimientos_neumatico(id, cliente_id),
    CONSTRAINT fk_mov_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
    CONSTRAINT fk_mov_anulado_por FOREIGN KEY (anulado_por) REFERENCES usuarios(id),
    CONSTRAINT ck_mov_anulado CHECK (anulado IN (0,1)),
    INDEX ix_mov_cliente_fecha (cliente_id, fecha),
    INDEX ix_mov_neumatico_fecha (neumatico_id, fecha),
    INDEX ix_mov_grupo_operacion (grupo_operacion)
) ENGINE=InnoDB;

-- I. HISTORIAL DE ESTADOS Y VIDAS
CREATE TABLE neumatico_estado_historial (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cliente_id BIGINT UNSIGNED NOT NULL,
    neumatico_id BIGINT UNSIGNED NOT NULL,
    estado_anterior_id BIGINT UNSIGNED NULL,
    estado_nuevo_id BIGINT UNSIGNED NOT NULL,
    fecha_cambio DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    movimiento_id BIGINT UNSIGNED NULL,
    usuario_id BIGINT UNSIGNED NOT NULL,
    motivo VARCHAR(500) NULL,
    CONSTRAINT fk_neu_hist_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id),
    CONSTRAINT fk_neu_hist_neumatico_cliente FOREIGN KEY (neumatico_id, cliente_id) REFERENCES neumaticos(id, cliente_id),
    CONSTRAINT fk_neu_hist_estado_ant FOREIGN KEY (estado_anterior_id) REFERENCES estados_neumatico(id),
    CONSTRAINT fk_neu_hist_estado_nuevo FOREIGN KEY (estado_nuevo_id) REFERENCES estados_neumatico(id),
    CONSTRAINT fk_neu_hist_movimiento FOREIGN KEY (movimiento_id) REFERENCES movimientos_neumatico(id),
    CONSTRAINT fk_neu_hist_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
    INDEX ix_neu_hist_neumatico_fecha (neumatico_id, fecha_cambio)
) ENGINE=InnoDB;

CREATE TABLE neumatico_vidas (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cliente_id BIGINT UNSIGNED NOT NULL,
    neumatico_id BIGINT UNSIGNED NOT NULL,
    numero_vida SMALLINT UNSIGNED NOT NULL,
    fecha_inicio DATETIME NOT NULL,
    fecha_fin DATETIME NULL,
    km_inicio BIGINT UNSIGNED NULL,
    km_fin BIGINT UNSIGNED NULL,
    profundidad_inicial_mm DECIMAL(6,2) NULL,
    profundidad_final_mm DECIMAL(6,2) NULL,
    motivo_fin VARCHAR(255) NULL,
    mantenimiento_origen_id BIGINT UNSIGNED NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_vidas_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id),
    CONSTRAINT fk_vidas_neumatico_cliente FOREIGN KEY (neumatico_id, cliente_id) REFERENCES neumaticos(id, cliente_id),
    CONSTRAINT fk_vidas_mantenimiento FOREIGN KEY (mantenimiento_origen_id) REFERENCES mantenimientos_neumatico(id),
    CONSTRAINT uk_vidas_neumatico_numero UNIQUE (neumatico_id, numero_vida),
    CONSTRAINT ck_vidas_numero CHECK (numero_vida >= 1),
    CONSTRAINT ck_vidas_fechas CHECK (fecha_fin IS NULL OR fecha_fin >= fecha_inicio),
    CONSTRAINT ck_vidas_km CHECK (km_fin IS NULL OR km_inicio IS NULL OR km_fin >= km_inicio)
) ENGINE=InnoDB;

-- J. INSPECCIONES
CREATE TABLE inspecciones (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cliente_id BIGINT UNSIGNED NOT NULL,
    unidad_id BIGINT UNSIGNED NOT NULL,
    fecha_inspeccion DATETIME NOT NULL,
    tecnico_id BIGINT UNSIGNED NOT NULL,
    kilometraje BIGINT UNSIGNED NULL,
    horometro DECIMAL(12,2) NULL,
    estado VARCHAR(20) NOT NULL DEFAULT 'BORRADOR',
    observacion_general TEXT NULL,
    finalizada_en DATETIME NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    creado_por BIGINT UNSIGNED NOT NULL,
    actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    actualizado_por BIGINT UNSIGNED NULL,
    CONSTRAINT fk_insp_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id),
    CONSTRAINT fk_insp_unidad_cliente FOREIGN KEY (unidad_id, cliente_id) REFERENCES unidades(id, cliente_id),
    CONSTRAINT fk_insp_tecnico FOREIGN KEY (tecnico_id) REFERENCES usuarios(id),
    CONSTRAINT fk_insp_creado_por FOREIGN KEY (creado_por) REFERENCES usuarios(id),
    CONSTRAINT fk_insp_actualizado_por FOREIGN KEY (actualizado_por) REFERENCES usuarios(id),
    CONSTRAINT uk_insp_id_cliente UNIQUE (id, cliente_id),
    CONSTRAINT ck_insp_estado CHECK (estado IN ('BORRADOR','FINALIZADA','ANULADA')),
    INDEX ix_insp_cliente_fecha (cliente_id, fecha_inspeccion),
    INDEX ix_insp_unidad_fecha (unidad_id, fecha_inspeccion)
) ENGINE=InnoDB;

CREATE TABLE inspeccion_detalles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inspeccion_id BIGINT UNSIGNED NOT NULL,
    cliente_id BIGINT UNSIGNED NOT NULL,
    posicion_id BIGINT UNSIGNED NOT NULL,
    neumatico_id BIGINT UNSIGNED NOT NULL,
    montaje_id BIGINT UNSIGNED NULL,
    posicion_codigo_snapshot VARCHAR(20) NOT NULL,
    eje_numero_snapshot SMALLINT UNSIGNED NOT NULL,
    eje_nombre_snapshot VARCHAR(100) NOT NULL,
    lado_snapshot VARCHAR(20) NOT NULL,
    ubicacion_snapshot VARCHAR(20) NOT NULL,
    profundidad_interior_mm DECIMAL(6,2) NULL,
    profundidad_centro_mm DECIMAL(6,2) NULL,
    profundidad_exterior_mm DECIMAL(6,2) NULL,
    presion_psi DECIMAL(8,2) NULL,
    condicion VARCHAR(20) NOT NULL,
    observacion TEXT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_insp_det_insp_cliente FOREIGN KEY (inspeccion_id, cliente_id) REFERENCES inspecciones(id, cliente_id),
    CONSTRAINT fk_insp_det_posicion FOREIGN KEY (posicion_id) REFERENCES configuracion_posiciones(id),
    CONSTRAINT fk_insp_det_neumatico_cliente FOREIGN KEY (neumatico_id, cliente_id) REFERENCES neumaticos(id, cliente_id),
    CONSTRAINT fk_insp_det_montaje_cliente FOREIGN KEY (montaje_id, cliente_id) REFERENCES montajes_neumatico(id, cliente_id),
    CONSTRAINT uk_insp_det_posicion UNIQUE (inspeccion_id, posicion_id),
    CONSTRAINT uk_insp_det_neumatico UNIQUE (inspeccion_id, neumatico_id),
    CONSTRAINT uk_insp_det_id_cliente UNIQUE (id, cliente_id),
    CONSTRAINT ck_insp_det_condicion CHECK (condicion IN ('NORMAL','ATENCION','CRITICO')),
    CONSTRAINT ck_insp_det_prof_i CHECK (profundidad_interior_mm IS NULL OR profundidad_interior_mm >= 0),
    CONSTRAINT ck_insp_det_prof_c CHECK (profundidad_centro_mm IS NULL OR profundidad_centro_mm >= 0),
    CONSTRAINT ck_insp_det_prof_e CHECK (profundidad_exterior_mm IS NULL OR profundidad_exterior_mm >= 0),
    CONSTRAINT ck_insp_det_presion CHECK (presion_psi IS NULL OR presion_psi >= 0)
) ENGINE=InnoDB;

CREATE TABLE tipos_dano (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(60) NOT NULL,
    nombre VARCHAR(140) NOT NULL,
    descripcion VARCHAR(255) NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_tipos_dano_codigo UNIQUE (codigo),
    CONSTRAINT ck_tipos_dano_activo CHECK (activo IN (0,1))
) ENGINE=InnoDB;

CREATE TABLE inspeccion_detalle_danos (
    inspeccion_detalle_id BIGINT UNSIGNED NOT NULL,
    tipo_dano_id BIGINT UNSIGNED NOT NULL,
    severidad VARCHAR(20) NULL,
    observacion VARCHAR(500) NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (inspeccion_detalle_id, tipo_dano_id),
    CONSTRAINT fk_insp_danos_detalle FOREIGN KEY (inspeccion_detalle_id) REFERENCES inspeccion_detalles(id),
    CONSTRAINT fk_insp_danos_tipo FOREIGN KEY (tipo_dano_id) REFERENCES tipos_dano(id),
    CONSTRAINT ck_insp_danos_severidad CHECK (severidad IS NULL OR severidad IN ('LEVE','MEDIA','ALTA','CRITICA'))
) ENGINE=InnoDB;

-- K. ARCHIVOS Y FOTOGRAFIAS
CREATE TABLE archivos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cliente_id BIGINT UNSIGNED NOT NULL,
    entidad_tipo VARCHAR(50) NOT NULL,
    entidad_id BIGINT UNSIGNED NOT NULL,
    tipo_archivo VARCHAR(20) NOT NULL,
    nombre_original VARCHAR(255) NOT NULL,
    nombre_archivo VARCHAR(255) NOT NULL,
    ruta VARCHAR(700) NOT NULL,
    mime_type VARCHAR(120) NOT NULL,
    tamano_bytes BIGINT UNSIGNED NULL,
    checksum_sha256 CHAR(64) NULL,
    descripcion VARCHAR(500) NULL,
    usuario_id BIGINT UNSIGNED NOT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    eliminado TINYINT(1) NOT NULL DEFAULT 0,
    eliminado_en DATETIME NULL,
    eliminado_por BIGINT UNSIGNED NULL,
    CONSTRAINT fk_archivos_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id),
    CONSTRAINT fk_archivos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
    CONSTRAINT fk_archivos_eliminado_por FOREIGN KEY (eliminado_por) REFERENCES usuarios(id),
    CONSTRAINT ck_archivos_tipo CHECK (tipo_archivo IN ('FOTO','DOCUMENTO')),
    CONSTRAINT ck_archivos_eliminado CHECK (eliminado IN (0,1)),
    INDEX ix_archivos_entidad (cliente_id, entidad_tipo, entidad_id, eliminado)
) ENGINE=InnoDB;

-- L. DESCARTE
CREATE TABLE motivos_descarte (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(60) NOT NULL,
    nombre VARCHAR(140) NOT NULL,
    descripcion VARCHAR(255) NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_motivos_descarte_codigo UNIQUE (codigo),
    CONSTRAINT ck_motivos_descarte_activo CHECK (activo IN (0,1))
) ENGINE=InnoDB;

CREATE TABLE descartes_neumatico (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cliente_id BIGINT UNSIGNED NOT NULL,
    neumatico_id BIGINT UNSIGNED NOT NULL,
    fecha_descarte DATETIME NOT NULL,
    motivo_descarte_id BIGINT UNSIGNED NOT NULL,
    vida_final SMALLINT UNSIGNED NOT NULL,
    km_totales BIGINT UNSIGNED NULL,
    profundidad_final_mm DECIMAL(6,2) NULL,
    observacion TEXT NULL,
    usuario_id BIGINT UNSIGNED NOT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_desc_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id),
    CONSTRAINT fk_desc_neumatico_cliente FOREIGN KEY (neumatico_id, cliente_id) REFERENCES neumaticos(id, cliente_id),
    CONSTRAINT fk_desc_motivo FOREIGN KEY (motivo_descarte_id) REFERENCES motivos_descarte(id),
    CONSTRAINT fk_desc_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
    CONSTRAINT uk_desc_neumatico UNIQUE (neumatico_id),
    CONSTRAINT ck_desc_vida CHECK (vida_final >= 1),
    CONSTRAINT ck_desc_prof CHECK (profundidad_final_mm IS NULL OR profundidad_final_mm >= 0)
) ENGINE=InnoDB;

-- M. ALERTAS
CREATE TABLE tipos_alerta (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(60) NOT NULL,
    nombre VARCHAR(140) NOT NULL,
    descripcion VARCHAR(255) NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_tipos_alerta_codigo UNIQUE (codigo),
    CONSTRAINT ck_tipos_alerta_activo CHECK (activo IN (0,1))
) ENGINE=InnoDB;

CREATE TABLE estados_alerta (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(60) NOT NULL,
    nombre VARCHAR(120) NOT NULL,
    orden SMALLINT UNSIGNED NOT NULL,
    es_final TINYINT(1) NOT NULL DEFAULT 0,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_estados_alerta_codigo UNIQUE (codigo),
    CONSTRAINT uk_estados_alerta_orden UNIQUE (orden),
    CONSTRAINT ck_estados_alerta_final CHECK (es_final IN (0,1)),
    CONSTRAINT ck_estados_alerta_activo CHECK (activo IN (0,1))
) ENGINE=InnoDB;

CREATE TABLE alertas (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cliente_id BIGINT UNSIGNED NOT NULL,
    tipo_alerta_id BIGINT UNSIGNED NOT NULL,
    estado_id BIGINT UNSIGNED NOT NULL,
    unidad_id BIGINT UNSIGNED NULL,
    neumatico_id BIGINT UNSIGNED NULL,
    inspeccion_id BIGINT UNSIGNED NULL,
    inspeccion_detalle_id BIGINT UNSIGNED NULL,
    fecha_generacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    nivel VARCHAR(20) NOT NULL,
    titulo VARCHAR(180) NOT NULL,
    descripcion TEXT NOT NULL,
    recomendacion TEXT NULL,
    generada_automaticamente TINYINT(1) NOT NULL DEFAULT 1,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_alertas_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id),
    CONSTRAINT fk_alertas_tipo FOREIGN KEY (tipo_alerta_id) REFERENCES tipos_alerta(id),
    CONSTRAINT fk_alertas_estado FOREIGN KEY (estado_id) REFERENCES estados_alerta(id),
    CONSTRAINT fk_alertas_unidad_cliente FOREIGN KEY (unidad_id, cliente_id) REFERENCES unidades(id, cliente_id),
    CONSTRAINT fk_alertas_neumatico_cliente FOREIGN KEY (neumatico_id, cliente_id) REFERENCES neumaticos(id, cliente_id),
    CONSTRAINT fk_alertas_inspeccion_cliente FOREIGN KEY (inspeccion_id, cliente_id) REFERENCES inspecciones(id, cliente_id),
    CONSTRAINT fk_alertas_detalle_cliente FOREIGN KEY (inspeccion_detalle_id, cliente_id) REFERENCES inspeccion_detalles(id, cliente_id),
    CONSTRAINT ck_alertas_nivel CHECK (nivel IN ('INFORMATIVA','ATENCION','CRITICA')),
    CONSTRAINT uk_alertas_id_cliente UNIQUE (id, cliente_id),
    CONSTRAINT ck_alertas_auto CHECK (generada_automaticamente IN (0,1)),
    INDEX ix_alertas_cliente_estado (cliente_id, estado_id, fecha_generacion)
) ENGINE=InnoDB;

CREATE TABLE alerta_estado_historial (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    alerta_id BIGINT UNSIGNED NOT NULL,
    estado_anterior_id BIGINT UNSIGNED NULL,
    estado_nuevo_id BIGINT UNSIGNED NOT NULL,
    fecha_cambio DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    usuario_id BIGINT UNSIGNED NOT NULL,
    observacion VARCHAR(500) NULL,
    CONSTRAINT fk_alerta_hist_alerta FOREIGN KEY (alerta_id) REFERENCES alertas(id),
    CONSTRAINT fk_alerta_hist_estado_ant FOREIGN KEY (estado_anterior_id) REFERENCES estados_alerta(id),
    CONSTRAINT fk_alerta_hist_estado_nuevo FOREIGN KEY (estado_nuevo_id) REFERENCES estados_alerta(id),
    CONSTRAINT fk_alerta_hist_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;

-- N. COMERCIAL
CREATE TABLE estados_oportunidad (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(60) NOT NULL,
    nombre VARCHAR(120) NOT NULL,
    orden SMALLINT UNSIGNED NOT NULL,
    es_final TINYINT(1) NOT NULL DEFAULT 0,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uk_estados_oportunidad_codigo UNIQUE (codigo),
    CONSTRAINT uk_estados_oportunidad_orden UNIQUE (orden),
    CONSTRAINT ck_estados_oportunidad_final CHECK (es_final IN (0,1)),
    CONSTRAINT ck_estados_oportunidad_activo CHECK (activo IN (0,1))
) ENGINE=InnoDB;

CREATE TABLE oportunidades (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cliente_id BIGINT UNSIGNED NOT NULL,
    responsable_comercial_id BIGINT UNSIGNED NOT NULL,
    estado_id BIGINT UNSIGNED NOT NULL,
    origen VARCHAR(20) NOT NULL,
    alerta_id BIGINT UNSIGNED NULL,
    titulo VARCHAR(180) NOT NULL,
    descripcion TEXT NULL,
    fecha_deteccion DATETIME NOT NULL,
    fecha_estimada_necesidad DATE NULL,
    valor_estimado DECIMAL(14,2) NULL,
    moneda CHAR(3) NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    creado_por BIGINT UNSIGNED NOT NULL,
    actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    actualizado_por BIGINT UNSIGNED NULL,
    CONSTRAINT fk_op_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id),
    CONSTRAINT fk_op_responsable FOREIGN KEY (responsable_comercial_id) REFERENCES usuarios(id),
    CONSTRAINT fk_op_estado FOREIGN KEY (estado_id) REFERENCES estados_oportunidad(id),
    CONSTRAINT fk_op_alerta_cliente FOREIGN KEY (alerta_id, cliente_id) REFERENCES alertas(id, cliente_id),
    CONSTRAINT fk_op_creado_por FOREIGN KEY (creado_por) REFERENCES usuarios(id),
    CONSTRAINT fk_op_actualizado_por FOREIGN KEY (actualizado_por) REFERENCES usuarios(id),
    CONSTRAINT uk_op_id_cliente UNIQUE (id, cliente_id),
    CONSTRAINT ck_op_origen CHECK (origen IN ('MANUAL','ALERTA','PROYECCION')),
    INDEX ix_op_cliente_estado (cliente_id, estado_id),
    INDEX ix_op_responsable_estado (responsable_comercial_id, estado_id)
) ENGINE=InnoDB;

CREATE TABLE oportunidad_detalles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    oportunidad_id BIGINT UNSIGNED NOT NULL,
    medida_neumatico_id BIGINT UNSIGNED NULL,
    modelo_neumatico_id BIGINT UNSIGNED NULL,
    cantidad DECIMAL(12,2) NOT NULL,
    precio_estimado DECIMAL(14,2) NULL,
    observacion VARCHAR(500) NULL,
    CONSTRAINT fk_op_det_op FOREIGN KEY (oportunidad_id) REFERENCES oportunidades(id),
    CONSTRAINT fk_op_det_medida FOREIGN KEY (medida_neumatico_id) REFERENCES medidas_neumatico(id),
    CONSTRAINT fk_op_det_modelo FOREIGN KEY (modelo_neumatico_id) REFERENCES modelos_neumatico(id),
    CONSTRAINT ck_op_det_cantidad CHECK (cantidad > 0)
) ENGINE=InnoDB;

CREATE TABLE oportunidad_estado_historial (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    oportunidad_id BIGINT UNSIGNED NOT NULL,
    estado_anterior_id BIGINT UNSIGNED NULL,
    estado_nuevo_id BIGINT UNSIGNED NOT NULL,
    fecha_cambio DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    usuario_id BIGINT UNSIGNED NOT NULL,
    motivo VARCHAR(500) NULL,
    CONSTRAINT fk_op_hist_op FOREIGN KEY (oportunidad_id) REFERENCES oportunidades(id),
    CONSTRAINT fk_op_hist_estado_ant FOREIGN KEY (estado_anterior_id) REFERENCES estados_oportunidad(id),
    CONSTRAINT fk_op_hist_estado_nuevo FOREIGN KEY (estado_nuevo_id) REFERENCES estados_oportunidad(id),
    CONSTRAINT fk_op_hist_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;

CREATE TABLE seguimientos_comerciales (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cliente_id BIGINT UNSIGNED NOT NULL,
    oportunidad_id BIGINT UNSIGNED NULL,
    usuario_id BIGINT UNSIGNED NOT NULL,
    tipo VARCHAR(20) NOT NULL,
    fecha DATETIME NOT NULL,
    resultado TEXT NULL,
    proximo_seguimiento DATETIME NULL,
    observacion TEXT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_seg_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id),
    CONSTRAINT fk_seg_op_cliente FOREIGN KEY (oportunidad_id, cliente_id) REFERENCES oportunidades(id, cliente_id),
    CONSTRAINT fk_seg_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
    CONSTRAINT ck_seg_tipo CHECK (tipo IN ('LLAMADA','VISITA','REUNION','CORREO','OTRO'))
) ENGINE=InnoDB;

CREATE TABLE cotizaciones (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cliente_id BIGINT UNSIGNED NOT NULL,
    oportunidad_id BIGINT UNSIGNED NULL,
    numero VARCHAR(60) NOT NULL,
    fecha DATE NOT NULL,
    estado VARCHAR(20) NOT NULL DEFAULT 'BORRADOR',
    moneda CHAR(3) NOT NULL,
    subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
    total DECIMAL(14,2) NOT NULL DEFAULT 0,
    observacion TEXT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    creado_por BIGINT UNSIGNED NOT NULL,
    actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_cot_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id),
    CONSTRAINT fk_cot_op_cliente FOREIGN KEY (oportunidad_id, cliente_id) REFERENCES oportunidades(id, cliente_id),
    CONSTRAINT fk_cot_creado_por FOREIGN KEY (creado_por) REFERENCES usuarios(id),
    CONSTRAINT uk_cot_cliente_numero UNIQUE (cliente_id, numero),
    CONSTRAINT ck_cot_estado CHECK (estado IN ('BORRADOR','ENVIADA','ACEPTADA','RECHAZADA','ANULADA')),
    CONSTRAINT ck_cot_subtotal CHECK (subtotal >= 0),
    CONSTRAINT ck_cot_total CHECK (total >= 0)
) ENGINE=InnoDB;

CREATE TABLE cotizacion_detalles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cotizacion_id BIGINT UNSIGNED NOT NULL,
    descripcion VARCHAR(255) NOT NULL,
    modelo_neumatico_id BIGINT UNSIGNED NULL,
    medida_neumatico_id BIGINT UNSIGNED NULL,
    cantidad DECIMAL(12,2) NOT NULL,
    precio_unitario DECIMAL(14,2) NOT NULL,
    subtotal DECIMAL(14,2) NOT NULL,
    CONSTRAINT fk_cot_det_cot FOREIGN KEY (cotizacion_id) REFERENCES cotizaciones(id),
    CONSTRAINT fk_cot_det_modelo FOREIGN KEY (modelo_neumatico_id) REFERENCES modelos_neumatico(id),
    CONSTRAINT fk_cot_det_medida FOREIGN KEY (medida_neumatico_id) REFERENCES medidas_neumatico(id),
    CONSTRAINT ck_cot_det_cantidad CHECK (cantidad > 0),
    CONSTRAINT ck_cot_det_precio CHECK (precio_unitario >= 0),
    CONSTRAINT ck_cot_det_subtotal CHECK (subtotal >= 0)
) ENGINE=InnoDB;

-- O. AUDITORIA
CREATE TABLE auditoria (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cliente_id BIGINT UNSIGNED NULL,
    usuario_id BIGINT UNSIGNED NOT NULL,
    entidad VARCHAR(80) NOT NULL,
    entidad_id BIGINT UNSIGNED NOT NULL,
    accion VARCHAR(40) NOT NULL,
    datos_anteriores JSON NULL,
    datos_nuevos JSON NULL,
    fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip VARCHAR(64) NULL,
    user_agent VARCHAR(500) NULL,
    CONSTRAINT fk_aud_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id),
    CONSTRAINT fk_aud_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
    INDEX ix_aud_cliente_fecha (cliente_id, fecha),
    INDEX ix_aud_entidad (entidad, entidad_id, fecha),
    INDEX ix_aud_usuario_fecha (usuario_id, fecha)
) ENGINE=InnoDB;

-- P. CATALOGOS INICIALES
INSERT INTO roles (codigo, nombre, descripcion) VALUES
('ADMIN_GENERAL','Administrador general','Control total de la plataforma'),
('GESTOR_NEUMATICOS','Gestor de neumáticos','Gestión técnica integral de clientes y neumáticos'),
('TECNICO_INSPECCION','Técnico de inspección','Registro de inspecciones y tareas operativas autorizadas'),
('VENDEDOR','Vendedor','Seguimiento comercial de clientes y oportunidades'),
('ADMIN_CLIENTE','Administrador cliente','Consulta integral de la información de su empresa'),
('CONSULTA_EJECUTIVA','Consulta ejecutiva','Acceso a indicadores y reportes ejecutivos');

INSERT INTO tipos_unidad (codigo, nombre) VALUES
('TRACTO','Tracto'),('CAMION','Camión'),('SEMIRREMOLQUE','Semirremolque'),('BUS','Bus'),
('VOLQUETE','Volquete'),('CAMIONETA','Camioneta'),('MONTACARGA','Montacarga'),('MAQUINARIA','Maquinaria'),('OTRO','Otro');

INSERT INTO estados_neumatico (codigo, nombre, descripcion, permite_montaje, es_final, orden) VALUES
('DISPONIBLE','Disponible','Neumático libre y disponible para ser montado',1,0,1),
('MONTADO','Montado','Neumático actualmente instalado en una unidad',0,0,2),
('EN_MANTENIMIENTO','En mantenimiento','Neumático fuera de unidad y en proceso de mantenimiento',0,0,3),
('EN_REENCAUCHE','En reencauche','Neumático fuera de unidad y en proceso de reencauche',0,0,4),
('DESCARTADO','Descartado','Neumático con ciclo de vida finalizado',0,1,5);

INSERT INTO tipos_movimiento (codigo, nombre) VALUES
('INGRESO','Ingreso'),('MONTAJE','Montaje'),('DESMONTAJE','Desmontaje'),('ROTACION','Rotación'),
('TRANSFERENCIA','Transferencia'),('ENVIO_MANTENIMIENTO','Envío a mantenimiento'),
('RETORNO_MANTENIMIENTO','Retorno de mantenimiento'),('DESCARTE','Descarte');

INSERT INTO tipos_dano (codigo, nombre) VALUES
('CORTE','Corte'),('GRIETA','Grieta'),('PUNZON','Punzón'),('ABULTAMIENTO','Abultamiento'),
('DANO_FLANCO','Daño de flanco'),('DESGASTE_IRREGULAR','Desgaste irregular'),
('SEPARACION_BANDA','Separación de banda'),('OBJETO_INCRUSTADO','Objeto incrustado'),('OTRO','Otro');

INSERT INTO tipos_mantenimiento (codigo, nombre) VALUES
('REPARACION','Reparación'),('REENCAUCHE','Reencauche'),('REGRABADO','Regrabado'),('OTRO','Otro');

INSERT INTO estados_mantenimiento (codigo, nombre, orden, es_final) VALUES
('SOLICITADO','Solicitado',1,0),('ENVIADO','Enviado',2,0),('EN_PROCESO','En proceso',3,0),
('FINALIZADO','Finalizado',4,1),('CANCELADO','Cancelado',5,1);

INSERT INTO motivos_descarte (codigo, nombre) VALUES
('DESGASTE_NORMAL','Desgaste normal'),('DANO_IRREPARABLE','Daño irreparable'),('DANO_FLANCO','Daño de flanco'),
('SEPARACION_BANDA','Separación de banda'),('LIMITE_REENCAUCHES','Límite de reencauches'),
('ACCIDENTE','Accidente'),('OTRO','Otro');

INSERT INTO tipos_alerta (codigo, nombre) VALUES
('PROFUNDIDAD_CRITICA','Profundidad crítica'),('PRESION_BAJA','Presión baja'),('PRESION_ALTA','Presión alta'),
('DESGASTE_IRREGULAR','Desgaste irregular'),('DANO_DETECTADO','Daño detectado'),
('PROXIMO_REEMPLAZO','Próximo reemplazo'),('PROXIMO_REENCAUCHE','Próximo reencauche'),
('MANTENIMIENTO_RECOMENDADO','Mantenimiento recomendado');

INSERT INTO estados_alerta (codigo, nombre, orden, es_final) VALUES
('ABIERTA','Abierta',1,0),('EN_ATENCION','En atención',2,0),('ATENDIDA','Atendida',3,1),('DESCARTADA','Descartada',4,1);

INSERT INTO estados_oportunidad (codigo, nombre, orden, es_final) VALUES
('ABIERTA','Abierta',1,0),('EN_SEGUIMIENTO','En seguimiento',2,0),('COTIZADA','Cotizada',3,0),
('GANADA','Ganada',4,1),('PERDIDA','Perdida',5,1),('CANCELADA','Cancelada',6,1);

-- Q. REGLAS DE SERVICIO / TRANSACCION (IMPLEMENTAR EN BACKEND)
-- 1. Todo acceso operativo se filtra y valida por cliente_id autorizado.
-- 2. MONTAJE: SELECT ... FOR UPDATE de neumático y posición; validar estado, crear montaje,
--    movimiento, cambio de estado, histórico y auditoría en UNA transacción.
-- 3. DESMONTAJE: cerrar montaje y registrar movimiento + estado histórico en UNA transacción.
-- 4. ENVIO_MANTENIMIENTO: requiere neumático sin montaje activo. REENCAUCHE => EN_REENCAUCHE;
--    otros => EN_MANTENIMIENTO.
-- 5. RETORNO_MANTENIMIENTO: finalizar proceso, volver a DISPONIBLE y, si corresponde,
--    cerrar vida anterior y abrir una nueva.
-- 6. DESCARTE: requiere no tener montaje activo; registrar descarte, movimiento, cerrar vida,
--    pasar a DESCARTADO. DESCARTADO no admite nuevos montajes.
-- 7. INSPECCION FINALIZADA: queda bloqueada; correcciones posteriores son controladas y auditadas.
-- 8. TABLAS HISTORICAS: sin DELETE físico ni UPDATE destructivo.
-- 9. ARCHIVOS: entidad_tipo + entidad_id es relación polimórfica; el backend debe validar
--    existencia y pertenencia al mismo cliente antes de leer/escribir/eliminar.
-- 10. CONFIGURACIONES YA USADAS: no modificar significado histórico; desactivar y crear nueva versión.

-- Nota de alcance V0.1:
-- El modelo conoce si un neumático está libre/disponible pero aún no administra ubicación física
-- de almacén (almacén, patio, rack, posición). Si el servicio requiere inventario físico detallado,
-- se agregará un módulo almacenes/ubicaciones sin alterar el historial actual del neumático.
