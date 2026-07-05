-- ============================================================
-- Migración 005 — Activos: campos físicos, foto, serial, unidades
-- Ejecutar DESPUÉS de 003_sprint2.sql
-- ============================================================

-- ── 1. Nuevas columnas en activos ────────────────────────────────────────────
ALTER TABLE `activos`
  -- Unidades y precio individual
  ADD COLUMN `numero_unidades`  SMALLINT UNSIGNED  NOT NULL DEFAULT 1
    COMMENT 'Cantidad de unidades (ej: 2 licuadoras → costo_inicial = precio_u × 2)'
    AFTER `nombre`,
  ADD COLUMN `precio_unitario`  DECIMAL(12,2)      NULL
    COMMENT 'Precio de compra por unidad. Si se llena, costo_inicial = precio_u × unidades'
    AFTER `numero_unidades`,
  -- Identificación física
  ADD COLUMN `serial`           VARCHAR(100)       NULL
    COMMENT 'Número de serie o código del equipo para identificación física'
    AFTER `lugar_compra`,
  ADD COLUMN `foto_url`         VARCHAR(255)       NULL
    COMMENT 'Ruta relativa a la foto: uploads/activos/nombre.jpg'
    AFTER `serial`,
  -- Control físico y responsabilidad
  ADD COLUMN `estado_fisico`    ENUM('excelente','bueno','regular','malo','baja')
    NOT NULL DEFAULT 'bueno'
    COMMENT 'Condición física actual del activo'
    AFTER `activo`,
  ADD COLUMN `categoria_activo` ENUM('equipo_cocina','herramienta','electrodomestico','utensilio','mobiliario','vehiculo','otro')
    NOT NULL DEFAULT 'otro'
    COMMENT 'Tipo o categoría del activo'
    AFTER `estado_fisico`,
  ADD COLUMN `responsable`      VARCHAR(100)       NULL
    COMMENT 'Persona o área a cargo del activo'
    AFTER `categoria_activo`,
  ADD COLUMN `garantia_hasta`   DATE               NULL
    COMMENT 'Fecha límite de garantía del proveedor'
    AFTER `fecha_adquisicion`,
  ADD COLUMN `notas`            TEXT               NULL
    COMMENT 'Observaciones: mantenimientos, reparaciones, incidencias'
    AFTER `responsable`;

-- ── 2. Reconstruir trigger INSERT para calcular costo_inicial desde precio_u ─
DELIMITER $$

DROP TRIGGER IF EXISTS `trg_activos_deprec_insert`$$
CREATE TRIGGER `trg_activos_deprec_insert`
BEFORE INSERT ON `activos`
FOR EACH ROW
BEGIN
    -- Si el usuario proporcionó precio_unitario, calcular costo_inicial automáticamente
    IF NEW.precio_unitario IS NOT NULL AND NEW.precio_unitario > 0 AND NEW.numero_unidades > 0 THEN
        SET NEW.costo_inicial = NEW.precio_unitario * NEW.numero_unidades;
    END IF;
    -- Calcular depreciaciones (evitar división por cero)
    IF NEW.vida_util_meses > 0 THEN
        SET NEW.depreciacion_mensual = NEW.costo_inicial / NEW.vida_util_meses;
        SET NEW.depreciacion_diaria  = NEW.depreciacion_mensual / 30.4;
    ELSE
        SET NEW.depreciacion_mensual = 0;
        SET NEW.depreciacion_diaria  = 0;
    END IF;
END$$

DROP TRIGGER IF EXISTS `trg_activos_deprec_update`$$
CREATE TRIGGER `trg_activos_deprec_update`
BEFORE UPDATE ON `activos`
FOR EACH ROW
BEGIN
    -- Recalcular costo_inicial si cambió precio_unitario o numero_unidades
    IF NEW.precio_unitario IS NOT NULL AND NEW.precio_unitario > 0
       AND (NEW.precio_unitario <> OLD.precio_unitario OR NEW.numero_unidades <> OLD.numero_unidades) THEN
        SET NEW.costo_inicial = NEW.precio_unitario * NEW.numero_unidades;
    END IF;
    -- Recalcular depreciaciones si cambió costo o vida útil
    IF NEW.costo_inicial <> OLD.costo_inicial OR NEW.vida_util_meses <> OLD.vida_util_meses THEN
        IF NEW.vida_util_meses > 0 THEN
            SET NEW.depreciacion_mensual = NEW.costo_inicial / NEW.vida_util_meses;
            SET NEW.depreciacion_diaria  = NEW.depreciacion_mensual / 30.4;
        ELSE
            SET NEW.depreciacion_mensual = 0;
            SET NEW.depreciacion_diaria  = 0;
        END IF;
    END IF;
END$$

DELIMITER ;

-- ── 3. Índices útiles ─────────────────────────────────────────────────────────
ALTER TABLE `activos`
  ADD INDEX `idx_activos_categoria` (`categoria_activo`),
  ADD INDEX `idx_activos_estado`    (`estado_fisico`),
  ADD INDEX `idx_activos_garantia`  (`garantia_hasta`);

-- ── 4. Crear directorio de fotos (hacerlo manualmente en el servidor) ─────────
-- mkdir -p public_html/uploads/activos
-- chmod 755 public_html/uploads/activos

-- ============================================================
-- VERIFICACIÓN:
-- DESCRIBE activos;
-- -- Debe mostrar columnas: numero_unidades, precio_unitario, serial, foto_url,
-- --   estado_fisico, categoria_activo, responsable, garantia_hasta, notas
-- ============================================================
