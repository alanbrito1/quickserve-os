-- ============================================================
-- QuickServe OS — Datos de ejemplo (OPCIONALES)
-- ============================================================
-- Set completo para PROBAR todo el sistema: catálogo (proveedores, insumos,
-- presentaciones, productos, variantes, combos, recetas), clientes con fiado,
-- ventas recientes (efectivo/transferencia/fiado/obsequio/descuento/combo/
-- variante), abonos, compras (contado y a crédito), producción, ajustes de
-- stock, turnos de caja, empleados, nómina, costos indirectos y activos.
--
-- Las fechas son RELATIVAS a la instalación (NOW()/CURDATE() − N días), así el
-- Dashboard y los reportes siempre muestran datos "recientes".
--
-- Pensado para una instalación LIMPIA (catálogo vacío): los IDs 1..N de abajo
-- corresponden al orden de inserción. Ejecútalo justo después de schema.sql.
-- Todo es editable/borrable desde el sistema (Admin → Mantenimiento de datos
-- borra todo lo transaccional de un golpe).
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ── Proveedores (ids 1..7) ─────────────────────────────────────────────────
INSERT INTO `proveedores` (`nombre`, `categoria`, `contacto`, `telefono`, `email`, `activo`, `created_by`) VALUES
('Mercado Central',            'plaza',     'Don Rafael',   '3001112233', NULL,                        1, 1),
('Distribuidora La Económica', 'mayorista', 'Ventas',       '3012223344', 'ventas@laeconomica.co',     1, 1),
('Supermercado Express',       'retail',    NULL,           '3023334455', NULL,                        1, 1),
('Tienda del Barrio',          'tienda',    'Sra. Elena',   '3034445566', NULL,                        1, 1),
('Panadería El Trigal',        'panaderia', 'Panadería',    '3045556677', 'pedidos@eltrigal.co',       1, 1),
('Bebidas y Más',              'online',    'Atención',     '3056667788', 'hola@bebidasymas.co',       1, 1),
('Empaques del Norte',         'mayorista', 'Comercial',    '3067778899', NULL,                        1, 1);

-- ── Insumos (ids 1..14) ────────────────────────────────────────────────────
-- costo_actual directo (sin presentación → el trigger no lo sobreescribe).
INSERT INTO `insumos`
    (`nombre`, `categoria`, `unidad_medida`, `costo_actual`, `stock_actual`, `stock_seguridad`, `proveedor_id`, `equiv_cantidad`, `equiv_unidad`, `activo`, `created_by`) VALUES
('Pechuga de pollo', 'proteina',   'kg',      15000.0000, 25.000,  5.000, 1, NULL, NULL, 1, 1),  -- 1
('Carne molida',     'proteina',   'kg',      22000.0000, 18.000,  4.000, 1, NULL, NULL, 1, 1),  -- 2
('Jamón',            'proteina',   'loncha',    450.0000, 200.000, 40.000, 2, 20.0000, 'g', 1, 1), -- 3
('Queso',            'lacteo',     'loncha',    380.0000, 200.000, 40.000, 2, 18.0000, 'g', 1, 1), -- 4
('Atún',             'proteina',   'lata',     3200.0000, 60.000, 12.000, 3, 160.0000, 'g', 1, 1), -- 5
('Lechuga',          'vegetal',    'unidad',   2200.0000, 15.000,  3.000, 1, NULL, NULL, 1, 1),  -- 6
('Tomate',           'vegetal',    'kg',       3500.0000, 20.000,  5.000, 1, NULL, NULL, 1, 1),  -- 7
('Cebolla',          'vegetal',    'kg',       2800.0000, 15.000,  4.000, 1, NULL, NULL, 1, 1),  -- 8
('Mayonesa',         'condimento', 'kg',       9000.0000,  8.000,  2.000, 3, NULL, NULL, 1, 1),  -- 9
('Pan de sándwich',  'otro',       'unidad',    900.0000, 150.000, 40.000, 5, NULL, NULL, 1, 1),  -- 10
('Papa',             'vegetal',    'kg',       2500.0000, 30.000,  6.000, 1, NULL, NULL, 1, 1),  -- 11
('Aceite',           'grasa',      'litro',   12000.0000, 10.000,  2.000, 3, NULL, NULL, 1, 1),  -- 12
('Gaseosa personal', 'combo',      'unidad',   1800.0000, 80.000, 20.000, 6, NULL, NULL, 1, 1),  -- 13
('Empaque / caja',   'empaque',    'unidad',    250.0000, 300.000, 60.000, 7, NULL, NULL, 1, 1);  -- 14

