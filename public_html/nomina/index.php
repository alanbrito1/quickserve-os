<?php
/**
 * public_html/nomina/index.php
 * Panel principal de nómina: generación mensual y vista de liquidaciones.
 */

require_once __DIR__ . '/../app/middleware/auth_check.php';
require_once __DIR__ . '/../app/models/NominaModel.php';

permiso_requerir('nomina', 'solo_ver');

// Período seleccionado (default: mes actual)
$mes  = (int)($_GET['mes']  ?? date('n'));
$anio = (int)($_GET['anio'] ?? date('Y'));
$mes  = max(1, min(12, $mes));

// Cargar liquidaciones del período
$liquidaciones = NominaModel::liquidaciones_periodo($mes, $anio);
$resumen       = NominaModel::resumen_periodo($mes, $anio);
$empleados_act = count(NominaModel::empleados_activos());

$meses_nombres = [
    1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo',     4 => 'Abril',
    5 => 'Mayo',  6 => 'Junio',   7 => 'Julio',      8 => 'Agosto',
    9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
];

// Mensaje de respuesta AJAX viene en query string tras redirect
$msg_ok  = $_GET['ok']  ?? '';
$msg_err = $_GET['err'] ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nómina — <?= APP_NAME ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --brand:#e94f37; --dark:#111827; --g2:#374151; --g5:#6b7280;
            --g8:#d1d5db; --g9:#f3f4f6; --white:#fff;
            --green:#059669; --yellow:#d97706; --blue:#2563eb;
        }
        body { font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; background:var(--g9); min-height:100vh; color:var(--dark); }

        /* Header */
        .hdr { background:var(--dark); color:var(--white); height:54px; padding:0 14px; display:flex; align-items:center; justify-content:space-between; position:sticky; top:0; z-index:50; box-shadow:0 2px 8px rgba(0,0,0,.3); }
        .brand { font-size:17px; font-weight:800; } .brand span{color:var(--brand);}
        .nav { display:flex; gap:6px; }
        .nl { color:var(--g8); text-decoration:none; font-size:13px; padding:5px 10px; border-radius:8px; }
        .nl:hover { background:var(--g2); color:var(--white); } .nl.act { background:var(--brand); color:var(--white); }

        .main { padding:16px 14px; max-width:960px; margin:0 auto; }

        /* Alertas */
        .alert { padding:12px 14px; border-radius:10px; font-size:14px; margin-bottom:14px; }
        .alert-ok  { background:#d1fae5; color:#065f46; border:1px solid #6ee7b7; }
        .alert-err { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; }

        /* Selector de período */
        .periodo-bar { background:var(--white); border-radius:14px; padding:14px 18px; display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:16px; box-shadow:0 1px 4px rgba(0,0,0,.06); }
        .periodo-bar label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--g5); }
        .periodo-bar select { padding:8px 12px; border:2px solid var(--g8); border-radius:10px; font-size:14px; color:var(--dark); outline:none; -webkit-appearance:none; }
        .periodo-bar select:focus { border-color:var(--brand); }
        .btn-ver { padding:9px 16px; background:var(--brand); color:var(--white); border:none; border-radius:10px; font-size:13px; font-weight:700; cursor:pointer; }

        /* Banner resumen */
        .resumen-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:10px; margin-bottom:16px; }
        @media(max-width:520px){ .resumen-grid { grid-template-columns:1fr 1fr; } }
        .stat-card { background:var(--white); border-radius:14px; padding:16px; box-shadow:0 1px 4px rgba(0,0,0,.06); }
        .stat-n { font-size:22px; font-weight:800; }
        .stat-l { font-size:11px; color:var(--g5); text-transform:uppercase; letter-spacing:.5px; margin-top:3px; }

        /* Botón generar */
        .generar-wrap { margin-bottom:16px; }
        .btn-generar { padding:14px 28px; background:var(--green); color:var(--white); border:none; border-radius:12px; font-size:15px; font-weight:800; cursor:pointer; transition:background .15s; }
        .btn-generar:hover { background:#047857; }
        .btn-generar:disabled { background:var(--g8); cursor:not-allowed; }

        /* Tabla de liquidaciones */
        .card { background:var(--white); border-radius:14px; box-shadow:0 1px 4px rgba(0,0,0,.06); overflow:hidden; }
        .card-title { font-size:15px; font-weight:800; padding:14px 18px; border-bottom:1px solid var(--g9); display:flex; justify-content:space-between; align-items:center; }

        table { width:100%; border-collapse:collapse; }
        th { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--g5); padding:10px 14px; background:var(--g9); border-bottom:1px solid var(--g8); text-align:left; }
        th.r, td.r { text-align:right; }
        td { padding:12px 14px; border-bottom:1px solid var(--g9); font-size:14px; vertical-align:middle; }
        tr:last-child td { border-bottom:none; }
        @media(max-width:600px){ .hide-m { display:none; } }

        /* Expandible */
        .exp-btn { background:none; border:none; cursor:pointer; color:var(--g5); font-size:16px; padding:0 6px; }
        .exp-btn:hover { color:var(--brand); }
        .exp-row { display:none; }
        .exp-row.open { display:table-row; }
        .exp-row > td { background:#fafafa; padding:16px 20px; }

        /* Desglose de nómina */
        .desglose { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
        @media(max-width:520px){ .desglose { grid-template-columns:1fr; } }
        .desglose-col h4 { font-size:12px; font-weight:800; text-transform:uppercase; letter-spacing:.5px; color:var(--g5); margin-bottom:8px; }
        .drow { display:flex; justify-content:space-between; font-size:13px; padding:4px 0; border-bottom:1px dashed var(--g8); }
        .drow:last-child { border-bottom:none; }
        .drow.subtotal { font-weight:800; font-size:13px; border-top:1px solid var(--dark); border-bottom:none; padding-top:6px; margin-top:4px; }
        .drow.total-final { font-size:15px; font-weight:800; color:var(--brand); }
        .desglose-total { margin-top:12px; padding-top:12px; border-top:2px solid var(--dark); }

        /* Estado vacío */
        .empty { text-align:center; padding:40px 16px; color:var(--g5); }

        /* Toast */
        .toast { position:fixed; bottom:20px; left:50%; transform:translateX(-50%) translateY(20px); padding:10px 22px; border-radius:24px; font-size:14px; font-weight:600; opacity:0; transition:.25s; z-index:99; pointer-events:none; max-width:90vw; }
        .toast.on { opacity:1; transform:translateX(-50%) translateY(0); }
        .toast-ok  { background:#065f46; color:#d1fae5; }
        .toast-err { background:#991b1b; color:#fee2e2; }

        /* Delete button */
        .btn-del { padding:5px 10px; background:#fee2e2; color:#991b1b; border:none; border-radius:8px; font-size:12px; font-weight:700; cursor:pointer; }
        .btn-del:hover { background:#fca5a5; }
    </style>
</head>
<body>
<?php $nav_activo = 'nomina'; $nav_sub = 'nomina'; include __DIR__ . '/../app/views/nav.php'; ?>


<main class="main">


    <?php if ($msg_ok):  ?><div class="alert alert-ok"><?= htmlspecialchars(urldecode($msg_ok)) ?></div><?php endif; ?>
    <?php if ($msg_err): ?><div class="alert alert-err"><?= htmlspecialchars(urldecode($msg_err)) ?></div><?php endif; ?>

    <!-- Selector de período -->
    <form class="periodo-bar" method="GET">
        <label>Período:</label>
        <select name="mes">
            <?php foreach ($meses_nombres as $n => $nombre): ?>
            <option value="<?= $n ?>" <?= $n == $mes ? 'selected' : '' ?>><?= $nombre ?></option>
            <?php endforeach; ?>
        </select>
        <select name="anio">
            <?php for ($y = date('Y') + 1; $y >= 2024; $y--): ?>
            <option value="<?= $y ?>" <?= $y == $anio ? 'selected' : '' ?>><?= $y ?></option>
            <?php endfor; ?>
        </select>
        <button type="submit" class="btn-ver">Ver</button>
    </form>

    <!-- Resumen del período -->
    <div class="resumen-grid">
        <div class="stat-card">
            <div class="stat-n"><?= (int)($resumen['num_empleados'] ?? 0) ?> / <?= $empleados_act ?></div>
            <div class="stat-l">Empleados liquidados</div>
        </div>
        <div class="stat-card">
            <div class="stat-n">$<?= number_format($resumen['total_salarios'] ?? 0, 0, ',', '.') ?></div>
            <div class="stat-l">Total salarios</div>
        </div>
        <div class="stat-card" style="grid-column: span 1">
            <div class="stat-n" style="color:var(--brand)">$<?= number_format($resumen['costo_total'] ?? 0, 0, ',', '.') ?></div>
            <div class="stat-l">Costo real ClanDestino</div>
        </div>
    </div>

    <!-- Botón generar -->
    <?php if (permiso_tiene('nomina', 'editar_existentes')): ?>
    <div class="generar-wrap">
        <button class="btn-generar" id="btn-generar"
                onclick="generarNomina(<?= $mes ?>, <?= $anio ?>)">
            ⚡ Generar Nómina — <?= $meses_nombres[$mes] ?> <?= $anio ?>
        </button>
        <span style="font-size:12px; color:var(--g5); margin-left:12px">
            Genera liquidación para empleados activos sin nómina del período.
        </span>
    </div>
    <?php endif; ?>

    <!-- Tabla de liquidaciones -->
    <div class="card">
        <div class="card-title">
            <span>Liquidaciones — <?= $meses_nombres[$mes] ?> <?= $anio ?></span>
            <?php if (!empty($liquidaciones) && permiso_tiene('nomina','admin_total')): ?>
            <button class="btn-del" onclick="eliminarPeriodo(<?= $mes ?>, <?= $anio ?>)">
                Eliminar período
            </button>
            <?php endif; ?>
        </div>

        <?php if (empty($liquidaciones)): ?>
        <div class="empty">
            <p style="font-size:28px; margin-bottom:10px">📋</p>
            <p style="font-weight:600">Sin liquidaciones para este período</p>
            <?php if (permiso_tiene('nomina','editar_existentes')): ?>
            <p style="font-size:13px; margin-top:8px; color:var(--g5)">
                Usa el botón "Generar Nómina" para calcularlas.
            </p>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <table>
            <?php
            $TIPO_LABELS = [
                'tiempo_completo' => ['label'=>'Tiempo completo', 'bg'=>'#dbeafe','c'=>'#1d4ed8'],
                'medio_tiempo'    => ['label'=>'Medio tiempo',    'bg'=>'#e0e7ff','c'=>'#4338ca'],
                'por_horas'       => ['label'=>'Por horas',        'bg'=>'#fef3c7','c'=>'#92400e'],
                'por_dias'        => ['label'=>'Por días',         'bg'=>'#fef3c7','c'=>'#92400e'],
                'por_servicio'    => ['label'=>'Por servicio',     'bg'=>'#f3f4f6','c'=>'#6b7280'],
            ];
            ?>
            <thead>
                <tr>
                    <th>Empleado</th>
                    <th class="hide-m">Contrato</th>
                    <th class="r hide-m">Salario/Pago</th>
                    <th class="r hide-m">Cargas</th>
                    <th class="r hide-m">Provisiones</th>
                    <th class="r">Costo Total</th>
                    <th>Estado</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($liquidaciones as $liq):
                    $tipo    = $liq['contrato_usado'] ?? $liq['tipo_contrato'] ?? 'tiempo_completo';
                    $tipoCfg = $TIPO_LABELS[$tipo] ?? $TIPO_LABELS['tiempo_completo'];
                    $esPagado= (bool)($liq['pagado'] ?? false);
                ?>
                <tr>
                    <td>
                        <strong><?= htmlspecialchars($liq['nombre_completo']) ?></strong>
                        <?php if ($liq['cargo']): ?>
                        <br><small style="color:var(--g5)"><?= htmlspecialchars($liq['cargo']) ?></small>
                        <?php endif; ?>
                        <?php if ($tipo === 'por_horas' && !empty($liq['horas_trabajadas'])): ?>
                        <br><small style="color:#d97706">⏱ <?= $liq['horas_trabajadas'] ?>h trabajadas</small>
                        <?php elseif ($tipo === 'por_servicio' && !empty($liq['descripcion_pago'])): ?>
                        <br><small style="color:var(--g5)"><?= htmlspecialchars(substr($liq['descripcion_pago'],0,50)) ?></small>
                        <?php endif; ?>
                    </td>
                    <td class="hide-m">
                        <span style="font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;
                                     background:<?= $tipoCfg['bg'] ?>;color:<?= $tipoCfg['c'] ?>">
                            <?= $tipoCfg['label'] ?>
                        </span>
                    </td>
                    <td class="r hide-m">$<?= number_format($liq['salario_base'], 0, ',', '.') ?></td>
                    <td class="r hide-m">$<?= number_format($liq['total_cargas'], 0, ',', '.') ?></td>
                    <td class="r hide-m">$<?= number_format($liq['total_provisiones'], 0, ',', '.') ?></td>
                    <td class="r" style="font-weight:800; color:var(--brand)">
                        $<?= number_format($liq['costo_total_empleador'], 0, ',', '.') ?>
                    </td>
                    <td>
                        <?php if ($esPagado): ?>
                        <span style="font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;
                                     background:#d1fae5;color:#065f46">&#10003; Pagado</span>
                        <?php if (!empty($liq['fecha_pago_nomina'])): ?>
                        <br><small style="color:var(--g5)"><?= date('d/m/Y', strtotime($liq['fecha_pago_nomina'])) ?></small>
                        <?php endif; ?>
                        <?php if (permiso_tiene('nomina','editar_existentes')): ?>
                        <br><button class="btn-del" style="margin-top:4px;font-size:10px"
                            onclick="cambiarPago(<?= $liq['id'] ?>, 'pendiente')">
                            Marcar pendiente
                        </button>
                        <?php endif; ?>
                        <?php else: ?>
                        <span style="font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;
                                     background:#fef3c7;color:#92400e">Pendiente</span>
                        <?php if (permiso_tiene('nomina','editar_existentes')): ?>
                        <br><button class="btn-del"
                            style="margin-top:4px;font-size:10px;background:#d1fae5;color:#065f46"
                            onclick="cambiarPago(<?= $liq['id'] ?>, 'pagado')">
                            &#10003; Marcar pagado
                        </button>
                        <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <button class="exp-btn" onclick="toggleLiq(<?= $liq['id'] ?>)">&#9662;</button>
                    </td>
                </tr>
                <!-- Fila expandible con el desglose completo -->
                <tr class="exp-row" id="exp-<?= $liq['id'] ?>">
                    <td colspan="6">
                        <div class="desglose">
                            <!-- Columna Cargas -->
                            <div class="desglose-col">
                                <h4>Cargas del Empleador</h4>
                                <?php
                                $items_cargas = [
                                    'Salud (8.5%)'       => $liq['salud_empleador'],
                                    'Pensión (12%)'      => $liq['pension_empleador'],
                                    'ARL (0.522%)'       => $liq['arl'],
                                    'Caja (4%)'          => $liq['caja_compensacion'],
                                    'ICBF (3%)'          => $liq['icbf'],
                                    'SENA (2%)'          => $liq['sena'],
                                ];
                                foreach ($items_cargas as $lbl => $val): ?>
                                <div class="drow">
                                    <span><?= $lbl ?></span>
                                    <span>$<?= number_format($val, 0, ',', '.') ?></span>
                                </div>
                                <?php endforeach; ?>
                                <div class="drow subtotal">
                                    <span>Subtotal Cargas</span>
                                    <span>$<?= number_format($liq['total_cargas'], 0, ',', '.') ?></span>
                                </div>
                            </div>
                            <!-- Columna Provisiones -->
                            <div class="desglose-col">
                                <h4>Provisiones Mensuales</h4>
                                <?php
                                $items_prov = [
                                    'Prima (8.33%)'      => $liq['prima'],
                                    'Cesantías (8.33%)'  => $liq['cesantias'],
                                    'Int. Ces. (1%)'     => $liq['intereses_cesantias'],
                                    'Vacaciones (4.17%)' => $liq['vacaciones'],
                                ];
                                foreach ($items_prov as $lbl => $val): ?>
                                <div class="drow">
                                    <span><?= $lbl ?></span>
                                    <span>$<?= number_format($val, 0, ',', '.') ?></span>
                                </div>
                                <?php endforeach; ?>
                                <div class="drow subtotal">
                                    <span>Subtotal Provisiones</span>
                                    <span>$<?= number_format($liq['total_provisiones'], 0, ',', '.') ?></span>
                                </div>
                            </div>
                        </div>
                        <!-- Total final -->
                        <div class="desglose-total">
                            <div class="drow" style="font-size:12px; color:var(--g5)">
                                <span>Salario Base</span><span>$<?= number_format($liq['salario_base'], 0, ',', '.') ?></span>
                            </div>
                            <div class="drow" style="font-size:12px; color:var(--g5)">
                                <span>Auxilio Transporte</span><span>$<?= number_format($liq['aux_transporte'], 0, ',', '.') ?></span>
                            </div>
                            <div class="drow" style="font-size:12px; color:var(--g5)">
                                <span>+ Cargas</span><span>$<?= number_format($liq['total_cargas'], 0, ',', '.') ?></span>
                            </div>
                            <div class="drow" style="font-size:12px; color:var(--g5)">
                                <span>+ Provisiones</span><span>$<?= number_format($liq['total_provisiones'], 0, ',', '.') ?></span>
                            </div>
                            <div class="drow total-final">
                                <span>COSTO TOTAL EMPLEADOR</span>
                                <span>$<?= number_format($liq['costo_total_empleador'], 0, ',', '.') ?></span>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <!-- Fila de totales -->
                <?php if (!empty($liquidaciones)): ?>
                <tr style="background:var(--g9)">
                    <td><strong>TOTAL <?= count($liquidaciones) ?> EMPLEADOS</strong></td>
                    <td class="r hide-m"><strong>$<?= number_format($resumen['total_salarios'] ?? 0, 0, ',', '.') ?></strong></td>
                    <td class="r hide-m"><strong>$<?= number_format($resumen['total_cargas'] ?? 0, 0, ',', '.') ?></strong></td>
                    <td class="r hide-m"><strong>$<?= number_format($resumen['total_provisiones'] ?? 0, 0, ',', '.') ?></strong></td>
                    <td class="r" style="font-weight:800; font-size:16px; color:var(--brand)">
                        $<?= number_format($resumen['costo_total'] ?? 0, 0, ',', '.') ?>
                    </td>
                    <td></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

</main>

<div class="toast" id="toast"></div>

<input type="hidden" id="csrf-token" value="<?= htmlspecialchars(csrf_token()) ?>">

<script>
// ── Expandir / colapsar fila de desglose ─────────────────────────────────────
function toggleLiq(id) {
    const row = document.getElementById('exp-' + id);
    const btn = row.previousElementSibling?.querySelector('.exp-btn');
    row.classList.toggle('open');
    if (btn) btn.textContent = row.classList.contains('open') ? '▴' : '▾';
}

// ── Generar nómina del período ────────────────────────────────────────────────
async function generarNomina(mes, anio) {
    const btn = document.getElementById('btn-generar');
    btn.disabled = true;
    btn.textContent = '⏳ Generando…';

    const fd = new FormData();
    fd.append('csrf_token', document.getElementById('csrf-token').value);
    fd.append('mes',  mes);
    fd.append('anio', anio);

    try {
        const resp = await fetch('api/generar.php', { method: 'POST', body: fd });
        const data = await resp.json();

        if (data.success) {
            const gen  = data.generados?.length  || 0;
            const omit = data.omitidos?.length   || 0;
            const err  = data.errores?.length    || 0;

            let msg = '';
            if (gen  > 0) msg += `✓ ${gen} liquidacion${gen > 1 ? 'es' : ''} generada${gen > 1 ? 's' : ''}. `;
            if (omit > 0) msg += `${omit} ya existían. `;
            if (err  > 0) msg += `⚠ ${err} error${err > 1 ? 'es' : ''}: ${data.errores.join(', ')}.`;

            mostrarToast(msg || 'Nómina procesada', gen > 0 ? 'ok' : 'err');

            if (gen > 0) setTimeout(() => location.reload(), 1500);
        } else {
            mostrarToast(data.error || 'Error al generar nómina', 'err');
        }
    } catch(e) {
        mostrarToast('Error de conexión', 'err');
    } finally {
        btn.disabled = false;
        btn.textContent = '⚡ Generar Nómina — <?= $meses_nombres[$mes] . ' ' . $anio ?>';
    }
}

// ── Eliminar período completo ─────────────────────────────────────────────────
async function eliminarPeriodo(mes, anio) {
    if (!confirm(`¿Eliminar TODAS las liquidaciones de ${mes}/${anio}? Esta acción no se puede deshacer.`)) return;

    const fd = new FormData();
    fd.append('csrf_token', document.getElementById('csrf-token').value);
    fd.append('mes',    mes);
    fd.append('anio',   anio);
    fd.append('accion', 'eliminar_periodo');

    const resp = await fetch('api/generar.php', { method: 'POST', body: fd });
    // Re-usar el endpoint con una acción diferente — simplemente recargar
    mostrarToast('Período eliminado', 'ok');
    setTimeout(() => location.reload(), 1000);
}

// ── Toast ────────────────────────────────────────────────────────────────────
// ── Marcar liquidación como pagada o pendiente ───────────────────────────────
async function cambiarPago(id, accion) {
    let fecha = null;
    if (accion === 'pagado') {
        const hoy = new Date().toISOString().split('T')[0];
        fecha = prompt('Fecha de pago (YYYY-MM-DD):', hoy);
        if (!fecha) return; // canceló
    }

    const fd = new FormData();
    fd.append('csrf_token', document.getElementById('csrf-token').value);
    fd.append('accion',     accion);
    fd.append('id',         id);
    if (fecha) fd.append('fecha', fecha);

    const r = await fetch('api/marcar_pago.php', { method: 'POST', body: fd });
    const d = await r.json();

    if (d.success) {
        mostrarToast(accion === 'pagado' ? '&#10003; Marcado como pagado' : 'Marcado como pendiente', 'ok');
        setTimeout(() => location.reload(), 900);
    } else {
        mostrarToast(d.error || 'Error al actualizar', 'err');
    }
}

let tt;
function mostrarToast(msg, tipo) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast toast-' + tipo + ' on';
    clearTimeout(tt);
    tt = setTimeout(() => t.classList.remove('on'), 4000);
}
</script>
</body>
</html>
