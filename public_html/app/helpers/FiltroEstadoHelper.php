<?php
/**
 * app/helpers/FiltroEstadoHelper.php
 * Filtro "ver inactivos/anulados" por módulo, SOLO para admin/superadmin.
 *
 * Patrón: cada listado lee `?ver=` (activos|inactivos|todos) y aplica el WHERE
 * correspondiente solo si el usuario es admin/superadmin; el resto siempre ve
 * "activos". El selector se pinta solo para admin.
 *
 * Uso típico en un listado:
 *   $ver = filtro_estado_actual();
 *   $sql = "SELECT ... FROM productos p WHERE 1=1" . filtro_estado_sql($ver, 'activo', 'activo', 'p');
 *   ... y en la barra de filtros:  echo filtro_estado_ui($ver);
 */

if (!function_exists('filtro_estado_es_admin')) {

    /** ¿El usuario actual es admin/superadmin? (único que ve inactivos/anulados) */
    function filtro_estado_es_admin(): bool
    {
        return in_array($_SESSION['usuario_rol'] ?? '', ['admin', 'superadmin'], true);
    }

    /** Valor actual del filtro, forzado a 'activos' para no-admin. */
    function filtro_estado_actual(): string
    {
        if (!filtro_estado_es_admin()) return 'activos';
        $v = $_GET['ver'] ?? 'activos';
        return in_array($v, ['activos', 'inactivos', 'todos'], true) ? $v : 'activos';
    }

    /**
     * Fragmento WHERE (con " AND " al inicio, o '' para "todos").
     *  $tipo='activo' → col 0/1 ; $tipo='estado' → col vs $baja (ej. 'anulada').
     *  $alias = alias de la tabla (ej. 'p') o '' si la columna va sin prefijo.
     */
    function filtro_estado_sql(string $ver, string $col, string $tipo = 'activo', string $alias = '', string $baja = 'anulada'): string
    {
        if ($ver === 'todos') return '';
        $c = ($alias !== '' ? $alias . '.' : '') . $col;
        if ($tipo === 'estado') {
            $q = "'" . str_replace("'", '', $baja) . "'"; // valor del mapa, sin comillas externas
            return $ver === 'activos' ? " AND $c <> $q" : " AND $c = $q";
        }
        return $ver === 'activos' ? " AND $c = 1" : " AND $c = 0";
    }

    /**
     * Selector HTML (solo se pinta para admin). $tipo='estado' usa la etiqueta
     * "Anulados" en vez de "Inactivos". Preserva los demás parámetros de la URL.
     */
    function filtro_estado_ui(string $actual, string $tipo = 'activo'): string
    {
        if (!filtro_estado_es_admin()) return '';
        $inact = $tipo === 'estado' ? 'Anulados' : 'Inactivos';
        $opts  = ['activos' => 'Solo activos', 'inactivos' => 'Solo ' . strtolower($inact), 'todos' => 'Todos'];
        $o = '';
        foreach ($opts as $v => $l) {
            $o .= '<option value="' . $v . '"' . ($v === $actual ? ' selected' : '') . '>' . $l . '</option>';
        }
        return '<select title="Ver registros (solo admin)" '
            . 'onchange="var p=new URLSearchParams(location.search);this.value===\'activos\'?p.delete(\'ver\'):p.set(\'ver\',this.value);var q=p.toString();location.assign(location.pathname+(q?\'?\'+q:\'\'));" '
            . 'style="padding:7px 10px;border:1px solid var(--g8,#d1d5db);border-radius:8px;font-size:13px;background:#fff">'
            . $o . '</select>';
    }
}
