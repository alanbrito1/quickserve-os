<?php
/**
 * reportes/compras.php — Reporte de compras y análisis de proveedores.
 *
 * Incluye:
 *   - Historial de compras por período con filtros
 *   - Gasto total por proveedor
 *   - Evolución mensual del gasto
 *   - Insumos más comprados
 *
 * Exporta a Excel con 3 hojas: Historial, Por Proveedor, Por Insumo.
 */
require_once __DIR__ . '/../app/middleware/auth_check.php';
require_once __DIR__ . '/../app/helpers/XlsxWriter.php';

$nav_activo = 'reportes';
permiso_requerir('compras', 'solo_ver');

// ── Filtros ───────────────────────────────────────────────────────────────────
$hoy         = date('Y-m-d');
$fecha_desde = $_GET['desde'] ?? date('Y-m-01'); // primer día del mes actual
$fecha_hasta = $_GET['hasta'] ?? $hoy;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_desde)) $fecha_desde = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_hasta)) $fecha_hasta = $hoy;
if ($fecha_desde > $fecha_hasta) $fecha_hasta = $fecha_desde;

$f_ini = $fecha_desde . ' 00:00:00';
$f_fin = $fecha_hasta . ' 23:59:59';

// ── Historial de compras del período ─────────────────────────────────────────
$stmt_hist = db()->prepare(
    "SELECT c.id, c.fecha_compra, c.total, c.notas,
            IFNULL(p.nombre,'Sin proveedor') AS proveedor,
            u.nombre AS registrado_por,
            GROUP_CONCAT(i.nombre ORDER BY i.nombre SEPARATOR ', ') AS insumos_lista,
            COUNT(DISTINCT cd.insumo_id) AS n_insumos
     FROM compras c
     LEFT JOIN proveedores  p  ON p.id  = c.proveedor_id
     LEFT JOIN usuarios     u  ON u.id  = c.created_by
     LEFT JOIN compra_detalles cd ON cd.compra_id = c.id
     LEFT JOIN insumos       i  ON i.id  = cd.insumo_id
     WHERE c.fecha_compra BETWEEN :ini AND :fin
     GROUP BY c.id
     ORDER BY c.fecha_compra DESC"
);
$stmt_hist->execute([':ini' => $f_ini, ':fin' => $f_fin]);
$historial = $stmt_hist->fetchAll();

// ── KPIs del período ──────────────────────────────────────────────────────────
$total_periodo  = array_sum(array_column($historial, 'total'));
$n_compras      = count($historial);
$promedio       = $n_compras > 0 ? $total_periodo / $n_compras : 0;

// ── Gasto por proveedor ───────────────────────────────────────────────────────
$stmt_prov = db()->prepare(
    "SELECT IFNULL(p.nombre,'Sin proveedor') AS proveedor,
            SUM(c.total)  AS total,
            COUNT(*)      AS n
     FROM compras c
     LEFT JOIN proveedores p ON p.id = c.proveedor_id
     WHERE c.fecha_compra BETWEEN :ini AND :fin
     GROUP BY proveedor
     ORDER BY total DESC"
);
$stmt_prov->execute([':ini' => $f_ini, ':fin' => $f_fin]);
$por_proveedor = $stmt_prov->fetchAll();

// ── Insumos más comprados ─────────────────────────────────────────────────────
$stmt_ins = db()->prepare(
    "SELECT i.nombre, SUM(cd.cantidad) AS total_cantidad,
            SUM(cd.subtotal) AS total_pesos, i.unidad_medida,
            AVG(cd.precio_unitario) AS precio_promedio
     FROM compra_detalles cd
     JOIN compras c ON c.id = cd.compra_id
     JOIN insumos i ON i.id = cd.insumo_id
     WHERE c.fecha_compra BETWEEN :ini AND :fin
     GROUP BY i.id, i.nombre, i.unidad_medida
     ORDER BY total_pesos DESC
     LIMIT 20"
);
$stmt_ins->execute([':ini' => $f_ini, ':fin' => $f_fin]);
$por_insumo = $stmt_ins->fetchAll();

