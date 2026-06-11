<?php
/**
 * admin/backup.php — Gestión de la base de datos y actualizaciones del sistema.
 *
 * Funcionalidades:
 *   - Descargar backup completo de la BD en SQL  (GET ?action=download)
 *   - Descargar backup del código fuente en ZIP  (GET ?action=download_code)
 *   - Subir y ejecutar archivos de migración SQL (POST sql_file)
 *   - Subir y aplicar actualización de código    (POST zip_file)
 *   - Ver estadísticas de tablas
 *
 * Acceso: solo superadmin.
 * Seguridad: CSRF en todos los formularios; path-traversal bloqueado en ZIP;
 *   archivos sensibles (database.php, uploads/) protegidos contra sobreescritura.
 */
require_once __DIR__ . '/../app/middleware/auth_check.php';
require_once __DIR__ . '/../app/helpers/AuditoriaHelper.php';

$nav_activo = 'admin';
$nav_sub    = 'backup';

if (($_SESSION['usuario_rol'] ?? '') !== 'superadmin') {
    http_response_code(403); include __DIR__ . '/../app/views/errors/403.php'; exit;
}

// ── Helper: validar CSRF enviado en parámetro GET (para links de descarga) ──────
function csrf_verificar_get(): bool {
    $token = $_GET['token'] ?? '';
    return !empty($token) && hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

// ════════════════════════════════════════════════════════════════════════════════
// GET: DESCARGAR BACKUP SQL (base de datos completa)
// ════════════════════════════════════════════════════════════════════════════════
if (($_GET['action'] ?? '') === 'download') {
    if (!csrf_verificar_get()) die('Token inválido.');

    $filename = 'clandestino_backup_' . date('Y-m-d_H-i-s') . '.sql';
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store');
    header('Pragma: no-cache');

    $pdo    = db();
    $tablas = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

    echo "-- ClanDestino ERP — Database Backup\n";
    echo '-- Generated: ' . date('Y-m-d H:i:s') . "\n";
    echo '-- Version: '   . (defined('APP_VERSION') ? APP_VERSION : '4.x') . "\n";
    echo '-- Tables: '    . count($tablas) . "\n\n";
    echo "SET FOREIGN_KEY_CHECKS = 0;\n";
    echo "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n";

    foreach ($tablas as $tabla) {
        // Validar que el nombre de tabla solo contenga caracteres seguros
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $tabla)) continue;

        $create = $pdo->query("SHOW CREATE TABLE `$tabla`")->fetch(PDO::FETCH_ASSOC);
        echo "-- ────────────────────────────────────────────\n";
        echo "-- Table: `$tabla`\n";
        echo "-- ────────────────────────────────────────────\n";
        echo "DROP TABLE IF EXISTS `$tabla`;\n";
        echo $create['Create Table'] . ";\n\n";

        $rows = $pdo->query("SELECT * FROM `$tabla`")->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($rows)) {
            $cols = array_map(fn($c) => "`$c`", array_keys($rows[0]));
            $lote = [];
            foreach ($rows as $row) {
                $vals = array_map(function ($val) {
                    if ($val === null) return 'NULL';
                    return "'" . str_replace(
                        ["\\",   "'",   "\n",   "\r",   "\x1a"],
                        ["\\\\", "\\'", "\\n",  "\\r",  "\\Z"]
                        , $val) . "'";
                }, array_values($row));
                $lote[] = '(' . implode(', ', $vals) . ')';
                // Bloques de 500 filas para no generar líneas demasiado largas
                if (count($lote) >= 500) {
                    echo 'INSERT INTO `' . $tabla . '` (' . implode(', ', $cols) . ") VALUES\n";
                    echo implode(",\n", $lote) . ";\n";
                    $lote = [];
                }
            }
            if (!empty($lote)) {
                echo 'INSERT INTO `' . $tabla . '` (' . implode(', ', $cols) . ") VALUES\n";
                echo implode(",\n", $lote) . ";\n";
            }
            echo "\n";
        }
    }

    echo "SET FOREIGN_KEY_CHECKS = 1;\n";
    echo "-- End of backup\n";
    log_registrar('configuracion_app', 0, 'backup_bd', null, $filename, 'INSERT');
    exit;
}

