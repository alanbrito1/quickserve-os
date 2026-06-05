<?php
/**
 * admin/api/usuario_crud.php — CRUD de usuarios.
 * Acciones: crear, editar, toggle (activar/desactivar)
 */
require_once __DIR__ . '/../../app/middleware/auth_check.php';
require_once __DIR__ . '/../../app/helpers/AuditoriaHelper.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success'=>false,'error'=>'Método no permitido.']);
    exit;
}

if (!in_array($_SESSION['usuario_rol'] ?? '', ['superadmin','admin'], true)) {
    http_response_code(403);
    echo json_encode(['success'=>false,'error'=>'Sin permisos.']);
    exit;
}

if (!csrf_verificar()) {
    http_response_code(403);
    echo json_encode(['success'=>false,'error'=>'Token CSRF inválido.']);
    exit;
}

$accion = $_POST['accion'] ?? '';
$uid    = (int)($_SESSION['usuario_id'] ?? 0);

// Módulos válidos para permisos
$MODULOS_OK = ['ventas','compras','inventario','nomina','productos','activos','reportes','proveedores','costos'];
$NIVELES_OK = ['sin_acceso','solo_ver','solo_propios','editar_existentes','admin_total'];

function guardarPermisos(int $usuario_id, array $post, array $modulos, array $niveles_ok, int $uid_admin): void
{
    // Borrar todos los permisos actuales del usuario
    db()->prepare('DELETE FROM permisos_modulos WHERE usuario_id = ?')->execute([$usuario_id]);

    foreach ($modulos as $mod) {
        $nivel = $post['perm_' . $mod] ?? 'sin_acceso';
        if (!in_array($nivel, $niveles_ok, true)) $nivel = 'sin_acceso';
        if ($nivel === 'sin_acceso') continue; // no guardar sin_acceso (implícito)
        db()->prepare(
            'INSERT INTO permisos_modulos (usuario_id, modulo, nivel_acceso, created_by)
             VALUES (?, ?, ?, ?)'
        )->execute([$usuario_id, $mod, $nivel, $uid_admin]);
    }
}

try {
    switch ($accion) {

        case 'crear':
            $nombre = trim($_POST['nombre'] ?? '');
            // Normalizar email: minúsculas + sin espacios antes de validar (evita duplicados por variantes)
            $email  = strtolower(trim($_POST['email'] ?? ''));
            $pass   = $_POST['password'] ?? '';
            $rol    = $_POST['rol'] ?? 'empleado';

            if (empty($nombre))        throw new RuntimeException('El nombre es obligatorio.');
            if (empty($email))         throw new RuntimeException('El correo es obligatorio.');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Correo inválido.');
            // Mínimo 8 caracteres (OWASP recomienda 8+ con bcrypt cost=12)
            if (strlen($pass) < 8)     throw new RuntimeException('La contraseña debe tener al menos 8 caracteres.');

            $roles_ok = ['empleado','admin'];
            if (($_SESSION['usuario_rol'] ?? '') === 'superadmin') $roles_ok[] = 'superadmin';
            if (!in_array($rol, $roles_ok, true)) $rol = 'empleado';

            // Verificar email único
            $dup = db()->prepare('SELECT id FROM usuarios WHERE email = ?');
            $dup->execute([$email]);
            if ($dup->fetchColumn()) throw new RuntimeException('Ya existe un usuario con ese correo.');

            $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);

            db()->prepare(
                'INSERT INTO usuarios (nombre, email, password_hash, rol, created_by)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$nombre, $email, $hash, $rol, $uid]);

            $new_id = (int)db()->lastInsertId();
            guardarPermisos($new_id, $_POST, $MODULOS_OK, $NIVELES_OK, $uid);
            log_registrar('usuarios', $new_id, 'nombre', null, $nombre, 'INSERT');

            echo json_encode(['success'=>true, 'id'=>$new_id]);
            break;

        case 'editar':
            $id     = (int)($_POST['id'] ?? 0);
            $nombre = trim($_POST['nombre'] ?? '');
            $email  = strtolower(trim($_POST['email'] ?? ''));
            $pass   = $_POST['password'] ?? '';
            $rol    = $_POST['rol'] ?? 'empleado';

            if (!$id)              throw new RuntimeException('ID inválido.');
            if (empty($nombre))    throw new RuntimeException('El nombre es obligatorio.');
            if (empty($email))     throw new RuntimeException('El correo es obligatorio.');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Correo inválido.');

            // No puede cambiar su propio rol — preserva el rol actual desde sesión
            if ($id === $uid) {
                $rol = $_SESSION['usuario_rol'] ?? null;
                if (!$rol) throw new RuntimeException('Sesión inválida. Vuelve a iniciar sesión.');
            }

            $roles_ok = ['empleado','admin'];
            if (($_SESSION['usuario_rol'] ?? '') === 'superadmin') $roles_ok[] = 'superadmin';
            if (!in_array($rol, $roles_ok, true)) $rol = 'empleado';

            // Verificar email único (excluir el propio)
            $dup = db()->prepare('SELECT id FROM usuarios WHERE email = ? AND id != ?');
            $dup->execute([$email, $id]);
            if ($dup->fetchColumn()) throw new RuntimeException('Ya existe otro usuario con ese correo.');

            if ($pass !== '') {
                if (strlen($pass) < 8) throw new RuntimeException('La contraseña debe tener al menos 8 caracteres.');
                $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
                db()->prepare('UPDATE usuarios SET nombre=?,email=?,password_hash=?,rol=?,updated_by=? WHERE id=?')
                    ->execute([$nombre,$email,$hash,$rol,$uid,$id]);
            } else {
                db()->prepare('UPDATE usuarios SET nombre=?,email=?,rol=?,updated_by=? WHERE id=?')
                    ->execute([$nombre,$email,$rol,$uid,$id]);
            }

            // No guardar permisos si es superadmin (bypassa de todos modos)
            if ($rol !== 'superadmin') {
                guardarPermisos($id, $_POST, $MODULOS_OK, $NIVELES_OK, $uid);
            }

            log_registrar('usuarios', $id, 'nombre', null, $nombre, 'UPDATE');
            echo json_encode(['success'=>true]);
            break;

        case 'toggle':
            $id = (int)($_POST['id'] ?? 0);
            if (!$id)           throw new RuntimeException('ID inválido.');
            if ($id === $uid)   throw new RuntimeException('No puedes desactivar tu propia cuenta.');

            db()->prepare('UPDATE usuarios SET activo = NOT activo, updated_by = ? WHERE id = ?')
                ->execute([$uid, $id]);

            $stmt = db()->prepare('SELECT activo FROM usuarios WHERE id = ?');
            $stmt->execute([$id]);
            $nuevo = (bool)$stmt->fetchColumn();

            log_registrar('usuarios', $id, 'activo', null, $nuevo ? '1' : '0', 'UPDATE');
            echo json_encode(['success'=>true, 'activo'=>$nuevo]);
            break;

        default:
            echo json_encode(['success'=>false,'error'=>'Acción inválida.']);
    }

} catch (RuntimeException $e) {
    echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
} catch (Exception $e) {
    error_log('[ClanDestino Admin] ' . $e->getMessage());
    echo json_encode(['success'=>false,'error'=>'Error interno del servidor.']);
}