// ── Exportar a Excel ──────────────────────────────────────────────────────────
if (($_GET['export'] ?? '') === '1') {
    $w = new XlsxWriter();

    // Hoja 1: Historial
    $w->setSheet('Historial');
    $w->addRow(['REPORTE DE COMPRAS ' . date('d/m/Y', strtotime($fecha_desde)) . ' - ' . date('d/m/Y', strtotime($fecha_hasta))], header: true);
    $w->addRow([]);
    $w->addRow(['Fecha', 'Proveedor', 'Insumos', 'Total ($)', 'Registrado por', 'Notas'], header: true);
    foreach ($historial as $c) {
        $w->addRow([
            date('d/m/Y', strtotime($c['fecha_compra'])),
            $c['proveedor'],
            $c['insumos_lista'] ?? '',
            round((float)$c['total']),
            $c['registrado_por'] ?? '',
            $c['notas'] ?? '',
        ]);
    }
    $w->addRow(['TOTAL', '', '', round($total_periodo), '', ''], total: true);

    // Hoja 2: Por proveedor
    $w->setSheet('Por Proveedor');
    $w->addRow(['Proveedor', 'N° compras', 'Total ($)'], header: true);
    foreach ($por_proveedor as $r) {
        $w->addRow([$r['proveedor'], (int)$r['n'], round((float)$r['total'])]);
    }

    // Hoja 3: Por insumo
    $w->setSheet('Por Insumo');
    $w->addRow(['Insumo', 'Unidad', 'Cantidad total', 'Precio promedio', 'Total ($)'], header: true);
    foreach ($por_insumo as $r) {
        $w->addRow([
            $r['nombre'],
            $r['unidad_medida'],
            round((float)$r['total_cantidad'], 3),
            round((float)$r['precio_promedio']),
            round((float)$r['total_pesos']),
        ]);
    }

    $w->download('ClanDestino_Compras_' . $fecha_desde . '_' . $fecha_hasta . '.xlsx');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reporte Compras — <?= APP_NAME ?></title>
    <style>
        :root { --brand:#e94f37; --dark:#111827; --g5:#6b7280; --g8:#d1d5db; --g9:#f3f4f6; --white:#fff; --green:#059669; }
        *,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
        body { font-family:system-ui,-apple-system,sans-serif; background:var(--g9); color:var(--dark); }
        .main { max-width:1000px; margin:0 auto; padding:20px 14px 60px; }
        .page-hdr { display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:10px; margin-bottom:20px; }
        .page-title { font-size:22px; font-weight:800; }
        .page-sub   { font-size:13px; color:var(--g5); margin-top:3px; }
        .filtros { display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end; background:var(--white); border:1px solid var(--g8); border-radius:12px; padding:12px 16px; margin-bottom:18px; }
        .fg { display:flex; flex-direction:column; gap:3px; }
        .fg label { font-size:11px; font-weight:700; color:var(--g5); text-transform:uppercase; }
        .fg input { padding:7px 10px; border:1px solid var(--g8); border-radius:8px; font-size:13px; outline:none; }
        .fg input:focus { border-color:var(--brand); }
        .btn-ver { padding:8px 16px; background:var(--brand); color:#fff; border:none; border-radius:8px; font-size:13px; font-weight:700; cursor:pointer; align-self:flex-end; }
        .btn-excel { padding:8px 16px; background:#16a34a; color:#fff; border:none; border-radius:8px; font-size:13px; font-weight:700; text-decoration:none; display:inline-block; }
        .kpi-row { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:10px; margin-bottom:18px; }
        .kpi { background:var(--white); border:1px solid var(--g8); border-radius:12px; padding:14px 16px; }
        .kpi-val { font-size:20px; font-weight:800; }
        .kpi-val.brand { color:var(--brand); }
        .kpi-val.green { color:var(--green); }
        .kpi-lbl { font-size:11px; color:var(--g5); margin-top:3px; text-transform:uppercase; letter-spacing:.4px; }
        .section-title { font-size:13px; font-weight:700; color:var(--g5); text-transform:uppercase; letter-spacing:.5px; margin:20px 0 10px; }
        .tbl-wrap { background:var(--white); border:1px solid var(--g8); border-radius:12px; overflow:hidden; overflow-x:auto; margin-bottom:16px; }
        .tbl { width:100%; border-collapse:collapse; }
        .tbl thead th { background:var(--g9); font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; padding:9px 12px; text-align:left; color:var(--g5); }
        .tbl th.r,.tbl td.r { text-align:right; }
        .tbl tbody tr { border-bottom:1px solid var(--g9); }
        .tbl tbody tr:last-child { border-bottom:none; }
        .tbl tbody tr.total-row td { font-weight:700; background:var(--g9); }
        .tbl td { padding:9px 12px; font-size:13px; }
        .empty { text-align:center; padding:40px; color:var(--g5); }

        /* ════════════════════════════════════════════════════════════════
           RESPONSIVE — REPORTE COMPRAS
           ════════════════════════════════════════════════════════════════ */
        /* Tablas con scroll horizontal */
        .tbl-wrap { overflow-x:auto; -webkit-overflow-scrolling:touch; }

        /* xs: < 480px */
        @media (max-width:479px) {
            .main { padding:12px 10px 40px; }
            /* Encabezado en columna */
            .page-hdr { flex-direction:column; }
            .btn-excel { width:100%; min-height:44px; text-align:center; display:block; }
            /* Filtros en columna */
            .filtros { flex-direction:column; align-items:stretch; }
            .fg { width:100%; }
            .fg input { width:100%; }
            .btn-ver { width:100%; min-height:44px; align-self:auto; }
            /* KPIs 2 cols */
            .kpi-row { grid-template-columns:1fr 1fr !important; gap:8px; }
            .kpi-val { font-size:16px !important; }
            /* Tabla min-width */
            .tbl { min-width:380px; }
        }
        /* sm: 480-639px */
        @media (min-width:480px) and (max-width:639px) {
            .filtros { flex-wrap:wrap; }
            .kpi-row { grid-template-columns:repeat(2,1fr) !important; }
        }
        /* ≥1600px */
        @media (min-width:1600px) {
            .main { max-width:1300px; }
            .kpi-val { font-size:24px !important; }
            .tbl thead th, .tbl td { padding:12px 14px !important; font-size:14px !important; }
        }
        /* TV ≥1920px */
        @media (min-width:1920px) {
            .main { max-width:1600px; }
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/../app/views/nav.php'; ?>
<main class="main">
    <div class="page-hdr">
        <div>
            <h1 class="page-title">Reporte de Compras</h1>
            <p class="page-sub"><?= date('d/m/Y', strtotime($fecha_desde)) ?> — <?= date('d/m/Y', strtotime($fecha_hasta)) ?> · <?= $n_compras ?> compras</p>
        </div>
        <a href="?desde=<?= $fecha_desde ?>&hasta=<?= $fecha_hasta ?>&export=1" class="btn-excel">Exportar Excel</a>
    </div>

    <!-- Filtros -->
    <form method="GET" class="filtros">
        <div class="fg"><label>Desde</label>
            <input type="date" name="desde" value="<?= htmlspecialchars($fecha_desde) ?>"></div>
        <div class="fg"><label>Hasta</label>
            <input type="date" name="hasta" value="<?= htmlspecialchars($fecha_hasta) ?>"></div>
        <button type="submit" class="btn-ver">Filtrar</button>
    </form>

    <!-- KPIs -->
    <div class="kpi-row">
        <div class="kpi"><div class="kpi-val brand"><?= $n_compras ?></div><div class="kpi-lbl">Compras</div></div>
        <div class="kpi"><div class="kpi-val">$<?= number_format($total_periodo,0,',','.') ?></div><div class="kpi-lbl">Total gastado</div></div>
        <div class="kpi"><div class="kpi-val green">$<?= number_format($promedio,0,',','.') ?></div><div class="kpi-lbl">Promedio por compra</div></div>
        <div class="kpi"><div class="kpi-val"><?= count($por_proveedor) ?></div><div class="kpi-lbl">Proveedores</div></div>
    </div>

    <!-- Por proveedor -->
    <p class="section-title">Gasto por proveedor</p>
    <div class="tbl-wrap">
        <?php if (empty($por_proveedor)): ?>
        <div class="empty">Sin compras en este período.</div>
        <?php else: ?>
        <table class="tbl">
            <thead><tr><th>Proveedor</th><th class="r">N° compras</th><th class="r">Total ($)</th><th class="r">% del total</th></tr></thead>
            <tbody>
            <?php foreach ($por_proveedor as $r):
                $pct = $total_periodo > 0 ? round((float)$r['total'] / $total_periodo * 100, 1) : 0;
            ?>
            <tr>
                <td><?= htmlspecialchars($r['proveedor']) ?></td>
                <td class="r"><?= (int)$r['n'] ?></td>
                <td class="r"><strong>$<?= number_format((float)$r['total'],0,',','.') ?></strong></td>
                <td class="r" style="color:var(--g5)"><?= $pct ?>%</td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr class="total-row"><td>TOTAL</td><td class="r"><?= $n_compras ?></td><td class="r">$<?= number_format($total_periodo,0,',','.') ?></td><td class="r">100%</td></tr></tfoot>
        </table>
        <?php endif; ?>
    </div>

    <!-- Insumos más comprados -->
    <?php if (!empty($por_insumo)): ?>
    <p class="section-title">Insumos más comprados</p>
    <div class="tbl-wrap">
        <table class="tbl">
            <thead><tr><th>Insumo</th><th>Unidad</th><th class="r">Cantidad total</th><th class="r">Precio promedio</th><th class="r">Total ($)</th></tr></thead>
            <tbody>
            <?php foreach ($por_insumo as $r): ?>
            <tr>
                <td><strong><?= htmlspecialchars($r['nombre']) ?></strong></td>
                <td style="color:var(--g5)"><?= htmlspecialchars($r['unidad_medida']) ?></td>
                <td class="r"><?= number_format((float)$r['total_cantidad'],2,',','.') ?></td>
                <td class="r">$<?= number_format((float)$r['precio_promedio'],0,',','.') ?></td>
                <td class="r"><strong>$<?= number_format((float)$r['total_pesos'],0,',','.') ?></strong></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- Historial detallado -->
    <p class="section-title">Historial de compras</p>
    <div class="tbl-wrap">
        <?php if (empty($historial)): ?>
        <div class="empty">Sin compras en este período.</div>
        <?php else: ?>
        <table class="tbl">
            <thead><tr><th>Fecha</th><th>Proveedor</th><th>Insumos</th><th class="r">Total</th></tr></thead>
            <tbody>
            <?php foreach ($historial as $c): ?>
            <tr>
                <td style="color:var(--g5);font-size:12px"><?= date('d/m/Y H:i', strtotime($c['fecha_compra'])) ?></td>
                <td><?= htmlspecialchars($c['proveedor']) ?></td>
                <td style="font-size:12px;color:var(--g5);max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                    <?= htmlspecialchars($c['insumos_lista'] ?? '—') ?>
                </td>
                <td class="r"><strong>$<?= number_format((float)$c['total'],0,',','.') ?></strong></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr class="total-row"><td colspan="3">TOTAL</td><td class="r">$<?= number_format($total_periodo,0,',','.') ?></td></tr></tfoot>
        </table>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
