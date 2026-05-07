<?php
/**
 * public_html/inventario/compras.php
 * Registro de compras de insumos.
 * Al guardar: actualiza costo_actual, stock_actual y recalcula productos afectados.
 */

require_once __DIR__ . '/../app/middleware/auth_check.php';
require_once __DIR__ . '/../app/models/CompraModel.php';
require_once __DIR__ . '/../app/models/InsumoModel.php';

permiso_requerir('compras', 'solo_propios');

$insumos   = InsumoModel::todos();
$compras   = CompraModel::historial_agrupado(); // agrupado por fecha y lugar
$msg_ok    = '';
$msg_err   = '';

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verificar()) {
        $msg_err = 'Token de seguridad inválido.';
    } else {
        $lineas_raw   = $_POST['lineas']       ?? [];
        $proveedor_id = !empty($_POST['proveedor_id']) ? (int)$_POST['proveedor_id'] : null;
        $notas        = trim($_POST['notas']   ?? '');
        $lugar_compra = trim($_POST['lugar_compra'] ?? '');

        // Sanitizar líneas: ignorar filas vacías
        $lineas = [];
        foreach ($lineas_raw as $l) {
            $iid    = (int)($l['insumo_id']      ?? 0);
            $cant   = (float)str_replace(',', '.', $l['cantidad']       ?? '0');
            $precio = (float)str_replace(',', '.', $l['precio_unitario'] ?? '0');
            if ($iid > 0 && $cant > 0 && $precio > 0) {
                $lineas[] = ['insumo_id' => $iid, 'cantidad' => $cant, 'precio_unitario' => $precio];
            }
        }

        if (empty($lineas)) {
            $msg_err = 'Agrega al menos un ítem válido (insumo, cantidad y precio requeridos).';
        } else {
            try {
                $id = CompraModel::crear($lineas, $proveedor_id, $notas, $lugar_compra);
                $msg_ok  = "Compra #$id registrada. Stock e insumos actualizados.";
                $compras = CompraModel::historial_agrupado(); // refrescar listado
            } catch (RuntimeException $e) {
                $msg_err = $e->getMessage();
            }
        }
    }
}

// Proveedores para el select
$proveedores = db()->query('SELECT id, nombre FROM proveedores WHERE activo=1 ORDER BY nombre')->fetchAll();

