<?php
/**
 * ventas/cierre.php — Resumen de cierre de caja diario.
 *
 * Muestra para una fecha (por defecto hoy):
 *  - Total por método de pago (efectivo, transferencias, fiado, obsequio)
 *  - Detalle por producto con desglose de variantes (mig. 034+035)
 *  - Lista de fiados del día (cliente + monto)
 *  - Ventas anuladas del período (para transparencia)
 *  - Versión imprimible/compartible via botón "Imprimir"
 */
require_once __DIR__ . '/../app/middleware/auth_check.php';

$nav_activo = 'ventas';
permiso_requerir('ventas', 'solo_ver');

$pdo  = db();
$hoy  = date('Y-m-d');
$fecha = $_GET['fecha'] ?? $hoy;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) $fecha = $hoy;
$fecha_label = date('d/m/Y', strtotime($fecha));
$es_hoy      = ($fecha === $hoy);

// Detectar migraciones
$tiene_034 = (int)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='venta_detalles'
       AND COLUMN_NAME='nombre_snap'"
)->fetchColumn() > 0;

$tiene_035 = (int)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='venta_detalles'
       AND COLUMN_NAME='variante_etiqueta'"
)->fetchColumn() > 0;

// ── 1. Resumen por método de pago (solo completadas / pendiente_pago) ─────────
$stmt_pago = $pdo->prepare(
    "SELECT metodo_pago,
            COUNT(*)           AS n_ventas,
            SUM(total)         AS total_pesos
     FROM ventas
     WHERE DATE(fecha_venta) = :f
       AND estado IN ('completada', 'pendiente_pago')
     GROUP BY metodo_pago
     ORDER BY FIELD(metodo_pago,'efectivo','nequi','daviplata','bancolombia','fiado','obsequio')"
);
$stmt_pago->execute([':f' => $fecha]);
$por_metodo = $stmt_pago->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);

$total_cobrado = 0.0;  // efectivo + transferencias (no fiado, no obsequio)
$total_fiado   = (float)($por_metodo['fiado']['total_pesos']   ?? 0);
$total_obsequio= (float)($por_metodo['obsequio']['total_pesos']?? 0);
$n_ventas_total= 0;
foreach ($por_metodo as $m => $row) {
    if (!in_array($m, ['fiado','obsequio'])) $total_cobrado += (float)$row['total_pesos'];
    $n_ventas_total += (int)$row['n_ventas'];
}
$total_ventas = $total_cobrado + $total_fiado + $total_obsequio;

// ── 2. Detalle por producto + variante ────────────────────────────────────────
$col_nombre  = $tiene_034 ? 'COALESCE(vd.nombre_snap, p.nombre)' : 'p.nombre';
$col_variante= $tiene_035 ? 'vd.variante_etiqueta' : 'NULL';

$stmt_prod = $pdo->prepare(
    "SELECT {$col_nombre}     AS producto,
            {$col_variante}   AS variante,
            SUM(vd.cantidad)  AS total_u,
            SUM(vd.subtotal)  AS total_pesos,
            vd.producto_id
     FROM venta_detalles vd
     JOIN ventas v    ON v.id  = vd.venta_id
     JOIN productos p ON p.id = vd.producto_id
     WHERE DATE(v.fecha_venta) = :f
       AND v.estado != 'anulada'
     GROUP BY vd.producto_id, {$col_variante}
     ORDER BY total_pesos DESC, producto ASC"
);
$stmt_prod->execute([':f' => $fecha]);
$detalle_raw = $stmt_prod->fetchAll();

// Agrupar variantes bajo su producto
$detalle = [];
foreach ($detalle_raw as $row) {
    $prod = $row['producto'];
    if (!isset($detalle[$prod])) {
        $detalle[$prod] = ['total_u' => 0, 'total_pesos' => 0.0, 'variantes' => []];
    }
    $detalle[$prod]['total_u']     += (int)$row['total_u'];
    $detalle[$prod]['total_pesos'] += (float)$row['total_pesos'];
    if ($row['variante'] !== null) {
        $detalle[$prod]['variantes'][] = [
            'etiqueta' => $row['variante'],
            'u'        => (int)$row['total_u'],
        ];
    }
}

