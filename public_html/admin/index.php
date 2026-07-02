<?php
/**
 * admin/index.php — Panel de administración del sistema.
 * Accesible solo para usuarios con rol superadmin o admin.
 */
require_once __DIR__ . '/../app/middleware/auth_check.php';

$nav_activo = 'admin';
$nav_sub    = 'resumen';

if (!in_array($_SESSION['usuario_rol'] ?? '', ['superadmin','admin'], true)) {
    http_response_code(403);
    include __DIR__ . '/../app/views/errors/403.php';
    exit;
}

// ── Estadísticas rápidas (envueltas en try-catch para tolerancia a migraciones parciales) ──
$stats = ['total_usuarios'=>0,'activos'=>0,'inactivos'=>0,'superadmins'=>0,'ultimo_login_global'=>null];
try {
    $stats = db()->query(
        "SELECT COUNT(*) AS total_usuarios, SUM(activo=1) AS activos,
                SUM(activo=0) AS inactivos, SUM(rol='superadmin') AS superadmins,
                MAX(ultimo_login) AS ultimo_login_global
         FROM usuarios"
    )->fetch() ?: $stats;
} catch (Exception $e) { /* tabla usuarios siempre existe, por seguridad */ }

// information_schema puede estar restringido en hosting compartido
$total_tablas = 0;
try {
    $total_tablas = (int) db()->query(
        "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()"
    )->fetchColumn();
} catch (Exception $e) {
    // Fallback: contar tablas con SHOW TABLES
    try { $total_tablas = count(db()->query("SHOW TABLES")->fetchAll()); } catch (Exception $e2) {}
}

$total_ventas_hoy = 0;
try {
    $total_ventas_hoy = (int) db()->query(
        "SELECT COUNT(*) FROM ventas WHERE DATE(fecha_venta) = CURDATE() AND estado != 'anulada'"
    )->fetchColumn();
} catch (Exception $e) {}

$version_app = defined('APP_VERSION') ? APP_VERSION : '4.4';

// ── Verificador de migraciones pendientes ─────────────────────────────────────
// Detecta si las migraciones clave ya fueron aplicadas chequeando columnas/tablas.
// Solo visible para superadmin. Útil antes de actualizar el código.
$migraciones_pendientes = [];
if (($_SESSION['usuario_rol'] ?? '') === 'superadmin') {
    $checks = [
        // [descripcion, SQL para verificar, resultado esperado]
        ['026 — ventas.metodo_pago incluye obsequio',
         "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ventas' AND COLUMN_NAME='metodo_pago' AND COLUMN_TYPE LIKE '%obsequio%'",
         1],
        ['026 — tabla ajustes_stock existe',
         "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ajustes_stock'",
         1],
        ['027 — productos.nombre2 existe',
         "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='productos' AND COLUMN_NAME='nombre2'",
         1],
        ['028 — clientes.apellido existe',
         "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='clientes' AND COLUMN_NAME='apellido'",
         1],
        ['029 — tabla listas_sistema existe',
         "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='listas_sistema'",
         1],
        ['030 — insumos.equiv_cantidad existe',
         "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='insumos' AND COLUMN_NAME='equiv_cantidad'",
         1],
        ['031 — productos.categoria es VARCHAR (catálogos dinámicos)',
         "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='productos' AND COLUMN_NAME='categoria' AND DATA_TYPE='varchar'",
         1],
        ['031 — insumos.unidad_medida es VARCHAR (catálogos dinámicos)',
         "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='insumos' AND COLUMN_NAME='unidad_medida' AND DATA_TYPE='varchar'",
         1],
        ['032 — compra_detalles.precio_presentacion existe (snapshot empaque)',
         "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='compra_detalles' AND COLUMN_NAME='precio_presentacion'",
         1],
        ['033 — nomina_liquidaciones.valor_hora_snap existe (snapshot tarifa)',
         "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nomina_liquidaciones' AND COLUMN_NAME='valor_hora_snap'",
         1],
        ['034 — venta_detalles.nombre_snap existe (snapshot nombre producto)',
         "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='venta_detalles' AND COLUMN_NAME='nombre_snap'",
         1],
        ['034 — pagos_fiado.saldo_anterior existe (snapshot saldo antes del abono)',
         "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pagos_fiado' AND COLUMN_NAME='saldo_anterior'",
         1],
    ];
    foreach ($checks as [$desc, $sql, $esperado]) {
        try {
            $resultado = (int)db()->query($sql)->fetchColumn();
            if ($resultado !== $esperado) {
                $migraciones_pendientes[] = $desc;
            }
        } catch (Exception $e) {
            $migraciones_pendientes[] = $desc . ' (error al verificar)';
        }
    }
}

