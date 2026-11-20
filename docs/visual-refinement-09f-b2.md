# PLATAFORMA LEGAL V2 — 09F-B.2 Modo oscuro del Directorio de Profesionales

## 1. Diagnóstico

Al inspeccionar `resources/css/professionals.css` de forma completa (líneas 1–262) junto con las vistas que la consumen (`pages/professionals/index.blade.php`, `components/professionals/card.blade.php`, `components/professionals/profile-dialog.blade.php`, `components/messages/start.blade.php`, `pages/profile/edit.blade.php`, `layouts/operations.blade.php` y `layouts/verification.blade.php`), se observó que:

1. **Variables oscuras presentes pero cobertura incompleta de controles y bordes:** Aunque existía un bloque `@media (prefers-color-scheme: dark)` para `.professionals` (línea 172) y `.profile-editor` (línea 258), varios elementos específicos del directorio carecían de contraste o definición en modo oscuro:
   - `.professionals-location-filters select` heredaba `border: 1px solid var(--professionals-hover)` (`#29292c` en oscuro sobre `#18181a`), resultando prácticamente invisible, y no definía colores en `<option>` para menús desplegables nativos.
   - `.professionals-search-panel`, `.professional-card`, `.professionals-empty`, `.professionals-guide`, `.professional-dialog` y `.professionals-search` compartían superficies oscuras (`#18181a` / `#202022`) sobre `#111112` sin borde delimitador (`--color-border-dark`).
   - `.professionals-filters button` inactivos tenían `background: transparent`, perdiendo delimitación táctil en modo oscuro.
   - `.professionals-error` usaba `color: var(--professionals-text)` en lugar de un tono semántico de error (`#B42318` en claro / `#FDA4AF` en oscuro).
   - `.professionals :is(a,button,input):focus-visible` omitía `select` en el foco por teclado.
   - `.profile-editor :is(input,textarea,select,button,a):focus-visible` usaba `var(--color-accent)` (`#9E1F52`) sin sobreescritura a `var(--color-focus-dark)` en modo oscuro, reduciendo el contraste del anillo de foco.
   - El formulario `<x-messages.start>` dentro de `.professional-card` carecía de separación superior (`margin-top: 8px`) y ancho completo respecto a `.professional-view`.

## 2. Solución implementada (exclusivamente en `resources/css/professionals.css`)

- **Foco accesible:** Se añadió `select` a `.professionals :is(a,button,input,select):focus-visible` y se aplicó `outline-color: var(--color-focus-dark)` dentro de `@media (prefers-color-scheme: dark)` para `.profile-editor`.
- **Contraste de errores:** `.professionals-error` usa `#B42318` en claro y `#FDA4AF` en modo oscuro (alineado con `.ui-field-error` de `app.css`).
- **Superficies, bordes y filtros en modo oscuro:** Dentro de `@media (prefers-color-scheme: dark)` para `.professionals`:
  - Bordes `1px solid var(--color-border-dark)` en `.professionals-search-panel`, `.professional-card`, `.professionals-empty`, `.professionals-guide`, `.professional-dialog` y `.professionals-search`.
  - Fondo `var(--professionals-soft)` y hover `var(--professionals-hover)` para `.professionals-filters button:not([aria-pressed="true"])`.
  - `.professionals-location-filters select` con `border-color: var(--color-focus-dark)`, `background: var(--professionals-soft)`, `color: var(--professionals-text)` y `<option>` con `background: var(--color-surface-dark); color: var(--color-text-dark)`.
- **`.profile-editor` compartido:** Se añadió `.profile-editor .profile-field select option` en modo oscuro (`var(--color-surface-dark)` / `var(--color-text-dark)`).
- **Alineación en tarjetas:** `.professional-card > form { margin-top: 8px; }` y `.professional-card > form .professional-view { width: 100%; }`.

## 3. Tokens del sistema reutilizados

Todos los colores utilizados provienen de `resources/css/app.css` (`@theme static`) o de la paleta de errores compartida en `app.css`:
- `--color-bg-dark` (`#111112`), `--color-surface-dark` (`#18181a`), `--color-accent-bg-dark` (`#202022`), `--color-accent-bg-hover-dark` (`#29292c`), `--color-border-dark` (`#38383C`), `--color-focus-dark` (`#9C969F`), `--color-text-dark` (`#F2F2F2`), `--color-light-text-dark` (`#B6B3BA`), `--color-accent-text-dark`, `--color-selected-dark`.
- Error claro/oscuro: `#B42318` / `#FDA4AF` (consistente con `.ui-field-error` en `app.css`).

## 4. Pantallas y compatibilidad

- **Directamente mejoradas:** Directorio de Profesionales (`pages/professionals/index.blade.php`), tarjetas de profesionales (`components/professionals/card.blade.php`), diálogo de detalle (`components/professionals/profile-dialog.blade.php`).
- **Compatibilidad verificada estáticamente:**
  - `pages/profile/edit.blade.php` y `confirm-professional.blade.php` (`.profile-editor` y `.profile-panel` conservados y mejorados en foco y `<option>` oscuros).
  - `layouts/operations.blade.php` y `layouts/verification.blade.php` (heredan `.profile-editor` y `.profile-panel` sin cambios disruptivos en modo claro ni contratos de formulario).
  - `pages/profile/show.blade.php` y `pages/profile/public.blade.php` (usan clases Tailwind `dark:` independientes; el componente `<x-messages.start>` conserva sus clases y funciona en ambos contextos).

## 5. Validaciones y limitaciones

- **Inspección estática de código:** Realizada sobre `resources/css/professionals.css`, `resources/css/app.css` y todas las vistas Blade que referencian clases de `professionals.css`.
- **Build de frontend (`npm run build`):** Ejecutado con éxito tras la edición (`vite v6.4.4`, 103 módulos transformados, `public/build/assets/professionals-QiCLGRWO.css` compilado en `2.18 s`).
- **QA visual real en navegador / escenarios autenticados:** **No ejecutado** para evitar conectarse o escribir en MySQL de desarrollo y respetar el aislamiento del entorno. La verificación visual final en `320`, `390`, `768`, `1024`, `1440 px` y modo oscuro real queda pendiente para el lote **H (QA visual integral)**.

## 6. Estado de Git

- Rama: `diseno/ajustes-ui` en `D:\projects\plataforma-legal-V2-diseno`.
- Archivos modificados en esta intervención: `resources/css/professionals.css` y `docs/visual-refinement-09f-b2.md`.
- Se preservaron intactos los cambios previos (incluido `09F-B.1` y `docs/visual-audit-09f-preliminary.md`).
- Sin commits, merges, rebases ni push; `D:\projects\plataforma-legal-V2` (`main`) no fue modificado.
