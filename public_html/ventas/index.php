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

// Serializar clientes para el autocomplete JS del POS.
// Se incluye nombre completo (nombre + apellido), empresa y saldo_fiado
// para el buscador en tiempo real del selector de cliente.
$clientes_js = json_encode(
    array_map(fn($c) => [
        'id'      => (int)$c['id'],
        'nombre'  => $c['nombre'],
        'apellido'=> $c['apellido'] ?? '',
        'empresa' => $c['empresa']  ?? '',
        'saldo'   => (float)$c['saldo_fiado'],
    ], $clientes),
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
);

// Cargar combo configs indexadas por producto_id para el POS.
// Si la migración 025 aún no se ha aplicado, el array queda vacío sin romper la página.
$combo_map = [];
try {
    $rows = db()->query(
        'SELECT cc.id AS combo_id, cc.producto_id, cc.precio_adicional, cc.nombre,
                ci.insumo_id, i.nombre AS insumo_nombre
         FROM combo_configs cc
         JOIN combo_insumos ci ON ci.combo_id = cc.id
         JOIN insumos i ON i.id = ci.insumo_id
         WHERE cc.activo = 1
         ORDER BY cc.producto_id, i.nombre'
    )->fetchAll();
    foreach ($rows as $row) {
        $pid = (int)$row['producto_id'];
        if (!isset($combo_map[$pid])) {
            $combo_map[$pid] = [
                'combo_id'         => (int)$row['combo_id'],
                'precio_adicional' => (float)$row['precio_adicional'],
                'nombre'           => $row['nombre'],
                'insumos'          => [],
            ];
        }
        $combo_map[$pid]['insumos'][] = $row['insumo_nombre'];
    }
} catch (\Exception $e) {
    // Migración 025 aún no aplicada — el POS sigue funcionando sin combos
    $combo_map = [];
}

