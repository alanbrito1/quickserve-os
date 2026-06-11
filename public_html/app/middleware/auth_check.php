<?php
/**
 * app/middleware/auth_check.php
 * Middleware de autenticación — incluir al inicio de CADA página protegida.
 *
 * USO:
 *   require_once __DIR__ . '/../../app/middleware/auth_check.php';
 *   // A partir de aquí, $usuario_activo está disponible con los datos del usuario.
 *
 * Este archivo:
 *  1. Inicia la sesión con parámetros seguros
 *  2. Verifica que exista sesión activa (redirige a login si no)
 *  3. Carga los helpers de permisos listos para usar
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../helpers/AuthHelper.php';
require_once __DIR__ . '/../helpers/PermisosHelper.php';
require_once __DIR__ . '/../helpers/FormatoHelper.php';

auth_session_init();

$usuario_activo = auth_check();

if (!$usuario_activo) {
    // Guardar la URL que el usuario intentaba acceder para redirigir después del login.
    // Solo se acepta una ruta interna que empiece con UN solo "/" — una URI que empiece
    // con "//" es una redirección relativa de protocolo (CWE-601 Open Redirect: el
    // navegador la interpreta como "//evil.com/..." y redirige a un dominio externo).
    $__uri = $_SERVER['REQUEST_URI'] ?? '';
    $_SESSION['redirect_after_login'] = (preg_match('#^/(?!/)#', $__uri) === 1)
        ? $__uri
        : (APP_BASE . '/dashboard.php');
    unset($__uri);
    header('Location: ' . APP_BASE . '/login.php');
    exit;
}
