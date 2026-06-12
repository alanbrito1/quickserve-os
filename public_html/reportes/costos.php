<?php
/**
 * reportes/costos.php — Reporte integral de costos del negocio.
 *
 * Consolida en un solo informe:
 *   - Costos registrados en el módulo Costos (directos e indirectos)
 *   - Compras del período (materia prima)
 *   - Depreciación de activos
 *   - Nómina por clasificación (directa vs indirecta)
 *   - Gran total mensual del período
 *
 * Exporta a Excel con 3 hojas: Resumen, Detalle Costos, Desglose.
 */
require_once __DIR__ . '/../app/middleware/auth_check.php';
require_once __DIR__ . '/../app/helpers/XlsxWriter.php';

$nav_activo = 'reportes';
permiso_requerir('costos', 'solo_ver');

// ── Período seleccionado ──────────────────────────────────────────────────────
$hoy         = date('Y-m-d');
$mes         = max(1, min(12, (int)($_GET['mes']  ?? date('n'))));
$anio        = (int)($_GET['anio'] ?? date('Y'));
$anio        = max(2020, min((int)date('Y') + 1, $anio));
$mes_inicio  = sprintf('%04d-%02d-01', $anio, $mes);
$mes_fin     = date('Y-m-t', strtotime($mes_inicio));

$meses_es = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',
             7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];

$FREC_DIV = ['mensual'=>1,'bimestral'=>2,'trimestral'=>3,'semestral'=>6,'anual'=>12];

// ── Costos registrados en el módulo ─────────────────────────────────────────
$costos_periodo = db()->prepare(
    "SELECT nombre, categoria, clasificacion, tipo, frecuencia, valor,
            CASE frecuencia
                WHEN 'mensual'    THEN valor
                WHEN 'bimestral'  THEN valor / 2
                WHEN 'trimestral' THEN valor / 3
                WHEN 'semestral'  THEN valor / 6
                WHEN 'anual'      THEN valor / 12
                ELSE valor
            END AS valor_mensual
     FROM costos_indirectos
     WHERE activo = 1
       AND fecha_inicio <= :fin
       AND (fecha_fin IS NULL OR fecha_fin >= :ini)
     ORDER BY clasificacion, categoria, nombre"
);
$costos_periodo->execute([':ini' => $mes_inicio, ':fin' => $mes_fin]);
$costos_rows = $costos_periodo->fetchAll();

$total_costos_directos   = 0.0;
$total_costos_indirectos = 0.0;
foreach ($costos_rows as $c) {
    $vm = (float)$c['valor_mensual'];
    if ($c['clasificacion'] === 'directo') $total_costos_directos   += $vm;
    else                                   $total_costos_indirectos += $vm;
}
$total_costos = $total_costos_directos + $total_costos_indirectos;

// ── Compras del período ───────────────────────────────────────────────────────
$stmt_compras = db()->prepare(
    'SELECT COALESCE(SUM(c.total),0) AS total_compras,
            COUNT(*) AS n_compras
     FROM compras c
     WHERE c.fecha_compra BETWEEN :ini AND :fin'
);
$stmt_compras->execute([':ini' => $mes_inicio . ' 00:00:00', ':fin' => $mes_fin . ' 23:59:59']);
$row_compras = $stmt_compras->fetch();
$total_compras = (float)($row_compras['total_compras'] ?? 0);
$n_compras     = (int)($row_compras['n_compras'] ?? 0);

// Compras desglosadas por proveedor
$compras_detalle = db()->prepare(
    "SELECT IFNULL(p.nombre,'Sin proveedor') AS proveedor,
            SUM(c.total) AS subtotal,
            COUNT(*)     AS n
     FROM compras c
     LEFT JOIN proveedores p ON p.id = c.proveedor_id
     WHERE c.fecha_compra BETWEEN :ini AND :fin
     GROUP BY proveedor ORDER BY subtotal DESC"
);
$compras_detalle->execute([':ini' => $mes_inicio . ' 00:00:00', ':fin' => $mes_fin . ' 23:59:59']);
$compras_por_proveedor = $compras_detalle->fetchAll();

// ── Depreciación de activos ───────────────────────────────────────────────────
$stmt_dep = db()->prepare(
    "SELECT COALESCE(SUM(depreciacion_mensual), 0) AS dep_total,
            COUNT(*) AS n_activos
     FROM activos
     WHERE activo = 1
       AND estado_vida != 'depreciado'
       AND (fecha_inicio_uso IS NULL OR fecha_inicio_uso <= :fin)"
);
$stmt_dep->execute([':fin' => $mes_fin]);
$row_dep      = $stmt_dep->fetch();
$dep_mensual  = (float)($row_dep['dep_total'] ?? 0);
$n_activos    = (int)($row_dep['n_activos'] ?? 0);

