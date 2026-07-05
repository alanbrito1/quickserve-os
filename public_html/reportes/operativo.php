<?php
/**
 * public_html/reportes/operativo.php
 * Reporte Operativo: Inventario + Producción + Stock Terminado + Activos + Obsequios/Desechos.
 * Exporta a .xlsx con hojas: "Inventario", "Activos", "Stock Terminado", "Producción",
 * y opcionalmente "Obsequios y Desechos" (requiere migración 026).
 */

require_once __DIR__ . '/../app/middleware/auth_check.php';
require_once __DIR__ . '/../app/models/InsumoModel.php';
require_once __DIR__ . '/../app/models/ActivoModel.php';
require_once __DIR__ . '/../app/helpers/XlsxWriter.php';

permiso_requerir('reportes', 'solo_ver');

// ── Período para producción ───────────────────────────────────────────────────
$hoy         = date('Y-m-d');
$mes         = max(1, min(12, (int)($_GET['mes'] ?? date('n'))));
$anio        = (int)($_GET['anio'] ?? date('Y'));
$mes_inicio  = sprintf('%04d-%02d-01', $anio, $mes);
$mes_fin     = date('Y-m-t', strtotime($mes_inicio));
$meses_es    = [1=>'Ene',2=>'Feb',3=>'Mar',4=>'Abr',5=>'May',6=>'Jun',
               7=>'Jul',8=>'Ago',9=>'Sep',10=>'Oct',11=>'Nov',12=>'Dic'];

$insumos = InsumoModel::todos_con_estado();
$activos = ActivoModel::todos();
$dep_dia = ActivoModel::costo_diario_total();

// ── Stock de producto terminado ───────────────────────────────────────────────
// nombre2 incluido para el Excel y la vista HTML del stock de producto terminado
$productos_stock = db()->query(
    "SELECT nombre, nombre2, categoria, stock_disponible, stock_minimo, precio_venta,
            IFNULL(costo_calculado, 0) AS costo_calculado
     FROM productos WHERE activo = 1
     ORDER BY nombre"
)->fetchAll();

// ── Producción del período (lotes activos) ────────────────────────────────────
// nombre2 incluido para mostrarlo junto al nombre del producto en el reporte
$stmt_lotes = db()->prepare(
    "SELECT pl.fecha_produccion, p.nombre AS producto, p.nombre2 AS producto_nombre2,
            pl.cantidad, pl.costo_unitario, pl.estado,
            u.nombre AS usuario_nombre
     FROM produccion_lotes pl
     JOIN productos p ON p.id = pl.producto_id
     LEFT JOIN usuarios u ON u.id = pl.created_by
     WHERE pl.fecha_produccion BETWEEN :ini AND :fin
     ORDER BY pl.fecha_produccion DESC, p.nombre"
);
$stmt_lotes->execute([':ini' => $mes_inicio, ':fin' => $mes_fin]);
$lotes_periodo = $stmt_lotes->fetchAll();

$total_producido   = array_sum(array_map(fn($l) => $l['estado']==='activo' ? (int)$l['cantidad'] : 0, $lotes_periodo));
$costo_produccion  = 0;
foreach ($lotes_periodo as $l) {
    if ($l['estado']==='activo' && $l['costo_unitario'])
        $costo_produccion += (float)$l['costo_unitario'] * (int)$l['cantidad'];
}

