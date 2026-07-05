-- ============================================================
-- Migración 011 — Módulo Proveedores: enum permisos + campos extra
-- ============================================================

-- ── 1. Agregar 'proveedores' al enum de permisos_modulos ──────────────────────
ALTER TABLE `permisos_modulos`
  MODIFY COLUMN `modulo`
    ENUM('ventas','compras','inventario','nomina','productos','activos','reportes','proveedores')
    NOT NULL;

-- ── 2. Dar acceso admin_total al superadmin ───────────────────────────────────
INSERT IGNORE INTO `permisos_modulos` (`usuario_id`, `modulo`, `nivel_acceso`)
VALUES (1, 'proveedores', 'admin_total');

-- ── 3. Campos adicionales en proveedores ─────────────────────────────────────
ALTER TABLE `proveedores`
  ADD COLUMN IF NOT EXISTS `email`     VARCHAR(150) NULL
    COMMENT 'Correo electrónico del proveedor'
    AFTER `telefono`,
  ADD COLUMN IF NOT EXISTS `sitio_web` VARCHAR(200) NULL
    COMMENT 'Sitio web o perfil de redes sociales'
    AFTER `email`,
  ADD COLUMN IF NOT EXISTS `direccion` VARCHAR(200) NULL
    COMMENT 'Dirección física'
    AFTER `sitio_web`;

-- ── 4. Índice por nombre y categoría ─────────────────────────────────────────
ALTER TABLE `proveedores`
  ADD INDEX IF NOT EXISTS `idx_prov_nombre`    (`nombre`),
  ADD INDEX IF NOT EXISTS `idx_prov_categoria` (`categoria`),
  ADD INDEX IF NOT EXISTS `idx_prov_activo`    (`activo`);

-- ============================================================
-- VERIFICACIÓN:
-- SELECT id, nombre, categoria, telefono, activo FROM proveedores ORDER BY nombre;
-- ============================================================
