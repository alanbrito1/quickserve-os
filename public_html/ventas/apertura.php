<?php
/**
 * ventas/apertura.php — Apertura de turno / fondo de caja.
 *
 * Permite registrar al inicio del día:
 *  - El fondo de caja en efectivo (billetes para dar cambio)
 *  - Notas del turno
 *  - Quién abre
 *
 * Detecta si ya existe un turno para el día (mig.037 — tabla turnos_caja).
 * Si la migración no está aplicada, muestra mensaje informativo y no falla.
 */

require_once __DIR__ . '/../app/middleware/auth_check.php';

$nav_activo = 'ventas';
permiso_requerir('ventas', 'solo_propios');

$pdo = db();
$hoy = date('Y-m-d');
$uid = (int)($_SESSION['usuario_id']   ?? 0);
$rol = $_SESSION['usuario_rol']         ?? '';
$nom = $_SESSION['usuario_nombre']      ?? 'Usuario';

// Detectar migración 037
$tiene_037 = false;
try {
    $tiene_037 = (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='turnos_caja'
           AND COLUMN_NAME='id'"
    )->fetchColumn() > 0;
} catch (\Exception $e) {}

$msg_ok  = '';
$msg_err = '';
$turno_hoy = null;

if ($tiene_037) {
    // Cargar turno del día
    $stmt_t = $pdo->prepare(
        "SELECT tc.*, u.nombre AS nombre_apertura,
                uc.nombre AS nombre_cierre
         FROM turnos_caja tc
         LEFT JOIN usuarios u  ON u.id  = tc.usuario_apertura
         LEFT JOIN usuarios uc ON uc.id = tc.usuario_cierre
         WHERE tc.fecha = ? ORDER BY tc.id DESC LIMIT 1"
    );
    $stmt_t->execute([$hoy]);
    $turno_hoy = $stmt_t->fetch();

    // ── POST: Abrir turno ─────────────────────────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
        if (!csrf_verificar()) {
            $msg_err = 'Token de seguridad inválido. Recarga la página.';
        } else {
            $accion = $_POST['accion'];

            if ($accion === 'abrir') {
                if ($turno_hoy && $turno_hoy['estado'] === 'abierto') {
                    $msg_err = 'Ya existe un turno abierto para hoy.';
                } else {
                    $fondo  = max(0.0, (float)str_replace(['.', ','], ['', '.'], $_POST['fondo_inicial'] ?? '0'));
                    $notas  = substr(trim($_POST['notas_apertura'] ?? ''), 0, 500);
                    try {
                        $pdo->prepare(
                            "INSERT INTO turnos_caja (fecha, fondo_inicial, notas_apertura, usuario_apertura, estado)
                             VALUES (?, ?, ?, ?, 'abierto')"
                        )->execute([$hoy, $fondo, $notas ?: null, $uid]);
                        log_registrar('turnos_caja', (int)$pdo->lastInsertId(), 'estado', null, 'abierto', 'INSERT');
                        $msg_ok = 'Turno abierto correctamente.';
                        // Recargar turno
                        $stmt_t->execute([$hoy]);
                        $turno_hoy = $stmt_t->fetch();
                    } catch (\Exception $e) {
                        error_log('[QuickServe OS Apertura] ' . $e->getMessage());
                        $msg_err = 'Error al abrir el turno.';
                    }
                }

            } elseif ($accion === 'cerrar') {
                if (!$turno_hoy || $turno_hoy['estado'] !== 'abierto') {
                    $msg_err = 'No hay turno abierto para cerrar.';
                } elseif (!in_array($rol, ['superadmin', 'admin'])) {
                    $msg_err = 'Solo admin puede cerrar el turno.';
                } else {
                    $notas_c = substr(trim($_POST['notas_cierre'] ?? ''), 0, 500);
                    try {
                        $pdo->prepare(
                            "UPDATE turnos_caja
                             SET estado='cerrado', fecha_cierre=NOW(), usuario_cierre=?, notas_cierre=?
                             WHERE id=?"
                        )->execute([$uid, $notas_c ?: null, $turno_hoy['id']]);
                        log_registrar('turnos_caja', $turno_hoy['id'], 'estado', 'abierto', 'cerrado', 'UPDATE');
                        $msg_ok = 'Turno cerrado.';
                        $stmt_t->execute([$hoy]);
                        $turno_hoy = $stmt_t->fetch();
                    } catch (\Exception $e) {
                        error_log('[QuickServe OS Apertura] ' . $e->getMessage());
                        $msg_err = 'Error al cerrar el turno.';
                    }
                }
            }
        }
    }
}

