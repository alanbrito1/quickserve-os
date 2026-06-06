<?php
/**
 * productos/analisis.php — Análisis integral de rentabilidad.
 *
 * Incluye:
 *   1. Costeo real vs estimado por producto y período
 *   2. Punto de equilibrio por producto y global
 *   3. Análisis histórico de ventas (patrones diarios/semanales/mensuales)
 *   4. Sugerencia de producción basada en histórico
 */
require_once __DIR__ . '/../app/middleware/auth_check.php';
require_once __DIR__ . '/../app/models/RecetaModel.php';
require_once __DIR__ . '/../app/models/ActivoModel.php';
require_once __DIR__ . '/../app/models/CostoIndirectoModel.php';

$nav_activo = 'productos';
permiso_requerir('productos', 'solo_ver');

// ── Período seleccionado ──────────────────────────────────────────────────────
$mes   = max(1, min(12, (int)($_GET['mes']  ?? date('n'))));
$anio  = (int)($_GET['anio'] ?? date('Y'));
$anio  = max(2020, min((int)date('Y') + 1, $anio));
$mes_inicio = sprintf('%04d-%02d-01', $anio, $mes);
$mes_fin    = date('Y-m-t', strtotime($mes_inicio));

$meses_es = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',
             7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];

// ── Capacidad y parámetros ────────────────────────────────────────────────────
$cfg = db()->query(
    "SELECT clave, valor FROM configuracion_negocio
     WHERE clave IN ('produccion_estimada_mensual','costos_fijos_mensuales')"
)->fetchAll(PDO::FETCH_KEY_PAIR);

$prod_mensual_cap = max(1, (float)($cfg['produccion_estimada_mensual'] ?? 2000));
$prod_diaria_cap  = round($prod_mensual_cap / 21.75, 1);

// ── Producción real del período ───────────────────────────────────────────────
$prod_real_stmt = db()->prepare(
    "SELECT pl.producto_id, p.nombre, p.nombre2, p.precio_venta,
            SUM(pl.cantidad) AS total_producido,
            AVG(pl.costo_unitario) AS costo_unit_promedio
     FROM produccion_lotes pl
     JOIN productos p ON p.id = pl.producto_id
     WHERE pl.estado = 'activo'
       AND pl.fecha_produccion BETWEEN :ini AND :fin
     GROUP BY pl.producto_id, p.nombre, p.nombre2, p.precio_venta
     ORDER BY total_producido DESC"
);
$prod_real_stmt->execute([':ini' => $mes_inicio, ':fin' => $mes_fin]);
$produccion_real = $prod_real_stmt->fetchAll();
$total_produccion_real = array_sum(array_column($produccion_real, 'total_producido'));

// ── Ventas del período ────────────────────────────────────────────────────────
$ventas_stmt = db()->prepare(
    "SELECT vd.producto_id, p.nombre, p.nombre2, p.precio_venta,
            SUM(vd.cantidad) AS unidades_vendidas,
            SUM(vd.subtotal) AS ingreso_total
     FROM venta_detalles vd
     JOIN ventas v ON v.id = vd.venta_id
     JOIN productos p ON p.id = vd.producto_id
     WHERE v.estado != 'anulada'
       AND DATE(v.fecha_venta) BETWEEN :ini AND :fin
     GROUP BY vd.producto_id, p.nombre, p.nombre2, p.precio_venta
     ORDER BY unidades_vendidas DESC"
);
$ventas_stmt->execute([':ini' => $mes_inicio, ':fin' => $mes_fin]);
$ventas_mes = $ventas_stmt->fetchAll();
$total_unidades_vendidas = array_sum(array_column($ventas_mes, 'unidades_vendidas'));
$total_ingresos = array_sum(array_column($ventas_mes, 'ingreso_total'));

// ── Costos fijos del período ─────────────────────────────────────────────────
$costos_fijos_mes = CostoIndirectoModel::total_mensual_activo();
if ($costos_fijos_mes <= 0) {
    $costos_fijos_mes = (float)($cfg['costos_fijos_mensuales'] ?? 0);
}

