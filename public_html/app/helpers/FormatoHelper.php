<?php
/**
 * app/helpers/FormatoHelper.php
 * Formato numérico estándar del proyecto (es-CO): punto para miles, coma para decimales.
 *
 * USO:
 *   fmt_cantidad(1234.5)     -> "1.234,50"
 *   fmt_cantidad(1234.567,3) -> "1.234,567"
 */

/**
 * Formatea una cantidad numérica con separador de miles (.) y decimales (,).
 *
 * @param float $n   Valor a formatear
 * @param int   $dec Número de decimales (por defecto 2)
 */
function fmt_cantidad(float $n, int $dec = 2): string
{
    return number_format($n, $dec, ',', '.');
}
