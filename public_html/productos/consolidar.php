<?php
/**
 * productos/consolidar.php — Consolidar productos separados por tamaño en un único
 * producto con variantes (requiere migración 035 — tabla producto_variantes).
 *
 * Flujo en 3 pasos:
 *   GET                   → Paso 1: seleccionar producto base + productos a absorber
 *   POST accion=preview   → Paso 2: configurar variantes (etiqueta, precio, factor)
 *   POST accion=ejecutar  → Paso 3: ejecutar consolidación en transacción PDO
 *
 * La herramienta NUNCA modifica datos históricos de ventas. Las ventas antiguas
 * siguen apuntando a los IDs originales. Solo desactiva los productos absorbidos
 * (activo=0) y crea variantes en el producto base.
 */

require_once __DIR__ . '/../app/middleware/auth_check.php';
require_once __DIR__ . '/../app/helpers/AuditoriaHelper.php';

permiso_requerir('admin', 'admin_total');

$nav_activo = 'productos';
$pdo = db();

// Verificar migración 035 (prerequisito)
$tiene_035 = (int)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='producto_variantes'
       AND COLUMN_NAME='id'"
)->fetchColumn() > 0;

// Todos los productos activos
$productos = $pdo->query(
    "SELECT id, nombre, nombre2, tamano, categoria, precio_venta, stock_disponible
     FROM productos WHERE activo = 1 ORDER BY nombre"
)->fetchAll();

$uid   = (int)($_SESSION['usuario_id'] ?? 0);
$accion = $_POST['accion'] ?? '';
$error = '';
$exito = '';

// Cantidad del ingrediente crítico de un producto (para calcular factor automático)
function qty_critica(PDO $pdo, int $pid): float
{
    $st = $pdo->prepare(
        'SELECT cantidad_requerida FROM recetas WHERE producto_id = ? AND es_insumo_critico = 1 LIMIT 1'
    );
    $st->execute([$pid]);
    return (float)($st->fetchColumn() ?: 0.0);
}

// ── PASO 3: Ejecutar consolidación ───────────────────────────────────────────
$resultado = null;
if ($accion === 'ejecutar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verificar()) {
        $error = 'Token CSRF inválido. Recarga la página e inténtalo de nuevo.';
    } else {
        $base_id     = (int)($_POST['base_id'] ?? 0);
        $fuentes_ids = array_map('intval', (array)($_POST['fuentes_ids'] ?? []));
        $transferir  = !empty($_POST['transferir_stock']);

        $variantes_post = [];
        foreach ($fuentes_ids as $fid) {
            $variantes_post[$fid] = [
                'etiqueta'      => substr(trim($_POST["etiqueta_{$fid}"] ?? ''), 0, 80),
                'precio_venta'  => (float)($_POST["precio_{$fid}"] ?? 0),
                'factor_receta' => max(0.001, min(10.0, (float)($_POST["factor_{$fid}"] ?? 1.0))),
            ];
        }

        if (!$base_id || empty($fuentes_ids)) {
            $error = 'Faltan datos requeridos.';
        } elseif (!$tiene_035) {
            $error = 'La migración 035 no está aplicada. No se puede continuar.';
        } else {
            $invalida = array_filter($variantes_post, fn($v) => $v['etiqueta'] === '' || $v['precio_venta'] <= 0);
            if ($invalida) {
                $error = 'Cada variante debe tener etiqueta y precio mayor a 0.';
            }
        }

        if (!$error) {
            try {
                $pdo->beginTransaction();

                $stock_transferido = 0;
                $productos_desactivados = 0;

                foreach ($fuentes_ids as $fid) {
                    $v = $variantes_post[$fid];

                    // Crear variante en producto base
                    $pdo->prepare(
                        'INSERT INTO producto_variantes
                            (producto_id, etiqueta, precio_venta, factor_receta, activo, created_by)
                         VALUES (?, ?, ?, ?, 1, ?)
                         ON DUPLICATE KEY UPDATE
                            precio_venta  = VALUES(precio_venta),
                            factor_receta = VALUES(factor_receta),
                            activo        = 1'
                    )->execute([
                        $base_id,
                        $v['etiqueta'],
                        $v['precio_venta'],
                        $v['factor_receta'],
                        $uid,
                    ]);

                    // Transferir stock al producto base (opcional)
                    if ($transferir) {
                        $st = $pdo->prepare('SELECT stock_disponible FROM productos WHERE id = ?');
                        $st->execute([$fid]);
                        $stock_src = (int)$st->fetchColumn();
                        if ($stock_src > 0) {
                            $pdo->prepare(
                                'UPDATE productos SET stock_disponible = stock_disponible + ?, updated_by = ? WHERE id = ?'
                            )->execute([$stock_src, $uid, $base_id]);
                            $stock_transferido += $stock_src;
                        }
                    }

                    // Desactivar producto fuente
                    $pdo->prepare(
                        "UPDATE productos SET activo = 0, updated_by = ? WHERE id = ?"
                    )->execute([$uid, $fid]);
                    $productos_desactivados++;

                    log_registrar('productos', $fid, 'activo', '1', '0', 'UPDATE');
                }

                log_registrar('productos', $base_id, 'consolidacion', null,
                    implode(',', $fuentes_ids), 'UPDATE');

                $pdo->commit();

                $resultado = [
                    'desactivados'      => $productos_desactivados,
                    'stock_transferido' => $stock_transferido,
                    'variantes_creadas' => count($fuentes_ids),
                ];
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log('[QuickServe OS Consolidar] ' . $e->getMessage());
                $error = 'Error al ejecutar la consolidación. No se realizaron cambios.';
            }
        }
    }
}

