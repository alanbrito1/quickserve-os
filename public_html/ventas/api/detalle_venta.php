<?php
/**
 * ventas/api/detalle_venta.php — Devuelve los ítems de una venta (JSON).
 * Usado por historial.php para expandir el detalle de cada fila.
 */
require_once __DIR__ . '/../../app/middleware/auth_check.php';

header('Content-Type: application/json; charset=utf-8');
permiso_requerir('ventas', 'solo_ver');

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    echo json_encode(['success' => false, 'error' => 'ID inválido.']);
    exit;
}

// Verificar que la venta existe y el usuario tiene acceso
$stmt = db()->prepare(
    'SELECT v.id, v.notas, v.created_by FROM ventas v WHERE v.id = ?'
);
$stmt->execute([$id]);
$venta = $stmt->fetch();

if (!$venta) {
    echo json_encode(['success' => false, 'error' => 'Venta no encontrada.']);
    exit;
}

// Restricción solo_propios
if (permiso_es_solo_propios('ventas') && (int)$venta['created_by'] !== (int)($_SESSION['usuario_id'] ?? 0)) {
    echo json_encode(['success' => false, 'error' => 'Sin acceso a esta venta.']);
    exit;
}

// Detectar migración 035 (columna variante_etiqueta en venta_detalles)
$tiene035 = false;
try {
    $tiene035 = (int)db()->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='venta_detalles'
           AND COLUMN_NAME='variante_etiqueta'"
    )->fetchColumn() > 0;
} catch (\Exception $e) {}

$colVariante = $tiene035 ? ', vd.variante_etiqueta' : ", NULL AS variante_etiqueta";

// Obtener líneas de detalle con nombre_snap cuando existe (migración 034).
// COALESCE garantiza retrocompatibilidad: si el snapshot no existe usa el nombre actual.
$items = db()->prepare(
    "SELECT COALESCE(vd.nombre_snap,  p.nombre)  AS nombre,
            COALESCE(vd.nombre2_snap, p.nombre2) AS nombre2,
            vd.cantidad, vd.precio_unitario, vd.subtotal,
            vd.from_stock, vd.es_combo{$colVariante}
     FROM venta_detalles vd
     JOIN productos p ON p.id = vd.producto_id
     WHERE vd.venta_id = ?
     ORDER BY nombre"
);
$items->execute([$id]);

echo json_encode([
    'success' => true,
    'items'   => $items->fetchAll(),
    'notas'   => $venta['notas'] ?? '',
]);