// ════════════════════════════════════════════════════════════════════════════════
// GET: DESCARGAR BACKUP DE CÓDIGO (public_html/ como ZIP, excluye uploads/ y DB config)
// ════════════════════════════════════════════════════════════════════════════════
if (($_GET['action'] ?? '') === 'download_code') {
    if (!csrf_verificar_get()) die('Token inválido.');

    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        die('Error: la extensión ZipArchive no está disponible en este servidor.');
    }

    $source   = dirname(__DIR__);                                  // public_html/
    $filename = 'clandestino_codigo_' . date('Y-m-d_H-i-s') . '.zip';
    $tmpFile  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $filename;

    $zip = new ZipArchive();
    if ($zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        http_response_code(500); die('No se pudo crear el archivo ZIP temporal.');
    }

    // Directorios/archivos excluidos del backup de código:
    //   uploads/     → imágenes y archivos del usuario (no código)
    //   app/config/  → credenciales de BD (no deben distribuirse)
    $excluir = ['uploads/', 'app/config/'];

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($it as $file) {
        if (!$file->isFile()) continue;
        $abs      = $file->getRealPath();
        $relativo = str_replace('\\', '/', substr($abs, strlen($source) + 1));

        $omitir = false;
        foreach ($excluir as $ex) {
            if (str_starts_with($relativo, $ex)) { $omitir = true; break; }
        }
        if ($omitir) continue;

        $zip->addFile($abs, $relativo);
    }
    $zip->close();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmpFile));
    header('Cache-Control: no-cache, no-store');
    header('Pragma: no-cache');
    readfile($tmpFile);
    @unlink($tmpFile);

    log_registrar('configuracion_app', 0, 'backup_codigo', null, $filename, 'INSERT');
    exit;
}

// ════════════════════════════════════════════════════════════════════════════════
// POST: EJECUTAR MIGRACIÓN SQL
// ════════════════════════════════════════════════════════════════════════════════
$migration_result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['sql_file'])) {
    if (!csrf_verificar()) {
        $migration_result = ['ok' => false, 'msg' => 'Token CSRF inválido.'];
    } else {
        $file = $_FILES['sql_file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $migration_result = ['ok' => false, 'msg' => 'Error al subir el archivo.'];
        } elseif (!str_ends_with(strtolower($file['name']), '.sql')) {
            $migration_result = ['ok' => false, 'msg' => 'Solo se permiten archivos .sql'];
        } elseif ($file['size'] > 5242880) {
            $migration_result = ['ok' => false, 'msg' => 'El archivo no puede superar 5 MB.'];
        } else {
            $sql_content = file_get_contents($file['tmp_name']);
            if ($sql_content === false) {
                $migration_result = ['ok' => false, 'msg' => 'No se pudo leer el archivo.'];
            } else {
                $stmts = array_filter(
                    array_map('trim', explode(';', $sql_content)),
                    fn($s) => strlen($s) > 3 && !preg_match('/^--/', ltrim($s))
                );
                $ejecutados = 0;
                $errores    = [];
                foreach ($stmts as $sql) {
                    try {
                        db()->exec($sql . ';');
                        $ejecutados++;
                    } catch (PDOException $e) {
                        $errores[] = substr($sql, 0, 80) . '… → ' . $e->getMessage();
                    }
                }
                $migration_result = [
                    'ok'       => empty($errores),
                    'ejecutados'=> $ejecutados,
                    'errores'  => $errores,
                    'msg'      => empty($errores)
                        ? "$ejecutados sentencias ejecutadas correctamente."
                        : count($errores) . ' error(es) — ' . $ejecutados . ' sentencias OK.',
                ];
                log_registrar('configuracion_app', 0, 'migracion',
                    null, $file['name'] . ' → ' . $ejecutados . ' stmt', 'INSERT');
            }
        }
    }
}

