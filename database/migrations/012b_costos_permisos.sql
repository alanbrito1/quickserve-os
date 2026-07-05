-- ============================================================
-- Migración 012b — Costos Indirectos: fix de permisos
-- Ejecutar DESPUÉS de 012_costos_indirectos.sql si el UPDATE
-- de permisos falló (error #1054 columna 'permisos_modulos').
-- Causa: los permisos viven en la tabla permisos_modulos,
-- no como columna JSON en usuarios.
-- ============================================================

-- Agregar 'costos' al ENUM de módulos permitidos
ALTER TABLE `permisos_modulos`
  MODIFY COLUMN `modulo`
    ENUM('ventas','compras','inventario','nomina','productos','activos',
         'reportes','proveedores','costos')
    NOT NULL;

-- Dar acceso admin_total al superadmin (usuario_id = 1)
-- Si el superadmin tiene otro ID: SELECT id FROM usuarios WHERE rol='superadmin';
INSERT IGNORE INTO `permisos_modulos` (`usuario_id`, `modulo`, `nivel_acceso`)
VALUES (1, 'costos', 'admin_total');

-- ============================================================
-- Verificar:
-- SELECT usuario_id, modulo, nivel_acceso
-- FROM permisos_modulos
-- WHERE modulo = 'costos';
-- ============================================================
