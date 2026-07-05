-- ============================================================
-- Migración 013 — Costos: reemplaza empleado_id por clasificacion
-- Elimina la asignación a empleado (confusa) y agrega el campo
-- clasificacion (directo/indirecto) para análisis contable.
-- ============================================================

ALTER TABLE `costos_indirectos`
  DROP FOREIGN KEY `fk_ci_empleado`,
  DROP COLUMN `empleado_id`,
  ADD COLUMN `clasificacion`
    ENUM('directo','indirecto') NOT NULL DEFAULT 'indirecto'
    COMMENT 'directo=trazable a un producto; indirecto=costo general del negocio'
    AFTER `tipo`;

-- ============================================================
-- Verificar:
-- DESCRIBE costos_indirectos;
-- ============================================================
