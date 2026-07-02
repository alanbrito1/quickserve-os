<?php
/**
 * contabilidad/libro_diario.php — Libro diario: asientos con su detalle (Fase 4a).
 * Filtro por fecha/origen; reversar (contra-asiento) los no anulados.
 */
require_once __DIR__ . '/../app/middleware/auth_check.php';
require_once __DIR__ . '/../app/models/ContabilidadModel.php';

$nav_activo = 'contabilidad';
$nav_sub    = 'diario';
if (!in_array($_SESSION['usuario_rol'] ?? '', ['admin', 'superadmin'], true)) {
    http_response_code(403); include __DIR__ . '/../app/views/errors/403.php'; exit;
}
if (!ContabilidadModel::existe()) { include __DIR__ . '/_sin_migracion.php'; exit; }

$desde = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['desde'] ?? '') ? $_GET['desde'] : date('Y-m-01');
$hasta = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['hasta'] ?? '') ? $_GET['hasta'] : date('Y-m-d');

$st = db()->prepare(
    "SELECT a.id, a.fecha, a.descripcion, a.origen, a.anulado,
            l.debe, l.haber, c.codigo, c.nombre AS cuenta
     FROM asientos a
     JOIN asiento_lineas l ON l.asiento_id = a.id
     JOIN cuentas_contables c ON c.id = l.cuenta_id
     WHERE a.fecha BETWEEN ? AND ?
     ORDER BY a.fecha DESC, a.id DESC, l.id"
);
$st->execute([$desde, $hasta]);
$rows = $st->fetchAll();
// Agrupar por asiento
$asientos = [];
foreach ($rows as $r) {
    $id = (int)$r['id'];
    if (!isset($asientos[$id])) {
        $asientos[$id] = ['id'=>$id,'fecha'=>$r['fecha'],'desc'=>$r['descripcion'],'origen'=>$r['origen'],'anulado'=>(int)$r['anulado'],'lineas'=>[]];
    }
    $asientos[$id]['lineas'][] = $r;
}
$csrf = csrf_token();
?>
<!DOCTYPE html><html lang="es"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Libro diario — <?= APP_NAME ?></title>
<style>
    :root{--brand:#e94f37;--dark:#111827;--g5:#6b7280;--g8:#d1d5db;--g9:#f3f4f6;--white:#fff;}
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
    body{font-family:system-ui,-apple-system,sans-serif;background:var(--g9);color:var(--dark);}
    .main{max-width:840px;margin:0 auto;padding:20px 14px 60px;}
    .page-title{font-size:22px;font-weight:800;}
    .filtros{display:flex;gap:8px;align-items:flex-end;background:var(--white);border:1px solid var(--g8);border-radius:12px;padding:12px 16px;margin:12px 0 16px;flex-wrap:wrap;}
    .fg{display:flex;flex-direction:column;gap:3px;} .fg label{font-size:11px;font-weight:700;color:var(--g5);text-transform:uppercase;}
    .fg input{padding:7px 10px;border:1px solid var(--g8);border-radius:8px;font-size:13px;}
    .btn{padding:8px 16px;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;color:#fff;background:var(--brand);}
    .as{background:var(--white);border:1px solid var(--g8);border-radius:12px;padding:12px 14px;margin-bottom:10px;}
    .as.anul{opacity:.5;}
    .as-h{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:6px;font-size:13px;}
    .as-h .d{font-weight:700;} .badge{font-size:10px;font-weight:700;padding:1px 7px;border-radius:20px;background:#e5e7eb;color:#374151;text-transform:uppercase;}
    .lt{width:100%;border-collapse:collapse;font-size:13px;} .lt td{padding:3px 6px;} .lt td.r{text-align:right;font-variant-numeric:tabular-nums;}
    .btn-rev{font-size:11px;padding:3px 9px;border:1px solid var(--g8);border-radius:7px;background:var(--g9);cursor:pointer;}
    .toast{position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:#111827;color:#fff;padding:11px 18px;border-radius:10px;font-size:13px;display:none;}
    .toast.ok{background:#065f46;} .toast.err{background:#991b1b;}
</style></head><body>
<?php include __DIR__ . '/../app/views/nav.php'; ?>
<main class="main">
    <h1 class="page-title">Libro diario</h1>
    <form class="filtros" method="get">
        <div class="fg"><label>Desde</label><input type="date" name="desde" value="<?= $desde ?>"></div>
        <div class="fg"><label>Hasta</label><input type="date" name="hasta" value="<?= $hasta ?>"></div>
        <button class="btn" type="submit">Ver</button>
    </form>

    <?php if (!$asientos): ?>
        <p style="color:var(--g5);font-size:14px">No hay asientos en el período.</p>
    <?php endif; ?>

    <?php foreach ($asientos as $a): ?>
    <div class="as <?= $a['anulado']?'anul':'' ?>">
        <div class="as-h">
            <span class="d">#<?= $a['id'] ?> · <?= $a['fecha'] ?> · <?= htmlspecialchars($a['desc']) ?></span>
            <span style="display:flex;gap:6px;align-items:center">
                <span class="badge"><?= htmlspecialchars($a['origen']) ?></span>
                <?php if (!$a['anulado'] && in_array($a['origen'], ['manual','apertura'], true)): ?>
                <button class="btn-rev" onclick="reversar(<?= $a['id'] ?>)">Reversar</button>
                <?php elseif ($a['anulado']): ?><span class="badge" style="background:#fee2e2;color:#991b1b">Anulado</span><?php endif; ?>
            </span>
        </div>
        <table class="lt"><?php foreach ($a['lineas'] as $l): ?>
            <tr><td><?= htmlspecialchars($l['codigo'].' · '.$l['cuenta']) ?></td>
                <td class="r"><?= (float)$l['debe']>0 ? '$'.fmt_moneda((float)$l['debe']) : '' ?></td>
                <td class="r"><?= (float)$l['haber']>0 ? '$'.fmt_moneda((float)$l['haber']) : '' ?></td></tr>
        <?php endforeach; ?></table>
    </div>
    <?php endforeach; ?>
</main>
<div class="toast" id="toast"></div>
<script>
const CSRF = <?= json_encode($csrf) ?>;
function toast(m,t){const e=document.getElementById('toast');e.textContent=m;e.className='toast '+(t||'');e.style.display='block';setTimeout(()=>e.style.display='none',4000);}
async function reversar(id){
    const motivo = prompt('Motivo de la reversa (opcional):','');
    if(motivo===null) return;
    const fd=new FormData(); fd.append('csrf_token',CSRF); fd.append('accion','reversar'); fd.append('asiento_id',id); fd.append('motivo',motivo);
    try{
        const r=await fetch('api/contab.php',{method:'POST',body:fd});
        const d=await r.json();
        if(d.success){ toast('Asiento reversado (#'+d.reversa+')','ok'); setTimeout(()=>location.reload(),900); }
        else toast(d.error||'Error','err');
    }catch(e){ toast('Error de red','err'); }
}
</script></body></html>
