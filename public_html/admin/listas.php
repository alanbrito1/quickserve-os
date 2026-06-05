<?php
/**
 * admin/listas.php — Gestión de catálogos configurables del sistema.
 *
 * Permite agregar, editar, reordenar y desactivar opciones de los selects
 * que aparecen en: Inventario (presentación, unidad, categoría),
 * Activos (categoría), Costos (categoría) y Proveedores (categoría).
 *
 * Los ítems desactivados dejan de aparecer en los formularios pero los
 * datos históricos que los usaban siguen siendo válidos en la BD.
 *
 * Acceso: solo superadmin y admin.
 * Responsive: xs<480 | sm480 | md640 | lg1024 | xl1600 | tv1920
 */

require_once __DIR__ . '/../app/middleware/auth_check.php';
require_once __DIR__ . '/../app/helpers/ListasHelper.php';

$nav_activo = 'admin';
$nav_sub    = 'listas';

// Solo admin y superadmin
if (!in_array($_SESSION['usuario_rol'] ?? '', ['superadmin','admin'], true)) {
    http_response_code(403);
    include __DIR__ . '/../app/views/errors/403.php';
    exit;
}

// ── Catálogos disponibles y sus metadatos ─────────────────────────────────────
// 'tipo'    → clave en listas_sistema.tipo
// 'titulo'  → encabezado en la UI
// 'desc'    → descripción breve del uso
// 'modulo'  → módulo donde se usa (para referencia)
$TIPOS_LISTA = [
    // Insumos / Inventario
    'presentacion'        => ['titulo'=>'Presentaciones de Insumos',   'desc'=>'Tipos de envase: frasco, paca, caja, etc.',                    'modulo'=>'Inventario'],
    'unidad_medida'       => ['titulo'=>'Unidades de Medida',           'desc'=>'Unidades básicas: kg, g, litro, ml, unidad, etc.',            'modulo'=>'Inventario'],
    'categoria_insumo'    => ['titulo'=>'Categorías de Insumos',        'desc'=>'Tipos de ingrediente: proteína, lácteo, vegetal, etc.',       'modulo'=>'Inventario'],
    // Productos
    'categoria_producto'  => ['titulo'=>'Categorías de Productos',      'desc'=>'Tipos de producto del menú: Sándwich, Combo, Bebida, etc.',   'modulo'=>'Productos'],
    'tamano_producto'     => ['titulo'=>'Tamaños de Productos',          'desc'=>'Tallas disponibles: XL, L, Único, etc.',                     'modulo'=>'Productos'],
    // Otros módulos
    'categoria_activo'    => ['titulo'=>'Categorías de Activos Fijos',  'desc'=>'Tipos de equipo: cocina, mobiliario, vehículo, etc.',         'modulo'=>'Activos'],
    'categoria_costo'     => ['titulo'=>'Categorías de Costos',         'desc'=>'Tipos de costo: arriendo, servicios, seguros, etc.',          'modulo'=>'Costos'],
    'categoria_proveedor' => ['titulo'=>'Categorías de Proveedores',    'desc'=>'Tipos de proveedor: plaza, retail, mayorista, etc.',          'modulo'=>'Proveedores'],
];

// Cargar todos los ítems agrupados por tipo (incluyendo inactivos para el admin)
$todos = [];
try {
    $rows = db()->query(
        'SELECT id, tipo, valor, etiqueta, orden, activo
         FROM listas_sistema
         ORDER BY tipo, orden, etiqueta'
    )->fetchAll();
    foreach ($rows as $r) {
        $todos[$r['tipo']][] = $r;
    }
} catch (\Exception $e) {
    // Si la tabla no existe todavía (migración 029 pendiente)
}

