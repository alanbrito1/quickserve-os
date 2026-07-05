<?php
/**
 * inventario/api/compra_crud.php
 * Endpoint JSON: editar, duplicar y eliminar compras.
 * POST {accion, compra_id, ...} → {success, [nuevo_id|error]}
 */

require_once __DIR__ . '/../../app/middleware/auth_check.php';
require_once __DIR__ . '/../../app/models/CompraModel.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido.']);
    exit;
}

if (!csrf_verificar()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Token de seguridad inválido.']);
    exit;
}

$accion     = $_POST['accion']     ?? '';
$compra_id  = (int)($_POST['compra_id'] ?? 0);

if (!in_array($accion, ['editar', 'duplicar', 'eliminar'], true) || $compra_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Parámetros inválidos.']);
    exit;
}

// ── Permisos ─────────────────────────────────────────────────────────────────
if ($accion === 'duplicar') {
    permiso_requerir('compras', 'solo_propios');
} else {
    permiso_requerir('compras', 'editar_existentes');
}

// ── Acciones ──────────────────────────────────────────────────────────────────
try {

    if ($accion === 'eliminar') {
        CompraModel::eliminar($compra_id);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($accion === 'duplicar') {
        $nuevo_id = CompraModel::duplicar($compra_id);
        echo json_encode(['success' => true, 'nuevo_id' => $nuevo_id]);
        exit;
    }

    if ($accion === 'editar') {
        $proveedor_id = !empty($_POST['proveedor_id']) ? (int)$_POST['proveedor_id'] : null;
        $lugar        = substr(trim($_POST['lugar_compra'] ?? ''), 0, 150);
        $notas        = substr(trim($_POST['notas']        ?? ''), 0, 500);
        $lineas_raw   = $_POST['lineas'] ?? [];

        // Extraer líneas incluyendo campos de presentación (032) para snapshot histórico.
        // Aunque el modal de edición no tiene el bloque de presentación por UI,
        // los datos originales que venían del formulario de nueva compra se preservan
        // si el modal incluye los hidden inputs correspondientes.
        $lineas = [];
        foreach ($lineas_raw as $l) {
            $iid    = (int)($l['insumo_id']       ?? 0);
            $cant   = (float)str_replace(',', '.', $l['cantidad']        ?? '0');
            $precio = (float)str_replace(',', '.', $l['precio_unitario'] ?? '0');
            if ($iid > 0 && $cant > 0 && $precio > 0) {
                $linea = ['insumo_id' => $iid, 'cantidad' => $cant, 'precio_unitario' => $precio];
                // Campos de presentación (migración 032) — snapshot del empaque
                $pres   = trim($l['presentacion']           ?? '');
                $cantpx = (float)str_replace(',', '.', $l['cantidad_presentacion'] ?? '0');
                $numpres= (float)str_replace(',', '.', $l['cant_presentaciones']   ?? '0');
                $ppres  = (float)str_replace(',', '.', $l['precio_presentacion']   ?? '0');
                if ($pres !== '' && $cantpx > 0 && $numpres > 0) {
                    $linea['presentacion']          = $pres;
                    $linea['cantidad_presentacion'] = $cantpx;
                    $linea['cant_presentaciones']   = $numpres;
                    $linea['precio_presentacion']   = $ppres > 0 ? $ppres : null;
                }
                $lineas[] = $linea;
            }
        }

        if (empty($lineas)) {
            echo json_encode(['success' => false, 'error' => 'Agrega al menos un ítem válido.']);
            exit;
        }

        CompraModel::editar($compra_id, $lineas, $proveedor_id, $notas, $lugar);
        echo json_encode(['success' => true]);
        exit;
    }

} catch (RuntimeException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    error_log('[QuickServe OS Compra CRUD] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Error interno. Intenta de nuevo.']);
}
