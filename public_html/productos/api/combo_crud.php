<?php
/**
 * productos/api/combo_crud.php
 * API para configurar la opción combo de un producto.
 *
 * GET  ?producto_id=X       → devuelve config actual (null si no existe)
 * POST accion=guardar       → upsert combo_configs + reemplaza combo_insumos
 * POST accion=eliminar      → desactiva la config (soft-delete via activo=0)
 *
 * Permisos:
 *   GET:    solo_ver
 *   POST:   editar_existentes
 */

require_once __DIR__ . '/../../app/middleware/auth_check.php';
require_once __DIR__ . '/../../app/helpers/AuditoriaHelper.php';

header('Content-Type: application/json; charset=utf-8');

// ── GET: obtener configuración de combo del producto ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    permiso_requerir('productos', 'solo_ver');

    $producto_id = (int)($_GET['producto_id'] ?? 0);
    if (!$producto_id) {
        echo json_encode(null);
        exit;
    }

    $pdo = db();

    // Leer cabecera del combo
    $stmtCC = $pdo->prepare(
        'SELECT id, producto_id, nombre, precio_adicional
         FROM combo_configs
         WHERE producto_id = ? AND activo = 1
         LIMIT 1'
    );
    $stmtCC->execute([$producto_id]);
    $config = $stmtCC->fetch();

    if (!$config) {
        echo json_encode(null);
        exit;
    }

    // Leer insumos del combo
    $stmtCI = $pdo->prepare(
        'SELECT ci.insumo_id, ci.cantidad,
                i.nombre      AS insumo_nombre,
                i.unidad_medida
         FROM combo_insumos ci
         JOIN insumos i ON i.id = ci.insumo_id
         WHERE ci.combo_id = ?
         ORDER BY i.nombre'
    );
    $stmtCI->execute([(int)$config['id']]);
    $config['insumos'] = $stmtCI->fetchAll();

    echo json_encode($config);
    exit;
}

// ── POST: guardar o eliminar ──────────────────────────────────────────────────
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

$accion = $_POST['accion'] ?? '';
$uid    = (int)($_SESSION['usuario_id'] ?? 0);

try {
    $pdo = db();

    switch ($accion) {

        // ── GUARDAR / ACTUALIZAR COMBO ────────────────────────────────────────
        case 'guardar':
            $producto_id      = (int)($_POST['producto_id']      ?? 0);
            $nombre           = substr(trim($_POST['nombre'] ?? 'Combo'), 0, 100); // máx 100 chars = columna DB
            $precio_adicional = (float)str_replace(',', '.', $_POST['precio_adicional'] ?? '0');
            $insumos_json     = $_POST['insumos'] ?? '[]';

            if ($producto_id <= 0)
                throw new RuntimeException('Producto inválido.');
            if ($precio_adicional < 0)
                throw new RuntimeException('El precio adicional no puede ser negativo.');
            if (empty($nombre)) $nombre = 'Combo';

            // Validar que el JSON de insumos sea un array
            $insumos = json_decode($insumos_json, true);
            if (!is_array($insumos))
                throw new RuntimeException('Lista de insumos inválida.');

            $pdo->beginTransaction();

            // Verificar que el producto existe
            $stmtProd = $pdo->prepare('SELECT id FROM productos WHERE id = ? AND activo = 1');
            $stmtProd->execute([$producto_id]);
            if (!$stmtProd->fetchColumn())
                throw new RuntimeException('Producto no encontrado.');

            // Upsert combo_configs: si ya existe para este producto, actualizar
            $pdo->prepare(
                'INSERT INTO combo_configs
                    (producto_id, nombre, precio_adicional, activo, created_by)
                 VALUES (?, ?, ?, 1, ?)
                 ON DUPLICATE KEY UPDATE
                    nombre           = VALUES(nombre),
                    precio_adicional = VALUES(precio_adicional),
                    activo           = 1'
            )->execute([$producto_id, $nombre, $precio_adicional, $uid]);

            // Obtener el combo_id (sea recién creado o ya existente)
            $stmtId = $pdo->prepare('SELECT id FROM combo_configs WHERE producto_id = ?');
            $stmtId->execute([$producto_id]);
            $combo_id = (int)$stmtId->fetchColumn();

            // Reemplazar los insumos del combo: borra todos y re-inserta
            $pdo->prepare('DELETE FROM combo_insumos WHERE combo_id = ?')
                ->execute([$combo_id]);

            if (!empty($insumos)) {
                $stmtIns = $pdo->prepare(
                    'INSERT INTO combo_insumos (combo_id, insumo_id, cantidad)
                     VALUES (?, ?, ?)'
                );
                foreach ($insumos as $ins) {
                    $insumo_id = (int)($ins['insumo_id'] ?? 0);
                    $cantidad  = (float)($ins['cantidad'] ?? 0);
                    // Ignorar entradas sin insumo o con cantidad 0
                    if ($insumo_id <= 0 || $cantidad <= 0) continue;

                    // Verificar que el insumo existe
                    $chk = $pdo->prepare('SELECT id FROM insumos WHERE id = ? AND activo = 1');
                    $chk->execute([$insumo_id]);
                    if (!$chk->fetchColumn())
                        throw new RuntimeException("Insumo #{$insumo_id} no encontrado.");

                    $stmtIns->execute([$combo_id, $insumo_id, $cantidad]);
                }
            }

            $pdo->commit();

            log_registrar('combo_configs', $combo_id, 'precio_adicional',
                null, (string)$precio_adicional, 'UPSERT');

            echo json_encode(['success' => true, 'combo_id' => $combo_id]);
            break;

        // ── ELIMINAR (soft-delete) COMBO ──────────────────────────────────────
        case 'eliminar':
            $producto_id = (int)($_POST['producto_id'] ?? 0);
            if ($producto_id <= 0)
                throw new RuntimeException('Producto inválido.');

            // Soft-delete: activo=0 (preserva historial de ventas combo ya registradas)
            $pdo->prepare(
                'UPDATE combo_configs SET activo = 0 WHERE producto_id = ?'
            )->execute([$producto_id]);

            log_registrar('combo_configs', 0, 'activo', '1', '0', 'UPDATE');
            echo json_encode(['success' => true]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Acción inválida.']);
    }

} catch (RuntimeException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('[QuickServe OS Combo CRUD] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Error interno del servidor.']);
}
