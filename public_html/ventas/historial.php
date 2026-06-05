<?php
/**
 * ventas/historial.php — Historial de ventas con búsqueda, filtros y acciones.
 * Permite: ver ventas por período, buscar por cliente, filtrar por método/estado,
 * marcar como pagadas y anular ventas.
 */
require_once __DIR__ . '/../app/middleware/auth_check.php';

$nav_activo = 'ventas';
permiso_requerir('ventas', 'solo_ver');

// ── Filtros GET ───────────────────────────────────────────────────────────────
$hoy         = date('Y-m-d');
// Cuando se filtra por cliente, el rango por defecto es el historial completo del año
$filtro_cli  = isset($_GET['cliente_id']) ? (int)$_GET['cliente_id'] : 0;
$hoy_o_anio  = $filtro_cli > 0 ? date('Y-01-01') : $hoy;  // al filtrar por cliente, inicio del año
$fecha_desde = $_GET['desde']  ?? $hoy_o_anio;
$fecha_hasta = $_GET['hasta']  ?? $hoy;
$filtro_met  = $_GET['metodo'] ?? '';
$filtro_est  = $_GET['estado'] ?? '';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_desde)) $fecha_desde = $hoy;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_hasta)) $fecha_hasta = $hoy;
if ($fecha_desde > $fecha_hasta) $fecha_hasta = $fecha_desde;

// Info del cliente filtrado (para mostrar el nombre en el banner)
$cliente_filtrado = null;
if ($filtro_cli > 0) {
    $cRow = db()->prepare('SELECT id, nombre, apellido, saldo_fiado FROM clientes WHERE id = ? AND activo = 1');
    $cRow->execute([$filtro_cli]);
    $cliente_filtrado = $cRow->fetch() ?: null;
    if (!$cliente_filtrado) $filtro_cli = 0; // cliente inválido → ignorar filtro
}

// ── Query principal ───────────────────────────────────────────────────────────
$where  = 'WHERE DATE(v.fecha_venta) BETWEEN :desde AND :hasta';
$params = [':desde' => $fecha_desde, ':hasta' => $fecha_hasta];

$metodos_ok = ['efectivo','nequi','daviplata','bancolombia','fiado','obsequio'];
$estados_ok  = ['completada','anulada','pendiente_pago'];
if ($filtro_met && in_array($filtro_met, $metodos_ok, true)) {
    $where .= ' AND v.metodo_pago = :metodo';
    $params[':metodo'] = $filtro_met;
}
if ($filtro_est && in_array($filtro_est, $estados_ok, true)) {
    $where .= ' AND v.estado = :estado';
    $params[':estado'] = $filtro_est;
}
// Filtro por cliente: muestra solo las ventas de ese cliente
if ($filtro_cli > 0) {
    $where .= ' AND v.cliente_id = :cli';
    $params[':cli'] = $filtro_cli;
}

$stmt = db()->prepare(
    "SELECT v.id, v.fecha_venta, v.metodo_pago, v.total, v.estado, v.notas,
            v.fecha_pago, v.es_combo,
            IFNULL(c.nombre, 'Mostrador') AS cliente_nombre,
            u.nombre                       AS cajero_nombre,
            -- Usar nombre_snap si existe (migración 034); si no, usar nombre actual del producto
            GROUP_CONCAT(COALESCE(vd.nombre_snap, p.nombre) ORDER BY p.nombre SEPARATOR ', ') AS productos_lista
     FROM ventas v
     LEFT JOIN clientes c        ON c.id  = v.cliente_id
     LEFT JOIN usuarios u        ON u.id  = v.created_by
     LEFT JOIN venta_detalles vd ON vd.venta_id = v.id
     LEFT JOIN productos p       ON p.id  = vd.producto_id
     $where
     GROUP BY v.id
     ORDER BY v.fecha_venta DESC"
);
$stmt->execute($params);
$ventas = $stmt->fetchAll();

// ── KPIs del período ──────────────────────────────────────────────────────────
// obsequio se contabiliza aparte; su valor NO entra en 'total' (no es ingreso)
$kpi = ['n'=>0, 'total'=>0.0, 'efectivo'=>0.0, 'digital'=>0.0, 'fiado'=>0.0,
        'pendiente'=>0.0, 'anuladas'=>0, 'obsequio_n'=>0, 'obsequio_val'=>0.0];
