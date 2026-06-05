-- ============================================================
-- Migracion 029: Tabla listas_sistema
-- ============================================================
-- Centraliza todos los catalogos configurables del sistema que
-- antes eran arrays PHP hardcodeados. Permite agregar, editar
-- y desactivar opciones desde Admin sin tocar codigo.
--
-- Listas incluidas:
--   presentacion       - envases de insumos (frasco, paca, etc.)
--   unidad_medida      - unidades basicas (kg, g, litro, etc.)
--   categoria_insumo   - tipo de ingrediente (proteina, lacteo, etc.)
--   categoria_activo   - tipo de activo fijo (equipo_cocina, etc.)
--   categoria_costo    - tipo de costo indirecto (arriendo, etc.)
--   categoria_proveedor- tipo de proveedor (plaza, retail, etc.)
--
-- Columnas:
--   tipo     -> identificador del catalogo
--   valor    -> valor almacenado en la base de datos (nunca cambia)
--   etiqueta -> texto que ve el usuario en el select
--   orden    -> posicion en el dropdown (menor = primero)
--   activo   -> 0 = oculto del dropdown pero conserva datos historicos
-- ============================================================

USE clandestinoERP;

CREATE TABLE IF NOT EXISTS listas_sistema (
    id          INT          AUTO_INCREMENT PRIMARY KEY,
    tipo        VARCHAR(60)  NOT NULL,
    valor       VARCHAR(100) NOT NULL,
    etiqueta    VARCHAR(150) NOT NULL,
    orden       SMALLINT     NOT NULL DEFAULT 0,
    activo      TINYINT(1)   NOT NULL DEFAULT 1,
    created_by  INT          DEFAULT NULL,
    INDEX idx_lista_tipo (tipo, activo, orden)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Restriccion: (tipo, valor) debe ser unico para evitar duplicados
ALTER TABLE listas_sistema
    ADD CONSTRAINT uk_lista_tipo_valor UNIQUE (tipo, valor);

-- ============================================================
-- Datos iniciales — presentaciones de insumos
-- ============================================================
INSERT INTO listas_sistema (tipo, valor, etiqueta, orden) VALUES
('presentacion', 'frasco',  'Frasco',   1),
('presentacion', 'tarro',   'Tarro',    2),
('presentacion', 'caja',    'Caja',     3),
('presentacion', 'paca',    'Paca',     4),
('presentacion', 'bolsa',   'Bolsa',    5),
('presentacion', 'atado',   'Atado',    6),
('presentacion', 'lata',    'Lata',     7),
('presentacion', 'bloque',  'Bloque',   8),
('presentacion', 'galon',   'Galón',    9),
('presentacion', 'unidad',  'Unidad',   10),
('presentacion', 'otra',    'Otra',     99);

-- ============================================================
-- Datos iniciales — unidades de medida de insumos
-- ============================================================
INSERT INTO listas_sistema (tipo, valor, etiqueta, orden) VALUES
('unidad_medida', 'kg',      'Kilogramos',  1),
('unidad_medida', 'g',       'Gramos',      2),
('unidad_medida', 'lb',      'Libras',      3),
('unidad_medida', 'litro',   'Litros',      4),
('unidad_medida', 'ml',      'Mililitros',  5),
('unidad_medida', 'unidad',  'Unidades',    6),
('unidad_medida', 'loncha',  'Lonchas',     7),
('unidad_medida', 'lata',    'Latas',       8),
('unidad_medida', 'paquete', 'Paquetes',    9);

-- ============================================================
-- Datos iniciales — categorias de insumos
-- ============================================================
INSERT INTO listas_sistema (tipo, valor, etiqueta, orden) VALUES
('categoria_insumo', 'proteina',    'Proteína',      1),
('categoria_insumo', 'lacteo',      'Lácteo',        2),
('categoria_insumo', 'vegetal',     'Vegetal',       3),
('categoria_insumo', 'condimento',  'Condimento',    4),
('categoria_insumo', 'empaque',     'Empaque',       5),
('categoria_insumo', 'grasa',       'Grasa',         6),
('categoria_insumo', 'combo',       'Combo',         7),
('categoria_insumo', 'otro',        'Otro',          99);

-- NOTA: Los datos existentes en insumos.categoria usan valores como
-- 'proteína' (con tilde). La migración agrega las nuevas entradas sin
-- tilde. El frontend mostrará la etiqueta correcta al editar.

-- ============================================================
-- Datos iniciales — categorias de activos fijos
-- ============================================================
INSERT INTO listas_sistema (tipo, valor, etiqueta, orden) VALUES
('categoria_activo', 'equipo_cocina',    'Equipo de cocina',   1),
('categoria_activo', 'electrodomestico', 'Electrodoméstico',   2),
('categoria_activo', 'herramienta',      'Herramienta',        3),
('categoria_activo', 'utensilio',        'Utensilio',          4),
('categoria_activo', 'mobiliario',       'Mobiliario',         5),
('categoria_activo', 'vehiculo',         'Vehículo',           6),
('categoria_activo', 'otro',             'Otro',               99);

-- ============================================================
-- Datos iniciales — categorias de costos indirectos
-- ============================================================
INSERT INTO listas_sistema (tipo, valor, etiqueta, orden) VALUES
('categoria_costo', 'arriendo',           'Arriendo / Alquiler',      1),
('categoria_costo', 'servicios_publicos', 'Servicios Públicos',       2),
('categoria_costo', 'intereses',          'Intereses y Financiación', 3),
('categoria_costo', 'seguros',            'Seguros',                  4),
('categoria_costo', 'mantenimiento',      'Mantenimiento',            5),
('categoria_costo', 'publicidad',         'Publicidad y Mercadeo',    6),
('categoria_costo', 'bancario',           'Gastos Bancarios',         7),
('categoria_costo', 'impuestos',          'Impuestos y Tasas',        8),
('categoria_costo', 'administrativo',     'Personal Administrativo',  9),
('categoria_costo', 'otro',               'Otro',                     99);

-- ============================================================
-- Datos iniciales — categorias de proveedores
-- ============================================================
INSERT INTO listas_sistema (tipo, valor, etiqueta, orden) VALUES
('categoria_proveedor', 'plaza',      'Plaza de mercado', 1),
('categoria_proveedor', 'tienda',     'Tienda',           2),
('categoria_proveedor', 'retail',     'Retail',           3),
('categoria_proveedor', 'online',     'Online',           4),
('categoria_proveedor', 'mayorista',  'Mayorista',        5),
('categoria_proveedor', 'panaderia',  'Panadería',        6),
('categoria_proveedor', 'otro',       'Otro',             99);
