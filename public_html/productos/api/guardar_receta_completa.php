<?php
/**
 * public_html/productos/api/guardar_receta_completa.php
 * Endpoint JSON: guardar la receta COMPLETA de un producto de una sola vez
 * (usado por el "Constructor de recetas"): reemplaza todos los ingredientes y
 * fija el rinde (unidades_por_receta / "cuántos salen").
 *
 * POST:
 *   producto_id   → producto destino
 *   rinde         → unidades_por_receta (cuántas unidades salen de la tanda)
 *   ingredientes  → JSON: [{ "insumo_id":N, "cantidad":N, "es_critico":0|1, "es_base":0|1 }, ...]
 *
 * Reglas: un ingrediente no puede ser crítico Y base; máximo un crítico por
 * producto. Si un insumo se repite en la lista, se SUMAN las cantidades.
 */

require_once __DIR__ . '/../../app/middleware/auth_check.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido.']);
    exit;
}

permiso_requerir('productos', 'editar_existentes');

if (!csrf_verificar()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Token CSRF inválido.']);
    exit;
}

$producto_id = (int)($_POST['producto_id'] ?? 0);
$rinde       = max(1, (int)($_POST['rinde'] ?? 1));
$ingsRaw     = json_decode($_POST['ingredientes'] ?? '[]', true);

if (!$producto_id) {
    echo json_encode(['success' => false, 'error' => 'Producto inválido.']);
    exit;
}
if (!is_array($ingsRaw) || empty($ingsRaw)) {
    echo json_encode(['success' => false, 'error' => 'Agrega al menos un ingrediente.']);
    exit;
}

$uid = (int)($_SESSION['usuario_id'] ?? 0);
$pdo = db();

// Detectar migración 036 (es_base)
$tiene036 = false;
try {
    $tiene036 = (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='recetas'
           AND COLUMN_NAME='es_base'"
    )->fetchColumn() > 0;
} catch (\Exception $e) { $tiene036 = false; }

// Unificar por insumo_id (suma cantidades, OR de banderas)
$map = [];
foreach ($ingsRaw as $r) {
    $iid  = (int)($r['insumo_id'] ?? 0);
    $cant = (float)($r['cantidad'] ?? 0);
    if ($iid <= 0 || $cant <= 0) continue;
    if (!isset($map[$iid])) $map[$iid] = ['cant' => 0.0, 'crit' => 0, 'base' => 0];
    $map[$iid]['cant'] += $cant;
    $map[$iid]['crit']  = $map[$iid]['crit'] || (int)($r['es_critico'] ?? 0) ? 1 : 0;
    $map[$iid]['base']  = $map[$iid]['base'] || (int)($r['es_base'] ?? 0) ? 1 : 0;
}
if (empty($map)) {
    echo json_encode(['success' => false, 'error' => 'No hay ingredientes válidos (revisa insumo y cantidad).']);
    exit;
}

// Resolver banderas: base anula crítico; máximo un crítico.
$critUsado = false;
foreach ($map as $iid => &$v) {
    if ($v['base']) $v['crit'] = 0;
    if ($v['crit']) {
        if ($critUsado) $v['crit'] = 0;
        else            $critUsado = true;
    }
}
unset($v);

try {
    $pdo->beginTransaction();

    // Verificar producto
    $chk = $pdo->prepare('SELECT id FROM productos WHERE id = ?');
    $chk->execute([$producto_id]);
    if (!$chk->fetch()) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Producto no encontrado.']);
        exit;
    }

    // Fijar rinde (cuántos salen)
    $pdo->prepare('UPDATE productos SET unidades_por_receta = ?, updated_by = ? WHERE id = ?')
        ->execute([$rinde, $uid, $producto_id]);

    // Reemplazar receta
    $pdo->prepare('DELETE FROM recetas WHERE producto_id = ?')->execute([$producto_id]);

    if ($tiene036) {
        $ins = $pdo->prepare(
            'INSERT INTO recetas (producto_id, insumo_id, cantidad_requerida, es_insumo_critico, es_base, created_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
    } else {
        $ins = $pdo->prepare(
            'INSERT INTO recetas (producto_id, insumo_id, cantidad_requerida, es_insumo_critico, created_by)
             VALUES (?, ?, ?, ?, ?)'
        );
    }

    $n = 0;
    foreach ($map as $iid => $v) {
        $cant = round($v['cant'], 4);
        if ($cant <= 0) continue;
        if ($tiene036) $ins->execute([$producto_id, $iid, $cant, $v['crit'], $v['base'], $uid]);
        else           $ins->execute([$producto_id, $iid, $cant, $v['crit'], $uid]);
        $n++;
    }

    // Recalcular costo del producto
    $pdo->prepare(
        "UPDATE productos p
         SET p.costo_calculado = (
             SELECT IFNULL(SUM(i.costo_actual * r.cantidad_requerida), 0)
             FROM recetas r JOIN insumos i ON i.id = r.insumo_id
             WHERE r.producto_id = p.id
         ), p.updated_by = ?
         WHERE p.id = ?"
    )->execute([$uid, $producto_id]);

    $pdo->commit();

    echo json_encode(['success' => true, 'ingredientes' => $n]);

} catch (\Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[QuickServe OS guardar_receta_completa] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Error interno al guardar la receta.']);
}
