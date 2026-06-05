-- ============================================================
-- Migración 015 — Productos: producción y stock de producto terminado
--
-- Agrega:
--   productos.unidades_por_receta → cuántos sándwiches produce una tanda completa
--   productos.stock_disponible    → inventario de producto terminado
--   productos.stock_minimo        → umbral de alerta de stock bajo
--   venta_detalles.from_stock     → indica si la venta descontó del stock terminado
--   tabla produccion_lotes        → registro de tandas de producción
--
-- IMPORTANTE: Cambiar 'clandestinoERP' por el nombre real de tu DB
-- ============================================================
USE `clandestinoERP`;

-- ── 1. Campos nuevos en productos ────────────────────────────────────────────
ALTER TABLE `productos`
  ADD COLUMN IF NOT EXISTS `unidades_por_receta` SMALLINT UNSIGNED NOT NULL DEFAULT 1
    COMMENT 'Sándwiches que produce una tanda completa de la receta (÷ para costo/u)'
    AFTER `costo_calculado`,
  ADD COLUMN IF NOT EXISTS `stock_disponible` INT NOT NULL DEFAULT 0
    COMMENT 'Unidades de producto terminado disponibles para venta'
    AFTER `unidades_por_receta`,
  ADD COLUMN IF NOT EXISTS `stock_minimo` INT NOT NULL DEFAULT 0
    COMMENT 'Umbral de alerta: si stock_disponible < stock_minimo → badge rojo'
    AFTER `stock_disponible`;

-- ── 2. Campo en venta_detalles para rastrear origen del descuento ─────────────
ALTER TABLE `venta_detalles`
  ADD COLUMN IF NOT EXISTS `from_stock` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1 = descontado de stock de producto terminado; 0 = descontado de insumos'
    AFTER `subtotal`;

-- ── 3. Tabla de producción por tandas ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `produccion_lotes` (
    `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `producto_id`      INT UNSIGNED  NOT NULL,
    `fecha_produccion` DATE          NOT NULL COMMENT 'Día en que se produjo la tanda',
    `cantidad`         INT UNSIGNED  NOT NULL COMMENT 'Sándwiches producidos',
    `costo_unitario`   DECIMAL(10,4)     NULL COMMENT 'Costo/sándwich al momento de producción (snapshot)',
    `notas`            TEXT              NULL,
    `estado`           ENUM('activo','anulado') NOT NULL DEFAULT 'activo',
    `created_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by`       INT UNSIGNED      NULL,
    `updated_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by`       INT UNSIGNED      NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_pl_producto_fecha` (`producto_id`, `fecha_produccion`),
    INDEX `idx_pl_fecha`          (`fecha_produccion`),
    CONSTRAINT `fk_pl_producto`
        FOREIGN KEY (`producto_id`) REFERENCES `productos`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Registro de tandas de producción de producto terminado';

-- ── 4. Recalcular costo_calculado dividiendo por unidades_por_receta ──────────
-- (Con DEFAULT 1, todos los productos existentes quedan igual)
UPDATE `productos` p
SET p.`costo_calculado` = IFNULL((
    SELECT SUM(i.`costo_actual` * r.`cantidad_requerida`)
    FROM `recetas` r
    JOIN `insumos` i ON i.`id` = r.`insumo_id`
    WHERE r.`producto_id` = p.`id`
), 0) / GREATEST(p.`unidades_por_receta`, 1)
WHERE p.`activo` = 1;

-- ============================================================
-- Verificar:
-- SELECT nombre, unidades_por_receta, stock_disponible, stock_minimo,
--        costo_calculado FROM productos;
-- DESCRIBE produccion_lotes;
-- ============================================================