// Cargar variantes de tamaño indexadas por producto_id para el POS.
// Solo variantes activas. Si la migración 035 aún no está aplicada, queda vacío.
$variantes_map = [];
try {
    $rows = db()->query(
        'SELECT producto_id, id, etiqueta, precio_venta, factor_receta
         FROM producto_variantes
         WHERE activo = 1
         ORDER BY producto_id, precio_venta ASC'
    )->fetchAll();
    foreach ($rows as $row) {
        $pid = (int)$row['producto_id'];
        $variantes_map[$pid][] = [
            'id'            => (int)$row['id'],
            'etiqueta'      => $row['etiqueta'],
            'precio_venta'  => (float)$row['precio_venta'],
            'factor_receta' => (float)$row['factor_receta'],
        ];
    }
} catch (\Exception $e) {
    $variantes_map = [];
}

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
            /* --r viene de nav.php (theme_radius en Admin → Apariencia) */
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--g9);
            color: var(--dark);
            min-height: 100vh;
            /* Espacio para la barra sticky de cobrar */
            padding-bottom: 80px;
        }

        /* nav.php provee el header global — sin header propio aquí */

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

        /* Fila de etiquetas: tamaño + combo/sencillo en la misma línea */
        .prod-meta {
            display: flex;
            align-items: center;
            gap: 4px;
            flex-wrap: wrap;
            margin-bottom: 6px;
        }
        .prod-tamano {
            display: inline-block;
            font-size: 10px;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 20px;
            background: var(--g9);
            color: var(--g2);
            text-transform: uppercase;
            letter-spacing: .4px;
            white-space: nowrap;
        }
        /* Badge verde si el producto tiene opción combo configurada */
        .prod-combo {
            display: inline-block;
            font-size: 9px;
            font-weight: 700;
            padding: 2px 6px;
            border-radius: 20px;
            background: #d1fae5;
            color: #065f46;
            letter-spacing: .3px;
            white-space: nowrap;
        }
        /* Badge gris si el producto es solo sencillo (sin combo) */
        .prod-sencillo {
            display: inline-block;
            font-size: 9px;
            font-weight: 600;
            padding: 2px 6px;
            border-radius: 20px;
            background: var(--g9);
            color: var(--g5);
            letter-spacing: .3px;
            white-space: nowrap;
        }
        /* Badge azul si el producto tiene variantes de tamaño configuradas */
        .prod-variante {
            display: inline-block;
            font-size: 9px;
            font-weight: 700;
            padding: 2px 6px;
            border-radius: 20px;
            background: #dbeafe;
            color: #1e40af;
            letter-spacing: .3px;
            white-space: nowrap;
        }
        /* Precio "Desde" en tarjeta con variantes */
        .prod-desde {
            font-size: 9px;
            font-weight: 600;
            color: var(--g5);
            display: block;
            margin-bottom: 1px;
        }
        .prod-nombre {
            font-size: 14px;
            font-weight: 700;
            line-height: 1.2;
            margin-bottom: 2px;
            color: var(--dark);
        }
        /* Subtítulo (nombre2): corrección de color — era rgba blanco invisible
           sobre fondo blanco de la card. Ahora usa gris legible. */
        .prod-sub {
            font-size: 11px;
            color: var(--g5);
            line-height: 1.3;
            margin-bottom: 3px;
            font-weight: 400;
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

        /* ── Selector de cliente con búsqueda en tiempo real ──────────────── */
        .fiado-section { display: block; }

        /* Contenedor del autocomplete */
        .cliente-ac { position: relative; }

        /* Input de búsqueda + botón limpiar */
        .cliente-ac-wrap {
            display: flex;
            align-items: center;
            border: 2px solid var(--g8);
            border-radius: 10px;
            background: var(--white);
            overflow: hidden;
            transition: border-color .15s;
        }
        .cliente-ac-wrap:focus-within { border-color: var(--brand); }
        .cliente-ac-wrap input {
            flex: 1;
            padding: 12px 14px;
            border: none;
            font-size: 15px;
            color: var(--dark);
            background: transparent;
            outline: none;
            min-width: 0;
        }
        /* Botón × para limpiar la selección */
        .cliente-ac-clear {
            padding: 0 12px;
            background: none;
            border: none;
            font-size: 18px;
            color: var(--g5);
            cursor: pointer;
            line-height: 1;
            flex-shrink: 0;
        }
        .cliente-ac-clear:hover { color: var(--brand); }

        /* Dropdown de resultados — z-index 1200 garantiza que quede por encima
           del overlay del confirm-sheet (z-index ~60) sin importar el contexto */
        .cliente-dropdown {
            position: absolute;
            top: calc(100% + 4px);
            left: 0; right: 0;
            background: var(--white);
            border: 1px solid var(--g8);
            border-radius: 10px;
            box-shadow: 0 8px 24px rgba(0,0,0,.12);
            max-height: 260px;
            overflow-y: auto;
            z-index: 1200;
            display: none;
        }
        .cliente-dropdown.open { display: block; }

        /* Cada opción del dropdown */
        .cli-opt {
            padding: 11px 14px;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
            border-bottom: 1px solid var(--g9);
            min-height: 44px; /* touch-friendly */
        }
        .cli-opt:last-child { border-bottom: none; }
        .cli-opt:hover, .cli-opt.focused {
            background: #fef2f0;
        }
        .cli-opt-nombre { font-size: 14px; font-weight: 600; }
        .cli-opt-empresa { font-size: 11px; color: var(--g5); }
        .cli-opt-deuda {
            font-size: 11px; font-weight: 700; color: var(--brand);
            background: #fee2e2; padding: 2px 7px; border-radius: 20px;
            white-space: nowrap; flex-shrink: 0;
        }
        /* Sin resultados */
        .cli-opt-empty {
            padding: 14px; font-size: 13px; color: var(--g5);
            text-align: center;
        }

        /* Chip del cliente seleccionado (reemplaza el input tras elegir) */
        .cliente-chip {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            padding: 10px 14px;
            border: 2px solid #86efac;
            border-radius: 10px;
            background: #f0fdf4;
        }
        .cliente-chip-info { display: flex; flex-direction: column; gap: 2px; }
        .cliente-chip-nombre { font-size: 14px; font-weight: 700; color: #166534; }
        .cliente-chip-deuda  { font-size: 11px; color: var(--brand); font-weight: 700; }
        .cliente-chip-btn {
            background: none; border: none; cursor: pointer;
            color: #166534; font-size: 18px; line-height: 1; padding: 0 4px;
            flex-shrink: 0;
        }
        .cliente-chip-btn:hover { color: var(--brand); }

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

        /* ---- SELECTOR SOLO / COMBO ---- */
        /* Mini bottom-sheet que aparece al tocar un producto que tiene combo */
        .combo-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.55);
            z-index: 70;
            align-items: flex-end;
        }
        .combo-overlay.active { display: flex; }
        .combo-sheet {
            background: var(--white);
            border-radius: 20px 20px 0 0;
            width: 100%;
            padding: 20px 18px max(24px, env(safe-area-inset-bottom));
            animation: slideUp .18s ease-out;
        }
        .combo-sheet-title {
            font-size: 15px;
            font-weight: 800;
            margin-bottom: 4px;
        }
        .combo-sheet-sub {
            font-size: 12px;
            color: var(--g5);
            margin-bottom: 16px;
        }
        /* Dos botones lado a lado: Solo | Combo */
        .combo-options {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }
        .combo-opt {
            padding: 14px 10px;
            border: 2px solid var(--g8);
            background: var(--white);
            border-radius: 14px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            text-align: center;
            line-height: 1.4;
            transition: border-color .15s, background .15s;
            -webkit-tap-highlight-color: transparent;
        }
        .combo-opt:hover  { border-color: var(--brand); }
        .combo-opt.is-combo { border-color: var(--green); background: #f0fdf4; color: #065f46; }
        .combo-opt-price {
            display: block;
            font-size: 17px;
            font-weight: 800;
            color: var(--brand);
            margin-top: 4px;
        }
        .combo-opt.is-combo .combo-opt-price { color: var(--green); }
        .combo-opt-extras {
            display: block;
            font-size: 11px;
            color: var(--g5);
            margin-top: 3px;
            font-weight: 400;
        }
        /* Badge "COMBO" en el carrito */
        .badge-combo {
            display: inline-block;
            font-size: 9px;
            font-weight: 800;
            background: #d1fae5;
            color: #065f46;
            padding: 1px 5px;
            border-radius: 20px;
            vertical-align: middle;
            margin-left: 4px;
            letter-spacing: .3px;
        }

        /* Badge "VARIANTE" en el carrito */
        .badge-variante {
            display: inline-block;
            font-size: 9px;
            font-weight: 800;
            background: #dbeafe;
            color: #1e40af;
            padding: 1px 5px;
            border-radius: 20px;
            vertical-align: middle;
            margin-left: 4px;
            letter-spacing: .3px;
        }

        /* ---- SELECTOR DE VARIANTE DE TAMAÑO ---- */
        .variante-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.55);
            z-index: 70;
            align-items: flex-end;
        }
        .variante-overlay.active { display: flex; }
        .variante-sheet {
            background: var(--white);
            border-radius: 20px 20px 0 0;
            width: 100%;
            padding: 20px 18px max(24px, env(safe-area-inset-bottom));
            animation: slideUp .18s ease-out;
            max-height: 70vh;
            overflow-y: auto;
        }
        .variante-sheet-title {
            font-size: 15px;
            font-weight: 800;
            margin-bottom: 4px;
        }
        .variante-sheet-sub {
            font-size: 12px;
            color: var(--g5);
            margin-bottom: 16px;
        }
        .variante-options { display: flex; flex-direction: column; gap: 10px; }
        .variante-opt {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 16px;
            border: 2px solid var(--g8);
            background: var(--white);
            border-radius: 14px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            width: 100%;
            transition: border-color .15s, background .15s;
            -webkit-tap-highlight-color: transparent;
        }
        .variante-opt:hover  { border-color: var(--brand); background: #fff5f4; }
        .variante-opt-price  { font-size: 16px; font-weight: 800; color: var(--brand); }

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

        /* ════════════════════════════════════════════════════════════════
           RESPONSIVE POS
           ════════════════════════════════════════════════════════════════ */

        /* ── Tablet portrait (640-1023px): 4 y 5 columnas en el grid ── */
        @media (min-width: 640px) and (max-width: 1023px) {
            /* Ya tenemos 3 cols @ 520px y 4 cols @ 720px — aquí 5 cols */
            .productos-grid { grid-template-columns: repeat(4, 1fr); gap: 10px; }
            /* Tarjeta un poco más grande en tablet */
            .prod-card  { padding: 16px 14px; }
            .prod-nombre { font-size: 15px; }
            .prod-precio { font-size: 18px; }
            /* Barra de resumen: más espacio y fuente levemente mayor */
            .resumen-bar { font-size: 13px; padding: 9px 18px; gap: 18px; }
            /* Main con más padding en tablet */
            .main { padding: 14px 18px 0; max-width: 100%; }
            /* El sheet pasa a ser un modal centrado en lugar de bottom-sheet */
            .overlay { align-items: center !important; padding: 20px; }
            .sheet {
                border-radius: 16px !important;
                max-width: 540px;
                max-height: 88vh;
                animation: fadeIn .2s ease-out;
            }
            .combo-overlay, .variante-overlay { align-items: center !important; padding: 20px; }
            .combo-sheet {
                border-radius: 16px !important;
                max-width: 420px;
                margin: 0 auto;
                animation: fadeIn .18s ease-out;
            }
            .variante-sheet {
                border-radius: 16px !important;
                max-width: 420px;
                margin: 0 auto;
                animation: fadeIn .18s ease-out;
            }
        }

        /* ── Escritorio pequeño (1024-1279px) ── */
        @media (min-width: 1024px) {
            .productos-grid { grid-template-columns: repeat(5, 1fr); gap: 12px; }
            .main  { padding: 16px 20px 0; max-width: 960px; }
            .prod-nombre { font-size: 14px; }
            .prod-precio { font-size: 17px; }
            /* Modales centrados en escritorio */
            .overlay, .combo-overlay, .variante-overlay {
                align-items: center !important;
                padding: 24px;
            }
            .sheet {
                border-radius: 16px !important;
                max-width: 560px;
                max-height: 86vh;
            }
            .combo-sheet {
                border-radius: 16px !important;
                max-width: 440px;
                margin: 0 auto;
            }
            .variante-sheet {
                border-radius: 16px !important;
                max-width: 440px;
                margin: 0 auto;
            }
            /* Barra de cobrar en desktop: alineada al contenido */
            .cobrar-bar { justify-content: center; gap: 32px; }
        }

        /* ── Escritorio estándar (1280-1599px) ── */
        @media (min-width: 1280px) {
            .productos-grid { grid-template-columns: repeat(5, 1fr); gap: 14px; }
            .main  { max-width: 1100px; padding: 18px 24px 0; }
            .prod-card  { padding: 18px 14px; }
        }

        /* ── Pantalla grande / TV (≥1600px) ── */
        @media (min-width: 1600px) {
            .productos-grid { grid-template-columns: repeat(6, 1fr); gap: 16px; }
            .main  { max-width: 1400px !important; padding: 22px 32px 0 !important; }
            .prod-card  { padding: 22px 18px; }
            .prod-nombre { font-size: 16px; }
            .prod-precio { font-size: 22px; }
            .prod-capacidad { font-size: 12px; padding: 3px 10px; }
            .resumen-bar { font-size: 15px; padding: 11px 32px; gap: 24px; }
            /* Cobrar bar */
            .cobrar-total  { font-size: 24px; }
            .cobrar-btn    { font-size: 18px; padding: 16px 32px; }
            .cobrar-count  { font-size: 14px; padding: 5px 14px; }
            /* Modales centrados y más grandes en pantalla grande */
            .sheet       { max-width: 680px; }
            .combo-sheet { max-width: 520px; }
        }

        /* ── TV (≥1920px) ── */
        @media (min-width: 1920px) {
            .productos-grid { grid-template-columns: repeat(7, 1fr); gap: 18px; }
            .main  { max-width: 1680px !important; padding: 28px 40px 0 !important; }
            .prod-card   { padding: 26px 20px; }
            .prod-nombre { font-size: 18px; }
            .prod-precio { font-size: 26px; }
            .prod-capacidad { font-size: 13px; padding: 4px 12px; }
            .resumen-bar { font-size: 16px; padding: 13px 40px; }
            .cobrar-total  { font-size: 28px; }
            .cobrar-btn    { font-size: 20px; padding: 18px 40px; }
            .sheet { max-width: 800px; }
        }

        /* Animación fade para modales centrados en desktop */
        @keyframes fadeIn {
            from { opacity: 0; transform: scale(.96); }
            to   { opacity: 1; transform: scale(1); }
        }

        /* ── Teléfono vertical muy pequeño (< 360px) ── */
        @media (max-width: 359px) {
            /* En teléfonos muy pequeños (ej. iPhone SE 1ª gen) una columna */
            .productos-grid { grid-template-columns: 1fr 1fr; gap: 6px; }
            .prod-card  { padding: 10px 8px; }
            .prod-nombre { font-size: 12px; }
            .prod-precio { font-size: 15px; }
            .prod-sub    { font-size: 10px; }
            .prod-combo, .prod-sencillo, .prod-tamano { font-size: 8px; padding: 1px 5px; }
            .metodos-grid { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/../app/views/nav.php'; ?>

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
    <?php if ((float)($resumen['obsequio'] ?? 0) > 0): ?>
    <span class="resumen-sep">|</span>
    <span>🎁 Obsequios: <strong><?= $resumen['obsequio_n'] ?></strong></span>
    <?php endif; ?>
    <span class="resumen-sep">|</span>
    <a href="<?= APP_BASE ?>/ventas/historial.php"
       style="background:var(--brand);color:#fff;text-decoration:none;padding:4px 12px;
              border-radius:20px;font-size:12px;font-weight:700;flex-shrink:0">
        Ver historial
    </a>
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
            data-nombre2="<?= htmlspecialchars($p['nombre2'] ?? '') ?>"
            data-precio="<?= $precio ?>"
            data-capacidad="<?= $cap ?>"
            onclick="agregarAlCarrito(<?= $p['id'] ?>, <?= htmlspecialchars(json_encode($p['nombre'])) ?>, <?= $precio ?>, <?= $cap ?>, <?= htmlspecialchars(json_encode($p['nombre2'] ?? '')) ?>)"
        >
            <!-- Badge de carrito (aparece al añadir el producto) -->
            <span class="prod-qty-badge" id="qty-<?= $p['id'] ?>">1</span>

            <?php
            // Variantes activas del producto (si las hay)
            $variantesCard = $variantes_map[$p['id']] ?? [];
            $tieneVariantes = !empty($variantesCard);
            // Precio mínimo de variante para mostrar "Desde $X"
            $precioDesde = $tieneVariantes ? $variantesCard[0]['precio_venta'] : null;
            $numVariantes = count($variantesCard);
            ?>
            <!-- Fila de etiquetas: Tamaño + Variante/Combo/Sencillo -->
            <div class="prod-meta">
                <?php if ($p['tamano'] !== 'unico'): ?>
                <span class="prod-tamano">Tam. <?= htmlspecialchars($p['tamano']) ?></span>
                <?php endif; ?>
                <?php if ($tieneVariantes): ?>
                <!-- Producto tiene variantes → indicar al cajero que deberá elegir talla -->
                <span class="prod-variante"><?= $numVariantes ?> talla<?= $numVariantes > 1 ? 's' : '' ?> ▾</span>
                <?php if (isset($combo_map[$p['id']])): ?>
                <span class="prod-combo">+ Combo</span>
                <?php endif; ?>
                <?php elseif (isset($combo_map[$p['id']])): ?>
                <!-- Producto con combo pero sin variantes -->
                <span class="prod-combo">+ Combo</span>
                <?php else: ?>
                <!-- Sin variantes ni combo -->
                <span class="prod-sencillo">Sencillo</span>
                <?php endif; ?>
            </div>

            <!-- Nombre principal del producto -->
            <div class="prod-nombre"><?= htmlspecialchars($p['nombre']) ?></div>
            <!-- Subtítulo complementario (nombre2) — visible ahora con color gris legible -->
            <?php if (!empty($p['nombre2'])): ?>
            <div class="prod-sub"><?= htmlspecialchars($p['nombre2']) ?></div>
            <?php endif; ?>
            <?php if ($tieneVariantes && $precioDesde !== null): ?>
            <span class="prod-desde">Desde</span>
            <div class="prod-precio">$<?= number_format($precioDesde, 0, ',', '.') ?></div>
            <?php else: ?>
            <div class="prod-precio">$<?= number_format($precio, 0, ',', '.') ?></div>
            <?php endif; ?>
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

<!-- ══ MINI SHEET: SELECCIONAR SOLO / COMBO ══════════════════════════════════ -->
<!-- Se muestra al tocar un producto que tiene combo configurado (migración 025) -->
<div class="combo-overlay" id="combo-overlay" onclick="if(event.target===this)cerrarCombo()">
    <div class="combo-sheet">
        <div class="combo-sheet-title" id="combo-titulo">Sándwich XL</div>
        <div class="combo-sheet-sub"   id="combo-extras">Combo incluye: bebida + papas</div>
        <div class="combo-options">
            <!-- Botón SOLO -->
            <button class="combo-opt" id="combo-btn-solo" onclick="elegirOpcionCombo(0)">
                Solo
                <span class="combo-opt-price" id="combo-precio-solo">$0</span>
                <span class="combo-opt-extras">Sin adicionales</span>
            </button>
            <!-- Botón COMBO -->
            <button class="combo-opt is-combo" id="combo-btn-combo" onclick="elegirOpcionCombo(1)">
                Combo
                <span class="combo-opt-price" id="combo-precio-combo">$0</span>
                <span class="combo-opt-extras" id="combo-extras-combo">+bebida +papas</span>
            </button>
        </div>
    </div>
</div>

<!-- ══ MINI SHEET: SELECCIONAR VARIANTE DE TAMAÑO ════════════════════════ -->
<!-- Se muestra al tocar un producto que tiene variantes configuradas (migración 035) -->
<div class="variante-overlay" id="variante-overlay" onclick="if(event.target===this)cerrarVariante()">
    <div class="variante-sheet">
        <div class="variante-sheet-title" id="variante-titulo">Elige el tamaño</div>
        <div class="variante-sheet-sub"   id="variante-sub">Selecciona una opción</div>
        <div class="variante-options"     id="variante-options">
            <!-- Botones generados por JS según las variantes del producto -->
        </div>
    </div>
</div>

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
                <button class="metodo-btn"     onclick="selMetodo(this,'obsequio')">🎁 Obsequio</button>
            </div>
        </div>

        <!-- Selector de cliente — visible para todos los métodos de pago.
             Implementa búsqueda en tiempo real con autocomplete:
             - Filtra por nombre, apellido y empresa mientras el usuario escribe
             - Muestra deuda pendiente en rojo junto al nombre
             - Chip verde al seleccionar; botón × para limpiar
             - Navegación con teclado (↑ ↓ Enter Escape)
             - Touch-friendly: ítems de 44px mínimo -->
        <div class="sheet-section fiado-section" id="fiado-section">
            <p class="sheet-section-title" id="cliente-section-title">Cliente (opcional)</p>

            <!-- Autocomplete de clientes -->
            <div class="cliente-ac" id="cliente-ac">
                <!-- Estado: buscando — input visible -->
                <div class="cliente-ac-wrap" id="cliente-search-wrap">
                    <input type="text" id="cliente-buscar"
                           placeholder="Buscar cliente por nombre..."
                           autocomplete="off"
                           inputmode="text"
                           aria-label="Buscar cliente por nombre, apellido o empresa"
                           aria-autocomplete="list"
                           aria-controls="cliente-dropdown"
                           aria-haspopup="listbox"
                           oninput="acFiltrar()"
                           onfocus="acAbrir()"
                           onkeydown="acTecla(event)">
                    <button id="cliente-ac-clear" class="cliente-ac-clear"
                            onclick="acLimpiar()" style="display:none"
                            title="Quitar cliente seleccionado" aria-label="Quitar cliente">×</button>
                </div>
                <!-- Dropdown de resultados — role=listbox para lectores de pantalla -->
                <div class="cliente-dropdown" id="cliente-dropdown"
                     role="listbox" aria-label="Resultados de búsqueda de clientes"></div>
                <!-- Estado: cliente seleccionado — chip verde -->
                <div class="cliente-chip" id="cliente-chip" style="display:none">
                    <div class="cliente-chip-info">
                        <span class="cliente-chip-nombre" id="cliente-chip-nombre">—</span>
                        <span class="cliente-chip-deuda"  id="cliente-chip-deuda"  style="display:none"></span>
                    </div>
                    <button class="cliente-chip-btn" onclick="acLimpiar()"
                            title="Cambiar cliente" aria-label="Cambiar cliente">×</button>
                </div>
            </div>

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

        <!-- Combos: se muestran automáticamente cuando hay ítems combo en el carrito -->
        <div class="sheet-section" id="combos-resumen-sec" style="display:none">
            <p class="sheet-section-title">Ítems con combo</p>
            <div id="combos-resumen" style="font-size:13px;color:#065f46;background:#f0fdf4;
                 border-radius:8px;padding:8px 12px;line-height:1.7"></div>
        </div>

        <!-- Fecha de la venta (por defecto hoy, editable para registrar ventas pasadas) -->
        <div class="sheet-section">
            <p class="sheet-section-title">Fecha de la venta</p>
            <input type="date" id="fecha-venta-input" value="<?= date('Y-m-d') ?>"
                   max="<?= date('Y-m-d') ?>"
                   style="padding:8px 10px;border:1px solid var(--g8);border-radius:8px;font-size:13px;width:100%;color:var(--dark)">
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
// Mapa de combos precargado desde PHP.
// Clave: producto_id (número), valor: {combo_id, precio_adicional, nombre, insumos:[...]}
const COMBOS    = <?= json_encode($combo_map,    JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>;
const VARIANTES = <?= json_encode($variantes_map, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>;

// ---- Estado del carrito ----
// Clave: `${producto_id}-${es_combo}-${variante_id|'solo'}` — permite mismo producto en distintas variantes
let carrito             = {};
let metodoPago          = 'efectivo';
let clienteSeleccionado = '';

// ════════════════════════════════════════════════════════════════════════
//  AUTOCOMPLETE DE CLIENTES
//  Reemplaza el <select> estático por un buscador en tiempo real.
//  Filtra por nombre, apellido y empresa mientras el usuario escribe.
//  Incluye navegación con teclado (↑ ↓ Enter Escape) y touch-friendly.
// ════════════════════════════════════════════════════════════════════════

const CLIENTES_DATA = <?= $clientes_js ?>;
let acIndexActivo   = -1;   // índice del ítem resaltado con teclado
let acResultados    = [];   // subconjunto filtrado actual

/* Normaliza texto para búsqueda sin importar tildes ni mayúsculas.
   String() convierte null/undefined a '' para evitar bugs con apellidos vacíos. */
function acNorm(s) {
    return String(s || '').toLowerCase()
        .normalize('NFD').replace(/[̀-ͯ]/g, '');
}

/* Filtra la lista según el texto del input y renderiza el dropdown */
function acFiltrar() {
    const q     = acNorm(document.getElementById('cliente-buscar')?.value);
    const clear = document.getElementById('cliente-ac-clear');

    // Mostrar botón × cuando hay texto
    if (clear) clear.style.display = q ? '' : 'none';

    if (!q) {
        // Sin texto → mostrar todos (máx. 8 para no saturar)
        acResultados = CLIENTES_DATA.slice(0, 8);
    } else {
        acResultados = CLIENTES_DATA.filter(c => {
            const haystack = acNorm(c.nombre + ' ' + c.apellido + ' ' + c.empresa);
            return haystack.includes(q);
        }).slice(0, 10);
    }

    acIndexActivo = -1;
    acRenderDropdown();
    acAbrir();
}

/* Renderiza los ítems en el dropdown */
function acRenderDropdown() {
    const dd = document.getElementById('cliente-dropdown');
    if (!dd) return;
    if (acResultados.length === 0) {
        dd.innerHTML = '<div class="cli-opt-empty">Sin resultados</div>';
        return;
    }
    dd.innerHTML = acResultados.map((c, i) => {
        const nombreMostrar = c.nombre + (c.apellido ? ' ' + c.apellido : '');
        const empresa = c.empresa ? `<div class="cli-opt-empresa">${escAc(c.empresa)}</div>` : '';
        const deuda   = c.saldo > 0
            ? `<span class="cli-opt-deuda">Deuda $${Math.round(c.saldo).toLocaleString('es-CO')}</span>`
            : '';
        return `<div class="cli-opt" data-idx="${i}" data-id="${c.id}"
                     onclick="acSeleccionar(${i})"
                     onmouseenter="acResaltar(${i})"
                     role="option">
                    <div>
                        <div class="cli-opt-nombre">${escAc(nombreMostrar)}</div>
                        ${empresa}
                    </div>
                    ${deuda}
                </div>`;
    }).join('');
}

/* Abre el dropdown */
function acAbrir() {
    const dd = document.getElementById('cliente-dropdown');
    if (dd) dd.classList.add('open');
    // Si el dropdown está vacío por primera apertura (sin texto), cargar los primeros clientes
    if (acResultados.length === 0 && !document.getElementById('cliente-buscar')?.value) {
        acResultados = CLIENTES_DATA.slice(0, 8);
        acRenderDropdown();
    }
}

/* Cierra el dropdown */
function acCerrar() {
    const dd = document.getElementById('cliente-dropdown');
    if (dd) dd.classList.remove('open');
    acIndexActivo = -1;
}

/* Resalta un ítem con hover o teclado */
function acResaltar(idx) {
    acIndexActivo = idx;
    document.querySelectorAll('.cli-opt').forEach((el, i) => {
        el.classList.toggle('focused', i === idx);
    });
}

/* Selecciona un cliente del dropdown */
function acSeleccionar(idx) {
    const cli = acResultados[idx];
    if (!cli) return;

    clienteSeleccionado = cli.id;
    const nombre = cli.nombre + (cli.apellido ? ' ' + cli.apellido : '');

    // Mostrar chip y ocultar input
    document.getElementById('cliente-search-wrap').style.display = 'none';
    document.getElementById('cliente-chip').style.display        = '';
    document.getElementById('cliente-chip-nombre').textContent   = nombre;

    const deudaEl = document.getElementById('cliente-chip-deuda');
    if (cli.saldo > 0) {
        deudaEl.textContent = 'Deuda: $' + Math.round(cli.saldo).toLocaleString('es-CO');
        deudaEl.style.display = '';
    } else {
        deudaEl.style.display = 'none';
    }

    acCerrar();
}

/* Limpia la selección y vuelve al input */
function acLimpiar() {
    clienteSeleccionado = '';
    const input = document.getElementById('cliente-buscar');
    if (input) { input.value = ''; input.focus(); }
    document.getElementById('cliente-ac-clear').style.display = 'none';
    document.getElementById('cliente-search-wrap').style.display = '';
    document.getElementById('cliente-chip').style.display = 'none';
    acCerrar();
}

/* Navegación con teclado dentro del dropdown */
function acTecla(e) {
    const dd = document.getElementById('cliente-dropdown');
    if (!dd?.classList.contains('open')) {
        if (e.key === 'ArrowDown') { acAbrir(); acFiltrar(); }
        return;
    }
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        acResaltar(Math.min(acIndexActivo + 1, acResultados.length - 1));
        _acScrollToFocused();
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        acResaltar(Math.max(acIndexActivo - 1, 0));
        _acScrollToFocused();
    } else if (e.key === 'Enter' && acIndexActivo >= 0) {
        e.preventDefault();
        acSeleccionar(acIndexActivo);
    } else if (e.key === 'Escape') {
        acCerrar();
    }
}

/* Scroll del dropdown para que el ítem resaltado sea visible */
function _acScrollToFocused() {
    const focused = document.querySelector('.cli-opt.focused');
    if (focused) focused.scrollIntoView({ block: 'nearest' });
}

/* Escape de caracteres HTML para evitar XSS en el dropdown */
function escAc(s) {
    return String(s)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* Cerrar dropdown al hacer clic fuera del componente */
document.addEventListener('click', e => {
    const ac = document.getElementById('cliente-ac');
    if (ac && !ac.contains(e.target)) acCerrar();
});

/* API pública: seleccionar cliente por ID (usada al crear cliente nuevo) */
function acSeleccionarPorId(id, nombre, saldo) {
    // Añadir a CLIENTES_DATA si no existe (cliente recién creado)
    if (!CLIENTES_DATA.find(c => c.id === id)) {
        CLIENTES_DATA.unshift({ id, nombre, apellido: '', empresa: '', saldo: saldo || 0 });
    }
    const idx = CLIENTES_DATA.findIndex(c => c.id === id);
    acResultados = CLIENTES_DATA;
    acSeleccionar(CLIENTES_DATA.findIndex(c => c.id === id) >= 0 ? CLIENTES_DATA.findIndex(c => c.id === id) : 0);
    // Fallback directo si acSeleccionar no encontró el idx
    if (!clienteSeleccionado) {
        clienteSeleccionado = id;
        document.getElementById('cliente-search-wrap').style.display = 'none';
        document.getElementById('cliente-chip').style.display = '';
        document.getElementById('cliente-chip-nombre').textContent = nombre;
        const deudaEl = document.getElementById('cliente-chip-deuda');
        deudaEl.style.display = 'none';
    }
}

/* Resetear el selector al completar una venta (se llama desde procesarVenta tras éxito) */
function acReset() {
    acLimpiar();
    const wrap = document.getElementById('cliente-search-wrap');
    if (wrap) wrap.style.display = '';
}

// Estado del selector de combo y de variante
let _comboActual    = null; // { id, nombre, nombre2, precio, capacidad, varianteId?, varianteEtiq?, factorReceta? }
let _varianteActual = null; // { id, nombre, nombre2, precio_base, capacidad }

// ---- CARRITO ----

// nombre2: subtítulo complementario (puede ser '' si el producto no tiene)
function agregarAlCarrito(id, nombre, precio, capacidad, nombre2 = '') {
    if (capacidad === 0) {
        mostrarToast('Sin stock: ' + nombre, 'err');
        return;
    }

    // Si el producto tiene variantes de tamaño → mostrar selector de variante primero
    if (VARIANTES[id] && VARIANTES[id].length > 0) {
        _varianteActual = { id, nombre, nombre2, precio_base: precio, capacidad };
        abrirVariante(id, nombre);
        return;
    }

    // Si el producto tiene combo configurado → mostrar selector Solo/Combo
    if (COMBOS[id]) {
        _comboActual = { id, nombre, nombre2, precio, capacidad };
        mostrarSelectorCombo(id, nombre, precio);
        return;
    }

    // Producto sin variantes ni combo → agregar directamente
    _agregarItem(id, nombre, nombre2, precio, capacidad, 0, null, null, 1.0);
}

/**
 * Muestra el mini-sheet para elegir Solo o Combo.
 * Se llama cuando el producto tiene un combo configurado.
 */
function mostrarSelectorCombo(id, nombre, precio) {
    const combo       = COMBOS[id];
    const precioCombo = precio + combo.precio_adicional;
    const extras      = combo.insumos.join(', ');

    document.getElementById('combo-titulo').textContent       = nombre;
    document.getElementById('combo-extras').textContent       = 'Combo: ' + (combo.nombre || 'Combo');
    document.getElementById('combo-precio-solo').textContent  = formatPeso(precio);
    document.getElementById('combo-precio-combo').textContent = formatPeso(precioCombo);
    document.getElementById('combo-extras-combo').textContent = extras || 'Extras incluidos';

    document.getElementById('combo-overlay').classList.add('active');
    document.body.style.overflow = 'hidden';
}

/** Cierra el mini-sheet de combo sin agregar nada */
function cerrarCombo() {
    document.getElementById('combo-overlay').classList.remove('active');
    document.body.style.overflow = '';
    _comboActual = null;
}

/**
 * Llamado al pulsar "Solo" (esCombo=0) o "Combo" (esCombo=1) en el selector.
 */
function elegirOpcionCombo(esCombo) {
    if (!_comboActual) return;
    const { id, nombre, nombre2, precio, capacidad,
            varianteId = null, varianteEtiq = null, factorReceta = 1.0 } = _comboActual;
    const precioFinal = esCombo
        ? precio + (COMBOS[id]?.precio_adicional || 0)
        : precio;

    cerrarCombo();
    _agregarItem(id, nombre, nombre2 || '', precioFinal, capacidad, esCombo,
                 varianteId, varianteEtiq, factorReceta);
}

// ════════════════════════════════════════════════════════════════════════
//  VARIANTES DE TAMAÑO (migración 035)
// ════════════════════════════════════════════════════════════════════════

function abrirVariante(id, nombre) {
    const variantes = VARIANTES[id] || [];
    document.getElementById('variante-titulo').textContent = nombre;
    document.getElementById('variante-sub').textContent    = 'Elige el tamaño';

    const html = variantes.map(v =>
        `<button class="variante-opt"
                 onclick="seleccionarVariante(${id}, ${v.id}, ${JSON.stringify(v.etiqueta)}, ${v.precio_venta}, ${v.factor_receta})">
             <span>${escHtml(v.etiqueta)}</span>
             <span class="variante-opt-price">${formatPeso(v.precio_venta)}</span>
         </button>`
    ).join('');

    document.getElementById('variante-options').innerHTML = html;
    document.getElementById('variante-overlay').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function cerrarVariante() {
    document.getElementById('variante-overlay').classList.remove('active');
    document.body.style.overflow = '';
    _varianteActual = null;
}

function seleccionarVariante(prodId, varId, etiqueta, precio, factor) {
    const actual = _varianteActual; // capturar antes de cerrar (que lo pone en null)
    document.getElementById('variante-overlay').classList.remove('active');
    document.body.style.overflow = '';
    _varianteActual = null;
    if (!actual) return;

    const { nombre, nombre2, capacidad } = actual;

    // Si el producto también tiene combo → abrir selector combo con el precio de la variante
    if (COMBOS[prodId]) {
        _comboActual = { id: prodId, nombre, nombre2, precio, capacidad,
                         varianteId: varId, varianteEtiq: etiqueta, factorReceta: factor };
        mostrarSelectorCombo(prodId, `${nombre} (${etiqueta})`, precio);
        return;
    }

    _agregarItem(prodId, nombre, nombre2, precio, capacidad, 0, varId, etiqueta, factor);
}

/**
 * Agrega o incrementa un ítem en el carrito.
 * @param {number} id             producto_id
 * @param {string} nombre         nombre principal del producto
 * @param {string} nombre2        subtítulo complementario (puede ser '')
 * @param {number} precio         precio ya resuelto (precio de variante o base)
 * @param {number} capacidad
 * @param {number} esCombo        0 = solo, 1 = combo
 * @param {number|null} varianteId
 * @param {string|null} varianteEtiq
 * @param {number} factorReceta
 */
function _agregarItem(id, nombre, nombre2, precio, capacidad, esCombo,
                      varianteId = null, varianteEtiq = null, factorReceta = 1.0) {
    // Clave única: producto + modo combo + variante → permite combinar XL solo + XL combo, etc.
    const varKey = varianteId != null ? varianteId : 'solo';
    const key    = id + '-' + esCombo + '-' + varKey;
    if (!carrito[key]) {
        carrito[key] = {
            id, nombre, nombre2: nombre2 || '', precio, cantidad: 1, es_combo: esCombo,
            variante_id: varianteId, variante_etiqueta: varianteEtiq,
            factor_receta: factorReceta || 1.0,
        };
    } else {
        carrito[key].cantidad++;
    }
    actualizarUI();
    // Feedback visual en la tarjeta del producto
    const card = document.getElementById('card-' + id);
    if (card) {
        card.style.transform = 'scale(.93)';
        setTimeout(() => card.style.transform = '', 120);
    }
}

function quitarDelCarrito(key) {
    if (!carrito[key]) return;
    carrito[key].cantidad--;
    if (carrito[key].cantidad <= 0) delete carrito[key];
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

    // Badges individuales en las tarjetas: suma de solo + combo del mismo producto
    const porProducto = {};
    items.forEach(item => {
        porProducto[item.id] = (porProducto[item.id] || 0) + item.cantidad;
    });

    document.querySelectorAll('.prod-qty-badge').forEach(badge => {
        const pid = parseInt(badge.id.replace('qty-', ''));
        const card = document.getElementById('card-' + pid);
        const qty  = porProducto[pid] || 0;
        if (qty > 0) {
            badge.textContent = qty;
            badge.style.display = 'flex';
            card?.classList.add('en-carrito');
        } else {
            badge.style.display = 'none';
            card?.classList.remove('en-carrito');
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

    const html = items.map(item => {
        const varKey     = item.variante_id != null ? item.variante_id : 'solo';
        const key        = item.id + '-' + item.es_combo + '-' + varKey;
        const comboBadge = item.es_combo
            ? '<span class="badge-combo">COMBO</span>'
            : '';
        const varBadge   = item.variante_etiqueta
            ? `<span class="badge-variante">${escHtml(item.variante_etiqueta)}</span>`
            : '';
        // nombre2 se muestra como subtítulo gris debajo del nombre principal
        const sub2 = item.nombre2
            ? `<span style="display:block;font-size:10px;color:var(--g5);font-weight:400;margin-top:1px">${escHtml(item.nombre2)}</span>`
            : '';
        return `
        <div class="cart-item">
            <span class="cart-item-name">${escHtml(item.nombre)}${varBadge}${comboBadge}${sub2}</span>
            <span class="cart-item-qty">× ${item.cantidad}</span>
            <span class="cart-item-price">${formatPeso(item.precio * item.cantidad)}</span>
            <button class="cart-item-rm" onclick="quitarDelCarrito('${key}')" title="Quitar uno">−</button>
        </div>`;
    }).join('');

    document.getElementById('modal-items').innerHTML = html;
    document.getElementById('modal-total').textContent = formatPeso(total);

    // Mostrar resumen de combos si hay ítems combo en el carrito
    const combosEnCarrito = items.filter(i => i.es_combo);
    const secCombo = document.getElementById('combos-resumen-sec');
    if (combosEnCarrito.length > 0) {
        secCombo.style.display = '';
        document.getElementById('combos-resumen').innerHTML = combosEnCarrito
            .map(i => {
                // Mostrar nombre + nombre2 en el resumen de combos
                const fullName = i.nombre2 ? `${escHtml(i.nombre)} — ${escHtml(i.nombre2)}` : escHtml(i.nombre);
                return `✔ ${fullName} ×${i.cantidad} — incluye ${(COMBOS[i.id]?.insumos || []).join(', ') || 'extras del combo'}`;
            })
            .join('<br>');
    } else {
        secCombo.style.display = 'none';
    }
}

// ---- MÉTODO DE PAGO ----

function selMetodo(btn, metodo) {
    document.querySelectorAll('.metodo-btn').forEach(b => b.classList.remove('sel'));
    btn.classList.add('sel');
    metodoPago = metodo;
    // El selector de cliente siempre visible — label varía según método
    const titulo = document.querySelector('#fiado-section .sheet-section-title');
    if (titulo) {
        if (metodo === 'fiado')     titulo.textContent = 'Cliente (requerido para fiado)';
        else if (metodo === 'obsequio') titulo.textContent = 'Cliente a quien se obsequia (opcional)';
        else                        titulo.textContent = 'Cliente (opcional)';
    }
    // Cambiar label del botón confirmar para que sea claro cuando es obsequio
    const btnConf = document.getElementById('btn-confirmar');
    if (btnConf) btnConf.textContent = metodo === 'obsequio' ? '🎁 Registrar Obsequio' : 'Confirmar Venta';
    // Si cambia a otro método, no borramos el cliente ya seleccionado
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
        // Seleccionar el nuevo cliente en el autocomplete
        acSeleccionarPorId(data.id, data.nombre, 0);
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
    // Validar que haya ítems — mostrar feedback si el carrito está vacío
    if (Object.keys(carrito).length === 0) {
        mostrarToast('Agrega productos al carrito antes de confirmar', 'err');
        return;
    }

    // Validar cliente si es fiado — enfocar el buscador
    if (metodoPago === 'fiado' && !clienteSeleccionado) {
        mostrarToast('Selecciona un cliente para el fiado', 'err');
        document.getElementById('cliente-buscar')?.focus();
        return;
    }

    const btn = document.getElementById('btn-confirmar');
    btn.disabled = true;
    btn.textContent = 'Procesando…';

    // Armar carrito para enviar: incluir es_combo (mig.025) y variante (mig.035) por ítem
    const carritoArray = Object.values(carrito).map(i => ({
        producto_id:       i.id,
        cantidad:          i.cantidad,
        precio:            i.precio,
        es_combo:          i.es_combo || 0,
        variante_id:       i.variante_id       ?? null,
        variante_etiqueta: i.variante_etiqueta ?? null,
        factor_receta:     i.factor_receta      ?? 1.0,
    }));

    const fd = new FormData();
    fd.append('csrf_token',   document.getElementById('csrf-token').value);
    fd.append('metodo_pago',  metodoPago);
    fd.append('cliente_id',   clienteSeleccionado || '');
    fd.append('notas',        document.getElementById('notas-input').value.trim());
    fd.append('fecha_venta',  document.getElementById('fecha-venta-input').value || '');
    fd.append('carrito',      JSON.stringify(carritoArray));

    try {
        const resp = await fetch('api/procesar_venta.php', { method: 'POST', body: fd });
        const data = await resp.json();

        if (data.success) {
            cerrarModal();
            limpiarCarrito();
            mostrarToast('✓ Venta #' + data.venta_id + ' registrada', 'ok');
            // Actualizar contadores de stock en las tarjetas sin recargar página
            setTimeout(refrescarCapacidades, 600);
            // Resetear inputs del modal y selector de cliente
            document.getElementById('notas-input').value = '';
            document.getElementById('fecha-venta-input').value = new Date().toISOString().split('T')[0];
            acReset();           // limpia el autocomplete y resetea clienteSeleccionado
            clienteSeleccionado = '';
            // Volver a efectivo y restaurar label del botón
            document.querySelectorAll('.metodo-btn').forEach(b => b.classList.remove('sel'));
            document.querySelector('.metodo-btn').classList.add('sel');
            metodoPago = 'efectivo';
            document.getElementById('btn-confirmar').textContent = 'Confirmar Venta';
            // fiado-section siempre visible (display:block) — no hay clase que quitar
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
            else if (cap === 9999)  { cls = 'cap-ok';      txt = 'Disponible'; }
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

// Cerrar modales con tecla Escape
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        cerrarModal();
        cerrarCombo();
    }
});
</script>

</body>
</html>
