<?php
/**
 * public_html/inventario/conteo.php
 * Conteo rápido de stock: permite ingresar el stock real de todos los insumos
 * en una sola pantalla y guardar con un click. Útil para cierres diarios o
 * auditorías rápidas sin editar insumo por insumo.
 */

require_once __DIR__ . '/../app/middleware/auth_check.php';
require_once __DIR__ . '/../app/models/InsumoModel.php';
require_once __DIR__ . '/../app/models/PresentacionModel.php';

$nav_activo = 'inventario';
permiso_requerir('inventario', 'editar_existentes');

// Categorías para filtrar columnas
$insumos = InsumoModel::todos_con_estado();

// Catálogo de presentaciones de compra (mig 039) por insumo — permite el
// helper "Convertir desde presentación" (ej. "conté 3 bidones de 18L").
$pres_por_insumo = PresentacionModel::todas_agrupadas();
foreach ($insumos as &$ins_pc) {
    $ins_pc['pres_cat'] = array_map(fn($p) => [
        'nombre'        => $p['nombre'],
        'cantidad_base' => (float)$p['cantidad_base'],
    ], $pres_por_insumo[$ins_pc['id']] ?? []);
}
unset($ins_pc);

// Agrupar por categoría para mostrar secciones
$grupos = [];
foreach ($insumos as $ins) {
    $cat = $ins['categoria'] ?? 'otro';
    $grupos[$cat][] = $ins;
}
ksort($grupos);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Conteo de Stock — <?= APP_NAME ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --brand:#e94f37; --dark:#111827; --g2:#374151; --g5:#6b7280;
            --g8:#d1d5db; --g9:#f3f4f6; --white:#fff;
            --green:#059669; --yellow:#d97706;
        }
        body { font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
               background:var(--g9); min-height:100vh; color:var(--dark); padding-bottom:80px; }
        .main { padding:16px 14px; max-width:860px; margin:0 auto; }
        h1 { font-size:20px; font-weight:800; margin-bottom:4px; }
        .sub { font-size:13px; color:var(--g5); margin-bottom:16px; }
        .act-bar { display:flex; gap:8px; margin-bottom:14px; align-items:center; flex-wrap:wrap; }
        .btn-primary { padding:9px 22px; background:var(--brand); color:#fff; border:none;
                       border-radius:10px; font-size:14px; font-weight:700; cursor:pointer; }
        .btn-primary:hover { opacity:.9; }
        .btn-sec { padding:8px 16px; background:var(--white); color:var(--dark);
                   border:1px solid var(--g8); border-radius:10px; font-size:13px;
                   font-weight:700; text-decoration:none; cursor:pointer; }
        .btn-sec:hover { border-color:var(--brand); color:var(--brand); }
        .chip-filtro { padding:5px 12px; border:1px solid var(--g8); border-radius:99px;
                       font-size:12px; font-weight:600; cursor:pointer; background:var(--white);
                       color:var(--g5); transition:.15s; }
        .chip-filtro.active { background:var(--brand); color:#fff; border-color:var(--brand); }
        /* Sección por categoría */
        .cat-section { margin-bottom:20px; }
        .cat-header { font-size:11px; font-weight:700; text-transform:uppercase;
                      letter-spacing:.6px; color:var(--g5); padding:6px 0 6px 2px;
                      border-bottom:2px solid var(--g8); margin-bottom:8px; }
        /* Grilla de tarjetas de insumo */
        .ins-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:10px; }
        .ins-card { background:var(--white); border-radius:12px; padding:12px 14px;
                    box-shadow:0 1px 3px rgba(0,0,0,.07); border:2px solid transparent;
                    transition:border-color .15s; }
        .ins-card.modificado { border-color:var(--brand); }
        .ins-card.sin-cambio  { border-color:transparent; }
        .ins-nombre { font-size:13px; font-weight:700; margin-bottom:2px;
                      white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .ins-meta { font-size:11px; color:var(--g5); margin-bottom:8px; }
        .ins-input-wrap { display:flex; align-items:center; gap:6px; }
        .ins-stock-actual { font-size:11px; color:var(--g5); }
        .ins-input { width:100%; padding:8px 10px; border:2px solid var(--g8);
                     border-radius:8px; font-size:15px; font-weight:700; text-align:right;
                     color:var(--dark); outline:none; -webkit-appearance:none; background:var(--white); }
        .ins-input:focus { border-color:var(--brand); }
        .ins-unidad { font-size:11px; font-weight:600; color:var(--g5); white-space:nowrap; }
        /* Convertir desde presentación (mig 039) — ej. "conté 3 bidones de 18L" */
        .ins-pres-conv { display:flex; gap:4px; margin-top:6px; }
        .ins-pres-conv select { flex:2; min-width:0; font-size:10px; padding:4px 6px;
                                 border:1px solid var(--g8); border-radius:6px;
                                 background:var(--white); color:var(--g5); -webkit-appearance:none; }
        .ins-pres-conv input { flex:1; min-width:0; font-size:11px; padding:4px 6px;
                                border:1px solid var(--g8); border-radius:6px; text-align:right; }
        /* Badge estado */
        .badge { font-size:10px; font-weight:700; padding:2px 7px; border-radius:20px; display:inline-block; }
        .b-ok   { background:#d1fae5; color:#065f46; }
        .b-bajo { background:#fef3c7; color:#92400e; }
        .b-ago  { background:#fee2e2; color:#991b1b; }
        /* Barra flotante de acción */
        .bar-flotante { position:fixed; bottom:0; left:0; right:0; background:var(--white);
                        border-top:2px solid var(--g8); padding:12px 20px;
                        display:flex; align-items:center; justify-content:space-between;
                        box-shadow:0 -4px 12px rgba(0,0,0,.08); z-index:50; }
        .bar-info { font-size:13px; color:var(--g5); }
        .bar-info strong { color:var(--dark); }
        /* Toast */
        #toast { position:fixed; bottom:80px; left:50%; transform:translateX(-50%);
                 background:var(--dark); color:#fff; padding:10px 20px;
                 border-radius:10px; font-size:13px; font-weight:600;
                 display:none; z-index:100; white-space:nowrap; }
        #toast.ok  { background:#059669; }
        #toast.err { background:#dc2626; }
        /* Búsqueda */
        .search-wrap { position:relative; flex:1; max-width:280px; }
        .search-wrap input { width:100%; padding:8px 10px 8px 32px;
                             border:1px solid var(--g8); border-radius:10px;
                             font-size:13px; outline:none; background:var(--white); }
        .search-wrap input:focus { border-color:var(--brand); }
        .search-wrap::before { content:'🔍'; position:absolute; left:9px; top:50%;
                               transform:translateY(-50%); font-size:13px; }
        @media (max-width:500px) {
            .ins-grid { grid-template-columns:1fr 1fr; }
        }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/../app/views/nav.php'; ?>

<div class="main">
    <h1>📋 Conteo de Stock</h1>
    <p class="sub">Ingresa el stock real de cada insumo. Solo se guardan los que cambien.</p>

    <div class="act-bar">
        <a href="<?= APP_BASE ?>/inventario/" class="btn-sec">← Inventario</a>
        <div class="search-wrap">
            <input type="text" id="buscador" placeholder="Buscar insumo…" oninput="filtrarInsumos()">
        </div>
        <button class="chip-filtro active" data-cat="todos" onclick="filtrarCat(this,'todos')">Todos</button>
        <?php foreach (array_keys($grupos) as $cat): ?>
        <button class="chip-filtro" data-cat="<?= htmlspecialchars($cat) ?>"
                onclick="filtrarCat(this,<?= htmlspecialchars(json_encode($cat), ENT_QUOTES) ?>)">
            <?= htmlspecialchars(ucfirst($cat)) ?>
        </button>
        <?php endforeach; ?>
    </div>

    <form id="form-conteo">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">

        <?php foreach ($grupos as $cat => $ins_list): ?>
        <div class="cat-section" data-cat="<?= htmlspecialchars($cat) ?>">
            <div class="cat-header"><?= htmlspecialchars(ucfirst($cat)) ?> (<?= count($ins_list) ?>)</div>
            <div class="ins-grid">
                <?php foreach ($ins_list as $ins): ?>
                <?php
                $est = $ins['estado'] ?? 'ok';
                $stock_actual = (float)$ins['stock_actual'];
                ?>
                <div class="ins-card" id="card-<?= $ins['id'] ?>"
                     data-id="<?= $ins['id'] ?>"
                     data-nombre="<?= htmlspecialchars(mb_strtolower($ins['nombre'])) ?>"
                     data-cat="<?= htmlspecialchars($cat) ?>"
                     data-stock="<?= $stock_actual ?>">
                    <div class="ins-nombre" title="<?= htmlspecialchars($ins['nombre']) ?>">
                        <?= htmlspecialchars($ins['nombre']) ?>
                    </div>
                    <div class="ins-meta">
                        <span class="badge b-<?= $est ?>"><?= $est === 'ok' ? 'OK' : strtoupper($est) ?></span>
                        &nbsp;Actual: <strong><?= fmt_cantidad($stock_actual) ?></strong> <?= htmlspecialchars($ins['unidad_medida']) ?>
                    </div>
                    <div class="ins-input-wrap">
                        <input type="number" class="ins-input"
                               id="inp-<?= $ins['id'] ?>"
                               step="any" min="0"
                               placeholder="<?= number_format($stock_actual, config_numeros()['decimales'], '.', '') ?>"
                               oninput="marcarCambio(<?= $ins['id'] ?>, <?= $stock_actual ?>)"
                               data-insumo-id="<?= $ins['id'] ?>">
                        <span class="ins-unidad"><?= htmlspecialchars($ins['unidad_medida']) ?></span>
                    </div>
                    <?php if (!empty($ins['pres_cat'])): ?>
                    <div class="ins-pres-conv">
                        <select id="pc-sel-<?= $ins['id'] ?>" title="Convertir desde presentación">
                            <?php foreach ($ins['pres_cat'] as $p): ?>
                            <option value="<?= $p['cantidad_base'] ?>">
                                <?= htmlspecialchars($p['nombre']) ?> (<?= fmt_cantidad($p['cantidad_base']) ?> <?= htmlspecialchars($ins['unidad_medida']) ?>/u)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="number" placeholder="Nro." min="0" step="0.01"
                               id="pc-num-<?= $ins['id'] ?>"
                               oninput="convertirDesdePresentacionConteo(<?= $ins['id'] ?>)">
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </form>
</div>

<!-- Barra flotante -->
<div class="bar-flotante">
    <div class="bar-info">
        Modificados: <strong id="n-modificados">0</strong>
        &nbsp;/&nbsp; <?= count($insumos) ?> insumos
    </div>
    <div style="display:flex;gap:8px">
        <button class="btn-sec" onclick="limpiarCambios()">Limpiar</button>
        <button class="btn-primary" onclick="guardar()" id="btn-guardar" disabled>
            Guardar conteo
        </button>
    </div>
</div>

<div id="toast"></div>

<script>
const CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
const API_URL    = <?= json_encode(APP_BASE . '/inventario/api/conteo_guardar.php') ?>;

let catActiva = 'todos';
let modificados = new Set();

// ── Convertir desde presentación (mig 039) ──────────────────────────────────
// "Conté 3 bidones de 18L" → llena el campo de stock contado con 3 × 18 = 54.
function convertirDesdePresentacionConteo(id) {
    const sel = document.getElementById('pc-sel-' + id);
    const num = document.getElementById('pc-num-' + id);
    const cantBase = parseFloat(sel.value) || 0;
    const nro      = parseFloat(num.value) || 0;
    if (cantBase <= 0 || nro <= 0) return;
    const inp  = document.getElementById('inp-' + id);
    const card = document.getElementById('card-' + id);
    inp.value = (nro * cantBase).toFixed(NUM_FORMAT.decimales);
    marcarCambio(id, parseFloat(card.dataset.stock));
}

function marcarCambio(id, stockAnterior) {
    const inp = document.getElementById('inp-' + id);
    const card = document.getElementById('card-' + id);
    const val = inp.value.trim();

    if (val === '' || parseFloat(val) === stockAnterior) {
        modificados.delete(id);
        card.className = 'ins-card sin-cambio';
    } else {
        modificados.add(id);
        card.className = 'ins-card modificado';
    }

    actualizarContador();
}

function actualizarContador() {
    document.getElementById('n-modificados').textContent = modificados.size;
    document.getElementById('btn-guardar').disabled = modificados.size === 0;
}

function limpiarCambios() {
    document.querySelectorAll('.ins-input').forEach(inp => {
        inp.value = '';
        const id = parseInt(inp.dataset.insumoId);
        modificados.delete(id);
        const card = document.getElementById('card-' + id);
        if (card) card.className = 'ins-card sin-cambio';
    });
    actualizarContador();
}

async function guardar() {
    if (modificados.size === 0) return;

    const btn = document.getElementById('btn-guardar');
    btn.disabled = true;
    btn.textContent = 'Guardando…';

    const conteos = [];
    modificados.forEach(id => {
        const inp = document.getElementById('inp-' + id);
        const val = parseFloat(inp.value);
        if (!isNaN(val) && val >= 0) {
            conteos.push({ insumo_id: id, stock_contado: val });
        }
    });

    try {
        const res = await fetch(API_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: CSRF_TOKEN, conteos })
        });
        const json = await res.json();

        if (json.success) {
            toast(`✅ ${json.actualizados} insumo(s) actualizados`, 'ok');
            // Actualizar los placeholders y datos con los nuevos valores
            conteos.forEach(c => {
                const card = document.getElementById('card-' + c.insumo_id);
                const inp  = document.getElementById('inp-' + c.insumo_id);
                if (card) {
                    card.dataset.stock = c.stock_contado;
                    card.className = 'ins-card sin-cambio';
                    const meta = card.querySelector('.ins-meta');
                    if (meta) {
                        const strong = meta.querySelector('strong');
                        if (strong) strong.textContent = formatDecimal(c.stock_contado);
                    }
                    inp.placeholder = c.stock_contado.toFixed(NUM_FORMAT.decimales);
                    inp.value = '';
                }
            });
            modificados.clear();
            actualizarContador();
            if (json.errores && json.errores.length > 0) {
                toast('⚠️ ' + json.errores.join(', '), 'err');
            }
        } else {
            toast('❌ ' + (json.error || 'Error al guardar'), 'err');
        }
    } catch (e) {
        toast('❌ Error de red', 'err');
    } finally {
        btn.disabled = modificados.size === 0;
        btn.textContent = 'Guardar conteo';
    }
}

