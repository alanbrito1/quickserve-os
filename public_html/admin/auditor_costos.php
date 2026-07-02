<?php
/**
 * admin/auditor_costos.php — Auditor de la cadena de costos (solo superadmin).
 *
 * Diagnóstico de "datos exactos" (Fase 1 contable): detecta lo que contamina
 * el costo → margen → punto de equilibrio → P&G, para corregirlo:
 *   1. Insumos cuyo costo_actual no coincide con su presentación predeterminada
 *      (precio_referencia ÷ cantidad_base, mig. 039).
 *   2. Productos con receta pero costo_calculado = 0 (falta recalcular).
 *   3. Insumos con equivalencia física "a medias" (una columna sin la otra).
 *   4. Compras con snapshot de presentación incoherente (histórico informativo).
 *
 * Acción: "Recalcular costos" → productos/api/recalcular.php (RecetaModel).
 * Solo lectura + recalcular; no borra ni edita datos maestros.
 */
require_once __DIR__ . '/../app/middleware/auth_check.php';

$nav_activo = 'admin';
$nav_sub    = 'auditor';

if (($_SESSION['usuario_rol'] ?? '') !== 'superadmin') {
    http_response_code(403);
    include __DIR__ . '/../app/views/errors/403.php';
    exit;
}

$hayPres = false;
try {
    $hayPres = (int)db()->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='insumo_presentaciones'"
    )->fetchColumn() > 0;
} catch (\Throwable $e) {}

// 1. Insumos con costo desalineado de su presentación predeterminada
$costoDesalineado = [];
if ($hayPres) {
    $costoDesalineado = db()->query(
        "SELECT i.id, i.nombre, i.unidad_medida, i.costo_actual,
                p.precio_referencia, p.cantidad_base,
                ROUND(p.precio_referencia / p.cantidad_base, 4) AS costo_esperado
         FROM insumos i
         JOIN insumo_presentaciones p ON p.insumo_id = i.id
              AND p.es_predeterminada = 1 AND p.activo = 1
              AND p.precio_referencia IS NOT NULL AND p.precio_referencia > 0
              AND p.cantidad_base > 0
         WHERE i.activo = 1
           AND ABS(i.costo_actual - (p.precio_referencia / p.cantidad_base)) > 0.5
         ORDER BY i.nombre"
    )->fetchAll();
}

// 2. Productos activos con receta pero costo_calculado = 0
$productosSinCosto = db()->query(
    "SELECT p.id, p.nombre, p.nombre2
     FROM productos p
     WHERE p.activo = 1 AND IFNULL(p.costo_calculado,0) = 0
       AND EXISTS (SELECT 1 FROM recetas r WHERE r.producto_id = p.id)
     ORDER BY p.nombre"
)->fetchAll();

// 3. Insumos con equivalencia a medias
$equivMedias = db()->query(
    "SELECT id, nombre, equiv_cantidad, equiv_unidad FROM insumos
     WHERE activo = 1
       AND ((equiv_cantidad IS NOT NULL AND equiv_cantidad > 0 AND (equiv_unidad IS NULL OR equiv_unidad = ''))
         OR ((equiv_cantidad IS NULL OR equiv_cantidad = 0) AND equiv_unidad IS NOT NULL AND equiv_unidad <> ''))
     ORDER BY nombre"
)->fetchAll();

// 4. Compras con presentación incoherente (histórico, informativo)
$comprasIncoherentes = 0;
try {
    $comprasIncoherentes = (int)db()->query(
        "SELECT COUNT(*) FROM compra_detalles
         WHERE (cant_presentaciones IS NOT NULL AND cant_presentaciones > 0
                AND cantidad_presentacion IS NOT NULL AND cantidad_presentacion > 0
                AND ABS(cantidad - (cant_presentaciones * cantidad_presentacion)) > 1)"
    )->fetchColumn();
} catch (\Throwable $e) {}

