<?php
/**
 * public_html/ventas/api/procesar_venta.php
 * Endpoint JSON: recibe el carrito y procesa la venta.
 * Llamado via fetch() desde ventas/index.php con FormData.
 * Retorna: {"success":true,"venta_id":123} o {"success":false,"error":"..."}
 */

require_once __DIR__ . '/../../app/middleware/auth_check.php';
require_once __DIR__ . '/../../app/models/VentaModel.php';

header('Content-Type: application/json; charset=utf-8');

// Solo acepta POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido.']);
    exit;
}

// Verificar permiso mínimo para registrar ventas
permiso_requerir('ventas', 'solo_propios');

// Verificar token CSRF antes de procesar datos
if (!csrf_verificar()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Token de seguridad inválido. Recarga la página.']);
    exit;
}

// ---- Validar y sanitizar inputs ----
$metodo_pago = $_POST['metodo_pago'] ?? '';
$metodos_validos = ['efectivo', 'nequi', 'daviplata', 'bancolombia', 'fiado'];
if (!in_array($metodo_pago, $metodos_validos, true)) {
    echo json_encode(['success' => false, 'error' => 'Método de pago inválido.']);
    exit;
}

$cliente_id     = !empty($_POST['cliente_id']) ? (int)$_POST['cliente_id'] : null;
$notas          = trim($_POST['notas']          ?? '');
$fecha_pago     = trim($_POST['fecha_pago']     ?? '') ?: null;
$es_combo       = !empty($_POST['es_combo']);
$tipo_sandwich  = trim($_POST['tipo_sandwich']  ?? '');
$carrito_raw    = $_POST['carrito'] ?? '[]';

// Validar fecha_pago si se proporcionó
if ($fecha_pago && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_pago)) {
    echo json_encode(['success' => false, 'error' => 'Formato de fecha de pago inválido (YYYY-MM-DD).']);
    exit;
}

$carrito = json_decode($carrito_raw, true);
if (!is_array($carrito) || empty($carrito)) {
    echo json_encode(['success' => false, 'error' => 'El carrito está vacío o tiene formato inválido.']);
    exit;
}

foreach ($carrito as $item) {
    if (empty($item['producto_id']) || (int)$item['cantidad'] <= 0 || (float)$item['precio'] <= 0) {
        echo json_encode(['success' => false, 'error' => 'Uno o más ítems del carrito son inválidos.']);
        exit;
    }
}

// ---- Procesar la venta ----
try {
    $venta_id = VentaModel::crear(
        $carrito, $metodo_pago, $cliente_id,
        $notas, $fecha_pago, $es_combo, $tipo_sandwich
    );
    echo json_encode(['success' => true, 'venta_id' => $venta_id]);

} catch (RuntimeException $e) {
    // Errores de negocio: stock insuficiente, cliente no encontrado, etc.
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);

} catch (Exception $e) {
    // Errores inesperados: no exponer detalles internos al cliente
    error_log('[ClanDestino POS] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Error interno al registrar la venta. Intenta de nuevo.']);
}
