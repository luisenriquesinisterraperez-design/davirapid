---
version: alpha
name: Davi Rapid Admin
description: Sistema de administración para Davi Rapid — comidas rápidas. Panel operativo para gestión de pedidos, menú, inventario y reportes.

colors:
  # Marca
  primary: "#E63027"
  primary-hover: "#C8281F"
  primary-pressed: "#A52119"
  primary-soft: "#FDECEA"
  on-primary: "#FFFFFF"

  secondary: "#F26B1F"
  secondary-hover: "#D85912"
  secondary-soft: "#FEF0E6"
  on-secondary: "#FFFFFF"

  tertiary: "#FFB627"
  tertiary-hover: "#E69F12"
  tertiary-soft: "#FFF6E0"
  on-tertiary: "#1F1F1F"

  # Neutros (tema claro)
  neutral: "#FAFAFA"
  surface: "#FFFFFF"
  surface-alt: "#F5F5F5"
  surface-sunken: "#EFEFEF"
  on-surface: "#1F1F1F"
  on-surface-muted: "#5C5C5C"
  on-surface-subtle: "#8A8A8A"
  border: "#E5E5E5"
  border-strong: "#D1D1D1"
  divider: "#EEEEEE"
  overlay: "#1F1F1FCC"

  # Estados semánticos
  success: "#22A06B"
  success-soft: "#E6F5EE"
  on-success: "#FFFFFF"
  warning: "#F2A93B"
  warning-soft: "#FEF4E2"
  on-warning: "#1F1F1F"
  error: "#D32F2F"
  error-soft: "#FBE9E9"
  on-error: "#FFFFFF"
  info: "#3B82F6"
  info-soft: "#E8F0FE"
  on-info: "#FFFFFF"

  # Foco
  focus-ring: "#E6302766"

typography:
  display:
    fontFamily: Inter
    fontSize: 2.25rem
    fontWeight: 700
    lineHeight: 1.15
    letterSpacing: -0.02em
  headline-lg:
    fontFamily: Inter
    fontSize: 1.75rem
    fontWeight: 700
    lineHeight: 1.2
    letterSpacing: -0.01em
  headline-md:
    fontFamily: Inter
    fontSize: 1.375rem
    fontWeight: 600
    lineHeight: 1.3
  headline-sm:
    fontFamily: Inter
    fontSize: 1.125rem
    fontWeight: 600
    lineHeight: 1.35
  body-lg:
    fontFamily: Inter
    fontSize: 1rem
    fontWeight: 400
    lineHeight: 1.55
  body-md:
    fontFamily: Inter
    fontSize: 0.9375rem
    fontWeight: 400
    lineHeight: 1.5
  body-sm:
    fontFamily: Inter
    fontSize: 0.8125rem
    fontWeight: 400
    lineHeight: 1.45
  label-lg:
    fontFamily: Inter
    fontSize: 0.9375rem
    fontWeight: 500
    lineHeight: 1.3
  label-md:
    fontFamily: Inter
    fontSize: 0.8125rem
    fontWeight: 500
    lineHeight: 1.3
  label-sm:
    fontFamily: Inter
    fontSize: 0.75rem
    fontWeight: 600
    lineHeight: 1.2
    letterSpacing: 0.04em
  mono-md:
    fontFamily: JetBrains Mono
    fontSize: 0.875rem
    fontWeight: 400
    lineHeight: 1.5

spacing:
  base: 8px
  xs: 4px
  sm: 8px
  md: 16px
  lg: 24px
  xl: 32px
  2xl: 48px
  3xl: 64px
  gutter: 24px
  margin: 32px
  sidebar-width: 248px
  topbar-height: 64px
  content-max: 1440px

rounded:
  none: 0px
  sm: 4px
  md: 8px
  lg: 12px
  xl: 16px
  pill: 9999px
  full: 9999px

