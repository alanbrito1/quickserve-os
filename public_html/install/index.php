<?php
/**
 * install/index.php — Asistente de instalación web de QuickServe OS.
 *
 * Guía al comprador por 4 pasos: requisitos → base de datos → negocio/admin →
 * instalación. Genera app/config/database.php, crea las tablas (schema.sql),
 * registra el negocio y el administrador, y bloquea la reinstalación.
 *
 * BORRA la carpeta /install después de instalar.
 */

require_once __DIR__ . '/lib.php';
session_start();

/** Escape corto para HTML. */
function h($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }

// ── Barrera anti-reinstalación ───────────────────────────────────────────────
if (qs_ya_instalado()) {
    render_layout('Ya instalado', function () { ?>
        <div class="card">
            <div class="ok-badge">✓</div>
            <h1>QuickServe OS ya está instalado</h1>
            <p class="muted">Por seguridad, el instalador está deshabilitado. Si necesitas
               reinstalar, elimina el archivo <code>app/config/installed.lock</code> (y
               <code>app/config/database.php</code> si quieres empezar de cero).</p>
            <a class="btn" href="../login.php">Ir al inicio de sesión</a>
        </div>
    <?php });
    exit;
}

// ── CSRF ─────────────────────────────────────────────────────────────────────
if (empty($_SESSION['qs_csrf'])) {
    $_SESSION['qs_csrf'] = bin2hex(random_bytes(32));
}
function qs_csrf_ok(): bool {
    return isset($_POST['csrf']) && is_string($_POST['csrf'])
        && hash_equals($_SESSION['qs_csrf'], $_POST['csrf']);
}
function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . h($_SESSION['qs_csrf']) . '">';
}

// ── Estado que viaja entre pasos ─────────────────────────────────────────────
$paso    = $_POST['paso'] ?? 'requisitos';
$errores = [];

$db = [
    'host'  => trim($_POST['db_host'] ?? 'localhost'),
    'name'  => trim($_POST['db_name'] ?? ''),
    'user'  => trim($_POST['db_user'] ?? ''),
    'pass'  => (string) ($_POST['db_pass'] ?? ''),
    'crear' => isset($_POST['db_crear']),
];
$neg = [
    'negocio'      => trim($_POST['negocio'] ?? ''),
    'admin_nombre' => trim($_POST['admin_nombre'] ?? ''),
    'admin_email'  => trim($_POST['admin_email'] ?? ''),
    'ejemplo'      => isset($_POST['ejemplo']),
];
$okConfig     = true;
$configManual = '';

// ── Procesar POST ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!qs_csrf_ok()) {
        $errores[] = 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.';
        $paso = 'requisitos';
    } else {
        switch ($paso) {
            case 'db':
                // Viene de "requisitos": solo mostramos el formulario de BD.
                break;

            case 'negocio':
                // Viene del formulario de BD: probamos la conexión antes de continuar.
                try {
                    qs_conectar($db['host'], $db['name'], $db['user'], $db['pass'], $db['crear']);
                } catch (Throwable $e) {
                    $errores[] = $e->getMessage();
                    $paso = 'db';
                }
                break;

            case 'instalar':
                // Viene del formulario de negocio: validar y ejecutar la instalación.
                $pass1 = (string) ($_POST['admin_pass'] ?? '');
                $pass2 = (string) ($_POST['admin_pass2'] ?? '');

                if ($neg['negocio'] === '')                                     $errores[] = 'Ingresa el nombre del negocio.';
                if ($neg['admin_nombre'] === '')                                $errores[] = 'Ingresa el nombre del administrador.';
                if (!filter_var($neg['admin_email'], FILTER_VALIDATE_EMAIL))    $errores[] = 'El correo del administrador no es válido.';
                if (strlen($pass1) < 8)                                         $errores[] = 'La contraseña debe tener al menos 8 caracteres.';
                if ($pass1 !== $pass2)                                          $errores[] = 'Las contraseñas no coinciden.';

                if (!$errores) {
                    try {
                        $pdo    = qs_conectar($db['host'], $db['name'], $db['user'], $db['pass'], $db['crear']);
                        $schema = qs_sql_path('schema.sql');
                        if (!$schema) throw new RuntimeException('No se encontró el script schema.sql.');

                        qs_run_sql_file($pdo, $schema);
                        if ($neg['ejemplo']) {
                            $sd = qs_sql_path('sample_data.sql');
                            if ($sd) qs_run_sql_file($pdo, $sd);
                        }
                        qs_crear_admin($pdo, $neg['admin_nombre'], $neg['admin_email'], $pass1);
                        qs_set_negocio($pdo, $neg['negocio']);

                        $okConfig = qs_write_db_config($db['host'], $db['name'], $db['user'], $db['pass']);
                        if ($okConfig) {
                            qs_crear_lock();
                        } else {
                            $configManual = qs_config_contenido($db['host'], $db['name'], $db['user'], $db['pass']);
                        }
                        $paso = 'listo';
                    } catch (Throwable $e) {
                        error_log('[QuickServe Install] ' . $e->getMessage());
                        $errores[] = 'Ocurrió un error durante la instalación: ' . $e->getMessage();
                        $paso = 'negocio';
                    }
                } else {
                    $paso = 'negocio';
                }
                break;
        }
    }
}

