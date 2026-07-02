<?php
/**
 * contabilidad/apertura.php — Balance de apertura (saldos iniciales).
 * Pre-llena lo que el sistema ya sabe (fiado, inventario, activos). El Capital
 * social se calcula solo como cifra de cuadre. Genera el asiento de apertura.
 */
require_once __DIR__ . '/../app/middleware/auth_check.php';
require_once __DIR__ . '/../app/models/ContabilidadModel.php';

$nav_activo = 'contabilidad';
$nav_sub    = 'apertura';
if (!in_array($_SESSION['usuario_rol'] ?? '', ['admin', 'superadmin'], true)) {
    http_response_code(403); include __DIR__ . '/../app/views/errors/403.php'; exit;
}
if (!ContabilidadModel::existe()) { include __DIR__ . '/_sin_migracion.php'; exit; }

// ¿Ya hay un asiento de apertura?
$yaApertura = (int)db()->query("SELECT COUNT(*) FROM asientos WHERE origen='apertura' AND anulado=0")->fetchColumn() > 0;

// Pre-llenado desde datos existentes
$cxc     = (float)db()->query("SELECT COALESCE(SUM(saldo_fiado),0) FROM clientes WHERE activo=1")->fetchColumn();
$invIns  = (float)db()->query("SELECT COALESCE(SUM(stock_actual*costo_actual),0) FROM insumos WHERE activo=1")->fetchColumn();
$invProd = (float)db()->query("SELECT COALESCE(SUM(stock_disponible*costo_calculado),0) FROM productos WHERE activo=1")->fetchColumn();
$activos = (float)db()->query("SELECT COALESCE(SUM(costo_inicial),0) FROM activos WHERE activo=1")->fetchColumn();

