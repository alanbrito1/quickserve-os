<?php
/**
 * app/models/payroll/PayrollStrategyColombia.php — Nómina de Colombia (Fase C multi-país).
 *
 * Extrae, sin cambios de fórmula, el cálculo laboral colombiano que vivía en NominaModel:
 *   · 4 tipos de contrato (tiempo_completo/medio_tiempo/por_horas/por_servicio)
 *   · prestaciones (prima, cesantías, intereses, vacaciones) + parafiscales (caja/ICBF/SENA)
 *   · aportes de salud/pensión (empleador y empleado) + ARL
 *   · auxilio de transporte proporcional (Circ. 0058/2015), tope 2 SMLMV
 *   · jornada legal (Ley 2101/2021, 44h/sem → mensual) y recargos Art. 168-172 CST
 * Los %/topes/SMLMV se leen de `parametros_laborales` (pais='Colombia'); aquí solo se aplica
 * la fórmula. Resultado IDÉNTICO al de NominaModel antes de la extracción (verificado).
 */

require_once __DIR__ . '/PayrollStrategy.php';

class PayrollStrategyColombia implements PayrollStrategy
{
    public function pais(): string
    {
        return 'Colombia';
    }

    public function horas_mes_estandar(array $params): float
    {
        $valor   = (float)($params['horas_jornada_valor']   ?? 44);
        $periodo = (int)($params['horas_jornada_periodo']   ?? 1);

        if ($periodo === 1) {
            // Semanal → anualizar y dividir en 12 meses
            // 52.14 = promedio de semanas exactas por año (365.25 / 7)
            return round($valor * 52.14 / 12, 2);
        } else {
            // Mensual directo
            return round($valor, 2);
        }
    }

    /**
     * Multiplicadores Colombia (Art. 168-172 CST):
     *   ordinaria × 1.00 · recargo_nocturno × 1.35 · extra_diurna × 1.25 · extra_nocturna × 1.75
     *   festiva_ordinaria × 1.75 · extra_festiva_diurna × 2.00 · extra_festiva_nocturna × 2.50
     */
    public function valor_hora_con_recargo(string $tipo_hora, float $valor_hora, array $params): float
    {
        $pct = match ($tipo_hora) {
            'recargo_nocturno'       => $params['pct_recargo_nocturno']          ?? 35,
            'extra_diurna'           => $params['pct_hora_extra_diurna']         ?? 25,
            'extra_nocturna'         => $params['pct_hora_extra_nocturna']       ?? 75,
            'festiva_ordinaria'      => $params['pct_hora_festiva_ordinaria']    ?? 75,
            'extra_festiva_diurna'   => $params['pct_hora_extra_festiva_diurna'] ?? 100,
            'extra_festiva_nocturna' => $params['pct_hora_extra_festiva_nocturna'] ?? 150,
            default                  => 0,  // ordinaria: sin recargo
        };
        return round($valor_hora * (1 + $pct / 100), 4);
    }

    public function calcular_desglose_horas(array $horas_desglose, float $valor_hora_base, array $params): array
    {
        $total_pago    = 0.0;
        $horas_extras  = 0.0;
        $detalle       = [];
        $tipos_extra   = ['extra_diurna','extra_nocturna','extra_festiva_diurna','extra_festiva_nocturna'];

        foreach ($horas_desglose as $tipo => $horas) {
            if ((float)$horas <= 0) continue;
            $horas        = (float)$horas;
            $valor_hora_t = $this->valor_hora_con_recargo($tipo, $valor_hora_base, $params);
            $subtotal     = round($horas * $valor_hora_t, 2);
            $total_pago  += $subtotal;

            if (in_array($tipo, $tipos_extra, true)) {
                $horas_extras += $horas;
            }
            $detalle[$tipo] = [
                'horas'    => $horas,
                'valor_h'  => round($valor_hora_t, 2),
                'subtotal' => $subtotal,
            ];
        }

        return [
            'total_pago'   => round($total_pago, 2),
            'horas_extras' => $horas_extras,
            'detalle'      => $detalle,
        ];
    }

