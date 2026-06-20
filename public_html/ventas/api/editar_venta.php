<?php
/**
 * ventas/api/editar_venta.php — Cargar y guardar edición de una venta existente.
 *
 * GET  ?id=X  → Devuelve cabecera + ítems de la venta, más catálogos (clientes, productos).
 * POST        → Aplica la edición dentro de una transacción PDO:
 *               revierte stock anterior → aplica nuevo stock → actualiza cabecera + detalles.
 */
require_once __DIR__ . '/../../app/middleware/auth_check.php';
require_once __DIR__ . '/../../app/helpers/AuditoriaHelper.php';

header('Content-Type: application/json; charset=utf-8');
permiso_requerir('ventas', 'editar_existentes');

// Detectar migración 042 (ventas.metodo_cobro) — con qué se cobró un fiado.
$tiene042 = false;
try {
    $tiene042 = (int)db()->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ventas'
           AND COLUMN_NAME='metodo_cobro'"
    )->fetchColumn() > 0;
} catch (\Exception $e) { $tiene042 = false; }

// ── GET: cargar datos para el modal ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) {
        echo json_encode(['success' => false, 'error' => 'ID inválido.']);
        exit;
    }

    $pdo = db();

    $colCobroSel = $tiene042 ? ', v.metodo_cobro' : ', NULL AS metodo_cobro';
    $stmt = $pdo->prepare(
        "SELECT v.id, v.cliente_id, v.metodo_pago, v.notas, v.estado,
                v.fecha_venta, v.fecha_pago, v.total{$colCobroSel}
         FROM ventas v WHERE v.id = ?"
    );
    $stmt->execute([$id]);
    $venta = $stmt->fetch();

    if (!$venta) {
        echo json_encode(['success' => false, 'error' => 'Venta no encontrada.']);
        exit;
    }
    if ($venta['estado'] === 'anulada') {
        echo json_encode(['success' => false, 'error' => 'No se puede editar una venta anulada.']);
        exit;
    }

    $items = $pdo->prepare(
        'SELECT vd.id, vd.producto_id, p.nombre AS producto_nombre,
                vd.cantidad, vd.precio_unitario, vd.es_combo,
                vd.variante_etiqueta
         FROM venta_detalles vd
         JOIN productos p ON p.id = vd.producto_id
         WHERE vd.venta_id = ?
         ORDER BY p.nombre'
    );
    // Si la columna variante_etiqueta no existe (mig. 035 no aplicada), se ignora silenciosamente
    try {
        $items->execute([$id]);
    } catch (\Exception $e) {
        // Fallback sin variante_etiqueta (mig. 035 no aplicada)
        $items = $pdo->prepare(
            'SELECT vd.id, vd.producto_id, p.nombre AS producto_nombre,
                    vd.cantidad, vd.precio_unitario, vd.es_combo,
                    NULL AS variante_etiqueta
             FROM venta_detalles vd
             JOIN productos p ON p.id = vd.producto_id
             WHERE vd.venta_id = ?
             ORDER BY p.nombre'
        );
        $items->execute([$id]);
    }

    $clientes = $pdo->query(
        "SELECT id, nombre FROM clientes WHERE activo = 1 ORDER BY nombre"
    )->fetchAll();

    // nombre2 incluido para mostrarlo en el selector de productos del modal de edición
    $productos = $pdo->query(
        "SELECT id, nombre, nombre2, tamano, precio_venta FROM productos WHERE activo = 1 ORDER BY nombre"
    )->fetchAll();

    echo json_encode([
        'success'   => true,
        'venta'     => $venta,
        'items'     => $items->fetchAll(),
        'clientes'  => $clientes,
        'productos' => $productos,
    ]);
    exit;
}

// ── POST: guardar edición ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido.']);
    exit;
}

if (!csrf_verificar()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Token CSRF inválido.']);
    exit;
}

$id = (int)($_POST['id'] ?? 0);
if (!$id) {
    echo json_encode(['success' => false, 'error' => 'ID de venta inválido.']);
    exit;
}

$metodos_ok  = ['efectivo', 'nequi', 'daviplata', 'bancolombia', 'fiado', 'obsequio'];
$metodo_pago = $_POST['metodo_pago'] ?? '';
if (!in_array($metodo_pago, $metodos_ok, true)) {
    echo json_encode(['success' => false, 'error' => 'Método de pago inválido.']);
    exit;
}

