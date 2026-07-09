-- ============================================================
-- Country pack — Ecuador (EC)   ·  multi-país
-- ============================================================
-- Plan de cuentas mapeado a los ROLES semánticos del motor contable + localización
-- (USD, IVA 15%). Aplicar sobre schema.sql. Idempotente. NO lleva USE.
--
-- ⚠️ ARRANQUE / A VALIDAR: Superintendencia de Compañías (plan NIIF estandarizado) — referencia. Estos códigos son un punto de partida; el plan de
-- cuentas definitivo y su uso fiscal deben definirse/revisarse con un CONTADOR del país.
-- Lo que el motor usa es el ROL (no el código), así que se pueden ajustar sin tocar código.
-- La NÓMINA y la FACTURACIÓN legal de este país son fases posteriores (asesor/PAC local).
-- ============================================================

SET NAMES utf8mb4;

-- ── Plan de cuentas → roles ───────────────────────────────────────────────
INSERT IGNORE INTO `cuentas_contables` (`codigo`,`nombre`,`rol`,`pais`,`tipo`,`naturaleza`,`es_contra`,`orden`) VALUES
  ('10101','Efectivo y equivalentes (caja)','caja','EC','activo','debito',0,10),
  ('10102','Bancos','bancos','EC','activo','debito',0,20),
  ('10201','Cuentas por cobrar clientes','cxc_fiado','EC','activo','debito',0,30),
  ('10301','Inventario de productos terminados','inv_terminado','EC','activo','debito',0,40),
  ('10305','Inventario de materia prima','inv_insumos','EC','activo','debito',0,50),
  ('10107','Crédito tributario IVA','imp_descontable','EC','activo','debito',0,55),
  ('10401','Propiedad, planta y equipo','activos_fijos','EC','activo','debito',0,60),
  ('10406','Depreciación acumulada','deprec_acumulada','EC','activo','credito',1,70),
  ('20101','Cuentas y documentos por pagar (proveedores)','proveedores_por_pagar','EC','pasivo','credito',0,110),
  ('20107','IVA por pagar','imp_ventas_por_pagar','EC','pasivo','credito',0,120),
  ('20103','Obligaciones con empleados','nomina_por_pagar','EC','pasivo','credito',0,130),
  ('30101','Capital suscrito','capital','EC','patrimonio','credito',0,210),
  ('30701','Resultado del ejercicio','utilidad','EC','patrimonio','credito',0,220),
  ('40101','Venta de bienes','ingresos','EC','ingreso','credito',0,310),
  ('50101','Costo de ventas','costo_ventas','EC','costo','debito',0,410),
  ('52101','Sueldos y salarios','gasto_nomina','EC','gasto','debito',0,510),
  ('52201','Depreciaciones','gasto_depreciacion','EC','gasto','debito',0,520),
  ('52301','Gastos generales','gastos_operativos','EC','gasto','debito',0,530),
  ('52302','Mermas y bajas de inventario','obsequios_mermas','EC','gasto','debito',0,540);

-- ── Localización (país activo = EC) ───────────────────────────────────
INSERT INTO `configuracion_app` (`clave`,`valor`) VALUES
  ('pais','EC'),('moneda_codigo','USD'),('moneda_simbolo','$'),
  ('moneda_decimales','2'),('impuesto_nombre','IVA'),('factura_modo','interno')
ON DUPLICATE KEY UPDATE `valor`=VALUES(`valor`);

INSERT INTO `configuracion_negocio` (`clave`,`valor`,`descripcion`,`categoria`) VALUES
  ('iva_tarifa',15,'Tarifa del impuesto de ventas por defecto (%)','impuestos')
ON DUPLICATE KEY UPDATE `valor`=VALUES(`valor`);

-- ============================================================
-- FIN country pack EC
-- ============================================================