// ── Render ───────────────────────────────────────────────────────────────────
$titulos = [
    'requisitos' => 'Requisitos del servidor',
    'db'         => 'Base de datos',
    'negocio'    => 'Tu negocio y administrador',
    'listo'      => '¡Instalación completa!',
];
$pasoNum = ['requisitos' => 1, 'db' => 2, 'negocio' => 3, 'listo' => 4];

render_layout($titulos[$paso] ?? 'Instalación', function () use ($paso, $errores, $db, $neg, $okConfig, $configManual, $pasoNum) {
    // Barra de pasos
    echo '<div class="steps">';
    foreach ([1 => 'Requisitos', 2 => 'Base de datos', 3 => 'Negocio', 4 => 'Listo'] as $i => $lbl) {
        $cur = ($pasoNum[$paso] ?? 1);
        $cls = $i < $cur ? 'done' : ($i === $cur ? 'active' : '');
        echo '<div class="step ' . $cls . '"><span>' . $i . '</span>' . h($lbl) . '</div>';
    }
    echo '</div>';

    if ($errores) {
        echo '<div class="alert">';
        foreach ($errores as $e) echo '<div>• ' . h($e) . '</div>';
        echo '</div>';
    }

    if ($paso === 'requisitos') {
        $checks = qs_requisitos();
        $ok = qs_requisitos_ok($checks);
        echo '<p class="muted">Verifica que tu servidor cumpla los requisitos. Los ítems en rojo son obligatorios.</p>';
        echo '<table class="reqs">';
        foreach ($checks as $c) {
            [$label, $pasa, $critico, $detalle] = $c;
            $icon = $pasa ? '<span class="yes">✓</span>' : ($critico ? '<span class="no">✗</span>' : '<span class="warn">!</span>');
            echo '<tr><td>' . $icon . '</td><td>' . h($label)
               . ($detalle ? '<div class="det">' . h($detalle) . '</div>' : '')
               . '</td></tr>';
        }
        echo '</table>';
        if ($ok) {
            echo '<form method="post">' . csrf_field()
               . '<input type="hidden" name="paso" value="db">'
               . '<button class="btn" type="submit">Continuar →</button></form>';
        } else {
            echo '<div class="alert">Corrige los requisitos obligatorios (en rojo) y recarga esta página.</div>';
            echo '<form method="post"><button class="btn secondary" type="submit">Volver a verificar</button></form>';
        }
        return;
    }

    if ($paso === 'db') {
        echo '<p class="muted">Ingresa los datos de la base de datos MySQL. En cPanel se crean en '
           . '«MySQL Databases» (base, usuario y contraseña).</p>';
        echo '<form method="post" autocomplete="off">' . csrf_field()
           . '<input type="hidden" name="paso" value="negocio">';
        campo('db_host', 'Servidor (host)', $db['host'], 'text', 'Normalmente «localhost»');
        campo('db_name', 'Nombre de la base de datos', $db['name']);
        campo('db_user', 'Usuario de la base de datos', $db['user']);
        campo('db_pass', 'Contraseña de la base de datos', '', 'password');
        echo '<label class="check"><input type="checkbox" name="db_crear" '
           . ($db['crear'] ? 'checked' : '') . '> Crear la base de datos si no existe '
           . '<span class="muted">(requiere privilegios; en cPanel créala tú primero)</span></label>';
        echo '<button class="btn" type="submit">Probar conexión y continuar →</button>';
        echo '</form>';
        return;
    }

    if ($paso === 'negocio') {
        echo '<p class="muted">Conexión exitosa. Ahora configura tu negocio y la cuenta de administrador.</p>';
        echo '<form method="post" autocomplete="off">' . csrf_field()
           . '<input type="hidden" name="paso" value="instalar">'
           // Arrastrar credenciales de BD ya validadas
           . '<input type="hidden" name="db_host" value="' . h($db['host']) . '">'
           . '<input type="hidden" name="db_name" value="' . h($db['name']) . '">'
           . '<input type="hidden" name="db_user" value="' . h($db['user']) . '">'
           . '<input type="hidden" name="db_pass" value="' . h($db['pass']) . '">'
           . ($db['crear'] ? '<input type="hidden" name="db_crear" value="1">' : '');
        campo('negocio', 'Nombre del negocio', $neg['negocio'], 'text', 'Aparecerá en el menú, login y reportes');
        echo '<div class="sep">Administrador</div>';
        campo('admin_nombre', 'Nombre del administrador', $neg['admin_nombre']);
        campo('admin_email', 'Correo (usuario para entrar)', $neg['admin_email'], 'email');
        campo('admin_pass', 'Contraseña (mínimo 8 caracteres)', '', 'password');
        campo('admin_pass2', 'Repetir contraseña', '', 'password');
        echo '<label class="check"><input type="checkbox" name="ejemplo" '
           . ($neg['ejemplo'] ? 'checked' : '') . '> Cargar datos de ejemplo '
           . '<span class="muted">(productos e insumos de muestra, editables o borrables después)</span></label>';
        echo '<button class="btn" type="submit">Instalar QuickServe OS</button>';
        echo '</form>';
        return;
    }

    if ($paso === 'listo') {
        echo '<div class="ok-badge">✓</div>';
        echo '<h1 style="text-align:center">¡Todo listo!</h1>';
        echo '<p class="muted">QuickServe OS se instaló correctamente. Entra con la cuenta de administrador que creaste.</p>';
        if (!$okConfig) {
            echo '<div class="alert">No se pudo escribir <code>app/config/database.php</code> automáticamente '
               . '(la carpeta no es escribible). Crea ese archivo a mano con este contenido:</div>';
            echo '<textarea class="conf" readonly onclick="this.select()">' . h($configManual) . '</textarea>';
        }
        echo '<div class="warn-box"><strong>Importante por seguridad:</strong> borra la carpeta '
           . '<code>/install</code> del servidor ahora que terminaste.</div>';
        echo '<a class="btn" href="../login.php">Ir al inicio de sesión →</a>';
        return;
    }
});


