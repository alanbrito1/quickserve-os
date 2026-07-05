<?php
/**
 * public_html/login.php
 * Página de acceso al sistema. Mobile-First.
 * Maneja el formulario POST y redirige al dashboard si el login es exitoso.
 */

require_once __DIR__ . '/app/config/app.php';
require_once __DIR__ . '/app/helpers/AuthHelper.php';

// ── Cargar branding personalizado (logo y nombre) desde configuracion_app ────
// logo_url_login  → logo vertical para la página de acceso
// logo_url        → logo horizontal (fallback si no hay logo de login)
$_login_brand = ['nombre' => APP_NAME, 'logo_login' => '', 'logo_nav' => '', 'color' => '#e94f37'];
try {
    $_br = db()->query(
        "SELECT clave, valor FROM configuracion_app
         WHERE clave IN ('nombre_negocio','logo_url_login','logo_url','theme_brand')"
    )->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!empty($_br['nombre_negocio'])) $_login_brand['nombre']     = $_br['nombre_negocio'];
    if (!empty($_br['logo_url_login'])) $_login_brand['logo_login'] = $_br['logo_url_login'];
    if (!empty($_br['logo_url']))       $_login_brand['logo_nav']   = $_br['logo_url'];
    if (!empty($_br['theme_brand']))    $_login_brand['color']      = $_br['theme_brand'];
} catch (Exception $_e) { /* tabla puede no existir aún — usa defaults */ }

auth_session_init();

// Si ya hay sesión activa, no mostrar el login
if (auth_check()) {
    header('Location: ' . APP_BASE . '/dashboard.php');
    exit;
}

$error     = '';
$email_val = ''; // valor previo del campo email (para no perderlo en error)
$msg       = $_GET['msg'] ?? ''; // 'logout' = viene de cerrar sesión

