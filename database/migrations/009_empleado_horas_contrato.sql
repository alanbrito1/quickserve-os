-- ============================================================
-- Migración 009 — Empleados: horas contratadas individuales
-- Permite que cada empleado por horas tenga su propio acuerdo de jornada.
-- IMPORTANTE: Cambiar 'clandestinoERP' por el nombre real de tu base de datos
-- ============================================================
USE `clandestinoERP`;

-- La columna horas_semana ya existe (migración 003).
-- Sólo agregamos el período para saber si es semanal o mensual.
ALTER TABLE `empleados`
  ADD COLUMN IF NOT EXISTS `periodo_horas_emp`
    ENUM('semana','mes') NULL DEFAULT NULL
    COMMENT 'Período al que corresponde horas_semana: semana = semanal, mes = mensual. NULL = usar parámetro global'
    AFTER `horas_semana`;

-- ============================================================
-- CÁLCULO:
--   Si periodo_horas_emp = 'semana': horas_mes = horas_semana × 52.14 / 12
--   Si periodo_horas_emp = 'mes':    horas_mes = horas_semana (directo)
--   Si NULL:                          horas_mes = parámetro global (horas_jornada_valor)
-- ============================================================
