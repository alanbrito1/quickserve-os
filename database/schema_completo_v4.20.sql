-- ============================================================
-- ClanDestino ERP v4.24 — Esquema completo de base de datos
-- Estado FINAL con todas las migraciones aplicadas (001-034)
-- Última actualización: 2026-06-06
-- ============================================================
-- ADVERTENCIA: Este archivo es de REFERENCIA, no es ejecutable
-- directamente. Para instalar desde cero usa database/schema.sql
-- (script completo v4.24 con todas las tablas, triggers y datos).
--
-- TABLAS EN schema.sql BASE (17):
--   logs_historial, usuarios, permisos_modulos, configuracion_negocio,
--   proveedores, insumos, productos, recetas, clientes, ventas,
--   venta_detalles, compras, compra_detalles, empleados,
--   nomina_liquidaciones, activos, pagos_fiado
--
-- TABLAS AGREGADAS POR MIGRACIONES:
--   002: login_intentos
--   007: registro_horas, parametros_laborales
--   012: costos_indirectos  (mig 013 elimina empleado_id, agrega clasificacion)
--   015: produccion_lotes
--   016: configuracion_app
--   025: combo_configs, combo_insumos
--   026: ajustes_stock
--   029: listas_sistema
--
-- COLUMNAS NOTABLES AGREGADAS POR MIGRACIONES:
--   003: venta_detalles.precio_lista | ventas.fecha_pago,es_combo,tipo_sandwich
--        nomina_liquidaciones.salud_empleado,pension_empleado,neto_pagado,pagado,fecha_pago_nomina
--        empleados.tipo_contrato,horas_semana,valor_hora | compras.lugar_compra
--   005: activos.numero_unidades,precio_unitario,serial,foto_url,estado_fisico,categoria_activo,responsable,garantia_hasta,notas
--   006: activos.fecha_inicio_uso | 006b: fix de la anterior
--   010: insumos.presentacion,cantidad_presentacion,precio_presentacion (+ triggers de costo automático)
--   011: proveedores.email,sitio_web | activos.proveedor_id (011b)
--   014: empleados.tipo_costo
--   015: venta_detalles.from_stock | productos.unidades_por_receta,stock_disponible,stock_minimo
--   017: triggers activos depreciacion con fecha_inicio_uso | 018: divisor 30.41666
--   025: venta_detalles.es_combo,combo_id
--   026: ventas.metodo_pago incluye 'obsequio'
--   027: productos.nombre2 (subtítulo visual del producto)
--   028: clientes.apellido,empresa
--   030: insumos.equiv_cantidad,equiv_unidad (equivalencia física para unidades no-físicas)
--   031: productos.categoria/tamano, insumos.unidad_medida/presentacion,
--        activos.categoria_activo — ENUM → VARCHAR para catálogos dinámicos
--   032: compra_detalles.presentacion,cantidad_presentacion,cant_presentaciones,precio_presentacion
--        → snapshot del empaque en cada compra (inmutable)
--   033: nomina_liquidaciones.valor_hora_snap,valor_proyecto_snap
--        → snapshot de tarifa/hora y valor de proyecto usados al liquidar (inmutable)
--   034: venta_detalles.nombre_snap,nombre2_snap — nombre del producto al vender (inmutable)
--        compra_detalles.nombre_snap,unidad_snap   — nombre e unidad al comprar (inmutable)
--        produccion_lotes.nombre_snap              — nombre al producir (inmutable)
--        pagos_fiado.saldo_anterior,saldo_posterior — deuda del cliente antes/después del abono
--
-- ──────────────────────────────────────────────────────────────
-- POLÍTICA DE SNAPSHOTS (INMUTABILIDAD EXTENDIDA)
-- ──────────────────────────────────────────────────────────────
-- Además de los PRECIOS, los siguientes NOMBRES Y CONTEXTOS se
-- guardan al momento de cada transacción para que cambios futuros
-- en los masters (productos, insumos, empleados, clientes) no
-- alteren la trazabilidad histórica:
--
--   venta_detalles.nombre_snap     = nombre del producto cuando se vendió
--   venta_detalles.nombre2_snap    = subtítulo del producto cuando se vendió
--   compra_detalles.nombre_snap    = nombre del insumo cuando se compró
--   compra_detalles.unidad_snap    = unidad de medida del insumo cuando se compró
--   compra_detalles.presentacion   = tipo de empaque (paca, frasco, etc.)
--   compra_detalles.cant_pres      = cuántos empaques se compraron
--   compra_detalles.precio_pres    = precio pagado por empaque
--   produccion_lotes.nombre_snap   = nombre del producto cuando se produjo
--   pagos_fiado.saldo_anterior     = deuda del cliente ANTES del abono
--   pagos_fiado.saldo_posterior    = deuda del cliente DESPUÉS del abono
--   nomina_liquidaciones.valor_hora_snap     = tarifa/hora usada al liquidar
--   nomina_liquidaciones.valor_proyecto_snap = valor del proyecto al liquidar
-- ============================================================

USE clandestinoERP;