// ── Componentes de render ────────────────────────────────────────────────────
function campo(string $name, string $label, string $val = '', string $type = 'text', string $hint = ''): void
{
    echo '<label class="field"><span>' . h($label) . '</span>'
       . '<input type="' . h($type) . '" name="' . h($name) . '" value="' . h($val) . '"'
       . ($type === 'password' ? ' autocomplete="new-password"' : '') . '>'
       . ($hint ? '<em class="hint">' . h($hint) . '</em>' : '')
       . '</label>';
}

function render_layout(string $titulo, callable $body): void
{
    ?><!doctype html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title><?= h($titulo) ?> — Instalación de QuickServe OS</title>
<style>
  :root { --brand:#e94f37; --dark:#111827; --g:#6b7280; --line:#e5e7eb; }
  * { box-sizing:border-box; }
  body { margin:0; font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif; background:#f3f4f6; color:var(--dark); padding:20px; }
  .wrap { max-width:560px; margin:24px auto; }
  .brand { text-align:center; margin-bottom:18px; }
  .brand b { font-size:22px; letter-spacing:-.5px; }
  .brand b span { color:var(--brand); }
  .card { background:#fff; border:1px solid var(--line); border-radius:14px; padding:24px; box-shadow:0 4px 20px rgba(0,0,0,.05); }
  h1 { font-size:20px; margin:0 0 6px; }
  .muted { color:var(--g); font-size:14px; line-height:1.5; }
  .steps { display:flex; gap:6px; margin-bottom:18px; font-size:11px; }
  .step { flex:1; text-align:center; color:var(--g); border-top:3px solid var(--line); padding-top:6px; }
  .step span { display:inline-flex; width:20px; height:20px; border-radius:50%; background:var(--line); color:#fff; align-items:center; justify-content:center; margin-right:4px; font-weight:700; }
  .step.active { color:var(--dark); border-top-color:var(--brand); }
  .step.active span { background:var(--brand); }
  .step.done { border-top-color:#10b981; }
  .step.done span { background:#10b981; }
  .field { display:block; margin:14px 0; }
  .field span { display:block; font-size:13px; font-weight:600; margin-bottom:5px; }
  .field input { width:100%; padding:11px 12px; border:1px solid var(--line); border-radius:9px; font-size:15px; }
  .field input:focus { outline:2px solid var(--brand); border-color:var(--brand); }
  .hint { display:block; color:var(--g); font-size:12px; margin-top:4px; font-style:normal; }
  .check { display:block; margin:14px 0; font-size:14px; }
  .sep { margin:22px 0 4px; font-weight:700; font-size:13px; text-transform:uppercase; letter-spacing:.5px; color:var(--g); border-top:1px solid var(--line); padding-top:16px; }
  .btn { display:block; width:100%; text-align:center; margin-top:20px; background:var(--brand); color:#fff; border:0; border-radius:10px; padding:13px; font-size:15px; font-weight:700; cursor:pointer; text-decoration:none; }
  .btn:hover { filter:brightness(.95); }
  .btn.secondary { background:#6b7280; }
  .reqs { width:100%; border-collapse:collapse; margin:8px 0; }
  .reqs td { padding:9px 6px; border-bottom:1px solid var(--line); font-size:14px; vertical-align:top; }
  .reqs td:first-child { width:26px; text-align:center; }
  .reqs .det { color:var(--g); font-size:12px; margin-top:2px; word-break:break-all; }
  .yes { color:#10b981; font-weight:700; } .no { color:#dc2626; font-weight:700; } .warn { color:#d97706; font-weight:700; }
  .alert { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; padding:12px; border-radius:9px; font-size:14px; margin:14px 0; }
  .ok-badge { width:60px; height:60px; border-radius:50%; background:#10b981; color:#fff; font-size:32px; display:flex; align-items:center; justify-content:center; margin:8px auto 14px; }
  .warn-box { background:#fffbeb; border:1px solid #fde68a; color:#92400e; padding:12px; border-radius:9px; font-size:14px; margin:16px 0; }
  .conf { width:100%; height:220px; font-family:ui-monospace,Menlo,Consolas,monospace; font-size:12px; border:1px solid var(--line); border-radius:9px; padding:10px; margin-top:8px; }
  code { background:#f3f4f6; padding:1px 5px; border-radius:4px; font-size:13px; }
</style>
</head>
<body>
  <div class="wrap">
    <div class="brand"><b>Quick<span>Serve</span> OS</b></div>
    <div class="card"><?php $body(); ?></div>
  </div>
</body>
</html><?php
}
