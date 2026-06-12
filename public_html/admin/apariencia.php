<?php
/**
 * admin/apariencia.php — Configuración de apariencia y datos del negocio.
 * Permite cambiar: logo, nombre, colores, tipografía y bordes.
 * Los cambios se guardan en configuracion_app y se inyectan via nav.php.
 */
require_once __DIR__ . '/../app/middleware/auth_check.php';

$nav_activo = 'admin';
$nav_sub    = 'apariencia';

if (!in_array($_SESSION['usuario_rol'] ?? '', ['superadmin','admin'], true)) {
    http_response_code(403); include __DIR__ . '/../app/views/errors/403.php'; exit;
}

// ── Cargar configuración actual ───────────────────────────────────────────────
$cfg = [];
try {
    $cfg = db()->query(
        "SELECT clave, valor FROM configuracion_app"
    )->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) { /* tabla puede no existir si migración 016 no se aplicó */ }

$v = [
    'nombre_negocio'   => $cfg['nombre_negocio']   ?? 'ClanDestino',
    'logo_url'         => $cfg['logo_url']         ?? '',
    'logo_url_login'   => $cfg['logo_url_login']   ?? '',
    'theme_brand'      => $cfg['theme_brand']      ?? '#e94f37',
    'theme_dark'       => $cfg['theme_dark']       ?? '#111827',
    'theme_font'       => $cfg['theme_font']       ?? 'system-ui, -apple-system, sans-serif',
    'font_heading'     => $cfg['font_heading']     ?? 'system-ui, -apple-system, sans-serif',
    'theme_radius'     => (int)($cfg['theme_radius']     ?? 12),
    'font_size_title'  => (int)($cfg['font_size_title']  ?? 22),
    'font_size_subtitle'=> (int)($cfg['font_size_subtitle']?? 15),
    'font_size_body'   => (int)($cfg['font_size_body']   ?? 13),
    'font_size_small'  => (int)($cfg['font_size_small']  ?? 11),
    'color_text'       => $cfg['color_text']       ?? '#111827',
    'color_text_sec'   => $cfg['color_text_sec']   ?? '#6b7280',
    'num_decimales'    => (int)($cfg['num_decimales']   ?? 2),
    'num_sep_miles'    => $cfg['num_sep_miles']   ?? '.',
    'num_sep_decimal'  => $cfg['num_sep_decimal'] ?? ',',
];

