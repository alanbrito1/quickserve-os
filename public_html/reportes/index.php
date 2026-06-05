<?php
/**
 * public_html/reportes/index.php
 * Hub de reportes — acceso centralizado a todos los informes del sistema.
 * Muestra solo los reportes a los que el usuario tiene acceso.
 */
require_once __DIR__ . '/../app/middleware/auth_check.php';

$nav_activo = 'reportes';
permiso_requerir('reportes', 'solo_ver');

// ── Catálogo de reportes disponibles ─────────────────────────────────────────
// 'modulo' → permiso mínimo requerido para ver el reporte
$reportes = [
    [
        'titulo' => 'Ventas & Rentabilidad',
        'desc'   => 'Ventas por período, método de pago, margen por producto y análisis de fiados.',
        'url'    => '/reportes/ventas.php',
        'icon'   => 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z',
        'modulo' => 'ventas',
    ],
    [
        'titulo' => 'Inventario, Producción & Activos',
        'desc'   => 'Stock de insumos, inventario de producto terminado, producción del período y depreciación.',
        'url'    => '/reportes/operativo.php',
        'icon'   => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4',
        'modulo' => 'inventario',
    ],
    [
        'titulo' => 'Nómina y Costo Laboral',
        'desc'   => 'Costo laboral mensual con desglose prestacional, costo directo vs indirecto y tendencia.',
        'url'    => '/reportes/nomina.php',
        'icon'   => 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z',
        'modulo' => 'nomina',
    ],
    [
        'titulo' => 'Costos del Negocio',
        'desc'   => 'Costos directos e indirectos, compras, depreciación, nómina y gran total mensual.',
        'url'    => '/reportes/costos.php',
        'icon'   => 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
        'modulo' => 'costos',
    ],
    [
        'titulo' => 'Compras & Proveedores',
        'desc'   => 'Historial de compras, gasto por proveedor y evolución de costos de insumos.',
        'url'    => '/reportes/compras.php',
        'icon'   => 'M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z',
        'modulo' => 'compras',
    ],
    [
        'titulo' => 'Variación de Precios y Costos',
        'desc'   => 'Historial de cómo han cambiado en el tiempo los precios de insumos, productos, salarios y costos fijos.',
        'url'    => '/reportes/precios.php',
        'icon'   => 'M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z',
        'modulo' => 'reportes',
    ],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reportes — <?= APP_NAME ?></title>
    <style>
        :root { --brand:#e94f37; --dark:#111827; --g5:#6b7280; --g8:#d1d5db; --g9:#f3f4f6; --white:#fff; }
        *,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
        body { font-family:system-ui,-apple-system,sans-serif; background:var(--g9); color:var(--dark); }
        .main { padding:24px 14px; max-width:840px; margin:0 auto; }
        .page-title { font-size:22px; font-weight:800; margin-bottom:4px; }
        .page-sub   { font-size:14px; color:var(--g5); margin-bottom:24px; }
        .grid { display:grid; grid-template-columns:1fr; gap:12px; }
        @media(min-width:520px){ .grid { grid-template-columns:1fr 1fr; } }
        .rep-card {
            background:var(--white); border-radius:14px; padding:20px;
            box-shadow:0 1px 4px rgba(0,0,0,.06); text-decoration:none;
            color:var(--dark); display:flex; gap:14px; align-items:flex-start;
            transition:box-shadow .15s, transform .12s; border:1px solid transparent;
        }
        .rep-card:hover { box-shadow:0 6px 20px rgba(0,0,0,.1); transform:translateY(-2px); border-color:var(--brand); }
        .rep-icon { width:44px; height:44px; background:#fef2f0; border-radius:12px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
        .rep-icon svg { width:22px; height:22px; stroke:var(--brand); fill:none; stroke-width:1.8; stroke-linecap:round; stroke-linejoin:round; }
        .rep-info h3 { font-size:15px; font-weight:800; margin-bottom:4px; }
        .rep-info p  { font-size:13px; color:var(--g5); line-height:1.4; }
        .rep-arrow   { margin-left:auto; font-size:20px; color:var(--g8); align-self:center; flex-shrink:0; }

        /* ════════════════════════════════════════════════════════════════
           RESPONSIVE — REPORTES INDEX
           ════════════════════════════════════════════════════════════════ */
        /* xs: tarjetas apiladas, ícono más compacto */
        @media (max-width:479px) {
            .main { padding:14px 10px 40px; }
            .page-title { font-size:18px; }
            .page-sub { font-size:12px; }
            .grid { grid-template-columns:1fr !important; }
            .rep-card { padding:14px; gap:10px; }
            .rep-icon { width:38px; height:38px; }
            .rep-info h3 { font-size:14px; }
        }
        /* ≥1600px: grilla más ancha */
        @media (min-width:1600px) {
            .main { max-width:1200px; padding:32px 20px; }
            .rep-info h3 { font-size:16px; }
            .rep-info p  { font-size:14px; }
        }
        /* TV ≥1920px */
        @media (min-width:1920px) {
            .main { max-width:1440px; }
            .rep-card { padding:26px; }
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/../app/views/nav.php'; ?>
<main class="main">
    <h1 class="page-title">Reportes y Exportación</h1>
    <p class="page-sub">Todos los reportes exportan a Excel (.xlsx) con múltiples hojas.</p>
    <div class="grid">
        <?php foreach ($reportes as $r):
            if (!permiso_tiene($r['modulo'], 'solo_ver')) continue;
        ?>
        <a href="<?= APP_BASE . $r['url'] ?>" class="rep-card">
            <div class="rep-icon">
                <svg viewBox="0 0 24 24"><path d="<?= $r['icon'] ?>"/></svg>
            </div>
            <div class="rep-info">
                <h3><?= htmlspecialchars($r['titulo']) ?></h3>
                <p><?= htmlspecialchars($r['desc']) ?></p>
            </div>
            <span class="rep-arrow">›</span>
        </a>
        <?php endforeach; ?>
    </div>
</main>
</body>
</html>
