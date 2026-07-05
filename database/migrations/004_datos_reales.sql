-- ============================================================
-- Migración 004 — Datos reales QuickServe OS
-- Fuente: Excel "Extraccion exacta QuickServe OS" (Blueprint v4.0)
-- EJECUTAR DESPUES de 001_schema.sql + 002_login_intentos.sql + 003_sprint2.sql
-- ============================================================


SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- 1. LIMPIAR datos placeholder del schema inicial
-- ============================================================
DELETE FROM recetas;
DELETE FROM productos;
DELETE FROM insumos;
DELETE FROM activos;
DELETE FROM proveedores;
-- NOTA: No borrar usuarios ni configuracion_negocio

-- ============================================================
-- 2. PROVEEDORES / LUGARES DE COMPRA
-- ============================================================
INSERT INTO `proveedores` (`nombre`, `categoria`, `contacto`, `telefono`, `activo`) VALUES
('Plaza Minorista',   'plaza',       NULL, NULL, 1),
('D1',                'tienda',      NULL, NULL, 1),
('Alkosto',           'retail',      NULL, NULL, 1),
('MercadoLibre',      'online',      NULL, NULL, 1),
('Dulce Tentación',   'panadería',   NULL, NULL, 1),
('Bamboos',           'transporte',  NULL, NULL, 1),
('Desechables',       'desechables', NULL, NULL, 1);

-- ============================================================
-- 3. INSUMOS reales con costos del Excel (Hoja: INSUMOS)
-- ============================================================
-- Precios basados en hoja INSUMOS — columna "Costo" de primera compra
INSERT INTO `insumos`
    (`nombre`, `categoria`, `unidad_medida`, `costo_actual`, `stock_actual`, `stock_seguridad`, `proveedor_id`)
