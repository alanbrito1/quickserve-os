-- ============================================================
-- Migracion 027: Campo nombre2 en productos
-- ============================================================
-- Agrega un segundo nombre (complemento) a cada producto.
-- Ejemplos de uso:
--   nombre  = "Sandwich de Pollo"
--   nombre2 = "con papas criollas"
--
--   nombre  = "Combo Loco"
--   nombre2 = "Sandwich + gaseosa + postre"
--
-- El campo es opcional (NULL = sin complemento).
-- Se muestra como subtitulo en POS, produccion, reportes y stock.
-- No afecta ninguna logica de negocio: solo es visual.
-- ============================================================

USE clandestinoERP;

ALTER TABLE productos
    ADD COLUMN nombre2 VARCHAR(120) NULL DEFAULT NULL
    AFTER nombre;
