-- ============================================================
-- Migración 033: nomina_liquidaciones — snapshots valor_hora y valor_proyecto
-- ============================================================
-- Al liquidar, el NominaModel calcula valor_hora y usa valor_proyecto
-- del empleado, pero esos valores NO se guardaban en la liquidación.
-- Si el empleado cambia de valor_hora o valor_proyecto después, el
-- historial de nómina no permite saber qué tarifa se usó para liquidar.
--
-- Esta migración agrega:
--   valor_hora_snap    → tarifa/hora usada al liquidar (para por_horas)
--   valor_proyecto_snap→ valor del proyecto al liquidar (para por_servicio)
-- ============================================================

USE clandestinoERP;

ALTER TABLE nomina_liquidaciones
    ADD COLUMN valor_hora_snap     DECIMAL(10,4) DEFAULT NULL
        COMMENT 'Tarifa por hora usada al calcular esta liquidación (snapshot inmutable)',
    ADD COLUMN valor_proyecto_snap DECIMAL(12,2) DEFAULT NULL
        COMMENT 'Valor del proyecto al liquidar (snapshot para por_servicio)';

-- Los registros existentes quedarán con NULL (histórico pre-033).
-- Para por_horas existentes se puede estimar: valor_hora = pago_base / horas_trabajadas
-- Para por_servicio existentes: valor_proyecto_snap = costo_total − aux − cargas − provisiones

-- Verificar:
-- SELECT id, tipo_contrato, valor_hora_snap, valor_proyecto_snap
-- FROM nomina_liquidaciones ORDER BY id DESC LIMIT 5;
