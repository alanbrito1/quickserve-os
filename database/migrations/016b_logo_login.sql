-- ============================================================
-- Migración 016b — Agregar logo_url_login a configuracion_app
-- Logo vertical para la página de acceso (diferente al logo
-- horizontal del menú de navegación).
-- IMPORTANTE: Cambiar 'clandestinoERP' por el nombre real de tu DB
-- ============================================================
USE `clandestinoERP`;

INSERT IGNORE INTO `configuracion_app` (`clave`, `valor`, `descripcion`)
VALUES ('logo_url_login', '', 'Logo vertical para la página de acceso (login)');

-- ============================================================
-- Verificar:
-- SELECT clave, valor FROM configuracion_app;
-- ============================================================
