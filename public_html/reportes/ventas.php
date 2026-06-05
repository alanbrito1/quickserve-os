<?php
/**
 * public_html/reportes/ventas.php
 * Reporte de Ventas + Rentabilidad por Producto.
 * Exporta a .xlsx con dos hojas: "Ventas" y "Rentabilidad".
 */

require_once __DIR__ . '/../app/middleware/auth_check.php';
require_once __DIR__ . '/../app/models/VentaModel.php';
require_once __DIR__ . '/../app/models/RecetaModel.php';
require_once __DIR__ . '/../app/models/CostoIndirectoModel.php';
require_once __DIR__ . '/../app/helpers/XlsxWriter.php';

permiso_requerir('reportes', 'solo_ver');

// Filtros de período
$desde = $_GET['desde'] ?? date('Y-m-d', strtotime('-30 days'));
$hasta = $_GET['hasta'] ?? date('Y-m-d');

// Filtro solo_propios
$solo_uid = permiso_es_solo_propios('ventas') ? (int)$usuario_activo['id'] : null;
$ventas   = VentaModel::historial($desde, $hasta, $solo_uid);

// Costo fijo unitario: usa costos_indirectos si hay datos, si no configuracion_negocio
$cfg = db()->query(
    "SELECT clave, valor FROM configuracion_negocio
     WHERE clave IN ('costos_fijos_mensuales','produccion_estimada_mensual')"
)->fetchAll(PDO::FETCH_KEY_PAIR);
$costos_indirectos_total = CostoIndirectoModel::total_mensual_activo();
$costos_fijos_legacy     = (float)($cfg['costos_fijos_mensuales'] ?? 0);
$costos_fijos_mensual    = $costos_indirectos_total > 0 ? $costos_indirectos_total : $costos_fijos_legacy;
$prod_mes                = max(1, (float)($cfg['produccion_estimada_mensual'] ?? 2175));
$costo_fijo_u            = $costos_fijos_mensual / $prod_mes;

$rentabilidad = RecetaModel::productos_con_margen($costo_fijo_u);

// Análisis from_stock vs on-demand
$from_stock_stats = db()->prepare(
    "SELECT
        SUM(CASE WHEN vd.from_stock = 1 THEN vd.subtotal ELSE 0 END) AS total_desde_stock,
        SUM(CASE WHEN vd.from_stock = 0 THEN vd.subtotal ELSE 0 END) AS total_demanda,
        SUM(CASE WHEN vd.from_stock = 1 THEN vd.cantidad ELSE 0 END) AS unid_desde_stock,
        SUM(CASE WHEN vd.from_stock = 0 THEN vd.cantidad ELSE 0 END) AS unid_demanda
     FROM venta_detalles vd
     JOIN ventas v ON v.id = vd.venta_id
     WHERE DATE(v.fecha_venta) BETWEEN :desde AND :hasta
       AND v.estado != 'anulada'"
);
$from_stock_stats->execute([':desde' => $desde, ':hasta' => $hasta]);
$fs_row = $from_stock_stats->fetch();

// Etiquetas de método de pago — definidas aquí para que estén disponibles tanto en
// el bloque Excel como en la vista HTML (el bloque Excel se ejecuta y hace exit primero).
$metodo_label = ['efectivo'=>'Efectivo','nequi'=>'Nequi','daviplata'=>'Daviplata',
                 'bancolombia'=>'Bancolombia','fiado'=>'Fiado','obsequio'=>'Obsequio'];

