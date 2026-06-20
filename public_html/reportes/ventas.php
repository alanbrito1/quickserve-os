<?php
/**
 * public_html/reportes/ventas.php
 * Reporte de Ventas + Rentabilidad por Producto.
 * Exporta a .xlsx con dos hojas: "Ventas" y "Rentabilidad".
 */

require_once __DIR__ . '/../app/middleware/auth_check.php';
require_once __DIR__ . '/../app/models/VentaModel.php';
require_once __DIR__ . '/../app/models/RecetaModel.php';
require_once __DIR__ . '/../app/models/CostoIndirectoModel.php';
require_once __DIR__ . '/../app/helpers/XlsxWriter.php';

permiso_requerir('reportes', 'solo_ver');

// Filtros de período
$desde = $_GET['desde'] ?? date('Y-m-d', strtotime('-30 days'));
$hasta = $_GET['hasta'] ?? date('Y-m-d');

// Filtro por forma de pago (origen de la venta) — 'todos' = sin filtro.
// Aplica al detalle, stats de cabecera y export; la discriminación de ingresos
// por forma de pago (más abajo) se calcula sobre TODO el período, sin filtrar.
$metodos_filtro_ok = ['efectivo','nequi','daviplata','bancolombia','fiado','obsequio'];
$metodo_filtro = in_array($_GET['metodo'] ?? '', $metodos_filtro_ok, true) ? $_GET['metodo'] : 'todos';

// Filtro solo_propios
$solo_uid = permiso_es_solo_propios('ventas') ? (int)$usuario_activo['id'] : null;
$ventas   = VentaModel::historial($desde, $hasta, $solo_uid);

// Costo fijo unitario: usa costos_indirectos si hay datos, si no configuracion_negocio
$cfg = db()->query(
    "SELECT clave, valor FROM configuracion_negocio
     WHERE clave IN ('costos_fijos_mensuales','produccion_estimada_mensual')"
)->fetchAll(PDO::FETCH_KEY_PAIR);
$costos_indirectos_total = CostoIndirectoModel::total_mensual_activo();
$costos_fijos_legacy     = (float)($cfg['costos_fijos_mensuales'] ?? 0);
$costos_fijos_mensual    = $costos_indirectos_total > 0 ? $costos_indirectos_total : $costos_fijos_legacy;
$prod_mes                = max(1, (float)($cfg['produccion_estimada_mensual'] ?? 2175));
$costo_fijo_u            = $costos_fijos_mensual / $prod_mes;

$rentabilidad = RecetaModel::productos_con_margen($costo_fijo_u);

// Análisis from_stock vs on-demand
$from_stock_stats = db()->prepare(
    "SELECT
        SUM(CASE WHEN vd.from_stock = 1 THEN vd.subtotal ELSE 0 END) AS total_desde_stock,
        SUM(CASE WHEN vd.from_stock = 0 THEN vd.subtotal ELSE 0 END) AS total_demanda,
        SUM(CASE WHEN vd.from_stock = 1 THEN vd.cantidad ELSE 0 END) AS unid_desde_stock,
        SUM(CASE WHEN vd.from_stock = 0 THEN vd.cantidad ELSE 0 END) AS unid_demanda
     FROM venta_detalles vd
     JOIN ventas v ON v.id = vd.venta_id
     WHERE DATE(v.fecha_venta) BETWEEN :desde AND :hasta
       AND v.estado != 'anulada'"
);
$from_stock_stats->execute([':desde' => $desde, ':hasta' => $hasta]);
$fs_row = $from_stock_stats->fetch();

// Etiquetas de método de pago — definidas aquí para que estén disponibles tanto en
// el bloque Excel como en la vista HTML (el bloque Excel se ejecuta y hace exit primero).
$metodo_label = ['efectivo'=>'Efectivo','nequi'=>'Nequi','daviplata'=>'Daviplata',
                 'bancolombia'=>'Bancolombia','fiado'=>'Fiado','obsequio'=>'Obsequio'];

// Ventas por variante (migración 035) — vacío si la migración no está aplicada.
$ventas_variante = [];
try {
    $tiene035v = (int)db()->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='venta_detalles'
           AND COLUMN_NAME='variante_etiqueta'"
    )->fetchColumn() > 0;
    if ($tiene035v) {
        $stmt = db()->prepare(
            "SELECT COALESCE(vd.nombre_snap, p.nombre) AS producto,
                    vd.variante_etiqueta                AS variante,
                    SUM(vd.cantidad)                    AS total_unidades,
                    SUM(vd.subtotal)                    AS total_venta
             FROM venta_detalles vd
             JOIN ventas v    ON v.id = vd.venta_id
             JOIN productos p ON p.id = vd.producto_id
             WHERE DATE(v.fecha_venta) BETWEEN :desde AND :hasta
               AND v.estado != 'anulada'
               AND vd.variante_etiqueta IS NOT NULL
             GROUP BY producto, vd.variante_etiqueta
             ORDER BY producto, total_venta DESC"
        );
        $stmt->execute([':desde' => $desde, ':hasta' => $hasta]);
        $ventas_variante = $stmt->fetchAll();
    }
} catch (\Exception $e) {
    $ventas_variante = [];
}

