-- ============================================================
-- Migración 011b — Activos: agregar proveedor_id (FK a proveedores)
-- Permite vincular cada activo con el proveedor donde se compró,
-- en lugar de solo guardar el nombre como texto libre.
-- ============================================================

-- Agregar proveedor_id como FK opcional a activos
ALTER TABLE `activos`
  ADD COLUMN IF NOT EXISTS `proveedor_id` INT UNSIGNED NULL
    COMMENT 'Proveedor donde se adquirió el activo (FK a proveedores)'
    AFTER `lugar_compra`,
  ADD FOREIGN KEY IF NOT EXISTS `fk_activos_proveedor`
    (`proveedor_id`) REFERENCES `proveedores`(`id`) ON DELETE SET NULL;

-- Intentar migrar datos: si lugar_compra coincide con nombre de proveedor, linkear
UPDATE `activos` a
JOIN   `proveedores` p ON LOWER(TRIM(p.nombre)) = LOWER(TRIM(a.lugar_compra))
SET    a.proveedor_id = p.id
WHERE  a.proveedor_id IS NULL AND a.lugar_compra IS NOT NULL AND a.lugar_compra != '';

-- ============================================================
-- Verificar:
-- SELECT a.nombre, a.lugar_compra, p.nombre AS proveedor_vinculado
-- FROM activos a LEFT JOIN proveedores p ON p.id = a.proveedor_id
-- ORDER BY a.nombre;
-- ============================================================
