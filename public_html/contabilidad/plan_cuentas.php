<?php
/**
 * contabilidad/plan_cuentas.php — Catálogo de cuentas contables (Fase 4a, solo lectura).
 */
require_once __DIR__ . '/../app/middleware/auth_check.php';
require_once __DIR__ . '/../app/models/ContabilidadModel.php';

$nav_activo = 'contabilidad';
$nav_sub    = 'plan';
if (!in_array($_SESSION['usuario_rol'] ?? '', ['admin', 'superadmin'], true)) {
    http_response_code(403); include __DIR__ . '/../app/views/errors/403.php'; exit;
}
if (!ContabilidadModel::existe()) { include __DIR__ . '/_sin_migracion.php'; exit; }

$cuentas = db()->query("SELECT * FROM cuentas_contables ORDER BY orden, codigo")->fetchAll();
$tipoLbl = ['activo'=>'Activo','pasivo'=>'Pasivo','patrimonio'=>'Patrimonio','ingreso'=>'Ingreso','costo'=>'Costo','gasto'=>'Gasto'];
?>
<!DOCTYPE html><html lang="es"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Plan de cuentas — <?= APP_NAME ?></title>
<style>
    :root{--brand:#e94f37;--dark:#111827;--g5:#6b7280;--g8:#d1d5db;--g9:#f3f4f6;--white:#fff;}
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
    body{font-family:system-ui,-apple-system,sans-serif;background:var(--g9);color:var(--dark);}
    .main{max-width:720px;margin:0 auto;padding:20px 14px 60px;}
    .page-title{font-size:22px;font-weight:800;margin-bottom:14px;}
    .card{background:var(--white);border:1px solid var(--g8);border-radius:14px;padding:8px;overflow-x:auto;}
    .tbl{width:100%;border-collapse:collapse;font-size:13px;}
    .tbl th{background:var(--g9);font-size:10px;font-weight:700;text-transform:uppercase;padding:8px 10px;text-align:left;color:var(--g5);}
    .tbl td{padding:8px 10px;border-bottom:1px solid var(--g9);}
    .badge{font-size:10px;font-weight:700;padding:1px 7px;border-radius:20px;background:#e5e7eb;color:#374151;}
</style></head><body>
<?php include __DIR__ . '/../app/views/nav.php'; ?>
<main class="main">
    <h1 class="page-title">Plan de cuentas</h1>
    <div class="card"><table class="tbl">
        <thead><tr><th>Código</th><th>Cuenta</th><th>Tipo</th><th>Naturaleza</th></tr></thead>
        <tbody><?php foreach ($cuentas as $c): ?>
            <tr><td><?= htmlspecialchars($c['codigo']) ?></td>
                <td><?= htmlspecialchars($c['nombre']) ?><?= $c['es_contra']?' <span class="badge">contra</span>':'' ?></td>
                <td><?= $tipoLbl[$c['tipo']] ?? $c['tipo'] ?></td>
                <td><?= $c['naturaleza']==='debito'?'Débito':'Crédito' ?></td></tr>
        <?php endforeach; ?></tbody>
    </table></div>
</main></body></html>