-- ── Presentaciones de compra catalogadas (mig. 039) ────────────────────────
INSERT INTO `insumo_presentaciones`
    (`insumo_id`, `nombre`, `cantidad_base`, `unidad_compra`, `precio_referencia`, `es_predeterminada`, `activo`) VALUES
-- precio_referencia = costo_actual × cantidad_base (coherente con el auditor de costos)
(5,  'Caja x24 latas', 24.0000, 'caja', 76800.00, 1, 1),
(9,  'Balde 4 kg',      4.0000, 'balde', 36000.00, 1, 1),
(10, 'Paca x50',       50.0000, 'paca', 45000.00, 1, 1),
(13, 'Six-pack',        6.0000, 'paca', 10800.00, 0, 1);

-- ── Productos (ids 1..7) ───────────────────────────────────────────────────
-- costo_calculado = Σ(insumo.costo_actual × cantidad_requerida) (recetas abajo).
INSERT INTO `productos`
    (`nombre`, `nombre2`, `categoria`, `tamano`, `precio_venta`, `costo_calculado`, `unidades_por_receta`, `stock_disponible`, `stock_minimo`, `activo`, `created_by`) VALUES
('Sándwich Clásico',      'jamón y queso',      'sandwich',  'unico', 12000.00, 3855.0000, 1, 20, 5, 1, 1),  -- 1
('Sándwich de Pollo',     'pechuga a la plancha','sandwich', 'unico', 14000.00, 4175.0000, 1, 15, 5, 1, 1),  -- 2
('Sándwich Vegetariano',  NULL,                 'sandwich',  'unico', 11000.00, 3254.0000, 1, 10, 5, 1, 1),  -- 3
('Sándwich de Atún',      NULL,                 'sandwich',  'unico', 13000.00, 3636.0000, 1, 12, 5, 1, 1),  -- 4
('Papas Fritas',          'porción',            'adicional', 'unico',  6000.00, 1475.0000, 1,  0, 0, 1, 1),  -- 5
('Jugo Natural',          'vaso 12oz',          'bebida',    'unico',  5000.00, 1500.0000, 1,  0, 0, 1, 1),  -- 6
('Gaseosa',               'personal',           'bebida',    'unico',  3500.00, 1800.0000, 1, 45, 10, 1, 1);  -- 7

-- ── Recetas ────────────────────────────────────────────────────────────────
INSERT INTO `recetas` (`producto_id`, `insumo_id`, `cantidad_requerida`, `es_insumo_critico`, `es_base`) VALUES
-- P1 Clásico
(1, 10, 2.000000, 0, 1), (1, 3, 2.000000, 1, 0), (1, 4, 2.000000, 0, 0), (1, 6, 0.100000, 0, 0), (1, 7, 0.050000, 0, 0),
-- P2 Pollo
(2, 10, 2.000000, 0, 1), (2, 1, 0.120000, 1, 0), (2, 6, 0.100000, 0, 0), (2, 7, 0.050000, 0, 0), (2, 9, 0.020000, 0, 0),
-- P3 Vegetariano
(3, 10, 2.000000, 0, 1), (3, 4, 2.000000, 1, 0), (3, 6, 0.150000, 0, 0), (3, 7, 0.080000, 0, 0), (3, 8, 0.030000, 0, 0),
-- P4 Atún
(4, 10, 2.000000, 0, 1), (4, 5, 0.500000, 1, 0), (4, 9, 0.020000, 0, 0), (4, 8, 0.020000, 0, 0),
-- P5 Papas
(5, 11, 0.250000, 1, 0), (5, 12, 0.050000, 0, 0), (5, 14, 1.000000, 0, 1),
-- P7 Gaseosa (reventa)
(7, 13, 1.000000, 1, 0);

-- ── Variantes de tamaño (mig. 035; ids 1..4) ───────────────────────────────
INSERT INTO `producto_variantes` (`producto_id`, `etiqueta`, `precio_venta`, `factor_receta`, `activo`, `created_by`) VALUES
(1, 'Regular', 12000.00, 1.000, 1, 1),  -- 1
(1, 'Grande',  16000.00, 1.500, 1, 1),  -- 2
(2, 'Sencillo',14000.00, 1.000, 1, 1),  -- 3
(2, 'Doble',   22000.00, 1.800, 1, 1);  -- 4

-- ── Combo (mig. 025; combo_config id 1 sobre el Sándwich de Pollo) ──────────
INSERT INTO `combo_configs` (`producto_id`, `nombre`, `precio_adicional`, `activo`, `created_by`) VALUES
(2, 'Combo', 4000.00, 1, 1);
INSERT INTO `combo_insumos` (`combo_id`, `insumo_id`, `cantidad`) VALUES
(1, 13, 1.0000),   -- + Gaseosa
(1, 11, 0.2500);   -- + Papas

