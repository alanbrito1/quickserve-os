<?php
/**
 * public_html/reportes/operativo.php
 * Reporte Operativo: Inventario + Activos.
 * Exporta a .xlsx con hojas "Inventario" y "Activos".
 */

require_once __DIR__ . '/../app/middleware/auth_check.php';
require_once __DIR__ . '/../app/models/InsumoModel.php';
require_once __DIR__ . '/../app/models/ActivoModel.php';
require_once __DIR__ . '/../app/helpers/XlsxWriter.php';

permiso_requerir('reportes', 'solo_ver');

$insumos = InsumoModel::todos_con_estado();
$activos = ActivoModel::todos();
$dep_dia = ActivoModel::costo_diario_total();

// ── EXPORTAR EXCEL ──────────────────────────────────────────────────────────
if (isset($_GET['export'])) {
    $w = new XlsxWriter();

    // Hoja 1: Inventario
    $w->setSheet('Inventario');
    $w->addRow(['ClanDestino ERP — Inventario de Insumos'], true);
    $w->addRow(['Generado: ' . date('d/m/Y H:i')]);
    $w->addEmptyRow();
    $w->addRow(['Insumo', 'Unidad', 'Stock Actual', 'Stock Seguridad', 'Estado', 'Costo/u', 'Valor en Stock', 'Proveedor'], true);

    $valor_total = 0;
    foreach ($insumos as $i) {
        $valor = round((float)$i['stock_actual'] * (float)$i['costo_actual'], 2);
        $valor_total += $valor;
        $w->addRow([
            $i['nombre'],
            $i['unidad_medida'],
            (float)$i['stock_actual'],
            (float)$i['stock_seguridad'],
            $i['estado'],
            (float)$i['costo_actual'],
            $valor,
            $i['proveedor_nombre'] ?? '',
        ]);
    }
    $w->addEmptyRow();
    $w->addRow(['', '', '', '', '', 'VALOR TOTAL INVENTARIO', $valor_total, ''], false, true);

    // Hoja 2: Activos
    $w->setSheet('Activos');
    $w->addRow(['ClanDestino ERP — Activos Fijos'], true);
    $w->addRow(['Generado: ' . date('d/m/Y H:i') . '  |  Depreciación diaria total: $' . number_format($dep_dia, 2)]);
    $w->addEmptyRow();
    $w->addRow(['Activo', 'Descripción', 'Costo Inicial', 'Fecha Adq.', 'Vida Útil (m)', 'Dep. Mensual', 'Dep. Diaria', 'Meses Rest.', 'Estado Vida'], true);

    $total_dep_mens = 0;
    foreach ($activos as $a) {
        if (!$a['activo']) continue;
        $total_dep_mens += (float)$a['depreciacion_mensual'];
        $w->addRow([
            $a['nombre'],
            $a['descripcion'] ?? '',
            (float)$a['costo_inicial'],
            date('d/m/Y', strtotime($a['fecha_adquisicion'])),
            (int)$a['vida_util_meses'],
            (float)$a['depreciacion_mensual'],
            (float)$a['depreciacion_diaria'],
            (int)$a['meses_restantes'],
            $a['estado_vida'],
        ]);
    }
    $w->addEmptyRow();
    $w->addRow(['', '', '', '', '', $total_dep_mens, $dep_dia, '', ''], false, true);

    $w->download('ClanDestino_Operativo_' . date('Y-m-d') . '.xlsx');
}

