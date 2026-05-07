-- ============================================================
-- ClanDestino ERP v4.0 — Schema SQL Completo
-- Compatible: MySQL 5.7+ / MariaDB 10.3+
-- Hosting compartido (cPanel / phpMyAdmin)
-- ============================================================
-- INSTRUCCIONES DE INSTALACIÓN:
--   1. Crear base de datos: clandestino_erp (charset: utf8mb4)
--   2. Ejecutar este script completo en phpMyAdmin > SQL
--   3. Cambiar la contraseña del superadmin EN PRIMER LOGIN
--   4. Verificar que las FK están activas: SELECT @@foreign_key_checks;
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '-05:00'; -- Colombia (UTC-5)
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- TABLA: logs_historial
-- Propósito: Auditoría centralizada de TODOS los cambios del sistema.
-- REGLA: Esta tabla NUNCA se trunca ni se borra. Solo consulta.
-- ============================================================
CREATE TABLE IF NOT EXISTS `logs_historial` (
    `id`             BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `tabla`          VARCHAR(64)      NOT NULL  COMMENT 'Nombre de la tabla afectada',
    `registro_id`    BIGINT UNSIGNED  NOT NULL  COMMENT 'PK del registro modificado',
    `campo`          VARCHAR(64)      NOT NULL  COMMENT 'Nombre del campo que cambió',
    `valor_anterior` TEXT                       COMMENT 'Valor ANTES del cambio (NULL en INSERT)',
    `valor_nuevo`    TEXT                       COMMENT 'Valor DESPUÉS del cambio (NULL en DELETE)',
    `accion`         ENUM('INSERT','UPDATE','DELETE') NOT NULL,
    `usuario_id`     INT UNSIGNED               COMMENT 'ID del usuario sesión activa',
    `ip_address`     VARCHAR(45)                COMMENT 'IP del cliente (IPv4 o IPv6)',
    `fecha_cambio`   DATETIME         NOT NULL  DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    -- Índices para búsquedas frecuentes de auditoría
    INDEX `idx_tabla_registro` (`tabla`, `registro_id`),
    INDEX `idx_usuario_fecha`  (`usuario_id`, `fecha_cambio`),
    INDEX `idx_fecha`          (`fecha_cambio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Auditoría completa de todos los cambios. NO BORRAR REGISTROS.';


-- ============================================================
-- TABLA: usuarios
-- Almacena los usuarios del sistema con su rol base.
-- Los permisos detallados por módulo están en permisos_modulos.
-- ============================================================
CREATE TABLE IF NOT EXISTS `usuarios` (
    `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `nombre`        VARCHAR(100)  NOT NULL,
    `email`         VARCHAR(150)  NOT NULL UNIQUE,
    -- bcrypt hash (cost 12 mínimo). NUNCA almacenar texto plano.
    `password_hash` VARCHAR(255)  NOT NULL,
    `rol`           ENUM('superadmin','admin','empleado') NOT NULL DEFAULT 'empleado',
    `activo`        TINYINT(1)    NOT NULL DEFAULT 1,
    `ultimo_login`  DATETIME          NULL,
    -- Metadatos de auditoría
    `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by`    INT UNSIGNED      NULL COMMENT 'superadmin que creó este usuario',
    `updated_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by`    INT UNSIGNED      NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_email`  (`email`),
    INDEX `idx_rol`    (`rol`),
    INDEX `idx_activo` (`activo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Usuarios del sistema. Roles base; permisos finos en permisos_modulos.';


-- ============================================================
-- TABLA: permisos_modulos
-- Una fila por combinación usuario+módulo.
-- El middleware PHP consulta esta tabla en CADA request.
-- ============================================================
CREATE TABLE IF NOT EXISTS `permisos_modulos` (
    `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `usuario_id`    INT UNSIGNED  NOT NULL,
    `modulo`        ENUM('ventas','compras','inventario','nomina','recetario','activos','reportes') NOT NULL,
    -- Jerarquía: sin_acceso < solo_ver < solo_propios < editar_existentes < admin_total
    `nivel_acceso`  ENUM('sin_acceso','solo_ver','solo_propios','editar_existentes','admin_total')
                    NOT NULL DEFAULT 'sin_acceso',
    `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by`    INT UNSIGNED      NULL,
    `updated_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by`    INT UNSIGNED      NULL,
    PRIMARY KEY (`id`),
    -- Un usuario solo puede tener UN nivel por módulo
    UNIQUE KEY `uk_usuario_modulo` (`usuario_id`, `modulo`),
    FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Permisos granulares por módulo. Consultado en cada request del middleware.';


-- ============================================================
-- TABLA: configuracion_negocio
-- Parámetros maestros del negocio.
-- Se actualizan cada año (decreto salario mínimo) o cuando cambian costos.
-- TODOS los cambios quedan en logs_historial via trigger.
-- ============================================================
CREATE TABLE IF NOT EXISTS `configuracion_negocio` (
    `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `clave`       VARCHAR(100)    NOT NULL UNIQUE COMMENT 'Identificador programático del parámetro',
    `valor`       DECIMAL(15,4)   NOT NULL         COMMENT 'Valor numérico en pesos o porcentaje',
    `descripcion` VARCHAR(255)    NOT NULL,
    `categoria`   ENUM('nomina','costos','produccion','impuestos') NOT NULL,
    `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by`  INT UNSIGNED        NULL,
    `updated_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by`  INT UNSIGNED        NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_categoria` (`categoria`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Parámetros maestros del negocio (salario mínimo, porcentajes prestacionales, etc.)';


-- ============================================================
-- TABLA: proveedores
-- ============================================================
CREATE TABLE IF NOT EXISTS `proveedores` (
    `id`        INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `nombre`    VARCHAR(150)  NOT NULL,
    `contacto`  VARCHAR(100)      NULL,
    `telefono`  VARCHAR(20)       NULL,
    `notas`     TEXT              NULL,
    `activo`    TINYINT(1)    NOT NULL DEFAULT 1,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by` INT UNSIGNED     NULL,
    `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by` INT UNSIGNED     NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Proveedores de insumos.';


-- ============================================================
-- TABLA: insumos
-- Inventario maestro de materias primas.
-- costo_actual se actualiza con cada compra y dispara recálculo de recetas.
-- ============================================================
CREATE TABLE IF NOT EXISTS `insumos` (
    `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `nombre`          VARCHAR(150)    NOT NULL,
    `unidad_medida`   ENUM('kg','g','litro','ml','unidad','loncha','lata','paquete') NOT NULL,
    -- Precio por unidad de medida. Se actualiza al registrar compras.
    `costo_actual`    DECIMAL(12,4)   NOT NULL DEFAULT 0,
    `stock_actual`    DECIMAL(12,4)   NOT NULL DEFAULT 0 COMMENT 'En la unidad definida',
    -- Nivel mínimo antes de aparecer en Lista de Compras Inteligente
    `stock_seguridad` DECIMAL(12,4)   NOT NULL DEFAULT 0,
    `proveedor_id`    INT UNSIGNED        NULL,
    `activo`          TINYINT(1)      NOT NULL DEFAULT 1,
    `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by`      INT UNSIGNED        NULL,
    `updated_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by`      INT UNSIGNED        NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_stock_bajo` (`stock_actual`, `stock_seguridad`), -- para lista de compras
    FOREIGN KEY (`proveedor_id`) REFERENCES `proveedores`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Inventario maestro de insumos. Cambios de costo disparan recálculo de productos.';


-- ============================================================
-- TABLA: productos
-- Catálogo de sándwiches y combos vendibles.
-- costo_calculado se recalcula automáticamente cuando cambia el costo de un insumo.
-- ============================================================
CREATE TABLE IF NOT EXISTS `productos` (
    `id`               INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `nombre`           VARCHAR(150)    NOT NULL,
    `descripcion`      TEXT                NULL,
    `categoria`        ENUM('sandwich','combo','bebida','adicional') NOT NULL DEFAULT 'sandwich',
    `tamano`           ENUM('XL','L','unico') NOT NULL DEFAULT 'unico',
    `precio_venta`     DECIMAL(10,2)   NOT NULL,
    -- Se recalcula sumando (insumo.costo_actual × receta.cantidad_requerida) por cada ingrediente
    `costo_calculado`  DECIMAL(10,4)       NULL COMMENT 'Suma del costo de ingredientes según receta',
    `activo`           TINYINT(1)      NOT NULL DEFAULT 1,
    `created_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by`       INT UNSIGNED        NULL,
    `updated_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by`       INT UNSIGNED        NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_activo_categoria` (`activo`, `categoria`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Catálogo de productos. costo_calculado refleja el costo real de ingredientes.';


-- ============================================================
-- TABLA: recetas
-- Ingredientes requeridos por producto con sus cantidades.
-- Es_insumo_critico determina qué insumo limita la capacidad de producción.
-- Fórmula: Capacidad = stock_actual / cantidad_requerida (para el insumo crítico)
-- ============================================================
CREATE TABLE IF NOT EXISTS `recetas` (
    `id`                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `producto_id`         INT UNSIGNED    NOT NULL,
    `insumo_id`           INT UNSIGNED    NOT NULL,
    -- Ejemplo: 0.0909 kg para pollo (90.9g = 1kg/11 unidades)
    `cantidad_requerida`  DECIMAL(12,6)   NOT NULL COMMENT 'En la unidad de medida del insumo',
    -- Solo 1 insumo crítico por producto (el que más restringe la producción)
    `es_insumo_critico`   TINYINT(1)      NOT NULL DEFAULT 0,
    `created_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by`          INT UNSIGNED        NULL,
    `updated_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by`          INT UNSIGNED        NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_producto_insumo` (`producto_id`, `insumo_id`),
    FOREIGN KEY (`producto_id`) REFERENCES `productos`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`insumo_id`)   REFERENCES `insumos`(`id`)   ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Recetario: ingredientes y cantidades por producto. Base del costeo dinámico.';


-- ============================================================
-- TABLA: clientes
-- Clientes registrados, necesario para gestión de fiado.
-- saldo_fiado se incrementa con ventas de tipo fiado y se decrementa con pagos.
-- ============================================================
CREATE TABLE IF NOT EXISTS `clientes` (
    `id`           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `nombre`       VARCHAR(150)    NOT NULL,
    `telefono`     VARCHAR(20)         NULL,
    -- Saldo positivo = cliente debe dinero. NUNCA puede ser negativo.
    `saldo_fiado`  DECIMAL(12,2)   NOT NULL DEFAULT 0 COMMENT 'Deuda acumulada pendiente',
    `activo`       TINYINT(1)      NOT NULL DEFAULT 1,
    `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by`   INT UNSIGNED        NULL,
    `updated_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by`   INT UNSIGNED        NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Clientes. saldo_fiado lleva la cuenta de deudas pendientes.';


-- ============================================================
-- TABLA: ventas
-- Encabezado de cada transacción del POS.
-- Cada INSERT dispara descuento de stock via la capa de aplicación PHP.
-- ============================================================
CREATE TABLE IF NOT EXISTS `ventas` (
    `id`            BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    `fecha_venta`   DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- NULL = cliente mostrador / anónimo
    `cliente_id`    INT UNSIGNED          NULL,
    `metodo_pago`   ENUM('efectivo','nequi','daviplata','bancolombia','fiado') NOT NULL,
    `total`         DECIMAL(12,2)     NOT NULL DEFAULT 0,
    `notas`         TEXT                  NULL,
    -- completada=stock ya descontado; anulada=stock revertido; pendiente_pago=fiado sin cerrar
    `estado`        ENUM('completada','anulada','pendiente_pago') NOT NULL DEFAULT 'completada',
    `created_at`    DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by`    INT UNSIGNED          NULL COMMENT 'Empleado que registró la venta',
    `updated_at`    DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by`    INT UNSIGNED          NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_fecha`       (`fecha_venta`),
    INDEX `idx_metodo_pago` (`metodo_pago`),
    INDEX `idx_estado`      (`estado`),
    FOREIGN KEY (`cliente_id`) REFERENCES `clientes`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Encabezado POS. Anulaciones requieren permiso admin_total y revierten stock.';


-- ============================================================
-- TABLA: venta_detalles
-- Líneas de cada venta. Al insertar, PHP descuenta stock de insumos.
-- precio_unitario almacena el precio HISTÓRICO al momento de la venta.
-- ============================================================
CREATE TABLE IF NOT EXISTS `venta_detalles` (
    `id`              BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    `venta_id`        BIGINT UNSIGNED   NOT NULL,
    `producto_id`     INT UNSIGNED      NOT NULL,
    `cantidad`        SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    -- Precio congelado al momento de venta (no cambia si sube el precio después)
    `precio_unitario` DECIMAL(10,2)     NOT NULL,
    `subtotal`        DECIMAL(12,2)     NOT NULL COMMENT 'cantidad × precio_unitario',
    `created_at`      DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by`      INT UNSIGNED          NULL,
    `updated_at`      DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by`      INT UNSIGNED          NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_venta` (`venta_id`),
    FOREIGN KEY (`venta_id`)    REFERENCES `ventas`(`id`)   ON DELETE CASCADE,
    FOREIGN KEY (`producto_id`) REFERENCES `productos`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Líneas del POS. precio_unitario es histórico (snapshot al momento de venta).';


-- ============================================================
-- TABLA: compras
-- Encabezado de compras de insumos.
-- Al confirmar una compra, PHP actualiza insumos.costo_actual y stock_actual.
-- ============================================================
CREATE TABLE IF NOT EXISTS `compras` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `fecha_compra`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `proveedor_id`  INT UNSIGNED        NULL,
    `total`         DECIMAL(12,2)   NOT NULL DEFAULT 0,
    `notas`         TEXT                NULL,
    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by`    INT UNSIGNED        NULL,
    `updated_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by`    INT UNSIGNED        NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_fecha` (`fecha_compra`),
    FOREIGN KEY (`proveedor_id`) REFERENCES `proveedores`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Encabezado de compras. Al confirmar, actualiza costo y stock de insumos.';


-- ============================================================
-- TABLA: compra_detalles
-- Líneas de cada compra. precio_unitario aquí actualiza insumos.costo_actual.
-- ============================================================
CREATE TABLE IF NOT EXISTS `compra_detalles` (
    `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `compra_id`       INT UNSIGNED    NOT NULL,
    `insumo_id`       INT UNSIGNED    NOT NULL,
    `cantidad`        DECIMAL(12,4)   NOT NULL,
    -- Este precio se convierte en el nuevo costo_actual del insumo
    `precio_unitario` DECIMAL(12,4)   NOT NULL,
    `subtotal`        DECIMAL(12,2)   NOT NULL,
    `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by`      INT UNSIGNED        NULL,
    `updated_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by`      INT UNSIGNED        NULL,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`compra_id`) REFERENCES `compras`(`id`)  ON DELETE CASCADE,
    FOREIGN KEY (`insumo_id`) REFERENCES `insumos`(`id`)  ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Líneas de compra. precio_unitario propaga actualización a insumos.costo_actual.';


-- ============================================================
-- TABLA: empleados
-- Registro maestro del personal.
-- Vinculado a usuarios para quienes tienen acceso al sistema.
-- ============================================================
CREATE TABLE IF NOT EXISTS `empleados` (
    `id`                    INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    -- NULL si el empleado no tiene usuario en el sistema
    `usuario_id`            INT UNSIGNED        NULL,
    `nombre_completo`       VARCHAR(200)    NOT NULL,
    `documento_identidad`   VARCHAR(20)         NULL UNIQUE,
    `cargo`                 VARCHAR(100)        NULL,
    `fecha_ingreso`         DATE            NOT NULL,
    `salario_base`          DECIMAL(12,2)   NOT NULL,
    -- Aplica aux. transporte si salario ≤ 2 SMLMV (verificar por ley cada año)
    `aplica_aux_transporte` TINYINT(1)      NOT NULL DEFAULT 1,
    `activo`                TINYINT(1)      NOT NULL DEFAULT 1,
    `created_at`            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by`            INT UNSIGNED        NULL,
    `updated_at`            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by`            INT UNSIGNED        NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_activo` (`activo`),
    FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Personal de ClanDestino. Vinculable a usuarios del sistema.';


-- ============================================================
-- TABLA: nomina_liquidaciones
-- Una fila = una liquidación mensual por empleado.
-- Almacena cada componente de la carga prestacional para trazabilidad.
-- Fórmula total: ver claude.md sección 4.1
-- ============================================================
CREATE TABLE IF NOT EXISTS `nomina_liquidaciones` (
    `id`                   INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `empleado_id`          INT UNSIGNED    NOT NULL,
    `periodo_mes`          TINYINT UNSIGNED NOT NULL  COMMENT '1=Enero ... 12=Diciembre',
    `periodo_anio`         YEAR            NOT NULL,
    -- Valores base del período
    `salario_base`         DECIMAL(12,2)   NOT NULL,
    `aux_transporte`       DECIMAL(10,2)   NOT NULL DEFAULT 0,
    -- Aportes parafiscales a cargo del empleador
    `salud_empleador`      DECIMAL(10,2)   NOT NULL DEFAULT 0 COMMENT '8.5% del salario',
    `pension_empleador`    DECIMAL(10,2)   NOT NULL DEFAULT 0 COMMENT '12% del salario',
    `arl`                  DECIMAL(10,2)   NOT NULL DEFAULT 0 COMMENT '0.522% del salario',
    `caja_compensacion`    DECIMAL(10,2)   NOT NULL DEFAULT 0 COMMENT '4% del salario',
    `icbf`                 DECIMAL(10,2)   NOT NULL DEFAULT 0 COMMENT '3% del salario',
    `sena`                 DECIMAL(10,2)   NOT NULL DEFAULT 0 COMMENT '2% del salario',
    -- Provisiones mensuales (derechos del trabajador)
    `prima`                DECIMAL(10,2)   NOT NULL DEFAULT 0 COMMENT '8.33% del salario',
    `cesantias`            DECIMAL(10,2)   NOT NULL DEFAULT 0 COMMENT '8.33% del salario',
    `intereses_cesantias`  DECIMAL(10,2)   NOT NULL DEFAULT 0 COMMENT '1% del salario',
    `vacaciones`           DECIMAL(10,2)   NOT NULL DEFAULT 0 COMMENT '4.17% del salario',
    -- Totales calculados
    `total_cargas`         DECIMAL(12,2)   NOT NULL DEFAULT 0 COMMENT 'Suma de aportes parafiscales',
    `total_provisiones`    DECIMAL(12,2)   NOT NULL DEFAULT 0 COMMENT 'Suma de provisiones',
    -- Lo que ClanDestino REALMENTE paga por este empleado en el período
    `costo_total_empleador` DECIMAL(12,2)  NOT NULL DEFAULT 0,
    `created_at`           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by`           INT UNSIGNED        NULL,
    `updated_at`           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by`           INT UNSIGNED        NULL,
    PRIMARY KEY (`id`),
    -- Un empleado tiene máximo una liquidación por período
    UNIQUE KEY `uk_empleado_periodo` (`empleado_id`, `periodo_mes`, `periodo_anio`),
    FOREIGN KEY (`empleado_id`) REFERENCES `empleados`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Liquidaciones mensuales. costo_total_empleador refleja el costo real para ClanDestino.';


-- ============================================================
-- TABLA: activos
-- Herramientas y equipos sujetos a depreciación.
-- Depreciación diaria = (costo_inicial / vida_util_meses) / 30.4
-- ============================================================
CREATE TABLE IF NOT EXISTS `activos` (
    `id`                   INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `nombre`               VARCHAR(150)    NOT NULL,
    `descripcion`          TEXT                NULL,
    `costo_inicial`        DECIMAL(12,2)   NOT NULL,
    `fecha_adquisicion`    DATE            NOT NULL,
    -- Default 12 meses según spec. Configurable por activo.
    `vida_util_meses`      TINYINT UNSIGNED NOT NULL DEFAULT 12,
    -- Calculado al insertar/actualizar: costo_inicial / vida_util_meses
    `depreciacion_mensual` DECIMAL(12,4)       NULL,
    -- Calculado al insertar/actualizar: depreciacion_mensual / 30.4
    `depreciacion_diaria`  DECIMAL(12,6)       NULL,
    `activo`               TINYINT(1)      NOT NULL DEFAULT 1,
    `created_at`           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by`           INT UNSIGNED        NULL,
    `updated_at`           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by`           INT UNSIGNED        NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Activos fijos. Depreciación diaria suma al costo operativo diario del negocio.';


-- ============================================================
-- TABLA: pagos_fiado
-- Abonos a deudas existentes. Reduce clientes.saldo_fiado.
-- ============================================================
CREATE TABLE IF NOT EXISTS `pagos_fiado` (
    `id`           INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `cliente_id`   INT UNSIGNED    NOT NULL,
    `monto`        DECIMAL(10,2)   NOT NULL COMMENT 'Monto abonado (positivo)',
    `metodo_pago`  ENUM('efectivo','nequi','daviplata','bancolombia') NOT NULL DEFAULT 'efectivo',
    `notas`        TEXT                NULL,
    `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by`   INT UNSIGNED        NULL,
    `updated_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by`   INT UNSIGNED        NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_cliente` (`cliente_id`),
    FOREIGN KEY (`cliente_id`) REFERENCES `clientes`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Abonos a deudas de fiado. Cada INSERT reduce clientes.saldo_fiado.';


-- ============================================================
-- ============================================================
-- TRIGGERS DE AUDITORÍA
-- Propósito: Registrar cambios en campos sensibles de forma
-- inamovible (no se puede bypass desde la aplicación PHP).
-- NOTA: Ejecutar en MySQL client o phpMyAdmin > SQL
-- ============================================================
-- ============================================================

DELIMITER $$

-- ----------------------------------------------------------
-- TRIGGER: Auditoría de configuracion_negocio
-- Se dispara cuando alguien cambia un parámetro financiero.
-- Registra el valor anterior vs nuevo para detectar fraudes o errores.
-- ----------------------------------------------------------
DROP TRIGGER IF EXISTS `trg_config_negocio_audit`$$
CREATE TRIGGER `trg_config_negocio_audit`
AFTER UPDATE ON `configuracion_negocio`
FOR EACH ROW
BEGIN
    -- Solo registrar si el valor realmente cambió (evitar log de updates sin cambio)
    IF OLD.valor != NEW.valor THEN
        INSERT INTO `logs_historial`
            (`tabla`, `registro_id`, `campo`, `valor_anterior`, `valor_nuevo`, `accion`, `fecha_cambio`)
        VALUES
            ('configuracion_negocio', NEW.id, 'valor', OLD.valor, NEW.valor, 'UPDATE', NOW());
    END IF;
END$$


-- ----------------------------------------------------------
-- TRIGGER: Auditoría de insumos (costo y stock)
-- Registra cambios de precio y de stock para trazabilidad de inventario.
-- Un cambio de costo inesperado puede indicar error de captura o fraude.
-- ----------------------------------------------------------
DROP TRIGGER IF EXISTS `trg_insumos_audit`$$
CREATE TRIGGER `trg_insumos_audit`
AFTER UPDATE ON `insumos`
FOR EACH ROW
BEGIN
    -- Log de cambio de costo
    IF OLD.costo_actual != NEW.costo_actual THEN
        INSERT INTO `logs_historial`
            (`tabla`, `registro_id`, `campo`, `valor_anterior`, `valor_nuevo`, `accion`, `fecha_cambio`)
        VALUES
            ('insumos', NEW.id, 'costo_actual', OLD.costo_actual, NEW.costo_actual, 'UPDATE', NOW());
    END IF;

    -- Log de cambio de stock (permite detectar mermas o pérdidas)
    IF OLD.stock_actual != NEW.stock_actual THEN
        INSERT INTO `logs_historial`
            (`tabla`, `registro_id`, `campo`, `valor_anterior`, `valor_nuevo`, `accion`, `fecha_cambio`)
        VALUES
            ('insumos', NEW.id, 'stock_actual', OLD.stock_actual, NEW.stock_actual, 'UPDATE', NOW());
    END IF;
END$$


-- ----------------------------------------------------------
-- TRIGGER: Auditoría de productos (precio de venta)
-- Registra cada cambio de precio para análisis histórico y auditoría.
-- ----------------------------------------------------------
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


-- ----------------------------------------------------------
-- TRIGGER: Auditoría de ventas (cambio de estado)
-- Registra anulaciones y cambios de estado para control de fraude.
-- Una venta anulada fraudulentamente quedaría registrada aquí.
-- ----------------------------------------------------------
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


-- ----------------------------------------------------------
-- TRIGGER: Auditoría de empleados (salario)
-- Un cambio de salario no autorizado queda registrado con timestamp.
-- ----------------------------------------------------------
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


-- ----------------------------------------------------------
-- TRIGGER: Depreciación automática en activos
-- Calcula depreciacion_mensual y depreciacion_diaria al insertar o actualizar.
-- Evita que la capa PHP tenga que recordar la fórmula.
-- ----------------------------------------------------------
DROP TRIGGER IF EXISTS `trg_activos_deprec_insert`$$
CREATE TRIGGER `trg_activos_deprec_insert`
BEFORE INSERT ON `activos`
FOR EACH ROW
BEGIN
    SET NEW.depreciacion_mensual = NEW.costo_inicial / NEW.vida_util_meses;
    SET NEW.depreciacion_diaria  = (NEW.costo_inicial / NEW.vida_util_meses) / 30.4;
END$$

DROP TRIGGER IF EXISTS `trg_activos_deprec_update`$$
CREATE TRIGGER `trg_activos_deprec_update`
BEFORE UPDATE ON `activos`
FOR EACH ROW
BEGIN
    IF OLD.costo_inicial != NEW.costo_inicial OR OLD.vida_util_meses != NEW.vida_util_meses THEN
        SET NEW.depreciacion_mensual = NEW.costo_inicial / NEW.vida_util_meses;
        SET NEW.depreciacion_diaria  = (NEW.costo_inicial / NEW.vida_util_meses) / 30.4;
    END IF;
END$$


DELIMITER ;


-- ============================================================
-- ============================================================
-- DATOS INICIALES (PRE-CARGA)
-- ============================================================
-- ============================================================

-- ----------------------------------------------------------
-- Parámetros financieros del negocio (Decreto 2025 / Spec v4.0)
-- ----------------------------------------------------------
INSERT INTO `configuracion_negocio` (`clave`, `valor`, `descripcion`, `categoria`) VALUES
-- Nómina
('salario_minimo',           1750905.0000, 'Salario Mínimo Legal Mensual Vigente (SMLMV) 2026',      'nomina'),
('aux_transporte',            249095.0000, 'Auxilio de Transporte Mensual Legal 2026',               'nomina'),
-- Cargas empleador
('pct_salud_empleador',            8.5000, 'Salud — aporte a cargo del empleador (%)',               'nomina'),
('pct_pension_empleador',         12.0000, 'Pensión — aporte a cargo del empleador (%)',             'nomina'),
('pct_arl',                        0.5220, 'ARL Riesgo Clase I — empleados de cocina/mostrador (%)', 'nomina'),
('pct_caja_compensacion',          4.0000, 'Caja de Compensación Familiar (%)',                     'nomina'),
('pct_icbf',                       3.0000, 'ICBF (%)',                                              'nomina'),
('pct_sena',                       2.0000, 'SENA (%)',                                              'nomina'),
-- Provisiones
('pct_prima',                      8.3300, 'Prima de Servicios — provisión mensual (%)',             'nomina'),
('pct_cesantias',                  8.3300, 'Cesantías — provisión mensual (%)',                     'nomina'),
('pct_intereses_cesantias',        1.0000, 'Intereses sobre Cesantías — provisión mensual (%)',      'nomina'),
('pct_vacaciones',                 4.1700, 'Vacaciones — provisión mensual (%)',                     'nomina'),
-- Costos operativos
('costos_fijos_mensuales',       365185.0000, 'Arriendo + servicios básicos mensuales',             'costos'),
('produccion_estimada_mensual',    2175.0000, 'Unidades/mes para prorratear costos fijos',          'produccion');


-- ----------------------------------------------------------
-- Insumos base del negocio
-- Rendimiento de referencia: 1kg pollo = 11u, 1kg carne = 10u, 6 latas atún = 9u
-- ----------------------------------------------------------
INSERT INTO `insumos` (`nombre`, `unidad_medida`, `costo_actual`, `stock_actual`, `stock_seguridad`) VALUES
('Pollo Desmechado', 'kg',      17325.0000, 0.000, 2.000),
('Carne de Res',     'kg',      27000.0000, 0.000, 1.000),
('Atún Lata 160g',   'lata',     2995.0000, 0.000, 6.000),
('Jamón (loncha)',   'loncha',      0.0000, 0.000, 28.000), -- costo variable: se actualiza por compra
('Pan',              'unidad',   1500.0000, 0.000, 50.000);


-- ----------------------------------------------------------
-- Productos del catálogo
-- Tamaño XL es el sándwich grande; L es el regular
-- ----------------------------------------------------------
INSERT INTO `productos` (`nombre`, `categoria`, `tamano`, `precio_venta`) VALUES
('Sándwich de Pollo XL',    'sandwich', 'XL',    0.00),
('Sándwich de Pollo L',     'sandwich', 'L',     0.00),
('Sándwich de Carne XL',    'sandwich', 'XL',    0.00),
('Sándwich de Carne L',     'sandwich', 'L',     0.00),
('Sándwich de Atún XL',     'sandwich', 'XL',    0.00),
('Sándwich de Atún L',      'sandwich', 'L',     0.00),
('Sándwich de Jamón XL',    'sandwich', 'XL',    0.00),
('Sándwich de Jamón L',     'sandwich', 'L',     0.00);
-- NOTA: precio_venta = 0 hasta que el administrador defina precios en el panel


-- ----------------------------------------------------------
-- Recetario base
-- Cantidades en la unidad de medida del insumo.
-- Pollo:  90.9g = 0.0909 kg por sándwich (1kg rinde 11 unidades)
-- Carne: 100.0g = 0.1000 kg por sándwich (1kg rinde 10 unidades)
-- Atún:  0.6667 latas por sándwich (6 latas rinden 9 unidades = 0.6667)
-- Jamón: 2 lonchas por sándwich (14 lonchas rinden 7 unidades)
-- Pan:   1 unidad por sándwich
-- ----------------------------------------------------------
-- Sándwich de Pollo XL (id=1)
INSERT INTO `recetas` (`producto_id`, `insumo_id`, `cantidad_requerida`, `es_insumo_critico`) VALUES
(1, 1, 0.090909, 1), -- pollo crítico
(1, 5, 1.000000, 0); -- pan

-- Sándwich de Pollo L (id=2)
INSERT INTO `recetas` (`producto_id`, `insumo_id`, `cantidad_requerida`, `es_insumo_critico`) VALUES
(2, 1, 0.090909, 1),
(2, 5, 1.000000, 0);

-- Sándwich de Carne XL (id=3)
INSERT INTO `recetas` (`producto_id`, `insumo_id`, `cantidad_requerida`, `es_insumo_critico`) VALUES
(3, 2, 0.100000, 1), -- carne crítica
(3, 5, 1.000000, 0);

-- Sándwich de Carne L (id=4)
INSERT INTO `recetas` (`producto_id`, `insumo_id`, `cantidad_requerida`, `es_insumo_critico`) VALUES
(4, 2, 0.100000, 1),
(4, 5, 1.000000, 0);

-- Sándwich de Atún XL (id=5)
INSERT INTO `recetas` (`producto_id`, `insumo_id`, `cantidad_requerida`, `es_insumo_critico`) VALUES
(5, 3, 0.666667, 1), -- atún crítico
(5, 5, 1.000000, 0);

-- Sándwich de Atún L (id=6)
INSERT INTO `recetas` (`producto_id`, `insumo_id`, `cantidad_requerida`, `es_insumo_critico`) VALUES
(6, 3, 0.666667, 1),
(6, 5, 1.000000, 0);

-- Sándwich de Jamón XL (id=7)
INSERT INTO `recetas` (`producto_id`, `insumo_id`, `cantidad_requerida`, `es_insumo_critico`) VALUES
(7, 4, 2.000000, 1), -- jamón crítico
(7, 5, 1.000000, 0);

-- Sándwich de Jamón L (id=8)
INSERT INTO `recetas` (`producto_id`, `insumo_id`, `cantidad_requerida`, `es_insumo_critico`) VALUES
(8, 4, 2.000000, 1),
(8, 5, 1.000000, 0);


-- ----------------------------------------------------------
-- Usuario Super Administrador inicial
-- CONTRASEÑA DEFAULT: Admin2026!
-- ACCIÓN OBLIGATORIA: Cambiar contraseña en el PRIMER login.
-- Hash bcrypt generado con cost=12. Regenerar en PHP:
--   password_hash('Admin2026!', PASSWORD_BCRYPT, ['cost' => 12])
-- ----------------------------------------------------------
INSERT INTO `usuarios` (`nombre`, `email`, `password_hash`, `rol`) VALUES
(
    'Super Administrador',
    'admin@clandestino.local',
    '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- placeholder hash
    'superadmin'
);
-- Asignar permisos admin_total a todos los módulos para el superadmin
INSERT INTO `permisos_modulos` (`usuario_id`, `modulo`, `nivel_acceso`) VALUES
(1, 'ventas',      'admin_total'),
(1, 'compras',     'admin_total'),
(1, 'inventario',  'admin_total'),
(1, 'nomina',      'admin_total'),
(1, 'recetario',   'admin_total'),
(1, 'activos',     'admin_total'),
(1, 'reportes',    'admin_total');


-- Restaurar verificación de FK
SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- VERIFICACIÓN POST-INSTALACIÓN
-- Ejecutar estas queries para confirmar que el schema quedó correcto:
-- ============================================================
-- SELECT COUNT(*) AS tablas_creadas FROM information_schema.TABLES
--   WHERE TABLE_SCHEMA = DATABASE();
-- -- Resultado esperado: 14 tablas
--
-- SELECT COUNT(*) AS triggers_activos FROM information_schema.TRIGGERS
--   WHERE TRIGGER_SCHEMA = DATABASE();
-- -- Resultado esperado: 7 triggers
--
-- SELECT clave, valor FROM configuracion_negocio ORDER BY categoria, clave;
-- -- Resultado esperado: 14 parámetros de configuración
-- ============================================================
-- FIN DEL SCRIPT — ClanDestino ERP v4.0
-- ============================================================
