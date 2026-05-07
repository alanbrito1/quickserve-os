<?php
/**
 * public_html/productos/api/guardar_producto.php
 * Endpoint JSON: crear producto o actualizar su precio de venta.
 *
 * POST accion=crear  → INSERT nuevo producto
 * POST accion=precio → UPDATE precio_venta de un producto existente
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

$accion = $_POST['accion'] ?? 'crear';
$uid    = (int)($_SESSION['usuario_id'] ?? 0);

try {
    if ($accion === 'precio') {
        $producto_id = (int)($_POST['producto_id'] ?? 0);
        $precio      = (float)($_POST['precio']    ?? 0);
        if (!$producto_id || $precio < 0) {
            echo json_encode(['success' => false, 'error' => 'Datos inválidos.']);
            exit;
        }
        $prev = db()->prepare('SELECT precio_venta FROM productos WHERE id = ?');
        $prev->execute([$producto_id]);
        $anterior = $prev->fetchColumn();

        db()->prepare('UPDATE productos SET precio_venta = ?, updated_by = ? WHERE id = ?')
            ->execute([$precio, $uid, $producto_id]);

        // Auditar cambio de precio
        if ((float)$anterior !== $precio) {
            require_once __DIR__ . '/../../app/helpers/AuditoriaHelper.php';
            log_registrar('productos', $producto_id, 'precio_venta',
                (string)$anterior, (string)$precio, 'UPDATE');
        }

        echo json_encode(['success' => true]);

    } else {
        // Crear nuevo producto
        $nombre    = trim($_POST['nombre']    ?? '');
        $categoria = $_POST['categoria']      ?? 'sandwich';
        $tamano    = $_POST['tamano']          ?? 'unico';
        $precio    = (float)($_POST['precio'] ?? 0);

        if (empty($nombre)) {
            echo json_encode(['success' => false, 'error' => 'El nombre es obligatorio.']);
            exit;
        }

        $cats    = ['sandwich','combo','bebida','adicional'];
        $tamanos = ['XL','L','unico'];
        if (!in_array($categoria, $cats, true)) $categoria = 'sandwich';
        if (!in_array($tamano,    $tamanos, true)) $tamano = 'unico';

        db()->prepare(
            'INSERT INTO productos (nombre, categoria, tamano, precio_venta, created_by)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$nombre, $categoria, $tamano, $precio, $uid]);

        $id = (int)db()->lastInsertId();

        require_once __DIR__ . '/../../app/helpers/AuditoriaHelper.php';
        log_registrar('productos', $id, 'nombre', null, $nombre, 'INSERT');

        echo json_encode(['success' => true, 'id' => $id]);
    }

} catch (Exception $e) {
    error_log('[ClanDestino Productos] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Error interno al guardar el producto.']);
}
