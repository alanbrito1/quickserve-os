<?php
/**
 * app/models/VentaModel.php
 * Lógica de negocio del módulo de ventas.
 *
 * ATOMICIDAD: crear() y anular() usan transacciones PDO.
 * Si cualquier paso falla (stock insuficiente, FK inválida, error de DB)
 * se hace ROLLBACK completo: la venta no queda a medias.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/AuditoriaHelper.php';
require_once __DIR__ . '/ContabilidadModel.php';

class VentaModel
{
    // -----------------------------------------------------------------------
    // CREAR VENTA
    // -----------------------------------------------------------------------

    /**
     * Registra una venta completa: cabecera + líneas + descuento de stock/insumos + fiado.
     *
     * @param array       $carrito       [['producto_id','cantidad','precio','es_combo',
     *                                     'variante_id','variante_etiqueta','factor_receta'], ...]
     *                                   es_combo: 0 = solo, 1 = combo (default 0)
     *                                   variante_id: INT|null — ID de producto_variantes
     *                                   variante_etiqueta: string|null — snapshot de la etiqueta
     *                                   factor_receta: float (default 1.0) — escala los insumos
     * @param string      $metodo_pago   efectivo|nequi|daviplata|bancolombia|fiado|obsequio
     * @param int|null    $cliente_id    Requerido si metodo_pago === 'fiado'
     * @param string      $notas
     * @param string|null $fecha_pago    NULL = pendiente cobro. ISO date si ya se pagó.
     * @param string      $tipo_sandwich  Etiqueta heredada (denormalizada, para legado)
     * @param string|null $fecha_venta    Fecha de la venta (YYYY-MM-DD HH:MM:SS); NULL = NOW()
     * @param float       $descuento_pct  Porcentaje de descuento 0-50; sólo si mig.038 aplicada
     * @return int  ID de la venta creada
     * @throws RuntimeException si stock insuficiente, cliente ausente en fiado, etc.
     */
    public static function crear(
        array   $carrito,
        string  $metodo_pago,
        ?int    $cliente_id,
        string  $notas          = '',
        ?string $fecha_pago     = null,
        string  $tipo_sandwich  = '',
        ?string $fecha_venta    = null,
        float   $descuento_pct  = 0.0
    ): int {
        if (empty($carrito)) {
            throw new RuntimeException('El carrito no puede estar vacío.');
        }
        if ($metodo_pago === 'fiado' && !$cliente_id) {
            throw new RuntimeException('Para ventas a fiado debes seleccionar un cliente.');
        }

        $pdo = db();
        $uid = (int)($_SESSION['usuario_id'] ?? 0);

        $total_bruto = array_reduce($carrito, static function (float $suma, array $item): float {
            return $suma + ((float)$item['precio'] * (int)$item['cantidad']);
        }, 0.0);

        // ventas.es_combo = 1 si al menos un ítem del carrito es combo
        $venta_es_combo = (int)!empty(array_filter($carrito, fn($i) => !empty($i['es_combo'])));

        // Fiado sin fecha_pago conocida → pendiente de cobro
        $estado = ($metodo_pago === 'fiado' && !$fecha_pago) ? 'pendiente_pago' : 'completada';

        $pdo->beginTransaction();
        try {
            // Detectar migración 038 (descuento_pct / descuento_valor en ventas)
            static $tiene038v = null;
            if ($tiene038v === null) {
                try {
                    $tiene038v = (int)$pdo->query(
                        "SELECT COUNT(*) FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ventas'
                           AND COLUMN_NAME='descuento_pct'"
                    )->fetchColumn() > 0;
                } catch (\Exception $e) { $tiene038v = false; }
            }
            $descuento_pct   = $tiene038v ? max(0.0, min(50.0, $descuento_pct)) : 0.0;
            $descuento_valor = round($total_bruto * $descuento_pct / 100, 2);
            $total           = round($total_bruto - $descuento_valor, 2);

            // ---- 1. Cabecera de venta ----
            $colDesc038  = $tiene038v ? ', descuento_pct, descuento_valor' : '';
            $valDesc038  = $tiene038v ? ', :dpct, :dval'                   : '';
            $execParams  = [
                ':cid'    => $cliente_id,
                ':metodo' => $metodo_pago,
                ':total'  => $total,
                ':notas'  => $notas ?: null,
                ':estado' => $estado,
                ':fpago'  => $fecha_pago,
                ':combo'  => $venta_es_combo,
                ':tipo'   => $tipo_sandwich ?: null,
                ':fventa' => $fecha_venta,
                ':uid'    => $uid,
            ];
            if ($tiene038v) {
                $execParams[':dpct'] = $descuento_pct;
                $execParams[':dval'] = $descuento_valor;
            }
            $pdo->prepare(
                "INSERT INTO ventas
                    (cliente_id, metodo_pago, total, notas, estado,
                     fecha_pago, es_combo, tipo_sandwich, fecha_venta, created_by{$colDesc038})
                 VALUES
                    (:cid, :metodo, :total, :notas, :estado,
                     :fpago, :combo, :tipo, COALESCE(:fventa, NOW()), :uid{$valDesc038})"
            )->execute($execParams);
            $venta_id = (int)$pdo->lastInsertId();

            // ---- 2. Líneas de detalle + descuento de stock o insumos ----
            // Detectar migración 034 (columnas nombre_snap / nombre2_snap en venta_detalles)
            static $tiene034v = null;
            if ($tiene034v === null) {
                try {
                    $tiene034v = (int)$pdo->query(
                        "SELECT COUNT(*) FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='venta_detalles'
                           AND COLUMN_NAME='nombre_snap'"
                    )->fetchColumn() > 0;
                } catch (\Exception $e) { $tiene034v = false; }
            }

            // Detectar migración 035 (columnas variante_id / factor_receta_snap en venta_detalles)
            static $tiene035v = null;
            if ($tiene035v === null) {
                try {
                    $tiene035v = (int)$pdo->query(
                        "SELECT COUNT(*) FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='venta_detalles'
                           AND COLUMN_NAME='variante_id'"
                    )->fetchColumn() > 0;
                } catch (\Exception $e) { $tiene035v = false; }
            }

            // Detectar migración 044 (costo_unit_snap — snapshot de COGS en venta_detalles)
            static $tiene044v = null;
            if ($tiene044v === null) {
                try {
                    $tiene044v = (int)$pdo->query(
                        "SELECT COUNT(*) FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='venta_detalles'
                           AND COLUMN_NAME='costo_unit_snap'"
                    )->fetchColumn() > 0;
                } catch (\Exception $e) { $tiene044v = false; }
            }

            // INSERT de línea de detalle: columnas adicionales según migraciones aplicadas
            $cols035 = $tiene035v ? ', variante_id, variante_etiqueta, factor_receta_snap' : '';
            $vals035 = $tiene035v ? ', :varid, :varetiq, :varfact' : '';
            $col044  = $tiene044v ? ', costo_unit_snap' : '';
            $val044  = $tiene044v ? ', :cusnap' : '';
            if ($tiene034v) {
                $stmtDetalle = $pdo->prepare(
                    "INSERT INTO venta_detalles
                        (venta_id, producto_id, cantidad, precio_unitario, subtotal,
                         from_stock, es_combo, combo_id,
                         nombre_snap, nombre2_snap{$cols035}{$col044},
                         created_by)
                     VALUES (:vid, :pid, :cant, :precio, :sub, :fs, :ec, :cid,
                             :nsnap, :n2snap{$vals035}{$val044}, :uid)"
                );
            } else {
                $stmtDetalle = $pdo->prepare(
                    "INSERT INTO venta_detalles
                        (venta_id, producto_id, cantidad, precio_unitario, subtotal,
                         from_stock, es_combo, combo_id{$cols035}{$col044}, created_by)
                     VALUES (:vid, :pid, :cant, :precio, :sub, :fs, :ec, :cid{$vals035}{$val044}, :uid)"
                );
            }

            // Costo de los insumos extra del combo por unidad (para el snapshot de COGS)
            $stmtComboCost = $pdo->prepare(
                'SELECT COALESCE(SUM(ci.cantidad * i.costo_actual), 0)
                 FROM combo_insumos ci JOIN insumos i ON i.id = ci.insumo_id
                 WHERE ci.combo_id = ?'
            );

            // FOR UPDATE: bloquea la fila durante la transacción para prevenir race conditions
            // (dos ventas simultáneas no pueden ambas ver stock > 0 y descontarlo dos veces)
            // También trae nombre y nombre2 para el snapshot (migración 034)
            $stmtProdInfo = $pdo->prepare(
                'SELECT stock_disponible, unidades_por_receta, nombre, nombre2, costo_calculado
                 FROM productos WHERE id = ? FOR UPDATE'
            );

            // Descuento del stock de producto terminado (si hay suficiente)
            $stmtStockProd = $pdo->prepare(
                'UPDATE productos
                 SET stock_disponible = stock_disponible - ?, updated_by = ?
                 WHERE id = ? AND stock_disponible >= ?'
            );

            // Detectar migración 036 (es_base en recetas)
            static $tiene036v = null;
            if ($tiene036v === null) {
                try {
                    $tiene036v = (int)$pdo->query(
                        "SELECT COUNT(*) FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='recetas'
                           AND COLUMN_NAME='es_base'"
                    )->fetchColumn() > 0;
                } catch (\Exception $e) { $tiene036v = false; }
            }
            $colEsBaseV = $tiene036v ? ', es_base' : ', 0 AS es_base';

            $stmtReceta = $pdo->prepare(
                "SELECT insumo_id, cantidad_requerida{$colEsBaseV}
                 FROM recetas WHERE producto_id = :pid"
            );

            // Reutilizado tanto para descontar insumos del sándwich como del combo
            $stmtDescuento = $pdo->prepare(
                'UPDATE insumos
                 SET stock_actual = stock_actual - :desc, updated_by = :uid
                 WHERE id = :id AND stock_actual >= :desc2'
            );

            // Insumos del combo: buscados una sola vez por combo_id para no hacer N queries extra
            $stmtComboIns = $pdo->prepare(
                'SELECT ci.insumo_id, ci.cantidad, i.nombre AS insumo_nombre
                 FROM combo_insumos ci
                 JOIN insumos i ON i.id = ci.insumo_id
                 WHERE ci.combo_id = ?'
            );

            // Obtener combo_id activo de un producto (cacheado por pid para el mismo carrito)
            $comboConfigCache = [];
            $stmtComboId = $pdo->prepare(
                'SELECT id FROM combo_configs WHERE producto_id = ? AND activo = 1 LIMIT 1'
            );

            foreach ($carrito as $item) {
                $pid          = (int)$item['producto_id'];
                $cantidad     = (int)$item['cantidad'];
                $precio       = (float)$item['precio'];
                $esCombo      = !empty($item['es_combo']) ? 1 : 0;
                // Variante (migración 035) — null si no aplica o no está aplicada la migración
                $varianteId   = isset($item['variante_id'])   ? (int)$item['variante_id']   : null;
                $varianteEtiq = isset($item['variante_etiqueta']) ? (string)$item['variante_etiqueta'] : null;
                // factor_receta: escala los insumos descontados en modo demanda
                $factorReceta = max(0.001, (float)($item['factor_receta'] ?? 1.0));

                // ── Lock y lectura del stock del sándwich ──────────────────────
                $stmtProdInfo->execute([$pid]);
                $prodInfo = $stmtProdInfo->fetch();
                if (!$prodInfo) {
                    throw new RuntimeException("Producto #{$pid} no encontrado o inactivo.");
                }
                $stockDisp = (int)($prodInfo['stock_disponible']    ?? 0);
                $rinde     = max(1, (int)($prodInfo['unidades_por_receta'] ?? 1));
                // Snapshot del nombre del producto al momento de la venta (migración 034)
                $nombreSnap  = $prodInfo['nombre']  ?? null;
                $nombre2Snap = $prodInfo['nombre2'] ?? null;

                // Lógica de fuente: stock terminado primero, luego modo demanda con insumos
                $fromStock = ($stockDisp >= $cantidad) ? 1 : 0;

                // Resolver combo_id si este ítem es combo (usar caché para evitar N queries)
                $comboId = null;
                if ($esCombo) {
                    if (!array_key_exists($pid, $comboConfigCache)) {
                        $stmtComboId->execute([$pid]);
                        $comboConfigCache[$pid] = $stmtComboId->fetchColumn() ?: null;
                    }
                    $comboId = $comboConfigCache[$pid];
                }

                // ── Snapshot de COSTO por unidad (COGS inmutable, migración 044) ─
                // = costo de receta del producto (escalado por la variante) + costo
                //   de los insumos extra del combo. Es el costo real al momento de
                //   vender; los reportes de P&G/margen lo usan sin recalcular.
                // Nota: costo_calculado es por unidad a factor 1; multiplicar por
                //   factor_receta sobre-escala levemente los ingredientes "base",
                //   igual que el cálculo de margen existente (aproximación aceptada).
                $costoSnap = null;
                if ($tiene044v) {
                    $costoRecetaU = (float)($prodInfo['costo_calculado'] ?? 0) * $factorReceta;
                    $costoComboU  = 0.0;
                    if ($esCombo && $comboId) {
                        $stmtComboCost->execute([$comboId]);
                        $costoComboU = (float)$stmtComboCost->fetchColumn();
                    }
                    $costoSnap = round($costoRecetaU + $costoComboU, 4);
                }

                // ── INSERT línea de detalle ────────────────────────────────────
                $detalleParams = [
                    ':vid'    => $venta_id,
                    ':pid'    => $pid,
                    ':cant'   => $cantidad,
                    ':precio' => $precio,
                    ':sub'    => $precio * $cantidad,
                    ':fs'     => $fromStock,
                    ':ec'     => $esCombo,
                    ':cid'    => $comboId,
                    ':uid'    => $uid,
                ];
                if ($tiene034v) {
                    $detalleParams[':nsnap']  = $nombreSnap;
                    $detalleParams[':n2snap'] = $nombre2Snap;
                }
                if ($tiene035v) {
                    $detalleParams[':varid']   = $varianteId ?: null;
                    $detalleParams[':varetiq'] = $varianteEtiq ?: null;
                    // Solo guardamos el factor como snapshot si hay variante; sin variante = 1.0 (implícito)
                    $detalleParams[':varfact'] = $varianteId ? round($factorReceta, 3) : null;
                }
                if ($tiene044v) {
                    $detalleParams[':cusnap'] = $costoSnap;
                }
                $stmtDetalle->execute($detalleParams);

                // ── Descontar el sándwich ──────────────────────────────────────
                if ($fromStock) {
                    // Los insumos ya fueron descontados al registrar la producción.
                    $stmtStockProd->execute([$cantidad, $uid, $pid, $cantidad]);
                    if ($stmtStockProd->rowCount() === 0) {
                        throw new RuntimeException('Stock insuficiente de producto terminado.');
                    }
                } else {
                    // Producción a demanda: descontar insumos directamente.
                    // cantidad_requerida está definida para toda la tanda; dividimos por rinde.
                    // factor_receta escala las cantidades si es una variante de diferente tamaño.
                    $stmtReceta->execute([':pid' => $pid]);
                    foreach ($stmtReceta->fetchAll() as $ing) {
                        // es_base=1: ingrediente fijo (pan, salsa) — no escala con factor de variante
                        $factor    = !empty($ing['es_base']) ? 1.0 : $factorReceta;
                        $descuento = ((float)$ing['cantidad_requerida'] / $rinde) * $cantidad * $factor;

                        $stmtDescuento->execute([
                            ':desc'  => $descuento,
                            ':uid'   => $uid,
                            ':id'    => (int)$ing['insumo_id'],
                            ':desc2' => $descuento,
                        ]);

                        if ($stmtDescuento->rowCount() === 0) {
                            $nom = $pdo->prepare('SELECT nombre FROM insumos WHERE id = ?');
                            $nom->execute([(int)$ing['insumo_id']]);
                            $nombre_ins = $nom->fetchColumn() ?: 'insumo #' . $ing['insumo_id'];
                            throw new RuntimeException(
                                "Stock insuficiente de \"{$nombre_ins}\". Actualiza el inventario."
                            );
                        }
                    }
                }

                // ── Descontar los insumos extra del combo ──────────────────────
                // Solo se ejecuta si el ítem es combo Y se encontró la config en BD.
                // Si comboId es NULL (config fue eliminada o es dato histórico), se omite sin error.
                if ($esCombo && $comboId) {
                    $stmtComboIns->execute([$comboId]);
                    foreach ($stmtComboIns->fetchAll() as $extra) {
                        // Descuento proporcional: cantidad del combo × unidades vendidas
                        $descExtra = (float)$extra['cantidad'] * $cantidad;

                        $stmtDescuento->execute([
                            ':desc'  => $descExtra,
                            ':uid'   => $uid,
                            ':id'    => (int)$extra['insumo_id'],
                            ':desc2' => $descExtra,
                        ]);

                        if ($stmtDescuento->rowCount() === 0) {
                            throw new RuntimeException(
                                "Stock insuficiente de \"{$extra['insumo_nombre']}\" para el combo."
                                . " Actualiza el inventario."
                            );
                        }
                    }
                }
            }

            // ---- 3. Fiado: acumular deuda en el saldo del cliente ----
            if ($metodo_pago === 'fiado' && $cliente_id) {
                $pdo->prepare(
                    'UPDATE clientes
                     SET saldo_fiado = saldo_fiado + :total, updated_by = :uid
                     WHERE id = :id'
                )->execute([':total' => $total, ':uid' => $uid, ':id' => $cliente_id]);
            }

            $pdo->commit();

            // Auditoría de la creación (el trigger MySQL no cubre INSERTs)
            log_registrar('ventas', $venta_id, 'estado', null, $estado, 'INSERT');

            // Contabilidad (Fase 4b): postear el asiento DESPUÉS del commit y aislado —
            // un fallo contable nunca debe romper la venta (el backfill lo reconcilia).
            try { ContabilidadModel::postear_venta($venta_id); }
            catch (\Throwable $e) { error_log('[QuickServe OS contab venta] ' . $e->getMessage()); }

            return $venta_id;

        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e; // propagar para que el controlador devuelva el error al cliente
        }
    }

    // -----------------------------------------------------------------------
    // ANULAR VENTA (requiere permiso admin_total en módulo ventas)
    // -----------------------------------------------------------------------

    /**
     * Anula una venta: cambia estado a 'anulada' y revierte el stock de insumos.
     * Si era fiado, también reduce el saldo del cliente.
     *
     * @throws RuntimeException si la venta no existe o ya está anulada
     */
    public static function anular(int $venta_id): void
    {
        $pdo = db();
        $uid = (int)($_SESSION['usuario_id'] ?? 0);

        // Verificar existencia y estado actual
        $v = $pdo->prepare(
            'SELECT id, estado, metodo_pago, total, cliente_id FROM ventas WHERE id = ?'
        );
        $v->execute([$venta_id]);
        $venta = $v->fetch();

        if (!$venta)                        throw new RuntimeException('Venta no encontrada.');
        if ($venta['estado'] === 'anulada') throw new RuntimeException('Esta venta ya fue anulada.');

        $pdo->beginTransaction();
        try {
            // Cambiar estado
            $pdo->prepare("UPDATE ventas SET estado = 'anulada', updated_by = ? WHERE id = ?")
                ->execute([$uid, $venta_id]);

            // Revertir stock según la fuente original (from_stock=1 → producto terminado; 0 → insumos)

            // Líneas que usaron stock de producto terminado → restaurar stock_disponible
            $stockLines = $pdo->prepare(
                'SELECT producto_id, SUM(cantidad) AS total
                 FROM venta_detalles WHERE venta_id = ? AND from_stock = 1
                 GROUP BY producto_id'
            );
            $stockLines->execute([$venta_id]);
            foreach ($stockLines->fetchAll() as $row) {
                $pdo->prepare(
                    'UPDATE productos SET stock_disponible = stock_disponible + ?, updated_by = ? WHERE id = ?'
                )->execute([(int)$row['total'], $uid, (int)$row['producto_id']]);
            }

            // Líneas que descontaron insumos directamente → restaurar cada insumo del sándwich.
            // Si hay variante (migración 035), incluir factor_receta para restaurar la cantidad
            // correcta escalada. COALESCE garantiza factor 1.0 para ventas anteriores a migración.
            // Migración 036: es_base=1 → el factor siempre fue 1.0 para ese ingrediente.
            static $tiene036a = null;
            if ($tiene036a === null) {
                try {
                    $tiene036a = (int)$pdo->query(
                        "SELECT COUNT(*) FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='recetas'
                           AND COLUMN_NAME='es_base'"
                    )->fetchColumn() > 0;
                } catch (\Exception $e) { $tiene036a = false; }
            }
            $colEsBaseA = $tiene036a ? ', r.es_base' : ', 0 AS es_base';

            $ingLines = $pdo->prepare(
                "SELECT vd.cantidad, r.insumo_id, r.cantidad_requerida,
                        GREATEST(p.unidades_por_receta, 1) AS rinde,
                        COALESCE(vd.factor_receta_snap, 1.0) AS factor_receta{$colEsBaseA}
                 FROM venta_detalles vd
                 JOIN recetas  r ON r.producto_id = vd.producto_id
                 JOIN productos p ON p.id = vd.producto_id
                 WHERE vd.venta_id = ? AND vd.from_stock = 0"
            );
            $ingLines->execute([$venta_id]);
            foreach ($ingLines->fetchAll() as $row) {
                $factor     = !empty($row['es_base']) ? 1.0 : (float)$row['factor_receta'];
                $devolucion = ((float)$row['cantidad_requerida'] / (int)$row['rinde'])
                              * (int)$row['cantidad']
                              * $factor;
                $pdo->prepare(
                    'UPDATE insumos SET stock_actual = stock_actual + ?, updated_by = ? WHERE id = ?'
                )->execute([$devolucion, $uid, (int)$row['insumo_id']]);
            }

            // Restaurar los insumos extra del combo para líneas que fueron vendidas como combo.
            // Solo se restaura si combo_id es NOT NULL: si es NULL (datos históricos sin config),
            // el descuento de combo nunca se realizó en BD → no hay nada que revertir.
            $comboLines = $pdo->prepare(
                'SELECT vd.cantidad, vd.combo_id
                 FROM venta_detalles vd
                 WHERE vd.venta_id = ? AND vd.es_combo = 1 AND vd.combo_id IS NOT NULL'
            );
            $comboLines->execute([$venta_id]);
            foreach ($comboLines->fetchAll() as $cl) {
                // Obtener los insumos del combo tal como estaban configurados al momento de la venta
                $extrasStmt = $pdo->prepare(
                    'SELECT ci.insumo_id, ci.cantidad
                     FROM combo_insumos ci
                     WHERE ci.combo_id = ?'
                );
                $extrasStmt->execute([(int)$cl['combo_id']]);
                foreach ($extrasStmt->fetchAll() as $extra) {
                    $devolucionExtra = (float)$extra['cantidad'] * (int)$cl['cantidad'];
                    $pdo->prepare(
                        'UPDATE insumos SET stock_actual = stock_actual + ?, updated_by = ? WHERE id = ?'
                    )->execute([$devolucionExtra, $uid, (int)$extra['insumo_id']]);
                }
            }

            // Si era fiado, reducir saldo del cliente (GREATEST(0,...) evita saldo negativo)
            if ($venta['metodo_pago'] === 'fiado' && $venta['cliente_id']) {
                $pdo->prepare(
                    'UPDATE clientes SET saldo_fiado = GREATEST(0, saldo_fiado - ?), updated_by = ? WHERE id = ?'
                )->execute([(float)$venta['total'], $uid, (int)$venta['cliente_id']]);
            }

            $pdo->commit();
            // Usar el estado real previo (puede ser 'completada' o 'pendiente_pago')
            log_registrar('ventas', $venta_id, 'estado', $venta['estado'], 'anulada', 'UPDATE');

            // Contabilidad (Fase 4b): reversar el asiento de la venta (aislado del flujo).
            try { ContabilidadModel::reversar_por_origen('venta', $venta_id); }
            catch (\Throwable $e) { error_log('[QuickServe OS contab anular] ' . $e->getMessage()); }

        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // -----------------------------------------------------------------------
    // CONSULTAS
    // -----------------------------------------------------------------------

    /**
     * Retorna el historial de ventas filtrado por rango de fechas y opcionalmente por usuario.
     * El parámetro $solo_usuario_id activa el filtro de "solo_propios".
     */
    public static function historial(
        string $fecha_desde = '',
        string $fecha_hasta = '',
        ?int   $solo_usuario_id = null
    ): array {
        $desde = $fecha_desde ?: date('Y-m-d');
        $hasta = $fecha_hasta ?: date('Y-m-d');

        $sql = "SELECT v.id, v.fecha_venta, v.metodo_pago, v.total, v.estado, v.notas,
                       IFNULL(c.nombre, 'Mostrador') AS cliente,
                       u.nombre AS cajero,
                       COUNT(vd.id) AS num_items
                FROM ventas v
                LEFT JOIN clientes c  ON c.id  = v.cliente_id
                LEFT JOIN usuarios u  ON u.id  = v.created_by
                LEFT JOIN venta_detalles vd ON vd.venta_id = v.id
                WHERE DATE(v.fecha_venta) BETWEEN :desde AND :hasta";

        $params = [':desde' => $desde, ':hasta' => $hasta];

        if ($solo_usuario_id) {
            $sql .= ' AND v.created_by = :uid';
            $params[':uid'] = $solo_usuario_id;
        }

        $sql .= ' GROUP BY v.id ORDER BY v.fecha_venta DESC';

        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Retorna cabecera + líneas de una venta específica.
     */
    public static function detalle(int $venta_id): array
    {
        $pdo = db();

        $cab = $pdo->prepare(
            "SELECT v.*, IFNULL(c.nombre, 'Mostrador') AS cliente_nombre, u.nombre AS cajero
             FROM ventas v
             LEFT JOIN clientes c ON c.id = v.cliente_id
             LEFT JOIN usuarios u ON u.id = v.created_by
             WHERE v.id = ?"
        );
        $cab->execute([$venta_id]);
        $venta = $cab->fetch();
        if (!$venta) return [];

        // nombre2 incluido para mostrar el subtítulo complementario en el detalle
        $lin = $pdo->prepare(
            'SELECT vd.cantidad, vd.precio_unitario, vd.subtotal,
                    vd.es_combo, vd.combo_id,
                    p.nombre AS producto, p.nombre2 AS producto_nombre2, p.tamano
             FROM venta_detalles vd
             JOIN productos p ON p.id = vd.producto_id
             WHERE vd.venta_id = ?'
        );
        $lin->execute([$venta_id]);
        $venta['lineas'] = $lin->fetchAll();

        return $venta;
    }

    /**
     * Retorna productos activos con su capacidad real de venta.
     *
     * Prioridad:
     *   1. Si hay stock de producto terminado (stock_disponible > 0) → esa es la capacidad.
     *   2. Si no hay stock terminado → capacidad según insumo crítico (modo demanda).
     *
     * Con unidades_por_receta: la cantidad_requerida es por tanda, no por unidad.
     * Capacidad_insumos = FLOOR(stock_insumo / (cantidad_requerida / unidades_por_receta))
     *                   = FLOOR(stock_insumo × unidades_por_receta / cantidad_requerida)
     */
    public static function productos_con_capacidad(): array
    {
        return db()->query(
            "SELECT p.id, p.nombre, p.nombre2, p.tamano, p.categoria, p.precio_venta,
                    IFNULL(p.stock_disponible, 0) AS stock_disponible,
                    -- Capacidad vía insumo crítico (ajustada por unidades_por_receta)
                    CASE
                        WHEN r.id IS NULL THEN 9999
                        ELSE IFNULL(
                            FLOOR(i.stock_actual * GREATEST(p.unidades_por_receta, 1)
                                  / NULLIF(r.cantidad_requerida, 0)),
                            0
                        )
                    END AS cap_insumos,
                    -- Capacidad efectiva: stock terminado tiene prioridad sobre insumos
                    CASE
                        WHEN IFNULL(p.stock_disponible, 0) > 0
                            THEN p.stock_disponible
                        WHEN r.id IS NULL THEN 9999
                        ELSE IFNULL(
                            FLOOR(i.stock_actual * GREATEST(p.unidades_por_receta, 1)
                                  / NULLIF(r.cantidad_requerida, 0)),
                            0
                        )
                    END AS capacidad
             FROM productos p
             LEFT JOIN recetas r ON r.producto_id = p.id AND r.es_insumo_critico = 1
             LEFT JOIN insumos  i ON i.id = r.insumo_id
             WHERE p.activo = 1 AND p.precio_venta > 0
             ORDER BY p.categoria, p.nombre, FIELD(p.tamano, 'XL', 'L', 'unico')"
        )->fetchAll();
    }

    /**
     * Resumen de ventas de hoy para el widget del dashboard.
     */
    public static function resumen_hoy(): array
    {
        // obsequio se cuenta por separado y NO suma al total_pesos (no es ingreso).
        $row = db()->query(
            "SELECT COUNT(*) AS total_ventas,
                    IFNULL(SUM(CASE WHEN metodo_pago != 'obsequio' THEN total ELSE 0 END), 0) AS total_pesos,
                    IFNULL(SUM(CASE WHEN metodo_pago = 'efectivo'    THEN total ELSE 0 END), 0) AS efectivo,
                    IFNULL(SUM(CASE WHEN metodo_pago = 'nequi'       THEN total ELSE 0 END), 0) AS nequi,
                    IFNULL(SUM(CASE WHEN metodo_pago = 'daviplata'   THEN total ELSE 0 END), 0) AS daviplata,
                    IFNULL(SUM(CASE WHEN metodo_pago = 'bancolombia' THEN total ELSE 0 END), 0) AS bancolombia,
                    IFNULL(SUM(CASE WHEN metodo_pago = 'fiado'       THEN total ELSE 0 END), 0) AS fiado,
                    IFNULL(SUM(CASE WHEN metodo_pago = 'obsequio'    THEN total ELSE 0 END), 0) AS obsequio,
                    COUNT(CASE WHEN metodo_pago = 'obsequio' THEN 1 END)                         AS obsequio_n
             FROM ventas
             WHERE DATE(fecha_venta) = CURDATE()
               AND estado IN ('completada', 'pendiente_pago')"
        )->fetch();
        return $row;
    }
}
