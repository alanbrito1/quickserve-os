-- ============================================================
-- Migración 034: Snapshots de nombres + saldo_anterior en pagos
-- ============================================================
-- Garantiza que cuando cambie el nombre de un producto, insumo o
-- cliente, los registros históricos conserven el dato original.
-- También guarda el saldo del cliente ANTES de cada abono.
--
-- Tablas afectadas:
--   venta_detalles   → nombre_snap, nombre2_snap
--   compra_detalles  → nombre_snap, unidad_snap
--   produccion_lotes → nombre_snap
--   pagos_fiado      → saldo_anterior (cuánto debía ANTES del abono)
-- ============================================================


-- ── 1. venta_detalles: nombre del producto al momento de la venta ──────────
ALTER TABLE venta_detalles
    ADD COLUMN nombre_snap  VARCHAR(200) DEFAULT NULL
        COMMENT 'Nombre del producto al momento de la venta (snapshot inmutable)',
    ADD COLUMN nombre2_snap VARCHAR(120) DEFAULT NULL
        COMMENT 'Subtítulo del producto al momento de la venta (snapshot inmutable)';

-- ── 2. compra_detalles: nombre e unidad del insumo al momento de comprar ───
ALTER TABLE compra_detalles
    ADD COLUMN nombre_snap VARCHAR(200) DEFAULT NULL
        COMMENT 'Nombre del insumo al momento de la compra (snapshot inmutable)',
    ADD COLUMN unidad_snap  VARCHAR(20)  DEFAULT NULL
        COMMENT 'Unidad básica del insumo al momento de la compra (snapshot inmutable)';

-- ── 3. produccion_lotes: nombre del producto al momento de producir ────────
ALTER TABLE produccion_lotes
    ADD COLUMN nombre_snap VARCHAR(200) DEFAULT NULL
        COMMENT 'Nombre del producto cuando se registró este lote (snapshot inmutable)';

-- ── 4. pagos_fiado: saldo del cliente antes y después del abono ────────────
ALTER TABLE pagos_fiado
    ADD COLUMN saldo_anterior DECIMAL(12,2) DEFAULT NULL
        COMMENT 'saldo_fiado del cliente ANTES de este abono (snapshot)',
    ADD COLUMN saldo_posterior DECIMAL(12,2) DEFAULT NULL
        COMMENT 'saldo_fiado del cliente DESPUÉS de este abono (= anterior − monto)';

-- ── Notas ──────────────────────────────────────────────────────────────────
-- Los registros existentes quedarán con NULL.
-- Las consultas y reportes deben usar COALESCE(nombre_snap, p.nombre)
-- para mostrar el nombre_snap si existe, o el nombre actual si no.
--
-- VERIFICAR:
-- DESCRIBE venta_detalles;      → nombre_snap, nombre2_snap al final
-- DESCRIBE compra_detalles;     → nombre_snap, unidad_snap al final
-- DESCRIBE produccion_lotes;    → nombre_snap al final
-- DESCRIBE pagos_fiado;         → saldo_anterior, saldo_posterior al final
