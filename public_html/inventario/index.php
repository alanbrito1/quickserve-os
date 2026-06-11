<?php
/**
 * public_html/inventario/index.php
 * Inventario de insumos: stock, presentaciones, costos y ajustes.
 *
 * LÓGICA DE COSTO:
 *   costo_actual = precio_presentacion ÷ cantidad_presentacion
 *   Ejemplo: Frasco de aceite 900ml a $8,500 → $9.44/ml
 *   Cuando el gobierno o el mercado cambia el precio, actualizar
 *   precio_presentacion y el costo por unidad se recalcula solo.
 */

require_once __DIR__ . '/../app/middleware/auth_check.php';
require_once __DIR__ . '/../app/models/InsumoModel.php';
require_once __DIR__ . '/../app/models/PresentacionModel.php';
require_once __DIR__ . '/../app/helpers/ListasHelper.php';

$nav_activo = 'inventario';
permiso_requerir('inventario', 'solo_ver');

$insumos = InsumoModel::todos_con_estado();

// Catálogo de presentaciones de compra (mig 039) por insumo — usado para el
// snapshot legacy (capa 1, read-only) y para la invitación a configurar
// presentaciones tras crear un insumo nuevo.
$pres_por_insumo = PresentacionModel::todas_agrupadas();
foreach ($insumos as &$ins_pc) {
    $ins_pc['pres_cat'] = array_map(fn($p) => [
        'id'                => (int)$p['id'],
        'nombre'            => $p['nombre'],
        'cantidad_base'     => (float)$p['cantidad_base'],
        'unidad_compra'     => $p['unidad_compra'],
        'precio_referencia' => $p['precio_referencia'] ? (float)$p['precio_referencia'] : null,
        'equiv_cantidad'    => $p['equiv_cantidad']  ? (float)$p['equiv_cantidad']  : null,
        'equiv_unidad'      => $p['equiv_unidad'] ?? null,
        'es_predeterminada' => (int)$p['es_predeterminada'],
    ], $pres_por_insumo[$ins_pc['id']] ?? []);
}
unset($ins_pc);

$total    = count($insumos);
$bajos    = count(array_filter($insumos, fn($i) => $i['estado'] === 'bajo'));
$agotados = count(array_filter($insumos, fn($i) => $i['estado'] === 'agotado'));
$ok       = $total - $bajos - $agotados;

// Proveedores para selects
$proveedores = db()->query('SELECT id, nombre FROM proveedores WHERE activo=1 ORDER BY nombre')->fetchAll();

// Detectar si existen columnas nuevas (migración 010)
$tienePresent = false;
try {
    $col = db()->query("SHOW COLUMNS FROM insumos LIKE 'presentacion'")->fetch();
    $tienePresent = (bool)$col;
} catch (\Exception $e) { $tienePresent = false; }

// Detectar migración 039: tabla insumo_presentaciones
$tiene039 = PresentacionModel::tabla_existe_publica();

// Catálogos cargados desde listas_sistema (Admin → Catálogos).
// Fallback a arrays hardcodeados si la migración 029 aún no está aplicada,
// para que el módulo siga funcionando durante la transición.
$PRESENTACIONES_LISTA = listas_get('presentacion');
$UNIDADES_LISTA       = listas_get('unidad_medida');
$CATEGORIAS_LISTA     = listas_get('categoria_insumo');

// Compatibilidad: formatos legacy usados en el HTML de abajo
$PRESENTACIONES = !empty($PRESENTACIONES_LISTA)
    ? array_column($PRESENTACIONES_LISTA, 'valor')
    : ['frasco','tarro','caja','paca','bolsa','atado','lata','bloque','galon','unidad','otra'];

$UNIDADES_LABEL = !empty($UNIDADES_LISTA)
    ? array_column($UNIDADES_LISTA, 'etiqueta', 'valor')
    : ['kg'=>'Kilogramos','g'=>'Gramos','lb'=>'Libras','litro'=>'Litros',
       'ml'=>'Mililitros','unidad'=>'Unidades','loncha'=>'Lonchas','lata'=>'Latas','paquete'=>'Paquetes'];

