<?php
/**
 * public_html/productos/api/guardar_producto.php
 * Endpoint JSON para CRUD de productos.
 *
 * POST accion=crear             → INSERT nuevo producto
 * POST accion=editar            → UPDATE nombre, nombre2, categoría, tamaño, precio, rinde, stock_min
 * POST accion=precio            → UPDATE solo precio_venta (acceso rápido desde receta)
 * POST accion=duplicar          → Copia producto + receta completa con "(Copia)" en el nombre
 * POST accion=actualizar_capacidad → UPDATE produccion_estimada_mensual
 *
 * nombre2: campo complementario opcional (ej: "con papas criollas", "grande").
 * Se muestra como subtítulo en POS, producción, stock y reportes.
 * No afecta ninguna lógica de negocio, solo es visual.
 */
require_once __DIR__ . '/../../app/middleware/auth_check.php';
require_once __DIR__ . '/../../app/models/RecetaModel.php';
require_once __DIR__ . '/../../app/helpers/AuditoriaHelper.php';
require_once __DIR__ . '/../../app/helpers/ListasHelper.php';

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

$accion = $_POST['accion'] ?? 'crear';
$uid    = (int)($_SESSION['usuario_id'] ?? 0);

// Categorías y tamaños válidos desde listas_sistema (Admin → Catálogos).
// Fallback a arrays hardcodeados si la migración 029b aún no está aplicada.
$_cats_lista  = array_column(listas_get('categoria_producto'), 'valor');
$_tams_lista  = array_column(listas_get('tamano_producto'),    'valor');
$cats    = !empty($_cats_lista)  ? $_cats_lista  : ['sandwich','combo','bebida','adicional'];
$tamanos = !empty($_tams_lista)  ? $_tams_lista  : ['XL','L','unico'];

