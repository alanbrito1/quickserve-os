<?php
/**
 * reportes/precios.php — Variación Histórica de Precios y Costos.
 *
 * Muestra cómo han cambiado en el tiempo los precios registrados en cada módulo.
 * Principio: cada transacción almacena el precio vigente al momento; este reporte
 * permite analizar esa evolución sin alterar ningún registro histórico.
 *
 * Secciones:
 *   1. Insumos       → precio pagado por compra (compra_detalles)
 *   2. Productos     → precio de venta efectivo (venta_detalles) y costo de producción
 *   3. Nómina        → salario/costo por empleado por período (nomina_liquidaciones)
 *   4. Costos fijos  → evolución de costos indirectos con fechas de vigencia
 *
 * Exporta a Excel (.xlsx) con una hoja por sección.
 */
require_once __DIR__ . '/../app/middleware/auth_check.php';
require_once __DIR__ . '/../app/helpers/XlsxWriter.php';

$nav_activo = 'reportes';
permiso_requerir('reportes', 'solo_ver');

// ── Filtros de período ────────────────────────────────────────────────────────
$hoy         = date('Y-m-d');
$fecha_desde = $_GET['desde'] ?? date('Y-01-01'); // inicio del año actual por defecto
$fecha_hasta = $_GET['hasta'] ?? $hoy;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_desde)) $fecha_desde = date('Y-01-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_hasta)) $fecha_hasta = $hoy;
if ($fecha_desde > $fecha_hasta) $fecha_hasta = $fecha_desde;

$f_ini = $fecha_desde . ' 00:00:00';
$f_fin = $fecha_hasta . ' 23:59:59';

// Selección de sección para la vista HTML (evita cargar todo al mismo tiempo en pantallas pequeñas)
// Secciones disponibles — 'activos' muestra historial de cambios desde logs_historial
$seccion = $_GET['seccion'] ?? 'insumos';
if (!in_array($seccion, ['insumos','productos','nomina','costos','activos','fiado'], true)) $seccion = 'insumos';

$pdo = db();

// ── 1. HISTORIAL DE PRECIOS DE INSUMOS ───────────────────────────────────────
// Fuente: compra_detalles (precio pagado en cada compra).
// La inmutabilidad está garantizada: el precio se almacena en compra_detalles
// y nunca se modifica cuando cambia insumos.costo_actual.
// Detectar columnas de migración 032 y 034 para enrichecer la query
$tiene_pres_cols = false;
$tiene_snap_cols = false;
try {
    $tiene_pres_cols = (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='compra_detalles'
           AND COLUMN_NAME='precio_presentacion'"
    )->fetchColumn() > 0;
    $tiene_snap_cols = (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='compra_detalles'
           AND COLUMN_NAME='nombre_snap'"
    )->fetchColumn() > 0;
} catch (\Exception $e) {}

$stmt_ins = $pdo->prepare(
    "SELECT i.id AS insumo_id,
            COALESCE(cd.nombre_snap, i.nombre) AS nombre,
            COALESCE(cd.unidad_snap,  i.unidad_medida) AS unidad_medida,
            DATE(c.fecha_compra) AS fecha,
            cd.precio_unitario,
            cd.cantidad,
            cd.presentacion, cd.cantidad_presentacion,
            cd.cant_presentaciones, cd.precio_presentacion,
            IFNULL(p.nombre,'Sin proveedor') AS proveedor
     FROM compra_detalles cd
     JOIN compras  c ON c.id  = cd.compra_id
     JOIN insumos  i ON i.id  = cd.insumo_id
     LEFT JOIN proveedores p ON p.id = c.proveedor_id
     WHERE c.fecha_compra BETWEEN :ini AND :fin
     ORDER BY i.nombre, c.fecha_compra"
);
$stmt_ins->execute([':ini' => $f_ini, ':fin' => $f_fin]);
$raw_ins = $stmt_ins->fetchAll();

// Agrupar por insumo y calcular variación respecto al precio anterior
$insumos_hist = [];
foreach ($raw_ins as $row) {
    $iid = $row['insumo_id'];
    if (!isset($insumos_hist[$iid])) {
        $insumos_hist[$iid] = [
            'nombre'    => $row['nombre'],
            'unidad'    => $row['unidad_medida'],
            'registros' => [],
        ];
    }
    $registros =& $insumos_hist[$iid]['registros'];
    $prev_precio = !empty($registros) ? (float)end($registros)['precio'] : null;
    $precio      = (float)$row['precio_unitario'];
    $variacion   = ($prev_precio !== null && $prev_precio > 0)
                   ? round(($precio - $prev_precio) / $prev_precio * 100, 1)
                   : null;
    $registros[] = [
        'fecha'     => $row['fecha'],
        'precio'    => $precio,
        'cantidad'  => (float)$row['cantidad'],
        'proveedor' => $row['proveedor'],
        'var'       => $variacion,
    ];
    unset($registros);
}

// ── 2. HISTORIAL DE PRECIOS DE PRODUCTOS ─────────────────────────────────────
// 2a. Precio de venta efectivo (venta_detalles.precio_unitario = precio cobrado al cliente)
// nombre2 incluido para identificar el producto con su subtítulo en el reporte
$stmt_pv = $pdo->prepare(
    "SELECT p.id AS prod_id, p.nombre, p.nombre2,
            DATE(v.fecha_venta)  AS fecha,
            vd.precio_unitario,
            vd.cantidad,
            v.metodo_pago
     FROM venta_detalles vd
     JOIN ventas    v  ON v.id  = vd.venta_id
     JOIN productos p  ON p.id  = vd.producto_id
     WHERE v.fecha_venta BETWEEN :ini AND :fin
       AND v.estado != 'anulada'
     ORDER BY p.nombre, v.fecha_venta"
);
$stmt_pv->execute([':ini' => $f_ini, ':fin' => $f_fin]);
$raw_pv = $stmt_pv->fetchAll();

// Agrupar por producto; detectar cambios de precio (solo mostrar cuando cambia)
$productos_precio = [];
foreach ($raw_pv as $row) {
    $pid = $row['prod_id'];
    if (!isset($productos_precio[$pid])) {
        $productos_precio[$pid] = ['nombre' => $row['nombre'], 'registros' => []];
    }
    $regs =& $productos_precio[$pid]['registros'];
    $ultimo_precio = !empty($regs) ? (float)end($regs)['precio'] : null;
    $precio = (float)$row['precio_unitario'];
    // Solo registrar cuando el precio cambia o es la primera aparición
    if ($ultimo_precio === null || abs($precio - $ultimo_precio) > 0.01) {
        $variacion = ($ultimo_precio !== null && $ultimo_precio > 0)
                     ? round(($precio - $ultimo_precio) / $ultimo_precio * 100, 1)
                     : null;
        $regs[] = ['fecha' => $row['fecha'], 'precio' => $precio, 'var' => $variacion];
    }
    unset($regs);
}

// 2b. Costo de producción por lote (produccion_lotes.costo_unitario = snapshot al producir)
$stmt_lotes = $pdo->prepare(
    "SELECT p.id AS prod_id, p.nombre, p.nombre2,
            DATE(pl.fecha_produccion) AS fecha,
            pl.costo_unitario,
            pl.cantidad
     FROM produccion_lotes pl
     JOIN productos p ON p.id = pl.producto_id
     WHERE pl.fecha_produccion BETWEEN :ini AND :fin
       AND pl.estado = 'activo'
       AND pl.costo_unitario IS NOT NULL AND pl.costo_unitario > 0
     ORDER BY p.nombre, pl.fecha_produccion"
);
// Usar $f_ini/$f_fin (YYYY-MM-DD HH:MM:SS) para coherencia con las demás queries del reporte
$stmt_lotes->execute([':ini' => $f_ini, ':fin' => $f_fin]);
$raw_lotes = $stmt_lotes->fetchAll();

$productos_costo = [];
foreach ($raw_lotes as $row) {
    $pid = $row['prod_id'];
    if (!isset($productos_costo[$pid])) {
        $productos_costo[$pid] = ['nombre' => $row['nombre'], 'registros' => []];
    }
    $regs =& $productos_costo[$pid]['registros'];
    $prev = !empty($regs) ? (float)end($regs)['costo'] : null;
    $costo = (float)$row['costo_unitario'];
    $var   = ($prev !== null && $prev > 0)
             ? round(($costo - $prev) / $prev * 100, 1)
             : null;
    $regs[] = ['fecha' => $row['fecha'], 'costo' => $costo, 'cantidad' => (int)$row['cantidad'], 'var' => $var];
    unset($regs);
}

// ── 3. HISTORIAL DE COSTOS LABORALES (NÓMINA) ────────────────────────────────
// Fuente: nomina_liquidaciones (snapshot completo de cada liquidación).
// salario_base y costo_total_empleador se guardan en el momento de liquidar
// y no cambian si el empleado cambia de salario posteriormente.
//
// NOTA: PDO::ATTR_EMULATE_PREPARES = false → los parámetros nombrados NO pueden
// repetirse en la misma query. Los límites AAAAMM se calculan en PHP y se pasan
// como parámetros independientes (:ini_ym y :fin_ym) para evitar el error HY093.
$ini_ym = (int)date('Y', strtotime($fecha_desde)) * 100 + (int)date('n', strtotime($fecha_desde));
$fin_ym = (int)date('Y', strtotime($fecha_hasta)) * 100 + (int)date('n', strtotime($fecha_hasta));

// Detectar migración 033 para mostrar valor_hora_snap / valor_proyecto_snap
$tiene033_rep = false;
try {
    $tiene033_rep = (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nomina_liquidaciones'
           AND COLUMN_NAME='valor_hora_snap'"
    )->fetchColumn() > 0;
} catch (\Exception $e) {}

// Incluye valor_hora_snap y valor_proyecto_snap si la migración 033 está aplicada
$colsSnap = $tiene033_rep
    ? ', nl.valor_hora_snap, nl.valor_proyecto_snap, nl.horas_trabajadas, nl.tipo_contrato AS liq_tipo'
    : ', NULL AS valor_hora_snap, NULL AS valor_proyecto_snap, nl.horas_trabajadas, nl.tipo_contrato AS liq_tipo';

$stmt_nom = $pdo->prepare(
    "SELECT e.id AS emp_id, e.nombre_completo, e.tipo_contrato,
            nl.periodo_anio, nl.periodo_mes,
            nl.salario_base, nl.costo_total_empleador,
            nl.total_cargas, nl.total_provisiones
            {$colsSnap}
     FROM nomina_liquidaciones nl
     JOIN empleados e ON e.id = nl.empleado_id
     WHERE (nl.periodo_anio * 100 + nl.periodo_mes) BETWEEN :ini_ym AND :fin_ym
     ORDER BY e.nombre_completo, nl.periodo_anio, nl.periodo_mes"
);
$stmt_nom->execute([':ini_ym' => $ini_ym, ':fin_ym' => $fin_ym]);
$raw_nom = $stmt_nom->fetchAll();

$nomina_hist = [];
foreach ($raw_nom as $row) {
    $eid = $row['emp_id'];
    if (!isset($nomina_hist[$eid])) {
        $nomina_hist[$eid] = [
            'nombre'   => $row['nombre_completo'],
            'contrato' => $row['tipo_contrato'],
            'registros'=> [],
        ];
    }
    $regs =& $nomina_hist[$eid]['registros'];
    $prev_sal = !empty($regs) ? (float)end($regs)['salario_base'] : null;
    $sal      = (float)$row['salario_base'];
    $var      = ($prev_sal !== null && $prev_sal > 0)
                ? round(($sal - $prev_sal) / $prev_sal * 100, 1)
                : null;
    $regs[] = [
        'periodo'          => sprintf('%d/%02d', $row['periodo_anio'], $row['periodo_mes']),
        'salario_base'     => $sal,
        'costo_total'      => (float)$row['costo_total_empleador'],
        'var'              => $var,
        'valor_hora_snap'  => isset($row['valor_hora_snap'])  ? (float)$row['valor_hora_snap']  : null,
        'valor_proj_snap'  => isset($row['valor_proyecto_snap']) ? (float)$row['valor_proyecto_snap'] : null,
        'horas'            => isset($row['horas_trabajadas']) ? (float)$row['horas_trabajadas'] : null,
        'tipo_liq'         => $row['liq_tipo'] ?? $row['tipo_contrato'] ?? null,
    ];
    unset($regs);
}

// ── 4. HISTORIAL DE COSTOS FIJOS E INDIRECTOS ────────────────────────────────
// Fuente: costos_indirectos (cada fila representa un costo en un período de vigencia).
// El modelo correcto es crear una nueva fila cuando un costo cambia de valor,
// no editar la existente, para mantener la trazabilidad.
$stmt_cos = $pdo->prepare(
    "SELECT ci.nombre, ci.categoria, ci.clasificacion, ci.tipo,
            ci.valor, ci.frecuencia,
            ROUND(
                ci.valor / CASE ci.frecuencia
                    WHEN 'mensual'    THEN 1
                    WHEN 'bimestral'  THEN 2
                    WHEN 'trimestral' THEN 3
                    WHEN 'semestral'  THEN 6
                    WHEN 'anual'      THEN 12
                    ELSE 1 END
            , 0) AS valor_mensual,
            ci.fecha_inicio, ci.fecha_fin, ci.activo
     FROM costos_indirectos ci
     WHERE ci.fecha_inicio <= :fin
       AND (ci.fecha_fin IS NULL OR ci.fecha_fin >= :ini)
     ORDER BY ci.nombre, ci.fecha_inicio"
);
$stmt_cos->execute([':ini' => $fecha_desde, ':fin' => $fecha_hasta]);
$costos_hist = $stmt_cos->fetchAll();

// Agrupar por nombre para calcular variación entre vigencias del mismo concepto
$costos_por_nombre = [];
foreach ($costos_hist as $row) {
    $key = $row['nombre'];
    if (!isset($costos_por_nombre[$key])) {
        $costos_por_nombre[$key] = [];
    }
    $prev = !empty($costos_por_nombre[$key])
            ? (float)end($costos_por_nombre[$key])['valor_mensual']
            : null;
    $val_mes = (float)$row['valor_mensual'];
    $var     = ($prev !== null && $prev > 0)
               ? round(($val_mes - $prev) / $prev * 100, 1)
               : null;
    $costos_por_nombre[$key][] = array_merge($row, ['var' => $var]);
}

// ── 5. HISTORIAL DE CAMBIOS EN ACTIVOS ───────────────────────────────────────
// Fuente: logs_historial — el trigger trg_activos_deprec_update registra cada vez
// que cambia costo_inicial o vida_util_meses. Aquí también mostramos estado_fisico.
// Permite ver cómo evolucionó la depreciación de cada activo en el tiempo.
$activos_hist = [];
if ($seccion === 'activos') {
    $stmt_act = $pdo->prepare(
        "SELECT lh.registro_id AS activo_id,
                a.nombre,
                lh.campo,
                lh.valor_anterior,
                lh.valor_nuevo,
                lh.fecha_cambio,
                u.nombre AS usuario
         FROM logs_historial lh
         LEFT JOIN activos a ON a.id = lh.registro_id
         LEFT JOIN usuarios u ON u.id = lh.usuario_id
         WHERE lh.tabla = 'activos'
           AND lh.campo IN ('costo_inicial','vida_util_meses','estado_fisico','foto_url')
           AND lh.accion = 'UPDATE'
           AND lh.fecha_cambio BETWEEN :ini AND :fin
         ORDER BY a.nombre, lh.fecha_cambio"
    );
    $stmt_act->execute([':ini' => $f_ini, ':fin' => $f_fin]);
    foreach ($stmt_act->fetchAll() as $row) {
        $aid = $row['activo_id'];
        if (!isset($activos_hist[$aid])) {
            $activos_hist[$aid] = ['nombre' => $row['nombre'] ?? "Activo #{$aid}", 'cambios' => []];
        }
        $activos_hist[$aid]['cambios'][] = $row;
    }
}

// ── 6. HISTORIAL DE ABONOS CON CONTEXTO DE SALDO (FIADO) ─────────────────────
// Fuente: pagos_fiado — desde migración 034 incluye saldo_anterior y saldo_posterior.
// Permite ver cómo fue disminuyendo (o aumentando) la deuda de cada cliente.
$fiado_hist = [];
if ($seccion === 'fiado') {
    $tiene034p_rep = false;
    try {
        $tiene034p_rep = (int)$pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pagos_fiado'
               AND COLUMN_NAME='saldo_anterior'"
        )->fetchColumn() > 0;
    } catch (\Exception $e) {}

    $sql_fiado = $tiene034p_rep
        ? "SELECT c.id AS cliente_id, CONCAT(c.nombre, IF(c.apellido IS NOT NULL, CONCAT(' ', c.apellido), '')) AS nombre_cliente,
                  pf.monto, pf.metodo_pago, pf.saldo_anterior, pf.saldo_posterior, pf.created_at
           FROM pagos_fiado pf
           JOIN clientes c ON c.id = pf.cliente_id
           WHERE pf.created_at BETWEEN :ini AND :fin
           ORDER BY c.nombre, pf.created_at"
        : "SELECT c.id AS cliente_id, CONCAT(c.nombre, IF(c.apellido IS NOT NULL, CONCAT(' ', c.apellido), '')) AS nombre_cliente,
                  pf.monto, pf.metodo_pago, NULL AS saldo_anterior, NULL AS saldo_posterior, pf.created_at
           FROM pagos_fiado pf
           JOIN clientes c ON c.id = pf.cliente_id
           WHERE pf.created_at BETWEEN :ini AND :fin
           ORDER BY c.nombre, pf.created_at";
    $stmt_fiado = $pdo->prepare($sql_fiado);
    $stmt_fiado->execute([':ini' => $f_ini, ':fin' => $f_fin]);
    foreach ($stmt_fiado->fetchAll() as $row) {
        $cid = $row['cliente_id'];
        if (!isset($fiado_hist[$cid])) {
            $fiado_hist[$cid] = ['nombre' => $row['nombre_cliente'], 'abonos' => []];
        }
        $fiado_hist[$cid]['abonos'][] = $row;
    }
}

