<?php
/**
 * public_html/dashboard.php
 * Panel principal del ERP. Muestra módulos accesibles y estadísticas del día.
 * Requiere sesión activa — el middleware redirige al login si no hay sesión.
 */

require_once __DIR__ . '/app/middleware/auth_check.php';
require_once __DIR__ . '/app/config/database.php';

// ---- Estadísticas rápidas del día ----
// Cada dato se consulta solo si el usuario tiene acceso al módulo correspondiente.

$stats = [
    'ventas_hoy'      => 0,
    'ventas_total'    => 0,
    'insumos_bajos'   => 0,
    'fiado_total'     => 0,
    'stock_terminado' => 0,  // unidades de producto terminado listas para vender
    'produccion_hoy'  => 0,  // sándwiches producidos hoy
    'costos_mes'      => 0,  // costos indirectos activos del mes
];

if (permiso_tiene('ventas', 'solo_ver')) {
    $row = db()->query(
        "SELECT COUNT(*) AS n, IFNULL(SUM(total),0) AS monto
         FROM ventas
         WHERE DATE(fecha_venta) = CURDATE() AND estado = 'completada'"
    )->fetch();
    $stats['ventas_hoy']   = (int)$row['n'];
    $stats['ventas_total'] = (float)$row['monto'];

    // Fiado real: suma de ventas fiadas sin fecha_pago (no pagadas aún)
    $row2 = db()->query(
        "SELECT IFNULL(SUM(total),0) AS total
         FROM ventas
         WHERE metodo_pago = 'fiado' AND fecha_pago IS NULL AND estado != 'anulada'"
    )->fetch();
    $stats['fiado_total'] = (float)$row2['total'];
}

if (permiso_tiene('inventario', 'solo_ver')) {
    $row = db()->query(
        'SELECT COUNT(*) AS n FROM insumos WHERE stock_actual <= stock_seguridad AND activo = 1'
    )->fetch();
    $stats['insumos_bajos'] = (int)$row['n'];
}

if (permiso_tiene('productos', 'solo_ver')) {
    // stock_disponible existe solo después de migración 015
    try {
        $row = db()->query(
            'SELECT COALESCE(SUM(stock_disponible), 0) AS total FROM productos WHERE activo = 1'
        )->fetch();
        $stats['stock_terminado'] = (int)($row['total'] ?? 0);
    } catch (Exception $e) {}

    // produccion_lotes existe solo después de migración 015
    try {
        $row2 = db()->query(
            "SELECT COALESCE(SUM(cantidad), 0) AS total
             FROM produccion_lotes
             WHERE fecha_produccion = CURDATE() AND estado = 'activo'"
        )->fetch();
        $stats['produccion_hoy'] = (int)($row2['total'] ?? 0);
    } catch (Exception $e) {}
}

if (permiso_tiene('costos', 'solo_ver')) {
    // Total costos indirectos activos del mes corriente
    try {
        require_once __DIR__ . '/app/models/CostoIndirectoModel.php';
        $stats['costos_mes'] = round(CostoIndirectoModel::total_mensual_activo());
    } catch (Exception $e) { /* módulo puede no estar disponible */ }
}

// ── Alertas operativas (datos detallados para el panel de acción) ─────────────
// Solo se consultan si el usuario tiene acceso al módulo correspondiente.
$alertas = [];

// A) Insumos agotados o bajo stock de seguridad (máx. 5 para no saturar el panel)
if (permiso_tiene('inventario', 'solo_ver')) {
    try {
        $rows = db()->query(
            "SELECT nombre, stock_actual, stock_seguridad, unidad_medida,
                    CASE WHEN stock_actual = 0 THEN 'agotado' ELSE 'bajo' END AS nivel
             FROM insumos
             WHERE activo = 1 AND stock_actual <= stock_seguridad
             ORDER BY stock_actual ASC
             LIMIT 5"
        )->fetchAll();
        if (!empty($rows)) {
            $alertas['insumos_bajos'] = $rows;
        }
    } catch (Exception $e) {}
}

