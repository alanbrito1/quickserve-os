-- ============================================================
-- Migración 006 — Activos: fecha_inicio_uso para depreciación correcta
-- IMPORTANTE: Cambiar 'clandestinoERP' por el nombre real de tu base de datos
-- Versión segura: no falla si se ejecuta dos veces
-- ============================================================
USE `clandestinoERP`;

-- ── 1. Agregar columna solo si NO existe ─────────────────────────────────────
SET @col = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'activos'
    AND COLUMN_NAME  = 'fecha_inicio_uso'
);

SET @sql = IF(@col = 0,
    'ALTER TABLE activos ADD COLUMN fecha_inicio_uso DATE NULL COMMENT "Fecha en que el activo entro en operacion. La depreciacion se calcula desde aqui." AFTER fecha_adquisicion',
    'SELECT "La columna fecha_inicio_uso ya existe"'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ── 2. Actualizar activos sin fecha de inicio: asignar hoy ───────────────────
UPDATE `activos`
SET `fecha_inicio_uso` = CURDATE()
WHERE `fecha_inicio_uso` IS NULL;

-- ── 3. Corregir fechas demo de 2025 que aparecen como depreciados ─────────────
UPDATE `activos`
SET `fecha_adquisicion` = '2026-01-01',
    `fecha_inicio_uso`  = '2026-01-01'
WHERE YEAR(`fecha_adquisicion`) = 2025;

-- ── 4. Agregar índice solo si NO existe ──────────────────────────────────────
SET @idx = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'activos'
    AND INDEX_NAME   = 'idx_fecha_inicio_uso'
);
SET @sql2 = IF(@idx = 0,
    'ALTER TABLE activos ADD INDEX idx_fecha_inicio_uso (fecha_inicio_uso)',
    'SELECT "Indice ya existe"'
);
PREPARE s2 FROM @sql2; EXECUTE s2; DEALLOCATE PREPARE s2;

-- ============================================================
-- VERIFICACIÓN:
-- SELECT id, nombre, fecha_adquisicion, fecha_inicio_uso FROM activos LIMIT 5;
-- ============================================================
