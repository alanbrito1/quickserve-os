<?php
/**
 * clientes/estado_cuenta.php — Estado de cuenta de un cliente.
 *
 * Muestra el historial completo de cargos (ventas a fiado) y abonos
 * (pagos_fiado) de un cliente en orden cronológico, con saldo corriente
 * acumulado en cada fila.
 *
 * Parámetros GET:
 *   id     → ID del cliente (requerido)
 *   desde  → Fecha inicio filtro (YYYY-MM-DD, default: 6 meses atrás)
 *   hasta  → Fecha fin filtro   (YYYY-MM-DD, default: hoy)
 *   print  → Si existe, activa modo impresión (solo tabla, sin nav)
 */

require_once __DIR__ . '/../app/middleware/auth_check.php';
require_once __DIR__ . '/../app/helpers/AuditoriaHelper.php';

$nav_activo = 'clientes';
permiso_requerir('ventas', 'solo_ver');

// ── Validar cliente ───────────────────────────────────────────────────────────
$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    header('Location: ' . APP_BASE . '/clientes/');
    exit;
}

$pdo = db();

$stmtCli = $pdo->prepare(
    'SELECT id, nombre, apellido, empresa, telefono, saldo_fiado
     FROM clientes WHERE id = ?'
);
$stmtCli->execute([$id]);
$cliente = $stmtCli->fetch();

if (!$cliente) {
    header('Location: ' . APP_BASE . '/clientes/');
    exit;
}

// Nombre completo del cliente (nombre + apellido si existe)
$nombre_completo = htmlspecialchars(
    $cliente['nombre']
    . (isset($cliente['apellido']) && $cliente['apellido'] ? ' ' . $cliente['apellido'] : '')
);

// ── Filtros de fecha ──────────────────────────────────────────────────────────
$hoy         = date('Y-m-d');
$fecha_desde = $_GET['desde'] ?? date('Y-m-d', strtotime('-6 months'));
$fecha_hasta = $_GET['hasta'] ?? $hoy;

// Validar formato de fechas
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_desde)) $fecha_desde = date('Y-m-d', strtotime('-6 months'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_hasta)) $fecha_hasta = $hoy;
if ($fecha_desde > $fecha_hasta)                         $fecha_hasta = $fecha_desde;

$f_ini = $fecha_desde . ' 00:00:00';
$f_fin = $fecha_hasta . ' 23:59:59';

$modo_impresion = isset($_GET['print']);

// Registrar en auditoría cuando se imprime un estado de cuenta
// (permite saber quién imprimió qué extracto y en qué período)
if ($modo_impresion) {
    require_once __DIR__ . '/../app/helpers/AuditoriaHelper.php';
    log_registrar('clientes', $id, 'estado_cuenta_impreso',
        null,
        "Período $fecha_desde a $fecha_hasta",
        'VIEW');
}

// ── 1. Cargar ventas a fiado del cliente ──────────────────────────────────────
// Trae el detalle de productos vendidos (usando nombre_snap si existe — mig. 034)
$stmtVentas = $pdo->prepare(
    "SELECT v.id, v.fecha_venta AS fecha, v.total,
            v.estado, v.notas,
            GROUP_CONCAT(
                COALESCE(vd.nombre_snap, p.nombre)
                ORDER BY p.nombre SEPARATOR ', '
            ) AS productos
     FROM ventas v
     LEFT JOIN venta_detalles vd ON vd.venta_id = v.id
     LEFT JOIN productos p       ON p.id = vd.producto_id
     WHERE v.cliente_id = :cli
       AND v.metodo_pago = 'fiado'
       AND v.fecha_venta BETWEEN :ini AND :fin
     GROUP BY v.id
     ORDER BY v.fecha_venta"
);
$stmtVentas->execute([':cli' => $id, ':ini' => $f_ini, ':fin' => $f_fin]);
$ventas = $stmtVentas->fetchAll();

