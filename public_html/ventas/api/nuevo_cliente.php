<?php
/**
 * public_html/ventas/api/nuevo_cliente.php
 * Endpoint JSON: crea un cliente nuevo desde el modal del POS (fiado on-the-fly).
 * Retorna: {"success":true,"id":5,"nombre":"Juan"} o {"success":false,"error":"..."}
 */

require_once __DIR__ . '/../../app/middleware/auth_check.php';
require_once __DIR__ . '/../../app/models/ClienteModel.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido.']);
    exit;
}

permiso_requerir('ventas', 'solo_propios');

if (!csrf_verificar()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Token de seguridad inválido.']);
    exit;
}

$nombre   = trim($_POST['nombre']   ?? '');
$telefono = trim($_POST['telefono'] ?? '');

if (empty($nombre)) {
    echo json_encode(['success' => false, 'error' => 'El nombre del cliente es obligatorio.']);
    exit;
}

try {
    $id = ClienteModel::crear($nombre, $telefono ?: null);
    echo json_encode(['success' => true, 'id' => $id, 'nombre' => $nombre]);
} catch (RuntimeException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
