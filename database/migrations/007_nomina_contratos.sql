-- ============================================================
-- Migración 007 — Nómina: tipos de contrato, horas y parámetros por país
-- ============================================================

-- ── 1. Actualizar enum tipo_contrato en empleados ─────────────────────────────
ALTER TABLE `empleados`
  MODIFY COLUMN `tipo_contrato`
    ENUM('tiempo_completo','medio_tiempo','por_horas','por_dias','por_servicio')
    NOT NULL DEFAULT 'tiempo_completo'
    COMMENT 'Modalidad de contratación. por_servicio = sin prestaciones (contratista)';

-- ── 2. Campos extra en empleados para contratos especiales ────────────────────
ALTER TABLE `empleados`
  ADD COLUMN IF NOT EXISTS `valor_hora`       DECIMAL(10,2) NULL
    COMMENT 'Tarifa por hora para tipo_contrato = por_horas'
    AFTER `horas_semana`,
  ADD COLUMN IF NOT EXISTS `valor_proyecto`   DECIMAL(12,2) NULL
    COMMENT 'Pago fijo del proyecto para tipo_contrato = por_servicio'
    AFTER `valor_hora`;

-- ── 3. Tabla de registro diario de horas (para contratos por horas) ───────────
CREATE TABLE IF NOT EXISTS `registro_horas` (
    `id`              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `empleado_id`     INT UNSIGNED     NOT NULL,
    `fecha`           DATE             NOT NULL,
    `horas`           DECIMAL(5,2)     NOT NULL DEFAULT 8.00
        COMMENT 'Horas trabajadas ese día (puede ser fracción: 3.5 = 3h30m)',
    `descripcion`     VARCHAR(200)     NULL
        COMMENT 'Descripción del trabajo realizado ese día',
    `aprobado`        TINYINT(1)       NOT NULL DEFAULT 0
        COMMENT '0=pendiente aprobación, 1=aprobado',
    `created_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by`      INT UNSIGNED     NULL,
    `updated_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by`      INT UNSIGNED     NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY  `uk_empleado_fecha` (`empleado_id`, `fecha`),
    INDEX `idx_empleado_fecha` (`empleado_id`, `fecha`),
    FOREIGN KEY (`empleado_id`) REFERENCES `empleados`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Registro diario de horas para empleados con contrato por horas.';

-- ── 4. Tabla de parámetros laborales configurables por país ──────────────────
-- Reemplaza los parámetros de nómina en configuracion_negocio.
-- Permite soportar múltiples países y modificar/agregar parámetros.
CREATE TABLE IF NOT EXISTS `parametros_laborales` (
    `id`              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `pais`            VARCHAR(60)      NOT NULL DEFAULT 'Colombia'
        COMMENT 'País al que aplica esta configuración',
    `clave`           VARCHAR(100)     NOT NULL
        COMMENT 'Identificador programático único por país',
    `nombre`          VARCHAR(200)     NOT NULL
        COMMENT 'Nombre legible del parámetro (ej: Salud empleador)',
    `valor`           DECIMAL(15,6)    NOT NULL
        COMMENT 'Porcentaje (ej: 8.5) o valor fijo en moneda local',
    `tipo`            ENUM('porcentaje','valor_fijo')
                      NOT NULL DEFAULT 'porcentaje',
    `aplica_a`        ENUM('empleador','empleado','ambos')
                      NOT NULL DEFAULT 'empleador',
    `categoria`       ENUM('base','carga_parafiscal','provision','descuento_empleado','tope')
                      NOT NULL DEFAULT 'carga_parafiscal',
    -- Qué tipos de contrato incluyen este parámetro (CSV)
    -- ej: 'tiempo_completo,medio_tiempo,por_horas'
    `aplica_contratos` VARCHAR(200)    NOT NULL
        DEFAULT 'tiempo_completo,medio_tiempo,por_horas',
    `descripcion`     TEXT            NULL,
    `activo`          TINYINT(1)      NOT NULL DEFAULT 1,
    `orden`           TINYINT UNSIGNED NOT NULL DEFAULT 50
        COMMENT 'Orden de presentación en la UI',
    `created_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_pais_clave` (`pais`, `clave`),
    INDEX `idx_pais_activo` (`pais`, `activo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Parámetros laborales configurables por país (porcentajes prestacionales, bases, topes).';

-- ── 5. Datos: Colombia 2026 ───────────────────────────────────────────────────
INSERT INTO `parametros_laborales`
    (`pais`, `clave`, `nombre`, `valor`, `tipo`, `aplica_a`, `categoria`,
     `aplica_contratos`, `descripcion`, `orden`)
VALUES
-- BASES
('Colombia','salario_minimo',
 'Salario Mínimo Mensual (SMLMV)',
 1750905, 'valor_fijo', 'empleador', 'base',
 'tiempo_completo,medio_tiempo,por_horas,por_servicio',
 'Salario Mínimo Legal Mensual Vigente 2026', 1),

('Colombia','aux_transporte',
 'Auxilio de Transporte',
 249095, 'valor_fijo', 'empleador', 'base',
 'tiempo_completo,medio_tiempo,por_horas',
 'Aplica cuando salario <= 2 SMLMV. Valor 2026.', 2),

('Colombia','tope_aux_transporte_smlmv',
 'Tope auxilio transporte (múltiplo SMLMV)',
 2, 'valor_fijo', 'empleador', 'tope',
 'tiempo_completo,medio_tiempo,por_horas',
 'El auxilio aplica si salario <= este valor × SMLMV', 3),

('Colombia','horas_mes_estandar',
 'Horas estándar de trabajo al mes',
 240, 'valor_fijo', 'empleador', 'base',
 'por_horas',
 'Para calcular valor hora: salario_base / horas_mes_estandar', 4),

-- CARGAS PARAFISCALES — Empleador
('Colombia','pct_salud_empleador',
 'Salud — aporte empleador',
 8.5, 'porcentaje', 'empleador', 'carga_parafiscal',
 'tiempo_completo,medio_tiempo,por_horas',
 '8.5% sobre salario base', 10),

('Colombia','pct_pension_empleador',
 'Pensión — aporte empleador',
 12.0, 'porcentaje', 'empleador', 'carga_parafiscal',
 'tiempo_completo,medio_tiempo,por_horas',
 '12% sobre salario base', 11),

('Colombia','pct_arl',
 'ARL Riesgo Clase I (cocina/mostrador)',
 0.522, 'porcentaje', 'empleador', 'carga_parafiscal',
 'tiempo_completo,medio_tiempo,por_horas',
 '0.522% para actividades de bajo riesgo', 12),

('Colombia','pct_caja_compensacion',
 'Caja de Compensación Familiar',
 4.0, 'porcentaje', 'empleador', 'carga_parafiscal',
 'tiempo_completo,medio_tiempo,por_horas',
 '4% sobre salario base', 13),

('Colombia','pct_icbf',
 'ICBF',
 3.0, 'porcentaje', 'empleador', 'carga_parafiscal',
 'tiempo_completo,medio_tiempo,por_horas',
 '3% sobre salario base. Exento si salario > 10 SMLMV.', 14),

('Colombia','pct_sena',
 'SENA',
 2.0, 'porcentaje', 'empleador', 'carga_parafiscal',
 'tiempo_completo,medio_tiempo,por_horas',
 '2% sobre salario base. Exento si salario > 10 SMLMV.', 15),

-- PROVISIONES MENSUALES — Empleador
('Colombia','pct_prima',
 'Prima de Servicios (provisión mensual)',
 8.33, 'porcentaje', 'empleador', 'provision',
 'tiempo_completo,medio_tiempo,por_horas',
 '1/12 del salario por mes = 8.33%. Se paga jun y dic.', 20),

('Colombia','pct_cesantias',
 'Cesantías (provisión mensual)',
 8.33, 'porcentaje', 'empleador', 'provision',
 'tiempo_completo,medio_tiempo,por_horas',
 '1/12 del salario por mes = 8.33%.', 21),

('Colombia','pct_intereses_cesantias',
 'Intereses sobre Cesantías (provisión mensual)',
 1.0, 'porcentaje', 'empleador', 'provision',
 'tiempo_completo,medio_tiempo,por_horas',
 '12% anual sobre saldo cesantías = 1% mensual.', 22),

('Colombia','pct_vacaciones',
 'Vacaciones (provisión mensual)',
 4.17, 'porcentaje', 'empleador', 'provision',
 'tiempo_completo,medio_tiempo,por_horas',
 '15 días hábiles / año = 4.17% mensual.', 23),

-- DESCUENTOS AL EMPLEADO
('Colombia','pct_salud_empleado',
 'Salud — descuento al empleado',
 4.0, 'porcentaje', 'empleado', 'descuento_empleado',
 'tiempo_completo,medio_tiempo,por_horas',
 '4% descontado de la nómina del trabajador.', 30),

('Colombia','pct_pension_empleado',
 'Pensión — descuento al empleado',
 4.0, 'porcentaje', 'empleado', 'descuento_empleado',
 'tiempo_completo,medio_tiempo,por_horas',
 '4% descontado de la nómina del trabajador.', 31);

-- ── 6. Agregar campo pais_laboral a empleados ─────────────────────────────────
ALTER TABLE `empleados`
  ADD COLUMN IF NOT EXISTS `pais_laboral` VARCHAR(60) NOT NULL DEFAULT 'Colombia'
    COMMENT 'País cuya legislación laboral aplica a este empleado'
    AFTER `tipo_contrato`;

-- ── 7. Campo horas_trabajadas en nomina_liquidaciones (para contratos por horas)─
ALTER TABLE `nomina_liquidaciones`
  ADD COLUMN IF NOT EXISTS `horas_trabajadas`  DECIMAL(7,2) NULL
    COMMENT 'Total horas trabajadas en el período (solo para tipo por_horas)'
    AFTER `periodo_anio`,
  ADD COLUMN IF NOT EXISTS `tipo_contrato`     VARCHAR(30)  NULL
    COMMENT 'Copia del tipo de contrato al momento de liquidar (trazabilidad)'
    AFTER `horas_trabajadas`,
  ADD COLUMN IF NOT EXISTS `descripcion_pago`  VARCHAR(300) NULL
    COMMENT 'Para por_servicio: descripción del proyecto pagado'
    AFTER `tipo_contrato`;

-- ============================================================
-- VERIFICACIÓN:
-- SELECT clave, nombre, valor, aplica_contratos
-- FROM parametros_laborales WHERE pais = 'Colombia' ORDER BY orden;
-- ============================================================
