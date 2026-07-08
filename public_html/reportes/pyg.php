<?php
/**
 * reportes/pyg.php — Estado de Resultados (Pérdidas y Ganancias) del período.
 *
 * Estructura contable gerencial:
 *   Ingresos por ventas (netos, excluye obsequios)
 *   (−) Costo de ventas (COGS)  = SUM(costo_unit_snap × cantidad)  [mig. 044;
 *                                  fallback al costo_calculado actual en ventas previas]
 *   = Utilidad bruta
 *   (−) Gastos operativos: nómina + costos indirectos + depreciación + obsequios (a costo)
 *   = Utilidad operativa (antes de impuestos)
 *
 * Además: valorización de inventario (insumos + producto terminado) como contexto.
 * Base de datos inmutable: usa el snapshot de COGS de cada venta (v5.1).
 * Exporta a Excel (1 hoja: Estado de Resultados).
 */
require_once __DIR__ . '/../app/middleware/auth_check.php';
require_once __DIR__ . '/../app/helpers/XlsxWriter.php';

$nav_activo = 'reportes';
permiso_requerir('costos', 'solo_ver');

// ── Período ───────────────────────────────────────────────────────────────────
$mes        = max(1, min(12, (int)($_GET['mes']  ?? date('n'))));
$anio       = (int)($_GET['anio'] ?? date('Y'));
$anio       = max(2020, min((int)date('Y') + 1, $anio));
$mes_inicio = sprintf('%04d-%02d-01', $anio, $mes);
$mes_fin    = date('Y-m-t', strtotime($mes_inicio));
$meses_es   = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',
               7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];

// ¿Existe el snapshot de COGS (migración 044)?
$tiene044 = false;
try {
    $tiene044 = (int)db()->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='venta_detalles'
           AND COLUMN_NAME='costo_unit_snap'"
    )->fetchColumn() > 0;
} catch (\Throwable $e) { $tiene044 = false; }

// Expresión de costo por línea: snapshot si existe, si no el costo actual de la receta.
$cogsExpr = $tiene044
    ? 'COALESCE(vd.costo_unit_snap, p.costo_calculado, 0)'
    : 'COALESCE(p.costo_calculado, 0)';

// ── INGRESOS netos por método (excluye anuladas y obsequios) ───────────────────
$stmt = db()->prepare(
    "SELECT v.metodo_pago, COALESCE(SUM(v.total),0) AS ingreso, COUNT(*) AS n
     FROM ventas v
     WHERE v.estado <> 'anulada' AND v.metodo_pago <> 'obsequio'
       AND DATE(v.fecha_venta) BETWEEN :ini AND :fin
     GROUP BY v.metodo_pago
     ORDER BY ingreso DESC"
);
$stmt->execute([':ini' => $mes_inicio, ':fin' => $mes_fin]);
$ingresos_metodo = $stmt->fetchAll();
$ingresos = array_sum(array_column($ingresos_metodo, 'ingreso'));
$n_ventas = array_sum(array_column($ingresos_metodo, 'n'));

// ── COSTO DE VENTAS (COGS) de esas ventas ──────────────────────────────────────
$stmt = db()->prepare(
    "SELECT COALESCE(SUM({$cogsExpr} * vd.cantidad),0) AS cogs,
            COALESCE(SUM(CASE WHEN " . ($tiene044 ? 'vd.costo_unit_snap IS NULL' : '1=1') . "
                              THEN vd.cantidad ELSE 0 END),0) AS unidades_estimadas
     FROM venta_detalles vd
     JOIN ventas v     ON v.id = vd.venta_id
     JOIN productos p  ON p.id = vd.producto_id
     WHERE v.estado <> 'anulada' AND v.metodo_pago <> 'obsequio'
       AND DATE(v.fecha_venta) BETWEEN :ini AND :fin"
);
$stmt->execute([':ini' => $mes_inicio, ':fin' => $mes_fin]);
$rowCogs = $stmt->fetch();
$cogs = (float)$rowCogs['cogs'];
$unidades_estimadas = (float)$rowCogs['unidades_estimadas']; // COGS estimado (sin snapshot)

