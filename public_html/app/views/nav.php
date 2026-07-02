<?php
/**
 * app/views/nav.php
 * Navegación global compartida + sub-tabs opcionales por módulo.
 * Inyecta el tema visual configurado desde admin/apariencia.
 *
 * Variables requeridas:
 *   $nav_activo  — módulo activo (ventas, inventario, nomina, admin, etc.)
 *   $nav_sub     — (opcional) sub-sección activa dentro del módulo
 */

// ── Iconos SVG unificados ─────────────────────────────────────────────────────
require_once __DIR__ . '/icons.php';

// ── Cargar tema y datos del negocio desde configuracion_app ──────────────────
$_theme = [
    'brand'        => '#e94f37',
    'dark'         => '#111827',
    'font'         => 'system-ui, -apple-system, sans-serif',
    'font_heading' => 'system-ui, -apple-system, sans-serif',
    'radius'       => '12',
    'logo'         => '',
    'negocio'      => 'ClanDestino',
    // Escala tipográfica (px)
    'fs_title'     => '22',
    'fs_subtitle'  => '15',
    'fs_body'      => '13',
    'fs_small'     => '11',
    // Colores de texto
    'color_text'   => '#111827',
    'color_text_sec'=> '#6b7280',
    // Formato numérico (migraciones 040 y 041)
    'num_decimales'   => '2',
    'num_sep_miles'   => '.',
    'num_sep_decimal' => ',',
    'num_sep_millones'=> '.',
];
try {
    $_trows = db()->query(
        "SELECT clave, valor FROM configuracion_app
         WHERE clave IN ('theme_brand','theme_dark','theme_font','theme_radius',
                         'logo_url','logo_url_login','nombre_negocio',
                         'font_heading','font_size_title','font_size_subtitle',
                         'font_size_body','font_size_small','color_text','color_text_sec',
                         'num_decimales','num_sep_miles','num_sep_decimal','num_sep_millones')"
    )->fetchAll(PDO::FETCH_KEY_PAIR);

    // Colores: validar hex #RRGGBB antes de inyectar en CSS (previene CSS injection)
    $_hexOk = fn($v) => preg_match('/^#[0-9a-fA-F]{6}$/', $v ?? '');
    if ($_hexOk($_trows['theme_brand'] ?? ''))    $_theme['brand']         = $_trows['theme_brand'];
    if ($_hexOk($_trows['theme_dark']  ?? ''))    $_theme['dark']          = $_trows['theme_dark'];
    if ($_hexOk($_trows['color_text']  ?? ''))    $_theme['color_text']    = $_trows['color_text'];
    if ($_hexOk($_trows['color_text_sec'] ?? '')) $_theme['color_text_sec']= $_trows['color_text_sec'];

    // Fuentes: solo si no están vacías (la whitelist se valida al guardar en guardar_apariencia.php)
    if (!empty($_trows['theme_font']))       $_theme['font']         = $_trows['theme_font'];
    if (!empty($_trows['font_heading']))     $_theme['font_heading'] = $_trows['font_heading'];

    // Tamaños de fuente: clamp al rango válido para evitar valores extremos
    if (!empty($_trows['theme_radius']))        $_theme['radius']     = max(0, min(24, (int)$_trows['theme_radius']));
    if (!empty($_trows['font_size_title']))     $_theme['fs_title']   = max(14, min(40, (int)$_trows['font_size_title']));
    if (!empty($_trows['font_size_subtitle']))  $_theme['fs_subtitle']= max(10, min(24, (int)$_trows['font_size_subtitle']));
    if (!empty($_trows['font_size_body']))      $_theme['fs_body']    = max(10, min(20, (int)$_trows['font_size_body']));
    if (!empty($_trows['font_size_small']))     $_theme['fs_small']   = max(8,  min(16, (int)$_trows['font_size_small']));

    // Nombre del negocio
    if (!empty($_trows['nombre_negocio'])) $_theme['negocio'] = $_trows['nombre_negocio'];

    // Formato numérico (migraciones 040 y 041): validar antes de inyectar a JS
    if (isset($_trows['num_decimales']) && $_trows['num_decimales'] !== '')
        $_theme['num_decimales'] = (string)max(0, min(4, (int)$_trows['num_decimales']));
    $_sepMilesOk = ['.', ',', ' ', "'"];
    $_sepDecOk   = ['.', ','];
    if (in_array($_trows['num_sep_miles'] ?? '', $_sepMilesOk, true))
        $_theme['num_sep_miles'] = $_trows['num_sep_miles'];
    if (in_array($_trows['num_sep_decimal'] ?? '', $_sepDecOk, true))
        $_theme['num_sep_decimal'] = $_trows['num_sep_decimal'];
    if ($_theme['num_sep_miles'] === $_theme['num_sep_decimal']) {
        $_theme['num_sep_miles']   = '.';
        $_theme['num_sep_decimal'] = ',';
    }
    if (in_array($_trows['num_sep_millones'] ?? '', $_sepMilesOk, true))
        $_theme['num_sep_millones'] = $_trows['num_sep_millones'];
    if ($_theme['num_sep_millones'] === $_theme['num_sep_decimal'])
        $_theme['num_sep_millones'] = $_theme['num_sep_miles'];

    // Logo: validar que la ruta sea relativa y empiece por uploads/ (previene javascript: y path traversal)
    // isset() en lugar de !empty() para distinguir "sin configurar" de "vacío deliberado (quitar logo)"
    $_validarLogo = function($url) {
        if (!isset($url) || $url === '') return '';
        // Solo rutas relativas que empiecen por uploads/ son seguras
        if (!str_starts_with($url, 'uploads/')) return '';
        return $url;
    };
    if (isset($_trows['logo_url']) && $_trows['logo_url'] !== '') {
        $_logo_val = $_validarLogo($_trows['logo_url']);
        if ($_logo_val !== '') $_theme['logo'] = $_logo_val;
    } elseif (isset($_trows['logo_url_login']) && $_trows['logo_url_login'] !== '') {
        // Fallback: si no hay logo de nav, usar el de login
        $_logo_val = $_validarLogo($_trows['logo_url_login']);
        if ($_logo_val !== '') $_theme['logo'] = $_logo_val;
    }
} catch (Exception $_e) { /* usar valores por defecto si la tabla no existe aún */ }

// Nombre separado (primera palabra) para el brand type
$_brand_parts = preg_split('/\s+/', trim($_theme['negocio']), 2);
$_brand_p1    = $_brand_parts[0] ?? 'Clan';
$_brand_p2    = $_brand_parts[1] ?? '';

// ── Módulos de navegación ─────────────────────────────────────────────────────
$_nav = [
    'ventas'     => ['label' => 'Ventas',      'url' => '/ventas/',                'permiso' => 'ventas'],
    'clientes'   => ['label' => 'Clientes',    'url' => '/clientes/',              'permiso' => 'ventas'],   // comparte permiso ventas
    'inventario' => ['label' => 'Inventario',  'url' => '/inventario/',            'permiso' => 'inventario'],
    'proveedores'=> ['label' => 'Proveedores', 'url' => '/proveedores/',           'permiso' => 'proveedores'],
    'compras'    => ['label' => 'Compras',     'url' => '/inventario/compras.php', 'permiso' => 'compras'],
    'productos'  => ['label' => 'Productos',   'url' => '/productos/',             'permiso' => 'productos'],
    'nomina'     => ['label' => 'Nómina',      'url' => '/nomina/',               'permiso' => 'nomina'],
    'activos'    => ['label' => 'Activos',     'url' => '/activos/',               'permiso' => 'activos'],
    'costos'     => ['label' => 'Costos',      'url' => '/costos/',                'permiso' => 'costos'],
    'reportes'   => ['label' => 'Reportes',    'url' => '/reportes/',              'permiso' => 'reportes'],
];

