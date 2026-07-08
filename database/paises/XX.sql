-- ============================================================
-- Country pack — GENÉRICO / CONFIGURABLE (XX)   ·  Fase B multi-país (v6.3)
-- ============================================================
-- Plan de cuentas NEUTRO (códigos numéricos simples) mapeado a los ROLES del motor
-- contable. Punto de partida para cualquier país que aún no tenga pack dedicado, o
-- para negocios que quieran su propia numeración. Aplicar sobre schema.sql.
-- Idempotente. NO lleva USE.
--
-- Al aplicar: siembra 19 cuentas genéricas (pais='XX') y deja país=XX con moneda y
-- nombre de impuesto GENÉRICOS que el superadmin ajusta en Admin → Localización.
-- La numeración es arbitraria — lo que el motor usa es el ROL.
-- ============================================================

SET NAMES utf8mb4;

-- ── Plan de cuentas genérico → roles ──────────────────────────────────────
INSERT IGNORE INTO `cuentas_contables` (`codigo`,`nombre`,`rol`,`pais`,`tipo`,`naturaleza`,`es_contra`,`orden`) VALUES
  ('1000','Caja',                          'caja',                  'XX','activo','debito',0,10),
  ('1010','Bancos',                        'bancos',                'XX','activo','debito',0,20),
  ('1100','Cuentas por cobrar',            'cxc_fiado',             'XX','activo','debito',0,30),
  ('1200','Inventario producto terminado', 'inv_terminado',         'XX','activo','debito',0,40),
  ('1210','Inventario de insumos',         'inv_insumos',           'XX','activo','debito',0,50),
  ('1300','Impuesto de ventas a favor',    'imp_descontable',       'XX','activo','debito',0,55),
  ('1500','Activos fijos',                 'activos_fijos',         'XX','activo','debito',0,60),
  ('1590','Depreciación acumulada',        'deprec_acumulada',      'XX','activo','credito',1,70),
  ('2000','Proveedores por pagar',         'proveedores_por_pagar', 'XX','pasivo','credito',0,110),
  ('2100','Impuesto de ventas por pagar',  'imp_ventas_por_pagar',  'XX','pasivo','credito',0,120),
  ('2200','Nómina por pagar',              'nomina_por_pagar',      'XX','pasivo','credito',0,130),
  ('3000','Capital',                       'capital',               'XX','patrimonio','credito',0,210),
  ('3100','Utilidad del ejercicio',        'utilidad',              'XX','patrimonio','credito',0,220),
  ('4000','Ingresos por ventas',           'ingresos',              'XX','ingreso','credito',0,310),
  ('5000','Costo de ventas',               'costo_ventas',          'XX','costo','debito',0,410),
  ('6000','Gastos de nómina',              'gasto_nomina',          'XX','gasto','debito',0,510),
  ('6100','Gasto por depreciación',        'gasto_depreciacion',    'XX','gasto','debito',0,520),
  ('6200','Gastos operativos',             'gastos_operativos',     'XX','gasto','debito',0,530),
  ('6300','Obsequios y mermas',            'obsequios_mermas',      'XX','gasto','debito',0,540);

-- ── Localización (país activo = XX genérico; ajustar en Admin) ─────────────
INSERT INTO `configuracion_app` (`clave`,`valor`) VALUES
  ('pais','XX'),('moneda_codigo','USD'),('moneda_simbolo','$'),
  ('moneda_decimales','2'),('impuesto_nombre','Impuesto'),('factura_modo','interno')
ON DUPLICATE KEY UPDATE `valor`=VALUES(`valor`);

INSERT INTO `configuracion_negocio` (`clave`,`valor`,`descripcion`,`categoria`) VALUES
  ('iva_tarifa',0,'Tarifa del impuesto de ventas por defecto (%)','impuestos')
ON DUPLICATE KEY UPDATE `valor`=VALUES(`valor`);

-- ============================================================
-- FIN country pack XX (genérico)
-- ============================================================
