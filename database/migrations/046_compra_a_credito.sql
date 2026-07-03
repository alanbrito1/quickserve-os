-- ============================================================
-- Migración 046 — Compra a crédito (cuentas por pagar, Fase 4c)
-- ============================================================
-- Marca si una compra quedó a crédito (por pagar) en vez de pagada de contado.
-- La contabilidad (postear_compra) acredita 2205 Proveedores por pagar cuando
-- a_credito=1, o 1105 Caja cuando 0. El pago se registra luego en
-- Contabilidad → Movimientos (Pago a proveedor: 2205 ↔ Caja/Bancos).
-- Idempotente (guard via information_schema).
-- ============================================================

USE clandestinoERP;

SET @existe := (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'compras' AND COLUMN_NAME = 'a_credito');
SET @sql := IF(@existe = 0,
    'ALTER TABLE compras ADD COLUMN a_credito TINYINT(1) NOT NULL DEFAULT 0',
    'DO 0');
PREPARE _s FROM @sql; EXECUTE _s; DEALLOCATE PREPARE _s;