// ── 2. Cargar abonos del cliente ──────────────────────────────────────────────
// saldo_anterior y saldo_posterior disponibles si migración 034 está aplicada
$stmtAbonos = $pdo->prepare(
    "SELECT pf.id, pf.created_at AS fecha, pf.monto, pf.metodo_pago,
            pf.notas,
            pf.saldo_anterior, pf.saldo_posterior
     FROM pagos_fiado pf
     WHERE pf.cliente_id = :cli
       AND pf.created_at BETWEEN :ini AND :fin
     ORDER BY pf.created_at"
);
$stmtAbonos->execute([':cli' => $id, ':ini' => $f_ini, ':fin' => $f_fin]);
$abonos = $stmtAbonos->fetchAll();

// ── 3. Combinar y ordenar cronológicamente ────────────────────────────────────
$movimientos = [];

foreach ($ventas as $v) {
    // Solo mostrar ventas activas (no anuladas) en el saldo corriente
    // Las anuladas se muestran con indicador pero no afectan el saldo
    $movimientos[] = [
        'fecha'       => $v['fecha'],
        'tipo'        => 'cargo',
        'descripcion' => $v['productos'] ?: 'Venta #' . $v['id'],
        'monto'       => (float)$v['total'],
        'anulada'     => $v['estado'] === 'anulada',
        'ref_id'      => $v['id'],
        'notas'       => $v['notas'] ?? '',
    ];
}

foreach ($abonos as $ab) {
    $movimientos[] = [
        'fecha'           => $ab['fecha'],
        'tipo'            => 'abono',
        'descripcion'     => 'Abono — ' . ucfirst($ab['metodo_pago']),
        'monto'           => (float)$ab['monto'],
        'anulada'         => false,
        'ref_id'          => $ab['id'],
        'notas'           => $ab['notas'] ?? '',
        'saldo_anterior'  => $ab['saldo_anterior'],
        'saldo_posterior' => $ab['saldo_posterior'],
    ];
}

// Ordenar por fecha ASC (mismo segundo: cargos antes que abonos)
usort($movimientos, function ($a, $b) {
    $cmp = strcmp($a['fecha'], $b['fecha']);
    if ($cmp !== 0) return $cmp;
    // Si misma fecha: abonos después de cargos (pago cierra la deuda del día)
    return ($a['tipo'] === 'cargo' ? 0 : 1) - ($b['tipo'] === 'cargo' ? 0 : 1);
});

// ── 4. Calcular saldo corriente acumulado ─────────────────────────────────────
// Se calcula desde 0 al inicio del período seleccionado.
// Para saldo real se necesita el saldo ANTES del período filtrado.
// Obtener el saldo al inicio del período buscando el último movimiento anterior.
$saldoPrePeriodo = 0.0;

// Saldo antes del período = suma de cargos no-anulados - suma de abonos, previos a fecha_desde
$stmtPrev = $pdo->prepare(
    "SELECT
         COALESCE(SUM(CASE WHEN v.estado != 'anulada' THEN v.total ELSE 0 END), 0) AS total_cargos,
         COALESCE((SELECT SUM(pf2.monto) FROM pagos_fiado pf2
                   WHERE pf2.cliente_id = :cli2
                     AND pf2.created_at < :ini2), 0) AS total_abonos
     FROM ventas v
     WHERE v.cliente_id = :cli
       AND v.metodo_pago = 'fiado'
       AND v.fecha_venta < :ini"
);
$stmtPrev->execute([':cli' => $id, ':ini' => $f_ini, ':cli2' => $id, ':ini2' => $f_ini]);
$prev = $stmtPrev->fetch();
$saldoPrePeriodo = max(0, (float)$prev['total_cargos'] - (float)$prev['total_abonos']);

// Calcular saldo corriente en cada movimiento
$saldoActual = $saldoPrePeriodo;
foreach ($movimientos as &$mov) {
    if ($mov['anulada']) {
        // Venta anulada: no afecta saldo, solo se muestra como referencia
        $mov['saldo'] = $saldoActual;
    } elseif ($mov['tipo'] === 'cargo') {
        $saldoActual += $mov['monto'];
        $mov['saldo'] = $saldoActual;
    } else {
        $saldoActual = max(0, $saldoActual - $mov['monto']);
        $mov['saldo'] = $saldoActual;
    }
}
unset($mov);