-- ============================================================
-- TABLA: usuarios
-- Usuarios del sistema ERP. Rol controla acceso a Admin.
-- ============================================================
CREATE TABLE usuarios (
    id             INT          AUTO_INCREMENT PRIMARY KEY,
    nombre         VARCHAR(150) NOT NULL,
    email          VARCHAR(200) NOT NULL,
    password_hash  VARCHAR(255) NOT NULL,          -- bcrypt COST=12
    rol            ENUM('superadmin','admin','empleado') NOT NULL DEFAULT 'empleado',
    activo         TINYINT(1)   NOT NULL DEFAULT 1,
    ultimo_login   DATETIME     DEFAULT NULL,
    created_by     INT          DEFAULT NULL,
    updated_by     INT          DEFAULT NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: login_intentos
-- Rate-limiting para proteger contra fuerza bruta (migración 002).
-- ============================================================
CREATE TABLE login_intentos (
    id          INT          AUTO_INCREMENT PRIMARY KEY,
    email       VARCHAR(200) NOT NULL,
    ip_address  VARCHAR(45)  NOT NULL,
    exitoso     TINYINT(1)   NOT NULL DEFAULT 0,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_li_email_ip (email, ip_address, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: permisos_modulos
-- Permisos granulares por usuario y módulo.
-- Niveles: sin_acceso(0) → solo_ver(1) → solo_propios(2)
--           → editar_existentes(3) → admin_total(4)
-- ============================================================
CREATE TABLE permisos_modulos (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id    INT NOT NULL,
    modulo        ENUM('ventas','compras','inventario','nomina','productos',
                       'activos','reportes','proveedores','costos') NOT NULL,
    nivel_acceso  ENUM('sin_acceso','solo_ver','solo_propios',
                       'editar_existentes','admin_total') NOT NULL DEFAULT 'sin_acceso',
    UNIQUE KEY uk_usuario_modulo (usuario_id, modulo),
    CONSTRAINT fk_pm_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: configuracion_negocio
-- Parámetros numéricos del negocio (SMLMV, costos, producción).
-- ============================================================
CREATE TABLE configuracion_negocio (
    id          INT          AUTO_INCREMENT PRIMARY KEY,
    clave       VARCHAR(100) NOT NULL,
    valor       DECIMAL(15,4) DEFAULT 0,
    descripcion VARCHAR(300) DEFAULT NULL,
    categoria   VARCHAR(60)  DEFAULT NULL,
    updated_by  INT          DEFAULT NULL,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_cn_clave (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: configuracion_app
-- Parámetros de texto: tema visual, logos, tipografía (migración 016).
-- ============================================================
CREATE TABLE configuracion_app (
    clave       VARCHAR(100) NOT NULL PRIMARY KEY,
    valor       TEXT         DEFAULT NULL,
    updated_by  INT          DEFAULT NULL,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Claves: nombre_negocio, logo_url, logo_url_login, theme_brand,
--         theme_dark, theme_font, font_heading, theme_radius,
--         font_size_title, font_size_subtitle, font_size_body,
--         font_size_small, color_text, color_text_sec

-- ============================================================
-- TABLA: proveedores
-- Directorio de proveedores. FK en insumos, compras y activos.
-- ============================================================
CREATE TABLE proveedores (
    id          INT          AUTO_INCREMENT PRIMARY KEY,
    nombre      VARCHAR(200) NOT NULL,
    categoria   VARCHAR(60)  DEFAULT NULL,  -- de listas_sistema tipo='categoria_proveedor'
    contacto    VARCHAR(150) DEFAULT NULL,
    telefono    VARCHAR(30)  DEFAULT NULL,
    email       VARCHAR(200) DEFAULT NULL,  -- migración 011
    sitio_web   VARCHAR(300) DEFAULT NULL,  -- migración 011
    direccion   VARCHAR(300) DEFAULT NULL,
    notas       TEXT         DEFAULT NULL,
    activo      TINYINT(1)   NOT NULL DEFAULT 1,
    created_by  INT          DEFAULT NULL,
    updated_by  INT          DEFAULT NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: insumos
-- Materia prima e ingredientes. costo_actual se actualiza
-- automáticamente via triggers cuando cambia precio_presentacion.
-- ============================================================
CREATE TABLE insumos (
    id                    INT           AUTO_INCREMENT PRIMARY KEY,
    nombre                VARCHAR(200)  NOT NULL,
    categoria             VARCHAR(60)   DEFAULT NULL,   -- de listas_sistema tipo='categoria_insumo'
    -- Unidad básica de medida
    unidad_medida         VARCHAR(20)   NOT NULL DEFAULT 'unidad', -- de listas_sistema tipo='unidad_medida'
    -- Equivalencia física (migración 030): para unidades no-físicas como lonchas, latas, paquetes
    equiv_cantidad        DECIMAL(10,4) DEFAULT NULL,   -- cuántas unidades físicas contiene/pesa 1 unidad
    equiv_unidad          VARCHAR(10)   DEFAULT NULL,   -- unidad física: g, kg, ml, litro
    -- Presentación de compra (migración 010): frasco, paca, caja, etc.
    presentacion          VARCHAR(30)   DEFAULT NULL,   -- de listas_sistema tipo='presentacion'
    cantidad_presentacion DECIMAL(12,4) DEFAULT NULL,   -- cuántas unidades básicas por presentación
    precio_presentacion   DECIMAL(12,2) DEFAULT NULL,   -- precio de la presentación completa
    -- Costo calculado automáticamente = precio_presentacion / cantidad_presentacion
    costo_actual          DECIMAL(12,4) NOT NULL DEFAULT 0,
    -- Stock
    stock_actual          DECIMAL(12,4) NOT NULL DEFAULT 0,
    stock_seguridad       DECIMAL(12,4) NOT NULL DEFAULT 0,
    -- Relaciones
    proveedor_id          INT           DEFAULT NULL,
    notas                 TEXT          DEFAULT NULL,
    activo                TINYINT(1)    NOT NULL DEFAULT 1,
    created_by            INT           DEFAULT NULL,
    updated_by            INT           DEFAULT NULL,
    created_at            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_ins_proveedor FOREIGN KEY (proveedor_id) REFERENCES proveedores(id) ON DELETE SET NULL,
    INDEX idx_ins_activo  (activo),
    INDEX idx_ins_prov    (proveedor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- TRIGGERS: trg_insumos_costo_from_presentacion_insert/update
--   → calculan costo_actual = precio_presentacion / cantidad_presentacion

-- ============================================================
-- TABLA: productos
-- Carta de productos / sándwiches. costo_calculado se recalcula
-- automáticamente en CompraModel y RecetaModel.
-- ============================================================
CREATE TABLE productos (
    id                  INT           AUTO_INCREMENT PRIMARY KEY,
    nombre              VARCHAR(200)  NOT NULL,
    nombre2             VARCHAR(120)  DEFAULT NULL,  -- migración 027: subtítulo complementario (visual)
    categoria           VARCHAR(60)   NOT NULL DEFAULT 'sandwich',  -- de listas_sistema tipo='categoria_producto'
    tamano              VARCHAR(20)   NOT NULL DEFAULT 'unico',     -- de listas_sistema tipo='tamano_producto'
    precio_venta        DECIMAL(12,2) NOT NULL DEFAULT 0,
    costo_calculado     DECIMAL(12,4) DEFAULT 0,    -- recalculado por RecetaModel
    unidades_por_receta SMALLINT      UNSIGNED NOT NULL DEFAULT 1,   -- migración 015
    stock_disponible    INT           NOT NULL DEFAULT 0,            -- migración 015
    stock_minimo        INT           NOT NULL DEFAULT 0,            -- migración 015
    activo              TINYINT(1)    NOT NULL DEFAULT 1,
    created_by          INT           DEFAULT NULL,
    updated_by          INT           DEFAULT NULL,
    created_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_prod_activo   (activo),
    INDEX idx_prod_cat      (categoria)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: recetas
-- Ingredientes por producto. Una fila por insumo por producto.
-- UNIQUE evita duplicar el mismo insumo en la misma receta.
-- ============================================================
CREATE TABLE recetas (
    id                  INT           AUTO_INCREMENT PRIMARY KEY,
    producto_id         INT           NOT NULL,
    insumo_id           INT           NOT NULL,
    cantidad_requerida  DECIMAL(12,4) NOT NULL,  -- cantidad por TANDA (÷ unidades_por_receta = por sándwich)
    es_insumo_critico   TINYINT(1)    NOT NULL DEFAULT 0,  -- define capacidad del POS
    created_by          INT           DEFAULT NULL,
    updated_by          INT           DEFAULT NULL,
    UNIQUE KEY uk_receta_prod_ins (producto_id, insumo_id),
    CONSTRAINT fk_rec_producto FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE,
    CONSTRAINT fk_rec_insumo   FOREIGN KEY (insumo_id)   REFERENCES insumos(id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: combo_configs (migración 025)
-- Cada producto puede tener máximo una opción combo.
-- ============================================================
CREATE TABLE combo_configs (
    id               INT           AUTO_INCREMENT PRIMARY KEY,
    producto_id      INT           NOT NULL,
    nombre           VARCHAR(100)  NOT NULL DEFAULT 'Combo',
    precio_adicional DECIMAL(12,2) NOT NULL DEFAULT 0,
    activo           TINYINT(1)    NOT NULL DEFAULT 1,  -- soft-delete, conserva historial
    created_by       INT           DEFAULT NULL,
    created_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_combo_producto (producto_id),
    CONSTRAINT fk_cc_producto FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: combo_insumos (migración 025)
-- Insumos extra que se descuentan al vender un combo.
-- ============================================================
CREATE TABLE combo_insumos (
    id        INT           AUTO_INCREMENT PRIMARY KEY,
    combo_id  INT           NOT NULL,
    insumo_id INT           NOT NULL,
    cantidad  DECIMAL(10,4) NOT NULL,
    UNIQUE KEY uk_combo_insumo (combo_id, insumo_id),
    CONSTRAINT fk_ci_combo  FOREIGN KEY (combo_id)  REFERENCES combo_configs(id) ON DELETE CASCADE,
    CONSTRAINT fk_ci_insumo FOREIGN KEY (insumo_id) REFERENCES insumos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: clientes
-- Clientes del negocio. saldo_fiado se actualiza en VentaModel.
-- ============================================================
CREATE TABLE clientes (
    id          INT           AUTO_INCREMENT PRIMARY KEY,
    nombre      VARCHAR(100)  NOT NULL,
    apellido    VARCHAR(100)  DEFAULT NULL,  -- migración 028
    empresa     VARCHAR(150)  DEFAULT NULL,  -- migración 028
    telefono    VARCHAR(30)   DEFAULT NULL,
    saldo_fiado DECIMAL(12,2) NOT NULL DEFAULT 0,  -- deuda acumulada pendiente de cobro
    activo      TINYINT(1)    NOT NULL DEFAULT 1,
    created_by  INT           DEFAULT NULL,
    updated_by  INT           DEFAULT NULL,
    created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cli_activo (activo),
    INDEX idx_cli_fiado  (saldo_fiado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: ventas
-- Cabecera de cada transacción del POS.
-- INVARIANTE: total y metodo_pago no cambian históricos de ingreso.
-- ============================================================
CREATE TABLE ventas (
    id           BIGINT        AUTO_INCREMENT PRIMARY KEY,
    fecha_venta  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    cliente_id   INT           DEFAULT NULL,  -- NULL = venta de mostrador
    metodo_pago  ENUM('efectivo','nequi','daviplata','bancolombia','fiado','obsequio') NOT NULL,
    -- NOTA: 'obsequio' NO genera ingreso, solo descuenta stock (migración 026)
    total        DECIMAL(12,2) NOT NULL DEFAULT 0,
    estado       ENUM('completada','pendiente_pago','anulada') NOT NULL DEFAULT 'completada',
    notas        VARCHAR(500)  DEFAULT NULL,
    fecha_pago   DATETIME      DEFAULT NULL,  -- NULL en fiados no cobrados
    es_combo     TINYINT(1)    NOT NULL DEFAULT 0,  -- 1 si algún ítem es combo
    tipo_sandwich VARCHAR(100) DEFAULT NULL,  -- denormalizado, legado
    created_by   INT           DEFAULT NULL,
    updated_by   INT           DEFAULT NULL,
    created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_v_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE SET NULL,
    INDEX idx_v_fecha    (fecha_venta),
    INDEX idx_v_estado   (estado),
    INDEX idx_v_cliente  (cliente_id),
    INDEX idx_v_metodo   (metodo_pago)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: venta_detalles
-- Líneas de cada venta.
-- INVARIANTES:
--   precio_unitario → NUNCA se actualiza; es el precio al momento de la venta
--   nombre_snap     → NUNCA se actualiza; preserva el nombre aunque el producto
--                     sea renombrado o eliminado después
-- ============================================================
CREATE TABLE venta_detalles (
    id              BIGINT        AUTO_INCREMENT PRIMARY KEY,
    venta_id        BIGINT        NOT NULL,
    producto_id     INT           NOT NULL,
    cantidad        INT           NOT NULL DEFAULT 1,
    -- ── Snapshots de precio (inmutables) ─────────────────────────────────
    precio_unitario DECIMAL(12,2) NOT NULL,   -- precio cobrado al momento de la venta
    precio_lista    DECIMAL(10,2) DEFAULT NULL,-- precio sugerido del catálogo (migración 003)
    subtotal        DECIMAL(12,2) NOT NULL,
    -- ── Snapshots de nombre del producto (migración 034) ─────────────────
    nombre_snap     VARCHAR(200)  DEFAULT NULL, -- nombre del producto al momento de la venta
    nombre2_snap    VARCHAR(120)  DEFAULT NULL, -- subtítulo del producto al momento de la venta
    -- ── Trazabilidad de fuente de stock ──────────────────────────────────
    from_stock      TINYINT(1)   NOT NULL DEFAULT 0, -- 1=del stock terminado, 0=insumos directos
    -- ── Información de combo (migración 025) ─────────────────────────────
    es_combo        TINYINT(1)   NOT NULL DEFAULT 0, -- 1=vendido como combo
    combo_id        INT          DEFAULT NULL,        -- NULL en ventas históricas pre-025
    created_by      INT          DEFAULT NULL,
    CONSTRAINT fk_vd_venta    FOREIGN KEY (venta_id)    REFERENCES ventas(id)        ON DELETE CASCADE,
    CONSTRAINT fk_vd_producto FOREIGN KEY (producto_id) REFERENCES productos(id),
    CONSTRAINT fk_vd_combo    FOREIGN KEY (combo_id)    REFERENCES combo_configs(id) ON DELETE SET NULL,
    INDEX idx_vd_venta    (venta_id),
    INDEX idx_vd_producto (producto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: pagos_fiado
-- Abonos que los clientes hacen a su deuda.
-- ClienteModel::registrar_abono() reduce saldo_fiado y registra
-- el saldo antes y después de cada abono (migración 034).
-- INVARIANTE: saldo_anterior y saldo_posterior son snapshots
-- inmutables del momento exacto del pago.
-- ============================================================
CREATE TABLE pagos_fiado (
    id              INT           AUTO_INCREMENT PRIMARY KEY,
    cliente_id      INT           NOT NULL,
    monto           DECIMAL(12,2) NOT NULL,
    metodo_pago     VARCHAR(30)   DEFAULT 'efectivo',
    notas           VARCHAR(300)  DEFAULT NULL,
    -- ── Snapshots de saldo (migración 034) ───────────────────────────────
    saldo_anterior  DECIMAL(12,2) DEFAULT NULL, -- deuda del cliente ANTES del abono
    saldo_posterior DECIMAL(12,2) DEFAULT NULL, -- deuda del cliente DESPUÉS del abono
    -- Fórmula: saldo_posterior = saldo_anterior - monto
    -- (ambos son snapshots; no cambian si el saldo actual del cliente cambia)
    created_by      INT           DEFAULT NULL,
    created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pf_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id),
    INDEX idx_pf_cliente (cliente_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: compras
-- Cabecera de cada compra de insumos. Al crear una compra:
--   costo_actual del insumo se actualiza
--   stock_actual del insumo aumenta
--   costo_calculado de productos afectados se recalcula
-- ============================================================
CREATE TABLE compras (
    id           INT           AUTO_INCREMENT PRIMARY KEY,
    fecha_compra DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    proveedor_id INT           DEFAULT NULL,
    lugar_compra VARCHAR(200)  DEFAULT NULL,
    total        DECIMAL(14,2) NOT NULL DEFAULT 0,
    notas        TEXT          DEFAULT NULL,
    created_by   INT           DEFAULT NULL,
    updated_by   INT           DEFAULT NULL,
    created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_c_proveedor FOREIGN KEY (proveedor_id) REFERENCES proveedores(id) ON DELETE SET NULL,
    INDEX idx_c_fecha (fecha_compra)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: compra_detalles
-- Líneas de cada compra.
-- INVARIANTES:
--   precio_unitario → precio por unidad básica al momento de la compra
--   nombre_snap     → nombre del insumo al momento de la compra
--   presentacion/precio_presentacion → contexto del empaque al comprar
-- Todos estos campos son INMUTABLES después del INSERT.
-- ============================================================
CREATE TABLE compra_detalles (
    id              INT           AUTO_INCREMENT PRIMARY KEY,
    compra_id       INT           NOT NULL,
    insumo_id       INT           NOT NULL,
    cantidad        DECIMAL(12,4) NOT NULL,   -- total en unidades básicas
    precio_unitario DECIMAL(12,4) NOT NULL,   -- precio por unidad básica (snapshot)
    subtotal        DECIMAL(14,2) NOT NULL,
    -- ── Snapshots de nombre e unidad del insumo (migración 034) ──────────
    nombre_snap     VARCHAR(200)  DEFAULT NULL, -- nombre del insumo al comprar (snapshot)
    unidad_snap     VARCHAR(20)   DEFAULT NULL, -- unidad básica al comprar (snapshot)
    -- ── Snapshot del empaque / presentación (migración 032) ──────────────
    -- Contexto de cómo se realizó la compra (en qué formato de empaque).
    -- Permite saber "compré 2 pacas de 12 unidades a $29.000/paca"
    -- en lugar de solo "compré 24 unidades a $2.416/u".
    -- INVARIANTE: precio_presentacion / cantidad_presentacion = precio_unitario
    --             cant_presentaciones × cantidad_presentacion  = cantidad
    presentacion         VARCHAR(30)   DEFAULT NULL, -- tipo de empaque (paca, frasco, lata, etc.)
    cantidad_presentacion DECIMAL(12,4) DEFAULT NULL, -- unidades básicas por empaque
    cant_presentaciones   DECIMAL(10,4) DEFAULT NULL, -- cuántos empaques se compraron
    precio_presentacion   DECIMAL(12,2) DEFAULT NULL, -- precio pagado por empaque (snapshot)
    created_by            INT           DEFAULT NULL,
    CONSTRAINT fk_cd_compra FOREIGN KEY (compra_id) REFERENCES compras(id) ON DELETE CASCADE,
    CONSTRAINT fk_cd_insumo FOREIGN KEY (insumo_id) REFERENCES insumos(id),
    INDEX idx_cd_compra (compra_id),
    INDEX idx_cd_insumo (insumo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: produccion_lotes (migración 015)
-- Registro de cada tanda de producción.
-- INVARIANTES:
--   costo_unitario → snapshot del costo_calculado al producir
--   nombre_snap    → nombre del producto al momento de producir
-- Al crear: descuenta insumos y suma stock_disponible.
-- Al anular: revierte insumos y resta stock_disponible.
-- ============================================================
CREATE TABLE produccion_lotes (
    id               INT           AUTO_INCREMENT PRIMARY KEY,
    producto_id      INT           NOT NULL,
    fecha_produccion DATE          NOT NULL,
    cantidad         INT           NOT NULL,
    costo_unitario   DECIMAL(12,4) DEFAULT NULL,  -- snapshot de costo_calculado al producir (inmutable)
    nombre_snap      VARCHAR(200)  DEFAULT NULL,  -- nombre del producto al producir (migración 034, inmutable)
    notas            VARCHAR(300)  DEFAULT NULL,
    estado           ENUM('activo','anulado') NOT NULL DEFAULT 'activo',
    created_by       INT           DEFAULT NULL,
    updated_by       INT           DEFAULT NULL,
    created_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pl_producto FOREIGN KEY (producto_id) REFERENCES productos(id),
    INDEX idx_pl_producto_fecha (producto_id, fecha_produccion),
    INDEX idx_pl_fecha          (fecha_produccion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: ajustes_stock (migración 026)
-- Registra reducciones manuales de stock_disponible sin venta:
--   obsequio = regalo/muestra gratuita
--   desecho  = producto vencido o dañado
-- NOTA: sin FK en BD (workaround errno 121 en cPanel compartido).
-- La integridad referencial la garantiza ajuste_stock.php via SELECT FOR UPDATE.
-- ============================================================
CREATE TABLE ajustes_stock (
    id           INT          AUTO_INCREMENT PRIMARY KEY,
    producto_id  INT          NOT NULL,
    cantidad     INT          NOT NULL,
    tipo         ENUM('obsequio','desecho') NOT NULL,
    motivo       VARCHAR(300) DEFAULT NULL,
    fecha_ajuste DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by   INT          DEFAULT NULL,
    INDEX idx_as_producto (producto_id),
    INDEX idx_as_fecha    (fecha_ajuste)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: empleados
-- Personal del negocio. tipo_costo determina si su costo aparece
-- en "Nómina directa" (cocina) o "Nómina indirecta" (admin).
-- ============================================================
CREATE TABLE empleados (
    id                    INT           AUTO_INCREMENT PRIMARY KEY,
    nombre_completo       VARCHAR(200)  NOT NULL,
    documento_identidad   VARCHAR(30)   DEFAULT NULL,
    cargo                 VARCHAR(100)  DEFAULT NULL,
    tipo_contrato         ENUM('tiempo_completo','medio_tiempo','por_horas','por_servicio') NOT NULL DEFAULT 'tiempo_completo',
    pais_laboral          VARCHAR(60)   NOT NULL DEFAULT 'Colombia',
    salario_base          DECIMAL(12,2) NOT NULL DEFAULT 0,
    valor_hora            DECIMAL(10,4) DEFAULT NULL,   -- para contratos por_horas (manual o calculado)
    valor_proyecto        DECIMAL(12,2) DEFAULT NULL,   -- para contratos por_servicio
    horas_semana          DECIMAL(6,2)  DEFAULT NULL,   -- horas semanales configuradas (migración 009)
    periodo_horas_emp     ENUM('semana','mes') DEFAULT 'semana',  -- migración 009
    aplica_aux_transporte TINYINT(1)    NOT NULL DEFAULT 1,
    tipo_costo            ENUM('directo','indirecto') NOT NULL DEFAULT 'directo',  -- migración 014
    activo                TINYINT(1)    NOT NULL DEFAULT 1,
    created_by            INT           DEFAULT NULL,
    updated_by            INT           DEFAULT NULL,
    created_at            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_emp_documento (documento_identidad),
    INDEX idx_emp_activo    (activo),
    INDEX idx_emp_tipo_costo (tipo_costo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: registro_horas (migración 007)
-- Registro diario de horas trabajadas por empleado.
-- ============================================================
CREATE TABLE registro_horas (
    id           INT           AUTO_INCREMENT PRIMARY KEY,
    empleado_id  INT           NOT NULL,
    fecha        DATE          NOT NULL,
    horas        DECIMAL(5,2)  NOT NULL,
    tipo_hora    ENUM('ordinaria','recargo_nocturno','extra_diurna','extra_nocturna',
                      'festiva_ordinaria','extra_festiva_diurna','extra_festiva_nocturna')
                              NOT NULL DEFAULT 'ordinaria',
    es_festivo   TINYINT(1)   NOT NULL DEFAULT 0,
    descripcion  VARCHAR(300) DEFAULT NULL,
    created_by   INT          DEFAULT NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_rh_emp_fecha (empleado_id, fecha),
    CONSTRAINT fk_rh_empleado FOREIGN KEY (empleado_id) REFERENCES empleados(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: nomina_liquidaciones
-- SNAPSHOT INMUTABLE de cada liquidación mensual.
-- TODOS los campos de esta tabla son inmutables después del INSERT:
--   salario_base, cargas, provisiones, descuentos, costo_total
--   y los nuevos campos de migración 033 (valor_hora_snap, valor_proyecto_snap).
-- Si el empleado cambia de salario/tarifa, las liquidaciones
-- anteriores conservan los valores que se usaron en su momento.
-- Solo se permite UPDATE de: pagado, fecha_pago_nomina (marcar pagado).
-- ============================================================
CREATE TABLE nomina_liquidaciones (
    id                   INT           AUTO_INCREMENT PRIMARY KEY,
    empleado_id          INT           NOT NULL,
    periodo_mes          TINYINT       NOT NULL,  -- 1-12
    periodo_anio         SMALLINT      NOT NULL,

    -- ── Snapshot del contrato al momento de liquidar ──────────────────────
    tipo_contrato        VARCHAR(30)   DEFAULT NULL,  -- snapshot del tipo al liquidar
    descripcion_pago     VARCHAR(300)  DEFAULT NULL,  -- proyecto pagado (por_servicio)

    -- ── Snapshots de tarifa (migración 033) ───────────────────────────────
    -- CRÍTICOS para trazabilidad: si el empleado cambia su tarifa,
    -- las liquidaciones históricas conservan la tarifa que se usó.
    valor_hora_snap      DECIMAL(10,4) DEFAULT NULL,  -- tarifa/hora al momento de liquidar (por_horas)
    valor_proyecto_snap  DECIMAL(12,2) DEFAULT NULL,  -- valor del proyecto al liquidar (por_servicio)

    -- ── Horas y recargos (migración 007 + 008) ───────────────────────────
    horas_trabajadas     DECIMAL(7,2)  DEFAULT NULL,  -- total horas del período (por_horas)
    horas_ordinarias     DECIMAL(7,2)  DEFAULT 0,
    horas_extras         DECIMAL(7,2)  DEFAULT 0,
    valor_horas_extras   DECIMAL(12,2) DEFAULT 0,
    detalle_recargos     TEXT          DEFAULT NULL,   -- JSON: desglose por tipo de hora

    -- ── Componentes del costo (Colombia 2026) ────────────────────────────
    salario_base         DECIMAL(12,2) NOT NULL DEFAULT 0,
    aux_transporte       DECIMAL(10,2) NOT NULL DEFAULT 0,
    -- Cargas parafiscales (a cargo del empleador):
    salud_empleador      DECIMAL(10,2) NOT NULL DEFAULT 0,   -- 8.5%
    pension_empleador    DECIMAL(10,2) NOT NULL DEFAULT 0,   -- 12%
    arl                  DECIMAL(10,2) NOT NULL DEFAULT 0,   -- 0.522%
    caja_compensacion    DECIMAL(10,2) NOT NULL DEFAULT 0,   -- 4%
    icbf                 DECIMAL(10,2) NOT NULL DEFAULT 0,   -- 3%
    sena                 DECIMAL(10,2) NOT NULL DEFAULT 0,   -- 2%
    total_cargas         DECIMAL(10,2) NOT NULL DEFAULT 0,
    -- Provisiones (derechos del trabajador):
    prima                DECIMAL(10,2) NOT NULL DEFAULT 0,   -- 8.33%
    cesantias            DECIMAL(10,2) NOT NULL DEFAULT 0,   -- 8.33%
    intereses_cesantias  DECIMAL(10,2) NOT NULL DEFAULT 0,   -- 1%
    vacaciones           DECIMAL(10,2) NOT NULL DEFAULT 0,   -- 4.17%
    total_provisiones    DECIMAL(10,2) NOT NULL DEFAULT 0,
    -- Descuentos del empleado (no son costo del empleador):
    salud_empleado       DECIMAL(10,2) NOT NULL DEFAULT 0,   -- 4%
    pension_empleado     DECIMAL(10,2) NOT NULL DEFAULT 0,   -- 4%
    -- Neto que recibe el empleado: salario + aux - descuentos
    neto_pagado          DECIMAL(12,2) NOT NULL DEFAULT 0,
    -- Costo TOTAL real para el negocio = salario + cargas + provisiones
    costo_total_empleador DECIMAL(12,2) NOT NULL DEFAULT 0,

    -- ── Estado de pago (campos que SÍ se pueden actualizar) ──────────────
    pagado               TINYINT(1)    NOT NULL DEFAULT 0,
    fecha_pago_nomina    DATE          DEFAULT NULL,
    created_by           INT           DEFAULT NULL,
    created_at           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by           INT           DEFAULT NULL,

    UNIQUE KEY uk_nl_emp_periodo (empleado_id, periodo_mes, periodo_anio),
    CONSTRAINT fk_nl_empleado FOREIGN KEY (empleado_id) REFERENCES empleados(id),
    INDEX idx_nl_periodo (periodo_anio, periodo_mes),
    INDEX idx_nl_pagado  (pagado, periodo_anio, periodo_mes)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: activos
-- Equipos y bienes fijos. La depreciación se calcula via triggers:
--   depreciacion_mensual = costo_inicial / vida_util_meses
--   depreciacion_diaria  = depreciacion_mensual / 30.41666
-- REGLA: sin fecha_inicio_uso → depreciacion = 0 (migración 017).
-- valor_en_libros usa fecha_adquisicion (no fecha_inicio_uso).
-- ============================================================
CREATE TABLE activos (
    id                  INT           AUTO_INCREMENT PRIMARY KEY,
    nombre              VARCHAR(200)  NOT NULL,
    descripcion         TEXT          DEFAULT NULL,
    categoria_activo    VARCHAR(60)   DEFAULT NULL,   -- de listas_sistema tipo='categoria_activo'
    numero_unidades     INT           NOT NULL DEFAULT 1,
    precio_unitario     DECIMAL(12,2) NOT NULL DEFAULT 0,
    costo_inicial       DECIMAL(14,2) NOT NULL DEFAULT 0,  -- numero_unidades × precio_unitario
    fecha_adquisicion   DATE          NOT NULL,
    fecha_inicio_uso    DATE          DEFAULT NULL,  -- NULL → en_espera, sin depreciación operativa
    garantia_hasta      DATE          DEFAULT NULL,
    vida_util_meses     SMALLINT      UNSIGNED NOT NULL DEFAULT 60,
    depreciacion_mensual DECIMAL(12,4) NOT NULL DEFAULT 0,
    depreciacion_diaria  DECIMAL(12,6) NOT NULL DEFAULT 0,
    estado_vida         ENUM('en_espera','nuevo','medio','critico','depreciado') NOT NULL DEFAULT 'en_espera',
    estado_fisico       ENUM('excelente','bueno','regular','malo') DEFAULT NULL,
    serial              VARCHAR(100)  DEFAULT NULL,
    lugar_compra        VARCHAR(200)  DEFAULT NULL,
    responsable         VARCHAR(150)  DEFAULT NULL,
    foto_url            VARCHAR(300)  DEFAULT NULL,
    proveedor_id        INT           DEFAULT NULL,
    activo              TINYINT(1)    NOT NULL DEFAULT 1,
    created_by          INT           DEFAULT NULL,
    updated_by          INT           DEFAULT NULL,
    created_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_act_proveedor FOREIGN KEY (proveedor_id) REFERENCES proveedores(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- TRIGGERS:
--   trg_activos_deprec_insert: calcula depreciacion_mensual/diaria al crear activo
--   trg_activos_deprec_update: recalcula si cambia costo_inicial, vida_util o fecha_inicio_uso
-- DIVISOR DEPRECIACIÓN: 30.41666 (= 365/12) — migración 018

-- ============================================================
-- TABLA: costos_indirectos
-- Costos fijos y variables del negocio. Cada fila es UN PERÍODO
-- de vigencia. Cuando un costo cambia de valor → crear nueva fila
-- con fecha_inicio actualizada, no editar la existente.
-- ============================================================
CREATE TABLE costos_indirectos (
    id             INT           AUTO_INCREMENT PRIMARY KEY,
    nombre         VARCHAR(200)  NOT NULL,
    categoria      VARCHAR(60)   DEFAULT NULL,   -- de listas_sistema tipo='categoria_costo'
    descripcion    TEXT          DEFAULT NULL,
    clasificacion  ENUM('directo','indirecto') NOT NULL DEFAULT 'indirecto',  -- migración 013
    tipo           ENUM('fijo','variable')     NOT NULL DEFAULT 'fijo',
    frecuencia     ENUM('mensual','bimestral','trimestral','semestral','anual') NOT NULL DEFAULT 'mensual',
    valor          DECIMAL(14,2) NOT NULL DEFAULT 0,
    fecha_inicio   DATE          NOT NULL,
    fecha_fin      DATE          DEFAULT NULL,   -- NULL = vigente actualmente
    activo         TINYINT(1)    NOT NULL DEFAULT 1,
    created_by     INT           DEFAULT NULL,
    updated_by     INT           DEFAULT NULL,
    created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ci_activo (activo, fecha_inicio, fecha_fin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: listas_sistema (migración 029)
-- Catálogos configurables desde Admin → Catálogos.
-- Reemplaza arrays PHP hardcodeados. Permite agregar opciones
-- a los selects sin tocar código.
-- Tipos disponibles:
--   presentacion       → envases de insumos (frasco, paca, caja…)
--   unidad_medida      → unidades básicas (kg, g, litro, ml…)
--   categoria_insumo   → tipos de ingrediente (proteína, lácteo…)
--   categoria_producto → tipos de menú (sandwich, combo, bebida…)
--   tamano_producto    → tallas de producto (XL, L, unico…)
--   categoria_activo   → tipos de equipo (equipo_cocina, mobiliario…)
--   categoria_costo    → tipos de costo (arriendo, servicios…)
--   categoria_proveedor→ tipos de proveedor (plaza, retail…)
-- ============================================================
CREATE TABLE listas_sistema (
    id          INT          AUTO_INCREMENT PRIMARY KEY,
    tipo        VARCHAR(60)  NOT NULL,
    valor       VARCHAR(100) NOT NULL,    -- clave almacenada en BD (inmutable)
    etiqueta    VARCHAR(150) NOT NULL,    -- texto visible al usuario (editable)
    orden       SMALLINT     NOT NULL DEFAULT 0,
    activo      TINYINT(1)   NOT NULL DEFAULT 1,
    created_by  INT          DEFAULT NULL,
    UNIQUE KEY  uk_lista_tipo_valor (tipo, valor),
    INDEX       idx_lista_tipo (tipo, activo, orden)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: logs_historial
-- Auditoría de cambios. Trigger-based + manual en PHP.
-- ============================================================
CREATE TABLE logs_historial (
    id              BIGINT       AUTO_INCREMENT PRIMARY KEY,
    tabla           VARCHAR(60)  NOT NULL,
    registro_id     BIGINT       DEFAULT NULL,
    campo           VARCHAR(100) DEFAULT NULL,
    valor_anterior  TEXT         DEFAULT NULL,
    valor_nuevo     TEXT         DEFAULT NULL,
    accion          ENUM('INSERT','UPDATE','DELETE') NOT NULL DEFAULT 'UPDATE',
    usuario_id      INT          DEFAULT NULL,
    ip_address      VARCHAR(45)  DEFAULT NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_lh_tabla_reg  (tabla, registro_id),
    INDEX idx_lh_usuario    (usuario_id),
    INDEX idx_lh_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: parametros_laborales (migración 007)
-- Parámetros de nómina configurables por país y tipo de contrato.
-- Clave: cambio de ley → solo actualizar la fila correspondiente.
-- ============================================================
CREATE TABLE parametros_laborales (
    id               INT           AUTO_INCREMENT PRIMARY KEY,
    pais             VARCHAR(60)   NOT NULL DEFAULT 'Colombia',
    clave            VARCHAR(100)  NOT NULL,
    valor            DECIMAL(15,6) NOT NULL,
    tipo             ENUM('porcentaje','monto_fijo','factor') NOT NULL,
    aplica_a         ENUM('empleador','empleado','ambos') NOT NULL DEFAULT 'empleador',
    descripcion      VARCHAR(300)  DEFAULT NULL,
    categoria        VARCHAR(60)   DEFAULT NULL,
    aplica_contratos VARCHAR(200)  DEFAULT NULL,  -- contratos que aplican
    updated_by       INT           DEFAULT NULL,
    updated_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_pl_pais_clave (pais, clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- RESUMEN DE RELACIONES CLAVE
-- ============================================================
/*
Flujo principal de negocio:
  proveedores → insumos → recetas ↘
                                    productos → produccion_lotes → stock_disponible
                                              ↗                                   ↘
                            combo_configs                                          ventas → venta_detalles
                             combo_insumos ↗                                              ↗
  clientes → ventas (fiado)                    insumos ← ventas on-demand (from_stock=0)
           → pagos_fiado

Catálogos configurables (Admin → Catálogos):
  listas_sistema → insumos.presentacion, unidad_medida, categoria
  listas_sistema → productos.categoria, tamano
  listas_sistema → activos.categoria_activo
  listas_sistema → costos_indirectos.categoria
  listas_sistema → proveedores.categoria

Costos de productos:
  insumos.costo_actual × recetas.cantidad_requerida → productos.costo_calculado
  + CostoIndirectoModel::total_mensual_activo() / produccion_estimada → costo_fijo_u
  + activos.depreciacion_diaria / (produccion_mensual/21.75) → costo_deprec_u
  + nomina_liquidaciones.costo_total_empleador / produccion_mensual → costo_rh_u
  = costo_total_u
  margen = precio_venta - costo_total_u

Ajustes de stock sin venta:
  ajustes_stock (tipo=obsequio/desecho) → descuenta productos.stock_disponible
  Contraste: ventas con metodo_pago=obsequio TAMBIÉN descuentan stock pero pasan por el POS

SNAPSHOTS INMUTABLES (política de trazabilidad extendida):
  -- PRECIOS:
  venta_detalles.precio_unitario       = precio cobrado al momento de la venta
  compra_detalles.precio_unitario      = precio pagado al momento de la compra
  compra_detalles.precio_presentacion  = precio por empaque al comprar (migr. 032)
  produccion_lotes.costo_unitario      = costo_calculado al momento de producir
  nomina_liquidaciones.salario_base    = salario base al momento de liquidar
  nomina_liquidaciones.costo_total_*   = todos los montos al momento de liquidar
  nomina_liquidaciones.valor_hora_snap = tarifa/hora usada al liquidar (migr. 033)
  nomina_liquidaciones.valor_proyecto_snap = valor proyecto al liquidar (migr. 033)

  -- NOMBRES (para preservar identidad cuando se renombran):
  venta_detalles.nombre_snap          = nombre del producto cuando se vendió (migr. 034)
  venta_detalles.nombre2_snap         = subtítulo al vender (migr. 034)
  compra_detalles.nombre_snap         = nombre del insumo cuando se compró (migr. 034)
  compra_detalles.unidad_snap         = unidad de medida al comprar (migr. 034)
  produccion_lotes.nombre_snap        = nombre del producto cuando se produjo (migr. 034)

  -- CONTEXTO FINANCIERO:
  pagos_fiado.saldo_anterior          = deuda del cliente ANTES del abono (migr. 034)
  pagos_fiado.saldo_posterior         = deuda del cliente DESPUÉS del abono (migr. 034)
  compra_detalles.cant_presentaciones = cuántos empaques se compraron (migr. 032)
  compra_detalles.cantidad_presentacion = unidades por empaque (migr. 032)

Cambios en activos → se auditan en logs_historial (trigger trg_activos_deprec_update):
  Cada vez que cambia costo_inicial o vida_util_meses, el trigger registra
  valor_anterior y valor_nuevo en logs_historial para trazabilidad completa.
*/
