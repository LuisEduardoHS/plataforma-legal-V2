# PLATAFORMA LEGAL V2 — 09F-A Auditoría visual preliminar

> **Alcance y naturaleza de esta auditoría:** Inspección estática exhaustiva de layouts Blade, componentes reutilizables, vistas de página, hojas de estilo CSS/Tailwind y configuración de Vite en `D:\projects\plataforma-legal-V2-diseno` (rama `diseno/ajustes-ui`). No se ejecutó el servidor Laravel contra MySQL ni se realizaron capturas en navegador real para preservar la integridad de la base de datos y el entorno.

---

## 1. Resumen ejecutivo

Plataforma Legal V2 cuenta con una base visual reconocible —paleta institucional (`#7F1842` / `#9E1F52`), identidad diferenciada para LEXA (`#6C4ACB`), tipografía `DM Sans` y soporte parcial de modo claro/oscuro mediante `prefers-color-scheme: dark`— pero presenta **fragmentación estructural entre módulos**. Cada sección evolucionó con su propia hoja de estilos aislada (`landing.css`, `home.css`, `community.css`, `professionals.css`, `messages.css`, `articles.css`, `library-help.css`, `lexa.css`, `views/login.css`), lo que generó:

1. **Defectos críticos (P1) de accesibilidad y modo oscuro:**
   - Solapamiento y recorte de etiquetas en la barra de navegación móvil (`.platform-mobile-nav`) a `390 px` con texto al `200 %` debido a altura fija (`h-14` / `56 px`), posicionamiento `absolute` del `<nav>` y texto `text-[0.625rem]` sin reflujo.
   - Ausencia total de modo oscuro en el directorio de Profesionales (`resources/css/professionals.css` bloque `.professionals`), que permanece en blanco fijo (`color-scheme: light`) incluso cuando el sistema está en modo oscuro.
   - Fondo transparente en el menú desplegable público (`.public-dropdown` en `publicHeader.css`) cuando se navega en páginas públicas distintas de Landing en modo claro.
   - Bajo contraste en botones oscuros (`dark:bg-light-accent` `#BE2562` con `dark:text-black` en `recovery.blade.php` y `settings/index.blade.php`) y en textos de error/peligro en modo oscuro (`#B42318` en `community.css`).
2. **Inconsistencias estructurales y de jerarquía (P2):**
   - Páginas principales sin etiqueta `<h1>` (`home.blade.php`, `community/index.blade.php`, `professionals/index.blade.php`, `settings/index.blade.php`) y `<main>` anidado en `login.blade.php` y `register.blade.php`.
   - Componentes UI base (`x-ui.card`, `x-ui.button`) sin soporte de modo oscuro o con colores hardcoded en lugar de tokens `@theme`, además de escasa adopción en las vistas de módulo.
   - Layouts de Operaciones y Verificación (`layouts/operations.blade.php`, `layouts/verification.blade.php`) que importan `professionals.css` únicamente para reutilizar `.profile-editor` y `.profile-panel`.
3. **Oportunidades de pulido visual y mantenibilidad (P3):**
   - Estandarización de radios (`--radius-ui: 10px` vs `rounded-xl`, `rounded-2xl`, `14px`, `16px`), sombras, espaciados y estados vacíos/error (inexistencia de `resources/views/errors/*` globales).

---

## 2. Estado visual actual

### Identidad y tokens (`resources/css/app.css`)
- **Tokens definidos en `@theme static`:**
  - `--radius-ui: 10px`
  - `--font-sans: "DM Sans", sans-serif`
  - `--color-accent: #9E1F52`, `--color-light-accent: #BE2562`, `--color-dark-accent: #7F1842`
  - `--color-lexa: #6C4ACB`, `--color-lexa-rose: #B32667`
  - Tokens de texto, fondo, bordes y variantes `-dark` (`--color-bg-dark: #111112`, `--color-surface-dark: #18181a`, `--color-border-dark: #38383C`, `--color-accent-text-dark`, `--color-lexa-dark`).
- **Hallazgo arquitectónico:** Aunque `app.css` define tokens globales, **cada módulo redeclara sus propios tokens locales** (`--home-*`, `--community-*`, `--professionals-*`, `--messages-*`, `--resources-*`, `--personal-*`, `--lexa-*`, `--landing-*`) con ligeras variaciones de grises (`#efefef`, `#f5f5f5`, `#f4f4f4`, `#EEEEEE`) y radios (`10px`, `12px`, `14px`, `16px`).
- **Carga de CSS:** `layouts/app.blade.php` carga `app.css` (que a su vez importa `publicHeader.css` en la línea 2 incluso para usuarios autenticados) y condicionalmente `userHeader.css` + `@yield('page-assets')`.

### Estado del worktree `diseno/ajustes-ui`
Al momento de la auditoría existen cambios sin confirmar previos en el worktree (`community.css`, `userHeader.css`, `home.css`, `header.blade.php`, componentes de `community` y `home`, `pages/community/show.blade.php`, `pages/home/home.blade.php`), los cuales fueron respetados sin modificación.

---

## 3. Inventario de pantallas

Se revisaron estáticamente **20 grupos de pantallas / rutas** reales definidas en `routes/web.php` y `resources/views/pages/`:

