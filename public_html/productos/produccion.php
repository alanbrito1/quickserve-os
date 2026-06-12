<?php
/**
 * productos/produccion.php — Registro diario de producción de producto terminado.
 *
 * Flujo:
 *   1. Usuario registra cuántos sándwiches produjo hoy de cada tipo.
 *   2. El sistema descuenta los insumos del inventario de materia prima.
 *   3. El stock de producto terminado (productos.stock_disponible) aumenta.
 *   4. Al vender, el sistema descuenta del stock terminado (no de insumos).
 */
require_once __DIR__ . '/../app/middleware/auth_check.php';
require_once __DIR__ . '/../app/models/RecetaModel.php';

$nav_activo = 'productos';
permiso_requerir('productos', 'solo_ver');

// ── Período ───────────────────────────────────────────────────────────────────
$fecha = $_GET['fecha'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) $fecha = date('Y-m-d');

// ── Datos ─────────────────────────────────────────────────────────────────────
// nombre2 incluido para mostrarlo como subtítulo en las cards de stock y en el modal de producción
$productos = db()->query(
    "SELECT id, nombre, nombre2, categoria, unidades_por_receta, stock_disponible, stock_minimo,
            costo_calculado
     FROM productos WHERE activo = 1 ORDER BY nombre"
)->fetchAll();

// Lotes del día seleccionado — nombre2 incluido para mostrarlo junto al nombre del producto
$lotes = db()->prepare(
    "SELECT pl.id, pl.cantidad, pl.costo_unitario, pl.notas, pl.estado, pl.created_at,
            p.nombre AS producto_nombre, p.nombre2 AS producto_nombre2, p.categoria,
            u.nombre AS usuario_nombre
     FROM produccion_lotes pl
     JOIN productos p ON p.id = pl.producto_id
     LEFT JOIN usuarios u ON u.id = pl.created_by
     WHERE pl.fecha_produccion = ?
     ORDER BY pl.created_at DESC"
);
$lotes->execute([$fecha]);
$lotes = $lotes->fetchAll();

// Resumen del día: total por producto
$resumen_dia = [];
foreach ($lotes as $l) {
    if ($l['estado'] === 'activo') {
        $resumen_dia[$l['producto_nombre']] = ($resumen_dia[$l['producto_nombre']] ?? 0) + (int)$l['cantidad'];
    }
}
$total_dia = array_sum($resumen_dia);

// Cargar stock actual de insumos (array indexado por id para lookup O(1) en JS)
$stocks_insumos = db()->query(
    "SELECT id, nombre, stock_actual, unidad_medida FROM insumos WHERE activo = 1"
)->fetchAll(PDO::FETCH_UNIQUE);

// ── Sugerencia de producción — período configurable ───────────────────────────
$dias_validos  = [7, 14, 30];
$dias_analisis = in_array((int)($_GET['dias'] ?? 14), $dias_validos) ? (int)$_GET['dias'] : 14;

// Ventas diarias promedio por producto (excluye hoy para no sesgar con datos parciales)
$stmt_avg = db()->prepare(
    "SELECT vd.producto_id,
            SUM(vd.cantidad)                          AS total_vendidas,
            COUNT(DISTINCT DATE(v.fecha_venta))       AS dias_activos
     FROM venta_detalles vd
     JOIN ventas v ON v.id = vd.venta_id
     WHERE v.fecha_venta >= DATE_SUB(CURDATE(), INTERVAL :dias DAY)
       AND DATE(v.fecha_venta) < CURDATE()
       AND v.estado != 'anulada'
     GROUP BY vd.producto_id"
);
$stmt_avg->execute([':dias' => $dias_analisis]);
$ventas_avg = [];
foreach ($stmt_avg->fetchAll() as $row) {
    $ventas_avg[(int)$row['producto_id']] = [
        'total' => (int)$row['total_vendidas'],
        'dias'  => (int)$row['dias_activos'],
        'avg'   => round((float)$row['total_vendidas'] / $dias_analisis, 1),
    ];
}

