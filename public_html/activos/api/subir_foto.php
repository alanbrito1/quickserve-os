<?php
/**
 * public_html/activos/api/subir_foto.php
 * Endpoint JSON: recibe, valida y guarda la foto de un activo.
 *
 * FLUJO MÓVIL:
 *   1. El cliente (JS) comprime la imagen con Canvas a ≤1400px y calidad 82%
 *      → El Canvas corrige la rotación EXIF automáticamente antes de enviar
 *   2. Este endpoint recibe el blob JPEG ya comprimido (típicamente < 500 KB)
 *   3. Como respaldo, si la imagen llegó sin comprimir (>2MB), PHP la redimensiona con GD
 *   4. Se guarda en uploads/activos/ y se actualiza foto_url en la DB
 */

require_once __DIR__ . '/../../app/middleware/auth_check.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metodo no permitido.']);
    exit;
}

permiso_requerir('activos', 'editar_existentes');

if (!csrf_verificar()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Token CSRF invalido.']);
    exit;
}

$activo_id = (int)($_POST['activo_id'] ?? 0);
if (!$activo_id) {
    echo json_encode(['success' => false, 'error' => 'ID de activo invalido.']);
    exit;
}

if (empty($_FILES['foto']['tmp_name']) || $_FILES['foto']['error'] !== UPLOAD_ERR_OK) {
    $errMsg = [
        UPLOAD_ERR_INI_SIZE   => 'El archivo supera upload_max_filesize del servidor.',
        UPLOAD_ERR_FORM_SIZE  => 'El archivo supera el limite del formulario.',
        UPLOAD_ERR_PARTIAL    => 'La subida fue incompleta. Intenta de nuevo.',
        UPLOAD_ERR_NO_FILE    => 'No se recibio ningun archivo.',
        UPLOAD_ERR_NO_TMP_DIR => 'Error de configuracion del servidor (sin directorio temporal).',
        UPLOAD_ERR_CANT_WRITE => 'Error al escribir en el servidor.',
    ];
    $code = $_FILES['foto']['error'] ?? UPLOAD_ERR_NO_FILE;
    echo json_encode(['success' => false, 'error' => $errMsg[$code] ?? 'Error desconocido al subir.']);
    exit;
}

$file     = $_FILES['foto'];
// Límite generoso: el cliente ya comprimió, pero si viene sin comprimir aceptamos hasta 15MB
$maxBytes = 15 * 1024 * 1024;

if ($file['size'] > $maxBytes) {
    echo json_encode(['success' => false, 'error' => 'El archivo supera 15 MB. Usa la opcion de enviar desde galeria con una foto de menor resolucion.']);
    exit;
}

// ── Validar tipo MIME real con finfo (no confiar en la extensión del cliente) ──
$finfo    = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

// Aceptar JPEG, PNG, WebP, y HEIC/HEIF (iOS — el navegador suele convertirlo a JPEG)
$tiposOk = [
    'image/jpeg'      => 'jpg',
    'image/jpg'       => 'jpg',
    'image/png'       => 'png',
    'image/webp'      => 'webp',
    'image/heic'      => 'jpg', // iOS: redimensionar con GD
    'image/heif'      => 'jpg',
];

if (!array_key_exists($mimeType, $tiposOk)) {
    echo json_encode(['success' => false,
        'error' => 'Formato no soportado (' . $mimeType . '). Usa JPG, PNG o WebP.']);
    exit;
}

$ext       = $tiposOk[$mimeType];
$uploadDir = __DIR__ . '/../../uploads/activos/';

// Crear directorio si no existe (con protección .htaccess)
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
    // Archivo .htaccess para evitar ejecución de scripts en la carpeta de uploads
    file_put_contents($uploadDir . '.htaccess',
        "# Bloquear ejecucion de scripts en directorio de uploads\n" .
        "<FilesMatch \"\.(php|php3|php4|php5|phtml|pl|py|cgi)$\">\n" .
        "    Order allow,deny\n    Deny from all\n</FilesMatch>\n"
    );
}

$nombreArchivo = 'activo_' . $activo_id . '_' . time() . '.' . $ext;
$rutaDestino   = $uploadDir . $nombreArchivo;
$urlRelativa   = 'uploads/activos/' . $nombreArchivo;

