-- ============================================================
-- Migración 039 — Presentaciones múltiples de compra por insumo
-- ============================================================
-- Permite catalogar distintas formas físicas de comprar el mismo insumo
-- (ej: aceite en Frasco 900ml, Galón 3.785L, Bidón 18L).
--
-- PRINCIPIO ARQUITECTÓNICO:
--   La unidad canónica del insumo (unidad_medida, stock_actual, costo_actual)
--   NUNCA cambia. Esta tabla solo almacena "cómo se compra". El sistema convierte:
--     cantidad_canónica = cant_presentaciones × cantidad_base
--     precio_unitario   = precio_presentacion  / cantidad_base
--
-- SIN FK A NIVEL BD (errno 121 en cPanel compartido).
-- Integridad referencial garantizada por PHP en PresentacionModel y CompraModel.
-- ============================================================


CREATE TABLE IF NOT EXISTS `insumo_presentaciones` (
    `id`                INT           NOT NULL AUTO_INCREMENT,
    `insumo_id`         INT           NOT NULL COMMENT 'FK lógica → insumos.id',
    `nombre`            VARCHAR(60)   NOT NULL COMMENT 'Ej: Frasco 900ml, Galón 3.785L, Paca 12 unidades',
    `cantidad_base`     DECIMAL(12,4) NOT NULL COMMENT 'Cuántas unidades canónicas trae esta presentación',
    `unidad_compra`     VARCHAR(30)   NOT NULL DEFAULT '' COMMENT 'Etiqueta: frasco, galón, paca, caja…',
    `precio_referencia` DECIMAL(12,2) DEFAULT NULL COMMENT 'Precio habitual de referencia (orientativo)',
    `equiv_cantidad`    DECIMAL(10,4) DEFAULT NULL COMMENT 'Override equiv_cantidad del insumo para esta presentación',
    `equiv_unidad`      VARCHAR(20)   DEFAULT NULL COMMENT 'Override equiv_unidad del insumo para esta presentación',
    `es_predeterminada` TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '1 = pre-selecciona en formulario de compras',
    `activo`            TINYINT(1)    NOT NULL DEFAULT 1,
    `created_at`        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by`        INT DEFAULT NULL,
    `updated_by`        INT DEFAULT NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_ip_insumo` (`insumo_id`),
    INDEX `idx_ip_activo` (`activo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Catálogo de presentaciones de compra por insumo — migración 039';

-- FK lógica en compra_detalles para trazar qué presentación se usó al comprar
ALTER TABLE `compra_detalles`
    ADD COLUMN IF NOT EXISTS `presentacion_id` INT DEFAULT NULL
        COMMENT 'FK lógica → insumo_presentaciones.id. NULL = compra sin presentación catalogada (mig. 039).';