// ── Ajustes de stock y ventas obsequio del período ────────────────────────────
// Consultados aquí (antes del bloque export) para que estén disponibles tanto
// en el Excel como en la vista HTML.
// Envueltos en try-catch: si migración 026 no está aplicada el reporte sigue
// funcionando sin la sección de obsequios/desechos.
$ajustes_periodo = [];
$obsequios_venta = [];
try {
    $stmt_aj = db()->prepare(
        "SELECT aj.fecha_ajuste, aj.tipo, aj.cantidad, aj.motivo,
                p.nombre AS producto, p.precio_venta,
                u.nombre AS usuario
         FROM ajustes_stock aj
         JOIN productos p ON p.id = aj.producto_id
         LEFT JOIN usuarios u ON u.id = aj.created_by
         WHERE DATE(aj.fecha_ajuste) BETWEEN :ini AND :fin
         ORDER BY aj.fecha_ajuste DESC"
    );
    $stmt_aj->execute([':ini' => $mes_inicio, ':fin' => $mes_fin]);
    $ajustes_periodo = $stmt_aj->fetchAll();

    $stmt_obs = db()->prepare(
        "SELECT v.fecha_venta, v.total, v.notas,
                IFNULL(c.nombre, 'Sin especificar') AS cliente,
                GROUP_CONCAT(p.nombre ORDER BY p.nombre SEPARATOR ', ') AS productos,
                SUM(vd.cantidad) AS total_unidades,
                u.nombre AS cajero
         FROM ventas v
         LEFT JOIN clientes c ON c.id = v.cliente_id
         LEFT JOIN venta_detalles vd ON vd.venta_id = v.id
         LEFT JOIN productos p ON p.id = vd.producto_id
         LEFT JOIN usuarios u ON u.id = v.created_by
         WHERE v.metodo_pago = 'obsequio'
           AND DATE(v.fecha_venta) BETWEEN :ini AND :fin
           AND v.estado != 'anulada'
         GROUP BY v.id
         ORDER BY v.fecha_venta DESC"
    );
    $stmt_obs->execute([':ini' => $mes_inicio, ':fin' => $mes_fin]);
    $obsequios_venta = $stmt_obs->fetchAll();
} catch (\Exception $e) {
    // Migración 026 pendiente — la sección se omite silenciosamente
}

// ── Historial de turnos del período (mig.037, opcional) ─────────────────────
$turnos_periodo = [];
try {
    $tiene_tc = (int)db()->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='turnos_caja' AND COLUMN_NAME='id'"
    )->fetchColumn() > 0;
    if ($tiene_tc) {
        $stmt_tc = db()->prepare(
            "SELECT tc.fecha, tc.fondo_inicial, tc.estado, tc.notas_apertura,
                    u.nombre  AS usuario_apertura,
                    uc.nombre AS usuario_cierre,
                    tc.fecha_apertura, tc.fecha_cierre,
                    COALESCE(
                        (SELECT SUM(v.total) FROM ventas v
                         WHERE DATE(v.fecha_venta)=tc.fecha
                           AND v.metodo_pago='efectivo'
                           AND v.estado IN ('completada','pendiente_pago')),
                    0) AS efectivo_cobrado
             FROM turnos_caja tc
             LEFT JOIN usuarios u  ON u.id  = tc.usuario_apertura
             LEFT JOIN usuarios uc ON uc.id = tc.usuario_cierre
             WHERE tc.fecha BETWEEN :ini AND :fin
             ORDER BY tc.fecha DESC"
        );
        $stmt_tc->execute([':ini' => $mes_inicio, ':fin' => $mes_fin]);
        $turnos_periodo = $stmt_tc->fetchAll();
    }
} catch (\Exception $e) {}