$utilidad_bruta = $ingresos - $cogs;
$margen_bruto_pct = $ingresos > 0 ? round($utilidad_bruta / $ingresos * 100, 1) : 0.0;

// ── GASTOS OPERATIVOS ──────────────────────────────────────────────────────────
// Nómina del período (costo total empleador); fallback a salarios actuales si no hay liquidaciones.
$nomina = 0.0; $nomina_estimada = false;
try {
    $s = db()->prepare("SELECT COALESCE(SUM(costo_total_empleador),0) FROM nomina_liquidaciones
                        WHERE periodo_mes=? AND periodo_anio=?");
    $s->execute([$mes, $anio]);
    $nomina = (float)$s->fetchColumn();
    if ($nomina <= 0) {
        $nomina = (float)db()->query("SELECT COALESCE(SUM(salario_base),0) FROM empleados WHERE activo=1")->fetchColumn();
        $nomina_estimada = $nomina > 0;
    }
} catch (\Throwable $e) {}

// Costos indirectos prorrateados a mensual (vigentes en el período)
$costos_indirectos = 0.0;
try {
    $s = db()->prepare(
        "SELECT COALESCE(SUM(CASE frecuencia
                    WHEN 'bimestral' THEN valor/2 WHEN 'trimestral' THEN valor/3
                    WHEN 'semestral' THEN valor/6 WHEN 'anual' THEN valor/12
                    ELSE valor END),0)
         FROM costos_indirectos
         WHERE activo=1 AND fecha_inicio <= :fin AND (fecha_fin IS NULL OR fecha_fin >= :ini)"
    );
    $s->execute([':ini' => $mes_inicio, ':fin' => $mes_fin]);
    $costos_indirectos = (float)$s->fetchColumn();
} catch (\Throwable $e) {}

// Depreciación mensual (solo activos en uso, no depreciados)
$depreciacion = 0.0;
try {
    $s = db()->prepare(
        "SELECT COALESCE(SUM(depreciacion_mensual),0) FROM activos
         WHERE activo=1
           AND fecha_inicio_uso IS NOT NULL AND fecha_inicio_uso <= ?
           AND TIMESTAMPDIFF(MONTH, fecha_inicio_uso, CURDATE()) < CAST(vida_util_meses AS SIGNED)"
    );
    $s->execute([$mes_fin]);
    $depreciacion = (float)$s->fetchColumn();
} catch (\Throwable $e) {}

// Obsequios a valor de COSTO (producto regalado = gasto real, no ingreso)
$obsequios_costo = 0.0;
try {
    $s = db()->prepare(
        "SELECT COALESCE(SUM({$cogsExpr} * vd.cantidad),0)
         FROM venta_detalles vd
         JOIN ventas v ON v.id = vd.venta_id
         JOIN productos p ON p.id = vd.producto_id
         WHERE v.estado <> 'anulada' AND v.metodo_pago = 'obsequio'
           AND DATE(v.fecha_venta) BETWEEN :ini AND :fin"
    );
    $s->execute([':ini' => $mes_inicio, ':fin' => $mes_fin]);
    $obsequios_costo = (float)$s->fetchColumn();
} catch (\Throwable $e) {}

$gastos_operativos = $nomina + $costos_indirectos + $depreciacion + $obsequios_costo;
$utilidad_operativa = $utilidad_bruta - $gastos_operativos;
$margen_neto_pct = $ingresos > 0 ? round($utilidad_operativa / $ingresos * 100, 1) : 0.0;

