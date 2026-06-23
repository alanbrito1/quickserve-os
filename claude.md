# ClanDestino ERP v5.0 — Memoria de Sesión
# Última sesión: 2026-06-22 | Próxima sesión: continuar desde este punto

> **INSTRUCCIÓN CLAUDE:** Leer este archivo COMPLETO al inicio de CADA sesión antes de generar código.

---

## 0. CONTROL DE VERSIONES — INSTRUCCIÓN OBLIGATORIA

> **⚠️ REGLA CRÍTICA:** Hacer commit y push a GitHub después de cada bloque significativo de trabajo.
> **NO** acumular todos los cambios de una sesión en un solo commit al final.

### Cuándo hacer commit
| Evento | Frecuencia |
|--------|-----------|
| Módulo nuevo completado | Inmediatamente al terminar |
| Migración SQL creada | Con cada migración |
| Bloque de bugs corregidos (3+) | Al terminar el bloque |
| Cambio de versión (v4.x → v4.y) | Siempre |
| Final de cada sesión de trabajo | Obligatorio |

### Flujo estándar
```bash
cd "C:\Users\alan_\ClanDestino"
git add -A -- "public_html/" "database/migrations/" "claude.md"
git commit -m "feat/fix/docs: descripción clara del cambio — v4.XX"
git push origin master
```

### Prefijos de commit
- `feat:` — módulo nuevo o funcionalidad nueva
- `fix:` — corrección de bug
- `refactor:` — cambio de código sin nueva funcionalidad
- `docs:` — solo CLAUDE.md, ayuda o README
- `chore:` — migraciones, configuración, versión

### Credenciales Git
El token de GitHub está guardado en el archivo de memoria privada de Claude
(`C:\Users\alan_\.claude\projects\...\memory\github_token.md`).
NO guardar tokens en este archivo — está en el repositorio y sería público.
Las credenciales de git están configuradas en Windows Credential Manager.

---

## 1. CONTEXTO DEL PROYECTO

- **Sistema:** ERP + POS para negocio de sándwiches "ClanDestino"
- **Negocio:** Venta de sándwiches en Colombia. 4 productos, 3 empleados, local físico.
- **Stack:** PHP 8.4, MySQL 5.7+. Sin Composer en producción. `samesite: Lax` (no Strict).
- **Entorno:** Hosting compartido cPanel. El sistema vive en un **subdirectorio**.
- **Interfaz:** Mobile-First. Prioritario Android/iOS.
- **GitHub:** https://github.com/alanbrito1/clandestino-erp.git (privado)
- **Superadmin:** `admin@clandestino.local` / `Admin2026!`

---

## 2. DESPLIEGUE Y RUTAS

`APP_BASE` se auto-detecta en `app/config/app.php`. Toda URL debe usar `APP_BASE`.
- PHP: `header('Location: ' . APP_BASE . '/login.php')`
- HTML: `href="<?= APP_BASE ?>/modulo/"`
- JS fetch: rutas **relativas** desde la página actual
- **Directorio local:** `C:\Users\alan_\ClanDestino\public_html\` → sube TODO esto al servidor.
- **Base de datos local:** `C:\Users\alan_\ClanDestino\database\` — schema.sql + migraciones en `database/migrations/`
- **Credenciales DB:** `public_html/app/config/database.php`

### Directorios en servidor (crear si no existen)
```
public_html/uploads/activos/   → fotos de activos
public_html/uploads/logos/     → logos del negocio (Admin → Apariencia)
```
Ambos tienen `.htaccess` que bloquea ejecución de PHP.

---

## 3. NAVEGACIÓN

Componente único: `public_html/app/views/nav.php`.

### Regla de inclusión
```php
<?php $nav_activo = 'modulo'; $nav_sub = ''; include __DIR__ . '/../app/views/nav.php'; ?>
```
**⚠️ Dashboard** usa `include __DIR__ . '/app/views/nav.php'` (sin `/../`).

### Orden de tabs
ventas → **clientes** → inventario → proveedores → compras → productos → nomina → activos → costos → reportes → **Admin** (superadmin/admin) → **Ayuda** (todos)

### Sub-tabs disponibles
| `$nav_activo` | Sub-tabs (`$nav_sub`) |
|---|---|
| `nomina` | `nomina`, `empleados`, `horas`, `parametros` |
| `admin` | `resumen`, `usuarios`, `apariencia`, `listas`, `backup`, `mantenimiento` (solo superadmin) |

### Menú hamburguesa móvil
- **≤ 640px:** tabs horizontales ocultos → botón ☰ visible → drawer vertical con todos los módulos
- **≥ 641px:** comportamiento desktop normal
- El drawer incluye sub-secciones (Nómina/Admin) si `$nav_activo` corresponde
- Se cierra con Escape, clic en overlay, o rotar a landscape

### Tema dinámico (nav.php inyecta CSS global)
nav.php carga de `configuracion_app` y emite `<style>` con todas las variables. Sobreescribe los `:root` de cada página.

Variables inyectadas:
```css
:root {
    --brand, --dark,
    --fs-title, --fs-subtitle, --fs-body, --fs-small,
    --color-text, --color-text-sec
}
body { font-family: [theme_font]; }
h1, .page-title { font-family: [font_heading]; font-size: var(--fs-title); }
/* + reglas para .card-title, td, label, .badge, .kpi-val etc. */
```

### Logos
- `logo_url` → logo horizontal para la barra de navegación (nav.php)
- `logo_url_login` → logo vertical/cuadrado para la pantalla de login
- Fallback en nav.php: si `logo_url` vacío, usa `logo_url_login`; si ambos vacíos, muestra texto

---

## 4. BASE DE DATOS — ESTRUCTURA COMPLETA

### Tablas y relaciones
```
usuarios               → permisos_modulos (tabla separada, NO JSON)
configuracion_negocio  → valores numéricos (DECIMAL): SMLMV, costos, producción
configuracion_app      → valores de texto: tema, logos, tipografía
proveedores            → insumos → recetas → productos
                         activos (proveedor_id FK)
                         compras (proveedor_id FK)
                         insumos → insumo_presentaciones (catálogo de empaques de compra, migración 039)
                         productos → producto_variantes (tamaños/variantes, migración 035)
clientes               → ventas → venta_detalles   (módulo clientes: clientes/index.php)
                         pagos_fiado (abonos de deuda fiado)
compras                → compra_detalles
empleados              → nomina_liquidaciones
                         registro_horas
activos
costos_indirectos
produccion_lotes
combo_configs          → combo_insumos (migración 025)
ajustes_stock          ← productos (sin FK en BD — ver nota migración 026)
turnos_caja            → apertura/cierre de caja (migración 037)
listas_sistema         → catálogos configurables (Admin → Catálogos, migración 029)
logs_historial
login_intentos
```

### Tabla `ajustes_stock` (migración 026) — SIN FK EN BD
```sql
CREATE TABLE ajustes_stock (
    id INT AUTO_INCREMENT PRIMARY KEY,
    producto_id INT NOT NULL,
    cantidad    INT NOT NULL,
    tipo        ENUM('obsequio','desecho') NOT NULL,
    motivo      VARCHAR(300) DEFAULT NULL,
    fecha_ajuste DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by  INT DEFAULT NULL,
    INDEX idx_as_producto (producto_id),
    INDEX idx_as_fecha (fecha_ajuste)
    -- SIN FOREIGN KEY: InnoDB en cPanel compartido lanza errno 121
    -- (nombre de constraint duplicado en la BD) al intentar crearlas.
    -- La integridad referencial la garantiza ajuste_stock.php mediante
    -- SELECT ... FOR UPDATE antes del INSERT.
) ENGINE=InnoDB;
```

### Tabla `configuracion_app`
```sql
(clave VARCHAR(100) PK, valor TEXT, updated_by)
```
Claves completas (post-migración 040):

| Clave | Default | Descripción |
|---|---|---|
| `nombre_negocio` | ClanDestino | Nombre en menú y páginas |
| `logo_url` | '' | Logo horizontal (nav) |
| `logo_url_login` | '' | Logo vertical (login) |
| `theme_brand` | #e94f37 | Color principal |
| `theme_dark` | #111827 | Fondo del menú |
| `theme_font` | system-ui... | Fuente del cuerpo |
| `font_heading` | system-ui... | Fuente de encabezados |
| `theme_radius` | 12 | Radio de bordes (px) |
| `font_size_title` | 22 | Títulos h1, KPI grandes (px) |
| `font_size_subtitle` | 15 | Subtítulos, tarjetas (px) |
| `font_size_body` | 13 | Texto general, tablas (px) |
| `font_size_small` | 11 | Labels, encabezados tabla (px) |
| `color_text` | #111827 | Color texto principal |
| `color_text_sec` | #6b7280 | Color texto secundario |
| `num_decimales` | 2 | Decimales para cantidades (stock, presentaciones, equivalencias, costo/u) — migración 040 |
| `num_sep_miles` | . | Separador de miles para todos los números — migración 040 |
| `num_sep_decimal` | , | Separador decimal para todos los números — migración 040 |
| `num_sep_millones` | . | Separador del grupo de millones (y superiores); si = `num_sep_miles`, formato uniforme — migración 041 |

### Tabla `permisos_modulos`
```sql
(usuario_id, modulo ENUM, nivel_acceso ENUM) UNIQUE KEY (usuario_id, modulo)
```
Módulos ENUM: `ventas, compras, inventario, nomina, productos, activos, reportes, proveedores, costos`
Niveles: `sin_acceso(0) → solo_ver(1) → solo_propios(2) → editar_existentes(3) → admin_total(4)`

> Admin y Ayuda NO están en permisos_modulos — se controlan por `$_SESSION['usuario_rol']` directamente.

### Tabla `usuarios`
`nombre`, `email` (UNIQUE), `password_hash` (bcrypt cost=12), `rol` ENUM(superadmin|admin|empleado), `activo`

### Tabla `registro_horas` (migración 007)
También tiene: `aprobado TINYINT(1) DEFAULT 0`

### Tabla `parametros_laborales` (migración 007)
También tiene: `nombre VARCHAR(200) NOT NULL`, `orden TINYINT UNSIGNED DEFAULT 50`
ENUM `tipo` en producción: `('porcentaje','valor_fijo')` — no `'monto_fijo'` ni `'factor'`

### Tabla `nomina_liquidaciones` — columnas completas (mig. 003 + 007 + 008 + 033)
```
tipo_contrato, descripcion_pago, valor_hora_snap*, valor_proyecto_snap*,
horas_trabajadas, horas_ordinarias, horas_extras, valor_horas_extras, detalle_recargos,
salario_base, aux_transporte, salud_empleador, pension_empleador, arl,
caja_compensacion, icbf, sena, total_cargas,
prima, cesantias, intereses_cesantias, vacaciones, total_provisiones,
salud_empleado, pension_empleado, neto_pagado,
costo_total_empleador, pagado, fecha_pago_nomina
```
`*` = snapshots agregados por migración 033. NULL en liquidaciones anteriores.

### Tabla `clientes` (migración 028)
`id`, `nombre`, `apellido` (nullable), `empresa` (nullable), `telefono` (nullable), `saldo_fiado` (DECIMAL, default 0), `activo`, `created_by`, `updated_by`

### Tabla `venta_detalles` — columnas completas (mig. base + 003 + 025 + 034)
| Columna | Tipo | Descripción |
|---------|------|-------------|
| `precio_unitario` | DECIMAL | **INMUTABLE** — precio al momento de la venta |
| `precio_lista` | DECIMAL | Precio sugerido del catálogo al vender (mig. 003) |
| `nombre_snap` | VARCHAR(200) | **INMUTABLE** — nombre del producto al vender (mig. 034) |
| `nombre2_snap` | VARCHAR(120) | **INMUTABLE** — subtítulo al vender (mig. 034) |
| `from_stock` | TINYINT | 1=del stock terminado, 0=insumos directos |
| `es_combo` | TINYINT | 1=vendido como combo |
| `combo_id` | INT FK | NULL en ventas anteriores a migración 025 |

### Tabla `compra_detalles` — columnas completas (mig. base + 032 + 034)
| Columna | Tipo | Descripción |
|---------|------|-------------|
| `precio_unitario` | DECIMAL | **INMUTABLE** — precio/unidad básica al comprar |
| `nombre_snap` | VARCHAR(200) | **INMUTABLE** — nombre del insumo al comprar (mig. 034) |
| `unidad_snap` | VARCHAR(20) | **INMUTABLE** — unidad de medida al comprar (mig. 034) |
| `presentacion` | VARCHAR(30) | Tipo de empaque (paca, frasco...) (mig. 032) |
| `cantidad_presentacion` | DECIMAL | Unidades básicas por empaque (mig. 032) |
| `cant_presentaciones` | DECIMAL | Cuántos empaques se compraron (mig. 032) |
| `precio_presentacion` | DECIMAL | **INMUTABLE** — precio pagado por empaque (mig. 032) |

### Tabla `pagos_fiado` — columnas completas (mig. base + 034)
| Columna | Tipo | Descripción |
|---------|------|-------------|
| `monto` | DECIMAL | Monto del abono |
| `metodo_pago` | VARCHAR | Método de pago |
| `saldo_anterior` | DECIMAL | **INMUTABLE** — deuda del cliente ANTES del abono (mig. 034) |
| `saldo_posterior` | DECIMAL | **INMUTABLE** — deuda DESPUÉS del abono = anterior − monto (mig. 034) |

### Tabla `produccion_lotes` — columnas (mig. 015 + 034)
| Columna | Tipo | Descripción |
|---------|------|-------------|
| `costo_unitario` | DECIMAL | **INMUTABLE** — snapshot de costo_calculado al producir |
| `nombre_snap` | VARCHAR(200) | **INMUTABLE** — nombre del producto al producir (mig. 034) |
| `estado` | ENUM | `activo` / `anulado` — único campo que se puede actualizar |

> Módulo: `clientes/index.php` — CRUD + fusión de duplicados.
> Permiso: usa 'ventas' (no tiene entrada propia en permisos_modulos).
> Fusión: atómica — transfiere ventas + pagos_fiado + saldo al principal, desactiva el secundario.

### Tabla `ventas`
`id`, `fecha_venta`, `cliente_id`, `metodo_pago`, `total`, `estado`, `fecha_pago` (NULL=fiado sin cobrar), `metodo_cobro` (ENUM efectivo/nequi/daviplata/bancolombia, NULL=no aplica o aún sin cobrar — con qué se saldó un fiado; `metodo_pago` se queda en 'fiado' — mig. 042), `es_combo` (derivado: 1 si algún ítem es combo), `tipo_sandwich`, `descuento_pct`/`descuento_valor` (**INMUTABLE**, snapshot del descuento aplicado — mig. 038; `total` = bruto − `descuento_valor`)

### Tabla `venta_detalles`
`id`, `venta_id`, `producto_id`, `cantidad`, `precio_unitario`, `subtotal`, `from_stock` (1=del stock terminado, 0=de insumos), `es_combo` (0=solo, 1=vendido como combo), `combo_id` FK→combo_configs (NULL en ventas históricas), `variante_id` FK→producto_variantes, `variante_etiqueta`, `factor_receta_snap` (**INMUTABLE** — tamaño/variante vendido, mig. 035; NULL en ventas previas), `nombre_snap`/`nombre2_snap` (**INMUTABLE** — nombre del producto al vender, mig. 034)

### Tabla `combo_configs`
`id`, `producto_id` (UNIQUE FK→productos), `nombre` (ej. "Combo"), `precio_adicional`, `activo` (soft-delete), `created_by` FK→usuarios

### Tabla `combo_insumos`
`id`, `combo_id` FK→combo_configs, `insumo_id` FK→insumos, `cantidad` DECIMAL(10,4), UNIQUE(combo_id, insumo_id)

### Tabla `empleados`
`nombre_completo`, `documento_identidad`, `cargo`, `tipo_contrato`, `pais_laboral`, `salario_base`, `valor_hora`, `valor_proyecto`, `horas_semana`, `periodo_horas_emp`, `aplica_aux_transporte`, `tipo_costo` (directo|indirecto), `activo`

### Tabla `productos`
`nombre`, `nombre2` (subtítulo complementario, VARCHAR 120, nullable — migración 027), `categoria`, `tamano`, `precio_venta`, `costo_calculado`, `unidades_por_receta` (default 1), `stock_disponible`, `stock_minimo`, `activo`

> **nombre2**: campo visual opcional. Ejemplos: nombre="Sandwich de Pollo" + nombre2="con papas criollas".
> Se muestra como subtítulo en POS, producción, historial, stock y reportes.
> No afecta ninguna lógica de negocio. NULL = sin subtítulo.

### Tabla `activos`
`nombre`, `numero_unidades`, `precio_unitario`, `costo_inicial`, `fecha_adquisicion`, `fecha_inicio_uso` (**ver regla**), `garantia_hasta`, `vida_util_meses`, `depreciacion_mensual`, `depreciacion_diaria`, `estado_vida`, `estado_fisico`, `categoria_activo`, `lugar_compra`, `proveedor_id` FK, `serial`, `foto_url`, `responsable`

### Tabla `costos_indirectos`
`nombre`, `categoria`, `descripcion`, `clasificacion` (directo|indirecto), `tipo` (fijo|variable), `frecuencia`, `valor`, `fecha_inicio`, `fecha_fin`, `activo`

### Tabla `produccion_lotes`
`producto_id` FK, `fecha_produccion`, `cantidad`, `costo_unitario` (snapshot), `estado` (activo|anulado)

---

## 5. MÓDULO ACTIVOS — REGLAS DE DEPRECIACIÓN

### Regla fundamental (migración 017)
> **Un activo NO se deprecia hasta que tenga `fecha_inicio_uso` asignada.**

| Campo | Fecha usada | Descripción |
|---|---|---|
| `depreciacion_mensual/diaria` | `fecha_inicio_uso` | Solo si tiene fecha de uso; si no → 0 |
| `valor_en_libros` | `fecha_adquisicion` | El activo pierde valor contable desde la compra |
| `estado_vida` | `fecha_inicio_uso` | NULL → 'en_espera' (sin calcular depreciación) |

### Fórmulas de depreciación (migración 018)
```
depreciacion_mensual = costo_inicial ÷ vida_util_meses
depreciacion_diaria  = depreciacion_mensual ÷ 30.41666   (= 365 ÷ 12, 5 decimales)
valor_en_libros      = costo_inicial − (depreciacion_mensual × meses_desde_fecha_adquisicion)
```
> ⚠️ El divisor es **30.41666** (no 30.4). Para consistencia, usar siempre 5 decimales en divisiones/multiplicaciones de depreciación.

### Triggers actualizados (018)
- **INSERT:** `fecha_inicio_uso IS NOT NULL` → calcula con 30.41666; si NULL → deprec = 0
- **UPDATE:** si se quita `fecha_inicio_uso` → deprec = 0; si se asigna/cambian valores → recalcula con 30.41666
- La migración 018 también recalcula todos los activos existentes con fecha_inicio_uso

### ActivoModel — campos usados por cada método
- `costo_diario_total()` → solo activos con `fecha_inicio_uso IS NOT NULL AND ≤ CURDATE()`
- `resumen_ampliado()` → dep operativa: usa `fecha_inicio_uso`; valor_en_libros: usa `fecha_adquisicion`

### Tabla sortable (JS client-side)
Columnas sortables: Activo, Categoría, Serial, Unidades, Precio/u, Total, Estado
- Clic en header → ↑; segundo clic → ↓; fila de totales siempre al final

### Estructura de columnas (activos/index.php)
- **Activo** (min-width 186px) — nombre + descripción + fechas + proveedor + acciones (Editar/Duplicar/Foto/Baja)
- **Estado** — columna independiente con badge estado físico (sortable)
- Columnas sortables usan `data-*` en `<tr>` + función JS `sortActivos(col)`

---

## 6. MÓDULO NÓMINA

### `tipo_costo` en empleados
- `directo` = produce el sándwich (cocina) → alimenta "Nómina directa" en Costos
- `indirecto` = soporte/administración → alimenta "Nómina indirecta" en Costos

### 4 Tipos de Contrato
| Tipo | Base de pago | Prestaciones | Aux. Transporte | Salario_base requerido |
|---|---|---|---|---|
| `tiempo_completo` | Salario base | Todas | Si aplica (≤ 2 SMLMV) | Sí |
| `medio_tiempo` | Salario × 50% | Todas proporcionales | Si aplica | Sí |
| `por_horas` | valor_hora × horas_trabajadas | Todas proporcionales | Si aplica | Sí (base para calcular valor/hora) |
| `por_servicio` | valor_proyecto (fijo) | **Ninguna** | No | **No** — requiere valor_proyecto > 0 |

### Validación de empleados por tipo de contrato
- `por_servicio`: `salario_base` puede ser 0; se exige `valor_proyecto > 0`
- Todos los demás: exige `salario_base > 0`
- Input salary: `step="1"` (acepta cualquier entero — SMLMV exacto = $1.750.905)

### Jornada legal (Colombia, Ley 2101/2021)
44h/semana = **191.18 h/mes** — configurable en Parámetros → categoría `horas_jornada`

### Valor/hora para contratos `por_horas`
```
valor_hora = salario_base ÷ horas_mes_estandar  (191.18 h/mes — NO 240)
```
- Si el empleado tiene `valor_hora` manual → se usa ese valor directamente
- El método `empleados_por_horas_periodo($mes, $anio, $horas_mes)` recibe `$horas_mes` como parámetro

### Pago estimado en registro de horas
```
pago_estimado = horas_ponderadas × valor_hora
horas_ponderadas = SUM(horas × multiplicador_tipo)
```
El módulo muestra "Horas con recargos" (en morado) cuando hay diferencia entre horas brutas y ponderadas.

### Columna "Salario / Pago" en liquidaciones y tabla de empleados
Cada tipo muestra diferente información:
| Tipo | Columna muestra | Etiqueta |
|---|---|---|
| `tiempo_completo` | `salario_base` | "Salario base" |
| `medio_tiempo` | `salario_base` (ya es el 50%) | "Salario (50%)" |
| `por_horas` | `valor_hora × horas_trabajadas` | "Pago (Xh)" |
| `por_servicio` | `valor_proyecto` | "Valor proyecto" |

**Fórmula universal** (nomina/index.php):
```
pago_base = costo_total_empleador − aux_transporte − total_cargas − total_provisiones
```
- `por_horas`: da exactamente `valor_hora × horas_trabajadas` ✓
- `por_servicio`: da exactamente `valor_proyecto` (cargas=0, prov=0, aux=0) ✓

### Columnas "Cargas" y "Provisiones" en liquidaciones
- `por_servicio`: muestra `—` (no tiene cargas ni provisiones)
- Todos los demás: muestra los valores calculados

---

## 7. MÓDULO PRODUCTOS + PRODUCCIÓN

### Costo por sándwich
```
costo_calculado = SUM(insumo.costo_actual × cantidad_requerida) ÷ unidades_por_receta
```
`unidades_por_receta = 1` (default) → receta por unidad individual.

### CRUD de productos (`guardar_producto.php`)
| Acción | Descripción |
|---|---|
| `crear` | INSERT nuevo producto |
| `editar` | UPDATE nombre, categoría, tamaño, precio, rinde, stock_min. Si cambia rinde → recalcula costos |
| `precio` | UPDATE solo precio_venta (desde vista rápida de receta) |
| `duplicar` | Copia producto + receta completa con "(Copia)" en el nombre |
| `actualizar_capacidad` | UPDATE `configuracion_negocio.produccion_estimada_mensual`; recarga la página para recalcular todos los márgenes |

### Capacidad instalada editable
- Campo en el banner de `productos/index.php` para actualizar `produccion_estimada_mensual`
- Al guardar → recalcula todos los costos fijos/u y márgenes de inmediato

### Calculadora bidireccional de receta (inline en cada producto expandido)
- **→** Ingresar número de sándwiches → muestra cantidades de ingredientes escaladas proporcionalmente
- **←** Ingresar cantidad de un ingrediente → calcula cuántos sándwiches se pueden hacer
- Se activa al expandir la fila del producto

### Configuración de combo (inline en cada producto expandido — migración 025)
- Cada producto puede tener exactamente 1 configuración combo (`combo_configs` UNIQUE por producto_id)
- La config define: nombre del combo, precio adicional, y lista de insumos extras (gaseosa, papas, etc.) con cantidades
- Se edita en `productos/index.php` → fila expandida → sección "Opción Combo"
- API: `productos/api/combo_crud.php` — GET devuelve config actual; POST guardar/eliminar
- Soft-delete: `activo=0` preserva historial de ventas combo ya registradas
- `toggleReceta()` carga receta e config combo en paralelo (`Promise.all`) para eficiencia

### Modal de producción (produccion.php)
- Campo cantidad: `type="text" inputmode="numeric"` (fix para móvil — `type="number"` con `oninput` fallaba)
- Botón explícito "Ver insumos que se descontarán" en lugar de oninput
- La cantidad se valida con `parseInt()` antes de enviar

### Flujo producción → venta
```
1. Producir N sándwiches → descuenta insumos → stock_disponible += N → registra en produccion_lotes
2. Vender → si stock_disponible ≥ qty: descuenta stock (from_stock=1)
           → si no: descuenta insumos (from_stock=0, modo demanda)
3. Anular venta → from_stock=1: restaura stock | from_stock=0: restaura insumos
```

### Página de análisis (`productos/analisis.php`)
Accesible desde el link "Ver análisis →" en productos/index.php. Incluye:

**1. Costeo real vs estimado:**
- Estimado: divide costos fijos entre `produccion_estimada_mensual` (capacidad instalada)
- Real: divide costos fijos entre producción real del período (`produccion_lotes`)
- Muestra ambos márgenes por producto

**2. Punto de equilibrio:**
```
Margen_contribucion = precio_venta - costo_variable_u - nómina_directa_u
PE_unidades = costos_fijos_totales ÷ margen_contribucion
```
- Barra de progreso: ventas actuales vs PE
- Alerta: ganancia o pérdida con monto estimado
- PE por producto individual

**3. Análisis histórico (últimos 90 días):**
- Promedio diario/semanal/mensual de ventas por producto
- Proyección mensual basada en histórico
- Sugerencia de producción diaria con +15% de buffer
- Comparación: proyección vs punto de equilibrio

**4. Desglose de costos:**
- Tabla con costos fijos desglosados (costos_indirectos, depreciación, nómina indirecta, nómina directa)
- Por unidad según capacidad Y según producción real del período

**Fuentes de datos:**
```
costos_fijos_mes    → CostoIndirectoModel::total_mensual_activo()
dep_mensual         → SUM(activos.depreciacion_mensual) WHERE fecha_inicio_uso IS NOT NULL
nomina_directa      → nomina_liquidaciones JOIN empleados WHERE tipo_costo='directo'
nomina_indirecta    → idem WHERE tipo_costo='indirecto'
prod_real           → SUM(produccion_lotes.cantidad) del período
ventas_historico    → ventas+venta_detalles últimos 90 días
```

---

## 8. MÓDULO COSTOS

### Selector de período (`?mes=N&anio=YYYY`)
Todos los datos se filtran al período. `location.reload()` preserva los GET params.

### Fuente de cada tarjeta KPI
| Tarjeta | Fuente | Filtro |
|---|---|---|
| Total / Directos / Indirectos | `costos_indirectos` activos | `fecha_inicio ≤ fin_mes AND fecha_fin ≥ ini_mes` |
| Compras | `SUM(compras.total)` | `fecha_compra BETWEEN ini AND fin` |
| Depreciación activos | `SUM(activos.depreciacion_mensual)` | `fecha_inicio_uso IS NOT NULL AND ≤ fin_mes AND estado_vida != 'depreciado'` |
| Nómina directa/indirecta | `nomina_liquidaciones` + `empleados.tipo_costo` | `periodo_mes + anio`; fallback: salario_base actual |
| Gran total | suma de todos | — |

---

## 9. MÓDULO VENTAS

- `ventas/index.php` — POS; **ya usa nav.php** (no tiene header propio)
- `ventas/historial.php` — historial, filtros, marcar pagado, anular
- `ventas/api/cambiar_estado.php` — `marcar_pagado` y `anular`
- `ventas/api/detalle_venta.php` — ítems de venta para expandir en historial

### Selector de cliente en el POS
- **Visible para TODOS los métodos de pago** (no solo fiado)
- Primera opción fija: `"— Cliente desconocido —"` (`value=""`) → venta sin cliente vinculado
- Título dinámico según método: `"Cliente (requerido para fiado)"` / `"Cliente (opcional)"`
- Validación: solo bloquea si `metodo_pago = 'fiado'` y no hay cliente seleccionado
- Al confirmar la venta → selector se resetea a "Cliente desconocido" automáticamente

### Fecha de venta — POS y edición
- **POS**: campo `type="date"` en el confirm sheet, valor por defecto = hoy, `max=hoy` (no permite fechas futuras). Se resetea a hoy tras cada venta exitosa. Enviado como `$fecha_venta` (YYYY-MM-DD) a `procesar_venta.php`, que añade `date('H:i:s')` actual para construir el DATETIME completo.
- **Edit modal (`historial.php`)**: campo `type="datetime-local"` pre-poblado con `fecha_venta` de la venta. Enviado como `"YYYY-MM-DD HH:MM"` al API. Si se envía vacío, la fecha se mantiene igual (`COALESCE(:fventa, fecha_venta)`).
- **`VentaModel::crear()`**: nuevo parámetro `?string $fecha_venta = null`. INSERT usa `COALESCE(:fventa, NOW())` — sin migración de DB necesaria.
- **`editar_venta.php` POST**: valida formato con regex, actualiza `fecha_venta = COALESCE(:fventa, fecha_venta)`.

### Obsequio en el POS (migración 026)
- `metodo_pago='obsequio'` es un nuevo método válido en el POS y en la edición de ventas.
- Descuenta stock exactamente igual que cualquier otra venta.
- El `total` guarda el precio real de los productos (para rastrear valor regalado), pero **NO se suma al ingreso** en ningún KPI (resumen_hoy(), historial, dashboard).
- En el historial aparece como badge rosa "Obsequio" y en un KPI aparte "🎁 Obsequiados: N — $X".
- El botón del POS cambia a "🎁 Registrar Obsequio" al seleccionarlo.

### Ajustes de stock desde Productos (migración 026)
- Botones 🎁 (Regalar) y 🗑 (Desechar) aparecen junto a cada producto que tenga `stock_disponible > 0` (requiere permiso `editar_existentes`).
- Registra en tabla `ajustes_stock` (producto_id, cantidad, tipo, motivo, fecha_ajuste, created_by).
- Descuenta `stock_disponible` del producto.
- API: `productos/api/ajuste_stock.php` (POST).

### Selector solo/combo en el POS (migración 025)
- Si el producto tiene combo configurado (`COMBOS[id]` en JS), aparece un mini bottom-sheet al añadir al carrito
- Permite elegir "Solo" o "Combo" (con precio total del combo mostrado)
- La clave del carrito es `${id}-${es_combo}` — permite el mismo producto solo Y combo en la misma venta
- El campo `es_combo` viaja por ítem en el carrito JSON enviado al backend

### Lógica de descuento en VentaModel::crear()
1. `SELECT ... FOR UPDATE` — lock anti race-condition
2. Si producto no existe → lanza excepción inmediata
3. Si `stock_disponible ≥ cantidad` → descuenta del stock terminado (`from_stock=1`)
4. Si no → descuenta insumos directamente (`from_stock=0`, modo demanda)
5. Si `es_combo=1`: además descuenta `combo_insumos.cantidad × venta_cantidad` de cada insumo extra del combo
   - Si stock de un insumo extra es insuficiente → RuntimeException (rollback)
   - `combo_id` se guarda en `venta_detalles` para restauración en anulación

### Anulación con combo (VentaModel::anular())
- Ítems con `es_combo=1 AND combo_id IS NOT NULL`: restaura insumos del combo (cantidad × unidades vendidas)
- Ítems con `es_combo=1 AND combo_id IS NULL` (datos históricos pre-025): solo restaura stock/insumos del producto; extras no se restauran (nunca se descontaron en el registro original)

---

## 10. MÓDULO ADMIN

### Acceso
Solo `rol = 'superadmin'` o `'admin'` — check directo por `$_SESSION['usuario_rol']`.

### Admin → Apariencia (configuracion_app)
Controla: nombre del negocio, dos logos (nav + login), colores brand/dark, fuente cuerpo, fuente encabezados, 4 tamaños de fuente (title/subtitle/body/small), 2 colores de texto.
- Logos: upload de archivo O ruta manual (campo de texto directo)
- Fuentes: whitelist de system fonts (sin CDN)

### Admin → Mantenimiento de datos (admin/mantenimiento.php, solo superadmin — v5.0)
- Limpieza masiva: reset transaccional global + borrar inactivos/anulados/todos por módulo
  (modo seguro/cascada). Motor en `admin/api/mantenimiento.php` (mapa `$ENTIDADES`). Exige escribir
  `BORRAR`, transacción, auditoría. Ver §"Estado v5.0".

### Admin → Base de Datos (admin/backup.php)
- **Backup BD SQL**: PHP PDO, streamed al cliente en bloques de 500 filas, GET `?action=download`
- **Backup código ZIP**: ZipArchive de `public_html/` (excluye `uploads/` y `app/config/`), GET `?action=download_code`
- **Ejecutar migración SQL**: upload `.sql` (máx 5 MB), ejecuta por sentencias, registra en logs_historial
- **Subir actualización de código**: upload `.zip` (máx 50 MB), extrae sobre `public_html/`; protege `database.php`, `app.php` y `uploads/` de sobreescritura; bloquea path-traversal con sanitización de rutas

---

## 11. MÓDULO AYUDA

`ayuda/index.php` — visible para todos los usuarios autenticados.

Contiene: visión general, flujos de datos, todas las fórmulas matemáticas, documentación por módulo con integración entre módulos.
- Sidebar navegable con búsqueda
- Buscador de contenido en tiempo real
- Sección de fórmulas globales (costo/sándwich, depreciación, recargos, capacidad POS)

---

## 12. MÓDULO REPORTES

| Reporte | Permiso | Hojas Excel |
|---|---|---|
| Ventas & Rentabilidad (`ventas.php`) | ventas | Ventas + Rentabilidad |
| Inventario, Producción & Activos (`operativo.php`) | inventario | Inventario + Activos + Stock Terminado + Producción + Obsequios y Desechos (si hay datos) |
| Nómina y Costo Laboral (`nomina.php`) | nomina | Nómina [mes] + Resumen |
| Costos del Negocio (`costos.php`) | costos | Resumen + Costos Registrados + Compras/Proveedor |
| Compras & Proveedores (`compras.php`) | compras | Historial + Por Proveedor + Por Insumo |
| Variación de Precios (`precios.php`) | reportes | Sin exportación — 6 tabs de solo lectura: Insumos, Productos, Nómina, Costos Fijos, Activos, Fiado/Abonos (ver §13 y §14) |

Exportación: `?export=1` en la URL. Herramienta: `XlsxWriter.php` (ZipArchive, sin dependencias).

---

## 13. MIGRACIONES — ORDEN COMPLETO

```
schema.sql                       → ⭐ INSTALACIÓN COMPLETA v4.25 (27 tablas, 9 triggers, seed data — todo en uno, sin migraciones)
002_login_intentos.sql           → Rate limiting
003_sprint2.sql                  → Nuevas columnas ventas/activos/nómina
004_datos_reales.sql             → Datos reales del negocio
004b_recalcular_costos.sql       → Recalcular costos (tras 004)
005_activos_mejoras.sql          → foto, serial, numero_unidades, estado_fisico
006_activos_fecha_inicio.sql     → fecha_inicio_uso para depreciación
  006b_fix_fechas.sql            → Solo si 006 dio "columna duplicada"
007_nomina_contratos.sql         → tipo_contrato, registro_horas, parametros_laborales
008_horas_extras.sql             → tipo_hora/es_festivo, recargos Art.168-172
009_empleado_horas_contrato.sql  → periodo_horas_emp en empleados
010_insumos_presentacion.sql     → presentacion + trigger costo_actual
011_proveedores_modulo.sql       → email/sitio_web en proveedores, 'proveedores' al ENUM
011b_activos_proveedor_id.sql    → proveedor_id FK en activos
012_costos_indirectos.sql        → tabla costos_indirectos
  012b_costos_permisos.sql       → Si 012 dio error de permisos
013_costos_clasificacion.sql     → elimina empleado_id, agrega clasificacion en costos
014_empleados_tipo_costo.sql     → tipo_costo ENUM en empleados
015_productos_produccion.sql     → unidades_por_receta, stock_disponible, from_stock, produccion_lotes
016_admin_modulo.sql             → tabla configuracion_app, actualiza ENUM permisos_modulos
  016b_logo_login.sql            → agrega clave logo_url_login a configuracion_app
  016c_tipografia.sql            → 7 claves tipográficas a configuracion_app
