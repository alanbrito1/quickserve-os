<?php
/**
 * public_html/ventas/historial.php
 * Historial de ventas con filtro por fecha. Soporta anulación (admin_total).
 */

require_once __DIR__ . '/../app/middleware/auth_check.php';
require_once __DIR__ . '/../app/models/VentaModel.php';

permiso_requerir('ventas', 'solo_ver');

// Filtros de fecha (default: hoy)
$fecha_desde = $_GET['desde'] ?? date('Y-m-d');
$fecha_hasta = $_GET['hasta'] ?? date('Y-m-d');

// solo_propios: el empleado solo ve sus ventas
$solo_uid = permiso_es_solo_propios('ventas') ? (int)$usuario_activo['id'] : null;

// Procesar anulación (solo admin_total)
$msg_ok  = '';
$msg_err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['anular_id'])) {
    permiso_requerir('ventas', 'admin_total');
    if (!csrf_verificar()) {
        $msg_err = 'Token de seguridad inválido.';
    } else {
        try {
            VentaModel::anular((int)$_POST['anular_id']);
            $msg_ok = 'Venta #' . (int)$_POST['anular_id'] . ' anulada correctamente. Stock revertido.';
        } catch (RuntimeException $e) {
            $msg_err = $e->getMessage();
        }
    }
}

$ventas = VentaModel::historial($fecha_desde, $fecha_hasta, $solo_uid);

// Totales del período filtrado
$total_pesos   = array_sum(array_column(
    array_filter($ventas, fn($v) => $v['estado'] === 'completada'), 'total'
));
$total_ventas  = count(array_filter($ventas, fn($v) => $v['estado'] === 'completada'));