    public function calcular(
        float  $salario_base,
        string $tipo_contrato,
        bool   $aplica_aux,
        float  $horas_trabajadas = 0,
        float  $valor_proyecto   = 0,
        array  $params           = [],
        array  $horas_desglose   = []
    ): array {
        if (empty($params)) {
            $params = NominaModel::params($this->pais());
        }

        $smlmv     = $params['salario_minimo']           ?? 1750905;
        $aux_valor = $params['aux_transporte']            ?? 249095;
        $tope_aux  = $params['tope_aux_transporte_smlmv'] ?? 2;

        // Horas estándar mensuales: calculado desde parámetros de jornada
        $horas_mes = $this->horas_mes_estandar($params);

        // ── Contrato por servicio: sin ninguna prestación ────────────────────
        if ($tipo_contrato === 'por_servicio') {
            return [
                'tipo_contrato'         => 'por_servicio',
                'salario_base'          => $salario_base,
                'salario_efectivo'      => $valor_proyecto,
                'aux_transporte'        => 0,
                'horas_trabajadas'      => 0,
                // Cargas empleador: NINGUNA
                'salud_empleador'       => 0,
                'pension_empleador'     => 0,
                'arl'                   => 0,
                'caja_compensacion'     => 0,
                'icbf'                  => 0,
                'sena'                  => 0,
                // Provisiones: NINGUNA
                'prima'                 => 0,
                'cesantias'             => 0,
                'intereses_cesantias'   => 0,
                'vacaciones'            => 0,
                // Descuentos empleado: NINGUNO
                'salud_empleado'        => 0,
                'pension_empleado'      => 0,
                // Totales
                'total_cargas'          => 0,
                'total_provisiones'     => 0,
                'costo_total_empleador' => $valor_proyecto,
                'neto_pagado'           => $valor_proyecto,
                'descripcion'           => 'Pago por proyecto/servicio sin prestaciones sociales',
            ];
        }

        // ── Calcular salario efectivo según tipo de contrato ─────────────────
        $salario_efectivo = match ($tipo_contrato) {
            'medio_tiempo' => round($salario_base * 0.5, 2),
            'por_horas'    => $horas_trabajadas > 0
                ? round(($salario_base / max(1, $horas_mes)) * $horas_trabajadas, 2)
                : 0,
            'por_dias'     => $salario_base, // se maneja externamente
            default        => $salario_base,
        };

        // Auxilio de transporte — Colombia (proporcionalidad según Min. Trabajo Circ. 0058/2015)
        // Aplica solo si salario_efectivo ≤ 2 SMLMV; el monto es proporcional al tiempo trabajado.
        $aplica_aux_real = $aplica_aux && ($salario_efectivo <= $tope_aux * $smlmv);
        if (!$aplica_aux_real) {
            // Salario > tope o empleado sin derecho a aux → no aplica
            $aux = 0;
        } elseif ($tipo_contrato === 'por_horas' && $horas_mes > 0 && $horas_trabajadas > 0) {
            // Por horas: proporcional a las horas trabajadas vs. jornada mensual legal
            $aux = round($aux_valor * ($horas_trabajadas / $horas_mes), 2);
        } elseif ($tipo_contrato === 'medio_tiempo') {
            // Medio tiempo: 50% del auxilio mensual (trabaja media jornada)
            $aux = round($aux_valor * 0.5, 2);
        } else {
            // Tiempo completo / por_dias: auxilio completo
            $aux = $aux_valor;
        }

        // ── Para por_horas: calcular recargos si hay desglose ────────────────
        $valor_hora_base    = $horas_mes > 0 ? round($salario_base / $horas_mes, 4) : 0;
        $horas_extras_total = 0.0;
        $valor_extras       = 0.0;
        $detalle_extras_json = null;

        if ($tipo_contrato === 'por_horas' && !empty($horas_desglose)) {
            $extra_result    = $this->calcular_desglose_horas($horas_desglose, $valor_hora_base, $params);
            $valor_extras    = $extra_result['total_pago'];
            $horas_extras_total = $extra_result['horas_extras'];
            $detalle_extras_json = json_encode($extra_result['detalle']);
            // REEMPLAZAR (no sumar): calcular_desglose_horas ya incluye el pago de TODAS
            // las horas (ordinarias × 1.00 + recargos × multiplicador).
            // Sumarlo generaría un doble pago de las horas ordinarias.
            $salario_efectivo = round($valor_extras, 2);
        }

        // ── Función helper: calcular % sobre salario_efectivo ─────────────────
        $pct = fn(string $clave) => round($salario_efectivo * ($params[$clave] ?? 0) / 100, 2);

        // Verificar si aplica ICBF/SENA (exentos si salario > 10 SMLMV)
        $icbf_aplica = $salario_efectivo <= 10 * $smlmv;

        // ── Cargas del empleador ──────────────────────────────────────────────
        $salud   = $pct('pct_salud_empleador');
        $pension = $pct('pct_pension_empleador');
        $arl     = $pct('pct_arl');
        $caja    = $pct('pct_caja_compensacion');
        $icbf    = $icbf_aplica ? $pct('pct_icbf') : 0;
        $sena    = $icbf_aplica ? $pct('pct_sena')  : 0;

        // ── Provisiones mensuales ─────────────────────────────────────────────
        $prima   = $pct('pct_prima');
        $ces     = $pct('pct_cesantias');
        $int_ces = $pct('pct_intereses_cesantias');
        $vacac   = $pct('pct_vacaciones');

        // ── Descuentos al empleado ────────────────────────────────────────────
        $salud_emp   = $pct('pct_salud_empleado');
        $pension_emp = $pct('pct_pension_empleado');

        $total_cargas = round($salud + $pension + $arl + $caja + $icbf + $sena, 2);
        $total_prov   = round($prima + $ces + $int_ces + $vacac, 2);
        $costo_total  = round($salario_efectivo + $aux + $total_cargas + $total_prov, 2);
        $neto         = round($salario_efectivo + $aux - $salud_emp - $pension_emp, 2);

        return [
            'tipo_contrato'         => $tipo_contrato,
            'salario_base'          => $salario_base,
            'salario_efectivo'      => $salario_efectivo,
            'valor_hora_base'       => $valor_hora_base,
            'horas_mes_estandar'    => $horas_mes,
            'aux_transporte'        => $aux,
            'horas_trabajadas'      => $horas_trabajadas,
            'horas_extras'          => $horas_extras_total,
            'valor_horas_extras'    => $valor_extras,
            'detalle_recargos'      => $detalle_extras_json,
            // Cargas empleador
            'salud_empleador'       => $salud,
            'pension_empleador'     => $pension,
            'arl'                   => $arl,
            'caja_compensacion'     => $caja,
            'icbf'                  => $icbf,
            'sena'                  => $sena,
            // Provisiones
            'prima'                 => $prima,
            'cesantias'             => $ces,
            'intereses_cesantias'   => $int_ces,
            'vacaciones'            => $vacac,
            // Descuentos empleado
            'salud_empleado'        => $salud_emp,
            'pension_empleado'      => $pension_emp,
            // Totales
            'total_cargas'          => $total_cargas,
            'total_provisiones'     => $total_prov,
            'costo_total_empleador' => $costo_total,
            'neto_pagado'           => $neto,
            'descripcion'           => '',
        ];
    }
}
