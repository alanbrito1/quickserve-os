<?php
/**
 * inventario/api/insumo_crud.php
 * CRUD de insumos: crear, editar, eliminar (soft-delete).
 *
 * POST accion=crear   → INSERT nuevo insumo
 * POST accion=editar  → UPDATE insumo existente
 * POST accion=eliminar id=X → soft-delete (activo=0)
 */

require_once __DIR__ . '/../../app/middleware/auth_check.php';
require_once __DIR__ . '/../../app/helpers/AuditoriaHelper.php';

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

$accion = $_POST['accion'] ?? '';
$uid    = (int)($_SESSION['usuario_id'] ?? 0);

// Listas de valores válidos
$PRESENTACIONES = ['frasco','tarro','caja','paca','bolsa','atado','lata','bloque','galon','unidad','otra'];
$UNIDADES       = ['kg','g','lb','litro','ml','unidad','loncha','lata','paquete'];
$CATEGORIAS     = ['proteína','lácteo','vegetal','condimento','empaque','grasa','combo','otro'];

try {
    switch ($accion) {

        // ── CREAR nuevo insumo ───────────────────────────────────────────────
        case 'crear':
            $nombre = trim($_POST['nombre'] ?? '');
            if (empty($nombre)) throw new \RuntimeException('El nombre del insumo es obligatorio.');

            $presentacion        = in_array($_POST['presentacion']  ?? '', $PRESENTACIONES, true) ? $_POST['presentacion']  : null;
            $unidad_medida       = in_array($_POST['unidad_medida'] ?? '', $UNIDADES, true)        ? $_POST['unidad_medida'] : 'unidad';
            $cantidad_pres       = (float)str_replace(',', '.', $_POST['cantidad_presentacion'] ?? '0');
            $precio_pres         = (float)str_replace(',', '.', $_POST['precio_presentacion']   ?? '0');
            $costo_actual        = (float)str_replace(',', '.', $_POST['costo_actual']          ?? '0');
            $stock_actual        = (float)str_replace(',', '.', $_POST['stock_actual']          ?? '0');
            $stock_seguridad     = (float)str_replace(',', '.', $_POST['stock_seguridad']       ?? '0');
            $proveedor_id        = !empty($_POST['proveedor_id']) ? (int)$_POST['proveedor_id'] : null;
            $categoria           = in_array($_POST['categoria'] ?? '', $CATEGORIAS, true) ? $_POST['categoria'] : 'otro';
            $notas               = trim($_POST['notas'] ?? '');

            // Si se dan precio + cantidad de presentación, el costo se calcula automáticamente (trigger)
            // Pero si el usuario lo editó manualmente, usamos el manual
            if ($precio_pres > 0 && $cantidad_pres > 0) {
                $costo_actual = round($precio_pres / $cantidad_pres, 4);
            }

            db()->prepare(
                'INSERT INTO insumos
                    (nombre, categoria, presentacion, cantidad_presentacion, precio_presentacion,
                     unidad_medida, costo_actual, stock_actual, stock_seguridad,
                     proveedor_id, notas, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $nombre, $categoria,
                $presentacion, $cantidad_pres ?: null, $precio_pres ?: null,
                $unidad_medida, $costo_actual, $stock_actual, $stock_seguridad,
                $proveedor_id, $notas ?: null, $uid,
            ]);

            $id = (int)db()->lastInsertId();
            log_registrar('insumos', $id, 'nombre', null, $nombre, 'INSERT');
            echo json_encode(['success' => true, 'id' => $id]);
            break;

        // ── EDITAR insumo existente ──────────────────────────────────────────
        case 'editar':
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) throw new \RuntimeException('ID inválido.');

            $nombre              = trim($_POST['nombre'] ?? '');
            $presentacion        = in_array($_POST['presentacion']  ?? '', $PRESENTACIONES, true) ? $_POST['presentacion']  : null;
            $unidad_medida       = in_array($_POST['unidad_medida'] ?? '', $UNIDADES, true)        ? $_POST['unidad_medida'] : 'unidad';
            $cantidad_pres       = (float)str_replace(',', '.', $_POST['cantidad_presentacion'] ?? '0');
            $precio_pres         = (float)str_replace(',', '.', $_POST['precio_presentacion']   ?? '0');
            $costo_actual        = (float)str_replace(',', '.', $_POST['costo_actual']          ?? '0');
            $stock_seguridad     = (float)str_replace(',', '.', $_POST['stock_seguridad']       ?? '0');
            $proveedor_id        = !empty($_POST['proveedor_id']) ? (int)$_POST['proveedor_id'] : null;
            $categoria           = in_array($_POST['categoria'] ?? '', $CATEGORIAS, true) ? $_POST['categoria'] : 'otro';
            $notas               = trim($_POST['notas'] ?? '');

            if ($precio_pres > 0 && $cantidad_pres > 0) {
                $costo_actual = round($precio_pres / $cantidad_pres, 4);
            }

            // Auditar cambio de costo
            $prev = db()->prepare('SELECT costo_actual FROM insumos WHERE id = ?');
            $prev->execute([$id]);
            $costo_ant = $prev->fetchColumn();

            db()->prepare(
                'UPDATE insumos
                 SET nombre = ?, categoria = ?, presentacion = ?,
                     cantidad_presentacion = ?, precio_presentacion = ?,
                     unidad_medida = ?, costo_actual = ?,
                     stock_seguridad = ?, proveedor_id = ?,
                     notas = ?, updated_by = ?
                 WHERE id = ?'
            )->execute([
                $nombre, $categoria,
                $presentacion, $cantidad_pres ?: null, $precio_pres ?: null,
                $unidad_medida, $costo_actual,
                $stock_seguridad, $proveedor_id,
                $notas ?: null, $uid, $id,
            ]);

            if (abs((float)$costo_ant - $costo_actual) > 0.001) {
                log_registrar('insumos', $id, 'costo_actual',
                    (string)$costo_ant, (string)$costo_actual, 'UPDATE');

                // Recalcular costo de productos que usan este insumo
                db()->prepare(
                    "UPDATE productos p
                     SET p.costo_calculado = (
                         SELECT IFNULL(SUM(i.costo_actual * r.cantidad_requerida), 0)
                         FROM recetas r JOIN insumos i ON i.id = r.insumo_id
                         WHERE r.producto_id = p.id
                     ), p.updated_by = ?
                     WHERE p.activo = 1
                       AND p.id IN (SELECT DISTINCT r2.producto_id FROM recetas r2 WHERE r2.insumo_id = ?)"
                )->execute([$uid, $id]);
            }

            echo json_encode(['success' => true]);
            break;

        // ── ELIMINAR insumo (soft-delete) ────────────────────────────────────
        case 'eliminar':
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) throw new \RuntimeException('ID inválido.');

            // Verificar si tiene stock o está en recetas
            $chk = db()->prepare(
                'SELECT nombre, stock_actual,
                        (SELECT COUNT(*) FROM recetas WHERE insumo_id = :id) AS en_recetas,
                        (SELECT COUNT(*) FROM compra_detalles WHERE insumo_id = :id2) AS en_compras
                 FROM insumos WHERE id = :id3'
            );
            $chk->execute([':id' => $id, ':id2' => $id, ':id3' => $id]);
            $ins = $chk->fetch();

            if (!$ins) throw new \RuntimeException('Insumo no encontrado.');

            $advertencias = [];
            if ((float)$ins['stock_actual'] > 0) {
                $advertencias[] = "tiene stock actual ({$ins['stock_actual']})";
            }
            if ((int)$ins['en_recetas'] > 0) {
                $advertencias[] = "está en {$ins['en_recetas']} receta(s)";
            }

            // Soft-delete: marcar como inactivo
            db()->prepare('UPDATE insumos SET activo = 0, updated_by = ? WHERE id = ?')
                ->execute([$uid, $id]);

            log_registrar('insumos', $id, 'activo', '1', '0', 'UPDATE');

            $msg = count($advertencias) > 0
                ? "Insumo desactivado (advertencia: " . implode(', ', $advertencias) . "). Puede reactivarlo desde Ajustar."
                : "Insumo eliminado correctamente.";

            echo json_encode(['success' => true, 'mensaje' => $msg, 'advertencias' => $advertencias]);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Acción inválida.']);
    }

} catch (\RuntimeException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\Exception $e) {
    error_log('[ClanDestino Inventario CRUD] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Error interno.']);
}