| # | Pantalla / Módulo | Vista(s) Blade | Layout | Hoja(s) CSS | Estado de inspección |
|---|---|---|---|---|---|
| 1 | **Landing** | `pages/landing/index.blade.php` | `layouts/public` | `app.css`, `landing.css` | Estática (Blade + CSS) |
| 2 | **Inicio autenticado** | `pages/home/home.blade.php` | `layouts/app` | `app.css`, `userHeader.css`, `home.css`, `articles.css` | Estática (Blade + CSS) |
| 3 | **Comunidad (Feed)** | `pages/community/index.blade.php` | `layouts/app` | `app.css`, `userHeader.css`, `community.css` | Estática (Blade + CSS) |
| 4 | **Publicación individual** | `pages/community/show.blade.php` | `layouts/app` | `app.css`, `userHeader.css`, `community.css` | Estática (Blade + CSS) |
| 5 | **Crear/Editar post y comentario** | `pages/community/edit.blade.php`, `comment-edit.blade.php` | `layouts/app` | `app.css`, `userHeader.css`, `community.css` | Estática (Blade + CSS) |
| 6 | **Conexiones de comunidad** | `pages/community/connections.blade.php` | `layouts/app` | `app.css`, `userHeader.css`, `community.css` | Estática (Blade + CSS) |
| 7 | **Profesionales (Directorio)** | `pages/professionals/index.blade.php` | `layouts/app` | `app.css`, `userHeader.css`, `professionals.css` | Estática (Blade + CSS) |
| 8 | **Perfil público** | `pages/profile/public.blade.php` | `layouts/app` | `app.css`, `userHeader.css` | Estática (Blade + CSS) |
| 9 | **Mi Perfil** | `pages/profile/show.blade.php` | `layouts/app` | `app.css`, `userHeader.css` | Estática (Blade + CSS) |
| 10 | **Edición de Perfil (Personal/Prof.)** | `pages/profile/edit.blade.php`, `confirm-professional.blade.php` | `layouts/app` | `app.css`, `userHeader.css`, `professionals.css` | Estática (Blade + CSS) |
| 11 | **Verificación profesional** | `pages/verification/index.blade.php`, `show.blade.php`, `history.blade.php`, `queue.blade.php` | `layouts/verification` (`layouts/app`) | `app.css`, `userHeader.css`, `professionals.css` | Estática (Blade + CSS) |
| 12 | **Mensajes** | `pages/messages/index.blade.php`, `edit.blade.php`, `partials/*` | `layouts/app` | `app.css`, `userHeader.css`, `messages.css` | Estática (Blade + CSS) |
| 13 | **Artículos** | `pages/articles/index.blade.php`, `show.blade.php`, `edit.blade.php`, `workspace.blade.php`, `history.blade.php` | `pages/articles/layout` (`layouts/app`) | `app.css`, `userHeader.css`, `articles.css` | Estática (Blade + CSS) |
| 14 | **Recursos jurídicos** | Mismas vistas `pages/articles/*` con `$kind = resource` | `pages/articles/layout` (`layouts/app`) | `app.css`, `userHeader.css`, `articles.css` | Estática (Blade + CSS) |
| 15 | **Guardados** | `pages/saved/index.blade.php` | `layouts/app` | `app.css`, `userHeader.css`, `library-help.css`, `articles.css` | Estática (Blade + CSS) |
| 16 | **Ayuda** | `pages/help/index.blade.php` | `layouts/app` (o público sin sesión) | `app.css`, `library-help.css` | Estática (Blade + CSS) |
| 17 | **LEXA (Asistente demo)** | `pages/lexa/index.blade.php` | `layouts/app` | `app.css`, `userHeader.css`, `lexa.css` | Estática (Blade + CSS) |
| 18 | **Login y Registro** | `pages/auth/login.blade.php`, `pages/auth/register.blade.php` | `layouts/guest` | `app.css`, `views/login.css` | Estática (Blade + CSS) |
| 19 | **Recuperar/Restablecer contraseña** | `pages/auth/recovery.blade.php` | `layouts/guest` | `app.css`, `views/login.css` | Estática (Blade + CSS) |
| 20 | **Configuración** | `pages/settings/index.blade.php` | `layouts/app` | `app.css`, `userHeader.css` | Estática (Blade + CSS) |
| 21 | **Errores de módulo** | `pages/{articles,community,messages,operations,verification}/error.blade.php` | Varios (`app` / `verification`) | Según módulo | Estática (Blade + CSS) |
| 22 | **Herramientas de operaciones** | `pages/operations/index.blade.php`, `users.blade.php`, `user.blade.php`, `audit.blade.php`, `moderation.blade.php` | `layouts/operations` (`layouts/app`) | `app.css`, `userHeader.css`, `professionals.css` | Estática (Blade + CSS) |

---

## 4. Componentes compartidos

### Layouts
- `resources/views/layouts/app.blade.php`: Usa `bg-gray-50 dark:bg-bg-dark text-gray-900 dark:text-text-dark` en `<body>` (Tailwind por defecto en claro en lugar de `bg-bg text-text`). Incluye `.ui-skip-link` y alterna entre `layouts.partials.header` y `x-public.header`.
- `resources/views/layouts/public.blade.php`: Usa `.public-skip` en lugar de `.ui-skip-link`, carga un subconjunto distinto de `DM Sans` (`wght@400;500;600;700`) y monta `x-public.header` + `x-public.footer`.
- `resources/views/layouts/guest.blade.php`: Usa `bg-white dark:bg-bg-dark` en `<body>` y carga `views/login.css`.
- `resources/views/layouts/operations.blade.php` y `layouts/verification.blade.php`: Extienden `layouts.app` pero cargan `professionals.css` para heredar `.profile-editor` y `.profile-panel`.

