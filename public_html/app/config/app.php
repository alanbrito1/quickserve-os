<?php
/**
 * app/config/app.php
 * Constantes globales de QuickServe OS.
 * Ajustar APP_ENV a 'development' para ver errores en local.
 */

define('APP_NAME',    'QuickServe OS');
define('APP_VERSION', '6.6'); // 2026-07-08: v6.6 multi-país UX — catálogo único de países `app/helpers/PaisesHelper.php` (por país: moneda/impuesto por defecto + estado de contabilidad/nómina + sistema de facturación electrónica LEGAL representativo: DIAN/CO, CFDI-SAT/MX, SUNAT/PE, SII/CL, AFIP/AR, SRI/EC, DGI/PA-UY, NF-e SPED/BR, AEAT/ES, SIFEN/PY). Admin → Apariencia → Localización: al elegir país se AUTOCOMPLETA moneda/impuesto y se muestra una ALERTA de consideraciones (qué está listo vs. qué requiere validación local: nómina solo Colombia validada, facturación legal = integrar PAC del país). El INSTALADOR ahora ELIGE país: selector en el paso Negocio + `qs_aplicar_pais()` (aplica el country pack si existe, si no fija la localización desde el catálogo) + misma alerta; packs copiados a install/sql/paises/. README de paises documenta la facturación por país. Verificado en MariaDB: instalar con MX aplica pack SAT (19 cuentas, MXN, IVA 16); con PE fija PEN/S//IGV/18 y usa el plan CO por fallback. — v6.5 (2026-07-08): Fase C (parte 1) multi-país — nómina por estrategia de país SIN cambiar Colombia: se EXTRAJO (verbatim, no se eliminó nada) el cálculo laboral colombiano de NominaModel a PayrollStrategyColombia (interface PayrollStrategy). Los métodos públicos de NominaModel siguen como delegadores → las vistas/APIs de nómina funcionan igual. NominaModel::estrategia($pais) enruta por empleados.pais_laboral (default/fallback Colombia). Colombia IDÉNTICO: golden test 7/7 + wiring ===. — v6.4 (2026-07-08): Fase B multi-país (country packs) — nómina por estrategia de país SIN cambiar Colombia: se EXTRAJO (verbatim, no se eliminó nada) el cálculo laboral colombiano de NominaModel a PayrollStrategyColombia (interface PayrollStrategy). Los métodos públicos de NominaModel (calcular/horas_mes_estandar/valor_hora_con_recargo/calcular_desglose_horas) siguen existiendo como delegadores → las vistas/APIs de nómina funcionan igual. NominaModel::estrategia($pais) enruta por empleados.pais_laboral (default Colombia; otros países caen al fallback Colombia hasta tener su estrategia validada). Colombia IDÉNTICO: golden test 7/7 campos iguales (4 tipos de contrato) + wiring NominaModel::calcular === estrategia. Suite G38 verifica las estrategias. NO se construyó nómina extranjera (Fase C completa = con asesoría laboral local). — v6.4 (2026-07-08): Fase B multi-país (country packs) — database/paises/{CO,MX,XX}.sql: plan de cuentas por país mapeado a los roles + localización (moneda/impuesto), aplicables como una migración; README documenta el formato y cómo añadir un país. México usa código agrupador SAT (arranque a validar por contador MX; IVA 16%, MXN). Suite G38 (38 grupos): columnas rol/pais + claves de localización presentes; cada country pack cubre los 19 roles; cuentaRol() resuelve; packs presentes. Verificado en MariaDB aislada: aplicar MX.sql → los 6 flujos postean bajo MX, 44 asientos cuadran, Balance cuadra, 100% de líneas usan códigos SAT (Colombia + México validan el diseño con 2 países). — v6.3 (2026-07-08): Fase A multi-país (núcleo país-agnóstico) — mig 047: cuentas_contables +rol/+pais + configuracion_app +pais/moneda/impuesto/factura_modo; ContabilidadModel usa ROLES semánticos por país (6 postear_* migrados; impuestoVentas() generaliza el IVA); FormatoHelper moneda por país; Admin → Localización. — v6.2 (2026-07-07): test profundo E2E en MariaDB; bug 'estado_vida' (derivada en WHERE) corregido. — v6.1 (2026-07-05): producto genérico "QuickServe OS" + instalador web /install/.
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
