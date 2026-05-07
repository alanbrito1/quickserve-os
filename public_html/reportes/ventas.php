<?php
/**
 * public_html/reportes/ventas.php
 * Reporte de Ventas + Rentabilidad por Producto.
 * Exporta a .xlsx con dos hojas: "Ventas" y "Rentabilidad".
 */

require_once __DIR__ . '/../app/middleware/auth_check.php';
require_once __DIR__ . '/../app/models/VentaModel.php';
require_once __DIR__ . '/../app/models/RecetaModel.php';
require_once __DIR__ . '/../app/helpers/XlsxWriter.php';

permiso_requerir('reportes', 'solo_ver');

// Filtros
$desde = $_GET['desde'] ?? date('Y-m-d', strtotime('-30 days'));
$hasta = $_GET['hasta'] ?? date('Y-m-d');

// Filtro solo_propios
$solo_uid = permiso_es_solo_propios('ventas') ? (int)$usuario_activo['id'] : null;
$ventas   = VentaModel::historial($desde, $hasta, $solo_uid);

// Costo fijo unitario
$cfg = db()->query(
    "SELECT clave, valor FROM configuracion_negocio
     WHERE clave IN ('costos_fijos_mensuales','produccion_estimada_mensual')"
)->fetchAll(PDO::FETCH_KEY_PAIR);
$costo_fijo_u = (float)$cfg['costos_fijos_mensuales'] / max(1, (float)$cfg['produccion_estimada_mensual']);

$rentabilidad = RecetaModel::productos_con_margen($costo_fijo_u);

// ── EXPORTAR EXCEL ──────────────────────────────────────────────────────────
if (isset($_GET['export'])) {
    $w = new XlsxWriter();

    // ── Hoja 1: Ventas ──────────────────────────────────────────────────────
    $w->setSheet('Ventas');
    $w->addRow(['ClanDestino ERP — Reporte de Ventas'], true);
    $w->addRow(["Período: $desde  al  $hasta | Generado: " . date('d/m/Y H:i')]);
    $w->addEmptyRow();
    $w->addRow(['#', 'Fecha', 'Hora', 'Cliente', 'Items', 'Método Pago', 'Total', 'Estado', 'Cajero'], true);

    $total_pesos = 0;
    foreach ($ventas as $v) {
        if ($v['estado'] === 'anulada') continue;
        $w->addRow([
            $v['id'],
            date('d/m/Y', strtotime($v['fecha_venta'])),
            date('H:i',   strtotime($v['fecha_venta'])),
            $v['cliente'],
            (int)$v['num_items'],
            $v['metodo_pago'],
            (float)$v['total'],
            $v['estado'],
            $v['cajero'] ?? '',
        ]);
        $total_pesos += (float)$v['total'];
    }
    $w->addEmptyRow();
    $w->addRow(['', '', '', '', '', 'TOTAL', $total_pesos, '', ''], false, true);

    // Resumen por método de pago
    $w->addEmptyRow();
    $w->addRow(['Resumen por Método de Pago'], true);
    $w->addRow(['Método', 'Cantidad', 'Total'], true);
    $por_metodo = [];
    foreach ($ventas as $v) {
        if ($v['estado'] === 'anulada') continue;
        $m = $v['metodo_pago'];
        $por_metodo[$m]['count'] = ($por_metodo[$m]['count'] ?? 0) + 1;
        $por_metodo[$m]['total'] = ($por_metodo[$m]['total'] ?? 0) + (float)$v['total'];
    }
    foreach ($por_metodo as $metodo => $datos) {
        $w->addRow([$metodo, $datos['count'], $datos['total']]);
    }

    // ── Hoja 2: Rentabilidad ────────────────────────────────────────────────
    $w->setSheet('Rentabilidad');
    $w->addRow(['ClanDestino ERP — Rentabilidad por Producto'], true);
    $w->addRow(["Costo fijo/u: $" . number_format($costo_fijo_u, 2) . "  |  Generado: " . date('d/m/Y H:i')]);
    $w->addEmptyRow();
    $w->addRow(['Producto', 'Tamaño', 'Precio Venta', 'Costo Ing.', 'Costo Fijo', 'Costo Total', 'Margen $', 'Margen %'], true);
    foreach ($rentabilidad as $p) {
        $w->addRow([
            $p['nombre'],
            $p['tamano'],
            (float)$p['precio_venta'],
            (float)$p['costo_ing'],
            (float)$p['costo_fijo_u'],
            (float)$p['costo_total_u'],
            (float)$p['margen_bruto'],
            (float)$p['margen_pct'],
        ]);
    }

    $w->download('ClanDestino_Ventas_' . $desde . '_' . $hasta . '.xlsx');
}

