<?php
/**
 * inventario/api/presentaciones.php
 * CRUD de presentaciones de compra por insumo (migración 039).
 *
 * GET  ?insumo_id=X        → lista presentaciones activas del insumo
 * POST accion=crear        → INSERT nueva presentación
 * POST accion=editar       → UPDATE presentación existente
 * POST accion=eliminar     → soft-delete (activo=0)
 */

require_once __DIR__ . '/../../app/middleware/auth_check.php';
require_once __DIR__ . '/../../app/models/PresentacionModel.php';

header('Content-Type: application/json; charset=utf-8');

permiso_requerir('inventario', 'editar_existentes');

try {
    // ── GET: listar presentaciones de un insumo ──────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $insumo_id = (int)($_GET['insumo_id'] ?? 0);
        if ($insumo_id <= 0) {
            echo json_encode(['success' => false, 'error' => 'ID de insumo inválido.']);
            exit;
        }
        $pres = PresentacionModel::de_insumo($insumo_id);
        echo json_encode(['success' => true, 'presentaciones' => $pres]);
        exit;
    }

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

    $accion = $_POST['accion'] ?? '';

    switch ($accion) {

        // ── CREAR ────────────────────────────────────────────────────────────
        case 'crear':
            $insumo_id = (int)($_POST['insumo_id'] ?? 0);
            if ($insumo_id <= 0)
                throw new \RuntimeException('Insumo inválido.');

            $nombre = trim($_POST['nombre'] ?? '');
            if (empty($nombre))
                throw new \RuntimeException('El nombre de la presentación es obligatorio.');

            $cantidad_base = (float)str_replace(',', '.', $_POST['cantidad_base'] ?? '0');
            if ($cantidad_base <= 0)
                throw new \RuntimeException('La cantidad base debe ser mayor a cero.');

            $id = PresentacionModel::crear($insumo_id, $_POST);
            if (!empty($_POST['es_predeterminada'])) {
                PresentacionModel::sincronizarLegacy($insumo_id);
            }
            echo json_encode([
                'success'  => true,
                'id'       => $id,
                'mensaje'  => 'Presentación creada.',
            ]);
            break;

        // ── EDITAR ───────────────────────────────────────────────────────────
        case 'editar':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new \RuntimeException('ID inválido.');

            $nombre = trim($_POST['nombre'] ?? '');
            if (empty($nombre))
                throw new \RuntimeException('El nombre es obligatorio.');

            $cantidad_base = (float)str_replace(',', '.', $_POST['cantidad_base'] ?? '0');
            if ($cantidad_base <= 0)
                throw new \RuntimeException('La cantidad base debe ser mayor a cero.');

            PresentacionModel::editar($id, $_POST);
            if (!empty($_POST['es_predeterminada'])) {
                $insumo_id = (int)($_POST['insumo_id'] ?? 0);
                if ($insumo_id > 0) PresentacionModel::sincronizarLegacy($insumo_id);
            }
            echo json_encode(['success' => true, 'mensaje' => 'Presentación actualizada.']);
            break;

        // ── ELIMINAR ─────────────────────────────────────────────────────────
        case 'eliminar':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new \RuntimeException('ID inválido.');

            PresentacionModel::eliminar($id);
            echo json_encode(['success' => true, 'mensaje' => 'Presentación eliminada.']);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Acción inválida.']);
    }

} catch (\RuntimeException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\Exception $e) {
    error_log('[QuickServe OS Presentaciones] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Error interno del servidor.']);
}