foreach ($ventas as $v) {
    if ($v['estado'] === 'anulada') { $kpi['anuladas']++; continue; }
    if ($v['metodo_pago'] === 'obsequio') {
        $kpi['obsequio_n']++;
        $kpi['obsequio_val'] += (float)$v['total'];
        continue; // no suma al recuento ni al total de ingresos
    }
    $kpi['n']++;
    $kpi['total'] += (float)$v['total'];
    if ($v['metodo_pago'] === 'efectivo')                                          $kpi['efectivo'] += (float)$v['total'];
    elseif (in_array($v['metodo_pago'], ['nequi','daviplata','bancolombia'], true)) $kpi['digital']  += (float)$v['total'];
    elseif ($v['metodo_pago'] === 'fiado')                                         $kpi['fiado']    += (float)$v['total'];
    if ($v['estado'] === 'pendiente_pago' || ($v['fecha_pago'] === null && $v['metodo_pago'] === 'fiado'))
        $kpi['pendiente'] += (float)$v['total'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Historial de Ventas — <?= APP_NAME ?></title>
    <style>
        :root { --brand:#e94f37; --dark:#111827; --g2:#374151; --g5:#6b7280; --g8:#d1d5db; --g9:#f3f4f6; --white:#fff; --green:#059669; }
        *,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
        body { font-family:system-ui,-apple-system,sans-serif; background:var(--g9); color:var(--dark); }
        .main { max-width:1100px; margin:0 auto; padding:20px 14px 60px; }
        .page-hdr { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap; margin-bottom:20px; }
        .page-title { font-size:22px; font-weight:800; }
        .page-sub   { font-size:13px; color:var(--g5); margin-top:3px; }

        /* Filtros */
        .filtros { display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end; background:var(--white); border:1px solid var(--g8); border-radius:12px; padding:14px 16px; margin-bottom:18px; }
        .fg { display:flex; flex-direction:column; gap:3px; }
        .fg label { font-size:11px; font-weight:700; color:var(--g5); text-transform:uppercase; letter-spacing:.4px; }
        .fg input,.fg select { padding:7px 10px; border:1px solid var(--g8); border-radius:8px; font-size:13px; color:var(--dark); outline:none; }
        .fg input:focus,.fg select:focus { border-color:var(--brand); }
        .fg-busq { flex:1; min-width:180px; }
        .btn-filtrar { padding:8px 18px; background:var(--brand); color:#fff; border:none; border-radius:8px; font-size:13px; font-weight:700; cursor:pointer; align-self:flex-end; }
        .btn-filtrar:hover { background:#c94330; }

        /* KPIs */
        .kpi-row { display:grid; grid-template-columns:repeat(auto-fit,minmax(130px,1fr)); gap:10px; margin-bottom:18px; }
        .kpi { background:var(--white); border:1px solid var(--g8); border-radius:12px; padding:14px 16px; }
        .kpi-val { font-size:19px; font-weight:800; }
        .kpi-val.brand { color:var(--brand); }
        .kpi-val.green { color:var(--green); }
        .kpi-val.warn  { color:#d97706; }
        .kpi-val.red   { color:var(--brand); }
        .kpi-lbl { font-size:11px; color:var(--g5); margin-top:3px; text-transform:uppercase; letter-spacing:.4px; }

        /* Tabla */
        .tbl-wrap { background:var(--white); border:1px solid var(--g8); border-radius:12px; overflow:hidden; overflow-x:auto; }
        .tbl { width:100%; border-collapse:collapse; }
        .tbl thead th { background:var(--g9); font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; padding:10px 12px; text-align:left; color:var(--g5); white-space:nowrap; }
        .tbl th.r,.tbl td.r { text-align:right; }
        .tbl tbody tr.venta-row { border-bottom:1px solid var(--g9); transition:background .1s; }
        .tbl tbody tr.venta-row:hover { background:#fafafa; }
        .tbl tbody tr.anulada td { opacity:.5; }
        .tbl td { padding:10px 12px; font-size:13px; vertical-align:middle; }
        .muted { color:var(--g5); font-size:12px; }
        .det-row { display:none; border-bottom:1px solid var(--g9); }
        .det-row.open { display:table-row; }
        .det-row td { background:#f8faff; padding:8px 20px 12px; }

        /* Badges */
        .badge { display:inline-block; font-size:11px; font-weight:600; padding:2px 8px; border-radius:999px; white-space:nowrap; }
        .badge-efectivo     { background:#d1fae5; color:#065f46; }
        .badge-nequi        { background:#ede9fe; color:#5b21b6; }
        .badge-daviplata    { background:#dbeafe; color:#1d4ed8; }
        .badge-bancolombia  { background:#fef9c3; color:#713f12; }
        .badge-fiado        { background:#fef3c7; color:#92400e; }
        .badge-obsequio     { background:#fce7f3; color:#9d174d; }
        .badge-completada   { background:#d1fae5; color:#065f46; }
        .badge-anulada      { background:#fee2e2; color:#991b1b; }
        .badge-pendiente    { background:#fef3c7; color:#92400e; }
        .badge-combo-item   { background:#e0e7ff; color:#3730a3; margin-left:4px; }

        /* Botones */
        .btn-a { border:none; padding:4px 9px; border-radius:6px; font-size:11px; font-weight:600; cursor:pointer; margin-left:3px; }
        .btn-ver    { background:var(--g9); color:var(--dark); }
        .btn-ver:hover { background:var(--g8); }
        .btn-pago   { background:#d1fae5; color:#065f46; }
        .btn-pago:hover { background:#a7f3d0; }
        .btn-anul   { background:#fee2e2; color:#991b1b; }
        .btn-anul:hover { background:#fca5a5; }
        .btn-edit   { background:#dbeafe; color:#1d4ed8; }
        .btn-edit:hover { background:#bfdbfe; }

        /* Edit modal */
        .modal-ov { position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:900; display:none; align-items:center; justify-content:center; padding:12px; }
        .modal-ov.open { display:flex; }
        .modal-card { background:#fff; border-radius:16px; width:100%; max-width:660px; max-height:92vh; overflow:hidden; display:flex; flex-direction:column; }
        .modal-hdr { padding:16px 20px 12px; border-bottom:1px solid var(--g8); display:flex; justify-content:space-between; align-items:center; flex-shrink:0; }
        .modal-title { font-size:16px; font-weight:800; }
        .modal-close { background:none; border:none; font-size:22px; cursor:pointer; color:var(--g5); line-height:1; padding:2px 6px; }
        .modal-body { padding:16px 20px; overflow-y:auto; flex:1; }
        .modal-ftr { padding:12px 20px; border-top:1px solid var(--g8); display:flex; gap:8px; justify-content:flex-end; flex-shrink:0; background:#fff; }
        .form-row { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px; }
        .form-row.full { grid-template-columns:1fr; }
        .fg-m { display:flex; flex-direction:column; gap:4px; }
        .fg-m label { font-size:11px; font-weight:700; color:var(--g5); text-transform:uppercase; letter-spacing:.4px; }
        .fg-m input,.fg-m select,.fg-m textarea { padding:8px 10px; border:1px solid var(--g8); border-radius:8px; font-size:13px; color:var(--dark); outline:none; width:100%; }
        .fg-m input:focus,.fg-m select:focus,.fg-m textarea:focus { border-color:var(--brand); }
        .items-hdr { font-size:11px; font-weight:700; color:var(--g5); text-transform:uppercase; letter-spacing:.4px; margin:16px 0 8px; }
        .items-tbl { width:100%; border-collapse:collapse; font-size:12px; }
        .items-tbl th { font-size:11px; font-weight:600; color:var(--g5); text-align:left; padding:4px 6px; background:var(--g9); }
        .items-tbl td { padding:4px 6px; vertical-align:middle; }
        .items-tbl td input,.items-tbl td select { padding:5px 7px; border:1px solid var(--g8); border-radius:6px; font-size:12px; width:100%; }
        .items-tbl td input:focus,.items-tbl td select:focus { border-color:var(--brand); outline:none; }
        .btn-del-item { background:#fee2e2; color:#991b1b; border:none; border-radius:6px; padding:4px 8px; font-size:11px; cursor:pointer; }
        .btn-del-item:hover { background:#fca5a5; }
        .btn-add-item { background:var(--g9); color:var(--dark); border:1px dashed var(--g8); border-radius:8px; padding:7px; font-size:12px; font-weight:600; cursor:pointer; width:100%; margin-top:6px; }
        .btn-add-item:hover { background:var(--g8); }
        .sub-cell { font-weight:700; text-align:right; white-space:nowrap; }
        .modal-total { text-align:right; font-size:15px; font-weight:800; padding:8px 0 2px; }
        .btn-save { background:var(--brand); color:#fff; border:none; border-radius:8px; padding:9px 22px; font-size:13px; font-weight:700; cursor:pointer; }
        .btn-save:hover { background:#c94330; }
        .btn-save:disabled { opacity:.6; cursor:not-allowed; }
        .btn-cancel { background:var(--g9); color:var(--dark); border:1px solid var(--g8); border-radius:8px; padding:9px 16px; font-size:13px; font-weight:700; cursor:pointer; }
        .btn-cancel:hover { background:var(--g8); }
        @media(max-width:500px) { .form-row { grid-template-columns:1fr; } }

        /* Empty */
        .empty { text-align:center; padding:48px; color:var(--g5); }
        .empty strong { display:block; margin-bottom:8px; font-size:15px; }

        /* Toast */
        .toast { position:fixed; bottom:20px; left:50%; transform:translateX(-50%) translateY(20px); padding:10px 20px; border-radius:24px; font-size:14px; font-weight:600; opacity:0; transition:.25s; z-index:999; pointer-events:none; white-space:nowrap; }
        .toast.on  { opacity:1; transform:translateX(-50%) translateY(0); }
        .toast-ok  { background:#065f46; color:#d1fae5; }
        .toast-err { background:#991b1b; color:#fee2e2; }

        /* ════════════════════════════════════════════════════════════════
           RESPONSIVE — HISTORIAL DE VENTAS
           Columnas de la tabla: 1=# 2=Fecha 3=Cliente 4=Productos
                                 5=Método 6=Total 7=Estado 8=Acciones
           ════════════════════════════════════════════════════════════════ */

        /* ── Teléfono vertical (< 480px): solo lo esencial ── */
        @media (max-width: 479px) {
            /* Ocultar: Productos (4), Método (5) */
            .tbl th:nth-child(4),.tbl td:nth-child(4),
            .tbl th:nth-child(5),.tbl td:nth-child(5) { display: none; }
            /* Compactar # y fecha */
            .tbl th:nth-child(1),.tbl td:nth-child(1) { white-space: nowrap; }
            .tbl th:nth-child(2),.tbl td:nth-child(2) { white-space: nowrap; }
            /* Botones de acción — iconos en fila compacta */
            .tbl td:nth-child(8) { white-space: nowrap; padding: 6px 4px; }
            .tbl td:nth-child(8) .btn-a { margin-left: 2px !important; }
            /* KPIs en 2 columnas */
            .kpi-row { grid-template-columns: repeat(2, 1fr) !important; }
        }

        /* ── Teléfono horizontal / tablet pequeña (480-767px) ── */
        @media (max-width: 767px) and (min-width: 480px) {
            /* Ocultar solo Productos (4) */
            .tbl th:nth-child(4),.tbl td:nth-child(4) { display: none; }
        }

        /* ── Legacy: ya existía — mantener para 680px ── */
        @media (max-width: 680px) {
            .tbl th:nth-child(4),.tbl td:nth-child(4),
            .tbl th:nth-child(6),.tbl td:nth-child(6) { display: none; }
        }

        /* ── Tablet (640-1023px): tabla con más espacio ── */
        @media (min-width: 640px) and (max-width: 1023px) {
            .main { max-width: 100%; padding: 16px 18px 60px; }
            /* KPIs en 3 o más columnas según ancho disponible */
            .kpi-row { grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); }
            /* Modal de edición: centrado con max-width */
            .modal-ov { align-items: center !important; padding: 16px; }
            .modal-card { max-height: 90vh; border-radius: 14px !important; }
        }

        /* ── Pantalla grande (≥1600px) ── */
        @media (min-width: 1600px) {
            .main       { max-width: 1440px !important; padding: 24px 32px 60px !important; }
            .tbl thead th { font-size: 12px !important; padding: 12px 16px !important; }
            .tbl td       { font-size: 14px !important; padding: 12px 16px !important; }
            .kpi-val      { font-size: 22px !important; }
            .kpi-lbl      { font-size: 12px !important; }
            /* Modal de edición más ancho */
            .modal-card { max-width: 800px; }
        }

        /* ── TV (≥1920px) ── */
        @media (min-width: 1920px) {
            .main { max-width: 1680px !important; padding: 32px 48px 80px !important; }
            .tbl thead th { font-size: 14px !important; padding: 15px 20px !important; }
            .tbl td       { font-size: 16px !important; padding: 14px 20px !important; }
            .kpi-val      { font-size: 28px !important; }
            .kpi-lbl      { font-size: 13px !important; }
            .badge        { font-size: 13px !important; padding: 4px 12px !important; }
            .btn-a        { font-size: 13px !important; padding: 7px 14px !important; }
        }

        /* ── Modal edición: full-screen en teléfono vertical ── */
        @media (max-width: 479px) {
            .modal-ov   { padding: 0 !important; align-items: flex-end !important; }
            .modal-card {
                border-radius: 20px 20px 0 0 !important;
                max-height: 96vh;
                /* En móvil el modal de edición usa toda la altura disponible */
            }
            /* Tabla de ítems en el modal: compactar columnas */
            .items-tbl th:nth-child(4),
            .items-tbl td:nth-child(4) { display: none; } /* ocultar combo en móvil */
            .form-row { grid-template-columns: 1fr !important; }
        }
    </style>
</head>
<body>
<?php $nav_activo = 'ventas'; include __DIR__ . '/../app/views/nav.php'; ?>

<main class="main">

    <div class="page-hdr">
        <div>
            <h1 class="page-title">Historial de Ventas</h1>
            <p class="page-sub">
                <?= date('d/m/Y', strtotime($fecha_desde)) ?> — <?= date('d/m/Y', strtotime($fecha_hasta)) ?>
                &middot; <?= $kpi['n'] ?> venta<?= $kpi['n'] !== 1 ? 's' : '' ?>
            </p>
        </div>
        <a href="<?= APP_BASE ?>/ventas/"
           style="background:var(--brand);color:#fff;text-decoration:none;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:700">
            Ir al POS
        </a>
    </div>

    <?php if ($cliente_filtrado): ?>
    <!-- Banner de filtro por cliente: visible cuando se llega desde el módulo Clientes -->
    <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:12px;
                padding:10px 16px;margin-bottom:14px;
                display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
        <span style="font-size:14px;color:#166534">
            <strong>Filtrando por cliente:</strong>
            <?= htmlspecialchars($cliente_filtrado['nombre'] . (isset($cliente_filtrado['apellido']) && $cliente_filtrado['apellido'] ? ' ' . $cliente_filtrado['apellido'] : '')) ?>
            <?php if ($cliente_filtrado['saldo_fiado'] > 0): ?>
            — Fiado pendiente: <strong>$<?= number_format($cliente_filtrado['saldo_fiado'], 0, ',', '.') ?></strong>
            <?php endif; ?>
        </span>
        <a href="<?= APP_BASE ?>/ventas/historial.php?desde=<?= $fecha_desde ?>&hasta=<?= $fecha_hasta ?>"
           style="font-size:12px;color:#166534;font-weight:700;text-decoration:none;
                  background:#dcfce7;padding:4px 12px;border-radius:8px">
            Quitar filtro de cliente ×
        </a>
    </div>
    <?php endif; ?>

    <!-- Filtros: si viene con cliente_id, se preserva en el form -->
    <form method="GET" class="filtros">
        <?php if ($filtro_cli > 0): ?>
        <input type="hidden" name="cliente_id" value="<?= $filtro_cli ?>">
        <?php endif; ?>
        <div class="fg"><label>Desde</label>
            <input type="date" name="desde" value="<?= htmlspecialchars($fecha_desde) ?>"></div>
        <div class="fg"><label>Hasta</label>
            <input type="date" name="hasta" value="<?= htmlspecialchars($fecha_hasta) ?>"></div>
        <div class="fg"><label>Método de pago</label>
            <select name="metodo">
                <option value="">Todos los métodos</option>
                <?php foreach (['efectivo'=>'Efectivo','nequi'=>'Nequi','daviplata'=>'Daviplata','bancolombia'=>'Bancolombia','fiado'=>'Fiado','obsequio'=>'Obsequio'] as $k=>$l): ?>
                <option value="<?= $k ?>" <?= $filtro_met===$k?'selected':'' ?>><?= $l ?></option>
                <?php endforeach; ?>
            </select></div>
        <div class="fg"><label>Estado</label>
            <select name="estado">
                <option value="">Todos los estados</option>
                <option value="completada"     <?= $filtro_est==='completada'    ?'selected':''?>>Completadas</option>
                <option value="anulada"        <?= $filtro_est==='anulada'       ?'selected':''?>>Anuladas</option>
                <option value="pendiente_pago" <?= $filtro_est==='pendiente_pago'?'selected':''?>>Pendiente cobro</option>
            </select></div>
        <div class="fg fg-busq"><label>Buscar cliente</label>
            <input type="text" id="busq" placeholder="Nombre del cliente…" oninput="filtrarCliente()" autocomplete="off"></div>
        <button type="submit" class="btn-filtrar">Filtrar</button>
    </form>

    <!-- KPIs -->
    <div class="kpi-row">
        <div class="kpi"><div class="kpi-val brand"><?= $kpi['n'] ?></div><div class="kpi-lbl">Ventas</div></div>
        <div class="kpi"><div class="kpi-val">$<?= number_format($kpi['total'],0,',','.') ?></div><div class="kpi-lbl">Total recaudado</div></div>
        <div class="kpi"><div class="kpi-val green">$<?= number_format($kpi['efectivo'],0,',','.') ?></div><div class="kpi-lbl">Efectivo</div></div>
        <div class="kpi"><div class="kpi-val" style="color:#5b21b6">$<?= number_format($kpi['digital'],0,',','.') ?></div><div class="kpi-lbl">Digital</div></div>
        <div class="kpi"><div class="kpi-val warn">$<?= number_format($kpi['fiado'],0,',','.') ?></div><div class="kpi-lbl">Fiado</div></div>
        <?php if ($kpi['pendiente'] > 0): ?>
        <div class="kpi" style="border-color:#fca5a5;background:#fff8f7">
            <div class="kpi-val red">$<?= number_format($kpi['pendiente'],0,',','.') ?></div>
            <div class="kpi-lbl">Sin cobrar</div>
        </div>
        <?php endif; ?>
        <?php if ($kpi['obsequio_n'] > 0): ?>
        <div class="kpi" style="border-color:#fbcfe8;background:#fdf4ff">
            <div class="kpi-val" style="color:#9d174d"><?= $kpi['obsequio_n'] ?> — $<?= number_format($kpi['obsequio_val'],0,',','.') ?></div>
            <div class="kpi-lbl">🎁 Obsequiados</div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Tabla -->
    <div class="tbl-wrap">
        <?php if (empty($ventas)): ?>
        <div class="empty">
            <strong>Sin ventas en este período</strong>
            Ajusta el rango de fechas o los filtros.
        </div>
        <?php else: ?>
        <table class="tbl" id="tbl-v">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Fecha / Hora</th>
                    <th>Cliente</th>
                    <th>Productos</th>
                    <th>Método</th>
                    <th class="r">Total</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($ventas as $v):
                $anulada  = $v['estado'] === 'anulada';
                $sinCobrar = !$anulada && $v['fecha_pago'] === null && $v['metodo_pago'] === 'fiado';
                $estBadge = $anulada ? 'anulada' : ($sinCobrar ? 'pendiente' : 'completada');
                $estLabel = $anulada ? 'Anulada'  : ($sinCobrar ? 'Sin cobrar' : 'Completada');
            ?>
            <tr class="venta-row <?= $anulada ? 'anulada' : '' ?>"
                data-cli="<?= htmlspecialchars(strtolower($v['cliente_nombre'])) ?>">
                <td class="muted">#<?= $v['id'] ?></td>
                <td class="muted"><?= date('d/m H:i', strtotime($v['fecha_venta'])) ?></td>
                <td>
                    <?php if ($v['cliente_nombre'] !== 'Mostrador'): ?>
                        <strong><?= htmlspecialchars($v['cliente_nombre']) ?></strong>
                    <?php else: ?>
                        <span class="muted">Mostrador</span>
                    <?php endif; ?>
                    <?php if (!empty($v['cajero_nombre'])): ?>
                        <br><small class="muted"><?= htmlspecialchars($v['cajero_nombre']) ?></small>
                    <?php endif; ?>
                </td>
                <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px;color:var(--g2)">
                    <?= htmlspecialchars($v['productos_lista'] ?? '—') ?>
                    <?php if ($v['es_combo']): ?>
                    <span class="badge" style="background:#e0e7ff;color:#3730a3">combo</span>
                    <?php endif; ?>
                </td>
                <td><span class="badge badge-<?= $v['metodo_pago'] ?>"><?= ucfirst($v['metodo_pago']) ?></span></td>
                <td class="r"><strong>$<?= number_format((float)$v['total'],0,',','.') ?></strong></td>
                <td>
                    <span class="badge badge-<?= $estBadge ?>"><?= $estLabel ?></span>
                    <?php if ($v['fecha_pago']): ?>
                    <br><small class="muted">Cobrado <?= date('d/m H:i', strtotime($v['fecha_pago'])) ?></small>
                    <?php endif; ?>
                </td>
                <td>
                    <button class="btn-a ic btn-ver" title="Detalle" onclick="toggleDet(<?= $v['id'] ?>)"><?= IC_EYE ?></button>
                    <?php if (!$anulada && permiso_tiene('ventas','editar_existentes')): ?>
                    <button class="btn-a ic btn-edit" title="Editar" onclick="abrirEditar(<?= $v['id'] ?>)"><?= IC_EDIT ?></button>
                    <?php endif; ?>
                    <?php if ($sinCobrar && permiso_tiene('ventas','editar_existentes')): ?>
                    <button class="btn-a ic btn-pago" title="Marcar pagado" onclick="marcarPagado(<?= $v['id'] ?>)"><?= IC_CHECK ?></button>
                    <?php endif; ?>
                    <?php if (!$anulada && permiso_tiene('ventas','admin_total')): ?>
                    <button class="btn-a ic btn-anul" title="Anular"
                            onclick="anularVenta(<?= $v['id'] ?>,'<?= date('d/m/Y H:i',strtotime($v['fecha_venta'])) ?>')">
                        <?= IC_XMARK ?>
                    </button>
                    <?php endif; ?>
                </td>
            </tr>
            <tr class="det-row" id="det-<?= $v['id'] ?>">
                <td colspan="8">
                    <div id="det-in-<?= $v['id'] ?>" style="font-size:12px;color:var(--g2)">Cargando…</div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</main>

<!-- Modal Editar Venta -->
<div class="modal-ov" id="modal-editar">
    <div class="modal-card">
        <div class="modal-hdr">
            <span class="modal-title" id="modal-editar-title">Editar venta</span>
            <button class="modal-close" onclick="cerrarEditar()" aria-label="Cerrar">&times;</button>
        </div>
        <div class="modal-body">
            <div id="modal-loading" style="text-align:center;padding:40px;color:var(--g5)">Cargando…</div>
            <div id="modal-form" style="display:none">
                <input type="hidden" id="edit-id">
                <div class="form-row">
                    <div class="fg-m">
                        <label>Fecha y hora de la venta</label>
                        <input type="datetime-local" id="edit-fecha-venta" max="<?= date('Y-m-d\TH:i') ?>">
                    </div>
                    <div class="fg-m">
                        <label>Método de pago</label>
                        <select id="edit-metodo" onchange="onMetodoChange()">
                            <option value="efectivo">Efectivo</option>
                            <option value="nequi">Nequi</option>
                            <option value="daviplata">Daviplata</option>
                            <option value="bancolombia">Bancolombia</option>
                            <option value="fiado">Fiado</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="fg-m">
                        <label>Cliente</label>
                        <select id="edit-cliente"></select>
                    </div>
                    <div></div>
                </div>
                <div class="form-row" id="row-fecha-pago" style="display:none">
                    <div class="fg-m">
                        <label>Fecha de cobro (fiado)</label>
                        <input type="datetime-local" id="edit-fecha-pago" max="<?= date('Y-m-d\TH:i') ?>">
                    </div>
                    <div></div>
                </div>
                <div class="form-row full">
                    <div class="fg-m">
                        <label>Notas</label>
                        <textarea id="edit-notas" rows="2" style="resize:vertical"></textarea>
                    </div>
                </div>
                <div class="items-hdr">Productos</div>
                <table class="items-tbl">
                    <thead>
                        <tr>
                            <th style="width:38%">Producto</th>
                            <th style="width:11%">Cant.</th>
                            <th style="width:18%">Precio unit.</th>
                            <th style="width:9%;text-align:center">Combo</th>
                            <th style="width:16%;text-align:right">Subtotal</th>
                            <th style="width:8%"></th>
                        </tr>
                    </thead>
                    <tbody id="edit-items-body"></tbody>
                </table>
                <button class="btn-add-item" type="button" onclick="agregarItemFila(null)">+ Agregar producto</button>
                <div class="modal-total">Total: <strong id="edit-total">$0</strong></div>
            </div>
        </div>
        <div class="modal-ftr">
            <button class="btn-cancel" onclick="cerrarEditar()">Cancelar</button>
            <button class="btn-save" id="btn-guardar" onclick="guardarEdicion()">Guardar cambios</button>
        </div>
    </div>
</div>

<input type="hidden" id="csrf-tk" value="<?= htmlspecialchars(csrf_token()) ?>">
<div class="toast" id="toast"></div>

<script>
var csrf = function(){ return document.getElementById('csrf-tk').value; };
var _tt;
function toast(m,t){
    var el=document.getElementById('toast');
    el.textContent=m; el.className='toast toast-'+t+' on';
    clearTimeout(_tt); _tt=setTimeout(function(){ el.classList.remove('on'); },3200);
}

/* Filtro cliente (client-side) */
function filtrarCliente(){
    var q=document.getElementById('busq').value.toLowerCase().trim();
    document.querySelectorAll('#tbl-v tbody tr.venta-row').forEach(function(tr){
        var ok=!q||tr.dataset.cli.indexOf(q)!==-1;
        tr.style.display=ok?'':'none';
        var next=tr.nextElementSibling;
        if(next&&next.classList.contains('det-row')&&!ok) next.classList.remove('open');
    });
}

/* Expandir detalle */
var cache={};
async function toggleDet(id){
    var row=document.getElementById('det-'+id);
    row.classList.toggle('open');
    if(!row.classList.contains('open')||cache[id]) return;
    try {
        var r=await fetch('api/detalle_venta.php?id='+id);
        var d=await r.json();
        if(d.success&&d.items){
            var h='<table style="width:100%;border-collapse:collapse">';
            h+='<tr style="color:var(--g5);font-size:11px"><th style="text-align:left;padding:3px 8px">Producto</th><th style="padding:3px 8px">Cant.</th><th style="text-align:right;padding:3px 8px">Precio</th><th style="text-align:right;padding:3px 8px">Subtotal</th></tr>';
            d.items.forEach(function(i){
                var comboTag=i.es_combo?'<span class="badge badge-combo-item">combo</span>':'';
                // nombre2 se muestra como subtítulo gris debajo del nombre del producto
                var sub2=i.nombre2?'<div style="font-size:10px;color:var(--g5)">'+esc(i.nombre2)+'</div>':'';
                h+='<tr style="border-top:1px solid #f3f4f6"><td style="padding:4px 8px">'+esc(i.nombre)+comboTag+sub2+'</td>';
                h+='<td style="text-align:center;padding:4px 8px">'+i.cantidad+'</td>';
                h+='<td style="text-align:right;padding:4px 8px">$'+fmt(i.precio_unitario)+'</td>';
                h+='<td style="text-align:right;padding:4px 8px;font-weight:700">$'+fmt(i.subtotal)+'</td></tr>';
            });
            h+='</table>';
            if(d.notas) h+='<p style="margin-top:6px;color:var(--g5)">Nota: '+esc(d.notas)+'</p>';
            document.getElementById('det-in-'+id).innerHTML=h;
            cache[id]=true;
        }
    } catch(e){ document.getElementById('det-in-'+id).textContent='Error al cargar.'; }
}

/* Marcar pagado */
async function marcarPagado(id){
    if(!confirm('¿Marcar la venta #'+id+' como pagada?')) return;
    var fd=new FormData();
    fd.append('csrf_token',csrf()); fd.append('accion','marcar_pagado'); fd.append('id',id);
    try {
        var r=await fetch('api/cambiar_estado.php',{method:'POST',body:fd});
        var d=await r.json();
        if(d.success){ toast('Venta #'+id+' marcada como pagada.','ok'); setTimeout(function(){ location.reload(); },800); }
        else toast(d.error||'Error.','err');
    } catch(e){ toast('Error de conexión.','err'); }
}

/* Anular */
async function anularVenta(id,fecha){
    if(!confirm('¿Anular la venta #'+id+' del '+fecha+'?\n\nSe revertirá el stock de ingredientes o producto terminado.')) return;
    var fd=new FormData();
    fd.append('csrf_token',csrf()); fd.append('accion','anular'); fd.append('id',id);
    try {
        var r=await fetch('api/cambiar_estado.php',{method:'POST',body:fd});
        var d=await r.json();
        if(d.success){ toast('Venta #'+id+' anulada.','ok'); setTimeout(function(){ location.reload(); },800); }
        else toast(d.error||'Error.','err');
    } catch(e){ toast('Error de conexión.','err'); }
}

function fmt(n){ return Math.round(parseFloat(n)||0).toLocaleString('es-CO'); }
function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

/* ──────── EDITAR VENTA ──────── */
var _editProds = [];
var _rowN = 0;

function abrirEditar(id) {
    document.getElementById('modal-editar').classList.add('open');
    document.getElementById('modal-loading').style.display = '';
    document.getElementById('modal-form').style.display = 'none';
    document.getElementById('modal-editar-title').textContent = 'Editar venta #' + id;
    document.getElementById('edit-id').value = id;
    document.getElementById('edit-items-body').innerHTML = '';

    fetch('api/editar_venta.php?id=' + id)
        .then(function(r){ return r.json(); })
        .then(function(d) {
            if (!d.success) { toast(d.error || 'Error al cargar.', 'err'); cerrarEditar(); return; }
            _editProds = d.productos;

            var selCli = document.getElementById('edit-cliente');
            selCli.innerHTML = '<option value="">Mostrador (sin cliente)</option>';
            d.clientes.forEach(function(c) {
                var o = document.createElement('option');
                o.value = c.id; o.textContent = c.nombre;
                if (String(c.id) === String(d.venta.cliente_id)) o.selected = true;
                selCli.appendChild(o);
            });

            document.getElementById('edit-metodo').value = d.venta.metodo_pago;
            document.getElementById('edit-notas').value  = d.venta.notas || '';
            var fv = d.venta.fecha_venta;
            document.getElementById('edit-fecha-venta').value = fv ? fv.substring(0,16).replace(' ','T') : '';
            var fp = d.venta.fecha_pago;
            document.getElementById('edit-fecha-pago').value = fp ? fp.substring(0,16).replace(' ','T') : '';
            onMetodoChange();

            d.items.forEach(function(item){ agregarItemFila(item); });
            recalcTotal();

            document.getElementById('modal-loading').style.display = 'none';
            document.getElementById('modal-form').style.display = '';
        })
        .catch(function(){ toast('Error de conexión.', 'err'); cerrarEditar(); });
}

function cerrarEditar() {
    document.getElementById('modal-editar').classList.remove('open');
}

function onMetodoChange() {
    var esF = document.getElementById('edit-metodo').value === 'fiado';
    document.getElementById('row-fecha-pago').style.display = esF ? '' : 'none';
    if (!esF) document.getElementById('edit-fecha-pago').value = '';
}

function agregarItemFila(item) {
    var rowId = 'ir-' + (++_rowN);
    var tr = document.createElement('tr');
    tr.id = rowId;

    var selHtml = '<select onchange="onProdChange(this)">'
        + '<option value="">— Producto —</option>';
    _editProds.forEach(function(p) {
        var sel = (item && String(p.id) === String(item.producto_id)) ? ' selected' : '';
        // Muestra: "Nombre [Tamaño] — nombre2" para identificar el producto fácilmente
        var label = p.nombre + (p.tamano && p.tamano !== 'unico' ? ' ' + p.tamano : '')
                              + (p.nombre2 ? ' — ' + p.nombre2 : '');
        selHtml += '<option value="' + p.id + '" data-precio="' + p.precio_venta + '"' + sel + '>'
            + esc(label) + '</option>';
    });
    selHtml += '</select>';

    var precio  = item ? parseFloat(item.precio_unitario) : 0;
    var cant    = item ? parseInt(item.cantidad) : 1;
    var checked = (item && item.es_combo) ? ' checked' : '';

    tr.innerHTML = '<td>' + selHtml + '</td>'
        + '<td><input type="number" min="1" step="1" value="' + cant + '" style="width:58px" oninput="recalcRow(this)"></td>'
        + '<td><input type="number" min="0" step="100" value="' + precio + '" style="width:86px" oninput="recalcRow(this)"></td>'
        + '<td style="text-align:center"><input type="checkbox"' + checked + '></td>'
        + '<td class="sub-cell" id="sub-' + rowId + '">$' + fmt(precio * cant) + '</td>'
        + '<td><button class="btn-del-item" type="button" onclick="eliminarFila(\'' + rowId + '\')">✕</button></td>';

    document.getElementById('edit-items-body').appendChild(tr);
}

function onProdChange(sel) {
    var precio = parseFloat(sel.options[sel.selectedIndex].dataset.precio || '0');
    var tr = sel.closest('tr');
    tr.querySelectorAll('input[type=number]')[1].value = precio;
    recalcRow(tr.querySelectorAll('input[type=number]')[0]);
}

function recalcRow(inp) {
    var tr   = inp.closest('tr');
    var inps = tr.querySelectorAll('input[type=number]');
    var sub  = (parseFloat(inps[0].value)||0) * (parseFloat(inps[1].value)||0);
    var cell = tr.querySelector('.sub-cell');
    if (cell) cell.textContent = '$' + fmt(sub);
    recalcTotal();
}

function recalcTotal() {
    var total = 0;
    document.querySelectorAll('#edit-items-body tr').forEach(function(tr) {
        var inps = tr.querySelectorAll('input[type=number]');
        if (inps.length >= 2) total += (parseFloat(inps[0].value)||0) * (parseFloat(inps[1].value)||0);
    });
    document.getElementById('edit-total').textContent = '$' + fmt(total);
}

function eliminarFila(rowId) {
    var tr = document.getElementById(rowId);
    if (tr) { tr.remove(); recalcTotal(); }
}

async function guardarEdicion() {
    var id        = parseInt(document.getElementById('edit-id').value);
    var metodo    = document.getElementById('edit-metodo').value;
    var clienteId = document.getElementById('edit-cliente').value;
    var notas     = document.getElementById('edit-notas').value;
    var fechaVenta = document.getElementById('edit-fecha-venta').value;
    var fechaPago  = document.getElementById('edit-fecha-pago').value;

    var items = [];
    document.querySelectorAll('#edit-items-body tr').forEach(function(tr) {
        var sel  = tr.querySelector('select');
        var inps = tr.querySelectorAll('input[type=number]');
        var chk  = tr.querySelector('input[type=checkbox]');
        var pid  = sel ? parseInt(sel.value) : 0;
        if (!pid) return;
        items.push({
            producto_id:    pid,
            cantidad:       parseInt(inps[0].value) || 1,
            precio_unitario: parseFloat(inps[1].value) || 0,
            es_combo:       (chk && chk.checked) ? 1 : 0
        });
    });

    if (!items.length) { toast('Agrega al menos un producto.', 'err'); return; }
    if (metodo === 'fiado' && !clienteId) { toast('Selecciona un cliente para ventas a fiado.', 'err'); return; }

    var btn = document.getElementById('btn-guardar');
    btn.disabled = true; btn.textContent = 'Guardando…';

    var fd = new FormData();
    fd.append('csrf_token',  csrf()); fd.append('id', id);
    fd.append('metodo_pago', metodo); fd.append('cliente_id', clienteId || '');
    fd.append('notas',       notas);
    fd.append('fecha_venta', fechaVenta ? fechaVenta.replace('T', ' ') : '');
    fd.append('fecha_pago',  fechaPago  ? fechaPago.replace('T', ' ')  : '');
    fd.append('items_json', JSON.stringify(items));

    try {
        var r = await fetch('api/editar_venta.php', { method:'POST', body:fd });
        var d = await r.json();
        if (d.success) {
            toast('Venta #' + id + ' actualizada correctamente.', 'ok');
            cerrarEditar();
            setTimeout(function(){ location.reload(); }, 900);
        } else {
            toast(d.error || 'Error al guardar.', 'err');
        }
    } catch(e) {
        toast('Error de conexión.', 'err');
    } finally {
        btn.disabled = false; btn.textContent = 'Guardar cambios';
    }
}

// Cerrar con Escape o clic en backdrop
document.addEventListener('keydown', function(e){ if(e.key==='Escape') cerrarEditar(); });
document.getElementById('modal-editar').addEventListener('click', function(e){ if(e.target===this) cerrarEditar(); });
</script>
</body>
</html>