// ---- Procesar formulario POST ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 1. Verificar token CSRF antes de cualquier otra lógica
    if (!csrf_verificar()) {
        $error = 'Error de seguridad en el formulario. Recarga la página e intenta de nuevo.';

    } else {
        $email    = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL) ?: '';
        $password = $_POST['password'] ?? '';
        $email_val = htmlspecialchars($email);

        if (empty($email) || empty($password)) {
            $error = 'Por favor ingresa tu correo y contraseña.';

        } elseif (auth_rl_bloqueado(strtolower(trim($email)), log_ip())) {
            // Mostrar bloqueo sin revelar cuántos intentos quedan
            $error = 'Cuenta bloqueada temporalmente por exceso de intentos. '
                   . 'Espera ' . LOGIN_BLOQUEO_MINS . ' minutos e intenta de nuevo.';

        } else {
            $usuario = auth_login($email, $password);

            if ($usuario) {
                $redirect = $_SESSION['redirect_after_login'] ?? (APP_BASE . '/dashboard.php');
                unset($_SESSION['redirect_after_login']);
                // Defensa en profundidad: solo redirigir a rutas internas (un solo "/" inicial).
                // Evita open redirect (CWE-601) si "redirect_after_login" llegara a contener "//host/..."
                if (preg_match('#^/(?!/)#', $redirect) !== 1) {
                    $redirect = APP_BASE . '/dashboard.php';
                }
                header('Location: ' . $redirect);
                exit;
            } else {
                $error = 'Correo o contraseña incorrectos.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="description" content="<?= htmlspecialchars($_login_brand['nombre']) ?> — Acceso al sistema">
    <meta name="robots" content="noindex, nofollow">
    <title>Acceso — <?= htmlspecialchars($_login_brand['nombre']) ?></title>
    <style>
        /* ---- Reset mínimo ---- */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        /* ---- Layout ---- */
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #111827;
            min-height: 100vh;
            min-height: 100dvh; /* iOS Safari */
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
            padding-bottom: max(16px, env(safe-area-inset-bottom)); /* notch iPhones */
        }

        /* ---- Card principal ---- */
        .card {
            background: #fff;
            border-radius: 20px;
            padding: 16px 28px 32px;
            width: 100%;
            max-width: 400px;
            box-shadow: 0 24px 64px rgba(0, 0, 0, .5);
        }

        /* ---- Marca ---- */
        .brand {
            text-align: center;
            margin-bottom: 20px;
        }
        .brand-icon {
            width: 112px;
            height: 112px;
            background: <?= htmlspecialchars($_login_brand['color']) ?>;
            border-radius: 28px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 14px;
        }
        .brand-icon svg {
            width: 60px;
            height: 60px;
            fill: #fff;
        }
        .brand h1 {
            font-size: 26px;
            font-weight: 800;
            color: #111827;
            letter-spacing: -0.5px;
            line-height: 1;
        }
        .brand h1 span { color: #e94f37; }
        .brand p {
            font-size: 13px;
            color: #9ca3af;
            margin-top: 5px;
        }

        /* ---- Alertas ---- */
        .alert {
            padding: 12px 14px;
            border-radius: 10px;
            font-size: 14px;
            margin-bottom: 22px;
            line-height: 1.4;
        }
        .alert-error   { background: #fef2f2; color: #b91c1c; border: 1px solid #fca5a5; }
        .alert-success { background: #f0fdf4; color: #15803d; border: 1px solid #86efac; }

        /* ---- Formulario ---- */
        .form-group { margin-bottom: 18px; }

        label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: .6px;
            margin-bottom: 6px;
        }

        input[type="email"],
        input[type="password"] {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            font-size: 16px; /* 16px previene el zoom automático en iOS */
            color: #111827;
            outline: none;
            transition: border-color .18s;
            -webkit-appearance: none;
            background: #f9fafb;
        }
        input:focus {
            border-color: #e94f37;
            background: #fff;
        }
        input::placeholder { color: #d1d5db; }

        /* ---- Botón ---- */
        .btn-login {
            width: 100%;
            padding: 16px;
            background: #e94f37;
            color: #fff;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            margin-top: 8px;
            transition: background .18s, transform .1s;
            -webkit-appearance: none;
            letter-spacing: .2px;
        }
        .btn-login:hover  { background: #d43d28; }
        .btn-login:active { transform: scale(.98); }

        /* ---- Pie ---- */
        .footer {
            text-align: center;
            font-size: 11px;
            color: #d1d5db;
            margin-top: 26px;
        }

        /* ---- Responsive Desktop ---- */
        @media (min-width: 480px) {
            .card { padding: 16px 40px 40px; }
        }
    </style>
</head>
<body>
    <div class="card">

        <!-- Marca — logo personalizado o ícono por defecto -->
        <div class="brand">
            <?php
            // Prioridad: logo_url_login > logo_url > SVG por defecto
            $_logo_mostrar = $_login_brand['logo_login'] ?: $_login_brand['logo_nav'];
            ?>
            <?php if ($_logo_mostrar): ?>
                <!-- Logo cargado desde Admin → Apariencia -->
                <img src="<?= APP_BASE . '/' . htmlspecialchars($_logo_mostrar) ?>"
                     alt="<?= htmlspecialchars($_login_brand['nombre']) ?>"
                     style="max-width:320px;max-height:240px;object-fit:contain;margin-bottom:14px;display:block;margin-left:auto;margin-right:auto">
            <?php else: ?>
                <!-- Ícono por defecto: tenedor y cuchillo -->
                <div class="brand-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path d="M11 9H9V2H7v7H5V2H3v7c0 2.12 1.66 3.84 3.75 3.97V22h2.5v-9.03C11.34 12.84 13 11.12 13 9V2h-2v7zm5-3v8h2.5v8H21V2c-2.76 0-5 2.24-5 4z"/>
                    </svg>
                </div>
            <?php endif; ?>

            <?php if (!$_logo_mostrar): ?>
            <!-- Nombre del negocio solo cuando no hay logo (el logo ya identifica el negocio) -->
            <?php
            $_partes = preg_split('/\s+/', trim($_login_brand['nombre']), 2);
            $_p1     = htmlspecialchars($_partes[0] ?? '');
            $_p2     = htmlspecialchars($_partes[1] ?? '');
            ?>
            <h1><?= $_p1 ?><?php if ($_p2): ?><span><?= $_p2 ?></span><?php endif; ?></h1>
            <?php endif; ?>
            <p>Sistema de Gestión ERP v<?= APP_VERSION ?></p>
        </div>

        <!-- Mensajes de estado -->
        <?php if ($error): ?>
            <div class="alert alert-error" role="alert">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php elseif ($msg === 'logout'): ?>
            <div class="alert alert-success" role="status">
                Sesión cerrada correctamente.
            </div>
        <?php endif; ?>

        <!-- Formulario de login -->
        <form method="POST" action="<?= APP_BASE ?>/login.php" novalidate>
            <!-- Token CSRF: obligatorio en todos los formularios POST -->
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">

            <div class="form-group">
                <label for="email">Correo Electrónico</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    value="<?= $email_val ?>"
                    autocomplete="username email"
                    inputmode="email"
                    placeholder="usuario@ejemplo.com"
                    required
                    autofocus>
            </div>

            <div class="form-group">
                <label for="password">Contraseña</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    autocomplete="current-password"
                    placeholder="••••••••"
                    required>
            </div>

            <button type="submit" class="btn-login">
                Ingresar al Sistema
            </button>
        </form>

        <p class="footer"><?= APP_NAME ?> &nbsp;·&nbsp; <?= date('Y') ?></p>
    </div>
</body>
</html>