### Navegación y cabecera
- `layouts/partials/header.blade.php` + `userHeader.css`: Cabecera fija en escritorio (`lg:h-18` / `72 px`), barra inferior fija en móvil/tablet (`.platform-mobile-nav`, `sm:h-18 h-14`), menú de cuenta (`x-header.account-menu`) con `<details class="account-dropdown">`, y acceso a LEXA.
- `components/public/header.blade.php` + `publicHeader.css`: Cabecera pública con enlace de marca, enlaces de escritorio (visibles desde `1280 px`) y `<details class="public-disclosure">` ("Explorar") por debajo de `1280 px`.
- `components/public/footer.blade.php`: Pie de página público estilizado en `landing.css` (solo disponible en `layouts/public`, ausente en vistas públicas que usan `layouts/app` como Comunidad/Profesionales/Ayuda sin sesión).

### UI Base (`resources/views/components/ui/`)
- `button.blade.php`: Colores hardcoded (`bg-[#7F1842]`, `hover:bg-[#9E1F52]`, `bg-[#6C4ACB]`) y `rounded-lg` en lugar de tokens `@theme` (`rounded-ui`).
- `card.blade.php`: Sin soporte de modo oscuro (`border-gray-200 bg-white` sin `dark:bg-surface-dark dark:border-border-dark`).
- `input.blade.php`, `field-error.blade.php`, `error-summary.blade.php`: Usan `.ui-field`, `.ui-input`, `.ui-field-error`, `.ui-error-summary` definidos en `app.css` con buen soporte claro/oscuro, pero casi ninguna pantalla los usa (Auth usa `.login-input`, Perfil usa `x-profiles.field`, Artículos usa `.content-editor`, Comunidad usa clases propias).

### Componentes de dominio
- `components/content/save.blade.php` y `pagination.blade.php`: Compartidos entre Inicio, Comunidad, Artículos, Recursos y Guardados.
- `components/profiles/card.blade.php` y `verification.blade.php`: Usados en `profile/show`, `profile/public`, `professionals/card`.
- `components/messages/start.blade.php`: Botón de inicio de chat reutilizado en Profesionales y Perfil público.

---

## 5. Hallazgos por pantalla

### 5.1 Barra de navegación autenticada (Transversal)
- **Pantalla:** Todas las vistas autenticadas (`layouts/partials/header.blade.php`, `userHeader.css`).
- **Problema observable:**
  1. `.platform-mobile-nav` tiene altura fija (`h-14` = `56 px` en `<640 px`, `sm:h-18` = `72 px` en tablet) y el `<nav>` interno usa `position: absolute; left: 50%; transform: translateX(-50%)` con `space-x-2` y botones `.headerNav-button` de `text-[0.625rem]` (`10 px`) y `basis-1/5`.
  2. El logotipo en cabecera (`div` con gradiente) es un círculo/cuadrado decorativo vacío (`lg:size-11`, `sm:size-9 size-8`) sin enlace a Inicio ni marca textual/SVG.
- **Impacto:** A `390 px` con texto al `200 %`, etiquetas largas como *"Profesionales"* (13 caracteres) desbordan horizontalmente sobre los botones vecinos y se recortan verticalmente contra los `56 px` fijos. Además, el logo decorativo sin enlace rompe la expectativa estándar de navegación.
- **Solución propuesta:** Sustituir `h-14`/`sm:h-18` por `min-h-14` con `height: auto`, eliminar `position: absolute` del `<nav>`, usar `grid-template-columns: repeat(5, minmax(0, 1fr))` con `gap: 2px`, `min-width: 0`, `overflow-wrap: anywhere` o etiqueta adaptable, y `font-size: clamp(0.6875rem, 2.5vw, 0.75rem)`. Convertir el logotipo en `<a href="{{ route('home') }}">` con isotipo/título accesible.

### 5.2 Cabecera pública en páginas internas sin sesión
- **Pantalla:** Comunidad, Profesionales, Artículos, Recursos y Ayuda cuando el usuario no ha iniciado sesión (`layouts/app.blade.php` + `publicHeader.css`).
- **Problema observable:** En `publicHeader.css` (línea 5), `.public-header` define `--public-surface: transparent; --public-border: transparent;`. Únicamente `.landing-page .public-dropdown` (línea 43) sobrescribe `--public-surface: #fff; --public-border: #e6e3eb;`.
- **Impacto:** En modo claro, al abrir el menú "Explorar" en cualquier pantalla pública que no sea Landing (p. ej. `/comunidad` o `/ayuda`), el panel desplegable `.public-dropdown` tiene fondo transparente y texto superpuesto sobre el contenido de la página.
- **Solución propuesta:** Definir `--public-surface: #fff; --public-border: #e6e3eb;` directamente en `.public-dropdown` (con su correspondiente `@media (prefers-color-scheme: dark)` ya existente en línea 47).

### 5.3 Landing (`pages/landing/index.blade.php`, `landing.css`)
- **Problema observable:**
  1. `.landing-hero h1` tiene altura fija (`height: 2.2em` en escritorio, `3.3em` en `<=767 px`).
  2. `.landing-lexa-composer textarea` tiene `height: 44px; max-height: 44px; line-height: 44px; overflow: hidden;`.
  3. `--landing-radius: 12px` difiere del token global `--radius-ui: 10px`.
- **Impacto:** Con texto al `200 %` o en `320 px`, el `<h1>` con altura fija puede solaparse con `.landing-hero-description`, y el `textarea` del composer recorta texto multilínea o ampliado.
- **Solución propuesta:** Cambiar `height` de `h1` por `min-height`, permitir crecimiento automático en `textarea` (`min-height: 44px; line-height: 1.5; padding-block: 10px;`) y unificar radios con `--radius-ui`.

