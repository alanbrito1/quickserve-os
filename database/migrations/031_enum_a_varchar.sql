-- ============================================================
-- Migracion 031: Convertir columnas ENUM a VARCHAR
-- ============================================================
-- Las columnas de categoria/presentacion/unidad que se
-- controlan desde Admin → Catalogos necesitan ser VARCHAR
-- para aceptar valores personalizados agregados en listas_sistema.
-- Con ENUM, cualquier valor fuera del conjunto fijo falla silenciosamente.
--
-- ANTES de ejecutar: hacer backup de la base de datos.
-- Los datos existentes no se alteran (todos los valores ENUM
-- actuales son validos como VARCHAR).
-- ============================================================

USE clandestinoERP;

-- productos.categoria: permite categorias personalizadas (ej: postre, ensalada)
ALTER TABLE productos
    MODIFY COLUMN categoria VARCHAR(60) NOT NULL DEFAULT 'sandwich'
    COMMENT 'Categoria del producto. Valores en listas_sistema tipo=categoria_producto';

-- productos.tamano: permite nuevos tamanos (ej: S, XXL, mini)
ALTER TABLE productos
    MODIFY COLUMN tamano VARCHAR(20) NOT NULL DEFAULT 'unico'
    COMMENT 'Tamano del producto. Valores en listas_sistema tipo=tamano_producto';

-- insumos.unidad_medida: permite nuevas unidades de medida
ALTER TABLE insumos
    MODIFY COLUMN unidad_medida VARCHAR(20) NOT NULL DEFAULT 'unidad'
    COMMENT 'Unidad basica de medida. Valores en listas_sistema tipo=unidad_medida';

-- insumos.presentacion: permite nuevas presentaciones de compra
ALTER TABLE insumos
    MODIFY COLUMN presentacion VARCHAR(30) DEFAULT NULL
    COMMENT 'Presentacion de compra. Valores en listas_sistema tipo=presentacion';

-- activos.categoria_activo: permite nuevas categorias de activos
ALTER TABLE activos
    MODIFY COLUMN categoria_activo VARCHAR(60) NOT NULL DEFAULT 'otro'
    COMMENT 'Categoria del activo. Valores en listas_sistema tipo=categoria_activo';

-- ============================================================
-- VERIFICAR resultado:
-- DESCRIBE productos;       → categoria y tamano deben ser varchar
-- DESCRIBE insumos;         → unidad_medida y presentacion deben ser varchar
-- DESCRIBE activos;         → categoria_activo debe ser varchar
-- ============================================================
