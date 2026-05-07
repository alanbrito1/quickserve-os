<?php
/**
 * public_html/productos/index.php
 * Módulo Productos: catálogo, recetas editables y costo/margen completo.
 *
 * COSTO TOTAL/u = Insumos + Depreciación activos/u + RH/u + Fijos/u
 * MARGEN        = Precio venta - Costo total
 */

require_once __DIR__ . '/../app/middleware/auth_check.php';
require_once __DIR__ . '/../app/models/RecetaModel.php';
require_once __DIR__ . '/../app/models/InsumoModel.php';
require_once __DIR__ . '/../app/models/ActivoModel.php';

$nav_activo = 'productos';
permiso_requerir('productos', 'solo_ver');

// ── Parámetros de costo fijo/u ───────────────────────────────────────────────
$cfg = db()->query(
    "SELECT clave, valor FROM configuracion_negocio
     WHERE clave IN ('costos_fijos_mensuales','produccion_estimada_mensual')"
)->fetchAll(PDO::FETCH_KEY_PAIR);

$costos_fijos = (float)($cfg['costos_fijos_mensuales']     ?? 365185);
$prod_mes     = (float)($cfg['produccion_estimada_mensual'] ?? 2175);
$prod_dia     = max(1, round($prod_mes / 21.75));

$costo_fijo_u   = $prod_mes > 0 ? round($costos_fijos / $prod_mes, 4) : 0;
$dep_diaria     = ActivoModel::costo_diario_total();
$costo_deprec_u = $prod_dia  > 0 ? round($dep_diaria   / $prod_dia, 4) : 0;

// Costo RH: último período de nómina disponible
$nomina_row = db()->query(
    "SELECT SUM(costo_total_empleador) AS total
     FROM nomina_liquidaciones
     WHERE (periodo_anio * 100 + periodo_mes) = (
         SELECT MAX(periodo_anio * 100 + periodo_mes) FROM nomina_liquidaciones
     )"
)->fetch();
$nomina_mensual = (float)($nomina_row['total'] ?? 0);
$costo_rh_u     = $prod_mes > 0 ? round($nomina_mensual / $prod_mes, 4) : 0;

// ── Productos con costos calculados ─────────────────────────────────────────
$productos = db()->query(
    "SELECT p.id, p.nombre, p.tamano, p.categoria, p.precio_venta, p.activo,
            IFNULL(p.costo_calculado, 0) AS costo_ing
     FROM productos p
     ORDER BY p.activo DESC, p.categoria, p.nombre,
              FIELD(p.tamano,'XL','L','unico')"
)->fetchAll();

$fijos_u = $costo_fijo_u + $costo_deprec_u + $costo_rh_u;
foreach ($productos as &$p) {
    $p['costo_total'] = round((float)$p['costo_ing'] + $fijos_u, 2);
    $precio = (float)$p['precio_venta'];
    $p['margen_pesos'] = $precio > 0 ? round($precio - $p['costo_total'], 2) : 0;
    $p['margen_pct']   = $precio > 0 ? round(($precio - $p['costo_total']) / $precio * 100, 1) : 0;
}
unset($p);

