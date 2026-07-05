<?php
/**
 * clientes/api/fusionar.php — Fusionar dos clientes en uno.
 *
 * POST principal_id  → ID del cliente que PERMANECE activo
 *      secundario_id → ID del cliente que se ABSORBE y desactiva
 *
 * Qué hace (todo en una transacción PDO atómica):
 *   1. Verifica que ambos IDs sean distintos y existan
 *   2. Reasigna todas las ventas del secundario al principal
 *   3. Reasigna todos los pagos_fiado del secundario al principal
 *   4. Suma el saldo_fiado del secundario al principal
 *   5. Desactiva el cliente secundario (activo = 0, saldo = 0)
 *   6. Registra el evento en logs_historial
 *
 * Si cualquier paso falla → ROLLBACK completo, no queda nada a medias.
 *
 * Requiere:
 *   - Método POST
 *   - Token CSRF válido
 *   - Permiso 'ventas' nivel 'editar_existentes'
 *
 * Retorna: {"success":true,"ventas":N,"pagos":N,"saldo":X.X,...}
 *       o: {"success":false,"error":"..."}
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

// Verificar permiso: fusionar es una operación de escritura destructiva
permiso_requerir('ventas', 'editar_existentes');

// Verificar token CSRF
if (!csrf_verificar()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Token CSRF inválido.']);
    exit;
}

$principal_id  = (int)($_POST['principal_id']  ?? 0);
$secundario_id = (int)($_POST['secundario_id'] ?? 0);

// Validar que ambos IDs sean positivos y distintos antes de llegar al modelo
if (!$principal_id || !$secundario_id) {
    echo json_encode(['success' => false, 'error' => 'Selecciona ambos clientes para fusionar.']);
    exit;
}
if ($principal_id === $secundario_id) {
    echo json_encode(['success' => false, 'error' => 'No se puede fusionar un cliente consigo mismo.']);
    exit;
}

try {
    $resultado = ClienteModel::fusionar($principal_id, $secundario_id);

    echo json_encode([
        'success'    => true,
        'ventas'     => $resultado['ventas'],
        'pagos'      => $resultado['pagos'],
        'saldo'      => $resultado['saldo'],
        'principal'  => $resultado['principal'],
        'secundario' => $resultado['secundario'],
        'mensaje'    => "Fusión completada: {$resultado['ventas']} venta(s) y "
                      . "$" . fmt_moneda($resultado['saldo']) . " de saldo transferidos.",
    ]);

} catch (RuntimeException $e) {
    // Errores de negocio: cliente no encontrado, inactivo, mismo ID
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    // Errores inesperados: loguear internamente, no exponer detalles
    error_log('[QuickServe OS Clientes Fusionar] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Error interno al fusionar clientes.']);
}