// ── EXPORTAR EXCEL ────────────────────────────────────────────────────────────
if (isset($_GET['export'])) {
    $w = new XlsxWriter();

    // ── Hoja 1: Insumos ──────────────────────────────────────────────────────
    $w->setSheet('Precios Insumos');
    $w->addRow(['ClanDestino ERP — Variación de Precios de Insumos'], true);
    $w->addRow(["Período: $fecha_desde al $fecha_hasta | Generado: " . date('d/m/Y H:i')]);
    $w->addEmptyRow();
    $w->addRow(['Insumo', 'Unidad', 'Fecha compra', 'Precio/u ($)', 'Cantidad', 'Proveedor', 'Var. %'], true);

    foreach ($insumos_hist as $ins) {
        foreach ($ins['registros'] as $r) {
            $var_str = $r['var'] !== null ? ($r['var'] > 0 ? '+' : '') . $r['var'] . '%' : '—';
            $w->addRow([
                $ins['nombre'], $ins['unidad'],
                $r['fecha'], $r['precio'], $r['cantidad'],
                $r['proveedor'], $var_str,
            ]);
        }
    }

    // ── Hoja 2: Precios de Venta ─────────────────────────────────────────────
    $w->setSheet('Precios Venta');
    $w->addRow(['ClanDestino ERP — Evolución de Precios de Venta'], true);
    $w->addRow(["Período: $fecha_desde al $fecha_hasta"]);
    $w->addEmptyRow();
    $w->addRow(['Producto', 'Nombre complementario', 'Fecha primer cobro', 'Precio ($)', 'Var. %'], true);
    foreach ($productos_precio as $p) {
        foreach ($p['registros'] as $r) {
            $var_str = $r['var'] !== null ? ($r['var'] > 0 ? '+' : '') . $r['var'] . '%' : '—';
            $w->addRow([$p['nombre'], $p['nombre2'] ?? '', $r['fecha'], $r['precio'], $var_str]);
        }
    }

    // ── Hoja 3: Costo de Producción ──────────────────────────────────────────
    $w->setSheet('Costo Producción');
    $w->addRow(['ClanDestino ERP — Evolución del Costo de Producción'], true);
    $w->addRow(["Período: $fecha_desde al $fecha_hasta"]);
    $w->addEmptyRow();
    $w->addRow(['Producto', 'Nombre complementario', 'Fecha lote', 'Costo/u ($)', 'Cantidad', 'Var. %'], true);
    foreach ($productos_costo as $p) {
        foreach ($p['registros'] as $r) {
            $var_str = $r['var'] !== null ? ($r['var'] > 0 ? '+' : '') . $r['var'] . '%' : '—';
            $w->addRow([$p['nombre'], $p['nombre2'] ?? '', $r['fecha'], $r['costo'], $r['cantidad'], $var_str]);
        }
    }

    // ── Hoja 4: Nómina ───────────────────────────────────────────────────────
    $w->setSheet('Nómina');
    $w->addRow(['ClanDestino ERP — Evolución de Salarios y Costo Laboral'], true);
    $w->addRow(["Período: $fecha_desde al $fecha_hasta"]);
    $w->addEmptyRow();
    $w->addRow(['Empleado', 'Contrato', 'Período', 'Salario base ($)', 'Costo total ($)', 'Var. salario %'], true);
    foreach ($nomina_hist as $emp) {
        foreach ($emp['registros'] as $r) {
            $var_str = $r['var'] !== null ? ($r['var'] > 0 ? '+' : '') . $r['var'] . '%' : '—';
            $w->addRow([
                $emp['nombre'], $emp['contrato'],
                $r['periodo'], $r['salario_base'], $r['costo_total'], $var_str,
            ]);
        }
    }

    // ── Hoja 5: Costos Fijos ─────────────────────────────────────────────────
    $w->setSheet('Costos Fijos');
    $w->addRow(['ClanDestino ERP — Variación de Costos Fijos e Indirectos'], true);
    $w->addRow(["Período: $fecha_desde al $fecha_hasta"]);
    $w->addEmptyRow();
    $w->addRow(['Concepto', 'Categoría', 'Clasificación', 'Tipo', 'Valor ($)', 'Frecuencia', 'Val. Mensual ($)', 'Desde', 'Hasta', 'Activo', 'Var. %'], true);
    foreach ($costos_por_nombre as $rows) {
        foreach ($rows as $r) {
            $var_str = $r['var'] !== null ? ($r['var'] > 0 ? '+' : '') . $r['var'] . '%' : '—';
            $w->addRow([
                $r['nombre'], $r['categoria'], $r['clasificacion'], $r['tipo'],
                (float)$r['valor'], $r['frecuencia'], (float)$r['valor_mensual'],
                $r['fecha_inicio'], $r['fecha_fin'] ?? 'Vigente',
                $r['activo'] ? 'Sí' : 'No', $var_str,
            ]);
        }
    }

    $w->download('ClanDestino_VariacionPrecios_' . $fecha_desde . '_' . $fecha_hasta . '.xlsx');
}