// Depreciación mensual: solo activos en uso (fecha_inicio_uso asignada) y no depreciados
$dep_mensual = 0.0;
try {
    $stmt_dep = db()->prepare(
        "SELECT COALESCE(SUM(depreciacion_mensual), 0)
         FROM activos
         WHERE activo = 1
           AND estado_vida != 'depreciado'
           AND fecha_inicio_uso IS NOT NULL
           AND fecha_inicio_uso <= ?"
    );
    $stmt_dep->execute([$mes_fin]);
    $dep_mensual = (float)$stmt_dep->fetchColumn();
} catch (Exception $e) { /* tabla activos puede no tener fecha_inicio_uso aún */ }

// Nómina del período (directa e indirecta)
$nomina_directa = $nomina_indirecta = 0.0;
try {
    $stmt_nom = db()->prepare(
        "SELECT COALESCE(e.tipo_costo,'indirecto') AS tc,
                COALESCE(SUM(nl.costo_total_empleador),0) AS total
         FROM nomina_liquidaciones nl
         LEFT JOIN empleados e ON e.id = nl.empleado_id
         WHERE nl.periodo_mes=? AND nl.periodo_anio=?
         GROUP BY tc"
    );
    $stmt_nom->execute([$mes, $anio]);
    foreach ($stmt_nom->fetchAll() as $r) {
        if ($r['tc'] === 'directo') $nomina_directa   = (float)$r['total'];
        else                        $nomina_indirecta = (float)$r['total'];
    }
    // Fallback: salarios actuales
    if ($nomina_directa + $nomina_indirecta == 0) {
        $stmt_sal = db()->prepare(
            "SELECT COALESCE(tipo_costo,'indirecto') AS tc, COALESCE(SUM(salario_base),0) AS total
             FROM empleados WHERE activo=1 GROUP BY tc"
        );
        $stmt_sal->execute();
        foreach ($stmt_sal->fetchAll() as $r) {
            if ($r['tc']==='directo') $nomina_directa   = (float)$r['total'];
            else                      $nomina_indirecta = (float)$r['total'];
        }
    }
} catch (Exception $e) {}

$total_costos_fijos = $costos_fijos_mes + $dep_mensual + $nomina_indirecta;
$total_costos_variables_mensuales = $nomina_directa;

// ── Productos con costeo completo ─────────────────────────────────────────────
$prod_cap = max(1, (int)$prod_mensual_cap);
$prod_real_u = max(1, (int)$total_produccion_real ?: $prod_cap);
$fijo_u_cap  = round($total_costos_fijos / $prod_cap,   2);
$fijo_u_real = round($total_costos_fijos / $prod_real_u, 2);
$var_directo_u_cap  = round($total_costos_variables_mensuales / $prod_cap,   2);
$var_directo_u_real = round($total_costos_variables_mensuales / $prod_real_u, 2);

// Tabla de productos con margen real y punto de equilibrio
$productos_mapa = [];
foreach (RecetaModel::productos_con_margen($fijo_u_cap + $var_directo_u_cap) as $p) {
    $productos_mapa[$p['id']] = $p;
}

// ── Histórico de ventas (últimos 90 días) ─────────────────────────────────────
$stmt_hist = db()->prepare(
    "SELECT DATE(v.fecha_venta) AS dia, vd.producto_id,
            SUM(vd.cantidad) AS vendidos
     FROM venta_detalles vd
     JOIN ventas v ON v.id = vd.venta_id
     WHERE v.estado != 'anulada'
       AND v.fecha_venta >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
     GROUP BY dia, vd.producto_id
     ORDER BY dia"
);
$stmt_hist->execute();
$historico_raw = $stmt_hist->fetchAll();

