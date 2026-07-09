-- ============================================================
-- Country pack — Chile (CL)   ·  multi-país
-- ============================================================
-- Plan de cuentas mapeado a los ROLES semánticos del motor contable + localización
-- (CLP, IVA 19%). Aplicar sobre schema.sql. Idempotente. NO lleva USE.
--
-- ⚠️ ARRANQUE / A VALIDAR: no tiene un plan de cuentas único obligatorio (uso conforme a NIIF). Estos códigos son un punto de partida; el plan de
-- cuentas definitivo y su uso fiscal deben definirse/revisarse con un CONTADOR del país.
-- Lo que el motor usa es el ROL (no el código), así que se pueden ajustar sin tocar código.
-- La NÓMINA y la FACTURACIÓN legal de este país son fases posteriores (asesor/PAC local).
-- ============================================================

SET NAMES utf8mb4;

-- ── Plan de cuentas → roles ───────────────────────────────────────────────
INSERT IGNORE INTO `cuentas_contables` (`codigo`,`nombre`,`rol`,`pais`,`tipo`,`naturaleza`,`es_contra`,`orden`) VALUES
  ('1101','Caja','caja','CL','activo','debito',0,10),
  ('1102','Banco','bancos','CL','activo','debito',0,20),
  ('1103','Deudores por venta (clientes)','cxc_fiado','CL','activo','debito',0,30),
  ('1105','Existencias — productos terminados','inv_terminado','CL','activo','debito',0,40),
  ('1106','Existencias — materias primas','inv_insumos','CL','activo','debito',0,50),
  ('1108','IVA crédito fiscal','imp_descontable','CL','activo','debito',0,55),
  ('1201','Activo fijo (maquinaria y equipo)','activos_fijos','CL','activo','debito',0,60),
  ('1209','Depreciación acumulada','deprec_acumulada','CL','activo','credito',1,70),
  ('2101','Proveedores','proveedores_por_pagar','CL','pasivo','credito',0,110),
  ('2103','IVA débito fiscal','imp_ventas_por_pagar','CL','pasivo','credito',0,120),
  ('2105','Remuneraciones por pagar','nomina_por_pagar','CL','pasivo','credito',0,130),
  ('3101','Capital','capital','CL','patrimonio','credito',0,210),
  ('3201','Resultado del ejercicio','utilidad','CL','patrimonio','credito',0,220),
  ('4101','Ventas','ingresos','CL','ingreso','credito',0,310),
  ('5101','Costo de ventas','costo_ventas','CL','costo','debito',0,410),
  ('5201','Remuneraciones','gasto_nomina','CL','gasto','debito',0,510),
  ('5202','Depreciación','gasto_depreciacion','CL','gasto','debito',0,520),
  ('5203','Gastos de administración y ventas','gastos_operativos','CL','gasto','debito',0,530),
  ('5204','Mermas y donaciones','obsequios_mermas','CL','gasto','debito',0,540);

-- ── Localización (país activo = CL) ───────────────────────────────────
INSERT INTO `configuracion_app` (`clave`,`valor`) VALUES
  ('pais','CL'),('moneda_codigo','CLP'),('moneda_simbolo','$'),
  ('moneda_decimales','0'),('impuesto_nombre','IVA'),('factura_modo','interno')
ON DUPLICATE KEY UPDATE `valor`=VALUES(`valor`);

INSERT INTO `configuracion_negocio` (`clave`,`valor`,`descripcion`,`categoria`) VALUES
  ('iva_tarifa',19,'Tarifa del impuesto de ventas por defecto (%)','impuestos')
ON DUPLICATE KEY UPDATE `valor`=VALUES(`valor`);

-- ============================================================
-- FIN country pack CL
-- ============================================================
