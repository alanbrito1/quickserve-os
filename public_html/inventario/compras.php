<?php
/**
 * public_html/inventario/compras.php
 * Registro de compras de insumos.
 * Al guardar: actualiza costo_actual, stock_actual y recalcula productos afectados.
 */

require_once __DIR__ . '/../app/middleware/auth_check.php';
require_once __DIR__ . '/../app/models/CompraModel.php';
require_once __DIR__ . '/../app/models/InsumoModel.php';
require_once __DIR__ . '/../app/models/PresentacionModel.php';
require_once __DIR__ . '/../app/helpers/ListasHelper.php';

permiso_requerir('compras', 'solo_propios');

$insumos         = InsumoModel::todos();
$pres_por_insumo = PresentacionModel::todas_agrupadas();

// Catálogos para mini-modal de nuevo insumo
$UNIDADES_LISTA   = listas_get('unidad_medida');
$CATEGORIAS_LISTA = listas_get('categoria_insumo');
$UNIDADES_NI  = !empty($UNIDADES_LISTA)
    ? array_column($UNIDADES_LISTA, 'etiqueta', 'valor')
    : ['g'=>'Gramos','ml'=>'Mililitros','unidad'=>'Unidades','kg'=>'Kilogramos',
       'litro'=>'Litros','loncha'=>'Lonchas','lata'=>'Latas'];
$CATEGORIAS_NI = !empty($CATEGORIAS_LISTA)
    ? array_column($CATEGORIAS_LISTA, 'etiqueta', 'valor')
    : ['proteína'=>'Proteína','lácteo'=>'Lácteo','vegetal'=>'Vegetal',
       'condimento'=>'Condimento','empaque'=>'Empaque','grasa'=>'Grasa','otro'=>'Otro'];
$msg_ok  = '';
$msg_err = '';

// ── Filtros del historial (GET) ───────────────────────────────────────────────
$f_desde    = trim($_GET['desde']    ?? '');
$f_hasta    = trim($_GET['hasta']    ?? '');
$f_lugar    = trim($_GET['lugar']    ?? '');
$f_item     = trim($_GET['item']     ?? '');
$f_cat      = trim($_GET['cat']      ?? '');
$f_orden    = in_array($_GET['orden'] ?? '', ['fecha','lugar','total']) ? $_GET['orden'] : 'fecha';

$compras    = CompraModel::historial_agrupado($f_desde, $f_hasta, $f_lugar, $f_item, $f_cat, $f_orden);
$categorias = CompraModel::categorias_usadas();
$hay_filtro = ($f_lugar !== '' || $f_item !== '' || $f_cat !== ''
            || ($f_desde !== '' && $f_desde !== date('Y-m-d', strtotime('-30 days')))
            || ($f_hasta !== '' && $f_hasta !== date('Y-m-d')));

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verificar()) {
        $msg_err = 'Token de seguridad inválido.';
    } else {
        $lineas_raw   = $_POST['lineas']       ?? [];
        $proveedor_id = !empty($_POST['proveedor_id']) ? (int)$_POST['proveedor_id'] : null;
        $notas        = trim($_POST['notas']   ?? '');
        $lugar_compra = trim($_POST['lugar_compra'] ?? '');

        // Sanitizar líneas: ignorar filas vacías
        // Incluir campos de presentación (opcionales) para snapshot histórico
        $lineas = [];
        foreach ($lineas_raw as $l) {
            $iid    = (int)($l['insumo_id']      ?? 0);
            $cant   = (float)str_replace(',', '.', $l['cantidad']        ?? '0');
            $precio = (float)str_replace(',', '.', $l['precio_unitario'] ?? '0');
            if ($iid > 0 && $cant > 0 && $precio > 0) {
                $linea = ['insumo_id' => $iid, 'cantidad' => $cant, 'precio_unitario' => $precio];
                // Campos de presentación (snapshot inmutable de cómo se compró)
                $pres   = trim($l['presentacion']          ?? '');
                $cantpx = (float)str_replace(',', '.', $l['cantidad_presentacion'] ?? '0');
                $numpres= (float)str_replace(',', '.', $l['cant_presentaciones']   ?? '0');
                $ppres  = (float)str_replace(',', '.', $l['precio_presentacion']   ?? '0');
                if ($pres !== '' && $cantpx > 0 && $numpres > 0) {
                    $linea['presentacion']          = $pres;
                    $linea['cantidad_presentacion'] = $cantpx;
                    $linea['cant_presentaciones']   = $numpres;
                    $linea['precio_presentacion']   = $ppres > 0 ? $ppres : null;
                }
                // Mig 039: snapshot de presentacion_id catalogada
                $pres_id = !empty($l['presentacion_id']) ? (int)$l['presentacion_id'] : null;
                if ($pres_id > 0) $linea['presentacion_id'] = $pres_id;
                $lineas[] = $linea;
            }
        }

        if (empty($lineas)) {
            $msg_err = 'Agrega al menos un ítem válido (insumo, cantidad y precio requeridos).';
        } else {
            try {
                $id = CompraModel::crear($lineas, $proveedor_id, $notas, $lugar_compra);
                $msg_ok  = "Compra #$id registrada. Stock e insumos actualizados.";
                $compras = CompraModel::historial_agrupado(); // refrescar listado
            } catch (RuntimeException $e) {
                $msg_err = $e->getMessage();
            }
        }
    }
}

// Proveedores para el select
$proveedores = db()->query('SELECT id, nombre FROM proveedores WHERE activo=1 ORDER BY nombre')->fetchAll();