// ── 5. Totales del período ────────────────────────────────────────────────────
$total_cargos  = array_sum(array_map(fn($m) => $m['tipo']==='cargo'  && !$m['anulada'] ? $m['monto'] : 0, $movimientos));
$total_abonos  = array_sum(array_map(fn($m) => $m['tipo']==='abono'             ? $m['monto'] : 0, $movimientos));
$saldo_final   = (float)$cliente['saldo_fiado']; // el saldo real actual en BD
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Estado de Cuenta — <?= $nombre_completo ?></title>
    <style>
        *, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }
        :root {
            --brand:#e94f37; --dark:#111827; --g2:#374151; --g5:#6b7280;
            --g8:#d1d5db; --g9:#f3f4f6; --white:#fff;
            --green:#059669; --red:#dc2626; --yellow:#d97706;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background:var(--g9); min-height:100vh; color:var(--dark);
        }
        .main { padding:20px 14px 60px; max-width:860px; margin:0 auto; }

        /* ── Encabezado del documento ──────────────────────────────────────── */
        .doc-header {
            background:var(--white); border-radius:14px;
            padding:20px 24px; margin-bottom:16px;
            box-shadow:0 1px 4px rgba(0,0,0,.06);
            display:flex; justify-content:space-between; align-items:flex-start;
            flex-wrap:wrap; gap:14px;
        }
        .doc-cliente-nombre { font-size:20px; font-weight:800; }
        .doc-cliente-sub    { font-size:13px; color:var(--g5); margin-top:2px; }
        .saldo-badge {
            font-size:18px; font-weight:800; padding:8px 18px;
            border-radius:10px; white-space:nowrap;
        }
        .saldo-positivo { background:#fee2e2; color:var(--red); }
        .saldo-cero     { background:#d1fae5; color:#065f46; }

        /* ── Filtros ───────────────────────────────────────────────────────── */
        .filtros {
            background:var(--white); border-radius:12px; padding:14px 18px;
            margin-bottom:16px; display:flex; gap:10px; flex-wrap:wrap;
            align-items:flex-end; box-shadow:0 1px 4px rgba(0,0,0,.06);
        }
        .fg { display:flex; flex-direction:column; gap:4px; }
        .fg label { font-size:11px; font-weight:700; text-transform:uppercase;
                    letter-spacing:.5px; color:var(--g5); }
        .fg input { padding:8px 12px; border:2px solid var(--g8); border-radius:9px;
                    font-size:14px; outline:none; }
        .fg input:focus { border-color:var(--brand); }
        .btn { padding:9px 16px; border:none; border-radius:9px; font-size:13px;
               font-weight:700; cursor:pointer; }
        .btn-ver   { background:var(--brand); color:#fff; }
        .btn-print {
            background:#1e3a5f; color:#fff;
            display:inline-flex; align-items:center; gap:6px;
            text-decoration:none; padding:9px 16px; border-radius:9px;
            font-size:13px; font-weight:700;
        }
        .btn-back {
            background:var(--g9); color:var(--g2); border:1px solid var(--g8);
            text-decoration:none; padding:9px 14px; border-radius:9px;
            font-size:13px; font-weight:700; display:inline-block;
        }

        /* ── KPIs del período ──────────────────────────────────────────────── */
        .kpis {
            display:grid; grid-template-columns:repeat(3,1fr); gap:10px;
            margin-bottom:16px;
        }
        @media(max-width:480px){ .kpis { grid-template-columns:1fr 1fr; } }
        .kpi {
            background:var(--white); border-radius:12px; padding:14px 16px;
            box-shadow:0 1px 4px rgba(0,0,0,.06);
        }
        .kpi-n  { font-size:20px; font-weight:800; }
        .kpi-l  { font-size:11px; color:var(--g5); text-transform:uppercase;
                  letter-spacing:.4px; margin-top:2px; }

        /* ── Tabla de movimientos ──────────────────────────────────────────── */
        .tabla-card {
            background:var(--white); border-radius:14px;
            box-shadow:0 1px 4px rgba(0,0,0,.06); overflow-x:auto;
        }
        table { width:100%; border-collapse:collapse; min-width:500px; }
        thead th {
            font-size:11px; font-weight:700; text-transform:uppercase;
            letter-spacing:.4px; color:var(--g5); padding:11px 14px;
            background:var(--g9); text-align:left; border-bottom:1px solid var(--g8);
            white-space:nowrap;
        }
        th.r, td.r { text-align:right; }
        tbody td {
            padding:10px 14px; font-size:13px;
            border-bottom:1px solid var(--g9); vertical-align:top;
        }
        tbody tr:last-child td { border-bottom:none; }
        /* Filas de cargo (compra a fiado) */
        .fila-cargo td:first-child { border-left:3px solid var(--red); }
        /* Filas de abono (pago) */
        .fila-abono td:first-child { border-left:3px solid var(--green); }
        /* Filas anuladas */
        .fila-anulada td { opacity:.45; text-decoration:line-through; }
        .fila-anulada td:first-child { border-left:3px solid var(--g8); }

        .badge-cargo  { background:#fee2e2; color:var(--red);   font-size:10px; font-weight:700;
                        padding:2px 7px; border-radius:20px; white-space:nowrap; }
        .badge-abono  { background:#d1fae5; color:#065f46; font-size:10px; font-weight:700;
                        padding:2px 7px; border-radius:20px; white-space:nowrap; }
        .badge-anulada{ background:var(--g9); color:var(--g5); font-size:10px; font-weight:700;
                        padding:2px 7px; border-radius:20px; white-space:nowrap; }

        .monto-cargo  { font-weight:700; color:var(--red); }
        .monto-abono  { font-weight:700; color:var(--green); }
        .saldo-col    { font-weight:800; }
        .saldo-alto   { color:var(--red); }
        .saldo-bajo   { color:var(--yellow); }
        .saldo-cero2  { color:var(--green); }

        .notas-cell   { font-size:11px; color:var(--g5); margin-top:2px; }

        /* ── Fila de saldo inicial del período ─────────────────────────────── */
        .fila-saldo-ini td {
            background:#eff6ff; font-size:12px; color:#1e40af;
            font-style:italic; border-bottom:2px solid #93c5fd;
        }

        /* ── Footer de totales ─────────────────────────────────────────────── */
        .tabla-footer {
            display:flex; justify-content:flex-end; gap:24px;
            padding:14px 20px; border-top:2px solid var(--dark);
            flex-wrap:wrap;
        }
        .totales-col { text-align:right; }
        .totales-col .lbl { font-size:11px; color:var(--g5); text-transform:uppercase; }
        .totales-col .val { font-size:16px; font-weight:800; }

        /* ── Aviso sin movimientos ─────────────────────────────────────────── */
        .empty-state { text-align:center; padding:48px 20px; color:var(--g5); }
        .empty-state svg { width:40px; height:40px; margin-bottom:10px;
                           display:block; margin:0 auto 10px; }

        /* ═══════════════════════════════════════════════════════════════════
           ESTILOS DE IMPRESIÓN — solo el documento, sin nav ni filtros
           ═══════════════════════════════════════════════════════════════════ */
        @media print {
            nav, .filtros, .btn-print, .btn-back,
            .kpis, .no-print { display:none !important; }
            body   { background:#fff; }
            .main  { padding:0; max-width:100%; }
            .doc-header { box-shadow:none; border:1px solid #ccc;
                          border-radius:0; page-break-inside:avoid; }
            .tabla-card { box-shadow:none; border:1px solid #ccc;
                          border-radius:0; }
            table  { min-width:0 !important; }
            th, td { font-size:11px !important; padding:6px 8px !important; }
            .print-header {
                display:block !important; text-align:center;
                margin-bottom:16px; font-size:18px; font-weight:800;
            }
        }
        .print-header { display:none; }

        /* ── Responsive ────────────────────────────────────────────────────── */
        @media(max-width:540px){
            .doc-header { flex-direction:column; }
            .filtros    { flex-direction:column; }
            .fg input   { width:100%; }
        }
    </style>
</head>
<body>
<?php if (!$modo_impresion): ?>
<?php include __DIR__ . '/../app/views/nav.php'; ?>
<?php endif; ?>

<main class="main">

    <!-- Encabezado del documento (visible siempre, incluso al imprimir) -->
    <div class="print-header">
        Estado de Cuenta — <?= $nombre_completo ?>
    </div>

    <!-- ── Header del cliente ──────────────────────────────────────────────── -->
    <div class="doc-header">
        <div>
            <div class="doc-cliente-nombre"><?= $nombre_completo ?></div>
            <?php if (!empty($cliente['empresa'])): ?>
            <div class="doc-cliente-sub"><?= htmlspecialchars($cliente['empresa']) ?></div>
            <?php endif; ?>
            <?php if (!empty($cliente['telefono'])): ?>
            <div class="doc-cliente-sub">📞 <?= htmlspecialchars($cliente['telefono']) ?></div>
            <?php endif; ?>
            <div class="doc-cliente-sub" style="margin-top:6px;font-size:11px;color:var(--g5)">
                Período: <?= date('d/m/Y', strtotime($fecha_desde)) ?> — <?= date('d/m/Y', strtotime($fecha_hasta)) ?>
            </div>
        </div>
        <div style="text-align:right">
            <div style="font-size:11px;color:var(--g5);margin-bottom:4px;text-transform:uppercase;letter-spacing:.4px">Saldo actual</div>
            <div class="saldo-badge <?= $saldo_final > 0 ? 'saldo-positivo' : 'saldo-cero' ?>">
                $<?= number_format($saldo_final, 0, ',', '.') ?>
            </div>
        </div>
    </div>

    <!-- ── Filtros de fecha ────────────────────────────────────────────────── -->
    <form class="filtros no-print" method="GET">
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="fg">
            <label>Desde</label>
            <input type="date" name="desde" value="<?= htmlspecialchars($fecha_desde) ?>" max="<?= $hoy ?>">
        </div>
        <div class="fg">
            <label>Hasta</label>
            <input type="date" name="hasta" value="<?= htmlspecialchars($fecha_hasta) ?>" max="<?= $hoy ?>">
        </div>
        <button type="submit" class="btn btn-ver">Filtrar</button>
        <a href="?id=<?= $id ?>&desde=<?= urlencode($fecha_desde) ?>&hasta=<?= urlencode($fecha_hasta) ?>&print=1"
           class="btn-print" target="_blank">
            🖨 Imprimir
        </a>
        <a href="<?= APP_BASE ?>/clientes/" class="btn-back">← Clientes</a>
    </form>

    <!-- ── KPIs del período ───────────────────────────────────────────────── -->
    <div class="kpis no-print">
        <div class="kpi">
            <div class="kpi-n" style="color:var(--red)">$<?= number_format($total_cargos, 0, ',', '.') ?></div>
            <div class="kpi-l">Total fiado en período</div>
        </div>
        <div class="kpi">
            <div class="kpi-n" style="color:var(--green)">$<?= number_format($total_abonos, 0, ',', '.') ?></div>
            <div class="kpi-l">Total abonado en período</div>
        </div>
        <div class="kpi">
            <div class="kpi-n" style="color:<?= $saldo_final > 0 ? 'var(--red)' : '#065f46' ?>">
                $<?= number_format($saldo_final, 0, ',', '.') ?>
            </div>
            <div class="kpi-l">Saldo pendiente actual</div>
        </div>
    </div>

    <!-- ── Tabla de movimientos ───────────────────────────────────────────── -->
    <div class="tabla-card">
        <?php if (empty($movimientos)): ?>
        <div class="empty-state">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
            </svg>
            Sin movimientos en el período seleccionado.
        </div>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Tipo</th>
                    <th>Descripción</th>
                    <th class="r">Cargo (+)</th>
                    <th class="r">Abono (−)</th>
                    <th class="r">Saldo</th>
                </tr>
            </thead>
            <tbody>
                <!-- Fila de saldo al inicio del período (si hay movimientos previos) -->
                <?php if ($saldoPrePeriodo > 0): ?>
                <tr class="fila-saldo-ini">
                    <td><?= date('d/m/Y', strtotime($fecha_desde)) ?></td>
                    <td colspan="4">Saldo al inicio del período</td>
                    <td class="r" style="font-weight:800">
                        $<?= number_format($saldoPrePeriodo, 0, ',', '.') ?>
                    </td>
                </tr>
                <?php endif; ?>

                <?php foreach ($movimientos as $mov):
                    $esCargo  = $mov['tipo'] === 'cargo';
                    $esAbono  = $mov['tipo'] === 'abono';
                    $anulada  = $mov['anulada'];
                    $saldo    = $mov['saldo'];
                    $clsFila  = $anulada ? 'fila-anulada' : ($esCargo ? 'fila-cargo' : 'fila-abono');
                    $clsSaldo = $saldo > 50000 ? 'saldo-alto' : ($saldo > 0 ? 'saldo-bajo' : 'saldo-cero2');
                ?>
                <tr class="<?= $clsFila ?>">
                    <td style="white-space:nowrap">
                        <?= date('d/m/Y', strtotime($mov['fecha'])) ?>
                        <div style="font-size:10px;color:var(--g5)">
                            <?= date('H:i', strtotime($mov['fecha'])) ?>
                        </div>
                    </td>
                    <td>
                        <?php if ($anulada): ?>
                        <span class="badge-anulada">Anulada</span>
                        <?php elseif ($esCargo): ?>
                        <span class="badge-cargo">Compra</span>
                        <?php else: ?>
                        <span class="badge-abono">Abono</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= htmlspecialchars($mov['descripcion']) ?>
                        <?php if (!empty($mov['notas'])): ?>
                        <div class="notas-cell"><?= htmlspecialchars($mov['notas']) ?></div>
                        <?php endif; ?>
                    </td>
                    <!-- Cargo: columna positiva (dinero que debe el cliente) -->
                    <td class="r <?= $esCargo && !$anulada ? 'monto-cargo' : '' ?>">
                        <?= $esCargo && !$anulada ? '$' . number_format($mov['monto'], 0, ',', '.') : '—' ?>
                    </td>
                    <!-- Abono: columna negativa (dinero que paga el cliente) -->
                    <td class="r <?= $esAbono ? 'monto-abono' : '' ?>">
                        <?= $esAbono ? '$' . number_format($mov['monto'], 0, ',', '.') : '—' ?>
                    </td>
                    <!-- Saldo corriente acumulado -->
                    <td class="r saldo-col <?= $anulada ? '' : $clsSaldo ?>">
                        $<?= number_format($saldo, 0, ',', '.') ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Totales del período -->
        <div class="tabla-footer">
            <div class="totales-col">
                <div class="lbl">Total cargado</div>
                <div class="val" style="color:var(--red)">+$<?= number_format($total_cargos, 0, ',', '.') ?></div>
            </div>
            <div class="totales-col">
                <div class="lbl">Total abonado</div>
                <div class="val" style="color:var(--green)">−$<?= number_format($total_abonos, 0, ',', '.') ?></div>
            </div>
            <div class="totales-col" style="border-left:2px solid var(--dark);padding-left:24px">
                <div class="lbl">Saldo actual en sistema</div>
                <div class="val" style="color:<?= $saldo_final > 0 ? 'var(--red)' : '#065f46' ?>">
                    $<?= number_format($saldo_final, 0, ',', '.') ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Nota legal / pie del documento -->
    <div style="margin-top:20px;font-size:11px;color:var(--g5);text-align:center" class="no-print">
        Estado de cuenta generado el <?= date('d/m/Y H:i') ?> · <?= APP_NAME ?>
    </div>

</main>

<?php if ($modo_impresion): ?>
<script>window.onload = function(){ window.print(); }</script>
<?php endif; ?>

</body>
</html>
