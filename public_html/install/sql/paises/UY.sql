-- ============================================================
-- Country pack — Uruguay (UY)   ·  multi-país
-- ============================================================
-- Plan de cuentas mapeado a los ROLES semánticos del motor contable + localización
-- (UYU, IVA 22%). Aplicar sobre schema.sql. Idempotente. NO lleva USE.
--
-- ⚠️ ARRANQUE / A VALIDAR: no tiene un plan único obligatorio (uso conforme a NIIF). Estos códigos son un punto de partida; el plan de
-- cuentas definitivo y su uso fiscal deben definirse/revisarse con un CONTADOR del país.
-- Lo que el motor usa es el ROL (no el código), así que se pueden ajustar sin tocar código.
-- La NÓMINA y la FACTURACIÓN legal de este país son fases posteriores (asesor/PAC local).
-- ============================================================

SET NAMES utf8mb4;

-- ── Plan de cuentas → roles ───────────────────────────────────────────────
INSERT IGNORE INTO `cuentas_contables` (`codigo`,`nombre`,`rol`,`pais`,`tipo`,`naturaleza`,`es_contra`,`orden`) VALUES
  ('1101','Caja','caja','UY','activo','debito',0,10),
  ('1102','Bancos','bancos','UY','activo','debito',0,20),
  ('1103','Deudores por ventas','cxc_fiado','UY','activo','debito',0,30),
  ('1104','Bienes de cambio — productos terminados','inv_terminado','UY','activo','debito',0,40),
  ('1105','Bienes de cambio — materias primas','inv_insumos','UY','activo','debito',0,50),
  ('1106','IVA compras (crédito)','imp_descontable','UY','activo','debito',0,55),
  ('1201','Bienes de uso','activos_fijos','UY','activo','debito',0,60),
  ('1209','Amortización acumulada','deprec_acumulada','UY','activo','credito',1,70),
  ('2101','Proveedores','proveedores_por_pagar','UY','pasivo','credito',0,110),
  ('2102','IVA ventas (débito)','imp_ventas_por_pagar','UY','pasivo','credito',0,120),
  ('2103','Sueldos a pagar','nomina_por_pagar','UY','pasivo','credito',0,130),
  ('3101','Capital','capital','UY','patrimonio','credito',0,210),
  ('3201','Resultado del ejercicio','utilidad','UY','patrimonio','credito',0,220),
  ('4101','Ventas','ingresos','UY','ingreso','credito',0,310),
  ('5101','Costo de ventas','costo_ventas','UY','costo','debito',0,410),
  ('5201','Sueldos','gasto_nomina','UY','gasto','debito',0,510),
  ('5202','Amortizaciones','gasto_depreciacion','UY','gasto','debito',0,520),
  ('5203','Gastos generales','gastos_operativos','UY','gasto','debito',0,530),
  ('5204','Mermas y donaciones','obsequios_mermas','UY','gasto','debito',0,540);

-- ── Localización (país activo = UY) ───────────────────────────────────
INSERT INTO `configuracion_app` (`clave`,`valor`) VALUES
  ('pais','UY'),('moneda_codigo','UYU'),('moneda_simbolo','$'),
  ('moneda_decimales','2'),('impuesto_nombre','IVA'),('factura_modo','interno')
ON DUPLICATE KEY UPDATE `valor`=VALUES(`valor`);

INSERT INTO `configuracion_negocio` (`clave`,`valor`,`descripcion`,`categoria`) VALUES
  ('iva_tarifa',22,'Tarifa del impuesto de ventas por defecto (%)','impuestos')
ON DUPLICATE KEY UPDATE `valor`=VALUES(`valor`);

-- ============================================================
-- FIN country pack UY
-- ============================================================
