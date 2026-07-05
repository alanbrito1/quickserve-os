<?php
/**
 * app/helpers/AuditoriaHelper.php
 * Segunda línea de auditoría (los triggers MySQL son la primera).
 * Registra operaciones complejas con contexto de IP y sesión del usuario.
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Inserta un registro en logs_historial desde la capa PHP.
 * Llamar cuando un trigger no es suficiente (ej: operaciones multi-tabla, anulaciones).
 *
 * @param string      $tabla          Nombre de la tabla afectada
 * @param int         $registro_id    PK del registro modificado
 * @param string      $campo          Nombre del campo
 * @param string|null $valor_anterior Valor antes del cambio (null en INSERT)
 * @param string|null $valor_nuevo    Valor después del cambio (null en DELETE)
 * @param string      $accion         'INSERT' | 'UPDATE' | 'DELETE'
 */
function log_registrar(
    string  $tabla,
    int     $registro_id,
    string  $campo,
    ?string $valor_anterior,
    ?string $valor_nuevo,
    string  $accion = 'UPDATE'
): void {
    // Obtener usuario activo de la sesión (null en procesos internos o cron)
    $usuario_id = $_SESSION['usuario_id'] ?? null;

    try {
        $stmt = db()->prepare(
            'INSERT INTO logs_historial
                (tabla, registro_id, campo, valor_anterior, valor_nuevo, accion, usuario_id, ip_address)
             VALUES
                (:tabla, :rid, :campo, :v_ant, :v_nvo, :accion, :uid, :ip)'
        );
        $stmt->execute([
            ':tabla'  => $tabla,
            ':rid'    => $registro_id,
            ':campo'  => $campo,
            ':v_ant'  => $valor_anterior,
            ':v_nvo'  => $valor_nuevo,
            ':accion' => $accion,
            ':uid'    => $usuario_id,
            ':ip'     => log_ip(),
        ]);
    } catch (PDOException $e) {
        // Los errores de auditoría no deben interrumpir el flujo principal de negocio
        error_log('[QuickServe OS Audit] ' . $e->getMessage());
    }
}

/**
 * Obtiene la IP real del cliente considerando proxies de hosting compartido.
 * Prioriza CF-Connecting-IP (CloudFlare) y X-Forwarded-For (proxies genéricos).
 */
function log_ip(): string
{
    // CloudFlare (muy común en hosting compartido con CDN)
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $ip = filter_var(trim($_SERVER['HTTP_CF_CONNECTING_IP']), FILTER_VALIDATE_IP);
        if ($ip) return $ip;
    }

    // Proxy genérico o balanceador
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $partes = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = filter_var(trim($partes[0]), FILTER_VALIDATE_IP);
        if ($ip) return $ip;
    }

    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}
