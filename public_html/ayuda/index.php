<?php
/**
 * ayuda/index.php — Centro de ayuda y documentación completa del sistema.
 *
 * Documenta cada módulo, función, integración y fórmula matemática del ERP.
 * Visible para todos los usuarios autenticados sin importar permisos.
 */
require_once __DIR__ . '/../app/middleware/auth_check.php';
$nav_activo = 'ayuda';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ayuda — <?= APP_NAME ?></title>
    <style>
        :root { --brand:#e94f37; --dark:#111827; --g2:#374151; --g5:#6b7280; --g8:#d1d5db; --g9:#f3f4f6; --white:#fff; --green:#059669; }
        *,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
        body { font-family:system-ui,-apple-system,sans-serif; background:var(--g9); color:var(--dark); }

        /* ── Layout dos columnas ────────────────────────────────────────── */
        .layout    { display:flex; max-width:1200px; margin:0 auto; padding:20px 14px 60px; gap:24px; align-items:flex-start; }
        .sidebar   { width:220px; flex-shrink:0; position:sticky; top:70px; }
        .content   { flex:1; min-width:0; }
        @media(max-width:720px) { .layout { flex-direction:column; } .sidebar { width:100%; position:static; } }

        /* ── Sidebar ───────────────────────────────────────────────────── */
        .side-search { width:100%; padding:8px 10px; border:1px solid var(--g8); border-radius:8px; font-size:13px; outline:none; margin-bottom:10px; }
        .side-search:focus { border-color:var(--brand); }
        .side-group    { margin-bottom:6px; }
        .side-group-lbl { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.6px; color:var(--g5); padding:4px 8px; }
        .side-link {
            display:block; padding:7px 10px; font-size:13px; font-weight:500;
            color:var(--g2); text-decoration:none; border-radius:8px; transition:background .1s;
            white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
        }
        .side-link:hover { background:var(--g8); color:var(--dark); }
        .side-link.active { background:var(--brand); color:#fff; font-weight:600; }

        /* ── Buscador de contenido ─────────────────────────────────────── */
        .search-bar {
            display:flex; align-items:center; gap:10px; background:var(--white);
            border:1px solid var(--g8); border-radius:12px; padding:12px 16px; margin-bottom:20px;
        }
        .search-bar input {
            flex:1; border:none; outline:none; font-size:15px; color:var(--dark);
        }
        .search-bar .icon { color:var(--g5); font-size:18px; }
        .no-results { display:none; text-align:center; padding:40px; color:var(--g5); }

        /* ── Secciones ─────────────────────────────────────────────────── */
        .section { background:var(--white); border:1px solid var(--g8); border-radius:14px; padding:24px; margin-bottom:20px; }
        .section-hdr { display:flex; align-items:center; gap:10px; margin-bottom:16px; padding-bottom:12px; border-bottom:2px solid var(--g9); }
        .section-icon { width:40px; height:40px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:20px; flex-shrink:0; }
        .section-title { font-size:18px; font-weight:800; }
        .section-badge { font-size:11px; font-weight:600; padding:2px 8px; border-radius:20px; background:var(--g9); color:var(--g5); }

        /* ── Subsecciones ──────────────────────────────────────────────── */
        .sub-title { font-size:13px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--g5); margin:16px 0 8px; }
        p { font-size:14px; line-height:1.6; color:var(--g2); margin-bottom:8px; }
        ul,ol { padding-left:20px; margin-bottom:10px; }
        li { font-size:14px; line-height:1.7; color:var(--g2); }

        /* ── Fórmulas matemáticas ──────────────────────────────────────── */
        .formula-block {
            background:#0f172a; color:#e2e8f0; border-radius:10px;
            padding:16px 20px; margin:12px 0; font-family:'Courier New',monospace;
            font-size:13px; line-height:1.8; overflow-x:auto;
        }
        .formula-block .comment { color:#64748b; }
        .formula-block .var     { color:#7dd3fc; }
        .formula-block .op      { color:#f472b6; }
        .formula-block .result  { color:#86efac; font-weight:700; }
        .formula-block .title   { color:#fbbf24; font-weight:700; font-size:12px; text-transform:uppercase; letter-spacing:.5px; margin-bottom:6px; display:block; }

        /* ── Tabla de datos ────────────────────────────────────────────── */
        .data-table { width:100%; border-collapse:collapse; font-size:13px; margin:8px 0; }
        .data-table th { background:var(--g9); padding:8px 12px; text-align:left; font-weight:700; font-size:11px; text-transform:uppercase; letter-spacing:.4px; color:var(--g5); }
        .data-table td { padding:8px 12px; border-top:1px solid var(--g9); vertical-align:top; }
        .data-table td:first-child { font-weight:600; white-space:nowrap; }
        .data-table tr:hover td { background:#fafafa; }

        /* ── Alerta / tip ──────────────────────────────────────────────── */
        .tip  { background:#eff6ff; border-left:4px solid #3b82f6; padding:10px 14px; border-radius:0 8px 8px 0; margin:10px 0; font-size:13px; color:var(--g2); }
        .warn { background:#fff7ed; border-left:4px solid #f97316; padding:10px 14px; border-radius:0 8px 8px 0; margin:10px 0; font-size:13px; color:var(--g2); }
        .ok   { background:#f0fdf4; border-left:4px solid #22c55e; padding:10px 14px; border-radius:0 8px 8px 0; margin:10px 0; font-size:13px; color:var(--g2); }

        /* ── Integración badge ─────────────────────────────────────────── */
        .int-list { display:flex; flex-wrap:wrap; gap:6px; margin:8px 0; }
        .int-badge { font-size:11px; font-weight:600; padding:3px 10px; border-radius:20px; background:var(--g9); color:var(--g2); }
        .int-badge.arrow { background:#fef2f0; color:var(--brand); }

        /* ── Toast ─────────────────────────────────────────────────────── */
        .copy-btn { font-size:11px; padding:2px 8px; border:1px solid var(--g8); background:var(--white); border-radius:4px; cursor:pointer; color:var(--g5); margin-left:8px; }
        .copy-btn:hover { border-color:var(--brand); color:var(--brand); }

        /* ════════════════════════════════════════════════════════════════
           RESPONSIVE — AYUDA
           ════════════════════════════════════════════════════════════════ */
        /* Tablas de datos con scroll horizontal en todos los tamaños */
        .data-table-wrap { overflow-x:auto; -webkit-overflow-scrolling:touch; }
        /* Envolver todas las .data-table automáticamente via CSS */
        .section { overflow-x:hidden; }
        .data-table { min-width:400px; }

        /* xs: móvil vertical < 480px ─────────────────────────────────── */
        @media (max-width:479px) {
            /* Layout ya en columna gracias a @media 720px; ajustar padding */
            .layout { padding:12px 10px 40px; gap:14px; }

            /* Sidebar compacto: ocultar al inicio, mostrar buscador */
            .sidebar { width:100%; }
            .side-search { font-size:15px; padding:10px 12px; min-height:44px; }
            .side-link { min-height:44px; display:flex; align-items:center; font-size:14px; }

            /* Secciones compactas */
            .section { padding:14px; }
            .section-title { font-size:16px !important; }

            /* Fórmulas con scroll horizontal */
            .formula-block { font-size:12px; padding:12px 14px; }

            /* Tablas con scroll */
            .data-table { min-width:360px; font-size:12px; }
            .data-table th, .data-table td { padding:6px 8px; }

            /* Buscador de contenido */
            .search-bar { padding:10px 12px; }
            .search-bar input { font-size:14px; }

            /* Tips/warns con texto más pequeño */
            .tip, .warn, .ok { font-size:12px; padding:8px 12px; }

            p, li { font-size:13px; }
        }

        /* sm: 480-639px ──────────────────────────────────────────────── */
        @media (min-width:480px) and (max-width:639px) {
            .layout { padding:14px 12px 40px; }
            .side-link { min-height:40px; display:flex; align-items:center; }
            .section { padding:16px; }
        }

        /* md: 640-719px (layout aún en columna) ─────────────────────── */
        @media (min-width:640px) and (max-width:719px) {
            .layout { padding:16px 14px 50px; }
        }

        /* lg: 720-1023px (layout en dos columnas por @media 720px) ───── */
        @media (min-width:720px) and (max-width:1023px) {
            .layout { padding:16px 14px 50px; }
            .sidebar { width:200px; }
        }

        /* ≥1600px ────────────────────────────────────────────────────── */
        @media (min-width:1600px) {
            .layout { max-width:1500px; padding:28px 20px 80px; }
            .sidebar { width:260px; }
            .section { padding:28px 30px; }
            .section-title { font-size:20px !important; }
            p, li { font-size:15px; }
            .data-table { font-size:14px; }
            .data-table th, .data-table td { padding:10px 14px; }
            .formula-block { font-size:14px; }
        }

        /* TV ≥1920px ───────────────────────────────────────────────────── */
        @media (min-width:1920px) {
            .layout { max-width:1800px; }
            .sidebar { width:300px; }
            .section-title { font-size:22px !important; }
            p, li { font-size:16px; }
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/../app/views/nav.php'; ?>

<div class="layout">

    <!-- ── Sidebar de navegación ──────────────────────────────────────── -->
    <aside class="sidebar">
        <input type="text" class="side-search" id="side-search" placeholder="Filtrar secciones…" oninput="filtrarSidebar(this.value)">

        <div class="side-group">
            <div class="side-group-lbl">General</div>
            <a href="#sistema"    class="side-link active" onclick="activar(this)">Visión general</a>
            <a href="#flujos"     class="side-link" onclick="activar(this)">Flujos de datos</a>
            <a href="#formulas"   class="side-link" onclick="activar(this)">Fórmulas globales</a>
        </div>
        <div class="side-group">
            <div class="side-group-lbl">Principios</div>
            <a href="#precios-hist" class="side-link" onclick="activar(this)">Precios Históricos</a>
        </div>
        <div class="side-group">
            <div class="side-group-lbl">Módulos</div>
            <a href="#dashboard"  class="side-link" onclick="activar(this)">Dashboard</a>
            <a href="#clientes"   class="side-link" onclick="activar(this)">Clientes</a>
            <a href="#ventas"     class="side-link" onclick="activar(this)">Ventas / POS</a>
            <a href="#inventario" class="side-link" onclick="activar(this)">Inventario</a>
            <a href="#compras"    class="side-link" onclick="activar(this)">Compras</a>
            <a href="#proveedores"class="side-link" onclick="activar(this)">Proveedores</a>
            <a href="#productos"  class="side-link" onclick="activar(this)">Productos</a>
            <a href="#produccion" class="side-link" onclick="activar(this)">Producción</a>
            <a href="#nomina"     class="side-link" onclick="activar(this)">Nómina</a>
            <a href="#activos"    class="side-link" onclick="activar(this)">Activos</a>
            <a href="#costos"     class="side-link" onclick="activar(this)">Costos</a>
            <a href="#analisis"   class="side-link" onclick="activar(this)">Análisis y PE</a>
            <a href="#reportes"   class="side-link" onclick="activar(this)">Reportes</a>
            <a href="#admin"      class="side-link" onclick="activar(this)">Admin</a>
            <a href="#seguridad"  class="side-link" onclick="activar(this)">Seguridad</a>
            <a href="#pruebas"   class="side-link" onclick="activar(this)">Pruebas / Tests</a>
            <a href="#bd-tecnica" class="side-link" onclick="activar(this)">Base de Datos</a>
        </div>
    </aside>

    <!-- ── Contenido principal ─────────────────────────────────────────── -->
    <main class="content">

        <!-- Búsqueda de contenido -->
        <div class="search-bar">
            <span class="icon">&#128269;</span>
            <input type="text" id="busq-contenido" placeholder="Buscar en la ayuda… (Ej: depreciación, fiado, nómina)" oninput="buscarContenido(this.value)">
        </div>
        <div class="no-results" id="no-results">No se encontraron resultados para tu búsqueda.</div>

        <!-- ══════════════════════════════════════════════════════════════ -->
        <!--  SECCIÓN: PRINCIPIO DE PRECIOS HISTÓRICOS                     -->
        <!-- ══════════════════════════════════════════════════════════════ -->
        <div class="section" id="precios-hist">
            <div class="section-hdr">
                <div class="section-icon" style="background:#fef3c7">&#128176;</div>
                <div>
                    <div class="section-title">Principio: Precios y Costos Históricos</div>
                    <div class="section-badge">Invariante del sistema</div>
                </div>
            </div>
            <p>Cada transacción registra el precio vigente <strong>al momento de realizarse</strong>. Ese precio no cambia nunca, aunque el precio actual del insumo, el salario o el costo fijo varíe después.</p>
            <p>Los precios varían con el tiempo (inflación, cambio de proveedor, ajuste salarial). El sistema conserva la trazabilidad exacta de cuánto se pagó, cobró o registró en cada momento histórico.</p>

            <div class="tip"><strong>La única excepción:</strong> Una corrección por mala digitación sí puede modificar el registro. Pero es una excepción explícita, no una actualización de precio de mercado.</div>

            <div class="sub-title">¿Dónde está garantizado este principio?</div>
            <table class="data-table">
                <thead><tr><th>Tabla</th><th>Campo histórico</th><th>Garantía</th></tr></thead>
                <tbody>
                    <tr><td><code>venta_detalles</code></td><td><code>precio_unitario</code></td><td>Precio cobrado al cliente en esa venta. No cambia si <code>productos.precio_venta</code> sube o baja después.</td></tr>
                    <tr><td><code>compra_detalles</code></td><td><code>precio_unitario</code></td><td>Precio pagado al proveedor en esa compra. No cambia si <code>insumos.costo_actual</code> cambia.</td></tr>
                    <tr><td><code>produccion_lotes</code></td><td><code>costo_unitario</code></td><td>Snapshot del costo calculado al momento de producir. No cambia cuando los ingredientes suben.</td></tr>
                    <tr><td><code>nomina_liquidaciones</code></td><td>Todas las columnas de costo</td><td>Snapshot completo de la liquidación del mes. No cambia si el empleado tiene ajuste salarial posterior.</td></tr>
                    <tr><td><code>costos_indirectos</code></td><td>Cada fila = un período de vigencia</td><td>Cuando un costo cambia → crear fila nueva con nueva fecha de inicio, no editar la existente.</td></tr>
                </tbody>
            </table>

            <div class="sub-title">¿Qué sí cambia (y debe cambiar)?</div>
            <table class="data-table">
                <thead><tr><th>Campo</th><th>Qué representa</th><th>Efecto hacia atrás</th></tr></thead>
                <tbody>
                    <tr><td><code>insumos.costo_actual</code></td><td>Precio de referencia hoy</td><td>Ninguno — solo afecta el costo calculado de producciones futuras.</td></tr>
                    <tr><td><code>productos.costo_calculado</code></td><td>Costo estimado para producir hoy</td><td>Se recalcula automáticamente. Las tandas pasadas conservan su snapshot.</td></tr>
                    <tr><td><code>productos.precio_venta</code></td><td>Precio de venta hoy</td><td>Ninguno — ventas pasadas conservan su <code>precio_unitario</code>.</td></tr>
                    <tr><td><code>empleados.salario_base</code></td><td>Salario vigente</td><td>Ninguno — liquidaciones pasadas no se alteran.</td></tr>
                </tbody>
            </table>

            <div class="sub-title">Reporte de variación de precios</div>
            <p>El reporte <strong>"Variación de Precios y Costos"</strong> disponible en Reportes muestra cómo ha cambiado cada precio con el tiempo, con 4 secciones:</p>
            <table class="data-table">
                <thead><tr><th>Sección</th><th>Fuente</th><th>¿Qué muestra?</th></tr></thead>
                <tbody>
                    <tr><td>🧂 Insumos</td><td><code>compra_detalles</code></td><td>Precio pagado por compra por insumo, con % de variación respecto a la compra anterior.</td></tr>
                    <tr><td>🥪 Productos</td><td><code>venta_detalles</code> + <code>produccion_lotes</code></td><td>Precio de venta cobrado efectivamente y costo de producción por lote.</td></tr>
                    <tr><td>👤 Nómina</td><td><code>nomina_liquidaciones</code></td><td>Salario base y costo total por empleado por período liquidado.</td></tr>
                    <tr><td>💰 Costos Fijos</td><td><code>costos_indirectos</code></td><td>Valor mensual equivalente de cada concepto de costo y variación entre vigencias.</td></tr>
                </tbody>
            </table>

            <div class="ok"><strong>Buena práctica para costos fijos:</strong> Cuando un arriendo, servicio o costo operativo cambia de valor, no edites la fila existente. En cambio, pon una <strong>fecha de fin</strong> a la fila actual y crea una <strong>nueva fila</strong> con la nueva vigencia. Así el reporte puede mostrar el historial completo.</div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════ -->
        <!--  SECCIÓN: VISIÓN GENERAL                                      -->
        <!-- ══════════════════════════════════════════════════════════════ -->
        <div class="section" id="sistema">
            <div class="section-hdr">
                <div class="section-icon" style="background:#fef2f0">&#127829;</div>
                <div>
                    <div class="section-title">ClanDestino ERP — Visión General</div>
                    <div class="section-badge">v4.69 · Colombia</div>
                </div>
            </div>
            <p>Sistema de gestión empresarial para negocios de sándwiches. Controla ventas, inventario, producción, nómina, activos y costos desde un único panel adaptado a la legislación colombiana.</p>
            <div class="tip"><strong>Panel de alertas en el Dashboard:</strong> Al ingresar al sistema, la página de inicio muestra automáticamente alertas operativas si hay insumos con stock bajo, clientes con fiado pendiente o productos con stock por debajo del mínimo configurado. Cada alerta tiene un enlace directo al módulo correspondiente para actuar de inmediato.</div>

            <div class="sub-title">Módulos del sistema</div>
            <table class="data-table">
                <tr><th>Módulo</th><th>Función principal</th><th>Acceso</th></tr>
                <tr><td>Clientes</td><td>Gestión de clientes, fiado y fusión de duplicados</td><td>Personal con acceso a ventas</td></tr>
                <tr><td>Ventas / POS</td><td>Registrar ventas, descuento automático de inventario</td><td>Todo el personal</td></tr>
                <tr><td>Inventario</td><td>Ver y ajustar stock de insumos (materia prima)</td><td>Administración</td></tr>
                <tr><td>Compras</td><td>Registrar compras de insumos, actualiza costos automáticamente</td><td>Administración</td></tr>
                <tr><td>Proveedores</td><td>Directorio de proveedores vinculados a insumos, activos y compras</td><td>Administración</td></tr>
                <tr><td>Productos</td><td>Recetas, costo real por sándwich y margen de rentabilidad</td><td>Administración</td></tr>
                <tr><td>Producción</td><td>Registro diario de tandas producidas, descuenta insumos</td><td>Administración / Cocina</td></tr>
                <tr><td>Nómina</td><td>Liquidación mensual con prestaciones Colombia</td><td>RRHH / Administración</td></tr>
                <tr><td>Activos</td><td>Control de equipos con depreciación automática</td><td>Administración</td></tr>
                <tr><td>Costos</td><td>Panel de costos directos/indirectos con selector de período</td><td>Administración</td></tr>
                <tr><td>Reportes</td><td>6 tipos de reportes exportables a Excel (incluye Variación de Precios)</td><td>Administración</td></tr>
                <tr><td>Admin</td><td>Usuarios, permisos, apariencia, backup de BD</td><td>Superadmin / Admin</td></tr>
            </table>

            <div class="sub-title">Tecnología</div>
            <ul>
                <li><strong>Backend:</strong> PHP 8.4 con PDO (prepared statements — sin SQL injection)</li>
                <li><strong>Base de datos:</strong> MySQL 5.7+ con InnoDB y 7 triggers automáticos</li>
                <li><strong>Frontend:</strong> HTML + CSS + JavaScript puro (sin frameworks)</li>
                <li><strong>Seguridad:</strong> CSRF tokens, bcrypt passwords (costo 12), rate limiting en login</li>
                <li><strong>Hosting:</strong> cPanel compartido — compatible con PHP-FPM</li>
            </ul>
        </div>

        <!-- ══════════════════════════════════════════════════════════════ -->
        <!--  SECCIÓN: FLUJOS DE DATOS                                     -->
        <!-- ══════════════════════════════════════════════════════════════ -->
        <div class="section" id="flujos">
            <div class="section-hdr">
                <div class="section-icon" style="background:#eff6ff">&#128257;</div>
                <div><div class="section-title">Flujos de datos entre módulos</div></div>
            </div>

            <div class="sub-title">Flujo de producción y ventas</div>
            <div class="formula-block">
<span class="title">Flujo completo de un sándwich</span>
<span class="comment">1. COMPRA (módulo Compras)</span>
   Compra de insumos → <span class="var">insumos.costo_actual</span> se actualiza
                     → <span class="var">insumos.stock_actual</span> aumenta
                     → <span class="var">productos.costo_calculado</span> se recalcula automáticamente

<span class="comment">2. PRODUCCIÓN (módulo Producción)</span>
   Se producen N sándwiches → <span class="var">insumos.stock_actual</span> decrece (insumos gastados)
                            → <span class="var">productos.stock_disponible</span> aumenta en N

<span class="comment">3. VENTA (módulo Ventas / POS)</span>
   SI <span class="var">stock_disponible</span> ≥ cantidad vendida:
     → <span class="var">stock_disponible</span> decrece (from_stock = 1)
     → Los insumos NO se tocan (ya se descontaron al producir)
   SI NO hay stock terminado:
     → <span class="var">insumos.stock_actual</span> decrece directamente (from_stock = 0)
     → Modo "producción a demanda" (comportamiento por defecto)

<span class="comment">4. OBSEQUIO (módulo Ventas — método de pago 'obsequio')</span>
   Flujo de stock: idéntico al de una venta normal (descuenta stock_disponible o insumos)
   El precio real queda en <span class="var">ventas.total</span> para trazabilidad
   <span class="result">NO cuenta como ingreso</span> — excluido de todos los KPIs de ventas

<span class="comment">5. DESECHAR / REGALAR STOCK TERMINADO (módulo Productos o Producción)</span>
   INSERT en <span class="var">ajustes_stock</span> (tipo='desecho' o 'obsequio')
   UPDATE <span class="var">productos.stock_disponible</span> -= cantidad
   <span class="result">Los insumos NO se devuelven</span> — ya fueron consumidos al producir

<span class="comment">6. ANULAR VENTA</span>
   SI from_stock = 1 → restaura <span class="var">stock_disponible</span>
   SI from_stock = 0 → restaura <span class="var">insumos.stock_actual</span>
   SI es_combo = 1 AND combo_id IS NOT NULL → restaura <span class="var">insumos.stock_actual</span> de los extras del combo</div>

            <div class="sub-title">Flujo de costos hacia margen de producto</div>
            <div class="formula-block">
<span class="title">Cómo los costos alimentan el precio de costo</span>
Módulo Costos (costos_indirectos) → <span class="var">CostoIndirectoModel::total_mensual_activo()</span>
  ↓
Módulo Productos (index.php) → <span class="var">costo_fijo_u</span> = total_costos / produccion_estimada
  ↓
Margen por sándwich = <span class="var">precio_venta</span> - (<span class="var">costo_ing</span> + <span class="var">costo_fijo_u</span> + <span class="var">costo_deprec_u</span> + <span class="var">costo_rh_u</span>)

<span class="comment">Nota: Si no hay costos en el módulo Costos, se usa el valor de
configuracion_negocio.costos_fijos_mensuales como estimado.</span></div>

            <div class="sub-title">Flujo de proveedores</div>
            <div class="formula-block">
<span class="title">Un proveedor se vincula a:</span>
proveedores.id → <span class="var">insumos.proveedor_id</span>     (de qué proveedor viene este insumo)
              → <span class="var">compras.proveedor_id</span>     (a quién le compramos)
              → <span class="var">activos.proveedor_id</span>     (dónde compramos el activo)

<span class="comment">Si se elimina un proveedor → los FK quedan en NULL (ON DELETE SET NULL)
No se borran las compras ni los activos asociados.</span></div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════ -->
        <!--  SECCIÓN: FÓRMULAS GLOBALES                                   -->
        <!-- ══════════════════════════════════════════════════════════════ -->
        <div class="section" id="formulas">
            <div class="section-hdr">
                <div class="section-icon" style="background:#fefce8">&#129518;</div>
                <div><div class="section-title">Fórmulas y Algoritmos Globales</div></div>
            </div>

            <div class="sub-title">Costo total de fabricación por sándwich</div>
            <div class="formula-block">
<span class="title">Ecuación maestra del costo unitario</span>
<span class="var">costo_ingredientes</span> = SUM(insumo.costo_actual × receta.cantidad_requerida)
                       ÷ productos.unidades_por_receta

<span class="var">costo_fijo_u</span>        = CostoIndirectoModel::total_mensual_activo()
                       ÷ configuracion_negocio.produccion_estimada_mensual

<span class="var">costo_deprec_u</span>      = SUM(activos.depreciacion_diaria)
                       ÷ (produccion_mensual ÷ 21.75)   <span class="comment">← producción por día hábil</span>

<span class="var">costo_rh_u</span>          = SUM(nomina_liquidaciones.costo_total_empleador)
                       ÷ produccion_mensual

<span class="result">costo_total/u</span>       = costo_ingredientes + costo_fijo_u + costo_deprec_u + costo_rh_u

<span class="result">margen_$</span>            = precio_venta - costo_total/u
<span class="result">margen_%</span>            = (precio_venta - costo_total/u) ÷ precio_venta × 100</div>

            <div class="sub-title">Depreciación de activos</div>
            <div class="formula-block">
<span class="title">Método de línea recta</span>
<span class="var">depreciacion_mensual</span>  = costo_inicial ÷ vida_util_meses
<span class="var">depreciacion_diaria</span>   = depreciacion_mensual ÷ 30.41666

<span class="comment">← El trigger trg_activos_deprec_insert/update recalcula automáticamente
   cada vez que se crea o modifica un activo.</span>

<span class="var">valor_en_libros</span>       = costo_inicial - (depreciacion_mensual × meses_transcurridos)
                        donde: meses_transcurridos = meses desde fecha_inicio_uso

<span class="var">estado_vida</span> = 'depreciado' → depreciacion_diaria = $0 (no suma al costo)</div>

            <div class="sub-title">Costos — equivalente mensual</div>
            <div class="formula-block">
<span class="title">Conversión a equivalente mensual por frecuencia de pago</span>
mensual    → valor ÷ 1   = valor/mes
bimestral  → valor ÷ 2   = valor/mes   <span class="comment">(pago cada 2 meses)</span>
trimestral → valor ÷ 3   = valor/mes   <span class="comment">(pago cada 3 meses)</span>
semestral  → valor ÷ 6   = valor/mes   <span class="comment">(pago cada 6 meses)</span>
anual      → valor ÷ 12  = valor/mes   <span class="comment">(pago anual)</span></div>

            <div class="sub-title">Capacidad de producción (POS)</div>
            <div class="formula-block">
<span class="title">Algoritmo de capacidad disponible</span>
<span class="comment">SI hay stock de producto terminado:</span>
  <span class="result">capacidad</span> = productos.stock_disponible

<span class="comment">SI no hay stock terminado (modo demanda):</span>
  <span class="result">capacidad</span> = FLOOR(stock_insumo_critico × unidades_por_receta ÷ cantidad_requerida)

<span class="comment">El insumo crítico es el marcado como es_insumo_critico=1 en la receta.
Es el ingrediente que más limita la producción (el cuello de botella).</span></div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════ -->
        <!--  MÓDULO: CLIENTES                                             -->
        <!-- ══════════════════════════════════════════════════════════════ -->
        <!-- ══════════════════════════════════════════════════════════════ -->
        <!--  DASHBOARD                                                     -->
        <!-- ══════════════════════════════════════════════════════════════ -->
        <div class="section" id="dashboard">
            <div class="section-hdr">
                <div class="section-icon" style="background:#f0f9ff">&#127968;</div>
                <div><div class="section-title">Dashboard</div></div>
            </div>
            <p>Página de inicio del sistema. Combina un resumen del día, indicadores de tendencia, reconocimientos automáticos por WhatsApp y un panel de alertas operativas — cada sección aparece solo si el usuario tiene permiso sobre el módulo correspondiente y solo si hay datos que mostrar.</p>

            <div class="sub-title">Resumen del día</div>
            <table class="data-table">
                <thead><tr><th>Tarjeta</th><th>Qué muestra</th><th>Visible para</th></tr></thead>
                <tbody>
                    <tr><td>Ventas hoy</td><td>Total cobrado en el día (incluye fiados pendientes, excluye obsequios). Clic → abre el Cierre de caja del día.</td><td><code>ventas:solo_ver</code></td></tr>
                    <tr><td>Turno de caja</td><td>Estado del turno actual: 🟢 Abierto (con fondo inicial) / ⚪ Cerrado / 🟡 Sin apertura. Clic → Apertura de turno.</td><td><code>ventas:solo_ver</code> (si mig. 037 aplicada)</td></tr>
                    <tr><td>Producidos hoy</td><td>Unidades producidas en el día. Solo aparece si hubo producción registrada hoy.</td><td><code>productos:solo_ver</code></td></tr>
                    <tr><td>Stock disponible</td><td>Total de unidades de producto terminado listas para vender hoy.</td><td><code>productos:solo_ver</code></td></tr>
                </tbody>
            </table>

            <div class="sub-title">Meta del día (v4.48) y Racha de Metas (v4.63)</div>
            <p>Si el negocio configura una <code>meta_ventas_diaria</code> (clave en <code>configuracion_negocio</code>), aparece una barra de progreso comparando lo vendido hoy contra la meta. El color cambia según el avance: rojo &lt;50%, ámbar 50–79%, verde ≥80%. Los administradores ven un botón ✏️ que revela un formulario inline para editar la meta sin salir del dashboard. Junto al porcentaje, un badge "🔥 Racha: N días" cuenta cuántos días <em>consecutivos</em> (contando hacia atrás desde ayer, sin incluir el día de hoy que aún está en curso) el negocio alcanzó la meta — calculado en PHP día por día porque MySQL 5.7 no soporta funciones de ventana.</p>

            <div class="sub-title">Gráfico de ventas — últimos 7 días (v4.49)</div>
            <p>Barras hechas en HTML/CSS puro (sin librerías externas) que comparan el total vendido cada día de la última semana. La barra de hoy resalta en rojo (<code>--brand</code>); los días anteriores en gris. Pasar el cursor sobre una barra muestra el monto exacto del día. Útil para detectar patrones — por ejemplo, si los fines de semana venden más que entre semana.</p>

            <div class="sub-title">Comparativo del mes (v4.65)</div>
            <p>Compara el total vendido en lo que va del mes contra el <strong>mismo número de días</strong> del mes anterior — no el mes completo, que penalizaría a mitad de mes — usando <code>DATEDIFF()</code> en SQL para una comparación justa sin importar cuántos días tuvo cada mes. Se muestra como un badge ▲/▼ verde o rojo con el porcentaje de variación, acompañado de un mensaje contextual de ánimo ("vas mejor que el mes pasado 🎉" / "¡a recuperar terreno!").</p>

            <div class="sub-title">Tarjetas de seguimiento de clientes y productos (v4.57 – v4.66)</div>
            <table class="data-table">
                <thead><tr><th>Tarjeta</th><th>Qué hace</th><th>Acción rápida</th></tr></thead>
                <tbody>
                    <tr><td>🏆 Top Clientes del Mes</td><td>Los 5 clientes que más compraron en el mes en curso (suma de <code>ventas.total</code>, excluye obsequios y ventas de mostrador), con medallas 🥇🥈🥉 para el top 3.</td><td>"🎉 Agradecer" — abre WhatsApp con mensaje de fidelización pre-armado</td></tr>
                    <tr><td>💌 Clientes para Reactivar</td><td>Clientes activos con teléfono que tienen historial de compras pero llevan más de 30 días sin volver, ordenados por valor histórico total acumulado.</td><td>"💌 Reconectar" — abre WhatsApp con mensaje de reconexión tipo "te extrañamos"</td></tr>
                    <tr><td>🎂 Aniversario de Clientes</td><td>Clientes cuya <strong>primera compra</strong> (<code>MIN(ventas.fecha_venta)</code>) ocurrió en esta misma fecha (mes y día) hace uno o más años — comparación por <code>DATE_FORMAT(...,'%m-%d')</code> para evitar desfases en años bisiestos.</td><td>"🎂 Felicitar" — abre WhatsApp con mensaje de celebración de aniversario pre-armado</td></tr>
                    <tr><td>🥪 Productos Más Vendidos</td><td>Los 5 productos más vendidos del mes en curso (por unidades), con monto generado y una barra de progreso proporcional al producto líder.</td><td>— informativo: ayuda a decidir qué producir primero según la demanda real</td></tr>
                    <tr><td>👤 Rendimiento de Cajeros</td><td>Ranking mensual de ventas agrupado por el usuario que registró cada venta (<code>ventas.created_by</code>): número de ventas, total vendido y ticket promedio.</td><td>— informativo. <strong>Solo visible para roles <code>admin</code>/<code>superadmin</code></strong></td></tr>
                </tbody>
            </table>
            <div class="tip"><strong>Cuatro tonos distintos de WhatsApp según el contexto de la relación:</strong> cobranza ("te recordamos tu saldo pendiente…" — fiados pendientes, v4.51), fidelización ("¡gracias por ser uno de nuestros mejores clientes!" — Top Clientes, v4.57), reconexión ("¡te extrañamos! ¿cuándo te vemos de nuevo?" — Clientes para Reactivar, v4.60) y celebración ("¡hoy se cumple tu aniversario con nosotros! 🎉" — Aniversario de Clientes, v4.66). Cada mensaje llega pre-escrito y solo falta pulsar enviar.</div>
            <div class="warn"><strong>"Rendimiento de Cajeros" y "Productos Más Rentables" son información sensible:</strong> el primero compara el desempeño de los empleados entre sí; el segundo expone el costo de producción y márgenes de ganancia — información financiera estratégica. Por eso ninguna de las dos consultas se ejecuta si el usuario no tiene rol <code>admin</code> o <code>superadmin</code> — el resto del personal no puede verlas bajo ninguna circunstancia, ni inspeccionando la página.</div>

            <div class="sub-title">Indicadores de tendencia y rentabilidad (v4.65, v4.67, v4.68)</div>
            <table class="data-table">
                <thead><tr><th>Tarjeta</th><th>Qué muestra</th><th>Para qué sirve</th></tr></thead>
                <tbody>
                    <tr><td>📊 Comparativo del mes</td><td>Variación porcentual entre el mes en curso y el mismo tramo de días del mes anterior, con badge ▲/▼ y mensaje de ánimo.</td><td>Saber si el negocio va mejor o peor que el mes pasado, no solo si tuvo un buen o mal día.</td></tr>
                    <tr><td>⏰ Horas Pico de Ventas</td><td>Las 3 franjas horarias (<code>HOUR(fecha_venta)</code>) con mayor monto vendido en los últimos 30 días, con medallas y barra de progreso relativa.</td><td>Planear turnos de personal y anticipar producción según la demanda real de cada franja del día.</td></tr>
                    <tr><td>💰 Productos Más Rentables</td><td>Los 5 productos con mejor margen porcentual (<code>(precio_venta − costo_calculado) / precio_venta</code>), con badge codificado por color y ganancia unitaria en pesos. <strong>Solo admin/superadmin.</strong></td><td>Decidir qué productos promocionar o destacar — venden lo mismo, pero no todos dejan la misma ganancia.</td></tr>
                </tbody>
            </table>

            <div class="sub-title">Panel de Alertas operativas</div>
            <p>Aparece automáticamente cuando hay algo que requiere atención — y solo entonces. Cada tarjeta enlaza directo al módulo correspondiente para resolver el problema sin tener que buscarlo.</p>
            <table class="data-table">
                <thead><tr><th>Alerta</th><th>Condición</th><th>Acción rápida incluida</th></tr></thead>
                <tbody>
                    <tr><td>🧂 Insumos bajos / agotados <span style="display:inline-block;margin-left:4px;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600;background:#fef2f2;color:#991b1b">rojo</span></td><td><code>stock_actual ≤ stock_seguridad</code></td><td>"📦 Pedir a [Proveedor] ↗" — WhatsApp con mensaje de restock pre-armado (v4.55), si el insumo tiene proveedor activo con teléfono</td></tr>
                    <tr><td>💳 Fiados pendientes <span style="display:inline-block;margin-left:4px;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600;background:#fff7ed;color:#9a3412">naranja</span></td><td><code>saldo_fiado &gt; 0</code> en clientes activos</td><td>"WA ↗" — recordatorio de cobro por WhatsApp (v4.51), si el cliente tiene teléfono registrado</td></tr>
                    <tr><td>🥪 Stock de producto bajo <span style="display:inline-block;margin-left:4px;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600;background:#fff7ed;color:#9a3412">naranja</span></td><td><code>stock_disponible &lt; stock_minimo</code></td><td>Enlace directo a "Producir"</td></tr>
                    <tr><td>🛡️ Garantías por vencer <span style="display:inline-block;margin-left:4px;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600;background:#fffbeb;color:#92400e">amarillo</span></td><td><code>garantia_hasta</code> entre hoy y los próximos 30 días</td><td>Enlace a "Ver activos"; muestra serial (si tiene) y días restantes (v4.61)</td></tr>
                    <tr><td>⏳ Productos sin rotación <span style="display:inline-block;margin-left:4px;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600;background:#fff7ed;color:#9a3412">naranja</span></td><td>Producto activo con <code>stock_disponible &gt; 0</code>, creado hace más de 15 días, sin ventas registradas en los últimos 15 días (o nunca vendido)</td><td>Enlace a "Ver productos"; distingue "Sin ventas hace N días" de "Nunca se ha vendido" (v4.64)</td></tr>
                </tbody>
            </table>
            <div class="tip"><strong>Solo aparece lo relevante:</strong> cada categoría de alerta se consulta únicamente si el usuario tiene acceso al módulo correspondiente (inventario, ventas, productos, activos), y cada tarjeta —y el panel completo— se oculta si no hay nada que reportar. El dashboard nunca muestra paneles vacíos.</div>
            <div class="tip"><strong>"Productos sin rotación" cuida los perecederos:</strong> en un negocio de sándwiches, el stock terminado que no rota es pérdida potencial por vencimiento. La alerta usa <code>LEFT JOIN</code> con <code>venta_detalles</code>/<code>ventas</code> para distinguir productos que nunca se han vendido (<code>ultima_venta IS NULL</code>) de los que simplemente llevan tiempo sin movimiento, y excluye productos recién creados para evitar falsas alarmas.</div>

            <div class="int-list">
                <span class="int-badge">Usa →</span>
                <span class="int-badge arrow">ventas (resumen del día, gráfico 7 días, comparativo mensual, horas pico, top clientes/productos/cajeros)</span>
                <span class="int-badge arrow">clientes (fiados pendientes, clientes para reactivar, aniversarios)</span>
                <span class="int-badge arrow">inventario (insumos bajos)</span>
                <span class="int-badge arrow">productos (stock bajo, sin rotación, producidos hoy, rentabilidad)</span>
                <span class="int-badge arrow">activos (garantías por vencer)</span>
                <span class="int-badge arrow">turnos_caja — mig. 037 (estado del turno)</span>
                <span class="int-badge arrow">configuracion_negocio (meta de ventas diaria, racha)</span>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════ -->
        <!--  MÓDULO: CLIENTES                                              -->
        <!-- ══════════════════════════════════════════════════════════════ -->
        <div class="section" id="clientes">
            <div class="section-hdr">
                <div class="section-icon" style="background:#eff6ff">&#128101;</div>
                <div><div class="section-title">Módulo: Clientes</div></div>
            </div>
            <p>Gestión completa de la base de clientes del negocio. Accesible desde el menú principal para cualquier usuario con acceso a Ventas.</p>

            <div class="sub-title">Campos del cliente</div>
            <table class="data-table">
                <thead><tr><th>Campo</th><th>Obligatorio</th><th>Descripción</th></tr></thead>
                <tbody>
                    <tr><td>Nombre</td><td>Sí</td><td>Primer nombre del cliente</td></tr>
                    <tr><td>Apellido</td><td>No</td><td>Apellido para distinguir clientes con el mismo nombre</td></tr>
                    <tr><td>Empresa</td><td>No</td><td>Nombre del negocio o empresa si es cliente corporativo</td></tr>
                    <tr><td>Teléfono</td><td>No</td><td>Número de contacto, útil para cobrar fiados</td></tr>
                    <tr><td>Saldo fiado</td><td>Auto</td><td>Deuda acumulada. Se actualiza automáticamente con ventas a crédito y abonos.</td></tr>
                </tbody>
            </table>

            <div class="sub-title">Acciones disponibles</div>
            <table class="data-table">
                <thead><tr><th>Acción</th><th>Permiso</th><th>Qué hace</th></tr></thead>
                <tbody>
                    <tr><td>Crear cliente</td><td>editar_existentes</td><td>Abre un modal con los campos del cliente. El cliente queda activo inmediatamente.</td></tr>
                    <tr><td>Editar cliente</td><td>editar_existentes</td><td>Permite cambiar nombre, apellido, empresa y teléfono. El saldo fiado se maneja desde el módulo de Fiado.</td></tr>
                    <tr><td>Desactivar / Reactivar</td><td>admin_total</td><td>Soft-delete: el cliente desaparece del POS pero conserva todo su historial. No permite desactivar si tiene deuda pendiente.</td></tr>
                    <tr><td>Fusionar</td><td>editar_existentes</td><td>Combina dos clientes en uno (ver sección Fusión).</td></tr>
                    <tr><td>Ver historial 👁</td><td>solo_ver</td><td>Abre Ventas → Historial filtrado por ese cliente (todas sus ventas).</td></tr>
                    <tr><td>Estado de cuenta 📋</td><td>solo_ver</td><td>Extracto cronológico de cargos + abonos con saldo corriente. Incluye botón de impresión. Ver sección abajo.</td></tr>
                </tbody>
            </table>

            <div class="sub-title">Estado de cuenta del cliente</div>
            <p>El extracto (<code>clientes/estado_cuenta.php?id=X</code>) muestra en orden cronológico todas las compras a fiado y todos los abonos del cliente, con el saldo corriente acumulado en cada fila. También es accesible desde el módulo de <strong>Fiado</strong> con el botón "📋 Extracto".</p>
            <table class="data-table">
                <thead><tr><th>Columna</th><th>Descripción</th></tr></thead>
                <tbody>
                    <tr><td>Fecha</td><td>Fecha y hora del movimiento</td></tr>
                    <tr><td>Tipo</td><td>Compra (cargo) o Abono (pago). Las ventas anuladas aparecen tachadas y no afectan el saldo.</td></tr>
                    <tr><td>Descripción</td><td>Productos comprados (usa nombre_snap si está disponible) o método del abono</td></tr>
                    <tr><td>Cargo (+)</td><td>Monto de la compra que se suma a la deuda</td></tr>
                    <tr><td>Abono (−)</td><td>Monto del pago que se resta a la deuda</td></tr>
                    <tr><td>Saldo</td><td>Saldo corriente acumulado: rojo si alto, amarillo si parcial, verde si cero</td></tr>
                </tbody>
            </table>
            <div class="tip"><strong>Impresión:</strong> El botón "🖨 Imprimir" abre una pestaña limpia (sin navegación) que lanza <code>window.print()</code> automáticamente. El CSS de impresión oculta todos los controles y muestra solo el extracto.</div>

            <div class="sub-title">Filtros de búsqueda</div>
            <p>La barra de búsqueda filtra en tiempo real por nombre, apellido, empresa y teléfono. El selector de estado permite ver solo activos, solo inactivos, todos, o solo los que tienen deuda fiado.</p>

            <div class="sub-title">Fusión de clientes duplicados</div>
            <p>Si el mismo cliente fue registrado dos veces con nombres distintos (ej. "Juan García" y "Juan G."), la fusión permite unificarlos sin perder ningún dato histórico.</p>
            <div class="formula-block">
<span class="title">Algoritmo de fusión (transacción atómica PDO)</span>
<span class="comment">Entrada: cliente_principal (permanece) + cliente_secundario (se absorbe)</span>

1. <span class="var">ventas.cliente_id</span> = principal_id    <span class="comment">← todas las ventas del secundario se reasignan</span>
2. <span class="var">pagos_fiado.cliente_id</span> = principal_id  <span class="comment">← todos los abonos del secundario se reasignan</span>
3. <span class="var">clientes.saldo_fiado</span> += saldo_secundario <span class="comment">← se suman las deudas</span>
4. <span class="var">secundario.activo</span> = 0, saldo_fiado = 0    <span class="comment">← se desactiva el secundario</span>

<span class="result">Si cualquier paso falla → ROLLBACK completo. No queda nada a medias.</span></div>
            <div class="warn"><strong>La fusión es irreversible.</strong> Una vez ejecutada, el cliente secundario queda inactivo. Antes de fusionar, verifica que seleccionaste el cliente correcto como principal (el que permanecerá visible en el POS).</div>

            <div class="int-list">
                <span class="int-badge">Usa →</span>
                <span class="int-badge arrow">Ventas (cliente_id)</span>
                <span class="int-badge arrow">pagos_fiado (historial de abonos)</span>
                <span class="int-badge arrow">Fiado (saldo_fiado)</span>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════ -->
        <!--  MÓDULO: VENTAS / POS                                         -->
        <!-- ══════════════════════════════════════════════════════════════ -->
        <div class="section" id="ventas">
            <div class="section-hdr">
                <div class="section-icon" style="background:#fef2f0">&#128722;</div>
                <div><div class="section-title">Módulo: Ventas / POS</div></div>
            </div>
            <p>Punto de venta optimizado para uso en teléfono durante el turno. Registra ventas en tiempo real con descuento automático de inventario.</p>

            <div class="sub-title">Vistas disponibles</div>
            <table class="data-table">
                <tr><th>Vista</th><th>URL</th><th>Función</th></tr>
                <tr><td>POS (caja)</td><td>/ventas/</td><td>Grid de productos, carrito, checkout con método de pago</td></tr>
                <tr><td>Historial</td><td>/ventas/historial.php</td><td>Lista de ventas con filtros, detalle expandible, acciones. Acepta <code>?cliente_id=X</code> para filtrar por cliente.</td></tr>
                <tr><td>Fiado</td><td>/ventas/fiado.php</td><td>Lista de clientes con deuda pendiente. Permite registrar abonos y ver el extracto de cada cliente.</td></tr>
            </table>

            <div class="sub-title">Selector de cliente en el POS</div>
            <p>El POS incluye un <strong>buscador de clientes en tiempo real</strong> (autocomplete). No es un dropdown estático — a medida que escribes el nombre, apellido o empresa, filtra instantáneamente la lista.</p>
            <table class="data-table">
                <thead><tr><th>Acción</th><th>Cómo</th></tr></thead>
                <tbody>
                    <tr><td>Buscar cliente</td><td>Escribe cualquier parte del nombre, apellido o empresa en el campo de búsqueda</td></tr>
                    <tr><td>Seleccionar</td><td>Clic en el resultado, o usa ↑↓ para navegar y Enter para confirmar</td></tr>
                    <tr><td>Ver deuda</td><td>Si el cliente tiene saldo pendiente, aparece en rojo junto a su nombre en la lista</td></tr>
                    <tr><td>Limpiar selección</td><td>Botón × en el chip verde, o presiona Escape</td></tr>
                    <tr><td>Crear cliente nuevo</td><td>Botón "+ Nuevo cliente" debajo del buscador — el cliente se selecciona automáticamente al crearlo</td></tr>
                </tbody>
            </table>
            <div class="tip">El cliente es <strong>opcional</strong> para todos los métodos de pago excepto Fiado, donde es obligatorio. Puedes seleccionar cliente en efectivo si quieres asociar una venta a un cliente específico.</div>

            <div class="sub-title">Métodos de pago disponibles</div>
            <table class="data-table">
                <tr><th>Método</th><th>Comportamiento especial</th></tr>
                <tr><td>Efectivo</td><td>Sin acción adicional</td></tr>
                <tr><td>Nequi / Daviplata / Bancolombia</td><td>Sin acción adicional (agrupados como "digital" en reportes)</td></tr>
                <tr><td>Fiado</td><td>Requiere seleccionar cliente. El total queda pendiente de cobro hasta "Marcar pagado"</td></tr>
                <tr><td>Obsequio 🎁</td><td>El producto se entrega gratis (muestra, regalo, cortesía). El stock se descuenta igual que una venta normal. El precio real queda registrado para saber el valor obsequiado, pero <strong>NO cuenta como ingreso</strong> en ningún reporte ni KPI de ventas.</td></tr>
            </table>
            <div class="tip"><strong>Obsequio vs Venta normal:</strong> La diferencia es solo contable. El flujo de stock es idéntico — se descuenta <code>stock_disponible</code> (o insumos en modo demanda). La venta queda en estado <em>completada</em> con <code>metodo_pago = 'obsequio'</code>.</div>

            <div class="sub-title">Lógica de descuento al vender</div>
            <div class="formula-block">
<span class="title">Algoritmo de descuento de stock (VentaModel::crear)</span>
Para cada producto en el carrito:

  <span class="comment">¿Hay producto terminado listo?</span>
  SI stock_disponible ≥ cantidad:
    UPDATE productos SET stock_disponible -= cantidad
    <span class="var">from_stock = 1</span>  <span class="comment">← insumos NO se tocan</span>

  SINO (producción a demanda):
    Para cada ingrediente en la receta:
      descuento = (cantidad_requerida ÷ unidades_por_receta) × cantidad_vendida
      UPDATE insumos SET stock_actual -= descuento
      <span class="var">from_stock = 0</span></div>

            <div class="sub-title">Estado de una venta</div>
            <table class="data-table">
                <thead><tr><th>Estado</th><th>Cuándo ocurre</th></tr></thead>
                <tbody>
                    <tr><td>completada</td><td>Venta pagada (efectivo, nequi, daviplata, bancolombia) o fiado ya cobrado.</td></tr>
                    <tr><td>pendiente_pago</td><td>Venta a <strong>fiado</strong> sin fecha de pago registrada. El saldo del cliente aumenta. Se convierte en "completada" al marcar pagado.</td></tr>
                    <tr><td>anulada</td><td>Venta cancelada. El stock se revierte automáticamente.</td></tr>
                </tbody>
            </table>

            <div class="sub-title">Venta en combo — selector solo/combo</div>
            <p>Cada producto puede tener una opción "Combo" configurada (ver módulo Productos). Cuando la tiene, al presionar el producto en el POS aparece un mini selector:</p>
            <table class="data-table">
                <thead><tr><th>Opción</th><th>Precio</th><th>Qué descuenta</th></tr></thead>
                <tbody>
                    <tr><td>Solo</td><td>Precio normal del producto</td><td>Solo el stock del sándwich (igual que siempre)</td></tr>
                    <tr><td>Combo</td><td>Precio normal + precio adicional del combo</td><td>Stock del sándwich <strong>más</strong> los insumos extra configurados (gaseosa, papas, chocolatina…)</td></tr>
                </tbody>
            </table>
            <div class="tip">Puedes tener el mismo sándwich como "Solo" Y como "Combo" en la misma venta — cada variante ocupa su propia línea en el carrito.</div>

            <div class="sub-title">Algoritmo de descuento con combo</div>
            <div class="formula-block">
<span class="title">VentaModel::crear() — extras de combo</span>
Para cada ítem en el carrito con <span class="var">es_combo = 1</span>:

  <span class="comment">Primero: descuento normal del sándwich (from_stock igual que siempre)</span>

  <span class="comment">Luego: descontar cada insumo extra del combo</span>
  Para cada insumo en combo_insumos WHERE combo_id = item.combo_id:
    descuento_extra = combo_insumos.cantidad × cantidad_vendida
    UPDATE insumos SET stock_actual -= descuento_extra
    SI stock insuficiente → ERROR "Stock insuficiente de X para el combo" → ROLLBACK

<span class="comment">Al anular la venta:
  - es_combo=1 + combo_id NOT NULL → restaura los insumos extra
  - es_combo=1 + combo_id NULL (datos históricos) → no restaura extras
    (registros anteriores a la migración 025 no los descontaron)</span></div>

            <div class="sub-title">Fecha de la venta — POS y edición</div>
            <p>Al registrar una nueva venta en el POS aparece un campo <strong>Fecha de la venta</strong> (solo fecha, sin hora) con el valor predeterminado de hoy. Puedes cambiarlo para registrar ventas de días anteriores (útil si olvidaste anotar una venta). La fecha no puede ser futura.</p>
            <p>Al editar una venta existente desde el historial, el modal incluye el campo <strong>Fecha y hora de la venta</strong> (fecha + hora), pre-cargado con la fecha original. Si lo dejas igual, la fecha no cambia. Si lo modificas, la venta se mueve al período que corresponda.</p>
            <div class="warn"><strong>Nota:</strong> Cambiar la fecha de una venta no mueve el registro en los reportes filtrados por rango — recuerda ajustar los filtros del historial para ver la venta en su nueva fecha.</div>

            <div class="sub-title">Acciones en el historial</div>
            <ul>
                <li><strong>Detalle:</strong> Expande la fila y carga los productos de la venta vía AJAX. Los ítems vendidos como combo muestran la etiqueta <strong>combo</strong> en azul. Las ventas con <code>metodo_pago='obsequio'</code> muestran la etiqueta <strong>Obsequio</strong> en rosa.</li>
                <li><strong>Editar:</strong> Abre el modal de edición. Permite cambiar: fecha/hora, método de pago (incluido obsequio), cliente, notas, productos (cantidad, precio, tipo solo/combo). Al guardar, el stock de la venta anterior se revierte y el nuevo stock se aplica en una sola transacción atómica.</li>
                <li><strong>Marcar pagado:</strong> Solo para ventas con estado=pendiente_pago (fiado sin cobrar). Registra la fecha de pago, cambia estado a "completada" y reduce saldo_fiado del cliente.</li>
                <li><strong>Anular:</strong> Solo admin_total. Revierte el stock (from_stock=1 → restaura stock_disponible; from_stock=0 → restaura insumos). Si tenía combo, restaura también los insumos extra. Si era fiado, reduce el saldo del cliente.</li>
            </ul>

            <div class="int-list">
                <span class="int-badge">Usa →</span>
                <span class="int-badge arrow">Inventario (insumos)</span>
                <span class="int-badge arrow">Producción (stock_disponible)</span>
                <span class="int-badge arrow">Clientes (fiado)</span>
                <span class="int-badge arrow">Productos (capacidad POS + combo config)</span>
            </div>

            <div class="sub-title">Cierre de caja — v4.43</div>
            <p><strong>Ventas → Cierre de caja</strong> (<code>ventas/cierre.php</code>) genera el resumen diario de ingresos. Accesible desde el historial (botón "🧾 Cierre de caja") o desde la tarjeta "Ventas hoy" del dashboard.</p>
            <table class="data-table">
                <thead><tr><th>Sección</th><th>Descripción</th></tr></thead>
                <tbody>
                    <tr><td>Selector de fecha</td><td>Navega cualquier día anterior. Predeterminado: hoy. No permite fechas futuras.</td></tr>
                    <tr><td>Resumen por método de pago</td><td>Cards individuales: efectivo, transferencia, fiado, obsequio — con total y número de ventas.</td></tr>
                    <tr><td>Panel de totales</td><td>Cobrado (sin fiado), fiado pendiente del día, obsequios, total general.</td></tr>
                    <tr><td>Detalle por producto</td><td>Unidades y pesos por producto + variante. Usa snapshots de nombre si mig.034 está aplicada.</td></tr>
                    <tr><td>Fiados del día</td><td>Lista de ventas pendientes de cobro con estado (pagado / pendiente).</td></tr>
                    <tr><td>Ventas anuladas</td><td>Contador informativo — excluidas del total.</td></tr>
                    <tr><td>Imprimir</td><td>CSS @media print oculta navegación, selector y botones. Queda solo el resumen limpio.</td></tr>
                    <tr><td>Compartir por WhatsApp</td><td>Botón verde "Compartir". En móvil usa la API nativa <code>navigator.share()</code>; en escritorio abre WhatsApp Web con el texto prellenado.</td></tr>
                </tbody>
            </table>
            <div class="ok"><strong>Texto compartido:</strong> Incluye nombre del negocio, fecha, totales por método de pago, detalle de productos con variantes, y sección de fondo + total en caja si hay turno registrado. Formato <em>Markdown</em> compatible con WhatsApp (negritas con <code>*asteriscos*</code>).</div>

            <div class="sub-title">Apertura de turno / Fondo de caja — v4.45</div>
            <p><strong>Ventas → Apertura de turno</strong> (<code>ventas/apertura.php</code>) registra el fondo de caja al inicio de cada día y es el complemento natural del cierre. Requiere migración 037 (<code>turnos_caja</code>). Si la migración no está aplicada, la página informa cómo aplicarla sin fallar.</p>
            <table class="data-table">
                <thead><tr><th>Estado</th><th>Descripción</th></tr></thead>
                <tbody>
                    <tr><td>● Abierto (verde)</td><td>Hay turno activo: muestra fondo inicial, efectivo cobrado hasta ahora (en vivo) y total en caja.</td></tr>
                    <tr><td>Cerrado (gris)</td><td>El turno del día fue cerrado por un admin. No se puede abrir otro.</td></tr>
                    <tr><td>Sin apertura (amarillo)</td><td>No hay turno para hoy. Formulario disponible para abrir uno con fondo y notas opcionales.</td></tr>
                </tbody>
            </table>
            <table class="data-table" style="margin-top:10px">
                <thead><tr><th>Campo</th><th>Descripción</th></tr></thead>
                <tbody>
                    <tr><td>Fondo de caja inicial</td><td>Efectivo en billetes que hay en caja al abrir (para dar cambio a los clientes).</td></tr>
                    <tr><td>Notas del turno</td><td>Observaciones libres: quién abre, eventos especiales, etc.</td></tr>
                    <tr><td>KPI "Total en caja"</td><td>Fondo inicial + efectivo cobrado en ventas del día. Se actualiza cada vez que recargas.</td></tr>
                    <tr><td>Cerrar turno</td><td>Solo admin. Registra fecha/hora de cierre y notas de cierre. Acción con confirmación.</td></tr>
                    <tr><td>Historial</td><td>Últimos 10 turnos con fecha, quien abrió, fondo y estado. Cada fecha enlaza al cierre correspondiente.</td></tr>
                </tbody>
            </table>
            <div class="ok"><strong>Integración con cierre.php:</strong> Cuando hay turno registrado para la fecha seleccionada, el cierre muestra un panel azul oscuro con Fondo apertura / Efectivo cobrado / Total en caja. Si es hoy y no hay turno, aparece una alerta con link directo para abrirlo.</div>
            <div class="warn"><strong>Nota:</strong> Un día puede tener máximo un turno activo. La tabla <code>turnos_caja</code> no tiene FK (política del proyecto — cPanel errno 121). La integridad la garantiza la validación en <code>apertura.php</code> antes de insertar.</div>

            <div class="sub-title">Descuentos en el POS — v4.46</div>
            <p>El POS (<code>ventas/index.php</code>) permite aplicar un descuento porcentual a toda la venta antes de confirmarla. Requiere migración 038 y permiso <strong>editar_existentes</strong> en el módulo <em>ventas</em>. Los cajeros sin ese permiso no ven el campo.</p>
            <table class="data-table">
                <thead><tr><th>Campo</th><th>Descripción</th></tr></thead>
                <tbody>
                    <tr><td>Descuento (%)</td><td>Porcentaje entero de 0 a 50. Al escribirlo aparece el monto descontado junto al campo. Al limpiar el carrito se resetea a 0.</td></tr>
                    <tr><td>descuento_pct</td><td>Guardado en <code>ventas</code>. Snapshot inmutable del porcentaje aplicado.</td></tr>
                    <tr><td>descuento_valor</td><td>Monto monetario descontado = total_bruto × pct / 100. Snapshot inmutable calculado en <code>VentaModel::crear()</code>.</td></tr>
                    <tr><td>total en ventas</td><td>= total_bruto − descuento_valor (el neto real pagado por el cliente).</td></tr>
                </tbody>
            </table>
            <table class="data-table" style="margin-top:10px">
                <thead><tr><th>Vista</th><th>Qué muestra</th></tr></thead>
                <tbody>
                    <tr><td>Historial de ventas</td><td>Badge amarillo "−X% dto" debajo del total en ventas con descuento.</td></tr>
                    <tr><td>Cierre de caja</td><td>Nota al pie del panel de totales: n ventas con descuento — total descontado: −$X. También aparece en el texto de WhatsApp.</td></tr>
                </tbody>
            </table>
            <div class="warn"><strong>Alcance del descuento:</strong> Se aplica al total de la venta, no por ítem. Las cantidades en <code>venta_detalles</code> (y por tanto el descuento de stock/insumos) no se modifican — el descuento es solo financiero. Solo usuarios con permiso <em>editar_existentes</em> o superior pueden aplicar descuentos; la validación ocurre en el servidor (<code>procesar_venta.php</code>).</div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════ -->
        <!--  MÓDULO: INVENTARIO                                           -->
        <!-- ══════════════════════════════════════════════════════════════ -->
        <div class="section" id="inventario">
            <div class="section-hdr">
                <div class="section-icon" style="background:#f0fdf4">&#128230;</div>
                <div><div class="section-title">Módulo: Inventario</div></div>
            </div>
            <p>Gestión del stock de materia prima (insumos). Muestra estado en tiempo real y permite ajustes manuales por mermas o pérdidas.</p>

            <div class="sub-title">Columna costo_actual — cómo se calcula</div>
            <div class="formula-block">
<span class="title">Trigger automático (migración 010)</span>
<span class="result">costo_actual</span> = precio_presentacion ÷ cantidad_presentacion

<span class="comment">Ejemplo: Pollo 1kg a $17,325 → costo_actual = $17,325/kg
Si el empaque es 500g a $9,000 → costo_actual = $9,000 ÷ 0.5 = $18,000/kg</span>

<span class="comment">Este valor se actualiza automáticamente al registrar una compra.
Al cambiar costo_actual → RecetaModel recalcula costo_calculado de todos
los productos que usan ese insumo.</span></div>

            <div class="sub-title">Cálculo bidireccional: Presentación ↔ Costo</div>
            <p>Los campos <strong>Cantidad por presentación</strong>, <strong>Precio por presentación</strong> y <strong>Costo por unidad</strong> están relacionados. Puedes llenar cualquier par y el tercero se calcula automáticamente:</p>
            <table class="data-table">
                <thead><tr><th>Llenas…</th><th>Se calcula…</th><th>Fórmula</th></tr></thead>
                <tbody>
                    <tr><td>Cantidad + Precio presentación</td><td>Costo/unidad</td><td>costo = precio ÷ cantidad</td></tr>
                    <tr><td>Cantidad + Costo/unidad</td><td>Precio presentación</td><td>precio = costo × cantidad</td></tr>
                    <tr><td>Precio presentación + Costo/unidad</td><td>Cantidad</td><td>cantidad = precio ÷ costo</td></tr>
                </tbody>
            </table>

            <div class="sub-title">Equivalencia física por unidad (migración 030)</div>
            <p>Cuando la unidad básica de un insumo no es una medida física (loncha, lata, paquete, unidad…), puedes indicar cuánto pesa o contiene cada unidad. Esto mejora la trazabilidad de costos.</p>
            <table class="data-table">
                <thead><tr><th>Ejemplo</th><th>Unidad básica</th><th>Equivalencia</th><th>Resultado</th></tr></thead>
                <tbody>
                    <tr><td>Jamón Serrano</td><td>loncha</td><td>1 loncha = 30 g</td><td>Puedes ver costo por gramo</td></tr>
                    <tr><td>Atún en lata</td><td>lata</td><td>1 lata = 170 g</td><td>Al comprar 10 latas = 1.700 g total</td></tr>
                    <tr><td>Jugo</td><td>paquete</td><td>1 paquete = 250 ml</td><td>Costo por ml calculable</td></tr>
                </tbody>
            </table>
            <div class="tip">El campo de equivalencia solo aparece cuando la unidad <strong>no es física</strong> (kg, g, lb, litro, ml ya son unidades físicas — no necesitan equivalencia). Al registrar una compra, se muestra automáticamente el total físico equivalente (ej: "= 300 g total").</div>

            <div class="sub-title">Estados de stock</div>
            <table class="data-table">
                <tr><th>Estado</th><th>Condición</th><th>Color</th></tr>
                <tr><td>OK</td><td>stock_actual &gt; stock_seguridad</td><td>Verde</td></tr>
                <tr><td>Bajo</td><td>0 &lt; stock_actual ≤ stock_seguridad</td><td>Amarillo</td></tr>
                <tr><td>Agotado</td><td>stock_actual = 0</td><td>Rojo</td></tr>
            </table>

            <div class="sub-title">Editar insumo — ajuste de cantidad opcional (v4.24)</div>
            <p>Al abrir el modal de edición de un insumo puedes modificar el <strong>nombre</strong>, la <strong>presentación</strong>, el <strong>costo</strong> y la <strong>equivalencia física</strong> sin necesidad de ingresar una cantidad de ajuste. La cantidad a ajustar es opcional:</p>
            <table class="data-table">
                <thead><tr><th>Escenario</th><th>Cantidad ingresada</th><th>Resultado</th></tr></thead>
                <tbody>
                    <tr><td>Solo editar presentación o costo</td><td>0 (vacío)</td><td>Se guardan los campos del insumo sin modificar el stock. No se registra movimiento en el historial.</td></tr>
                    <tr><td>Ajuste + edición de campos</td><td>&gt; 0</td><td>Se registra el movimiento de stock (entrada o merma) <strong>y</strong> se guardan los campos del insumo.</td></tr>
                    <tr><td>Solo ajuste de stock</td><td>&gt; 0 sin cambiar otros campos</td><td>Solo se registra el movimiento de stock.</td></tr>
                </tbody>
            </table>
            <div class="ok"><strong>Caso de uso típico:</strong> Actualizaste el proveedor de una lata de atún — ahora viene en presentación de 170 g en lugar de 160 g y el precio cambió. Abre el insumo, edita Cantidad/Presentación y Precio/Presentación, deja la cantidad de ajuste en 0 y guarda. El costo_actual se recalcula y los productos afectados actualizan su margen automáticamente.</div>

            <div class="int-list">
                <span class="int-badge">Alimenta →</span>
                <span class="int-badge arrow">Ventas (descuento on-demand)</span>
                <span class="int-badge arrow">Producción (descuento al producir)</span>
                <span class="int-badge arrow">Productos (costo_calculado)</span>
                <span class="int-badge arrow">Reportes (stock report)</span>
            </div>

            <div class="sub-title">Conteo rápido de stock — v4.44</div>
            <p><strong>Inventario → Conteo rápido</strong> (<code>inventario/conteo.php</code>) permite actualizar el stock real de todos los insumos en una sola pantalla, ideal para cierres de turno o auditorías sin editar insumo por insumo.</p>
            <table class="data-table">
                <thead><tr><th>Elemento</th><th>Descripción</th></tr></thead>
                <tbody>
                    <tr><td>Filtro por categoría</td><td>Chips en la barra superior para ver solo un grupo (proteína, lácteo, vegetal…).</td></tr>
                    <tr><td>Buscador</td><td>Filtra insumos por nombre en tiempo real.</td></tr>
                    <tr><td>Tarjeta de insumo</td><td>Muestra nombre, estado (OK / bajo / agotado) y stock actual. El campo de entrada usa el stock actual como placeholder.</td></tr>
                    <tr><td>Resaltado en rojo</td><td>La tarjeta se bordea en rojo al ingresar un valor diferente al stock actual.</td></tr>
                    <tr><td>Barra flotante</td><td>Fija en la parte inferior: contador "X / N insumos" + botones <em>Limpiar</em> y <em>Guardar conteo</em>.</td></tr>
                    <tr><td>Solo guarda cambios</td><td>El sistema omite los insumos cuyo valor ingresado coincide con el stock actual — no genera registros de auditoría innecesarios.</td></tr>
                    <tr><td>Atajo teclado</td><td><kbd>Enter</kbd> avanza al siguiente insumo visible, útil con tablet en mano durante el conteo físico.</td></tr>
                </tbody>
            </table>
            <div class="ok"><strong>Flujo recomendado:</strong> Al abrir el negocio o al cerrar turno, cuenta físicamente cada insumo, escribe el valor en la tarjeta correspondiente y pulsa <em>Guardar conteo</em>. Cada cambio queda registrado en el historial de auditoría con motivo <code>conteo_rapido</code>.</div>
            <div class="warn"><strong>Importante:</strong> El conteo rápido <em>reemplaza</em> el stock actual — no suma ni resta. Si ingresas 5 kg donde había 8 kg, el sistema registra el ajuste de −3 kg. Úsalo cuando tienes la cantidad real frente a ti.</div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════ -->
        <!--  MÓDULO: COMPRAS                                              -->
        <!-- ══════════════════════════════════════════════════════════════ -->
        <div class="section" id="compras">
            <div class="section-hdr">
                <div class="section-icon" style="background:#eff6ff">&#128717;</div>
                <div><div class="section-title">Módulo: Compras</div></div>
            </div>
            <p>Registro de compras de insumos. Cada compra actualiza automáticamente el costo y el stock de los insumos comprados, y recalcula el costo de los productos afectados.</p>

            <div class="sub-title">Cascada de actualización al registrar una compra</div>
            <div class="formula-block">
<span class="title">CompraModel::crear() — en una sola transacción PDO</span>
1. INSERT en <span class="var">compras</span> (cabecera)
2. Para cada línea (compra_detalles):
   INSERT en <span class="var">compra_detalles</span>
   UPDATE <span class="var">insumos</span>:
     costo_actual = precio_pagado_por_unidad    <span class="comment">← nuevo precio de referencia</span>
     stock_actual += cantidad_comprada
3. <span class="result">RecetaModel::recalcular_por_insumo(insumo_id)</span>
   → Actualiza costo_calculado de todos los productos que usan ese insumo</div>

            <div class="tip"><strong>Importante:</strong> El costo_actual de un insumo siempre refleja el último precio de compra. Si se compra pollo a $17,000 y luego a $18,000, el costo_actual queda en $18,000 y el margen de los sándwiches se recalcula automáticamente.</div>

            <div class="sub-title">Panel informativo de presentación (v4.24)</div>
            <p>Al seleccionar un insumo en el formulario de nueva compra, aparece automáticamente un <strong>panel informativo de solo lectura</strong> que muestra la presentación tal como está registrada en el inventario. Esto facilita verificar qué unidad se está comprando antes de ingresar cantidad y precio.</p>
            <table class="data-table">
                <thead><tr><th>Dato en el panel</th><th>Qué indica</th><th>Ejemplo</th></tr></thead>
                <tbody>
                    <tr><td>📦 Tipo de empaque</td><td>Presentación del insumo (lata, bolsa, paquete…)</td><td>Lata · Unidad · 1 und/presentación</td></tr>
                    <tr><td>Unidad básica</td><td>Unidad en la que se mide el stock</td><td>unidad, g, ml, kg…</td></tr>
                    <tr><td>Cantidad por empaque</td><td>Cuántas unidades básicas trae cada empaque</td><td>"12 und/paquete"</td></tr>
                    <tr><td>Equivalencia física</td><td>Peso o volumen real por unidad (badge verde)</td><td>"170 g"</td></tr>
                    <tr><td>= X unidades total</td><td>Hint dinámico que se actualiza al ingresar cantidad</td><td>"= 24 und total" al comprar 2 paquetes</td></tr>
                </tbody>
            </table>
            <div class="tip">El hint dinámico <strong>"= X unidades total"</strong> aparece debajo del campo de cantidad y también dentro del panel. Se actualiza en tiempo real conforme escribes la cantidad. Para insumos con empaque de más de 1 unidad muestra el total en unidades básicas; para insumos con equivalencia física muestra el total en la unidad física (gramos, ml…).</div>
            <div class="ok"><strong>Importante:</strong> Los datos del panel son de solo lectura — reflejan la información del inventario. Para editar la presentación de un insumo usa el módulo <strong>Inventario → editar insumo</strong>. Las compras siempre quedan guardadas con el snapshot de presentación vigente al momento de registrarlas.</div>

            <div class="sub-title">Filtrar historial de compras</div>
            <p>En el historial de compras puedes combinar los siguientes filtros para encontrar rápidamente cualquier compra:</p>
            <table class="data-table">
                <thead><tr><th>Filtro</th><th>Descripción</th></tr></thead>
                <tbody>
                    <tr><td>Rango de fechas</td><td>Desde / hasta — por defecto muestra los últimos 30 días.</td></tr>
                    <tr><td>Lugar de compra</td><td>Búsqueda parcial: escribe "plaza" para ver todas las compras en la Plaza Minorista.</td></tr>
                    <tr><td>Ítem (insumo)</td><td>Muestra solo las compras que contienen ese insumo. Las líneas del detalle se filtran para mostrar solo el ítem buscado.</td></tr>
                    <tr><td>Categoría</td><td>Filtra por categoría de insumo: proteína, lácteo, vegetal, condimento, empaque…</td></tr>
                    <tr><td>Ordenar por</td><td>Fecha (más reciente), lugar de compra A-Z, o total (mayor primero).</td></tr>
                </tbody>
            </table>

            <div class="sub-title">Acciones sobre compras registradas</div>
            <p>Cada compra en el historial tiene tres botones de acción:</p>
            <table class="data-table">
                <thead><tr><th>Acción</th><th>Permiso</th><th>Qué hace</th></tr></thead>
                <tbody>
                    <tr><td>✏️ Editar</td><td>editar_existentes</td><td>Abre un formulario pre-llenado. Puedes cambiar proveedor, lugar, notas y todas las líneas. Al guardar: revierte el stock original, aplica el nuevo y recalcula costos. <strong>Transacción atómica</strong>: si algo falla, todo se revierte.</td></tr>
                    <tr><td>📋 Duplicar</td><td>solo_propios</td><td>Crea una nueva compra con los mismos ítems, precios, proveedor y lugar. Aplica al stock como si fuera una compra nueva. Útil para compras recurrentes.</td></tr>
                    <tr><td>🗑 Eliminar</td><td>editar_existentes</td><td>Borra la compra y <strong>revierte el stock</strong> de todos sus ítems. Esta acción no puede deshacerse. El costo_actual de los insumos no se revierte (queda el valor del registro más reciente).</td></tr>
                </tbody>
            </table>
            <div class="warn"><strong>Eliminar es permanente.</strong> Si registraste una compra por error, edítala en lugar de eliminarla para no perder la trazabilidad histórica.</div>

            <div class="int-list">
                <span class="int-badge">Actualiza →</span>
                <span class="int-badge arrow">Inventario (stock + costo)</span>
                <span class="int-badge arrow">Productos (margen automático)</span>
                <span class="int-badge arrow">Proveedores (historial)</span>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════ -->
        <!--  MÓDULO: PROVEEDORES                                          -->
        <!-- ══════════════════════════════════════════════════════════════ -->
        <div class="section" id="proveedores">
            <div class="section-hdr">
                <div class="section-icon" style="background:#fefce8">&#127968;</div>
                <div><div class="section-title">Módulo: Proveedores</div></div>
            </div>
            <p>Directorio centralizado de proveedores. Un proveedor vinculado aparece automáticamente como opción en insumos, compras y activos.</p>

            <div class="sub-title">Relaciones en la base de datos</div>
            <div class="formula-block">
<span class="title">FK (Foreign Key) ON DELETE SET NULL</span>
proveedores.id ← insumos.proveedor_id     <span class="comment">¿De quién compro este insumo?</span>
             ← compras.proveedor_id     <span class="comment">¿A quién le compré?</span>
             ← activos.proveedor_id     <span class="comment">¿Dónde compré el equipo?</span>

<span class="comment">Si se desactiva un proveedor, sus referencias quedan (no se borran).
Si se eliminara, todos los FK quedarían en NULL.</span>
<span class="comment">← Por eso usamos toggle (activar/desactivar) en vez de delete.</span></div>

            <div class="int-list">
                <span class="int-badge">Referenciado por →</span>
                <span class="int-badge arrow">Insumos</span>
                <span class="int-badge arrow">Compras</span>
                <span class="int-badge arrow">Activos</span>
                <span class="int-badge arrow">Reporte Compras</span>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════ -->
        <!--  MÓDULO: PRODUCTOS                                            -->
        <!-- ══════════════════════════════════════════════════════════════ -->
        <div class="section" id="productos">
            <div class="section-hdr">
                <div class="section-icon" style="background:#fdf4ff">&#129364;</div>
                <div><div class="section-title">Módulo: Productos y Recetas</div></div>
            </div>
            <p>Gestión de la carta de productos con costeo real basado en ingredientes, costos operativos, nómina y depreciación.</p>

            <div class="sub-title">Campo nombre2 — Subtítulo complementario</div>
            <p>Cada producto tiene un campo <strong>Nombre complementario</strong> (nombre2) que actúa como subtítulo descriptivo.</p>
            <table class="data-table">
                <thead><tr><th>Campo</th><th>Ejemplo</th><th>Dónde se muestra</th></tr></thead>
                <tbody>
                    <tr><td><code>nombre</code></td><td>Sandwich de Pollo</td><td>Principal en todos los módulos</td></tr>
                    <tr><td><code>nombre2</code></td><td>con papas criollas</td><td>Subtítulo gris debajo del nombre en: POS, Producción, Historial de ventas, Stock terminado, Reportes Excel</td></tr>
                </tbody>
            </table>
            <div class="tip"><strong>Es solo visual.</strong> El nombre2 no afecta ninguna lógica de negocio (precios, stock, recetas, reportes financieros). Es un complemento descriptivo para distinguir variantes de un mismo producto.</div>
            <div class="ok"><strong>Cómo editar:</strong> En el módulo Productos, abre el modal de edición de cualquier producto y encontrarás el campo "Nombre complementario" debajo del nombre principal. Es opcional — déjalo vacío si no aplica.</div>

            <div class="sub-title">Campo unidades_por_receta</div>
            <div class="formula-block">
<span class="title">¿Qué significa unidades_por_receta?</span>
<span class="comment">Define cuántos sándwiches produce UNA tanda completa de la receta.</span>

Ejemplo: unidades_por_receta = 5
  → La receta usa 500g de pollo, 5 panes, 150g queso
  → Eso alcanza para 5 sándwiches
  → Costo por sándwich = costo_ingredientes_tanda ÷ 5

Si unidades_por_receta = 1 (valor por defecto):
  → La receta define cantidades POR sándwich individual
  → Compatible con toda la data existente</div>

            <div class="sub-title">Recálculo automático de costo_calculado</div>
            <div class="formula-block">
<span class="title">RecetaModel::recalcular_todos()</span>
UPDATE productos p
SET costo_calculado = IFNULL(
    SUM(insumo.costo_actual × receta.cantidad_requerida), 0
) ÷ GREATEST(unidades_por_receta, 1)

<span class="comment">Se dispara automáticamente cuando:
  - Se registra una compra (cambia costo_actual de insumos)
  - Se edita una receta (agregar/quitar ingrediente o cambiar cantidad)
  - Se presiona "Recalcular" en el panel de Productos</span></div>

            <div class="sub-title">Clasificación del margen</div>
            <table class="data-table">
                <tr><th>Margen</th><th>Semáforo</th><th>Acción recomendada</th></tr>
                <tr><td>≥ 40%</td><td>Verde ✓</td><td>Producto rentable</td></tr>
                <tr><td>20% — 39%</td><td>Amarillo ⚠</td><td>Revisar costos</td></tr>
                <tr><td>&lt; 20%</td><td>Rojo ✗</td><td>Ajustar precio o reducir costos urgente</td></tr>
            </table>

            <div class="sub-title">Configurar opción Combo</div>
            <p>Dentro de la fila expandida de cada producto hay una sección <strong>"Opción Combo"</strong>. Aquí se define qué insumos extras trae el combo y cuánto cuesta adicional.</p>
            <table class="data-table">
                <thead><tr><th>Campo</th><th>Descripción</th></tr></thead>
                <tbody>
                    <tr><td>Nombre del combo</td><td>Etiqueta descriptiva (ej. "Combo", "Combo grande"). Solo informativo.</td></tr>
                    <tr><td>Precio adicional</td><td>Lo que se suma al precio normal del sándwich al vender en combo. El total se muestra en tiempo real.</td></tr>
                    <tr><td>Insumos extra</td><td>Lista de insumos que se descontarán del inventario al vender el combo (gaseosa, papas, chocolatina…). Se especifica la cantidad por combo vendido.</td></tr>
                </tbody>
            </table>
            <div class="ok"><strong>Guardar combo:</strong> Hace un upsert (crea si no existe, actualiza si ya existe). Los insumos configurados se reemplazan completamente — agrega o quita los que necesites y guarda.</div>
            <div class="warn"><strong>Quitar combo:</strong> Desactiva la configuración (soft-delete). Las ventas históricas registradas como combo no se alteran. El producto vuelve a venderse solo en el POS.</div>

            <div class="sub-title">Variantes de tamaño — migración 035</div>
            <p>Un producto puede tener múltiples variantes de tamaño (XL, Regular, Familiar…). En lugar de crear un producto separado por tamaño, defines las variantes dentro del mismo producto. Cada variante tiene su propio precio y un <strong>factor de receta</strong> que escala las cantidades de ingredientes.</p>
            <table class="data-table">
                <thead><tr><th>Campo</th><th>Descripción</th></tr></thead>
                <tbody>
                    <tr><td>Etiqueta</td><td>Nombre de la variante: "XL", "Regular", "Familiar", etc. Máximo 80 caracteres. Debe ser único por producto (no puede haber dos variantes activas con la misma etiqueta).</td></tr>
                    <tr><td>Precio de venta</td><td>Precio cobrado al cliente cuando elige esta variante. Sobreescribe el precio base del producto.</td></tr>
                    <tr><td>Factor de receta</td><td>Multiplica las cantidades de insumos que se descuentan al vender. <code>1.0</code> = igual que la receta base. <code>1.5</code> = 50% más ingredientes (p.ej. para XL). <code>0.7</code> = 30% menos (p.ej. para mini).</td></tr>
                </tbody>
            </table>
            <div class="formula-block">
<span class="title">Fórmula de descuento de insumos con variante</span>
<span class="comment">Al vender en modo demanda (sin stock terminado):</span>
descuento = (cantidad_requerida ÷ unidades_por_receta) × cantidad_vendida × <span class="var">factor_receta</span>

<span class="comment">Ejemplo: Pollo XL (factor = 1.5), receta base usa 150g de pollo / sándwich:
  descuento = (150 ÷ 1) × 1 × 1.5 = 225g de pollo por XL vendido</span>

<span class="comment">Al anular la venta se usa factor_receta_snap (snapshot guardado en venta_detalles).
  Si factor_receta_snap es NULL (venta anterior a mig. 035) se asume factor 1.0.</span></div>

            <div class="sub-title">Flujo en el POS con variantes</div>
            <p>Al tocar un producto que tiene variantes configuradas, el POS muestra un <strong>selector de tamaño</strong> (mini bottom-sheet) antes de agregar al carrito:</p>
            <ol style="padding-left:20px;line-height:1.9">
                <li>El cajero toca el producto en el grid.</li>
                <li>Aparece el selector de variante con un botón por cada tamaño y su precio.</li>
                <li>Al seleccionar una variante, si el producto también tiene combo configurado, se muestra el selector Solo/Combo (con el precio de la variante como base).</li>
                <li>El ítem se agrega al carrito con un badge azul que muestra la etiqueta (ej. <strong>XL</strong>).</li>
            </ol>
            <div class="tip">Puedes tener el mismo producto en distintos tamaños en la misma venta — cada combinación (producto + variante) ocupa su propia línea en el carrito.</div>
            <div class="tip">El historial de ventas muestra la etiqueta de variante en azul al expandir cada venta. El reporte de ventas incluye una tabla "Ventas por Variante" y una hoja Excel dedicada.</div>

            <div class="sub-title">Configurar variantes — Módulo Productos</div>
            <p>En la fila expandida de cada producto hay una sección <strong>"Variantes de Tamaño"</strong> que permite:</p>
            <ul style="padding-left:20px;line-height:1.9">
                <li><strong>Crear</strong> variantes con etiqueta, precio y factor de receta.</li>
                <li><strong>Editar</strong> una variante existente (el precio y factor se pueden cambiar sin perder el historial).</li>
                <li><strong>Desactivar</strong> una variante (soft-delete: activo=0). Los datos históricos de ventas se conservan intactos.</li>
                <li><strong>Reactivar</strong> una variante desactivada.</li>
            </ul>
            <div class="warn"><strong>Nota de inmutabilidad:</strong> Al vender una variante, la etiqueta (<code>variante_etiqueta</code>) y el factor (<code>factor_receta_snap</code>) se guardan como snapshot en <code>venta_detalles</code>. Si luego editas el factor de la variante, las ventas históricas se revierten con el factor original.</div>

            <div class="sub-title">Ingrediente base — migración 036</div>
            <p>Dentro de la receta de un producto, cada ingrediente puede marcarse como <strong>"base"</strong> (<code>es_base = 1</code>). Un ingrediente base tiene cantidad <strong>fija</strong>: no se escala con el <code>factor_receta</code> de la variante de tamaño.</p>
            <table class="data-table">
                <thead><tr><th>Ingrediente</th><th>es_base</th><th>Venta Regular (factor 1.0)</th><th>Venta XL (factor 1.5)</th></tr></thead>
                <tbody>
                    <tr><td>Pan (1 unidad)</td><td>1 — base</td><td>1 unidad</td><td>1 unidad <em>(no escala)</em></td></tr>
                    <tr><td>Pollo (150g)</td><td>0 — escala</td><td>150g</td><td>225g (×1.5)</td></tr>
                    <tr><td>Salsa (1 cdta)</td><td>1 — base</td><td>1 cdta</td><td>1 cdta <em>(no escala)</em></td></tr>
                </tbody>
            </table>
            <div class="formula-block">
<span class="title">Fórmula con ingrediente base</span>
descuento = (cantidad_requerida ÷ unidades_por_receta) × cantidad_vendida × <span class="var">factor</span>

<span class="comment">donde factor = 1.0  si es_base = 1  (ingrediente fijo)
              factor = factor_receta  si es_base = 0  (ingrediente que escala)</span></div>
            <div class="ok"><strong>Cómo marcar un ingrediente como base:</strong> En la fila expandida de un producto (Módulo Productos), cada ingrediente de la receta tiene un botón 🔒. Al pulsarlo, el botón se torna verde y el badge <em>🔒base</em> aparece junto al nombre. Pulsa de nuevo para desmarcar.</div>
            <div class="tip">El marcado como base también aplica al <strong>revertir stock</strong> al anular una venta o al editar una venta existente — garantiza que se restaure exactamente la cantidad que se consumió originalmente.</div>
            <div class="warn"><strong>Limitación:</strong> Si cambias <code>es_base</code> de un ingrediente después de haber realizado ventas, la restauración de stock en ventas antiguas usará el valor actual de <code>es_base</code>, no el que existía cuando se realizó la venta. Se recomienda configurar <code>es_base</code> antes de comenzar a vender.</div>

            <div class="sub-title">Dar de baja stock terminado — 🎁 Regalar y 🗑 Desechar</div>
            <p>Cuando hay <code>stock_disponible &gt; 0</code>, cada tarjeta de producto muestra dos botones para bajar stock sin pasar por el POS:</p>
            <table class="data-table">
                <thead><tr><th>Acción</th><th>Cuándo usarla</th><th>Qué registra</th></tr></thead>
                <tbody>
                    <tr><td>🎁 Regalar</td><td>Muestra, cortesía, regalo a un cliente</td><td>Inserta en <code>ajustes_stock</code> con <code>tipo='obsequio'</code>. Reduce <code>stock_disponible</code>.</td></tr>
                    <tr><td>🗑 Desechar</td><td>Producto vencido, dañado, no apto para venta</td><td>Inserta en <code>ajustes_stock</code> con <code>tipo='desecho'</code>. Reduce <code>stock_disponible</code>.</td></tr>
                </tbody>
            </table>
            <div class="warn"><strong>Los insumos NO se devuelven al inventario.</strong> Ya fueron consumidos cuando se produjo el lote. Si quieres recuperar insumos, usa "Anular" en el módulo de Producción — pero solo si el lote completo no llegó a usarse.</div>
            <p>Ambas acciones quedan registradas con fecha, cantidad, motivo (opcional) y usuario. Puedes consultarlas en el <strong>Reporte Operativo → Obsequios y Desechos</strong>.</p>

            <div class="int-list">
                <span class="int-badge">Recibe datos de →</span>
                <span class="int-badge arrow">Insumos (costo_actual)</span>
                <span class="int-badge arrow">Costos (costo_fijo_u)</span>
                <span class="int-badge arrow">Activos (depreciación/u)</span>
                <span class="int-badge arrow">Nómina (RH/u)</span>
                <span class="int-badge arrow">Producción (stock_disponible)</span>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════ -->
        <!--  MÓDULO: PRODUCCIÓN                                           -->
        <!-- ══════════════════════════════════════════════════════════════ -->
        <div class="section" id="produccion">
            <div class="section-hdr">
                <div class="section-icon" style="background:#f0fdf4">&#129363;</div>
                <div><div class="section-title">Módulo: Registro de Producción</div></div>
            </div>
            <p>Registra las tandas de sándwiches producidos cada día. Al registrar una tanda, el sistema descuenta los insumos del inventario y suma al stock de producto terminado.</p>

            <div class="sub-title">Algoritmo de registro de tanda</div>
            <div class="formula-block">
<span class="title">registrar_lote.php — accion=crear (transacción PDO)</span>
Entrada: producto_id, cantidad N, fecha

Para cada ingrediente en la receta:
  descuento = (cantidad_requerida ÷ unidades_por_receta) × N
  UPDATE insumos SET stock_actual -= descuento
  SI stock < descuento → ERROR "Stock insuficiente" → ROLLBACK

UPDATE productos SET stock_disponible += N

INSERT produccion_lotes (producto_id, fecha, cantidad, costo_unitario)
  donde costo_unitario = productos.costo_calculado (snapshot del momento)</div>

            <div class="sub-title">Vista previa antes de confirmar</div>
            <p>El modal de "Registrar Producción" muestra en tiempo real qué insumos se descontarán y si hay suficiente stock. Los insumos con stock insuficiente aparecen en rojo ⚠.</p>

            <div class="sub-title">Anular una tanda</div>
            <div class="formula-block">
<span class="title">Reversa de una tanda (accion=anular)</span>
Para cada ingrediente:
  devolucion = (cantidad_requerida ÷ unidades_por_receta) × cantidad_tanda
  UPDATE insumos SET stock_actual += devolucion

UPDATE productos SET stock_disponible = GREATEST(0, stock_disponible - cantidad_tanda)
UPDATE produccion_lotes SET estado = 'anulado'</div>
            <div class="tip"><strong>Anular vs Desechar:</strong> Anular deshace la producción y <em>devuelve los insumos</em> al inventario. Úsalo si la tanda nunca se terminó de hacer. Desechar se usa cuando el producto YA estaba terminado pero está dañado o vencido — los insumos no vuelven.</div>

            <div class="sub-title">Desechar stock de producto terminado 🗑</div>
            <p>Cada tarjeta de stock terminado muestra el botón <strong>🗑 Desechar</strong> cuando <code>stock_disponible &gt; 0</code>. Úsalo para dar de baja unidades producidas que ya no son aptas para vender (vencidas, dañadas, contaminadas).</p>
            <div class="formula-block">
<span class="title">Flujo al desechar desde Producción</span>
INSERT en <span class="var">ajustes_stock</span> (tipo='desecho', producto_id, cantidad, motivo, created_by)
UPDATE <span class="var">productos</span> SET stock_disponible -= cantidad

<span class="comment">Los insumos NO se devuelven — ya fueron consumidos al producir el lote.
El registro queda trazable en Reporte Operativo → Obsequios y Desechos.</span></div>

            <div class="int-list">
                <span class="int-badge">Modifica →</span>
                <span class="int-badge arrow">Inventario (descuenta insumos al producir)</span>
                <span class="int-badge arrow">Productos (aumenta/reduce stock_disponible)</span>
                <span class="int-badge arrow">ajustes_stock (registro de desechos)</span>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════ -->
        <!--  MÓDULO: NÓMINA                                               -->
        <!-- ══════════════════════════════════════════════════════════════ -->
        <div class="section" id="nomina">
            <div class="section-hdr">
                <div class="section-icon" style="background:#eff6ff">&#128100;</div>
                <div><div class="section-title">Módulo: Nómina</div></div>
            </div>
            <p>Liquidación mensual de empleados con toda la carga prestacional según la legislación colombiana vigente. Soporta 4 tipos de contrato.</p>

            <div class="sub-title">Tipos de contrato</div>
            <table class="data-table">
                <tr><th>Tipo</th><th>Base de cálculo</th><th>Prestaciones</th><th>Aux. transporte</th></tr>
                <tr><td>Tiempo completo</td><td>Salario base</td><td>Todas</td><td>Si ≤ 2 SMLMV</td></tr>
                <tr><td>Medio tiempo</td><td>Salario × 50%</td><td>Proporcionales</td><td>Si aplica</td></tr>
                <tr><td>Por horas</td><td>valor_hora × horas</td><td>Proporcionales</td><td>Si aplica</td></tr>
                <tr><td>Por servicio</td><td>valor_proyecto fijo</td><td>Ninguna</td><td>No</td></tr>
            </table>

            <div class="sub-title">Fórmulas prestacionales (Colombia 2026)</div>
            <div class="formula-block">
<span class="title">Aportes a cargo del empleador (carga parafiscal)</span>
<span class="var">salud_empleador</span>      = salario_base × 8.5%
<span class="var">pension_empleador</span>    = salario_base × 12%
<span class="var">arl</span>                  = salario_base × 0.522%   <span class="comment">(Riesgo clase I)</span>
<span class="var">caja_compensacion</span>    = salario_base × 4%
<span class="var">icbf</span>                 = salario_base × 3%       <span class="comment">(exento si salario &gt; 10 SMLMV)</span>
<span class="var">sena</span>                 = salario_base × 2%       <span class="comment">(exento si salario &gt; 10 SMLMV)</span>

<span class="result">total_cargas</span>         = salud + pension + arl + caja + icbf + sena

<span class="title">Provisiones (prestaciones sociales)</span>
<span class="var">prima</span>                = salario_base × 8.33%
<span class="var">cesantias</span>            = salario_base × 8.33%
<span class="var">intereses_cesantias</span>  = salario_base × 1%
<span class="var">vacaciones</span>           = salario_base × 4.17%

<span class="result">total_provisiones</span>    = prima + cesantias + intereses_cesantias + vacaciones

<span class="title">Costo real del empleado para el negocio</span>
<span class="result">costo_total_empleador</span> = salario_base + aux_transporte + total_cargas + total_provisiones</div>

            <div class="sub-title">Auxilio de transporte — proporcionalidad (Circ. Min. Trabajo 0058/2015)</div>
            <div class="formula-block">
<span class="title">El auxilio es proporcional al tiempo trabajado</span>
Tiempo completo:  aux = $249.095 completo      <span class="comment">(jornada completa)</span>
Medio tiempo:     aux = $249.095 × 50%         <span class="comment">(media jornada)</span>
Por horas:        aux = $249.095 × (horas_trabajadas / horas_mes_legal)
                      = $249.095 × (horas / 191.18)
Por servicio:     aux = $0                      <span class="comment">(contratista sin relación laboral)</span>

<span class="comment">Solo aplica si salario_efectivo ≤ 2 SMLMV ($3.501.810 en 2026)</span></div>

            <div class="sub-title">Cálculo de valor por hora (contratos por horas)</div>
            <div class="formula-block">
<span class="title">Jornada legal — Ley 2101/2021 Colombia</span>
<span class="comment">Las horas mensuales las define el GOBIERNO, no el empleado ni el empleador.</span>

SI empleado tiene horas_semana y periodo='semana':
  <span class="var">horas_mes</span> = horas_semana × 52.14 ÷ 12

SI empleado tiene horas_semana y periodo='mes':
  <span class="var">horas_mes</span> = horas_semana

SI no tiene horas definidas (NULL):
  <span class="var">horas_mes</span> = parámetro global 'horas_jornada_valor'
           = 44h/sem × 52.14/12 = <span class="result">191.18 h/mes</span>  (Colombia 2026)

<span class="result">valor_hora</span> = salario_base ÷ horas_mes

<span class="comment">Si el gobierno cambia el tope → actualizar en Nómina → Parámetros
y todos los contratos por horas se recalculan automáticamente.</span></div>

            <div class="sub-title">Recargos por tipo de hora (Art. 168-172 CST)</div>
            <table class="data-table">
                <tr><th>Tipo de hora</th><th>Multiplicador</th><th>Horario</th></tr>
                <tr><td>Ordinaria</td><td>× 1.00</td><td>Horario laboral normal</td></tr>
                <tr><td>Recargo nocturno</td><td>× 1.35</td><td>9pm — 6am</td></tr>
                <tr><td>Extra diurna</td><td>× 1.25</td><td>Horas extra en día hábil</td></tr>
                <tr><td>Extra nocturna</td><td>× 1.75</td><td>Horas extra en la noche</td></tr>
                <tr><td>Festiva ordinaria</td><td>× 1.75</td><td>Domingo / festivo en horario normal</td></tr>
                <tr><td>Extra festiva diurna</td><td>× 2.00</td><td>Horas extra en festivo (día)</td></tr>
                <tr><td>Extra festiva nocturna</td><td>× 2.50</td><td>Horas extra en festivo (noche)</td></tr>
            </table>

            <div class="sub-title">Clasificación tipo_costo del empleado</div>
            <p>Cada empleado tiene un campo <strong>tipo_costo</strong>:</p>
            <ul>
                <li><strong>Directo:</strong> Produce el producto (cocina, preparación). Su costo aparece en "Nómina directa" en el módulo Costos.</li>
                <li><strong>Indirecto:</strong> Administración, servicio, ventas. Aparece en "Nómina indirecta".</li>
            </ul>

            <div class="int-list">
                <span class="int-badge">Alimenta →</span>
                <span class="int-badge arrow">Costos (KPI Nómina directa/indirecta)</span>
                <span class="int-badge arrow">Productos (costo_rh_u)</span>
                <span class="int-badge arrow">Dashboard (métricas del mes)</span>
                <span class="int-badge arrow">Reportes (nómina Excel)</span>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════ -->
        <!--  MÓDULO: ACTIVOS                                              -->
        <!-- ══════════════════════════════════════════════════════════════ -->
        <div class="section" id="activos">
            <div class="section-hdr">
                <div class="section-icon" style="background:#fff7ed">&#129520;</div>
                <div><div class="section-title">Módulo: Activos Fijos</div></div>
            </div>
            <p>Control del patrimonio físico del negocio (equipos, herramientas, muebles) con depreciación automática por línea recta.</p>

            <div class="sub-title">Ciclo de vida de un activo</div>
            <table class="data-table">
                <tr><th>Estado</th><th>Significado</th><th>Dep. diaria</th></tr>
                <tr><td>en_espera</td><td>Comprado pero aún no en uso</td><td>$0</td></tr>
                <tr><td>nuevo</td><td>En uso, excelente condición</td><td>Calculada</td></tr>
                <tr><td>medio</td><td>En uso, desgaste normal</td><td>Calculada</td></tr>
                <tr><td>critico</td><td>Cerca del fin de vida útil</td><td>Calculada</td></tr>
                <tr><td>depreciado</td><td>Vida útil agotada</td><td><strong>$0</strong></td></tr>
            </table>

            <div class="sub-title">Fórmula de depreciación (línea recta)</div>
            <div class="formula-block">
<span class="title">Trigger automático trg_activos_deprec_insert / trg_activos_deprec_update</span>
<span class="var">costo_inicial</span>         = numero_unidades × precio_unitario

<span class="result">depreciacion_mensual</span>  = costo_inicial ÷ vida_util_meses
<span class="result">depreciacion_diaria</span>   = depreciacion_mensual ÷ 30.41666

<span class="comment">La fecha que inicia la depreciación es fecha_inicio_uso
(no fecha_adquisicion — pueden ser distintas).</span>

<span class="var">meses_transcurridos</span>   = TIMESTAMPDIFF(MONTH, fecha_inicio_uso, CURDATE())
<span class="result">valor_en_libros</span>       = costo_inicial - (depreciacion_mensual × meses_desde_COMPRA)

<span class="comment">IMPORTANTE: valor_en_libros usa fecha_ADQUISICION (no fecha_inicio_uso).
El activo pierde valor contable desde que se COMPRA, aunque no esté en uso aún.
La depreciación OPERATIVA (costo diario) solo corre desde fecha_inicio_uso.</span>

<span class="comment">Nota de seguridad: CAST(vida_util_meses AS SIGNED) previene overflow
BIGINT UNSIGNED en MySQL modo estricto cuando vida_util_meses es TINYINT.</span></div>

            <div class="int-list">
                <span class="int-badge">Alimenta →</span>
                <span class="int-badge arrow">Productos (costo_deprec_u)</span>
                <span class="int-badge arrow">Costos KPI (Depreciación activos)</span>
                <span class="int-badge arrow">Reportes Operativo (hoja Activos)</span>
                <span class="int-badge arrow">Dashboard (dep. diaria total)</span>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════ -->
        <!--  MÓDULO: COSTOS                                               -->
        <!-- ══════════════════════════════════════════════════════════════ -->
        <div class="section" id="costos">
            <div class="section-hdr">
                <div class="section-icon" style="background:#fdf4ff">&#128176;</div>
                <div><div class="section-title">Módulo: Costos</div></div>
            </div>
            <p>Panel consolidado de todos los costos del negocio con selector de período. Alimenta el cálculo de margen en el módulo Productos.</p>

            <div class="sub-title">Tarjetas KPI y su fuente de datos</div>
            <table class="data-table">
                <tr><th>Tarjeta</th><th>Tabla fuente</th><th>Filtro de período</th></tr>
                <tr><td>Total costos</td><td>costos_indirectos</td><td>fecha_inicio ≤ fin_mes AND (fecha_fin IS NULL OR fecha_fin ≥ ini_mes)</td></tr>
                <tr><td>Costos directos</td><td>costos_indirectos</td><td>Mismo + clasificacion='directo'</td></tr>
                <tr><td>Costos indirectos</td><td>costos_indirectos</td><td>Mismo + clasificacion='indirecto'</td></tr>
                <tr><td>Compras</td><td>compras</td><td>fecha_compra BETWEEN ini AND fin</td></tr>
                <tr><td>Depreciación</td><td>activos</td><td>estado_vida ≠ 'depreciado' AND fecha_inicio_uso ≤ fin_mes</td></tr>
                <tr><td>Nómina directa</td><td>nomina_liquidaciones + empleados</td><td>periodo_mes + periodo_anio (o salario_base si no hay liquidaciones)</td></tr>
                <tr><td>Nómina indirecta</td><td>Ídem</td><td>Ídem, tipo_costo ≠ 'directo'</td></tr>
                <tr><td>Gran total</td><td>Todos los anteriores</td><td>Suma aritmética</td></tr>
            </table>

            <div class="sub-title">Clasificación de costos</div>
            <table class="data-table">
                <tr><th>Clasificación</th><th>Definición</th><th>Ejemplos</th></tr>
                <tr><td>Directo</td><td>Trazable a un producto específico</td><td>Empaques, gas cocina, insumos de limpieza de equipos</td></tr>
                <tr><td>Indirecto</td><td>Costo general del negocio</td><td>Arriendo, servicios públicos, internet, seguros</td></tr>
            </table>

            <div class="warn"><strong>Nota:</strong> Una vez que registres costos en este módulo, el margen de productos se recalcula automáticamente usando estos valores en lugar del estimado de configuracion_negocio.</div>

            <div class="int-list">
                <span class="int-badge">Recibe de →</span>
                <span class="int-badge arrow">Compras</span>
                <span class="int-badge arrow">Activos (depreciación)</span>
                <span class="int-badge arrow">Nómina (tipo_costo)</span>
                <span class="int-badge">Alimenta →</span>
                <span class="int-badge arrow">Productos (margen real)</span>
                <span class="int-badge arrow">Dashboard</span>
                <span class="int-badge arrow">Reportes Costos</span>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════ -->
        <!--  MÓDULO: REPORTES                                             -->
        <!-- ══════════════════════════════════════════════════════════════ -->
        <div class="section" id="reportes">
            <div class="section-hdr">
                <div class="section-icon" style="background:#eff6ff">&#128202;</div>
                <div><div class="section-title">Módulo: Reportes</div></div>
            </div>
            <p>5 reportes especializados exportables a Excel (.xlsx) con múltiples hojas. El botón "Exportar Excel" agrega <code>?export=1</code> a la URL actual con todos los filtros aplicados.</p>

            <div class="sub-title">Reportes disponibles</div>
            <table class="data-table">
                <tr><th>Reporte</th><th>Hojas Excel</th><th>Filtros</th><th>Permiso</th></tr>
                <tr><td>Ventas & Rentabilidad</td><td>Ventas + Rentabilidad</td><td>Fecha desde/hasta</td><td>ventas</td></tr>
                <tr><td>Inventario, Producción & Activos</td><td>Inventario + Stock Terminado + Producción + Activos</td><td>Mes / Año</td><td>inventario</td></tr>
                <tr><td>Nómina y Costo Laboral</td><td>Nómina [mes] + Resumen</td><td>Mes / Año</td><td>nomina</td></tr>
                <tr><td>Costos del Negocio</td><td>Resumen + Costos Registrados + Compras/Proveedor</td><td>Mes / Año</td><td>costos</td></tr>
                <tr><td>Compras & Proveedores</td><td>Historial + Por Proveedor + Por Insumo</td><td>Fecha desde/hasta</td><td>compras</td></tr>
                <tr><td>Reporte Operativo</td><td>Inventario + Producción + Ventas + Activos + <strong>Obsequios y Desechos</strong></td><td>Mes / Año</td><td>admin_total</td></tr>
                <tr><td>Variación de Precios y Costos</td><td>Insumos (precio/compra) + Productos (precio venta + costo producción) + Nómina (salario/período) + Costos Fijos (vigencias)</td><td>Fecha desde/hasta</td><td>reportes</td></tr>
            </table>

            <div class="sub-title">Reporte Ventas — tratamiento de obsequios</div>
            <p>Las ventas con <code>metodo_pago='obsequio'</code> aparecen en el listado del reporte (para trazabilidad) pero están <strong>excluidas de todos los totales de ingresos</strong>:</p>
            <table class="data-table">
                <thead><tr><th>KPI / columna</th><th>¿Incluye obsequios?</th></tr></thead>
                <tbody>
                    <tr><td>Total ventas (N°)</td><td>No — solo cuenta ventas pagadas</td></tr>
                    <tr><td>Total ingresos ($)</td><td>No — excluye obsequios</td></tr>
                    <tr><td>Efectivo / Digital / Fiado</td><td>No — obsequio tiene su propio contador</td></tr>
                    <tr><td>Obsequios (N° / valor)</td><td>Sí — tarjeta propia en rosa</td></tr>
                    <tr><td>Excel: total_pesos</td><td>No — columna marcada "sin obsequios"</td></tr>
                    <tr><td>Excel: fila de totales por método</td><td>Obsequio separado con nota "(no es ingreso)"</td></tr>
                </tbody>
            </table>

            <div class="sub-title">Reporte Operativo — Obsequios y Desechos</div>
            <p>La sección final del Reporte Operativo (y su hoja Excel) consolida dos fuentes de datos:</p>
            <table class="data-table">
                <thead><tr><th>Subsección</th><th>Fuente</th><th>Qué muestra</th></tr></thead>
                <tbody>
                    <tr><td>A — Obsequios POS</td><td>tabla <code>ventas</code></td><td>Ventas con metodo_pago='obsequio': producto, unidades, valor de mercado, fecha</td></tr>
                    <tr><td>B — Ajustes de stock</td><td>tabla <code>ajustes_stock</code></td><td>Registros de Regalar / Desechar desde módulos Productos y Producción: tipo, producto, cantidad, motivo, usuario</td></tr>
                </tbody>
            </table>
            <div class="tip">La hoja Excel "Obsequios y Desechos" solo se crea si hay al menos un registro en el período seleccionado.</div>

            <div class="tip"><strong>Solo veo algunos reportes:</strong> El sistema muestra solo los reportes de módulos a los que tienes acceso. Pide al administrador que te asigne el permiso del módulo correspondiente.</div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════ -->
        <!--  MÓDULO: ADMIN                                                -->
        <!-- ══════════════════════════════════════════════════════════════ -->
        <div class="section" id="admin">
            <div class="section-hdr">
                <div class="section-icon" style="background:#fef2f0">&#9881;&#65039;</div>
                <div><div class="section-title">Módulo: Administración</div></div>
            </div>
            <p>Panel exclusivo para superadmin y admin. Gestión completa del sistema.</p>

            <div class="sub-title">Secciones del módulo Admin</div>
            <table class="data-table">
                <tr><th>Sección</th><th>Función</th></tr>
                <tr><td>Resumen</td><td>Dashboard del sistema: usuarios activos, ventas hoy, últimos cambios en tiempo real.</td></tr>
                <tr><td>Usuarios</td><td>Crear/editar usuarios, asignar rol y permisos por módulo (matriz interactiva)</td></tr>
                <tr><td>Apariencia</td><td>Nombre del negocio, logo (upload), color brand, color dark, fuente, radio de bordes. Vista previa en tiempo real.</td></tr>
                <tr><td>Catálogos</td><td>Gestionar opciones de dropdowns: presentaciones, unidades, categorías de insumo/producto/activo/costo/proveedor y tamaños</td></tr>
                <tr><td>Base de Datos</td><td>4 funciones: backup SQL, backup código ZIP, ejecutar migración .sql, aplicar actualización .zip</td></tr>
            </table>

            <div class="sub-title">Sistema de permisos</div>
            <table class="data-table">
                <tr><th>Nivel</th><th>Acceso</th></tr>
                <tr><td>sin_acceso</td><td>No ve ni accede al módulo</td></tr>
                <tr><td>solo_ver</td><td>Puede ver pero no crear ni editar</td></tr>
                <tr><td>solo_propios</td><td>Ve y edita solo sus propios registros</td></tr>
                <tr><td>editar_existentes</td><td>Puede crear y editar cualquier registro</td></tr>
                <tr><td>admin_total</td><td>Control total: crear, editar, eliminar, anular, acceder a configuración del módulo</td></tr>
            </table>

            <div class="tip"><strong>Rol Superadmin:</strong> Bypassa toda la tabla de permisos. Tiene admin_total en todos los módulos automáticamente, sin necesidad de registros en permisos_modulos.</div>

            <div class="sub-title">Los 9 módulos configurables (matriz de permisos)</div>
            <p>La matriz de Admin → Usuarios permite asignar, <strong>por usuario individual</strong>, uno de los 5 niveles de la tabla anterior para cada uno de estos módulos (columna <code>modulo</code> de <code>permisos_modulos</code>, ENUM de 9 valores):</p>
            <p style="font-size:13px;line-height:1.8">
                <code>ventas</code> · <code>inventario</code> · <code>proveedores</code> · <code>compras</code> ·
                <code>productos</code> · <code>nomina</code> · <code>activos</code> · <code>costos</code> · <code>reportes</code>
            </p>

            <div class="sub-title">Casos especiales — fuera de la matriz</div>
            <p>No todo el sistema se controla mediante <code>permisos_modulos</code>. Tres excepciones documentadas:</p>
            <table class="data-table">
                <thead><tr><th>Caso</th><th>Cómo se controla</th><th>Por qué</th></tr></thead>
                <tbody>
                    <tr><td><strong>Clientes</strong></td><td>Comparte el permiso de <code>ventas</code> (no tiene fila ni ENUM propio)</td><td>El módulo de clientes nació como una extensión natural del flujo de ventas/fiado — separar su permiso duplicaría configuración sin necesidad real</td></tr>
                    <tr><td><strong>Admin / Ayuda</strong></td><td>Verificación directa de <code>$_SESSION['usuario_rol']</code> (solo <code>admin</code>/<code>superadmin</code> entran a Admin; Ayuda es para todos los autenticados)</td><td>Son paneles de configuración del sistema y documentación — no datos operativos del negocio que ameriten gradación por niveles</td></tr>
                    <tr><td><strong>Tarjetas sensibles del Dashboard</strong><br>("Rendimiento de Cajeros" v4.59, "Productos Más Rentables" v4.68)</td><td>Verificación directa de rol — visibles <strong>solo</strong> para <code>admin</code>/<code>superadmin</code>, sin importar el nivel asignado en la matriz para ventas/productos</td><td>Comparar el desempeño de compañeros o exponer márgenes de ganancia (<code>costo_calculado</code>) es información financiera y de personal sensible que no debería filtrarse al personal operativo aunque tenga <code>admin_total</code> en su módulo</td></tr>
                </tbody>
            </table>
            <div class="warn"><strong>Importante al auditar permisos:</strong> un usuario con <code>admin_total</code> en "ventas" o "productos" <em>no</em> verá automáticamente las tarjetas de Cajeros o Rentabilidad en el Dashboard — esas dos requieren además el <em>rol</em> <code>admin</code>/<code>superadmin</code>. Si necesitas que un empleado de confianza vea esos datos, la única forma es asignarle el rol "Admin" desde Usuarios (lo cual le da además acceso al panel de Administración completo).</div>

            <div class="sub-title">Catálogos configurables (Admin → Catálogos)</div>
            <p>Permite agregar, editar y desactivar las opciones que aparecen en los desplegables de los módulos sin necesidad de modificar código.</p>
            <table class="data-table">
                <thead><tr><th>Catálogo</th><th>Módulo donde se usa</th><th>Ejemplos de valores</th></tr></thead>
                <tbody>
                    <tr><td>Presentaciones de Insumos</td><td>Inventario</td><td>Frasco, Paca, Caja, Tarro…</td></tr>
                    <tr><td>Unidades de Medida</td><td>Inventario</td><td>kg, g, litro, ml, Unidades…</td></tr>
                    <tr><td>Categorías de Insumos</td><td>Inventario</td><td>Proteína, Lácteo, Vegetal, Condimento…</td></tr>
                    <tr><td>Categorías de Productos</td><td>Productos</td><td>Sándwich, Combo, Bebida, Adicional…</td></tr>
                    <tr><td>Tamaños de Productos</td><td>Productos</td><td>XL, L, Único… (agrega nuevas tallas libremente)</td></tr>
                    <tr><td>Categorías de Activos Fijos</td><td>Activos</td><td>Equipo de cocina, Mobiliario, Vehículo…</td></tr>
                    <tr><td>Categorías de Costos</td><td>Costos</td><td>Arriendo, Servicios Públicos, Seguros…</td></tr>
                    <tr><td>Categorías de Proveedores</td><td>Proveedores</td><td>Plaza, Tienda, Mayorista, Panadería…</td></tr>
                </tbody>
            </table>
            <div class="tip"><strong>Valor vs Etiqueta:</strong> El <em>Valor</em> es el identificador interno almacenado en la BD (ej: <code>equipo_cocina</code>) y no puede cambiarse después de creado. La <em>Etiqueta</em> es el texto que ve el usuario (ej: "Equipo de cocina") y puede editarse libremente.</div>
            <div class="warn"><strong>Desactivar no elimina.</strong> Al desactivar una opción deja de aparecer en nuevos formularios, pero todos los registros históricos que la usaban siguen siendo válidos.</div>

            <div class="sub-title">Copias de seguridad</div>
            <table class="data-table">
                <thead><tr><th>Tipo</th><th>Qué incluye</th><th>Para qué sirve</th></tr></thead>
                <tbody>
                    <tr><td>Backup BD SQL</td><td>Todas las tablas y datos. <code>DROP+CREATE+INSERT</code> en bloques de 500 filas.</td><td>Restaurar la base de datos completa en phpMyAdmin</td></tr>
                    <tr><td>Backup código ZIP</td><td>Todos los archivos PHP. <strong>Excluye</strong> <code>uploads/</code> y <code>app/config/</code>.</td><td>Guardar el estado del código para rollback ante un update fallido</td></tr>
                </tbody>
            </table>

            <div class="sub-title">Actualizaciones del sistema</div>
            <table class="data-table">
                <thead><tr><th>Tipo</th><th>Archivo</th><th>Qué hace</th></tr></thead>
                <tbody>
                    <tr><td>Migración SQL</td><td><code>.sql</code> (máx. 5 MB)</td><td>Ejecuta cada sentencia SQL. Útil para cambios de esquema (ALTER TABLE, CREATE TABLE) o inserts de datos.</td></tr>
                    <tr><td>Actualización código</td><td><code>.zip</code> (máx. 50 MB)</td><td>Extrae los archivos sobre <code>public_html/</code>. Protege automáticamente <code>database.php</code>, <code>app.php</code> y <code>uploads/</code> — nunca se sobreescriben.</td></tr>
                </tbody>
            </table>
            <div class="warn"><strong>Siempre descarga un backup antes de ejecutar una migración o una actualización de código.</strong> Los errores en este nivel son difíciles de revertir manualmente.</div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════ -->
        <!--  SECCIÓN: ANÁLISIS DE RENTABILIDAD                           -->
        <!-- ══════════════════════════════════════════════════════════════ -->
        <div class="section" id="analisis">
            <div class="section-hdr">
                <div class="section-icon" style="background:#eff6ff">&#128202;</div>
                <div><div class="section-title">Análisis de Rentabilidad</div></div>
            </div>
            <p>Página disponible en <strong>Productos → Ver análisis →</strong> (<code>productos/analisis.php</code>). Consolida producción, costos, ventas históricas y punto de equilibrio.</p>

            <div class="sub-title">Calculadora bidireccional de receta</div>
            <p>Dentro de cada producto expandido hay una calculadora interactiva:</p>
            <ul>
                <li><strong>→</strong> Ingresar número de sándwiches a producir → muestra cantidades de ingredientes escaladas</li>
                <li><strong>←</strong> Ingresar cantidad disponible de un ingrediente → calcula cuántos sándwiches se pueden hacer</li>
            </ul>

            <div class="sub-title">Costeo real vs estimado</div>
            <div class="formula-block">
<span class="title">Dos formas de calcular el costo por unidad</span>
<span class="comment">ESTIMADO (base: capacidad instalada configurada)</span>
costo_fijo/u_estimado = total_costos_fijos / produccion_estimada_mensual

<span class="comment">REAL (base: producción real del período)</span>
costo_fijo/u_real     = total_costos_fijos / SUM(produccion_lotes.cantidad) del período

<span class="comment">Fuentes de costos fijos:
  - costos_indirectos (módulo Costos)
  - activos.depreciacion_mensual
  - nomina_liquidaciones donde tipo_costo='indirecto'</span></div>

            <div class="sub-title">Punto de equilibrio (PE)</div>
            <div class="formula-block">
<span class="title">Definición: unidades que cubren exactamente todos los costos fijos</span>
<span class="var">margen_contribucion</span> = precio_venta - costo_ingredientes - nómina_directa/u
<span class="result">PE_unidades</span>         = costos_fijos_totales / margen_contribucion
<span class="result">PE_pesos</span>            = PE_unidades × precio_venta_promedio

<span class="comment">Si unidades_vendidas > PE → ganancia
Si unidades_vendidas < PE → pérdida
La barra de progreso muestra el % del PE alcanzado en el mes</span></div>

            <div class="sub-title">Análisis histórico y sugerencia de producción</div>
            <p>Basado en los últimos <strong>90 días</strong> de ventas:</p>
            <div class="formula-block">
<span class="var">promedio_diario</span>         = total_vendido_90_días / días_con_ventas
<span class="var">proyeccion_mensual</span>       = promedio_diario × 21.75 días hábiles
<span class="result">produccion_sugerida/día</span> = promedio_diario × 1.15  <span class="comment">(+15% buffer)</span></div>
            <div class="tip"><strong>Capacidad instalada editable:</strong> El campo en la parte superior de Productos permite actualizar la capacidad estimada. Al guardar, todos los márgenes se recalculan automáticamente.</div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════ -->
        <!--  SECCIÓN: SEGURIDAD                                           -->
        <!-- ══════════════════════════════════════════════════════════════ -->
        <div class="section" id="seguridad">
            <div class="section-hdr">
                <div class="section-icon" style="background:#fff7ed">&#128274;</div>
                <div><div class="section-title">Seguridad del Sistema</div></div>
            </div>
            <p>El sistema implementa múltiples capas de seguridad. Aquí se documenta lo que se protege y cómo.</p>

            <div class="sub-title">Protecciones implementadas</div>
            <table class="data-table">
                <tr><th>Amenaza</th><th>Protección</th><th>Dónde</th></tr>
                <tr><td>SQL Injection</td><td>PDO prepared statements en TODAS las queries — nunca interpolación directa</td><td>Todo el sistema</td></tr>
                <tr><td>XSS (Cross-Site Scripting)</td><td><code>htmlspecialchars()</code> en toda salida HTML; validación hex en colores CSS</td><td>Todas las vistas</td></tr>
                <tr><td>CSRF</td><td>Token de sesión validado en todos los POST con <code>csrf_verificar()</code></td><td>Todos los APIs</td></tr>
                <tr><td>Path Traversal</td><td>Rutas de logo validadas: deben comenzar con <code>uploads/logos/</code></td><td>guardar_apariencia.php</td></tr>
                <tr><td>CSS Injection</td><td>Colores validados con regex <code>/^#[0-9a-fA-F]{6}$/</code> antes de inyectar en &lt;style&gt;</td><td>nav.php</td></tr>
                <tr><td>Race Condition en stock</td><td><code>SELECT ... FOR UPDATE</code> dentro de transacción PDO</td><td>VentaModel::crear()</td></tr>
                <tr><td>Brute Force Login</td><td>Rate limiting: máx 5 intentos, bloqueo 15 minutos (tabla login_intentos)</td><td>auth_login()</td></tr>
                <tr><td>Contraseñas débiles</td><td>bcrypt COST=12, mínimo 8 caracteres</td><td>usuario_crud.php</td></tr>
                <tr><td>Upload de archivos maliciosos</td><td>Validación MIME real con <code>finfo</code>, directorio con .htaccess que bloquea PHP</td><td>guardar_apariencia.php</td></tr>
                <tr><td>Acceso no autorizado</td><td><code>permiso_requerir()</code> al inicio de cada página; superadmin bypassea permisos granulares</td><td>Todas las páginas</td></tr>
            </table>

            <div class="sub-title">Prácticas de seguridad para administradores</div>
            <ul>
                <li>Cambiar la contraseña del superadmin (<code>admin@clandestino.local</code>) en el primer login</li>
                <li>Usar contraseñas de mínimo 12 caracteres con combinación de letras, números y símbolos</li>
                <li>Hacer backup de la BD antes de ejecutar cualquier migración desde el panel Admin</li>
                <li>Los logos se guardan en <code>uploads/logos/</code> — no subir archivos PHP a ese directorio</li>
                <li>El archivo <code>.htaccess</code> en <code>uploads/</code> bloquea ejecución PHP — no eliminar</li>
            </ul>
            <div class="warn"><strong>Nunca</strong> pongas en producción con <code>APP_ENV = 'development'</code> — expone errores PHP al usuario.</div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════ -->
        <!--  SECCIÓN: BASE DE DATOS — ESTRUCTURA TÉCNICA                  -->
        <!-- ══════════════════════════════════════════════════════════════ -->
        <div class="section" id="bd-tecnica">
            <div class="section-hdr">
                <div class="section-icon" style="background:#f0fdf4">&#128209;</div>
                <div>
                    <div class="section-title">Base de Datos — Estructura Técnica v4.22</div>
                    <div class="section-badge">27 tablas · MariaDB 10.11 · Migraciones 001-034</div>
                </div>
            </div>
            <p>El sistema usa MariaDB/MySQL con InnoDB. Las migraciones se ejecutan en orden desde <strong>Admin → Base de Datos → Ejecutar Migración</strong>. El código detecta automáticamente cuáles están aplicadas usando <code>information_schema.COLUMNS</code>.</p>

            <div class="sub-title">Tablas y su propósito</div>
            <table class="data-table">
                <thead><tr><th>Tabla</th><th>Propósito</th><th>Migraciones</th></tr></thead>
                <tbody>
                    <tr><td><code>usuarios</code></td><td>Cuentas del sistema con roles (superadmin/admin/empleado)</td><td>base</td></tr>
                    <tr><td><code>permisos_modulos</code></td><td>Permisos granulares: sin_acceso → admin_total por módulo</td><td>base + 003 + 011 + 012</td></tr>
                    <tr><td><code>login_intentos</code></td><td>Rate-limiting anti fuerza bruta</td><td>002</td></tr>
                    <tr><td><code>configuracion_negocio</code></td><td>Parámetros numéricos: SMLMV, aux. transporte, % parafiscales</td><td>base + 003</td></tr>
                    <tr><td><code>configuracion_app</code></td><td>Tema visual: colores, fuentes, logos, radio de bordes</td><td>016 + 016b + 016c</td></tr>
                    <tr><td><code>proveedores</code></td><td>Directorio de proveedores con categoría y contacto</td><td>base + 003 + 011</td></tr>
                    <tr><td><code>insumos</code></td><td>Inventario de materias primas. Incluye presentación, equivalencia física (030) y unidades como VARCHAR (031)</td><td>base + 003 + 010 + 030 + 031</td></tr>
                    <tr><td><code>productos</code></td><td>Carta de productos con receta, nombre2 (027), stock terminado (015), categoría/tamaño como VARCHAR (031)</td><td>base + 015 + 027 + 031</td></tr>
                    <tr><td><code>recetas</code></td><td>Ingredientes requeridos por producto. <code>es_base</code>: cantidad fija, no escala con variante (mig.036)</td><td>base + 036</td></tr>
                    <tr><td><code>combo_configs / combo_insumos</code></td><td>Configuración de combos: producto + insumos extras por combo</td><td>025</td></tr>
                    <tr><td><code>producto_variantes</code></td><td>Variantes de tamaño por producto (etiqueta, precio, factor_receta). Sin FK (cPanel errno 121)</td><td>035</td></tr>
                    <tr><td><code>clientes</code></td><td>Clientes con saldo fiado, apellido (028) y empresa (028)</td><td>base + 028</td></tr>
                    <tr><td><code>ventas</code></td><td>Cabecera POS. metodo_pago incluye 'obsequio' (026)</td><td>base + 003 + 026</td></tr>
                    <tr><td><code>venta_detalles</code></td><td>Líneas de venta. <strong>Snapshot completo:</strong> precio_unitario + nombre_snap + nombre2_snap</td><td>base + 003 + 025 + 034</td></tr>
                    <tr><td><code>pagos_fiado</code></td><td>Abonos. <strong>Snapshot:</strong> saldo_anterior y saldo_posterior del cliente (034)</td><td>base + 034</td></tr>
                    <tr><td><code>compras</code></td><td>Cabecera de compras de insumos</td><td>base + 003</td></tr>
                    <tr><td><code>compra_detalles</code></td><td>Líneas de compra. <strong>Snapshot completo:</strong> precio_unitario + nombre_snap + presentacion + precio_presentacion</td><td>base + 032 + 034</td></tr>
                    <tr><td><code>produccion_lotes</code></td><td>Tandas producidas. <strong>Snapshot:</strong> costo_unitario + nombre_snap</td><td>015 + 034</td></tr>
                    <tr><td><code>ajustes_stock</code></td><td>Obsequios y desechos de stock terminado (tipo=obsequio|desecho)</td><td>026</td></tr>
                    <tr><td><code>empleados</code></td><td>Personal con tipo_contrato, valor_hora, tipo_costo (directo/indirecto)</td><td>base + 003 + 007 + 009 + 014</td></tr>
                    <tr><td><code>registro_horas</code></td><td>Horas diarias por empleado (por_horas). Incluye tipo_hora y es_festivo</td><td>007 + 008</td></tr>
                    <tr><td><code>nomina_liquidaciones</code></td><td>Snapshot completo mensual. Incluye valor_hora_snap y valor_proyecto_snap (033)</td><td>base + 003 + 007 + 008 + 033</td></tr>
                    <tr><td><code>parametros_laborales</code></td><td>Parámetros laborales configurables por país (Colombia 2026)</td><td>007</td></tr>
                    <tr><td><code>activos</code></td><td>Equipos con depreciación automática. categoria_activo como VARCHAR (031)</td><td>base + 005 + 006 + 017 + 018 + 031</td></tr>
                    <tr><td><code>costos_indirectos</code></td><td>Costos por períodos de vigencia. Cada registro es inmutable</td><td>012 + 013</td></tr>
                    <tr><td><code>listas_sistema</code></td><td>Catálogos dinámicos de dropdowns (presentación, unidades, categorías…)</td><td>029 + 029b</td></tr>
                    <tr><td><code>logs_historial</code></td><td>Auditoría completa. Columna timestamp = <code>fecha_cambio</code></td><td>base</td></tr>
                </tbody>
            </table>

            <div class="sub-title">Migraciones 031-034 — Resumen de cambios recientes</div>
            <table class="data-table">
                <thead><tr><th>Migración</th><th>Estado</th><th>Qué agrega</th></tr></thead>
                <tbody>
                    <tr>
                        <td><strong>031</strong></td>
                        <td><span style="color:#065f46;background:#d1fae5;padding:2px 8px;border-radius:8px;font-size:11px;font-weight:700">APLICADA</span></td>
                        <td>Convierte 5 ENUMs → VARCHAR: <code>productos.categoria/tamano</code>, <code>insumos.unidad_medida/presentacion</code>, <code>activos.categoria_activo</code>. Habilita catálogos personalizados desde Admin → Catálogos.</td>
                    </tr>
                    <tr>
                        <td><strong>032</strong></td>
                        <td><span style="color:#065f46;background:#d1fae5;padding:2px 8px;border-radius:8px;font-size:11px;font-weight:700">APLICADA</span></td>
                        <td>Agrega 4 columnas snapshot de empaque a <code>compra_detalles</code>: <code>presentacion</code>, <code>cantidad_presentacion</code>, <code>cant_presentaciones</code>, <code>precio_presentacion</code>. Permite registrar "2 pacas a $29.000/paca" y no solo "24 unidades a $2.416/u".</td>
                    </tr>
                    <tr>
                        <td><strong>033</strong></td>
                        <td><span style="color:#065f46;background:#d1fae5;padding:2px 8px;border-radius:8px;font-size:11px;font-weight:700">APLICADA</span></td>
                        <td>Agrega 2 columnas snapshot a <code>nomina_liquidaciones</code>: <code>valor_hora_snap</code> (tarifa/hora usada al liquidar) y <code>valor_proyecto_snap</code> (valor del proyecto para <code>por_servicio</code>). Permite auditar qué tarifa se usó aunque el empleado cambie de tarifa después.</td>
                    </tr>
                    <tr>
                        <td><strong>034</strong></td>
                        <td><span style="color:#065f46;background:#d1fae5;padding:2px 8px;border-radius:8px;font-size:11px;font-weight:700">APLICADA</span></td>
                        <td>Agrega snapshots de <strong>nombres</strong> y <strong>contexto financiero</strong>: <code>venta_detalles.nombre_snap/nombre2_snap</code>, <code>compra_detalles.nombre_snap/unidad_snap</code>, <code>produccion_lotes.nombre_snap</code>, <code>pagos_fiado.saldo_anterior/saldo_posterior</code>.</td>
                    </tr>
                </tbody>
            </table>
            <div class="tip">El código detecta automáticamente si estas migraciones están aplicadas antes de intentar guardar los campos. Si no están aplicadas, el sistema sigue funcionando normalmente sin los snapshots adicionales.</div>

            <div class="sub-title">Política de inmutabilidad histórica extendida</div>
            <p>El sistema preserva <strong>precios, nombres y contexto completo</strong> al momento de cada transacción. Esto garantiza que aunque renombres un producto, cambies la tarifa de un empleado o corrijas un precio, el historial conserva los datos originales.</p>
            <table class="data-table">
                <thead><tr><th>Tabla</th><th>Campos inmutables</th><th>¿Por qué importa?</th></tr></thead>
                <tbody>
                    <tr>
                        <td><code>venta_detalles</code></td>
                        <td><code>precio_unitario</code>, <code>nombre_snap</code>, <code>nombre2_snap</code></td>
                        <td>Si "Sándwich de Pollo" se renombra a "Desmechado XL", las ventas antiguas siguen mostrando el nombre original</td>
                    </tr>
                    <tr>
                        <td><code>compra_detalles</code></td>
                        <td><code>precio_unitario</code>, <code>nombre_snap</code>, <code>unidad_snap</code>, <code>precio_presentacion</code>, <code>cant_presentaciones</code></td>
                        <td>Se sabe exactamente qué se compró, en qué empaque y a qué precio por empaque</td>
                    </tr>
                    <tr>
                        <td><code>produccion_lotes</code></td>
                        <td><code>costo_unitario</code>, <code>nombre_snap</code></td>
                        <td>El costo de producción histórico no cambia cuando suben los ingredientes</td>
                    </tr>
                    <tr>
                        <td><code>nomina_liquidaciones</code></td>
                        <td>Todos los montos + <code>valor_hora_snap</code> + <code>valor_proyecto_snap</code></td>
                        <td>La liquidación de enero refleja la tarifa de enero, no la tarifa actual del empleado</td>
                    </tr>
                    <tr>
                        <td><code>pagos_fiado</code></td>
                        <td><code>saldo_anterior</code>, <code>saldo_posterior</code></td>
                        <td>Se puede verificar que el abono fue correcto: "pagó $50.000 de una deuda de $130.000; quedó en $80.000"</td>
                    </tr>
                    <tr>
                        <td><code>costos_indirectos</code></td>
                        <td>Cada fila es un período completo</td>
                        <td>Al cambiar el arriendo de $500k a $600k, crear nueva fila; no editar la anterior</td>
                    </tr>
                    <tr>
                        <td><code>activos</code></td>
                        <td>Cambios en <code>costo_inicial</code> y <code>vida_util_meses</code> → auditados en <code>logs_historial</code></td>
                        <td>El historial de cambios en activos está en Reportes → Variación de Precios → tab Activos</td>
                    </tr>
                </tbody>
            </table>
            <div class="tip"><strong>Corrección de error de digitación:</strong> Se permite editar una venta o compra mal digitada. El sistema usa flujo DELETE + re-INSERT (nunca UPDATE del precio). El reporte de variación de precios mostrará el precio corregido como si siempre hubiera sido ese.</div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════ -->
        <!--  SECCIÓN: PRUEBAS DE INTEGRIDAD                               -->
        <!-- ══════════════════════════════════════════════════════════════ -->
        <div class="section" id="pruebas">
            <div class="section-hdr">
                <div class="section-icon" style="background:#f0fdf4">&#129514;</div>
                <div><div class="section-title">Suite de Pruebas de Integridad</div></div>
            </div>
            <p>El sistema incluye una suite de pruebas automáticas accesible desde el navegador en <code>/tests/suite.php</code>. Solo puede ser ejecutada por el superadmin.</p>

            <div class="sub-title">¿Qué valida?</div>
            <table class="data-table">
                <thead><tr><th>Grupo</th><th>¿Qué verifica?</th></tr></thead>
                <tbody>
                    <tr><td>G01 Esquema BD</td><td>Todas las tablas y columnas críticas existen (incluye migraciones 027-030 + listas_sistema)</td></tr>
                    <tr><td>G02 Migraciones 026-030</td><td>obsequio en metodo_pago, nombre2, apellido/empresa en clientes, listas_sistema, equiv_cantidad</td></tr>
                    <tr><td>G03 Precios históricos</td><td>precio_unitario > 0 en ventas/compras activas. Subtotales correctos. Totales cuadran con detalles.</td></tr>
                    <tr><td>G04 Stock</td><td>Sin negativos en stock. from_stock válido. ajustes_stock coherente.</td></tr>
                    <tr><td>G05 Fiado</td><td>Saldos ≥ 0. Solo fiado en estado pendiente_pago.</td></tr>
                    <tr><td>G06 Obsequios</td><td>No en pendiente_pago. Total > 0. En ajustes_stock correctamente.</td></tr>
                    <tr><td>G07 Combos</td><td>combo_configs e integridad de venta_detalles.</td></tr>
                    <tr><td>G23 Variantes 035</td><td>producto_variantes y columnas venta_detalles (variante_id, factor_receta_snap). Factor en rango, precios positivos, sin duplicados, coherencia NULL.</td></tr>
                    <tr><td>G24 Ingrediente Base 036</td><td>recetas.es_base solo 0 o 1. Ningún ingrediente es crítico Y base a la vez. Productos con factor≠1 tienen al menos un ingrediente escalable.</td></tr>
                    <tr><td>G25 Conteo Rápido</td><td>Endpoint conteo_guardar.php accesible solo con permiso editar_existentes. Stock no negativo tras conteo. Cada cambio registra entrada en logs_historial.</td></tr>
                    <tr><td>G26 Turnos de Caja 037</td><td>Tabla turnos_caja existe. Columnas requeridas. Estado solo 'abierto'/'cerrado'. Máximo 1 turno abierto por fecha. Fondo ≥ 0. Turnos cerrados con fecha_cierre. Sin huérfanos en usuarios.</td></tr>
                    <tr><td>G27 Descuentos 038</td><td>Columnas descuento_pct / descuento_valor en ventas. pct en rango 0-50. valor ≥ 0. Coherencia pct ↔ valor. Total con descuento ≤ suma bruta de detalles.</td></tr>
                    <tr><td>G08 Clientes</td><td>Campos mig. 028, saldos, FKs del módulo de fusión.</td></tr>
                    <tr><td>G09 Producción</td><td>Lotes activos con costo coherente. FK sin huérfanos.</td></tr>
                    <tr><td>G10 Activos</td><td>Sin fecha_inicio_uso → depreciación = 0. Divisor 30.41666.</td></tr>
                    <tr><td>G11 Nómina</td><td>FK liquidaciones → empleados. Costo razonable.</td></tr>
                    <tr><td>G12 Costos productos</td><td>costo_calculado > 0 en productos con receta.</td></tr>
                    <tr><td>G13 Foreign keys</td><td>Sin huérfanos en tablas críticas.</td></tr>
                    <tr><td>G14 Catálogos</td><td>listas_sistema tiene al menos 6 tipos activos.</td></tr>
                    <tr><td>G15 Configuración</td><td>Todas las claves de configuracion_app existen.</td></tr>
                    <tr><td>G16 Seguridad</td><td>APP_ENV, BCRYPT_COST, SESSION_LIFETIME, roles, rate-limiting.</td></tr>
                    <tr><td>G17 Auditoría</td><td>logs_historial activo, registros recientes, sin bloqueos activos.</td></tr>
                    <tr><td>G18 Eficiencia</td><td>Índices críticos en ventas.fecha_venta, listas_sistema, insumos.activo.</td></tr>
                    <tr><td>G19 Usuario UX</td><td>Hay productos activos, nombre negocio configurado, contraseña no es la de ejemplo.</td></tr>
                    <tr><td>G20 Inmutabilidad profunda</td><td>precio_unitario ≠ 0 en ventas activas, salario_base > 0 en nómina, costo lotes ≥ 0.</td></tr>
                    <tr><td>G21 Migraciones 031-034</td><td>Verifica si las columnas ENUM se convirtieron a VARCHAR y si los snapshots de nombre y saldo están aplicados.</td></tr>
                    <tr><td>Auditoría y Seguridad</td><td><code>logs_historial</code> activo. Contraseñas de usuarios usan bcrypt.</td></tr>
                </tbody>
            </table>

            <div class="sub-title">Cómo interpretar resultados</div>
            <table class="data-table">
                <thead><tr><th>Estado</th><th>Significado</th><th>Acción</th></tr></thead>
                <tbody>
                    <tr><td><strong style="color:#059669">PASS</strong></td><td>La prueba pasó correctamente</td><td>Ninguna</td></tr>
                    <tr><td><strong style="color:#d97706">WARN</strong></td><td>Situación a revisar pero no crítica (posible dato histórico o edge case)</td><td>Revisar el detalle y determinar si requiere corrección</td></tr>
                    <tr><td><strong style="color:#dc2626">FAIL</strong></td><td>Inconsistencia detectada que requiere corrección</td><td>Leer el detalle y aplicar la corrección indicada antes de continuar</td></tr>
                </tbody>
            </table>

            <div class="tip"><strong>Cuándo ejecutar:</strong> Después de aplicar una migración SQL, después de una actualización de código, o si sospechas que los datos tienen inconsistencias. También útil como verificación pre-producción.</div>
            <div class="warn"><strong>Solo superadmin.</strong> El archivo <code>tests/suite.php</code> requiere sesión activa con rol <em>superadmin</em>. Cualquier otro rol recibe un error 403.</div>
        </div>

    </main>
</div>

<script>
/* ── Activar link del sidebar ────────────────────────────────────────── */
function activar(el) {
    document.querySelectorAll('.side-link').forEach(function(l){ l.classList.remove('active'); });
    el.classList.add('active');
}

/* ── Filtro del sidebar ──────────────────────────────────────────────── */
function filtrarSidebar(q) {
    var query = q.toLowerCase().trim();
    document.querySelectorAll('.side-link').forEach(function(l){
        l.style.display = !query || l.textContent.toLowerCase().includes(query) ? '' : 'none';
    });
}

/* ── Búsqueda en el contenido ───────────────────────────────────────── */
function buscarContenido(q) {
    var query = q.toLowerCase().trim();
    var sections = document.querySelectorAll('.section');
    var hayResultados = false;

    sections.forEach(function(s) {
        if (!query) {
            s.style.display = '';
            hayResultados = true;
        } else {
            var texto = s.textContent.toLowerCase();
            var visible = texto.includes(query);
            s.style.display = visible ? '' : 'none';
            if (visible) hayResultados = true;
        }
    });

    document.getElementById('no-results').style.display = (!query || hayResultados) ? 'none' : 'block';
}

/* ── Resaltar sección activa en sidebar al hacer scroll ─────────────── */
window.addEventListener('scroll', function() {
    var secciones = document.querySelectorAll('.section');
    var scrollY   = window.scrollY + 80;
    var activa    = null;
    secciones.forEach(function(s) {
        if (s.offsetTop <= scrollY) activa = s.id;
    });
    if (activa) {
        document.querySelectorAll('.side-link').forEach(function(l){
            l.classList.toggle('active', l.getAttribute('href') === '#' + activa);
        });
    }
}, {passive: true});
</script>
</body>
</html>
