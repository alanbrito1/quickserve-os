-- ============================================================
-- Country pack — Brasil (BR)   ·  multi-país
-- ============================================================
-- Plan de cuentas mapeado a los ROLES semánticos del motor contable + localización
-- (BRL, ICMS/ISS 17%). Aplicar sobre schema.sql. Idempotente. NO lleva USE.
--
-- ⚠️ ARRANQUE / A VALIDAR: plano referencial SPED ECD (nombres en portugués). Estos códigos son un punto de partida; el plan de
-- cuentas definitivo y su uso fiscal deben definirse/revisarse con un CONTADOR del país.
-- Lo que el motor usa es el ROL (no el código), así que se pueden ajustar sin tocar código.
-- La NÓMINA y la FACTURACIÓN legal de este país son fases posteriores (asesor/PAC local).
-- ============================================================

SET NAMES utf8mb4;

-- ── Plan de cuentas → roles ───────────────────────────────────────────────
INSERT IGNORE INTO `cuentas_contables` (`codigo`,`nombre`,`rol`,`pais`,`tipo`,`naturaleza`,`es_contra`,`orden`) VALUES
  ('1.01.01','Caixa','caja','BR','activo','debito',0,10),
  ('1.01.02','Bancos conta movimento','bancos','BR','activo','debito',0,20),
  ('1.01.03','Clientes (duplicatas a receber)','cxc_fiado','BR','activo','debito',0,30),
  ('1.01.04','Estoques — produtos acabados','inv_terminado','BR','activo','debito',0,40),
  ('1.01.05','Estoques — matérias-primas','inv_insumos','BR','activo','debito',0,50),
  ('1.01.06','ICMS a recuperar','imp_descontable','BR','activo','debito',0,55),
  ('1.02.01','Imobilizado','activos_fijos','BR','activo','debito',0,60),
  ('1.02.09','Depreciação acumulada','deprec_acumulada','BR','activo','credito',1,70),
  ('2.01.01','Fornecedores','proveedores_por_pagar','BR','pasivo','credito',0,110),
  ('2.01.02','ICMS a recolher','imp_ventas_por_pagar','BR','pasivo','credito',0,120),
  ('2.01.03','Salários a pagar','nomina_por_pagar','BR','pasivo','credito',0,130),
  ('2.03.01','Capital social','capital','BR','patrimonio','credito',0,210),
  ('2.03.05','Resultado do exercício','utilidad','BR','patrimonio','credito',0,220),
  ('3.01.01','Receita de vendas','ingresos','BR','ingreso','credito',0,310),
  ('3.02.01','Custo das mercadorias vendidas (CMV)','costo_ventas','BR','costo','debito',0,410),
  ('3.03.01','Salários e encargos','gasto_nomina','BR','gasto','debito',0,510),
  ('3.03.02','Depreciação','gasto_depreciacion','BR','gasto','debito',0,520),
  ('3.03.03','Despesas gerais','gastos_operativos','BR','gasto','debito',0,530),
  ('3.03.04','Perdas e quebras de estoque','obsequios_mermas','BR','gasto','debito',0,540);

-- ── Localización (país activo = BR) ───────────────────────────────────
INSERT INTO `configuracion_app` (`clave`,`valor`) VALUES
  ('pais','BR'),('moneda_codigo','BRL'),('moneda_simbolo','R$'),
  ('moneda_decimales','2'),('impuesto_nombre','ICMS/ISS'),('factura_modo','interno')
ON DUPLICATE KEY UPDATE `valor`=VALUES(`valor`);

INSERT INTO `configuracion_negocio` (`clave`,`valor`,`descripcion`,`categoria`) VALUES
  ('iva_tarifa',17,'Tarifa del impuesto de ventas por defecto (%)','impuestos')
ON DUPLICATE KEY UPDATE `valor`=VALUES(`valor`);

-- ============================================================
-- FIN country pack BR
-- ============================================================
