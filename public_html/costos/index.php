<?php
/**
 * costos/index.php — Módulo Costos
 *
 * Gestiona todos los costos del negocio: directos (trazables a producción)
 * e indirectos (arriendo, servicios, intereses…). Los costos activos
 * alimentan el cálculo de costo total por producto en el módulo Productos.
 */
require_once __DIR__ . '/../app/middleware/auth_check.php';
require_once __DIR__ . '/../app/models/CostoIndirectoModel.php';
require_once __DIR__ . '/../app/helpers/ListasHelper.php';

$nav_activo = 'costos';
permiso_requerir('costos', 'solo_ver');

// ── Período seleccionado ───────────────────────────────────────────────────────
$mes  = max(1, min(12, (int)($_GET['mes']  ?? date('n'))));
$anio = (int)($_GET['anio'] ?? date('Y'));
$anio = max(2020, min((int)date('Y') + 1, $anio));

$mes_inicio = sprintf('%04d-%02d-01', $anio, $mes);
$mes_fin    = date('Y-m-t', strtotime($mes_inicio)); // último día del mes

$meses_nombres = [
    1=>'Enero', 2=>'Febrero', 3=>'Marzo',     4=>'Abril',
    5=>'Mayo',  6=>'Junio',   7=>'Julio',      8=>'Agosto',
    9=>'Septiembre', 10=>'Octubre', 11=>'Noviembre', 12=>'Diciembre',
];

// ── Costos vigentes en el período ─────────────────────────────────────────────
// Un costo aplica si inició antes de que termine el mes Y aún no había terminado
$costos = array_values(array_filter(CostoIndirectoModel::todos(), function($c) use ($mes_inicio, $mes_fin) {
    if ($c['fecha_inicio'] > $mes_fin) return false;
    if (!empty($c['fecha_fin']) && $c['fecha_fin'] < $mes_inicio) return false;
    return true;
}));
$ver = filtro_estado_actual(); // estado a mostrar (solo admin puede cambiarlo)

// KPIs de costos calculados desde el array filtrado
$total_mensual   = 0.0;
$total_directo   = 0.0;
$total_indirecto = 0.0;
$n_activos       = 0;
$n_pausados      = 0;
foreach ($costos as $c) {
    if ((int)$c['activo'] === 1) {
        $n_activos++;
        $vm = (float)$c['valor_mensual'];
        $total_mensual += $vm;
        if (($c['clasificacion'] ?? 'indirecto') === 'directo') {
            $total_directo += $vm;
        } else {
            $total_indirecto += $vm;
        }
    } else {
        $n_pausados++;
    }
}

// ── Compras del período (módulo Compras) ───────────────────────────────────────
$compras_mes = 0.0;
try {
    $stmt = db()->prepare(
        'SELECT COALESCE(SUM(total), 0) FROM compras
         WHERE fecha_compra BETWEEN ? AND ?'
    );
    $stmt->execute([$mes_inicio, $mes_fin . ' 23:59:59']);
    $compras_mes = (float) $stmt->fetchColumn();
} catch (Exception $e) { /* tabla no disponible */ }

// ── Depreciación de activos activos en el período ─────────────────────────────
$dep_activos_mensual = 0.0;
try {
    $stmt = db()->prepare(
        "SELECT COALESCE(SUM(depreciacion_mensual), 0)
         FROM activos
         WHERE activo = 1
           AND (fecha_inicio_uso IS NULL OR fecha_inicio_uso <= ?)
           AND (estado_vida IS NULL OR estado_vida != 'depreciado')"
    );
    $stmt->execute([$mes_fin]);
    $dep_activos_mensual = (float) $stmt->fetchColumn();
} catch (Exception $e) { /* tabla no disponible */ }

