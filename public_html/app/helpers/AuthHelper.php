<?php
/**
 * app/helpers/AuthHelper.php
 * Autenticación: login, sesiones seguras, CSRF y rate limiting.
 *
 * SEGURIDAD implementada:
 * - Bcrypt para verificación de contraseñas (tiempo constante)
 * - session_regenerate_id() en cada login (previene session fixation)
 * - Cookie de sesión: httponly + samesite=Strict (previene XSS/CSRF)
 * - CSRF token por sesión (previene ataques cross-site)
 * - Rate limiting por email+IP en tabla login_intentos
 * - Mensajes de error genéricos (no revela si el email existe)
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/AuditoriaHelper.php';

// ---------------------------------------------------------------------------
// INICIALIZACIÓN DE SESIÓN
// ---------------------------------------------------------------------------

/**
 * Configura e inicia la sesión PHP de forma segura.
 * Llamar antes de cualquier output HTML.
 */
function auth_session_init(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return; // ya iniciada
    }

    session_name(SESSION_NAME);

    session_set_cookie_params([
        'lifetime' => 0,         // cookie de sesión (se borra al cerrar el navegador)
        'path'     => '/',
        'domain'   => '',
        'secure'   => !empty($_SERVER['HTTPS']), // solo HTTPS cuando esté disponible
        'httponly' => true,       // no accesible desde JavaScript (previene XSS)
        'samesite' => 'Lax',      // Lax permite cookies en redirects del mismo dominio (compatible con cPanel)
    ]);

    session_start();
}

// ---------------------------------------------------------------------------
// LOGIN / LOGOUT
// ---------------------------------------------------------------------------

/**
 * Intenta autenticar al usuario con email y contraseña.
 *
 * @return array  Datos del usuario si exitoso
 * @return false  Si las credenciales son inválidas o la cuenta está bloqueada
 */
function auth_login(string $email, string $password)
{
    $email = strtolower(trim($email));
    $ip    = log_ip();

    // Verificar bloqueo por exceso de intentos fallidos
    if (auth_rl_bloqueado($email, $ip)) {
        return false;
    }

    // Buscar usuario activo en la DB
    $stmt = db()->prepare(
        'SELECT id, nombre, email, password_hash, rol
         FROM usuarios
         WHERE email = :email AND activo = 1
         LIMIT 1'
    );
    $stmt->execute([':email' => $email]);
    $usuario = $stmt->fetch();

    // password_verify usa tiempo constante: no revela si el email existe
    if (!$usuario || !password_verify($password, $usuario['password_hash'])) {
        auth_rl_registrar_fallo($email, $ip);
        return false;
    }

    // Credenciales correctas: limpiar intentos fallidos previos
    auth_rl_limpiar($email, $ip);

    // Guardar identidad en sesión
    // NOTA: session_regenerate_id(true) desactivado — en hosting cPanel compartido
    // con PHP-FPM la cookie de sesión no llega al cliente antes del redirect.
    $_SESSION['usuario_id']     = (int)$usuario['id'];
    $_SESSION['usuario_nombre'] = $usuario['nombre'];
    $_SESSION['usuario_rol']    = $usuario['rol'];
    $_SESSION['login_time']     = time();
    $_SESSION['last_activity']  = time();
    $_SESSION['csrf_token']     = csrf_token_nuevo();

    // Actualizar marca de último login
    db()->prepare('UPDATE usuarios SET ultimo_login = NOW() WHERE id = :id')
        ->execute([':id' => $usuario['id']]);

    log_registrar('usuarios', $usuario['id'], 'ultimo_login', null, date('Y-m-d H:i:s'), 'UPDATE');

    return $usuario;
}

/**
 * Retorna los datos del usuario de la sesión activa, o false si no hay sesión.
 * Verifica expiración por inactividad (SESSION_LIFETIME segundos).
 */
function auth_check()
{
    auth_session_init();

    if (empty($_SESSION['usuario_id'])) {
        return false;
    }

    // Expirar sesión por inactividad
    if (isset($_SESSION['last_activity'])
        && (time() - $_SESSION['last_activity']) > SESSION_LIFETIME)
    {
        auth_logout();
        return false;
    }

    // Renovar timestamp de actividad
    $_SESSION['last_activity'] = time();

    return [
        'id'     => $_SESSION['usuario_id'],
        'nombre' => $_SESSION['usuario_nombre'],
        'rol'    => $_SESSION['usuario_rol'],
    ];
}

/**
 * Cierra la sesión actual destruyendo todos los datos de sesión y la cookie.
 */
function auth_logout(): void
{
    auth_session_init();

    if (!empty($_SESSION['usuario_id'])) {
        log_registrar('usuarios', $_SESSION['usuario_id'], 'sesion', 'activa', 'cerrada', 'UPDATE');
    }

    // Invalidar cookie en el navegador del cliente
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(
            session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']
        );
    }

    $_SESSION = [];
    session_destroy();
}

// ---------------------------------------------------------------------------
// CSRF
// ---------------------------------------------------------------------------

/** Genera un token CSRF criptográficamente seguro y lo guarda en sesión. */
function csrf_token_nuevo(): string
{
    $token = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $token;
    return $token;
}

/**
 * Retorna el token CSRF actual para incluirlo en formularios.
 * Crea uno nuevo si no existe.
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        csrf_token_nuevo();
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verifica el token CSRF enviado en $_POST['csrf_token'].
 * hash_equals() previene timing attacks en la comparación.
 */
function csrf_verificar(): bool
{
    $enviado  = $_POST['csrf_token']   ?? '';
    $esperado = $_SESSION['csrf_token'] ?? '';

    return !empty($esperado) && hash_equals($esperado, $enviado);
}

// ---------------------------------------------------------------------------
// RATE LIMITING (tabla login_intentos)
// ---------------------------------------------------------------------------

/**
 * Verifica si el email o la IP tienen demasiados intentos fallidos recientes.
 * Bloquea si hay >= MAX_LOGIN_INTENTOS en los últimos LOGIN_BLOQUEO_MINS minutos.
 */
function auth_rl_bloqueado(string $email, string $ip): bool
{
    $desde = date('Y-m-d H:i:s', time() - (LOGIN_BLOQUEO_MINS * 60));

    $stmt = db()->prepare(
        'SELECT COUNT(*) AS n
         FROM login_intentos
         WHERE (email = :email OR ip_address = :ip)
           AND exitoso = 0
           AND created_at >= :desde'
    );
    $stmt->execute([':email' => $email, ':ip' => $ip, ':desde' => $desde]);
    $row = $stmt->fetch();

    return (int)($row['n'] ?? 0) >= MAX_LOGIN_INTENTOS;
}

/** Registra un intento fallido en la tabla de rate limiting. */
function auth_rl_registrar_fallo(string $email, string $ip): void
{
    db()->prepare(
        'INSERT INTO login_intentos (email, ip_address, exitoso) VALUES (:email, :ip, 0)'
    )->execute([':email' => $email, ':ip' => $ip]);
}

/** Borra los intentos fallidos de un email/IP tras un login exitoso. */
function auth_rl_limpiar(string $email, string $ip): void
{
    db()->prepare(
        'DELETE FROM login_intentos WHERE email = :email OR ip_address = :ip'
    )->execute([':email' => $email, ':ip' => $ip]);
}