$cliente_id = (int)($_POST['cliente_id'] ?? 0) ?: null;
$notas      = substr(trim($_POST['notas'] ?? ''), 0, 500);

// fecha_venta: acepta "YYYY-MM-DD HH:MM" o "YYYY-MM-DDTHH:MM".
// Vacío = conservar la fecha original (COALESCE en el UPDATE).
// Se rechaza cualquier datetime futuro para evitar registros antedatados hacia adelante.
$fecha_venta = null;
$fv_raw = trim($_POST['fecha_venta'] ?? '');
if ($fv_raw && preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', $fv_raw)) {
    $fecha_venta = str_replace('T', ' ', substr($fv_raw, 0, 16)) . ':00';
    if ($fecha_venta > date('Y-m-d H:i:s')) {
        echo json_encode(['success' => false, 'error' => 'La fecha de la venta no puede ser futura.']);
        exit;
    }
}

// fecha_pago solo aplica a fiado; para los demás se descarta.
// Acepta fecha sola ("YYYY-MM-DD", del <input type=date>) o fecha+hora
// ("YYYY-MM-DD HH:MM"). Si viene solo fecha se usa el mediodía (evita
// corrimiento de día por zona horaria al guardar el DATETIME).
$fecha_pago = null;
if ($metodo_pago === 'fiado') {
    $fp = str_replace('T', ' ', trim($_POST['fecha_pago'] ?? ''));
    if (preg_match('/^(\d{4}-\d{2}-\d{2})( \d{2}:\d{2})?/', $fp, $m)) {
        $fecha_pago = (isset($m[2]) && $m[2] !== '')
            ? $m[1] . $m[2] . ':00'
            : $m[1] . ' 12:00:00';
    }
}

// metodo_cobro: con qué se cobró el fiado. Solo aplica a fiado YA cobrado
// (con fecha_pago); para los demás casos se descarta (NULL).
$metodo_cobro = null;
if ($metodo_pago === 'fiado' && $fecha_pago) {
    $mc = $_POST['metodo_cobro'] ?? '';
    if (in_array($mc, ['efectivo', 'nequi', 'daviplata', 'bancolombia'], true)) {
        $metodo_cobro = $mc;
    }
}

if ($metodo_pago === 'fiado' && !$cliente_id) {
    echo json_encode(['success' => false, 'error' => 'Para ventas a fiado debes seleccionar un cliente.']);
    exit;
}

// Validar y sanitizar ítems del carrito
$items_raw = json_decode($_POST['items_json'] ?? '[]', true);
if (!is_array($items_raw)) {
    echo json_encode(['success' => false, 'error' => 'Formato de ítems inválido.']);
    exit;
}

$carrito = [];
foreach ($items_raw as $item) {
    $pid      = (int)($item['producto_id']    ?? 0);
    $cantidad = (int)($item['cantidad']        ?? 0);
    $precio   = (float)($item['precio_unitario'] ?? 0);
    $esCombo  = !empty($item['es_combo']) ? 1 : 0;
    if (!$pid || $cantidad < 1 || $precio < 0) continue;
    $carrito[] = ['producto_id' => $pid, 'cantidad' => $cantidad, 'precio' => $precio, 'es_combo' => $esCombo];
}
if (empty($carrito)) {
    echo json_encode(['success' => false, 'error' => 'La venta debe tener al menos un producto válido.']);
    exit;
}

$uid = (int)($_SESSION['usuario_id'] ?? 0);
$pdo = db();