// Descuentos del período (mig.038) — mapa por venta_id + lista completa para Excel
$descuentos_map           = []; // [venta_id => ['pct' => X, 'valor' => Y]]
$descuentos_lista         = []; // filas completas con cliente/cajero (para hoja Excel)
$tiene038r                = false;
$total_descuentos_periodo = 0.0;
$n_descuentos_periodo     = 0;
try {
    $tiene038r = (int)db()->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ventas'
           AND COLUMN_NAME='descuento_pct'"
    )->fetchColumn() > 0;
    if ($tiene038r) {
        $st = db()->prepare(
            "SELECT v.id, v.fecha_venta, v.total, v.descuento_pct, v.descuento_valor,
                    IFNULL(c.nombre, 'Mostrador') AS cliente, u.nombre AS cajero
             FROM ventas v
             LEFT JOIN clientes c ON c.id = v.cliente_id
             LEFT JOIN usuarios u ON u.id = v.created_by
             WHERE DATE(v.fecha_venta) BETWEEN :desde AND :hasta
               AND v.estado != 'anulada' AND v.descuento_pct > 0
             ORDER BY v.fecha_venta DESC"
        );
        $st->execute([':desde' => $desde, ':hasta' => $hasta]);
        foreach ($st->fetchAll() as $d) {
            $vid = (int)$d['id'];
            $descuentos_map[$vid] = [
                'pct'   => (float)$d['descuento_pct'],
                'valor' => (float)$d['descuento_valor'],
            ];
            $descuentos_lista[] = $d;
            $total_descuentos_periodo += (float)$d['descuento_valor'];
            $n_descuentos_periodo++;
        }
    }
} catch (\Exception $e) {}

// Abonos a fiado recibidos en el período (mig.034 — saldo_anterior/saldo_posterior)
$abonos_lista          = []; // filas completas con cliente/cajero (para hoja Excel)
$tiene034r             = false;
$total_abonos_periodo  = 0.0;
$n_abonos_periodo      = 0;
try {
    $tiene034r = (int)db()->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pagos_fiado'
           AND COLUMN_NAME='saldo_anterior'"
    )->fetchColumn() > 0;

    $st = db()->prepare(
        "SELECT pf.id, pf.created_at, pf.monto, pf.metodo_pago, pf.notas,
                pf.saldo_anterior, pf.saldo_posterior,
                IFNULL(c.nombre, 'Cliente eliminado') AS cliente,
                u.nombre AS registrado_por
         FROM pagos_fiado pf
         LEFT JOIN clientes c ON c.id = pf.cliente_id
         LEFT JOIN usuarios u ON u.id = pf.created_by
         WHERE DATE(pf.created_at) BETWEEN :desde AND :hasta
         ORDER BY pf.created_at DESC"
    );
    $st->execute([':desde' => $desde, ':hasta' => $hasta]);
    foreach ($st->fetchAll() as $a) {
        $abonos_lista[]        = $a;
        $total_abonos_periodo += (float)$a['monto'];
        $n_abonos_periodo++;
    }
} catch (\Exception $e) {}

// ── Método de cobro de fiados (mig.042) ─────────────────────────────────────
// $cobro_map: con qué método se cobró cada venta fiada del período (para la
// columna del detalle/Excel). $ingresos_forma: DISCRIMINA cuánto dinero entró
// por cada forma de pago, separando ventas directas de cobros de fiado, para
// saber exactamente el origen del efectivo/digital recibido.
$tiene042r = false;
$cobro_map = [];                 // [venta_id => metodo_cobro]
$formas_pago      = ['efectivo','nequi','daviplata','bancolombia'];
$ingresos_forma   = [];          // [forma => ['directo'=>x, 'fiado'=>y]]
foreach ($formas_pago as $f) $ingresos_forma[$f] = ['directo' => 0.0, 'fiado' => 0.0];
$total_fiado_cobrado = 0.0;

// Ventas directas por forma de pago (no fiado/obsequio, no anulada), por fecha_venta
$stDir = db()->prepare(
    "SELECT metodo_pago, SUM(total) AS t
     FROM ventas
     WHERE DATE(fecha_venta) BETWEEN :desde AND :hasta
       AND estado != 'anulada'
       AND metodo_pago IN ('efectivo','nequi','daviplata','bancolombia')
     GROUP BY metodo_pago"
);
$stDir->execute([':desde' => $desde, ':hasta' => $hasta]);
foreach ($stDir->fetchAll() as $r) $ingresos_forma[$r['metodo_pago']]['directo'] = (float)$r['t'];

