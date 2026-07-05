<?php
/**
 * install/lib.php — Funciones del instalador web de QuickServe OS.
 *
 * Es AUTÓNOMO: la base de datos y app/config/database.php aún no existen cuando
 * corre el instalador, así que este archivo no depende de la app. Solo usa PDO
 * y funciones nativas de PHP.
 */

const QS_MIN_PHP = '8.0.0';

// ── Rutas ────────────────────────────────────────────────────────────────────
/** public_html/app/config/ — donde vive (o vivirá) database.php */
function qs_config_dir(): string  { return dirname(__DIR__) . '/app/config'; }
function qs_config_file(): string { return qs_config_dir() . '/database.php'; }
function qs_lock_file(): string   { return qs_config_dir() . '/installed.lock'; }
function qs_uploads_dir(): string { return dirname(__DIR__) . '/uploads'; }

/**
 * Localiza un archivo .sql. Prefiere database/ (cuando se subió el repo
 * completo, útil en desarrollo) y cae a install/sql/ (copia empaquetada que
 * siempre viaja dentro de public_html en un despliegue normal a cPanel).
 */
function qs_sql_path(string $nombre): ?string
{
    $candidatos = [
        dirname(dirname(__DIR__)) . '/database/' . $nombre, // ../../database/<nombre>
        __DIR__ . '/sql/' . $nombre,                        // ./sql/<nombre>
    ];
    foreach ($candidatos as $p) {
        if (is_file($p)) return $p;
    }
    return null;
}

// ── Estado de instalación ────────────────────────────────────────────────────
/**
 * ¿El sistema ya está instalado? Es la barrera anti-reinstalación.
 * True si existe el candado, o si database.php conecta y ya hay usuarios.
 */
function qs_ya_instalado(): bool
{
    if (is_file(qs_lock_file())) return true;

    if (is_file(qs_config_file())) {
        try {
            require_once qs_config_file();          // define constantes DB_* y db()
            if (function_exists('db')) {
                $n = (int) db()->query("SELECT COUNT(*) FROM usuarios")->fetchColumn();
                if ($n > 0) return true;
            }
        } catch (Throwable $e) {
            // config incompleta o sin tablas → tratar como NO instalado (permite reintentar)
        }
    }
    return false;
}

// ── Requisitos del servidor ──────────────────────────────────────────────────
/**
 * Devuelve la lista de chequeos [label, ok, critico, detalle].
 * Si algún chequeo crítico falla, no se debe permitir continuar.
 */
function qs_requisitos(): array
{
    $checks = [];

    $phpOk = version_compare(PHP_VERSION, QS_MIN_PHP, '>=');
    $checks[] = ['PHP ' . QS_MIN_PHP . ' o superior', $phpOk, true, 'Versión actual: ' . PHP_VERSION];

    foreach (['pdo', 'pdo_mysql', 'mbstring', 'json'] as $ext) {
        $checks[] = ['Extensión ' . $ext, extension_loaded($ext), true, ''];
    }
    // Recomendadas (no críticas)
    $checks[] = ['Extensión zip (backups y actualizaciones)', extension_loaded('zip'), false, ''];
    $checks[] = ['Extensión gd (fotos de activos)', extension_loaded('gd'), false, ''];

    $cfgOk = is_writable(qs_config_dir());
    $checks[] = ['Carpeta app/config/ escribible', $cfgOk, true, qs_config_dir()];

    $upOk = is_dir(qs_uploads_dir()) ? is_writable(qs_uploads_dir()) : @mkdir(qs_uploads_dir(), 0755, true);
    $checks[] = ['Carpeta uploads/ escribible', $upOk, false, qs_uploads_dir()];

    $schemaOk = qs_sql_path('schema.sql') !== null;
    $checks[] = ['Script schema.sql disponible', $schemaOk, true, (string) qs_sql_path('schema.sql')];

    return $checks;
}

/** True si todos los requisitos CRÍTICOS pasan. */
function qs_requisitos_ok(array $checks): bool
{
    foreach ($checks as $c) {
        if ($c[2] === true && $c[1] === false) return false;
    }
    return true;
}

// ── Conexión ─────────────────────────────────────────────────────────────────
/**
 * Conecta por PDO a la base indicada. Si $crearSiNoExiste y la base no existe,
 * intenta crearla (best-effort, requiere privilegios). Lanza RuntimeException
 * con un mensaje amigable si falla.
 */
function qs_conectar(string $host, string $nombre, string $user, string $pass, bool $crearSiNoExiste = false): PDO
{
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        return new PDO("mysql:host={$host};dbname={$nombre};charset=utf8mb4", $user, $pass, $opts);
    } catch (PDOException $e) {
        // 1049 = Unknown database
        if ($crearSiNoExiste && (int) $e->getCode() === 1049) {
            try {
                $srv = new PDO("mysql:host={$host};charset=utf8mb4", $user, $pass, $opts);
                $srv->exec("CREATE DATABASE `" . str_replace('`', '', $nombre)
                    . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                return new PDO("mysql:host={$host};dbname={$nombre};charset=utf8mb4", $user, $pass, $opts);
            } catch (PDOException $e2) {
                error_log('[QuickServe Install] crear BD: ' . $e2->getMessage());
                throw new RuntimeException('No se pudo crear la base de datos «' . $nombre
                    . '». Créala primero en tu panel (cPanel → MySQL Databases) y vuelve a intentar.');
            }
        }
        error_log('[QuickServe Install] conexión: ' . $e->getMessage());
        // Mensajes comunes traducidos
        $cod = (int) $e->getCode();
        if ($cod === 1045) throw new RuntimeException('Usuario o contraseña de la base de datos incorrectos.');
        if ($cod === 1049) throw new RuntimeException('La base de datos «' . $nombre . '» no existe.');
        if ($cod === 2002) throw new RuntimeException('No se pudo conectar al servidor «' . $host . '». Verifica el host.');
        throw new RuntimeException('No se pudo conectar a la base de datos. Verifica los datos e inténtalo de nuevo.');
    }
}

