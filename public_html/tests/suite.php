<?php
/**
 * public_html/tests/suite.php — Suite de pruebas de integridad y seguridad.
 *
 * Accesible solo para superadmin. Cubre:
 *   G01  Esquema BD          — tablas, columnas y tipos correctos (incluye 027-030)
 *   G02  Migraciones 026-030 — obsequio, nombre2, clientes, listas, equiv física
 *   G03  Precios históricos  — inmutabilidad de precios en ventas y compras
 *   G04  Stock               — no negativos, from_stock, ajustes válidos
 *   G05  Fiado               — saldos, estados y fechas de pago
 *   G06  Obsequios           — excluidos de ingresos, integridad ajustes_stock
 *   G07  Combos              — combo_configs e integridad de venta_detalles
 *   G08  Clientes            — campos migración 028, saldos, FKs (módulo nuevo)
 *   G09  Producción          — lotes, estados, FKs
 *   G10  Activos             — depreciación, triggers, coherencia de fórmulas
 *   G11  Nómina              — liquidaciones y FKs
 *   G12  Costos de productos — costo_calculado y precio_venta
 *   G13  Foreign keys        — sin huérfanos en tablas críticas
 *   G14b Catálogos           — items activos en listas_sistema, sin duplicados (029)
 *   G15  Configuración       — claves requeridas en configuracion_app/negocio
 *   G16  Seguridad           — contraseñas, APP_ENV, rate-limiting, CSRF activo
 *   G17  Auditoría           — logs_historial activo y con registros recientes
 *   G18  Eficiencia          — índices en columnas críticas de tablas grandes
 *   G19  Usuario UX          — validaciones de interfaz y flujos de usuario
 *   G20  Inmutabilidad ext.  — nombres snapshot en ventas, compras, producción
 *   G21  Migración 031       — conversión ENUM → VARCHAR para catálogos
 *   G22  Snapshots 032-034   — coherencia de empaque, nómina, nombres y saldos
 *   G23  Variantes 035       — tabla producto_variantes, columnas venta_detalles, coherencia
 *
 * EJECUTAR: /tests/suite.php (navegador, sesión activa como superadmin)
 */

require_once __DIR__ . '/../app/middleware/auth_check.php';

// Acceso exclusivo: superadmin por seguridad (este script lee datos sensibles)
if (($_SESSION['usuario_rol'] ?? '') !== 'superadmin') {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="es"><body style="font-family:sans-serif;padding:40px">'
       . '<h2>403 — Solo el superadmin puede ejecutar las pruebas de integridad.</h2></body></html>';
    exit;
}

$pdo = db();

// ── Contadores globales ───────────────────────────────────────────────────────
$resultados = [];
$pass       = 0;
$fail       = 0;
$warn       = 0;

// ── Helper: registrar resultado de una prueba ─────────────────────────────────
// $ok=true → PASS | $ok=false && $es_warn=true → WARN | $ok=false → FAIL
function t(string $grupo, string $nombre, bool $ok, string $detalle = '', bool $es_warn = false): void
{
    global $resultados, $pass, $fail, $warn;

    if ($ok) {
        $pass++;
        $estado = 'PASS';
    } elseif ($es_warn) {
        $warn++;
        $estado = 'WARN';
    } else {
        $fail++;
        $estado = 'FAIL';
    }

    $resultados[] = compact('grupo', 'nombre', 'estado', 'detalle');
}

// ── Helper: verificar si una tabla existe en la BD ────────────────────────────
// Usa query() con backticks para evitar inyección SQL con nombres de tabla.
// Devuelve false ante cualquier excepción PDO (tabla inexistente o sin permiso).
function tabla_existe(PDO $pdo, string $tabla): bool
{
    try {
        $pdo->query("SELECT 1 FROM `{$tabla}` LIMIT 1");
        return true;
    } catch (\Exception $e) {
        return false;
    }
}

// ── Helper: verificar si una columna existe en una tabla ─────────────────────
// Usa backtick en tabla y columna para nombres con caracteres especiales.
function columna_existe(PDO $pdo, string $tabla, string $columna): bool
{
    try {
        $pdo->query("SELECT `{$columna}` FROM `{$tabla}` LIMIT 1");
        return true;
    } catch (\Exception $e) {
        return false;
    }
}

// ── Helper: ejecutar query y devolver el primer valor escalar ─────────────────
// Siempre usa prepared statements para evitar inyección SQL.
function scalar(PDO $pdo, string $sql, array $params = [])
{
    $s = $pdo->prepare($sql);
    $s->execute($params);
    return $s->fetchColumn();
}

// ════════════════════════════════════════════════════════════════════════════════
//  G01 — ESQUEMA DE BASE DE DATOS
//  Verifica que todas las tablas y columnas críticas existen con los tipos
//  correctos. Un fallo aquí indica que falta aplicar una migración SQL.
// ════════════════════════════════════════════════════════════════════════════════

$G = 'G01 Esquema BD';

// Todas las tablas del sistema (en orden cronológico de migración)
$tablas_requeridas = [
    // Base: schema.sql
    'usuarios', 'permisos_modulos', 'configuracion_negocio',
    'clientes', 'proveedores', 'insumos', 'recetas', 'productos',
    'ventas', 'venta_detalles', 'compras', 'compra_detalles',
    'produccion_lotes', 'empleados', 'nomina_liquidaciones',
    'activos', 'costos_indirectos', 'logs_historial', 'login_intentos',
    // Migraciones posteriores
    'registro_horas',    // migración 007
    'configuracion_app', // migración 016
    'combo_configs',     // migración 025
    'combo_insumos',     // migración 025
    'ajustes_stock',     // migración 026
    'listas_sistema',    // migración 029
];

foreach ($tablas_requeridas as $tabla) {
    $existe = tabla_existe($pdo, $tabla);
    t($G, "Tabla '{$tabla}' existe", $existe,
      $existe ? '' : "Tabla '{$tabla}' no encontrada. Revisar migraciones.");
}

// Columnas críticas para el funcionamiento del POS y reportes
$columnas_criticas = [
    // Catálogos configurables (migración 029)
    ['listas_sistema',  'tipo'],             // tipo de catálogo ('presentacion', 'unidad_medida', etc.)
    ['listas_sistema',  'valor'],            // valor almacenado en BD
    ['listas_sistema',  'etiqueta'],         // etiqueta visible al usuario
    ['listas_sistema',  'activo'],           // 1=activo en formularios, 0=inactivo (histórico)
    // Productos: nombre2 es el subtítulo complementario (migración 027)
    ['productos',       'nombre2'],          // complemento visual — solo afecta display, no lógica
    // Ventas: precio inmutable + obsequio + combo
    ['ventas',          'metodo_pago'],
    ['ventas',          'fecha_venta'],
    ['ventas',          'es_combo'],
    ['venta_detalles',  'precio_unitario'], // precio histórico — nunca se modifica
    ['venta_detalles',  'from_stock'],      // 1=stock terminado, 0=modo demanda
    ['venta_detalles',  'es_combo'],
    ['venta_detalles',  'combo_id'],
    // Compras: precio histórico
    ['compra_detalles', 'precio_unitario'], // precio pagado al proveedor — inmutable
    ['compra_detalles', 'subtotal'],
    // Producción: costo snapshot al momento de producir
    ['produccion_lotes', 'costo_unitario'], // snapshot de costo_calculado — inmutable
    ['produccion_lotes', 'estado'],
    // Ajustes de stock (migración 026)
    ['ajustes_stock',   'producto_id'],
    ['ajustes_stock',   'cantidad'],
    ['ajustes_stock',   'tipo'],
    ['ajustes_stock',   'fecha_ajuste'],
    // Activos: fechas para depreciación
    ['activos',         'fecha_inicio_uso'],
    ['activos',         'depreciacion_diaria'],
];

foreach ($columnas_criticas as [$tabla, $col]) {
    $existe = columna_existe($pdo, $tabla, $col);
    t($G, "Columna {$tabla}.{$col} existe", $existe,
      $existe ? '' : "Columna faltante. Revisar migración correspondiente.");
}

// ════════════════════════════════════════════════════════════════════════════════
//  G02 — MIGRACIONES 026-030
//  Verifica que todas las migraciones recientes están aplicadas:
//    026 → obsequio en metodo_pago + tabla ajustes_stock
//    027 → productos.nombre2 (subtítulo visual)
//    028 → clientes.apellido + clientes.empresa
//    029 → tabla listas_sistema con catálogos configurables
//    030 → insumos.equiv_cantidad + insumos.equiv_unidad
// ════════════════════════════════════════════════════════════════════════════════

$G = 'G02 Migraciones 026-030';

// Verificar que 'obsequio' está en el ENUM de ventas.metodo_pago
$enum_row = $pdo->query("SHOW COLUMNS FROM ventas LIKE 'metodo_pago'")->fetch();
$enum_val = $enum_row ? $enum_row['Type'] : '';
t($G, "ventas.metodo_pago incluye 'obsequio'",
    str_contains($enum_val, 'obsequio'),
    "Tipo actual: {$enum_val} — ejecutar: ALTER TABLE ventas MODIFY COLUMN metodo_pago ENUM(...,'obsequio')");

