-- ============================================================
-- Migración 044 — Snapshot de COSTO de venta (COGS) en venta_detalles
-- ============================================================
-- Base para un Estado de Resultados (P&G) y márgenes EXACTOS e inmutables.
-- Guarda el costo de receta por unidad AL MOMENTO DE VENDER (incluye los
-- insumos extra del combo si aplica), escalado por la variante.
--
-- venta_detalles.costo_unit_snap:
--   = productos.costo_calculado × factor_receta  (+ costo de combo_insumos por unidad)
--   NULL en ventas previas a esta migración → los reportes estiman con el
--   costo_calculado ACTUAL como fallback.
--
-- Idempotente: solo agrega la columna si no existe (por si se corre dos veces).
-- ============================================================


SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'venta_detalles'
                  AND COLUMN_NAME = 'costo_unit_snap');
SET @sql := IF(@existe = 0,
    'ALTER TABLE venta_detalles ADD COLUMN costo_unit_snap DECIMAL(12,4) DEFAULT NULL',
    'DO 0');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;