try {

    // ── CREAR ─────────────────────────────────────────────────────────────────
    if ($accion === 'crear') {
        $nombre    = trim($_POST['nombre']    ?? '');
        // nombre2: subtítulo complementario opcional (máx 120 chars; NULL si vacío)
        $nombre2   = substr(trim($_POST['nombre2'] ?? ''), 0, 120) ?: null;
        $categoria = in_array($_POST['categoria'] ?? '', $cats,    true) ? $_POST['categoria'] : 'sandwich';
        $tamano    = in_array($_POST['tamano']    ?? '', $tamanos, true) ? $_POST['tamano']    : 'unico';
        $precio    = (float)($_POST['precio']              ?? 0);
        $rinde     = max(1, (int)($_POST['unidades_por_receta'] ?? 1));
        $stmin     = max(0, (int)($_POST['stock_minimo']        ?? 0));

        if (empty($nombre)) {
            echo json_encode(['success' => false, 'error' => 'El nombre es obligatorio.']);
            exit;
        }

        db()->prepare(
            'INSERT INTO productos
                (nombre, nombre2, categoria, tamano, precio_venta, unidades_por_receta, stock_minimo, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$nombre, $nombre2, $categoria, $tamano, $precio, $rinde, $stmin, $uid]);

        $id = (int)db()->lastInsertId();
        log_registrar('productos', $id, 'nombre', null, $nombre, 'INSERT');
        echo json_encode(['success' => true, 'id' => $id]);

    // ── EDITAR ────────────────────────────────────────────────────────────────
    } elseif ($accion === 'editar') {
        $id        = (int)($_POST['producto_id'] ?? 0);
        $nombre    = trim($_POST['nombre']       ?? '');
        // nombre2: subtítulo complementario opcional (máx 120 chars; NULL si vacío)
        $nombre2   = substr(trim($_POST['nombre2'] ?? ''), 0, 120) ?: null;
        $categoria = in_array($_POST['categoria'] ?? '', $cats,    true) ? $_POST['categoria'] : 'sandwich';
        $tamano    = in_array($_POST['tamano']    ?? '', $tamanos, true) ? $_POST['tamano']    : 'unico';
        $precio    = (float)($_POST['precio']              ?? 0);
        $rinde     = max(1, (int)($_POST['unidades_por_receta'] ?? 1));
        $stmin     = max(0, (int)($_POST['stock_minimo']        ?? 0));

        if (!$id)            throw new RuntimeException('ID inválido.');
        if (empty($nombre))  throw new RuntimeException('El nombre es obligatorio.');

        // Obtener valores anteriores para auditoría y decidir si recalcular
        $prev = db()->prepare('SELECT nombre, unidades_por_receta FROM productos WHERE id = ?');
        $prev->execute([$id]);
        $anterior = $prev->fetch();

        db()->prepare(
            'UPDATE productos
             SET nombre=?, nombre2=?, categoria=?, tamano=?, precio_venta=?,
                 unidades_por_receta=?, stock_minimo=?, updated_by=?
             WHERE id=?'
        )->execute([$nombre, $nombre2, $categoria, $tamano, $precio, $rinde, $stmin, $uid, $id]);

        // Si cambió unidades_por_receta → recalcular costo/u de este producto
        if ($anterior && (int)$anterior['unidades_por_receta'] !== $rinde) {
            RecetaModel::recalcular_todos();
        }

        log_registrar('productos', $id, 'nombre', $anterior['nombre'] ?? '', $nombre, 'UPDATE');
        echo json_encode(['success' => true]);

    // ── ACTUALIZAR SOLO PRECIO ────────────────────────────────────────────────
    } elseif ($accion === 'precio') {
        $id     = (int)($_POST['producto_id'] ?? 0);
        $precio = (float)($_POST['precio']    ?? 0);
        if (!$id || $precio < 0) throw new RuntimeException('Datos inválidos.');

        $prev = db()->prepare('SELECT precio_venta FROM productos WHERE id = ?');
        $prev->execute([$id]);
        $anterior = $prev->fetchColumn();

        db()->prepare('UPDATE productos SET precio_venta = ?, updated_by = ? WHERE id = ?')
            ->execute([$precio, $uid, $id]);

        if ((float)$anterior !== $precio) {
            log_registrar('productos', $id, 'precio_venta', (string)$anterior, (string)$precio, 'UPDATE');
        }
        echo json_encode(['success' => true]);

    // ── DUPLICAR ──────────────────────────────────────────────────────────────
    } elseif ($accion === 'duplicar') {
        $id = (int)($_POST['producto_id'] ?? 0);
        if (!$id) throw new RuntimeException('ID inválido.');

        // Obtener producto original (incluyendo nombre2 para copiarlo)
        $orig = db()->prepare(
            'SELECT nombre, nombre2, categoria, tamano, precio_venta, unidades_por_receta, stock_minimo
             FROM productos WHERE id = ? AND activo = 1'
        );
        $orig->execute([$id]);
        $p = $orig->fetch();
        if (!$p) throw new RuntimeException('Producto no encontrado.');

        $pdo = db();
        $pdo->beginTransaction();

        // Insertar copia del producto (stock_disponible = 0, costo_calculado se recalcula)
        // nombre2 se copia igual que el original; el usuario puede editarlo después
        $pdo->prepare(
            'INSERT INTO productos
                (nombre, nombre2, categoria, tamano, precio_venta, unidades_por_receta, stock_minimo, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $p['nombre'] . ' (Copia)',
            $p['nombre2'],         // se copia el subtítulo del original
            $p['categoria'],
            $p['tamano'],
            $p['precio_venta'],
            $p['unidades_por_receta'],
            $p['stock_minimo'],
            $uid,
        ]);
        $new_id = (int)$pdo->lastInsertId();

        // Copiar receta completa del producto original
        $pdo->prepare(
            'INSERT INTO recetas (producto_id, insumo_id, cantidad_requerida, es_insumo_critico, created_by)
             SELECT ?, insumo_id, cantidad_requerida, es_insumo_critico, ?
             FROM recetas WHERE producto_id = ?'
        )->execute([$new_id, $uid, $id]);

        $pdo->commit();

        // Recalcular costo del nuevo producto
        RecetaModel::recalcular_todos();

        log_registrar('productos', $new_id, 'nombre', null, $p['nombre'] . ' (Copia)', 'INSERT');
        echo json_encode(['success' => true, 'id' => $new_id, 'nombre' => $p['nombre'] . ' (Copia)']);

    } elseif ($accion === 'actualizar_capacidad') {
        // Actualiza produccion_estimada_mensual en configuracion_negocio.
        // INSERT ... ON DUPLICATE KEY UPDATE garantiza atomicidad sin necesitar
        // un SELECT previo para saber si existe la fila.
        $val = max(1, (int)($_POST['produccion_estimada'] ?? 0));
        if ($val <= 0) throw new RuntimeException('Valor de capacidad inválido.');
        db()->prepare(
            "INSERT INTO configuracion_negocio (clave, valor, descripcion, categoria, updated_by)
             VALUES ('produccion_estimada_mensual', ?, 'Producción estimada mensual', 'produccion', ?)
             ON DUPLICATE KEY UPDATE valor = VALUES(valor), updated_by = VALUES(updated_by)"
        )->execute([$val, $uid]);
        require_once __DIR__ . '/../../app/helpers/AuditoriaHelper.php';
        log_registrar('configuracion_negocio', 0, 'produccion_estimada_mensual', null, (string)$val, 'UPDATE');
        echo json_encode(['success' => true, 'valor' => $val]);

    } else {
        echo json_encode(['success' => false, 'error' => 'Acción inválida.']);
    }

} catch (RuntimeException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('[ClanDestino Productos] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Error interno al guardar el producto.']);
}