// ── Historial reciente (últimos 10 turnos) ────────────────────────────────────
$historial = [];
if ($tiene_037) {
    try {
        $historial = $pdo->query(
            "SELECT tc.*, u.nombre AS nombre_apertura
             FROM turnos_caja tc
             LEFT JOIN usuarios u ON u.id = tc.usuario_apertura
             ORDER BY tc.fecha DESC, tc.id DESC LIMIT 10"
        )->fetchAll();
    } catch (\Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Apertura de Turno — <?= APP_NAME ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root { --brand:#e94f37; --dark:#111827; --g2:#374151; --g5:#6b7280;
                --g8:#d1d5db; --g9:#f3f4f6; --white:#fff; --green:#059669; }
        body { font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
               background:var(--g9); min-height:100vh; color:var(--dark); padding-bottom:40px; }
        .main { padding:16px 14px; max-width:680px; margin:0 auto; }
        h1 { font-size:20px; font-weight:800; margin-bottom:4px; }
        .sub { font-size:13px; color:var(--g5); margin-bottom:20px; }

        .card { background:var(--white); border-radius:14px; box-shadow:0 1px 4px rgba(0,0,0,.07);
                padding:20px 22px; margin-bottom:16px; }
        .card-title { font-size:15px; font-weight:800; margin-bottom:14px; }

        .fg { display:flex; flex-direction:column; gap:4px; margin-bottom:12px; }
        .fg label { font-size:11px; font-weight:700; text-transform:uppercase;
                    letter-spacing:.5px; color:var(--g5); }
        .fg input, .fg textarea { padding:10px 12px; border:2px solid var(--g8); border-radius:9px;
                                   font-size:15px; color:var(--dark); outline:none;
                                   width:100%; background:var(--white); }
        .fg input:focus, .fg textarea:focus { border-color:var(--brand); }
        .fg textarea { resize:vertical; min-height:70px; font-size:13px; }

        .btn { padding:11px 22px; border:none; border-radius:10px; font-size:14px;
               font-weight:700; cursor:pointer; }
        .btn-brand { background:var(--brand); color:#fff; }
        .btn-brand:hover { opacity:.9; }
        .btn-sec { background:var(--white); color:var(--dark); border:1px solid var(--g8); }
        .btn-sec:hover { border-color:var(--brand); color:var(--brand); }
        .btn-dark { background:#374151; color:#fff; }

        .alert { padding:12px 16px; border-radius:10px; font-size:13px; margin-bottom:16px; }
        .alert-ok   { background:#d1fae5; color:#065f46; border:1px solid #6ee7b7; }
        .alert-err  { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; }
        .alert-info { background:#eff6ff; color:#1e40af; border:1px solid #bfdbfe; }
        .alert-warn { background:#fffbeb; color:#92400e; border:1px solid #fcd34d; }

        /* Estado del turno */
        .turno-estado { display:flex; align-items:center; gap:10px; padding:14px 18px;
                        border-radius:12px; margin-bottom:16px; }
        .turno-abierto  { background:#d1fae5; border:1px solid #6ee7b7; }
        .turno-cerrado  { background:#f3f4f6; border:1px solid #d1d5db; }
        .turno-sin      { background:#fffbeb; border:1px solid #fcd34d; }
        .dot { width:12px; height:12px; border-radius:50%; flex-shrink:0; }
        .dot-verde  { background:#059669; }
        .dot-gris   { background:#9ca3af; }
        .dot-amarillo { background:#d97706; }
        .turno-label { font-size:14px; font-weight:700; }
        .turno-meta  { font-size:12px; color:var(--g5); }

        /* KPIs del turno */
        .kpi-row { display:grid; grid-template-columns:1fr 1fr 1fr; gap:10px; margin-bottom:14px; }
        .kpi { background:var(--g9); border-radius:10px; padding:12px 14px; }
        .kpi-n { font-size:20px; font-weight:800; }
        .kpi-l { font-size:11px; color:var(--g5); text-transform:uppercase; }

        /* Historial */
        .hist-table { width:100%; border-collapse:collapse; font-size:13px; }
        .hist-table th { background:var(--g9); font-size:10px; font-weight:700;
                         text-transform:uppercase; letter-spacing:.4px; padding:8px 12px;
                         text-align:left; color:var(--g5); }
        .hist-table td { padding:9px 12px; border-top:1px solid var(--g9); vertical-align:middle; }
        .badge { font-size:10px; font-weight:700; padding:2px 8px; border-radius:99px; }
        .b-abierto { background:#d1fae5; color:#065f46; }
        .b-cerrado { background:#f3f4f6; color:#6b7280; }

        @media(max-width:480px) { .kpi-row { grid-template-columns:1fr 1fr; } }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/../app/views/nav.php'; ?>
<div class="main">
    <h1>🏪 Apertura de Turno</h1>
    <p class="sub">Registra el fondo de caja al iniciar el día.</p>

    <?php if ($msg_ok): ?>
    <div class="alert alert-ok"><?= htmlspecialchars($msg_ok) ?></div>
    <?php endif; ?>
    <?php if ($msg_err): ?>
    <div class="alert alert-err"><?= htmlspecialchars($msg_err) ?></div>
    <?php endif; ?>

    <div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap">
        <a href="<?= APP_BASE ?>/ventas/cierre.php" class="btn btn-sec">🧾 Cierre de caja</a>
        <a href="<?= APP_BASE ?>/ventas/" class="btn btn-sec">🛒 Ir al POS</a>
    </div>

    <?php if (!$tiene_037): ?>
    <div class="alert alert-warn">
        <strong>Migración 037 no aplicada.</strong>
        Ve a <a href="<?= APP_BASE ?>/admin/backup.php">Admin → Base de Datos</a> y aplica
        <code>037_turnos_caja.sql</code> para habilitar el registro de turnos.
    </div>

    <?php else: ?>

    <!-- ── Estado del turno de hoy ───────────────────────────────────────── -->
    <?php if ($turno_hoy && $turno_hoy['estado'] === 'abierto'): ?>
    <div class="turno-estado turno-abierto">
        <div class="dot dot-verde"></div>
        <div>
            <div class="turno-label">Turno abierto</div>
            <div class="turno-meta">
                Abierto por <?= htmlspecialchars($turno_hoy['nombre_apertura'] ?? '—') ?>
                a las <?= date('H:i', strtotime($turno_hoy['fecha_apertura'])) ?>
            </div>
        </div>
    </div>

    <div class="kpi-row">
        <div class="kpi">
            <div class="kpi-n">$<?= fmt_moneda((float)$turno_hoy['fondo_inicial']) ?></div>
            <div class="kpi-l">Fondo inicial</div>
        </div>
        <?php
        // Ventas en efectivo del día (excluye fiado, obsequio)
        $ef_stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(total),0) FROM ventas
             WHERE DATE(fecha_venta)=? AND metodo_pago='efectivo'
               AND estado IN ('completada','pendiente_pago')"
        );
        $ef_stmt->execute([$hoy]);
        $ef_hoy     = (float)$ef_stmt->fetchColumn();
        $total_caja = (float)$turno_hoy['fondo_inicial'] + $ef_hoy;
        ?>
        <div class="kpi">
            <div class="kpi-n" style="color:var(--green)">$<?= fmt_moneda($ef_hoy) ?></div>
            <div class="kpi-l">Efectivo cobrado</div>
        </div>
        <div class="kpi">
            <div class="kpi-n" style="color:var(--brand)">$<?= fmt_moneda($total_caja) ?></div>
            <div class="kpi-l">Total en caja</div>
        </div>
    </div>

    <?php if ($turno_hoy['notas_apertura']): ?>
    <div class="alert alert-info" style="margin-bottom:12px">
        📝 <?= htmlspecialchars($turno_hoy['notas_apertura']) ?>
    </div>
    <?php endif; ?>

    <a href="<?= APP_BASE ?>/ventas/cierre.php?fecha=<?= $hoy ?>" class="btn btn-brand"
       style="display:inline-block;text-decoration:none;margin-bottom:16px">
        🧾 Ver cierre de hoy →
    </a>

    <?php if (in_array($rol, ['superadmin', 'admin'])): ?>
    <div class="card">
        <div class="card-title">Cerrar turno</div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="accion" value="cerrar">
            <div class="fg">
                <label>Notas de cierre (opcional)</label>
                <textarea name="notas_cierre" placeholder="Observaciones del turno…"></textarea>
            </div>
            <button type="submit" class="btn btn-dark"
                    onclick="return confirm('¿Cerrar el turno de hoy?')">
                🔒 Cerrar turno
            </button>
        </form>
    </div>
    <?php endif; ?>

    <?php elseif ($turno_hoy && $turno_hoy['estado'] === 'cerrado'): ?>
    <div class="turno-estado turno-cerrado">
        <div class="dot dot-gris"></div>
        <div>
            <div class="turno-label" style="color:var(--g5)">Turno cerrado</div>
            <div class="turno-meta">
                Cerrado a las <?= date('H:i', strtotime($turno_hoy['fecha_cierre'])) ?>
                · Fondo inicial: $<?= fmt_moneda((float)$turno_hoy['fondo_inicial']) ?>
            </div>
        </div>
    </div>
    <div class="alert alert-info">
        El turno de hoy ya fue cerrado. Si necesitas registrar otro turno,
        contacta a un administrador.
    </div>

    <?php else: ?>
    <!-- Sin turno para hoy -->
    <div class="turno-estado turno-sin">
        <div class="dot dot-amarillo"></div>
        <div>
            <div class="turno-label" style="color:#92400e">Sin turno abierto hoy</div>
            <div class="turno-meta"><?= date('d/m/Y') ?></div>
        </div>
    </div>

    <div class="card">
        <div class="card-title">🏪 Abrir turno de hoy</div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="accion" value="abrir">
            <div class="fg">
                <label>Fondo de caja inicial (efectivo en billetes)</label>
                <input type="number" name="fondo_inicial" value="0"
                       step="500" min="0" inputmode="numeric"
                       placeholder="Ej: 50000">
            </div>
            <div class="fg">
                <label>Notas del turno (opcional)</label>
                <textarea name="notas_apertura"
                          placeholder="Ej: Abre Juan, feria este fin de semana…"></textarea>
            </div>
            <div style="display:flex;gap:8px;align-items:center">
                <button type="submit" class="btn btn-brand">✅ Abrir turno</button>
                <span style="font-size:12px;color:var(--g5)">Turno para hoy: <?= date('d/m/Y') ?></span>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- ── Historial de turnos ────────────────────────────────────────────── -->
    <?php if (!empty($historial)): ?>
    <div class="card">
        <div class="card-title" style="margin-bottom:12px">Últimos 10 turnos</div>
        <div style="overflow-x:auto">
        <table class="hist-table">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th style="text-align:right">Fondo</th>
                    <th>Apertura</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($historial as $t): ?>
            <tr>
                <td>
                    <a href="<?= APP_BASE ?>/ventas/cierre.php?fecha=<?= $t['fecha'] ?>"
                       style="color:var(--brand);font-weight:600;text-decoration:none">
                        <?= date('d/m/Y', strtotime($t['fecha'])) ?>
                    </a>
                </td>
                <td style="text-align:right;font-weight:700">
                    $<?= fmt_moneda((float)$t['fondo_inicial']) ?>
                </td>
                <td style="color:var(--g5)">
                    <?= htmlspecialchars($t['nombre_apertura'] ?? '—') ?>
                    · <?= date('H:i', strtotime($t['fecha_apertura'])) ?>
                </td>
                <td>
                    <span class="badge b-<?= $t['estado'] ?>">
                        <?= $t['estado'] === 'abierto' ? '● Abierto' : 'Cerrado' ?>
                    </span>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; // $tiene_037 ?>
</div>
</body>
</html>