// Opciones de fuente (sin CDN — solo fuentes del sistema para garantizar disponibilidad)
$FUENTES = [
    'system-ui, -apple-system, sans-serif'            => 'Sistema (por defecto)',
    'Arial, Helvetica, sans-serif'                    => 'Arial',
    "'Segoe UI', Tahoma, Geneva, sans-serif"          => 'Segoe UI',
    "'Helvetica Neue', Helvetica, Arial, sans-serif"  => 'Helvetica Neue',
    'Georgia, "Times New Roman", serif'               => 'Georgia (serif)',
    "'Courier New', Courier, monospace"               => 'Courier (monoespaciada)',
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Apariencia — <?= APP_NAME ?></title>
    <style>
        :root { --brand:<?= htmlspecialchars($v['theme_brand']) ?>; --dark:<?= htmlspecialchars($v['theme_dark']) ?>; --g5:#6b7280; --g8:#d1d5db; --g9:#f3f4f6; --white:#fff; }
        *,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
        body { font-family:<?= htmlspecialchars($v['theme_font']) ?>; background:var(--g9); color:#111827; }
        .main { max-width:960px; margin:0 auto; padding:20px 14px 60px; display:grid; grid-template-columns:1fr 340px; gap:20px; }
        @media(max-width:720px){ .main { grid-template-columns:1fr; } }
        .page-title { font-size:22px; font-weight:800; margin-bottom:20px; grid-column:1/-1; }

        /* Tarjetas de sección */
        .card { background:var(--white); border:1px solid var(--g8); border-radius:12px; padding:20px; margin-bottom:16px; }
        .card-title { font-size:13px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--g5); margin-bottom:14px; }
        .fg { display:flex; flex-direction:column; gap:4px; margin-bottom:14px; }
        .fg label { font-size:12px; font-weight:600; color:var(--g5); }
        .fg input[type=text], .fg select { padding:9px 10px; border:1px solid var(--g8); border-radius:8px; font-size:14px; color:#111827; outline:none; width:100%; }
        .fg input:focus, .fg select:focus { border-color:var(--brand); }
        .fg .hint { font-size:11px; color:var(--g5); }
        .color-row { display:flex; align-items:center; gap:10px; }
        .color-row input[type=color] { width:48px; height:38px; padding:2px; border:1px solid var(--g8); border-radius:8px; cursor:pointer; }
        .color-row input[type=text] { flex:1; }
        .radius-row { display:flex; align-items:center; gap:12px; }
        .radius-row input[type=range] { flex:1; accent-color:var(--brand); }
        .radius-lbl { font-size:13px; font-weight:600; min-width:40px; }

        /* Logo */
        .logo-preview     { max-width:200px; max-height:80px;  object-fit:contain; border:1px solid var(--g8); border-radius:8px; padding:6px; display:none; }
        .logo-preview-v   { max-width:120px; max-height:120px; object-fit:contain; border:1px solid var(--g8); border-radius:8px; padding:6px; display:none; }
        .logo-preview.show, .logo-preview-v.show { display:block; }

        /* Botón guardar */
        .btn-save { width:100%; padding:12px; background:var(--brand); color:#fff; border:none; border-radius:10px; font-size:14px; font-weight:700; cursor:pointer; margin-top:4px; }
        .btn-save:hover { filter:brightness(.9); }
        .btn-sec { padding:8px 16px; background:var(--g9); color:#111827; border:1px solid var(--g8); border-radius:8px; font-size:13px; font-weight:600; cursor:pointer; }

        /* Preview sticky */
        .preview-panel { position:sticky; top:70px; }
        .preview-card { background:var(--white); border:1px solid var(--g8); border-radius:12px; overflow:hidden; }
        .preview-title { font-size:12px; font-weight:700; color:var(--g5); text-transform:uppercase; letter-spacing:.5px; padding:12px 14px; border-bottom:1px solid var(--g8); }
        /* Preview del nav */
        .prev-nav { height:46px; display:flex; align-items:center; padding:0 12px; gap:8px; }
        .prev-brand { font-size:15px; font-weight:800; color:#fff; }
        .prev-brand span { color:var(--brand); }
        .prev-link { font-size:11px; font-weight:600; padding:5px 8px; border-radius:6px; color:#9ca3af; }
        .prev-link.act { background:var(--brand); color:#fff; }
        .prev-body { padding:16px; }
        .prev-card { background:var(--g9); border-radius:var(--r,8px); padding:12px; margin-bottom:10px; }
        .prev-btn { display:inline-block; padding:8px 16px; background:var(--brand); color:#fff; border-radius:var(--r,8px); font-size:13px; font-weight:700; }

        /* Toast */
        .toast { position:fixed; bottom:20px; left:50%; transform:translateX(-50%) translateY(20px); padding:10px 20px; border-radius:24px; font-size:14px; font-weight:600; opacity:0; transition:.25s; z-index:999; pointer-events:none; }
        .toast.on  { opacity:1; transform:translateX(-50%) translateY(0); }
        .toast-ok  { background:#065f46; color:#d1fae5; }
        .toast-err { background:#991b1b; color:#fee2e2; }

        /* ════════════════════════════════════════════════════════════════
           RESPONSIVE — ADMIN APARIENCIA
           ════════════════════════════════════════════════════════════════ */
        /* xs: < 480px */
        @media (max-width:479px) {
            /* El .main ya pasa a 1 col a ≤720px; aquí ajustamos padding */
            .main { padding:12px 10px 40px !important; }
            .page-title { font-size:18px; }
            .card { padding:14px !important; }
            .btn-save { min-height:44px; }
            /* Ocultar panel de preview (ocupa demasiado espacio en xs) */
            .preview-panel { display:none !important; }
            /* Color picker más fácil de tocar */
            .color-row input[type=color] { width:56px; height:44px; }
            .radius-row input[type=range] { min-height:44px; }
        }
        /* sm: 480-719px (ya manejado por el @media 720px → 1col) */
        @media (min-width:480px) and (max-width:719px) {
            .main { gap:14px !important; }
        }
        /* ≥1600px: dar más ancho al layout y mostrar preview */
        @media (min-width:1600px) {
            .main { max-width:1200px !important; grid-template-columns:1fr 400px !important; }
            .card { padding:24px !important; }
            .fg input[type=text], .fg select { font-size:15px !important; padding:11px 12px !important; }
        }
        /* TV ≥1920px */
        @media (min-width:1920px) {
            .main { max-width:1440px !important; grid-template-columns:1fr 480px !important; }
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/../app/views/nav.php'; ?>

<div style="max-width:960px;margin:0 auto;padding:12px 14px 0">
    <h1 style="font-size:22px;font-weight:800;margin-bottom:20px">Apariencia y Negocio</h1>
</div>

<div class="main">

    <!-- ── Columna principal ─────────────────────────────────────────── -->
    <div>

        <!-- Datos del negocio -->
        <div class="card">
            <div class="card-title">Datos del negocio</div>
            <div class="fg">
                <label>Nombre del negocio</label>
                <input type="text" id="ap-nombre" value="<?= htmlspecialchars($v['nombre_negocio']) ?>" maxlength="60"
                       oninput="prevNombre(this.value)">
                <span class="hint">Aparece en el menú principal y el título de las páginas.</span>
            </div>
            <!-- Logo horizontal (barra de navegación) -->
            <div class="fg">
                <label>Logo del menú (horizontal)</label>
                <?php if ($v['logo_url']): ?>
                <img src="<?= APP_BASE . '/' . htmlspecialchars($v['logo_url']) ?>"
                     class="logo-preview show" id="logo-preview" alt="Logo nav actual">
                <p style="font-size:11px;color:var(--green);margin-top:4px">
                    Guardado: <code><?= htmlspecialchars($v['logo_url']) ?></code>
                </p>
                <?php else: ?>
                <img class="logo-preview" id="logo-preview" alt="">
                <p style="font-size:11px;color:var(--g5);margin-top:4px">Sin logo guardado aún.</p>
                <?php endif; ?>
                <!-- Opción 1: subir archivo -->
                <input type="file" id="ap-logo-file" accept="image/png,image/jpeg,image/svg+xml,image/webp"
                       onchange="prevLogo('logo-preview', this)" style="margin-top:8px">
                <span class="hint">PNG, JPG, SVG o WebP · máx 1 MB · altura recomendada 64px.</span>
                <!-- Opción 2: ingresar ruta directamente (si el archivo ya está en el servidor) -->
                <label style="margin-top:10px">O ingresa la ruta del archivo (ej: uploads/logos/mi-logo.png)</label>
                <input type="text" id="ap-logo-ruta" placeholder="uploads/logos/nombre-archivo.png"
                       value="<?= htmlspecialchars($v['logo_url']) ?>">
                <?php if ($v['logo_url']): ?>
                <button class="btn-sec" style="margin-top:6px;width:fit-content"
                        onclick="quitarLogo('logo-quitar','logo-preview','ap-logo-file');document.getElementById('ap-logo-ruta').value=''">
                    Quitar logo del menú
                </button>
                <?php endif; ?>
            </div>

            <!-- Logo vertical (página de login) -->
            <div class="fg">
                <label>Logo de la página de acceso (vertical / cuadrado)</label>
                <?php if ($v['logo_url_login']): ?>
                <img src="<?= APP_BASE . '/' . htmlspecialchars($v['logo_url_login']) ?>"
                     class="logo-preview-v show" id="logo-preview-login" alt="Logo login actual">
                <p style="font-size:11px;color:var(--green);margin-top:4px">
                    Guardado: <code><?= htmlspecialchars($v['logo_url_login']) ?></code>
                </p>
                <?php else: ?>
                <img class="logo-preview-v" id="logo-preview-login" alt="">
                <p style="font-size:11px;color:var(--g5);margin-top:4px">Sin logo guardado aún.</p>
                <?php endif; ?>
                <!-- Opción 1: subir archivo -->
                <input type="file" id="ap-logo-login-file" accept="image/png,image/jpeg,image/svg+xml,image/webp"
                       onchange="prevLogo('logo-preview-login', this)" style="margin-top:8px">
                <span class="hint">Formato vertical o cuadrado · máx 1 MB.</span>
                <!-- Opción 2: ingresar ruta directamente -->
                <label style="margin-top:10px">O ingresa la ruta del archivo (ej: uploads/logos/mi-logo.png)</label>
                <input type="text" id="ap-logo-login-ruta" placeholder="uploads/logos/nombre-archivo.png"
                       value="<?= htmlspecialchars($v['logo_url_login']) ?>">
                <?php if ($v['logo_url_login']): ?>
                <button class="btn-sec" style="margin-top:6px;width:fit-content"
                        onclick="quitarLogo('logo-login-quitar','logo-preview-login','ap-logo-login-file');document.getElementById('ap-logo-login-ruta').value=''">
                    Quitar logo del login
                </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Colores -->
        <div class="card">
            <div class="card-title">Colores</div>
            <div class="fg">
                <label>Color principal (brand)</label>
                <div class="color-row">
                    <input type="color" id="ap-brand-picker" value="<?= htmlspecialchars($v['theme_brand']) ?>"
                           oninput="syncColor('brand',this.value)">
                    <input type="text"  id="ap-brand-txt"    value="<?= htmlspecialchars($v['theme_brand']) ?>"
                           oninput="syncColor('brand',this.value)" maxlength="7" placeholder="#e94f37">
                </div>
                <span class="hint">Botones, badges activos, barra de cobro, acentos.</span>
            </div>
            <div class="fg">
                <label>Color de fondo del menú</label>
                <div class="color-row">
                    <input type="color" id="ap-dark-picker" value="<?= htmlspecialchars($v['theme_dark']) ?>"
                           oninput="syncColor('dark',this.value)">
                    <input type="text"  id="ap-dark-txt"    value="<?= htmlspecialchars($v['theme_dark']) ?>"
                           oninput="syncColor('dark',this.value)" maxlength="7" placeholder="#111827">
                </div>
                <span class="hint">Fondo de la barra de navegación superior.</span>
            </div>
        </div>

        <!-- Tipografía -->
        <div class="card">
            <div class="card-title">Tipografía</div>

            <p style="font-size:12px;color:var(--g5);margin-bottom:14px">
                Los cambios aplican a todo el sistema: títulos, tablas, formularios, menú y tarjetas.
            </p>

            <!-- Fuentes -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <div class="fg">
                    <label>Fuente del cuerpo (textos, tablas)</label>
                    <select id="ap-font" onchange="prevFuente(this.value)">
                        <?php foreach ($FUENTES as $val => $lbl): ?>
                        <option value="<?= htmlspecialchars($val) ?>"
                                <?= $v['theme_font'] === $val ? 'selected' : '' ?>>
                            <?= htmlspecialchars($lbl) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fg">
                    <label>Fuente de encabezados (h1, títulos)</label>
                    <select id="ap-font-heading" onchange="prevFuente(this.value)">
                        <?php foreach ($FUENTES as $val => $lbl): ?>
                        <option value="<?= htmlspecialchars($val) ?>"
                                <?= $v['font_heading'] === $val ? 'selected' : '' ?>>
                            <?= htmlspecialchars($lbl) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <span class="hint" style="display:block;margin-bottom:14px">Fuentes del sistema — no requieren internet.</span>

            <!-- Tamaños -->
            <p style="font-size:11px;font-weight:700;color:var(--g5);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px">Tamaños de fuente (px)</p>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">

                <div class="fg">
                    <label>Títulos de página (h1, KPI grandes)</label>
                    <div class="radius-row">
                        <input type="range" id="ap-fs-title" min="14" max="40" step="1"
                               value="<?= (int)$v['font_size_title'] ?>"
                               oninput="prevTamanio('fs-title',this.value)">
                        <span class="radius-lbl" id="lbl-fs-title"><?= (int)$v['font_size_title'] ?>px</span>
                    </div>
                </div>

                <div class="fg">
                    <label>Subtítulos (tarjetas, secciones)</label>
                    <div class="radius-row">
                        <input type="range" id="ap-fs-subtitle" min="10" max="24" step="1"
                               value="<?= (int)$v['font_size_subtitle'] ?>"
                               oninput="prevTamanio('fs-subtitle',this.value)">
                        <span class="radius-lbl" id="lbl-fs-subtitle"><?= (int)$v['font_size_subtitle'] ?>px</span>
                    </div>
                </div>

                <div class="fg">
                    <label>Texto del cuerpo (tablas, párrafos)</label>
                    <div class="radius-row">
                        <input type="range" id="ap-fs-body" min="10" max="20" step="1"
                               value="<?= (int)$v['font_size_body'] ?>"
                               oninput="prevTamanio('fs-body',this.value)">
                        <span class="radius-lbl" id="lbl-fs-body"><?= (int)$v['font_size_body'] ?>px</span>
                    </div>
                </div>

                <div class="fg">
                    <label>Texto pequeño (labels, encabezados tabla)</label>
                    <div class="radius-row">
                        <input type="range" id="ap-fs-small" min="8" max="16" step="1"
                               value="<?= (int)$v['font_size_small'] ?>"
                               oninput="prevTamanio('fs-small',this.value)">
                        <span class="radius-lbl" id="lbl-fs-small"><?= (int)$v['font_size_small'] ?>px</span>
                    </div>
                </div>
            </div>

            <!-- Colores de texto -->
            <p style="font-size:11px;font-weight:700;color:var(--g5);text-transform:uppercase;letter-spacing:.5px;margin:14px 0 10px">Colores de texto</p>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <div class="fg">
                    <label>Color principal (títulos, cuerpo)</label>
                    <div class="color-row">
                        <input type="color" id="ap-color-text-picker"
                               value="<?= htmlspecialchars($v['color_text']) ?>"
                               oninput="syncColorText('text',this.value)">
                        <input type="text"  id="ap-color-text-txt"
                               value="<?= htmlspecialchars($v['color_text']) ?>"
                               oninput="syncColorText('text',this.value)" maxlength="7" placeholder="#111827">
                    </div>
                </div>
                <div class="fg">
                    <label>Color secundario (labels, subtítulos)</label>
                    <div class="color-row">
                        <input type="color" id="ap-color-sec-picker"
                               value="<?= htmlspecialchars($v['color_text_sec']) ?>"
                               oninput="syncColorText('sec',this.value)">
                        <input type="text"  id="ap-color-sec-txt"
                               value="<?= htmlspecialchars($v['color_text_sec']) ?>"
                               oninput="syncColorText('sec',this.value)" maxlength="7" placeholder="#6b7280">
                    </div>
                </div>
            </div>
        </div>

        <!-- Bordes -->
        <div class="card">
            <div class="card-title">Bordes redondeados</div>
            <div class="fg">
                <label>Radio de bordes (px)</label>
                <div class="radius-row">
                    <input type="range" id="ap-radius" min="0" max="24" step="2"
                           value="<?= (int)$v['theme_radius'] ?>" oninput="prevRadius(this.value)">
                    <span class="radius-lbl" id="radius-lbl"><?= (int)$v['theme_radius'] ?>px</span>
                </div>
                <span class="hint">Aplica a tarjetas, modales y botones.</span>
            </div>
        </div>

        <!-- Formato de números -->
        <div class="card">
            <div class="card-title">Formato de números</div>
            <div class="fg">
                <label>Decimales para cantidades</label>
                <select id="ap-num-decimales">
                    <?php for ($i = 0; $i <= 4; $i++): ?>
                    <option value="<?= $i ?>" <?= $v['num_decimales'] === $i ? 'selected' : '' ?>><?= $i ?></option>
                    <?php endfor; ?>
                </select>
                <span class="hint">Aplica a cantidades (stock, presentaciones, equivalencias, costo por unidad). Los precios en pesos siempre se muestran con 0 decimales.</span>
            </div>
            <div class="fg">
                <label>Separador de miles</label>
                <select id="ap-num-sep-miles">
                    <option value="." <?= $v['num_sep_miles'] === '.' ? 'selected' : '' ?>>Punto (1.234)</option>
                    <option value="," <?= $v['num_sep_miles'] === ',' ? 'selected' : '' ?>>Coma (1,234)</option>
                    <option value=" " <?= $v['num_sep_miles'] === ' ' ? 'selected' : '' ?>>Espacio (1 234)</option>
                    <option value="'" <?= $v['num_sep_miles'] === "'" ? 'selected' : '' ?>>Apóstrofe (1'234)</option>
                </select>
            </div>
            <div class="fg">
                <label>Separador decimal</label>
                <select id="ap-num-sep-decimal">
                    <option value="," <?= $v['num_sep_decimal'] === ',' ? 'selected' : '' ?>>Coma (0,50)</option>
                    <option value="." <?= $v['num_sep_decimal'] === '.' ? 'selected' : '' ?>>Punto (0.50)</option>
                </select>
                <span class="hint">Los campos editables (inputs numéricos) siempre usan punto como separador decimal — limitación del navegador. Estos separadores solo afectan a textos, etiquetas y vistas previas de solo lectura.</span>
            </div>
        </div>

        <button class="btn-save" onclick="guardarApariencia()">Guardar cambios</button>
    </div>

    <!-- ── Panel de vista previa ──────────────────────────────────────── -->
    <div class="preview-panel">
        <div class="preview-card">
            <div class="preview-title">Vista previa</div>
            <!-- Mini nav simulado -->
            <div class="prev-nav" id="prev-nav" style="background:#111827">
                <span class="prev-brand" id="prev-brand">Clan<span id="prev-brand2">Destino</span></span>
                <span class="prev-link act" id="prev-link-act">Ventas</span>
                <span class="prev-link">Inventario</span>
            </div>
            <!-- Body simulado -->
            <div class="prev-body">
                <div class="prev-card" id="prev-card">
                    <div style="font-size:11px;color:var(--g5);text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px">Total mensual</div>
                    <div style="font-size:20px;font-weight:800;color:var(--brand)" id="prev-amount">$1,750,905</div>
                </div>
                <div>
                    <button class="prev-btn" id="prev-btn">+ Nuevo registro</button>
                </div>
                <p style="margin-top:14px;font-size:13px;line-height:1.5" id="prev-text">
                    Este es el aspecto que tendrán los textos del sistema con la fuente seleccionada.
                </p>
            </div>
        </div>
    </div>
</div>

<input type="hidden" id="csrf-tk" value="<?= htmlspecialchars(csrf_token()) ?>">
<div class="toast" id="toast"></div>

<script>
var csrf = function(){ return document.getElementById('csrf-tk').value; };
var _tt;
function toast(m,t){
    var el=document.getElementById('toast'); el.textContent=m;
    el.className='toast toast-'+t+' on';
    clearTimeout(_tt); _tt=setTimeout(function(){ el.classList.remove('on'); },3200);
}

/* ── Sincronizar picker de color con campo de texto ─────────────────── */
function syncColor(tipo, val){
    var clean = val.trim();
    if(tipo==='brand'){
        document.getElementById('ap-brand-picker').value = clean.startsWith('#')&&clean.length===7 ? clean : '#e94f37';
        document.getElementById('ap-brand-txt').value    = clean;
        if(/^#[0-9a-fA-F]{6}$/.test(clean)){
            document.documentElement.style.setProperty('--brand', clean);
            document.getElementById('prev-link-act').style.background = clean;
            document.getElementById('prev-amount').style.color         = clean;
            document.getElementById('prev-btn').style.background       = clean;
        }
    } else {
        document.getElementById('ap-dark-picker').value = clean.startsWith('#')&&clean.length===7 ? clean : '#111827';
        document.getElementById('ap-dark-txt').value    = clean;
        if(/^#[0-9a-fA-F]{6}$/.test(clean)){
            document.documentElement.style.setProperty('--dark', clean);
            document.getElementById('prev-nav').style.background = clean;
        }
    }
}

/* ── Preview del nombre del negocio ─────────────────────────────────── */
function prevNombre(val){
    var parts = val.trim().split(/\s+/);
    document.getElementById('prev-brand').childNodes[0].textContent = parts[0] || '';
    document.getElementById('prev-brand2').textContent = parts.slice(1).join(' ') || '';
}

/* ── Preview fuente ──────────────────────────────────────────────────── */
function prevFuente(val){
    document.getElementById('prev-text').style.fontFamily   = val;
    document.getElementById('prev-amount').style.fontFamily = val;
    document.getElementById('prev-btn').style.fontFamily    = val;
}

/* ── Preview tamaño de fuente ────────────────────────────────────────── */
function prevTamanio(key, val){
    document.getElementById('lbl-' + key).textContent = val + 'px';
    var map = {
        'fs-title':    ['prev-amount'],
        'fs-subtitle': ['prev-card'],
        'fs-body':     ['prev-text'],
        'fs-small':    [],
    };
    if(key === 'fs-title'   ) document.getElementById('prev-amount').style.fontSize = val + 'px';
    if(key === 'fs-body'    ) document.getElementById('prev-text').style.fontSize   = val + 'px';
}

/* ── Sincronizar colores de texto ────────────────────────────────────── */
function syncColorText(tipo, val){
    var clean = val.trim();
    var valid = /^#[0-9a-fA-F]{6}$/.test(clean);
    if(tipo === 'text'){
        document.getElementById('ap-color-text-picker').value = valid ? clean : '#111827';
        document.getElementById('ap-color-text-txt').value    = clean;
        if(valid) document.getElementById('prev-text').style.color    = clean;
        if(valid) document.getElementById('prev-amount').style.color  = clean;
    } else {
        document.getElementById('ap-color-sec-picker').value = valid ? clean : '#6b7280';
        document.getElementById('ap-color-sec-txt').value    = clean;
    }
}

/* ── Preview del radio de bordes ─────────────────────────────────────── */
function prevRadius(val){
    document.getElementById('radius-lbl').textContent = val + 'px';
    document.getElementById('prev-card').style.borderRadius = val + 'px';
    document.getElementById('prev-btn').style.borderRadius  = val + 'px';
}

/* ── Preview genérico de logo ────────────────────────────────────────── */
function prevLogo(previewId, input){
    if(!input.files||!input.files[0]) return;
    var f = input.files[0];
    if(f.size > 1048576){ toast('El logo debe pesar menos de 1 MB.','err'); input.value=''; return; }
    var reader = new FileReader();
    reader.onload = function(e){
        var img = document.getElementById(previewId);
        img.src = e.target.result;
        img.classList.add('show');
    };
    reader.readAsDataURL(f);
}

function quitarLogo(flagId, previewId, fileId){
    var img  = document.getElementById(previewId);
    var flag = document.getElementById(flagId);
    if(img)  { img.src=''; img.classList.remove('show'); }
    var inp  = document.getElementById(fileId);
    if(inp)  inp.value='';
    if(flag) flag.value='1';
}

/* ── Guardar todo ────────────────────────────────────────────────────── */
async function guardarApariencia(){
    var fd = new FormData();
    fd.append('csrf_token',      csrf());
    fd.append('nombre_negocio',   document.getElementById('ap-nombre').value.trim());
    fd.append('theme_brand',      document.getElementById('ap-brand-txt').value.trim());
    fd.append('theme_dark',       document.getElementById('ap-dark-txt').value.trim());
    fd.append('theme_font',       document.getElementById('ap-font').value);
    fd.append('font_heading',     document.getElementById('ap-font-heading').value);
    fd.append('theme_radius',     document.getElementById('ap-radius').value);
    fd.append('font_size_title',  document.getElementById('ap-fs-title').value);
    fd.append('font_size_subtitle',document.getElementById('ap-fs-subtitle').value);
    fd.append('font_size_body',   document.getElementById('ap-fs-body').value);
    fd.append('font_size_small',  document.getElementById('ap-fs-small').value);
    fd.append('color_text',       document.getElementById('ap-color-text-txt').value.trim());
    fd.append('color_text_sec',   document.getElementById('ap-color-sec-txt').value.trim());
    fd.append('num_decimales',    document.getElementById('ap-num-decimales').value);
    fd.append('num_sep_miles',    document.getElementById('ap-num-sep-miles').value);
    fd.append('num_sep_decimal',  document.getElementById('ap-num-sep-decimal').value);
    fd.append('logo_quitar',       document.getElementById('logo-quitar').value);
    fd.append('logo_login_quitar', document.getElementById('logo-login-quitar').value);

    // Si hay ruta manual, tiene prioridad sobre el upload
    var logoRuta = document.getElementById('ap-logo-ruta').value.trim();
    if(logoRuta) fd.append('logo_ruta_manual', logoRuta);

    var logoFile = document.getElementById('ap-logo-file').files[0];
    if(logoFile && !logoRuta) fd.append('logo_file', logoFile);

    var logoLoginRuta = document.getElementById('ap-logo-login-ruta').value.trim();
    if(logoLoginRuta) fd.append('logo_login_ruta_manual', logoLoginRuta);

    var logoLoginFile = document.getElementById('ap-logo-login-file').files[0];
    if(logoLoginFile && !logoLoginRuta) fd.append('logo_login_file', logoLoginFile);

    try {
        var r=await fetch('api/guardar_apariencia.php',{method:'POST',body:fd});
        var d=await r.json();
        if(d.success){ toast('Cambios guardados. Recargando…','ok'); setTimeout(function(){ location.reload(); },1200); }
        else toast(d.error||'Error al guardar.','err');
    } catch(e){ toast('Error de conexión.','err'); }
}
</script>
<!-- Flags para quitar logos -->
<input type="hidden" id="logo-quitar"       value="0">
<input type="hidden" id="logo-login-quitar" value="0">
</body>
</html>