// Construir tabs visibles según permisos del usuario activo
// Cada módulo declara su propio permiso requerido en el array anterior
$_nav_vis = [];
foreach ($_nav as $_mod => $_cfg) {
    if (isset($usuario_activo) && permiso_tiene($_cfg['permiso'], 'solo_ver')) {
        $_nav_vis[$_mod] = $_cfg;
    }
}

// Tab Contabilidad y Admin: solo para superadmin y admin (gate por rol)
$_rol_actual = $_SESSION['usuario_rol'] ?? '';
if (in_array($_rol_actual, ['superadmin', 'admin'], true)) {
    $_nav_vis['contabilidad'] = ['label' => 'Contabilidad', 'url' => '/contabilidad/'];
    $_nav_vis['admin'] = ['label' => 'Admin', 'url' => '/admin/'];
}

// Tab Ayuda: visible para todos los usuarios autenticados
if (isset($usuario_activo)) {
    $_nav_vis['ayuda'] = ['label' => 'Ayuda', 'url' => '/ayuda/'];
}

$_nombre         = isset($usuario_activo['nombre'])
    ? explode(' ', trim($usuario_activo['nombre']))[0]
    : 'Usuario';
$_nav_sub_actual = $nav_sub ?? '';
$_nav_activo     = $nav_activo ?? '';
?>

<!-- ══ INYECCIÓN DE TEMA GLOBAL ════════════════════════════════════════════ -->
<style>
/* Variables de tema y tipografía — controladas desde Admin → Apariencia */
:root {
    --brand:   <?= htmlspecialchars($_theme['brand'],    ENT_QUOTES) ?>;
    --dark:    <?= htmlspecialchars($_theme['dark'],     ENT_QUOTES) ?>;
    /* Radio de bordes — controlado desde Admin → Apariencia → theme_radius */
    --r: <?= (int)$_theme['radius'] ?>px;
    /* Escala tipográfica */
    --fs-title:    <?= (int)$_theme['fs_title']    ?>px;
    --fs-subtitle: <?= (int)$_theme['fs_subtitle'] ?>px;
    --fs-body:     <?= (int)$_theme['fs_body']     ?>px;
    --fs-small:    <?= (int)$_theme['fs_small']    ?>px;
    /* Colores de texto */
    --color-text:     <?= htmlspecialchars($_theme['color_text'],    ENT_QUOTES) ?>;
    --color-text-sec: <?= htmlspecialchars($_theme['color_text_sec'],ENT_QUOTES) ?>;
}
/* Aplicación global de tipografía */
body {
    font-family: <?= htmlspecialchars($_theme['font'], ENT_QUOTES) ?>;
    font-size:   var(--fs-body);
    color:       var(--color-text);
}
/* Títulos de página */
h1, .page-title, .greeting {
    font-family: <?= htmlspecialchars($_theme['font_heading'], ENT_QUOTES) ?>;
    font-size:   var(--fs-title);
    color:       var(--color-text);
}
/* Subtítulos: títulos de tarjeta, secciones */
h2, h3, .card-title, .section-title, .sub-title,
.modal-hdr span, .page-sub + *, .section-hdr .section-title {
    font-size: var(--fs-subtitle);
    color:     var(--color-text);
}
/* Texto estándar: celdas de tabla, párrafos */
p, td, li, .tbl td, table td, .stat-sub, .kpi-sub, .page-sub {
    font-size: var(--fs-body);
    color:     var(--color-text);
}
/* Texto pequeño: labels, encabezados tabla, badges, hints */
label, .fg label, .kpi-lbl, .kpi-group-lbl,
.tbl thead th, table th, .badge,
.smlmv-note, .hint, .muted, .nav-name,
.stat-label, .stat-l, small {
    font-size: var(--fs-small);
    color:     var(--color-text-sec);
}
/* Valores destacados: KPIs, estadísticas */
.kpi-val, .stat-value, .stat-n, .cobrar-total {
    font-size: var(--fs-title);
    color:     var(--color-text);
}
/* Radio de bordes global — todas las tarjetas/modales/secciones respetan --r */
.card, .modal, .kpi, .stat, .section,
.sheet, .combo-sheet, .tbl-wrap,
.alert, .alert-ok, .alert-err,
.warning-box, .tip, .warn, .ok,
.badge, .btn-primary, .btn-sec, .btn-a,
input, select, textarea,
.prod-card, .metodo-btn, .combo-opt {
    border-radius: var(--r);
}
</style>

<!-- ══ BARRA PRINCIPAL ══════════════════════════════════════════════════════ -->
<header class="nav-hdr">
    <a href="<?= APP_BASE ?>/dashboard.php" class="nav-brand">
        <?php if ($_theme['logo']): ?>
            <img src="<?= APP_BASE . '/' . htmlspecialchars($_theme['logo']) ?>"
                 alt="<?= htmlspecialchars($_theme['negocio']) ?>"
                 style="height:64px;width:auto;display:block">
        <?php elseif ($_brand_p2): ?>
            <?= htmlspecialchars($_brand_p1) ?><span><?= htmlspecialchars($_brand_p2) ?></span>
        <?php else: ?>
            <?= htmlspecialchars($_brand_p1) ?>
        <?php endif; ?>
    </a>

    <!-- Tabs horizontales — visibles solo en desktop -->
    <nav class="nav-links" id="nav-links-desktop">
        <?php foreach ($_nav_vis as $_mod => $_cfg): ?>
        <a href="<?= APP_BASE . $_cfg['url'] ?>"
           class="nav-link<?= $_nav_activo === $_mod ? ' nav-link--act' : '' ?>">
            <?= htmlspecialchars($_cfg['label']) ?>
        </a>
        <?php endforeach; ?>
    </nav>

    <div class="nav-user">
        <span class="nav-name"><?= htmlspecialchars($_nombre) ?></span>
        <!-- Botón hamburguesa — solo móvil -->
        <button class="nav-burger" id="nav-burger"
                onclick="toggleNavMobile()" aria-label="Abrir menú" aria-expanded="false">
            <span></span><span></span><span></span>
        </button>
        <a href="<?= APP_BASE ?>/logout.php" class="nav-out">Salir</a>
    </div>
</header>