// ── PASO 2: Preview ───────────────────────────────────────────────────────────
$preview = null;
if ($accion === 'preview' && $_SERVER['REQUEST_METHOD'] === 'POST' && !$resultado) {
    $base_id     = (int)($_POST['base_id']     ?? 0);
    $fuentes_ids = array_map('intval', array_filter((array)($_POST['fuentes_ids'] ?? [])));

    if (!$base_id) {
        $error = 'Selecciona el producto base.';
    } elseif (empty($fuentes_ids)) {
        $error = 'Selecciona al menos un producto a absorber.';
    } elseif (in_array($base_id, $fuentes_ids)) {
        $error = 'El producto base no puede estar en la lista de productos a absorber.';
    } elseif (!$tiene_035) {
        $error = 'La migración 035 (producto_variantes) no está aplicada. Aplícala antes de consolidar.';
    } else {
        $qty_base = qty_critica($pdo, $base_id);

        $filas = [];
        foreach ($fuentes_ids as $fid) {
            $prod = $pdo->prepare('SELECT nombre, tamano, precio_venta, stock_disponible FROM productos WHERE id = ?');
            $prod->execute([$fid]);
            $p = $prod->fetch();
            if (!$p) continue;

            $qty_fuente   = qty_critica($pdo, $fid);
            $factor_calc  = ($qty_base > 0 && $qty_fuente > 0)
                ? round($qty_fuente / $qty_base, 3)
                : 1.0;
            $factor_auto  = ($qty_base > 0 && $qty_fuente > 0);

            $etiqueta_def = $p['tamano'] !== 'unico' ? strtoupper($p['tamano']) : $p['nombre'];

            $filas[$fid] = [
                'nombre'       => $p['nombre'],
                'tamano'       => $p['tamano'],
                'precio'       => (float)$p['precio_venta'],
                'stock'        => (int)$p['stock_disponible'],
                'factor_calc'  => $factor_calc,
                'factor_auto'  => $factor_auto,
                'etiqueta_def' => $etiqueta_def,
            ];
        }

        $base = $pdo->prepare('SELECT nombre, nombre2 FROM productos WHERE id = ?');
        $base->execute([$base_id]);
        $base_info = $base->fetch();

        // Detectar es_base (mig.036) para mostrar en la comparativa de ingredientes
        $tiene_036 = (int)$pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='recetas'
               AND COLUMN_NAME='es_base'"
        )->fetchColumn() > 0;
        $col_base = $tiene_036 ? 'r.es_base' : '0 AS es_base';

        // Ingredientes del producto base para comparación
        $stmt_ing = $pdo->prepare(
            "SELECT i.nombre AS insumo, r.cantidad_requerida, r.es_insumo_critico, {$col_base}
             FROM recetas r JOIN insumos i ON i.id = r.insumo_id
             WHERE r.producto_id = ? ORDER BY r.es_insumo_critico DESC, i.nombre"
        );
        $stmt_ing->execute([$base_id]);
        $ing_base_list = $stmt_ing->fetchAll();

        // Ingredientes de cada fuente
        $ing_fuentes = [];
        foreach ($fuentes_ids as $fid) {
            $stmt_ing->execute([$fid]);
            $ing_fuentes[$fid] = $stmt_ing->fetchAll();
        }

        $preview = [
            'base_id'       => $base_id,
            'base_info'     => $base_info,
            'filas'         => $filas,
            'ing_base_list' => $ing_base_list,
            'ing_fuentes'   => $ing_fuentes,
            'tiene_036'     => $tiene_036,
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Consolidar Productos — <?= APP_NAME ?></title>
    <style>
        :root { --brand:#e94f37; --dark:#111827; --g2:#374151; --g5:#6b7280; --g8:#d1d5db; --g9:#f3f4f6; --white:#fff; --green:#059669; }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: system-ui, sans-serif; background: var(--g9); color: var(--dark); }
        .main { max-width: 880px; margin: 0 auto; padding: 24px 14px 60px; }
        .page-hdr { display: flex; align-items: flex-start; gap: 12px; flex-wrap: wrap; margin-bottom: 24px; }
        .page-title { font-size: 22px; font-weight: 800; }
        .page-sub { font-size: 13px; color: var(--g5); margin-top: 3px; }

        .card { background: var(--white); border: 1px solid var(--g8); border-radius: 14px; padding: 22px; margin-bottom: 20px; }
        .card-title { font-size: 15px; font-weight: 700; margin-bottom: 14px; display: flex; align-items: center; gap: 8px; }
        .step-num { width: 24px; height: 24px; border-radius: 50%; background: var(--brand); color: #fff;
                    display: inline-flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 800; flex-shrink:0; }

        .prod-list { display: flex; flex-direction: column; gap: 6px; }
        .prod-item { display: flex; align-items: center; gap: 8px; padding: 8px 10px;
                     border: 1px solid var(--g8); border-radius: 8px; cursor: pointer; }
        .prod-item:hover { background: var(--g9); }
        .prod-item.selected { border-color: var(--brand); background: #fff5f5; }
        .prod-item input[type=radio], .prod-item input[type=checkbox] { flex-shrink: 0; }
        .prod-nombre { font-size: 13px; font-weight: 600; }
        .prod-meta { font-size: 11px; color: var(--g5); margin-left: auto; text-align: right; }

        .alert { padding: 12px 16px; border-radius: 10px; font-size: 13px; margin-bottom: 16px; }
        .alert-err  { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        .alert-ok   { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }
        .alert-info { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }
        .alert-warn { background: #fffbeb; color: #92400e; border: 1px solid #fcd34d; }

        .btn { padding: 10px 20px; border-radius: 9px; font-size: 14px; font-weight: 700; cursor: pointer; border: none; }
        .btn-brand { background: var(--brand); color: #fff; }
        .btn-brand:hover { background: #c94330; }
        .btn-sec { background: var(--white); color: var(--dark); border: 1px solid var(--g8); }
        .btn-grn { background: #059669; color: #fff; }
        .btn-grn:hover { background: #047857; }

        .tbl-consolidar { width: 100%; border-collapse: collapse; font-size: 13px; }
        .tbl-consolidar th { background: var(--g9); font-size: 11px; font-weight: 700; text-transform: uppercase;
                             letter-spacing: .4px; padding: 9px 12px; text-align: left; color: var(--g5); }
        .tbl-consolidar td { padding: 10px 12px; border-top: 1px solid var(--g9); vertical-align: middle; }
        .tbl-consolidar input[type=text],
        .tbl-consolidar input[type=number] {
            padding: 6px 8px; border: 1px solid var(--g8); border-radius: 7px;
            font-size: 13px; width: 100%; outline: none;
        }
        .tbl-consolidar input:focus { border-color: var(--brand); }
        .badge-auto { background: #d1fae5; color: #065f46; padding: 1px 6px; border-radius: 999px;
                      font-size: 10px; font-weight: 600; }
        .badge-manual { background: #fffbeb; color: #92400e; padding: 1px 6px; border-radius: 999px;
                        font-size: 10px; font-weight: 600; }

        .tip { font-size: 12px; color: var(--g5); margin-top: 6px; }
        .divider { border: none; border-top: 1px solid var(--g9); margin: 16px 0; }

        .resultado-box { background: #d1fae5; border: 1px solid #6ee7b7; border-radius: 12px; padding: 20px; text-align: center; }
        .resultado-box h2 { font-size: 20px; color: #065f46; margin-bottom: 8px; }
        .resultado-box p { font-size: 14px; color: #065f46; margin-top: 6px; }

        @media (max-width: 639px) {
            .main { padding: 14px 10px 60px; }
            .tbl-consolidar .hide-sm { display: none; }
        }
    </style>
</head>
<body>
<?php $nav_activo = 'productos'; include __DIR__ . '/../app/views/nav.php'; ?>
<main class="main">

    <div class="page-hdr">
        <div>
            <h1 class="page-title">🔀 Consolidar Productos</h1>
            <p class="page-sub">Convierte productos separados por tamaño en un solo producto con variantes</p>
        </div>
        <a href="<?= APP_BASE ?>/productos/" class="btn btn-sec" style="margin-left:auto">
            ← Volver a Productos
        </a>
    </div>

    <?php if (!$tiene_035): ?>
    <div class="alert alert-err">
        <strong>La migración 035 no está aplicada.</strong> Ve a Admin → Migraciones y aplica
        <code>035_variantes_producto.sql</code> antes de usar esta herramienta.
    </div>
    <?php elseif ($resultado): ?>

    <!-- ── RESULTADO EXITOSO ───────────────────────────────────────────────── -->
    <div class="resultado-box">
        <h2>✅ Consolidación completada</h2>
        <p><?= $resultado['variantes_creadas'] ?> variante(s) creada(s) en el producto base.</p>
        <p><?= $resultado['desactivados'] ?> producto(s) absorbido(s) desactivado(s).</p>
        <?php if ($resultado['stock_transferido'] > 0): ?>
        <p><?= $resultado['stock_transferido'] ?> unidad(es) de stock transferida(s) al producto base.</p>
        <?php endif; ?>
        <p style="margin-top:14px">
            <a href="<?= APP_BASE ?>/productos/" class="btn btn-brand">Ir a Productos</a>
            &nbsp;
            <a href="<?= APP_BASE ?>/productos/consolidar.php" class="btn btn-sec">Otra consolidación</a>
        </p>
    </div>

    <?php elseif ($preview): ?>

    <!-- ── PASO 2: Configurar variantes ───────────────────────────────────── -->
    <?php if ($error): ?>
    <div class="alert alert-err"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="alert alert-info">
        <strong>Producto base:</strong>
        <?= htmlspecialchars($preview['base_info']['nombre']) ?>
        <?php if ($preview['base_info']['nombre2']): ?>
        · <em><?= htmlspecialchars($preview['base_info']['nombre2']) ?></em>
        <?php endif; ?>
        — Las variantes que configures abajo se agregarán a este producto.
    </div>

    <form method="POST" action="">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
        <input type="hidden" name="accion"   value="ejecutar">
        <input type="hidden" name="base_id"  value="<?= $preview['base_id'] ?>">
        <?php foreach (array_keys($preview['filas']) as $fid): ?>
        <input type="hidden" name="fuentes_ids[]" value="<?= $fid ?>">
        <?php endforeach; ?>

        <div class="card">
            <div class="card-title">
                <span class="step-num">2</span>
                Configurar variantes
            </div>
            <p style="font-size:13px;color:var(--g5);margin-bottom:14px">
                Ajusta la etiqueta, precio y factor de receta para cada variante.
                El <strong>factor</strong> multiplica los ingredientes al vender en modo demanda
                (por ejemplo: 1.5 = usa 50% más ingredientes que el producto base).
            </p>
            <div style="overflow-x:auto">
            <table class="tbl-consolidar">
                <thead>
                    <tr>
                        <th>Producto a absorber</th>
                        <th>Etiqueta de variante</th>
                        <th style="text-align:right">Precio de venta</th>
                        <th style="text-align:right" class="hide-sm">Factor receta</th>
                        <th style="text-align:right" class="hide-sm">Stock actual</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($preview['filas'] as $fid => $f): ?>
                <tr>
                    <td>
                        <div class="prod-nombre"><?= htmlspecialchars($f['nombre']) ?></div>
                        <div class="tip">tamano: <?= htmlspecialchars($f['tamano']) ?></div>
                    </td>
                    <td style="min-width:120px">
                        <input type="text" name="etiqueta_<?= $fid ?>"
                               value="<?= htmlspecialchars($f['etiqueta_def']) ?>"
                               placeholder="XL, Regular, Familiar…" maxlength="80" required>
                    </td>
                    <td style="min-width:110px">
                        <input type="number" name="precio_<?= $fid ?>"
                               value="<?= number_format($f['precio'], 2, '.', '') ?>"
                               step="100" min="0.01" required>
                    </td>
                    <td style="min-width:110px" class="hide-sm">
                        <input type="number" name="factor_<?= $fid ?>"
                               value="<?= $f['factor_calc'] ?>" step="0.001" min="0.001" max="10" required>
                        <div class="tip">
                            <?php if ($f['factor_auto']): ?>
                            <span class="badge-auto">calculado</span> vs ingrediente crítico
                            <?php else: ?>
                            <span class="badge-manual">manual</span> sin receta comparable
                            <?php endif; ?>
                        </div>
                    </td>
                    <td style="text-align:right" class="hide-sm">
                        <?= $f['stock'] ?> u
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>

        <?php if (!empty($preview['ing_base_list'])): ?>
        <div class="card" style="padding:16px 20px">
            <div class="card-title" style="margin-bottom:10px;font-size:13px">
                📋 Comparativa de ingredientes
                <span style="font-size:11px;font-weight:400;color:var(--g5);margin-left:8px">
                    Ingr. base (🔒) = cantidad fija; escalable = multiplica por factor de variante
                </span>
            </div>
            <?php
            // Construir mapa insumo→cantidad del base para comparar
            $map_base = [];
            foreach ($preview['ing_base_list'] as $r) {
                $map_base[$r['insumo']] = $r;
            }
            ?>
            <?php foreach ($preview['filas'] as $fid => $f): ?>
            <details style="margin-bottom:10px" <?= count($preview['filas']) <= 2 ? 'open' : '' ?>>
                <summary style="font-size:12px;font-weight:700;cursor:pointer;padding:4px 0;color:var(--g2)">
                    <?= htmlspecialchars($f['nombre']) ?> — vs Producto base
                </summary>
                <div style="overflow-x:auto;margin-top:8px">
                <table style="width:100%;font-size:11px;border-collapse:collapse">
                    <thead>
                        <tr style="background:var(--g9)">
                            <th style="padding:5px 8px;text-align:left;color:var(--g5)">Ingrediente</th>
                            <th style="padding:5px 8px;text-align:right;color:var(--g5)">Base (=<?= htmlspecialchars($preview['base_info']['nombre']) ?>)</th>
                            <th style="padding:5px 8px;text-align:right;color:var(--g5)"><?= htmlspecialchars($f['nombre']) ?></th>
                            <th style="padding:5px 8px;text-align:center;color:var(--g5)">Tipo</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    // Fusionar listas por nombre de insumo
                    $map_fid = [];
                    foreach ($preview['ing_fuentes'][$fid] as $r) {
                        $map_fid[$r['insumo']] = $r;
                    }
                    $all_insumos = array_unique(array_merge(array_keys($map_base), array_keys($map_fid)));
                    sort($all_insumos);
                    foreach ($all_insumos as $insumo_nombre):
                        $rb = $map_base[$insumo_nombre] ?? null;
                        $rf = $map_fid[$insumo_nombre]  ?? null;
                        $es_critico = ($rb['es_insumo_critico'] ?? $rf['es_insumo_critico'] ?? 0);
                        $es_base_ing = ($rb['es_base'] ?? $rf['es_base'] ?? 0);
                    ?>
                    <tr style="border-top:1px solid var(--g9)">
                        <td style="padding:5px 8px">
                            <?= htmlspecialchars($insumo_nombre) ?>
                            <?php if ($es_critico): ?>
                            <span style="background:#fef3c7;color:#92400e;font-size:9px;font-weight:700;padding:1px 5px;border-radius:99px">⭐crítico</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding:5px 8px;text-align:right;font-weight:700">
                            <?= $rb ? fmt_cantidad((float)$rb['cantidad_requerida'], 3) : '<span style="color:var(--g5)">—</span>' ?>
                        </td>
                        <td style="padding:5px 8px;text-align:right;font-weight:700">
                            <?= $rf ? fmt_cantidad((float)$rf['cantidad_requerida'], 3) : '<span style="color:var(--g5)">—</span>' ?>
                        </td>
                        <td style="padding:5px 8px;text-align:center">
                            <?php if ($es_base_ing): ?>
                            <span style="background:#d1fae5;color:#065f46;font-size:9px;font-weight:700;padding:1px 6px;border-radius:99px">🔒base</span>
                            <?php else: ?>
                            <span style="background:#eff6ff;color:#1e40af;font-size:9px;font-weight:700;padding:1px 6px;border-radius:99px">× factor</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php if ($f['factor_auto']): ?>
                <div class="tip" style="margin-top:6px">
                    Factor calculado = <?= $f['factor_calc'] ?> (ingrediente crítico fuente ÷ ingrediente crítico base).
                    Los ingredientes 🔒base siempre usan factor 1.0 independientemente de este valor.
                </div>
                <?php endif; ?>
            </details>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-title">
                <span class="step-num">3</span>
                Opciones adicionales
            </div>
            <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer">
                <input type="checkbox" name="transferir_stock" value="1" style="margin-top:2px">
                <div>
                    <div style="font-size:13px;font-weight:600">Transferir stock al producto base</div>
                    <div class="tip">
                        Suma el stock disponible de los productos absorbidos al producto base.
                        Stock total a transferir: <strong>
                        <?= array_sum(array_column($preview['filas'], 'stock')) ?> unidades
                        </strong>
                    </div>
                </div>
            </label>
        </div>

        <div class="alert alert-warn">
            <strong>⚠ Acción irreversible:</strong> Los productos absorbidos quedarán
            <strong>inactivos</strong> (no aparecerán en el POS ni en producción).
            Las ventas históricas conservan sus datos originales intactos.
        </div>

        <div style="display:flex;gap:10px;flex-wrap:wrap">
            <a href="<?= APP_BASE ?>/productos/consolidar.php" class="btn btn-sec">
                ← Volver a selección
            </a>
            <button type="submit" class="btn btn-grn">
                ✅ Confirmar consolidación
            </button>
        </div>
    </form>

    <?php else: ?>

    <!-- ── PASO 1: Seleccionar productos ──────────────────────────────────── -->
    <?php if ($error): ?>
    <div class="alert alert-err"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="alert alert-info">
        <strong>¿Para qué sirve esta herramienta?</strong><br>
        Si tienes "Sándwich Pollo XL" y "Sándwich Pollo L" como productos separados, puedes
        convertirlos en un único "Sándwich Pollo" con dos variantes (XL y L). Las ventas
        históricas no se modifican.
    </div>

    <form method="POST" action="">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
        <input type="hidden" name="accion" value="preview">

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px">

            <!-- Producto base -->
            <div class="card">
                <div class="card-title">
                    <span class="step-num">1</span>
                    Producto base
                    <span style="font-size:11px;color:var(--g5);font-weight:400">El que permanece activo</span>
                </div>
                <p class="tip" style="margin-bottom:10px">
                    Las variantes se agregarán a este producto. Su receta es la referencia para calcular los factores.
                </p>
                <div class="prod-list" id="base-list">
                    <?php foreach ($productos as $p): ?>
                    <label class="prod-item" id="base-<?= $p['id'] ?>">
                        <input type="radio" name="base_id" value="<?= $p['id'] ?>"
                               onchange="syncLists()">
                        <div>
                            <div class="prod-nombre"><?= htmlspecialchars($p['nombre']) ?></div>
                            <?php if ($p['nombre2']): ?>
                            <div class="tip"><?= htmlspecialchars($p['nombre2']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="prod-meta">
                            $<?= fmt_moneda($p['precio_venta']) ?><br>
                            <span style="color:<?= (int)$p['stock_disponible'] > 0 ? 'var(--green)' : 'var(--g5)' ?>">
                                <?= (int)$p['stock_disponible'] ?> u
                            </span>
                        </div>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Productos a absorber -->
            <div class="card">
                <div class="card-title">
                    <span class="step-num">2</span>
                    Productos a absorber
                    <span style="font-size:11px;color:var(--g5);font-weight:400">Se desactivarán</span>
                </div>
                <p class="tip" style="margin-bottom:10px">
                    Cada producto absorbido se convertirá en una variante del producto base.
                </p>
                <div class="prod-list" id="fuente-list">
                    <?php foreach ($productos as $p): ?>
                    <label class="prod-item" id="fuente-<?= $p['id'] ?>">
                        <input type="checkbox" name="fuentes_ids[]" value="<?= $p['id'] ?>"
                               onchange="syncLists()">
                        <div>
                            <div class="prod-nombre"><?= htmlspecialchars($p['nombre']) ?></div>
                            <?php if ($p['nombre2']): ?>
                            <div class="tip"><?= htmlspecialchars($p['nombre2']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="prod-meta">
                            $<?= fmt_moneda($p['precio_venta']) ?><br>
                            <span style="color:<?= (int)$p['stock_disponible'] > 0 ? 'var(--green)' : 'var(--g5)' ?>">
                                <?= (int)$p['stock_disponible'] ?> u
                            </span>
                        </div>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-brand">
            Ver previsualización →
        </button>
    </form>

    <script>
    function syncLists() {
        // Deshabilitar en lista de fuentes el producto seleccionado como base
        const baseVal = document.querySelector('input[name=base_id]:checked')?.value;
        document.querySelectorAll('#fuente-list .prod-item').forEach(item => {
            const cb = item.querySelector('input[type=checkbox]');
            const isBase = cb.value === baseVal;
            cb.disabled = isBase;
            item.style.opacity = isBase ? '.4' : '';
            if (isBase) { cb.checked = false; item.classList.remove('selected'); }
        });
        // Highlight selected items
        document.querySelectorAll('#fuente-list .prod-item').forEach(item => {
            const cb = item.querySelector('input[type=checkbox]');
            item.classList.toggle('selected', cb.checked);
        });
        document.querySelectorAll('#base-list .prod-item').forEach(item => {
            const rb = item.querySelector('input[type=radio]');
            item.classList.toggle('selected', rb.checked);
        });
    }
    syncLists();
    </script>

    <?php endif; ?>

</main>
</body>
</html>