// ── Nómina del período ────────────────────────────────────────────────────────
// Intenta usar liquidaciones reales; si no hay, usa salario base
$nomina_directa   = 0.0;
$nomina_indirecta = 0.0;
$nomina_fuente    = 'estimado'; // o 'liquidaciones'

$stmt_nom = db()->prepare(
    "SELECT COALESCE(e.tipo_costo,'indirecto') AS tipo_costo,
            COALESCE(SUM(nl.neto_pagado), 0)   AS total
     FROM nomina_liquidaciones nl
     LEFT JOIN empleados e ON e.id = nl.empleado_id
     WHERE nl.periodo_mes = :mes AND nl.periodo_anio = :anio
     GROUP BY tipo_costo"
);
$stmt_nom->execute([':mes' => $mes, ':anio' => $anio]);
$nom_rows = $stmt_nom->fetchAll();

if (!empty($nom_rows)) {
    $nomina_fuente = 'liquidaciones';
    foreach ($nom_rows as $r) {
        if ($r['tipo_costo'] === 'directo') $nomina_directa   = (float)$r['total'];
        else                                $nomina_indirecta = (float)$r['total'];
    }
} else {
    // Fallback: salario_base actual de empleados activos
    $stmt_emp = db()->prepare(
        "SELECT COALESCE(tipo_costo,'indirecto') AS tc,
                COALESCE(SUM(salario_base), 0)    AS total
         FROM empleados WHERE activo = 1 GROUP BY tc"
    );
    $stmt_emp->execute();
    foreach ($stmt_emp->fetchAll() as $r) {
        if ($r['tc'] === 'directo') $nomina_directa   = (float)$r['total'];
        else                        $nomina_indirecta = (float)$r['total'];
    }
}
$nomina_total = $nomina_directa + $nomina_indirecta;

// ── Gran total ────────────────────────────────────────────────────────────────
$gran_total = $total_costos + $total_compras + $dep_mensual + $nomina_total;

