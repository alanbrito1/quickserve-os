<?php
/**
 * public_html/productos/api/recalcular.php
 * Endpoint JSON: dispara el recálculo completo de costo_calculado en todos los productos.
 */

require_once __DIR__ . '/../../app/middleware/auth_check.php';
require_once __DIR__ . '/../../app/models/RecetaModel.php';

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

try {
    $n = RecetaModel::recalcular_todos();
    echo json_encode(['success' => true, 'actualizados' => $n]);
} catch (Exception $e) {
    error_log('[QuickServe OS Productos] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Error interno al recalcular costos.']);
}
