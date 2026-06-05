<?php
/**
 * admin/api/guardar_apariencia.php — Guarda configuración de apariencia y negocio.
 * Maneja logo (upload), nombre del negocio, colores, fuente y radio de bordes.
 */
require_once __DIR__ . '/../../app/middleware/auth_check.php';
require_once __DIR__ . '/../../app/helpers/AuditoriaHelper.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success'=>false,'error'=>'Método no permitido.']);
    exit;
}

if (!in_array($_SESSION['usuario_rol'] ?? '', ['superadmin','admin'], true)) {
    http_response_code(403);
    echo json_encode(['success'=>false,'error'=>'Sin permisos.']);
    exit;
}

if (!csrf_verificar()) {
    http_response_code(403);
    echo json_encode(['success'=>false,'error'=>'Token CSRF inválido.']);
    exit;
}

$uid = (int)($_SESSION['usuario_id'] ?? 0);

try {
    // ── Validar y preparar los valores ───────────────────────────────────────
    $nombre  = substr(trim($_POST['nombre_negocio'] ?? 'ClanDestino'), 0, 60);
    $brand   = trim($_POST['theme_brand']  ?? '#e94f37');
    $dark    = trim($_POST['theme_dark']   ?? '#111827');
    $font    = trim($_POST['theme_font']   ?? 'system-ui, -apple-system, sans-serif');
    $radius  = max(0, min(24, (int)($_POST['theme_radius'] ?? 12)));
    $quitarLogo = ($_POST['logo_quitar'] ?? '0') === '1';

    // Validar formato de colores (hex #RRGGBB)
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $brand)) $brand = '#e94f37';
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $dark))  $dark  = '#111827';

    // Fuentes permitidas (whitelist única — evita inyección CSS en nav.php)
    // IMPORTANTE: Esta lista se usa dos veces más abajo; no duplicar.
    $fontsOk = [
        'system-ui, -apple-system, sans-serif',
        'Arial, Helvetica, sans-serif',
        "'Segoe UI', Tahoma, Geneva, sans-serif",
        "'Helvetica Neue', Helvetica, Arial, sans-serif",
        'Georgia, "Times New Roman", serif',
        "'Courier New', Courier, monospace",
    ];
    if (!in_array($font, $fontsOk, true)) $font = 'system-ui, -apple-system, sans-serif';

    $quitarLogoLogin = ($_POST['logo_login_quitar'] ?? '0') === '1';

    // ── Función reutilizable para subir un logo ───────────────────────────────
    $subirLogo = function(string $fileKey, string $prefijo) use ($uid): ?string {
        if (!isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) return null;
        $file  = $_FILES[$fileKey];
        $types = ['image/png'=>'png','image/jpeg'=>'jpg','image/jpg'=>'jpg',
                  'image/svg+xml'=>'svg','image/webp'=>'webp'];
        if ($file['size'] > 1048576)
            throw new RuntimeException('El logo no puede superar 1 MB.');
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);
        if (!isset($types[$mime]))
            throw new RuntimeException('Formato no permitido. Usa PNG, JPG, SVG o WebP.');
        $dir = BASE_PATH . '/uploads/logos/';
        if (!is_dir($dir) && !mkdir($dir, 0700, true)) // 0700: solo el propietario (más seguro en hosting compartido)
            throw new RuntimeException('No se pudo crear el directorio de logos.');
        $nombre = $prefijo . '_' . time() . '.' . $types[$mime];
        if (!move_uploaded_file($file['tmp_name'], $dir . $nombre))
            throw new RuntimeException('Error al guardar el archivo en el servidor.');
        return 'uploads/logos/' . $nombre;
    };

    /**
     * Sanitiza una ruta de logo ingresada manualmente.
     * Seguridad: previene path traversal exigiendo que la ruta comience con 'uploads/logos/'
     * y solo contenga caracteres seguros para nombres de archivo.
     */
    $sanitizarRutaLogo = function(string $ruta): string {
        // Solo caracteres válidos para nombres de archivo y directorios
        $ruta = preg_replace('/[^a-zA-Z0-9_\-\.\/]/', '', trim($ruta));
        // Prevenir path traversal: la ruta DEBE empezar por uploads/logos/
        if ($ruta !== '' && !str_starts_with($ruta, 'uploads/logos/')) {
            return ''; // Ruta inválida → ignorar silenciosamente
        }
        return $ruta;
    };

    // Logo horizontal: ruta manual tiene prioridad sobre upload
    $logo_ruta_manual = trim($_POST['logo_ruta_manual'] ?? '');
    if ($quitarLogo) {
        $logo_url = '';
    } elseif ($logo_ruta_manual !== '') {
        $logo_url = $sanitizarRutaLogo($logo_ruta_manual);
    } else {
        $logo_url = $subirLogo('logo_file', 'logo_nav');
    }

    // Logo vertical (página de login): ruta manual tiene prioridad sobre upload
    $logo_login_ruta_manual = trim($_POST['logo_login_ruta_manual'] ?? '');
    if ($quitarLogoLogin) {
        $logo_login_url = '';
    } elseif ($logo_login_ruta_manual !== '') {
        $logo_login_url = $sanitizarRutaLogo($logo_login_ruta_manual);
    } else {
        $logo_login_url = $subirLogo('logo_login_file', 'logo_login');
    }

    // ── Validar y limpiar campos de tipografía ────────────────────────────────
    // Reutilizar $fontsOk definida arriba (NO redefinir — evitar duplicación)
    $font_heading  = trim($_POST['font_heading']  ?? $font);
    if (!in_array($font_heading, $fontsOk, true)) $font_heading = $font;

    $fs_title    = max(14, min(40, (int)($_POST['font_size_title']    ?? 22)));
    $fs_subtitle = max(10, min(24, (int)($_POST['font_size_subtitle'] ?? 15)));
    $fs_body     = max(10, min(20, (int)($_POST['font_size_body']     ?? 13)));
    $fs_small    = max(8,  min(16, (int)($_POST['font_size_small']    ?? 11)));

    $color_text     = trim($_POST['color_text']     ?? '#111827');
    $color_text_sec = trim($_POST['color_text_sec'] ?? '#6b7280');
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color_text))     $color_text     = '#111827';
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color_text_sec)) $color_text_sec = '#6b7280';

    // ── Guardar en configuracion_app ─────────────────────────────────────────
    $pares = [
        'nombre_negocio'    => $nombre,
        'theme_brand'       => $brand,
        'theme_dark'        => $dark,
        'theme_font'        => $font,
        'font_heading'      => $font_heading,
        'theme_radius'      => (string)$radius,
        'font_size_title'   => (string)$fs_title,
        'font_size_subtitle'=> (string)$fs_subtitle,
        'font_size_body'    => (string)$fs_body,
        'font_size_small'   => (string)$fs_small,
        'color_text'        => $color_text,
        'color_text_sec'    => $color_text_sec,
    ];
    if ($logo_url       !== null) $pares['logo_url']       = $logo_url;
    if ($logo_login_url !== null) $pares['logo_url_login'] = $logo_login_url;

    $stmt = db()->prepare(
        'INSERT INTO configuracion_app (clave, valor, updated_by)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE valor = VALUES(valor), updated_by = VALUES(updated_by)'
    );
    foreach ($pares as $clave => $valor) {
        $stmt->execute([$clave, $valor, $uid]);
    }

    log_registrar('configuracion_app', 0, 'apariencia', null, 'Actualizada', 'UPDATE');

    echo json_encode(['success' => true]);

} catch (RuntimeException $e) {
    echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
} catch (Exception $e) {
    error_log('[ClanDestino Admin Apariencia] ' . $e->getMessage());
    echo json_encode(['success'=>false,'error'=>'Error interno al guardar.']);
}
