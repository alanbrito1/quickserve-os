<?php
/** Partial: se muestra cuando la migración 045 (contabilidad) aún no está aplicada. */
$nav_activo = 'contabilidad';
?>
<!DOCTYPE html><html lang="es"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Contabilidad — <?= APP_NAME ?></title>
<style>
    body{font-family:system-ui,-apple-system,sans-serif;background:#f3f4f6;color:#111827;margin:0;}
    .main{max-width:640px;margin:0 auto;padding:24px 16px;}
    .box{background:#fef3c7;border:1px solid #fde68a;border-radius:12px;padding:20px;color:#92400e;font-size:14px;line-height:1.6;}
    code{background:#fff;padding:2px 6px;border-radius:4px;}
    a{color:#e94f37;font-weight:700;}
</style></head><body>
<?php include __DIR__ . '/../app/views/nav.php'; ?>
<main class="main">
    <div class="box">
        <strong>Contabilidad no inicializada.</strong><br>
        Aplica la migración <code>045_contabilidad.sql</code> en
        <a href="<?= APP_BASE ?>/admin/backup.php">Admin → Base de Datos → Ejecutar Migración</a>
        (o en phpMyAdmin) para crear el plan de cuentas y el libro diario. Luego vuelve aquí.
    </div>
</main></body></html>
