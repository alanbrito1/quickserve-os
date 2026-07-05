-- ============================================================
-- Migración 045 — Contabilidad de partida doble (Fase 4a)
-- ============================================================
-- Subsistema contable: plan de cuentas + libro diario (asientos) + líneas.
-- Todo asiento cumple partida doble: SUM(debe) = SUM(haber).
-- El Balance General sale de los saldos de cuentas: Activo = Pasivo + Patrimonio.
-- Idempotente: CREATE TABLE IF NOT EXISTS + INSERT IGNORE en seeds.
-- ============================================================


-- Plan de cuentas (catálogo simplificado a la medida)
CREATE TABLE IF NOT EXISTS cuentas_contables (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    codigo      VARCHAR(10)  NOT NULL,
    nombre      VARCHAR(120) NOT NULL,
    tipo        ENUM('activo','pasivo','patrimonio','ingreso','costo','gasto') NOT NULL,
    naturaleza  ENUM('debito','credito') NOT NULL,   -- saldo normal de la cuenta
    es_contra   TINYINT(1)   NOT NULL DEFAULT 0,       -- contra-cuenta (ej. depreciación acumulada)
    activo      TINYINT(1)   NOT NULL DEFAULT 1,
    orden       SMALLINT     NOT NULL DEFAULT 100,
    UNIQUE KEY uq_cuenta_codigo (codigo),
    INDEX idx_cuenta_tipo (tipo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Libro diario — cabecera del asiento
CREATE TABLE IF NOT EXISTS asientos (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    fecha       DATE         NOT NULL,
    descripcion VARCHAR(255) NOT NULL,
    origen      VARCHAR(20)  NOT NULL DEFAULT 'manual', -- venta|compra|nomina|abono|produccion|obsequio|pago_prov|capital|apertura|manual|reversa
    origen_id   INT          DEFAULT NULL,               -- FK lógica a la transacción de origen
    anulado     TINYINT(1)   NOT NULL DEFAULT 0,
    created_by  INT          DEFAULT NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_asiento_fecha (fecha),
    INDEX idx_asiento_origen (origen, origen_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Líneas del asiento (cada una usa debe O haber, no ambos)
CREATE TABLE IF NOT EXISTS asiento_lineas (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asiento_id  INT UNSIGNED NOT NULL,
    cuenta_id   INT UNSIGNED NOT NULL,
    debe        DECIMAL(14,2) NOT NULL DEFAULT 0,
    haber       DECIMAL(14,2) NOT NULL DEFAULT 0,
    CONSTRAINT fk_al_asiento FOREIGN KEY (asiento_id) REFERENCES asientos(id) ON DELETE CASCADE,
    CONSTRAINT fk_al_cuenta  FOREIGN KEY (cuenta_id)  REFERENCES cuentas_contables(id),
    INDEX idx_al_asiento (asiento_id),
    INDEX idx_al_cuenta (cuenta_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Seed del plan de cuentas simplificado ─────────────────────────────────────
INSERT IGNORE INTO cuentas_contables (codigo, nombre, tipo, naturaleza, es_contra, orden) VALUES
  ('1105','Caja',                        'activo','debito',0,10),
  ('1110','Bancos',                      'activo','debito',0,20),
  ('1305','Cuentas por cobrar (fiado)',  'activo','debito',0,30),
  ('1430','Inventario producto terminado','activo','debito',0,40),
  ('1435','Inventario de insumos',       'activo','debito',0,50),
  ('1355','IVA descontable',             'activo','debito',0,55),
  ('1524','Activos fijos',               'activo','debito',0,60),
  ('1592','Depreciación acumulada',      'activo','credito',1,70),
  ('2205','Proveedores por pagar',       'pasivo','credito',0,110),
  ('2408','IVA por pagar',               'pasivo','credito',0,120),
  ('2510','Nómina por pagar',            'pasivo','credito',0,130),
  ('3115','Capital social',              'patrimonio','credito',0,210),
  ('3705','Utilidad del ejercicio',      'patrimonio','credito',0,220),
  ('4135','Ingresos por ventas',         'ingreso','credito',0,310),
  ('6135','Costo de ventas',             'costo','debito',0,410),
  ('5105','Gastos de nómina',            'gasto','debito',0,510),
  ('5160','Gasto por depreciación',      'gasto','debito',0,520),
  ('5195','Gastos operativos (indirectos)','gasto','debito',0,530),
  ('5199','Obsequios y mermas',          'gasto','debito',0,540);

-- ── Config: IVA (por defecto desactivado — régimen simple) ────────────────────
-- valor es DECIMAL: iva_activo 0/1, iva_tarifa en % (19). categoria 'impuestos' (ENUM existente).
INSERT IGNORE INTO configuracion_negocio (clave, valor, descripcion, categoria) VALUES
  ('iva_activo', 0,  'Discriminar IVA en ventas/compras (0=no, 1=si)', 'impuestos'),
  ('iva_tarifa', 19, 'Tarifa de IVA por defecto (%)', 'impuestos');
