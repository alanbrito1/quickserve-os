<?php
/**
 * contabilidad/index.php — Tablero de Contabilidad (Fase 4a).
 * Resumen del balance + accesos (apertura, balance, libro diario, plan de cuentas).
 */
require_once __DIR__ . '/../app/middleware/auth_check.php';
require_once __DIR__ . '/../app/models/ContabilidadModel.php';

$nav_activo = 'contabilidad';
$nav_sub    = 'resumen';
if (!in_array($_SESSION['usuario_rol'] ?? '', ['admin', 'superadmin'], true)) {
    http_response_code(403); include __DIR__ . '/../app/views/errors/403.php'; exit;
}
if (!ContabilidadModel::existe()) { include __DIR__ . '/_sin_migracion.php'; exit; }

$bal      = ContabilidadModel::balance();
$nAsientos = (int)db()->query("SELECT COUNT(*) FROM asientos WHERE anulado=0")->fetchColumn();
$hayApertura = (int)db()->query("SELECT COUNT(*) FROM asientos WHERE origen='apertura' AND anulado=0")->fetchColumn() > 0;
?>
<!DOCTYPE html><html lang="es"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Contabilidad — <?= APP_NAME ?></title>
<style>
    :root{--brand:#e94f37;--dark:#111827;--g5:#6b7280;--g8:#d1d5db;--g9:#f3f4f6;--white:#fff;--green:#059669;--red:#dc2626;}
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
    body{font-family:system-ui,-apple-system,sans-serif;background:var(--g9);color:var(--dark);}
    .main{max-width:900px;margin:0 auto;padding:20px 14px 60px;}
    .page-title{font-size:22px;font-weight:800;} .page-sub{font-size:13px;color:var(--g5);margin:3px 0 16px;}
    .kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:16px;}
    .kpi{background:var(--white);border:1px solid var(--g8);border-radius:12px;padding:13px 15px;}
    .kpi-val{font-size:19px;font-weight:800;} .kpi-lbl{font-size:10.5px;color:var(--g5);margin-top:3px;text-transform:uppercase;}
    .eq{padding:11px 15px;border-radius:10px;font-size:13.5px;font-weight:700;margin-bottom:16px;}
    .eq.ok{background:#d1fae5;color:#065f46;} .eq.no{background:#fee2e2;color:#991b1b;}
    .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:12px;}
    .nav-card{background:var(--white);border:1px solid var(--g8);border-radius:12px;padding:16px;text-decoration:none;color:inherit;transition:.15s;}
    .nav-card:hover{border-color:var(--brand);box-shadow:0 6px 20px rgba(0,0,0,.08);}
    .nav-card b{font-size:14px;} .nav-card p{font-size:12px;color:var(--g5);margin-top:4px;}
    .warn{background:#fef3c7;border:1px solid #fde68a;border-radius:10px;padding:12px 14px;font-size:13px;color:#92400e;margin-bottom:16px;}
</style></head><body>
<?php include __DIR__ . '/../app/views/nav.php'; ?>
<main class="main">
    <h1 class="page-title">Contabilidad</h1>
    <p class="page-sub">Partida doble · plan de cuentas simplificado · <?= $nAsientos ?> asientos.</p>

    <?php if (!$hayApertura): ?>
    <div class="warn"><strong>Falta el balance de apertura.</strong> Empieza fijando los saldos iniciales (caja, inventario, activos…) en <a href="<?= APP_BASE ?>/contabilidad/apertura.php" style="color:#e94f37;font-weight:700">Apertura</a>.</div>
    <?php endif; ?>

    <div class="kpis">
        <div class="kpi"><div class="kpi-val">$<?= fmt_moneda($bal['activo']) ?></div><div class="kpi-lbl">Total activos</div></div>
        <div class="kpi"><div class="kpi-val">$<?= fmt_moneda($bal['pasivo']) ?></div><div class="kpi-lbl">Total pasivos</div></div>
        <div class="kpi"><div class="kpi-val">$<?= fmt_moneda($bal['patrimonio_total']) ?></div><div class="kpi-lbl">Patrimonio</div></div>
        <div class="kpi"><div class="kpi-val <?= $bal['resultado']>=0?'':'' ?>">$<?= fmt_moneda($bal['resultado']) ?></div><div class="kpi-lbl">Resultado ejercicio</div></div>
    </div>

    <div class="eq <?= $bal['cuadra']?'ok':'no' ?>">
        <?= $bal['cuadra'] ? '✓ El balance cuadra' : '⚠ El balance NO cuadra (revisa los asientos)' ?>
    </div>

    <div class="grid">
        <a class="nav-card" href="<?= APP_BASE ?>/contabilidad/balance.php"><b>📊 Balance General</b><p>Activo = Pasivo + Patrimonio, a una fecha. Export Excel.</p></a>
        <a class="nav-card" href="<?= APP_BASE ?>/contabilidad/apertura.php"><b>🏁 Balance de apertura</b><p>Fijar los saldos iniciales del negocio.</p></a>
        <a class="nav-card" href="<?= APP_BASE ?>/contabilidad/libro_diario.php"><b>📖 Libro diario</b><p>Todos los asientos con su detalle; reversar.</p></a>
        <a class="nav-card" href="<?= APP_BASE ?>/contabilidad/plan_cuentas.php"><b>🗂 Plan de cuentas</b><p>Catálogo de cuentas contables.</p></a>
        <a class="nav-card" href="<?= APP_BASE ?>/reportes/pyg.php"><b>💵 Estado de Resultados</b><p>P&amp;G del período (Reportes).</p></a>
    </div>
</main></body></html>
