<?php
/**
 * public_html/nomina/empleados.php
 * Gestión de empleados: altas, edición y activación/desactivación.
 */

require_once __DIR__ . '/../app/middleware/auth_check.php';
require_once __DIR__ . '/../app/models/NominaModel.php';

permiso_requerir('nomina', 'solo_ver');

$msg_ok  = '';
$msg_err = '';

// Procesar acciones POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verificar()) {
    permiso_requerir('nomina', 'editar_existentes');

    $accion = $_POST['accion'] ?? '';

    if ($accion === 'crear') {
        try {
            $id = NominaModel::crear_empleado($_POST);
            $msg_ok = 'Empleado registrado con ID #' . $id;
        } catch (RuntimeException $e) { $msg_err = $e->getMessage(); }

    } elseif ($accion === 'editar') {
        try {
            NominaModel::actualizar_empleado((int)$_POST['id'], $_POST);
            $msg_ok = 'Empleado actualizado correctamente.';
        } catch (RuntimeException $e) { $msg_err = $e->getMessage(); }

    } elseif ($accion === 'toggle' && permiso_tiene('nomina', 'admin_total')) {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            db()->prepare('UPDATE empleados SET activo = NOT activo, updated_by = ? WHERE id = ?')
                ->execute([$usuario_activo['id'], $id]);
            $msg_ok = 'Estado del empleado actualizado.';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $msg_err = 'Token de seguridad inválido.';
}

$empleados = NominaModel::todos_empleados();
$smlmv = db()->query("SELECT valor FROM configuracion_negocio WHERE clave='salario_minimo'")->fetchColumn();

