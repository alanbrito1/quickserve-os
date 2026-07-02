<?php
/**
 * productos/simulador.php — Simulador de escenarios "¿qué pasa si…?" (Fase 3).
 *
 * Sin tocar datos reales: el usuario cambia el costo de uno o varios insumos
 * (p. ej. cambio de proveedor o subida de precio) y ve al instante, del lado
 * del cliente:
 *   - el costo de receta recalculado de CADA producto que usa ese insumo,
 *   - el margen actual vs. proyectado por producto,
 *   - el impacto en la utilidad bruta mensual (ponderada por las unidades
 *     vendidas en los últimos 30 días — la mezcla real reciente).
 *
 * Fórmula reusada del costeo real:
 *   costo_receta_u = SUM(insumo.costo × receta.cantidad_requerida) ÷ unidades_por_receta
 */
require_once __DIR__ . '/../app/middleware/auth_check.php';

$nav_activo = 'productos';
permiso_requerir('productos', 'solo_ver');

// ── Productos activos con receta ────────────────────────────────────────────────
$productos = db()->query(
    "SELECT id, nombre, nombre2, precio_venta,
            GREATEST(1, IFNULL(unidades_por_receta,1)) AS rinde,
            IFNULL(costo_calculado,0) AS costo_actual
     FROM productos WHERE activo = 1 ORDER BY nombre"
)->fetchAll();

// ── Recetas (producto → insumos) ────────────────────────────────────────────────
$recetas = db()->query(
    "SELECT producto_id, insumo_id, cantidad_requerida FROM recetas"
)->fetchAll();

// ── Insumos usados en alguna receta (id, nombre, costo, unidad) ─────────────────
$insumos = db()->query(
    "SELECT DISTINCT i.id, i.nombre, i.unidad_medida, IFNULL(i.costo_actual,0) AS costo_actual
     FROM insumos i
     JOIN recetas r ON r.insumo_id = i.id
     WHERE i.activo = 1
     ORDER BY i.nombre"
)->fetchAll();

// ── Unidades vendidas por producto en los últimos 30 días (mezcla real) ─────────
$ventas30 = db()->query(
    "SELECT vd.producto_id, SUM(vd.cantidad) AS u
     FROM venta_detalles vd JOIN ventas v ON v.id = vd.venta_id
     WHERE v.estado <> 'anulada' AND v.metodo_pago <> 'obsequio'
       AND v.fecha_venta >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
     GROUP BY vd.producto_id"
)->fetchAll(PDO::FETCH_KEY_PAIR);

// Estructuras para JS
$PRODUCTOS = array_map(fn($p) => [
    'id'     => (int)$p['id'],
    'nombre' => $p['nombre'] . (!empty($p['nombre2']) ? ' · ' . $p['nombre2'] : ''),
    'precio' => (float)$p['precio_venta'],
    'rinde'  => (int)$p['rinde'],
    'costoActual' => round((float)$p['costo_actual'], 4),
    'u30'    => (int)($ventas30[$p['id']] ?? 0),
], $productos);

$RECETAS = [];
foreach ($recetas as $r) {
    $RECETAS[(int)$r['producto_id']][] = [
        'insumo' => (int)$r['insumo_id'],
        'cant'   => (float)$r['cantidad_requerida'],
    ];
}

