<?php
/**
 * admin/api/set_meta_ventas.php
 * Guarda meta_ventas_diaria en configuracion_negocio.
 * Solo admin / superadmin. POST + CSRF.
 */

require_once __DIR__ . '/../../app/middleware/auth_check.php';
require_once __DIR__ . '/../../app/helpers/AuditoriaHelper.php';

$rol = $_SESSION['usuario_rol'] ?? '';
if (!in_array($rol, ['admin', 'superadmin'], true)) {
    http_response_code(403);
    header('Location: ' . APP_BASE . '/dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Location: ' . APP_BASE . '/dashboard.php');
    exit;
}

if (!csrf_verificar()) {
    http_response_code(403);
    header('Location: ' . APP_BASE . '/dashboard.php');
    exit;
}

$meta = max(0.0, (float)($_POST['meta'] ?? 0));

try {
    $anterior = db()->query(
        "SELECT valor FROM configuracion_negocio WHERE clave = 'meta_ventas_diaria' LIMIT 1"
    )->fetchColumn();

    db()->prepare(
        "INSERT INTO configuracion_negocio (clave, valor)
         VALUES ('meta_ventas_diaria', ?)
         ON DUPLICATE KEY UPDATE valor = ?"
    )->execute([$meta, $meta]);

    log_registrar('configuracion_negocio', 0, 'meta_ventas_diaria',
        $anterior !== false ? (string)$anterior : null,
        (string)$meta, 'UPDATE');

} catch (\Exception $e) {
    error_log('[QuickServe OS] Error al guardar meta_ventas_diaria: ' . $e->getMessage());
}

header('Location: ' . APP_BASE . '/dashboard.php');
exit;
