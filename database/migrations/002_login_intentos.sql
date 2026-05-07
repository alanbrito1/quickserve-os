-- ============================================================
-- Migración 002: Tabla de Rate Limiting para Login
-- Ejecutar después de schema.sql (001)
-- ============================================================

CREATE TABLE IF NOT EXISTS `login_intentos` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `email`       VARCHAR(150)    NOT NULL,
    `ip_address`  VARCHAR(45)     NOT NULL,
    -- 0 = fallido, 1 = exitoso (para analytics de seguridad)
    `exitoso`     TINYINT(1)      NOT NULL DEFAULT 0,
    `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    -- Índices para las consultas de bloqueo (email+fecha e ip+fecha)
    INDEX `idx_email_fecha` (`email`, `created_at`),
    INDEX `idx_ip_fecha`    (`ip_address`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Rate limiting de login. Limpiar registros > 24h con un cron job.';

-- Evento opcional para auto-limpiar intentos viejos (requiere EVENT scheduler activo)
-- En hosting compartido, desactivar si el scheduler no está disponible.
-- CREATE EVENT IF NOT EXISTS `purgar_login_intentos`
--   ON SCHEDULE EVERY 1 DAY
--   DO DELETE FROM login_intentos WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 DAY);
