-- ============================================================
-- Migración 016c — Sistema tipográfico centralizado
-- Agrega 8 claves a configuracion_app para controlar
-- tipografía desde Admin → Apariencia.
-- ============================================================

INSERT IGNORE INTO `configuracion_app` (`clave`, `valor`, `descripcion`) VALUES
('font_heading',      'system-ui, -apple-system, sans-serif', 'Fuente para títulos y encabezados'),
('font_size_title',   '22',  'Tamaño títulos de página (h1) en px'),
('font_size_subtitle','15',  'Tamaño subtítulos y tarjetas en px'),
('font_size_body',    '13',  'Tamaño texto del cuerpo en px'),
('font_size_small',   '11',  'Tamaño labels, encabezados tabla en px'),
('color_text',        '#111827', 'Color del texto principal'),
('color_text_sec',    '#6b7280', 'Color del texto secundario (labels, subtítulos)');

-- ============================================================
-- Verificar:
-- SELECT clave, valor FROM configuracion_app WHERE clave LIKE 'font%' OR clave LIKE 'color_text%';
-- ============================================================
