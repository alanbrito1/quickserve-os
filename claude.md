# ClanDestino ERP v4.0 — Memoria de Sesión

> **INSTRUCCIÓN CLAUDE:** Leer este archivo COMPLETO al inicio de CADA sesión antes de generar código.

---

## 1. CONTEXTO DEL PROYECTO

- **Sistema:** ERP + POS para negocio de sándwiches "ClanDestino"
- **Negocio:** Venta de sándwiches en Colombia. 4 productos, 3 empleados, local físico.
- **Objetivo:** Automatizar ventas, inventario, nómina, activos y costos.
- **Entorno:** Hosting compartido cPanel. PHP 8.4, MySQL. El sistema vive en un **subdirectorio**.
- **Stack:** PHP 8.4, MySQL 5.7+. Sin Composer en producción. `samesite: Lax` (no Strict).
- **Interfaz:** Mobile-First. Prioritario Android/iOS.

---

## 2. DESPLIEGUE Y RUTAS

`APP_BASE` se auto-detecta en `app/config/app.php`. Toda URL debe usar `APP_BASE`.
- PHP: `header('Location: ' . APP_BASE . '/login.php')`
- HTML: `href="<?= APP_BASE ?>/modulo/"`
- JS fetch: rutas **relativas** (ej: `fetch('api/endpoint.php')`)

**Directorio:** `C:\Users\alan_\ClanDestino\public_html\` → sube TODO esto al servidor.
Credenciales DB: `public_html/app/config/database.php`

---

## 3. NAVEGACIÓN

Un único componente: `public_html/app/views/nav.php`.
Cada página: `<?php $nav_activo = 'modulo'; include __DIR__ . '/../app/views/nav.php'; ?>`

**Sub-tabs de Nómina** (dentro de nav.php, visibles cuando `$nav_activo = 'nomina'`):
- Cada página de nómina define `$nav_sub = 'nomina'|'empleados'|'horas'|'parametros'`
- Estilo: línea roja debajo del tab activo (`subtab--act`)

**Módulos:** `ventas`, `compras`, `inventario`, `productos`, `nomina`, `activos`, `reportes`
> ⚠️ El módulo se llamaba `recetario`. **Ya fue renombrado a `productos`** en DB y código.

---

## 4. BASE DE DATOS — ESTRUCTURA ACTUAL

### Tablas principales y campos clave
```
usuarios               → permisos_modulos
configuracion_negocio  (SMLMV, aux_transporte, costos_fijos, etc.)
parametros_laborales   (% prestaciones, horas jornada, recargos — por país)
proveedores            → insumos (con categoria)  → recetas → productos
clientes               → ventas → venta_detalles
                         compras (con lugar_compra) → compra_detalles
empleados              → nomina_liquidaciones
                         registro_horas