// ── VALORIZACIÓN DE INVENTARIO (contexto — activo corriente) ───────────────────
$inv_insumos = (float)db()->query(
    "SELECT COALESCE(SUM(stock_actual * costo_actual),0) FROM insumos WHERE activo=1"
)->fetchColumn();
$inv_productos = (float)db()->query(
    "SELECT COALESCE(SUM(stock_disponible * costo_calculado),0) FROM productos WHERE activo=1"
)->fetchColumn();
$inv_total = $inv_insumos + $inv_productos;

$metodo_label = [
    'efectivo'=>'Efectivo','nequi'=>'Nequi','daviplata'=>'Daviplata',
    'bancolombia'=>'Bancolombia','fiado'=>'Fiado (por cobrar)',
];

// ── EXPORT EXCEL ───────────────────────────────────────────────────────────────
if (($_GET['export'] ?? '') === '1') {
    $w = new XlsxWriter();
    $w->setSheet('Estado de Resultados');
    $w->addRow(['ESTADO DE RESULTADOS (P&G) — ' . strtoupper($meses_es[$mes]) . ' ' . $anio], header: true);
    $w->addRow([]);
    $w->addRow(['Concepto', 'Monto ($)'], header: true);
    $w->addRow(['INGRESOS POR VENTAS (netos)', round($ingresos)], total: true);
    foreach ($ingresos_metodo as $im) {
        $w->addRow(['   ' . ($metodo_label[$im['metodo_pago']] ?? ucfirst($im['metodo_pago'])), round((float)$im['ingreso'])]);
    }
    $w->addRow(['(−) Costo de ventas (COGS)', -round($cogs)]);
    $w->addRow(['= UTILIDAD BRUTA', round($utilidad_bruta)], total: true);
    $w->addRow(['   Margen bruto %', $margen_bruto_pct . '%']);
    $w->addRow([]);
    $w->addRow(['(−) GASTOS OPERATIVOS', -round($gastos_operativos)], total: true);
    $w->addRow(['   Nómina' . ($nomina_estimada ? ' (estimada)' : ''), -round($nomina)]);
    $w->addRow(['   Costos indirectos', -round($costos_indirectos)]);
    $w->addRow(['   Depreciación', -round($depreciacion)]);
    $w->addRow(['   Obsequios (a costo)', -round($obsequios_costo)]);
    $w->addRow([]);
    $w->addRow(['= UTILIDAD OPERATIVA (antes de impuestos)', round($utilidad_operativa)], total: true);
    $w->addRow(['   Margen neto %', $margen_neto_pct . '%']);
    $w->addRow([]);
    $w->addRow(['VALORIZACIÓN DE INVENTARIO (fin de período)', round($inv_total)], header: true);
    $w->addRow(['   Insumos (stock × costo)', round($inv_insumos)]);
    $w->addRow(['   Producto terminado (stock × costo)', round($inv_productos)]);
    $w->download(slug_negocio() . '_PyG_' . $meses_es[$mes] . '_' . $anio . '.xlsx');
    exit;
}

