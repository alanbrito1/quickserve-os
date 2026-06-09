<?php
/**
 * app/models/PresentacionModel.php
 * Catálogo de presentaciones de compra por insumo (migración 039).
 *
 * Cada insumo puede tener N presentaciones catalogadas (frasco 900ml, galón 3.785L…).
 * La unidad canónica del insumo NUNCA cambia — esta tabla solo define "cómo se compra".
 *
 * Matemática de compra:
 *   cantidad (canónica) = cant_presentaciones × cantidad_base
 *   precio_unitario     = precio_presentacion  / cantidad_base
 *
 * SIN FK A NIVEL BD: patrón ajustes_stock / producto_variantes (errno 121 en cPanel).
 * Integridad referencial garantizada por PHP.
 *
 * equiv_cantidad/equiv_unidad por presentación: permite registrar que una lata
 * de 170g contiene más que la equivalencia genérica del insumo (160g).
 * Al guardar la compra, CompraModel actualiza insumos.equiv_cantidad con el
 * valor de la presentación usada para reflejar el lote actual en inventario.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/AuditoriaHelper.php';

class PresentacionModel
{
    private static ?bool $tiene039 = null;

    public static function tabla_existe_publica(): bool
    {
        return self::tabla_existe();
    }

    private static function tabla_existe(): bool
    {
        if (self::$tiene039 !== null) return self::$tiene039;
        try {
            self::$tiene039 = (int)db()->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = 'insumo_presentaciones'
                   AND COLUMN_NAME  = 'id'"
            )->fetchColumn() > 0;
        } catch (\Exception $e) {
            self::$tiene039 = false;
        }
        return self::$tiene039;
    }

    /**
     * Retorna todas las presentaciones activas de un insumo.
     * Ordenadas: predeterminada primero, luego alfabéticamente.
     */
    public static function de_insumo(int $insumo_id): array
    {
        if (!self::tabla_existe()) return [];
        $stmt = db()->prepare(
            'SELECT * FROM insumo_presentaciones
             WHERE insumo_id = ? AND activo = 1
             ORDER BY es_predeterminada DESC, nombre'
        );
        $stmt->execute([$insumo_id]);
        return $stmt->fetchAll();
    }

    /**
     * Retorna todas las presentaciones activas de todos los insumos de una vez.
     * Usado en compras.php para construir el mapa JS en un solo query.
     * Retorna: array indexado por insumo_id → array de presentaciones.
     */
    public static function todas_agrupadas(): array
    {
        if (!self::tabla_existe()) return [];
        $rows = db()->query(
            'SELECT * FROM insumo_presentaciones
             WHERE activo = 1
             ORDER BY insumo_id, es_predeterminada DESC, nombre'
        )->fetchAll();

        $mapa = [];
        foreach ($rows as $r) {
            $mapa[(int)$r['insumo_id']][] = $r;
        }
        return $mapa;
    }

    /**
     * Crea una nueva presentación para un insumo.
     * Si es_predeterminada = 1, quita el flag de las demás del mismo insumo (atómico).
     */
    public static function crear(int $insumo_id, array $d): int
    {
        self::_requerir_tabla();
        $uid = (int)($_SESSION['usuario_id'] ?? 0);
        $pdo = db();

        $pdo->beginTransaction();
        try {
            if (!empty($d['es_predeterminada'])) {
                $pdo->prepare(
                    'UPDATE insumo_presentaciones SET es_predeterminada=0, updated_by=?
                     WHERE insumo_id=?'
                )->execute([$uid, $insumo_id]);
            }

            $pdo->prepare(
                'INSERT INTO insumo_presentaciones
                    (insumo_id, nombre, cantidad_base, unidad_compra, precio_referencia,
                     equiv_cantidad, equiv_unidad, es_predeterminada, created_by, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $insumo_id,
                substr(trim($d['nombre']), 0, 60),
                (float)str_replace(',', '.', $d['cantidad_base'] ?? '0'),
                substr(trim($d['unidad_compra'] ?? ''), 0, 30),
                !empty($d['precio_referencia']) ? (float)str_replace(',', '.', $d['precio_referencia']) : null,
                !empty($d['equiv_cantidad'])    ? (float)str_replace(',', '.', $d['equiv_cantidad'])    : null,
                !empty($d['equiv_unidad'])      ? substr(trim($d['equiv_unidad']), 0, 20)               : null,
                !empty($d['es_predeterminada']) ? 1 : 0,
                $uid, $uid,
            ]);
            $id = (int)$pdo->lastInsertId();
            $pdo->commit();
            return $id;
        } catch (\Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Actualiza una presentación existente.
     */
    public static function editar(int $id, array $d): void
    {
        self::_requerir_tabla();
        $uid = (int)($_SESSION['usuario_id'] ?? 0);
        $pdo = db();

        $row = $pdo->prepare('SELECT insumo_id FROM insumo_presentaciones WHERE id=? AND activo=1');
        $row->execute([$id]);
        $insumo_id = (int)($row->fetchColumn() ?: 0);
        if (!$insumo_id) throw new \RuntimeException('Presentación no encontrada.');

        $pdo->beginTransaction();
        try {
            if (!empty($d['es_predeterminada'])) {
                $pdo->prepare(
                    'UPDATE insumo_presentaciones SET es_predeterminada=0, updated_by=?
                     WHERE insumo_id=? AND id != ?'
                )->execute([$uid, $insumo_id, $id]);
            }

            $pdo->prepare(
                'UPDATE insumo_presentaciones
                 SET nombre=?, cantidad_base=?, unidad_compra=?, precio_referencia=?,
                     equiv_cantidad=?, equiv_unidad=?, es_predeterminada=?, updated_by=?
                 WHERE id=?'
            )->execute([
                substr(trim($d['nombre']), 0, 60),
                (float)str_replace(',', '.', $d['cantidad_base'] ?? '0'),
                substr(trim($d['unidad_compra'] ?? ''), 0, 30),
                !empty($d['precio_referencia']) ? (float)str_replace(',', '.', $d['precio_referencia']) : null,
                !empty($d['equiv_cantidad'])    ? (float)str_replace(',', '.', $d['equiv_cantidad'])    : null,
                !empty($d['equiv_unidad'])      ? substr(trim($d['equiv_unidad']), 0, 20)               : null,
                !empty($d['es_predeterminada']) ? 1 : 0,
                $uid, $id,
            ]);
            $pdo->commit();
        } catch (\Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Soft-delete de una presentación (activo = 0).
     * No elimina físicamente para preservar la trazabilidad de compras históricas
     * (compra_detalles.presentacion_id puede apuntar a esta presentación).
     */
    public static function eliminar(int $id): void
    {
        self::_requerir_tabla();
        $uid = (int)($_SESSION['usuario_id'] ?? 0);
        db()->prepare(
            'UPDATE insumo_presentaciones SET activo=0, updated_by=? WHERE id=?'
        )->execute([$uid, $id]);
    }

    private static function _requerir_tabla(): void
    {
        if (!self::tabla_existe()) {
            throw new \RuntimeException('Migración 039 no aplicada. Aplica 039_insumo_presentaciones.sql primero.');
        }
    }
}
