-- ============================================================
-- Migración 014 — Empleados: agregar tipo_costo
-- Permite clasificar cada empleado como costo directo
-- (produce el producto) o indirecto (administración/soporte).
-- Esta clasificación alimenta el análisis de costos en el
-- módulo Costos y el cálculo de costo de producto en Productos.
-- ============================================================

ALTER TABLE `empleados`
  ADD COLUMN IF NOT EXISTS `tipo_costo`
    ENUM('directo','indirecto') NOT NULL DEFAULT 'indirecto'
    COMMENT 'directo = produce el producto (cocina); indirecto = soporte / administración'
    AFTER `aplica_aux_transporte`;

-- ============================================================
-- Verificar:
-- SELECT nombre_completo, cargo, tipo_costo FROM empleados;
-- ============================================================
