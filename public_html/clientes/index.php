<?php
/**
 * public_html/clientes/index.php
 * Módulo de gestión de clientes del negocio.
 *
 * Funcionalidades:
 *   - Listado paginado con búsqueda en tiempo real (nombre, apellido, empresa, teléfono)
 *   - Crear cliente (modal)
 *   - Editar cliente (modal)
 *   - Activar / desactivar cliente (toggle)
 *   - Fusionar dos clientes: transfiere ventas, abonos y saldo al cliente principal
 *   - Ver deuda fiado y última compra por cliente
 *   - Acceso al historial de ventas de cada cliente
 *
 * Permisos:
 *   solo_ver          → puede ver la lista y el historial
 *   editar_existentes → puede crear, editar y fusionar
 *   admin_total       → puede además desactivar clientes
 *
 * Responsive: xs<480 | sm480 | md640 | lg1024 | xl1280 | 2xl1600 | tv1920
 */

require_once __DIR__ . '/../app/middleware/auth_check.php';
require_once __DIR__ . '/../app/models/ClienteModel.php';

$nav_activo = 'clientes';
permiso_requerir('ventas', 'solo_ver'); // clientes comparte el permiso del módulo ventas

$clientes = ClienteModel::todos_completo();

// Estadísticas rápidas para el encabezado
$total_activos  = count(array_filter($clientes, fn($c) => (int)$c['activo'] === 1));
$con_deuda      = count(array_filter($clientes, fn($c) => (float)$c['saldo_fiado'] > 0));
$deuda_total    = array_sum(array_map(fn($c) => (float)$c['saldo_fiado'], $clientes));
$total_ventas   = array_sum(array_column($clientes, 'total_ventas'));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clientes — <?= APP_NAME ?></title>
    <style>
        /* ── Reset y variables ───────────────────────────────────────────── */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --brand:#e94f37; --dark:#111827; --g1:#1f2937; --g2:#374151;
            --g5:#6b7280; --g8:#d1d5db; --g9:#f3f4f6; --white:#fff;
            --green:#059669; --yellow:#d97706; --red:#dc2626;
        }
        body { font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
               background:var(--g9); color:var(--dark); min-height:100vh; }

        /* ── Layout ──────────────────────────────────────────────────────── */
        .main { padding:16px 14px 60px; max-width:1100px; margin:0 auto; }

        /* ── KPIs ────────────────────────────────────────────────────────── */
        .kpis { display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:16px; }
        .kpi  { background:var(--white); border-radius:14px; padding:14px 16px;
                box-shadow:0 1px 4px rgba(0,0,0,.06); }
        .kpi-n { font-size:20px; font-weight:800; }
        .kpi-l { font-size:11px; color:var(--g5); text-transform:uppercase; margin-top:2px; }

        /* ── Barra de acciones ───────────────────────────────────────────── */
        .actions-bar {
            display:flex; gap:10px; align-items:center; flex-wrap:wrap;
            background:var(--white); border-radius:14px;
            padding:12px 16px; margin-bottom:14px;
            box-shadow:0 1px 4px rgba(0,0,0,.06);
        }
        .search-input {
            flex:1; min-width:160px; padding:9px 12px;
            border:2px solid var(--g8); border-radius:10px;
            font-size:14px; outline:none;
        }
        .search-input:focus { border-color:var(--brand); }
        /* Filtro de estado (activos / inactivos / todos) */
        .filter-sel {
            padding:9px 12px; border:2px solid var(--g8);
            border-radius:10px; font-size:13px; outline:none;
            background:var(--white); cursor:pointer;
        }
        .filter-sel:focus { border-color:var(--brand); }
        .btn { padding:9px 16px; border:none; border-radius:10px;
               font-size:13px; font-weight:700; cursor:pointer; }
        .btn-primary { background:var(--brand); color:var(--white); }
        .btn-primary:hover { background:#c73d28; }
        .btn-sec     { background:var(--g9); color:var(--g2); }
        .btn-sec:hover { background:var(--g8); }

        /* ── Tabla ───────────────────────────────────────────────────────── */
        .tbl-card { background:var(--white); border-radius:14px;
                    box-shadow:0 1px 4px rgba(0,0,0,.06); overflow:hidden; overflow-x:auto; }
        table  { width:100%; border-collapse:collapse; min-width:600px; }
        thead th { font-size:11px; font-weight:700; text-transform:uppercase;
                   letter-spacing:.4px; color:var(--g5); padding:10px 14px;
                   background:var(--g9); border-bottom:1px solid var(--g8);
                   text-align:left; white-space:nowrap; }
        th.r, td.r { text-align:right; }
        tbody td { padding:10px 14px; border-bottom:1px solid var(--g9);
                   font-size:13px; vertical-align:middle; }
        tbody tr:last-child td { border-bottom:none; }
        /* Fila de cliente inactivo */
        tbody tr.inactivo td { opacity:.5; }
        /* Hover */
        tbody tr:hover td { background:#fafafa; }

        /* ── Badges ──────────────────────────────────────────────────────── */
        .badge { font-size:10px; font-weight:700; padding:2px 7px;
                 border-radius:20px; white-space:nowrap; display:inline-block; }
        .b-deuda  { background:#fee2e2; color:#991b1b; }
        .b-ok     { background:#d1fae5; color:#065f46; }
        .b-inac   { background:#f3f4f6; color:#6b7280; }

        /* ── Botones de acción en tabla ──────────────────────────────────── */
        /* Mismo patrón que inventario/productos: btn-ajuste + .ic de nav.php */
        .btn-acc {
            background:var(--white); border:1px solid var(--g8);
            cursor:pointer; padding:0; border-radius:8px;
            color:var(--g5); transition:border-color .15s, color .15s, background .15s;
        }
        .btn-acc:hover { border-color:var(--brand); color:var(--brand); }
        .btn-acc.danger:hover { border-color:var(--red); color:var(--red); background:#fff5f5; }

        /* ── Modales ─────────────────────────────────────────────────────── */
        .overlay {
            display:none; position:fixed; inset:0;
            background:rgba(0,0,0,.55); z-index:200;
            align-items:center; justify-content:center;
            padding:16px;
        }
        .overlay.on { display:flex; }
        .modal {
            background:var(--white); border-radius:16px;
            width:100%; max-width:480px; max-height:92vh;
            overflow-y:auto; padding:0 0 20px;
        }
        .modal-hdr {
            display:flex; justify-content:space-between; align-items:center;
            padding:16px 20px 12px; border-bottom:1px solid var(--g9);
            font-size:16px; font-weight:800; position:sticky; top:0;
            background:var(--white); z-index:1;
        }
        .btn-cls {
            background:var(--g9); border:none; color:var(--g5);
            width:30px; height:30px; border-radius:50%; cursor:pointer; font-size:16px;
        }
        .modal-body { padding:16px 20px 0; }

        /* ── Formularios dentro del modal ────────────────────────────────── */
        .fg { display:flex; flex-direction:column; gap:5px; margin-bottom:14px; }
        .fg label { font-size:11px; font-weight:700; text-transform:uppercase;
                    letter-spacing:.4px; color:var(--g5); }
        .fg input, .fg select {
            padding:10px 12px; border:2px solid var(--g8);
            border-radius:10px; font-size:14px; outline:none;
            transition:border-color .15s;
        }
        .fg input:focus, .fg select:focus { border-color:var(--brand); }
        .fg .hint { font-size:11px; color:var(--g5); }
        .fg-row { display:grid; grid-template-columns:1fr 1fr; gap:10px; }

        /* Botón de guardar ocupa todo el ancho */
        .btn-full {
            width:100%; padding:12px; background:var(--brand); color:var(--white);
            border:none; border-radius:10px; font-size:14px; font-weight:700;
            cursor:pointer; margin-top:4px;
        }
        .btn-full:hover { background:#c73d28; }
        .btn-full.warning { background:#d97706; }
        .btn-full.warning:hover { background:#b45309; }

        /* ── Modal de fusión: visualización de datos ─────────────────────── */
        .fusion-preview {
            background:var(--g9); border-radius:10px;
            padding:12px 14px; margin:10px 0; font-size:13px;
        }
        .fusion-preview strong { display:block; margin-bottom:4px; }
        .fusion-preview .row   { display:flex; justify-content:space-between;
                                  padding:3px 0; border-bottom:1px solid var(--g8); }
        .fusion-preview .row:last-child { border-bottom:none; }

        /* ── Toast ───────────────────────────────────────────────────────── */
        .toast {
            position:fixed; bottom:24px; left:50%; transform:translateX(-50%) translateY(80px);
            background:var(--dark); color:var(--white); padding:10px 20px;
            border-radius:30px; font-size:13px; font-weight:600;
            transition:transform .25s; z-index:9999; pointer-events:none;
        }
        .toast.ok  { background:var(--green); }
        .toast.err { background:var(--red); }
        .toast.show { transform:translateX(-50%) translateY(0); }

        /* ── Estado vacío ────────────────────────────────────────────────── */
        .empty-state {
            text-align:center; padding:48px 20px; color:var(--g5);
        }
        /* El icono SVG se escala a 40px via width/height en el contenedor */
        .empty-state .icon { margin-bottom:10px; display:flex; justify-content:center; }
        .empty-state .icon svg { width:40px; height:40px; }

        /* ════════════════════════════════════════════════════════════════
           RESPONSIVE
           ════════════════════════════════════════════════════════════════ */

        /* xs: < 480px — apilado, sin columnas secundarias */
        @media (max-width:479px) {
            .main    { padding:12px 10px 50px; }
            .kpis    { grid-template-columns:1fr 1fr; gap:8px; }
            .kpi-n   { font-size:17px; }
            /* Barra de acciones en columna para que quepa en pantallas pequeñas */
            .actions-bar { flex-direction:column; align-items:stretch; }
            .search-input, .filter-sel, .btn { min-height:44px; width:100%; }
            /* Ocultar columnas no esenciales en móvil vertical */
            .hide-xs { display:none; }
            /* Tabla scrollable horizontalmente */
            table    { min-width:400px; }
            /* Modales en full-width, esquinas redondeadas arriba */
            .modal   { max-width:100% !important; border-radius:20px 20px 0 0 !important; }
            .fg-row  { grid-template-columns:1fr; }  /* columnas del form en vertical */
        }

        /* 360px: teléfonos Android pequeños */
        @media (max-width:360px) {
            .kpis    { grid-template-columns:1fr 1fr; gap:6px; }
            .kpi-n   { font-size:15px; }
            .kpi-l   { font-size:9px; }
        }

        /* sm: 480–639px */
        @media (min-width:480px) and (max-width:639px) {
            .kpis { grid-template-columns:1fr 1fr; }
            .hide-sm { display:none; }
        }

        /* md: 640–1023px */
        @media (min-width:640px) and (max-width:1023px) {
            .kpis { grid-template-columns:repeat(4,1fr); }
        }

        /* ≥ 1600px */
        @media (min-width:1600px) {
            .main  { max-width:1400px; }
            .kpi-n { font-size:24px; }
            thead th, tbody td { padding:12px 16px; font-size:14px; }
        }
        /* TV ≥ 1920px */
        @media (min-width:1920px) {
            .main { max-width:1700px; }
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/../app/views/nav.php'; ?>

<main class="main">

    <!-- KPIs del módulo -->
    <div class="kpis">
        <div class="kpi">
            <div class="kpi-n"><?= $total_activos ?></div>
            <div class="kpi-l">Clientes activos</div>
        </div>
        <div class="kpi">
            <div class="kpi-n" style="color:var(--red)"><?= $con_deuda ?></div>
            <div class="kpi-l">Con deuda</div>
        </div>
        <div class="kpi">
            <div class="kpi-n" style="color:var(--yellow)">$<?= number_format($deuda_total,0,',','.') ?></div>
            <div class="kpi-l">Total fiado</div>
        </div>
        <div class="kpi">
            <div class="kpi-n"><?= $total_ventas ?></div>
            <div class="kpi-l">Ventas totales</div>
        </div>
    </div>

    <!-- Barra de búsqueda y acciones -->
    <div class="actions-bar">
        <input type="text" class="search-input" id="busq"
               placeholder="Buscar por nombre, empresa o teléfono…"
               oninput="filtrar()">
        <select class="filter-sel" id="filtro-estado" onchange="filtrar()">
            <option value="activos">Solo activos</option>
            <option value="todos">Todos</option>
            <option value="inactivos">Solo inactivos</option>
            <option value="deuda">Con deuda</option>
        </select>
        <?php if (permiso_tiene('ventas','editar_existentes')): ?>
        <button class="btn btn-primary" onclick="abrirNuevo()">+ Nuevo Cliente</button>
        <?php endif; ?>
    </div>

    <!-- Tabla de clientes -->
    <div class="tbl-card">
        <table id="tbl-clientes">
            <thead>
                <tr>
                    <th>Cliente</th>
                    <th class="hide-sm">Empresa</th>
                    <th class="hide-sm">Teléfono</th>
                    <th class="r">Deuda fiado</th>
                    <th class="r hide-xs">Ventas</th>
                    <th class="hide-xs">Última compra</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="tbody">
            <?php foreach ($clientes as $c):
                $nc = htmlspecialchars($c['nombre'] . (!empty($c['apellido']) ? ' '.$c['apellido'] : ''));
            ?>
            <tr class="<?= !(int)$c['activo'] ? 'inactivo' : '' ?>"
                data-nombre="<?= strtolower($c['nombre'].' '.($c['apellido']??'').' '.($c['empresa']??'').' '.($c['telefono']??'')) ?>"
                data-activo="<?= (int)$c['activo'] ?>"
                data-deuda="<?= (float)$c['saldo_fiado'] > 0 ? '1' : '0' ?>">

                <td>
                    <div style="font-weight:700"><?= $nc ?></div>
                    <?php if ((float)$c['saldo_fiado'] > 0): ?>
                    <span class="badge b-deuda">Fiado $<?= number_format($c['saldo_fiado'],0,',','.') ?></span>
                    <?php elseif ((int)$c['activo']): ?>
                    <span class="badge b-ok">Activo</span>
                    <?php else: ?>
                    <span class="badge b-inac">Inactivo</span>
                    <?php endif; ?>
                </td>
                <td class="hide-sm" style="color:var(--g5)"><?= htmlspecialchars($c['empresa'] ?? '—') ?></td>
                <td class="hide-sm" style="color:var(--g5)"><?= htmlspecialchars($c['telefono'] ?? '—') ?></td>
                <td class="r">
                    <?php if ((float)$c['saldo_fiado'] > 0): ?>
                    <strong style="color:var(--red)">$<?= number_format($c['saldo_fiado'],0,',','.') ?></strong>
                    <?php else: ?>
                    <span style="color:var(--g5)">—</span>
                    <?php endif; ?>
                </td>
                <td class="r hide-xs"><?= (int)$c['total_ventas'] ?></td>
                <td class="hide-xs" style="font-size:12px;color:var(--g5)">
                    <?= $c['ultima_compra'] ? date('d/m/Y', strtotime($c['ultima_compra'])) : '—' ?>
                </td>
                <td style="white-space:nowrap">
                    <?php if (permiso_tiene('ventas','editar_existentes')): ?>
                    <!-- Editar cliente: abre el modal de edición pre-cargado -->
                    <button class="btn-acc ic" title="Editar cliente"
                            onclick="abrirEditar(<?= htmlspecialchars(json_encode([
                                'id'       => (int)$c['id'],
                                'nombre'   => $c['nombre'],
                                'apellido' => $c['apellido'] ?? '',
                                'empresa'  => $c['empresa']  ?? '',
                                'telefono' => $c['telefono'] ?? '',
                            ])) ?>)"><?= IC_EDIT ?></button>
                    <!-- Fusionar: solo disponible para clientes activos -->
                    <?php if ((int)$c['activo']): ?>
                    <button class="btn-acc ic" title="Fusionar con otro cliente"
                            onclick="abrirFusion(<?= (int)$c['id'] ?>, '<?= htmlspecialchars(addslashes($nc)) ?>', <?= (float)$c['saldo_fiado'] ?>)"
                            ><?= IC_MERGE ?></button>
                    <?php endif; ?>
                    <!-- Abonar: solo si tiene deuda pendiente -->
                    <?php if ((float)$c['saldo_fiado'] > 0): ?>
                    <button class="btn-acc ic" title="Registrar pago de fiado" style="color:var(--green)"
                            onclick="abrirAbono(<?= (int)$c['id'] ?>, '<?= htmlspecialchars(addslashes($nc)) ?>', <?= number_format((float)$c['saldo_fiado'], 2, '.', '') ?>)"
                            ><?= IC_CASH ?></button>
                    <?php endif; ?>
                    <?php endif; ?>

                    <?php if (permiso_tiene('ventas','admin_total')): ?>
                    <!-- Toggle activo/inactivo. No se puede desactivar si tiene deuda pendiente -->
                    <button class="btn-acc ic <?= (int)$c['activo'] ? 'danger' : '' ?>"
                            title="<?= (int)$c['activo'] ? 'Desactivar cliente' : 'Reactivar cliente' ?>"
                            onclick="toggleCliente(<?= (int)$c['id'] ?>, <?= (int)$c['activo'] ?>)"
                            ><?= (int)$c['activo'] ? IC_PAUSE : IC_PLAY ?></button>
                    <?php endif; ?>

                    <!-- Ver historial de ventas filtrado por este cliente -->
                    <a href="<?= APP_BASE ?>/ventas/historial.php?cliente_id=<?= (int)$c['id'] ?>"
                       class="btn-acc ic" title="Ver historial de ventas"><?= IC_EYE ?></a>
                    <!-- Estado de cuenta: historial completo de cargos + abonos con saldo corriente -->
                    <a href="<?= APP_BASE ?>/clientes/estado_cuenta.php?id=<?= (int)$c['id'] ?>"
                       class="btn-acc ic"
                       title="Estado de cuenta / Extracto"><?= IC_RECEIPT ?></a>
                    <!-- WhatsApp recordatorio: visible para todos si tiene teléfono y deuda -->
                    <?php if (!empty($c['telefono']) && (float)$c['saldo_fiado'] > 0): ?>
                    <?php
                    $tel_n  = preg_replace('/[^0-9]/', '', $c['telefono']);
                    $tel_wa = (strlen($tel_n) === 10 && str_starts_with($tel_n, '3')) ? '57'.$tel_n : $tel_n;
                    $s_fmt  = '$' . number_format((float)$c['saldo_fiado'], 0, ',', '.');
                    $msg_wa = rawurlencode(
                        "Hola {$c['nombre']}, te recordamos que tienes un saldo pendiente de {$s_fmt} en "
                        . APP_NAME . ". ¿Cuándo podemos acordar el pago? ¡Gracias! 🙏"
                    );
                    ?>
                    <a href="https://wa.me/<?= $tel_wa ?>?text=<?= $msg_wa ?>" target="_blank"
                       rel="noopener noreferrer" class="btn-acc ic"
                       title="Recordatorio de pago por WhatsApp" style="color:#25d366"><?= IC_WA ?></a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($clientes)): ?>
            <tr>
                <td colspan="7">
                    <div class="empty-state">
                        <!-- Ícono SVG consistente con el sistema (IC_USERS) -->
                        <div class="icon" style="color:var(--g5)"><?= IC_USERS ?></div>
                        <div>No hay clientes registrados aún.</div>
                    </div>
                </td>
            </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div id="empty-filter" style="display:none;text-align:center;padding:32px;color:var(--g5)">
        No se encontraron clientes con ese criterio.
    </div>

</main>

<!-- ══ MODAL NUEVO CLIENTE ════════════════════════════════════════════════ -->
<?php if (permiso_tiene('ventas','editar_existentes')): ?>
<div class="overlay" id="modal-nc" onclick="if(event.target===this)cerrarModal('modal-nc')">
    <div class="modal" role="dialog" aria-modal="true" aria-label="Nuevo cliente">
        <div class="modal-hdr">
            Nuevo Cliente
            <button class="btn-cls" onclick="cerrarModal('modal-nc')" aria-label="Cerrar">&#x2715;</button>
        </div>
        <div class="modal-body">
            <div class="fg-row">
                <div class="fg">
                    <label>Nombre *</label>
                    <input id="nc-nom" placeholder="Juan" maxlength="100" autocomplete="off">
                </div>
                <div class="fg">
                    <label>Apellido</label>
                    <input id="nc-ape" placeholder="García" maxlength="100" autocomplete="off">
                </div>
            </div>
            <div class="fg">
                <label>Empresa (opcional)</label>
                <input id="nc-emp" placeholder="Nombre de la empresa o negocio" maxlength="150">
            </div>
            <div class="fg">
                <label>Teléfono (opcional)</label>
                <input id="nc-tel" type="tel" placeholder="3001234567" maxlength="20">
            </div>
            <button class="btn-full" onclick="guardarNuevo()">Crear Cliente</button>
        </div>
    </div>
</div>

<!-- ══ MODAL EDITAR CLIENTE ══════════════════════════════════════════════ -->
<div class="overlay" id="modal-ec" onclick="if(event.target===this)cerrarModal('modal-ec')">
    <div class="modal" role="dialog" aria-modal="true" aria-label="Editar cliente">
        <div class="modal-hdr">
            Editar Cliente
            <button class="btn-cls" onclick="cerrarModal('modal-ec')" aria-label="Cerrar">&#x2715;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="ec-id">
            <div class="fg-row">
                <div class="fg">
                    <label>Nombre *</label>
                    <input id="ec-nom" maxlength="100" autocomplete="off">
                </div>
                <div class="fg">
                    <label>Apellido</label>
                    <input id="ec-ape" maxlength="100" autocomplete="off">
                </div>
            </div>
            <div class="fg">
                <label>Empresa (opcional)</label>
                <input id="ec-emp" maxlength="150">
            </div>
            <div class="fg">
                <label>Teléfono (opcional)</label>
                <input id="ec-tel" type="tel" maxlength="20">
            </div>
            <button class="btn-full" onclick="guardarEditar()">Guardar Cambios</button>
        </div>
    </div>
</div>

<!-- ══ MODAL FUSIONAR CLIENTES ═══════════════════════════════════════════ -->
<div class="overlay" id="modal-fu" onclick="if(event.target===this)cerrarModal('modal-fu')">
    <div class="modal" role="dialog" aria-modal="true" aria-label="Fusionar clientes">
        <div class="modal-hdr">
            <!-- Ícono SVG de fusión consistente con el sistema -->
            <span style="display:inline-flex;align-items:center;gap:6px">
                <?= IC_MERGE ?> Fusionar Clientes
            </span>
            <button class="btn-cls" onclick="cerrarModal('modal-fu')" aria-label="Cerrar">&#x2715;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="fu-pid">

            <!-- Cliente principal (el que permanece) -->
            <div class="fg">
                <label>Cliente que PERMANECE (principal)</label>
                <input id="fu-principal-nom" readonly
                       style="background:var(--g9);cursor:default">
                <span class="hint">Este cliente conserva todos los datos y el historial combinado.</span>
            </div>

            <!-- Selector del cliente a absorber -->
            <div class="fg">
                <label>Cliente a ABSORBER (se desactiva después)</label>
                <select id="fu-secundario" onchange="actualizarPreviewFusion()">
                    <option value="">— Selecciona el cliente a fusionar —</option>
                    <?php foreach ($clientes as $c):
                        if (!(int)$c['activo']) continue;
                        $nc = $c['nombre'] . (!empty($c['apellido']) ? ' '.$c['apellido'] : '');
                    ?>
                    <option value="<?= $c['id'] ?>"
                            data-nombre="<?= htmlspecialchars($nc) ?>"
                            data-ventas="<?= (int)$c['total_ventas'] ?>"
                            data-saldo="<?= (float)$c['saldo_fiado'] ?>">
                        <?= htmlspecialchars($nc) ?>
                        <?php if ((float)$c['saldo_fiado'] > 0): ?>
                        (Fiado: $<?= number_format($c['saldo_fiado'],0,',','.') ?>)
                        <?php endif; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Vista previa de lo que se va a transferir -->
            <div class="fusion-preview" id="fu-preview" style="display:none">
                <strong>Vista previa de la fusión:</strong>
                <div class="row"><span>Ventas transferidas</span><span id="fu-prev-ventas">—</span></div>
                <div class="row"><span>Deuda fiado transferida</span><span id="fu-prev-saldo">—</span></div>
                <div class="row"><span>Cliente que se desactiva</span><span id="fu-prev-nombre">—</span></div>
            </div>

            <!-- Advertencia antes de confirmar -->
            <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;
                        padding:10px 14px;font-size:12px;color:#92400e;margin-bottom:14px">
                <strong>Esta acción no se puede deshacer.</strong>
                Todas las ventas y abonos del cliente absorbido quedarán asociados al cliente principal.
                El cliente absorbido se desactivará automáticamente.
            </div>

            <button class="btn-full warning" id="fu-btn" onclick="confirmarFusion()" disabled>
                Fusionar Clientes
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ══ MODAL ABONO / PAGO DE FIADO ════════════════════════════════════════ -->
<?php if (permiso_tiene('ventas','editar_existentes')): ?>
<div class="overlay" id="modal-ab" onclick="if(event.target===this)cerrarModal('modal-ab')">
    <div class="modal" role="dialog" aria-modal="true" aria-label="Registrar abono">
        <div class="modal-hdr">
            Registrar Pago / Abono
            <button class="btn-cls" onclick="cerrarModal('modal-ab')" aria-label="Cerrar">&#x2715;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="ab-id">
            <p id="ab-cliente-info" style="font-size:13px;color:var(--g5);margin-bottom:14px;
               background:var(--g9);border-radius:8px;padding:8px 12px;line-height:1.5"></p>
            <div class="fg">
                <label>Monto del pago ($) *</label>
                <input type="number" id="ab-monto" min="1" step="100" inputmode="numeric"
                       placeholder="0" oninput="actualizarSaldoPreview()">
            </div>
            <p id="ab-saldo-preview" style="font-size:12px;color:var(--g5);margin:-6px 0 12px;display:none">
                Saldo tras el pago:
                <strong id="ab-saldo-nuevo" style="color:var(--green)">$0</strong>
            </p>
            <div class="fg">
                <label>Método de pago *</label>
                <select id="ab-metodo">
                    <option value="efectivo">💵 Efectivo</option>
                    <option value="nequi">📱 Nequi</option>
                    <option value="daviplata">📱 Daviplata</option>
                    <option value="bancolombia">🏦 Bancolombia</option>
                </select>
            </div>
            <div class="fg">
                <label>Notas (opcional)</label>
                <input id="ab-notas" maxlength="255" placeholder="Observación sobre el pago">
            </div>
            <button class="btn-full" id="ab-btn" onclick="guardarAbono()">Registrar Abono</button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Token CSRF para las peticiones fetch -->
<input type="hidden" id="csrf-tk" value="<?= htmlspecialchars(csrf_token()) ?>">
<div class="toast" id="toast"></div>

<script>
/* ── Utilidades ─────────────────────────────────────────────────────────── */
const csrf = () => document.getElementById('csrf-tk').value;

// Muestra un mensaje temporal en pantalla
function toast(msg, tipo) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className   = 'toast ' + (tipo || '');
    void t.offsetWidth; // fuerza reflow para reiniciar la animación CSS
    t.classList.add('show');
    clearTimeout(t._t);
    t._t = setTimeout(() => t.classList.remove('show'), 3000);
}

function cerrarModal(id) {
    document.getElementById(id).classList.remove('on');
}

// Cierra cualquier modal abierto con la tecla Escape
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.overlay.on').forEach(m => m.classList.remove('on'));
    }
});

/* ── Filtro de tabla en tiempo real ────────────────────────────────────── */
function filtrar() {
    const q      = document.getElementById('busq').value.toLowerCase().trim();
    const estado = document.getElementById('filtro-estado').value;
    const filas  = document.querySelectorAll('#tbody tr[data-nombre]');
    let   vis    = 0;

    filas.forEach(tr => {
        const nombre  = tr.dataset.nombre || '';
        const activo  = tr.dataset.activo === '1';
        const deuda   = tr.dataset.deuda  === '1';

        // Filtro de texto — busca en nombre, apellido, empresa, teléfono
        const matchTxt = !q || nombre.includes(q);

        // Filtro de estado
        const matchEst =
            estado === 'todos'     ? true :
            estado === 'activos'   ? activo :
            estado === 'inactivos' ? !activo :
            estado === 'deuda'     ? deuda : true;

        const visible = matchTxt && matchEst;
        tr.style.display = visible ? '' : 'none';
        if (visible) vis++;
    });

    // Muestra mensaje cuando ninguna fila coincide
    document.getElementById('empty-filter').style.display = vis === 0 ? 'block' : 'none';
    document.querySelector('.tbl-card').style.display      = vis > 0  ? '' : 'none';
}

/* ── Modal Nuevo Cliente ────────────────────────────────────────────────── */
function abrirNuevo() {
    // Limpiar campos antes de abrir
    ['nc-nom','nc-ape','nc-emp','nc-tel'].forEach(id =>
        document.getElementById(id).value = '');
    document.getElementById('modal-nc').classList.add('on');
    setTimeout(() => document.getElementById('nc-nom').focus(), 100);
}

async function guardarNuevo() {
    const nombre = document.getElementById('nc-nom').value.trim();
    if (!nombre) { toast('El nombre es obligatorio', 'err'); return; }

    const fd = new FormData();
    fd.append('csrf_token', csrf());
    fd.append('accion',   'crear');
    fd.append('nombre',   nombre);
    fd.append('apellido', document.getElementById('nc-ape').value.trim());
    fd.append('empresa',  document.getElementById('nc-emp').value.trim());
    fd.append('telefono', document.getElementById('nc-tel').value.trim());

    try {
        const r = await fetch('api/crud.php', { method:'POST', body:fd });
        const d = await r.json();
        if (d.success) {
            cerrarModal('modal-nc');
            toast('Cliente creado correctamente', 'ok');
            // Recargar para reflejar el nuevo cliente en la tabla
            setTimeout(() => location.reload(), 900);
        } else {
            toast(d.error || 'Error al crear cliente', 'err');
        }
    } catch(e) {
        toast('Error de conexión', 'err');
    }
}

/* ── Modal Editar Cliente ───────────────────────────────────────────────── */
function abrirEditar(c) {
    document.getElementById('ec-id').value  = c.id;
    document.getElementById('ec-nom').value = c.nombre;
    document.getElementById('ec-ape').value = c.apellido || '';
    document.getElementById('ec-emp').value = c.empresa  || '';
    document.getElementById('ec-tel').value = c.telefono || '';
    document.getElementById('modal-ec').classList.add('on');
    setTimeout(() => document.getElementById('ec-nom').focus(), 100);
}

async function guardarEditar() {
    const nombre = document.getElementById('ec-nom').value.trim();
    if (!nombre) { toast('El nombre es obligatorio', 'err'); return; }

    const fd = new FormData();
    fd.append('csrf_token', csrf());
    fd.append('accion',   'editar');
    fd.append('id',       document.getElementById('ec-id').value);
    fd.append('nombre',   nombre);
    fd.append('apellido', document.getElementById('ec-ape').value.trim());
    fd.append('empresa',  document.getElementById('ec-emp').value.trim());
    fd.append('telefono', document.getElementById('ec-tel').value.trim());

    try {
        const r = await fetch('api/crud.php', { method:'POST', body:fd });
        const d = await r.json();
        if (d.success) {
            cerrarModal('modal-ec');
            toast('Cliente actualizado', 'ok');
            setTimeout(() => location.reload(), 900);
        } else {
            toast(d.error || 'Error al guardar', 'err');
        }
    } catch(e) {
        toast('Error de conexión', 'err');
    }
}

/* ── Toggle activo/inactivo ─────────────────────────────────────────────── */
async function toggleCliente(id, activo) {
    const msg = activo
        ? '¿Desactivar este cliente? No afecta el historial de ventas.'
        : '¿Reactivar este cliente?';
    if (!confirm(msg)) return;

    const fd = new FormData();
    fd.append('csrf_token', csrf());
    fd.append('accion', 'toggle');
    fd.append('id', id);

    try {
        const r = await fetch('api/crud.php', { method:'POST', body:fd });
        const d = await r.json();
        if (d.success) {
            toast(d.activo ? 'Cliente reactivado' : 'Cliente desactivado', 'ok');
            setTimeout(() => location.reload(), 800);
        } else {
            toast(d.error || 'Error', 'err');
        }
    } catch(e) {
        toast('Error de conexión', 'err');
    }
}

/* ── Modal Fusionar Clientes ────────────────────────────────────────────── */
// Abre el modal de fusión pre-cargando el cliente principal
function abrirFusion(pid, nombre, saldo) {
    document.getElementById('fu-pid').value          = pid;
    document.getElementById('fu-principal-nom').value = nombre;
    document.getElementById('fu-preview').style.display = 'none';
    document.getElementById('fu-btn').disabled         = true;

    // Excluir al propio cliente del selector de secundario
    const sel = document.getElementById('fu-secundario');
    sel.value = '';
    Array.from(sel.options).forEach(o => {
        // Ocultar la opción correspondiente al cliente principal
        o.style.display = (o.value && parseInt(o.value) === pid) ? 'none' : '';
    });

    document.getElementById('modal-fu').classList.add('on');
}

// Actualiza la vista previa cuando cambia el selector del cliente secundario
function actualizarPreviewFusion() {
    const sel  = document.getElementById('fu-secundario');
    const opt  = sel.options[sel.selectedIndex];
    const btn  = document.getElementById('fu-btn');
    const prev = document.getElementById('fu-preview');

    if (!sel.value) {
        prev.style.display = 'none';
        btn.disabled = true;
        return;
    }

    // Mostrar datos del cliente secundario seleccionado
    const nombre  = opt.dataset.nombre  || '—';
    const ventas  = opt.dataset.ventas  || 0;
    const saldo   = parseFloat(opt.dataset.saldo || 0);
    const saldoFmt = saldo > 0
        ? '$' + saldo.toLocaleString('es-CO', {maximumFractionDigits:0})
        : '—';

    document.getElementById('fu-prev-nombre').textContent = nombre;
    document.getElementById('fu-prev-ventas').textContent = ventas + ' venta(s)';
    document.getElementById('fu-prev-saldo').textContent  = saldoFmt;

    prev.style.display = 'block';
    btn.disabled = false;
}

// Ejecuta la fusión después de la confirmación final del usuario
async function confirmarFusion() {
    const pId = document.getElementById('fu-pid').value;
    const sId = document.getElementById('fu-secundario').value;
    const sNom= document.getElementById('fu-secundario').options[
        document.getElementById('fu-secundario').selectedIndex
    ].dataset.nombre;
    const pNom= document.getElementById('fu-principal-nom').value;

    if (!sId) { toast('Selecciona el cliente a absorber', 'err'); return; }

    if (!confirm(
        `¿Confirmar fusión?\n\n` +
        `"${sNom}" será absorbido por "${pNom}".\n` +
        `Todas sus ventas y abonos se transferirán al cliente principal.\n` +
        `Esta acción NO se puede deshacer.`
    )) return;

    const fd = new FormData();
    fd.append('csrf_token',     csrf());
    fd.append('principal_id',   pId);
    fd.append('secundario_id',  sId);

    document.getElementById('fu-btn').disabled   = true;
    document.getElementById('fu-btn').textContent = 'Fusionando…';

    try {
        const r = await fetch('api/fusionar.php', { method:'POST', body:fd });
        const d = await r.json();
        if (d.success) {
            cerrarModal('modal-fu');
            toast(
                `Fusión completada: ${d.ventas} venta(s) y $${d.saldo} transferidos`, 'ok'
            );
            setTimeout(() => location.reload(), 1200);
        } else {
            toast(d.error || 'Error al fusionar', 'err');
            document.getElementById('fu-btn').disabled   = false;
            document.getElementById('fu-btn').textContent = 'Fusionar Clientes';
        }
    } catch(e) {
        toast('Error de conexión', 'err');
        document.getElementById('fu-btn').disabled   = false;
        document.getElementById('fu-btn').textContent = 'Fusionar Clientes';
    }
}

// Aplicar filtro inicial al cargar (muestra solo activos por defecto)
filtrar();

/* ── Abono / pago de fiado ─────────────────────────────────────────────── */
let _abSaldoActual = 0;

function abrirAbono(id, nombre, saldo) {
    _abSaldoActual = saldo;
    document.getElementById('ab-id').value    = id;
    document.getElementById('ab-monto').value = '';
    document.getElementById('ab-monto').max   = saldo;
    document.getElementById('ab-notas').value = '';
    document.getElementById('ab-cliente-info').textContent =
        nombre + ' — Deuda: $' + saldo.toLocaleString('es-CO', {maximumFractionDigits: 0});
    document.getElementById('ab-saldo-preview').style.display = 'none';
    const btn = document.getElementById('ab-btn');
    btn.disabled    = false;
    btn.textContent = 'Registrar Abono';
    document.getElementById('modal-ab').classList.add('on');
    setTimeout(() => document.getElementById('ab-monto').focus(), 80);
}

function actualizarSaldoPreview() {
    const monto   = parseFloat(document.getElementById('ab-monto').value) || 0;
    const preview = document.getElementById('ab-saldo-preview');
    if (monto > 0) {
        const nuevo = Math.max(0, _abSaldoActual - monto);
        document.getElementById('ab-saldo-nuevo').textContent =
            '$' + nuevo.toLocaleString('es-CO', {maximumFractionDigits: 0});
        preview.style.display = 'block';
    } else {
        preview.style.display = 'none';
    }
}

async function guardarAbono() {
    const id     = document.getElementById('ab-id').value;
    const monto  = parseFloat(document.getElementById('ab-monto').value);
    const metodo = document.getElementById('ab-metodo').value;
    const notas  = document.getElementById('ab-notas').value.trim();

    if (!monto || monto <= 0) { toast('Ingresa un monto válido', 'err'); return; }

    const btn = document.getElementById('ab-btn');
    btn.disabled    = true;
    btn.textContent = 'Guardando…';

    const fd = new FormData();
    fd.append('csrf_token', csrf());
    fd.append('cliente_id', id);
    fd.append('monto',      monto);
    fd.append('metodo_pago', metodo);
    fd.append('notas',       notas);

    try {
        const resp = await fetch('api/registrar_abono.php', { method: 'POST', body: fd });
        const data = await resp.json();
        if (data.success) {
            cerrarModal('modal-ab');
            const fmt = monto.toLocaleString('es-CO', {maximumFractionDigits: 0});
            toast('Abono de $' + fmt + ' registrado ✓', 'ok');
            setTimeout(() => location.reload(), 1200);
        } else {
            toast(data.error || 'Error al registrar el abono', 'err');
            btn.disabled    = false;
            btn.textContent = 'Registrar Abono';
        }
    } catch (e) {
        toast('Error de red. Intenta de nuevo.', 'err');
        btn.disabled    = false;
        btn.textContent = 'Registrar Abono';
    }
}
</script>
</body>
</html>
