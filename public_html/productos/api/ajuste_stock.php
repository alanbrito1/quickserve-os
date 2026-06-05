<?php
/**
 * productos/api/ajuste_stock.php
 * POST: Registra un ajuste de stock de producto terminado (obsequio o desecho).
 *       Descuenta stock_disponible y guarda el registro en ajustes_stock.
 *
 * Retorna: {"success":true} | {"success":false,"error":"..."}
 */

require_once __DIR__ . '/../../app/middleware/auth_check.php';
require_once __DIR__ . '/../../app/helpers/AuditoriaHelper.php';

header('Content-Type: application/json; charset=utf-8');
permiso_requerir('productos', 'editar_existentes');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido.']);
    exit;
}

if (!csrf_verificar()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Token CSRF inválido.']);
    exit;
}

$producto_id = (int)($_POST['producto_id'] ?? 0);
$cantidad    = (int)($_POST['cantidad']    ?? 0);
$tipo        = $_POST['tipo'] ?? '';
$motivo      = substr(trim($_POST['motivo'] ?? ''), 0, 300);

if (!$producto_id) {
    echo json_encode(['success' => false, 'error' => 'Producto inválido.']);
    exit;
}
if ($cantidad < 1) {
    echo json_encode(['success' => false, 'error' => 'La cantidad debe ser al menos 1.']);
    exit;
}
if (!in_array($tipo, ['obsequio', 'desecho'], true)) {
    echo json_encode(['success' => false, 'error' => 'Tipo de ajuste inválido.']);
    exit;
}

$uid = (int)($_SESSION['usuario_id'] ?? 0);
$pdo = db();

try {
    $pdo->beginTransaction();

    // Verificar que el producto existe y tiene suficiente stock
    $prod = $pdo->prepare('SELECT nombre, stock_disponible FROM productos WHERE id = ? FOR UPDATE');
    $prod->execute([$producto_id]);
    $p = $prod->fetch();

    if (!$p) {
        throw new RuntimeException('Producto no encontrado.');
    }
    if ((int)$p['stock_disponible'] < $cantidad) {
        throw new RuntimeException(
            'Stock insuficiente. Solo hay ' . (int)$p['stock_disponible'] . ' unidades disponibles.'
        );
    }

    // Descontar del stock de producto terminado
    $pdo->prepare(
        'UPDATE productos SET stock_disponible = stock_disponible - ?, updated_by = ? WHERE id = ?'
    )->execute([$cantidad, $uid, $producto_id]);

    // Registrar el ajuste
    $pdo->prepare(
        'INSERT INTO ajustes_stock (producto_id, cantidad, tipo, motivo, created_by)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([$producto_id, $cantidad, $tipo, $motivo ?: null, $uid]);

    $pdo->commit();

    // $tipo es ya 'obsequio' o 'desecho' (validado arriba); se usa directamente como campo de auditoría
    log_registrar('productos', $producto_id, $tipo, null,
        "cantidad={$cantidad},producto={$p['nombre']}", 'UPDATE');

    // nuevo_stock = stock antes del descuento − cantidad (el UPDATE ya se aplicó en BD)
    echo json_encode(['success' => true, 'nuevo_stock' => (int)$p['stock_disponible'] - $cantidad]);

} catch (RuntimeException $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    $pdo->rollBack();
    error_log('[ClanDestino AjusteStock] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Error interno del servidor.']);
}
