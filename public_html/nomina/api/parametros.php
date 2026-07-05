<?php
/**
 * nomina/api/parametros.php
 * CRUD de parámetros laborales.
 * POST accion=actualizar      id=X  valor=Y  activo=1|0   (edición rápida inline)
 * POST accion=editar_completo id=X  nombre=X valor=Y ...  (edición completa modal)
 * POST accion=crear            pais=X clave=X nombre=X ...
 * POST accion=eliminar         id=X
 */
require_once __DIR__ . '/../../app/middleware/auth_check.php';
require_once __DIR__ . '/../../app/models/NominaModel.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido.']);
    exit;
}

permiso_requerir('nomina', 'admin_total');

if (!csrf_verificar()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Token CSRF inválido.']);
    exit;
}

$accion = $_POST['accion'] ?? '';

try {
    switch ($accion) {
        case 'actualizar':
            $id     = (int)($_POST['id']     ?? 0);
            $valor  = (float)str_replace(',', '.', $_POST['valor']  ?? '0');
            $activo = !empty($_POST['activo']);
            if (!$id) throw new \RuntimeException('ID inválido.');
            NominaModel::actualizar_parametro($id, $valor, $activo);
            // Invalidar caché de parámetros
            echo json_encode(['success' => true]);
            break;

        case 'editar_completo':
            // Edición completa desde el modal: actualiza todos los campos del parámetro
            $id     = (int)($_POST['id'] ?? 0);
            $nombre = trim($_POST['nombre'] ?? '');
            $valor  = (float)str_replace(',', '.', $_POST['valor'] ?? '0');
            $activo = !empty($_POST['activo']);

            if (!$id)         throw new \RuntimeException('ID inválido.');
            if (empty($nombre)) throw new \RuntimeException('El nombre es obligatorio.');

            $tiposValidos   = ['porcentaje', 'valor_fijo'];
            $aplicaValidos  = ['empleador', 'empleado', 'ambos'];
            $catValidas     = ['base', 'carga_parafiscal', 'provision', 'descuento_empleado', 'tope', 'horas_jornada'];

            $tipo      = in_array($_POST['tipo']      ?? '', $tiposValidos,  true) ? $_POST['tipo']      : 'porcentaje';
            $aplica_a  = in_array($_POST['aplica_a']  ?? '', $aplicaValidos, true) ? $_POST['aplica_a']  : 'empleador';
            $categoria = in_array($_POST['categoria'] ?? '', $catValidas,    true) ? $_POST['categoria'] : 'carga_parafiscal';

            $aplica_contratos = trim($_POST['aplica_contratos'] ?? 'tiempo_completo,medio_tiempo,por_horas');
            $descripcion      = trim($_POST['descripcion']       ?? '');

            $uid = (int)($_SESSION['usuario_id'] ?? 0);

            db()->prepare(
                'UPDATE parametros_laborales
                 SET nombre = ?, valor = ?, tipo = ?, aplica_a = ?,
                     categoria = ?, aplica_contratos = ?, descripcion = ?,
                     activo = ?, updated_at = NOW()
                 WHERE id = ?'
            )->execute([
                $nombre, $valor, $tipo, $aplica_a,
                $categoria, $aplica_contratos, $descripcion ?: null,
                $activo ? 1 : 0,
                $id,
            ]);

            require_once __DIR__ . '/../../app/helpers/AuditoriaHelper.php';
            log_registrar('parametros_laborales', $id, 'edicion_completa', null, $nombre, 'UPDATE');

            // Invalidar caché de parámetros (evita que cálculos usen valores viejos)
            NominaModel::invalidar_cache();

            echo json_encode(['success' => true]);
            break;

        case 'crear':
            $clave = trim($_POST['clave'] ?? '');
            $nombre= trim($_POST['nombre'] ?? '');
            if (empty($clave) || empty($nombre)) {
                throw new \RuntimeException('Clave y nombre son obligatorios.');
            }
            $id = NominaModel::crear_parametro($_POST);
            echo json_encode(['success' => true, 'id' => $id]);
            break;

        case 'eliminar':
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) throw new \RuntimeException('ID inválido.');
            db()->prepare('DELETE FROM parametros_laborales WHERE id = ?')->execute([$id]);
            echo json_encode(['success' => true]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Acción inválida.']);
    }
} catch (\RuntimeException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\Exception $e) {
    error_log('[QuickServe OS Params] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Error interno.']);
}