-- ── Clientes (ids 1..8) ────────────────────────────────────────────────────
-- saldo_fiado coherente con las ventas fiado y los abonos de abajo.
INSERT INTO `clientes` (`nombre`, `apellido`, `empresa`, `telefono`, `saldo_fiado`, `activo`, `created_by`) VALUES
('María',   'Gómez',   NULL,             '3101112233',     0.00, 1, 1),  -- 1
('Carlos',  'Ruiz',    NULL,             '3102223344', 25000.00, 1, 1),  -- 2  (debe 25.000)
('Ana',     'Torres',  'Oficina Central','3103334455',     0.00, 1, 1),  -- 3
('Luis',    'Pérez',   NULL,             '3104445566', 12000.00, 1, 1),  -- 4  (debe 12.000 tras abono)
('Sofía',   'Ramírez', NULL,             '3105556677',     0.00, 1, 1),  -- 5
('Empresa XYZ SAS', NULL, 'Empresa XYZ SAS','3106667788', 48000.00, 1, 1),  -- 6  (debe 48.000)
('Pedro',   'Martínez',NULL,             '3107778899',     0.00, 1, 1),  -- 7
('Laura',   'Díaz',    NULL,             NULL,             0.00, 0, 1);  -- 8  (INACTIVO: prueba el filtro admin)

-- ── Ventas (cabecera; ids 1..25) ───────────────────────────────────────────
-- total = Σ subtotales de sus líneas (− descuento_valor). Fechas relativas.
INSERT INTO `ventas`
    (`fecha_venta`, `fecha_pago`, `cliente_id`, `metodo_pago`, `total`, `es_combo`, `estado`, `descuento_pct`, `descuento_valor`, `created_by`) VALUES
(NOW(),                          NOW(),                          NULL, 'efectivo',    24000.00, 0, 'completada',     0.00,    0.00, 1),  -- 1
(NOW(),                          NOW(),                          1,    'nequi',       17500.00, 0, 'completada',     0.00,    0.00, 1),  -- 2
(NOW() - INTERVAL 1 DAY,         NOW() - INTERVAL 1 DAY,         NULL, 'efectivo',    11000.00, 0, 'completada',     0.00,    0.00, 1),  -- 3
(NOW() - INTERVAL 1 DAY,         NOW() - INTERVAL 1 DAY,         3,    'daviplata',   26000.00, 0, 'completada',     0.00,    0.00, 1),  -- 4
(NOW() - INTERVAL 2 DAY,         NOW() - INTERVAL 2 DAY,         NULL, 'efectivo',    18000.00, 0, 'completada',     0.00,    0.00, 1),  -- 5
(NOW() - INTERVAL 2 DAY,         NULL,                           2,    'fiado',       25000.00, 0, 'pendiente_pago', 0.00,    0.00, 1),  -- 6
(NOW() - INTERVAL 3 DAY,         NOW() - INTERVAL 3 DAY,         NULL, 'nequi',       14000.00, 0, 'completada',     0.00,    0.00, 1),  -- 7
(NOW() - INTERVAL 3 DAY,         NOW() - INTERVAL 3 DAY,         NULL, 'obsequio',    12000.00, 0, 'completada',     0.00,    0.00, 1),  -- 8
(NOW() - INTERVAL 4 DAY,         NOW() - INTERVAL 4 DAY,         NULL, 'efectivo',    10000.00, 0, 'completada',     0.00,    0.00, 1),  -- 9
(NOW() - INTERVAL 4 DAY,         NOW() - INTERVAL 4 DAY,         5,    'bancolombia', 18000.00, 0, 'completada',     0.00,    0.00, 1),  -- 10
(NOW() - INTERVAL 5 DAY,         NOW() - INTERVAL 5 DAY,         NULL, 'efectivo',    25200.00, 0, 'completada',    10.00, 2800.00, 1),  -- 11
(NOW() - INTERVAL 5 DAY,         NULL,                           4,    'fiado',       20000.00, 0, 'pendiente_pago', 0.00,    0.00, 1),  -- 12
(NOW() - INTERVAL 6 DAY,         NOW() - INTERVAL 6 DAY,         NULL, 'efectivo',    36000.00, 0, 'completada',     0.00,    0.00, 1),  -- 13
(NOW() - INTERVAL 6 DAY,         NOW() - INTERVAL 6 DAY,         7,    'nequi',       18000.00, 1, 'completada',     0.00,    0.00, 1),  -- 14 combo
(NOW() - INTERVAL 7 DAY,         NOW() - INTERVAL 7 DAY,         NULL, 'efectivo',    22000.00, 0, 'completada',     0.00,    0.00, 1),  -- 15 variante
(NOW() - INTERVAL 8 DAY,         NULL,                           6,    'fiado',       30000.00, 0, 'pendiente_pago', 0.00,    0.00, 1),  -- 16
(NOW() - INTERVAL 9 DAY,         NULL,                           6,    'fiado',       18000.00, 0, 'pendiente_pago', 0.00,    0.00, 1),  -- 17
(NOW() - INTERVAL 10 DAY,        NOW() - INTERVAL 10 DAY,        NULL, 'efectivo',    12000.00, 0, 'completada',     0.00,    0.00, 1),  -- 18
(NOW() - INTERVAL 12 DAY,        NOW() - INTERVAL 12 DAY,        NULL, 'nequi',       24000.00, 0, 'completada',     0.00,    0.00, 1),  -- 19
(NOW() - INTERVAL 15 DAY,        NOW() - INTERVAL 15 DAY,        1,    'efectivo',    16500.00, 0, 'completada',     0.00,    0.00, 1),  -- 20
(NOW() - INTERVAL 20 DAY,        NOW() - INTERVAL 20 DAY,        NULL, 'daviplata',   42000.00, 0, 'completada',     0.00,    0.00, 1),  -- 21
(NOW() - INTERVAL 25 DAY,        NOW() - INTERVAL 25 DAY,        NULL, 'efectivo',    22000.00, 0, 'completada',     0.00,    0.00, 1),  -- 22
(NOW() - INTERVAL 30 DAY,        NOW() - INTERVAL 30 DAY,        NULL, 'efectivo',    12000.00, 0, 'completada',     0.00,    0.00, 1),  -- 23
(NOW() - INTERVAL 35 DAY,        NOW() - INTERVAL 35 DAY,        NULL, 'nequi',       28000.00, 0, 'completada',     0.00,    0.00, 1),  -- 24
(NOW() - INTERVAL 3 DAY,         NULL,                           NULL, 'efectivo',    12000.00, 0, 'anulada',        0.00,    0.00, 1);  -- 25 anulada

