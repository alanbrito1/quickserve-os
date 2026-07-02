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
