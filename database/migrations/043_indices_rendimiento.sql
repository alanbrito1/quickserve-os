-- ============================================================
-- Migración 043 — Índices de rendimiento (auditoría G18 de tests/suite.php)
-- ============================================================
-- Agrega dos índices que faltan en bases de datos anteriores:
--   - insumos.activo            → se filtra en casi toda query de insumos.
--   - compra_detalles.presentacion_id → JOIN/filtro con insumo_presentaciones
--                                       (snapshot mig. 039).
--
-- Idempotente: solo crea cada índice si la columna aún no tiene uno (así no
-- falla si ya existe, p. ej. en instalaciones nuevas desde schema.sql donde
-- insumos.activo ya viene como idx_ins_activo).
-- ============================================================


-- insumos.activo
SET @idx_ins := (SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'insumos' AND COLUMN_NAME = 'activo');
SET @sql_ins := IF(@idx_ins = 0, 'CREATE INDEX idx_ins_activo ON insumos (activo)', 'DO 0');
PREPARE s1 FROM @sql_ins; EXECUTE s1; DEALLOCATE PREPARE s1;

-- compra_detalles.presentacion_id
SET @idx_cd := (SELECT COUNT(*) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'compra_detalles' AND COLUMN_NAME = 'presentacion_id');
SET @sql_cd := IF(@idx_cd = 0, 'CREATE INDEX idx_cd_presentacion ON compra_detalles (presentacion_id)', 'DO 0');
PREPARE s2 FROM @sql_cd; EXECUTE s2; DEALLOCATE PREPARE s2;
