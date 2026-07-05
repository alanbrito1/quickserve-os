-- ============================================================
-- Migración 016 — Módulo Admin: configuración de app y permisos
--
-- Crea tabla configuracion_app para valores de texto (tema, logo,
-- nombre del negocio) que no caben en configuracion_negocio (DECIMAL).
-- Actualiza el ENUM de permisos_modulos con todos los módulos actuales.
-- ============================================================

-- ── 1. Tabla de configuración de texto (tema, logo, negocio) ─────────────────
CREATE TABLE IF NOT EXISTS `configuracion_app` (
    `clave`      VARCHAR(100) NOT NULL,
    `valor`      TEXT         NOT NULL DEFAULT '',
    `descripcion` VARCHAR(255) NOT NULL DEFAULT '',
    `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by` INT UNSIGNED     NULL,
    PRIMARY KEY (`clave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Configuración de texto de la aplicación: tema, logo, nombre del negocio';

-- ── 2. Valores por defecto ────────────────────────────────────────────────────
INSERT IGNORE INTO `configuracion_app` (`clave`, `valor`, `descripcion`) VALUES
('nombre_negocio', 'QuickServe OS',       'Nombre que aparece en el menú y las páginas'),
('logo_url',       '',                  'Ruta relativa al logo (vacío = mostrar texto)'),
('theme_brand',    '#e94f37',           'Color principal de la marca (botones, badges activos)'),
('theme_dark',     '#111827',           'Color de fondo del menú superior'),
('theme_font',     'system-ui, -apple-system, sans-serif', 'Familia de fuente global'),
('theme_radius',   '12',                'Radio de bordes en píxeles (tarjetas, modales)');

-- ── 3. Actualizar ENUM de permisos_modulos con todos los módulos actuales ─────
ALTER TABLE `permisos_modulos`
  MODIFY COLUMN `modulo`
    ENUM('ventas','compras','inventario','nomina','productos','activos',
         'reportes','proveedores','costos')
    NOT NULL;

-- ── 4. Dar acceso admin_total al superadmin en todos los módulos nuevos ───────
-- (El superadmin bypassa el check de DB, pero esto permite que otros admins
-- también reciban acceso si se les asigna el rol 'admin' en el futuro)
INSERT IGNORE INTO `permisos_modulos` (`usuario_id`, `modulo`, `nivel_acceso`)
SELECT 1, m, 'admin_total'
FROM (
    SELECT 'ventas'      AS m UNION SELECT 'compras'    UNION SELECT 'inventario'
    UNION SELECT 'nomina'   UNION SELECT 'productos'   UNION SELECT 'activos'
    UNION SELECT 'reportes' UNION SELECT 'proveedores' UNION SELECT 'costos'
) mods;

-- ============================================================
-- Verificar:
-- SELECT * FROM configuracion_app;
-- SELECT modulo, nivel_acceso FROM permisos_modulos WHERE usuario_id = 1;
-- ============================================================