// ════════════════════════════════════════════════════════════════════════════════
// POST: SUBIR ACTUALIZACIÓN DE CÓDIGO (ZIP)
// Extrae el ZIP sobre public_html/, protegiendo archivos sensibles.
// ════════════════════════════════════════════════════════════════════════════════
$update_result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['zip_file'])) {
    if (!csrf_verificar()) {
        $update_result = ['ok' => false, 'msg' => 'Token CSRF inválido.'];
    } elseif (!class_exists('ZipArchive')) {
        $update_result = ['ok' => false, 'msg' => 'ZipArchive no disponible en este servidor.'];
    } else {
        $file = $_FILES['zip_file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $update_result = ['ok' => false, 'msg' => 'Error al subir el archivo.'];
        } elseif (!str_ends_with(strtolower($file['name']), '.zip')) {
            $update_result = ['ok' => false, 'msg' => 'Solo se permiten archivos .zip'];
        } elseif ($file['size'] > 52428800) { // 50 MB máximo
            $update_result = ['ok' => false, 'msg' => 'El archivo no puede superar 50 MB.'];
        } else {
            $zip = new ZipArchive();
            if ($zip->open($file['tmp_name']) !== true) {
                $update_result = ['ok' => false, 'msg' => 'No se pudo abrir el archivo ZIP.'];
            } else {
                $destino   = dirname(__DIR__); // public_html/
                $extraidos = 0;
                $omitidos  = 0;
                $errores   = [];

                // Estos archivos NUNCA se sobreescriben para proteger la instalación
                $protegidos = [
                    'app/config/database.php',
                    'app/config/app.php',
                ];

                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $nombre = $zip->getNameIndex($i);

                    // Validar ruta por segmentos (zip-slip / path traversal):
                    // un str_replace de "../" es vulnerable a patrones anidados como
                    // "....//" que se convierten en "../" tras un solo reemplazo.
                    // En su lugar, se descarta cualquier entrada con segmento ".."
                    // o ruta absoluta — más robusto que reemplazar substrings.
                    $normalizado = str_replace('\\', '/', $nombre);
                    $segmentos   = explode('/', $normalizado);
                    if (str_starts_with($normalizado, '/') || in_array('..', $segmentos, true)) {
                        $omitidos++;
                        continue;
                    }
                    $limpio = ltrim($normalizado, '/');
                    if (empty($limpio) || str_ends_with($limpio, '/')) continue; // directorio

                    // Proteger archivos sensibles y uploads (imágenes del usuario)
                    $proteger = str_starts_with($limpio, 'uploads/');
                    foreach ($protegidos as $p) {
                        if ($limpio === $p) { $proteger = true; break; }
                    }
                    if ($proteger) { $omitidos++; continue; }

                    $destFile = $destino . '/' . $limpio;
                    $destDir  = dirname($destFile);

                    if (!is_dir($destDir) && !mkdir($destDir, 0755, true)) {
                        $errores[] = "No se pudo crear directorio: $destDir";
                        continue;
                    }

                    $contenido = $zip->getFromIndex($i);
                    if ($contenido === false) {
                        $errores[] = "No se pudo leer del ZIP: $limpio";
                    } elseif (file_put_contents($destFile, $contenido) === false) {
                        $errores[] = "No se pudo escribir: $limpio";
                    } else {
                        $extraidos++;
                    }
                }
                $zip->close();

                $update_result = [
                    'ok'      => empty($errores),
                    'msg'     => empty($errores)
                        ? "$extraidos archivos actualizados. $omitidos omitidos (protegidos)."
                        : count($errores) . " error(es). $extraidos archivos actualizados OK.",
                    'errores' => $errores,
                ];
                log_registrar('configuracion_app', 0, 'actualizacion_codigo',
                    null, $file['name'] . ' → ' . $extraidos . ' archivos', 'INSERT');
            }
        }
    }
}

// ── Estadísticas de tablas ────────────────────────────────────────────────────
$tabla_stats = db()->query(
    "SELECT TABLE_NAME AS nombre, TABLE_ROWS AS filas,
            ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024, 1) AS kb
     FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
     ORDER BY TABLE_NAME"
)->fetchAll();