// ── EXPORTAR EXCEL ──────────────────────────────────────────────────────────
if (isset($_GET['export'])) {
    $w = new XlsxWriter();

    // ── Hoja 1: Ventas ──────────────────────────────────────────────────────
    $w->setSheet('Ventas');
    $w->addRow(['ClanDestino ERP — Reporte de Ventas'], true);
    $w->addRow(["Período: $desde  al  $hasta | Generado: " . date('d/m/Y H:i')]);
    $w->addEmptyRow();
    $w->addRow(['#', 'Fecha', 'Hora', 'Cliente', 'Items', 'Método Pago', 'Total', 'Estado', 'Cajero'], true);

    $total_pesos = 0; // solo ingresos reales (excluye obsequio)
    foreach ($ventas as $v) {
        if ($v['estado'] === 'anulada') continue;
        $es_obsequio = $v['metodo_pago'] === 'obsequio';
        $w->addRow([
            $v['id'],
            date('d/m/Y', strtotime($v['fecha_venta'])),
            date('H:i',   strtotime($v['fecha_venta'])),
            $v['cliente'],
            (int)$v['num_items'],
            $metodo_label[$v['metodo_pago']] ?? $v['metodo_pago'],
            (float)$v['total'],
            $v['estado'],
            $v['cajero'] ?? '',
        ]);
        if (!$es_obsequio) $total_pesos += (float)$v['total'];
    }
    $w->addEmptyRow();
    $w->addRow(['', '', '', '', '', 'TOTAL INGRESOS (sin obsequios)', $total_pesos, '', ''], false, true);

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
        $label = $metodo_label[$metodo] ?? $metodo;
        $nota  = $metodo === 'obsequio' ? ' (no es ingreso)' : '';
        $w->addRow([$label . $nota, $datos['count'], $datos['total']]);
    }

    // ── Hoja 2: Rentabilidad ────────────────────────────────────────────────
    $w->setSheet('Rentabilidad');
    $w->addRow(['ClanDestino ERP — Rentabilidad por Producto'], true);
    $w->addRow(["Costo fijo/u: $" . number_format($costo_fijo_u, 2) . "  |  Generado: " . date('d/m/Y H:i')]);
    $w->addEmptyRow();
    $w->addRow(['Producto', 'Nombre complementario', 'Tamaño', 'Precio Venta', 'Costo Ing.', 'Costo Fijo', 'Costo Total', 'Margen $', 'Margen %'], true);
    foreach ($rentabilidad as $p) {
        $w->addRow([
            $p['nombre'],
            $p['nombre2'] ?? '',   // subtítulo complementario (vacío si no tiene)
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
// obsequio se cuenta aparte; su valor NO suma al total de ingresos
$stats = ['total'=>0,'pesos'=>0,'efectivo'=>0,'digital'=>0,'fiado'=>0,'anuladas'=>0,'obsequio_n'=>0,'obsequio_val'=>0.0];
foreach ($ventas as $v) {
    if ($v['estado'] === 'anulada') { $stats['anuladas']++; continue; }
    if ($v['metodo_pago'] === 'obsequio') {
        $stats['obsequio_n']++;
        $stats['obsequio_val'] += (float)$v['total'];
        continue;
    }
    $stats['total']++;
    $stats['pesos'] += (float)$v['total'];
    if ($v['metodo_pago'] === 'efectivo')                                          $stats['efectivo'] += (float)$v['total'];
    elseif ($v['metodo_pago'] === 'fiado')                                         $stats['fiado']    += (float)$v['total'];
    elseif (in_array($v['metodo_pago'], ['nequi','daviplata','bancolombia'], true)) $stats['digital']  += (float)$v['total'];
}
$estado_c = ['completada'=>'b-ok','anulada'=>'b-ano','pendiente_pago'=>'b-pend'];
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
        .main { padding:16px 14px; max-width:1000px; margin:0 auto; }
        .card { background:var(--white); border-radius:14px; box-shadow:0 1px 4px rgba(0,0,0,.06); overflow:hidden; overflow-x:auto; margin-bottom:16px; }
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
        .b-obs { background:#fce7f3; color:#9d174d; }
        /* Margen en tabla rentabilidad */
        .m-ok  { color:var(--green); font-weight:800; }
        .m-mid { color:var(--yellow); font-weight:800; }
        .m-bad { color:var(--brand); font-weight:800; }

        /* ════════════════════════════════════════════════════════════════
           RESPONSIVE — REPORTE VENTAS
           ════════════════════════════════════════════════════════════════ */
        /* Tabla con scroll horizontal global */
        .card { overflow-x:auto; -webkit-overflow-scrolling:touch; }

        /* xs: < 480px */
        @media (max-width:479px) {
            .main { padding:12px 10px 40px; }
            /* Filtros en columna */
            .filter-row { flex-direction:column; align-items:stretch; padding:12px; }
            .fg { width:100%; }
            .fg input { width:100%; }
            .btn-ver, .btn-xl { width:100%; min-height:44px; padding:11px 16px; }
            /* Stats 2 cols */
            .stats { grid-template-columns:1fr 1fr !important; gap:8px; }
            .stat-n { font-size:15px !important; }
            /* Tabla scroll */
            .card { overflow-x:auto; }
            table { min-width:480px; }
        }
        /* sm: 480-639px */
        @media (min-width:480px) and (max-width:639px) {
            .filter-row { flex-wrap:wrap; }
            .stats { grid-template-columns:repeat(3,1fr) !important; }
            .card { overflow-x:auto; }
            table { min-width:520px; }
        }
        /* md: 640-1023px */
        @media (min-width:640px) and (max-width:1023px) {
            .card { overflow-x:auto; }
        }
        /* ≥1600px */
        @media (min-width:1600px) {
            .main { max-width:1300px; }
            .stats { grid-template-columns:repeat(5,1fr) !important; }
            .stat-n { font-size:22px !important; }
            th, td { padding:12px 16px !important; font-size:14px !important; }
        }
        /* TV ≥1920px */
        @media (min-width:1920px) {
            .main { max-width:1600px; }
            .stat-n { font-size:26px !important; }
        }
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
        <div class="stat"><div class="stat-n" style="color:var(--brand)">$<?= number_format($stats['pesos'],0,',','.') ?></div><div class="stat-l">Total ingresos</div></div>
        <div class="stat"><div class="stat-n">$<?= number_format($stats['efectivo'],0,',','.') ?></div><div class="stat-l">Efectivo</div></div>
        <div class="stat"><div class="stat-n">$<?= number_format($stats['digital'],0,',','.') ?></div><div class="stat-l">Digital</div></div>
        <div class="stat"><div class="stat-n" style="color:var(--yellow)">$<?= number_format($stats['fiado'],0,',','.') ?></div><div class="stat-l">Fiado</div></div>
    </div>
    <?php if ($stats['obsequio_n'] > 0): ?>
    <div style="background:#fdf4ff;border:1px solid #fbcfe8;border-radius:12px;padding:12px 16px;
                margin-bottom:16px;font-size:13px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <span style="font-size:18px">🎁</span>
        <span><strong><?= $stats['obsequio_n'] ?> obsequio<?= $stats['obsequio_n']>1?'s':'' ?></strong>
        registrado<?= $stats['obsequio_n']>1?'s':'' ?> en este período
        — valor ref: <strong>$<?= number_format($stats['obsequio_val'],0,',','.') ?></strong>
        <span style="color:var(--g5)">(no incluido en total de ingresos)</span></span>
    </div>
    <?php endif; ?>

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
