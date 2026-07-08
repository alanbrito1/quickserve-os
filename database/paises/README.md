# Country packs — plan de cuentas por país (Fase B multi-país)

Cada archivo `<ISO>.sql` es un **country pack**: el plan de cuentas de un país mapeado
a los **roles semánticos** que usa el motor contable, más la localización (moneda e
impuesto). Es la pieza que hace que QuickServe OS opere en un país concreto sin tocar
código: el motor (`ContabilidadModel`) postea por **rol** (`caja`, `ingresos`,
`imp_ventas_por_pagar`…) y cada país mapea su propio código de cuenta a esos roles vía
`cuentas_contables.rol` + `cuentas_contables.pais` (migración 047).

## Packs disponibles

| Pack | País | Plan de cuentas | Moneda | Impuesto | Estado |
|------|------|-----------------|--------|----------|--------|
| `CO.sql` | Colombia | PUC simplificado | COP ($, 0 dec) | IVA 19% | ✅ verificado (motor + balance) |
| `MX.sql` | México | Código agrupador SAT | MXN ($, 2 dec) | IVA 16% | ⚠️ arranque — validar plan con contador MX |
| `XX.sql` | Genérico / configurable | numeración neutra | USD ($, 2 dec) | genérico | base para cualquier país nuevo |

## Cómo aplicar un pack

Un país activo por instancia (`configuracion_app.pais`). Sobre una BD ya creada con
`database/schema.sql`, ejecutar el pack del país deseado, p. ej.:

```
mysql -u USUARIO -p BASE_DE_DATOS < database/paises/MX.sql
```

El pack es **idempotente** (`INSERT IGNORE` para las cuentas, `ON DUPLICATE KEY UPDATE`
para la config): siembra las cuentas del país y fija país/moneda/impuesto. Cambiar de
país después también se puede desde **Admin → Apariencia → Localización** (el superadmin
elige el país; el motor usa las cuentas de ese país si su pack ya fue sembrado).

> El instalador web (`/install/`) cargará el pack del país elegido en una fase posterior
> (Fase E — empaquetado). Por ahora los packs se aplican manualmente (como una migración).

## Los 19 roles que todo pack debe cubrir

El motor resuelve estos roles; un pack **completo** define una cuenta para cada uno (si
falta alguno, el motor cae al código colombiano por defecto de la constante `ROLES` en
`ContabilidadModel`). El test `tests/suite.php` **G38** verifica que cada país presente
en `cuentas_contables` cubra los 19 roles.

| Rol | Significado | Tipo |
|-----|-------------|------|
| `caja` | Efectivo | Activo |
| `bancos` | Bancos / transferencias | Activo |
| `cxc_fiado` | Cuentas por cobrar (fiado) | Activo |
| `inv_terminado` | Inventario de producto terminado | Activo |
| `inv_insumos` | Inventario de insumos / materia prima | Activo |
| `imp_descontable` | Impuesto de ventas a favor (IVA descontable/acreditable) | Activo |
| `activos_fijos` | Activos fijos | Activo |
| `deprec_acumulada` | Depreciación acumulada (contra-activo) | Activo (contra) |
| `proveedores_por_pagar` | Proveedores por pagar | Pasivo |
| `imp_ventas_por_pagar` | Impuesto de ventas por pagar (IVA por pagar/trasladado) | Pasivo |
| `nomina_por_pagar` | Nómina / sueldos por pagar | Pasivo |
| `capital` | Capital social | Patrimonio |
| `utilidad` | Utilidad / resultado del ejercicio | Patrimonio |
| `ingresos` | Ingresos por ventas | Ingreso |
| `costo_ventas` | Costo de ventas | Costo |
| `gasto_nomina` | Gasto de nómina | Gasto |
| `gasto_depreciacion` | Gasto por depreciación | Gasto |
| `gastos_operativos` | Gastos operativos / indirectos | Gasto |
| `obsequios_mermas` | Obsequios y mermas | Gasto |