// Cálculos para la vista HTML
$valor_inventario = array_sum(array_map(fn($i) => (float)$i['stock_actual'] * (float)$i['costo_actual'], $insumos));
$bajos    = count(array_filter($insumos, fn($i) => $i['estado'] === 'bajo'));
$agotados = count(array_filter($insumos, fn($i) => $i['estado'] === 'agotado'));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reporte Operativo — <?= APP_NAME ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root { --brand:#e94f37; --dark:#111827; --g2:#374151; --g5:#6b7280; --g8:#d1d5db; --g9:#f3f4f6; --white:#fff; --green:#059669; --yellow:#d97706; }
        body { font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; background:var(--g9); min-height:100vh; color:var(--dark); }
        .hdr { background:var(--dark); color:var(--white); height:54px; padding:0 14px; display:flex; align-items:center; justify-content:space-between; position:sticky; top:0; z-index:50; box-shadow:0 2px 8px rgba(0,0,0,.3); }
        .brand { font-size:17px; font-weight:800; } .brand span{color:var(--brand);}
        .nl { color:var(--g8); text-decoration:none; font-size:13px; padding:5px 10px; border-radius:8px; }
        .nl:hover { background:var(--g2); color:var(--white); }
        .main { padding:16px 14px; max-width:960px; margin:0 auto; }
        .top-actions { display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; }
        .page-title { font-size:20px; font-weight:800; }
        .btn-xl { padding:10px 18px; background:#16a34a; color:var(--white); border:none; border-radius:10px; font-size:13px; font-weight:700; cursor:pointer; text-decoration:none; }
        .stats { display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:16px; }
        @media(max-width:600px){ .stats { grid-template-columns:1fr 1fr; } }
        .stat { background:var(--white); border-radius:14px; padding:14px; box-shadow:0 1px 4px rgba(0,0,0,.06); }
        .stat-n { font-size:20px; font-weight:800; }
        .stat-l { font-size:11px; color:var(--g5); text-transform:uppercase; letter-spacing:.4px; }
        .section-title { font-size:16px; font-weight:800; margin:20px 0 10px; }
        .card { background:var(--white); border-radius:14px; box-shadow:0 1px 4px rgba(0,0,0,.06); overflow:hidden; margin-bottom:16px; }
        table { width:100%; border-collapse:collapse; }
        th { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--g5); padding:10px 14px; background:var(--g9); border-bottom:1px solid var(--g8); text-align:left; }
        th.r, td.r { text-align:right; }
        td { padding:10px 14px; border-bottom:1px solid var(--g9); font-size:13px; }
        tr:last-child td { border-bottom:none; }
        @media(max-width:600px){ .hide-m { display:none; } }
        .badge { font-size:10px; font-weight:700; padding:2px 7px; border-radius:20px; }
        .b-ok  { background:#d1fae5; color:#065f46; }
        .b-bajo{ background:#fef3c7; color:#92400e; }
        .b-ago { background:#fee2e2; color:#991b1b; }
        .b-nvo { background:#d1fae5; color:#065f46; }
        .b-med { background:#fef3c7; color:#92400e; }
        .b-cri { background:#fed7aa; color:#9a3412; }
        .b-dep { background:#f3f4f6; color:#6b7280; }
    </style>
</head>
<body>
<?php $nav_activo = 'reportes'; include __DIR__ . '/../app/views/nav.php'; ?>

<!-- header reemplazado por nav.php -->
<main class="main">

    <div class="top-actions">
        <h1 class="page-title">Reporte Operativo</h1>
        <a href="?export=1" class="btn-xl">⬇ Exportar Excel</a>
    </div>

    <!-- Stats -->
    <div class="stats">
        <div class="stat"><div class="stat-n">$<?= number_format($valor_inventario,0,',','.') ?></div><div class="stat-l">Valor Inventario</div></div>
        <div class="stat"><div class="stat-n" style="color:var(--yellow)"><?= $bajos ?></div><div class="stat-l">Insumos Bajos</div></div>
        <div class="stat"><div class="stat-n" style="color:var(--brand)"><?= $agotados ?></div><div class="stat-l">Agotados</div></div>
        <div class="stat"><div class="stat-n" style="color:var(--brand)">$<?= number_format($dep_dia,2,',','.') ?></div><div class="stat-l">Dep. Diaria</div></div>
    </div>

    <!-- Inventario -->
    <h2 class="section-title">Inventario de Insumos</h2>
    <div class="card">
        <table>
            <thead><tr>
                <th>Insumo</th>
                <th class="r">Stock</th>
                <th class="r hide-m">Seguridad</th>
                <th class="r hide-m">Costo/u</th>
                <th class="r hide-m">Valor Stock</th>
                <th>Estado</th>
            </tr></thead>
            <tbody>
                <?php foreach ($insumos as $i):
                    $bc = $i['estado'] === 'ok' ? 'b-ok' : ($i['estado'] === 'bajo' ? 'b-bajo' : 'b-ago');
                ?>
                <tr>
                    <td><?= htmlspecialchars($i['nombre']) ?></td>
                    <td class="r"><?= number_format($i['stock_actual'],3,',','.') ?> <small style="color:var(--g5)"><?= $i['unidad_medida'] ?></small></td>
                    <td class="r hide-m"><?= number_format($i['stock_seguridad'],3,',','.') ?></td>
                    <td class="r hide-m">$<?= number_format($i['costo_actual'],0,',','.') ?></td>
                    <td class="r hide-m">$<?= number_format((float)$i['stock_actual']*(float)$i['costo_actual'],0,',','.') ?></td>
                    <td><span class="badge <?= $bc ?>"><?= $i['estado'] ?></span></td>
                </tr>
                <?php endforeach; ?>
                <tr style="background:var(--g9); font-weight:800">
                    <td colspan="4">VALOR TOTAL INVENTARIO</td>
                    <td class="r hide-m">$<?= number_format($valor_inventario,0,',','.') ?></td>
                    <td></td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Activos -->
    <h2 class="section-title">Activos Fijos en Uso</h2>
    <div class="card">
        <table>
            <thead><tr>
                <th>Activo</th>
                <th class="r hide-m">Costo</th>
                <th class="r hide-m">Dep. Mensual</th>
                <th class="r">Dep. Diaria</th>
                <th class="r hide-m">Meses Rest.</th>
                <th>Estado</th>
            </tr></thead>
            <tbody>
                <?php foreach ($activos as $a):
                    if (!$a['activo']) continue;
                    $bc = ['nuevo'=>'b-nvo','medio'=>'b-med','critico'=>'b-cri','depreciado'=>'b-dep'][$a['estado_vida']] ?? 'b-ok';
                ?>
                <tr>
                    <td><?= htmlspecialchars($a['nombre']) ?></td>
                    <td class="r hide-m">$<?= number_format($a['costo_inicial'],0,',','.') ?></td>
                    <td class="r hide-m">$<?= number_format($a['depreciacion_mensual'],0,',','.') ?></td>
                    <td class="r">$<?= number_format($a['depreciacion_diaria'],2,',','.') ?></td>
                    <td class="r hide-m"><?= $a['meses_restantes'] ?> m</td>
                    <td><span class="badge <?= $bc ?>"><?= $a['estado_vida'] ?></span></td>
                </tr>
                <?php endforeach; ?>
                <tr style="background:var(--g9); font-weight:800">
                    <td colspan="3">TOTAL DEPRECIACIÓN DIARIA</td>
                    <td class="r">$<?= number_format($dep_dia,2,',','.') ?></td>
                    <td colspan="2"></td>
                </tr>
            </tbody>
        </table>
    </div>

</main>
</body>
</html>