// Cuentas del formulario: [codigo, nombre, lado, prefill]  (lado: 'activo' o 'pasivo')
$campos = [
    ['1105','Caja','activo',0],
    ['1110','Bancos','activo',0],
    ['1305','Cuentas por cobrar (fiado)','activo',round($cxc,2)],
    ['1430','Inventario producto terminado','activo',round($invProd,2)],
    ['1435','Inventario de insumos','activo',round($invIns,2)],
    ['1524','Activos fijos (costo)','activo',round($activos,2)],
    ['1592','(−) Depreciación acumulada','pasivo',0],
    ['2205','Proveedores por pagar','pasivo',0],
    ['2510','Nómina por pagar','pasivo',0],
];
$csrf = csrf_token();
?>
<!DOCTYPE html><html lang="es"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Balance de apertura — <?= APP_NAME ?></title>
<style>
    :root{--brand:#e94f37;--dark:#111827;--g5:#6b7280;--g8:#d1d5db;--g9:#f3f4f6;--white:#fff;--green:#059669;}
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
    body{font-family:system-ui,-apple-system,sans-serif;background:var(--g9);color:var(--dark);}
    .main{max-width:620px;margin:0 auto;padding:20px 14px 60px;}
    .page-title{font-size:22px;font-weight:800;} .page-sub{font-size:13px;color:var(--g5);margin:3px 0 16px;}
    .card{background:var(--white);border:1px solid var(--g8);border-radius:14px;padding:18px;margin-bottom:16px;}
    .row{display:grid;grid-template-columns:1fr 140px;gap:10px;align-items:center;padding:7px 0;border-bottom:1px solid var(--g9);}
    .row label{font-size:13px;} .row input{width:100%;padding:7px 9px;border:1px solid var(--g8);border-radius:8px;font-size:13px;text-align:right;}
    .cap{display:flex;justify-content:space-between;align-items:center;font-weight:800;padding:12px 0 4px;font-size:15px;}
    .warn{background:#fef3c7;border:1px solid #fde68a;border-radius:10px;padding:12px 14px;font-size:13px;color:#92400e;margin-bottom:14px;}
    .btn{padding:10px 18px;border:none;border-radius:8px;font-size:14px;font-weight:700;cursor:pointer;color:#fff;background:var(--brand);}
    .btn:disabled{opacity:.5;} .fecha{display:flex;flex-direction:column;gap:3px;margin-bottom:12px;}
    .fecha input{padding:7px 10px;border:1px solid var(--g8);border-radius:8px;font-size:13px;max-width:180px;}
    .toast{position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:#111827;color:#fff;padding:11px 18px;border-radius:10px;font-size:13px;display:none;}
    .toast.ok{background:#065f46;} .toast.err{background:#991b1b;}
</style></head><body>
<?php include __DIR__ . '/../app/views/nav.php'; ?>
<main class="main">
    <h1 class="page-title">Balance de apertura</h1>
    <p class="page-sub">Fija los saldos iniciales. El <strong>Capital social</strong> se calcula solo para que el balance cuadre.</p>

    <?php if ($yaApertura): ?>
    <div class="warn">Ya existe un asiento de apertura. Si registras otro, se sumará (revisa el Libro diario antes).</div>
    <?php endif; ?>

    <div class="card">
        <div class="fecha"><label style="font-size:11px;font-weight:700;color:var(--g5);text-transform:uppercase">Fecha</label>
            <input type="date" id="ap-fecha" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>"></div>
        <?php foreach ($campos as [$cod,$nom,$lado,$pre]): ?>
        <div class="row">
            <label><?= htmlspecialchars($nom) ?> <small style="color:var(--g5)">(<?= $cod ?>)</small></label>
            <input type="number" step="any" min="0" data-cod="<?= $cod ?>" data-lado="<?= $lado ?>"
                   value="<?= $pre ?>" oninput="calc()">
        </div>
        <?php endforeach; ?>
        <div class="cap"><span>Capital social (3115) — calculado</span><span id="cap">$0</span></div>
    </div>

    <button class="btn" id="go" onclick="guardar()">Registrar balance de apertura</button>
</main>
<div class="toast" id="toast"></div>
<script>
const CSRF = <?= json_encode($csrf) ?>;
function fmt(n){ return '$'+Math.round(n).toLocaleString('es-CO'); }
function toast(m,t){const e=document.getElementById('toast');e.textContent=m;e.className='toast '+(t||'');e.style.display='block';setTimeout(()=>e.style.display='none',4200);}
function filas(){ return [...document.querySelectorAll('input[data-cod]')]; }
function calc(){
    let deb=0, cred=0;
    filas().forEach(i=>{ const v=parseFloat(i.value)||0; if(i.dataset.lado==='activo') deb+=v; else cred+=v; });
    const capital = deb - cred; // cifra de cuadre
    document.getElementById('cap').textContent = fmt(capital) + (capital<0?' (déficit)':'');
    return capital;
}
async function guardar(){
    const saldos = filas().map(i=>({codigo:i.dataset.cod, saldo:parseFloat(i.value)||0})).filter(s=>s.saldo!==0);
    if(!saldos.length){ toast('Ingresa al menos un saldo','err'); return; }
    if(!confirm('¿Registrar el balance de apertura con estos saldos?')) return;
    const btn=document.getElementById('go'); btn.disabled=true;
    const fd=new FormData(); fd.append('csrf_token',CSRF); fd.append('accion','apertura');
    fd.append('fecha',document.getElementById('ap-fecha').value);
    fd.append('saldos',JSON.stringify(saldos));
    try{
        const r=await fetch('api/contab.php',{method:'POST',body:fd});
        const d=await r.json();
        if(d.success){ toast('Apertura registrada. Capital: '+fmt(d.capital),'ok'); setTimeout(()=>location.href='balance.php',1200); }
        else { toast(d.error||'Error','err'); btn.disabled=false; }
    }catch(e){ toast('Error de red','err'); btn.disabled=false; }
}
calc();
</script></body></html>