$insumos_todos = InsumoModel::todos();
$total_prods   = count($productos);
$buenos        = count(array_filter($productos, fn($p) => $p['margen_pct'] >= 40));
$en_riesgo     = count(array_filter($productos, fn($p) => $p['margen_pct'] < 20 && (float)$p['precio_venta'] > 0));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Productos — <?= APP_NAME ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root { --brand:#e94f37; --dark:#111827; --g2:#374151; --g5:#6b7280; --g8:#d1d5db; --g9:#f3f4f6; --white:#fff; --green:#059669; --yellow:#d97706; }
        body { font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; background:var(--g9); min-height:100vh; color:var(--dark); }
        .main { padding:16px 14px; max-width:1000px; margin:0 auto; }
        .stats { display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:16px; }
        @media(max-width:600px){ .stats { grid-template-columns:1fr 1fr; } }
        .stat { background:var(--white); border-radius:14px; padding:14px; box-shadow:0 1px 4px rgba(0,0,0,.06); }
        .stat-n { font-size:22px; font-weight:800; }
        .stat-l { font-size:11px; color:var(--g5); text-transform:uppercase; letter-spacing:.5px; }
        .banner { background:var(--dark); color:var(--white); border-radius:14px; padding:14px 18px; margin-bottom:16px; }
        .banner-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin-top:10px; }
        @media(max-width:540px){ .banner-grid { grid-template-columns:1fr 1fr; } }
        .bn-val { font-size:18px; font-weight:800; color:#fcd34d; }
        .bn-lbl { font-size:10px; color:#9ca3af; text-transform:uppercase; letter-spacing:.4px; margin-top:2px; }
        .card { background:var(--white); border-radius:14px; box-shadow:0 1px 4px rgba(0,0,0,.06); overflow:hidden; margin-bottom:16px; }
        .card-title { font-size:15px; font-weight:800; padding:14px 18px; border-bottom:1px solid var(--g9); display:flex; justify-content:space-between; align-items:center; }
        .btn-add { padding:8px 16px; background:var(--brand); color:var(--white); border:none; border-radius:10px; font-size:13px; font-weight:700; cursor:pointer; }
        table { width:100%; border-collapse:collapse; }
        th { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; color:var(--g5); padding:10px 14px; background:var(--g9); border-bottom:1px solid var(--g8); text-align:left; }
        th.r, td.r { text-align:right; }
        td { padding:11px 14px; border-bottom:1px solid var(--g9); font-size:13px; vertical-align:middle; }
        tr:last-child td { border-bottom:none; }
        @media(max-width:640px){ .hide-m { display:none; } }
        .mg-green  { color:var(--green); font-weight:800; }
        .mg-yellow { color:var(--yellow); font-weight:800; }
        .mg-red    { color:var(--brand); font-weight:800; }
        .mg-bar { display:inline-block; width:46px; height:5px; background:var(--g8); border-radius:3px; vertical-align:middle; margin-left:6px; overflow:hidden; }
        .mg-fill { height:100%; border-radius:3px; }
        .exp-btn { background:none; border:none; cursor:pointer; color:var(--g5); font-size:15px; padding:0 4px; }
        .exp-btn:hover { color:var(--brand); }
        .recipe-row { display:none; }
        .recipe-row.open { display:table-row; }
        .recipe-row > td { background:#fafafa; padding:14px 18px; }
        .rcp-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
        @media(max-width:520px){ .rcp-grid { grid-template-columns:1fr; } }
        .rcp-title { font-size:11px; font-weight:800; text-transform:uppercase; color:var(--g5); margin-bottom:8px; }
        .ing-table { width:100%; border-collapse:collapse; font-size:12px; }
        .ing-table th { font-size:10px; padding:5px 7px; background:var(--g9); border-bottom:1px solid var(--g8); }
        .ing-table td { padding:6px 7px; border-bottom:1px dashed var(--g8); }
        .ing-table tr:last-child td { border-bottom:none; }
        .badge-crit { font-size:9px; background:#fee2e2; color:#991b1b; padding:1px 5px; border-radius:20px; margin-left:3px; }
        .add-row { display:flex; gap:6px; flex-wrap:wrap; align-items:flex-end; padding-top:10px; border-top:1px solid var(--g8); margin-top:8px; }
        .add-row select, .add-row input { padding:7px 10px; border:2px solid var(--g8); border-radius:8px; font-size:13px; outline:none; color:var(--dark); }
        .add-row select:focus, .add-row input:focus { border-color:var(--brand); }
        .btn-sm { padding:7px 12px; border:none; border-radius:8px; font-size:12px; font-weight:700; cursor:pointer; }
        .btn-grn { background:var(--green); color:#fff; }
        .btn-red { background:#fee2e2; color:#991b1b; }
        .cost-row { display:flex; justify-content:space-between; font-size:13px; padding:4px 0; border-bottom:1px dashed var(--g8); }
        .cost-row:last-child { border-bottom:none; font-weight:800; font-size:14px; }
        .precio-row { display:flex; gap:8px; margin-top:10px; padding-top:10px; border-top:1px solid var(--g8); align-items:center; }
        .precio-row input { padding:8px 12px; border:2px solid var(--g8); border-radius:8px; font-size:14px; width:150px; outline:none; }
        .precio-row input:focus { border-color:var(--brand); }
        /* Modal */
        .overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:60; align-items:center; justify-content:center; padding:16px; }
        .overlay.on { display:flex; }
        .modal { background:var(--white); border-radius:16px; padding:24px; width:100%; max-width:440px; }
        .modal-hdr { font-size:16px; font-weight:800; margin-bottom:16px; display:flex; justify-content:space-between; }
        .fg { display:flex; flex-direction:column; gap:4px; margin-bottom:12px; }
        .fg label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--g5); }
        .fg input, .fg select { padding:10px 12px; border:2px solid var(--g8); border-radius:10px; font-size:15px; color:var(--dark); outline:none; width:100%; }
        .btn-cls { background:var(--g9); border:none; color:var(--g5); width:30px; height:30px; border-radius:50%; cursor:pointer; font-size:16px; }
        .btn-full { width:100%; padding:12px; background:var(--brand); color:#fff; border:none; border-radius:10px; font-size:15px; font-weight:800; cursor:pointer; }
        /* Toast */
        .toast { position:fixed; bottom:20px; left:50%; transform:translateX(-50%) translateY(20px); padding:10px 20px; border-radius:24px; font-size:14px; font-weight:600; opacity:0; transition:.25s; z-index:99; pointer-events:none; }
        .toast.on { opacity:1; transform:translateX(-50%) translateY(0); }
        .toast-ok  { background:#065f46; color:#d1fae5; }
        .toast-err { background:#991b1b; color:#fee2e2; }
    </style>
</head>
<body>

<?php include __DIR__ . '/../app/views/nav.php'; ?>

<main class="main">

    <div class="stats">
        <div class="stat"><div class="stat-n"><?= $total_prods ?></div><div class="stat-l">Productos</div></div>
        <div class="stat"><div class="stat-n" style="color:var(--green)"><?= $buenos ?></div><div class="stat-l">Margen ≥40%</div></div>
        <div class="stat"><div class="stat-n" style="color:var(--brand)"><?= $en_riesgo ?></div><div class="stat-l">En riesgo</div></div>
        <div class="stat"><div class="stat-n">$<?= number_format($dep_diaria,2,',','.') ?></div><div class="stat-l">Dep. diaria</div></div>
    </div>

    <div class="banner">
        <strong style="font-size:13px">Costos fijos prorrateados por unidad producida</strong>
        <div class="banner-grid">
            <div><div class="bn-val">$<?= number_format($costo_fijo_u,0,',','.') ?></div><div class="bn-lbl">Arriendo/Servicios</div></div>
            <div><div class="bn-val">$<?= number_format($costo_deprec_u,0,',','.') ?></div><div class="bn-lbl">Depreciación</div></div>
            <div><div class="bn-val">$<?= number_format($costo_rh_u,0,',','.') ?></div><div class="bn-lbl">Recurso Humano</div></div>
            <div><div class="bn-val">$<?= number_format($fijos_u,0,',','.') ?></div><div class="bn-lbl">Total fijos/u</div></div>
        </div>
    </div>

    <div class="card">
        <div class="card-title">
            Catálogo de Productos
            <?php if (permiso_tiene('productos','editar_existentes')): ?>
            <button class="btn-add" onclick="document.getElementById('modal-np').classList.add('on')">+ Nuevo</button>
            <?php endif; ?>
        </div>
        <table>
            <thead><tr>
                <th>Producto</th>
                <th class="r hide-m">Precio</th>
                <th class="r hide-m">Insumos</th>
                <th class="r hide-m">Fijos+RH</th>
                <th class="r">Costo Total</th>
                <th class="r">Margen</th>
                <th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($productos as $p):
                $mg = $p['margen_pct'];
                $mc = $mg >= 40 ? 'mg-green' : ($mg >= 20 ? 'mg-yellow' : 'mg-red');
                $fc = $mg >= 40 ? '#059669' : ($mg >= 20 ? '#d97706' : '#e94f37');
            ?>
            <tr <?= !$p['activo'] ? 'style="opacity:.45"' : '' ?>>
                <td>
                    <strong><?= htmlspecialchars($p['nombre']) ?></strong>
                    <?php if ($p['tamano'] !== 'unico'): ?>
                    <span style="font-size:10px;background:var(--g9);padding:1px 7px;border-radius:20px;margin-left:5px;font-weight:700"><?= $p['tamano'] ?></span>
                    <?php endif; ?>
                </td>
                <td class="r hide-m"><?= (float)$p['precio_venta'] > 0 ? '$'.number_format($p['precio_venta'],0,',','.') : '<em style="color:var(--g5)">—</em>' ?></td>
                <td class="r hide-m">$<?= number_format($p['costo_ing'],0,',','.') ?></td>
                <td class="r hide-m">$<?= number_format($fijos_u,0,',','.') ?></td>
                <td class="r"><strong>$<?= number_format($p['costo_total'],0,',','.') ?></strong></td>
                <td class="r">
                    <?php if ((float)$p['precio_venta'] > 0): ?>
                    <span class="<?= $mc ?>"><?= $mg ?>%</span>
                    <span class="mg-bar"><span class="mg-fill" style="width:<?= max(0,min(100,$mg)) ?>%;background:<?= $fc ?>"></span></span>
                    <?php else: ?><span style="color:var(--g5)">—</span><?php endif; ?>
                </td>
                <td><button class="exp-btn" onclick="toggleReceta(<?= $p['id'] ?>,<?= $p['precio_venta'] ?>)">▾</button></td>
            </tr>
            <tr class="recipe-row" id="rr-<?= $p['id'] ?>">
                <td colspan="7">
                    <div id="rc-<?= $p['id'] ?>"><em style="color:var(--g5);font-size:13px">Cargando…</em></div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</main>

<!-- Modal nuevo producto -->
<div class="overlay" id="modal-np" onclick="if(event.target===this)this.classList.remove('on')">
    <div class="modal">
        <div class="modal-hdr">
            Nuevo Producto
            <button class="btn-cls" onclick="document.getElementById('modal-np').classList.remove('on')">✕</button>
        </div>
        <div class="fg"><label>Nombre *</label><input id="np-nom" placeholder="Ej: El Desechado"></div>
        <div class="fg"><label>Categoría</label>
            <select id="np-cat"><option value="sandwich">Sándwich</option><option value="combo">Combo</option><option value="bebida">Bebida</option><option value="adicional">Adicional</option></select>
        </div>
        <div class="fg"><label>Tamaño</label>
            <select id="np-tam"><option value="unico">Único</option><option value="XL">XL</option><option value="L">L</option></select>
        </div>
        <div class="fg"><label>Precio de Venta ($)</label><input type="number" id="np-precio" min="0" step="500" placeholder="18000"></div>
        <button class="btn-full" onclick="guardarNuevoProducto()">Guardar Producto</button>
    </div>
</div>

<div class="toast" id="toast"></div>
<input type="hidden" id="csrf-tk" value="<?= htmlspecialchars(csrf_token()) ?>">

<script>
const INSUMOS = <?= json_encode(array_map(
    fn($i) => ['id'=>(int)$i['id'],'nombre'=>$i['nombre'],'unidad'=>$i['unidad_medida'],'costo'=>(float)$i['costo_actual']],
    $insumos_todos
), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>;

const FIJOS_U = <?= json_encode(['fijo'=>$costo_fijo_u,'deprec'=>$costo_deprec_u,'rh'=>$costo_rh_u,'total'=>$fijos_u]) ?>;

const cache = {};
const csrf  = () => document.getElementById('csrf-tk').value;

// ── Expandir fila de receta ─────────────────────────────────────────────────
async function toggleReceta(id, precio) {
    const row = document.getElementById('rr-' + id);
    const btn = row.previousElementSibling?.querySelector('.exp-btn');
    row.classList.toggle('open');
    if (btn) btn.textContent = row.classList.contains('open') ? '▴' : '▾';
    if (!row.classList.contains('open') || cache[id]) return;

    const resp = await fetch('api/ingredientes.php?producto_id=' + id);
    const ings = await resp.json();
    cache[id] = true;

    renderReceta(id, ings, precio);
}

function renderReceta(id, ings, precio) {
    let totalIng = 0;
    const puedEdt = <?= permiso_tiene('productos','editar_existentes') ? 'true' : 'false' ?>;

    let tblRows = '';
    ings.forEach(i => {
        totalIng += parseFloat(i.costo_linea || 0);
        const crit = i.es_insumo_critico == 1 ? '<span class="badge-crit">⚡crítico</span>' : '';
        tblRows += `<tr>
            <td>${esc(i.nombre)}${crit}</td>
            <td>${(+i.cantidad_requerida).toFixed(4)} ${esc(i.unidad_medida)}</td>
            <td style="text-align:right">$${fmt(i.costo_actual)}</td>
            <td style="text-align:right"><strong>$${fmt(i.costo_linea)}</strong></td>
            ${puedEdt ? `<td><button class="btn-sm btn-red" onclick="delIng(${id},${i.insumo_id})">✕</button></td>` : ''}
        </tr>`;
    });

    // Formulario para agregar ingrediente
    const optIns = INSUMOS.map(i => `<option value="${i.id}" data-costo="${i.costo}" data-u="${esc(i.unidad)}">${esc(i.nombre)} (${esc(i.unidad)})</option>`).join('');
    const addForm = puedEdt ? `
        <div class="add-row">
            <select id="si-${id}">${optIns}</select>
            <input type="number" id="ci-${id}" placeholder="Cantidad" step="0.001" min="0.001" style="width:110px">
            <label style="font-size:12px;display:flex;align-items:center;gap:4px;color:var(--g5)">
                <input type="checkbox" id="krit-${id}"> Crítico
            </label>
            <button class="btn-sm btn-grn" onclick="addIng(${id})">+ Agregar</button>
        </div>` : '';

    // Desglose de costos
    const costoTotal = totalIng + FIJOS_U.total;
    const costBreak = `
        <div class="cost-row"><span>Insumos (receta)</span><span>$${fmt(totalIng)}</span></div>
        <div class="cost-row"><span>Depreciación activos</span><span>$${fmt(FIJOS_U.deprec)}</span></div>
        <div class="cost-row"><span>Recurso humano</span><span>$${fmt(FIJOS_U.rh)}</span></div>
        <div class="cost-row"><span>Arriendo/servicios</span><span>$${fmt(FIJOS_U.fijo)}</span></div>
        <div class="cost-row"><span>COSTO TOTAL/u</span><span>$${fmt(costoTotal)}</span></div>`;

    const precioForm = puedEdt ? `
        <div class="precio-row">
            <label style="font-size:12px;font-weight:700;color:var(--g5)">Precio de venta:</label>
            <input type="number" id="pv-${id}" value="${precio||''}" step="500" placeholder="$18000">
            <button class="btn-sm btn-grn" onclick="savePrecio(${id})">Guardar</button>
        </div>` : '';

    document.getElementById('rc-' + id).innerHTML = `
        <div class="rcp-grid">
            <div>
                <p class="rcp-title">Ingredientes de la receta</p>
                <table class="ing-table">
                    <thead><tr><th>Ingrediente</th><th>Cantidad</th><th style="text-align:right">$/u</th><th style="text-align:right">Subtotal</th>${puedEdt?'<th></th>':''}</tr></thead>
                    <tbody>${tblRows || '<tr><td colspan="5" style="text-align:center;color:var(--g5);padding:16px">Sin ingredientes configurados</td></tr>'}</tbody>
                </table>
                ${addForm}
            </div>
            <div>
                <p class="rcp-title">Desglose de costo</p>
                ${costBreak}
                ${precioForm}
            </div>
        </div>`;
}

// ── CRUD de ingredientes ──────────────────────────────────────────────────────
async function addIng(prodId) {
    const insumoId = document.getElementById('si-' + prodId).value;
    const cantidad = document.getElementById('ci-' + prodId).value;
    const critico  = document.getElementById('krit-' + prodId).checked ? 1 : 0;
    if (!cantidad || parseFloat(cantidad) <= 0) { toast('Cantidad inválida', 'err'); return; }
    const fd = new FormData();
    fd.append('csrf_token', csrf()); fd.append('accion','guardar');
    fd.append('producto_id', prodId); fd.append('insumo_id', insumoId);
    fd.append('cantidad', cantidad); fd.append('es_critico', critico);
    const r = await fetch('api/guardar_receta.php', {method:'POST',body:fd});
    const d = await r.json();
    if (d.success) { delete cache[prodId]; toast('Guardado', 'ok'); reloadReceta(prodId); }
    else toast(d.error || 'Error', 'err');
}

async function delIng(prodId, insumoId) {
    if (!confirm('¿Quitar este ingrediente?')) return;
    const fd = new FormData();
    fd.append('csrf_token', csrf()); fd.append('accion','eliminar');
    fd.append('producto_id', prodId); fd.append('insumo_id', insumoId);
    const r = await fetch('api/guardar_receta.php', {method:'POST',body:fd});
    const d = await r.json();
    if (d.success) { delete cache[prodId]; toast('Eliminado', 'ok'); reloadReceta(prodId); }
    else toast(d.error || 'Error', 'err');
}

async function savePrecio(prodId) {
    const precio = document.getElementById('pv-' + prodId).value;
    if (!precio || parseFloat(precio) < 0) { toast('Precio inválido', 'err'); return; }
    const fd = new FormData();
    fd.append('csrf_token', csrf()); fd.append('accion','precio');
    fd.append('producto_id', prodId); fd.append('precio', precio);
    const r = await fetch('api/guardar_producto.php', {method:'POST',body:fd});
    const d = await r.json();
    if (d.success) { toast('Precio actualizado', 'ok'); setTimeout(() => location.reload(), 1000); }
    else toast(d.error || 'Error', 'err');
}

async function reloadReceta(id) {
    document.getElementById('rr-' + id).classList.remove('open');
    const btn = document.getElementById('rr-' + id).previousElementSibling?.querySelector('.exp-btn');
    if (btn) btn.textContent = '▾';
}

// ── Nuevo producto ────────────────────────────────────────────────────────────
async function guardarNuevoProducto() {
    const nombre = document.getElementById('np-nom').value.trim();
    if (!nombre) { toast('Nombre obligatorio', 'err'); return; }
    const fd = new FormData();
    fd.append('csrf_token', csrf()); fd.append('accion','crear');
    fd.append('nombre',   nombre);
    fd.append('categoria',document.getElementById('np-cat').value);
    fd.append('tamano',   document.getElementById('np-tam').value);
    fd.append('precio',   document.getElementById('np-precio').value || 0);
    const r = await fetch('api/guardar_producto.php', {method:'POST',body:fd});
    const d = await r.json();
    if (d.success) { toast('Producto creado', 'ok'); setTimeout(() => location.reload(), 1000); }
    else toast(d.error || 'Error', 'err');
}

function fmt(n) { return Math.round(parseFloat(n)||0).toLocaleString('es-CO'); }
function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
let tt;
function toast(m, t) {
    const el = document.getElementById('toast');
    el.textContent = m; el.className = 'toast toast-' + t + ' on';
    clearTimeout(tt); tt = setTimeout(() => el.classList.remove('on'), 3200);
}
document.addEventListener('keydown', e => {
    if (e.key==='Escape') document.querySelectorAll('.overlay.on').forEach(o => o.classList.remove('on'));
});
</script>
</body>
</html>
