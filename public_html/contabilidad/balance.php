<?php
/**
 * contabilidad/balance.php — Balance General a una fecha (Fase 4a).
 * Activo = Pasivo + Patrimonio (+ resultado del ejercicio). Export Excel.
 */
require_once __DIR__ . '/../app/middleware/auth_check.php';
require_once __DIR__ . '/../app/models/ContabilidadModel.php';

$nav_activo = 'contabilidad';
$nav_sub    = 'balance';
if (!in_array($_SESSION['usuario_rol'] ?? '', ['admin', 'superadmin'], true)) {
    http_response_code(403); include __DIR__ . '/../app/views/errors/403.php'; exit;
}
if (!ContabilidadModel::existe()) {
    $nav_activo='contabilidad'; include __DIR__ . '/_sin_migracion.php'; exit;
}

$hasta   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['hasta'] ?? '') ? $_GET['hasta'] : date('Y-m-d');
$saldos  = ContabilidadModel::saldos($hasta);
$bal     = ContabilidadModel::balance($hasta);

// Agrupar por tipo para el render
$porTipo = ['activo'=>[], 'pasivo'=>[], 'patrimonio'=>[], 'ingreso'=>[], 'costo'=>[], 'gasto'=>[]];
foreach ($saldos as $s) {
    if (abs($s['saldo']) < 0.005) continue;
    $porTipo[$s['tipo']][] = $s;
}

