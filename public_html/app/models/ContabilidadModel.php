<?php
/**
 * app/models/ContabilidadModel.php — Motor de contabilidad de partida doble (Fase 4).
 *
 * Reglas invariantes:
 *   - Todo asiento cumple partida doble: SUM(debe) = SUM(haber) (tolerancia 1 centavo).
 *   - Los asientos NO se editan: se corrigen con un contra-asiento (reversar_asiento).
 *   - Cada línea usa debe O haber (no ambos).
 *
 * El resto del sistema debe llamar a `existe()` antes de contabilizar, para no
 * fallar si la migración 045 aún no está aplicada (patrón de detección del proyecto).
 */
class ContabilidadModel
{
    /** ¿Está aplicada la migración 045 (existe el subsistema contable)? (cacheado) */
    public static function existe(): bool
    {
        static $ok = null;
        if ($ok === null) {
            try {
                $ok = (int)db()->query(
                    "SELECT COUNT(*) FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cuentas_contables'"
                )->fetchColumn() > 0;
            } catch (\Throwable $e) { $ok = false; }
        }
        return $ok;
    }

    /** Mapa codigo→id de cuentas (cacheado por request). */
    private static function mapaCuentas(): array
    {
        static $mapa = null;
        if ($mapa === null) {
            $mapa = [];
            foreach (db()->query("SELECT id, codigo FROM cuentas_contables")->fetchAll() as $c) {
                $mapa[$c['codigo']] = (int)$c['id'];
            }
        }
        return $mapa;
    }

    /** id de una cuenta por su código (o null). */
    public static function cuentaId(string $codigo): ?int
    {
        return self::mapaCuentas()[$codigo] ?? null;
    }

