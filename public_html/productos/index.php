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
require_once __DIR__ . '/../app/models/CostoIndirectoModel.php';
require_once __DIR__ . '/../app/helpers/ListasHelper.php';

$nav_activo = 'productos';
permiso_requerir('productos', 'solo_ver');

// ── Parámetros de producción ──────────────────────────────────────────────────
$cfg = db()->query(
    "SELECT clave, valor FROM configuracion_negocio
     WHERE clave IN ('costos_fijos_mensuales','produccion_estimada_mensual')"
)->fetchAll(PDO::FETCH_KEY_PAIR);

$costos_fijos_legacy = (float)($cfg['costos_fijos_mensuales']     ?? 0);
$prod_mes            = (float)($cfg['produccion_estimada_mensual'] ?? 2175);
$prod_dia            = max(1, round($prod_mes / 21.75));

// Costos indirectos: si hay registros en el módulo Costos los usamos (más precisos).
// Si no hay ninguno registrado, caemos al valor hardcodeado de configuracion_negocio.
$costos_indirectos_total = CostoIndirectoModel::total_mensual_activo();
$costos_fijos = $costos_indirectos_total > 0 ? $costos_indirectos_total : $costos_fijos_legacy;

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
    "SELECT p.id, p.nombre, p.nombre2, p.tamano, p.categoria, p.precio_venta, p.activo,
            IFNULL(p.costo_calculado, 0) AS costo_ing,
            IFNULL(p.unidades_por_receta, 1) AS unidades_por_receta,
            IFNULL(p.stock_disponible, 0)    AS stock_disponible,
            IFNULL(p.stock_minimo, 0)        AS stock_minimo
     FROM productos p
     ORDER BY p.activo DESC, p.categoria, p.nombre,
              FIELD(p.tamano,'XL','L','unico')"
)->fetchAll();

$fijos_u = $costo_fijo_u + $costo_deprec_u + $costo_rh_u;
foreach ($productos as &$p) {
    $p['costo_total']  = round((float)$p['costo_ing'] + $fijos_u, 2);
    $precio = (float)$p['precio_venta'];
    $p['margen_pesos'] = $precio > 0 ? round($precio - $p['costo_total'], 2) : 0;
    $p['margen_pct']   = $precio > 0 ? round(($precio - $p['costo_total']) / $precio * 100, 1) : 0;
    $p['stock_bajo']   = (int)$p['stock_minimo'] > 0
                         && (int)$p['stock_disponible'] < (int)$p['stock_minimo'];
}
unset($p);

$insumos_todos   = InsumoModel::todos();

// Catálogos configurables desde Admin → Catálogos (migración 029b).
// Fallback a arrays hardcodeados si la migración aún no está aplicada.
$_cats_lista  = listas_map('categoria_producto');
$_tams_lista  = listas_map('tamano_producto');

$CATS_PRODUCTO = !empty($_cats_lista) ? $_cats_lista : [
    'sandwich'  => 'Sándwich',
    'combo'     => 'Combo',
    'bebida'    => 'Bebida',
    'adicional' => 'Adicional',
];
$TAMS_PRODUCTO = !empty($_tams_lista) ? $_tams_lista : [
    'XL'    => 'XL',
    'L'     => 'L',
    'unico' => 'Único',
];