// Serializar insumos para el JS
$insumos_js = json_encode(array_map(
    fn($i) => ['id' => $i['id'], 'nombre' => $i['nombre'], 'unidad' => $i['unidad_medida'], 'costo' => (float)$i['costo_actual']],
    $insumos
), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Compras — <?= APP_NAME ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root { --brand:#e94f37; --dark:#111827; --g2:#374151; --g5:#6b7280; --g8:#d1d5db; --g9:#f3f4f6; --white:#fff; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background:var(--g9); min-height:100vh; color:var(--dark); }

        .hdr { background:var(--dark); color:var(--white); height:54px; padding:0 14px; display:flex; align-items:center; justify-content:space-between; position:sticky; top:0; z-index:50; box-shadow:0 2px 8px rgba(0,0,0,.3); }
        .brand { font-size:17px; font-weight:800; } .brand span{color:var(--brand);}
        .nav { display:flex; gap:6px; }
        .nl { color:var(--g8); text-decoration:none; font-size:13px; padding:5px 10px; border-radius:8px; }
        .nl:hover { background:var(--g2); color:var(--white); } .nl.act { background:var(--brand); color:var(--white); }

        .main { padding:16px 14px; max-width:900px; margin:0 auto; }
        .card { background:var(--white); border-radius:14px; padding:20px; margin-bottom:16px; box-shadow:0 1px 4px rgba(0,0,0,.06); }
        .card-title { font-size:16px; font-weight:800; margin-bottom:16px; }

        .alert { padding:12px 14px; border-radius:10px; font-size:14px; margin-bottom:14px; }
        .alert-ok  { background:#d1fae5; color:#065f46; border:1px solid #6ee7b7; }
        .alert-err { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; }

        /* Formulario */
        .fg { display:flex; flex-direction:column; gap:5px; margin-bottom:14px; }
        .fg label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--g5); }
        .fg input, .fg select, .fg textarea { padding:10px 12px; border:2px solid var(--g8); border-radius:10px; font-size:15px; color:var(--dark); width:100%; outline:none; -webkit-appearance:none; }
        .fg input:focus, .fg select:focus { border-color:var(--brand); }
        .top-row { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        @media(max-width:480px){ .top-row { grid-template-columns:1fr; } }

        /* Líneas de compra */
        .linea {
            display:grid;
            grid-template-columns: 2fr 1fr 1fr auto auto;
            gap:8px;
            align-items:center;
            padding:10px 0;
            border-bottom:1px dashed var(--g8);
        }
        @media(max-width:600px){
            .linea { grid-template-columns:1fr 1fr; }
            .linea select { grid-column:1/-1; }
        }
        .linea select, .linea input { padding:9px 10px; border:2px solid var(--g8); border-radius:9px; font-size:14px; color:var(--dark); outline:none; width:100%; }
        .linea select:focus, .linea input:focus { border-color:var(--brand); }
        .sub-val { font-size:13px; font-weight:700; color:var(--brand); white-space:nowrap; min-width:80px; text-align:right; }
        .btn-rm { background:var(--g9); color:var(--brand); border:none; width:30px; height:30px; border-radius:50%; cursor:pointer; font-size:18px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
        .btn-rm:hover { background:#fee2e2; }

        .add-linea-btn { padding:9px 16px; background:var(--g9); color:var(--g2); border:1px solid var(--g8); border-radius:10px; font-size:13px; font-weight:700; cursor:pointer; margin-top:8px; }
        .add-linea-btn:hover { border-color:var(--brand); color:var(--brand); }

        .total-row { display:flex; justify-content:flex-end; align-items:center; gap:10px; padding:14px 0 0; border-top:2px solid var(--dark); margin-top:8px; }
        .total-row span { font-size:13px; color:var(--g5); }
        .total-row strong { font-size:20px; font-weight:800; }

        .btn-guardar { width:100%; padding:14px; background:var(--brand); color:var(--white); border:none; border-radius:12px; font-size:16px; font-weight:800; cursor:pointer; margin-top:16px; }
        .btn-guardar:hover { background:#c73d28; }

        /* Historial */
        .historial-row { display:flex; justify-content:space-between; align-items:center; padding:10px 0; border-bottom:1px solid var(--g9); font-size:14px; }
        .historial-row:last-child { border-bottom:none; }
        .hist-fecha { font-size:12px; color:var(--g5); }
        .hist-total { font-weight:800; }
    </style>
</head>
<body>
<?php $nav_activo = 'inventario'; include __DIR__ . '/../app/views/nav.php'; ?>


<main class="main">

    <?php if ($msg_ok):  ?><div class="alert alert-ok"><?= htmlspecialchars($msg_ok) ?></div><?php endif; ?>
    <?php if ($msg_err): ?><div class="alert alert-err"><?= htmlspecialchars($msg_err) ?></div><?php endif; ?>

    <!-- Formulario nueva compra -->
    <div class="card">
        <p class="card-title">Registrar Nueva Compra</p>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">

            <div class="top-row">
                <div class="fg">
                    <label>Proveedor (opcional)</label>
                    <select name="proveedor_id">
                        <option value="">— Sin proveedor —</option>
                        <?php foreach ($proveedores as $p): ?>
                        <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fg">
                    <label>Lugar de Compra</label>
                    <input type="text" name="lugar_compra" placeholder="Ej: Plaza minorista, D1, Almacenes...">
                </div>
                <div class="fg">
                    <label>Notas (opcional)</label>
                    <input type="text" name="notas" placeholder="Ej: factura #123, cotización...">
                </div>
            </div>

            <!-- Líneas de la compra -->
            <div id="lineas-container"></div>

            <button type="button" class="add-linea-btn" onclick="agregarLinea()">+ Agregar ítem</button>

            <div class="total-row">
                <span>Total compra:</span>
                <strong id="total-compra">$0</strong>
            </div>

            <button type="submit" class="btn-guardar">Guardar Compra y Actualizar Stock</button>
        </form>
    </div>

    <!-- Historial reciente -->
    <div class="card">
        <p class="card-title">Últimas Compras</p>
        <?php if (empty($compras)): ?>
        <p style="color:var(--g5); text-align:center; padding:20px 0">Sin compras registradas</p>
        <?php else: ?>
            <?php foreach ($compras as $cv): ?>
            <div class="historial-row" style="display:block;padding:10px 0;border-bottom:1px solid var(--g9)">
                <div style="display:flex;justify-content:space-between;align-items:flex-start">
                    <div>
                        <div style="font-weight:700"><?= htmlspecialchars($cv['proveedor']) ?></div>
                        <div class="hist-fecha">
                            <?= date('d/m/Y H:i', strtotime($cv['fecha_compra'])) ?>
                            <?php if (!empty($cv['lugar_compra'])): ?> · <strong><?= htmlspecialchars($cv['lugar_compra']) ?></strong><?php endif; ?>
                            · <?= $cv['num_lineas'] ?> ítem<?= $cv['num_lineas'] != 1 ? 's' : '' ?>
                            · <?= htmlspecialchars($cv['registrado_por'] ?? '—') ?>
                        </div>
                    </div>
                    <div class="hist-total">$<?= number_format($cv['total'], 0, ',', '.') ?></div>
                </div>
                <?php if (!empty($cv['lineas'])): ?>
                <div style="margin-top:6px;padding-left:8px;border-left:3px solid var(--g8)">
                    <?php foreach ($cv['lineas'] as $lin): ?>
                    <div style="font-size:12px;color:var(--g5);display:flex;justify-content:space-between">
                        <span><?= htmlspecialchars($lin['insumo']) ?> (<?= $lin['cantidad'] ?> <?= $lin['unidad_medida'] ?>)</span>
                        <span>$<?= number_format($lin['subtotal'], 0, ',', '.') ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</main>

<script>
const INSUMOS = <?= $insumos_js ?>;
let lineaCount = 0;

// Generar las opciones del select de insumos una sola vez
const opcionesInsumos = '<option value="">— Seleccionar insumo —</option>'
    + INSUMOS.map(i =>
        `<option value="${i.id}" data-costo="${i.costo}" data-unidad="${escHtml(i.unidad)}">
            ${escHtml(i.nombre)} (${escHtml(i.unidad)})
         </option>`
    ).join('');

// Agregar al menos una línea al cargar la página
window.addEventListener('DOMContentLoaded', () => agregarLinea());

function agregarLinea() {
    lineaCount++;
    const n = lineaCount;
    const div = document.createElement('div');
    div.className = 'linea';
    div.id = 'linea-' + n;
    div.innerHTML = `
        <select name="lineas[${n}][insumo_id]" onchange="sugerirPrecio(${n})" required>
            ${opcionesInsumos}
        </select>
        <input type="number" name="lineas[${n}][cantidad]"
               placeholder="Cantidad" min="0.001" step="0.001"
               oninput="calcSubtotal(${n})" required>
        <input type="number" name="lineas[${n}][precio_unitario]"
               placeholder="Precio/u" min="1" step="1"
               oninput="calcSubtotal(${n})" required>
        <span class="sub-val" id="sub-${n}">$0</span>
        <button type="button" class="btn-rm" onclick="quitarLinea(${n})">−</button>
    `;
    document.getElementById('lineas-container').appendChild(div);
}

function quitarLinea(n) {
    const el = document.getElementById('linea-' + n);
    if (el && document.querySelectorAll('.linea').length > 1) {
        el.remove();
        recalcularTotal();
    }
}

// Al seleccionar un insumo, rellenar el precio con el costo actual como sugerencia
function sugerirPrecio(n) {
    const sel   = document.querySelector(`[name="lineas[${n}][insumo_id]"]`);
    const opt   = sel.options[sel.selectedIndex];
    const precio = document.querySelector(`[name="lineas[${n}][precio_unitario]"]`);
    if (opt && opt.dataset.costo > 0) {
        precio.value = opt.dataset.costo;
        calcSubtotal(n);
    }
}

function calcSubtotal(n) {
    const cant   = parseFloat(document.querySelector(`[name="lineas[${n}][cantidad]"]`)?.value)        || 0;
    const precio = parseFloat(document.querySelector(`[name="lineas[${n}][precio_unitario]"]`)?.value) || 0;
    const sub    = cant * precio;
    const el     = document.getElementById('sub-' + n);
    if (el) el.textContent = formatPeso(sub);
    recalcularTotal();
}

function recalcularTotal() {
    let total = 0;
    document.querySelectorAll('.linea').forEach(linea => {
        const cant   = parseFloat(linea.querySelector('[name*="cantidad"]')?.value)        || 0;
        const precio = parseFloat(linea.querySelector('[name*="precio_unitario"]')?.value) || 0;
        total += cant * precio;
    });
    document.getElementById('total-compra').textContent = formatPeso(total);
}

function formatPeso(n) {
    return '$' + Math.round(n).toLocaleString('es-CO');
}
function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
</body>
</html>