// ── EXPORTAR EXCEL ──────────────────────────────────────────────────────────
if (isset($_GET['export'])) {
    $w = new XlsxWriter();

    // Hoja 1: Inventario
    $w->setSheet('Inventario');
    $w->addRow([nombre_negocio() . ' — Inventario de Insumos'], true);
    $w->addRow(['Generado: ' . date('d/m/Y H:i')]);
    $w->addEmptyRow();
    $w->addRow(['Insumo', 'Unidad', 'Stock Actual', 'Stock Seguridad', 'Estado', 'Costo/u', 'Valor en Stock', 'Proveedor'], true);

    $valor_total = 0;
    foreach ($insumos as $i) {
        $valor = round((float)$i['stock_actual'] * (float)$i['costo_actual'], 2);
        $valor_total += $valor;
        $w->addRow([
            $i['nombre'],
            $i['unidad_medida'],
            (float)$i['stock_actual'],
            (float)$i['stock_seguridad'],
            $i['estado'],
            (float)$i['costo_actual'],
            $valor,
            $i['proveedor_nombre'] ?? '',
        ]);
    }
    $w->addEmptyRow();
    $w->addRow(['', '', '', '', '', 'VALOR TOTAL INVENTARIO', $valor_total, ''], false, true);

    // Hoja 2: Activos
    $w->setSheet('Activos');
    $w->addRow([nombre_negocio() . ' — Activos Fijos'], true);
    $w->addRow(['Generado: ' . date('d/m/Y H:i') . '  |  Depreciación diaria total: $' . fmt_cantidad($dep_dia, 2)]);
    $w->addEmptyRow();
    $w->addRow(['Activo', 'Descripción', 'Costo Inicial', 'Fecha Adq.', 'Vida Útil (m)', 'Dep. Mensual', 'Dep. Diaria', 'Meses Rest.', 'Estado Vida'], true);

    $total_dep_mens = 0;
    foreach ($activos as $a) {
        if (!$a['activo']) continue;
        $total_dep_mens += (float)$a['depreciacion_mensual'];
        $w->addRow([
            $a['nombre'],
            $a['descripcion'] ?? '',
            (float)$a['costo_inicial'],
            date('d/m/Y', strtotime($a['fecha_adquisicion'])),
            (int)$a['vida_util_meses'],
            (float)$a['depreciacion_mensual'],
            (float)$a['depreciacion_diaria'],
            (int)$a['meses_restantes'],
            $a['estado_vida'],
        ]);
    }
    $w->addEmptyRow();
    $w->addRow(['', '', '', '', '', $total_dep_mens, $dep_dia, '', ''], false, true);

    // Hoja 3: Stock de producto terminado
    $w->setSheet('Stock Terminado');
    $w->addRow([nombre_negocio() . ' — Stock de Producto Terminado'], true);
    $w->addRow(['Generado: ' . date('d/m/Y H:i')]);
    $w->addEmptyRow();
    $w->addRow(['Producto', 'Nombre complementario', 'Categoría', 'Stock Disponible', 'Stock Mínimo', 'Estado', 'Precio Venta', 'Costo Calculado', 'Margen Est.'], true);
    foreach ($productos_stock as $p) {
        $estado_st = (int)$p['stock_minimo'] > 0 && (int)$p['stock_disponible'] < (int)$p['stock_minimo'] ? 'Bajo' : ((int)$p['stock_disponible'] > 0 ? 'OK' : 'Sin stock');
        $margen    = (float)$p['precio_venta'] > 0 ? round(((float)$p['precio_venta'] - (float)$p['costo_calculado']) / (float)$p['precio_venta'] * 100, 1) . '%' : '—';
        $w->addRow([
            $p['nombre'], $p['nombre2'] ?? '', ucfirst($p['categoria']),
            (int)$p['stock_disponible'], (int)$p['stock_minimo'],
            $estado_st, (float)$p['precio_venta'], round((float)$p['costo_calculado']), $margen,
        ]);
    }

    // Hoja 4: Producción del período
    $w->setSheet('Producción ' . $meses_es[$mes] . ' ' . $anio);
    $w->addRow([nombre_negocio() . ' — Producción ' . $meses_es[$mes] . ' ' . $anio], true);
    $w->addRow(['Total producido: ' . $total_producido . ' unidades']);
    $w->addEmptyRow();
    $w->addRow(['Fecha', 'Producto', 'Nombre complementario', 'Cantidad', 'Costo/u', 'Costo total', 'Estado', 'Registrado por'], true);
    foreach ($lotes_periodo as $l) {
        $ct = $l['costo_unitario'] ? round((float)$l['costo_unitario'] * (int)$l['cantidad']) : '—';
        $w->addRow([
            date('d/m/Y', strtotime($l['fecha_produccion'])),
            $l['producto'], $l['producto_nombre2'] ?? '', (int)$l['cantidad'],
            $l['costo_unitario'] ? round((float)$l['costo_unitario']) : '—',
            $ct, ucfirst($l['estado']), $l['usuario_nombre'] ?? '',
        ]);
    }
    $w->addRow(['', 'TOTAL', $total_producido, '', round($costo_produccion), '', ''], false, true);

    // Hoja 5: Obsequios y Desechos (solo si la migración 026 está aplicada)
    if (!empty($ajustes_periodo) || !empty($obsequios_venta)) {
    $w->setSheet('Obsequios y Desechos');
    $w->addRow([nombre_negocio() . ' — Obsequios y Desechos ' . $meses_es[$mes] . ' ' . $anio], true);
    $w->addEmptyRow();

    // Sección A: ajustes de stock (de productos ya en inventario)
    $w->addRow(['A. Ajustes de Stock (Regalar / Desechar desde inventario)'], true);
    $w->addRow(['Fecha', 'Tipo', 'Producto', 'Cantidad', 'Valor estimado', 'Motivo', 'Registrado por'], true);
    $total_aj_val = 0;
    foreach ($ajustes_periodo as $aj) {
        // Precio venta como valor de referencia (costo real no disponible aquí)
        $val_ref = (float)$aj['precio_venta'] * (int)$aj['cantidad'];
        $total_aj_val += $val_ref;
        $w->addRow([
            date('d/m/Y H:i', strtotime($aj['fecha_ajuste'])),
            ucfirst($aj['tipo']),
            $aj['producto'],
            (int)$aj['cantidad'],
            $val_ref,
            $aj['motivo'] ?? '',
            $aj['usuario'] ?? '',
        ]);
    }
    if (!empty($ajustes_periodo)) {
        $w->addRow(['', 'TOTAL', '', array_sum(array_column($ajustes_periodo, 'cantidad')), $total_aj_val, '', ''], false, true);
    }
    $w->addEmptyRow();

    // Sección B: ventas obsequio (pasaron por el POS)
    $w->addRow(['B. Ventas Obsequio (registradas en el POS)'], true);
    $w->addRow(['Fecha', 'Cliente', 'Productos', 'Unidades', 'Valor', 'Notas', 'Cajero'], true);
    $total_obs_val = 0;
    foreach ($obsequios_venta as $ob) {
        $total_obs_val += (float)$ob['total'];
        $w->addRow([
            date('d/m/Y H:i', strtotime($ob['fecha_venta'])),
            $ob['cliente'],
            $ob['productos'] ?? '',
            (int)$ob['total_unidades'],
            (float)$ob['total'],
            $ob['notas'] ?? '',
            $ob['cajero'] ?? '',
        ]);
    }
    if (!empty($obsequios_venta)) {
        $w->addRow(['', 'TOTAL', '', array_sum(array_column($obsequios_venta, 'total_unidades')), $total_obs_val, '', ''], false, true);
    }
    } // fin if datos obsequio

    // Hoja 6: Turnos de Caja (solo si mig.037 y hay datos)
    if (!empty($turnos_periodo)) {
        $w->setSheet('Turnos de Caja');
        $w->addRow([nombre_negocio() . ' — Turnos de Caja ' . $meses_es[$mes] . ' ' . $anio], true);
        $w->addEmptyRow();
        $w->addRow(['Fecha', 'Abre', 'Fondo Inicial', 'Efectivo Cobrado', 'Total en Caja', 'Diferencia', 'Estado', 'Notas'], true);
        $total_fondo = 0;
        $total_efec  = 0;
        foreach ($turnos_periodo as $tc) {
            $fondo = (float)$tc['fondo_inicial'];
            $efec  = (float)$tc['efectivo_cobrado'];
            $total = $fondo + $efec;
            $diff  = $efec; // Efectivo generado en el día
            $total_fondo += $fondo;
            $total_efec  += $efec;
            $w->addRow([
                date('d/m/Y', strtotime($tc['fecha'])),
                $tc['usuario_apertura'] ?? '',
                $fondo,
                $efec,
                $total,
                $diff,
                ucfirst($tc['estado']),
                $tc['notas_apertura'] ?? '',
            ]);
        }
        $w->addEmptyRow();
        $w->addRow(['TOTAL', '', $total_fondo, $total_efec, $total_fondo + $total_efec, $total_efec, '', ''], false, true);
    }

    $w->download(slug_negocio() . '_Operativo_' . $meses_es[$mes] . '_' . $anio . '.xlsx');
}

