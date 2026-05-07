<?php
/**
 * app/models/CompraModel.php
 * Registro de compras de insumos.
 *
 * CADENA DE ACTUALIZACIÓN (dentro de la misma transacción):
 *   compra_detalles.INSERT
 *     → insumos.costo_actual  = precio_unitario_compra
 *     → insumos.stock_actual += cantidad
 *     → productos.costo_calculado = SUM(ingrediente.costo × receta.cantidad)
 *
 * Si cualquier paso falla → ROLLBACK completo.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/AuditoriaHelper.php';

class CompraModel
{
    /**
     * Registra una compra completa y propaga los cambios de precio a los productos.
     *
     * @param array    $lineas        [['insumo_id','cantidad','precio_unitario'], ...]
     * @param int|null $proveedor_id
     * @param string   $notas
     * @param string   $lugar_compra  Tienda, plaza o proveedor (texto libre)
     * @return int     ID de la compra creada
     * @throws RuntimeException si datos inválidos
     */
    public static function crear(
        array   $lineas,
        ?int    $proveedor_id  = null,
        string  $notas         = '',
        string  $lugar_compra  = ''
    ): int {
        if (empty($lineas)) throw new RuntimeException('La compra debe tener al menos un ítem.');

        $pdo = db();
        $uid = (int)($_SESSION['usuario_id'] ?? 0);

        $total = array_reduce($lineas, static function (float $s, array $l): float {
            return $s + ((float)$l['precio_unitario'] * (float)$l['cantidad']);
        }, 0.0);

        $pdo->beginTransaction();
        try {
            // ── 1. Cabecera de compra ────────────────────────────────────────
            $pdo->prepare(
                'INSERT INTO compras (proveedor_id, lugar_compra, total, notas, created_by)
                 VALUES (:pid, :lugar, :total, :notas, :uid)'
            )->execute([
                ':pid'   => $proveedor_id,
                ':lugar' => $lugar_compra ?: null,
                ':total' => $total,
                ':notas' => $notas ?: null,
                ':uid'   => $uid,
            ]);
            $compra_id = (int)$pdo->lastInsertId();

            // ── 2. Líneas: insertar + actualizar insumo ─────────────────────
            $stmtDetalle = $pdo->prepare(
                'INSERT INTO compra_detalles
                    (compra_id, insumo_id, cantidad, precio_unitario, subtotal, created_by)
                 VALUES (:cid, :iid, :cant, :precio, :sub, :uid)'
            );

            // Recálculo de costo de productos afectados por el insumo
            $stmtRecalc = $pdo->prepare(
                "UPDATE productos p
                 SET p.costo_calculado = (
                     SELECT IFNULL(SUM(ins.costo_actual * rec.cantidad_requerida), 0)
                     FROM recetas rec
                     JOIN insumos ins ON ins.id = rec.insumo_id
                     WHERE rec.producto_id = p.id
                 ),
                 p.updated_by = :uid
                 WHERE p.activo = 1
                   AND p.id IN (
                       SELECT DISTINCT r2.producto_id FROM recetas r2 WHERE r2.insumo_id = :iid
                   )"
            );

            $insumosActualizados = [];

            foreach ($lineas as $l) {
                $iid    = (int)$l['insumo_id'];
                $cant   = (float)$l['cantidad'];
                $precio = (float)$l['precio_unitario'];

                $stmtDetalle->execute([
                    ':cid'    => $compra_id,
                    ':iid'    => $iid,
                    ':cant'   => $cant,
                    ':precio' => $precio,
                    ':sub'    => $cant * $precio,
                    ':uid'    => $uid,
                ]);

                // Costo anterior para auditoría
                $prev = $pdo->prepare('SELECT costo_actual FROM insumos WHERE id = ?');
                $prev->execute([$iid]);
                $costo_anterior = (float)($prev->fetchColumn() ?: 0);

                // El precio de esta compra pasa a ser el nuevo costo_actual del insumo
                $pdo->prepare(
                    'UPDATE insumos
                     SET costo_actual = :precio,
                         stock_actual = stock_actual + :cant,
                         updated_by   = :uid
                     WHERE id = :id'
                )->execute([':precio' => $precio, ':cant' => $cant, ':uid' => $uid, ':id' => $iid]);

                if (abs($costo_anterior - $precio) > 0.001) {
                    log_registrar('insumos', $iid, 'costo_actual',
                        (string)$costo_anterior, (string)$precio, 'UPDATE');
                }

                $insumosActualizados[$iid] = true;
            }

            // ── 3. Recalcular costo_calculado en productos afectados ─────────
            foreach (array_keys($insumosActualizados) as $iid) {
                $stmtRecalc->execute([':uid' => $uid, ':iid' => (int)$iid]);
            }

            $pdo->commit();
            log_registrar('compras', $compra_id, 'total', null, (string)$total, 'INSERT');

            return $compra_id;

        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Retorna el historial de compras recientes con conteo de líneas.
     * NOTA: bindValue con PDO::PARAM_INT es obligatorio para LIMIT en modo non-emulated.
     */
    public static function recientes(int $limite = 30): array
    {
        $stmt = db()->prepare(
            "SELECT c.id, c.fecha_compra, c.total, c.notas,
                    IFNULL(c.lugar_compra, '') AS lugar_compra,
                    IFNULL(p.nombre, 'Sin proveedor') AS proveedor,
                    u.nombre AS registrado_por,
                    (SELECT COUNT(*) FROM compra_detalles WHERE compra_id = c.id) AS num_lineas
             FROM compras c
             LEFT JOIN proveedores p ON p.id = c.proveedor_id
             LEFT JOIN usuarios u    ON u.id = c.created_by
             ORDER BY c.fecha_compra DESC
             LIMIT :lim"
        );
        $stmt->bindValue(':lim', (int)$limite, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Compras agrupadas por fecha y lugar, con líneas anidadas.
     * Vista para "Historial de Compras" organizado por fecha.
     */
    public static function historial_agrupado(string $desde = '', string $hasta = ''): array
    {
        $desde = $desde ?: date('Y-m-d', strtotime('-30 days'));
        $hasta = $hasta ?: date('Y-m-d');

        $stmt = db()->prepare(
            "SELECT c.id,
                    DATE(c.fecha_compra)                AS fecha,
                    c.fecha_compra,
                    c.total,
                    c.notas,
                    IFNULL(c.lugar_compra, 'Sin lugar') AS lugar_compra,
                    IFNULL(p.nombre, 'Sin proveedor')   AS proveedor,
                    u.nombre                            AS registrado_por,
                    (SELECT COUNT(*) FROM compra_detalles WHERE compra_id = c.id) AS num_lineas
             FROM compras c
             LEFT JOIN proveedores p ON p.id = c.proveedor_id
             LEFT JOIN usuarios u    ON u.id = c.created_by
             WHERE DATE(c.fecha_compra) BETWEEN :desde AND :hasta
             ORDER BY c.fecha_compra DESC, c.lugar_compra"
        );
        $stmt->execute([':desde' => $desde, ':hasta' => $hasta]);
        $compras = $stmt->fetchAll();

        // Cargar líneas de detalle para cada compra (expandible en la vista)
        $stmtLineas = db()->prepare(
            'SELECT cd.cantidad, cd.precio_unitario, cd.subtotal,
                    i.nombre AS insumo, i.unidad_medida, i.categoria
             FROM compra_detalles cd
             JOIN insumos i ON i.id = cd.insumo_id
             WHERE cd.compra_id = :cid
             ORDER BY i.nombre'
        );

        foreach ($compras as &$c) {
            $stmtLineas->execute([':cid' => $c['id']]);
            $c['lineas'] = $stmtLineas->fetchAll();
        }

        return $compras;
    }

    /** Retorna el detalle completo de una compra (cabecera + líneas). */
    public static function detalle(int $id): array
    {
        $pdo = db();

        $cab = $pdo->prepare(
            "SELECT c.*, IFNULL(p.nombre,'Sin proveedor') AS proveedor
             FROM compras c LEFT JOIN proveedores p ON p.id = c.proveedor_id WHERE c.id = ?"
        );
        $cab->execute([$id]);
        $compra = $cab->fetch();
        if (!$compra) return [];

        $lin = $pdo->prepare(
            'SELECT cd.cantidad, cd.precio_unitario, cd.subtotal, i.nombre AS insumo, i.unidad_medida
             FROM compra_detalles cd JOIN insumos i ON i.id = cd.insumo_id
             WHERE cd.compra_id = ? ORDER BY i.nombre'
        );
        $lin->execute([$id]);
        $compra['lineas'] = $lin->fetchAll();

        return $compra;
    }
}