try {
    $tiene042r = (int)db()->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ventas'
           AND COLUMN_NAME='metodo_cobro'"
    )->fetchColumn() > 0;
    if ($tiene042r) {
        // Mapa por venta (las fiadas del período por fecha_venta)
        $stMap = db()->prepare(
            "SELECT id, metodo_cobro FROM ventas
             WHERE DATE(fecha_venta) BETWEEN :desde AND :hasta
               AND metodo_pago = 'fiado' AND metodo_cobro IS NOT NULL"
        );
        $stMap->execute([':desde' => $desde, ':hasta' => $hasta]);
        foreach ($stMap->fetchAll() as $r) $cobro_map[(int)$r['id']] = $r['metodo_cobro'];

        // Cobros de fiado del período (por fecha_pago) agrupados por metodo_cobro
        $stCob = db()->prepare(
            "SELECT metodo_cobro, SUM(total) AS t
             FROM ventas
             WHERE metodo_pago = 'fiado' AND metodo_cobro IS NOT NULL
               AND fecha_pago IS NOT NULL AND DATE(fecha_pago) BETWEEN :desde AND :hasta
               AND estado != 'anulada'
             GROUP BY metodo_cobro"
        );
        $stCob->execute([':desde' => $desde, ':hasta' => $hasta]);
        foreach ($stCob->fetchAll() as $r) {
            if (isset($ingresos_forma[$r['metodo_cobro']])) {
                $ingresos_forma[$r['metodo_cobro']]['fiado'] = (float)$r['t'];
                $total_fiado_cobrado += (float)$r['t'];
            }
        }
    }
} catch (\Exception $e) {}

// Aplicar el filtro de forma de pago al detalle/stats/export (la discriminación
// de arriba ya quedó calculada sobre todo el período).
if ($metodo_filtro !== 'todos') {
    $ventas = array_values(array_filter($ventas, function ($v) use ($metodo_filtro) {
        return $v['metodo_pago'] === $metodo_filtro;
    }));
}

