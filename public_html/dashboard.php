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
    'ventas_hoy'     => 0,
    'ventas_total'   => 0,
    'insumos_bajos'  => 0,
    'fiado_total'    => 0,
];

if (permiso_tiene('ventas', 'solo_ver')) {
    $row = db()->query(
        "SELECT COUNT(*) AS n, IFNULL(SUM(total),0) AS monto
         FROM ventas
         WHERE DATE(fecha_venta) = CURDATE() AND estado = 'completada'"
    )->fetch();
    $stats['ventas_hoy']   = (int)$row['n'];
    $stats['ventas_total'] = (float)$row['monto'];
}

if (permiso_tiene('inventario', 'solo_ver')) {
    $row = db()->query(
        'SELECT COUNT(*) AS n
         FROM insumos
         WHERE stock_actual <= stock_seguridad AND activo = 1'
    )->fetch();
    $stats['insumos_bajos'] = (int)$row['n'];
}

if (permiso_tiene('ventas', 'solo_ver')) {
    $row = db()->query(
        'SELECT IFNULL(SUM(saldo_fiado),0) AS total FROM clientes WHERE activo = 1'
    )->fetch();
    $stats['fiado_total'] = (float)$row['total'];
}

// ---- Definición de módulos ----
$modulos_config = [
    'ventas'     => ['label' => 'Ventas / POS',  'url' => '/ventas/',     'fase' => 3, 'icon' => 'M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z'],
    'compras'    => ['label' => 'Compras',        'url' => '/compras/',    'fase' => 4, 'icon' => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4'],
    'inventario' => ['label' => 'Inventario',     'url' => '/inventario/', 'fase' => 4, 'icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2'],
    'productos'  => ['label' => 'Productos',      'url' => '/productos/',  'fase' => 4, 'icon' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
    'nomina'     => ['label' => 'Nómina',         'url' => '/nomina/',     'fase' => 5, 'icon' => 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z'],
    'activos'    => ['label' => 'Activos',        'url' => '/activos/',    'fase' => 6, 'icon' => 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z'],
    'reportes'   => ['label' => 'Reportes',       'url' => '/reportes/',   'fase' => 7, 'icon' => 'M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
];

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

        /* ---- Header ---- */
        .header {
            background: var(--dark);
            color: var(--white);
            padding: 0 16px;
            height: 56px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 2px 8px rgba(0,0,0,.3);
        }
        .header-brand {
            font-size: 18px;
            font-weight: 800;
            letter-spacing: -.3px;
        }
        .header-brand span { color: var(--brand); }
        .header-user {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .header-user .nombre {
            font-size: 13px;
            color: var(--gray-8);
            display: none; /* oculto en móvil muy pequeño */
        }
        .btn-logout {
            background: var(--gray-2);
            color: var(--gray-8);
            border: none;
            padding: 7px 14px;
            border-radius: 8px;
            font-size: 13px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: background .15s;
        }
        .btn-logout:hover { background: #4b5563; }

        @media (min-width: 400px) {
            .header-user .nombre { display: block; }
        }

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
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }
        @media (min-width: 600px) { .modules-grid { grid-template-columns: repeat(3, 1fr); } }
        @media (min-width: 800px) { .modules-grid { grid-template-columns: repeat(4, 1fr); } }

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

    <!-- ---- Header ---- -->
    <header class="header">
        <div class="header-brand">Clan<span>Destino</span></div>
        <div class="header-user">
            <span class="nombre"><?= htmlspecialchars($usuario_activo['nombre']) ?></span>
            <a href="<?= APP_BASE ?>/logout.php" class="btn-logout">Salir</a>
        </div>
    </header>

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
        <?php if (permiso_tiene('ventas', 'solo_ver') || permiso_tiene('inventario', 'solo_ver')): ?>
        <p class="section-title">Resumen del Día</p>
        <div class="stats-grid">

            <?php if (permiso_tiene('ventas', 'solo_ver')): ?>
            <div class="stat-card">
                <p class="stat-label">Ventas hoy</p>
                <p class="stat-value"><?= $stats['ventas_hoy'] ?></p>
                <p class="stat-sub">$<?= number_format($stats['ventas_total'], 0, ',', '.') ?></p>
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
                <p class="stat-label">Fiado total</p>
                <p class="stat-value <?= $stats['fiado_total'] > 0 ? 'alert-yellow' : '' ?>">
                    $<?= number_format($stats['fiado_total'], 0, ',', '.') ?>
                </p>
                <p class="stat-sub">pendiente de cobro</p>
            </div>
            <?php endif; ?>

        </div>
        <?php endif; ?>

        <!-- Módulos accesibles -->
        <p class="section-title">Módulos</p>
        <div class="modules-grid">
            <?php foreach ($modulos_config as $clave => $cfg):
                $tiene_acceso = isset($modulos_accesibles[$clave]);
                $implementado = $cfg['fase'] <= $fase_actual;
                $nivel        = $modulos_accesibles[$clave] ?? null;
                $url          = ($tiene_acceso && $implementado) ? $cfg['url'] : '#';
            ?>
            <<?= ($tiene_acceso && $implementado) ? 'a href="' . APP_BASE . $cfg['url'] . '"' : 'div' ?>
                class="module-card<?= (!$tiene_acceso || !$implementado) ? ' disabled' : '' ?>"
                <?= ($tiene_acceso && !$implementado) ? 'title="En desarrollo — Fase ' . $cfg['fase'] . '"' : '' ?>
            >
                <div class="module-icon">
                    <svg viewBox="0 0 24 24"><path d="<?= $cfg['icon'] ?>"/></svg>
                </div>
                <span class="module-label"><?= $cfg['label'] ?></span>
                <?php if (!$implementado): ?>
                    <span class="module-badge badge-soon">Fase <?= $cfg['fase'] ?></span>
                <?php elseif ($tiene_acceso && $nivel): ?>
                    <span class="module-badge badge-level"><?= $nivel_labels[$nivel] ?? $nivel ?></span>
                <?php endif; ?>
            </<?= ($tiene_acceso && $implementado) ? 'a' : 'div' ?>>
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
