<?php
/**
 * app/models/NominaModel.php
 * Nómina con soporte para 4 tipos de contrato según legislación colombiana.
 *
 * TIPOS DE CONTRATO:
 *   tiempo_completo  → Salario base + todas las prestaciones + parafiscales
 *   medio_tiempo     → Salario × 0.5 + todas las prestaciones proporcionales
 *   por_horas        → Pago = valor_hora × horas_trabajadas + prestaciones proporcionales
 *   por_servicio     → Pago fijo por proyecto, SIN ninguna prestación ni parafiscal
 *
 * PARÁMETROS: Se leen de la tabla parametros_laborales (no hardcodeados).
 * Esto permite ajustar a cualquier país o actualización de ley.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/AuditoriaHelper.php';
require_once __DIR__ . '/payroll/PayrollStrategy.php';
require_once __DIR__ . '/payroll/PayrollStrategyColombia.php';

class NominaModel
{
    // ── Cache de parámetros (una sola query por request) ─────────────────────
    private static array $paramsCache = [];

    // ── Estrategias de nómina por país (Fase C multi-país) ───────────────────
    private static array $estrategiasCache = [];

    /**
     * Estrategia de nómina para un país (enruta por `empleados.pais_laboral`).
     * El cálculo laboral (prestaciones/aportes/recargos) es localizable: cada país tiene
     * su propia clase PayrollStrategy. Hoy solo Colombia; otros países caen a Colombia
     * hasta tener su estrategia validada (Fase C+). Agregar un país = una rama aquí.
     */
    public static function estrategia(string $pais = 'Colombia'): PayrollStrategy
    {
        $key = $pais !== '' ? $pais : 'Colombia';
        if (!isset(self::$estrategiasCache[$key])) {
            self::$estrategiasCache[$key] = match ($key) {
                // 'México', 'MX' => new PayrollStrategyMexico(),  // Fase C — pendiente asesoría laboral MX
                default => new PayrollStrategyColombia(),
            };
        }
        return self::$estrategiasCache[$key];
    }

    /** Invalida el caché de parámetros — llamar después de actualizar parametros_laborales. */
    public static function invalidar_cache(): void
    {
        self::$paramsCache = [];
    }

    /**
     * Lee parámetros laborales del país dado.
     * Fallback: si no existe la tabla parametros_laborales, usa configuracion_negocio.
     *
     * @return array<string, float>  Mapa clave → valor numérico
     */
    public static function params(string $pais = 'Colombia'): array
    {
        if (isset(self::$paramsCache[$pais])) {
            return self::$paramsCache[$pais];
        }

        // Intentar leer de la nueva tabla parametros_laborales
        try {
            $stmt = db()->prepare(
                'SELECT clave, valor FROM parametros_laborales
                 WHERE pais = :pais AND activo = 1'
            );
            $stmt->execute([':pais' => $pais]);
            $rows = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);

            if (!empty($rows)) {
                self::$paramsCache[$pais] = array_map('floatval', $rows);
                return self::$paramsCache[$pais];
            }
        } catch (\Exception $e) {
            // Tabla aún no existe, usar fallback
        }

        // Fallback: leer de configuracion_negocio (compatibilidad hacia atrás)
        $rows = db()->query(
            "SELECT clave, valor FROM configuracion_negocio WHERE categoria = 'nomina'"
        )->fetchAll(\PDO::FETCH_KEY_PAIR);

        if (empty($rows)) {
            throw new \RuntimeException('Parámetros de nómina no encontrados. Ejecuta la migración 007.');
        }

        self::$paramsCache[$pais] = array_map('floatval', $rows);
        return self::$paramsCache[$pais];
    }

    // ── HELPERS DE JORNADA Y RECARGOS ────────────────────────────────────────

    /**
     * Calcula las horas estándar mensuales desde los parámetros de jornada.
     *
     * Fórmula:
     *   - Si horas_jornada_periodo = 1 (semana): valor × 52.14 / 12
     *   - Si horas_jornada_periodo = 12 (mes): valor directo
     *
     * Ejemplo Colombia 2026: 44h/semana × 52.14 / 12 = 191.18 h/mes
     */
    // ── Delegadores al cálculo por país (Fase C) ──────────────────────────────
    // Estos 4 métodos conservan su firma pública (los usan las vistas de nómina y
    // liquidar_empleado) y delegan en la estrategia colombiana — comportamiento idéntico
    // al anterior. liquidar_empleado usa la estrategia del país del empleado.

    public static function horas_mes_estandar(array $params): float
    {
        return self::estrategia('Colombia')->horas_mes_estandar($params);
    }

    /**
     * Calcula el valor de pago de UNA hora según su tipo, aplicando el recargo legal.
     *
     * Multiplicadores Colombia (Art. 168-172 CST):
     *   ordinaria              → × 1.00
     *   recargo_nocturno       → × 1.35  (trabajo nocturno ordinario, no extra)
     *   extra_diurna           → × 1.25
     *   extra_nocturna         → × 1.75
     *   festiva_ordinaria      → × 1.75  (dominical/festivo jornada normal)
     *   extra_festiva_diurna   → × 2.00
     *   extra_festiva_nocturna → × 2.50
     *
     * @param string $tipo_hora    Tipo de hora trabajada
     * @param float  $valor_hora   Valor base de la hora ordinaria
     * @param array  $params       Parámetros laborales
     * @return float               Valor a pagar por esa hora (con recargo incluido)
     */
    public static function valor_hora_con_recargo(
        string $tipo_hora,
        float  $valor_hora,
        array  $params
    ): float {
        return self::estrategia('Colombia')->valor_hora_con_recargo($tipo_hora, $valor_hora, $params);
    }

    /**
     * Calcula el costo total de un desglose de horas por tipo.
     *
     * @param array $horas_desglose  [tipo_hora => cantidad_horas, ...]
     * @param float $valor_hora_base Valor de la hora ordinaria
     * @param array $params          Parámetros laborales
     * @return array {total, detalle, horas_extras_total}
     */
    public static function calcular_desglose_horas(
        array $horas_desglose,
        float $valor_hora_base,
        array $params
    ): array {
        return self::estrategia('Colombia')->calcular_desglose_horas($horas_desglose, $valor_hora_base, $params);
    }

    // ── CÁLCULO PURO (delegado a la estrategia del país) ──────────────────────

    /**
     * Calcula todos los componentes de nómina según el tipo de contrato.
     * Delega en la estrategia colombiana (compatibilidad; los llamadores externos
     * históricos siempre obtuvieron el cálculo de Colombia). liquidar_empleado usa
     * la estrategia del país del empleado (`empleados.pais_laboral`).
     *
     * @param float  $salario_base        Salario base (tiempo completo o referencia)
     * @param string $tipo_contrato
     * @param bool   $aplica_aux
     * @param float  $horas_trabajadas    Total horas trabajadas (por_horas)
     * @param float  $valor_proyecto      Pago fijo (por_servicio)
     * @param array  $params
     * @param array  $horas_desglose      Desglose por tipo: ['extra_diurna'=>2, ...]
     */
    public static function calcular(
        float  $salario_base,
        string $tipo_contrato,
        bool   $aplica_aux,
        float  $horas_trabajadas = 0,
        float  $valor_proyecto   = 0,
        array  $params           = [],
        array  $horas_desglose   = []
    ): array {
        return self::estrategia('Colombia')->calcular(
            $salario_base, $tipo_contrato, $aplica_aux,
            $horas_trabajadas, $valor_proyecto, $params, $horas_desglose
        );
    }

    // ── GENERACIÓN Y GUARDADO ─────────────────────────────────────────────────

    /**
     * Calcula y guarda la liquidación de un empleado para un período.
     * Para contratos por_horas, requiere que existan registros en registro_horas.
     *
     * @param int    $emp_id
     * @param int    $mes    1–12
     * @param int    $anio
     * @param string $descripcion_pago Para por_servicio: descripción del proyecto
     * @throws \RuntimeException
     * @return int ID de la liquidación creada
     */
    public static function liquidar_empleado(
        int    $emp_id,
        int    $mes,
        int    $anio,
        string $descripcion_pago = ''
    ): int {
        // Obtener empleado
        $row = db()->prepare('SELECT * FROM empleados WHERE id = ? AND activo = 1');
        $row->execute([$emp_id]);
        $e = $row->fetch();
        if (!$e) throw new \RuntimeException("Empleado #$emp_id no encontrado o inactivo.");

        // Detectar duplicado
        $dup = db()->prepare(
            'SELECT id FROM nomina_liquidaciones
             WHERE empleado_id = ? AND periodo_mes = ? AND periodo_anio = ?'
        );
        $dup->execute([$emp_id, $mes, $anio]);
        if ($dup->fetch()) {
            throw new \RuntimeException("Ya existe liquidación de {$e['nombre_completo']} para $mes/$anio.");
        }

        $pais   = $e['pais_laboral'] ?? 'Colombia';
        $params = self::params($pais);
        $tipo   = $e['tipo_contrato'] ?? 'tiempo_completo';
        $uid    = (int)($_SESSION['usuario_id'] ?? 0);

        // ── Calcular horas para contratos por_horas ──────────────────────────
        $horas_trabajadas = 0;
        if ($tipo === 'por_horas') {
            $horas_trabajadas = self::total_horas_periodo($emp_id, $mes, $anio);
            if ($horas_trabajadas <= 0) {
                throw new \RuntimeException(
                    "No hay horas registradas para {$e['nombre_completo']} en $mes/$anio. ".
                    "Registra las horas trabajadas antes de generar la nómina."
                );
            }
        }

        // ── Valor hora para por_horas ─────────────────────────────────────────
        $valor_hora = (float)($e['valor_hora'] ?? 0);
        // ── Horas/mes: primero usar las del empleado, si no hay usar el parámetro global ──
        // El empleado puede tener sus propias horas contratadas (horas_semana + periodo_horas_emp)
        $horas_emp     = (float)($e['horas_semana'] ?? 0);
        $periodo_emp   = $e['periodo_horas_emp'] ?? null;

        if ($horas_emp > 0 && $periodo_emp !== null) {
            // El empleado tiene jornada propia configurada
            $horas_mes_std = $periodo_emp === 'semana'
                ? round($horas_emp * 52.14 / 12, 2)   // semanal → mensual
                : $horas_emp;                           // ya es mensual
        } else {
            // Sin configuración propia → usar parámetro global (jornada del país del empleado)
            $horas_mes_std = self::estrategia($pais)->horas_mes_estandar($params);
        }

        if ($tipo === 'por_horas' && $valor_hora <= 0) {
            // valor_hora = salario_base ÷ horas_mes del empleado (o del global)
            $valor_hora = round((float)$e['salario_base'] / max(1, $horas_mes_std), 2);
        }

        $salario_base   = (float)$e['salario_base'];
        $valor_proyecto = (float)($e['valor_proyecto'] ?? 0);

        // ── Construir desglose de horas por tipo (para calcular recargos Art. 168-172 CST) ──
        // Agrupa las horas del mes por tipo (ordinaria, recargo_nocturno, extra_diurna, etc.)
        // Este desglose se pasa a calcular() que multiplica cada tipo por su multiplicador legal.
        // Ejemplo: ['ordinaria' => 150, 'recargo_nocturno' => 10, 'extra_diurna' => 5]
        // Si no hay desglose disponible, calcular() usa la fórmula simple (salario_base/horas_mes × horas)
        $horas_desglose = [];
        if ($tipo === 'por_horas' && $horas_trabajadas > 0) {
            $horas_reg = self::horas_periodo($emp_id, $mes, $anio);
            foreach ($horas_reg as $h) {
                $t = $h['tipo_hora'] ?? 'ordinaria';
                $horas_desglose[$t] = ($horas_desglose[$t] ?? 0) + (float)$h['horas'];
            }
        }

        // Cálculo por la estrategia del país del empleado (Fase C). Colombia = idéntico al anterior.
        $c = self::estrategia($pais)->calcular(
            $salario_base,
            $tipo,
            (bool)$e['aplica_aux_transporte'],
            $horas_trabajadas,
            $valor_proyecto,
            $params,
            $horas_desglose  // ← desglose para calcular recargos correctamente
        );

        // ── Detectar columnas disponibles según migraciones aplicadas ─────────
        // Razón: en hosting compartido puede que no todas las migraciones se hayan ejecutado.
        // El INSERT se adapta dinámicamente para no fallar si faltan columnas de migraciones
        // tardías (007 → tipo_contrato/horas_trabajadas; 008 → horas_extras/detalle_recargos;
        //          033 → valor_hora_snap/valor_proyecto_snap).
        // NOTA: este SHOW COLUMNS se ejecuta una vez por request (ver columnas_liquidacion_existen())
        $tiene007 = self::columnas_liquidacion_existen(); // tipo_contrato, horas_trabajadas
        $tiene008 = false;
        $tiene033 = false;
        try {
            $col = db()->query("SHOW COLUMNS FROM nomina_liquidaciones LIKE 'horas_extras'")->fetch();
            $tiene008 = (bool)$col;
        } catch (\Exception $ex) { $tiene008 = false; }
        try {
            $col033 = db()->query("SHOW COLUMNS FROM nomina_liquidaciones LIKE 'valor_hora_snap'")->fetch();
            $tiene033 = (bool)$col033;
        } catch (\Exception $ex) { $tiene033 = false; }

        // Construir query según columnas disponibles
        if ($tiene008) {
            // Migración 008 aplicada — guardar desglose completo de horas
            $horas_ord  = $horas_trabajadas - ($c['horas_extras'] ?? 0);

            if ($tiene033) {
                // Migración 033: también guardar snapshots de valor_hora y valor_proyecto
                $stmt = db()->prepare(
                    'INSERT INTO nomina_liquidaciones
                        (empleado_id, periodo_mes, periodo_anio,
                         horas_trabajadas, tipo_contrato, descripcion_pago,
                         horas_ordinarias, horas_extras, valor_horas_extras, detalle_recargos,
                         salario_base, aux_transporte,
                         salud_empleador, pension_empleador, arl, caja_compensacion, icbf, sena,
                         salud_empleado, pension_empleado, neto_pagado,
                         prima, cesantias, intereses_cesantias, vacaciones,
                         total_cargas, total_provisiones, costo_total_empleador,
                         valor_hora_snap, valor_proyecto_snap,
                         created_by)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
                );
                $stmt->execute([
                    $emp_id, $mes, $anio,
                    $horas_trabajadas, $tipo, $descripcion_pago ?: null,
                    max(0, $horas_ord), $c['horas_extras'] ?? 0,
                    $c['valor_horas_extras'] ?? 0, $c['detalle_recargos'],
                    $salario_base,           $c['aux_transporte'],
                    $c['salud_empleador'],   $c['pension_empleador'], $c['arl'],
                    $c['caja_compensacion'], $c['icbf'],              $c['sena'],
                    $c['salud_empleado'],    $c['pension_empleado'],  $c['neto_pagado'],
                    $c['prima'],             $c['cesantias'],
                    $c['intereses_cesantias'], $c['vacaciones'],
                    $c['total_cargas'],      $c['total_provisiones'],
                    $c['costo_total_empleador'],
                    $valor_hora > 0    ? $valor_hora    : null,  // snapshot tarifa/hora
                    $valor_proyecto > 0 ? $valor_proyecto : null, // snapshot valor proyecto
                    $uid,
                ]);
            } else {
                $stmt = db()->prepare(
                    'INSERT INTO nomina_liquidaciones
                        (empleado_id, periodo_mes, periodo_anio,
                         horas_trabajadas, tipo_contrato, descripcion_pago,
                         horas_ordinarias, horas_extras, valor_horas_extras, detalle_recargos,
                         salario_base, aux_transporte,
                         salud_empleador, pension_empleador, arl, caja_compensacion, icbf, sena,
                         salud_empleado, pension_empleado, neto_pagado,
                         prima, cesantias, intereses_cesantias, vacaciones,
                         total_cargas, total_provisiones, costo_total_empleador,
                         created_by)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
                );
                $stmt->execute([
                    $emp_id, $mes, $anio,
                    $horas_trabajadas, $tipo, $descripcion_pago ?: null,
                    max(0, $horas_ord), $c['horas_extras'] ?? 0,
                    $c['valor_horas_extras'] ?? 0, $c['detalle_recargos'],
                    $salario_base,           $c['aux_transporte'],
                    $c['salud_empleador'],   $c['pension_empleador'], $c['arl'],
                    $c['caja_compensacion'], $c['icbf'],              $c['sena'],
                    $c['salud_empleado'],    $c['pension_empleado'],  $c['neto_pagado'],
                    $c['prima'],             $c['cesantias'],
                    $c['intereses_cesantias'], $c['vacaciones'],
                    $c['total_cargas'],      $c['total_provisiones'],
                    $c['costo_total_empleador'], $uid,
                ]);
            }
        } elseif ($tiene007) {
            // Solo migración 007 — sin columnas de desglose de horas
            $stmt = db()->prepare(
                'INSERT INTO nomina_liquidaciones
                    (empleado_id, periodo_mes, periodo_anio,
                     horas_trabajadas, tipo_contrato, descripcion_pago,
                     salario_base, aux_transporte,
                     salud_empleador, pension_empleador, arl, caja_compensacion, icbf, sena,
                     salud_empleado, pension_empleado, neto_pagado,
                     prima, cesantias, intereses_cesantias, vacaciones,
                     total_cargas, total_provisiones, costo_total_empleador,
                     created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([
                $emp_id, $mes, $anio,
                $horas_trabajadas, $tipo, $descripcion_pago ?: null,
                $salario_base,           $c['aux_transporte'],
                $c['salud_empleador'],   $c['pension_empleador'], $c['arl'],
                $c['caja_compensacion'], $c['icbf'],              $c['sena'],
                $c['salud_empleado'],    $c['pension_empleado'],  $c['neto_pagado'],
                $c['prima'],             $c['cesantias'],
                $c['intereses_cesantias'], $c['vacaciones'],
                $c['total_cargas'],      $c['total_provisiones'],
                $c['costo_total_empleador'], $uid,
            ]);
        } else {
            // Sin migraciones — fallback básico (compatibilidad máxima)
            $stmt = db()->prepare(
                'INSERT INTO nomina_liquidaciones
                    (empleado_id, periodo_mes, periodo_anio,
                     salario_base, aux_transporte,
                     salud_empleador, pension_empleador, arl, caja_compensacion, icbf, sena,
                     salud_empleado, pension_empleado, neto_pagado,
                     prima, cesantias, intereses_cesantias, vacaciones,
                     total_cargas, total_provisiones, costo_total_empleador,
                     created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([
                $emp_id, $mes, $anio,
                $salario_base,           $c['aux_transporte'],
                $c['salud_empleador'],   $c['pension_empleador'], $c['arl'],
                $c['caja_compensacion'], $c['icbf'],              $c['sena'],
                $c['salud_empleado'],    $c['pension_empleado'],  $c['neto_pagado'],
                $c['prima'],             $c['cesantias'],
                $c['intereses_cesantias'], $c['vacaciones'],
                $c['total_cargas'],      $c['total_provisiones'],
                $c['costo_total_empleador'], $uid,
            ]);
        }

        $id = (int)db()->lastInsertId();
        log_registrar('nomina_liquidaciones', $id, 'periodo', null,
            "$mes/$anio ($tipo)", 'INSERT');

        return $id;
    }

    /**
     * Genera nómina para TODOS los empleados activos del período.
     * Para por_servicio: solo si tienen valor_proyecto definido.
     * Para por_horas: solo si tienen horas registradas.
     */
    public static function generar_periodo(int $mes, int $anio): array
    {
        $empleados = self::empleados_activos();
        $generados = [];
        $omitidos  = [];
        $errores   = [];

        foreach ($empleados as $e) {
            $dup = db()->prepare(
                'SELECT id FROM nomina_liquidaciones
                 WHERE empleado_id = ? AND periodo_mes = ? AND periodo_anio = ?'
            );
            $dup->execute([$e['id'], $mes, $anio]);
            if ($dup->fetch()) {
                $omitidos[] = $e['nombre_completo'] . ' (ya liquidado)';
                continue;
            }
            try {
                $lid = self::liquidar_empleado((int)$e['id'], $mes, $anio);
                $generados[] = $e['nombre_completo'] . ' (' . $e['tipo_contrato'] . ')';
                // Contabilidad (Fase 4b): causación de la nómina (gasto vs por pagar),
                // tras la liquidación (ya commiteada) y aislada — no rompe la nómina.
                try {
                    require_once __DIR__ . '/ContabilidadModel.php';
                    ContabilidadModel::postear_nomina((int)$lid);
                } catch (\Throwable $ex2) { error_log('[QuickServe OS contab nomina] ' . $ex2->getMessage()); }
            } catch (\Exception $ex) {
                $errores[] = $e['nombre_completo'] . ': ' . $ex->getMessage();
            }
        }

        return compact('generados', 'omitidos', 'errores');
    }

    // ── HORAS ─────────────────────────────────────────────────────────────────

    /**
     * Registra o actualiza las horas trabajadas de un empleado en una fecha.
     * Incluye tipo de hora (para recargos) y si es día festivo.
     *
     * @param string $tipo_hora  'ordinaria'|'recargo_nocturno'|'extra_diurna'|...
     * @param int    $es_festivo  1 = día festivo/dominical
     */
    public static function registrar_horas(
        int    $empleado_id,
        string $fecha,
        float  $horas,
        string $descripcion = '',
        string $tipo_hora   = 'ordinaria',
        int    $es_festivo  = 0
    ): void {
        if ($horas < 0 || $horas > 24) {
            throw new \RuntimeException('Horas inválidas (deben estar entre 0 y 24).');
        }

        $tipos_validos = [
            'ordinaria','recargo_nocturno','extra_diurna','extra_nocturna',
            'festiva_ordinaria','extra_festiva_diurna','extra_festiva_nocturna',
        ];
        if (!in_array($tipo_hora, $tipos_validos, true)) {
            $tipo_hora = 'ordinaria';
        }

        $uid = (int)($_SESSION['usuario_id'] ?? 0);

        // Detectar si la tabla tiene las columnas nuevas (migración 008)
        $tieneNuevosCampos = true;
        try {
            $col = db()->query("SHOW COLUMNS FROM registro_horas LIKE 'tipo_hora'")->fetch();
            $tieneNuevosCampos = (bool)$col;
        } catch (\Exception $e) {
            $tieneNuevosCampos = false;
        }

        if ($tieneNuevosCampos) {
            db()->prepare(
                'INSERT INTO registro_horas
                    (empleado_id, fecha, horas, tipo_hora, es_festivo, descripcion, created_by)
                 VALUES (:eid, :fecha, :horas, :tipo, :festivo, :desc, :uid)
                 ON DUPLICATE KEY UPDATE
                    horas       = VALUES(horas),
                    tipo_hora   = VALUES(tipo_hora),
                    es_festivo  = VALUES(es_festivo),
                    descripcion = VALUES(descripcion),
                    updated_by  = :uid2'
            )->execute([
                ':eid'    => $empleado_id,
                ':fecha'  => $fecha,
                ':horas'  => $horas,
                ':tipo'   => $tipo_hora,
                ':festivo'=> $es_festivo,
                ':desc'   => $descripcion ?: null,
                ':uid'    => $uid,
                ':uid2'   => $uid,
            ]);
        } else {
            // Fallback sin columnas nuevas
            db()->prepare(
                'INSERT INTO registro_horas (empleado_id, fecha, horas, descripcion, created_by)
                 VALUES (:eid, :fecha, :horas, :desc, :uid)
                 ON DUPLICATE KEY UPDATE
                    horas = VALUES(horas), descripcion = VALUES(descripcion), updated_by = :uid2'
            )->execute([
                ':eid'   => $empleado_id,
                ':fecha' => $fecha,
                ':horas' => $horas,
                ':desc'  => $descripcion ?: null,
                ':uid'   => $uid,
                ':uid2'  => $uid,
            ]);
        }
    }

    /**
     * Total de horas trabajadas por un empleado en un período mes/año.
     */
    public static function total_horas_periodo(int $empleado_id, int $mes, int $anio): float
    {
        $stmt = db()->prepare(
            'SELECT IFNULL(SUM(horas), 0) AS total
             FROM registro_horas
             WHERE empleado_id = :eid
               AND MONTH(fecha) = :mes
               AND YEAR(fecha)  = :anio'
        );
        $stmt->execute([':eid' => $empleado_id, ':mes' => $mes, ':anio' => $anio]);
        return round((float)$stmt->fetchColumn(), 2);
    }

    /**
     * Horas registradas por día en un período (para el panel de horas).
     */
    public static function horas_periodo(int $empleado_id, int $mes, int $anio): array
    {
        $stmt = db()->prepare(
            'SELECT fecha, horas,
                    IFNULL(tipo_hora, \'ordinaria\') AS tipo_hora,
                    IFNULL(es_festivo, 0)            AS es_festivo,
                    descripcion, aprobado
             FROM registro_horas
             WHERE empleado_id = :eid
               AND MONTH(fecha) = :mes
               AND YEAR(fecha)  = :anio
             ORDER BY fecha'
        );
        $stmt->execute([':eid' => $empleado_id, ':mes' => $mes, ':anio' => $anio]);
        return $stmt->fetchAll();
    }

    /**
     * Empleados con contrato por_horas con resumen de horas y pago estimado del período.
     *
     * @param float $horas_mes  Jornada legal mensual (191.18 para Colombia 2026).
     *                          Se usa como divisor si el empleado no tiene valor_hora manual.
     *
     * Retorna:
     *   valor_hora       → tarifa real (manual) o calculada (salario_base ÷ horas_mes_legal)
     *   horas_total      → horas brutas registradas en el período
     *   horas_ponderadas → horas × multiplicador por tipo (ordinaria=1.00, nocturno=1.35, etc.)
     *   pago_estimado    → horas_ponderadas × valor_hora (estimación correcta con recargos)
     *   dias_trabajados  → días con al menos un registro
     */
    public static function empleados_por_horas_periodo(int $mes, int $anio, float $horas_mes = 191.18): array
    {
        $stmt = db()->prepare(
            "SELECT e.id, e.nombre_completo, e.cargo,
                    -- Valor/hora: manual si está configurado, si no: salario_base ÷ jornada legal
                    IFNULL(e.valor_hora, e.salario_base / :horas_mes) AS valor_hora,

                    -- Horas brutas registradas
                    IFNULL(SUM(rh.horas), 0) AS horas_total,

                    -- Horas ponderadas con multiplicador legal (Art. 168-172 CST Colombia)
                    IFNULL(SUM(rh.horas * CASE IFNULL(rh.tipo_hora, 'ordinaria')
                        WHEN 'ordinaria'             THEN 1.00000
                        WHEN 'recargo_nocturno'      THEN 1.35000
                        WHEN 'extra_diurna'          THEN 1.25000
                        WHEN 'extra_nocturna'        THEN 1.75000
                        WHEN 'festiva_ordinaria'     THEN 1.75000
                        WHEN 'extra_festiva_diurna'  THEN 2.00000
                        WHEN 'extra_festiva_nocturna'THEN 2.50000
                        ELSE 1.00000 END
                    ), 0) AS horas_ponderadas,

                    COUNT(rh.fecha) AS dias_trabajados
             FROM empleados e
             LEFT JOIN registro_horas rh
                ON rh.empleado_id = e.id
                AND MONTH(rh.fecha) = :mes
                AND YEAR(rh.fecha)  = :anio
             WHERE e.activo = 1 AND e.tipo_contrato = 'por_horas'
             GROUP BY e.id
             ORDER BY e.nombre_completo"
        );
        $stmt->execute([':horas_mes' => $horas_mes, ':mes' => $mes, ':anio' => $anio]);
        return $stmt->fetchAll();
    }

    // ── PARÁMETROS LABORALES ──────────────────────────────────────────────────

    /** Lista todos los países configurados. */
    public static function paises_disponibles(): array
    {
        try {
            return db()->query(
                'SELECT DISTINCT pais FROM parametros_laborales ORDER BY pais'
            )->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\Exception $e) {
            return ['Colombia'];
        }
    }

    /** Lista los parámetros de un país organizados por categoría. */
    public static function parametros_pais(string $pais = 'Colombia'): array
    {
        $stmt = db()->prepare(
            'SELECT * FROM parametros_laborales
             WHERE pais = :pais
             ORDER BY categoria, orden, clave'
        );
        $stmt->execute([':pais' => $pais]);
        $rows = $stmt->fetchAll();

        $grouped = [];
        foreach ($rows as $r) {
            $grouped[$r['categoria']][] = $r;
        }
        return $grouped;
    }

    /** Actualiza el valor de un parámetro laboral. */
    public static function actualizar_parametro(int $id, float $valor, bool $activo): void
    {
        $uid = (int)($_SESSION['usuario_id'] ?? 0);
        db()->prepare(
            'UPDATE parametros_laborales
             SET valor = ?, activo = ?, updated_at = NOW()
             WHERE id = ?'
        )->execute([$valor, $activo ? 1 : 0, $id]);
        log_registrar('parametros_laborales', $id, 'valor', null, (string)$valor, 'UPDATE');
    }

    /** Crea un nuevo parámetro laboral. */
    public static function crear_parametro(array $datos): int
    {
        db()->prepare(
            'INSERT INTO parametros_laborales
                (pais, clave, nombre, valor, tipo, aplica_a, categoria,
                 aplica_contratos, descripcion, orden)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $datos['pais']             ?? 'Colombia',
            $datos['clave'],
            $datos['nombre'],
            (float)$datos['valor'],
            $datos['tipo']             ?? 'porcentaje',
            $datos['aplica_a']         ?? 'empleador',
            $datos['categoria']        ?? 'carga_parafiscal',
            $datos['aplica_contratos'] ?? 'tiempo_completo,medio_tiempo,por_horas',
            $datos['descripcion']      ?? null,
            (int)($datos['orden']      ?? 50),
        ]);
        $id = (int)db()->lastInsertId();
        // Invalidar caché
        self::$paramsCache = [];
        return $id;
    }

    // ── CONSULTAS ─────────────────────────────────────────────────────────────

    public static function empleados_activos(): array
    {
        return db()->query(
            "SELECT * FROM empleados WHERE activo = 1 ORDER BY nombre_completo"
        )->fetchAll();
    }

    public static function todos_empleados(string $ver = 'todos'): array
    {
        $filtro = function_exists('filtro_estado_sql')
            ? filtro_estado_sql($ver, 'activo', 'activo', 'e')
            : '';
        return db()->query(
            "SELECT e.*, u.nombre AS usuario_nombre
             FROM empleados e
             LEFT JOIN usuarios u ON u.id = e.usuario_id
             WHERE 1=1" . $filtro . "
             ORDER BY e.activo DESC, e.nombre_completo"
        )->fetchAll();
    }

    public static function liquidaciones_periodo(int $mes, int $anio): array
    {
        $stmt = db()->prepare(
            'SELECT nl.*, e.nombre_completo, e.cargo,
                    IFNULL(nl.tipo_contrato, e.tipo_contrato) AS contrato_usado,
                    COALESCE(e.tipo_costo, \'indirecto\')      AS tipo_costo
             FROM nomina_liquidaciones nl
             JOIN empleados e ON e.id = nl.empleado_id
             WHERE nl.periodo_mes = ? AND nl.periodo_anio = ?
             ORDER BY e.tipo_costo, e.nombre_completo'
        );
        $stmt->execute([$mes, $anio]);
        return $stmt->fetchAll();
    }

    public static function resumen_periodo(int $mes, int $anio): array
    {
        $stmt = db()->prepare(
            'SELECT COUNT(*)                        AS num_empleados,
                    SUM(salario_base)               AS total_salarios,
                    SUM(aux_transporte)             AS total_aux,
                    SUM(total_cargas)               AS total_cargas,
                    SUM(total_provisiones)          AS total_provisiones,
                    SUM(costo_total_empleador)      AS costo_total,
                    SUM(CASE WHEN pagado = 1 THEN 1 ELSE 0 END) AS pagados,
                    SUM(CASE WHEN pagado = 0 THEN 1 ELSE 0 END) AS pendientes
             FROM nomina_liquidaciones
             WHERE periodo_mes = ? AND periodo_anio = ?'
        );
        $stmt->execute([$mes, $anio]);
        return $stmt->fetch();
    }

    public static function marcar_pagado(int $id, string $fecha_pago): void
    {
        $uid = (int)($_SESSION['usuario_id'] ?? 0);
        db()->prepare(
            'UPDATE nomina_liquidaciones
             SET pagado = 1, fecha_pago_nomina = ?, updated_by = ?
             WHERE id = ?'
        )->execute([$fecha_pago, $uid, $id]);
        log_registrar('nomina_liquidaciones', $id, 'pagado', '0', '1', 'UPDATE');
    }

    public static function marcar_pendiente(int $id): void
    {
        $uid = (int)($_SESSION['usuario_id'] ?? 0);
        db()->prepare(
            'UPDATE nomina_liquidaciones
             SET pagado = 0, fecha_pago_nomina = NULL, updated_by = ?
             WHERE id = ?'
        )->execute([$uid, $id]);
        log_registrar('nomina_liquidaciones', $id, 'pagado', '1', '0', 'UPDATE');
    }

    public static function eliminar_periodo(int $mes, int $anio): int
    {
        $uid = (int)($_SESSION['usuario_id'] ?? 0);
        $ids = db()->prepare(
            'SELECT id FROM nomina_liquidaciones WHERE periodo_mes = ? AND periodo_anio = ?'
        );
        $ids->execute([$mes, $anio]);
        foreach ($ids->fetchAll() as $row) {
            log_registrar('nomina_liquidaciones', $row['id'], 'periodo', "$mes/$anio", null, 'DELETE');
            // Contabilidad (Fase 4b): reversar el asiento de causación de esa liquidación.
            try {
                require_once __DIR__ . '/ContabilidadModel.php';
                ContabilidadModel::reversar_por_origen('nomina', (int)$row['id']);
            } catch (\Throwable $ex) { error_log('[QuickServe OS contab nomina del] ' . $ex->getMessage()); }
        }
        $stmt = db()->prepare(
            'DELETE FROM nomina_liquidaciones WHERE periodo_mes = ? AND periodo_anio = ?'
        );
        $stmt->execute([$mes, $anio]);
        return $stmt->rowCount();
    }

    // ── CRUD DE EMPLEADOS ─────────────────────────────────────────────────────

    public static function crear_empleado(array $datos): int
    {
        $nombre = trim($datos['nombre_completo'] ?? '');
        if (empty($nombre)) throw new \RuntimeException('El nombre es obligatorio.');
        $tipos_contrato = ['tiempo_completo','medio_tiempo','por_horas','por_servicio'];
        $tipo    = in_array($datos['tipo_contrato'] ?? '', $tipos_contrato, true)
                   ? $datos['tipo_contrato'] : 'tiempo_completo';
        $salario = (float)($datos['salario_base'] ?? 0);
        // por_servicio no requiere salario_base — usa valor_proyecto
        if ($tipo !== 'por_servicio' && $salario <= 0) {
            throw new \RuntimeException('El salario base es obligatorio para este tipo de contrato.');
        }
        if ($tipo === 'por_servicio' && (float)($datos['valor_proyecto'] ?? 0) <= 0) {
            throw new \RuntimeException('El valor del proyecto es obligatorio para contratos por servicio.');
        }

        $uid = (int)($_SESSION['usuario_id'] ?? 0);

        $stmt = db()->prepare(
            'INSERT INTO empleados
                (nombre_completo, documento_identidad, cargo, tipo_contrato, pais_laboral,
                 fecha_ingreso, salario_base, valor_hora, valor_proyecto,
                 horas_semana, aplica_aux_transporte, tipo_costo, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $nombre,
            trim($datos['documento_identidad']    ?? '') ?: null,
            trim($datos['cargo']                  ?? '') ?: null,
            $tipo,
            $datos['pais_laboral']                 ?? 'Colombia',
            $datos['fecha_ingreso']                ?? date('Y-m-d'),
            $salario,
            (float)($datos['valor_hora']           ?? 0) ?: null,
            (float)($datos['valor_proyecto']        ?? 0) ?: null,
            (int)($datos['horas_semana']            ?? 0) ?: null,
            isset($datos['aplica_aux_transporte'])  ? 1 : 0,
            in_array($datos['tipo_costo'] ?? '', ['directo','indirecto'])
                ? $datos['tipo_costo'] : 'indirecto',
            $uid,
        ]);

        $id = (int)db()->lastInsertId();
        log_registrar('empleados', $id, 'nombre_completo', null, $nombre, 'INSERT');
        return $id;
    }

    public static function actualizar_empleado(int $id, array $datos): void
    {
        $uid     = (int)($_SESSION['usuario_id'] ?? 0);
        $nombre  = trim($datos['nombre_completo'] ?? '');
        $tipos_contrato = ['tiempo_completo','medio_tiempo','por_horas','por_servicio'];
        $tipo    = in_array($datos['tipo_contrato'] ?? '', $tipos_contrato, true)
                   ? $datos['tipo_contrato'] : 'tiempo_completo';
        $salario = (float)($datos['salario_base'] ?? 0);
        if (empty($nombre)) {
            throw new \RuntimeException('El nombre es obligatorio.');
        }
        if ($tipo !== 'por_servicio' && $salario <= 0) {
            throw new \RuntimeException('El salario base es obligatorio para este tipo de contrato.');
        }
        if ($tipo === 'por_servicio' && (float)($datos['valor_proyecto'] ?? 0) <= 0) {
            throw new \RuntimeException('El valor del proyecto es obligatorio para contratos por servicio.');
        }

        $prev = db()->prepare('SELECT salario_base FROM empleados WHERE id = ?');
        $prev->execute([$id]);
        $anterior = $prev->fetchColumn();

        db()->prepare(
            'UPDATE empleados
             SET nombre_completo       = ?,
                 documento_identidad   = ?,
                 cargo                 = ?,
                 tipo_contrato         = ?,
                 pais_laboral          = ?,
                 fecha_ingreso         = ?,
                 salario_base          = ?,
                 valor_hora            = ?,
                 valor_proyecto        = ?,
                 horas_semana          = ?,
                 aplica_aux_transporte = ?,
                 tipo_costo            = ?,
                 updated_by            = ?
             WHERE id = ?'
        )->execute([
            $nombre,
            trim($datos['documento_identidad']   ?? '') ?: null,
            trim($datos['cargo']                 ?? '') ?: null,
            $tipo,
            $datos['pais_laboral']                ?? 'Colombia',
            $datos['fecha_ingreso']               ?? date('Y-m-d'),
            $salario,
            (float)($datos['valor_hora']          ?? 0) ?: null,
            (float)($datos['valor_proyecto']       ?? 0) ?: null,
            (int)($datos['horas_semana']           ?? 0) ?: null,
            isset($datos['aplica_aux_transporte']) ? 1 : 0,
            in_array($datos['tipo_costo'] ?? '', ['directo','indirecto'])
                ? $datos['tipo_costo'] : 'indirecto',
            $uid, $id,
        ]);

        if ((float)$anterior !== $salario) {
            log_registrar('empleados', $id, 'salario_base',
                (string)$anterior, (string)$salario, 'UPDATE');
        }
    }

    // ── HELPERS ───────────────────────────────────────────────────────────────

    /** Detecta si las columnas de migración 007 existen en nomina_liquidaciones. */
    private static function columnas_liquidacion_existen(): bool
    {
        static $cache = null;
        if ($cache !== null) return $cache;
        try {
            $cols = db()->query('SHOW COLUMNS FROM nomina_liquidaciones LIKE "tipo_contrato"')->fetch();
            $cache = (bool)$cols;
        } catch (\Exception $e) {
            $cache = false;
        }
        return $cache;
    }
}
