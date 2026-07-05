-- ============================================================
-- Migración 003 — Sprint 2: Nuevas columnas y renombrado de módulos
-- Ejecutar en phpMyAdmin DESPUÉS de schema.sql y 002_login_intentos.sql
-- ============================================================

-- (el mismo que pusiste en app/config/database.php)

SET NAMES utf8mb4;

-- ── 1. Renombrar enum 'recetario' → 'productos' en permisos_modulos ──────────
-- Primero ampliar el enum para que acepte ambos valores durante la transición
ALTER TABLE `permisos_modulos`
  MODIFY COLUMN `modulo`
    ENUM('ventas','compras','inventario','nomina','recetario','productos','activos','reportes')
    NOT NULL;

-- Migrar datos existentes
UPDATE `permisos_modulos` SET `modulo` = 'productos' WHERE `modulo` = 'recetario';

-- Ahora eliminar el valor 'recetario' del enum
ALTER TABLE `permisos_modulos`
  MODIFY COLUMN `modulo`
    ENUM('ventas','compras','inventario','nomina','productos','activos','reportes')
    NOT NULL;

-- ── 2. Nuevas columnas en tabla ventas ───────────────────────────────────────
ALTER TABLE `ventas`
  ADD COLUMN `fecha_pago`    DATETIME        NULL
    COMMENT 'Fecha real de pago. NULL = pendiente de cobro'
    AFTER `fecha_venta`,
  ADD COLUMN `es_combo`      TINYINT(1)      NOT NULL DEFAULT 0
    COMMENT '1 = incluye combo (bebida + papas)'
    AFTER `notas`,
  ADD COLUMN `tipo_sandwich` VARCHAR(100)    NULL
    COMMENT 'Nombre del sándwich vendido (denormalizado para historial)'
    AFTER `es_combo`;

-- ── 3. Nuevas columnas en tabla ventas_detalle: precio de lista ─────────────
ALTER TABLE `venta_detalles`
  ADD COLUMN `precio_lista`  DECIMAL(10,2)   NULL
    COMMENT 'Precio sugerido del módulo Productos en el momento de la venta'
    AFTER `precio_unitario`;

-- ── 4. Campo lugar_compra en tabla activos ────────────────────────────────────
ALTER TABLE `activos`
  ADD COLUMN `lugar_compra`  VARCHAR(150)    NULL
    COMMENT 'Tienda o proveedor donde se adquirió el activo'
    AFTER `descripcion`;

-- ── 5. Campo lugar_compra en tabla compras ────────────────────────────────────
ALTER TABLE `compras`
  ADD COLUMN `lugar_compra`  VARCHAR(150)    NULL
    COMMENT 'Tienda, plaza o proveedor donde se realizó la compra'
    AFTER `proveedor_id`;

-- ── 6. Tipo de contrato y horas en empleados ─────────────────────────────────
ALTER TABLE `empleados`
  ADD COLUMN `tipo_contrato` ENUM('tiempo_completo','medio_tiempo','por_horas','por_dias')
    NOT NULL DEFAULT 'tiempo_completo'
    COMMENT 'Modalidad de contratación para prorratear salario'
    AFTER `cargo`,
  ADD COLUMN `horas_semana`  TINYINT UNSIGNED NULL
    COMMENT 'Horas semanales pactadas (para contratos por horas)'
    AFTER `tipo_contrato`,
  ADD COLUMN `valor_hora`    DECIMAL(10,2)   NULL
    COMMENT 'Valor por hora si tipo_contrato = por_horas'
    AFTER `horas_semana`;

