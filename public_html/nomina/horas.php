<?php
/**
 * nomina/horas.php
 * Registro de horas diarias para empleados con contrato por horas.
 */
require_once __DIR__ . '/../app/middleware/auth_check.php';
require_once __DIR__ . '/../app/models/NominaModel.php';

$nav_activo = 'nomina';
permiso_requerir('nomina', 'editar_existentes');

$mes  = (int)($_GET['mes']  ?? date('n'));
$anio = (int)($_GET['anio'] ?? date('Y'));
$mes  = max(1, min(12, $mes));

// Empleados por horas con resumen del período
$empleados_hora = NominaModel::empleados_por_horas_periodo($mes, $anio);

// Empleado seleccionado
$emp_sel   = (int)($_GET['emp'] ?? 0);
$horas_mes = [];
if ($emp_sel) {
    $horas_mes = NominaModel::horas_periodo($emp_sel, $mes, $anio);
}

// Parámetros laborales para mostrar info de recargos
$params_lab = NominaModel::params();
$horas_mes_std = NominaModel::horas_mes_estandar($params_lab);

$meses = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',
          7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];

$diasMes = cal_days_in_month(CAL_GREGORIAN, $mes, $anio);

// Indexar horas por fecha
$horasIdx = [];
foreach ($horas_mes as $h) { $horasIdx[$h['fecha']] = $h; }

