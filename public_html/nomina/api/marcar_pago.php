<?php
/**
 * public_html/nomina/api/marcar_pago.php
 * Endpoint JSON: marcar liquidación como pagada o pendiente.
 *
 * POST accion=pagado    id=X  fecha=YYYY-MM-DD
 * POST accion=pendiente id=X
 */

require_once __DIR__ . '/../../app/middleware/auth_check.php';
require_once __DIR__ . '/../../app/models/NominaModel.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido.']);
    exit;
}

permiso_requerir('nomina', 'editar_existentes');

if (!csrf_verificar()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Token CSRF inválido.']);
    exit;
}

$accion = $_POST['accion'] ?? '';
$id     = (int)($_POST['id'] ?? 0);

if (!$id) {
    echo json_encode(['success' => false, 'error' => 'ID inválido.']);
    exit;
}

try {
    if ($accion === 'pagado') {
        $fecha = trim($_POST['fecha'] ?? date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            $fecha = date('Y-m-d');
        }
        NominaModel::marcar_pagado($id, $fecha);
        echo json_encode(['success' => true, 'estado' => 'pagado', 'fecha' => $fecha]);

    } elseif ($accion === 'pendiente') {
        NominaModel::marcar_pendiente($id);
        echo json_encode(['success' => true, 'estado' => 'pendiente']);

    } else {
        echo json_encode(['success' => false, 'error' => 'Acción inválida.']);
    }
} catch (Exception $e) {
    error_log('[QuickServe OS Nómina] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Error interno.']);
}