017_depreciacion_solo_con_fecha_uso.sql → triggers: deprec solo con fecha_inicio_uso; limpia activos sin fecha
018_precision_depreciacion_diaria.sql   → divisor 30.4 → 30.41666; recalcula todos los activos existentes
025_combo_config.sql                    → tablas combo_configs + combo_insumos; ALTER venta_detalles (es_combo, combo_id); migra ventas históricas (es_combo=1 en detalles donde ventas.es_combo=1)
026_obsequio_desecho.sql               → ALTER ventas.metodo_pago (agrega 'obsequio'); CREATE ajustes_stock
027_productos_nombre2.sql              → ADD COLUMN productos.nombre2 VARCHAR(120) NULL (subtítulo visual)
028_clientes_campos.sql                → ADD COLUMN clientes.apellido VARCHAR(100), clientes.empresa VARCHAR(150)
029_listas_sistema.sql                 → CREATE TABLE listas_sistema + seed de 6 catálogos (presentacion, unidad_medida, categoria_insumo, categoria_activo, categoria_costo, categoria_proveedor)
029b_listas_productos.sql              → seed de 2 catálogos adicionales: categoria_producto y tamano_producto
030_insumo_equivalencia.sql            → ADD COLUMN insumos.equiv_cantidad DECIMAL(10,4) + insumos.equiv_unidad VARCHAR(10)
031_enum_a_varchar.sql                 → ✅ APLICADA EN PRODUCCIÓN. Convierte 5 ENUMs → VARCHAR: productos.categoria/tamano, insumos.unidad_medida/presentacion, activos.categoria_activo. Habilita catálogos dinámicos.
032_compra_detalles_presentacion.sql   → ADD COLUMN compra_detalles.(presentacion, cantidad_presentacion, cant_presentaciones, precio_presentacion) — snapshot del empaque al comprar
033_nomina_snapshots.sql               → ADD COLUMN nomina_liquidaciones.(valor_hora_snap, valor_proyecto_snap) — tarifa/hora y valor proyecto usados al liquidar
034_snapshots_nombres_y_saldo.sql      → ADD COLUMN venta_detalles.(nombre_snap, nombre2_snap) | compra_detalles.(nombre_snap, unidad_snap) | produccion_lotes.(nombre_snap) | pagos_fiado.(saldo_anterior, saldo_posterior)
035_variantes_producto.sql             → CREATE TABLE producto_variantes (id, producto_id, etiqueta, precio_venta, factor_receta, activo, created_by); ALTER venta_detalles ADD (variante_id, variante_etiqueta, factor_receta_snap). SIN FK (errno 121 cPanel)
036_receta_ingrediente_base.sql        → ALTER TABLE recetas ADD COLUMN es_base TINYINT(1) DEFAULT 0 — ingredientes base no escalan con factor_receta de variante
037_turnos_caja.sql                    → CREATE TABLE turnos_caja (id, fecha, fondo_inicial, notas_apertura, usuario_apertura, fecha_apertura, estado ENUM('abierto','cerrado'), fecha_cierre, usuario_cierre, notas_cierre)
038_descuento_venta.sql                → ALTER TABLE ventas ADD COLUMN descuento_pct DECIMAL(5,2) DEFAULT 0, ADD COLUMN descuento_valor DECIMAL(12,2) DEFAULT 0
039_insumo_presentaciones.sql          → CREATE TABLE insumo_presentaciones (catálogo de presentaciones de compra por insumo); ALTER TABLE compra_detalles ADD COLUMN presentacion_id INT DEFAULT NULL (FK lógica)
040_config_formato_numerico.sql        → INSERT IGNORE en configuracion_app: num_decimales, num_sep_miles, num_sep_decimal (formato numérico configurable — decimales y separadores, Admin → Apariencia)
041_config_sep_millones.sql            → INSERT IGNORE en configuracion_app: num_sep_millones (separador independiente del grupo de millones; si = num_sep_miles → formato uniforme)
042_venta_metodo_cobro.sql             → ALTER TABLE ventas ADD COLUMN metodo_cobro ENUM('efectivo','nequi','daviplata','bancolombia') DEFAULT NULL AFTER fecha_pago (con qué se cobró un fiado; metodo_pago se queda en 'fiado')
043_indices_rendimiento.sql            → CREATE INDEX idx_ins_activo ON insumos(activo) + idx_cd_presentacion ON compra_detalles(presentacion_id) — idempotente (guard via information_schema); cierra avisos G18 de suite.php en BDs anteriores

### Política de snapshots (principio de inmutabilidad extendido)
Además de los precios, TODOS estos datos se guardan como snapshot al momento de la transacción:
- `venta_detalles.nombre_snap/nombre2_snap` — nombre del producto al vender (migr. 034)
- `compra_detalles.nombre_snap/unidad_snap` — nombre e unidad del insumo al comprar (migr. 034)
- `compra_detalles.presentacion/cantidad_presentacion/cant_presentaciones/precio_presentacion` — cómo se compró el empaque (migr. 032)
- `produccion_lotes.nombre_snap` — nombre del producto al producir (migr. 034)
- `pagos_fiado.saldo_anterior/saldo_posterior` — deuda del cliente antes y después del abono (migr. 034)
- `nomina_liquidaciones.valor_hora_snap/valor_proyecto_snap` — tarifas usadas en la liquidación (migr. 033)

### Visualización histórica completa (reportes/precios.php)
6 secciones disponibles:
| Tab | Fuente | Datos nuevos tras migraciones |
|-----|--------|-------------------------------|
| 🧂 Insumos | compra_detalles | Tipo empaque, und/empaque, nro. empaques, precio/empaque |
| 🥪 Productos | venta_detalles | Nombre snapshot, evolución de precios de venta |
| 👤 Nómina | nomina_liquidaciones | Tarifa/hora snapshot, horas trabajadas, valor proyecto |
| 💰 Costos Fijos | costos_indirectos | Períodos de vigencia, variación mensual |
| 🔧 Activos | logs_historial | Historial de cambios en costo_inicial y vida_útil |
| 💳 Fiado/Abonos | pagos_fiado | Saldo antes y después de cada abono (migr. 034) |
```

**Todos incluyen `USE clandestinoERP;`** — reemplazar por el nombre real de la DB.

---

## 14. INVARIANTE CRÍTICO: INMUTABILIDAD HISTÓRICA EXTENDIDA

> **⚠️ Esta regla aplica a TODOS los módulos y nunca debe violarse.**

### Principio
Cada transacción registra **tanto el precio como el contexto completo** (nombre, empaque, tarifa, saldo) vigente al momento de realizarse. Esos datos **no cambian jamás**, aunque los maestros (productos, insumos, empleados, clientes) cambien después.

### Implementación completa

| Tabla | Campos inmutables | Se preserva aunque cambie… |
|-------|-------------------|---------------------------|
| `venta_detalles` | `precio_unitario`, `nombre_snap`, `nombre2_snap` | Precio de venta del producto; nombre del producto |
| `compra_detalles` | `precio_unitario`, `precio_presentacion`, `nombre_snap`, `unidad_snap`, `presentacion`, `cantidad_presentacion`, `cant_presentaciones` | Precio del insumo; nombre del insumo; tipo de empaque |
| `produccion_lotes` | `costo_unitario`, `nombre_snap` | Costo de ingredientes; nombre del producto |
| `nomina_liquidaciones` | `salario_base`, `costo_total_empleador`, `total_cargas`, `total_provisiones`, `valor_hora_snap`, `valor_proyecto_snap` | Salario del empleado; tarifa/hora; valor de proyecto |
| `costos_indirectos` | Cada fila ES un período histórico | Nueva fila al cambiar valor (no editar la existente) |
| `pagos_fiado` | `saldo_anterior`, `saldo_posterior` | Saldo del cliente al momento del abono |
| `activos` | `costo_inicial`, `vida_util_meses` → auditados en `logs_historial` | Cambios posteriores quedan en auditoría con timestamp |

### La única excepción permitida
Una **corrección por mala digitación** usa flujo DELETE+INSERT (no UPDATE del precio). Documentado con comentario en `editar_venta.php` y `CompraModel::editar()`.

### Detección dinámica de migraciones
Los modelos usan `SHOW COLUMNS` / `information_schema.COLUMNS` para detectar si las columnas de snapshot existen antes de intentar guardarlas. Retrocompatibilidad heredada — en producción todas las columnas (032-034) están aplicadas.

### Lo que cambia (y debe cambiar)
- `insumos.costo_actual` → se actualiza en cada compra (es el costo **actual** de referencia para nuevas producciones)
- `productos.costo_calculado` → se recalcula cuando `costo_actual` cambia (para saber el margen **hoy**)
- `productos.precio_venta` → puede editarse (precio de venta **hoy**)
- `empleados.salario_base` → puede editarse (salario **vigente**)

### Reporte de variación de precios
`reportes/precios.php` — Muestra cómo han evolucionado en el tiempo:
- Insumos (desde `compra_detalles` — precio pagado por compra)
- Productos (desde `venta_detalles` — precio cobrado efectivo; desde `produccion_lotes` — costo de producción)
- Nómina (desde `nomina_liquidaciones` — salario por período)
- Costos fijos (desde `costos_indirectos` — valor por período de vigencia)

---

## 15. DECISIONES TÉCNICAS VIGENTES

| Decisión | Razón |
|---|---|
| `--r` inyectado en nav.php `:root` | Única fuente de verdad para border-radius; pages que declaren `--r` localmente lo sobreescriben |
| nav.php inyecta reglas globales de `.card`, `.modal`, etc. con `var(--r)` | Permite que `theme_radius` en Admin afecte todos los módulos sin tocar cada página |
| `samesite: Lax` | Compatibilidad con cPanel PHP-FPM |
| Sin `session_regenerate_id()` | Race condition en hosting compartido |
| `CAST AS SIGNED` en activos | Evita overflow UNSIGNED MySQL estricto |
| `fecha_inicio_uso` para deprec operativa | Solo deprecia cuando el activo está en servicio |
| `fecha_adquisicion` para valor_en_libros | El activo pierde valor contable desde la compra |
| Depreciación = 0 sin fecha_inicio_uso | Trigger INSERT/UPDATE: si NULL → dep = 0 |
| Divisor depreciación diaria = 30.41666 | Precisión: 365÷12 en lugar de 30.4 |
| Parámetros nómina en tabla DB | Cambio de ley → actualizar fila → recálculo automático |
| `configuracion_app` para tema/tipografía | `configuracion_negocio.valor` es DECIMAL, no acepta texto |
| nav.php inyecta CSS global | Sobreescribe `:root` de todas las páginas con 1 sola fuente de verdad |
| Backup PHP PDO (no mysqldump) | `exec()` deshabilitado en cPanel compartido |
| Logo: ruta manual + upload | Si upload falla (permisos), el admin puede pegar la ruta directamente |
| Fallback nav logo → logo_login | Si solo hay logo de login, se reutiliza en el nav |
| `unidades_por_receta = 1` default | Backward compatible con recetas existentes |
| `from_stock` en venta_detalles | Necesario para anular ventas y restaurar la fuente correcta |
| Admin/Ayuda sin permisos_modulos | Check directo por `$_SESSION['usuario_rol']` |
| Menú hamburguesa en móvil ≤640px | Evita scroll horizontal en pantallas pequeñas |
| Sort activos JS client-side | Tabla pequeña (~50 activos max), no necesita server-side |
| Duplicate producto copia receta completa | Permite iterar sobre recetas sin perder la base original |
| `por_servicio` permite salario_base=0 | El pago es valor_proyecto, no salario mensual |
| `step="1"` en input salario | Acepta cualquier entero — SMLMV=$1.750.905 no es múltiplo de 1000 |
| Valor/hora nómina usa 191.18h (no 240h) | Jornada legal Colombia 2026; 240 era hardcoded incorrecto |
| pago_base = costo_total−aux−cargas−prov | Fórmula universal: extrae el pago base para cualquier tipo de contrato |
| horas_ponderadas con multiplicadores | Registro de horas incluye recargos (nocturno×1.35, extra×1.25...) en el estimado |
| calcular_desglose_horas() → REPLACE not ADD | Resultado ya incluye ordinarias (×1.00); sumarlo a salario_efectivo los duplicaba |
| Aux. transporte proporcional (por_horas) | `$249.095 × (horas_trabajadas / 191.18)` — Circ. 0058/2015 Min. Trabajo |
| Aux. transporte 50% (medio_tiempo) | Proporcional a la jornada efectiva — Circ. 0058/2015 |
| Modal producción usa type="text" inputmode="numeric" | `type="number"` con `oninput` fallaba en dispositivos móviles |
| Calculadora receta es JS puro sin backend | Lee cantidades del DOM renderizado; no requiere petición al servidor |
| analisis.php usa costo_unitario de produccion_lotes | Snapshot del costo real del ingrediente al momento de producir |
| VentaModel usa FOR UPDATE en SELECT stock | Previene race condition (dos ventas simultáneas vendiendo el mismo stock) |
| nav.php valida hex color antes de inyectar CSS | Previene CSS injection por admin malicioso via theme_brand |
| nav.php valida ruta logo (debe ser uploads/) | Previene javascript: protocol en src de imagen |
| guardar_apariencia.php valida ruta logo con str_starts_with | Previene path traversal (`../../etc/passwd` → rechazado) |
| guardar_apariencia.php usa mkdir 0700 (no 0755) | Solo propietario accede al directorio en hosting compartido |
| generar.php usa whitelist explícita para accion | Evita default implícito que ejecutaba generar sin autorización |
| usuario_crud.php exige contraseña ≥ 8 caracteres | OWASP recomienda 8+ con bcrypt |
| guardar_producto.php usa ON DUPLICATE KEY UPDATE | Atomicidad: evita UPDATE+INSERT no-atómico |
| procesar_venta.php limita cantidad a 9999 | Previene abuso con cantidades extremas |
| `.card { overflow:hidden; overflow-x:auto }` — doble declaración | La segunda sobreescribe el shorthand; permite scroll horizontal en tablas manteniendo el clip de border-radius |
| `fecha_venta` en POS es `type="date"` (solo fecha) | La hora se añade en el API con `date('H:i:s')` — suficiente precisión para registro manual de ventas pasadas |
| `fecha_venta` en edit usa `COALESCE(:fventa, fecha_venta)` | Si el campo llega vacío/nulo, la fecha original no cambia — evita borrar fechas accidentalmente |

---

## 16. BUGS RESUELTOS

> Añadidos en v4.7:

| Bug | Causa | Solución |
|---|---|---|
| 500 en activos | UNSIGNED overflow MySQL | `CAST(vida_util_meses AS SIGNED)` |
| Tab Nómina sin resaltar | Faltaba `$nav_activo = 'nomina'` | Añadida en horas.php y parametros.php |
| Dashboard mostraba texto "ClanDestino" | Tenía su propio `<header>` hardcodeado | Reemplazado con `include nav.php` |
| Logo no aparecía en nav | `BASE_PATH . '/public_html/uploads'` duplicaba la ruta | Corregido a `BASE_PATH . '/uploads'` |
| Depreciación en activos sin fecha_inicio_uso | COALESCE(fecha_inicio_uso, fecha_adquisicion) como fallback | Eliminado fallback; sin fecha → dep = 0 |
| Valor en libros usaba fecha_inicio_uso | Mismo COALESCE | Valor en libros ahora usa `fecha_adquisicion` |
| `produccion_lotes` / `stock_disponible` crashaban dashboard | Queries sin try-catch si migración 015 no aplicada | Envueltas en try-catch independientes |
| admin/index.php → 500 | `information_schema` restringido en cPanel | Fallback a `SHOW TABLES` |
| CSS dashboard roto | Comentario sin cerrar al eliminar header CSS | Limpiado el bloque CSS |
| Ventas sin nav | nav.php no incluido en ventas/index.php | Añadido include, eliminado header propio |
| 500 costos ENUM | 'costos' no estaba en ENUM permisos_modulos | Migración 012b |
| Salario `step="1000"` rechazaba SMLMV exacto | `step="1000"` no acepta 1.750.905 | Cambiado a `step="1"` |
| Valor/hora nómina incorrecto | Divisor hardcodeado 240 en lugar de jornada legal | `empleados_por_horas_periodo()` recibe `$horas_mes` (191.18) |
| Pago estimado horas ignoraba recargos | Multiplicaba horas brutas × valor_hora | Usa `horas_ponderadas` con multiplicadores por tipo_hora |
| Columna "Salario/Pago" mostraba salario_base para todos | `$liq['salario_base']` = referencia, no pago real | Fórmula `pago_base = costo_total − aux − cargas − prov` |
| `por_servicio` bloqueado con salario_base=0 | Validación `salario <= 0` en crear/actualizar empleado | Validación ahora bypassea para `por_servicio`; exige `valor_proyecto > 0` |
| Depreciación usaba 30.4 en lugar de 30.41666 | Divisor impreciso (≠ 365/12) | Migración 018: todos los triggers y activos recalculados con 30.41666 |
| Aux. transporte por_horas era el mes completo | No proporcional a las horas trabajadas | `aux = $249.095 × (horas / 191.18)` — proporcional |
| Aux. transporte medio_tiempo era el mes completo | No proporcional a la jornada | `aux = $249.095 × 50%` |
| Salario efectivo por_horas se DUPLICABA | `calcular_desglose_horas()` devuelve total incluyendo ordinarias; se SUMABA a salario_efectivo ya calculado | Cambiado a REPLACE: `$salario_efectivo = $valor_extras` |
| `eliminar_periodo` nómina no hacía nada | `generar.php` ignoraba el parámetro `accion` y siempre ejecutaba generar | Whitelist explícita + `elseif` para cada acción |
| JS de modal producción no respondía en móvil | `type="number"` con `oninput` falla en algunos navegadores móviles | Cambiado a `type="text" inputmode="numeric"` |
| Acciones productos (editar/duplicar/expandir) no funcionaban | `}` de más rompía el bloque `<script>` completo | Eliminada la `}` duplicada |
| analisis.php depreciación siempre 0 | Ternario `? 0 : 0` — bug lógico que ignoraba el fetchColumn() | Reescrito con `$stmt->execute()` + `fetchColumn()` correcto |
| Race condition en venta de stock terminado | SELECT sin FOR UPDATE → dos requests concurrentes vendían el mismo stock | Añadido `FOR UPDATE` al SELECT de productos en VentaModel |
| Producto inexistente vendido como modo demanda | `$prodInfo = false` → `$stockDisp = 0` sin error | Añadida validación `if (!$prodInfo) throw RuntimeException` |
| Path traversal en ruta logo manual | `preg_replace` permitía `../../etc/passwd` | Añadida validación `str_starts_with($ruta, 'uploads/logos/')` |
| CSS injection via theme_brand en nav.php | Valores no validados al leer de DB | Validación `/^#[0-9a-fA-F]{6}$/` antes de inyectar en CSS |
| Ruta logo con protocol javascript: en nav | No se validaba el protocolo | `str_starts_with($url, 'uploads/')` requerido |
| Fiado creaba estado='completada' incorrecto | Estado hardcodeado en VentaModel::crear() | Ahora `$estado = fiado && !fpago ? 'pendiente_pago' : 'completada'` |
| resumen_hoy() excluía ventas fiado activas | `estado='completada'` filtraba pendiente_pago | Cambiado a `estado IN ('completada','pendiente_pago')` |
| KPI pendiente en historial no detectaba estado=pendiente_pago | Solo chequeaba `fecha_pago IS NULL` | Ahora también detecta `estado='pendiente_pago'` |
| APP_VERSION desactualizada (4.0) | Constante no actualizada tras subir versión | Corregida a '4.8' en app.php |
| '? Ayuda' en nav.php con símbolo extra | Label string tenía '? ' al inicio | Eliminado; label ahora es 'Ayuda' |
| CompraModel::editar() sin validar existencia | Podía operar sobre compra inexistente | Añadida verificación previa con SELECT |
| `fecha_venta` admitía fechas futuras en POS | Solo había validación client-side (`max=hoy`) | `procesar_venta.php`: rechaza si `$fecha_venta_raw > date('Y-m-d')` |
| `fecha_venta` admitía fechas futuras en edición | `datetime-local` sin `max`; sin validación server-side | `editar_venta.php`: rechaza si `$fecha_venta > date('Y-m-d H:i:s')`; inputs con `max="<?= date('Y-m-d\TH:i') ?>"` |
| CSS `.hdr/.brand/.nav/.nl` muerto en 7 módulos | Reglas del header antiguo no eliminadas al migrar a nav.php | Eliminado de `nomina/empleados.php`, `nomina/index.php`, `inventario/compras.php`, `inventario/lista_compras.php`, `reportes/ventas.php`, `reportes/nomina.php`, `reportes/operativo.php` |
| `cambiar_estado.php` marcar_pagado sin transacción | UPDATE ventas y UPDATE clientes no eran atómicos; si el segundo fallaba el saldo_fiado quedaba incorrecto | Envuelto en `beginTransaction()/commit()` + rollback en catch |
| `cambiar_estado.php` guard check incorrecto en marcar_pagado | Chequeaba `fecha_pago IS NULL` en lugar de `estado='pendiente_pago'` — podía "marcar pagada" una venta completada no-fiado | Cambiado a `$v['estado'] !== 'pendiente_pago'` |
| `reportes/operativo.php` hoja Excel Obsequios nunca aparecía | `$ajustes_periodo` y `$obsequios_venta` se consultaban DESPUÉS del bloque `if (isset($_GET['export']))` | Movidas las queries antes del bloque de exportación |
| `reportes/operativo.php` enlace Excel perdía filtros mes/anio | `href="?export=1"` no preservaba los parámetros GET de período seleccionado | Cambiado a `?mes=&anio=&export=1` |
| `reportes/ventas.php` etiquetas de método en Excel eran crudas | `$metodo_label` se definía después del bloque Excel — "obsequio" aparecía sin formatear | Movida la definición antes del bloque de exportación |
| `APP_VERSION` desactualizada (4.8) | No se actualizó tras cada versión | Actualizada a 4.15 en `app/config/app.php` |
| `reportes/precios.php` param PDO duplicado | Query nómina usaba `:ini` dos veces; con `ATTR_EMULATE_PREPARES=false` lanzaba HY093 | Pre-calcular `$ini_ym`/`$fin_ym` en PHP y pasarlos como params únicos |
| `nomina/api/registrar_horas.php` interpolación SQL | `"SELECT ... WHERE id = $empleado_id"` usando query() sin prepared statement | Cambiado a `prepare('... WHERE id = ?')` + `execute([$empleado_id])` |
| `nomina/index.php` y `nomina/empleados.php` sin `$nav_activo` | El tab Nómina no se resaltaba en estas dos páginas (la corrección anterior solo cubrió horas.php y parametros.php) | Añadido `$nav_activo = 'nomina'; $nav_sub = 'nomina'/'empleados';` |
| `inventario/index.php` modal ajuste no guardaba sin cantidad | Validación JS `cantidad===0 && tipo!=='correccion'` bloqueaba guardar presentación/costo cuando no había movimiento de stock | Eliminada la restricción; el llamado a `ajustar_stock.php` ahora es condicional (`if cantidad !== 0`) |
| `inventario/compras.php` campos de presentación no mostraban contexto | El formulario solo mostraba Cantidad/Precio/Total sin ninguna referencia al tipo de empaque del insumo | Reemplazado bloque editable `pres-grid` por panel informativo de solo lectura: badge de tipo + unidad + cant/empaque + equivalencia física + hint dinámico de total físico al tipear cantidad |
| `ventas/api/editar_venta.php` reversa de insumos ignoraba factor variante | Paso 2b usaba `(cantidad_req/rinde)×cantidad` sin `factor_receta_snap` → restauraba cantidad incorrecta en ventas con variante XL/etc. | `COALESCE(vd.factor_receta_snap, 1.0)` en la query; multiplicado en el cálculo de devolución |
| `ventas/api/editar_venta.php` INSERT paso 4 sin columnas mig.035 | Al re-insertar líneas tras edición, faltaban `variante_id/variante_etiqueta/factor_receta_snap` → error si mig.035 aplicada | Detecta mig.035 con `information_schema`; añade `NULL, NULL, NULL` para las tres columnas |

---

## 17. ESTADO DE MÓDULOS (2026-05-30, actualizado a v4.92 — 2026-06-12)

> Dos mejoras transversales recientes afectan a (casi) todos los módulos de la tabla — ver sus
> propias secciones "Estado vX.XX" para el detalle completo:
> - **Catálogo de presentaciones de compra** (`insumo_presentaciones`, mig. 039) — v4.80-v4.86.
> - **Formato numérico configurable** (decimales/separadores, `fmt_cantidad`/`fmt_moneda`,
>   `formatMiles`/`formatDecimal`+`NUM_FORMAT`, Admin → Apariencia) — v4.87-v4.92.

| Módulo | Estado | Notas |
|---|---|---|
| Auth / Login | ✅ | Logo dinámico desde configuracion_app |
| Clientes | ✅ | CRUD completo (nombre+apellido+empresa+teléfono); toggle activo; fusión; botón 👁 filtra historial; botón 📋 **estado de cuenta** (`clientes/estado_cuenta.php?id=X`) — extracto con cargos + abonos + saldo corriente + impresión |
| Estado de cuenta | ✅ | `clientes/estado_cuenta.php` — historial cronológico de compras fiado + pagos con saldo corriente acumulado, filtro por período, modo impresión (window.print()), accesible desde Clientes y desde Fiado |
| POS — Selector cliente | ✅ | Reemplazado `<select>` estático por **autocomplete buscable**: filtra por nombre/apellido/empresa en tiempo real, chip verde al seleccionar, navegación teclado (↑↓ Enter Esc), touch-friendly (44px items), crea cliente on-the-fly y lo selecciona automáticamente |
| Dashboard | ✅ | Resumen del día + **panel de alertas**: insumos bajos, fiados pendientes, productos bajo mínimo |
| Ventas / POS | ✅ | Fiado crea estado=pendiente_pago; historial con filtros; marcar pagado (transacción atómica); anular; selector solo/combo; fecha_venta; obsequio como método de pago; **selector de variante de tamaño** (mig.035); descuento % en el carrito (mig.038); **formato numérico configurable** en index/apertura/cierre/fiado/historial (v4.89) |
| Inventario | ✅ | costo_actual trigger; modal editar/ajustar guarda presentación/costo **sin requerir cantidad de ajuste** (cantidad=0 omite llamada a ajustar_stock.php); **Conteo rápido** (`inventario/conteo.php`) — actualización masiva de stock sin editar insumo por insumo; **presentaciones catalogadas** (`insumo_presentaciones`, mig.039) como fuente primaria con sincronización a campos legacy (v4.83-v4.86); **formato numérico configurable** (v4.87) |
| Compras | ✅ | Filtros por fecha/lugar/ítem/categoría; editar/duplicar/eliminar compras; **panel informativo de presentación** al seleccionar insumo: muestra tipo de empaque, unidad básica, cant/empaque, equivalencia física y hint dinámico "= X unidades total"; usa **insumo_presentaciones** (mig.039) cuando hay catálogo (v4.80, v4.84); **formato numérico configurable** (v4.92) |
| Proveedores | ✅ | CRUD, toggle |
| Productos | ✅ | Editar, Duplicar, calculadora bidireccional de receta, capacidad editable, configuración combo inline; botones Regalar 🎁 y Desechar 🗑 **solo si hay stock terminado** (`stock_disponible>0`); campo nombre2 (subtítulo visual); **conversión receta ↔ equivalencia física** al agregar ingrediente (v4.85); **formato numérico configurable** (v4.88); **editar cantidades inline + copiar/combinar receta con % (v4.98)**; **tab Constructor de recetas (v4.99)** |
| Producción | ✅ | Registro diario (fix móvil), preview insumos, anular lote, desechar stock terminado 🗑; responsive xs añadido; **Sugerencia de producción** con selector 7/14/30 días |
| Nómina | ✅ | 4 contratos; pago correcto; aux proporcional; eliminar período funcional; **formato numérico configurable** en index/empleados/horas (v4.90) |
| Activos | ✅ | Sortable, dep solo con fecha_inicio_uso, 30.41666 como divisor |
| Costos | ✅ | Selector período, 8 KPIs consolidados |
| Reportes | ✅ | 6 reportes: Ventas, Operativo, Nómina, Costos, Compras, **Variación de Precios** (nuevo); Operativo con Obsequios/Desechos; Ventas con tabla **Por Variante** (mig.035); **formato numérico configurable** en los 6 reportes (v4.92) |
| Admin | ✅ | Usuarios, apariencia (2 logos + tipografía + theme_radius), backup BD + código ZIP, migraciones + updates de código, **Catálogos** (admin/listas.php — gestiona 6 listas configurables); sección **Formato de números** (decimales/separadores, mig.040) en Apariencia (v4.87) |
| Ayuda | ✅ | Documentación actualizada v4.13: obsequio, desechar, reportes obsequios/desechos |
| Compras (panel pres.) | ✅ | Panel informativo de solo lectura al seleccionar insumo: badge tipo empaque + unidad básica + cant/empaque + badge verde equivalencia física + hint dinámico total físico. Snapshot de presentación se guarda en `compra_detalles` (mig. 032 + 034). `calcPres` eliminado — lógica simplificada. |
| Historial ventas | ✅ | Acepta `?cliente_id=X` para filtrar por cliente; banner verde con nombre del cliente y saldo pendiente; preserva filtro al cambiar fechas |
| Reporte Precios | ✅ | **6 tabs**: Insumos (con columnas de empaque), Productos, Nómina (con tarifa/hora snap), Costos Fijos, **Activos** (historial logs_historial), **Fiado/Abonos** (saldo antes/después) |
| Tests | ✅ | Suite de pruebas en `/tests/suite.php` (solo superadmin) — **29 grupos, ~175 pruebas** (G01-G27: esquema, migraciones 026-038, precios, stock, fiado, obsequios, combos, clientes, produccion, activos, nomina, costos, FK, catalogos, configuracion, seguridad, auditoria, eficiencia, usuario UX, inmutabilidad profunda, ENUMs→VARCHAR, snapshots 032-034, **G23: variantes 035**, **G24: ingrediente base 036**, **G25: conteo rápido**, **G26: turnos de caja 037**, **G27: descuentos 038**, **G28: abonos a fiado (snapshots 034)**, **G29: presentaciones de compra (mig.039)**) |

### Autocomplete de clientes en POS (ventas/index.php)
- `CLIENTES_DATA`: array JSON serializado en PHP con todos los clientes (id, nombre, apellido, empresa, saldo)
- Funciones: `acFiltrar()`, `acAbrir()`, `acCerrar()`, `acSeleccionar(idx)`, `acLimpiar()`, `acTecla(event)`, `acReset()`
- `acNorm(s)`: normaliza texto eliminando tildes para búsqueda robusta
- `acSeleccionarPorId(id, nombre, saldo)`: selecciona cliente recién creado (on-the-fly)
- `acReset()`: se llama después de confirmar una venta para limpiar la selección
- Estado visual: input+dropdown (buscando) → chip verde (seleccionado)
- Cierra al hacer clic fuera del componente (event listener en document)

---

## 18. INTEGRACIONES CLAVE

```
Proveedores → insumos → recetas → productos (costo_calculado)
                                  productos → produccion_lotes → stock_disponible → ventas (from_stock=1)
                         insumos ← produccion_lotes (descuenta al producir)
                         insumos ← ventas on-demand (from_stock=0)
                         insumos → insumo_presentaciones (catálogo de empaques, mig.039)
                                 → inventario/compras.php (panel presentación, costo_actual al comprar)

productos → producto_variantes (tamaños, mig.035) → ventas (selector de variante, factor_receta)

activos.depreciacion → ActivoModel::costo_diario_total() → Productos (costo_deprec_u)
                     → resumen_ampliado().valor_en_libros (usa fecha_adquisicion)

costos_indirectos → CostoIndirectoModel::total_mensual_activo() → Productos (costo_fijo_u)
                  → costos/index.php KPIs

empleados.tipo_costo → Costos KPIs (nómina directa/indirecta)
                     → NominaModel::liquidaciones_periodo() (columna en Excel)

configuracion_app → nav.php (inyecta CSS global a todas las páginas)
                  → login.php (logos, color brand)
                  → admin/apariencia.php (edición del tema)
                  → nav.php → window.NUM_FORMAT (formato numérico, mig.040)
                            → fmt_cantidad()/fmt_moneda() (PHP) y formatMiles()/formatDecimal() (JS)
                              en todos los módulos (v4.87-v4.92)
```

---

## 19. MÓDULO COMPRAS — FUNCIONES CLAVE

### Filtros del historial (`compras.php` GET params)
| Param | Tipo | Descripción |
|---|---|---|
| `desde` / `hasta` | date | Rango fechas (default: últimos 30 días) |
| `lugar` | string | Búsqueda parcial en `lugar_compra` (LIKE) |
| `item` | string | Filtra compras que contienen ese insumo (EXISTS subquery) |
| `cat` | string | Filtra por `insumos.categoria` exacta |
| `orden` | string | `fecha` \| `lugar` \| `total` |

### CRUD de compras (`inventario/api/compra_crud.php`)
- `accion=editar`: revierte stock viejo → borra detalles → inserta nuevos → actualiza stock/costo → recalcula productos. Transacción atómica. Permisos: `editar_existentes`
- `accion=duplicar`: copia cabecera + líneas → llama `CompraModel::crear()`. Permisos: `solo_propios`
- `accion=eliminar`: revierte stock → DELETE en cascade. **No revierte costo_actual.** Permisos: `editar_existentes`

### Estado de ventas (importante)
- Fiado sin `fecha_pago` → `estado='pendiente_pago'` (desde v4.9)
- Al marcar pagado → `estado='completada'` + `fecha_pago=NOW()`
- `resumen_hoy()` incluye ambos estados (`completada` + `pendiente_pago`)

### Suite de pruebas de integridad (`tests/suite.php`)
Ejecutar como superadmin en el navegador. Valida:
- Esquema BD (27 tablas, columnas críticas, ENUM metodo_pago con 'obsequio')
- Inmutabilidad de precios históricos (venta_detalles, compra_detalles, produccion_lotes)
- Consistencia de stock (no negativos, from_stock válido)
- Consistencia saldo_fiado de clientes
- Integridad de obsequios (estado, total > 0)
- Integridad de producción (FK, estados, cantidades)
- Depreciación de activos (sin fecha_inicio_uso → dep=0)
- Nómina (FK, costo_total_empleador razonable)
- Configuración (todas las claves de configuracion_app)
- Foreign keys sin huérfanos en tablas críticas
- Costos de productos (costo_calculado > 0 cuando hay receta)
- Auditoría (logs_historial activo, passwords bcrypt)

### Suite de pruebas expandida (`tests/suite.php`) — v4.15
15 grupos, ~80 pruebas. Nuevos grupos añadidos:
- G02 Migración 026 (ENUM obsequio + ajustes_stock estructura completa)
- G07 Combos (combo_configs, combo_insumos, es_combo en venta_detalles)
- G14 Seguridad (APP_ENV, BCRYPT_COST, SESSION_LIFETIME, roles, rate-limiting)
- G15 Auditoría (logs recientes, bloqueos activos por fuerza bruta)
- G16 Estado (nueva)
- G17 Auditoría (renumerado)
- G18 Eficiencia (nueva) — índices BD críticos, cantidades anómalas
- G19 Usuario UX (nueva) — datos mínimos, contraseña default, catálogos llenos

### Diferencias reales encontradas al comparar con dump de producción (2026-06-03)
- `registro_horas.aprobado` (TINYINT, DEFAULT 0) — columna existente no documentada
- `parametros_laborales.nombre` (VARCHAR 200) y `.orden` (TINYINT) — campos existentes no documentados
- `parametros_laborales.tipo` ENUM en producción es `('porcentaje','valor_fijo')` no `('porcentaje','monto_fijo','factor')`
- `nomina_liquidaciones` tiene además: `horas_ordinarias`, `horas_extras`, `valor_horas_extras`, `detalle_recargos TEXT`
- `logs_historial` usa `fecha_cambio` (no `created_at`) como nombre de columna de timestamp
- `activos.estado_fisico` tiene 5 valores: `('excelente','bueno','regular','malo','baja')`
- ✅ Migración 031 aplicada en producción (dump 2026-06-03 20:34): los 5 ENUMs ya son VARCHAR (productos.categoria/tamano, insumos.unidad_medida/presentacion, activos.categoria_activo). Catálogos dinámicos completamente operativos.

### Iconografía del sistema (icons.php)
Todos los módulos usan los mismos SVG definidos en `app/views/icons.php` (incluido automáticamente por nav.php). Constantes disponibles: `IC_EDIT`, `IC_COPY`, `IC_TRASH`, `IC_EYE`, `IC_CHECK`, `IC_XMARK`, `IC_PAUSE`, `IC_PLAY`, `IC_CAMERA`, `IC_BOLT`, `IC_CHEV`, `IC_MERGE`, `IC_GIFT`, `IC_USER`, `IC_USERS`.
Uso: `<button class="btn-ajuste ic" title="..."><?= IC_EDIT ?></button>` — la clase `.ic` de nav.php hace el botón 30×30px (38×38 en móvil ≤640px).
**Regla**: botones de ACCIÓN → IC_* SVG. Labels/títulos → emojis aceptables para semántica. NUNCA usar emoji &#128XXX; en botones de tabla.