<!-- ══ MENÚ MÓVIL (drawer vertical) ═════════════════════════════════════════ -->
<div class="nav-mobile" id="nav-mobile" aria-hidden="true">
    <!-- Cabecera del drawer: usuario + salir -->
    <div class="nav-mobile-hdr">
        <span class="nav-mobile-user">&#128100; <?= htmlspecialchars($_nombre) ?></span>
        <a href="<?= APP_BASE ?>/logout.php" class="nav-mobile-out">Salir</a>
    </div>
    <!-- Links de navegación -->
    <?php foreach ($_nav_vis as $_mod => $_cfg): ?>
    <a href="<?= APP_BASE . $_cfg['url'] ?>"
       class="nav-mobile-link<?= $_nav_activo === $_mod ? ' nav-mobile-link--act' : '' ?>">
        <?= htmlspecialchars($_cfg['label']) ?>
    </a>
    <?php endforeach; ?>
    <!-- Sub-secciones visibles solo si el módulo activo las tiene -->
    <?php if ($_nav_activo === 'nomina'): ?>
    <div class="nav-mobile-sub">
        <a href="<?= APP_BASE ?>/nomina/" class="nav-mobile-sub-link<?= $_nav_sub_actual==='nomina'?' act':'' ?>">Liquidaciones</a>
        <a href="<?= APP_BASE ?>/nomina/empleados.php" class="nav-mobile-sub-link<?= $_nav_sub_actual==='empleados'?' act':'' ?>">Empleados</a>
        <a href="<?= APP_BASE ?>/nomina/horas.php" class="nav-mobile-sub-link<?= $_nav_sub_actual==='horas'?' act':'' ?>">Horas</a>
        <?php if (permiso_tiene('nomina','admin_total')): ?>
        <a href="<?= APP_BASE ?>/nomina/parametros.php" class="nav-mobile-sub-link<?= $_nav_sub_actual==='parametros'?' act':'' ?>">Parámetros</a>
        <?php endif; ?>
    </div>
    <?php elseif ($_nav_activo === 'admin'): ?>
    <div class="nav-mobile-sub">
        <a href="<?= APP_BASE ?>/admin/" class="nav-mobile-sub-link<?= $_nav_sub_actual==='resumen'?' act':'' ?>">Resumen</a>
        <a href="<?= APP_BASE ?>/admin/usuarios.php" class="nav-mobile-sub-link<?= $_nav_sub_actual==='usuarios'?' act':'' ?>">Usuarios</a>
        <a href="<?= APP_BASE ?>/admin/apariencia.php" class="nav-mobile-sub-link<?= $_nav_sub_actual==='apariencia'?' act':'' ?>">Apariencia</a>
        <a href="<?= APP_BASE ?>/admin/listas.php" class="nav-mobile-sub-link<?= $_nav_sub_actual==='listas'?' act':'' ?>">Catálogos</a>
        <a href="<?= APP_BASE ?>/admin/backup.php" class="nav-mobile-sub-link<?= $_nav_sub_actual==='backup'?' act':'' ?>">Base de Datos</a>
        <?php if (($_SESSION['usuario_rol'] ?? '') === 'superadmin'): ?>
        <a href="<?= APP_BASE ?>/admin/mantenimiento.php" class="nav-mobile-sub-link<?= $_nav_sub_actual==='mantenimiento'?' act':'' ?>">Mantenimiento</a>
        <a href="<?= APP_BASE ?>/admin/auditor_costos.php" class="nav-mobile-sub-link<?= $_nav_sub_actual==='auditor'?' act':'' ?>">Auditor costos</a>
        <a href="<?= APP_BASE ?>/tests/suite.php" class="nav-mobile-sub-link<?= $_nav_sub_actual==='pruebas'?' act':'' ?>">Pruebas</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<!-- Capa oscura detrás del drawer -->
<div class="nav-mobile-overlay" id="nav-mobile-overlay" onclick="closeNavMobile()"></div>

<!-- ══ SUB-TABS DE NÓMINA ═══════════════════════════════════════════════════ -->
<?php if ($_nav_activo === 'nomina'): ?>
<div class="subtab-bar">
    <div class="subtab-inner">
        <a href="<?= APP_BASE ?>/nomina/"
           class="subtab<?= $_nav_sub_actual === 'nomina'     ? ' subtab--act' : '' ?>">Liquidaciones</a>
        <a href="<?= APP_BASE ?>/nomina/empleados.php"
           class="subtab<?= $_nav_sub_actual === 'empleados'  ? ' subtab--act' : '' ?>">Empleados</a>
        <a href="<?= APP_BASE ?>/nomina/horas.php"
           class="subtab<?= $_nav_sub_actual === 'horas'      ? ' subtab--act' : '' ?>">Horas</a>
        <?php if (permiso_tiene('nomina', 'admin_total')): ?>
        <a href="<?= APP_BASE ?>/nomina/parametros.php"
           class="subtab<?= $_nav_sub_actual === 'parametros' ? ' subtab--act' : '' ?>">Parámetros</a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ══ SUB-TABS DE CONTABILIDAD ═════════════════════════════════════════════ -->
<?php if ($_nav_activo === 'contabilidad'): ?>
<div class="subtab-bar">
    <div class="subtab-inner">
        <a href="<?= APP_BASE ?>/contabilidad/"               class="subtab<?= $_nav_sub_actual==='resumen'?' subtab--act':'' ?>">Resumen</a>
        <a href="<?= APP_BASE ?>/contabilidad/balance.php"     class="subtab<?= $_nav_sub_actual==='balance'?' subtab--act':'' ?>">Balance</a>
        <a href="<?= APP_BASE ?>/contabilidad/apertura.php"    class="subtab<?= $_nav_sub_actual==='apertura'?' subtab--act':'' ?>">Apertura</a>
        <a href="<?= APP_BASE ?>/contabilidad/libro_diario.php" class="subtab<?= $_nav_sub_actual==='diario'?' subtab--act':'' ?>">Libro diario</a>
        <a href="<?= APP_BASE ?>/contabilidad/plan_cuentas.php" class="subtab<?= $_nav_sub_actual==='plan'?' subtab--act':'' ?>">Plan de cuentas</a>
    </div>
</div>
<?php endif; ?>

<!-- ══ SUB-TABS DE ADMIN ════════════════════════════════════════════════════ -->
<?php if ($_nav_activo === 'admin'): ?>
<div class="subtab-bar">
    <div class="subtab-inner">
        <a href="<?= APP_BASE ?>/admin/"
           class="subtab<?= $_nav_sub_actual === 'resumen'    ? ' subtab--act' : '' ?>">Resumen</a>
        <a href="<?= APP_BASE ?>/admin/usuarios.php"
           class="subtab<?= $_nav_sub_actual === 'usuarios'   ? ' subtab--act' : '' ?>">Usuarios</a>
        <a href="<?= APP_BASE ?>/admin/apariencia.php"
           class="subtab<?= $_nav_sub_actual === 'apariencia' ? ' subtab--act' : '' ?>">Apariencia</a>
        <a href="<?= APP_BASE ?>/admin/listas.php"
           class="subtab<?= $_nav_sub_actual === 'listas'     ? ' subtab--act' : '' ?>">Catálogos</a>
        <a href="<?= APP_BASE ?>/admin/backup.php"
           class="subtab<?= $_nav_sub_actual === 'backup'     ? ' subtab--act' : '' ?>">Base de Datos</a>
        <?php if (($_SESSION['usuario_rol'] ?? '') === 'superadmin'): ?>
        <a href="<?= APP_BASE ?>/admin/mantenimiento.php"
           class="subtab<?= $_nav_sub_actual === 'mantenimiento' ? ' subtab--act' : '' ?>">Mantenimiento</a>
        <a href="<?= APP_BASE ?>/admin/auditor_costos.php"
           class="subtab<?= $_nav_sub_actual === 'auditor' ? ' subtab--act' : '' ?>">Auditor costos</a>
        <a href="<?= APP_BASE ?>/tests/suite.php"
           class="subtab<?= $_nav_sub_actual === 'pruebas' ? ' subtab--act' : '' ?>">Pruebas</a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<style>
