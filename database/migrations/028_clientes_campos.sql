-- ============================================================
-- Migracion 028: Campos adicionales en clientes
-- ============================================================
-- Agrega apellido y empresa para identificar mejor a cada cliente.
-- telefono ya existe desde el schema original.
-- Todos los campos son opcionales (NULL) para compatibilidad con
-- clientes existentes que solo tienen nombre.
-- ============================================================

USE clandestinoERP;

ALTER TABLE clientes
    ADD COLUMN apellido VARCHAR(100) NULL DEFAULT NULL AFTER nombre,
    ADD COLUMN empresa  VARCHAR(150) NULL DEFAULT NULL AFTER apellido;
