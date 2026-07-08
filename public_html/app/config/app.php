<?php
/**
 * app/config/app.php
 * Constantes globales de QuickServe OS.
 * Ajustar APP_ENV a 'development' para ver errores en local.
 */

define('APP_NAME',    'QuickServe OS');
define('APP_VERSION', '6.2'); // 2026-07-07: v6.2 test profundo end-to-end en MariaDB real (instalador + math + contabilidad partida doble + fiscal + suite 37 grupos + smoke 44 páginas). BUG corregido: 'estado_vida' (columna DERIVADA por CASE en ActivoModel, no física) se usaba en WHERE en 4 queries de depreciación → reportes/costos.php crasheaba y costos/index+analisis+pyg devolvían depreciación 0 en silencio; reemplazado por TIMESTAMPDIFF(MONTH, fecha_inicio_uso, CURDATE()) < vida_util_meses. Afinados suite G03 (resta descuento)/G11 (excluye por_servicio) y sample_data (recetas crítico/base + costos de presentación). — v6.1 (2026-07-05): producto genérico "QuickServe OS" (de-branding total) + instalador web /install/ (runner SQL DELIMITER-aware; genera database.php; candado anti-reinstalación); datos demo en database/sample_data.sql.
define('APP_ENV',     'production'); // cambiar a 'development' para depurar

// Ruta absoluta a public_html/ (raíz web del proyecto)
define('BASE_PATH', dirname(dirname(__DIR__)));

// Prefijo URL del sistema — funciona tanto en raíz como en subdirectorio.
// Si el ERP está en www.dominio.com/miempresa/ → APP_BASE = '/miempresa'
// Si está en la raíz del dominio          → APP_BASE = ''
if (!defined('APP_BASE')) {
    $__realBase    = realpath(BASE_PATH);
    $__realDocRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '/');
    if ($__realBase && $__realDocRoot) {
        $__b = rtrim(str_replace('\\', '/', $__realBase),    '/');
        $__d = rtrim(str_replace('\\', '/', $__realDocRoot), '/');
        define('APP_BASE', str_replace($__d, '', $__b));
    } else {
        define('APP_BASE', '');
    }
    unset($__realBase, $__realDocRoot, $__b, $__d);
}

// Colombia (UTC-5)
date_default_timezone_set('America/Bogota');

// Sesión
define('SESSION_NAME',     'quickserve_sess');
define('SESSION_LIFETIME', 7200); // 2 horas en segundos sin actividad

// Seguridad de contraseñas
define('BCRYPT_COST', 12);

// Rate limiting de login
define('MAX_LOGIN_INTENTOS', 5);  // intentos fallidos antes de bloquear
define('LOGIN_BLOQUEO_MINS',  15); // minutos que dura el bloqueo

// Errores: mostrar en development, ocultar en production
if (APP_ENV === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}
