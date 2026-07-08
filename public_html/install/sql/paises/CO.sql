-- ============================================================
-- Country pack — COLOMBIA (CO)   ·  Fase B multi-país (v6.3)
-- ============================================================
-- Plan de cuentas (PUC simplificado) mapeado a los ROLES semánticos del motor
-- contable + localización (moneda COP, IVA). Aplicar sobre una BD ya creada con
-- schema.sql. Idempotente (INSERT IGNORE / ON DUPLICATE KEY UPDATE). NO lleva USE.
--
-- Al aplicar este pack, la instancia queda operando como COLOMBIA:
--   · siembra/asegura las 19 cuentas PUC con su rol (pais='CO')
--   · fija país=CO, moneda=COP ($, 0 decimales), impuesto=IVA (19%), factura=interno
--
-- Solo UN país activo por instancia (lo determina configuracion_app.pais). El
-- superadmin también puede cambiarlo en Admin → Apariencia → Localización.
-- ============================================================

SET NAMES utf8mb4;

-- ── Plan de cuentas PUC (Colombia) → roles ────────────────────────────────
INSERT IGNORE INTO `cuentas_contables` (`codigo`,`nombre`,`rol`,`pais`,`tipo`,`naturaleza`,`es_contra`,`orden`) VALUES
  ('1105','Caja',                          'caja',                  'CO','activo','debito',0,10),
  ('1110','Bancos',                        'bancos',                'CO','activo','debito',0,20),
  ('1305','Cuentas por cobrar (fiado)',    'cxc_fiado',             'CO','activo','debito',0,30),
  ('1430','Inventario producto terminado', 'inv_terminado',         'CO','activo','debito',0,40),
  ('1435','Inventario de insumos',         'inv_insumos',           'CO','activo','debito',0,50),
  ('1355','IVA descontable',               'imp_descontable',       'CO','activo','debito',0,55),
  ('1524','Activos fijos',                 'activos_fijos',         'CO','activo','debito',0,60),
  ('1592','Depreciación acumulada',        'deprec_acumulada',      'CO','activo','credito',1,70),
  ('2205','Proveedores por pagar',         'proveedores_por_pagar', 'CO','pasivo','credito',0,110),
  ('2408','IVA por pagar',                 'imp_ventas_por_pagar',  'CO','pasivo','credito',0,120),
  ('2510','Nómina por pagar',              'nomina_por_pagar',      'CO','pasivo','credito',0,130),
  ('3115','Capital social',                'capital',               'CO','patrimonio','credito',0,210),
  ('3705','Utilidad del ejercicio',        'utilidad',              'CO','patrimonio','credito',0,220),
  ('4135','Ingresos por ventas',           'ingresos',              'CO','ingreso','credito',0,310),
  ('6135','Costo de ventas',               'costo_ventas',          'CO','costo','debito',0,410),
  ('5105','Gastos de nómina',              'gasto_nomina',          'CO','gasto','debito',0,510),
  ('5160','Gasto por depreciación',        'gasto_depreciacion',    'CO','gasto','debito',0,520),
  ('5195','Gastos operativos (indirectos)','gastos_operativos',     'CO','gasto','debito',0,530),
  ('5199','Obsequios y mermas',            'obsequios_mermas',      'CO','gasto','debito',0,540);

-- ── Localización (país activo = CO) ───────────────────────────────────────
INSERT INTO `configuracion_app` (`clave`,`valor`) VALUES
  ('pais','CO'),('moneda_codigo','COP'),('moneda_simbolo','$'),
  ('moneda_decimales','0'),('impuesto_nombre','IVA'),('factura_modo','interno')
ON DUPLICATE KEY UPDATE `valor`=VALUES(`valor`);

-- Tarifa de referencia del impuesto de ventas (IVA general Colombia = 19%).
-- No activa la discriminación (iva_activo se maneja aparte, régimen simple por defecto).
INSERT INTO `configuracion_negocio` (`clave`,`valor`,`descripcion`,`categoria`) VALUES
  ('iva_tarifa',19,'Tarifa del impuesto de ventas por defecto (%)','impuestos')
ON DUPLICATE KEY UPDATE `valor`=VALUES(`valor`);

-- ============================================================
-- FIN country pack CO
-- ============================================================
