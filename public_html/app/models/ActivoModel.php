<?php
/**
 * app/models/ActivoModel.php
 * Gestión de activos fijos y cálculo de depreciación.
 *
 * FÓRMULAS (spec §5.2):
 *   Depreciación Mensual = Costo Inicial / vida_util_meses
 *   Depreciación Diaria  = Depreciación Mensual / 30.41666  (= 365 / 12)
 *
 * NOTA: El trigger trg_activos_deprec_insert/update calcula automáticamente
 * depreciacion_mensual y depreciacion_diaria en cada INSERT/UPDATE.
 * Este modelo solo gestiona el CRUD y las consultas agregadas.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/AuditoriaHelper.php';

class ActivoModel
{
    /**
     * Retorna activos con estado de vida calculado.
     * Estado: nuevo (<50%), medio (50-74%), critico (75-99%), depreciado (≥100%).
     * @param string $orden  'fecha'|'nombre'|'lugar'
     */
    public static function todos(string $orden = 'fecha'): array
    {
        $orderBy = match ($orden) {
            'nombre' => 'a.nombre, a.fecha_adquisicion DESC',
            'lugar'  => 'a.lugar_compra, a.fecha_adquisicion DESC',
            default  => 'a.activo DESC, a.fecha_adquisicion DESC',
        };

        // ── Lógica de depreciación ──────────────────────────────────────────────
        // fecha_adquisicion = cuándo se COMPRÓ el equipo (no afecta la depreciación)
        // fecha_inicio_uso  = cuándo ENTRÓ EN OPERACIÓN → desde aquí corre la depreciación
        //
        // Casos especiales:
        //   NULL  → activo comprado pero aún no en uso → 0% depreciado ("en_espera")
        //   Futura → programado para uso futuro → 0% depreciado
        //   Pasada → calcular TIMESTAMPDIFF desde esa fecha
        //
        // CAST AS SIGNED evita SQLSTATE[22003] cuando la resta de UNSIGNED da negativo
        // en MySQL modo estricto.

        // Detectar si la columna fecha_inicio_uso ya existe (migración 006)
        $tieneInicioUso = self::columnas_existen(['fecha_inicio_uso']);

        // Solo fecha_inicio_uso determina el inicio de depreciación.
        // Sin ella el activo está en estado "en_espera" y NO se deprecia.
        $campoFecha = $tieneInicioUso
            ? 'a.fecha_inicio_uso'
            : 'a.fecha_adquisicion'; // fallback solo si migración 006 no está aplicada

        $stmtSql = "SELECT a.*,
                    prov.nombre AS proveedor_nombre,
                    -- Fecha de fin de vida útil (referencia visual)
                    DATE_ADD($campoFecha, INTERVAL CAST(a.vida_util_meses AS SIGNED) MONTH)
                        AS fecha_fin_util,

                    -- Meses que lleva en uso (0 si no ha iniciado)
                    GREATEST(0,
                        CASE
                            WHEN $campoFecha IS NULL OR $campoFecha > CURDATE() THEN 0
                            ELSE TIMESTAMPDIFF(MONTH, $campoFecha, CURDATE())
                        END
                    ) AS meses_transcurridos,

                    -- Meses restantes de vida útil
                    CASE
                        WHEN $campoFecha IS NULL OR $campoFecha > CURDATE()
                            THEN CAST(a.vida_util_meses AS SIGNED)
                        ELSE GREATEST(0,
                            CAST(a.vida_util_meses AS SIGNED)
                            - TIMESTAMPDIFF(MONTH, $campoFecha, CURDATE())
                        )
                    END AS meses_restantes,

                    -- Porcentaje depreciado (0–100)
                    CASE
                        WHEN $campoFecha IS NULL OR $campoFecha > CURDATE() THEN 0
                        ELSE LEAST(100, IFNULL(ROUND(
                            TIMESTAMPDIFF(MONTH, $campoFecha, CURDATE())
                            / NULLIF(CAST(a.vida_util_meses AS SIGNED), 0) * 100
                        ), 0))
                    END AS pct_depreciado,

                    -- Estado de vida útil
                    CASE
                        WHEN $campoFecha IS NULL OR $campoFecha > CURDATE()
                            THEN 'en_espera'
                        WHEN TIMESTAMPDIFF(MONTH, $campoFecha, CURDATE())
                             >= CAST(a.vida_util_meses AS SIGNED)
                             THEN 'depreciado'
                        WHEN TIMESTAMPDIFF(MONTH, $campoFecha, CURDATE())
                             >= CAST(a.vida_util_meses AS SIGNED) * 0.75
                             THEN 'critico'
                        WHEN TIMESTAMPDIFF(MONTH, $campoFecha, CURDATE())
                             >= CAST(a.vida_util_meses AS SIGNED) * 0.50
                             THEN 'medio'
                        ELSE 'nuevo'
                    END AS estado_vida

             FROM activos a
             LEFT JOIN proveedores prov ON prov.id = a.proveedor_id
             ORDER BY $orderBy";

        $stmt = db()->prepare($stmtSql);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Suma las depreciaciones diarias de activos en uso que aún tienen vida útil.
     * Un activo totalmente depreciado ya no genera costo operativo diario.
     */
    public static function costo_diario_total(): float
    {
        $tieneInicio = self::columnas_existen(['fecha_inicio_uso']);

        // Solo suma activos que YA tienen fecha_inicio_uso asignada y en uso.
        // Sin fecha_inicio_uso → depreciacion_diaria = 0 (regla de negocio).
        if ($tieneInicio) {
            $row = db()->query(
                "SELECT IFNULL(SUM(
                    CASE
                        WHEN fecha_inicio_uso IS NOT NULL
                          AND fecha_inicio_uso <= CURDATE()
                          AND TIMESTAMPDIFF(MONTH, fecha_inicio_uso, CURDATE())
                              < CAST(vida_util_meses AS SIGNED)
                        THEN depreciacion_diaria
                        ELSE 0
                    END
                 ), 0) AS total
                 FROM activos WHERE activo = 1"
            )->fetch();
        } else {
            // Fallback: migración 006 no aplicada
            $row = db()->query(
                "SELECT IFNULL(SUM(
                    CASE
                        WHEN TIMESTAMPDIFF(MONTH, fecha_adquisicion, CURDATE())
                             < CAST(vida_util_meses AS SIGNED)
                        THEN depreciacion_diaria ELSE 0
                    END
                 ), 0) AS total FROM activos WHERE activo = 1"
            )->fetch();
        }
        return round((float)$row['total'], 4);
    }

    /** Resumen para el dashboard y el encabezado de la página. */
    public static function resumen(): array
    {
        $tieneInicio = self::columnas_existen(['fecha_inicio_uso']);

        if ($tieneInicio) {
            // Solo cuenta depreciación para activos con fecha_inicio_uso asignada y en curso
            $row = db()->query(
                "SELECT COUNT(*)                       AS total,
                        IFNULL(SUM(costo_inicial), 0)  AS inversion_total,
                        IFNULL(SUM(CASE
                            WHEN fecha_inicio_uso IS NOT NULL
                              AND fecha_inicio_uso <= CURDATE()
                              AND TIMESTAMPDIFF(MONTH, fecha_inicio_uso, CURDATE())
                                  < CAST(vida_util_meses AS SIGNED)
                            THEN depreciacion_mensual ELSE 0 END), 0) AS dep_mensual_total,
                        IFNULL(SUM(CASE
                            WHEN fecha_inicio_uso IS NOT NULL
                              AND fecha_inicio_uso <= CURDATE()
                              AND TIMESTAMPDIFF(MONTH, fecha_inicio_uso, CURDATE())
                                  < CAST(vida_util_meses AS SIGNED)
                            THEN depreciacion_diaria ELSE 0 END), 0) AS dep_diaria_total
                 FROM activos WHERE activo = 1"
            )->fetch();
        } else {
            $row = db()->query(
                "SELECT COUNT(*) AS total,
                        IFNULL(SUM(costo_inicial),0) AS inversion_total,
                        IFNULL(SUM(CASE WHEN TIMESTAMPDIFF(MONTH,fecha_adquisicion,CURDATE())
                            < CAST(vida_util_meses AS SIGNED) THEN depreciacion_mensual ELSE 0 END),0) AS dep_mensual_total,
                        IFNULL(SUM(CASE WHEN TIMESTAMPDIFF(MONTH,fecha_adquisicion,CURDATE())
                            < CAST(vida_util_meses AS SIGNED) THEN depreciacion_diaria ELSE 0 END),0) AS dep_diaria_total
                 FROM activos WHERE activo = 1"
            )->fetch();
        }
        return $row;
    }

    /**
     * Crea un nuevo activo. Si se provee precio_unitario×numero_unidades, calcula costo_inicial.
     * El trigger MySQL recalcula depreciacion_mensual y depreciacion_diaria automáticamente.
     * @throws RuntimeException si datos obligatorios faltan
     */
    public static function crear(array $datos): int
    {
        $nombre = trim($datos['nombre'] ?? '');
        if (empty($nombre)) throw new RuntimeException('El nombre del activo es obligatorio.');

        $num_u  = max(1, (int)($datos['numero_unidades'] ?? 1));
        $precio_u = (float)($datos['precio_unitario']  ?? 0);

        // costo_inicial: si hay precio por unidad se calcula; si no, se toma el campo directo
        if ($precio_u > 0) {
            $costo = $precio_u * $num_u;
        } else {
            $costo = (float)($datos['costo_inicial'] ?? 0);
            if ($costo <= 0) throw new RuntimeException('Ingresa el precio por unidad o el costo total.');
        }

        $vida = max(1, (int)($datos['vida_util_meses'] ?? 12));
        $uid  = (int)($_SESSION['usuario_id'] ?? 0);

        // Detectar columnas nuevas para usar INSERT extendido o básico
        $colsNuevas = self::columnas_existen(['numero_unidades', 'precio_unitario', 'serial']);

        // Fecha de inicio de uso: si no se proporciona, dejar NULL (no ha iniciado uso)
        $fecha_inicio_uso = trim($datos['fecha_inicio_uso'] ?? '') ?: null;

        if ($colsNuevas) {
            // Detectar si la columna fecha_inicio_uso existe (migración 006)
            $tieneInicio = self::columnas_existen(['fecha_inicio_uso']);
            $colsFechaInicio = $tieneInicio ? ', fecha_inicio_uso' : '';
            $valFechaInicio  = $tieneInicio ? ', ?' : '';
            $paramsFechaInicio = $tieneInicio ? [$fecha_inicio_uso] : [];

            $stmt = db()->prepare(
                'INSERT INTO activos
                    (nombre, numero_unidades, precio_unitario, descripcion,
                     lugar_compra, proveedor_id, serial, costo_inicial,
                     fecha_adquisicion' . $colsFechaInicio . ', garantia_hasta, vida_util_meses,
                     estado_fisico, categoria_activo, responsable, notas, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?' . $valFechaInicio . ',?,?,?,?,?,?,?)'
            );
            $stmt->execute(array_merge([
                $nombre,
                $num_u,
                $precio_u > 0 ? $precio_u : null,
                trim($datos['descripcion']    ?? '') ?: null,
                trim($datos['lugar_compra']   ?? '') ?: null,
                !empty($datos['proveedor_id']) ? (int)$datos['proveedor_id'] : null,
                trim($datos['serial']          ?? '') ?: null,
                $costo,
                $datos['fecha_adquisicion']   ?? date('Y-m-d'),
            ], $paramsFechaInicio, [
                $datos['garantia_hasta']       ?: null,
                $vida,
                $datos['estado_fisico']        ?? 'bueno',
                $datos['categoria_activo']     ?? 'otro',
                trim($datos['responsable']     ?? '') ?: null,
                trim($datos['notas']           ?? '') ?: null,
                $uid,
            ]));
        } else {
            // Fallback si migración 005 no fue aplicada
            db()->prepare(
                'INSERT INTO activos
                    (nombre, descripcion, lugar_compra, costo_inicial, fecha_adquisicion, vida_util_meses, created_by)
                 VALUES (?,?,?,?,?,?,?)'
            )->execute([
                $nombre,
                trim($datos['descripcion']  ?? '') ?: null,
                trim($datos['lugar_compra'] ?? '') ?: null,
                $costo,
                $datos['fecha_adquisicion'] ?? date('Y-m-d'),
                $vida,
                $uid,
            ]);
        }

        $id = (int)db()->lastInsertId();
        log_registrar('activos', $id, 'nombre', null, $nombre, 'INSERT');
        return $id;
    }

    /** Actualiza un activo. El trigger recalcula la depreciación si cambia el costo. */
    public static function actualizar(int $id, array $datos): void
    {
        $uid    = (int)($_SESSION['usuario_id'] ?? 0);
        $nombre = trim($datos['nombre'] ?? '');
        $vida   = max(1, (int)($datos['vida_util_meses'] ?? 12));
        if (empty($nombre)) throw new RuntimeException('El nombre es obligatorio.');

        $num_u   = max(1, (int)($datos['numero_unidades'] ?? 1));
        $precio_u = (float)($datos['precio_unitario']  ?? 0);
        $costo   = $precio_u > 0 ? $precio_u * $num_u : (float)($datos['costo_inicial'] ?? 0);
        if ($costo <= 0) throw new RuntimeException('Ingresa el precio por unidad o el costo total.');

        $prev = db()->prepare('SELECT costo_inicial FROM activos WHERE id = ?');
        $prev->execute([$id]);
        $costo_ant = $prev->fetchColumn();

        $colsNuevas = self::columnas_existen(['numero_unidades', 'precio_unitario', 'serial']);

        $fecha_inicio_uso = trim($datos['fecha_inicio_uso'] ?? '') ?: null;

        if ($colsNuevas) {
            $tieneInicio    = self::columnas_existen(['fecha_inicio_uso']);
            $tieneProveedor = self::columnas_existen(['proveedor_id']);
            $colFI  = $tieneInicio    ? ', fecha_inicio_uso = ?' : '';
            $valFI  = $tieneInicio    ? [$fecha_inicio_uso] : [];
            $colPV  = $tieneProveedor ? ', proveedor_id = ?'    : '';
            $valPV  = $tieneProveedor ? [!empty($datos['proveedor_id']) ? (int)$datos['proveedor_id'] : null] : [];

            $stmt = db()->prepare(
                'UPDATE activos
                 SET nombre = ?, numero_unidades = ?, precio_unitario = ?,
                     descripcion = ?, lugar_compra = ?, serial = ?,
                     costo_inicial = ?, fecha_adquisicion = ?' . $colFI . ', garantia_hasta = ?,
                     vida_util_meses = ?, estado_fisico = ?, categoria_activo = ?,
                     responsable = ?, notas = ?' . $colPV . ', updated_by = ?
                 WHERE id = ?'
            );
            $stmt->execute(array_merge([
                $nombre, $num_u, $precio_u > 0 ? $precio_u : null,
                trim($datos['descripcion']    ?? '') ?: null,
                trim($datos['lugar_compra']   ?? '') ?: null,
                trim($datos['serial']          ?? '') ?: null,
                $costo,
                $datos['fecha_adquisicion'],
            ], $valFI, [
                $datos['garantia_hasta']       ?: null,
                $vida,
                $datos['estado_fisico']        ?? 'bueno',
                $datos['categoria_activo']     ?? 'otro',
                trim($datos['responsable']     ?? '') ?: null,
                trim($datos['notas']           ?? '') ?: null,
            ], $valPV, [
                $uid, $id,
            ]));
        } else {
            db()->prepare(
                'UPDATE activos
                 SET nombre = ?, descripcion = ?, lugar_compra = ?,
                     costo_inicial = ?, fecha_adquisicion = ?, vida_util_meses = ?, updated_by = ?
                 WHERE id = ?'
            )->execute([
                $nombre,
                trim($datos['descripcion']  ?? '') ?: null,
                trim($datos['lugar_compra'] ?? '') ?: null,
                $costo, $datos['fecha_adquisicion'], $vida, $uid, $id,
            ]);
        }

        if ((float)$costo_ant !== $costo) {
            log_registrar('activos', $id, 'costo_inicial', (string)$costo_ant, (string)$costo, 'UPDATE');
        }
    }

    /** Da de baja / reactiva un activo. */
    public static function toggle_activo(int $id): void
    {
        db()->prepare(
            'UPDATE activos SET activo = NOT activo, updated_by = ? WHERE id = ?'
        )->execute([(int)($_SESSION['usuario_id'] ?? 0), $id]);
    }

    /**
     * Duplica un activo existente (copia todos los campos excepto ID, foto y serial).
     * Útil para registrar equipos idénticos comprados en lotes.
     *
     * @return int ID del nuevo activo
     * @throws RuntimeException si el activo origen no existe
     */
    public static function duplicar(int $id_origen): int
    {
        $row = db()->prepare('SELECT * FROM activos WHERE id = ?');
        $row->execute([$id_origen]);
        $original = $row->fetch();
        if (!$original) throw new RuntimeException('Activo origen no encontrado.');

        $uid = (int)($_SESSION['usuario_id'] ?? 0);

        // Columnas a NO copiar al duplicar:
        //   id              → auto-increment en el nuevo registro
        //   foto_url        → la foto es del activo original, no de la copia
        //   serial          → el número de serie es único por equipo
        //   timestamps      → generados automáticamente por MySQL
        //   depreciacion_*  → el trigger trg_activos_deprec_insert los recalcula
        // IMPORTANTE: si se agregan columnas nuevas a `activos`, revisar si deben excluirse aquí.
        $excluir = ['id', 'foto_url', 'serial', 'created_at', 'created_by', 'updated_at', 'updated_by',
                    'depreciacion_mensual', 'depreciacion_diaria'];

        $campos = [];
        $valores = [];
        foreach ($original as $col => $val) {
            if (!in_array($col, $excluir, true)) {
                $campos[]  = "`$col`";
                $valores[] = $val;
            }
        }
        $campos[]  = '`created_by`';
        $valores[] = $uid;

        $placeholders = implode(',', array_fill(0, count($valores), '?'));
        $sql = 'INSERT INTO activos (' . implode(',', $campos) . ') VALUES (' . $placeholders . ')';
        db()->prepare($sql)->execute($valores);

        $nuevo_id = (int)db()->lastInsertId();
        log_registrar('activos', $nuevo_id, 'duplicado_de', null, (string)$id_origen, 'INSERT');
        return $nuevo_id;
    }

    /**
     * Verifica si columnas dadas existen en la tabla activos.
     * Usado para degradar gracefully si la migración no está aplicada.
     *
     * @param  string[] $columnas
     * @return bool  true si TODAS existen
     */
    public static function columnas_existen(array $columnas): bool
    {
        static $cache = null;
        if ($cache === null) {
            $rows = db()->query('SHOW COLUMNS FROM activos')->fetchAll(\PDO::FETCH_COLUMN);
            $cache = array_flip($rows);
        }
        foreach ($columnas as $col) {
            if (!isset($cache[$col])) return false;
        }
        return true;
    }

    /**
     * Retorna el resumen ampliado incluyendo campos nuevos si existen.
     * Valor en libros = costo_inicial − depreciación acumulada hasta hoy.
     */
    public static function resumen_ampliado(): array
    {
        $colsNuevas = self::columnas_existen(['numero_unidades', 'estado_fisico']);

        $extra = $colsNuevas
            ? ", SUM(CASE WHEN estado_fisico = 'malo' THEN 1 ELSE 0 END)        AS en_mal_estado
               , SUM(CASE WHEN garantia_hasta < CURDATE() THEN 1 ELSE 0 END)   AS garantia_vencida"
            : ', 0 AS en_mal_estado, 0 AS garantia_vencida';

        // Depreciación operativa: solo activos en uso (fecha_inicio_uso definida)
        // Valor en libros:        usa fecha_adquisicion (el activo pierde valor contable desde la compra)
        $row = db()->query(
            "SELECT COUNT(*)                              AS total,
                    IFNULL(SUM(costo_inicial), 0)         AS inversion_total,

                    -- Depreciación operativa: solo activos con fecha_inicio_uso asignada y en curso
                    IFNULL(SUM(CASE
                        WHEN fecha_inicio_uso IS NOT NULL
                          AND fecha_inicio_uso <= CURDATE()
                          AND TIMESTAMPDIFF(MONTH, fecha_inicio_uso, CURDATE())
                              < CAST(vida_util_meses AS SIGNED)
                        THEN depreciacion_mensual ELSE 0 END), 0) AS dep_mensual_total,

                    IFNULL(SUM(CASE
                        WHEN fecha_inicio_uso IS NOT NULL
                          AND fecha_inicio_uso <= CURDATE()
                          AND TIMESTAMPDIFF(MONTH, fecha_inicio_uso, CURDATE())
                              < CAST(vida_util_meses AS SIGNED)
                        THEN depreciacion_diaria ELSE 0 END), 0) AS dep_diaria_total,

                    -- Valor en libros = costo − (depreciación × meses desde COMPRA)
                    -- Usa fecha_adquisicion: el activo pierde valor contable desde que se adquirió
                    IFNULL(SUM(
                        GREATEST(0,
                            costo_inicial - (
                                CAST(depreciacion_mensual AS SIGNED)
                                * GREATEST(0, CAST(TIMESTAMPDIFF(MONTH, fecha_adquisicion, CURDATE()) AS SIGNED))
                            )
                        )
                    ), 0) AS valor_en_libros

                    $extra
             FROM activos WHERE activo = 1"
        )->fetch();
        return $row;
    }
}