components:
  # ─── Botones ─────────────────────────────────────────────
  button-primary:
    backgroundColor: "{colors.primary}"
    textColor: "{colors.on-primary}"
    typography: "{typography.label-lg}"
    rounded: "{rounded.md}"
    padding: 10px 20px
    height: 40px
  button-primary-hover:
    backgroundColor: "{colors.primary-hover}"
  button-primary-pressed:
    backgroundColor: "{colors.primary-pressed}"
  button-primary-disabled:
    backgroundColor: "{colors.surface-sunken}"
    textColor: "{colors.on-surface-subtle}"

  button-secondary:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.primary}"
    typography: "{typography.label-lg}"
    rounded: "{rounded.md}"
    padding: 10px 20px
    height: 40px
  button-secondary-hover:
    backgroundColor: "{colors.primary-soft}"
  button-secondary-pressed:
    backgroundColor: "{colors.primary-soft}"
    textColor: "{colors.primary-pressed}"

  button-tertiary:
    backgroundColor: "transparent"
    textColor: "{colors.on-surface}"
    typography: "{typography.label-lg}"
    rounded: "{rounded.md}"
    padding: 10px 16px
    height: 40px
  button-tertiary-hover:
    backgroundColor: "{colors.surface-alt}"

  button-ghost:
    backgroundColor: "transparent"
    textColor: "{colors.on-surface-muted}"
    typography: "{typography.label-md}"
    rounded: "{rounded.md}"
    padding: 8px 12px
    height: 36px
  button-ghost-hover:
    backgroundColor: "{colors.surface-alt}"
    textColor: "{colors.on-surface}"

  button-danger:
    backgroundColor: "{colors.error}"
    textColor: "{colors.on-error}"
    typography: "{typography.label-lg}"
    rounded: "{rounded.md}"
    padding: 10px 20px
    height: 40px
  button-danger-hover:
    backgroundColor: "#B62525"

  button-icon:
    backgroundColor: "transparent"
    textColor: "{colors.on-surface-muted}"
    rounded: "{rounded.md}"
    size: 36px
  button-icon-hover:
    backgroundColor: "{colors.surface-alt}"
    textColor: "{colors.on-surface}"

  # ─── Inputs y formularios ────────────────────────────────
  input-text:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.on-surface}"
    typography: "{typography.body-md}"
    rounded: "{rounded.md}"
    padding: 10px 12px
    height: 40px
  input-text-hover:
    backgroundColor: "{colors.surface}"
  input-text-focus:
    backgroundColor: "{colors.surface}"
  input-text-disabled:
    backgroundColor: "{colors.surface-sunken}"
    textColor: "{colors.on-surface-subtle}"
  input-text-error:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.on-surface}"

  input-label:
    textColor: "{colors.on-surface}"
    typography: "{typography.label-md}"

  input-helper:
    textColor: "{colors.on-surface-muted}"
    typography: "{typography.body-sm}"

  input-error-message:
    textColor: "{colors.error}"
    typography: "{typography.body-sm}"

  textarea:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.on-surface}"
    typography: "{typography.body-md}"
    rounded: "{rounded.md}"
    padding: 12px

  select:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.on-surface}"
    typography: "{typography.body-md}"
    rounded: "{rounded.md}"
    padding: 10px 12px
    height: 40px

  search:
    backgroundColor: "{colors.surface-alt}"
    textColor: "{colors.on-surface}"
    typography: "{typography.body-md}"
    rounded: "{rounded.md}"
    padding: 10px 12px 10px 36px
    height: 40px

  checkbox:
    backgroundColor: "{colors.surface}"
    rounded: "{rounded.sm}"
    size: 18px
  checkbox-checked:
    backgroundColor: "{colors.primary}"
    textColor: "{colors.on-primary}"

  radio:
    backgroundColor: "{colors.surface}"
    rounded: "{rounded.full}"
    size: 18px
  radio-checked:
    backgroundColor: "{colors.primary}"
    textColor: "{colors.on-primary}"

  switch:
    backgroundColor: "{colors.border-strong}"
    rounded: "{rounded.pill}"
    width: 40px
    height: 22px
  switch-on:
    backgroundColor: "{colors.success}"

  # ─── Cards y contenedores ────────────────────────────────
  card:
    backgroundColor: "{colors.surface}"
    rounded: "{rounded.lg}"
    padding: 24px
  card-interactive:
    backgroundColor: "{colors.surface}"
    rounded: "{rounded.lg}"
    padding: 24px
  card-interactive-hover:
    backgroundColor: "{colors.surface}"

  stat-card:
    backgroundColor: "{colors.surface}"
    rounded: "{rounded.lg}"
    padding: 20px

  # ─── Badges y chips ──────────────────────────────────────
  badge-success:
    backgroundColor: "{colors.success-soft}"
    textColor: "{colors.success}"
    typography: "{typography.label-sm}"
    rounded: "{rounded.pill}"
    padding: 4px 10px
  badge-warning:
    backgroundColor: "{colors.warning-soft}"
    textColor: "#A06A12"
    typography: "{typography.label-sm}"
    rounded: "{rounded.pill}"
    padding: 4px 10px
  badge-error:
    backgroundColor: "{colors.error-soft}"
    textColor: "{colors.error}"
    typography: "{typography.label-sm}"
    rounded: "{rounded.pill}"
    padding: 4px 10px
  badge-info:
    backgroundColor: "{colors.info-soft}"
    textColor: "{colors.info}"
    typography: "{typography.label-sm}"
    rounded: "{rounded.pill}"
    padding: 4px 10px
  badge-neutral:
    backgroundColor: "{colors.surface-alt}"
    textColor: "{colors.on-surface-muted}"
    typography: "{typography.label-sm}"
    rounded: "{rounded.pill}"
    padding: 4px 10px

  chip-filter:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.on-surface}"
    typography: "{typography.label-md}"
    rounded: "{rounded.pill}"
    padding: 6px 12px
    height: 32px
  chip-filter-active:
    backgroundColor: "{colors.primary-soft}"
    textColor: "{colors.primary}"

  # ─── Navegación ──────────────────────────────────────────
  topbar:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.on-surface}"
    height: 64px
    padding: 0 24px

  sidebar:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.on-surface}"
    width: 248px
    padding: 16px

  sidebar-item:
    backgroundColor: "transparent"
    textColor: "{colors.on-surface-muted}"
    typography: "{typography.label-lg}"
    rounded: "{rounded.md}"
    padding: 10px 12px
    height: 40px
  sidebar-item-hover:
    backgroundColor: "{colors.surface-alt}"
    textColor: "{colors.on-surface}"
  sidebar-item-active:
    backgroundColor: "{colors.primary-soft}"
    textColor: "{colors.primary}"

  tab:
    backgroundColor: "transparent"
    textColor: "{colors.on-surface-muted}"
    typography: "{typography.label-lg}"
    padding: 10px 16px
    height: 44px
  tab-active:
    textColor: "{colors.primary}"

  breadcrumb:
    textColor: "{colors.on-surface-muted}"
    typography: "{typography.body-sm}"
  breadcrumb-current:
    textColor: "{colors.on-surface}"

  # ─── Tablas ──────────────────────────────────────────────
  table-header:
    backgroundColor: "{colors.surface-alt}"
    textColor: "{colors.on-surface-muted}"
    typography: "{typography.label-sm}"
    padding: 12px 16px
    height: 44px
  table-row:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.on-surface}"
    typography: "{typography.body-md}"
    padding: 14px 16px
    height: 56px
  table-row-hover:
    backgroundColor: "{colors.surface-alt}"
  table-row-selected:
    backgroundColor: "{colors.primary-soft}"

  # ─── Alertas y notificaciones ────────────────────────────
  alert-success:
    backgroundColor: "{colors.success-soft}"
    textColor: "#0E5C3D"
    rounded: "{rounded.md}"
    padding: 12px 16px
  alert-warning:
    backgroundColor: "{colors.warning-soft}"
    textColor: "#7A4F0E"
    rounded: "{rounded.md}"
    padding: 12px 16px
  alert-error:
    backgroundColor: "{colors.error-soft}"
    textColor: "#7A1A1A"
    rounded: "{rounded.md}"
    padding: 12px 16px
  alert-info:
    backgroundColor: "{colors.info-soft}"
    textColor: "#1E3A8A"
    rounded: "{rounded.md}"
    padding: 12px 16px

  toast:
    backgroundColor: "{colors.on-surface}"
    textColor: "{colors.surface}"
    typography: "{typography.body-md}"
    rounded: "{rounded.md}"
    padding: 12px 16px

  # ─── Diálogos y overlays ─────────────────────────────────
  modal:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.on-surface}"
    rounded: "{rounded.lg}"
    padding: 24px
    width: 480px
  modal-overlay:
    backgroundColor: "{colors.overlay}"

  dropdown:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.on-surface}"
    rounded: "{rounded.md}"
    padding: 4px

  dropdown-item:
    backgroundColor: "transparent"
    textColor: "{colors.on-surface}"
    typography: "{typography.body-md}"
    rounded: "{rounded.sm}"
    padding: 8px 12px
    height: 36px
  dropdown-item-hover:
    backgroundColor: "{colors.surface-alt}"
  dropdown-item-active:
    backgroundColor: "{colors.primary-soft}"
    textColor: "{colors.primary}"

  tooltip:
    backgroundColor: "{colors.on-surface}"
    textColor: "{colors.surface}"
    typography: "{typography.label-md}"
    rounded: "{rounded.sm}"
    padding: 6px 10px

  # ─── Estados de pedido (dominio del negocio) ─────────────
  status-pending:
    backgroundColor: "{colors.warning-soft}"
    textColor: "#A06A12"
    typography: "{typography.label-sm}"
    rounded: "{rounded.pill}"
    padding: 4px 10px
  status-preparing:
    backgroundColor: "{colors.secondary-soft}"
    textColor: "{colors.secondary}"
    typography: "{typography.label-sm}"
    rounded: "{rounded.pill}"
    padding: 4px 10px
  status-on-route:
    backgroundColor: "{colors.info-soft}"
    textColor: "{colors.info}"
    typography: "{typography.label-sm}"
    rounded: "{rounded.pill}"
    padding: 4px 10px
  status-delivered:
    backgroundColor: "{colors.success-soft}"
    textColor: "{colors.success}"
    typography: "{typography.label-sm}"
    rounded: "{rounded.pill}"
    padding: 4px 10px
  status-cancelled:
    backgroundColor: "{colors.error-soft}"
    textColor: "{colors.error}"
    typography: "{typography.label-sm}"
    rounded: "{rounded.pill}"
    padding: 4px 10px