$total_prods     = count($productos);
$buenos          = count(array_filter($productos, fn($p) => $p['margen_pct'] >= 40));
$en_riesgo       = count(array_filter($productos, fn($p) => $p['margen_pct'] < 20 && (float)$p['precio_venta'] > 0));
$stock_bajo_cnt  = count(array_filter($productos, fn($p) => $p['stock_bajo']));
$stock_total     = array_sum(array_column($productos, 'stock_disponible'));
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
        .card { background:var(--white); border-radius:14px; box-shadow:0 1px 4px rgba(0,0,0,.06); overflow:hidden; overflow-x:auto; margin-bottom:16px; }
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
        /* max-height + overflow-y garantizan scroll cuando el contenido supera
           el viewport (múltiples campos en pantalla pequeña o ventana reducida).
           El encabezado NO es sticky aquí (el modal usa padding:24px directo).
           nav.php inyecta la regla global .modal-hdr { position:sticky; top:0 }
           que sí funciona cuando la estructura usa padding en hijos, no en el padre. */
        .modal { background:var(--white); border-radius:16px; padding:24px; width:100%; max-width:440px;
                 max-height:90vh; max-height:90dvh;
                 overflow-y:auto; -webkit-overflow-scrolling:touch; }
        .modal-hdr { font-size:16px; font-weight:800; margin-bottom:16px;
                     display:flex; justify-content:space-between; align-items:center; }
        .fg { display:flex; flex-direction:column; gap:4px; margin-bottom:12px; }
        .fg label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--g5); }
        .fg input, .fg select { padding:10px 12px; border:2px solid var(--g8); border-radius:10px; font-size:15px; color:var(--dark); outline:none; width:100%; }
        .btn-cls { background:var(--g9); border:none; color:var(--g5); width:30px; height:30px; border-radius:50%; cursor:pointer; font-size:16px; }
        .btn-full { width:100%; padding:12px; background:var(--brand); color:#fff; border:none; border-radius:10px; font-size:15px; font-weight:800; cursor:pointer; }
        /* Sección combo dentro del expandable */
        .combo-sec { margin-top:14px; padding-top:14px; border-top:1px solid var(--g8); }
        .combo-sec-title { font-size:11px; font-weight:800; text-transform:uppercase; color:#059669; letter-spacing:.4px; margin-bottom:10px; }
        .combo-badge { display:inline-block; font-size:10px; font-weight:800; background:#d1fae5; color:#065f46; padding:2px 8px; border-radius:20px; margin-left:8px; vertical-align:middle; }
        .combo-form-row { display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end; margin-bottom:8px; }
        .combo-form-row label { font-size:11px; font-weight:700; color:var(--g5); display:block; margin-bottom:3px; }
        .combo-form-row input, .combo-form-row select { padding:7px 10px; border:2px solid var(--g8); border-radius:8px; font-size:13px; outline:none; }
        .combo-form-row input:focus, .combo-form-row select:focus { border-color:var(--green); }
        .combo-ins-list { margin:8px 0; }
        .combo-ins-row { display:flex; align-items:center; gap:8px; padding:5px 0; border-bottom:1px dashed var(--g8); font-size:12px; }
        .combo-ins-row:last-child { border-bottom:none; }
        .btn-combo-save { padding:8px 18px; background:#059669; color:#fff; border:none; border-radius:8px; font-size:12px; font-weight:700; cursor:pointer; }
        .btn-combo-del  { padding:8px 14px; background:#fee2e2; color:#991b1b; border:none; border-radius:8px; font-size:12px; font-weight:700; cursor:pointer; }
        /* Toast */
        .toast { position:fixed; bottom:20px; left:50%; transform:translateX(-50%) translateY(20px); padding:10px 20px; border-radius:24px; font-size:14px; font-weight:600; opacity:0; transition:.25s; z-index:99; pointer-events:none; }
        .toast.on { opacity:1; transform:translateX(-50%) translateY(0); }
        .toast-ok  { background:#065f46; color:#d1fae5; }
        .toast-err { background:#991b1b; color:#fee2e2; }

        /* ════════════════════════════════════════════════════════════════
           RESPONSIVE — PRODUCTOS
           Tabla cols: 1=Producto 2=Precio(hide-m) 3=Insumos(hide-m)
                       4=Fijos+RH(hide-m) 5=Costo Total 6=Margen 7=Acciones
           .hide-m ya oculta 2,3,4 en ≤640px — aquí se refuerza y amplía
           ════════════════════════════════════════════════════════════════ */

        /* ── Stats (4 columnas): 2 en móvil ── */
        @media (max-width: 479px) {
            /* Banner con 4 métricas: 2 columnas en móvil */
            .banner-grid { grid-template-columns: 1fr 1fr !important; }
            .main { padding: 12px 10px 40px; }
            /* La tabla se vuelve tarjetas vía .rcards (nav.php). La fila de receta
               expandible (recipe-row) no es una tarjeta: se muestra como bloque
               continuo bajo el producto. */
            .rcards tr.recipe-row { display:none; border:none; box-shadow:none; padding:0;
                                    margin:-8px 0 10px; background:transparent; }
            .rcards tr.recipe-row.open { display:block; }
            .rcards tr.recipe-row td { display:block; padding:0; border:none !important; }
            .rcards tr.recipe-row td::before { content:none; }
            /* Paneles de receta/combo: inputs de ancho fijo se adaptan al contenedor */
            .combo-form-row > div { flex: 0 0 100%; }
            .combo-form-row input, .combo-form-row select { width: 100% !important; box-sizing: border-box; }
            .add-row input[type="number"] { width: 100% !important; }
            .ing-table { display: block; overflow-x: auto; }
        }

        /* ── Teléfono horizontal (480-639px): ocultar Fijos+RH ── */
        @media (max-width: 639px) {
            /* .hide-m ya oculta cols 2,3,4 — agregar Margen (6) en móvil pequeño */
            /* En 480-639 el .hide-m ya hace su trabajo — no necesitamos más */
        }

        /* ── Tablet (640-1023px) ── */
        @media (min-width: 640px) and (max-width: 1023px) {
            .main { max-width: 100%; padding: 16px 18px 60px; }
            /* Stats 4 columnas — caben bien en tablet */
            .stats { grid-template-columns: repeat(4, 1fr); }
            /* En tablet mostrar precio y costo total pero ocultar insumos y fijos */
            table thead tr th:nth-child(3), table tbody tr td:nth-child(3),
            table thead tr th:nth-child(4), table tbody tr td:nth-child(4) { display: none; }
        }

        /* ── Escritorio pequeño (1024-1279px): mostrar todas las columnas ── */
        @media (min-width: 1024px) {
            /* Mostrar todas las columnas ocultas con .hide-m */
            .hide-m { display: table-cell !important; }
        }

        /* ── Pantalla grande (≥1600px) ── */
        @media (min-width: 1600px) {
            .main  { max-width: 1440px !important; padding: 24px 32px 60px !important; }
            .stat-n { font-size: 28px; }
            .bn-val { font-size: 22px; }
            table th { font-size: 11px !important; padding: 11px 16px !important; }
            table td { font-size: 14px !important; padding: 12px 16px !important; }
        }

        /* ── TV (≥1920px) ── */
        @media (min-width: 1920px) {
            .main  { max-width: 1680px !important; }
            .stat-n { font-size: 34px; }
            .bn-val { font-size: 28px; }
            table th { font-size: 13px !important; padding: 14px 20px !important; }
            table td { font-size: 16px !important; padding: 14px 20px !important; }
        }
    </style>
</head>
<body>

<?php include __DIR__ . '/../app/views/nav.php'; ?>

<main class="main">

    <!-- Acceso rápido a producción y herramientas admin -->
    <div style="display:flex;justify-content:flex-end;gap:8px;margin-bottom:10px;flex-wrap:wrap">
        <?php if (permiso_tiene('admin', 'admin_total')): ?>
        <a href="<?= APP_BASE ?>/productos/consolidar.php"
           style="background:#f8faff;color:#1e40af;border:1px solid #bfdbfe;text-decoration:none;padding:8px 14px;
                  border-radius:8px;font-size:13px;font-weight:600">
            🔀 Consolidar productos
        </a>
        <?php endif; ?>
        <a href="<?= APP_BASE ?>/productos/produccion.php"
           style="background:var(--brand);color:#fff;text-decoration:none;padding:8px 16px;
                  border-radius:8px;font-size:13px;font-weight:700">
            Registro de Producción
        </a>
    </div>

    <div class="stats" style="grid-template-columns:repeat(6,1fr)">
        <div class="stat"><div class="stat-n"><?= $total_prods ?></div><div class="stat-l">Productos</div></div>
        <div class="stat"><div class="stat-n" style="color:var(--green)"><?= $buenos ?></div><div class="stat-l">Margen ≥40%</div></div>
        <div class="stat"><div class="stat-n" style="color:var(--brand)"><?= $en_riesgo ?></div><div class="stat-l">En riesgo</div></div>
        <div class="stat"><div class="stat-n" style="color:var(--green)"><?= $stock_total ?></div><div class="stat-l">Stock disponible</div></div>
        <div class="stat <?= $stock_bajo_cnt > 0 ? 'style="border:1px solid #fca5a5"' : '' ?>">
            <div class="stat-n" style="color:<?= $stock_bajo_cnt > 0 ? 'var(--brand)' : 'var(--g5)' ?>">
                <?= $stock_bajo_cnt ?>
            </div>
            <div class="stat-l">Stock bajo</div>
        </div>
        <div class="stat"><div class="stat-n">$<?= fmt_cantidad($dep_diaria, 2) ?></div><div class="stat-l">Dep. diaria</div></div>
    </div>

    <!-- Banner de capacidad instalada — editable -->
    <div style="background:#fff;border:1px solid var(--g8);border-radius:12px;padding:12px 16px;margin-bottom:14px;display:flex;align-items:center;gap:12px;flex-wrap:wrap">
        <strong style="font-size:13px;color:var(--dark)">Capacidad instalada:</strong>
        <div style="display:flex;align-items:center;gap:8px">
            <input type="number" id="prod-estimada-input" value="<?= (int)$prod_mes ?>"
                   min="1" step="100" style="width:90px;padding:5px 8px;border:1px solid var(--g8);border-radius:8px;font-size:13px">
            <span style="font-size:13px;color:var(--g5)">sándwiches/mes</span>
            <button onclick="actualizarCapacidad()" style="padding:6px 12px;background:var(--brand);color:#fff;border:none;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer">
                Actualizar y recalcular
            </button>
        </div>
        <span style="font-size:12px;color:var(--g5);margin-left:auto;display:flex;align-items:center;gap:10px">
            Producción diaria estimada: <strong><?= (int)$prod_dia ?> u/día</strong>
            · <a href="<?= APP_BASE ?>/productos/analisis.php" style="color:var(--brand);text-decoration:none;font-weight:700">
                Ver análisis y punto de equilibrio →
            </a>
            <?php if (permiso_tiene('productos', 'editar_existentes')): ?>
            · <button onclick="recalcularCostos()"
                    title="Recalcula costo_calculado de todos los productos a partir de las recetas e insumos actuales — útil tras editar precios de insumos en lote o aplicar migraciones"
                    style="padding:4px 10px;background:#fff;color:var(--brand);border:1px solid var(--brand);border-radius:8px;font-size:11px;font-weight:700;cursor:pointer">
                ↻ Recalcular costos
            </button>
            <?php endif; ?>
        </span>
    </div>

    <div class="banner">
        <strong style="font-size:13px">Costos fijos prorrateados por unidad producida</strong>
        <div class="banner-grid">
            <div><div class="bn-val">$<?= fmt_moneda($costo_fijo_u) ?></div><div class="bn-lbl">Arriendo/Servicios</div></div>
            <div><div class="bn-val">$<?= fmt_moneda($costo_deprec_u) ?></div><div class="bn-lbl">Depreciación</div></div>
            <div><div class="bn-val">$<?= fmt_moneda($costo_rh_u) ?></div><div class="bn-lbl">Recurso Humano</div></div>
            <div><div class="bn-val">$<?= fmt_moneda($fijos_u) ?></div><div class="bn-lbl">Total fijos/u</div></div>
        </div>
    </div>

    <?php if (permiso_tiene('productos','editar_existentes')): ?>
    <style>
        .tab-btn{padding:8px 16px;border:1px solid var(--g8);background:var(--white);border-radius:10px 10px 0 0;font-size:13px;font-weight:700;cursor:pointer;color:var(--g5)}
        .tab-btn.on{background:var(--brand);color:#fff;border-color:var(--brand)}
        .cr-lbl{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:var(--g5);display:block;margin-bottom:3px}
        .cr-inp{padding:8px 10px;border:1px solid var(--g8);border-radius:8px;font-size:13px;width:100%}
        .cr-row{display:flex;gap:8px;align-items:center;margin-bottom:6px;flex-wrap:wrap}
    </style>
    <div style="display:flex;gap:4px;margin-bottom:0">
        <button class="tab-btn on" id="tabb-catalogo" onclick="mostrarTabProd('catalogo')">Catálogo</button>
        <button class="tab-btn" id="tabb-constructor" onclick="mostrarTabProd('constructor')">🧪 Constructor de recetas</button>
    </div>
    <?php endif; ?>

    <div id="tab-catalogo">
    <div class="card rcards-wrap">
        <div class="card-title">
            Catálogo de Productos
            <?php if (permiso_tiene('productos','editar_existentes')): ?>
            <button class="btn-add" onclick="document.getElementById('modal-np').classList.add('on')">+ Nuevo</button>
            <?php endif; ?>
        </div>
        <table class="rcards">
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
                    <span style="font-size:10px;background:var(--g9);padding:1px 7px;border-radius:20px;margin-left:5px;font-weight:700"><?= htmlspecialchars($p['tamano']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($p['nombre2'])): ?>
                    <div style="font-size:11px;color:var(--g5);margin-top:2px"><?= htmlspecialchars($p['nombre2']) ?></div>
                    <?php endif; ?>
                    <!-- Stock de producto terminado -->
                    <?php if ((int)$p['stock_bajo']): ?>
                    <span style="font-size:10px;background:#fee2e2;color:#991b1b;padding:2px 7px;border-radius:20px;margin-left:4px;font-weight:700">
                        Stock bajo: <?= (int)$p['stock_disponible'] ?>
                    </span>
                    <?php elseif ((int)$p['stock_disponible'] > 0): ?>
                    <span style="font-size:10px;background:#d1fae5;color:#065f46;padding:2px 7px;border-radius:20px;margin-left:4px;font-weight:700">
                        Stock: <?= (int)$p['stock_disponible'] ?>
                    </span>
                    <?php endif; ?>
                </td>
                <td class="r hide-m" data-label="Precio"><?= (float)$p['precio_venta'] > 0 ? '$'.fmt_moneda($p['precio_venta']) : '<em style="color:var(--g5)">—</em>' ?></td>
                <td class="r hide-m" data-label="Insumos">$<?= fmt_moneda($p['costo_ing']) ?></td>
                <td class="r hide-m" data-label="Fijos+RH">$<?= fmt_moneda($fijos_u) ?></td>
                <td class="r" data-label="Costo Total"><strong>$<?= fmt_moneda($p['costo_total']) ?></strong></td>
                <td class="r" data-label="Margen">
                    <?php if ((float)$p['precio_venta'] > 0): ?>
                    <span class="<?= $mc ?>"><?= $mg ?>%</span>
                    <span class="mg-bar"><span class="mg-fill" style="width:<?= max(0,min(100,$mg)) ?>%;background:<?= $fc ?>"></span></span>
                    <?php else: ?><span style="color:var(--g5)">—</span><?php endif; ?>
                </td>
                <td class="acc-cell" style="white-space:nowrap">
                    <?php if (permiso_tiene('productos','editar_existentes')): ?>
                    <button class="exp-btn ic ic-edit" title="Editar"
                            onclick="abrirEditarProd(<?= htmlspecialchars(json_encode([
                                'id'                  => $p['id'],
                                'nombre'              => $p['nombre'],
                                'nombre2'             => $p['nombre2'] ?? '',
                                'categoria'           => $p['categoria'],
                                'tamano'              => $p['tamano'],
                                'precio_venta'        => $p['precio_venta'],
                                'unidades_por_receta' => (int)$p['unidades_por_receta'],
                                'stock_minimo'        => (int)$p['stock_minimo'],
                            ])) ?>)"
                            ><?= IC_EDIT ?></button>
                    <button class="exp-btn ic ic-ok" title="Duplicar"
                            onclick="duplicarProd(<?= $p['id'] ?>, '<?= htmlspecialchars(addslashes($p['nombre'])) ?>')"
                            ><?= IC_COPY ?></button>
                    <?php if ((int)$p['stock_disponible'] > 0): ?>
                    <!-- Regalar unidades de producto terminado (obsequio) -->
                    <button class="exp-btn ic ic-gift" title="Regalar / Obsequio"
                            onclick="abrirAjuste(<?= $p['id'] ?>,'<?= htmlspecialchars(addslashes($p['nombre'])) ?>',<?= (int)$p['stock_disponible'] ?>,'obsequio')"
                            ><?= IC_GIFT ?></button>
                    <!-- Desechar producto dañado o vencido -->
                    <button class="exp-btn ic ic-view" title="Desechar / Dar de baja"
                            onclick="abrirAjuste(<?= $p['id'] ?>,'<?= htmlspecialchars(addslashes($p['nombre'])) ?>',<?= (int)$p['stock_disponible'] ?>,'desecho')"
                            ><?= IC_TRASH ?></button>
                    <?php endif; ?>
                    <?php endif; ?>
                    <button class="exp-btn ic ic-view" title="Ver receta"
                            onclick="toggleReceta(<?= $p['id'] ?>,<?= $p['precio_venta'] ?>,<?= (int)$p['unidades_por_receta'] ?>)"><?= IC_CHEV ?></button>
                </td>
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
    </div><!-- /tab-catalogo -->

    <?php if (permiso_tiene('productos','editar_existentes')): ?>
    <!-- ══ TAB: CONSTRUCTOR DE RECETAS ══════════════════════════════════════ -->
    <div id="tab-constructor" style="display:none">
        <div class="card">
            <div class="card-title">🧪 Constructor de recetas</div>
            <div style="padding:14px 18px">
                <p style="font-size:12px;color:var(--g5);margin-bottom:14px">
                    Elige un producto, indica cuántas unidades <strong>salen</strong> (rinde) y arma su lista de
                    ingredientes. Al guardar se <strong>reemplaza</strong> la receta de ese producto.
                </p>
                <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end;margin-bottom:16px">
                    <div style="flex:1;min-width:220px">
                        <label class="cr-lbl">Producto</label>
                        <select id="cr-prod" class="cr-inp" onchange="crCargarProducto()"></select>
                    </div>
                    <div>
                        <label class="cr-lbl">¿Cuántos salen? (rinde)</label>
                        <input type="number" id="cr-rinde" class="cr-inp" min="1" step="1" value="1" style="width:130px">
                    </div>
                </div>

                <label class="cr-lbl">Ingredientes</label>
                <div id="cr-ings" style="margin-bottom:8px"></div>
                <button class="btn-sm" onclick="crAddIng()">+ Agregar ingrediente</button>
                <div style="margin-top:14px">
                    <button class="btn-sm btn-grn" style="padding:9px 18px" onclick="crGuardar()">💾 Guardar receta del producto</button>
                </div>

                <div style="margin-top:20px;border-top:1px solid var(--g9);padding-top:14px">
                    <p style="font-size:13px;font-weight:800;margin-bottom:3px">Traer ingredientes de otros productos</p>
                    <p style="font-size:12px;color:var(--g5);margin-bottom:10px">
                        Trae los ingredientes de uno o varios productos (cada uno a su <strong>porcentaje</strong>, ej. 60%)
                        y los <strong>suma a la lista de arriba</strong>. Los insumos repetidos se unifican sumando.
                        Revisa las cantidades y luego pulsa <strong>"Guardar receta del producto"</strong>.
                    </p>
                    <div id="cr-origenes" style="margin-bottom:8px"></div>
                    <button class="btn-sm" onclick="crAddOrigen()">+ Agregar producto origen</button>
                    <div style="margin-top:12px;display:flex;gap:6px;flex-wrap:wrap">
                        <button class="btn-sm btn-grn" onclick="crTraer('reemplazar')">Reemplazar receta</button>
                        <button class="btn-sm" style="background:#dbeafe;color:#1d4ed8" onclick="crTraer('sumar')">Sumar a la actual</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</main>

<!-- Modal EDITAR producto -->
<?php if (permiso_tiene('productos','editar_existentes')): ?>
<div class="overlay" id="modal-ep" onclick="if(event.target===this)this.classList.remove('on')">
    <div class="modal">
        <div class="modal-hdr">
            Editar Producto
            <button class="btn-cls" onclick="document.getElementById('modal-ep').classList.remove('on')">✕</button>
        </div>
        <input type="hidden" id="ep-id">
        <div class="fg"><label>Nombre *</label>
            <input id="ep-nom" placeholder="Ej: Sandwich de Pollo" maxlength="150"></div>
        <div class="fg"><label>Nombre complementario (opcional)</label>
            <input id="ep-nom2" placeholder="Ej: con papas criollas" maxlength="120">
            <span style="font-size:11px;color:var(--g5)">Se muestra como subtítulo en el POS, producción, stock y reportes.</span>
        </div>
        <div class="fg"><label>Categoría</label>
            <select id="ep-cat">
                <?php foreach ($CATS_PRODUCTO as $val => $lbl): ?>
                <option value="<?= htmlspecialchars($val) ?>"><?= htmlspecialchars($lbl) ?></option>
                <?php endforeach; ?>
            </select></div>
        <div class="fg"><label>Tamaño</label>
            <select id="ep-tam">
                <?php foreach ($TAMS_PRODUCTO as $val => $lbl): ?>
                <option value="<?= htmlspecialchars($val) ?>"><?= htmlspecialchars($lbl) ?></option>
                <?php endforeach; ?>
            </select></div>
        <div class="fg"><label>Precio de Venta ($)</label>
            <input type="number" id="ep-precio" min="0" step="500" placeholder="18000"></div>
        <div class="fg">
            <label>Sándwiches por tanda de receta</label>
            <input type="number" id="ep-rinde" min="1" step="1" value="1">
            <span style="font-size:11px;color:var(--g5)">
                Define cuántos sándwiches produce UNA tanda completa de la receta.<br>
                El costo/u = costo total ingredientes &divide; este número.<br>
                Si cambias este valor se recalcula el margen automáticamente.
            </span>
        </div>
        <div class="fg">
            <label>Stock mínimo (alerta)</label>
            <input type="number" id="ep-stmin" min="0" step="1" value="0">
            <span style="font-size:11px;color:var(--g5)">Badge rojo cuando stock terminado cae por debajo de este valor.</span>
        </div>
        <button class="btn-full" onclick="guardarEditarProd()">Guardar Cambios</button>
    </div>
</div>
<?php endif; ?>

<!-- Modal NUEVO producto -->
<div class="overlay" id="modal-np" onclick="if(event.target===this)this.classList.remove('on')">
    <div class="modal">
        <div class="modal-hdr">
            Nuevo Producto
            <button class="btn-cls" onclick="document.getElementById('modal-np').classList.remove('on')">✕</button>
        </div>
        <div class="fg"><label>Nombre *</label><input id="np-nom" placeholder="Ej: Sandwich de Pollo" maxlength="150"></div>
        <div class="fg"><label>Nombre complementario (opcional)</label>
            <input id="np-nom2" placeholder="Ej: con papas criollas" maxlength="120">
            <span style="font-size:11px;color:var(--g5)">Subtítulo visible en POS, producción y reportes.</span>
        </div>
        <div class="fg"><label>Categoría</label>
            <select id="np-cat">
                <?php foreach ($CATS_PRODUCTO as $val => $lbl): ?>
                <option value="<?= htmlspecialchars($val) ?>"><?= htmlspecialchars($lbl) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="fg"><label>Tamaño</label>
            <select id="np-tam">
                <?php foreach ($TAMS_PRODUCTO as $val => $lbl): ?>
                <option value="<?= htmlspecialchars($val) ?>"><?= htmlspecialchars($lbl) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="fg"><label>Precio de Venta ($)</label><input type="number" id="np-precio" min="0" step="500" placeholder="18000"></div>
        <div class="fg">
            <label>Sándwiches por tanda de receta</label>
            <input type="number" id="np-rinde" min="1" step="1" value="1" placeholder="1">
            <span style="font-size:11px;color:var(--g5)">Cuántas unidades produce una tanda completa de la receta. Divide el costo de ingredientes entre este número para obtener el costo por sándwich.</span>
        </div>
        <div class="fg">
            <label>Stock mínimo (alerta)</label>
            <input type="number" id="np-stmin" min="0" step="1" value="0" placeholder="0">
            <span style="font-size:11px;color:var(--g5)">Cantidad mínima de producto terminado antes de mostrar alerta de stock bajo.</span>
        </div>
        <button class="btn-full" onclick="guardarNuevoProducto()">Guardar Producto</button>
    </div>
</div>

<!-- Modal: Regalar / Desechar producto terminado en stock -->
<?php if (permiso_tiene('productos','editar_existentes')): ?>
<div class="overlay" id="modal-ajuste" onclick="if(event.target===this)this.classList.remove('on')">
    <div class="modal">
        <div class="modal-hdr">
            <span id="ajuste-titulo">Ajuste de Stock</span>
            <button class="btn-cls" onclick="document.getElementById('modal-ajuste').classList.remove('on')">✕</button>
        </div>
        <input type="hidden" id="ajuste-pid">
        <input type="hidden" id="ajuste-tipo">
        <div class="fg">
            <label id="ajuste-lbl-cantidad">Cantidad a ajustar</label>
            <input type="number" id="ajuste-cantidad" min="1" step="1" placeholder="1">
            <span id="ajuste-stock-disp" style="font-size:11px;color:var(--g5)"></span>
        </div>
        <div class="fg">
            <label>Motivo (opcional)</label>
            <input type="text" id="ajuste-motivo" maxlength="300" placeholder="Ej: producto vencido, muestra para cliente, etc.">
        </div>
        <button class="btn-full" id="ajuste-btn" onclick="guardarAjuste()">Confirmar</button>
    </div>
</div>
<?php endif; ?>

<div class="toast" id="toast"></div>
<input type="hidden" id="csrf-tk" value="<?= htmlspecialchars(csrf_token()) ?>">

<script>
const INSUMOS = <?= json_encode(array_map(
    fn($i) => ['id'=>(int)$i['id'],'nombre'=>$i['nombre'],'unidad'=>$i['unidad_medida'],'costo'=>(float)$i['costo_actual'],
               'equiv_cantidad'=>(float)($i['equiv_cantidad'] ?? 0),'equiv_unidad'=>$i['equiv_unidad'] ?? ''],
    $insumos_todos
), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>;

const FIJOS_U = <?= json_encode(['fijo'=>$costo_fijo_u,'deprec'=>$costo_deprec_u,'rh'=>$costo_rh_u,'total'=>$fijos_u]) ?>;

// Productos activos (para copiar/combinar recetas y el Constructor de recetas)
const PRODUCTOS_RECETA = <?= json_encode(array_values(array_map(
    fn($p) => ['id'=>(int)$p['id'],'nombre'=>$p['nombre'],'tamano'=>$p['tamano'] ?? '','nombre2'=>$p['nombre2'] ?? '','rinde'=>(int)($p['unidades_por_receta'] ?? 1)],
    array_filter($productos, fn($p) => (int)($p['activo'] ?? 0) === 1)
)), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>;

const cache = {};
const recetaParams = {};   // [prodId => {precio, rinde, combo, variantes}] para re-render en sitio
const csrf  = () => document.getElementById('csrf-tk').value;

// Re-renderiza la receta SIN colapsarla (re-lee ingredientes y reusa precio/rinde/
// combo/variantes ya cargados). Para reflejar cambios y seguir editando.
async function refrescarReceta(id) {
    const p = recetaParams[id];
    if (!p) { reloadReceta(id); return; }
    try {
        const r = await fetch('api/ingredientes.php?producto_id=' + id);
        renderReceta(id, await r.json(), p.precio, p.rinde, p.combo, p.variantes);
    } catch (e) { reloadReceta(id); }
}

// ── Expandir fila de receta ─────────────────────────────────────────────────
async function toggleReceta(id, precio, rinde) {
    const row = document.getElementById('rr-' + id);
    // El último exp-btn de la fila es el de expandir (▾/▴)
    const btns = row.previousElementSibling?.querySelectorAll('.exp-btn');
    const expBtn = btns ? btns[btns.length - 1] : null;
    row.classList.toggle('open');
    if (expBtn) expBtn.textContent = row.classList.contains('open') ? '▴' : '▾';
    if (!row.classList.contains('open') || cache[id]) return;

    // Carga ingredientes, combo y variantes en paralelo para no bloquear la UI
    const [r1, r2, r3] = await Promise.all([
        fetch('api/ingredientes.php?producto_id=' + id),
        fetch('api/combo_crud.php?producto_id=' + id).catch(() => null),
        fetch('api/variantes_crud.php?producto_id=' + id).catch(() => null),
    ]);
    const ings      = await r1.json();
    const combo     = r2 ? await r2.json().catch(() => null) : null;
    const varData   = r3 ? await r3.json().catch(() => null) : null;
    const variantes = varData?.variantes ?? [];
    cache[id] = true;

    renderReceta(id, ings, precio, rinde || 1, combo, variantes);
}

function renderReceta(id, ings, precio, rinde, combo, variantes) {
    rinde = parseInt(rinde) || 1;
    recetaParams[id] = { precio, rinde, combo, variantes };   // para re-render en sitio
    let totalIng = 0;
    const puedEdt = <?= permiso_tiene('productos','editar_existentes') ? 'true' : 'false' ?>;

    // Nota sobre rendimiento de tanda cuando rinde > 1
    const notaRinde = rinde > 1
        ? `<p style="font-size:12px;color:#3b82f6;margin-bottom:8px;font-weight:600">
               Tanda: las cantidades abajo son para <strong>${rinde} sándwiches</strong>.
               El costo/u ya está dividido por ${rinde}.
           </p>`
        : '';

    let tblRows = '';
    ings.forEach(i => {
        totalIng += parseFloat(i.costo_linea || 0);
        const crit = i.es_insumo_critico == 1 ? '<span class="badge-crit">⚡crítico</span>' : '';
        const base = i.es_base == 1
            ? '<span style="background:#d1fae5;color:#065f46;padding:1px 6px;border-radius:10px;font-size:10px;font-weight:600;margin-left:3px" title="Cantidad fija — no escala con variante de tamaño">🔒base</span>'
            : '';
        const unidLbl = `${esc(i.unidad_medida)}${rinde > 1 ? ' <span style="color:#9ca3af;font-size:10px">(tanda)</span>' : ''}`;
        // Cantidad editable inline (guarda al salir del campo / Enter); si no hay
        // permiso, solo muestra el valor.
        const cantCell = puedEdt
            ? `<input type="number" value="${+i.cantidad_requerida}" step="any" min="0.0001"
                      style="width:86px;padding:3px 6px;border:1px solid var(--g8);border-radius:6px;font-size:12px"
                      title="Editar cantidad"
                      onkeydown="if(event.key==='Enter')this.blur()"
                      onchange="guardarCantIng(${id},${i.insumo_id},${i.es_insumo_critico==1?1:0},${i.es_base==1?1:0},this)">
               <span style="font-size:11px;color:var(--g5)">${unidLbl}</span>`
            : `${(+i.cantidad_requerida).toFixed(NUM_FORMAT.decimales)} ${unidLbl}`;
        tblRows += `<tr>
            <td>${esc(i.nombre)}${crit}${base}</td>
            <td>${cantCell}</td>
            <td style="text-align:right">$${fmt(i.costo_actual)}</td>
            <td style="text-align:right"><strong>$${fmt(i.costo_linea)}</strong> <span style="color:#9ca3af;font-size:10px">/u</span></td>
            ${puedEdt ? `<td style="display:flex;gap:3px;align-items:center">
                <button class="btn-sm" style="${i.es_base ? 'background:#10b981;color:#fff' : ''}"
                        onclick="toggleBase(${id},${i.insumo_id},${i.es_base},${i.cantidad_requerida},${i.es_insumo_critico})"
                        title="Ingrediente base: cantidad fija, no escala con variante">🔒</button>
                <button class="btn-sm btn-red" onclick="delIng(${id},${i.insumo_id})">✕</button>
            </td>` : ''}
        </tr>`;
    });

    // Formulario para agregar ingrediente
    const optIns = INSUMOS.map(i => `<option value="${i.id}" data-costo="${i.costo}" data-u="${esc(i.unidad)}" data-equiv-cant="${i.equiv_cantidad}" data-equiv-unidad="${esc(i.equiv_unidad)}">${esc(i.nombre)} (${esc(i.unidad)})</option>`).join('');
    const addForm = puedEdt ? `
        <div class="add-row">
            <select id="si-${id}" onchange="onSelectInsumoReceta(${id})">${optIns}</select>
            <input type="number" id="ci-${id}" placeholder="Cantidad" step="any" min="0.001" style="width:110px" oninput="convertirCantidadReceta(${id})">
            <span id="cu-label-${id}" style="display:none;font-size:12px;color:var(--g5)">en:</span>
            <select id="cu-${id}" style="display:none" onchange="convertirCantidadReceta(${id})"></select>
            <span id="cu-hint-${id}" style="display:none;font-size:11px;color:var(--g5);align-self:center"></span>
            <label style="font-size:12px;display:flex;align-items:center;gap:4px;color:var(--g5)">
                <input type="checkbox" id="krit-${id}"> Crítico
            </label>
            <label style="font-size:12px;display:flex;align-items:center;gap:4px;color:var(--g5)" title="Base: cantidad fija, no escala con variante de tamaño (ej: pan, salsa)">
                <input type="checkbox" id="base-${id}"> 🔒Base
            </label>
            <button class="btn-sm btn-grn" onclick="addIng(${id})">+ Agregar</button>
        </div>` : '';

    // Copiar/combinar receta desde otro(s) producto(s) escalando por %
    const copyForm = puedEdt ? `
        <details class="copy-receta" style="margin-top:10px;border:1px solid var(--g8);border-radius:10px;padding:8px 10px;background:#fafafa">
            <summary style="cursor:pointer;font-size:12px;font-weight:700;color:#1d4ed8">📋 Copiar / combinar receta de otro producto</summary>
            <div style="margin-top:8px">
                <div id="orig-list-${id}"></div>
                <button class="btn-sm" onclick="addOrigen(${id})">+ Agregar origen</button>
                <div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap">
                    <button class="btn-sm btn-grn" onclick="aplicarCopia(${id},'reemplazar')">Reemplazar receta</button>
                    <button class="btn-sm" style="background:#dbeafe;color:#1d4ed8" onclick="aplicarCopia(${id},'sumar')">Sumar a la actual</button>
                </div>
                <p style="font-size:11px;color:var(--g5);margin-top:6px">Cada origen aporta sus ingredientes al % indicado (ej. 60%); los ingredientes repetidos se <strong>suman</strong>. Crítico/base se resuelven solos.</p>
            </div>
        </details>` : '';

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

    // Calculadora: opciones de ingredientes para el selector de referencia
    const optCalc = ings.map(i =>
        `<option value="${i.insumo_id}" data-cant="${i.cantidad_requerida}">${esc(i.nombre)} (${esc(i.unidad_medida)})</option>`
    ).join('');

    // ── Secciones de combo y variantes ──────────────────────────────────────
    const comboSection     = puedEdt ? buildComboSection(id, precio, combo) : '';
    const variantesSection = puedEdt ? buildVariantesSection(id, variantes || []) : '';

    document.getElementById('rc-' + id).innerHTML = `
        <div class="rcp-grid">
            <div>
                <p class="rcp-title">Ingredientes de la receta</p>
                ${notaRinde}
                <table class="ing-table">
                    <thead><tr><th>Ingrediente</th><th>Cantidad</th><th style="text-align:right">$/u</th><th style="text-align:right">Subtotal /u</th>${puedEdt?'<th></th>':''}</tr></thead>
                    <tbody>${tblRows || '<tr><td colspan="5" style="text-align:center;color:var(--g5);padding:16px">Sin ingredientes configurados</td></tr>'}</tbody>
                </table>
                ${addForm}
                ${copyForm}
            </div>
            <div>
                <p class="rcp-title">Desglose de costo</p>
                ${costBreak}
                ${precioForm}
                <!-- ── Calculadora bidireccional ── -->
                <div style="margin-top:14px;background:#f8faff;border:1px solid #dbeafe;border-radius:10px;padding:12px">
                    <p style="font-size:11px;font-weight:700;color:#1d4ed8;text-transform:uppercase;letter-spacing:.4px;margin-bottom:10px">Calculadora de producción</p>
                    <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-bottom:8px">
                        <label style="font-size:12px;color:var(--g5)">Quiero hacer</label>
                        <input type="number" id="calc-u-${id}" value="1" min="1" step="1"
                               style="width:70px;padding:4px 6px;border:1px solid var(--g8);border-radius:6px;font-size:13px"
                               oninput="calcIngredientes(${id},${rinde})">
                        <label style="font-size:12px;color:var(--g5)">unidades. Necesito:</label>
                    </div>
                    <div id="calc-ings-${id}" style="font-size:12px;color:var(--g2)"></div>
                    <div style="margin-top:10px;padding-top:10px;border-top:1px solid #dbeafe;display:flex;flex-wrap:wrap;align-items:center;gap:4px">
                        <label style="font-size:12px;color:var(--g5)">Si tengo</label>
                        <input type="number" id="calc-qty-${id}" placeholder="Cantidad" min="0" step="any"
                               style="width:80px;padding:4px 6px;border:1px solid var(--g8);border-radius:6px;font-size:13px"
                               oninput="calcPorInsumo(${id},${rinde})">
                        <select id="calc-ing-${id}"
                                style="padding:4px 6px;border:1px solid var(--g8);border-radius:6px;font-size:12px;flex:1;min-width:120px"
                                onchange="calcPorInsumo(${id},${rinde})">${optCalc}</select>
                        <span id="calc-max-${id}" style="font-size:12px;color:#059669;font-weight:700"></span>
                    </div>
                </div>
            </div>
        </div>
        ${variantesSection}
        ${comboSection}`;

    // Inicializar calculadora con 1 unidad
    calcIngredientes(id, rinde);

    // Inicializar selector "Ingresar en" para el insumo preseleccionado
    if (puedEdt) { onSelectInsumoReceta(id); addOrigen(id); }
}

// ── Editar cantidad de un ingrediente directamente (inline) ─────────────────
async function guardarCantIng(prodId, insumoId, esCritico, esBase, inputEl) {
    const cant = parseFloat(inputEl.value);
    if (!cant || cant <= 0) { toast('Cantidad inválida', 'err'); reloadReceta(prodId); return; }
    const fd = new FormData();
    fd.append('csrf_token', csrf()); fd.append('accion','guardar');
    fd.append('producto_id', prodId); fd.append('insumo_id', insumoId);
    fd.append('cantidad', cant); fd.append('es_critico', esCritico);
    fd.append('es_base', esBase);
    const r = await fetch('api/guardar_receta.php', {method:'POST',body:fd});
    const d = await r.json();
    if (d.success) { delete cache[prodId]; toast('Cantidad actualizada', 'ok'); refrescarReceta(prodId); }
    else { toast(d.error || 'Error', 'err'); reloadReceta(prodId); }
}

// ── Copiar / combinar receta desde otro(s) producto(s) ──────────────────────
function origenRowHtml(prodId) {
    const opts = PRODUCTOS_RECETA
        .filter(p => p.id !== prodId)
        .map(p => {
            const t = p.tamano && p.tamano !== 'unico' ? ' ' + p.tamano : '';
            const n2 = p.nombre2 ? ' — ' + p.nombre2 : '';
            return `<option value="${p.id}">${esc(p.nombre + t + n2)}</option>`;
        }).join('');
    return `<div class="orig-row" style="display:flex;gap:6px;align-items:center;margin-bottom:6px;flex-wrap:wrap">
        <select class="orig-prod" style="flex:1;min-width:150px;padding:5px 7px;border:1px solid var(--g8);border-radius:6px;font-size:12px">${opts}</select>
        <input type="number" class="orig-pct" value="100" min="1" max="1000" step="5"
               style="width:74px;padding:5px 7px;border:1px solid var(--g8);border-radius:6px;font-size:12px" title="Porcentaje de los ingredientes a tomar">
        <span style="font-size:12px;color:var(--g5)">%</span>
        <button class="btn-sm btn-red" onclick="this.closest('.orig-row').remove()" title="Quitar origen">✕</button>
    </div>`;
}
function addOrigen(prodId) {
    const list = document.getElementById('orig-list-' + prodId);
    if (list) list.insertAdjacentHTML('beforeend', origenRowHtml(prodId));
}
async function aplicarCopia(prodId, modo) {
    const list = document.getElementById('orig-list-' + prodId);
    const fuentes = [];
    list.querySelectorAll('.orig-row').forEach(row => {
        const id  = parseInt(row.querySelector('.orig-prod').value);
        const pct = parseFloat(row.querySelector('.orig-pct').value);
        if (id && pct > 0) fuentes.push({ id, factor: pct });
    });
    if (!fuentes.length) { toast('Agrega al menos un producto de origen con %', 'err'); return; }
    if (modo === 'reemplazar' && !confirm('Esto REEMPLAZARÁ la receta actual por los orígenes seleccionados. ¿Continuar?')) return;

    const fd = new FormData();
    fd.append('csrf_token', csrf());
    fd.append('producto_id', prodId);
    fd.append('modo', modo);
    fd.append('fuentes', JSON.stringify(fuentes));
    try {
        const r = await fetch('api/copiar_receta.php', {method:'POST', body:fd});
        const txt = await r.text();
        let d; try { d = JSON.parse(txt); } catch (e) { toast('Respuesta inválida del servidor: ' + txt.slice(0,180), 'err'); return; }
        if (d.success) {
            delete cache[prodId];
            toast((modo === 'sumar' ? 'Sumado' : 'Receta construida') + ': ' + d.ingredientes + ' ingrediente(s)', 'ok');
            refrescarReceta(prodId);
        } else toast(d.error || 'Error', 'err');
    } catch (e) { toast('Error de red al copiar receta', 'err'); }
}

// ══ CONSTRUCTOR DE RECETAS (tab) ════════════════════════════════════════════
let crInit = false;
function mostrarTabProd(name) {
    const cat = document.getElementById('tab-catalogo');
    const con = document.getElementById('tab-constructor');
    const bc  = document.getElementById('tabb-catalogo');
    const bk  = document.getElementById('tabb-constructor');
    if (name === 'constructor') {
        cat.style.display = 'none'; con.style.display = '';
        bc.classList.remove('on'); bk.classList.add('on');
        if (!crInit) { crInitConstructor(); crInit = true; }
    } else {
        cat.style.display = ''; con.style.display = 'none';
        bk.classList.remove('on'); bc.classList.add('on');
    }
}
function crProdOptions(excludeId) {
    return PRODUCTOS_RECETA.filter(p => p.id !== excludeId).map(p => {
        const t  = p.tamano && p.tamano !== 'unico' ? ' ' + p.tamano : '';
        const n2 = p.nombre2 ? ' — ' + p.nombre2 : '';
        return `<option value="${p.id}">${esc(p.nombre + t + n2)}</option>`;
    }).join('');
}
function crInsumoOptions(selId) {
    return INSUMOS.map(i => `<option value="${i.id}"${i.id==selId?' selected':''}>${esc(i.nombre)} (${esc(i.unidad)})</option>`).join('');
}
function crIngRow(insumoId, cant, crit, base) {
    insumoId = insumoId || (INSUMOS[0] ? INSUMOS[0].id : 0);
    return `<div class="cr-row cr-ing-row">
        <select class="cr-inp cr-ing-sel" style="flex:1;min-width:150px">${crInsumoOptions(insumoId)}</select>
        <input type="number" class="cr-inp cr-ing-cant" value="${cant!=null?cant:''}" step="any" min="0.0001" placeholder="Cantidad" style="width:110px">
        <label style="font-size:11px;color:var(--g5);display:flex;align-items:center;gap:3px"><input type="checkbox" class="cr-ing-crit"${crit?' checked':''}> Crít.</label>
        <label style="font-size:11px;color:var(--g5);display:flex;align-items:center;gap:3px" title="Base: cantidad fija, no escala con variante"><input type="checkbox" class="cr-ing-base"${base?' checked':''}> 🔒</label>
        <button class="btn-sm btn-red" onclick="this.closest('.cr-ing-row').remove()">✕</button>
    </div>`;
}
function crAddIng(insumoId, cant, crit, base) {
    document.getElementById('cr-ings').insertAdjacentHTML('beforeend', crIngRow(insumoId, cant, crit, base));
}
function crInitConstructor() {
    document.getElementById('cr-prod').innerHTML = crProdOptions(0);
    crCargarProducto();
    if (!document.querySelectorAll('#cr-origenes .cr-og-row').length) crAddOrigen();
}
async function crCargarProducto() {
    const id   = parseInt(document.getElementById('cr-prod').value);
    const prod = PRODUCTOS_RECETA.find(p => p.id === id);
    document.getElementById('cr-rinde').value = prod ? prod.rinde : 1;
    const cont = document.getElementById('cr-ings');
    cont.innerHTML = '';
    try {
        const r = await fetch('api/ingredientes.php?producto_id=' + id);
        const ings = await r.json();
        if (Array.isArray(ings) && ings.length)
            ings.forEach(i => crAddIng(i.insumo_id, +i.cantidad_requerida, i.es_insumo_critico==1?1:0, i.es_base==1?1:0));
        else crAddIng();
    } catch (e) { crAddIng(); }
    // Excluir el producto actual de los selectores de origen
    document.querySelectorAll('#cr-origenes .cr-og-sel').forEach(s => {
        const cur = s.value; s.innerHTML = crProdOptions(id); if (cur) s.value = cur;
    });
}
async function crGuardar() {
    const prodId = parseInt(document.getElementById('cr-prod').value);
    const rinde  = parseInt(document.getElementById('cr-rinde').value) || 1;
    const ings = [];
    document.querySelectorAll('#cr-ings .cr-ing-row').forEach(row => {
        const insumo_id = parseInt(row.querySelector('.cr-ing-sel').value);
        const cantidad  = parseFloat(row.querySelector('.cr-ing-cant').value);
        if (insumo_id && cantidad > 0) ings.push({
            insumo_id, cantidad,
            es_critico: row.querySelector('.cr-ing-crit').checked ? 1 : 0,
            es_base:    row.querySelector('.cr-ing-base').checked ? 1 : 0,
        });
    });
    if (!ings.length) { toast('Agrega al menos un ingrediente con cantidad', 'err'); return; }
    const fd = new FormData();
    fd.append('csrf_token', csrf());
    fd.append('producto_id', prodId);
    fd.append('rinde', rinde);
    fd.append('ingredientes', JSON.stringify(ings));
    const r = await fetch('api/guardar_receta_completa.php', {method:'POST', body:fd});
    const d = await r.json();
    if (d.success) {
        delete cache[prodId];
        const prod = PRODUCTOS_RECETA.find(p => p.id === prodId); if (prod) prod.rinde = rinde;
        toast('Receta guardada: ' + d.ingredientes + ' ingrediente(s)', 'ok');
    } else toast(d.error || 'Error', 'err');
}
function crOrigenRow() {
    const curId = parseInt(document.getElementById('cr-prod').value) || 0;
    return `<div class="cr-row cr-og-row">
        <select class="cr-inp cr-og-sel" style="flex:1;min-width:150px">${crProdOptions(curId)}</select>
        <input type="number" class="cr-inp cr-og-pct" value="100" min="1" max="1000" step="5" style="width:80px" title="Porcentaje a tomar de ese producto">
        <span style="font-size:12px;color:var(--g5)">%</span>
        <button class="btn-sm btn-red" onclick="this.closest('.cr-og-row').remove()">✕</button>
    </div>`;
}
function crAddOrigen() {
    document.getElementById('cr-origenes').insertAdjacentHTML('beforeend', crOrigenRow());
}
// Trae los ingredientes de los productos origen (escalados por %) y los SUMA a la
// lista de arriba (cr-ings), unificando insumos repetidos. Solo del lado del cliente:
// nada se guarda hasta pulsar "Guardar receta del producto".
async function crTraer(modo) {
    modo = modo === 'reemplazar' ? 'reemplazar' : 'sumar';
    const origenes = [];
    document.querySelectorAll('#cr-origenes .cr-og-row').forEach(row => {
        const id  = parseInt(row.querySelector('.cr-og-sel').value);
        const pct = parseFloat(row.querySelector('.cr-og-pct').value);
        if (id && pct > 0) origenes.push({ id, pct });
    });
    if (!origenes.length) { toast('Agrega al menos un producto origen con %', 'err'); return; }

    // Mapa inicial: en "sumar" parte de lo que ya hay arriba; en "reemplazar" parte vacío.
    const map = {};
    if (modo === 'sumar') {
        document.querySelectorAll('#cr-ings .cr-ing-row').forEach(row => {
            const iid = parseInt(row.querySelector('.cr-ing-sel').value);
            const c   = parseFloat(row.querySelector('.cr-ing-cant').value) || 0;
            if (iid && c > 0) map[iid] = { cant: c,
                crit: row.querySelector('.cr-ing-crit').checked,
                base: row.querySelector('.cr-ing-base').checked };
        });
    } else {
        const tieneAlgo = [...document.querySelectorAll('#cr-ings .cr-ing-row')]
            .some(row => (parseFloat(row.querySelector('.cr-ing-cant').value) || 0) > 0);
        if (tieneAlgo && !confirm('Esto REEMPLAZARÁ la lista actual por los productos origen combinados. ¿Continuar?')) return;
    }

    // Sumar cada origen escalado por su %
    let traidos = 0;
    for (const o of origenes) {
        try {
            const r = await fetch('api/ingredientes.php?producto_id=' + o.id);
            const ings = await r.json();
            const f = o.pct / 100;
            (Array.isArray(ings) ? ings : []).forEach(i => {
                const iid = parseInt(i.insumo_id);
                const c   = (+i.cantidad_requerida) * f;
                if (!iid || !(c > 0)) return;
                traidos++;
                if (map[iid]) {
                    map[iid].cant += c;
                    map[iid].crit = map[iid].crit || i.es_insumo_critico == 1;
                    map[iid].base = map[iid].base || i.es_base == 1;
                } else {
                    map[iid] = { cant: c, crit: i.es_insumo_critico == 1, base: i.es_base == 1 };
                }
            });
        } catch (e) { toast('Error trayendo un origen', 'err'); }
    }

    // Re-pintar la lista con el resultado combinado
    const cont = document.getElementById('cr-ings');
    cont.innerHTML = '';
    Object.keys(map).forEach(iid => {
        const v = map[iid];
        crAddIng(parseInt(iid), Math.round(v.cant * 10000) / 10000, v.crit ? 1 : 0, v.base ? 1 : 0);
    });
    if (!Object.keys(map).length) crAddIng();
    toast((modo === 'reemplazar' ? 'Lista reemplazada' : 'Sumado a la lista') + ': ' + traidos + ' ingrediente(s) traído(s). Revisa y guarda.', 'ok');
}

// ── Conversión receta ↔ equivalencia física (mig 030) ──────────────────────
// Si el insumo tiene equiv_cantidad/equiv_unidad (ej: "1 lata = 160 g"), permite
// ingresar la cantidad en esa unidad y convertirla a unidad_medida del insumo
// (la única que viaja a guardar_receta.php / cantidad_requerida).
function onSelectInsumoReceta(prodId) {
    const sel    = document.getElementById('si-' + prodId);
    const opt    = sel.options[sel.selectedIndex];
    const cuSel  = document.getElementById('cu-' + prodId);
    const cuLbl  = document.getElementById('cu-label-' + prodId);
    const equivCant   = parseFloat(opt.dataset.equivCant) || 0;
    const equivUnidad = opt.dataset.equivUnidad || '';
    if (equivCant > 0 && equivUnidad) {
        cuSel.innerHTML = `<option value="base">${esc(opt.dataset.u)}</option><option value="equiv">${esc(equivUnidad)}</option>`;
        cuSel.style.display = '';
        cuLbl.style.display = '';
    } else {
        cuSel.innerHTML = '';
        cuSel.style.display = 'none';
        cuLbl.style.display = 'none';
    }
    convertirCantidadReceta(prodId);
}

function convertirCantidadReceta(prodId) {
    const hint  = document.getElementById('cu-hint-' + prodId);
    const cuSel = document.getElementById('cu-' + prodId);
    if (!cuSel || cuSel.style.display === 'none' || cuSel.value !== 'equiv') {
        hint.style.display = 'none';
        return;
    }
    const sel       = document.getElementById('si-' + prodId);
    const opt       = sel.options[sel.selectedIndex];
    const equivCant = parseFloat(opt.dataset.equivCant) || 0;
    const cantidad  = parseFloat(document.getElementById('ci-' + prodId).value) || 0;
    if (equivCant <= 0 || cantidad <= 0) { hint.style.display = 'none'; return; }
    hint.textContent = `= ${formatDecimal(cantidad / equivCant)} ${opt.dataset.u}`;
    hint.style.display = '';
}

// ── CRUD de ingredientes ──────────────────────────────────────────────────────
async function addIng(prodId) {
    const insumoId = document.getElementById('si-' + prodId).value;
    let   cantidad = document.getElementById('ci-' + prodId).value;
    const critico  = document.getElementById('krit-' + prodId).checked ? 1 : 0;
    const esBase   = document.getElementById('base-' + prodId).checked ? 1 : 0;
    if (!cantidad || parseFloat(cantidad) <= 0) { toast('Cantidad inválida', 'err'); return; }
    // Si el usuario ingresó la cantidad en la unidad de equivalencia (ej: "unidades"
    // de huevo), convertir a la unidad_medida del insumo antes de guardar.
    const cuSel = document.getElementById('cu-' + prodId);
    if (cuSel && cuSel.style.display !== 'none' && cuSel.value === 'equiv') {
        const sel       = document.getElementById('si-' + prodId);
        const opt       = sel.options[sel.selectedIndex];
        const equivCant = parseFloat(opt.dataset.equivCant) || 0;
        if (equivCant > 0) cantidad = (parseFloat(cantidad) / equivCant).toFixed(6);
    }
    const fd = new FormData();
    fd.append('csrf_token', csrf()); fd.append('accion','guardar');
    fd.append('producto_id', prodId); fd.append('insumo_id', insumoId);
    fd.append('cantidad', cantidad); fd.append('es_critico', critico);
    fd.append('es_base', esBase);
    const r = await fetch('api/guardar_receta.php', {method:'POST',body:fd});
    const d = await r.json();
    if (d.success) { delete cache[prodId]; toast('Guardado', 'ok'); refrescarReceta(prodId); }
    else toast(d.error || 'Error', 'err');
}

async function toggleBase(prodId, insumoId, esBase, cantidad, esCritico) {
    const fd = new FormData();
    fd.append('csrf_token', csrf()); fd.append('accion','guardar');
    fd.append('producto_id', prodId); fd.append('insumo_id', insumoId);
    fd.append('cantidad', cantidad); fd.append('es_critico', esCritico);
    fd.append('es_base', esBase ? 0 : 1);
    const r = await fetch('api/guardar_receta.php', {method:'POST',body:fd});
    const d = await r.json();
    if (d.success) {
        delete cache[prodId];
        toast(esBase ? 'Ingrediente ya escala con variante' : 'Ingrediente marcado como base (fijo)', 'ok');
        refrescarReceta(prodId);
    } else toast(d.error || 'Error', 'err');
}

async function delIng(prodId, insumoId) {
    if (!confirm('¿Quitar este ingrediente?')) return;
    const fd = new FormData();
    fd.append('csrf_token', csrf()); fd.append('accion','eliminar');
    fd.append('producto_id', prodId); fd.append('insumo_id', insumoId);
    const r = await fetch('api/guardar_receta.php', {method:'POST',body:fd});
    const d = await r.json();
    if (d.success) { delete cache[prodId]; toast('Eliminado', 'ok'); refrescarReceta(prodId); }
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

// ── Combo config: renderizar sección ─────────────────────────────────────────

/**
 * Construye el HTML de la sección "Opción Combo" dentro del expandable.
 * @param {number} prodId     ID del producto
 * @param {number} precioProd Precio de venta del sándwich solo
 * @param {object|null} combo Objeto de combo_crud.php GET o null si no hay config
 */
function buildComboSection(prodId, precioProd, combo) {
    const optIns = INSUMOS.map(i =>
        `<option value="${i.id}" data-u="${esc(i.unidad)}">${esc(i.nombre)} (${esc(i.unidad)})</option>`
    ).join('');

    // Insumos ya configurados en el combo (si existe config)
    let insRows = '';
    if (combo && combo.insumos && combo.insumos.length > 0) {
        insRows = combo.insumos.map(ci => `
            <div class="combo-ins-row" id="combo-ir-${prodId}-${ci.insumo_id}">
                <span style="flex:1">${esc(ci.insumo_nombre)}</span>
                <span style="color:var(--g5);font-size:11px;margin-right:4px">
                    <input type="number" value="${ci.cantidad}" min="0.001" step="any"
                           id="combo-qty-${prodId}-${ci.insumo_id}"
                           style="width:70px;padding:3px 6px;border:1px solid var(--g8);border-radius:6px;font-size:12px">
                    ${esc(ci.unidad_medida)}
                </span>
                <button class="btn-sm btn-red" onclick="quitarComboIns(${prodId},${ci.insumo_id})">✕</button>
            </div>`).join('');
    }

    // Precio combo calculado (precio solo + adicional)
    const adicional = combo ? parseFloat(combo.precio_adicional) : 0;
    const precioCombo = precioProd + adicional;

    const badge = combo
        ? `<span class="combo-badge">✔ Configurado — $${fmt(adicional)} adicional</span>`
        : `<span style="font-size:10px;color:var(--g5);margin-left:8px">Sin combo</span>`;

    return `
    <div class="combo-sec" id="combo-sec-${prodId}">
        <p class="combo-sec-title">Opción Combo ${badge}</p>

        <!-- Campos: nombre del combo + precio adicional -->
        <div class="combo-form-row">
            <div>
                <label>Nombre del combo</label>
                <input type="text" id="combo-nom-${prodId}"
                       value="${combo ? esc(combo.nombre) : 'Combo'}"
                       placeholder="Ej: Combo XL" style="width:160px">
            </div>
            <div>
                <label>Precio adicional ($)</label>
                <input type="number" id="combo-add-precio-${prodId}"
                       value="${adicional}" min="0" step="500"
                       style="width:120px"
                       oninput="actualizarPrecioCombo(${prodId},${precioProd})">
            </div>
            <div style="padding-bottom:2px">
                <label>Precio total</label>
                <strong id="combo-total-${prodId}" style="font-size:15px;color:#059669">
                    $${fmt(precioCombo)}
                </strong>
            </div>
        </div>

        <!-- Lista de insumos ya configurados -->
        <div class="combo-ins-list" id="combo-ins-list-${prodId}">
            ${insRows || '<p style="font-size:12px;color:var(--g5);margin-bottom:6px">Sin insumos configurados aún.</p>'}
        </div>

        <!-- Formulario para agregar insumo al combo -->
        <div class="combo-form-row" style="margin-top:6px">
            <div>
                <label>Agregar insumo al combo</label>
                <select id="combo-add-ins-${prodId}">${optIns}</select>
            </div>
            <div>
                <label>Cantidad</label>
                <input type="number" id="combo-add-qty-${prodId}"
                       placeholder="1" min="0.001" step="any"
                       style="width:80px" value="1">
            </div>
            <button class="btn-sm btn-grn" style="margin-top:16px"
                    onclick="agregarComboIns(${prodId})">
                + Insumo
            </button>
        </div>

        <!-- Botones guardar / quitar -->
        <div style="display:flex;gap:8px;margin-top:8px">
            <button class="btn-combo-save" onclick="guardarCombo(${prodId})">
                &#10003; Guardar combo
            </button>
            ${combo ? `<button class="btn-combo-del" onclick="eliminarCombo(${prodId})">
                Quitar combo
            </button>` : ''}
        </div>
    </div>`;
}

// ── Variantes de tamaño: renderizar sección ───────────────────────────────────

/**
 * Construye el HTML de la sección "Variantes de Tamaño" dentro del expandable.
 * @param {number}   prodId    ID del producto
 * @param {Array}    variantes Array de objetos {id, etiqueta, precio_venta, factor_receta, activo}
 */
function buildVariantesSection(prodId, variantes) {
    const activas    = variantes.filter(v => v.activo == 1);
    const inactivas  = variantes.filter(v => v.activo != 1);
    const countBadge = activas.length > 0
        ? `<span style="background:#dbeafe;color:#1e40af;font-size:10px;font-weight:600;padding:1px 7px;border-radius:20px;margin-left:6px">${activas.length} activa${activas.length !== 1 ? 's' : ''}</span>`
        : `<span style="font-size:10px;color:var(--g5);margin-left:8px">Sin variantes</span>`;

    const rows = variantes.map(v => {
        const esActiva = v.activo == 1;
        return `<tr id="var-row-${prodId}-${v.id}" style="${!esActiva ? 'opacity:.5' : ''}">
            <td id="var-etiq-${prodId}-${v.id}" contenteditable="${esActiva}" spellcheck="false"
                style="font-weight:600;cursor:${esActiva?'text':'default'}">${esc(v.etiqueta)}</td>
            <td>
                <input type="number" id="var-precio-${prodId}-${v.id}" value="${v.precio_venta}"
                       min="1" step="500" ${!esActiva ? 'disabled' : ''}
                       style="width:90px;padding:3px 6px;border:1px solid var(--g8);border-radius:6px;font-size:12px">
            </td>
            <td>
                <input type="number" id="var-factor-${prodId}-${v.id}" value="${parseFloat(v.factor_receta).toFixed(2)}"
                       min="0.001" max="10" step="0.1" ${!esActiva ? 'disabled' : ''}
                       style="width:70px;padding:3px 6px;border:1px solid var(--g8);border-radius:6px;font-size:12px">
                <span style="font-size:10px;color:var(--g5)">× receta</span>
            </td>
            <td style="display:flex;gap:4px;align-items:center">
                ${esActiva ? `
                <button class="btn-sm btn-grn" onclick="guardarVariante(${prodId},${v.id})" title="Guardar cambios">&#10003;</button>
                <button class="btn-sm btn-red" onclick="eliminarVariante(${prodId},${v.id})" title="Desactivar">✕</button>
                ` : `
                <button class="btn-sm" style="background:#fef3c7;color:#92400e" onclick="reactivarVariante(${prodId},${v.id})" title="Reactivar">↩</button>
                `}
            </td>
        </tr>`;
    }).join('');

    return `
    <div class="combo-sec" id="var-sec-${prodId}">
        <p class="combo-sec-title">Variantes de Tamaño ${countBadge}</p>
        <p style="font-size:12px;color:var(--g5);margin-bottom:10px">
            En el POS, si un producto tiene variantes activas, el cajero debe elegir una antes de añadir al carrito.
            El precio de la variante reemplaza al precio base. El factor escala las cantidades de la receta
            (ej: XL con factor 1.5 descuenta 1.5× los insumos en modo demanda).
        </p>

        ${variantes.length > 0 ? `
        <div style="overflow-x:auto;margin-bottom:10px">
            <table style="width:100%;border-collapse:collapse;font-size:12px">
                <thead>
                    <tr style="background:var(--g9)">
                        <th style="padding:6px 8px;text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.4px;color:var(--g5)">Etiqueta</th>
                        <th style="padding:6px 8px;text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.4px;color:var(--g5)">Precio</th>
                        <th style="padding:6px 8px;text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.4px;color:var(--g5)">Factor receta</th>
                        <th style="padding:6px 8px"></th>
                    </tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>
        </div>` : ''}

        <!-- Formulario para nueva variante -->
        <div class="combo-form-row" style="margin-top:4px">
            <div>
                <label>Nueva variante</label>
                <input type="text" id="var-new-etiq-${prodId}" placeholder="XL, Regular, Familiar…"
                       maxlength="80" style="width:130px">
            </div>
            <div>
                <label>Precio ($)</label>
                <input type="number" id="var-new-precio-${prodId}" placeholder="18000" min="1" step="500" style="width:90px">
            </div>
            <div>
                <label>Factor receta</label>
                <input type="number" id="var-new-factor-${prodId}" value="1.000" min="0.001" max="10" step="0.1" style="width:70px">
            </div>
            <button class="btn-sm btn-grn" style="margin-top:16px" onclick="guardarVariante(${prodId},0)">
                + Agregar
            </button>
        </div>
        <p style="font-size:11px;color:var(--g5);margin-top:4px">Factor 1.0 = misma receta. 1.5 = 50% más insumos (tamaño XL). 0.7 = 30% menos (tamaño Mini).</p>
    </div>`;
}

/** Crea o actualiza una variante. id=0 → crear nueva */
async function guardarVariante(prodId, varId) {
    let etiq, precio, factor;
    if (varId === 0) {
        etiq   = document.getElementById('var-new-etiq-' + prodId)?.value.trim();
        precio = parseFloat(document.getElementById('var-new-precio-' + prodId)?.value);
        factor = parseFloat(document.getElementById('var-new-factor-' + prodId)?.value);
    } else {
        etiq   = document.getElementById('var-etiq-' + prodId + '-' + varId)?.innerText.trim();
        precio = parseFloat(document.getElementById('var-precio-' + prodId + '-' + varId)?.value);
        factor = parseFloat(document.getElementById('var-factor-' + prodId + '-' + varId)?.value);
    }

    if (!etiq)              { toast('La etiqueta no puede estar vacía', 'err'); return; }
    if (!precio || precio <= 0) { toast('El precio debe ser mayor que 0', 'err'); return; }
    if (!factor || factor <= 0) { toast('Factor inválido', 'err'); return; }

    const fd = new FormData();
    fd.append('csrf_token',    csrf());
    fd.append('accion',        'guardar');
    fd.append('producto_id',   prodId);
    fd.append('id',            varId);
    fd.append('etiqueta',      etiq);
    fd.append('precio_venta',  precio);
    fd.append('factor_receta', factor);

    const r = await fetch('api/variantes_crud.php', { method: 'POST', body: fd });
    const d = await r.json();
    if (d.success) {
        toast(d.mensaje || 'Guardado', 'ok');
        delete cache[prodId];
        reloadVariantes(prodId);
    } else {
        toast(d.error || 'Error', 'err');
    }
}

/** Desactiva una variante (soft-delete) */
async function eliminarVariante(prodId, varId) {
    if (!confirm('¿Desactivar esta variante?\nLas ventas históricas con esta variante no se verán afectadas.')) return;
    const fd = new FormData();
    fd.append('csrf_token',   csrf());
    fd.append('accion',       'eliminar');
    fd.append('producto_id',  prodId);
    fd.append('id',           varId);
    const r = await fetch('api/variantes_crud.php', { method: 'POST', body: fd });
    const d = await r.json();
    if (d.success) { toast('Variante desactivada', 'ok'); delete cache[prodId]; reloadVariantes(prodId); }
    else toast(d.error || 'Error', 'err');
}

/** Reactiva una variante desactivada */
async function reactivarVariante(prodId, varId) {
    const fd = new FormData();
    fd.append('csrf_token',   csrf());
    fd.append('accion',       'reactivar');
    fd.append('producto_id',  prodId);
    fd.append('id',           varId);
    const r = await fetch('api/variantes_crud.php', { method: 'POST', body: fd });
    const d = await r.json();
    if (d.success) { toast('Variante reactivada', 'ok'); delete cache[prodId]; reloadVariantes(prodId); }
    else toast(d.error || 'Error', 'err');
}

/** Recarga solo la sección de variantes sin colapsar la fila */
async function reloadVariantes(prodId) {
    try {
        const r  = await fetch('api/variantes_crud.php?producto_id=' + prodId);
        const d  = await r.json();
        const sec = document.getElementById('var-sec-' + prodId);
        if (sec) {
            const tmp = document.createElement('div');
            tmp.innerHTML = buildVariantesSection(prodId, d.variantes || []);
            sec.replaceWith(tmp.firstElementChild);
        }
    } catch (e) { /* silencioso */ }
}

/** Actualiza el precio total mostrado al cambiar el precio adicional */
function actualizarPrecioCombo(prodId, precioProd) {
    const adicional = parseFloat(document.getElementById('combo-add-precio-' + prodId)?.value) || 0;
    const el = document.getElementById('combo-total-' + prodId);
    if (el) el.textContent = '$' + fmt(precioProd + adicional);
}

/** Agrega un insumo a la lista visual del combo (sin guardar aún) */
function agregarComboIns(prodId) {
    const sel = document.getElementById('combo-add-ins-' + prodId);
    const qtyEl = document.getElementById('combo-add-qty-' + prodId);
    const insumoId = parseInt(sel.value);
    const cantidad = parseFloat(qtyEl.value);
    const unidad   = sel.options[sel.selectedIndex]?.dataset.u || '';
    const nombre   = sel.options[sel.selectedIndex]?.text.split(' (')[0] || '';

    if (!insumoId || cantidad <= 0) { toast('Cantidad inválida', 'err'); return; }

    // Si ya existe ese insumo en la lista, solo actualizamos la cantidad
    const existingQty = document.getElementById('combo-qty-' + prodId + '-' + insumoId);
    if (existingQty) {
        existingQty.value = cantidad;
        toast('Cantidad actualizada', 'ok');
        return;
    }

    const list = document.getElementById('combo-ins-list-' + prodId);
    // Remover el placeholder "Sin insumos" si estaba
    const placeholder = list.querySelector('p');
    if (placeholder) placeholder.remove();

    const div = document.createElement('div');
    div.className = 'combo-ins-row';
    div.id = 'combo-ir-' + prodId + '-' + insumoId;
    div.innerHTML = `
        <span style="flex:1">${esc(nombre)}</span>
        <span style="color:var(--g5);font-size:11px;margin-right:4px">
            <input type="number" value="${cantidad}" min="0.001" step="any"
                   id="combo-qty-${prodId}-${insumoId}"
                   style="width:70px;padding:3px 6px;border:1px solid var(--g8);border-radius:6px;font-size:12px">
            ${esc(unidad)}
        </span>
        <button class="btn-sm btn-red" onclick="quitarComboIns(${prodId},${insumoId})">✕</button>`;
    list.appendChild(div);
    qtyEl.value = 1;
}

/** Elimina visualmente un insumo de la lista del combo */
function quitarComboIns(prodId, insumoId) {
    document.getElementById('combo-ir-' + prodId + '-' + insumoId)?.remove();
}

/** Recolecta los insumos del combo desde el DOM y envía al backend */
async function guardarCombo(prodId) {
    const nombre           = document.getElementById('combo-nom-' + prodId)?.value.trim() || 'Combo';
    const precio_adicional = parseFloat(document.getElementById('combo-add-precio-' + prodId)?.value) || 0;

    // Serializar todos los insumos visibles en la lista del combo
    const insumos = [];
    document.querySelectorAll(`[id^="combo-ir-${prodId}-"]`).forEach(row => {
        const parts    = row.id.split('-');
        const insumoId = parseInt(parts[parts.length - 1]);
        const qtyInput = document.getElementById('combo-qty-' + prodId + '-' + insumoId);
        const cantidad = parseFloat(qtyInput?.value) || 0;
        if (insumoId && cantidad > 0) insumos.push({ insumo_id: insumoId, cantidad });
    });

    const fd = new FormData();
    fd.append('csrf_token',      csrf());
    fd.append('accion',          'guardar');
    fd.append('producto_id',     prodId);
    fd.append('nombre',          nombre);
    fd.append('precio_adicional', precio_adicional);
    fd.append('insumos',         JSON.stringify(insumos));

    const r = await fetch('api/combo_crud.php', { method: 'POST', body: fd });
    const d = await r.json();
    if (d.success) {
        toast('Combo guardado', 'ok');
        // Invalidar caché para que al re-expandir cargue datos frescos
        delete cache[prodId];
        // Actualizar el badge sin recargar la fila completa
        const badge = document.querySelector(`#combo-sec-${prodId} .combo-sec-title`);
        if (badge) {
            let sp = badge.querySelector('.combo-badge');
            if (!sp) { sp = document.createElement('span'); sp.className = 'combo-badge'; badge.appendChild(sp); }
            sp.textContent = `✔ Configurado — $${fmt(precio_adicional)} adicional`;
        }
    } else {
        toast(d.error || 'Error al guardar combo', 'err');
    }
}

/** Desactiva el combo del producto (soft-delete) */
async function eliminarCombo(prodId) {
    if (!confirm('¿Quitar la configuración de combo de este producto?\n' +
                 'Las ventas anteriores registradas como combo no se verán afectadas.')) return;

    const fd = new FormData();
    fd.append('csrf_token',  csrf());
    fd.append('accion',      'eliminar');
    fd.append('producto_id', prodId);

    const r = await fetch('api/combo_crud.php', { method: 'POST', body: fd });
    const d = await r.json();
    if (d.success) {
        toast('Combo eliminado', 'ok');
        delete cache[prodId];
        // Colapsar y limpiar la fila expandida
        document.getElementById('rr-' + prodId)?.classList.remove('open');
        document.getElementById('rc-' + prodId).innerHTML = '<em style="color:var(--g5);font-size:13px">Cargando…</em>';
    } else {
        toast(d.error || 'Error', 'err');
    }
}

async function reloadReceta(id) {
    document.getElementById('rr-' + id).classList.remove('open');
    const btn = document.getElementById('rr-' + id).previousElementSibling?.querySelector('.exp-btn');
    if (btn) btn.textContent = '▾';
}

// ── Editar producto ───────────────────────────────────────────────────────────
function abrirEditarProd(p) {
    document.getElementById('ep-id').value     = p.id;
    document.getElementById('ep-nom').value    = p.nombre;
    document.getElementById('ep-nom2').value   = p.nombre2 || '';   // subtítulo complementario
    document.getElementById('ep-cat').value    = p.categoria;
    document.getElementById('ep-tam').value    = p.tamano;
    document.getElementById('ep-precio').value = p.precio_venta || '';
    document.getElementById('ep-rinde').value  = p.unidades_por_receta || 1;
    document.getElementById('ep-stmin').value  = p.stock_minimo || 0;
    document.getElementById('modal-ep').classList.add('on');
    setTimeout(() => document.getElementById('ep-nom').focus(), 100);
}

async function guardarEditarProd() {
    const nombre = document.getElementById('ep-nom').value.trim();
    if (!nombre) { toast('Nombre obligatorio', 'err'); return; }
    const fd = new FormData();
    fd.append('csrf_token', csrf());
    fd.append('accion',              'editar');
    fd.append('producto_id',         document.getElementById('ep-id').value);
    fd.append('nombre',              nombre);
    fd.append('nombre2',             document.getElementById('ep-nom2').value.trim()); // subtítulo
    fd.append('categoria',           document.getElementById('ep-cat').value);
    fd.append('tamano',              document.getElementById('ep-tam').value);
    fd.append('precio',              document.getElementById('ep-precio').value || 0);
    fd.append('unidades_por_receta', document.getElementById('ep-rinde').value || 1);
    fd.append('stock_minimo',        document.getElementById('ep-stmin').value || 0);
    const r = await fetch('api/guardar_producto.php', {method:'POST',body:fd});
    const d = await r.json();
    if (d.success) {
        document.getElementById('modal-ep').classList.remove('on');
        toast('Producto actualizado', 'ok');
        setTimeout(() => location.reload(), 1000);
    } else toast(d.error || 'Error', 'err');
}

// ── Duplicar producto ─────────────────────────────────────────────────────────
async function duplicarProd(id, nombre) {
    if (!confirm(`¿Duplicar "${nombre}" con toda su receta?\n\nSe creará una copia con el nombre "${nombre} (Copia)".`)) return;
    const fd = new FormData();
    fd.append('csrf_token', csrf());
    fd.append('accion',      'duplicar');
    fd.append('producto_id', id);
    const r = await fetch('api/guardar_producto.php', {method:'POST',body:fd});
    const d = await r.json();
    if (d.success) {
        toast(`"${d.nombre}" creado correctamente`, 'ok');
        setTimeout(() => location.reload(), 1200);
    } else toast(d.error || 'Error al duplicar', 'err');
}

// ── Nuevo producto ────────────────────────────────────────────────────────────
async function guardarNuevoProducto() {
    const nombre = document.getElementById('np-nom').value.trim();
    if (!nombre) { toast('Nombre obligatorio', 'err'); return; }
    const fd = new FormData();
    fd.append('csrf_token', csrf()); fd.append('accion','crear');
    fd.append('nombre',   nombre);
    fd.append('nombre2',  document.getElementById('np-nom2').value.trim()); // subtítulo complementario
    fd.append('categoria',document.getElementById('np-cat').value);
    fd.append('tamano',   document.getElementById('np-tam').value);
    fd.append('precio',              document.getElementById('np-precio').value || 0);
    fd.append('unidades_por_receta', document.getElementById('np-rinde').value  || 1);
    fd.append('stock_minimo',        document.getElementById('np-stmin').value  || 0);
    const r = await fetch('api/guardar_producto.php', {method:'POST',body:fd});
    const d = await r.json();
    if (d.success) { toast('Producto creado', 'ok'); setTimeout(() => location.reload(), 1000); }
    else toast(d.error || 'Error', 'err');
}

/* ── Calculadora bidireccional ───────────────────────────────────────────── */
// Cuántos ingredientes necesito para producir N sándwiches
function calcIngredientes(prodId, rinde) {
    const n    = parseInt(document.getElementById('calc-u-' + prodId)?.value) || 1;
    const row  = document.getElementById('rr-' + prodId);
    const tbl  = row?.querySelector('.ing-table tbody');
    if (!tbl) return;
    rinde = rinde || 1;
    const rows = tbl.querySelectorAll('tr');
    let html = '';
    rows.forEach(tr => {
        const celdas = tr.querySelectorAll('td');
        if (celdas.length < 2) return;
        const nombre = celdas[0]?.textContent?.replace(/⚡crítico/g,'').trim();
        // cantidad_requerida está en la segunda celda, parseamos el número
        const cantText = celdas[1]?.textContent?.match(/[\d.,]+/)?.[0]?.replace(',','.');
        if (!cantText || !nombre) return;
        const cantPorTanda = parseFloat(cantText) || 0;
        const cantPorUnidad = cantPorTanda / rinde;
        const total = cantPorUnidad * n;
        const unidad = celdas[1]?.textContent?.replace(/[\d.,]+/,'').replace('(tanda)','').trim();
        html += `<div style="display:flex;justify-content:space-between;padding:2px 0;border-bottom:1px solid #dbeafe">
            <span>${nombre}</span>
            <strong style="color:#1d4ed8">${formatDecimal(total)} ${unidad}</strong>
        </div>`;
    });
    const el = document.getElementById('calc-ings-' + prodId);
    if (el) el.innerHTML = html || '<span style="color:var(--g5)">Sin ingredientes</span>';
}

// Cuántos sándwiches puedo hacer con X cantidad de un ingrediente
function calcPorInsumo(prodId, rinde) {
    rinde = rinde || 1;
    const qty = parseFloat(document.getElementById('calc-qty-' + prodId)?.value) || 0;
    const sel = document.getElementById('calc-ing-' + prodId);
    if (!sel) return;
    const opt = sel.options[sel.selectedIndex];
    const cantPorTanda = parseFloat(opt?.dataset?.cant) || 0;
    if (!cantPorTanda || !qty) {
        const el = document.getElementById('calc-max-' + prodId);
        if (el) el.textContent = '';
        return;
    }
    const cantPorUnidad = cantPorTanda / rinde;
    const maxUnidades   = Math.floor(qty / cantPorUnidad);
    const el = document.getElementById('calc-max-' + prodId);
    if (el) el.textContent = `→ puedo hacer ${maxUnidades} sándwiches`;
}

/* ── Actualizar producción estimada ──────────────────────────────────────── */
async function actualizarCapacidad() {
    const val = parseInt(document.getElementById('prod-estimada-input')?.value) || 0;
    if (val <= 0) { toast('Ingresa un valor válido', 'err'); return; }
    const fd = new FormData();
    fd.append('csrf_token', csrf());
    fd.append('accion', 'actualizar_capacidad');
    fd.append('produccion_estimada', val);
    try {
        const r = await fetch('api/guardar_producto.php', {method:'POST',body:fd});
        const d = await r.json();
        if (d.success) { toast('Capacidad actualizada — recargando…', 'ok'); setTimeout(()=>location.reload(),900); }
        else toast(d.error||'Error','err');
    } catch(e){ toast('Error de conexión','err'); }
}

/* ── Recalcular costo_calculado de todos los productos (recetas + insumos) ── */
async function recalcularCostos() {
    if (!confirm('¿Recalcular el costo de todos los productos a partir de las recetas e insumos actuales?')) return;
    const fd = new FormData();
    fd.append('csrf_token', csrf());
    try {
        const r = await fetch('api/recalcular.php', {method:'POST', body:fd});
        const d = await r.json();
        if (d.success) { toast(`${d.actualizados} producto(s) recalculados — recargando…`, 'ok'); setTimeout(()=>location.reload(),900); }
        else toast(d.error||'Error','err');
    } catch(e){ toast('Error de conexión','err'); }
}

// ---- AJUSTE DE STOCK (regalar / desechar) ----

function abrirAjuste(pid, nombre, stockActual, tipo) {
    document.getElementById('ajuste-pid').value   = pid;
    document.getElementById('ajuste-tipo').value  = tipo;
    document.getElementById('ajuste-cantidad').value = 1;
    document.getElementById('ajuste-motivo').value   = '';
    document.getElementById('ajuste-stock-disp').textContent = 'Stock disponible: ' + stockActual + ' unidades';
    if (tipo === 'obsequio') {
        document.getElementById('ajuste-titulo').textContent     = '🎁 Regalar: ' + nombre;
        document.getElementById('ajuste-lbl-cantidad').textContent = 'Cantidad a regalar';
        document.getElementById('ajuste-btn').textContent        = '🎁 Confirmar Obsequio';
        document.getElementById('ajuste-btn').style.background   = '#9d174d';
    } else {
        document.getElementById('ajuste-titulo').textContent     = '🗑 Desechar: ' + nombre;
        document.getElementById('ajuste-lbl-cantidad').textContent = 'Cantidad a desechar';
        document.getElementById('ajuste-btn').textContent        = '🗑 Confirmar Desecho';
        document.getElementById('ajuste-btn').style.background   = '#374151';
    }
    document.getElementById('modal-ajuste').classList.add('on');
    setTimeout(() => document.getElementById('ajuste-cantidad').focus(), 100);
}

async function guardarAjuste() {
    const pid      = document.getElementById('ajuste-pid').value;
    const tipo     = document.getElementById('ajuste-tipo').value;
    const cantidad = parseInt(document.getElementById('ajuste-cantidad').value);
    const motivo   = document.getElementById('ajuste-motivo').value.trim();
    if (!pid || isNaN(cantidad) || cantidad < 1) { toast('Cantidad inválida', 'err'); return; }
    const btn = document.getElementById('ajuste-btn');
    btn.disabled = true;
    const fd = new FormData();
    fd.append('csrf_token',   csrf());
    fd.append('producto_id',  pid);
    fd.append('cantidad',     cantidad);
    fd.append('tipo',         tipo);
    fd.append('motivo',       motivo);
    try {
        const r = await fetch('api/ajuste_stock.php', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.success) {
            document.getElementById('modal-ajuste').classList.remove('on');
            const etq = tipo === 'obsequio' ? 'Obsequio registrado' : 'Desecho registrado';
            toast(etq + ' — ' + cantidad + ' unid.', 'ok');
            setTimeout(() => location.reload(), 900);
        } else {
            toast(d.error || 'Error al registrar ajuste', 'err');
        }
    } catch(e) { toast('Error de conexión', 'err'); }
    finally { btn.disabled = false; }
}

var fmt = formatMiles;
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