$tipo_sel = $_GET['tipo'] ?? array_key_first($TIPOS_LISTA); // Tab activo
if (!isset($TIPOS_LISTA[$tipo_sel])) $tipo_sel = array_key_first($TIPOS_LISTA);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Catálogos — <?= APP_NAME ?></title>
    <style>
        /* ── Reset y variables ───────────────────────────────────────── */
        *, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }
        :root {
            --brand:#e94f37; --dark:#111827; --g2:#374151;
            --g5:#6b7280; --g8:#d1d5db; --g9:#f3f4f6; --white:#fff;
            --green:#059669; --yellow:#d97706; --red:#dc2626;
        }
        body { font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
               background:var(--g9); color:var(--dark); min-height:100vh; }

        /* ── Layout ──────────────────────────────────────────────────── */
        .main { padding:16px 14px 60px; max-width:980px; margin:0 auto; }
        .page-title { font-size:20px; font-weight:800; margin-bottom:4px; }
        .page-sub   { font-size:13px; color:var(--g5); margin-bottom:16px; }

        /* ── Tabs de tipo de lista ───────────────────────────────────── */
        .tabs { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:16px; }
        .tab {
            padding:7px 14px; border-radius:10px; font-size:13px;
            font-weight:600; cursor:pointer; text-decoration:none;
            border:2px solid var(--g8); color:var(--g2);
            background:var(--white); transition:border-color .15s, color .15s;
        }
        .tab:hover { border-color:var(--brand); color:var(--brand); }
        .tab.active { background:var(--brand); color:var(--white); border-color:var(--brand); }
        .tab .mod-badge {
            font-size:10px; font-weight:700; padding:1px 5px;
            border-radius:10px; margin-left:5px;
            background:rgba(0,0,0,.1); vertical-align:middle;
        }

        /* ── Barra de acciones ───────────────────────────────────────── */
        .actions-bar {
            display:flex; gap:10px; align-items:center; flex-wrap:wrap;
            background:var(--white); border-radius:14px; padding:14px 18px;
            box-shadow:0 1px 4px rgba(0,0,0,.06); margin-bottom:12px;
        }
        .lista-desc { flex:1; font-size:13px; color:var(--g5); }
        .btn { padding:9px 16px; border:none; border-radius:10px;
               font-size:13px; font-weight:700; cursor:pointer; }
        .btn-primary { background:var(--brand); color:var(--white); }
        .btn-primary:hover { background:#c73d28; }

        /* ── Tabla de ítems ──────────────────────────────────────────── */
        .tbl-card {
            background:var(--white); border-radius:14px;
            box-shadow:0 1px 4px rgba(0,0,0,.06);
            overflow:hidden; overflow-x:auto;
        }
        table { width:100%; border-collapse:collapse; min-width:420px; }
        thead th {
            font-size:11px; font-weight:700; text-transform:uppercase;
            letter-spacing:.4px; color:var(--g5); padding:10px 14px;
            background:var(--g9); border-bottom:1px solid var(--g8);
            text-align:left;
        }
        th.r, td.r { text-align:right; }
        tbody td { padding:10px 14px; border-bottom:1px solid var(--g9); font-size:13px; }
        tbody tr:last-child td { border-bottom:none; }
        tbody tr.inactivo td { opacity:.5; }
        tbody tr:hover td { background:#fafafa; }

        /* ── Badges ──────────────────────────────────────────────────── */
        .badge { font-size:10px; font-weight:700; padding:2px 7px;
                 border-radius:20px; display:inline-block; }
        .b-ok   { background:#d1fae5; color:#065f46; }
        .b-inac { background:#f3f4f6; color:#9ca3af; }

        /* ── Botones de acción ───────────────────────────────────────── */
        .ic-btn {
            background:none; border:none; cursor:pointer;
            padding:4px 8px; border-radius:8px; font-size:13px; color:var(--g5);
        }
        .ic-btn:hover { background:var(--g9); color:var(--dark); }
        .ic-btn.danger:hover { color:var(--red); }

        /* ── Columna orden ───────────────────────────────────────────── */
        .orden-input {
            width:52px; padding:4px 8px; border:1px solid var(--g8);
            border-radius:8px; font-size:13px; text-align:center;
        }

        /* ── Aviso de migración pendiente ────────────────────────────── */
        .alert-mig {
            background:#fff7ed; border:1px solid #fed7aa; border-radius:12px;
            padding:14px 18px; font-size:13px; color:#92400e; margin-bottom:16px;
        }

        /* ── Modal ───────────────────────────────────────────────────── */
        .overlay {
            display:none; position:fixed; inset:0;
            background:rgba(0,0,0,.55); z-index:200;
            align-items:center; justify-content:center; padding:16px;
        }
        .overlay.on { display:flex; }
        .modal {
            background:var(--white); border-radius:16px;
            width:100%; max-width:420px; max-height:90vh; overflow-y:auto;
            padding:0 0 20px;
        }
        .modal-hdr {
            display:flex; justify-content:space-between; align-items:center;
            padding:16px 20px 12px; border-bottom:1px solid var(--g9);
            font-size:16px; font-weight:800; position:sticky; top:0;
            background:var(--white); z-index:1;
        }
        .btn-cls {
            background:var(--g9); border:none; color:var(--g5);
            width:30px; height:30px; border-radius:50%; cursor:pointer; font-size:16px;
        }
        .modal-body { padding:16px 20px 0; }
        .fg { display:flex; flex-direction:column; gap:5px; margin-bottom:14px; }
        .fg label { font-size:11px; font-weight:700; text-transform:uppercase;
                    letter-spacing:.4px; color:var(--g5); }
        .fg input { padding:10px 12px; border:2px solid var(--g8);
                    border-radius:10px; font-size:14px; outline:none; }
        .fg input:focus { border-color:var(--brand); }
        .fg .hint { font-size:11px; color:var(--g5); line-height:1.4; }
        .btn-full {
            width:100%; padding:12px; background:var(--brand); color:var(--white);
            border:none; border-radius:10px; font-size:14px; font-weight:700;
            cursor:pointer; margin-top:4px;
        }
        .btn-full:hover { background:#c73d28; }

        /* ── Toast ───────────────────────────────────────────────────── */
        .toast {
            position:fixed; bottom:24px; left:50%; transform:translateX(-50%) translateY(80px);
            background:var(--dark); color:var(--white); padding:10px 20px;
            border-radius:30px; font-size:13px; font-weight:600;
            transition:transform .25s; z-index:9999; pointer-events:none;
        }
        .toast.ok  { background:var(--green); }
        .toast.err { background:var(--red); }
        .toast.show { transform:translateX(-50%) translateY(0); }

        /* ════════════════════════════════════════════════════════════
           RESPONSIVE
           ════════════════════════════════════════════════════════════ */
        @media (max-width:479px) {
            .main       { padding:12px 10px 50px; }
            .page-title { font-size:17px; }
            .tabs       { gap:4px; }
            .tab        { font-size:12px; padding:6px 10px; }
            .tab .mod-badge { display:none; }
            .actions-bar { flex-direction:column; align-items:stretch; }
            .btn { min-height:44px; }
            table { min-width:380px; }
        }
        @media (min-width:480px) and (max-width:639px) {
            .tab .mod-badge { display:none; }
        }
        @media (min-width:1600px) {
            .main       { max-width:1200px; }
            .page-title { font-size:24px; }
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/../app/views/nav.php'; ?>

<main class="main">
    <h1 class="page-title">Catálogos del Sistema</h1>
    <p class="page-sub">Administra las opciones que aparecen en los desplegables de cada módulo.</p>

    <?php if (empty($todos)): ?>
    <div class="alert-mig">
        <strong>⚠ Migración pendiente.</strong>
        La tabla <code>listas_sistema</code> no existe todavía.
        Ve a <strong>Admin → Base de Datos → Ejecutar Migración</strong> y sube el archivo
        <code>029_listas_sistema.sql</code> para activar este módulo.
    </div>
    <?php endif; ?>

    <!-- Tabs de tipo de lista -->
    <div class="tabs">
        <?php foreach ($TIPOS_LISTA as $tipo => $meta): ?>
        <a href="?tipo=<?= urlencode($tipo) ?>"
           class="tab <?= $tipo_sel === $tipo ? 'active' : '' ?>">
            <?= htmlspecialchars($meta['titulo']) ?>
            <span class="mod-badge"><?= htmlspecialchars($meta['modulo']) ?></span>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Barra de acciones para el catálogo seleccionado -->
    <div class="actions-bar">
        <span class="lista-desc"><?= htmlspecialchars($TIPOS_LISTA[$tipo_sel]['desc']) ?></span>
        <button class="btn btn-primary" onclick="abrirNuevo()">+ Agregar opción</button>
    </div>

    <!-- Tabla de ítems del catálogo seleccionado -->
    <div class="tbl-card">
        <?php $items = $todos[$tipo_sel] ?? []; ?>
        <?php if (empty($items)): ?>
        <div style="padding:30px;text-align:center;color:var(--g5);font-size:13px">
            No hay opciones en este catálogo todavía.
            Agrega la primera con el botón de arriba.
        </div>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Valor (BD)</th>
                    <th>Etiqueta (mostrada al usuario)</th>
                    <th class="r" style="width:70px">Orden</th>
                    <th style="width:90px">Estado</th>
                    <th style="width:70px"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $item): ?>
            <tr class="<?= !(int)$item['activo'] ? 'inactivo' : '' ?>"
                id="row-<?= $item['id'] ?>">
                <td>
                    <code style="font-size:12px;background:var(--g9);padding:2px 6px;border-radius:6px">
                        <?= htmlspecialchars($item['valor']) ?>
                    </code>
                </td>
                <td><strong><?= htmlspecialchars($item['etiqueta']) ?></strong></td>
                <td class="r">
                    <!-- Input inline de orden — se guarda al perder el foco -->
                    <input type="number" class="orden-input"
                           value="<?= (int)$item['orden'] ?>"
                           min="0" max="999"
                           onchange="guardarOrden(<?= $item['id'] ?>, this.value)"
                           title="Posición en el dropdown">
                </td>
                <td>
                    <span class="badge <?= (int)$item['activo'] ? 'b-ok' : 'b-inac' ?>">
                        <?= (int)$item['activo'] ? 'Activo' : 'Inactivo' ?>
                    </span>
                </td>
                <td style="white-space:nowrap">
                    <!-- Editar -->
                    <button class="ic-btn" title="Editar"
                            onclick="abrirEditar(<?= htmlspecialchars(json_encode([
                                'id'       => (int)$item['id'],
                                'valor'    => $item['valor'],
                                'etiqueta' => $item['etiqueta'],
                                'orden'    => (int)$item['orden'],
                            ])) ?>)">&#9998;</button>
                    <!-- Toggle activo/inactivo -->
                    <button class="ic-btn <?= (int)$item['activo'] ? 'danger' : '' ?>"
                            title="<?= (int)$item['activo'] ? 'Desactivar' : 'Reactivar' ?>"
                            onclick="toggleItem(<?= $item['id'] ?>, <?= (int)$item['activo'] ?>)">
                        <?= (int)$item['activo'] ? '&#128683;' : '&#10003;' ?>
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <!-- Nota explicativa sobre valores vs etiquetas -->
    <div style="margin-top:10px;font-size:12px;color:var(--g5);line-height:1.6">
        <strong>Valor (BD):</strong> identificador interno almacenado en la base de datos — no puede cambiarse una vez creado.<br>
        <strong>Etiqueta:</strong> texto que ve el usuario en los formularios — puede editarse libremente.<br>
        <strong>Desactivar:</strong> oculta la opción en nuevos registros pero conserva los datos históricos que la usaban.
    </div>
</main>

<!-- ══ MODAL NUEVO ÍTEM ═══════════════════════════════════════════════════ -->
<div class="overlay" id="modal-ni" onclick="if(event.target===this)cerrar('modal-ni')">
    <div class="modal" role="dialog" aria-modal="true">
        <div class="modal-hdr">
            Agregar opción
            <button class="btn-cls" onclick="cerrar('modal-ni')">&#x2715;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="ni-tipo" value="<?= htmlspecialchars($tipo_sel) ?>">
            <div class="fg">
                <label>Valor (clave interna) *</label>
                <input id="ni-valor" placeholder="ej: botella" maxlength="100" autocomplete="off"
                       oninput="this.value=this.value.toLowerCase().replace(/[^a-z0-9_]/g,'')">
                <span class="hint">
                    Solo letras minúsculas, números y guion bajo. Se guarda en la BD y
                    <strong>no se puede cambiar</strong> después. Ej: <code>botella</code>,
                    <code>lt</code>, <code>frutas_verduras</code>.
                </span>
            </div>
            <div class="fg">
                <label>Etiqueta (texto visible) *</label>
                <input id="ni-etiqueta" placeholder="ej: Botella" maxlength="150" autocomplete="off">
                <span class="hint">Lo que verá el usuario en el desplegable.</span>
            </div>
            <div class="fg">
                <label>Orden en el dropdown</label>
                <input type="number" id="ni-orden" value="0" min="0" max="999">
                <span class="hint">Menor número = aparece antes. El 99 suele usarse para "Otro".</span>
            </div>
            <button class="btn-full" onclick="guardarNuevo()">Agregar al catálogo</button>
        </div>
    </div>
</div>

<!-- ══ MODAL EDITAR ÍTEM ═════════════════════════════════════════════════ -->
<div class="overlay" id="modal-ei" onclick="if(event.target===this)cerrar('modal-ei')">
    <div class="modal" role="dialog" aria-modal="true">
        <div class="modal-hdr">
            Editar opción
            <button class="btn-cls" onclick="cerrar('modal-ei')">&#x2715;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="ei-id">
            <!-- El valor (clave BD) NO se puede editar -->
            <div class="fg">
                <label>Valor (clave interna — no editable)</label>
                <input id="ei-valor" readonly style="background:var(--g9);cursor:default">
            </div>
            <div class="fg">
                <label>Etiqueta (texto visible) *</label>
                <input id="ei-etiqueta" maxlength="150" autocomplete="off">
            </div>
            <div class="fg">
                <label>Orden en el dropdown</label>
                <input type="number" id="ei-orden" min="0" max="999">
            </div>
            <button class="btn-full" onclick="guardarEditar()">Guardar Cambios</button>
        </div>
    </div>
</div>

<input type="hidden" id="csrf-tk" value="<?= htmlspecialchars(csrf_token()) ?>">
<div class="toast" id="toast"></div>

<script>
const csrf  = () => document.getElementById('csrf-tk').value;
const TIPO  = document.getElementById('ni-tipo').value; // tipo de lista activo

/* ── Toast ──────────────────────────────────────────────────────────────── */
function toast(msg, tipo) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className   = 'toast ' + (tipo || '');
    void t.offsetWidth;
    t.classList.add('show');
    clearTimeout(t._t);
    t._t = setTimeout(() => t.classList.remove('show'), 3000);
}

