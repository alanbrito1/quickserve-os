<?php
/**
 * app/helpers/FormatoHelper.php
 * Formato numérico configurable desde Admin > Apariencia (tabla configuracion_app,
 * claves num_decimales/num_sep_miles/num_sep_decimal — migración 040).
 *
 * USO:
 *   fmt_cantidad(1234.5)     -> "1.234,50"  (decimales y separadores configurables)
 *   fmt_cantidad(1234.567,3) -> "1.234,567" (decimales explícitos, ignora config)
 *   fmt_moneda(1234.5)       -> "1.235"     (siempre 0 decimales, separadores configurables)
 */

/**
 * Lee la configuración de formato numérico (cacheada por request).
 * Defaults si la tabla/claves no existen: 2 decimales, punto=miles, coma=decimal (es-CO).
 */
function config_numeros(): array
{
    static $cfg = null;
    if ($cfg !== null) return $cfg;

    $cfg = ['decimales' => 2, 'sep_miles' => '.', 'sep_decimal' => ','];
    try {
        $rows = db()->query(
            "SELECT clave, valor FROM configuracion_app
             WHERE clave IN ('num_decimales','num_sep_miles','num_sep_decimal')"
        )->fetchAll(PDO::FETCH_KEY_PAIR);

        if (isset($rows['num_decimales']))   $cfg['decimales']   = max(0, min(4, (int)$rows['num_decimales']));
        if (isset($rows['num_sep_miles']))   $cfg['sep_miles']   = $rows['num_sep_miles'];
        if (isset($rows['num_sep_decimal'])) $cfg['sep_decimal'] = $rows['num_sep_decimal'];
    } catch (Exception $e) { /* valores por defecto si la tabla no existe aún */ }

    return $cfg;
}

/**
 * Formatea una cantidad (stock, presentaciones, equivalencias, costo por unidad).
 * Decimales configurables vía Admin > Apariencia; $dec sobreescribe si se indica.
 *
 * @param float    $n   Valor a formatear
 * @param int|null $dec Número de decimales (null = usar configuración global)
 */
function fmt_cantidad(float $n, ?int $dec = null): string
{
    $cfg = config_numeros();
    $dec = $dec ?? $cfg['decimales'];
    return number_format($n, $dec, $cfg['sep_decimal'], $cfg['sep_miles']);
}

/**
 * Formatea un monto en pesos. Siempre 0 decimales (invariante de precios);
 * separadores configurables vía Admin > Apariencia.
 */
function fmt_moneda(float $n): string
{
    $cfg = config_numeros();
    return number_format($n, 0, $cfg['sep_decimal'], $cfg['sep_miles']);
}