---

# Davi Rapid Admin

## Overview

**Energía rápida con cabeza fría.** El logo de Davi Rapid es vibrante, jugoso y enérgico — perfecto para una marca de comidas rápidas. Pero un panel administrativo no es la fachada: es la sala de máquinas. Los meseros, despachadores y administradores miran esta interfaz durante turnos largos, así que la pantalla no puede gritarles.

La estrategia es **rojo de marca como acento dirigido, no como ambiente**. El sistema vive sobre superficies blancas y neutros cálidos. El rojo `#E63027` aparece exclusivamente en acciones primarias, indicadores de marca y elementos críticos. El naranja y el amarillo del logo se reservan para acentos puntuales: badges de estado, métricas destacadas, alertas. El resultado: la marca está presente sin agotar la vista, y los datos respiran.

El tono es **operativo y directo**. Densidad media, jerarquía clara, mucho espacio en blanco entre módulos. Tipografía sans-serif moderna para legibilidad en pantallas todo el día. Esquinas suavemente redondeadas (4–12px) que sugieren la informalidad de la marca sin perder profesionalismo.

## Colors

La paleta nace de tres fuentes calientes del logo (rojo, naranja, amarillo) sostenidas por una base neutra fría que las hace usables en una interfaz de trabajo.

- **Primary (#E63027) — Rojo Davi:** El rojo del logotipo. Es la voz de la marca y el motor de las acciones primarias: "Crear pedido", "Confirmar venta", "Guardar". Se usa con mesura — máximo una acción primaria por pantalla.
- **Secondary (#F26B1F) — Naranja brasa:** El segundo calor del logo. Se reserva para acentos secundarios y estados de "en proceso" (pedidos preparándose). Nunca compite con el primary por atención.
- **Tertiary (#FFB627) — Amarillo dorado:** El brillo de las papas a la francesa. Se usa puntualmente para destacar métricas KPI, calificaciones, o información que merece reconocimiento sin urgencia.
- **Neutral (#FAFAFA) — Crema operativa:** El fondo del lienzo. Cálido, levemente teñido para evitar la frialdad clínica del blanco puro.
- **Surface (#FFFFFF):** Blanco puro reservado para tarjetas, modales y filas de tabla — flota sobre el neutral creando jerarquía sin sombras pesadas.
- **On-surface (#1F1F1F):** Casi negro para texto principal. Aporta contraste alto sin ser tan severo como `#000`.
- **Estados semánticos:** Verde, amarillo, rojo y azul cuidadosamente escogidos para no chocar con el rojo de marca. El verde de éxito (`#22A06B`) es decididamente verde — no se confunde con el rojo. El rojo de error (`#D32F2F`) es ligeramente más oscuro que el primary para diferenciarse.

Cada color principal tiene una variante **soft** (fondo tintado al ~10%) usada para badges, alertas y estados activos en navegación. Esto evita rellenos saturados que dominan la composición.

## Typography

**Inter** como tipografía única del sistema. Es una sans-serif geométrica diseñada explícitamente para interfaces, con excelente legibilidad en pantallas pequeñas y un set completo de pesos. Usar una sola familia reduce la carga cognitiva y refuerza la coherencia.

- **Display & Headlines:** Inter Bold/Semibold. Pesos 600–700, tracking ligeramente negativo en tamaños grandes. Se usan para títulos de página, KPIs grandes y encabezados de módulos.
- **Body:** Inter Regular a 15px (`body-md`) como tamaño base operativo — un poco más compacto que el clásico 16px porque las tablas administrativas necesitan densidad. `body-lg` 16px se reserva para lectura larga (descripciones, notas).
- **Labels:** Inter Medium para etiquetas de formulario, botones y navegación. `label-sm` usa peso 600 con tracking positivo para badges y encabezados de tabla — ahí la legibilidad en mayúsculas pesa más.
- **Mono (JetBrains Mono):** Reservada para identificadores técnicos: número de pedido, SKU, IDs de transacción.

Regla operativa: no más de tres tamaños tipográficos en una misma vista para evitar ruido jerárquico.

## Layout

Layout de **panel administrativo clásico**: sidebar fija a la izquierda (248px), topbar fijo arriba (64px), área de contenido fluida con ancho máximo de 1440px.

El sistema de espaciado sigue una **escala base 8** con medio paso de 4px para microajustes. Todos los componentes encajan en esta grilla — botones de 40px, filas de tabla de 56px, padding de tarjetas de 24px. Esta disciplina hace que las composiciones se vean ordenadas sin esfuerzo.

- **xs (4px):** separación entre icono y texto, ajustes finos.
- **sm (8px):** separación entre elementos relacionados (label e input).
- **md (16px):** separación interna estándar.
- **lg (24px):** padding de tarjetas, gutter principal.
- **xl (32px):** márgenes de página, separación entre secciones.
- **2xl (48px):** separación entre módulos mayores.

La densidad es **media-alta** — el sistema atiende a operadores que necesitan ver muchos pedidos, productos o registros sin desplazarse en exceso, pero sin caer en el ruido visual de una hoja de cálculo.

## Elevation & Depth

La jerarquía se logra con **capas tonales y bordes**, no con sombras pesadas. Un panel de comidas rápidas debe sentirse limpio, no dramático.

- **Nivel 0 — Lienzo:** Fondo `neutral` (#FAFAFA).
- **Nivel 1 — Superficies:** Tarjetas, sidebar, topbar sobre `surface` (#FFFFFF) con borde sutil (`border` #E5E5E5) en lugar de sombra.
- **Nivel 2 — Elementos flotantes:** Dropdowns, popovers y tooltips usan sombra ligera (`0 4px 12px rgba(0,0,0,0.08)`) más borde sutil.
- **Nivel 3 — Modales:** Sombra media (`0 12px 32px rgba(0,0,0,0.12)`) sobre overlay oscuro (`overlay`).

Las sombras son siempre frías y discretas. Nunca usar sombras coloreadas con el rojo de marca — eso pertenece al territorio del marketing, no del operario.

## Shapes

Lenguaje de formas **suavemente redondeado**. Sigue la energía amigable del logo sin caer en lo infantil.

- **sm (4px):** checkboxes, radios, tags muy pequeños.
- **md (8px):** botones, inputs, dropdowns — la base del sistema.
- **lg (12px):** tarjetas, paneles, modales — un paso más suave para superficies grandes.
- **xl (16px):** tarjetas destacadas o de métricas KPI.
- **pill (9999px):** badges, chips, switches y avatares circulares.

Regla: nunca mezclar `none` (esquinas duras) con el resto del sistema en una misma vista. La consistencia del radio comunica calidad.

## Components

El sistema cubre los controles típicos de un panel administrativo más estados específicos del negocio (pedidos en distintas fases). Las variantes de cada componente se definen como entradas separadas con sufijos `-hover`, `-active`, `-pressed`, `-disabled`.

### Botones

Cinco variantes ordenadas por peso visual:

- **`button-primary`** — Rojo Davi sobre blanco. Una sola vez por pantalla, en la acción más importante.
- **`button-secondary`** — Borde rojo, fondo blanco. Para acciones de soporte que aún son destacables.
- **`button-tertiary`** — Texto sin relleno. Acciones menores ("Cancelar", "Volver").
- **`button-ghost`** — Versión más discreta del tertiary, para barras de herramientas densas.
- **`button-danger`** — Rojo error (más oscuro que el primary) para acciones destructivas: eliminar pedido, anular factura.
- **`button-icon`** — Botón cuadrado de 36px solo con icono, para barras de acciones en tablas.

### Formularios

Inputs con altura de 40px para coincidir con la altura de los botones en la misma fila. Estados explícitos para `hover`, `focus`, `disabled` y `error`. El `focus-ring` usa el rojo primary al 40% de opacidad — distintivo de marca pero no agresivo. Cada input se acompaña de `input-label` (arriba) e `input-helper` o `input-error-message` (abajo).

### Cards y stat-card

Las tarjetas estándar (`card`) llevan padding de 24px. Las tarjetas de métricas (`stat-card`) son más compactas (20px) porque se agrupan en grids de 3–4 columnas en el dashboard.

### Badges, chips y estados de pedido

Badges semánticos genéricos (`badge-success`, `badge-warning`, etc.) más una familia específica del dominio: `status-pending`, `status-preparing`, `status-on-route`, `status-delivered`, `status-cancelled`. Cada estado tiene un color reconocible que mapea al ciclo de vida real de un pedido en el restaurante.

### Navegación

- **`sidebar-item`** — Items de la navegación lateral con estado activo en `primary-soft` (fondo rojo tintado al 10%) y texto en `primary`. El operador identifica de inmediato dónde está.
- **`tab`** — Subnavegación dentro de una vista, indicador inferior en rojo primary.
- **`breadcrumb`** — Migas de pan para vistas profundas (Pedidos / Pedido #1234 / Editar).

### Tablas

Las tablas son el centro del trabajo. Header en `surface-alt` con label-sm en mayúsculas. Filas de 56px con hover sutil. Fila seleccionada con tinte rojo suave. Acciones por fila usan `button-icon`.

### Alertas, toasts y diálogos

Cuatro variantes semánticas (`alert-success`, `alert-warning`, `alert-error`, `alert-info`) en versión soft para no gritarle al usuario. Toasts en oscuro sobre claro para destacar sin invadir. Modales centrados con overlay al 80% de opacidad.

### Dropdowns y tooltips

Dropdowns con padding interno de 4px y items de 36px de alto. Tooltip oscuro discreto, aparece tras 500ms de hover, desaparece inmediatamente.

## Do's and Don'ts

- **Do** usar `primary` solo una vez por pantalla, en la acción más importante.
- **Do** mantener contraste WCAG AA mínimo (4.5:1) — todo el texto sobre `surface` y `neutral` ya cumple.
- **Do** usar las variantes `*-soft` para fondos de badges, alertas y estados activos. Nunca rellenos saturados.
- **Do** alinear alturas: botones, inputs y selects de 40px en la misma fila.
- **Do** usar `status-*` para estados de pedido específicos del negocio en lugar de los `badge-*` genéricos.

- **Don't** usar el rojo `primary` como color de fondo de regiones grandes. Cansa la vista en jornadas de 8 horas.
- **Don't** mezclar radios duros (`none`) con el resto del sistema. Mantén `md` o `lg` consistentemente.
- **Don't** apilar más de tres niveles tipográficos en una misma vista.
- **Don't** usar sombras coloreadas o efectos de neón del logo en la UI operativa. Eso pertenece al marketing.
- **Don't** confundir `error` con `primary`. El rojo de marca es para crear, no para destruir — `button-danger` existe para acciones destructivas.
- **Don't** usar más de dos colores semánticos simultáneamente en un mismo componente (un badge, una alerta).
