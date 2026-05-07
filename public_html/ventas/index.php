<?php
/**
 * public_html/ventas/index.php
 * Interfaz principal del POS — Registro de ventas en tiempo real.
 * Mobile-First: optimizado para uso en teléfono durante el turno.
 */

require_once __DIR__ . '/../app/middleware/auth_check.php';
require_once __DIR__ . '/../app/models/VentaModel.php';
require_once __DIR__ . '/../app/models/ClienteModel.php';

permiso_requerir('ventas', 'solo_propios');

// Cargar datos para la vista
$productos = VentaModel::productos_con_capacidad();
$clientes  = ClienteModel::todos();
$resumen   = VentaModel::resumen_hoy();

// Agrupar productos por categoría para el grid
$por_categoria = [];
foreach ($productos as $p) {
    $por_categoria[$p['categoria']][] = $p;
}
$nav_activo = 'ventas';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>POS — <?= APP_NAME ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --brand:  #e94f37;
            --dark:   #111827;
            --g1:     #1f2937;
            --g2:     #374151;
            --g5:     #6b7280;
            --g8:     #d1d5db;
            --g9:     #f3f4f6;
            --white:  #ffffff;
            --green:  #059669;
            --yellow: #d97706;
            --r:      14px;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--g9);
            color: var(--dark);
            min-height: 100vh;
            /* Espacio para la barra sticky de cobrar */
            padding-bottom: 80px;
        }

        /* ---- HEADER ---- */
        .header {
            background: var(--dark);
            color: var(--white);
            height: 54px;
            padding: 0 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 50;
            box-shadow: 0 2px 8px rgba(0,0,0,.35);
        }
        .header-brand { font-size: 17px; font-weight: 800; letter-spacing: -.3px; }
        .header-brand span { color: var(--brand); }
        .header-nav { display: flex; gap: 6px; }
        .nav-link {
            color: var(--g8);
            text-decoration: none;
            font-size: 13px;
            padding: 5px 10px;
            border-radius: 8px;
            transition: background .15s;
        }
        .nav-link:hover     { background: var(--g2); color: var(--white); }
        .nav-link.active    { background: var(--brand); color: var(--white); }
        .nav-link.logout    { color: var(--g5); }

        /* ---- BARRA DE RESUMEN ---- */
        .resumen-bar {
            background: var(--white);
            border-bottom: 1px solid var(--g8);
            padding: 8px 14px;
            display: flex;
            align-items: center;
            gap: 16px;
            font-size: 12px;
            color: var(--g5);
            overflow-x: auto;
            white-space: nowrap;
        }
        .resumen-bar strong { color: var(--dark); }
        .resumen-sep { color: var(--g8); }

        /* ---- CONTENIDO PRINCIPAL ---- */
        .main { padding: 12px 12px 0; max-width: 900px; margin: 0 auto; }

        .section-label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .6px;
            color: var(--g5);
            margin: 14px 0 8px;
        }

        /* ---- GRID DE PRODUCTOS ---- */
        .productos-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }
        @media (min-width: 520px) { .productos-grid { grid-template-columns: repeat(3, 1fr); } }
        @media (min-width: 720px) { .productos-grid { grid-template-columns: repeat(4, 1fr); } }

        /* ---- TARJETA DE PRODUCTO ---- */
        .prod-card {
            background: var(--white);
            border-radius: var(--r);
            padding: 14px 12px 12px;
            border: 2px solid transparent;
            cursor: pointer;
            text-align: left;
            width: 100%;
            transition: box-shadow .15s, transform .1s, border-color .15s;
            position: relative;
            -webkit-tap-highlight-color: transparent;
        }
        .prod-card:hover  { box-shadow: 0 4px 16px rgba(0,0,0,.1); }
        .prod-card:active { transform: scale(.96); }
        .prod-card.en-carrito { border-color: var(--brand); }
        .prod-card.sin-stock  { opacity: .45; cursor: not-allowed; }

        .prod-tamano {
            display: inline-block;
            font-size: 10px;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 20px;
            background: var(--g9);
            color: var(--g2);
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: .4px;
        }
        .prod-nombre {
            font-size: 14px;
            font-weight: 700;
            line-height: 1.2;
            margin-bottom: 4px;
        }
        .prod-precio {
            font-size: 17px;
            font-weight: 800;
            color: var(--brand);
            margin-bottom: 6px;
        }
        .prod-capacidad {
            font-size: 11px;
            font-weight: 600;
            padding: 2px 7px;
            border-radius: 20px;
        }
        .cap-ok     { background: #d1fae5; color: #065f46; }
        .cap-bajo   { background: #fef3c7; color: #92400e; }
        .cap-agotado{ background: #fee2e2; color: #991b1b; }
        .cap-libre  { background: var(--g9); color: var(--g5); }

        /* Contador de unidades en carrito (badge) */
        .prod-qty-badge {
            display: none;
            position: absolute;
            top: 8px;
            right: 8px;
            background: var(--brand);
            color: var(--white);
            font-size: 12px;
            font-weight: 800;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            align-items: center;
            justify-content: center;
        }

        /* ---- BARRA STICKY DE COBRAR ---- */
        .cobrar-bar {
            display: none;
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            z-index: 40;
            background: var(--dark);
            color: var(--white);
            padding: 10px 14px;
            padding-bottom: max(10px, env(safe-area-inset-bottom));
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            box-shadow: 0 -4px 20px rgba(0,0,0,.3);
        }
        .cobrar-info {
            display: flex;
            align-items: baseline;
            gap: 10px;
        }
        .cobrar-count {
            background: var(--brand);
            color: var(--white);
            font-size: 12px;
            font-weight: 800;
            padding: 3px 9px;
            border-radius: 20px;
        }
        .cobrar-total {
            font-size: 18px;
            font-weight: 800;
        }
        .cobrar-btn {
            background: var(--brand);
            color: var(--white);
            border: none;
            padding: 12px 24px;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 800;
            cursor: pointer;
            flex-shrink: 0;
            letter-spacing: .2px;
            transition: background .15s;
        }
        .cobrar-btn:hover { background: #c73d28; }

        /* ---- MODAL / BOTTOM SHEET ---- */
        .overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.6);
            z-index: 60;
            align-items: flex-end;
        }
        .overlay.active { display: flex; }

        .sheet {
            background: var(--white);
            border-radius: 20px 20px 0 0;
            width: 100%;
            max-height: 92vh;
            overflow-y: auto;
            padding: 0 0 max(24px, env(safe-area-inset-bottom));
            animation: slideUp .22s ease-out;
        }
        @keyframes slideUp {
            from { transform: translateY(60px); opacity: 0; }
            to   { transform: translateY(0);    opacity: 1; }
        }

        .sheet-handle {
            text-align: center;
            padding: 12px 0 0;
        }
        .sheet-handle::before {
            content: '';
            display: inline-block;
            width: 36px;
            height: 4px;
            border-radius: 2px;
            background: var(--g8);
        }

        .sheet-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 14px 18px 10px;
            border-bottom: 1px solid var(--g9);
        }
        .sheet-header h2 { font-size: 17px; font-weight: 800; }
        .btn-close {
            background: var(--g9);
            border: none;
            color: var(--g5);
            width: 30px;
            height: 30px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .sheet-section { padding: 14px 18px; }
        .sheet-section + .sheet-section { border-top: 1px solid var(--g9); }

        .sheet-section-title {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .6px;
            color: var(--g5);
            margin-bottom: 10px;
        }

        /* Lista de ítems del carrito en el modal */
        .cart-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            font-size: 14px;
        }
        .cart-item + .cart-item { border-top: 1px dashed var(--g8); }
        .cart-item-name  { font-weight: 600; flex: 1; }
        .cart-item-qty   { color: var(--g5); margin: 0 12px; font-size: 13px; white-space: nowrap; }
        .cart-item-price { font-weight: 700; white-space: nowrap; }
        .cart-item-rm {
            background: none;
            border: none;
            color: var(--brand);
            cursor: pointer;
            font-size: 18px;
            padding: 0 0 0 10px;
            line-height: 1;
        }

        .cart-total-row {
            display: flex;
            justify-content: space-between;
            font-size: 17px;
            font-weight: 800;
            padding-top: 12px;
            border-top: 2px solid var(--dark);
            margin-top: 4px;
        }

        /* Botones de método de pago */
        .metodos-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
        }
        .metodo-btn {
            padding: 10px 6px;
            border: 2px solid var(--g8);
            background: var(--white);
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            text-align: center;
            transition: border-color .15s, background .15s, color .15s;
            -webkit-tap-highlight-color: transparent;
        }
        .metodo-btn:hover  { border-color: var(--brand); color: var(--brand); }
        .metodo-btn.sel    { border-color: var(--brand); background: #fef2f0; color: var(--brand); }

        /* Selector de cliente (fiado) */
        .fiado-section { display: none; }
        .fiado-section.visible { display: block; }

        select, input[type="text"] {
            width: 100%;
            padding: 12px 14px;
            border: 2px solid var(--g8);
            border-radius: 10px;
            font-size: 15px;
            color: var(--dark);
            background: var(--white);
            outline: none;
            -webkit-appearance: none;
        }
        select:focus, input[type="text"]:focus { border-color: var(--brand); }

        .nuevo-cliente-form {
            display: none;
            margin-top: 10px;
            padding: 12px;
            background: var(--g9);
            border-radius: 10px;
            gap: 8px;
            flex-direction: column;
        }
        .nuevo-cliente-form.visible { display: flex; }
        .input-row { display: flex; gap: 8px; }
        .btn-sm {
            padding: 10px 14px;
            border: none;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
        }
        .btn-sm-brand  { background: var(--brand); color: var(--white); }
        .btn-sm-ghost  { background: var(--g8); color: var(--g2); }

        /* Notas */
        input.notas-input {
            font-size: 15px;
            padding: 12px 14px;
        }

        /* Botón confirmar */
        .btn-confirmar {
            display: block;
            width: calc(100% - 36px);
            margin: 0 18px;
            padding: 16px;
            background: var(--brand);
            color: var(--white);
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 800;
            cursor: pointer;
            transition: background .15s;
            letter-spacing: .2px;
        }
        .btn-confirmar:hover     { background: #c73d28; }
        .btn-confirmar:disabled  { background: var(--g8); cursor: not-allowed; }

        /* ---- TOAST ---- */
        .toast {
            position: fixed;
            bottom: 90px;
            left: 50%;
            transform: translateX(-50%) translateY(20px);
            padding: 10px 20px;
            border-radius: 24px;
            font-size: 14px;
            font-weight: 600;
            white-space: nowrap;
            pointer-events: none;
            opacity: 0;
            transition: opacity .25s, transform .25s;
            z-index: 100;
            max-width: 90vw;
            text-overflow: ellipsis;
            overflow: hidden;
        }
        .toast.visible { opacity: 1; transform: translateX(-50%) translateY(0); }
        .toast-ok  { background: #065f46; color: #d1fae5; }
        .toast-err { background: #991b1b; color: #fee2e2; }
    </style>
</head>
<body>

<!-- ---- HEADER ---- -->

<!-- ---- BARRA DE RESUMEN DEL DÍA ---- -->
<div class="resumen-bar">
    <span>Ventas hoy: <strong><?= $resumen['total_ventas'] ?></strong></span>
    <span class="resumen-sep">|</span>
    <span>Total: <strong>$<?= number_format($resumen['total_pesos'], 0, ',', '.') ?></strong></span>
    <span class="resumen-sep">|</span>
    <span>Efectivo: <strong>$<?= number_format($resumen['efectivo'], 0, ',', '.') ?></strong></span>
    <span class="resumen-sep">|</span>
    <span>Digital: <strong>$<?= number_format($resumen['nequi'] + $resumen['daviplata'] + $resumen['bancolombia'], 0, ',', '.') ?></strong></span>
    <span class="resumen-sep">|</span>
    <span>Fiado: <strong>$<?= number_format($resumen['fiado'], 0, ',', '.') ?></strong></span>
</div>

<!-- ---- GRID DE PRODUCTOS ---- -->
<main class="main">
    <?php foreach ($por_categoria as $cat => $items): ?>
    <p class="section-label"><?= htmlspecialchars(ucfirst($cat)) ?></p>
    <div class="productos-grid">
        <?php foreach ($items as $p):
            $cap    = (int)$p['capacidad'];
            $precio = (float)$p['precio_venta'];
            $agotado = ($cap === 0);
            if ($cap >= 10 || $cap === 9999) { $cap_class = 'cap-ok'; $cap_txt = $cap === 9999 ? 'Disponible' : "$cap disp."; }
            elseif ($cap > 0) { $cap_class = 'cap-bajo'; $cap_txt = "$cap disp."; }
            else { $cap_class = 'cap-agotado'; $cap_txt = 'Agotado'; }
        ?>
        <button
            class="prod-card<?= $agotado ? ' sin-stock' : '' ?>"
            id="card-<?= $p['id'] ?>"
            data-id="<?= $p['id'] ?>"
            data-nombre="<?= htmlspecialchars($p['nombre']) ?>"
            data-precio="<?= $precio ?>"
            data-capacidad="<?= $cap ?>"
            onclick="agregarAlCarrito(<?= $p['id'] ?>, <?= htmlspecialchars(json_encode($p['nombre'])) ?>, <?= $precio ?>, <?= $cap ?>)"
        >
            <span class="prod-qty-badge" id="qty-<?= $p['id'] ?>">1</span>
            <?php if ($p['tamano'] !== 'unico'): ?>
            <span class="prod-tamano"><?= htmlspecialchars($p['tamano']) ?></span>
            <?php endif; ?>
            <div class="prod-nombre"><?= htmlspecialchars($p['nombre']) ?></div>
            <div class="prod-precio">$<?= number_format($precio, 0, ',', '.') ?></div>
            <span class="prod-capacidad <?= $cap_class ?>" id="cap-<?= $p['id'] ?>">
                <?= $cap_txt ?>
            </span>
        </button>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>

    <?php if (empty($productos)): ?>
    <div style="text-align:center; padding:60px 16px; color:var(--g5);">
        <p style="font-size:32px; margin-bottom:10px;">🛒</p>
        <p style="font-weight:600;">Sin productos configurados</p>
        <p style="font-size:13px; margin-top:6px;">Agrega productos en el módulo de Recetario.</p>
    </div>
    <?php endif; ?>
</main>

<!-- ---- BARRA STICKY: COBRAR ---- -->
<div class="cobrar-bar" id="cobrar-bar">
    <div class="cobrar-info">
        <span class="cobrar-count" id="cart-count">0</span>
        <span class="cobrar-total" id="cart-total">$0</span>
    </div>
    <button class="cobrar-btn" onclick="abrirModal()">Cobrar →</button>
</div>

<!-- ---- TOAST ---- -->
<div class="toast" id="toast" role="alert"></div>

<!-- ---- MODAL DE CHECKOUT ---- -->
<div class="overlay" id="modal-overlay" onclick="cerrarModalFuera(event)">
    <div class="sheet" id="checkout-sheet">
        <div class="sheet-handle"></div>

        <div class="sheet-header">
            <h2>Confirmar Venta</h2>
            <button class="btn-close" onclick="cerrarModal()" aria-label="Cerrar">✕</button>
        </div>

        <!-- Resumen del carrito -->
        <div class="sheet-section">
            <p class="sheet-section-title">Productos</p>
            <div id="modal-items"><!-- renderizado por JS --></div>
            <div class="cart-total-row">
                <span>Total</span>
                <span id="modal-total">$0</span>
            </div>
        </div>

        <!-- Método de pago -->
        <div class="sheet-section">
            <p class="sheet-section-title">Método de Pago</p>
            <div class="metodos-grid">
                <button class="metodo-btn sel" onclick="selMetodo(this,'efectivo')">💵 Efectivo</button>
                <button class="metodo-btn"     onclick="selMetodo(this,'nequi')">🟣 Nequi</button>
                <button class="metodo-btn"     onclick="selMetodo(this,'daviplata')">🔵 Daviplata</button>
                <button class="metodo-btn"     onclick="selMetodo(this,'bancolombia')">🟡 Bancolombia</button>
                <button class="metodo-btn"     onclick="selMetodo(this,'fiado')">📋 Fiado</button>
            </div>
        </div>

        <!-- Sección fiado (cliente + nuevo cliente) -->
        <div class="sheet-section fiado-section" id="fiado-section">
            <p class="sheet-section-title">Cliente (Fiado)</p>
            <select id="cliente-select" onchange="clienteSeleccionado=this.value">
                <option value="">— Seleccionar cliente —</option>
                <?php foreach ($clientes as $c): ?>
                <option value="<?= $c['id'] ?>">
                    <?= htmlspecialchars($c['nombre']) ?>
                    <?= $c['saldo_fiado'] > 0 ? ' (Deuda: $' . number_format($c['saldo_fiado'],0,',','.') . ')' : '' ?>
                </option>
                <?php endforeach; ?>
            </select>
            <button class="btn-sm btn-sm-ghost" style="margin-top:8px; width:100%"
                    onclick="toggleNuevoCliente()">+ Nuevo cliente</button>
            <div class="nuevo-cliente-form" id="form-nuevo-cliente">
                <div class="input-row">
                    <input type="text" id="nc-nombre"   placeholder="Nombre del cliente" style="flex:1">
                    <input type="text" id="nc-telefono" placeholder="Teléfono" style="width:130px">
                </div>
                <div class="input-row">
                    <button class="btn-sm btn-sm-brand" style="flex:1" onclick="crearCliente()">Guardar</button>
                    <button class="btn-sm btn-sm-ghost" style="flex:1" onclick="toggleNuevoCliente()">Cancelar</button>
                </div>
            </div>
        </div>

        <!-- Combo -->
        <div class="sheet-section">
            <p class="sheet-section-title">Adicionales</p>
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
                <label style="display:flex;align-items:center;gap:8px;font-size:14px;cursor:pointer">
                    <input type="checkbox" id="es-combo-inp"
                           style="width:18px;height:18px;accent-color:var(--brand)">
                    <span><strong>Incluye combo</strong> (bebida + papas)</span>
                </label>
            </div>
        </div>

        <!-- Notas -->
        <div class="sheet-section">
            <p class="sheet-section-title">Notas (opcional)</p>
            <input type="text" class="notas-input" id="notas-input"
                   placeholder="Instrucciones especiales, sin picante, etc.">
        </div>

        <!-- CSRF token (enviado con FormData en cada submit) -->
        <input type="hidden" id="csrf-token" value="<?= htmlspecialchars(csrf_token()) ?>">

        <!-- Confirmar -->
        <button class="btn-confirmar" id="btn-confirmar" onclick="confirmarVenta()">
            Confirmar Venta
        </button>

    </div><!-- /sheet -->
</div><!-- /overlay -->

<!-- ====================================================================
     JAVASCRIPT — Lógica del carrito y comunicación con la API
     ==================================================================== -->
<script>
// ---- Estado del carrito ----
let carrito          = {};  // { id: { id, nombre, precio, cantidad } }
let metodoPago       = 'efectivo';
let clienteSeleccionado = '';

// ---- CARRITO ----

function agregarAlCarrito(id, nombre, precio, capacidad) {
    if (capacidad === 0) {
        mostrarToast('Sin stock: ' + nombre, 'err');
        return;
    }
    if (!carrito[id]) {
        carrito[id] = { id, nombre, precio, cantidad: 1 };
    } else {
        carrito[id].cantidad++;
    }
    actualizarUI();
    // Feedback táctil breve en la tarjeta
    const card = document.getElementById('card-' + id);
    card.style.transform = 'scale(.93)';
    setTimeout(() => card.style.transform = '', 120);
}

function quitarDelCarrito(id) {
    if (!carrito[id]) return;
    carrito[id].cantidad--;
    if (carrito[id].cantidad <= 0) delete carrito[id];
    actualizarUI();
    renderModalItems(); // refrescar lista en modal si está abierto
}

function limpiarCarrito() {
    carrito = {};
    actualizarUI();
}

function actualizarUI() {
    const items  = Object.values(carrito);
    const total  = items.reduce((s, i) => s + i.precio * i.cantidad, 0);
    const count  = items.reduce((s, i) => s + i.cantidad, 0);

    // Barra de cobrar
    const bar = document.getElementById('cobrar-bar');
    bar.style.display = count > 0 ? 'flex' : 'none';
    document.getElementById('cart-count').textContent = count;
    document.getElementById('cart-total').textContent = formatPeso(total);

    // Badges individuales en las tarjetas
    Object.values(carrito).forEach(item => {
        const badge = document.getElementById('qty-' + item.id);
        const card  = document.getElementById('card-' + item.id);
        if (badge) {
            badge.textContent = item.cantidad;
            badge.style.display = 'flex';
            card.classList.add('en-carrito');
        }
    });
    // Limpiar badges de productos removidos del carrito
    document.querySelectorAll('.prod-qty-badge').forEach(badge => {
        const id = badge.id.replace('qty-', '');
        if (!carrito[id]) {
            badge.style.display = 'none';
            document.getElementById('card-' + id)?.classList.remove('en-carrito');
        }
    });
}

// ---- MODAL ----

function abrirModal() {
    if (Object.keys(carrito).length === 0) return;
    renderModalItems();
    document.getElementById('modal-overlay').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function cerrarModal() {
    document.getElementById('modal-overlay').classList.remove('active');
    document.body.style.overflow = '';
}

function cerrarModalFuera(e) {
    // Solo cierra si se hace clic en el fondo oscuro, no en el sheet
    if (e.target === document.getElementById('modal-overlay')) cerrarModal();
}

function renderModalItems() {
    const items = Object.values(carrito);
    const total = items.reduce((s, i) => s + i.precio * i.cantidad, 0);

    const html = items.map(item => `
        <div class="cart-item">
            <span class="cart-item-name">${escHtml(item.nombre)}</span>
            <span class="cart-item-qty">× ${item.cantidad}</span>
            <span class="cart-item-price">${formatPeso(item.precio * item.cantidad)}</span>
            <button class="cart-item-rm" onclick="quitarDelCarrito(${item.id})" title="Quitar uno">−</button>
        </div>
    `).join('');

    document.getElementById('modal-items').innerHTML = html;
    document.getElementById('modal-total').textContent = formatPeso(total);
}

// ---- MÉTODO DE PAGO ----

function selMetodo(btn, metodo) {
    document.querySelectorAll('.metodo-btn').forEach(b => b.classList.remove('sel'));
    btn.classList.add('sel');
    metodoPago = metodo;
    const fiado = document.getElementById('fiado-section');
    if (metodo === 'fiado') {
        fiado.classList.add('visible');
    } else {
        fiado.classList.remove('visible');
        clienteSeleccionado = '';
        document.getElementById('cliente-select').value = '';
    }
}

// ---- NUEVO CLIENTE ON-THE-FLY ----

function toggleNuevoCliente() {
    const form = document.getElementById('form-nuevo-cliente');
    form.classList.toggle('visible');
    if (form.classList.contains('visible')) {
        document.getElementById('nc-nombre').focus();
    }
}

async function crearCliente() {
    const nombre   = document.getElementById('nc-nombre').value.trim();
    const telefono = document.getElementById('nc-telefono').value.trim();
    if (!nombre) { mostrarToast('Ingresa el nombre del cliente', 'err'); return; }

    const fd = new FormData();
    fd.append('csrf_token', document.getElementById('csrf-token').value);
    fd.append('nombre',   nombre);
    fd.append('telefono', telefono);

    const resp = await fetch('api/nuevo_cliente.php', { method: 'POST', body: fd });
    const data = await resp.json();

    if (data.success) {
        // Añadir al select y seleccionarlo automáticamente
        const select = document.getElementById('cliente-select');
        const opt = new Option(data.nombre, data.id, true, true);
        select.add(opt);
        clienteSeleccionado = data.id;
        toggleNuevoCliente();
        document.getElementById('nc-nombre').value   = '';
        document.getElementById('nc-telefono').value = '';
        mostrarToast('Cliente "' + data.nombre + '" creado', 'ok');
    } else {
        mostrarToast(data.error || 'Error al crear cliente', 'err');
    }
}

// ---- CONFIRMAR VENTA ----

async function confirmarVenta() {
    // Validar que haya items
    if (Object.keys(carrito).length === 0) return;

    // Validar cliente si es fiado
    if (metodoPago === 'fiado' && !clienteSeleccionado) {
        mostrarToast('Selecciona un cliente para el fiado', 'err');
        document.getElementById('cliente-select').focus();
        return;
    }

    const btn = document.getElementById('btn-confirmar');
    btn.disabled = true;
    btn.textContent = 'Procesando…';

    // Armar carrito para enviar
    const carritoArray = Object.values(carrito).map(i => ({
        producto_id: i.id,
        cantidad:    i.cantidad,
        precio:      i.precio,
    }));

    const fd = new FormData();
    fd.append('csrf_token',  document.getElementById('csrf-token').value);
    fd.append('metodo_pago', metodoPago);
    fd.append('cliente_id',  clienteSeleccionado || '');
    fd.append('notas',       document.getElementById('notas-input').value.trim());
    fd.append('es_combo',    document.getElementById('es-combo-inp')?.checked ? '1' : '0');
    fd.append('carrito',     JSON.stringify(carritoArray));

    try {
        const resp = await fetch('api/procesar_venta.php', { method: 'POST', body: fd });
        const data = await resp.json();

        if (data.success) {
            cerrarModal();
            limpiarCarrito();
            mostrarToast('✓ Venta #' + data.venta_id + ' registrada', 'ok');
            // Actualizar contadores de stock en las tarjetas sin recargar página
            setTimeout(refrescarCapacidades, 600);
            // Resetear inputs del modal
            document.getElementById('notas-input').value = '';
            document.getElementById('cliente-select').value = '';
            clienteSeleccionado = '';
            // Volver a efectivo
            document.querySelectorAll('.metodo-btn').forEach(b => b.classList.remove('sel'));
            document.querySelector('.metodo-btn').classList.add('sel');
            metodoPago = 'efectivo';
            document.getElementById('fiado-section').classList.remove('visible');
        } else {
            mostrarToast(data.error || 'Error al procesar la venta', 'err');
        }
    } catch (e) {
        mostrarToast('Error de conexión. Verifica tu red.', 'err');
    } finally {
        btn.disabled = false;
        btn.textContent = 'Confirmar Venta';
    }
}

// ---- REFRESCAR CAPACIDADES (sin reload de página) ----

async function refrescarCapacidades() {
    try {
        const resp = await fetch('api/capacidades.php');
        const data = await resp.json();

        data.forEach(p => {
            const cap = p.capacidad;
            // Actualizar texto e indicador de color
            const capEl = document.getElementById('cap-' + p.id);
            const card  = document.getElementById('card-' + p.id);
            if (!capEl || !card) return;

            let cls, txt;
            if (cap === 0)          { cls = 'cap-agotado'; txt = 'Agotado'; }
            else if (cap < 10)      { cls = 'cap-bajo';    txt = cap + ' disp.'; }
            else if (cap === 9999)  { cls = 'cap-libre';   txt = 'Disponible'; }
            else                    { cls = 'cap-ok';      txt = cap + ' disp.'; }

            capEl.className = 'prod-capacidad ' + cls;
            capEl.textContent = txt;
            card.dataset.capacidad = cap;
            card.classList.toggle('sin-stock', cap === 0);
        });
    } catch (e) {
        // Silenciar errores de red en el refresco automático
    }
}

// Refrescar capacidades cada 2 minutos (útil cuando hay varios empleados registrando ventas)
setInterval(refrescarCapacidades, 120_000);

// ---- UTILIDADES ----

function formatPeso(n) {
    return '$' + Math.round(n).toLocaleString('es-CO');
}

function escHtml(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

let toastTimer;
function mostrarToast(msg, tipo) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast toast-' + tipo + ' visible';
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => t.classList.remove('visible'), 3200);
}

// Cerrar modal con tecla Escape
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') cerrarModal();
});
</script>

</body>
</html>
