<?php
/**
 * app/config/database.example.php
 * PLANTILLA de configuración de base de datos de QuickServe OS.
 *
 * NO edites este archivo. El instalador web (/install/) genera automáticamente
 * el archivo real `database.php` con las credenciales que ingreses.
 *
 * Instalación MANUAL (sin el instalador): copia este archivo como
 * `database.php` en la misma carpeta y reemplaza los valores de abajo.
 *
 * SEGURIDAD: `database.php` NUNCA debe ser accesible vía web (vive en app/,
 * fuera de public_html) y está excluido del repositorio (.gitignore).
 */

define('DB_HOST',    'localhost');
define('DB_NAME',    'nombre_de_tu_base_de_datos');
define('DB_USER',    'usuario_de_tu_base_de_datos');
define('DB_PASS',    'contraseña_de_tu_base_de_datos');
define('DB_CHARSET', 'utf8mb4');

/**
 * Retorna la conexión PDO como singleton lazy.
 * Uso en cualquier archivo: $pdo = db();
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,  // lanza excepciones en error
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,        // arrays asociativos por defecto
            PDO::ATTR_EMULATE_PREPARES   => false,                   // prepared statements reales (previene SQLi)
        ]);
    } catch (PDOException $e) {
        // No exponer datos de conexión al navegador
        error_log('[QuickServe DB] ' . $e->getMessage());
        http_response_code(503);
        exit('<h1 style="font-family:sans-serif">Error de base de datos. Contacte al administrador.</h1>');
    }

    return $pdo;
}