// Calcular promedio diario por producto y total
$productos_hist = [];
$dias_hist = [];
foreach ($historico_raw as $h) {
    $dia = $h['dia'];
    $pid = $h['producto_id'];
    $dias_hist[$dia] = true;
    $productos_hist[$pid] = ($productos_hist[$pid] ?? 0) + (int)$h['vendidos'];
}
$dias_con_ventas = count($dias_hist);
$promedio_diario_total = $dias_con_ventas > 0 ? round(array_sum($productos_hist) / $dias_con_ventas, 1) : 0;
$proyeccion_mensual = round($promedio_diario_total * 21.75, 0);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Análisis de Rentabilidad — <?= APP_NAME ?></title>
    <style>
        :root { --brand:#e94f37; --dark:#111827; --g5:#6b7280; --g8:#d1d5db; --g9:#f3f4f6; --white:#fff; --green:#059669; }
        *,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
        body { font-family:system-ui,-apple-system,sans-serif; background:var(--g9); color:var(--dark); }
        .main { max-width:1100px; margin:0 auto; padding:20px 14px 60px; }
        .page-hdr { display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:10px; margin-bottom:20px; }
        .page-title { font-size:22px; font-weight:800; }
        .page-sub   { font-size:13px; color:var(--g5); margin-top:3px; }

        /* Filtros */
        .filtros { display:flex; gap:8px; align-items:flex-end; background:var(--white); border:1px solid var(--g8); border-radius:12px; padding:12px 16px; margin-bottom:18px; flex-wrap:wrap; }
        .fg { display:flex; flex-direction:column; gap:3px; }
        .fg label { font-size:11px; font-weight:700; color:var(--g5); text-transform:uppercase; }
        .fg select { padding:7px 10px; border:1px solid var(--g8); border-radius:8px; font-size:13px; outline:none; }
        .btn-ver { padding:8px 16px; background:var(--brand); color:#fff; border:none; border-radius:8px; font-size:13px; font-weight:700; cursor:pointer; align-self:flex-end; }

        /* KPIs */
        .kpi-row { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:10px; margin-bottom:20px; }
        .kpi { background:var(--white); border:1px solid var(--g8); border-radius:12px; padding:14px 16px; }
        .kpi-val { font-size:20px; font-weight:800; }
        .kpi-lbl { font-size:11px; color:var(--g5); margin-top:3px; text-transform:uppercase; }
        .kpi-sub { font-size:11px; color:var(--g5); margin-top:2px; }
        .kpi-val.brand { color:var(--brand); }
        .kpi-val.green { color:var(--green); }

        /* Secciones */
        .section { background:var(--white); border:1px solid var(--g8); border-radius:14px; padding:20px; margin-bottom:20px; overflow-x:auto; -webkit-overflow-scrolling:touch; }
        .section-title { font-size:15px; font-weight:700; margin-bottom:14px; padding-bottom:10px; border-bottom:1px solid var(--g9); display:flex; justify-content:space-between; align-items:center; }
        .section-title span { font-size:12px; font-weight:400; color:var(--g5); }

        /* Tabla */
        .tbl { width:100%; border-collapse:collapse; font-size:13px; }
        .tbl th { background:var(--g9); font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; padding:9px 12px; text-align:left; color:var(--g5); }
        .tbl th.r,.tbl td.r { text-align:right; }
        .tbl tbody tr { border-bottom:1px solid var(--g9); }
        .tbl tbody tr:last-child { border-bottom:none; }
        .tbl td { padding:10px 12px; }
        .tbl tr.total-row td { font-weight:700; background:var(--g9); }
        .badge { display:inline-block; font-size:11px; font-weight:600; padding:2px 7px; border-radius:999px; }
        .badge-ok   { background:#d1fae5; color:#065f46; }
        .badge-warn { background:#fef3c7; color:#92400e; }
        .badge-bad  { background:#fee2e2; color:#991b1b; }

        /* Barra de utilización */
        .util-bar { background:var(--g9); border-radius:4px; height:8px; margin-top:4px; min-width:100px; }
        .util-fill { height:8px; border-radius:4px; background:var(--green); transition:width .3s; }
        .util-fill.warn { background:#d97706; }
        .util-fill.low  { background:var(--brand); }

        /* Gráfico simple de barras */
        .bar-chart { display:flex; align-items:flex-end; gap:3px; height:80px; padding:0 4px; }
        .bar-col { flex:1; display:flex; flex-direction:column; align-items:center; gap:2px; }
        .bar { width:100%; background:var(--brand); border-radius:2px 2px 0 0; min-height:2px; transition:height .3s; }
        .bar-lbl { font-size:8px; color:var(--g5); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:24px; }

        /* Alerta */
        .alert-warn { background:#fff7ed; border:1px solid #fed7aa; border-radius:10px; padding:12px 14px; font-size:13px; color:#92400e; margin-bottom:16px; }
        .alert-ok   { background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px; padding:12px 14px; font-size:13px; color:#166534; margin-bottom:16px; }

        /* Punto de equilibrio highlight */
        .pe-highlight { background:#eff6ff; border:2px solid #3b82f6; border-radius:12px; padding:16px; text-align:center; }
        .pe-val { font-size:28px; font-weight:800; color:#1d4ed8; }
        .pe-lbl { font-size:12px; color:var(--g5); }
    </style>
</head>
<body>
<?php $nav_activo = 'productos'; include __DIR__ . '/../app/views/nav.php'; ?>
<main class="main">

    <div class="page-hdr">
        <div>
            <h1 class="page-title">Análisis de Rentabilidad</h1>
            <p class="page-sub">Costeo real · Punto de equilibrio · Tendencias de ventas</p>
        </div>
        <a href="<?= htmlspecialchars(APP_BASE, ENT_QUOTES) ?>/productos/" style="background:var(--g9);color:var(--dark);text-decoration:none;padding:8px 14px;border-radius:8px;font-size:13px;font-weight:600">
            ← Productos
        </a>
    </div>

    <!-- Filtro de período -->
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
        <span style="align-self:center;font-size:12px;color:var(--g5);margin-left:8px">
            Capacidad instalada: <strong><?= number_format($prod_mensual_cap,0,',','.') ?> u/mes</strong>
            · Producción real: <strong><?= number_format($total_produccion_real,0,',','.') ?> u</strong>
        </span>
    </form>

    <!-- KPIs del período -->
    <div class="kpi-row">
        <div class="kpi">
            <div class="kpi-val brand"><?= number_format($total_produccion_real,0,',','.') ?></div>
            <div class="kpi-lbl">Unidades producidas</div>
            <div class="kpi-sub"><?= round($total_produccion_real/$prod_mensual_cap*100) ?>% de capacidad</div>
        </div>
        <div class="kpi">
            <div class="kpi-val green"><?= number_format($total_unidades_vendidas,0,',','.') ?></div>
            <div class="kpi-lbl">Unidades vendidas</div>
            <div class="kpi-sub">$<?= number_format($total_ingresos,0,',','.') ?> ingresos</div>
        </div>
        <div class="kpi">
            <div class="kpi-val" style="color:#6d28d9">$<?= number_format($total_costos_fijos,0,',','.') ?></div>
            <div class="kpi-lbl">Costos fijos</div>
            <div class="kpi-sub">Arriendo + dep. + nómina indir.</div>
        </div>
        <div class="kpi">
            <div class="kpi-val"><?= round($promedio_diario_total,1) ?></div>
            <div class="kpi-lbl">Promedio ventas/día</div>
            <div class="kpi-sub">Proyección: <?= number_format($proyeccion_mensual,0,',','.') ?>/mes</div>
        </div>
    </div>

    <!-- ══ 1. COSTEO REAL VS ESTIMADO ══════════════════════════════════════════ -->
    <div class="section">
        <div class="section-title">
            Costeo real vs estimado por producto
            <span>Período: <?= $meses_es[$mes] ?> <?= $anio ?></span>
        </div>
        <p style="font-size:12px;color:var(--g5);margin-bottom:14px">
            <strong>Estimado:</strong> divide costos fijos entre capacidad instalada (<?= number_format($prod_mensual_cap,0,',','.') ?> u/mes) ·
            <strong>Real:</strong> divide entre unidades realmente producidas <?= $total_produccion_real > 0 ? '('.$total_produccion_real.' u)' : '(sin datos de producción este mes)' ?>
        </p>
        <table class="tbl">
            <thead>
                <tr>
                    <th>Producto</th>
                    <th class="r">Precio venta</th>
                    <th class="r">Costo ingredientes</th>
                    <th class="r">Costo fijo estimado/u</th>
                    <th class="r">Costo fijo real/u</th>
                    <th class="r">Margen estimado</th>
                    <th class="r">Margen real</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($productos_mapa as $p):
                $costo_ing = (float)$p['costo_ing'];
                $precio    = (float)$p['precio_venta'];
                $costo_est = $costo_ing + $fijo_u_cap + $var_directo_u_cap;
                $costo_real= $costo_ing + $fijo_u_real + $var_directo_u_real;
                $mg_est    = $precio > 0 ? round(($precio-$costo_est)/$precio*100,1) : 0;
                $mg_real   = $precio > 0 ? round(($precio-$costo_real)/$precio*100,1) : 0;
                $cls_est   = $mg_est >= 40 ? 'badge-ok' : ($mg_est >= 20 ? 'badge-warn' : 'badge-bad');
                $cls_real  = $total_produccion_real > 0
                    ? ($mg_real >= 40 ? 'badge-ok' : ($mg_real >= 20 ? 'badge-warn' : 'badge-bad'))
                    : 'badge-warn';
            ?>
            <tr>
                <td>
                    <strong><?= htmlspecialchars($p['nombre']) ?></strong>
                    <?php if ($p['tamano'] !== 'unico'): ?>
                    <span style="font-size:10px;background:var(--g9);padding:1px 5px;border-radius:10px;margin-left:4px"><?= $p['tamano'] ?></span>
                    <?php endif; ?>
                    <?php if (!empty($p['nombre2'])): ?>
                    <div style="font-size:11px;color:var(--g5);margin-top:2px"><?= htmlspecialchars($p['nombre2']) ?></div>
                    <?php endif; ?>
                </td>
                <td class="r">$<?= number_format($precio,0,',','.') ?></td>
                <td class="r">$<?= number_format($costo_ing,0,',','.') ?></td>
                <td class="r">$<?= number_format($fijo_u_cap + $var_directo_u_cap,0,',','.') ?></td>
                <td class="r"><?= $total_produccion_real > 0 ? '$'.number_format($fijo_u_real + $var_directo_u_real,0,',','.') : '—' ?></td>
                <td class="r"><span class="badge <?= $cls_est ?>"><?= $mg_est ?>%</span></td>
                <td class="r">
                    <?php if ($total_produccion_real > 0): ?>
                    <span class="badge <?= $cls_real ?>"><?= $mg_real ?>%</span>
                    <?php else: ?>
                    <span style="color:var(--g5)">Sin producción</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- ══ 2. PUNTO DE EQUILIBRIO ══════════════════════════════════════════════ -->
    <div class="section">
        <div class="section-title">
            Punto de equilibrio
            <span>Costos fijos: $<?= number_format($total_costos_fijos,0,',','.') ?>/mes</span>
        </div>

        <!-- PE Global -->
        <?php
        // Calcular PE global usando el producto más vendido como referencia
        // o el precio/costo promedio si hay varios
        $precios_arr = array_map(fn($p) => (float)$p['precio_venta'], array_values($productos_mapa));
        $costos_arr  = array_map(fn($p) => (float)$p['costo_ing'], array_values($productos_mapa));
        $precio_prom = count($precios_arr) > 0 ? array_sum($precios_arr) / count($precios_arr) : 18000;
        $costo_var_u = count($costos_arr) > 0 ? array_sum($costos_arr) / count($costos_arr) : 5000;
        $margen_contribucion = $precio_prom - $costo_var_u - $var_directo_u_cap;
        $pe_global = $margen_contribucion > 0 ? round($total_costos_fijos / $margen_contribucion) : 0;
        $pe_global_pesos = $pe_global * $precio_prom;
        $util_pct = $pe_global > 0 ? min(100, round($total_unidades_vendidas / $pe_global * 100)) : 0;
        $superavit = $total_unidades_vendidas - $pe_global;
        ?>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:20px">
            <div class="pe-highlight">
                <div class="pe-val"><?= number_format($pe_global,0,',','.') ?></div>
                <div class="pe-lbl">Unidades/mes para cubrir costos fijos</div>
                <div style="font-size:11px;color:var(--g5);margin-top:4px">(promedio todos los productos)</div>
            </div>
            <div class="pe-highlight" style="background:#f0fdf4;border-color:#22c55e">
                <div class="pe-val" style="color:#16a34a">$<?= number_format($pe_global_pesos,0,',','.') ?></div>
                <div class="pe-lbl">Ingreso mínimo mensual requerido</div>
            </div>
            <div class="pe-highlight" style="background:<?= $superavit >= 0 ? '#f0fdf4' : '#fff7ed' ?>;border-color:<?= $superavit >= 0 ? '#22c55e' : '#f97316' ?>">
                <div class="pe-val" style="color:<?= $superavit >= 0 ? '#16a34a' : '#ea580c' ?>">
                    <?= $superavit >= 0 ? '+' : '' ?><?= number_format($superavit,0,',','.') ?>
                </div>
                <div class="pe-lbl"><?= $superavit >= 0 ? 'Unidades sobre el PE (ganancia)' : 'Unidades bajo el PE (pérdida)' ?></div>
            </div>
        </div>

        <!-- Barra de progreso hacia el PE -->
        <div style="margin-bottom:20px">
            <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--g5);margin-bottom:4px">
                <span>Ventas del mes: <?= number_format($total_unidades_vendidas,0,',','.') ?> u</span>
                <span>PE: <?= number_format($pe_global,0,',','.') ?> u</span>
            </div>
            <div class="util-bar" style="height:16px">
                <div class="util-fill <?= $util_pct < 50 ? 'low' : ($util_pct < 90 ? 'warn' : '') ?>"
                     style="width:<?= min(100,$util_pct) ?>%;height:16px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:11px;font-weight:700">
                    <?= $util_pct ?>%
                </div>
            </div>
        </div>

        <?php if ($superavit >= 0): ?>
        <div class="alert-ok">
            ✓ Este mes las ventas (<?= $total_unidades_vendidas ?> u) superaron el punto de equilibrio (<?= $pe_global ?> u) por <strong><?= number_format($superavit,0,',','.') ?> unidades</strong>.
            Ganancia estimada: <strong>$<?= number_format($superavit * ($precio_prom - $costo_var_u - $var_directo_u_cap),0,',','.') ?></strong>
        </div>
        <?php elseif ($pe_global > 0): ?>
        <div class="alert-warn">
            ⚠ Faltan <strong><?= number_format(abs($superavit),0,',','.') ?> unidades</strong> para cubrir todos los costos fijos.
            Con las ventas actuales hay una pérdida estimada de <strong>$<?= number_format(abs($superavit) * ($precio_prom - $costo_var_u - $var_directo_u_cap),0,',','.') ?></strong>
        </div>
        <?php endif; ?>

        <!-- PE por producto -->
        <p style="font-size:13px;font-weight:700;margin-bottom:10px">Punto de equilibrio por producto</p>
        <table class="tbl">
            <thead>
                <tr>
                    <th>Producto</th>
                    <th class="r">Precio</th>
                    <th class="r">Costo variable/u</th>
                    <th class="r">Margen contribución</th>
                    <th class="r">PE (asumiendo costos fijos proporcionales)</th>
                    <th class="r">Vendidas este mes</th>
                    <th>¿Rentable?</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($productos_mapa as $p):
                $precio = (float)$p['precio_venta'];
                $cv_u   = (float)$p['costo_ing'] + $var_directo_u_cap;
                $mc_u   = $precio - $cv_u;
                $costos_fijos_prods = count($productos_mapa) > 0 ? $total_costos_fijos / count($productos_mapa) : $total_costos_fijos;
                $pe_prod = $mc_u > 0 ? ceil($costos_fijos_prods / $mc_u) : '—';
                $vend    = 0;
                foreach ($ventas_mes as $v) {
                    if ((int)$v['producto_id'] === (int)$p['id']) { $vend = (int)$v['unidades_vendidas']; break; }
                }
                $rentable = is_numeric($pe_prod) && $vend >= $pe_prod;
            ?>
            <tr>
                <td>
                    <strong><?= htmlspecialchars($p['nombre']) ?></strong>
                    <?php if (!empty($p['nombre2'])): ?>
                    <div style="font-size:11px;color:var(--g5)"><?= htmlspecialchars($p['nombre2']) ?></div>
                    <?php endif; ?>
                </td>
                <td class="r">$<?= number_format($precio,0,',','.') ?></td>
                <td class="r">$<?= number_format($cv_u,0,',','.') ?></td>
                <td class="r"><strong>$<?= number_format($mc_u,0,',','.') ?></strong> (<?= $precio>0?round($mc_u/$precio*100,1):0 ?>%)</td>
                <td class="r"><?= is_numeric($pe_prod) ? number_format($pe_prod,0,',','.') : '—' ?> u</td>
                <td class="r"><?= $vend ?> u</td>
                <td>
                    <?php if (is_numeric($pe_prod)): ?>
                    <span class="badge <?= $rentable ? 'badge-ok' : 'badge-bad' ?>">
                        <?= $rentable ? '✓ Sí' : '✗ No' ?>
                    </span>
                    <?php else: ?>
                    <span class="badge badge-warn">Sin precio</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- ══ 3. ANÁLISIS HISTÓRICO DE VENTAS ══════════════════════════════════════ -->
    <div class="section">
        <div class="section-title">
            Análisis histórico — últimos 90 días
            <span>Promedio: <?= $promedio_diario_total ?> u/día · Proyección: <?= number_format($proyeccion_mensual,0,',','.') ?> u/mes</span>
        </div>

        <!-- Comparar proyección vs PE -->
        <?php if ($pe_global > 0): ?>
        <div class="<?= $proyeccion_mensual >= $pe_global ? 'alert-ok' : 'alert-warn' ?>" style="margin-bottom:16px">
            <?php if ($proyeccion_mensual >= $pe_global): ?>
            ✓ Con el ritmo histórico de ventas (<?= $promedio_diario_total ?>/día), proyectás <strong><?= number_format($proyeccion_mensual,0,',','.') ?> u/mes</strong>, que supera el PE (<?= number_format($pe_global,0,',','.') ?> u). Recomendación de producción diaria: <strong><?= round($promedio_diario_total * 1.1, 0) ?> u/día</strong> (+10% buffer).
            <?php else: ?>
            ⚠ Con el ritmo histórico de ventas (<?= $promedio_diario_total ?>/día), proyectás <strong><?= number_format($proyeccion_mensual,0,',','.') ?> u/mes</strong>, pero el PE es <strong><?= number_format($pe_global,0,',','.') ?> u</strong>. Para ser rentable necesitás vender <strong><?= round($pe_global / 21.75, 1) ?> u/día</strong>.
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Tabla de sugerencia de producción por producto -->
        <?php if (!empty($productos_hist)): ?>
        <table class="tbl" style="margin-bottom:16px">
            <thead>
                <tr>
                    <th>Producto</th>
                    <th class="r">Total vendido (90 días)</th>
                    <th class="r">Promedio diario</th>
                    <th class="r">Promedio semanal</th>
                    <th class="r">Proyección mensual</th>
                    <th class="r">Producción sugerida/día</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($productos_hist as $pid => $total_90):
                $nombre_p  = $productos_mapa[$pid]['nombre']  ?? 'Producto #'.$pid;
                $nombre2_p = $productos_mapa[$pid]['nombre2'] ?? null;
                $prom_dia = round($total_90 / max(1, $dias_con_ventas), 1);
                $prom_sem = round($prom_dia * 7, 1);
                $proy_mes = round($prom_dia * 21.75, 0);
                $sugerida = round($prom_dia * 1.15, 1); // +15% buffer
            ?>
            <tr>
                <td>
                    <strong><?= htmlspecialchars($nombre_p) ?></strong>
                    <?php if (!empty($nombre2_p)): ?>
                    <div style="font-size:11px;color:var(--g5)"><?= htmlspecialchars($nombre2_p) ?></div>
                    <?php endif; ?>
                </td>
                <td class="r"><?= number_format($total_90,0,',','.') ?> u</td>
                <td class="r"><?= $prom_dia ?> u</td>
                <td class="r"><?= $prom_sem ?> u</td>
                <td class="r"><?= number_format($proy_mes,0,',','.') ?> u</td>
                <td class="r">
                    <strong style="color:var(--brand)"><?= $sugerida ?> u</strong>
                    <small style="color:var(--g5)">(+15% buffer)</small>
                </td>
            </tr>
            <?php endforeach; ?>
            <tr class="total-row">
                <td>TOTAL</td>
                <td class="r"><?= number_format(array_sum($productos_hist),0,',','.') ?> u</td>
                <td class="r"><?= $promedio_diario_total ?> u</td>
                <td class="r"><?= round($promedio_diario_total * 7, 1) ?> u</td>
                <td class="r"><?= number_format($proyeccion_mensual,0,',','.') ?> u</td>
                <td class="r"><strong style="color:var(--brand)"><?= round($promedio_diario_total * 1.15, 1) ?> u</strong></td>
            </tr>
            </tbody>
        </table>
        <?php else: ?>
        <p style="color:var(--g5);text-align:center;padding:30px">
            Sin ventas en los últimos 90 días. Registra ventas para ver el análisis histórico.
        </p>
        <?php endif; ?>
    </div>

    <!-- ══ 4. DESGLOSE DE COSTOS DEL PERÍODO ════════════════════════════════════ -->
    <div class="section">
        <div class="section-title">Estructura de costos del período</div>
        <table class="tbl">
            <thead><tr><th>Concepto</th><th>Tipo</th><th class="r">Monto mensual</th><th class="r">Por unidad (cap. <?= number_format($prod_mensual_cap,0,',','.'). ' u' ?>)</th><th class="r">Por unidad (real <?= $total_produccion_real ? number_format($total_produccion_real,0,',','.').'u' : 'sin datos' ?>)</th></tr></thead>
            <tbody>
            <tr>
                <td>Costos indirectos (módulo Costos)</td><td><span class="badge badge-warn">Fijo</span></td>
                <td class="r">$<?= number_format($costos_fijos_mes,0,',','.') ?></td>
                <td class="r">$<?= number_format($costos_fijos_mes/$prod_cap,2,',','.') ?></td>
                <td class="r"><?= $total_produccion_real ? '$'.number_format($costos_fijos_mes/$prod_real_u,2,',','.') : '—' ?></td>
            </tr>
            <tr>
                <td>Depreciación activos</td><td><span class="badge badge-warn">Fijo</span></td>
                <td class="r">$<?= number_format($dep_mensual,0,',','.') ?></td>
                <td class="r">$<?= number_format($dep_mensual/$prod_cap,2,',','.') ?></td>
                <td class="r"><?= $total_produccion_real ? '$'.number_format($dep_mensual/$prod_real_u,2,',','.') : '—' ?></td>
            </tr>
            <tr>
                <td>Nómina indirecta (admin/soporte)</td><td><span class="badge badge-warn">Fijo</span></td>
                <td class="r">$<?= number_format($nomina_indirecta,0,',','.') ?></td>
                <td class="r">$<?= number_format($nomina_indirecta/$prod_cap,2,',','.') ?></td>
                <td class="r"><?= $total_produccion_real ? '$'.number_format($nomina_indirecta/$prod_real_u,2,',','.') : '—' ?></td>
            </tr>
            <tr>
                <td>Nómina directa (producción)</td><td><span class="badge badge-ok">Variable</span></td>
                <td class="r">$<?= number_format($nomina_directa,0,',','.') ?></td>
                <td class="r">$<?= number_format($nomina_directa/$prod_cap,2,',','.') ?></td>
                <td class="r"><?= $total_produccion_real ? '$'.number_format($nomina_directa/$prod_real_u,2,',','.') : '—' ?></td>
            </tr>
            <tr class="total-row">
                <td colspan="2">TOTAL COSTOS FIJOS + NÓMINA</td>
                <td class="r">$<?= number_format($total_costos_fijos,0,',','.') ?></td>
                <td class="r">$<?= number_format($total_costos_fijos/$prod_cap,2,',','.') ?></td>
                <td class="r"><?= $total_produccion_real ? '$'.number_format($total_costos_fijos/$prod_real_u,2,',','.') : '—' ?></td>
            </tr>
            </tbody>
        </table>
        <p style="font-size:11px;color:var(--g5);margin-top:8px">
            * El costo de ingredientes (variable) se muestra en la tabla de costeo por producto.
            Los costos fijos se dividen por la cantidad de unidades producidas.
        </p>
    </div>

</main>
</body>
</html>
