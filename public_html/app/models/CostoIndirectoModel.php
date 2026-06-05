<?php
/**
 * CostoIndirectoModel — Gestión de costos del negocio.
 *
 * El "valor_mensual" es el equivalente mensual según la frecuencia de pago:
 *   mensual=÷1  bimestral=÷2  trimestral=÷3  semestral=÷6  anual=÷12
 *
 * clasificacion:
 *   directo   = trazable a un producto específico (empaques, gas, etc.)
 *   indirecto = costo general del negocio (arriendo, servicios, etc.)
 *
 * Solo los costos con activo=1 suman al costo operativo mensual.
 */
class CostoIndirectoModel
{
    /** Expresión SQL que convierte valor+frecuencia → equivalente mensual */
    private const SQL_MENSUAL = "
        CASE ci.frecuencia
            WHEN 'mensual'    THEN ci.valor
            WHEN 'bimestral'  THEN ci.valor / 2
            WHEN 'trimestral' THEN ci.valor / 3
            WHEN 'semestral'  THEN ci.valor / 6
            WHEN 'anual'      THEN ci.valor / 12
            ELSE ci.valor
        END";

    /** Retorna todos los costos con el equivalente mensual calculado. */
    public static function todos(): array
    {
        return db()->query(
            'SELECT ci.*,
                    ' . self::SQL_MENSUAL . ' AS valor_mensual
             FROM costos_indirectos ci
             ORDER BY ci.activo DESC, ci.categoria, ci.nombre'
        )->fetchAll();
    }

    /** Resumen: conteos y total mensual de costos activos. */
    public static function resumen(): array
    {
        return db()->query(
            "SELECT
                COUNT(*) AS total_registros,
                SUM(activo = 1) AS activos,
                SUM(activo = 0) AS pausados,
                COALESCE(SUM(CASE WHEN activo = 1 THEN
                    CASE frecuencia
                        WHEN 'mensual'    THEN valor
                        WHEN 'bimestral'  THEN valor / 2
                        WHEN 'trimestral' THEN valor / 3
                        WHEN 'semestral'  THEN valor / 6
                        WHEN 'anual'      THEN valor / 12
                        ELSE valor
                    END
                ELSE 0 END), 0) AS total_mensual
             FROM costos_indirectos"
        )->fetch();
    }

    /**
     * Total mensual equivalente de costos activos.
     * Usado por el módulo Productos para calcular el costo de fabricación.
     */
    public static function total_mensual_activo(): float
    {
        return (float) db()->query(
            "SELECT COALESCE(SUM(
                CASE frecuencia
                    WHEN 'mensual'    THEN valor
                    WHEN 'bimestral'  THEN valor / 2
                    WHEN 'trimestral' THEN valor / 3
                    WHEN 'semestral'  THEN valor / 6
                    WHEN 'anual'      THEN valor / 12
                    ELSE valor
                END
             ), 0)
             FROM costos_indirectos
             WHERE activo = 1"
        )->fetchColumn();
    }

    /** Inserta un nuevo costo. Retorna el ID insertado. */
    public static function crear(array $datos): int
    {
        self::validar($datos);
        $uid = (int) ($_SESSION['usuario_id'] ?? 0);

        db()->prepare(
            'INSERT INTO costos_indirectos
                (nombre, categoria, descripcion, clasificacion, tipo, frecuencia, valor,
                 fecha_inicio, fecha_fin, notas, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            trim($datos['nombre']),
            $datos['categoria']      ?? 'otro',
            trim($datos['descripcion'] ?? '') ?: null,
            in_array($datos['clasificacion'] ?? '', ['directo','indirecto'])
                ? $datos['clasificacion'] : 'indirecto',
            $datos['tipo']           ?? 'fijo',
            $datos['frecuencia']     ?? 'mensual',
            (float) $datos['valor'],
            $datos['fecha_inicio'],
            !empty($datos['fecha_fin']) ? $datos['fecha_fin'] : null,
            trim($datos['notas']     ?? '') ?: null,
            $uid,
        ]);

        return (int) db()->lastInsertId();
    }

    /** Actualiza un costo existente. */
    public static function actualizar(int $id, array $datos): void
    {
        if ($id <= 0) throw new RuntimeException('ID inválido.');
        self::validar($datos);
        $uid = (int) ($_SESSION['usuario_id'] ?? 0);

        db()->prepare(
            'UPDATE costos_indirectos
             SET nombre=?, categoria=?, descripcion=?, clasificacion=?, tipo=?,
                 frecuencia=?, valor=?, fecha_inicio=?, fecha_fin=?, notas=?, updated_by=?
             WHERE id=?'
        )->execute([
            trim($datos['nombre']),
            $datos['categoria']      ?? 'otro',
            trim($datos['descripcion'] ?? '') ?: null,
            in_array($datos['clasificacion'] ?? '', ['directo','indirecto'])
                ? $datos['clasificacion'] : 'indirecto',
            $datos['tipo']           ?? 'fijo',
            $datos['frecuencia']     ?? 'mensual',
            (float) $datos['valor'],
            $datos['fecha_inicio'],
            !empty($datos['fecha_fin']) ? $datos['fecha_fin'] : null,
            trim($datos['notas']     ?? '') ?: null,
            $uid,
            $id,
        ]);
    }

    /** Activa o pausa el costo (toggle). Retorna el nuevo estado activo. */
    public static function toggle(int $id): bool
    {
        $uid = (int) ($_SESSION['usuario_id'] ?? 0);
        db()->prepare(
            'UPDATE costos_indirectos SET activo = NOT activo, updated_by = ? WHERE id = ?'
        )->execute([$uid, $id]);

        $stmt = db()->prepare('SELECT activo FROM costos_indirectos WHERE id = ?');
        $stmt->execute([$id]);
        return (bool) $stmt->fetchColumn();
    }

    /** Valida campos requeridos; lanza RuntimeException si hay error. */
    private static function validar(array $datos): void
    {
        if (empty(trim($datos['nombre'] ?? '')))
            throw new RuntimeException('El nombre del costo es obligatorio.');
        if ((float) ($datos['valor'] ?? 0) < 0)
            throw new RuntimeException('El valor no puede ser negativo.');
        if (empty($datos['fecha_inicio']))
            throw new RuntimeException('La fecha de inicio es obligatoria.');
    }
}