### 5.4 Inicio autenticado (`pages/home/home.blade.php`, `home.css`)
- **Problema observable:**
  1. No existe ningún `<h1>` en toda la página (el saludo `.home-start-greeting` es `<h2>`).
  2. `home.css` (líneas 204-208) sobrescribe `.platform-header` en modo oscuro con `!important` y `backdrop-filter: blur(12px)`, regla inexistente en el resto de páginas.
  3. Carga `articles.css` en `@section('page-assets')` sin usar su layout ni clases principales.
- **Impacto:** Jerarquía de encabezados rota para lectores de pantalla y apariencia inconsistente de la barra superior entre Inicio y el resto de la app en modo oscuro.
- **Solución propuesta:** Promover el saludo o un título visual/SR a `<h1>`, mover el estilo de `.platform-header` a `userHeader.css` para aplicarlo globalmente si se desea, y retirar `articles.css` de `home.blade.php` si no es necesario.

### 5.5 Comunidad y Publicación individual (`pages/community/*`, `community.css`)
- **Problema observable:**
  1. `community/index.blade.php` carece de `<h1>` (a diferencia de `show.blade.php` que sí tiene `<h1 class="community-sr-only">`).
  2. El formulario de filtros usa clases Tailwind con `!` (`!mt-0 !h-9 !w-full ... border-gray-200/70 bg-white`) mezcladas con reglas CSS en `community.css`.
  3. `.community-comment-action--danger:hover` (línea 322) usa `#B42318` sin variante oscura; sobre fondo `#202022` ofrece bajo contraste.
  4. `.community` usa `min-height: calc(100vh - 72px)` en lugar de `100dvh`.
- **Impacto:** Inconsistencia de controles en filtros respecto a los demás formularios del módulo, contraste insuficiente en acción "Eliminar" en modo oscuro y salto de altura en móviles con barras dinámicas.
- **Solución propuesta:** Añadir `<h1>` (visible o `.community-sr-only`), limpiar utilidades `!important` moviendo el estilo del `select` a `community.css`, añadir `#FDA4AF` en modo oscuro para `--danger`, y usar `100dvh`.

### 5.6 Profesionales (`pages/professionals/index.blade.php`, `professionals.css`, `components/professionals/card.blade.php`)
- **Problema observable:**
  1. **Sin modo oscuro en `.professionals`:** `professionals.css` fija `color-scheme: light` (línea 18) y solo incluye `@media (prefers-color-scheme: dark)` para `.profile-editor` al final del archivo (líneas 258-262).
  2. `professionals/index.blade.php` no tiene `<h1>` (empieza con `<h2>Busca según tu situación</h2>`).
  3. Clases usadas en Blade (`professionals-search-panel`, `professional-practice`, `professional-modality`) no tienen reglas definidas en `professionals.css`.
- **Impacto:** En modo oscuro, la pantalla de Profesionales muestra un lienzo blanco brillante deslumbrante que rompe por completo con el header oscuro (`#18181a`) y el resto de la plataforma. Además, las tarjetas carecen de espaciado específico para `.professional-practice` y `.professional-modality`.
- **Solución propuesta:** Agregar `@media (prefers-color-scheme: dark)` completo para `.professionals` usando los tokens `--color-bg-dark`, `--color-surface-dark`, `--color-text-dark`, `--color-light-text-dark` y `--color-accent-text-dark`; añadir `<h1>` y definir estilos de `.professionals-search-panel`, `.professional-practice` y `.professional-modality`.

### 5.7 Mi Perfil, Perfil Público y Edición de Perfil (`pages/profile/*`)
- **Problema observable:**
  1. `profile/show.blade.php` y `profile/public.blade.php` combinan utilidades Tailwind directas (`bg-bg`, `rounded-2xl`, `px-6 py-2`, enlaces `underline` sin estilo de botón/chip) con `x-profiles.card`, mientras `profile/edit.blade.php` usa `.profile-editor` / `.profile-panel` de `professionals.css`.
  2. En `profile/edit.blade.php`, los `<select>` (`state_id`, `attention_mode`, `municipality_id`, `visibility`) tienen `background: transparent` sin estilizar `<option>` en modo oscuro.
  3. En `profile/show.blade.php` línea 13, el botón `bg-dark-accent text-white` tiene `px-6 py-2` (altura ~`36 px`, menor al mínimo táctil de `44 px`).
- **Impacto:** Enlace/acciones poco jerarquizadas en Mi Perfil (muchos enlaces `underline` planos juntos), riesgo de `<option>` ilegibles en navegadores Windows/Chromium en modo oscuro y área táctil insuficiente en móvil.
- **Solución propuesta:** Estandarizar enlaces secundarios como botones/chips secundarios con `min-h-11`, definir `option { background: var(--color-surface-dark); color: var(--color-text-dark); }` en modo oscuro y desacoplar `.profile-editor` a un CSS propio o compartido.

### 5.8 Verificación profesional y Herramientas de operaciones (`pages/verification/*`, `pages/operations/*`)
- **Problema observable:**
  1. `layouts/verification.blade.php` y `layouts/operations.blade.php` cargan `professionals.css` únicamente para heredar `.profile-editor` y `.profile-panel`.
  2. Las listas, tablas/registros y navegación secundaria dependen de enlaces `underline` simples y bordes `border-gray-200` sin una jerarquía visual clara de estados (pendiente, aprobado, rechazado, revocado).
- **Impacto:** Acoplamiento innecesario con CSS del directorio de profesionales y apariencia rudimentaria en flujos administrativos y de revisión documental.
- **Solución propuesta:** Extraer estilos de paneles y formularios administrativos a un módulo compartido (`forms.css` o `app.css`) y aplicar insignias semánticas consistentes.