activos
logs_historial
login_intentos
```

### Campos clave por tabla (post-migraciones 003–009)

**`ventas`:** `fecha_venta`, `fecha_pago` (NULL=pendiente), `es_combo`, `tipo_sandwich`, `metodo_pago`, `total`, `estado`

**`compras`:** `lugar_compra` (plaza, tienda, D1, Alkosto…), `proveedor_id`, `total`

**`empleados`:** `tipo_contrato` (tiempo_completo|medio_tiempo|por_horas|por_dias|por_servicio), `pais_laboral`, `horas_semana`, `periodo_horas_emp` (semana|mes|NULL), `valor_hora`, `valor_proyecto`

**`nomina_liquidaciones`:** `tipo_contrato`, `horas_trabajadas`, `horas_ordinarias`, `horas_extras`, `valor_horas_extras`, `detalle_recargos` (JSON), `pagado`, `fecha_pago_nomina`, todos los % prestacionales, `neto_pagado`

**`registro_horas`:** `empleado_id`, `fecha`, `horas`, `tipo_hora` (ordinaria|recargo_nocturno|extra_diurna|extra_nocturna|festiva_ordinaria|extra_festiva_diurna|extra_festiva_nocturna), `es_festivo`

**`activos`:** `nombre`, `numero_unidades`, `precio_unitario`, `descripcion`, `lugar_compra`, `serial`, `foto_url`, `costo_inicial`, `fecha_adquisicion`, `fecha_inicio_uso` (**depreciación desde aquí**), `garantia_hasta`, `vida_util_meses`, `depreciacion_mensual`, `depreciacion_diaria`, `estado_fisico`, `categoria_activo`, `responsable`, `notas`

**`parametros_laborales`:** `pais`, `clave`, `nombre`, `valor`, `tipo` (porcentaje|valor_fijo), `aplica_a` (empleador|empleado|ambos), `categoria` (base|carga_parafiscal|provision|descuento_empleado|tope|horas_jornada), `aplica_contratos`, `activo`

---

## 5. MÓDULO NÓMINA — DISEÑO COMPLETO

### 4 Tipos de Contrato
| Tipo | Base de cálculo | Prestaciones | Aux. Transporte |
|---|---|---|---|
| `tiempo_completo` | Salario base | Todas | Si aplica (≤ 2 SMLMV) |
| `medio_tiempo` | Salario × 50% | Todas proporcionales | Si aplica |
| `por_horas` | valor_hora × horas_trabajadas | Todas proporcionales | Si aplica |
| `por_servicio` | valor_proyecto (fijo) | **Ninguna** | No |

### Lógica de horas para contratos `por_horas`
El **valor por hora** se calcula como: `salario_base ÷ horas_mes_legales`

**Las horas legales las define el gobierno** (Ley 2101/2021), NO el empleado:
- Se configuran en **Nómina → Parámetros → Horas y Jornada** (`horas_jornada_valor` + `horas_jornada_periodo`)
- Colombia 2026: 44h/semana × 52.14/12 = **191.18 h/mes**
- Si el gobierno cambia el tope → actualizar ese parámetro → **todos los empleados por horas se recalculan automáticamente**

**`horas_semana` + `periodo_horas_emp` en `empleados`:**
- Se llena solo si este empleado específico tiene un acuerdo de horas DIFERENTE al tope legal
- Si vacío → usa el parámetro global de jornada
- `periodo_horas_emp = 'semana'`: horas_mes = horas_semana × 52.14 / 12
- `periodo_horas_emp = 'mes'`: horas_mes = horas_semana (directo)

### Recargos por tipo de hora (Art. 168-172 CST Colombia)
| Tipo | Multiplicador | Base legal |
|---|---|---|
| Ordinaria | × 1.00 | — |
| Recargo nocturno (9pm-6am) | × 1.35 | Art. 168 |
| Extra diurna | × 1.25 | Art. 168 |
| Extra nocturna | × 1.75 | Art. 168 |
| Festiva/dominical ordinaria | × 1.75 | Art. 171 |
| Extra festiva diurna | × 2.00 | Art. 172 |
| Extra festiva nocturna | × 2.50 | Art. 172 |

### Parámetros laborales (`parametros_laborales`)
- Se leen con `NominaModel::params(pais)` — cacheados por request
- Invalidar caché: `NominaModel::invalidar_cache()`
- Editables desde Nómina → Parámetros (categorías: base, carga_parafiscal, provision, descuento_empleado, tope, **horas_jornada**)
- Multi-país: agregar filas con `pais = 'X'`

### Colombia 2026 (Ley vigente)
- SMLMV: $1,750,905 | Aux. transporte: $249,095
- Jornada: 44h/semana (Ley 2101/2021) = 191.18 h/mes
- ICBF y SENA exentos si salario > 10 SMLMV

---

## 6. MÓDULO ACTIVOS — DISEÑO COMPLETO

### Campos clave
- `fecha_adquisicion` → cuándo se compró (no afecta depreciación)
- `fecha_inicio_uso` → cuándo entró en operación → **LA DEPRECIACIÓN CORRE DESDE AQUÍ**
- `numero_unidades × precio_unitario → costo_inicial` (calculado por trigger)
- `depreciacion_diaria = (costo_inicial / vida_util_meses) / 30.4` (calculado por trigger)

### Reglas de depreciación
- **Activos con `estado_vida = 'depreciado'` → dep/día = $0 → NO suman al costo operativo**
- Solo activos aún en vida útil suman al costo diario
- `valor_en_libros = costo_inicial − (dep_mensual × meses_desde_inicio_uso)`
- Estado: `en_espera` → `nuevo` → `medio` → `critico` → `depreciado`

### Fotos de activos
- Upload: `activos/api/subir_foto.php`
- **Compresión Canvas del lado del cliente** antes de enviar (corrige EXIF de móvil automáticamente)
- Guardar en `uploads/activos/` (crear carpeta manualmente en el servidor)

---

## 7. MÓDULO VENTAS/POS

### Campos de venta
- `fecha_pago` (NULL = pendiente de cobro; diferente a `fecha_venta`)
- `es_combo` (checkbox en POS)
- `tipo_sandwich` (campo libre)
- `metodo_pago`: efectivo|nequi|daviplata|bancolombia|fiado
- Fiado → incrementa `clientes.saldo_fiado`
- Cada venta descuenta insumos según receta (transacción atómica)

---

## 8. MÓDULO COMPRAS

- Cada compra actualiza `insumos.costo_actual` y `stock_actual`
- Recalcula `productos.costo_calculado` para productos afectados
- `lugar_compra` = plaza, tienda, D1, etc.
- Vista usa `historial_agrupado()` — muestra ítems y lugar por compra

---

## 9. MÓDULO PRODUCTOS (ex Recetario)

### Costo total por unidad
```
costo_total/u = costo_ingredientes (costo_calculado)
              + depreciación_diaria_total / producción_día
              + nómina_mensual / producción_mensual
              + costos_fijos_mensuales / producción_mensual
