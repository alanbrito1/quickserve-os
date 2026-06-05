<?php
/**
 * public_html/ventas/fiado.php
 * Gestión de deudas de clientes (fiado).
 * Permite ver saldos pendientes y registrar abonos.
 */

require_once __DIR__ . '/../app/middleware/auth_check.php';
require_once __DIR__ . '/../app/models/ClienteModel.php';

// Ver fiado requiere poder editar ventas; registrar abonos también
permiso_requerir('ventas', 'editar_existentes');

$msg_ok  = '';
$msg_err = '';

// ---- Procesar abono ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    if (!csrf_verificar()) {
        $msg_err = 'Token de seguridad inválido.';
    } else {
        $accion = $_POST['accion'];

        if ($accion === 'abonar') {
            try {
                $cliente_id = (int)($_POST['cliente_id'] ?? 0);
                $monto      = (float)str_replace(['.', ','], ['', '.'], $_POST['monto'] ?? '0');
                $metodo     = $_POST['metodo_pago'] ?? 'efectivo';
                ClienteModel::registrar_abono($cliente_id, $monto, $metodo);
                $msg_ok = 'Abono de $' . number_format($monto, 0, ',', '.') . ' registrado correctamente.';
            } catch (RuntimeException $e) {
                $msg_err = $e->getMessage();
            }

        } elseif ($accion === 'nuevo_cliente') {
            try {
                $id = ClienteModel::crear($_POST['nombre'] ?? '', $_POST['telefono'] ?? '');
                $msg_ok = 'Cliente creado con ID #' . $id;
            } catch (RuntimeException $e) {
                $msg_err = $e->getMessage();
            }
        }
    }
}

$clientes_fiado = ClienteModel::con_fiado();
$todos_clientes = ClienteModel::todos();