### Bugs críticos corregidos en revisión 2026-06-04
| Archivo | Bug | Fix aplicado |
|---------|-----|-------------|
| `inventario/api/compra_crud.php` | El caso 'editar' no pasaba campos de presentación (032) al modelo | Extraer campos presentacion/cant_presentaciones/precio_presentacion |
| `ventas/api/editar_venta.php` | Al re-insertar líneas de venta no guardaba nombre_snap (034) | Detectar mig.034, incluir nombre/nombre2 en SELECT FOR UPDATE y en INSERT |
| `ventas/api/cambiar_estado.php` | Marcar pagado no registraba en pagos_fiado ni guardaba saldo_anterior | INSERT en pagos_fiado con snapshot saldo_anterior/posterior |
| `ventas/historial.php` | Usaba p.nombre en vez de COALESCE(vd.nombre_snap, p.nombre) | Corregido COALESCE en GROUP_CONCAT |
| `ventas/api/detalle_venta.php` | Mismo problema con nombre actual en vez de snapshot | COALESCE en SELECT de items del detalle |
| `clientes/api/crud.php` | trim() sin re-validar nombre vacío (podría guardar ' ') | Validación explícita post-trim |

### Estado al cierre de sesión 2026-06-06 (v4.25)
Todo subido a GitHub. Sin pendientes de código ni migraciones.

**✅ Código y base de datos 100% sincronizados:**
- Migraciones 002-034 todas aplicadas en producción
- `database/schema.sql` sincronizado con producción (dump 2026-06-06)

**✅ Fixes aplicados en v4.25:**
- `admin/index.php`: `logs_historial.created_at` → `logs_historial.fecha_cambio` (query + display)
- `tests/suite.php`: `logs_historial.created_at` → `logs_historial.fecha_cambio` (G17 Auditoría)
- `tests/suite.php`: comentario G16 mislabeled "G14"; header actualizado con G14b–G22
- `app/views/nav.php`: CSS global `html { overflow-x:hidden; max-width:100% }` y `box-sizing:border-box` — fix mobile overflow en todos los módulos
- `productos/analisis.php`: `.section` con `overflow-x:auto` para tablas en análisis
- `ayuda/index.php`: migraciones 032-034 corregidas de PENDIENTE → APLICADA; badge v4.25
- `app/models/NominaModel.php`: docblock duplicado en `registrar_horas()` eliminado
- `app/models/CompraModel.php`: `editar()` ahora detecta migración 034 y captura `nombre_snap`/`unidad_snap` al re-insertar `compra_detalles` (igual que `crear()`)

**✅ Auditoría exhaustiva completada (2026-06-06):**
- Revisados los 82 archivos PHP; todos tienen `auth_check` + `csrf_verificar()` + `permiso_requerir()`
- Todos los `ORDER BY` dinámicos pasan por `match` con valores hardcodeados (sin inyección SQL)
- `activos/api/subir_foto.php`: validación MIME via `finfo`, nombres generados, `.htaccess` en uploads
- `admin/api/usuario_crud.php`: bcrypt cost 12, email normalizado, no puede cambiar propio rol
- `ventas/api/cambiar_estado.php`: transacción atómica + detección migración 034 correcta
- `ventas/api/editar_venta.php`: detección migración 034 + reversa de stock completa

**✅ Auditoría XSS completada (sesión 2026-06-06 continuación):**
- Escaneados los 82 archivos en busca de `<?= $var ?>` sin `htmlspecialchars()`
- **17 echoes sin escapar corregidos** en 14 archivos: `lista_compras.php`, `compras.php`,
  `operativo.php`, `reportes/compras.php`, `costos/index.php`, `reportes/costos.php`,
  `reportes/precios.php`, `nomina/parametros.php`, `productos/index.php`, `productos/analisis.php`,
  `reportes/ventas.php`, `ventas/historial.php`, `inventario/index.php`, `proveedores/index.php`,
  `activos/index.php`
- **Whitelist de modelo añadida** en `CostoIndirectoModel` (`tipo`, `frecuencia`) y
  `NominaModel` (`tipo_contrato`) — campos que llegaban sin validar a la BD
- `admin/api/lista_crud.php`: valor de catálogo validado con `/^[a-z0-9_]+$/` (solo alfanumérico)
- `admin/backup.php`: path traversal prevention en ZIP upload, archivos config protegidos
- `AuthHelper.php`: bcrypt, CSRF hash_equals, rate limiting, session httponly+samesite=Lax

**✅ Fase 2 Responsive completada (sesión 2026-06-06 continuación):**
- Auditados todos los 33 archivos PHP con vistas — solo `productos/produccion.php` carecía de `@media`
- `productos/produccion.php`: añadidos `@media (max-width:639px)` y `@media (max-width:479px)`;
  `hide-xs` en columnas Costo/u, Registrado por y Notas; font sizes reducidos; stock-grid 2 cols en xs
- Misma página: fix XSS menor — `htmlspecialchars()` en badge `estado` (clase CSS + texto)
- Eliminado `public_html/public_html.zip` del repo: archivo de respaldo expuesto en la web (seguridad)
- Fase 3 (Tests): suite verificada — 22 grupos, ~130 pruebas, cubre hasta mig. 034, sin gaps
- Fase 4 (Ayuda): `ayuda/index.php` verificado — ya documenta todas las funciones incluyendo 032-034

---

### Estado al cierre de sesión 2026-06-06 (v4.30)

**✅ v4.3 Variantes de tamaño completada:**

| Archivo | Cambio |
|---------|--------|
| `database/migrations/035_variantes_producto.sql` | Nueva tabla `producto_variantes`; ALTER `venta_detalles` con `variante_id`, `variante_etiqueta`, `factor_receta_snap` |
| `public_html/productos/api/variantes_crud.php` | CRUD completo: GET lista, POST guardar/eliminar/reactivar |
| `public_html/app/models/VentaModel.php` | `crear()` detecta mig.035, descuenta insumos × `factor_receta`; `anular()` restaura con `COALESCE(factor_receta_snap, 1.0)` |
| `public_html/productos/index.php` | Sección "Variantes" en fila expandida: CRUD inline, Promise.all para cargar en paralelo |
| `public_html/ventas/index.php` | `VARIANTES` map PHP+JS; overlay `.variante-overlay` con picker de tamaño; `_agregarItem` acepta variante_id/etiq/factor; carrito key incluye variante |
| `public_html/ventas/api/procesar_venta.php` | Valida `factor_receta` (0.001–10) por ítem |
| `public_html/ventas/api/detalle_venta.php` | Detecta mig.035; incluye `variante_etiqueta` en SELECT |
| `public_html/ventas/historial.php` | Badge azul `variante_etiqueta` en detalle expandido |
| `public_html/reportes/ventas.php` | Query "ventas por variante"; card HTML + hoja Excel "Por Variante" (solo si hay datos) |
| `public_html/tests/suite.php` | G23 (6 tests): tabla, columnas, factor rango, precio positivo, sin duplicados, coherencia NULL |
| `public_html/app/config/app.php` | APP_VERSION → 4.30 |

**Flujo en el POS:**
1. Tocar producto con variantes → abre `.variante-overlay` con botones XL / Regular / Familiar
2. Seleccionar variante → si también tiene combo, abre combo picker; si no, agrega al carrito
3. Carrito muestra badge azul con la etiqueta de variante
4. Al confirmar: `variante_id`, `variante_etiqueta`, `factor_receta` viajan en cada ítem del JSON
5. `VentaModel::crear()` usa `factor_receta` para escalar el descuento de insumos

**✅ Completado en continuación:**
- `ayuda/index.php`: sección "Variantes de tamaño" con fórmulas, flujo POS, tabla campos, nota inmutabilidad; badge v4.30; G23 en tabla de tests; `producto_variantes` en tabla de DB
- `database/schema.sql`: tabla `producto_variantes`; columnas `variante_id/variante_etiqueta/factor_receta_snap` en `venta_detalles`; DROP TABLE añadido; contador 28 tablas; versión v4.30

---

## 20. MAPA DE MÓDULOS — OBJETIVOS, INTERDEPENDENCIAS Y ROLES (v4.93)

> Vista cruzada de los 12 módulos: para qué existe cada uno, qué nivel de permiso desbloquea
> qué, y qué Model lee/escribe datos "de otro módulo". Complementa §3 (navegación), §4 (BD),
> §5-§12/§19 (funcionalidad detallada) y la tabla `permisos_modulos` (§4).

### 20.1 Objetivo y casos de uso por módulo

| Módulo | Objetivo | Casos de uso principales | Ref. |
|---|---|---|---|
| **Ventas** (POS) | Registrar ventas en el punto de venta, controlar turnos de caja y mantener el stock de productos sincronizado. | Procesar venta (efectivo/transferencia/fiado, con descuento %, combo y variantes); abrir/cerrar turno de caja; anular venta (revierte stock/insumos/saldo); ver historial filtrable. | §9 |
| **Clientes** | Gestionar la cartera de clientes y su deuda fiado. | Alta/edición de cliente; registrar abono a fiado; ver estado de cuenta/historial; fusionar duplicados (transfiere ventas/abonos/saldo); activar/desactivar. | `clientes/index.php` (docblock) |
| **Inventario** | Mantener el catálogo de insumos (materia prima): stock, costo, unidades, equivalencias y presentaciones de compra. | CRUD insumos; ajustar stock (entradas/salidas manuales con motivo); conteo físico periódico; definir presentaciones catalogadas y equivalencias (mig. 039, v4.85-v4.86). | §19, §4 |
| **Proveedores** | Catálogo de proveedores vinculados a insumos y compras. | CRUD proveedor; activar/desactivar; ver cantidad de insumos asociados. | §4 |
| **Compras** | Registrar compras de insumos a proveedores, actualizando `costo_actual`/`stock_actual` y propagando el costo a `productos.costo_calculado`. | Registrar compra multi-línea (con presentaciones catalogadas); duplicar compra propia; editar/eliminar cualquier compra; ver historial filtrable y "Lista de compras" sugerida (insumos bajo `stock_seguridad`). | §19 |
| **Productos + Producción** | Definir el menú vendible: recetas (consumo de insumos), variantes/combos, costo calculado y producción de lotes. | CRUD producto/receta (con conversión de equivalencias v4.85); **editar cantidades inline + copiar/combinar receta de otros productos con % v4.98**; **tab "Constructor de recetas" (producto+rinde+ingredientes; traer/combinar de varios productos) v4.99**; configurar combo y variantes; **descontinuar/reactivar producto (toggle `activo`): los descontinuados se ocultan del POS y de todos los selectores, pero se conservan y siguen en el Catálogo para reactivar**; producir lote (descuenta insumos, snapshot de `costo_unitario`); análisis de rentabilidad; obsequiar/desechar stock; consolidar productos duplicados (superadmin). | §7 |
| **Nómina** | Liquidar nómina por tipo de contrato (Ley 2101/2021), registrar horas y parámetros laborales legales. | CRUD empleados; registrar horas (recargos festivos/nocturnos); generar liquidaciones del período (snapshot inmutable); eliminar período; configurar parámetros laborales. | §6 |
| **Activos** | Inventario de activos fijos con depreciación mensual automática (mig. 017/018) que alimenta el costeo. | CRUD activo (depreciación automática); duplicar; subir foto; activar/desactivar; alerta de garantías por vencer en dashboard. | §5 |
| **Costos** | Registrar costos indirectos (arriendo, servicios, etc.) como períodos de vigencia inmutables y mostrar KPIs de costeo del período. | CRUD costo indirecto (cada cambio de valor = fila/período nuevo, §14); ver KPIs del período (costo fijo total, depreciación, nómina). | §8 |
| **Reportes** | Vistas agregadas/históricas de solo lectura (ventas, compras, nómina, costos, operación, precios), respetando los snapshots inmutables (§14). | Filtrar por mes/año; exportar a Excel; ver solo "propias" si el usuario tiene `solo_propios` en el módulo fuente del reporte. | §12 |
| **Admin** | Configuración global: usuarios y permisos, apariencia/tema, catálogos (`listas_sistema`), respaldo de BD. Solo roles `admin`/`superadmin`. | Crear usuario y asignar permisos por módulo; cambiar tema/logo/formato numérico (Apariencia); editar catálogos (categorías, unidades); exportar/restaurar backup de BD. | §10 |
| **Ayuda** | Documentación in-app de cada módulo, la matriz de permisos y los reportes. Visible para cualquier usuario autenticado. | Consultar cómo funciona cada módulo/permiso/reporte. | §11 |

### 20.2 Matriz de permisos — qué desbloquea cada nivel, por módulo

Jerarquía (§4): `sin_acceso(0) < solo_ver(1) < solo_propios(2) < editar_existentes(3) < admin_total(4)`.
Cada nivel incluye lo de los niveles inferiores. Solo se listan los 9 módulos del ENUM
`permisos_modulos.modulo`; Clientes/Admin/Ayuda y otros casos especiales están en §20.3.

| Módulo | `solo_ver` | `solo_propios` | `editar_existentes` | `admin_total` |
|---|---|---|---|---|
| **ventas** | Ver historial y detalle de venta; ver listado de Clientes (permiso compartido) | Crear venta (POS), abrir turno de caja — filtra historial/reportes a "mis ventas" (`created_by`) | Editar venta, abono a fiado, aplicar descuento %, CRUD/fusionar Clientes, exportar Clientes a Excel | Anular venta (revierte stock/insumos/saldo del cliente); activar/desactivar Cliente |
| **inventario** | Listar insumos, ver stock | *(no usado)* | CRUD insumos, ajustar stock, conteo físico, presentaciones catalogadas, eliminar insumo | *(no usado — el tope real del módulo es `editar_existentes`)* |
| **proveedores** | Listar proveedores | *(no usado)* | CRUD proveedor | Activar/desactivar proveedor |
| **compras** | *(no usado — el mínimo de acceso a la página es `solo_propios`)* | Acceso a `compras.php`/`lista_compras.php`; crear compra; duplicar compra propia | Editar/eliminar **cualquier** compra (`$puede_editar`) | *(no usado)* |
| **productos** | Listar productos, ver receta/análisis | *(no usado)* | CRUD producto/receta/combo/variantes, obsequiar/desechar stock, producir lote | *(no usado dentro de `productos`)* — "Consolidar productos" exige `admin_total` del módulo **`admin`** (ver §20.3, equivale a superadmin) |
| **nomina** | Ver liquidaciones | *(no usado)* | CRUD empleados, registrar horas, generar liquidaciones | Eliminar período de liquidaciones; activar/desactivar empleado; acceso a sub-tab "Parámetros" |
| **activos** | Ver listado de activos | *(no usado)* | CRUD activo, duplicar, subir foto | Activar/desactivar activo |
| **costos** | Ver KPIs del período | *(no usado)* | CRUD costo indirecto | *(no usado)* |
| **reportes** | Ver `index`/`ventas`/`nomina`/`operativo`/`precios` (`reportes/compras.php` exige `compras:solo_ver` y `reportes/costos.php` exige `costos:solo_ver` — heredan el permiso del módulo fuente) | Reportes filtran a "propias" si el módulo fuente está en `solo_propios` | Exportar a Excel | *(no usado)* |

### 20.3 Casos especiales — fuera de la matriz `permisos_modulos`

| Caso | Mecanismo | Detalle |
|---|---|---|
| **Clientes** | Comparte el permiso de `'ventas'` — no tiene fila propia en `permisos_modulos` | `solo_ver`→ver lista/historial; `editar_existentes`→crear/editar/fusionar; `admin_total`→activar/desactivar (`clientes/api/crud.php:90`) |
| **Admin** | Acceso por **rol** directo (`usuario_rol` IN `['admin','superadmin']`), sin entrada en `permisos_modulos` (`nav.php:130`) | Acceso a todo el módulo Admin (Usuarios, Apariencia, Catálogos, Backup); los 4 endpoints `admin/api/*.php` validan el rol directamente |
| **Ayuda** | Cualquier usuario autenticado (`nav.php:135`) | Solo lectura, documentación in-app |
| **Tarjetas "Rendimiento de Cajeros" / "Productos Más Rentables"** (`dashboard.php`) | Rol directo (`admin`/`superadmin`), **bypasea** `permisos_modulos` | Un empleado con `admin_total` en ventas/productos NO las ve — dato financiero/de personal sensible (intencional) |
| **"Consolidar productos"** (`productos/index.php:264`, `productos/consolidar.php:19`) | `permiso_tiene('admin','admin_total')` — `'admin'` **no** es un módulo del ENUM `permisos_modulos`, así que solo `superadmin` lo obtiene automáticamente (§4: "los superadmin siempre retornan `admin_total` sin consultar la DB") | Efectivamente restringido a **superadmin**, aunque la funcionalidad vive dentro del módulo Productos — un admin con `admin_total` en `productos` NO ve este botón |

### 20.4 Interdependencias entre módulos (Models y tablas compartidas)

Los 9 Models en `app/models/`: `VentaModel`, `CompraModel`, `InsumoModel`, `PresentacionModel`,
`RecetaModel`, `NominaModel`, `ActivoModel`, `CostoIndirectoModel`, `ClienteModel`. Productos no
tiene Model propio — su CRUD vive en `productos/api/*.php` y delega cálculo de costos en
`RecetaModel`.

- **VentaModel** (ventas) → **lee** `productos`, `recetas`, `insumos`, `insumo_presentaciones`,
  `producto_variantes`, `combo_configs`/`combo_insumos` (para descontar stock/insumos al
  vender) y `clientes` (validar/actualizar `saldo_fiado`); **escribe** `ventas`,
  `venta_detalles`, `ajustes_stock` (insumos en modo demanda), `pagos_fiado` (abonos, snapshot
  saldo ant/post), `logs_historial`.
- **CompraModel** (compras) → **lee** `proveedores` (validación FK); **escribe** `compras`,
  `compra_detalles` (con snapshots `nombre_snap`/`unidad_snap`/`presentacion*`), y
  `insumos.costo_actual`/`stock_actual` — este último dispara el recálculo de
  `productos.costo_calculado` (vía `RecetaModel`) para todos los productos que usan ese insumo.
- **RecetaModel** (productos) → **lee** `insumos` (costo_actual, equiv_cantidad) e
  `insumo_presentaciones` (conversión receta↔presentación, v4.85); **escribe** `recetas`,
  `producto_variantes`, `productos.costo_calculado`.
- **InsumoModel** / **PresentacionModel** (inventario) → **leen** `listas_sistema` (catálogos de
  categoría/unidad/presentación); **escriben** `insumos`, `insumo_presentaciones`. Usados por
  **Compras** (selección de presentación al comprar) y **Productos** (conversión en recetas).
- **ClienteModel** (clientes, comparte permiso `ventas`) → **lee** `ventas`, `venta_detalles`,
  `pagos_fiado`; **escribe** `clientes`, `pagos_fiado`, `logs_historial`.
- **NominaModel** (nómina) → **lee** `empleados`, `registro_horas`, `parametros_laborales`
  (o `configuracion_negocio` como fallback); **escribe** `nomina_liquidaciones` (snapshots
  `valor_hora_snap`/`valor_proyecto_snap`).
- **ActivoModel** (activos) → autocontenido (`activos`), pero `depreciacion_mensual` (trigger
  mig. 018) alimenta el KPI "Depreciación de activos" en **Costos** (`costos/index.php`) y el
  costo fijo por unidad en **Productos** (`productos/analisis.php`).
- **CostoIndirectoModel** (costos) → autocontenido (`costos_indirectos`), pero
  `SUM(costos_indirectos.valor)` por período alimenta `costo_fijo_u` en
  **Productos/análisis** y el reporte `reportes/costos.php`.
- **Reportes** no tiene Model propio: cada `reportes/*.php` consulta directamente `ventas`,
  `compras`, `nomina_liquidaciones`, `activos`, `costos_indirectos`, `produccion_lotes`, etc.
  — es la capa que agrega datos de TODOS los demás módulos.

**Implicación práctica**: una migración o bug en `insumos`/`insumo_presentaciones` (Inventario)
puede afectar en cascada a Compras (costo de línea), Productos (`costo_calculado`,
`análisis`/rentabilidad), Ventas (descuento de stock al vender) y Reportes (todos los reportes
de costos/rentabilidad). De igual forma, `activos` y `costos_indirectos` no tienen escritura
cruzada, pero sus KPIs se LEEN desde Costos, Productos/análisis y Reportes — un cambio en sus
fórmulas de cálculo (depreciación, vigencia) debe revisarse en esos 3 consumidores.

---

## Estado v4.40 (2026-06-06)

### Cambios implementados en esta sesión

| Archivo | Cambio |
|---------|--------|
| `database/migrations/036_receta_ingrediente_base.sql` | ALTER TABLE recetas ADD COLUMN es_base TINYINT DEFAULT 0 |
| `database/schema.sql` | Columna `es_base` en tabla `recetas`; versión v4.40 |
| `public_html/app/models/RecetaModel.php` | `ingredientes_de()` detecta mig.036; devuelve `es_base` |
| `public_html/app/models/VentaModel.php` | `crear()` y `anular()` detectan mig.036; aplican `factor=1.0` para ingredientes base |
| `public_html/ventas/api/editar_venta.php` | Paso 2b (reversal) y stmtReceta detectan mig.036; factor=1.0 para ingredientes base |
| `public_html/productos/api/guardar_receta.php` | Acepta `es_base` POST param; detecta mig.036; incluye en INSERT/UPDATE |
| `public_html/productos/index.php` | Badge 🔒base, botón toggle `toggleBase()`, checkbox en form agregar |
| `public_html/tests/suite.php` | G24: 4 pruebas para mig.036 (es_base existe, rango 0/1, no crítico+base, escalabilidad) |
| `public_html/ayuda/index.php` | Sección "Ingrediente base — migración 036" con tabla, fórmula, limitaciones |
| `public_html/app/config/app.php` | APP_VERSION → 4.40 |

### Concepto clave: ingrediente base

- `es_base = 0` (defecto): ingrediente escala con `factor_receta` de la variante. Ej: pollo → 150g × 1.5 = 225g para XL.
- `es_base = 1`: ingrediente fijo, no escala. Ej: pan → siempre 1 unidad sin importar si es Regular o XL.
- Aplica en `VentaModel::crear()`, `VentaModel::anular()`, y `editar_venta.php` paso 2b.
- Backward-compatible: DEFAULT 0 → todas las recetas existentes se comportan igual que antes.

### Limitación conocida (aceptada)
Si `es_base` se cambia en una receta después de realizar ventas, la restauración de stock en ventas antiguas usará el valor actual, no el histórico (no hay snapshot de `es_base` en `venta_detalles`). Configurar antes de comenzar a vender.

---

## Estado v4.41 (2026-06-06)

### Cambios implementados en esta sesión

| Archivo | Cambio |
|---------|--------|
| `public_html/productos/produccion.php` | Panel `<details>` colapsable "Sugerencia de producción — últimos 14 días": promedio ventas/día, stock actual, cuánto producir, variante más vendida |
| `public_html/app/config/app.php` | APP_VERSION → 4.41 |

### Lógica de la sugerencia
- Promedio = `SUM(cantidad_vendida) / 14` (últimos 14 días, excluyendo hoy)
- Sugerido = `max(0, ceil(promedio) - stock_actual)`
- Solo aparecen productos con historial de ventas en el período
- Si mig.035 existe, muestra variante más vendida (la de mayor volumen) con % del total
- Panel abierto automáticamente cuando `total_sugerido > 0` o es día actual
- Ordenado: mayor sugerido primero, desempate por mayor promedio

---

## Estado v4.42 (2026-06-06)

### Cambios implementados en esta sesión

| Archivo | Cambio |
|---------|--------|
| `public_html/productos/consolidar.php` | Wizard 3 pasos: selección → preview → ejecutar. Requiere mig.035. |
| `public_html/productos/index.php` | Botón "🔀 Consolidar productos" (solo admin_total) en acceso rápido |
| `public_html/app/config/app.php` | APP_VERSION → 4.42 |

### Flujo de la consolidación
1. **Paso 1 (GET)**: Dos columnas — seleccionar producto base (radio) + productos a absorber (checkboxes). JS deshabilita el producto base de la lista de fuentes.
2. **Paso 2 (POST preview)**: Tabla con etiqueta (input, default=tamano), precio (input, default=precio_venta), factor (calculado automáticamente del ingrediente crítico: `qty_fuente / qty_base`, o 1.0 si no hay receta comparable). Badge "calculado" o "manual".
3. **Paso 3 (POST ejecutar)**: Transacción PDO: INSERT en producto_variantes + UPDATE activo=0 en fuentes + opcional transferencia de stock + log_registrar auditoría.

### Invariantes preservados
- Historial de ventas NUNCA se modifica (IDs originales intactos)
- Si mig.035 no está aplicada, la página muestra error y no permite continuar
- Factor calculado = `qty_critica(fuente) / qty_critica(base)` usando el ingrediente es_insumo_critico=1
- ON DUPLICATE KEY UPDATE en INSERT de variante: si la etiqueta ya existe en ese producto, actualiza precio/factor

---

## Estado v4.43 (2026-06-06)

### Cambios implementados en esta sesión

| Archivo | Cambio |
|---------|--------|
| `public_html/ventas/cierre.php` | Nueva página: cierre de caja diario, selector de fecha, print-ready |
| `public_html/ventas/historial.php` | Botón "🧾 Cierre de caja" en el header junto al "Ir al POS" |
| `public_html/dashboard.php` | Tarjeta "Ventas hoy" clickeable → cierre.php |
| `public_html/app/config/app.php` | APP_VERSION → 4.43 |

### Funcionalidad de cierre.php
- Detecta mig.034 (nombre_snap) y mig.035 (variante_etiqueta) para queries adaptivas
- Resumen por método de pago: cards individuales con total y número de ventas
- Panel totales oscuro: cobrado, fiado pendiente, obsequios, total del día
- Detalle por producto: agrupado por producto_id + variante, con badges de variante inline
- Lista de fiados del día con estado (pagado / pendiente)
- Contador de ventas anuladas (excluidas del total, informativas)
- Botón "🖨 Imprimir" con CSS @media print (oculta nav, selector fecha, botones)
- Selector de fecha con `max=hoy` para navegar días anteriores

---

## Estado v4.44 (2026-06-06)

### Cambios implementados en esta sesión

| Archivo | Cambio |
|---------|--------|
| `public_html/ventas/cierre.php` | WhatsApp share: `$txt_share` PHP, botón verde con icono, JS `compartir()` con `navigator.share()` + fallback `wa.me` |
| `public_html/productos/produccion.php` | Selector de período 7/14/30 días en el `<summary>` del panel sugerencias; `$dias_analisis` desde GET param validado |
| `public_html/inventario/conteo.php` | **NUEVO**: conteo rápido de stock — grilla de tarjetas por categoría, filtro por categoría+búsqueda, barra flotante, guarda solo los que cambian, atajos teclado (Enter avanza al siguiente) |
| `public_html/inventario/api/conteo_guardar.php` | **NUEVO**: endpoint JSON; acepta `{conteos:[{insumo_id, stock_contado}]}`; transacción atómica; `log_registrar` por cada cambio |
| `public_html/inventario/index.php` | Botón "📋 Conteo rápido" en barra de acciones (solo `editar_existentes`) |
| `public_html/app/config/app.php` | APP_VERSION → 4.44 |
| `CLAUDE.md` | Módulos Inventario y Producción actualizados |

### Funcionalidad conteo.php
- Grilla de tarjetas por categoría con chip-filtro por categoría y buscador instantáneo
- Campos numéricos muestran el stock actual como placeholder
- Solo se guardan los insumos cuyo valor cambió (delta ≠ 0)
- Barra flotante fija: contador "X / N insumos" + botones Limpiar / Guardar
- Tras guardar: actualiza placeholder + badge de estado en DOM sin recargar la página
- Atajo Enter: avanza al siguiente input visible (útil en conteo físico con tablet)
- Endpoint `conteo_guardar.php`: JSON body, CSRF, transacción PDO, `log_registrar` motivo='conteo_rapido'

### Selector 7/14/30 días en produccion.php
- PHP: `$dias_analisis = in_array((int)$_GET['dias'], [7,14,30]) ? (int)$_GET['dias'] : 14`
- HTML: chips circulares dentro del `<summary>` con `onclick="event.stopPropagation()"` para no colapsar el panel al cambiar período
- Link activo: `background:var(--brand);color:#fff`; resto: fondo gris
- Preserva el parámetro `?fecha=X` existente en el link

**Próxima sesión puede continuar desde:**
- Actualizar `ayuda/index.php` con secciones conteo rápido (v4.44) y compartir cierre (WhatsApp)
- Agregar tests G25 para conteo rápido (API endpoint, log_registrar, sin negativos)
- Mejora del consolidar.php: mostrar factores de es_base en el preview de ingredientes

---

## Estado v4.45 (2026-06-06)

### Cambios implementados en esta sesión

| Archivo | Cambio |
|---------|--------|
| `database/migrations/037_turnos_caja.sql` | CREATE TABLE turnos_caja — apertura de turno con fondo inicial |
| `database/schema.sql` | Tabla turnos_caja, DROP IF EXISTS, versión v4.45, 28 tablas |
| `public_html/ventas/apertura.php` | **NUEVO**: registro de apertura de turno, fondo de caja, historial 10 turnos |
| `public_html/ventas/cierre.php` | Panel azul "Fondo apertura + Efectivo cobrado + Total en caja"; alerta si sin turno; botón "🏪 Apertura"; fondo en texto WhatsApp |
| `public_html/dashboard.php` | Tarjeta "Turno de caja" con estado abierto/cerrado/sin apertura (solo si mig.037 aplicada) |
| `public_html/app/config/app.php` | APP_VERSION → 4.45 |
| `CLAUDE.md` | Migración 037, módulo Ventas actualizado |

### Funcionalidad apertura.php
- Detecta mig.037 con `information_schema`; si no está, muestra mensaje y link a Admin → Backup
- Estado del turno actual: verde (abierto), gris (cerrado), amarillo (sin turno hoy)
- KPIs en turno abierto: Fondo inicial · Efectivo cobrado (live) · Total en caja
- Admin/superadmin puede cerrar el turno con notas de cierre
- Historial de los últimos 10 turnos, enlazado con cierre.php de cada fecha
- `log_registrar` en apertura y cierre de turno

### Integración con cierre.php
- Cuando hay turno para la fecha: panel azul oscuro con Fondo apertura, Efectivo cobrado, Total en caja
- Cuando no hay turno (hoy): alerta amarilla con link a apertura.php
- Texto de WhatsApp incluye sección "Fondo inicial + Total en caja" si hay turno

### Dashboard
- Nueva tarjeta "Turno de caja" (visible solo si mig.037 aplicada)
- Verde: ● Abierto + fondo inicial; Gris: Cerrado; Amarillo: Sin apertura
- Click → apertura.php

**Próxima sesión puede continuar desde:**
- Nuevo módulo o mejora funcional — el sistema está completo y estable en v4.45

---

## Estado v4.46 (2026-06-06)

### Cambios implementados en esta sesión

| Archivo | Cambio |
|---------|--------|
| `database/migrations/038_descuento_venta.sql` | ALTER TABLE ventas ADD COLUMN descuento_pct DECIMAL(5,2) DEFAULT 0, descuento_valor DECIMAL(12,2) DEFAULT 0 |
| `database/schema.sql` | Columnas descuento_pct/descuento_valor en ventas; versión v4.46 |
| `public_html/ventas/index.php` | Input descuento (%), gated por editar_existentes; JS actualizarDescuento(); limpiarCarrito() resetea; confirmarVenta() envía descuento_pct |
| `public_html/ventas/api/procesar_venta.php` | Parsea y valida descuento_pct (0-50, clamp, permiso server-side); 8º arg a VentaModel::crear() |
| `public_html/app/models/VentaModel.php` | crear() acepta float $descuento_pct=0.0; detecta mig.038; calcula descuento_valor; INSERT dinámico con columnas opcionales; total = total_bruto − descuento_valor |
| `public_html/ventas/historial.php` | Detecta mig.038; añade v.descuento_pct al SELECT; badge amarillo "−X% dto" en celda total |
| `public_html/ventas/cierre.php` | Sección 6: detecta mig.038, query descuentos del día; nota pie panel totales; línea en texto WhatsApp |
| `public_html/tests/suite.php` | G27 (5 tests): columnas existen, pct 0-50, valor≥0, coherencia pct/valor, total≤bruto |
| `public_html/ayuda/index.php` | Sección "Descuentos en el POS — v4.46"; fila G27 en tabla tests; badge v4.46 |
| `public_html/app/config/app.php` | APP_VERSION → 4.46 |

### Concepto clave: descuento en POS

- El descuento es **financiero** — reduce el total de la venta pero NO las cantidades de insumos/stock.
- `descuento_pct` y `descuento_valor` son **snapshots inmutables** (no se editan después).
- `ventas.total` = suma_bruta_ítems − descuento_valor (el neto real cobrado).
- Solo usuarios con permiso `editar_existentes` o superior pueden aplicar descuentos (validado en el servidor).
- Las ventas con descuento quedan en historial con badge "−X% dto" y en cierre con nota de total descontado.
- La anulación revierte correctamente usando `ventas.total` (el neto) para el saldo fiado.

*Última actualización: 2026-06-06 | v4.46 — descuentos en POS, G27, historial badge, cierre descuentos.*

---

## Estado v4.47 (2026-06-06)

### Cambios implementados en esta sesión

| Archivo | Cambio |
|---------|--------|
| `public_html/reportes/ventas.php` | Detecta mig.038; query descuentos por período; Excel Hoja 1: columnas Desc.%/Desc.$ por fila + fila TOTAL DESCONTADO; nueva hoja "Descuentos" (solo si hay datos); HTML: banner descuentos + badge −X% dto en celda Total |
| `public_html/app/config/app.php` | APP_VERSION → 4.47 |

### Funcionalidad v4.47

- **Excel Hoja 1 (Ventas)**: columnas `Desc. %` y `Desc. $` opcionales (solo si mig.038 aplicada); cada fila con descuento muestra el porcentaje y monto; fila resumen "TOTAL DESCONTADO (N ventas con dto)".
- **Excel Hoja "Descuentos"** (nueva, solo si hay ventas con descuento): `#` | `Fecha` | `Cliente` | `Total Bruto` | `Desc. %` | `Desc. $` | `Total Neto` | `Cajero`; fila totales.
- **HTML banner**: alerta amarilla "N ventas con descuento — total descontado: −$X" (similar al banner de obsequios); menciona la hoja Excel.
- **HTML tabla**: badge amarillo "−X% dto" debajo del Total en las filas con descuento.
- Retrocompatible: si mig.038 no está aplicada, nada cambia.

---

## Estado v4.48 (2026-06-06)

### Cambios implementados en esta sesión

| Archivo | Cambio |
|---------|--------|
| `public_html/dashboard.php` | PHP: lee `meta_ventas_diaria` de `configuracion_negocio` dentro del bloque ventas; calcula `$meta_pct` / `$meta_alcanzada`. CSS: `.meta-card`, `.progress-track`, `.progress-fill`. HTML: nueva sección "Meta del día" con progress bar color-coded (rojo<50%, ámbar 50-79%, verde≥80%) + badge %; admin/superadmin ven botón ✏️ → form inline POST |
| `public_html/admin/api/set_meta_ventas.php` | Nuevo endpoint POST: valida rol (admin/superadmin) + CSRF; `INSERT ... ON DUPLICATE KEY UPDATE` en `configuracion_negocio`; log auditoría; redirige a dashboard |
| `public_html/ventas/cierre.php` | Sección 8: lee `meta_ventas_diaria`; calcula `$meta_pct_c`; muestra badge en panel totales + línea "🎯 Meta:" en texto WhatsApp |
| `public_html/app/config/app.php` | APP_VERSION → 4.48 |

### Funcionalidad v4.48

- **Dashboard progress bar**: tarjeta "Meta del día" visible si hay meta configurada o si el usuario es admin. Muestra `$ventas_hoy / $meta_diaria`, porcentaje con badge color-coded, y barra de progreso animada. Admin ve botón ✏️ que revela un form `<input type="number">` para editar la meta.
- **Sin migración**: `meta_ventas_diaria` se guarda como nueva fila en `configuracion_negocio` (clave/valor existente). INSERT ... ON DUPLICATE KEY UPDATE.
- **cierre.php**: panel de totales muestra "🎯 Meta del día: $X — Z% ✓ ¡Alcanzada!" si la meta está configurada. El texto WhatsApp incluye la línea de meta.
- Retrocompatible: si la clave no existe, no se muestra nada (queries con try/catch).

*Última actualización: 2026-06-06 | v4.48 — meta de ventas diaria con progress bar dashboard y comparación en cierre.*

---

## Estado v4.49 (2026-06-06)

### Cambios implementados en esta sesión

| Archivo | Cambio |
|---------|--------|
| `public_html/dashboard.php` | PHP: query `SELECT DATE(fecha_venta), SUM(total)` de los últimos 7 días (CURDATE()-6) con `FETCH_KEY_PAIR`; genera `$grafico_7d` (array con label, total, hoy) y `$total_7d`. CSS: `.chart-bars`, `.chart-lbls`, `.chart-lbl`, `.chart-hoy`. HTML: tarjeta con barras CSS (`align-items:flex-end`, altura dinámica en px, max 60px), etiquetas Lun-Dom debajo, barra de hoy en rojo brand, tooltip nativo con `title="$X"` |
| `public_html/app/config/app.php` | APP_VERSION → 4.49 |

### Funcionalidad v4.49

- **Gráfico de barras 7 días**: visible para usuarios con acceso a ventas; sin librerías externas (HTML/CSS puro).
- Barra más alta = día con más ventas (escala relativa al máximo de la semana).
- Barra de hoy en rojo `var(--brand)`, días anteriores en gris; hoy en negrita en las etiquetas.
- Días sin ventas → barra invisible (height:0), no se muestra stub.
- Total semanal en el encabezado de la tarjeta.
- Sin migración — usa únicamente la tabla `ventas` existente.

