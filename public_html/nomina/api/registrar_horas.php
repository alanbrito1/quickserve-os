<?php
/**
 * nomina/api/registrar_horas.php
 * Registra o actualiza horas trabajadas con tipo de recargo y flag de festivo.
 *
 * Campos POST:
 *   empleado_id, fecha, horas, tipo_hora, es_festivo, descripcion
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

$empleado_id = (int)($_POST['empleado_id'] ?? 0);
$fecha       = trim($_POST['fecha']        ?? '');
$horas       = (float)str_replace(',', '.', $_POST['horas'] ?? '0');
$descripcion = trim($_POST['descripcion']  ?? '');
$es_festivo  = !empty($_POST['es_festivo']) ? 1 : 0;

// Validar tipo_hora
$tipos_validos = [
    'ordinaria','recargo_nocturno','extra_diurna','extra_nocturna',
    'festiva_ordinaria','extra_festiva_diurna','extra_festiva_nocturna',
];
$tipo_hora = in_array($_POST['tipo_hora'] ?? '', $tipos_validos, true)
    ? $_POST['tipo_hora']
    : 'ordinaria';

if (!$empleado_id || !$fecha) {
    echo json_encode(['success' => false, 'error' => 'empleado_id y fecha son requeridos.']);
    exit;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
    echo json_encode(['success' => false, 'error' => 'Formato de fecha inválido.']);
    exit;
}

try {
    // Registrar con tipo y festivo
    NominaModel::registrar_horas(
        $empleado_id, $fecha, $horas, $descripcion, $tipo_hora, $es_festivo
    );

    // Devolver totales del mes para actualizar la UI
    [$anio, $mes] = explode('-', $fecha);
    $total      = NominaModel::total_horas_periodo($empleado_id, (int)$mes, (int)$anio);

    // Calcular pago estimado del período con desglose
    $stmtEmp = db()->prepare('SELECT salario_base, valor_hora FROM empleados WHERE id = ?');
    $stmtEmp->execute([$empleado_id]);
    $emp = $stmtEmp->fetch();
    $params = NominaModel::params();
    $horas_mes_std = NominaModel::horas_mes_estandar($params);
    $valor_hora_base = !empty($emp['valor_hora'])
        ? (float)$emp['valor_hora']
        : round((float)$emp['salario_base'] / max(1, $horas_mes_std), 4);

    // Desglose del período completo
    $horas_periodo = NominaModel::horas_periodo($empleado_id, (int)$mes, (int)$anio);
    $desglose = [];
    foreach ($horas_periodo as $h) {
        $t = $h['tipo_hora'] ?? 'ordinaria';
        $desglose[$t] = ($desglose[$t] ?? 0) + (float)$h['horas'];
    }
    $calc = NominaModel::calcular_desglose_horas($desglose, $valor_hora_base, $params);

    echo json_encode([
        'success'      => true,
        'total_mes'    => $total,
        'pago_estimado'=> $calc['total_pago'],
        'horas_extras' => $calc['horas_extras'],
    ]);
} catch (\RuntimeException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\Exception $e) {
    error_log('[ClanDestino Horas] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Error interno al registrar horas.']);
}