// ── RESÚMENES para la vista HTML ────────────────────────────────────────────
$stats = ['total' => 0, 'pesos' => 0, 'efectivo' => 0, 'digital' => 0, 'fiado' => 0, 'anuladas' => 0];
foreach ($ventas as $v) {
    if ($v['estado'] === 'anulada') { $stats['anuladas']++; continue; }
    $stats['total']++;
    $stats['pesos'] += (float)$v['total'];
    if ($v['metodo_pago'] === 'efectivo') $stats['efectivo'] += (float)$v['total'];
    elseif ($v['metodo_pago'] === 'fiado') $stats['fiado']   += (float)$v['total'];
    else $stats['digital'] += (float)$v['total'];
}
$metodo_label = ['efectivo'=>'Efectivo','nequi'=>'Nequi','daviplata'=>'Daviplata','bancolombia'=>'Bancolombia','fiado'=>'Fiado'];
$estado_c     = ['completada'=>'b-ok','anulada'=>'b-ano','pendiente_pago'=>'b-pend'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reporte Ventas — <?= APP_NAME ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root { --brand:#e94f37; --dark:#111827; --g2:#374151; --g5:#6b7280; --g8:#d1d5db; --g9:#f3f4f6; --white:#fff; --green:#059669; --yellow:#d97706; }
        body { font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; background:var(--g9); min-height:100vh; color:var(--dark); }
        .hdr { background:var(--dark); color:var(--white); height:54px; padding:0 14px; display:flex; align-items:center; justify-content:space-between; position:sticky; top:0; z-index:50; box-shadow:0 2px 8px rgba(0,0,0,.3); }
        .brand { font-size:17px; font-weight:800; } .brand span{color:var(--brand);}
        .nav { display:flex; gap:6px; }
        .nl { color:var(--g8); text-decoration:none; font-size:13px; padding:5px 10px; border-radius:8px; }
        .nl:hover { background:var(--g2); color:var(--white); }
        .main { padding:16px 14px; max-width:1000px; margin:0 auto; }
        .card { background:var(--white); border-radius:14px; box-shadow:0 1px 4px rgba(0,0,0,.06); overflow:hidden; margin-bottom:16px; }
        .card-title { font-size:15px; font-weight:800; padding:14px 18px; border-bottom:1px solid var(--g9); }
        .filter-row { background:var(--white); border-radius:14px; padding:14px 18px; display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; margin-bottom:16px; box-shadow:0 1px 4px rgba(0,0,0,.06); }
        .fg { display:flex; flex-direction:column; gap:4px; }
        .fg label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--g5); }
        .fg input { padding:9px 12px; border:2px solid var(--g8); border-radius:10px; font-size:14px; outline:none; }
        .fg input:focus { border-color:var(--brand); }
        .btn-ver { padding:9px 16px; background:var(--brand); color:var(--white); border:none; border-radius:10px; font-size:13px; font-weight:700; cursor:pointer; }
        .btn-xl  { padding:9px 16px; background:#16a34a; color:var(--white); border:none; border-radius:10px; font-size:13px; font-weight:700; cursor:pointer; text-decoration:none; }
        .stats { display:grid; grid-template-columns:repeat(5,1fr); gap:10px; margin-bottom:16px; }
        @media(max-width:640px){ .stats { grid-template-columns:1fr 1fr; } }
        .stat { background:var(--white); border-radius:14px; padding:12px 14px; box-shadow:0 1px 4px rgba(0,0,0,.06); }
        .stat-n { font-size:18px; font-weight:800; }
        .stat-l { font-size:11px; color:var(--g5); text-transform:uppercase; letter-spacing:.4px; }
        table { width:100%; border-collapse:collapse; }
        th { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--g5); padding:10px 14px; background:var(--g9); border-bottom:1px solid var(--g8); text-align:left; }
        th.r, td.r { text-align:right; }
        td { padding:10px 14px; border-bottom:1px solid var(--g9); font-size:13px; }
        tr:last-child td { border-bottom:none; }
        @media(max-width:600px){ .hide-m { display:none; } }
        .badge { font-size:10px; font-weight:700; padding:2px 7px; border-radius:20px; }
        .b-ok  { background:#d1fae5; color:#065f46; }
        .b-ano { background:#fee2e2; color:#991b1b; }
        .b-pend{ background:#fef3c7; color:#92400e; }
        /* Margen en tabla rentabilidad */
        .m-ok  { color:var(--green); font-weight:800; }
        .m-mid { color:var(--yellow); font-weight:800; }
        .m-bad { color:var(--brand); font-weight:800; }
    </style>
</head>
<body>
<?php $nav_activo = 'reportes'; include __DIR__ . '/../app/views/nav.php'; ?>

<!-- header reemplazado por nav.php -->
<main class="main">

    <!-- Filtros -->
    <form class="filter-row" method="GET">
        <div class="fg"><label>Desde</label><input type="date" name="desde" value="<?= htmlspecialchars($desde) ?>"></div>
        <div class="fg"><label>Hasta</label><input type="date" name="hasta" value="<?= htmlspecialchars($hasta) ?>"></div>
        <button type="submit" class="btn-ver">Filtrar</button>
        <a href="?desde=<?= urlencode($desde) ?>&hasta=<?= urlencode($hasta) ?>&export=1" class="btn-xl">
            ⬇ Exportar Excel
        </a>
    </form>

    <!-- Stats -->
    <div class="stats">
        <div class="stat"><div class="stat-n"><?= $stats['total'] ?></div><div class="stat-l">Ventas</div></div>
        <div class="stat"><div class="stat-n" style="color:var(--brand)">$<?= number_format($stats['pesos'],0,',','.') ?></div><div class="stat-l">Total</div></div>
        <div class="stat"><div class="stat-n">$<?= number_format($stats['efectivo'],0,',','.') ?></div><div class="stat-l">Efectivo</div></div>
        <div class="stat"><div class="stat-n">$<?= number_format($stats['digital'],0,',','.') ?></div><div class="stat-l">Digital</div></div>
        <div class="stat"><div class="stat-n" style="color:var(--yellow)">$<?= number_format($stats['fiado'],0,',','.') ?></div><div class="stat-l">Fiado</div></div>
    </div>

    <!-- Tabla de ventas -->
    <div class="card">
        <div class="card-title">Detalle de Ventas — <?= htmlspecialchars($desde) ?> al <?= htmlspecialchars($hasta) ?></div>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Fecha</th>
                    <th class="hide-m">Cliente</th>
                    <th class="hide-m">Items</th>
                    <th class="hide-m">Método</th>
                    <th class="r">Total</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($ventas as $v): ?>
                <tr <?= $v['estado'] === 'anulada' ? 'style="opacity:.5"' : '' ?>>
                    <td><?= $v['id'] ?></td>
                    <td><?= date('d/m H:i', strtotime($v['fecha_venta'])) ?></td>
                    <td class="hide-m"><?= htmlspecialchars($v['cliente']) ?></td>
                    <td class="hide-m"><?= $v['num_items'] ?></td>
                    <td class="hide-m"><?= $metodo_label[$v['metodo_pago']] ?? $v['metodo_pago'] ?></td>
                    <td class="r"><strong>$<?= number_format($v['total'],0,',','.') ?></strong></td>
                    <td><span class="badge <?= $estado_c[$v['estado']] ?? 'b-ok' ?>"><?= $v['estado'] ?></span></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($ventas)): ?>
                <tr><td colspan="7" style="text-align:center; padding:30px; color:var(--g5)">Sin ventas en este período</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Rentabilidad por producto -->
    <div class="card">
        <div class="card-title">Rentabilidad por Producto <small style="font-weight:400; color:var(--g5)">(costo fijo $<?= number_format($costo_fijo_u,1,',','.') ?>/u)</small></div>
        <table>
            <thead>
                <tr>
                    <th>Producto</th>
                    <th class="r hide-m">Precio</th>
                    <th class="r hide-m">Costo Total</th>
                    <th class="r">Margen</th>
                    <th class="r">%</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rentabilidad as $p):
                    $mc = (float)$p['margen_pct'] >= 40 ? 'm-ok' : ((float)$p['margen_pct'] >= 20 ? 'm-mid' : 'm-bad');
                    if ((float)$p['precio_venta'] <= 0) continue;
                ?>
                <tr>
                    <td><?= htmlspecialchars($p['nombre']) ?> <small style="color:var(--g5)"><?= $p['tamano'] ?></small></td>
                    <td class="r hide-m">$<?= number_format($p['precio_venta'],0,',','.') ?></td>
                    <td class="r hide-m">$<?= number_format($p['costo_total_u'],0,',','.') ?></td>
                    <td class="r"><strong>$<?= number_format($p['margen_bruto'],0,',','.') ?></strong></td>
                    <td class="r"><span class="<?= $mc ?>"><?= $p['margen_pct'] ?>%</span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

</main>
</body>
</html>
