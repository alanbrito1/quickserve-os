<?php
/**
 * clientes/api/crud.php — CRUD de clientes.
 *
 * POST accion=crear  → INSERT nuevo cliente (nombre, apellido, empresa, telefono)
 * POST accion=editar → UPDATE datos de un cliente existente
 * POST accion=toggle → Activar/desactivar cliente (soft-delete)
 *                      No permite desactivar si tiene deuda pendiente.
 *
 * Todos los endpoints:
 *   - Requieren método POST
 *   - Requieren token CSRF válido
 *   - Requieren permiso 'ventas' nivel 'editar_existentes' (crear/editar)
 *     o 'admin_total' (toggle)
 *
 * Retorna siempre JSON: {"success":true,...} o {"success":false,"error":"..."}
 */

require_once __DIR__ . '/../../app/middleware/auth_check.php';
require_once __DIR__ . '/../../app/models/ClienteModel.php';

header('Content-Type: application/json; charset=utf-8');

// Solo acepta POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido.']);
    exit;
}

// Verificar CSRF antes de cualquier operación de escritura
if (!csrf_verificar()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Token CSRF inválido.']);
    exit;
}

$accion = $_POST['accion'] ?? '';

try {
    switch ($accion) {

        // ── CREAR ─────────────────────────────────────────────────────────
        case 'crear':
            permiso_requerir('ventas', 'editar_existentes');

            // Trim + validar que nombre no sea solo espacios en blanco
            $nombre   = trim($_POST['nombre'] ?? '');
            if ($nombre === '') {
                echo json_encode(['success' => false, 'error' => 'El nombre es obligatorio.']);
                exit;
            }
            $nombre   = substr($nombre, 0, 100);
            $apellido = substr(trim($_POST['apellido'] ?? ''), 0, 100) ?: null;
            $empresa  = substr(trim($_POST['empresa']  ?? ''), 0, 150) ?: null;
            $telefono = substr(trim($_POST['telefono'] ?? ''), 0, 20)  ?: null;

            $id = ClienteModel::crear($nombre, $apellido, $empresa, $telefono);
            echo json_encode(['success' => true, 'id' => $id]);
            break;

        // ── EDITAR ────────────────────────────────────────────────────────
        case 'editar':
            permiso_requerir('ventas', 'editar_existentes');

            $id     = (int)($_POST['id'] ?? 0);
            $nombre = trim($_POST['nombre'] ?? '');
            if ($nombre === '') {
                echo json_encode(['success' => false, 'error' => 'El nombre es obligatorio.']);
                exit;
            }
            $nombre   = substr($nombre, 0, 100);
            $apellido = substr(trim($_POST['apellido'] ?? ''), 0, 100) ?: null;
            $empresa  = substr(trim($_POST['empresa']  ?? ''), 0, 150) ?: null;
            $telefono = substr(trim($_POST['telefono'] ?? ''), 0, 20)  ?: null;

            if (!$id) {
                echo json_encode(['success' => false, 'error' => 'ID inválido.']);
                exit;
            }

            ClienteModel::editar($id, $nombre, $apellido, $empresa, $telefono);
            echo json_encode(['success' => true]);
            break;

        // ── TOGGLE ACTIVO ─────────────────────────────────────────────────
        case 'toggle':
            // Desactivar/reactivar requiere admin_total para evitar que
            // usuarios con editar_existentes puedan ocultar clientes
            permiso_requerir('ventas', 'admin_total');

            $id = (int)($_POST['id'] ?? 0);
            if (!$id) {
                echo json_encode(['success' => false, 'error' => 'ID inválido.']);
                exit;
            }

            $activo_nuevo = ClienteModel::toggle($id);
            echo json_encode(['success' => true, 'activo' => $activo_nuevo]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Acción inválida.']);
    }

} catch (RuntimeException $e) {
    // Errores de negocio: nombre vacío, cliente no encontrado, deuda pendiente
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    // Errores inesperados: no exponer detalles internos al cliente
    error_log('[QuickServe OS Clientes CRUD] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Error interno del servidor.']);
}
