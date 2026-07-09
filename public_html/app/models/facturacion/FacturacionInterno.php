<?php
/**
 * app/models/facturacion/FacturacionInterno.php — Comprobante interno (no fiscal).
 *
 * El modo por defecto y universal: genera un recibo/comprobante propio a partir de la venta,
 * SIN dependencias externas ni llamadas de red. El número es derivado y estable (INT-<id>),
 * no requiere una tabla ni un consecutivo fiscal. Sirve en cualquier país.
 *
 * El modo LEGAL (factura electrónica del país, vía PAC certificado) será un driver aparte por
 * país — este driver es su reemplazo seguro mientras no esté integrado.
 */

require_once __DIR__ . '/FacturacionDriver.php';

class FacturacionInterno implements FacturacionDriver
{
    private string $pais;

    public function __construct(string $pais = 'CO')
    {
        $this->pais = $pais !== '' ? strtoupper($pais) : 'CO';
    }

    public function nombre(): string { return 'Comprobante interno'; }
    public function es_legal(): bool { return false; }
    public function pais(): string   { return $this->pais; }

    /** Número de comprobante interno derivado (estable, sin consecutivo fiscal). */
    public static function numero(int $venta_id): string
    {
        return 'INT-' . str_pad((string)$venta_id, 6, '0', STR_PAD_LEFT);
    }

    public function emitir(int $venta_id): array
    {
        return [
            'ok'      => $venta_id > 0,
            'tipo'    => 'interno',
            'numero'  => self::numero($venta_id),
            'mensaje' => 'Comprobante interno (no fiscal).',
        ];
    }
}