// ── Estadísticas resumen ──────────────────────────────────────────────────────
// Para las tarjetas KPI en la vista HTML

// Insumos con mayor alza (porcentaje máximo de aumento en el período)
$ins_alzas = [];
foreach ($insumos_hist as $ins) {
    $regs = $ins['registros'];
    if (count($regs) >= 2) {
        $primero = (float)$regs[0]['precio'];
        $ultimo  = (float)end($regs)['precio'];
        if ($primero > 0) {
            $ins_alzas[$ins['nombre']] = round(($ultimo - $primero) / $primero * 100, 1);
        }
    }
}
arsort($ins_alzas);
$top_alza = array_slice($ins_alzas, 0, 3, true);

// Variaciones de costos laborales
$nom_variaciones = 0;
foreach ($nomina_hist as $emp) {
    foreach ($emp['registros'] as $r) {
        if ($r['var'] !== null && abs((float)$r['var']) > 0.1) $nom_variaciones++;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Variación de Precios — <?= APP_NAME ?></title>
    <style>
        *, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }
        :root {
            --brand:#e94f37; --dark:#111827; --g2:#374151; --g5:#6b7280;
            --g8:#d1d5db; --g9:#f3f4f6; --white:#fff;
            --green:#059669; --yellow:#d97706; --red:#dc2626;
        }
        body { font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
               background:var(--g9); min-height:100vh; color:var(--dark); }
        .main { padding:16px 14px 60px; max-width:1100px; margin:0 auto; }
        /* Filtros */
        .filter-row { background:var(--white); border-radius:14px; padding:14px 18px;
                      display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;
                      margin-bottom:16px; box-shadow:0 1px 4px rgba(0,0,0,.06); }
        .fg { display:flex; flex-direction:column; gap:4px; }
        .fg label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--g5); }
        .fg input, .fg select { padding:9px 12px; border:2px solid var(--g8); border-radius:10px;
                                font-size:14px; outline:none; background:var(--white); }
        .fg input:focus, .fg select:focus { border-color:var(--brand); }
        .btn { padding:9px 16px; border:none; border-radius:10px; font-size:13px;
               font-weight:700; cursor:pointer; }
        .btn-ver { background:var(--brand); color:var(--white); }
        .btn-xl  { background:#16a34a; color:var(--white); text-decoration:none;
                   display:inline-block; padding:9px 16px; border-radius:10px;
                   font-size:13px; font-weight:700; }
        /* Tabs de sección */
        .tabs { display:flex; gap:6px; margin-bottom:16px; flex-wrap:wrap; }
        .tab { padding:8px 16px; border-radius:10px; font-size:13px; font-weight:700;
               cursor:pointer; border:2px solid var(--g8); background:var(--white);
               color:var(--g2); text-decoration:none; }
        .tab:hover { border-color:var(--brand); color:var(--brand); }
        .tab.active { background:var(--brand); color:var(--white); border-color:var(--brand); }
        /* KPI */
        .kpis { display:grid; grid-template-columns:repeat(3,1fr); gap:10px; margin-bottom:16px; }
        @media(max-width:600px){ .kpis { grid-template-columns:1fr 1fr; } }
        .kpi { background:var(--white); border-radius:14px; padding:14px 18px;
               box-shadow:0 1px 4px rgba(0,0,0,.06); }
        .kpi-n { font-size:20px; font-weight:800; }
        .kpi-l { font-size:11px; color:var(--g5); text-transform:uppercase; margin-top:2px; }
        /* Tarjetas de insumo */
        .ins-card { background:var(--white); border-radius:14px;
                    box-shadow:0 1px 4px rgba(0,0,0,.06);
                    margin-bottom:12px; overflow:hidden; }
        .ins-hdr { display:flex; justify-content:space-between; align-items:center;
                   padding:12px 18px; background:var(--g9); border-bottom:1px solid var(--g8);
                   cursor:pointer; }
        .ins-nombre { font-size:14px; font-weight:700; }
        .ins-meta   { font-size:11px; color:var(--g5); margin-top:2px; }
        .ins-body   { display:none; padding:0; }
        .ins-body.open { display:block; }
        /* Tablas */
        table { width:100%; border-collapse:collapse; }
        th { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.4px;
             color:var(--g5); padding:9px 14px; background:var(--g9);
             border-bottom:1px solid var(--g8); text-align:left; }
        th.r, td.r { text-align:right; }
        td { padding:9px 14px; border-bottom:1px solid var(--g9); font-size:13px; }
        tr:last-child td { border-bottom:none; }
        /* Badges de variación */
        .badge { font-size:10px; font-weight:700; padding:2px 7px; border-radius:20px; white-space:nowrap; }
        .var-up    { background:#fee2e2; color:#991b1b; }
        .var-down  { background:#d1fae5; color:#065f46; }
        .var-same  { background:#f3f4f6; color:#6b7280; }
        .var-first { background:#eff6ff; color:#1e40af; }
        /* Alertas */
        .info { background:#eff6ff; border-left:4px solid #3b82f6; padding:10px 14px;
                border-radius:0 8px 8px 0; margin:10px 0; font-size:13px; color:var(--g2); }

        /* ════════════════════════════════════════════════════════════════
           RESPONSIVE
           ════════════════════════════════════════════════════════════════ */
        .ins-card { overflow-x:auto; }
        table { min-width:380px; }

        @media (max-width:479px) {
            .main { padding:12px 10px 40px; }
            .filter-row { flex-direction:column; }
            .fg input, .fg select { width:100%; }
            .btn-ver, .btn-xl { width:100%; min-height:44px; text-align:center; }
            .kpis { grid-template-columns:1fr !important; }
            th, td { padding:7px 10px; font-size:12px; }
        }
        @media (min-width:480px) and (max-width:639px) {
            .kpis { grid-template-columns:1fr 1fr !important; }
        }
        @media (min-width:1600px) {
            .main { max-width:1400px; }
            th, td { font-size:14px; }
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/../app/views/nav.php'; ?>

<main class="main">

    <!-- Filtros -->
    <form class="filter-row" method="GET">
        <input type="hidden" name="seccion" value="<?= htmlspecialchars($seccion) ?>">
        <div class="fg">
            <label>Desde</label>
            <input type="date" name="desde" value="<?= htmlspecialchars($fecha_desde) ?>" max="<?= $hoy ?>">
        </div>
        <div class="fg">
            <label>Hasta</label>
            <input type="date" name="hasta" value="<?= htmlspecialchars($fecha_hasta) ?>" max="<?= $hoy ?>">
        </div>
        <button type="submit" class="btn btn-ver">Filtrar</button>
        <a href="?desde=<?= urlencode($fecha_desde) ?>&hasta=<?= urlencode($fecha_hasta) ?>&export=1"
           class="btn-xl">⬇ Exportar Excel</a>
    </form>

    <!-- Tabs de sección — 6 secciones desde migración 034 -->
    <div class="tabs">
        <?php
        $secciones = [
            'insumos'   => '🧂 Insumos',
            'productos' => '🥪 Productos',
            'nomina'    => '👤 Nómina',
            'costos'    => '💰 Costos Fijos',
            'activos'   => '🔧 Activos',
            'fiado'     => '💳 Fiado / Abonos',
        ];
        foreach ($secciones as $s => $label):
        ?>
        <a href="?seccion=<?= $s ?>&desde=<?= urlencode($fecha_desde) ?>&hasta=<?= urlencode($fecha_hasta) ?>"
           class="tab <?= $seccion === $s ? 'active' : '' ?>"><?= $label ?></a>
        <?php endforeach; ?>
    </div>

    <!-- ══════════════════════════════════════════════════════════════════════ -->
    <!--  SECCIÓN: INSUMOS                                                      -->
    <!-- ══════════════════════════════════════════════════════════════════════ -->
    <?php if ($seccion === 'insumos'): ?>

    <div class="kpis">
        <div class="kpi">
            <div class="kpi-n"><?= count($insumos_hist) ?></div>
            <div class="kpi-l">Insumos con compras</div>
        </div>
        <div class="kpi">
            <div class="kpi-n"><?= count($raw_ins) ?></div>
            <div class="kpi-l">Total compras registradas</div>
        </div>
        <div class="kpi">
            <div class="kpi-n" style="color:var(--red)"><?= count(array_filter($ins_alzas, fn($v) => $v > 0)) ?></div>
            <div class="kpi-l">Insumos con alza en período</div>
        </div>
    </div>

    <?php if (!empty($top_alza)): ?>
    <div class="info">
        <strong>Mayores alzas del período:</strong>
        <?php foreach ($top_alza as $nombre => $pct): ?>
        <span style="margin-left:12px">
            <?= htmlspecialchars($nombre) ?>
            <strong style="color:<?= $pct > 0 ? 'var(--red)' : 'var(--green)' ?>">
                <?= ($pct > 0 ? '+' : '') . $pct ?>%
            </strong>
        </span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (empty($insumos_hist)): ?>
    <div style="text-align:center;padding:40px;color:var(--g5);background:var(--white);border-radius:14px">
        Sin compras de insumos en el período seleccionado.
    </div>
    <?php else:
        foreach ($insumos_hist as $iid => $ins):
        $total_regs  = count($ins['registros']);
        $primero     = (float)$ins['registros'][0]['precio'];
        $ultimo      = (float)end($ins['registros'])['precio'];
        $var_total   = $primero > 0 ? round(($ultimo - $primero) / $primero * 100, 1) : null;
        $var_clase   = $var_total === null ? 'var-first' : ($var_total > 0 ? 'var-up' : ($var_total < 0 ? 'var-down' : 'var-same'));
        $var_txt     = $var_total !== null ? ($var_total > 0 ? '+' : '') . $var_total . '%' : 'Sin variación';
    ?>
    <div class="ins-card">
        <div class="ins-hdr" onclick="this.nextElementSibling.classList.toggle('open')">
            <div>
                <div class="ins-nombre"><?= htmlspecialchars($ins['nombre']) ?></div>
                <div class="ins-meta"><?= $ins['unidad'] ?> · <?= $total_regs ?> compra<?= $total_regs>1?'s':'' ?></div>
            </div>
            <div style="display:flex;align-items:center;gap:10px">
                <?php if ($var_total !== null): ?>
                <span class="badge <?= $var_clase ?>">
                    <?= ($var_total > 0 ? '↑' : ($var_total < 0 ? '↓' : '—')) ?> <?= abs($var_total) ?>% total
                </span>
                <?php endif; ?>
                <span style="font-size:14px;font-weight:800">$<?= number_format($ultimo,2,',','.') ?></span>
                <span style="color:var(--g5)">▾</span>
            </div>
        </div>
        <div class="ins-body">
            <table>
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Proveedor</th>
                        <!-- Columnas de presentación (migración 032) -->
                        <?php if ($tiene_pres_cols): ?>
                        <th>Empaque</th>
                        <th class="r">Und/Empaque</th>
                        <th class="r">Cant. Empaques</th>
                        <th class="r">Precio/Empaque</th>
                        <?php endif; ?>
                        <th class="r">Precio/Unidad</th>
                        <th class="r">Cantidad</th>
                        <th>Variación</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ins['registros'] as $r):
                        $vc = $r['var'] === null ? 'var-first'
                             : ($r['var'] > 0 ? 'var-up' : ($r['var'] < 0 ? 'var-down' : 'var-same'));
                        $vt = $r['var'] !== null ? ($r['var'] > 0 ? '+' : '') . $r['var'] . '%' : 'Primera';
                    ?>
                    <tr>
                        <td><?= $r['fecha'] ?></td>
                        <td><?= htmlspecialchars($r['proveedor']) ?></td>
                        <?php if ($tiene_pres_cols): ?>
                        <td style="color:var(--g5);font-size:12px">
                            <?= htmlspecialchars($r['presentacion'] ?? '—') ?>
                        </td>
                        <td class="r" style="font-size:12px">
                            <?= $r['cantidad_presentacion'] ? number_format($r['cantidad_presentacion'],2,',','.') : '—' ?>
                        </td>
                        <td class="r" style="font-size:12px">
                            <?= $r['cant_presentaciones'] ? number_format($r['cant_presentaciones'],2,',','.') : '—' ?>
                        </td>
                        <td class="r" style="font-size:12px">
                            <?= $r['precio_presentacion'] ? '$' . number_format($r['precio_presentacion'],0,',','.') : '—' ?>
                        </td>
                        <?php endif; ?>
                        <td class="r"><strong>$<?= number_format($r['precio'],2,',','.') ?></strong></td>
                        <td class="r"><?= number_format($r['cantidad'],2,',','.') ?> <?= htmlspecialchars($ins['unidad']) ?></td>
                        <td><span class="badge <?= $vc ?>"><?= $vt ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endforeach;
    endif; ?>

    <!-- ══════════════════════════════════════════════════════════════════════ -->
    <!--  SECCIÓN: PRODUCTOS                                                    -->
    <!-- ══════════════════════════════════════════════════════════════════════ -->
    <?php elseif ($seccion === 'productos'): ?>

    <?php if (empty($productos_precio) && empty($productos_costo)): ?>
    <div style="text-align:center;padding:40px;color:var(--g5);background:var(--white);border-radius:14px">
        Sin ventas ni producción en el período seleccionado.
    </div>
    <?php else: ?>

    <div style="margin-bottom:14px">
        <strong style="font-size:15px">Precio de venta efectivo</strong>
        <span style="font-size:12px;color:var(--g5);margin-left:8px">
            Precio cobrado al cliente — registrado en venta_detalles al momento de la venta
        </span>
    </div>

    <?php foreach ($productos_precio as $pid => $p): ?>
    <div class="ins-card">
        <div class="ins-hdr" onclick="this.nextElementSibling.classList.toggle('open')">
            <div>
                <div class="ins-nombre"><?= htmlspecialchars($p['nombre']) ?></div>
                <?php if (!empty($p['nombre2'])): ?>
                <div class="ins-meta"><?= htmlspecialchars($p['nombre2']) ?></div>
                <?php endif; ?>
            </div>
            <span style="color:var(--g5)">▾</span>
        </div>
        <div class="ins-body">
            <table>
                <thead><tr><th>Fecha 1er cobro</th><th class="r">Precio ($)</th><th>Variación</th></tr></thead>
                <tbody>
                    <?php foreach ($p['registros'] as $r):
                        $vc = $r['var'] === null ? 'var-first'
                             : ($r['var'] > 0 ? 'var-up' : ($r['var'] < 0 ? 'var-down' : 'var-same'));
                        $vt = $r['var'] !== null ? ($r['var'] > 0 ? '+' : '') . $r['var'] . '%' : 'Primera';
                    ?>
                    <tr>
                        <td><?= $r['fecha'] ?></td>
                        <td class="r"><strong>$<?= number_format($r['precio'],0,',','.') ?></strong></td>
                        <td><span class="badge <?= $vc ?>"><?= $vt ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endforeach; ?>

    <?php if (!empty($productos_costo)): ?>
    <div style="margin:20px 0 14px">
        <strong style="font-size:15px">Costo de producción por lote</strong>
        <span style="font-size:12px;color:var(--g5);margin-left:8px">
            Snapshot del costo_calculado al momento de registrar la tanda
        </span>
    </div>
    <?php foreach ($productos_costo as $pid => $p): ?>
    <div class="ins-card">
        <div class="ins-hdr" onclick="this.nextElementSibling.classList.toggle('open')">
            <div>
                <div class="ins-nombre"><?= htmlspecialchars($p['nombre']) ?></div>
                <?php if (!empty($p['nombre2'])): ?>
                <div class="ins-meta"><?= htmlspecialchars($p['nombre2']) ?></div>
                <?php endif; ?>
            </div>
            <span style="color:var(--g5)">▾</span>
        </div>
        <div class="ins-body">
            <table>
                <thead><tr><th>Fecha</th><th class="r">Costo/u ($)</th><th class="r">Cantidad</th><th>Variación</th></tr></thead>
                <tbody>
                    <?php foreach ($p['registros'] as $r):
                        $vc = $r['var'] === null ? 'var-first'
                             : ($r['var'] > 0 ? 'var-up' : ($r['var'] < 0 ? 'var-down' : 'var-same'));
                        $vt = $r['var'] !== null ? ($r['var'] > 0 ? '+' : '') . $r['var'] . '%' : 'Primera';
                    ?>
                    <tr>
                        <td><?= $r['fecha'] ?></td>
                        <td class="r"><strong>$<?= number_format($r['costo'],0,',','.') ?></strong></td>
                        <td class="r"><?= $r['cantidad'] ?></td>
                        <td><span class="badge <?= $vc ?>"><?= $vt ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endforeach;
    endif; ?>
    <?php endif; ?>

    <!-- ══════════════════════════════════════════════════════════════════════ -->
    <!--  SECCIÓN: NÓMINA                                                       -->
    <!-- ══════════════════════════════════════════════════════════════════════ -->
    <?php elseif ($seccion === 'nomina'): ?>

    <div class="kpis">
        <div class="kpi">
            <div class="kpi-n"><?= count($nomina_hist) ?></div>
            <div class="kpi-l">Empleados liquidados</div>
        </div>
        <div class="kpi">
            <div class="kpi-n"><?= $nom_variaciones ?></div>
            <div class="kpi-l">Cambios de salario</div>
        </div>
        <div class="kpi">
            <div class="kpi-n">$<?= number_format(array_sum(array_map(fn($e) => (float)end($e['registros'])['costo_total'], $nomina_hist)),0,',','.') ?></div>
            <div class="kpi-l">Costo laboral último período</div>
        </div>
    </div>

    <?php if (empty($nomina_hist)): ?>
    <div style="text-align:center;padding:40px;color:var(--g5);background:var(--white);border-radius:14px">
        Sin liquidaciones en el período seleccionado.
    </div>
    <?php else:
        foreach ($nomina_hist as $eid => $emp):
    ?>
    <div class="ins-card">
        <div class="ins-hdr" onclick="this.nextElementSibling.classList.toggle('open')">
            <div>
                <div class="ins-nombre"><?= htmlspecialchars($emp['nombre']) ?></div>
                <div class="ins-meta"><?= $emp['contrato'] ?></div>
            </div>
            <span style="color:var(--g5)">▾</span>
        </div>
        <div class="ins-body">
            <table>
                <thead>
                    <tr>
                        <th>Período</th>
                        <th>Contrato</th>
                        <?php if ($tiene033_rep): ?>
                        <th class="r">Tarifa/hora</th>
                        <th class="r">Horas trabajadas</th>
                        <th class="r">Valor proyecto</th>
                        <?php endif; ?>
                        <th class="r">Salario base</th>
                        <th class="r">Costo total</th>
                        <th>Var. salario</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($emp['registros'] as $r):
                        $vc = $r['var'] === null ? 'var-first'
                             : ($r['var'] > 0 ? 'var-up' : ($r['var'] < 0 ? 'var-down' : 'var-same'));
                        $vt = $r['var'] !== null ? ($r['var'] > 0 ? '+' : '') . $r['var'] . '%' : 'Primera liq.';
                    ?>
                    <tr>
                        <td><?= $r['periodo'] ?></td>
                        <td style="font-size:12px;color:var(--g5)"><?= htmlspecialchars($r['tipo_liq'] ?? '—') ?></td>
                        <?php if ($tiene033_rep): ?>
                        <td class="r" style="font-size:12px">
                            <?= $r['valor_hora_snap'] ? '$'.number_format($r['valor_hora_snap'],2,',','.') : '—' ?>
                        </td>
                        <td class="r" style="font-size:12px">
                            <?= $r['horas'] ? number_format($r['horas'],1,',','.') . ' h' : '—' ?>
                        </td>
                        <td class="r" style="font-size:12px">
                            <?= $r['valor_proj_snap'] ? '$'.number_format($r['valor_proj_snap'],0,',','.') : '—' ?>
                        </td>
                        <?php endif; ?>
                        <td class="r"><strong>$<?= number_format($r['salario_base'],0,',','.') ?></strong></td>
                        <td class="r">$<?= number_format($r['costo_total'],0,',','.') ?></td>
                        <td><span class="badge <?= $vc ?>"><?= $vt ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endforeach;
    endif; ?>

    <!-- ══════════════════════════════════════════════════════════════════════ -->
    <!--  SECCIÓN: COSTOS FIJOS                                                 -->
    <!-- ══════════════════════════════════════════════════════════════════════ -->
    <?php elseif ($seccion === 'costos'): ?>

    <div class="info">
        El modelo correcto para registrar un cambio de precio en un costo fijo es <strong>crear una nueva fila</strong>
        con la nueva vigencia, no editar la existente. Así se conserva el historial completo.
    </div>

    <?php if (empty($costos_por_nombre)): ?>
    <div style="text-align:center;padding:40px;color:var(--g5);background:var(--white);border-radius:14px">
        Sin costos fijos activos en el período seleccionado.
    </div>
    <?php else: ?>
    <div style="background:var(--white);border-radius:14px;box-shadow:0 1px 4px rgba(0,0,0,.06);overflow-x:auto">
        <table>
            <thead>
                <tr>
                    <th>Concepto</th>
                    <th>Categoría</th>
                    <th>Tipo</th>
                    <th class="r">Valor/mes ($)</th>
                    <th>Desde</th>
                    <th>Hasta</th>
                    <th>Estado</th>
                    <th>Var. %</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($costos_por_nombre as $rows):
                    foreach ($rows as $r):
                        $vc  = $r['var'] === null ? 'var-first'
                               : ($r['var'] > 0 ? 'var-up' : ($r['var'] < 0 ? 'var-down' : 'var-same'));
                        $vt  = $r['var'] !== null ? ($r['var'] > 0 ? '+' : '') . $r['var'] . '%' : '—';
                        $est = $r['activo'] ? '<span class="badge var-down">Activo</span>'
                                            : '<span class="badge var-same">Inactivo</span>';
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($r['nombre']) ?></strong></td>
                    <td><?= htmlspecialchars($r['categoria'] ?? '—') ?></td>
                    <td><?= $r['clasificacion'] ?> / <?= $r['tipo'] ?></td>
                    <td class="r"><strong>$<?= number_format((float)$r['valor_mensual'],0,',','.') ?></strong></td>
                    <td><?= $r['fecha_inicio'] ?></td>
                    <td><?= $r['fecha_fin'] ?? '<span style="color:var(--green)">Vigente</span>' ?></td>
                    <td><?= $est ?></td>
                    <td><span class="badge <?= $vc ?>"><?= $vt ?></span></td>
                </tr>
                <?php endforeach; endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- ══════════════════════════════════════════════════════════════════════ -->
    <!--  SECCIÓN: ACTIVOS — HISTORIAL DE CAMBIOS                               -->
    <!-- ══════════════════════════════════════════════════════════════════════ -->
    <?php elseif ($seccion === 'activos'): ?>

    <div class="info">
        Historial de cambios en activos fijos leído desde el registro de auditoría.
        Cada vez que se edita <strong>costo_inicial</strong> o <strong>vida_util_meses</strong>,
        el trigger registra el valor anterior y el nuevo. Así puedes ver cómo evolucionó la
        depreciación de cada equipo.
    </div>

    <?php if (empty($activos_hist)): ?>
    <div style="text-align:center;padding:40px;color:var(--g5);background:var(--white);border-radius:14px">
        Sin cambios en activos durante el período seleccionado.
    </div>
    <?php else: ?>
    <div style="background:var(--white);border-radius:14px;box-shadow:0 1px 4px rgba(0,0,0,.06);overflow-x:auto">
        <table>
            <thead>
                <tr>
                    <th>Activo</th>
                    <th>Campo modificado</th>
                    <th class="r">Valor anterior</th>
                    <th class="r">Valor nuevo</th>
                    <th>Fecha cambio</th>
                    <th>Usuario</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($activos_hist as $aid => $act): ?>
                <?php foreach ($act['cambios'] as $cambio): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($act['nombre']) ?></strong></td>
                    <td style="font-size:12px;color:var(--g5)"><?= htmlspecialchars($cambio['campo']) ?></td>
                    <td class="r" style="color:var(--red)">
                        <?php if (in_array($cambio['campo'],['costo_inicial','vida_util_meses'])): ?>
                        <?= $cambio['campo'] === 'costo_inicial'
                            ? '$' . number_format((float)$cambio['valor_anterior'],0,',','.')
                            : htmlspecialchars($cambio['valor_anterior'] ?? '—') . ' meses' ?>
                        <?php else: ?><?= htmlspecialchars($cambio['valor_anterior'] ?? '—') ?><?php endif; ?>
                    </td>
                    <td class="r" style="color:var(--green)">
                        <?php if (in_array($cambio['campo'],['costo_inicial','vida_util_meses'])): ?>
                        <?= $cambio['campo'] === 'costo_inicial'
                            ? '$' . number_format((float)$cambio['valor_nuevo'],0,',','.')
                            : htmlspecialchars($cambio['valor_nuevo'] ?? '—') . ' meses' ?>
                        <?php else: ?><?= htmlspecialchars($cambio['valor_nuevo'] ?? '—') ?><?php endif; ?>
                    </td>
                    <td style="font-size:12px;color:var(--g5)"><?= date('d/m/Y H:i', strtotime($cambio['fecha_cambio'])) ?></td>
                    <td style="font-size:12px"><?= htmlspecialchars($cambio['usuario'] ?? '—') ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- ══════════════════════════════════════════════════════════════════════ -->
    <!--  SECCIÓN: FIADO — HISTORIAL DE ABONOS CON CONTEXTO DE SALDO           -->
    <!-- ══════════════════════════════════════════════════════════════════════ -->
    <?php elseif ($seccion === 'fiado'): ?>

    <?php if (!$tiene034p_rep): ?>
    <div class="info" style="background:#fff7ed;border-color:#f97316">
        ⚠ Para ver el saldo anterior/posterior en cada abono, aplica la
        <strong>migración 034</strong> desde Admin → Base de Datos.
        Por ahora se muestran solo los abonos sin contexto de saldo.
    </div>
    <?php else: ?>
    <div class="info">
        Cada abono registrado incluye el saldo del cliente <strong>antes</strong> y <strong>después</strong>
        del pago. Permite verificar que cada abono fue aplicado correctamente.
    </div>
    <?php endif; ?>

    <?php if (empty($fiado_hist)): ?>
    <div style="text-align:center;padding:40px;color:var(--g5);background:var(--white);border-radius:14px">
        Sin abonos registrados en el período seleccionado.
    </div>
    <?php else: ?>
    <?php foreach ($fiado_hist as $cid => $cli): ?>
    <div class="ins-card" style="margin-bottom:12px">
        <div class="ins-hdr" onclick="this.nextElementSibling.classList.toggle('open')">
            <div>
                <div class="ins-nombre"><?= htmlspecialchars($cli['nombre']) ?></div>
                <div class="ins-meta"><?= count($cli['abonos']) ?> abono<?= count($cli['abonos'])>1?'s':'' ?></div>
            </div>
            <div>
                <strong>Total abonado: $<?= number_format(array_sum(array_column($cli['abonos'],'monto')),0,',','.') ?></strong>
                <span style="color:var(--g5);margin-left:8px">▾</span>
            </div>
        </div>
        <div class="ins-body">
            <table>
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th class="r">Monto abonado</th>
                        <th>Método</th>
                        <?php if ($tiene034p_rep): ?>
                        <th class="r" style="color:var(--red)">Saldo anterior</th>
                        <th class="r" style="color:var(--green)">Saldo posterior</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cli['abonos'] as $ab): ?>
                    <tr>
                        <td><?= date('d/m/Y H:i', strtotime($ab['created_at'])) ?></td>
                        <td class="r"><strong>$<?= number_format((float)$ab['monto'],0,',','.') ?></strong></td>
                        <td style="font-size:12px;text-transform:capitalize"><?= htmlspecialchars($ab['metodo_pago']) ?></td>
                        <?php if ($tiene034p_rep): ?>
                        <td class="r" style="color:var(--red);font-size:12px">
                            <?= $ab['saldo_anterior'] !== null ? '$'.number_format((float)$ab['saldo_anterior'],0,',','.') : '—' ?>
                        </td>
                        <td class="r" style="color:var(--green);font-size:12px">
                            <?= $ab['saldo_posterior'] !== null ? '$'.number_format((float)$ab['saldo_posterior'],0,',','.') : '—' ?>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <?php endif; // fin de todas las secciones ?>

</main>

<script>
/* Abrir primera tarjeta de cada sección por defecto */
document.addEventListener('DOMContentLoaded', function() {
    var primera = document.querySelector('.ins-body');
    if (primera) primera.classList.add('open');
});
</script>
</body>
</html>
