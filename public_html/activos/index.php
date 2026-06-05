<?php
/**
 * public_html/activos/index.php
 * Módulo de Activos Fijos — control físico, depreciación e impacto en costos.
 */

require_once __DIR__ . '/../app/middleware/auth_check.php';
require_once __DIR__ . '/../app/models/ActivoModel.php';
require_once __DIR__ . '/../app/helpers/ListasHelper.php';

$nav_activo = 'activos';
permiso_requerir('activos', 'solo_ver');

$msg_ok  = '';
$msg_err = '';

// ── Detección de columnas (migración 005 puede no estar aplicada aún) ────────
$v5 = ActivoModel::columnas_existen(['numero_unidades', 'precio_unitario', 'serial',
                                      'estado_fisico', 'categoria_activo', 'garantia_hasta']);

// ── Procesar POST ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verificar()) {
    permiso_requerir('activos', 'editar_existentes');
    $accion = $_POST['accion'] ?? '';

    try {
        switch ($accion) {
            case 'crear':
                ActivoModel::crear($_POST);
                $msg_ok = 'Activo registrado. Depreciación calculada automáticamente.';
                break;
            case 'editar':
                ActivoModel::actualizar((int)$_POST['id'], $_POST);
                $msg_ok = 'Activo actualizado.';
                break;
            case 'duplicar':
                $nuevo_id = ActivoModel::duplicar((int)$_POST['id']);
                $msg_ok = "Activo duplicado — nuevo ID #$nuevo_id. Edita los datos específicos (serial, foto).";
                break;
            case 'toggle':
                if (permiso_tiene('activos', 'admin_total')) {
                    ActivoModel::toggle_activo((int)$_POST['id']);
                    $msg_ok = 'Estado del activo actualizado.';
                }
                break;
        }
    } catch (RuntimeException $e) {
        $msg_err = $e->getMessage();
    }

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $msg_err = 'Token de seguridad inválido.';
}

// ── Criterio de orden ─────────────────────────────────────────────────────────
$ordenes_ok = ['fecha', 'nombre', 'lugar', 'categoria'];
$orden = in_array($_GET['orden'] ?? 'fecha', $ordenes_ok, true) ? ($_GET['orden'] ?? 'fecha') : 'fecha';
if (($orden === 'lugar' || $orden === 'categoria') && !$v5) $orden = 'fecha';

// ── Datos ─────────────────────────────────────────────────────────────────────
$activos = ActivoModel::todos($orden);
$proveedores_list = db()->query('SELECT id, nombre FROM proveedores WHERE activo=1 ORDER BY nombre')->fetchAll();
$resumen = $v5 ? ActivoModel::resumen_ampliado() : ActivoModel::resumen();

$dep_dia   = (float)($resumen['dep_diaria_total']  ?? 0);
$dep_mens  = (float)($resumen['dep_mensual_total'] ?? 0);
$inv_total = (float)($resumen['inversion_total']   ?? 0);
$val_libros= (float)($resumen['valor_en_libros']   ?? 0);
$mal_estado= (int)  ($resumen['en_mal_estado']     ?? 0);
$garan_venc= (int)  ($resumen['garantia_vencida']  ?? 0);

// Categorías para select
// Categorías de activos desde la tabla listas_sistema (Admin → Catálogos).
// Fallback hardcodeado si la migración 029 aún no está aplicada.
$_cats_lista = listas_map('categoria_activo');
$CATEGORIAS  = !empty($_cats_lista) ? $_cats_lista : [
    'equipo_cocina'   => 'Equipo de cocina',
    'electrodomestico'=> 'Electrodoméstico',
    'herramienta'     => 'Herramienta',
    'utensilio'       => 'Utensilio',
    'mobiliario'      => 'Mobiliario',
    'vehiculo'        => 'Vehículo',
    'otro'            => 'Otro',
];
$ESTADOS_FISICO = [
    'excelente' => ['label' => 'Excelente', 'style' => 'background:#d1fae5;color:#065f46'],
    'bueno'     => ['label' => 'Bueno',     'style' => 'background:#dbeafe;color:#1d4ed8'],
    'regular'   => ['label' => 'Regular',   'style' => 'background:#fef3c7;color:#92400e'],
    'malo'      => ['label' => 'Malo',      'style' => 'background:#fee2e2;color:#991b1b'],
    'baja'      => ['label' => 'Dado baja', 'style' => 'background:#f3f4f6;color:#6b7280'],
];
// Detectar si la migración 006 fue aplicada
$tieneFechaInicio = ActivoModel::columnas_existen(['fecha_inicio_uso']);

