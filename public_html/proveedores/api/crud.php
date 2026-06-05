<?php
/**
 * proveedores/api/crud.php
 * CRUD completo de proveedores.
 * POST accion=crear   → INSERT
 * POST accion=editar  → UPDATE
 * POST accion=toggle  → activo = NOT activo (soft-delete)
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

permiso_requerir('proveedores', 'editar_existentes');

if (!csrf_verificar()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Token CSRF inválido.']);
    exit;
}

$accion = $_POST['accion'] ?? '';
$uid    = (int)($_SESSION['usuario_id'] ?? 0);

// Categorías de proveedores desde listas_sistema. Fallback hardcodeado
// si la migración 029 aún no está aplicada.
$_cats_prov = array_column(listas_get('categoria_proveedor'), 'valor');
$CATEGORIAS = !empty($_cats_prov)
    ? $_cats_prov
    : ['plaza','tienda','retail','online','mayorista','panaderia','otro'];

// Detectar columnas disponibles (migración 011 puede no estar aplicada)
static $cols = null;
if ($cols === null) {
    $cols = [];
    $res = db()->query('SHOW COLUMNS FROM proveedores');
    foreach ($res->fetchAll() as $r) { $cols[] = $r['Field']; }
}
$tieneEmail  = in_array('email',     $cols);
$tieneWeb    = in_array('sitio_web', $cols);
$tieneDireccion = in_array('direccion', $cols);

try {
    switch ($accion) {

        case 'crear':
            $nombre = trim($_POST['nombre'] ?? '');
            if (empty($nombre)) throw new \RuntimeException('El nombre es obligatorio.');

            $cat_raw  = $_POST['categoria'] ?? '';
            $cat      = listas_valor_valido('categoria_proveedor', $cat_raw) ? ($cat_raw ?: 'otro') : 'otro';
            $contacto = trim($_POST['contacto']  ?? '') ?: null;
            $telefono = trim($_POST['telefono']  ?? '') ?: null;
            $email    = trim($_POST['email']     ?? '') ?: null;
            $web      = trim($_POST['sitio_web'] ?? '') ?: null;
            $dir      = trim($_POST['direccion'] ?? '') ?: null;
            $notas    = trim($_POST['notas']     ?? '') ?: null;

            if ($tieneEmail && $tieneDireccion) {
                db()->prepare(
                    'INSERT INTO proveedores
                        (nombre, categoria, contacto, telefono, email, sitio_web, direccion, notas, created_by)
                     VALUES (?,?,?,?,?,?,?,?,?)'
                )->execute([$nombre,$cat,$contacto,$telefono,$email,$web,$dir,$notas,$uid]);
            } else {
                db()->prepare(
                    'INSERT INTO proveedores (nombre, categoria, contacto, telefono, notas, created_by)
                     VALUES (?,?,?,?,?,?)'
                )->execute([$nombre,$cat,$contacto,$telefono,$notas,$uid]);
            }

            $id = (int)db()->lastInsertId();
            log_registrar('proveedores', $id, 'nombre', null, $nombre, 'INSERT');
            echo json_encode(['success' => true, 'id' => $id, 'nombre' => $nombre]);
            break;

        case 'editar':
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) throw new \RuntimeException('ID inválido.');
            $nombre = trim($_POST['nombre'] ?? '');
            if (empty($nombre)) throw new \RuntimeException('El nombre es obligatorio.');

            $cat_raw  = $_POST['categoria'] ?? '';
            $cat      = listas_valor_valido('categoria_proveedor', $cat_raw) ? ($cat_raw ?: 'otro') : 'otro';
            $contacto = trim($_POST['contacto']  ?? '') ?: null;
            $telefono = trim($_POST['telefono']  ?? '') ?: null;
            $email    = trim($_POST['email']     ?? '') ?: null;
            $web      = trim($_POST['sitio_web'] ?? '') ?: null;
            $dir      = trim($_POST['direccion'] ?? '') ?: null;
            $notas    = trim($_POST['notas']     ?? '') ?: null;

            if ($tieneEmail && $tieneDireccion) {
                db()->prepare(
                    'UPDATE proveedores
                     SET nombre=?,categoria=?,contacto=?,telefono=?,
                         email=?,sitio_web=?,direccion=?,notas=?,updated_by=?
                     WHERE id=?'
                )->execute([$nombre,$cat,$contacto,$telefono,$email,$web,$dir,$notas,$uid,$id]);
            } else {
                db()->prepare(
                    'UPDATE proveedores SET nombre=?,categoria=?,contacto=?,telefono=?,notas=?,updated_by=? WHERE id=?'
                )->execute([$nombre,$cat,$contacto,$telefono,$notas,$uid,$id]);
            }

            log_registrar('proveedores', $id, 'nombre', null, $nombre, 'UPDATE');
            echo json_encode(['success' => true]);
            break;

        case 'toggle':
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) throw new \RuntimeException('ID inválido.');
            db()->prepare('UPDATE proveedores SET activo = NOT activo, updated_by=? WHERE id=?')
                ->execute([$uid, $id]);
            $row = db()->prepare('SELECT activo FROM proveedores WHERE id=?');
            $row->execute([$id]);
            $nuevo = (bool)$row->fetchColumn();
            log_registrar('proveedores', $id, 'activo', null, $nuevo ? '1' : '0', 'UPDATE');
            echo json_encode(['success' => true, 'activo' => $nuevo]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Acción inválida.']);
    }
} catch (\RuntimeException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\Exception $e) {
    error_log('[ClanDestino Proveedores] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Error interno.']);
}