-- ── Detalles de venta ──────────────────────────────────────────────────────
-- subtotal = cantidad × precio_unitario; costo_unit_snap = costo por unidad (COGS).
INSERT INTO `venta_detalles`
    (`venta_id`, `producto_id`, `cantidad`, `precio_unitario`, `precio_lista`, `subtotal`, `from_stock`, `es_combo`, `combo_id`, `nombre_snap`, `variante_id`, `variante_etiqueta`, `factor_receta_snap`, `costo_unit_snap`, `created_by`) VALUES
(1,  1, 2, 12000.00, 12000.00, 24000.00, 1, 0, NULL, 'Sándwich Clásico',     NULL, NULL,    NULL,  3855.0000, 1),
(2,  2, 1, 14000.00, 14000.00, 14000.00, 1, 0, NULL, 'Sándwich de Pollo',    NULL, NULL,    NULL,  4175.0000, 1),
(2,  7, 1,  3500.00,  3500.00,  3500.00, 1, 0, NULL, 'Gaseosa',              NULL, NULL,    NULL,  1800.0000, 1),
(3,  3, 1, 11000.00, 11000.00, 11000.00, 1, 0, NULL, 'Sándwich Vegetariano', NULL, NULL,    NULL,  3254.0000, 1),
(4,  4, 2, 13000.00, 13000.00, 26000.00, 1, 0, NULL, 'Sándwich de Atún',     NULL, NULL,    NULL,  3636.0000, 1),
(5,  5, 3,  6000.00,  6000.00, 18000.00, 0, 0, NULL, 'Papas Fritas',         NULL, NULL,    NULL,  1475.0000, 1),
(6,  1, 1, 12000.00, 12000.00, 12000.00, 1, 0, NULL, 'Sándwich Clásico',     NULL, NULL,    NULL,  3855.0000, 1),
(6,  4, 1, 13000.00, 13000.00, 13000.00, 1, 0, NULL, 'Sándwich de Atún',     NULL, NULL,    NULL,  3636.0000, 1),
(7,  2, 1, 14000.00, 14000.00, 14000.00, 1, 0, NULL, 'Sándwich de Pollo',    NULL, NULL,    NULL,  4175.0000, 1),
(8,  1, 1, 12000.00, 12000.00, 12000.00, 1, 0, NULL, 'Sándwich Clásico',     NULL, NULL,    NULL,  3855.0000, 1),
(9,  6, 2,  5000.00,  5000.00, 10000.00, 0, 0, NULL, 'Jugo Natural',         NULL, NULL,    NULL,  1500.0000, 1),
(10, 3, 1, 11000.00, 11000.00, 11000.00, 1, 0, NULL, 'Sándwich Vegetariano', NULL, NULL,    NULL,  3254.0000, 1),
(10, 7, 2,  3500.00,  3500.00,  7000.00, 1, 0, NULL, 'Gaseosa',              NULL, NULL,    NULL,  1800.0000, 1),
(11, 2, 2, 14000.00, 14000.00, 28000.00, 1, 0, NULL, 'Sándwich de Pollo',    NULL, NULL,    NULL,  4175.0000, 1),
(12, 2, 1, 14000.00, 14000.00, 14000.00, 1, 0, NULL, 'Sándwich de Pollo',    NULL, NULL,    NULL,  4175.0000, 1),
(12, 5, 1,  6000.00,  6000.00,  6000.00, 0, 0, NULL, 'Papas Fritas',         NULL, NULL,    NULL,  1475.0000, 1),
(13, 1, 3, 12000.00, 12000.00, 36000.00, 1, 0, NULL, 'Sándwich Clásico',     NULL, NULL,    NULL,  3855.0000, 1),
(14, 2, 1, 18000.00, 14000.00, 18000.00, 1, 1, 1,    'Sándwich de Pollo',    NULL, NULL,    NULL,  6600.0000, 1),
(15, 2, 1, 22000.00, 22000.00, 22000.00, 1, 0, NULL, 'Sándwich de Pollo',    4,    'Doble', 1.800, 7515.0000, 1),
(16, 4, 1, 13000.00, 13000.00, 13000.00, 1, 0, NULL, 'Sándwich de Atún',     NULL, NULL,    NULL,  3636.0000, 1),
(16, 1, 1, 12000.00, 12000.00, 12000.00, 1, 0, NULL, 'Sándwich Clásico',     NULL, NULL,    NULL,  3855.0000, 1),
(16, 6, 1,  5000.00,  5000.00,  5000.00, 0, 0, NULL, 'Jugo Natural',         NULL, NULL,    NULL,  1500.0000, 1),
(17, 3, 1, 11000.00, 11000.00, 11000.00, 1, 0, NULL, 'Sándwich Vegetariano', NULL, NULL,    NULL,  3254.0000, 1),
(17, 7, 2,  3500.00,  3500.00,  7000.00, 1, 0, NULL, 'Gaseosa',              NULL, NULL,    NULL,  1800.0000, 1),
(18, 5, 2,  6000.00,  6000.00, 12000.00, 0, 0, NULL, 'Papas Fritas',         NULL, NULL,    NULL,  1475.0000, 1),
(19, 1, 2, 12000.00, 12000.00, 24000.00, 1, 0, NULL, 'Sándwich Clásico',     NULL, NULL,    NULL,  3855.0000, 1),
(20, 4, 1, 13000.00, 13000.00, 13000.00, 1, 0, NULL, 'Sándwich de Atún',     NULL, NULL,    NULL,  3636.0000, 1),
(20, 7, 1,  3500.00,  3500.00,  3500.00, 1, 0, NULL, 'Gaseosa',              NULL, NULL,    NULL,  1800.0000, 1),
(21, 2, 3, 14000.00, 14000.00, 42000.00, 1, 0, NULL, 'Sándwich de Pollo',    NULL, NULL,    NULL,  4175.0000, 1),
(22, 3, 2, 11000.00, 11000.00, 22000.00, 1, 0, NULL, 'Sándwich Vegetariano', NULL, NULL,    NULL,  3254.0000, 1),
(23, 1, 1, 12000.00, 12000.00, 12000.00, 1, 0, NULL, 'Sándwich Clásico',     NULL, NULL,    NULL,  3855.0000, 1),
(24, 2, 2, 14000.00, 14000.00, 28000.00, 1, 0, NULL, 'Sándwich de Pollo',    NULL, NULL,    NULL,  4175.0000, 1),
(25, 1, 1, 12000.00, 12000.00, 12000.00, 1, 0, NULL, 'Sándwich Clásico',     NULL, NULL,    NULL,  3855.0000, 1);

