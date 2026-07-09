-- ============================================================
-- Country pack — Panamá (PA)   ·  multi-país
-- ============================================================
-- Plan de cuentas mapeado a los ROLES semánticos del motor contable + localización
-- (USD, ITBMS 7%). Aplicar sobre schema.sql. Idempotente. NO lleva USE.
--
-- ⚠️ ARRANQUE / A VALIDAR: uso conforme a NIIF (sin plan numérico único obligatorio). Estos códigos son un punto de partida; el plan de
-- cuentas definitivo y su uso fiscal deben definirse/revisarse con un CONTADOR del país.
-- Lo que el motor usa es el ROL (no el código), así que se pueden ajustar sin tocar código.
-- La NÓMINA y la FACTURACIÓN legal de este país son fases posteriores (asesor/PAC local).
-- ============================================================

SET NAMES utf8mb4;

-- ── Plan de cuentas → roles ───────────────────────────────────────────────
INSERT IGNORE INTO `cuentas_contables` (`codigo`,`nombre`,`rol`,`pais`,`tipo`,`naturaleza`,`es_contra`,`orden`) VALUES
  ('1101','Caja','caja','PA','activo','debito',0,10),
  ('1102','Bancos','bancos','PA','activo','debito',0,20),
  ('1105','Cuentas por cobrar (clientes)','cxc_fiado','PA','activo','debito',0,30),
  ('1201','Inventario — mercancía terminada','inv_terminado','PA','activo','debito',0,40),
  ('1202','Inventario — materia prima','inv_insumos','PA','activo','debito',0,50),
  ('1108','ITBMS pagado (crédito fiscal)','imp_descontable','PA','activo','debito',0,55),
  ('1401','Mobiliario y equipo','activos_fijos','PA','activo','debito',0,60),
  ('1409','Depreciación acumulada','deprec_acumulada','PA','activo','credito',1,70),
  ('2101','Proveedores','proveedores_por_pagar','PA','pasivo','credito',0,110),
  ('2103','ITBMS por pagar','imp_ventas_por_pagar','PA','pasivo','credito',0,120),
  ('2105','Salarios por pagar','nomina_por_pagar','PA','pasivo','credito',0,130),
  ('3101','Capital','capital','PA','patrimonio','credito',0,210),
  ('3201','Utilidad del período','utilidad','PA','patrimonio','credito',0,220),
  ('4101','Ventas','ingresos','PA','ingreso','credito',0,310),
  ('5101','Costo de ventas','costo_ventas','PA','costo','debito',0,410),
  ('6101','Salarios','gasto_nomina','PA','gasto','debito',0,510),
  ('6102','Depreciación','gasto_depreciacion','PA','gasto','debito',0,520),
  ('6103','Gastos generales','gastos_operativos','PA','gasto','debito',0,530),
  ('6104','Mermas y donaciones','obsequios_mermas','PA','gasto','debito',0,540);

-- ── Localización (país activo = PA) ───────────────────────────────────
INSERT INTO `configuracion_app` (`clave`,`valor`) VALUES
  ('pais','PA'),('moneda_codigo','USD'),('moneda_simbolo','$'),
  ('moneda_decimales','2'),('impuesto_nombre','ITBMS'),('factura_modo','interno')
ON DUPLICATE KEY UPDATE `valor`=VALUES(`valor`);

INSERT INTO `configuracion_negocio` (`clave`,`valor`,`descripcion`,`categoria`) VALUES
  ('iva_tarifa',7,'Tarifa del impuesto de ventas por defecto (%)','impuestos')
ON DUPLICATE KEY UPDATE `valor`=VALUES(`valor`);

-- ============================================================
-- FIN country pack PA
-- ============================================================
