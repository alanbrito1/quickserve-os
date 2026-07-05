-- ============================================================
-- Migración 042 — Método de cobro para ventas fiadas
-- ============================================================
-- Agrega ventas.metodo_cobro: el método con que se COBRÓ una venta fiada
-- (efectivo/nequi/daviplata/bancolombia), independiente de metodo_pago que
-- permanece en 'fiado' para preservar el origen de la venta.
--
--   - NULL  = no aplica (venta no fiada) o fiado aún sin cobrar.
--   - Se llena al editar una venta Fiado y registrar la "Fecha de cobro".
--
-- No reemplaza al saldo_fiado del cliente ni a los abonos (pagos_fiado):
-- es un snapshot informativo a nivel de la venta, alineado con el KPI
-- "Sin cobrar" de ventas/historial.php (que se calcula por fecha_pago).
--
-- Leído/escrito por ventas/api/editar_venta.php y mostrado en
-- ventas/historial.php. Retrocompatible: DEFAULT NULL para filas anteriores.
-- ============================================================


ALTER TABLE `ventas`
    ADD COLUMN `metodo_cobro` ENUM('efectivo','nequi','daviplata','bancolombia') DEFAULT NULL
    COMMENT 'Metodo con que se cobro una venta fiada; NULL si no aplica o aun sin cobrar'
    AFTER `fecha_pago`;
