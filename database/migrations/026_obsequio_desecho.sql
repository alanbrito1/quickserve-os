-- ============================================================
-- Migracion 026: Obsequio en ventas + tabla ajustes_stock
-- ============================================================
-- Reemplazar 'clandestinoERP' por el nombre real de la base de datos.
-- ============================================================

USE clandestinoERP;

-- 1. Agregar 'obsequio' al ENUM de metodo_pago en ventas.
--    MODIFY COLUMN es idempotente: si 'obsequio' ya existe en el ENUM,
--    MySQL aplica el cambio sin error.
ALTER TABLE ventas
    MODIFY COLUMN metodo_pago
    ENUM('efectivo','nequi','daviplata','bancolombia','fiado','obsequio') NOT NULL;

-- 2. Tabla de ajustes de stock de producto terminado.
--    Registra unidades de stock_disponible que se regalan o desechan
--    sin pasar por el flujo de venta (ej: producto vencido, muestra gratuita).
--
--    Sin FK explicitas: la integridad referencial la garantiza el PHP
--    (ajuste_stock.php valida que producto_id exista antes del INSERT).
--    Esto evita errores errno 121/150 en hosting compartido con InnoDB
--    que tiene restricciones sobre nombres de constraint o modos estrictos.
CREATE TABLE IF NOT EXISTS ajustes_stock (
    id            INT          AUTO_INCREMENT PRIMARY KEY,
    producto_id   INT          NOT NULL,
    cantidad      INT          NOT NULL,
    tipo          ENUM('obsequio','desecho') NOT NULL,
    motivo        VARCHAR(300) DEFAULT NULL,
    fecha_ajuste  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by    INT          DEFAULT NULL,
    INDEX idx_as_producto (producto_id),
    INDEX idx_as_fecha    (fecha_ajuste)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
