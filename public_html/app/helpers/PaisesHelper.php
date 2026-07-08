<?php
/**
 * app/helpers/PaisesHelper.php — Catálogo de países soportados (multi-país, Fase C/E).
 *
 * Fuente ÚNICA de metadatos por país: moneda/impuesto por defecto, estado de cada capa
 * localizable (contabilidad / nómina) y el sistema de facturación electrónica LEGAL
 * representativo del país (el objetivo de integración de la Fase D).
 *
 * Lo consumen: Admin → Apariencia → Localización (autocompleta moneda/impuesto y muestra
 * una alerta de consideraciones al elegir el país) y el instalador web (elige el país →
 * carga su plan de cuentas + localización). Sin dependencias: función pura.
 *
 * Estados honestos por país:
 *   contabilidad: 'pack'     → tiene plan de cuentas propio en database/paises/<ISO>.sql
 *                 'generico' → usa un plan genérico/colombiano por fallback (ajustar al oficial)
 *   nomina:       'validada' → cálculo laboral propio y verificado (hoy solo Colombia)
 *                 'fallback' → usa el motor colombiano por defecto; NO válido legalmente para
 *                              ese país hasta tener una estrategia local validada por un contador
 */

/** @return array<string,array> ISO → metadatos del país */
function paises_meta(): array
{
    return [
        'CO' => ['nombre'=>'Colombia','moneda_codigo'=>'COP','moneda_simbolo'=>'$','moneda_decimales'=>0,
                 'impuesto_nombre'=>'IVA','impuesto_tarifa'=>19,'contabilidad'=>'pack','nomina'=>'validada',
                 'factura_legal'=>'DIAN — Factura Electrónica (proveedor tecnológico autorizado)'],
        'MX' => ['nombre'=>'México','moneda_codigo'=>'MXN','moneda_simbolo'=>'$','moneda_decimales'=>2,
                 'impuesto_nombre'=>'IVA','impuesto_tarifa'=>16,'contabilidad'=>'pack','nomina'=>'fallback',
                 'factura_legal'=>'SAT — CFDI 4.0 (vía PAC: Proveedor Autorizado de Certificación)'],
        'PE' => ['nombre'=>'Perú','moneda_codigo'=>'PEN','moneda_simbolo'=>'S/','moneda_decimales'=>2,
                 'impuesto_nombre'=>'IGV','impuesto_tarifa'=>18,'contabilidad'=>'generico','nomina'=>'fallback',
                 'factura_legal'=>'SUNAT — Comprobante de Pago Electrónico (OSE/PSE)'],
        'CL' => ['nombre'=>'Chile','moneda_codigo'=>'CLP','moneda_simbolo'=>'$','moneda_decimales'=>0,
                 'impuesto_nombre'=>'IVA','impuesto_tarifa'=>19,'contabilidad'=>'generico','nomina'=>'fallback',
                 'factura_legal'=>'SII — Documento Tributario Electrónico (DTE)'],
        'ES' => ['nombre'=>'España','moneda_codigo'=>'EUR','moneda_simbolo'=>'€','moneda_decimales'=>2,
                 'impuesto_nombre'=>'IVA','impuesto_tarifa'=>21,'contabilidad'=>'generico','nomina'=>'fallback',
                 'factura_legal'=>'AEAT — Veri*Factu / SII (TicketBAI en el País Vasco)'],
        'PA' => ['nombre'=>'Panamá','moneda_codigo'=>'USD','moneda_simbolo'=>'$','moneda_decimales'=>2,
                 'impuesto_nombre'=>'ITBMS','impuesto_tarifa'=>7,'contabilidad'=>'generico','nomina'=>'fallback',
                 'factura_legal'=>'DGI — Factura Electrónica de Panamá (PAC)'],
        'EC' => ['nombre'=>'Ecuador','moneda_codigo'=>'USD','moneda_simbolo'=>'$','moneda_decimales'=>2,
                 'impuesto_nombre'=>'IVA','impuesto_tarifa'=>15,'contabilidad'=>'generico','nomina'=>'fallback',
                 'factura_legal'=>'SRI — Comprobantes Electrónicos'],
        'AR' => ['nombre'=>'Argentina','moneda_codigo'=>'ARS','moneda_simbolo'=>'$','moneda_decimales'=>2,
                 'impuesto_nombre'=>'IVA','impuesto_tarifa'=>21,'contabilidad'=>'generico','nomina'=>'fallback',
                 'factura_legal'=>'AFIP/ARCA — Factura Electrónica (CAE)'],
        'BR' => ['nombre'=>'Brasil','moneda_codigo'=>'BRL','moneda_simbolo'=>'R$','moneda_decimales'=>2,
                 'impuesto_nombre'=>'ICMS/ISS','impuesto_tarifa'=>17,'contabilidad'=>'generico','nomina'=>'fallback',
                 'factura_legal'=>'SEFAZ — NF-e / NFC-e (SPED) — el más complejo; varía por estado/municipio'],
        'PY' => ['nombre'=>'Paraguay','moneda_codigo'=>'PYG','moneda_simbolo'=>'₲','moneda_decimales'=>0,
                 'impuesto_nombre'=>'IVA','impuesto_tarifa'=>10,'contabilidad'=>'generico','nomina'=>'fallback',
                 'factura_legal'=>'DNIT — SIFEN (Factura Electrónica Nacional)'],
        'UY' => ['nombre'=>'Uruguay','moneda_codigo'=>'UYU','moneda_simbolo'=>'$','moneda_decimales'=>2,
                 'impuesto_nombre'=>'IVA','impuesto_tarifa'=>22,'contabilidad'=>'generico','nomina'=>'fallback',
                 'factura_legal'=>'DGI — Comprobante Fiscal Electrónico (CFE)'],
        'XX' => ['nombre'=>'Genérico / configurable','moneda_codigo'=>'USD','moneda_simbolo'=>'$','moneda_decimales'=>2,
                 'impuesto_nombre'=>'Impuesto','impuesto_tarifa'=>0,'contabilidad'=>'generico','nomina'=>'fallback',
                 'factura_legal'=>'— (solo comprobante interno; sin sistema legal de un país específico)'],
    ];
}

/** Metadatos de un país por ISO (o null si no está en el catálogo). */
function pais_meta(string $iso): ?array
{
    return paises_meta()[strtoupper(trim($iso))] ?? null;
}
