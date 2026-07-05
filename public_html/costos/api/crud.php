<?php
/**
 * costos/api/crud.php — API CRUD del módulo Costos Indirectos.
 * Acciones POST: crear, editar, toggle
 */
require_once __DIR__ . '/../../app/middleware/auth_check.php';
require_once __DIR__ . '/../../app/models/CostoIndirectoModel.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido.']);
    exit;
}

permiso_requerir('costos', 'editar_existentes');

if (!csrf_verificar()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Token CSRF inválido.']);
    exit;
}

$accion = $_POST['accion'] ?? '';

try {
    switch ($accion) {
        case 'crear':
            $id = CostoIndirectoModel::crear($_POST);
            echo json_encode(['success' => true, 'id' => $id]);
            break;

        case 'editar':
            $id = (int) ($_POST['id'] ?? 0);
            if (!$id) throw new RuntimeException('ID inválido.');
            CostoIndirectoModel::actualizar($id, $_POST);
            echo json_encode(['success' => true]);
            break;

        case 'toggle':
            $id = (int) ($_POST['id'] ?? 0);
            if (!$id) throw new RuntimeException('ID inválido.');
            $nuevoEstado = CostoIndirectoModel::toggle($id);
            echo json_encode(['success' => true, 'activo' => $nuevoEstado]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Acción inválida.']);
    }
} catch (RuntimeException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    error_log('[QuickServe OS Costos] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Error interno del servidor.']);
}