$total_fiado = array_sum(array_column($clientes_fiado, 'saldo_fiado'));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fiado — <?= APP_NAME ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root { --brand:#e94f37; --dark:#111827; --g1:#1f2937; --g2:#374151; --g5:#6b7280; --g8:#d1d5db; --g9:#f3f4f6; --white:#fff; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background:var(--g9); color:var(--dark); min-height:100vh; }

        .header { background:var(--dark); color:var(--white); height:54px; padding:0 14px; display:flex; align-items:center; justify-content:space-between; position:sticky; top:0; z-index:50; box-shadow:0 2px 8px rgba(0,0,0,.35); }
        .header-brand { font-size:17px; font-weight:800; } .header-brand span{color:var(--brand);}
        .header-nav { display:flex; gap:6px; }
        .nav-link { color:var(--g8); text-decoration:none; font-size:13px; padding:5px 10px; border-radius:8px; }
        .nav-link:hover { background:var(--g2); color:var(--white); }
        .nav-link.active { background:var(--brand); color:var(--white); }

        .main { padding:16px 14px; max-width:760px; margin:0 auto; }
        .card { background:var(--white); border-radius:14px; padding:16px; margin-bottom:14px; box-shadow:0 1px 4px rgba(0,0,0,.06); }
        .card-title { font-size:14px; font-weight:700; margin-bottom:12px; }

        .stat-total { font-size:28px; font-weight:800; color:var(--brand); }
        .stat-sub { font-size:13px; color:var(--g5); margin-top:4px; }

        /* Lista de clientes con fiado */
        .cliente-row { padding:12px 0; border-bottom:1px solid var(--g9); }
        .cliente-row:last-child { border-bottom:none; }
        .cliente-nombre { font-size:15px; font-weight:700; }
        .cliente-tel { font-size:12px; color:var(--g5); }
        .cliente-saldo { font-size:17px; font-weight:800; color:var(--brand); }
        .cliente-ultima { font-size:11px; color:var(--g5); margin-top:2px; }
        .cliente-actions { margin-top:8px; }

        /* Formulario de abono inline */
        .abono-form { display:none; background:var(--g9); border-radius:10px; padding:12px; margin-top:8px; }
        .abono-form.open { display:block; }
        .form-row { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:8px; align-items:flex-end; }
        .form-group { display:flex; flex-direction:column; gap:4px; flex:1; min-width:120px; }
        .lbl { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--g5); }
        input[type="number"], input[type="text"], select {
            padding:10px 12px;
            border:2px solid var(--g8);
            border-radius:10px;
            font-size:14px;
            color:var(--dark);
            background:var(--white);
            outline:none;
            width:100%;
        }
        input:focus, select:focus { border-color:var(--brand); }
        .btn-abonar { padding:10px 20px; background:var(--brand); color:var(--white); border:none; border-radius:10px; font-size:14px; font-weight:700; cursor:pointer; }
        .btn-abonar:hover { background:#c73d28; }
        .btn-ghost { padding:10px 14px; background:var(--g8); color:var(--g2); border:none; border-radius:10px; font-size:13px; font-weight:600; cursor:pointer; }
        .btn-open { padding:6px 14px; background:var(--g9); color:var(--brand); border:1px solid var(--brand); border-radius:8px; font-size:12px; font-weight:700; cursor:pointer; }
        .btn-open:hover { background:#fef2f0; }

        /* Alertas */
        .alert { padding:12px 14px; border-radius:10px; font-size:14px; margin-bottom:14px; }
        .alert-ok  { background:#d1fae5; color:#065f46; border:1px solid #6ee7b7; }
        .alert-err { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; }

        .empty-state { text-align:center; padding:40px 0; color:var(--g5); }

        /* Nuevo cliente */
        .nc-grid { display:grid; grid-template-columns:1fr 1fr; gap:8px; }
        @media(max-width:400px){.nc-grid{grid-template-columns:1fr;}}
    </style>
</head>
<body>
<?php $nav_activo = 'ventas'; include __DIR__ . '/../app/views/nav.php'; ?>


<main class="main">

    <?php if ($msg_ok):  ?><div class="alert alert-ok"><?= htmlspecialchars($msg_ok) ?></div><?php endif; ?>
    <?php if ($msg_err): ?><div class="alert alert-err"><?= htmlspecialchars($msg_err) ?></div><?php endif; ?>

    <!-- Resumen total fiado -->
    <div class="card">
        <div class="stat-total">$<?= number_format($total_fiado, 0, ',', '.') ?></div>
        <p class="stat-sub">deuda total pendiente · <?= count($clientes_fiado) ?> cliente<?= count($clientes_fiado) != 1 ? 's' : '' ?></p>
    </div>

    <!-- Lista de clientes con deuda -->
    <div class="card">
        <p class="card-title">Clientes con Saldo Pendiente</p>

        <?php if (empty($clientes_fiado)): ?>
        <div class="empty-state">
            <p style="font-size:28px; margin-bottom:8px">✅</p>
            <p style="font-weight:600">Sin deudas pendientes</p>
        </div>
        <?php else: ?>
            <?php foreach ($clientes_fiado as $c): ?>
            <div class="cliente-row">
                <div style="display:flex; justify-content:space-between; align-items:flex-start">
                    <div>
                        <div class="cliente-nombre"><?= htmlspecialchars($c['nombre']) ?></div>
                        <?php if ($c['telefono']): ?>
                        <div class="cliente-tel">📞 <?= htmlspecialchars($c['telefono']) ?></div>
                        <?php endif; ?>
                        <?php if ($c['ultima_compra']): ?>
                        <div class="cliente-ultima">Última compra: <?= date('d/m/Y H:i', strtotime($c['ultima_compra'])) ?></div>
                        <?php endif; ?>
                    </div>
                    <div style="text-align:right">
                        <div class="cliente-saldo">$<?= number_format($c['saldo_fiado'], 0, ',', '.') ?></div>
                        <div style="display:flex;gap:6px;justify-content:flex-end;margin-top:4px">
                            <button class="btn-open" onclick="toggleAbono(<?= $c['id'] ?>)">Registrar abono</button>
                            <!-- Estado de cuenta: ver historial completo de cargos y abonos -->
                            <a href="<?= APP_BASE ?>/clientes/estado_cuenta.php?id=<?= (int)$c['id'] ?>"
                               style="padding:6px 12px;background:#1e3a5f;color:#fff;border-radius:8px;font-size:12px;font-weight:700;text-decoration:none;white-space:nowrap">
                                📋 Extracto
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Formulario de abono (oculto por default) -->
                <form class="abono-form" id="form-<?= $c['id'] ?>" method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                    <input type="hidden" name="accion"     value="abonar">
                    <input type="hidden" name="cliente_id" value="<?= $c['id'] ?>">
                    <div class="form-row">
                        <div class="form-group">
                            <span class="lbl">Monto abono ($)</span>
                            <input type="number" name="monto" placeholder="0"
                                   min="1" max="<?= $c['saldo_fiado'] ?>" step="100" required>
                        </div>
                        <div class="form-group">
                            <span class="lbl">Método</span>
                            <select name="metodo_pago">
                                <option value="efectivo">Efectivo</option>
                                <option value="nequi">Nequi</option>
                                <option value="daviplata">Daviplata</option>
                                <option value="bancolombia">Bancolombia</option>
                            </select>
                        </div>
                    </div>
                    <div style="display:flex; gap:8px;">
                        <button type="submit" class="btn-abonar">Confirmar Abono</button>
                        <button type="button" class="btn-ghost" onclick="toggleAbono(<?= $c['id'] ?>)">Cancelar</button>
                    </div>
                </form>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Nuevo cliente -->
    <div class="card">
        <p class="card-title">Registrar Nuevo Cliente</p>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <input type="hidden" name="accion"     value="nuevo_cliente">
            <div class="nc-grid" style="margin-bottom:10px">
                <div class="form-group">
                    <span class="lbl">Nombre *</span>
                    <input type="text" name="nombre" placeholder="Nombre completo" required>
                </div>
                <div class="form-group">
                    <span class="lbl">Teléfono</span>
                    <input type="text" name="telefono" placeholder="Opcional">
                </div>
            </div>
            <button type="submit" class="btn-abonar">Guardar Cliente</button>
        </form>
    </div>

</main>

<script>
function toggleAbono(id) {
    const form = document.getElementById('form-' + id);
    form.classList.toggle('open');
    if (form.classList.contains('open')) {
        form.querySelector('input[name="monto"]').focus();
    }
}
</script>
</body>
</html>
