-- ============================================================
-- Country pack — ESPAÑA (ES)   ·  multi-país
-- ============================================================
-- Plan de cuentas basado en el PGC (Plan General de Contabilidad) mapeado a los ROLES
-- semánticos del motor contable + localización (moneda EUR, IVA 21%). Aplicar sobre una
-- BD ya creada con schema.sql. Idempotente. NO lleva USE.
--
-- ⚠️ ARRANQUE / A VALIDAR: los códigos PGC son un punto de partida razonable; el plan de
-- cuentas definitivo y su uso fiscal deben revisarse con un ASESOR/CONTABLE ESPAÑOL. Lo
-- que el motor usa es el ROL (no el código). En el PGC el "costo de ventas" no es una
-- única cuenta (se obtiene por compras +/- variación de existencias); aquí se mapea a la
-- cuenta de compras (600) como aproximación de arranque. La NÓMINA (Seguridad Social,
-- IRPF) y la FACTURACIÓN (AEAT: Veri*Factu / TicketBAI / SII) son fases posteriores.
-- ============================================================

SET NAMES utf8mb4;

-- ── Plan de cuentas (PGC) → roles ─────────────────────────────────────────
INSERT IGNORE INTO `cuentas_contables` (`codigo`,`nombre`,`rol`,`pais`,`tipo`,`naturaleza`,`es_contra`,`orden`) VALUES
  ('570','Caja, euros',                       'caja',                  'ES','activo','debito',0,10),
  ('572','Bancos c/c',                        'bancos',                'ES','activo','debito',0,20),
  ('430','Clientes',                          'cxc_fiado',             'ES','activo','debito',0,30),
  ('350','Productos terminados',              'inv_terminado',         'ES','activo','debito',0,40),
  ('310','Materias primas',                   'inv_insumos',           'ES','activo','debito',0,50),
  ('472','H.P. IVA soportado',                'imp_descontable',       'ES','activo','debito',0,55),
  ('216','Mobiliario y equipo',               'activos_fijos',         'ES','activo','debito',0,60),
  ('281','Amortización acumulada del inmovilizado','deprec_acumulada', 'ES','activo','credito',1,70),
  ('400','Proveedores',                       'proveedores_por_pagar', 'ES','pasivo','credito',0,110),
  ('477','H.P. IVA repercutido',              'imp_ventas_por_pagar',  'ES','pasivo','credito',0,120),
  ('465','Remuneraciones pendientes de pago', 'nomina_por_pagar',      'ES','pasivo','credito',0,130),
  ('100','Capital social',                    'capital',               'ES','patrimonio','credito',0,210),
  ('129','Resultado del ejercicio',           'utilidad',              'ES','patrimonio','credito',0,220),
  ('700','Ventas de mercaderías',             'ingresos',              'ES','ingreso','credito',0,310),
  ('600','Compras / Coste de ventas',         'costo_ventas',          'ES','costo','debito',0,410),
  ('640','Sueldos y salarios',                'gasto_nomina',          'ES','gasto','debito',0,510),
  ('681','Amortización del inmovilizado',     'gasto_depreciacion',    'ES','gasto','debito',0,520),
  ('629','Otros servicios (gastos operativos)','gastos_operativos',    'ES','gasto','debito',0,530),
  ('659','Otras pérdidas en gestión corriente (mermas/obsequios)','obsequios_mermas','ES','gasto','debito',0,540);

-- ── Localización (país activo = ES) ───────────────────────────────────────
INSERT INTO `configuracion_app` (`clave`,`valor`) VALUES
  ('pais','ES'),('moneda_codigo','EUR'),('moneda_simbolo','€'),
  ('moneda_decimales','2'),('impuesto_nombre','IVA'),('factura_modo','interno')
ON DUPLICATE KEY UPDATE `valor`=VALUES(`valor`);

INSERT INTO `configuracion_negocio` (`clave`,`valor`,`descripcion`,`categoria`) VALUES
  ('iva_tarifa',21,'Tarifa del impuesto de ventas por defecto (%)','impuestos')
ON DUPLICATE KEY UPDATE `valor`=VALUES(`valor`);

-- ============================================================
-- FIN country pack ES
-- ============================================================
