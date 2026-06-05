<?php
/**
 * public_html/inventario/compras.php
 * Registro de compras de insumos.
 * Al guardar: actualiza costo_actual, stock_actual y recalcula productos afectados.
 */

require_once __DIR__ . '/../app/middleware/auth_check.php';
require_once __DIR__ . '/../app/models/CompraModel.php';
require_once __DIR__ . '/../app/models/InsumoModel.php';

permiso_requerir('compras', 'solo_propios');

$insumos = InsumoModel::todos();
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

        /* Fila 2: bloque de presentación (oculto hasta que se selecciona insumo) */
        .linea-pres {
            background:var(--g9);
            border:1px solid var(--g8);
            border-radius:10px;
            padding:10px 12px;
            display:none; /* JS lo muestra */
        }
        .pres-hdr {
            display:flex; align-items:center; gap:8px; margin-bottom:8px;
            font-size:11px; font-weight:700; text-transform:uppercase;
            letter-spacing:.5px; color:var(--g5);
        }
        .pres-grid {
            display:grid;
            grid-template-columns:auto 1fr 1fr 1fr;
            gap:8px;
            align-items:end;
        }
        @media(max-width:540px){
            .pres-grid { grid-template-columns:1fr 1fr; }
            .pres-grid .tipo-col { grid-column:1/-1; }
        }
        .pres-grid .fg-sm { display:flex; flex-direction:column; gap:3px; }
        .pres-grid .fg-sm label { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; color:var(--g5); }
        .pres-grid input, .pres-grid select {
            padding:8px 10px; border:2px solid var(--g8); border-radius:8px;
            font-size:14px; color:var(--dark); outline:none; width:100%;
        }
        .pres-grid input:focus, .pres-grid select:focus { border-color:var(--brand); }
        /* Badge visual del tipo de presentación */
        .tipo-badge {
            display:inline-flex; align-items:center; height:38px; padding:0 12px;
            background:#e0f2fe; color:#0369a1; border-radius:8px;
            font-size:13px; font-weight:700; white-space:nowrap;
        }
        /* Hint resultado del cálculo bidireccional */
        .pres-hint {
            font-size:11px; color:#0369a1; background:#e0f2fe;
            padding:3px 8px; border-radius:6px; text-align:center; white-space:nowrap;
            align-self:center; margin-top:16px;
        }

        /* Fila 3: nro.presentaciones | precio/unidad | total | delete */
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

            <button type="button" class="add-linea-btn" onclick="agregarLinea()">+ Agregar ítem</button>

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
                        <span><?= htmlspecialchars($lin['insumo']) ?> (<?= $lin['cantidad'] ?> <?= $lin['unidad_medida'] ?>)</span>
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

        <!-- Fila 2: bloque de presentación.
             Siempre se muestra al seleccionar un insumo.
             Pre-carga con los datos guardados del insumo; editable para esta compra específica.
             Replicado del modal de inventario: mismo layout y cálculo bidireccional. -->
        <div class="linea-pres" id="pres-block-${n}">
            <div class="pres-hdr">
                📦 Presentación de compra
                <span id="pres-hint-unidad-${n}" style="color:var(--brand);font-weight:600;font-size:12px;margin-left:auto"></span>
            </div>
            <div class="pres-grid">
                <div class="fg-sm tipo-col">
                    <label>Tipo de empaque</label>
                    <select id="pres-tipo-${n}" onchange="calcPres(${n},'tipo')">
                        <option value="">— Tipo —</option>
                    </select>
                </div>
                <div class="fg-sm">
                    <label>Und. básicas/empaque</label>
                    <input type="number" id="pres-cantx-${n}"
                           placeholder="Ej: 12" min="0.001" step="0.001"
                           title="Cuántas unidades básicas contiene cada empaque"
                           oninput="calcPres(${n},'cantx')">
                </div>
                <div class="fg-sm">
                    <label>Precio/empaque ($)</label>
                    <input type="number" id="pres-precio-${n}"
                           placeholder="Ej: 29000" min="0" step="1"
                           title="Precio que pagas por cada empaque (paca, frasco, etc.)"
                           oninput="calcPres(${n},'precio_pres')">
                </div>
                <div class="fg-sm">
                    <label>Costo/unidad ($)</label>
                    <input type="number" id="pres-costou-${n}"
                           placeholder="Calculado" min="0" step="0.0001"
                           title="Precio/empaque ÷ und./empaque = costo por unidad básica"
                           oninput="calcPres(${n},'costou')">
                </div>
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

        <!-- Hidden: campos de presentación para snapshot al backend (migraciones 032/034) -->
        <input type="hidden" name="lineas[${n}][presentacion]"          id="hpres-${n}">
        <input type="hidden" name="lineas[${n}][cantidad_presentacion]" id="hcantpx-${n}">
        <input type="hidden" name="lineas[${n}][cant_presentaciones]"   id="hnumpres-${n}">
        <input type="hidden" name="lineas[${n}][precio_presentacion]"   id="hppres-${n}">
    `;
    document.getElementById('lineas-container').appendChild(div);
    // Rellenar select de tipos de presentación desde listas_sistema si está disponible
    _poblarTiposPres(n);
}

/* ── Poblar el select de tipos de presentación ───────────────────────────── */
// Se usa el mismo set de opciones fijas que inventario (los valores de listas_sistema).
// Si la página no tiene acceso al server-side, se usa una lista hardcoded como fallback.
const TIPOS_PRES = [
    {v:'frasco',      l:'Frasco'},
    {v:'tarro',       l:'Tarro'},
    {v:'caja',        l:'Caja'},
    {v:'paca',        l:'Paca'},
    {v:'bolsa',       l:'Bolsa'},
    {v:'atado',       l:'Atado'},
    {v:'lata',        l:'Lata'},
    {v:'bloque',      l:'Bloque'},
    {v:'mediobloque', l:'Medio Bloque'},
    {v:'galon',       l:'Galón'},
    {v:'unidad',      l:'Unidad'},
    {v:'otra',        l:'Otra'},
];
function _poblarTiposPres(n) {
    const sel = document.getElementById('pres-tipo-' + n);
    if (!sel) return;
    TIPOS_PRES.forEach(t => {
        const o = document.createElement('option');
        o.value = t.v; o.textContent = t.l;
        sel.appendChild(o);
    });
}

/* ── Selección de insumo ──────────────────────────────────────────────────── */
// Se muestra SIEMPRE el bloque de presentación al seleccionar un insumo.
// Si el insumo tiene datos guardados (presentacion, cantidad_presentacion,
// precio_presentacion) se pre-cargan. Si no, los campos quedan vacíos para
// que el usuario los llene manualmente para esta compra.
// Misma experiencia que el modal de edición en inventario/index.php.
function selectInsumo(n) {
    const sel = document.getElementById('sel-ins-' + n);
    const iid = parseInt(sel.value);
    const ins = INSUMO_MAP[iid];
    const presBlock = document.getElementById('pres-block-' + n);

    if (!ins) {
        // No hay insumo seleccionado → ocultar bloque
        if (presBlock) presBlock.style.display = 'none';
        return;
    }

    // Mostrar bloque siempre que haya un insumo seleccionado
    presBlock.style.display = '';

    // Mostrar unidad básica del insumo como referencia en el encabezado del bloque
    const hintUnidad = document.getElementById('pres-hint-unidad-' + n);
    if (hintUnidad) hintUnidad.textContent = ins.unidad ? `(${ins.unidad})` : '';

    // Pre-cargar con datos almacenados del insumo (si existen)
    const tipSel   = document.getElementById('pres-tipo-'   + n);
    const cantxEl  = document.getElementById('pres-cantx-'  + n);
    const ppresEl  = document.getElementById('pres-precio-' + n);
    const costoUEl = document.getElementById('pres-costou-' + n);

    if (tipSel)  tipSel.value  = ins.presentacion || '';
    if (cantxEl) cantxEl.value = ins.cant_pres     || '';   // und./empaque almacenadas
    if (ppresEl) ppresEl.value = ins.precio_pres   || '';   // precio/empaque almacenado
    if (costoUEl) costoUEl.value = ins.costo       || '';   // costo/unidad almacenado

    // Etiqueta de cantidad varía según si hay presentación o no
    const lblCant = document.getElementById('cant-label-' + n);
    if (lblCant) lblCant.textContent = ins.cant_pres > 0 ? 'Nro. empaques' : 'Cantidad';

    const lblPrecio = document.getElementById('precio-label-' + n);
    if (lblPrecio) lblPrecio.textContent = 'Precio/unidad';

    // Sugerir cantidad = 1 si no hay valor todavía
    const cantEl = document.getElementById('cant-' + n);
    if (cantEl && !cantEl.value) cantEl.value = 1;

    // Disparar cálculo bidireccional de presentación con los datos pre-cargados
    if (ins.cant_pres > 0 || ins.precio_pres > 0 || ins.costo > 0) {
        calcPres(n, 'cantx');
    } else {
        // Sin datos de presentación: solo sugerir precio actual del insumo en precio/unidad
        const precioUEl = document.getElementById('precio-u-' + n);
        if (precioUEl && ins.costo > 0) precioUEl.value = ins.costo;
        calcLinea(n, 'precio');
    }
}

/* ── Cálculo bidireccional de la FILA PRINCIPAL (triángulo cant-precio-total) ─
   source: campo que acaba de cambiar → 'cant' | 'precio' | 'total'
   Llenar cualquier 2 de los 3 → calcula el tercero automáticamente.
   Mismo concepto que el triángulo de presentación (calcPres) pero para la
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
        // Si el bloque de presentación está activo, propagar precio/unidad hacia costo/unidad
        const presBlock = document.getElementById('pres-block-' + n);
        if (presBlock && presBlock.style.display !== 'none' && precio > 0) {
            const cantxEl = document.getElementById('pres-cantx-' + n);
            const cantx   = parseFloat(cantxEl?.value) || 0;
            if (cantx > 0) {
                // precio/unidad = precio_pres / cantx → precio_pres = precio × cantx
                const ppresEl = document.getElementById('pres-precio-' + n);
                if (ppresEl) ppresEl.value = Math.round(precio * cantx * 100) / 100;
                calcPres(n, 'precio_pres');
                return; // calcPres llamará a calcSubtotal al final
            }
            const costoUEl = document.getElementById('pres-costou-' + n);
            if (costoUEl) costoUEl.value = precio;
        }
    }

    // Actualizar hint de unidades totales cuando hay presentación activa
    _actualizarHintCant(n);
    // Sincronizar snapshots y actualizar total visual
    _syncHiddenPres(n);
    recalcularTotal();
}

/* ── Actualiza el hint de "= X unidades totales" bajo el campo de cantidad ── */
function _actualizarHintCant(n) {
    const hint    = document.getElementById('equiv-hint-' + n);
    if (!hint) return;
    const cantxEl = document.getElementById('pres-cantx-' + n);
    const cantEl  = document.getElementById('cant-' + n);
    const sel     = document.getElementById('sel-ins-' + n);
    const ins     = INSUMO_MAP[parseInt(sel?.value)];
    const cant    = parseFloat(cantEl?.value)  || 0;
    const cantx   = parseFloat(cantxEl?.value) || 0;

    if (cant > 0 && cantx > 1) {
        // Modo presentación: mostrar total en unidades básicas
        const total = cant * cantx;
        const fmt = total === Math.round(total)
            ? Math.round(total).toLocaleString('es-CO')
            : total.toLocaleString('es-CO', {maximumFractionDigits: 3});
        hint.textContent = `= ${fmt} ${ins?.unidad || 'unidades'} totales`;
        hint.style.display = '';
    } else if (cant > 0 && ins?.equiv_cant > 0 && ins?.equiv_unidad) {
        // Modo directo con equivalencia física (lonchas → g, latas → g)
        const totalF = cant * ins.equiv_cant;
        const fmt = totalF === Math.round(totalF)
            ? Math.round(totalF).toLocaleString('es-CO')
            : totalF.toLocaleString('es-CO', {maximumFractionDigits: 2});
        hint.textContent = `= ${fmt} ${ins.equiv_unidad} total`;
        hint.style.display = '';
    } else {
        hint.style.display = 'none';
    }
}

/* ── Cálculo bidireccional dentro del bloque de presentación ─────────────── */
// source: qué campo cambió → 'cantx' | 'precio_pres' | 'costou' | 'tipo'
// Misma lógica que calcCosto() en inventario/index.php
function calcPres(n, source) {
    const cantxEl    = document.getElementById('pres-cantx-'  + n); // und/presentación
    const precioPresEl = document.getElementById('pres-precio-' + n); // precio/presentación
    const costoUEl   = document.getElementById('pres-costou-'  + n); // costo/unidad

    const cantx    = parseFloat(cantxEl?.value)     || 0;
    const precioPr = parseFloat(precioPresEl?.value) || 0;
    const costoU   = parseFloat(costoUEl?.value)     || 0;

    // Calcular el campo faltante (el que NO acaba de cambiar)
    if (source !== 'costou' && cantx > 0 && precioPr > 0) {
        // Llenar costo/unidad desde los otros dos
        const calc = precioPr / cantx;
        if (costoUEl) costoUEl.value = Math.round(calc * 10000) / 10000;
    } else if (source !== 'precio_pres' && cantx > 0 && costoU > 0) {
        // Llenar precio/presentación desde los otros dos
        const calc = costoU * cantx;
        if (precioPresEl) precioPresEl.value = Math.round(calc * 100) / 100;
    } else if (source !== 'cantx' && precioPr > 0 && costoU > 0) {
        // Llenar cant/presentación desde los otros dos
        const calc = precioPr / costoU;
        if (cantxEl) cantxEl.value = Math.round(calc * 1000) / 1000;
    }

    // Propagar costo/unidad calculado al campo precio/unidad de la fila principal
    const cantxFinal  = parseFloat(cantxEl?.value)     || 0;
    const costoUFinal = parseFloat(costoUEl?.value)     || 0;
    const precioUEl   = document.getElementById('precio-u-' + n);
    if (precioUEl && costoUFinal > 0) {
        precioUEl.value = costoUFinal;
    } else if (precioUEl && cantxFinal > 0 && parseFloat(precioPresEl?.value) > 0) {
        precioUEl.value = Math.round((parseFloat(precioPresEl.value) / cantxFinal) * 10000) / 10000;
    }

    // Actualizar el campo Total con cant × precio_pres
    const cantEl = document.getElementById('cant-' + n);
    const totalEl = document.getElementById('total-' + n);
    const cant = parseFloat(cantEl?.value) || 0;
    const ppFinal = parseFloat(precioPresEl?.value) || 0;
    if (totalEl && cant > 0 && ppFinal > 0) {
        totalEl.value = Math.round(cant * ppFinal * 100) / 100;
    }

    // Sincronizar campos hidden y actualizar hint de unidades
    _syncHiddenPres(n);
    _actualizarHintCant(n);
    recalcularTotal();
}

/* Sincroniza los campos hidden de presentación para que el POST incluya el snapshot.
   Nro. presentaciones = campo cant-N (cantidad de empaques comprados).
   Tipo, und/empaque y precio/empaque vienen del bloque de presentación. */
function _syncHiddenPres(n) {
    const hPres   = document.getElementById('hpres-'    + n);
    const hCantpx = document.getElementById('hcantpx-'  + n);
    const hNumpres= document.getElementById('hnumpres-' + n);
    const hPpres  = document.getElementById('hppres-'   + n);

    const presBlock = document.getElementById('pres-block-' + n);
    if (!presBlock || presBlock.style.display === 'none') {
        // Bloque oculto → limpiar snapshots de presentación
        if (hPres)    hPres.value    = '';
        if (hCantpx)  hCantpx.value  = '';
        if (hNumpres) hNumpres.value = '';
        if (hPpres)   hPpres.value   = '';
        return;
    }

    const tipo   = document.getElementById('pres-tipo-'   + n)?.value || '';
    const cantx  = document.getElementById('pres-cantx-'  + n)?.value || '';
    const cant   = document.getElementById('cant-'        + n)?.value || ''; // nro empaques
    const ppres  = document.getElementById('pres-precio-' + n)?.value || '';

    if (hPres)    hPres.value    = tipo;
    if (hCantpx)  hCantpx.value  = cantx;
    if (hNumpres) hNumpres.value = cant;
    if (hPpres)   hPpres.value   = ppres;
}

/* ── Cambio manual en el campo de cantidad ──────────────────────────────── */
// Cuando el usuario cambia la cantidad de empaques (modo presentación)
// o unidades directas, actualiza Total y el hint de unidades totales.
function onCantChange(n) {
    // En modo presentación, actualizar Total = cant_empaques × precio/empaque
    const presBlock = document.getElementById('pres-block-' + n);
    const isPres    = presBlock && presBlock.style.display !== 'none';
    if (isPres) {
        const ppresEl = document.getElementById('pres-precio-' + n);
        const cantEl  = document.getElementById('cant-' + n);
        const totalEl = document.getElementById('total-' + n);
        const pp  = parseFloat(ppresEl?.value) || 0;
        const cant= parseFloat(cantEl?.value)  || 0;
        if (totalEl && pp > 0 && cant > 0) {
            totalEl.value = Math.round(cant * pp * 100) / 100;
        }
    }
    _syncHiddenPres(n);
    _actualizarHintCant(n);
    calcLinea(n, 'cant');
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
    return '$' + Math.round(n).toLocaleString('es-CO');
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

function cerrarModal() {
    document.getElementById('modalEditar').classList.remove('open');
    document.body.style.overflow = '';
}

document.getElementById('modalEditar').addEventListener('click', e => {
    if (e.target === e.currentTarget) cerrarModal();
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') cerrarModal();
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
        <!-- Bloque de presentación del modal — misma estructura que el formulario principal -->
        <div class="linea-pres" id="mpres-block-${n}">
            <div class="pres-hdr">
                📦 Presentación de compra
                <span id="mpres-hint-unidad-${n}" style="color:var(--brand);font-weight:600;font-size:12px;margin-left:auto"></span>
            </div>
            <div class="pres-grid">
                <div class="fg-sm tipo-col">
                    <label>Tipo de empaque</label>
                    <select id="mpres-tipo-${n}" onchange="mCalcPres(${n},'tipo')">
                        <option value="">— Tipo —</option>
                    </select>
                </div>
                <div class="fg-sm">
                    <label>Und. básicas/empaque</label>
                    <input type="number" id="mpres-cantx-${n}" placeholder="Ej: 12"
                           min="0.001" step="0.001" oninput="mCalcPres(${n},'cantx')">
                </div>
                <div class="fg-sm">
                    <label>Precio/empaque ($)</label>
                    <input type="number" id="mpres-precio-${n}" placeholder="Ej: 29000"
                           min="0" step="1" oninput="mCalcPres(${n},'precio_pres')">
                </div>
                <div class="fg-sm">
                    <label>Costo/unidad ($)</label>
                    <input type="number" id="mpres-costou-${n}" placeholder="Calculado"
                           min="0" step="0.0001" oninput="mCalcPres(${n},'costou')">
                </div>
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
    _poblarTiposPres2(n); // poblar select de tipos con prefijo 'm'

    if (prefill) {
        const selEl = document.getElementById('msel-ins-' + n);
        selEl.value = prefill.insumo_id;
        document.getElementById('mcant-' + n).value     = prefill.cantidad;
        document.getElementById('mprecio-u-' + n).value = prefill.precio;
        // Al pre-cargar, intentar mostrar bloque de presentación si aplica
        mSelectInsumo(n, /*skipReset=*/true);
        // Calcular Total desde los valores pre-cargados
        mCalcLinea(n, 'cant');
    }
}

/* Poblar select de tipos en el modal (prefijo 'm') */
function _poblarTiposPres2(n) {
    const sel = document.getElementById('mpres-tipo-' + n);
    if (!sel) return;
    TIPOS_PRES.forEach(t => {
        const o = document.createElement('option');
        o.value = t.v; o.textContent = t.l;
        sel.appendChild(o);
    });
}

/* Selección de insumo en modal — mismo comportamiento que selectInsumo() del form principal.
   Siempre muestra el bloque de presentación y pre-carga datos almacenados del insumo. */
function mSelectInsumo(n, skipReset = false) {
    const sel = document.getElementById('msel-ins-' + n);
    const iid = parseInt(sel.value);
    const ins = INSUMO_MAP[iid];
    const presBlock = document.getElementById('mpres-block-' + n);

    if (!ins) { if (presBlock) presBlock.style.display = 'none'; return; }

    // Siempre mostrar el bloque
    presBlock.style.display = '';

    // Mostrar unidad como referencia
    const hintU = document.getElementById('mpres-hint-unidad-' + n);
    if (hintU) hintU.textContent = ins.unidad ? `(${ins.unidad})` : '';

    const tipSel = document.getElementById('mpres-tipo-' + n);
    if (tipSel) tipSel.value = ins.presentacion || '';
    const cxEl = document.getElementById('mpres-cantx-' + n);
    if (cxEl) cxEl.value = ins.cant_pres || '';
    const ppEl = document.getElementById('mpres-precio-' + n);
    if (true) { // siempre ejecutar bloque de pre-carga
        if (ppEl && !skipReset) ppEl.value = ins.precio_pres || '';
        const cuEl = document.getElementById('mpres-costou-' + n);
        if (cuEl) cuEl.value = ins.costo || '';

        const lbl = document.getElementById('mcant-label-' + n);
        if (lbl) lbl.textContent = ins.cant_pres > 0 ? 'Nro. empaques' : 'Cantidad';

        if (!skipReset) {
            const cantEl = document.getElementById('mcant-' + n);
            if (cantEl && !cantEl.value) cantEl.value = 1;
            if (ins.cant_pres > 0 || ins.precio_pres > 0) mCalcPres(n, 'cantx');
            else mCalcLinea(n, 'precio');
        }
    } else { // bloque "else" vacío, siempre entra el if(true)
        // Sin datos previos de presentación → mostrar bloque vacío de todas formas
        presBlock.style.display = '';
        const lbl = document.getElementById('mcant-label-' + n);
        if (lbl) lbl.textContent = 'Cantidad';
        const plbl = document.getElementById('mprecio-label-' + n);
        if (plbl) plbl.textContent = 'Precio/unidad';
        const prEl = document.getElementById('mprecio-u-' + n);
        if (prEl && ins && ins.costo > 0 && !skipReset) prEl.value = ins.costo;
        mCalcLinea(n, 'precio');
    }
}

/* Cálculo bidireccional en bloque presentación del modal */
function mCalcPres(n, source) {
    const cxEl = document.getElementById('mpres-cantx-' + n);
    const ppEl = document.getElementById('mpres-precio-' + n);
    const cuEl = document.getElementById('mpres-costou-' + n);
    const cantx = parseFloat(cxEl?.value) || 0;
    const pp    = parseFloat(ppEl?.value) || 0;
    const cu    = parseFloat(cuEl?.value) || 0;

    if (source !== 'costou' && cantx > 0 && pp > 0) {
        if (cuEl) cuEl.value = Math.round((pp / cantx) * 10000) / 10000;
    } else if (source !== 'precio_pres' && cantx > 0 && cu > 0) {
        if (ppEl) ppEl.value = Math.round(cu * cantx * 100) / 100;
    } else if (source !== 'cantx' && pp > 0 && cu > 0) {
        if (cxEl) cxEl.value = Math.round((pp / cu) * 1000) / 1000;
    }

    const cuFinal = parseFloat(cuEl?.value) || 0;
    const puEl = document.getElementById('mprecio-u-' + n);
    if (puEl && cuFinal > 0) puEl.value = cuFinal;

    // Actualizar Total del modal = cant × precio/empaque
    const cantEl  = document.getElementById('mcant-' + n);
    const totalEl = document.getElementById('mtotal-' + n);
    const cant = parseFloat(cantEl?.value) || 0;
    const ppFinal = parseFloat(ppEl?.value) || 0;
    if (totalEl && cant > 0 && ppFinal > 0) totalEl.value = Math.round(cant * ppFinal * 100) / 100;

    recalcTotal();
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
        if (precio > 0 && total > 0 && cantEl) {
            cantEl.value = Math.round((total / precio) * 1000) / 1000; cant = total / precio;
        } else if (cant > 0 && total > 0 && precioEl) {
            precioEl.value = Math.round((total / cant) * 10000) / 10000; precio = total / cant;
        }
        // Propagar precio/unidad hacia bloque de presentación
        const presBlock = document.getElementById('mpres-block-' + n);
        if (presBlock && presBlock.style.display !== 'none' && precio > 0) {
            const cxEl2 = document.getElementById('mpres-cantx-' + n);
            const cantx2 = parseFloat(cxEl2?.value) || 0;
            if (cantx2 > 0) {
                const ppEl2 = document.getElementById('mpres-precio-' + n);
                if (ppEl2) ppEl2.value = Math.round(precio * cantx2 * 100) / 100;
                mCalcPres(n, 'precio_pres'); return;
            }
            const cuEl2 = document.getElementById('mpres-costou-' + n);
            if (cuEl2) cuEl2.value = precio;
        }
    }
    recalcTotal();
}

/* calcSubM: legacy, delega a mCalcLinea */
function calcSubM(n) { mCalcLinea(n, 'precio'); }

function quitarLineaModal(n) {
    const el = document.getElementById('mlin-' + n);
    if (el && document.querySelectorAll('#mEditLineas .linea').length > 1) {
        el.remove();
        recalcTotal();
    }
}

function calcSubM(n) {
    const cant   = parseFloat(document.getElementById('mcant-' + n)?.value)     || 0;
    const precio = parseFloat(document.getElementById('mprecio-u-' + n)?.value) || 0;
    const el = document.getElementById('msub-' + n);
    if (el) el.textContent = formatPeso(cant * precio);
    recalcTotal();
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
