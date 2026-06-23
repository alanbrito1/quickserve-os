<?php
/**
 * public_html/proveedores/index.php
 * Módulo de Proveedores — CRUD completo.
 */

require_once __DIR__ . '/../app/middleware/auth_check.php';
require_once __DIR__ . '/../app/helpers/ListasHelper.php';

$nav_activo = 'proveedores';
permiso_requerir('proveedores', 'solo_ver');

// Detectar columnas extra (migración 011)
$cols = [];
$res  = db()->query('SHOW COLUMNS FROM proveedores');
foreach ($res->fetchAll() as $r) { $cols[] = $r['Field']; }
$tieneEmail = in_array('email',     $cols);
$tieneWeb   = in_array('sitio_web', $cols);
$tieneDir   = in_array('direccion', $cols);

// Proveedores con conteo de insumos asociados. Filtro de estado solo para admin.
$ver = filtro_estado_actual();
$proveedores = db()->query(
    "SELECT p.*,
            (SELECT COUNT(*) FROM insumos i WHERE i.proveedor_id = p.id AND i.activo = 1) AS num_insumos
     FROM proveedores p
     WHERE 1=1" . filtro_estado_sql($ver, 'activo', 'activo', 'p') . "
     ORDER BY p.activo DESC, p.nombre"
)->fetchAll();

$total   = count($proveedores);
$activos = count(array_filter($proveedores, fn($p) => $p['activo']));

