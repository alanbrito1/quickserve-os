-- ============================================================
-- Migración 012 — Módulo Costos Indirectos
-- Crea la tabla para gestionar arriendo, servicios, intereses
-- y demás costos fijos/variables del negocio. Los costos activos
-- alimentan el cálculo de costo de producto en el módulo Productos.
-- IMPORTANTE: Cambiar 'clandestinoERP' por el nombre real de tu DB
-- ============================================================
USE `clandestinoERP`;

CREATE TABLE IF NOT EXISTS `costos_indirectos` (
    `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `nombre`       VARCHAR(200)  NOT NULL
                       COMMENT 'Nombre descriptivo del costo (ej: Arriendo local, Servicio de agua)',
    `categoria`    VARCHAR(60)   NOT NULL DEFAULT 'otro'
                       COMMENT 'arriendo|servicios_publicos|intereses|seguros|mantenimiento|publicidad|bancario|impuestos|administrativo|otro',
    `descripcion`  TEXT              NULL
                       COMMENT 'Detalle adicional del costo',
    `tipo`         ENUM('fijo','variable') NOT NULL DEFAULT 'fijo'
                       COMMENT 'fijo=siempre el mismo monto; variable=monto estimado promedio',
    `frecuencia`   ENUM('mensual','bimestral','trimestral','semestral','anual')
                   NOT NULL DEFAULT 'mensual'
                       COMMENT 'Cada cuánto se paga este costo',
    `valor`        DECIMAL(12,2) NOT NULL DEFAULT 0.00
                       COMMENT 'Monto que se paga por período (según frecuencia)',
    `fecha_inicio` DATE          NOT NULL
                       COMMENT 'Desde cuándo aplica este costo',
    `fecha_fin`    DATE              NULL
                       COMMENT 'NULL = vigente indefinidamente; fecha = cuando terminó/terminará',
    `empleado_id`  INT UNSIGNED      NULL
                       COMMENT 'Empleado responsable o al que se asigna este costo (opcional)',
    `activo`       TINYINT(1)    NOT NULL DEFAULT 1
                       COMMENT '1=activo (suma al costo mensual); 0=pausado',
    `notas`        TEXT              NULL,
    `created_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by`   INT UNSIGNED      NULL,
    `updated_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by`   INT UNSIGNED      NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_ci_activo`    (`activo`),
    INDEX `idx_ci_categoria` (`categoria`),
    INDEX `idx_ci_empleado`  (`empleado_id`),
    CONSTRAINT `fk_ci_empleado`
        FOREIGN KEY (`empleado_id`) REFERENCES `empleados`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Costos indirectos: arriendo, servicios públicos, intereses, seguros, etc.';

-- ─── Permisos: agregar 'costos' al ENUM y dar admin_total al superadmin ───────
-- El sistema usa la tabla permisos_modulos (igual que migración 011)
ALTER TABLE `permisos_modulos`
  MODIFY COLUMN `modulo`
    ENUM('ventas','compras','inventario','nomina','productos','activos',
         'reportes','proveedores','costos')
    NOT NULL;

INSERT IGNORE INTO `permisos_modulos` (`usuario_id`, `modulo`, `nivel_acceso`)
VALUES (1, 'costos', 'admin_total');

-- ─── Datos de ejemplo (opcional) ─────────────────────────────────────────────
-- INSERT INTO `costos_indirectos` (nombre, categoria, tipo, frecuencia, valor, fecha_inicio)
-- VALUES
--   ('Arriendo local', 'arriendo', 'fijo', 'mensual', 1200000, CURDATE()),
--   ('Servicio de energía', 'servicios_publicos', 'variable', 'mensual', 150000, CURDATE()),
--   ('Servicio de agua', 'servicios_publicos', 'variable', 'mensual', 80000, CURDATE()),
--   ('Internet + teléfono', 'servicios_publicos', 'fijo', 'mensual', 120000, CURDATE());

-- ─── Verificar: ──────────────────────────────────────────────────────────────
-- SELECT nombre, categoria, tipo, frecuencia, valor,
--        CASE frecuencia
--            WHEN 'mensual'    THEN valor
--            WHEN 'bimestral'  THEN valor / 2
--            WHEN 'trimestral' THEN valor / 3
--            WHEN 'semestral'  THEN valor / 6
--            WHEN 'anual'      THEN valor / 12
--        END AS valor_mensual
-- FROM costos_indirectos
-- ORDER BY categoria;
-- ============================================================
