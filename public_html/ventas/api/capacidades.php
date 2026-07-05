<?php
/**
 * public_html/ventas/api/capacidades.php
 * Endpoint JSON: retorna la capacidad de producción actual por producto.
 * Llamado via fetch() para refrescar los contadores del POS sin recargar la página.
 * Retorna: [{"id":1,"capacidad":22}, {"id":2,"capacidad":14}, ...]
 */

require_once __DIR__ . '/../../app/middleware/auth_check.php';
require_once __DIR__ . '/../../app/models/VentaModel.php';

header('Content-Type: application/json; charset=utf-8');

permiso_requerir('ventas', 'solo_ver');

try {
    $productos = VentaModel::productos_con_capacidad();

    // Retornar solo id y capacidad (lo que necesita el frontend para actualizar los indicadores)
    $resultado = array_map(static fn(array $p): array => [
        'id'        => (int)$p['id'],
        'capacidad' => (int)$p['capacidad'],
    ], $productos);

    echo json_encode($resultado);
} catch (Throwable $e) {
    error_log('[QuickServe OS Capacidades] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([]);
}
