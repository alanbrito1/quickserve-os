<?php
/**
 * app/config/app.php
 * Constantes globales de QuickServe OS.
 * Ajustar APP_ENV a 'development' para ver errores en local.
 */

define('APP_NAME',    'QuickServe OS');
define('APP_VERSION', '6.8'); // 2026-07-08: v6.8 Fase D (arquitectura de facturación, modo Interno) — subsistema `app/models/facturacion/`: interface `FacturacionDriver` + `FacturacionInterno` (comprobante propio no fiscal, número derivado estable INT-<id>, sin llamadas externas) + `FacturacionModel` (router por `configuracion_app.factura_modo` interno|legal + `datos_comprobante()`). Nuevo `ventas/comprobante.php`: recibo IMPRIMIBLE por venta, consciente del país (moneda/impuesto/negocio vía PaisesHelper/FormatoHelper) — desglosa base+impuesto si el IVA está activo; enlace por fila en el historial. El modo Legal (factura electrónica vía PAC del país) queda ENCHUFABLE como driver aparte cuando se integre; si se elige 'legal' sin PAC, cae a Interno y avisa qué sistema integrar. Suite G38 amplía (driver presente + driver() ⇒ FacturacionDriver). Verificado en MariaDB: 12/12 (driver Interno, número estable, Σítems−descuento=total, IVA discriminado base+impuesto=total). Sin cambios de BD. — v6.7 (2026-07-08): country packs Perú (PCGE, PEN/IGV 18) + España (PGC, EUR/IVA 21) mapeados a los 19 roles (arranque a validar por contador local); 4 países (CO/MX/PE/ES) verificados E2E. — v6.6: multi-país UX (catálogo `PaisesHelper` moneda/impuesto/estado/facturación-legal; Admin → Localización alerta+autocompletar; instalador elige país). — v6.5: Fase C p.1 nómina por `PayrollStrategy` (Colombia idéntico, golden 7/7). — v6.4: Fase B country packs CO/MX/XX. — v6.3: Fase A núcleo país-agnóstico (mig 047, ContabilidadModel por ROLES). — v6.2: test profundo E2E + fix depreciación. — v6.1: producto genérico + instalador web.
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