// B) Clientes con fiado pendiente (máx. 5, ordenados de mayor deuda a menor)
if (permiso_tiene('ventas', 'solo_ver')) {
    try {
        $rows = db()->query(
            "SELECT CONCAT(nombre, IF(apellido IS NOT NULL, CONCAT(' ', apellido), '')) AS nombre,
                    saldo_fiado, telefono
             FROM clientes
             WHERE activo = 1 AND saldo_fiado > 0
             ORDER BY saldo_fiado DESC
             LIMIT 5"
        )->fetchAll();
        if (!empty($rows)) {
            $alertas['fiados_pendientes'] = $rows;
        }
    } catch (Exception $e) {}
}

// C) Productos con stock terminado bajo el mínimo configurado
if (permiso_tiene('productos', 'solo_ver')) {
    try {
        $rows = db()->query(
            "SELECT nombre, nombre2, stock_disponible, stock_minimo
             FROM productos
             WHERE activo = 1 AND stock_minimo > 0 AND stock_disponible < stock_minimo
             ORDER BY stock_disponible ASC
             LIMIT 5"
        )->fetchAll();
        if (!empty($rows)) {
            $alertas['productos_bajos'] = $rows;
        }
    } catch (Exception $e) {}
}

// ---- Definición de módulos ----
$modulos_config = [
    'ventas'      => ['label' => 'Ventas / POS',   'url' => '/ventas/',      'icon' => 'M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z'],
    'inventario'  => ['label' => 'Inventario',      'url' => '/inventario/', 'icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2'],
    'proveedores' => ['label' => 'Proveedores',     'url' => '/proveedores/', 'icon' => 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-2 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4'],
    'compras'     => ['label' => 'Compras',         'url' => '/inventario/compras.php', 'icon' => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4'],
    'productos'   => ['label' => 'Productos',       'url' => '/productos/',  'icon' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
    'nomina'      => ['label' => 'Nómina',          'url' => '/nomina/',     'icon' => 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z'],
    'activos'     => ['label' => 'Activos',         'url' => '/activos/',    'icon' => 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z'],
    'costos'      => ['label' => 'Costos',          'url' => '/costos/',     'icon' => 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
    'reportes'    => ['label' => 'Reportes',        'url' => '/reportes/',   'icon' => 'M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
];

// Admin y Ayuda se agregan por rol, no por permisos_modulos
$_rol_dash = $_SESSION['usuario_rol'] ?? '';
if (in_array($_rol_dash, ['superadmin','admin'], true)) {
    $modulos_config['admin'] = ['label'=>'Administración','url'=>'/admin/','icon'=>'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z'];
}
$modulos_config['ayuda'] = ['label'=>'Ayuda','url'=>'/ayuda/','icon'=>'M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z'];

$modulos_accesibles = permiso_modulos_accesibles();

// Fase actual implementada — todas las fases están completas
$fase_actual = 7;

// Etiquetas legibles para los niveles de permiso
$nivel_labels = [
    'solo_ver'          => 'Solo ver',
    'solo_propios'      => 'Solo propios',
    'editar_existentes' => 'Editar',
    'admin_total'       => 'Admin',
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — <?= APP_NAME ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --brand:   #e94f37;
            --dark:    #111827;
            --gray-1:  #1f2937;
            --gray-2:  #374151;
            --gray-5:  #6b7280;
            --gray-8:  #d1d5db;
            --gray-9:  #f3f4f6;
            --white:   #ffffff;
            --green:   #10b981;
            --yellow:  #f59e0b;
            --radius:  14px;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--gray-9);
            color: var(--dark);
            min-height: 100vh;
        }

        /* Header provisto por nav.php — sin header propio aquí */

        /* ---- Main ---- */
        .main { padding: 20px 16px 40px; max-width: 960px; margin: 0 auto; }

        /* ---- Sección título ---- */
        .section-title {
            font-size: 13px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .6px;
            color: var(--gray-5);
            margin: 24px 0 12px;
        }

        /* ---- Stats ---- */
        .stats-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }
        @media (min-width: 600px) { .stats-grid { grid-template-columns: repeat(4, 1fr); } }

        .stat-card {
            background: var(--white);
            border-radius: var(--radius);
            padding: 16px;
            box-shadow: 0 1px 4px rgba(0,0,0,.06);
        }
        .stat-label { font-size: 11px; color: var(--gray-5); text-transform: uppercase; letter-spacing: .5px; }
        .stat-value { font-size: 22px; font-weight: 800; margin-top: 4px; color: var(--dark); }
        .stat-value.alert-red { color: var(--brand); }
        .stat-value.alert-yellow { color: var(--yellow); }
        .stat-sub { font-size: 11px; color: var(--gray-5); margin-top: 2px; }

        /* ---- Módulos ---- */
        .modules-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
        }
        /* Último item solo → ocupa todo el ancho para no quedar huérfano */
        .modules-grid > a:last-child:nth-child(odd) {
            grid-column: 1 / -1;
            max-width: calc(50% - 5px); /* mantiene tamaño visual similar */
        }
        @media (min-width: 600px) {
            .modules-grid { grid-template-columns: repeat(3, 1fr); }
            .modules-grid > a:last-child:nth-child(odd) { grid-column: unset; max-width: unset; }
            .modules-grid > a:last-child:nth-child(3n+1) { grid-column: 1 / -1; max-width: calc(33.33% - 7px); }
        }
        @media (min-width: 800px) {
            .modules-grid { grid-template-columns: repeat(4, 1fr); }
            .modules-grid > a:last-child:nth-child(3n+1) { grid-column: unset; max-width: unset; }
            .modules-grid > a:last-child:nth-child(4n+1) { grid-column: unset; max-width: unset; }
        }

        .module-card {
            background: var(--white);
            border-radius: var(--radius);
            padding: 18px 14px 16px;
            box-shadow: 0 1px 4px rgba(0,0,0,.06);
            text-decoration: none;
            color: var(--dark);
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
            transition: box-shadow .18s, transform .12s;
            position: relative;
            overflow: hidden;
        }
        .module-card:hover {
            box-shadow: 0 6px 20px rgba(0,0,0,.1);
            transform: translateY(-2px);
        }
        .module-card.disabled {
            opacity: .55;
            pointer-events: none;
            cursor: default;
        }
        .module-icon {
            width: 40px;
            height: 40px;
            background: #fef2f0;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .module-icon svg {
            width: 22px;
            height: 22px;
            stroke: var(--brand);
            fill: none;
            stroke-width: 1.8;
            stroke-linecap: round;
            stroke-linejoin: round;
        }
        .module-label {
            font-size: 14px;
            font-weight: 700;
            line-height: 1.2;
        }
        .module-badge {
            font-size: 10px;
            padding: 2px 8px;
            border-radius: 20px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .4px;
        }
        .badge-active  { background: #d1fae5; color: #065f46; }
        .badge-soon    { background: #fef3c7; color: #92400e; }
        .badge-level   { background: #ede9fe; color: #5b21b6; }

        /* ---- Saludo ---- */
        .greeting { font-size: 20px; font-weight: 700; }
        .greeting span { color: var(--brand); }
        .greeting-sub { font-size: 13px; color: var(--gray-5); margin-top: 4px; }

        /* ---- Panel de alertas ---- */
        .alertas-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 10px;
        }
        @media (min-width: 600px) { .alertas-grid { grid-template-columns: 1fr 1fr; } }
        @media (min-width: 900px) { .alertas-grid { grid-template-columns: repeat(3, 1fr); } }

        .alerta-card {
            background: var(--white);
            border-radius: var(--radius);
            box-shadow: 0 1px 4px rgba(0,0,0,.06);
            overflow: hidden;
        }
        .alerta-hdr {
            display: flex; align-items: center; justify-content: space-between;
            padding: 10px 14px;
            font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .4px;
        }
        .alerta-hdr.rojo  { background: #fef2f2; color: #991b1b; }
        .alerta-hdr.naranja { background: #fff7ed; color: #9a3412; }
        .alerta-hdr.amarillo { background: #fffbeb; color: #92400e; }
        .alerta-hdr a { font-size: 11px; font-weight: 700; text-decoration: none;
                        background: var(--brand); color: #fff; padding: 2px 8px;
                        border-radius: 10px; }
        .alerta-item {
            display: flex; justify-content: space-between; align-items: center;
            padding: 7px 14px; border-bottom: 1px solid var(--gray-9); font-size: 13px;
        }
        .alerta-item:last-child { border-bottom: none; }
        .alerta-nom { font-weight: 600; }
        .alerta-val { font-size: 12px; color: var(--brand); font-weight: 700; white-space: nowrap; }
        .alerta-sub { font-size: 11px; color: var(--gray-5); margin-top: 1px; }

        /* ---- Rol badge ---- */
        .rol-badge {
            display: inline-block;
            font-size: 11px;
            padding: 2px 10px;
            border-radius: 20px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .4px;
            background: #fef2f0;
            color: var(--brand);
            margin-left: 8px;
            vertical-align: middle;
        }
    </style>
</head>
<body>
<?php $nav_activo = ''; include __DIR__ . '/app/views/nav.php'; ?>

    <!-- ---- Contenido principal ---- -->
    <main class="main">

        <!-- Saludo -->
        <div style="margin-bottom: 4px;">
            <p class="greeting">
                Bienvenido, <span><?= htmlspecialchars(explode(' ', $usuario_activo['nombre'])[0]) ?></span>
                <span class="rol-badge"><?= htmlspecialchars($usuario_activo['rol']) ?></span>
            </p>
            <p class="greeting-sub"><?= date('l d \d\e F Y') ?></p>
        </div>

        <!-- Estadísticas del día -->
        <?php if (permiso_tiene('ventas', 'solo_ver') || permiso_tiene('inventario', 'solo_ver') || permiso_tiene('productos', 'solo_ver')): ?>
        <p class="section-title">Resumen del Día</p>
        <div class="stats-grid">

            <?php if (permiso_tiene('ventas', 'solo_ver')): ?>
            <div class="stat-card">
                <p class="stat-label">Ventas hoy</p>
                <p class="stat-value"><?= $stats['ventas_hoy'] ?></p>
                <p class="stat-sub">$<?= number_format($stats['ventas_total'], 0, ',', '.') ?></p>
            </div>
            <?php endif; ?>

            <?php if (permiso_tiene('productos', 'solo_ver') && $stats['produccion_hoy'] > 0): ?>
            <div class="stat-card">
                <p class="stat-label">Producidos hoy</p>
                <p class="stat-value" style="color:#059669"><?= $stats['produccion_hoy'] ?></p>
                <p class="stat-sub">sándwiches terminados</p>
            </div>
            <?php endif; ?>

            <?php if (permiso_tiene('productos', 'solo_ver')): ?>
            <div class="stat-card">
                <p class="stat-label">Stock disponible</p>
                <p class="stat-value <?= $stats['stock_terminado'] === 0 ? 'alert-red' : '' ?>">
                    <?= $stats['stock_terminado'] ?>
                </p>
                <p class="stat-sub">productos terminados</p>
            </div>
            <?php endif; ?>

            <?php if (permiso_tiene('inventario', 'solo_ver')): ?>
            <div class="stat-card">
                <p class="stat-label">Insumos bajos</p>
                <p class="stat-value <?= $stats['insumos_bajos'] > 0 ? 'alert-red' : '' ?>">
                    <?= $stats['insumos_bajos'] ?>
                </p>
                <p class="stat-sub">bajo stock de seguridad</p>
            </div>
            <?php endif; ?>

            <?php if (permiso_tiene('ventas', 'solo_ver')): ?>
            <div class="stat-card">
                <p class="stat-label">Fiado sin cobrar</p>
                <p class="stat-value <?= $stats['fiado_total'] > 0 ? 'alert-yellow' : '' ?>">
                    $<?= number_format($stats['fiado_total'], 0, ',', '.') ?>
                </p>
                <p class="stat-sub">pendiente de cobro</p>
            </div>
            <?php endif; ?>

            <?php if (permiso_tiene('costos', 'solo_ver') && $stats['costos_mes'] > 0): ?>
            <div class="stat-card">
                <p class="stat-label">Costos este mes</p>
                <p class="stat-value" style="color:#6d28d9">
                    $<?= number_format($stats['costos_mes'], 0, ',', '.') ?>
                </p>
                <p class="stat-sub">costos indirectos activos</p>
            </div>
            <?php endif; ?>

        </div>
        <?php endif; ?>

        <!-- ── Panel de alertas operativas ──────────────────────────────────── -->
        <?php if (!empty($alertas)): ?>
        <p class="section-title">⚡ Alertas — Atención requerida</p>
        <div class="alertas-grid">

            <?php if (!empty($alertas['insumos_bajos'])): ?>
            <div class="alerta-card">
                <div class="alerta-hdr rojo">
                    <span>🧂 Insumos bajos / agotados</span>
                    <a href="<?= APP_BASE ?>/inventario/">Ver todos</a>
                </div>
                <?php foreach ($alertas['insumos_bajos'] as $ins): ?>
                <div class="alerta-item">
                    <div>
                        <div class="alerta-nom"><?= htmlspecialchars($ins['nombre']) ?></div>
                        <div class="alerta-sub">
                            Mín: <?= number_format($ins['stock_seguridad'],2,',','.') ?>
                            <?= htmlspecialchars($ins['unidad_medida']) ?>
                        </div>
                    </div>
                    <div class="alerta-val">
                        <?= number_format($ins['stock_actual'],2,',','.') ?>
                        <?= htmlspecialchars($ins['unidad_medida']) ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($alertas['fiados_pendientes'])): ?>
            <div class="alerta-card">
                <div class="alerta-hdr amarillo">
                    <span>💳 Fiados pendientes</span>
                    <a href="<?= APP_BASE ?>/ventas/fiado.php">Ver todos</a>
                </div>
                <?php foreach ($alertas['fiados_pendientes'] as $cli): ?>
                <div class="alerta-item">
                    <div>
                        <div class="alerta-nom"><?= htmlspecialchars($cli['nombre']) ?></div>
                        <?php if (!empty($cli['telefono'])): ?>
                        <div class="alerta-sub">📞 <?= htmlspecialchars($cli['telefono']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="alerta-val">$<?= number_format($cli['saldo_fiado'],0,',','.') ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($alertas['productos_bajos'])): ?>
            <div class="alerta-card">
                <div class="alerta-hdr naranja">
                    <span>🥪 Stock de producto bajo</span>
                    <a href="<?= APP_BASE ?>/productos/produccion.php">Producir</a>
                </div>
                <?php foreach ($alertas['productos_bajos'] as $prod): ?>
                <div class="alerta-item">
                    <div>
                        <div class="alerta-nom"><?= htmlspecialchars($prod['nombre']) ?></div>
                        <?php if (!empty($prod['nombre2'])): ?>
                        <div class="alerta-sub"><?= htmlspecialchars($prod['nombre2']) ?></div>
                        <?php endif; ?>
                        <div class="alerta-sub">Mín: <?= (int)$prod['stock_minimo'] ?> u</div>
                    </div>
                    <div class="alerta-val"><?= (int)$prod['stock_disponible'] ?> u</div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

        </div>
        <?php endif; ?>

        <!-- Módulos accesibles -->
        <p class="section-title">Módulos</p>
        <div class="modules-grid">
            <?php foreach ($modulos_config as $clave => $modCfg):
                // Admin y Ayuda siempre tienen acceso (se añadieron condicionalmente arriba)
                $es_especial  = in_array($clave, ['admin','ayuda'], true);
                $tiene_acceso = $es_especial || isset($modulos_accesibles[$clave]);
                $nivel        = $modulos_accesibles[$clave] ?? null;
                if (!$tiene_acceso) continue; // no mostrar módulos sin acceso
            ?>
            <a href="<?= APP_BASE . $modCfg['url'] ?>" class="module-card">
                <div class="module-icon">
                    <svg viewBox="0 0 24 24"><path d="<?= $modCfg['icon'] ?>"/></svg>
                </div>
                <span class="module-label"><?= htmlspecialchars($modCfg['label']) ?></span>
                <?php if (!$es_especial && $nivel): ?>
                <span class="module-badge badge-level"><?= $nivel_labels[$nivel] ?? $nivel ?></span>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        </div>

        <?php if (empty($modulos_accesibles)): ?>
        <div style="text-align:center; padding: 48px 16px; color: var(--gray-5);">
            <p style="font-size:32px; margin-bottom:12px;">🔐</p>
            <p style="font-weight:600;">Sin módulos asignados</p>
            <p style="font-size:13px; margin-top:6px;">Contacta al administrador para configurar tus permisos.</p>
        </div>
        <?php endif; ?>

    </main>

</body>
</html>
