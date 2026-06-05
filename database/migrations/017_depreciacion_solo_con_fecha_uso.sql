-- ============================================================
-- Migración 017 — Depreciación solo con fecha_inicio_uso
--
-- REGLA: Un activo NO se deprecia hasta que tenga fecha_inicio_uso.
-- Si se quita la fecha_inicio_uso al editar → deprec = 0.
--
-- Cambios:
--   1. Reemplaza ambos triggers para respetar la regla.
--   2. Limpia los activos sin fecha_inicio_uso (deprec → 0).
--
-- IMPORTANTE: Cambiar 'clandestinoERP' por el nombre real de tu DB
-- ============================================================
USE `clandestinoERP`;

DELIMITER $$

-- ── Trigger INSERT ────────────────────────────────────────────────────────────
-- Solo calcula depreciación si se proporciona fecha_inicio_uso.
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
        -- Sin fecha de inicio: el activo está en espera, sin depreciación
        SET NEW.depreciacion_mensual = 0;
        SET NEW.depreciacion_diaria  = 0;
    END IF;
END$$

-- ── Trigger UPDATE ────────────────────────────────────────────────────────────
-- Si se quita la fecha_inicio_uso → deprec = 0.
-- Si se asigna o ya existe y cambia costo/vida → recalcular.
DROP TRIGGER IF EXISTS `trg_activos_deprec_update`$$
CREATE TRIGGER `trg_activos_deprec_update`
BEFORE UPDATE ON `activos`
FOR EACH ROW
BEGIN
    IF NEW.fecha_inicio_uso IS NULL THEN
        -- Se quitó la fecha o nunca la tuvo → limpiar depreciación
        SET NEW.depreciacion_mensual = 0;
        SET NEW.depreciacion_diaria  = 0;
    ELSEIF OLD.costo_inicial      != NEW.costo_inicial
        OR OLD.vida_util_meses    != NEW.vida_util_meses
        OR OLD.fecha_inicio_uso   IS NULL
    THEN
        -- Se asignó fecha por primera vez, o cambió costo/vida → recalcular
        SET NEW.depreciacion_mensual = NEW.costo_inicial
                                       / GREATEST(CAST(NEW.vida_util_meses AS SIGNED), 1);
        SET NEW.depreciacion_diaria  = NEW.depreciacion_mensual / 30.41666;
    END IF;
END$$

DELIMITER ;

-- ── Limpiar activos existentes sin fecha_inicio_uso ───────────────────────────
UPDATE `activos`
SET    `depreciacion_mensual` = 0,
       `depreciacion_diaria`  = 0
WHERE  `fecha_inicio_uso` IS NULL;

-- ============================================================
-- Verificar:
-- SELECT nombre, fecha_inicio_uso, depreciacion_mensual, depreciacion_diaria
-- FROM activos ORDER BY fecha_inicio_uso IS NULL DESC;
-- ============================================================
