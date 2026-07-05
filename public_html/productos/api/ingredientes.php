<?php
/**
 * public_html/productos/api/ingredientes.php
 * Endpoint JSON: retorna los ingredientes de un producto para el detalle expandible.
 */

require_once __DIR__ . '/../../app/middleware/auth_check.php';
require_once __DIR__ . '/../../app/models/RecetaModel.php';

header('Content-Type: application/json; charset=utf-8');

permiso_requerir('productos', 'solo_ver');

$producto_id = (int)($_GET['producto_id'] ?? 0);
if (!$producto_id) {
    echo json_encode([]);
    exit;
}

try {
    echo json_encode(RecetaModel::ingredientes_de($producto_id));
} catch (Throwable $e) {
    error_log('[QuickServe OS Ingredientes] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([]);
}