VALUES
-- ── Proteínas ─────────────────────────────────────────────────────────────
('Pollo Desmechado',          'proteína',   'kg',      17325.00,  0, 2.000, 1),
('Carne de Res',              'proteína',   'kg',      27000.00,  0, 1.000, 1),
('Atún Lata 160g',            'proteína',   'lata',     2995.00,  0, 6.000, 2),
('Jamón de Cerdo (loncha)',   'proteína',   'loncha',    489.13,  0,14.000, 1),
('Jamón de Pavo (loncha)',    'proteína',   'loncha',    863.64,  0, 7.000, 1),
('Jamón de Cordero (loncha)', 'proteína',   'loncha',    489.13,  0,14.000, 1),
-- ── Lácteos y bases ───────────────────────────────────────────────────────
('Mayonesa',                  'lácteo',     'kg',       8500.00,  0, 1.600, 1),
('Mostaza',                   'lácteo',     'kg',      10000.00,  0, 0.500, 1),
('Queso Doble Crema (loncha)','lácteo',     'loncha',    442.56,  0,37.000, 1),
('Queso Mozzarella (loncha)', 'lácteo',     'loncha',    625.00,  0,37.000, 1),
('Queso Campesino',           'lácteo',     'kg',      18000.00,  0, 0.000, 1),
('Queso Costeño Rallado',     'lácteo',     'g',          94.40,  0,30.000, 1),
('Leche Entera',              'lácteo',     'ml',          2.95,  0,80.000, 1),
('Mantequilla',               'lácteo',     'g',          19.75,  0,108.0,  1),
-- ── Vegetales y frutas ────────────────────────────────────────────────────
('Lechuga Crespa',            'vegetal',    'unidad',   2500.00,  0, 2.500, 1),
('Tomate Chonto',             'vegetal',    'g',           3.30,  0,1175.0, 1),
('Tomate de Mesa',            'vegetal',    'g',           3.30,  0,1500.0, 1),
('Cebolla Blanca',            'vegetal',    'g',          10.00,  0, 375.0, 1),
('Cebolla Larga',             'vegetal',    'g',          16.00,  0, 377.0, 1),
('Ajo',                       'vegetal',    'g',          16.00,  0, 131.0, 1),
('Plátano Maduro',            'vegetal',    'unidad',   3000.00,  0, 1.000, 1),
('Limón',                     'vegetal',    'g',          10.00,  0,  30.0, 1),
('Aguacate',                  'vegetal',    'unidad',   4000.00,  0, 1.200, 1),
('Cilantro',                  'vegetal',    'atado',    2000.00,  0, 0.500, 1),
('Perejil',                   'vegetal',    'atado',    2000.00,  0, 0.500, 1),
('Maíz Tierno (lata)',        'vegetal',    'lata',     10000.00, 0, 0.000, 1),
('Apio',                      'vegetal',    'rama',      200.00,  0, 0.111, 1),
('Pimentón',                  'vegetal',    'g',          6.00,   0, 216.0, 1),
-- ── Grasas y líquidos ─────────────────────────────────────────────────────
('Aceite Vegetal',            'grasa',      'ml',          9.44,  0, 200.0, 2),
('Miel de Abejas',            'grasa',      'frasco',  15000.00,  0, 0.125, 1),
('Vinagre',                   'grasa',      'ml',          2.85,  0,  20.0, 2),
('Azúcar Blanca',             'condimento', 'g',           3.99,  0,  50.0, 2),
-- ── Condimentos ───────────────────────────────────────────────────────────
('Sal',                       'condimento', 'g',           2.70,  0, 100.0, 2),
('Ricostilla',                'condimento', 'cubo',      498.75,  0,   1.0, 2),
('Laurel',                    'condimento', 'atado',    1000.00,  0,  0.50, 1),
('Pimienta',                  'condimento', 'paquete',  3000.00,  0,   1.0, 1),
('Tomillo',                   'condimento', 'atado',    1000.00,  0,  0.50, 1),
('Finas Hierbas',             'condimento', 'paquete',  1990.00,  0,   1.0, 1),
-- ── Empaques ──────────────────────────────────────────────────────────────
('Pan de Sándwich',           'empaque',    'unidad',   1500.00,  0,  37.0, 5),
('Papel Parafinado (lámina)', 'empaque',    'unidad',    110.00,  0,  37.0, 1),
('Bolsa de Papel',            'empaque',    'unidad',    560.00,  0,  37.0, 1),
-- ── Combos ────────────────────────────────────────────────────────────────
('Papas Margarita Pollo',     'combo',      'paquete',  1525.00,  0,   2.0, 1),
('Gaseosa Manzana 400ml',     'combo',      'botella',  2483.33,  0,   2.0, 1),
('Chocolatina Jet Bolitas',   'combo',      'unidad',    710.00,  0,   2.0, 1);

-- ============================================================
-- 4. PRODUCTOS QuickServe OS — Los 4 sándwiches reales
-- ============================================================
-- Precios = $18,000 (hoja COSTEO, fila 19)
INSERT INTO `productos` (`nombre`, `categoria`, `tamano`, `precio_venta`) VALUES
('El Desechado',       'sandwich', 'unico', 18000),  -- Pollo desmechado
('El Triple Golpe',    'sandwich', 'unico', 18000),  -- 3 tipos de jamón
('El Submarino',       'sandwich', 'unico', 18000),  -- Atún
('El Criollo Pesado',  'sandwich', 'unico', 18000);  -- Carne de res

-- IDs esperados: 1=Desechado, 2=TripleGolpe, 3=Submarino, 4=Criollo

-- ============================================================
-- 5. RECETAS por producto
-- Fuente: Hoja INSUMOS — columnas J(Pollo), K(Carne), L(Jamón), M(Atún)
-- Rendimiento base: 37 sándwiches por preparación
-- ============================================================