*Última actualización: 2026-06-06 | v4.49 — gráfico de barras ventas últimos 7 días.*

---

## Estado v4.50 (2026-06-06)

### Cambios implementados en esta sesión

| Archivo | Cambio |
|---------|--------|
| `public_html/app/views/icons.php` | Nuevo `IC_CASH` — ícono moneda/dólar (Heroicons outline currency-dollar) |
| `public_html/clientes/index.php` | Botón verde IC_CASH en fila de tabla (solo si `saldo_fiado > 0` y `editar_existentes`); modal "Registrar Pago / Abono" con monto, preview saldo nuevo, método de pago, notas; funciones JS `abrirAbono()`, `actualizarSaldoPreview()`, `guardarAbono()` |
| `public_html/clientes/api/registrar_abono.php` | Nuevo endpoint POST: CSRF + permiso; SELECT FOR UPDATE para evitar carrera; clamp monto ≤ saldo_fiado; INSERT pagos_fiado con saldo_anterior/saldo_posterior; UPDATE clientes.saldo_fiado; log auditoría; JSON response |
| `public_html/app/config/app.php` | APP_VERSION → 4.50 |

### Funcionalidad v4.50

- **Botón abonar**: icono verde 💲 aparece en la fila del cliente solo cuando `saldo_fiado > 0` y el usuario tiene `editar_existentes`. Al hacer clic abre el modal pre-cargado con nombre y deuda actual del cliente.
- **Preview de saldo**: mientras el usuario escribe el monto, el modal muestra en tiempo real cuánto quedará pendiente tras el pago.
- **Seguridad**: SELECT FOR UPDATE previene condición de carrera; el monto se clampea al saldo real aunque el usuario envíe un número mayor.
- **pagos_fiado.saldo_anterior / saldo_posterior**: snapshots inmutables para el estado de cuenta (`estado_cuenta.php` ya los mostraba).
- **Auditoría**: `log_registrar('pagos_fiado', $abono_id, 'abono', ...)`.

*Última actualización: 2026-06-06 | v4.50 — registro de abonos a fiado desde módulo clientes.*

---

## Estado v4.51 (2026-06-06)

### Cambios implementados en esta sesión

| Archivo | Cambio |
|---------|--------|
| `public_html/app/views/icons.php` | Nuevo `IC_WA` — burbuja de chat (Heroicons outline annotation) |
| `public_html/clientes/index.php` | Botón verde `IC_WA` al final de la fila (visible a todos) si `telefono` + `saldo_fiado > 0`; genera URL `wa.me/57XXXXXXXXXX?text=...` con rawurlencode; mensaje pre-escrito con nombre, deuda y APP_NAME |
| `public_html/dashboard.php` | Panel fiados pendientes: sub-línea del teléfono ahora incluye link "WA ↗" verde cuando el cliente tiene teléfono; mismo mensaje pre-escrito; genera URL en PHP con rawurlencode |
| `public_html/app/config/app.php` | APP_VERSION → 4.51 |

### Funcionalidad v4.51

- **Botón WA en clientes**: ícono burbuja de chat verde en cada fila con `saldo_fiado > 0` y `telefono` registrado. Sin permiso especial (es solo un enlace externo). Abre WhatsApp con mensaje: _"Hola [nombre], te recordamos que tienes un saldo pendiente de $X en [negocio]. ¿Cuándo podemos acordar el pago? ¡Gracias! 🙏"_
- **Link WA en dashboard**: el panel "Fiados pendientes" muestra "📞 [tel] · WA ↗" en verde para los clientes con teléfono; al hacer clic abre WhatsApp con el mismo mensaje pre-escrito.
- **Normalización de teléfono**: si el número tiene 10 dígitos y empieza en `3` → prefijo `57` (Colombia). Cualquier otro número se usa tal cual.
- Sin nueva API ni migración.

*Última actualización: 2026-06-06 | v4.51 — recordatorio de pago por WhatsApp desde clientes y dashboard.*

---

## Estado v4.52 (2026-06-06)

### Cambios implementados en esta sesión

| Archivo | Cambio |
|---------|--------|
| `public_html/clientes/exportar.php` | Nuevo: genera Excel con todos los clientes ordenados por saldo_fiado DESC; columnas: #, Nombre, Apellido, Empresa, Teléfono, Estado, Deuda Fiado, Total Ventas, Última Compra; fila TOTALES al final |
| `public_html/clientes/index.php` | Botón "📊 Excel" verde (`.btn-excel`) en actions-bar, solo para `editar_existentes`; agrega `.btn-excel` al CSS |
| `public_html/reportes/index.php` | Nueva tarjeta "Clientes & Deudas Fiado" en el hub de reportes (enlaza a `clientes/exportar.php`, requiere módulo `ventas`) |
| `public_html/app/config/app.php` | APP_VERSION → 4.52 |

### Funcionalidad v4.52

- **Excel de clientes**: descarga inmediata (sin pantalla previa) con todos los clientes ordenados por mayor deuda primero. Útil para el contador o para hacer gestión de cobros.
- **Fila de totales**: al final muestra "N clientes · M con deuda", total fiado pendiente y total ventas históricas.
- Acceso desde el botón verde en `clientes/index.php` y desde `reportes/index.php`.
- Requiere `ventas:editar_existentes` — protege datos financieros y de contacto de los clientes.

*Última actualización: 2026-06-06 | v4.52 — exportar clientes a Excel.*

---

## Estado v4.53 (2026-06-06)

### Cambios implementados en esta sesión

| Archivo | Cambio |
|---------|--------|
| `public_html/ventas/fiado.php` | Modernizado: el formulario de abono ahora envía vía AJAX a `clientes/api/registrar_abono.php` (con SELECT FOR UPDATE y snapshots de saldo) en lugar del antiguo `ClienteModel::registrar_abono()`; agrega campo de notas, preview de saldo en tiempo real, botón "Abonar" con `IC_CASH`, enlace "Recordar" por WhatsApp (`IC_WA`) junto al botón "Extracto"; agrega toast de confirmación |
| `public_html/tests/suite.php` | Nuevo grupo G28 "Abonos a Fiado": valida columnas snapshot (mig.034), coherencia aritmética saldo_anterior/posterior, montos y métodos de pago válidos, límite de notas y alerta de desfase de saldo por concurrencia |
| `public_html/app/config/app.php` | APP_VERSION → 4.53 |

### Funcionalidad v4.53

- **Abonos consistentes**: `ventas/fiado.php` ahora usa el mismo endpoint AJAX que `clientes/index.php` (v4.50), con bloqueo `SELECT ... FOR UPDATE` para evitar condiciones de carrera y registro de `saldo_anterior`/`saldo_posterior` en `pagos_fiado` para auditoría.
- **Notas en abonos**: campo opcional de hasta 255 caracteres (ej. "pago parcial en efectivo").
- **Preview de saldo**: al escribir el monto se muestra "Saldo actual → Nuevo saldo" en tiempo real.
- **Recordatorio WhatsApp**: enlace verde `wa.me` junto a cada cliente con teléfono y deuda, mismo mensaje y normalización de número (Colombia, prefijo 57) que en `clientes/index.php` y el dashboard.
- El antiguo `ClienteModel::registrar_abono()` queda sin usar desde la UI (se mantiene por compatibilidad con pruebas existentes que lo invocan directamente).
- **Tests G28 (nuevo)**: agregado a `tests/suite.php` — valida que `pagos_fiado` tenga las columnas snapshot de la migración 034, que `monto`/`saldo_anterior`/`saldo_posterior` sean coherentes y no negativos, que `saldo_posterior = saldo_anterior − monto`, que `metodo_pago` esté en el catálogo válido, que `notas` no exceda 255 caracteres, y una alerta (warning) si el `saldo_fiado` actual de un cliente quedó por debajo de lo esperado tras su último abono (señal de condición de carrera).

*Última actualización: 2026-06-06 | v4.53 — modernizar ventas/fiado.php con abonos AJAX, notas, recordatorio WhatsApp y tests G28.*

---

## Estado v4.54 (2026-06-06)

### Cambios implementados en esta sesión

| Archivo | Cambio |
|---------|--------|
| `public_html/proveedores/index.php` | Agrega enlace verde "WA" (`IC_WA`) junto al teléfono de cada proveedor en el directorio — abre chat de WhatsApp directo (sin mensaje predefinido, a diferencia del recordatorio de fiado); usa la misma normalización de número colombiano (10 dígitos que empiezan en 3 → prefijo 57) que `clientes/index.php` (v4.51) |
| `public_html/app/config/app.php` | APP_VERSION → 4.54 |

### Funcionalidad v4.54

- **Contacto rápido por WhatsApp con proveedores**: desde el directorio (`proveedores/index.php`), cada tarjeta con teléfono registrado muestra un enlace "WA" que abre directamente una conversación de WhatsApp con ese proveedor — útil para consultar disponibilidad, negociar precios o coordinar entregas sin salir del ERP.
- A diferencia del recordatorio de pago a clientes (v4.51), este enlace **no** incluye un mensaje predefinido — es solo un acceso directo al chat, ya que el contexto de cada conversación con proveedores varía (pedido, cotización, reclamo, etc.).
- No requiere migración: `proveedores.telefono` ya existía en el esquema original.

*Última actualización: 2026-06-06 | v4.54 — contacto rápido por WhatsApp en directorio de proveedores.*

---

## Estado v4.55 (2026-06-06)

### Cambios implementados en esta sesión

| Archivo | Cambio |
|---------|--------|
| `public_html/dashboard.php` | La consulta de "insumos bajos / agotados" ahora hace `LEFT JOIN proveedores` (vía `insumos.proveedor_id`) trayendo `proveedor_nombre`/`proveedor_telefono`; en cada ítem de la alerta se agrega un enlace "📦 Pedir a [Proveedor] ↗" que abre WhatsApp con un mensaje pre-armado pidiendo restock del insumo específico (nombre, nivel agotado/bajo, stock actual y unidad) |

### Funcionalidad v4.55

- **Pedido rápido al proveedor desde la alerta de stock bajo**: cuando un insumo cae bajo su stock de seguridad y tiene un proveedor activo con teléfono registrado, el panel de alertas del dashboard muestra un enlace directo de WhatsApp con un mensaje ya redactado (nombre del insumo en negrita, si está agotado o bajo, cantidad actual y unidad) — se ahorra el paso de buscar el proveedor y escribir el mensaje manualmente.
- Cierra el círculo de las funciones de WhatsApp introducidas en v4.51 (recordatorio a clientes) y v4.54 (contacto con proveedores): ahora la alerta operativa más urgente del negocio (quedarse sin insumos) tiene una acción de un clic para resolverla.
- Si el insumo no tiene proveedor asociado o el proveedor no tiene teléfono válido, simplemente no se muestra el enlace (sin romper el layout).

*Última actualización: 2026-06-06 | v4.55 — pedido rápido por WhatsApp al proveedor desde alerta de insumos bajos.*

---

## Estado v4.56 (2026-06-06)

### Cambios implementados en esta sesión

| Archivo | Cambio |
|---------|--------|
| `public_html/reportes/ventas.php` | Agrega consulta de `pagos_fiado` filtrada por el rango de fechas del reporte (con detección de mig. 034 para columnas `saldo_anterior`/`saldo_posterior`); banner verde "💰 N abonos a fiado recibidos — total recaudado: $X" junto a los de obsequios/descuentos; nueva hoja "Abonos a Fiado" en el Excel exportado con columnas #, Fecha, Cliente, Monto, Método, Saldo Antes/Después, Notas, Registrado por, y fila TOTAL RECAUDADO |
| `public_html/app/config/app.php` | APP_VERSION → 4.56 |

### Funcionalidad v4.56

- **Trazabilidad de cobranza**: el reporte de ventas (`reportes/ventas.php`) ahora también informa cuánto se recaudó en abonos a fiado durante el período filtrado — útil para que el dueño vea de un vistazo no solo lo vendido sino lo efectivamente cobrado de deudas pendientes.
- **Hoja "Abonos a Fiado" en Excel**: lista cada abono con cliente, monto, método de pago, saldo antes/después (snapshot de auditoría de la mig. 034) y quién lo registró — complementa la hoja "Descuentos" ya existente y reutiliza el mismo patrón de hojas condicionales.
- Sigue el mismo patrón visual que los banners de obsequios (v4.4x) y descuentos (v4.47): aparece solo si hay abonos en el rango seleccionado, sin alterar el layout cuando no los hay.
- No requiere migración nueva — usa las columnas de snapshot ya agregadas en la migración 034 y validadas por los tests G28 (v4.53).

*Última actualización: 2026-06-06 | v4.56 — hoja "Abonos a Fiado" en el reporte de ventas + banner de recaudo del período.*

---

## Estado v4.57 (2026-06-06)

### Cambios implementados en esta sesión

| Archivo | Cambio |
|---------|--------|
| `public_html/dashboard.php` | Nueva consulta `$top_clientes`: top 5 clientes del mes en curso por monto comprado (excluye obsequios y ventas de mostrador, agrupado por cliente); nueva tarjeta `.meta-card` "🏆 Top Clientes del Mes" entre el gráfico de 7 días y el panel de alertas, con medallas 🥇🥈🥉, monto comprado, # de compras y enlace "🎉 Agradecer" por WhatsApp con mensaje de fidelización pre-armado; mapeo `$meses_es` para mostrar el nombre del mes en español |
| `public_html/app/config/app.php` | APP_VERSION → 4.57 |

### Funcionalidad v4.57

- **Reconocimiento a los mejores clientes**: nueva tarjeta en el dashboard que muestra los 5 clientes que más compraron en el mes en curso (suma de `ventas.total`, excluyendo obsequios y ventas sin cliente asociado), con medallas para el top 3.
- **WhatsApp de fidelización**: a diferencia de los enlaces de WA anteriores (recordatorio de deuda v4.51, contacto a proveedor v4.54, pedido de restock v4.55 — todos orientados a cobranza/operación), este es el primero **orientado a relación con el cliente**: un mensaje de agradecimiento pre-armado para fortalecer la fidelidad.
- Solo se muestra si hay compras registradas con cliente identificado en el mes — no rompe el layout si el negocio recién empieza o no tiene clientes frecuentes.
- No requiere migración: usa `ventas.cliente_id`, `ventas.total` y `clientes.telefono` ya existentes.

*Última actualización: 2026-06-06 | v4.57 — tarjeta "Top Clientes del Mes" en el dashboard con agradecimiento por WhatsApp.*

---

## Estado v4.58 (2026-06-06)

### Cambios implementados en esta sesión

| Archivo | Cambio |
|---------|--------|
| `public_html/dashboard.php` | Nueva consulta `$top_productos`: top 5 productos del mes en curso por unidades vendidas (`venta_detalles` JOIN `ventas`/`productos`, excluye obsequios y ventas sin completar); nueva tarjeta `.meta-card` "🥪 Productos Más Vendidos" justo debajo de "Top Clientes del Mes", con medallas 🥇🥈🥉, unidades vendidas, monto generado y barra de progreso relativa al producto líder; `$meses_es` (mapeo de meses en español) movido del HTML al bloque PHP de consultas para reutilizarlo en ambas tarjetas |

### Funcionalidad v4.58

- **Ranking de productos**: nueva tarjeta en el dashboard que muestra los 5 productos más vendidos (por unidades) en el mes en curso, con su monto total generado — complementa la tarjeta "Top Clientes del Mes" (v4.57) dando visibilidad del lado de la oferta además de la demanda.
- **Barra de progreso relativa**: cada producto muestra una barra cuya longitud es proporcional a sus unidades vendidas respecto al producto líder del mes — permite ver de un vistazo qué tan dominante es el producto #1 frente al resto.
- **Subtítulo `nombre2`**: si el producto tiene subtítulo configurado (mig. 027), se muestra junto al nombre — igual que en POS, producción e historial.
- Solo se muestra si hay ventas de productos registradas en el mes — no rompe el layout si el negocio recién empieza.
- No requiere migración: usa `venta_detalles.cantidad/subtotal`, `ventas.fecha_venta/estado/metodo_pago` y `productos.nombre/nombre2` ya existentes.
- Útil para decisiones de producción: el dueño puede priorizar qué sándwiches preparar primero según la demanda real del mes.

*Última actualización: 2026-06-06 | v4.58 — tarjeta "Productos Más Vendidos del Mes" en el dashboard.*

---

## Estado v4.59 (2026-06-06)

### Cambios implementados en esta sesión

| Archivo | Cambio |
|---------|--------|
| `public_html/dashboard.php` | Nueva consulta `$top_cajeros`: ranking del mes en curso por usuario que registró la venta (`ventas.created_by` JOIN `usuarios`), con número de ventas, total vendido y ticket promedio — **solo se ejecuta y se muestra para roles `admin`/`superadmin`**; nueva tarjeta `.meta-card` "👤 Rendimiento de Cajeros" debajo de "Productos Más Vendidos", con medallas 🥇🥈🥉, barra de progreso relativa al líder y nota "🔒 Visible solo para administradores" |

### Funcionalidad v4.59

- **Visibilidad de desempeño del personal**: el dueño/administrador puede ver de un vistazo qué empleado generó más ventas en el mes — útil para reconocimientos, ajustes de turnos o detectar quién necesita más apoyo/capacitación.
- **Restricción de acceso intencional**: a diferencia de "Top Clientes" (v4.57) y "Productos Más Vendidos" (v4.58), que son visibles para cualquiera con acceso a ventas, esta tarjeta **solo se consulta y renderiza si `$_SESSION['usuario_rol']` es `admin` o `superadmin`** — los datos de comparación entre compañeros de trabajo son sensibles y no deben ser visibles para todo el personal operativo.
- **Ticket promedio**: además del total vendido, cada cajero muestra cuántas ventas registró y el valor promedio por venta (`total_vendido / num_ventas`) — una métrica más justa que solo el monto total cuando los turnos tienen duración distinta.
- No requiere migración: usa `ventas.created_by`, `ventas.total/fecha_venta/estado/metodo_pago` y `usuarios.nombre` ya existentes.

*Última actualización: 2026-06-06 | v4.59 — tarjeta "Rendimiento de Cajeros" (solo admin) en el dashboard.*

---

## Estado v4.60 (2026-06-06)

### Cambios implementados en esta sesión

| Archivo | Cambio |
|---------|--------|
| `public_html/dashboard.php` | Nueva consulta `$clientes_reactivar`: clientes activos con teléfono que tienen historial de compras pero cuya última compra (`MAX(ventas.fecha_venta)`) fue hace más de 30 días, ordenados por valor histórico total (`SUM(ventas.total)`); nueva tarjeta `.meta-card` "💌 Clientes para Reactivar" entre "Top Clientes del Mes" y "Productos Más Vendidos", mostrando días de inactividad, # de compras históricas, monto histórico y enlace "💌 Reconectar" por WhatsApp con mensaje de reconexión pre-armado |

### Funcionalidad v4.60

- **Cierra el círculo de relación con el cliente**: si v4.57 reconoce a los clientes más frecuentes del mes con un agradecimiento, v4.60 hace el complemento natural — identificar a clientes valiosos que **dejaron de venir** y darle al dueño una acción de un clic para reconectar con ellos antes de perderlos definitivamente.
- **Detección de inactividad**: usa `HAVING MAX(v.fecha_venta) < (CURDATE() - INTERVAL 30 DAY)` sobre clientes con al menos una compra histórica completada (excluye obsequios) — ignora clientes nuevos sin historial, que no son candidatos a "reactivación".
- **Prioriza por valor histórico**: ordena por `SUM(ventas.total)` descendente — el dueño dedica el esfuerzo de reconexión primero a quienes más valor generaron en el pasado.
- **WhatsApp de reconexión**: mensaje pre-armado tipo "te extrañamos" — tercer tono distinto de comunicación con clientes (cobranza en v4.51, fidelización/agradecimiento en v4.57, reconexión/win-back en v4.60).
- No requiere migración: usa `clientes.activo/telefono` y `ventas.cliente_id/fecha_venta/total/estado/metodo_pago` ya existentes.

*Última actualización: 2026-06-06 | v4.60 — tarjeta "Clientes para Reactivar" con WhatsApp de reconexión.*

---

## Estado v4.61 (2026-06-06)

### Cambios implementados en esta sesión

| Archivo | Cambio |
|---------|--------|
| `public_html/dashboard.php` | Nueva alerta `$alertas['garantias_por_vencer']`: activos cuya `garantia_hasta` vence dentro de los próximos 30 días (`garantia_hasta BETWEEN CURDATE() AND CURDATE()+30`), gateada por permiso `activos:solo_ver`; nueva tarjeta "🛡️ Garantías por vencer" (`alerta-hdr amarillo`) en el panel de Alertas operativas, mostrando nombre del activo, serial (si tiene), fecha de vencimiento y días restantes; enlace "Ver activos" |

### Funcionalidad v4.61

- **Aprovechar garantías a tiempo**: el dueño recibe una alerta proactiva cuando la garantía de un activo (nevera, horno, equipo de cocina, etc.) está por vencer en los próximos 30 días — suficiente margen para hacer un reclamo o mantenimiento preventivo antes de perder la cobertura.
- **Cuarta tarjeta del panel de alertas**: se suma a "Insumos bajos/agotados" (rojo), "Fiados pendientes" (naranja/rojo) y "Stock de producto bajo" (naranja) — usa el color amarillo (`alerta-hdr.amarillo`) porque es informativa/preventiva, no urgente.
- **Filtro de ventana**: solo muestra activos cuya garantía **aún no ha vencido** pero vence pronto (`garantia_hasta >= CURDATE()`) — evita mostrar activos con garantías ya vencidas (esas ya no son accionables).
- No requiere migración: usa `activos.garantia_hasta/serial/nombre/activo` ya existentes (campo presente desde migración 005).

*Última actualización: 2026-06-06 | v4.61 — alerta "Garantías por vencer" en el panel operativo del dashboard.*

---

## Estado v4.62 (2026-06-06)

### Cambios implementados en esta sesión

| Archivo | Cambio |
|---------|--------|
| `public_html/ayuda/index.php` | Nueva sección "Dashboard" (`id="dashboard"`, primer ítem del grupo "Módulos" en el sidebar): documenta Resumen del día (4 tarjetas), Meta del día (v4.48), Gráfico de ventas 7 días (v4.49), las 4 tarjetas de seguimiento de clientes/productos (Top Clientes v4.57, Clientes para Reactivar v4.60, Productos Más Vendidos v4.58, Rendimiento de Cajeros v4.59) y las 4 categorías del Panel de Alertas operativas (insumos bajos, fiados pendientes, stock de producto bajo, garantías por vencer v4.61); incluye tablas, tips sobre los "tres tonos de WhatsApp" y advertencia sobre la sensibilidad del ranking de cajeros; badge de versión de "Visión General" actualizado a v4.62 |

### Funcionalidad v4.62

- **Cierra una brecha de documentación**: el Dashboard llevaba 9 versiones (v4.43 a v4.61) acumulando funcionalidad — resumen del día, meta diaria, gráfico de tendencia, 4 tarjetas de WhatsApp/ranking y 4 categorías de alertas — sin tener una sección propia en `ayuda/index.php` (solo existía un párrafo breve mencionando el panel de alertas).
- **Tabla de "tonos de WhatsApp"**: documenta explícitamente que el sistema usa tres mensajes pre-armados con propósitos distintos según la relación con el cliente — cobranza (v4.51), fidelización (v4.57) y reconexión (v4.60) — para que el usuario entienda que cada botón verde de WhatsApp tiene un propósito comunicacional específico.
- **Aclaración de privacidad**: explica por qué "Rendimiento de Cajeros" solo es visible para roles `admin`/`superadmin` (comparación de desempeño entre compañeros = información sensible).
- No requiere cambios de código funcional — es documentación pura, sin migración ni alteración de queries.

*Última actualización: 2026-06-06 | v4.62 — documentación completa del Dashboard en el módulo de Ayuda.*

---

## Estado v4.63 (2026-06-06)

### Cambios implementados en esta sesión

| Archivo | Cambio |
|---------|--------|
| `public_html/dashboard.php` | Nueva variable `$racha_meta`: cuenta los días consecutivos (hacia atrás desde ayer) en que `SUM(ventas.total)` alcanzó o superó `meta_ventas_diaria`, calculado en PHP iterando sobre un mapa fecha→monto de los últimos 30 días (`FETCH_KEY_PAIR`, sin window functions por límite de MySQL 5.7); nuevo badge "🔥 Racha: N días" junto al porcentaje de la tarjeta "Meta del día", visible solo cuando `$racha_meta > 0` |

### Funcionalidad v4.63

- **Gamificación de la meta diaria**: complementa la tarjeta "Meta del día" (v4.48) con un indicador de constancia — reconoce no solo si HOY se cumplió la meta, sino cuántos días seguidos el negocio ha venido cumpliéndola, motivando al equipo a mantener la racha.
- **Cálculo en PHP, no en SQL**: dado que MariaDB/MySQL 5.7 no soporta funciones de ventana, la racha se calcula iterando día por día hacia atrás desde ayer sobre un mapa `fecha => monto` obtenido con `FETCH_KEY_PAIR`, deteniéndose en el primer día que no alcanzó la meta.
- **Cuenta desde ayer, no desde hoy**: el día actual puede estar incompleto (la jornada de ventas sigue en curso), así que la racha solo considera días ya cerrados — evita mostrar una racha "rota" prematuramente a media tarde.
- **Badge visual coherente**: pill redondeada en tonos naranja/fuego (`#fff7ed`/`#c2410c`) junto al badge de porcentaje existente, con singular/plural correcto ("1 día" / "N días") y `title` explicativo en hover.
- No requiere migración: usa `ventas.fecha_venta/total/estado/metodo_pago` y `configuracion_negocio.meta_ventas_diaria` ya existentes (la misma clave que alimenta la tarjeta "Meta del día" desde v4.48).

*Última actualización: 2026-06-06 | v4.63 — badge "Racha de Metas" (días consecutivos cumpliendo la meta diaria) en el dashboard.*

---

## Estado v4.64 (2026-06-06)

### Cambios implementados en esta sesión

| Archivo | Cambio |
|---------|--------|
| `public_html/dashboard.php` | Nueva alerta `$alertas['productos_estancados']` (categoría E del panel operativo): productos terminados activos con `stock_disponible > 0`, creados hace más de 15 días, cuya última venta (`MAX(ventas.fecha_venta)` vía `LEFT JOIN venta_detalles`/`ventas`) fue hace más de 15 días o nunca ocurrió (`ultima_venta IS NULL`); nueva tarjeta "⏳ Productos sin rotación" (`alerta-hdr naranja`) mostrando nombre, unidades en inventario y "Sin ventas hace N días" / "Nunca se ha vendido" |

### Funcionalidad v4.64

- **Alerta de posible merma**: en un negocio de sándwiches (productos perecederos), tener stock terminado que no rota es una señal de alerta temprana — el dueño puede actuar (promoción, descuento, donación) antes de que el producto se dañe y se convierta en pérdida total.
- **Quinta categoría del panel de alertas**: complementa "Stock de producto bajo" (naranja, `productos_bajos`) con su contraparte — exceso/estancamiento — usando el mismo color naranja porque ambas son advertencias accionables, no urgencias críticas.
- **`LEFT JOIN` para detectar "nunca vendido"**: usa `LEFT JOIN venta_detalles`/`ventas` (en vez de `JOIN`) para que `MAX(fecha_venta)` devuelva `NULL` cuando el producto jamás registró una venta — distinguiéndolo en el mensaje ("Nunca se ha vendido" vs. "Sin ventas hace N días").
- **Filtro anti-falsos-positivos**: excluye productos creados hace menos de 15 días (`p.created_at <= CURDATE() - INTERVAL 15 DAY`) — un producto recién agregado al catálogo no ha tenido tiempo de "rotar" y no debería generar una alerta de estancamiento.
- No requiere migración: usa `productos.stock_disponible/created_at/activo` y `venta_detalles`/`ventas` ya existentes.

*Última actualización: 2026-06-06 | v4.64 — alerta "Productos sin rotación" (riesgo de merma) en el panel operativo del dashboard.*

---

## Estado v4.65 (2026-06-06)

### Cambios implementados en esta sesión

| Archivo | Cambio |
|---------|--------|
| `public_html/dashboard.php` | Nueva variable `$comparativa_mensual`: compara el total vendido en lo que va del mes en curso contra el total del **mismo tramo de días** del mes anterior (usando `DATEDIFF(fecha_venta, inicio_mes_anterior) < DAY(CURDATE())` para una comparación "manzanas con manzanas" sin importar la duración de cada mes), calculando `$cambio_pct` (variación porcentual); nueva tarjeta "📊 Comparativo del mes" entre el gráfico de 7 días y "Top Clientes del Mes", con badge de variación (▲/▼ verde o rojo) y mensaje contextual de ánimo |

### Funcionalidad v4.65

- **Perspectiva de tendencia, no solo de corte**: el dashboard ya mostraba ventas de hoy y de los últimos 7 días — v4.65 añade la pregunta que más le importa a un dueño de negocio: *"¿voy mejor o peor que el mes pasado?"*.
- **Comparación justa por tramo de días**: en vez de comparar el mes completo anterior (que penalizaría a mitad de mes), compara solo los primeros N días de cada mes — usando `DATEDIFF()` en SQL para evitar errores de límites de mes (p. ej. comparar 31 días de mayo cuando abril solo tuvo 30).
- **Badge con código de color y mensaje de ánimo**: variación positiva en verde ("vas mejor que el mes pasado 🎉"), negativa en rojo ("un poco más flojo... ¡a recuperar terreno!") — refuerza el tono motivacional ya presente en "Meta del día" (v4.48) y "Racha de Metas" (v4.63).
- **Reutiliza `$meses_es`**: aprovecha el arreglo de nombres de meses en español ya centralizado desde v4.58 para mostrar "los primeros 6 días de Junio" en vez de fechas crudas.
- No requiere migración: usa `ventas.fecha_venta/total/estado/metodo_pago` ya existentes.

*Última actualización: 2026-06-06 | v4.65 — tarjeta "Comparativo del mes" (tendencia vs. mes anterior) en el dashboard.*

---

## Estado v4.66 (2026-06-06)

### Cambios implementados en esta sesión

| Archivo | Cambio |
|---------|--------|
| `public_html/dashboard.php` | Nueva consulta `$clientes_aniversario`: detecta clientes activos cuya **primera compra** (`MIN(ventas.fecha_venta)`) ocurrió en esta misma fecha (mes y día, `DATE_FORMAT(...,'%m-%d')`) hace uno o más años (`TIMESTAMPDIFF(YEAR, ...) >= 1`); nueva tarjeta `.meta-card` "🎂 Aniversario de Clientes" (entre "Clientes para Reactivar" y "Productos Más Vendidos") mostrando fecha de ingreso, años cumplidos y enlace "🎂 Felicitar" por WhatsApp con mensaje de celebración pre-armado |

### Funcionalidad v4.66

- **Cuarto tono de WhatsApp — celebración**: se suma a cobranza (v4.51), fidelización/agradecimiento (v4.57) y reconexión/win-back (v4.60) un nuevo tono: **celebración de aniversario**, felicitando al cliente por "su" fecha especial con el negocio — un detalle que fortalece el vínculo emocional con la marca.
- **Detección por fecha de primera compra, no de registro**: usa `MIN(ventas.fecha_venta)` (la primera compra real, no la fecha de creación del registro de cliente) para definir el "aniversario" — más significativo porque marca el inicio real de la relación comercial.
- **Comparación mes-día con `DATE_FORMAT(...,'%m-%d')`**: evita usar `DAYOFYEAR()` (que se desfasa en años bisiestos) y compara directamente "MM-DD" entre la fecha de primera compra y hoy, capturando el aniversario exacto sin importar el año.
- **Filtro de al menos 1 año**: `TIMESTAMPDIFF(YEAR, ...) >= 1` excluye clientes cuya "primera compra" fue este mismo año (no tendría sentido felicitar un aniversario de "0 años").
- No requiere migración: usa `clientes.activo/telefono/nombre` y `ventas.cliente_id/fecha_venta/estado/metodo_pago/total` ya existentes.

*Última actualización: 2026-06-06 | v4.66 — tarjeta "Aniversario de Clientes" con felicitación por WhatsApp en el dashboard.*

---

## Estado v4.67 (2026-06-06)

### Cambios implementados en esta sesión

| Archivo | Cambio |
|---------|--------|
| `public_html/dashboard.php` | Nueva consulta `$horas_pico`: agrupa las ventas completadas (excluyendo obsequios y mostrador) de los últimos 30 días por `HOUR(fecha_venta)`, ordenando las 3 franjas horarias de mayor monto vendido; nueva tarjeta `.meta-card` "⏰ Horas Pico de Ventas" con medallas 🥇🥈🥉, rango horario (`HH:00 – HH:00`), número de ventas, monto y barra de progreso relativa (`.progress-track`/`.progress-fill`) |

### Funcionalidad v4.67

- **De "qué se vendió" a "cuándo se vende"**: las tarjetas anteriores (Top Productos, Top Clientes, Comparativo mensual) responden "qué" y "cuánto" — v4.67 responde una pregunta operativa distinta y muy práctica: *¿en qué momento del día se concentra la demanda?*
- **Ventana de 30 días para significancia estadística**: agrupar por hora sobre un solo día sería ruidoso; usar 30 días de historial suaviza variaciones diarias y revela el patrón real de tráfico.
- **Aplicación directa a la operación**: el mensaje final ("Útil para planear turnos de personal y producción según la demanda real del día") conecta la analítica con una decisión concreta — cuántas personas poner en el mostrador y cuánta producción anticipar antes de cada franja pico.
- **Reutiliza componentes visuales existentes**: aprovecha las medallas (`🥇🥈🥉`, patrón ya usado en "Productos Más Vendidos" desde v4.58) y las clases `.progress-track`/`.progress-fill` (definidas para "Meta del día" desde v4.48) — coherencia visual sin duplicar CSS.
- No requiere migración: usa `ventas.fecha_venta/total/estado/metodo_pago` ya existentes (`fecha_venta` es `DATETIME`, por lo que `HOUR()` está disponible de forma nativa).

*Última actualización: 2026-06-06 | v4.67 — tarjeta "Horas Pico de Ventas" (analítica de franjas horarias) en el dashboard.*

---

## Estado v4.68 (2026-06-06)

### Cambios implementados en esta sesión

| Archivo | Cambio |
|---------|--------|
| `public_html/dashboard.php` | Nueva consulta `$productos_rentables` (solo admin/superadmin — información financiera sensible): calcula `margen_unitario` (`precio_venta - costo_calculado`) y `margen_pct` (`ROUND((precio_venta - costo_calculado)/precio_venta*100, 1)`) para productos activos con precio > 0, ordenados de mayor a menor margen porcentual; nueva tarjeta `.meta-card` "💰 Productos Más Rentables" con badge de margen % codificado por color (verde ≥50%, ámbar ≥30%, rojo <30%), precio de venta, costo calculado y ganancia unitaria en pesos |

### Funcionalidad v4.68

- **De "qué se vende más" a "qué deja más ganancia"**: complementa "Productos Más Vendidos" (v4.58, ranking por unidades) con la pregunta que más le importa al bolsillo del dueño — *¿cuáles productos son más rentables por cada venta?* — útil para decidir qué promocionar o destacar en el mostrador.
- **Información financiera sensible, gateada como "Rendimiento de Cajeros"**: igual que v4.59, restringe tanto la ejecución de la consulta como el renderizado HTML a roles `admin`/`superadmin` — el costo de producción (`costo_calculado`) es información estratégica que no debería filtrarse al personal operativo.
- **Fórmula explícita y transparente**: el pie de la tarjeta muestra la fórmula exacta (`margen = (precio venta − costo calculado) / precio venta`) para que el administrador entienda de dónde sale el porcentaje, sin "caja negra".
- **Reutiliza `costo_calculado`**: aprovecha el campo que `RecetaModel::recalcularCostos()` ya mantiene actualizado por receta — sin necesidad de recalcular nada nuevo ni tocar la lógica de costeo existente.
- No requiere migración: usa `productos.precio_venta/costo_calculado/nombre/nombre2/activo` ya existentes.

*Última actualización: 2026-06-06 | v4.68 — tarjeta "Productos Más Rentables" (ranking por margen, solo admin) en el dashboard.*

---

## Estado v4.69 (2026-06-06)

### Cambios implementados en esta sesión

| Archivo | Cambio |
|---------|--------|
| `public_html/ayuda/index.php` | Actualiza la sección "Dashboard" (creada en v4.62) para documentar las 6 funcionalidades agregadas desde entonces: Racha de Metas (v4.63, agregado a la subsección "Meta del día"), Comparativo del mes (v4.65, subsección nueva), Aniversario de Clientes (v4.66, fila nueva en la tabla de tarjetas de clientes/productos — y "tres tonos de WhatsApp" pasa a ser "cuatro tonos" incluyendo celebración), nueva subsección "Indicadores de tendencia y rentabilidad" con tabla para Comparativo del mes / Horas Pico de Ventas (v4.67) / Productos Más Rentables (v4.68), nueva fila "⏳ Productos sin rotación" en la tabla de alertas (v4.64) con tip explicativo sobre perecederos, advertencia (`warn`) ampliada para incluir "Productos Más Rentables" como información financiera sensible gateada a admin, e `int-list` actualizado con las nuevas integraciones; badge de versión actualizado a v4.69 |

