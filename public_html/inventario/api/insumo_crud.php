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

$accion = $_POST['accion'] ?? '';
$uid    = (int)($_SESSION['usuario_id'] ?? 0);

// Validación de valores de presentacion, unidad_medida y categoria_insumo
// contra la tabla listas_sistema (Admin → Catálogos).
// Si la migración 029 aún no está aplicada, listas_valor_valido() retorna
// true para valores vacíos/null (campo opcional), por lo que el módulo
// sigue funcionando durante la transición.

try {
    switch ($accion) {

        // ── CREAR nuevo insumo ───────────────────────────────────────────────
        case 'crear':
            $nombre = trim($_POST['nombre'] ?? '');
            if (empty($nombre)) throw new \RuntimeException('El nombre del insumo es obligatorio.');

            // Validar contra listas_sistema; si el valor no existe o la tabla no está,
            // listas_valor_valido() retorna true para vacíos y false para valores inválidos
            $pres_raw      = $_POST['presentacion']  ?? '';
            $unidad_raw    = $_POST['unidad_medida'] ?? 'unidad';
            $cat_raw       = $_POST['categoria']     ?? 'otro';
            $presentacion  = listas_valor_valido('presentacion',    $pres_raw)   ? ($pres_raw   ?: null) : null;
            $unidad_medida = listas_valor_valido('unidad_medida',   $unidad_raw) ? $unidad_raw  : 'unidad';
            $categoria     = listas_valor_valido('categoria_insumo',$cat_raw)    ? $cat_raw     : 'otro';
            $cantidad_pres = (float)str_replace(',', '.', $_POST['cantidad_presentacion'] ?? '0');
            $precio_pres   = (float)str_replace(',', '.', $_POST['precio_presentacion']   ?? '0');
            $costo_actual  = (float)str_replace(',', '.', $_POST['costo_actual']          ?? '0');
            $stock_actual  = (float)str_replace(',', '.', $_POST['stock_actual']          ?? '0');
            $stock_seguridad = (float)str_replace(',', '.', $_POST['stock_seguridad']     ?? '0');
            $proveedor_id  = !empty($_POST['proveedor_id']) ? (int)$_POST['proveedor_id'] : null;
            $notas         = trim($_POST['notas'] ?? '');
            // Equivalencia física: cuánto pesa/contiene una unidad no-física (loncha, lata, etc.)
            $equiv_cantidad = !empty($_POST['equiv_cantidad'])
                ? (float)str_replace(',', '.', $_POST['equiv_cantidad']) : null;
            $equiv_unidad   = in_array($_POST['equiv_unidad'] ?? '', ['g','kg','ml','litro'], true)
                ? $_POST['equiv_unidad'] : null;
            // Si la unidad ya es física, limpiar equivalencia (no tiene sentido)
            if (in_array($unidad_medida, ['kg','g','lb','litro','ml'], true)) {
                $equiv_cantidad = null;
                $equiv_unidad   = null;
            }
            // Coherencia: la equivalencia solo sirve con AMBOS campos (cantidad y unidad).
            // Si falta uno, se descartan los dos (evita "equivalencia a medias").
            if ($equiv_cantidad === null || $equiv_unidad === null) {
                $equiv_cantidad = null;
                $equiv_unidad   = null;
            }

            // Si se dan precio + cantidad de presentación, el costo se calcula automáticamente
            if ($precio_pres > 0 && $cantidad_pres > 0) {
                $costo_actual = round($precio_pres / $cantidad_pres, 4);
            }

            db()->prepare(
                'INSERT INTO insumos
                    (nombre, categoria, presentacion, cantidad_presentacion, precio_presentacion,
                     unidad_medida, equiv_cantidad, equiv_unidad,
                     costo_actual, stock_actual, stock_seguridad,
                     proveedor_id, notas, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $nombre, $categoria,
                $presentacion, $cantidad_pres ?: null, $precio_pres ?: null,
                $unidad_medida, $equiv_cantidad, $equiv_unidad,
                $costo_actual, $stock_actual, $stock_seguridad,
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

            $nombre = trim($_POST['nombre'] ?? '');
            if (empty($nombre)) throw new \RuntimeException('El nombre del insumo es obligatorio.');
            $pres_raw      = $_POST['presentacion']  ?? '';
            $unidad_raw    = $_POST['unidad_medida'] ?? 'unidad';
            $cat_raw       = $_POST['categoria']     ?? 'otro';
            $presentacion  = listas_valor_valido('presentacion',    $pres_raw)   ? ($pres_raw   ?: null) : null;
            $unidad_medida = listas_valor_valido('unidad_medida',   $unidad_raw) ? $unidad_raw  : 'unidad';
            $categoria     = listas_valor_valido('categoria_insumo',$cat_raw)    ? $cat_raw     : 'otro';
            $cantidad_pres = (float)str_replace(',', '.', $_POST['cantidad_presentacion'] ?? '0');
            $precio_pres   = (float)str_replace(',', '.', $_POST['precio_presentacion']   ?? '0');
            $costo_actual  = (float)str_replace(',', '.', $_POST['costo_actual']          ?? '0');
            $stock_seguridad = (float)str_replace(',', '.', $_POST['stock_seguridad']     ?? '0');
            $proveedor_id  = !empty($_POST['proveedor_id']) ? (int)$_POST['proveedor_id'] : null;
            $notas         = trim($_POST['notas'] ?? '');
            // Equivalencia física (igual que en crear)
            $equiv_cantidad = !empty($_POST['equiv_cantidad'])
                ? (float)str_replace(',', '.', $_POST['equiv_cantidad']) : null;
            $equiv_unidad   = in_array($_POST['equiv_unidad'] ?? '', ['g','kg','ml','litro'], true)
                ? $_POST['equiv_unidad'] : null;
            if (in_array($unidad_medida, ['kg','g','lb','litro','ml'], true)) {
                $equiv_cantidad = null;
                $equiv_unidad   = null;
            }
            // Coherencia: ambos campos o ninguno (evita "equivalencia a medias").
            if ($equiv_cantidad === null || $equiv_unidad === null) {
                $equiv_cantidad = null;
                $equiv_unidad   = null;
            }

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
                     unidad_medida = ?, equiv_cantidad = ?, equiv_unidad = ?,
                     costo_actual = ?,
                     stock_seguridad = ?, proveedor_id = ?,
                     notas = ?, updated_by = ?
                 WHERE id = ?'
            )->execute([
                $nombre, $categoria,
                $presentacion, $cantidad_pres ?: null, $precio_pres ?: null,
                $unidad_medida, $equiv_cantidad, $equiv_unidad,
                $costo_actual,
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

        // ── DUPLICAR insumo ──────────────────────────────────────────────────
        case 'duplicar':
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) throw new \RuntimeException('ID inválido.');

            $orig = db()->prepare('SELECT * FROM insumos WHERE id = ? AND activo = 1');
            $orig->execute([$id]);
            $ins = $orig->fetch();
            if (!$ins) throw new \RuntimeException('Insumo no encontrado.');

            $nuevo_nombre = 'Copia de ' . $ins['nombre'];

            // Incluye equiv_cantidad y equiv_unidad (migración 030) en la copia.
            // stock_actual se pone en 0: la copia empieza sin stock físico.
            db()->prepare(
                'INSERT INTO insumos
                    (nombre, categoria, presentacion, cantidad_presentacion, precio_presentacion,
                     unidad_medida, equiv_cantidad, equiv_unidad,
                     costo_actual, stock_actual, stock_seguridad,
                     proveedor_id, notas, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,0,?,?,?,?)'
            )->execute([
                $nuevo_nombre, $ins['categoria'],
                $ins['presentacion'], $ins['cantidad_presentacion'], $ins['precio_presentacion'],
                $ins['unidad_medida'], $ins['equiv_cantidad'] ?? null, $ins['equiv_unidad'] ?? null,
                $ins['costo_actual'],
                $ins['stock_seguridad'], $ins['proveedor_id'],
                $ins['notas'], $uid,
            ]);

            $new_id = (int)db()->lastInsertId();
            log_registrar('insumos', $new_id, 'nombre', null, $nuevo_nombre, 'INSERT');
            echo json_encode(['success' => true, 'id' => $new_id, 'nombre' => $nuevo_nombre]);
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
    error_log('[QuickServe OS Inventario CRUD] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Error interno.']);
}