// Categorías desde listas_sistema; fallback hardcodeado si migración 029 pendiente
$_cats_prov = listas_map('categoria_proveedor');
$CAT_LABELS = !empty($_cats_prov) ? $_cats_prov : [
    'plaza'      => 'Plaza / Mercado',
    'tienda'     => 'Tienda / Supermercado',
    'retail'     => 'Cadena retail',
    'online'     => 'Online',
    'mayorista'  => 'Mayorista',
    'panaderia'  => 'Panadería / Pastelería',
    'otro'       => 'Otro',
];
$CAT_COLORS = [
    'plaza'    => '#dbeafe:#1d4ed8',
    'tienda'   => '#d1fae5:#065f46',
    'retail'   => '#fef3c7:#92400e',
    'online'   => '#ede9fe:#5b21b6',
    'mayorista'=> '#fee2e2:#991b1b',
    'panaderia'=> '#fce7f3:#9d174d',
    'otro'     => '#f3f4f6:#6b7280',
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Proveedores — <?= APP_NAME ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root { --brand:#e94f37; --dark:#111827; --g2:#374151; --g5:#6b7280; --g8:#d1d5db; --g9:#f3f4f6; --white:#fff; --green:#059669; }
        body { font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; background:var(--g9); min-height:100vh; color:var(--dark); padding-bottom:40px; }
        .main { padding:16px 14px; max-width:960px; margin:0 auto; }
        .stats { display:grid; grid-template-columns:repeat(3,1fr); gap:10px; margin-bottom:16px; }
        .stat { background:var(--white); border-radius:14px; padding:14px; box-shadow:0 1px 4px rgba(0,0,0,.06); }
        .stat-n { font-size:22px; font-weight:800; }
        .stat-l { font-size:11px; color:var(--g5); text-transform:uppercase; letter-spacing:.4px; }
        .act-bar { display:flex; gap:8px; margin-bottom:14px; flex-wrap:wrap; }
        .btn-primary { padding:9px 18px; background:var(--brand); color:#fff; border:none; border-radius:10px; font-size:13px; font-weight:700; cursor:pointer; }
        .btn-sec     { padding:9px 16px; background:var(--white); color:var(--dark); border:1px solid var(--g8); border-radius:10px; font-size:13px; font-weight:700; text-decoration:none; cursor:pointer; }
        .btn-sec:hover { border-color:var(--brand); color:var(--brand); }
        /* Tarjetas de proveedores */
        .prov-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:12px; }
        .prov-card { background:var(--white); border-radius:14px; box-shadow:0 1px 4px rgba(0,0,0,.06); padding:16px; position:relative; }
        .prov-card.inactivo { opacity:.5; }
        .prov-nombre { font-size:16px; font-weight:800; margin-bottom:4px; }
        .prov-cat  { font-size:10px; font-weight:700; padding:2px 8px; border-radius:20px; display:inline-block; margin-bottom:8px; }
        .prov-info { font-size:12px; color:var(--g5); line-height:1.8; }
        .prov-info strong { color:var(--dark); }
        .prov-insumos { font-size:11px; margin-top:8px; padding-top:8px; border-top:1px solid var(--g8); color:var(--g5); }
        .prov-actions { display:flex; gap:6px; margin-top:12px; }
        .btn-edit   { flex:1; padding:7px; background:var(--g9); color:var(--g2); border:1px solid var(--g8); border-radius:8px; font-size:12px; font-weight:600; cursor:pointer; }
        .btn-edit:hover { border-color:var(--brand); color:var(--brand); }
        .btn-toggle { padding:7px 12px; border:none; border-radius:8px; font-size:12px; font-weight:600; cursor:pointer; }
        .btn-act  { background:#d1fae5; color:#065f46; }
        .btn-desa { background:#fef3c7; color:#92400e; }
        /* Modal */
        .overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.55); z-index:60; align-items:center; justify-content:center; padding:16px; }
        .overlay.on { display:flex; }
        .modal { background:var(--white); border-radius:16px; padding:22px; width:100%; max-width:520px; max-height:92vh; overflow-y:auto; }
        .modal-hdr { font-size:16px; font-weight:800; margin-bottom:16px; display:flex; justify-content:space-between; }
        .btn-cls { background:var(--g9); border:none; color:var(--g5); width:30px; height:30px; border-radius:50%; cursor:pointer; font-size:16px; }
        .fg { display:flex; flex-direction:column; gap:4px; margin-bottom:10px; }
        .fg label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--g5); }
        .fg input,.fg select,.fg textarea { padding:9px 11px; border:2px solid var(--g8); border-radius:9px; font-size:14px; color:var(--dark); outline:none; width:100%; -webkit-appearance:none; background:var(--white); }
        .fg input:focus,.fg select:focus,.fg textarea:focus { border-color:var(--brand); }
        .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
        @media(max-width:480px){ .form-grid { grid-template-columns:1fr; } }
        .btn-submit { width:100%; padding:12px; background:var(--brand); color:#fff; border:none; border-radius:10px; font-size:15px; font-weight:700; cursor:pointer; margin-top:8px; }
        .empty { text-align:center; padding:48px 16px; color:var(--g5); }
        .toast { position:fixed; bottom:20px; left:50%; transform:translateX(-50%) translateY(20px); padding:10px 20px; border-radius:24px; font-size:14px; font-weight:600; opacity:0; transition:.25s; z-index:99; pointer-events:none; max-width:90vw; }
        .toast.on { opacity:1; transform:translateX(-50%) translateY(0); }
        .toast-ok  { background:#065f46; color:#d1fae5; }
        .toast-err { background:#991b1b; color:#fee2e2; }
    </style>
</head>
<body>
<?php include __DIR__ . '/../app/views/nav.php'; ?>

<main class="main">

    <!-- Stats -->
    <div class="stats">
        <div class="stat"><div class="stat-n"><?= $activos ?></div><div class="stat-l">Activos</div></div>
        <div class="stat"><div class="stat-n"><?= $total - $activos ?></div><div class="stat-l">Inactivos</div></div>
        <div class="stat"><div class="stat-n"><?= $total ?></div><div class="stat-l">Total</div></div>
    </div>

    <!-- Acciones -->
    <div class="act-bar" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <?php if (permiso_tiene('proveedores','editar_existentes')): ?>
        <button class="btn-primary" onclick="abrirNuevo()">+ Nuevo Proveedor</button>
        <?php endif; ?>
        <?php if (filtro_estado_es_admin()): ?>
        <span style="margin-left:auto"><?= filtro_estado_ui($ver) ?></span>
        <?php endif; ?>
    </div>

    <!-- Grid de proveedores -->
    <?php if (empty($proveedores)): ?>
    <div class="empty">
        <p style="font-size:32px;margin-bottom:12px">🏪</p>
        <p style="font-weight:700">Sin proveedores registrados</p>
        <p style="font-size:13px;margin-top:6px">Usa el botón "+ Nuevo Proveedor" para agregar el primero.</p>
    </div>
    <?php else: ?>
    <div class="prov-grid">
        <?php foreach ($proveedores as $p):
            $cat    = $p['categoria'] ?? 'otro';
            $catL   = $CAT_LABELS[$cat] ?? 'Otro';
            $colors = explode(':', $CAT_COLORS[$cat] ?? '#f3f4f6:#6b7280');
            $bgC = $colors[0] ?? '#f3f4f6';
            $txC = $colors[1] ?? '#6b7280';
        ?>
        <div class="prov-card <?= !$p['activo'] ? 'inactivo' : '' ?>">
            <div class="prov-nombre"><?= htmlspecialchars($p['nombre']) ?></div>
            <span class="prov-cat" style="background:<?= $bgC ?>;color:<?= $txC ?>">
                <?= htmlspecialchars($catL) ?>
            </span>
            <div class="prov-info">
                <?php if (!empty($p['contacto'])): ?>
                <div><strong>Contacto:</strong> <?= htmlspecialchars($p['contacto']) ?></div>
                <?php endif; ?>
                <?php if (!empty($p['telefono'])):
                    $tel_pn = preg_replace('/[^0-9]/', '', $p['telefono']);
                    $tel_pw = (strlen($tel_pn) === 10 && str_starts_with($tel_pn, '3')) ? '57'.$tel_pn : $tel_pn;
                ?>
                <div>
                    <strong>Tel:</strong> <?= htmlspecialchars($p['telefono']) ?>
                    <?php if ($tel_pw): ?>
                    &nbsp;<a href="https://wa.me/<?= $tel_pw ?>" target="_blank" rel="noopener noreferrer"
                             title="Contactar por WhatsApp"
                             style="color:#25d366;font-weight:700;text-decoration:none;font-size:11px;display:inline-flex;align-items:center;gap:2px;vertical-align:middle">
                        <?= IC_WA ?> WA
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <?php if ($tieneEmail && !empty($p['email'])): ?>
                <div><strong>Email:</strong> <?= htmlspecialchars($p['email']) ?></div>
                <?php endif; ?>
                <?php if ($tieneDir && !empty($p['direccion'])): ?>
                <div><strong>Dir:</strong> <?= htmlspecialchars(mb_substr($p['direccion'],0,50)) ?></div>
                <?php endif; ?>
                <?php if ($tieneWeb && !empty($p['sitio_web'])): ?>
                <div><strong>Web:</strong> <?= htmlspecialchars($p['sitio_web']) ?></div>
                <?php endif; ?>
                <?php if (!empty($p['notas'])): ?>
                <div style="margin-top:4px;color:var(--g5)">&#128221; <?= htmlspecialchars(mb_substr($p['notas'],0,60)) ?></div>
                <?php endif; ?>
            </div>
            <div class="prov-insumos">
                <?= (int)$p['num_insumos'] ?> insumo<?= $p['num_insumos'] != 1 ? 's' : '' ?> asociado<?= $p['num_insumos'] != 1 ? 's' : '' ?>
            </div>
            <?php if (permiso_tiene('proveedores','editar_existentes')): ?>
            <div class="prov-actions">
                <button class="btn-edit ic ic-edit" title="Editar" onclick="abrirEditar(<?= htmlspecialchars(json_encode($p)) ?>)">
                    <?= IC_EDIT ?>
                </button>
                <?php if (permiso_tiene('proveedores','admin_total')): ?>
                <button class="btn-toggle ic <?= $p['activo'] ? 'ic-warn' : 'ic-ok' ?>"
                        title="<?= $p['activo'] ? 'Desactivar' : 'Activar' ?>"
                        onclick="toggleProv(<?= $p['id'] ?>, this)">
                    <?= $p['activo'] ? IC_PAUSE : IC_PLAY ?>
                </button>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</main>

<!-- ══ MODAL: NUEVO / EDITAR PROVEEDOR ═══════════════════════════════════════ -->
<div class="overlay" id="modal-prov" onclick="if(event.target===this)cerrar()">
  <div class="modal">
    <div class="modal-hdr">
      <span id="mp-titulo">Nuevo Proveedor</span>
      <button class="btn-cls" onclick="cerrar()">&#x2715;</button>
    </div>
    <input type="hidden" id="mp-id" value="">

    <div class="fg"><label>Nombre *</label>
      <input id="mp-nombre" placeholder="Ej: Plaza Minorista, D1, Alkosto..."></div>

    <div class="fg"><label>Categoría</label>
      <select id="mp-cat">
        <?php foreach ($CAT_LABELS as $k => $v): ?>
        <option value="<?= $k ?>"><?= htmlspecialchars($v) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="form-grid">
      <div class="fg"><label>Persona de contacto</label>
        <input id="mp-contacto" placeholder="Ej: Doña Carmen"></div>
      <div class="fg"><label>Teléfono / WhatsApp</label>
        <input id="mp-tel" type="tel" placeholder="300 123 4567"></div>
      <?php if ($tieneEmail): ?>
      <div class="fg"><label>Correo electrónico</label>
        <input id="mp-email" type="email" placeholder="proveedor@email.com"></div>
      <?php endif; ?>
      <?php if ($tieneWeb): ?>
      <div class="fg"><label>Sitio web</label>
        <input id="mp-web" placeholder="www.proveedor.com"></div>
      <?php endif; ?>
    </div>

    <?php if ($tieneDir): ?>
    <div class="fg"><label>Dirección</label>
      <input id="mp-dir" placeholder="Carrera 50 #10-20, Local 115"></div>
    <?php endif; ?>

    <div class="fg"><label>Notas</label>
      <textarea id="mp-notas" rows="2"
        placeholder="Horarios, productos principales, condiciones de pago, plazos..."></textarea>
    </div>

    <button class="btn-submit" onclick="guardar()">Guardar Proveedor</button>
  </div>
</div>

<div class="toast" id="toast"></div>
<input type="hidden" id="csrf-tk" value="<?= htmlspecialchars(csrf_token()) ?>">

<script>
var csrf = () => document.getElementById('csrf-tk').value;
var HAS_EMAIL = <?= $tieneEmail ? 'true' : 'false' ?>;
var HAS_WEB   = <?= $tieneWeb   ? 'true' : 'false' ?>;
var HAS_DIR   = <?= $tieneDir   ? 'true' : 'false' ?>;

function abrirNuevo() {
    document.getElementById('mp-titulo').textContent = 'Nuevo Proveedor';
    document.getElementById('mp-id').value = '';
    ['mp-nombre','mp-contacto','mp-tel','mp-notas'].forEach(id => {
        var el = document.getElementById(id); if (el) el.value = '';
    });
    if (HAS_EMAIL) { var e = document.getElementById('mp-email'); if (e) e.value = ''; }
    if (HAS_WEB)   { var e = document.getElementById('mp-web');   if (e) e.value = ''; }
    if (HAS_DIR)   { var e = document.getElementById('mp-dir');   if (e) e.value = ''; }
    document.getElementById('mp-cat').value = 'otro';
    document.getElementById('modal-prov').classList.add('on');
    document.getElementById('mp-nombre').focus();
}

function abrirEditar(p) {
    document.getElementById('mp-titulo').textContent = 'Editar: ' + p.nombre;
    document.getElementById('mp-id').value         = p.id;
    document.getElementById('mp-nombre').value     = p.nombre    || '';
    document.getElementById('mp-cat').value        = p.categoria || 'otro';
    document.getElementById('mp-contacto').value   = p.contacto  || '';
    document.getElementById('mp-tel').value        = p.telefono  || '';
    document.getElementById('mp-notas').value      = p.notas     || '';
    if (HAS_EMAIL) { var e = document.getElementById('mp-email'); if (e) e.value = p.email     || ''; }
    if (HAS_WEB)   { var e = document.getElementById('mp-web');   if (e) e.value = p.sitio_web || ''; }
    if (HAS_DIR)   { var e = document.getElementById('mp-dir');   if (e) e.value = p.direccion || ''; }
    document.getElementById('modal-prov').classList.add('on');
}

function cerrar() { document.getElementById('modal-prov').classList.remove('on'); }
document.addEventListener('keydown', e => { if (e.key==='Escape') cerrar(); });

async function guardar() {
    var nombre = document.getElementById('mp-nombre').value.trim();
    if (!nombre) { toast('El nombre es obligatorio', 'err'); return; }

    var id = document.getElementById('mp-id').value;
    var fd = new FormData();
    fd.append('csrf_token', csrf());
    fd.append('accion',     id ? 'editar' : 'crear');
    if (id) fd.append('id', id);
    fd.append('nombre',     nombre);
    fd.append('categoria',  document.getElementById('mp-cat').value);
    fd.append('contacto',   document.getElementById('mp-contacto').value);
    fd.append('telefono',   document.getElementById('mp-tel').value);
    fd.append('notas',      document.getElementById('mp-notas').value);
    if (HAS_EMAIL) fd.append('email',     document.getElementById('mp-email')?.value || '');
    if (HAS_WEB)   fd.append('sitio_web', document.getElementById('mp-web')?.value   || '');
    if (HAS_DIR)   fd.append('direccion', document.getElementById('mp-dir')?.value   || '');

    var r = await fetch('api/crud.php', {method:'POST', body:fd});
    var d = await r.json();
    if (d.success) {
        cerrar();
        toast(id ? 'Proveedor actualizado' : 'Proveedor creado: ' + nombre, 'ok');
        setTimeout(() => location.reload(), 900);
    } else toast(d.error || 'Error', 'err');
}

async function toggleProv(id, btn) {
    var activo = btn.classList.contains('btn-desa'); // si es "Desactivar" = actualmente activo
    if (!confirm((activo ? '¿Desactivar' : '¿Activar') + ' este proveedor?')) return;
    var fd = new FormData();
    fd.append('csrf_token', csrf());
    fd.append('accion', 'toggle');
    fd.append('id', id);
    var r = await fetch('api/crud.php', {method:'POST', body:fd});
    var d = await r.json();
    if (d.success) {
        toast('Estado actualizado', 'ok');
        setTimeout(() => location.reload(), 700);
    } else toast(d.error || 'Error', 'err');
}

var _tt;
function toast(m, t) {
    var el = document.getElementById('toast');
    el.textContent = m; el.className = 'toast toast-' + t + ' on';
    clearTimeout(_tt); _tt = setTimeout(() => el.classList.remove('on'), 3200);
}
</script>
</body>
</html>
