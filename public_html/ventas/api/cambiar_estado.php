<?php
/**
 * ventas/api/cambiar_estado.php — Marcar venta pagada o anularla.
 *
 * POST accion=marcar_pagado → fecha_pago=NOW(), estado=completada; reduce saldo_fiado si era fiado.
 *                             Requiere permiso editar_existentes.
 *                             Transacción atómica: ventas + clientes en una sola unidad.
 * POST accion=anular        → VentaModel::anular() revierte stock y saldo_fiado.
 *                             Requiere permiso admin_total (escalado dentro del case).
 *
 * Ambas acciones requieren token CSRF válido.
 */
require_once __DIR__ . '/../../app/middleware/auth_check.php';
require_once __DIR__ . '/../../app/models/VentaModel.php';
// AuditoriaHelper ya se incluye vía VentaModel; require_once es idempotente si se llama de nuevo
require_once __DIR__ . '/../../app/helpers/AuditoriaHelper.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido.']);
    exit;
}

permiso_requerir('ventas', 'editar_existentes');

if (!csrf_verificar()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Token CSRF inválido.']);
    exit;
}

$accion = $_POST['accion'] ?? '';
$uid    = (int)($_SESSION['usuario_id'] ?? 0);

try {
    switch ($accion) {

        case 'marcar_pagado':
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) throw new RuntimeException('ID de venta inválido.');

            $pdo  = db();
            $stmt = $pdo->prepare(
                'SELECT id, estado, metodo_pago, total, cliente_id FROM ventas WHERE id = ?'
            );
            $stmt->execute([$id]);
            $v = $stmt->fetch();

            if (!$v)                                     throw new RuntimeException('Venta no encontrada.');
            if ($v['estado'] === 'anulada')              throw new RuntimeException('La venta está anulada.');
            if ($v['estado'] !== 'pendiente_pago')       throw new RuntimeException('Esta venta ya está pagada.');

            $fecha_pago = date('Y-m-d H:i:s');

            // Detectar migración 034 (saldo_anterior, saldo_posterior en pagos_fiado)
            static $tiene034mp = null;
            if ($tiene034mp === null) {
                try {
                    $tiene034mp = (int)$pdo->query(
                        "SELECT COUNT(*) FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pagos_fiado'
                           AND COLUMN_NAME='saldo_anterior'"
                    )->fetchColumn() > 0;
                } catch (\Exception $e) { $tiene034mp = false; }
            }

            // Transacción atómica: UPDATE ventas + UPDATE clientes + INSERT pagos_fiado
            $pdo->beginTransaction();

            $pdo->prepare(
                "UPDATE ventas SET fecha_pago = ?, estado = 'completada', updated_by = ? WHERE id = ?"
            )->execute([$fecha_pago, $uid, $id]);

            // Si era fiado, reducir saldo del cliente y registrar el abono en pagos_fiado
            if ($v['metodo_pago'] === 'fiado' && $v['cliente_id']) {
                $cliente_id = (int)$v['cliente_id'];
                $monto      = (float)$v['total'];

                // Snapshot del saldo ANTES del abono (migración 034)
                $saldoRow = $pdo->prepare('SELECT saldo_fiado FROM clientes WHERE id = ?');
                $saldoRow->execute([$cliente_id]);
                $saldo_anterior = (float)($saldoRow->fetchColumn() ?? 0);
                $saldo_posterior = max(0, $saldo_anterior - $monto);

                // Reducir saldo del cliente
                $pdo->prepare(
                    'UPDATE clientes
                     SET saldo_fiado = GREATEST(0, saldo_fiado - ?), updated_by = ?
                     WHERE id = ?'
                )->execute([$monto, $uid, $cliente_id]);

                // Registrar abono en pagos_fiado con snapshot de saldos (migración 034)
                // Esto crea trazabilidad: se puede ver cuánto debía antes y después de pagar
                if ($tiene034mp) {
                    $pdo->prepare(
                        'INSERT INTO pagos_fiado
                            (cliente_id, monto, metodo_pago, saldo_anterior, saldo_posterior, created_by)
                         VALUES (?, ?, ?, ?, ?, ?)'
                    )->execute([$cliente_id, $monto, 'efectivo', $saldo_anterior, $saldo_posterior, $uid]);
                } else {
                    $pdo->prepare(
                        'INSERT INTO pagos_fiado (cliente_id, monto, metodo_pago, created_by)
                         VALUES (?, ?, ?, ?)'
                    )->execute([$cliente_id, $monto, 'efectivo', $uid]);
                }
            }

            $pdo->commit();

            log_registrar('ventas', $id, 'fecha_pago', null, $fecha_pago, 'UPDATE');

            echo json_encode(['success' => true, 'fecha_pago' => date('d/m/Y H:i')]);
            break;

        case 'anular':
            $id = (int)($_POST['id'] ?? 0);
            permiso_requerir('ventas', 'admin_total');
            VentaModel::anular($id);
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
    error_log('[ClanDestino Ventas] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Error interno del servidor.']);
}
