<?php
/**
 * inventario/api/proveedor_crud.php
 * Alta RÁPIDA de proveedor desde el módulo Inventario (al agregar un insumo se
 * puede crear el proveedor en el momento). Solo soporta 'crear'; el CRUD
 * completo (editar/toggle) vive en proveedores/api/crud.php.
 *
 * Seguridad: requiere permiso de inventario (editar_existentes) + token CSRF.
 */

require_once __DIR__ . '/../../app/middleware/auth_check.php';
require_once __DIR__ . '/../../app/helpers/AuditoriaHelper.php';
require_once __DIR__ . '/../../app/helpers/ListasHelper.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido.']);
    exit;
}

permiso_requerir('inventario', 'editar_existentes');

if (!csrf_verificar()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Token CSRF inválido.']);
    exit;
}

$uid = (int)($_SESSION['usuario_id'] ?? 0);

try {
    $nombre = trim($_POST['nombre'] ?? '');
    if ($nombre === '') throw new \RuntimeException('El nombre del proveedor es obligatorio.');

    $cat_raw  = $_POST['categoria'] ?? '';
    $cat      = listas_valor_valido('categoria_proveedor', $cat_raw) ? ($cat_raw ?: 'otro') : 'otro';
    $contacto = trim($_POST['contacto']  ?? '') ?: null;
    $telefono = trim($_POST['telefono']  ?? '') ?: null;
    $dir      = trim($_POST['direccion'] ?? '') ?: null;
    $notas    = trim($_POST['notas']     ?? '') ?: null;

    // Detectar si existe la columna 'direccion' (migración 011 puede no estar aplicada)
    $cols = [];
    foreach (db()->query('SHOW COLUMNS FROM proveedores')->fetchAll() as $r) $cols[] = $r['Field'];
    $tieneDir = in_array('direccion', $cols, true);

    if ($tieneDir) {
        db()->prepare(
            'INSERT INTO proveedores (nombre, categoria, contacto, telefono, direccion, notas, created_by)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([$nombre, $cat, $contacto, $telefono, $dir, $notas, $uid]);
    } else {
        db()->prepare(
            'INSERT INTO proveedores (nombre, categoria, contacto, telefono, notas, created_by)
             VALUES (?,?,?,?,?,?)'
        )->execute([$nombre, $cat, $contacto, $telefono, $notas, $uid]);
    }

    $id = (int)db()->lastInsertId();
    log_registrar('proveedores', $id, 'nombre', null, $nombre, 'INSERT');
    echo json_encode(['success' => true, 'id' => $id, 'nombre' => $nombre]);

} catch (\RuntimeException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    error_log('[QuickServe OS inv proveedor_crud] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Error interno al crear el proveedor.']);
}
