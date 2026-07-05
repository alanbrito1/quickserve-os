<?php
/**
 * public_html/inventario/api/ajustar_stock.php
 * Endpoint JSON: ajuste manual de stock (mermas, correcciones).
 */

require_once __DIR__ . '/../../app/middleware/auth_check.php';
require_once __DIR__ . '/../../app/models/InsumoModel.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido.']);
    exit;
}

permiso_requerir('inventario', 'editar_existentes');

if (!csrf_verificar()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Token de seguridad inválido.']);
    exit;
}

$insumo_id = (int)($_POST['insumo_id'] ?? 0);
$delta     = (float)($_POST['delta']   ?? 0);
$motivo    = trim($_POST['motivo']     ?? 'ajuste_manual');

if (!$insumo_id || $delta == 0) {
    echo json_encode(['success' => false, 'error' => 'Datos inválidos.']);
    exit;
}

try {
    InsumoModel::ajustar($insumo_id, $delta, $motivo);
    echo json_encode(['success' => true]);
} catch (RuntimeException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    error_log('[QuickServe OS Inv] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Error interno al ajustar el stock.']);
}