$CATEGORIAS = !empty($CATEGORIAS_LISTA)
    ? array_column($CATEGORIAS_LISTA, 'etiqueta', 'valor')  // valor => etiqueta para el select
    : ['proteína'=>'Proteína','lácteo'=>'Lácteo','vegetal'=>'Vegetal',
       'condimento'=>'Condimento','empaque'=>'Empaque','grasa'=>'Grasa',
       'combo'=>'Combo','otro'=>'Otro'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventario — <?= APP_NAME ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root { --brand:#e94f37; --dark:#111827; --g2:#374151; --g5:#6b7280; --g8:#d1d5db; --g9:#f3f4f6; --white:#fff; --green:#059669; --yellow:#d97706; }
        body { font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; background:var(--g9); min-height:100vh; color:var(--dark); padding-bottom:40px; }
        .main { padding:16px 14px; max-width:1080px; margin:0 auto; }
        .alert { padding:12px 14px; border-radius:10px; font-size:14px; margin-bottom:14px; }
        .alert-ok  { background:#d1fae5; color:#065f46; border:1px solid #6ee7b7; }
        .alert-err { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; }
        /* Stats */
        .stats { display:grid; grid-template-columns:repeat(3,1fr); gap:10px; margin-bottom:16px; }
        .stat { background:var(--white); border-radius:14px; padding:14px; box-shadow:0 1px 4px rgba(0,0,0,.06); }
        .stat-n { font-size:24px; font-weight:800; }
        .stat-l { font-size:11px; color:var(--g5); text-transform:uppercase; letter-spacing:.5px; }
        .n-ok  { color:var(--green); } .n-bajo { color:var(--yellow); } .n-ago { color:var(--brand); }
        /* Barra de acciones */
        .act-bar { display:flex; gap:8px; margin-bottom:14px; flex-wrap:wrap; align-items:center; }
        .btn-primary { padding:9px 18px; background:var(--brand); color:#fff; border:none; border-radius:10px; font-size:13px; font-weight:700; cursor:pointer; }
        .btn-sec     { padding:9px 16px; background:var(--white); color:var(--dark); border:1px solid var(--g8); border-radius:10px; font-size:13px; font-weight:700; text-decoration:none; cursor:pointer; }
        .btn-sec:hover { border-color:var(--brand); color:var(--brand); }
        /* Tabla */
        .card { background:var(--white); border-radius:14px; box-shadow:0 1px 4px rgba(0,0,0,.06); overflow:hidden; }
        .table-wrap { overflow-x:auto; }
        table { width:100%; border-collapse:collapse; min-width:700px; }
        th { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; color:var(--g5); padding:9px 12px; background:var(--g9); border-bottom:1px solid var(--g8); text-align:left; white-space:nowrap; }
        th.r, td.r { text-align:right; }
        td { padding:10px 12px; border-bottom:1px solid var(--g9); font-size:13px; vertical-align:middle; }
        tr:last-child td { border-bottom:none; }
        /* Barra de stock */
        .sb { width:60px; height:5px; background:var(--g8); border-radius:3px; overflow:hidden; display:inline-block; vertical-align:middle; margin-left:5px; }
        .sf { height:100%; border-radius:3px; }
        .sf-ok { background:var(--green); } .sf-bajo { background:var(--yellow); } .sf-ago { background:var(--brand); }
        .badge { font-size:10px; font-weight:700; padding:2px 7px; border-radius:20px; }
        .b-ok  { background:#d1fae5; color:#065f46; }
        .b-bajo{ background:#fef3c7; color:#92400e; }
        .b-ago { background:#fee2e2; color:#991b1b; }
        /* Presentación info */
        .pres-tag { font-size:11px; font-weight:700; background:var(--g9); color:var(--g2); padding:2px 7px; border-radius:20px; display:inline-block; }
        .costo-calc { font-size:11px; color:var(--g5); margin-top:2px; }
        /* Botones acción */
        .btn-ajuste { padding:4px 10px; border:1px solid var(--g8); background:var(--white); border-radius:7px; font-size:11px; font-weight:600; cursor:pointer; color:var(--g2); }
        .btn-ajuste:hover { border-color:var(--brand); color:var(--brand); }
        .btn-eliminar { padding:4px 10px; border:none; background:#fee2e2; border-radius:7px; font-size:11px; font-weight:600; cursor:pointer; color:#991b1b; }
        .btn-eliminar:hover { background:#fca5a5; }
        /* Modal */
        .overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.55); z-index:60; align-items:center; justify-content:center; padding:16px; }
        .overlay.on { display:flex; }
        .modal { background:var(--white); border-radius:16px; padding:22px; width:100%; max-width:620px; max-height:92vh; overflow-y:auto; }
        .modal-hdr { font-size:16px; font-weight:800; margin-bottom:16px; display:flex; justify-content:space-between; }
        .btn-cls { background:var(--g9); border:none; color:var(--g5); width:30px; height:30px; border-radius:50%; cursor:pointer; font-size:16px; }
        .fg { display:flex; flex-direction:column; gap:4px; margin-bottom:10px; }
        .fg label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--g5); }
        .fg input, .fg select, .fg textarea { padding:9px 11px; border:2px solid var(--g8); border-radius:9px; font-size:14px; color:var(--dark); outline:none; width:100%; -webkit-appearance:none; background:var(--white); }
        .fg input:focus, .fg select:focus, .fg textarea:focus { border-color:var(--brand); }
        .fg .hint { font-size:11px; color:var(--g5); }
        .fg .hint strong { color:var(--brand); }
        .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
        @media(max-width:500px){ .form-grid { grid-template-columns:1fr; } }
        .form-section { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--g5); margin:12px 0 8px; border-top:1px solid var(--g8); padding-top:10px; }
        .btn-submit { width:100%; padding:12px; background:var(--brand); color:#fff; border:none; border-radius:10px; font-size:15px; font-weight:700; cursor:pointer; margin-top:8px; }
        .costo-preview { background:var(--g9); border-radius:10px; padding:10px 14px; font-size:13px; margin-bottom:10px; display:none; }
        /* Toast */
        .toast { position:fixed; bottom:20px; left:50%; transform:translateX(-50%) translateY(20px); padding:10px 20px; border-radius:24px; font-size:14px; font-weight:600; opacity:0; transition:.25s; z-index:99; pointer-events:none; max-width:90vw; }
        .toast.on { opacity:1; transform:translateX(-50%) translateY(0); }
        .toast-ok  { background:#065f46; color:#d1fae5; }
        .toast-err { background:#991b1b; color:#fee2e2; }

        /* ════════════════════════════════════════════════════════════════
           RESPONSIVE — INVENTARIO
           Columnas tabla: 1=Insumo 2=Presentación 3=Stock 4=Costo/u 5=Estado 6=Acciones
           ════════════════════════════════════════════════════════════════ */

        /* ── Teléfono vertical (< 480px) ── */
        @media (max-width: 479px) {
            /* Ocultar: Presentación (2) y Costo/u (4) — los menos críticos en campo */
            table thead tr th:nth-child(2), table tbody tr td:nth-child(2),
            table thead tr th:nth-child(4), table tbody tr td:nth-child(4) { display: none; }
            /* Stats: 2 columnas ya controlado por nav.php global */
            /* Barra de acciones: vertical */
            .act-bar { flex-direction: column; align-items: stretch; }
            .act-bar .btn-primary,
            .act-bar .btn-sec { width: 100%; text-align: center; }
            /* Tabla: menos min-width para reducir scroll */
            table { min-width: 420px !important; }
        }

        /* ── Teléfono horizontal / tablet pequeña (480-639px) ── */
        @media (max-width: 639px) {
            /* Ocultar solo Presentación (2) */
            table thead tr th:nth-child(2), table tbody tr td:nth-child(2) { display: none; }
            table { min-width: 520px !important; }
        }

        /* ── Tablet (640-1023px) ── */
        @media (min-width: 640px) and (max-width: 1023px) {
            .main { max-width: 100%; padding: 16px 18px 60px; }
            /* Stats en 3 columnas — ya está bien */
            /* Tabla completa pero con padding reducido */
            th, td { padding: 9px 11px !important; }
        }

        /* ── Pantalla grande (≥1600px) ── */
        @media (min-width: 1600px) {
            .main { max-width: 1440px !important; padding: 24px 32px 60px !important; }
            .stat-n { font-size: 28px; }
            .stat-l { font-size: 12px; }
            th  { font-size: 11px !important; padding: 11px 16px !important; }
            td  { font-size: 14px !important; padding: 12px 16px !important; }
        }

        /* ── TV (≥1920px) ── */
        @media (min-width: 1920px) {
            .main { max-width: 1680px !important; }
            .stat-n { font-size: 34px; }
            th  { font-size: 13px !important; padding: 14px 20px !important; }
            td  { font-size: 16px !important; padding: 14px 20px !important; }
            .badge { font-size: 12px !important; padding: 4px 10px !important; }
            .btn-primary, .btn-sec { font-size: 15px !important; padding: 12px 22px !important; }
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/../app/views/nav.php'; ?>

<main class="main">

    <!-- Stats -->
    <div class="stats">
        <div class="stat"><div class="stat-n n-ok"><?= $ok ?></div><div class="stat-l">OK</div></div>
        <div class="stat"><div class="stat-n n-bajo"><?= $bajos ?></div><div class="stat-l">Stock Bajo</div></div>
        <div class="stat"><div class="stat-n n-ago"><?= $agotados ?></div><div class="stat-l">Agotados</div></div>
    </div>

    <!-- Barra de acciones -->
    <div class="act-bar">
        <?php if (permiso_tiene('inventario','editar_existentes')): ?>
        <button class="btn-primary" onclick="abrirModal('modal-nuevo-insumo')">
            + Agregar Insumo
        </button>
        <button class="btn-primary" style="background:#374151"
                onclick="abrirModal('modal-nuevo-proveedor')">
            + Proveedor
        </button>
        <?php endif; ?>
        <?php if (permiso_tiene('inventario','editar_existentes')): ?>
        <a href="<?= APP_BASE ?>/inventario/conteo.php" class="btn-sec">📋 Conteo rápido</a>
        <?php endif; ?>
        <?php /* Lista de Compras y Registrar Compra están en el tab Compras */ ?>
    </div>

    <!-- Tabla de insumos -->
    <div class="card">
        <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th style="min-width:180px">Insumo</th>
                    <th>Presentación</th>
                    <th>Stock Actual</th>
                    <th class="r">Costo / Unidad</th>
                    <th>Estado</th>
                    <?php if (permiso_tiene('inventario','editar_existentes')): ?><th></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($insumos)): ?>
                <tr><td colspan="6" style="text-align:center;padding:40px;color:var(--g5)">
                    Sin insumos registrados. Usa "+ Agregar Insumo".
                </td></tr>
                <?php endif; ?>
                <?php foreach ($insumos as $ins):
                    $est   = $ins['estado'];
                    $pct   = min(100, (int)($ins['pct_stock'] ?? 0));
                    $fillC = $est === 'ok' ? 'sf-ok' : ($est === 'bajo' ? 'sf-bajo' : 'sf-ago');
                    $badgeC= $est === 'ok' ? 'b-ok'  : ($est === 'bajo' ? 'b-bajo'  : 'b-ago');
                    // Presentación
                    $pres        = $tienePresent ? ($ins['presentacion'] ?? null) : null;
                    $cant_pres   = $tienePresent ? (float)($ins['cantidad_presentacion'] ?? 0) : 0;
                    $precio_pres = $tienePresent ? (float)($ins['precio_presentacion']   ?? 0) : 0;
                ?>
                <tr <?= !$ins['activo'] ? 'style="opacity:.45"' : '' ?>>
                    <td>
                        <strong><?= htmlspecialchars($ins['nombre']) ?></strong>
                        <?php if (!empty($ins['categoria'])): ?>
                        <br><small style="color:var(--g5)"><?= htmlspecialchars(ucfirst($ins['categoria'])) ?></small>
                        <?php endif; ?>
                        <?php if (!empty($ins['proveedor_nombre'])): ?>
                        <br><small style="color:var(--g5)">&#128205; <?= htmlspecialchars($ins['proveedor_nombre']) ?></small>
                        <?php endif; ?>
                        <?php if ($tienePresent && !empty($ins['notas'])): ?>
                        <br><small style="color:var(--g5)" title="<?= htmlspecialchars($ins['notas']) ?>">
                            &#128221; <?= htmlspecialchars(mb_substr($ins['notas'],0,40)) ?>
                        </small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($pres): ?>
                        <span class="pres-tag"><?= ucfirst($pres) ?></span>
                        <?php if ($cant_pres > 0): ?>
                        <br><span style="font-size:12px;color:var(--g5)">
                            <?= number_format($cant_pres,0,',','.') ?>
                            <?= htmlspecialchars($ins['unidad_medida']) ?>
                            <?php if ($precio_pres > 0): ?>
                            · $<?= number_format($precio_pres,0,',','.') ?>
                            <?php endif; ?>
                        </span>
                        <?php endif; ?>
                        <?php else: ?>
                        <small style="color:var(--g8)">Sin configurar</small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= number_format($ins['stock_actual'],2,',','.') ?>
                        <small style="color:var(--g5)"><?= htmlspecialchars($ins['unidad_medida']) ?></small>
                        <span class="sb"><span class="sf <?= $fillC ?>" style="width:<?= $pct ?>%"></span></span>
                        <br><small style="color:var(--g5)">Mín: <?= number_format($ins['stock_seguridad'],2,',','.') ?></small>
                    </td>
                    <td class="r">
                        <strong>$<?= number_format($ins['costo_actual'],2,',','.') ?></strong>
                        <br><small style="color:var(--g5)">/ <?= htmlspecialchars($ins['unidad_medida']) ?></small>
                        <?php
                        // Si tiene equivalencia física, mostrar el costo por unidad física también
                        // Ej: $500/loncha → $16.67/g (si 1 loncha = 30g)
                        if (!empty($ins['equiv_cantidad']) && (float)$ins['equiv_cantidad'] > 0
                            && !empty($ins['equiv_unidad'])): ?>
                        <br><small style="color:var(--g5);font-size:10px">
                            1 <?= htmlspecialchars($ins['unidad_medida']) ?>
                            = <?= number_format((float)$ins['equiv_cantidad'],2,',','.') ?>
                            <?= htmlspecialchars($ins['equiv_unidad']) ?>
                        </small>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge <?= $badgeC ?>"><?= $est ?></span></td>
                    <?php if (permiso_tiene('inventario','editar_existentes')): ?>
                    <td style="display:flex;gap:5px;align-items:center;flex-wrap:nowrap">
                        <button class="btn-ajuste ic" title="Ajustar"
                            onclick="abrirEditar(<?= htmlspecialchars(json_encode($ins)) ?>)">
                            <?= IC_EDIT ?>
                        </button>
                        <button class="btn-ajuste ic" style="color:#0369a1;border-color:#bae6fd" title="Copiar"
                            onclick="duplicarInsumo(<?= htmlspecialchars(json_encode($ins)) ?>)">
                            <?= IC_COPY ?>
                        </button>
                        <?php if (permiso_tiene('inventario','editar_existentes')): ?>
                        <button class="btn-eliminar ic" title="Eliminar"
                            onclick="eliminarInsumo(<?= $ins['id'] ?>, <?= htmlspecialchars(json_encode($ins['nombre'])) ?>)">
                            <?= IC_TRASH ?>
                        </button>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>

</main>

<!-- ══ MODAL: NUEVO INSUMO ══════════════════════════════════════════════════ -->
<?php if (permiso_tiene('inventario','editar_existentes')): ?>
<div class="overlay" id="modal-nuevo-insumo" onclick="if(event.target===this)cerrar('modal-nuevo-insumo')">
  <div class="modal">
    <div class="modal-hdr">Agregar Insumo
      <button class="btn-cls" onclick="cerrar('modal-nuevo-insumo')">&#x2715;</button>
    </div>

    <p class="form-section" style="margin-top:0;border-top:none;padding-top:0">Identificación</p>
    <div class="form-grid">
      <div class="fg"><label>Nombre *</label>
        <input type="text" id="ni-nombre" placeholder="Ej: Aceite Vegetal" required></div>
      <div class="fg"><label>Categoría</label>
        <select id="ni-cat">
          <?php foreach ($CATEGORIAS as $val => $lbl): ?>
          <option value="<?= htmlspecialchars($val) ?>"><?= htmlspecialchars($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <p class="form-section">Unidad y costo</p>
    <div class="form-grid">
      <div class="fg"><label>Unidad básica de medida *</label>
        <select id="ni-unidad" onchange="toggleEquiv('ni')">
          <?php foreach ($UNIDADES_LABEL as $k => $v): ?>
          <option value="<?= $k ?>"><?= htmlspecialchars($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="fg"><label>Costo actual por unidad ($)</label>
        <input type="number" id="ni-costo" placeholder="Ej: 9.44" min="0" step="0.01">
        <span class="hint">Opcional. Después de guardar podrás definir presentaciones de compra para calcularlo automáticamente.</span>
      </div>
    </div>

    <!-- Equivalencia física: visible solo si la unidad NO es kg/g/lb/litro/ml.
         Permite registrar cuánto pesa o cuántos ml tiene una loncha, lata, paquete, etc.
         Ej: 1 loncha = 30 g | 1 lata = 170 g | 1 paquete = 250 ml -->
    <div id="ni-equiv-sec" style="display:none;background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:12px 14px;margin-bottom:14px">
      <p style="font-size:12px;font-weight:700;color:#1e40af;margin-bottom:8px">
        📏 Equivalencia física por unidad <span style="font-weight:400;color:#3b82f6">(opcional pero recomendado)</span>
      </p>
      <p style="font-size:11px;color:#1d4ed8;margin-bottom:10px">
        Como la unidad elegida no es una medida física de masa o volumen, puedes indicar cuánto
        pesa o contiene una unidad. Esto permite calcular costos por gramo/ml con mayor precisión.
      </p>
      <div class="form-grid">
        <div class="fg">
          <label>1 unidad equivale a…</label>
          <input type="number" id="ni-equiv-cant" placeholder="Ej: 30" min="0.001" step="0.001">
          <span class="hint">Cantidad en la unidad física (30 si 1 loncha pesa 30 g)</span>
        </div>
        <div class="fg">
          <label>Unidad física</label>
          <select id="ni-equiv-unidad">
            <option value="g">Gramos (g)</option>
            <option value="kg">Kilogramos (kg)</option>
            <option value="ml">Mililitros (ml)</option>
            <option value="litro">Litros</option>
          </select>
        </div>
      </div>
    </div>

    <p class="form-section">Stock</p>
    <div class="form-grid">
      <div class="fg"><label>Stock actual</label>
        <input type="number" id="ni-stock" value="0" min="0" step="0.001"></div>
      <div class="fg"><label>Stock de seguridad (mínimo)</label>
        <input type="number" id="ni-seg" value="0" min="0" step="0.001">
        <span class="hint">Alerta cuando el stock baje de este valor</span>
      </div>
      <div class="fg"><label>Proveedor</label>
        <select id="ni-proveedor">
          <option value="">— Sin proveedor —</option>
          <?php foreach ($proveedores as $p): ?>
          <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="fg"><label>Notas / Observaciones</label>
      <textarea id="ni-notas" rows="2"
                placeholder="Proveedor habitual, condiciones de almacenamiento, sustitutos..."></textarea>
    </div>

    <button class="btn-submit" onclick="guardarNuevoInsumo()">Guardar Insumo</button>
  </div>
</div>
<?php endif; ?>

<!-- ══ MODAL: AJUSTAR / EDITAR INSUMO ══════════════════════════════════════ -->
<div class="overlay" id="modal-ajustar" onclick="if(event.target===this)cerrar('modal-ajustar')">
  <div class="modal">
    <div class="modal-hdr">
      <span id="aj-titulo">Ajustar insumo</span>
      <button class="btn-cls" onclick="cerrar('modal-ajustar')">&#x2715;</button>
    </div>
    <input type="hidden" id="aj-id">

    <div class="fg" style="margin-bottom:12px">
      <label>Nombre del insumo</label>
      <input type="text" id="aj-nombre" placeholder="Nombre del insumo">
    </div>

    <p class="form-section" style="border-top:1px solid var(--g8);padding-top:10px">Stock</p>
    <div class="fg"><label>Stock actual</label>
      <input type="text" id="aj-stock-actual" readonly
             style="background:var(--g9);color:var(--g5)"></div>
    <div class="fg"><label>Tipo de ajuste</label>
      <select id="aj-tipo" onchange="actualizarLabelCantidadAjuste()">
        <option value="entrada">Entrada (+) — compra directa, donación</option>
        <option value="merma">Merma (−) — vencimiento, derrame</option>
        <option value="correccion">Corrección (±) — conteo físico</option>
        <option value="total">Total (=) — fijar stock actual</option>
      </select>
    </div>
    <div class="fg"><label id="aj-cantidad-label">Cantidad a ajustar</label>
      <input type="number" id="aj-cantidad" placeholder="0.00" step="0.01" min="0.01">
    </div>
    <div class="fg" id="aj-pres-conv-wrap" style="display:none">
      <label>Convertir desde presentación</label>
      <div style="display:flex;gap:6px">
        <select id="aj-pres-conv-sel" onchange="convertirDesdePresentacionAj()" style="flex:2"></select>
        <input type="number" id="aj-pres-conv-num" placeholder="Nro." min="0" step="0.01" style="flex:1" oninput="convertirDesdePresentacionAj()">
      </div>
      <span id="aj-pres-conv-hint" class="hint"></span>
    </div>
    <div class="fg"><label>Motivo (opcional)</label>
      <input type="text" id="aj-motivo" placeholder="Ej: conteo físico, merma por vencimiento...">
    </div>
    <div class="fg"><label>Stock de seguridad</label>
      <input type="number" id="aj-seg" step="0.001" min="0">
    </div>

    <?php if ($tienePresent): ?>
    <p class="form-section">Presentación y costo
      <span id="aj-capa1-badge" style="display:none;font-weight:400;font-size:11px;color:#3730a3;background:#eef2ff;border-radius:8px;padding:2px 8px;margin-left:6px;white-space:normal">
        snapshot automático desde presentación predeterminada
      </span>
    </p>
    <div class="form-grid">
      <div class="fg"><label>Presentación</label>
        <select id="aj-pres" onchange="calcCostoAj('pres')">
          <option value="">— Sin definir —</option>
          <?php foreach ($PRESENTACIONES as $p): ?>
          <option value="<?= $p ?>"><?= ucfirst($p) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="fg"><label>Unidad básica</label>
        <select id="aj-unidad" onchange="calcCostoAj(); toggleEquiv('aj')">
          <?php foreach ($UNIDADES_LABEL as $k => $v): ?>
          <option value="<?= $k ?>"><?= htmlspecialchars($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="fg"><label>Cantidad por presentación</label>
        <input type="number" id="aj-cant-pres" step="0.001" min="0" oninput="calcCostoAj('cant')"></div>
      <div class="fg"><label>Precio por presentación ($)</label>
        <input type="number" id="aj-precio-pres" step="1" min="0" oninput="calcCostoAj('precio')"></div>
    </div>
    <div class="costo-preview" id="aj-costo-preview">
      <strong>Costo por unidad:</strong> <span id="aj-costo-calc">—</span>
    </div>
    <div class="fg">
      <label>Costo por unidad ($)</label>
      <input type="number" id="aj-costo" step="0.0001" min="0" oninput="calcCostoAj('costo')">
      <span class="hint">Llena cualquier par de campos y el tercero se calcula automáticamente</span>
    </div>

    <!-- Equivalencia física: visible cuando la unidad no es kg/g/lb/litro/ml -->
    <div id="aj-equiv-sec" style="display:none;background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:12px 14px;margin-top:4px;margin-bottom:14px">
      <p style="font-size:12px;font-weight:700;color:#1e40af;margin-bottom:8px">
        📏 Equivalencia física por unidad
      </p>
      <div class="form-grid">
        <div class="fg">
          <label>1 unidad equivale a…</label>
          <input type="number" id="aj-equiv-cant" placeholder="Ej: 30" min="0.001" step="0.001">
          <span class="hint">Ej: 30 si 1 loncha pesa 30 g</span>
        </div>
        <div class="fg">
          <label>Unidad física</label>
          <select id="aj-equiv-unidad">
            <option value="g">Gramos (g)</option>
            <option value="kg">Kilogramos (kg)</option>
            <option value="ml">Mililitros (ml)</option>
            <option value="litro">Litros</option>
          </select>
        </div>
      </div>
    </div>

    <div class="fg"><label>Notas</label>
      <textarea id="aj-notas" rows="2"></textarea>
    </div>
    <?php endif; ?>

    <?php if ($tiene039 && permiso_tiene('inventario','editar_existentes')): ?>
    <!-- ── Presentaciones de compra (migración 039) ──────────────────── -->
    <details id="aj-pres-section" style="margin-top:14px;border:1px solid #c7d2fe;border-radius:10px;overflow:hidden">
      <summary style="padding:10px 14px;background:#eef2ff;font-weight:700;font-size:13px;color:#3730a3;cursor:pointer;user-select:none;list-style:none">
        📦 Presentaciones de compra
        <span id="aj-pres-badge" style="margin-left:8px;background:#6366f1;color:#fff;border-radius:12px;padding:1px 8px;font-size:11px"></span>
      </summary>
      <div style="padding:12px 14px;background:#fff">
        <p style="font-size:11px;color:var(--g5);margin-bottom:10px">
          Define cómo se compra habitualmente este insumo (frasco 900ml, galón 3.785L, etc.). El sistema convierte automáticamente al registrar una compra.
        </p>
        <!-- lista de presentaciones existentes -->
        <div id="aj-pres-lista" style="margin-bottom:10px"></div>

        <!-- formulario para agregar/editar presentación -->
        <details id="aj-pres-form-wrap" style="border:1px solid var(--g8);border-radius:8px;overflow:hidden">
          <summary style="padding:8px 12px;background:var(--g9);font-size:12px;font-weight:700;cursor:pointer;list-style:none">
            + Agregar presentación
          </summary>
          <div style="padding:12px">
            <input type="hidden" id="pf-id">
            <input type="hidden" id="pf-insumo-id">
            <div class="form-grid">
              <div class="fg" style="grid-column:1/-1">
                <label>Nombre / descripción *</label>
                <input type="text" id="pf-nombre" placeholder="Ej: Frasco 900ml, Galón 3.785L, Paca 12 und" maxlength="60">
              </div>
              <div class="fg">
                <label>Cantidad base (unidades canónicas) *</label>
                <input type="number" id="pf-cantidad-base" placeholder="Ej: 900 (para 900ml)" min="0.0001" step="0.0001">
                <span class="hint" id="pf-hint-base"></span>
              </div>
              <div class="fg">
                <label>Unidad de compra</label>
                <input type="text" id="pf-unidad-compra" placeholder="Ej: frasco, galón, paca" maxlength="30">
              </div>
              <div class="fg">
                <label>Precio de referencia ($)</label>
                <input type="number" id="pf-precio-ref" placeholder="Precio habitual (orientativo)" min="0" step="1">
              </div>
              <div class="fg" style="grid-column:1/-1;display:flex;align-items:center;gap:8px;margin-top:2px">
                <input type="checkbox" id="pf-predeterminada" style="width:18px;height:18px">
                <label for="pf-predeterminada" style="margin:0;font-weight:600">Presentación predeterminada</label>
              </div>
            </div>

            <!-- equiv override -->
            <details style="margin-top:10px;border:1px solid #bfdbfe;border-radius:8px;overflow:hidden">
              <summary style="padding:7px 10px;background:#eff6ff;font-size:11px;font-weight:700;color:#1e40af;cursor:pointer;list-style:none">
                📏 Override de equivalencia física (opcional)
              </summary>
              <div style="padding:10px" class="form-grid">
                <div class="fg">
                  <label>1 unidad equivale a…</label>
                  <input type="number" id="pf-equiv-cant" placeholder="Ej: 170" min="0.001" step="0.001">
                  <span class="hint">Deja vacío para usar el equivalente genérico del insumo</span>
                </div>
                <div class="fg">
                  <label>Unidad física</label>
                  <select id="pf-equiv-unidad">
                    <option value="g">Gramos (g)</option>
                    <option value="kg">Kilogramos (kg)</option>
                    <option value="ml">Mililitros (ml)</option>
                    <option value="litro">Litros</option>
                  </select>
                </div>
              </div>
            </details>

            <div style="display:flex;gap:8px;margin-top:10px">
              <button class="btn-submit" style="flex:1;font-size:13px;padding:9px" onclick="guardarPresentacion()">Guardar presentación</button>
              <button onclick="cancelarFormPres()" style="padding:9px 14px;background:var(--g9);color:var(--dark);border:none;border-radius:8px;font-weight:700;cursor:pointer">Cancelar</button>
            </div>
          </div>
        </details>
      </div>
    </details>
    <?php endif; ?>

    <div style="display:flex;gap:8px;margin-top:8px">
      <button class="btn-submit" style="flex:1" onclick="confirmarAjuste()">Guardar</button>
      <button style="flex:0.4;padding:12px;background:var(--g9);color:var(--dark);border:none;border-radius:10px;font-size:15px;font-weight:700;cursor:pointer"
              onclick="cerrar('modal-ajustar')">Cancelar</button>
    </div>
  </div>
</div>

<!-- ══ MODAL: NUEVO PROVEEDOR ══════════════════════════════════════════════════ -->
<?php if (permiso_tiene('inventario','editar_existentes')): ?>
<div class="overlay" id="modal-nuevo-proveedor" onclick="if(event.target===this)cerrar('modal-nuevo-proveedor')">
  <div class="modal" style="max-width:480px">
    <div class="modal-hdr">Agregar Proveedor
      <button class="btn-cls" onclick="cerrar('modal-nuevo-proveedor')">&#x2715;</button>
    </div>
    <div class="fg">
      <label>Nombre del proveedor *</label>
      <input type="text" id="np-nombre" placeholder="Ej: Plaza Minorista, D1, Alkosto...">
    </div>
    <div class="fg">
      <label>Categoría</label>
      <select id="np-cat-prov">
        <option value="plaza">Plaza / Mercado</option>
        <option value="tienda">Tienda / Supermercado</option>
        <option value="retail">Cadena (Alkosto, Éxito...)</option>
        <option value="online">Online (MercadoLibre, Amazon...)</option>
        <option value="mayorista">Mayorista</option>
        <option value="panaderia">Panadería / Pastelería</option>
        <option value="otro">Otro</option>
      </select>
    </div>
    <div class="fg">
      <label>Teléfono / WhatsApp</label>
      <input type="tel" id="np-tel" placeholder="Ej: 300 123 4567">
    </div>
    <div class="fg">
      <label>Dirección</label>
      <input type="text" id="np-dir" placeholder="Ej: Carrera 50 #10-20, Local 115">
    </div>
    <div class="fg">
      <label>Contacto (nombre persona)</label>
      <input type="text" id="np-contacto" placeholder="Ej: Doña Carmen">
    </div>
    <div class="fg">
      <label>Notas</label>
      <textarea id="np-notas-prov" rows="2"
                placeholder="Horarios, productos principales, condiciones de pago..."></textarea>
    </div>
    <button class="btn-submit" onclick="guardarNuevoProveedor()">Guardar Proveedor</button>
  </div>
</div>
<?php endif; ?>

<div class="toast" id="toast"></div>
<input type="hidden" id="csrf-tk" value="<?= htmlspecialchars(csrf_token()) ?>">

<script>
var TIENE_PRESENT = <?= $tienePresent ? 'true' : 'false' ?>;
var TIENE_039     = <?= $tiene039     ? 'true' : 'false' ?>;
var csrf = () => document.getElementById('csrf-tk').value;
var presentacionesActuales = [];
var ajusteInsumoActual = null;

function abrirModal(id) { var el=document.getElementById(id); if(el) el.classList.add('on'); }
function cerrar(id)     { var el=document.getElementById(id); if(el) el.classList.remove('on'); }
document.addEventListener('keydown', e => {
    if (e.key==='Escape') document.querySelectorAll('.overlay.on').forEach(o=>o.classList.remove('on'));
});

// ── Unidades físicas de masa/volumen que NO necesitan equivalencia adicional ──
// Si la unidad básica ES física, el campo de equivalencia queda oculto.
// Si es una unidad contable (loncha, lata, paquete...), se muestra el campo.
const UNIDADES_FISICAS = ['kg', 'g', 'lb', 'litro', 'ml'];

/**
 * Muestra u oculta la sección de equivalencia física según la unidad seleccionada.
 * @param {string} prefijo 'ni' (nuevo insumo) o 'aj' (ajustar/editar)
 */
function toggleEquiv(prefijo) {
    var sel     = document.getElementById(prefijo + '-unidad');
    var seccion = document.getElementById(prefijo + '-equiv-sec');
    if (!sel || !seccion) return;
    var esFisica = UNIDADES_FISICAS.includes(sel.value);
    // Si la unidad es física, ocultamos la sección de equivalencia
    seccion.style.display = esFisica ? 'none' : '';
}

// ── Guardar nuevo insumo ─────────────────────────────────────────────────────
async function guardarNuevoInsumo() {
    var nombre = document.getElementById('ni-nombre').value.trim();
    if (!nombre) { toast('El nombre es obligatorio', 'err'); return; }

    var fd = new FormData();
    fd.append('csrf_token',             csrf());
    fd.append('accion',                 'crear');
    fd.append('nombre',                 nombre);
    fd.append('categoria',              document.getElementById('ni-cat').value);
    fd.append('unidad_medida',          document.getElementById('ni-unidad').value);
    fd.append('costo_actual',           document.getElementById('ni-costo').value || '0');
    fd.append('stock_actual',           document.getElementById('ni-stock').value  || '0');
    fd.append('stock_seguridad',        document.getElementById('ni-seg').value    || '0');
    fd.append('proveedor_id',           document.getElementById('ni-proveedor').value);
    fd.append('notas',                  document.getElementById('ni-notas').value);
    // Equivalencia física (solo si el campo está visible, es decir la unidad no es física)
    if (document.getElementById('ni-equiv-sec').style.display !== 'none') {
        fd.append('equiv_cantidad', document.getElementById('ni-equiv-cant').value  || '');
        fd.append('equiv_unidad',   document.getElementById('ni-equiv-unidad').value || 'g');
    }

    var r = await fetch('api/insumo_crud.php', {method:'POST', body:fd});
    var d = await r.json();
    if (d.success) {
        cerrar('modal-nuevo-insumo');
        toast('Insumo guardado. Configura su primera presentación de compra.', 'ok');

        // Abrir el modal de edición invitando a configurar la primera
        // presentación de compra (mig 039). Como el insumo es nuevo, aún
        // no tiene catálogo: pres_cat=[] mantiene la capa 1 editable.
        var nuevoIns = {
            id: d.id,
            nombre: nombre,
            categoria: document.getElementById('ni-cat').value,
            unidad_medida: document.getElementById('ni-unidad').value,
            costo_actual: parseFloat(document.getElementById('ni-costo').value) || 0,
            stock_actual: parseFloat(document.getElementById('ni-stock').value) || 0,
            stock_seguridad: parseFloat(document.getElementById('ni-seg').value) || 0,
            presentacion: null,
            cantidad_presentacion: null,
            precio_presentacion: null,
            equiv_cantidad: null,
            equiv_unidad: null,
            notas: document.getElementById('ni-notas').value,
            pres_cat: [],
        };
        if (document.getElementById('ni-equiv-sec').style.display !== 'none') {
            nuevoIns.equiv_cantidad = parseFloat(document.getElementById('ni-equiv-cant').value) || null;
            nuevoIns.equiv_unidad   = document.getElementById('ni-equiv-unidad').value || 'g';
        }

        abrirEditar(nuevoIns);
        if (TIENE_039) {
            var sec  = document.getElementById('aj-pres-section');
            var wrap = document.getElementById('aj-pres-form-wrap');
            if (sec)  sec.open  = true;
            if (wrap) wrap.open = true;
        }
    } else toast(d.error || 'Error', 'err');
}

// ── Capa 1 (legacy, mig 010) read-only cuando hay catálogo de presentaciones (mig 039) ──
// Si el insumo tiene presentaciones catalogadas, presentación/cantidad/precio/costo
// son un snapshot automático de la predeterminada (PresentacionModel::sincronizarLegacy)
// y no deben editarse manualmente aquí. Sin catálogo, quedan editables (fallback).
function actualizarCapa1ReadOnly(ins) {
    if (!TIENE_PRESENT) return;
    var tieneCatalogo = Array.isArray(ins.pres_cat) && ins.pres_cat.length > 0;
    ['aj-pres', 'aj-cant-pres', 'aj-precio-pres', 'aj-costo'].forEach(function(id) {
        var el = document.getElementById(id);
        if (!el) return;
        if (el.tagName === 'SELECT') el.disabled = tieneCatalogo;
        else el.readOnly = tieneCatalogo;
        el.style.background = tieneCatalogo ? 'var(--g9)' : '';
        el.style.color      = tieneCatalogo ? 'var(--g5)' : '';
    });
    var badge = document.getElementById('aj-capa1-badge');
    if (badge) badge.style.display = tieneCatalogo ? 'inline' : 'none';
}

// ── Conversión presentación → cantidad de ajuste (mig 039) ──────────────────
// Si el insumo tiene presentaciones catalogadas (ej. "Bidón 18L"), permite
// indicar "Nro. de presentaciones" (ej. 3) y calcula automáticamente
// aj-cantidad = nro × cantidad_base ("conté 3 bidones de 18L" → 54 L).
function actualizarConversionPresentacionAj(ins) {
    var wrap = document.getElementById('aj-pres-conv-wrap');
    var sel  = document.getElementById('aj-pres-conv-sel');
    var num  = document.getElementById('aj-pres-conv-num');
    var hint = document.getElementById('aj-pres-conv-hint');
    if (!wrap || !sel) return;
    var cats = (ins && Array.isArray(ins.pres_cat)) ? ins.pres_cat : [];
    if (cats.length > 0) {
        sel.innerHTML = cats.map(function(p) {
            return '<option value="' + p.cantidad_base + '">' + htmlEsc(p.nombre)
                 + ' (' + formatDecimal(p.cantidad_base, 2) + ' ' + htmlEsc(ins.unidad_medida) + '/u)</option>';
        }).join('');
        var pred = cats.find(function(p){ return p.es_predeterminada == 1; });
        if (pred) sel.value = pred.cantidad_base;
        wrap.style.display = '';
    } else {
        sel.innerHTML = '';
        wrap.style.display = 'none';
    }
    if (num)  num.value = '';
    if (hint) hint.textContent = '';
}

function convertirDesdePresentacionAj() {
    var sel  = document.getElementById('aj-pres-conv-sel');
    var num  = document.getElementById('aj-pres-conv-num');
    var hint = document.getElementById('aj-pres-conv-hint');
    if (!sel || !num || sel.value === '') return;
    var cantBase = parseFloat(sel.value) || 0;
    var nro      = parseFloat(num.value) || 0;
    if (cantBase <= 0 || nro <= 0) { hint.textContent = ''; return; }
    var total  = nro * cantBase;
    var unidad = (ajusteInsumoActual && ajusteInsumoActual.unidad_medida) || '';
    document.getElementById('aj-cantidad').value = total.toFixed(2);
    hint.textContent = '= ' + formatDecimal(total, 2) + ' ' + unidad;
}

// ── Abrir modal de ajuste/edición ────────────────────────────────────────────
function abrirEditar(ins) {
    ajusteInsumoActual = ins;
    document.getElementById('aj-titulo').textContent = 'Ajustar: ' + ins.nombre;
    document.getElementById('aj-id').value     = ins.id;
    document.getElementById('aj-nombre').value = ins.nombre;
    document.getElementById('aj-stock-actual').value = formatDecimal(ins.stock_actual, 2) + ' ' + ins.unidad_medida;
    document.getElementById('aj-tipo').value   = 'entrada';
    document.getElementById('aj-cantidad').value = '';
    document.getElementById('aj-motivo').value = '';
    document.getElementById('aj-seg').value    = (parseFloat(ins.stock_seguridad) || 0).toFixed(2);
    actualizarLabelCantidadAjuste();

    if (TIENE_PRESENT) {
        document.getElementById('aj-pres').value      = ins.presentacion || '';
        document.getElementById('aj-unidad').value    = ins.unidad_medida || 'unidad';
        document.getElementById('aj-cant-pres').value = ins.cantidad_presentacion ? parseFloat(ins.cantidad_presentacion).toFixed(2) : '';
        document.getElementById('aj-precio-pres').value = ins.precio_presentacion || '';
        document.getElementById('aj-costo').value     = (parseFloat(ins.costo_actual) || 0).toFixed(2);
        document.getElementById('aj-notas').value     = ins.notas || '';
        // Cargar equivalencia física si existe
        document.getElementById('aj-equiv-cant').value   = ins.equiv_cantidad ? parseFloat(ins.equiv_cantidad).toFixed(2) : '';
        document.getElementById('aj-equiv-unidad').value = ins.equiv_unidad   || 'g';
        // Mostrar/ocultar sección de equivalencia según unidad actual
        toggleEquiv('aj');
        // Sin source: solo actualiza la vista previa con los valores ya cargados
        calcCostoAj('precio');
        // Read-only/badge si ya hay presentaciones catalogadas (mig 039)
        actualizarCapa1ReadOnly(ins);
    }

    // Helper "Convertir desde presentación" (mig 039) — independiente de TIENE_PRESENT
    actualizarConversionPresentacionAj(ins);

    abrirModal('modal-ajustar');

    // Cargar presentaciones catalogadas si mig 039 está aplicada
    if (TIENE_039) {
        document.getElementById('pf-insumo-id').value = ins.id;
        document.getElementById('pf-hint-base').textContent = 'Unidad básica del insumo: ' + ins.unidad_medida;
        cargarPresentaciones(ins.id);
    }
}

// ── Cálculo bidireccional entre presentación/cantidad/precio/costo (modal editar/ajustar) ──
// source: 'cant' | 'precio' | 'costo'
function calcCostoAj(source) {
    var cantEl   = document.getElementById('aj-cant-pres');
    var precioEl = document.getElementById('aj-precio-pres');
    var costoEl  = document.getElementById('aj-costo');
    var prev     = document.getElementById('aj-costo-preview');
    var calc     = document.getElementById('aj-costo-calc');

    if (!cantEl || !precioEl || !costoEl) return;

    var cant  = parseFloat(cantEl.value)   || 0;
    var precio = parseFloat(precioEl.value) || 0;
    var costo  = parseFloat(costoEl.value)  || 0;

    if (source === 'cant') {
        if (precio > 0 && cant > 0)       { costo = precio / cant;  costoEl.value  = costo.toFixed(2); }
        else if (costo > 0 && cant > 0)   { precio = costo * cant;  precioEl.value = precio.toFixed(0); }
    } else if (source === 'precio') {
        if (cant > 0 && precio > 0)       { costo = precio / cant;  costoEl.value  = costo.toFixed(2); }
        else if (costo > 0 && precio > 0) { cant  = precio / costo; cantEl.value   = cant.toFixed(2); }
    } else if (source === 'costo') {
        if (cant > 0 && costo > 0)        { precio = costo * cant;  precioEl.value = precio.toFixed(0); }
        else if (precio > 0 && costo > 0) { cant   = precio / costo; cantEl.value  = cant.toFixed(2); }
    }

    // Re-leer tras el cálculo para mostrar el resultado correcto en la vista previa
    costo = parseFloat(costoEl.value) || 0;
    if (costo > 0) {
        if (calc) calc.innerHTML = '<strong>$' + formatDecimal(costo, 2) + '</strong>';
        if (prev) prev.style.display = 'block';
    } else {
        if (prev) prev.style.display = 'none';
    }
}

// ── Cambiar etiqueta de "Cantidad a ajustar" según el tipo seleccionado ───────
function actualizarLabelCantidadAjuste() {
    var tipo  = document.getElementById('aj-tipo').value;
    var label = document.getElementById('aj-cantidad-label');
    var input = document.getElementById('aj-cantidad');
    if (!label || !input) return;
    if (tipo === 'total') {
        label.textContent = 'Nuevo stock total';
        input.placeholder = 'Ej: 12.50';
        input.min = '0';
    } else {
        label.textContent = 'Cantidad a ajustar';
        input.placeholder = '0.00';
        input.min = '0.01';
    }
}

// ── Confirmar ajuste ─────────────────────────────────────────────────────────
async function confirmarAjuste() {
    var id       = document.getElementById('aj-id').value;
    var tipo     = document.getElementById('aj-tipo').value;
    var cantidad = parseFloat(document.getElementById('aj-cantidad').value) || 0;
    var motivo   = document.getElementById('aj-motivo').value.trim() || tipo;
    var seg      = document.getElementById('aj-seg').value;
    var nombre   = document.getElementById('aj-nombre').value.trim();
    if (!nombre) { toast('El nombre es obligatorio', 'err'); return; }

    var delta;
    if (tipo === 'merma') {
        delta = -cantidad;
    } else if (tipo === 'total') {
        var stockActual = (ajusteInsumoActual && ajusteInsumoActual.id == id)
            ? parseFloat(ajusteInsumoActual.stock_actual) || 0 : 0;
        delta = cantidad - stockActual;
    } else {
        delta = cantidad; // entrada, correccion
    }

    // Ajuste de stock: solo cuando hay un cambio real (cantidad=0 → solo editar campos,
    // salvo tipo 'total' donde cantidad=0 puede significar "fijar stock en cero")
    var aplicaAjuste = (tipo === 'total') ? (delta !== 0) : (cantidad !== 0);
    if (aplicaAjuste) {
        var fd1 = new FormData();
        fd1.append('csrf_token',  csrf());
        fd1.append('insumo_id',   id);
        fd1.append('delta',       delta);
        fd1.append('motivo',      motivo);
        var r1 = await fetch('api/ajustar_stock.php', {method:'POST', body:fd1});
        var d1 = await r1.json();
        if (!d1.success) { toast(d1.error || 'Error al ajustar stock', 'err'); return; }
    }

    // Guardar presentación/costo/nombre aunque la cantidad sea 0
    if (TIENE_PRESENT) {
        var fd2 = new FormData();
        fd2.append('csrf_token',            csrf());
        fd2.append('accion',                'editar');
        fd2.append('id',                    id);
        fd2.append('nombre',                nombre);
        fd2.append('presentacion',          document.getElementById('aj-pres')?.value || '');
        fd2.append('unidad_medida',         document.getElementById('aj-unidad')?.value || 'unidad');
        fd2.append('cantidad_presentacion', document.getElementById('aj-cant-pres')?.value || '0');
        fd2.append('precio_presentacion',   document.getElementById('aj-precio-pres')?.value || '0');
        fd2.append('costo_actual',          document.getElementById('aj-costo')?.value || '0');
        fd2.append('stock_seguridad',       seg);
        fd2.append('notas',                 document.getElementById('aj-notas')?.value || '');
        // Equivalencia física (solo si la sección es visible)
        if (document.getElementById('aj-equiv-sec')?.style.display !== 'none') {
            fd2.append('equiv_cantidad', document.getElementById('aj-equiv-cant')?.value  || '');
            fd2.append('equiv_unidad',   document.getElementById('aj-equiv-unidad')?.value || 'g');
        }
        var r2 = await fetch('api/insumo_crud.php', {method:'POST', body:fd2});
        var d2 = await r2.json();
        if (!d2.success) { toast(d2.error || 'Error al guardar datos del insumo', 'err'); return; }
    }

    cerrar('modal-ajustar');
    toast('Insumo actualizado', 'ok');
    setTimeout(() => location.reload(), 900);
}

// ── Eliminar insumo ───────────────────────────────────────────────────────────
async function eliminarInsumo(id, nombre) {
    if (!confirm('¿Desactivar "' + nombre + '"?\nEl insumo no aparecerá en el inventario activo pero se conserva el historial.')) return;

    var fd = new FormData();
    fd.append('csrf_token', csrf());
    fd.append('accion',     'eliminar');
    fd.append('id',         id);
    var r = await fetch('api/insumo_crud.php', {method:'POST', body:fd});
    var d = await r.json();

    if (d.success) {
        if (d.advertencias && d.advertencias.length > 0) {
            toast('Desactivado (advertencia: ' + d.advertencias.join(', ') + ')', 'ok');
        } else {
            toast(d.mensaje || 'Insumo desactivado', 'ok');
        }
        setTimeout(() => location.reload(), 1000);
    } else {
        toast(d.error || 'Error', 'err');
    }
}

// ── Guardar nuevo proveedor ───────────────────────────────────────────────────
async function guardarNuevoProveedor() {
    var nombre = document.getElementById('np-nombre').value.trim();
    if (!nombre) { toast('El nombre del proveedor es obligatorio', 'err'); return; }

    var fd = new FormData();
    fd.append('csrf_token', csrf());
    fd.append('nombre',     nombre);
    fd.append('categoria',  document.getElementById('np-cat-prov').value);
    fd.append('telefono',   document.getElementById('np-tel').value.trim());
    fd.append('direccion',  document.getElementById('np-dir').value.trim());
    fd.append('contacto',   document.getElementById('np-contacto').value.trim());
    fd.append('notas',      document.getElementById('np-notas-prov').value.trim());

    var r = await fetch('api/proveedor_crud.php', {method:'POST', body:fd});
    var d = await r.json();
    if (d.success) {
        cerrar('modal-nuevo-proveedor');
        toast('Proveedor guardado: ' + nombre, 'ok');
        // Agregar al select de proveedores sin recargar la página
        var opts = document.querySelectorAll('select[id$="-proveedor"], #ni-proveedor');
        opts.forEach(function(s) {
            var o = new Option(nombre, d.id, false, true);
            s.add(o);
        });
        // Limpiar campos
        ['np-nombre','np-tel','np-dir','np-contacto','np-notas-prov'].forEach(function(id) {
            var el = document.getElementById(id);
            if (el) el.value = '';
        });
    } else {
        toast(d.error || 'Error al guardar proveedor', 'err');
    }
}

// ── Duplicar insumo ───────────────────────────────────────────────────────────
async function duplicarInsumo(ins) {
    var fd = new FormData();
    fd.append('csrf_token', csrf());
    fd.append('accion',     'duplicar');
    fd.append('id',         ins.id);
    var r = await fetch('api/insumo_crud.php', {method:'POST', body:fd});
    var d = await r.json();
    if (d.success) {
        toast('Creado: ' + d.nombre, 'ok');
        setTimeout(() => location.reload(), 900);
    } else toast(d.error || 'Error al duplicar', 'err');
}

// ── Presentaciones de compra (mig 039) ──────────────────────────────────────
async function cargarPresentaciones(insumo_id) {
    var lista = document.getElementById('aj-pres-lista');
    var badge = document.getElementById('aj-pres-badge');
    if (!lista) return;
    lista.innerHTML = '<em style="font-size:11px;color:var(--g5)">Cargando…</em>';
    try {
        var r = await fetch('api/presentaciones.php?insumo_id=' + insumo_id);
        var d = await r.json();
        if (!d.success) { lista.innerHTML = ''; return; }
        var pres = d.presentaciones || [];
        presentacionesActuales = pres;
        if (badge) badge.textContent = pres.length > 0 ? pres.length : '';
        // Reflejar de inmediato el estado read-only/badge de la capa 1 y el
        // selector "Convertir desde presentación" al crear/eliminar
        // presentaciones, sin esperar a recargar la página
        if (ajusteInsumoActual && ajusteInsumoActual.id == insumo_id) {
            actualizarCapa1ReadOnly({ pres_cat: pres });
            actualizarConversionPresentacionAj({ pres_cat: pres, unidad_medida: ajusteInsumoActual.unidad_medida });
        }
        if (pres.length === 0) {
            lista.innerHTML = '<p style="font-size:11px;color:var(--g5);margin:0">Sin presentaciones configuradas.</p>';
            return;
        }
        var html = '';
        pres.forEach(function(p) {
            var pred = p.es_predeterminada == 1 ? ' <span style="background:#dcfce7;color:#166534;border-radius:8px;padding:1px 6px;font-size:10px">★ Predeterminada</span>' : '';
            var equiv = (p.equiv_cantidad && p.equiv_unidad) ? ' · equiv: ' + formatDecimal(p.equiv_cantidad, 2) + ' ' + p.equiv_unidad : '';
            html += '<div style="display:flex;align-items:center;gap:6px;padding:6px 0;border-bottom:1px solid var(--g9)">'
                  + '<div style="flex:1;font-size:12px"><strong>' + htmlEsc(p.nombre) + '</strong>' + pred + '<br>'
                  + '<span style="color:var(--g5)">' + formatDecimal(p.cantidad_base, 2) + ' ' + htmlEsc(p.unidad_compra || 'und')
                  + (p.precio_referencia > 0 ? ' · $' + Number(p.precio_referencia).toLocaleString('es-CO') : '')
                  + equiv + '</span></div>'
                  + '<button onclick="editarPresentacion(' + p.id + ')" style="padding:4px 8px;font-size:11px;border:1px solid var(--g7);border-radius:6px;background:#fff;cursor:pointer">Editar</button>'
                  + '<button onclick="eliminarPresentacion(' + p.id + ')" style="padding:4px 8px;font-size:11px;border:1px solid #fca5a5;border-radius:6px;background:#fff;color:#dc2626;cursor:pointer">✕</button>'
                  + '</div>';
        });
        lista.innerHTML = html;
    } catch(e) {
        lista.innerHTML = '<em style="font-size:11px;color:#dc2626">Error al cargar presentaciones</em>';
    }
}

function editarPresentacion(id) {
    var p = presentacionesActuales.find(function(x){ return x.id == id; });
    if (!p) { toast('Presentación no encontrada', 'err'); return; }
    document.getElementById('pf-id').value            = p.id;
    document.getElementById('pf-nombre').value        = p.nombre;
    document.getElementById('pf-cantidad-base').value = (parseFloat(p.cantidad_base) || 0).toFixed(2);
    document.getElementById('pf-unidad-compra').value = p.unidad_compra || '';
    document.getElementById('pf-precio-ref').value    = p.precio_referencia || '';
    document.getElementById('pf-predeterminada').checked = p.es_predeterminada == 1;
    document.getElementById('pf-equiv-cant').value    = p.equiv_cantidad ? parseFloat(p.equiv_cantidad).toFixed(2) : '';
    document.getElementById('pf-equiv-unidad').value  = p.equiv_unidad   || 'g';
    var wrap = document.getElementById('aj-pres-form-wrap');
    wrap.open = true;
    wrap.querySelector('summary').textContent = '✏ Editando: ' + p.nombre;
    wrap.scrollIntoView({behavior:'smooth', block:'nearest'});
}

function cancelarFormPres() {
    document.getElementById('pf-id').value            = '';
    document.getElementById('pf-nombre').value        = '';
    document.getElementById('pf-cantidad-base').value = '';
    document.getElementById('pf-unidad-compra').value = '';
    document.getElementById('pf-precio-ref').value    = '';
    document.getElementById('pf-predeterminada').checked = false;
    document.getElementById('pf-equiv-cant').value    = '';
    var wrap = document.getElementById('aj-pres-form-wrap');
    if (wrap) { wrap.open = false; wrap.querySelector('summary').textContent = '+ Agregar presentación'; }
}

async function guardarPresentacion() {
    var nombre = document.getElementById('pf-nombre').value.trim();
    var cantB  = document.getElementById('pf-cantidad-base').value;
    if (!nombre)          { toast('El nombre es obligatorio', 'err'); return; }
    if (!(parseFloat(cantB) > 0)) { toast('La cantidad base debe ser mayor a cero', 'err'); return; }

    var insumo_id = document.getElementById('pf-insumo-id').value;
    var pf_id     = document.getElementById('pf-id').value;
    var fd = new FormData();
    fd.append('csrf_token',    csrf());
    fd.append('accion',        pf_id ? 'editar' : 'crear');
    if (pf_id) fd.append('id', pf_id);
    fd.append('insumo_id',     insumo_id);
    fd.append('nombre',        nombre);
    fd.append('cantidad_base', cantB);
    fd.append('unidad_compra', document.getElementById('pf-unidad-compra').value.trim());
    fd.append('precio_referencia', document.getElementById('pf-precio-ref').value || '');
    fd.append('es_predeterminada', document.getElementById('pf-predeterminada').checked ? '1' : '0');
    fd.append('equiv_cantidad', document.getElementById('pf-equiv-cant').value || '');
    fd.append('equiv_unidad',   document.getElementById('pf-equiv-unidad').value || 'g');

    try {
        var r = await fetch('api/presentaciones.php', {method:'POST', body:fd});
        var d = await r.json();
        if (d.success) {
            toast(d.mensaje || 'Guardado', 'ok');
            cancelarFormPres();
            cargarPresentaciones(insumo_id);
        } else toast(d.error || 'Error', 'err');
    } catch(e) { toast('Error de red', 'err'); }
}

async function eliminarPresentacion(id) {
    var p = presentacionesActuales.find(function(x){ return x.id == id; });
    var nombre    = p ? p.nombre : '';
    var insumo_id = document.getElementById('pf-insumo-id').value;
    if (!confirm('¿Eliminar la presentación "' + nombre + '"?\nNo afecta el historial de compras.')) return;
    var fd = new FormData();
    fd.append('csrf_token', csrf());
    fd.append('accion',     'eliminar');
    fd.append('id',         id);
    try {
        var r = await fetch('api/presentaciones.php', {method:'POST', body:fd});
        var d = await r.json();
        if (d.success) { toast(d.mensaje || 'Eliminado', 'ok'); cargarPresentaciones(insumo_id); }
        else toast(d.error || 'Error', 'err');
    } catch(e) { toast('Error de red', 'err'); }
}

function htmlEsc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

var _tt;
function toast(m, t) {
    var el = document.getElementById('toast');
    el.textContent = m; el.className = 'toast toast-' + t + ' on';
    clearTimeout(_tt); _tt = setTimeout(() => el.classList.remove('on'), 3500);
}
</script>
</body>
</html>
