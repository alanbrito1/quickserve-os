<?php
/**
 * public_html/reportes/nomina.php
 * Reporte de Nómina mensual con exportación a .xlsx.
 * Incluye todos los 12 componentes prestacionales por empleado.
 */

require_once __DIR__ . '/../app/middleware/auth_check.php';
require_once __DIR__ . '/../app/models/NominaModel.php';
require_once __DIR__ . '/../app/helpers/XlsxWriter.php';

permiso_requerir('reportes', 'solo_ver');

$mes  = (int)($_GET['mes']  ?? date('n'));
$anio = (int)($_GET['anio'] ?? date('Y'));
$mes  = max(1, min(12, $mes));

$liquidaciones = NominaModel::liquidaciones_periodo($mes, $anio);
$resumen       = NominaModel::resumen_periodo($mes, $anio);

$meses_nombres = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',
                  7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];

// ── EXPORTAR EXCEL ──────────────────────────────────────────────────────────
if (isset($_GET['export'])) {
    $w = new XlsxWriter();
    $w->setSheet('Nómina ' . $meses_nombres[$mes] . ' ' . $anio);

    $w->addRow(['ClanDestino ERP — Nómina ' . $meses_nombres[$mes] . ' ' . $anio], true);
    $w->addRow(['Generado: ' . date('d/m/Y H:i') . '  |  Empleados: ' . count($liquidaciones)]);
    $w->addEmptyRow();

    $headers = [
        'Empleado', 'Cargo', 'Tipo Contrato',
        'Salario Base', 'Aux. Transporte',
        'Salud (8.5%)', 'Pensión (12%)', 'ARL (0.522%)', 'Caja (4%)', 'ICBF (3%)', 'SENA (2%)',
        'Total Cargas',
        'Prima (8.33%)', 'Cesantías (8.33%)', 'Int. Ces. (1%)', 'Vacaciones (4.17%)',
        'Total Provisiones',
        'COSTO TOTAL EMPLEADOR',
    ];
    $w->addRow($headers, true);

    foreach ($liquidaciones as $liq) {
        $w->addRow([
            $liq['nombre_completo'],
            $liq['cargo'] ?? '',
            (float)$liq['salario_base'],
            (float)$liq['aux_transporte'],
            (float)$liq['salud_empleador'],
            (float)$liq['pension_empleador'],
            (float)$liq['arl'],
            (float)$liq['caja_compensacion'],
            (float)$liq['icbf'],
            (float)$liq['sena'],
            (float)$liq['total_cargas'],
            (float)$liq['prima'],
            (float)$liq['cesantias'],
            (float)$liq['intereses_cesantias'],
            (float)$liq['vacaciones'],
            (float)$liq['total_provisiones'],
            (float)$liq['costo_total_empleador'],
        ]);
    }

    // Fila de totales
    if (!empty($liquidaciones)) {
        $w->addRow([
            'TOTALES (' . count($liquidaciones) . ' empleados)', '',
            (float)$resumen['total_salarios'],
            (float)$resumen['total_aux'],
            '', '', '', '', '', '',
            (float)$resumen['total_cargas'],
            '', '', '', '',
            (float)$resumen['total_provisiones'],
            (float)$resumen['costo_total'],
        ], false, true);
    }

    // Hoja 2: Resumen por componente
    $w->setSheet('Resumen');
    $w->addRow(['ClanDestino ERP — Resumen Nómina ' . $meses_nombres[$mes] . ' ' . $anio], true);
    $w->addEmptyRow();
    $w->addRow(['Componente', 'Monto Total', '% sobre Salarios'], true);

    $sal = (float)$resumen['total_salarios'];
    $componentes = [
        'Salarios Base'        => $sal,
        'Aux. de Transporte'   => (float)$resumen['total_aux'],
        'Total Cargas (emp.)'  => (float)$resumen['total_cargas'],
        'Total Provisiones'    => (float)$resumen['total_provisiones'],
    ];
    foreach ($componentes as $nombre => $monto) {
        $pct = $sal > 0 ? round($monto / $sal * 100, 1) : 0;
        $w->addRow([$nombre, $monto, $pct]);
    }
    $w->addEmptyRow();
    $w->addRow(['COSTO TOTAL EMPLEADOR', (float)$resumen['costo_total'], ''], false, true);

    $w->download('ClanDestino_Nomina_' . $mes . '_' . $anio . '.xlsx');
}