function cerrar(id) { document.getElementById(id).classList.remove('on'); }

document.addEventListener('keydown', e => {
    if (e.key === 'Escape')
        document.querySelectorAll('.overlay.on').forEach(m => m.classList.remove('on'));
});

/* ── Nuevo ítem ─────────────────────────────────────────────────────────── */
function abrirNuevo() {
    ['ni-valor','ni-etiqueta'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('ni-orden').value = '0';
    document.getElementById('modal-ni').classList.add('on');
    setTimeout(() => document.getElementById('ni-valor').focus(), 100);
}

async function guardarNuevo() {
    const valor    = document.getElementById('ni-valor').value.trim();
    const etiqueta = document.getElementById('ni-etiqueta').value.trim();
    if (!valor)    { toast('El valor es obligatorio', 'err'); return; }
    if (!etiqueta) { toast('La etiqueta es obligatoria', 'err'); return; }

    const fd = new FormData();
    fd.append('csrf_token', csrf());
    fd.append('accion',    'crear');
    fd.append('tipo',      TIPO);
    fd.append('valor',     valor);
    fd.append('etiqueta',  etiqueta);
    fd.append('orden',     document.getElementById('ni-orden').value);

    const r = await fetch('api/lista_crud.php', { method:'POST', body:fd });
    const d = await r.json();
    if (d.success) {
        cerrar('modal-ni');
        toast('Opción agregada', 'ok');
        setTimeout(() => location.reload(), 800);
    } else {
        toast(d.error || 'Error', 'err');
    }
}

/* ── Editar ítem ────────────────────────────────────────────────────────── */
function abrirEditar(item) {
    document.getElementById('ei-id').value       = item.id;
    document.getElementById('ei-valor').value    = item.valor;
    document.getElementById('ei-etiqueta').value = item.etiqueta;
    document.getElementById('ei-orden').value    = item.orden;
    document.getElementById('modal-ei').classList.add('on');
    setTimeout(() => document.getElementById('ei-etiqueta').focus(), 100);
}

async function guardarEditar() {
    const etiqueta = document.getElementById('ei-etiqueta').value.trim();
    if (!etiqueta) { toast('La etiqueta es obligatoria', 'err'); return; }

    const fd = new FormData();
    fd.append('csrf_token', csrf());
    fd.append('accion',    'editar');
    fd.append('id',        document.getElementById('ei-id').value);
    fd.append('etiqueta',  etiqueta);
    fd.append('orden',     document.getElementById('ei-orden').value);

    const r = await fetch('api/lista_crud.php', { method:'POST', body:fd });
    const d = await r.json();
    if (d.success) {
        cerrar('modal-ei');
        toast('Opción actualizada', 'ok');
        setTimeout(() => location.reload(), 800);
    } else {
        toast(d.error || 'Error', 'err');
    }
}

/* ── Toggle activo/inactivo ─────────────────────────────────────────────── */
async function toggleItem(id, activo) {
    const msg = activo
        ? '¿Desactivar esta opción? Ya no aparecerá en los formularios.\n'
          + 'Los datos existentes que la usaban no se alteran.'
        : '¿Reactivar esta opción?';
    if (!confirm(msg)) return;

    const fd = new FormData();
    fd.append('csrf_token', csrf());
    fd.append('accion', 'toggle');
    fd.append('id', id);

    const r = await fetch('api/lista_crud.php', { method:'POST', body:fd });
    const d = await r.json();
    if (d.success) {
        toast(d.activo ? 'Opción reactivada' : 'Opción desactivada', 'ok');
        setTimeout(() => location.reload(), 700);
    } else {
        toast(d.error || 'Error', 'err');
    }
}

/* ── Guardar orden inline ───────────────────────────────────────────────── */
async function guardarOrden(id, orden) {
    const fd = new FormData();
    fd.append('csrf_token', csrf());
    fd.append('accion', 'orden');
    fd.append('id', id);
    fd.append('orden', orden);

    const r = await fetch('api/lista_crud.php', { method:'POST', body:fd });
    const d = await r.json();
    if (!d.success) toast(d.error || 'Error al guardar orden', 'err');
}
</script>
</body>
</html>