// ── Nómina del período (liquidaciones reales; fallback: salario base) ──────────
$nomina_directa   = 0.0;
$nomina_indirecta = 0.0;
try {
    $stmt = db()->prepare(
        'SELECT
            COALESCE(e.tipo_costo, \'indirecto\') AS tipo_costo,
            COALESCE(SUM(nl.neto_pagado), 0)     AS total
         FROM nomina_liquidaciones nl
         LEFT JOIN empleados e ON e.id = nl.empleado_id
         WHERE nl.periodo_mes = ? AND nl.periodo_anio = ?
         GROUP BY tipo_costo'
    );
    $stmt->execute([$mes, $anio]);
    $rows_nom = $stmt->fetchAll();
    $hay_liquidaciones = false;
    foreach ($rows_nom as $r) {
        $hay_liquidaciones = true;
        if ($r['tipo_costo'] === 'directo') {
            $nomina_directa   = (float)$r['total'];
        } else {
            $nomina_indirecta = (float)$r['total'];
        }
    }
    // Fallback si no hay liquidaciones: usar salario_base mensual
    if (!$hay_liquidaciones) {
        $stmt2 = db()->prepare(
            'SELECT COALESCE(tipo_costo,\'indirecto\') AS tc,
                    COALESCE(SUM(salario_base), 0) AS total
             FROM empleados WHERE activo = 1 GROUP BY tc'
        );
        $stmt2->execute();
        foreach ($stmt2->fetchAll() as $r) {
            if ($r['tc'] === 'directo') { $nomina_directa   = (float)$r['total']; }
            else                        { $nomina_indirecta = (float)$r['total']; }
        }
    }
} catch (Exception $e) { /* tabla no disponible */ }

$nomina_total = $nomina_directa + $nomina_indirecta;
$gran_total   = $total_mensual + $compras_mes + $dep_activos_mensual + $nomina_total;

// ── Catálogos ─────────────────────────────────────────────────────────────────
// Categorías de costos desde la tabla listas_sistema (Admin → Catálogos).
// Fallback hardcodeado si la migración 029 aún no está aplicada.
$_cats_lista = listas_map('categoria_costo');
$CATEGORIAS  = !empty($_cats_lista) ? $_cats_lista : [
    'arriendo'           => 'Arriendo / Alquiler',
    'servicios_publicos' => 'Servicios Públicos',
    'intereses'          => 'Intereses y Financiación',
    'seguros'            => 'Seguros',
    'mantenimiento'      => 'Mantenimiento',
    'publicidad'         => 'Publicidad y Mercadeo',
    'bancario'           => 'Gastos Bancarios',
    'impuestos'          => 'Impuestos y Tasas',
    'administrativo'     => 'Personal Administrativo',
    'otro'               => 'Otro',
];

$FRECUENCIAS = [
    'mensual'    => 'Mensual',
    'bimestral'  => 'Bimestral',
    'trimestral' => 'Trimestral',
    'semestral'  => 'Semestral',
    'anual'      => 'Anual',
];

// Colores de badge por categoría [bg, text]
$CAT_COLOR = [
    'arriendo'           => ['#dbeafe', '#1d4ed8'],
    'servicios_publicos' => ['#fef9c3', '#713f12'],
    'intereses'          => ['#fce7f3', '#9d174d'],
    'seguros'            => ['#d1fae5', '#065f46'],
    'mantenimiento'      => ['#e0e7ff', '#3730a3'],
    'publicidad'         => ['#fef3c7', '#92400e'],
    'bancario'           => ['#f3f4f6', '#374151'],
    'impuestos'          => ['#fee2e2', '#991b1b'],
    'administrativo'     => ['#ecfdf5', '#166534'],
    'otro'               => ['#f9fafb', '#6b7280'],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Costos — <?= APP_NAME ?></title>
    <style>
        :root {
            --brand:  #e94f37;
            --dark:   #111827;
            --g2:     #374151;
            --g5:     #6b7280;
            --g8:     #d1d5db;
            --g9:     #f3f4f6;
            --white:  #fff;
            --green:  #059669;
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: system-ui, -apple-system, sans-serif; background: var(--g9); color: var(--dark); }

        /* ── Layout ── */
        .main       { max-width: 1100px; margin: 0 auto; padding: 20px 14px 60px; }
        .page-hdr   { display: flex; justify-content: space-between; align-items: flex-start;
                      gap: 12px; flex-wrap: wrap; margin-bottom: 22px; }
        .page-title { font-size: 22px; font-weight: 800; color: var(--dark); }
        .page-sub   { font-size: 13px; color: var(--g5); margin-top: 3px; }
        .hdr-btns   { display: flex; gap: 8px; flex-wrap: wrap; }

        /* ── KPI cards ── */
        .kpi-group       { margin-bottom: 22px; }
        .kpi-group-lbl   { font-size: 11px; font-weight: 700; color: var(--g5); text-transform: uppercase;
                           letter-spacing: .6px; margin-bottom: 8px; padding-left: 2px; }
        .kpi-row         { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
                           gap: 10px; }
        .kpi             { background: var(--white); border: 1px solid var(--g8); border-radius: 12px;
                           padding: 14px 16px; }
        .kpi-val         { font-size: 20px; font-weight: 800; color: var(--dark); }
        .kpi-val.brand   { color: var(--brand); }
        .kpi-val.green   { color: var(--green); }
        .kpi-val.yellow  { color: #92400e; }
        .kpi-val.blue    { color: #1d4ed8; }
        .kpi-val.purple  { color: #6d28d9; }
        .kpi-val.teal    { color: #0f766e; }
        .kpi-val.total   { color: var(--brand); font-size: 23px; }
        .kpi-lbl         { font-size: 11px; color: var(--g5); margin-top: 4px; text-transform: uppercase;
                           letter-spacing: .4px; }
        .kpi-sub         { font-size: 11px; color: var(--g5); margin-top: 2px; }
        .kpi.highlight   { border-color: var(--brand); background: #fff8f7; }

        /* ── Toolbar ── */
        .toolbar { display: flex; gap: 8px; align-items: center; flex-wrap: wrap;
                   margin-bottom: 14px; }
        .toolbar input[type=search] {
            flex: 1; min-width: 180px; padding: 8px 12px; border: 1px solid var(--g8);
            border-radius: 8px; font-size: 13px; background: var(--white);
            outline: none; transition: border-color .15s;
        }
        .toolbar input[type=search]:focus { border-color: var(--brand); }
        .filter-sel {
            padding: 8px 10px; border: 1px solid var(--g8); border-radius: 8px;
            font-size: 13px; background: var(--white); outline: none; color: var(--dark);
        }

        /* ── Table ── */
        .tbl-wrap { background: var(--white); border: 1px solid var(--g8); border-radius: 12px;
                    overflow: hidden; }
        .tbl      { width: 100%; border-collapse: collapse; }
        .tbl thead th {
            background: var(--g9); font-size: 11px; font-weight: 700; text-transform: uppercase;
            letter-spacing: .5px; padding: 10px 12px; text-align: left; color: var(--g5);
            white-space: nowrap;
        }
        .tbl tbody tr { border-bottom: 1px solid var(--g9); transition: background .1s; }
        .tbl tbody tr:last-child { border-bottom: none; }
        .tbl tbody tr:hover { background: #fafafa; }
        .tbl tbody tr.pausado td { opacity: .55; }
        .tbl td { padding: 11px 12px; font-size: 13px; vertical-align: middle; }

        /* ── Badges ── */
        .badge { display: inline-block; font-size: 11px; font-weight: 600; padding: 3px 8px;
                 border-radius: 999px; white-space: nowrap; }
        .badge-fijo      { background: #e0f2fe; color: #0369a1; }
        .badge-variable  { background: #fef3c7; color: #92400e; }
        .badge-activo    { background: #d1fae5; color: #065f46; }
        .badge-pausado   { background: #fee2e2; color: #991b1b; }
        .badge-directo   { background: #fef9c3; color: #713f12; }
        .badge-indirecto { background: #dbeafe; color: #1d4ed8; }

        /* ── Amount cells ── */
        .amt     { font-variant-numeric: tabular-nums; font-weight: 600; }
        .amt-men { color: var(--green); font-weight: 700; }

        /* ── Buttons ── */
        .btn-primary {
            background: var(--brand); color: #fff; border: none; padding: 8px 16px;
            border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer;
            white-space: nowrap; transition: background .15s;
        }
        .btn-primary:hover { background: #c94330; }
        .btn-tbl {
            border: none; padding: 5px 10px; border-radius: 6px; font-size: 12px;
            font-weight: 600; cursor: pointer; transition: background .15s; white-space: nowrap;
        }
        .btn-edit   { background: var(--g9); color: var(--dark); }
        .btn-edit:hover   { background: var(--g8); }
        .btn-pause  { background: #fef3c7; color: #92400e; }
        .btn-pause:hover  { background: #fde68a; }
        .btn-resume { background: #d1fae5; color: #065f46; }
        .btn-resume:hover { background: #a7f3d0; }
        .tbl-actions { display: flex; gap: 5px; }

        /* ── Selector de período ── */
        .periodo-bar {
            display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
            background: var(--white); border: 1px solid var(--g8); border-radius: 12px;
            padding: 12px 16px; margin-bottom: 20px;
        }
        .periodo-bar strong { font-size: 13px; color: var(--dark); margin-right: 4px; }
        .periodo-bar select {
            padding: 6px 10px; border: 1px solid var(--g8); border-radius: 8px;
            font-size: 13px; color: var(--dark); background: var(--white); outline: none;
        }
        .periodo-bar select:focus { border-color: var(--brand); }
        .btn-periodo {
            padding: 7px 16px; background: var(--brand); color: #fff; border: none;
            border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer;
        }
        .btn-periodo:hover { background: #c94330; }
        .periodo-lbl {
            margin-left: auto; font-size: 12px; color: var(--g5);
            font-weight: 600;
        }

        /* ── Empty state ── */
        .empty-state { text-align: center; padding: 48px 20px; color: var(--g5); }
        .empty-state p { font-size: 14px; margin-top: 8px; }

        /* ── Modal ── */
        .overlay {
            display: none; position: fixed; inset: 0; background: rgba(0,0,0,.45);
            align-items: flex-start; justify-content: center; padding: 20px;
            z-index: 200; overflow-y: auto;
        }
        .overlay.on { display: flex; }
        .modal {
            background: var(--white); border-radius: 14px; width: 100%; max-width: 620px;
            margin: auto; padding: 24px; box-shadow: 0 20px 60px rgba(0,0,0,.2);
            /* scroll interno si el contenido supera el viewport */
            max-height: 90vh; max-height: 90dvh; overflow-y: auto; -webkit-overflow-scrolling: touch;
        }
        .modal-hdr {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 20px;
        }
        .modal-hdr span { font-size: 17px; font-weight: 700; color: var(--dark); }
        .btn-cls {
            background: var(--g9); border: none; width: 30px; height: 30px; border-radius: 50%;
            font-size: 14px; cursor: pointer; display: flex; align-items: center;
            justify-content: center; color: var(--g5); transition: background .15s; flex-shrink: 0;
        }
        .btn-cls:hover { background: var(--g8); }
        .form-section {
            font-size: 11px; font-weight: 700; color: var(--g5); text-transform: uppercase;
            letter-spacing: .5px; margin: 16px 0 10px; border-bottom: 1px solid var(--g8);
            padding-bottom: 6px;
        }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        @media (max-width: 480px) { .form-grid { grid-template-columns: 1fr; } }
        .fg { display: flex; flex-direction: column; gap: 4px; margin-bottom: 10px; }
        .fg label { font-size: 12px; font-weight: 600; color: var(--g5); }
        .fg input, .fg select, .fg textarea {
            padding: 8px 10px; border: 1px solid var(--g8); border-radius: 8px;
            font-size: 13px; color: var(--dark); background: var(--white);
            transition: border-color .15s; outline: none; font-family: inherit;
        }
        .fg input:focus, .fg select:focus, .fg textarea:focus { border-color: var(--brand); }
        .fg textarea { resize: vertical; min-height: 64px; }
        .fg .hint { font-size: 11px; color: var(--g5); }
        .btn-submit {
            width: 100%; margin-top: 18px; background: var(--brand); color: #fff;
            border: none; padding: 11px; border-radius: 10px; font-size: 14px;
            font-weight: 700; cursor: pointer; transition: background .15s;
        }
        .btn-submit:hover { background: #c94330; }

        /* ── Toast ── */
        .toast {
            position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%) translateY(20px);
            padding: 10px 20px; border-radius: 24px; font-size: 14px; font-weight: 600;
            opacity: 0; transition: .25s; z-index: 999; pointer-events: none; white-space: nowrap;
        }
        .toast.on  { opacity: 1; transform: translateX(-50%) translateY(0); }
        .toast-ok  { background: #065f46; color: #d1fae5; }
        .toast-err { background: #991b1b; color: #fee2e2; }

        /* ════════════════════════════════════════════════════════════════
           RESPONSIVE — COSTOS
           Columnas tabla:
             1=Nombre  2=Categoría  3=Clasificación  4=Frecuencia/Valor
             5=Val.mensual  6=Tipo  7=Vigencia  8=Estado  9=Acciones
           ════════════════════════════════════════════════════════════════ */

        /* — xs: móvil vertical < 480px ─────────────────────────────────
           Ocultar: Categoría(2), Clasificación(3), Tipo(6), Vigencia(7)
           Mantener: Nombre(1), Frec/Valor(4), Val.mensual(5), Estado(8)  */
        @media (max-width:479px) {
            /* Ocultar columnas no esenciales */
            .tbl thead th:nth-child(2), .tbl tbody td:nth-child(2), /* Categoría */
            .tbl thead th:nth-child(3), .tbl tbody td:nth-child(3), /* Clasificación */
            .tbl thead th:nth-child(6), .tbl tbody td:nth-child(6), /* Tipo */
            .tbl thead th:nth-child(7), .tbl tbody td:nth-child(7)  /* Vigencia */
            { display:none !important; }

            /* Scroll horizontal en tabla */
            .tbl-wrap { overflow-x:auto; -webkit-overflow-scrolling:touch; }
            .tbl { min-width:300px; }

            /* KPIs 2 columnas */
            .kpi-row { grid-template-columns: repeat(2,1fr) !important; }
            .kpi { padding:10px 12px !important; }
            .kpi-val { font-size:clamp(15px,4vw,20px) !important; }

            /* Período bar en columna */
            .periodo-bar { flex-direction:column; align-items:stretch; }
            .periodo-bar select { width:100%; }
            .periodo-bar .btn-periodo { width:100%; min-height:44px; }
            .periodo-lbl { margin-left:0 !important; }

            /* Toolbar en columna */
            .toolbar { flex-direction:column; align-items:stretch; }
            .toolbar input[type=search],
            .toolbar .filter-sel { width:100% !important; min-width:unset; }

            /* Botón nuevo full-width */
            .hdr-btns .btn-primary { width:100%; min-height:44px; }

            /* Acciones apiladas */
            .tbl-actions { flex-direction:column; gap:4px; }
            .tbl-actions .btn-tbl { min-height:44px; }

            /* Modal bottom-sheet */
            .overlay { align-items:flex-end !important; padding:0 !important; }
            .modal {
                border-radius:16px 16px 0 0 !important;
                max-height:92vh !important;
                max-width:100% !important;
            }
        }

        /* — sm: móvil landscape 480-639px ──────────────────────────────
           Ocultar: Clasificación(3), Vigencia(7)                        */
        @media (min-width:480px) and (max-width:639px) {
            .tbl thead th:nth-child(3), .tbl tbody td:nth-child(3), /* Clasificación */
            .tbl thead th:nth-child(7), .tbl tbody td:nth-child(7)  /* Vigencia */
            { display:none !important; }

            .tbl-wrap { overflow-x:auto; -webkit-overflow-scrolling:touch; }
            .tbl { min-width:420px; }
            .kpi-row { grid-template-columns: repeat(2,1fr) !important; }

            /* Reemplaza la regla legacy de 700px para este rango */
            .tbl thead th:nth-child(5), .tbl tbody td:nth-child(5) { display:table-cell !important; }
        }

        /* — md: tablet 640-1023px ───────────────────────────────────────
           Mostrar todas las columnas principales; scroll si desborda     */
        @media (min-width:640px) and (max-width:1023px) {
            /* Anular la regla legacy de 700px */
            .tbl thead th:nth-child(3), .tbl tbody td:nth-child(3),
            .tbl thead th:nth-child(5), .tbl tbody td:nth-child(5)
            { display:table-cell !important; }

            .tbl-wrap { overflow-x:auto; -webkit-overflow-scrolling:touch; }

            /* Modal centrado en tablet */
            .overlay { align-items:center !important; padding:20px !important; }
            .modal { border-radius:14px !important; max-width:560px !important; }
        }

        /* — lg+: escritorio ≥1024px — mostrar todo ────────────────────── */
        @media (min-width:1024px) {
            .tbl thead th, .tbl tbody td { display:table-cell !important; }
        }

        /* — 2xl: pantalla grande ≥1600px ───────────────────────────────── */
        @media (min-width:1600px) {
            .kpi { padding:18px 22px !important; }
            .kpi-val { font-size:clamp(20px,2vw,26px) !important; }
            .tbl thead th, .tbl td { padding:12px 14px !important; font-size:14px !important; }
            .modal { max-width:700px !important; padding:30px !important; }
        }

        /* — tv: televisor ≥1920px ────────────────────────────────────── */
        @media (min-width:1920px) {
            .kpi-row { grid-template-columns: repeat(4,1fr) !important; }
            .kpi-val { font-size:clamp(24px,2vw,32px) !important; }
            .tbl thead th, .tbl td { padding:14px 18px !important; font-size:15px !important; }
            .modal { max-width:800px !important; }
        }
    </style>
</head>
<body>
<?php $nav_activo = 'costos'; include __DIR__ . '/../app/views/nav.php'; ?>

<main class="main">

    <!-- ── Encabezado ──────────────────────────────────────────────────── -->
    <div class="page-hdr">
        <div>
            <h1 class="page-title">Costos</h1>
            <p class="page-sub">Arriendo, servicios, intereses y demás costos del negocio</p>
        </div>
        <div class="hdr-btns">
            <?php if (permiso_tiene('costos', 'editar_existentes')): ?>
            <button class="btn-primary" onclick="abrirNuevo()">+ Nuevo Costo</button>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Selector de período ────────────────────────────────────────── -->
    <form method="GET" class="periodo-bar">
        <strong>Período:</strong>
        <select name="mes">
            <?php foreach ($meses_nombres as $n => $lbl): ?>
            <option value="<?= $n ?>" <?= $n === $mes ? 'selected' : '' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
        </select>
        <select name="anio">
            <?php for ($y = 2020; $y <= (int)date('Y') + 1; $y++): ?>
            <option value="<?= $y ?>" <?= $y === $anio ? 'selected' : '' ?>><?= $y ?></option>
            <?php endfor; ?>
        </select>
        <button type="submit" class="btn-periodo">Ver</button>
        <span class="periodo-lbl">
            Mostrando: <?= $meses_nombres[$mes] ?> <?= $anio ?>
        </span>
    </form>

    <!-- ── KPIs ────────────────────────────────────────────────────────── -->

    <!-- Fila 1: Costos registrados en el período -->
    <div class="kpi-group">
        <div class="kpi-group-lbl">Costos — <?= $meses_nombres[$mes] ?> <?= $anio ?></div>
        <div class="kpi-row">
            <div class="kpi">
                <div class="kpi-val brand">$<?= fmt_moneda($total_mensual) ?></div>
                <div class="kpi-lbl">Total costos</div>
                <div class="kpi-sub"><?= $n_activos ?> activo<?= $n_activos !== 1 ? 's' : '' ?>, <?= $n_pausados ?> pausado<?= $n_pausados !== 1 ? 's' : '' ?></div>
            </div>
            <div class="kpi">
                <div class="kpi-val yellow">$<?= fmt_moneda($total_directo) ?></div>
                <div class="kpi-lbl">Costos directos</div>
                <div class="kpi-sub">Trazables a producción</div>
            </div>
            <div class="kpi">
                <div class="kpi-val blue">$<?= fmt_moneda($total_indirecto) ?></div>
                <div class="kpi-lbl">Costos indirectos</div>
                <div class="kpi-sub">Generales del negocio</div>
            </div>
            <div class="kpi">
                <div class="kpi-val teal">$<?= fmt_moneda($compras_mes) ?></div>
                <div class="kpi-lbl">Compras</div>
                <div class="kpi-sub">Módulo Compras</div>
            </div>
        </div>
    </div>

    <!-- Fila 2: Activos y nómina del período -->
    <div class="kpi-group">
        <div class="kpi-group-lbl">Activos y nómina — <?= $meses_nombres[$mes] ?> <?= $anio ?></div>
        <div class="kpi-row">
            <div class="kpi">
                <div class="kpi-val purple">$<?= fmt_moneda($dep_activos_mensual) ?></div>
                <div class="kpi-lbl">Depreciación activos</div>
                <div class="kpi-sub">Módulo Activos</div>
            </div>
            <div class="kpi">
                <div class="kpi-val yellow">$<?= fmt_moneda($nomina_directa) ?></div>
                <div class="kpi-lbl">Nómina directa</div>
                <div class="kpi-sub">Empleados que producen</div>
            </div>
            <div class="kpi">
                <div class="kpi-val blue">$<?= fmt_moneda($nomina_indirecta) ?></div>
                <div class="kpi-lbl">Nómina indirecta</div>
                <div class="kpi-sub">Admin / soporte</div>
            </div>
            <div class="kpi highlight">
                <div class="kpi-val total">$<?= fmt_moneda($gran_total) ?></div>
                <div class="kpi-lbl">Gran total del período</div>
                <div class="kpi-sub">Costos + compras + activos + nómina</div>
            </div>
        </div>
    </div>

    <!-- ── Toolbar ─────────────────────────────────────────────────────── -->
    <div class="toolbar">
        <input type="search" id="filtro-texto" placeholder="Buscar por nombre o categoría…"
               oninput="filtrar()">
        <select class="filter-sel" id="filtro-cat" onchange="filtrar()">
            <option value="">Todas las categorías</option>
            <?php foreach ($CATEGORIAS as $k => $lbl): ?>
            <option value="<?= $k ?>"><?= htmlspecialchars($lbl) ?></option>
            <?php endforeach; ?>
        </select>
        <select class="filter-sel" id="filtro-clasif" onchange="filtrar()">
            <option value="">Directo e indirecto</option>
            <option value="directo">Solo directos</option>
            <option value="indirecto">Solo indirectos</option>
        </select>
        <?php if (filtro_estado_es_admin()): ?>
        <?= filtro_estado_ui($ver) ?>
        <?php endif; ?>
    </div>

    <!-- ── Tabla ───────────────────────────────────────────────────────── -->
    <div class="tbl-wrap rcards-wrap">
        <?php if (empty($costos)): ?>
        <div class="empty-state">
            <strong>Sin costos para <?= $meses_nombres[$mes] ?> <?= $anio ?></strong>
            <p>No hay costos vigentes en este período. Ajusta las fechas de un costo existente o agrega uno nuevo.</p>
        </div>
        <?php else: ?>
        <table class="tbl rcards" id="tabla-costos">
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th>Categoría</th>
                    <th>Clasificación</th>
                    <th>Frecuencia / Valor</th>
                    <th>Val. mensual</th>
                    <th>Tipo</th>
                    <th>Vigencia</th>
                    <th>Estado</th>
                    <?php if (permiso_tiene('costos', 'editar_existentes')): ?>
                    <th></th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($costos as $c):
                // Filtro de estado en servidor (admin elige; no-admin solo ve activos)
                if ($ver === 'activos'   && !(int)$c['activo']) continue;
                if ($ver === 'inactivos' &&  (int)$c['activo']) continue;
                $catLbl   = $CATEGORIAS[$c['categoria']]   ?? ucfirst($c['categoria']);
                $catCol   = $CAT_COLOR[$c['categoria']]    ?? ['#f9fafb', '#6b7280'];
                $frecLbl  = $FRECUENCIAS[$c['frecuencia']] ?? $c['frecuencia'];
                $clasif   = $c['clasificacion'] ?? 'indirecto';
                $esActivo = (bool) $c['activo'];
            ?>
                <tr class="fila<?= $esActivo ? '' : ' pausado' ?>"
                    data-nombre="<?= htmlspecialchars(strtolower($c['nombre'])) ?>"
                    data-cat="<?= htmlspecialchars($c['categoria']) ?>"
                    data-clasif="<?= htmlspecialchars($clasif) ?>"
                    data-activo="<?= $c['activo'] ?>">
                    <td>
                        <strong><?= htmlspecialchars($c['nombre']) ?></strong>
                        <?php if (!empty($c['descripcion'])): ?>
                        <br><small style="color:var(--g5)"><?= htmlspecialchars($c['descripcion']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td data-label="Categoría">
                        <span class="badge"
                              style="background:<?= $catCol[0] ?>;color:<?= $catCol[1] ?>">
                            <?= htmlspecialchars($catLbl) ?>
                        </span>
                    </td>
                    <td data-label="Clasificación">
                        <span class="badge badge-<?= $clasif ?>">
                            <?= $clasif === 'directo' ? 'Directo' : 'Indirecto' ?>
                        </span>
                    </td>
                    <td data-label="Frecuencia / Valor">
                        <span style="color:var(--g5);font-size:11px"><?= $frecLbl ?></span><br>
                        <span class="amt">$<?= fmt_moneda($c['valor']) ?></span>
                    </td>
                    <td data-label="Val. mensual">
                        <span class="amt amt-men">
                            $<?= fmt_moneda($c['valor_mensual']) ?>
                        </span>
                    </td>
                    <td data-label="Tipo">
                        <span class="badge badge-<?= htmlspecialchars($c['tipo']) ?>">
                            <?= $c['tipo'] === 'fijo' ? 'Fijo' : 'Variable' ?>
                        </span>
                    </td>
                    <td data-label="Vigencia" style="font-size:12px;color:var(--g5)">
                        <?php if (!empty($c['fecha_fin'])): ?>
                            Hasta <?= date('d/m/Y', strtotime($c['fecha_fin'])) ?>
                        <?php else: ?>
                            Vigente
                        <?php endif; ?>
                    </td>
                    <td data-label="Estado">
                        <span class="badge badge-<?= $esActivo ? 'activo' : 'pausado' ?>">
                            <?= $esActivo ? 'Activo' : 'Pausado' ?>
                        </span>
                    </td>
                    <?php if (permiso_tiene('costos', 'editar_existentes')): ?>
                    <td class="acc-cell">
                        <div class="tbl-actions">
                            <button class="btn-tbl ic ic-edit" title="Editar"
                                    onclick="abrirEditar(<?= htmlspecialchars(json_encode($c)) ?>)">
                                <?= IC_EDIT ?>
                            </button>
                            <button class="btn-tbl ic <?= $esActivo ? 'ic-warn' : 'ic-ok' ?>"
                                    title="<?= $esActivo ? 'Pausar' : 'Activar' ?>"
                                    onclick="toggleCosto(<?= $c['id'] ?>, <?= (int)$esActivo ?>)">
                                <?= $esActivo ? IC_PAUSE : IC_PLAY ?>
                            </button>
                        </div>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

</main>

<!-- ── CSRF ────────────────────────────────────────────────────────────── -->
<input type="hidden" id="csrf-tk" value="<?= htmlspecialchars(csrf_token()) ?>">

<!-- ── Modal Nuevo / Editar ────────────────────────────────────────────── -->
<?php if (permiso_tiene('costos', 'editar_existentes')): ?>
<div class="overlay" id="modal-costo" onclick="if(event.target===this)cerrar()">
  <div class="modal">
    <div class="modal-hdr">
      <span id="mc-titulo">Nuevo Costo</span>
      <button class="btn-cls" onclick="cerrar()">&#x2715;</button>
    </div>
    <input type="hidden" id="mc-id">

    <p class="form-section">Identificación</p>
    <div class="fg"><label>Nombre del costo *</label>
      <input id="mc-nombre" placeholder="Ej: Arriendo local, Factura de energía…" maxlength="200"></div>

    <div class="form-grid">
      <div class="fg"><label>Categoría *</label>
        <select id="mc-categoria">
          <?php foreach ($CATEGORIAS as $k => $lbl): ?>
          <option value="<?= $k ?>"><?= htmlspecialchars($lbl) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="fg"><label>Clasificación *</label>
        <select id="mc-clasificacion">
          <option value="indirecto">Indirecto — costo general del negocio</option>
          <option value="directo">Directo — trazable a un producto</option>
        </select></div>
    </div>

    <p class="form-section">Valor y Periodicidad</p>
    <div class="form-grid">
      <div class="fg"><label>Tipo *</label>
        <select id="mc-tipo">
          <option value="fijo">Fijo — siempre el mismo monto</option>
          <option value="variable">Variable — monto estimado promedio</option>
        </select></div>
      <div class="fg"><label>Frecuencia de pago *</label>
        <select id="mc-frecuencia">
          <?php foreach ($FRECUENCIAS as $k => $lbl): ?>
          <option value="<?= $k ?>"><?= htmlspecialchars($lbl) ?></option>
          <?php endforeach; ?>
        </select></div>
    </div>
    <div class="fg"><label>Monto ($) *</label>
      <input id="mc-valor" type="number" step="1" min="0" placeholder="0">
      <span class="hint" id="mc-hint-mensual">Monto que se paga por período</span></div>

    <p class="form-section">Vigencia</p>
    <div class="form-grid">
      <div class="fg"><label>Fecha de inicio *</label>
        <input id="mc-inicio" type="date"></div>
      <div class="fg"><label>Fecha de fin</label>
        <input id="mc-fin" type="date">
        <span class="hint">Dejar vacío si el costo es indefinido</span></div>
    </div>

    <div class="fg"><label>Descripción / Notas</label>
      <textarea id="mc-desc" placeholder="Detalle adicional, número de contrato, etc."></textarea></div>

    <button class="btn-submit" onclick="guardar()">Guardar Costo</button>
  </div>
</div>
<?php endif; ?>

<!-- ── Toast ───────────────────────────────────────────────────────────── -->
<div class="toast" id="toast"></div>

<script>
const FREC_MESES = {mensual:1, bimestral:2, trimestral:3, semestral:6, anual:12};

var _tt;
function toast(m, t) {
    var el = document.getElementById('toast');
    el.textContent = m;
    el.className = 'toast toast-' + t + ' on';
    clearTimeout(_tt);
    _tt = setTimeout(function(){ el.classList.remove('on'); }, 3200);
}
var csrf = function(){ return document.getElementById('csrf-tk').value; };

function cerrar() { document.getElementById('modal-costo').classList.remove('on'); }
document.addEventListener('keydown', function(e){ if (e.key==='Escape') cerrar(); });

/* ── Filtro de tabla ─────────────────────────────────────────────────── */
function filtrar() {
    var txt    = document.getElementById('filtro-texto').value.toLowerCase().trim();
    var cat    = document.getElementById('filtro-cat').value;
    var clasif = document.getElementById('filtro-clasif').value;
    // activo/pausado lo filtra el servidor (selector admin)
    document.querySelectorAll('#tabla-costos tbody tr.fila').forEach(function(tr){
        var ok = true;
        if (txt    && tr.dataset.nombre.indexOf(txt)  === -1
                   && tr.dataset.cat.indexOf(txt)     === -1) ok = false;
        if (cat    && tr.dataset.cat    !== cat)              ok = false;
        if (clasif && tr.dataset.clasif !== clasif)           ok = false;
        tr.style.display = ok ? '' : 'none';
    });
}

/* ── Hint equivalente mensual ────────────────────────────────────────── */
function actualizarHint() {
    var val  = parseFloat(document.getElementById('mc-valor').value) || 0;
    var frec = document.getElementById('mc-frecuencia').value;
    var mens = val / (FREC_MESES[frec] || 1);
    var hint = document.getElementById('mc-hint-mensual');
    if (hint) hint.textContent = frec !== 'mensual' && val > 0
        ? '= $' + formatMiles(mens) + ' / mes'
        : 'Monto que se paga por período';
}
document.getElementById('mc-valor')      && document.getElementById('mc-valor').addEventListener('input', actualizarHint);
document.getElementById('mc-frecuencia') && document.getElementById('mc-frecuencia').addEventListener('change', actualizarHint);

/* ── Abrir modal NUEVO ───────────────────────────────────────────────── */
function abrirNuevo() {
    document.getElementById('mc-titulo').textContent  = 'Nuevo Costo';
    document.getElementById('mc-id').value            = '';
    document.getElementById('mc-nombre').value        = '';
    document.getElementById('mc-categoria').value     = 'otro';
    document.getElementById('mc-clasificacion').value = 'indirecto';
    document.getElementById('mc-tipo').value          = 'fijo';
    document.getElementById('mc-frecuencia').value    = 'mensual';
    document.getElementById('mc-valor').value         = '';
    document.getElementById('mc-inicio').value        = new Date().toISOString().slice(0,10);
    document.getElementById('mc-fin').value           = '';
    document.getElementById('mc-desc').value          = '';
    actualizarHint();
    document.getElementById('modal-costo').classList.add('on');
    setTimeout(function(){ document.getElementById('mc-nombre').focus(); }, 100);
}

/* ── Abrir modal EDITAR ──────────────────────────────────────────────── */
function abrirEditar(c) {
    document.getElementById('mc-titulo').textContent  = 'Editar: ' + c.nombre;
    document.getElementById('mc-id').value            = c.id;
    document.getElementById('mc-nombre').value        = c.nombre           || '';
    document.getElementById('mc-categoria').value     = c.categoria        || 'otro';
    document.getElementById('mc-clasificacion').value = c.clasificacion    || 'indirecto';
    document.getElementById('mc-tipo').value          = c.tipo             || 'fijo';
    document.getElementById('mc-frecuencia').value    = c.frecuencia       || 'mensual';
    document.getElementById('mc-valor').value         = c.valor            || '';
    document.getElementById('mc-inicio').value        = c.fecha_inicio     || '';
    document.getElementById('mc-fin').value           = c.fecha_fin        || '';
    document.getElementById('mc-desc').value          = c.descripcion      || '';
    actualizarHint();
    document.getElementById('modal-costo').classList.add('on');
}

/* ── Guardar (crear o editar) ────────────────────────────────────────── */
async function guardar() {
    var nombre = document.getElementById('mc-nombre').value.trim();
    var valor  = document.getElementById('mc-valor').value;
    var inicio = document.getElementById('mc-inicio').value;

    if (!nombre)      { toast('El nombre es obligatorio.', 'err');         return; }
    if (valor === '')  { toast('Ingresa el valor del costo.', 'err');       return; }
    if (!inicio)      { toast('La fecha de inicio es obligatoria.', 'err'); return; }

    var id = document.getElementById('mc-id').value;
    var fd = new FormData();
    fd.append('csrf_token',    csrf());
    fd.append('accion',        id ? 'editar' : 'crear');
    if (id) fd.append('id',    id);
    fd.append('nombre',        nombre);
    fd.append('categoria',     document.getElementById('mc-categoria').value);
    fd.append('clasificacion', document.getElementById('mc-clasificacion').value);
    fd.append('tipo',          document.getElementById('mc-tipo').value);
    fd.append('frecuencia',    document.getElementById('mc-frecuencia').value);
    fd.append('valor',         valor);
    fd.append('fecha_inicio',  inicio);
    fd.append('fecha_fin',     document.getElementById('mc-fin').value);
    fd.append('descripcion',   document.getElementById('mc-desc').value);

    try {
        var r = await fetch('api/crud.php', {method:'POST', body:fd});
        var d = await r.json();
        if (d.success) {
            cerrar();
            toast(id ? 'Costo actualizado.' : 'Costo registrado.', 'ok');
            setTimeout(function(){ location.reload(); }, 900);
        } else {
            toast(d.error || 'Error al guardar.', 'err');
        }
    } catch(e) { toast('Error de conexión.', 'err'); }
}

/* ── Toggle pausar / activar ─────────────────────────────────────────── */
async function toggleCosto(id, esActivo) {
    if (!confirm(esActivo
        ? '¿Pausar este costo? Dejará de sumarse al total mensual.'
        : '¿Activar este costo?')) return;

    var fd = new FormData();
    fd.append('csrf_token', csrf());
    fd.append('accion', 'toggle');
    fd.append('id', id);

    try {
        var r = await fetch('api/crud.php', {method:'POST', body:fd});
        var d = await r.json();
        if (d.success) {
            toast(d.activo ? 'Costo activado.' : 'Costo pausado.', 'ok');
            setTimeout(function(){ location.reload(); }, 700);
        } else { toast(d.error || 'Error.', 'err'); }
    } catch(e) { toast('Error de conexión.', 'err'); }
}
</script>
</body>
</html>
