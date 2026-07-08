<?php
/**
 * app/config/app.php
 * Constantes globales de QuickServe OS.
 * Ajustar APP_ENV a 'development' para ver errores en local.
 */

define('APP_NAME',    'QuickServe OS');
define('APP_VERSION', '6.4'); // 2026-07-08: v6.4 Fase B multi-país (country packs) — database/paises/{CO,MX,XX}.sql: plan de cuentas por país mapeado a los roles + localización (moneda/impuesto), aplicables como una migración; README documenta el formato y cómo añadir un país. México usa código agrupador SAT (arranque a validar por contador MX; IVA 16%, MXN). Suite G38 (38 grupos): columnas rol/pais + claves de localización presentes; cada country pack cubre los 19 roles; cuentaRol() resuelve; packs presentes. Verificado en MariaDB aislada: aplicar MX.sql → los 6 flujos postean bajo MX, 44 asientos cuadran, Balance cuadra, 100% de líneas usan códigos SAT (Colombia + México validan el diseño con 2 países). — v6.3 (2026-07-08): Fase A multi-país (núcleo país-agnóstico) — mig 047: cuentas_contables +rol/+pais + configuracion_app +pais/moneda/impuesto/factura_modo; ContabilidadModel usa ROLES semánticos por país (6 postear_* migrados; impuestoVentas() generaliza el IVA); FormatoHelper moneda por país; Admin → Localización. — v6.2 (2026-07-07): test profundo E2E en MariaDB; bug 'estado_vida' (derivada en WHERE) corregido. — v6.1 (2026-07-05): producto genérico "QuickServe OS" + instalador web /install/.
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
