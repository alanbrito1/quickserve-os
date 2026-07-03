<?php
/**
 * contabilidad/api/contab.php — Acciones contables (solo admin/superadmin).
 *   accion=apertura       → asiento de balance de apertura (saldos iniciales)
 *   accion=asiento_manual → asiento manual del contador (líneas debe/haber)
 *   accion=reversar       → contra-asiento de un asiento existente
 *
 * Seguridad: rol admin/superadmin + CSRF + try/catch. Partida doble validada
 * por ContabilidadModel::crear_asiento (Σ debe = Σ haber).
 */
require_once __DIR__ . '/../../app/middleware/auth_check.php';
require_once __DIR__ . '/../../app/models/ContabilidadModel.php';
require_once __DIR__ . '/../../app/helpers/AuditoriaHelper.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido.']);
    exit;
}
if (!in_array($_SESSION['usuario_rol'] ?? '', ['admin', 'superadmin'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Sin permisos.']);
    exit;
}
if (!csrf_verificar()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Token CSRF inválido.']);
    exit;
}
if (!ContabilidadModel::existe()) {
    echo json_encode(['success' => false, 'error' => 'Aplica la migración 045 (contabilidad) primero.']);
    exit;
}

$accion = $_POST['accion'] ?? '';

try {
    // ── BALANCE DE APERTURA ───────────────────────────────────────────────────
    // Recibe saldos[] = [{codigo, saldo}]. Debita activos (saldo>0), acredita
    // contra-activos/pasivos, y CAPITAL (3115) se calcula como cifra de cuadre.
    if ($accion === 'apertura') {
        $fecha  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['fecha'] ?? '') ? $_POST['fecha'] : date('Y-m-d');
        $saldos = json_decode($_POST['saldos'] ?? '[]', true);
        if (!is_array($saldos)) throw new RuntimeException('Datos inválidos.');

        // Traer naturaleza/tipo de cada cuenta para saber si va al debe o al haber
        $cuentas = [];
        foreach (db()->query("SELECT codigo, tipo, naturaleza, es_contra FROM cuentas_contables")->fetchAll() as $c) {
            $cuentas[$c['codigo']] = $c;
        }

        $lineas = []; $sumaDebe = 0.0; $sumaHaber = 0.0;
        foreach ($saldos as $s) {
            $cod = (string)($s['codigo'] ?? '');
            $val = round((float)($s['saldo'] ?? 0), 2);
            if ($cod === '3115' || $val == 0 || !isset($cuentas[$cod])) continue; // capital se calcula; 0 se omite
            $c = $cuentas[$cod];
            // Activos normales → debe; contra-activos y pasivos → haber
            if ($c['tipo'] === 'activo' && !$c['es_contra']) {
                $lineas[] = ['codigo' => $cod, 'debe' => $val, 'haber' => 0]; $sumaDebe += $val;
            } else {
                $lineas[] = ['codigo' => $cod, 'debe' => 0, 'haber' => $val]; $sumaHaber += $val;
            }
        }
        // Capital = cifra que hace cuadrar (Activo − Pasivo − contra-activos)
        $capital = round($sumaDebe - $sumaHaber, 2);
        if (abs($capital) >= 0.01) {
            $lineas[] = $capital >= 0
                ? ['codigo' => '3115', 'debe' => 0, 'haber' => $capital]   // patrimonio positivo
                : ['codigo' => '3115', 'debe' => -$capital, 'haber' => 0]; // patrimonio negativo (déficit)
        }
        if (count($lineas) < 2) throw new RuntimeException('Ingresa al menos un saldo inicial.');

        $aid = ContabilidadModel::crear_asiento($fecha, 'Balance de apertura', 'apertura', null, $lineas);
        log_registrar('asientos', $aid, 'apertura', null, 'capital=' . $capital, 'INSERT');
        echo json_encode(['success' => true, 'asiento' => $aid, 'capital' => $capital]);
        exit;
    }

    // ── ASIENTO MANUAL ────────────────────────────────────────────────────────
    if ($accion === 'asiento_manual') {
        $fecha = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['fecha'] ?? '') ? $_POST['fecha'] : date('Y-m-d');
        $desc  = trim($_POST['descripcion'] ?? '');
        if ($desc === '') throw new RuntimeException('La descripción es obligatoria.');
        $lineasRaw = json_decode($_POST['lineas'] ?? '[]', true);
        if (!is_array($lineasRaw) || count($lineasRaw) < 2) throw new RuntimeException('Agrega al menos dos líneas.');
        $lineas = array_map(fn($l) => [
            'cuenta_id' => (int)($l['cuenta_id'] ?? 0),
            'debe'  => (float)($l['debe']  ?? 0),
            'haber' => (float)($l['haber'] ?? 0),
        ], $lineasRaw);

        $aid = ContabilidadModel::crear_asiento($fecha, $desc, 'manual', null, $lineas);
        log_registrar('asientos', $aid, 'manual', null, $desc, 'INSERT');
        echo json_encode(['success' => true, 'asiento' => $aid]);
        exit;
    }

    // ── CONFIG IVA (Fase 4c) ──────────────────────────────────────────────────
    if ($accion === 'config_iva') {
        $activo = ($_POST['iva_activo'] ?? '0') === '1' ? 1 : 0;
        $tarifa = max(0, min(100, (float)($_POST['iva_tarifa'] ?? 19)));
        $uid = (int)($_SESSION['usuario_id'] ?? 0);
        $upd = db()->prepare("UPDATE configuracion_negocio SET valor = ?, updated_by = ? WHERE clave = ?");
        $upd->execute([$activo, $uid, 'iva_activo']);
        $upd->execute([$tarifa, $uid, 'iva_tarifa']);
        log_registrar('configuracion_negocio', 0, 'iva', null, "activo={$activo} tarifa={$tarifa}", 'UPDATE');
        echo json_encode(['success' => true, 'iva_activo' => $activo, 'iva_tarifa' => $tarifa]);
        exit;
    }

    // ── MOVIMIENTO DE TESORERÍA / CAPITAL (Fase 4c) ───────────────────────────
    // Genera el asiento de un pago o aporte guiado (el usuario no elige cuentas).
    if ($accion === 'movimiento') {
        $tipo   = $_POST['tipo'] ?? '';
        $fecha  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['fecha'] ?? '') ? $_POST['fecha'] : date('Y-m-d');
        $monto  = round((float)($_POST['monto'] ?? 0), 2);
        $nota   = trim($_POST['nota'] ?? '');
        $tesor  = ($_POST['tesoreria'] ?? 'caja') === 'bancos' ? '1110' : '1105';
        if ($monto <= 0) throw new RuntimeException('El monto debe ser mayor a 0.');

        // tipo → [cuenta débito, cuenta crédito, etiqueta]
        $MAP = [
            'aporte_capital' => [$tesor, '3115', 'Aporte de capital'],
            'retiro_capital' => ['3115', $tesor, 'Retiro de capital'],
            'pago_proveedor' => ['2205', $tesor, 'Pago a proveedor'],
            'pago_nomina'    => ['2510', $tesor, 'Pago de nómina'],
        ];
        if (!isset($MAP[$tipo])) throw new RuntimeException('Tipo de movimiento no válido.');
        [$cDebe, $cHaber, $lbl] = $MAP[$tipo];
        $lineas = [
            ['codigo' => $cDebe,  'debe' => $monto, 'haber' => 0],
            ['codigo' => $cHaber, 'debe' => 0,      'haber' => $monto],
        ];
        $aid = ContabilidadModel::crear_asiento($fecha, $lbl . ($nota ? ' — ' . $nota : ''), 'movimiento', null, $lineas);
        log_registrar('asientos', $aid, $tipo, null, 'monto=' . $monto, 'INSERT');
        echo json_encode(['success' => true, 'asiento' => $aid]);
        exit;
    }

    // ── BACKFILL DE VENTAS ────────────────────────────────────────────────────
    // Genera el asiento de las ventas no anuladas que aún no lo tienen (histórico).
    if ($accion === 'backfill_ventas') {
        $ventas = db()->query(
            "SELECT v.id FROM ventas v
             WHERE v.estado <> 'anulada'
               AND NOT EXISTS (SELECT 1 FROM asientos a WHERE a.origen='venta' AND a.origen_id=v.id AND a.anulado=0)
             ORDER BY v.id"
        )->fetchAll(PDO::FETCH_COLUMN);
        $n = 0; $err = 0;
        foreach ($ventas as $vid) {
            try { ContabilidadModel::postear_venta((int)$vid); $n++; }
            catch (\Throwable $e) { $err++; error_log('[contab backfill venta '.$vid.'] '.$e->getMessage()); }
        }
        log_registrar('asientos', 0, 'backfill_ventas', null, "posteadas={$n} errores={$err}", 'INSERT');
        echo json_encode(['success' => true, 'posteadas' => $n, 'errores' => $err]);
        exit;
    }

    // ── REVERSAR ──────────────────────────────────────────────────────────────
    if ($accion === 'reversar') {
        $id = (int)($_POST['asiento_id'] ?? 0);
        if (!$id) throw new RuntimeException('ID inválido.');
        $rid = ContabilidadModel::reversar_asiento($id, trim($_POST['motivo'] ?? ''));
        log_registrar('asientos', $id, 'reversa', null, 'reversa=' . $rid, 'UPDATE');
        echo json_encode(['success' => true, 'reversa' => $rid]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Acción inválida.']);

} catch (\RuntimeException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    error_log('[ClanDestino contab] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Error interno contable.']);
}
