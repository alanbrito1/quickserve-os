<?php
/**
 * public_html/inventario/lista_compras.php
 * Lista de Compras Inteligente: insumos bajo stock de seguridad,
 * agrupados por proveedor con cantidades sugeridas y costo estimado.
 */

require_once __DIR__ . '/../app/middleware/auth_check.php';
require_once __DIR__ . '/../app/models/InsumoModel.php';

permiso_requerir('inventario', 'solo_ver');

$faltantes = InsumoModel::lista_compras();

// Agrupar por proveedor para facilitar la compra por proveedor
$por_proveedor = [];
foreach ($faltantes as $f) {
    $prov = $f['proveedor_nombre'] ?: 'Sin proveedor asignado';
    $por_proveedor[$prov][] = $f;
}

$total_estimado = array_sum(array_column($faltantes, 'costo_estimado'));
$fecha_generada = date('d/m/Y H:i');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lista de Compras — <?= APP_NAME ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root { --brand:#e94f37; --dark:#111827; --g2:#374151; --g5:#6b7280; --g8:#d1d5db; --g9:#f3f4f6; --white:#fff; --yellow:#d97706; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background:var(--g9); min-height:100vh; color:var(--dark); }

        .main { padding:16px 14px; max-width:800px; margin:0 auto; }

        /* Encabezado del listado */
        .list-header { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:16px; flex-wrap:wrap; gap:10px; }
        .list-title { font-size:20px; font-weight:800; }
        .list-meta  { font-size:12px; color:var(--g5); margin-top:4px; }
        .btn-print { padding:9px 16px; background:var(--white); border:1px solid var(--g8); border-radius:10px; font-size:13px; font-weight:700; cursor:pointer; color:var(--g2); }
        .btn-print:hover { border-color:var(--brand); color:var(--brand); }
        .act-bar { display:flex; gap:8px; margin-bottom:14px; flex-wrap:wrap; align-items:center; }
        .btn-primary { padding:9px 18px; background:var(--brand); color:#fff; border:none; border-radius:10px; font-size:13px; font-weight:700; cursor:pointer; text-decoration:none; }
        .btn-sec { padding:9px 16px; background:var(--white); color:var(--dark); border:1px solid var(--g8); border-radius:10px; font-size:13px; font-weight:700; text-decoration:none; cursor:pointer; }
        .btn-sec:hover { border-color:var(--brand); color:var(--brand); }

        /* Total estimado */
        .total-banner { background:var(--dark); color:var(--white); border-radius:14px; padding:16px 20px; margin-bottom:16px; display:flex; justify-content:space-between; align-items:center; }
        .total-label { font-size:12px; color:var(--g8); text-transform:uppercase; letter-spacing:.5px; }
        .total-val   { font-size:26px; font-weight:800; }

        /* Por proveedor */
        .proveedor-card { background:var(--white); border-radius:14px; box-shadow:0 1px 4px rgba(0,0,0,.06); margin-bottom:14px; overflow:hidden; overflow-x:auto; }
        .proveedor-hdr { background:var(--g9); padding:12px 16px; border-bottom:1px solid var(--g8); }
        .proveedor-nombre { font-size:14px; font-weight:800; }
        .proveedor-total  { font-size:12px; color:var(--g5); margin-top:2px; }

        /* Tabla insumos */
        table { width:100%; border-collapse:collapse; }
        th { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--g5); padding:10px 16px; text-align:left; }
        td { padding:11px 16px; border-top:1px solid var(--g9); font-size:14px; }
        td.num { text-align:right; font-weight:700; }

        .badge { font-size:10px; font-weight:700; padding:2px 8px; border-radius:20px; }
        .b-ago  { background:#fee2e2; color:#991b1b; }
        .b-bajo { background:#fef3c7; color:#92400e; }

        /* Checkbox para marcar como comprado */
        td.check { width:32px; }
        input[type="checkbox"] { width:18px; height:18px; cursor:pointer; accent-color:var(--brand); }
        tr.comprado td { opacity:.4; text-decoration:line-through; }

        /* Estado vacío */
        .empty { text-align:center; padding:60px 16px; }
        .empty p { font-size:28px; margin-bottom:12px; }

        /* Print styles */
        @media print {
            .hdr, .btn-print, .no-print { display:none !important; }
            body { background:white; }
            .proveedor-card { box-shadow:none; border:1px solid #ccc; }
            .total-banner { background:#222; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
        }
    </style>
</head>
<body>
<?php $nav_activo = 'compras'; include __DIR__ . '/../app/views/nav.php'; ?>


<main class="main">

    <!-- Barra de acciones consistente con el resto del módulo inventario -->
    <div class="act-bar no-print">
        <?php if (permiso_tiene('inventario','editar_existentes')): ?>
        <button class="btn-primary" onclick="document.dispatchEvent(new CustomEvent('abrirNuevoInsumo'))">
            + Agregar Insumo
        </button>
        <button class="btn-primary" style="background:#374151"
                onclick="document.dispatchEvent(new CustomEvent('abrirNuevoProveedor'))">
            + Proveedor
        </button>
        <?php endif; ?>
        <a href="<?= APP_BASE ?>/inventario/" class="btn-sec">&#128230; Inventario</a>
        <?php if (permiso_tiene('compras','solo_propios')): ?>
        <a href="<?= APP_BASE ?>/inventario/compras.php" class="btn-sec">Registrar Compra</a>
        <?php endif; ?>
        <button class="btn-sec no-print" onclick="window.print()">&#128424; Imprimir</button>
    </div>

    <?php if (empty($faltantes)): ?>
    <div class="empty">
        <p>✅</p>
        <p style="font-weight:700; font-size:18px">Inventario en orden</p>
        <p style="color:var(--g5); margin-top:8px; font-size:14px">
            Todos los insumos están por encima de su nivel de seguridad.
        </p>
        <a href="<?= APP_BASE ?>/inventario/" style="display:inline-block; margin-top:20px; padding:10px 20px; background:var(--brand); color:#fff; border-radius:10px; text-decoration:none; font-weight:700;">
            Ver Inventario
        </a>
    </div>

    <?php else: ?>

    <div class="list-header">
        <div>
            <div class="list-title">Lista de Compras</div>
            <div class="list-meta">Generada: <?= $fecha_generada ?> · <?= count($faltantes) ?> insumo<?= count($faltantes) != 1 ? 's' : '' ?> por comprar</div>
        </div>
        <button class="btn-print no-print" onclick="window.print()">🖨 Imprimir</button>
    </div>

    <!-- Total estimado -->
    <div class="total-banner">
        <div>
            <div class="total-label">Inversión estimada</div>
            <div class="total-val">$<?= number_format($total_estimado, 0, ',', '.') ?></div>
        </div>
        <div style="text-align:right">
            <div class="total-label">Proveedores</div>
            <div style="font-size:20px; font-weight:800;"><?= count($por_proveedor) ?></div>
        </div>
    </div>

    <!-- Por proveedor -->
    <?php foreach ($por_proveedor as $prov_nombre => $items):
        $total_prov = array_sum(array_column($items, 'costo_estimado'));
    ?>
    <div class="proveedor-card">
        <div class="proveedor-hdr">
            <div class="proveedor-nombre">🏪 <?= htmlspecialchars($prov_nombre) ?></div>
            <div class="proveedor-total">Subtotal estimado: $<?= number_format($total_prov, 0, ',', '.') ?></div>
        </div>
        <table>
            <thead>
                <tr>
                    <th class="no-print" style="width:32px"></th>
                    <th>Insumo</th>
                    <th>Stock actual</th>
                    <th>Necesito</th>
                    <th>Costo est.</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item):
                    $estado = $item['stock_actual'] == 0 ? 'agotado' : 'bajo';
                    $badgeC = $estado === 'agotado' ? 'b-ago' : 'b-bajo';
                ?>
                <tr id="row-<?= $item['id'] ?>" onclick="toggleComprado(<?= $item['id'] ?>)" style="cursor:pointer" title="Clic para marcar como comprado">
                    <td class="check no-print">
                        <input type="checkbox" id="chk-<?= $item['id'] ?>" onclick="event.stopPropagation(); toggleComprado(<?= $item['id'] ?>)">
                    </td>
                    <td>
                        <strong><?= htmlspecialchars($item['nombre']) ?></strong>
                        <span class="badge <?= $badgeC ?>"><?= $estado ?></span>
                    </td>
                    <td>
                        <?= number_format($item['stock_actual'], 3, ',', '.') ?>
                        <small style="color:var(--g5)"><?= $item['unidad_medida'] ?></small>
                    </td>
                    <td class="num">
                        <?= number_format(max(0, $item['cantidad_sugerida']), 3, ',', '.') ?>
                        <small style="color:var(--g5); font-weight:400"><?= $item['unidad_medida'] ?></small>
                    </td>
                    <td class="num">
                        $<?= number_format($item['costo_estimado'], 0, ',', '.') ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endforeach; ?>

    <p style="font-size:11px; color:var(--g5); text-align:center; margin-top:8px">
        * La cantidad sugerida cubre hasta 2× el stock de seguridad configurado. Ajustar según consumo real.
    </p>

    <?php endif; ?>

</main>

<script>
function toggleComprado(id) {
    const row = document.getElementById('row-' + id);
    const chk = document.getElementById('chk-' + id);
    row.classList.toggle('comprado');
    if (chk) chk.checked = row.classList.contains('comprado');
}
</script>
</body>
</html>
