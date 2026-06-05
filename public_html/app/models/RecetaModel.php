<?php
/**
 * app/models/RecetaModel.php
 * Gestión de recetas y costeo dinámico de productos.
 *
 * COSTO POR SÁNDWICH:
 *   recetas.cantidad_requerida  → cantidad de insumo para toda la tanda
 *   productos.unidades_por_receta → cuántos sándwiches produce esa tanda
 *   costo_calculado = SUM(insumo.costo_actual × cantidad_requerida) ÷ unidades_por_receta
 *
 * MARGEN DE RENTABILIDAD:
 *   costo_total/u = costo_calculado + costos_fijos/u + depreciación/u + nómina/u
 *   margen_%      = (precio_venta − costo_total/u) / precio_venta × 100
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/AuditoriaHelper.php';

class RecetaModel
{
    /**
     * Retorna todos los productos activos con costo, margen y stock disponible.
     */
    public static function productos_con_margen(float $costo_fijo_unitario): array
    {
        $stmt = db()->prepare(
            "SELECT p.id, p.nombre, p.nombre2, p.tamano, p.categoria, p.precio_venta,
                    p.unidades_por_receta, p.stock_disponible, p.stock_minimo,
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
     * Retorna los ingredientes de un producto con costo por tanda y costo por sándwich.
     * costo_linea = costo por sándwich (cantidad_requerida × costo_actual ÷ unidades_por_receta)
     */
    public static function ingredientes_de(int $producto_id): array
    {
        $stmt = db()->prepare(
            'SELECT r.cantidad_requerida, r.es_insumo_critico,
                    i.id AS insumo_id, i.nombre, i.unidad_medida, i.costo_actual,
                    ROUND(r.cantidad_requerida * i.costo_actual, 2) AS costo_batch,
                    ROUND(r.cantidad_requerida * i.costo_actual
                          / GREATEST(p.unidades_por_receta, 1), 4) AS costo_linea,
                    p.unidades_por_receta
             FROM recetas r
             JOIN insumos   i ON i.id = r.insumo_id
             JOIN productos p ON p.id = r.producto_id
             WHERE r.producto_id = :pid
             ORDER BY r.es_insumo_critico DESC, i.nombre'
        );
        $stmt->execute([':pid' => $producto_id]);
        return $stmt->fetchAll();
    }

    /**
     * Recalcula costo_calculado (por sándwich) para TODOS los productos activos.
     * costo_calculado = SUM(insumo.costo × cantidad_requerida) ÷ unidades_por_receta
     */
    public static function recalcular_todos(): int
    {
        $uid = (int)($_SESSION['usuario_id'] ?? 0);

        $stmt = db()->prepare(
            'UPDATE productos p
             SET p.costo_calculado = IFNULL((
                 SELECT SUM(i.costo_actual * r.cantidad_requerida)
                 FROM recetas r
                 JOIN insumos i ON i.id = r.insumo_id
                 WHERE r.producto_id = p.id
             ), 0) / GREATEST(p.unidades_por_receta, 1),
             p.updated_by = :uid
             WHERE p.activo = 1'
        );
        $stmt->execute([':uid' => $uid]);
        return $stmt->rowCount();
    }

    /**
     * Recalcula costo_calculado solo para productos que usan un insumo específico.
     * Más eficiente que recalcular_todos() — llamado por CompraModel tras cada compra.
     */
    public static function recalcular_por_insumo(int $insumo_id): int
    {
        $uid = (int)($_SESSION['usuario_id'] ?? 0);

        $stmt = db()->prepare(
            'UPDATE productos p
             SET p.costo_calculado = IFNULL((
                 SELECT SUM(i.costo_actual * r.cantidad_requerida)
                 FROM recetas r
                 JOIN insumos i ON i.id = r.insumo_id
                 WHERE r.producto_id = p.id
             ), 0) / GREATEST(p.unidades_por_receta, 1),
             p.updated_by = :uid
             WHERE p.activo = 1
               AND p.id IN (
                   SELECT DISTINCT r2.producto_id
                   FROM recetas r2 WHERE r2.insumo_id = :iid
               )'
        );
        $stmt->execute([':uid' => $uid, ':iid' => $insumo_id]);
        return $stmt->rowCount();
    }
}