-- ── Abono a fiado (Luis Pérez, cliente 4: 20.000 → 12.000) ──────────────────
INSERT INTO `pagos_fiado`
    (`cliente_id`, `monto`, `metodo_pago`, `notas`, `saldo_anterior`, `saldo_posterior`, `created_at`, `created_by`) VALUES
(4, 8000.00, 'efectivo', 'Abono parcial', 20000.00, 12000.00, NOW() - INTERVAL 3 DAY, 1);

-- ── Compras (ids 1..5) + detalles (total = Σ subtotales) ────────────────────
INSERT INTO `compras`
    (`fecha_compra`, `proveedor_id`, `lugar_compra`, `total`, `a_credito`, `created_by`) VALUES
(NOW() - INTERVAL 5 DAY,  1, 'Mercado Central',            89000.00,  0, 1),  -- 1
(NOW() - INTERVAL 10 DAY, 2, 'Distribuidora La Económica', 83000.00,  0, 1),  -- 2
(NOW() - INTERVAL 15 DAY, 3, 'Supermercado Express',      112800.00,  1, 1),  -- 3 (a crédito)
(NOW() - INTERVAL 20 DAY, 5, 'Panadería El Trigal',        90000.00,  0, 1),  -- 4
(NOW() - INTERVAL 30 DAY, 1, 'Mercado Central',            83800.00,  0, 1);  -- 5

