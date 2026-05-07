<?php
/**
 * app/models/ClienteModel.php
 * Gestión de clientes y módulo de fiado (crédito informal).
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/AuditoriaHelper.php';

class ClienteModel
{
    /** Retorna todos los clientes activos ordenados por nombre (para el dropdown del POS). */
    public static function todos(): array
    {
        return db()->query(
            'SELECT id, nombre, telefono, saldo_fiado
             FROM clientes WHERE activo = 1 ORDER BY nombre'
        )->fetchAll();
    }

    /** Retorna clientes con deuda pendiente, ordenados por mayor saldo. */
    public static function con_fiado(): array
    {
        return db()->query(
            "SELECT c.id, c.nombre, c.telefono, c.saldo_fiado,
                    (SELECT MAX(v.fecha_venta)
                     FROM ventas v
                     WHERE v.cliente_id = c.id
                       AND v.metodo_pago = 'fiado'
                       AND v.estado = 'completada') AS ultima_compra
             FROM clientes c
             WHERE c.activo = 1 AND c.saldo_fiado > 0
             ORDER BY c.saldo_fiado DESC"
        )->fetchAll();
    }

    /**
     * Crea un nuevo cliente.
     * @return int ID del cliente creado
     * @throws RuntimeException si el nombre está vacío
     */
    public static function crear(string $nombre, ?string $telefono = null): int
    {
        $nombre = trim($nombre);
        if (empty($nombre)) throw new RuntimeException('El nombre del cliente es obligatorio.');

        $uid = (int)($_SESSION['usuario_id'] ?? 0);

        $stmt = db()->prepare(
            'INSERT INTO clientes (nombre, telefono, created_by) VALUES (?, ?, ?)'
        );
        $stmt->execute([$nombre, $telefono ?: null, $uid]);
        $id = (int)db()->lastInsertId();

        log_registrar('clientes', $id, 'nombre', null, $nombre, 'INSERT');

        return $id;
    }

    /**
     * Registra un abono a la deuda de un cliente.
     * Valida que el monto no supere el saldo actual para evitar saldo negativo.
     *
     * @throws RuntimeException si el monto es inválido o excede el saldo
     */
    public static function registrar_abono(
        int    $cliente_id,
        float  $monto,
        string $metodo_pago
    ): void {
        $pdo = db();
        $uid = (int)($_SESSION['usuario_id'] ?? 0);

        // Obtener saldo actual del cliente
        $stmt = $pdo->prepare('SELECT saldo_fiado, nombre FROM clientes WHERE id = ? AND activo = 1');
        $stmt->execute([$cliente_id]);
        $cliente = $stmt->fetch();

        if (!$cliente) throw new RuntimeException('Cliente no encontrado.');
        if ($monto <= 0) throw new RuntimeException('El monto del abono debe ser mayor a $0.');
        if ($monto > (float)$cliente['saldo_fiado']) {
            throw new RuntimeException(
                'El abono ($' . number_format($monto, 0, ',', '.') . ') supera '
                . 'la deuda actual ($' . number_format($cliente['saldo_fiado'], 0, ',', '.') . ').'
            );
        }

        $pdo->beginTransaction();
        try {
            // Registrar el pago en la tabla de abonos
            $pdo->prepare(
                'INSERT INTO pagos_fiado (cliente_id, monto, metodo_pago, created_by)
                 VALUES (?, ?, ?, ?)'
            )->execute([$cliente_id, $monto, $metodo_pago, $uid]);
            $pago_id = (int)$pdo->lastInsertId();

            $saldo_anterior = (float)$cliente['saldo_fiado'];
            $saldo_nuevo    = $saldo_anterior - $monto;

            // Reducir saldo del cliente
            $pdo->prepare(
                'UPDATE clientes SET saldo_fiado = saldo_fiado - ?, updated_by = ? WHERE id = ?'
            )->execute([$monto, $uid, $cliente_id]);

            $pdo->commit();

            log_registrar('clientes',    $cliente_id, 'saldo_fiado', (string)$saldo_anterior, (string)$saldo_nuevo, 'UPDATE');
            log_registrar('pagos_fiado', $pago_id,    'monto',       null,                    (string)$monto,       'INSERT');

        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Retorna el historial de ventas a fiado de un cliente específico.
     */
    public static function historial_fiado(int $cliente_id): array
    {
        $pdo = db();

        $ventas = $pdo->prepare(
            "SELECT v.id, v.fecha_venta, v.total, v.estado
             FROM ventas v
             WHERE v.cliente_id = ? AND v.metodo_pago = 'fiado'
             ORDER BY v.fecha_venta DESC
             LIMIT 50"
        );
        $ventas->execute([$cliente_id]);

        $abonos = $pdo->prepare(
            'SELECT pf.created_at, pf.monto, pf.metodo_pago
             FROM pagos_fiado pf
             WHERE pf.cliente_id = ?
             ORDER BY pf.created_at DESC
             LIMIT 50'
        );
        $abonos->execute([$cliente_id]);

        return [
            'ventas'  => $ventas->fetchAll(),
            'abonos'  => $abonos->fetchAll(),
        ];
    }
}
