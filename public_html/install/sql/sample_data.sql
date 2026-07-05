-- ============================================================
-- QuickServe OS — Datos de ejemplo (OPCIONALES)
-- ============================================================
-- Cárgalo si quieres explorar el sistema con un catálogo de muestra
-- (productos, insumos y recetas genéricos). El instalador web lo ejecuta
-- cuando marcas "Cargar datos de ejemplo".
--
-- IMPORTANTE: pensado para una instalación LIMPIA (catálogo vacío). Ejecútalo
-- justo después de schema.sql, sobre la misma base de datos ya seleccionada.
-- No lleva sentencia "USE": la conexión ya selecciona la base de datos.
--
-- Todo esto es 100% editable/borrable desde el sistema (Admin → Inventario,
-- Productos, y Admin → Mantenimiento de datos).
-- ============================================================

SET NAMES utf8mb4;

-- ── Insumos de ejemplo ─────────────────────────────────────────────────────
-- costo_actual es un valor de referencia; se recalcula solo cuando registras
-- una compra o configuras presentaciones. Ajusta precios reales en Inventario.
INSERT INTO `insumos` (`nombre`, `unidad_medida`, `costo_actual`, `stock_actual`, `stock_seguridad`) VALUES
('Ingrediente A', 'kg',     12000.0000, 10.000, 2.000),
('Ingrediente B', 'kg',      9000.0000,  8.000, 1.000),
('Ingrediente C', 'unidad',   800.0000, 40.000, 6.000),
('Empaque',       'unidad',   300.0000, 100.000, 20.000),
('Base',          'unidad',  1500.0000, 60.000, 10.000);

-- ── Productos de ejemplo ───────────────────────────────────────────────────
-- Ajusta precios y recetas reales en el módulo Productos. Tras editar recetas,
-- usa "Recalcular costos" para que el margen refleje el costo de los insumos.
INSERT INTO `productos` (`nombre`, `categoria`, `tamano`, `precio_venta`) VALUES
('Producto de ejemplo 1', 'general', 'Único', 12000.00),
('Producto de ejemplo 2', 'general', 'Único', 10000.00),
('Producto de ejemplo 3', 'general', 'Único', 15000.00),
('Producto de ejemplo 4', 'general', 'Único',  8000.00);

-- ── Recetas de ejemplo ─────────────────────────────────────────────────────
-- Enlazan producto → insumos (cantidad requerida por unidad; es_insumo_critico
-- marca el ingrediente que limita la capacidad de producción del POS).
-- Los ids 1..5 (insumos) y 1..4 (productos) corresponden a las filas de arriba
-- en una instalación limpia.
INSERT INTO `recetas` (`producto_id`, `insumo_id`, `cantidad_requerida`, `es_insumo_critico`) VALUES
(1, 1, 0.100000, 1), (1, 5, 1.000000, 0), (1, 4, 1.000000, 0),   -- Producto 1: Ingrediente A + Base + Empaque
(2, 2, 0.120000, 1), (2, 5, 1.000000, 0),                        -- Producto 2: Ingrediente B + Base
(3, 1, 0.150000, 1), (3, 3, 2.000000, 0), (3, 4, 1.000000, 0),   -- Producto 3: Ingrediente A + Ingrediente C + Empaque
(4, 3, 3.000000, 1), (4, 4, 1.000000, 0);                        -- Producto 4: Ingrediente C + Empaque

-- ============================================================
-- FIN DE LOS DATOS DE EJEMPLO
-- Sugerencia: en Productos → "Recalcular costos" para poblar costo_calculado.
-- ============================================================
