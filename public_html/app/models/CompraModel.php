<?php
/**
 * app/models/CompraModel.php
 * Registro de compras de insumos.
 *
 * CADENA DE ACTUALIZACIÓN (dentro de la misma transacción):
 *   compra_detalles.INSERT
 *     → insumos.costo_actual  = precio_unitario_compra
 *     → insumos.stock_actual += cantidad
 *     → productos.costo_calculado = SUM(ingrediente.costo × receta.cantidad)
 *
 * Si cualquier paso falla → ROLLBACK completo.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/AuditoriaHelper.php';

class CompraModel
{
    /**
     * Registra una compra completa y propaga los cambios de precio a los productos.
     *
     * @param array    $lineas        [['insumo_id','cantidad','precio_unitario',
     *                                  'presentacion?','cantidad_presentacion?',
     *                                  'cant_presentaciones?','precio_presentacion?'], ...]
     *   - cantidad + precio_unitario son SIEMPRE requeridos (en unidades básicas).
     *   - Los campos de presentación son opcionales (snapshot de cómo se compró).
     *     Permiten saber "compré 2 pacas de 12 ud a $29.000/paca" además de
     *     "compré 24 unidades a $2.416/u".
     * @param int|null $proveedor_id
     * @param string   $notas
     * @param string   $lugar_compra  Tienda, plaza o proveedor (texto libre)
     * @return int     ID de la compra creada
     * @throws RuntimeException si datos inválidos
     */
    public static function crear(
        array   $lineas,
        ?int    $proveedor_id  = null,
        string  $notas         = '',
        string  $lugar_compra  = ''
    ): int {
        if (empty($lineas)) throw new RuntimeException('La compra debe tener al menos un ítem.');

        $pdo = db();
        $uid = (int)($_SESSION['usuario_id'] ?? 0);

        $total = array_reduce($lineas, static function (float $s, array $l): float {
            return $s + ((float)$l['precio_unitario'] * (float)$l['cantidad']);
        }, 0.0);

        $pdo->beginTransaction();
        try {
            // ── 1. Cabecera de compra ────────────────────────────────────────
            $pdo->prepare(
                'INSERT INTO compras (proveedor_id, lugar_compra, total, notas, created_by)
                 VALUES (:pid, :lugar, :total, :notas, :uid)'
            )->execute([
                ':pid'   => $proveedor_id,
                ':lugar' => $lugar_compra ?: null,
                ':total' => $total,
                ':notas' => $notas ?: null,
                ':uid'   => $uid,
            ]);
            $compra_id = (int)$pdo->lastInsertId();

            // ── 2. Líneas: insertar + actualizar insumo ─────────────────────
            // Detectar si migración 032 está aplicada (columnas de presentación)
            static $tiene032 = null;
            if ($tiene032 === null) {
                try {
                    $c032 = db()->query(
                        "SELECT COUNT(*) FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='compra_detalles'
                           AND COLUMN_NAME='precio_presentacion'"
                    )->fetchColumn();
                    $tiene032 = (int)$c032 > 0;
                } catch (\Exception $e) { $tiene032 = false; }
            }

            // Detectar migración 034 (nombre_snap, unidad_snap en compra_detalles)
            static $tiene034c = null;
            if ($tiene034c === null) {
                try {
                    $tiene034c = (int)$pdo->query(
                        "SELECT COUNT(*) FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='compra_detalles'
                           AND COLUMN_NAME='nombre_snap'"
                    )->fetchColumn() > 0;
                } catch (\Exception $e2) { $tiene034c = false; }
            }

            if ($tiene032 && $tiene034c) {
                $stmtDetalle = $pdo->prepare(
                    'INSERT INTO compra_detalles
                        (compra_id, insumo_id, cantidad, precio_unitario, subtotal,
                         presentacion, cantidad_presentacion, cant_presentaciones, precio_presentacion,
                         nombre_snap, unidad_snap,
                         created_by)
                     VALUES (:cid, :iid, :cant, :precio, :sub,
                             :pres, :cantpx, :numpres, :ppres,
                             :nsnap, :usnap, :uid)'
                );
            } elseif ($tiene032) {
                $stmtDetalle = $pdo->prepare(
                    'INSERT INTO compra_detalles
                        (compra_id, insumo_id, cantidad, precio_unitario, subtotal,
                         presentacion, cantidad_presentacion, cant_presentaciones, precio_presentacion,
                         created_by)
                     VALUES (:cid, :iid, :cant, :precio, :sub,
                             :pres, :cantpx, :numpres, :ppres, :uid)'
                );
            } elseif ($tiene034c) {
                $stmtDetalle = $pdo->prepare(
                    'INSERT INTO compra_detalles
                        (compra_id, insumo_id, cantidad, precio_unitario, subtotal,
                         nombre_snap, unidad_snap, created_by)
                     VALUES (:cid, :iid, :cant, :precio, :sub, :nsnap, :usnap, :uid)'
                );
            } else {
                $stmtDetalle = $pdo->prepare(
                    'INSERT INTO compra_detalles
                        (compra_id, insumo_id, cantidad, precio_unitario, subtotal, created_by)
                     VALUES (:cid, :iid, :cant, :precio, :sub, :uid)'
                );
            }

            // Precarga los nombres de insumos una vez para todo el loop
            $stmtInsNombre = $pdo->prepare('SELECT nombre, unidad_medida FROM insumos WHERE id = ?');

            // Recálculo de costo de productos afectados por el insumo
            $stmtRecalc = $pdo->prepare(
                "UPDATE productos p
                 SET p.costo_calculado = (
                     SELECT IFNULL(SUM(ins.costo_actual * rec.cantidad_requerida), 0)
                     FROM recetas rec
                     JOIN insumos ins ON ins.id = rec.insumo_id
                     WHERE rec.producto_id = p.id
                 ),
                 p.updated_by = :uid
                 WHERE p.activo = 1
                   AND p.id IN (
                       SELECT DISTINCT r2.producto_id FROM recetas r2 WHERE r2.insumo_id = :iid
                   )"
            );

            $insumosActualizados = [];

            foreach ($lineas as $l) {
                $iid    = (int)$l['insumo_id'];
                $cant   = (float)$l['cantidad'];
                $precio = (float)$l['precio_unitario'];

                // Campos de presentación (opcionales — snapshot de cómo se compró)
                $pres     = $l['presentacion']          ?? null;
                $cantpx   = isset($l['cantidad_presentacion']) && $l['cantidad_presentacion'] > 0
                            ? (float)$l['cantidad_presentacion'] : null;
                $numpres  = isset($l['cant_presentaciones']) && $l['cant_presentaciones'] > 0
                            ? (float)$l['cant_presentaciones'] : null;
                $ppres    = isset($l['precio_presentacion']) && $l['precio_presentacion'] > 0
                            ? (float)$l['precio_presentacion'] : null;

                // Fetch nombre e unidad del insumo para snapshot
                $stmtInsNombre->execute([$iid]);
                $insRow = $stmtInsNombre->fetch();
                $nsnap  = $insRow['nombre']       ?? null;
                $usnap  = $insRow['unidad_medida'] ?? null;

                $params = [
                    ':cid'    => $compra_id,
                    ':iid'    => $iid,
                    ':cant'   => $cant,
                    ':precio' => $precio,
                    ':sub'    => $cant * $precio,
                    ':uid'    => $uid,
                ];
                if ($tiene032) {
                    $params[':pres']    = $pres;
                    $params[':cantpx']  = $cantpx;
                    $params[':numpres'] = $numpres;
                    $params[':ppres']   = $ppres;
                }
                if ($tiene034c) {
                    $params[':nsnap'] = $nsnap;
                    $params[':usnap'] = $usnap;
                }
                $stmtDetalle->execute($params);

                // Costo anterior para auditoría
                $prev = $pdo->prepare('SELECT costo_actual FROM insumos WHERE id = ?');
                $prev->execute([$iid]);
                $costo_anterior = (float)($prev->fetchColumn() ?: 0);

                // El precio de esta compra pasa a ser el nuevo costo_actual del insumo
                $pdo->prepare(
                    'UPDATE insumos
                     SET costo_actual = :precio,
                         stock_actual = stock_actual + :cant,
                         updated_by   = :uid
                     WHERE id = :id'
                )->execute([':precio' => $precio, ':cant' => $cant, ':uid' => $uid, ':id' => $iid]);

                if (abs($costo_anterior - $precio) > 0.001) {
                    log_registrar('insumos', $iid, 'costo_actual',
                        (string)$costo_anterior, (string)$precio, 'UPDATE');
                }

                $insumosActualizados[$iid] = true;
            }

            // ── 3. Recalcular costo_calculado en productos afectados ─────────
            foreach (array_keys($insumosActualizados) as $iid) {
                $stmtRecalc->execute([':uid' => $uid, ':iid' => (int)$iid]);
            }

            $pdo->commit();
            log_registrar('compras', $compra_id, 'total', null, (string)$total, 'INSERT');

            return $compra_id;

        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Retorna el historial de compras recientes con conteo de líneas.
     * NOTA: bindValue con PDO::PARAM_INT es obligatorio para LIMIT en modo non-emulated.
     */
    public static function recientes(int $limite = 30): array
    {
        $stmt = db()->prepare(
            "SELECT c.id, c.fecha_compra, c.total, c.notas,
                    IFNULL(c.lugar_compra, '') AS lugar_compra,
                    IFNULL(p.nombre, 'Sin proveedor') AS proveedor,
                    u.nombre AS registrado_por,
                    (SELECT COUNT(*) FROM compra_detalles WHERE compra_id = c.id) AS num_lineas
             FROM compras c
             LEFT JOIN proveedores p ON p.id = c.proveedor_id
             LEFT JOIN usuarios u    ON u.id = c.created_by
             ORDER BY c.fecha_compra DESC
             LIMIT :lim"
        );
        $stmt->bindValue(':lim', (int)$limite, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Compras agrupadas por fecha y lugar, con líneas anidadas.
     * Acepta filtros de rango de fechas, lugar de compra, ítem e insumo categoría.
     *
     * @param string $desde     YYYY-MM-DD  (default: hace 30 días)
     * @param string $hasta     YYYY-MM-DD  (default: hoy)
     * @param string $lugar     Texto libre — búsqueda parcial en lugar_compra
     * @param string $item      Texto libre — búsqueda parcial en nombre del insumo
     * @param string $categoria Exacto — categoría del insumo (proteína, lácteo, …)
     * @param string $orden     'fecha'|'lugar'|'total'
     */
    public static function historial_agrupado(
        string $desde     = '',
        string $hasta     = '',
        string $lugar     = '',
        string $item      = '',
        string $categoria = '',
        string $orden     = 'fecha'
    ): array {
        $desde = $desde ?: date('Y-m-d', strtotime('-30 days'));
        $hasta = $hasta ?: date('Y-m-d');

        $params = [':desde' => $desde, ':hasta' => $hasta];
        $where  = ['DATE(c.fecha_compra) BETWEEN :desde AND :hasta'];

        if ($lugar !== '') {
            $where[]          = "c.lugar_compra LIKE :lugar";
            $params[':lugar'] = '%' . $lugar . '%';
        }

        if ($item !== '' || $categoria !== '') {
            $subCond = [];
            if ($item !== '') {
                $subCond[]       = 'i.nombre LIKE :item';
                $params[':item'] = '%' . $item . '%';
            }
            if ($categoria !== '') {
                $subCond[]           = 'i.categoria = :cat';
                $params[':cat']      = $categoria;
            }
            $where[] = 'EXISTS (
                SELECT 1 FROM compra_detalles cd
                JOIN insumos i ON i.id = cd.insumo_id
                WHERE cd.compra_id = c.id AND ' . implode(' AND ', $subCond) . '
            )';
        }

        $orderSql = match ($orden) {
            'lugar' => 'c.lugar_compra ASC, c.fecha_compra DESC',
            'total' => 'c.total DESC, c.fecha_compra DESC',
            default => 'c.fecha_compra DESC, c.lugar_compra',
        };

        $sql = "SELECT c.id,
                       c.proveedor_id,
                       DATE(c.fecha_compra)                AS fecha,
                       c.fecha_compra,
                       c.total,
                       c.notas,
                       IFNULL(c.lugar_compra, 'Sin lugar') AS lugar_compra,
                       IFNULL(p.nombre, 'Sin proveedor')   AS proveedor,
                       u.nombre                            AS registrado_por,
                       (SELECT COUNT(*) FROM compra_detalles WHERE compra_id = c.id) AS num_lineas
                FROM compras c
                LEFT JOIN proveedores p ON p.id = c.proveedor_id
                LEFT JOIN usuarios u    ON u.id = c.created_by
                WHERE " . implode(' AND ', $where) . "
                ORDER BY $orderSql";

        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $compras = $stmt->fetchAll();

        // Líneas de detalle para cada compra; si hay filtro por ítem/cat → solo las coincidentes
        $lineaWhere  = 'cd.compra_id = :cid';
        $lineaParams = [':cid' => 0];
        if ($item !== '') {
            $lineaWhere         .= ' AND i.nombre LIKE :litem';
            $lineaParams[':litem'] = '%' . $item . '%';
        }
        if ($categoria !== '') {
            $lineaWhere        .= ' AND i.categoria = :lcat';
            $lineaParams[':lcat'] = $categoria;
        }

        $stmtLineas = db()->prepare(
            "SELECT cd.insumo_id, cd.cantidad, cd.precio_unitario, cd.subtotal,
                    i.nombre AS insumo, i.unidad_medida, i.categoria
             FROM compra_detalles cd
             JOIN insumos i ON i.id = cd.insumo_id
             WHERE $lineaWhere
             ORDER BY i.nombre"
        );

        foreach ($compras as &$c) {
            $lineaParams[':cid'] = $c['id'];
            $stmtLineas->execute($lineaParams);
            $c['lineas'] = $stmtLineas->fetchAll();
        }

        return $compras;
    }

    /**
     * Edita una compra existente: revierte stock de las líneas antiguas,
     * borra los detalles, inserta los nuevos y recalcula insumos/productos.
     *
     * @param int      $id          ID de la compra
     * @param array    $lineas       Nuevas líneas [['insumo_id','cantidad','precio_unitario'],…]
     * @param int|null $proveedor_id
     * @param string   $notas
     * @param string   $lugar_compra
     * @throws RuntimeException
     */
    public static function editar(
        int    $id,
        array  $lineas,
        ?int   $proveedor_id,
        string $notas        = '',
        string $lugar_compra = ''
    ): void {
        if (empty($lineas)) throw new RuntimeException('La compra debe tener al menos un ítem.');

        $pdo = db();
        $uid = (int)($_SESSION['usuario_id'] ?? 0);

        // Verificar que la compra exista
        $existe = $pdo->prepare('SELECT id FROM compras WHERE id = ?');
        $existe->execute([$id]);
        if (!$existe->fetchColumn()) throw new RuntimeException('Compra no encontrada.');

        $pdo->beginTransaction();
        try {
            // ── 1. Revertir stock de las líneas originales ──────────────────
            $old = $pdo->prepare('SELECT insumo_id, cantidad FROM compra_detalles WHERE compra_id = ?');
            $old->execute([$id]);
            $stmtRev = $pdo->prepare(
                'UPDATE insumos SET stock_actual = stock_actual - :cant, updated_by = :uid WHERE id = :iid'
            );
            foreach ($old->fetchAll() as $lo) {
                $stmtRev->execute([':cant' => $lo['cantidad'], ':uid' => $uid, ':iid' => $lo['insumo_id']]);
            }

            // ── 2. Borrar líneas antiguas y reinsertar ──────────────────────
            // INMUTABILIDAD: se usa DELETE + re-INSERT (no UPDATE de precio_unitario)
            // para preservar la semántica de "corrección de error de captura".
            // Los reportes de variación de precios seguirán reflejando
            // el precio corregido como si siempre hubiera sido ese.
            $pdo->prepare('DELETE FROM compra_detalles WHERE compra_id = ?')->execute([$id]);

            // ── 3. Insertar nuevas líneas + actualizar insumos ──────────────
            // Reusar la detección de migración 032 de crear()
            static $tiene032e = null;
            if ($tiene032e === null) {
                try {
                    $tiene032e = (int)db()->query(
                        "SELECT COUNT(*) FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='compra_detalles'
                           AND COLUMN_NAME='precio_presentacion'"
                    )->fetchColumn() > 0;
                } catch (\Exception $e2) { $tiene032e = false; }
            }

            if ($tiene032e) {
                $stmtDet = $pdo->prepare(
                    'INSERT INTO compra_detalles
                        (compra_id, insumo_id, cantidad, precio_unitario, subtotal,
                         presentacion, cantidad_presentacion, cant_presentaciones, precio_presentacion,
                         created_by)
                     VALUES (:cid, :iid, :cant, :precio, :sub,
                             :pres, :cantpx, :numpres, :ppres, :uid)'
                );
            } else {
                $stmtDet = $pdo->prepare(
                    'INSERT INTO compra_detalles
                        (compra_id, insumo_id, cantidad, precio_unitario, subtotal, created_by)
                     VALUES (:cid, :iid, :cant, :precio, :sub, :uid)'
                );
            }
            $stmtUpd = $pdo->prepare(
                'UPDATE insumos
                 SET costo_actual  = :precio,
                     stock_actual  = stock_actual + :cant,
                     updated_by    = :uid
                 WHERE id = :iid'
            );
            $stmtRecalc = $pdo->prepare(
                "UPDATE productos p
                 SET p.costo_calculado = (
                     SELECT IFNULL(SUM(ins.costo_actual * rec.cantidad_requerida), 0)
                     FROM recetas rec JOIN insumos ins ON ins.id = rec.insumo_id
                     WHERE rec.producto_id = p.id
                 ), p.updated_by = :uid
                 WHERE p.activo = 1
                   AND p.id IN (SELECT DISTINCT r2.producto_id FROM recetas r2 WHERE r2.insumo_id = :iid)"
            );

            $total  = 0.0;
            $tocados = [];
            foreach ($lineas as $l) {
                $iid    = (int)$l['insumo_id'];
                $cant   = (float)$l['cantidad'];
                $precio = (float)$l['precio_unitario'];
                $sub    = $cant * $precio;
                $total += $sub;

                $detParams = [':cid'=>$id,':iid'=>$iid,':cant'=>$cant,':precio'=>$precio,':sub'=>$sub,':uid'=>$uid];
                if ($tiene032e) {
                    $detParams[':pres']    = $l['presentacion']          ?? null;
                    $detParams[':cantpx']  = isset($l['cantidad_presentacion']) && $l['cantidad_presentacion'] > 0
                                            ? (float)$l['cantidad_presentacion'] : null;
                    $detParams[':numpres'] = isset($l['cant_presentaciones']) && $l['cant_presentaciones'] > 0
                                            ? (float)$l['cant_presentaciones'] : null;
                    $detParams[':ppres']   = isset($l['precio_presentacion']) && $l['precio_presentacion'] > 0
                                            ? (float)$l['precio_presentacion'] : null;
                }
                $stmtDet->execute($detParams);
                $stmtUpd->execute([':precio'=>$precio,':cant'=>$cant,':uid'=>$uid,':iid'=>$iid]);
                $tocados[$iid] = true;
            }

            // ── 4. Actualizar cabecera ──────────────────────────────────────
            $pdo->prepare(
                'UPDATE compras
                 SET proveedor_id = :pid, lugar_compra = :lugar,
                     total = :total, notas = :notas, updated_by = :uid
                 WHERE id = :id'
            )->execute([
                ':pid'   => $proveedor_id,
                ':lugar' => $lugar_compra ?: null,
                ':total' => $total,
                ':notas' => $notas        ?: null,
                ':uid'   => $uid,
                ':id'    => $id,
            ]);

            // ── 5. Recalcular productos ─────────────────────────────────────
            foreach (array_keys($tocados) as $iid) {
                $stmtRecalc->execute([':uid' => $uid, ':iid' => (int)$iid]);
            }

            $pdo->commit();

        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Duplica una compra: crea una nueva con las mismas líneas, proveedor y lugar.
     * Propaga cambios de stock igual que CompraModel::crear().
     *
     * @return int ID de la nueva compra
     */
    public static function duplicar(int $id): int
    {
        $pdo = db();

        $cab = $pdo->prepare('SELECT proveedor_id, lugar_compra, notas FROM compras WHERE id = ?');
        $cab->execute([$id]);
        $compra = $cab->fetch();
        if (!$compra) throw new RuntimeException('Compra no encontrada.');

        $lin = $pdo->prepare(
            'SELECT insumo_id, cantidad, precio_unitario FROM compra_detalles WHERE compra_id = ?'
        );
        $lin->execute([$id]);
        $lineas = $lin->fetchAll();
        if (empty($lineas)) throw new RuntimeException('La compra original no tiene ítems.');

        $nuevas = array_map(fn($r) => [
            'insumo_id'       => $r['insumo_id'],
            'cantidad'        => $r['cantidad'],
            'precio_unitario' => $r['precio_unitario'],
        ], $lineas);

        return static::crear(
            $nuevas,
            $compra['proveedor_id'],
            ($compra['notas'] ?? '') ? '[Copia] ' . $compra['notas'] : '[Copia]',
            $compra['lugar_compra'] ?? ''
        );
    }

    /**
     * Elimina una compra y revierte el stock de sus líneas.
     * No restaura costo_actual (la compra más reciente del mismo insumo lo determina).
     */
    public static function eliminar(int $id): void
    {
        $pdo = db();
        $uid = (int)($_SESSION['usuario_id'] ?? 0);

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT insumo_id, cantidad FROM compra_detalles WHERE compra_id = ?');
            $stmt->execute([$id]);
            $lineas = $stmt->fetchAll();

            $stmtRev = $pdo->prepare(
                'UPDATE insumos SET stock_actual = stock_actual - :cant, updated_by = :uid WHERE id = :iid'
            );
            foreach ($lineas as $l) {
                $stmtRev->execute([':cant' => $l['cantidad'], ':uid' => $uid, ':iid' => $l['insumo_id']]);
            }

            // compra_detalles se borra por CASCADE al eliminar la compra
            $pdo->prepare('DELETE FROM compras WHERE id = ?')->execute([$id]);

            $pdo->commit();

        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Devuelve las categorías de insumos que tienen compras registradas. */
    public static function categorias_usadas(): array
    {
        return db()->query(
            "SELECT DISTINCT i.categoria
             FROM compra_detalles cd
             JOIN insumos i ON i.id = cd.insumo_id
             WHERE i.categoria IS NOT NULL AND i.categoria != ''
             ORDER BY i.categoria"
        )->fetchAll(PDO::FETCH_COLUMN);
    }

    /** Retorna el detalle completo de una compra (cabecera + líneas). */
    public static function detalle(int $id): array
    {
        $pdo = db();

        $cab = $pdo->prepare(
            "SELECT c.*, IFNULL(p.nombre,'Sin proveedor') AS proveedor
             FROM compras c LEFT JOIN proveedores p ON p.id = c.proveedor_id WHERE c.id = ?"
        );
        $cab->execute([$id]);
        $compra = $cab->fetch();
        if (!$compra) return [];

        $lin = $pdo->prepare(
            'SELECT cd.cantidad, cd.precio_unitario, cd.subtotal, i.nombre AS insumo, i.unidad_medida
             FROM compra_detalles cd JOIN insumos i ON i.id = cd.insumo_id
             WHERE cd.compra_id = ? ORDER BY i.nombre'
        );
        $lin->execute([$id]);
        $compra['lineas'] = $lin->fetchAll();

        return $compra;
    }
}
