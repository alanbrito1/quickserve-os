<?php
/**
 * app/helpers/PermisosHelper.php
 * Verificación de permisos granulares por módulo.
 *
 * Jerarquía de niveles (de menor a mayor acceso):
 *   sin_acceso → solo_ver → solo_propios → editar_existentes → admin_total
 *
 * USO TÍPICO al inicio de una página protegida:
 *   permiso_requerir('ventas', 'solo_ver');       // bloquea si nivel < solo_ver
 *   permiso_requerir('nomina', 'editar_existentes'); // bloquea si nivel < editar_existentes
 *
 *   // Para filtrar por registros propios:
 *   if (permiso_es_solo_propios('ventas')) {
 *       $sql .= ' AND created_by = :uid';
 *   }
 */

require_once __DIR__ . '/../config/database.php';

// Mapa numérico de la jerarquía de niveles (mayor número = más acceso)
const PERMISO_NIVELES = [
    'sin_acceso'        => 0,
    'solo_ver'          => 1,
    'solo_propios'      => 2,
    'editar_existentes' => 3,
    'admin_total'       => 4,
];

/**
 * Obtiene el nivel de acceso del usuario activo para un módulo.
 * Los superadmin siempre retornan 'admin_total' sin consultar la DB.
 * El resultado se cachea en sesión para evitar queries repetidas.
 */
function permiso_get(string $modulo): string
{
    // El superadmin siempre tiene control total (no necesita filas en permisos_modulos)
    if (($_SESSION['usuario_rol'] ?? '') === 'superadmin') {
        return 'admin_total';
    }

    $usuario_id = $_SESSION['usuario_id'] ?? 0;
    if (!$usuario_id) {
        return 'sin_acceso';
    }

    // Caché de sesión para no repetir la query en cada llamada durante el mismo request
    $cache_key = "perm_{$modulo}";
    if (isset($_SESSION[$cache_key])) {
        return $_SESSION[$cache_key];
    }

    $stmt = db()->prepare(
        'SELECT nivel_acceso
         FROM permisos_modulos
         WHERE usuario_id = :uid AND modulo = :modulo
         LIMIT 1'
    );
    $stmt->execute([':uid' => $usuario_id, ':modulo' => $modulo]);
    $row = $stmt->fetch();

    $nivel = $row['nivel_acceso'] ?? 'sin_acceso';
    $_SESSION[$cache_key] = $nivel;

    return $nivel;
}

/**
 * Verifica si el usuario activo tiene AL MENOS el nivel requerido en un módulo.
 *
 * @param string $modulo       Ej: 'ventas', 'nomina', 'inventario'
 * @param string $nivel_minimo Ej: 'solo_ver', 'editar_existentes'
 */
function permiso_tiene(string $modulo, string $nivel_minimo): bool
{
    $nivel_actual = permiso_get($modulo);
    $valor_actual = PERMISO_NIVELES[$nivel_actual]   ?? 0;
    $valor_minimo = PERMISO_NIVELES[$nivel_minimo]   ?? 0;

    return $valor_actual >= $valor_minimo;
}

/**
 * Exige el nivel mínimo en un módulo.
 * Si el usuario no tiene acceso, muestra la página 403 y detiene la ejecución.
 *
 * Colocar al inicio de cada página del módulo:
 *   permiso_requerir('ventas', 'solo_ver');
 */
function permiso_requerir(string $modulo, string $nivel_minimo): void
{
    if (!permiso_tiene($modulo, $nivel_minimo)) {
        http_response_code(403);
        // La vista 403 puede usar $modulo para mostrar un mensaje contextual
        include __DIR__ . '/../views/errors/403.php';
        exit;
    }
}

/**
 * Retorna true si el usuario tiene exactamente nivel 'solo_propios' en el módulo.
 * Cuando es true, las queries SQL deben agregar AND created_by = :uid.
 */
function permiso_es_solo_propios(string $modulo): bool
{
    return permiso_get($modulo) === 'solo_propios';
}

/**
 * Retorna todos los módulos accesibles (nivel > sin_acceso) para el usuario activo.
 * Usado por el menú de navegación para mostrar solo lo que el usuario puede ver.
 *
 * @return array ['ventas' => 'solo_ver', 'compras' => 'admin_total', ...]
 */
function permiso_modulos_accesibles(): array
{
    $todos       = ['ventas','compras','inventario','nomina','productos','activos','reportes','proveedores','costos'];
    $accesibles  = [];

    foreach ($todos as $modulo) {
        $nivel = permiso_get($modulo);
        if ($nivel !== 'sin_acceso') {
            $accesibles[$modulo] = $nivel;
        }
    }

    return $accesibles;
}

/**
 * Invalida la caché de permisos de la sesión actual.
 * Llamar cuando se cambian los permisos de un usuario en la misma sesión.
 */
function permiso_limpiar_cache(): void
{
    $modulos = ['ventas','compras','inventario','nomina','productos','activos','reportes','proveedores','costos'];
    foreach ($modulos as $m) {
        unset($_SESSION["perm_{$m}"]);
    }
}