-- Helper: SET @pid_desechado = (SELECT id FROM productos WHERE nombre='El Desechado');
SET @p_desechado  = (SELECT id FROM productos WHERE nombre='El Desechado'  LIMIT 1);
SET @p_triple     = (SELECT id FROM productos WHERE nombre='El Triple Golpe' LIMIT 1);
SET @p_submarino  = (SELECT id FROM productos WHERE nombre='El Submarino'  LIMIT 1);
SET @p_criollo    = (SELECT id FROM productos WHERE nombre='El Criollo Pesado' LIMIT 1);

-- IDs de insumos
SET @i_pollo      = (SELECT id FROM insumos WHERE nombre='Pollo Desmechado'         LIMIT 1);
SET @i_carne      = (SELECT id FROM insumos WHERE nombre='Carne de Res'             LIMIT 1);
SET @i_atun       = (SELECT id FROM insumos WHERE nombre='Atún Lata 160g'           LIMIT 1);
SET @i_jcerdo     = (SELECT id FROM insumos WHERE nombre='Jamón de Cerdo (loncha)'  LIMIT 1);
SET @i_jpavo      = (SELECT id FROM insumos WHERE nombre='Jamón de Pavo (loncha)'   LIMIT 1);
SET @i_jcordero   = (SELECT id FROM insumos WHERE nombre='Jamón de Cordero (loncha)' LIMIT 1);
SET @i_mayo       = (SELECT id FROM insumos WHERE nombre='Mayonesa'                 LIMIT 1);
SET @i_mostaza    = (SELECT id FROM insumos WHERE nombre='Mostaza'                  LIMIT 1);
SET @i_qdc        = (SELECT id FROM insumos WHERE nombre='Queso Doble Crema (loncha)' LIMIT 1);
SET @i_qmoz       = (SELECT id FROM insumos WHERE nombre='Queso Mozzarella (loncha)' LIMIT 1);
SET @i_lechuga    = (SELECT id FROM insumos WHERE nombre='Lechuga Crespa'           LIMIT 1);
SET @i_tomcto     = (SELECT id FROM insumos WHERE nombre='Tomate Chonto'            LIMIT 1);
SET @i_tomm       = (SELECT id FROM insumos WHERE nombre='Tomate de Mesa'           LIMIT 1);
SET @i_cblla      = (SELECT id FROM insumos WHERE nombre='Cebolla Blanca'           LIMIT 1);
SET @i_cblal      = (SELECT id FROM insumos WHERE nombre='Cebolla Larga'            LIMIT 1);
SET @i_ajo        = (SELECT id FROM insumos WHERE nombre='Ajo'                      LIMIT 1);
SET @i_mant       = (SELECT id FROM insumos WHERE nombre='Mantequilla'              LIMIT 1);
SET @i_pan        = (SELECT id FROM insumos WHERE nombre='Pan de Sándwich'          LIMIT 1);
SET @i_papel      = (SELECT id FROM insumos WHERE nombre='Papel Parafinado (lámina)' LIMIT 1);
SET @i_bolsa      = (SELECT id FROM insumos WHERE nombre='Bolsa de Papel'           LIMIT 1);
SET @i_aceite     = (SELECT id FROM insumos WHERE nombre='Aceite Vegetal'           LIMIT 1);
SET @i_sal        = (SELECT id FROM insumos WHERE nombre='Sal'                      LIMIT 1);
SET @i_ricost     = (SELECT id FROM insumos WHERE nombre='Ricostilla'               LIMIT 1);
SET @i_laurel     = (SELECT id FROM insumos WHERE nombre='Laurel'                   LIMIT 1);
SET @i_pimienta   = (SELECT id FROM insumos WHERE nombre='Pimienta'                 LIMIT 1);
SET @i_limón      = (SELECT id FROM insumos WHERE nombre='Limón'                    LIMIT 1);
SET @i_aguacate   = (SELECT id FROM insumos WHERE nombre='Aguacate'                 LIMIT 1);