// ── Redimensionado server-side como respaldo si llegó sin comprimir ──────────
// El JS ya debe haber comprimido, pero si falló (Android sin Canvas, formatos raros),
// PHP redimensiona con GD para no almacenar fotos de 15 MB.
$necesitaResize = $file['size'] > 2 * 1024 * 1024; // > 2MB = probablemente sin comprimir

if ($necesitaResize && extension_loaded('gd')) {
    // Crear imagen GD según el tipo MIME
    $imgSrc = match ($mimeType) {
        'image/jpeg', 'image/jpg' => @imagecreatefromjpeg($file['tmp_name']),
        'image/png'               => @imagecreatefrompng($file['tmp_name']),
        'image/webp'              => @imagecreatefromwebp($file['tmp_name']),
        default                   => false,
    };

    if ($imgSrc) {
        $w = imagesx($imgSrc);
        $h = imagesy($imgSrc);
        $maxPx = 1400;

        // Redimensionar si supera maxPx
        if ($w > $maxPx || $h > $maxPx) {
            if ($w >= $h) { $nw = $maxPx; $nh = (int)round($h * $maxPx / $w); }
            else          { $nh = $maxPx; $nw = (int)round($w * $maxPx / $h); }
            $imgDst = imagecreatetruecolor($nw, $nh);
            imagecopyresampled($imgDst, $imgSrc, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagedestroy($imgSrc);
            $imgSrc = $imgDst;
        }

        // Corregir orientación EXIF si exif_read_data está disponible
        if (function_exists('exif_read_data') && in_array($mimeType, ['image/jpeg','image/jpg'])) {
            $exif = @exif_read_data($file['tmp_name']);
            $ori  = $exif['Orientation'] ?? 1;
            $imgSrc = match ((int)$ori) {
                3       => imagerotate($imgSrc, 180, 0),
                6       => imagerotate($imgSrc, -90, 0),
                8       => imagerotate($imgSrc,  90, 0),
                default => $imgSrc,
            };
        }

        // Guardar como JPEG comprimido
        $ext            = 'jpg';
        $nombreArchivo  = 'activo_' . $activo_id . '_' . time() . '.jpg';
        $rutaDestino    = $uploadDir . $nombreArchivo;
        $urlRelativa    = 'uploads/activos/' . $nombreArchivo;

        imagejpeg($imgSrc, $rutaDestino, 85);
        imagedestroy($imgSrc);

    } else {
        // GD no pudo abrir la imagen — mover el archivo sin procesar
        if (!move_uploaded_file($file['tmp_name'], $rutaDestino)) {
            echo json_encode(['success' => false, 'error' => 'Error al guardar la imagen.']);
            exit;
        }
    }
} else {
    // Archivo ya comprimido (< 2MB): mover directamente sin procesar
    if (!move_uploaded_file($file['tmp_name'], $rutaDestino)) {
        echo json_encode(['success' => false, 'error' => 'Error al guardar la imagen en el servidor.']);
        exit;
    }
}

// ── Eliminar foto anterior si existía ────────────────────────────────────────
$prev = db()->prepare('SELECT foto_url FROM activos WHERE id = ?');
$prev->execute([$activo_id]);
$anterior = $prev->fetchColumn();
if ($anterior && file_exists(__DIR__ . '/../../' . $anterior)) {
    @unlink(__DIR__ . '/../../' . $anterior);
}

// ── Actualizar foto_url en la base de datos ───────────────────────────────────
$uid = (int)($_SESSION['usuario_id'] ?? 0);
db()->prepare('UPDATE activos SET foto_url = ?, updated_by = ? WHERE id = ?')
    ->execute([$urlRelativa, $uid, $activo_id]);

require_once __DIR__ . '/../../app/helpers/AuditoriaHelper.php';
log_registrar('activos', $activo_id, 'foto_url', $anterior ?: null, $urlRelativa, 'UPDATE');

echo json_encode([
    'success' => true,
    'url'     => APP_BASE . '/' . $urlRelativa,
    'size_kb' => round(filesize($rutaDestino) / 1024),
]);