// ── 3. Fiados del día ─────────────────────────────────────────────────────────
$stmt_fiado = $pdo->prepare(
    "SELECT v.id, v.total, v.estado, v.notas,
            IFNULL(c.nombre, 'Sin cliente') AS cliente
     FROM ventas v
     LEFT JOIN clientes c ON c.id = v.cliente_id
     WHERE DATE(v.fecha_venta) = :f
       AND v.metodo_pago = 'fiado'
     ORDER BY c.nombre, v.id"
);
$stmt_fiado->execute([':f' => $fecha]);
$fiados_dia = $stmt_fiado->fetchAll();

// ── 4. Anuladas del día ───────────────────────────────────────────────────────
$n_anuladas_stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM ventas WHERE DATE(fecha_venta) = ? AND estado = 'anulada'"
);
$n_anuladas_stmt->execute([$fecha]);
$n_anuladas = (int)$n_anuladas_stmt->fetchColumn();

// ── 5. Turno de caja (mig.037) ───────────────────────────────────────────────
$turno = null;
try {
    $tiene_037 = (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='turnos_caja' AND COLUMN_NAME='id'"
    )->fetchColumn() > 0;
    if ($tiene_037) {
        $st = $pdo->prepare(
            "SELECT tc.fondo_inicial, tc.estado, tc.notas_apertura,
                    u.nombre AS nombre_apertura, tc.fecha_apertura
             FROM turnos_caja tc
             LEFT JOIN usuarios u ON u.id = tc.usuario_apertura
             WHERE tc.fecha = ? ORDER BY tc.id DESC LIMIT 1"
        );
        $st->execute([$fecha]);
        $turno = $st->fetch() ?: null;
    }
} catch (\Exception $e) {}

// ── 6. Nombre del negocio ─────────────────────────────────────────────────────
$negocio_nombre = '';
try {
    $negocio_nombre = (string)$pdo->query(
        "SELECT valor FROM configuracion_negocio WHERE clave = 'nombre_negocio' LIMIT 1"
    )->fetchColumn();
} catch (\Exception $e) {}
$negocio_nombre = $negocio_nombre ?: APP_NAME;

// ── 6. Texto para compartir por WhatsApp ──────────────────────────────────────
function fmt_cop(float $n): string { return '$' . number_format($n, 0, ',', '.'); }

$txt_share = '';
if ($n_ventas_total > 0) {
    $txt_share  = "🧾 *Cierre {$fecha_label}* — {$negocio_nombre}\n";
    $txt_share .= str_repeat('─', 28) . "\n";

    $iconos_share = ['efectivo'=>'💵','nequi'=>'📱','daviplata'=>'📱','bancolombia'=>'🏦','fiado'=>'📋','obsequio'=>'🎁'];
    $labels_share = ['efectivo'=>'Efectivo','nequi'=>'Nequi','daviplata'=>'Daviplata','bancolombia'=>'Bancolombia','fiado'=>'Fiado','obsequio'=>'Obsequio'];
    foreach ($labels_share as $m => $lbl) {
        if (!isset($por_metodo[$m])) continue;
        $icn  = $iconos_share[$m];
        $monto= fmt_cop((float)$por_metodo[$m]['total_pesos']);
        $n    = (int)$por_metodo[$m]['n_ventas'];
        $txt_share .= "{$icn} {$lbl}: {$monto} ({$n})\n";
    }

    $txt_share .= str_repeat('─', 28) . "\n";
    $txt_share .= "✅ *Cobrado: " . fmt_cop($total_cobrado) . "*\n";
    if ($total_fiado > 0)    $txt_share .= "📋 Fiado: "    . fmt_cop($total_fiado)    . "\n";
    if ($total_obsequio > 0) $txt_share .= "🎁 Obsequio: " . fmt_cop($total_obsequio) . "\n";
    $txt_share .= "📊 *Total: " . fmt_cop($total_ventas) . "*\n";
    if ($turno) {
        $fondo_share = (float)$turno['fondo_inicial'];
        $ef_share    = (float)($por_metodo['efectivo']['total_pesos'] ?? 0);
        $txt_share .= str_repeat('─', 28) . "\n";
        $txt_share .= "🏪 Fondo inicial: "  . fmt_cop($fondo_share) . "\n";
        $txt_share .= "💵 Total en caja: *" . fmt_cop($fondo_share + $ef_share) . "*\n";
    }

    if (!empty($detalle)) {
        $txt_share .= str_repeat('─', 28) . "\n";
        foreach ($detalle as $prod => $d) {
            $vars = '';
            if (!empty($d['variantes'])) {
                $partes = array_map(fn($v) => $v['etiqueta'] . ':' . $v['u'], $d['variantes']);
                $vars = ' (' . implode(', ', $partes) . ')';
            }
            $txt_share .= "🥪 {$prod}: {$d['total_u']}u{$vars}\n";
        }
    }
}