## Cómo agregar un país nuevo

1. Copiar `XX.sql` a `<ISO>.sql` (p. ej. `PE.sql`, `CL.sql`, `ES.sql`).
2. Reemplazar los códigos/nombres de cuenta por el plan de cuentas oficial del país
   (PCGE en Perú, plan del SII en Chile, PGC en España, etc.), conservando **la columna
   `rol` y `pais`** exactamente. No omitir ningún rol de la tabla de arriba.
3. Ajustar la localización (`moneda_codigo`, `moneda_simbolo`, `moneda_decimales`,
   `impuesto_nombre`, `iva_tarifa`).
4. Validar con el harness contable (postear los 6 flujos → asientos y Balance cuadran) y
   correr `tests/suite.php` G38.

## Facturación electrónica legal por país (objetivo de integración — Fase D)

El catálogo `app/helpers/PaisesHelper.php` guarda, por país, el **sistema de facturación
electrónica legal representativo** — el objetivo de integración del modo "Legal" (Fase D).
Hoy el sistema opera en modo **Interno** (comprobante propio, sin dependencias externas); el
modo Legal integra un **proveedor certificado/PAC** por país (costo y contrato del negocio).

| País | Sistema legal representativo |
|------|------------------------------|
| Colombia (CO) | **DIAN** — Factura Electrónica (proveedor tecnológico autorizado) |
| México (MX) | **SAT** — CFDI 4.0 (vía **PAC**: Proveedor Autorizado de Certificación) |
| Perú (PE) | **SUNAT** — Comprobante de Pago Electrónico (OSE/PSE) |
| Chile (CL) | **SII** — Documento Tributario Electrónico (DTE) |
| España (ES) | **AEAT** — Veri*Factu / SII (TicketBAI en el País Vasco) |
| Panamá (PA) | **DGI** — Factura Electrónica de Panamá (PAC) |
| Ecuador (EC) | **SRI** — Comprobantes Electrónicos |
| Argentina (AR) | **AFIP/ARCA** — Factura Electrónica (CAE) |
| Brasil (BR) | **SEFAZ** — NF-e / NFC-e (SPED) — el más complejo; varía por estado/municipio |
| Paraguay (PY) | **DNIT** — SIFEN (Factura Electrónica Nacional) |
| Uruguay (UY) | **DGI** — Comprobante Fiscal Electrónico (CFE) |

Estos nombres se muestran como **alerta de consideraciones** cuando el superadmin elige el
país en Admin → Apariencia → Localización, y cuando se elige el país en el instalador.

## Alcance y honestidad

- La **contabilidad** (este pack) es la parte menos riesgosa de localizar: los planes de
  cuentas son catálogos públicos y estandarizados. Aun así, **el mapeo definitivo debe
  revisarlo un contador del país**.
- La **nómina** (prestaciones, aportes, impuestos laborales) es **Fase C** — hoy solo
  Colombia tiene cálculo validado; los demás países usan el motor colombiano por fallback
  (NO válido legalmente) hasta tener su `PayrollStrategy` propia, que exige la validación de
  un contador/abogado laboral local (no basta "corre sin error": debe dar el neto/aportes
  correctos por ley).
- La **facturación electrónica legal** (tabla de arriba) es **Fase D** — requiere integrar un
  **proveedor certificado por país** (costo/contrato del negocio); **Brasil es el más costoso**.

## El instalador elige el país

El instalador web (`/install/`) incluye un **selector de país** en el paso "Negocio": al
instalar aplica el country pack del país elegido (o fija su localización desde
`PaisesHelper` si aún no tiene pack dedicado) y muestra la alerta de consideraciones. Para
mantenerlo funcionando en el servidor (donde solo se sube `public_html/`), los packs se
copian en `public_html/install/sql/paises/` — **mantener esas copias sincronizadas** con
`database/paises/`.
