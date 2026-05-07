-- ============================================================
-- Migración 008 — Horas de jornada, recargos y horas extras
-- Colombia: Ley 2101/2021 (jornada 44h/semana desde 2026)
--           Art. 168-172 CST (recargos nocturnos y extras)
-- IMPORTANTE: Cambiar 'clandestinoERP' por el nombre real de tu DB
-- ============================================================
USE `clandestinoERP`;

-- ── 1. Extender categoria enum en parametros_laborales ────────────────────────
ALTER TABLE `parametros_laborales`
  MODIFY COLUMN `categoria`
    ENUM('base','carga_parafiscal','provision','descuento_empleado','tope','horas_jornada')
    NOT NULL DEFAULT 'carga_parafiscal';

-- ── 2. Nuevos parámetros de jornada y recargos — Colombia 2026 ────────────────
INSERT IGNORE INTO `parametros_laborales`
    (`pais`, `clave`, `nombre`, `valor`, `tipo`, `aplica_a`, `categoria`,
     `aplica_contratos`, `descripcion`, `orden`)
VALUES
-- Configuración de jornada
('Colombia', 'horas_jornada_valor',
 'Horas de jornada laboral',
 44, 'valor_fijo', 'empleador', 'horas_jornada',
 'tiempo_completo,medio_tiempo,por_horas',
 'Ley 2101/2021: reducción gradual. 2026 en adelante = 44 h/semana.', 1),

('Colombia', 'horas_jornada_periodo',
 'Período de la jornada (1=semana, 12=mes)',
 1, 'valor_fijo', 'empleador', 'horas_jornada',
 'tiempo_completo,medio_tiempo,por_horas',
 '1 = el valor es horas/semana (se convierte: × 52.14 / 12). 12 = ya es horas/mes.', 2),

('Colombia', 'horas_max_dia',
 'Horas máximas ordinarias por día',
 8, 'valor_fijo', 'empleador', 'horas_jornada',
 'tiempo_completo,medio_tiempo,por_horas',
 'Máximo de horas sin recargo en un día. A partir de aquí aplican recargos.', 3),

-- Horario nocturno
('Colombia', 'hora_inicio_nocturno',
 'Hora inicio jornada nocturna (formato 24h)',
 21, 'valor_fijo', 'empleador', 'horas_jornada',
 'tiempo_completo,medio_tiempo,por_horas',
 'Defecto: 21 = 9pm. Trabajo a partir de esta hora tiene recargo nocturno.', 4),

('Colombia', 'hora_fin_nocturno',
 'Hora fin jornada nocturna (formato 24h)',
 6, 'valor_fijo', 'empleador', 'horas_jornada',
 'tiempo_completo,medio_tiempo,por_horas',
 'Defecto: 6 = 6am. La jornada nocturna va de hora_inicio hasta hora_fin.', 5),

-- Recargos (Art. 168-172 CST)
('Colombia', 'pct_recargo_nocturno',
 'Recargo nocturno ordinario',
 35, 'porcentaje', 'empleador', 'horas_jornada',
 'tiempo_completo,medio_tiempo,por_horas',
 'Trabajo ordinario entre 9pm y 6am (no son horas extra). Pago = valor_hora × 1.35', 10),

('Colombia', 'pct_hora_extra_diurna',
 'Recargo hora extra diurna',
 25, 'porcentaje', 'empleador', 'horas_jornada',
 'tiempo_completo,medio_tiempo,por_horas',
 'Art. 168 CST. Horas extra entre 6am-9pm. Pago = valor_hora × 1.25', 11),

('Colombia', 'pct_hora_extra_nocturna',
 'Recargo hora extra nocturna',
 75, 'porcentaje', 'empleador', 'horas_jornada',
 'tiempo_completo,medio_tiempo,por_horas',
 'Art. 168 CST. Horas extra entre 9pm-6am. Pago = valor_hora × 1.75', 12),

('Colombia', 'pct_hora_festiva_ordinaria',
 'Recargo dominical/festivo ordinario',
 75, 'porcentaje', 'empleador', 'horas_jornada',
 'tiempo_completo,medio_tiempo,por_horas',
 'Art. 171 CST. Jornada normal en día festivo o domingo. Pago = valor_hora × 1.75', 13),