$VIDA_ESTADO = [
    'en_espera'  => ['style' => 'background:#e0f2fe;color:#0369a1', 'fill' => '#60a5fa', 'label' => 'En espera'],
    'nuevo'      => ['style' => 'background:#d1fae5;color:#065f46', 'fill' => '#059669', 'label' => 'Nuevo'],
    'medio'      => ['style' => 'background:#fef3c7;color:#92400e', 'fill' => '#d97706', 'label' => 'Mitad'],
    'critico'    => ['style' => 'background:#fed7aa;color:#9a3412', 'fill' => '#ea580c', 'label' => 'Critico'],
    'depreciado' => ['style' => 'background:#f3f4f6;color:#6b7280', 'fill' => '#d1d5db', 'label' => 'Depreciado'],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activos — <?= APP_NAME ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root { --brand:#e94f37; --dark:#111827; --g2:#374151; --g5:#6b7280; --g8:#d1d5db; --g9:#f3f4f6; --white:#fff; }
        body { font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; background:var(--g9); min-height:100vh; color:var(--dark); padding-bottom:48px; }
        .main { padding:16px 14px; max-width:1080px; margin:0 auto; }
        .alert { padding:12px 14px; border-radius:10px; font-size:14px; margin-bottom:14px; line-height:1.4; }
        .alert-ok  { background:#d1fae5; color:#065f46; border:1px solid #6ee7b7; }
        .alert-err { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; }
        /* Stats */
        .stats { display:grid; grid-template-columns:repeat(6,1fr); gap:10px; margin-bottom:16px; }
        @media(max-width:780px){ .stats { grid-template-columns:repeat(3,1fr); } }
        @media(max-width:480px){ .stats { grid-template-columns:1fr 1fr; } }
        .stat { background:var(--white); border-radius:14px; padding:13px 14px; box-shadow:0 1px 4px rgba(0,0,0,.06); }
        .stat-n { font-size:18px; font-weight:800; }
        .stat-l { font-size:10px; color:var(--g5); text-transform:uppercase; letter-spacing:.4px; margin-top:2px; }
        .stat.warn .stat-n { color:var(--brand); }
        /* Banner depreciación */
        .dep-banner { background:var(--dark); color:var(--white); border-radius:14px; padding:14px 18px; margin-bottom:16px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; }
        .dep-item { text-align:center; }
        .dep-val { font-size:20px; font-weight:800; color:#fcd34d; }
        .dep-lbl { font-size:10px; color:#9ca3af; text-transform:uppercase; letter-spacing:.4px; margin-top:2px; }
        /* Controles */
        .ctrl-row { display:flex; gap:8px; align-items:center; margin-bottom:12px; flex-wrap:wrap; }
        .ctrl-lbl { font-size:11px; color:var(--g5); font-weight:700; text-transform:uppercase; letter-spacing:.5px; }
        .btn-ord { padding:6px 12px; border:2px solid var(--g8); background:var(--white); border-radius:8px; font-size:12px; font-weight:700; cursor:pointer; color:var(--g5); text-decoration:none; transition:.15s; }
        .btn-ord:hover,.btn-ord.act { border-color:var(--brand); color:var(--brand); }
        .btn-nuevo { padding:9px 18px; background:var(--brand); color:var(--white); border:none; border-radius:10px; font-size:13px; font-weight:700; cursor:pointer; margin-left:auto; }
        /* Tabla */
        .card { background:var(--white); border-radius:14px; box-shadow:0 1px 4px rgba(0,0,0,.06); overflow:hidden; margin-bottom:16px; overflow-x:auto; }
        table { width:100%; border-collapse:collapse; min-width:800px; }
        th { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; color:var(--g5); padding:9px 12px; background:var(--g9); border-bottom:1px solid var(--g8); text-align:left; white-space:nowrap; }
        td { padding:10px 12px; border-bottom:1px solid var(--g9); font-size:12px; vertical-align:middle; }
        tr:last-child td { border-bottom:none; }
        /* Foto thumbnail */
        /* image-orientation corrige la rotación EXIF en navegadores modernos */
        .thumb { width:44px; height:44px; object-fit:cover; border-radius:8px; cursor:pointer; border:2px solid var(--g8); image-orientation:from-image; }
        .no-foto { width:44px; height:44px; background:var(--g9); border-radius:8px; border:2px dashed var(--g8); display:flex; align-items:center; justify-content:center; font-size:18px; cursor:pointer; }
        /* Barra vida */
        .vida-wrap { display:flex; align-items:center; gap:5px; }
        .vida-bar  { width:50px; height:5px; background:var(--g8); border-radius:3px; overflow:hidden; flex-shrink:0; }
        .vida-fill { height:100%; border-radius:3px; }
        .badge { font-size:9px; font-weight:700; padding:2px 7px; border-radius:20px; white-space:nowrap; display:inline-block; }
        /* Botones acción — dentro de la celda Activo */
        .act-btns { display:flex; gap:4px; flex-wrap:wrap; align-items:center; margin-top:6px; }
        .btn-ic { padding:5px 8px; border:none; border-radius:7px; font-size:11px; font-weight:700; cursor:pointer; white-space:nowrap; }

        /* ── Ordenamiento de columnas ─────────────────────────────────── */
        th.sortable {
            cursor: pointer;
            user-select: none;
            white-space: nowrap;
        }
        th.sortable:hover { background: #e9ecf0; }
        th.sortable .sort-icon {
            display: inline-block;
            margin-left: 4px;
            color: #9ca3af;
            font-size: 10px;
            font-style: normal;
        }
        th.sort-asc  .sort-icon { color: var(--brand, #e94f37); }
        th.sort-desc .sort-icon { color: var(--brand, #e94f37); }
        th.sort-asc  .sort-icon::after { content: '↑'; }
        th.sort-desc .sort-icon::after { content: '↓'; }
        th.sortable:not(.sort-asc):not(.sort-desc) .sort-icon::after { content: '⇅'; }
        .btn-edit  { background:var(--g9); color:var(--g2); border:1px solid var(--g8); }
        .btn-dup   { background:#e0f2fe; color:#0369a1; }
        .btn-photo { background:#f0fdf4; color:#16a34a; }
        .btn-baja  { background:#fef3c7; color:#92400e; }
        .btn-act   { background:#d1fae5; color:#065f46; }
        /* Formulario */
        .form-card { background:var(--white); border-radius:14px; padding:20px; box-shadow:0 1px 4px rgba(0,0,0,.06); }
        .form-title { font-size:15px; font-weight:800; margin-bottom:4px; }
        .form-sub   { font-size:12px; color:var(--g5); margin-bottom:16px; }
        .form-section { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--g5); margin:14px 0 8px; border-top:1px solid var(--g8); padding-top:12px; }
        .form-grid  { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
        @media(max-width:520px){ .form-grid { grid-template-columns:1fr; } }
        .form-grid-3{ grid-template-columns:1fr 1fr 1fr; }
        @media(max-width:620px){ .form-grid-3 { grid-template-columns:1fr 1fr; } }
        .fg { display:flex; flex-direction:column; gap:4px; }
        .fg label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--g5); }
        .fg input, .fg select, .fg textarea { padding:9px 11px; border:2px solid var(--g8); border-radius:9px; font-size:14px; color:var(--dark); outline:none; width:100%; background:var(--white); -webkit-appearance:none; }
        .fg input:focus,.fg select:focus,.fg textarea:focus { border-color:var(--brand); }
        .fg textarea { resize:vertical; min-height:60px; }
        .fg .hint { font-size:10px; color:var(--g5); margin-top:2px; }
        .btn-submit { padding:11px 24px; background:var(--brand); color:var(--white); border:none; border-radius:10px; font-size:14px; font-weight:700; cursor:pointer; }
        .total-calc { font-size:12px; color:var(--brand); font-weight:700; margin-top:4px; }
        /* Modal editar */
        .overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.55); z-index:60; align-items:center; justify-content:center; padding:16px; }
        .overlay.on { display:flex; }
        .modal { background:var(--white); border-radius:16px; padding:22px; width:100%; max-width:640px; max-height:92vh; overflow-y:auto; }
        .modal-hdr { font-size:16px; font-weight:800; margin-bottom:16px; display:flex; justify-content:space-between; }
        .btn-cls { background:var(--g9); border:none; color:var(--g5); width:30px; height:30px; border-radius:50%; cursor:pointer; font-size:17px; }
        /* Modal foto */
        .foto-modal img { max-width:100%; border-radius:10px; }
        /* Toast */
        .toast { position:fixed; bottom:22px; left:50%; transform:translateX(-50%) translateY(20px); padding:10px 20px; border-radius:24px; font-size:14px; font-weight:600; opacity:0; transition:.25s; z-index:99; pointer-events:none; max-width:92vw; }
        .toast.on { opacity:1; transform:translateX(-50%) translateY(0); }
        .toast-ok  { background:#065f46; color:#d1fae5; }
        .toast-err { background:#991b1b; color:#fee2e2; }

        /* ════════════════════════════════════════════════════════════════
           RESPONSIVE — ACTIVOS
           Breakpoints: xs<480 | sm480-639 | md640-1023 | lg1024-1279 |
                        xl1280-1599 | 2xl≥1600 | tv≥1920
           Columnas tabla (cuando $v5 activo, hasta 10):
             1=Foto  2=Activo  3=Categoría  4=Serial  5=Unidades
             6=Precio/u  7=Total  8=Dep/día  9=Vida útil  10=Estado
           ════════════════════════════════════════════════════════════════ */

        /* — xs: móvil vertical < 480px ─────────────────────────────────
           Ocultar: Foto(1), Categoría(3), Serial(4), Precio/u(6), Dep/día(8)
           Mantener: Activo(2), Unidades(5), Total(7), Vida útil(9), Estado(10)
           Stats: 2 columnas; act-bar apilada; modal bottom-sheet             */
        @media (max-width:479px) {
            /* Stats grid 2 columnas */
            .stats { grid-template-columns: repeat(2,1fr) !important; gap:8px !important; }
            .stats .card { padding:10px 12px !important; }
            .stats .kpi-val { font-size:clamp(16px,5vw,20px) !important; }

            /* Ocultar columnas no esenciales */
            .tbl-activos th:nth-child(1), .tbl-activos td:nth-child(1), /* Foto */
            .tbl-activos th:nth-child(3), .tbl-activos td:nth-child(3), /* Categoría */
            .tbl-activos th:nth-child(4), .tbl-activos td:nth-child(4), /* Serial */
            .tbl-activos th:nth-child(6), .tbl-activos td:nth-child(6), /* Precio/u */
            .tbl-activos th:nth-child(8), .tbl-activos td:nth-child(8)  /* Dep/día */
            { display:none !important; }

            /* Tabla con scroll horizontal mínimo */
            .tbl-wrap { overflow-x:auto; -webkit-overflow-scrolling:touch; }
            .tbl-activos { min-width:320px; }

            /* Botones de acción apilados verticalmente */
            .act-bar { flex-direction:column; align-items:stretch; }
            .act-bar .btn-primary, .act-bar .btn-sec {
                width:100% !important; min-height:44px; justify-content:center;
            }

            /* Formulario 1 columna */
            .form-grid, .form-grid-3 { grid-template-columns:1fr !important; }

            /* Modal bottom-sheet */
            .overlay { align-items:flex-end !important; }
            .modal {
                border-radius:16px 16px 0 0 !important;
                max-height:90vh !important;
                width:100% !important;
                max-width:100% !important;
            }
        }

        /* — sm: móvil landscape 480-639px ──────────────────────────────
           Ocultar: Foto(1), Serial(4), Dep/día(8)                          */
        @media (min-width:480px) and (max-width:639px) {
            .tbl-activos th:nth-child(1), .tbl-activos td:nth-child(1), /* Foto */
            .tbl-activos th:nth-child(4), .tbl-activos td:nth-child(4), /* Serial */
            .tbl-activos th:nth-child(8), .tbl-activos td:nth-child(8)  /* Dep/día */
            { display:none !important; }

            .tbl-wrap { overflow-x:auto; -webkit-overflow-scrolling:touch; }
            .tbl-activos { min-width:480px; }
            .stats { grid-template-columns: repeat(3,1fr) !important; }
        }

        /* — md: tablet 640-1023px ───────────────────────────────────────
           Ocultar solo: Foto(1), Dep/día(8)                                */
        @media (min-width:640px) and (max-width:1023px) {
            .tbl-activos th:nth-child(1), .tbl-activos td:nth-child(1), /* Foto */
            .tbl-activos th:nth-child(8), .tbl-activos td:nth-child(8)  /* Dep/día */
            { display:none !important; }

            .tbl-wrap { overflow-x:auto; -webkit-overflow-scrolling:touch; }
            .tbl-activos { min-width:640px; }

            /* Modal centrado en tablet */
            .overlay { align-items:center !important; }
            .modal { border-radius:16px !important; max-width:560px !important; }
        }

        /* — lg+: escritorio ≥1024px — mostrar todas las columnas ─────── */
        @media (min-width:1024px) {
            .tbl-activos th, .tbl-activos td { display:table-cell !important; }
        }

        /* — 2xl: pantalla grande ≥1600px ───────────────────────────────── */
        @media (min-width:1600px) {
            .stats { gap:16px !important; }
            .stats .card { padding:20px 24px !important; }
            .stats .kpi-val { font-size:clamp(22px,2vw,28px) !important; }
            .tbl-activos th, .tbl-activos td { padding:12px 14px !important; font-size:14px !important; }
            .modal { max-width:720px !important; padding:30px !important; }
        }

        /* — tv: televisor ≥1920px ────────────────────────────────────── */
        @media (min-width:1920px) {
            .stats { grid-template-columns:repeat(6,1fr) !important; gap:20px !important; }
            .stats .kpi-val { font-size:clamp(26px,2vw,34px) !important; }
            .tbl-activos th, .tbl-activos td { padding:14px 18px !important; font-size:15px !important; }
            .modal { max-width:860px !important; }
        }
    </style>
</head>
<body>

<?php include __DIR__ . '/../app/views/nav.php'; ?>

<main class="main">

    <?php if ($msg_ok): ?><div class="alert alert-ok"><?= htmlspecialchars($msg_ok) ?></div><?php endif; ?>
    <?php if ($msg_err): ?><div class="alert alert-err"><?= htmlspecialchars($msg_err) ?></div><?php endif; ?>

    <!-- Stats -->
    <div class="stats">
        <div class="stat">
            <div class="stat-n"><?= (int)($resumen['total'] ?? 0) ?></div>
            <div class="stat-l">Activos activos</div>
        </div>
        <div class="stat">
            <div class="stat-n">$<?= number_format($inv_total, 0, ',', '.') ?></div>
            <div class="stat-l">Inversión total</div>
        </div>
        <div class="stat">
            <div class="stat-n">$<?= number_format($val_libros, 0, ',', '.') ?></div>
            <div class="stat-l">Valor en libros</div>
        </div>
        <div class="stat">
            <div class="stat-n" style="color:var(--brand)">$<?= number_format($dep_dia, 2, ',', '.') ?></div>
            <div class="stat-l">Dep. diaria</div>
        </div>
        <?php if ($v5): ?>
        <div class="stat <?= $mal_estado > 0 ? 'warn' : '' ?>">
            <div class="stat-n"><?= $mal_estado ?></div>
            <div class="stat-l">En mal estado</div>
        </div>
        <div class="stat <?= $garan_venc > 0 ? 'warn' : '' ?>">
            <div class="stat-n"><?= $garan_venc ?></div>
            <div class="stat-l">Garantía vencida</div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Banner depreciación operativa -->
    <div class="dep-banner">
        <div><strong style="font-size:13px">Costo operativo por depreciación</strong><br>
        <span style="font-size:11px;color:#9ca3af">Impacta el costo de cada producto</span></div>
        <div class="dep-item"><div class="dep-val">$<?= number_format($dep_dia,2,',','.') ?></div><div class="dep-lbl">/ día</div></div>
        <div class="dep-item"><div class="dep-val">$<?= number_format($dep_dia*30.41666,0,',','.') ?></div><div class="dep-lbl">/ mes</div></div>
        <div class="dep-item"><div class="dep-val">$<?= number_format($dep_dia*365,0,',','.') ?></div><div class="dep-lbl">/ año</div></div>
    </div>

    <!-- Controles -->
    <div class="ctrl-row">
        <span class="ctrl-lbl">Ordenar:</span>
        <a href="?orden=fecha"    class="btn-ord <?= $orden==='fecha'    ?'act':'' ?>">Fecha</a>
        <a href="?orden=nombre"   class="btn-ord <?= $orden==='nombre'   ?'act':'' ?>">Nombre</a>
        <?php if ($v5): ?>
        <a href="?orden=categoria" class="btn-ord <?= $orden==='categoria'?'act':'' ?>">Categoría</a>
        <a href="?orden=lugar"    class="btn-ord <?= $orden==='lugar'    ?'act':'' ?>">Lugar</a>
        <?php endif; ?>
        <?php if (permiso_tiene('activos','editar_existentes')): ?>
        <button class="btn-nuevo" onclick="abrirModal('modal-nuevo')">+ Nuevo activo</button>
        <?php endif; ?>
    </div>

    <!-- Tabla de activos -->
    <div class="card">
        <table class="activos-table">
            <thead>
                <tr>
                    <?php if ($v5): ?><th style="width:54px">Foto</th><?php endif; ?>
                    <th class="sortable" data-col="nombre" onclick="sortActivos('nombre')" style="min-width:186px">
                        Activo <i class="sort-icon"></i>
                    </th>
                    <?php if ($v5): ?>
                    <th class="sortable" data-col="cat" onclick="sortActivos('cat')">Categoría <i class="sort-icon"></i></th>
                    <th class="sortable" data-col="serial" onclick="sortActivos('serial')">Serial <i class="sort-icon"></i></th>
                    <th class="sortable" data-col="uni" onclick="sortActivos('uni')" style="text-align:center">Unidades <i class="sort-icon"></i></th>
                    <th class="sortable" data-col="precio" onclick="sortActivos('precio')" style="text-align:right">Precio/u <i class="sort-icon"></i></th>
                    <?php endif; ?>
                    <th class="sortable" data-col="total" onclick="sortActivos('total')" style="text-align:right">Total <i class="sort-icon"></i></th>
                    <th style="text-align:right">Dep/día</th>
                    <th>Vida útil</th>
                    <th class="sortable" data-col="estado" onclick="sortActivos('estado')">Estado <i class="sort-icon"></i></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($activos)): ?>
                <tr><td colspan="14" style="text-align:center;padding:40px;color:var(--g5)">
                    Sin activos registrados. Usa el botón "+ Nuevo activo".
                </td></tr>
                <?php endif; ?>
                <?php foreach ($activos as $a):
                    $est   = $a['estado_vida'] ?? 'nuevo';
                    $vstyle = $VIDA_ESTADO[$est] ?? $VIDA_ESTADO['nuevo'];
                    $pct    = (int)($a['pct_depreciado'] ?? 0);
                    $efis   = $a['estado_fisico'] ?? 'bueno';
                    $eStyle = ($ESTADOS_FISICO[$efis] ?? $ESTADOS_FISICO['bueno'])['style'];
                    $eLabel = ($ESTADOS_FISICO[$efis] ?? $ESTADOS_FISICO['bueno'])['label'];
                    $enGar  = $v5 && !empty($a['garantia_hasta']) && $a['garantia_hasta'] < date('Y-m-d');
                ?>
                <tr class="activo-row"
                    data-nombre="<?= htmlspecialchars(strtolower($a['nombre'])) ?>"
                    data-cat="<?= htmlspecialchars(strtolower($CATEGORIAS[$a['categoria_activo'] ?? 'otro'] ?? '')) ?>"
                    data-serial="<?= htmlspecialchars(strtolower($a['serial'] ?? '')) ?>"
                    data-uni="<?= (int)($a['numero_unidades'] ?? 1) ?>"
                    data-precio="<?= (float)($a['precio_unitario'] ?? 0) ?>"
                    data-total="<?= (float)$a['costo_inicial'] ?>"
                    data-estado="<?= htmlspecialchars($est) ?>"
                    <?= !$a['activo'] ? 'style="opacity:.45"' : '' ?>>
                    <?php if ($v5): ?>
                    <td>
                        <?php if (!empty($a['foto_url'])): ?>
                        <img src="<?= APP_BASE ?>/<?= htmlspecialchars($a['foto_url']) ?>"
                             class="thumb" onclick="verFoto('<?= APP_BASE ?>/<?= htmlspecialchars($a['foto_url']) ?>', <?= htmlspecialchars(json_encode($a['nombre'])) ?>)"
                             alt="<?= htmlspecialchars($a['nombre']) ?>">
                        <?php elseif (permiso_tiene('activos','editar_existentes')): ?>
                        <div class="no-foto" onclick="subirFoto(<?= $a['id'] ?>)" title="Subir foto">&#128247;</div>
                        <?php else: ?>
                        <div class="no-foto" style="cursor:default">&#128247;</div>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                    <td style="min-width:186px">
                        <strong><?= htmlspecialchars($a['nombre']) ?></strong>
                        <?php if (!empty($a['descripcion'])): ?>
                        <br><small style="color:var(--g5)"><?= htmlspecialchars($a['descripcion']) ?></small>
                        <?php endif; ?>
                        <!-- Fechas compra / uso -->
                        <br><small style="color:var(--g5)">
                            Compra: <?= date('d/m/Y', strtotime($a['fecha_adquisicion'])) ?>
                        </small>
                        <?php if ($tieneFechaInicio && !empty($a['fecha_inicio_uso'])): ?>
                        <br><small style="color:#0369a1">
                            Uso: <?= date('d/m/Y', strtotime($a['fecha_inicio_uso'])) ?>
                        </small>
                        <?php elseif ($tieneFechaInicio): ?>
                        <br><small style="color:#d97706">Sin fecha inicio uso</small>
                        <?php endif; ?>
                        <!-- Fin de vida útil -->
                        <?php if (!empty($a['fecha_fin_util'])): ?>
                        <br><small style="color:<?= $est === 'depreciado' ? 'var(--brand)' : 'var(--g5)' ?>">
                            Fin vida: <?= date('d/m/Y', strtotime($a['fecha_fin_util'])) ?>
                        </small>
                        <?php endif; ?>
                        <!-- Garantía: siempre visible debajo de Fin de vida -->
                        <?php
                        $garFecha = $a['garantia_hasta'] ?? null;
                        $enGarCol = $garFecha && $garFecha < date('Y-m-d');
                        ?>
                        <?php if ($garFecha): ?>
                        <br><small style="color:<?= $enGarCol ? 'var(--brand)' : '#16a34a' ?>">
                            <?= $enGarCol ? '&#9888;' : '&#10003;' ?>&nbsp;Garantía:
                            <?= date('d/m/Y', strtotime($garFecha)) ?>
                            <?= $enGarCol ? '(vencida)' : '' ?>
                        </small>
                        <?php else: ?>
                        <br><small style="color:var(--g8)">Sin fecha de garantía</small>
                        <?php endif; ?>
                        <!-- Lugar de compra / proveedor -->
                        <?php if (!empty($a['proveedor_nombre'] ?? '')): ?>
                        <br><small style="color:#0369a1">&#127968; <?= htmlspecialchars($a['proveedor_nombre']) ?></small>
                        <?php elseif (!empty($a['lugar_compra'])): ?>
                        <br><small style="color:var(--g5)">&#128205; <?= htmlspecialchars($a['lugar_compra']) ?></small>
                        <?php endif; ?>
                        <!-- ── Acciones dentro de la celda Activo ──────────── -->
                        <?php if (permiso_tiene('activos','editar_existentes')): ?>
                        <div class="act-btns">
                            <button class="btn-ic ic btn-edit" title="Editar"
                                onclick="abrirEditar(<?= htmlspecialchars(json_encode($a)) ?>)">
                                <?= IC_EDIT ?>
                            </button>
                            <button class="btn-ic ic btn-dup" title="Duplicar"
                                onclick="duplicar(<?= $a['id'] ?>, <?= htmlspecialchars(json_encode($a['nombre'])) ?>)">
                                <?= IC_COPY ?>
                            </button>
                            <?php if ($v5): ?>
                            <button class="btn-ic ic btn-photo" title="Subir foto" onclick="subirFoto(<?= $a['id'] ?>)">
                                <?= IC_CAMERA ?>
                            </button>
                            <?php endif; ?>
                            <?php if (permiso_tiene('activos','admin_total')): ?>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                <input type="hidden" name="accion" value="toggle">
                                <input type="hidden" name="id" value="<?= $a['id'] ?>">
                                <button type="submit" class="btn-ic ic <?= $a['activo'] ? 'btn-baja' : 'btn-act' ?>"
                                        title="<?= $a['activo'] ? 'Dar de baja' : 'Activar' ?>">
                                    <?= $a['activo'] ? IC_PAUSE : IC_PLAY ?>
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <?php if ($v5): ?>
                    <td><small><?= htmlspecialchars($CATEGORIAS[$a['categoria_activo'] ?? 'otro'] ?? '—') ?></small></td>
                    <td style="font-family:monospace;font-size:11px"><?= htmlspecialchars($a['serial'] ?? '—') ?></td>
                    <td style="text-align:center"><?= (int)($a['numero_unidades'] ?? 1) ?></td>
                    <td style="text-align:right">
                        <?= !empty($a['precio_unitario']) ? '$'.number_format($a['precio_unitario'],0,',','.') : '—' ?>
                    </td>
                    <?php endif; ?>
                    <td style="text-align:right;font-weight:700">$<?= number_format($a['costo_inicial'],0,',','.') ?></td>
                    <?php
                    // Activo depreciado = ya cumplió su vida útil → dep/día = $0
                    $depDiaActivo = ($est === 'depreciado' || $est === 'en_espera')
                        ? 0
                        : (float)($a['depreciacion_diaria'] ?? 0);
                    ?>
                    <td style="text-align:right;color:<?= $depDiaActivo > 0 ? 'var(--brand)' : 'var(--g5)' ?>">
                        <?= $depDiaActivo > 0 ? '$'.number_format($depDiaActivo,2,',','.') : '—' ?>
                    </td>
                    <td>
                        <div class="vida-wrap">
                            <div class="vida-bar">
                                <div class="vida-fill" style="width:<?= $pct ?>%;background:<?= $vstyle['fill'] ?>"></div>
                            </div>
                            <span class="badge" style="<?= $vstyle['style'] ?>">
                                <?= $a['meses_restantes'] ?? 0 ?>m
                            </span>
                        </div>
                    </td>
                    <!-- Estado: columna independiente restaurada -->
                    <td>
                        <span class="badge" style="<?= $eStyle ?>"><?= $eLabel ?></span>
                        <?php if ($est === 'depreciado'): ?>
                        <br><span class="badge" style="background:#fef3c7;color:#92400e;margin-top:3px">Dep. total</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!empty($activos)): ?>
                <tr style="background:var(--g9);font-weight:800">
                    <td <?= $v5 ? 'colspan="6"' : 'colspan="1"' ?>>TOTALES (solo activos en depreciación)</td>
                    <td style="text-align:right">$<?= number_format($inv_total,0,',','.') ?></td>
                    <td style="text-align:right;color:var(--brand)">$<?= number_format($dep_dia,2,',','.') ?>/día</td>
                    <td colspan="2"></td><!-- Vida útil + Estado -->
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>


</main>

<!-- ========================================================
     MODAL NUEVO ACTIVO — todos los campos siempre visibles
     ======================================================== -->
<?php if (permiso_tiene('activos','editar_existentes')): ?>
<div class="overlay" id="modal-nuevo" onclick="if(event.target===this)cerrar('modal-nuevo')">
  <div class="modal" style="max-width:700px">
    <div class="modal-hdr">Nuevo Activo
      <button class="btn-cls" onclick="cerrar('modal-nuevo')">&#x2715;</button>
    </div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
      <input type="hidden" name="accion" value="crear">
      <p class="form-section" style="margin-top:0;border-top:none;padding-top:0">Identificación</p>
      <div class="form-grid">
        <div class="fg"><label>Nombre *</label>
          <input type="text" name="nombre" placeholder="Ej: Licuadora industrial" required></div>
        <div class="fg"><label>Descripción / Modelo</label>
          <input type="text" name="descripcion" placeholder="Marca, modelo..."></div>
        <div class="fg"><label>Serial / Código</label>
          <input type="text" name="serial" placeholder="Ej: SN-LIC-001"></div>
        <div class="fg"><label>Categoría</label>
          <select name="categoria_activo">
            <?php foreach ($CATEGORIAS as $k => $lbl): ?>
            <option value="<?= $k ?>"><?= $lbl ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <p class="form-section">Precio y unidades</p>
      <div class="form-grid form-grid-3">
        <div class="fg"><label>Precio por unidad ($)</label>
          <input type="number" name="precio_unitario" id="n-pu" placeholder="0" min="0" step="1"
                 oninput="calcTotal('n')">
          <span class="hint">Si lo ingresas, el total se calcula solo</span></div>
        <div class="fg"><label>Número de unidades</label>
          <input type="number" name="numero_unidades" id="n-nu" value="1" min="1" max="999"
                 oninput="calcTotal('n')"></div>
        <div class="fg"><label>Costo total ($) *</label>
          <input type="number" name="costo_inicial" id="n-total" placeholder="0" min="0" step="1">
          <span class="hint total-calc" id="n-calc">= precio/u x unidades</span></div>
      </div>
      <p class="form-section">Fechas y lugar</p>
      <div class="form-grid">
        <div class="fg"><label>Fecha de Compra *</label>
          <input type="date" name="fecha_adquisicion" value="<?= date('Y-m-d') ?>" required>
          <span class="hint">Cuando se pagó / recibió el equipo</span></div>
        <div class="fg"><label>Fecha Inicio de Uso</label>
          <input type="date" name="fecha_inicio_uso" value="<?= date('Y-m-d') ?>">
          <span class="hint">Desde aquí corre la depreciación</span></div>
        <div class="fg"><label>Garantía hasta</label>
          <input type="date" name="garantia_hasta"></div>
        <div class="fg"><label>Lugar de Compra</label>
          <input type="text" name="lugar_compra" placeholder="Alkosto, MercadoLibre, Plaza..."></div>
        <div class="fg"><label>Proveedor</label>
          <select name="proveedor_id">
            <option value="">— Sin vincular —</option>
            <?php foreach ($proveedores_list as $pv): ?>
            <option value="<?= $pv['id'] ?>"><?= htmlspecialchars($pv['nombre']) ?></option>
            <?php endforeach; ?>
          </select></div>
      </div>
      <p class="form-section">Depreciación y estado</p>
      <div class="form-grid">
        <div class="fg"><label>Vida Útil (meses)</label>
          <input type="number" name="vida_util_meses" value="12" min="1" max="240">
          <span class="hint">12 = 1 año, 24 = 2 años</span></div>
        <div class="fg"><label>Estado Físico</label>
          <select name="estado_fisico">
            <?php foreach ($ESTADOS_FISICO as $k => $ef): ?>
            <option value="<?= $k ?>"><?= $ef['label'] ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="fg"><label>Responsable</label>
          <input type="text" name="responsable" placeholder="Persona o área a cargo"></div>
      </div>
      <div class="fg" style="margin-top:10px"><label>Notas / Observaciones</label>
        <textarea name="notas" style="min-height:60px"
          placeholder="Mantenimiento, ubicación física, incidencias..."></textarea></div>
      <button type="submit" class="btn-submit" style="width:100%;margin-top:14px">
        Guardar Activo
      </button>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- ========================================================
     MODAL EDITAR ACTIVO — todos los campos siempre visibles
     ======================================================== -->
<div class="overlay" id="modal-editar" onclick="if(event.target===this)cerrar('modal-editar')">
  <div class="modal" style="max-width:700px">
    <div class="modal-hdr">
      <span id="e-titulo">Editar Activo</span>
      <button class="btn-cls" onclick="cerrar('modal-editar')">&#x2715;</button>
    </div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
      <input type="hidden" name="accion" value="editar">
      <input type="hidden" name="id" id="e-id">

      <!-- Foto actual -->
      <div style="margin-bottom:14px">
        <p class="form-section" style="margin-top:0;border-top:none;padding-top:0;margin-bottom:8px">
          Foto del activo
        </p>
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
          <img id="e-foto-img" src="" alt=""
               style="width:72px;height:72px;object-fit:cover;border-radius:10px;
                      border:2px solid var(--g8);image-orientation:from-image;display:none">
          <div id="e-foto-ph"
               style="width:72px;height:72px;background:var(--g9);border-radius:10px;
                      border:2px dashed var(--g8);display:flex;align-items:center;
                      justify-content:center;font-size:26px">&#128247;</div>
          <div>
            <button type="button" class="btn-ic btn-photo"
                    onclick="subirFoto(document.getElementById('e-id').value)"
                    style="font-size:13px;padding:8px 16px">
              Subir / Cambiar foto
            </button>
            <p style="font-size:11px;color:var(--g5);margin-top:4px">
              JPG, PNG o WebP — funciona desde galería o cámara del móvil
            </p>
          </div>
        </div>
      </div>

      <p class="form-section">Identificación</p>
      <div class="form-grid">
        <div class="fg"><label>Nombre *</label><input name="nombre" id="e-nom" required></div>
        <div class="fg"><label>Descripción / Modelo</label><input name="descripcion" id="e-desc"></div>
        <div class="fg"><label>Serial / Código</label>
          <input name="serial" id="e-serial" placeholder="Ej: SN-LIC-001"></div>
        <div class="fg"><label>Categoría</label>
          <select name="categoria_activo" id="e-cat">
            <?php foreach ($CATEGORIAS as $k => $lbl): ?>
            <option value="<?= $k ?>"><?= $lbl ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <p class="form-section">Precio y unidades</p>
      <div class="form-grid form-grid-3">
        <div class="fg"><label>Precio por unidad ($)</label>
          <input type="number" name="precio_unitario" id="e-pu" min="0" step="1"
                 oninput="calcTotal('e')"></div>
        <div class="fg"><label>Número de unidades</label>
          <input type="number" name="numero_unidades" id="e-nu" value="1" min="1"
                 oninput="calcTotal('e')"></div>
        <div class="fg"><label>Costo total ($) *</label>
          <input type="number" name="costo_inicial" id="e-total" min="0" step="1" required>
          <span class="hint total-calc" id="e-calc"></span></div>
      </div>

      <p class="form-section">Fechas y lugar</p>
      <div class="form-grid">
        <div class="fg"><label>Fecha de Compra *</label>
          <input type="date" name="fecha_adquisicion" id="e-fecha" required>
          <span class="hint">Cuando se pagó / recibió</span></div>
        <div class="fg"><label>Fecha Inicio de Uso</label>
          <input type="date" name="fecha_inicio_uso" id="e-inicio">
          <span class="hint">Desde aquí corre la depreciación</span></div>
        <div class="fg"><label>Garantía hasta</label>
          <input type="date" name="garantia_hasta" id="e-garantia"></div>
        <div class="fg"><label>Lugar de Compra</label>
          <input name="lugar_compra" id="e-lugar"></div>
        <div class="fg"><label>Proveedor</label>
          <select name="proveedor_id" id="e-proveedor">
            <option value="">— Sin vincular —</option>
            <?php foreach ($proveedores_list as $pv): ?>
            <option value="<?= $pv['id'] ?>"><?= htmlspecialchars($pv['nombre']) ?></option>
            <?php endforeach; ?>
          </select></div>
      </div>

      <p class="form-section">Depreciación y estado</p>
      <div class="form-grid">
        <div class="fg"><label>Vida Útil (meses)</label>
          <input type="number" name="vida_util_meses" id="e-vida" min="1" max="240"></div>
        <div class="fg"><label>Estado Físico</label>
          <select name="estado_fisico" id="e-estado">
            <?php foreach ($ESTADOS_FISICO as $k => $ef): ?>
            <option value="<?= $k ?>"><?= $ef['label'] ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="fg"><label>Responsable</label>
          <input name="responsable" id="e-resp"></div>
      </div>
      <div class="fg" style="margin-top:10px"><label>Notas / Observaciones</label>
        <textarea name="notas" id="e-notas" style="min-height:60px"></textarea></div>

      <button type="submit" class="btn-submit" style="width:100%;margin-top:14px">
        Guardar Cambios
      </button>
    </form>
  </div>
</div>

<!-- Modal foto ampliada -->
<div class="overlay" id="modal-foto" onclick="cerrar('modal-foto')">
  <div class="modal" style="max-width:500px;text-align:center" onclick="event.stopPropagation()">
    <div class="modal-hdr"><span id="foto-titulo">Foto</span>
      <button class="btn-cls" onclick="cerrar('modal-foto')">&#x2715;</button></div>
    <img id="foto-grande" src="" alt=""
         style="max-width:100%;border-radius:10px;image-orientation:from-image">
  </div>
</div>

<input type="file" id="input-foto" accept="image/*" style="display:none" onchange="enviarFoto()">
<input type="hidden" id="foto-activo-id" value="">
<input type="hidden" id="foto-csrf" value="<?= htmlspecialchars(csrf_token()) ?>">

<form method="POST" id="form-dup" style="display:none">
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
  <input type="hidden" name="accion" value="duplicar">
  <input type="hidden" name="id" id="dup-id">
</form>

<div class="toast" id="toast"></div>

<script>
function calcTotal(p) {
    var pu  = parseFloat(document.getElementById(p+'-pu')  ? document.getElementById(p+'-pu').value  : 0) || 0;
    var nu  = parseInt( document.getElementById(p+'-nu')  ? document.getElementById(p+'-nu').value  : 1, 10) || 1;
    var tot = document.getElementById(p+'-total');
    var lbl = document.getElementById(p+'-calc');
    if (!tot) return;
    if (pu > 0) { var t = pu*nu; tot.value = t; if (lbl) lbl.textContent = '= $'+Math.round(t).toLocaleString('es-CO'); }
}
function abrirModal(id) { var el=document.getElementById(id); if(el) el.classList.add('on'); }
function cerrar(id)     { var el=document.getElementById(id); if(el) el.classList.remove('on'); }
document.addEventListener('keydown', function(e){
    if(e.key==='Escape') document.querySelectorAll('.overlay.on').forEach(function(o){o.classList.remove('on');});
});
function abrirEditar(a) {
    document.getElementById('e-titulo').textContent = 'Editar: ' + a.nombre;
    function s(id){ return document.getElementById(id); }
    s('e-id').value     = a.id;
    s('e-nom').value    = a.nombre            || '';
    s('e-desc').value   = a.descripcion       || '';
    s('e-serial').value = a.serial            || '';
    s('e-total').value  = a.costo_inicial     || '';
    s('e-pu').value     = a.precio_unitario   || '';
    s('e-nu').value     = a.numero_unidades   || 1;
    s('e-fecha').value  = a.fecha_adquisicion || '';
    s('e-inicio').value = a.fecha_inicio_uso  || '';
    s('e-garantia').value=a.garantia_hasta    || '';
    s('e-lugar').value  = a.lugar_compra      || '';
    if(s('e-proveedor')) s('e-proveedor').value = a.proveedor_id ? String(a.proveedor_id) : '';
    s('e-vida').value   = a.vida_util_meses   || 12;
    s('e-resp').value   = a.responsable       || '';
    s('e-notas').value  = a.notas             || '';
    if(s('e-cat'))    s('e-cat').value    = a.categoria_activo || 'otro';
    if(s('e-estado')) s('e-estado').value = a.estado_fisico    || 'bueno';
    document.getElementById('foto-activo-id').value = a.id;
    if (a.foto_url) {
        s('e-foto-img').src           = '<?= APP_BASE ?>/' + a.foto_url;
        s('e-foto-img').style.display = 'block';
        s('e-foto-ph').style.display  = 'none';
    } else {
        s('e-foto-img').style.display = 'none';
        s('e-foto-ph').style.display  = 'flex';
    }
    if (parseFloat(a.precio_unitario) > 0) {
        var t = parseFloat(a.precio_unitario) * (parseInt(a.numero_unidades)||1);
        var lbl = s('e-calc'); if(lbl) lbl.textContent = '= $'+Math.round(t).toLocaleString('es-CO');
    }
    abrirModal('modal-editar');
}
function duplicar(id, nombre) {
    if (!confirm('Duplicar "'+nombre+'"?\nSe creara una copia sin foto ni serial.')) return;
    document.getElementById('dup-id').value = id;
    document.getElementById('form-dup').submit();
}
function subirFoto(activoId) {
    if (!activoId) { toast('Guarda el activo primero', 'err'); return; }
    document.getElementById('foto-activo-id').value = activoId;
    document.getElementById('input-foto').click();
}
async function comprimirImagen(file) {
    return new Promise(function(resolve) {
        var reader = new FileReader();
        reader.onerror = function(){ resolve(file); };
        reader.onload = function(e) {
            var img = new Image();
            img.onerror = function(){ resolve(file); };
            img.onload = function() {
                var w=img.naturalWidth, h=img.naturalHeight, maxPx=1400;
                if(w>maxPx||h>maxPx){if(w>=h){h=Math.round(h*maxPx/w);w=maxPx;}else{w=Math.round(w*maxPx/h);h=maxPx;}}
                var c=document.createElement('canvas'); c.width=w; c.height=h;
                c.getContext('2d').drawImage(img,0,0,w,h);
                c.toBlob(function(b){ resolve(b||file); },'image/jpeg',0.82);
            };
            img.src = e.target.result;
        };
        reader.readAsDataURL(file);
    });
}
async function enviarFoto() {
    var input=document.getElementById('input-foto');
    var file=input.files[0];
    var id=document.getElementById('foto-activo-id').value;
    var csrf=document.getElementById('foto-csrf').value;
    if(!file||!id) return;
    toast('Comprimiendo ('+(file.size/1048576).toFixed(1)+' MB)...','ok');
    var blob = await comprimirImagen(file);
    if(blob.size>10*1048576){toast('Imagen muy grande. Usa una de menor resolución.','err');input.value='';return;}
    toast('Subiendo ('+(blob.size/1048576).toFixed(1)+' MB)...','ok');
    var fd=new FormData();
    fd.append('csrf_token',csrf); fd.append('activo_id',id); fd.append('foto',blob,'foto.jpg');
    try {
        var r=await fetch('api/subir_foto.php',{method:'POST',body:fd});
        var d=await r.json();
        if(d.success){toast('Foto guardada','ok');setTimeout(function(){location.reload();},1000);}
        else toast(d.error||'Error al subir','err');
    } catch(ex){ toast('Error de conexión','err'); }
    input.value='';
}
function verFoto(url,nombre){
    document.getElementById('foto-grande').src=url;
    document.getElementById('foto-titulo').textContent=nombre;
    abrirModal('modal-foto');
}
var _tt;
function toast(msg,tipo){
    var el=document.getElementById('toast');
    el.textContent=msg; el.className='toast toast-'+tipo+' on';
    clearTimeout(_tt); _tt=setTimeout(function(){el.classList.remove('on');},3500);
}

/* ── Ordenamiento de la tabla de activos ─────────────────────────────────── */
var _sortCol = null;
var _sortDir = 1; // 1 = ascendente, -1 = descendente

function sortActivos(col) {
    // Alternar dirección si es la misma columna, reset a asc si es nueva
    if (_sortCol === col) {
        _sortDir *= -1;
    } else {
        _sortCol = col;
        _sortDir = 1;
    }

    // Actualizar indicadores visuales en los headers
    document.querySelectorAll('.activos-table th.sortable').forEach(function(th) {
        th.classList.remove('sort-asc', 'sort-desc');
    });
    var activeTh = document.querySelector('.activos-table th[data-col="' + col + '"]');
    if (activeTh) activeTh.classList.add(_sortDir === 1 ? 'sort-asc' : 'sort-desc');

    // Obtener filas de datos (clase activo-row, excluye totales y vacíos)
    var tbody  = document.querySelector('.activos-table tbody');
    var rows   = Array.from(tbody.querySelectorAll('tr.activo-row'));
    var totals = Array.from(tbody.querySelectorAll('tr:not(.activo-row)'));

    rows.sort(function(a, b) {
        var va = (a.dataset[col] || '').trim();
        var vb = (b.dataset[col] || '').trim();

        // Comparación numérica para columnas de cantidad/precio
        var na = parseFloat(va);
        var nb = parseFloat(vb);
        if (!isNaN(na) && !isNaN(nb)) {
            return (na - nb) * _sortDir;
        }
        // Comparación textual con localización para tildes y ñ
        return va.localeCompare(vb, 'es') * _sortDir;
    });

    // Reinsertar filas ordenadas; los totales van siempre al final
    rows.forEach(function(row) { tbody.appendChild(row); });
    totals.forEach(function(row) { tbody.appendChild(row); });
}
</script>
</body>
</html>