### Funcionalidad v4.69

- **Cierra la segunda brecha de documentación del Dashboard**: tras v4.62 (que documentó v4.43–v4.61), 6 versiones más (v4.63–v4.68) habían agregado funcionalidad sin reflejo en `ayuda/index.php` — esta versión pone la documentación al día nuevamente, manteniendo la promesa de que el módulo de Ayuda reflexione fielmente lo que el usuario ve en pantalla.
- **"Cuatro tonos de WhatsApp"**: actualiza el tip que documenta los mensajes pre-armados — ahora cobranza, fidelización, reconexión y **celebración** (Aniversario de Clientes, v4.66) — para que el usuario entienda el propósito comunicacional de cada botón verde nuevo.
- **Nueva subsección "Indicadores de tendencia y rentabilidad"**: agrupa temáticamente las tres tarjetas más analíticas (Comparativo del mes, Horas Pico de Ventas, Productos Más Rentables) con una columna "Para qué sirve" que conecta cada métrica con una decisión operativa concreta — coherente con el tono pedagógico del resto del módulo de Ayuda.
- **Advertencia de privacidad ampliada**: el `warn` que antes solo cubría "Rendimiento de Cajeros" ahora también explica por qué "Productos Más Rentables" es información financiera estratégica gateada a `admin`/`superadmin`.
- No requiere cambios de código funcional — es documentación pura, sin migración ni alteración de queries.

*Última actualización: 2026-06-06 | v4.69 — documentación del Dashboard actualizada (Racha de Metas, Comparativo mensual, Aniversario, Horas Pico, Rentabilidad, Productos sin rotación).*

---

## Estado v4.70 (2026-06-06)

### Cambios implementados en esta sesión

| Archivo | Cambio |
|---------|--------|
| `public_html/activos/exportar.php` | Nuevo: genera Excel (.xlsx) del inventario completo de activos fijos, modelado en `clientes/exportar.php`; detecta migración 005 (`$v5`) para incluir columnas opcionales (Categoría, Serial, Unidades, Precio/u, Estado físico, Garantía); columnas siempre presentes: Activo, Descripción, Costo total, Fecha adquisición, Inicio de uso, Vida útil, % Depreciado, Estado de vida, Proveedor/Lugar, Estado; fila TOTALES con costo total acumulado, % depreciación promedio, conteo de activos activos y garantías vencidas |
| `public_html/activos/index.php` | Botón "📊 Excel" verde (`.btn-excel`) en `.ctrl-row`, junto a "+ Nuevo activo", visible solo para `activos:editar_existentes`; `margin-left:auto` movido de `.btn-nuevo` a `.btn-excel` para agrupar ambos botones a la derecha |
| `public_html/app/config/app.php` | APP_VERSION → 4.70 |

### Funcionalidad v4.70

- **Cierra otra brecha de exportación**: clientes, compras, costos, nómina, operativo, precios y ventas ya tenían exportación a Excel — `activos`, `inventario` y `proveedores` no. Esta versión resuelve el caso de mayor valor contable: el inventario de activos fijos (útil para auditoría, reclamos de seguro, declaración de renta o respaldo ante el contador).
- **Degradación elegante (`$v5`)**: igual que `activos/index.php`, detecta con `ActivoModel::columnas_existen()` si la migración 005 está aplicada — si no lo está, omite las columnas Categoría/Serial/Unidades/Precio·u/Estado físico/Garantía sin romper el archivo generado.
- **Catálogo dinámico con fallback**: usa `listas_map('categoria_activo')` (Admin → Catálogos) para las etiquetas de categoría, con el mismo arreglo hardcodeado de respaldo que `activos/index.php` si la migración 029 no está aplicada.
- **Fila de totales informativa**: además del costo total acumulado, calcula el % de depreciación promedio del inventario y cuenta cuántas garantías ya vencieron — datos que el contador o el dueño normalmente tendrían que calcular manualmente.
- **Botón agrupado a la derecha**: se trasladó `margin-left:auto` de `.btn-nuevo` a `.btn-excel` (que ahora aparece primero) para que ambos botones de acción queden agrupados al extremo derecho de la barra de controles, igual que en `clientes/index.php`.
- No requiere migración: usa `ActivoModel::todos()` y las columnas existentes de la tabla `activos`; reutiliza `XlsxWriter` y el patrón de `clientes/exportar.php` (v4.52).

*Última actualización: 2026-06-06 | v4.70 — exportar inventario de activos fijos a Excel.*

---

## Estado v4.71 (2026-06-06)

### Cambios implementados en esta sesión

| Archivo | Cambio |
|---------|--------|
| `public_html/admin/usuarios.php` | Nueva caja informativa azul sobre la matriz de permisos: lista los 3 casos especiales que NO se controlan desde la matriz (Clientes comparte permiso de Ventas; Admin/Ayuda dependen del rol; tarjetas sensibles del Dashboard —Cajeros y Rentabilidad— visibles solo admin/superadmin sin importar el nivel asignado) |
| `public_html/ayuda/index.php` | Sección "Módulo: Administración" → "Sistema de permisos" ampliada: nueva tabla "Los 9 módulos configurables" (lista explícita del ENUM `permisos_modulos.modulo`) y tabla "Casos especiales — fuera de la matriz" con 3 filas (Clientes, Admin/Ayuda, Tarjetas sensibles del Dashboard) + advertencia explicando que `admin_total` en ventas/productos NO otorga acceso a Cajeros/Rentabilidad — se requiere además el *rol* admin/superadmin |
| `public_html/app/config/app.php` | APP_VERSION → 4.71 |

### Auditoría realizada (resultado: sistema en buen estado, sin código por corregir)

Se revisó si "cada rol de usuario se puede configurar para cada módulo o sección":

- ✅ **Matriz 100% sincronizada**: los 9 módulos en `$MODULOS` de `admin/usuarios.php`, los 9 valores del ENUM `permisos_modulos.modulo` (`database/schema.sql`) y los 9 módulos de `permiso_modulos_accesibles()` (`PermisosHelper.php`) coinciden exactamente — no hay módulos huérfanos ni faltantes.
- ✅ **Granularidad correcta en sub-páginas**: se verificó que `nomina/` (index, empleados, horas, parametros), `clientes/` (index, exportar, estado_cuenta, fusionar, registrar_abono) y otros módulos llaman `permiso_requerir()`/`permiso_tiene()` con los niveles apropiados (`solo_ver` → `editar_existentes` → `admin_total`) de forma consistente con la jerarquía de 5 niveles.
- ⚠️ **3 excepciones identificadas y ahora documentadas** (ya existían, pero no estaban explicadas en ningún lado):
  1. `clientes` comparte el permiso `ventas` (decisión correcta — evita duplicar configuración de un módulo que es extensión natural de ventas/fiado)
  2. `admin`/`ayuda` se controlan por `$_SESSION['usuario_rol']` directo, no por la matriz (correcto — son paneles de sistema, no datos operativos graduables)
  3. Las tarjetas "Rendimiento de Cajeros" (v4.59) y "Productos Más Rentables" (v4.68) del dashboard usan `in_array($_SESSION['usuario_rol'], ['admin','superadmin'])` — **bypasean la matriz**: un empleado con `admin_total` en ventas/productos NO las verá. Esto es intencional (datos financieros/de personal sensibles) pero no estaba comunicado al usuario administrador.

### Conclusión

El sistema de permisos está bien diseñado y completo — no requirió cambios funcionales ni de esquema. El "avance" de esta versión es **cerrar la brecha de visibilidad**: ahora tanto la pantalla de gestión de usuarios como el módulo de Ayuda explican exactamente qué se controla por la matriz y qué se controla por rol directo, evitando que el administrador asuma (incorrectamente) que basta con subir el nivel de un módulo para que un empleado vea información sensible.

*Última actualización: 2026-06-06 | v4.71 — auditoría y documentación completa del sistema de permisos por rol/módulo (matriz + 3 excepciones documentadas).*

## Estado v4.72 (2026-06-06)

### Auditoría de seguridad / vulnerabilidades — segundo ciclo de la mejora grande

Se auditaron los ~32 archivos PHP modificados desde v4.40 (la última auditoría XSS documentada fue en v4.25) más los puntos de entrada críticos (login, middleware, uploads, backups). Resultado: **2 hallazgos corregidos, 1 endurecimiento defensivo añadido a 4 endpoints, y 6 categorías confirmadas sin problemas**.

### 🔴 Hallazgo 1 — Open Redirect (CWE-601), CORREGIDO

| Archivo | Problema | Corrección |
|---------|----------|------------|
| `app/middleware/auth_check.php` | Guardaba `$_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI']` **sin validar**. Una URL maliciosa con `REQUEST_URI` empezando en `//` (p. ej. `//evil.com/phish`) se interpreta por el navegador como redirección de protocolo relativo a un dominio externo | Ahora se valida con `preg_match('#^/(?!/)#', $uri)`: solo se acepta si empieza con **un solo** `/` (ruta interna); si no, se usa `/dashboard.php` por defecto |
| `login.php` | Usaba `$redirect` de la sesión directamente en `header('Location: ' . $redirect)` sin revalidar | Defensa en profundidad: se repite la misma validación `preg_match('#^/(?!/)#', ...)` justo antes del `header()`, por si la sesión llegara a contener un valor no confiable por otra vía en el futuro |

### 🔴 Hallazgo 2 — Zip-Slip / Path Traversal en restaurador de código (CWE-22), CORREGIDO

| Archivo | Problema | Corrección |
|---------|----------|------------|
| `admin/backup.php` (función "Actualizar código" — solo superadmin) | Sanitizaba rutas del ZIP con `str_replace(['../', ...], ...)`, vulnerable a patrones anidados: `"....//"` se convierte en `"../"` tras un único reemplazo (bypass clásico de filtros ingenuos) | Reemplazado por validación por **segmentos de ruta**: se descompone la ruta en partes con `explode('/', ...)` y se **descarta toda la entrada** si contiene un segmento `".."` o empieza con `/` — no hay forma de reconstruir un `..` mediante concatenación |

> Nota de exposición: esta función ya estaba protegida por `if ($_SESSION['usuario_rol'] !== 'superadmin')`, por lo que el riesgo real era bajo (requiere cuenta superadmin comprometida o ZIP de fuente no confiable). Aun así se corrigió por buena práctica de defensa en profundidad.

### 🟡 Endurecimiento — manejo de errores ("pruebas de error")

Se detectaron 4 endpoints JSON que ejecutaban operaciones de BD/filesystem **sin try/catch**, a diferencia del patrón estándar usado en `clientes/api/crud.php` y `ventas/api/editar_venta.php` (capturar `Throwable`, registrar con `error_log()`, responder JSON genérico sin filtrar detalles internos). Se añadió ese mismo patrón a:

- `activos/api/subir_foto.php` — protege contra fallos de GD (redimensionado), filesystem y DB durante la subida de fotos
- `productos/api/variantes_crud.php` — protege las mutaciones (crear/editar/eliminar/reactivar variantes)
- `productos/api/ingredientes.php` — protege la consulta de ingredientes para el detalle expandible
- `ventas/api/capacidades.php` — protege la consulta de capacidad de producción del POS

Sin este cambio, una excepción de `PDOException` (con `PDO::ERRMODE_EXCEPTION` activo) habría terminado el script de forma abrupta — sin filtrar información (porque `display_errors=0` en producción) pero con una respuesta vacía/rota para el cliente JS. Ahora responden con JSON limpio (`{"success": false, "error": "Error interno del servidor."}` o `[]` según el endpoint) y registran el detalle real en el log del servidor.

### ✅ Categorías auditadas sin hallazgos

| Categoría | Resultado |
|-----------|-----------|
| **Inyección SQL** | Todo el SQL dinámico usa `match()` con valores hardcodeados, *flags* booleanos de detección de migración (`$colEsBaseV ? ', es_base' : ...`), o listas blancas vía `preg_match('/^[a-zA-Z0-9_]+$/', ...)` (nombres de tabla en backups). Cero concatenación de input de usuario en queries. |
| **XSS** | Todo texto de BD mostrado en HTML pasa por `htmlspecialchars()`. Mensajes de WhatsApp (que interpolan `{$var['nombre']}`) nunca se imprimen como HTML — van con `rawurlencode()` a `href="https://wa.me/...?text=..."`, y los teléfonos se sanitizan con `preg_replace('/[^0-9]/', '', $tel)`. |
| **CSRF** | Los 9 endpoints POST que cambian estado llaman `csrf_verificar()` con `hash_equals()` (comparación segura contra *timing attacks*); los endpoints GET de solo descarga (`clientes/exportar.php`, `activos/exportar.php`) lo omiten correctamente por ser lecturas idempotentes detrás de `auth_check` + `permiso_requerir`. |
| **Subida de archivos** | `activos/api/subir_foto.php` valida el tipo MIME real con `finfo_file()` (no la extensión del cliente), limita a 15 MB, genera nombres de archivo en servidor (`activo_{id}_{time}.ext` — el cliente no controla el nombre), y crea un `.htaccess` que bloquea ejecución de `.php/.phtml/.cgi` en `uploads/`. |
| **Sesiones y contraseñas** | Cookies de sesión con `httponly=true`, `secure` (cuando hay HTTPS), `samesite=Lax`; contraseñas con `password_hash`/`password_verify` (bcrypt costo 12, comparación en tiempo constante). Rate-limiting de login activo: `MAX_LOGIN_INTENTOS=5` en `LOGIN_BLOQUEO_MINS=15`, registrado por email **e** IP en `login_intentos`. |
| **Funciones peligrosas** | Cero `eval()`, `exec()`, `shell_exec()`, `system()`, `passthru()`, `proc_open()`, `unserialize()`, `assert()` en todo `public_html/`. La única coincidencia (`db()->exec($sql)` en `admin/backup.php`) es `PDO::exec()` para restaurar migraciones SQL — no la función de shell — y ya estaba protegida con CSRF, límite de 5 MB, solo `.sql`, y try/catch por sentencia. |

### Cambios de versión

| Archivo | Cambio |
|---------|--------|
| `public_html/app/middleware/auth_check.php` | Valida `REQUEST_URI` antes de guardarlo como redirect post-login (corrige open redirect) |
| `public_html/login.php` | Revalida el redirect antes de `header('Location: ...)` (defensa en profundidad) |
| `public_html/admin/backup.php` | Sanitización de rutas ZIP por segmentos en vez de `str_replace` (corrige zip-slip) |
| `public_html/activos/api/subir_foto.php` | + try/catch con `error_log` y respuesta JSON genérica |
| `public_html/productos/api/variantes_crud.php` | + try/catch con `error_log` y respuesta JSON genérica |
| `public_html/productos/api/ingredientes.php` | + try/catch con `error_log` y respuesta JSON genérica |
| `public_html/ventas/api/capacidades.php` | + try/catch con `error_log` y respuesta JSON genérica |
| `public_html/app/config/app.php` | APP_VERSION → 4.72 |

*Última actualización: 2026-06-06 | v4.72 — auditoría de seguridad: corrige open redirect (CWE-601) y zip-slip/path traversal (CWE-22), añade manejo de errores try/catch a 4 endpoints JSON. Próximo ciclo: v4.73 (código obsoleto + comentarios).*

## Estado v4.73 (2026-06-06)

### Auditoría de código obsoleto y comentarios — tercer ciclo de la mejora grande

Se rastrearon en los 94 archivos PHP de `public_html/`: marcadores `TODO/FIXME/DEPRECATED`, bloques de código comentado, archivos huérfanos (sin referencias), funciones de helpers/modelos nunca invocadas, y restos de depuración (`var_dump`/`print_r`). Resultado: **2 piezas de código muerto eliminadas, 2 funciones huérfanas activadas (en vez de borradas, porque ya eran seguras y útiles), y el resto del código confirmado limpio**.

### 🗑️ Código muerto eliminado

| Archivo | Por qué se eliminó |
|---------|--------------------|
| `inventario/api/proveedor_crud.php` | *Shim* de compatibilidad que solo hacía `require` al endpoint consolidado `proveedores/api/crud.php`. Se confirmó con `grep` en todo el proyecto (PHP + JS) que **ninguna** llamada lo referencia — el "código existente" que decía proteger ya no existe. |
| `app\models\ClienteModel::historial_fiado()` | Método que devolvía ventas a fiado + abonos de un cliente, presente desde el commit inicial (v4.0) pero **nunca invocado** (verificado con `git log -S` en todo el historial). Quedó completamente superado por la implementación más completa y específica en `clientes/estado_cuenta.php` (que además agrega filtro por fechas y saldo corriente acumulado). |

### ⚙️ Funciones huérfanas activadas (en vez de eliminarlas)

Estas dos ya eran código **seguro, completo y bien escrito** — simplemente nunca se conectaron a la interfaz. Conectarlas aporta más valor que borrarlas:

| Función / endpoint | Dónde estaba "huérfana" | Qué se hizo |
|---|---|---|
| `productos/api/recalcular.php` (`RecetaModel::recalcular_todos()`) | Endpoint JSON completo (auth + permiso + CSRF + try/catch) que existe desde v4.0 pero **ningún botón lo llamaba** — el recálculo automático solo ocurre como efecto secundario al guardar un producto | Se agregó el botón **"↻ Recalcular costos"** junto al banner de "Capacidad instalada" en `productos/index.php` (visible solo con permiso `editar_existentes`+), con confirmación y tooltip explicando cuándo usarlo: tras editar precios de insumos en lote o aplicar migraciones |
| `permiso_limpiar_cache()` (`PermisosHelper.php`) | Función documentada como "llamar cuando se cambian los permisos de un usuario en la misma sesión", pero **nunca se llamaba** desde `admin/api/usuario_crud.php`, donde se guardan los permisos | Se agregó la llamada en la rama `editar` cuando `$id === $uid` (un admin edita su propia cuenta): así su sesión no queda con el nivel de acceso cacheado y desactualizado hasta el próximo login — corrige un *bug* latente de caché obsoleta |

### ✅ Categorías auditadas sin hallazgos

| Categoría | Resultado |
|-----------|-----------|
| Marcadores `TODO/FIXME/XXX/HACK/DEPRECATED` | Cero — solo coincidencias falsas de la palabra "TODOS" (plural de "todo" en español) |
| Bloques de código comentado (HTML/JS/PHP/SQL) | Cero — no hay funciones, `<div>`, `<table>` ni reglas CSS comentadas como código muerto |
| Restos de depuración (`var_dump`, `print_r`, `console.log` de prueba) | Cero en `public_html/` (fuera de `tests/`) |
| Archivos sueltos de prueba/backup (`.bak`, `.old`, `*test*`, `*debug*`, `*temp*`) | Cero fuera del directorio oficial `tests/` |
| Comentarios "legacy" encontrados (`compras.php` `calcSubtotal`, `inventario/index.php` listas de respaldo, `costos/index.php` reglas CSS responsive, `$costos_fijos_legacy`) | Los 5 son **intencionales y bien documentados** — wrappers de compatibilidad, *fallbacks* defensivos o puentes de migración de configuración. Ninguno es código muerto; se dejaron tal cual |
| Credenciales hardcodeadas en archivos públicos | Cero — las dos coincidencias de `Admin2026!`/`admin@clandestino.local` son: (1) en `ayuda/index.php`, solo se muestra el *email* con instrucción de cambiar la contraseña; (2) en `tests/suite.php`, se usa la contraseña de ejemplo como valor de comparación para **verificar que el superadmin YA NO la use** (prueba de seguridad, no una fuga) |
| Numeración de migraciones (`019`-`024` ausentes) | Hueco preexistente del desarrollo temprano (antes de formalizar el sistema de migraciones numeradas) — no se renombran archivos ya aplicados en producción para no romper el historial; cada migración tiene sus propias guardas idempotentes vía `information_schema` |

### Conclusión

El código de ClanDestino está, en términos generales, **limpio de residuos** — no había bloques comentados ni `TODOs` olvidados. Los únicos hallazgos reales fueron dos piezas de código verdaderamente muertas (eliminadas) y dos funciones bien construidas que simplemente nunca llegaron a conectarse con la interfaz (ahora activas y útiles). El nivel de comentarios explicativos ya es alto en los módulos críticos (helpers de permisos, modelos, validaciones de seguridad) — se documenta el *porqué* de las decisiones no obvias, no el *qué* del código.

### Cambios de versión

| Archivo | Cambio |
|---------|--------|
| `public_html/inventario/api/proveedor_crud.php` | **Eliminado** — shim de compatibilidad sin referencias |
| `public_html/app/models/ClienteModel.php` | **Eliminado** método `historial_fiado()` — nunca usado, superado por `estado_cuenta.php` |
| `public_html/productos/index.php` | + botón "↻ Recalcular costos" y función JS `recalcularCostos()` |
| `public_html/admin/api/usuario_crud.php` | + llamada a `permiso_limpiar_cache()` al editar la propia cuenta (corrige caché de permisos obsoleta) |
| `public_html/app/config/app.php` | APP_VERSION → 4.73 |

*Última actualización: 2026-06-06 | v4.73 — auditoría de código obsoleto: elimina 2 piezas de código muerto (shim de proveedores, método ClienteModel::historial_fiado), activa 2 funciones huérfanas (botón recalcular costos, limpieza de caché de permisos al auto-editarse). Próximo ciclo: v4.74 (revisión responsive móvil + TV/pantallas grandes).*

## Estado v4.74 (2026-06-06)

### Auditoría responsive móvil + TV/pantallas grandes — cuarto ciclo de la mejora grande

Se revisaron los 94 archivos PHP con vista de `public_html/` en busca de problemas de visualización en **móvil vertical** (≤480px) y **TV/pantallas grandes** (≥1920px). Sin herramienta de navegador disponible en este entorno, la auditoría se hizo por **análisis estático de código**: cobertura de `<meta viewport>`, sistema de breakpoints, anchos fijos de riesgo, envoltorios de scroll en tablas, tamaño de objetivos táctiles, y muestreo de las páginas más recientes (v4.4x–v4.7x, estadísticamente las más propensas a brechas porque son las menos probadas en producción).

### ✅ Resultado: sistema responsive maduro y consistente — sin bugs funcionales encontrados

| Verificación | Resultado |
|---|---|
| **`<meta viewport>`** | 100% de cobertura — los únicos 4 archivos PHP sin la etiqueta no son vistas HTML (son exportadores Excel y endpoints de redirección/JSON) |
| **Sistema de breakpoints global** (`app/views/nav.php`) | Completo y escalonado: `≤359px` (phone XS) → `360-479px` → `480-639px` (phone landscape) → `640-1023px` (tablet) → `1024-1279px` → `≥1600px` (pantalla grande) → `≥1920px` (TV) |
| **Menú hamburguesa móvil** | Tabs horizontales se ocultan ≤640px; drawer vertical con todas las secciones; cierra con Escape/clic-fuera/rotación a horizontal |
| **Tipografía escalable para TV** (`nav.php`, ≥1920px) | `body`, `p/td/li/.tbl td`, `.muted/.kpi-lbl`, `.badge`, inputs — todos suben de tamaño con `!important` porque "el usuario está a mayor distancia" (comentario textual del código) |
| **Grids auto-adaptables** | `grid-template-columns:repeat(auto-fill,minmax(280px,1fr))` (proveedores, activos, conteo) — se ajustan a cualquier ancho sin necesitar breakpoints explícitos |
| **Objetivos táctiles** | POS (`ventas/index.php`) usa `min-height:44px /* touch-friendly */` + `-webkit-tap-highlight-color:transparent` — cumple el estándar de 44×44px de Apple/Google |
| **Tablas anchas** | Envueltas en `.table-wrap{overflow-x:auto}` o `.card{overflow-x:auto}`, con `min-width` base (700-1000px) y columnas no esenciales ocultas vía `display:none !important` en `nth-child()` para móvil |
| **Páginas recientes (v4.43-v4.70)** | `cierre.php`, `apertura.php`, `consolidar.php`, `conteo.php`, `estado_cuenta.php`, `produccion.php` — todas con viewport + al menos 1 breakpoint propio (ajustes de grid/columnas) que se apoya en el sistema global de `nav.php` para tablet/desktop/TV |
| **`html{overflow-x:hidden;max-width:100%}` + `box-sizing:border-box`** | Regla global en `nav.php` (añadida en v4.25) previene scroll horizontal accidental en cualquier módulo |

### 🟡 Hallazgo único — cosmético, no funcional (documentado, sin corregir)

| Dónde | Qué pasa | Por qué no se corrigió en código |
|---|---|---|
| `dashboard.php` — tarjetas tipo ranking (`.meta-card`: Top Clientes v4.57, Productos Más Vendidos v4.58, Cajeros v4.59, Reactivar v4.60, Aniversario v4.66, Horas Pico v4.67, Rentables v4.68) | Cada fila usa `font-size` **inline** en `<span>/<strong>/<div>` (11-15px, ej. `style="font-size:13px"`). El sistema de escalado tipográfico para TV de `nav.php` (≥1920px) sube el tamaño de `body`, `p/td/li`, `.muted/.kpi-lbl`, `.badge` — pero **no** alcanza estos elementos porque no tienen esas clases ni son esos tags. Resultado: en una TV (≥1920px), estas 7 tarjetas mostrarán texto fijo de 11-15px mientras el resto de la interfaz escala +2px — una inconsistencia visual menor (texto comparativamente pequeño/disperso a la distancia de visualización de un TV) | Una corrección robusta requiere **refactorizar el marcado** (reemplazar los `style="font-size:Npx"` inline por clases CSS, p. ej. `.mc-medalla/.mc-nombre/.mc-detalle/.mc-monto`, y agregar reglas de escalado para esas clases en el media query de `nav.php` — el mismo patrón usado para `.nav-link`/`.subtab`). Es un cambio de **~7 tarjetas × ~5 elementos** sin posibilidad de verificación visual en este entorno (no hay navegador/captura disponible) — el riesgo de introducir una regresión visual no detectable supera el beneficio de un ajuste cosmético en un caso de uso poco frecuente (panel administrativo de un local pequeño visto en TV). Se documenta para una futura iteración con verificación visual en navegador real. |

### Conclusión

ClanDestino tiene un sistema de diseño responsive **notablemente maduro, consistente y completo** — construido sobre una única fuente de verdad (`nav.php`) que inyecta breakpoints, tipografía escalable y reglas globales a los 94 módulos. La revisión no encontró **ningún bug funcional** de visualización en móvil vertical ni en TV/pantallas grandes; el único hallazgo es una inconsistencia cosmética menor y de bajo impacto en 7 tarjetas del dashboard, documentada arriba con su causa raíz exacta y la ruta de corrección recomendada para cuando se pueda verificar visualmente.

> **Nota de método:** esta auditoría se realizó 100% por análisis estático de código — no hay herramienta de navegador/captura de pantalla disponible en este entorno (confirmado al buscar en las herramientas disponibles). Se recomienda una verificación visual en navegador real (DevTools responsive mode + TV/monitor grande) como complemento antes de dar el sistema responsive por "cerrado" definitivamente.

### Cambios de versión

| Archivo | Cambio |
|---------|--------|
| `public_html/app/config/app.php` | APP_VERSION → 4.74 |

*Última actualización: 2026-06-06 | v4.74 — auditoría responsive móvil + TV/pantallas grandes: confirma sistema maduro y consistente (viewport 100%, breakpoints 359px-1920px, touch targets 44px, grids auto-adaptables, tablas con scroll), documenta 1 hallazgo cosmético menor (font-size inline en 7 tarjetas del dashboard no escala en TV) sin corregir por imposibilidad de verificación visual. Próximo ciclo: v4.75 (sincronizar schema.sql + revisión final claude.md + verificación GitHub).*

## Estado v4.75 (2026-06-06)

### Sincronización de schema.sql + revisión final — quinto y último ciclo de la mejora grande

Cierre del plan de 5 ciclos (v4.71 permisos → v4.72 seguridad → v4.73 código obsoleto → v4.74 responsive → **v4.75 schema/documentación/sync**). Se auditó `database/schema.sql` contra las 37 migraciones (002-038) y contra el propio `claude.md`, y se verificó que todo el trabajo de la sesión esté efectivamente subido a GitHub.

### 🔴 Inconsistencias encontradas y corregidas en `database/schema.sql`

El esquema ya contenía estructuralmente las tablas/columnas de las migraciones 035-038 (mantenido al día en v4.30/v4.40/v4.45/v4.46), pero su **documentación interna había quedado desfasada** — un caso clásico de "el código está bien, el comentario miente":

| Problema | Antes | Corregido a |
|---|---|---|
| Título del encabezado vs. pie de página inconsistentes entre sí | Encabezado decía `v4.46`, pie de página decía `v4.45` (dos versiones distintas en el mismo archivo) | Ambos unificados a `v4.74` → ahora `v4.75` (versión de la sesión que sincronizó el archivo) |
| Conteo de tablas desactualizado | Comentario decía "TABLAS (28)" y el `SHOW TABLES` esperado decía "28 tablas" | Recontadas: **29** `CREATE TABLE` = **29** `DROP TABLE IF EXISTS` (balance verificado) → ambos lugares corregidos a 29 |
| `turnos_caja` (mig. 037) faltaba en el listado enumerado de tablas | La tabla existía en el script (con `CREATE TABLE IF NOT EXISTS`) pero no aparecía en el comentario que enumera las 28/29 tablas | Agregada al final de la lista: `producto_variantes (mig. 035), turnos_caja (mig. 037)` |
| Migración 036 (`recetas.es_base`) no mencionada en el resumen de migraciones incluidas | El encabezado solo mencionaba mig. 035, 037 y 038 — la columna `es_base` (con su comentario `-- cantidad fija, no escala con factor_receta (mig. 036)`) sí estaba en el `CREATE TABLE recetas`, pero invisible en el resumen | Agregada línea: `mig. 036: recetas.es_base (ingrediente que no escala con factor_receta)` |
| Conteo de seed `listas_sistema` desactualizado | Verificación esperada decía "debe ser 57" | Recontado por categoría (`presentacion`=11, `categoria_costo`=10, `categoria_insumo`=8, `unidad_medida`=9, `categoria_activo`=7, `categoria_proveedor`=7, `categoria_producto`=4, `tamano_producto`=3) = **59** filas reales → corregido a 59 (el valor 57 quedó obsoleto desde que mig. 029b agregó las categorías `categoria_producto`/`tamano_producto`) |
| Rango de migraciones cubiertas | Decía "no es necesario ejecutar las migraciones 002-034" (desactualizado — el script ya incluye hasta 038) | Corregido a "002-038" |

### ✅ Revisión final de `claude.md`

- Verificado que las 4 migraciones más recientes (035, 036, 037, 038) están documentadas en la sección 13 (tabla de migraciones) — ✅ las 4 presentes con su descripción.
- Verificado que el número de versión en el título (`v4.75`) coincide con `APP_VERSION` en `app.php` (`'4.75'`) — ✅ sincronizado.
- Confirmado que las 11 secciones "Estado v4.6X/v4.7X" de esta sesión están todas presentes y en orden cronológico (v4.62 → v4.75, sin huecos) — ✅.

### ✅ Verificación de sincronía con GitHub

```
git log origin/master..HEAD --oneline   →  (vacío = todo sincronizado)
git status --short                       →  solo schema.sql modificado (este ciclo, recién comiteado)
```

Todos los commits de la sesión (v4.71 a v4.75) están confirmados en `origin/master`. No hay trabajo pendiente de subir.

### Cierre del plan de 5 ciclos — resumen ejecutivo

| Ciclo | Versión | Resultado |
|---|---|---|
| 1 — Roles y permisos | v4.71 | Sistema de permisos confirmado completo; documentadas 3 excepciones (Clientes/Admin-Ayuda/tarjetas sensibles) que antes no estaban explicadas |
| 2 — Seguridad/vulnerabilidades | v4.72 | 2 vulnerabilidades reales corregidas (open redirect CWE-601, zip-slip CWE-22); 4 endpoints reforzados con try/catch; 6 categorías OWASP confirmadas limpias |
| 3 — Código obsoleto/comentarios | v4.73 | 2 piezas de código muerto eliminadas; 2 funciones huérfanas activadas (en vez de borradas, por ser seguras y útiles) |
| 4 — Responsive móvil/TV | v4.74 | Sistema responsive confirmado maduro y consistente; 1 hallazgo cosmético menor documentado (sin corregir por imposibilidad de verificación visual) |
| 5 — Schema SQL + documentación | v4.75 | `schema.sql` recontado y corregido (29 tablas, 59 seeds, mig. 036 visible, versión unificada); `claude.md` verificado completo; GitHub 100% sincronizado |

### Cambios de versión

| Archivo | Cambio |
|---------|--------|
| `database/schema.sql` | Encabezado/pie unificados a v4.75; conteo de tablas 28→29 (+ `turnos_caja` en la lista); mención de mig. 036 agregada; conteo `listas_sistema` 57→59; rango de migraciones 002-034→002-038 |
| `public_html/app/config/app.php` | APP_VERSION → 4.75 |

*Última actualización: 2026-06-06 | v4.75 — cierre del plan de 5 ciclos: schema.sql recontado y corregido (29 tablas, 59 seeds, versiones unificadas), claude.md verificado completo, GitHub 100% sincronizado. Sistema en estado estable, sin pendientes de código, documentación o sincronización.*

## Estado v4.80 (2026-06-08)

### Presentaciones múltiples de compra por insumo (mig. 039)

Implementación completa del soporte para catálogos de presentaciones de compra por insumo. Un insumo puede tener múltiples formas físicas de compra (frasco, galón, bidón, etc.) sin alterar su unidad canónica de stock ni su base de recetas.

### Arquitectura (Opción A — principio de unidad canónica)

- La `unidad_medida` del insumo permanece fija e inmutable (base de recetas, mov. de stock sin cambios).
- `insumo_presentaciones` cataloga las distintas presentaciones de compra con sus `cantidad_base` y `unidad_compra`.
- Al comprar con presentación con `equiv_cantidad` → se actualiza `insumos.equiv_cantidad/equiv_unidad` para reflejar la equivalencia física real.
- `compra_detalles.presentacion_id` es FK lógica nullable (sin restricción DB, patrón del proyecto para cPanel).
- Historial inmutable: compras anteriores con `presentacion_id = NULL` continúan funcionando sin cambios.

### Migración 039

```sql
CREATE TABLE insumo_presentaciones (
  id INT AUTO_INCREMENT PRIMARY KEY,
  insumo_id INT NOT NULL,                          -- FK lógica → insumos.id
  nombre VARCHAR(60) NOT NULL,                     -- Ej: Frasco 900ml, Galón 3.785L
  cantidad_base DECIMAL(12,4) NOT NULL,            -- unidades canónicas por presentación
  unidad_compra VARCHAR(30) NOT NULL DEFAULT '',   -- frasco, galón, paca, caja…
  precio_referencia DECIMAL(12,2) DEFAULT NULL,
  equiv_cantidad DECIMAL(10,4) DEFAULT NULL,       -- override equiv_cantidad del insumo
  equiv_unidad VARCHAR(20) DEFAULT NULL,
  es_predeterminada TINYINT(1) NOT NULL DEFAULT 0,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_by INT DEFAULT NULL,
  updated_by INT DEFAULT NULL,
  INDEX idx_ip_insumo (insumo_id),
  INDEX idx_ip_activo (activo)
) ENGINE=InnoDB;
ALTER TABLE compra_detalles ADD COLUMN presentacion_id INT DEFAULT NULL;
-- FK lógica → insumo_presentaciones.id. NULL = compra sin presentación catalogada.
```

### Archivos modificados

| Archivo | Cambio |
|---|---|
| `database/migrations/039_insumo_presentaciones.sql` | Nueva migración (tabla + columna) |
| `public_html/app/models/PresentacionModel.php` | Nuevo modelo CRUD; `tabla_existe_publica()` para vistas |
| `public_html/inventario/api/presentaciones.php` | API REST: listar, crear, editar, toggle-activo por insumo |
| `public_html/app/models/CompraModel.php` | `criar()` y `editar()`: INSERT dinámico + detección mig. 039 + update `equiv_cantidad` |
| `public_html/app/models/InsumoModel.php` | `todos()` incluye campo `pres_cat` (presentaciones por insumo para JS) |
| `public_html/inventario/index.php` | Sección "Presentaciones de compra" en modal ajustar + JS CRUD |
| `public_html/inventario/compras.php` | Selector de presentación catalogada + mini-modal nuevo insumo + `pres_cat` en `INSUMO_MAP` |
| `public_html/tests/suite.php` | G29: 9 tests para tabla `insumo_presentaciones` y FK en `compra_detalles` |
| `database/schema.sql` | v4.80 — tabla `insumo_presentaciones` (30 tablas totales); columna en `compra_detalles` |
| `public_html/ayuda/index.php` | Sección "Presentaciones de compra por insumo (v4.80)" con ejemplos y flujo |
| `public_html/app/config/app.php` | APP_VERSION → 4.80 |

### Cambios técnicos destacados