// Últimos 10 cambios en logs_historial
$logs = [];
try {
    $logs = db()->query(
        "SELECT l.tabla, l.campo, l.valor_nuevo, l.accion, l.fecha_cambio,
                u.nombre AS usuario_nombre
         FROM logs_historial l
         LEFT JOIN usuarios u ON u.id = l.usuario_id
         ORDER BY l.fecha_cambio DESC
         LIMIT 10"
    )->fetchAll();
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin — <?= APP_NAME ?></title>
    <style>
        :root { --brand:#e94f37; --dark:#111827; --g5:#6b7280; --g8:#d1d5db; --g9:#f3f4f6; --white:#fff; --green:#059669; }
        *,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
        body { font-family:system-ui,-apple-system,sans-serif; background:var(--g9); color:var(--dark); }
        .main { max-width:1000px; margin:0 auto; padding:20px 14px 60px; }
        .page-title { font-size:22px; font-weight:800; margin-bottom:20px; }
        .kpi-row { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:12px; margin-bottom:24px; }
        .kpi { background:var(--white); border:1px solid var(--g8); border-radius:12px; padding:16px; }
        .kpi-val { font-size:26px; font-weight:800; }
        .kpi-val.brand { color:var(--brand); }
        .kpi-val.green { color:var(--green); }
        .kpi-lbl { font-size:11px; color:var(--g5); margin-top:4px; text-transform:uppercase; letter-spacing:.4px; }

        .cards-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:14px; margin-bottom:24px; }
        .nav-card {
            background:var(--white); border-radius:14px; padding:20px;
            box-shadow:0 1px 4px rgba(0,0,0,.06); text-decoration:none; color:var(--dark);
            transition:box-shadow .15s,transform .1s; border:1px solid transparent; display:block;
        }
        .nav-card:hover { box-shadow:0 6px 20px rgba(0,0,0,.1); transform:translateY(-2px); border-color:var(--brand); }
        /* Icono SVG — mismo patrón que reportes/index.php y dashboard.php */
        .nav-card-icon {
            width:44px; height:44px; background:#fef2f0; border-radius:12px;
            display:flex; align-items:center; justify-content:center;
            margin-bottom:12px; flex-shrink:0;
        }
        .nav-card-icon svg {
            width:22px; height:22px; stroke:var(--brand); fill:none;
            stroke-width:1.8; stroke-linecap:round; stroke-linejoin:round;
        }
        .nav-card-title { font-size:15px; font-weight:700; margin-bottom:4px; }
        .nav-card-desc  { font-size:12px; color:var(--g5); }

        .section-title { font-size:14px; font-weight:700; margin-bottom:10px; color:var(--g5); text-transform:uppercase; letter-spacing:.5px; }
        .log-wrap { background:var(--white); border:1px solid var(--g8); border-radius:12px; overflow:hidden; overflow-x:auto; }
        .log-tbl { width:100%; border-collapse:collapse; }
        .log-tbl th { background:var(--g9); font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; padding:10px 12px; text-align:left; color:var(--g5); }
        .log-tbl td { padding:9px 12px; font-size:12px; border-bottom:1px solid var(--g9); }
        .log-tbl tr:last-child td { border-bottom:none; }
        .badge-ins { background:#d1fae5; color:#065f46; font-size:10px; padding:1px 6px; border-radius:999px; }
        .badge-upd { background:#dbeafe; color:#1d4ed8; font-size:10px; padding:1px 6px; border-radius:999px; }
        .badge-del { background:#fee2e2; color:#991b1b; font-size:10px; padding:1px 6px; border-radius:999px; }

        /* ════════════════════════════════════════════════════════════════
           RESPONSIVE — ADMIN INDEX
           ════════════════════════════════════════════════════════════════ */
        /* Log tabla con scroll horizontal */
        .log-wrap { overflow-x:auto; -webkit-overflow-scrolling:touch; }

        /* xs: < 480px */
        @media (max-width:479px) {
            .main { padding:12px 10px 40px; }
            .page-title { font-size:18px; }
            .kpi-row { grid-template-columns:1fr 1fr !important; gap:8px; }
            .kpi-val { font-size:20px !important; }
            /* Cards de navegación en 1 col */
            .cards-grid { grid-template-columns:1fr !important; gap:10px; }
            .log-tbl { min-width:480px; }
        }
        /* sm: 480-639px */
        @media (min-width:480px) and (max-width:639px) {
            .cards-grid { grid-template-columns:repeat(2,1fr) !important; }
            .log-tbl { min-width:480px; }
        }
        /* ≥1600px */
        @media (min-width:1600px) {
            .main { max-width:1300px; }
            .kpi-val { font-size:30px !important; }
            .log-tbl th, .log-tbl td { padding:11px 14px !important; font-size:13px !important; }
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

    <h1 class="page-title">Panel de Administración</h1>

    <!-- KPIs del sistema -->
    <div class="kpi-row">
        <div class="kpi">
            <div class="kpi-val"><?= (int)$stats['total_usuarios'] ?></div>
            <div class="kpi-lbl">Usuarios totales</div>
        </div>
        <div class="kpi">
            <div class="kpi-val green"><?= (int)$stats['activos'] ?></div>
            <div class="kpi-lbl">Usuarios activos</div>
        </div>
        <div class="kpi">
            <div class="kpi-val brand"><?= $total_ventas_hoy ?></div>
            <div class="kpi-lbl">Ventas hoy</div>
        </div>
        <div class="kpi">
            <div class="kpi-val"><?= $total_tablas ?></div>
            <div class="kpi-lbl">Tablas en DB</div>
        </div>
        <div class="kpi">
            <div class="kpi-val" style="font-size:18px">v<?= htmlspecialchars($version_app) ?></div>
            <div class="kpi-lbl">Versión del sistema</div>
        </div>
    </div>

    <!-- Accesos rápidos — iconos SVG consistentes con el resto del sistema -->
    <p class="section-title">Administración</p>
    <div class="cards-grid">

        <!-- Usuarios y permisos -->
        <a href="<?= APP_BASE ?>/admin/usuarios.php" class="nav-card">
            <div class="nav-card-icon">
                <svg viewBox="0 0 24 24">
                    <path d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
            </div>
            <div class="nav-card-title">Usuarios y Permisos</div>
            <div class="nav-card-desc">Crear, editar y gestionar accesos al sistema. <?= (int)$stats['activos'] ?> activos.</div>
        </a>

        <!-- Apariencia -->
        <a href="<?= APP_BASE ?>/admin/apariencia.php" class="nav-card">
            <div class="nav-card-icon">
                <svg viewBox="0 0 24 24">
                    <path d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                </svg>
            </div>
            <div class="nav-card-title">Apariencia y Negocio</div>
            <div class="nav-card-desc">Logo, nombre del negocio, colores y tipografía.</div>
        </a>

        <!-- Catálogos (listas configurables) -->
        <a href="<?= APP_BASE ?>/admin/listas.php" class="nav-card">
            <div class="nav-card-icon">
                <svg viewBox="0 0 24 24">
                    <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/>
                </svg>
            </div>
            <div class="nav-card-title">Catálogos</div>
            <div class="nav-card-desc">Gestionar opciones de desplegables: presentaciones, categorías, tamaños y más.</div>
        </a>

        <!-- Base de datos -->
        <a href="<?= APP_BASE ?>/admin/backup.php" class="nav-card">
            <div class="nav-card-icon">
                <svg viewBox="0 0 24 24">
                    <path d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/>
                </svg>
            </div>
            <div class="nav-card-title">Base de Datos</div>
            <div class="nav-card-desc">Backup, migraciones y actualizaciones del sistema.</div>
        </a>

        <?php if (($_SESSION['usuario_rol'] ?? '') === 'superadmin'): ?>
        <a href="<?= APP_BASE ?>/admin/mantenimiento.php" class="nav-card">
            <div class="nav-card-icon">
                <svg viewBox="0 0 24 24">
                    <path d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                </svg>
            </div>
            <div class="nav-card-title">Mantenimiento de datos</div>
            <div class="nav-card-desc">Limpieza masiva: reset transaccional, borrar inactivos/anulados por módulo.</div>
        </a>

        <a href="<?= APP_BASE ?>/tests/suite.php" class="nav-card">
            <div class="nav-card-icon">
                <svg viewBox="0 0 24 24">
                    <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <div class="nav-card-title">Pruebas de integridad</div>
            <div class="nav-card-desc">Ejecuta la suite de tests (36 grupos): esquema, datos, seguridad, formato y más.</div>
        </a>

        <a href="<?= APP_BASE ?>/admin/auditor_costos.php" class="nav-card">
            <div class="nav-card-icon">
                <svg viewBox="0 0 24 24">
                    <path d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
            </div>
            <div class="nav-card-title">Auditor de costos</div>
            <div class="nav-card-desc">Diagnóstico de la cadena de costos (insumo→presentación→costo→margen) + recalcular.</div>
        </a>
        <?php endif; ?>

    </div>

    <!-- Verificador de migraciones (solo superadmin) -->
    <?php if (!empty($migraciones_pendientes)): ?>
    <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:12px;
                padding:14px 18px;margin-bottom:20px;">
        <p style="font-size:14px;font-weight:700;color:#9a3412;margin-bottom:8px">
            ⚠ Migraciones pendientes de aplicar
        </p>
        <ul style="padding-left:18px;font-size:13px;color:#92400e;line-height:1.8">
            <?php foreach ($migraciones_pendientes as $m): ?>
            <li><?= htmlspecialchars($m) ?></li>
            <?php endforeach; ?>
        </ul>
        <p style="font-size:12px;color:#b45309;margin-top:8px">
            Ve a <a href="<?= APP_BASE ?>/admin/backup.php" style="color:#d97706;font-weight:700">Admin → Base de Datos → Ejecutar Migración</a>
            y sube los archivos SQL correspondientes.
        </p>
    </div>
    <?php elseif (($_SESSION['usuario_rol'] ?? '') === 'superadmin'): ?>
    <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;
                padding:10px 18px;margin-bottom:20px;font-size:13px;color:#065f46">
        ✅ Todas las migraciones conocidas están aplicadas.
    </div>
    <?php endif; ?>

    <!-- Últimos cambios en el sistema -->
    <?php if (!empty($logs)): ?>
    <p class="section-title">Últimos cambios registrados</p>
    <div class="log-wrap">
        <table class="log-tbl">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Tabla</th>
                    <th>Campo</th>
                    <th>Valor nuevo</th>
                    <th>Acción</th>
                    <th>Usuario</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($logs as $l): ?>
            <tr>
                <td style="color:var(--g5)"><?= date('d/m H:i', strtotime($l['fecha_cambio'])) ?></td>
                <td><code style="font-size:11px"><?= htmlspecialchars($l['tabla']) ?></code></td>
                <td style="color:var(--g5)"><?= htmlspecialchars($l['campo'] ?? '') ?></td>
                <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                    <?= htmlspecialchars(substr($l['valor_nuevo'] ?? '', 0, 40)) ?>
                </td>
                <td>
                    <?php
                    $a = strtoupper($l['accion'] ?? '');
                    $cls = $a === 'INSERT' ? 'badge-ins' : ($a === 'DELETE' ? 'badge-del' : 'badge-upd');
                    ?>
                    <span class="<?= $cls ?>"><?= $a ?></span>
                </td>
                <td style="color:var(--g5)"><?= htmlspecialchars($l['usuario_nombre'] ?? '—') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

</main>
</body>
</html>