// Verificar que el ENUM tiene exactamente los 6 métodos esperados
$metodos_esperados = ['efectivo','nequi','daviplata','bancolombia','fiado','obsequio'];
$todos_presentes   = true;
foreach ($metodos_esperados as $m) {
    if (!str_contains($enum_val, $m)) { $todos_presentes = false; break; }
}
t($G, "metodo_pago tiene los 6 metodos: efectivo,nequi,daviplata,bancolombia,fiado,obsequio",
    $todos_presentes,
    $todos_presentes ? '' : "Metodos faltantes en el ENUM. Verificar ALTER TABLE de migracion 026.");

// Verificar que ajustes_stock existe y tiene las columnas correctas
$cols_ajustes = ['id','producto_id','cantidad','tipo','motivo','fecha_ajuste','created_by'];
foreach ($cols_ajustes as $col) {
    $existe = columna_existe($pdo, 'ajustes_stock', $col);
    t($G, "ajustes_stock.{$col} existe", $existe,
      $existe ? '' : "Columna faltante en ajustes_stock. Re-ejecutar migracion 026.");
}

// Verificar que el ENUM tipo de ajustes_stock solo admite 'obsequio' y 'desecho'
$tipo_row = $pdo->query("SHOW COLUMNS FROM ajustes_stock LIKE 'tipo'")->fetch();
$tipo_val = $tipo_row ? $tipo_row['Type'] : '';
t($G, "ajustes_stock.tipo es ENUM('obsequio','desecho')",
    str_contains($tipo_val, 'obsequio') && str_contains($tipo_val, 'desecho'),
    "Tipo actual: {$tipo_val}");

// Migración 027: productos.nombre2 (subtítulo visual)
t($G, "027 — productos.nombre2 existe",
    columna_existe($pdo, 'productos', 'nombre2'),
    "Falta columna nombre2. Aplicar 027_productos_nombre2.sql");

// Migración 028: campos adicionales en clientes
foreach (['apellido','empresa'] as $col) {
    t($G, "028 — clientes.{$col} existe",
      columna_existe($pdo, 'clientes', $col),
      "Falta columna {$col}. Aplicar 028_clientes_campos.sql");
}

// Migración 029: tabla listas_sistema
t($G, "029 — tabla listas_sistema existe", tabla_existe($pdo, 'listas_sistema'),
    "Aplicar 029_listas_sistema.sql");
if (tabla_existe($pdo, 'listas_sistema')) {
    $tipos_ok = (int)scalar($pdo, "SELECT COUNT(DISTINCT tipo) FROM listas_sistema");
    t($G, "029 — listas_sistema tiene al menos 6 tipos de catalogo", $tipos_ok >= 6,
      "Solo {$tipos_ok} tipos. Puede faltar 029b_listas_productos.sql", true);
}

// Migración 030: equivalencia física en insumos
foreach (['equiv_cantidad','equiv_unidad'] as $col) {
    t($G, "030 — insumos.{$col} existe",
      columna_existe($pdo, 'insumos', $col),
      "Falta columna {$col}. Aplicar 030_insumo_equivalencia.sql");
}