INSERT INTO `compra_detalles`
    (`compra_id`, `insumo_id`, `cantidad`, `precio_unitario`, `subtotal`, `nombre_snap`, `unidad_snap`, `created_by`) VALUES
(1, 1,  3.0000, 15000.0000, 45000.00, 'Pechuga de pollo', 'kg',     1),
(1, 2,  2.0000, 22000.0000, 44000.00, 'Carne molida',     'kg',     1),
(2, 3, 100.0000,  450.0000, 45000.00, 'Jamón',            'loncha', 1),
(2, 4, 100.0000,  380.0000, 38000.00, 'Queso',            'loncha', 1),
(3, 5, 24.0000, 3200.0000, 76800.00, 'Atún',              'lata',   1),
(3, 9,  4.0000, 9000.0000, 36000.00, 'Mayonesa',          'kg',     1),
(4, 10,100.0000, 900.0000, 90000.00, 'Pan de sándwich',   'unidad', 1),
(5, 7, 10.0000, 3500.0000, 35000.00, 'Tomate',            'kg',     1),
(5, 8,  8.0000, 2800.0000, 22400.00, 'Cebolla',           'kg',     1),
(5, 6, 12.0000, 2200.0000, 26400.00, 'Lechuga',           'unidad', 1);

-- ── Producción (lotes recientes) ───────────────────────────────────────────
INSERT INTO `produccion_lotes`
    (`producto_id`, `fecha_produccion`, `cantidad`, `costo_unitario`, `estado`, `nombre_snap`, `created_by`) VALUES
(1, CURDATE() - INTERVAL 1 DAY, 20, 3855.0000, 'activo', 'Sándwich Clásico',     1),
(2, CURDATE() - INTERVAL 2 DAY, 15, 4175.0000, 'activo', 'Sándwich de Pollo',    1),
(3, CURDATE() - INTERVAL 3 DAY, 12, 3254.0000, 'activo', 'Sándwich Vegetariano', 1),
(4, CURDATE() - INTERVAL 4 DAY, 12, 3636.0000, 'activo', 'Sándwich de Atún',     1),
(7, CURDATE() - INTERVAL 6 DAY, 50, 1800.0000, 'activo', 'Gaseosa',              1);

-- ── Ajustes de stock (obsequios / desechos) ────────────────────────────────
INSERT INTO `ajustes_stock` (`producto_id`, `cantidad`, `tipo`, `motivo`, `fecha_ajuste`, `created_by`) VALUES
(1, 1, 'obsequio', 'Degustación a cliente nuevo', NOW() - INTERVAL 2 DAY, 1),
(3, 2, 'desecho',  'Producto vencido',            NOW() - INTERVAL 5 DAY, 1),
(7, 3, 'obsequio', 'Cortesía por demora',         NOW() - INTERVAL 8 DAY, 1);

-- ── Turnos de caja ─────────────────────────────────────────────────────────
INSERT INTO `turnos_caja`
    (`fecha`, `fondo_inicial`, `notas_apertura`, `usuario_apertura`, `fecha_apertura`, `estado`, `fecha_cierre`, `usuario_cierre`, `notas_cierre`) VALUES
(CURDATE(),                 100000.00, 'Apertura del día',  1, NOW(),                    'abierto', NULL,                    NULL, NULL),
(CURDATE() - INTERVAL 1 DAY,100000.00, 'Apertura',          1, NOW() - INTERVAL 1 DAY,   'cerrado', NOW() - INTERVAL 1 DAY,  1,    'Cuadre correcto'),
(CURDATE() - INTERVAL 2 DAY,100000.00, 'Apertura',          1, NOW() - INTERVAL 2 DAY,   'cerrado', NOW() - INTERVAL 2 DAY,  1,    'Cuadre correcto');

-- ── Empleados (ids 1..4) ───────────────────────────────────────────────────
INSERT INTO `empleados`
    (`nombre_completo`, `documento_identidad`, `cargo`, `tipo_contrato`, `pais_laboral`, `horas_semana`, `periodo_horas_emp`, `valor_hora`, `valor_proyecto`, `fecha_ingreso`, `salario_base`, `aplica_aux_transporte`, `tipo_costo`, `activo`, `created_by`) VALUES
