-- Migración 038: descuento en ventas
-- Agrega descuento_pct y descuento_valor a la tabla ventas.
-- descuento_pct: porcentaje aplicado (0-50), DEFAULT 0 → retrocompatible.
-- descuento_valor: monto monetario descontado (snapshot inmutable).


ALTER TABLE `ventas`
    ADD COLUMN IF NOT EXISTS `descuento_pct`   DECIMAL(5,2)  NOT NULL DEFAULT 0
        COMMENT 'Porcentaje de descuento aplicado (0-50)',
    ADD COLUMN IF NOT EXISTS `descuento_valor` DECIMAL(12,2) NOT NULL DEFAULT 0
        COMMENT 'Valor monetario del descuento — snapshot inmutable';