$anios = range((int)date('Y'), 2023);
$es_utilidad = $utilidad_operativa >= 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Estado de Resultados (P&G) — <?= APP_NAME ?></title>
    <style>
        :root { --brand:#e94f37; --dark:#111827; --g5:#6b7280; --g8:#d1d5db; --g9:#f3f4f6; --white:#fff; --green:#059669; --red:#dc2626; }
        *,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
        body { font-family:system-ui,-apple-system,sans-serif; background:var(--g9); color:var(--dark); }
        .main { max-width:820px; margin:0 auto; padding:20px 14px 60px; }
        .page-title { font-size:22px; font-weight:800; }
        .page-sub { font-size:13px; color:var(--g5); margin:3px 0 18px; }
        .filtros { display:flex; gap:8px; align-items:flex-end; background:var(--white); border:1px solid var(--g8); border-radius:12px; padding:12px 16px; margin-bottom:16px; flex-wrap:wrap; }
        .fg { display:flex; flex-direction:column; gap:3px; }
        .fg label { font-size:11px; font-weight:700; color:var(--g5); text-transform:uppercase; }
        .fg select { padding:7px 10px; border:1px solid var(--g8); border-radius:8px; font-size:13px; }
        .btn-ver { padding:8px 16px; background:var(--brand); color:#fff; border:none; border-radius:8px; font-size:13px; font-weight:700; cursor:pointer; }
        .btn-excel { padding:8px 16px; background:#16a34a; color:#fff; border:none; border-radius:8px; font-size:13px; font-weight:700; text-decoration:none; margin-left:auto; }
        .kpis { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:10px; margin-bottom:18px; }
        .kpi { background:var(--white); border:1px solid var(--g8); border-radius:12px; padding:14px 16px; }
        .kpi-val { font-size:20px; font-weight:800; } .kpi-lbl { font-size:11px; color:var(--g5); margin-top:3px; text-transform:uppercase; }
        .kpi-val.green { color:var(--green); } .kpi-val.red { color:var(--red); }
        .card { background:var(--white); border:1px solid var(--g8); border-radius:14px; padding:8px 8px; margin-bottom:18px; overflow-x:auto; }
        .pl { width:100%; border-collapse:collapse; font-size:14px; }
        .pl td { padding:10px 14px; border-bottom:1px solid var(--g9); }
        .pl td.r { text-align:right; font-variant-numeric:tabular-nums; }
        .pl tr.sub td { color:var(--g5); font-size:13px; padding:6px 14px 6px 30px; }
        .pl tr.tot td { font-weight:800; background:var(--g9); }
        .pl tr.tot.big td { font-size:16px; }
        .neg { color:var(--red); }
        .pos { color:var(--green); }
        .warn { background:#fff7ed; border:1px solid #fed7aa; border-radius:10px; padding:10px 14px; font-size:12.5px; color:#92400e; margin-bottom:14px; }
        .sec-title { font-size:12px; font-weight:800; color:var(--g5); text-transform:uppercase; letter-spacing:.5px; margin:10px 0 6px; }
        @media(max-width:479px){ .main{padding:12px 10px 40px;} .btn-excel{margin-left:0;width:100%;text-align:center;} }
    </style>
</head>
<body>
<?php include __DIR__ . '/../app/views/nav.php'; ?>
<main class="main">
    <h1 class="page-title">Estado de Resultados (P&amp;G)</h1>
    <p class="page-sub"><?= $meses_es[$mes] ?> <?= $anio ?> · <?= (int)$n_ventas ?> ventas · base contable inmutable (costo real al vender)</p>

    <form class="filtros" method="get">
        <div class="fg"><label>Mes</label>
            <select name="mes"><?php foreach ($meses_es as $m=>$nm): ?>
                <option value="<?= $m ?>"<?= $m===$mes?' selected':'' ?>><?= $nm ?></option>
            <?php endforeach; ?></select></div>
        <div class="fg"><label>Año</label>
            <select name="anio"><?php foreach ($anios as $a): ?>
                <option value="<?= $a ?>"<?= $a===$anio?' selected':'' ?>><?= $a ?></option>
            <?php endforeach; ?></select></div>
        <button class="btn-ver" type="submit">Ver</button>
        <a class="btn-excel" href="?mes=<?= $mes ?>&anio=<?= $anio ?>&export=1">📊 Excel</a>
    </form>

    <?php if (!$tiene044): ?>
    <div class="warn"><strong>Migración 044 no aplicada:</strong> el COGS se estima con el costo actual de la receta (no el del momento de la venta). Aplica <code>044_venta_costo_snap.sql</code> para números exactos.</div>
    <?php elseif ($unidades_estimadas > 0): ?>
    <div class="warn"><strong>Nota:</strong> <?= fmt_cantidad($unidades_estimadas, 0) ?> unidades vendidas antes de la migración 044 usan el costo actual de la receta como estimación (las ventas nuevas usan el costo real inmutable).</div>
    <?php endif; ?>

    <div class="kpis">
        <div class="kpi"><div class="kpi-val">$<?= fmt_moneda($ingresos) ?></div><div class="kpi-lbl">Ingresos netos</div></div>
        <div class="kpi"><div class="kpi-val green"><?= $margen_bruto_pct ?>%</div><div class="kpi-lbl">Margen bruto</div></div>
        <div class="kpi"><div class="kpi-val <?= $es_utilidad?'green':'red' ?>">$<?= fmt_moneda($utilidad_operativa) ?></div><div class="kpi-lbl"><?= $es_utilidad?'Utilidad':'Pérdida' ?> operativa</div></div>
        <div class="kpi"><div class="kpi-val"><?= $margen_neto_pct ?>%</div><div class="kpi-lbl">Margen neto</div></div>
    </div>

    <div class="card">
        <table class="pl">
            <tr class="tot"><td>Ingresos por ventas (netos)</td><td class="r">$<?= fmt_moneda($ingresos) ?></td></tr>
            <?php foreach ($ingresos_metodo as $im): ?>
            <tr class="sub"><td><?= $metodo_label[$im['metodo_pago']] ?? ucfirst(htmlspecialchars($im['metodo_pago'])) ?></td><td class="r">$<?= fmt_moneda((float)$im['ingreso']) ?></td></tr>
            <?php endforeach; ?>
            <tr><td>(−) Costo de ventas (COGS)</td><td class="r neg">−$<?= fmt_moneda($cogs) ?></td></tr>
            <tr class="tot"><td>= Utilidad bruta</td><td class="r <?= $utilidad_bruta>=0?'pos':'neg' ?>">$<?= fmt_moneda($utilidad_bruta) ?> <span style="color:var(--g5);font-weight:400">(<?= $margen_bruto_pct ?>%)</span></td></tr>

            <tr><td colspan="2"><div class="sec-title">Gastos operativos</div></td></tr>
            <tr class="sub"><td>Nómina<?= $nomina_estimada?' (estimada, sin liquidación del mes)':'' ?></td><td class="r neg">−$<?= fmt_moneda($nomina) ?></td></tr>
            <tr class="sub"><td>Costos indirectos</td><td class="r neg">−$<?= fmt_moneda($costos_indirectos) ?></td></tr>
            <tr class="sub"><td>Depreciación de activos</td><td class="r neg">−$<?= fmt_moneda($depreciacion) ?></td></tr>
            <tr class="sub"><td>Obsequios (a valor de costo)</td><td class="r neg">−$<?= fmt_moneda($obsequios_costo) ?></td></tr>
            <tr class="tot"><td>(−) Total gastos operativos</td><td class="r neg">−$<?= fmt_moneda($gastos_operativos) ?></td></tr>

            <tr class="tot big"><td>= <?= $es_utilidad?'Utilidad':'Pérdida' ?> operativa (antes de impuestos)</td><td class="r <?= $es_utilidad?'pos':'neg' ?>">$<?= fmt_moneda($utilidad_operativa) ?> <span style="color:var(--g5);font-weight:400">(<?= $margen_neto_pct ?>%)</span></td></tr>
        </table>
    </div>

    <div class="sec-title">Valorización de inventario (fin de período · activo corriente)</div>
    <div class="card">
        <table class="pl">
            <tr class="sub"><td>Insumos (stock × costo actual)</td><td class="r">$<?= fmt_moneda($inv_insumos) ?></td></tr>
            <tr class="sub"><td>Producto terminado (stock × costo calculado)</td><td class="r">$<?= fmt_moneda($inv_productos) ?></td></tr>
            <tr class="tot"><td>Total inventario valorizado</td><td class="r">$<?= fmt_moneda($inv_total) ?></td></tr>
        </table>
    </div>
    <p class="page-sub">Base devengado: el ingreso se reconoce al vender (incluye fiado por cobrar). Impuestos y retiros de socios no se incluyen (utilidad antes de impuestos). Balance general completo: fase futura.</p>
</main>
</body>
</html>
