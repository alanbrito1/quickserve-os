<?php
/**
 * app/models/InsumoModel.php
 * Gestión de insumos: stock, costos, alertas y ajustes manuales.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/AuditoriaHelper.php';

class InsumoModel
{
    /**
     * Retorna todos los insumos activos, ordenados por prioridad de alerta (agotado primero).
     * Incluye: estado calculado, proveedor y porcentaje de stock vs seguridad.
     */
    public static function todos_con_estado(): array
    {
        return db()->query(
            "SELECT i.*,
                    p.nombre AS proveedor_nombre,
                    CASE
                        WHEN i.stock_actual = 0                          THEN 'agotado'
                        WHEN i.stock_actual <= i.stock_seguridad          THEN 'bajo'
                        ELSE 'ok'
                    END AS estado,
                    ROUND(
                        i.stock_actual / GREATEST(i.stock_seguridad, 0.001) * 100
                    ) AS pct_stock
             FROM insumos i
             LEFT JOIN proveedores p ON p.id = i.proveedor_id
             WHERE i.activo = 1
             ORDER BY
                 CASE
                     WHEN i.stock_actual = 0                     THEN 0
                     WHEN i.stock_actual <= i.stock_seguridad     THEN 1
                     ELSE 2
                 END,
                 i.nombre"
        )->fetchAll();
    }

    /**
     * Retorna insumos básicos para dropdowns y cálculos.
     * Incluye campos de presentación (mig. 010) y equivalencia (mig. 030)
     * para el panel informativo del formulario de compras.
     */
    public static function todos(): array
    {
        return db()->query(
            'SELECT id, nombre, unidad_medida, costo_actual, stock_actual,
                    presentacion, cantidad_presentacion, precio_presentacion,
                    equiv_cantidad, equiv_unidad
             FROM insumos WHERE activo = 1 ORDER BY nombre'
        )->fetchAll();
    }

    /**
     * Retorna insumos bajo su nivel de seguridad — base de la Lista de Compras Inteligente.
     * La cantidad sugerida cubre hasta el doble del stock de seguridad (buffer de ~2 semanas).
     */
    public static function lista_compras(): array
    {
        return db()->query(
            "SELECT i.*,
                    p.nombre AS proveedor_nombre,
                    (i.stock_seguridad - i.stock_actual)             AS deficit,
                    (i.stock_seguridad * 2 - i.stock_actual)         AS cantidad_sugerida,
                    ((i.stock_seguridad * 2 - i.stock_actual) * i.costo_actual) AS costo_estimado
             FROM insumos i
             LEFT JOIN proveedores p ON p.id = i.proveedor_id
             WHERE i.activo = 1 AND i.stock_actual <= i.stock_seguridad
             ORDER BY p.nombre, i.nombre"
        )->fetchAll();
    }

    /**
     * Ajusta el stock de un insumo de forma manual (merma, corrección de inventario).
     * delta positivo = entrada; delta negativo = salida / merma.
     *
     * @param float  $delta   Cantidad a sumar o restar (puede ser negativa)
     * @param string $motivo  Razón del ajuste para el log de auditoría
     * @throws RuntimeException si el ajuste dejaría el stock en negativo
     */
    public static function ajustar(int $id, float $delta, string $motivo = 'ajuste_manual'): void
    {
        $pdo = db();
        $uid = (int)($_SESSION['usuario_id'] ?? 0);

        // Obtener stock actual
        $row = $pdo->prepare('SELECT stock_actual, nombre FROM insumos WHERE id = ? AND activo = 1');
        $row->execute([$id]);
        $ins = $row->fetch();
        if (!$ins) throw new RuntimeException('Insumo no encontrado.');

        $nuevo_stock = (float)$ins['stock_actual'] + $delta;
        if ($nuevo_stock < 0) {
            throw new RuntimeException(
                "El ajuste dejaría el stock de \"{$ins['nombre']}\" en $nuevo_stock. "
                . "Stock actual: {$ins['stock_actual']}."
            );
        }

        $pdo->prepare('UPDATE insumos SET stock_actual = ?, updated_by = ? WHERE id = ?')
            ->execute([$nuevo_stock, $uid, $id]);

        // Registrar en auditoría con el motivo (el trigger DB también registra el cambio)
        log_registrar(
            'insumos', $id,
            "stock_actual [{$motivo}]",
            (string)$ins['stock_actual'],
            (string)$nuevo_stock,
            'UPDATE'
        );
    }
}