// ── Runner SQL consciente de DELIMITER ───────────────────────────────────────
/**
 * Ejecuta un archivo .sql sentencia por sentencia, respetando cambios de
 * DELIMITER (necesario para los triggers BEGIN…END del schema). Ignora
 * comentarios de línea y líneas vacías entre sentencias.
 */
function qs_run_sql_file(PDO $pdo, string $archivo): void
{
    $sql = file_get_contents($archivo);
    if ($sql === false) {
        throw new RuntimeException('No se pudo leer el script SQL: ' . basename($archivo));
    }

    $delim  = ';';
    $buffer = '';
    $lineas = preg_split("/\r\n|\n|\r/", $sql);

    foreach ($lineas as $linea) {
        $trim = trim($linea);

        // Entre sentencias: saltar líneas en blanco y comentarios de línea
        if ($buffer === '' && ($trim === '' || str_starts_with($trim, '--'))) {
            continue;
        }

        // Cambio de delimitador (directiva del cliente, no es SQL)
        if (stripos($trim, 'DELIMITER ') === 0) {
            $delim = trim(substr($trim, 10));
            continue;
        }

        $buffer .= $linea . "\n";

        // ¿La sentencia acumulada termina con el delimitador activo?
        $bt = rtrim($buffer);
        if ($delim !== '' && substr($bt, -strlen($delim)) === $delim) {
            $stmt = trim(substr($bt, 0, -strlen($delim)));
            if ($stmt !== '') {
                $pdo->exec($stmt);
            }
            $buffer = '';
        }
    }

    $resto = trim($buffer);
    if ($resto !== '') {
        $pdo->exec($resto);
    }
}

// ── Acciones de instalación ──────────────────────────────────────────────────
/**
 * Fija las credenciales del administrador sobre el superadmin sembrado por
 * schema.sql (deja de servir la clave por defecto). Si por algún motivo no
 * existe, lo crea con permisos totales.
 */
function qs_crear_admin(PDO $pdo, string $nombre, string $email, string $passwordPlano): void
{
    $hash = password_hash($passwordPlano, PASSWORD_BCRYPT, ['cost' => 12]);

    $upd = $pdo->prepare(
        "UPDATE usuarios SET nombre = ?, email = ?, password_hash = ?, activo = 1
         WHERE rol = 'superadmin' ORDER BY id LIMIT 1"
    );
    $upd->execute([$nombre, $email, $hash]);

    if ($upd->rowCount() === 0) {
        // No había superadmin sembrado: crear uno nuevo con permisos totales
        $ins = $pdo->prepare(
            "INSERT INTO usuarios (nombre, email, password_hash, rol, activo)
             VALUES (?, ?, ?, 'superadmin', 1)"
        );
        $ins->execute([$nombre, $email, $hash]);
        $uid = (int) $pdo->lastInsertId();
        foreach (['ventas','compras','inventario','nomina','productos','activos','reportes','proveedores','costos'] as $m) {
            $pdo->prepare("INSERT INTO permisos_modulos (usuario_id, modulo, nivel_acceso)
                           VALUES (?, ?, 'admin_total')")->execute([$uid, $m]);
        }
    }
}

/** Fija el nombre del negocio (configuracion_app.nombre_negocio). */
function qs_set_negocio(PDO $pdo, string $nombreNegocio): void
{
    $pdo->prepare(
        "INSERT INTO configuracion_app (clave, valor) VALUES ('nombre_negocio', ?)
         ON DUPLICATE KEY UPDATE valor = VALUES(valor)"
    )->execute([$nombreNegocio]);
}

/** Genera el contenido de app/config/database.php con las credenciales dadas. */
function qs_config_contenido(string $host, string $nombre, string $user, string $pass): string
{
    $h = var_export($host,   true);
    $n = var_export($nombre, true);
    $u = var_export($user,   true);
    $p = var_export($pass,   true);

    return <<<PHP
<?php
/**
 * app/config/database.php
 * Generado por el instalador de QuickServe OS. Conexión PDO (singleton).
 * SEGURIDAD: este archivo NUNCA debe ser accesible vía web.
 */

define('DB_HOST',    {$h});
define('DB_NAME',    {$n});
define('DB_USER',    {$u});
define('DB_PASS',    {$p});
define('DB_CHARSET', 'utf8mb4');

function db(): PDO
{
    static \$pdo = null;
    if (\$pdo !== null) return \$pdo;

    \$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    try {
        \$pdo = new PDO(\$dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException \$e) {
        error_log('[QuickServe DB] ' . \$e->getMessage());
        http_response_code(503);
        exit('<h1 style="font-family:sans-serif">Error de base de datos. Contacte al administrador.</h1>');
    }
    return \$pdo;
}

PHP;
}

/**
 * Escribe app/config/database.php. Devuelve true si lo logró; si la carpeta no
 * es escribible devuelve false (el instalador muestra el contenido para pegarlo
 * a mano).
 */
function qs_write_db_config(string $host, string $nombre, string $user, string $pass): bool
{
    $contenido = qs_config_contenido($host, $nombre, $user, $pass);
    return @file_put_contents(qs_config_file(), $contenido) !== false;
}

/** Crea el candado de instalación. */
function qs_crear_lock(): void
{
    @file_put_contents(qs_lock_file(), 'Instalado el ' . date('Y-m-d H:i:s') . "\n");
}
