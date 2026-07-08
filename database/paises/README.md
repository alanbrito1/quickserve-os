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

## Alcance y honestidad

- La **contabilidad** (este pack) es la parte menos riesgosa de localizar: los planes de
  cuentas son catálogos públicos y estandarizados. Aun así, **el mapeo definitivo debe
  revisarlo un contador del país**.
- La **nómina** (prestaciones, aportes, impuestos laborales) es **Fase C** — cada país
  exige la validación de un contador/abogado laboral local; no es solo "corre sin error",
  debe dar el neto/aportes correctos por ley.
- La **facturación electrónica legal** (CFDI/SAT, DTE/SII, DIAN, etc.) es **Fase D** —
  requiere integrar un **proveedor certificado por país** (costo/contrato del negocio).