### 5.9 Mensajes (`pages/messages/*`, `messages.css`)
- **Problema observable:**
  1. `.messages` calcula su altura con `calc(var(--messages-viewport, 100dvh) - 48px - 56px - env(safe-area-inset-bottom, 0px))` en móvil (`<=639 px`). Si la barra móvil o el header crecen al `200 %` de texto, el contenedor puede desbordar o quedar oculto tras la barra inferior.
  2. `.messages-pagination` (`font-size: 12px`) y `.messages-message-actions summary` carecen de área táctil mínima de `44 px`.
  3. `.messages-bubble footer` tiene `font-size: 11px` (muy pequeño para metadatos de hora/leído).
- **Impacto:** Dificultad de toque en paginación y acciones de mensaje en móvil, y posible recorte del compositor en zoom `200 %`.
- **Solución propuesta:** Usar `min-height` táctil (`44 px`) en enlaces de paginación y acciones, subir `footer` a `12px` (`0.75rem`) y coordinar la altura del viewport con variables CSS dinámicas del header/nav.

### 5.10 Artículos, Recursos jurídicos, Guardados y Ayuda (`pages/articles/*`, `pages/saved/*`, `pages/help/*`)
- **Problema observable:**
  1. `articles/layout.blade.php` no define un `<h1>` visible de sección (en `index.blade.php` y `show.blade.php` el título principal es `<h2>`), aunque `articles.css` define `.resources-intro h1`.
  2. `articles/index.blade.php` tiene `<select>` y `<input>` de filtros sin estilizar `<option>` para modo oscuro y con `content-primary` (`var(--color-accent)` `#9E1F52` con texto blanco, menor contraste que `#7F1842`).
  3. En `help/index.blade.php` y `saved/index.blade.php`, los `<details class="help-faq">` y `.personal-filters` tienen buen soporte oscuro, pero `.personal-page` no tiene `<h1>` en `help/index.blade.php` ni en `saved/index.blade.php` (solo `<h2>`).
- **Impacto:** Ausencia de `<h1>` en 4 pantallas clave (Artículos, Recursos, Guardados, Ayuda) y contraste mejorable en botón primario de filtros de Biblioteca.
- **Solución propuesta:** Incorporar `<h1>` semántico en cada vista (o en `articles/layout.blade.php`), usar `--color-dark-accent` (`#7F1842`) en `.content-primary` para mayor contraste con blanco, y unificar los estilos de filtros.

### 5.11 LEXA (`pages/lexa/index.blade.php`, `lexa.css`)
- **Problema observable:**
  1. Excelente coherencia visual con `--color-lexa` (`#6C4ACB`) y modo claro/oscuro, pero en `<768 px` (`.lexa-composer-actions .lexa-icon`, línea 175) los botones de icono bajan a `32 px` (`min-width: 32px; width: 32px;`) rompiendo la regla de `44 px` en móvil.
  2. Al igual que Mensajes, usa altura fija calculada (`- 48px - 56px`) que asume altura constante de la barra móvil.
- **Impacto:** Botones de acción pequeños en móvil (`32 px`) y vulnerabilidad si la barra móvil crece con texto al `200 %`.
- **Solución propuesta:** Mantener `min-width: 44px; min-height: 44px` en `.lexa-icon` en móvil y usar `flex` / variables dinámicas para la altura del contenedor.

### 5.12 Login, Registro y Recuperación (`pages/auth/*`, `views/login.css`)
- **Problema observable:**
  1. **HTML inválido:** `login.blade.php` y `register.blade.php` abren `<main>` dentro del `<main id="main-content">` de `layouts/guest.blade.php`.
  2. **Títulos y textos:** `<title>` dice `| LEXA` en lugar de `| Plataforma Legal`. En `login.blade.php` el enlace *"¿Olvidaste tu contraseña?"* aparece duplicado (línea 31 arriba del formulario y línea 73 debajo del botón).
  3. **Ancho intermedio:** La tarjeta usa `sm:w-4xl` (`896 px` desde `sm` = `640 px`), lo que provoca **desbordamiento horizontal** entre `640 px` y `920 px`.
  4. **Banner vacío:** El panel izquierdo (`sm:basis-1/2`) es un gradiente vacío sin logotipo ni mensaje de bienvenida.
  5. **Botones sociales deshabilitados:** Se muestran 3 botones ("Google", "LinkedIn", "Facebook") deshabilitados sin indicación visual clara de "Próximamente" más allá del `title`.
  6. **Contraste en `recovery.blade.php`:** Botón submit usa `bg-dark-accent dark:bg-light-accent text-white dark:text-black` — en modo oscuro `#BE2562` con texto negro tiene contraste ~`3.4:1` (no cumple WCAG AA `4.5:1`).
- **Impacto:** Desbordamiento horizontal en tablets (`768 px`), HTML inválido, confusión por enlace duplicado y fallo de contraste en recuperación de contraseña en modo oscuro.
- **Solución propuesta:** Cambiar `<main>` interno a `<div>`, usar `w-full max-w-4xl mx-4`, corregir `<title>`, eliminar el enlace duplicado de recuperación, diseñar el panel lateral con marca/contexto, y usar `dark:bg-dark-accent dark:text-white` o `dark:bg-accent-text-dark dark:text-bg-dark` para cumplir contraste AA.

### 5.13 Configuración (`pages/settings/index.blade.php`)
- **Problema observable:**
  1. Sin `<h1>` en la página.
  2. Botón "Cerrar sesión" (línea 96) usa `dark:bg-light-accent` con `dark:text-black` (bajo contraste en modo oscuro).
  3. Panel lateral e izquierdo tienen `min-h-[520px]` fijo, generando espacio vacío excesivo en móvil cuando el aside se apila arriba.
  4. Mezcla colores hardcoded (`bg-[#EEEEEE]`, `bg-[#FAFAFA]`, `border-gray-100`) en lugar de tokens `@theme`.
