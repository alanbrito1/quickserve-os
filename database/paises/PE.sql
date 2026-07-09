-- ============================================================
-- Country pack — PERÚ (PE)   ·  multi-país
-- ============================================================
-- Plan de cuentas basado en el PCGE (Plan Contable General Empresarial) mapeado a los
-- ROLES semánticos del motor contable + localización (moneda PEN, IGV 18%). Aplicar
-- sobre una BD ya creada con schema.sql. Idempotente. NO lleva USE.
--
-- ⚠️ ARRANQUE / A VALIDAR: los códigos PCGE son un punto de partida razonable; el plan
-- de cuentas definitivo y su uso tributario deben revisarse con un CONTADOR PERUANO. Lo
-- que el motor usa es el ROL (no el código), así que los códigos se pueden ajustar sin
-- tocar código de la app. La NÓMINA (EsSalud/AFP/ONP/CTS/gratificaciones) y la
-- FACTURACIÓN (SUNAT/OSE/PSE) son fases posteriores, con asesoría local.
-- ============================================================

SET NAMES utf8mb4;

-- ── Plan de cuentas (PCGE) → roles ────────────────────────────────────────
INSERT IGNORE INTO `cuentas_contables` (`codigo`,`nombre`,`rol`,`pais`,`tipo`,`naturaleza`,`es_contra`,`orden`) VALUES
  ('101',   'Caja',                              'caja',                  'PE','activo','debito',0,10),
  ('104',   'Cuentas corrientes (bancos)',       'bancos',                'PE','activo','debito',0,20),
  ('121',   'Facturas por cobrar (clientes)',    'cxc_fiado',             'PE','activo','debito',0,30),
  ('201',   'Mercaderías (producto terminado)',  'inv_terminado',         'PE','activo','debito',0,40),
  ('241',   'Materias primas (insumos)',         'inv_insumos',           'PE','activo','debito',0,50),
  ('40111', 'IGV - Crédito fiscal',              'imp_descontable',       'PE','activo','debito',0,55),
  ('333',   'Maquinaria y equipo',               'activos_fijos',         'PE','activo','debito',0,60),
  ('391',   'Depreciación acumulada',            'deprec_acumulada',      'PE','activo','credito',1,70),
  ('421',   'Facturas por pagar (proveedores)',  'proveedores_por_pagar', 'PE','pasivo','credito',0,110),
  ('40112', 'IGV por pagar',                     'imp_ventas_por_pagar',  'PE','pasivo','credito',0,120),
  ('411',   'Remuneraciones por pagar',          'nomina_por_pagar',      'PE','pasivo','credito',0,130),
  ('501',   'Capital',                           'capital',               'PE','patrimonio','credito',0,210),
  ('591',   'Resultados acumulados',             'utilidad',              'PE','patrimonio','credito',0,220),
  ('701',   'Ventas - Mercaderías',              'ingresos',              'PE','ingreso','credito',0,310),
  ('691',   'Costo de ventas',                   'costo_ventas',          'PE','costo','debito',0,410),
  ('621',   'Remuneraciones (cargas de personal)','gasto_nomina',         'PE','gasto','debito',0,510),
  ('681',   'Depreciación del ejercicio',        'gasto_depreciacion',    'PE','gasto','debito',0,520),
  ('639',   'Otros servicios (gastos operativos)','gastos_operativos',    'PE','gasto','debito',0,530),
  ('659',   'Otros gastos de gestión (mermas/obsequios)','obsequios_mermas','PE','gasto','debito',0,540);

-- ── Localización (país activo = PE) ───────────────────────────────────────
INSERT INTO `configuracion_app` (`clave`,`valor`) VALUES
  ('pais','PE'),('moneda_codigo','PEN'),('moneda_simbolo','S/'),
  ('moneda_decimales','2'),('impuesto_nombre','IGV'),('factura_modo','interno')
ON DUPLICATE KEY UPDATE `valor`=VALUES(`valor`);

INSERT INTO `configuracion_negocio` (`clave`,`valor`,`descripcion`,`categoria`) VALUES
  ('iva_tarifa',18,'Tarifa del impuesto de ventas por defecto (%)','impuestos')
ON DUPLICATE KEY UPDATE `valor`=VALUES(`valor`);

-- ============================================================
-- FIN country pack PE
-- ============================================================
