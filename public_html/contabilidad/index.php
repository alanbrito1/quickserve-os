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
        <a class="nav-card" href="<?= APP_BASE ?>/contabilidad/movimientos.php"><b>💸 Movimientos</b><p>Pagos (proveedor/nómina) y aportes/retiros de capital.</p></a>
        <a class="nav-card" href="<?= APP_BASE ?>/contabilidad/libro_diario.php"><b>📖 Libro diario</b><p>Todos los asientos con su detalle; reversar.</p></a>
        <a class="nav-card" href="<?= APP_BASE ?>/contabilidad/plan_cuentas.php"><b>🗂 Plan de cuentas</b><p>Catálogo de cuentas contables.</p></a>
        <a class="nav-card" href="<?= APP_BASE ?>/reportes/pyg.php"><b>💵 Estado de Resultados</b><p>P&amp;G del período (Reportes).</p></a>
    </div>

    <div style="margin-top:18px">
        <button class="nav-card" style="cursor:pointer;border:1px dashed var(--g8);width:100%;text-align:left" onclick="backfill(this)">
            <b>⚙ Contabilizar ventas históricas</b>
            <p>Genera el asiento de las ventas que aún no lo tienen (las nuevas ya se contabilizan solas).</p>
        </button>
    </div>

    <?php [$ivaAct, $ivaTarifa] = ContabilidadModel::ivaConfig(); ?>
    <div class="nav-card" style="margin-top:12px">
        <b>🧾 IVA</b>
        <p>Actívalo solo si tu negocio discrimina IVA. Al vender/comprar, el sistema separa el IVA (IVA por pagar 2408 / IVA descontable 1355). Por defecto: sin IVA.</p>
        <label style="font-size:13px"><input type="checkbox" id="iva-on" <?= $ivaAct?'checked':'' ?>> Discriminar IVA</label>
        &nbsp; Tarifa <input type="number" id="iva-tarifa" value="<?= (int)$ivaTarifa ?>" min="0" max="100" step="1" style="width:64px;padding:5px 7px;border:1px solid var(--g8);border-radius:7px">%
        <button class="btn" style="background:var(--brand);color:#fff;border:none;border-radius:8px;padding:6px 14px;font-weight:700;cursor:pointer;font-size:13px;margin-left:8px" onclick="guardarIva(this)">Guardar</button>
    </div>
</main>
<div id="toast" style="position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:#111827;color:#fff;padding:11px 18px;border-radius:10px;font-size:13px;display:none"></div>
<script>
const CSRF = <?= json_encode(csrf_token()) ?>;
function toast(m,ok){const e=document.getElementById('toast');e.textContent=m;e.style.background=ok?'#065f46':'#991b1b';e.style.display='block';setTimeout(()=>e.style.display='none',4500);}
async function backfill(btn){
    if(!confirm('¿Generar los asientos de todas las ventas históricas sin contabilizar?')) return;
    btn.disabled=true;
    const fd=new FormData(); fd.append('csrf_token',CSRF); fd.append('accion','backfill_ventas');
    try{
        const r=await fetch('api/contab.php',{method:'POST',body:fd});
        const d=await r.json();
        if(d.success){ toast('Ventas contabilizadas: '+d.posteadas+(d.errores?(' · '+d.errores+' con error'):''),true); setTimeout(()=>location.reload(),1500); }
        else { toast(d.error||'Error',false); btn.disabled=false; }
    }catch(e){ toast('Error de red',false); btn.disabled=false; }
}
async function guardarIva(btn){
    btn.disabled=true;
    const fd=new FormData(); fd.append('csrf_token',CSRF); fd.append('accion','config_iva');
    fd.append('iva_activo',document.getElementById('iva-on').checked?'1':'0');
    fd.append('iva_tarifa',document.getElementById('iva-tarifa').value||'0');
    try{
        const r=await fetch('api/contab.php',{method:'POST',body:fd});
        const d=await r.json();
        if(d.success){ toast('IVA guardado ('+(d.iva_activo?d.iva_tarifa+'%':'desactivado')+')',true); }
        else toast(d.error||'Error',false);
    }catch(e){ toast('Error de red',false); }
    btn.disabled=false;
}
</script></body></html>
