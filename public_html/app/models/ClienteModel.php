<?php
/**
 * app/models/ClienteModel.php
 * Gestión de clientes: CRUD, fiado (crédito) y fusión de duplicados.
 *
 * Tabla clientes: id, nombre, apellido, empresa, telefono, saldo_fiado, activo
 * Tabla pagos_fiado: id, cliente_id, monto, metodo_pago, created_at
 *
 * FUSIÓN: al fusionar dos clientes se transfieren todas las ventas y abonos
 * del cliente secundario al principal, se suman los saldos y se desactiva
 * el secundario. Todo en una transacción PDO atómica.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/AuditoriaHelper.php';

class ClienteModel
{
    // ── Helpers privados ─────────────────────────────────────────────────────

    /**
     * Construye el nombre completo del cliente para mostrar en la UI.
     * Formato: "Nombre [Apellido]" — apellido es opcional.
     */
    public static function nombre_completo(array $cliente): string
    {
        $partes = [$cliente['nombre']];
        if (!empty($cliente['apellido'])) $partes[] = $cliente['apellido'];
        return implode(' ', $partes);
    }

    // ── Consultas ─────────────────────────────────────────────────────────────

    /**
     * Retorna todos los clientes activos para el dropdown del POS.
     * Solo incluye los campos necesarios para el selector (rendimiento).
     */
    public static function todos(): array
    {
        return db()->query(
            "SELECT id,
                    CONCAT(nombre, IF(apellido IS NOT NULL, CONCAT(' ', apellido), '')) AS nombre,
                    telefono, saldo_fiado
             FROM clientes WHERE activo = 1 ORDER BY nombre, apellido"
        )->fetchAll();
    }

    /**
     * Retorna todos los clientes con todos sus campos para el módulo de gestión.
     * Incluye conteo de ventas y última fecha de compra para cada cliente.
     */
    public static function todos_completo(): array
    {
        return db()->query(
            "SELECT c.id, c.nombre, c.apellido, c.empresa, c.telefono,
                    c.saldo_fiado, c.activo,
                    c.created_at,
                    COUNT(v.id)         AS total_ventas,
                    IFNULL(SUM(v.total), 0) AS total_comprado,
                    MAX(v.fecha_venta)  AS ultima_compra
             FROM clientes c
             LEFT JOIN ventas v ON v.cliente_id = c.id AND v.estado != 'anulada'
             GROUP BY c.id
             ORDER BY c.activo DESC, c.nombre, c.apellido"
        )->fetchAll();
    }

    /**
     * Retorna clientes con deuda pendiente, ordenados de mayor a menor saldo.
     * Incluye la fecha de la última compra a fiado para contexto de cobro.
     */
    public static function con_fiado(): array
    {
        return db()->query(
            "SELECT c.id,
                    CONCAT(c.nombre, IF(c.apellido IS NOT NULL, CONCAT(' ', c.apellido), '')) AS nombre,
                    c.telefono, c.empresa, c.saldo_fiado,
                    (SELECT MAX(v.fecha_venta)
                     FROM ventas v
                     WHERE v.cliente_id = c.id
                       AND v.metodo_pago = 'fiado'
                       AND v.estado      = 'completada') AS ultima_compra
             FROM clientes c
             WHERE c.activo = 1 AND c.saldo_fiado > 0
             ORDER BY c.saldo_fiado DESC"
        )->fetchAll();
    }

    // ── CRUD ─────────────────────────────────────────────────────────────────

    /**
     * Crea un nuevo cliente con los campos completos.
     *
     * @return int ID del cliente creado
     * @throws RuntimeException si el nombre está vacío
     */
    public static function crear(
        string  $nombre,
        ?string $apellido = null,
        ?string $empresa  = null,
        ?string $telefono = null
    ): int {
        $nombre = trim($nombre);
        if (empty($nombre)) throw new RuntimeException('El nombre del cliente es obligatorio.');

        $apellido = trim($apellido ?? '') ?: null;
        $empresa  = trim($empresa  ?? '') ?: null;
        $telefono = trim($telefono ?? '') ?: null;
        $uid      = (int)($_SESSION['usuario_id'] ?? 0);

        $stmt = db()->prepare(
            'INSERT INTO clientes (nombre, apellido, empresa, telefono, created_by)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$nombre, $apellido, $empresa, $telefono, $uid]);
        $id = (int)db()->lastInsertId();

        log_registrar('clientes', $id, 'nombre', null, $nombre, 'INSERT');
        return $id;
    }

    /**
     * Actualiza los datos de un cliente existente.
     *
     * @throws RuntimeException si el cliente no existe o el nombre está vacío
     */
    public static function editar(
        int     $id,
        string  $nombre,
        ?string $apellido = null,
        ?string $empresa  = null,
        ?string $telefono = null
    ): void {
        $nombre = trim($nombre);
        if (!$id)            throw new RuntimeException('ID de cliente inválido.');
        if (empty($nombre))  throw new RuntimeException('El nombre del cliente es obligatorio.');

        $apellido = trim($apellido ?? '') ?: null;
        $empresa  = trim($empresa  ?? '') ?: null;
        $telefono = trim($telefono ?? '') ?: null;
        $uid      = (int)($_SESSION['usuario_id'] ?? 0);

        // Guardar nombre anterior para auditoría
        $prev = db()->prepare('SELECT nombre FROM clientes WHERE id = ?');
        $prev->execute([$id]);
        $ant = $prev->fetchColumn();
        if ($ant === false) throw new RuntimeException('Cliente no encontrado.');

        db()->prepare(
            'UPDATE clientes
             SET nombre=?, apellido=?, empresa=?, telefono=?, updated_by=?
             WHERE id=?'
        )->execute([$nombre, $apellido, $empresa, $telefono, $uid, $id]);

        if ($ant !== $nombre) {
            log_registrar('clientes', $id, 'nombre', $ant, $nombre, 'UPDATE');
        }
    }

    /**
     * Activa o desactiva un cliente (soft-delete).
     * No se puede desactivar un cliente con saldo_fiado > 0 (deuda pendiente).
     *
     * @throws RuntimeException si el cliente tiene deuda pendiente al desactivar
     */
    public static function toggle(int $id): bool
    {
        $uid  = (int)($_SESSION['usuario_id'] ?? 0);

        $stmt = db()->prepare('SELECT activo, saldo_fiado FROM clientes WHERE id = ?');
        $stmt->execute([$id]);
        $c = $stmt->fetch();
        if (!$c) throw new RuntimeException('Cliente no encontrado.');

        // Impedir desactivar clientes con deuda pendiente
        if ((int)$c['activo'] === 1 && (float)$c['saldo_fiado'] > 0) {
            throw new RuntimeException(
                'No se puede desactivar un cliente con deuda pendiente '
                . '($' . fmt_moneda($c['saldo_fiado']) . ').'
            );
        }

        $nuevo_activo = (int)$c['activo'] === 1 ? 0 : 1;

        db()->prepare('UPDATE clientes SET activo=?, updated_by=? WHERE id=?')
            ->execute([$nuevo_activo, $uid, $id]);

        log_registrar('clientes', $id, 'activo', (string)$c['activo'], (string)$nuevo_activo, 'UPDATE');
        return (bool)$nuevo_activo;
    }

    // ── FUSIÓN DE CLIENTES ────────────────────────────────────────────────────

    /**
     * Fusiona dos clientes en uno: transfiere ventas, abonos y saldo del
     * cliente secundario al principal, luego desactiva el secundario.
     *
     * ATOMICIDAD: todo ocurre en una única transacción PDO. Si cualquier paso
     * falla (ventas, pagos_fiado, saldo, desactivación) se hace ROLLBACK completo.
     *
     * @param int $principal_id  Cliente que PERMANECE activo
     * @param int $secundario_id Cliente que se absorbe y desactiva
     * @throws RuntimeException si alguno no existe, es el mismo, o el secundario tiene FK críticos
     */
    public static function fusionar(int $principal_id, int $secundario_id): array
    {
        if ($principal_id === $secundario_id) {
            throw new RuntimeException('No se puede fusionar un cliente consigo mismo.');
        }

        $pdo = db();
        $uid = (int)($_SESSION['usuario_id'] ?? 0);

        // Verificar que ambos clientes existen y están activos
        $stmt = $pdo->prepare('SELECT id, nombre, apellido, saldo_fiado, activo FROM clientes WHERE id = ?');

        $stmt->execute([$principal_id]);
        $principal = $stmt->fetch();
        if (!$principal || !(int)$principal['activo']) {
            throw new RuntimeException('El cliente principal no existe o está inactivo.');
        }

        $stmt->execute([$secundario_id]);
        $secundario = $stmt->fetch();
        if (!$secundario || !(int)$secundario['activo']) {
            throw new RuntimeException('El cliente secundario no existe o está inactivo.');
        }

        // Contar lo que se va a transferir (para mostrar en el resultado)
        $n_ventas = (int)$pdo->prepare('SELECT COUNT(*) FROM ventas WHERE cliente_id = ?')
                              ->execute([$secundario_id]) ? $pdo->query("SELECT FOUND_ROWS()")->fetchColumn() : 0;
        $stmt_cv = $pdo->prepare('SELECT COUNT(*) FROM ventas WHERE cliente_id = ?');
        $stmt_cv->execute([$secundario_id]);
        $n_ventas = (int)$stmt_cv->fetchColumn();

        $stmt_cp = $pdo->prepare('SELECT COUNT(*) FROM pagos_fiado WHERE cliente_id = ?');
        $stmt_cp->execute([$secundario_id]);
        $n_pagos = (int)$stmt_cp->fetchColumn();

        $saldo_sec = (float)$secundario['saldo_fiado'];

        $pdo->beginTransaction();
        try {
            // 1. Reasignar todas las ventas del secundario al principal
            $pdo->prepare('UPDATE ventas SET cliente_id = ? WHERE cliente_id = ?')
                ->execute([$principal_id, $secundario_id]);

            // 2. Reasignar todos los abonos (pagos_fiado) al principal
            $pdo->prepare('UPDATE pagos_fiado SET cliente_id = ? WHERE cliente_id = ?')
                ->execute([$principal_id, $secundario_id]);

            // 3. Sumar el saldo del secundario al saldo del principal
            if ($saldo_sec > 0) {
                $pdo->prepare(
                    'UPDATE clientes SET saldo_fiado = saldo_fiado + ?, updated_by = ? WHERE id = ?'
                )->execute([$saldo_sec, $uid, $principal_id]);
            }

            // 4. Poner saldo del secundario a 0 antes de desactivarlo (integridad)
            $pdo->prepare(
                'UPDATE clientes SET saldo_fiado = 0, activo = 0, updated_by = ? WHERE id = ?'
            )->execute([$uid, $secundario_id]);

            $pdo->commit();

            // Registrar la fusión en auditoría como un evento único
            $nombre_sec  = self::nombre_completo($secundario);
            $nombre_prin = self::nombre_completo($principal);
            log_registrar('clientes', $principal_id, 'fusion',
                "absorbio_a={$secundario_id}:{$nombre_sec}",
                "ventas={$n_ventas},pagos={$n_pagos},saldo_transferido={$saldo_sec}",
                'UPDATE');

        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }

        return [
            'principal'   => $nombre_prin,
            'secundario'  => $nombre_sec,
            'ventas'      => $n_ventas,
            'pagos'       => $n_pagos,
            'saldo'       => $saldo_sec,
        ];
    }

    // ── Fiado ─────────────────────────────────────────────────────────────────

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

        $stmt = $pdo->prepare('SELECT saldo_fiado, nombre FROM clientes WHERE id = ? AND activo = 1');
        $stmt->execute([$cliente_id]);
        $cliente = $stmt->fetch();

        if (!$cliente) throw new RuntimeException('Cliente no encontrado.');
        if ($monto <= 0) throw new RuntimeException('El monto del abono debe ser mayor a $0.');
        if ($monto > (float)$cliente['saldo_fiado']) {
            throw new RuntimeException(
                'El abono ($' . fmt_moneda($monto) . ') supera '
                . 'la deuda actual ($' . fmt_moneda($cliente['saldo_fiado']) . ').'
            );
        }

        $saldo_anterior = (float)$cliente['saldo_fiado'];
        $saldo_nuevo    = $saldo_anterior - $monto;

        // Detectar migración 034 (saldo_anterior, saldo_posterior en pagos_fiado)
        static $tiene034p = null;
        if ($tiene034p === null) {
            try {
                $tiene034p = (int)$pdo->query(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pagos_fiado'
                       AND COLUMN_NAME='saldo_anterior'"
                )->fetchColumn() > 0;
            } catch (\Exception $e) { $tiene034p = false; }
        }

        $pdo->beginTransaction();
        try {
            if ($tiene034p) {
                // Guardar snapshot del saldo antes y después del abono (migración 034)
                $pdo->prepare(
                    'INSERT INTO pagos_fiado
                        (cliente_id, monto, metodo_pago, saldo_anterior, saldo_posterior, created_by)
                     VALUES (?, ?, ?, ?, ?, ?)'
                )->execute([$cliente_id, $monto, $metodo_pago, $saldo_anterior, $saldo_nuevo, $uid]);
            } else {
                $pdo->prepare(
                    'INSERT INTO pagos_fiado (cliente_id, monto, metodo_pago, created_by)
                     VALUES (?, ?, ?, ?)'
                )->execute([$cliente_id, $monto, $metodo_pago, $uid]);
            }
            $pago_id = (int)$pdo->lastInsertId();

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

}