if (($_GET['export'] ?? '') === '1') {
    require_once __DIR__ . '/../app/helpers/XlsxWriter.php';
    $w = new XlsxWriter();
    $w->setSheet('Balance General');
    $w->addRow(['BALANCE GENERAL AL ' . $hasta], header: true);
    $w->addRow([]);
    $w->addRow(['Código', 'Cuenta', 'Saldo ($)'], header: true);
    $secc = ['activo'=>'ACTIVOS','pasivo'=>'PASIVOS','patrimonio'=>'PATRIMONIO'];
    foreach ($secc as $tp => $tit) {
        $w->addRow([$tit], header: true);
        foreach ($porTipo[$tp] as $s) $w->addRow([$s['codigo'], $s['nombre'], round($s['saldo'])]);
    }
    $w->addRow(['', 'Utilidad del ejercicio (ingresos − costos − gastos)', round($bal['resultado'])]);
    $w->addRow([]);
    $w->addRow(['TOTAL ACTIVOS', '', round($bal['activo'])], total: true);
    $w->addRow(['TOTAL PASIVO + PATRIMONIO', '', round($bal['pasivo_mas_patrimonio'])], total: true);
    $w->download('QuickServe OS_Balance_' . $hasta . '.xlsx');
    exit;
}
function saldoTxt($v){ return '$' . fmt_moneda((float)$v); }
?>
<!DOCTYPE html><html lang="es"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Balance General — <?= APP_NAME ?></title>
<style>
    :root{--brand:#e94f37;--dark:#111827;--g5:#6b7280;--g8:#d1d5db;--g9:#f3f4f6;--white:#fff;--green:#059669;--red:#dc2626;}
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
    body{font-family:system-ui,-apple-system,sans-serif;background:var(--g9);color:var(--dark);}
    .main{max-width:760px;margin:0 auto;padding:20px 14px 60px;}
    .page-title{font-size:22px;font-weight:800;}
    .filtros{display:flex;gap:8px;align-items:flex-end;background:var(--white);border:1px solid var(--g8);border-radius:12px;padding:12px 16px;margin:12px 0 16px;flex-wrap:wrap;}
    .fg{display:flex;flex-direction:column;gap:3px;} .fg label{font-size:11px;font-weight:700;color:var(--g5);text-transform:uppercase;}
    .fg input{padding:7px 10px;border:1px solid var(--g8);border-radius:8px;font-size:13px;}
    .btn{padding:8px 16px;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;text-decoration:none;color:#fff;}
    .btn-brand{background:var(--brand);} .btn-excel{background:#16a34a;margin-left:auto;}
    .card{background:var(--white);border:1px solid var(--g8);border-radius:14px;padding:8px;margin-bottom:16px;}
    .bl{width:100%;border-collapse:collapse;font-size:14px;}
    .bl td{padding:8px 14px;border-bottom:1px solid var(--g9);} .bl td.r{text-align:right;font-variant-numeric:tabular-nums;}
    .bl tr.sec td{font-weight:800;background:var(--g9);text-transform:uppercase;font-size:12px;letter-spacing:.4px;color:var(--g5);}
    .bl tr.tot td{font-weight:800;} .bl tr.tot.big td{font-size:16px;border-top:2px solid var(--dark);}
    .eq{padding:12px 16px;border-radius:10px;font-size:14px;font-weight:700;margin-bottom:16px;}
    .eq.ok{background:#d1fae5;color:#065f46;} .eq.no{background:#fee2e2;color:#991b1b;}
</style></head><body>
<?php include __DIR__ . '/../app/views/nav.php'; ?>
<main class="main">
    <h1 class="page-title">Balance General</h1>
    <form class="filtros" method="get">
        <div class="fg"><label>Al corte</label><input type="date" name="hasta" value="<?= $hasta ?>" max="<?= date('Y-m-d') ?>"></div>
        <button class="btn btn-brand" type="submit">Ver</button>
        <a class="btn btn-excel" href="?hasta=<?= $hasta ?>&export=1">📊 Excel</a>
    </form>

    <div class="eq <?= $bal['cuadra']?'ok':'no' ?>">
        <?= $bal['cuadra'] ? '✓ El balance cuadra' : '⚠ El balance NO cuadra' ?>:
        Activo <?= saldoTxt($bal['activo']) ?> = Pasivo + Patrimonio <?= saldoTxt($bal['pasivo_mas_patrimonio']) ?>
    </div>

    <div class="card"><table class="bl">
        <?php
        $secciones = ['activo'=>'Activos','pasivo'=>'Pasivos','patrimonio'=>'Patrimonio'];
        foreach ($secciones as $tp => $tit):
            $subtotal = 0.0; ?>
            <tr class="sec"><td colspan="2"><?= $tit ?></td></tr>
            <?php foreach ($porTipo[$tp] as $s): $subtotal += $s['saldo']; ?>
                <tr><td><?= htmlspecialchars($s['codigo'].' · '.$s['nombre']) ?></td>
                    <td class="r"><?= saldoTxt($s['saldo']) ?></td></tr>
            <?php endforeach; ?>
            <?php if ($tp === 'patrimonio'): ?>
                <tr><td>Utilidad del ejercicio (ingresos − costos − gastos)</td>
                    <td class="r <?= $bal['resultado']>=0?'':'' ?>"><?= saldoTxt($bal['resultado']) ?></td></tr>
                <?php $subtotal += $bal['resultado']; ?>
            <?php endif; ?>
            <tr class="tot"><td>Total <?= strtolower($tit) ?></td>
                <td class="r"><?= saldoTxt($tp==='activo' ? $bal['activo'] : ($tp==='pasivo'?$bal['pasivo']:$bal['patrimonio_total'])) ?></td></tr>
        <?php endforeach; ?>
        <tr class="tot big"><td>TOTAL ACTIVOS</td><td class="r"><?= saldoTxt($bal['activo']) ?></td></tr>
        <tr class="tot big"><td>TOTAL PASIVO + PATRIMONIO</td><td class="r"><?= saldoTxt($bal['pasivo_mas_patrimonio']) ?></td></tr>
    </table></div>
    <p style="font-size:12.5px;color:var(--g5)">El "resultado del ejercicio" es la utilidad acumulada según los asientos (ingresos − costos − gastos). Un balance de resultados detallado está en Reportes → Estado de Resultados (P&amp;G).</p>
</main></body></html>
