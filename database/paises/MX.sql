-- ============================================================
-- Country pack — MÉXICO (MX)   ·  Fase B multi-país (v6.3)
-- ============================================================
-- Plan de cuentas basado en el CÓDIGO AGRUPADOR DEL SAT (Anexo 24 RMF) mapeado a
-- los ROLES semánticos del motor contable + localización (moneda MXN, IVA 16%).
-- Aplicar sobre una BD ya creada con schema.sql. Idempotente. NO lleva USE.
--
-- Al aplicar este pack la instancia queda operando como MÉXICO:
--   · siembra las 19 cuentas (código agrupador SAT) con su rol (pais='MX')
--   · fija país=MX, moneda=MXN ($, 2 decimales), impuesto=IVA (16%), factura=interno
--
-- ⚠️ ARRANQUE / A VALIDAR: los códigos agrupadores son un punto de partida
-- razonable; el plan de cuentas definitivo y su uso fiscal deben ser revisados por
-- un CONTADOR MEXICANO. Lo que el motor usa es el ROL (no el código), así que los
-- códigos pueden ajustarse sin tocar código de la aplicación. La NÓMINA (ISR/IMSS)
-- y la FACTURACIÓN (CFDI 4.0/PAC) son fases posteriores (C y D), con asesoría local.
-- ============================================================

SET NAMES utf8mb4;

-- ── Plan de cuentas (código agrupador SAT) → roles ────────────────────────
INSERT IGNORE INTO `cuentas_contables` (`codigo`,`nombre`,`rol`,`pais`,`tipo`,`naturaleza`,`es_contra`,`orden`) VALUES
  ('100.01','Caja',                          'caja',                  'MX','activo','debito',0,10),
  ('102.01','Bancos nacionales',             'bancos',                'MX','activo','debito',0,20),
  ('105.01','Clientes (cuentas por cobrar)', 'cxc_fiado',             'MX','activo','debito',0,30),
  ('115.01','Inventario producto terminado', 'inv_terminado',         'MX','activo','debito',0,40),
  ('115.05','Inventario de materia prima',   'inv_insumos',           'MX','activo','debito',0,50),
  ('118.01','IVA acreditable pagado',        'imp_descontable',       'MX','activo','debito',0,55),
  ('152.01','Mobiliario y equipo',           'activos_fijos',         'MX','activo','debito',0,60),
  ('153.01','Depreciación acumulada',        'deprec_acumulada',      'MX','activo','credito',1,70),
  ('201.01','Proveedores nacionales',        'proveedores_por_pagar', 'MX','pasivo','credito',0,110),
  ('208.01','IVA trasladado (por pagar)',    'imp_ventas_por_pagar',  'MX','pasivo','credito',0,120),
  ('211.01','Sueldos y salarios por pagar',  'nomina_por_pagar',      'MX','pasivo','credito',0,130),
  ('301.01','Capital social',                'capital',               'MX','patrimonio','credito',0,210),
  ('306.01','Resultado del ejercicio',       'utilidad',              'MX','patrimonio','credito',0,220),
  ('401.01','Ventas y/o servicios gravados', 'ingresos',              'MX','ingreso','credito',0,310),
  ('501.01','Costo de venta',                'costo_ventas',          'MX','costo','debito',0,410),
  ('601.01','Sueldos y salarios',            'gasto_nomina',          'MX','gasto','debito',0,510),
  ('601.84','Depreciaciones',                'gasto_depreciacion',    'MX','gasto','debito',0,520),
  ('601.99','Gastos generales (indirectos)', 'gastos_operativos',     'MX','gasto','debito',0,530),
  ('601.85','Mermas y obsequios',            'obsequios_mermas',      'MX','gasto','debito',0,540);

-- ── Localización (país activo = MX) ───────────────────────────────────────
INSERT INTO `configuracion_app` (`clave`,`valor`) VALUES
  ('pais','MX'),('moneda_codigo','MXN'),('moneda_simbolo','$'),
  ('moneda_decimales','2'),('impuesto_nombre','IVA'),('factura_modo','interno')
ON DUPLICATE KEY UPDATE `valor`=VALUES(`valor`);

-- Tarifa de referencia del IVA general en México = 16%.
INSERT INTO `configuracion_negocio` (`clave`,`valor`,`descripcion`,`categoria`) VALUES
  ('iva_tarifa',16,'Tarifa del impuesto de ventas por defecto (%)','impuestos')
ON DUPLICATE KEY UPDATE `valor`=VALUES(`valor`);

-- ============================================================
-- FIN country pack MX
-- ============================================================
