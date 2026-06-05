-- ============================================================
-- Migración 018 — Precisión en depreciación diaria
--
-- Cambia el divisor de 30.4 a 30.41666 (365 / 12) en los triggers.
-- Antes:  depreciacion_diaria = depreciacion_mensual / 30.4
-- Ahora:  depreciacion_diaria = depreciacion_mensual / 30.41666
--
-- Se recalculan automáticamente todos los activos con fecha_inicio_uso.
-- IMPORTANTE: Cambiar 'clandestinoERP' por el nombre real de tu DB
-- ============================================================
USE `clandestinoERP`;

DELIMITER $$

-- ── Trigger INSERT ────────────────────────────────────────────────────────────
DROP TRIGGER IF EXISTS `trg_activos_deprec_insert`$$
CREATE TRIGGER `trg_activos_deprec_insert`
BEFORE INSERT ON `activos`
FOR EACH ROW
BEGIN
    IF NEW.fecha_inicio_uso IS NOT NULL THEN
        SET NEW.depreciacion_mensual = NEW.costo_inicial
                                       / GREATEST(CAST(NEW.vida_util_meses AS SIGNED), 1);
        SET NEW.depreciacion_diaria  = NEW.depreciacion_mensual / 30.41666;
    ELSE
        SET NEW.depreciacion_mensual = 0;
        SET NEW.depreciacion_diaria  = 0;
    END IF;
END$$

-- ── Trigger UPDATE ────────────────────────────────────────────────────────────
DROP TRIGGER IF EXISTS `trg_activos_deprec_update`$$
CREATE TRIGGER `trg_activos_deprec_update`
BEFORE UPDATE ON `activos`
FOR EACH ROW
BEGIN
    IF NEW.fecha_inicio_uso IS NULL THEN
        SET NEW.depreciacion_mensual = 0;
        SET NEW.depreciacion_diaria  = 0;
    ELSEIF OLD.costo_inicial      != NEW.costo_inicial
        OR OLD.vida_util_meses    != NEW.vida_util_meses
        OR OLD.fecha_inicio_uso   IS NULL
    THEN
        SET NEW.depreciacion_mensual = NEW.costo_inicial
                                       / GREATEST(CAST(NEW.vida_util_meses AS SIGNED), 1);
        SET NEW.depreciacion_diaria  = NEW.depreciacion_mensual / 30.41666;
    END IF;
END$$

DELIMITER ;

-- ── Recalcular activos existentes con fecha_inicio_uso ────────────────────────
UPDATE `activos`
SET
    depreciacion_mensual = costo_inicial / GREATEST(CAST(vida_util_meses AS SIGNED), 1),
    depreciacion_diaria  = (costo_inicial / GREATEST(CAST(vida_util_meses AS SIGNED), 1))
                           / 30.41666
WHERE fecha_inicio_uso IS NOT NULL
  AND GREATEST(CAST(vida_util_meses AS SIGNED), 1) > 0;

-- ── Confirmar ─────────────────────────────────────────────────────────────────
-- SELECT nombre, costo_inicial, vida_util_meses,
--        depreciacion_mensual, depreciacion_diaria
-- FROM activos WHERE fecha_inicio_uso IS NOT NULL;
-- ============================================================
