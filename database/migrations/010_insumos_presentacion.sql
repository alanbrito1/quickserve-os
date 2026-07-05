-- ============================================================
-- Migración 010 — Insumos: presentación, unidad básica y notas
-- ============================================================

-- ── 1. Ampliar unidad_medida con libra ────────────────────────────────────────
-- La unidad_medida es la unidad BÁSICA para costeo y stock (kg, g, lb, litro, ml, unidad)
ALTER TABLE `insumos`
  MODIFY COLUMN `unidad_medida`
    ENUM('kg','g','lb','litro','ml','unidad','loncha','lata','paquete')
    NOT NULL DEFAULT 'unidad'
    COMMENT 'Unidad básica de medida para costeo y recetas';

-- ── 2. Nuevos campos de presentación ─────────────────────────────────────────
ALTER TABLE `insumos`
  -- Cómo viene empacado cuando se compra
  ADD COLUMN IF NOT EXISTS `presentacion`
    ENUM('frasco','tarro','caja','paca','bolsa','atado','lata','bloque','galon','unidad','otra')
    NULL COMMENT 'Presentación de compra: frasco, tarro, caja, paca, etc.'
    AFTER `nombre`,

  -- Cuántas unidades básicas hay en una presentación
  -- Ej: 1 frasco de aceite = 900 ml → cantidad_presentacion = 900
  ADD COLUMN IF NOT EXISTS `cantidad_presentacion`
    DECIMAL(12,4) NULL
    COMMENT 'Cantidad de la unidad básica en una presentación. Ej: 900 (ml en un frasco)'
    AFTER `presentacion`,

  -- Precio de una presentación completa
  ADD COLUMN IF NOT EXISTS `precio_presentacion`
    DECIMAL(12,2) NULL
    COMMENT 'Precio de una presentación. costo_actual = precio_presentacion / cantidad_presentacion'
    AFTER `cantidad_presentacion`,

  -- Notas del insumo
  ADD COLUMN IF NOT EXISTS `notas`
    TEXT NULL
    COMMENT 'Observaciones: proveedor habitual, condiciones de almacenamiento, etc.'
    AFTER `proveedor_id`;

-- ── 3. Trigger actualizado: costo_actual se calcula desde la presentación ─────
DELIMITER $$

DROP TRIGGER IF EXISTS `trg_insumos_costo_from_presentacion_insert`$$
CREATE TRIGGER `trg_insumos_costo_from_presentacion_insert`
BEFORE INSERT ON `insumos`
FOR EACH ROW
BEGIN
    -- Si se proporciona precio y cantidad por presentación, calcular costo/unidad
    IF NEW.precio_presentacion IS NOT NULL
       AND NEW.cantidad_presentacion IS NOT NULL
       AND NEW.cantidad_presentacion > 0 THEN
        SET NEW.costo_actual = ROUND(NEW.precio_presentacion / NEW.cantidad_presentacion, 4);
    END IF;
END$$

DROP TRIGGER IF EXISTS `trg_insumos_costo_from_presentacion_update`$$
CREATE TRIGGER `trg_insumos_costo_from_presentacion_update`
BEFORE UPDATE ON `insumos`
FOR EACH ROW
BEGIN
    IF NEW.precio_presentacion IS NOT NULL
       AND NEW.cantidad_presentacion IS NOT NULL
       AND NEW.cantidad_presentacion > 0
       AND (NEW.precio_presentacion <> OLD.precio_presentacion
            OR NEW.cantidad_presentacion <> OLD.cantidad_presentacion) THEN
        SET NEW.costo_actual = ROUND(NEW.precio_presentacion / NEW.cantidad_presentacion, 4);
    END IF;
END$$

DELIMITER ;

-- ── 4. Actualizar insumos existentes con datos de presentación ─────────────────
-- Los insumos cargados en migración 004 necesitan sus presentaciones
UPDATE `insumos` SET
    `presentacion`           = 'kg',
    `cantidad_presentacion`  = 1,
    `precio_presentacion`    = `costo_actual`
WHERE `nombre` = 'Pollo Desmechado' AND `presentacion` IS NULL;

UPDATE `insumos` SET
    `presentacion`           = 'kg',
    `cantidad_presentacion`  = 1,
    `precio_presentacion`    = `costo_actual`
WHERE `nombre` = 'Carne de Res' AND `presentacion` IS NULL;

UPDATE `insumos` SET
    `presentacion`           = 'lata',
    `cantidad_presentacion`  = 1,
    `precio_presentacion`    = `costo_actual`
WHERE `nombre` = 'Atún Lata 160g' AND `presentacion` IS NULL;

UPDATE `insumos` SET
    `presentacion`           = 'frasco',
    `cantidad_presentacion`  = 900,
    `precio_presentacion`    = 8500
WHERE `nombre` = 'Aceite Vegetal' AND `presentacion` IS NULL;

UPDATE `insumos` SET
    `presentacion`           = 'unidad',
    `cantidad_presentacion`  = 1,
    `precio_presentacion`    = `costo_actual`
WHERE `presentacion` IS NULL;

-- ── 5. Índice para filtrar por categoría ──────────────────────────────────────
ALTER TABLE `insumos` ADD INDEX IF NOT EXISTS `idx_presentacion` (`presentacion`);

-- ============================================================
-- VERIFICACIÓN:
-- SELECT nombre, presentacion, cantidad_presentacion, unidad_medida,
--        precio_presentacion, costo_actual
-- FROM insumos WHERE activo = 1 LIMIT 10;
-- ============================================================