- **Impacto:** Espacio vacío desproporcionado en móvil (`320 px` / `390 px`), bajo contraste en botón de cierre de sesión en modo oscuro y falta de `<h1>`.
- **Solución propuesta:** Añadir `<h1>Configuración</h1>`, cambiar `min-h-[520px]` a `lg:min-h-[520px]`, corregir el contraste del botón (`dark:bg-dark-accent dark:text-white`) y migrar a tokens `@theme`.

### 5.14 Pantallas de Error
- **Problema observable:** No existe directorio `resources/views/errors/` para errores HTTP globales (403, 404, 419, 429, 500). Los 5 errores de módulo (`articles/error`, `community/error`, `messages/error`, `operations/error`, `verification/error`) tienen estructuras y estilos dispares.
- **Impacto:** Cualquier 404 o 500 fuera de esos controladores muestra la pantalla genérica de Laravel sin navegación ni identidad de Plataforma Legal.
- **Solución propuesta:** Crear vistas base en `resources/views/errors/` (403, 404, 419, 429, 500) alineadas al sistema visual y unificar el componente de tarjeta de error de módulo.

---

## 6. Problemas globales de consistencia

1. **Fragmentación de tokens y colores:** `app.css` define `--color-bg: #EEEEEE`, pero los módulos usan `#f5f5f5` (Comunidad, Artículos, Mensajes), `#f4f4f4` (Profesionales), `#f5f4f7` (LEXA), `#fff` (Landing) y `bg-gray-50` (`layouts/app.blade.php`).
2. **Radios inconsistentes:** Conviven `--radius-ui: 10px`, `rounded-lg` (`8px`), `rounded-xl` (`12px`), `rounded-2xl` (`16px`), `14px` (`.home-start`, `.community-comment-input-box`) y `999px` (pills).
3. **Componentes UI infrautilizados:** `x-ui.card` (sin modo oscuro) y `x-ui.button` (con colores hexadecimales hardcoded) no se usan en casi ninguna vista; cada módulo recrea sus propios botones (`.community-primary`, `.professionals-primary`, `.content-primary`, `.personal-button-primary`, `.profile-button`).
4. **Acoplamiento cruzado de CSS:** `operations.blade.php` y `verification.blade.php` importan `professionals.css`; `home.blade.php` importa `articles.css`; `app.css` importa `publicHeader.css` incluso para usuarios autenticados.
5. **Inconsistencia en `<h1>`:** Varias pantallas internas principales (`home`, `community/index`, `professionals/index`, `articles/index`, `saved`, `help`, `settings`) omiten `<h1>` y comienzan en `<h2>`.

---

## 7. Problemas responsive

Evaluación estática por punto de quiebre objetivo:

- **`320 px` y `390 px` (Móvil):**
  - **Crítico (`.platform-mobile-nav`):** A `390 px` con texto al `200 %`, los 5 botones (`basis-1/5`, `space-x-2`, `h-14` fija de `56 px`) solapan etiquetas largas (*"Profesionales"*) y recortan contenido verticalmente.
  - **LEXA y Mensajes:** Alturas calculadas con `56 px` fijo para la barra móvil; si la barra crece o hay teclado virtual, se reduce el área útil o se recorta.
  - **Configuración (`settings/index.blade.php`):** `min-h-[520px]` en el `<aside>` empuja el contenido principal muy abajo en pantallas pequeñas.
  - **Landing:** `.landing-hero h1` con `height: 3.3em` y `.landing-lexa-composer textarea` con `height: 44px; overflow: hidden` corren riesgo de recorte con texto ampliado.
- **`768 px` (Tablet):**
  - **Login y Registro:** `sm:w-4xl` (`896 px`) aplicado desde `640 px` (`sm:`) excede los `768 px` del viewport y genera scroll horizontal.
  - **Header público:** El menú completo de escritorio solo aparece en `1280 px` (`xl`), por lo que a `768 px` y `1024 px` usa el botón desplegable "Explorar", cuya caja `.public-dropdown` carece de fondo sólido fuera de Landing en modo claro.
- **`1024 px` y `1440 px` (Escritorio):**
  - En `1024 px`–`1279 px`, `publicHeader.css` oculta los enlaces horizontales públicos (breakpoint en `1280 px`), dejando un header muy vacío con solo marca y "Explorar".
  - En `1440 px`, las grillas máximas oscilan entre `1040 px` (Artículos), `1080 px` (Perfil/Configuración), `1120 px` (Profesionales), `1160 px` (Inicio/Comunidad), `1180 px` (Ayuda/Guardados) y `1200 px` (Landing), generando ligeros saltos de alineación horizontal al navegar entre pestañas.

---

## 8. Problemas de accesibilidad visual

1. **Reflujo y texto al 200 % (WCAG 1.4.4 / 1.4.10):**
   - `.platform-mobile-nav` con `h-14` (`56 px`) fija y `text-[0.625rem]` (`10 px`).
   - `.landing-hero h1` (`height: 2.2em` / `3.3em`) y `.landing-lexa-composer textarea` (`max-height: 44px; overflow: hidden`).
2. **Contraste en modo oscuro y claro (WCAG 1.4.3):**
   - `professionals.css`: `.professionals` sin soporte dark mode; textos oscuros/fondos claros forzados.
   - `publicHeader.css`: `.public-dropdown` con `background: transparent` fuera de Landing en modo claro.
   - `recovery.blade.php` y `settings/index.blade.php`: `dark:bg-light-accent` (`#BE2562`) con `dark:text-black` (~`3.4:1`).
   - `community.css`: `.community-comment-action--danger:hover` (`#B42318`) sin variante clara en modo oscuro.
