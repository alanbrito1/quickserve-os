<?php
/**
 * admin/usuarios.php — Gestión de usuarios del sistema.
 * Crear, editar, activar/desactivar y asignar permisos por módulo.
 */
require_once __DIR__ . '/../app/middleware/auth_check.php';

$nav_activo = 'admin';
$nav_sub    = 'usuarios';

if (!in_array($_SESSION['usuario_rol'] ?? '', ['superadmin','admin'], true)) {
    http_response_code(403); include __DIR__ . '/../app/views/errors/403.php'; exit;
}

$msg_ok  = '';
$msg_err = '';

// ── Módulos disponibles (para la matriz de permisos) ─────────────────────────
$MODULOS = [
    'ventas'      => 'Ventas / POS',
    'inventario'  => 'Inventario',
    'proveedores' => 'Proveedores',
    'compras'     => 'Compras',
    'productos'   => 'Productos / Recetas',
    'nomina'      => 'Nómina',
    'activos'     => 'Activos',
    'costos'      => 'Costos',
    'reportes'    => 'Reportes',
];

$NIVELES = [
    'sin_acceso'        => 'Sin acceso',
    'solo_ver'          => 'Solo ver',
    'solo_propios'      => 'Solo propios',
    'editar_existentes' => 'Editar',
    'admin_total'       => 'Admin total',
];

// ── Cargar usuarios ───────────────────────────────────────────────────────────
$usuarios = db()->query(
    "SELECT u.id, u.nombre, u.email, u.rol, u.activo, u.ultimo_login,
            COUNT(pm.id) AS n_permisos
     FROM usuarios u
     LEFT JOIN permisos_modulos pm ON pm.usuario_id = u.id AND pm.nivel_acceso != 'sin_acceso'
     GROUP BY u.id
     ORDER BY u.activo DESC, u.rol, u.nombre"
)->fetchAll();

