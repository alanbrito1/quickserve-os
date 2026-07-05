<?php
/**
 * public_html/inventario/api/conteo_guardar.php
 * Endpoint JSON: guarda conteo rápido de stock (reemplaza stock_actual por valor contado).
 * Recibe: csrf_token, conteos[] = [{insumo_id, stock_contado}]
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

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!isset($data['conteos']) || !is_array($data['conteos']) || empty($data['conteos'])) {
    echo json_encode(['success' => false, 'error' => 'Sin datos de conteo.']);
    exit;
}

$pdo = db();
$uid = (int)($_SESSION['usuario_id'] ?? 0);

$actualizados = 0;
$errores      = [];

try {
    $pdo->beginTransaction();

    $stmt_get = $pdo->prepare('SELECT id, nombre, stock_actual FROM insumos WHERE id = ? AND activo = 1');
    $stmt_set = $pdo->prepare('UPDATE insumos SET stock_actual = ?, updated_by = ? WHERE id = ?');

    foreach ($data['conteos'] as $c) {
        $iid    = (int)($c['insumo_id'] ?? 0);
        $nuevo  = isset($c['stock_contado']) ? (float)$c['stock_contado'] : null;

        if (!$iid || $nuevo === null || $nuevo < 0) {
            $errores[] = "Dato inválido para insumo #$iid";
            continue;
        }

        $stmt_get->execute([$iid]);
        $ins = $stmt_get->fetch();
        if (!$ins) {
            $errores[] = "Insumo #$iid no encontrado";
            continue;
        }

        $anterior = (float)$ins['stock_actual'];
        if (abs($anterior - $nuevo) < 0.0001) {
            continue; // Sin cambio — no registrar log ni UPDATE
        }

        $stmt_set->execute([$nuevo, $uid, $iid]);
        log_registrar('insumos', $iid, 'stock_actual', (string)$anterior, (string)$nuevo, 'conteo_rapido');
        $actualizados++;
    }

    $pdo->commit();

    echo json_encode([
        'success'     => true,
        'actualizados' => $actualizados,
        'errores'     => $errores,
    ]);
} catch (\Exception $e) {
    $pdo->rollBack();
    error_log('[QuickServe OS Conteo] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Error interno al guardar el conteo.']);
}