try {
    $pdo->beginTransaction();

    // ── 1. Leer y bloquear venta original ──────────────────────────────────────
    $vStmt = $pdo->prepare(
        'SELECT id, estado, metodo_pago, total, cliente_id FROM ventas WHERE id = ? FOR UPDATE'
    );
    $vStmt->execute([$id]);
    $ventaOld = $vStmt->fetch();

    if (!$ventaOld)                           throw new RuntimeException('Venta no encontrada.');
    if ($ventaOld['estado'] === 'anulada')    throw new RuntimeException('No se puede editar una venta anulada.');

    // ── 2. Revertir stock de los detalles anteriores ───────────────────────────

    // 2a. Líneas from_stock=1 → restaurar stock de producto terminado
    $oldStock = $pdo->prepare(
        'SELECT producto_id, SUM(cantidad) AS total
         FROM venta_detalles WHERE venta_id = ? AND from_stock = 1 GROUP BY producto_id'
    );
    $oldStock->execute([$id]);
    foreach ($oldStock->fetchAll() as $row) {
        $pdo->prepare(
            'UPDATE productos SET stock_disponible = stock_disponible + ?, updated_by = ? WHERE id = ?'
        )->execute([(int)$row['total'], $uid, (int)$row['producto_id']]);
    }

    // 2b. Líneas from_stock=0 → restaurar insumos vía receta.
    // COALESCE(vd.factor_receta_snap, 1.0): si la venta fue con variante, se restaura
    // la cantidad escalada que se descontó originalmente (mig. 035).
    // Mig. 036: es_base=1 → el factor fue siempre 1.0 para ese ingrediente.
    static $tiene036ev = null;
    if ($tiene036ev === null) {
        try {
            $tiene036ev = (int)$pdo->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='recetas'
                   AND COLUMN_NAME='es_base'"
            )->fetchColumn() > 0;
        } catch (\Exception $e) { $tiene036ev = false; }
    }
    $colEsBaseEv = $tiene036ev ? ', r.es_base' : ', 0 AS es_base';

    $oldIng = $pdo->prepare(
        "SELECT vd.cantidad, r.insumo_id, r.cantidad_requerida,
                GREATEST(p.unidades_por_receta, 1)       AS rinde,
                COALESCE(vd.factor_receta_snap, 1.0)     AS factor_receta{$colEsBaseEv}
         FROM venta_detalles vd
         JOIN recetas r ON r.producto_id = vd.producto_id
         JOIN productos p ON p.id = vd.producto_id
         WHERE vd.venta_id = ? AND vd.from_stock = 0"
    );
    $oldIng->execute([$id]);
    foreach ($oldIng->fetchAll() as $row) {
        $factor     = !empty($row['es_base']) ? 1.0 : (float)$row['factor_receta'];
        $devolucion = ((float)$row['cantidad_requerida'] / (int)$row['rinde'])
                    * (int)$row['cantidad']
                    * $factor;
        $pdo->prepare(
            'UPDATE insumos SET stock_actual = stock_actual + ?, updated_by = ? WHERE id = ?'
        )->execute([$devolucion, $uid, (int)$row['insumo_id']]);
    }

    // 2c. Líneas combo con combo_id → restaurar insumos extra del combo
    $oldCombo = $pdo->prepare(
        'SELECT vd.cantidad, vd.combo_id
         FROM venta_detalles vd
         WHERE vd.venta_id = ? AND vd.es_combo = 1 AND vd.combo_id IS NOT NULL'
    );
    $oldCombo->execute([$id]);
    foreach ($oldCombo->fetchAll() as $cl) {
        $extras = $pdo->prepare('SELECT insumo_id, cantidad FROM combo_insumos WHERE combo_id = ?');
        $extras->execute([(int)$cl['combo_id']]);
        foreach ($extras->fetchAll() as $ex) {
            $pdo->prepare(
                'UPDATE insumos SET stock_actual = stock_actual + ?, updated_by = ? WHERE id = ?'
            )->execute([(float)$ex['cantidad'] * (int)$cl['cantidad'], $uid, (int)$ex['insumo_id']]);
        }
    }

    // 2d. Revertir fiado del cliente anterior
    if ($ventaOld['metodo_pago'] === 'fiado' && $ventaOld['cliente_id']) {
        $pdo->prepare(
            'UPDATE clientes SET saldo_fiado = GREATEST(0, saldo_fiado - ?), updated_by = ? WHERE id = ?'
        )->execute([(float)$ventaOld['total'], $uid, (int)$ventaOld['cliente_id']]);
    }

    // ── 3. Eliminar detalles anteriores ────────────────────────────────────────
    // INMUTABILIDAD: los precios históricos se preservan mediante el flujo
    // DELETE + re-INSERT (no UPDATE). Esta es la única vía de corrección
    // por mala digitación. Los reportes históricos NO se ven afectados
    // porque operan sobre ventas completadas en sus propias fechas.
    $pdo->prepare('DELETE FROM venta_detalles WHERE venta_id = ?')->execute([$id]);

    // ── 4. Insertar nuevos detalles + descontar stock nuevo ────────────────────
    // Detectar migración 034 (nombre_snap, nombre2_snap en venta_detalles)
    static $tiene034ev = null;
    if ($tiene034ev === null) {
        try {
            $tiene034ev = (int)$pdo->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='venta_detalles'
                   AND COLUMN_NAME='nombre_snap'"
            )->fetchColumn() > 0;
        } catch (\Exception $e) { $tiene034ev = false; }
    }

    // Detectar migración 035 (variante_id, factor_receta_snap en venta_detalles)
    static $tiene035ev = null;
    if ($tiene035ev === null) {
        try {
            $tiene035ev = (int)$pdo->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='venta_detalles'
                   AND COLUMN_NAME='variante_id'"
            )->fetchColumn() > 0;
        } catch (\Exception $e) { $tiene035ev = false; }
    }

    // Al re-insertar, incluir snapshots activos (mig. 034 y 035).
    // El editor de ventas no soporta picker de variante → variante_id = NULL en la re-edición.
    // factor_receta_snap = NULL indica "sin variante", igual que una venta nueva sin variante.
    $cols035 = $tiene035ev ? ', variante_id, variante_etiqueta, factor_receta_snap' : '';
    $vals035 = $tiene035ev ? ', NULL, NULL, NULL' : '';

    if ($tiene034ev) {
        $stmtDetalle = $pdo->prepare(
            "INSERT INTO venta_detalles
                (venta_id, producto_id, cantidad, precio_unitario, subtotal,
                 from_stock, es_combo, combo_id,
                 nombre_snap, nombre2_snap,
                 created_by{$cols035})
             VALUES (:vid, :pid, :cant, :precio, :sub, :fs, :ec, :cid,
                     :nsnap, :n2snap, :uid{$vals035})"
        );
    } else {
        $stmtDetalle = $pdo->prepare(
            "INSERT INTO venta_detalles
                (venta_id, producto_id, cantidad, precio_unitario, subtotal,
                 from_stock, es_combo, combo_id, created_by{$cols035})
             VALUES (:vid, :pid, :cant, :precio, :sub, :fs, :ec, :cid, :uid{$vals035})"
        );
    }

    // También trae nombre y nombre2 para el snapshot (migración 034)
    $stmtProdInfo  = $pdo->prepare('SELECT stock_disponible, unidades_por_receta, nombre, nombre2 FROM productos WHERE id = ? FOR UPDATE');
    $stmtStockProd = $pdo->prepare('UPDATE productos SET stock_disponible = stock_disponible - ?, updated_by = ? WHERE id = ? AND stock_disponible >= ?');
    // NOTA: es_base no se usa al re-descontar (la re-edición no soporta variante →
    // factor 1.0), por eso NO se inyecta $colEsBaseEv aquí (esta query no aliasa la
    // tabla como 'r', y r.es_base rompería el SELECT). $colEsBaseEv sí se usa arriba
    // en la query de restauración (JOIN recetas r).
    $stmtReceta    = $pdo->prepare("SELECT insumo_id, cantidad_requerida FROM recetas WHERE producto_id = :pid");
    $stmtDescuento = $pdo->prepare('UPDATE insumos SET stock_actual = stock_actual - :desc, updated_by = :uid WHERE id = :id AND stock_actual >= :desc2');
    $stmtComboIns  = $pdo->prepare('SELECT ci.insumo_id, ci.cantidad, i.nombre AS insumo_nombre FROM combo_insumos ci JOIN insumos i ON i.id = ci.insumo_id WHERE ci.combo_id = ?');
    $stmtComboId   = $pdo->prepare('SELECT id FROM combo_configs WHERE producto_id = ? AND activo = 1 LIMIT 1');

    $total          = 0.0;
    $venta_es_combo = 0;
    $comboCache     = [];

    foreach ($carrito as $item) {
        $pid      = $item['producto_id'];
        $cantidad = $item['cantidad'];
        $precio   = $item['precio'];
        $esCombo  = $item['es_combo'];

        $stmtProdInfo->execute([$pid]);
        $prodInfo = $stmtProdInfo->fetch();
        if (!$prodInfo) throw new RuntimeException("Producto #{$pid} no encontrado.");

        $rinde      = max(1, (int)($prodInfo['unidades_por_receta'] ?? 1));
        $fromStock  = ((int)($prodInfo['stock_disponible'] ?? 0) >= $cantidad) ? 1 : 0;
        // Snapshot del nombre del producto al momento de la (re)edición
        $nombreSnap  = $prodInfo['nombre']  ?? null;
        $nombre2Snap = $prodInfo['nombre2'] ?? null;

        $comboId = null;
        if ($esCombo) {
            if (!array_key_exists($pid, $comboCache)) {
                $stmtComboId->execute([$pid]);
                $comboCache[$pid] = $stmtComboId->fetchColumn() ?: null;
            }
            $comboId        = $comboCache[$pid];
            $venta_es_combo = 1;
        }

        $subtotal = $precio * $cantidad;
        $total   += $subtotal;

        $detalleParams = [
            ':vid' => $id, ':pid' => $pid, ':cant' => $cantidad,
            ':precio' => $precio, ':sub' => $subtotal,
            ':fs' => $fromStock, ':ec' => $esCombo, ':cid' => $comboId, ':uid' => $uid,
        ];
        if ($tiene034ev) {
            $detalleParams[':nsnap']  = $nombreSnap;
            $detalleParams[':n2snap'] = $nombre2Snap;
        }
        $stmtDetalle->execute($detalleParams);

        if ($fromStock) {
            $stmtStockProd->execute([$cantidad, $uid, $pid, $cantidad]);
            if ($stmtStockProd->rowCount() === 0) {
                throw new RuntimeException("Stock insuficiente de producto terminado para el producto #{$pid}.");
            }
        } else {
            $stmtReceta->execute([':pid' => $pid]);
            foreach ($stmtReceta->fetchAll() as $ing) {
                $desc = ((float)$ing['cantidad_requerida'] / $rinde) * $cantidad;
                $stmtDescuento->execute([':desc' => $desc, ':uid' => $uid, ':id' => (int)$ing['insumo_id'], ':desc2' => $desc]);
                if ($stmtDescuento->rowCount() === 0) {
                    $nom = $pdo->prepare('SELECT nombre FROM insumos WHERE id = ?');
                    $nom->execute([(int)$ing['insumo_id']]);
                    throw new RuntimeException('Stock insuficiente de "' . ($nom->fetchColumn() ?: 'insumo') . '".');
                }
            }
        }

        if ($esCombo && $comboId) {
            $stmtComboIns->execute([$comboId]);
            foreach ($stmtComboIns->fetchAll() as $extra) {
                $descExtra = (float)$extra['cantidad'] * $cantidad;
                $stmtDescuento->execute([':desc' => $descExtra, ':uid' => $uid, ':id' => (int)$extra['insumo_id'], ':desc2' => $descExtra]);
                if ($stmtDescuento->rowCount() === 0) {
                    throw new RuntimeException('Stock insuficiente de "' . $extra['insumo_nombre'] . '" para el combo.');
                }
            }
        }
    }

    // ── 5. Calcular nuevo estado ────────────────────────────────────────────────
    $estadoNuevo = ($metodo_pago === 'fiado' && !$fecha_pago) ? 'pendiente_pago' : 'completada';

    // ── 6. Actualizar cabecera ──────────────────────────────────────────────────
    $setCobro    = $tiene042 ? ', metodo_cobro = :mcobro' : '';
    $paramsVenta = [
        ':cid'    => $cliente_id,
        ':metodo' => $metodo_pago,
        ':total'  => $total,
        ':notas'  => $notas ?: null,
        ':estado' => $estadoNuevo,
        ':fpago'  => $fecha_pago,
        ':combo'  => $venta_es_combo,
        ':fventa' => $fecha_venta,
        ':uid'    => $uid,
        ':id'     => $id,
    ];
    if ($tiene042) $paramsVenta[':mcobro'] = $metodo_cobro;

    $pdo->prepare(
        "UPDATE ventas
         SET cliente_id = :cid, metodo_pago = :metodo, total = :total, notas = :notas,
             estado = :estado, fecha_pago = :fpago, es_combo = :combo,
             fecha_venta = COALESCE(:fventa, fecha_venta), updated_by = :uid{$setCobro}
         WHERE id = :id"
    )->execute($paramsVenta);

    // ── 7. Ajustar fiado del nuevo cliente ──────────────────────────────────────
    if ($metodo_pago === 'fiado' && $cliente_id) {
        $pdo->prepare(
            'UPDATE clientes SET saldo_fiado = saldo_fiado + ?, updated_by = ? WHERE id = ?'
        )->execute([$total, $uid, $cliente_id]);
    }

    $pdo->commit();
    log_registrar('ventas', $id, 'edicion', null, "total={$total},metodo={$metodo_pago}", 'UPDATE');

    echo json_encode(['success' => true, 'nuevo_total' => $total]);

} catch (RuntimeException $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    $pdo->rollBack();
    error_log('[ClanDestino EditarVenta] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Error interno del servidor.']);
}
