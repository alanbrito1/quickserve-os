<?php
/**
 * public_html/nomina/api/generar.php
 * Endpoint JSON: genera la nómina del período indicado para todos los empleados activos.
 * Retorna {success, generados:[], omitidos:[], errores:[]}
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

$mes    = (int)($_POST['mes']    ?? 0);
$anio   = (int)($_POST['anio']   ?? 0);
$accion = $_POST['accion'] ?? '';

// Whitelist explícita de acciones permitidas — nunca usar else implícito
$acciones_ok = ['generar', 'eliminar_periodo'];
if (!in_array($accion, $acciones_ok, true)) {
    echo json_encode(['success' => false, 'error' => 'Acción inválida.']);
    exit;
}

if ($mes < 1 || $mes > 12 || $anio < 2020 || $anio > 2100) {
    echo json_encode(['success' => false, 'error' => 'Período inválido.']);
    exit;
}

try {
    if ($accion === 'eliminar_periodo') {
        // Eliminar todas las liquidaciones del período (requiere admin_total)
        permiso_requerir('nomina', 'admin_total');
        $eliminados = NominaModel::eliminar_periodo($mes, $anio);
        echo json_encode(['success' => true, 'eliminados' => $eliminados, 'accion_realizada' => 'eliminar']);
    } elseif ($accion === 'generar') {
        // Generar nómina para todos los empleados activos del período
        $resultado = NominaModel::generar_periodo($mes, $anio);
        echo json_encode(['success' => true, 'accion_realizada' => 'generar'] + $resultado);
    }
} catch (RuntimeException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    error_log('[QuickServe OS Nomina] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Error interno.']);
}