// Variante más vendida por producto (si mig. 035 existe)
$variante_top = [];
$tiene_035p = false;
try {
    $tiene_035p = (int)db()->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='venta_detalles'
           AND COLUMN_NAME='variante_etiqueta'"
    )->fetchColumn() > 0;
} catch (Exception $e) {}
if ($tiene_035p) {
    $stmt_var = db()->prepare(
        "SELECT vd.producto_id, vd.variante_etiqueta, SUM(vd.cantidad) AS total
         FROM venta_detalles vd
         JOIN ventas v ON v.id = vd.venta_id
         WHERE v.fecha_venta >= DATE_SUB(CURDATE(), INTERVAL :dias DAY)
           AND DATE(v.fecha_venta) < CURDATE()
           AND v.estado != 'anulada'
           AND vd.variante_etiqueta IS NOT NULL
         GROUP BY vd.producto_id, vd.variante_etiqueta
         ORDER BY vd.producto_id, total DESC"
    );
    $stmt_var->execute([':dias' => $dias_analisis]);
    $var_all = [];
    foreach ($stmt_var->fetchAll() as $r) {
        $pid = (int)$r['producto_id'];
        $var_all[$pid][] = ['etiqueta' => $r['variante_etiqueta'], 'total' => (int)$r['total']];
    }
    foreach ($var_all as $pid => $vars) {
        $total_pid = array_sum(array_column($vars, 'total'));
        $top = $vars[0]; // ORDER BY total DESC → primer elemento es el más vendido
        $pct = $total_pid > 0 ? round($top['total'] / $total_pid * 100) : 0;
        $variante_top[$pid] = ['etiqueta' => $top['etiqueta'], 'pct' => $pct];
    }
}

// Armar tabla de sugerencias: solo productos con historial de ventas
$sugerencias = [];
foreach ($productos as $p) {
    $pid = (int)$p['id'];
    $avg = $ventas_avg[$pid]['avg'] ?? 0.0;
    if ($avg <= 0) continue;
    $stock    = (int)$p['stock_disponible'];
    $sugerido = max(0, (int)ceil($avg) - $stock);
    $sugerencias[] = [
        'id'       => $pid,
        'nombre'   => $p['nombre'],
        'avg'      => $avg,
        'stock'    => $stock,
        'minimo'   => (int)$p['stock_minimo'],
        'sugerido' => $sugerido,
        'variante' => $variante_top[$pid] ?? null,
    ];
}
// Mayor sugerido primero, luego mayor promedio
usort($sugerencias, fn($a, $b) => $b['sugerido'] <=> $a['sugerido'] ?: $b['avg'] <=> $a['avg']);