function filtrarCat(el, cat) {
    document.querySelectorAll('.chip-filtro').forEach(c => c.classList.remove('active'));
    el.classList.add('active');
    catActiva = cat;
    aplicarFiltros();
}

function filtrarInsumos() {
    aplicarFiltros();
}

function aplicarFiltros() {
    const q = document.getElementById('buscador').value.trim().toLowerCase();

    document.querySelectorAll('.cat-section').forEach(section => {
        let secVisible = false;
        const cards = section.querySelectorAll('.ins-card');

        cards.forEach(card => {
            const matchCat    = catActiva === 'todos' || card.dataset.cat === catActiva;
            const matchBusq   = !q || card.dataset.nombre.includes(q);
            const visible     = matchCat && matchBusq;
            card.style.display = visible ? '' : 'none';
            if (visible) secVisible = true;
        });

        section.style.display = secVisible ? '' : 'none';
    });
}

function toast(msg, tipo) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = tipo;
    t.style.display = 'block';
    clearTimeout(t._to);
    t._to = setTimeout(() => { t.style.display = 'none'; }, 3500);
}

// Atajos de teclado: Enter avanza al siguiente input visible
document.addEventListener('keydown', e => {
    if (e.key !== 'Enter') return;
    const inputs = Array.from(document.querySelectorAll('.ins-input'))
        .filter(el => el.closest('.ins-card').style.display !== 'none');
    const idx = inputs.indexOf(document.activeElement);
    if (idx >= 0 && idx < inputs.length - 1) {
        e.preventDefault();
        inputs[idx + 1].focus();
        inputs[idx + 1].select();
    }
});
</script>
</body>
</html>