// Coherencia de equivalencia: si equiv_cantidad está configurada, equiv_unidad no debe ser NULL
$equiv_incoherente = (int)scalar($pdo,
    "SELECT COUNT(*) FROM insumos
     WHERE equiv_cantidad IS NOT NULL AND equiv_cantidad > 0
       AND (equiv_unidad IS NULL OR equiv_unidad = '')");
t($G, "Insumos con equiv_cantidad siempre tienen equiv_unidad",
    $equiv_incoherente === 0,
    $equiv_incoherente > 0 ? "{$equiv_incoherente} insumos con equivalencia incompleta." : '');

// ════════════════════════════════════════════════════════════════════════════════
//  G03 — INMUTABILIDAD DE PRECIOS HISTÓRICOS
//  Principio central del sistema: los precios registrados en transacciones
//  (ventas, compras, producción) nunca deben cambiar cuando los precios
//  actuales varían. Esto garantiza reportes financieros históricos correctos.
// ════════════════════════════════════════════════════════════════════════════════

$G = 'G03 Precios Historicos';

// Ninguna línea activa de venta debe tener precio <= 0 o cantidad <= 0
$ventas_precio_invalido = (int)scalar($pdo,
    "SELECT COUNT(*) FROM venta_detalles vd
     JOIN ventas v ON v.id = vd.venta_id
     WHERE v.estado != 'anulada'
       AND (vd.precio_unitario <= 0 OR vd.cantidad <= 0)");
t($G, "Lineas de venta activas tienen precio_unitario > 0 y cantidad > 0",
    $ventas_precio_invalido === 0,
    $ventas_precio_invalido > 0 ? "{$ventas_precio_invalido} lineas con precio o cantidad invalida." : '');

// venta_detalles.subtotal = precio_unitario × cantidad (tolerancia $1 por redondeo DECIMAL)
$subtotal_venta_mal = (int)scalar($pdo,
    "SELECT COUNT(*) FROM venta_detalles vd
     JOIN ventas v ON v.id = vd.venta_id
     WHERE v.estado != 'anulada'
       AND ABS(vd.subtotal - (vd.precio_unitario * vd.cantidad)) > 1");
t($G, "venta_detalles.subtotal = precio * cantidad (tolerancia 1 peso)",
    $subtotal_venta_mal === 0,
    $subtotal_venta_mal > 0 ? "{$subtotal_venta_mal} lineas con subtotal inconsistente." : '');

// ventas.total = SUM(venta_detalles.subtotal) para cada venta no anulada
$totales_venta_mal = (int)scalar($pdo,
    "SELECT COUNT(*) FROM (
         SELECT v.id FROM ventas v
         JOIN venta_detalles vd ON vd.venta_id = v.id
         WHERE v.estado != 'anulada'
         GROUP BY v.id
         HAVING ABS(v.total - SUM(vd.subtotal)) > 1
     ) sub");
t($G, "ventas.total = SUM(venta_detalles.subtotal) en ventas activas",
    $totales_venta_mal === 0,
    $totales_venta_mal > 0 ? "{$totales_venta_mal} ventas con total diferente a la suma de detalles." : '');

// compra_detalles.subtotal = precio_unitario × cantidad
$subtotal_compra_mal = (int)scalar($pdo,
    "SELECT COUNT(*) FROM compra_detalles
     WHERE ABS(subtotal - (precio_unitario * cantidad)) > 1");
t($G, "compra_detalles.subtotal = precio * cantidad",
    $subtotal_compra_mal === 0,
    $subtotal_compra_mal > 0 ? "{$subtotal_compra_mal} lineas de compra con subtotal inconsistente." : '');

// compras.total = SUM(compra_detalles.subtotal) para cada compra
$totales_compra_mal = (int)scalar($pdo,
    "SELECT COUNT(*) FROM (
         SELECT c.id FROM compras c
         JOIN compra_detalles cd ON cd.compra_id = c.id
         GROUP BY c.id
         HAVING ABS(c.total - SUM(cd.subtotal)) > 1
     ) sub");
t($G, "compras.total = SUM(compra_detalles.subtotal)",
    $totales_compra_mal === 0,
    $totales_compra_mal > 0 ? "{$totales_compra_mal} compras con total diferente a la suma de detalles." : '');

// produccion_lotes.costo_unitario no debe ser negativo (puede ser NULL si no habia costo configurado)
$lote_costo_negativo = (int)scalar($pdo,
    "SELECT COUNT(*) FROM produccion_lotes
     WHERE estado = 'activo' AND costo_unitario IS NOT NULL AND costo_unitario < 0");
t($G, "produccion_lotes.costo_unitario no es negativo",
    $lote_costo_negativo === 0,
    $lote_costo_negativo > 0 ? "{$lote_costo_negativo} lotes con costo_unitario negativo." : '');

// ════════════════════════════════════════════════════════════════════════════════
//  G04 — CONSISTENCIA DE STOCK
//  El stock nunca debe quedar en negativo. El campo from_stock indica la fuente
//  del descuento: 1=stock terminado, 0=insumos directos (modo demanda).
// ════════════════════════════════════════════════════════════════════════════════

$G = 'G04 Consistencia Stock';

$stock_prod_negativo = (int)scalar($pdo,
    "SELECT COUNT(*) FROM productos WHERE activo = 1 AND stock_disponible < 0");
t($G, "Ningun producto activo tiene stock_disponible negativo",
    $stock_prod_negativo === 0,
    $stock_prod_negativo > 0 ? "{$stock_prod_negativo} productos con stock negativo." : '');

$stock_ins_negativo = (int)scalar($pdo,
    "SELECT COUNT(*) FROM insumos WHERE activo = 1 AND stock_actual < 0");
t($G, "Ningun insumo activo tiene stock_actual negativo",
    $stock_ins_negativo === 0,
    $stock_ins_negativo > 0 ? "{$stock_ins_negativo} insumos con stock negativo." : '');

// from_stock solo puede ser 0 o 1 (binario que indica fuente del descuento)
$from_stock_invalido = (int)scalar($pdo,
    "SELECT COUNT(*) FROM venta_detalles WHERE from_stock NOT IN (0,1)");
t($G, "venta_detalles.from_stock es siempre 0 o 1",
    $from_stock_invalido === 0,
    $from_stock_invalido > 0 ? "{$from_stock_invalido} registros con from_stock invalido." : '');

// Integridad de ajustes_stock: cantidad positiva y tipo válido
if (tabla_existe($pdo, 'ajustes_stock')) {
    $aj_cantidad_mal = (int)scalar($pdo,
        "SELECT COUNT(*) FROM ajustes_stock WHERE cantidad <= 0");
    t($G, "ajustes_stock.cantidad siempre > 0",
        $aj_cantidad_mal === 0,
        $aj_cantidad_mal > 0 ? "{$aj_cantidad_mal} ajustes con cantidad invalida." : '');

    $aj_tipo_invalido = (int)scalar($pdo,
        "SELECT COUNT(*) FROM ajustes_stock WHERE tipo NOT IN ('obsequio','desecho')");
    t($G, "ajustes_stock.tipo es 'obsequio' o 'desecho'",
        $aj_tipo_invalido === 0,
        $aj_tipo_invalido > 0 ? "{$aj_tipo_invalido} ajustes con tipo invalido." : '');

    // Los ajustes deben referenciar productos que existen
    $aj_prod_roto = (int)scalar($pdo,
        "SELECT COUNT(*) FROM ajustes_stock aj
         LEFT JOIN productos p ON p.id = aj.producto_id WHERE p.id IS NULL");
    t($G, "ajustes_stock → productos sin huerfanos",
        $aj_prod_roto === 0,
        $aj_prod_roto > 0 ? "{$aj_prod_roto} ajustes apuntan a productos inexistentes." : '');
}

// ════════════════════════════════════════════════════════════════════════════════
//  G05 — CONSISTENCIA DE FIADO
//  Las ventas a crédito usan metodo_pago='fiado' y estado='pendiente_pago'.
//  Al cobrar cambian a estado='completada' + fecha_pago = NOW().
// ════════════════════════════════════════════════════════════════════════════════

$G = 'G05 Fiado';

$saldo_negativo = (int)scalar($pdo,
    "SELECT COUNT(*) FROM clientes WHERE saldo_fiado < 0");
t($G, "Ningun cliente tiene saldo_fiado negativo",
    $saldo_negativo === 0,
    $saldo_negativo > 0 ? "{$saldo_negativo} clientes con saldo negativo." : '');

// Estado 'pendiente_pago' solo debe ocurrir en ventas fiado
$pendiente_no_fiado = (int)scalar($pdo,
    "SELECT COUNT(*) FROM ventas WHERE estado = 'pendiente_pago' AND metodo_pago != 'fiado'");
t($G, "Solo ventas fiado pueden tener estado=pendiente_pago",
    $pendiente_no_fiado === 0,
    $pendiente_no_fiado > 0 ? "{$pendiente_no_fiado} ventas no-fiado con estado pendiente_pago." : '');

// Warning: ventas fiado completadas sin fecha_pago (posible dato histórico pre-v4.9)
$fiado_sin_fecha = (int)scalar($pdo,
    "SELECT COUNT(*) FROM ventas WHERE metodo_pago='fiado' AND estado='completada' AND fecha_pago IS NULL");
t($G, "Ventas fiado completadas tienen fecha_pago registrada",
    $fiado_sin_fecha === 0,
    $fiado_sin_fecha > 0 ? "{$fiado_sin_fecha} ventas fiado completadas sin fecha_pago (dato historico pre-v4.9)." : '',
    true); // WARN: puede existir en datos históricos

// ════════════════════════════════════════════════════════════════════════════════
//  G06 — INTEGRIDAD DE OBSEQUIOS
//  Las ventas con metodo_pago='obsequio' deben:
//   - Estado siempre 'completada' (nunca 'pendiente_pago')
//   - total > 0 (almacena el precio real como referencia de valor obsequiado)
//   - NO sumarse a los ingresos en ningún reporte
// ════════════════════════════════════════════════════════════════════════════════

$G = 'G06 Obsequios';

$obsequio_pendiente = (int)scalar($pdo,
    "SELECT COUNT(*) FROM ventas WHERE metodo_pago='obsequio' AND estado='pendiente_pago'");
t($G, "Ventas obsequio NO tienen estado=pendiente_pago",
    $obsequio_pendiente === 0,
    $obsequio_pendiente > 0 ? "{$obsequio_pendiente} obsequios con estado pendiente_pago (invalido)." : '');

// Obsequios deben tener total > 0 (precio de referencia para calcular valor regalado)
$obsequio_total_cero = (int)scalar($pdo,
    "SELECT COUNT(*) FROM ventas WHERE metodo_pago='obsequio' AND estado!='anulada' AND total <= 0");
t($G, "Ventas obsequio tienen total > 0 (precio de referencia)",
    $obsequio_total_cero === 0,
    $obsequio_total_cero > 0 ? "{$obsequio_total_cero} obsequios con total = 0." : '',
    true);

// Verificar que los reportes excluirían obsequios de ingresos:
// Contar cuántas ventas obsequio existen en estado completada (solo para info)
$total_obsequios = (int)scalar($pdo,
    "SELECT COUNT(*) FROM ventas WHERE metodo_pago='obsequio' AND estado='completada'");
$valor_obsequios = (float)scalar($pdo,
    "SELECT IFNULL(SUM(total),0) FROM ventas WHERE metodo_pago='obsequio' AND estado='completada'");
// Esta prueba siempre pasa; es informativa sobre el volumen de obsequios
t($G, "Conteo de obsequios registrados: {$total_obsequios} ventas / \$" . number_format($valor_obsequios,0,',','.') . " valor ref.",
    true, '');

// ════════════════════════════════════════════════════════════════════════════════
//  G07 — INTEGRIDAD DE COMBOS (migración 025)
//  Cada producto puede tener una configuración combo activa.
//  Las líneas de venta con es_combo=1 deben referenciar combo_id válidos
//  (excepto datos históricos pre-025 que tienen combo_id=NULL).
// ════════════════════════════════════════════════════════════════════════════════

$G = 'G07 Combos';

// combo_configs debe referenciar productos existentes
$combo_prod_roto = (int)scalar($pdo,
    "SELECT COUNT(*) FROM combo_configs cc
     LEFT JOIN productos p ON p.id = cc.producto_id WHERE p.id IS NULL");
t($G, "combo_configs → productos sin huerfanos",
    $combo_prod_roto === 0,
    $combo_prod_roto > 0 ? "{$combo_prod_roto} combo_configs apuntan a productos inexistentes." : '');

// combo_insumos debe referenciar combo_configs e insumos existentes
$combo_ins_roto = (int)scalar($pdo,
    "SELECT COUNT(*) FROM combo_insumos ci
     LEFT JOIN combo_configs cc ON cc.id = ci.combo_id WHERE cc.id IS NULL");
t($G, "combo_insumos → combo_configs sin huerfanos",
    $combo_ins_roto === 0,
    $combo_ins_roto > 0 ? "{$combo_ins_roto} combo_insumos apuntan a combo_configs inexistentes." : '');

// es_combo en venta_detalles solo puede ser 0 o 1
$es_combo_invalido = (int)scalar($pdo,
    "SELECT COUNT(*) FROM venta_detalles WHERE es_combo NOT IN (0,1)");
t($G, "venta_detalles.es_combo es siempre 0 o 1",
    $es_combo_invalido === 0,
    $es_combo_invalido > 0 ? "{$es_combo_invalido} registros con es_combo invalido." : '');

// combo_id en venta_detalles con es_combo=1 puede ser NULL (datos históricos pre-025) — solo warning
$combo_sin_id = (int)scalar($pdo,
    "SELECT COUNT(*) FROM venta_detalles vd
     JOIN ventas v ON v.id = vd.venta_id
     WHERE vd.es_combo = 1 AND vd.combo_id IS NULL AND v.estado != 'anulada'");
t($G, "Combos activos con combo_id NULL (datos historicos pre-025)",
    true, // siempre PASS — es informativo
    $combo_sin_id > 0 ? "{$combo_sin_id} lineas combo sin combo_id (normales si son pre-v4.13)." : 'Ninguno.',
    $combo_sin_id > 0); // WARN si hay datos históricos

// ════════════════════════════════════════════════════════════════════════════════
//  G08 — MÓDULO DE CLIENTES (migración 028)
//  Verifica la estructura del módulo de clientes y la consistencia de datos.
// ════════════════════════════════════════════════════════════════════════════════

$G = 'G08 Clientes';

// Columnas del módulo de clientes (migración 028 agrega apellido y empresa)
foreach (['nombre','apellido','empresa','telefono','saldo_fiado','activo'] as $col) {
    $existe = columna_existe($pdo, 'clientes', $col);
    t($G, "clientes.{$col} existe",
      $existe, $existe ? '' : "Columna faltante. Aplicar migracion 028.");
}

// saldo_fiado nunca debe ser negativo (GREATEST(0,...) en los UPDATE lo garantiza)
$cli_saldo_neg = (int)scalar($pdo,
    "SELECT COUNT(*) FROM clientes WHERE saldo_fiado < 0");
t($G, "Ningun cliente tiene saldo_fiado negativo",
    $cli_saldo_neg === 0,
    $cli_saldo_neg > 0 ? "{$cli_saldo_neg} clientes con saldo negativo." : '');

// Los clientes inactivos no deben tener saldo_fiado > 0 (la fusion lo pone a 0)
$cli_inac_deuda = (int)scalar($pdo,
    "SELECT COUNT(*) FROM clientes WHERE activo = 0 AND saldo_fiado > 0");
t($G, "Clientes inactivos tienen saldo_fiado = 0",
    $cli_inac_deuda === 0,
    $cli_inac_deuda > 0 ? "{$cli_inac_deuda} clientes inactivos con saldo > 0." : '',
    true); // WARN: puede haber datos historicos

// Todas las ventas con cliente_id deben apuntar a clientes que existen
$v_cli_roto = (int)scalar($pdo,
    "SELECT COUNT(*) FROM ventas v
     LEFT JOIN clientes c ON c.id = v.cliente_id
     WHERE v.cliente_id IS NOT NULL AND c.id IS NULL");
t($G, "Todas las ventas con cliente_id apuntan a clientes existentes",
    $v_cli_roto === 0,
    $v_cli_roto > 0 ? "{$v_cli_roto} ventas con cliente_id huerfano." : '');

// ════════════════════════════════════════════════════════════════════════════════
//  G09 — INTEGRIDAD DE PRODUCCIÓN
//  Los lotes de producción deben estar en estado 'activo' o 'anulado'.
//  Al anular un lote, los insumos se restauran pero stock_disponible se reduce.
// ════════════════════════════════════════════════════════════════════════════════

$G = 'G09 Produccion';

$lote_estado_invalido = (int)scalar($pdo,
    "SELECT COUNT(*) FROM produccion_lotes WHERE estado NOT IN ('activo','anulado')");
t($G, "Estado de produccion_lotes es 'activo' o 'anulado'",
    $lote_estado_invalido === 0,
    $lote_estado_invalido > 0 ? "{$lote_estado_invalido} lotes con estado invalido." : '');

$lote_cantidad_invalida = (int)scalar($pdo,
    "SELECT COUNT(*) FROM produccion_lotes WHERE estado = 'activo' AND cantidad <= 0");
t($G, "Lotes activos tienen cantidad > 0",
    $lote_cantidad_invalida === 0,
    $lote_cantidad_invalida > 0 ? "{$lote_cantidad_invalida} lotes activos con cantidad invalida." : '');

$lote_fk_roto = (int)scalar($pdo,
    "SELECT COUNT(*) FROM produccion_lotes pl
     LEFT JOIN productos p ON p.id = pl.producto_id WHERE p.id IS NULL");
t($G, "produccion_lotes → productos sin huerfanos",
    $lote_fk_roto === 0,
    $lote_fk_roto > 0 ? "{$lote_fk_roto} lotes apuntan a productos inexistentes." : '');

// ════════════════════════════════════════════════════════════════════════════════
//  G09 — ACTIVOS Y DEPRECIACIÓN
//  Regla crítica (migración 017): un activo NO deprecia hasta tener fecha_inicio_uso.
//  Fórmula (migración 018): divisor = 30.41666 (≈ 365/12).
// ════════════════════════════════════════════════════════════════════════════════

$G = 'G10 Activos y Depreciacion';

// Sin fecha_inicio_uso → depreciacion_diaria debe ser exactamente 0
$deprec_sin_fecha = (int)scalar($pdo,
    "SELECT COUNT(*) FROM activos WHERE fecha_inicio_uso IS NULL AND depreciacion_diaria > 0");
t($G, "Activos sin fecha_inicio_uso tienen depreciacion_diaria = 0",
    $deprec_sin_fecha === 0,
    $deprec_sin_fecha > 0 ? "{$deprec_sin_fecha} activos deprecian sin fecha de uso. Revisar triggers." : '');

// costo_inicial > 0 en activos activos
$activo_costo_invalido = (int)scalar($pdo,
    "SELECT COUNT(*) FROM activos WHERE activo = 1 AND costo_inicial <= 0");
t($G, "Activos activos tienen costo_inicial > 0",
    $activo_costo_invalido === 0,
    $activo_costo_invalido > 0 ? "{$activo_costo_invalido} activos sin costo_inicial valido." : '');

// depreciacion_mensual = costo_inicial / vida_util_meses (tolerancia $1 por redondeo)
$deprec_formula_mal = (int)scalar($pdo,
    "SELECT COUNT(*) FROM activos
     WHERE fecha_inicio_uso IS NOT NULL AND vida_util_meses > 0
       AND ABS(depreciacion_mensual - (costo_inicial / vida_util_meses)) > 1");
t($G, "depreciacion_mensual = costo_inicial / vida_util_meses (tolerancia 1 peso)",
    $deprec_formula_mal === 0,
    $deprec_formula_mal > 0 ? "{$deprec_formula_mal} activos con formula de depreciacion inconsistente. Ejecutar migracion 018." : '');

// ════════════════════════════════════════════════════════════════════════════════
//  G10 — NÓMINA
//  Cada liquidación es un snapshot completo del período.
//  costo_total_empleador debe ser >= salario_base para contratos no por_servicio.
// ════════════════════════════════════════════════════════════════════════════════

$G = 'G11 Nomina';

$liq_fk_rota = (int)scalar($pdo,
    "SELECT COUNT(*) FROM nomina_liquidaciones nl
     LEFT JOIN empleados e ON e.id = nl.empleado_id WHERE e.id IS NULL");
t($G, "nomina_liquidaciones → empleados sin huerfanos",
    $liq_fk_rota === 0,
    $liq_fk_rota > 0 ? "{$liq_fk_rota} liquidaciones apuntan a empleados inexistentes." : '');

// costo_total_empleador debe ser al menos 90% del salario_base (incluye cargas)
$liq_costo_bajo = (int)scalar($pdo,
    "SELECT COUNT(*) FROM nomina_liquidaciones nl
     JOIN empleados e ON e.id = nl.empleado_id
     WHERE e.tipo_contrato != 'por_servicio'
       AND nl.costo_total_empleador < nl.salario_base * 0.9");
t($G, "costo_total_empleador >= 90% salario_base en liquidaciones (no por_servicio)",
    $liq_costo_bajo === 0,
    $liq_costo_bajo > 0 ? "{$liq_costo_bajo} liquidaciones con costo_total < 90% salario." : '',
    true); // WARN: puede ocurrir en contratos parciales

// ════════════════════════════════════════════════════════════════════════════════
//  G11 — COSTOS DE PRODUCTOS
//  Productos con receta deben tener costo_calculado > 0.
//  Productos activos deben tener precio_venta > 0 para venderse en el POS.
// ════════════════════════════════════════════════════════════════════════════════

$G = 'G12 Costos Productos';

$prod_sin_costo = (int)scalar($pdo,
    "SELECT COUNT(*) FROM productos p
     WHERE p.activo = 1
       AND (SELECT COUNT(*) FROM recetas r WHERE r.producto_id = p.id) > 0
       AND (p.costo_calculado IS NULL OR p.costo_calculado <= 0)");
t($G, "Productos activos con receta tienen costo_calculado > 0",
    $prod_sin_costo === 0,
    $prod_sin_costo > 0 ? "{$prod_sin_costo} productos con receta sin costo. Ejecutar Recalcular en Productos." : '',
    true);

$prod_sin_precio = (int)scalar($pdo,
    "SELECT COUNT(*) FROM productos WHERE activo = 1 AND precio_venta <= 0");
t($G, "Productos activos tienen precio_venta > 0",
    $prod_sin_precio === 0,
    $prod_sin_precio > 0 ? "{$prod_sin_precio} productos activos sin precio de venta." : '',
    true);

// ════════════════════════════════════════════════════════════════════════════════
//  G12 — FOREIGN KEYS (integridad referencial por software)
//  ajustes_stock no tiene FKs en BD (se creó sin ellas para evitar errno 121
//  en cPanel). La integridad la garantiza el PHP. Las demás tablas usan FKs.
// ════════════════════════════════════════════════════════════════════════════════

$G = 'G13 Foreign Keys';

// ventas → clientes (NULL = venta de mostrador, sin cliente registrado)
$v_cliente_roto = (int)scalar($pdo,
    "SELECT COUNT(*) FROM ventas v
     LEFT JOIN clientes c ON c.id = v.cliente_id
     WHERE v.cliente_id IS NOT NULL AND c.id IS NULL");
t($G, "ventas → clientes sin FK rotas",
    $v_cliente_roto === 0,
    $v_cliente_roto > 0 ? "{$v_cliente_roto} ventas con cliente_id inexistente." : '');

// venta_detalles → ventas
$vd_venta_roto = (int)scalar($pdo,
    "SELECT COUNT(*) FROM venta_detalles vd
     LEFT JOIN ventas v ON v.id = vd.venta_id WHERE v.id IS NULL");
t($G, "venta_detalles → ventas sin FK rotas",
    $vd_venta_roto === 0,
    $vd_venta_roto > 0 ? "{$vd_venta_roto} detalles sin venta padre." : '');

// venta_detalles → productos
$vd_prod_roto = (int)scalar($pdo,
    "SELECT COUNT(*) FROM venta_detalles vd
     LEFT JOIN productos p ON p.id = vd.producto_id WHERE p.id IS NULL");
t($G, "venta_detalles → productos sin FK rotas",
    $vd_prod_roto === 0,
    $vd_prod_roto > 0 ? "{$vd_prod_roto} detalles con producto_id inexistente." : '');

// compra_detalles → insumos
$cd_ins_roto = (int)scalar($pdo,
    "SELECT COUNT(*) FROM compra_detalles cd
     LEFT JOIN insumos i ON i.id = cd.insumo_id WHERE i.id IS NULL");
t($G, "compra_detalles → insumos sin FK rotas",
    $cd_ins_roto === 0,
    $cd_ins_roto > 0 ? "{$cd_ins_roto} detalles de compra con insumo_id inexistente." : '');

// recetas → productos e insumos
$rec_p = (int)scalar($pdo,
    "SELECT COUNT(*) FROM recetas r LEFT JOIN productos p ON p.id = r.producto_id WHERE p.id IS NULL");
$rec_i = (int)scalar($pdo,
    "SELECT COUNT(*) FROM recetas r LEFT JOIN insumos i ON i.id = r.insumo_id WHERE i.id IS NULL");
t($G, "recetas → productos sin FK rotas",
    $rec_p === 0, $rec_p > 0 ? "{$rec_p} recetas con producto_id inexistente." : '');
t($G, "recetas → insumos sin FK rotas",
    $rec_i === 0, $rec_i > 0 ? "{$rec_i} recetas con insumo_id inexistente." : '');

// ════════════════════════════════════════════════════════════════════════════════
//  G13 — CONFIGURACIÓN DEL SISTEMA
//  Todas las claves de configuracion_app (tema, logos, tipografía) y
//  configuracion_negocio (SMLMV, costos) deben existir.
// ════════════════════════════════════════════════════════════════════════════════

// ════════════════════════════════════════════════════════════════════════════════
//  G14 extra — CATÁLOGOS DEL SISTEMA (migración 029)
// ════════════════════════════════════════════════════════════════════════════════
{
    $GC = 'G14b Catalogos';
    if (tabla_existe($pdo, 'listas_sistema')) {
        foreach (['presentacion','unidad_medida','categoria_insumo','categoria_activo','categoria_costo','categoria_proveedor'] as $_tipo) {
            $n = (int)scalar($pdo, 'SELECT COUNT(*) FROM listas_sistema WHERE tipo = ? AND activo = 1', [$_tipo]);
            t($GC, "Catalogo '{$_tipo}' tiene items activos", $n > 0,
              $n === 0 ? "Sin items. Ejecutar migracion 029 o agregar desde Admin → Catalogos." : '');
        }
        $dup = (int)scalar($pdo, 'SELECT COUNT(*) FROM (SELECT tipo,valor FROM listas_sistema GROUP BY tipo,valor HAVING COUNT(*)>1) s');
        t($GC, "No hay tipo+valor duplicados en listas_sistema", $dup === 0,
          $dup > 0 ? "{$dup} duplicados." : '');
    } else {
        t($GC, "Tabla listas_sistema existe", false, "Aplicar migracion 029_listas_sistema.sql.");
    }
}

$G = 'G15 Configuracion';

// Claves de UI y tema (migración 016 + 016b + 016c)
$claves_app = [
    'nombre_negocio', 'logo_url', 'logo_url_login',
    'theme_brand', 'theme_dark', 'theme_radius',
    'theme_font', 'font_heading',
    'font_size_title', 'font_size_subtitle', 'font_size_body', 'font_size_small',
    'color_text', 'color_text_sec',
];
foreach ($claves_app as $clave) {
    $existe = (int)scalar($pdo, "SELECT COUNT(*) FROM configuracion_app WHERE clave = ?", [$clave]);
    t($G, "configuracion_app.{$clave} existe",
      $existe > 0, $existe === 0 ? "Clave faltante. Aplicar migracion 016c." : '');
}

// Claves numéricas del negocio
$claves_neg = ['SMLMV', 'costos_fijos_mensuales', 'produccion_estimada_mensual'];
foreach ($claves_neg as $clave) {
    $existe = (int)scalar($pdo, "SELECT COUNT(*) FROM configuracion_negocio WHERE clave = ?", [$clave]);
    t($G, "configuracion_negocio.{$clave} existe",
      $existe > 0, $existe === 0 ? "Clave faltante en configuracion_negocio." : '',
      true); // WARN: algunas instalaciones usan valores por defecto en el código
}

// Al menos un superadmin activo en el sistema
$superadmin_activo = (int)scalar($pdo,
    "SELECT COUNT(*) FROM usuarios WHERE rol = 'superadmin' AND activo = 1");
t($G, "Existe al menos un superadmin activo",
    $superadmin_activo >= 1,
    $superadmin_activo === 0 ? "No hay superadmin activo. El sistema quedaria sin acceso de administracion." : '');

// ════════════════════════════════════════════════════════════════════════════════
//  G16 — SEGURIDAD Y VULNERABILIDADES
//  Verifica controles de seguridad activos: contraseñas, entorno, rate-limiting.
//  No puede testear CSRF o rutas HTTP desde aquí, pero sí la configuración de BD
//  y las constantes de PHP que afectan la postura de seguridad.
// ════════════════════════════════════════════════════════════════════════════════

$G = 'G16 Seguridad';

// Todos los usuarios activos deben tener password hasheado con bcrypt ($2y$ o $2b$)
$usuario_sin_hash = (int)scalar($pdo,
    "SELECT COUNT(*) FROM usuarios WHERE activo = 1 AND (password_hash IS NULL OR password_hash = '')");
t($G, "Todos los usuarios activos tienen password_hash",
    $usuario_sin_hash === 0,
    $usuario_sin_hash > 0 ? "{$usuario_sin_hash} usuarios sin contrasena." : '');

$hash_no_bcrypt = (int)scalar($pdo,
    "SELECT COUNT(*) FROM usuarios WHERE activo = 1 AND password_hash NOT LIKE '\$2%'");
t($G, "Contrasenas usan bcrypt (\$2y\$/\$2b\$)",
    $hash_no_bcrypt === 0,
    $hash_no_bcrypt > 0 ? "{$hash_no_bcrypt} usuarios con hash no-bcrypt." : '');

// APP_ENV debe ser 'production' en servidor. 'development' expone errores PHP al navegador.
t($G, "APP_ENV = 'production' (no expone errores al navegador)",
    defined('APP_ENV') && APP_ENV === 'production',
    defined('APP_ENV') && APP_ENV !== 'production'
        ? "APP_ENV = '" . APP_ENV . "'. Cambiar a 'production' antes de usar en produccion real." : '');

// BCRYPT_COST >= 12 (costo mínimo recomendado por OWASP para PHP 2024)
t($G, "BCRYPT_COST >= 12 (seguridad minima OWASP)",
    defined('BCRYPT_COST') && BCRYPT_COST >= 12,
    defined('BCRYPT_COST') ? "Costo actual: " . BCRYPT_COST : "Constante BCRYPT_COST no definida.");

// SESSION_LIFETIME razonable (entre 30 minutos y 8 horas)
$lifetime_ok = defined('SESSION_LIFETIME') && SESSION_LIFETIME >= 1800 && SESSION_LIFETIME <= 28800;
t($G, "SESSION_LIFETIME entre 30min y 8h",
    $lifetime_ok,
    defined('SESSION_LIFETIME') ? "Valor actual: " . SESSION_LIFETIME . "s" : "Constante no definida.",
    !$lifetime_ok); // WARN si está fuera de rango

// login_intentos accesible (tabla de rate-limiting)
t($G, "Tabla login_intentos accesible (rate-limiting activo)",
    tabla_existe($pdo, 'login_intentos'),
    "Sin login_intentos, el sistema no puede bloquear ataques de fuerza bruta.");

// MAX_LOGIN_INTENTOS razonable (3-10)
$intentos_ok = defined('MAX_LOGIN_INTENTOS') && MAX_LOGIN_INTENTOS >= 3 && MAX_LOGIN_INTENTOS <= 10;
t($G, "MAX_LOGIN_INTENTOS entre 3 y 10",
    $intentos_ok,
    defined('MAX_LOGIN_INTENTOS') ? "Valor actual: " . MAX_LOGIN_INTENTOS : "Constante no definida.",
    !$intentos_ok);

// No debe haber usuarios sin rol definido
$usuario_sin_rol = (int)scalar($pdo,
    "SELECT COUNT(*) FROM usuarios WHERE activo = 1 AND (rol IS NULL OR rol = '')");
t($G, "Todos los usuarios activos tienen rol definido",
    $usuario_sin_rol === 0,
    $usuario_sin_rol > 0 ? "{$usuario_sin_rol} usuarios sin rol. Podrian tener acceso inesperado." : '');

// ════════════════════════════════════════════════════════════════════════════════
//  G15 — AUDITORÍA
//  logs_historial debe estar activo y tener registros recientes.
//  Las últimas modificaciones críticas deben trazarse.
// ════════════════════════════════════════════════════════════════════════════════

$G = 'G17 Auditoria';

$total_logs = (int)scalar($pdo, "SELECT COUNT(*) FROM logs_historial");
t($G, "logs_historial tiene registros",
    $total_logs > 0,
    "No hay registros de auditoria. Verificar que AuditoriaHelper.php este siendo incluido.",
    $total_logs === 0);

// Registros de auditoría en los últimos 30 días (indica actividad reciente del sistema)
$logs_recientes = (int)scalar($pdo,
    "SELECT COUNT(*) FROM logs_historial WHERE fecha_cambio >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
t($G, "Hay registros de auditoria en los ultimos 30 dias",
    $logs_recientes > 0,
    "Sin actividad auditada en 30 dias. Normal si es instalacion nueva.",
    $logs_recientes === 0);

// La tabla login_intentos no debe tener bloqueos activos (indica ataque en curso)
$bloqueos_activos = (int)scalar($pdo,
    "SELECT COUNT(DISTINCT email) FROM login_intentos
     WHERE exitoso = 0 AND created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)",
    [defined('LOGIN_BLOQUEO_MINS') ? LOGIN_BLOQUEO_MINS : 15]);
t($G, "No hay cuentas bloqueadas por fuerza bruta en este momento",
    $bloqueos_activos === 0,
    $bloqueos_activos > 0 ? "{$bloqueos_activos} cuentas con intentos fallidos recientes (posible ataque)." : '',
    $bloqueos_activos > 0); // WARN, no error: es info operativa

// ════════════════════════════════════════════════════════════════════════════════
//  G18 — EFICIENCIA Y RENDIMIENTO
//  Detecta patrones que degradan el rendimiento: tablas grandes sin índice,
//  configuraciones faltantes que obligan a full-scans frecuentes.
// ════════════════════════════════════════════════════════════════════════════════

$G = 'G18 Eficiencia';

// ventas.fecha_venta debe estar indexada (todos los reportes filtran por fecha)
$idx_fv = (int)scalar($pdo,
    "SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ventas'
       AND COLUMN_NAME = 'fecha_venta'");
t($G, "Indice en ventas.fecha_venta (filtros de reportes)",
    $idx_fv > 0,
    "Sin indice → full-scan en cada reporte. Agregar: CREATE INDEX idx_ventas_fecha ON ventas(fecha_venta)");

// ventas.estado también se filtra con frecuencia
$idx_ve = (int)scalar($pdo,
    "SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ventas'
       AND COLUMN_NAME = 'estado'");
t($G, "Indice en ventas.estado (filtros de historial)",
    $idx_ve > 0,
    "Considerar: CREATE INDEX idx_ventas_estado ON ventas(estado)",
    true); // WARN: útil pero no crítico

// insumos.activo se usa en casi toda query de insumos
$idx_ia = (int)scalar($pdo,
    "SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'insumos'
       AND COLUMN_NAME = 'activo'");
t($G, "Indice en insumos.activo",
    $idx_ia > 0,
    "Considerar: CREATE INDEX idx_insumos_activo ON insumos(activo)",
    true);

// listas_sistema tiene índice en (tipo, activo, orden) — crítico para cada página con selects
if (tabla_existe($pdo, 'listas_sistema')) {
    $idx_ls = (int)scalar($pdo,
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'listas_sistema'
           AND INDEX_NAME = 'idx_lista_tipo'");
    t($G, "Indice idx_lista_tipo en listas_sistema",
      $idx_ls > 0,
      "Sin indice → cada listas_get() hace full-scan. Aplicar migracion 029.");
}

// Verificar que no hay ventas con cantidades absurdas (posible abuso)
$cant_absurda = (int)scalar($pdo,
    "SELECT COUNT(*) FROM venta_detalles WHERE cantidad > 9999");
t($G, "No hay lineas de venta con cantidad > 9999",
    $cant_absurda === 0,
    $cant_absurda > 0 ? "{$cant_absurda} lineas con cantidad sospechosa." : '',
    true);

// ════════════════════════════════════════════════════════════════════════════════
//  G19 — PRUEBAS DE USUARIO (UX y completitud de datos)
//  Verifica que el sistema tiene datos mínimos para operar correctamente
//  y que la configuración del negocio está completa.
// ════════════════════════════════════════════════════════════════════════════════

$G = 'G19 Usuario UX';

// Debe haber al menos un producto activo para que el POS funcione
$prods_activos = (int)scalar($pdo,
    "SELECT COUNT(*) FROM productos WHERE activo = 1 AND precio_venta > 0");
t($G, "Hay al menos un producto activo con precio para el POS",
    $prods_activos > 0,
    "Sin productos activos → el POS estará vacío.",
    $prods_activos === 0);

// El nombre del negocio no debe ser el default 'ClanDestino'
$nombre_negocio = scalar($pdo,
    "SELECT valor FROM configuracion_app WHERE clave = 'nombre_negocio'");
t($G, "Nombre del negocio configurado (no es el default)",
    !empty($nombre_negocio) && strtolower(trim($nombre_negocio)) !== 'clandestino',
    "Cambiar en Admin → Apariencia.",
    true);

// El superadmin no debe seguir usando la contraseña de ejemplo 'Admin2026!'
$superadmin_pass = scalar($pdo,
    "SELECT password_hash FROM usuarios WHERE rol = 'superadmin' AND activo = 1 LIMIT 1");
$usa_pass_default = $superadmin_pass && password_verify('Admin2026!', $superadmin_pass);
t($G, "El superadmin no usa la contrasena de ejemplo (Admin2026!)",
    !$usa_pass_default,
    "CAMBIAR LA CONTRASENA DEL SUPERADMIN INMEDIATAMENTE. Usa Admin → Usuarios.");

// Debe haber al menos un insumo configurado (el sistema sin insumos no calcula costos)
$insumos_count = (int)scalar($pdo, "SELECT COUNT(*) FROM insumos WHERE activo = 1");
t($G, "Hay insumos activos configurados",
    $insumos_count > 0,
    "Sin insumos → costos de productos seran 0.",
    $insumos_count === 0);

// Catálogos básicos deben tener al menos un ítem (para que los selects no estén vacíos)
if (tabla_existe($pdo, 'listas_sistema')) {
    $tipos_criticos = ['presentacion', 'unidad_medida', 'categoria_insumo'];
    foreach ($tipos_criticos as $tipo) {
        $n = (int)scalar($pdo, "SELECT COUNT(*) FROM listas_sistema WHERE tipo=? AND activo=1", [$tipo]);
        t($G, "Catalogo '{$tipo}' tiene opciones activas",
          $n > 0,
          "Sin opciones → el select estara vacio en formularios.",
          $n === 0);
    }
}

// ════════════════════════════════════════════════════════════════════════════════
//  G20 — INMUTABILIDAD DE PRECIOS HISTÓRICOS (verificación profunda)
//  El principio central del sistema: ningún precio registrado en una transacción
//  debe cambiar cuando cambian los precios actuales del mercado.
//  Solo las correcciones explícitas de errores de digitación son permitidas.
// ════════════════════════════════════════════════════════════════════════════════

$G = 'G20 Inmutabilidad Precios';

// Verificar que ningun venta_detalle tiene precio_unitario = 0 en ventas activas
// (precio 0 indicaría un registro corrupto o no capturado correctamente)
$precios_cero = (int)scalar($pdo,
    "SELECT COUNT(*) FROM venta_detalles vd
     JOIN ventas v ON v.id = vd.venta_id
     WHERE v.estado != 'anulada' AND vd.precio_unitario = 0");
t($G, "Ninguna linea de venta activa tiene precio_unitario = 0",
    $precios_cero === 0,
    $precios_cero > 0 ? "{$precios_cero} lineas con precio 0 en ventas activas." : '',
    $precios_cero > 0);

// Verificar que costo_unitario en lotes activos no fue alterado post-produccion
// (la columna solo debe NULL cuando no habia costo configurado al producir, no 0)
$lote_costo_cero = (int)scalar($pdo,
    "SELECT COUNT(*) FROM produccion_lotes
     WHERE estado = 'activo' AND costo_unitario IS NOT NULL AND costo_unitario = 0");
t($G, "Lotes activos no tienen costo_unitario = 0 (snapshot debe ser positivo o NULL)",
    $lote_costo_cero === 0,
    $lote_costo_cero > 0 ? "{$lote_costo_cero} lotes con costo_unitario = 0 (puede indicar snapshot invalido)." : '',
    true); // WARN: puede ocurrir si el producto no tenia receta al producir

// Verificar que nomina_liquidaciones.salario_base nunca fue actualizado despues del INSERT
// Se verifica comparando con el created_at vs updated_at si la tabla lo permite
// Como control alternativo: ninguna liquidacion pagada debe tener salario_base = 0
$nom_sal_cero = (int)scalar($pdo,
    "SELECT COUNT(*) FROM nomina_liquidaciones WHERE salario_base <= 0");
t($G, "Todas las liquidaciones tienen salario_base > 0",
    $nom_sal_cero === 0,
    $nom_sal_cero > 0 ? "{$nom_sal_cero} liquidaciones con salario_base invalido." : '');

// Verificar que compra_detalles.precio_unitario > 0 en todas las compras registradas
$compra_precio_invalido = (int)scalar($pdo,
    "SELECT COUNT(*) FROM compra_detalles WHERE precio_unitario <= 0 AND subtotal > 0");
t($G, "Todas las lineas de compra tienen precio_unitario > 0",
    $compra_precio_invalido === 0,
    $compra_precio_invalido > 0 ? "{$compra_precio_invalido} lineas de compra con precio invalido." : '');

// ════════════════════════════════════════════════════════════════════════════════
//  G21 — MIGRACIÓN 031: ENUMs → VARCHAR (catálogos dinámicos)
//  Verifica si la migración 031 está aplicada. Sin ella, los catálogos
//  configurables desde Admin → Catálogos no pueden guardar valores personalizados.
//  Crítico para que productos, insumos y activos acepten categorías nuevas.
// ════════════════════════════════════════════════════════════════════════════════

$G = 'G21 Migracion 031 Catalogos';

$cols_varchar = [
    // [tabla, columna, descripción]
    ['productos', 'categoria',       'Permite categorias de producto personalizadas'],
    ['productos', 'tamano',          'Permite tamanos de producto personalizados'],
    ['insumos',   'unidad_medida',   'Permite unidades de medida personalizadas'],
    ['insumos',   'presentacion',    'Permite presentaciones personalizadas'],
    ['activos',   'categoria_activo','Permite categorias de activo personalizadas'],
];

foreach ($cols_varchar as [$tabla, $col, $desc]) {
    // INFORMATION_SCHEMA puede estar restringido — usar try/catch
    try {
        $tipo = scalar($pdo,
            "SELECT DATA_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$tabla, $col]);
        $es_varchar = ($tipo === 'varchar');
        t($G, "{$tabla}.{$col} es VARCHAR (no ENUM)",
          $es_varchar,
          $es_varchar ? '' : "{$tipo} — Aplicar 031_enum_a_varchar.sql desde Admin → BD. {$desc}");
    } catch (\Exception $e) {
        t($G, "{$tabla}.{$col} tipo verificable",
          false, "No se pudo consultar information_schema. Verificar manualmente.", true);
    }
}

// ════════════════════════════════════════════════════════════════════════════════
//  G22 — INTEGRIDAD DE SNAPSHOTS (migraciones 032-034)
//  Verifica que los campos snapshot están presentes y, cuando existen,
//  tienen valores coherentes con las tablas maestro.
// ════════════════════════════════════════════════════════════════════════════════

$G = 'G22 Snapshots 032-034';

// Migración 032: compra_detalles con presentación — coherencia interna
// Si cant_presentaciones > 0 y cantidad_presentacion > 0, entonces:
// cantidad ≈ cant_presentaciones × cantidad_presentacion (tolerancia de redondeo)
if (tabla_existe($pdo, 'compra_detalles') && columna_existe($pdo, 'compra_detalles', 'cant_presentaciones')) {
    $pres_incoherentes = (int)scalar($pdo,
        "SELECT COUNT(*) FROM compra_detalles
         WHERE cant_presentaciones IS NOT NULL AND cant_presentaciones > 0
           AND cantidad_presentacion IS NOT NULL AND cantidad_presentacion > 0
           AND ABS(cantidad - (cant_presentaciones * cantidad_presentacion)) > 1");
    t($G, "Compras: cant_presentaciones × cantidad_presentacion ≈ cantidad",
        $pres_incoherentes === 0,
        $pres_incoherentes > 0 ? "{$pres_incoherentes} lineas con presentacion incoherente." : '',
        $pres_incoherentes > 0);

    // Si tiene precio_presentacion, precio_unitario ≈ precio_pres / cant_pres
    $precio_pres_incoherente = (int)scalar($pdo,
        "SELECT COUNT(*) FROM compra_detalles
         WHERE precio_presentacion IS NOT NULL AND precio_presentacion > 0
           AND cantidad_presentacion IS NOT NULL AND cantidad_presentacion > 0
           AND ABS(precio_unitario - (precio_presentacion / cantidad_presentacion)) > 1");
    t($G, "Compras: precio_presentacion / cantidad_presentacion ≈ precio_unitario",
        $precio_pres_incoherente === 0,
        $precio_pres_incoherente > 0 ? "{$precio_pres_incoherente} lineas con precio de presentacion incoherente." : '',
        $precio_pres_incoherente > 0);
} else {
    t($G, "Migración 032 aplicada (compra_detalles.cant_presentaciones)",
      false, "Aplicar 032_compra_detalles_presentacion.sql");
}

// Migración 034: venta_detalles con nombre_snap
// Ventas NUEVAS (post-migración) deberían tener nombre_snap; las anteriores NULL está bien
if (columna_existe($pdo, 'venta_detalles', 'nombre_snap')) {
    // Verificar que ningún nombre_snap sea string vacío (NULL sí es válido para datos pre-034)
    $snap_vacio = (int)scalar($pdo,
        "SELECT COUNT(*) FROM venta_detalles WHERE nombre_snap = ''");
    t($G, "venta_detalles: nombre_snap nunca es string vacío (NULL está permitido)",
        $snap_vacio === 0,
        $snap_vacio > 0 ? "{$snap_vacio} detalles con nombre_snap vacío en lugar de NULL." : '');
} else {
    t($G, "Migración 034 aplicada (venta_detalles.nombre_snap)",
      false, "Aplicar 034_snapshots_nombres_y_saldo.sql", true);
}

// Migración 034: pagos_fiado con saldo_anterior
if (columna_existe($pdo, 'pagos_fiado', 'saldo_anterior')) {
    // Coherencia: saldo_posterior debe ser saldo_anterior - monto (cuando ambos no son NULL)
    $saldo_incoherente = (int)scalar($pdo,
        "SELECT COUNT(*) FROM pagos_fiado
         WHERE saldo_anterior IS NOT NULL AND saldo_posterior IS NOT NULL
           AND ABS((saldo_anterior - monto) - saldo_posterior) > 1");
    t($G, "pagos_fiado: saldo_posterior = saldo_anterior - monto",
        $saldo_incoherente === 0,
        $saldo_incoherente > 0 ? "{$saldo_incoherente} abonos con saldo incoherente." : '');

    // Ningún saldo_anterior debería ser negativo
    $saldo_neg = (int)scalar($pdo,
        "SELECT COUNT(*) FROM pagos_fiado WHERE saldo_anterior IS NOT NULL AND saldo_anterior < 0");
    t($G, "pagos_fiado: saldo_anterior nunca es negativo",
        $saldo_neg === 0,
        $saldo_neg > 0 ? "{$saldo_neg} abonos con saldo_anterior negativo." : '');
} else {
    t($G, "Migración 034 aplicada (pagos_fiado.saldo_anterior)",
      false, "Aplicar 034_snapshots_nombres_y_saldo.sql", true);
}

// Migración 033: nomina con valor_hora_snap
if (columna_existe($pdo, 'nomina_liquidaciones', 'valor_hora_snap')) {
    // Para contratos por_horas, valor_hora_snap debería existir en registros nuevos
    $hora_snap_faltante = (int)scalar($pdo,
        "SELECT COUNT(*) FROM nomina_liquidaciones
         WHERE tipo_contrato = 'por_horas'
           AND horas_trabajadas > 0
           AND valor_hora_snap IS NULL
           AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)");
    t($G, "Nómina por_horas reciente tiene valor_hora_snap",
        $hora_snap_faltante === 0,
        $hora_snap_faltante > 0 ? "{$hora_snap_faltante} liquidaciones recientes por_horas sin valor_hora_snap." : '',
        $hora_snap_faltante > 0);
} else {
    t($G, "Migración 033 aplicada (nomina_liquidaciones.valor_hora_snap)",
      false, "Aplicar 033_nomina_snapshots.sql", true);
}

// ════════════════════════════════════════════════════════════════════════════════
//  G23 — VARIANTES DE PRODUCTO (migración 035)
//  Verifica la tabla producto_variantes y las columnas de variante en venta_detalles.
// ════════════════════════════════════════════════════════════════════════════════

$G = 'G23 Variantes 035';

$tiene_pv = columna_existe($pdo, 'producto_variantes', 'id');
t($G, "Tabla producto_variantes existe",
    $tiene_pv, "Aplicar 035_variantes_producto.sql", !$tiene_pv);

$tiene_vd_var = columna_existe($pdo, 'venta_detalles', 'variante_id');
t($G, "venta_detalles.variante_id existe (mig.035)",
    $tiene_vd_var, "Aplicar 035_variantes_producto.sql", !$tiene_vd_var);

$tiene_vd_snap = columna_existe($pdo, 'venta_detalles', 'factor_receta_snap');
t($G, "venta_detalles.factor_receta_snap existe (mig.035)",
    $tiene_vd_snap, "Aplicar 035_variantes_producto.sql", !$tiene_vd_snap);

if ($tiene_pv) {
    // Factor de receta debe estar en rango válido (0.001 - 10)
    $factor_invalido = (int)scalar($pdo,
        "SELECT COUNT(*) FROM producto_variantes WHERE factor_receta <= 0 OR factor_receta > 10");
    t($G, "producto_variantes: factor_receta en rango válido (0.001–10)",
        $factor_invalido === 0,
        $factor_invalido > 0 ? "{$factor_invalido} variantes con factor fuera de rango." : '');

    // Precio de venta debe ser positivo
    $precio_invalido = (int)scalar($pdo,
        "SELECT COUNT(*) FROM producto_variantes WHERE precio_venta <= 0");
    t($G, "producto_variantes: precio_venta siempre positivo",
        $precio_invalido === 0,
        $precio_invalido > 0 ? "{$precio_invalido} variantes con precio <= 0." : '');

    // No deben existir etiquetas duplicadas activas para el mismo producto
    $dup_etiquetas = (int)scalar($pdo,
        "SELECT COUNT(*) FROM (
            SELECT producto_id, etiqueta, COUNT(*) AS cnt
            FROM producto_variantes WHERE activo = 1
            GROUP BY producto_id, etiqueta HAVING cnt > 1
         ) AS dups");
    t($G, "producto_variantes: sin etiquetas duplicadas activas por producto",
        $dup_etiquetas === 0,
        $dup_etiquetas > 0 ? "{$dup_etiquetas} combinaciones producto+etiqueta con duplicados activos." : '');
}

if ($tiene_vd_snap) {
    // factor_receta_snap debe estar en rango válido cuando no es NULL
    $snap_invalido = (int)scalar($pdo,
        "SELECT COUNT(*) FROM venta_detalles
         WHERE factor_receta_snap IS NOT NULL
           AND (factor_receta_snap <= 0 OR factor_receta_snap > 10)");
    t($G, "venta_detalles: factor_receta_snap en rango válido cuando no es NULL",
        $snap_invalido === 0,
        $snap_invalido > 0 ? "{$snap_invalido} detalles con factor_receta_snap fuera de rango." : '');

    // variante_id y variante_etiqueta deben aparecer juntos (si uno está, el otro también)
    $var_inconsistente = (int)scalar($pdo,
        "SELECT COUNT(*) FROM venta_detalles
         WHERE (variante_id IS NULL) != (variante_etiqueta IS NULL)");
    t($G, "venta_detalles: variante_id y variante_etiqueta son coherentes (ambos NULL o ambos NOT NULL)",
        $var_inconsistente === 0,
        $var_inconsistente > 0 ? "{$var_inconsistente} detalles con variante_id/variante_etiqueta incoherentes." : '');
}

// ── Tiempo total de ejecución ─────────────────────────────────────────────────
$tiempo        = round(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'], 3);
$total_pruebas = $pass + $fail + $warn;

// ════════════════════════════════════════════════════════════════════════════════
//  RENDER HTML
// ════════════════════════════════════════════════════════════════════════════════
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Suite de Pruebas — ClanDestino ERP</title>
    <style>
        :root {
            --brand:#e94f37; --dark:#111827; --g5:#6b7280; --g8:#d1d5db;
            --g9:#f3f4f6; --white:#fff; --green:#059669; --yellow:#d97706; --red:#dc2626;
        }
        *, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }
        body { font-family:system-ui,sans-serif; background:var(--g9); color:var(--dark); padding:20px 16px 60px; }
        h1   { font-size:22px; font-weight:800; margin-bottom:4px; }
        .meta { font-size:12px; color:var(--g5); margin-bottom:20px; }
        /* Tarjetas de resumen */
        .resumen { display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:24px; }
        .kpi { background:var(--white); border-radius:12px; padding:14px 18px; box-shadow:0 1px 4px rgba(0,0,0,.06); }
        .kpi-n { font-size:24px; font-weight:800; }
        .kpi-l { font-size:11px; color:var(--g5); text-transform:uppercase; }
        /* Grupos de pruebas */
        .grupo { background:var(--white); border-radius:12px; margin-bottom:14px; overflow:hidden; box-shadow:0 1px 4px rgba(0,0,0,.06); }
        .grupo-hdr { font-size:13px; font-weight:700; padding:10px 16px; background:var(--g9); border-bottom:1px solid var(--g8); }
        /* Tabla de resultados */
        table { width:100%; border-collapse:collapse; }
        td  { padding:8px 16px; font-size:13px; border-bottom:1px solid var(--g9); }
        tr:last-child td { border-bottom:none; }
        .td-name { width:65%; }
        /* Badges de estado */
        .badge { font-size:10px; font-weight:700; padding:2px 8px; border-radius:20px; white-space:nowrap; }
        .b-pass { background:#d1fae5; color:#065f46; }
        .b-fail { background:#fee2e2; color:#991b1b; }
        .b-warn { background:#fef3c7; color:#92400e; }
        .detail { font-size:11px; color:var(--g5); margin-top:2px; }
        /* Alerta final */
        .alerta { border-radius:12px; padding:14px 18px; margin-top:8px; font-size:13px; }
        /* Responsive */
        @media(max-width:600px){
            .resumen { grid-template-columns:1fr 1fr; }
            .kpi-n  { font-size:20px; }
        }
    </style>
</head>
<body>

<h1>&#129514; Suite de Pruebas — ClanDestino ERP v<?= APP_VERSION ?></h1>
<p class="meta">
    Ejecutado: <?= date('d/m/Y H:i:s') ?> |
    <?= $tiempo ?>s |
    <?= $total_pruebas ?> pruebas |
    22 grupos
</p>

<!-- Resumen global -->
<div class="resumen">
    <div class="kpi">
        <div class="kpi-n" style="color:var(--green)"><?= $pass ?></div>
        <div class="kpi-l">Pasaron</div>
    </div>
    <div class="kpi">
        <div class="kpi-n" style="color:var(--red)"><?= $fail ?></div>
        <div class="kpi-l">Fallaron</div>
    </div>
    <div class="kpi">
        <div class="kpi-n" style="color:var(--yellow)"><?= $warn ?></div>
        <div class="kpi-l">Advertencias</div>
    </div>
    <div class="kpi">
        <div class="kpi-n"><?= $total_pruebas ?></div>
        <div class="kpi-l">Total</div>
    </div>
</div>

<?php
// Agrupar resultados por grupo y renderizar una tarjeta por grupo
$por_grupo = [];
foreach ($resultados as $r) {
    $por_grupo[$r['grupo']][] = $r;
}

foreach ($por_grupo as $grupo => $items):
    $g_fail  = count(array_filter($items, fn($i) => $i['estado'] === 'FAIL'));
    $g_warn  = count(array_filter($items, fn($i) => $i['estado'] === 'WARN'));
    $g_pass  = count(array_filter($items, fn($i) => $i['estado'] === 'PASS'));
    $g_color = $g_fail > 0 ? 'var(--red)' : ($g_warn > 0 ? 'var(--yellow)' : 'var(--green)');
?>
<div class="grupo">
    <div class="grupo-hdr" style="border-left:4px solid <?= $g_color ?>; padding-left:12px">
        <?= htmlspecialchars($grupo) ?>
        &nbsp;—&nbsp;
        <span style="color:var(--green)"><?= $g_pass ?> OK</span>
        <?php if ($g_fail > 0): ?>
            &nbsp;<span style="color:var(--red)"><?= $g_fail ?> FAIL</span>
        <?php endif; ?>
        <?php if ($g_warn > 0): ?>
            &nbsp;<span style="color:var(--yellow)"><?= $g_warn ?> WARN</span>
        <?php endif; ?>
    </div>
    <table>
        <?php foreach ($items as $item): ?>
        <tr>
            <td class="td-name">
                <?= htmlspecialchars($item['nombre']) ?>
                <?php if ($item['detalle']): ?>
                <div class="detail"><?= htmlspecialchars($item['detalle']) ?></div>
                <?php endif; ?>
            </td>
            <td>
                <span class="badge b-<?= strtolower($item['estado']) ?>"><?= $item['estado'] ?></span>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>
<?php endforeach; ?>

<!-- Resultado final -->
<?php if ($fail > 0): ?>
<div class="alerta" style="background:#fef2f2;border:1px solid #fecaca">
    <strong>Se encontraron <?= $fail ?> prueba(s) fallida(s).</strong>
    Revisar los detalles e aplicar las correcciones antes de usar en produccion.
</div>
<?php elseif ($warn > 0): ?>
<div class="alerta" style="background:#fffbeb;border:1px solid #fde68a">
    <strong>&#9989; Todas las pruebas pasaron.</strong>
    Hay <?= $warn ?> advertencia(s) — no son errores criticos pero conviene revisarlas.
</div>
<?php else: ?>
<div class="alerta" style="background:#f0fdf4;border:1px solid #bbf7d0">
    <strong>&#9989; Todas las <?= $total_pruebas ?> pruebas pasaron sin advertencias.</strong>
    El sistema esta en perfecto estado de integridad.
</div>
<?php endif; ?>

</body>
</html>
