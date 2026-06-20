<?php
/**
 * public_html/productos/api/copiar_receta.php
 * Endpoint JSON: construir/ampliar la receta de un producto a partir de la(s)
 * receta(s) de otro(s) producto(s), escalando cada origen por un porcentaje y
 * UNIFICANDO ingredientes repetidos (sumando cantidades).
 *
 * Caso de uso: armar "Criollo L" tomando el "Criollo XL" al 60%, o "Mixto"
 * tomando "Pollo desmechado" 50% + "Criollo" 50% (los insumos repetidos suman).
 *
 * POST:
 *   producto_id  → producto destino
 *   modo         → 'reemplazar' (vacía y construye) | 'sumar' (suma a la actual)
 *   fuentes      → JSON: [{ "id": <producto_id>, "factor": <porcentaje> }, ...]
 *
 * Reglas de la receta respetadas:
 *   - Como máximo UN ingrediente crítico por producto (se conserva el primero).
 *   - Un ingrediente no puede ser crítico Y base a la vez (base anula crítico).
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
$modo        = ($_POST['modo'] ?? 'reemplazar') === 'sumar' ? 'sumar' : 'reemplazar';
$fuentesRaw  = json_decode($_POST['fuentes'] ?? '[]', true);

if (!$producto_id) {
    echo json_encode(['success' => false, 'error' => 'Producto destino inválido.']);
    exit;
}
if (!is_array($fuentesRaw) || empty($fuentesRaw)) {
    echo json_encode(['success' => false, 'error' => 'Selecciona al menos un producto de origen.']);
    exit;
}

// Normalizar fuentes: {id, factor%}. Se descarta el propio destino y factores ≤ 0.
$fuentes = [];
foreach ($fuentesRaw as $f) {
    $fid    = (int)($f['id'] ?? 0);
    $factor = (float)($f['factor'] ?? 0);
    if ($fid > 0 && $fid !== $producto_id && $factor > 0) {
        $fuentes[] = ['id' => $fid, 'factor' => $factor];
    }
}
if (empty($fuentes)) {
    echo json_encode(['success' => false, 'error' => 'Orígenes inválidos (revisa producto y porcentaje).']);
    exit;
}

$uid = (int)($_SESSION['usuario_id'] ?? 0);
$pdo = db();

// Detectar migración 036 (es_base en recetas)
$tiene036 = false;
try {
    $tiene036 = (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='recetas'
           AND COLUMN_NAME='es_base'"
    )->fetchColumn() > 0;
} catch (\Exception $e) { $tiene036 = false; }

$colBaseSel = $tiene036 ? ', es_base' : ', 0 AS es_base';

/**
 * Suma una fila de receta al mapa acumulado, unificando por insumo_id.
 * Cantidad se suma; banderas con OR lógico.
 */
$mergeRow = function (array &$map, int $insumoId, float $cant, int $crit, int $base): void {
    if (!isset($map[$insumoId])) {
        $map[$insumoId] = ['cant' => 0.0, 'crit' => 0, 'base' => 0];
    }
    $map[$insumoId]['cant'] += $cant;
    $map[$insumoId]['crit']  = $map[$insumoId]['crit'] || $crit ? 1 : 0;
    $map[$insumoId]['base']  = $map[$insumoId]['base'] || $base ? 1 : 0;
};

try {
    $pdo->beginTransaction();

    // Verificar destino
    $dst = $pdo->prepare('SELECT id FROM productos WHERE id = ?');
    $dst->execute([$producto_id]);
    if (!$dst->fetch()) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Producto destino no encontrado.']);
        exit;
    }

    $map = [];

    // Modo "sumar": partir de la receta actual del destino
    if ($modo === 'sumar') {
        $cur = $pdo->prepare(
            "SELECT insumo_id, cantidad_requerida, es_insumo_critico{$colBaseSel}
             FROM recetas WHERE producto_id = ?"
        );
        $cur->execute([$producto_id]);
        foreach ($cur->fetchAll() as $r) {
            $mergeRow($map, (int)$r['insumo_id'], (float)$r['cantidad_requerida'],
                      (int)$r['es_insumo_critico'], (int)$r['es_base']);
        }
    }

    // Agregar cada origen escalado por su porcentaje
    $stmtFuente = $pdo->prepare(
        "SELECT insumo_id, cantidad_requerida, es_insumo_critico{$colBaseSel}
         FROM recetas WHERE producto_id = ?"
    );
    foreach ($fuentes as $f) {
        $stmtFuente->execute([$f['id']]);
        $factor = $f['factor'] / 100.0;
        foreach ($stmtFuente->fetchAll() as $r) {
            $cant = round((float)$r['cantidad_requerida'] * $factor, 4);
            if ($cant <= 0) continue;
            $mergeRow($map, (int)$r['insumo_id'], $cant,
                      (int)$r['es_insumo_critico'], (int)$r['es_base']);
        }
    }

    if (empty($map)) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Los productos de origen no tienen ingredientes.']);
        exit;
    }

    // ── Resolver banderas ───────────────────────────────────────────────────
    // 1) base anula crítico (no pueden coexistir).
    // 2) máximo un crítico: conservar el primero, el resto a 0.
    $critUsado = false;
    foreach ($map as $iid => &$v) {
        if ($v['base']) $v['crit'] = 0;
        if ($v['crit']) {
            if ($critUsado) $v['crit'] = 0;
            else            $critUsado = true;
        }
    }
    unset($v);

    // ── Escribir receta (delete-all + insert del mapa, en ambos modos: en "sumar"
    //    el mapa ya incluye lo actual sumado) ─────────────────────────────────
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
        if ($v['cant'] <= 0) continue;
        if ($tiene036) {
            $ins->execute([$producto_id, $iid, $v['cant'], $v['crit'], $v['base'], $uid]);
        } else {
            $ins->execute([$producto_id, $iid, $v['cant'], $v['crit'], $uid]);
        }
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

    echo json_encode(['success' => true, 'ingredientes' => $n, 'modo' => $modo]);

} catch (\Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[ClanDestino copiar_receta] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Error al copiar la receta: ' . $e->getMessage()]);
}