// Cargar permisos de todos los usuarios en un array indexado
$permisos_todos = db()->query(
    'SELECT usuario_id, modulo, nivel_acceso FROM permisos_modulos'
)->fetchAll();
$permisos_idx = [];
foreach ($permisos_todos as $p) {
    $permisos_idx[$p['usuario_id']][$p['modulo']] = $p['nivel_acceso'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Usuarios — <?= APP_NAME ?></title>
    <style>
        :root { --brand:#e94f37; --dark:#111827; --g5:#6b7280; --g8:#d1d5db; --g9:#f3f4f6; --white:#fff; --green:#059669; }
        *,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
        body { font-family:system-ui,-apple-system,sans-serif; background:var(--g9); color:var(--dark); }
        .main { max-width:1000px; margin:0 auto; padding:20px 14px 60px; }
        .page-hdr { display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:10px; }
        .page-title { font-size:22px; font-weight:800; }
        .btn-primary { background:var(--brand); color:#fff; border:none; padding:9px 18px; border-radius:8px; font-size:13px; font-weight:700; cursor:pointer; }
        .btn-primary:hover { background:#c94330; }
        .alert-ok  { background:#d1fae5; color:#065f46; border:1px solid #6ee7b7; padding:12px 14px; border-radius:10px; margin-bottom:14px; font-size:14px; }
        .alert-err { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; padding:12px 14px; border-radius:10px; margin-bottom:14px; font-size:14px; }

        /* Tabla */
        .tbl-wrap { background:var(--white); border:1px solid var(--g8); border-radius:12px; overflow:hidden; overflow-x:auto; }
        .tbl { width:100%; border-collapse:collapse; }
        .tbl thead th { background:var(--g9); font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; padding:10px 14px; text-align:left; color:var(--g5); }
        .tbl tbody tr { border-bottom:1px solid var(--g9); }
        .tbl tbody tr:last-child { border-bottom:none; }
        .tbl tbody tr:hover { background:#fafafa; }
        .tbl tbody tr.inactivo td { opacity:.5; }
        .tbl td { padding:12px 14px; font-size:14px; vertical-align:middle; }
        .badge { display:inline-block; font-size:11px; font-weight:700; padding:2px 8px; border-radius:999px; }
        .b-super { background:#fef3c7; color:#92400e; }
        .b-admin { background:#dbeafe; color:#1d4ed8; }
        .b-emp   { background:var(--g9); color:var(--g5); }
        .b-act   { background:#d1fae5; color:#065f46; }
        .b-ina   { background:#fee2e2; color:#991b1b; }
        .btn-tbl { border:none; padding:5px 10px; border-radius:6px; font-size:12px; font-weight:600; cursor:pointer; margin-left:3px; }
        .btn-edit { background:var(--g9); color:var(--dark); }
        .btn-toggle-d { background:#fef3c7; color:#92400e; }
        .btn-toggle-a { background:#d1fae5; color:#065f46; }

        /* Modal */
        .overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:200; align-items:flex-start; justify-content:center; padding:20px; overflow-y:auto; }
        .overlay.on { display:flex; }
        .modal { background:var(--white); border-radius:14px; width:100%; max-width:700px; margin:auto; padding:24px; box-shadow:0 20px 60px rgba(0,0,0,.2); }
        .modal-hdr { display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; }
        .modal-hdr span { font-size:17px; font-weight:700; }
        .btn-cls { background:var(--g9); border:none; width:30px; height:30px; border-radius:50%; font-size:14px; cursor:pointer; color:var(--g5); }
        .form-section { font-size:11px; font-weight:700; color:var(--g5); text-transform:uppercase; letter-spacing:.5px; margin:16px 0 10px; border-bottom:1px solid var(--g8); padding-bottom:6px; }
        .form-grid2 { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        @media(max-width:480px) { .form-grid2 { grid-template-columns:1fr; } }
        .fg { display:flex; flex-direction:column; gap:4px; margin-bottom:10px; }
        .fg label { font-size:12px; font-weight:600; color:var(--g5); }
        .fg input, .fg select { padding:9px 10px; border:1px solid var(--g8); border-radius:8px; font-size:14px; color:var(--dark); outline:none; }
        .fg input:focus, .fg select:focus { border-color:var(--brand); }
        .fg .hint { font-size:11px; color:var(--g5); }

        /* Matriz de permisos */
        .perm-matrix { width:100%; border-collapse:collapse; font-size:12px; }
        .perm-matrix th { background:var(--g9); padding:6px 10px; text-align:left; font-weight:700; color:var(--g5); font-size:11px; text-transform:uppercase; }
        .perm-matrix td { padding:5px 10px; border-top:1px solid var(--g9); }
        .perm-matrix td:first-child { font-weight:600; width:150px; }
        .perm-radio { display:flex; gap:10px; flex-wrap:wrap; }
        .perm-radio label { display:flex; align-items:center; gap:4px; cursor:pointer; font-size:12px; }
        .perm-radio input[type=radio] { accent-color:var(--brand); }

        .btn-submit { width:100%; margin-top:18px; background:var(--brand); color:#fff; border:none; padding:11px; border-radius:10px; font-size:14px; font-weight:700; cursor:pointer; }
        .btn-submit:hover { background:#c94330; }

        /* Toast */
        .toast { position:fixed; bottom:20px; left:50%; transform:translateX(-50%) translateY(20px); padding:10px 20px; border-radius:24px; font-size:14px; font-weight:600; opacity:0; transition:.25s; z-index:999; pointer-events:none; white-space:nowrap; }
        .toast.on  { opacity:1; transform:translateX(-50%) translateY(0); }
        .toast-ok  { background:#065f46; color:#d1fae5; }
        .toast-err { background:#991b1b; color:#fee2e2; }

        /* ════════════════════════════════════════════════════════════════
           RESPONSIVE — ADMIN USUARIOS
           ════════════════════════════════════════════════════════════════ */
        /* Tabla principal y matriz de permisos con scroll horizontal */
        .tbl-wrap { overflow-x:auto; -webkit-overflow-scrolling:touch; }

        /* xs: < 480px */
        @media (max-width:479px) {
            .main { padding:12px 10px 40px; }
            .page-hdr { flex-direction:column; align-items:stretch; }
            .btn-primary { width:100%; min-height:44px; }
            /* Tabla scroll */
            .tbl { min-width:460px; }
            /* Modal bottom-sheet */
            .overlay { align-items:flex-end !important; padding:0 !important; }
            .modal { border-radius:16px 16px 0 0 !important; max-width:100% !important; max-height:92vh !important; }
            /* Matriz permisos en scroll */
            .perm-matrix { min-width:480px; }
            /* Radio permisos 2 por fila en xs */
            .perm-radio { gap:6px; }
            .perm-radio label { font-size:11px; }
        }
        /* sm: 480-639px */
        @media (min-width:480px) and (max-width:639px) {
            .tbl { min-width:520px; }
        }
        /* md: modal centrado en tablet */
        @media (min-width:640px) and (max-width:1023px) {
            .overlay { align-items:center !important; }
            .modal { max-width:600px !important; }
        }
        /* ≥1600px */
        @media (min-width:1600px) {
            .main { max-width:1300px; }
            .tbl thead th, .tbl td { padding:12px 16px !important; font-size:14px !important; }
            .modal { max-width:800px !important; }
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

    <div class="page-hdr">
        <h1 class="page-title">Usuarios (<?= count($usuarios) ?>)</h1>
        <button class="btn-primary" onclick="abrirNuevo()">+ Nuevo Usuario</button>
    </div>

    <?php if ($msg_ok):  ?><div class="alert-ok"><?= htmlspecialchars($msg_ok) ?></div><?php endif; ?>
    <?php if ($msg_err): ?><div class="alert-err"><?= htmlspecialchars($msg_err) ?></div><?php endif; ?>

    <div class="tbl-wrap">
        <table class="tbl">
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th>Email</th>
                    <th>Rol</th>
                    <th>Módulos con acceso</th>
                    <th>Último acceso</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($usuarios as $u):
                $rolBadge = ['superadmin'=>'b-super','admin'=>'b-admin','empleado'=>'b-emp'][$u['rol']] ?? 'b-emp';
                $esSelf   = (int)$u['id'] === (int)($_SESSION['usuario_id'] ?? 0);
            ?>
            <tr class="<?= !$u['activo'] ? 'inactivo' : '' ?>">
                <td>
                    <strong><?= htmlspecialchars($u['nombre']) ?></strong>
                    <?php if ($esSelf): ?>
                    <span style="font-size:11px;background:var(--g9);padding:1px 6px;border-radius:20px;margin-left:4px">Tú</span>
                    <?php endif; ?>
                </td>
                <td style="color:var(--g5);font-size:13px"><?= htmlspecialchars($u['email']) ?></td>
                <td><span class="badge <?= $rolBadge ?>"><?= ucfirst($u['rol']) ?></span></td>
                <td style="color:var(--g5);font-size:13px">
                    <?= $u['rol'] === 'superadmin' ? 'Todos (superadmin)' : ((int)$u['n_permisos'] . '/' . count($MODULOS) . ' módulos') ?>
                </td>
                <td style="color:var(--g5);font-size:12px">
                    <?= $u['ultimo_login'] ? date('d/m/Y H:i', strtotime($u['ultimo_login'])) : '—' ?>
                </td>
                <td><span class="badge <?= $u['activo'] ? 'b-act' : 'b-ina' ?>"><?= $u['activo'] ? 'Activo' : 'Inactivo' ?></span></td>
                <td>
                    <button class="btn-tbl ic btn-edit" title="Editar"
                            onclick="abrirEditar(<?= htmlspecialchars(json_encode([
                                'id'     => $u['id'],
                                'nombre' => $u['nombre'],
                                'email'  => $u['email'],
                                'rol'    => $u['rol'],
                                'permisos' => $permisos_idx[$u['id']] ?? [],
                            ])) ?>)">
                        <?= IC_EDIT ?>
                    </button>
                    <?php if (!$esSelf): ?>
                    <button class="btn-tbl ic <?= $u['activo'] ? 'btn-toggle-d' : 'btn-toggle-a' ?>"
                            title="<?= $u['activo'] ? 'Desactivar' : 'Activar' ?>"
                            onclick="toggleUsuario(<?= $u['id'] ?>, <?= (int)$u['activo'] ?>, '<?= htmlspecialchars(addslashes($u['nombre'])) ?>')">
                        <?= $u['activo'] ? IC_PAUSE : IC_PLAY ?>
                    </button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</main>

<!-- CSRF -->
<input type="hidden" id="csrf-tk" value="<?= htmlspecialchars(csrf_token()) ?>">

<!-- Modal Crear / Editar Usuario -->
<div class="overlay" id="modal-u" onclick="if(event.target===this)cerrar()">
  <div class="modal">
    <div class="modal-hdr">
      <span id="mu-titulo">Nuevo Usuario</span>
      <button class="btn-cls" onclick="cerrar()">&#x2715;</button>
    </div>
    <input type="hidden" id="mu-id">

    <p class="form-section">Datos del usuario</p>
    <div class="form-grid2">
        <div class="fg"><label>Nombre completo *</label>
            <input id="mu-nombre" placeholder="Nombre y apellidos" maxlength="100"></div>
        <div class="fg"><label>Correo electrónico *</label>
            <input id="mu-email" type="email" placeholder="correo@ejemplo.com"></div>
        <div class="fg"><label>Contraseña</label>
            <input id="mu-pass" type="password" placeholder="Dejar vacío = no cambiar (al editar)">
            <span class="hint" id="mu-pass-hint">Mínimo 6 caracteres</span></div>
        <div class="fg"><label>Rol *</label>
            <select id="mu-rol">
                <option value="empleado">Empleado — acceso según permisos</option>
                <option value="admin">Admin — acceso según permisos</option>
                <?php if (($_SESSION['usuario_rol'] ?? '') === 'superadmin'): ?>
                <option value="superadmin">Superadmin — acceso total</option>
                <?php endif; ?>
            </select>
            <span class="hint">Superadmin bypassa todos los permisos automáticamente.</span>
        </div>
    </div>

    <p class="form-section">Permisos por módulo</p>
    <div id="mu-permisos-wrap">
        <table class="perm-matrix">
            <thead>
                <tr>
                    <th>Módulo</th>
                    <?php foreach ($NIVELES as $nk => $nl): ?>
                    <th style="text-align:center"><?= htmlspecialchars($nl) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($MODULOS as $modKey => $modLabel): ?>
            <tr>
                <td><?= htmlspecialchars($modLabel) ?></td>
                <?php foreach ($NIVELES as $nk => $nl): ?>
                <td style="text-align:center">
                    <input type="radio"
                           name="perm_<?= $modKey ?>"
                           value="<?= $nk ?>"
                           <?= $nk === 'sin_acceso' ? 'checked' : '' ?>
                           style="accent-color:var(--brand)">
                </td>
                <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <button class="btn-submit" onclick="guardarUsuario()">Guardar Usuario</button>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
const MODULOS = <?= json_encode(array_keys($MODULOS)) ?>;
var csrf = function(){ return document.getElementById('csrf-tk').value; };
var _tt;
function toast(m,t){
    var el=document.getElementById('toast'); el.textContent=m;
    el.className='toast toast-'+t+' on';
    clearTimeout(_tt); _tt=setTimeout(function(){ el.classList.remove('on'); },3500);
}
function cerrar(){ document.getElementById('modal-u').classList.remove('on'); }
document.addEventListener('keydown', function(e){ if(e.key==='Escape') cerrar(); });

function resetPermisos(){
    MODULOS.forEach(function(m){
        var r=document.querySelector('input[name="perm_'+m+'"][value="sin_acceso"]');
        if(r) r.checked=true;
    });
}

function abrirNuevo(){
    document.getElementById('mu-titulo').textContent = 'Nuevo Usuario';
    document.getElementById('mu-id').value    = '';
    document.getElementById('mu-nombre').value = '';
    document.getElementById('mu-email').value  = '';
    document.getElementById('mu-pass').value   = '';
    document.getElementById('mu-rol').value    = 'empleado';
    document.getElementById('mu-pass-hint').textContent = 'Requerida para nuevo usuario';
    resetPermisos();
    document.getElementById('modal-u').classList.add('on');
    setTimeout(function(){ document.getElementById('mu-nombre').focus(); }, 100);
}

function abrirEditar(u){
    document.getElementById('mu-titulo').textContent = 'Editar: ' + u.nombre;
    document.getElementById('mu-id').value    = u.id;
    document.getElementById('mu-nombre').value = u.nombre || '';
    document.getElementById('mu-email').value  = u.email  || '';
    document.getElementById('mu-pass').value   = '';
    document.getElementById('mu-rol').value    = u.rol    || 'empleado';
    document.getElementById('mu-pass-hint').textContent = 'Dejar vacío para no cambiar la contraseña';
    resetPermisos();
    // Cargar permisos actuales
    var perms = u.permisos || {};
    MODULOS.forEach(function(m){
        var nivel = perms[m] || 'sin_acceso';
        var r = document.querySelector('input[name="perm_'+m+'"][value="'+nivel+'"]');
        if(r) r.checked = true;
    });
    document.getElementById('modal-u').classList.add('on');
}

async function guardarUsuario(){
    var id     = document.getElementById('mu-id').value;
    var nombre = document.getElementById('mu-nombre').value.trim();
    var email  = document.getElementById('mu-email').value.trim();
    var pass   = document.getElementById('mu-pass').value;
    var rol    = document.getElementById('mu-rol').value;

    if(!nombre){ toast('El nombre es obligatorio.','err'); return; }
    if(!email)  { toast('El correo es obligatorio.','err'); return; }
    if(!id && !pass){ toast('La contraseña es obligatoria para nuevos usuarios.','err'); return; }
    if(pass && pass.length < 6){ toast('La contraseña debe tener al menos 6 caracteres.','err'); return; }

    var fd = new FormData();
    fd.append('csrf_token', csrf());
    fd.append('accion',     id ? 'editar' : 'crear');
    if(id)  fd.append('id', id);
    fd.append('nombre',  nombre);
    fd.append('email',   email);
    if(pass) fd.append('password', pass);
    fd.append('rol',     rol);

    // Permisos
    MODULOS.forEach(function(m){
        var r=document.querySelector('input[name="perm_'+m+'"]:checked');
        fd.append('perm_'+m, r ? r.value : 'sin_acceso');
    });

    try {
        var r=await fetch('api/usuario_crud.php', {method:'POST',body:fd});
        var d=await r.json();
        if(d.success){
            cerrar();
            toast(id ? 'Usuario actualizado.' : 'Usuario creado.','ok');
            setTimeout(function(){ location.reload(); },900);
        } else toast(d.error||'Error al guardar.','err');
    } catch(e){ toast('Error de conexión.','err'); }
}

async function toggleUsuario(id, activo, nombre){
    var msg = activo ? '¿Desactivar a "'+nombre+'"? Ya no podrá ingresar al sistema.' : '¿Activar a "'+nombre+'"?';
    if(!confirm(msg)) return;

    var fd=new FormData();
    fd.append('csrf_token',csrf());
    fd.append('accion','toggle');
    fd.append('id',id);

    try {
        var r=await fetch('api/usuario_crud.php',{method:'POST',body:fd});
        var d=await r.json();
        if(d.success){ toast('Estado actualizado.','ok'); setTimeout(function(){ location.reload(); },700); }
        else toast(d.error||'Error.','err');
    } catch(e){ toast('Error de conexión.','err'); }
}
</script>
</body>
</html>
