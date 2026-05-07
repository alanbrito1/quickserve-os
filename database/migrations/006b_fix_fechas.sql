-- ============================================================
-- Migración 006b — Fix: ejecutar SOLO si 006 dio error de columna duplicada
-- La columna fecha_inicio_uso YA existe. Solo actualizamos los datos.
-- IMPORTANTE: Cambiar 'clandestinoERP' por el nombre real de tu base de datos
-- ============================================================
USE `clandestinoERP`;

-- Poner fecha_inicio_uso = hoy en activos que no la tienen
UPDATE `activos`
SET `fecha_inicio_uso` = CURDATE()
WHERE `fecha_inicio_uso` IS NULL;

-- Corregir activos con fecha 2025 que aparecen como depreciados
-- (les ponemos 2026-01-01 para que muestren vida útil correcta)
UPDATE `activos`
SET `fecha_adquisicion` = '2026-01-01',
    `fecha_inicio_uso`  = '2026-01-01'
WHERE YEAR(`fecha_adquisicion`) = 2025;

-- Agregar índice SOLO si no existe aún
SET @idx = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'activos'
    AND INDEX_NAME   = 'idx_fecha_inicio_uso'
);

SET @sql = IF(@idx = 0,
    'ALTER TABLE activos ADD INDEX idx_fecha_inicio_uso (fecha_inicio_uso)',
    'SELECT 1'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ============================================================
-- VERIFICACIÓN:
-- SELECT id, nombre, fecha_adquisicion, fecha_inicio_uso FROM activos LIMIT 5;
-- ============================================================
