<?php
/**
 * admin/api/mantenimiento.php — Motor de limpieza de datos (solo superadmin).
 *
 * Acciones (POST):
 *   accion=stats               → conteos (total / inactivos / anulados) por entidad
 *   accion=reset_transaccional → vacía ventas/compras/producción/nómina/fiado/turnos/ajustes
 *                                (conserva catálogo); opcional reset de stock
 *   accion=borrar              → entidad + ambito(inactivos|anulados|todos) + modo(seguro|cascada)
 *
 * Seguridad: solo rol superadmin; CSRF; las acciones destructivas exigen
 * $_POST['confirmacion'] === 'BORRAR'. Todo en transacción + auditoría.
 */

require_once __DIR__ . '/../../app/middleware/auth_check.php';
require_once __DIR__ . '/../../app/helpers/AuditoriaHelper.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido.']);
    exit;
}

// Solo superadmin — operaciones destructivas de alto impacto
if (!in_array($_SESSION['usuario_rol'] ?? '', ['superadmin'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Solo el superadmin puede ejecutar mantenimiento de datos.']);
    exit;
}

if (!csrf_verificar()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Token CSRF inválido.']);
    exit;
}

$accion = $_POST['accion'] ?? '';
$uid    = (int)($_SESSION['usuario_id'] ?? 0);
$pdo    = db();

/**
 * Mapa de entidades limpiables.
 *  tipo    : 'activo' (col activo=0 ⇒ inactivo) | 'estado' (col=valor ⇒ anulado)
 *  col     : columna de baja
 *  baja    : valor que marca la baja (0 / 'anulada' / 'anulado')
 *  hijos   : [[tabla, fk]] que (a) bloquean el borrado "seguro" si tienen filas y
 *            (b) se borran primero en modo "cascada". Las FKs ON DELETE CASCADE/SET NULL
 *            no se listan (las maneja la BD).
 */
$ENTIDADES = [
    'ventas' => [
        'label' => 'Ventas', 'tabla' => 'ventas', 'tipo' => 'estado',
        'col' => 'estado', 'baja' => 'anulada', 'hijos' => [], // venta_detalles → CASCADE
    ],
    'compras' => [
        'label' => 'Compras', 'tabla' => 'compras', 'tipo' => 'todos_only',
        'col' => null, 'baja' => null, 'hijos' => [], // compra_detalles → CASCADE; no tiene estado
    ],
    'produccion' => [
        'label' => 'Producción (lotes)', 'tabla' => 'produccion_lotes', 'tipo' => 'estado',
        'col' => 'estado', 'baja' => 'anulado', 'hijos' => [],
    ],
    'productos' => [
        'label' => 'Productos', 'tabla' => 'productos', 'tipo' => 'activo',
        'col' => 'activo', 'baja' => 0,
        'hijos' => [['venta_detalles', 'producto_id'], ['produccion_lotes', 'producto_id'], ['ajustes_stock', 'producto_id']],
    ],
    'insumos' => [
        'label' => 'Insumos', 'tabla' => 'insumos', 'tipo' => 'activo',
        'col' => 'activo', 'baja' => 0,
        'hijos' => [['compra_detalles', 'insumo_id']],
    ],
    'clientes' => [
        'label' => 'Clientes', 'tabla' => 'clientes', 'tipo' => 'activo',
        'col' => 'activo', 'baja' => 0,
        // ventas → clientes es SET NULL (no se borra la venta); pagos_fiado bloquea
        'hijos' => [['pagos_fiado', 'cliente_id']],
        'hijos_setnull' => [['ventas', 'cliente_id']], // bloquean "seguro" pero NO se borran en cascada
    ],
    'proveedores' => [
        'label' => 'Proveedores', 'tabla' => 'proveedores', 'tipo' => 'activo',
        'col' => 'activo', 'baja' => 0, 'hijos' => [], // insumos/compras/activos → SET NULL
    ],
    'empleados' => [
        'label' => 'Empleados', 'tabla' => 'empleados', 'tipo' => 'activo',
        'col' => 'activo', 'baja' => 0,
        'hijos' => [['nomina_liquidaciones', 'empleado_id']], // registro_horas → CASCADE
    ],
    'activos' => [
        'label' => 'Activos', 'tabla' => 'activos', 'tipo' => 'activo',
        'col' => 'activo', 'baja' => 0, 'hijos' => [],
    ],
    'costos' => [
        'label' => 'Costos indirectos', 'tabla' => 'costos_indirectos', 'tipo' => 'activo',
        'col' => 'activo', 'baja' => 0, 'hijos' => [],
    ],
];

/** Cuenta filas con un WHERE simple (seguro: tabla/col del mapa, sin input crudo). */
function contar(PDO $pdo, string $tabla, string $where = '1', array $par = []): int
{
    $s = $pdo->prepare("SELECT COUNT(*) FROM `$tabla` WHERE $where");
    $s->execute($par);
    return (int)$s->fetchColumn();
}

try {

    // ── STATS ────────────────────────────────────────────────────────────────
    if ($accion === 'stats') {
        $out = [];
        foreach ($ENTIDADES as $k => $e) {
            $total     = contar($pdo, $e['tabla']);
            $inactivos = 0;
            $anulados  = 0;
            if ($e['tipo'] === 'activo') {
                $inactivos = contar($pdo, $e['tabla'], "`{$e['col']}` = 0");
            } elseif ($e['tipo'] === 'estado') {
                $anulados = contar($pdo, $e['tabla'], "`{$e['col']}` = ?", [$e['baja']]);
            }
            $out[$k] = [
                'label' => $e['label'], 'tipo' => $e['tipo'],
                'total' => $total, 'inactivos' => $inactivos, 'anulados' => $anulados,
            ];
        }
        echo json_encode(['success' => true, 'entidades' => $out]);
        exit;
    }

    // A partir de aquí: acciones destructivas → exigen confirmación escrita
    if (($_POST['confirmacion'] ?? '') !== 'BORRAR') {
        echo json_encode(['success' => false, 'error' => 'Debes escribir BORRAR para confirmar.']);
        exit;
    }

    // ── RESET TRANSACCIONAL GLOBAL ───────────────────────────────────────────
    if ($accion === 'reset_transaccional') {
        $reset_stock = ($_POST['reset_stock'] ?? '') === '1';
        $pdo->beginTransaction();

        // Orden FK-seguro (hijos antes que padres; CASCADE cubre el resto)
        $tablas = [
            'venta_detalles', 'pagos_fiado', 'ventas',
            'compra_detalles', 'compras',
            'produccion_lotes', 'ajustes_stock',
            'nomina_liquidaciones', 'registro_horas',
            'turnos_caja', 'logs_historial',
        ];
        $borrados = [];
        foreach ($tablas as $t) {
            $n = $pdo->exec("DELETE FROM `$t`");
            $borrados[$t] = (int)$n;
        }
        // Resetear deuda de fiado de todos los clientes
        $pdo->exec("UPDATE clientes SET saldo_fiado = 0 WHERE saldo_fiado <> 0");
        if ($reset_stock) {
            $pdo->exec("UPDATE productos SET stock_disponible = 0 WHERE stock_disponible <> 0");
            $pdo->exec("UPDATE insumos   SET stock_actual     = 0 WHERE stock_actual     <> 0");
        }
        $pdo->commit();

        log_registrar('mantenimiento', 0, 'reset_transaccional', null,
            'stock=' . ($reset_stock ? '1' : '0') . ' ' . json_encode($borrados), 'DELETE');
        echo json_encode(['success' => true, 'borrados' => $borrados, 'reset_stock' => $reset_stock]);
        exit;
    }

    // ── BORRAR (inactivos | anulados | todos), modo seguro | cascada ─────────
    if ($accion === 'borrar') {
        $ek     = $_POST['entidad'] ?? '';
        $ambito = $_POST['ambito']  ?? '';
        $modo   = ($_POST['modo'] ?? 'seguro') === 'cascada' ? 'cascada' : 'seguro';

        if (!isset($ENTIDADES[$ek])) {
            echo json_encode(['success' => false, 'error' => 'Entidad no válida.']);
            exit;
        }
        $e = $ENTIDADES[$ek];

        // Resolver el WHERE del ámbito (sin input crudo: valores del mapa)
        if ($ambito === 'todos') {
            $where = '1'; $par = [];
        } elseif ($ambito === 'inactivos' && $e['tipo'] === 'activo') {
            $where = "`{$e['col']}` = 0"; $par = [];
        } elseif ($ambito === 'anulados' && $e['tipo'] === 'estado') {
            $where = "`{$e['col']}` = ?"; $par = [$e['baja']];
        } else {
            echo json_encode(['success' => false, 'error' => 'Ámbito no aplicable a esta entidad.']);
            exit;
        }

        $tabla     = $e['tabla'];
        $enScope   = contar($pdo, $tabla, $where, $par);
        $hijos     = $e['hijos'] ?? [];
        $hijosSN   = $e['hijos_setnull'] ?? [];

        $pdo->beginTransaction();
        $borrados = 0; $omitidos = 0;

        if ($modo === 'seguro') {
            // Borra solo las filas del ámbito SIN referencias en hijos (FK) ni hijos_setnull
            $conds = [$where];
            $bloqueadores = array_merge($hijos, $hijosSN);
            foreach ($bloqueadores as [$ht, $hfk]) {
                $conds[] = "NOT EXISTS (SELECT 1 FROM `$ht` WHERE `$ht`.`$hfk` = `$tabla`.id)";
            }
            $sql = "DELETE FROM `$tabla` WHERE " . implode(' AND ', $conds);
            $st  = $pdo->prepare($sql);
            $st->execute($par);
            $borrados = $st->rowCount();
            $omitidos = $enScope - $borrados;
        } else {
            // Cascada: borra primero los hijos FK (RESTRICT) de las filas en ámbito, luego las filas.
            // Subconsulta por ids del ámbito.
            $idsSub = "(SELECT id FROM (SELECT id FROM `$tabla` WHERE $where) AS _s)";
            foreach ($hijos as [$ht, $hfk]) {
                $st = $pdo->prepare("DELETE FROM `$ht` WHERE `$hfk` IN $idsSub");
                $st->execute($par);
            }
            // hijos_setnull: la FK ON DELETE SET NULL lo hace sola al borrar el padre.
            $st = $pdo->prepare("DELETE FROM `$tabla` WHERE $where");
            $st->execute($par);
            $borrados = $st->rowCount();
            $omitidos = 0;
        }

        $pdo->commit();
        log_registrar('mantenimiento', 0, "borrar_{$ek}", null,
            "ambito={$ambito} modo={$modo} borrados={$borrados} omitidos={$omitidos}", 'DELETE');

        echo json_encode([
            'success' => true, 'entidad' => $ek, 'ambito' => $ambito, 'modo' => $modo,
            'en_scope' => $enScope, 'borrados' => $borrados, 'omitidos' => $omitidos,
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Acción inválida.']);

} catch (\Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[QuickServe OS mantenimiento] ' . $ex->getMessage());
    echo json_encode(['success' => false, 'error' => 'Error interno al ejecutar la operación.']);
}
