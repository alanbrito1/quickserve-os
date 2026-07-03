<?php
/**
 * clientes/api/registrar_abono.php
 * Registra un abono/pago de deuda fiado para un cliente.
 * Requiere: POST + CSRF + permiso ventas:editar_existentes.
 */

require_once __DIR__ . '/../../app/middleware/auth_check.php';
require_once __DIR__ . '/../../app/helpers/AuditoriaHelper.php';

header('Content-Type: application/json; charset=utf-8');

permiso_requerir('ventas', 'editar_existentes');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verificar()) {
    echo json_encode(['success' => false, 'error' => 'Petición inválida.']);
    exit;
}

$cliente_id = (int)($_POST['cliente_id']  ?? 0);
$monto      = (float)($_POST['monto']     ?? 0);
$metodo     = trim($_POST['metodo_pago']  ?? 'efectivo');
$notas      = substr(trim($_POST['notas'] ?? ''), 0, 255);

$metodos_validos = ['efectivo', 'nequi', 'daviplata', 'bancolombia'];

if ($cliente_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Cliente inválido.']);
    exit;
}
if ($monto <= 0) {
    echo json_encode(['success' => false, 'error' => 'El monto debe ser mayor a cero.']);
    exit;
}
if (!in_array($metodo, $metodos_validos, true)) {
    $metodo = 'efectivo';
}

$pdo = db();

try {
    $pdo->beginTransaction();

    // Leer saldo actual con lock para evitar condición de carrera
    $stmt = $pdo->prepare('SELECT id, saldo_fiado FROM clientes WHERE id = ? FOR UPDATE');
    $stmt->execute([$cliente_id]);
    $cliente = $stmt->fetch();

    if (!$cliente) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Cliente no encontrado.']);
        exit;
    }

    $saldo_anterior = (float)$cliente['saldo_fiado'];

    if ($saldo_anterior <= 0) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Este cliente no tiene deuda pendiente.']);
        exit;
    }

    // Clamped: no se puede abonar más de lo que debe
    $monto_real      = min($monto, $saldo_anterior);
    $saldo_posterior = round(max(0, $saldo_anterior - $monto_real), 2);

    // Registrar en pagos_fiado
    $pdo->prepare(
        "INSERT INTO pagos_fiado
             (cliente_id, monto, metodo_pago, notas, saldo_anterior, saldo_posterior, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    )->execute([
        $cliente_id,
        $monto_real,
        $metodo,
        $notas ?: null,
        $saldo_anterior,
        $saldo_posterior,
        $_SESSION['usuario_id'] ?? 0,
    ]);
    $abono_id = (int)$pdo->lastInsertId();

    // Actualizar saldo del cliente
    $pdo->prepare('UPDATE clientes SET saldo_fiado = ? WHERE id = ?')
        ->execute([$saldo_posterior, $cliente_id]);

    $pdo->commit();

    log_registrar('pagos_fiado', $abono_id, 'abono',
        (string)$saldo_anterior, (string)$saldo_posterior, 'INSERT');

    // Contabilidad (Fase 4b): asiento del abono (Caja/Bancos vs CxC), tras el commit y aislado.
    try {
        require_once __DIR__ . '/../../app/models/ContabilidadModel.php';
        ContabilidadModel::postear_abono($abono_id);
    } catch (\Throwable $e) { error_log('[ClanDestino contab abono] ' . $e->getMessage()); }

    echo json_encode([
        'success'          => true,
        'monto_registrado' => $monto_real,
        'saldo_anterior'   => $saldo_anterior,
        'saldo_posterior'  => $saldo_posterior,
    ]);

} catch (\Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[ClanDestino] Error en registrar_abono: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Error interno. Intenta de nuevo.']);
}
