-- ============================================================
-- Migración 040 — Configuración global de formato numérico
-- ============================================================
-- Permite configurar desde Admin > Apariencia:
--   - num_decimales:   N° de decimales para cantidades (stock, presentaciones,
--                       equivalencias, costo por unidad). Los precios/montos en
--                       pesos siguen siempre en 0 decimales.
--   - num_sep_miles:   Carácter separador de miles (aplica a cantidades y precios).
--   - num_sep_decimal: Carácter separador decimal (aplica a cantidades y precios).
--
-- Reutiliza la tabla configuracion_app (clave/valor) ya usada por
-- admin/apariencia.php para tema/tipografía. Leído por
-- app/helpers/FormatoHelper.php::config_numeros().
-- ============================================================


INSERT IGNORE INTO configuracion_app (clave, valor, descripcion) VALUES
  ('num_decimales',   '2', 'Decimales para cantidades (stock, presentaciones, equivalencias, costo por unidad)'),
  ('num_sep_miles',   '.', 'Caracter separador de miles para todos los numeros'),
  ('num_sep_decimal', ',', 'Caracter separador decimal para todos los numeros');
