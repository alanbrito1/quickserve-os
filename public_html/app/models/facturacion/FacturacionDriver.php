<?php
/**
 * app/models/facturacion/FacturacionDriver.php — Driver de facturación por país (Fase D multi-país).
 *
 * La facturación es LOCALIZABLE: cada país tiene su sistema legal (DIAN, CFDI/SAT, SUNAT, SII…).
 * Esta interfaz separa "cómo se emite el comprobante" del resto del sistema, para que el modo
 * legal de cada país sea un driver enchufable sin tocar el POS ni el historial.
 *
 * Modo **Interno** (`FacturacionInterno`, el default, sin dependencias externas): genera un
 * comprobante/recibo propio no fiscal — funciona en cualquier país.
 * Modo **Legal**: un driver por país que integra el **proveedor certificado/PAC** correspondiente
 * (costo/contrato del negocio, normativa cambiante) — se construye por país cuando hay demanda y
 * el contrato con el PAC. Ver `app/helpers/PaisesHelper.php` → `factura_legal` para el sistema
 * objetivo de cada país.
 *
 * NominaModel/ContabilidadModel siguen el mismo patrón (estrategia/roles por país).
 */
interface FacturacionDriver
{
    /** Nombre legible del comprobante que emite este driver (ej. "Comprobante interno", "CFDI 4.0"). */
    public function nombre(): string;

    /** ¿Es facturación legal (fiscal) o solo un comprobante interno? */
    public function es_legal(): bool;

    /** País (ISO) para el que aplica este driver. */
    public function pais(): string;

    /**
     * Emite/prepara el comprobante de una venta.
     * @return array ['ok'=>bool, 'tipo'=>'interno'|'legal', 'numero'=>string, 'mensaje'=>string]
     */
    public function emitir(int $venta_id): array;
}