// ── Exportar a Excel ──────────────────────────────────────────────────────────
if ($_GET['export'] ?? '' === '1') {
    require_once __DIR__ . '/../app/helpers/XlsxWriter.php';
    $w = new XlsxWriter();

    // Hoja 1: Resumen ejecutivo
    $w->setSheet('Resumen');
    $w->addRow(['REPORTE DE COSTOS — ' . strtoupper($meses_es[$mes]) . ' ' . $anio], header: true);
    $w->addRow([]);
    $w->addRow(['Concepto', 'Monto ($)', '% del total'], header: true);
    $items = [
        ['Costos directos (módulo)',       $total_costos_directos],
        ['Costos indirectos (módulo)',      $total_costos_indirectos],
        ['Compras (materia prima)',         $total_compras],
        ['Depreciación activos',            $dep_mensual],
        ['Nómina directa',                 $nomina_directa],
        ['Nómina indirecta',               $nomina_indirecta],
    ];
    foreach ($items as [$label, $monto]) {
        $pct = $gran_total > 0 ? round($monto / $gran_total * 100, 1) : 0;
        $w->addRow([$label, round($monto), $pct . '%']);
    }
    $w->addRow(['GRAN TOTAL', round($gran_total), '100%'], total: true);

    // Hoja 2: Detalle de costos registrados
    $w->setSheet('Costos Registrados');
    $w->addRow(['Nombre', 'Categoría', 'Clasificación', 'Tipo', 'Frecuencia', 'Valor período', 'Equiv. mensual'], header: true);
    foreach ($costos_rows as $c) {
        $w->addRow([
            $c['nombre'],
            ucfirst(str_replace('_',' ',$c['categoria'])),
            ucfirst($c['clasificacion']),
            ucfirst($c['tipo']),
            ucfirst($c['frecuencia']),
            round((float)$c['valor']),
            round((float)$c['valor_mensual']),
        ]);
    }
    $w->addRow(['TOTAL', '', '', '', '', '', round($total_costos)], total: true);

    // Hoja 3: Compras por proveedor
    $w->setSheet('Compras por Proveedor');
    $w->addRow(['Proveedor', 'N° compras', 'Total ($)'], header: true);
    foreach ($compras_por_proveedor as $r) {
        $w->addRow([$r['proveedor'], (int)$r['n'], round((float)$r['subtotal'])]);
    }
    $w->addRow(['TOTAL', $n_compras, round($total_compras)], total: true);

    $w->download('ClanDestino_Costos_' . $meses_es[$mes] . '_' . $anio . '.xlsx');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reporte Costos — <?= APP_NAME ?></title>
    <style>
        :root { --brand:#e94f37; --dark:#111827; --g5:#6b7280; --g8:#d1d5db; --g9:#f3f4f6; --white:#fff; --green:#059669; }
        *,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
        body { font-family:system-ui,-apple-system,sans-serif; background:var(--g9); color:var(--dark); }
        .main { max-width:1000px; margin:0 auto; padding:20px 14px 60px; }
        .page-hdr { display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:10px; margin-bottom:20px; }
        .page-title { font-size:22px; font-weight:800; }
        .page-sub   { font-size:13px; color:var(--g5); margin-top:3px; }
        .filtros { display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end; background:var(--white); border:1px solid var(--g8); border-radius:12px; padding:12px 16px; margin-bottom:18px; }
        .fg { display:flex; flex-direction:column; gap:3px; }
        .fg label { font-size:11px; font-weight:700; color:var(--g5); text-transform:uppercase; }
        .fg select { padding:7px 10px; border:1px solid var(--g8); border-radius:8px; font-size:13px; color:var(--dark); outline:none; }
        .fg select:focus { border-color:var(--brand); }
        .btn-ver { padding:8px 16px; background:var(--brand); color:#fff; border:none; border-radius:8px; font-size:13px; font-weight:700; cursor:pointer; align-self:flex-end; }
        .btn-excel { padding:8px 16px; background:#16a34a; color:#fff; border:none; border-radius:8px; font-size:13px; font-weight:700; cursor:pointer; text-decoration:none; display:inline-block; }

        /* KPIs */
        .kpi-row { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:10px; margin-bottom:18px; }
        .kpi { background:var(--white); border:1px solid var(--g8); border-radius:12px; padding:14px 16px; }
        .kpi-val { font-size:19px; font-weight:800; }
        .kpi-lbl { font-size:11px; color:var(--g5); margin-top:3px; text-transform:uppercase; letter-spacing:.4px; }
        .kpi-sub { font-size:11px; color:var(--g5); margin-top:2px; }
        .kpi.highlight { border-color:var(--brand); background:#fff8f7; }
        .kpi-val.brand { color:var(--brand); }
        .kpi-val.green { color:var(--green); }
        .kpi-val.yellow { color:#d97706; }
        .kpi-val.purple { color:#6d28d9; }
        .kpi-val.blue   { color:#1d4ed8; }

        /* Secciones */
        .section-title { font-size:13px; font-weight:700; color:var(--g5); text-transform:uppercase; letter-spacing:.5px; margin:20px 0 10px; }
        .tbl-wrap { background:var(--white); border:1px solid var(--g8); border-radius:12px; overflow:hidden; overflow-x:auto; margin-bottom:16px; }
        .tbl { width:100%; border-collapse:collapse; }
        .tbl thead th { background:var(--g9); font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; padding:9px 12px; text-align:left; color:var(--g5); }
        .tbl th.r,.tbl td.r { text-align:right; }
        .tbl tbody tr { border-bottom:1px solid var(--g9); }
        .tbl tbody tr:last-child { border-bottom:none; }
        .tbl tbody tr.total-row td { font-weight:700; background:var(--g9); }
        .tbl td { padding:9px 12px; font-size:13px; }
        .badge { display:inline-block; font-size:11px; font-weight:600; padding:2px 7px; border-radius:999px; }
        .badge-directo   { background:#fef9c3; color:#713f12; }
        .badge-indirecto { background:#dbeafe; color:#1d4ed8; }
        .nota { font-size:12px; color:var(--g5); margin-top:6px; }

        /* Barra de progreso */
        .bar-wrap { background:var(--g9); border-radius:4px; height:8px; margin-top:4px; }
        .bar-fill  { height:8px; border-radius:4px; background:var(--brand); }

        /* ════════════════════════════════════════════════════════════════
           RESPONSIVE — REPORTE COSTOS
           ════════════════════════════════════════════════════════════════ */
        /* Tablas con scroll horizontal */
        .tbl-wrap { overflow-x:auto; -webkit-overflow-scrolling:touch; }

        /* xs: < 480px */
        @media (max-width:479px) {
            .main { padding:12px 10px 40px; }
            /* Encabezado en columna */
            .page-hdr { flex-direction:column; }
            .btn-excel { width:100%; min-height:44px; text-align:center; display:block; }
            /* Filtros en columna */
            .filtros { flex-direction:column; align-items:stretch; }
            .fg select { width:100%; }
            .btn-ver { width:100%; min-height:44px; align-self:auto; }
            /* KPIs 2 cols */
            .kpi-row { grid-template-columns:1fr 1fr !important; gap:8px; }
            .kpi-val { font-size:15px !important; }
            /* Tabla min-width */
            .tbl { min-width:380px; }
        }
        /* sm: 480-639px */
        @media (min-width:480px) and (max-width:639px) {
            .kpi-row { grid-template-columns:repeat(2,1fr) !important; }
        }
        /* ≥1600px */
        @media (min-width:1600px) {
            .main { max-width:1300px; }
            .kpi-val { font-size:22px !important; }
            .tbl thead th, .tbl td { padding:12px 14px !important; font-size:14px !important; }
        }
        /* TV ≥1920px */
        @media (min-width:1920px) {
            .main { max-width:1600px; }
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/../app/views/nav.php'; ?>
<main class="main">

    <div class="page-hdr">
        <div>
            <h1 class="page-title">Reporte de Costos</h1>
            <p class="page-sub"><?= $meses_es[$mes] ?> <?= $anio ?> · Todos los costos del negocio consolidados</p>
        </div>
        <a href="?mes=<?= $mes ?>&anio=<?= $anio ?>&export=1" class="btn-excel">
            Exportar Excel
        </a>
    </div>

    <!-- Filtros -->
    <form method="GET" class="filtros">
        <div class="fg"><label>Mes</label>
            <select name="mes">
                <?php foreach ($meses_es as $n => $nm): ?>
                <option value="<?= $n ?>" <?= $n===$mes?'selected':''?>><?= $nm ?></option>
                <?php endforeach; ?>
            </select></div>
        <div class="fg"><label>Año</label>
            <select name="anio">
                <?php for($y=2024;$y<=(int)date('Y')+1;$y++): ?>
                <option value="<?= $y ?>" <?= $y===$anio?'selected':''?>><?= $y ?></option>
                <?php endfor; ?>
            </select></div>
        <button type="submit" class="btn-ver">Ver período</button>
    </form>

    <!-- KPIs del período -->
    <p class="section-title">Resumen del período</p>
    <div class="kpi-row">
        <div class="kpi">
            <div class="kpi-val yellow">$<?= fmt_moneda($total_costos_directos) ?></div>
            <div class="kpi-lbl">Costos directos</div>
            <div class="kpi-sub">Trazables a producción</div>
        </div>
        <div class="kpi">
            <div class="kpi-val blue">$<?= fmt_moneda($total_costos_indirectos) ?></div>
            <div class="kpi-lbl">Costos indirectos</div>
            <div class="kpi-sub">Generales del negocio</div>
        </div>
        <div class="kpi">
            <div class="kpi-val green">$<?= fmt_moneda($total_compras) ?></div>
            <div class="kpi-lbl">Compras (<?= $n_compras ?>)</div>
            <div class="kpi-sub">Materia prima</div>
        </div>
        <div class="kpi">
            <div class="kpi-val purple">$<?= fmt_moneda($dep_mensual) ?></div>
            <div class="kpi-lbl">Depreciación</div>
            <div class="kpi-sub"><?= $n_activos ?> activos</div>
        </div>
        <div class="kpi">
            <div class="kpi-val yellow">$<?= fmt_moneda($nomina_directa) ?></div>
            <div class="kpi-lbl">Nómina directa</div>
            <div class="kpi-sub"><?= $nomina_fuente === 'estimado' ? 'Estimado' : 'Liquidaciones' ?></div>
        </div>
        <div class="kpi">
            <div class="kpi-val blue">$<?= fmt_moneda($nomina_indirecta) ?></div>
            <div class="kpi-lbl">Nómina indirecta</div>
        </div>
        <div class="kpi highlight">
            <div class="kpi-val brand">$<?= fmt_moneda($gran_total) ?></div>
            <div class="kpi-lbl">Gran total</div>
            <div class="kpi-sub">Suma de todos los costos</div>
        </div>
    </div>

    <!-- Desglose porcentual -->
    <p class="section-title">Composición del costo total</p>
    <div class="tbl-wrap">
        <table class="tbl">
            <thead><tr><th>Concepto</th><th>Monto</th><th>Participación</th><th>Proporción visual</th></tr></thead>
            <tbody>
            <?php
            $desglose = [
                ['Costos directos (módulo)',  $total_costos_directos,  '#92400e'],
                ['Costos indirectos (módulo)',$total_costos_indirectos,'#1d4ed8'],
                ['Compras materia prima',     $total_compras,          '#059669'],
                ['Depreciación activos',      $dep_mensual,            '#6d28d9'],
                ['Nómina directa',            $nomina_directa,         '#92400e'],
                ['Nómina indirecta',          $nomina_indirecta,       '#1d4ed8'],
            ];
            foreach ($desglose as [$label, $monto, $color]):
                $pct = $gran_total > 0 ? round($monto / $gran_total * 100, 1) : 0;
            ?>
            <tr>
                <td><?= htmlspecialchars($label) ?></td>
                <td><strong>$<?= fmt_moneda($monto) ?></strong></td>
                <td style="color:var(--g5)"><?= $pct ?>%</td>
                <td style="width:180px">
                    <div class="bar-wrap">
                        <div class="bar-fill" style="width:<?= min(100,$pct) ?>%;background:<?= $color ?>"></div>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
            <tr class="total-row">
                <td>GRAN TOTAL</td>
                <td>$<?= fmt_moneda($gran_total) ?></td>
                <td>100%</td>
                <td></td>
            </tr>
            </tfoot>
        </table>
    </div>

    <!-- Costos registrados del período -->
    <?php if (!empty($costos_rows)): ?>
    <p class="section-title">Costos registrados en el período</p>
    <div class="tbl-wrap">
        <table class="tbl">
            <thead><tr><th>Nombre</th><th>Categoría</th><th>Clasificación</th><th>Frecuencia</th><th class="r">Equiv. mensual</th></tr></thead>
            <tbody>
            <?php
            $grupo_actual = '';
            $total_grupo  = 0;
            foreach ($costos_rows as $i => $c):
                // Encabezado de grupo por clasificación
                if ($c['clasificacion'] !== $grupo_actual) {
                    if ($grupo_actual !== '' && $total_grupo > 0) {
                        echo '<tr class="total-row"><td colspan="4">Subtotal ' . ucfirst($grupo_actual) . '</td><td class="r">$' . fmt_moneda($total_grupo) . '</td></tr>';
                        $total_grupo = 0;
                    }
                    $grupo_actual = $c['clasificacion'];
                }
                $total_grupo += (float)$c['valor_mensual'];
            ?>
            <tr>
                <td><?= htmlspecialchars($c['nombre']) ?></td>
                <td style="font-size:12px;color:var(--g5)"><?= htmlspecialchars(str_replace('_',' ',ucfirst($c['categoria']))) ?></td>
                <td><span class="badge badge-<?= htmlspecialchars($c['clasificacion']) ?>"><?= htmlspecialchars(ucfirst($c['clasificacion'])) ?></span></td>
                <td style="font-size:12px;color:var(--g5)"><?= htmlspecialchars(ucfirst($c['frecuencia'])) ?></td>
                <td class="r"><strong>$<?= fmt_moneda((float)$c['valor_mensual']) ?></strong></td>
            </tr>
            <?php endforeach;
            if ($grupo_actual !== '' && $total_grupo > 0):
            ?>
            <tr class="total-row"><td colspan="4">Subtotal <?= ucfirst($grupo_actual) ?></td><td class="r">$<?= fmt_moneda($total_grupo) ?></td></tr>
            <?php endif; ?>
            </tbody>
            <tfoot>
            <tr class="total-row">
                <td colspan="4"><strong>TOTAL COSTOS DEL MÓDULO</strong></td>
                <td class="r"><strong>$<?= fmt_moneda($total_costos) ?></strong></td>
            </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>

    <!-- Compras por proveedor -->
    <?php if (!empty($compras_por_proveedor)): ?>
    <p class="section-title">Compras del período por proveedor</p>
    <div class="tbl-wrap">
        <table class="tbl">
            <thead><tr><th>Proveedor</th><th class="r">N° compras</th><th class="r">Total</th></tr></thead>
            <tbody>
            <?php foreach ($compras_por_proveedor as $r): ?>
            <tr>
                <td><?= htmlspecialchars($r['proveedor']) ?></td>
                <td class="r"><?= (int)$r['n'] ?></td>
                <td class="r"><strong>$<?= fmt_moneda((float)$r['subtotal']) ?></strong></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr class="total-row"><td>TOTAL</td><td class="r"><?= $n_compras ?></td><td class="r">$<?= fmt_moneda($total_compras) ?></td></tr></tfoot>
        </table>
    </div>
    <?php endif; ?>

    <?php if ($nomina_fuente === 'estimado'): ?>
    <p class="nota">Nota: No hay liquidaciones de nómina para este período. Los valores de nómina son estimados basados en los salarios base actuales de los empleados activos.</p>
    <?php endif; ?>

</main>
</body>
</html>
