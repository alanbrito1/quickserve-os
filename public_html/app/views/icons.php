<?php
/**
 * app/views/icons.php — Iconos SVG unificados para todos los módulos.
 * Basados en Heroicons outline (MIT). Incluido automáticamente por nav.php.
 *
 * Uso en HTML: <?= IC_EDIT ?>  dentro del botón.
 * Cada icono es 16×16, hereda color del padre via stroke="currentColor".
 * Añadir clase .ic al botón para el tamaño y alineación correctos.
 */

if (!defined('IC_EDIT')) {

    define('_IC', 'viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16" aria-hidden="true"');

    /* — CRUD ─────────────────────────────────────────────────────────────── */

    /* Lápiz — Editar / Ajustar */
    define('IC_EDIT', '<svg ' . _IC . '><path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>');

    /* Páginas superpuestas — Duplicar / Copiar */
    define('IC_COPY', '<svg ' . _IC . '><path d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>');

    /* Papelera — Eliminar */
    define('IC_TRASH', '<svg ' . _IC . '><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/></svg>');

    /* — Visualización ────────────────────────────────────────────────────── */

    /* Ojo — Ver detalle */
    define('IC_EYE', '<svg ' . _IC . '><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>');

    /* — Estados / Toggle ─────────────────────────────────────────────────── */

    /* Check en círculo — Marcar pagado / Activar */
    define('IC_CHECK', '<svg ' . _IC . '><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>');

    /* X en círculo — Anular */
    define('IC_XMARK', '<svg ' . _IC . '><path d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>');

    /* Pausa en círculo — Pausar / Desactivar / Dar de baja */
    define('IC_PAUSE', '<svg ' . _IC . '><path d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>');

    /* Play en círculo — Activar / Reanudar */
    define('IC_PLAY', '<svg ' . _IC . '><path d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664zM21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>');

    /* — Especialidades ───────────────────────────────────────────────────── */

    /* Cámara — Subir / ver foto */
    define('IC_CAMERA', '<svg ' . _IC . '><path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/><circle cx="12" cy="13" r="4"/></svg>');

    /* Rayo — Generar nómina / acción rápida */
    define('IC_BOLT', '<svg ' . _IC . '><path d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>');

    /* Chevron abajo — Expandir sección */
    define('IC_CHEV', '<svg ' . _IC . '><polyline points="6 9 12 15 18 9"/></svg>');

    /* Fusionar / Merge — Combinar dos registros en uno */
    define('IC_MERGE', '<svg ' . _IC . '><path d="M5 8V5m0 0H2m3 0 9 9m5-5v3m0 0h3m-3 0-9-9"/></svg>');

    /* Regalo / Obsequio — Regalar producto terminado */
    define('IC_GIFT', '<svg ' . _IC . '><polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><line x1="12" y1="22" x2="12" y2="7"/><path d="M12 7H7.5a2.5 2.5 0 010-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 000-5C13 2 12 7 12 7z"/></svg>');

    /* Usuario / Persona — Clientes, empleados */
    define('IC_USER', '<svg ' . _IC . '><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>');

    /* Usuarios / Grupo — Lista de clientes */
    define('IC_USERS', '<svg ' . _IC . '><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>');

    /* Recibo / Estado de cuenta — Documento de transacciones con check */
    define('IC_RECEIPT', '<svg ' . _IC . '><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>');

    /* Imprimir — Botón de impresión */
    define('IC_PRINT', '<svg ' . _IC . '><path d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>');

}
