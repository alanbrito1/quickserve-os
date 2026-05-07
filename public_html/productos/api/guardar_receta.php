<?php
/**
 * public_html/productos/api/guardar_receta.php
 * Endpoint JSON: crear, actualizar o eliminar un ingrediente de una receta.
 *
 * POST accion=guardar  → insertar o actualizar recetas(producto_id, insumo_id)
 * POST accion=eliminar → eliminar la línea de receta
 */

require_once __DIR__ . '/../../app/middleware/auth_check.php';

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

$accion     = $_POST['accion']     ?? 'guardar';
$producto_id = (int)($_POST['producto_id'] ?? 0);
$insumo_id   = (int)($_POST['insumo_id']   ?? 0);

if (!$producto_id || !$insumo_id) {
    echo json_encode(['success' => false, 'error' => 'producto_id e insumo_id son requeridos.']);
    exit;
}

$uid = (int)($_SESSION['usuario_id'] ?? 0);

try {
    if ($accion === 'eliminar') {
        // Eliminar línea de receta
        db()->prepare('DELETE FROM recetas WHERE producto_id = ? AND insumo_id = ?')
            ->execute([$producto_id, $insumo_id]);

        // Recalcular costo del producto
        db()->prepare(
            "UPDATE productos p
             SET p.costo_calculado = (
                 SELECT IFNULL(SUM(i.costo_actual * r.cantidad_requerida), 0)
                 FROM recetas r JOIN insumos i ON i.id = r.insumo_id
                 WHERE r.producto_id = p.id
             ), p.updated_by = ?
             WHERE p.id = ?"
        )->execute([$uid, $producto_id]);

        echo json_encode(['success' => true]);

    } else {
        // Insertar o actualizar cantidad_requerida
        $cantidad  = (float)($_POST['cantidad']   ?? 0);
        $es_critico = (int)($_POST['es_critico']   ?? 0);

        if ($cantidad <= 0) {
            echo json_encode(['success' => false, 'error' => 'La cantidad debe ser mayor a 0.']);
            exit;
        }

        // Si marca este como crítico, desmarcar los otros críticos del mismo producto
        if ($es_critico) {
            db()->prepare(
                'UPDATE recetas SET es_insumo_critico = 0 WHERE producto_id = ?'
            )->execute([$producto_id]);
        }

        db()->prepare(
            'INSERT INTO recetas (producto_id, insumo_id, cantidad_requerida, es_insumo_critico, created_by)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                cantidad_requerida  = VALUES(cantidad_requerida),
                es_insumo_critico   = VALUES(es_insumo_critico),
                updated_by          = VALUES(created_by)'
        )->execute([$producto_id, $insumo_id, $cantidad, $es_critico, $uid]);

        // Recalcular costo del producto inmediatamente
        db()->prepare(
            "UPDATE productos p
             SET p.costo_calculado = (
                 SELECT IFNULL(SUM(i.costo_actual * r.cantidad_requerida), 0)
                 FROM recetas r JOIN insumos i ON i.id = r.insumo_id
                 WHERE r.producto_id = p.id
             ), p.updated_by = ?
             WHERE p.id = ?"
        )->execute([$uid, $producto_id]);

        echo json_encode(['success' => true]);
    }

} catch (Exception $e) {
    error_log('[ClanDestino Productos] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Error interno al guardar la receta.']);
}
