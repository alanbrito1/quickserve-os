<?php
/**
 * app/views/nav.php
 * Navegación global compartida + sub-tabs opcionales por módulo.
 *
 * Variables requeridas:
 *   $nav_activo  — módulo activo (ventas, inventario, nomina, etc.)
 *   $nav_sub     — (opcional) sub-sección activa dentro del módulo
 *
 * Sub-tabs disponibles: nómina (nomina, empleados, horas, parametros)
 */

$_nav = [
    'ventas'     => ['label' => 'Ventas',     'url' => '/ventas/'],
    'inventario' => ['label' => 'Inventario', 'url' => '/inventario/'],
    'compras'    => ['label' => 'Compras',    'url' => '/inventario/compras.php'],
    'productos'  => ['label' => 'Productos',  'url' => '/productos/'],
    'nomina'     => ['label' => 'Nómina',     'url' => '/nomina/'],
    'activos'    => ['label' => 'Activos',    'url' => '/activos/'],
    'reportes'   => ['label' => 'Reportes',   'url' => '/reportes/'],
];

$_nav_vis = [];
foreach ($_nav as $_mod => $_cfg) {
    $_check = ($_mod === 'compras') ? 'compras' : $_mod;
    if (isset($usuario_activo) && permiso_tiene($_check, 'solo_ver')) {
        $_nav_vis[$_mod] = $_cfg;
    }
}

$_nombre = isset($usuario_activo['nombre'])
    ? explode(' ', trim($usuario_activo['nombre']))[0]
    : 'Usuario';

$_nav_sub_actual = $nav_sub ?? '';
$_nav_activo     = $nav_activo ?? '';
?>

<!-- ══ BARRA PRINCIPAL ══════════════════════════════════════════════════ -->
<header class="nav-hdr">
    <a href="<?= APP_BASE ?>/dashboard.php" class="nav-brand">
        Clan<span>Destino</span>
    </a>

    <nav class="nav-links">
        <?php foreach ($_nav_vis as $_mod => $_cfg): ?>
        <a href="<?= APP_BASE . $_cfg['url'] ?>"
           class="nav-link<?= $_nav_activo === $_mod ? ' nav-link--act' : '' ?>">
            <?= htmlspecialchars($_cfg['label']) ?>
        </a>
        <?php endforeach; ?>
    </nav>

    <div class="nav-user">
        <span class="nav-name"><?= htmlspecialchars($_nombre) ?></span>
        <a href="<?= APP_BASE ?>/logout.php" class="nav-out">Salir</a>
    </div>
</header>

<!-- ══ SUB-TABS DE NÓMINA (visible solo cuando nav_activo = nomina) ════ -->
<?php if ($_nav_activo === 'nomina'): ?>
<div class="subtab-bar">
    <div class="subtab-inner">
        <a href="<?= APP_BASE ?>/nomina/"
           class="subtab<?= $_nav_sub_actual === 'nomina'     ? ' subtab--act' : '' ?>">
            Liquidaciones
        </a>
        <a href="<?= APP_BASE ?>/nomina/empleados.php"
           class="subtab<?= $_nav_sub_actual === 'empleados'  ? ' subtab--act' : '' ?>">
            Empleados
        </a>
        <a href="<?= APP_BASE ?>/nomina/horas.php"
           class="subtab<?= $_nav_sub_actual === 'horas'      ? ' subtab--act' : '' ?>">
            Horas
        </a>
        <?php if (permiso_tiene('nomina', 'admin_total')): ?>
        <a href="<?= APP_BASE ?>/nomina/parametros.php"
           class="subtab<?= $_nav_sub_actual === 'parametros' ? ' subtab--act' : '' ?>">
            Parámetros
        </a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<style>
/* ── Barra principal ────────────────────────────────────────────────── */
.nav-hdr {
    background: #111827;
    color: #fff;
    height: 52px;
    padding: 0 14px;
    display: flex;
    align-items: center;
    gap: 6px;
    position: sticky;
    top: 0;
    z-index: 100;
    box-shadow: 0 2px 8px rgba(0,0,0,.35);
}
.nav-brand {
    font-size: 17px;
    font-weight: 800;
    color: #fff;
    text-decoration: none;
    letter-spacing: -.3px;
    white-space: nowrap;
    flex-shrink: 0;
    margin-right: 4px;
}
.nav-brand span { color: #e94f37; }

.nav-links {
    display: flex;
    gap: 2px;
    flex: 1;
    overflow-x: auto;
    scrollbar-width: none;
    -ms-overflow-style: none;
}
.nav-links::-webkit-scrollbar { display: none; }

.nav-link {
    color: #9ca3af;
    text-decoration: none;
    font-size: 12px;
    font-weight: 600;
    padding: 6px 10px;
    border-radius: 8px;
    white-space: nowrap;
    transition: background .15s, color .15s;
    flex-shrink: 0;
}
.nav-link:hover  { background: #374151; color: #fff; }
.nav-link--act   { background: #e94f37; color: #fff; }

.nav-user {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-shrink: 0;
}
.nav-name {
    font-size: 12px;
    color: #9ca3af;
    display: none;
}
@media (min-width: 480px) { .nav-name { display: block; } }

.nav-out {
    background: #374151;
    color: #d1d5db;
    text-decoration: none;
    padding: 5px 12px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 600;
    white-space: nowrap;
    transition: background .15s;
}
.nav-out:hover { background: #4b5563; }

/* ── Sub-tabs de módulo (Nómina) ────────────────────────────────────── */
.subtab-bar {
    background: #fff;
    border-bottom: 2px solid #e5e7eb;
    position: sticky;
    top: 52px;       /* justo debajo del nav-hdr */
    z-index: 99;
}
.subtab-inner {
    max-width: 960px;
    margin: 0 auto;
    padding: 0 14px;
    display: flex;
    gap: 0;
    overflow-x: auto;
    scrollbar-width: none;
}
.subtab-inner::-webkit-scrollbar { display: none; }

.subtab {
    display: inline-block;
    padding: 11px 18px;
    font-size: 13px;
    font-weight: 600;
    color: #6b7280;
    text-decoration: none;
    white-space: nowrap;
    border-bottom: 3px solid transparent;
    margin-bottom: -2px;   /* sobreescribe el border-bottom del bar */
    transition: color .15s, border-color .15s;
}
.subtab:hover {
    color: #111827;
    border-bottom-color: #d1d5db;
}
.subtab--act {
    color: #e94f37;
    border-bottom-color: #e94f37;
    font-weight: 700;
}
</style>