-- ── 7. Descuentos del empleado y estado de pago en nomina_liquidaciones ───────
ALTER TABLE `nomina_liquidaciones`
  -- Descuentos que se le deducen al empleado (no son costo del empleador)
  ADD COLUMN `salud_empleado`    DECIMAL(10,2) NOT NULL DEFAULT 0
    COMMENT 'Salud descontada al empleado: 4% del salario'
    AFTER `sena`,
  ADD COLUMN `pension_empleado`  DECIMAL(10,2) NOT NULL DEFAULT 0
    COMMENT 'Pensión descontada al empleado: 4% del salario'
    AFTER `salud_empleado`,
  -- Neto que efectivamente recibe el empleado en la mano
  ADD COLUMN `neto_pagado`       DECIMAL(12,2) NOT NULL DEFAULT 0
    COMMENT 'Salario + aux_transporte - salud_empleado - pension_empleado'
    AFTER `pension_empleado`,
  -- Estado de pago de la liquidación
  ADD COLUMN `pagado`            TINYINT(1)    NOT NULL DEFAULT 0
    COMMENT '0=pendiente, 1=pagado'
    AFTER `costo_total_empleador`,
  ADD COLUMN `fecha_pago_nomina` DATE          NULL
    COMMENT 'Fecha en que se efectuó el pago al empleado'
    AFTER `pagado`;

-- ── 8. Parámetros de descuento del empleado en configuracion_negocio ──────────
INSERT IGNORE INTO `configuracion_negocio` (`clave`, `valor`, `descripcion`, `categoria`) VALUES
('pct_salud_empleado',   4.0000, 'Salud — descuento al empleado (%) sobre salario base',   'nomina'),
('pct_pension_empleado', 4.0000, 'Pensión — descuento al empleado (%) sobre salario base', 'nomina');

-- ── 9. Índices para los nuevos campos de filtrado/orden ───────────────────────
ALTER TABLE `activos` ADD INDEX `idx_lugar_compra`     (`lugar_compra`);
ALTER TABLE `activos` ADD INDEX `idx_fecha_adq`        (`fecha_adquisicion`);
ALTER TABLE `compras` ADD INDEX `idx_compras_lugar`    (`lugar_compra`, `fecha_compra`);
ALTER TABLE `ventas`  ADD INDEX `idx_ventas_fecha_pago`(`fecha_pago`);
ALTER TABLE `nomina_liquidaciones` ADD INDEX `idx_nomina_pagado` (`pagado`, `periodo_anio`, `periodo_mes`);

-- ── 10. Tabla proveedores: agregar campo categoria y direccion ────────────────
ALTER TABLE `proveedores`
  ADD COLUMN `categoria`  VARCHAR(80) NULL
    COMMENT 'Tipo de proveedor: plaza, tienda, mayorista, D1, etc.'
    AFTER `nombre`,
  ADD COLUMN `direccion`  VARCHAR(200) NULL
    AFTER `telefono`;

-- ── 11. Tabla insumos: campo categoria para agrupar en lista de compras ───────
ALTER TABLE `insumos`
  ADD COLUMN `categoria` VARCHAR(80) NULL
    COMMENT 'Grupo del insumo: proteína, lácteo, vegetal, condimento, empaque, etc.'
    AFTER `nombre`;

-- ── 12. Superadmin: asignar permiso productos (ya lo tiene como admin_total) ──
-- Los usuarios con permiso 'recetario' ya fueron migrados a 'productos' en paso 1
-- Verificar que superadmin siga teniendo admin_total en todos los módulos
INSERT IGNORE INTO `permisos_modulos` (`usuario_id`, `modulo`, `nivel_acceso`)
SELECT 1, 'productos', 'admin_total'
WHERE NOT EXISTS (
    SELECT 1 FROM `permisos_modulos` WHERE `usuario_id` = 1 AND `modulo` = 'productos'
);

-- ============================================================
-- VERIFICACIÓN POST-EJECUCIÓN:
-- SELECT clave, valor FROM configuracion_negocio WHERE categoria = 'nomina' ORDER BY clave;
-- -- Debe mostrar 14 filas incluyendo pct_salud_empleado y pct_pension_empleado
--
-- DESCRIBE ventas;
-- -- Debe mostrar columnas: fecha_pago, es_combo, tipo_sandwich
--
-- DESCRIBE activos;
-- -- Debe mostrar columna: lugar_compra
-- ============================================================
