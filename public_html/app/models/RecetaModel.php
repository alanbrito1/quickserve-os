<?php
/**
 * app/models/RecetaModel.php
 * Gestión de recetas y costeo dinámico de productos.
 *
 * MARGEN DE RENTABILIDAD:
 *   costo_ingredientes = productos.costo_calculado
 *   costo_fijo/u       = costos_fijos_mensuales / produccion_estimada_mensual
 *   costo_total/u      = costo_ingredientes + costo_fijo/u
 *   margen_bruto       = precio_venta - costo_total/u
 *   margen_%           = (margen_bruto / precio_venta) × 100
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/AuditoriaHelper.php';

class RecetaModel
{
    /**
     * Retorna todos los productos activos con su costo calculado y margen de rentabilidad.
     * El costo fijo por unidad se proratea desde configuracion_negocio.
     *
     * @param float $costo_fijo_unitario  Calculado en el controlador (costos_fijos / produccion_estimada)
     */
    public static function productos_con_margen(float $costo_fijo_unitario): array
    {
        $stmt = db()->prepare(
            "SELECT p.id, p.nombre, p.tamano, p.categoria, p.precio_venta,
                    IFNULL(p.costo_calculado, 0) AS costo_ing,
                    :cfu                          AS costo_fijo_u,
                    ROUND(IFNULL(p.costo_calculado, 0) + :cfu2, 2) AS costo_total_u,
                    ROUND(p.precio_venta - IFNULL(p.costo_calculado, 0) - :cfu3, 2) AS margen_bruto,
                    CASE
                        WHEN p.precio_venta > 0
                        THEN ROUND(
                            (p.precio_venta - IFNULL(p.costo_calculado, 0) - :cfu4)
                            / p.precio_venta * 100, 1)
                        ELSE 0
                    END AS margen_pct
             FROM productos p
             WHERE p.activo = 1
             ORDER BY p.categoria, p.nombre, FIELD(p.tamano, 'XL', 'L', 'unico')"
        );
        $stmt->execute([
            ':cfu'  => $costo_fijo_unitario,
            ':cfu2' => $costo_fijo_unitario,
            ':cfu3' => $costo_fijo_unitario,
            ':cfu4' => $costo_fijo_unitario,
        ]);
        return $stmt->fetchAll();
    }

    /**
     * Retorna los ingredientes de un producto específico con su costo de línea.
     */
    public static function ingredientes_de(int $producto_id): array
    {
        $stmt = db()->prepare(
            'SELECT r.cantidad_requerida, r.es_insumo_critico,
                    i.id AS insumo_id, i.nombre, i.unidad_medida, i.costo_actual,
                    ROUND(r.cantidad_requerida * i.costo_actual, 2) AS costo_linea
             FROM recetas r
             JOIN insumos i ON i.id = r.insumo_id
             WHERE r.producto_id = :pid
             ORDER BY r.es_insumo_critico DESC, i.nombre'
        );
        $stmt->execute([':pid' => $producto_id]);
        return $stmt->fetchAll();
    }

    /**
     * Recalcula costo_calculado para TODOS los productos activos.
     * Llamar desde el botón "Recalcular" del panel de Productos.
     * También es llamado por CompraModel para recálculos parciales.
     *
     * @return int Número de productos actualizados
     */
    public static function recalcular_todos(): int
    {
        $uid = (int)($_SESSION['usuario_id'] ?? 0);

        $stmt = db()->prepare(
            "UPDATE productos p
             SET p.costo_calculado = (
                 SELECT IFNULL(SUM(i.costo_actual * r.cantidad_requerida), 0)
                 FROM recetas r
                 JOIN insumos i ON i.id = r.insumo_id
                 WHERE r.producto_id = p.id
             ),
             p.updated_by = :uid
             WHERE p.activo = 1"
        );
        $stmt->execute([':uid' => $uid]);
        return $stmt->rowCount();
    }

    /**
     * Recalcula costo_calculado solo para productos que usan un insumo específico.
     * Más eficiente que recalcular_todos() cuando cambia un único insumo.
     *
     * @return int Número de productos actualizados
     */
    public static function recalcular_por_insumo(int $insumo_id): int
    {
        $uid = (int)($_SESSION['usuario_id'] ?? 0);

        $stmt = db()->prepare(
            "UPDATE productos p
             SET p.costo_calculado = (
                 SELECT IFNULL(SUM(i.costo_actual * r.cantidad_requerida), 0)
                 FROM recetas r
                 JOIN insumos i ON i.id = r.insumo_id
                 WHERE r.producto_id = p.id
             ),
             p.updated_by = :uid
             WHERE p.activo = 1
               AND p.id IN (
                   SELECT DISTINCT r2.producto_id
                   FROM recetas r2 WHERE r2.insumo_id = :iid
               )"
        );
        $stmt->execute([':uid' => $uid, ':iid' => $insumo_id]);
        return $stmt->rowCount();
    }
}