/* ── Barra principal ─────────────────────────────────────────────────── */
.nav-hdr {
    background: var(--dark, #111827);
    color: #fff;
    height: 80px;
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
    display: flex;
    align-items: center;
}
.nav-brand span { color: var(--brand, #e94f37); }

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
.nav-link--act   { background: var(--brand, #e94f37); color: #fff; }

.nav-user { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
.nav-name { font-size: 12px; color: #9ca3af; display: none; }
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

/* ── Botón hamburguesa ───────────────────────────────────────────────── */
.nav-burger {
    display: none;        /* oculto en desktop */
    flex-direction: column;
    justify-content: center;
    gap: 5px;
    background: none;
    border: none;
    cursor: pointer;
    padding: 8px;
    border-radius: 8px;
    transition: background .15s;
    flex-shrink: 0;
}
.nav-burger:hover { background: #374151; }
.nav-burger span {
    display: block;
    width: 22px;
    height: 2px;
    background: #9ca3af;
    border-radius: 2px;
    transition: transform .22s ease, opacity .22s ease;
    transform-origin: center;
}
/* Animación a "X" cuando está abierto */
.nav-burger.open span:nth-child(1) { transform: translateY(7px) rotate(45deg);  background: #fff; }
.nav-burger.open span:nth-child(2) { opacity: 0; }
.nav-burger.open span:nth-child(3) { transform: translateY(-7px) rotate(-45deg); background: #fff; }

/* ── Menú móvil (drawer) ─────────────────────────────────────────────── */
.nav-mobile {
    position: fixed;
    top: 80px;           /* justo debajo del nav-hdr */
    left: 0;
    right: 0;
    background: var(--dark, #111827);
    z-index: 98;
    max-height: calc(100dvh - 80px);
    overflow-y: auto;
    box-shadow: 0 8px 32px rgba(0,0,0,.5);
    /* Animación slide-down */
    transform: translateY(-8px);
    opacity: 0;
    pointer-events: none;
    transition: transform .22s ease, opacity .22s ease;
}
.nav-mobile.open {
    transform: translateY(0);
    opacity: 1;
    pointer-events: auto;
}
.nav-mobile-hdr {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 18px;
    border-bottom: 1px solid #374151;
    margin-bottom: 4px;
}
.nav-mobile-user { font-size: 13px; color: #9ca3af; font-weight: 600; }
.nav-mobile-out {
    background: #374151;
    color: #d1d5db;
    text-decoration: none;
    padding: 5px 12px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 600;
}
.nav-mobile-link {
    display: flex;
    align-items: center;
    padding: 14px 20px;
    color: #9ca3af;
    text-decoration: none;
    font-size: 15px;
    font-weight: 600;
    border-left: 3px solid transparent;
    transition: background .12s, color .12s;
}
.nav-mobile-link:hover  { background: #1f2937; color: #fff; }
.nav-mobile-link--act   { background: #1f2937; color: #fff; border-left-color: var(--brand, #e94f37); }

/* Sub-secciones dentro del menú móvil */
.nav-mobile-sub {
    background: #0d1117;
    border-top: 1px solid #374151;
    padding: 4px 0;
}
.nav-mobile-sub-link {
    display: block;
    padding: 11px 32px;
    color: #6b7280;
    text-decoration: none;
    font-size: 13px;
    font-weight: 600;
    transition: color .12s;
}
.nav-mobile-sub-link:hover { color: #fff; }
.nav-mobile-sub-link.act   { color: var(--brand, #e94f37); font-weight: 700; }

/* Overlay oscuro detrás del drawer */
.nav-mobile-overlay {
    display: none;
    position: fixed;
    inset: 0;
    top: 80px;
    background: rgba(0,0,0,.45);
    z-index: 97;
}
.nav-mobile-overlay.open { display: block; }

/* ── Responsive ──────────────────────────────────────────────────────── */
@media (max-width: 640px) {
    /* En móvil: ocultar tabs horizontales, mostrar hamburguesa */
    #nav-links-desktop { display: none !important; }
    .nav-burger         { display: flex; }
    .nav-name           { display: none !important; }
}
@media (min-width: 641px) {
    /* En desktop: ocultar todo lo del menú móvil */
    .nav-mobile, .nav-mobile-overlay { display: none !important; }
    .nav-burger                       { display: none !important; }
}

/* ── Sub-tabs ────────────────────────────────────────────────────────── */
.subtab-bar {
    background: #fff;
    border-bottom: 2px solid #e5e7eb;
    position: sticky;
    top: 80px;
    z-index: 99;
}
.subtab-inner {
    max-width: 1100px;
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
    margin-bottom: -2px;
    transition: color .15s, border-color .15s;
}
.subtab:hover { color: #111827; border-bottom-color: #d1d5db; }
.subtab--act  { color: var(--brand, #e94f37); border-bottom-color: var(--brand, #e94f37); font-weight: 700; }
</style>

<!-- ══ CSS GLOBAL RESPONSIVE — aplicado a TODOS los módulos ═════════════ -->
<!--
    Breakpoints estándar del proyecto:
      xs   < 480px  → teléfono vertical
      sm   480-639px → teléfono horizontal / tablet pequeña
      md   640-1023px → tablet (portrait y landscape)
      lg   1024-1279px → escritorio pequeño / tablet landscape
      xl   1280-1599px → escritorio estándar
      2xl  ≥1600px   → pantalla grande / TV

    Este bloque <style> se inyecta desde nav.php (incluido en <body> de
    cada módulo DESPUÉS del <style> propio de la página), por lo que sus
    reglas tienen mayor precedencia en la cascada cuando la especificidad
    es igual. Se usa !important solo donde es estrictamente necesario para
    garantizar que los ajustes responsive no sean silenciados por los
    estilos inline de cada página.
-->
<style>
/* ═══════════════════════════════════════════════════════════════════════
   0. OVERFLOW GLOBAL — evita que cualquier elemento desborde el viewport
   en horizontal. html usa overflow-x:hidden en lugar de body para no
   interferir con position:fixed (modales, toasts, nav sticky).
   ═══════════════════════════════════════════════════════════════════════ */
html {
    overflow-x: hidden;  /* ningún módulo puede ser más ancho que el viewport */
    max-width: 100%;
}
*, *::before, *::after { box-sizing: border-box; }

/* ═══════════════════════════════════════════════════════════════════════
   1. VARIABLES GLOBALES DE ESPACIADO Y TAMAÑO
   ═══════════════════════════════════════════════════════════════════════ */
:root {
    /* Padding lateral mínimo para que el contenido no pegue en bordes */
    --page-px:   14px;
    /* Ancho máximo del contenido en pantalla normal */
    --max-w:     1100px;
    /* Ancho máximo en pantalla grande / TV */
    --max-w-xl:  1440px;
    /* Tamaño mínimo de tap-target táctil (WCAG 2.5.5) */
    --tap-min:   44px;
}

/* ═══════════════════════════════════════════════════════════════════════
   2. CONTENEDOR DE PÁGINA (.main)
   Cada módulo centra su contenido con .main {max-width: Xpx}.
   Aquí se añade padding responsivo y soporte para notches (safe-area).
   ═══════════════════════════════════════════════════════════════════════ */

/* Padding lateral que respeta la safe-area en iPhones con notch */
.main {
    padding-left:  max(var(--page-px), env(safe-area-inset-left,  0px));
    padding-right: max(var(--page-px), env(safe-area-inset-right, 0px));
    /* Espacio al final de la página para que el contenido no quede
       tapado por la barra de gestos en iOS / Android */
    padding-bottom: max(60px, calc(40px + env(safe-area-inset-bottom, 0px)));
}

/* Pantalla grande (≥1600px): ampliar el contenedor a --max-w-xl */
@media (min-width: 1600px) {
    .main { max-width: var(--max-w-xl) !important; }
}

/* TV (≥1920px): ancho aún mayor y padding generoso */
@media (min-width: 1920px) {
    .main {
        max-width: 1680px !important;
        padding-left:  max(40px, env(safe-area-inset-left,  0px)) !important;
        padding-right: max(40px, env(safe-area-inset-right, 0px)) !important;
    }
}

/* ═══════════════════════════════════════════════════════════════════════
   3. NAVEGACIÓN — ajustes por tamaño de pantalla
   ═══════════════════════════════════════════════════════════════════════ */

/* --- Tablet (641-1023px): tabs horizontales presentes pero compactos --- */
@media (min-width: 641px) and (max-width: 1023px) {
    /* Reducir altura de la barra y tamaño de los tabs para que quepan todos */
    .nav-hdr  { height: 64px; padding: 0 10px; gap: 4px; }
    .nav-brand { font-size: 15px; margin-right: 2px; }
    .nav-brand img { height: 44px !important; }
    .nav-link  { font-size: 11px; padding: 5px 7px; }
    .nav-out   { font-size: 11px; padding: 4px 10px; }
    /* Sub-tabs deben adherirse justo debajo de la barra reducida */
    .subtab-bar { top: 64px; }
    /* Drawer: si se llega a abrir en tablet, empieza debajo de la barra nueva */
    .nav-mobile { top: 64px; }
    .nav-mobile-overlay { top: 64px; }
    /* Logo más pequeño en tablet */
    .nav-brand img { height: 48px !important; }
}

/* --- Pantalla grande (≥1600px): nav más espacioso --- */
@media (min-width: 1600px) {
    .nav-hdr   { height: 88px; padding: 0 28px; }
    .nav-brand { font-size: 19px; }
    .nav-brand img { height: 72px !important; }
    .nav-link  { font-size: 13px; padding: 7px 13px; }
    .nav-out   { font-size: 13px; padding: 7px 16px; }
    .nav-name  { font-size: 13px; display: block; } /* siempre visible */
    .subtab-bar { top: 88px; }
    .subtab     { font-size: 14px; padding: 13px 20px; }
}

/* --- TV (≥1920px): barra aún más alta y tipografía grande --- */
@media (min-width: 1920px) {
    .nav-hdr   { height: 100px; padding: 0 40px; }
    .nav-brand { font-size: 22px; }
    .nav-brand img { height: 80px !important; }
    .nav-link  { font-size: 15px; padding: 9px 16px; border-radius: 10px; }
    .nav-out   { font-size: 15px; padding: 8px 20px; }
    .subtab-bar { top: 100px; }
    .subtab     { font-size: 16px; padding: 15px 26px; }
}

/* ═══════════════════════════════════════════════════════════════════════
   4. CLASES UTILITARIAS DE VISIBILIDAD RESPONSIVE
   Usadas en <th> y <td> de las tablas para ocultar columnas en
   pantallas pequeñas sin eliminarlas del DOM.
   ═══════════════════════════════════════════════════════════════════════ */

/* Ocultar en teléfono vertical (< 480px) */
@media (max-width: 479px) {
    .hide-xs { display: none !important; }
}
/* Ocultar en móvil (< 640px) — alias moderno de .hide-m */
@media (max-width: 639px) {
    .hide-sm { display: none !important; }
    /* Compatibilidad con .hide-m ya existente en algunos módulos */
    .hide-m  { display: none !important; }
}
/* Ocultar en tablet y móvil (< 1024px) */
@media (max-width: 1023px) {
    .hide-md { display: none !important; }
}
/* Ocultar en escritorio pequeño (< 1280px) */
@media (max-width: 1279px) {
    .hide-lg { display: none !important; }
}

/* ═══════════════════════════════════════════════════════════════════════
   5. TABLAS (.tbl + .tbl-wrap)
   Estrategia:
   • .tbl-wrap siempre tiene scroll horizontal (ya definido en muchos módulos,
     aquí se refuerza como regla global).
   • En móvil se reduce el padding de celdas para que quepan más columnas.
   • El scroll horizontal es la forma menos destructiva para tablas de datos.
   ═══════════════════════════════════════════════════════════════════════ */

/* Asegurar scroll horizontal en TODOS los wrappers de tabla */
.tbl-wrap {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch; /* scroll suave en Safari/iOS */
}

/* Teléfono vertical (< 480px): celdas más compactas */
@media (max-width: 479px) {
    .tbl thead th       { padding: 7px 8px !important; font-size: 10px !important; }
    .tbl td             { padding: 7px 8px !important; font-size: 11px !important; }
    /* Badges dentro de celdas: más pequeños */
    .tbl td .badge      { font-size: 9px !important; padding: 2px 5px !important; }
    /* Botones de acción dentro de celdas */
    .tbl td .btn-a,
    .tbl td .btn-tbl    { padding: 4px 7px !important; font-size: 10px !important; }
}

/* Móvil (< 640px): padding levemente reducido */
@media (max-width: 639px) {
    .tbl thead th  { padding: 8px 9px; font-size: 11px; }
    .tbl td        { padding: 8px 9px; font-size: 12px; }
}

/* Pantalla grande (≥1600px): más espacio en celdas */
@media (min-width: 1600px) {
    .tbl thead th  { padding: 12px 16px; font-size: 12px; }
    .tbl td        { padding: 13px 16px; font-size: 14px; }
    .tbl td .badge { font-size: 12px; padding: 4px 10px; }
}

/* TV (≥1920px): celdas holgadas para lectura a distancia */
@media (min-width: 1920px) {
    .tbl thead th  { padding: 15px 20px; font-size: 13px; }
    .tbl td        { padding: 15px 20px; font-size: 15px; }
}

/* ═══════════════════════════════════════════════════════════════════════
   6. KPI CARDS (.kpi + .kpi-row)
   .kpi-row usa repeat(auto-fit, minmax(X, 1fr)) que ya es responsive,
   pero en teléfonos puede producir tarjetas muy angostas si X es grande.
   ═══════════════════════════════════════════════════════════════════════ */

/* Teléfono vertical: forzar exactamente 2 columnas */
@media (max-width: 479px) {
    .kpi-row { grid-template-columns: repeat(2, 1fr) !important; gap: 8px !important; }
    .kpi     { padding: 10px 11px !important; }
    .kpi-val { font-size: clamp(14px, 4vw, 18px) !important; }
    .kpi-lbl { font-size: 9px !important; letter-spacing: .3px !important; }
    .kpi-sub { font-size: 9px !important; }
}

/* Tablet (640-1023px): 3 columnas mínimo si hay espacio */
@media (min-width: 640px) and (max-width: 1023px) {
    .kpi-row { grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); }
}

/* Pantalla grande (≥1600px): tarjetas más holgadas */
@media (min-width: 1600px) {
    .kpi     { padding: 18px 22px; }
    .kpi-val { font-size: clamp(22px, 2vw, 28px); }
    .kpi-lbl { font-size: 12px; letter-spacing: .5px; }
    .kpi-sub { font-size: 12px; }
}

/* TV (≥1920px): tarjetas grandes y texto legible a distancia */
@media (min-width: 1920px) {
    .kpi     { padding: 24px 28px; }
    .kpi-val { font-size: clamp(26px, 2.2vw, 34px); }
    .kpi-lbl { font-size: 14px; }
    .kpi-sub { font-size: 13px; }
}

/* ═══════════════════════════════════════════════════════════════════════
   7. ENCABEZADO DE PÁGINA (.page-hdr, .page-title, .page-sub)
   ═══════════════════════════════════════════════════════════════════════ */

/* Teléfono vertical: título e información apilados verticalmente */
@media (max-width: 479px) {
    .page-hdr   { flex-direction: column; align-items: stretch !important; gap: 8px; }
    .page-title { font-size: clamp(16px, 5vw, 20px) !important; }
    .page-sub   { font-size: 11px !important; }
    /* Botones del header ocupan todo el ancho en móvil */
    .page-hdr > a[style*="background"],
    .hdr-btns .btn-primary { width: 100%; text-align: center; }
    .hdr-btns { flex-wrap: wrap; }
}

/* Pantalla grande: título más grande */
@media (min-width: 1600px) {
    .page-title { font-size: clamp(24px, 2vw, 30px) !important; }
    .page-sub   { font-size: 14px !important; }
}
@media (min-width: 1920px) {
    .page-title { font-size: clamp(28px, 2.2vw, 36px) !important; }
    .page-sub   { font-size: 16px !important; }
}

/* ═══════════════════════════════════════════════════════════════════════
   8. FORMULARIOS (.form-grid, .fg, inputs)
   ═══════════════════════════════════════════════════════════════════════ */

/* Teléfono vertical: formularios de 2 columnas colapsan a 1 */
@media (max-width: 479px) {
    .form-grid { grid-template-columns: 1fr !important; gap: 8px !important; }
}

/* Móvil (< 640px): también colapsar a 1 columna */
@media (max-width: 639px) {
    .form-grid { grid-template-columns: 1fr !important; }
}

/*
   FIX crítico para iOS: si un input tiene font-size < 16px el navegador
   Safari hace zoom automático al enfocar el campo, lo cual desacomoda
   toda la interfaz. Forzar mínimo 16px en móvil elimina ese comportamiento.
*/
@media (max-width: 639px) {
    input[type="text"],
    input[type="number"],
    input[type="date"],
    input[type="datetime-local"],
    input[type="email"],
    input[type="search"],
    input[type="tel"],
    input[type="password"],
    select,
    textarea {
        font-size: max(16px, var(--fs-body, 13px)) !important;
    }
}

/* Pantalla grande: inputs un poco más grandes */
@media (min-width: 1600px) {
    .fg input, .fg select, .fg textarea,
    .fg-m input, .fg-m select, .fg-m textarea {
        padding: 10px 13px;
        font-size: 14px;
    }
    .fg label, .fg-m label { font-size: 12px; }
}

/* ═══════════════════════════════════════════════════════════════════════
   9. BOTONES — touch targets
   WCAG 2.5.5 recomienda 44×44px de área táctil mínima en dispositivos touch.
   ═══════════════════════════════════════════════════════════════════════ */

/* Móvil (< 640px): agrandar botones principales para dedo */
@media (max-width: 639px) {
    .btn-primary, .btn-sec {
        min-height: var(--tap-min);
        padding: 10px 18px;
        font-size: 14px;
    }
    /* Botones de acción en tabla: un poco más grandes */
    .btn-a   { min-height: 36px; padding: 6px 10px; font-size: 11px; }
    .btn-tbl { min-height: 36px; padding: 6px 10px; font-size: 11px; }
    /* Botón filtrar de formularios */
    .btn-filtrar { min-height: var(--tap-min); font-size: 14px; }
}

/* Pantalla grande: botones proporcionalmente más grandes */
@media (min-width: 1600px) {
    .btn-primary, .btn-sec { padding: 10px 22px; font-size: 14px; }
    .btn-a                 { padding: 6px 12px; font-size: 12px; }
}
@media (min-width: 1920px) {
    .btn-primary, .btn-sec { padding: 13px 28px; font-size: 16px; }
    .btn-a                 { padding: 8px 14px; font-size: 13px; }
}

/* ═══════════════════════════════════════════════════════════════════════
   10. BARRAS DE FILTROS (.filtros, .toolbar, .periodo-bar)
   ═══════════════════════════════════════════════════════════════════════ */

/* Teléfono vertical: apilar todos los controles de filtro verticalmente */
@media (max-width: 479px) {
    /* Filtros de historial de ventas */
    .filtros            { flex-direction: column !important; gap: 8px !important; }
    .filtros .fg        { width: 100% !important; }
    .filtros .btn-filtrar { width: 100% !important; min-height: var(--tap-min); }
    /* Toolbar de inventario, productos, costos */
    .toolbar            { flex-direction: column !important; gap: 8px !important; }
    .toolbar > *        { width: 100% !important; }
    .filter-sel         { width: 100% !important; }
    /* Barra de período (costos) */
    .periodo-bar        { flex-direction: column !important; gap: 8px !important; align-items: stretch !important; }
    .periodo-bar select { width: 100% !important; }
    .btn-periodo        { width: 100% !important; min-height: var(--tap-min); }
    .periodo-lbl        { margin-left: 0 !important; }
}

/* Móvil (< 640px): reducir gap en toolbars */
@media (max-width: 639px) {
    .toolbar { flex-wrap: wrap; gap: 6px; }
    .toolbar input[type="search"] { min-width: 0; flex: 1 1 140px; }
    .filter-sel { flex: 1 1 130px; }
}

/* ═══════════════════════════════════════════════════════════════════════
   11. TIPOGRAFÍA GLOBAL — escalado para pantallas grandes y TV
   ═══════════════════════════════════════════════════════════════════════ */

/* Pantalla grande (≥1600px): aumentar ligeramente la base tipográfica */
@media (min-width: 1600px) {
    body   { font-size: calc(var(--fs-body, 13px) + 1px) !important; }
    .muted,
    .page-sub,
    .kpi-lbl,
    .tbl thead th { font-size: 12px !important; }
}

/* TV (≥1920px): escala mayor — el usuario está a mayor distancia */
@media (min-width: 1920px) {
    body   { font-size: calc(var(--fs-body, 13px) + 2px) !important; }
    p, td, li, .tbl td { font-size: 15px !important; }
    .muted,
    .kpi-lbl   { font-size: 13px !important; }
    .badge     { font-size: 12px !important; padding: 4px 11px !important; }
    /* Inputs legibles a distancia */
    input, select, textarea { font-size: 16px !important; }
}

/* ═══════════════════════════════════════════════════════════════════════
   12. TOASTS Y OVERLAYS
   ═══════════════════════════════════════════════════════════════════════ */

/* Teléfono vertical: toast ocupa casi todo el ancho */
@media (max-width: 479px) {
    .toast {
        left: 8px !important;
        right: 8px !important;
        transform: translateY(20px) !important;
        white-space: normal !important;
        text-align: center;
        font-size: 13px !important;
    }
    .toast.on { transform: translateY(0) !important; }
}

/* ═══════════════════════════════════════════════════════════════════════
   13. MODALES GENÉRICOS (.overlay, .modal)
   Regla base GLOBAL: todos los modales del sistema son scrollables y
   nunca sobrepasan el viewport, independientemente del contenido.
   nav.php se inyecta dentro de <body> → sus reglas tienen mayor peso
   de cascada que el CSS en <head> de cada página.
   ═══════════════════════════════════════════════════════════════════════ */

/*
 * BASE: garantiza max-height + scroll en TODOS los modales del sistema.
 * Usa max-height en cascada: primero 90dvh (dynamic viewport height,
 * descuenta barra de direcciones del navegador), luego fallback 90vh.
 * -webkit-overflow-scrolling: touch → scroll con inercia en iOS.
 */
.modal {
    max-height: 90vh;             /* fallback universal */
    max-height: 90dvh;            /* browsers modernos: descuenta chrome del navegador */
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
}

/*
 * El encabezado del modal (.modal-hdr) queda fijo arriba mientras el
 * contenido hace scroll, para que el botón "✕" siempre sea visible.
 */
.modal-hdr {
    position: sticky;
    top: 0;
    background: inherit;          /* hereda el blanco del .modal */
    background: var(--white, #fff);
    z-index: 2;
}

/* xs < 480px: BOTTOM SHEET
   El modal se ancla abajo (flex-end) para que sea más natural en móvil
   vertical. Bordes superiores redondeados, ocupa hasta el 92% de la pantalla. */
@media (max-width: 479px) {
    .overlay {
        padding: 0 !important;
        align-items: flex-end !important;
    }
    .modal {
        border-radius: 20px 20px 0 0 !important;
        max-width: 100% !important;
        max-height: 92vh !important;
        max-height: 92dvh !important;
        /* margen seguro en notch de iOS */
        padding-bottom: max(20px, env(safe-area-inset-bottom)) !important;
    }
}

/* sm 480–639px: sigue bottom sheet — pantallas de teléfono en landscape
   o tabletas pequeñas donde el modal puede seguir siendo demasiado alto. */
@media (min-width: 480px) and (max-width: 639px) {
    .overlay {
        padding: 8px !important;
        align-items: flex-end !important;
    }
    .modal {
        border-radius: 20px 20px 0 0 !important;
        max-width: 100% !important;
        max-height: 90vh !important;
        max-height: 90dvh !important;
    }
}

/* md 640–1023px: modal centrado, se garantiza que no desborde */
@media (min-width: 640px) and (max-width: 1023px) {
    .modal { max-height: 88vh; max-height: 88dvh; }
}

/* lg/xl ≥ 1024px: pantalla grande, modal más cómodo */
@media (min-width: 1024px) {
    .modal { max-height: 85vh; max-height: 85dvh; }
}

/* 2xl ≥ 1600px: modal más ancho en pantallas grandes */
@media (min-width: 1600px) {
    .modal { max-width: 780px; padding: 32px; }
}

/* ═══════════════════════════════════════════════════════════════════════
   14. GRIDS DE ESTADÍSTICAS (.stats, .stat)
   Usados en inventario, productos, activos y otros módulos.
   ═══════════════════════════════════════════════════════════════════════ */

/* Teléfono vertical: máximo 2 columnas en grids de stats */
@media (max-width: 479px) {
    .stats { grid-template-columns: repeat(2, 1fr) !important; gap: 8px !important; }
    .stat  { padding: 10px 11px !important; }
    .stat-value, .stat-n { font-size: clamp(14px, 4vw, 18px) !important; }
    .stat-label, .stat-l { font-size: 9px !important; }
}

/* Pantalla grande: stats con más padding */
@media (min-width: 1600px) {
    .stat  { padding: 16px 20px; }
    .stat-value, .stat-n { font-size: clamp(20px, 2vw, 26px); }
    .stat-label, .stat-l { font-size: 12px; }
}

/* ═══════════════════════════════════════════════════════════════════════
   15. PRINT — ocultar navegación al imprimir
   ═══════════════════════════════════════════════════════════════════════ */
@media print {
    .nav-hdr, .subtab-bar,
    .nav-mobile, .nav-mobile-overlay,
    .toast, .btn-primary, .btn-sec,
    .btn-a, .btn-tbl, .btn-filtrar { display: none !important; }
    .main { max-width: 100% !important; padding: 0 16px !important; }
    .tbl-wrap { overflow: visible !important; }
}

/* ═══════════════════════════════════════════════════════════════════════
   16. BOTONES DE ACCIÓN — clase .ic para iconos SVG unificados
   ═══════════════════════════════════════════════════════════════════════ */
/* Alinea verticalmente SVGs inline en botones con texto */
button svg, a svg { vertical-align: middle; pointer-events: none; }

/* Clase utilitaria: convierte cualquier botón en botón-icono cuadrado */
.ic {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 30px !important;
    height: 30px !important;
    padding: 0 !important;
    line-height: 1 !important;
}
.ic svg { display: block; flex-shrink: 0; }

/* Colores semánticos para botones-icono — reutilizables en TODOS los módulos.
   El icono usa stroke="currentColor" y el recuadro lleva FONDO tintado del
   mismo tono (borde igual al fondo → caja de color plana, como en Compras).
   Hover = tinte un paso más oscuro. Línea consistente:
   editar=azul, ver=gris, info=celeste, fusionar=violeta, ok/cobro=verde,
   whatsapp=verde WA, eliminar/peligro=rojo. */
.ic-edit  { color:#1d4ed8; background:#dbeafe; border-color:#dbeafe; }
.ic-view  { color:#374151; background:#f3f4f6; border-color:#f3f4f6; }
.ic-info  { color:#0369a1; background:#e0f2fe; border-color:#e0f2fe; }
.ic-merge { color:#6d28d9; background:#ede9fe; border-color:#ede9fe; }
.ic-ok    { color:#065f46; background:#d1fae5; border-color:#d1fae5; }
.ic-wa    { color:#16a34a; background:#dcfce7; border-color:#dcfce7; }
.ic-del   { color:#991b1b; background:#fee2e2; border-color:#fee2e2; }
.ic-warn  { color:#92400e; background:#fef3c7; border-color:#fef3c7; }
.ic-gift  { color:#9d174d; background:#fce7f3; border-color:#fce7f3; }
.ic-edit:hover  { color:#1d4ed8; background:#bfdbfe; border-color:#bfdbfe; }
.ic-view:hover  { color:#111827; background:#e5e7eb; border-color:#e5e7eb; }
.ic-info:hover  { color:#0369a1; background:#bae6fd; border-color:#bae6fd; }
.ic-merge:hover { color:#6d28d9; background:#ddd6fe; border-color:#ddd6fe; }
.ic-ok:hover    { color:#047857; background:#a7f3d0; border-color:#a7f3d0; }
.ic-wa:hover    { color:#16a34a; background:#bbf7d0; border-color:#bbf7d0; }
.ic-del:hover   { color:#b91c1c; background:#fecaca; border-color:#fecaca; }
.ic-warn:hover  { color:#92400e; background:#fde68a; border-color:#fde68a; }
.ic-gift:hover  { color:#9d174d; background:#fbcfe8; border-color:#fbcfe8; }

/* Mayor touch target en móvil */
@media (max-width: 640px) {
    .ic { width: 38px !important; height: 38px !important; }
    .ic svg { width: 18px !important; height: 18px !important; }
}

/* ═══════════════════════════════════════════════════════════════════════
   17. TABLAS → TARJETAS EN MÓVIL VERTICAL (reutilizable en todos los módulos)
   Marca la tabla con class="rcards" y su contenedor con class="rcards-wrap".
   En cada <td> agrega data-label="Etiqueta"; la primera celda (nombre) va sin
   etiqueta y a todo el ancho; la celda de acciones lleva class="acc-cell".
   <480px: cada fila se vuelve una tarjeta (nombre arriba, campos etiquetados,
   botones de acción abajo) — sin scroll horizontal. ≥480px no cambia nada.
   ═══════════════════════════════════════════════════════════════════════ */
@media (max-width: 479px) {
    /* El contenedor deja de scrollear; las tarjetas son los <tr> */
    .rcards-wrap { overflow: visible !important; background: transparent !important;
                   box-shadow: none !important; border-radius: 0 !important; }
    .rcards { min-width: 0 !important; }
    .rcards thead { display: none; }
    .rcards, .rcards tbody { display: block; width: 100%; }
    .rcards tr {
        display: block; background: var(--white, #fff);
        border: 1px solid var(--g8, #d1d5db); border-radius: 12px;
        padding: 10px 12px; margin-bottom: 10px; box-shadow: 0 1px 3px rgba(0,0,0,.05);
    }
    .rcards tr.inactivo, .rcards tr.anulada, .rcards tr.is-inactivo { opacity: .6; }
    /* Re-mostrar columnas que se ocultaban con scroll (todas se ven en la tarjeta) */
    .rcards .hide-xs, .rcards .hide-sm, .rcards .hide-m { display: flex !important; }
    /* Cada celda: etiqueta a la izquierda, valor a la derecha */
    .rcards td {
        display: flex; justify-content: space-between; align-items: center; gap: 12px;
        padding: 5px 0; border: none !important; text-align: right; font-size: 13px;
    }
    .rcards td:empty { display: none; }
    .rcards td[data-label]::before {
        content: attr(data-label);
        font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .3px;
        color: var(--g5, #6b7280); text-align: left; flex: 0 0 auto;
    }
    /* Primera celda (nombre/título): ancho completo, destacada, sin etiqueta.
       Si el título no es la primera celda, márcalo con class="rcard-title" y
       oculta columnas que no aplican en tarjeta con class="rcard-hide". */
    .rcards td:first-child, .rcards td.rcard-title {
        display: block; text-align: left;
        border-bottom: 1px solid var(--g9, #f3f4f6) !important;
        padding-bottom: 8px; margin-bottom: 4px;
    }
    .rcards .rcard-hide { display: none !important; }
    /* Acciones: fila propia abajo, botones envueltos */
    .rcards td.acc-cell {
        display: flex; flex-wrap: wrap; justify-content: flex-start; gap: 8px;
        white-space: normal !important;
        border-top: 1px solid var(--g9, #f3f4f6) !important;
        padding-top: 10px; margin-top: 6px;
    }
}
</style>

<script>
/* ── Menú hamburguesa para móvil ──────────────────────────────────────── */
function toggleNavMobile() {
    var burger  = document.getElementById('nav-burger');
    var menu    = document.getElementById('nav-mobile');
    var overlay = document.getElementById('nav-mobile-overlay');
    var isOpen  = menu.classList.contains('open');
    if (isOpen) {
        closeNavMobile();
    } else {
        burger.classList.add('open');
        burger.setAttribute('aria-expanded', 'true');
        menu.classList.add('open');
        menu.setAttribute('aria-hidden', 'false');
        overlay.classList.add('open');
        // Impedir scroll del body mientras el menú está abierto
        document.body.style.overflow = 'hidden';
    }
}
function closeNavMobile() {
    document.getElementById('nav-burger').classList.remove('open');
    document.getElementById('nav-burger').setAttribute('aria-expanded', 'false');
    document.getElementById('nav-mobile').classList.remove('open');
    document.getElementById('nav-mobile').setAttribute('aria-hidden', 'true');
    document.getElementById('nav-mobile-overlay').classList.remove('open');
    document.body.style.overflow = '';
}
// Cerrar con tecla Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeNavMobile();
});
// Cerrar automáticamente al rotar a landscape (≥641px)
window.addEventListener('resize', function() {
    if (window.innerWidth >= 641) closeNavMobile();
});

/* ── Formato numérico configurable (Admin > Apariencia, migraciones 040/041) ─
   formatMiles(1234.5)      -> "1.235"        (entero, redondeado)
   formatDecimal(1234.5)    -> "1.234,50"     (decimales = NUM_FORMAT.decimales)
   formatDecimal(1234.5, 3) -> "1.234,500"    (decimales explícitos)

   Separador de millones (sepMillones): si es igual a sepMiles, el formato es
   uniforme (ej. "1.234.567"). Si es distinto, solo el grupo junto al decimal
   usa sepMiles y los grupos a la izquierda usan sepMillones
   (ej. miles="." y millones="'": formatMiles(1234567) -> "1'234.567")        */
window.NUM_FORMAT = {
    decimales: <?= (int)$_theme['num_decimales'] ?>,
    sepMiles: <?= json_encode($_theme['num_sep_miles']) ?>,
    sepMillones: <?= json_encode($_theme['num_sep_millones']) ?>,
    sepDecimal: <?= json_encode($_theme['num_sep_decimal']) ?>
};
function formatDecimal(n, dec) {
    dec = (dec === undefined) ? NUM_FORMAT.decimales : dec;
    n = Number(n) || 0;
    var fixed = n.toFixed(dec);
    var neg = fixed.charAt(0) === '-';
    if (neg) fixed = fixed.slice(1);
    var parts  = fixed.split('.');
    var entero = parts[0];
    var grupos = [];
    while (entero.length > 3) {
        grupos.unshift(entero.slice(-3));
        entero = entero.slice(0, -3);
    }
    grupos.unshift(entero);
    var out    = grupos[0];
    var ultimo = grupos.length - 1;
    for (var i = 1; i <= ultimo; i++) {
        out += (i === ultimo ? NUM_FORMAT.sepMiles : NUM_FORMAT.sepMillones) + grupos[i];
    }
    if (dec > 0) out += NUM_FORMAT.sepDecimal + parts[1];
    return neg ? '-' + out : out;
}
function formatMiles(n) {
    return formatDecimal(n, 0);
}
/* Inverso de formatDecimal(): convierte un texto formateado a Number, quitando
   separadores de agrupación (miles/millones) y normalizando el decimal a '.'.
   Para texto YA en crudo ('.' decimal, sin agrupación) usar parseFloat directo
   (parseNum quitaría un '.' usado como separador de miles).                     */
function parseNum(str) {
    if (typeof str !== 'string') return Number(str) || 0;
    var s = str.trim();
    if (s === '') return 0;
    s = s.split(NUM_FORMAT.sepMiles).join('');
    if (NUM_FORMAT.sepMillones !== NUM_FORMAT.sepMiles) s = s.split(NUM_FORMAT.sepMillones).join('');
    if (NUM_FORMAT.sepDecimal !== '.') s = s.split(NUM_FORMAT.sepDecimal).join('.');
    var n = parseFloat(s);
    return isNaN(n) ? 0 : n;
}
</script>
