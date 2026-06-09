-- Migración 039: Presentaciones múltiples de compra por insumo
-- Permite catalogar distintas formas de comprar el mismo insumo
-- (ej: aceite en frasco 900ml, galón 3.785L, bidón 18L).
--
-- PRINCIPIO ARQUITECTÓNICO CLAVE:
--   La unidad canónica del insumo (unidad_medida, stock_actual, costo_actual) NO cambia.
--   Esta tabla solo almacena "cómo se compra" → el sistema convierte automáticamente
--   cant_presentaciones × cantidad_base → cantidad en unidades canónicas.
--
-- FK LÓGICA (sin constraint a nivel BD — errno 121 en cPanel compartido).
-- Integridad referencial garantizada por PHP en PresentacionModel y CompraModel.

USE clandestinoERP;

CREATE TABLE IF NOT EXISTS `insumo_presentaciones` (
    `id`                INT           NOT NULL AUTO_INCREMENT,
    `insumo_id`         INT           NOT NULL
                            COMMENT 'FK lógica → insumos.id',
    `nombre`            VARCHAR(60)   NOT NULL
                            COMMENT 'Nombre descriptivo. Ej: Frasco 900ml, Paca 12 unidades, Caja 48 latas',
    `cantidad_base`     DECIMAL(12,4) NOT NULL
                            COMMENT 'Cuántas unidades canónicas del insumo trae esta presentación',
    `unidad_compra`     VARCHAR(30)   NOT NULL DEFAULT ''
                            COMMENT 'Etiqueta de la presentación: frasco, paca, caja, galón…',
    `precio_referencia` DECIMAL(12,2) DEFAULT NULL
                            COMMENT 'Precio habitual de referencia (orientativo, no inmutable)',
    `equiv_cantidad`    DECIMAL(10,4) DEFAULT NULL
                            COMMENT 'Override de equiv_cantidad del insumo para esta presentación específica (ej: lata 170g vs 160g)',
    `equiv_unidad`      VARCHAR(20)   DEFAULT NULL
                            COMMENT 'Override de equiv_unidad del insumo para esta presentación',
    `es_predeterminada` TINYINT(1)    NOT NULL DEFAULT 0
                            COMMENT '1 = se pre-selecciona en el formulario de compras',
    `activo`            TINYINT(1)    NOT NULL DEFAULT 1,
    `created_at`        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by`        INT           DEFAULT NULL,
    `updated_by`        INT           DEFAULT NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_ip_insumo`  (`insumo_id`),
    INDEX `idx_ip_activo`  (`activo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Catálogo de presentaciones de compra por insumo — migración 039';

-- Agregar FK lógica en compra_detalles para trazar qué presentación se usó
ALTER TABLE `compra_detalles`
    ADD COLUMN IF NOT EXISTS `presentacion_id` INT DEFAULT NULL
        COMMENT 'FK lógica → insumo_presentaciones.id. NULL = compra sin presentación catalogada (mig. 039).';
