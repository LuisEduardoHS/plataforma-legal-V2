# PLATAFORMA LEGAL V2 — 09F-B.1 Corrección visual de navegación móvil

## 1. Problema original

Durante la auditoría visual preliminar (`docs/visual-audit-09f-preliminary.md`) y el QA previo de `09E.2-E`, se detectó un defecto P1 en la barra de navegación móvil autenticada (`.platform-mobile-nav`): a `390 px` de ancho y con tamaño de texto al `200 %`, algunas etiquetas (especialmente *"Profesionales"*) se solapaban horizontalmente con los elementos adyacentes o quedaban recortadas verticalmente.

## 2. Causa identificada

1. **Altura fija en el contenedor (`.platform-mobile-nav`):** `resources/views/layouts/partials/header.blade.php` asignaba `sm:h-18 h-14` (`56 px` en móvil y `72 px` en tablet), impidiendo que la barra creciera cuando el texto aumenta al `200 %` o refluye a una segunda línea.
2. **Posicionamiento absoluto y ancho rígido en `<nav>`:** El `<nav>` interno usaba `absolute left-1/2 transform -translate-x-1/2` con `space-x-2`, mientras cada `.headerNav-button` en `resources/css/components/userHeader.css` tenía `basis-1/5` y `text-[0.625rem]` (`10 px` base -> `20 px` al `200 %`). Con 5 elementos forzados en una sola fila de `390 px`, cada columna disponía de ~`71 px`, insuficiente para palabras de 13 caracteres a `20 px`.
3. **Sin reglas de reflujo ni límite de desbordamiento:** Faltaba `flex-wrap: wrap` con base mínima proporcional en `rem` (`flex: 1 1 4.25rem`) y `overflow-wrap: anywhere` en las etiquetas.

## 3. Solución aplicada

Se realizó una corrección exclusivamente visual/presentacional conservando todos los enlaces, rutas, atributos `aria-*`, clases base (`.platform-mobile-nav`, `.headerNav-button`, `.mobile`, `.selected`) y la navegación de escritorio (`>= 1024 px`):

- **En `resources/views/layouts/partials/header.blade.php` (bloque `.platform-mobile-nav`):**
  - Se reemplazó `sm:h-18 h-14` por `min-h-14 sm:min-h-18 h-auto` y `px-4` por `px-2`.
  - Se eliminó `absolute left-1/2 transform -translate-x-1/2` y `space-x-2` del `<nav aria-label="Navegación principal móvil">`, sustituyéndolo por `flex flex-wrap items-stretch justify-center w-full`.
  - Se añadió `shrink-0` al icono y se envolvió `{{ $item['label'] }}` en `<span class="headerNav-label">`.
- **En `resources/css/components/userHeader.css` (dentro de `@media (max-width: 1023px)`):**
  - `.platform-mobile-nav`: `padding-bottom: max(0.25rem, env(safe-area-inset-bottom, 0px))`, `max-height: 45dvh` y `overflow-y: auto`.
  - `.platform-mobile-nav nav`: `gap: 2px`.
  - `.platform-mobile-nav .headerNav-button.mobile`: `flex: 1 1 4.25rem; width: auto; min-width: 0; min-height: 44px; padding: 4px 4px; line-height: 1.2;`.
  - `.platform-mobile-nav .headerNav-label`: `display: block; max-width: 100%; overflow-wrap: anywhere; text-align: center;`.
  - Se preservaron intactas las reglas previas de color en modo claro y oscuro (`.selected`, `:hover`, `prefers-color-scheme: dark`) y la navegación de escritorio (`@media (min-width: 1024px)`).

## 4. Archivos modificados

- `resources/views/layouts/partials/header.blade.php`
- `resources/css/components/userHeader.css`
- `docs/visual-refinement-09f-b1.md` (documentación nueva)

## 5. QA y validaciones realizadas

- **Build de frontend (`npm run build`):** Ejecutado correctamente tras las ediciones (`vite v6.4.4`, 103 módulos transformados, `userHeader-DRMIkaWi.css` y `app-CgWVEOAA.css` compilados sin errores en `2.67 s`).
- **Inspección estática de CSS y Blade:** Verificado que los selectores de escritorio (`@media (min-width: 1024px)`), los estados `:focus-visible` de `app.css` y los colores de modo claro/oscuro permanecen intactos. No se modificaron rutas, controladores, políticas, JavaScript ni MySQL.

## 6. Comportamiento responsive esperado (análisis estático por cálculo de layout)

- **390 px (texto 100 %, `1rem = 16 px`):** `4.25rem = 68 px` por base; `5 × 68 px = 340 px <= 374 px` disponibles, por lo que los 5 botones permanecen en **1 sola fila** con altura mínima de `56 px` (`min-h-14`).
- **390 px (texto 200 %, `1rem = 32 px`):** `4.25rem = 136 px` por base; `3 × 136 px = 408 px > 374 px`, por lo que los botones **refluyen automáticamente en filas de 2 elementos** (~`183 px` cada uno), eliminando solapamiento y recorte vertical gracias a `h-auto` y `min-height: 44px`.
- **320 px (texto 100 % y 200 %):** Con `304 px` útiles, `5 × 68 px = 340 px > 304 px`, por lo que refluye limpiamente a **2 filas** incluso a `100 %` cuando el ancho es muy estrecho, evitando compresión excesiva de *"Profesionales"*.
- **768 px (texto 100 % y 200 %):** `5 × 136 px = 680 px <= 752 px`, manteniendo **1 fila** holgada en ambos casos.
- **>= 1024 px (escritorio):** `.platform-mobile-nav` permanece oculta (`lg:hidden`) y la barra superior de escritorio no sufre alteraciones.

## 7. Limitaciones

- No se ejecutó QA visual en navegador real con sesión autenticada ni se iniciaron suites HTTP para no escribir ni depender de la base de datos MySQL de desarrollo. La verificación de reflujo al `200 %` en navegador real queda pendiente para el lote **H (QA visual integral)**.

## 8. Estado de Git

- Rama: `diseno/ajustes-ui` en `D:\projects\plataforma-legal-V2-diseno`.
- Se preservaron los 12 archivos modificados previamente y `docs/visual-audit-09f-preliminary.md`.
- Sin commits, merges, rebases ni push; `D:\projects\plataforma-legal-V2` (`main`) no fue modificado.
