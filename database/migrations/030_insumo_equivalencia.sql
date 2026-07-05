-- ============================================================
-- Migracion 030: Equivalencia fisica de unidades de insumos
-- ============================================================
-- Cuando la unidad basica de un insumo NO es una unidad fisica de
-- masa o volumen (ej: loncha, lata, paquete), este par de columnas
-- permite registrar su equivalente fisico.
--
-- Ejemplos:
--   Jamon Serrano (loncha): equiv_cantidad=30, equiv_unidad='g'
--   → 1 loncha = 30 g
--   Atun en lata (lata): equiv_cantidad=170, equiv_unidad='g'
--   → 1 lata = 170 g
--   Jugo (paquete): equiv_cantidad=250, equiv_unidad='ml'
--   → 1 paquete = 250 ml
--
-- Se usan en:
--   - inventario/index.php: campo de equivalencia en modal Nuevo/Editar
--   - inventario/compras.php: calculo y visualizacion de equivalente total
-- ============================================================


ALTER TABLE insumos
    ADD COLUMN equiv_cantidad DECIMAL(10,4) NULL DEFAULT NULL
        COMMENT 'Equivalencia fisica: cuanto pesa/contiene 1 unidad (ej: 30 para 30g)',
    ADD COLUMN equiv_unidad   VARCHAR(10)   NULL DEFAULT NULL
        COMMENT 'Unidad fisica de la equivalencia: g, kg, ml, litro'
    AFTER unidad_medida;
