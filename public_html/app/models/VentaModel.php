<?php
/**
 * app/models/VentaModel.php
 * Lógica de negocio del módulo de ventas.
 *
 * ATOMICIDAD: crear() y anular() usan transacciones PDO.
 * Si cualquier paso falla (stock insuficiente, FK inválida, error de DB)
 * se hace ROLLBACK completo: la venta no queda a medias.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/AuditoriaHelper.php';

class VentaModel
{
    // -----------------------------------------------------------------------
    // CREAR VENTA
    // -----------------------------------------------------------------------

    /**
     * Registra una venta completa: cabecera + líneas + descuento de stock + fiado.
     *
     * @param array    $carrito     [['producto_id'=>int, 'cantidad'=>int, 'precio'=>float], ...]
     * @param string   $metodo_pago 'efectivo'|'nequi'|'daviplata'|'bancolombia'|'fiado'
     * @param int|null $cliente_id  Obligatorio cuando metodo_pago === 'fiado'
     * @param string   $notas       Instrucciones adicionales (opcional)
     * @return int     ID de la venta creada
     * @throws RuntimeException si stock insuficiente o datos inválidos
     */
    /**
     * @param array       $carrito      [['producto_id','cantidad','precio'], ...]
     * @param string      $metodo_pago  efectivo|nequi|daviplata|bancolombia|fiado
     * @param int|null    $cliente_id   Requerido si metodo_pago === 'fiado'
     * @param string      $notas
     * @param string|null $fecha_pago   NULL = pendiente. ISO date si ya se pagó.
     * @param bool        $es_combo     Si incluye bebida/papas
     * @param string      $tipo_sandwich Nombre del tipo de sándwich (denormalizado)
     */
    public static function crear(
        array   $carrito,
        string  $metodo_pago,
        ?int    $cliente_id,
        string  $notas          = '',
        ?string $fecha_pago     = null,
        bool    $es_combo       = false,
        string  $tipo_sandwich  = ''
    ): int {
        if (empty($carrito)) {
            throw new RuntimeException('El carrito no puede estar vacío.');
        }
        if ($metodo_pago === 'fiado' && !$cliente_id) {
            throw new RuntimeException('Para ventas a fiado debes seleccionar un cliente.');
        }

        $pdo = db();
        $uid = (int)($_SESSION['usuario_id'] ?? 0);

        $total = array_reduce($carrito, static function (float $suma, array $item): float {
            return $suma + ((float)$item['precio'] * (int)$item['cantidad']);
        }, 0.0);

        $pdo->beginTransaction();
        try {
            // ---- 1. Cabecera de venta ----
            $pdo->prepare(
                "INSERT INTO ventas
                    (cliente_id, metodo_pago, total, notas, estado,
                     fecha_pago, es_combo, tipo_sandwich, created_by)
                 VALUES
                    (:cid, :metodo, :total, :notas, 'completada',
                     :fpago, :combo, :tipo, :uid)"
            )->execute([
                ':cid'    => $cliente_id,
                ':metodo' => $metodo_pago,
                ':total'  => $total,
                ':notas'  => $notas ?: null,
                ':fpago'  => $fecha_pago,
                ':combo'  => $es_combo ? 1 : 0,
                ':tipo'   => $tipo_sandwich ?: null,
                ':uid'    => $uid,
            ]);
            $venta_id = (int)$pdo->lastInsertId();

            // ---- 2. Líneas de detalle + descuento de insumos ----
            $stmtDetalle = $pdo->prepare(
                'INSERT INTO venta_detalles
                    (venta_id, producto_id, cantidad, precio_unitario, subtotal, created_by)
                 VALUES (:vid, :pid, :cant, :precio, :sub, :uid)'
            );
            $stmtReceta = $pdo->prepare(
                'SELECT insumo_id, cantidad_requerida
                 FROM recetas WHERE producto_id = :pid'
            );
            $stmtDescuento = $pdo->prepare(
                'UPDATE insumos
                 SET stock_actual = stock_actual - :desc, updated_by = :uid
                 WHERE id = :id AND stock_actual >= :desc2'
            );

            foreach ($carrito as $item) {
                $pid      = (int)$item['producto_id'];
                $cantidad = (int)$item['cantidad'];
                $precio   = (float)$item['precio'];

                // Insertar línea (precio congelado = histórico, no cambia con futuros ajustes)
                $stmtDetalle->execute([
                    ':vid'    => $venta_id,
                    ':pid'    => $pid,
                    ':cant'   => $cantidad,
                    ':precio' => $precio,
                    ':sub'    => $precio * $cantidad,
                    ':uid'    => $uid,
                ]);

                // Descontar cada ingrediente de la receta
                $stmtReceta->execute([':pid' => $pid]);
                foreach ($stmtReceta->fetchAll() as $ing) {
                    $descuento = (float)$ing['cantidad_requerida'] * $cantidad;

                    // La condición AND stock_actual >= descuento previene stock negativo.
                    // rowCount() = 0 significa que no hubo suficiente stock.
                    $stmtDescuento->execute([
                        ':desc'  => $descuento,
                        ':uid'   => $uid,
                        ':id'    => (int)$ing['insumo_id'],
                        ':desc2' => $descuento,
                    ]);

                    if ($stmtDescuento->rowCount() === 0) {
                        // Obtener nombre del insumo para dar un mensaje útil
                        $nom = $pdo->prepare('SELECT nombre FROM insumos WHERE id = ?');
                        $nom->execute([(int)$ing['insumo_id']]);
                        $nombre_ins = $nom->fetchColumn() ?: 'insumo #' . $ing['insumo_id'];

                        throw new RuntimeException(
                            "Stock insuficiente de \"{$nombre_ins}\". Actualiza el inventario."
                        );
                    }
                }
            }

            // ---- 3. Fiado: acumular deuda en el saldo del cliente ----
            if ($metodo_pago === 'fiado' && $cliente_id) {
                $pdo->prepare(
                    'UPDATE clientes
                     SET saldo_fiado = saldo_fiado + :total, updated_by = :uid
                     WHERE id = :id'
                )->execute([':total' => $total, ':uid' => $uid, ':id' => $cliente_id]);
            }

            $pdo->commit();

            // Auditoría de la creación (el trigger MySQL no cubre INSERTs)
            log_registrar('ventas', $venta_id, 'estado', null, 'completada', 'INSERT');

            return $venta_id;

        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e; // propagar para que el controlador devuelva el error al cliente
        }
    }

    // -----------------------------------------------------------------------
    // ANULAR VENTA (requiere permiso admin_total en módulo ventas)
    // -----------------------------------------------------------------------

    /**
     * Anula una venta: cambia estado a 'anulada' y revierte el stock de insumos.
     * Si era fiado, también reduce el saldo del cliente.
     *
     * @throws RuntimeException si la venta no existe o ya está anulada
     */
    public static function anular(int $venta_id): void
    {
        $pdo = db();
        $uid = (int)($_SESSION['usuario_id'] ?? 0);

        // Verificar existencia y estado actual
        $v = $pdo->prepare(
            'SELECT id, estado, metodo_pago, total, cliente_id FROM ventas WHERE id = ?'
        );
        $v->execute([$venta_id]);
        $venta = $v->fetch();

        if (!$venta)                        throw new RuntimeException('Venta no encontrada.');
        if ($venta['estado'] === 'anulada') throw new RuntimeException('Esta venta ya fue anulada.');

        $pdo->beginTransaction();
        try {
            // Cambiar estado
            $pdo->prepare("UPDATE ventas SET estado = 'anulada', updated_by = ? WHERE id = ?")
                ->execute([$uid, $venta_id]);

            // Revertir stock: obtener líneas con sus ingredientes y SUMAR de vuelta
            $lineas = $pdo->prepare(
                'SELECT vd.cantidad, r.insumo_id, r.cantidad_requerida
                 FROM venta_detalles vd
                 JOIN recetas r ON r.producto_id = vd.producto_id
                 WHERE vd.venta_id = ?'
            );
            $lineas->execute([$venta_id]);

            foreach ($lineas->fetchAll() as $row) {
                $devolucion = (float)$row['cantidad_requerida'] * (int)$row['cantidad'];
                $pdo->prepare(
                    'UPDATE insumos SET stock_actual = stock_actual + ?, updated_by = ? WHERE id = ?'
                )->execute([$devolucion, $uid, (int)$row['insumo_id']]);
            }

            // Si era fiado, reducir saldo del cliente (GREATEST(0,...) evita saldo negativo)
            if ($venta['metodo_pago'] === 'fiado' && $venta['cliente_id']) {
                $pdo->prepare(
                    'UPDATE clientes SET saldo_fiado = GREATEST(0, saldo_fiado - ?), updated_by = ? WHERE id = ?'
                )->execute([(float)$venta['total'], $uid, (int)$venta['cliente_id']]);
            }

            $pdo->commit();
            log_registrar('ventas', $venta_id, 'estado', 'completada', 'anulada', 'UPDATE');

        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // -----------------------------------------------------------------------
    // CONSULTAS
    // -----------------------------------------------------------------------

    /**
     * Retorna el historial de ventas filtrado por rango de fechas y opcionalmente por usuario.
     * El parámetro $solo_usuario_id activa el filtro de "solo_propios".
     */
    public static function historial(
        string $fecha_desde = '',
        string $fecha_hasta = '',
        ?int   $solo_usuario_id = null
    ): array {
        $desde = $fecha_desde ?: date('Y-m-d');
        $hasta = $fecha_hasta ?: date('Y-m-d');

        $sql = "SELECT v.id, v.fecha_venta, v.metodo_pago, v.total, v.estado, v.notas,
                       IFNULL(c.nombre, 'Mostrador') AS cliente,
                       u.nombre AS cajero,
                       COUNT(vd.id) AS num_items
                FROM ventas v
                LEFT JOIN clientes c  ON c.id  = v.cliente_id
                LEFT JOIN usuarios u  ON u.id  = v.created_by
                LEFT JOIN venta_detalles vd ON vd.venta_id = v.id
                WHERE DATE(v.fecha_venta) BETWEEN :desde AND :hasta";

        $params = [':desde' => $desde, ':hasta' => $hasta];

        if ($solo_usuario_id) {
            $sql .= ' AND v.created_by = :uid';
            $params[':uid'] = $solo_usuario_id;
        }

        $sql .= ' GROUP BY v.id ORDER BY v.fecha_venta DESC';

        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Retorna cabecera + líneas de una venta específica.
     */
    public static function detalle(int $venta_id): array
    {
        $pdo = db();

        $cab = $pdo->prepare(
            "SELECT v.*, IFNULL(c.nombre, 'Mostrador') AS cliente_nombre, u.nombre AS cajero
             FROM ventas v
             LEFT JOIN clientes c ON c.id = v.cliente_id
             LEFT JOIN usuarios u ON u.id = v.created_by
             WHERE v.id = ?"
        );
        $cab->execute([$venta_id]);
        $venta = $cab->fetch();
        if (!$venta) return [];

        $lin = $pdo->prepare(
            'SELECT vd.cantidad, vd.precio_unitario, vd.subtotal,
                    p.nombre AS producto, p.tamano
             FROM venta_detalles vd
             JOIN productos p ON p.id = vd.producto_id
             WHERE vd.venta_id = ?'
        );
        $lin->execute([$venta_id]);
        $venta['lineas'] = $lin->fetchAll();

        return $venta;
    }

    /**
     * Retorna productos activos con su capacidad de producción calculada en tiempo real.
     * Capacidad = FLOOR(stock_insumo_crítico / cantidad_por_unidad).
     * Si no hay insumo crítico definido en la receta, capacidad = 9999 (sin límite).
     */
    public static function productos_con_capacidad(): array
    {
        return db()->query(
            "SELECT p.id, p.nombre, p.tamano, p.categoria, p.precio_venta,
                    CASE
                        WHEN r.id IS NULL THEN 9999
                        ELSE IFNULL(FLOOR(i.stock_actual / NULLIF(r.cantidad_requerida, 0)), 0)
                    END AS capacidad
             FROM productos p
             LEFT JOIN recetas r ON r.producto_id = p.id AND r.es_insumo_critico = 1
             LEFT JOIN insumos  i ON i.id = r.insumo_id
             WHERE p.activo = 1 AND p.precio_venta > 0
             ORDER BY p.categoria, p.nombre, FIELD(p.tamano, 'XL', 'L', 'unico')"
        )->fetchAll();
    }

    /**
     * Resumen de ventas de hoy para el widget del dashboard.
     */
    public static function resumen_hoy(): array
    {
        $row = db()->query(
            "SELECT COUNT(*) AS total_ventas,
                    IFNULL(SUM(total), 0) AS total_pesos,
                    IFNULL(SUM(CASE WHEN metodo_pago = 'efectivo'    THEN total ELSE 0 END), 0) AS efectivo,
                    IFNULL(SUM(CASE WHEN metodo_pago = 'nequi'       THEN total ELSE 0 END), 0) AS nequi,
                    IFNULL(SUM(CASE WHEN metodo_pago = 'daviplata'   THEN total ELSE 0 END), 0) AS daviplata,
                    IFNULL(SUM(CASE WHEN metodo_pago = 'bancolombia' THEN total ELSE 0 END), 0) AS bancolombia,
                    IFNULL(SUM(CASE WHEN metodo_pago = 'fiado'       THEN total ELSE 0 END), 0) AS fiado
             FROM ventas
             WHERE DATE(fecha_venta) = CURDATE() AND estado = 'completada'"
        )->fetch();
        return $row;
    }
}