- **INSERT dinámico**: `CompraModel::criar()` y `editar()` usan array builder (`$cols_det[]`/`$pars_det[]` + `implode()`) en lugar de 8 ramas estáticas (`if/elseif/else`).
- **Detección de migración**: `static $tiene039c` / `static $tiene039ce` en `CompraModel`; `PresentacionModel::tabla_existe()` en vistas.
- **`cerrarModal(id)`**: Refactorizado para aceptar ID como parámetro (soporta `modalEditar` y `modalNuevoIns`).
- **Creación inline de insumos**: `guardarNuevoInsInline()` crea el insumo vía API y lo inyecta en `INSUMOS`/`INSUMO_MAP` sin recarga de página.

### Limpieza de archivos SQL obsoletos (2026-06-08)

Eliminados del repo en commit `43bda19`:
- `historicos_fase1..fase4c.sql`, `historicos_correctivo.sql`, `historicos_correctivo2.sql`, `historicos_fase4b_ventas_fix.sql`, `historicos_fix_dulce.sql` — scripts de carga histórica del 23/05/2026, ya aplicados a la BD.
- `database/schema_completo_v4.20.sql` — snapshot antiguo de v4.20, reemplazado por `schema.sql`.

*Última actualización: 2026-06-08 | v4.80 — presentaciones múltiples de compra por insumo: nueva tabla `insumo_presentaciones` (mig. 039), 11 archivos modificados/creados, 9 tests G29, INSERT dinámico en CompraModel, limpieza de 11 SQL auxiliares obsoletos. 30 tablas en schema.*

## Estado v4.81 (2026-06-10)

### Fase 1 — Fixes en módulo Inventario (sin migración)

Tres correcciones en `public_html/inventario/index.php`, todas confirmadas por lectura directa de código antes de implementar.

1. **Botón "Eliminar insumo" no aparecía**: el gate de permiso usaba `permiso_tiene('inventario','admin_total')` (línea 312) mientras el endpoint `api/insumo_crud.php` solo exige `editar_existentes`. Se igualó el gate al backend (mismo permiso que "Ajustar"/"Copiar").

2. **Editar presentación catalogada (mig. 039) creaba fila duplicada**: el botón "Editar" inyectaba `JSON.stringify(p).replace(/"/g,"'")` dentro de un `onclick`; si `nombre`/`unidad_compra` contenía un apóstrofe, el literal JS se rompía, `pf-id` quedaba vacío y `guardarPresentacion()` mandaba `accion='crear'` (INSERT en vez de UPDATE). Se refactorizó a un array global `presentacionesActuales` + botones `editarPresentacion(id)`/`eliminarPresentacion(id)` que buscan el objeto por `id` — ya no se serializa JSON en atributos HTML.

3. **Nuevo tipo de ajuste "Total (=)"** en el modal Ajustar stock: junto a `entrada`/`merma`/`correccion`, ahora existe `total` para fijar el stock absoluto (ej. tras un conteo físico). `abrirEditar(ins)` guarda `ajusteInsumoActual = ins`; `confirmarAjuste()` calcula `delta = cantidad_ingresada - stock_actual` para este tipo. La etiqueta del campo "Cantidad a ajustar" cambia dinámicamente a "Nuevo stock total" vía `actualizarLabelCantidadAjuste()`, y se permite ingresar `0` (fijar stock en cero) ajustando el gate de envío a `delta !== 0` para este tipo. Sin cambios de backend ni migración — `api/ajustar_stock.php` ya validaba `nuevo_stock >= 0` con `delta` crudo.

### Archivos modificados

| Archivo | Cambio |
|---|---|
| `public_html/inventario/index.php` | 3 fixes descritos arriba (permiso eliminar, refactor presentaciones, tipo "total") |
| `public_html/app/config/app.php` | APP_VERSION → 4.81 |

### Pendiente (próximas sesiones, ver plan v4.81+)

- **v4.82**: normalización numérica a 2 decimales (helper `FormatoHelper.php` + `formatMiles`/`formatDecimal` en `nav.php` + ~15 correcciones de `number_format`/`.toFixed` + eliminación de 4 funciones de formato duplicadas).
- **v4.83-v4.86**: consolidación de arquitectura de presentaciones — `insumo_presentaciones` (mig. 039) como UI primaria con sincronización automática a campos legacy (`PresentacionModel::sincronizarLegacy()`), simplificación del panel "Tipo de empaque" en compras, conversión receta↔equivalencia física, conversión presentación↔ajuste de stock/conteo.

*Última actualización: 2026-06-10 | v4.81 — 3 fixes en inventario (eliminar insumo, editar presentación sin duplicar, ajuste de stock tipo "total"), sin migraciones. Plan completo de continuación en `.claude/plans` (v4.82 normalización numérica, v4.83-v4.86 arquitectura de presentaciones).*

## Estado v4.82 (2026-06-10)

### Fase 2 — Normalización numérica a 2 decimales (formato es-CO), sin migración

Se confirmó con el usuario mantener el formato colombiano ya dominante (punto = miles, coma = decimales) — esta fase **no cambia separadores**, solo unifica la precisión a 2 decimales en todas las cantidades visibles.

### Helpers nuevos (fuente única de formato)

| Helper | Archivo | Uso |
|---|---|---|
| `fmt_cantidad(float $n, int $dec=2): string` | `app/helpers/FormatoHelper.php` (nuevo) | `number_format($n, $dec, ',', '.')` — disponible globalmente vía `require` en `auth_check.php` |
| `formatMiles(n)` | `app/views/nav.php` (script inline) | Entero redondeado con separador de miles es-CO — reemplaza los `formatPeso`/`fmt` duplicados |
| `formatDecimal(n, dec=2)` | `app/views/nav.php` (script inline) | `toLocaleString('es-CO', {minimumFractionDigits, maximumFractionDigits})` |

### Eliminación de 4 funciones de formato duplicadas

| Archivo | Antes | Después |
|---|---|---|
| `ventas/index.php` | `function formatPeso(n){...}` | usa `formatMiles` global |
| `inventario/compras.php` | `function formatPeso(n){...}` | usa `formatMiles` global |
| `productos/index.php` | `function fmt(n){...}` | `var fmt = formatMiles;` |
| `ventas/historial.php` | `function fmt(n){...}` | `var fmt = formatMiles;` |

### Correcciones de precisión — PHP `number_format` (3/1 decimales → 2)

| Archivo | Campo |
|---|---|
| `inventario/lista_compras.php` | `stock_actual`, `cantidad_sugerida` |
| `reportes/operativo.php` | `stock_actual`, `stock_seguridad` |
| `reportes/compras.php` | `total_cantidad` |
| `reportes/ventas.php` | `costo_fijo_u` (XLSX y HTML, antes 2 sin separador y 1 decimal respectivamente — ahora `,2,',','.'` en ambos) |
| `admin/backup.php` | tamaño de tabla en KB |

**2 correcciones omitidas (falsos positivos del plan)**: `productos/consolidar.php:397` y `inventario/conteo.php:159` son valores de `<input type="number">` — el HTML5 exige separador decimal `.` (punto) independientemente del locale de visualización. Cambiarlos a coma habría invalidado el input. Se dejaron sin tocar, consistente con el principio "no se cambian separadores".

### Correcciones de precisión — JS `.toFixed` (3-4 decimales → 2)

| Archivo | Función | Campos |
|---|---|---|
| `inventario/index.php` | `calcCostoAj()` (modal Ajustar) | costo unitario, cantidad recalculada, preview costo |
| `inventario/index.php` | `calcCosto()` (modal Agregar Insumo) | mismos campos — **duplicado no documentado en el plan original**, detectado por ser código idéntico al de `calcCostoAj()`; corregido para mantener consistencia entre ambos modales |
| `productos/index.php` | receta/variantes | `cantidad_requerida` (×2), `total` calculadora, `factor_receta` |
| `activos/index.php` | toasts de subida de foto | tamaño de archivo en MB (×2) |

`precio.toFixed(0)` se dejó sin cambios en ambas funciones de `inventario/index.php` (precio de presentación en COP enteros, fuera del alcance de "normalizar cantidades").

### Correcciones adicionales en `inventario/compras.php` (halladas en la "verificación adicional" del plan)

- `_actualizarHintCant()`: `total.toLocaleString('es-CO',{maximumFractionDigits:3})` → `2`
- `calcDesdePres()`: hint de equivalencia física y cantidad calculada — `{minimumFractionDigits:4,...}`/`{maximumFractionDigits:4}` → `2` (solo los hints de **visualización**; los valores que viajan al backend para `DECIMAL(12,4)` se dejaron en su precisión de cálculo original)

### Archivos modificados (13) + 1 nuevo

| Archivo | Cambio |
|---|---|
| `app/helpers/FormatoHelper.php` | **Nuevo** — `fmt_cantidad()` |
| `app/middleware/auth_check.php` | `require_once` de FormatoHelper |
| `app/views/nav.php` | + `formatMiles()`/`formatDecimal()` |
| `inventario/index.php` | `.toFixed` en `calcCosto()`/`calcCostoAj()` (8 cambios) |
| `inventario/compras.php` | elimina `formatPeso`, 3 hints de precisión |
| `inventario/lista_compras.php` | 2× `number_format` 3→2 |
| `productos/index.php` | elimina `fmt` duplicado, 4× `.toFixed` |
| `ventas/index.php` | elimina `formatPeso` duplicado |
| `ventas/historial.php` | elimina `fmt` duplicado |
| `reportes/operativo.php` | 2× `number_format` 3→2 |
| `reportes/compras.php` | 1× `number_format` 3→2 |
| `reportes/ventas.php` | 2× `number_format` corregido a `,2,',','.'` |
| `admin/backup.php` | `number_format` KB 1→2 |
| `activos/index.php` | 2× `.toFixed(1)`→`.toFixed(2)` |
| `app/config/app.php` | APP_VERSION → 4.82 |

### Verificación

`php -l` sin errores en los 14 archivos tocados/creados. `tests/suite.php` requiere sesión superadmin en navegador (no ejecutable por CLI) — verificación visual pendiente para próxima sesión.

### Pendiente (próximas sesiones, ver plan v4.81+)

- **v4.83-v4.86**: consolidación de arquitectura de presentaciones — `insumo_presentaciones` (mig. 039) como UI primaria con sincronización automática a campos legacy (`PresentacionModel::sincronizarLegacy()`), simplificación del panel "Tipo de empaque" en compras, conversión receta↔equivalencia física, conversión presentación↔ajuste de stock/conteo. Riesgo medio-alto (toca trigger `costo_actual`) — recomendado para sesión dedicada.

*Última actualización: 2026-06-10 | v4.82 — normalización numérica a 2 decimales (es-CO): nuevo helper `FormatoHelper.php` + `formatMiles`/`formatDecimal` en nav.php, 4 funciones duplicadas eliminadas, ~9 correcciones `number_format`/`.toFixed`, sin migraciones. Próximo ciclo: v4.83 (consolidación arquitectura de presentaciones, sesión dedicada por riesgo medio-alto).*

## Estado v4.83 (2026-06-10)

### Fase 3.1 — Consolidación UI de insumos + sincronización automática a capa legacy, sin migración

El usuario decidió continuar con v4.83 en la misma sesión que v4.82, pese a la recomendación del plan de dedicarle una sesión aparte (riesgo medio-alto: toca el trigger `costo_actual`). Dirección: `insumo_presentaciones` (mig. 039) pasa a ser la **única UI editable** para "cómo se compra el insumo". Los campos legacy `insumos.presentacion/cantidad_presentacion/precio_presentacion` (mig. 010, de los que depende el trigger `costo_actual`) se mantienen en BD pero ahora se sincronizan automáticamente desde la presentación marcada `es_predeterminada=1`. **Cero migraciones SQL.**

### `PresentacionModel::sincronizarLegacy(int $insumo_id): void` (nuevo)

- Lee la presentación `es_predeterminada=1 AND activo=1` del insumo.
- Si no existe o `precio_referencia IS NULL`, no hace nada (evita pisar el último costo real de compra con un precio de referencia vacío).
- `UPDATE insumos SET presentacion=:unidad_compra, cantidad_presentacion=:cantidad_base, precio_presentacion=:precio_referencia WHERE id=:insumo_id`.
- **Decisión de mapeo**: `unidad_compra` (VARCHAR 30, ej. "frasco") → `insumos.presentacion` (VARCHAR 30 desde mig. 031), NO `nombre` (VARCHAR 60, ej. "Frasco 900ml") — `insumos.presentacion` se usa en `compras.php` como etiqueta corta de tipo de empaque (`Cantidad (${ins.presentacion}s)`), consistente con `unidad_compra` y el catálogo `listas_sistema` tipo=`presentacion`.
- El `UPDATE` dispara el trigger `trg_insumos_costo_from_presentacion_update` (mig. 010, sin modificar) que recalcula `costo_actual = ROUND(precio_presentacion / cantidad_presentacion, 4)`.

### `inventario/api/presentaciones.php`

- Tras `accion=crear` o `accion=editar` exitoso con `es_predeterminada=1` (POST), llama a `PresentacionModel::sincronizarLegacy($insumo_id)`. En `editar`, `$insumo_id` viene de `$_POST['insumo_id']` (siempre enviado por `guardarPresentacion()`).

### `inventario/index.php` — modal "Agregar Insumo" simplificado

- Eliminada la sección "Presentación de compra" (capa 1): select `ni-pres`, calculadora bidireccional `ni-cant-pres`/`ni-precio-pres`/preview.
- Nueva sección "Unidad y costo": solo `ni-unidad` (unidad básica) + `ni-costo` como campo directo opcional ("Costo actual por unidad"), con hint invitando a configurar presentaciones después de guardar.
- Se mantiene `ni-equiv-sec` (equivalencia física) sin cambios — no es parte de "presentación de compra".
- Eliminada por completo la función `calcCosto()` (calculadora bidireccional del modal Agregar Insumo) — sus campos ya no existen.
- `guardarNuevoInsumo()` ya no envía `presentacion`/`cantidad_presentacion`/`precio_presentacion` (el backend `insumo_crud.php` los trata como `NULL` si están ausentes; `costo_actual` se guarda directo desde `ni-costo`).

### `inventario/index.php` — modal "Ajustar": capa 1 read-only + badge cuando hay catálogo

- `$insumos` (de `InsumoModel::todos_con_estado()`) ahora incluye `pres_cat` por insumo (merge de `PresentacionModel::todas_agrupadas()`, mismo patrón que `compras.php`).
- Nueva función `actualizarCapa1ReadOnly(ins)`: si `ins.pres_cat.length > 0`, marca `aj-pres`/`aj-cant-pres`/`aj-precio-pres`/`aj-costo` como `disabled`/`readOnly` (gris) y muestra el badge "snapshot automático desde presentación predeterminada" junto al título "Presentación y costo". Si `pres_cat` está vacío, quedan editables (fallback para insumos simples sin catálogo). `aj-unidad` (unidad básica del insumo) **no** se incluye — es una propiedad del insumo, no de "cómo se compra".
- `cargarPresentaciones()` también llama `actualizarCapa1ReadOnly({pres_cat: pres})` tras cada fetch, para reflejar el cambio de inmediato si el usuario agrega/elimina presentaciones sin recargar la página.

### `inventario/index.php` — flujo "crear insumo → invitar a configurar presentación"

- Tras `guardarNuevoInsumo()` exitoso, ya no se hace `location.reload()` inmediato: se cierra el modal "Agregar Insumo", se construye un objeto `ins` mínimo con los datos recién guardados (`pres_cat: []`) y se llama `abrirEditar(ins)`, dejando expandidos `aj-pres-section` y `aj-pres-form-wrap` (mig. 039) para invitar a registrar la primera presentación de compra. El reload ocurre normalmente al guardar desde el modal Ajustar (`confirmarAjuste()`).

### Archivos modificados (3)

| Archivo | Cambio |
|---|---|
| `app/models/PresentacionModel.php` | + `sincronizarLegacy(int $insumo_id): void` |
| `inventario/api/presentaciones.php` | crear/editar con `es_predeterminada=1` → llama `sincronizarLegacy()` |
| `inventario/index.php` | `pres_cat` en `$insumos`, modal Agregar simplificado, capa 1 read-only+badge en Ajustar, flujo post-creación, elimina `calcCosto()` |
| `app/config/app.php` | APP_VERSION → 4.83 |

### Verificación

`php -l` sin errores en los 3 archivos PHP tocados. **Pendiente prueba manual en navegador** (siguiente sesión o antes de cerrar): crear insumo → agregar presentación predeterminada con precio de referencia → verificar que `costo_actual` se recalcula (trigger mig. 010) y que la capa 1 del modal Ajustar pasa a read-only con badge; comprar usando esa presentación → verificar que `costo_actual`/`stock_actual` siguen actualizándose igual que en v4.80.

### Pendiente (próximas sesiones, ver plan v4.81+)

- **v4.84**: simplificar panel "Tipo de empaque" en `compras.php` — ocultar badge legacy (capa 1) cuando `pres_cat.length > 0`.
- **v4.85**: conversión receta↔equivalencia física en `productos/index.php`.
- **v4.86**: conversión presentación↔ajuste de stock/conteo en `inventario/index.php` y `inventario/conteo.php`.

*Última actualización: 2026-06-10 | v4.83 — consolidación de arquitectura de presentaciones (Fase 3.1): `PresentacionModel::sincronizarLegacy()` sincroniza la presentación predeterminada (mig. 039) hacia los campos legacy (mig. 010) disparando el trigger `costo_actual`; modal "Agregar Insumo" simplificado (sin calculadora capa 1); modal "Ajustar" marca capa 1 read-only+badge cuando hay catálogo; tras crear insumo se invita a configurar su primera presentación. Sin migraciones. Pendiente prueba manual del flujo completo. Próximo ciclo: v4.84 (simplificar panel "Tipo de empaque" en compras.php).*

## Estado v4.84 (2026-06-10)

### Fase 3.2 — Simplificar panel "Tipo de empaque" en compras.php, sin migración

Continuación directa de v4.83: ahora que `insumo_presentaciones` (mig. 039) es la fuente primaria de "cómo se compra el insumo", el panel legacy de `compras.php` (capa 1, derivado de `insumos.presentacion/cantidad_presentacion/equiv_*`) queda redundante cuando el insumo tiene catálogo — `pres-cat-block` (capa 3) ya muestra esa información (y más: precio de referencia, selector de presentación específica).

### `inventario/compras.php` — `agregarLinea()`

- El panel informativo `<div class="linea-pres" id="pres-block-${n}">` (badge tipo de empaque + unidad básica + cant/empaque + equivalencia + hint de total) quedó envuelto en un nuevo contenedor `<div id="pres-legacy-${n}">`, para poder ocultarlo como unidad sin tocar sus IDs internos (`pres-tipo-lbl-${n}`, `pres-equiv-lbl-${n}`, `pres-total-hint-${n}`, etc.) ni la lógica que ya los rellena.

### `inventario/compras.php` — `selectInsumo()`

- Nueva referencia `presLegacy = document.getElementById('pres-legacy-' + n)`.
- Dentro del bloque que resuelve `cats = ins.pres_cat || []`: `presLegacy.style.display = cats.length > 0 ? 'none' : ''`.
  - **Con catálogo** (`pres_cat.length > 0`): se oculta el panel legacy completo; solo se muestra `pres-cat-block` (selector de presentación + detalle + hint de cálculo).
  - **Sin catálogo** (`pres_cat` vacío, insumo simple): el panel legacy permanece visible como fallback informativo — comportamiento idéntico a versiones anteriores.
- `equiv-hint-${n}` (en `units-row-${n}`, fuera de `pres-legacy-${n}`) no se ve afectado — sigue mostrando el hint "= X unidades total" independientemente del estado del panel legacy, ya que `_actualizarHintCant()` actualiza ambos (`pres-total-hint-${n}` dentro del panel oculto y `equiv-hint-${n}` siempre visible) sin distinción.
- Sin cambios en `agregarLineaModal()`/`mSelectInsumo()` (modal de edición de compra): esa vista no tiene `pres-cat-block`, está fuera del alcance del plan.

### Archivos modificados

| Archivo | Cambio |
|---|---|
| `inventario/compras.php` | Envuelve panel legacy en `pres-legacy-${n}`; `selectInsumo()` lo oculta cuando `pres_cat.length > 0` |
| `app/config/app.php` | APP_VERSION → 4.84 |

### Verificación

`php -l` sin errores en `compras.php`. **Pendiente prueba manual en navegador**: seleccionar un insumo con presentaciones catalogadas (mig. 039) → confirmar que el panel "📦 [tipo] · [unidad] · [cant/empaque]" desaparece y solo se ve el selector "📦 Presentación catalogada"; seleccionar un insumo sin catálogo → confirmar que el panel legacy sigue apareciendo igual que antes.

### Pendiente (próximas sesiones, ver plan v4.81+)

- **v4.85**: conversión receta↔equivalencia física en `productos/index.php`.
- **v4.86**: conversión presentación↔ajuste de stock/conteo en `inventario/index.php` y `inventario/conteo.php`.

*Última actualización: 2026-06-10 | v4.84 — simplifica panel "Tipo de empaque" en compras.php: envuelve el panel legacy (capa 1) en `pres-legacy-${n}` y lo oculta cuando el insumo tiene catálogo de presentaciones (mig. 039), mostrando solo `pres-cat-block` (capa 3); sin catálogo, el panel legacy permanece como fallback. Sin migraciones. Pendiente prueba manual. Próximo ciclo: v4.85 (conversión receta↔equivalencia física en productos/index.php).*

## Estado v4.85 (2026-06-11)

### Fase 3.3 — Conversión receta ↔ equivalencia física en productos/index.php, sin migración

Continuación del plan v4.81+ (Fase 3): escenario "120 g de huevo → 2 unidades". El insumo "Huevo" tiene `unidad_medida='g'` y `equiv_cantidad=160, equiv_unidad='unidad'` (mig. 030, "1 unidad = 160 g"). Antes de v4.85, al agregar este insumo a una receta había que calcular mentalmente cuántos gramos pesan "2 unidades". Ahora el formulario permite ingresar la cantidad directamente en `equiv_unidad` y la convierte a `unidad_medida` (la única que viaja a `guardar_receta.php`/`cantidad_requerida`) antes de guardar. **100% conversión de entrada (UX) — `RecetaModel`/BD no cambian.**

### `productos/index.php` — `INSUMOS` (JS) y `optIns`

- `INSUMOS` (línea ~541) ahora incluye `equiv_cantidad`/`equiv_unidad` por insumo (ya expuestos por `InsumoModel::todos()`, sin cambios de backend).
- `optIns` (dentro de `renderReceta()`) agrega `data-equiv-cant`/`data-equiv-unidad` (además de `data-costo`/`data-u` existentes) a cada `<option>` del selector de insumo.

### `productos/index.php` — formulario "Agregar ingrediente" (`addForm`)

- Selector de insumo `si-${id}` ahora dispara `onSelectInsumoReceta(${id})` en `onchange`.
- Input de cantidad `ci-${id}` dispara `convertirCantidadReceta(${id})` en `oninput`.
- Nuevos elementos, ocultos por defecto: `<span id="cu-label-${id}">en:</span>` + `<select id="cu-${id}">` ("Ingresar en: [unidad_medida | equiv_unidad]") + `<span id="cu-hint-${id}">` (hint de conversión en vivo, ej. "= 0,75 unidad").

### `productos/index.php` — nuevas funciones JS

- **`onSelectInsumoReceta(prodId)`**: lee `data-equiv-cant`/`data-equiv-unidad` del insumo seleccionado. Si `equivCant > 0 && equivUnidad`, llena `cu-${id}` con `<option value="base">unidad_medida</option>` + `<option value="equiv">equiv_unidad</option>` y muestra el selector + label "en:"; si no, los oculta. Se invoca al final de `renderReceta()` (sobre el insumo preseleccionado) y en cada cambio de `si-${id}`.
- **`convertirCantidadReceta(prodId)`**: si `cu-${id}` está visible y en `'equiv'`, calcula `cantidad / equivCant` y muestra el hint "= X unidad_medida" (formateado con `formatDecimal`, 2 decimales). Si no, oculta el hint.
- **`addIng(prodId)`**: si `cu-${id}` está en `'equiv'` al guardar, convierte `cantidad = (cantidad / equivCant).toFixed(6)` antes de enviarla a `guardar_receta.php` — `cantidad_requerida` (DECIMAL 12,6) sigue almacenándose en `unidad_medida` del insumo, igual que siempre.

### Verificación

`php -l` sin errores en `productos/index.php`. `guardar_receta.php` confirmado sin cambios necesarios — solo recibe `cantidad` ya convertida. **Pendiente prueba manual en navegador**: en un insumo con `equiv_cantidad`/`equiv_unidad` configurados (ej. Huevo: 160 g = 1 unidad), expandir receta → seleccionar ese insumo → confirmar que aparece selector "en: [g | unidad]" → elegir "unidad", ingresar "2" → confirmar hint "= 320,00 g" → Agregar → confirmar que `cantidad_requerida` quedó en 320 g (no en 2). Insumos sin `equiv_cantidad`/`equiv_unidad` deben comportarse exactamente igual que antes (selector oculto).

### Pendiente (próximas sesiones, ver plan v4.81+)

- **v4.86**: conversión presentación↔ajuste de stock/conteo en `inventario/index.php` y `inventario/conteo.php`.
- Prueba manual combinada v4.83+v4.84+v4.85 (ver notas de cada versión).

*Última actualización: 2026-06-11 | v4.85 — productos/index.php: formulario de ingrediente de receta agrega selector "Ingresar en: [unidad_medida | equiv_unidad]" cuando el insumo tiene equivalencia física (mig. 030); nuevas funciones `onSelectInsumoReceta()`/`convertirCantidadReceta()`; `addIng()` convierte la cantidad a `unidad_medida` antes de enviarla a `guardar_receta.php`. 100% UX, sin cambios de backend ni migraciones. Pendiente prueba manual. Próximo ciclo: v4.86 (conversión presentación↔ajuste de stock/conteo).*

## Estado v4.86 (2026-06-11)

### Fase 3.4 (cierre Fase 3) — Conversión presentación ↔ ajuste de stock / conteo, sin migración

Cierra la Fase 3 del plan v4.81+ (arquitectura de presentaciones). Escenario: "conté 3 bidones de 18L". El insumo tiene `unidad_medida='L'` y una presentación catalogada (mig. 039) "Bidón 18L" con `cantidad_base=18`. Antes de v4.86, había que calcular mentalmente 3×18=54 e ingresar "54" a mano. Ahora un helper "Convertir desde presentación" + "Nro." calcula y llena el campo automáticamente. **100% UX — no toca `ajustar_stock.php`, `conteo_guardar.php` ni el trigger `costo_actual`.**

### `inventario/index.php` — modal "Ajustar stock"

- Nuevo bloque `<div id="aj-pres-conv-wrap">` (oculto por defecto) entre "Cantidad a ajustar" y "Motivo": `<select id="aj-pres-conv-sel">` (presentaciones catalogadas del insumo, con la `es_predeterminada` preseleccionada) + `<input id="aj-pres-conv-num">` ("Nro.") + hint `<span id="aj-pres-conv-hint">`.
- **`actualizarConversionPresentacionAj(ins)`** (nueva): si `ins.pres_cat.length > 0`, llena `aj-pres-conv-sel` con `<option value="cantidad_base">nombre (cantidad_base unidad_medida/u)</option>` por presentación y muestra el bloque; si está vacío, lo oculta. Se invoca desde `abrirEditar(ins)` (independiente de `TIENE_PRESENT`, solo depende de `pres_cat`/mig. 039) y desde `cargarPresentaciones()` (para refrescar en vivo si el usuario agrega/elimina presentaciones sin recargar).
- **`convertirDesdePresentacionAj()`** (nueva): lee `cantidad_base` de la opción seleccionada × "Nro.", escribe el resultado en `aj-cantidad` (`.toFixed(3)`, consistente con `step="0.001"`) y muestra el hint "= X unidad_medida". El valor resultante fluye sin cambios al flujo existente de `confirmarAjuste()` (funciona con `tipo='total'`, `'correccion'`, `'entrada'`, etc. — v4.81 §1.3).

### `inventario/conteo.php`

- Ahora incluye `PresentacionModel` y agrega `pres_cat` (solo `nombre`+`cantidad_base`) a cada insumo, igual que `compras.php`/`inventario/index.php`.
- Cada tarjeta de insumo con `pres_cat` no vacío gana un bloque `.ins-pres-conv`: `<select id="pc-sel-${id}">` (presentaciones, value=`cantidad_base`) + `<input id="pc-num-${id}">` ("Nro.").
- **`convertirDesdePresentacionConteo(id)`** (nueva): `cantidad_base × Nro.` → `inp-${id}.value` (`.toFixed(2)`, consistente con `step="0.01"` del conteo) y llama a `marcarCambio(id, stock_anterior)` para que la tarjeta se marque como modificada y el botón "Guardar conteo" se habilite, igual que si el usuario hubiera tecleado el valor directamente.

### Archivos modificados

| Archivo | Cambio |
|---|---|
| `inventario/index.php` | `aj-pres-conv-wrap` en modal Ajustar; `actualizarConversionPresentacionAj()`/`convertirDesdePresentacionAj()`; llamadas desde `abrirEditar()` y `cargarPresentaciones()` |
| `inventario/conteo.php` | `pres_cat` por insumo; bloque `.ins-pres-conv` por tarjeta; `convertirDesdePresentacionConteo()` |
| `app/config/app.php` | APP_VERSION → 4.86 |

### Verificación

`php -l` sin errores en los 3 archivos PHP tocados. **Pendiente prueba manual en navegador**: en un insumo con presentación catalogada (ej. "Bidón 18L", `cantidad_base=18`, `unidad_medida='L'`) — (1) modal Ajustar: seleccionar "Bidón 18L", ingresar "3" en Nro. → confirmar que "Cantidad a ajustar" queda en 54.000 y el hint dice "= 54,00 L"; probar con `tipo='total'` y `tipo='correccion'`; (2) Conteo de Stock: mismo insumo, usar el selector+Nro. de la tarjeta → confirmar que el campo de stock contado queda en 54.00 y la tarjeta se marca como modificada (botón "Guardar conteo" habilitado). Insumos sin `pres_cat` deben verse exactamente igual que antes (sin el bloque nuevo).

### Cierre de la Fase 3 (plan v4.81+)

Con v4.86 se cierran las 3 fases del plan `curried-napping-hollerith.md` (v4.81→v4.86). Pendiente acumulado para próximas sesiones: pruebas manuales en navegador de v4.83 a v4.86 (ver cada sección "Verificación").

*Última actualización: 2026-06-11 | v4.86 — cierre Fase 3 (arquitectura de presentaciones): inventario/index.php (modal Ajustar) e inventario/conteo.php agregan helper "Convertir desde presentación" (selector de presentación catalogada + "Nro.") que calcula `cantidad_base × Nro.` y llena el campo de cantidad/stock contado correspondiente. 100% UX, sin cambios de backend ni migraciones. Pendiente prueba manual. Plan v4.81+ completo (v4.81-v4.86).*

## Estado v4.87 (2026-06-11)

### Configuración global de formato numérico (decimales y separadores) — infraestructura + piloto inventario

El usuario pidió que el número de decimales y los separadores de miles/decimales sean
**configurables desde Admin → Apariencia** y se apliquen a todos los campos numéricos
(lectura e inputs) de todos los módulos. Decisiones acordadas:

1. **Alcance v4.87**: construir toda la infraestructura y aplicarla de inmediato solo a
   `inventario/index.php` y `inventario/conteo.php` (piloto). El resto de módulos
   (~8 módulos / ~30 archivos con `number_format`) se migran uno por uno en v4.88+, mismo
   ritmo que v4.81→v4.86.
2. **"Decimales" configurable aplica solo a CANTIDADES** (stock, presentaciones,
   equivalencias, costo por unidad). Los **precios/montos en pesos** se mantienen siempre en
   0 decimales (invariante "no tocar precios"). Los **separadores** (miles/decimal) aplican a
   ambos tipos para apariencia consistente.

Limitación de navegador: `<input type="number">` siempre usa `.` como decimal y nunca agrupa
miles — eso no es configurable. Lo que sí es configurable en inputs es la **cantidad de
decimales** (`toFixed(N)` + `step="any"`).

### Migración 040 (`database/migrations/040_config_formato_numerico.sql`)

`INSERT IGNORE` en `configuracion_app`: `num_decimales` (default `'2'`), `num_sep_miles`
(default `'.'`), `num_sep_decimal` (default `','`). Reutiliza la tabla existente
clave/valor — sin tabla nueva.

### `app/helpers/FormatoHelper.php`

- **`config_numeros(): array`** — lee las 3 claves de `configuracion_app` (cacheado por
  request vía `static $cfg`), devuelve `['decimales'=>2,'sep_miles'=>'.','sep_decimal'=>',']`
  con defaults si la tabla/claves no existen; `decimales` clamped 0-4.
- **`fmt_cantidad(float $n, ?int $dec=null): string`** — `number_format($n, $dec ??
  $cfg['decimales'], $cfg['sep_decimal'], $cfg['sep_miles'])`. Usar para stock,
  presentaciones, equivalencias y costo por unidad.
- **`fmt_moneda(float $n): string`** — siempre 0 decimales, separadores configurables. Usar
  para precios/montos en pesos.

### `app/views/nav.php`

- `$_theme` extendido con `num_decimales`/`num_sep_miles`/`num_sep_decimal` (defaults `'2'`,
  `'.'`, `','`), leídos del mismo `SELECT` de configuración de apariencia y validados
  (clamp 0-4; separadores en whitelist; si miles==decimal, se restauran defaults).
- Nuevo `window.NUM_FORMAT = {decimales, sepMiles, sepDecimal}` inyectado a JS.
- **`formatDecimal(n, dec)`** reescrito (ya no usa `toLocaleString`): si `dec===undefined`
  usa `NUM_FORMAT.decimales`; `toFixed()` + regex de agrupación de miles con el separador
  configurado. **`formatMiles(n)` = `formatDecimal(n, 0)`**.

### Admin → Apariencia (`admin/apariencia.php` + `admin/api/guardar_apariencia.php`)

- Nueva tarjeta "Formato de números" con 3 `<select>`: decimales para cantidades (0-4,
  default 2), separador de miles (`.`/`,`/` `/`'`, default `.`), separador decimal
  (`,`/`.`, default `,`).
- `guardar_apariencia.php` valida (clamp 0-4; whitelist de separadores; si miles==decimal se
  fuerza a defaults `.`/`,`) y persiste en `configuracion_app`. Mismo permiso
  `superadmin`/`admin` existente.

### `inventario/index.php` (piloto)

- Listado: `stock_actual`, `stock_seguridad`, `costo_actual`, `equiv_cantidad` y
  `cantidad_presentacion`/`precio_presentacion` ahora usan `fmt_cantidad()`/`fmt_moneda()`.
- Inputs de cantidad (`ni-stock`, `ni-seg`, `ni-equiv-cant`, `aj-seg`, `aj-cantidad`,
  `aj-cant-pres`, `aj-costo`, `aj-equiv-cant`, `pf-cantidad-base`, `pf-equiv-cant`):
  `step="any"` (antes `step="0.001"`/`"0.0001"`/`"0.01"`).
- JS (`abrirEditar`, `calcCostoAj`, `convertirDesdePresentacionAj`, `cargarPresentaciones`,
  `editarPresentacion`): `formatDecimal(x, 2)` → `formatDecimal(x)` y `.toFixed(2)` →
  `.toFixed(NUM_FORMAT.decimales)`; `precio_referencia` en `cargarPresentaciones` ahora usa
  `formatMiles()`.
- Sin cambios: `ni-costo`, `aj-precio-pres`, `pf-precio-ref` (precios, 0 decimales) y
  `aj-pres-conv-num` (helper v4.86, fuera de alcance).

### `inventario/conteo.php` (piloto)

- "Actual: X unidad" y la cantidad por presentación del selector de conversión usan
  `fmt_cantidad()`.
- Input de conteo: `step="any"`; `placeholder` usa `config_numeros()['decimales']` (mantiene
  `.` por limitación de `<input type="number">`).
- JS: `convertirDesdePresentacionConteo()` y la actualización post-guardado usan
  `NUM_FORMAT.decimales`/`formatDecimal()` en vez de `2` fijo.

### Archivos modificados

| Archivo | Cambio |
|---|---|
| `database/migrations/040_config_formato_numerico.sql` | nueva — defaults num_decimales/num_sep_miles/num_sep_decimal |
| `app/helpers/FormatoHelper.php` | `config_numeros()`, `fmt_cantidad()`, `fmt_moneda()` |
| `app/views/nav.php` | `window.NUM_FORMAT`, `formatDecimal()`/`formatMiles()` reescritos |
| `admin/apariencia.php` + `admin/api/guardar_apariencia.php` | sección "Formato de números" |
| `inventario/index.php` | `fmt_cantidad()`/`fmt_moneda()`, `step="any"`, `NUM_FORMAT.decimales` |
| `inventario/conteo.php` | idem, piloto |
| `app/config/app.php` | APP_VERSION → 4.87 |

### Verificación