('Colombia', 'pct_hora_extra_festiva_diurna',
 'Recargo hora extra festiva diurna',
 100, 'porcentaje', 'empleador', 'horas_jornada',
 'tiempo_completo,medio_tiempo,por_horas',
 'Art. 172 CST. Extra de día en festivo. Pago = valor_hora × 2.00', 14),

('Colombia', 'pct_hora_extra_festiva_nocturna',
 'Recargo hora extra festiva nocturna',
 150, 'porcentaje', 'empleador', 'horas_jornada',
 'tiempo_completo,medio_tiempo,por_horas',
 'Art. 172 CST. Extra de noche en festivo. Pago = valor_hora × 2.50', 15);

-- ── 3. Actualizar horas_mes_estandar al valor correcto (44h × 52.14 / 12) ────
-- Reemplaza el valor 240 (incorrecto para Colombia 2026) por el cálculo correcto
UPDATE `parametros_laborales`
SET `valor`       = 191.18,
    `descripcion` = 'Calculado automáticamente: 44h/semana × 52.14 semanas/año ÷ 12 meses = 191.18 h/mes. Actualizar si cambia horas_jornada_valor.'
WHERE `pais`  = 'Colombia'
  AND `clave` = 'horas_mes_estandar';

-- Si no existía, insertar
INSERT IGNORE INTO `parametros_laborales`
    (`pais`, `clave`, `nombre`, `valor`, `tipo`, `aplica_a`, `categoria`,
     `aplica_contratos`, `descripcion`, `orden`)
VALUES
('Colombia', 'horas_mes_estandar',
 'Horas estándar de trabajo al mes (calculado)',
 191.18, 'valor_fijo', 'empleador', 'horas_jornada',
 'por_horas',
 'Calculado: 44h/semana × 52.14 / 12 = 191.18 h/mes. Actualizar manualmente si cambia la jornada.', 6);

-- ── 4. Extender registro_horas con tipo de hora y festivo ─────────────────────
ALTER TABLE `registro_horas`
  ADD COLUMN IF NOT EXISTS `tipo_hora`
    ENUM('ordinaria','recargo_nocturno','extra_diurna','extra_nocturna',
         'festiva_ordinaria','extra_festiva_diurna','extra_festiva_nocturna')
    NOT NULL DEFAULT 'ordinaria'
    COMMENT 'Tipo de hora para calcular el recargo correspondiente'
    AFTER `horas`,
  ADD COLUMN IF NOT EXISTS `es_festivo`
    TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1 = día festivo o dominical (aplica recargos de ley)'
    AFTER `tipo_hora`;

ALTER TABLE `registro_horas`
  ADD INDEX IF NOT EXISTS `idx_tipo_hora` (`tipo_hora`, `es_festivo`);

-- ── 5. Desglose de horas en nomina_liquidaciones ──────────────────────────────
ALTER TABLE `nomina_liquidaciones`
  ADD COLUMN IF NOT EXISTS `horas_ordinarias`         DECIMAL(7,2) NULL DEFAULT 0
    COMMENT 'Horas ordinarias trabajadas en el período'
    AFTER `horas_trabajadas`,
  ADD COLUMN IF NOT EXISTS `horas_extras`             DECIMAL(7,2) NULL DEFAULT 0
    COMMENT 'Total horas extras (todos los tipos)'
    AFTER `horas_ordinarias`,
  ADD COLUMN IF NOT EXISTS `valor_horas_extras`       DECIMAL(12,2) NULL DEFAULT 0
    COMMENT 'Monto total pagado por horas extras (con recargos incluidos)'
    AFTER `horas_extras`,
  ADD COLUMN IF NOT EXISTS `detalle_recargos`         TEXT NULL
    COMMENT 'JSON: {"extra_diurna":{"horas":2,"valor":30000}, ...}'
    AFTER `valor_horas_extras`;

-- ============================================================
-- VERIFICACIÓN:
-- SELECT clave, nombre, valor FROM parametros_laborales
-- WHERE pais='Colombia' AND categoria='horas_jornada' ORDER BY orden;
-- -- Debe mostrar 12 filas (jornada + recargos)
-- ============================================================