// Cálculos para la vista HTML
$valor_inventario = array_sum(array_map(fn($i) => (float)$i['stock_actual'] * (float)$i['costo_actual'], $insumos));
$bajos    = count(array_filter($insumos, fn($i) => $i['estado'] === 'bajo'));
$agotados = count(array_filter($insumos, fn($i) => $i['estado'] === 'agotado'));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reporte Operativo — <?= APP_NAME ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root { --brand:#e94f37; --dark:#111827; --g2:#374151; --g5:#6b7280; --g8:#d1d5db; --g9:#f3f4f6; --white:#fff; --green:#059669; --yellow:#d97706; }
        body { font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; background:var(--g9); min-height:100vh; color:var(--dark); }
        .main { padding:16px 14px; max-width:960px; margin:0 auto; }
        .top-actions { display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; }
        .page-title { font-size:20px; font-weight:800; }
        .btn-xl { padding:10px 18px; background:#16a34a; color:var(--white); border:none; border-radius:10px; font-size:13px; font-weight:700; cursor:pointer; text-decoration:none; }
        .stats { display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:16px; }
        @media(max-width:600px){ .stats { grid-template-columns:1fr 1fr; } }
        .stat { background:var(--white); border-radius:14px; padding:14px; box-shadow:0 1px 4px rgba(0,0,0,.06); }
        .stat-n { font-size:20px; font-weight:800; }
        .stat-l { font-size:11px; color:var(--g5); text-transform:uppercase; letter-spacing:.4px; }
        .section-title { font-size:16px; font-weight:800; margin:20px 0 10px; }
        .card { background:var(--white); border-radius:14px; box-shadow:0 1px 4px rgba(0,0,0,.06); overflow:hidden; overflow-x:auto; margin-bottom:16px; }
        table { width:100%; border-collapse:collapse; }
        th { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--g5); padding:10px 14px; background:var(--g9); border-bottom:1px solid var(--g8); text-align:left; }
        th.r, td.r { text-align:right; }
        td { padding:10px 14px; border-bottom:1px solid var(--g9); font-size:13px; }
        tr:last-child td { border-bottom:none; }
        @media(max-width:600px){ .hide-m { display:none; } }
        .badge { font-size:10px; font-weight:700; padding:2px 7px; border-radius:20px; }
        .b-ok  { background:#d1fae5; color:#065f46; }
        .b-bajo{ background:#fef3c7; color:#92400e; }
        .b-ago { background:#fee2e2; color:#991b1b; }
        .b-nvo { background:#d1fae5; color:#065f46; }
        .b-med { background:#fef3c7; color:#92400e; }
        .b-cri { background:#fed7aa; color:#9a3412; }
        .b-dep { background:#f3f4f6; color:#6b7280; }

        /* ════════════════════════════════════════════════════════════════
           RESPONSIVE — REPORTE OPERATIVO
           ════════════════════════════════════════════════════════════════ */
        /* Tablas con scroll horizontal por defecto */
        .card { overflow-x:auto; -webkit-overflow-scrolling:touch; }

        /* xs: < 480px */
        @media (max-width:479px) {
            .main { padding:12px 10px 40px; }
            .top-actions { flex-direction:column; align-items:stretch; gap:8px; }
            .btn-xl { width:100%; min-height:44px; text-align:center; }
            .stats { grid-template-columns:1fr 1fr !important; gap:8px; }
            .stat-n { font-size:16px !important; }
            table { min-width:400px; }
        }
        /* sm: 480-639px */
        @media (min-width:480px) and (max-width:639px) {
            .stats { grid-template-columns:repeat(2,1fr) !important; }
            table { min-width:480px; }
        }
        /* ≥1600px */
        @media (min-width:1600px) {
            .main { max-width:1300px; }
            .stat-n { font-size:24px !important; }
            th, td { padding:12px 16px !important; font-size:14px !important; }
        }
        /* TV ≥1920px */
        @media (min-width:1920px) {
            .main { max-width:1600px; }
        }
    </style>
</head>
<body>
<?php $nav_activo = 'reportes'; include __DIR__ . '/../app/views/nav.php'; ?>

<!-- header reemplazado por nav.php -->
<main class="main">

    <div class="top-actions">
        <h1 class="page-title">Reporte Operativo</h1>
        <a href="?mes=<?= $mes ?>&anio=<?= $anio ?>&export=1" class="btn-xl">⬇ Exportar Excel</a>
    </div>

    <!-- Stats -->
    <div class="stats">
        <div class="stat"><div class="stat-n">$<?= fmt_moneda($valor_inventario) ?></div><div class="stat-l">Valor Inventario</div></div>
        <div class="stat"><div class="stat-n" style="color:var(--yellow)"><?= $bajos ?></div><div class="stat-l">Insumos Bajos</div></div>
        <div class="stat"><div class="stat-n" style="color:var(--brand)"><?= $agotados ?></div><div class="stat-l">Agotados</div></div>
        <div class="stat"><div class="stat-n" style="color:var(--brand)">$<?= fmt_cantidad($dep_dia, 2) ?></div><div class="stat-l">Dep. Diaria</div></div>
    </div>

    <!-- Inventario -->
    <h2 class="section-title">Inventario de Insumos</h2>
    <div class="card">
        <table>
            <thead><tr>
                <th>Insumo</th>
                <th class="r">Stock</th>
                <th class="r hide-m">Seguridad</th>
                <th class="r hide-m">Costo/u</th>
                <th class="r hide-m">Valor Stock</th>
                <th>Estado</th>
            </tr></thead>
            <tbody>
                <?php foreach ($insumos as $i):
                    $bc = $i['estado'] === 'ok' ? 'b-ok' : ($i['estado'] === 'bajo' ? 'b-bajo' : 'b-ago');
                ?>
                <tr>
                    <td><?= htmlspecialchars($i['nombre']) ?></td>
                    <td class="r"><?= fmt_cantidad($i['stock_actual'], 2) ?> <small style="color:var(--g5)"><?= htmlspecialchars($i['unidad_medida']) ?></small></td>
                    <td class="r hide-m"><?= fmt_cantidad($i['stock_seguridad'], 2) ?></td>
                    <td class="r hide-m">$<?= fmt_moneda($i['costo_actual']) ?></td>
                    <td class="r hide-m">$<?= fmt_moneda((float)$i['stock_actual']*(float)$i['costo_actual']) ?></td>
                    <td><span class="badge <?= $bc ?>"><?= $i['estado'] ?></span></td>
                </tr>
                <?php endforeach; ?>
                <tr style="background:var(--g9); font-weight:800">
                    <td colspan="4">VALOR TOTAL INVENTARIO</td>
                    <td class="r hide-m">$<?= fmt_moneda($valor_inventario) ?></td>
                    <td></td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Activos -->
    <h2 class="section-title">Activos Fijos en Uso</h2>
    <div class="card">
        <table>
            <thead><tr>
                <th>Activo</th>
                <th class="r hide-m">Costo</th>
                <th class="r hide-m">Dep. Mensual</th>
                <th class="r">Dep. Diaria</th>
                <th class="r hide-m">Meses Rest.</th>
                <th>Estado</th>
            </tr></thead>
            <tbody>
                <?php foreach ($activos as $a):
                    if (!$a['activo']) continue;
                    $bc = ['nuevo'=>'b-nvo','medio'=>'b-med','critico'=>'b-cri','depreciado'=>'b-dep'][$a['estado_vida']] ?? 'b-ok';
                ?>
                <tr>
                    <td><?= htmlspecialchars($a['nombre']) ?></td>
                    <td class="r hide-m">$<?= fmt_moneda($a['costo_inicial']) ?></td>
                    <td class="r hide-m">$<?= fmt_moneda($a['depreciacion_mensual']) ?></td>
                    <td class="r">$<?= fmt_cantidad($a['depreciacion_diaria'], 2) ?></td>
                    <td class="r hide-m"><?= $a['meses_restantes'] ?> m</td>
                    <td><span class="badge <?= $bc ?>"><?= $a['estado_vida'] ?></span></td>
                </tr>
                <?php endforeach; ?>
                <tr style="background:var(--g9); font-weight:800">
                    <td colspan="3">TOTAL DEPRECIACIÓN DIARIA</td>
                    <td class="r">$<?= fmt_cantidad($dep_dia, 2) ?></td>
                    <td colspan="2"></td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Obsequios y Desechos -->
    <h2 class="section-title">🎁 Obsequios y Desechos — <?= $meses_es[$mes] . ' ' . $anio ?></h2>

    <?php if (empty($obsequios_venta) && empty($ajustes_periodo)): ?>
    <div class="card" style="padding:20px;color:var(--g5);font-size:14px">Sin registros de obsequios o desechos en este período.</div>
    <?php else: ?>

    <?php if (!empty($obsequios_venta)): ?>
    <p style="font-size:13px;font-weight:700;color:var(--g2);margin-bottom:6px">A. Ventas obsequio (POS)</p>
    <div class="card">
        <table>
            <thead><tr>
                <th>Fecha</th>
                <th>Cliente</th>
                <th class="hide-m">Productos</th>
                <th class="r">Unid.</th>
                <th class="r">Valor ref.</th>
                <th class="hide-m">Cajero</th>
            </tr></thead>
            <tbody>
            <?php
            $obs_total_u = 0; $obs_total_v = 0;
            foreach ($obsequios_venta as $ob):
                $obs_total_u += (int)$ob['total_unidades'];
                $obs_total_v += (float)$ob['total'];
            ?>
            <tr>
                <td><?= date('d/m H:i', strtotime($ob['fecha_venta'])) ?></td>
                <td><?= htmlspecialchars($ob['cliente']) ?></td>
                <td class="hide-m" style="font-size:12px;color:var(--g2)"><?= htmlspecialchars($ob['productos'] ?? '—') ?></td>
                <td class="r"><?= (int)$ob['total_unidades'] ?></td>
                <td class="r">$<?= fmt_moneda((float)$ob['total']) ?></td>
                <td class="hide-m" style="font-size:12px"><?= htmlspecialchars($ob['cajero'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
            <tr style="background:var(--g9);font-weight:800">
                <td colspan="3">TOTAL OBSEQUIOS POS</td>
                <td class="r"><?= $obs_total_u ?></td>
                <td class="r">$<?= fmt_moneda($obs_total_v) ?></td>
                <td class="hide-m"></td>
            </tr>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if (!empty($ajustes_periodo)): ?>
    <p style="font-size:13px;font-weight:700;color:var(--g2);margin-bottom:6px">B. Ajustes de stock (desde inventario)</p>
    <div class="card">
        <table>
            <thead><tr>
                <th>Fecha</th>
                <th>Tipo</th>
                <th>Producto</th>
                <th class="r">Cant.</th>
                <th class="hide-m">Motivo</th>
                <th class="hide-m">Registrado por</th>
            </tr></thead>
            <tbody>
            <?php
            $aj_total = 0;
            foreach ($ajustes_periodo as $aj):
                $aj_total += (int)$aj['cantidad'];
                $badge_color = $aj['tipo'] === 'obsequio' ? '#fce7f3;color:#9d174d' : '#f3f4f6;color:#374151';
            ?>
            <tr>
                <td><?= date('d/m H:i', strtotime($aj['fecha_ajuste'])) ?></td>
                <td><span class="badge" style="background:<?= $badge_color ?>"><?= ucfirst($aj['tipo']) ?></span></td>
                <td><?= htmlspecialchars($aj['producto']) ?></td>
                <td class="r"><?= (int)$aj['cantidad'] ?></td>
                <td class="hide-m" style="font-size:12px;color:var(--g2)"><?= htmlspecialchars($aj['motivo'] ?? '—') ?></td>
                <td class="hide-m" style="font-size:12px"><?= htmlspecialchars($aj['usuario'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
            <tr style="background:var(--g9);font-weight:800">
                <td colspan="3">TOTAL AJUSTADO</td>
                <td class="r"><?= $aj_total ?></td>
                <td colspan="2" class="hide-m"></td>
            </tr>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php endif; ?>

    <!-- Turnos de Caja -->
    <?php if (!empty($turnos_periodo)): ?>
    <h2 class="section-title">🏪 Turnos de Caja — <?= $meses_es[$mes] . ' ' . $anio ?></h2>
    <div class="card">
        <table>
            <thead><tr>
                <th>Fecha</th>
                <th class="hide-m">Abre</th>
                <th class="r">Fondo</th>
                <th class="r">Efectivo cobrado</th>
                <th class="r">Total en caja</th>
                <th style="text-align:center">Estado</th>
                <th class="hide-m">Notas</th>
            </tr></thead>
            <tbody>
            <?php
            $tc_fondo = 0; $tc_efec = 0;
            foreach ($turnos_periodo as $tc):
                $fondo = (float)$tc['fondo_inicial'];
                $efec  = (float)$tc['efectivo_cobrado'];
                $total = $fondo + $efec;
                $tc_fondo += $fondo; $tc_efec += $efec;
            ?>
            <tr>
                <td>
                    <a href="<?= APP_BASE ?>/ventas/cierre.php?fecha=<?= $tc['fecha'] ?>"
                       style="color:var(--brand);font-weight:600;text-decoration:none">
                        <?= date('d/m/Y', strtotime($tc['fecha'])) ?>
                    </a>
                </td>
                <td class="hide-m" style="font-size:12px"><?= htmlspecialchars($tc['usuario_apertura'] ?? '—') ?></td>
                <td class="r">$<?= fmt_moneda($fondo) ?></td>
                <td class="r" style="color:var(--green);font-weight:700">$<?= fmt_moneda($efec) ?></td>
                <td class="r" style="font-weight:800">$<?= fmt_moneda($total) ?></td>
                <td style="text-align:center">
                    <span style="font-size:10px;font-weight:700;padding:2px 8px;border-radius:99px;
                                 <?= $tc['estado']==='abierto'
                                     ? 'background:#d1fae5;color:#065f46'
                                     : 'background:#f3f4f6;color:#6b7280' ?>">
                        <?= $tc['estado'] === 'abierto' ? '● Abierto' : 'Cerrado' ?>
                    </span>
                </td>
                <td class="hide-m" style="font-size:12px;color:var(--g5)">
                    <?= htmlspecialchars($tc['notas_apertura'] ?? '') ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <tr style="background:var(--g9);font-weight:800">
                <td colspan="2">TOTAL</td>
                <td class="r">$<?= fmt_moneda($tc_fondo) ?></td>
                <td class="r" style="color:var(--green)">$<?= fmt_moneda($tc_efec) ?></td>
                <td class="r">$<?= fmt_moneda($tc_fondo + $tc_efec) ?></td>
                <td colspan="2"></td>
            </tr>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

</main>
</body>
</html>
