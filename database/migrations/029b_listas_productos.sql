-- ============================================================
-- Migracion 029b: Categorias y tamanos de productos
-- ============================================================
-- Complemento de 029_listas_sistema.sql.
-- Agrega dos catalogos nuevos a listas_sistema para que las
-- categorias y tamanos de productos sean configurables desde
-- Admin → Catalogos, igual que las presentaciones de insumos.
--
-- Ejecutar SOLO si ya esta aplicada la migracion 029.
-- ============================================================

USE clandestinoERP;

-- Categorias de productos (antes hardcodeado en guardar_producto.php)
INSERT INTO listas_sistema (tipo, valor, etiqueta, orden) VALUES
('categoria_producto', 'sandwich',  'Sándwich',  1),
('categoria_producto', 'combo',     'Combo',     2),
('categoria_producto', 'bebida',    'Bebida',    3),
('categoria_producto', 'adicional', 'Adicional', 4);

-- Tamanos de productos (antes hardcodeado en guardar_producto.php)
-- El orden aqui define como aparecen en el dropdown:
-- XL primero (mas grande) → L → Único (sin talla)
INSERT INTO listas_sistema (tipo, valor, etiqueta, orden) VALUES
('tamano_producto', 'XL',    'XL',    1),
('tamano_producto', 'L',     'L',     2),
('tamano_producto', 'unico', 'Único', 3);
