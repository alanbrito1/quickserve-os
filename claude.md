# ClanDestino ERP v4.25 — Memoria de Sesión
# Última sesión: 2026-06-06 | Próxima sesión: continuar desde este punto

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
| `admin` | `resumen`, `usuarios`, `apariencia`, `listas`, `backup` |

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
Claves completas (post-migración 016c):

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
`id`, `fecha_venta`, `cliente_id`, `metodo_pago`, `total`, `estado`, `fecha_pago` (NULL=fiado sin cobrar), `es_combo` (derivado: 1 si algún ítem es combo), `tipo_sandwich`

### Tabla `venta_detalles`
`id`, `venta_id`, `producto_id`, `cantidad`, `precio_unitario`, `subtotal`, `from_stock` (1=del stock terminado, 0=de insumos), `es_combo` (0=solo, 1=vendido como combo), `combo_id` FK→combo_configs (NULL en ventas históricas)

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

---

## 17. ESTADO DE MÓDULOS (2026-05-30)

| Módulo | Estado | Notas |
|---|---|---|
| Auth / Login | ✅ | Logo dinámico desde configuracion_app |
| Clientes | ✅ | CRUD completo (nombre+apellido+empresa+teléfono); toggle activo; fusión; botón 👁 filtra historial; botón 📋 **estado de cuenta** (`clientes/estado_cuenta.php?id=X`) — extracto con cargos + abonos + saldo corriente + impresión |
| Estado de cuenta | ✅ | `clientes/estado_cuenta.php` — historial cronológico de compras fiado + pagos con saldo corriente acumulado, filtro por período, modo impresión (window.print()), accesible desde Clientes y desde Fiado |
| POS — Selector cliente | ✅ | Reemplazado `<select>` estático por **autocomplete buscable**: filtra por nombre/apellido/empresa en tiempo real, chip verde al seleccionar, navegación teclado (↑↓ Enter Esc), touch-friendly (44px items), crea cliente on-the-fly y lo selecciona automáticamente |

### Autocomplete de clientes en POS (ventas/index.php)
- `CLIENTES_DATA`: array JSON serializado en PHP con todos los clientes (id, nombre, apellido, empresa, saldo)
- Funciones: `acFiltrar()`, `acAbrir()`, `acCerrar()`, `acSeleccionar(idx)`, `acLimpiar()`, `acTecla(event)`, `acReset()`
- `acNorm(s)`: normaliza texto eliminando tildes para búsqueda robusta
- `acSeleccionarPorId(id, nombre, saldo)`: selecciona cliente recién creado (on-the-fly)
- `acReset()`: se llama después de confirmar una venta para limpiar la selección
- Estado visual: input+dropdown (buscando) → chip verde (seleccionado)
- Cierra al hacer clic fuera del componente (event listener en document)
| Dashboard | ✅ | Resumen del día + **panel de alertas**: insumos bajos, fiados pendientes, productos bajo mínimo |
| Ventas / POS | ✅ | Fiado crea estado=pendiente_pago; historial con filtros; marcar pagado (transacción atómica); anular; selector solo/combo; fecha_venta; obsequio como método de pago |
| Inventario | ✅ | costo_actual trigger; modal editar/ajustar guarda presentación/costo **sin requerir cantidad de ajuste** (cantidad=0 omite llamada a ajustar_stock.php) |
| Compras | ✅ | Filtros por fecha/lugar/ítem/categoría; editar/duplicar/eliminar compras; **panel informativo de presentación** al seleccionar insumo: muestra tipo de empaque, unidad básica, cant/empaque, equivalencia física y hint dinámico "= X unidades total" |
| Proveedores | ✅ | CRUD, toggle |
| Productos | ✅ | Editar, Duplicar, calculadora bidireccional de receta, capacidad editable, configuración combo inline; botones Regalar 🎁 y Desechar 🗑 en stock; campo nombre2 (subtítulo visual) |
| Producción | ✅ | Registro diario (fix móvil), preview insumos, anular lote, desechar stock terminado 🗑 |
| Nómina | ✅ | 4 contratos; pago correcto; aux proporcional; eliminar período funcional |
| Activos | ✅ | Sortable, dep solo con fecha_inicio_uso, 30.41666 como divisor |
| Costos | ✅ | Selector período, 8 KPIs consolidados |
| Reportes | ✅ | 6 reportes: Ventas, Operativo, Nómina, Costos, Compras, **Variación de Precios** (nuevo); Operativo con Obsequios/Desechos |
| Admin | ✅ | Usuarios, apariencia (2 logos + tipografía + theme_radius), backup BD + código ZIP, migraciones + updates de código, **Catálogos** (admin/listas.php — gestiona 6 listas configurables) |
| Ayuda | ✅ | Documentación actualizada v4.13: obsequio, desechar, reportes obsequios/desechos |
| Compras (panel pres.) | ✅ | Panel informativo de solo lectura al seleccionar insumo: badge tipo empaque + unidad básica + cant/empaque + badge verde equivalencia física + hint dinámico total físico. Snapshot de presentación se guarda en `compra_detalles` (mig. 032 + 034). `calcPres` eliminado — lógica simplificada. |
| Historial ventas | ✅ | Acepta `?cliente_id=X` para filtrar por cliente; banner verde con nombre del cliente y saldo pendiente; preserva filtro al cambiar fechas |
| Reporte Precios | ✅ | **6 tabs**: Insumos (con columnas de empaque), Productos, Nómina (con tarifa/hora snap), Costos Fijos, **Activos** (historial logs_historial), **Fiado/Abonos** (saldo antes/después) |
| Tests | ✅ | Suite de pruebas en `/tests/suite.php` (solo superadmin) — **22 grupos, ~130 pruebas** (G01-G22: esquema, migraciones 026-034, precios, stock, fiado, obsequios, combos, clientes, produccion, activos, nomina, costos, FK, catalogos, configuracion, seguridad, auditoria, eficiencia, usuario UX, inmutabilidad profunda, ENUMs→VARCHAR, **G22: coherencia snapshots 032-034**) |

---

## 18. INTEGRACIONES CLAVE

```
Proveedores → insumos → recetas → productos (costo_calculado)
                                  productos → produccion_lotes → stock_disponible → ventas (from_stock=1)
                         insumos ← produccion_lotes (descuenta al producir)
                         insumos ← ventas on-demand (from_stock=0)

activos.depreciacion → ActivoModel::costo_diario_total() → Productos (costo_deprec_u)
                     → resumen_ampliado().valor_en_libros (usa fecha_adquisicion)

costos_indirectos → CostoIndirectoModel::total_mensual_activo() → Productos (costo_fijo_u)
                  → costos/index.php KPIs

empleados.tipo_costo → Costos KPIs (nómina directa/indirecta)
                     → NominaModel::liquidaciones_periodo() (columna en Excel)

configuracion_app → nav.php (inyecta CSS global a todas las páginas)
                  → login.php (logos, color brand)
                  → admin/apariencia.php (edición del tema)
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

**Próxima sesión puede continuar desde:**
- Roadmap v4.3: ingrediente base + variantes de producto

*Última actualización: 2026-06-06 | v4.25 — auditoría XSS exhaustiva completada; whitelist tipo/frecuencia/tipo_contrato.*