// Serializar insumos para el JS.
// presentacion, cant_pres y precio_pres incluidos para el bloque de presentación
// en el formulario de compras (misma lógica bidireccional que inventario/index.php).
// equiv_cantidad y equiv_unidad para el hint de equivalencia física.
$insumos_js = json_encode(array_map(fn($i) => [
    'id'            => $i['id'],
    'nombre'        => $i['nombre'],
    'unidad'        => $i['unidad_medida'],
    'costo'         => (float)$i['costo_actual'],
    'presentacion'  => $i['presentacion']         ?? null,
    'cant_pres'     => $i['cantidad_presentacion'] ? (float)$i['cantidad_presentacion'] : null,
    'precio_pres'   => $i['precio_presentacion']   ? (float)$i['precio_presentacion']   : null,
    'equiv_cant'    => $i['equiv_cantidad']  ? (float)$i['equiv_cantidad']  : null,
    'equiv_unidad'  => $i['equiv_unidad']   ?? null,
    // Catálogo de presentaciones (mig 039) — vacío si no hay o mig no aplicada
    'pres_cat'      => array_map(fn($p) => [
        'id'              => (int)$p['id'],
        'nombre'          => $p['nombre'],
        'cantidad_base'   => (float)$p['cantidad_base'],
        'unidad_compra'   => $p['unidad_compra'],
        'precio_referencia'=> $p['precio_referencia'] ? (float)$p['precio_referencia'] : null,
        'equiv_cantidad'  => $p['equiv_cantidad']  ? (float)$p['equiv_cantidad']  : null,
        'equiv_unidad'    => $p['equiv_unidad'] ?? null,
        'es_predeterminada'=> (int)$p['es_predeterminada'],
    ], $pres_por_insumo[$i['id']] ?? []),
], $insumos), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Compras — <?= APP_NAME ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root { --brand:#e94f37; --dark:#111827; --g2:#374151; --g5:#6b7280; --g8:#d1d5db; --g9:#f3f4f6; --white:#fff; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background:var(--g9); min-height:100vh; color:var(--dark); }

        .main { padding:16px 14px; max-width:900px; margin:0 auto; }
        .card { background:var(--white); border-radius:14px; padding:20px; margin-bottom:16px; box-shadow:0 1px 4px rgba(0,0,0,.06); }
        .card-title { font-size:16px; font-weight:800; margin-bottom:16px; }

        .alert { padding:12px 14px; border-radius:10px; font-size:14px; margin-bottom:14px; }
        .alert-ok  { background:#d1fae5; color:#065f46; border:1px solid #6ee7b7; }
        .alert-err { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; }

        /* Formulario */
        .fg { display:flex; flex-direction:column; gap:5px; margin-bottom:14px; }
        .fg label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--g5); }
        .fg input, .fg select, .fg textarea { padding:10px 12px; border:2px solid var(--g8); border-radius:10px; font-size:15px; color:var(--dark); width:100%; outline:none; -webkit-appearance:none; }
        .fg input:focus, .fg select:focus { border-color:var(--brand); }
        .top-row { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        @media(max-width:480px){ .top-row { grid-template-columns:1fr; } }

        /* ── Líneas de compra ───────────────────────────────────────────────── */
        .linea {
            display:flex;
            flex-direction:column;
            gap:6px;
            padding:14px 0;
            border-bottom:1px dashed var(--g8);
        }
        /* Fila 1: selector de insumo */
        .linea-sel select {
            width:100%; padding:9px 10px; border:2px solid var(--g8);
            border-radius:9px; font-size:14px; color:var(--dark);
            outline:none; -webkit-appearance:none;
        }
        .linea-sel select:focus { border-color:var(--brand); }

        /* Fila 2: panel informativo de presentación (solo lectura) */
        .linea-pres {
            display:none; /* JS lo muestra al seleccionar un insumo */
        }
        /* Panel horizontal con badges y etiquetas de la presentación del insumo */
        .pres-info-panel {
            display:flex; flex-wrap:wrap; align-items:center; gap:6px;
            padding:7px 12px;
            background:#f0f9ff; border:1px solid #bae6fd; border-radius:10px;
            font-size:13px;
        }
        /* Badge con el tipo de empaque (ej: "Frasco", "Lata", "Paca") */
        .pres-badge {
            display:inline-flex; align-items:center; padding:3px 10px;
            background:#e0f2fe; color:#0369a1; border-radius:20px;
            font-size:12px; font-weight:700; white-space:nowrap;
        }
        .pres-info-sep { color:#94a3b8; font-weight:300; }
        .pres-info-detail { color:#374151; font-size:13px; }
        /* Badge verde para la equivalencia física (ej: "= 160 g/lata") */
        .pres-equiv-badge {
            display:inline-flex; align-items:center; padding:2px 8px;
            background:#f0fdf4; color:#166534; border-radius:20px;
            font-size:11px; font-weight:600; border:1px solid #bbf7d0;
            white-space:nowrap;
        }
        /* Hint dinámico que muestra el total físico al escribir la cantidad */
        .pres-total-hint {
            margin-left:auto; padding:2px 8px;
            background:#dbeafe; color:#1e40af;
            border-radius:6px; font-size:11px; font-weight:600; white-space:nowrap;
        }

        /* Fila 3: cantidad | precio/unidad | total | delete */
        .linea-units {
            display:grid;
            grid-template-columns:1fr 1fr 1fr auto;
            gap:8px;
            align-items:end;
        }
        @media(max-width:500px){
            .linea-units { grid-template-columns:1fr 1fr; gap:6px; }
            .linea-units > div:last-of-type { grid-column:1/-1; }
        }
        .linea-units .fu-col { display:flex; flex-direction:column; gap:3px; }
        .linea-units .fu-col .fu-lbl {
            font-size:10px; font-weight:700; text-transform:uppercase;
            letter-spacing:.4px; color:var(--g5);
        }
        .linea-units input {
            padding:9px 10px; border:2px solid var(--g8); border-radius:9px;
            font-size:14px; color:var(--dark); outline:none; width:100%;
        }
        .linea-units input:focus { border-color:var(--brand); }
        /* Campo Total: borde azul para diferenciarlo visualmente */
        .linea-units input.total-input { border-color:#93c5fd; background:#f0f9ff; }
        .linea-units input.total-input:focus { border-color:#2563eb; }
        /* Hint equivalencia física (unidades no-físicas) */
        .equiv-hint {
            font-size:10px; color:#1d4ed8; background:#eff6ff;
            padding:1px 5px; border-radius:6px; display:none;
        }
        .sub-val { font-size:13px; font-weight:700; color:var(--brand); white-space:nowrap; min-width:60px; text-align:right; align-self:center; }
        .btn-rm { background:var(--g9); color:var(--brand); border:none; width:30px; height:30px; border-radius:50%; cursor:pointer; font-size:18px; display:flex; align-items:center; justify-content:center; flex-shrink:0; align-self:center; }
        .btn-rm:hover { background:#fee2e2; }

        .add-linea-btn { padding:9px 16px; background:var(--g9); color:var(--g2); border:1px solid var(--g8); border-radius:10px; font-size:13px; font-weight:700; cursor:pointer; margin-top:8px; }
        .add-linea-btn:hover { border-color:var(--brand); color:var(--brand); }

        .total-row { display:flex; justify-content:flex-end; align-items:center; gap:10px; padding:14px 0 0; border-top:2px solid var(--dark); margin-top:8px; }
        .total-row span { font-size:13px; color:var(--g5); }
        .total-row strong { font-size:20px; font-weight:800; }

        .btn-guardar { width:100%; padding:14px; background:var(--brand); color:var(--white); border:none; border-radius:12px; font-size:16px; font-weight:800; cursor:pointer; margin-top:16px; }
        .btn-guardar:hover { background:#c73d28; }

        /* Filtros historial */
        .filtros-card { background:var(--white); border-radius:14px; padding:16px 20px; margin-bottom:16px; box-shadow:0 1px 4px rgba(0,0,0,.06); }
        .filtros-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
        @media(max-width:540px){ .filtros-grid { grid-template-columns:1fr; } }
        .filtros-grid .fg { margin-bottom:0; }
        .filtros-row { display:flex; gap:8px; margin-top:12px; flex-wrap:wrap; align-items:center; }
        .btn-filtrar { padding:9px 18px; background:var(--brand); color:#fff; border:none; border-radius:10px; font-size:13px; font-weight:700; cursor:pointer; }
        .btn-filtrar:hover { background:#c73d28; }
        .btn-limpiar { padding:9px 14px; background:var(--g9); color:var(--g2); border:1px solid var(--g8); border-radius:10px; font-size:13px; font-weight:700; cursor:pointer; text-decoration:none; }
        .btn-limpiar:hover { border-color:var(--brand); color:var(--brand); }
        .filtro-activo { display:inline-flex; align-items:center; gap:4px; padding:3px 10px; background:#fef3c7; color:#92400e; border-radius:20px; font-size:11px; font-weight:700; }
        /* Historial */
        .historial-row { padding:10px 0; border-bottom:1px solid var(--g9); font-size:14px; }
        .historial-row:last-child { border-bottom:none; }
        .hist-fecha { font-size:12px; color:var(--g5); }
        .hist-total { font-weight:800; }
        .hist-acciones { display:flex; gap:6px; margin-top:8px; flex-wrap:wrap; }
        .btn-accion { padding:5px 11px; border-radius:8px; font-size:12px; font-weight:700; border:none; cursor:pointer; }
        .btn-editar    { background:#dbeafe; color:#1d4ed8; }
        .btn-editar:hover { background:#bfdbfe; }
        .btn-duplicar  { background:#d1fae5; color:#065f46; }
        .btn-duplicar:hover { background:#a7f3d0; }
        .btn-eliminar  { background:#fee2e2; color:#991b1b; }
        .btn-eliminar:hover { background:#fecaca; }

        /* Modal de edición */
        .modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:200; overflow-y:auto; padding:16px; }
        .modal-overlay.open { display:flex; align-items:flex-start; justify-content:center; }
        .modal-box { background:var(--white); border-radius:16px; padding:20px; width:100%; max-width:720px; margin:auto; box-shadow:0 8px 32px rgba(0,0,0,.2); }
        .modal-hdr { display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; }
        .modal-title { font-size:16px; font-weight:800; }
        .btn-close { background:none; border:none; font-size:22px; cursor:pointer; color:var(--g5); line-height:1; padding:0 4px; }
        .btn-close:hover { color:var(--brand); }

        /* Selector de presentación catalogada (mig 039) */
        .pres-cat-block { margin-top:6px; }
        .pres-cat-panel { background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px; padding:9px 12px; }
        .pres-cat-panel select { width:100%; padding:8px 10px; border:2px solid #86efac; border-radius:8px; font-size:14px; color:var(--dark); outline:none; -webkit-appearance:none; background:#fff; }
        .pres-cat-panel select:focus { border-color:#22c55e; }
        .pres-cat-detail { margin-top:8px; display:grid; grid-template-columns:1fr 1fr; gap:8px; }
        .pres-calc-hint { margin-top:4px; font-size:11px; color:#1d4ed8; background:#eff6ff; padding:3px 8px; border-radius:6px; display:none; }

        /* Mini-modal: crear nuevo insumo */
        .btn-nuevo-ins { display:inline-flex; align-items:center; gap:5px; padding:6px 12px;
            background:#fff; border:1px dashed var(--g8); border-radius:8px; font-size:12px;
            font-weight:700; color:var(--g5); cursor:pointer; margin-top:6px; }
        .btn-nuevo-ins:hover { border-color:var(--brand); color:var(--brand); }
    </style>
</head>
<body>
<?php $nav_activo = 'compras'; include __DIR__ . '/../app/views/nav.php'; ?>


<main class="main">

    <!-- Barra de acciones del módulo Compras -->
    <div style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap;align-items:center">
        <a href="<?= APP_BASE ?>/inventario/compras.php"
           style="padding:9px 18px;background:var(--brand);color:#fff;border:none;border-radius:10px;font-size:13px;font-weight:700;text-decoration:none">
            Registrar Compra
        </a>
        <a href="<?= APP_BASE ?>/inventario/lista_compras.php"
           style="padding:9px 16px;background:#fff;color:var(--dark);border:1px solid var(--g8);border-radius:10px;font-size:13px;font-weight:700;text-decoration:none">
            &#128203; Lista de Compras
        </a>
        <a href="<?= APP_BASE ?>/inventario/"
           style="padding:9px 16px;background:#fff;color:var(--dark);border:1px solid var(--g8);border-radius:10px;font-size:13px;font-weight:700;text-decoration:none">
            &#128230; Inventario
        </a>
    </div>

    <?php if ($msg_ok):  ?><div class="alert alert-ok"><?= htmlspecialchars($msg_ok) ?></div><?php endif; ?>
    <?php if ($msg_err): ?><div class="alert alert-err"><?= htmlspecialchars($msg_err) ?></div><?php endif; ?>

    <!-- Formulario nueva compra -->
    <div class="card">
        <p class="card-title">Registrar Nueva Compra</p>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">

            <div class="top-row">
                <div class="fg">
                    <label>Proveedor (opcional)</label>
                    <select name="proveedor_id">
                        <option value="">— Sin proveedor —</option>
                        <?php foreach ($proveedores as $p): ?>
                        <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fg">
                    <label>Lugar de Compra</label>
                    <input type="text" name="lugar_compra" placeholder="Ej: Plaza minorista, D1, Almacenes...">
                </div>
                <div class="fg">
                    <label>Notas (opcional)</label>
                    <input type="text" name="notas" placeholder="Ej: factura #123, cotización...">
                </div>
            </div>

            <!-- Líneas de la compra -->
            <div id="lineas-container"></div>

            <div style="display:flex;gap:8px;align-items:center;margin-top:8px;flex-wrap:wrap">
                <button type="button" class="add-linea-btn" style="margin-top:0" onclick="agregarLinea()">+ Agregar ítem</button>
                <?php if (permiso_tiene('inventario','editar_existentes')): ?>
                <button type="button" class="btn-nuevo-ins" onclick="abrirModalNuevoIns()">+ Crear nuevo insumo</button>
                <?php endif; ?>
            </div>

            <div class="total-row">
                <span>Total compra:</span>
                <strong id="total-compra">$0</strong>
            </div>

            <button type="submit" class="btn-guardar">Guardar Compra y Actualizar Stock</button>
        </form>
    </div>

    <!-- ── Panel de filtros ─────────────────────────────────────────────── -->
    <div class="filtros-card">
        <form method="GET" action="" id="frmFiltros">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:6px">
                <span style="font-size:14px;font-weight:800">Filtrar historial</span>
                <?php if ($hay_filtro): ?>
                <div style="display:flex;gap:6px;flex-wrap:wrap">
                    <?php if ($f_lugar !== ''): ?><span class="filtro-activo">Lugar: <?= htmlspecialchars($f_lugar) ?></span><?php endif; ?>
                    <?php if ($f_item  !== ''): ?><span class="filtro-activo">Ítem: <?= htmlspecialchars($f_item) ?></span><?php endif; ?>
                    <?php if ($f_cat   !== ''): ?><span class="filtro-activo">Cat.: <?= htmlspecialchars($f_cat) ?></span><?php endif; ?>
                </div>
                <?php endif; ?>
            </div>

            <div class="filtros-grid">
                <div class="fg">
                    <label>Desde</label>
                    <input type="date" name="desde" value="<?= htmlspecialchars($f_desde) ?>"
                           max="<?= date('Y-m-d') ?>">
                </div>
                <div class="fg">
                    <label>Hasta</label>
                    <input type="date" name="hasta" value="<?= htmlspecialchars($f_hasta) ?>"
                           max="<?= date('Y-m-d') ?>">
                </div>
                <div class="fg">
                    <label>Lugar de compra</label>
                    <input type="text" name="lugar" value="<?= htmlspecialchars($f_lugar) ?>"
                           placeholder="Plaza, D1, Pernikes…" list="lst-lugares">
                    <datalist id="lst-lugares">
                        <?php
                        $lugares = db()->query(
                            "SELECT DISTINCT lugar_compra FROM compras
                             WHERE lugar_compra IS NOT NULL AND lugar_compra != ''
                             ORDER BY lugar_compra"
                        )->fetchAll(PDO::FETCH_COLUMN);
                        foreach ($lugares as $l): ?>
                        <option value="<?= htmlspecialchars($l) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div class="fg">
                    <label>Ítem (insumo)</label>
                    <input type="text" name="item" value="<?= htmlspecialchars($f_item) ?>"
                           placeholder="Carne, pollo, queso…" list="lst-items">
                    <datalist id="lst-items">
                        <?php foreach ($insumos as $ins): ?>
                        <option value="<?= htmlspecialchars($ins['nombre']) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div class="fg">
                    <label>Categoría</label>
                    <select name="cat">
                        <option value="">— Todas las categorías —</option>
                        <?php foreach ($categorias as $cat): ?>
                        <option value="<?= htmlspecialchars($cat) ?>"
                            <?= $f_cat === $cat ? 'selected' : '' ?>>
                            <?= htmlspecialchars(ucfirst($cat)) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fg">
                    <label>Ordenar por</label>
                    <select name="orden">
                        <option value="fecha"  <?= $f_orden === 'fecha'  ? 'selected' : '' ?>>Fecha (más reciente)</option>
                        <option value="lugar"  <?= $f_orden === 'lugar'  ? 'selected' : '' ?>>Lugar de compra A-Z</option>
                        <option value="total"  <?= $f_orden === 'total'  ? 'selected' : '' ?>>Total (mayor primero)</option>
                    </select>
                </div>
            </div>

            <div class="filtros-row">
                <button type="submit" class="btn-filtrar">Buscar</button>
                <?php if ($hay_filtro): ?>
                <a href="compras.php" class="btn-limpiar">✕ Limpiar filtros</a>
                <?php endif; ?>
                <span style="font-size:12px;color:var(--g5);margin-left:auto">
                    <?= count($compras) ?> compra<?= count($compras) !== 1 ? 's' : '' ?> encontrada<?= count($compras) !== 1 ? 's' : '' ?>
                </span>
            </div>
        </form>
    </div>

    <!-- Historial reciente -->
    <div class="card">
        <p class="card-title">
            <?php if ($hay_filtro): ?>
            Resultados
            <?php else: ?>
            Últimas Compras <span style="font-size:12px;font-weight:400;color:var(--g5)">(últimos 30 días)</span>
            <?php endif; ?>
        </p>
        <?php if (empty($compras)): ?>
        <p style="color:var(--g5); text-align:center; padding:20px 0">Sin compras para los filtros aplicados</p>
        <?php else: ?>
            <?php foreach ($compras as $cv):
                // Data para el modal de edición (embebido como JSON)
                $compra_js = json_encode([
                    'id'          => (int)$cv['id'],
                    'proveedor_id'=> $cv['proveedor_id'] ?? null,
                    'lugar'       => $cv['lugar_compra'] ?? '',
                    'notas'       => $cv['notas'] ?? '',
                    'lineas'      => array_map(fn($l) => [
                        'insumo_id' => (int)$l['insumo_id'],
                        'insumo'    => $l['insumo'],
                        'unidad'    => $l['unidad_medida'],
                        'cantidad'  => (float)$l['cantidad'],
                        'precio'    => (float)$l['precio_unitario'],
                    ], $cv['lineas'])
                ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
                $puede_editar = permiso_tiene('compras', 'editar_existentes');
            ?>
            <div class="historial-row">
                <div style="display:flex;justify-content:space-between;align-items:flex-start">
                    <div>
                        <div style="font-weight:700"><?= htmlspecialchars($cv['proveedor']) ?></div>
                        <div class="hist-fecha">
                            <?= date('d/m/Y H:i', strtotime($cv['fecha_compra'])) ?>
                            <?php if (!empty($cv['lugar_compra']) && $cv['lugar_compra'] !== 'Sin lugar'): ?>
                             · <strong><?= htmlspecialchars($cv['lugar_compra']) ?></strong>
                            <?php endif; ?>
                            · <?= $cv['num_lineas'] ?> ítem<?= $cv['num_lineas'] != 1 ? 's' : '' ?>
                            · <?= htmlspecialchars($cv['registrado_por'] ?? '—') ?>
                        </div>
                    </div>
                    <div class="hist-total">$<?= number_format($cv['total'], 0, ',', '.') ?></div>
                </div>

                <?php if (!empty($cv['lineas'])): ?>
                <div style="margin-top:6px;padding-left:8px;border-left:3px solid var(--g8)">
                    <?php foreach ($cv['lineas'] as $lin): ?>
                    <div style="font-size:12px;color:var(--g5);display:flex;justify-content:space-between">
                        <span><?= htmlspecialchars($lin['insumo']) ?> (<?= $lin['cantidad'] ?> <?= htmlspecialchars($lin['unidad_medida']) ?>)</span>
                        <span>$<?= number_format($lin['subtotal'], 0, ',', '.') ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- Botones de acción -->
                <div class="hist-acciones">
                    <?php if ($puede_editar): ?>
                    <button class="btn-accion btn-editar ic" title="Editar"
                            onclick="abrirEditar(<?= htmlspecialchars($compra_js, ENT_QUOTES) ?>)">
                        <?= IC_EDIT ?>
                    </button>
                    <?php endif; ?>
                    <?php if (permiso_tiene('compras', 'solo_propios')): ?>
                    <button class="btn-accion btn-duplicar ic" title="Duplicar"
                            onclick="duplicarCompra(<?= (int)$cv['id'] ?>)">
                        <?= IC_COPY ?>
                    </button>
                    <?php endif; ?>
                    <?php if ($puede_editar): ?>
                    <button class="btn-accion btn-eliminar ic" title="Eliminar"
                            onclick="eliminarCompra(<?= (int)$cv['id'] ?>, '<?= date('d/m/Y', strtotime($cv['fecha_compra'])) ?>', '$<?= number_format($cv['total'], 0, ',', '.') ?>')">
                        <?= IC_TRASH ?>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</main>

<!-- ── Mini-modal: crear nuevo insumo inline ────────────────────────── -->
<div class="modal-overlay" id="modalNuevoIns" role="dialog" aria-modal="true">
  <div class="modal-box" style="max-width:480px">
    <div class="modal-hdr">
      <span class="modal-title">Crear nuevo insumo</span>
      <button class="btn-close" onclick="cerrarModal('modalNuevoIns')">✕</button>
    </div>
    <div id="niAlert" style="display:none" class="alert alert-err"></div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
      <div class="fg" style="grid-column:1/-1">
        <label>Nombre *</label>
        <input type="text" id="ni2-nombre" placeholder="Ej: Aceite Vegetal">
      </div>
      <div class="fg">
        <label>Unidad *</label>
        <select id="ni2-unidad">
          <?php foreach ($UNIDADES_NI as $k => $v): ?>
          <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="fg">
        <label>Categoría</label>
        <select id="ni2-categoria">
          <?php foreach ($CATEGORIAS_NI as $k => $v): ?>
          <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($v) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="fg">
        <label>Stock de seguridad</label>
        <input type="number" id="ni2-stock-seg" placeholder="0" min="0" step="0.001" value="0">
      </div>
      <div class="fg">
        <label>Stock inicial</label>
        <input type="number" id="ni2-stock" placeholder="0" min="0" step="0.001" value="0">
      </div>
    </div>
    <div style="display:flex;gap:8px;margin-top:12px">
      <button class="btn-guardar" style="font-size:14px;padding:12px" onclick="guardarNuevoInsInline()">Crear insumo</button>
      <button onclick="cerrarModal('modalNuevoIns')" style="padding:12px 16px;background:var(--g9);border:1px solid var(--g8);border-radius:10px;font-size:14px;font-weight:700;cursor:pointer">Cancelar</button>
    </div>
  </div>
</div>

<!-- ── Modal de edición ───────────────────────────────────────────────── -->
<div class="modal-overlay" id="modalEditar" role="dialog" aria-modal="true">
    <div class="modal-box">
        <div class="modal-hdr">
            <span class="modal-title">Editar Compra #<span id="mEditId"></span></span>
            <button class="btn-close" onclick="cerrarModal()" aria-label="Cerrar">✕</button>
        </div>

        <div id="mEditAlert" style="display:none" class="alert alert-err"></div>

        <div class="top-row">
            <div class="fg">
                <label>Proveedor (opcional)</label>
                <select id="mEditProveedor">
                    <option value="">— Sin proveedor —</option>
                    <?php foreach ($proveedores as $p): ?>
                    <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="fg">
                <label>Lugar de Compra</label>
                <input type="text" id="mEditLugar" placeholder="Plaza minorista, D1…">
            </div>
            <div class="fg">
                <label>Notas</label>
                <input type="text" id="mEditNotas" placeholder="Factura, observaciones…">
            </div>
        </div>

        <div id="mEditLineas"></div>
        <button type="button" class="add-linea-btn" onclick="agregarLineaModal()">+ Agregar ítem</button>

        <div class="total-row">
            <span>Total:</span>
            <strong id="mEditTotal">$0</strong>
        </div>

        <div style="display:flex;gap:10px;margin-top:16px">
            <button class="btn-guardar" onclick="guardarEdicion()" style="flex:1">
                Guardar cambios
            </button>
            <button onclick="cerrarModal()"
                    style="padding:14px 20px;background:var(--g9);border:1px solid var(--g8);border-radius:12px;font-size:15px;font-weight:700;cursor:pointer">
                Cancelar
            </button>
        </div>
    </div>
</div>

<script>
const INSUMOS = <?= $insumos_js ?>;
// Construir mapa id→insumo para acceso rápido al seleccionar un ítem
const INSUMO_MAP = {};
INSUMOS.forEach(i => { INSUMO_MAP[i.id] = i; });

let lineaCount = 0;

// Opciones del select: incluye data-attributes para presentación y equivalencia
const opcionesInsumos = '<option value="">— Seleccionar insumo —</option>'
    + INSUMOS.map(i =>
        `<option value="${i.id}"
                 data-costo="${i.costo}"
                 data-unidad="${escHtml(i.unidad)}"
                 data-presentacion="${escHtml(i.presentacion || '')}"
                 data-cant-pres="${i.cant_pres || ''}"
                 data-precio-pres="${i.precio_pres || ''}"
                 data-equiv-cant="${i.equiv_cant || ''}"
                 data-equiv-unidad="${escHtml(i.equiv_unidad || '')}">
            ${escHtml(i.nombre)} (${escHtml(i.unidad)})
         </option>`
    ).join('');

// Agregar al menos una línea al cargar la página
window.addEventListener('DOMContentLoaded', () => agregarLinea());

/* ── Agregar nueva línea de compra ──────────────────────────────────────── */
function agregarLinea() {
    lineaCount++;
    const n = lineaCount;
    const div = document.createElement('div');
    div.className = 'linea';
    div.id = 'linea-' + n;
    div.innerHTML = `
        <!-- Fila 1: selector de insumo -->
        <div class="linea-sel">
            <select id="sel-ins-${n}" name="lineas[${n}][insumo_id]"
                    onchange="selectInsumo(${n})" required>
                ${opcionesInsumos}
            </select>
        </div>

        <!-- Fila 2: panel informativo de presentación (solo lectura).
             Se muestra al seleccionar un insumo y muestra el tipo de empaque,
             la unidad básica, la cantidad por empaque y la equivalencia física
             para dar contexto visual de qué se está comprando. -->
        <div class="linea-pres" id="pres-block-${n}">
            <div class="pres-info-panel">
                <span>📦</span>
                <span class="pres-badge"       id="pres-tipo-lbl-${n}">—</span>
                <span class="pres-info-sep">·</span>
                <span class="pres-info-detail" id="pres-unidad-lbl-${n}">—</span>
                <span class="pres-info-sep">·</span>
                <span class="pres-info-detail" id="pres-cant-lbl-${n}">—</span>
                <span class="pres-equiv-badge" id="pres-equiv-lbl-${n}" style="display:none"></span>
                <span class="pres-total-hint"  id="pres-total-hint-${n}" style="display:none"></span>
            </div>
        </div>

        <!-- Fila 3: cantidad | precio/unidad | total $ | delete
             Triángulo bidireccional: llenar cualquier 2 → calcula el tercero.
             • cant × precio  → total
             • total ÷ precio → cant
             • total ÷ cant   → precio  -->
        <div class="linea-units" id="units-row-${n}">
            <div class="fu-col">
                <span class="fu-lbl" id="cant-label-${n}">Cantidad</span>
                <input type="number" name="lineas[${n}][cantidad]" id="cant-${n}"
                       placeholder="Cantidad" min="0.001" step="0.001"
                       title="Cuántas unidades/empaques compras"
                       oninput="calcLinea(${n},'cant')" required>
                <span class="equiv-hint" id="equiv-hint-${n}"></span>
            </div>
            <div class="fu-col">
                <span class="fu-lbl" id="precio-label-${n}">Precio/unidad</span>
                <input type="number" name="lineas[${n}][precio_unitario]" id="precio-u-${n}"
                       placeholder="Precio/u" min="0" step="0.0001"
                       title="Precio por unidad básica. Se calcula automáticamente si usas el bloque de presentación."
                       oninput="calcLinea(${n},'precio')" required>
            </div>
            <div class="fu-col">
                <span class="fu-lbl">Total ($)</span>
                <input type="number" id="total-${n}" class="total-input"
                       placeholder="Total" min="0" step="1"
                       title="Total pagado por esta línea. Puedes llenarlo y se calculará automáticamente la cantidad o precio faltante."
                       oninput="calcLinea(${n},'total')">
            </div>
            <button type="button" class="btn-rm" onclick="quitarLinea(${n})" title="Quitar ítem">−</button>
        </div>

        <!-- Selector de presentación catalogada (mig 039) — se muestra si el insumo tiene catálogo -->
        <div class="pres-cat-block" id="pres-cat-block-${n}" style="display:none">
            <div class="pres-cat-panel">
                <div class="fu-lbl" style="margin-bottom:5px">📦 Presentación catalogada</div>
                <select id="pres-cat-sel-${n}" onchange="selectPresentacion(${n})">
                    <option value="">— Seleccionar presentación —</option>
                </select>
                <div id="pres-cat-detail-${n}" style="display:none;margin-top:8px">
                    <div class="pres-cat-detail">
                        <div class="fu-col">
                            <span class="fu-lbl">Nro. de presentaciones</span>
                            <input type="number" id="num-pres-${n}" placeholder="Ej: 3" min="1" step="1" oninput="calcDesdePres(${n})">
                        </div>
                        <div class="fu-col">
                            <span class="fu-lbl">Precio por presentación ($)</span>
                            <input type="number" id="ppres-in-${n}" placeholder="Ej: 8500" min="0" step="1" oninput="calcDesdePres(${n})">
                        </div>
                    </div>
                    <div class="pres-calc-hint" id="pres-calc-hint-${n}"></div>
                </div>
            </div>
        </div>

        <!-- Hidden: campos de presentación para snapshot al backend (migraciones 032/034 y 039) -->
        <input type="hidden" name="lineas[${n}][presentacion]"          id="hpres-${n}">
        <input type="hidden" name="lineas[${n}][cantidad_presentacion]" id="hcantpx-${n}">
        <input type="hidden" name="lineas[${n}][cant_presentaciones]"   id="hnumpres-${n}">
        <input type="hidden" name="lineas[${n}][precio_presentacion]"   id="hppres-${n}">
        <input type="hidden" name="lineas[${n}][presentacion_id]"       id="hpres-id-${n}">
    `;
    document.getElementById('lineas-container').appendChild(div);
}

/* ── Selección de insumo ──────────────────────────────────────────────────── */
// Al seleccionar un insumo puebla el panel informativo (solo lectura) con la
// presentación del insumo, pre-carga precio/unidad con el costo actual
// y calcula el Total inicial para la fila de transacción.
function selectInsumo(n) {
    const sel       = document.getElementById('sel-ins-' + n);
    const iid       = parseInt(sel.value);
    const ins       = INSUMO_MAP[iid];
    const presBlock = document.getElementById('pres-block-' + n);

    if (!ins) {
        if (presBlock) presBlock.style.display = 'none';
        const lblCant = document.getElementById('cant-label-' + n);
        if (lblCant) lblCant.textContent = 'Cantidad';
        return;
    }

    // Badge: tipo de empaque (Frasco, Lata, Paca…)
    const tipoLbl = document.getElementById('pres-tipo-lbl-' + n);
    if (tipoLbl) {
        const t = ins.presentacion || ins.unidad;
        tipoLbl.textContent = t.charAt(0).toUpperCase() + t.slice(1);
    }
    // Etiqueta de unidad básica (g, ml, lata, unidad…)
    const unidadLbl = document.getElementById('pres-unidad-lbl-' + n);
    if (unidadLbl) unidadLbl.textContent = ins.unidad;
    // Cantidad por presentación (ej: "900 ml/frasco")
    const cantLbl = document.getElementById('pres-cant-lbl-' + n);
    if (cantLbl) {
        if (ins.cant_pres > 0 && ins.presentacion && ins.presentacion !== ins.unidad) {
            cantLbl.textContent = `${ins.cant_pres} ${ins.unidad}/${ins.presentacion}`;
        } else if (ins.cant_pres > 0) {
            cantLbl.textContent = `${ins.cant_pres} ${ins.unidad}/und.`;
        } else {
            cantLbl.textContent = `unidad: ${ins.unidad}`;
        }
    }
    // Equivalencia física (ej: "= 160 g/lata") — solo si está configurada
    const equivLbl = document.getElementById('pres-equiv-lbl-' + n);
    if (equivLbl) {
        if (ins.equiv_cant > 0 && ins.equiv_unidad) {
            equivLbl.textContent = `= ${ins.equiv_cant} ${ins.equiv_unidad}/${ins.presentacion || ins.unidad}`;
            equivLbl.style.display = '';
        } else {
            equivLbl.style.display = 'none';
        }
    }
    presBlock.style.display = '';

    // Etiqueta dinámica del campo Cantidad: muestra la unidad del insumo
    const lblCant = document.getElementById('cant-label-' + n);
    if (lblCant) {
        lblCant.textContent = ins.presentacion && ins.presentacion !== ins.unidad
            ? `Cantidad (${ins.presentacion}s)` : `Cantidad (${ins.unidad})`;
    }

    // Selector de presentación catalogada (mig 039)
    const presCatBlock = document.getElementById('pres-cat-block-' + n);
    const presCatSel   = document.getElementById('pres-cat-sel-' + n);
    const hPresId      = document.getElementById('hpres-id-' + n);
    if (presCatBlock && presCatSel) {
        const cats = ins.pres_cat || [];
        if (cats.length > 0) {
            // Reconstruir opciones del selector
            let opts = '<option value="">— Seleccionar presentación —</option>';
            cats.forEach(p => {
                const prec = p.precio_referencia > 0 ? ` · $${Math.round(p.precio_referencia).toLocaleString('es-CO')}` : '';
                opts += `<option value="${p.id}" data-base="${p.cantidad_base}" data-ucomp="${escHtml(p.unidad_compra)}" data-ref="${p.precio_referencia||0}">${escHtml(p.nombre)} (${p.cantidad_base} ${escHtml(ins.unidad)}/${p.unidad_compra||'und'})${prec}</option>`;
            });
            presCatSel.innerHTML = opts;
            presCatBlock.style.display = '';
            // Pre-seleccionar la predeterminada si existe
            const pred = cats.find(p => p.es_predeterminada == 1);
            if (pred) {
                presCatSel.value = pred.id;
                selectPresentacion(n);
            }
        } else {
            presCatBlock.style.display = 'none';
            if (hPresId) hPresId.value = '';
            document.getElementById('pres-cat-detail-' + n).style.display = 'none';
        }
    }

    // Pre-cargar precio/unidad con el costo actual (solo si no hay presentación catalogada pre-cargada)
    const precioUEl = document.getElementById('precio-u-' + n);
    if (precioUEl && ins.costo > 0 && !precioUEl.value) precioUEl.value = ins.costo;

    // Sugerir cantidad = 1 si el campo está vacío
    const cantEl = document.getElementById('cant-' + n);
    if (cantEl && !cantEl.value) cantEl.value = 1;

    // Calcular Total y sincronizar datos
    calcLinea(n, 'precio');
    _syncHiddenPres(n);
    _actualizarHintCant(n);
}

/* ── Cálculo bidireccional de la FILA PRINCIPAL (triángulo cant-precio-total) ─
   source: campo que acaba de cambiar → 'cant' | 'precio' | 'total'
   Llenar cualquier 2 de los 3 → calcula el tercero automáticamente.
   Mismo concepto bidireccional que en inventario/index.php pero para la
   fila de cantidades totales de la compra. ──────────────────────────────── */
function calcLinea(n, source) {
    const cantEl   = document.getElementById('cant-'    + n);
    const precioEl = document.getElementById('precio-u-'+ n);
    const totalEl  = document.getElementById('total-'   + n);

    let cant  = parseFloat(cantEl?.value)  || 0;
    let precio= parseFloat(precioEl?.value)|| 0;
    let total = parseFloat(totalEl?.value) || 0;

    // Calcular el campo faltante según cuál cambió
    if (source === 'cant' || source === 'precio') {
        // Cantidad o precio cambiaron → recalcular total
        if (cant > 0 && precio > 0) {
            const t = Math.round(cant * precio * 100) / 100;
            if (totalEl) totalEl.value = isFinite(t) ? t : '';
        } else {
            // Si falta alguno, limpiar total para evitar valores stale
            if (totalEl) totalEl.value = '';
        }
    } else if (source === 'total') {
        // Guard: ignorar total inválido (0, negativo, NaN)
        if (!total || total <= 0 || !isFinite(total)) {
            if (cantEl)   cantEl.value  = '';
            if (precioEl) precioEl.value = '';
            recalcularTotal();
            return;
        }
        // Total cambió → calcular el campo que falte (división segura)
        if (precio > 0 && total > 0) {
            const c = total / precio;
            if (isFinite(c) && c > 0) { cantEl.value = Math.round(c * 1000) / 1000; cant = c; }
        } else if (cant > 0 && total > 0) {
            const p = total / cant;
            if (isFinite(p) && p > 0) { precioEl.value = Math.round(p * 10000) / 10000; precio = p; }
        }
    }

    // Sincronizar snapshot de presentación y actualizar hints
    _actualizarHintCant(n);
    _syncHiddenPres(n);
    recalcularTotal();
}

/* ── Hint dinámico: "= X unidades totales" en el panel y bajo el campo de cantidad ──
   Muestra el total físico (cant × cant_pres o cant × equiv_cant) para contexto. */
function _actualizarHintCant(n) {
    const hintPanel = document.getElementById('pres-total-hint-' + n); // en el panel info
    const hintUnits = document.getElementById('equiv-hint-'       + n); // bajo campo cant

    const sel  = document.getElementById('sel-ins-' + n);
    const ins  = INSUMO_MAP[parseInt(sel?.value)];
    const cant = parseFloat(document.getElementById('cant-' + n)?.value) || 0;

    let hint = '';
    if (ins && cant > 0) {
        if (ins.cant_pres > 1) {
            // Presentación con múltiples unidades: ej. "3 frascos = 2700 ml"
            const total = cant * ins.cant_pres;
            hint = `= ${total.toLocaleString('es-CO', {maximumFractionDigits:2})} ${ins.unidad} total`;
        } else if (ins.equiv_cant > 0 && ins.equiv_unidad) {
            // Equivalencia física: ej. "3 lonchas = 90 g"
            const totalF = cant * ins.equiv_cant;
            hint = `= ${totalF.toLocaleString('es-CO', {maximumFractionDigits:2})} ${ins.equiv_unidad} total`;
        }
    }

    if (hintPanel) { hintPanel.textContent = hint; hintPanel.style.display = hint ? '' : 'none'; }
    if (hintUnits) { hintUnits.textContent = hint; hintUnits.style.display = hint ? '' : 'none'; }
}

/* ── Sincronizar campos hidden de presentación para el snapshot POST ─────────
   Lee los datos del insumo seleccionado (inmutables en el panel info) y los
   guarda en los campos hidden para que el backend registre el snapshot de compra. */
function _syncHiddenPres(n) {
    const hPres   = document.getElementById('hpres-'    + n);
    const hCantpx = document.getElementById('hcantpx-'  + n);
    const hNumpres= document.getElementById('hnumpres-' + n);
    const hPpres  = document.getElementById('hppres-'   + n);

    const sel = document.getElementById('sel-ins-' + n);
    const ins = INSUMO_MAP[parseInt(sel?.value)];
    const presBlock = document.getElementById('pres-block-' + n);

    if (!ins || !ins.presentacion || !presBlock || presBlock.style.display === 'none') {
        if (hPres)    hPres.value    = '';
        if (hCantpx)  hCantpx.value  = '';
        if (hNumpres) hNumpres.value = '';
        if (hPpres)   hPpres.value   = '';
        return;
    }

    const cant = document.getElementById('cant-' + n)?.value || '';
    if (hPres)    hPres.value    = ins.presentacion;
    if (hCantpx)  hCantpx.value  = ins.cant_pres  || '';
    if (hNumpres) hNumpres.value = cant;
    if (hPpres)   hPpres.value   = ins.precio_pres || '';
}


function quitarLinea(n) {
    const el = document.getElementById('linea-' + n);
    if (el && document.querySelectorAll('.linea').length > 1) {
        el.remove();
        recalcularTotal();
    }
}

// calcSubtotal: función legacy que se sigue llamando desde algunos paths.
// Ahora delega a calcLinea para mantener un solo punto de verdad.
function calcSubtotal(n) {
    calcLinea(n, 'precio');
}

function recalcularTotal() {
    let total = 0;
    // Suma los subtotales de cada línea usando el campo total-N cuando está disponible
    // (más preciso en modo presentación donde cant × precio_unitario puede tener redondeo)
    document.querySelectorAll('.linea').forEach(linea => {
        const id = linea.id?.replace('linea-', '');
        if (id) {
            const totalLinea = parseFloat(document.getElementById('total-' + id)?.value) || 0;
            if (totalLinea > 0) { total += totalLinea; return; }
        }
        // Fallback: calcular desde cant × precio si total-N no tiene valor
        const cant   = parseFloat(linea.querySelector('[name*="cantidad"]')?.value)        || 0;
        const precio = parseFloat(linea.querySelector('[name*="precio_unitario"]')?.value) || 0;
        total += cant * precio;
    });
    document.getElementById('total-compra').textContent = formatPeso(total);
}

function formatPeso(n) {
    return '$' + formatMiles(n);
}
function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Modal de edición ──────────────────────────────────────────────────────────
// El modal usa el mismo patrón de presentación que el formulario principal.
// Los IDs llevan prefijo 'm' para no colisionar con los IDs del formulario de nueva compra.
let mEditCompraId = 0;
let mLineaCount   = 0;

function abrirEditar(data) {
    mEditCompraId = data.id;
    mLineaCount   = 0;

    document.getElementById('mEditId').textContent        = data.id;
    document.getElementById('mEditLugar').value           = data.lugar  || '';
    document.getElementById('mEditNotas').value           = data.notas  || '';
    document.getElementById('mEditAlert').style.display   = 'none';

    const sel = document.getElementById('mEditProveedor');
    sel.value = data.proveedor_id ?? '';

    // Cargar líneas existentes
    document.getElementById('mEditLineas').innerHTML = '';
    (data.lineas || []).forEach(l => agregarLineaModal(l));
    if ((data.lineas || []).length === 0) agregarLineaModal();

    recalcTotal();
    document.getElementById('modalEditar').classList.add('open');
    document.body.style.overflow = 'hidden';
}

function cerrarModal(id = 'modalEditar') {
    document.getElementById(id)?.classList.remove('open');
    document.body.style.overflow = '';
}

document.getElementById('modalEditar').addEventListener('click', e => {
    if (e.target === e.currentTarget) cerrarModal('modalEditar');
});
document.getElementById('modalNuevoIns').addEventListener('click', e => {
    if (e.target === e.currentTarget) cerrarModal('modalNuevoIns');
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        cerrarModal('modalEditar');
        cerrarModal('modalNuevoIns');
    }
});

/* Líneas del modal — misma estructura que el formulario principal */
function agregarLineaModal(prefill = null) {
    mLineaCount++;
    const n   = mLineaCount;
    const div = document.createElement('div');
    div.className = 'linea';
    div.id = 'mlin-' + n;

    // Opciones del select de insumos (mismas que en el formulario principal)
    const opts = '<option value="">— Seleccionar insumo —</option>'
        + INSUMOS.map(i =>
            `<option value="${i.id}"
                     data-costo="${i.costo}"
                     data-unidad="${escHtml(i.unidad)}"
                     data-presentacion="${escHtml(i.presentacion||'')}"
                     data-cant-pres="${i.cant_pres||''}"
                     data-precio-pres="${i.precio_pres||''}">
                ${escHtml(i.nombre)} (${escHtml(i.unidad)})
             </option>`
        ).join('');

    div.innerHTML = `
        <div class="linea-sel">
            <select id="msel-ins-${n}" name="mlineas[${n}][insumo_id]"
                    onchange="mSelectInsumo(${n})" required>${opts}</select>
        </div>
        <!-- Panel informativo de presentación del modal (solo lectura) -->
        <div class="linea-pres" id="mpres-block-${n}">
            <div class="pres-info-panel">
                <span>📦</span>
                <span class="pres-badge"       id="mpres-tipo-lbl-${n}">—</span>
                <span class="pres-info-sep">·</span>
                <span class="pres-info-detail" id="mpres-unidad-lbl-${n}">—</span>
                <span class="pres-info-sep">·</span>
                <span class="pres-info-detail" id="mpres-cant-lbl-${n}">—</span>
                <span class="pres-equiv-badge" id="mpres-equiv-lbl-${n}" style="display:none"></span>
                <span class="pres-total-hint"  id="mpres-total-hint-${n}" style="display:none"></span>
            </div>
        </div>
        <!-- Fila cantidad | precio/unidad | total$ | delete — igual que formulario principal -->
        <div class="linea-units" id="munits-row-${n}">
            <div class="fu-col">
                <span class="fu-lbl" id="mcant-label-${n}">Cantidad</span>
                <input type="number" name="mlineas[${n}][cantidad]" id="mcant-${n}"
                       placeholder="Cantidad" min="0.001" step="0.001"
                       oninput="mCalcLinea(${n},'cant')" required>
            </div>
            <div class="fu-col">
                <span class="fu-lbl" id="mprecio-label-${n}">Precio/unidad</span>
                <input type="number" name="mlineas[${n}][precio_unitario]" id="mprecio-u-${n}"
                       placeholder="Precio/u" min="0" step="0.0001"
                       oninput="mCalcLinea(${n},'precio')" required>
            </div>
            <div class="fu-col">
                <span class="fu-lbl">Total ($)</span>
                <input type="number" id="mtotal-${n}" class="total-input"
                       placeholder="Total" min="0" step="1"
                       oninput="mCalcLinea(${n},'total')">
            </div>
            <button type="button" class="btn-rm" onclick="quitarLineaModal(${n})">−</button>
        </div>
    `;
    document.getElementById('mEditLineas').appendChild(div);

    if (prefill) {
        const selEl = document.getElementById('msel-ins-' + n);
        selEl.value = prefill.insumo_id;
        document.getElementById('mcant-' + n).value     = prefill.cantidad;
        document.getElementById('mprecio-u-' + n).value = prefill.precio;
        mSelectInsumo(n);
        mCalcLinea(n, 'cant');
    }
}

/* Selección de insumo en el modal — igual que selectInsumo() pero con prefijo 'm'. */
function mSelectInsumo(n) {
    const sel       = document.getElementById('msel-ins-' + n);
    const iid       = parseInt(sel.value);
    const ins       = INSUMO_MAP[iid];
    const presBlock = document.getElementById('mpres-block-' + n);

    if (!ins) {
        if (presBlock) presBlock.style.display = 'none';
        const lbl = document.getElementById('mcant-label-' + n);
        if (lbl) lbl.textContent = 'Cantidad';
        return;
    }

    const tipoLbl = document.getElementById('mpres-tipo-lbl-' + n);
    if (tipoLbl) {
        const t = ins.presentacion || ins.unidad;
        tipoLbl.textContent = t.charAt(0).toUpperCase() + t.slice(1);
    }
    const unidadLbl = document.getElementById('mpres-unidad-lbl-' + n);
    if (unidadLbl) unidadLbl.textContent = ins.unidad;
    const cantLbl = document.getElementById('mpres-cant-lbl-' + n);
    if (cantLbl) {
        if (ins.cant_pres > 0 && ins.presentacion && ins.presentacion !== ins.unidad) {
            cantLbl.textContent = `${ins.cant_pres} ${ins.unidad}/${ins.presentacion}`;
        } else if (ins.cant_pres > 0) {
            cantLbl.textContent = `${ins.cant_pres} ${ins.unidad}/und.`;
        } else {
            cantLbl.textContent = `unidad: ${ins.unidad}`;
        }
    }
    const equivLbl = document.getElementById('mpres-equiv-lbl-' + n);
    if (equivLbl) {
        if (ins.equiv_cant > 0 && ins.equiv_unidad) {
            equivLbl.textContent = `= ${ins.equiv_cant} ${ins.equiv_unidad}/${ins.presentacion || ins.unidad}`;
            equivLbl.style.display = '';
        } else {
            equivLbl.style.display = 'none';
        }
    }
    presBlock.style.display = '';

    const lbl = document.getElementById('mcant-label-' + n);
    if (lbl) lbl.textContent = ins.presentacion && ins.presentacion !== ins.unidad
        ? `Cantidad (${ins.presentacion}s)` : `Cantidad (${ins.unidad})`;

    const precioUEl = document.getElementById('mprecio-u-' + n);
    if (precioUEl && ins.costo > 0 && !precioUEl.value) precioUEl.value = ins.costo;

    const cantEl = document.getElementById('mcant-' + n);
    if (cantEl && !cantEl.value) cantEl.value = 1;

    mCalcLinea(n, 'precio');
    _mActualizarHintCant(n);
}

/* Hint dinámico del total físico en el modal */
function _mActualizarHintCant(n) {
    const hintEl = document.getElementById('mpres-total-hint-' + n);
    const sel    = document.getElementById('msel-ins-' + n);
    const ins    = INSUMO_MAP[parseInt(sel?.value)];
    const cant   = parseFloat(document.getElementById('mcant-' + n)?.value) || 0;
    let hint = '';
    if (ins && cant > 0) {
        if (ins.cant_pres > 1) {
            hint = `= ${(cant * ins.cant_pres).toLocaleString('es-CO', {maximumFractionDigits:3})} ${ins.unidad} total`;
        } else if (ins.equiv_cant > 0 && ins.equiv_unidad) {
            hint = `= ${(cant * ins.equiv_cant).toLocaleString('es-CO', {maximumFractionDigits:2})} ${ins.equiv_unidad} total`;
        }
    }
    if (hintEl) { hintEl.textContent = hint; hintEl.style.display = hint ? '' : 'none'; }
}

/* Bidireccional de la fila principal del modal (cant-precio-total) */
function mCalcLinea(n, source) {
    const cantEl   = document.getElementById('mcant-'     + n);
    const precioEl = document.getElementById('mprecio-u-' + n);
    const totalEl  = document.getElementById('mtotal-'    + n);
    let cant  = parseFloat(cantEl?.value)  || 0;
    let precio= parseFloat(precioEl?.value)|| 0;
    let total = parseFloat(totalEl?.value) || 0;

    if (source === 'cant' || source === 'precio') {
        if (cant > 0 && precio > 0 && totalEl)
            totalEl.value = Math.round(cant * precio * 100) / 100;
    } else if (source === 'total') {
        if (!total || total <= 0) { recalcTotal(); return; }
        if (precio > 0) {
            const c = total / precio;
            if (isFinite(c) && c > 0) { cantEl.value = Math.round(c * 1000) / 1000; }
        } else if (cant > 0) {
            const p = total / cant;
            if (isFinite(p) && p > 0) { precioEl.value = Math.round(p * 10000) / 10000; }
        }
    }
    _mActualizarHintCant(n);
    recalcTotal();
}

function quitarLineaModal(n) {
    const el = document.getElementById('mlin-' + n);
    if (el && document.querySelectorAll('#mEditLineas .linea').length > 1) {
        el.remove();
        recalcTotal();
    }
}

function recalcTotal() {
    let total = 0;
    document.querySelectorAll('#mEditLineas .linea').forEach(linea => {
        const cant   = parseFloat(linea.querySelector('[name*="cantidad"]')?.value)        || 0;
        const precio = parseFloat(linea.querySelector('[name*="precio_unitario"]')?.value) || 0;
        total += cant * precio;
    });
    document.getElementById('mEditTotal').textContent = formatPeso(total);
}

async function guardarEdicion() {
    const alertEl = document.getElementById('mEditAlert');
    alertEl.style.display = 'none';

    const lineas = [];
    document.querySelectorAll('#mEditLineas .linea').forEach(linea => {
        const iid    = linea.querySelector('[name*="insumo_id"]')?.value;
        const cant   = linea.querySelector('[name*="cantidad"]')?.value;
        const precio = linea.querySelector('[name*="precio_unitario"]')?.value;
        if (iid && cant && precio) lineas.push({insumo_id: iid, cantidad: cant, precio_unitario: precio});
    });

    if (lineas.length === 0) {
        alertEl.textContent = 'Agrega al menos un ítem.';
        alertEl.style.display = 'block';
        return;
    }

    const fd = new FormData();
    fd.append('csrf_token',   document.querySelector('[name="csrf_token"]').value);
    fd.append('accion',       'editar');
    fd.append('compra_id',    mEditCompraId);
    fd.append('proveedor_id', document.getElementById('mEditProveedor').value);
    fd.append('lugar_compra', document.getElementById('mEditLugar').value);
    fd.append('notas',        document.getElementById('mEditNotas').value);
    lineas.forEach((l, i) => {
        fd.append(`lineas[${i}][insumo_id]`,       l.insumo_id);
        fd.append(`lineas[${i}][cantidad]`,         l.cantidad);
        fd.append(`lineas[${i}][precio_unitario]`,  l.precio_unitario);
    });

    const res  = await fetch('api/compra_crud.php', {method:'POST', body:fd});
    const data = await res.json();
    if (data.success) {
        cerrarModal();
        location.reload();
    } else {
        alertEl.textContent = data.error || 'Error al guardar.';
        alertEl.style.display = 'block';
    }
}

// ── Presentación catalogada (mig 039) ────────────────────────────────────────
function selectPresentacion(n) {
    const sel     = document.getElementById('pres-cat-sel-' + n);
    const pid     = parseInt(sel.value);
    const detail  = document.getElementById('pres-cat-detail-' + n);
    const hid     = document.getElementById('hpres-id-' + n);
    const hint    = document.getElementById('pres-calc-hint-' + n);

    if (!pid) {
        if (detail) detail.style.display = 'none';
        if (hid)    hid.value = '';
        if (hint)   hint.style.display = 'none';
        return;
    }

    const iid = parseInt(document.getElementById('sel-ins-' + n)?.value);
    const ins = INSUMO_MAP[iid];
    const pres = (ins?.pres_cat || []).find(p => p.id == pid);
    if (!pres) return;

    if (detail) detail.style.display = '';
    if (hid)    hid.value = pid;

    // Pre-llenar precio de referencia si existe y está vacío
    const ppresEl   = document.getElementById('ppres-in-' + n);
    const numPresEl = document.getElementById('num-pres-'  + n);
    if (ppresEl && pres.precio_referencia > 0 && !ppresEl.value) ppresEl.value = pres.precio_referencia;
    if (numPresEl  && !numPresEl.value) numPresEl.value = 1;

    calcDesdePres(n);
}

function calcDesdePres(n) {
    const sel = document.getElementById('pres-cat-sel-' + n);
    const pid = parseInt(sel?.value);
    if (!pid) return;

    const iid  = parseInt(document.getElementById('sel-ins-' + n)?.value);
    const ins  = INSUMO_MAP[iid];
    const pres = (ins?.pres_cat || []).find(p => p.id == pid);
    if (!pres) return;

    const numPres = parseFloat(document.getElementById('num-pres-'  + n)?.value) || 0;
    const pPres   = parseFloat(document.getElementById('ppres-in-' + n)?.value) || 0;
    const base    = parseFloat(pres.cantidad_base) || 0;
    if (!base) return;

    const cantEl   = document.getElementById('cant-'    + n);
    const precioEl = document.getElementById('precio-u-'+ n);
    const totalEl  = document.getElementById('total-'   + n);
    const hpres    = document.getElementById('hpres-'   + n);
    const hcantpx  = document.getElementById('hcantpx-' + n);
    const hnumpres = document.getElementById('hnumpres-'+ n);
    const hppres   = document.getElementById('hppres-'  + n);
    const hint     = document.getElementById('pres-calc-hint-' + n);

    if (numPres > 0) {
        const cantCanonica = Math.round(numPres * base * 10000) / 10000;
        if (cantEl) cantEl.value = cantCanonica;
        if (hnumpres) hnumpres.value = numPres;
    }
    if (pPres > 0) {
        const precioUnit = Math.round((pPres / base) * 10000) / 10000;
        if (precioEl)  precioEl.value = precioUnit;
        if (hppres)    hppres.value   = pPres;
    }
    if (numPres > 0 && pPres > 0) {
        if (totalEl)   totalEl.value  = Math.round(numPres * pPres);
    }
    if (hpres)   hpres.value   = pres.unidad_compra || '';
    if (hcantpx) hcantpx.value = base;

    if (hint) {
        if (numPres > 0 && base > 0) {
            const cantC = numPres * base;
            const precioU = pPres > 0 ? '$' + (pPres / base).toLocaleString('es-CO', {minimumFractionDigits:2, maximumFractionDigits:2}) + '/' + ins.unidad : '—';
            hint.textContent = `${numPres} × ${base} ${ins.unidad}/${pres.unidad_compra||'und'} = ${cantC.toLocaleString('es-CO', {maximumFractionDigits:2})} ${ins.unidad} · ${precioU}`;
            hint.style.display = '';
        }
    }

    recalcularTotal();
}

// ── Mini-modal: crear nuevo insumo inline ─────────────────────────────────────
function abrirModalNuevoIns() {
    document.getElementById('ni2-nombre').value   = '';
    document.getElementById('ni2-stock-seg').value = '0';
    document.getElementById('ni2-stock').value     = '0';
    document.getElementById('niAlert').style.display = 'none';
    document.getElementById('modalNuevoIns').classList.add('open');
    document.body.style.overflow = 'hidden';
    setTimeout(() => document.getElementById('ni2-nombre').focus(), 100);
}

async function guardarNuevoInsInline() {
    const nombre = document.getElementById('ni2-nombre').value.trim();
    if (!nombre) {
        document.getElementById('niAlert').textContent = 'El nombre es obligatorio.';
        document.getElementById('niAlert').style.display = 'block';
        return;
    }
    const fd = new FormData();
    fd.append('csrf_token',    document.querySelector('[name="csrf_token"]').value);
    fd.append('accion',        'crear');
    fd.append('nombre',        nombre);
    fd.append('unidad_medida', document.getElementById('ni2-unidad').value);
    fd.append('categoria',     document.getElementById('ni2-categoria').value);
    fd.append('stock_seguridad', document.getElementById('ni2-stock-seg').value || '0');
    fd.append('stock_actual',    document.getElementById('ni2-stock').value     || '0');
    fd.append('costo_actual',  '0');
    fd.append('presentacion',  '');
    fd.append('cantidad_presentacion', '0');
    fd.append('precio_presentacion',   '0');

    try {
        const r = await fetch('../inventario/api/insumo_crud.php', {method:'POST', body:fd});
        const d = await r.json();
        if (!d.success) {
            document.getElementById('niAlert').textContent = d.error || 'Error al crear.';
            document.getElementById('niAlert').style.display = 'block';
            return;
        }
        // Agregar al mapa local y reconstruir selects
        const nuevo = {
            id: d.id, nombre: nombre,
            unidad: document.getElementById('ni2-unidad').value,
            costo: 0, presentacion: null, cant_pres: null, precio_pres: null,
            equiv_cant: null, equiv_unidad: null, pres_cat: []
        };
        INSUMOS.push(nuevo);
        INSUMO_MAP[nuevo.id] = nuevo;

        // Agregar opción a todos los selects de insumos activos
        const newOpt = `<option value="${nuevo.id}">${escHtml(nuevo.nombre)} (${escHtml(nuevo.unidad)})</option>`;
        document.querySelectorAll('[id^="sel-ins-"]').forEach(s => {
            const opt = document.createElement('option');
            opt.value = nuevo.id;
            opt.textContent = `${nuevo.nombre} (${nuevo.unidad})`;
            s.appendChild(opt);
        });

        cerrarModal('modalNuevoIns');
        // Seleccionar automáticamente en la última línea si no tiene insumo
        const lineas = document.querySelectorAll('.linea');
        if (lineas.length > 0) {
            const ultima = lineas[lineas.length - 1];
            const id = ultima.id.replace('linea-', '');
            const sel = document.getElementById('sel-ins-' + id);
            if (sel && !sel.value) {
                sel.value = nuevo.id;
                selectInsumo(id);
            }
        }
    } catch(e) {
        document.getElementById('niAlert').textContent = 'Error de red.';
        document.getElementById('niAlert').style.display = 'block';
    }
}

// ── Duplicar ──────────────────────────────────────────────────────────────────
async function duplicarCompra(id) {
    if (!confirm(`¿Duplicar compra #${id}?\n\nSe creará una nueva compra con los mismos ítems y el stock se actualizará.`)) return;

    const fd = new FormData();
    fd.append('csrf_token', document.querySelector('[name="csrf_token"]').value);
    fd.append('accion',     'duplicar');
    fd.append('compra_id',  id);

    const res  = await fetch('api/compra_crud.php', {method:'POST', body:fd});
    const data = await res.json();
    if (data.success) {
        alert(`Compra duplicada. Nueva compra #${data.nuevo_id} registrada.`);
        location.reload();
    } else {
        alert('Error: ' + (data.error || 'No se pudo duplicar.'));
    }
}

// ── Eliminar ──────────────────────────────────────────────────────────────────
async function eliminarCompra(id, fecha, total) {
    if (!confirm(`¿Eliminar compra #${id} del ${fecha} por ${total}?\n\nEsto REVERTIRÁ el stock de todos sus ítems. Esta acción no se puede deshacer.`)) return;

    const fd = new FormData();
    fd.append('csrf_token', document.querySelector('[name="csrf_token"]').value);
    fd.append('accion',     'eliminar');
    fd.append('compra_id',  id);

    const res  = await fetch('api/compra_crud.php', {method:'POST', body:fd});
    const data = await res.json();
    if (data.success) {
        location.reload();
    } else {
        alert('Error: ' + (data.error || 'No se pudo eliminar.'));
    }
}
</script>
</body>
</html>