$fmt = fn(float $n) => '$' . number_format($n, 0, ',', '.');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reporte Nómina — <?= APP_NAME ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root { --brand:#e94f37; --dark:#111827; --g2:#374151; --g5:#6b7280; --g8:#d1d5db; --g9:#f3f4f6; --white:#fff; }
        body { font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; background:var(--g9); min-height:100vh; color:var(--dark); }
        .hdr { background:var(--dark); color:var(--white); height:54px; padding:0 14px; display:flex; align-items:center; justify-content:space-between; position:sticky; top:0; z-index:50; box-shadow:0 2px 8px rgba(0,0,0,.3); }
        .brand { font-size:17px; font-weight:800; } .brand span{color:var(--brand);}
        .nl { color:var(--g8); text-decoration:none; font-size:13px; padding:5px 10px; border-radius:8px; }
        .nl:hover { background:var(--g2); color:var(--white); }
        .main { padding:16px 14px; max-width:1100px; margin:0 auto; }
        .filter-row { background:var(--white); border-radius:14px; padding:14px 18px; display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; margin-bottom:16px; box-shadow:0 1px 4px rgba(0,0,0,.06); }
        .fg { display:flex; flex-direction:column; gap:4px; }
        .fg label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--g5); }
        .fg select { padding:9px 12px; border:2px solid var(--g8); border-radius:10px; font-size:14px; outline:none; -webkit-appearance:none; }
        .btn-ver { padding:9px 16px; background:var(--brand); color:var(--white); border:none; border-radius:10px; font-size:13px; font-weight:700; cursor:pointer; }
        .btn-xl  { padding:9px 16px; background:#16a34a; color:var(--white); border:none; border-radius:10px; font-size:13px; font-weight:700; text-decoration:none; }
        .stats { display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:16px; }
        @media(max-width:600px){ .stats { grid-template-columns:1fr 1fr; } }
        .stat { background:var(--white); border-radius:14px; padding:14px; box-shadow:0 1px 4px rgba(0,0,0,.06); }
        .stat-n { font-size:18px; font-weight:800; }
        .stat-l { font-size:11px; color:var(--g5); text-transform:uppercase; letter-spacing:.4px; }
        /* Tabla horizontal scroll en mobile */
        .table-wrap { overflow-x:auto; }
        .card { background:var(--white); border-radius:14px; box-shadow:0 1px 4px rgba(0,0,0,.06); overflow:hidden; margin-bottom:16px; }
        table { width:100%; border-collapse:collapse; min-width:900px; }
        th { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; color:var(--g5); padding:9px 10px; background:var(--g9); border-bottom:1px solid var(--g8); text-align:right; white-space:nowrap; }
        th:first-child, th:nth-child(2) { text-align:left; }
        td { padding:9px 10px; border-bottom:1px solid var(--g9); font-size:12px; text-align:right; white-space:nowrap; }
        td:first-child, td:nth-child(2) { text-align:left; }
        tr:last-child td { border-bottom:none; }
        tr.total-row { background:var(--g9); font-weight:800; }
        tr.total-row td { font-size:12px; }
        .empty { text-align:center; padding:40px; color:var(--g5); }
    </style>
</head>
<body>
<?php $nav_activo = 'reportes'; include __DIR__ . '/../app/views/nav.php'; ?>

<!-- header reemplazado por nav.php -->
<main class="main">

    <!-- Filtros -->
    <form class="filter-row" method="GET">
        <div class="fg">
            <label>Mes</label>
            <select name="mes">
                <?php foreach ($meses_nombres as $n => $nombre): ?>
                <option value="<?= $n ?>" <?= $n == $mes ? 'selected' : '' ?>><?= $nombre ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="fg">
            <label>Año</label>
            <select name="anio">
                <?php for ($y = date('Y') + 1; $y >= 2024; $y--): ?>
                <option value="<?= $y ?>" <?= $y == $anio ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <button type="submit" class="btn-ver">Ver</button>
        <?php if (!empty($liquidaciones)): ?>
        <a href="?mes=<?= $mes ?>&anio=<?= $anio ?>&export=1" class="btn-xl">⬇ Exportar Excel</a>
        <?php endif; ?>
    </form>

    <!-- Stats -->
    <div class="stats">
        <div class="stat"><div class="stat-n"><?= (int)($resumen['num_empleados'] ?? 0) ?></div><div class="stat-l">Empleados</div></div>
        <div class="stat"><div class="stat-n"><?= $fmt((float)($resumen['total_salarios']   ?? 0)) ?></div><div class="stat-l">Total Salarios</div></div>
        <div class="stat"><div class="stat-n"><?= $fmt((float)($resumen['total_cargas']     ?? 0)) ?></div><div class="stat-l">Total Cargas</div></div>
        <div class="stat"><div class="stat-n" style="color:var(--brand)"><?= $fmt((float)($resumen['costo_total'] ?? 0)) ?></div><div class="stat-l">Costo Total</div></div>
    </div>

    <!-- Tabla con todos los componentes -->
    <div class="card">
        <div class="table-wrap">
            <?php if (empty($liquidaciones)): ?>
            <div class="empty">Sin liquidaciones para <?= $meses_nombres[$mes] ?> <?= $anio ?>.<br>
                <a href="<?= APP_BASE ?>/nomina/" style="color:var(--brand)">Generar nómina →</a>
            </div>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th style="text-align:left">Empleado</th>
                        <th style="text-align:left; min-width:80px">Cargo</th>
                        <th>Contrato</th>
                        <th>Salario</th>
                        <th>Aux.</th>
                        <th>Salud</th>
                        <th>Pensión</th>
                        <th>ARL</th>
                        <th>Caja</th>
                        <th>ICBF</th>
                        <th>SENA</th>
                        <th>Σ Cargas</th>
                        <th>Prima</th>
                        <th>Ces.</th>
                        <th>Int.Ces</th>
                        <th>Vac.</th>
                        <th>Σ Prov.</th>
                        <th style="color:var(--brand)">TOTAL</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($liquidaciones as $liq): ?>
                    <tr>
                        <td style="font-weight:700"><?= htmlspecialchars($liq['nombre_completo']) ?></td>
                        <td><?= htmlspecialchars($liq['cargo'] ?? '—') ?></td>
                        <td><?= $fmt($liq['salario_base']) ?></td>
                        <td><?= $fmt($liq['aux_transporte']) ?></td>
                        <td><?= $fmt($liq['salud_empleador']) ?></td>
                        <td><?= $fmt($liq['pension_empleador']) ?></td>
                        <td><?= $fmt($liq['arl']) ?></td>
                        <td><?= $fmt($liq['caja_compensacion']) ?></td>
                        <td><?= $fmt($liq['icbf']) ?></td>
                        <td><?= $fmt($liq['sena']) ?></td>
                        <td style="font-weight:700"><?= $fmt($liq['total_cargas']) ?></td>
                        <td><?= $fmt($liq['prima']) ?></td>
                        <td><?= $fmt($liq['cesantias']) ?></td>
                        <td><?= $fmt($liq['intereses_cesantias']) ?></td>
                        <td><?= $fmt($liq['vacaciones']) ?></td>
                        <td style="font-weight:700"><?= $fmt($liq['total_provisiones']) ?></td>
                        <td style="font-weight:800; color:var(--brand)"><?= $fmt($liq['costo_total_empleador']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <!-- Fila de totales -->
                    <tr class="total-row">
                        <td>TOTALES</td><td></td>
                        <td><?= $fmt($resumen['total_salarios']   ?? 0) ?></td>
                        <td><?= $fmt($resumen['total_aux']        ?? 0) ?></td>
                        <td colspan="6"></td>
                        <td><?= $fmt($resumen['total_cargas']     ?? 0) ?></td>
                        <td colspan="4"></td>
                        <td><?= $fmt($resumen['total_provisiones']?? 0) ?></td>
                        <td style="color:var(--brand)"><?= $fmt($resumen['costo_total'] ?? 0) ?></td>
                    </tr>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

</main>
</body>
</html>