// Etiquetas amigables por método de pago
$metodo_labels = [
    'efectivo'    => 'Efectivo',
    'nequi'       => 'Nequi',
    'daviplata'   => 'Daviplata',
    'bancolombia' => 'Bancolombia',
    'fiado'       => 'Fiado',
    'obsequio'    => 'Obsequio',
];
$metodo_icons = [
    'efectivo'    => '💵',
    'nequi'       => '📱',
    'daviplata'   => '📱',
    'bancolombia' => '🏦',
    'fiado'       => '📋',
    'obsequio'    => '🎁',
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cierre de Caja <?= $fecha_label ?> — <?= APP_NAME ?></title>
    <style>
        :root { --brand:#e94f37; --dark:#111827; --g2:#374151; --g5:#6b7280; --g8:#d1d5db; --g9:#f3f4f6; --white:#fff; --green:#059669; }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: system-ui, -apple-system, sans-serif; background: var(--g9); color: var(--dark); }
        .main { max-width: 860px; margin: 0 auto; padding: 20px 14px 60px; }

        /* Header */
        .page-hdr { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap; margin-bottom: 20px; }
        .page-title { font-size: 22px; font-weight: 800; }
        .page-sub   { font-size: 13px; color: var(--g5); margin-top: 3px; }
        .hdr-btns   { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }

        /* Fecha selector */
        .fecha-bar { background: var(--white); border: 1px solid var(--g8); border-radius: 12px;
                     padding: 12px 16px; display: flex; align-items: center; gap: 10px;
                     flex-wrap: wrap; margin-bottom: 20px; }
        .fecha-bar strong { font-size: 13px; }
        .fecha-bar input[type=date] { padding: 6px 10px; border: 1px solid var(--g8); border-radius: 8px; font-size: 13px; }
        .btn-fecha { padding: 7px 16px; background: var(--brand); color: #fff; border: none; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; }

        /* Grid de métodos de pago */
        .metodos-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin-bottom: 20px; }
        .metodo-card { background: var(--white); border: 1px solid var(--g8); border-radius: 12px; padding: 14px 16px; }
        .metodo-card.cobrado  { border-color: #6ee7b7; }
        .metodo-card.pendiente { border-color: #fcd34d; background: #fffbeb; }
        .metodo-card.obsequio { background: var(--g9); }
        .metodo-icon  { font-size: 20px; margin-bottom: 4px; }
        .metodo-label { font-size: 11px; color: var(--g5); font-weight: 700; text-transform: uppercase; letter-spacing: .4px; }
        .metodo-total { font-size: 22px; font-weight: 800; margin: 4px 0 2px; }
        .metodo-n     { font-size: 11px; color: var(--g5); }

        /* Totales resumen */
        .totales { background: var(--dark); color: #fff; border-radius: 14px; padding: 20px 22px; margin-bottom: 20px; }
        .totales-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; }
        .tot-item { }
        .tot-lbl  { font-size: 11px; color: #9ca3af; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 3px; }
        .tot-val  { font-size: 26px; font-weight: 800; }
        .tot-val.green  { color: #34d399; }
        .tot-val.yellow { color: #fcd34d; }
        .tot-val.gray   { color: #9ca3af; }
        .tot-sep { border: none; border-top: 1px solid #374151; margin: 14px 0; }

        /* Detalle productos */
        .card { background: var(--white); border: 1px solid var(--g8); border-radius: 12px; padding: 18px; margin-bottom: 16px; }
        .card-title { font-size: 14px; font-weight: 700; margin-bottom: 12px; color: var(--g2); }
        .prod-row { display: flex; align-items: baseline; gap: 8px; padding: 7px 0; border-bottom: 1px solid var(--g9); }
        .prod-row:last-child { border-bottom: none; }
        .prod-nombre { font-weight: 600; font-size: 13px; flex: 1; }
        .prod-u  { font-size: 12px; color: var(--g5); white-space: nowrap; }
        .prod-val{ font-size: 13px; font-weight: 700; white-space: nowrap; }
        .var-row { display: flex; gap: 6px; padding: 2px 0 2px 16px; font-size: 11px; color: var(--g5); }
        .badge-var { background: #dbeafe; color: #1e40af; padding: 1px 6px; border-radius: 999px; font-size: 10px; font-weight: 700; }

        /* Fiados */
        .fiado-row { display: flex; justify-content: space-between; align-items: center; padding: 7px 0; border-bottom: 1px solid var(--g9); font-size: 13px; }
        .fiado-row:last-child { border-bottom: none; }
        .fiado-estado-ok   { color: var(--green); font-size: 11px; font-weight: 600; }
        .fiado-estado-pend { color: #d97706; font-size: 11px; font-weight: 600; }

        /* Botones */
        .btn { padding: 8px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; border: none; text-decoration: none; display: inline-block; }
        .btn-sec  { background: var(--white); color: var(--dark); border: 1px solid var(--g8); }
        .btn-print{ background: var(--dark);  color: #fff; }

        /* Print */
        @media print {
            body { background: #fff; }
            .no-print { display: none !important; }
            .main { max-width: 100%; padding: 0; }
            .totales { background: #111 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
        @media (max-width: 600px) {
            .metodos-grid { grid-template-columns: 1fr 1fr; }
            .tot-val { font-size: 20px; }
        }
    </style>
</head>
<body>
<?php $nav_activo = 'ventas'; include __DIR__ . '/../app/views/nav.php'; ?>

<main class="main">

    <!-- Encabezado -->
    <div class="page-hdr no-print">
        <div>
            <h1 class="page-title">Cierre de Caja</h1>
            <p class="page-sub">Resumen de ventas del día · <?= $fecha_label ?><?= $es_hoy ? ' — Hoy' : '' ?></p>
        </div>
        <div class="hdr-btns">
            <a href="<?= APP_BASE ?>/ventas/historial.php" class="btn btn-sec">← Historial</a>
            <a href="<?= APP_BASE ?>/ventas/apertura.php" class="btn btn-sec no-print">🏪 Apertura</a>
            <?php if ($n_ventas_total > 0): ?>
            <button class="btn" style="background:#25d366;color:#fff" onclick="compartir()">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" style="vertical-align:-2px;margin-right:4px"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/><path d="M12 0C5.373 0 0 5.373 0 12c0 2.104.548 4.078 1.508 5.793L.057 23.75l6.163-1.618A11.945 11.945 0 0012 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 21.818a9.818 9.818 0 01-5.006-1.371l-.359-.213-3.713.975.992-3.62-.234-.372A9.785 9.785 0 012.182 12C2.182 6.57 6.57 2.182 12 2.182S21.818 6.57 21.818 12 17.43 21.818 12 21.818z"/></svg>Compartir
            </button>
            <?php endif; ?>
            <button class="btn btn-print" onclick="window.print()">🖨 Imprimir</button>
        </div>
    </div>

    <!-- Print header (solo visible al imprimir) -->
    <div style="display:none" class="print-only" id="print-hdr">
        <h2 style="font-size:18px;font-weight:800"><?= htmlspecialchars($negocio_nombre) ?></h2>
        <p style="font-size:13px;color:#555">Cierre de Caja — <?= $fecha_label ?></p>
        <hr style="margin:10px 0;border-color:#ccc">
    </div>
    <style>@media print { .print-only { display:block !important; margin-bottom:14px; } }</style>

    <!-- Selector de fecha -->
    <form method="GET" class="fecha-bar no-print">
        <strong>Fecha:</strong>
        <input type="date" name="fecha" value="<?= htmlspecialchars($fecha) ?>" max="<?= $hoy ?>">
        <button type="submit" class="btn-fecha">Ver</button>
        <?php if ($n_ventas_total === 0): ?>
        <span style="font-size:12px;color:var(--g5)">Sin ventas para esta fecha.</span>
        <?php endif; ?>
        <?php if (!$es_hoy): ?>
        <a href="<?= APP_BASE ?>/ventas/cierre.php" class="btn btn-sec" style="margin-left:auto">Ver hoy</a>
        <?php endif; ?>
    </form>

    <?php if ($n_ventas_total === 0): ?>
    <div style="text-align:center;padding:48px;color:var(--g5)">
        No hay ventas registradas para <?= $fecha_label ?>.
    </div>
    <?php else: ?>

    <!-- Métodos de pago -->
    <div class="metodos-grid">
        <?php foreach ($metodo_labels as $m => $label):
            if (!isset($por_metodo[$m])) continue;
            $row  = $por_metodo[$m];
            $cls  = $m === 'fiado' ? 'pendiente' : ($m === 'obsequio' ? 'obsequio' : 'cobrado');
        ?>
        <div class="metodo-card <?= $cls ?>">
            <div class="metodo-icon"><?= $metodo_icons[$m] ?></div>
            <div class="metodo-label"><?= $label ?></div>
            <div class="metodo-total">$<?= number_format((float)$row['total_pesos'], 0, ',', '.') ?></div>
            <div class="metodo-n"><?= (int)$row['n_ventas'] ?> venta<?= $row['n_ventas'] != 1 ? 's' : '' ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Panel de fondo de caja (si hay turno registrado) -->
    <?php if ($turno): ?>
    <?php
    $fondo      = (float)$turno['fondo_inicial'];
    $ef_total   = (float)($por_metodo['efectivo']['total_pesos'] ?? 0);
    $total_caja = $fondo + $ef_total;
    ?>
    <div class="totales" style="margin-bottom:12px;background:linear-gradient(135deg,#1e3a5f,#1e40af)">
        <div class="totales-grid">
            <div class="tot-item">
                <div class="tot-lbl">Fondo apertura</div>
                <div class="tot-val">$<?= number_format($fondo, 0, ',', '.') ?></div>
            </div>
            <div class="tot-item">
                <div class="tot-lbl">Efectivo cobrado</div>
                <div class="tot-val green">$<?= number_format($ef_total, 0, ',', '.') ?></div>
            </div>
            <div class="tot-item">
                <div class="tot-lbl">Total en caja</div>
                <div class="tot-val" style="font-size:22px">$<?= number_format($total_caja, 0, ',', '.') ?></div>
            </div>
            <div class="tot-item">
                <div class="tot-lbl">Turno</div>
                <div class="tot-val gray" style="font-size:12px;font-weight:500;text-align:left;padding-top:3px">
                    <?= $turno['estado'] === 'abierto' ? '● Abierto' : '○ Cerrado' ?><br>
                    <?= htmlspecialchars($turno['nombre_apertura'] ?? '') ?>
                    · <?= date('H:i', strtotime($turno['fecha_apertura'])) ?>
                </div>
            </div>
        </div>
    </div>
    <?php else: ?>
    <?php if (isset($tiene_037) && $tiene_037 && $es_hoy): ?>
    <div style="background:#fffbeb;border:1px solid #fcd34d;border-radius:12px;padding:12px 16px;
                margin-bottom:12px;font-size:13px;color:#92400e;display:flex;gap:10px;align-items:center">
        ⚠ Sin turno registrado.
        <a href="<?= APP_BASE ?>/ventas/apertura.php" style="color:#92400e;font-weight:700">Abrir turno →</a>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <!-- Totales consolidados -->
    <div class="totales">
        <div class="totales-grid">
            <div class="tot-item">
                <div class="tot-lbl">Cobrado hoy</div>
                <div class="tot-val green">$<?= number_format($total_cobrado, 0, ',', '.') ?></div>
            </div>
            <div class="tot-item">
                <div class="tot-lbl">Fiado pendiente</div>
                <div class="tot-val yellow">$<?= number_format($total_fiado, 0, ',', '.') ?></div>
            </div>
            <div class="tot-item">
                <div class="tot-lbl">Obsequios</div>
                <div class="tot-val gray">$<?= number_format($total_obsequio, 0, ',', '.') ?></div>
            </div>
            <div class="tot-item">
                <div class="tot-lbl">Total ventas del día</div>
                <div class="tot-val">$<?= number_format($total_ventas, 0, ',', '.') ?></div>
            </div>
        </div>
        <?php if ($n_anuladas > 0): ?>
        <hr class="tot-sep">
        <p style="font-size:12px;color:#9ca3af">
            <?= $n_anuladas ?> venta<?= $n_anuladas != 1 ? 's' : '' ?> anulada<?= $n_anuladas != 1 ? 's' : '' ?> (excluidas del total)
        </p>
        <?php endif; ?>
    </div>

    <!-- Detalle por producto -->
    <?php if (!empty($detalle)): ?>
    <div class="card">
        <div class="card-title">Detalle por producto</div>
        <?php
        $total_u_todos = 0;
        foreach ($detalle as $prod => $d):
            $total_u_todos += $d['total_u'];
        ?>
        <div class="prod-row">
            <span class="prod-nombre"><?= htmlspecialchars($prod) ?></span>
            <span class="prod-u"><?= $d['total_u'] ?> u</span>
            <span class="prod-val">$<?= number_format($d['total_pesos'], 0, ',', '.') ?></span>
        </div>
        <?php if (!empty($d['variantes'])): ?>
        <div class="var-row">
            <?php foreach ($d['variantes'] as $v): ?>
            <span><span class="badge-var"><?= htmlspecialchars($v['etiqueta']) ?></span> <?= $v['u'] ?>u</span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php endforeach; ?>
        <div class="prod-row" style="margin-top:6px;padding-top:10px;border-top:2px solid var(--g8);border-bottom:none">
            <span class="prod-nombre" style="color:var(--g5)">Total unidades</span>
            <span class="prod-u" style="font-weight:700;color:var(--dark)"><?= $total_u_todos ?> u</span>
            <span class="prod-val">$<?= number_format(array_sum(array_column($detalle, 'total_pesos')), 0, ',', '.') ?></span>
        </div>
    </div>
    <?php endif; ?>

    <!-- Fiados del día -->
    <?php if (!empty($fiados_dia)): ?>
    <div class="card">
        <div class="card-title">Fiados del día</div>
        <?php foreach ($fiados_dia as $f): ?>
        <div class="fiado-row">
            <span>
                <?= htmlspecialchars($f['cliente']) ?>
                <?php if ($f['notas']): ?>
                <span style="font-size:11px;color:var(--g5)"> · <?= htmlspecialchars(substr($f['notas'], 0, 40)) ?></span>
                <?php endif; ?>
            </span>
            <span style="display:flex;align-items:center;gap:10px">
                <span class="<?= $f['estado'] === 'completada' ? 'fiado-estado-ok' : 'fiado-estado-pend' ?>">
                    <?= $f['estado'] === 'completada' ? '✓ pagado' : 'pendiente' ?>
                </span>
                <strong>$<?= number_format((float)$f['total'], 0, ',', '.') ?></strong>
            </span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php endif; ?>

</main>

<script>
const TEXTO_COMPARTIR = <?= json_encode($txt_share, JSON_UNESCAPED_UNICODE) ?>;

async function compartir() {
    if (!TEXTO_COMPARTIR) return;
    if (navigator.share) {
        try {
            await navigator.share({ title: 'Cierre de Caja', text: TEXTO_COMPARTIR });
            return;
        } catch (e) { /* usuario canceló o API no disponible */ }
    }
    // Fallback: abrir WhatsApp Web con el texto codificado
    window.open('https://wa.me/?text=' + encodeURIComponent(TEXTO_COMPARTIR), '_blank');
}
</script>
</body>
</html>
