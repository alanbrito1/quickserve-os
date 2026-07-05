<?php
/**
 * clientes/exportar.php — Exporta la lista de clientes a Excel (.xlsx).
 *
 * Genera un archivo con todos los clientes ordenados por deuda fiado (mayor primero),
 * incluyendo nombre, empresa, teléfono, estado, saldo fiado, total ventas y última compra.
 *
 * Requiere: sesión activa + ventas:editar_existentes.
 */

require_once __DIR__ . '/../app/middleware/auth_check.php';
require_once __DIR__ . '/../app/helpers/XlsxWriter.php';
require_once __DIR__ . '/../app/models/ClienteModel.php';

permiso_requerir('ventas', 'editar_existentes');

$clientes = ClienteModel::todos_completo();

// Ordenar: primero con deuda (mayor a menor), luego el resto por nombre
usort($clientes, function ($a, $b) {
    $da = (float)$a['saldo_fiado'];
    $db = (float)$b['saldo_fiado'];
    if ($da !== $db) return $db <=> $da;
    return strcmp($a['nombre'], $b['nombre']);
});

$w = new XlsxWriter();
$w->setSheet('Clientes');

$w->addRow(['REPORTE DE CLIENTES — ' . APP_NAME . ' — ' . date('d/m/Y H:i')], header: true);
$w->addRow([]);
$w->addRow([
    '#', 'Nombre', 'Apellido', 'Empresa', 'Teléfono',
    'Estado', 'Deuda Fiado ($)', 'Total Ventas', 'Última Compra',
], header: true);

$i              = 0;
$total_fiado    = 0.0;
$total_ventas_n = 0;
$con_deuda      = 0;

foreach ($clientes as $c) {
    $i++;
    $fiado = (float)$c['saldo_fiado'];
    $tvtas = (int)$c['total_ventas'];
    $total_fiado    += $fiado;
    $total_ventas_n += $tvtas;
    if ($fiado > 0) $con_deuda++;

    $w->addRow([
        $i,
        $c['nombre'],
        $c['apellido']    ?? '',
        $c['empresa']     ?? '',
        $c['telefono']    ?? '',
        (int)$c['activo'] ? 'Activo' : 'Inactivo',
        $fiado,
        $tvtas,
        $c['ultima_compra'] ? date('d/m/Y', strtotime($c['ultima_compra'])) : '',
    ]);
}

$w->addRow([]);
$w->addRow([
    '', 'TOTALES', '', '', '',
    "{$i} clientes · {$con_deuda} con deuda",
    $total_fiado,
    $total_ventas_n,
    '',
], total: true);

$w->download(slug_negocio() . '_Clientes_' . date('Ymd') . '.xlsx');
exit;
