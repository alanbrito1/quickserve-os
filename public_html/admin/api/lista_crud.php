<?php
/**
 * admin/api/lista_crud.php — CRUD de ítems de listas_sistema.
 *
 * POST accion=crear  → Agrega un nuevo ítem a un catálogo
 * POST accion=editar → Actualiza etiqueta y/u orden de un ítem (valor no editable)
 * POST accion=toggle → Activa o desactiva un ítem
 * POST accion=orden  → Actualiza solo el orden (guardado inline en la tabla)
 *
 * Acceso: solo superadmin y admin (mismo control que el resto del módulo Admin).
 * Requiere: método POST + token CSRF válido.
 *
 * Retorna: {"success":true,...} o {"success":false,"error":"..."}
 */

require_once __DIR__ . '/../../app/middleware/auth_check.php';
require_once __DIR__ . '/../../app/helpers/AuditoriaHelper.php';

header('Content-Type: application/json; charset=utf-8');

// Solo admin y superadmin pueden gestionar los catálogos del sistema
if (!in_array($_SESSION['usuario_rol'] ?? '', ['superadmin','admin'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Sin permisos para esta acción.']);
    exit;
}

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

$accion = $_POST['accion'] ?? '';
$uid    = (int)($_SESSION['usuario_id'] ?? 0);

// Tipos de lista válidos — whitelist para evitar inyección de tipos arbitrarios
$TIPOS_VALIDOS = [
    'presentacion', 'unidad_medida', 'categoria_insumo',
    'categoria_producto', 'tamano_producto',               // módulo Productos
    'categoria_activo', 'categoria_costo', 'categoria_proveedor',
];

try {
    $pdo = db();

    switch ($accion) {

        // ── CREAR ─────────────────────────────────────────────────────────
        case 'crear':
            $tipo     = $_POST['tipo']     ?? '';
            $valor    = trim($_POST['valor']    ?? '');
            $etiqueta = trim($_POST['etiqueta'] ?? '');
            $orden    = max(0, min(999, (int)($_POST['orden'] ?? 0)));

            // Validar tipo contra la whitelist
            if (!in_array($tipo, $TIPOS_VALIDOS, true)) {
                echo json_encode(['success' => false, 'error' => 'Tipo de lista inválido.']);
                exit;
            }

            if (empty($valor)) {
                echo json_encode(['success' => false, 'error' => 'El valor es obligatorio.']);
                exit;
            }
            if (empty($etiqueta)) {
                echo json_encode(['success' => false, 'error' => 'La etiqueta es obligatoria.']);
                exit;
            }

            // Solo letras minúsculas, números y guion bajo en el valor
            // (para consistencia con los valores históricos de la BD)
            if (!preg_match('/^[a-z0-9_]+$/', $valor)) {
                echo json_encode(['success' => false, 'error' => 'El valor solo puede contener letras minúsculas, números y guion bajo.']);
                exit;
            }

            $pdo->prepare(
                'INSERT INTO listas_sistema (tipo, valor, etiqueta, orden, created_by)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$tipo, $valor, $etiqueta, $orden, $uid]);

            $id = (int)$pdo->lastInsertId();
            log_registrar('listas_sistema', $id, 'valor', null, "{$tipo}:{$valor}", 'INSERT');

            echo json_encode(['success' => true, 'id' => $id]);
            break;

        // ── EDITAR ────────────────────────────────────────────────────────
        // Solo se permite cambiar etiqueta y orden.
        // El valor (clave BD) es inmutable: los datos existentes en otras tablas
        // lo referencian y cambiar el valor rompería esa integridad.
        case 'editar':
            $id       = (int)($_POST['id']       ?? 0);
            $etiqueta = trim($_POST['etiqueta']   ?? '');
            $orden    = max(0, min(999, (int)($_POST['orden'] ?? 0)));

            if (!$id)           { echo json_encode(['success'=>false,'error'=>'ID inválido.']); exit; }
            if (empty($etiqueta)) { echo json_encode(['success'=>false,'error'=>'La etiqueta es obligatoria.']); exit; }

            $stmt = $pdo->prepare('SELECT id FROM listas_sistema WHERE id = ?');
            $stmt->execute([$id]);
            if (!$stmt->fetchColumn()) {
                echo json_encode(['success' => false, 'error' => 'Ítem no encontrado.']);
                exit;
            }

            $pdo->prepare(
                'UPDATE listas_sistema SET etiqueta = ?, orden = ? WHERE id = ?'
            )->execute([$etiqueta, $orden, $id]);

            log_registrar('listas_sistema', $id, 'etiqueta', null, $etiqueta, 'UPDATE');
            echo json_encode(['success' => true]);
            break;

        // ── TOGGLE ────────────────────────────────────────────────────────
        // Activar / desactivar un ítem. Los datos históricos que usan ese valor
        // no se alteran; solo deja de aparecer en nuevos formularios.
        case 'toggle':
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) { echo json_encode(['success'=>false,'error'=>'ID inválido.']); exit; }

            $stmt = $pdo->prepare('SELECT activo FROM listas_sistema WHERE id = ?');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) { echo json_encode(['success'=>false,'error'=>'Ítem no encontrado.']); exit; }

            $nuevo = (int)$row['activo'] === 1 ? 0 : 1;
            $pdo->prepare('UPDATE listas_sistema SET activo = ? WHERE id = ?')
                ->execute([$nuevo, $id]);

            log_registrar('listas_sistema', $id, 'activo', (string)$row['activo'], (string)$nuevo, 'UPDATE');
            echo json_encode(['success' => true, 'activo' => (bool)$nuevo]);
            break;

        // ── ORDEN ─────────────────────────────────────────────────────────
        // Guardado inline: el usuario cambia el número en la tabla y se
        // persiste al perder el foco, sin recargar la página.
        case 'orden':
            $id    = (int)($_POST['id']    ?? 0);
            $orden = max(0, min(999, (int)($_POST['orden'] ?? 0)));
            if (!$id) { echo json_encode(['success'=>false,'error'=>'ID inválido.']); exit; }

            $pdo->prepare('UPDATE listas_sistema SET orden = ? WHERE id = ?')
                ->execute([$orden, $id]);

            echo json_encode(['success' => true]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Acción inválida.']);
    }

} catch (\PDOException $e) {
    // SQLSTATE 23000 = Integrity Constraint Violation (incluye UK duplicados)
    // Usamos el código estándar SQLSTATE en lugar de parsear el mensaje de texto
    // (el mensaje varía según idioma/versión de MySQL/MariaDB)
    if ($e->getCode() === '23000') {
        echo json_encode(['success' => false, 'error' => 'Ya existe un ítem con ese valor en este catálogo.']);
    } else {
        error_log('[QuickServe OS ListaCRUD] ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Error de base de datos.']);
    }
} catch (\Exception $e) {
    error_log('[QuickServe OS ListaCRUD] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Error interno del servidor.']);
}