// ── EXPORTAR EXCEL ──────────────────────────────────────────────────────────
if (isset($_GET['export'])) {
    $w = new XlsxWriter();

    // ── Hoja 1: Ventas ──────────────────────────────────────────────────────
    $w->setSheet('Ventas');
    $w->addRow(['ClanDestino ERP — Reporte de Ventas'], true);
    $w->addRow(["Período: $desde  al  $hasta | Generado: " . date('d/m/Y H:i')]);
    $w->addEmptyRow();
    $cols038h = $tiene038r ? ['Desc. %', 'Desc. $'] : [];
    $w->addRow(array_merge(['#', 'Fecha', 'Hora', 'Cliente', 'Items', 'Método Pago', 'Total'], $cols038h, ['Estado', 'Cajero', 'Método Cobro']), true);

    $total_pesos = 0; // solo ingresos reales (excluye obsequio)
    foreach ($ventas as $v) {
        if ($v['estado'] === 'anulada') continue;
        $es_obsequio = $v['metodo_pago'] === 'obsequio';
        $vid038 = (int)$v['id'];
        $row038 = $tiene038r
            ? [$descuentos_map[$vid038]['pct'] ?? 0, $descuentos_map[$vid038]['valor'] ?? 0]
            : [];
        $w->addRow(array_merge([
            $v['id'],
            date('d/m/Y', strtotime($v['fecha_venta'])),
            date('H:i',   strtotime($v['fecha_venta'])),
            $v['cliente'],
            (int)$v['num_items'],
            $metodo_label[$v['metodo_pago']] ?? $v['metodo_pago'],
            (float)$v['total'],
        ], $row038, [
            $v['estado'],
            $v['cajero'] ?? '',
            isset($cobro_map[(int)$v['id']]) ? ($metodo_label[$cobro_map[(int)$v['id']]] ?? $cobro_map[(int)$v['id']]) : '',
        ]));
        if (!$es_obsequio) $total_pesos += (float)$v['total'];
    }
    $w->addEmptyRow();
    $blank038 = $tiene038r ? ['', ''] : [];
    $w->addRow(array_merge(['', '', '', '', '', 'TOTAL INGRESOS (sin obsequios)', $total_pesos], $blank038, ['', '', '']), false, true);
    if ($tiene038r && $total_descuentos_periodo > 0) {
        $w->addRow(array_merge(['', '', '', '', '', 'TOTAL DESCONTADO (' . $n_descuentos_periodo . ' ventas con dto)', ''], [0, $total_descuentos_periodo], ['', '', '']), false, true);
    }

    // Resumen por método de pago
    $w->addEmptyRow();
    $w->addRow(['Resumen por Método de Pago'], true);
    $w->addRow(['Método', 'Cantidad', 'Total'], true);
    $por_metodo = [];
    foreach ($ventas as $v) {
        if ($v['estado'] === 'anulada') continue;
        $m = $v['metodo_pago'];
        $por_metodo[$m]['count'] = ($por_metodo[$m]['count'] ?? 0) + 1;
        $por_metodo[$m]['total'] = ($por_metodo[$m]['total'] ?? 0) + (float)$v['total'];
    }
    foreach ($por_metodo as $metodo => $datos) {
        $label = $metodo_label[$metodo] ?? $metodo;
        $nota  = $metodo === 'obsequio' ? ' (no es ingreso)' : '';
        $w->addRow([$label . $nota, $datos['count'], $datos['total']]);
    }

    // Discriminación: ingresos por forma de pago, separando ventas directas de
    // cobros de fiado (por fecha de cobro). Calculado sobre todo el período.
    $w->addEmptyRow();
    $w->addRow(['Ingresos por Forma de Pago (directo vs cobro de fiado)'], true);
    $w->addRow(['Forma de pago', 'Ventas directas', 'Cobro de fiados', 'Total recibido'], true);
    $xd = 0.0; $xf = 0.0;
    foreach ($formas_pago as $f) {
        $d  = $ingresos_forma[$f]['directo'];
        $fi = $ingresos_forma[$f]['fiado'];
        $xd += $d; $xf += $fi;
        $w->addRow([$metodo_label[$f], $d, $fi, $d + $fi]);
    }
    $w->addRow(['TOTAL', $xd, $xf, $xd + $xf], false, true);

    // ── Hoja 2: Rentabilidad ────────────────────────────────────────────────
    $w->setSheet('Rentabilidad');
    $w->addRow(['ClanDestino ERP — Rentabilidad por Producto'], true);
    $w->addRow(["Costo fijo/u: $" . fmt_cantidad($costo_fijo_u, 2) . "  |  Generado: " . date('d/m/Y H:i')]);
    $w->addEmptyRow();
    $w->addRow(['Producto', 'Nombre complementario', 'Tamaño', 'Precio Venta', 'Costo Ing.', 'Costo Fijo', 'Costo Total', 'Margen $', 'Margen %'], true);
    foreach ($rentabilidad as $p) {
        $w->addRow([
            $p['nombre'],
            $p['nombre2'] ?? '',   // subtítulo complementario (vacío si no tiene)
            $p['tamano'],
            (float)$p['precio_venta'],
            (float)$p['costo_ing'],
            (float)$p['costo_fijo_u'],
            (float)$p['costo_total_u'],
            (float)$p['margen_bruto'],
            (float)$p['margen_pct'],
        ]);
    }

    // ── Hoja 3: Por Variante (solo si hay datos de mig. 035) ───────────────
    if (!empty($ventas_variante)) {
        $w->setSheet('Por Variante');
        $w->addRow(['ClanDestino ERP — Ventas por Variante de Tamaño'], true);
        $w->addRow(["Período: $desde  al  $hasta | Generado: " . date('d/m/Y H:i')]);
        $w->addEmptyRow();
        $w->addRow(['Producto', 'Variante', 'Unidades', 'Total Venta'], true);
        foreach ($ventas_variante as $row) {
            $w->addRow([
                $row['producto'],
                $row['variante'],
                (int)$row['total_unidades'],
                (float)$row['total_venta'],
            ]);
        }
    }

    // ── Hoja: Descuentos (solo si hay ventas con descuento en el período) ──────
    if ($tiene038r && !empty($descuentos_lista)) {
        $w->setSheet('Descuentos');
        $w->addRow(['ClanDestino ERP — Descuentos del Período'], true);
        $w->addRow(["Período: $desde  al  $hasta | Generado: " . date('d/m/Y H:i')]);
        $w->addEmptyRow();
        $w->addRow(['#', 'Fecha', 'Cliente', 'Total Bruto', 'Desc. %', 'Desc. $', 'Total Neto', 'Cajero'], true);
        foreach ($descuentos_lista as $d) {
            $total_bruto = (float)$d['total'] + (float)$d['descuento_valor'];
            $w->addRow([
                $d['id'],
                date('d/m/Y H:i', strtotime($d['fecha_venta'])),
                $d['cliente'],
                $total_bruto,
                (float)$d['descuento_pct'],
                (float)$d['descuento_valor'],
                (float)$d['total'],
                $d['cajero'] ?? '',
            ]);
        }
        $w->addEmptyRow();
        $w->addRow(['', '', 'TOTAL DESCONTADO', '', '', $total_descuentos_periodo, '', ''], false, true);
    }

    // ── Hoja: Abonos a Fiado (solo si hay abonos en el período) ────────────────
    if (!empty($abonos_lista)) {
        $w->setSheet('Abonos a Fiado');
        $w->addRow(['ClanDestino ERP — Abonos a Fiado del Período'], true);
        $w->addRow(["Período: $desde  al  $hasta | Generado: " . date('d/m/Y H:i')]);
        $w->addEmptyRow();
        $cols034h = $tiene034r ? ['Saldo Antes', 'Saldo Después'] : [];
        $w->addRow(array_merge(['#', 'Fecha', 'Cliente', 'Monto', 'Método'], $cols034h, ['Notas', 'Registrado por']), true);
        foreach ($abonos_lista as $a) {
            $row034 = $tiene034r
                ? [
                    $a['saldo_anterior']  !== null ? (float)$a['saldo_anterior']  : '',
                    $a['saldo_posterior'] !== null ? (float)$a['saldo_posterior'] : '',
                  ]
                : [];
            $w->addRow(array_merge([
                $a['id'],
                date('d/m/Y H:i', strtotime($a['created_at'])),
                $a['cliente'],
                (float)$a['monto'],
                $metodo_label[$a['metodo_pago']] ?? $a['metodo_pago'],
            ], $row034, [
                $a['notas'] ?? '',
                $a['registrado_por'] ?? '',
            ]));
        }
        $w->addEmptyRow();
        $blank034 = $tiene034r ? ['', ''] : [];
        $w->addRow(array_merge(['', '', 'TOTAL RECAUDADO (' . $n_abonos_periodo . ' abonos)', $total_abonos_periodo, ''], $blank034, ['', '']), false, true);
    }

    $w->download('ClanDestino_Ventas_' . $desde . '_' . $hasta . '.xlsx');
}