('Juan Operario',   '1001001001', 'Cocina',            'tiempo_completo', 'Colombia', NULL, NULL, NULL,    NULL,      '2025-06-01', 1750905.00, 1, 'directo',    1, 1),  -- 1
('Marta Cajera',    '1002002002', 'Caja y atención',   'tiempo_completo', 'Colombia', NULL, NULL, NULL,    NULL,      '2025-08-15', 1750905.00, 1, 'indirecto',  1, 1),  -- 2
('Diego Ayudante',  '1003003003', 'Ayudante de cocina','por_horas',       'Colombia',   24, 'semana', 7500.00, NULL,   '2025-10-01', 1300000.00, 1, 'directo',    1, 1),  -- 3
('Sara Domicilios', '1004004004', 'Domicilios',        'por_servicio',    'Colombia', NULL, NULL, NULL,    900000.00, '2025-09-01',       0.00, 0, 'indirecto',  1, 1);  -- 4

-- ── Registro de horas (empleado por_horas) ─────────────────────────────────
INSERT INTO `registro_horas` (`empleado_id`, `fecha`, `horas`, `tipo_hora`, `es_festivo`, `aprobado`, `created_by`) VALUES
(3, CURDATE() - INTERVAL 1 DAY, 6.00, 'ordinaria', 0, 1, 1),
(3, CURDATE() - INTERVAL 2 DAY, 6.00, 'ordinaria', 0, 1, 1),
(3, CURDATE() - INTERVAL 3 DAY, 6.00, 'ordinaria', 0, 1, 1),
(3, CURDATE() - INTERVAL 4 DAY, 8.00, 'ordinaria', 0, 1, 1);

-- ── Nómina liquidada (mes actual + mes anterior) ───────────────────────────
-- Tiempo completo (SMLMV 2026 = 1.750.905). Componentes Colombia.
INSERT INTO `nomina_liquidaciones`
    (`empleado_id`, `periodo_mes`, `periodo_anio`, `tipo_contrato`, `salario_base`, `aux_transporte`,
     `salud_empleador`, `pension_empleador`, `arl`, `caja_compensacion`, `icbf`, `sena`,
     `salud_empleado`, `pension_empleado`, `neto_pagado`,
     `prima`, `cesantias`, `intereses_cesantias`, `vacaciones`,
     `total_cargas`, `total_provisiones`, `costo_total_empleador`, `pagado`, `fecha_pago_nomina`, `created_by`) VALUES
-- Juan (mes actual, sin pagar aún)
(1, MONTH(CURDATE()), YEAR(CURDATE()), 'tiempo_completo', 1750905.00, 200000.00,
 148827.00, 210109.00, 9140.00, 70036.00, 52527.00, 35018.00,
 70036.00, 70036.00, 1810833.00,
 145850.00, 145850.00, 17509.00, 73013.00,
 525657.00, 382222.00, 2858784.00, 0, NULL, 1),
-- Marta (mes actual, sin pagar aún)
(2, MONTH(CURDATE()), YEAR(CURDATE()), 'tiempo_completo', 1750905.00, 200000.00,
 148827.00, 210109.00, 9140.00, 70036.00, 52527.00, 35018.00,
 70036.00, 70036.00, 1810833.00,
 145850.00, 145850.00, 17509.00, 73013.00,
 525657.00, 382222.00, 2858784.00, 0, NULL, 1),
-- Juan (mes anterior, pagado)
(1, MONTH(CURDATE() - INTERVAL 1 MONTH), YEAR(CURDATE() - INTERVAL 1 MONTH), 'tiempo_completo', 1750905.00, 200000.00,
 148827.00, 210109.00, 9140.00, 70036.00, 52527.00, 35018.00,
 70036.00, 70036.00, 1810833.00,
 145850.00, 145850.00, 17509.00, 73013.00,
 525657.00, 382222.00, 2858784.00, 1, CURDATE() - INTERVAL 20 DAY, 1),
-- Marta (mes anterior, pagado)
(2, MONTH(CURDATE() - INTERVAL 1 MONTH), YEAR(CURDATE() - INTERVAL 1 MONTH), 'tiempo_completo', 1750905.00, 200000.00,
 148827.00, 210109.00, 9140.00, 70036.00, 52527.00, 35018.00,
 70036.00, 70036.00, 1810833.00,
 145850.00, 145850.00, 17509.00, 73013.00,
 525657.00, 382222.00, 2858784.00, 1, CURDATE() - INTERVAL 20 DAY, 1);

