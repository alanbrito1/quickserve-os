-- Migración 037: turnos de caja
-- Registra la apertura de cada turno con el fondo inicial en efectivo.
-- Permite al cierre (cierre.php) calcular cuánto efectivo se generó vs el fondo.
-- Un día puede tener máximo un turno activo.


CREATE TABLE IF NOT EXISTS `turnos_caja` (
    `id`               INT AUTO_INCREMENT PRIMARY KEY,
    `fecha`            DATE        NOT NULL,
    `fondo_inicial`    DECIMAL(12,2) NOT NULL DEFAULT 0
                       COMMENT 'Efectivo en caja al abrir el turno',
    `notas_apertura`   TEXT         NULL,
    `usuario_apertura` INT          NOT NULL,
    `fecha_apertura`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `estado`           ENUM('abierto','cerrado') NOT NULL DEFAULT 'abierto',
    `fecha_cierre`     DATETIME     NULL,
    `usuario_cierre`   INT          NULL,
    `notas_cierre`     TEXT         NULL,
    INDEX `idx_tc_fecha`   (`fecha`),
    INDEX `idx_tc_estado`  (`estado`, `fecha`)
    -- SIN FK: consistencia con política del proyecto (errno 121 cPanel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