-- ── El Desechado (Pollo) ─────────────────────────────────────────────────────
-- Fuente: columna K (Pollo) de la hoja INSUMOS
-- 1 kg de pollo rinde 11 unidades → 0.090909 kg/u
INSERT INTO recetas (producto_id, insumo_id, cantidad_requerida, es_insumo_critico) VALUES
(@p_desechado, @i_pollo,     0.090909, 1),  -- Pollo ← CRÍTICO (determina capacidad)
(@p_desechado, @i_mayo,      0.043243, 0),  -- Mayonesa 1.6kg/37u = 0.043
(@p_desechado, @i_mostaza,   0.013514, 0),  -- Mostaza 0.5kg/37u
(@p_desechado, @i_qdc,       1.000000, 0),  -- Queso doble crema 1 loncha/u
(@p_desechado, @i_qmoz,      1.000000, 0),  -- Queso mozzarella 1 loncha/u
(@p_desechado, @i_lechuga,   0.067568, 0),  -- Lechuga 2.5u/37u
(@p_desechado, @i_tomcto,   31.824324, 0),  -- Tomate chonto 1175g/37u
(@p_desechado, @i_tomm,      40.54054, 0),  -- Tomate de mesa 1500g/37u
(@p_desechado, @i_cblla,     10.13514, 0),  -- Cebolla blanca 375g/37u
(@p_desechado, @i_cblal,     10.18919, 0),  -- Cebolla larga 377g/37u
(@p_desechado, @i_ajo,        3.54054, 0),  -- Ajo 131g/37u
(@p_desechado, @i_mant,       2.91892, 0),  -- Mantequilla 108g/37u
(@p_desechado, @i_aceite,     5.40541, 0),  -- Aceite 200ml/37u
(@p_desechado, @i_sal,        2.70270, 0),  -- Sal 100g/37u
(@p_desechado, @i_ricost,     0.027027, 0), -- Ricostilla 1 cubo/37u
(@p_desechado, @i_laurel,     0.013514, 0), -- Laurel 0.5 atado/37u
(@p_desechado, @i_pimienta,   0.054054, 0), -- Pimienta 2g/37u
(@p_desechado, @i_limón,      0.810811, 0), -- Limón 30g/37u
(@p_desechado, @i_pan,        1.000000, 0), -- Pan 1/u
(@p_desechado, @i_papel,      1.000000, 0), -- Papel parafinado 1/u
(@p_desechado, @i_bolsa,      1.000000, 0); -- Bolsa 1/u

-- ── El Triple Golpe (3 Jamones) ──────────────────────────────────────────────
-- Jamón de cerdo: 14 lonchas/7u = 2 lonchas/u
-- Jamón de pavo: 7 lonchas/7u = 1 loncha/u
-- Jamón de cordero: 14 lonchas/7u = 2 lonchas/u
INSERT INTO recetas (producto_id, insumo_id, cantidad_requerida, es_insumo_critico) VALUES
(@p_triple, @i_jcerdo,    2.000000, 1),  -- Jamón cerdo ← CRÍTICO
(@p_triple, @i_jpavo,     1.000000, 0),  -- Jamón pavo
(@p_triple, @i_jcordero,  2.000000, 0),  -- Jamón cordero
(@p_triple, @i_mayo,      0.043243, 0),
(@p_triple, @i_mostaza,   0.013514, 0),
(@p_triple, @i_qdc,       1.000000, 0),
(@p_triple, @i_qmoz,      1.000000, 0),
(@p_triple, @i_lechuga,   0.067568, 0),
(@p_triple, @i_tomcto,   31.824324, 0),
(@p_triple, @i_tomm,      40.54054, 0),
(@p_triple, @i_cblla,     10.13514, 0),
(@p_triple, @i_cblal,     10.18919, 0),
(@p_triple, @i_ajo,        3.54054, 0),
(@p_triple, @i_mant,       2.91892, 0),
(@p_triple, @i_aceite,     5.40541, 0),
(@p_triple, @i_sal,        2.70270, 0),
(@p_triple, @i_limón,      0.810811, 0),
(@p_triple, @i_pan,        1.000000, 0),
(@p_triple, @i_papel,      1.000000, 0),
(@p_triple, @i_bolsa,      1.000000, 0);