-- Diego (por_horas, mes actual): 96 h × 7.500
INSERT INTO `nomina_liquidaciones`
    (`empleado_id`, `periodo_mes`, `periodo_anio`, `tipo_contrato`, `horas_trabajadas`, `salario_base`, `aux_transporte`,
     `salud_empleador`, `pension_empleador`, `arl`, `caja_compensacion`, `icbf`, `sena`,
     `salud_empleado`, `pension_empleado`, `neto_pagado`,
     `prima`, `cesantias`, `intereses_cesantias`, `vacaciones`,
     `total_cargas`, `total_provisiones`, `costo_total_empleador`, `valor_hora_snap`, `pagado`, `created_by`) VALUES
(3, MONTH(CURDATE()), YEAR(CURDATE()), 'por_horas', 96.00, 720000.00, 100000.00,
 61200.00, 86400.00, 3758.00, 28800.00, 21600.00, 14400.00,
 28800.00, 28800.00, 762400.00,
 59976.00, 59976.00, 7200.00, 30024.00,
 216158.00, 157176.00, 1193334.00, 7500.0000, 0, 1);

-- Sara (por_servicio, mes actual): sin prestaciones
INSERT INTO `nomina_liquidaciones`
    (`empleado_id`, `periodo_mes`, `periodo_anio`, `tipo_contrato`, `descripcion_pago`, `salario_base`,
     `neto_pagado`, `costo_total_empleador`, `valor_proyecto_snap`, `pagado`, `created_by`) VALUES
(4, MONTH(CURDATE()), YEAR(CURDATE()), 'por_servicio', 'Servicio de domicilios del mes', 0.00,
 900000.00, 900000.00, 900000.00, 0, 1);

-- ── Costos indirectos (vigentes) ───────────────────────────────────────────
INSERT INTO `costos_indirectos`
    (`nombre`, `categoria`, `descripcion`, `tipo`, `clasificacion`, `frecuencia`, `valor`, `fecha_inicio`, `fecha_fin`, `activo`, `created_by`) VALUES
('Arriendo del local',      'arriendo',           'Canon mensual del local',        'fijo',     'indirecto', 'mensual', 1200000.00, '2025-01-01', NULL, 1, 1),
('Servicios públicos',      'servicios_publicos', 'Agua, luz, gas',                 'variable', 'indirecto', 'mensual',  350000.00, '2025-01-01', NULL, 1, 1),
('Internet y teléfono',     'servicios_publicos', 'Plan de datos e internet fijo',  'fijo',     'indirecto', 'mensual',  120000.00, '2025-01-01', NULL, 1, 1),
('Publicidad en redes',     'publicidad',         'Pauta en redes sociales',        'variable', 'indirecto', 'mensual',  200000.00, '2025-03-01', NULL, 1, 1);

-- ── Activos fijos (la depreciación la calcula el trigger) ───────────────────
INSERT INTO `activos`
    (`nombre`, `numero_unidades`, `precio_unitario`, `descripcion`, `lugar_compra`, `proveedor_id`, `serial`, `costo_inicial`, `fecha_adquisicion`, `garantia_hasta`, `fecha_inicio_uso`, `vida_util_meses`, `activo`, `estado_fisico`, `categoria_activo`, `responsable`, `created_by`) VALUES
('Nevera industrial', 1, 2500000.00, 'Nevera vertical 2 puertas', 'Supermercado Express', 3, 'NV-2025-01', 2500000.00, '2025-02-01', '2027-02-01', '2025-02-05', 120, 1, 'bueno',     'electrodomestico', 'Marta Cajera', 1),
('Plancha / asador',  1, 1800000.00, 'Plancha a gas de 80cm',     'Distribuidora La Económica', 2, 'PL-2025-07', 1800000.00, '2025-03-01', '2026-03-01', '2025-03-01', 96,  1, 'bueno',     'equipo_cocina',    'Juan Operario', 1),
('Licuadora industrial', 1, 350000.00, 'Licuadora 4 litros',      'Supermercado Express', 3, NULL,         350000.00, '2025-05-10', NULL,          '2025-05-10', 60,  1, 'excelente', 'electrodomestico', 'Juan Operario', 1),
('Mesa de trabajo acero',1, 600000.00, 'Mesa de acero inoxidable','Empaques del Norte', 7, NULL,          600000.00, '2025-01-15', NULL,          '2025-01-20', 120, 1, 'bueno',     'mobiliario',       'Marta Cajera', 1);

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- FIN DE LOS DATOS DE EJEMPLO
-- Sugerencias tras cargar:
--   • Productos → "Recalcular costos" (repuebla costo_calculado exacto).
--   • Contabilidad → "Contabilizar ventas históricas" + Balance de apertura
--     (los asientos automáticos solo se generan con operaciones en vivo; el
--      backfill contabiliza estas ventas de ejemplo).
--   • Para empezar de cero: Admin → Mantenimiento de datos → reset transaccional.
-- ============================================================
