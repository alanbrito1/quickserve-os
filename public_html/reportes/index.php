<?php
/**
 * public_html/reportes/index.php
 * Hub de reportes — acceso centralizado a todos los informes del sistema.
 */

require_once __DIR__ . '/../app/middleware/auth_check.php';

permiso_requerir('reportes', 'solo_ver');

$reportes = [
    [
        'titulo'  => 'Ventas & Rentabilidad',
        'desc'    => 'Ventas por período, por método de pago y margen de rentabilidad por producto.',
        'url'     => '/reportes/ventas.php',
        'icon'    => 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z',
        'modulo'  => 'ventas',
    ],
    [
        'titulo'  => 'Inventario & Activos',
        'desc'    => 'Estado del stock, costos de insumos y depreciación de equipos.',
        'url'     => '/reportes/operativo.php',
        'icon'    => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4',
        'modulo'  => 'inventario',
    ],
    [
        'titulo'  => 'Nómina',
        'desc'    => 'Costo laboral mensual con desglose de carga prestacional por empleado.',
        'url'     => '/reportes/nomina.php',
        'icon'    => 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z',
        'modulo'  => 'nomina',
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
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root { --brand:#e94f37; --dark:#111827; --g2:#374151; --g5:#6b7280; --g8:#d1d5db; --g9:#f3f4f6; --white:#fff; }
        body { font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; background:var(--g9); min-height:100vh; color:var(--dark); }
        .hdr { background:var(--dark); color:var(--white); height:54px; padding:0 14px; display:flex; align-items:center; justify-content:space-between; position:sticky; top:0; z-index:50; box-shadow:0 2px 8px rgba(0,0,0,.3); }
        .brand { font-size:17px; font-weight:800; } .brand span{color:var(--brand);}
        .nav { display:flex; gap:6px; }
        .nl { color:var(--g8); text-decoration:none; font-size:13px; padding:5px 10px; border-radius:8px; }
        .nl:hover { background:var(--g2); color:var(--white); } .nl.act { background:var(--brand); color:var(--white); }

        .main { padding:24px 14px; max-width:800px; margin:0 auto; }
        .page-title { font-size:22px; font-weight:800; margin-bottom:6px; }
        .page-sub   { font-size:14px; color:var(--g5); margin-bottom:24px; }

        .grid { display:grid; grid-template-columns:1fr; gap:12px; }
        @media(min-width:520px){ .grid { grid-template-columns:1fr 1fr; } }

        .rep-card {
            background:var(--white);
            border-radius:16px;
            padding:20px;
            box-shadow:0 1px 4px rgba(0,0,0,.06);
            text-decoration:none;
            color:var(--dark);
            display:flex;
            gap:14px;
            align-items:flex-start;
            transition:box-shadow .15s, transform .12s;
        }
        .rep-card:hover { box-shadow:0 6px 20px rgba(0,0,0,.1); transform:translateY(-2px); }
        .rep-icon { width:44px; height:44px; background:#fef2f0; border-radius:12px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
        .rep-icon svg { width:24px; height:24px; stroke:var(--brand); fill:none; stroke-width:1.8; stroke-linecap:round; stroke-linejoin:round; }
        .rep-info h3 { font-size:15px; font-weight:800; margin-bottom:4px; }
        .rep-info p  { font-size:13px; color:var(--g5); line-height:1.4; }
        .rep-arrow { margin-left:auto; font-size:20px; color:var(--g8); align-self:center; }
    </style>
</head>
<body>
<?php $nav_activo = 'reportes'; include __DIR__ . '/../app/views/nav.php'; ?>

<!-- header reemplazado por nav.php -->
<main class="main">
    <h1 class="page-title">Reportes y Exportación</h1>
    <p class="page-sub">Todos los reportes se pueden exportar a Excel (.xlsx).</p>
    <div class="grid">
        <?php foreach ($reportes as $r):
            if (!permiso_tiene($r['modulo'], 'solo_ver')) continue;
        ?>
        <a href="<?= $r['url'] ?>" class="rep-card">
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