$total_filas = array_sum(array_column($tabla_stats, 'filas'));
$total_kb    = array_sum(array_column($tabla_stats, 'kb'));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Base de Datos — <?= APP_NAME ?></title>
    <style>
        :root { --brand:#e94f37; --dark:#111827; --g5:#6b7280; --g8:#d1d5db; --g9:#f3f4f6; --white:#fff; --green:#059669; }
        *,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
        body { font-family:system-ui,-apple-system,sans-serif; background:var(--g9); color:var(--dark); }
        .main { max-width:900px; margin:0 auto; padding:20px 14px 60px; }
        .page-title { font-size:22px; font-weight:800; margin-bottom:20px; }
        .kpi-row { display:grid; grid-template-columns:repeat(auto-fit,minmax(130px,1fr)); gap:10px; margin-bottom:24px; }
        .kpi { background:var(--white); border:1px solid var(--g8); border-radius:12px; padding:14px 16px; }
        .kpi-val { font-size:22px; font-weight:800; }
        .kpi-lbl { font-size:11px; color:var(--g5); margin-top:4px; text-transform:uppercase; letter-spacing:.4px; }
        .card { background:var(--white); border:1px solid var(--g8); border-radius:12px; padding:20px; margin-bottom:20px; }
        .card-title { font-size:13px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--g5); margin-bottom:14px; }
        .cards-row { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:20px; }
        @media(max-width:620px){ .cards-row { grid-template-columns:1fr; } }
        .btn-primary { padding:10px 20px; background:var(--brand); color:#fff; border:none; border-radius:8px; font-size:14px; font-weight:700; cursor:pointer; text-decoration:none; display:inline-block; }
        .btn-primary:hover { filter:brightness(.9); }
        .btn-dark { background:#374151; }
        .btn-dark:hover { background:#1f2937; filter:none; }
        .btn-green { background:var(--green); }
        .btn-green:hover { background:#047857; filter:none; }
        .alert-ok  { background:#d1fae5; color:#065f46; padding:12px 14px; border-radius:10px; margin-bottom:14px; font-size:14px; border:1px solid #6ee7b7; }
        .alert-err { background:#fee2e2; color:#991b1b; padding:12px 14px; border-radius:10px; margin-bottom:14px; font-size:14px; border:1px solid #fca5a5; }
        .alert-err ul { margin:6px 0 0 18px; font-size:12px; }
        .tbl-wrap { border:1px solid var(--g8); border-radius:10px; overflow:hidden; overflow-x:auto; }
        .tbl { width:100%; border-collapse:collapse; font-size:13px; }
        .tbl thead th { background:var(--g9); padding:9px 12px; text-align:left; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--g5); }
        .tbl thead th.r { text-align:right; }
        .tbl tbody tr { border-bottom:1px solid var(--g9); }
        .tbl tbody tr:last-child { border-bottom:none; }
        .tbl td { padding:8px 12px; }
        .tbl td.r { text-align:right; font-variant-numeric:tabular-nums; color:var(--g5); }
        code { background:var(--g9); padding:1px 6px; border-radius:4px; font-size:12px; }
        .warning-box { background:#fef3c7; border:1px solid #fde68a; border-radius:10px; padding:14px; margin-bottom:16px; font-size:13px; color:#92400e; }
        .info-box { background:#eff6ff; border:1px solid #bfdbfe; border-radius:10px; padding:14px; margin-bottom:16px; font-size:13px; color:#1d4ed8; }
        .fg { display:flex; flex-direction:column; gap:4px; margin-bottom:12px; }
        .fg label { font-size:12px; font-weight:600; color:var(--g5); }
        .fg .hint { font-size:11px; color:var(--g5); line-height:1.5; }
        .section-sep { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--g5); margin:28px 0 14px; border-top:2px solid var(--g9); padding-top:14px; }

        /* ════════════════════════════════════════════════════════════════
           RESPONSIVE — ADMIN BACKUP / BASE DE DATOS
           ════════════════════════════════════════════════════════════════ */
        /* Tabla con scroll horizontal */
        .tbl-wrap { overflow-x:auto; -webkit-overflow-scrolling:touch; }

        /* xs: < 480px */
        @media (max-width:479px) {
            .main { padding:12px 10px 40px; }
            /* Cards en 1 columna (ya tiene @media 620px → 1col; reforzar) */
            .cards-row { grid-template-columns:1fr !important; }
            /* Botones full-width */
            .btn-primary { width:100%; min-height:44px; text-align:center; margin-bottom:8px; }
            .kpi-row { grid-template-columns:1fr 1fr !important; gap:8px; }
            .kpi-val { font-size:18px !important; }
            /* Tabla scroll */
            .tbl { min-width:340px; }
        }
        /* sm: 480-619px (ya manejado por 620px media query del archivo) */
        @media (min-width:480px) and (max-width:619px) {
            .kpi-row { grid-template-columns:repeat(2,1fr) !important; }
        }
        /* ≥1600px */
        @media (min-width:1600px) {
            .main { max-width:1200px; }
            .kpi-val { font-size:26px !important; }
            .card { padding:24px !important; }
        }
        /* TV ≥1920px */
        @media (min-width:1920px) {
            .main { max-width:1440px; }
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/../app/views/nav.php'; ?>
<main class="main">
    <h1 class="page-title">Base de Datos y Actualizaciones</h1>

    <!-- KPIs -->
    <div class="kpi-row">
        <div class="kpi"><div class="kpi-val"><?= count($tabla_stats) ?></div><div class="kpi-lbl">Tablas</div></div>
        <div class="kpi"><div class="kpi-val"><?= number_format($total_filas, 0, ',', '.') ?></div><div class="kpi-lbl">Registros totales</div></div>
        <div class="kpi"><div class="kpi-val"><?= number_format($total_kb, 0, ',', '.') ?> KB</div><div class="kpi-lbl">Tamaño estimado</div></div>
        <div class="kpi"><div class="kpi-val" style="font-size:16px"><?= date('d/m/Y') ?></div><div class="kpi-lbl">Fecha actual</div></div>
    </div>

    <!-- ══ BACKUPS ══ -->
    <div class="section-sep">Copias de Seguridad</div>
    <div class="cards-row">

        <!-- Backup Base de Datos -->
        <div class="card">
            <div class="card-title">Base de Datos (SQL)</div>
            <p style="font-size:13px;color:var(--g5);margin-bottom:14px;line-height:1.5">
                Archivo <code>.sql</code> con todas las tablas y registros.
                Importar en phpMyAdmin para restaurar.
            </p>
            <a href="?action=download&token=<?= htmlspecialchars(csrf_token()) ?>"
               class="btn-primary">
                Descargar backup SQL
            </a>
        </div>

        <!-- Backup Código Fuente -->
        <div class="card">
            <div class="card-title">Código Fuente (ZIP)</div>
            <p style="font-size:13px;color:var(--g5);margin-bottom:14px;line-height:1.5">
                Archivo <code>.zip</code> con todos los archivos PHP del sistema.
                <em>No incluye</em> <code>uploads/</code> ni credenciales de BD.
            </p>
            <a href="?action=download_code&token=<?= htmlspecialchars(csrf_token()) ?>"
               class="btn-primary btn-dark">
                Descargar backup código
            </a>
        </div>

    </div>

    <!-- ══ ACTUALIZACIONES ══ -->
    <div class="section-sep">Actualizaciones del Sistema</div>
    <div class="cards-row">

        <!-- Migración SQL -->
        <div class="card">
            <div class="card-title">Ejecutar Migración SQL</div>
            <div class="warning-box">
                <strong>Precaución:</strong> Puede modificar o borrar datos. Haz backup antes.
                Solo sube archivos <code>.sql</code> de confianza.
            </div>

            <?php if ($migration_result): ?>
            <div class="<?= $migration_result['ok'] ? 'alert-ok' : 'alert-err' ?>">
                <?= htmlspecialchars($migration_result['msg']) ?>
                <?php if (!empty($migration_result['errores'])): ?>
                <ul>
                    <?php foreach (array_slice($migration_result['errores'], 0, 5) as $err): ?>
                    <li><?= htmlspecialchars($err) ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <div class="fg">
                    <label>Archivo de migración (.sql)</label>
                    <input type="file" name="sql_file" accept=".sql" required>
                    <span class="hint">Máx. 5 MB. Archivos en <code>database/migrations/</code></span>
                </div>
                <button type="submit" class="btn-primary btn-dark"
                        onclick="return confirm('¿Ejecutar esta migración SQL? Haz backup primero.')">
                    Ejecutar Migración
                </button>
            </form>
        </div>

        <!-- Actualización de Código -->
        <div class="card">
            <div class="card-title">Subir Actualización de Código</div>
            <div class="info-box">
                Sube un <code>.zip</code> con los archivos actualizados del proyecto.
                Se extraerán sobre <code>public_html/</code>.
                <br><strong>Protegidos (no se sobreescriben):</strong>
                <code>app/config/database.php</code>, <code>app/config/app.php</code>, <code>uploads/</code>.
            </div>

            <?php if ($update_result): ?>
            <div class="<?= $update_result['ok'] ? 'alert-ok' : 'alert-err' ?>">
                <?= htmlspecialchars($update_result['msg']) ?>
                <?php if (!empty($update_result['errores'])): ?>
                <ul>
                    <?php foreach (array_slice($update_result['errores'], 0, 5) as $err): ?>
                    <li><?= htmlspecialchars($err) ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <div class="fg">
                    <label>Archivo de actualización (.zip)</label>
                    <input type="file" name="zip_file" accept=".zip" required>
                    <span class="hint">Máx. 50 MB. Estructura interna debe replicar <code>public_html/</code></span>
                </div>
                <button type="submit" class="btn-primary btn-green"
                        onclick="return confirm('¿Aplicar la actualización de código? Haz backup primero y verifica el contenido del ZIP.')">
                    Aplicar Actualización
                </button>
            </form>
        </div>

    </div>

    <!-- ══ ESTADÍSTICAS ══ -->
    <div class="section-sep">Tablas de la Base de Datos</div>
    <div class="card">
        <div class="tbl-wrap">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>Tabla</th>
                        <th class="r">Registros aprox.</th>
                        <th class="r">Tamaño (KB)</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($tabla_stats as $t): ?>
                <tr>
                    <td><code><?= htmlspecialchars($t['nombre']) ?></code></td>
                    <td class="r"><?= number_format((int)$t['filas'], 0, ',', '.') ?></td>
                    <td class="r"><?= number_format((float)$t['kb'], 2, ',', '.') ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</main>
</body>
</html>
