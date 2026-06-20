-- ============================================================
-- ClanDestino ERP v4.80 — Esquema de instalación completo
-- Compatible: MySQL 5.7+ / MariaDB 10.3+
-- Última actualización: 2026-06-08
-- Incluye migración 035: variantes de tamaño (producto_variantes)
--              mig. 036: recetas.es_base (ingrediente que no escala con factor_receta)
--              mig. 037: tabla turnos_caja
--              mig. 038: columnas descuento_pct / descuento_valor en ventas
--              mig. 039: tabla insumo_presentaciones + presentacion_id en compra_detalles
-- ============================================================
-- INSTRUCCIONES DE INSTALACIÓN (instalación desde cero):
--   1. Crear base de datos: CREATE DATABASE clandestinoERP
--      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
--   2. Editar la línea "USE clandestinoERP;" con el nombre real de tu DB.
--   3. Ejecutar este script completo en phpMyAdmin > SQL.
--   4. Cambiar la contraseña del superadmin EN EL PRIMER LOGIN.
--
-- NOTA: Este script incluye TODAS las tablas, triggers y datos
--       iniciales. No es necesario ejecutar las migraciones 002-038
--       para una instalación nueva.
--
-- TABLAS (30): logs_historial, login_intentos, usuarios,
--   permisos_modulos, configuracion_negocio, configuracion_app,
--   listas_sistema, proveedores, insumos, productos, recetas,
--   combo_configs, combo_insumos, clientes, ventas, venta_detalles,
--   pagos_fiado, compras, compra_detalles, empleados, registro_horas,
--   parametros_laborales, nomina_liquidaciones, activos,
--   costos_indirectos, produccion_lotes, ajustes_stock,
--   producto_variantes (mig. 035), turnos_caja (mig. 037),
--   insumo_presentaciones (mig. 039)
--
-- TRIGGERS (9):
--   trg_config_negocio_audit, trg_insumos_costo_from_presentacion_insert,
--   trg_insumos_costo_from_presentacion_update, trg_insumos_audit,
--   trg_productos_audit, trg_ventas_audit, trg_empleados_audit,
--   trg_activos_deprec_insert, trg_activos_deprec_update
-- ============================================================

USE `clandestinoERP`;

SET NAMES utf8mb4;
SET time_zone = '-05:00';
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- DROP TABLES (orden inverso a FKs para evitar errores)
-- ============================================================
DROP TABLE IF EXISTS `ajustes_stock`;
DROP TABLE IF EXISTS `produccion_lotes`;
DROP TABLE IF EXISTS `producto_variantes`;
DROP TABLE IF EXISTS `insumo_presentaciones`;
DROP TABLE IF EXISTS `costos_indirectos`;
DROP TABLE IF EXISTS `activos`;
DROP TABLE IF EXISTS `nomina_liquidaciones`;
DROP TABLE IF EXISTS `parametros_laborales`;
DROP TABLE IF EXISTS `registro_horas`;
DROP TABLE IF EXISTS `empleados`;
DROP TABLE IF EXISTS `compra_detalles`;
DROP TABLE IF EXISTS `compras`;
DROP TABLE IF EXISTS `pagos_fiado`;
DROP TABLE IF EXISTS `venta_detalles`;
DROP TABLE IF EXISTS `ventas`;
DROP TABLE IF EXISTS `clientes`;
DROP TABLE IF EXISTS `combo_insumos`;
DROP TABLE IF EXISTS `combo_configs`;
DROP TABLE IF EXISTS `recetas`;
DROP TABLE IF EXISTS `productos`;
DROP TABLE IF EXISTS `insumos`;
DROP TABLE IF EXISTS `proveedores`;
DROP TABLE IF EXISTS `turnos_caja`;
DROP TABLE IF EXISTS `listas_sistema`;
DROP TABLE IF EXISTS `configuracion_app`;
DROP TABLE IF EXISTS `configuracion_negocio`;
DROP TABLE IF EXISTS `permisos_modulos`;
DROP TABLE IF EXISTS `login_intentos`;
DROP TABLE IF EXISTS `usuarios`;
DROP TABLE IF EXISTS `logs_historial`;