// Definición de tipos de hora para la UI
$TIPOS_HORA = [
    'ordinaria'              => ['label' => 'Ordinaria',               'mult' => 1.00, 'color' => '#059669'],
    'recargo_nocturno'       => ['label' => 'Recargo nocturno (9pm-6am)','mult' => 1.35, 'color' => '#7c3aed'],
    'extra_diurna'           => ['label' => 'Extra diurna (+25%)',      'mult' => 1.25, 'color' => '#d97706'],
    'extra_nocturna'         => ['label' => 'Extra nocturna (+75%)',    'mult' => 1.75, 'color' => '#9a3412'],
    'festiva_ordinaria'      => ['label' => 'Festiva/dominical (+75%)', 'mult' => 1.75, 'color' => '#0369a1'],
    'extra_festiva_diurna'   => ['label' => 'Extra festiva día (+100%)','mult' => 2.00, 'color' => '#b45309'],
    'extra_festiva_nocturna' => ['label' => 'Extra festiva noche (+150%)','mult' => 2.50,'color' => '#be185d'],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro de Horas — <?= APP_NAME ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root { --brand:#e94f37; --dark:#111827; --g2:#374151; --g5:#6b7280; --g8:#d1d5db; --g9:#f3f4f6; --white:#fff; --green:#059669; }
        body { font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; background:var(--g9); min-height:100vh; color:var(--dark); padding-bottom:40px; }
        .main { padding:16px 14px; max-width:960px; margin:0 auto; }
        .row-top { display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; margin-bottom:16px; }
        .fg { display:flex; flex-direction:column; gap:4px; }
        .fg label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--g5); }
        .fg select { padding:8px 12px; border:2px solid var(--g8); border-radius:10px; font-size:14px; outline:none; }
        .fg select:focus { border-color:var(--brand); }
        .btn-ver { padding:9px 16px; background:var(--brand); color:#fff; border:none; border-radius:10px; font-size:13px; font-weight:700; cursor:pointer; }
        .card { background:var(--white); border-radius:14px; box-shadow:0 1px 4px rgba(0,0,0,.06); overflow:hidden; margin-bottom:16px; }
        .card-title { font-size:15px; font-weight:800; padding:14px 18px; border-bottom:1px solid var(--g9); }
        table { width:100%; border-collapse:collapse; }
        th { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; color:var(--g5); padding:9px 12px; background:var(--g9); border-bottom:1px solid var(--g8); text-align:left; }
        td { padding:9px 12px; border-bottom:1px solid var(--g9); font-size:13px; }
        tr:last-child td { border-bottom:none; }
        .btn-sel { padding:5px 12px; background:var(--brand); color:#fff; border:none; border-radius:8px; font-size:12px; font-weight:700; cursor:pointer; text-decoration:none; }
        /* Cuadrícula de días */
        .dias-grid { display:grid; grid-template-columns:repeat(7,1fr); gap:6px; padding:16px; }
        .dia-card { border:2px solid var(--g8); border-radius:10px; padding:8px; cursor:pointer; transition:.15s; }
        .dia-card:hover { border-color:var(--brand); }
        .dia-card.tiene-horas { border-color:var(--green); background:#f0fdf4; }
        .dia-card.fin-semana  { background:var(--g9); }
        .dia-num  { font-size:11px; font-weight:800; color:var(--g5); }
        .dia-hrs  { font-size:15px; font-weight:800; color:var(--green); }
        .dia-desc { font-size:10px; color:var(--g5); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        /* Modal */
        .overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:60; align-items:center; justify-content:center; padding:16px; }
        .overlay.on { display:flex; }
        .modal { background:var(--white); border-radius:16px; padding:22px; width:100%; max-width:420px; }
        .modal-hdr { font-size:16px; font-weight:800; margin-bottom:16px; display:flex; justify-content:space-between; }
        .btn-cls { background:var(--g9); border:none; color:var(--g5); width:30px; height:30px; border-radius:50%; cursor:pointer; font-size:16px; }
        .hfg { display:flex; flex-direction:column; gap:5px; margin-bottom:12px; }
        .hfg label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--g5); }
        .hfg input, .hfg textarea { padding:10px 12px; border:2px solid var(--g8); border-radius:9px; font-size:15px; color:var(--dark); outline:none; width:100%; }
        .hfg input:focus, .hfg textarea:focus { border-color:var(--brand); }
        .btn-save { width:100%; padding:12px; background:var(--green); color:#fff; border:none; border-radius:10px; font-size:15px; font-weight:700; cursor:pointer; }
        .resumen-bar { background:var(--dark); color:#fff; border-radius:14px; padding:14px 18px; margin-bottom:16px; display:flex; gap:24px; align-items:center; flex-wrap:wrap; }
        .rb-val { font-size:20px; font-weight:800; color:#fcd34d; }
        .rb-lbl { font-size:11px; color:#9ca3af; text-transform:uppercase; letter-spacing:.4px; }
        .toast { position:fixed; bottom:20px; left:50%; transform:translateX(-50%) translateY(20px); padding:10px 20px; border-radius:24px; font-size:14px; font-weight:600; opacity:0; transition:.25s; z-index:99; pointer-events:none; }
        .toast.on { opacity:1; transform:translateX(-50%) translateY(0); }
        .toast-ok  { background:#065f46; color:#d1fae5; }
        .toast-err { background:#991b1b; color:#fee2e2; }


    </style>
</head>
<body>
<?php $nav_sub = 'horas'; include __DIR__ . '/../app/views/nav.php'; ?>
<main class="main">


    <!-- Filtros período + empleado -->
    <form class="row-top" method="GET">
        <div class="fg"><label>Mes</label>
            <select name="mes">
                <?php foreach ($meses as $n => $nom): ?>
                <option value="<?= $n ?>" <?= $n==$mes?'selected':'' ?>><?= $nom ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="fg"><label>Año</label>
            <select name="anio">
                <?php for($y=date('Y')+1; $y>=2024; $y--): ?>
                <option value="<?= $y ?>" <?= $y==$anio?'selected':'' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <?php if (!empty($empleados_hora)): ?>
        <div class="fg"><label>Empleado</label>
            <select name="emp">
                <option value="">— Ver resumen —</option>
                <?php foreach ($empleados_hora as $e): ?>
                <option value="<?= $e['id'] ?>" <?= $e['id']==$emp_sel?'selected':'' ?>>
                    <?= htmlspecialchars($e['nombre_completo']) ?>
                    (<?= $e['horas_total'] ?>h)
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <button type="submit" class="btn-ver">Ver</button>
    </form>

    <?php if (empty($empleados_hora)): ?>
    <div class="card">
        <p style="text-align:center;padding:40px;color:var(--g5)">
            No hay empleados con contrato <strong>por horas</strong>.<br>
            <a href="<?= APP_BASE ?>/nomina/empleados.php" style="color:var(--brand)">
                Configura empleados por horas →
            </a>
        </p>
    </div>

    <?php else: ?>

    <!-- Resumen por empleado -->
    <?php if (!$emp_sel): ?>
    <div class="card">
        <div class="card-title">Horas del período — <?= $meses[$mes] ?> <?= $anio ?></div>
        <table>
            <thead><tr>
                <th>Empleado</th><th>Valor/hora</th>
                <th style="text-align:center">Días</th>
                <th style="text-align:right">Horas</th>
                <th style="text-align:right">Pago estimado</th>
                <th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($empleados_hora as $e):
                $pago = $e['horas_total'] * $e['valor_hora'];
            ?>
            <tr>
                <td><strong><?= htmlspecialchars($e['nombre_completo']) ?></strong>
                    <?php if ($e['cargo']): ?><br><small style="color:var(--g5)"><?= htmlspecialchars($e['cargo']) ?></small><?php endif; ?>
                </td>
                <td>$<?= number_format($e['valor_hora'],0,',','.') ?>/h</td>
                <td style="text-align:center"><?= $e['dias_trabajados'] ?></td>
                <td style="text-align:right"><strong><?= $e['horas_total'] ?>h</strong></td>
                <td style="text-align:right;font-weight:700;color:var(--brand)">$<?= number_format($pago,0,',','.') ?></td>
                <td>
                    <a href="?mes=<?= $mes ?>&anio=<?= $anio ?>&emp=<?= $e['id'] ?>"
                       class="btn-sel">Registrar horas</a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php else:
        // Empleado seleccionado
        $empData = null;
        foreach ($empleados_hora as $e) {
            if ($e['id'] == $emp_sel) { $empData = $e; break; }
        }
        $totalHoras = $empData['horas_total'] ?? 0;
        $valorHora  = $empData['valor_hora']  ?? 0;
        $pagoEst    = $totalHoras * $valorHora;
    ?>

    <div class="resumen-bar">
        <div>
            <div class="rb-val"><?= htmlspecialchars($empData['nombre_completo'] ?? '') ?></div>
            <div class="rb-lbl">Contrato por horas — <?= $meses[$mes] ?> <?= $anio ?></div>
        </div>
        <div><div class="rb-val"><?= $totalHoras ?>h</div><div class="rb-lbl">Total horas</div></div>
        <div><div class="rb-val">$<?= number_format($valorHora,0,',','.') ?></div><div class="rb-lbl">Valor/hora</div></div>
        <div><div class="rb-val" style="color:var(--green)">$<?= number_format($pagoEst,0,',','.') ?></div><div class="rb-lbl">Pago estimado</div></div>
    </div>

    <!-- Cuadrícula de días del mes -->
    <div class="card">
        <div class="card-title">
            Horas diarias — clic en un día para registrar
            <span style="font-size:12px;font-weight:400;color:var(--g5);margin-left:8px">
                <span style="color:var(--green)">■</span> Con horas registradas
            </span>
        </div>
        <!-- Cabecera días de semana -->
        <div class="dias-grid" style="padding-bottom:0">
            <?php foreach (['Lun','Mar','Mié','Jue','Vie','Sáb','Dom'] as $d): ?>
            <div style="text-align:center;font-size:10px;font-weight:700;color:var(--g5);padding:4px"><?= $d ?></div>
            <?php endforeach; ?>
        </div>
        <div class="dias-grid">
            <?php
            // Calcular día de la semana del primer día (1=Lun...7=Dom)
            $primerDia = (int)date('N', mktime(0,0,0,$mes,1,$anio)); // 1=Lun
            // Espacios vacíos antes del día 1
            for ($i = 1; $i < $primerDia; $i++):
            ?>
            <div></div>
            <?php endfor; ?>
            <?php for ($dia = 1; $dia <= $diasMes; $dia++):
                $fechaDia = sprintf('%04d-%02d-%02d', $anio, $mes, $dia);
                $dSem     = (int)date('N', mktime(0,0,0,$mes,$dia,$anio));
                $esFin    = $dSem >= 6;
                $registr  = $horasIdx[$fechaDia] ?? null;
                $esFestivo = $esFin; // Sábados y domingos por defecto = festivos
                $tipoReg   = $registr['tipo_hora'] ?? 'ordinaria';
                $tipoCfg   = $TIPOS_HORA[$tipoReg] ?? $TIPOS_HORA['ordinaria'];
                $clases    = 'dia-card'
                           . ($registr         ? ' tiene-horas' : '')
                           . ($esFin           ? ' fin-semana'  : '')
                           . (($registr && ($registr['es_festivo'] ?? 0)) ? ' es-festivo' : '');
            ?>
            <div class="<?= $clases ?>"
                 onclick="abrirDia(<?= htmlspecialchars(json_encode([
                     'fecha'     => $fechaDia,
                     'dia'       => $dia,
                     'horas'     => $registr ? (float)$registr['horas'] : 0,
                     'tipo_hora' => $tipoReg,
                     'es_festivo'=> $registr ? (int)($registr['es_festivo'] ?? $esFin ? 1 : 0) : ($esFin ? 1 : 0),
                     'descripcion'=> $registr['descripcion'] ?? '',
                 ])) ?>)">
                <div class="dia-num">
                    <?= $dia ?>
                    <span style="color:var(--g8)"><?= ['','L','M','X','J','V','S','D'][$dSem] ?></span>
                    <?php if ($registr && ($registr['es_festivo'] ?? 0)): ?>
                    <span style="color:#d97706;font-size:9px">★</span>
                    <?php endif; ?>
                </div>
                <?php if ($registr): ?>
                <div class="dia-hrs" style="color:<?= $tipoCfg['color'] ?>"><?= $registr['horas'] ?>h</div>
                <div class="dia-desc" style="color:<?= $tipoCfg['color'] ?>;font-size:9px">
                    ×<?= number_format($tipoCfg['mult'], 2) ?>
                </div>
                <?php else: ?>
                <div style="font-size:11px;color:var(--g8);margin-top:4px">—</div>
                <?php endif; ?>
            </div>
            <?php endfor; ?>
        </div>
    </div>

    <a href="?mes=<?= $mes ?>&anio=<?= $anio ?>" class="btn-ver" style="display:inline-block;margin-top:8px">
        ← Volver al resumen
    </a>
    <?php endif; ?>
    <?php endif; ?>

</main>

<!-- Modal registro de horas -->
<div class="overlay" id="modal-horas" onclick="if(event.target===this)this.classList.remove('on')">
    <div class="modal">
        <div class="modal-hdr">
            <span id="m-titulo">Registrar horas</span>
            <button class="btn-cls" onclick="document.getElementById('modal-horas').classList.remove('on')">&#x2715;</button>
        </div>
        <input type="hidden" id="m-fecha" value="">

        <!-- Horas -->
        <div class="hfg">
            <label>Horas trabajadas ese día</label>
            <input type="number" id="m-horas" min="0" max="24" step="0.5" placeholder="8">
            <small style="color:var(--g5)">Decimales: 7.5 = 7h 30min. Máx ordinario/día: <?= (int)($params_lab['horas_max_dia'] ?? 8) ?>h</small>
        </div>

        <!-- Tipo de hora -->
        <div class="hfg">
            <label>Tipo de hora</label>
            <select id="m-tipo" onchange="actualizarMultiplicador()">
                <?php foreach ($TIPOS_HORA as $key => $th): ?>
                <option value="<?= $key ?>" data-mult="<?= $th['mult'] ?>" style="color:<?= $th['color'] ?>">
                    <?= htmlspecialchars($th['label']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <small style="color:var(--g5)" id="m-mult-info">
                Multiplicador: ×1.00 — Pago = valor_hora × 1.00
            </small>
        </div>

        <!-- Festivo -->
        <div class="hfg" style="flex-direction:row;align-items:center;gap:10px">
            <input type="checkbox" id="m-festivo" style="width:18px;height:18px;accent-color:var(--brand)">
            <label for="m-festivo" style="font-size:14px;text-transform:none;letter-spacing:0;cursor:pointer;color:var(--dark)">
                <strong>Día festivo o dominical</strong>
                <span style="font-size:12px;color:var(--g5)">— aplica recargo adicional</span>
            </label>
        </div>

        <!-- Descripción -->
        <div class="hfg">
            <label>Descripción (opcional)</label>
            <textarea id="m-desc" rows="2" placeholder="Ej: Turno mañana, empacado, atención al cliente..."></textarea>
        </div>

        <!-- Resumen del pago estimado -->
        <div id="m-resumen" style="background:var(--g9);border-radius:10px;padding:10px 14px;
             margin-bottom:12px;font-size:13px;display:none">
            <strong>Estimado:</strong>
            <span id="m-pago-est">—</span>
            <span style="color:var(--g5);font-size:11px"> (basado en valor/hora del empleado)</span>
        </div>

        <button class="btn-save" onclick="guardarHoras()">Guardar horas</button>
    </div>
</div>

<div class="toast" id="toast"></div>
<input type="hidden" id="csrf-tk" value="<?= htmlspecialchars(csrf_token()) ?>">

<script>
const EMP_ID    = <?= $emp_sel ?: 0 ?>;
const TIPOS_HORA = <?= json_encode($TIPOS_HORA, JSON_HEX_TAG) ?>;
const VALOR_HORA = <?= isset($empData) ? round($empData['valor_hora'],2) : 0 ?>;

// Actualizar info de multiplicador al cambiar tipo
function actualizarMultiplicador() {
    const sel   = document.getElementById('m-tipo');
    const tipo  = sel.value;
    const mult  = parseFloat(sel.options[sel.selectedIndex].dataset.mult) || 1;
    const horas = parseFloat(document.getElementById('m-horas').value) || 0;
    const info  = document.getElementById('m-mult-info');
    const res   = document.getElementById('m-resumen');
    const pago  = document.getElementById('m-pago-est');

    info.textContent = 'Multiplicador: ×' + mult.toFixed(2) + ' — Pago = valor_hora × ' + mult.toFixed(2);
    info.style.color = TIPOS_HORA[tipo]?.color || '#6b7280';

    if (VALOR_HORA > 0 && horas > 0) {
        const est = Math.round(horas * VALOR_HORA * mult);
        pago.textContent = '$' + est.toLocaleString('es-CO');
        res.style.display = 'block';
    } else {
        res.style.display = 'none';
    }
}

document.getElementById('m-horas').addEventListener('input', actualizarMultiplicador);

function abrirDia(d) {
    // d = objeto {fecha, dia, horas, tipo_hora, es_festivo, descripcion}
    document.getElementById('m-titulo').textContent = 'Día ' + d.dia + ' — ' + d.fecha;
    document.getElementById('m-fecha').value   = d.fecha;
    document.getElementById('m-horas').value   = d.horas || '';
    document.getElementById('m-tipo').value    = d.tipo_hora || 'ordinaria';
    document.getElementById('m-festivo').checked = !!d.es_festivo;
    document.getElementById('m-desc').value    = d.descripcion || '';
    actualizarMultiplicador();
    document.getElementById('modal-horas').classList.add('on');
    document.getElementById('m-horas').focus();
}

async function guardarHoras() {
    const fecha    = document.getElementById('m-fecha').value;
    const horas    = document.getElementById('m-horas').value;
    const tipo     = document.getElementById('m-tipo').value;
    const festivo  = document.getElementById('m-festivo').checked ? 1 : 0;
    const desc     = document.getElementById('m-desc').value;
    if (!horas && parseFloat(horas) !== 0) { toast('Ingresa las horas', 'err'); return; }
    const fd = new FormData();
    fd.append('csrf_token',   document.getElementById('csrf-tk').value);
    fd.append('empleado_id',  EMP_ID);
    fd.append('tipo_hora',    tipo);
    fd.append('es_festivo',   festivo);
    fd.append('fecha',        fecha);
    fd.append('horas',        horas);
    fd.append('descripcion',  desc);
    const r = await fetch('api/registrar_horas.php', { method:'POST', body:fd });
    const d = await r.json();
    if (d.success) {
        toast('Horas guardadas. Total mes: ' + d.total_mes + 'h', 'ok');
        document.getElementById('modal-horas').classList.remove('on');
        setTimeout(() => location.reload(), 800);
    } else toast(d.error || 'Error', 'err');
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') document.getElementById('modal-horas').classList.remove('on');
    if (e.key === 'Enter' && document.getElementById('modal-horas').classList.contains('on')) {
        guardarHoras();
    }
});

var _tt;
function toast(m,t){
    var el=document.getElementById('toast');
    el.textContent=m; el.className='toast toast-'+t+' on';
    clearTimeout(_tt); _tt=setTimeout(()=>el.classList.remove('on'), 3500);
}
</script>
</body>
</html>