    /**
     * Crea un asiento cuadrado. $lineas: [['codigo'=>'1105','debe'=>1000,'haber'=>0], ...]
     * (también acepta 'cuenta_id'). Devuelve el id del asiento.
     * @throws RuntimeException si no cuadra o una cuenta no existe.
     */
    public static function crear_asiento(
        string $fecha, string $descripcion, string $origen, ?int $origen_id, array $lineas
    ): int {
        $pdo = db();
        $uid = (int)($_SESSION['usuario_id'] ?? 0);

        // Normalizar y validar líneas
        $totDebe = 0.0; $totHaber = 0.0; $norm = [];
        foreach ($lineas as $l) {
            $cid = isset($l['cuenta_id']) ? (int)$l['cuenta_id'] : self::cuentaId((string)($l['codigo'] ?? ''));
            if (!$cid) throw new RuntimeException('Cuenta contable no encontrada: ' . ($l['codigo'] ?? $l['cuenta_id'] ?? '?'));
            $debe  = round((float)($l['debe']  ?? 0), 2);
            $haber = round((float)($l['haber'] ?? 0), 2);
            if ($debe < 0 || $haber < 0) throw new RuntimeException('Debe/haber no puede ser negativo.');
            if ($debe > 0 && $haber > 0) throw new RuntimeException('Una línea no puede tener debe y haber a la vez.');
            if ($debe == 0 && $haber == 0) continue; // línea vacía → se omite
            $totDebe += $debe; $totHaber += $haber;
            $norm[] = [$cid, $debe, $haber];
        }
        if (count($norm) < 2) throw new RuntimeException('Un asiento necesita al menos dos líneas.');
        if (abs($totDebe - $totHaber) > 0.01) {
            throw new RuntimeException('El asiento no cuadra: debe=' . $totDebe . ' haber=' . $totHaber . '.');
        }

        $propia = !$pdo->inTransaction();
        if ($propia) $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO asientos (fecha, descripcion, origen, origen_id, created_by)
                 VALUES (?,?,?,?,?)'
            )->execute([$fecha, $descripcion, $origen, $origen_id, $uid]);
            $aid = (int)$pdo->lastInsertId();
            $ins = $pdo->prepare('INSERT INTO asiento_lineas (asiento_id, cuenta_id, debe, haber) VALUES (?,?,?,?)');
            foreach ($norm as [$cid, $debe, $haber]) {
                $ins->execute([$aid, $cid, $debe, $haber]);
            }
            if ($propia) $pdo->commit();
            return $aid;
        } catch (\Throwable $e) {
            if ($propia && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** Reversa un asiento generando su contra-asiento (debe↔haber). */
    public static function reversar_asiento(int $asiento_id, string $motivo = ''): int
    {
        $pdo = db();
        $cab = $pdo->prepare('SELECT * FROM asientos WHERE id = ? AND anulado = 0');
        $cab->execute([$asiento_id]);
        $a = $cab->fetch();
        if (!$a) throw new RuntimeException('Asiento no encontrado o ya anulado.');

        $ls = $pdo->prepare('SELECT cuenta_id, debe, haber FROM asiento_lineas WHERE asiento_id = ?');
        $ls->execute([$asiento_id]);
        $lineas = [];
        foreach ($ls->fetchAll() as $l) {
            // Invertir: lo que estaba en debe va a haber y viceversa
            $lineas[] = ['cuenta_id' => (int)$l['cuenta_id'], 'debe' => (float)$l['haber'], 'haber' => (float)$l['debe']];
        }
        $propia = !$pdo->inTransaction();
        if ($propia) $pdo->beginTransaction();
        try {
            $rid = self::crear_asiento(
                date('Y-m-d'),
                'Reversa asiento #' . $asiento_id . ($motivo ? ' — ' . $motivo : ''),
                'reversa', $asiento_id, $lineas
            );
            $pdo->prepare('UPDATE asientos SET anulado = 1 WHERE id = ?')->execute([$asiento_id]);
            if ($propia) $pdo->commit();
            return $rid;
        } catch (\Throwable $e) {
            if ($propia && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Saldos por cuenta hasta una fecha (inclusive). Devuelve filas con debe/haber
     * acumulados y el saldo con signo según naturaleza (contra-cuentas restan).
     */
    public static function saldos(?string $hasta = null): array
    {
        $par = [];
        $where = 'a.anulado = 0';
        if ($hasta) { $where .= ' AND a.fecha <= ?'; $par[] = $hasta; }
        $sql =
            "SELECT c.id, c.codigo, c.nombre, c.tipo, c.naturaleza, c.es_contra, c.orden,
                    COALESCE(SUM(l.debe),0)  AS debe,
                    COALESCE(SUM(l.haber),0) AS haber
             FROM cuentas_contables c
             LEFT JOIN asiento_lineas l ON l.cuenta_id = c.id
             LEFT JOIN asientos a ON a.id = l.asiento_id AND $where
             GROUP BY c.id, c.codigo, c.nombre, c.tipo, c.naturaleza, c.es_contra, c.orden
             ORDER BY c.orden, c.codigo";
        $st = db()->prepare($sql);
        $st->execute($par);
        $rows = $st->fetchAll();
        foreach ($rows as &$r) {
            $debe = (float)$r['debe']; $haber = (float)$r['haber'];
            // Saldo con la naturaleza de la cuenta (positivo = saldo normal)
            $r['saldo'] = $r['naturaleza'] === 'debito' ? ($debe - $haber) : ($haber - $debe);
        }
        return $rows;
    }

    /** Reversa todos los asientos (no anulados) de una transacción origen. */
    public static function reversar_por_origen(string $origen, int $origen_id): void
    {
        if (!self::existe()) return;
        $st = db()->prepare("SELECT id FROM asientos WHERE origen=? AND origen_id=? AND anulado=0");
        $st->execute([$origen, $origen_id]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $aid) {
            self::reversar_asiento((int)$aid, 'anulación ' . $origen . ' #' . $origen_id);
        }
    }

    /** ¿Ya existe un asiento (no anulado) para esta transacción? (evita duplicados) */
    private static function ya_posteado(string $origen, int $origen_id): bool
    {
        $st = db()->prepare("SELECT COUNT(*) FROM asientos WHERE origen=? AND origen_id=? AND anulado=0");
        $st->execute([$origen, $origen_id]);
        return (int)$st->fetchColumn() > 0;
    }

    /**
     * Postea el asiento de una VENTA (Fase 4b). Débito Caja/Bancos/CxC según método,
     * crédito Ingresos; + Débito Costo de ventas / crédito Inventario (COGS con el
     * snapshot inmutable). Obsequio: Gasto obsequios contra inventario (sin ingreso).
     * Se llama DESPUÉS de que la venta commitea; idempotente.
     */
    public static function postear_venta(int $venta_id): void
    {
        if (!self::existe() || self::ya_posteado('venta', $venta_id)) return;
        $pdo = db();
        $v = $pdo->prepare("SELECT fecha_venta, metodo_pago, total FROM ventas WHERE id = ? AND estado <> 'anulada'");
        $v->execute([$venta_id]);
        $venta = $v->fetch();
        if (!$venta) return;
        $fecha  = substr((string)$venta['fecha_venta'], 0, 10);
        $metodo = (string)$venta['metodo_pago'];
        $total  = round((float)$venta['total'], 2);

        // COGS separado por fuente: from_stock=1 → producto terminado (1430); 0 → insumos (1435)
        $c = $pdo->prepare(
            "SELECT vd.from_stock,
                    COALESCE(SUM(COALESCE(vd.costo_unit_snap, p.costo_calculado, 0) * vd.cantidad),0) AS costo
             FROM venta_detalles vd JOIN productos p ON p.id = vd.producto_id
             WHERE vd.venta_id = ? GROUP BY vd.from_stock"
        );
        $c->execute([$venta_id]);
        $cTerm = 0.0; $cIns = 0.0;
        foreach ($c->fetchAll() as $r) {
            if ((int)$r['from_stock'] === 1) $cTerm += (float)$r['costo']; else $cIns += (float)$r['costo'];
        }
        $cTerm = round($cTerm, 2); $cIns = round($cIns, 2); $cogs = round($cTerm + $cIns, 2);

        $lineas = [];
        if ($metodo === 'obsequio') {
            if ($cogs <= 0) return;
            $lineas[] = ['codigo' => '5199', 'debe' => $cogs, 'haber' => 0];
            if ($cTerm > 0) $lineas[] = ['codigo' => '1430', 'debe' => 0, 'haber' => $cTerm];
            if ($cIns  > 0) $lineas[] = ['codigo' => '1435', 'debe' => 0, 'haber' => $cIns];
            $desc = 'Obsequio venta #' . $venta_id;
        } else {
            if ($total <= 0) return;
            $cta = $metodo === 'efectivo' ? '1105' : ($metodo === 'fiado' ? '1305' : '1110');
            $lineas[] = ['codigo' => $cta,   'debe' => $total, 'haber' => 0];
            $lineas[] = ['codigo' => '4135', 'debe' => 0,      'haber' => $total];
            if ($cogs > 0) {
                $lineas[] = ['codigo' => '6135', 'debe' => $cogs, 'haber' => 0];
                if ($cTerm > 0) $lineas[] = ['codigo' => '1430', 'debe' => 0, 'haber' => $cTerm];
                if ($cIns  > 0) $lineas[] = ['codigo' => '1435', 'debe' => 0, 'haber' => $cIns];
            }
            $desc = 'Venta #' . $venta_id . ' (' . $metodo . ')';
        }
        if (count($lineas) < 2) return;
        self::crear_asiento($fecha, $desc, 'venta', $venta_id, $lineas);
    }

    /**
     * Totales para el Balance General a una fecha:
     *   activo, pasivo, patrimonio, ingresos, costos, gastos, resultado (ing-cost-gas),
     *   patrimonio_total (patrimonio + resultado) y 'cuadra' (activo ≈ pasivo + patrimonio_total).
     */
    public static function balance(?string $hasta = null): array
    {
        $t = ['activo'=>0.0,'pasivo'=>0.0,'patrimonio'=>0.0,'ingreso'=>0.0,'costo'=>0.0,'gasto'=>0.0];
        foreach (self::saldos($hasta) as $r) {
            // Para activos, las contra-cuentas (deprec. acumulada) ya tienen saldo natural credito
            // → su 'saldo' sale negativo respecto al activo; se suma tal cual para netear.
            if ($r['tipo'] === 'activo') {
                $t['activo'] += $r['es_contra'] ? -abs($r['saldo']) : $r['saldo'];
            } else {
                $t[$r['tipo']] += $r['saldo'];
            }
        }
        $resultado = $t['ingreso'] - $t['costo'] - $t['gasto'];
        $patr_total = $t['patrimonio'] + $resultado;
        $t['resultado'] = round($resultado, 2);
        $t['patrimonio_total'] = round($patr_total, 2);
        $t['pasivo_mas_patrimonio'] = round($t['pasivo'] + $patr_total, 2);
        $t['cuadra'] = abs($t['activo'] - $t['pasivo_mas_patrimonio']) < 1.0;
        foreach (['activo','pasivo','patrimonio','ingreso','costo','gasto'] as $k) $t[$k] = round($t[$k], 2);
        return $t;
    }
}
