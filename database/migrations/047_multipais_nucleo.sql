-- ============================================================
-- Migración 047 — Núcleo multi-país (Fase A)
-- ============================================================
-- Desacopla el motor contable del plan de cuentas colombiano:
--   • cuentas_contables gana `rol` (rol semántico: caja, ingresos, etc.)
--     y `pais` (ISO) para que el auto-posting use ROLES, no códigos fijos.
--   • configuracion_app gana país / moneda / nombre de impuesto / modo de
--     facturación (valores de TEXTO — no van en configuracion_negocio, que es DECIMAL).
-- Colombia queda IDÉNTICO: los códigos actuales se etiquetan con su rol y país 'CO'.
-- Idempotente (guard information_schema). NO lleva USE.
-- ============================================================

SET NAMES utf8mb4;

-- ── cuentas_contables.rol ─────────────────────────────────────────────────
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cuentas_contables' AND COLUMN_NAME='rol');
SET @ddl := IF(@c=0,
  'ALTER TABLE `cuentas_contables` ADD COLUMN `rol` VARCHAR(40) DEFAULT NULL AFTER `nombre`',
  'SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- ── cuentas_contables.pais ────────────────────────────────────────────────
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cuentas_contables' AND COLUMN_NAME='pais');
SET @ddl := IF(@c=0,
  'ALTER TABLE `cuentas_contables` ADD COLUMN `pais` VARCHAR(5) NOT NULL DEFAULT ''CO'' AFTER `rol`',
  'SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- Índice para resolver rol→cuenta por país (idempotente)
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cuentas_contables' AND INDEX_NAME='idx_cuenta_rol_pais');
SET @ddl := IF(@c=0,
  'ALTER TABLE `cuentas_contables` ADD INDEX `idx_cuenta_rol_pais` (`pais`,`rol`)',
  'SELECT 1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- ── Etiquetar los códigos colombianos con su rol semántico (pais='CO') ─────
UPDATE `cuentas_contables` SET `pais`='CO';
UPDATE `cuentas_contables` SET `rol` = CASE `codigo`
    WHEN '1105' THEN 'caja'
    WHEN '1110' THEN 'bancos'
    WHEN '1305' THEN 'cxc_fiado'
    WHEN '1430' THEN 'inv_terminado'
    WHEN '1435' THEN 'inv_insumos'
    WHEN '1355' THEN 'imp_descontable'
    WHEN '1524' THEN 'activos_fijos'
    WHEN '1592' THEN 'deprec_acumulada'
    WHEN '2205' THEN 'proveedores_por_pagar'
    WHEN '2408' THEN 'imp_ventas_por_pagar'
    WHEN '2510' THEN 'nomina_por_pagar'
    WHEN '3115' THEN 'capital'
    WHEN '3705' THEN 'utilidad'
    WHEN '4135' THEN 'ingresos'
    WHEN '6135' THEN 'costo_ventas'
    WHEN '5105' THEN 'gasto_nomina'
    WHEN '5160' THEN 'gasto_depreciacion'
    WHEN '5195' THEN 'gastos_operativos'
    WHEN '5199' THEN 'obsequios_mermas'
    ELSE `rol`
END
WHERE `codigo` IN ('1105','1110','1305','1430','1435','1355','1524','1592','2205',
                   '2408','2510','3115','3705','4135','6135','5105','5160','5195','5199');

-- ── Config de localización (TEXTO → configuracion_app) ─────────────────────
INSERT IGNORE INTO `configuracion_app` (`clave`, `valor`, `descripcion`) VALUES
  ('pais',             'CO',  'País operativo (ISO): CO, MX, PE, CL, ES, AR, BR, EC, PA, PY, UY'),
  ('moneda_codigo',    'COP', 'Código ISO de la moneda (COP, MXN, PEN, CLP, EUR, ARS, BRL, USD…)'),
  ('moneda_simbolo',   '$',   'Símbolo de la moneda para mostrar en pantalla'),
  ('moneda_decimales', '0',   'Decimales para montos de dinero (0 = pesos enteros)'),
  ('impuesto_nombre',  'IVA', 'Nombre del impuesto de ventas (IVA, IGV, ITBMS, IEPS…)'),
  ('factura_modo',     'interno', 'Modo de facturación: interno (comprobante propio) o legal (proveedor certificado)');

-- ============================================================
-- FIN migración 047
-- ============================================================
