-- ============================================================
-- Country pack — Argentina (AR)   ·  multi-país
-- ============================================================
-- Plan de cuentas mapeado a los ROLES semánticos del motor contable + localización
-- (ARS, IVA 21%). Aplicar sobre schema.sql. Idempotente. NO lleva USE.
--
-- ⚠️ ARRANQUE / A VALIDAR: no tiene un plan único obligatorio (guías FACPCE). Estos códigos son un punto de partida; el plan de
-- cuentas definitivo y su uso fiscal deben definirse/revisarse con un CONTADOR del país.
-- Lo que el motor usa es el ROL (no el código), así que se pueden ajustar sin tocar código.
-- La NÓMINA y la FACTURACIÓN legal de este país son fases posteriores (asesor/PAC local).
-- ============================================================

SET NAMES utf8mb4;

-- ── Plan de cuentas → roles ───────────────────────────────────────────────
INSERT IGNORE INTO `cuentas_contables` (`codigo`,`nombre`,`rol`,`pais`,`tipo`,`naturaleza`,`es_contra`,`orden`) VALUES
  ('11101','Caja','caja','AR','activo','debito',0,10),
  ('11102','Banco cuenta corriente','bancos','AR','activo','debito',0,20),
  ('11201','Deudores por ventas','cxc_fiado','AR','activo','debito',0,30),
  ('11301','Bienes de cambio — productos terminados','inv_terminado','AR','activo','debito',0,40),
  ('11302','Bienes de cambio — materias primas','inv_insumos','AR','activo','debito',0,50),
  ('11401','IVA crédito fiscal','imp_descontable','AR','activo','debito',0,55),
  ('12101','Bienes de uso','activos_fijos','AR','activo','debito',0,60),
  ('12109','Amortización acumulada','deprec_acumulada','AR','activo','credito',1,70),
  ('21101','Proveedores','proveedores_por_pagar','AR','pasivo','credito',0,110),
  ('21201','IVA débito fiscal','imp_ventas_por_pagar','AR','pasivo','credito',0,120),
  ('21301','Sueldos a pagar','nomina_por_pagar','AR','pasivo','credito',0,130),
  ('31101','Capital','capital','AR','patrimonio','credito',0,210),
  ('32101','Resultado del ejercicio','utilidad','AR','patrimonio','credito',0,220),
  ('41101','Ventas','ingresos','AR','ingreso','credito',0,310),
  ('51101','Costo de mercaderías vendidas','costo_ventas','AR','costo','debito',0,410),
  ('52101','Sueldos y jornales','gasto_nomina','AR','gasto','debito',0,510),
  ('52102','Amortizaciones','gasto_depreciacion','AR','gasto','debito',0,520),
  ('52103','Gastos de administración y comercialización','gastos_operativos','AR','gasto','debito',0,530),
  ('52104','Mermas y donaciones','obsequios_mermas','AR','gasto','debito',0,540);

-- ── Localización (país activo = AR) ───────────────────────────────────
INSERT INTO `configuracion_app` (`clave`,`valor`) VALUES
  ('pais','AR'),('moneda_codigo','ARS'),('moneda_simbolo','$'),
  ('moneda_decimales','2'),('impuesto_nombre','IVA'),('factura_modo','interno')
ON DUPLICATE KEY UPDATE `valor`=VALUES(`valor`);

INSERT INTO `configuracion_negocio` (`clave`,`valor`,`descripcion`,`categoria`) VALUES
  ('iva_tarifa',21,'Tarifa del impuesto de ventas por defecto (%)','impuestos')
ON DUPLICATE KEY UPDATE `valor`=VALUES(`valor`);

-- ============================================================
-- FIN country pack AR
-- ============================================================