`php -l` sin errores en los 6 archivos PHP tocados (3 bloques, commits separados).
**Pendiente prueba manual en navegador**: (1) con config por defecto (2/`.`/`,`), confirmar
que `inventario/index.php` y `conteo.php` se ven igual que antes (sin regresiones) y que los
inputs `step="any"` siguen guardando bien; (2) en Admin → Apariencia cambiar a 3 decimales y
separadores estilo en-US, recargar ambas páginas y confirmar que cantidades muestran 3
decimales con nuevos separadores, inputs aceptan/guardan 3 decimales (con `.`), y los precios
siguen en 0 decimales (solo cambian separadores); (3) volver la config a los valores por
defecto antes de cerrar sesión, para no afectar al resto de módulos que aún no usan estos
helpers.

### v4.88+ (futuro)

Replicar el patrón módulo por módulo: productos, ventas, nómina, compras, reportes, activos,
costos, clientes — un módulo por versión, igual que v4.81→v4.86.

*Última actualización: 2026-06-11 | v4.87 — infraestructura de formato numérico configurable
(decimales + separadores de miles/decimal) vía Admin → Apariencia (migración 040 +
`FormatoHelper.php` + `NUM_FORMAT` en `nav.php`), aplicada como piloto en
`inventario/index.php` y `inventario/conteo.php`. Precios se mantienen en 0 decimales.
Pendiente prueba manual en navegador (default + config alternativa) antes de extender a
v4.88+.*

---

## Estado v4.88 (2026-06-12)

### Formato numérico configurable — módulo Productos completo

Continuación de v4.87: se replicó el patrón `fmt_cantidad()`/`fmt_moneda()`/`NUM_FORMAT`/
`formatDecimal()`/`formatMiles()`/`step="any"` en los 4 archivos del módulo **productos**, uno
por bloque/commit, siguiendo el ritmo v4.81→v4.87.

**Categorización aplicada (consistente con v4.87):** "decimales" configurable solo en
CANTIDADES (stock, presentaciones, equivalencias, cantidades de receta, costo por unidad,
unidades de producción) vía `fmt_cantidad()`/`formatDecimal()`/`NUM_FORMAT.decimales`;
precios/montos en pesos siempre 0 decimales vía `fmt_moneda()`; separadores configurables en
ambos. Quedan **fuera de alcance** (sin cambios): campos "factor" multiplicador
(`factor_receta`/`factor_calc`, `step` fijo 0.1-0.001), valores internos de precisión solo
para envío a API (`.toFixed(6)`), salidas PHP crudas no envueltas en `number_format` (para no
cambiar el redondeo por defecto), y el `value`/`step` del input de precio de variante en
consolidar.php (precisión fija de 2 decimales con `.` requerido por `<input type=number>`).

### `productos/index.php` (commit `45492a3`)

- Costos fijos prorrateados por unidad y precios/costos de la tabla de productos →
  `fmt_moneda()`; "Dep. diaria" → `fmt_cantidad($x, 2)`.
- JS: `renderReceta` (`cantidad_requerida` ×2) → `.toFixed(NUM_FORMAT.decimales)`; hint de
  equivalencia y total de calculadora de producción → `formatDecimal()`.
- Inputs de cantidad de receta/combo (`ci-*`, `calc-qty-*`, `combo-qty-*`,
  `combo-add-qty-*`) → `step="any"` (antes `step="0.001"`).
- Sin cambios: `.toFixed(6)` interno (envío a API) y campos `factor_receta` (`step="0.1"`).

### `productos/analisis.php` (commit `c3f55f2`)

Página 100% server-rendered, sin JS de formato. 52 sustituciones en 49 líneas vía script
Python temporal (line-anchored, borrado tras aplicar):
- Unidades (producción, ventas, proyecciones, punto de equilibrio) → `fmt_cantidad($x, 0)`.
- Montos en pesos (KPIs, desglose de costos) → `fmt_moneda($x)`.
- Costos fijos por unidad (cap./real) → `fmt_cantidad($x, 2)`.
- Sin cambios: salidas PHP crudas (`round($x,1)`, `$prom_dia`, `$util_pct`, etc.) no envueltas
  previamente en `number_format`.

### `productos/consolidar.php` (commit `ab1056f`)

- Comparativa de ingredientes (`cantidad_requerida` base/fuente) → `fmt_cantidad($x, 3)`
  (corrige separadores `number_format($x,3)` que usaba el formato US por defecto de PHP,
  preservando los 3 decimales de precisión).
- Precio de venta en listas de selección de producto base/absorber → `fmt_moneda()`.
- Sin cambios: `value` del input de precio de variante (2 decimales fijos, `.` requerido por
  `<input type=number>`), `factor_calc`/`factor_receta` (campo multiplicador) y stock crudo
  (`$f['stock']`, `array_sum(...)` — enteros no envueltos en `number_format`).

### `productos/produccion.php` (commit `c849f63`)

- "Prom./día" en la tabla de sugerencia de producción → `fmt_cantidad($x, 1)`.
- "Costo/u (al producir)" de cada tanda → `fmt_moneda()`.
- **Fuera de alcance**: `descuento`/`restante` del preview de insumos usan
  `toLocaleString('es-CO', {maximumFractionDigits:4})` — el mismo patrón
  `toLocaleString('es-CO', ...)` aparece en ~20 sitios de otros módulos (ventas, clientes,
  costos, activos, nómina, inventario/compras.php); se migrará en un bloque dedicado
  (candidato a v4.89) en vez de crear un helper especial solo para estas 2 líneas.

### Archivos modificados

| Archivo | Cambio |
|---|---|
| `productos/index.php` | `fmt_cantidad()`/`fmt_moneda()`, `NUM_FORMAT.decimales`, `step="any"` |
| `productos/analisis.php` | `fmt_cantidad()`/`fmt_moneda()` (52 sustituciones) |
| `productos/consolidar.php` | `fmt_cantidad($x,3)` comparativa de ingredientes, `fmt_moneda()` precios |
| `productos/produccion.php` | `fmt_cantidad($x,1)` prom./día, `fmt_moneda()` costo/u |
| `app/config/app.php` | APP_VERSION → 4.88 |

### Verificación

`php -l` sin errores en los 4 archivos (4 bloques, 4 commits separados, push tras cada uno).
**Pendiente prueba manual en navegador** (igual que v4.87, sin acceso a navegador/BD desde
este entorno): confirmar con config por defecto (2/`.`/`,`) que las 4 páginas se ven igual
que antes, y con config alternativa (3 decimales, separadores en-US) que cantidades/precios
muestran los nuevos formatos correctamente.

### v4.89+ (futuro)

Candidatos: (a) migrar `toLocaleString('es-CO', {maximumFractionDigits:N})` (~20 sitios en
ventas, clientes, costos, activos, nómina, inventario/compras.php) a un helper con
`NUM_FORMAT`; (b) continuar el patrón v4.87/v4.88 en el resto de módulos (ventas, nómina,
compras, reportes, activos, costos, clientes) — uno por versión.

*Última actualización: 2026-06-12 | v4.88 — formato numérico configurable
(`fmt_cantidad()`/`fmt_moneda()`/`NUM_FORMAT`) aplicado a los 4 archivos del módulo Productos
(index, analisis, consolidar, produccion), 4 commits separados. Precios siguen en 0
decimales. `toLocaleString('es-CO')` de produccion.php queda fuera de alcance (patrón
compartido con ~20 sitios de otros módulos, candidato a v4.89). Pendiente prueba manual en
navegador.*

## Estado v4.89 (2026-06-12)

### Formato numérico configurable — módulo Ventas (POS) completo

Continuación de v4.87/v4.88: se replicó el patrón `fmt_cantidad()`/`fmt_moneda()`/
`NUM_FORMAT`/`formatMiles()` en los 5 archivos del módulo **ventas**, uno por bloque/commit,
siguiendo el ritmo v4.81→v4.88. Todos los montos en ventas son "precio en pesos" → 0
decimales vía `fmt_moneda()`; no se encontraron campos "cantidad" (stock/recetas) en este
módulo, salvo un caso puntual de porcentaje de descuento.

### `ventas/index.php` (commit `6fc087f`)

- Barra de resumen del día (total, efectivo, digital, fiado) y precios de producto en el
  catálogo → `fmt_moneda()`.
- JS: `formatPeso(n){ return '$'+formatMiles(n); }` ya hereda `NUM_FORMAT` (reescrito en
  v4.87) sin cambios.
- **Fuera de alcance**: `step="1"` de descuento % (no es "cantidad"); `saldo`/deuda de cliente
  vía `Math.round(...).toLocaleString('es-CO')` (líneas 1291/1347 — patrón compartido
  diferido, mismo criterio que produccion.php en v4.88).

### `ventas/apertura.php` (commit `4034eb9`)

- Fondo inicial, efectivo cobrado y total en caja (KPIs + historial de turnos) →
  `fmt_moneda()`.
- **Fuera de alcance**: `step="500"` del input de fondo inicial (múltiplos de $500, no es
  "cantidad").

### `ventas/cierre.php` (commit `d8ceeff`)

- Helper local `fmt_cop()` (línea 186, duplicado de `fmt_moneda()` con prefijo `$` propio)
  redefinido como `return '$' . fmt_moneda($n);` — punto único de cambio que cubre sus 9 usos
  en el texto compartido por WhatsApp (cobrado, fiado, obsequio, total, descuentos, meta,
  fondo, total en caja, desglose por método de pago).
- 13 montos en pesos en la vista HTML (tarjetas por método de pago, fondo/efectivo/total caja,
  cobrado/fiado/obsequio/total ventas, descuentos, meta diaria, detalle por producto + total,
  fiados del día) → `fmt_moneda()`.

### `ventas/fiado.php` (commit `975e189`)

- Deuda total pendiente y saldo por cliente (lista, mensaje de WhatsApp y preview de abono) →
  `fmt_moneda()`.
- **Fuera de alcance**: `step="100"` del input de monto de abono (no es "cantidad");
  `toLocaleString('es-CO')` del preview JS y del toast de confirmación de abono (patrón
  compartido diferido).

### `ventas/historial.php` (commit `0938b38`)

- Fiado pendiente del cliente filtrado, KPIs (total recaudado, efectivo, digital, fiado, sin
  cobrar, obsequiados) y total por venta → `fmt_moneda()`.
- `descuento_pct` (badge "−X% dto"): `number_format($x, 0)` sin separadores (bug es-CO latente,
  mismo caso que `cantidad_requerida`/`avg` en v4.88) → `fmt_cantidad($x, 0)`, preservando 0
  decimales.
- **Fuera de alcance**: `var fmt = formatMiles` (modal de edición de items) ya hereda
  `NUM_FORMAT`; `step="1"`/`step="100"` del modal (cantidad entera / precio).

### Archivos modificados

| Archivo | Cambio |
|---|---|
| `ventas/index.php` | `fmt_moneda()` resumen del día + precios de catálogo |
| `ventas/apertura.php` | `fmt_moneda()` fondo/efectivo/total caja (KPIs + historial) |
| `ventas/cierre.php` | `fmt_cop()` redefinido sobre `fmt_moneda()` (9 usos WhatsApp) + 13 montos HTML |
| `ventas/fiado.php` | `fmt_moneda()` deuda total y saldo por cliente |
| `ventas/historial.php` | `fmt_moneda()` KPIs/totales, `fmt_cantidad($x,0)` descuento_pct |
| `app/config/app.php` | APP_VERSION → 4.89 |

### Verificación

`php -l` sin errores en los 5 archivos (5 bloques, 5 commits separados, push tras cada uno).
**Pendiente prueba manual en navegador** (igual que v4.87/v4.88, sin acceso a navegador/BD
desde este entorno): con config por defecto (2/`.`/`,`) confirmar que las 5 páginas de ventas
se ven igual que antes; con config alternativa (separadores en-US) confirmar que montos en
pesos y el badge de descuento muestran los nuevos separadores.

### v4.90+ (futuro)

Candidatos: (a) migrar `toLocaleString('es-CO', {maximumFractionDigits:N})` (~20 sitios en
ventas, clientes, costos, activos, nómina, inventario/compras.php — incluye los 4 sitios
identificados en index.php/fiado.php de este bloque) a un helper con `NUM_FORMAT`; (b)
continuar el patrón v4.87-v4.89 en el resto de módulos (nómina, compras, reportes, activos,
costos, clientes) — uno por versión.

*Última actualización: 2026-06-12 | v4.89 — formato numérico configurable
(`fmt_moneda()`/`fmt_cantidad()`) aplicado a los 5 archivos del módulo Ventas/POS (index,
apertura, cierre, fiado, historial), 5 commits separados, incluyendo el fix del helper local
`fmt_cop()` en cierre.php. Precios siguen en 0 decimales. `toLocaleString('es-CO')` de
index.php/fiado.php queda fuera de alcance (patrón compartido con ~20 sitios de otros módulos,
candidato a v4.90). Pendiente prueba manual en navegador.*

---

## Estado v4.90 (2026-06-12)

### Formato numérico configurable — módulo Nómina completo

Continuación de v4.87-v4.89: se replicó el patrón `fmt_cantidad()`/`fmt_moneda()`/
`formatDecimal()` en el módulo **nómina**. Nueva categorización confirmada en este módulo:
los campos de **"horas"** (`horas_mes_std`, `horas_total`, `horas_ponderadas`) y el
**"valor/hora"** mostrado con 2 decimales se tratan como "cantidad" (regla f de v4.88:
`number_format($x,2,'.','')` sin separadores es-CO → `fmt_cantidad($x,2)`, preservando 2
decimales pero corrigiendo el separador). Los montos en pesos (salarios, valor/hora ×0
decimales, pagos, auxilio de transporte, SMLMV) → `fmt_moneda()`. Los multiplicadores de
recargo (`mult`/factor ×1.25/×1.75/etc.) quedan fuera de alcance (regla b).

### `nomina/index.php` (commit `bb8a6f0`)

- 19 ocurrencias de `number_format($x,0,',','.')` → `fmt_moneda()`: resumen del período (total
  salarios, costo total), pago base, cargas/provisiones del empleador (×3 cada uno), costo
  total empleador (×2), auxilio de transporte, totales de cargas/provisiones.

### `nomina/empleados.php` (commit `2c3c98b`)

- `fmt_moneda()`: valor/hora y valor/proyecto en tarjetas de empleado, salario base
  (modalidad por horas y tiempo completo), placeholder y nota SMLMV en el formulario de nuevo
  empleado, auxilio de transporte (`db()->query(...aux_transporte...)->fetchColumn()`).
- `fmt_cantidad($horas_mes_std, 2)`: jornada legal "h/mes" en la nota SMLMV y en el hint de
  horas contratadas (PHP).
- JS `calcHorasMes()`: `horasMes.toFixed(2)` / `HORAS_GLOBALES.toFixed(2)` →
  `formatDecimal(horasMes, 2)` / `formatDecimal(HORAS_GLOBALES, 2)` (hints de horas/mes).
- **Fuera de alcance**: `step="0.5"` (horas_semana, regla de negocio de media hora);
  `step="100"`/`step="10000"`/`step="1"` (valor_hora/valor_proyecto/salario_base, precios);
  `toLocaleString('es-CO')` (líneas 442/444/445, migración futura).

### `nomina/horas.php` (commit `b1657dc`)

- `fmt_moneda()`: valor/hora, pago (tabla de empleados), pago estimado (resumen del empleado
  seleccionado).
- `fmt_cantidad(x, 2)`: jornada legal h/mes, horas totales, horas ponderadas (tabla y resumen),
  "valor/hora base" (mostrado con $ pero 2 decimales, preservado vía `fmt_cantidad` no
  `fmt_moneda`).
- **Fuera de alcance**: `number_format($tipoCfg['mult'], 2)` y `mult.toFixed(2)` (multiplicador
  de recargo, regla b); `step="0.5"` (registro de horas por día, media hora); `est.toLocaleString
  ('es-CO')` (línea 454, migración futura).

### `nomina/parametros.php` — sin cambios

Revisado: no contiene llamadas a `number_format`/`toFixed`. Los únicos inputs numéricos son
`step="0.001"`/`step="1"` para valores de parámetro (porcentajes/factores de configuración,
regla b/e) — fuera de alcance en su totalidad.

### Archivos modificados

| Archivo | Cambio |
|---|---|
| `nomina/index.php` | `fmt_moneda()` — 19 ocurrencias (resumen, pago base, cargas/provisiones, costo empleador) |
| `nomina/empleados.php` | `fmt_moneda()` (valor/hora, valor/proyecto, salarios, SMLMV, aux. transporte) + `fmt_cantidad($horas_mes_std,2)` + `formatDecimal` JS |
| `nomina/horas.php` | `fmt_moneda()` (valor/hora, pagos) + `fmt_cantidad(x,2)` (horas, valor/hora base) |
| `nomina/parametros.php` | sin cambios (fuera de alcance) |
| `app/config/app.php` | APP_VERSION → 4.90 |

### Verificación

`php -l` sin errores en los 3 archivos editados (3 bloques, 3 commits separados, push tras
cada uno). **Pendiente prueba manual en navegador** (acumulada con v4.83-v4.89, sin acceso a
navegador/BD desde este entorno): con config por defecto (2/`.`/`,`) confirmar que las páginas
de nómina se ven igual que antes; con config alternativa confirmar que horas, valor/hora base y
montos en pesos muestran los nuevos separadores/decimales.

### v4.91+ (futuro)

Candidatos: (a) migrar `toLocaleString('es-CO', {maximumFractionDigits})` (~22 sitios:
ventas/index.php y fiado.php de v4.89, empleados.php líneas 442/444/445 y horas.php línea 454
de v4.90, más los previos de productos/clientes/costos/activos/inventario) a un helper con
`NUM_FORMAT`; (b) continuar el patrón v4.87-v4.90 en el resto de módulos (compras, reportes,
activos, costos, clientes) — uno por versión.

*Última actualización: 2026-06-12 | v4.90 — formato numérico configurable
(`fmt_moneda()`/`fmt_cantidad()`/`formatDecimal()`) aplicado al módulo Nómina (index,
empleados, horas — 3 commits separados; parametros.php sin cambios, fuera de alcance). Nueva
categorización: "horas" y "valor/hora" a 2 decimales tratados como "cantidad"
(`fmt_cantidad($x,2)`). `toLocaleString('es-CO')` de empleados.php/horas.php queda fuera de
alcance (patrón compartido, candidato a v4.91). Pendiente prueba manual en navegador.*

---

## Estado v4.91 (2026-06-12)

### Migración `toLocaleString('es-CO')` → `NUM_FORMAT` (formatMiles/formatDecimal)

Cierra el candidato pendiente desde v4.89/v4.90: se reemplazaron los **25 sitios** restantes
que usaban `Number.prototype.toLocaleString('es-CO', ...)` (separadores fijos es-CO,
independientes de la configuración) por `formatMiles(x)` (montos en pesos, 0 decimales) o
`formatDecimal(x, N)` (cantidades, N decimales preservados), ambos definidos en `nav.php`
(v4.87) y ya disponibles globalmente en toda página que incluye el layout. Confirmado tras el
cambio: `grep -r "toLocaleString('es-CO'" public_html/` → 0 resultados.

Regla aplicada (análoga a la regla f de v4.88 para PHP):
- `Math.round(x).toLocaleString('es-CO')` / `x.toLocaleString('es-CO', {maximumFractionDigits:0})`
  → `formatMiles(x)` (montos en pesos).
- `x.toLocaleString('es-CO', {maximumFractionDigits:N})` /
  `{minimumFractionDigits:N, maximumFractionDigits:N}` → `formatDecimal(x, N)` (cantidades,
  preserva N decimales). Nota: `toLocaleString` con solo `maximumFractionDigits` recorta ceros
  finales (ej. "5" en vez de "5,00"); `formatDecimal(x,N)` siempre muestra N decimales fijos —
  mismo criterio que `fmt_cantidad()` en PHP (consistencia con los valores ya renderizados en
  servidor en la misma página).

### Archivos modificados (9 commits)

| Archivo | Commit | Cambio |
|---|---|---|
| `ventas/index.php` | `d7d7930` | Deuda de cliente (autocomplete + chip) → `formatMiles()` (2 sitios) |
| `ventas/fiado.php` | `4fc5026` | Preview de saldo y toast de abono → `formatMiles()` (2 sitios) |
| `inventario/compras.php` | `ca6479a` | Precio de referencia → `formatMiles()`; hints de cantidad total y precio/u → `formatDecimal()` (8 sitios) |
| `costos/index.php` | `024b2d2` | Hint de equivalente mensual → `formatMiles()` (1 sitio) |
| `productos/produccion.php` | `cfb9a5d` | Preview de descuento/restante de ingredientes (4 decimales) → `formatDecimal(x,4)` (2 sitios) |
| `nomina/horas.php` | `1df5d83` | Pago estimado con recargo → `formatMiles()` (1 sitio) |
| `nomina/empleados.php` | `6abacaa` | Hint valor/hora (manual y automático) → `formatMiles()`/`formatDecimal(x,2)` (3 sitios) |
| `clientes/index.php` | `43a6995` | Saldo en fusión, modal de abono y toast → `formatMiles()` (4 sitios) |
| `activos/index.php` | `74b736c` | Total calculado (precio_unitario × número_unidades) → `formatMiles()` (2 sitios) |
| `app/config/app.php` | (este commit) | APP_VERSION → 4.91 |

### Verificación

`php -l` sin errores en los 9 archivos (9 bloques, 9 commits separados, push tras cada uno).
**Pendiente prueba manual en navegador** (acumulada con v4.83-v4.90, sin acceso a
navegador/BD desde este entorno): con config por defecto (2/`.`/`,`) confirmar que todos los
hints/previews tocados se ven igual que antes; con config alternativa (3 decimales,
separadores en-US) confirmar que los hints de cantidad muestran 3 decimales y los montos en
pesos muestran los nuevos separadores.

### v4.92+ (futuro)

Con v4.87-v4.91 completos, la infraestructura de formato numérico configurable
(`fmt_cantidad()`/`fmt_moneda()`/`NUM_FORMAT`/`formatDecimal()`/`formatMiles()`) cubre todos
los módulos con `number_format`/`toFixed`/`toLocaleString('es-CO')` detectados (productos,
ventas, nómina, inventario, costos, clientes, activos). Sin candidatos pendientes de este
patrón. Próxima sesión: definir nuevo objetivo (revisar módulos restantes — compras/reportes —
por si quedan casos sueltos, o abordar las pruebas manuales acumuladas v4.83-v4.91, o nueva
funcionalidad).

*Última actualización: 2026-06-12 | v4.91 — migración completa de `toLocaleString('es-CO')` a
`formatMiles()`/`formatDecimal()` (NUM_FORMAT) en 9 archivos / 25 sitios, 9 commits separados.
Cierra el patrón v4.87-v4.91 de formato numérico configurable. Pendiente prueba manual en
navegador (acumulada v4.83-v4.91).*

---

## Estado v4.92 (2026-06-12)

### Módulo Reportes + inventario/compras usa formato numérico configurable

Cierra el candidato pendiente de v4.91 ("revisar módulos restantes — compras/reportes — por si
quedan casos sueltos"): se migraron los **8 archivos** restantes con `number_format`
hardcodeado (`,`/`.`) a `fmt_cantidad()`/`fmt_moneda()` (PHP, disponibles globalmente vía
`auth_check.php`), cubriendo `inventario/compras.php`, `inventario/lista_compras.php` y los 6
`reportes/*.php`. Confirmado tras el cambio: `grep -rn "number_format" public_html/reportes/
public_html/inventario/compras.php public_html/inventario/lista_compras.php` → 0 resultados.

Reglas aplicadas (consistentes con v4.87-v4.91):
- Montos en pesos a 0 decimales (`number_format($x,0,',','.')`) → `fmt_moneda($x)`.
- "Cantidades" a N decimales (stock, presentaciones, % de descuento, etc.) →
  `fmt_cantidad($x,N)` preservando N.
- "costo/u", "dep_dia"/depreciación diaria y "valor/hora" a 2 decimales, "horas" a 1 decimal →
  `fmt_cantidad($x,2)`/`fmt_cantidad($x,1)` (regla i de v4.88, también aplicada en
  `reportes/precios.php` y `reportes/operativo.php`).
- Helper local `$fmt` en `reportes/nomina.php` (línea 105) redefinido para delegar en
  `fmt_moneda()` — cubre sus 18 usos con un solo cambio (mismo patrón que `fmt_cop()` en
  v4.89).

### Archivos modificados (8 commits)

| Archivo | Commit | Cambio |
|---|---|---|
| `inventario/compras.php` | `9d7e9b0` | 3 sitios → `fmt_moneda()` |
| `inventario/lista_compras.php` | `ac306f9` | 5 sitios → `fmt_moneda()`/`fmt_cantidad()` |
| `reportes/ventas.php` | `a677dde` | 15 sitios → `fmt_moneda()`/`fmt_cantidad()` |
| `reportes/costos.php` | `cada36e` | 14 sitios → `fmt_moneda()` |
| `reportes/precios.php` | `55be418` | 19 sitios → `fmt_moneda()`/`fmt_cantidad()` |
| `reportes/compras.php` | `b6bc726` | 9 sitios → `fmt_moneda()`/`fmt_cantidad()` |
| `reportes/operativo.php` | `e47a066` | 19 sitios → `fmt_moneda()`/`fmt_cantidad()` |
| `reportes/nomina.php` | `b0758b8` | helper `$fmt` → delega en `fmt_moneda()` (18 usos) |
| `app/config/app.php` | (este commit) | APP_VERSION → 4.92 |

### Verificación

`php -l` sin errores en los 8 archivos (8 bloques, 8 commits separados, push tras cada uno).
**Pendiente prueba manual en navegador** (acumulada con v4.83-v4.91, sin acceso a
navegador/BD desde este entorno): con config por defecto (2/`.`/`,`) confirmar que los 6
reportes y las 2 páginas de inventario se ven igual que antes; con config alternativa (3
decimales, separadores en-US) confirmar que las cantidades muestran 3 decimales y los montos
en pesos muestran los nuevos separadores, sin romper los exports a Excel (hoja "Rentabilidad"
de `reportes/ventas.php` y las hojas de `reportes/operativo.php`/`reportes/nomina.php`).

### v4.93+ (futuro)

Con v4.87-v4.92 completos, la infraestructura de formato numérico configurable
(`fmt_cantidad()`/`fmt_moneda()`/`NUM_FORMAT`/`formatDecimal()`/`formatMiles()`) cubre todos
los módulos detectados con `number_format`/`toFixed`/`toLocaleString('es-CO')` hardcodeado
(productos, ventas, nómina, inventario, costos, clientes, activos, compras, reportes). Sin
candidatos pendientes de este patrón. Próxima sesión: abordar las pruebas manuales acumuladas
v4.83-v4.92, o nueva funcionalidad/módulo.

*Última actualización: 2026-06-12 | v4.92 — módulo Reportes (6 archivos) + `inventario/compras.php`
+ `inventario/lista_compras.php` migrados a formato numérico configurable
(`fmt_cantidad()`/`fmt_moneda()`), 85 sitios / 8 archivos, 8 commits separados. Cierra el patrón
v4.87-v4.92 de formato numérico configurable. Pendiente prueba manual en navegador (acumulada
v4.83-v4.92).*

---

## Estado v4.93 (2026-06-12)

### 1. Bugs corregidos durante pruebas manuales (commit `99942c8`, validado en producción)

1. `inventario/compras.php` — el historial "Últimas Compras" imprimía `cantidad` cruda
   (ej. `46.0000`, `DECIMAL(10,4)` sin formatear) en vez de `fmt_cantidad((float)$lin['cantidad'])`.
   Corregido.
2. `nomina/index.php` — `generarNomina()` no enviaba `accion=generar` en el `FormData`, por lo
   que `api/generar.php` respondía "Acción inválida." y nunca generaba liquidaciones pese a
   tener horas registradas. Corregido.

Issue descartado (no es bug, fuera del patrón de formato configurable): "MARGEN %" en
`productos/index.php` se muestra con punto decimal (ej. "-105.7%") — es un ratio/porcentaje
(`round(...,1)`), igual que `mult` (factor/multiplicador), explícitamente fuera del alcance de
`fmt_cantidad()`/`fmt_moneda()` (§15).

### 2. `tests/suite.php` — de 29 a 31 grupos (G01-G31)

- **G16 Seguridad** (ampliado): nueva auditoría estática de los 33 archivos `*/api/*.php` —
  todos deben verificar autorización (`permiso_requerir()` o el patrón
  `usuario_rol`+`in_array` usado por `admin/api/*`), y los que leen `$_POST` deben validar
  `csrf_verificar()`.
- **G18 Eficiencia** (ampliado): barrido genérico por `information_schema` sobre 13 tablas de
  detalle/transacción (`venta_detalles`, `compra_detalles`, `ajustes_stock`,
  `produccion_lotes`, `nomina_liquidaciones`, `registro_horas`, `insumo_presentaciones`,
  `pagos_fiado`, `combo_insumos`, `recetas`, `producto_variantes`, `costos_indirectos`,
  `logs_historial`) — verifica (WARN) que cada columna `*_id` tenga índice. Guarda de
  regresión para futuras migraciones; el esquema actual ya cumple.
- **G30 Regresión de bugs v4.93** (nuevo): confirma que el código fuente sigue conteniendo el
  fix de `inventario/compras.php` (`fmt_cantidad((float)$lin['cantidad'])`) y de
  `nomina/index.php` (`fd.append('accion', 'generar')`) descritos en §1.
- **G31 Manejo de errores** (nuevo): confirma que los 33 endpoints `api/*.php` envuelven su
  lógica en `try/catch`, y que los `catch (Exception $e)` genéricos registran con
  `error_log()` (WARN si no).
- Docblock y meta HTML actualizados ("29 grupos" → "31 grupos"). `php -l` sin errores.

### 3. CLAUDE.md — nueva §20 "Mapa de módulos"

Nueva sección de referencia (§20, antes de "Estado v4.40") con 4 sub-secciones:
- **20.1** Objetivo y casos de uso principales de los 12 módulos.
- **20.2** Matriz módulo × nivel de permiso → qué desbloquea cada nivel (`solo_ver` …
  `admin_total`), para los 9 módulos de `permisos_modulos`.
- **20.3** Casos especiales fuera de la matriz (Clientes comparte permiso de ventas; Admin/Ayuda
  por rol; tarjetas sensibles del dashboard; y un caso **nuevo documentado**: "Consolidar
  productos" exige `permiso_tiene('admin','admin_total')` — como `'admin'` no es un módulo de
  `permisos_modulos`, esto equivale a restringir el botón a `superadmin`, aunque vive dentro de
  Productos).
- **20.4** Interdependencias entre Models/tablas: qué módulo lee/escribe datos "de otro módulo"
  (p. ej. `CompraModel` actualiza `insumos.costo_actual` → dispara recálculo de
  `productos.costo_calculado`; `ActivoModel`/`CostoIndirectoModel` alimentan KPIs de Costos y
  Productos/análisis; Reportes agrega de todos los demás módulos).

### Cambios de versión

- `app/config/app.php`: `APP_VERSION` → `4.93`.

### Pendiente

Sin tareas de desarrollo pendientes de este bloque. Próxima sesión: reanudar Bloque A2-A4/B1-B5
de pruebas manuales acumuladas v4.83-v4.92 (cambiar Admin → Apariencia a 3 decimales +
separadores en-US, verificar, revertir; luego presentaciones/equivalencias — ver
`C:\Users\alan_\.claude\plans\curried-napping-hollerith.md`).

*Última actualización: 2026-06-12 | v4.93 — `tests/suite.php` ampliado de 29 a 31 grupos
(G16/G18 extendidos + G30/G31 nuevos: auditoría de permisos/CSRF, índices FK, regresión de los
2 bugs de v4.92, manejo de errores); CLAUDE.md §20 nuevo (mapa de módulos: objetivos, casos de
uso, matriz de roles/permisos, interdependencias). `APP_VERSION` → 4.93.*

---

## Estado v4.94 (2026-06-12)

### 1. Auditoría de proyecto completo — formato numérico configurable (9 archivos, ~69 sitios, 7 commits)

v4.87-v4.92 migraron los módulos "grandes" (productos, ventas, nómina, inventario, costos,
clientes, activos, compras, reportes) al patrón `fmt_cantidad()`/`fmt_moneda()`. Al revisar el
proyecto completo con `grep -rn "number_format"` quedaron 9 archivos/~69 sitios sueltos —
mayormente mensajes de error de modelos, módulos secundarios (admin, dashboard) y vistas que se
habían tocado por última vez antes de v4.87. Migrados en 7 commits:

1. `a026bce` fix(clientes): `ClienteModel.php` (3 sitios: deuda pendiente, abono vs saldo, saldo
   transferido) + `clientes/api/fusionar.php` (1 sitio) — mensajes de error/resultado del modelo
   ahora usan `fmt_moneda()`.
2. `49978a1` fix(inventario): `inventario/index.php` (2 sitios) — "PRECIO POR PRESENTACIÓN ($)"
   y `precio_referencia` en el modal Ajustar, antes sin `parseFloat().toFixed(0)`.
3. `7027afd` fix(clientes): `clientes/estado_cuenta.php` (11 sitios: saldo actual, totales
   cargos/abonos, saldo pre-período, movimientos, saldo corriente) + `clientes/index.php`
   (5 sitios: KPIs, badges deuda fiado, recordatorio WhatsApp) → `fmt_moneda()`.
4. `e75f057` fix(activos): `activos/index.php` (11 sitios: inversión total, valor en libros,
   precio unitario, costo inicial, depreciación/mes y /año → `fmt_moneda()`; depreciación
   diaria `dep_dia`/`depDiaActivo` → `fmt_cantidad($x,2)`, siguiendo la convención
   "costo/u"/"dep_dia"/"valor/hora" ya usada en otros módulos).
5. `0403ffe` fix(admin): `admin/backup.php` (4 sitios: registros totales/por tabla →
   `fmt_cantidad($x,0)`, tamaño en KB → `fmt_cantidad($x,2)`).
6. `11d88fd` fix(costos): `costos/index.php` (10 sitios: KPIs total/directos/indirectos,
   compras, depreciación, nómina directa/indirecta, gran total, valor y valor mensual por
   costo) → `fmt_moneda()`.
7. `8473d70` fix(dashboard): `dashboard.php` (22 sitios: ventas hoy, fondo de turno, fiado,
   costos del mes, meta diaria, ventas 7 días, comparativo mensual, top clientes/reactivar/
   aniversario, productos más vendidos, rendimiento cajeros/ticket promedio, horas pico,
   productos rentables, fiados pendientes → `fmt_moneda()`; cantidades de stock en alertas de
   insumos bajos → `fmt_cantidad()`).

### 2. Excepciones documentadas — 5 usos de `number_format` fuera del patrón

Tras la migración, una nueva auditoría con G32 (ver §3) confirma que solo quedan 5 llamadas a
`number_format` en todo el proyecto, todas legítimamente fuera de alcance:

1. `clientes/index.php:364` — `onclick="abrirAbono(..., <?= number_format($saldo,2,'.','') ?>)"`:
   literal numérico de JS dentro de un atributo `onclick`, requiere `.` decimal y separador de
   miles vacío para ser JS válido — no es un formato de presentación.
2. `dashboard.php:1115` — `number_format($margen_pct, 1)`: porcentaje/ratio (llamada de 2
   argumentos, sin separadores), igual que "MARGEN %" en `productos/index.php` (§15, v4.93).
3. `inventario/conteo.php:178` — `placeholder` de `<input type="number">`: el navegador exige
   `.` como decimal en placeholders (documentado desde v4.87).
4. `nomina/horas.php:355` — `×<?= number_format($mult, 2) ?>`: multiplicador/factor (2
   argumentos), fuera de alcance por la "regla b" de v4.90.
5. `productos/consolidar.php:397` — `value` de `<input type="number">` para precio de variante:
   el navegador exige `.` decimal en `value` (documentado en v4.88).

Ninguno de los 5 coincide con el patrón `number_format(valor, N, '<sep>', '<sep>')` que detecta
G32 (todos usan separadores vacíos, son llamadas de 2 argumentos, o ambos).

### 3. `tests/suite.php` — de 31 a 32 grupos (G01-G32)

- **G32 Formato numérico** (nuevo): auditoría estática de proyecto completo — recorre todos los
  `.php` (hasta 4 niveles bajo `BASE_PATH`) buscando `number_format(..., N, '<sep>', '<sep>')`
  con separadores literales `.`/`,` hardcodeados, que ignorarían la configuración de Admin →
  Apariencia (decimales/separadores de migración 040). El test pasa si la lista está vacía —
  verificado: 96 archivos escaneados, 0 coincidencias.
- También confirma que `fmt_moneda()`/`fmt_cantidad()` (de `FormatoHelper.php`) están
  disponibles globalmente vía `auth_check.php`.
- Línea ~451 (diagnóstico de obsequios): `number_format($valor_obsequios,0,',','.')` →
  `fmt_moneda($valor_obsequios)`, para que el propio test no dispare G32.
- Docblock y meta HTML actualizados ("31 grupos" → "32 grupos"). `php -l` sin errores.

### Cambios de versión

- `app/config/app.php`: `APP_VERSION` → `4.94`.

### Pendiente

Sin tareas de desarrollo pendientes de este bloque. El patrón de formato numérico configurable
(v4.87-v4.94) queda completo y con guarda de regresión (G32). Próxima sesión: reanudar Bloque
A2-A4/B1-B5 de pruebas manuales acumuladas v4.83-v4.92 (cambiar Admin → Apariencia a 3 decimales
+ separadores en-US, verificar, revertir; luego presentaciones/equivalencias — ver
`C:\Users\alan_\.claude\plans\curried-napping-hollerith.md`).

*Última actualización: 2026-06-12 | v4.94 — auditoría de proyecto completo: 9 archivos / ~69
sitios migrados a `fmt_moneda()`/`fmt_cantidad()` (ClienteModel, clientes/api/fusionar,
inventario/index, clientes/estado_cuenta, clientes/index, activos/index, admin/backup,
costos/index, dashboard) en 7 commits; 5 excepciones documentadas (literales JS, porcentajes/
ratios, atributos de `<input type="number">`, multiplicadores); `tests/suite.php` G32 nuevo
(32 grupos) audita `number_format` hardcodeado en todo el proyecto. `APP_VERSION` → 4.94.*

---

## Estado v4.95 (2026-06-12)

### 1. Separador de millones independiente — migración 041 + `fmt_agrupar()`

v4.87-v4.94 dejaron el formato numérico configurable (decimales, separador de miles,
separador decimal) vía `fmt_cantidad()`/`fmt_moneda()` (`FormatoHelper.php`, basados en
`number_format()`) y `window.NUM_FORMAT` + `formatDecimal()`/`formatMiles()` en `nav.php`.
Como `number_format()` solo soporta UN separador de miles repetido, no era posible que el
grupo de "millones" (y superiores) usara un separador distinto al del grupo más cercano al
decimal.

- **Migración 041** (`database/migrations/041_config_sep_millones.sql`): nueva clave
  `num_sep_millones` en `configuracion_app`, default `'.'` (= `num_sep_miles` por defecto →
  formato uniforme, sin cambios para instalaciones existentes).
- **`FormatoHelper.php`**: `config_numeros()` agrega `'sep_millones' => '.'` a los defaults y
  lee `num_sep_millones`. Nueva función `fmt_agrupar(float $n, int $dec, array $cfg): string`
  — agrupación propia de la parte entera en bloques de 3 dígitos: el grupo junto al decimal
  usa `sep_miles`, todos los grupos a la izquierda usan `sep_millones`. `number_format()` se
  usa solo para redondeo/relleno decimal (sin separadores). `fmt_cantidad()`/`fmt_moneda()`
  ahora llaman a `fmt_agrupar()` — sin cambios de firma.
- **`nav.php`**: carga `num_sep_millones` (con la misma whitelist `['.', ',', ' ', "'"]` que
  `num_sep_miles`; colisión con `sep_decimal` cae a `sep_miles`). `window.NUM_FORMAT.sepMillones`
  nuevo. `formatDecimal()` reescrito con agrupación manual por bloques de 3 (igual lógica que
  `fmt_agrupar()` en PHP); `formatMiles()` sin cambios (sigue llamando `formatDecimal(n, 0)`).
- Ejemplo con `sep_miles='.'`, `sep_millones="'"`: `1234567` → `1'234.567`; `1234567890` →
  `1'234'567.890`. Con `sep_millones = sep_miles` (default) el resultado es idéntico al actual
  (ej. `1.234.567`) — los ~96 archivos auditados por G32 (v4.94) no cambian.
- Commit: `f8ce48e` `feat: separador de millones independiente en formato numerico (migracion 041)`.

### 2. Admin > Apariencia — nuevo selector "Separador de millones"

- `admin/apariencia.php`: nuevo `<select id="ap-num-sep-millones">` (Punto/Coma/Espacio/
  Apóstrofe) junto al selector "Separador de miles", con ejemplos de 7 cifras y hint explicando
  que solo se nota en números ≥ 1.000.000 y que, si coincide con el separador de miles, el
  formato es uniforme (como hasta ahora). `guardarApariencia()` envía `num_sep_millones` en el
  FormData.
- `admin/api/guardar_apariencia.php`: valida `num_sep_millones` con la misma whitelist
  `$sepMilesOk` que `num_sep_miles`; si coincide con `num_sep_decimal`, cae a `num_sep_miles`
  (que en ese punto ya está resuelto y validado). Se persiste en `configuracion_app` junto a las
  demás claves de formato. Mismo permiso existente (superadmin/admin), sin cambios de permisos.
- Incluido en el commit `f8ce48e` (mismo commit del punto 1).

### 3. `tests/suite.php` — de 32 a 33 grupos (G01-G33)

- **G33 Separador millones** (nuevo): prueba `fmt_agrupar()` directamente con arrays `$cfg`
  construidos a mano (no toca `configuracion_app`, para no afectar la config en vivo):
  1. `config_numeros()` incluye la clave `'sep_millones'` con valor no vacío.
  2. Formato uniforme (`sep_miles = sep_millones = '.'`, `sep_decimal = ','`):
     `fmt_agrupar(1234567, 0, $cfg) === '1.234.567'`.
  3. Separadores distintos (`sep_miles='.'`, `sep_millones="'"`):
     `fmt_agrupar(1234567.89, 2, $cfg) === "1'234.567,89"`.
  4. 4 grupos / miles de millones: `fmt_agrupar(1234567890, 0, $cfg) === "1'234'567.890"`.
  5. Negativos: `fmt_agrupar(-1234567, 0, $cfg) === "-1'234.567"`.
  6. Sin agrupación (< 1000): `fmt_agrupar(45, 2, $cfg) === '45,00'`.
- G32 (auditoría de `number_format` hardcodeado) sigue intacto y en PASS — ningún sitio de los
  96 archivos auditados cambia, solo el motor interno de `fmt_cantidad()`/`fmt_moneda()`.
- Docblock y meta HTML actualizados ("32 grupos" → "33 grupos"). `php -l` sin errores.
- Commit: `e6c3afb` `test: agregar G33 - separador de millones independiente (v4.95)`.

### 4. Fix aparte — placeholder de "Cantidad a ajustar" respeta decimales configurados

Bug detectado al revisar `inventario/index.php`: `actualizarLabelCantidadAjuste()` usaba
placeholders hardcodeados (`'0.00'` / `'Ej: 12.50'`) en vez de `NUM_FORMAT.decimales`
(migración 040, v4.87+), por lo que el placeholder no reflejaba la cantidad de decimales
configurada en Admin → Apariencia. Corregido a `(0).toFixed(NUM_FORMAT.decimales)` y
`'Ej: ' + (12.5).toFixed(NUM_FORMAT.decimales)`. Sin relación con el resto de v4.95 — commit
separado por convención (commits frecuentes y acotados por cambio).

- Commit: `45a4e5d` `fix: placeholder de Cantidad a ajustar respeta decimales configurados`.

### 5. Fix — campo "Costo por unidad" (modal Ajustar) respeta separadores configurables

Al revisar la config con separadores no-default, el usuario notó que el campo "Costo por
unidad" del modal Ajustar (`inventario/index.php`) seguía mostrando el valor en crudo (ej.
`1000.00`) mientras que el preview formateado justo encima mostraba `$1.000,00`. Causa: era un
`<input type="number">`, que por especificación del navegador **siempre** usa `.` decimal y
nunca muestra separador de miles (la misma limitación documentada para los otros number inputs
en v4.88/v4.94). Solución — convertirlo a `<input type="text" inputmode="decimal">` con formato
configurable:

- **`app/views/nav.php`**: nueva función global `parseNum(str)` (inverso de `formatDecimal()`):
  texto formateado → `Number`, quitando separadores de miles/millones y normalizando el decimal
  a `.`. Para texto ya en crudo se usa `parseFloat` directo (parseNum quitaría un `.` usado como
  separador de miles).
- **`inventario/index.php`**: el campo se muestra **formateado** salvo mientras tiene el foco,
  cuando pasa a **crudo** (`.` decimal, sin agrupación) para edición inequívoca y sin saltos de
  cursor. Helpers `costoValorNum()` (lee el número correcto según el campo esté enfocado o no,
  vía `document.activeElement`), `costoFocusRaw()` (foco → crudo) y `costoBlurFormat()` (blur →
  formateado). `calcCostoAj()` lee/escribe el costo según estado (al editar costo NO reformatea
  el campo; al calcularlo desde cantidad/precio sí); carga inicial usa `formatDecimal()` y el
  envío del form manda el número crudo a PHP vía `costoValorNum()`. Respeta `readOnly` cuando
  hay presentaciones catalogadas (mig 039).
- Los otros dos campos del modal ("Cantidad por presentación", "Precio por presentación") siguen
  como `<input type="number">` por decisión de alcance — no tienen preview formateado encima que
  evidencie el contraste; pendiente solo si se quiere consistencia visual del modal.
- Verificado por el usuario en producción. Commit: `d98118d` `fix: campo Costo por unidad
  respeta separadores configurables (v4.95)`.

### 6. Fix — "Costo por unidad" conserva decimales al guardar (no redondea a entero)

Tras el fix anterior, el usuario notó que al guardar un costo con decimales (ej. `1000.50`) se
guardaba redondeado al entero (`1001`; `1000.20` → `1000`). Causa — el modelo de datos:
`insumos.costo_actual` (DECIMAL 12,4) **siempre se deriva** de `precio_presentacion ÷
cantidad_presentacion`, por trigger DB (`trg_insumos_costo_from_presentacion_update`) **y** por
PHP (`inventario/api/insumo_crud.php:128-130`). El JS de `calcCostoAj()` calculaba el precio
derivado con `precio.toFixed(0)` (redondeo a peso entero); al recalcular el trigger
`costo = precio / cant`, los decimales del costo se perdían (costo `1000.50`, cant `1` → precio
`1001` → costo `1001`).

- `precio_presentacion` es `DECIMAL(12,2)` (admite centavos); el `toFixed(0)` era un redondeo
  de más. Corregido: el precio derivado y su carga inicial usan `toFixed(2)`, conservando los
  centavos y por tanto los decimales del costo. Input precio: `step="1"` → `step="any"`.
- Consecuencia esperada: si el costo tiene decimales y la cantidad es 1, "Precio por
  presentación" mostrará esos decimales (ej. `1000.5`) — matemáticamente correcto
  (`costo = precio ÷ cantidad`). Para cantidades > 1 (caso normal, ej. precio 1005 / cant 1000 →
  costo 1.005) no había regresión.
- Decisión de alcance (confirmada con el usuario): "Cantidad por presentación" y "Precio por
  presentación" se dejan como `<input type="number">` crudo, consistente con la convención
  documentada de toda la app (inputs de precio/cantidad no se formatean con separadores). Solo
  "Costo por unidad" usa el campo de texto formateado (§5), por su preview contiguo.
- Commit: `a55c7ed` `fix: Costo por unidad conserva decimales al guardar (no redondea a entero)`.

### Cambios de versión

- `app/config/app.php`: `APP_VERSION` → `4.95`.

### Pendiente

Sin tareas de desarrollo pendientes de v4.95. Próxima sesión, verificación manual (no
automatizable por `tests/suite.php`):

1. Con la config recién migrada (`sep_millones='.'`, igual a `sep_miles`), confirmar en el
   navegador que todo se ve exactamente igual que antes (Inventario/Dashboard/Reportes).
2. En Admin → Apariencia, cambiar "Separador de millones" a Apóstrofe (miles=Punto,
   decimal=Coma), guardar, y confirmar que un valor ≥ 1.000.000 (ej. "Inversión total" en
   Activos) se ve `1'234.567`.
3. Probar colisión: "Separador de millones" = Coma con "Separador decimal" = Coma → guardar →
   confirmar que el backend cae a `sep_millones = sep_miles` sin error.
4. Volver "Separador de millones" a Punto (uniforme) antes de cerrar la sesión.

Además, sigue pendiente (pausado desde v4.83) reanudar el Bloque A2-A4/B1-B5 de pruebas
manuales acumuladas v4.83-v4.92 (cambiar Admin → Apariencia a 3 decimales + separadores en-US,
verificar, revertir; luego presentaciones/equivalencias) — ver
`C:\Users\alan_\.claude\plans\curried-napping-hollerith.md`.

*Última actualización: 2026-06-12 | v4.95 — separador de "millones" independiente del
separador de "miles" (migración 041): `fmt_agrupar()` nuevo en `FormatoHelper.php` reemplaza
la agrupación de miles de `number_format()` con agrupación propia de 2 separadores;
`formatDecimal()` (nav.php) con la misma lógica en JS; nuevo selector "Separador de millones"
en Admin → Apariencia; `tests/suite.php` G33 nuevo (33 grupos) prueba `fmt_agrupar()`; fix
aparte de placeholder en inventario (45a4e5d); fix del campo "Costo por unidad" del modal
Ajustar (`<input type=number>` → `type=text` formateado con `parseNum()` nuevo en nav.php,
d98118d) y fix de redondeo del costo al guardar (precio derivado `toFixed(0)` → `toFixed(2)`,
conserva decimales; a55c7ed). Los 2 fixes del campo "Costo por unidad" verificados en
producción (2026-06-13); auditoría proactiva confirmó que compras (`calcLinea`/`calcDesdePres`,
precisión a 4 decimales) y `precio_referencia` (peso entero consistente) no tienen el bug.
`APP_VERSION` → 4.95.*

---

## Estado v4.96 (2026-06-19)

### 1. Fix — Conteo Rápido fallaba con "Token de seguridad inválido" (CSRF en endpoint JSON)

`inventario/api/conteo_guardar.php` es el único endpoint que envía el cuerpo como **JSON**
(`fetch` con `Content-Type: application/json`), por lo que `$_POST` queda vacío y
`csrf_verificar()` —que solo leía `$_POST['csrf_token']`— nunca encontraba el token. Bug
latente desde v4.44.

- `app/helpers/AuthHelper.php`: `csrf_verificar()` ahora acepta el token también por header
  `X-CSRF-Token` (patrón estándar para APIs JSON, retrocompatible con los callers `FormData`;
  más seguro: un header custom dispara preflight CORS). Guard `is_string()` para evitar
  `TypeError` de `hash_equals` con arrays.
- `inventario/conteo.php`: el `fetch` envía el header `X-CSRF-Token`.
- Commit: `311a34f`.

### 2. Fix — editar venta: "Unknown column 'r.es_base'" (SQLSTATE 42S22)

Al guardar la edición de una venta (desde Ventas o Clientes), la query de **re-descuento de
stock** (`ventas/api/editar_venta.php`, paso 4) usaba `FROM recetas` (sin alias) pero inyectaba
`$colEsBaseEv = ', r.es_base'` (pensado para la query de restauración que sí hace `JOIN recetas
r`). Con la migración 036 aplicada (producción la tiene), el SELECT referenciaba `r.es_base`
sobre una tabla sin alias `r` → crash. Como `es_base` no se usa al re-descontar (la re-edición
no soporta variante → factor 1.0), se quitó la inyección de esa query. Commit: `927f53b`.

### 3. Método de cobro de fiados — migración 042

Al cobrar una venta a fiado faltaba registrar **con qué** método se cobró (`metodo_pago` se
queda en `'fiado'` para preservar el origen). Decisión de diseño (confirmada con el usuario,
Opción A frente a usar abonos o integrar ambos): columna a nivel venta.

- **Migración 042** (`ventas.metodo_cobro` ENUM efectivo/nequi/daviplata/bancolombia, NULL si
  no aplica o aún sin cobrar) + `schema.sql`.
- `ventas/api/editar_venta.php`: detecta la columna (retrocompatible vía INFORMATION_SCHEMA),
  lee/valida `metodo_cobro` (solo fiado **con** `fecha_pago`) y lo guarda condicionalmente en
  el UPDATE.
- `ventas/historial.php`: nuevo selector **"Método de cobro"** junto a "Fecha de cobro" (visible
  solo cuando Método de pago = Fiado, vía `onMetodoChange()`); carga, envío y limpieza; el
  listado muestra "Cobrado dd/mm · Método". `metodo_pago` permanece en `fiado`; no toca
  `saldo_fiado`/abonos (mecanismo a nivel cliente, complementario).
- Flujo correcto: para marcar un fiado como pagado **NO** se cambia el método a Nequi (eso
  oculta los campos de cobro); se deja en Fiado y se llenan Fecha + Método de cobro.
- Commit: `672b645`.

### 4. Reporte de Ventas — filtro por forma de pago + discriminación de cobros de fiado

`reportes/ventas.php`:
- Filtro **"Forma de pago"** (todos/efectivo/nequi/daviplata/bancolombia/fiado/obsequio) que
  aplica al detalle, KPIs de cabecera y export Excel.
- Nueva tabla **"Ingresos por Forma de Pago"** (sobre todo el período, sin verse afectada por
  el filtro) que **discrimina** por método: *ventas directas* (`metodo_pago`, por fecha de
  venta) vs *cobro de fiados* (`metodo_cobro`, por fecha de cobro) → permite saber exactamente
  el origen del dinero recibido. Independiente de los abonos (hoja "Abonos a Fiado").
- El detalle y la hoja Excel "Ventas" muestran el "Método Cobro" de cada fiado cobrado.
  Retrocompatible: sin mig 042, la columna queda vacía y el desglose lo indica.
- Commit: `0c20ada`.

### 5. Ayuda

`ayuda/index.php`: nueva subsección "Cobro de un fiado — método de cobro (v4.96)" en Ventas
(flujo correcto + diferencia con abonos) y documentación del filtro/discriminación en Reportes.
Commit: `a028a1b`.

### 6. Fix — la fecha de cobro del fiado no se guardaba (datetime-local sin hora)

El campo "Fecha de cobro" era `<input type="datetime-local">`, que devuelve `.value` **vacío**
si no se completa la hora (lo habitual: solo poner la fecha). Al llegar vacía, el backend no
marcaba pagado ni guardaba fecha, y como `metodo_cobro` depende de `fecha_pago`, tampoco se
guardaba el método. Corregido: el campo pasa a `<input type="date">` (un cobro solo necesita
fecha; carga/submit a `YYYY-MM-DD`) y `editar_venta.php` acepta fecha sola (usa el mediodía) o
fecha+hora. Commit: `d8afc19`.

### 7. Sincronización de estructura de BD (auditoría) — schema.sql + CLAUDE.md

Auditoría a pedido del usuario: varios cambios de BD no estaban reflejados en el instalador ni
en la doc. El seed de `configuracion_app` en `schema.sql` no incluía las claves de formato
numérico → agregadas `num_decimales`/`num_sep_miles`/`num_sep_decimal` (mig 040) y
`num_sep_millones` (mig 041). En CLAUDE.md: tabla de claves +`num_sep_millones`; tabla `ventas`
(§4) +`metodo_cobro`; lista de migraciones +041 +042. El resto de migraciones se verificó ya
presente en `schema.sql`. Commit: `076486c`.

### Cambios de versión

- `app/config/app.php`: `APP_VERSION` → `4.96`.
- Migración nueva: **042** (`ventas.metodo_cobro`) — **aplicada en producción (2026-06-19)**.

### Pendiente

- Todo v4.96 **verificado en producción** (2026-06-19): Conteo Rápido, editar venta, método de
  cobro de fiados (tras correr la mig 042 y el fix de fecha) y el reporte de Ventas (filtro +
  discriminación).
- Bloque A2-A4/B1-B5 de pruebas manuales acumuladas v4.83-v4.92: **realizado parcialmente,
  sin novedades hasta ahora** (2026-06-19). Falta completar el resto.

*Última actualización: 2026-06-19 | v4.96 — método de cobro de fiados (migración 042
`ventas.metodo_cobro`): selector en el modal de editar venta (`historial.php`) + guardado en
`editar_venta.php`; reporte de Ventas con filtro por forma de pago y tabla que discrimina
ventas directas vs cobro de fiados (`reportes/ventas.php`); ayuda actualizada. Fixes: Conteo
Rápido CSRF en endpoint JSON (`csrf_verificar()` acepta header `X-CSRF-Token`, 311a34f),
editar venta `Unknown column 'r.es_base'` (927f53b) y fecha de cobro que no guardaba
(`datetime-local` → `date`, d8afc19). Sincronizados `schema.sql` (seed de claves de formato) y
CLAUDE.md con las migraciones 040/041/042 (076486c). Todo verificado en producción
(2026-06-19). `APP_VERSION` → 4.96.*

---

## Estado v4.97 (2026-06-20)

UI: unificación de iconos de acción y layout móvil. Sin cambios de BD ni de lógica.

### 1. Iconos de acción con color consistente — clases reutilizables `.ic-*` (nav.php §16)

Los botones-icono de acción eran inconsistentes entre módulos (Clientes monocromo; otros con
colores/clases propias). Se centralizó la paleta en `app/views/nav.php` como modificadores
reutilizables sobre la clase `.ic` (icono SVG `currentColor` + recuadro tintado del mismo tono,
borde = fondo, hover un paso más oscuro — estilo de Compras):

| Clase | Color | Acción |
|-------|-------|--------|
| `.ic-edit`  | azul     | Editar |
| `.ic-ok`    | verde    | Duplicar/Copiar, Activar, Abonar, Marcar pagado |
| `.ic-del`   | rojo     | Eliminar, Anular |
| `.ic-warn`  | ámbar    | Desactivar/Pausar/Dar de baja (reversible) |
| `.ic-view`  | gris     | Ver/Detalle/Expandir, Desechar |
| `.ic-info`  | celeste  | Estado de cuenta/Extracto, Foto |
| `.ic-merge` | violeta  | Fusionar |
| `.ic-wa`    | verde WA | WhatsApp |
| `.ic-gift`  | rosa     | Regalar/Obsequio |

Uso: `<button class="btn-x ic ic-edit">…</button>`. Cualquier botón nuevo de acción debe usar
estas clases para mantener la línea. Aplicado en: Clientes, Inventario, Productos, Ventas,
Proveedores, Activos, Nómina (empleados), Admin (usuarios), Costos. Compras ya usaba la misma
paleta (fue la referencia).

### 2. Tablas → tarjetas en móvil vertical — clases reutilizables `.rcards` (nav.php §17)

En `<480px` (teléfono vertical) las tablas anchas hacían scroll horizontal. Se centralizó un
patrón reutilizable en `nav.php`:

- `class="rcards-wrap"` en el contenedor (`.card`/`.tbl-card`/`.table-wrap`) → quita el
  `overflow-x:auto`.
- `class="rcards"` en la `<table>` → en `<480px` cada `<tr>` se vuelve una **tarjeta** (sin
  scroll horizontal); `≥480px` no cambia nada.
- `data-label="Etiqueta"` en cada `<td>` → la etiqueta aparece a la izquierda y el valor a la
  derecha en la tarjeta. La 1ª celda es el título (ancho completo, sin etiqueta).
- `class="acc-cell"` en la celda de acciones → fila propia con los botones envueltos abajo.
- `class="rcard-title"` marca la celda título cuando no es la 1ª; `class="rcard-hide"` oculta
  una columna en modo tarjeta (ej. la foto en Activos).
- Filas expandibles (`recipe-row`/`det-row`/`exp-row`, que usan `.open`): se muestran como
  bloque continuo bajo la tarjeta; respetan `display:none`/`.open` (override en el `<480px` de
  cada página).

Aplicado en: Clientes, Inventario, Productos, Ventas (historial), Activos, Nómina (empleados,
liquidaciones, horas), Admin (usuarios, listas), Costos, y la tabla "Detalle de Ventas" del
reporte de Ventas. **Decisión:** el resto de tablas de **Reportes** se dejan con scroll
horizontal en móvil (son numéricas, para comparación lado a lado y exportación a Excel en
escritorio). Conteo y Proveedores ya eran grillas de tarjetas. La barra de resumen del POS
(`ventas/index.php`) ahora envuelve en móvil en vez de scrollear.

### Cambios de versión

- `app/config/app.php`: `APP_VERSION` → `4.97`. Sin migraciones (cambio solo de UI/CSS).

### Pendiente

- Verificación visual del usuario (escritorio sin cambios; teléfono vertical = tarjetas sin
  scroll). Especial atención a tablas anchas (Activos, Costos, Nómina) y filas expandibles
  (Productos/Ventas/Nómina).
- Opcional si se desea: convertir a tarjetas las demás tablas de Reportes (operativo, nómina,
  costos, compras, precios) — hoy con scroll por diseño.

*Última actualización: 2026-06-20 | v4.97 — UI: iconos de acción con color consistente
(`.ic-*` reutilizables en nav.php) y tablas a tarjetas en móvil vertical (`.rcards`/
`.rcards-wrap`/`.rcard-title`/`.rcard-hide`/`.acc-cell`) sin scroll horizontal, en todos los
módulos con tablas de acción (Clientes, Inventario, Productos, Ventas, Proveedores, Activos,
Nómina, Admin, Costos) + Detalle del reporte de Ventas + barra de resumen del POS. Sin cambios
de BD. `APP_VERSION` → 4.97.*

---

## Estado v4.98 (2026-06-20)

Recetas de Productos: edición de cantidades inline + copiar/combinar recetas. Sin cambios de BD
(reusa `recetas`).

### 1. Editar cantidades inline

En la fila expandida de un producto (Módulo Productos → "Ingredientes de la receta"), la
cantidad de cada ingrediente es ahora un `<input>` editable. Al salir del campo o pulsar Enter
se guarda vía `api/guardar_receta.php` (upsert, conserva crítico/base) y se recalcula el costo.
Antes había que borrar y volver a agregar el ingrediente.

### 2. Copiar / combinar recetas con porcentaje — `api/copiar_receta.php` (nuevo)

Panel "📋 Copiar / combinar receta" en la receta: se eligen **uno o varios productos de origen**,
cada uno con su **porcentaje**, y se traen sus ingredientes escalados. Casos: "Criollo L" =
"Criollo XL" al 60%; "Mixto" = "Pollo desmechado" 50% + "Criollo" 50%.

- **Unifica insumos repetidos sumando** las cantidades (entre orígenes y, en modo sumar, con la
  receta actual).
- Dos modos (el usuario eligió "preguntar cada vez" → dos botones): **Reemplazar** (vacía la
  receta y la construye) o **Sumar** (a la actual).
- Resuelve banderas según las reglas de la receta: **base anula crítico** (no coexisten) y
  **máximo un crítico** por producto (se conserva el primero).
- Backend `productos/api/copiar_receta.php`: transacción — merge por `insumo_id` (detección
  mig 036 para `es_base`), `DELETE` + `INSERT` del mapa resultante, recalcula
  `productos.costo_calculado`. Permiso `productos:editar_existentes`, CSRF.
- Frontend (`productos/index.php`): nuevo global JS `PRODUCTOS_RECETA` (productos activos para
  el selector de origen); funciones `guardarCantIng`, `origenRowHtml`/`addOrigen`/`aplicarCopia`.
- **Re-render en sitio** (`refrescarReceta`): tras editar/copiar la receta se re-renderiza sin
  colapsar (antes `reloadReceta` cerraba la fila). Aplicado también a addIng/delIng/toggleBase.

### Cambios de versión

- `app/config/app.php`: `APP_VERSION` → `4.98`. Sin migraciones.

### Pendiente

- Verificación del usuario: editar una cantidad inline (guarda y recalcula sin cerrar);
  construir una receta desde otro producto a un % (Reemplazar) y combinar dos orígenes al 50%
  con duplicados (Sumar/Reemplazar) confirmando que los insumos repetidos se sumen.
- Caveat conocido: el % escala TODOS los ingredientes del origen (incluidos los "base"); si un
  base no debía escalar, se ajusta con la edición inline. Tampoco ajusta por diferencia de
  `unidades_por_receta` (rinde) entre origen y destino (la mayoría usa rinde=1).

*Última actualización: 2026-06-20 | v4.98 — recetas: edición de cantidades inline (campo
editable que guarda al salir) + copiar/combinar receta desde otro(s) producto(s) escalando por
% y unificando insumos repetidos sumando (`api/copiar_receta.php`, modos reemplazar/sumar,
resuelve crítico/base); re-render en sitio (`refrescarReceta`). Sin cambios de BD.
`APP_VERSION` → 4.98.*

---

## Estado v4.99 (2026-06-20)

Productos: tab **"Constructor de recetas"**. Sin cambios de BD.

`productos/index.php` ahora tiene 2 pestañas (`#tab-catalogo` / `#tab-constructor`,
toggle JS `mostrarTabProd`): **Catálogo** (la tabla de siempre) y **Constructor de recetas**.

### Constructor de recetas
- Eliges **producto** + **"cuántos salen"** (rinde = `unidades_por_receta`) + **lista de
  ingredientes** (insumo + cantidad + crítico/base). Al seleccionar un producto se carga su
  receta actual (editable). **Guardar** → reemplaza la receta y fija el rinde.
- **Traer ingredientes de otros productos** (PULL, del lado del cliente): agrega productos de
  origen, cada uno con su **%**; "⬇ Traer y combinar" trae sus ingredientes (escalados) y los
  **suma a la lista en pantalla** (cr-ings), unificando insumos repetidos. Nada se guarda hasta
  "Guardar receta del producto". (`crTraer` lee `ingredientes.php` de cada origen y mergea en JS;
  reemplazó la versión "push a destinos" inicial que no actualizaba la lista visible.)
- Backend nuevo `productos/api/guardar_receta_completa.php`: transacción — fija
  `unidades_por_receta`, `DELETE`+`INSERT` de la receta (unifica insumos repetidos sumando,
  resuelve banderas: base anula crítico, máx 1 crítico), recalcula `costo_calculado`.
- Frontend: `PRODUCTOS_RECETA` ahora incluye `rinde`; funciones `mostrarTabProd`,
  `crInitConstructor`, `crCargarProducto`, `crAddIng`, `crGuardar`, `crAddOrigen`, `crTraer`.

**Endpoints de receta (productos/api/):** `guardar_receta.php` (1 ingrediente, upsert/borrar),
`copiar_receta.php` (combinar fuentes con %, v4.98), `guardar_receta_completa.php` (receta
completa + rinde, v4.99), `ingredientes.php` (GET receta de un producto).

### Dirección corregida: "Traer ingredientes de otros productos" (PULL)

La versión inicial del Constructor distribuía la receta a destinos (push) y no actualizaba la
lista visible → confusión ("no suma / se pierde al guardar"). Corregido a **PULL**: la sección
**"Traer ingredientes de otros productos"** trae los ingredientes de uno o varios productos
origen (cada uno a su **%**) y los combina en la **lista en pantalla** (cr-ings) del lado del
cliente, con dos botones que reusan los nombres del modal de la receta expandida:
**"Reemplazar receta"** (arma la lista solo desde los orígenes) y **"Sumar a la actual"** (los
suma a lo que ya hay). Nada se guarda hasta "Guardar receta del producto". (`crTraer(modo)` lee
`ingredientes.php` de cada origen y mergea en JS.)

### tests/suite.php — de 33 a 34 grupos (G01-G34)

- **G34 Cobro fiado y recetas** (nuevo): valida `ventas.metodo_cobro` (mig 042: existe, ENUM
  válido, solo en ventas fiado) y que los endpoints de receta existan
  (`guardar_receta`/`copiar_receta`/`guardar_receta_completa`/`ingredientes`). Los endpoints
  nuevos ya quedan cubiertos por las auditorías G16 (autorización + CSRF) y G31 (try/catch +
  error_log), que auto-escanean `*/api/*.php`.

### Suite ejecutada completa por primera vez — hallazgos y correcciones

Al correr la suite en producción salió un **500**: bug pre-existente en G03 — los chequeos de
coherencia `ventas.total`/`compras.total` agrupaban por `id` pero usaban `v.total`/`c.total` en
`HAVING` sin estar en SELECT/GROUP BY (MySQL del hosting lo rechaza). Corregido (total en
SELECT+GROUP BY, alias agregado). La suite ahora corre los 34 grupos.

Hallazgos (archivos huérfanos en producción, fuera de git):
- `inventario/api/proveedor_crud.php` (alta rápida de proveedor desde inventario, **en uso** por
  `inventario/index.php`) estaba **sin `permiso_requerir` ni try/catch** → **recreado en git**
  seguro (`permiso_requerir('inventario','editar_existentes')` + `csrf_verificar` + try/catch).
- `recetario/` (index.php + api/ingredientes.php) es **legacy muerto** (cero referencias) →
  **borrar de producción** (cierra G31/G32).
- **Migración 043** (índices `insumos.activo` + `compra_detalles.presentacion_id`) para cerrar
  los avisos G18 en BDs anteriores.
- Avisos para el usuario (no son bugs de código): **G19** superadmin con contraseña de ejemplo
  (cambiar ya); **G03** algunas ventas/compras con total ≠ suma de detalles (datos);
  **G15/G22/G11** warnings de datos/config.

### Cambios de versión

- `app/config/app.php`: `APP_VERSION` → `4.99`.
- Migración nueva: **043** (índices de rendimiento, idempotente).

*Última actualización: 2026-06-20 | v4.99 — Productos: tab "Constructor de recetas" (producto +
rinde + lista de ingredientes → `api/guardar_receta_completa.php`) con "Traer ingredientes de
otros productos" (PULL, combina en pantalla; botones Reemplazar receta / Sumar a la actual).
Pestañas Catálogo/Constructor en `productos/index.php`. `tests/suite.php` G34 nuevo (34 grupos:
mig 042 metodo_cobro + endpoints de receta). Sin cambios de BD. `APP_VERSION` → 4.99.*

---

## Estado v5.0 (2026-06-22)

Admin: **Mantenimiento de datos** (limpieza masiva) + **filtro admin de inactivos/anulados** por
módulo. Sin cambios de BD.

### 1. Admin → Mantenimiento de datos (solo superadmin)
- Página `admin/mantenimiento.php` + motor `admin/api/mantenimiento.php` (sub-tab y tarjeta solo
  para superadmin). Seguridad: solo superadmin (`in_array` para pasar G16), CSRF, exige escribir
  **`BORRAR`**, transacción + `log_registrar`.
- **Reset transaccional global** (`accion=reset_transaccional`): `DELETE` de venta_detalles,
  ventas, pagos_fiado, turnos_caja, compra_detalles, compras, produccion_lotes, ajustes_stock,
  nomina_liquidaciones, registro_horas, logs_historial; `saldo_fiado=0`; opcional reset de stock
  (productos/insumos). Conserva el catálogo.
- **Borrar por módulo** (`accion=borrar`, ambito=inactivos|anulados|todos, modo=seguro|cascada):
  mapa de entidades declarativo `$ENTIDADES` con la columna de baja (`activo`/`estado`) y los
  **hijos bloqueantes (RESTRICT)**. *Seguro* = `NOT EXISTS` contra los hijos (omite y reporta los
  que tienen historial); *cascada* = borra los hijos RESTRICT primero. Las FK CASCADE/SET NULL
  las maneja la BD. **`usuarios` se excluye** (su `created_by` está en casi todas las tablas).
  Mapa de bloqueo: productos←venta_detalles/produccion_lotes/ajustes_stock; insumos←compra_detalles;
  clientes←pagos_fiado (ventas SET NULL); empleados←nomina_liquidaciones; proveedores/activos/costos
  sin bloqueo; ventas(anulada)/produccion(anulado) limpios por CASCADE.
- `accion=stats` alimenta la tabla (total/inactivos/anulados por entidad).

### 2. Filtro "ver inactivos/anulados" por módulo (solo admin)
- Helper `app/helpers/FiltroEstadoHelper.php` (global vía `auth_check.php`):
  `filtro_estado_actual()` (lee `?ver=`, forzado a 'activos' si no es admin),
  `filtro_estado_sql($ver,$col,$tipo,$alias,$baja)` (fragmento WHERE),
  `filtro_estado_ui($actual,$tipo)` (selector, solo se pinta para admin),
  `filtro_estado_es_admin()`.
- Aplicado (default = solo activos; admin elige inactivos/todos): **proveedores**, **productos**
  (catálogo), **inventario** (`InsumoModel::todos_con_estado($ver)`), **empleados**
  (`NominaModel::todos_empleados($ver)`), **activos** (`ActivoModel::todos($orden,$ver)`),
  **clientes** y **costos** (su selector de estado se unificó al patrón admin-only en servidor —
  render-skip por `$ver`, KPIs intactos; se conservó "Con deuda" en clientes y categoría/
  clasificación/texto en costos como filtros cliente para todos).
  Ya filtraban por estado de forma nativa: **ventas/historial** y **producción** (lotes anulados).

### 3. tests/suite.php — G35 (35 grupos)
- **G35 Mantenimiento y filtro estado**: página/endpoint de mantenimiento presentes; helper de
  filtro disponible; `filtro_estado_sql()` genera los fragmentos esperados (activo/estado).

### Cambios de versión
- `app/config/app.php`: `APP_VERSION` → `5.0`. Sin migraciones.

### Pendiente / verificación del usuario
- **Probar el motor de limpieza con un respaldo a mano** (acción destructiva): borrar anulados de
  Ventas (seguro), borrar inactivos de Productos (seguro omite con historial / cascada borra), y
  un reset transaccional en datos de prueba (catálogo intacto, `saldo_fiado=0`).
- Confirmar el selector de estado en cada módulo: visible solo para admin; no-admin ve solo activos.

*Última actualización: 2026-06-22 | v5.0 — Admin → Mantenimiento de datos (reset transaccional +
borrar inactivos/anulados/todos por módulo, modo seguro/cascada, solo superadmin, confirmación
"BORRAR", auditoría) + filtro admin de inactivos/anulados por módulo (`FiltroEstadoHelper` en
proveedores/productos/inventario/empleados/activos). `tests/suite.php` G35 (35 grupos). Sin
cambios de BD. `APP_VERSION` → 5.0.*