$metodo_label = [
    'efectivo'    => 'Efectivo',
    'nequi'       => 'Nequi',
    'daviplata'   => 'Daviplata',
    'bancolombia' => 'Bancolombia',
    'fiado'       => 'Fiado',
];
$estado_class = ['completada' => 'badge-ok', 'anulada' => 'badge-anulada', 'pendiente_pago' => 'badge-pend'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historial — <?= APP_NAME ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root { --brand:#e94f37; --dark:#111827; --g1:#1f2937; --g2:#374151; --g5:#6b7280; --g8:#d1d5db; --g9:#f3f4f6; --white:#fff; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: var(--g9); color: var(--dark); min-height: 100vh; }

        /* Header */
        .header { background:var(--dark); color:var(--white); height:54px; padding:0 14px; display:flex; align-items:center; justify-content:space-between; position:sticky; top:0; z-index:50; box-shadow:0 2px 8px rgba(0,0,0,.35); }
        .header-brand { font-size:17px; font-weight:800; } .header-brand span{color:var(--brand);}
        .header-nav { display:flex; gap:6px; }
        .nav-link { color:var(--g8); text-decoration:none; font-size:13px; padding:5px 10px; border-radius:8px; }
        .nav-link:hover { background:var(--g2); color:var(--white); }
        .nav-link.active { background:var(--brand); color:var(--white); }

        /* Layout */
        .main { padding:16px 14px; max-width:900px; margin:0 auto; }
        .card { background:var(--white); border-radius:14px; padding:16px; margin-bottom:14px; box-shadow:0 1px 4px rgba(0,0,0,.06); }

        /* Filtros */
        .filtros-row { display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end; }
        .filtro-group { display:flex; flex-direction:column; gap:4px; }
        label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--g5); }
        input[type="date"] { padding:9px 12px; border:2px solid var(--g8); border-radius:10px; font-size:14px; color:var(--dark); outline:none; }
        input[type="date"]:focus { border-color:var(--brand); }
        .btn-filtrar { padding:9px 18px; background:var(--brand); color:var(--white); border:none; border-radius:10px; font-size:14px; font-weight:700; cursor:pointer; }

        /* Stats */
        .stats-row { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:14px; }
        @media(min-width:520px){.stats-row{grid-template-columns:repeat(4,1fr);}}
        .stat { background:var(--white); border-radius:14px; padding:14px; box-shadow:0 1px 4px rgba(0,0,0,.06); }
        .stat-label { font-size:11px; color:var(--g5); text-transform:uppercase; letter-spacing:.5px; }
        .stat-val { font-size:20px; font-weight:800; margin-top:4px; }

        /* Tabla / Lista */
        .venta-row { padding:12px 0; border-bottom:1px solid var(--g9); display:grid; grid-template-columns:auto 1fr auto; gap:4px 12px; align-items:center; }
        .venta-row:last-child { border-bottom:none; }
        .venta-id { font-size:11px; color:var(--g5); }
        .venta-hora { font-size:12px; color:var(--g5); }
        .venta-cliente { font-size:14px; font-weight:600; }
        .venta-metodo { font-size:12px; color:var(--g5); }
        .venta-total { font-size:16px; font-weight:800; text-align:right; }
        .venta-acciones { grid-column:1/-1; display:flex; gap:8px; align-items:center; }

        .badge { display:inline-block; font-size:10px; font-weight:700; padding:2px 8px; border-radius:20px; text-transform:uppercase; letter-spacing:.4px; }
        .badge-ok      { background:#d1fae5; color:#065f46; }
        .badge-anulada { background:#fee2e2; color:#991b1b; }
        .badge-pend    { background:#fef3c7; color:#92400e; }

        .btn-anular { background:#fee2e2; color:#991b1b; border:none; padding:5px 12px; border-radius:8px; font-size:12px; font-weight:700; cursor:pointer; }
        .btn-anular:hover { background:#fca5a5; }

        .alert { padding:12px 14px; border-radius:10px; font-size:14px; margin-bottom:14px; }
        .alert-ok  { background:#d1fae5; color:#065f46; border:1px solid #6ee7b7; }
        .alert-err { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; }

        .empty-state { text-align:center; padding:40px 0; color:var(--g5); }
    </style>
</head>
<body>
<?php $nav_activo = 'ventas'; include __DIR__ . '/../app/views/nav.php'; ?>


<main class="main">

    <?php if ($msg_ok):  ?><div class="alert alert-ok"><?= htmlspecialchars($msg_ok) ?></div><?php endif; ?>
    <?php if ($msg_err): ?><div class="alert alert-err"><?= htmlspecialchars($msg_err) ?></div><?php endif; ?>

    <!-- Filtros -->
    <div class="card">
        <form method="GET" action="">
            <div class="filtros-row">
                <div class="filtro-group">
                    <label>Desde</label>
                    <input type="date" name="desde" value="<?= htmlspecialchars($fecha_desde) ?>">
                </div>
                <div class="filtro-group">
                    <label>Hasta</label>
                    <input type="date" name="hasta" value="<?= htmlspecialchars($fecha_hasta) ?>">
                </div>
                <button type="submit" class="btn-filtrar">Buscar</button>
            </div>
        </form>
    </div>

    <!-- Stats del período -->
    <div class="stats-row">
        <div class="stat">
            <p class="stat-label">Ventas</p>
            <p class="stat-val"><?= $total_ventas ?></p>
        </div>
        <div class="stat">
            <p class="stat-label">Total</p>
            <p class="stat-val">$<?= number_format($total_pesos, 0, ',', '.') ?></p>
        </div>
        <div class="stat">
            <p class="stat-label">Promedio</p>
            <p class="stat-val">$<?= $total_ventas > 0 ? number_format($total_pesos / $total_ventas, 0, ',', '.') : '0' ?></p>
        </div>
        <div class="stat">
            <p class="stat-label">Anuladas</p>
            <p class="stat-val"><?= count(array_filter($ventas, fn($v) => $v['estado'] === 'anulada')) ?></p>
        </div>
    </div>

    <!-- Lista de ventas -->
    <div class="card">
        <?php if (empty($ventas)): ?>
            <div class="empty-state">
                <p style="font-size:28px; margin-bottom:8px;">📋</p>
                <p style="font-weight:600;">Sin ventas en este período</p>
            </div>
        <?php else: ?>
            <?php foreach ($ventas as $v): ?>
            <div class="venta-row">
                <!-- Columna 1: ID + hora -->
                <div>
                    <div class="venta-id">#<?= $v['id'] ?></div>
                    <div class="venta-hora"><?= date('H:i', strtotime($v['fecha_venta'])) ?></div>
                </div>
                <!-- Columna 2: cliente + método -->
                <div>
                    <div class="venta-cliente"><?= htmlspecialchars($v['cliente']) ?></div>
                    <div class="venta-metodo">
                        <?= htmlspecialchars($metodo_label[$v['metodo_pago']] ?? $v['metodo_pago']) ?>
                        · <?= $v['num_items'] ?> ítem<?= $v['num_items'] != 1 ? 's' : '' ?>
                        · <?= htmlspecialchars($v['cajero'] ?? '—') ?>
                    </div>
                </div>
                <!-- Columna 3: total + estado -->
                <div style="text-align:right">
                    <div class="venta-total">$<?= number_format($v['total'], 0, ',', '.') ?></div>
                    <span class="badge <?= $estado_class[$v['estado']] ?? 'badge-ok' ?>">
                        <?= htmlspecialchars($v['estado']) ?>
                    </span>
                </div>
                <!-- Fila de acciones -->
                <?php if ($v['estado'] === 'completada' && permiso_tiene('ventas', 'admin_total')): ?>
                <div class="venta-acciones">
                    <form method="POST" onsubmit="return confirm('¿Anular venta #<?= $v['id'] ?>? Esta acción revertirá el stock.')">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                        <input type="hidden" name="anular_id"  value="<?= $v['id'] ?>">
                        <button type="submit" class="btn-anular">✕ Anular</button>
                    </form>
                    <?php if ($v['notas']): ?>
                    <span style="font-size:12px; color:var(--g5)">📝 <?= htmlspecialchars($v['notas']) ?></span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</main>
</body>
</html>