3. **Tamaños mínimos táctiles (`44×44 px`):**
   - `.lexa-icon` en móvil (`32 px`), botón "Editar perfil" en `profile/show.blade.php` (`py-2` ~`36 px`), enlaces de paginación en `messages.css`, y `.home-post-actions` (`34-36 px` en escritorio).
4. **Jerarquía semántica y landmarks:**
   - `<main>` anidado en `login.blade.php` y `register.blade.php`.
   - Ausencia de `<h1>` en `home`, `community/index`, `professionals/index`, `articles/index`, `saved`, `help` y `settings`.
5. **Estados de foco:**
   - `login-input` usa `focus:outline-none focus:ring-2` con bajo contraste en algunos bordes; `app.css` define `:focus-visible` global dentro de `#app`, pero `layouts/guest.blade.php` no envuelve en `#app`.

---

## 9. Propuestas concretas de mejora

1. **Sistema de diseño unificado en `app.css`:**
   - Consolidar tokens semánticos de superficie (`--surface-page`, `--surface-card`, `--surface-soft`, `--border-subtle`, `--text-primary`, `--text-muted`) para claro y oscuro.
   - Actualizar `x-ui.card` y `x-ui.button` para consumir tokens `@theme` y soportar `dark:`.
   - Crear una capa base de formularios/paneles compartida (`forms.css` o en `app.css`) para eliminar la dependencia de `professionals.css` en Verificación, Operaciones y Edición de Perfil.
2. **Corrección de navegación móvil y pública:**
   - Refactorizar `.platform-mobile-nav` a Grid de 5 columnas con `min-h-14` flexible, sin `position: absolute` en `<nav>`, y texto fluido que no solape a `390 px` con zoom `200 %`.
   - Dar fondo sólido a `.public-dropdown` en todas las páginas públicas y convertir el logo del header autenticado en enlace a Inicio.
3. **Modo oscuro completo en Profesionales y contraste AA:**
   - Implementar `@media (prefers-color-scheme: dark)` en `.professionals` y añadir estilos a `<option>` en `<select>` oscuros.
   - Corregir botones `dark:bg-light-accent dark:text-black` a `dark:bg-dark-accent dark:text-white` (o `dark:bg-accent-text-dark dark:text-bg-dark`).
4. **Limpieza semántica y responsive:**
   - Garantizar un único `<h1>` por pantalla y eliminar `<main>` duplicado en Auth.
   - Cambiar `sm:w-4xl` por `w-full max-w-4xl mx-4` en Login/Registro y `lg:min-h-[520px]` en Configuración.
   - Unificar ancho máximo de contenedor interno (p. ej. `1160 px`) y crear `resources/views/errors/*`.

---

## 10. Prioridades P1, P2 y P3

### Prioridad P1 (Crítico — Bloques visuales, accesibilidad y roturas)
- Corregir solapamiento/recorte de `.platform-mobile-nav` a `390 px` con texto al `200 %` (`header.blade.php`, `userHeader.css`).
- Implementar modo oscuro en `.professionals` (`professionals.css`).
- Corregir fondo transparente de `.public-dropdown` en páginas públicas en modo claro (`publicHeader.css`).
- Corregir contraste de botones en modo oscuro (`recovery.blade.php`, `settings/index.blade.php`, `community.css` acción eliminar).
- Corregir desbordamiento `sm:w-4xl` y `<main>` anidado en `login.blade.php` y `register.blade.php`.
- Eliminar alturas fijas que recortan texto al `200 %` en `landing.css` (`.landing-hero h1`, `.landing-lexa-composer textarea`).

### Prioridad P2 (Importante — Consistencia, jerarquía y experiencia móvil)
- Añadir `<h1>` faltante en `home`, `community/index`, `professionals/index`, `articles/index`, `saved`, `help`, `settings`.
- Actualizar `x-ui.card` y `x-ui.button` a tokens `@theme` con modo oscuro.
- Desacoplar `professionals.css` de `layouts/operations.blade.php`, `layouts/verification.blade.php` y `profile/edit.blade.php`.
- Unificar estilos de `.platform-header` en modo oscuro (retirar override `!important` de `home.css` hacia `userHeader.css`).
- Garantizar áreas táctiles `>= 44 px` en móvil (`.lexa-icon`, paginación de mensajes, botón editar perfil).
- Ajustar `min-h-[520px]` de `settings/index.blade.php` a `lg:min-h-[520px]` y estilizar `<option>` en `<select>` oscuros.

### Prioridad P3 (Pulido — Cohesión estética y casos borde)
- Unificar radios (`--radius-ui: 10px` vs `12px`/`14px`/`16px`) y colores de fondo de página (`#f4f4f4` / `#f5f5f5` / `#efefef` -> token único).
- Enriquecer panel lateral de Login/Registro y aclarar estado "Próximamente" de botones sociales.
- Eliminar enlace duplicado de recuperación en `login.blade.php` y corregir `<title> | Plataforma Legal`.
- Convertir el logotipo circular/cuadrado de `header.blade.php` en enlace con identidad visual hacia Inicio.
- Crear plantillas globales `resources/views/errors/{403,404,419,429,500}.blade.php` y unificar errores de módulo.
- Retirar importaciones CSS redundantes (`articles.css` en `home.blade.php`, `publicHeader.css` cuando el usuario está autenticado).

---

## 11. Archivos que probablemente necesitarán cambios

