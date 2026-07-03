<?php
/**
 * productos/api/registrar_lote.php — API de producción de producto terminado.
 *
 * Acciones POST:
 *   crear  → deducir insumos + sumar al stock de producto terminado + insertar lote
 *   anular → revertir insumos + reducir stock + marcar lote como anulado
 */
require_once __DIR__ . '/../../app/middleware/auth_check.php';
require_once __DIR__ . '/../../app/helpers/AuditoriaHelper.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido.']);
    exit;
}

permiso_requerir('productos', 'editar_existentes');

if (!csrf_verificar()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Token CSRF inválido.']);
    exit;
}

$accion = $_POST['accion'] ?? '';
$uid    = (int)($_SESSION['usuario_id'] ?? 0);
$pdo    = db();

try {
    switch ($accion) {

        // ── CREAR TANDA DE PRODUCCIÓN ─────────────────────────────────────────
        case 'crear':
            $producto_id = (int)($_POST['producto_id'] ?? 0);
            $cantidad    = (int)($_POST['cantidad']    ?? 0);
            $fecha       = trim($_POST['fecha']        ?? date('Y-m-d'));
            $notas       = trim($_POST['notas']        ?? '');

            if ($producto_id <= 0)
                throw new RuntimeException('Selecciona un producto.');
            if ($cantidad <= 0)
                throw new RuntimeException('La cantidad debe ser mayor a cero.');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha))
                throw new RuntimeException('Fecha inválida.');

            // Obtener producto y receta
            $prod = $pdo->prepare(
                'SELECT nombre, costo_calculado, unidades_por_receta FROM productos WHERE id = ? AND activo = 1'
            );
            $prod->execute([$producto_id]);
            $p = $prod->fetch();
            if (!$p) throw new RuntimeException('Producto no encontrado.');

            $rinde          = max(1, (int)$p['unidades_por_receta']);
            $costo_unitario = (float)($p['costo_calculado'] ?? 0);

            // Obtener ingredientes de la receta
            $receta = $pdo->prepare(
                'SELECT r.insumo_id, r.cantidad_requerida, i.nombre AS insumo_nombre, i.stock_actual
                 FROM recetas r
                 JOIN insumos i ON i.id = r.insumo_id
                 WHERE r.producto_id = ?'
            );
            $receta->execute([$producto_id]);
            $ingredientes = $receta->fetchAll();

            $pdo->beginTransaction();

            // Descontar cada insumo (cantidad_requerida / unidades_por_receta × cantidad_producida)
            $stmtDesc = $pdo->prepare(
                'UPDATE insumos
                 SET stock_actual = stock_actual - ?, updated_by = ?
                 WHERE id = ? AND stock_actual >= ?'
            );
            foreach ($ingredientes as $ing) {
                $descuento = ((float)$ing['cantidad_requerida'] / $rinde) * $cantidad;
                $stmtDesc->execute([$descuento, $uid, (int)$ing['insumo_id'], $descuento]);
                if ($stmtDesc->rowCount() === 0) {
                    throw new RuntimeException(
                        "Stock insuficiente de \"{$ing['insumo_nombre']}\" para producir {$cantidad} unidades."
                    );
                }
            }

            // Sumar al stock de producto terminado
            $pdo->prepare(
                'UPDATE productos SET stock_disponible = stock_disponible + ?, updated_by = ? WHERE id = ?'
            )->execute([$cantidad, $uid, $producto_id]);

            // Detectar migración 034 (nombre_snap en produccion_lotes)
            static $tiene034l = null;
            if ($tiene034l === null) {
                try {
                    $tiene034l = (int)$pdo->query(
                        "SELECT COUNT(*) FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='produccion_lotes'
                           AND COLUMN_NAME='nombre_snap'"
                    )->fetchColumn() > 0;
                } catch (\Exception $e2) { $tiene034l = false; }
            }

            // Registrar la tanda — incluye nombre_snap si migración 034 está aplicada
            if ($tiene034l) {
                $pdo->prepare(
                    'INSERT INTO produccion_lotes
                        (producto_id, fecha_produccion, cantidad, costo_unitario, notas,
                         nombre_snap, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    $producto_id, $fecha, $cantidad,
                    $costo_unitario > 0 ? $costo_unitario : null,
                    $notas ?: null,
                    $p['nombre'],  // snapshot del nombre al momento de producir
                    $uid,
                ]);
            } else {
                $pdo->prepare(
                    'INSERT INTO produccion_lotes
                        (producto_id, fecha_produccion, cantidad, costo_unitario, notas, created_by)
                     VALUES (?, ?, ?, ?, ?, ?)'
                )->execute([
                    $producto_id, $fecha, $cantidad,
                    $costo_unitario > 0 ? $costo_unitario : null,
                    $notas ?: null, $uid,
                ]);
            }
            $lote_id = (int)$pdo->lastInsertId();

            $pdo->commit();

            log_registrar('produccion_lotes', $lote_id, 'cantidad', null, (string)$cantidad, 'INSERT');

            // Contabilidad (Fase 4b): asiento de producción (prod. terminado vs insumos), aislado.
            try {
                require_once __DIR__ . '/../../app/models/ContabilidadModel.php';
                ContabilidadModel::postear_produccion($lote_id);
            } catch (\Throwable $e) { error_log('[ClanDestino contab produccion] ' . $e->getMessage()); }

            echo json_encode([
                'success'  => true,
                'lote_id'  => $lote_id,
                'mensaje'  => "Producción registrada: {$cantidad} {$p['nombre']}",
            ]);
            break;

        // ── ANULAR TANDA ─────────────────────────────────────────────────────
        case 'anular':
            $lote_id = (int)($_POST['lote_id'] ?? 0);
            if ($lote_id <= 0) throw new RuntimeException('ID de lote inválido.');

            $lote = $pdo->prepare(
                'SELECT pl.*, p.unidades_por_receta, p.nombre AS producto_nombre
                 FROM produccion_lotes pl
                 JOIN productos p ON p.id = pl.producto_id
                 WHERE pl.id = ?'
            );
            $lote->execute([$lote_id]);
            $l = $lote->fetch();

            if (!$l)                          throw new RuntimeException('Lote no encontrado.');
            if ($l['estado'] === 'anulado')   throw new RuntimeException('Este lote ya fue anulado.');

            $rinde    = max(1, (int)$l['unidades_por_receta']);
            $cantidad = (int)$l['cantidad'];

            // Obtener ingredientes para restaurar
            $receta = $pdo->prepare(
                'SELECT insumo_id, cantidad_requerida FROM recetas WHERE producto_id = ?'
            );
            $receta->execute([$l['producto_id']]);
            $ingredientes = $receta->fetchAll();

            $pdo->beginTransaction();

            // Restaurar cada insumo
            foreach ($ingredientes as $ing) {
                $devolucion = ((float)$ing['cantidad_requerida'] / $rinde) * $cantidad;
                $pdo->prepare(
                    'UPDATE insumos SET stock_actual = stock_actual + ?, updated_by = ? WHERE id = ?'
                )->execute([$devolucion, $uid, (int)$ing['insumo_id']]);
            }

            // Reducir stock de producto terminado (no lleva a negativo)
            $pdo->prepare(
                'UPDATE productos
                 SET stock_disponible = GREATEST(0, stock_disponible - ?), updated_by = ?
                 WHERE id = ?'
            )->execute([$cantidad, $uid, $l['producto_id']]);

            // Marcar lote como anulado
            $pdo->prepare(
                'UPDATE produccion_lotes SET estado = \'anulado\', updated_by = ? WHERE id = ?'
            )->execute([$uid, $lote_id]);

            $pdo->commit();

            log_registrar('produccion_lotes', $lote_id, 'estado', 'activo', 'anulado', 'UPDATE');

            echo json_encode([
                'success' => true,
                'mensaje' => "Lote #{$lote_id} de {$l['producto_nombre']} anulado.",
            ]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Acción inválida.']);
    }

} catch (RuntimeException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[ClanDestino Producción] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Error interno del servidor.']);
}
