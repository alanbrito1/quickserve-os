-- =============================================================================
-- Migración 025 — Sistema de combos por producto
-- Permite configurar qué insumos extra incluye el combo de cada sándwich
-- y registrar en venta_detalles si cada ítem fue vendido como solo o combo.
-- =============================================================================

USE clandestinoERP;

-- -----------------------------------------------------------------------------
-- 1. Tabla principal: una configuración de combo por producto
--    UNIQUE KEY (producto_id) → un producto tiene como máximo un combo activo
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS combo_configs (
    id               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    producto_id      INT UNSIGNED  NOT NULL,
    nombre           VARCHAR(100)  NOT NULL DEFAULT 'Combo',
    precio_adicional DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    activo           TINYINT(1)    NOT NULL DEFAULT 1,
    created_by       INT UNSIGNED  NULL,
    created_at       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE  KEY uk_combo_producto (producto_id),
    CONSTRAINT fk_cc_producto  FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE,
    CONSTRAINT fk_cc_createdby FOREIGN KEY (created_by)  REFERENCES usuarios(id)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 2. Insumos adicionales que el combo descuenta al momento de la venta
--    UNIQUE KEY (combo_id, insumo_id) → cada insumo aparece una sola vez por combo
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS combo_insumos (
    id        INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    combo_id  INT UNSIGNED   NOT NULL,
    insumo_id INT UNSIGNED   NOT NULL,
    cantidad  DECIMAL(10,4)  NOT NULL DEFAULT 1.0000,
    PRIMARY KEY (id),
    UNIQUE  KEY uk_combo_insumo (combo_id, insumo_id),
    CONSTRAINT fk_ci_combo  FOREIGN KEY (combo_id)  REFERENCES combo_configs(id) ON DELETE CASCADE,
    CONSTRAINT fk_ci_insumo FOREIGN KEY (insumo_id) REFERENCES insumos(id)       ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 3. Extender venta_detalles para rastrear combo por ítem
--    es_combo  = 1 si este ítem se vendió como combo
--    combo_id  = FK al combo que se usó (NULL en datos históricos)
--    DEFAULT 0 y NULL garantizan compatibilidad total con datos anteriores
-- -----------------------------------------------------------------------------
ALTER TABLE venta_detalles
    ADD COLUMN IF NOT EXISTS es_combo TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = vendido como combo; 0 = solo'
        AFTER from_stock,
    ADD COLUMN IF NOT EXISTS combo_id INT UNSIGNED NULL DEFAULT NULL
        COMMENT 'FK a combo_configs; NULL en ventas históricas'
        AFTER es_combo,
    ADD CONSTRAINT fk_vd_combo
        FOREIGN KEY (combo_id) REFERENCES combo_configs(id) ON DELETE SET NULL;

-- -----------------------------------------------------------------------------
-- 4. Migrar datos históricos
--    Ventas que tenían es_combo=1 a nivel cabecera → marcar todos sus detalles
--    como es_combo=1. combo_id queda NULL (no se conoce la config usada).
--
--    Consecuencia al anular esas ventas: el sándwich se restaura normalmente,
--    pero los insumos del combo NO se restauran (comportamiento aceptable porque
--    al momento de esas ventas el descuento de combo nunca se hizo en BD).
-- -----------------------------------------------------------------------------
UPDATE venta_detalles vd
    INNER JOIN ventas v ON v.id = vd.venta_id
SET vd.es_combo = 1
WHERE v.es_combo = 1;

-- Fin de migración 025