-- ── El Submarino (Atún) ───────────────────────────────────────────────────────
-- 6 latas/9u = 0.6667 latas/u
INSERT INTO recetas (producto_id, insumo_id, cantidad_requerida, es_insumo_critico) VALUES
(@p_submarino, @i_atun,      0.666667, 1),  -- Atún ← CRÍTICO
(@p_submarino, @i_mayo,      0.043243, 0),
(@p_submarino, @i_mostaza,   0.013514, 0),
(@p_submarino, @i_qdc,       1.000000, 0),
(@p_submarino, @i_qmoz,      1.000000, 0),
(@p_submarino, @i_lechuga,   0.067568, 0),
(@p_submarino, @i_tomcto,   31.824324, 0),
(@p_submarino, @i_cblla,     10.13514, 0),
(@p_submarino, @i_aguacate,  0.032432, 0),  -- Aguacate 1.2u/37u
(@p_submarino, @i_ajo,        3.54054, 0),
(@p_submarino, @i_aceite,     5.40541, 0),
(@p_submarino, @i_sal,        2.70270, 0),
(@p_submarino, @i_limón,      0.270270, 0),
(@p_submarino, @i_pan,        1.000000, 0),
(@p_submarino, @i_papel,      1.000000, 0),
(@p_submarino, @i_bolsa,      1.000000, 0);

-- ── El Criollo Pesado (Carne) ─────────────────────────────────────────────────
-- 1 kg rinde 10u → 0.1 kg/u
INSERT INTO recetas (producto_id, insumo_id, cantidad_requerida, es_insumo_critico) VALUES
(@p_criollo, @i_carne,      0.100000, 1),  -- Carne ← CRÍTICO
(@p_criollo, @i_mayo,       0.043243, 0),
(@p_criollo, @i_mostaza,    0.013514, 0),
(@p_criollo, @i_qdc,        1.000000, 0),
(@p_criollo, @i_qmoz,       1.000000, 0),
(@p_criollo, @i_lechuga,    0.067568, 0),
(@p_criollo, @i_tomcto,    31.824324, 0),
(@p_criollo, @i_tomm,       40.54054, 0),
(@p_criollo, @i_cblla,      10.13514, 0),
(@p_criollo, @i_cblal,      10.18919, 0),
(@p_criollo, @i_ajo,         3.54054, 0),
(@p_criollo, @i_mant,        2.91892, 0),
(@p_criollo, @i_aceite,      5.40541, 0),
(@p_criollo, @i_sal,         2.70270, 0),
(@p_criollo, @i_ricost,      0.027027, 0),
(@p_criollo, @i_laurel,      0.013514, 0),
(@p_criollo, @i_pimienta,    0.054054, 0),
(@p_criollo, @i_pan,         1.000000, 0),
(@p_criollo, @i_papel,       1.000000, 0),
(@p_criollo, @i_bolsa,       1.000000, 0);

-- ============================================================
-- 6. Recalcular costo_calculado en todos los productos
-- ============================================================
UPDATE productos p
SET p.costo_calculado = (
    SELECT IFNULL(SUM(i.costo_actual * r.cantidad_requerida), 0)
    FROM recetas r JOIN insumos i ON i.id = r.insumo_id
    WHERE r.producto_id = p.id
)
WHERE p.activo = 1;

