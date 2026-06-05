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

// Whitelist de métodos de pago válidos
$metodo_pago     = $_POST['metodo_pago'] ?? '';
$metodos_validos = ['efectivo', 'nequi', 'daviplata', 'bancolombia', 'fiado', 'obsequio'];
if (!in_array($metodo_pago, $metodos_validos, true)) {
    echo json_encode(['success' => false, 'error' => 'Método de pago inválido.']);
    exit;
}

$cliente_id    = !empty($_POST['cliente_id']) ? (int)$_POST['cliente_id'] : null;
$notas         = substr(trim($_POST['notas'] ?? ''), 0, 500); // limitar largo para evitar abuso
$fecha_pago    = trim($_POST['fecha_pago']    ?? '') ?: null;

// Fecha de la venta: acepta YYYY-MM-DD; convierte a YYYY-MM-DD HH:MM:SS con hora actual.
// Se rechaza cualquier fecha futura para evitar registros antedatados hacia adelante.
$fecha_venta_raw = trim($_POST['fecha_venta'] ?? '');
$fecha_venta = null;
if ($fecha_venta_raw && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_venta_raw)) {
    if ($fecha_venta_raw > date('Y-m-d')) {
        echo json_encode(['success' => false, 'error' => 'La fecha de la venta no puede ser futura.']);
        exit;
    }
    $fecha_venta = $fecha_venta_raw . ' ' . date('H:i:s');
}
// es_combo global eliminado: ahora es por ítem dentro del carrito (migración 025)
$tipo_sandwich = substr(trim($_POST['tipo_sandwich'] ?? ''), 0, 100);
$carrito_raw   = $_POST['carrito'] ?? '[]';

// Validar fecha_pago: formato correcto y no en el futuro
if ($fecha_pago) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_pago)) {
        echo json_encode(['success' => false, 'error' => 'Formato de fecha de pago inválido (YYYY-MM-DD).']);
        exit;
    }
}

// Deserializar carrito (enviado como JSON desde el POS)
$carrito = json_decode($carrito_raw, true);
if (!is_array($carrito) || empty($carrito)) {
    echo json_encode(['success' => false, 'error' => 'El carrito está vacío o tiene formato inválido.']);
    exit;
}

foreach ($carrito as $item) {
    $qty      = (int)($item['cantidad']  ?? 0);
    $price    = (float)($item['precio'] ?? 0);
    $es_combo = (int)($item['es_combo'] ?? 0);
    // Validar: producto_id presente, cantidad 1-9999, precio no negativo, es_combo 0 ó 1
    if (empty($item['producto_id']) || $qty <= 0 || $qty > 9999
        || $price < 0 || !in_array($es_combo, [0, 1], true)) {
        echo json_encode(['success' => false, 'error' => 'Uno o más ítems del carrito son inválidos.']);
        exit;
    }
}

// ---- Procesar la venta ----
try {
    // es_combo ya no es parámetro global: viene dentro de cada ítem del carrito
    $venta_id = VentaModel::crear(
        $carrito, $metodo_pago, $cliente_id,
        $notas, $fecha_pago, $tipo_sandwich, $fecha_venta
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