```

### Datos reales ClanDestino
| Producto | Proteína | Precio |
|---|---|---|
| El Desechado | Pollo desmechado | $18,000 |
| El Triple Golpe | 3 tipos de jamón | $18,000 |
| El Submarino | Atún | $18,000 |
| El Criollo Pesado | Carne de res | $18,000 |

---

## 10. MIGRACIONES — ORDEN DE EJECUCIÓN

```
schema.sql                    → Base inicial (14 tablas, 7 triggers)
002_login_intentos.sql        → Rate limiting
003_sprint2.sql               → Nuevas columnas ventas/activos/nómina
004_datos_reales.sql          → Productos, insumos, activos, empleados reales
004b_recalcular_costos.sql    → Recalcular costo_calculado (ejecutar tras 004)
005_activos_mejoras.sql       → foto, serial, numero_unidades, estado_fisico, etc.
006_activos_fecha_inicio.sql  → fecha_inicio_uso para depreciación correcta
  006b_fix_fechas.sql         → Solo si 006 dio "columna duplicada"
007_nomina_contratos.sql      → tipo_contrato, registro_horas, parametros_laborales
008_horas_extras.sql          → tipo_hora/es_festivo, recargos (Art.168-172), horas jornada
009_empleado_horas_contrato.sql → periodo_horas_emp en empleados
```

**Todos incluyen `USE clandestinoERP;`** — reemplazar por el nombre real de la DB.

---

## 11. DECISIONES TÉCNICAS VIGENTES

| Decisión | Razón |
|---|---|
| `samesite: Lax` (no Strict) | Compatibilidad con cPanel PHP-FPM |
| Sin `session_regenerate_id()` | Race condition en hosting compartido |
| `CAST AS SIGNED` en queries de activos | Evita overflow UNSIGNED en MySQL modo estricto |
| `step="1" min="0"` en inputs de costo | `step="1000" min="1"` rechazaba valores como 64000 |
| `fecha_inicio_uso` ≠ `fecha_adquisicion` | Depreciación desde entrada en operación, no desde compra |
| Parámetros nómina en tabla DB | Cambio de ley → actualizar fila → recálculo automático |
| Horas jornada en `parametros_laborales` | La ley define el tope (no el empleado). Cambio del gobierno → recalcula todos automáticamente |
| Categoría `horas_jornada` en parametros | Sin este valor en `$CAT_LABELS` de parametros.php → parámetros invisibles aunque existen en DB |
| `NominaModel::invalidar_cache()` | Método público para que APIs puedan invalidar el caché privado |
| Compresión Canvas antes de subir foto | Fotos de móvil 8-20MB; Canvas corrige EXIF automáticamente |
| `APP_ENV = 'production'` | Errores PHP no expuestos al usuario |

---

## 12. BUGS CONOCIDOS RESUELTOS

| Bug | Causa | Solución |
|---|---|---|
| 500 en activos | `TINYINT UNSIGNED − INT` overflow MySQL estricto | `CAST(vida_util_meses AS SIGNED)` |
| step validation (64000) | `min="1" step="1000"` → no es 1+n×1000 | `step="1" min="0"` |
| Horas extras no calculadas en liquidación | `horas_desglose` no se pasaba a `calcular()` | Construir desglose desde `horas_periodo()` antes de llamar |
| Liquidación falla si migración 008 no está | INSERT con columnas inexistentes | Detectar `tiene008` con SHOW COLUMNS, usar INSERT compatible |
| Parámetros horas_jornada invisibles | `horas_jornada` no estaba en `$CAT_LABELS` de parametros.php | Agregada la categoría |
| Botón "Marcar pagado" no existía | API existía pero nunca se llamaba | Botón + función `cambiarPago()` en index.php |
| ID `edit-tc` vs `tc-edit` incorrecto | Mismatch ID en modal editar empleado | Estandarizado a `tc-edit` en HTML y JS |
| Tipo_hora siempre "ordinaria" en UI | SELECT de horas_periodo no traía la columna | Agregado `tipo_hora` y `es_festivo` al SELECT |
| Caché parámetros no invalidada | `$paramsCache` privado → API no podía limpiarla | Método público `invalidar_cache()` |
| "horas_jornada global" texto confuso | Término técnico que confundía | Reescrito: "tope legal de horas definido por el gobierno" |

---

## 13. ESTADO DE MÓDULOS (2026-05-06)

| Módulo | Estado | Notas |
|---|---|---|
| Auth/Login | ✅ | — |
| Dashboard | ✅ | — |
| Ventas/POS | ✅ | es_combo, fecha_pago, tipo_sandwich |
| Inventario | ✅ | Ajuste stock por mermas |
| Compras | ✅ | historial_agrupado por fecha/lugar |
| Productos | ✅ | Recetas editables, costo completo, margen |
| Nómina | ✅ | 4 tipos contrato, horas+recargos, parámetros por ley |
| Activos | ✅ | foto, serial, unidades, fecha_inicio_uso, garantía |
| Reportes | ✅ | Excel multi-hoja |

---

*Última actualización: 2026-05-06 | Todas las fases completadas*
