-- ============================================================
-- Country pack — Paraguay (PY)   ·  multi-país
-- ============================================================
-- Plan de cuentas mapeado a los ROLES semánticos del motor contable + localización
-- (PYG, IVA 10%). Aplicar sobre schema.sql. Idempotente. NO lleva USE.
--
-- ⚠️ ARRANQUE / A VALIDAR: no tiene un plan único obligatorio (uso conforme a NIIF). Estos códigos son un punto de partida; el plan de
-- cuentas definitivo y su uso fiscal deben definirse/revisarse con un CONTADOR del país.
-- Lo que el motor usa es el ROL (no el código), así que se pueden ajustar sin tocar código.
-- La NÓMINA y la FACTURACIÓN legal de este país son fases posteriores (asesor/PAC local).
-- ============================================================

SET NAMES utf8mb4;

-- ── Plan de cuentas → roles ───────────────────────────────────────────────
INSERT IGNORE INTO `cuentas_contables` (`codigo`,`nombre`,`rol`,`pais`,`tipo`,`naturaleza`,`es_contra`,`orden`) VALUES
  ('1101','Caja','caja','PY','activo','debito',0,10),
  ('1102','Bancos','bancos','PY','activo','debito',0,20),
  ('1103','Créditos por ventas (clientes)','cxc_fiado','PY','activo','debito',0,30),
  ('1104','Mercaderías — productos terminados','inv_terminado','PY','activo','debito',0,40),
  ('1105','Mercaderías — materias primas','inv_insumos','PY','activo','debito',0,50),
  ('1106','IVA crédito fiscal','imp_descontable','PY','activo','debito',0,55),
  ('1201','Bienes de uso','activos_fijos','PY','activo','debito',0,60),
  ('1209','Depreciación acumulada','deprec_acumulada','PY','activo','credito',1,70),
  ('2101','Proveedores','proveedores_por_pagar','PY','pasivo','credito',0,110),
  ('2102','IVA débito fiscal','imp_ventas_por_pagar','PY','pasivo','credito',0,120),
  ('2103','Remuneraciones a pagar','nomina_por_pagar','PY','pasivo','credito',0,130),
  ('3101','Capital','capital','PY','patrimonio','credito',0,210),
  ('3201','Resultado del ejercicio','utilidad','PY','patrimonio','credito',0,220),
  ('4101','Ventas','ingresos','PY','ingreso','credito',0,310),
  ('5101','Costo de ventas','costo_ventas','PY','costo','debito',0,410),
  ('5201','Remuneraciones','gasto_nomina','PY','gasto','debito',0,510),
  ('5202','Depreciación','gasto_depreciacion','PY','gasto','debito',0,520),
  ('5203','Gastos generales','gastos_operativos','PY','gasto','debito',0,530),
  ('5204','Mermas y donaciones','obsequios_mermas','PY','gasto','debito',0,540);

-- ── Localización (país activo = PY) ───────────────────────────────────
INSERT INTO `configuracion_app` (`clave`,`valor`) VALUES
  ('pais','PY'),('moneda_codigo','PYG'),('moneda_simbolo','₲'),
  ('moneda_decimales','0'),('impuesto_nombre','IVA'),('factura_modo','interno')
ON DUPLICATE KEY UPDATE `valor`=VALUES(`valor`);

INSERT INTO `configuracion_negocio` (`clave`,`valor`,`descripcion`,`categoria`) VALUES
  ('iva_tarifa',10,'Tarifa del impuesto de ventas por defecto (%)','impuestos')
ON DUPLICATE KEY UPDATE `valor`=VALUES(`valor`);

-- ============================================================
-- FIN country pack PY
-- ============================================================