$INSUMOS = array_map(fn($i) => [
    'id'     => (int)$i['id'],
    'nombre' => $i['nombre'],
    'unidad' => $i['unidad_medida'],
    'costo'  => round((float)$i['costo_actual'], 4),
], $insumos);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Simulador de escenarios — <?= APP_NAME ?></title>
    <style>
        :root { --brand:#e94f37; --dark:#111827; --g5:#6b7280; --g8:#d1d5db; --g9:#f3f4f6; --white:#fff; --green:#059669; --red:#dc2626; }
        *,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
        body { font-family:system-ui,-apple-system,sans-serif; background:var(--g9); color:var(--dark); }
        .main { max-width:1080px; margin:0 auto; padding:20px 14px 60px; }
        .page-title { font-size:22px; font-weight:800; }
        .page-sub { font-size:13px; color:var(--g5); margin:3px 0 16px; }
        .grid2 { display:grid; grid-template-columns:340px 1fr; gap:16px; align-items:start; }
        @media(max-width:820px){ .grid2 { grid-template-columns:1fr; } }
        .card { background:var(--white); border:1px solid var(--g8); border-radius:14px; padding:16px; margin-bottom:16px; }
        .card-title { font-size:13px; font-weight:800; text-transform:uppercase; letter-spacing:.4px; color:var(--g5); margin-bottom:10px; display:flex; justify-content:space-between; align-items:center; gap:8px; }
        .btn-sm { padding:5px 10px; font-size:12px; font-weight:700; border:1px solid var(--g8); border-radius:8px; background:var(--g9); cursor:pointer; }
        .tbl { width:100%; border-collapse:collapse; font-size:13px; }
        .tbl th { background:var(--g9); font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.3px; padding:7px 8px; text-align:left; color:var(--g5); position:sticky; top:0; }
        .tbl th.r,.tbl td.r { text-align:right; font-variant-numeric:tabular-nums; }
        .tbl td { padding:7px 8px; border-bottom:1px solid var(--g9); }
        .ins-list { max-height:520px; overflow:auto; }
        .ins-row { display:grid; grid-template-columns:1fr 92px; gap:8px; align-items:center; padding:7px 0; border-bottom:1px solid var(--g9); }
        .ins-row .nm { font-size:13px; } .ins-row .nm small { color:var(--g5); }
        .ins-row input { width:100%; padding:6px 8px; border:1px solid var(--g8); border-radius:7px; font-size:13px; text-align:right; }
        .ins-row input.chg { border-color:var(--brand); background:#fff7f5; font-weight:700; }
        .kpis { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:10px; margin-bottom:14px; }
        .kpi { background:var(--white); border:1px solid var(--g8); border-radius:12px; padding:12px 14px; }
        .kpi-val { font-size:19px; font-weight:800; } .kpi-lbl { font-size:10.5px; color:var(--g5); margin-top:3px; text-transform:uppercase; }
        .pos { color:var(--green); } .neg { color:var(--red); }
        .badge { font-size:11px; font-weight:700; padding:1px 6px; border-radius:6px; }
        .b-up { background:#fee2e2; color:#991b1b; } .b-down { background:#d1fae5; color:#065f46; } .b-eq { color:#9ca3af; }
    </style>
</head>
<body>
<?php include __DIR__ . '/../app/views/nav.php'; ?>
<main class="main">
    <h1 class="page-title">🧪 Simulador de escenarios</h1>
    <p class="page-sub">Cambia el costo de un insumo (ej. otro proveedor) y mira el impacto en el costo, margen y utilidad — <strong>sin tocar los datos reales</strong>. Ponderado por lo vendido en 30 días.</p>

    <div class="kpis" id="kpis"></div>

    <div class="grid2">
        <div class="card">
            <div class="card-title"><span>Costo de insumos</span><button class="btn-sm" onclick="resetSim()">↺ Restablecer</button></div>
            <div class="ins-list" id="insList"></div>
        </div>
        <div class="card">
            <div class="card-title">Impacto por producto</div>
            <div style="overflow-x:auto">
            <table class="tbl">
                <thead><tr>
                    <th>Producto</th><th class="r">Precio</th><th class="r">Costo actual</th>
                    <th class="r">Costo proy.</th><th class="r">Margen actual</th><th class="r">Margen proy.</th>
                </tr></thead>
                <tbody id="prodBody"></tbody>
            </table>
            </div>
        </div>
    </div>
    <p class="page-sub" style="margin-top:6px">La utilidad bruta mensual proyectada = Σ (precio − costo proyectado) × unidades vendidas (30 días). No incluye costos fijos ni nómina (eso lo ves en Análisis y P&G).</p>
</main>

<script>
const INSUMOS   = <?= json_encode($INSUMOS, JSON_UNESCAPED_UNICODE) ?>;
const RECETAS   = <?= json_encode($RECETAS) ?>;
const PRODUCTOS = <?= json_encode($PRODUCTOS, JSON_UNESCAPED_UNICODE) ?>;

// Mapa de costos simulados (arranca con el costo actual de cada insumo)
const costoSim = {};
INSUMOS.forEach(i => costoSim[i.id] = i.costo);

function fmt(n){ return (Math.round(n)).toLocaleString('es-CO'); }
function fmt2(n){ return n.toLocaleString('es-CO',{minimumFractionDigits:2,maximumFractionDigits:2}); }

// Costo de receta de un producto con los costos simulados actuales
function costoReceta(p){
    const ings = RECETAS[p.id] || [];
    let c = 0;
    ings.forEach(r => { c += (costoSim[r.insumo] ?? 0) * r.cant; });
    return c / Math.max(1, p.rinde);
}

function pctMargen(precio, costo){ return precio > 0 ? (precio - costo) / precio * 100 : 0; }

function render(){
    let utilAct = 0, utilProy = 0;
    const tb = document.getElementById('prodBody'); tb.innerHTML = '';
    PRODUCTOS.forEach(p => {
        const cProy = costoReceta(p);
        const mAct  = pctMargen(p.precio, p.costoActual);
        const mProy = pctMargen(p.precio, cProy);
        utilAct  += (p.precio - p.costoActual) * p.u30;
        utilProy += (p.precio - cProy)         * p.u30;
        const dMarg = mProy - mAct;
        const bcls = Math.abs(dMarg) < 0.05 ? 'b-eq' : (dMarg < 0 ? 'b-up' : 'b-down');
        const arrow = Math.abs(dMarg) < 0.05 ? '' : (dMarg < 0 ? '▼' : '▲');
        const tr = document.createElement('tr');
        tr.innerHTML = `<td>${p.nombre}</td>
            <td class="r">$${fmt(p.precio)}</td>
            <td class="r">$${fmt(p.costoActual)}</td>
            <td class="r">$${fmt(cProy)}</td>
            <td class="r">${mAct.toFixed(1)}%</td>
            <td class="r"><span class="badge ${bcls}">${mProy.toFixed(1)}% ${arrow}</span></td>`;
        tb.appendChild(tr);
    });

    const dUtil = utilProy - utilAct;
    const dcls = Math.abs(dUtil) < 1 ? '' : (dUtil < 0 ? 'neg' : 'pos');
    document.getElementById('kpis').innerHTML =
        `<div class="kpi"><div class="kpi-val">$${fmt(utilAct)}</div><div class="kpi-lbl">Utilidad bruta / mes (actual)</div></div>
         <div class="kpi"><div class="kpi-val ${dcls}">$${fmt(utilProy)}</div><div class="kpi-lbl">Utilidad bruta / mes (proyectada)</div></div>
         <div class="kpi"><div class="kpi-val ${dcls}">${dUtil>=0?'+':''}$${fmt(dUtil)}</div><div class="kpi-lbl">Impacto mensual</div></div>`;
}

function renderInsumos(){
    const cont = document.getElementById('insList'); cont.innerHTML = '';
    INSUMOS.forEach(i => {
        const row = document.createElement('div'); row.className = 'ins-row';
        const chg = costoSim[i.id] !== i.costo;
        row.innerHTML = `<div class="nm">${i.nombre} <small>($/${i.unidad})</small></div>
            <input type="number" step="any" min="0" value="${costoSim[i.id]}" class="${chg?'chg':''}"
                   oninput="setCosto(${i.id}, this.value, this)">`;
        cont.appendChild(row);
    });
}

function setCosto(id, val, el){
    const v = parseFloat(val);
    costoSim[id] = isNaN(v) ? 0 : v;
    const base = INSUMOS.find(x => x.id === id).costo;
    el.classList.toggle('chg', costoSim[id] !== base);
    render();
}

function resetSim(){
    INSUMOS.forEach(i => costoSim[i.id] = i.costo);
    renderInsumos(); render();
}

renderInsumos(); render();
</script>
</body>
</html>
