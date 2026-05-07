<?php
/**
 * nomina/parametros.php
 * Panel de configuración de parámetros laborales por país.
 * Permite modificar % de prestaciones, bases, topes para cualquier país.
 */
require_once __DIR__ . '/../app/middleware/auth_check.php';
require_once __DIR__ . '/../app/models/NominaModel.php';

$nav_activo = 'nomina';
permiso_requerir('nomina', 'admin_total');

$pais   = $_GET['pais'] ?? 'Colombia';
$paises = NominaModel::paises_disponibles();
if (!in_array($pais, $paises, true)) $pais = 'Colombia';

$params_grouped = NominaModel::parametros_pais($pais);

$CAT_LABELS = [
    'base'              => 'Bases y valores fijos',
    'carga_parafiscal'  => 'Cargas Parafiscales (empleador)',
    'provision'         => 'Provisiones Mensuales (empleador)',
    'descuento_empleado'=> 'Descuentos al Empleado',
    'tope'              => 'Topes y Límites',
    'horas_jornada'     => 'Horas de Jornada y Recargos',   // ← migración 008
];
$CAT_DESC = [
    'base'              => 'SMLMV, auxilio de transporte, horas estándar.',
    'carga_parafiscal'  => 'Se pagan mensualmente sobre el salario base.',
    'provision'         => 'Se reservan cada mes para pagar prima, cesantías, vacaciones.',
    'descuento_empleado'=> 'Se descuentan del salario neto del empleado.',
    'tope'              => 'Umbrales que determinan cuándo aplica cada beneficio.',
    'horas_jornada'     => 'Jornada laboral (Ley 2101/2021) y recargos nocturnos/extras (Art. 168-172 CST).',
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Parámetros Laborales — <?= APP_NAME ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root { --brand:#e94f37; --dark:#111827; --g2:#374151; --g5:#6b7280; --g8:#d1d5db; --g9:#f3f4f6; --white:#fff; --green:#059669; }
        body { font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; background:var(--g9); min-height:100vh; color:var(--dark); padding-bottom:40px; }
        .main { padding:16px 14px; max-width:900px; margin:0 auto; }
        .top-bar { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:16px; }
        .page-title { font-size:20px; font-weight:800; }
        .pais-row { display:flex; gap:8px; align-items:center; }
        .pais-row select { padding:8px 12px; border:2px solid var(--g8); border-radius:10px; font-size:14px; outline:none; }
        .pais-row select:focus { border-color:var(--brand); }
        .btn-nuevo { padding:9px 16px; background:var(--brand); color:#fff; border:none; border-radius:10px; font-size:13px; font-weight:700; cursor:pointer; }
        .aviso { background:#fef3c7; color:#92400e; border:1px solid #fcd34d; border-radius:12px; padding:12px 16px; font-size:13px; margin-bottom:16px; }
        /* Sección por categoría */
        .cat-block { background:var(--white); border-radius:14px; box-shadow:0 1px 4px rgba(0,0,0,.06); margin-bottom:14px; overflow:hidden; }
        .cat-hdr { padding:12px 18px; border-bottom:1px solid var(--g9); }
        .cat-title { font-size:14px; font-weight:800; }
        .cat-sub   { font-size:12px; color:var(--g5); margin-top:2px; }
        table { width:100%; border-collapse:collapse; }
        th { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; color:var(--g5); padding:8px 14px; background:var(--g9); border-bottom:1px solid var(--g8); text-align:left; }
        td { padding:10px 14px; border-bottom:1px solid var(--g9); font-size:13px; vertical-align:middle; }
        tr:last-child td { border-bottom:none; }
        /* Input editable inline */
        .val-inp { padding:6px 10px; border:2px solid var(--g8); border-radius:8px; font-size:14px; font-weight:700; width:100px; text-align:right; outline:none; color:var(--dark); }
        .val-inp:focus { border-color:var(--brand); }
        .btn-save-row { padding:5px 12px; background:var(--green); color:#fff; border:none; border-radius:7px; font-size:12px; font-weight:700; cursor:pointer; }
        .btn-del-row  { padding:5px 10px; background:#fee2e2; color:#991b1b; border:none; border-radius:7px; font-size:12px; font-weight:600; cursor:pointer; }
        .saved-ok { color:var(--green); font-size:12px; font-weight:700; display:none; }
        /* Toggle activo */
        .toggle { display:flex; align-items:center; gap:6px; cursor:pointer; }
        .toggle input { width:14px; height:14px; cursor:pointer; }
        /* Contratos badges */
        .ctr-badge { font-size:9px; padding:1px 6px; border-radius:20px; background:var(--g9); color:var(--g2); margin:1px; display:inline-block; }
        /* Modal nuevo parámetro */
        .overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:60; align-items:center; justify-content:center; padding:16px; }
        .overlay.on { display:flex; }
        .modal { background:var(--white); border-radius:16px; padding:22px; width:100%; max-width:500px; max-height:90vh; overflow-y:auto; }
        .modal-hdr { font-size:16px; font-weight:800; margin-bottom:14px; display:flex; justify-content:space-between; }
        .btn-cls { background:var(--g9); border:none; color:var(--g5); width:28px; height:28px; border-radius:50%; cursor:pointer; font-size:15px; }
        .fg { display:flex; flex-direction:column; gap:4px; margin-bottom:10px; }
        .fg label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--g5); }
        .fg input,.fg select,.fg textarea { padding:9px 11px; border:2px solid var(--g8); border-radius:9px; font-size:14px; color:var(--dark); outline:none; width:100%; }
        .fg input:focus,.fg select:focus { border-color:var(--brand); }
        .btn-submit { width:100%; padding:11px; background:var(--brand); color:#fff; border:none; border-radius:10px; font-size:14px; font-weight:700; cursor:pointer; margin-top:6px; }
        .toast { position:fixed; bottom:20px; left:50%; transform:translateX(-50%) translateY(20px); padding:10px 20px; border-radius:24px; font-size:14px; font-weight:600; opacity:0; transition:.25s; z-index:99; pointer-events:none; }
        .toast.on { opacity:1; transform:translateX(-50%) translateY(0); }
        .toast-ok  { background:#065f46; color:#d1fae5; }
        .toast-err { background:#991b1b; color:#fee2e2; }


    </style>
</head>
<body>
<?php $nav_sub = 'parametros'; include __DIR__ . '/../app/views/nav.php'; ?>
<main class="main">


    <div class="top-bar">
        <div>
            <h1 class="page-title">Parámetros Laborales</h1>
            <p style="font-size:13px;color:var(--g5);margin-top:4px">
                Modifica los % de acuerdo a la legislación vigente de cada país.
            </p>
        </div>
        <div class="pais-row">
            <span style="font-size:12px;font-weight:700;color:var(--g5)">País:</span>
            <form method="GET" style="display:flex;gap:8px">
                <select name="pais" onchange="this.form.submit()">
                    <?php foreach ($paises as $p): ?>
                    <option value="<?= htmlspecialchars($p) ?>" <?= $p===$pais?'selected':'' ?>>
                        <?= htmlspecialchars($p) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </form>
            <button class="btn-nuevo" onclick="abrirModal('modal-nuevo')">+ Nuevo parámetro</button>
        </div>
    </div>

    <div class="aviso">
        <strong>Importante:</strong> Los cambios aquí afectan los cálculos de <strong>todas las liquidaciones futuras</strong>.
        Las liquidaciones ya generadas conservan los valores históricos.
        Para Colombia 2026 estos valores ya están precargados según la normativa vigente.
    </div>

    <?php foreach ($CAT_LABELS as $cat => $catLabel): ?>
    <?php if (empty($params_grouped[$cat])) continue; ?>
    <div class="cat-block">
        <div class="cat-hdr">
            <div class="cat-title"><?= $catLabel ?></div>
            <div class="cat-sub"><?= $CAT_DESC[$cat] ?? '' ?></div>
        </div>
        <table>
            <thead><tr>
                <th>Parámetro</th>
                <th style="text-align:right">Valor</th>
                <th>Tipo</th>
                <th>Aplica a</th>
                <th>Contratos</th>
                <th>Activo</th>
                <th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($params_grouped[$cat] as $param): ?>
            <tr id="row-<?= $param['id'] ?>">
                <td>
                    <strong><?= htmlspecialchars($param['nombre']) ?></strong>
                    <?php if ($param['descripcion']): ?>
                    <br><small style="color:var(--g5)"><?= htmlspecialchars(substr($param['descripcion'],0,80)) ?></small>
                    <?php endif; ?>
                    <br><code style="font-size:10px;color:var(--g5)"><?= htmlspecialchars($param['clave']) ?></code>
                </td>
                <td style="text-align:right">
                    <input type="number"
                           class="val-inp"
                           id="val-<?= $param['id'] ?>"
                           value="<?= $param['valor'] ?>"
                           step="<?= $param['tipo'] === 'porcentaje' ? '0.001' : '1' ?>">
                    <span style="font-size:11px;color:var(--g5)"><?= $param['tipo'] === 'porcentaje' ? '%' : 'COP' ?></span>
                </td>
                <td>
                    <span style="font-size:11px"><?= $param['tipo'] === 'porcentaje' ? 'Porcentaje' : 'Valor fijo' ?></span>
                </td>
                <td>
                    <?php $ap = $param['aplica_a'];
                    $apColor = $ap==='empleador' ? '#0369a1' : ($ap==='empleado' ? '#7c3aed' : '#065f46'); ?>
                    <span style="font-size:11px;font-weight:700;color:<?= $apColor ?>"><?= ucfirst($ap) ?></span>
                </td>
                <td>
                    <?php foreach (explode(',', $param['aplica_contratos']) as $ct): ?>
                    <span class="ctr-badge"><?= trim($ct) ?></span>
                    <?php endforeach; ?>
                </td>
                <td>
                    <label class="toggle">
                        <input type="checkbox" id="act-<?= $param['id'] ?>"
                               <?= $param['activo'] ? 'checked' : '' ?>>
                        <span style="font-size:12px"><?= $param['activo'] ? 'Sí' : 'No' ?></span>
                    </label>
                </td>
                <td style="display:flex;gap:6px;align-items:center;flex-wrap:nowrap">
                    <button class="btn-save-row"
                            onclick="abrirEditarParam(<?= htmlspecialchars(json_encode($param), ENT_QUOTES) ?>)">
                        Editar
                    </button>
                    <button class="btn-del-row"
                            onclick="eliminarParam(<?= $param['id'] ?>, <?= json_encode($param['nombre']) ?>)">
                        ✕
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endforeach; ?>

    <?php if (empty($params_grouped)): ?>
    <div style="text-align:center;padding:48px;color:var(--g5)">
        <p style="font-size:28px;margin-bottom:10px">⚙</p>
        <p style="font-weight:700">Sin parámetros para <?= htmlspecialchars($pais) ?></p>
        <p style="font-size:13px;margin-top:6px">
            Ejecuta la migración 007 en phpMyAdmin para cargar los parámetros de Colombia,
            o agrega parámetros manualmente con el botón "Nuevo parámetro".
        </p>
    </div>
    <?php endif; ?>

</main>

<!-- Modal EDITAR parámetro existente -->
<div class="overlay" id="modal-editar-param" onclick="if(event.target===this)cerrar('modal-editar-param')">
  <div class="modal">
    <div class="modal-hdr">
      <span id="ep-titulo">Editar Parámetro</span>
      <button class="btn-cls" onclick="cerrar('modal-editar-param')">&#x2715;</button>
    </div>
    <input type="hidden" id="ep-id">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
      <div class="fg" style="grid-column:1/-1"><label>Nombre *</label>
        <input type="text" id="ep-nombre" required></div>
      <div class="fg"><label>Valor *</label>
        <input type="number" id="ep-valor" step="0.001"></div>
      <div class="fg"><label>Tipo</label>
        <select id="ep-tipo">
          <option value="porcentaje">Porcentaje (%)</option>
          <option value="valor_fijo">Valor fijo (COP)</option>
        </select>
      </div>
      <div class="fg"><label>Aplica a</label>
        <select id="ep-aplica">
          <option value="empleador">Empleador</option>
          <option value="empleado">Empleado</option>
          <option value="ambos">Ambos</option>
        </select>
      </div>
      <div class="fg"><label>Categoría</label>
        <select id="ep-cat">
          <?php foreach ($CAT_LABELS as $k => $v): ?>
          <option value="<?= $k ?>"><?= htmlspecialchars($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="fg" style="grid-column:1/-1"><label>Aplica a contratos (separar con coma)</label>
        <input type="text" id="ep-contratos"
               placeholder="tiempo_completo,medio_tiempo,por_horas"></div>
      <div class="fg" style="grid-column:1/-1"><label>Descripción</label>
        <textarea id="ep-desc" rows="2"></textarea></div>
      <div class="fg" style="grid-column:1/-1">
        <label style="display:flex;align-items:center;gap:8px;font-size:14px;cursor:pointer;
                       text-transform:none;letter-spacing:0">
          <input type="checkbox" id="ep-activo" style="width:16px;height:16px">
          <span>Parámetro activo (se usa en los cálculos)</span>
        </label>
      </div>
    </div>
    <button class="btn-submit" style="margin-top:14px" onclick="guardarEditarParam()">
      Guardar Cambios
    </button>
  </div>
</div>

<!-- Modal nuevo parámetro -->
<div class="overlay" id="modal-nuevo" onclick="if(event.target===this)cerrar('modal-nuevo')">
  <div class="modal">
    <div class="modal-hdr">Nuevo Parámetro Laboral
      <button class="btn-cls" onclick="cerrar('modal-nuevo')">&#x2715;</button>
    </div>
    <div class="fg"><label>País</label>
      <input type="text" id="np-pais" value="<?= htmlspecialchars($pais) ?>" placeholder="Colombia"></div>
    <div class="fg"><label>Clave (identificador único) *</label>
      <input type="text" id="np-clave" placeholder="Ej: pct_nueva_carga"></div>
    <div class="fg"><label>Nombre *</label>
      <input type="text" id="np-nombre" placeholder="Ej: Nueva carga empleador"></div>
    <div class="fg"><label>Valor</label>
      <input type="number" id="np-valor" step="0.001" placeholder="0"></div>
    <div class="fg"><label>Tipo</label>
      <select id="np-tipo">
        <option value="porcentaje">Porcentaje (%)</option>
        <option value="valor_fijo">Valor fijo (COP)</option>
      </select>
    </div>
    <div class="fg"><label>Aplica a</label>
      <select id="np-aplica">
        <option value="empleador">Empleador</option>
        <option value="empleado">Empleado</option>
        <option value="ambos">Ambos</option>
      </select>
    </div>
    <div class="fg"><label>Categoría</label>
      <select id="np-cat">
        <?php foreach ($CAT_LABELS as $k => $v): ?>
        <option value="<?= $k ?>"><?= $v ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="fg"><label>Aplica a contratos (separar con coma)</label>
      <input type="text" id="np-contratos"
             value="tiempo_completo,medio_tiempo,por_horas"
             placeholder="tiempo_completo,medio_tiempo,por_horas"></div>
    <div class="fg"><label>Descripción</label>
      <textarea id="np-desc" rows="2" placeholder="Explicación del parámetro..."></textarea></div>
    <button class="btn-submit" onclick="crearParam()">Guardar Parámetro</button>
  </div>
</div>

<div class="toast" id="toast"></div>
<input type="hidden" id="csrf-tk" value="<?= htmlspecialchars(csrf_token()) ?>">

<script>
const csrf = () => document.getElementById('csrf-tk').value;
function cerrar(id) { document.getElementById(id).classList.remove('on'); }
function abrirModal(id) { document.getElementById(id).classList.add('on'); }
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') document.querySelectorAll('.overlay.on').forEach(o => o.classList.remove('on'));
});

// ── Abrir modal de edición con los datos del parámetro pre-cargados ──────────
function abrirEditarParam(param) {
    document.getElementById('ep-titulo').textContent = 'Editar: ' + param.nombre;
    document.getElementById('ep-id').value         = param.id;
    document.getElementById('ep-nombre').value     = param.nombre        || '';
    document.getElementById('ep-valor').value      = param.valor         || '';
    document.getElementById('ep-tipo').value       = param.tipo          || 'porcentaje';
    document.getElementById('ep-aplica').value     = param.aplica_a      || 'empleador';
    document.getElementById('ep-cat').value        = param.categoria     || 'carga_parafiscal';
    document.getElementById('ep-contratos').value  = param.aplica_contratos || '';
    document.getElementById('ep-desc').value       = param.descripcion   || '';
    document.getElementById('ep-activo').checked   = param.activo == 1;
    abrirModal('modal-editar-param');
}

// ── Guardar todos los campos del parámetro editado ───────────────────────────
async function guardarEditarParam() {
    const id = document.getElementById('ep-id').value;
    if (!id) return;
    const fd = new FormData();
    fd.append('csrf_token',       csrf());
    fd.append('accion',           'editar_completo');
    fd.append('id',               id);
    fd.append('nombre',           document.getElementById('ep-nombre').value);
    fd.append('valor',            document.getElementById('ep-valor').value);
    fd.append('tipo',             document.getElementById('ep-tipo').value);
    fd.append('aplica_a',         document.getElementById('ep-aplica').value);
    fd.append('categoria',        document.getElementById('ep-cat').value);
    fd.append('aplica_contratos', document.getElementById('ep-contratos').value);
    fd.append('descripcion',      document.getElementById('ep-desc').value);
    fd.append('activo',           document.getElementById('ep-activo').checked ? 1 : 0);

    const btn = document.querySelector('#modal-editar-param .btn-submit');
    btn.disabled = true;
    btn.textContent = 'Guardando...';

    const r = await fetch('api/parametros.php', { method: 'POST', body: fd });
    const d = await r.json();

    btn.disabled = false;
    btn.textContent = 'Guardar Cambios';

    if (d.success) {
        cerrar('modal-editar-param');
        toast('Parámetro actualizado. Recargando...', 'ok');
        setTimeout(() => location.reload(), 900);
    } else {
        toast(d.error || 'Error al guardar', 'err');
    }
}

async function guardarParam(id) {
    const val  = document.getElementById('val-' + id)?.value;
    const act  = document.getElementById('act-' + id)?.checked ? 1 : 0;
    const fd   = new FormData();
    fd.append('csrf_token', csrf());
    fd.append('accion', 'actualizar');
    fd.append('id',     id);
    fd.append('valor',  val);
    fd.append('activo', act);
    const r = await fetch('api/parametros.php', {method:'POST', body:fd});
    const d = await r.json();
    if (d.success) {
        const ok = document.getElementById('ok-' + id);
        ok.style.display = 'inline';
        setTimeout(() => ok.style.display = 'none', 2500);
        toast('Parámetro actualizado', 'ok');
    } else toast(d.error || 'Error', 'err');
}

async function eliminarParam(id, nombre) {
    if (!confirm('Eliminar el parámetro "' + nombre + '"?\nEsta acción no se puede deshacer.')) return;
    const fd = new FormData();
    fd.append('csrf_token', csrf());
    fd.append('accion', 'eliminar');
    fd.append('id', id);
    const r = await fetch('api/parametros.php', {method:'POST', body:fd});
    const d = await r.json();
    if (d.success) {
        document.getElementById('row-' + id)?.remove();
        toast('Parámetro eliminado', 'ok');
    } else toast(d.error || 'Error', 'err');
}

async function crearParam() {
    const fd = new FormData();
    fd.append('csrf_token',       csrf());
    fd.append('accion',           'crear');
    fd.append('pais',             document.getElementById('np-pais').value);
    fd.append('clave',            document.getElementById('np-clave').value);
    fd.append('nombre',           document.getElementById('np-nombre').value);
    fd.append('valor',            document.getElementById('np-valor').value);
    fd.append('tipo',             document.getElementById('np-tipo').value);
    fd.append('aplica_a',         document.getElementById('np-aplica').value);
    fd.append('categoria',        document.getElementById('np-cat').value);
    fd.append('aplica_contratos', document.getElementById('np-contratos').value);
    fd.append('descripcion',      document.getElementById('np-desc').value);
    const r = await fetch('api/parametros.php', {method:'POST', body:fd});
    const d = await r.json();
    if (d.success) {
        cerrar('modal-nuevo');
        toast('Parámetro creado. Recargando...', 'ok');
        setTimeout(() => location.reload(), 1000);
    } else toast(d.error || 'Error', 'err');
}

var _tt;
function toast(m,t){
    var el=document.getElementById('toast');
    el.textContent=m; el.className='toast toast-'+t+' on';
    clearTimeout(_tt); _tt=setTimeout(()=>el.classList.remove('on'),3500);
}
</script>
</body>
</html>
