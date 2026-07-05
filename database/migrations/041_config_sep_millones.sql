-- ============================================================
-- Migración 041 — Separador independiente para el grupo de millones
-- ============================================================
-- Agrega num_sep_millones: separador para el grupo de millones (y superiores),
-- independiente del separador de miles (num_sep_miles, migración 040).
--
--   - Si num_sep_millones === num_sep_miles -> formato uniforme (igual que antes),
--     ej. con '.': 1.234.567
--   - Si son distintos -> el primer grupo (miles) usa num_sep_miles y todos los
--     grupos a la izquierda (millones, miles de millones, ...) usan
--     num_sep_millones, ej. con miles='.' y millones="'": 1'234.567
--
-- Valor por defecto '.' = igual al valor por defecto de num_sep_miles, así que
-- el comportamiento no cambia hasta que el admin lo configure distinto.
--
-- Reutiliza la tabla configuracion_app (clave/valor) ya usada por
-- admin/apariencia.php para tema/tipografía/formato numérico (migración 040).
-- Leído por app/helpers/FormatoHelper.php::config_numeros() y por
-- window.NUM_FORMAT en app/views/nav.php.
-- ============================================================


INSERT IGNORE INTO configuracion_app (clave, valor, descripcion) VALUES
  ('num_sep_millones', '.', 'Caracter separador para el grupo de millones (y superiores); si es igual al separador de miles, el formato es uniforme');
