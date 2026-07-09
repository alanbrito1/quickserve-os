<?php
/**
 * app/models/facturacion/FacturacionModel.php — Subsistema de facturación (Fase D multi-país).
 *
 * Enruta el modo de facturación (interno | legal) al driver correspondiente y ensambla los datos
 * del comprobante de una venta (usados por `ventas/comprobante.php`). Hoy solo existe el modo
 * **Interno** (`FacturacionInterno`); el modo **Legal** requiere integrar el PAC/proveedor
 * certificado del país (Fase D por país) — mientras no exista, 'legal' cae a Interno y el
 * comprobante avisa qué sistema legal habría que integrar (de `PaisesHelper::factura_legal`).
 */

require_once __DIR__ . '/FacturacionInterno.php';
require_once __DIR__ . '/../../helpers/PaisesHelper.php';
require_once __DIR__ . '/../ContabilidadModel.php';

class FacturacionModel
{
    /** Modo de facturación configurado ('interno' | 'legal'), cacheado. */
    public static function modo(): string
    {
        static $m = null;
        if ($m === null) {
            $m = 'interno';
            try {
                $v = db()->query("SELECT valor FROM configuracion_app WHERE clave='factura_modo' LIMIT 1")->fetchColumn();
                if ($v) $m = strtolower(trim((string)$v));
            } catch (\Throwable $e) {}
            if ($m !== 'legal') $m = 'interno';
        }
        return $m;
    }

    /** País operativo (ISO), cacheado. */
    public static function pais(): string
    {
        static $p = null;
        if ($p === null) {
            $p = 'CO';
            try {
                $v = db()->query("SELECT valor FROM configuracion_app WHERE clave='pais' LIMIT 1")->fetchColumn();
                if ($v) $p = strtoupper(trim((string)$v));
            } catch (\Throwable $e) {}
        }
        return $p;
    }

    /**
     * Driver de facturación activo. Cuando exista un driver legal por país, aquí se enruta por
     * `pais()` + `modo()`. Hoy solo hay Interno; 'legal' sin PAC integrado usa Interno.
     */
    public static function driver(): FacturacionDriver
    {
        return new FacturacionInterno(self::pais());
    }

    /** ¿El modo legal está integrado (PAC contratado) para el país activo? Hoy: nunca. */
    public static function legal_disponible(): bool
    {
        return false;
    }

    /** Sistema de facturación legal representativo del país activo (para el aviso). */
    public static function sistema_legal(): string
    {
        $m = function_exists('pais_meta') ? pais_meta(self::pais()) : null;
        return $m['factura_legal'] ?? '';
    }

    /** Ensambla los datos del comprobante de una venta (o null si no existe). */
    public static function datos_comprobante(int $venta_id): ?array
    {
        $st = db()->prepare("SELECT * FROM ventas WHERE id = ?");
        $st->execute([$venta_id]);
        $v = $st->fetch();
        if (!$v) return null;

        $it = db()->prepare(
            "SELECT COALESCE(vd.nombre_snap, p.nombre, '—') AS nombre,
                    vd.cantidad, vd.precio_unitario, vd.subtotal
             FROM venta_detalles vd
             LEFT JOIN productos p ON p.id = vd.producto_id
             WHERE vd.venta_id = ? ORDER BY vd.id"
        );
        $it->execute([$venta_id]);
        $items = $it->fetchAll();

        $cliente = null;
        if (!empty($v['cliente_id'])) {
            $c = db()->prepare("SELECT nombre, apellido, empresa, telefono FROM clientes WHERE id = ?");
            $c->execute([(int)$v['cliente_id']]);
            $cliente = $c->fetch() ?: null;
        }

        $meta = function_exists('pais_meta') ? (pais_meta(self::pais()) ?? []) : [];
        $imp  = ContabilidadModel::impuestoVentas(); // ['activo','tarifa','nombre']

        // Si el impuesto de ventas está activo, el total INCLUYE impuesto → se separa base + impuesto.
        $total = (float)$v['total'];
        $base = $total; $valorImp = 0.0;
        if (!empty($imp['activo']) && (float)($imp['tarifa'] ?? 0) > 0) {
            $tarifa = (float)$imp['tarifa'];
            $base = round($total / (1 + $tarifa / 100), 2);
            $valorImp = round($total - $base, 2);
        }

        return [
            'numero'          => FacturacionInterno::numero($venta_id),
            'tipo'            => self::driver()->es_legal() ? 'legal' : 'interno',
            'modo'            => self::modo(),
            'legal_pendiente' => (self::modo() === 'legal' && !self::legal_disponible()),
            'sistema_legal'   => self::sistema_legal(),
            'negocio'         => function_exists('nombre_negocio') ? nombre_negocio() : 'Mi Negocio',
            'pais'            => self::pais(),
            'pais_nombre'     => $meta['nombre'] ?? self::pais(),
            'venta'           => $v,
            'items'           => $items,
            'cliente'         => $cliente,
            'impuesto'        => [
                'activo' => !empty($imp['activo']),
                'nombre' => $imp['nombre'] ?? 'IVA',
                'tarifa' => (float)($imp['tarifa'] ?? 0),
                'base'   => $base,
                'valor'  => $valorImp,
            ],
        ];
    }
}
