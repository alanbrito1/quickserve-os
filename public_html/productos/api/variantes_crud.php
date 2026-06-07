<?php
/**
 * productos/api/variantes_crud.php — CRUD de variantes de tamaño por producto.
 *
 * Acciones:
 *   GET  ?producto_id=X            → lista variantes del producto
 *   POST accion=guardar            → crear o actualizar variante (upsert por id)
 *   POST accion=eliminar           → soft-delete (activo=0)
 *   POST accion=reactivar          → activo=1
 */
require_once __DIR__ . '/../../app/middleware/auth_check.php';

header('Content-Type: application/json; charset=utf-8');

permiso_requerir('productos', 'solo_ver');

$pdo = db();

try {

// ── GET: lista de variantes de un producto ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $pid = (int)($_GET['producto_id'] ?? 0);
    if (!$pid) { echo json_encode(['variantes' => []]); exit; }

    $rows = $pdo->prepare(
        'SELECT id, etiqueta, precio_venta, factor_receta, activo
         FROM producto_variantes
         WHERE producto_id = ?
         ORDER BY precio_venta ASC'
    );
    $rows->execute([$pid]);
    echo json_encode(['variantes' => $rows->fetchAll()]);
    exit;
}

// ── POST: mutaciones ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido.']);
    exit;
}

csrf_verificar();
$accion = $_POST['accion'] ?? '';

// ── guardar (crear o actualizar) ───────────────────────────────────────────
if ($accion === 'guardar') {
    permiso_requerir('productos', 'editar_existentes');

    $id      = (int)($_POST['id'] ?? 0);
    $pid     = (int)($_POST['producto_id'] ?? 0);
    $etiq    = trim($_POST['etiqueta']     ?? '');
    $precio  = (float)($_POST['precio_venta'] ?? 0);
    $factor  = (float)($_POST['factor_receta'] ?? 1.0);
    $uid     = (int)($_SESSION['usuario_id'] ?? 0);

    if (!$pid)             { echo json_encode(['error' => 'Producto requerido.']); exit; }
    if ($etiq === '')      { echo json_encode(['error' => 'La etiqueta no puede estar vacía.']); exit; }
    if (strlen($etiq) > 80){ echo json_encode(['error' => 'Etiqueta demasiado larga (máx 80 caracteres).']); exit; }
    if ($precio <= 0)      { echo json_encode(['error' => 'El precio debe ser mayor que 0.']); exit; }
    if ($factor <= 0 || $factor > 10) { echo json_encode(['error' => 'Factor de receta debe estar entre 0.001 y 10.']); exit; }

    // Verificar que el producto existe
    $exists = $pdo->prepare('SELECT id FROM productos WHERE id = ? AND activo = 1');
    $exists->execute([$pid]);
    if (!$exists->fetch()) {
        echo json_encode(['error' => 'Producto no encontrado.']); exit;
    }

    // Verificar que no haya otra variante del mismo producto con la misma etiqueta (activa)
    $dupCheck = $pdo->prepare(
        'SELECT id FROM producto_variantes
         WHERE producto_id = ? AND etiqueta = ? AND activo = 1 AND id != ?'
    );
    $dupCheck->execute([$pid, $etiq, $id]);
    if ($dupCheck->fetch()) {
        echo json_encode(['error' => "Ya existe una variante \"{$etiq}\" para este producto."]); exit;
    }

    if ($id > 0) {
        // Actualizar variante existente
        $pdo->prepare(
            'UPDATE producto_variantes
             SET etiqueta=?, precio_venta=?, factor_receta=?
             WHERE id=? AND producto_id=?'
        )->execute([$etiq, $precio, round($factor, 3), $id, $pid]);
        echo json_encode(['success' => true, 'mensaje' => "Variante \"{$etiq}\" actualizada."]);
    } else {
        // Crear variante nueva
        $pdo->prepare(
            'INSERT INTO producto_variantes
                (producto_id, etiqueta, precio_venta, factor_receta, activo, created_by)
             VALUES (?, ?, ?, ?, 1, ?)'
        )->execute([$pid, $etiq, $precio, round($factor, 3), $uid]);
        $newId = (int)$pdo->lastInsertId();
        echo json_encode(['success' => true, 'id' => $newId, 'mensaje' => "Variante \"{$etiq}\" creada."]);
    }
    exit;
}

// ── eliminar (soft-delete) ─────────────────────────────────────────────────
if ($accion === 'eliminar') {
    permiso_requerir('productos', 'editar_existentes');

    $id  = (int)($_POST['id'] ?? 0);
    $pid = (int)($_POST['producto_id'] ?? 0);
    if (!$id || !$pid) { echo json_encode(['error' => 'Parámetros inválidos.']); exit; }

    $pdo->prepare(
        'UPDATE producto_variantes SET activo = 0 WHERE id = ? AND producto_id = ?'
    )->execute([$id, $pid]);

    echo json_encode(['success' => true, 'mensaje' => 'Variante desactivada. Los datos históricos se conservan.']);
    exit;
}

// ── reactivar ──────────────────────────────────────────────────────────────
if ($accion === 'reactivar') {
    permiso_requerir('productos', 'editar_existentes');

    $id  = (int)($_POST['id'] ?? 0);
    $pid = (int)($_POST['producto_id'] ?? 0);
    if (!$id || !$pid) { echo json_encode(['error' => 'Parámetros inválidos.']); exit; }

    $pdo->prepare(
        'UPDATE producto_variantes SET activo = 1 WHERE id = ? AND producto_id = ?'
    )->execute([$id, $pid]);

    echo json_encode(['success' => true, 'mensaje' => 'Variante reactivada.']);
    exit;
}

} catch (Throwable $e) {
    // Errores inesperados: no exponer detalles internos al cliente
    error_log('[ClanDestino Variantes CRUD] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Error interno del servidor.']);
}

echo json_encode(['error' => 'Acción no reconocida.']);