- **Layouts y globales:** `resources/css/app.css`, `resources/css/components/userHeader.css`, `resources/css/components/publicHeader.css`, `resources/views/layouts/app.blade.php`, `resources/views/layouts/public.blade.php`, `resources/views/layouts/guest.blade.php`, `resources/views/layouts/operations.blade.php`, `resources/views/layouts/verification.blade.php`, `resources/views/layouts/partials/header.blade.php`.
- **Componentes UI:** `resources/views/components/ui/button.blade.php`, `resources/views/components/ui/card.blade.php`.
- **Auth y Landing:** `resources/css/landing.css`, `resources/css/views/login.css`, `resources/views/pages/auth/login.blade.php`, `resources/views/pages/auth/register.blade.php`, `resources/views/pages/auth/recovery.blade.php`.
- **Módulos internos:** `resources/css/home.css`, `resources/views/pages/home/home.blade.php`, `resources/css/community.css`, `resources/views/pages/community/index.blade.php`, `resources/css/professionals.css`, `resources/views/pages/professionals/index.blade.php`, `resources/css/messages.css`, `resources/css/lexa.css`, `resources/css/articles.css`, `resources/views/pages/articles/layout.blade.php`, `resources/css/library-help.css`, `resources/views/pages/help/index.blade.php`, `resources/views/pages/saved/index.blade.php`, `resources/views/pages/profile/show.blade.php`, `resources/views/pages/profile/edit.blade.php`, `resources/views/pages/settings/index.blade.php`.
- **Errores (nuevos en fase posterior):** `resources/views/errors/*.blade.php`.

---

## 12. Plan de implementación visual por lotes

Se propone ejecutar la etapa 09F en **8 lotes secuenciales e independientes** para mantener diffs pequeños, revisables y sin conflictos funcionales:

| Lote | Nombre | Alcance principal | Prioridad |
|---|---|---|---|
| **A** | Sistema visual y componentes compartidos | `app.css` (tokens), `x-ui.*`, `userHeader.css`, `publicHeader.css`, `header.blade.php` (fix barra móvil 390 px / 200 % y dropdown público), capa base de formularios. | P1 / P2 |
| **B** | Landing e Inicio | `landing.css`, `landing/index.blade.php`, `home.css`, `home/home.blade.php` (`h1`, composer fluido, header unificado). | P1 / P2 |
| **C** | Comunidad y publicaciones | `community.css`, `community/index.blade.php`, `show.blade.php`, `edit.blade.php` (`h1`, filtros sin `!important`, contraste peligro en oscuro). | P1 / P2 |
| **D** | Directorio y perfiles | `professionals.css` (modo oscuro completo, clases faltantes), `professionals/index.blade.php`, `profile/show`, `profile/public`, `profile/edit`. | P1 / P2 |
| **E** | Mensajes (y ajustes de LEXA móvil) | `messages.css`, `messages/index.blade.php`, `lexa.css` (áreas táctiles `44 px`, altura dinámica sin recorte). | P2 |
| **F** | Artículos, Recursos y Ayuda | `articles.css`, `library-help.css`, `articles/layout.blade.php`, `saved/index.blade.php`, `help/index.blade.php` (`h1`, contraste botón primario). | P2 |
| **G** | Formularios, configuración, auth, verificación y errores | `auth/login`, `auth/register`, `auth/recovery`, `settings/index`, `verification/*`, `operations/*`, `resources/views/errors/*`. | P1 / P2 / P3 |
| **H** | QA visual integral | Verificación en navegador real a `320`, `390`, `768`, `1024`, `1440 px`, modo claro/oscuro, teclado, contraste y zoom `200 %`. | P1–P3 |

---

## 13. Posibles conflictos futuros con `main`

1. **Cambios funcionales en Blade:** Si `main` modifica directivas `@if`, `@can`, nombres de rutas, atributos `data-*` (usados por JS de `community`, `messages`, `lexa`, `professionals`, `home`) o formularios, los lotes visuales deberán respetar intactos todos los atributos `data-*`, `name`, `id`, `action` y tokens `@csrf`.
2. **Worktree con cambios previos:** Existen 12 archivos modificados previamente en `diseno/ajustes-ui` (`community.css`, `userHeader.css`, `home.css`, `header.blade.php`, `post-card.blade.php`, `home.blade.php`, etc.). Conviene revisar/consolidar esos cambios antes o durante el Lote A para evitar divergencias al hacer merge con `main`.
3. **Compilación de Vite (`public/build/`):** Evitar subir artefactos compilados en commits intermedios si `main` compila en CI o en despliegues separados, para no generar conflictos binarios/hash en `public/build/manifest.json`.
4. **JavaScript de polling y estados:** En Mensajes y LEXA, las clases CSS ligadas a `data-view="list|chat"`, `data-stage="welcome|thread"` y `hidden` controlan la lógica JS; no deben renombrarse ni alterarse en su semántica de visibilidad.

---

## 14. Limitaciones de la auditoría

- **Inspección exclusivamente estática:** No se levantó el servidor PHP/Vite ni se conectó a MySQL; no se inspeccionaron renders reales en Chromium/Firefox/Safari ni flujos autenticados en vivo.
- **Cálculos de contraste y reflujo:** Basados en lectura directa de valores CSS/Tailwind y reglas de especificidad; la verificación exacta de solapamientos a `390 px` + `200 %` texto y contraste de gradientes requiere validación en navegador durante el **Lote H (QA visual integral)**.
- **Datos reales:** No se evaluaron textos extremadamente largos de usuarios reales, adjuntos múltiples en producción ni estados de error dinámicos devueltos por controladores.
