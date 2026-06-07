<?php
/**
 * public_html/dashboard.php
 * Panel principal del ERP. Muestra módulos accesibles y estadísticas del día.
 * Requiere sesión activa — el middleware redirige al login si no hay sesión.
 */

require_once __DIR__ . '/app/middleware/auth_check.php';
require_once __DIR__ . '/app/config/database.php';

// ---- Estadísticas rápidas del día ----
// Cada dato se consulta solo si el usuario tiene acceso al módulo correspondiente.

$stats = [
    'ventas_hoy'      => 0,
    'ventas_total'    => 0,
    'insumos_bajos'   => 0,
    'fiado_total'     => 0,
    'stock_terminado' => 0,  // unidades de producto terminado listas para vender
    'produccion_hoy'  => 0,  // sándwiches producidos hoy
    'costos_mes'      => 0,  // costos indirectos activos del mes
];

if (permiso_tiene('ventas', 'solo_ver')) {
    $row = db()->query(
        "SELECT COUNT(*) AS n, IFNULL(SUM(total),0) AS monto
         FROM ventas
         WHERE DATE(fecha_venta) = CURDATE() AND estado = 'completada'"
    )->fetch();
    $stats['ventas_hoy']   = (int)$row['n'];
    $stats['ventas_total'] = (float)$row['monto'];

    // Fiado real: suma de ventas fiadas sin fecha_pago (no pagadas aún)
    $row2 = db()->query(
        "SELECT IFNULL(SUM(total),0) AS total
         FROM ventas
         WHERE metodo_pago = 'fiado' AND fecha_pago IS NULL AND estado != 'anulada'"
    )->fetch();
    $stats['fiado_total'] = (float)$row2['total'];

    // Meta de ventas diaria (clave opcional — no falla si no existe aún)
    try {
        $m = db()->query(
            "SELECT valor FROM configuracion_negocio WHERE clave = 'meta_ventas_diaria' LIMIT 1"
        )->fetchColumn();
        $meta_diaria = (float)($m ?: 0);
    } catch (\Exception $e) { $meta_diaria = 0.0; }
    if ($meta_diaria > 0) {
        $meta_pct       = min(100, (int)round($stats['ventas_total'] / $meta_diaria * 100));
        $meta_alcanzada = $stats['ventas_total'] >= $meta_diaria;
    } else {
        $meta_pct       = 0;
        $meta_alcanzada = false;
    }

    // Racha de días consecutivos cumpliendo la meta diaria (gamificación)
    $racha_meta = 0;
    if ($meta_diaria > 0) {
        try {
            $dias_meta = db()->query(
                "SELECT DATE(fecha_venta) AS dia, SUM(total) AS monto
                 FROM ventas
                 WHERE fecha_venta >= CURDATE() - INTERVAL 30 DAY
                   AND estado = 'completada' AND metodo_pago != 'obsequio'
                 GROUP BY DATE(fecha_venta)"
            )->fetchAll(PDO::FETCH_KEY_PAIR);
            // Se cuenta hacia atrás desde ayer — el día de hoy aún puede estar incompleto
            for ($i = 1; $i <= 30; $i++) {
                $dia_chk = date('Y-m-d', strtotime("-{$i} day"));
                if ((float)($dias_meta[$dia_chk] ?? 0) >= $meta_diaria) {
                    $racha_meta++;
                } else {
                    break;
                }
            }
        } catch (\Exception $e) {}
    }

    // Gráfico de ventas últimos 7 días
    $grafico_7d = [];
    $total_7d   = 0.0;
    try {
        $dias_es = ['Sun'=>'Dom','Mon'=>'Lun','Tue'=>'Mar','Wed'=>'Mié','Thu'=>'Jue','Fri'=>'Vie','Sat'=>'Sáb'];
        $rows7d  = db()->query(
            "SELECT DATE(fecha_venta) AS dia, SUM(total) AS monto
             FROM ventas
             WHERE fecha_venta >= CURDATE() - INTERVAL 6 DAY AND estado = 'completada'
             GROUP BY DATE(fecha_venta)"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        for ($i = 6; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i days"));
            $t = (float)($rows7d[$d] ?? 0);
            $grafico_7d[] = [
                'label' => $dias_es[date('D', strtotime($d))] ?? date('D', strtotime($d)),
                'total' => $t,
                'hoy'   => ($i === 0),
            ];
            $total_7d += $t;
        }
    } catch (\Exception $e) {}

    // Comparativo: ventas del mes en curso vs. el mismo tramo de días del mes anterior
    $comparativa_mensual = null;
    try {
        $dia_actual = (int)date('j');
        $inicio_mes_ant = date('Y-m-01', strtotime('first day of last month'));
        $total_mes_actual = (float)db()->query(
            "SELECT IFNULL(SUM(total),0) FROM ventas
             WHERE fecha_venta >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
               AND estado = 'completada' AND metodo_pago != 'obsequio'"
        )->fetchColumn();
        $st_cmp = db()->prepare(
            "SELECT IFNULL(SUM(total),0) FROM ventas
             WHERE fecha_venta >= ?
               AND DATEDIFF(fecha_venta, ?) < ?
               AND estado = 'completada' AND metodo_pago != 'obsequio'"
        );
        $st_cmp->execute([$inicio_mes_ant, $inicio_mes_ant, $dia_actual]);
        $total_mes_anterior = (float)$st_cmp->fetchColumn();
        if ($total_mes_anterior > 0 || $total_mes_actual > 0) {
            $cambio_pct = $total_mes_anterior > 0
                ? (int)round((($total_mes_actual - $total_mes_anterior) / $total_mes_anterior) * 100)
                : 100;
            $comparativa_mensual = [
                'actual'    => $total_mes_actual,
                'anterior'  => $total_mes_anterior,
                'pct'       => $cambio_pct,
                'dias'      => $dia_actual,
                'mes_act'   => (int)date('n'),
                'mes_ant'   => (int)date('n', strtotime('first day of last month')),
            ];
        }
    } catch (\Exception $e) {}

    // Top clientes del mes en curso (por monto comprado, excluye obsequios y mostrador)
    $top_clientes = [];
    try {
        $top_clientes = db()->query(
            "SELECT c.id, c.nombre, c.telefono,
                    COUNT(v.id)  AS num_compras,
                    SUM(v.total) AS total_comprado
             FROM ventas v
             JOIN clientes c ON c.id = v.cliente_id
             WHERE v.fecha_venta >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
               AND v.estado = 'completada' AND v.metodo_pago != 'obsequio'
             GROUP BY c.id
             ORDER BY total_comprado DESC
             LIMIT 5"
        )->fetchAll();
    } catch (\Exception $e) {}

    // Nombres de meses en español (date('F') siempre devuelve inglés)
    $meses_es = [1=>'Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio',
                 'Agosto','Septiembre','Octubre','Noviembre','Diciembre'];

    // Productos más vendidos del mes en curso (por unidades, excluye obsequios y mostrador)
    $top_productos = [];
    try {
        $top_productos = db()->query(
            "SELECT p.id, p.nombre, p.nombre2,
                    SUM(vd.cantidad) AS unidades,
                    SUM(vd.subtotal) AS total_vendido
             FROM venta_detalles vd
             JOIN ventas v ON v.id = vd.venta_id
             JOIN productos p ON p.id = vd.producto_id
             WHERE v.fecha_venta >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
               AND v.estado = 'completada' AND v.metodo_pago != 'obsequio'
             GROUP BY p.id, p.nombre, p.nombre2
             ORDER BY unidades DESC
             LIMIT 5"
        )->fetchAll();
    } catch (\Exception $e) {}

    // Clientes para reactivar — compraron antes pero no en los últimos 30 días
    $clientes_reactivar = [];
    try {
        $clientes_reactivar = db()->query(
            "SELECT c.id, c.nombre, c.telefono,
                    MAX(v.fecha_venta)  AS ultima_compra,
                    COUNT(v.id)         AS num_compras_total,
                    SUM(v.total)        AS total_historico
             FROM clientes c
             JOIN ventas v ON v.cliente_id = c.id
                          AND v.estado = 'completada' AND v.metodo_pago != 'obsequio'
             WHERE c.activo = 1 AND c.telefono IS NOT NULL AND c.telefono != ''
             GROUP BY c.id, c.nombre, c.telefono
             HAVING MAX(v.fecha_venta) < (CURDATE() - INTERVAL 30 DAY)
             ORDER BY total_historico DESC
             LIMIT 5"
        )->fetchAll();
    } catch (\Exception $e) {}

    // Aniversario de clientes — hoy se cumple N año(s) desde su primera compra
    $clientes_aniversario = [];
    try {
        $clientes_aniversario = db()->query(
            "SELECT c.id, c.nombre, c.telefono,
                    MIN(v.fecha_venta) AS primera_compra,
                    TIMESTAMPDIFF(YEAR, MIN(v.fecha_venta), CURDATE()) AS anios
             FROM clientes c
             JOIN ventas v ON v.cliente_id = c.id
                          AND v.estado = 'completada' AND v.metodo_pago != 'obsequio'
             WHERE c.activo = 1 AND c.telefono IS NOT NULL AND c.telefono != ''
             GROUP BY c.id, c.nombre, c.telefono
             HAVING DATE_FORMAT(MIN(v.fecha_venta), '%m-%d') = DATE_FORMAT(CURDATE(), '%m-%d')
                AND TIMESTAMPDIFF(YEAR, MIN(v.fecha_venta), CURDATE()) >= 1
             ORDER BY anios DESC
             LIMIT 5"
        )->fetchAll();
    } catch (\Exception $e) {}

    // Horas pico de ventas — franjas horarias de mayor demanda (últimos 30 días)
    $horas_pico = [];
    try {
        $horas_pico = db()->query(
            "SELECT HOUR(fecha_venta) AS hora,
                    COUNT(*)  AS num_ventas,
                    SUM(total) AS monto
             FROM ventas
             WHERE fecha_venta >= CURDATE() - INTERVAL 30 DAY
               AND estado = 'completada' AND metodo_pago != 'obsequio'
             GROUP BY HOUR(fecha_venta)
             ORDER BY monto DESC
             LIMIT 3"
        )->fetchAll();
    } catch (\Exception $e) {}

    // Rendimiento de cajeros del mes (solo admin/superadmin — datos de desempeño del personal)
    $top_cajeros = [];
    if (in_array($_SESSION['usuario_rol'] ?? '', ['admin','superadmin'], true)) {
        try {
            $top_cajeros = db()->query(
                "SELECT u.id, u.nombre,
                        COUNT(v.id)  AS num_ventas,
                        SUM(v.total) AS total_vendido
                 FROM ventas v
                 JOIN usuarios u ON u.id = v.created_by
                 WHERE v.fecha_venta >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                   AND v.estado = 'completada' AND v.metodo_pago != 'obsequio'
                 GROUP BY u.id, u.nombre
                 ORDER BY total_vendido DESC
                 LIMIT 5"
            )->fetchAll();
        } catch (\Exception $e) {}
    }
} else {
    $meta_diaria = 0.0; $meta_pct = 0; $meta_alcanzada = false; $racha_meta = 0;
    $grafico_7d  = [];  $total_7d  = 0.0;
    $comparativa_mensual = null;
    $top_clientes       = [];
    $top_productos      = [];
    $top_cajeros        = [];
    $clientes_reactivar = [];
    $clientes_aniversario = [];
    $horas_pico = [];
    $meses_es = [1=>'Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio',
                 'Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
}
if (permiso_tiene('inventario', 'solo_ver')) {
    $row = db()->query(
        'SELECT COUNT(*) AS n FROM insumos WHERE stock_actual <= stock_seguridad AND activo = 1'
    )->fetch();
    $stats['insumos_bajos'] = (int)$row['n'];
}

if (permiso_tiene('productos', 'solo_ver')) {
    // stock_disponible existe solo después de migración 015
    try {
        $row = db()->query(
            'SELECT COALESCE(SUM(stock_disponible), 0) AS total FROM productos WHERE activo = 1'
        )->fetch();
        $stats['stock_terminado'] = (int)($row['total'] ?? 0);
    } catch (Exception $e) {}

    // produccion_lotes existe solo después de migración 015
    try {
        $row2 = db()->query(
            "SELECT COALESCE(SUM(cantidad), 0) AS total
             FROM produccion_lotes
             WHERE fecha_produccion = CURDATE() AND estado = 'activo'"
        )->fetch();
        $stats['produccion_hoy'] = (int)($row2['total'] ?? 0);
    } catch (Exception $e) {}
}

if (permiso_tiene('costos', 'solo_ver')) {
    // Total costos indirectos activos del mes corriente
    try {
        require_once __DIR__ . '/app/models/CostoIndirectoModel.php';
        $stats['costos_mes'] = round(CostoIndirectoModel::total_mensual_activo());
    } catch (Exception $e) { /* módulo puede no estar disponible */ }
}

// ── Alertas operativas (datos detallados para el panel de acción) ─────────────
// Solo se consultan si el usuario tiene acceso al módulo correspondiente.
$alertas = [];

// A) Insumos agotados o bajo stock de seguridad (máx. 5 para no saturar el panel)
if (permiso_tiene('inventario', 'solo_ver')) {
    try {
        $rows = db()->query(
            "SELECT i.nombre, i.stock_actual, i.stock_seguridad, i.unidad_medida,
                    CASE WHEN i.stock_actual = 0 THEN 'agotado' ELSE 'bajo' END AS nivel,
                    p.nombre AS proveedor_nombre, p.telefono AS proveedor_telefono
             FROM insumos i
             LEFT JOIN proveedores p ON p.id = i.proveedor_id AND p.activo = 1
             WHERE i.activo = 1 AND i.stock_actual <= i.stock_seguridad
             ORDER BY i.stock_actual ASC
             LIMIT 5"
        )->fetchAll();
        if (!empty($rows)) {
            $alertas['insumos_bajos'] = $rows;
        }
    } catch (Exception $e) {}
}

// B) Clientes con fiado pendiente (máx. 5, ordenados de mayor deuda a menor)
if (permiso_tiene('ventas', 'solo_ver')) {
    try {
        $rows = db()->query(
            "SELECT CONCAT(nombre, IF(apellido IS NOT NULL, CONCAT(' ', apellido), '')) AS nombre,
                    saldo_fiado, telefono
             FROM clientes
             WHERE activo = 1 AND saldo_fiado > 0
             ORDER BY saldo_fiado DESC
             LIMIT 5"
        )->fetchAll();
        if (!empty($rows)) {
            $alertas['fiados_pendientes'] = $rows;
        }
    } catch (Exception $e) {}
}

// C) Productos con stock terminado bajo el mínimo configurado
if (permiso_tiene('productos', 'solo_ver')) {
    try {
        $rows = db()->query(
            "SELECT nombre, nombre2, stock_disponible, stock_minimo
             FROM productos
             WHERE activo = 1 AND stock_minimo > 0 AND stock_disponible < stock_minimo
             ORDER BY stock_disponible ASC
             LIMIT 5"
        )->fetchAll();
        if (!empty($rows)) {
            $alertas['productos_bajos'] = $rows;
        }
    } catch (Exception $e) {}
}

// D) Garantías de activos por vencer en los próximos 30 días
if (permiso_tiene('activos', 'solo_ver')) {
    try {
        $rows = db()->query(
            "SELECT nombre, serial, garantia_hasta, categoria_activo
             FROM activos
             WHERE activo = 1
               AND garantia_hasta IS NOT NULL
               AND garantia_hasta >= CURDATE()
               AND garantia_hasta <= (CURDATE() + INTERVAL 30 DAY)
             ORDER BY garantia_hasta ASC
             LIMIT 5"
        )->fetchAll();
        if (!empty($rows)) {
            $alertas['garantias_por_vencer'] = $rows;
        }
    } catch (Exception $e) {}
}

// E) Productos terminados sin movimiento — riesgo de merma (perecederos)
if (permiso_tiene('productos', 'solo_ver')) {
    try {
        $rows = db()->query(
            "SELECT p.nombre, p.nombre2, p.stock_disponible, MAX(v.fecha_venta) AS ultima_venta
             FROM productos p
             LEFT JOIN venta_detalles vd ON vd.producto_id = p.id
             LEFT JOIN ventas v ON v.id = vd.venta_id AND v.estado = 'completada'
             WHERE p.activo = 1 AND p.stock_disponible > 0
               AND p.created_at <= (CURDATE() - INTERVAL 15 DAY)
             GROUP BY p.id, p.nombre, p.nombre2, p.stock_disponible
             HAVING ultima_venta IS NULL OR ultima_venta < (CURDATE() - INTERVAL 15 DAY)
             ORDER BY p.stock_disponible DESC
             LIMIT 5"
        )->fetchAll();
        if (!empty($rows)) {
            $alertas['productos_estancados'] = $rows;
        }
    } catch (Exception $e) {}
}

// ---- Definición de módulos ----
$modulos_config = [
    'ventas'      => ['label' => 'Ventas / POS',   'url' => '/ventas/',      'icon' => 'M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z'],
    'inventario'  => ['label' => 'Inventario',      'url' => '/inventario/', 'icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2'],
    'proveedores' => ['label' => 'Proveedores',     'url' => '/proveedores/', 'icon' => 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-2 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4'],
    'compras'     => ['label' => 'Compras',         'url' => '/inventario/compras.php', 'icon' => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4'],
    'productos'   => ['label' => 'Productos',       'url' => '/productos/',  'icon' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
    'nomina'      => ['label' => 'Nómina',          'url' => '/nomina/',     'icon' => 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z'],
    'activos'     => ['label' => 'Activos',         'url' => '/activos/',    'icon' => 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z'],
    'costos'      => ['label' => 'Costos',          'url' => '/costos/',     'icon' => 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
    'reportes'    => ['label' => 'Reportes',        'url' => '/reportes/',   'icon' => 'M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
];

// Admin y Ayuda se agregan por rol, no por permisos_modulos
$_rol_dash = $_SESSION['usuario_rol'] ?? '';
if (in_array($_rol_dash, ['superadmin','admin'], true)) {
    $modulos_config['admin'] = ['label'=>'Administración','url'=>'/admin/','icon'=>'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z'];
}
$modulos_config['ayuda'] = ['label'=>'Ayuda','url'=>'/ayuda/','icon'=>'M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z'];

$modulos_accesibles = permiso_modulos_accesibles();

// Fase actual implementada — todas las fases están completas
$fase_actual = 7;

// Etiquetas legibles para los niveles de permiso
$nivel_labels = [
    'solo_ver'          => 'Solo ver',
    'solo_propios'      => 'Solo propios',
    'editar_existentes' => 'Editar',
    'admin_total'       => 'Admin',
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — <?= APP_NAME ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --brand:   #e94f37;
            --dark:    #111827;
            --gray-1:  #1f2937;
            --gray-2:  #374151;
            --gray-5:  #6b7280;
            --gray-8:  #d1d5db;
            --gray-9:  #f3f4f6;
            --white:   #ffffff;
            --green:   #10b981;
            --yellow:  #f59e0b;
            --radius:  14px;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--gray-9);
            color: var(--dark);
            min-height: 100vh;
        }

        /* Header provisto por nav.php — sin header propio aquí */

        /* ---- Main ---- */
        .main { padding: 20px 16px 40px; max-width: 960px; margin: 0 auto; }

        /* ---- Sección título ---- */
        .section-title {
            font-size: 13px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .6px;
            color: var(--gray-5);
            margin: 24px 0 12px;
        }

        /* ---- Stats ---- */
        .stats-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }
        @media (min-width: 600px) { .stats-grid { grid-template-columns: repeat(4, 1fr); } }

        .stat-card {
            background: var(--white);
            border-radius: var(--radius);
            padding: 16px;
            box-shadow: 0 1px 4px rgba(0,0,0,.06);
        }
        .stat-label { font-size: 11px; color: var(--gray-5); text-transform: uppercase; letter-spacing: .5px; }
        .stat-value { font-size: 22px; font-weight: 800; margin-top: 4px; color: var(--dark); }
        .stat-value.alert-red { color: var(--brand); }
        .stat-value.alert-yellow { color: var(--yellow); }
        .stat-sub { font-size: 11px; color: var(--gray-5); margin-top: 2px; }

        /* ---- Módulos ---- */
        .modules-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
        }
        /* Último item solo → ocupa todo el ancho para no quedar huérfano */
        .modules-grid > a:last-child:nth-child(odd) {
            grid-column: 1 / -1;
            max-width: calc(50% - 5px); /* mantiene tamaño visual similar */
        }
        @media (min-width: 600px) {
            .modules-grid { grid-template-columns: repeat(3, 1fr); }
            .modules-grid > a:last-child:nth-child(odd) { grid-column: unset; max-width: unset; }
            .modules-grid > a:last-child:nth-child(3n+1) { grid-column: 1 / -1; max-width: calc(33.33% - 7px); }
        }
        @media (min-width: 800px) {
            .modules-grid { grid-template-columns: repeat(4, 1fr); }
            .modules-grid > a:last-child:nth-child(3n+1) { grid-column: unset; max-width: unset; }
            .modules-grid > a:last-child:nth-child(4n+1) { grid-column: unset; max-width: unset; }
        }

        .module-card {
            background: var(--white);
            border-radius: var(--radius);
            padding: 18px 14px 16px;
            box-shadow: 0 1px 4px rgba(0,0,0,.06);
            text-decoration: none;
            color: var(--dark);
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
            transition: box-shadow .18s, transform .12s;
            position: relative;
            overflow: hidden;
        }
        .module-card:hover {
            box-shadow: 0 6px 20px rgba(0,0,0,.1);
            transform: translateY(-2px);
        }
        .module-card.disabled {
            opacity: .55;
            pointer-events: none;
            cursor: default;
        }
        .module-icon {
            width: 40px;
            height: 40px;
            background: #fef2f0;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .module-icon svg {
            width: 22px;
            height: 22px;
            stroke: var(--brand);
            fill: none;
            stroke-width: 1.8;
            stroke-linecap: round;
            stroke-linejoin: round;
        }
        .module-label {
            font-size: 14px;
            font-weight: 700;
            line-height: 1.2;
        }
        .module-badge {
            font-size: 10px;
            padding: 2px 8px;
            border-radius: 20px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .4px;
        }
        .badge-active  { background: #d1fae5; color: #065f46; }
        .badge-soon    { background: #fef3c7; color: #92400e; }
        .badge-level   { background: #ede9fe; color: #5b21b6; }

        /* ---- Saludo ---- */
        .greeting { font-size: 20px; font-weight: 700; }
        .greeting span { color: var(--brand); }
        .greeting-sub { font-size: 13px; color: var(--gray-5); margin-top: 4px; }

        /* ---- Panel de alertas ---- */
        .alertas-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 10px;
        }
        @media (min-width: 600px) { .alertas-grid { grid-template-columns: 1fr 1fr; } }
        @media (min-width: 900px) { .alertas-grid { grid-template-columns: repeat(3, 1fr); } }

        .alerta-card {
            background: var(--white);
            border-radius: var(--radius);
            box-shadow: 0 1px 4px rgba(0,0,0,.06);
            overflow: hidden;
        }
        .alerta-hdr {
            display: flex; align-items: center; justify-content: space-between;
            padding: 10px 14px;
            font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .4px;
        }
        .alerta-hdr.rojo  { background: #fef2f2; color: #991b1b; }
        .alerta-hdr.naranja { background: #fff7ed; color: #9a3412; }
        .alerta-hdr.amarillo { background: #fffbeb; color: #92400e; }
        .alerta-hdr a { font-size: 11px; font-weight: 700; text-decoration: none;
                        background: var(--brand); color: #fff; padding: 2px 8px;
                        border-radius: 10px; }
        .alerta-item {
            display: flex; justify-content: space-between; align-items: center;
            padding: 7px 14px; border-bottom: 1px solid var(--gray-9); font-size: 13px;
        }
        .alerta-item:last-child { border-bottom: none; }
        .alerta-nom { font-weight: 600; }
        .alerta-val { font-size: 12px; color: var(--brand); font-weight: 700; white-space: nowrap; }
        .alerta-sub { font-size: 11px; color: var(--gray-5); margin-top: 1px; }

        /* ---- Rol badge ---- */
        .rol-badge {
            display: inline-block;
            font-size: 11px;
            padding: 2px 10px;
            border-radius: 20px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .4px;
            background: #fef2f0;
            color: var(--brand);
            margin-left: 8px;
            vertical-align: middle;
        }

        /* ---- Meta de ventas ---- */
        .meta-card { background:var(--white); border-radius:var(--radius); padding:14px 18px; box-shadow:0 1px 4px rgba(0,0,0,.06); margin-bottom:16px; }
        .meta-header { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px; margin-bottom:8px; }
        .meta-lbl { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--gray-5); }
        .progress-track { background:var(--gray-9); border-radius:99px; height:10px; overflow:hidden; }
        .progress-fill { height:100%; border-radius:99px; min-width:4px; transition:width .6s ease; }

        /* ---- Gráfico 7 días ---- */
        .chart-bars { display:flex; align-items:flex-end; gap:4px; height:64px; }
        .chart-bars > div { flex:1; border-radius:4px 4px 0 0; transition:height .4s ease; }
        .chart-lbls { display:flex; gap:4px; margin-top:5px; }
        .chart-lbl  { flex:1; text-align:center; font-size:9px; color:var(--gray-5); line-height:1; }
        .chart-hoy  { color:var(--brand); font-weight:700; }
    </style>
</head>
<body>
<?php $nav_activo = ''; include __DIR__ . '/app/views/nav.php'; ?>

    <!-- ---- Contenido principal ---- -->
    <main class="main">

        <!-- Saludo -->
        <div style="margin-bottom: 4px;">
            <p class="greeting">
                Bienvenido, <span><?= htmlspecialchars(explode(' ', $usuario_activo['nombre'])[0]) ?></span>
                <span class="rol-badge"><?= htmlspecialchars($usuario_activo['rol']) ?></span>
            </p>
            <p class="greeting-sub"><?= date('l d \d\e F Y') ?></p>
        </div>

        <!-- Estadísticas del día -->
        <?php if (permiso_tiene('ventas', 'solo_ver') || permiso_tiene('inventario', 'solo_ver') || permiso_tiene('productos', 'solo_ver')): ?>
        <p class="section-title">Resumen del Día</p>
        <div class="stats-grid">

            <?php if (permiso_tiene('ventas', 'solo_ver')): ?>
            <div class="stat-card" style="cursor:pointer" onclick="location.href='<?= APP_BASE ?>/ventas/cierre.php'">
                <p class="stat-label">Ventas hoy</p>
                <p class="stat-value"><?= $stats['ventas_hoy'] ?></p>
                <p class="stat-sub">$<?= number_format($stats['ventas_total'], 0, ',', '.') ?> &middot; <span style="color:var(--brand)">Cierre →</span></p>
            </div>
            <?php
            // Turno de caja (mig.037)
            $turno_dashboard = null;
            $tiene_tc_db = false;
            try {
                $tiene_tc_db = (int)db()->query(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='turnos_caja' AND COLUMN_NAME='id'"
                )->fetchColumn() > 0;
                if ($tiene_tc_db) {
                    $st_tc = db()->prepare(
                        "SELECT estado, fondo_inicial FROM turnos_caja WHERE fecha=? ORDER BY id DESC LIMIT 1"
                    );
                    $st_tc->execute([date('Y-m-d')]);
                    $turno_dashboard = $st_tc->fetch();
                }
            } catch (\Exception $e) {}
            ?>
            <?php if ($tiene_tc_db): ?>
            <div class="stat-card" style="cursor:pointer" onclick="location.href='<?= APP_BASE ?>/ventas/apertura.php'">
                <p class="stat-label">Turno de caja</p>
                <?php if ($turno_dashboard && $turno_dashboard['estado'] === 'abierto'): ?>
                <p class="stat-value" style="font-size:18px;color:var(--green)">● Abierto</p>
                <p class="stat-sub">Fondo: $<?= number_format((float)$turno_dashboard['fondo_inicial'], 0, ',', '.') ?> &middot; <span style="color:var(--brand)">Ver →</span></p>
                <?php elseif ($turno_dashboard): ?>
                <p class="stat-value" style="font-size:18px;color:var(--g5)">Cerrado</p>
                <p class="stat-sub"><span style="color:var(--brand)">Ver historial →</span></p>
                <?php else: ?>
                <p class="stat-value" style="font-size:18px;color:#d97706">Sin apertura</p>
                <p class="stat-sub"><span style="color:var(--brand)">Abrir turno →</span></p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php endif; ?>

            <?php if (permiso_tiene('productos', 'solo_ver') && $stats['produccion_hoy'] > 0): ?>
            <div class="stat-card">
                <p class="stat-label">Producidos hoy</p>
                <p class="stat-value" style="color:#059669"><?= $stats['produccion_hoy'] ?></p>
                <p class="stat-sub">sándwiches terminados</p>
            </div>
            <?php endif; ?>

            <?php if (permiso_tiene('productos', 'solo_ver')): ?>
            <div class="stat-card">
                <p class="stat-label">Stock disponible</p>
                <p class="stat-value <?= $stats['stock_terminado'] === 0 ? 'alert-red' : '' ?>">
                    <?= $stats['stock_terminado'] ?>
                </p>
                <p class="stat-sub">productos terminados</p>
            </div>
            <?php endif; ?>

            <?php if (permiso_tiene('inventario', 'solo_ver')): ?>
            <div class="stat-card">
                <p class="stat-label">Insumos bajos</p>
                <p class="stat-value <?= $stats['insumos_bajos'] > 0 ? 'alert-red' : '' ?>">
                    <?= $stats['insumos_bajos'] ?>
                </p>
                <p class="stat-sub">bajo stock de seguridad</p>
            </div>
            <?php endif; ?>

            <?php if (permiso_tiene('ventas', 'solo_ver')): ?>
            <div class="stat-card">
                <p class="stat-label">Fiado sin cobrar</p>
                <p class="stat-value <?= $stats['fiado_total'] > 0 ? 'alert-yellow' : '' ?>">
                    $<?= number_format($stats['fiado_total'], 0, ',', '.') ?>
                </p>
                <p class="stat-sub">pendiente de cobro</p>
            </div>
            <?php endif; ?>

            <?php if (permiso_tiene('costos', 'solo_ver') && $stats['costos_mes'] > 0): ?>
            <div class="stat-card">
                <p class="stat-label">Costos este mes</p>
                <p class="stat-value" style="color:#6d28d9">
                    $<?= number_format($stats['costos_mes'], 0, ',', '.') ?>
                </p>
                <p class="stat-sub">costos indirectos activos</p>
            </div>
            <?php endif; ?>

        </div>
        <?php endif; ?>

        <!-- ── Meta de ventas diaria ─────────────────────────────────────────── -->
        <?php if (permiso_tiene('ventas', 'solo_ver') && ($meta_diaria > 0 || in_array($_SESSION['usuario_rol'] ?? '', ['admin','superadmin']))): ?>
        <div class="meta-card">
            <div class="meta-header">
                <span class="meta-lbl">Meta del día</span>
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                    <?php if ($meta_diaria > 0): ?>
                    <span style="font-size:13px;font-weight:700;color:var(--dark)">
                        $<?= number_format($stats['ventas_total'], 0, ',', '.') ?>
                        <span style="color:var(--gray-5);font-weight:400">/ $<?= number_format($meta_diaria, 0, ',', '.') ?></span>
                    </span>
                    <?php
                    $meta_color_bg  = $meta_pct >= 80 ? '#d1fae5' : ($meta_pct >= 50 ? '#fef3c7' : '#fee2e2');
                    $meta_color_txt = $meta_pct >= 80 ? '#065f46' : ($meta_pct >= 50 ? '#92400e' : '#991b1b');
                    $meta_bar_color = $meta_pct >= 100 ? '#10b981' : ($meta_pct >= 80 ? '#16a34a' : ($meta_pct >= 50 ? '#d97706' : '#e94f37'));
                    ?>
                    <span style="font-size:12px;font-weight:700;padding:2px 8px;border-radius:99px;background:<?= $meta_color_bg ?>;color:<?= $meta_color_txt ?>">
                        <?= $meta_pct ?>%<?= $meta_alcanzada ? ' ✓' : '' ?>
                    </span>
                    <?php if ($racha_meta > 0): ?>
                    <span style="font-size:12px;font-weight:700;padding:2px 8px;border-radius:99px;background:#fff7ed;color:#c2410c"
                          title="Días consecutivos cumpliendo la meta diaria (sin contar hoy)">
                        🔥 Racha: <?= $racha_meta ?> día<?= $racha_meta != 1 ? 's' : '' ?>
                    </span>
                    <?php endif; ?>
                    <?php else: ?>
                    <span style="font-size:12px;color:var(--gray-5)">Sin meta configurada</span>
                    <?php endif; ?>
                    <?php if (in_array($_SESSION['usuario_rol'] ?? '', ['admin','superadmin'])): ?>
                    <a href="#" onclick="event.preventDefault();var f=document.getElementById('meta-edit');f.style.display=f.style.display==='flex'?'none':'flex';"
                       style="font-size:11px;color:var(--gray-5);text-decoration:none;padding:2px 8px;border:1px solid var(--gray-8);border-radius:6px">✏️ editar</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($meta_diaria > 0): ?>
            <div class="progress-track">
                <div class="progress-fill" style="width:<?= $meta_pct ?>%;background:<?= $meta_bar_color ?>"></div>
            </div>
            <?php if ($meta_alcanzada): ?>
            <p style="font-size:12px;color:#059669;font-weight:700;margin-top:6px">🎉 ¡Meta del día alcanzada!</p>
            <?php endif; ?>
            <?php endif; ?>
            <?php if (in_array($_SESSION['usuario_rol'] ?? '', ['admin','superadmin'])): ?>
            <div id="meta-edit" style="display:none;margin-top:12px;gap:8px;align-items:center;flex-wrap:wrap">
                <form method="POST" action="<?= APP_BASE ?>/admin/api/set_meta_ventas.php"
                      style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                    <label style="font-size:12px;color:var(--gray-5)">Meta diaria ($)</label>
                    <input type="number" name="meta" value="<?= (int)$meta_diaria ?>"
                           min="0" max="99999999" step="1000"
                           style="padding:7px 10px;border:1px solid var(--gray-8);border-radius:8px;font-size:13px;width:140px">
                    <button type="submit"
                            style="padding:7px 14px;background:var(--brand);color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer">
                        Guardar
                    </button>
                </form>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ── Gráfico de ventas últimos 7 días ─────────────────────────────── -->
        <?php if (permiso_tiene('ventas', 'solo_ver') && !empty($grafico_7d)): ?>
        <?php
        $max7d_px = max(1.0, max(array_column($grafico_7d, 'total')));
        ?>
        <div class="meta-card">
            <div class="meta-header">
                <span class="meta-lbl">Ventas — últimos 7 días</span>
                <?php if ($total_7d > 0): ?>
                <span style="font-size:13px;font-weight:700;color:var(--dark)">
                    $<?= number_format($total_7d, 0, ',', '.') ?>
                    <span style="color:var(--gray-5);font-weight:400;font-size:11px"> semana</span>
                </span>
                <?php endif; ?>
            </div>
            <div class="chart-bars">
                <?php foreach ($grafico_7d as $g):
                    $px = $g['total'] > 0 ? max(4, (int)round($g['total'] / $max7d_px * 60)) : 0;
                    $bg = $g['hoy'] ? 'var(--brand)' : '#d1d5db';
                ?>
                <div style="height:<?= $px ?>px;background:<?= $bg ?>"
                     title="$<?= number_format($g['total'], 0, ',', '.') ?>"></div>
                <?php endforeach; ?>
            </div>
            <div class="chart-lbls">
                <?php foreach ($grafico_7d as $g): ?>
                <div class="chart-lbl <?= $g['hoy'] ? 'chart-hoy' : '' ?>"><?= $g['label'] ?></div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- ── Comparativo mensual ──────────────────────────────────────────── -->
        <?php if ($comparativa_mensual !== null):
            $cmp = $comparativa_mensual;
            $cmp_subio   = $cmp['pct'] >= 0;
            $cmp_bg      = $cmp_subio ? '#d1fae5' : '#fee2e2';
            $cmp_txt     = $cmp_subio ? '#065f46' : '#991b1b';
            $cmp_flecha  = $cmp_subio ? '▲' : '▼';
        ?>
        <div class="meta-card">
            <div class="meta-header">
                <span class="meta-lbl">📊 Comparativo del mes</span>
                <span style="font-size:12px;font-weight:700;padding:2px 8px;border-radius:99px;background:<?= $cmp_bg ?>;color:<?= $cmp_txt ?>">
                    <?= $cmp_flecha ?> <?= abs($cmp['pct']) ?>%
                </span>
            </div>
            <p style="font-size:12px;color:var(--gray-5);margin-top:8px;line-height:1.6">
                Llevas <strong style="color:var(--dark)">$<?= number_format($cmp['actual'], 0, ',', '.') ?></strong>
                en los primeros <?= $cmp['dias'] ?> día<?= $cmp['dias'] != 1 ? 's' : '' ?> de <?= $meses_es[$cmp['mes_act']] ?? '' ?>,
                frente a <strong style="color:var(--dark)">$<?= number_format($cmp['anterior'], 0, ',', '.') ?></strong>
                en el mismo periodo de <?= $meses_es[$cmp['mes_ant']] ?? '' ?>
                — <?= $cmp_subio ? 'vas mejor que el mes pasado 🎉' : 'un poco más flojo que el mes pasado, ¡a recuperar terreno!' ?>
            </p>
        </div>
        <?php endif; ?>

        <?php if (!empty($top_clientes)): ?>
        <div class="meta-card">
            <div class="meta-header">
                <span class="meta-lbl">🏆 Top Clientes del Mes</span>
                <span style="font-size:11px;font-weight:400;color:var(--gray-5)"><?= $meses_es[(int)date('n')] ?> <?= date('Y') ?></span>
            </div>
            <?php foreach ($top_clientes as $i => $tc):
                $medalla = ['🥇','🥈','🥉'][$i] ?? ($i + 1) . '.';
                $tel_tc  = preg_replace('/[^0-9]/', '', $tc['telefono'] ?? '');
                $tel_tcw = (strlen($tel_tc) === 10 && str_starts_with($tel_tc, '3')) ? '57'.$tel_tc : $tel_tc;
                $msg_tc  = rawurlencode(
                    "Hola {$tc['nombre']}, ¡queremos darte las gracias por ser uno de nuestros mejores "
                    . "clientes este mes en " . APP_NAME . "! 🎉 Apreciamos mucho tu confianza. ¡Que sigas disfrutando! 🥪"
                );
            ?>
            <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--gray-9)">
                <div>
                    <span style="font-size:15px;margin-right:6px"><?= $medalla ?></span>
                    <strong style="font-size:13px"><?= htmlspecialchars($tc['nombre']) ?></strong>
                    <div style="font-size:11px;color:var(--gray-5);margin-left:21px">
                        <?= (int)$tc['num_compras'] ?> compra<?= $tc['num_compras'] != 1 ? 's' : '' ?>
                    </div>
                </div>
                <div style="text-align:right">
                    <div style="font-weight:800;color:var(--brand);font-size:14px">$<?= number_format($tc['total_comprado'], 0, ',', '.') ?></div>
                    <?php if ($tel_tcw): ?>
                    <a href="https://wa.me/<?= $tel_tcw ?>?text=<?= $msg_tc ?>" target="_blank" rel="noopener noreferrer"
                       style="color:#25d366;font-weight:700;text-decoration:none;font-size:11px">🎉 Agradecer</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($clientes_reactivar)): ?>
        <div class="meta-card">
            <div class="meta-header">
                <span class="meta-lbl">💌 Clientes para Reactivar</span>
                <span style="font-size:11px;font-weight:400;color:var(--gray-5)">+30 días sin comprar</span>
            </div>
            <?php foreach ($clientes_reactivar as $cr):
                $dias_inactivo = (int)floor((time() - strtotime($cr['ultima_compra'])) / 86400);
                $tel_cr  = preg_replace('/[^0-9]/', '', $cr['telefono'] ?? '');
                $tel_crw = (strlen($tel_cr) === 10 && str_starts_with($tel_cr, '3')) ? '57'.$tel_cr : $tel_cr;
                $msg_cr  = rawurlencode(
                    "Hola {$cr['nombre']}, ¡te extrañamos en " . APP_NAME . "! 🥪 Hace tiempo no te vemos por aquí. "
                    . "¿Te provoca pasar pronto por tus sándwiches favoritos? ¡Te esperamos con gusto! 😊"
                );
            ?>
            <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--gray-9)">
                <div>
                    <strong style="font-size:13px"><?= htmlspecialchars($cr['nombre']) ?></strong>
                    <div style="font-size:11px;color:var(--gray-5)">
                        Hace <?= $dias_inactivo ?> días · <?= (int)$cr['num_compras_total'] ?> compra<?= $cr['num_compras_total'] != 1 ? 's' : '' ?> históricas
                    </div>
                </div>
                <div style="text-align:right">
                    <div style="font-weight:800;color:var(--brand);font-size:14px">$<?= number_format($cr['total_historico'], 0, ',', '.') ?></div>
                    <?php if ($tel_crw): ?>
                    <a href="https://wa.me/<?= $tel_crw ?>?text=<?= $msg_cr ?>" target="_blank" rel="noopener noreferrer"
                       style="color:#25d366;font-weight:700;text-decoration:none;font-size:11px">💌 Reconectar</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($clientes_aniversario)): ?>
        <div class="meta-card">
            <div class="meta-header">
                <span class="meta-lbl">🎂 Aniversario de Clientes</span>
                <span style="font-size:11px;font-weight:400;color:var(--gray-5)">primera compra hace años</span>
            </div>
            <?php foreach ($clientes_aniversario as $ca):
                $tel_ca  = preg_replace('/[^0-9]/', '', $ca['telefono'] ?? '');
                $tel_caw = (strlen($tel_ca) === 10 && str_starts_with($tel_ca, '3')) ? '57'.$tel_ca : $tel_ca;
                $anios_ca = (int)$ca['anios'];
                $msg_ca  = rawurlencode(
                    "¡Hola {$ca['nombre']}! 🎉 Hoy se cumple {$anios_ca} año" . ($anios_ca != 1 ? 's' : '')
                    . " desde tu primera visita a " . APP_NAME . ". ¡Gracias por seguir confiando en nosotros y "
                    . "acompañarnos todo este tiempo! Como agradecimiento, hoy tienes una sorpresa especial si nos visitas 😊🥪"
                );
            ?>
            <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--gray-9)">
                <div>
                    <strong style="font-size:13px"><?= htmlspecialchars($ca['nombre']) ?></strong>
                    <div style="font-size:11px;color:var(--gray-5)">
                        Cliente desde el <?= date('d/m/Y', strtotime($ca['primera_compra'])) ?>
                    </div>
                </div>
                <div style="text-align:right">
                    <div style="font-weight:800;color:var(--brand);font-size:14px">🎉 <?= $anios_ca ?> año<?= $anios_ca != 1 ? 's' : '' ?></div>
                    <?php if ($tel_caw): ?>
                    <a href="https://wa.me/<?= $tel_caw ?>?text=<?= $msg_ca ?>" target="_blank" rel="noopener noreferrer"
                       style="color:#25d366;font-weight:700;text-decoration:none;font-size:11px">🎂 Felicitar</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($top_productos)): ?>
        <div class="meta-card">
            <div class="meta-header">
                <span class="meta-lbl">🥪 Productos Más Vendidos</span>
                <span style="font-size:11px;font-weight:400;color:var(--gray-5)"><?= $meses_es[(int)date('n')] ?> <?= date('Y') ?></span>
            </div>
            <?php
            $max_unidades_tp = max(array_column($top_productos, 'unidades'));
            foreach ($top_productos as $i => $tp):
                $medalla_tp = ['🥇','🥈','🥉'][$i] ?? ($i + 1) . '.';
                $pct_barra  = $max_unidades_tp > 0 ? max(6, (int)round($tp['unidades'] / $max_unidades_tp * 100)) : 0;
            ?>
            <div style="padding:8px 0;border-bottom:1px solid var(--gray-9)">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">
                    <div>
                        <span style="font-size:15px;margin-right:6px"><?= $medalla_tp ?></span>
                        <strong style="font-size:13px"><?= htmlspecialchars($tp['nombre']) ?></strong>
                        <?php if (!empty($tp['nombre2'])): ?>
                        <span style="font-size:11px;color:var(--gray-5)"> · <?= htmlspecialchars($tp['nombre2']) ?></span>
                        <?php endif; ?>
                    </div>
                    <div style="text-align:right">
                        <div style="font-weight:800;color:var(--brand);font-size:14px"><?= (int)$tp['unidades'] ?> u</div>
                        <div style="font-size:11px;color:var(--gray-5)">$<?= number_format($tp['total_vendido'], 0, ',', '.') ?></div>
                    </div>
                </div>
                <div style="height:5px;background:var(--gray-9);border-radius:3px;overflow:hidden">
                    <div style="height:100%;width:<?= $pct_barra ?>%;background:var(--brand);border-radius:3px"></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($top_cajeros)): ?>
        <div class="meta-card">
            <div class="meta-header">
                <span class="meta-lbl">👤 Rendimiento de Cajeros</span>
                <span style="font-size:11px;font-weight:400;color:var(--gray-5)"><?= $meses_es[(int)date('n')] ?> <?= date('Y') ?></span>
            </div>
            <?php
            $max_vendido_tcj = max(array_column($top_cajeros, 'total_vendido'));
            foreach ($top_cajeros as $i => $tcj):
                $medalla_tcj   = ['🥇','🥈','🥉'][$i] ?? ($i + 1) . '.';
                $pct_barra_tcj = $max_vendido_tcj > 0 ? max(6, (int)round($tcj['total_vendido'] / $max_vendido_tcj * 100)) : 0;
                $ticket_prom   = $tcj['num_ventas'] > 0 ? $tcj['total_vendido'] / $tcj['num_ventas'] : 0;
            ?>
            <div style="padding:8px 0;border-bottom:1px solid var(--gray-9)">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">
                    <div>
                        <span style="font-size:15px;margin-right:6px"><?= $medalla_tcj ?></span>
                        <strong style="font-size:13px"><?= htmlspecialchars($tcj['nombre']) ?></strong>
                        <div style="font-size:11px;color:var(--gray-5);margin-left:21px">
                            <?= (int)$tcj['num_ventas'] ?> venta<?= $tcj['num_ventas'] != 1 ? 's' : '' ?> · ticket prom. $<?= number_format($ticket_prom, 0, ',', '.') ?>
                        </div>
                    </div>
                    <div style="text-align:right">
                        <div style="font-weight:800;color:var(--brand);font-size:14px">$<?= number_format($tcj['total_vendido'], 0, ',', '.') ?></div>
                    </div>
                </div>
                <div style="height:5px;background:var(--gray-9);border-radius:3px;overflow:hidden">
                    <div style="height:100%;width:<?= $pct_barra_tcj ?>%;background:var(--brand);border-radius:3px"></div>
                </div>
            </div>
            <?php endforeach; ?>
            <p style="font-size:11px;color:var(--gray-5);margin:8px 0 0">🔒 Visible solo para administradores</p>
        </div>
        <?php endif; ?>

        <?php if (!empty($horas_pico)): ?>
        <div class="meta-card">
            <div class="meta-header">
                <span class="meta-lbl">⏰ Horas Pico de Ventas</span>
                <span style="font-size:11px;font-weight:400;color:var(--gray-5)">últimos 30 días</span>
            </div>
            <?php
            $max_hp    = max(array_column($horas_pico, 'monto'));
            $medallas  = ['🥇','🥈','🥉'];
            $n_hp      = count($horas_pico);
            foreach ($horas_pico as $i => $hp):
                $hora_ini = (int)$hp['hora'];
                $hora_fin = ($hora_ini + 1) % 24;
                $pct_hp   = $max_hp > 0 ? max(4, (int)round($hp['monto'] / $max_hp * 100)) : 0;
            ?>
            <div style="padding:7px 0;<?= $i < $n_hp - 1 ? 'border-bottom:1px solid var(--gray-9)' : '' ?>">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">
                    <strong style="font-size:13px"><?= $medallas[$i] ?? '' ?> <?= sprintf('%02d:00 – %02d:00', $hora_ini, $hora_fin) ?></strong>
                    <span style="font-size:11px;color:var(--gray-5)"><?= (int)$hp['num_ventas'] ?> venta<?= $hp['num_ventas'] != 1 ? 's' : '' ?> · $<?= number_format($hp['monto'], 0, ',', '.') ?></span>
                </div>
                <div class="progress-track">
                    <div class="progress-fill" style="width:<?= $pct_hp ?>%;background:var(--brand)"></div>
                </div>
            </div>
            <?php endforeach; ?>
            <p style="font-size:11px;color:var(--gray-5);margin:8px 0 0">💡 Útil para planear turnos de personal y producción según la demanda real del día.</p>
        </div>
        <?php endif; ?>

        <!-- ── Panel de alertas operativas ──────────────────────────────────── -->
        <?php if (!empty($alertas)): ?>
        <p class="section-title">⚡ Alertas — Atención requerida</p>
        <div class="alertas-grid">

            <?php if (!empty($alertas['insumos_bajos'])): ?>
            <div class="alerta-card">
                <div class="alerta-hdr rojo">
                    <span>🧂 Insumos bajos / agotados</span>
                    <a href="<?= APP_BASE ?>/inventario/">Ver todos</a>
                </div>
                <?php foreach ($alertas['insumos_bajos'] as $ins): ?>
                <?php
                $tel_ip  = preg_replace('/[^0-9]/', '', $ins['proveedor_telefono'] ?? '');
                $tel_ipw = (strlen($tel_ip) === 10 && str_starts_with($tel_ip, '3')) ? '57'.$tel_ip : $tel_ip;
                $msg_ip  = rawurlencode(
                    "Hola {$ins['proveedor_nombre']}, te escribimos de " . APP_NAME . " para hacer un pedido de "
                    . "*{$ins['nombre']}* — nuestro stock está " . ($ins['nivel'] === 'agotado' ? 'agotado' : 'bajo')
                    . " ({$ins['stock_actual']} {$ins['unidad_medida']}). ¿Tienes disponibilidad? ¡Gracias! 🙏"
                );
                ?>
                <div class="alerta-item">
                    <div>
                        <div class="alerta-nom"><?= htmlspecialchars($ins['nombre']) ?></div>
                        <div class="alerta-sub">
                            Mín: <?= number_format($ins['stock_seguridad'],2,',','.') ?>
                            <?= htmlspecialchars($ins['unidad_medida']) ?>
                            <?php if ($tel_ipw): ?>
                            &nbsp;·&nbsp;
                            <a href="https://wa.me/<?= $tel_ipw ?>?text=<?= $msg_ip ?>"
                               target="_blank" rel="noopener noreferrer"
                               style="color:#25d366;font-weight:700;text-decoration:none;font-size:11px"
                               title="Pedir a <?= htmlspecialchars($ins['proveedor_nombre']) ?> por WhatsApp">
                                📦 Pedir a <?= htmlspecialchars($ins['proveedor_nombre']) ?> ↗
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="alerta-val">
                        <?= number_format($ins['stock_actual'],2,',','.') ?>
                        <?= htmlspecialchars($ins['unidad_medida']) ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($alertas['fiados_pendientes'])): ?>
            <div class="alerta-card">
                <div class="alerta-hdr amarillo">
                    <span>💳 Fiados pendientes</span>
                    <a href="<?= APP_BASE ?>/ventas/fiado.php">Ver todos</a>
                </div>
                <?php foreach ($alertas['fiados_pendientes'] as $cli): ?>
                <?php
                $tel_da  = preg_replace('/[^0-9]/', '', $cli['telefono'] ?? '');
                $tel_wad = (strlen($tel_da) === 10 && str_starts_with($tel_da, '3')) ? '57'.$tel_da : $tel_da;
                $sf_fmt  = '$' . number_format($cli['saldo_fiado'], 0, ',', '.');
                $msg_wad = rawurlencode(
                    "Hola {$cli['nombre']}, te recordamos que tienes un saldo pendiente de {$sf_fmt} en "
                    . APP_NAME . ". ¿Cuándo podemos acordar el pago? ¡Gracias! 🙏"
                );
                ?>
                <div class="alerta-item">
                    <div>
                        <div class="alerta-nom"><?= htmlspecialchars($cli['nombre']) ?></div>
                        <?php if (!empty($cli['telefono'])): ?>
                        <div class="alerta-sub">
                            📞 <?= htmlspecialchars($cli['telefono']) ?>
                            <?php if ($tel_wad): ?>
                            &nbsp;<a href="https://wa.me/<?= $tel_wad ?>?text=<?= $msg_wad ?>"
                                     target="_blank" rel="noopener noreferrer"
                                     style="color:#25d366;font-weight:700;text-decoration:none;font-size:11px">WA ↗</a>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="alerta-val">$<?= number_format($cli['saldo_fiado'],0,',','.') ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($alertas['productos_bajos'])): ?>
            <div class="alerta-card">
                <div class="alerta-hdr naranja">
                    <span>🥪 Stock de producto bajo</span>
                    <a href="<?= APP_BASE ?>/productos/produccion.php">Producir</a>
                </div>
                <?php foreach ($alertas['productos_bajos'] as $prod): ?>
                <div class="alerta-item">
                    <div>
                        <div class="alerta-nom"><?= htmlspecialchars($prod['nombre']) ?></div>
                        <?php if (!empty($prod['nombre2'])): ?>
                        <div class="alerta-sub"><?= htmlspecialchars($prod['nombre2']) ?></div>
                        <?php endif; ?>
                        <div class="alerta-sub">Mín: <?= (int)$prod['stock_minimo'] ?> u</div>
                    </div>
                    <div class="alerta-val"><?= (int)$prod['stock_disponible'] ?> u</div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($alertas['garantias_por_vencer'])): ?>
            <div class="alerta-card">
                <div class="alerta-hdr amarillo">
                    <span>🛡️ Garantías por vencer</span>
                    <a href="<?= APP_BASE ?>/activos/">Ver activos</a>
                </div>
                <?php foreach ($alertas['garantias_por_vencer'] as $act):
                    $dias_restantes = (int)ceil((strtotime($act['garantia_hasta']) - strtotime(date('Y-m-d'))) / 86400);
                ?>
                <div class="alerta-item">
                    <div>
                        <div class="alerta-nom"><?= htmlspecialchars($act['nombre']) ?></div>
                        <div class="alerta-sub">
                            <?php if (!empty($act['serial'])): ?>
                            Serial: <?= htmlspecialchars($act['serial']) ?> ·
                            <?php endif; ?>
                            Vence: <?= date('d/m/Y', strtotime($act['garantia_hasta'])) ?>
                        </div>
                    </div>
                    <div class="alerta-val"><?= $dias_restantes ?> día<?= $dias_restantes != 1 ? 's' : '' ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($alertas['productos_estancados'])): ?>
            <div class="alerta-card">
                <div class="alerta-hdr naranja">
                    <span>⏳ Productos sin rotación</span>
                    <a href="<?= APP_BASE ?>/productos/">Ver productos</a>
                </div>
                <?php foreach ($alertas['productos_estancados'] as $est):
                    $sub_estancado = $est['ultima_venta']
                        ? 'Sin ventas hace ' . (int)floor((time() - strtotime($est['ultima_venta'])) / 86400) . ' días'
                        : 'Nunca se ha vendido';
                ?>
                <div class="alerta-item">
                    <div>
                        <div class="alerta-nom"><?= htmlspecialchars($est['nombre']) ?></div>
                        <?php if (!empty($est['nombre2'])): ?>
                        <div class="alerta-sub"><?= htmlspecialchars($est['nombre2']) ?></div>
                        <?php endif; ?>
                        <div class="alerta-sub"><?= $sub_estancado ?></div>
                    </div>
                    <div class="alerta-val"><?= (int)$est['stock_disponible'] ?> u</div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

        </div>
        <?php endif; ?>

        <!-- Módulos accesibles -->
        <p class="section-title">Módulos</p>
        <div class="modules-grid">
            <?php foreach ($modulos_config as $clave => $modCfg):
                // Admin y Ayuda siempre tienen acceso (se añadieron condicionalmente arriba)
                $es_especial  = in_array($clave, ['admin','ayuda'], true);
                $tiene_acceso = $es_especial || isset($modulos_accesibles[$clave]);
                $nivel        = $modulos_accesibles[$clave] ?? null;
                if (!$tiene_acceso) continue; // no mostrar módulos sin acceso
            ?>
            <a href="<?= APP_BASE . $modCfg['url'] ?>" class="module-card">
                <div class="module-icon">
                    <svg viewBox="0 0 24 24"><path d="<?= $modCfg['icon'] ?>"/></svg>
                </div>
                <span class="module-label"><?= htmlspecialchars($modCfg['label']) ?></span>
                <?php if (!$es_especial && $nivel): ?>
                <span class="module-badge badge-level"><?= $nivel_labels[$nivel] ?? $nivel ?></span>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        </div>

        <?php if (empty($modulos_accesibles)): ?>
        <div style="text-align:center; padding: 48px 16px; color: var(--gray-5);">
            <p style="font-size:32px; margin-bottom:12px;">🔐</p>
            <p style="font-weight:600;">Sin módulos asignados</p>
            <p style="font-size:13px; margin-top:6px;">Contacta al administrador para configurar tus permisos.</p>
        </div>
        <?php endif; ?>

    </main>

</body>
</html>