// Cargar horas estándar mensuales desde parámetros laborales (dinámico)
$horas_mes_std = 191.18; // default Colombia 2026
try {
    $params_lab    = NominaModel::params();
    $horas_mes_std = NominaModel::horas_mes_estandar($params_lab);
} catch (Exception $e) { /* fallback al default */ }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Empleados — <?= APP_NAME ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root { --brand:#e94f37; --dark:#111827; --g2:#374151; --g5:#6b7280; --g8:#d1d5db; --g9:#f3f4f6; --white:#fff; --green:#059669; }
        body { font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; background:var(--g9); min-height:100vh; color:var(--dark); }

        .hdr { background:var(--dark); color:var(--white); height:54px; padding:0 14px; display:flex; align-items:center; justify-content:space-between; position:sticky; top:0; z-index:50; box-shadow:0 2px 8px rgba(0,0,0,.3); }
        .brand { font-size:17px; font-weight:800; } .brand span{color:var(--brand);}
        .nav { display:flex; gap:6px; }
        .nl { color:var(--g8); text-decoration:none; font-size:13px; padding:5px 10px; border-radius:8px; }
        .nl:hover { background:var(--g2); color:var(--white); } .nl.act { background:var(--brand); color:var(--white); }

        .main { padding:16px 14px; max-width:900px; margin:0 auto; }
        .card { background:var(--white); border-radius:14px; box-shadow:0 1px 4px rgba(0,0,0,.06); overflow:hidden; margin-bottom:16px; }
        .card-title { font-size:15px; font-weight:800; padding:14px 18px; border-bottom:1px solid var(--g9); }

        .alert { padding:12px 14px; border-radius:10px; font-size:14px; margin-bottom:14px; }
        .alert-ok  { background:#d1fae5; color:#065f46; border:1px solid #6ee7b7; }
        .alert-err { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; }

        /* Tabla */
        table { width:100%; border-collapse:collapse; }
        th { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--g5); padding:10px 14px; background:var(--g9); border-bottom:1px solid var(--g8); text-align:left; }
        td { padding:11px 14px; border-bottom:1px solid var(--g9); font-size:14px; vertical-align:middle; }
        tr:last-child td { border-bottom:none; }
        @media(max-width:640px){ .hide-m { display:none; } }

        .badge { font-size:10px; font-weight:700; padding:2px 8px; border-radius:20px; }
        .b-act { background:#d1fae5; color:#065f46; }
        .b-ina { background:var(--g9); color:var(--g5); }

        /* Formulario */
        .form-card { background:var(--white); border-radius:14px; padding:20px; box-shadow:0 1px 4px rgba(0,0,0,.06); margin-bottom:16px; }
        .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        @media(max-width:520px){ .form-grid { grid-template-columns:1fr; } }
        .fg { display:flex; flex-direction:column; gap:5px; }
        .fg label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--g5); }
        .fg input, .fg select { padding:10px 12px; border:2px solid var(--g8); border-radius:10px; font-size:15px; color:var(--dark); outline:none; width:100%; -webkit-appearance:none; }
        .fg input:focus, .fg select:focus { border-color:var(--brand); }
        .checkbox-row { display:flex; align-items:center; gap:8px; font-size:14px; padding:10px 0; }
        .checkbox-row input { width:18px; height:18px; accent-color:var(--brand); }
        .btn-submit { padding:12px 24px; background:var(--brand); color:var(--white); border:none; border-radius:10px; font-size:14px; font-weight:700; cursor:pointer; margin-top:4px; }
        .btn-edit   { padding:5px 10px; background:var(--g9); color:var(--g2); border:1px solid var(--g8); border-radius:8px; font-size:12px; font-weight:600; cursor:pointer; }
        .btn-edit:hover { border-color:var(--brand); color:var(--brand); }
        .btn-toggle { padding:5px 10px; font-size:12px; font-weight:600; border:none; border-radius:8px; cursor:pointer; }
        .btn-deact { background:#fef3c7; color:#92400e; }
        .btn-act   { background:#d1fae5; color:#065f46; }

        /* Modal edición */
        .overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:60; align-items:center; justify-content:center; padding:16px; }
        .overlay.on { display:flex; }
        .modal { background:var(--white); border-radius:16px; padding:24px; width:100%; max-width:560px; max-height:90vh; overflow-y:auto; }
        .modal-title { font-size:16px; font-weight:800; margin-bottom:16px; display:flex; justify-content:space-between; }
        .btn-close { background:var(--g9); border:none; color:var(--g5); width:30px; height:30px; border-radius:50%; cursor:pointer; font-size:16px; }

        /* Nota SMLMV */
        .smlmv-note { font-size:12px; color:var(--g5); margin-top:4px; }


    </style>
</head>
<body>
<?php $nav_activo = 'nomina'; $nav_sub = 'empleados'; include __DIR__ . '/../app/views/nav.php'; ?>


<main class="main">


    <?php if ($msg_ok):  ?><div class="alert alert-ok"><?= htmlspecialchars($msg_ok) ?></div><?php endif; ?>
    <?php if ($msg_err): ?><div class="alert alert-err"><?= htmlspecialchars($msg_err) ?></div><?php endif; ?>

    <!-- Tabla de empleados -->
    <div class="card">
        <div class="card-title">Empleados (<?= count($empleados) ?>)</div>
        <table>
            <thead>
                <tr>
                    <th>Nombre</th>
                    <th class="hide-m">Cargo</th>
                    <th class="hide-m">Contrato</th>
                    <th class="hide-m">Ingreso</th>
                    <th>Salario Base</th>
                    <th>Estado</th>
                    <?php if (permiso_tiene('nomina','editar_existentes')): ?>
                    <th></th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($empleados as $e): ?>
                <tr <?= !$e['activo'] ? 'style="opacity:.55"' : '' ?>>
                    <td>
                        <strong><?= htmlspecialchars($e['nombre_completo']) ?></strong>
                        <?php if ($e['documento_identidad']): ?>
                        <br><small style="color:var(--g5)">CC <?= htmlspecialchars($e['documento_identidad']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td class="hide-m"><?= htmlspecialchars($e['cargo'] ?: '—') ?></td>
                    <td class="hide-m">
                        <?php
                        $tc = $e['tipo_contrato'] ?? 'tiempo_completo';
                        $tcCfg = [
                            'tiempo_completo' => ['Tiempo completo', '#dbeafe','#1d4ed8'],
                            'medio_tiempo'    => ['Medio tiempo',    '#e0e7ff','#4338ca'],
                            'por_horas'       => ['Por horas',       '#fef3c7','#92400e'],
                            'por_servicio'    => ['Por servicio',    '#f3f4f6','#6b7280'],
                        ][$tc] ?? ['—','#f3f4f6','#6b7280'];
                        ?>
                        <span style="font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;
                                     background:<?= $tcCfg[1] ?>;color:<?= $tcCfg[2] ?>;white-space:nowrap">
                            <?= $tcCfg[0] ?>
                        </span>
                        <?php if ($tc === 'por_horas' && !empty($e['valor_hora'])): ?>
                        <br><small style="color:var(--g5)">$<?= number_format($e['valor_hora'],0,',','.') ?>/h</small>
                        <?php elseif ($tc === 'por_servicio' && !empty($e['valor_proyecto'])): ?>
                        <br><small style="color:var(--g5)">$<?= number_format($e['valor_proyecto'],0,',','.') ?>/proy</small>
                        <?php endif; ?>
                    </td>
                    <td class="hide-m"><?= date('d/m/Y', strtotime($e['fecha_ingreso'])) ?></td>
                    <td>
                        $<?= number_format($e['salario_base'], 0, ',', '.') ?>
                        <?php if ($e['aplica_aux_transporte']): ?>
                        <small style="color:var(--g5)"> + aux</small>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge <?= $e['activo'] ? 'b-act' : 'b-ina' ?>"><?= $e['activo'] ? 'Activo' : 'Inactivo' ?></span></td>
                    <?php if (permiso_tiene('nomina','editar_existentes')): ?>
                    <td style="display:flex; gap:6px; flex-wrap:wrap">
                        <button class="btn-edit" onclick="abrirEditar(<?= htmlspecialchars(json_encode($e)) ?>)">
                            Editar
                        </button>
                        <?php if (permiso_tiene('nomina','admin_total')): ?>
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                            <input type="hidden" name="accion" value="toggle">
                            <input type="hidden" name="id" value="<?= $e['id'] ?>">
                            <button type="submit" class="btn-toggle <?= $e['activo'] ? 'btn-deact' : 'btn-act' ?>"
                                    onclick="return confirm('¿Cambiar estado del empleado?')">
                                <?= $e['activo'] ? 'Desactivar' : 'Activar' ?>
                            </button>
                        </form>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Formulario nuevo empleado -->
    <?php if (permiso_tiene('nomina','editar_existentes')): ?>
    <div class="form-card">
        <div class="card-title" style="padding:0 0 14px">Registrar Nuevo Empleado</div>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="accion" value="crear">
            <div class="form-grid">
                <div class="fg">
                    <label>Nombre Completo *</label>
                    <input type="text" name="nombre_completo" placeholder="Nombre y apellidos" required>
                </div>
                <div class="fg">
                    <label>Documento de Identidad</label>
                    <input type="text" name="documento_identidad" placeholder="CC / NIT">
                </div>
                <div class="fg">
                    <label>Cargo</label>
                    <input type="text" name="cargo" placeholder="Ej: Auxiliar de cocina">
                </div>
                <div class="fg">
                    <label>Fecha de Ingreso *</label>
                    <input type="date" name="fecha_ingreso" value="<?= date('Y-m-d') ?>" required>
                </div>
                <!-- Tipo de contrato -->
                <div class="fg" style="grid-column:1/-1">
                    <label>Tipo de Contrato *</label>
                    <select name="tipo_contrato" id="tc-nuevo" onchange="toggleCamposContrato('nuevo')">
                        <option value="tiempo_completo">Tiempo completo — salario fijo + todas las prestaciones</option>
                        <option value="medio_tiempo">Medio tiempo — 50% del salario + prestaciones proporcionales</option>
                        <option value="por_horas">Por horas — pago según horas trabajadas + prestaciones</option>
                        <option value="por_servicio">Por servicio — pago fijo por proyecto, sin prestaciones (contratista)</option>
                    </select>
                </div>
                <!-- Campos según tipo de contrato -->
                <div class="fg" id="nuevo-campo-salario">
                    <label>Salario Base (COP) *</label>
                    <input type="number" name="salario_base" id="nuevo-salario" placeholder="<?= number_format($smlmv, 0) ?>"
                           min="0" step="1000">
                    <span class="smlmv-note">SMLMV 2026: $<?= number_format($smlmv, 0, ',', '.') ?></span>
                </div>
                <!-- Horas contratadas por este empleado (anula el parámetro global) -->
                <div class="fg" id="nuevo-campo-hora" style="display:none">
                    <label>Horas contratadas</label>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
                        <input type="number" name="horas_semana" id="n-horas-val"
                               placeholder="Ej: 44" min="1" max="240" step="0.5"
                               oninput="calcHorasMes('n')">
                        <select name="periodo_horas_emp" id="n-horas-periodo"
                                onchange="calcHorasMes('n')">
                            <option value="semana">horas / semana</option>
                            <option value="mes">horas / mes</option>
                        </select>
                    </div>
                    <span class="smlmv-note" id="n-horas-calc">
                        <strong>Dejar vacío = usa la jornada que fija la ley</strong>
                        (Parámetros Nómina: <?= number_format($horas_mes_std,2,'.','') ?> h/mes).
                        Si el gobierno cambia el tope de horas, actualízalo en
                        <a href="<?= APP_BASE ?>/nomina/parametros.php" style="color:var(--brand)">
                        Nómina → Parámetros</a> y todos los empleados por horas se recalculan automáticamente.
                        Solo llena este campo si este empleado tiene un acuerdo de horas diferente al tope legal.
                    </span>
                </div>
                <div class="fg" id="nuevo-campo-valor-hora" style="display:none">
                    <label>Valor por hora ($) — opcional</label>
                    <input type="number" name="valor_hora" id="n-valor-hora"
                           placeholder="Se calcula automáticamente" min="0" step="100"
                           oninput="calcHorasMes('n')">
                    <span class="smlmv-note" id="n-valor-hora-calc">
                        Si lo dejas vacío: salario_base ÷ horas_mes = valor/hora
                    </span>
                </div>
                <div class="fg" id="nuevo-campo-proyecto" style="display:none">
                    <label>Valor del proyecto ($)</label>
                    <input type="number" name="valor_proyecto" placeholder="Ej: 500000" min="0" step="10000">
                    <span class="smlmv-note">Pago único por el proyecto o servicio contratado</span>
                </div>
                <div class="fg" id="nuevo-campo-aux" style="justify-content:flex-end">
                    <div class="checkbox-row">
                        <input type="checkbox" name="aplica_aux_transporte" id="aux-nuevo" checked>
                        <label for="aux-nuevo" style="font-size:14px; text-transform:none; letter-spacing:0; color:var(--dark)">
                            Aplica Auxilio de Transporte
                            ($<?= number_format(db()->query("SELECT valor FROM configuracion_negocio WHERE clave='aux_transporte'")->fetchColumn(), 0, ',', '.') ?>
                            — aplica si salario ≤ 2 SMLMV)
                        </label>
                    </div>
                </div>
            </div>
            <button type="submit" class="btn-submit">Guardar Empleado</button>

            <script>
            var HORAS_GLOBALES = <?= round($horas_mes_std, 2) ?>; // jornada global configurada

            // ── Mostrar/ocultar campos según tipo de contrato ────────────────
            function toggleCamposContrato(prefix) {
                var tc  = document.getElementById('tc-' + prefix)?.value || 'tiempo_completo';
                var pfx = (prefix === 'nuevo') ? 'nuevo-campo' : 'e-campo';
                var show = function(id, v) {
                    var el = document.getElementById(id);
                    if (el) el.style.display = v ? '' : 'none';
                };
                show(pfx + '-salario',     ['tiempo_completo','medio_tiempo','por_horas'].includes(tc));
                show(pfx + '-hora',        tc === 'por_horas');
                show(pfx + '-valor-hora',  tc === 'por_horas'); // campo extra para valor/hora
                show(pfx + '-proyecto',    tc === 'por_servicio');
                show(pfx + '-aux',         tc !== 'por_servicio');
                var salInp = document.getElementById('nuevo-salario') || document.getElementById('e-salario');
                if (salInp) salInp.required = ['tiempo_completo','medio_tiempo','por_horas'].includes(tc);
                if (tc === 'por_horas') calcHorasMes(prefix === 'nuevo' ? 'n' : 'e');
            }

            // ── Calcular horas/mes en tiempo real y actualizar el hint ────────
            function calcHorasMes(p) {
                var hVal  = parseFloat(document.getElementById(p + '-horas-val')?.value)  || 0;
                var peri  = document.getElementById(p + '-horas-periodo')?.value || 'semana';
                var salEl = document.getElementById(p === 'n' ? 'nuevo-salario' : 'edit-salario');
                var salario = parseFloat(salEl?.value) || 0;
                var calcEl = document.getElementById(p + '-horas-calc');
                var vhEl   = document.getElementById(p + '-valor-hora-calc');

                var horasMes = 0;
                if (hVal > 0) {
                    horasMes = peri === 'semana'
                        ? Math.round(hVal * 52.14 / 12 * 100) / 100
                        : hVal;
                } else {
                    horasMes = HORAS_GLOBALES;
                }

                // Actualizar hint de horas/mes
                if (calcEl) {
                    if (hVal > 0) {
                        calcEl.innerHTML = 'Equivale a <strong>' + horasMes.toFixed(2) + '</strong> h/mes'
                            + (peri === 'semana'
                                ? ' (' + hVal + 'h/sem &times; 52.14 semanas/a&ntilde;o &divide; 12 meses)'
                                : ' (valor mensual directo)');
                    } else {
                        calcEl.innerHTML = '<strong>Campo vac&iacute;o: se usa el tope legal de horas ('
                            + HORAS_GLOBALES.toFixed(2) + ' h/mes)</strong>'
                            + ' definido en N&oacute;mina &rarr; Par&aacute;metros.'
                            + '<br><em>Si el gobierno cambia el m&aacute;ximo de horas,'
                            + ' solo actualiza ese par&aacute;metro y todos los empleados por horas se recalculan.</em>';
                    }
                }

                // Actualizar hint de valor/hora
                if (vhEl && salario > 0 && horasMes > 0) {
                    var vh = Math.round(salario / horasMes);
                    // Verificar si hay un valor manual
                    var vhManual = parseFloat(document.getElementById(
                        p === 'n' ? 'n-valor-hora' : 'edit-valor-hora')?.value) || 0;
                    if (vhManual > 0) {
                        vhEl.textContent = 'Valor/hora definido manualmente: $' + vhManual.toLocaleString('es-CO');
                    } else {
                        vhEl.textContent = 'Automático: $' + salario.toLocaleString('es-CO')
                            + ' ÷ ' + horasMes.toFixed(2) + ' h = $' + vh.toLocaleString('es-CO') + '/hora';
                    }
                }
            }

            // Ejecutar al cargar
            toggleCamposContrato('nuevo');
            </script>
        </form>
    </div>
    <?php endif; ?>

</main>

<!-- Modal de edición -->
<div class="overlay" id="modal-edit" onclick="if(event.target===this)cerrarModal()">
    <div class="modal">
        <div class="modal-title">
            <span>Editar Empleado</span>
            <button class="btn-close" onclick="cerrarModal()">✕</button>
        </div>
        <form method="POST" id="form-edit">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="accion" value="editar">
            <input type="hidden" name="id" id="edit-id">
            <div class="form-grid">
                <div class="fg">
                    <label>Nombre Completo *</label>
                    <input type="text" name="nombre_completo" id="edit-nombre" required>
                </div>
                <div class="fg">
                    <label>Documento</label>
                    <input type="text" name="documento_identidad" id="edit-doc">
                </div>
                <div class="fg">
                    <label>Cargo</label>
                    <input type="text" name="cargo" id="edit-cargo">
                </div>
                <div class="fg">
                    <label>Fecha de Ingreso *</label>
                    <input type="date" name="fecha_ingreso" id="edit-fecha" required>
                </div>
                <div class="fg" style="grid-column:1/-1">
                    <label>Tipo de Contrato</label>
                    <select name="tipo_contrato" id="tc-edit" onchange="toggleCamposContrato('edit')">
                        <option value="tiempo_completo">Tiempo completo</option>
                        <option value="medio_tiempo">Medio tiempo — 50% proporcional</option>
                        <option value="por_horas">Por horas</option>
                        <option value="por_servicio">Por servicio (contratista)</option>
                    </select>
                </div>
                <div class="fg" style="grid-column:1/-1" id="e-campo-salario">
                    <label>Salario Base (COP)</label>
                    <input type="number" name="salario_base" id="edit-salario" min="0" step="1000">
                </div>
                <!-- Horas contratadas por este empleado (modal editar) -->
                <div class="fg" style="grid-column:1/-1;display:none" id="e-campo-hora">
                    <label>Horas contratadas</label>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:6px">
                        <input type="number" name="horas_semana" id="e-horas-val"
                               placeholder="Ej: 44" min="1" max="240" step="0.5"
                               oninput="calcHorasMes('e')">
                        <select name="periodo_horas_emp" id="e-horas-periodo"
                                onchange="calcHorasMes('e')">
                            <option value="semana">horas / semana</option>
                            <option value="mes">horas / mes</option>
                        </select>
                    </div>
                    <span class="smlmv-note" id="e-horas-calc">Equivale a — h/mes</span>
                </div>
                <div class="fg" style="grid-column:1/-1;display:none" id="e-campo-valor-hora">
                    <label>Valor por hora ($) — opcional</label>
                    <input type="number" name="valor_hora" id="edit-valor-hora" min="0" step="100"
                           oninput="calcHorasMes('e')">
                    <span class="smlmv-note" id="e-valor-hora-calc">Si vacío: salario_base ÷ horas_mes</span>
                </div>
                <div class="fg" style="grid-column:1/-1;display:none" id="e-campo-proyecto">
                    <label>Valor del proyecto ($)</label>
                    <input type="number" name="valor_proyecto" id="edit-valor-proyecto" min="0" step="10000">
                </div>
                <div class="fg" style="grid-column:1/-1">
                    <div class="checkbox-row">
                        <input type="checkbox" name="aplica_aux_transporte" id="edit-aux">
                        <label for="edit-aux" style="font-size:14px; text-transform:none; letter-spacing:0; color:var(--dark)">
                            Aplica Auxilio de Transporte
                        </label>
                    </div>
                </div>
            </div>
            <button type="submit" class="btn-submit" style="width:100%">Guardar Cambios</button>
        </form>
    </div>
</div>

<script>
function abrirEditar(emp) {
    document.getElementById('edit-id').value       = emp.id;
    document.getElementById('edit-nombre').value   = emp.nombre_completo;
    document.getElementById('edit-doc').value      = emp.documento_identidad || '';
    document.getElementById('edit-cargo').value    = emp.cargo || '';
    document.getElementById('edit-fecha').value    = emp.fecha_ingreso;
    document.getElementById('edit-salario').value  = emp.salario_base;
    // Tipo de contrato y campos extra
    var tcSel = document.getElementById('tc-edit');
    if (tcSel) {
        tcSel.value = emp.tipo_contrato || 'tiempo_completo';
        toggleCamposContrato('edit');
    }
    var vhInp = document.getElementById('edit-valor-hora');
    if (vhInp) vhInp.value = emp.valor_hora || '';
    var vpInp = document.getElementById('edit-valor-proyecto');
    if (vpInp) vpInp.value = emp.valor_proyecto || '';
    // Horas contratadas del empleado
    var horasInp = document.getElementById('e-horas-val');
    if (horasInp) horasInp.value = emp.horas_semana || '';
    var periodoSel = document.getElementById('e-horas-periodo');
    if (periodoSel) periodoSel.value = emp.periodo_horas_emp || 'semana';
    // Recalcular hint
    calcHorasMes('e');
    document.getElementById('edit-aux').checked    = !!parseInt(emp.aplica_aux_transporte);
    document.getElementById('modal-edit').classList.add('on');
}
function cerrarModal() {
    document.getElementById('modal-edit').classList.remove('on');
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') cerrarModal(); });
</script>
</body>
</html>
