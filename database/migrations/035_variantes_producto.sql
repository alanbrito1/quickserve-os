-- ============================================================
-- Migración 035 — Variantes de tamaño para productos
-- Permite definir múltiples variantes (XL, Regular, Familiar)
-- con precio propio y factor de escala de receta.
-- ============================================================

-- Tabla de variantes de producto
CREATE TABLE IF NOT EXISTS producto_variantes (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    producto_id   INT NOT NULL,
    etiqueta      VARCHAR(80)    NOT NULL,         -- "XL", "Regular", "Familiar", etc.
    precio_venta  DECIMAL(12,2)  NOT NULL,
    factor_receta DECIMAL(5,3)   NOT NULL DEFAULT 1.000,  -- escala las cantidades de la receta
    activo        TINYINT(1)     NOT NULL DEFAULT 1,
    created_by    INT            DEFAULT NULL,
    created_at    DATETIME       DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pv_producto (producto_id)
    -- SIN FK: misma razón que ajustes_stock (errno 121 en cPanel compartido)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Columnas en venta_detalles para registrar la variante vendida (snapshots)
ALTER TABLE venta_detalles
    ADD COLUMN IF NOT EXISTS variante_id        INT           DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS variante_etiqueta  VARCHAR(80)   DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS factor_receta_snap DECIMAL(5,3)  DEFAULT NULL;
-- factor_receta_snap: snapshot del factor usado al descontar insumos.
-- NULL = venta anterior a migración 035 (se asume factor 1.0 al anular).

-- Índice para consultas por variante (reportes)
ALTER TABLE venta_detalles
    ADD INDEX IF NOT EXISTS idx_vd_variante (variante_id);
