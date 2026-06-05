<?php
/**
 * app/helpers/ListasHelper.php
 * Acceso centralizado a la tabla listas_sistema.
 *
 * Proporciona funciones para leer, validar y gestionar los catálogos
 * configurables del sistema (presentaciones, unidades, categorías, etc.).
 *
 * CACHÉ POR REQUEST:
 *   Cada tipo se consulta una sola vez por request y se guarda en un
 *   array estático. Evita queries repetidas cuando múltiples partes
 *   de la misma página necesitan el mismo catálogo.
 *
 * USO TÍPICO:
 *   // En páginas PHP para render de dropdowns:
 *   $pres = listas_get('presentacion');
 *   foreach ($pres as $item) { echo $item['valor'] . ' - ' . $item['etiqueta']; }
 *
 *   // En APIs para validar que un valor enviado por el usuario es válido:
 *   if (!listas_valor_valido('presentacion', $_POST['presentacion'])) { ... }
 *
 *   // Para obtener la etiqueta de un valor almacenado:
 *   echo listas_etiqueta('categoria_activo', 'equipo_cocina'); // "Equipo de cocina"
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Retorna todos los ítems activos de un catálogo, ordenados por 'orden'.
 * El resultado se cachea en memoria para el request actual.
 *
 * @param string $tipo  Identificador del catálogo (ej: 'presentacion', 'unidad_medida')
 * @return array        [['valor' => '...', 'etiqueta' => '...'], ...]
 */
function listas_get(string $tipo): array
{
    static $cache = [];

    // Respetar la señal de invalidación (ver listas_cache_invalida())
    $inv_all  = !empty($_SERVER['_listas_cache_invalida_all']);
    $inv_tipo = !empty($_SERVER['_listas_cache_invalida_' . $tipo]);
    if ($inv_all || $inv_tipo) {
        unset($cache[$tipo]);
        unset($_SERVER['_listas_cache_invalida_' . $tipo]);
    }

    if (!isset($cache[$tipo])) {
        try {
            $stmt = db()->prepare(
                'SELECT valor, etiqueta FROM listas_sistema
                 WHERE tipo = ? AND activo = 1
                 ORDER BY orden, etiqueta'
            );
            $stmt->execute([$tipo]);
            $cache[$tipo] = $stmt->fetchAll();
        } catch (\Exception $e) {
            // Si la tabla aún no existe (migración 029 pendiente), retorna vacío
            // sin romper la página. El formulario mostrará el select vacío.
            $cache[$tipo] = [];
        }
    }

    return $cache[$tipo];
}

/**
 * Retorna los ítems de un catálogo como array asociativo valor → etiqueta.
 * Útil para renderizar selects con PHP y para mostrar etiquetas en tablas.
 *
 * @param string $tipo
 * @return array ['valor1' => 'Etiqueta 1', 'valor2' => 'Etiqueta 2', ...]
 */
function listas_map(string $tipo): array
{
    $map = [];
    foreach (listas_get($tipo) as $item) {
        $map[$item['valor']] = $item['etiqueta'];
    }
    return $map;
}

/**
 * Verifica si un valor enviado por el usuario existe en un catálogo activo.
 * Usar en APIs para validar inputs antes de guardar en la BD.
 *
 * Ejemplo: listas_valor_valido('presentacion', 'frasco') → true
 *          listas_valor_valido('presentacion', 'inventado') → false
 *
 * @param string  $tipo
 * @param ?string $valor  NULL o vacío → siempre retorna true (campo opcional)
 */
function listas_valor_valido(string $tipo, ?string $valor): bool
{
    // Campo opcional vacío es válido (NULL se guarda en BD)
    if ($valor === null || $valor === '') return true;

    foreach (listas_get($tipo) as $item) {
        if ($item['valor'] === $valor) return true;
    }
    return false;
}

/**
 * Retorna la etiqueta de un valor para mostrar en tablas/reportes.
 * Si el valor no existe en el catálogo, retorna el mismo valor como fallback.
 *
 * @param string  $tipo
 * @param ?string $valor
 * @return string  Etiqueta legible o el valor original si no se encuentra
 */
function listas_etiqueta(string $tipo, ?string $valor): string
{
    if ($valor === null || $valor === '') return '—';

    foreach (listas_get($tipo) as $item) {
        if ($item['valor'] === $valor) return $item['etiqueta'];
    }

    // Fallback: si el valor no está en el catálogo (dato histórico anterior a la migración),
    // mostrar el valor con primera letra en mayúscula para mantener legibilidad
    return ucfirst(str_replace('_', ' ', $valor));
}

/**
 * Invalida la caché de un tipo específico (o toda la caché).
 *
 * NOTA: Las variables static de funciones PHP son por-función, no hay forma
 * de acceder a la static de listas_get() desde otra función. La caché de
 * listas_get() se invalida automáticamente al finalizar el request (PHP destruye
 * todas las variables). Por tanto, esta función es útil SOLO si se llama
 * justo antes de otra llamada a listas_get() en el mismo request, y lo que hace
 * es forzar la invalidación via un estado compartido en $_SERVER.
 *
 * En la práctica, basta con llamar location.reload() en el frontend después
 * de crear/editar/eliminar ítems (que es lo que hace admin/listas.php), lo
 * que destruye el request y garantiza datos frescos en la próxima petición.
 *
 * @param string|null $tipo  Si es null, marca toda la caché como inválida
 */
function listas_cache_invalida(?string $tipo = null): void
{
    // Marcamos el key en $_SERVER como señal para que listas_get() lo detecte
    if ($tipo === null) {
        $_SERVER['_listas_cache_invalida_all'] = true;
    } else {
        $_SERVER['_listas_cache_invalida_' . $tipo] = true;
    }
}