-- ============================================================
-- 7. ACTIVOS / EQUIPOS (Hoja: UTENCILIOS Y EQUIPOS)
-- Fuente: hoja UTENCILIOS — vida útil 12 meses para todos
-- ============================================================
INSERT INTO `activos` (`nombre`, `descripcion`, `lugar_compra`, `costo_inicial`, `fecha_adquisicion`, `vida_util_meses`) VALUES
('Bombonera vidrio para salsas',  '4 unidades',              NULL,           64000,  '2025-01-01', 12),
('Salsero Jumbo',                 '4 unidades',              NULL,           28000,  '2025-01-01', 12),
('Salsero Grande',                '1 unidad',                NULL,            6000,  '2025-01-01', 12),
('Delantales',                    '3 unidades',              'MercadoLibre', 167700,  '2025-01-01', 12),
('Guantes de Látex',              'Caja x 100 unidades',     'Desechables',   24000,  '2025-01-01', 12),
('Gorros',                        'Caja x 100 unidades',     'Desechables',   28000,  '2025-01-01', 12),
('Tabla Picar Grande',            '1 unidad',                'MercadoLibre',  94000,  '2025-01-01', 12),
('Tabla Picar Pequeña',           '1 unidad',                'MercadoLibre',  49500,  '2025-01-01', 12),
('Afilador + Cuchillo',           '1 set',                   'MercadoLibre',  17600,  '2025-01-01', 12),
('Ollas a Presión',               '2 unidades',              'Alkosto',       219800, '2025-01-01', 12),
('Licuadoras',                    '2 unidades',              'Alkosto',       778000, '2025-01-01', 12),
('Ayudante de Cocina',            '1 unidad (procesadora)',  'Alkosto',       279900, '2025-01-01', 12),
('Tarros Plásticos',              'Set x 5',                 'MercadoLibre',  65900,  '2025-01-01', 12),
('Balanza',                       '1 unidad',                'MercadoLibre',   6500,  '2025-01-01', 12),
('Contenedor de Alimentos',       '2 unidades',              'MercadoLibre',  239600, '2025-01-01', 12);

-- ============================================================
-- 8. EMPLEADOS (Hoja: RH Y COSTOS FIJOS)
-- 3 empleados a salario mínimo 2026
-- ============================================================
INSERT INTO `empleados`
    (`nombre_completo`, `cargo`, `tipo_contrato`, `fecha_ingreso`, `salario_base`, `aplica_aux_transporte`)
VALUES
('Preparador QuickServe OS',  'Preparador',  'tiempo_completo', '2025-01-01', 1750905, 1),
('Vendedor QuickServe OS',    'Vendedor',    'tiempo_completo', '2025-01-01', 1750905, 1),
('Cocinero QuickServe OS',    'Cocinero',    'tiempo_completo', '2025-01-01', 1750905, 1);

-- ============================================================
-- 9. CONFIGURACIÓN: actualizar producción diaria
-- ============================================================
-- La hoja muestra 100 unidades/día
UPDATE `configuracion_negocio` SET `valor` = 100 WHERE `clave` = 'produccion_estimada_mensual'
  AND `valor` = 2175;
-- Restaurar valor correcto mensual (100 días × 21.75 días hábiles ≈ 2175/mes)
UPDATE `configuracion_negocio` SET `valor` = 2175 WHERE `clave` = 'produccion_estimada_mensual';

-- Actualizar costos fijos reales (Hoja RH Y COSTOS FIJOS, fila 38)
-- Agua 40k + Luz 70k + Gas 70k + Internet 30k + Arriendo 300k = 510k
UPDATE `configuracion_negocio` SET `valor` = 510000 WHERE `clave` = 'costos_fijos_mensuales';

-- ============================================================
-- 10. ASIGNAR permisos al superadmin para módulo productos
-- ============================================================
INSERT IGNORE INTO `permisos_modulos` (`usuario_id`, `modulo`, `nivel_acceso`)
VALUES (1, 'productos', 'admin_total');

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- VERIFICACIÓN:
-- SELECT nombre, precio_venta, costo_calculado,
--        ROUND(precio_venta - costo_calculado, 0) AS margen_bruto_ing
-- FROM productos WHERE activo = 1;
--
-- SELECT nombre, ROUND(depreciacion_diaria, 2) AS dep_dia
-- FROM activos WHERE activo = 1;
--
-- Depreciación total diaria esperada: ~$57.30/u (57.297 del Excel)
-- ============================================================