// Construir datos de recetas para el preview de insumos en el modal
// El stock real se lee de $stocks_insumos y se pasa como STOCKS al JS
$recetas_js = [];
foreach ($productos as $prod) {
    $ings = RecetaModel::ingredientes_de((int)$prod['id']);
    $recetas_js[$prod['id']] = [
        'nombre'              => $prod['nombre'],
        'unidades_por_receta' => (int)$prod['unidades_por_receta'],
        'ingredientes_full'   => $ings, // JS calcula descuento usando STOCKS[insumo_id].stock_actual
    ];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Registro de Producción — <?= APP_NAME ?></title>
    <style>
        :root { --brand:#e94f37; --dark:#111827; --g2:#374151; --g5:#6b7280; --g8:#d1d5db; --g9:#f3f4f6; --white:#fff; --green:#059669; }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: system-ui, -apple-system, sans-serif; background: var(--g9); color: var(--dark); }
        .main  { max-width: 960px; margin: 0 auto; padding: 20px 14px 60px; }
        .page-hdr { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap; margin-bottom: 20px; }
        .page-title { font-size: 22px; font-weight: 800; }
        .page-sub   { font-size: 13px; color: var(--g5); margin-top: 3px; }
        .hdr-btns   { display: flex; gap: 8px; flex-wrap: wrap; }

        /* Selector de fecha */
        .fecha-bar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
                     background: var(--white); border: 1px solid var(--g8); border-radius: 12px;
                     padding: 12px 16px; margin-bottom: 20px; }
        .fecha-bar strong { font-size: 13px; }
        .fecha-bar input[type=date] { padding: 6px 10px; border: 1px solid var(--g8); border-radius: 8px; font-size: 13px; }
        .btn-fecha { padding: 7px 16px; background: var(--brand); color: #fff; border: none; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; }

        /* KPIs */
        .kpi-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 10px; margin-bottom: 20px; }
        .kpi { background: var(--white); border: 1px solid var(--g8); border-radius: 12px; padding: 14px 16px; }
        .kpi-val { font-size: 26px; font-weight: 800; color: var(--dark); }
        .kpi-val.brand { color: var(--brand); }
        .kpi-val.green { color: var(--green); }
        .kpi-lbl { font-size: 11px; color: var(--g5); margin-top: 3px; text-transform: uppercase; letter-spacing: .4px; }

        /* Cards de stock */
        .stock-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 10px; margin-bottom: 20px; }
        .stock-card { background: var(--white); border: 1px solid var(--g8); border-radius: 12px; padding: 14px; }
        .stock-card.bajo { border-color: #fca5a5; background: #fff8f7; }
        .stock-prod-nombre { font-size: 14px; font-weight: 700; }
        .stock-val { font-size: 28px; font-weight: 800; margin: 6px 0 2px; }
        .stock-val.ok   { color: var(--green); }
        .stock-val.bajo { color: var(--brand); }
        .stock-lbl { font-size: 11px; color: var(--g5); }

        /* Tabla de lotes */
        .section-title { font-size: 15px; font-weight: 700; margin-bottom: 10px; }
        .tbl-wrap { background: var(--white); border: 1px solid var(--g8); border-radius: 12px; overflow: hidden; overflow-x: auto; margin-bottom: 20px; }
        .tbl { width: 100%; border-collapse: collapse; }
        .tbl thead th { background: var(--g9); font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; padding: 10px 12px; text-align: left; color: var(--g5); }
        .tbl tbody tr { border-bottom: 1px solid var(--g9); }
        .tbl tbody tr:last-child { border-bottom: none; }
        .tbl tbody tr:hover { background: #fafafa; }
        .tbl tbody tr.anulado td { opacity: .5; text-decoration: line-through; }
        .tbl td { padding: 10px 12px; font-size: 13px; vertical-align: middle; }
        .badge { display: inline-block; font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 999px; }
        .badge-activo  { background: #d1fae5; color: #065f46; }
        .badge-anulado { background: #fee2e2; color: #991b1b; }

        /* Botones */
        .btn-primary { background: var(--brand); color: #fff; border: none; padding: 8px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; }
        .btn-primary:hover { background: #c94330; }
        .btn-sec { background: var(--white); color: var(--dark); border: 1px solid var(--g8); padding: 8px 14px; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; }
        .btn-tbl { border: none; padding: 4px 9px; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; }
        .btn-anular { background: #fee2e2; color: #991b1b; }
        .btn-anular:hover { background: #fca5a5; }

        /* Preview ingredientes */
        .preview-box { background: var(--g9); border-radius: 8px; padding: 12px; margin: 12px 0; font-size: 13px; }
        .preview-row { display: flex; justify-content: space-between; padding: 3px 0; border-bottom: 1px solid var(--g8); }
        .preview-row:last-child { border-bottom: none; }
        .preview-ok   { color: var(--green); font-weight: 600; }
        .preview-warn { color: #d97706; font-weight: 600; }
        .preview-err  { color: var(--brand); font-weight: 700; }

        /* Modal */
        .overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.45); align-items: flex-start; justify-content: center; padding: 20px; z-index: 200; overflow-y: auto; }
        .overlay.on { display: flex; }
        .modal { background: var(--white); border-radius: 14px; width: 100%; max-width: 580px; margin: auto; padding: 24px; box-shadow: 0 20px 60px rgba(0,0,0,.2); max-height: 90vh; max-height: 90dvh; overflow-y: auto; -webkit-overflow-scrolling: touch; }
        .modal-hdr { display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; }
        .modal-hdr span { font-size: 17px; font-weight: 700; }
        .btn-cls { background: var(--g9); border: none; width: 30px; height: 30px; border-radius: 50%; font-size: 14px; cursor: pointer; color: var(--g5); }
        .fg { display: flex; flex-direction: column; gap: 4px; margin-bottom: 12px; }
        .fg label { font-size: 12px; font-weight: 600; color: var(--g5); }
        .fg input, .fg select, .fg textarea { padding: 9px 10px; border: 1px solid var(--g8); border-radius: 8px; font-size: 14px; color: var(--dark); outline: none; font-family: inherit; }
        .fg input:focus, .fg select:focus { border-color: var(--brand); }
        .btn-submit { width: 100%; margin-top: 16px; background: var(--brand); color: #fff; border: none; padding: 11px; border-radius: 10px; font-size: 14px; font-weight: 700; cursor: pointer; }

        /* Botón desechar en tarjeta de stock */
        .btn-desechar { margin-top: 10px; width: 100%; padding: 6px 0; background: #f3f4f6; color: #374151;
                        border: 1px solid var(--g8); border-radius: 8px; font-size: 12px; font-weight: 600;
                        cursor: pointer; }
        .btn-desechar:hover { background: #fee2e2; color: #991b1b; border-color: #fca5a5; }

        /* Empty */
        .empty { text-align: center; padding: 36px; color: var(--g5); }

        /* Responsive */
        @media (max-width: 639px) {
            .main { padding: 12px 10px 60px; }
            .page-title { font-size: 18px; }
            .kpi-val { font-size: 20px; }
            .stock-val { font-size: 22px; }
            .stock-grid { grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); }
        }
        @media (max-width: 479px) {
            .hide-xs { display: none !important; }
            .hdr-btns { width: 100%; }
            .hdr-btns .btn-primary, .hdr-btns .btn-sec { flex: 1; text-align: center; }
            .stock-grid { grid-template-columns: 1fr 1fr; }
        }

        /* Toast */
        .toast { position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%) translateY(20px); padding: 10px 20px; border-radius: 24px; font-size: 14px; font-weight: 600; opacity: 0; transition: .25s; z-index: 999; pointer-events: none; }
        .toast.on  { opacity: 1; transform: translateX(-50%) translateY(0); }
        .toast-ok  { background: #065f46; color: #d1fae5; }
        .toast-err { background: #991b1b; color: #fee2e2; }

        /* Sugerencia de producción */
        .sug-panel { background: var(--white); border: 1px solid var(--g8); border-radius: 12px; margin-bottom: 20px; overflow: hidden; }
        .sug-panel summary { list-style: none; cursor: pointer; padding: 14px 16px;
                             display: flex; align-items: center; gap: 8px;
                             font-size: 14px; font-weight: 700; user-select: none; }
        .sug-panel summary::-webkit-details-marker { display: none; }
        .sug-panel summary .sug-chevron { margin-left: auto; font-size: 12px; color: var(--g5); transition: transform .2s; }
        .sug-panel[open] summary .sug-chevron { transform: rotate(180deg); }
        .sug-panel summary .sug-badge { font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 999px; }
        .sug-table-wrap { overflow-x: auto; }
        .sug-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .sug-table th { background: var(--g9); font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .4px; padding: 9px 14px; text-align: left; color: var(--g5); }
        .sug-table td { padding: 10px 14px; border-top: 1px solid var(--g9); vertical-align: middle; }
        .sug-table tr:first-child td { border-top: none; }
        .sug-sugerido { font-size: 18px; font-weight: 800; }
        .sug-sugerido.ok { color: var(--green); }
        .sug-sugerido.urgente { color: var(--brand); }
        .sug-sugerido.cero { color: var(--g5); font-size: 14px; }
        .sug-footer { padding: 10px 16px; font-size: 11px; color: var(--g5); border-top: 1px solid var(--g9); }
        .badge-var { background: #dbeafe; color: #1e40af; padding: 1px 7px; border-radius: 999px; font-size: 11px; font-weight: 600; }
    </style>
</head>
<body>
<?php $nav_activo = 'productos'; include __DIR__ . '/../app/views/nav.php'; ?>

<main class="main">

    <!-- Encabezado -->
    <div class="page-hdr">
        <div>
            <h1 class="page-title">Registro de Producción</h1>
            <p class="page-sub">Inventario de producto terminado — descuenta insumos al producir</p>
        </div>
        <div class="hdr-btns">
            <a href="<?= APP_BASE ?>/productos/" class="btn-sec">Ver Recetas y Costos</a>
            <?php if (permiso_tiene('productos', 'editar_existentes')): ?>
            <button class="btn-primary" onclick="abrirRegistro()">+ Registrar Producción</button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Selector de fecha -->
    <form method="GET" class="fecha-bar">
        <strong>Fecha:</strong>
        <input type="date" name="fecha" value="<?= htmlspecialchars($fecha) ?>">
        <button type="submit" class="btn-fecha">Ver</button>
        <span style="margin-left:auto;font-size:12px;color:var(--g5)">
            <?= date('d/m/Y', strtotime($fecha)) ?>
            <?= $fecha === date('Y-m-d') ? ' — Hoy' : '' ?>
        </span>
    </form>

    <!-- KPIs del día -->
    <div class="kpi-row">
        <div class="kpi">
            <div class="kpi-val brand"><?= $total_dia ?></div>
            <div class="kpi-lbl">Producidos hoy</div>
        </div>
        <div class="kpi">
            <div class="kpi-val"><?= count($lotes) ?></div>
            <div class="kpi-lbl">Tandas registradas</div>
        </div>
        <div class="kpi">
            <div class="kpi-val green"><?= array_sum(array_column($productos, 'stock_disponible')) ?></div>
            <div class="kpi-lbl">Stock total disponible</div>
        </div>
        <?php
        $bajo_stock = count(array_filter($productos, fn($p) => (int)$p['stock_disponible'] < (int)$p['stock_minimo'] && (int)$p['stock_minimo'] > 0));
        ?>
        <div class="kpi <?= $bajo_stock > 0 ? 'highlight' : '' ?>">
            <div class="kpi-val <?= $bajo_stock > 0 ? 'brand' : '' ?>"><?= $bajo_stock ?></div>
            <div class="kpi-lbl">Productos con stock bajo</div>
        </div>
    </div>

    <!-- Sugerencia de producción diaria -->
    <?php if (!empty($sugerencias)): ?>
    <?php
    $hay_urgente = !empty(array_filter($sugerencias, fn($s) => $s['sugerido'] >= 5));
    $total_sugerido = array_sum(array_column($sugerencias, 'sugerido'));
    ?>
    <details class="sug-panel" <?= ($total_sugerido > 0 || $fecha === date('Y-m-d')) ? 'open' : '' ?>>
        <summary>
            <span>📊</span>
            <span>Sugerencia de producción</span>
            <?php if ($total_sugerido > 0): ?>
            <span class="sug-badge" style="background:#fef3c7;color:#92400e">
                +<?= $total_sugerido ?> a producir
            </span>
            <?php else: ?>
            <span class="sug-badge" style="background:#d1fae5;color:#065f46">Stock OK</span>
            <?php endif; ?>
            <span style="display:flex;gap:4px;margin-left:auto;margin-right:8px" onclick="event.stopPropagation()">
                <?php foreach ([7, 14, 30] as $d): ?>
                <a href="?fecha=<?= urlencode($fecha) ?>&dias=<?= $d ?>"
                   style="padding:2px 9px;border-radius:99px;font-size:11px;font-weight:700;text-decoration:none;
                          <?= $dias_analisis === $d ? 'background:var(--brand);color:#fff' : 'background:var(--g9);color:var(--g5)' ?>">
                    <?= $d ?>d
                </a>
                <?php endforeach; ?>
            </span>
            <span class="sug-chevron">▼</span>
        </summary>
        <div class="sug-table-wrap">
            <table class="sug-table">
                <thead>
                    <tr>
                        <th>Producto</th>
                        <th style="text-align:right">Prom./día</th>
                        <th style="text-align:right">Stock actual</th>
                        <th style="text-align:right">Sugerido producir</th>
                        <?php if (!empty($variante_top)): ?>
                        <th>Variante más vendida</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($sugerencias as $s): ?>
                <?php
                $sugerido = (int)$s['sugerido'];
                $clsSug = $sugerido === 0 ? 'cero' : ($sugerido >= 5 ? 'urgente' : 'ok');
                $clsStock = ($s['minimo'] > 0 && $s['stock'] < $s['minimo']) ? 'color:#dc2626;font-weight:700' : '';
                ?>
                <tr>
                    <td style="font-weight:600"><?= htmlspecialchars($s['nombre']) ?></td>
                    <td style="text-align:right;color:var(--g5)"><?= fmt_cantidad($s['avg'], 1) ?></td>
                    <td style="text-align:right;<?= $clsStock ?>"><?= $s['stock'] ?></td>
                    <td style="text-align:right">
                        <span class="sug-sugerido <?= $clsSug ?>">
                            <?= $sugerido > 0 ? '+' . $sugerido : '—' ?>
                        </span>
                    </td>
                    <?php if (!empty($variante_top)): ?>
                    <td>
                        <?php if (isset($s['variante'])): ?>
                        <span class="badge-var"><?= htmlspecialchars($s['variante']['etiqueta']) ?></span>
                        <span style="font-size:11px;color:var(--g5);margin-left:3px"><?= $s['variante']['pct'] ?>%</span>
                        <?php else: ?>
                        <span style="font-size:11px;color:var(--g5)">Sin variante</span>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="sug-footer">
            Sugerido = ceil(promedio/día) − stock actual. Datos de ventas completadas desde <?= date('d/m/Y', strtotime("-{$dias_analisis} days")) ?> (excluye hoy). Productos sin historial de ventas no se muestran.
        </div>
    </details>
    <?php endif; ?>

    <!-- Stock de producto terminado por producto -->
    <p class="section-title">Stock de Producto Terminado</p>
    <div class="stock-grid">
        <?php foreach ($productos as $p):
            $bajo = (int)$p['stock_minimo'] > 0 && (int)$p['stock_disponible'] < (int)$p['stock_minimo'];
        ?>
        <div class="stock-card <?= $bajo ? 'bajo' : '' ?>">
            <div class="stock-prod-nombre"><?= htmlspecialchars($p['nombre']) ?></div>
            <?php if (!empty($p['nombre2'])): ?>
            <div style="font-size:11px;color:var(--g5);margin-top:1px"><?= htmlspecialchars($p['nombre2']) ?></div>
            <?php endif; ?>
            <div class="stock-val <?= $bajo ? 'bajo' : 'ok' ?>"><?= (int)$p['stock_disponible'] ?></div>
            <div class="stock-lbl">
                unidades disponibles
                <?php if ((int)$p['stock_minimo'] > 0): ?>
                · mínimo: <?= (int)$p['stock_minimo'] ?>
                <?php endif; ?>
            </div>
            <?php if (isset($resumen_dia[$p['nombre']])): ?>
            <div style="margin-top:6px;font-size:12px;color:var(--green);font-weight:600">
                +<?= $resumen_dia[$p['nombre']] ?> producidos hoy
            </div>
            <?php endif; ?>
            <?php if (permiso_tiene('productos','editar_existentes') && (int)$p['stock_disponible'] > 0): ?>
            <!-- Botón desechar: usa IC_TRASH SVG + texto para claridad en mobile -->
            <button class="btn-desechar"
                    onclick="abrirDesecho(<?= $p['id'] ?>,'<?= htmlspecialchars(addslashes($p['nombre'])) ?>',<?= (int)$p['stock_disponible'] ?>)">
                <?= IC_TRASH ?> Desechar
            </button>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Tabla de lotes del día -->
    <p class="section-title">
        Tandas del <?= date('d/m/Y', strtotime($fecha)) ?>
        <?php if (!empty($lotes)): ?>
        <span style="font-weight:400;color:var(--g5)">(<?= count($lotes) ?>)</span>
        <?php endif; ?>
    </p>
    <div class="tbl-wrap">
        <?php if (empty($lotes)): ?>
        <div class="empty">No hay tandas registradas para esta fecha.</div>
        <?php else: ?>
        <table class="tbl">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Producto</th>
                    <th>Cantidad</th>
                    <th class="hide-xs">Costo/u (al producir)</th>
                    <th>Hora</th>
                    <th class="hide-xs">Registrado por</th>
                    <th class="hide-xs">Notas</th>
                    <th>Estado</th>
                    <?php if (permiso_tiene('productos', 'editar_existentes')): ?>
                    <th></th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($lotes as $l): ?>
                <tr class="<?= $l['estado'] === 'anulado' ? 'anulado' : '' ?>">
                    <td style="color:var(--g5)">#<?= $l['id'] ?></td>
                    <td>
                        <strong><?= htmlspecialchars($l['producto_nombre']) ?></strong>
                        <?php if (!empty($l['producto_nombre2'])): ?>
                        <div style="font-size:11px;color:var(--g5)"><?= htmlspecialchars($l['producto_nombre2']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><strong><?= (int)$l['cantidad'] ?></strong> unidades</td>
                    <td class="hide-xs">
                        <?php if ($l['costo_unitario']): ?>
                        $<?= fmt_moneda((float)$l['costo_unitario']) ?>
                        <?php else: ?>
                        <span style="color:var(--g5)">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="color:var(--g5);font-size:12px"><?= date('H:i', strtotime($l['created_at'])) ?></td>
                    <td class="hide-xs" style="font-size:12px"><?= htmlspecialchars($l['usuario_nombre'] ?? '—') ?></td>
                    <td class="hide-xs" style="font-size:12px;color:var(--g5)"><?= htmlspecialchars($l['notas'] ?? '') ?></td>
                    <td><span class="badge badge-<?= htmlspecialchars($l['estado']) ?>"><?= htmlspecialchars(ucfirst($l['estado'])) ?></span></td>
                    <?php if (permiso_tiene('productos', 'editar_existentes')): ?>
                    <td>
                        <?php if ($l['estado'] === 'activo'): ?>
                        <button class="btn-tbl btn-anular"
                                onclick="anularLote(<?= $l['id'] ?>, '<?= htmlspecialchars($l['producto_nombre']) ?>', <?= (int)$l['cantidad'] ?>)">
                            Anular
                        </button>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

</main>

<!-- CSRF -->
<input type="hidden" id="csrf-tk" value="<?= htmlspecialchars(csrf_token()) ?>">

<!-- Modal Registrar Producción -->
<?php if (permiso_tiene('productos', 'editar_existentes')): ?>
<div class="overlay" id="modal-prod" onclick="if(event.target===this)cerrar()">
  <div class="modal">
    <div class="modal-hdr">
      <span>Registrar Producción</span>
      <button class="btn-cls" onclick="cerrar()">&#x2715;</button>
    </div>

    <div class="fg"><label>Producto *</label>
      <select id="mp-producto" onchange="actualizarPreview()">
        <option value="">— Selecciona un producto —</option>
        <?php foreach ($productos as $p): ?>
        <option value="<?= $p['id'] ?>"
                data-rinde="<?= (int)$p['unidades_por_receta'] ?>">
            <?= htmlspecialchars($p['nombre']) ?><?= !empty($p['nombre2']) ? ' — ' . htmlspecialchars($p['nombre2']) : '' ?>
        </option>
        <?php endforeach; ?>
      </select></div>

    <div class="fg"><label>Cantidad a producir *</label>
      <input type="text" inputmode="numeric" pattern="[0-9]+"
             id="mp-cantidad" placeholder="Ej: 20"
             onchange="actualizarPreview()" oninput="this.value=this.value.replace(/[^0-9]/g,'')"></div>

    <div class="fg"><label>Fecha de producción *</label>
      <input type="date" id="mp-fecha" value="<?= date('Y-m-d') ?>"></div>

    <!-- Botón explícito para calcular los insumos necesarios -->
    <button type="button" class="btn-sec" style="width:100%;margin-bottom:10px"
            onclick="actualizarPreview()">
        Ver insumos que se descontarán
    </button>

    <div id="preview-ingredientes" style="display:none">
      <p style="font-size:12px;font-weight:700;color:var(--g5);text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px">
          Insumos que se descontarán del inventario:
      </p>
      <div class="preview-box" id="preview-lista"></div>
    </div>

    <div class="fg"><label>Notas (opcional)</label>
      <input type="text" id="mp-notas" placeholder="Turno mañana, calidad especial, etc."></div>

    <button class="btn-submit" onclick="guardarProduccion()">Registrar Producción</button>
  </div>
</div>
<?php endif; ?>

<!-- Modal Desechar stock de producto terminado -->
<?php if (permiso_tiene('productos', 'editar_existentes')): ?>
<div class="overlay" id="modal-desecho" onclick="if(event.target===this)cerrarDesecho()">
  <div class="modal">
    <div class="modal-hdr">
      <span id="desecho-titulo">Desechar producto</span>
      <button class="btn-cls" onclick="cerrarDesecho()">&#x2715;</button>
    </div>
    <input type="hidden" id="desecho-pid">
    <p id="desecho-aviso" style="font-size:13px;color:#374151;background:#fef3c7;border:1px solid #fde68a;
       border-radius:8px;padding:10px 12px;margin-bottom:14px;line-height:1.5">
        ⚠️ Esto baja el stock de producto terminado. Los insumos <strong>no se devuelven</strong>
        al inventario porque ya fueron consumidos al producir.
    </p>
    <div class="fg">
      <label>Cantidad a desechar *</label>
      <input type="text" inputmode="numeric" pattern="[0-9]+" id="desecho-cantidad"
             placeholder="Ej: 3" oninput="this.value=this.value.replace(/[^0-9]/g,'')">
      <span id="desecho-stock-lbl" style="font-size:11px;color:var(--g5)"></span>
    </div>
    <div class="fg">
      <label>Motivo (opcional)</label>
      <input type="text" id="desecho-motivo" maxlength="300"
             placeholder="Ej: producto vencido, golpeado, temperatura incorrecta…">
    </div>
    <button class="btn-submit" id="desecho-btn" onclick="confirmarDesecho()"
            style="background:#374151">🗑 Confirmar Desecho</button>
  </div>
</div>
<?php endif; ?>

<div class="toast" id="toast"></div>

<script>
/* Datos de recetas y stock actual cargados desde PHP */
const RECETAS = <?= json_encode($recetas_js, JSON_UNESCAPED_UNICODE) ?>;
const STOCKS  = <?= json_encode($stocks_insumos, JSON_UNESCAPED_UNICODE) ?>;

var _tt;
function toast(m, t) {
    var el = document.getElementById('toast');
    el.textContent = m;
    el.className = 'toast toast-' + t + ' on';
    clearTimeout(_tt);
    _tt = setTimeout(function(){ el.classList.remove('on'); }, 3200);
}
var csrf = function(){ return document.getElementById('csrf-tk').value; };
function cerrar() { document.getElementById('modal-prod').classList.remove('on'); }
document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') { cerrar(); cerrarDesecho(); }
});

function abrirRegistro() {
    document.getElementById('mp-producto').value = '';
    document.getElementById('mp-cantidad').value = '';
    document.getElementById('mp-notas').value   = '';
    document.getElementById('preview-ingredientes').style.display = 'none';
    document.getElementById('modal-prod').classList.add('on');
    setTimeout(function(){ document.getElementById('mp-producto').focus(); }, 100);
}

/* ── Preview de insumos a descontar ─────────────────────────────────────── */
function actualizarPreview() {
    var pid   = document.getElementById('mp-producto').value;
    var cant  = parseInt(document.getElementById('mp-cantidad').value) || 0;
    var prev  = document.getElementById('preview-ingredientes');
    var lista = document.getElementById('preview-lista');

    if (!pid || cant <= 0 || !RECETAS[pid]) {
        prev.style.display = 'none';
        return;
    }

    var r    = RECETAS[pid];
    var rinde = r.unidades_por_receta || 1;
    var ings  = r.ingredientes_full || [];
    var html  = '';

    ings.forEach(function(ing) {
        var descuento = (ing.cantidad_requerida / rinde) * cant;
        var stock     = STOCKS[ing.insumo_id] ? parseFloat(STOCKS[ing.insumo_id].stock_actual) : 0;
        var restante  = stock - descuento;
        var cls       = restante >= 0 ? 'preview-ok' : 'preview-err';
        var suficiente = restante >= 0;

        html += '<div class="preview-row">';
        html += '<span>' + ing.nombre + '</span>';
        html += '<span class="' + cls + '">';
        html += '−' + descuento.toLocaleString('es-CO', {maximumFractionDigits:4}) + ' ' + ing.unidad_medida;
        html += suficiente
            ? ' (quedan ' + restante.toLocaleString('es-CO', {maximumFractionDigits:4}) + ')'
            : ' ⚠ INSUFICIENTE';
        html += '</span></div>';
    });

    lista.innerHTML = html || '<div style="color:var(--g5)">Esta receta no tiene ingredientes.</div>';
    prev.style.display = 'block';
}

/* ── Guardar tanda ────────────────────────────────────────────────────── */
async function guardarProduccion() {
    var pid   = document.getElementById('mp-producto').value;
    var cant  = document.getElementById('mp-cantidad').value;
    var fecha = document.getElementById('mp-fecha').value;
    var notas = document.getElementById('mp-notas').value;

    if (!pid)   { toast('Selecciona un producto.', 'err'); return; }
    if (!cant || parseInt(cant) <= 0) { toast('Ingresa una cantidad válida.', 'err'); return; }
    if (!fecha) { toast('La fecha es obligatoria.', 'err'); return; }

    var fd = new FormData();
    fd.append('csrf_token', csrf());
    fd.append('accion',     'crear');
    fd.append('producto_id', pid);
    fd.append('cantidad',    cant);
    fd.append('fecha',       fecha);
    fd.append('notas',       notas);

    try {
        var r = await fetch('api/registrar_lote.php', {method:'POST', body:fd});
        var d = await r.json();
        if (d.success) {
            cerrar();
            toast(d.mensaje || 'Producción registrada.', 'ok');
            setTimeout(function(){ location.reload(); }, 1000);
        } else {
            toast(d.error || 'Error al registrar.', 'err');
        }
    } catch(e) { toast('Error de conexión.', 'err'); }
}

/* ── Desechar stock de producto terminado ─────────────────────────────── */

function abrirDesecho(pid, nombre, stockActual) {
    document.getElementById('desecho-pid').value       = pid;
    document.getElementById('desecho-titulo').textContent = '🗑 Desechar: ' + nombre;
    document.getElementById('desecho-stock-lbl').textContent = 'Stock disponible: ' + stockActual + ' unidades';
    document.getElementById('desecho-cantidad').value  = '';
    document.getElementById('desecho-motivo').value    = '';
    document.getElementById('modal-desecho').classList.add('on');
    setTimeout(function(){ document.getElementById('desecho-cantidad').focus(); }, 100);
}
function cerrarDesecho() {
    document.getElementById('modal-desecho').classList.remove('on');
}

async function confirmarDesecho() {
    var pid      = document.getElementById('desecho-pid').value;
    var cantidad = parseInt(document.getElementById('desecho-cantidad').value);
    var motivo   = document.getElementById('desecho-motivo').value.trim();
    if (!pid || isNaN(cantidad) || cantidad < 1) { toast('Ingresa una cantidad válida.', 'err'); return; }

    var btn = document.getElementById('desecho-btn');
    btn.disabled = true;
    var fd = new FormData();
    fd.append('csrf_token',  csrf());
    fd.append('producto_id', pid);
    fd.append('cantidad',    cantidad);
    fd.append('tipo',        'desecho');
    fd.append('motivo',      motivo);

    try {
        var r = await fetch('api/ajuste_stock.php', {method:'POST', body:fd});
        var d = await r.json();
        if (d.success) {
            cerrarDesecho();
            toast('Desecho registrado — ' + cantidad + ' unidades dadas de baja.', 'ok');
            setTimeout(function(){ location.reload(); }, 900);
        } else {
            toast(d.error || 'Error al registrar desecho.', 'err');
        }
    } catch(e) { toast('Error de conexión.', 'err'); }
    finally { btn.disabled = false; }
}

/* ── Anular tanda ─────────────────────────────────────────────────────── */
async function anularLote(id, nombre, cantidad) {
    if (!confirm('¿Anular la tanda #' + id + ' de ' + nombre + ' (' + cantidad + ' unidades)?\n\nEsto restaurará los insumos en el inventario.')) return;

    var fd = new FormData();
    fd.append('csrf_token', csrf());
    fd.append('accion',     'anular');
    fd.append('lote_id',    id);

    try {
        var r = await fetch('api/registrar_lote.php', {method:'POST', body:fd});
        var d = await r.json();
        if (d.success) {
            toast(d.mensaje || 'Lote anulado.', 'ok');
            setTimeout(function(){ location.reload(); }, 800);
        } else {
            toast(d.error || 'Error.', 'err');
        }
    } catch(e) { toast('Error de conexión.', 'err'); }
}
</script>
</body>
</html>
