-- ============================================================
-- Migración 036 — Ingrediente base en recetas
-- ClanDestino ERP v4.40 | 2026-06-06
--
-- Añade la columna es_base a recetas para marcar ingredientes
-- cuya cantidad NO escala con el factor de variante de tamaño.
-- Ejemplo: el pan siempre es 1 unidad (base); la proteína sí
-- escala (es_base = 0, comportamiento por defecto).
--
-- Backward-compatible: DEFAULT 0 → todas las recetas existentes
-- siguen comportándose igual (escalan con factor_receta).
-- ============================================================

ALTER TABLE `recetas`
    ADD COLUMN IF NOT EXISTS `es_base` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Cantidad fija — no escala con factor_receta de variante'
    AFTER `es_insumo_critico`;