-- ============================================================
-- TABLA: logs_historial
-- Auditoría de cambios. NUNCA truncar ni borrar registros.
-- NOTA: columna de timestamp se llama fecha_cambio (no created_at).
-- ============================================================
CREATE TABLE `logs_historial` (
    `id`             BIGINT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    `tabla`          VARCHAR(64)      NOT NULL,
    `registro_id`    BIGINT UNSIGNED  NOT NULL,
    `campo`          VARCHAR(64)      NOT NULL,
    `valor_anterior` TEXT             DEFAULT NULL,
    `valor_nuevo`    TEXT             DEFAULT NULL,
    `accion`         ENUM('INSERT','UPDATE','DELETE') NOT NULL,
    `usuario_id`     INT UNSIGNED     DEFAULT NULL,
    `ip_address`     VARCHAR(45)      DEFAULT NULL,
    `fecha_cambio`   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_tabla_registro`  (`tabla`, `registro_id`),
    INDEX `idx_usuario_fecha`   (`usuario_id`, `fecha_cambio`),
    INDEX `idx_fecha`           (`fecha_cambio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Auditoría completa. Trigger-based + manual PHP. NO BORRAR FILAS.';


-- ============================================================
-- TABLA: login_intentos
-- Rate-limiting para protección contra fuerza bruta.
-- ============================================================
CREATE TABLE `login_intentos` (
    `id`         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `email`      VARCHAR(150)    NOT NULL,
    `ip_address` VARCHAR(45)     NOT NULL,
    `exitoso`    TINYINT(1)      NOT NULL DEFAULT 0,
    `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_li_email_ip` (`email`, `ip_address`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- TABLA: usuarios
-- Usuarios del sistema. Rol controla acceso a Admin.
-- ============================================================
CREATE TABLE `usuarios` (
    `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `nombre`        VARCHAR(100) NOT NULL,
    `email`         VARCHAR(150) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,    -- bcrypt COST=12
    `rol`           ENUM('superadmin','admin','empleado') NOT NULL DEFAULT 'empleado',
    `activo`        TINYINT(1)   NOT NULL DEFAULT 1,
    `ultimo_login`  DATETIME     DEFAULT NULL,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by`    INT UNSIGNED DEFAULT NULL,
    `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by`    INT UNSIGNED DEFAULT NULL,
    UNIQUE KEY `uk_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- TABLA: permisos_modulos
-- Permisos granulares por usuario y módulo.
-- Niveles: sin_acceso → solo_ver → solo_propios
--           → editar_existentes → admin_total
-- ============================================================
CREATE TABLE `permisos_modulos` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `usuario_id`   INT UNSIGNED NOT NULL,
    `modulo`       ENUM('ventas','compras','inventario','nomina','productos',
                        'activos','reportes','proveedores','costos') NOT NULL,
    `nivel_acceso` ENUM('sin_acceso','solo_ver','solo_propios',
                        'editar_existentes','admin_total') NOT NULL DEFAULT 'sin_acceso',
    `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by`   INT UNSIGNED DEFAULT NULL,
    `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by`   INT UNSIGNED DEFAULT NULL,
    UNIQUE KEY `uk_usuario_modulo` (`usuario_id`, `modulo`),
    CONSTRAINT `fk_pm_usuario` FOREIGN KEY (`usuario_id`)
        REFERENCES `usuarios`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- TABLA: configuracion_negocio
-- Parámetros numéricos del negocio (costos, producción, nómina).
-- Todos los cambios quedan en logs_historial via trigger.
-- ============================================================
CREATE TABLE `configuracion_negocio` (
    `id`          INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    `clave`       VARCHAR(100)  NOT NULL,
    `valor`       DECIMAL(15,4) NOT NULL,
    `descripcion` VARCHAR(255)  NOT NULL,
    `categoria`   ENUM('nomina','costos','produccion','impuestos') NOT NULL,
    `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by`  INT UNSIGNED  DEFAULT NULL,
    `updated_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by`  INT UNSIGNED  DEFAULT NULL,
    UNIQUE KEY `uk_cn_clave` (`clave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- TABLA: configuracion_app
-- Parámetros de texto: tema visual, logos, tipografía.
-- Clave primaria por nombre de clave (key-value store).
-- ============================================================
CREATE TABLE `configuracion_app` (
    `clave`       VARCHAR(100) NOT NULL,
    `valor`       TEXT         NOT NULL DEFAULT '',
    `descripcion` VARCHAR(255) NOT NULL DEFAULT '',
    `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by`  INT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (`clave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Configuración visual: tema, logos, tipografía, nombre del negocio.';


-- ============================================================
-- TABLA: listas_sistema
-- Catálogos configurables desde Admin → Catálogos.
-- Tipos: presentacion, unidad_medida, categoria_insumo,
--        categoria_producto, tamano_producto, categoria_activo,
--        categoria_costo, categoria_proveedor
-- ============================================================
CREATE TABLE `listas_sistema` (
    `id`         INT          AUTO_INCREMENT PRIMARY KEY,
    `tipo`       VARCHAR(60)  NOT NULL,
    `valor`      VARCHAR(100) NOT NULL,    -- clave almacenada (inmutable)
    `etiqueta`   VARCHAR(150) NOT NULL,    -- texto visible al usuario
    `orden`      SMALLINT     NOT NULL DEFAULT 0,
    `activo`     TINYINT(1)   NOT NULL DEFAULT 1,
    `created_by` INT          DEFAULT NULL,
    UNIQUE KEY `uk_lista_tipo_valor` (`tipo`, `valor`),
    INDEX `idx_lista_tipo` (`tipo`, `activo`, `orden`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- TABLA: proveedores
-- Directorio de proveedores. FK en insumos, compras y activos.
-- ============================================================
CREATE TABLE `proveedores` (
    `id`         INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    `nombre`     VARCHAR(150)  NOT NULL,
    `categoria`  VARCHAR(80)   DEFAULT NULL,   -- listas_sistema tipo='categoria_proveedor'
    `contacto`   VARCHAR(100)  DEFAULT NULL,
    `telefono`   VARCHAR(20)   DEFAULT NULL,
    `email`      VARCHAR(150)  DEFAULT NULL,
    `sitio_web`  VARCHAR(200)  DEFAULT NULL,
    `direccion`  VARCHAR(200)  DEFAULT NULL,
    `notas`      TEXT          DEFAULT NULL,
    `activo`     TINYINT(1)    NOT NULL DEFAULT 1,
    `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by` INT UNSIGNED  DEFAULT NULL,
    `updated_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by` INT UNSIGNED  DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- TABLA: insumos
-- Materia prima e ingredientes. costo_actual se calcula
-- automáticamente via triggers desde precio_presentacion.
-- ============================================================
CREATE TABLE `insumos` (
    `id`                    INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    `nombre`                VARCHAR(150)  NOT NULL,
    -- Presentación de compra
    `presentacion`          VARCHAR(30)   DEFAULT NULL,   -- listas_sistema tipo='presentacion'
    `cantidad_presentacion` DECIMAL(12,4) DEFAULT NULL,   -- unidades básicas por presentación
    `precio_presentacion`   DECIMAL(12,2) DEFAULT NULL,   -- precio de la presentación completa
    `categoria`             VARCHAR(80)   DEFAULT NULL,   -- listas_sistema tipo='categoria_insumo'
    `unidad_medida`         VARCHAR(20)   NOT NULL DEFAULT 'unidad', -- listas_sistema tipo='unidad_medida'
    -- Equivalencia física (mig. 030): para unidades no-físicas (lata, loncha, paquete…)
    `equiv_unidad`          VARCHAR(10)   DEFAULT NULL,   -- unidad física: g, kg, ml, litro
    -- Costo calculado = precio_presentacion / cantidad_presentacion (via trigger)
    `costo_actual`          DECIMAL(12,4) NOT NULL DEFAULT 0,
    -- Stock
    `stock_actual`          DECIMAL(12,4) NOT NULL DEFAULT 0,
    `stock_seguridad`       DECIMAL(12,4) NOT NULL DEFAULT 0,
    -- Relaciones
    `proveedor_id`          INT UNSIGNED  DEFAULT NULL,
    `notas`                 TEXT          DEFAULT NULL,
    `activo`                TINYINT(1)    NOT NULL DEFAULT 1,
    `created_at`            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by`            INT UNSIGNED  DEFAULT NULL,
    `updated_at`            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by`            INT UNSIGNED  DEFAULT NULL,
    -- Equivalencia física (al final por orden histórico de migración 030)
    `equiv_cantidad`        DECIMAL(10,4) DEFAULT NULL,   -- ej: 1 lata = 170 g → 170
    CONSTRAINT `fk_ins_proveedor` FOREIGN KEY (`proveedor_id`)
        REFERENCES `proveedores`(`id`) ON DELETE SET NULL,
    INDEX `idx_ins_activo`  (`activo`),
    INDEX `idx_ins_prov`    (`proveedor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Inventario maestro. Cambios de costo disparan recálculo de productos.';


-- ============================================================
-- TABLA: productos
-- Carta de sándwiches y combos. costo_calculado se recalcula
-- automáticamente en CompraModel y RecetaModel.
-- ============================================================
CREATE TABLE `productos` (
    `id`                  INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    `nombre`              VARCHAR(150)  NOT NULL,
    `nombre2`             VARCHAR(120)  DEFAULT NULL,  -- subtítulo visual (mig. 027)
    `descripcion`         TEXT          DEFAULT NULL,
    `categoria`           VARCHAR(60)   NOT NULL DEFAULT 'sandwich',  -- listas_sistema tipo='categoria_producto'
    `tamano`              VARCHAR(20)   NOT NULL DEFAULT 'unico',      -- listas_sistema tipo='tamano_producto'
    `precio_venta`        DECIMAL(10,2) NOT NULL,
    `costo_calculado`     DECIMAL(10,4) DEFAULT NULL,   -- recalculado por RecetaModel
    `unidades_por_receta` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    `stock_disponible`    INT           NOT NULL DEFAULT 0,
    `stock_minimo`        INT           NOT NULL DEFAULT 0,
    `activo`              TINYINT(1)    NOT NULL DEFAULT 1,
    `created_at`          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by`          INT UNSIGNED  DEFAULT NULL,
    `updated_at`          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by`          INT UNSIGNED  DEFAULT NULL,
    INDEX `idx_prod_activo`  (`activo`),
    INDEX `idx_prod_cat`     (`categoria`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- TABLA: recetas
-- Ingredientes por producto. Determina costeo y capacidad de producción.
-- ============================================================
CREATE TABLE `recetas` (
    `id`                 INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    `producto_id`        INT UNSIGNED  NOT NULL,
    `insumo_id`          INT UNSIGNED  NOT NULL,
    `cantidad_requerida` DECIMAL(12,6) NOT NULL,  -- cantidad por TANDA (÷ unidades_por_receta = por unidad)
    `es_insumo_critico`  TINYINT(1)    NOT NULL DEFAULT 0,  -- define capacidad máxima del POS
    `es_base`            TINYINT(1)    NOT NULL DEFAULT 0,  -- cantidad fija, no escala con factor_receta (mig. 036)
    `created_at`         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by`         INT UNSIGNED  DEFAULT NULL,
    `updated_at`         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by`         INT UNSIGNED  DEFAULT NULL,
    UNIQUE KEY `uk_receta_prod_ins` (`producto_id`, `insumo_id`),
    CONSTRAINT `fk_rec_producto` FOREIGN KEY (`producto_id`)
        REFERENCES `productos`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rec_insumo` FOREIGN KEY (`insumo_id`)
        REFERENCES `insumos`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- TABLA: combo_configs (mig. 025)
-- Configuración del "combo" opcional de un producto.
-- Un producto puede tener máximo un combo.
-- ============================================================
CREATE TABLE `combo_configs` (
    `id`               INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    `producto_id`      INT UNSIGNED  NOT NULL,
    `nombre`           VARCHAR(100)  NOT NULL DEFAULT 'Combo',
    `precio_adicional` DECIMAL(10,2) NOT NULL DEFAULT 0,
    `activo`           TINYINT(1)    NOT NULL DEFAULT 1,
    `created_by`       INT UNSIGNED  DEFAULT NULL,
    `created_at`       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_combo_producto` (`producto_id`),
    CONSTRAINT `fk_cc_producto` FOREIGN KEY (`producto_id`)
        REFERENCES `productos`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- TABLA: combo_insumos (mig. 025)
-- Insumos extra que se descuentan al vender un combo.
-- ============================================================
CREATE TABLE `combo_insumos` (
    `id`        INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    `combo_id`  INT UNSIGNED  NOT NULL,
    `insumo_id` INT UNSIGNED  NOT NULL,
    `cantidad`  DECIMAL(10,4) NOT NULL DEFAULT 1,
    UNIQUE KEY `uk_combo_insumo` (`combo_id`, `insumo_id`),
    CONSTRAINT `fk_ci_combo`  FOREIGN KEY (`combo_id`)
        REFERENCES `combo_configs`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ci_insumo` FOREIGN KEY (`insumo_id`)
        REFERENCES `insumos`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- TABLA: producto_variantes (mig. 035)
-- Variantes de tamaño de un producto (XL, Regular, Familiar…).
-- Cada variante tiene precio propio y factor de escala de receta.
-- SIN FK sobre producto_id: errno 121 en cPanel compartido.
-- ============================================================
CREATE TABLE `producto_variantes` (
    `id`            INT AUTO_INCREMENT PRIMARY KEY,
    `producto_id`   INT NOT NULL,
    `etiqueta`      VARCHAR(80)   NOT NULL,
    `precio_venta`  DECIMAL(12,2) NOT NULL,
    `factor_receta` DECIMAL(5,3)  NOT NULL DEFAULT 1.000,
    `activo`        TINYINT(1)    NOT NULL DEFAULT 1,
    `created_by`    INT           DEFAULT NULL,
    `created_at`    DATETIME      DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_pv_producto` (`producto_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- TABLA: clientes
-- Clientes del negocio. saldo_fiado se actualiza en VentaModel.
-- ============================================================
CREATE TABLE `clientes` (
    `id`          INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    `nombre`      VARCHAR(150)  NOT NULL,
    `apellido`    VARCHAR(100)  DEFAULT NULL,   -- mig. 028
    `empresa`     VARCHAR(150)  DEFAULT NULL,   -- mig. 028
    `telefono`    VARCHAR(20)   DEFAULT NULL,
    `saldo_fiado` DECIMAL(12,2) NOT NULL DEFAULT 0,  -- deuda acumulada pendiente
    `activo`      TINYINT(1)    NOT NULL DEFAULT 1,
    `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by`  INT UNSIGNED  DEFAULT NULL,
    `updated_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by`  INT UNSIGNED  DEFAULT NULL,
    INDEX `idx_cli_activo` (`activo`),
    INDEX `idx_cli_fiado`  (`saldo_fiado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- TABLA: ventas
-- Cabecera de cada transacción del POS.
-- INVARIANTE: total y metodo_pago no cambian históricos de ingreso.
-- ============================================================
CREATE TABLE `ventas` (
    `id`           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `fecha_venta`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `fecha_pago`   DATETIME        DEFAULT NULL,   -- NULL en fiados no cobrados
    -- Método con que se cobró una venta fiada (mig. 042); NULL = no aplica o aún sin cobrar.
    -- metodo_pago permanece en 'fiado' (origen); metodo_cobro guarda con qué se saldó.
    `metodo_cobro` ENUM('efectivo','nequi','daviplata','bancolombia') DEFAULT NULL,
    `cliente_id`   INT UNSIGNED    DEFAULT NULL,   -- NULL = venta mostrador
    `metodo_pago`  ENUM('efectivo','nequi','daviplata','bancolombia','fiado','obsequio') NOT NULL,
    -- obsequio (mig. 026): NO genera ingreso, solo descuenta stock
    `total`        DECIMAL(12,2)   NOT NULL DEFAULT 0,
    `notas`        TEXT            DEFAULT NULL,
    `es_combo`     TINYINT(1)      NOT NULL DEFAULT 0,  -- 1 si algún ítem es combo (mig. 025)
    `tipo_sandwich` VARCHAR(100)   DEFAULT NULL,   -- denormalizado, legado
    `estado`       ENUM('completada','anulada','pendiente_pago') NOT NULL DEFAULT 'completada',
    -- Descuento en el POS (mig.038) — retrocompatible: DEFAULT 0 para filas anteriores
    `descuento_pct`   DECIMAL(5,2)  NOT NULL DEFAULT 0 COMMENT 'Porcentaje de descuento aplicado (0-50)',
    `descuento_valor` DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'Valor monetario del descuento — snapshot inmutable',
    `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by`   INT UNSIGNED    DEFAULT NULL,
    `updated_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by`   INT UNSIGNED    DEFAULT NULL,
    CONSTRAINT `fk_v_cliente` FOREIGN KEY (`cliente_id`)
        REFERENCES `clientes`(`id`) ON DELETE SET NULL,
    INDEX `idx_v_fecha`   (`fecha_venta`),
    INDEX `idx_v_estado`  (`estado`),
    INDEX `idx_v_cliente` (`cliente_id`),
    INDEX `idx_v_metodo`  (`metodo_pago`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- TABLA: venta_detalles
-- Líneas de cada venta.
-- INVARIANTES (NUNCA actualizar estos campos tras el INSERT):
--   precio_unitario → precio cobrado al momento de la venta
--   nombre_snap     → nombre del producto al momento de la venta (mig. 034)
--   nombre2_snap    → subtítulo del producto al momento de la venta (mig. 034)
-- ============================================================
CREATE TABLE `venta_detalles` (
    `id`              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `venta_id`        BIGINT UNSIGNED NOT NULL,
    `producto_id`     INT UNSIGNED    NOT NULL,
    `cantidad`        SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    -- Snapshots de precio (inmutables)
    `precio_unitario` DECIMAL(10,2)   NOT NULL,
    `precio_lista`    DECIMAL(10,2)   DEFAULT NULL,  -- precio sugerido del catálogo (mig. 003)
    `subtotal`        DECIMAL(12,2)   NOT NULL,
    -- Trazabilidad de fuente de stock
    `from_stock`      TINYINT(1)      NOT NULL DEFAULT 0,
    -- Información de combo (mig. 025)
    `es_combo`        TINYINT(1)      NOT NULL DEFAULT 0,
    `combo_id`        INT UNSIGNED    DEFAULT NULL,
    `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by`      INT UNSIGNED    DEFAULT NULL,
    `updated_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by`      INT UNSIGNED    DEFAULT NULL,
    -- Snapshots de nombre (mig. 034 — inmutables — al final por orden histórico de migración)
    `nombre_snap`     VARCHAR(200)    DEFAULT NULL,
    `nombre2_snap`    VARCHAR(120)    DEFAULT NULL,
    -- Variante de tamaño (mig. 035 — snapshot al momento de la venta)
    -- NULL = venta anterior a mig. 035 o producto sin variantes
    `variante_id`        INT           DEFAULT NULL,
    `variante_etiqueta`  VARCHAR(80)   DEFAULT NULL,
    `factor_receta_snap` DECIMAL(5,3)  DEFAULT NULL,
    -- SIN FK sobre variante_id: errno 121 en cPanel compartido (igual que ajustes_stock)
    CONSTRAINT `fk_vd_venta`    FOREIGN KEY (`venta_id`)
        REFERENCES `ventas`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_vd_producto` FOREIGN KEY (`producto_id`)
        REFERENCES `productos`(`id`),
    CONSTRAINT `fk_vd_combo`    FOREIGN KEY (`combo_id`)
        REFERENCES `combo_configs`(`id`) ON DELETE SET NULL,
    INDEX `idx_vd_venta`    (`venta_id`),
    INDEX `idx_vd_producto` (`producto_id`),
    INDEX `idx_vd_variante` (`variante_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- TABLA: pagos_fiado
-- Abonos a deudas de clientes.
-- INVARIANTES (mig. 034):
--   saldo_anterior → deuda del cliente ANTES del abono
--   saldo_posterior → deuda del cliente DESPUÉS del abono
-- ============================================================
CREATE TABLE `pagos_fiado` (
    `id`              INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    `cliente_id`      INT UNSIGNED  NOT NULL,
    `monto`           DECIMAL(10,2) NOT NULL,
    `metodo_pago`     ENUM('efectivo','nequi','daviplata','bancolombia') NOT NULL DEFAULT 'efectivo',
    `notas`           TEXT          DEFAULT NULL,
    `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by`      INT UNSIGNED  DEFAULT NULL,
    `updated_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by`      INT UNSIGNED  DEFAULT NULL,
    -- Snapshots de saldo (mig. 034)
    `saldo_anterior`  DECIMAL(12,2) DEFAULT NULL,
    `saldo_posterior` DECIMAL(12,2) DEFAULT NULL,
    CONSTRAINT `fk_pf_cliente` FOREIGN KEY (`cliente_id`)
        REFERENCES `clientes`(`id`),
    INDEX `idx_pf_cliente` (`cliente_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- TABLA: compras
-- Cabecera de cada compra de insumos.
-- Al crear: actualiza costo_actual y stock de insumos → recalcula productos.
-- ============================================================
CREATE TABLE `compras` (
    `id`           INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    `fecha_compra` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `proveedor_id` INT UNSIGNED  DEFAULT NULL,
    `lugar_compra` VARCHAR(150)  DEFAULT NULL,
    `total`        DECIMAL(12,2) NOT NULL DEFAULT 0,
    `notas`        TEXT          DEFAULT NULL,
    `created_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by`   INT UNSIGNED  DEFAULT NULL,
    `updated_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by`   INT UNSIGNED  DEFAULT NULL,
    CONSTRAINT `fk_c_proveedor` FOREIGN KEY (`proveedor_id`)
        REFERENCES `proveedores`(`id`) ON DELETE SET NULL,
    INDEX `idx_c_fecha` (`fecha_compra`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- TABLA: compra_detalles
-- Líneas de cada compra.
-- INVARIANTES (NUNCA actualizar tras el INSERT):
--   precio_unitario → precio por unidad básica al comprar
--   presentacion/precio_presentacion → contexto del empaque (mig. 032)
--   nombre_snap/unidad_snap → snapshots de nombre e unidad (mig. 034)
-- ============================================================
CREATE TABLE `compra_detalles` (
    `id`              INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    `compra_id`       INT UNSIGNED  NOT NULL,
    `insumo_id`       INT UNSIGNED  NOT NULL,
    `cantidad`        DECIMAL(12,4) NOT NULL,     -- total en unidades básicas
    `precio_unitario` DECIMAL(12,4) NOT NULL,     -- precio por unidad básica (snapshot)
    `subtotal`        DECIMAL(12,2) NOT NULL,
    `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by`      INT UNSIGNED  DEFAULT NULL,
    `updated_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by`      INT UNSIGNED  DEFAULT NULL,
    -- Snapshot del empaque (mig. 032 — al final por orden histórico de migración)
    -- Invariante: precio_presentacion / cantidad_presentacion = precio_unitario
    --             cant_presentaciones × cantidad_presentacion  = cantidad
    `presentacion`          VARCHAR(30)   DEFAULT NULL,
    `cantidad_presentacion` DECIMAL(12,4) DEFAULT NULL,
    `cant_presentaciones`   DECIMAL(10,4) DEFAULT NULL,
    `precio_presentacion`   DECIMAL(12,2) DEFAULT NULL,
    -- Snapshots de nombre e unidad (mig. 034 — al final por orden histórico)
    `nombre_snap`           VARCHAR(200)  DEFAULT NULL,
    `unidad_snap`           VARCHAR(20)   DEFAULT NULL,
    -- FK lógica a insumo_presentaciones (mig. 039) — NULL = compra sin presentación catalogada
    `presentacion_id`       INT           DEFAULT NULL
                                COMMENT 'FK lógica → insumo_presentaciones.id. NULL = sin presentación catalogada.',
    CONSTRAINT `fk_cd_compra` FOREIGN KEY (`compra_id`)
        REFERENCES `compras`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_cd_insumo` FOREIGN KEY (`insumo_id`)
        REFERENCES `insumos`(`id`),
    INDEX `idx_cd_compra` (`compra_id`),
    INDEX `idx_cd_insumo` (`insumo_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- TABLA: insumo_presentaciones (mig. 039)
-- Catálogo de presentaciones de compra por insumo.
-- Principio: la unidad canónica del insumo (unidad_medida, stock, costo) NUNCA cambia.
-- Esta tabla solo define "cómo se compra" — el sistema convierte automáticamente:
--   cantidad (canónica) = cant_presentaciones × cantidad_base
--   precio_unitario     = precio_presentacion  / cantidad_base
-- SIN FK A NIVEL BD (errno 121 en cPanel compartido).
-- ============================================================
CREATE TABLE `insumo_presentaciones` (
    `id`                INT           NOT NULL AUTO_INCREMENT,
    `insumo_id`         INT           NOT NULL COMMENT 'FK lógica → insumos.id',
    `nombre`            VARCHAR(60)   NOT NULL COMMENT 'Ej: Frasco 900ml, Galón 3.785L, Paca 12 unidades',
    `cantidad_base`     DECIMAL(12,4) NOT NULL COMMENT 'Cuántas unidades canónicas trae esta presentación',
    `unidad_compra`     VARCHAR(30)   NOT NULL DEFAULT '' COMMENT 'Etiqueta: frasco, galón, paca, caja…',
    `precio_referencia` DECIMAL(12,2) DEFAULT NULL COMMENT 'Precio habitual de referencia (orientativo)',
    `equiv_cantidad`    DECIMAL(10,4) DEFAULT NULL COMMENT 'Override equiv_cantidad del insumo para esta presentación',
    `equiv_unidad`      VARCHAR(20)   DEFAULT NULL COMMENT 'Override equiv_unidad del insumo para esta presentación',
    `es_predeterminada` TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '1 = pre-selecciona en formulario de compras',
    `activo`            TINYINT(1)    NOT NULL DEFAULT 1,
    `created_at`        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by`        INT DEFAULT NULL,
    `updated_by`        INT DEFAULT NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_ip_insumo` (`insumo_id`),
    INDEX `idx_ip_activo` (`activo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Catálogo de presentaciones de compra por insumo — migración 039';


-- ============================================================
-- TABLA: empleados
-- Personal del negocio. tipo_costo clasifica el gasto en costos.
-- usuario_id enlaza al empleado con su usuario del sistema.
-- ============================================================
CREATE TABLE `empleados` (
    `id`                    INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    `usuario_id`            INT UNSIGNED  DEFAULT NULL,  -- FK a usuarios (empleado con acceso al sistema)
    `nombre_completo`       VARCHAR(200)  NOT NULL,
    `documento_identidad`   VARCHAR(20)   DEFAULT NULL,
    `cargo`                 VARCHAR(100)  DEFAULT NULL,
    `tipo_contrato`         ENUM('tiempo_completo','medio_tiempo','por_horas','por_dias','por_servicio')
                            NOT NULL DEFAULT 'tiempo_completo',
    `pais_laboral`          VARCHAR(60)   NOT NULL DEFAULT 'Colombia',
    `horas_semana`          TINYINT(3) UNSIGNED DEFAULT NULL,
    `periodo_horas_emp`     ENUM('semana','mes') DEFAULT NULL,
    `valor_hora`            DECIMAL(10,2) DEFAULT NULL,   -- tarifa para por_horas
    `valor_proyecto`        DECIMAL(12,2) DEFAULT NULL,   -- pago para por_servicio
    `fecha_ingreso`         DATE          NOT NULL,
    `salario_base`          DECIMAL(12,2) NOT NULL,
    `aplica_aux_transporte` TINYINT(1)    NOT NULL DEFAULT 1,
    `tipo_costo`            ENUM('directo','indirecto') NOT NULL DEFAULT 'indirecto',
    `activo`                TINYINT(1)    NOT NULL DEFAULT 1,
    `created_at`            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by`            INT UNSIGNED  DEFAULT NULL,
    `updated_at`            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by`            INT UNSIGNED  DEFAULT NULL,
    CONSTRAINT `fk_emp_usuario` FOREIGN KEY (`usuario_id`)
        REFERENCES `usuarios`(`id`) ON DELETE SET NULL,
    INDEX `idx_emp_activo`     (`activo`),
    INDEX `idx_emp_tipo_costo` (`tipo_costo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- TABLA: registro_horas (mig. 007 + 008)
-- Registro diario de horas trabajadas (contratos por_horas).
-- ============================================================
CREATE TABLE `registro_horas` (
    `id`          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `empleado_id` INT UNSIGNED    NOT NULL,
    `fecha`       DATE            NOT NULL,
    `horas`       DECIMAL(5,2)    NOT NULL DEFAULT 8.00,
    `tipo_hora`   ENUM('ordinaria','recargo_nocturno','extra_diurna','extra_nocturna',
                       'festiva_ordinaria','extra_festiva_diurna','extra_festiva_nocturna')
                  NOT NULL DEFAULT 'ordinaria',
    `es_festivo`  TINYINT(1)      NOT NULL DEFAULT 0,
    `descripcion` VARCHAR(200)    DEFAULT NULL,
    `aprobado`    TINYINT(1)      NOT NULL DEFAULT 0,  -- 0=pendiente aprobación, 1=aprobado
    `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by`  INT UNSIGNED    DEFAULT NULL,
    `updated_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by`  INT UNSIGNED    DEFAULT NULL,
    UNIQUE KEY `uk_rh_emp_fecha` (`empleado_id`, `fecha`),
    CONSTRAINT `fk_rh_empleado` FOREIGN KEY (`empleado_id`)
        REFERENCES `empleados`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- TABLA: parametros_laborales (mig. 007)
-- Porcentajes prestacionales configurables por país.
-- ============================================================
CREATE TABLE `parametros_laborales` (
    `id`               INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    `pais`             VARCHAR(60)   NOT NULL DEFAULT 'Colombia',
    `clave`            VARCHAR(100)  NOT NULL,
    `nombre`           VARCHAR(200)  NOT NULL,   -- nombre legible del parámetro
    `valor`            DECIMAL(15,6) NOT NULL,
    `tipo`             ENUM('porcentaje','valor_fijo') NOT NULL DEFAULT 'porcentaje',
    `aplica_a`         ENUM('empleador','empleado','ambos') NOT NULL DEFAULT 'empleador',
    `categoria`        ENUM('base','carga_parafiscal','provision','descuento_empleado','tope','horas_jornada')
                       NOT NULL DEFAULT 'carga_parafiscal',
    `aplica_contratos` VARCHAR(200)  NOT NULL DEFAULT 'tiempo_completo,medio_tiempo,por_horas',
    `descripcion`      TEXT          DEFAULT NULL,
    `activo`           TINYINT(1)    NOT NULL DEFAULT 1,
    `orden`            TINYINT(3) UNSIGNED NOT NULL DEFAULT 50,
    `created_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_pl_pais_clave` (`pais`, `clave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- TABLA: nomina_liquidaciones
-- SNAPSHOT INMUTABLE de cada liquidación mensual.
-- Solo pagado y fecha_pago_nomina pueden actualizarse post-INSERT.
-- valor_hora_snap y valor_proyecto_snap al final (mig. 033).
-- ============================================================
CREATE TABLE `nomina_liquidaciones` (
    `id`                    INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    `empleado_id`           INT UNSIGNED  NOT NULL,
    `periodo_mes`           TINYINT(3) UNSIGNED NOT NULL,    -- 1-12
    `periodo_anio`          YEAR(4)       NOT NULL,

    -- Horas y recargos (mig. 007 + 008)
    `horas_trabajadas`      DECIMAL(7,2)  DEFAULT NULL,
    `horas_ordinarias`      DECIMAL(7,2)  DEFAULT 0,
    `horas_extras`          DECIMAL(7,2)  DEFAULT 0,
    `valor_horas_extras`    DECIMAL(12,2) DEFAULT 0,
    `detalle_recargos`      TEXT          DEFAULT NULL,  -- JSON: desglose por tipo de hora

    -- Snapshot del contrato al liquidar
    `tipo_contrato`         VARCHAR(30)   DEFAULT NULL,
    `descripcion_pago`      VARCHAR(300)  DEFAULT NULL,  -- para por_servicio

    -- Componentes del costo (Colombia 2026)
    `salario_base`          DECIMAL(12,2) NOT NULL DEFAULT 0,
    `aux_transporte`        DECIMAL(10,2) NOT NULL DEFAULT 0,
    -- Cargas parafiscales (a cargo del empleador)
    `salud_empleador`       DECIMAL(10,2) NOT NULL DEFAULT 0,   -- 8.5%
    `pension_empleador`     DECIMAL(10,2) NOT NULL DEFAULT 0,   -- 12%
    `arl`                   DECIMAL(10,2) NOT NULL DEFAULT 0,   -- 0.522%
    `caja_compensacion`     DECIMAL(10,2) NOT NULL DEFAULT 0,   -- 4%
    `icbf`                  DECIMAL(10,2) NOT NULL DEFAULT 0,   -- 3%
    `sena`                  DECIMAL(10,2) NOT NULL DEFAULT 0,   -- 2%
    -- Descuentos del empleado
    `salud_empleado`        DECIMAL(10,2) NOT NULL DEFAULT 0,   -- 4%
    `pension_empleado`      DECIMAL(10,2) NOT NULL DEFAULT 0,   -- 4%
    -- Neto que recibe el empleado
    `neto_pagado`           DECIMAL(12,2) NOT NULL DEFAULT 0,
    -- Provisiones (derechos del trabajador)
    `prima`                 DECIMAL(10,2) NOT NULL DEFAULT 0,   -- 8.33%
    `cesantias`             DECIMAL(10,2) NOT NULL DEFAULT 0,   -- 8.33%
    `intereses_cesantias`   DECIMAL(10,2) NOT NULL DEFAULT 0,   -- 1%
    `vacaciones`            DECIMAL(10,2) NOT NULL DEFAULT 0,   -- 4.17%
    `total_cargas`          DECIMAL(12,2) NOT NULL DEFAULT 0,
    `total_provisiones`     DECIMAL(12,2) NOT NULL DEFAULT 0,
    -- Costo total real para el negocio
    `costo_total_empleador` DECIMAL(12,2) NOT NULL DEFAULT 0,

    -- Estado de pago (únicos campos actualizables)
    `pagado`                TINYINT(1)    NOT NULL DEFAULT 0,
    `fecha_pago_nomina`     DATE          DEFAULT NULL,
    `created_at`            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by`            INT UNSIGNED  DEFAULT NULL,
    `updated_at`            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by`            INT UNSIGNED  DEFAULT NULL,
    -- Snapshots de tarifa (mig. 033 — inmutables — al final por orden histórico)
    `valor_hora_snap`       DECIMAL(10,4) DEFAULT NULL,  -- tarifa/hora al liquidar
    `valor_proyecto_snap`   DECIMAL(12,2) DEFAULT NULL,  -- valor proyecto al liquidar

    UNIQUE KEY `uk_nl_emp_periodo` (`empleado_id`, `periodo_mes`, `periodo_anio`),
    CONSTRAINT `fk_nl_empleado` FOREIGN KEY (`empleado_id`)
        REFERENCES `empleados`(`id`),
    INDEX `idx_nl_periodo` (`periodo_anio`, `periodo_mes`),
    INDEX `idx_nl_pagado`  (`pagado`, `periodo_anio`, `periodo_mes`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- TABLA: activos
-- Equipos y bienes fijos. Depreciación via triggers:
--   depreciacion_mensual = costo_inicial / vida_util_meses
--   depreciacion_diaria  = depreciacion_mensual / 30.41666
-- REGLA (mig. 017): sin fecha_inicio_uso → deprec = 0.
-- NOTA: NO tiene columna estado_vida (se gestiona por PHP).
-- ============================================================
CREATE TABLE `activos` (
    `id`                   INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    `nombre`               VARCHAR(150)  NOT NULL,
    `numero_unidades`      SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    `precio_unitario`      DECIMAL(12,2) DEFAULT NULL,   -- precio por unidad; si se llena → costo_inicial = precio_u × unidades
    `descripcion`          TEXT          DEFAULT NULL,
    `lugar_compra`         VARCHAR(150)  DEFAULT NULL,
    `proveedor_id`         INT UNSIGNED  DEFAULT NULL,
    `serial`               VARCHAR(100)  DEFAULT NULL,
    `foto_url`             VARCHAR(255)  DEFAULT NULL,
    `costo_inicial`        DECIMAL(12,2) NOT NULL,
    `fecha_adquisicion`    DATE          NOT NULL,
    `garantia_hasta`       DATE          DEFAULT NULL,
    `fecha_inicio_uso`     DATE          DEFAULT NULL,  -- NULL → en_espera, sin depreciación
    `vida_util_meses`      TINYINT(3) UNSIGNED NOT NULL DEFAULT 12,
    `depreciacion_mensual` DECIMAL(12,4) DEFAULT NULL,   -- calculado por trigger
    `depreciacion_diaria`  DECIMAL(12,6) DEFAULT NULL,   -- calculado por trigger
    `activo`               TINYINT(1)    NOT NULL DEFAULT 1,
    `estado_fisico`        ENUM('excelente','bueno','regular','malo','baja') NOT NULL DEFAULT 'bueno',
    `categoria_activo`     VARCHAR(60)   NOT NULL DEFAULT 'otro',
    `responsable`          VARCHAR(100)  DEFAULT NULL,
    `notas`                TEXT          DEFAULT NULL,
    `created_at`           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by`           INT UNSIGNED  DEFAULT NULL,
    `updated_at`           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by`           INT UNSIGNED  DEFAULT NULL,
    CONSTRAINT `fk_act_proveedor` FOREIGN KEY (`proveedor_id`)
        REFERENCES `proveedores`(`id`) ON DELETE SET NULL,
    INDEX `idx_lugar_compra`    (`lugar_compra`),
    INDEX `idx_fecha_adq`       (`fecha_adquisicion`),
    INDEX `idx_fecha_inicio_uso`(`fecha_inicio_uso`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- TABLA: costos_indirectos
-- Costos fijos y variables del negocio.
-- Cada fila ES un período de vigencia. Al cambiar valor:
-- poner fecha_fin a la fila actual y crear una fila nueva.
-- ============================================================
CREATE TABLE `costos_indirectos` (
    `id`             INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    `nombre`         VARCHAR(200)  NOT NULL,
    `categoria`      VARCHAR(60)   NOT NULL DEFAULT 'otro',  -- listas_sistema tipo='categoria_costo'
    `descripcion`    TEXT          DEFAULT NULL,
    `tipo`           ENUM('fijo','variable')     NOT NULL DEFAULT 'fijo',
    `clasificacion`  ENUM('directo','indirecto') NOT NULL DEFAULT 'indirecto',  -- mig. 013
    `frecuencia`     ENUM('mensual','bimestral','trimestral','semestral','anual') NOT NULL DEFAULT 'mensual',
    `valor`          DECIMAL(12,2) NOT NULL DEFAULT 0,
    `fecha_inicio`   DATE          NOT NULL,
    `fecha_fin`      DATE          DEFAULT NULL,   -- NULL = vigente actualmente
    `activo`         TINYINT(1)    NOT NULL DEFAULT 1,
    `notas`          TEXT          DEFAULT NULL,
    `created_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by`     INT UNSIGNED  DEFAULT NULL,
    `updated_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by`     INT UNSIGNED  DEFAULT NULL,
    INDEX `idx_ci_activo` (`activo`, `fecha_inicio`, `fecha_fin`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- TABLA: produccion_lotes (mig. 015)
-- Registro de cada tanda de producción.
-- INVARIANTES: costo_unitario y nombre_snap son snapshots inmutables.
-- ============================================================
CREATE TABLE `produccion_lotes` (
    `id`               INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    `producto_id`      INT UNSIGNED  NOT NULL,
    `fecha_produccion` DATE          NOT NULL,
    `cantidad`         INT UNSIGNED  NOT NULL,
    `costo_unitario`   DECIMAL(10,4) DEFAULT NULL,  -- snapshot de costo_calculado al producir
    `notas`            TEXT          DEFAULT NULL,
    `estado`           ENUM('activo','anulado') NOT NULL DEFAULT 'activo',
    `created_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by`       INT UNSIGNED  DEFAULT NULL,
    `updated_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by`       INT UNSIGNED  DEFAULT NULL,
    -- Snapshot del nombre (mig. 034 — al final por orden histórico)
    `nombre_snap`      VARCHAR(200)  DEFAULT NULL,
    CONSTRAINT `fk_pl_producto` FOREIGN KEY (`producto_id`)
        REFERENCES `productos`(`id`),
    INDEX `idx_pl_producto_fecha` (`producto_id`, `fecha_produccion`),
    INDEX `idx_pl_fecha`          (`fecha_produccion`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- TABLA: ajustes_stock (mig. 026)
-- Reducciones manuales de stock_disponible sin venta.
-- NOTA: Sin FK en BD (workaround errno 121 en cPanel compartido).
-- La integridad la garantiza ajuste_stock.php via SELECT FOR UPDATE.
-- ============================================================
CREATE TABLE `ajustes_stock` (
    `id`           INT          AUTO_INCREMENT PRIMARY KEY,
    `producto_id`  INT          NOT NULL,
    `cantidad`     INT          NOT NULL,
    `tipo`         ENUM('obsequio','desecho') NOT NULL,
    `motivo`       VARCHAR(300) DEFAULT NULL,
    `fecha_ajuste` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by`   INT          DEFAULT NULL,
    INDEX `idx_as_producto` (`producto_id`),
    INDEX `idx_as_fecha`    (`fecha_ajuste`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


SET FOREIGN_KEY_CHECKS = 1;


-- ============================================================
-- ============================================================
-- TRIGGERS
-- ============================================================
-- ============================================================
DELIMITER $$

-- ── Insumos: costo_actual desde precio_presentacion (INSERT) ──────────────
DROP TRIGGER IF EXISTS `trg_insumos_costo_from_presentacion_insert`$$
CREATE TRIGGER `trg_insumos_costo_from_presentacion_insert`
BEFORE INSERT ON `insumos`
FOR EACH ROW
BEGIN
    IF NEW.precio_presentacion IS NOT NULL
       AND NEW.cantidad_presentacion IS NOT NULL
       AND NEW.cantidad_presentacion > 0 THEN
        SET NEW.costo_actual = ROUND(NEW.precio_presentacion / NEW.cantidad_presentacion, 4);
    END IF;
END$$

-- ── Insumos: costo_actual desde precio_presentacion (UPDATE) ─────────────
DROP TRIGGER IF EXISTS `trg_insumos_costo_from_presentacion_update`$$
CREATE TRIGGER `trg_insumos_costo_from_presentacion_update`
BEFORE UPDATE ON `insumos`
FOR EACH ROW
BEGIN
    IF NEW.precio_presentacion IS NOT NULL
       AND NEW.cantidad_presentacion IS NOT NULL
       AND NEW.cantidad_presentacion > 0
       AND (NEW.precio_presentacion <> OLD.precio_presentacion
            OR NEW.cantidad_presentacion <> OLD.cantidad_presentacion) THEN
        SET NEW.costo_actual = ROUND(NEW.precio_presentacion / NEW.cantidad_presentacion, 4);
    END IF;
END$$

-- ── Auditoría: configuracion_negocio ─────────────────────────────────────
DROP TRIGGER IF EXISTS `trg_config_negocio_audit`$$
CREATE TRIGGER `trg_config_negocio_audit`
AFTER UPDATE ON `configuracion_negocio`
FOR EACH ROW
BEGIN
    IF OLD.valor != NEW.valor THEN
        INSERT INTO `logs_historial`
            (`tabla`, `registro_id`, `campo`, `valor_anterior`, `valor_nuevo`, `accion`, `fecha_cambio`)
        VALUES
            ('configuracion_negocio', NEW.id, 'valor', OLD.valor, NEW.valor, 'UPDATE', NOW());
    END IF;
END$$

-- ── Auditoría: insumos (costo y stock) ───────────────────────────────────
DROP TRIGGER IF EXISTS `trg_insumos_audit`$$
CREATE TRIGGER `trg_insumos_audit`
AFTER UPDATE ON `insumos`
FOR EACH ROW
BEGIN
    IF OLD.costo_actual != NEW.costo_actual THEN
        INSERT INTO `logs_historial`
            (`tabla`, `registro_id`, `campo`, `valor_anterior`, `valor_nuevo`, `accion`, `fecha_cambio`)
        VALUES
            ('insumos', NEW.id, 'costo_actual', OLD.costo_actual, NEW.costo_actual, 'UPDATE', NOW());
    END IF;
    IF OLD.stock_actual != NEW.stock_actual THEN
        INSERT INTO `logs_historial`
            (`tabla`, `registro_id`, `campo`, `valor_anterior`, `valor_nuevo`, `accion`, `fecha_cambio`)
        VALUES
            ('insumos', NEW.id, 'stock_actual', OLD.stock_actual, NEW.stock_actual, 'UPDATE', NOW());
    END IF;
END$$

-- ── Auditoría: productos (precio de venta) ───────────────────────────────
DROP TRIGGER IF EXISTS `trg_productos_audit`$$
CREATE TRIGGER `trg_productos_audit`
AFTER UPDATE ON `productos`
FOR EACH ROW
BEGIN
    IF OLD.precio_venta != NEW.precio_venta THEN
        INSERT INTO `logs_historial`
            (`tabla`, `registro_id`, `campo`, `valor_anterior`, `valor_nuevo`, `accion`, `fecha_cambio`)
        VALUES
            ('productos', NEW.id, 'precio_venta', OLD.precio_venta, NEW.precio_venta, 'UPDATE', NOW());
    END IF;
END$$

-- ── Auditoría: ventas (cambio de estado) ─────────────────────────────────
DROP TRIGGER IF EXISTS `trg_ventas_audit`$$
CREATE TRIGGER `trg_ventas_audit`
AFTER UPDATE ON `ventas`
FOR EACH ROW
BEGIN
    IF OLD.estado != NEW.estado THEN
        INSERT INTO `logs_historial`
            (`tabla`, `registro_id`, `campo`, `valor_anterior`, `valor_nuevo`, `accion`, `fecha_cambio`)
        VALUES
            ('ventas', NEW.id, 'estado', OLD.estado, NEW.estado, 'UPDATE', NOW());
    END IF;
END$$

-- ── Auditoría: empleados (salario) ───────────────────────────────────────
DROP TRIGGER IF EXISTS `trg_empleados_audit`$$
CREATE TRIGGER `trg_empleados_audit`
AFTER UPDATE ON `empleados`
FOR EACH ROW
BEGIN
    IF OLD.salario_base != NEW.salario_base THEN
        INSERT INTO `logs_historial`
            (`tabla`, `registro_id`, `campo`, `valor_anterior`, `valor_nuevo`, `accion`, `fecha_cambio`)
        VALUES
            ('empleados', NEW.id, 'salario_base', OLD.salario_base, NEW.salario_base, 'UPDATE', NOW());
    END IF;
END$$

-- ── Activos: depreciación al crear (mig. 017-018) ────────────────────────
-- REGLA: sin fecha_inicio_uso → deprec = 0
-- DIVISOR: 30.41666 (= 365/12, más exacto que 30.4)
DROP TRIGGER IF EXISTS `trg_activos_deprec_insert`$$
CREATE TRIGGER `trg_activos_deprec_insert`
BEFORE INSERT ON `activos`
FOR EACH ROW
BEGIN
    IF NEW.fecha_inicio_uso IS NOT NULL THEN
        SET NEW.depreciacion_mensual = NEW.costo_inicial
                                       / GREATEST(CAST(NEW.vida_util_meses AS SIGNED), 1);
        SET NEW.depreciacion_diaria  = NEW.depreciacion_mensual / 30.41666;
    ELSE
        SET NEW.depreciacion_mensual = NULL;
        SET NEW.depreciacion_diaria  = NULL;
    END IF;
END$$

-- ── Activos: depreciación al actualizar (mig. 017-018) ───────────────────
DROP TRIGGER IF EXISTS `trg_activos_deprec_update`$$
CREATE TRIGGER `trg_activos_deprec_update`
BEFORE UPDATE ON `activos`
FOR EACH ROW
BEGIN
    IF NEW.fecha_inicio_uso IS NULL THEN
        SET NEW.depreciacion_mensual = NULL;
        SET NEW.depreciacion_diaria  = NULL;
    ELSEIF OLD.costo_inicial   != NEW.costo_inicial
        OR OLD.vida_util_meses != NEW.vida_util_meses
        OR OLD.fecha_inicio_uso IS NULL
    THEN
        SET NEW.depreciacion_mensual = NEW.costo_inicial
                                       / GREATEST(CAST(NEW.vida_util_meses AS SIGNED), 1);
        SET NEW.depreciacion_diaria  = NEW.depreciacion_mensual / 30.41666;
    END IF;
END$$

DELIMITER ;


-- ============================================================
-- ============================================================
-- DATOS INICIALES
-- ============================================================
-- ============================================================

-- ── configuracion_negocio ────────────────────────────────────────────────
INSERT INTO `configuracion_negocio` (`clave`, `valor`, `descripcion`, `categoria`) VALUES
('salario_minimo',              1750905.0000, 'Salario Mínimo Legal Mensual Vigente (SMLMV) 2026', 'nomina'),
('aux_transporte',               249095.0000, 'Auxilio de Transporte Mensual Legal 2026',          'nomina'),
('pct_salud_empleador',               8.5000, 'Salud — aporte a cargo del empleador (%)',          'nomina'),
('pct_pension_empleador',            12.0000, 'Pensión — aporte a cargo del empleador (%)',        'nomina'),
('pct_arl',                           0.5220, 'ARL Riesgo Clase I — cocina/mostrador (%)',         'nomina'),
('pct_caja_compensacion',             4.0000, 'Caja de Compensación Familiar (%)',                 'nomina'),
('pct_icbf',                          3.0000, 'ICBF (%)',                                          'nomina'),
('pct_sena',                          2.0000, 'SENA (%)',                                          'nomina'),
('pct_prima',                         8.3300, 'Prima de Servicios — provisión mensual (%)',        'nomina'),
('pct_cesantias',                     8.3300, 'Cesantías — provisión mensual (%)',                 'nomina'),
('pct_intereses_cesantias',           1.0000, 'Intereses sobre Cesantías — provisión mensual (%)', 'nomina'),
('pct_vacaciones',                    4.1700, 'Vacaciones — provisión mensual (%)',                'nomina'),
('costos_fijos_mensuales',       365185.0000, 'Arriendo + servicios básicos mensuales',            'costos'),
('produccion_estimada_mensual',    2175.0000, 'Unidades/mes para prorratear costos fijos',         'produccion');


-- ── configuracion_app ────────────────────────────────────────────────────
INSERT IGNORE INTO `configuracion_app` (`clave`, `valor`, `descripcion`) VALUES
('nombre_negocio',    'ClanDestino',                          'Nombre del negocio'),
('logo_url',          '',                                     'Logo principal (ruta relativa)'),
('logo_url_login',    '',                                     'Logo en pantalla de login'),
('theme_brand',       '#e94f37',                              'Color de acento principal'),
('theme_dark',        '#111827',                              'Color de fondo oscuro'),
('theme_font',        'system-ui, -apple-system, sans-serif', 'Fuente principal del sistema'),
('font_heading',      'system-ui, -apple-system, sans-serif', 'Fuente para títulos'),
('theme_radius',      '12',                                   'Radio de bordes en px'),
('font_size_title',   '22',                                   'Tamaño fuente título'),
('font_size_subtitle','15',                                   'Tamaño fuente subtítulo'),
('font_size_body',    '13',                                   'Tamaño fuente cuerpo'),
('font_size_small',   '11',                                   'Tamaño fuente texto pequeño'),
('color_text',        '#111827',                              'Color de texto principal'),
('color_text_sec',    '#6b7280',                              'Color de texto secundario'),
-- Formato numérico configurable (mig. 040 y 041) — leído por FormatoHelper.php / NUM_FORMAT
('num_decimales',     '2', 'Decimales para cantidades (stock, presentaciones, equivalencias, costo por unidad)'),
('num_sep_miles',     '.', 'Caracter separador de miles para todos los numeros'),
('num_sep_decimal',   ',', 'Caracter separador decimal para todos los numeros'),
('num_sep_millones',  '.', 'Caracter separador para el grupo de millones (y superiores); si es igual al separador de miles, el formato es uniforme');


-- ── listas_sistema — presentaciones ──────────────────────────────────────
INSERT INTO `listas_sistema` (`tipo`, `valor`, `etiqueta`, `orden`) VALUES
('presentacion', 'frasco',  'Frasco',   1),
('presentacion', 'tarro',   'Tarro',    2),
('presentacion', 'caja',    'Caja',     3),
('presentacion', 'paca',    'Paca',     4),
('presentacion', 'bolsa',   'Bolsa',    5),
('presentacion', 'atado',   'Atado',    6),
('presentacion', 'lata',    'Lata',     7),
('presentacion', 'bloque',  'Bloque',   8),
('presentacion', 'galon',   'Galón',    9),
('presentacion', 'unidad',  'Unidad',   10),
('presentacion', 'otra',    'Otra',     99);

-- ── listas_sistema — unidades de medida ──────────────────────────────────
INSERT INTO `listas_sistema` (`tipo`, `valor`, `etiqueta`, `orden`) VALUES
('unidad_medida', 'kg',      'Kilogramos',  1),
('unidad_medida', 'g',       'Gramos',      2),
('unidad_medida', 'lb',      'Libras',      3),
('unidad_medida', 'litro',   'Litros',      4),
('unidad_medida', 'ml',      'Mililitros',  5),
('unidad_medida', 'unidad',  'Unidades',    6),
('unidad_medida', 'loncha',  'Lonchas',     7),
('unidad_medida', 'lata',    'Latas',       8),
('unidad_medida', 'paquete', 'Paquetes',    9);

-- ── listas_sistema — categorías de insumos ───────────────────────────────
INSERT INTO `listas_sistema` (`tipo`, `valor`, `etiqueta`, `orden`) VALUES
('categoria_insumo', 'proteina',   'Proteína',   1),
('categoria_insumo', 'lacteo',     'Lácteo',     2),
('categoria_insumo', 'vegetal',    'Vegetal',    3),
('categoria_insumo', 'condimento', 'Condimento', 4),
('categoria_insumo', 'empaque',    'Empaque',    5),
('categoria_insumo', 'grasa',      'Grasa',      6),
('categoria_insumo', 'combo',      'Combo',      7),
('categoria_insumo', 'otro',       'Otro',       99);

-- ── listas_sistema — categorías de productos ─────────────────────────────
INSERT INTO `listas_sistema` (`tipo`, `valor`, `etiqueta`, `orden`) VALUES
('categoria_producto', 'sandwich',  'Sándwich',  1),
('categoria_producto', 'combo',     'Combo',     2),
('categoria_producto', 'bebida',    'Bebida',    3),
('categoria_producto', 'adicional', 'Adicional', 4);

-- ── listas_sistema — tamaños de productos ────────────────────────────────
INSERT INTO `listas_sistema` (`tipo`, `valor`, `etiqueta`, `orden`) VALUES
('tamano_producto', 'XL',    'XL',    1),
('tamano_producto', 'L',     'L',     2),
('tamano_producto', 'unico', 'Único', 3);

-- ── listas_sistema — categorías de activos ───────────────────────────────
INSERT INTO `listas_sistema` (`tipo`, `valor`, `etiqueta`, `orden`) VALUES
('categoria_activo', 'equipo_cocina',    'Equipo de cocina',   1),
('categoria_activo', 'electrodomestico', 'Electrodoméstico',   2),
('categoria_activo', 'herramienta',      'Herramienta',        3),
('categoria_activo', 'utensilio',        'Utensilio',          4),
('categoria_activo', 'mobiliario',       'Mobiliario',         5),
('categoria_activo', 'vehiculo',         'Vehículo',           6),
('categoria_activo', 'otro',             'Otro',               99);

-- ── listas_sistema — categorías de costos ────────────────────────────────
INSERT INTO `listas_sistema` (`tipo`, `valor`, `etiqueta`, `orden`) VALUES
('categoria_costo', 'arriendo',           'Arriendo / Alquiler',      1),
('categoria_costo', 'servicios_publicos', 'Servicios Públicos',       2),
('categoria_costo', 'intereses',          'Intereses y Financiación', 3),
('categoria_costo', 'seguros',            'Seguros',                  4),
('categoria_costo', 'mantenimiento',      'Mantenimiento',            5),
('categoria_costo', 'publicidad',         'Publicidad y Mercadeo',    6),
('categoria_costo', 'bancario',           'Gastos Bancarios',         7),
('categoria_costo', 'impuestos',          'Impuestos y Tasas',        8),
('categoria_costo', 'administrativo',     'Personal Administrativo',  9),
('categoria_costo', 'otro',               'Otro',                     99);

-- ── listas_sistema — categorías de proveedores ───────────────────────────
INSERT INTO `listas_sistema` (`tipo`, `valor`, `etiqueta`, `orden`) VALUES
('categoria_proveedor', 'plaza',     'Plaza de mercado', 1),
('categoria_proveedor', 'tienda',    'Tienda',           2),
('categoria_proveedor', 'retail',    'Retail',           3),
('categoria_proveedor', 'online',    'Online',           4),
('categoria_proveedor', 'mayorista', 'Mayorista',        5),
('categoria_proveedor', 'panaderia', 'Panadería',        6),
('categoria_proveedor', 'otro',      'Otro',             99);


-- ── parametros_laborales — Colombia 2026 ─────────────────────────────────
-- tipo: 'porcentaje' o 'valor_fijo' (sin 'monto_fijo' ni 'factor')
-- categoria: ENUM estricto — ver definición de la tabla
INSERT INTO `parametros_laborales`
    (`pais`, `clave`, `nombre`, `valor`, `tipo`, `aplica_a`, `categoria`, `aplica_contratos`, `descripcion`, `activo`, `orden`)
VALUES
('Colombia', 'salario_minimo',          'Salario Mínimo Mensual (SMLMV)',         1750905, 'valor_fijo',  'empleador', 'base',               'tiempo_completo,medio_tiempo,por_horas,por_servicio', 'Salario Mínimo Legal Mensual Vigente 2026',                        1,  1),
('Colombia', 'aux_transporte',          'Auxilio de Transporte',                   249095, 'valor_fijo',  'empleador', 'base',               'tiempo_completo,medio_tiempo,por_horas',              'Aplica cuando salario <= 2 SMLMV. Valor 2026.',                    1,  2),
('Colombia', 'tope_aux_transporte_smlmv','Tope auxilio transporte (múltiplo SMLMV)',    2, 'valor_fijo',  'empleador', 'tope',               'tiempo_completo,medio_tiempo,por_horas',              'El auxilio aplica si salario <= este valor × SMLMV',               1,  3),
('Colombia', 'horas_mes_estandar',      'Horas estándar de trabajo al mes',          240, 'valor_fijo',  'empleador', 'base',               'por_horas',                                          'Para calcular valor hora: salario_base / horas_mes_estandar',      1,  4),
('Colombia', 'pct_salud_empleador',     'Salud — aporte empleador',                  8.5, 'porcentaje',  'empleador', 'carga_parafiscal',   'tiempo_completo,medio_tiempo,por_horas',              '8.5% sobre salario base',                                          1, 10),
('Colombia', 'pct_pension_empleador',   'Pensión — aporte empleador',                 12, 'porcentaje',  'empleador', 'carga_parafiscal',   'tiempo_completo,medio_tiempo,por_horas',              '12% sobre salario base',                                           1, 11),
('Colombia', 'pct_arl',                 'ARL Riesgo Clase I (cocina/mostrador)',    0.522, 'porcentaje',  'empleador', 'carga_parafiscal',   'tiempo_completo,medio_tiempo,por_horas',              '0.522% para actividades de bajo riesgo',                           1, 12),
('Colombia', 'pct_caja_compensacion',   'Caja de Compensación Familiar',               4, 'porcentaje',  'empleador', 'carga_parafiscal',   'tiempo_completo,medio_tiempo,por_horas',              '4% sobre salario base',                                            1, 13),
('Colombia', 'pct_icbf',                'ICBF',                                        3, 'porcentaje',  'empleador', 'carga_parafiscal',   'tiempo_completo,medio_tiempo,por_horas',              '3% sobre salario base. Exento si salario > 10 SMLMV.',             1, 14),
('Colombia', 'pct_sena',                'SENA',                                        2, 'porcentaje',  'empleador', 'carga_parafiscal',   'tiempo_completo,medio_tiempo,por_horas',              '2% sobre salario base. Exento si salario > 10 SMLMV.',             1, 15),
('Colombia', 'pct_prima',               'Prima de Servicios (provisión mensual)',   8.33, 'porcentaje',  'empleador', 'provision',          'tiempo_completo,medio_tiempo,por_horas',              '1/12 del salario por mes = 8.33%. Se paga jun y dic.',             1, 20),
('Colombia', 'pct_cesantias',           'Cesantías (provisión mensual)',            8.33, 'porcentaje',  'empleador', 'provision',          'tiempo_completo,medio_tiempo,por_horas',              '1/12 del salario por mes = 8.33%.',                                1, 21),
('Colombia', 'pct_intereses_cesantias', 'Intereses sobre Cesantías (provisión)',      1, 'porcentaje',  'empleador', 'provision',          'tiempo_completo,medio_tiempo,por_horas',              '1% sobre el saldo de cesantías acumuladas.',                       1, 22),
('Colombia', 'pct_vacaciones',          'Vacaciones (provisión mensual)',           4.17, 'porcentaje',  'empleador', 'provision',          'tiempo_completo,medio_tiempo,por_horas',              '15 días hábiles al año = 4.17% mensual.',                          1, 23),
('Colombia', 'pct_salud_empleado',      'Salud — descuento al empleado',              4, 'porcentaje',  'empleado',  'descuento_empleado', 'tiempo_completo,medio_tiempo,por_horas',              '4% sobre salario base, a cargo del trabajador.',                   1, 30),
('Colombia', 'pct_pension_empleado',    'Pensión — descuento al empleado',            4, 'porcentaje',  'empleado',  'descuento_empleado', 'tiempo_completo,medio_tiempo,por_horas',              '4% sobre salario base, a cargo del trabajador.',                   1, 31);


-- ── Superadministrador inicial ────────────────────────────────────────────
-- CONTRASEÑA DEFAULT: Admin2026!
-- ACCIÓN OBLIGATORIA: Cambiar en el PRIMER login.
-- Para regenerar el hash en PHP:
--   echo password_hash('Admin2026!', PASSWORD_BCRYPT, ['cost' => 12]);
INSERT INTO `usuarios` (`nombre`, `email`, `password_hash`, `rol`) VALUES
(
    'Super Administrador',
    'admin@clandestino.local',
    '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'superadmin'
);

-- Permisos admin_total para el superadmin en todos los módulos
INSERT INTO `permisos_modulos` (`usuario_id`, `modulo`, `nivel_acceso`) VALUES
(1, 'ventas',       'admin_total'),
(1, 'compras',      'admin_total'),
(1, 'inventario',   'admin_total'),
(1, 'nomina',       'admin_total'),
(1, 'productos',    'admin_total'),
(1, 'activos',      'admin_total'),
(1, 'reportes',     'admin_total'),
(1, 'proveedores',  'admin_total'),
(1, 'costos',       'admin_total');


-- ── Insumos base del negocio ──────────────────────────────────────────────
-- Ajustar precios y stocks reales en Admin → Inventario después de instalar.
INSERT INTO `insumos` (`nombre`, `unidad_medida`, `costo_actual`, `stock_actual`, `stock_seguridad`) VALUES
('Pollo Desmechado', 'kg',      17325.0000, 0.000, 2.000),
('Carne de Res',     'kg',      27000.0000, 0.000, 1.000),
('Atún Lata 160g',   'lata',     2995.0000, 0.000, 6.000),
('Jamón (loncha)',   'loncha',      0.0000, 0.000, 28.000),
('Pan',              'unidad',   1500.0000, 0.000, 50.000);


-- ── Productos base del catálogo ───────────────────────────────────────────
-- precio_venta = 0 hasta que el administrador configure precios reales.
INSERT INTO `productos` (`nombre`, `categoria`, `tamano`, `precio_venta`) VALUES
('Sándwich de Pollo XL',  'sandwich', 'XL',    0.00),
('Sándwich de Pollo L',   'sandwich', 'L',     0.00),
('Sándwich de Carne XL',  'sandwich', 'XL',    0.00),
('Sándwich de Carne L',   'sandwich', 'L',     0.00),
('Sándwich de Atún XL',   'sandwich', 'XL',    0.00),
('Sándwich de Atún L',    'sandwich', 'L',     0.00),
('Sándwich de Jamón XL',  'sandwich', 'XL',    0.00),
('Sándwich de Jamón L',   'sandwich', 'L',     0.00);


-- ── Recetario base ────────────────────────────────────────────────────────
-- Pollo: 90.9g = 0.090909 kg/sándwich (1kg rinde 11 unidades)
-- Carne: 100g  = 0.100000 kg/sándwich (1kg rinde 10 unidades)
-- Atún:  0.6667 latas/sándwich (6 latas rinden 9 unidades)
-- Jamón: 2 lonchas/sándwich
-- Pan:   1 unidad/sándwich
INSERT INTO `recetas` (`producto_id`, `insumo_id`, `cantidad_requerida`, `es_insumo_critico`) VALUES
(1, 1, 0.090909, 1), (1, 5, 1.000000, 0),   -- Pollo XL
(2, 1, 0.090909, 1), (2, 5, 1.000000, 0),   -- Pollo L
(3, 2, 0.100000, 1), (3, 5, 1.000000, 0),   -- Carne XL
(4, 2, 0.100000, 1), (4, 5, 1.000000, 0),   -- Carne L
(5, 3, 0.666667, 1), (5, 5, 1.000000, 0),   -- Atún XL
(6, 3, 0.666667, 1), (6, 5, 1.000000, 0),   -- Atún L
(7, 4, 2.000000, 1), (7, 5, 1.000000, 0),   -- Jamón XL
(8, 4, 2.000000, 1), (8, 5, 1.000000, 0);   -- Jamón L

-- ============================================================
-- TABLA: turnos_caja (mig. 037)
-- Registra la apertura de cada turno con el fondo inicial en efectivo.
-- Un día puede tener máximo un turno activo.
-- SIN FK: consistencia con política del proyecto (errno 121 cPanel).
-- ============================================================
CREATE TABLE IF NOT EXISTS `turnos_caja` (
    `id`               INT AUTO_INCREMENT PRIMARY KEY,
    `fecha`            DATE          NOT NULL,
    `fondo_inicial`    DECIMAL(12,2) NOT NULL DEFAULT 0,
    `notas_apertura`   TEXT          NULL,
    `usuario_apertura` INT           NOT NULL,
    `fecha_apertura`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `estado`           ENUM('abierto','cerrado') NOT NULL DEFAULT 'abierto',
    `fecha_cierre`     DATETIME      NULL,
    `usuario_cierre`   INT           NULL,
    `notas_cierre`     TEXT          NULL,
    INDEX `idx_tc_fecha`  (`fecha`),
    INDEX `idx_tc_estado` (`estado`, `fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- FIN DEL ESQUEMA v4.74
-- Verifica la instalación:
--   SHOW TABLES;                            -- debe mostrar 29 tablas
--   SHOW TRIGGERS;                          -- debe mostrar 9 triggers
--   SELECT COUNT(*) FROM listas_sistema;    -- debe ser 59
--   SELECT COUNT(*) FROM parametros_laborales; -- debe ser 16
-- ============================================================
