<?php
/**
 * activos/exportar.php — Exporta el inventario de activos fijos a Excel (.xlsx).
 *
 * Genera un archivo con todos los activos (orden por fecha de adquisición),
 * incluyendo categoría, serial, costos, fechas clave, depreciación y estado —
 * útil para auditoría contable, reclamos de seguro o declaración de renta.
 *
 * Requiere: sesión activa + activos:editar_existentes.
 */

require_once __DIR__ . '/../app/middleware/auth_check.php';
require_once __DIR__ . '/../app/helpers/XlsxWriter.php';
require_once __DIR__ . '/../app/helpers/ListasHelper.php';
require_once __DIR__ . '/../app/models/ActivoModel.php';

permiso_requerir('activos', 'editar_existentes');

$v5 = ActivoModel::columnas_existen(['numero_unidades', 'precio_unitario', 'serial',
                                      'estado_fisico', 'categoria_activo', 'garantia_hasta']);

$_cats_lista = listas_map('categoria_activo');
$CATEGORIAS  = !empty($_cats_lista) ? $_cats_lista : [
    'equipo_cocina'    => 'Equipo de cocina',
    'electrodomestico' => 'Electrodoméstico',
    'herramienta'      => 'Herramienta',
    'utensilio'        => 'Utensilio',
    'mobiliario'       => 'Mobiliario',
    'vehiculo'         => 'Vehículo',
    'otro'             => 'Otro',
];
$ESTADOS_FISICO_LBL = [
    'excelente' => 'Excelente', 'bueno' => 'Bueno', 'regular' => 'Regular',
    'malo'      => 'Malo',      'baja'  => 'Dado de baja',
];
$VIDA_ESTADO_LBL = [
    'en_espera' => 'En espera', 'nuevo' => 'Nuevo', 'medio' => 'Mitad de vida',
    'critico'   => 'Crítico',   'depreciado' => 'Depreciado',
];

$activos = ActivoModel::todos('fecha');

$w = new XlsxWriter();
$w->setSheet('Activos');

$w->addRow(['INVENTARIO DE ACTIVOS FIJOS — ' . APP_NAME . ' — ' . date('d/m/Y H:i')], header: true);
$w->addRow([]);

$encabezados = ['#', 'Activo', 'Descripción'];
if ($v5) { $encabezados[] = 'Categoría'; $encabezados[] = 'Serial'; $encabezados[] = 'Unidades'; $encabezados[] = 'Precio/u ($)'; }
$encabezados[] = 'Costo total ($)';
$encabezados[] = 'Fecha adquisición';
$encabezados[] = 'Inicio de uso';
$encabezados[] = 'Vida útil (meses)';
$encabezados[] = '% Depreciado';
$encabezados[] = 'Estado de vida';
if ($v5) { $encabezados[] = 'Estado físico'; $encabezados[] = 'Garantía hasta'; }
$encabezados[] = 'Proveedor / Lugar';
$encabezados[] = 'Estado';

$w->addRow($encabezados, header: true);

$i              = 0;
$total_costo    = 0.0;
$total_dep_pct  = 0;
$n_activos_act  = 0;
$n_garantia_venc= 0;

foreach ($activos as $a) {
    $i++;
    $costo = (float)$a['costo_inicial'];
    $total_costo   += $costo;
    $total_dep_pct += (int)($a['pct_depreciado'] ?? 0);
    if ((int)$a['activo']) $n_activos_act++;
    $garFecha = $a['garantia_hasta'] ?? null;
    if ($v5 && $garFecha && $garFecha < date('Y-m-d')) $n_garantia_venc++;

    $fila = [
        $i,
        $a['nombre'],
        $a['descripcion'] ?? '',
    ];
    if ($v5) {
        $fila[] = $CATEGORIAS[$a['categoria_activo'] ?? 'otro'] ?? 'Otro';
        $fila[] = $a['serial'] ?? '';
        $fila[] = (int)($a['numero_unidades'] ?? 1);
        $fila[] = (float)($a['precio_unitario'] ?? 0);
    }
    $fila[] = $costo;
    $fila[] = date('d/m/Y', strtotime($a['fecha_adquisicion']));
    $fila[] = !empty($a['fecha_inicio_uso']) ? date('d/m/Y', strtotime($a['fecha_inicio_uso'])) : 'Sin iniciar';
    $fila[] = (int)$a['vida_util_meses'];
    $fila[] = (int)($a['pct_depreciado'] ?? 0) . '%';
    $fila[] = $VIDA_ESTADO_LBL[$a['estado_vida'] ?? 'nuevo'] ?? 'Nuevo';
    if ($v5) {
        $fila[] = $ESTADOS_FISICO_LBL[$a['estado_fisico'] ?? 'bueno'] ?? 'Bueno';
        $fila[] = $garFecha ? date('d/m/Y', strtotime($garFecha)) . ($garFecha < date('Y-m-d') ? ' (vencida)' : '') : '';
    }
    $fila[] = $a['proveedor_nombre'] ?? ($a['lugar_compra'] ?? '');
    $fila[] = (int)$a['activo'] ? 'Activo' : 'Inactivo';

    $w->addRow($fila);
}

$dep_promedio = $i > 0 ? (int)round($total_dep_pct / $i) : 0;

$w->addRow([]);
$totales = ['', 'TOTALES', ''];
if ($v5) { $totales[] = ''; $totales[] = ''; $totales[] = ''; $totales[] = ''; }
$totales[] = $total_costo;
$totales[] = '';
$totales[] = '';
$totales[] = '';
$totales[] = "{$dep_promedio}% prom.";
$totales[] = "{$i} activos · {$n_activos_act} activos";
if ($v5) { $totales[] = ''; $totales[] = "{$n_garantia_venc} garantía(s) vencida(s)"; }
$totales[] = '';
$totales[] = '';

$w->addRow($totales, total: true);

$w->download('ClanDestino_Activos_' . date('Ymd') . '.xlsx');
exit;