$totalProblemas = count($costoDesalineado) + count($productosSinCosto) + count($equivMedias);
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Auditor de costos — <?= APP_NAME ?></title>
    <style>
        :root { --brand:#e94f37; --dark:#111827; --g5:#6b7280; --g8:#d1d5db; --g9:#f3f4f6; --white:#fff; --green:#059669; --red:#dc2626; }
        *,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
        body { font-family:system-ui,-apple-system,sans-serif; background:var(--g9); color:var(--dark); }
        .main { max-width:920px; margin:0 auto; padding:20px 14px 60px; }
        .page-title { font-size:22px; font-weight:800; }
        .page-sub { font-size:13px; color:var(--g5); margin:3px 0 16px; }
        .card { background:var(--white); border:1px solid var(--g8); border-radius:14px; padding:18px; margin-bottom:16px; }
        .card-title { font-size:14px; font-weight:800; margin-bottom:4px; display:flex; align-items:center; gap:8px; }
        .card-desc { font-size:12.5px; color:var(--g5); margin-bottom:12px; }
        .ok-box { background:#d1fae5; border:1px solid #6ee7b7; color:#065f46; border-radius:10px; padding:14px; font-size:14px; font-weight:600; }
        .tbl { width:100%; border-collapse:collapse; font-size:13px; }
        .tbl th { background:var(--g9); font-size:10px; font-weight:700; text-transform:uppercase; padding:7px 9px; text-align:left; color:var(--g5); }
        .tbl th.r,.tbl td.r { text-align:right; font-variant-numeric:tabular-nums; }
        .tbl td { padding:8px 9px; border-bottom:1px solid var(--g9); }
        .badge { font-size:11px; font-weight:700; padding:2px 8px; border-radius:20px; }
        .b-red { background:#fee2e2; color:#991b1b; } .b-amber { background:#fef3c7; color:#92400e; }
        .btn { padding:9px 16px; border:none; border-radius:8px; font-size:13px; font-weight:700; cursor:pointer; text-decoration:none; display:inline-block; }
        .btn-brand { background:var(--brand); color:#fff; } .btn-back { background:#374151; color:#fff; }
        .toast { position:fixed; bottom:20px; left:50%; transform:translateX(-50%); background:#111827; color:#fff; padding:11px 18px; border-radius:10px; font-size:13px; display:none; }
        .toast.ok { background:#065f46; } .toast.err { background:#991b1b; }
    </style>
</head>
<body>
<?php include __DIR__ . '/../app/views/nav.php'; ?>
<main class="main">
    <h1 class="page-title">🔎 Auditor de costos</h1>
    <p class="page-sub">Diagnóstico de la cadena de costos (insumo → presentación → costo → margen → P&amp;G). Solo lectura + recalcular.</p>

    <?php if ($totalProblemas === 0): ?>
    <div class="ok-box">✓ Sin incoherencias en la cadena de costos. Todos los costos están alineados.</div>
    <?php endif; ?>

    <!-- 1. Costo desalineado de su presentación -->
    <div class="card">
        <div class="card-title">1. Insumos con costo desalineado de su presentación
            <?php if ($costoDesalineado): ?><span class="badge b-red"><?= count($costoDesalineado) ?></span><?php endif; ?>
        </div>
        <div class="card-desc">El <code>costo_actual</code> no coincide con <code>precio_referencia ÷ cantidad_base</code> de la presentación predeterminada. Puede ser un override manual legítimo o un dato viejo. Corrige editando el insumo o su presentación en Inventario.</div>
        <?php if (!$hayPres): ?>
            <p style="font-size:13px;color:var(--g5)">Migración 039 (presentaciones) no aplicada — no se puede comparar.</p>
        <?php elseif (!$costoDesalineado): ?>
            <p style="font-size:13px;color:var(--green)">✓ Ninguno.</p>
        <?php else: ?>
            <div style="overflow-x:auto"><table class="tbl">
                <thead><tr><th>Insumo</th><th class="r">Costo actual</th><th class="r">Esperado</th><th class="r">Presentación</th></tr></thead>
                <tbody><?php foreach ($costoDesalineado as $r): ?>
                    <tr><td><?= htmlspecialchars($r['nombre']) ?></td>
                        <td class="r">$<?= fmt_cantidad((float)$r['costo_actual']) ?>/<?= htmlspecialchars($r['unidad_medida']) ?></td>
                        <td class="r">$<?= fmt_cantidad((float)$r['costo_esperado']) ?></td>
                        <td class="r"><?= fmt_cantidad((float)$r['precio_referencia'],0) ?> ÷ <?= fmt_cantidad((float)$r['cantidad_base']) ?></td></tr>
                <?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>
    </div>

    <!-- 2. Productos con receta y costo 0 -->
    <div class="card">
        <div class="card-title">2. Productos con receta pero costo en $0
            <?php if ($productosSinCosto): ?><span class="badge b-red"><?= count($productosSinCosto) ?></span><?php endif; ?>
        </div>
        <div class="card-desc">Tienen ingredientes pero <code>costo_calculado = 0</code> → márgenes y P&amp;G saldrán inflados. Casi siempre se arregla con "Recalcular costos".</div>
        <?php if (!$productosSinCosto): ?>
            <p style="font-size:13px;color:var(--green)">✓ Ninguno.</p>
        <?php else: ?>
            <ul style="font-size:13px;margin-left:18px"><?php foreach ($productosSinCosto as $p): ?>
                <li><?= htmlspecialchars($p['nombre'] . (!empty($p['nombre2']) ? ' · '.$p['nombre2'] : '')) ?></li>
            <?php endforeach; ?></ul>
            <button class="btn btn-brand" style="margin-top:12px" onclick="recalcular(this)">↻ Recalcular todos los costos</button>
        <?php endif; ?>
    </div>

    <!-- 3. Equivalencia a medias -->
    <div class="card">
        <div class="card-title">3. Insumos con equivalencia física a medias
            <?php if ($equivMedias): ?><span class="badge b-amber"><?= count($equivMedias) ?></span><?php endif; ?>
        </div>
        <div class="card-desc">Tienen la unidad equivalente pero no la cantidad (o al revés). No rompe cálculos (la conversión exige ambos), pero es dato incompleto. Reedita el insumo para completarlo o quitarlo.</div>
        <?php if (!$equivMedias): ?>
            <p style="font-size:13px;color:var(--green)">✓ Ninguno.</p>
        <?php else: ?>
            <ul style="font-size:13px;margin-left:18px"><?php foreach ($equivMedias as $e): ?>
                <li><?= htmlspecialchars($e['nombre']) ?> — cant: <?= $e['equiv_cantidad'] ?: '—' ?>, unidad: <?= htmlspecialchars($e['equiv_unidad'] ?: '—') ?></li>
            <?php endforeach; ?></ul>
        <?php endif; ?>
    </div>

    <!-- 4. Compras incoherentes (informativo) -->
    <div class="card">
        <div class="card-title">4. Compras con presentación incoherente (histórico)</div>
        <div class="card-desc">Snapshots de empaque de compras viejas donde <code>cant × cantidad_presentación ≠ cantidad</code>. Son inmutables (no afectan totales ni costos actuales); solo informativo.</div>
        <p style="font-size:13px;color:<?= $comprasIncoherentes>0?'#92400e':'var(--green)' ?>">
            <?= $comprasIncoherentes>0 ? $comprasIncoherentes.' líneas históricas (sin acción).' : '✓ Ninguna.' ?></p>
    </div>

    <a class="btn btn-back" href="<?= APP_BASE ?>/admin/">← Volver a Admin</a>
</main>
<div class="toast" id="toast"></div>
<script>
const CSRF = <?= json_encode($csrf) ?>;
function toast(m,t){const e=document.getElementById('toast');e.textContent=m;e.className='toast '+(t||'');e.style.display='block';setTimeout(()=>e.style.display='none',4000);}
async function recalcular(btn){
    if(!confirm('¿Recalcular el costo de TODOS los productos activos desde sus recetas?')) return;
    btn.disabled=true;
    const fd=new FormData(); fd.append('csrf_token',CSRF);
    try{
        const r=await fetch('<?= APP_BASE ?>/productos/api/recalcular.php',{method:'POST',body:fd});
        const d=await r.json();
        if(d.success){ toast('Costos recalculados: '+d.actualizados+' producto(s).','ok'); setTimeout(()=>location.reload(),1000); }
        else { toast(d.error||'Error','err'); btn.disabled=false; }
    }catch(e){ toast('Error de red','err'); btn.disabled=false; }
}
</script>
</body>
</html>