// ── RESÚMENES para la vista HTML ────────────────────────────────────────────
// obsequio se cuenta aparte; su valor NO suma al total de ingresos
$stats = ['total'=>0,'pesos'=>0,'efectivo'=>0,'digital'=>0,'fiado'=>0,'anuladas'=>0,'obsequio_n'=>0,'obsequio_val'=>0.0];
foreach ($ventas as $v) {
    if ($v['estado'] === 'anulada') { $stats['anuladas']++; continue; }
    if ($v['metodo_pago'] === 'obsequio') {
        $stats['obsequio_n']++;
        $stats['obsequio_val'] += (float)$v['total'];
        continue;
    }
    $stats['total']++;
    $stats['pesos'] += (float)$v['total'];
    if ($v['metodo_pago'] === 'efectivo')                                          $stats['efectivo'] += (float)$v['total'];
    elseif ($v['metodo_pago'] === 'fiado')                                         $stats['fiado']    += (float)$v['total'];
    elseif (in_array($v['metodo_pago'], ['nequi','daviplata','bancolombia'], true)) $stats['digital']  += (float)$v['total'];
}
$estado_c = ['completada'=>'b-ok','anulada'=>'b-ano','pendiente_pago'=>'b-pend'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reporte Ventas — <?= APP_NAME ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root { --brand:#e94f37; --dark:#111827; --g2:#374151; --g5:#6b7280; --g8:#d1d5db; --g9:#f3f4f6; --white:#fff; --green:#059669; --yellow:#d97706; }
        body { font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; background:var(--g9); min-height:100vh; color:var(--dark); }
        .main { padding:16px 14px; max-width:1000px; margin:0 auto; }
        .card { background:var(--white); border-radius:14px; box-shadow:0 1px 4px rgba(0,0,0,.06); overflow:hidden; overflow-x:auto; margin-bottom:16px; }
        .card-title { font-size:15px; font-weight:800; padding:14px 18px; border-bottom:1px solid var(--g9); }
        .filter-row { background:var(--white); border-radius:14px; padding:14px 18px; display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; margin-bottom:16px; box-shadow:0 1px 4px rgba(0,0,0,.06); }
        .fg { display:flex; flex-direction:column; gap:4px; }
        .fg label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--g5); }
        .fg input { padding:9px 12px; border:2px solid var(--g8); border-radius:10px; font-size:14px; outline:none; }
        .fg input:focus { border-color:var(--brand); }
        .btn-ver { padding:9px 16px; background:var(--brand); color:var(--white); border:none; border-radius:10px; font-size:13px; font-weight:700; cursor:pointer; }
        .btn-xl  { padding:9px 16px; background:#16a34a; color:var(--white); border:none; border-radius:10px; font-size:13px; font-weight:700; cursor:pointer; text-decoration:none; }
        .stats { display:grid; grid-template-columns:repeat(5,1fr); gap:10px; margin-bottom:16px; }
        @media(max-width:640px){ .stats { grid-template-columns:1fr 1fr; } }
        .stat { background:var(--white); border-radius:14px; padding:12px 14px; box-shadow:0 1px 4px rgba(0,0,0,.06); }
        .stat-n { font-size:18px; font-weight:800; }
        .stat-l { font-size:11px; color:var(--g5); text-transform:uppercase; letter-spacing:.4px; }
        table { width:100%; border-collapse:collapse; }
        th { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--g5); padding:10px 14px; background:var(--g9); border-bottom:1px solid var(--g8); text-align:left; }
        th.r, td.r { text-align:right; }
        td { padding:10px 14px; border-bottom:1px solid var(--g9); font-size:13px; }
        tr:last-child td { border-bottom:none; }
        @media(max-width:600px){ .hide-m { display:none; } }
        .badge { font-size:10px; font-weight:700; padding:2px 7px; border-radius:20px; }
        .b-ok  { background:#d1fae5; color:#065f46; }
        .b-ano { background:#fee2e2; color:#991b1b; }
        .b-pend{ background:#fef3c7; color:#92400e; }
        .b-obs { background:#fce7f3; color:#9d174d; }
        /* Margen en tabla rentabilidad */
        .m-ok  { color:var(--green); font-weight:800; }
        .m-mid { color:var(--yellow); font-weight:800; }
        .m-bad { color:var(--brand); font-weight:800; }

        /* ════════════════════════════════════════════════════════════════
           RESPONSIVE — REPORTE VENTAS
           ════════════════════════════════════════════════════════════════ */
        /* Tabla con scroll horizontal global */
        .card { overflow-x:auto; -webkit-overflow-scrolling:touch; }

        /* xs: < 480px */
        @media (max-width:479px) {
            .main { padding:12px 10px 40px; }
            /* Filtros en columna */
            .filter-row { flex-direction:column; align-items:stretch; padding:12px; }
            .fg { width:100%; }
            .fg input { width:100%; }
            .btn-ver, .btn-xl { width:100%; min-height:44px; padding:11px 16px; }
            /* Stats 2 cols */
            .stats { grid-template-columns:1fr 1fr !important; gap:8px; }
            .stat-n { font-size:15px !important; }
            /* Tabla scroll */
            .card { overflow-x:auto; }
            table { min-width:480px; }
        }
        /* sm: 480-639px */
        @media (min-width:480px) and (max-width:639px) {
            .filter-row { flex-wrap:wrap; }
            .stats { grid-template-columns:repeat(3,1fr) !important; }
            .card { overflow-x:auto; }
            table { min-width:520px; }
        }
        /* md: 640-1023px */
        @media (min-width:640px) and (max-width:1023px) {
            .card { overflow-x:auto; }
        }
        /* ≥1600px */
        @media (min-width:1600px) {
            .main { max-width:1300px; }
            .stats { grid-template-columns:repeat(5,1fr) !important; }
            .stat-n { font-size:22px !important; }
            th, td { padding:12px 16px !important; font-size:14px !important; }
        }
        /* TV ≥1920px */
        @media (min-width:1920px) {
            .main { max-width:1600px; }
            .stat-n { font-size:26px !important; }
        }
    </style>
</head>
<body>
<?php $nav_activo = 'reportes'; include __DIR__ . '/../app/views/nav.php'; ?>

<!-- header reemplazado por nav.php -->
<main class="main">

    <!-- Filtros -->
    <form class="filter-row" method="GET">
        <div class="fg"><label>Desde</label><input type="date" name="desde" value="<?= htmlspecialchars($desde) ?>"></div>
        <div class="fg"><label>Hasta</label><input type="date" name="hasta" value="<?= htmlspecialchars($hasta) ?>"></div>
        <div class="fg"><label>Forma de pago</label>
            <select name="metodo" style="padding:9px 12px;border:2px solid var(--g8);border-radius:10px;font-size:14px;outline:none">
                <option value="todos"<?= $metodo_filtro==='todos'?' selected':'' ?>>Todas</option>
                <?php foreach ($metodos_filtro_ok as $mf): ?>
                <option value="<?= $mf ?>"<?= $metodo_filtro===$mf?' selected':'' ?>><?= htmlspecialchars($metodo_label[$mf] ?? $mf) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn-ver">Filtrar</button>
        <a href="?desde=<?= urlencode($desde) ?>&hasta=<?= urlencode($hasta) ?>&metodo=<?= urlencode($metodo_filtro) ?>&export=1" class="btn-xl">
            ⬇ Exportar Excel
        </a>
    </form>

    <!-- Stats -->
    <div class="stats">
        <div class="stat"><div class="stat-n"><?= $stats['total'] ?></div><div class="stat-l">Ventas</div></div>
        <div class="stat"><div class="stat-n" style="color:var(--brand)">$<?= fmt_moneda($stats['pesos']) ?></div><div class="stat-l">Total ingresos</div></div>
        <div class="stat"><div class="stat-n">$<?= fmt_moneda($stats['efectivo']) ?></div><div class="stat-l">Efectivo</div></div>
        <div class="stat"><div class="stat-n">$<?= fmt_moneda($stats['digital']) ?></div><div class="stat-l">Digital</div></div>
        <div class="stat"><div class="stat-n" style="color:var(--yellow)">$<?= fmt_moneda($stats['fiado']) ?></div><div class="stat-l">Fiado</div></div>
    </div>
    <?php if ($stats['obsequio_n'] > 0): ?>
    <div style="background:#fdf4ff;border:1px solid #fbcfe8;border-radius:12px;padding:12px 16px;
                margin-bottom:16px;font-size:13px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <span style="font-size:18px">🎁</span>
        <span><strong><?= $stats['obsequio_n'] ?> obsequio<?= $stats['obsequio_n']>1?'s':'' ?></strong>
        registrado<?= $stats['obsequio_n']>1?'s':'' ?> en este período
        — valor ref: <strong>$<?= fmt_moneda($stats['obsequio_val']) ?></strong>
        <span style="color:var(--g5)">(no incluido en total de ingresos)</span></span>
    </div>
    <?php endif; ?>
    <?php if ($n_descuentos_periodo > 0): ?>
    <div style="background:#fffbeb;border:1px solid #fcd34d;border-radius:12px;padding:12px 16px;
                margin-bottom:16px;font-size:13px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <span style="font-size:18px">🏷</span>
        <span><strong><?= $n_descuentos_periodo ?> venta<?= $n_descuentos_periodo>1?'s':'' ?> con descuento</strong>
        en este período — total descontado:
        <strong style="color:#92400e">−$<?= fmt_moneda($total_descuentos_periodo) ?></strong>
        <span style="color:var(--g5)">(incluido en el Excel → hoja "Descuentos")</span></span>
    </div>
    <?php endif; ?>
    <?php if ($n_abonos_periodo > 0): ?>
    <div style="background:#ecfdf5;border:1px solid #6ee7b7;border-radius:12px;padding:12px 16px;
                margin-bottom:16px;font-size:13px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <span style="font-size:18px">💰</span>
        <span><strong><?= $n_abonos_periodo ?> abono<?= $n_abonos_periodo>1?'s':'' ?> a fiado</strong>
        recibido<?= $n_abonos_periodo>1?'s':'' ?> en este período — total recaudado:
        <strong style="color:#065f46">$<?= fmt_moneda($total_abonos_periodo) ?></strong>
        <span style="color:var(--g5)">(incluido en el Excel → hoja "Abonos a Fiado")</span></span>
    </div>
    <?php endif; ?>

    <!-- Ingresos por forma de pago (discriminado: directo vs cobro de fiado) -->
    <div class="card">
        <div class="card-title">Ingresos por Forma de Pago
            <small style="font-weight:400;color:var(--g5)">(todo el período — separa ventas directas de cobros de fiado)</small>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Forma de pago</th>
                    <th class="r">Ventas directas</th>
                    <th class="r">Cobro de fiados</th>
                    <th class="r">Total recibido</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $tot_dir = 0.0; $tot_fia = 0.0;
                foreach ($formas_pago as $f):
                    $d  = $ingresos_forma[$f]['directo'];
                    $fi = $ingresos_forma[$f]['fiado'];
                    $tot_dir += $d; $tot_fia += $fi;
                ?>
                <tr>
                    <td><?= htmlspecialchars($metodo_label[$f]) ?></td>
                    <td class="r">$<?= fmt_moneda($d) ?></td>
                    <td class="r"><?= $fi > 0 ? '$'.fmt_moneda($fi) : '<span style="color:var(--g8)">—</span>' ?></td>
                    <td class="r"><strong>$<?= fmt_moneda($d + $fi) ?></strong></td>
                </tr>
                <?php endforeach; ?>
                <tr style="background:var(--g9)">
                    <td><strong>Total</strong></td>
                    <td class="r"><strong>$<?= fmt_moneda($tot_dir) ?></strong></td>
                    <td class="r"><strong>$<?= fmt_moneda($tot_fia) ?></strong></td>
                    <td class="r"><strong>$<?= fmt_moneda($tot_dir + $tot_fia) ?></strong></td>
                </tr>
            </tbody>
        </table>
        <div style="padding:10px 14px;font-size:12px;color:var(--g5)">
            <?php if (!$tiene042r): ?>
            El desglose de "Cobro de fiados" requiere aplicar la migración 042 (<code>metodo_cobro</code>).
            <?php else: ?>
            "Cobro de fiados" = ventas fiadas marcadas como cobradas en este período (por fecha de cobro),
            según el método con que se saldaron. Es independiente de los abonos por cliente (hoja "Abonos a Fiado").
            <?php endif; ?>
        </div>
    </div>

    <!-- Tabla de ventas -->
    <div class="card rcards-wrap">
        <div class="card-title">Detalle de Ventas — <?= htmlspecialchars($desde) ?> al <?= htmlspecialchars($hasta) ?>
            <?php if ($metodo_filtro !== 'todos'): ?>
            <small style="font-weight:400;color:var(--brand)">· filtrado: <?= htmlspecialchars($metodo_label[$metodo_filtro] ?? $metodo_filtro) ?></small>
            <?php endif; ?>
        </div>
        <table class="rcards">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Fecha</th>
                    <th class="hide-m">Cliente</th>
                    <th class="hide-m">Items</th>
                    <th class="hide-m">Método</th>
                    <th class="r">Total</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($ventas as $v): ?>
                <tr <?= $v['estado'] === 'anulada' ? 'style="opacity:.5"' : '' ?>>
                    <td><?= $v['id'] ?></td>
                    <td data-label="Fecha"><?= date('d/m H:i', strtotime($v['fecha_venta'])) ?></td>
                    <td class="hide-m" data-label="Cliente"><?= htmlspecialchars($v['cliente']) ?></td>
                    <td class="hide-m" data-label="Items"><?= $v['num_items'] ?></td>
                    <td class="hide-m" data-label="Método">
                        <?= htmlspecialchars($metodo_label[$v['metodo_pago']] ?? $v['metodo_pago']) ?>
                        <?php if ($v['metodo_pago'] === 'fiado' && !empty($cobro_map[(int)$v['id']])): ?>
                        <br><small style="color:var(--green)">↳ cobrado: <?= htmlspecialchars($metodo_label[$cobro_map[(int)$v['id']]] ?? $cobro_map[(int)$v['id']]) ?></small>
                        <?php endif; ?>
                    </td>
                    <td class="r" data-label="Total">
                        <strong>$<?= fmt_moneda($v['total']) ?></strong>
                        <?php if (isset($descuentos_map[(int)$v['id']])): ?>
                        <br><span class="badge" style="background:#fef3c7;color:#92400e;font-size:10px">−<?= fmt_cantidad($descuentos_map[(int)$v['id']]['pct'], 0) ?>% dto</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Estado"><span class="badge <?= $estado_c[$v['estado']] ?? 'b-ok' ?>"><?= htmlspecialchars($v['estado']) ?></span></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($ventas)): ?>
                <tr><td colspan="7" style="text-align:center; padding:30px; color:var(--g5)">Sin ventas en este período</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Rentabilidad por producto -->
    <div class="card">
        <div class="card-title">Rentabilidad por Producto <small style="font-weight:400; color:var(--g5)">(costo fijo $<?= fmt_cantidad($costo_fijo_u, 2) ?>/u)</small></div>
        <table>
            <thead>
                <tr>
                    <th>Producto</th>
                    <th class="r hide-m">Precio</th>
                    <th class="r hide-m">Costo Total</th>
                    <th class="r">Margen</th>
                    <th class="r">%</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rentabilidad as $p):
                    $mc = (float)$p['margen_pct'] >= 40 ? 'm-ok' : ((float)$p['margen_pct'] >= 20 ? 'm-mid' : 'm-bad');
                    if ((float)$p['precio_venta'] <= 0) continue;
                ?>
                <tr>
                    <td><?= htmlspecialchars($p['nombre']) ?> <small style="color:var(--g5)"><?= htmlspecialchars($p['tamano']) ?></small></td>
                    <td class="r hide-m">$<?= fmt_moneda($p['precio_venta']) ?></td>
                    <td class="r hide-m">$<?= fmt_moneda($p['costo_total_u']) ?></td>
                    <td class="r"><strong>$<?= fmt_moneda($p['margen_bruto']) ?></strong></td>
                    <td class="r"><span class="<?= $mc ?>"><?= $p['margen_pct'] ?>%</span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if (!empty($ventas_variante)): ?>
    <!-- Ventas por variante de tamaño (migración 035) -->
    <div class="card">
        <div class="card-title">Ventas por Variante de Tamaño</div>
        <table>
            <thead>
                <tr>
                    <th>Producto</th>
                    <th>Variante</th>
                    <th class="r">Unidades</th>
                    <th class="r">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($ventas_variante as $row): ?>
                <tr>
                    <td><?= htmlspecialchars($row['producto']) ?></td>
                    <td><span class="badge" style="background:#dbeafe;color:#1e40af"><?= htmlspecialchars($row['variante']) ?></span></td>
                    <td class="r"><?= (int)$row['total_unidades'] ?></td>
                    <td class="r"><strong>$<?= fmt_moneda($row['total_venta']) ?></strong></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

</main>
</body>
</html>
