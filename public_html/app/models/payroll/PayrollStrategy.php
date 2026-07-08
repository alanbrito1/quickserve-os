<?php
/**
 * app/models/payroll/PayrollStrategy.php — Estrategia de nómina por país (Fase C multi-país).
 *
 * El cálculo laboral (prestaciones, aportes/parafiscales, recargos, jornada, auxilios) es
 * LOCALIZABLE: cada país tiene su propia ley. Esta interfaz separa ese cálculo del resto de
 * NominaModel (que hace la I/O: leer empleado/horas/params, guardar la liquidación, contabilizar).
 *
 * NominaModel enruta por `empleados.pais_laboral` a la estrategia correspondiente
 * (ver NominaModel::estrategia()). Los parámetros (%, topes, SMLMV) siguen viniendo de
 * `parametros_laborales` (ya keyed por país) — la estrategia solo aplica la FÓRMULA.
 *
 * Agregar un país = nueva clase que implemente esta interfaz + registrarla en el router.
 * Cada estrategia nueva DEBE validarse con un contador/abogado laboral del país: no basta
 * con "corre sin error", debe dar el neto/aportes correctos por ley.
 *
 * El contrato de retorno de calcular() es el mismo mapa que consume
 * NominaModel::liquidar_empleado() al construir el INSERT en nomina_liquidaciones.
 */
interface PayrollStrategy
{
    /** Etiqueta del país tal como aparece en empleados.pais_laboral / parametros_laborales.pais. */
    public function pais(): string;

    /**
     * Calcula todos los componentes de una liquidación (cálculo puro, sin I/O).
     *
     * @param float  $salario_base      Salario base (tiempo completo o referencia)
     * @param string $tipo_contrato     tiempo_completo|medio_tiempo|por_horas|por_servicio|por_dias
     * @param bool   $aplica_aux        ¿Aplica auxilio de transporte?
     * @param float  $horas_trabajadas  Total de horas (contratos por_horas)
     * @param float  $valor_proyecto    Pago fijo (contratos por_servicio)
     * @param array  $params            Parámetros laborales del país (parametros_laborales)
     * @param array  $horas_desglose    Horas por tipo: ['extra_diurna'=>2, 'recargo_nocturno'=>5, ...]
     * @return array Mapa de conceptos + totales (salario_efectivo, aux_transporte, cargas,
     *               provisiones, descuentos, total_cargas, total_provisiones,
     *               costo_total_empleador, neto_pagado, …).
     */
    public function calcular(
        float  $salario_base,
        string $tipo_contrato,
        bool   $aplica_aux,
        float  $horas_trabajadas,
        float  $valor_proyecto,
        array  $params,
        array  $horas_desglose
    ): array;

    /** Horas estándar mensuales según la jornada del país (para valor/hora y proporciones). */
    public function horas_mes_estandar(array $params): float;

    /** Valor de UNA hora según su tipo, con el recargo legal del país aplicado. */
    public function valor_hora_con_recargo(string $tipo_hora, float $valor_hora, array $params): float;

    /** Costo total de un desglose de horas por tipo (suma ponderada por recargos). */
    public function calcular_desglose_horas(array $horas_desglose, float $valor_hora_base, array $params): array;
}
