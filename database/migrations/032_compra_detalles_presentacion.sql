-- ============================================================
-- Migración 032: compra_detalles — snapshot de presentación
-- ============================================================
-- Al registrar una compra con el nuevo bloque de presentación,
-- se guardan los 4 campos de contexto junto con la línea.
-- Esto permite auditar CÓMO se compró (en qué empaque y a qué
-- precio por empaque), sin alterar la lógica de compras que
-- ya usa cantidad (en unidades básicas) y precio_unitario.
--
-- INVARIANTE: precio_presentacion / cant_presentaciones = precio_unitario
--             cant_presentaciones × cantidad_presentacion  = cantidad
-- ============================================================


ALTER TABLE compra_detalles
    ADD COLUMN presentacion         VARCHAR(30)    DEFAULT NULL
        COMMENT 'Tipo de empaque usado en esta compra (paca, frasco, etc.)',
    ADD COLUMN cantidad_presentacion DECIMAL(12,4) DEFAULT NULL
        COMMENT 'Unidades básicas que contiene cada presentación (snapshot del insumo al comprar)',
    ADD COLUMN cant_presentaciones   DECIMAL(10,4) DEFAULT NULL
        COMMENT 'Cuántas presentaciones se compraron (ej: 2 pacas)',
    ADD COLUMN precio_presentacion   DECIMAL(12,2) DEFAULT NULL
        COMMENT 'Precio pagado por cada presentación (snapshot inmutable)';

-- Los datos existentes quedan con NULL en estos campos (compras anteriores al formulario nuevo).
-- Las nuevas compras que usen el bloque de presentación los tendrán completos.

-- Verificar:
-- DESCRIBE compra_detalles;
-- → Nuevas columnas al final: presentacion, cantidad_presentacion, cant_presentaciones, precio_presentacion
