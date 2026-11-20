# UX-01 — Rendimiento, navegación y rediseño profesional de Mensajes y LEXA

**Rama:** `feat/ux-01-navigation-messaging-lexa`  
**Worktree dedicado:** `D:\projects\plataforma-legal-V2-ux-01`  
**Base (`main`):** `52e5c0812a191be8973038a35bfaf4d2c668b534`

---

## 1. Problemas reproducidos

Durante la auditoría del flujo de navegación entre páginas internas (`Inicio`, `Comunidad`, `Profesionales`, `Mensajes`, `LEXA`, `Guardados` y `Perfil`) y de las vistas de **Mensajes** y **LEXA**, se identificaron y reprodujeron los siguientes problemas de experiencia y rendimiento:

1. **Navegación que aparenta terminar de cargar y regresa brevemente a la vista anterior (snap-back / doble transición) o reconstruye la pantalla con retraso:**
   - Al navegar rápidamente entre secciones con hojas de estilo específicas por página (p. ej. `Inicio` → `Profesionales` → `Mensajes` → `LEXA`) o hacer clics repetidos en la barra de navegación, la transición de Turbo Drive se bloqueaba esperando la descarga de hojas de estilo nuevas del `<head>`, o disparaba visitas solapadas y redirecciones tardías desde controladores que seguían vivos durante la salida.
2. **Bloqueo de navegación de hasta 10 segundos al salir de Mensajes durante un ciclo de polling:**
   - Mientras la vista de `Mensajes` ejecutaba `GET /mensajes/{conversation}/actualizaciones` cada 10 segundos, cualquier clic del usuario hacia otra página (`Inicio`, `LEXA`, `Comunidad`) quedaba en espera de sesión en el servidor si coincidía con el polling activo.
3. **Destello de layout (FOUC / layout shift) al entrar a Profesionales y al hidratar páginas internas:**
   - El panel `#professional-directory-filters` se renderizaba visible en el HTML del servidor y se ocultaba decenas de milisegundos después cuando terminaba de descargarse y ejecutarse el chunk dinámico de `professionals.js`.
   - En cada evento `turbo:load`, `app.js` ejecutaba `import(...)` dinámico asíncrono para montar el controlador de la página después de que Turbo ya había pintado el nuevo `<body>`.
4. **Fugas de listeners y carreras asíncronas en LEXA, Inicio, Perfil y Guardados:**
   - En `lexa.js`, todos los `addEventListener` se registraban directamente sobre nodos del DOM sin señal de `AbortController`, y las peticiones `fetch` de carga de conversación (`selectSession`) o generación (`submitPrompt`) no se cancelaban ni aislaban al cambiar de sesión o navegar fuera.
   - En `home.js`, `personal.js`, `saved/library.js` y `profiles/professional.js`, los callbacks de `fetch` podían ejecutar `window.location.reload()` o `window.location.assign(...)` después de que el usuario ya había iniciado otra navegación con Turbo.
5. **Problemas visuales y de jerarquía en Mensajes y LEXA:**
   - **Mensajes** presentaba bloques oscuros pesados para mensajes salientes (`linear-gradient(135deg, #641734, #7c1f43)` con texto blanco), ausencia de separadores temporales de fecha entre días, área de adjuntos siempre desplegada ocupando altura vertical, y cabeceras/composer sin jerarquía social ligera.
   - **LEXA** ejecutaba una animación infinita permanente de 28 segundos (`.lexa-atmosphere::before` con `filter: blur(18px)`) que consumía composición GPU continuamente, carecía de identidad propia en el toolbar superior, mostraba el selector de alcance jurídico desconectado como una caja pesada superior y no diferenciaba con claridad editorial la consulta del usuario frente al análisis jurídico fundamentado con citas verificables.

---

## 2. Causas identificadas

### 2.1. Hojas de estilo fragmentadas por página (`@yield('page-assets')`) y carrera en `HeadSnapshot` de Turbo Drive
En `resources/views/layouts/app.blade.php`, cada vista inyectaba su propio CSS mediante `@section('page-assets')` (`home.css`, `community.css`, `messages.css`, `lexa.css`, `professionals.css`, `personal.css`, `saved.css`, `editorial.css`).
- En `@hotwired/turbo` (`PageRenderer.copyNewHeadStylesheetElements`), cuando la página destino contiene `<link rel="stylesheet">` que no estaban en el `<head>` actual, Turbo **bloquea el intercambio del `<body>`** en `await Promise.all(waitForLoad(...))` hasta que cada hoja de estilo nueva se descarga y parsea por red.
- Peor aún: cuando un usuario navega de la Página A a la Página B, Turbo inserta `B.css` en `<head>` pero **nunca elimina `A.css`**. Si el usuario navega A → B → C rápidamente mientras `B.css` sigue cargando, la promesa de hoja de estilo de Turbo no se aborta limpiamente y provoca retrasos perceptibles, cascadas de red en cada primer cambio de pestaña y reglas CSS acumuladas en orden variable.

### 2.2. Bloqueo de sesión (`->block(60, 10)`) en la ruta de solo lectura `messages.updates`
En `routes/web.php`, la ruta `GET /mensajes/{conversation}/actualizaciones` tenía aplicado `->block(60, 10)`.
- En Laravel, `Route::block()` adquiere un bloqueo exclusivo de caché por sesión durante toda la petición HTTP.
- Aunque el endpoint solo realiza lecturas (`SELECT`) para devolver mensajes nuevos, el bloqueo serializaba cualquier navegación concurrente del mismo usuario (p. ej. clic en `LEXA` o `Inicio` mientras un poll estaba en vuelo), provocando pausas de cientos de milisegundos o varios segundos y respuestas que llegaban fuera de orden.

### 2.3. Hidratación asíncrona diferida (`loadOnPage` con `import()` dinámico) y visibilidad inicial en cliente
- En `resources/js/app.js`, los controladores de páginas internas se cargaban con `loadOnPage(selector, () => import('./module'))`. Aunque ahorraba pocos kilobytes iniciales, obligaba a que en el evento `turbo:load` Turbo pintara primero el DOM crudo y luego esperara la resolución de promesas de módulos JS antes de inicializar el estado visual (`syncFilterPanel(false)` en `Profesionales`, pestañas móviles en `Mensajes`, estado de `LEXA`), generando un parpadeo post-pintado.

### 2.4. Navegaciones programáticas y polling sin cancelación en `turbo:visit`
- En `resources/js/ui/page.js`, el ciclo de limpieza (`cleanups`) solo se ejecutaba en `turbo:before-cache` o en el siguiente `turbo:load` (es decir, **después** de que la respuesta HTTP de la nueva página ya había regresado del servidor).
- Durante el intervalo entre el clic del usuario (`turbo:visit`) y la llegada de la respuesta (`turbo:before-render` / `turbo:load`), el intervalo de polling de `Mensajes` (`10s`) o las peticiones en curso de `LEXA`, `Inicio`, `Perfil` y `Guardados` seguían activas. Si un callback de recuperación o mutación llamaba a `window.location.assign(...)` o `window.location.reload()` en ese intervalo, el navegador cancelaba o revertía la transición de Turbo y regresaba al usuario a la página anterior (causa directa del fenómeno de "termina de cargar y me regresa a donde estaba").

---

## 3. Correcciones realizadas

1. **Unificación de hojas de estilo internas en `<head>` (`resources/views/layouts/app.blade.php`):**
   - Se consolidaron las hojas de estilo de la aplicación autenticada en el `@vite([...])` principal del `<head>` y se eliminó `@yield('page-assets')`.
   - Ahora todas las páginas internas comparten exactamente la misma huella de `<link rel="stylesheet">` en `<head>`. Turbo Drive realiza el intercambio de `<body>` de forma inmediata (`0` esperas de `copyNewHeadStylesheetElements`) y sin acumulación asíncrona de CSS entre vistas.
   - Se añadió `<meta name="color-scheme" content="light dark">` en `<head>` y `:root { color-scheme: light dark; }` en `resources/css/app.css`.

2. **Eliminación del bloqueo de sesión en polling de Mensajes (`routes/web.php`):**
   - Se removió `->block(60, 10)` de `GET /mensajes/{conversation}/actualizaciones` (`messages.updates`), conservando `throttle:120,1` y manteniendo intacto `->block(60, 10)` en todas las rutas de escritura (`POST`, `PATCH`, `DELETE`).

3. **Montaje síncrono de controladores y eliminación del flash en Profesionales (`resources/js/app.js`, `resources/views/pages/professionals/index.blade.php`):**
   - Se reemplazó `loadOnPage(..., () => import(...))` por importación estática directa y montaje síncrono (`setupHome()`, `setupCommunity()`, `setupMessages()`, `setupLexa()`, `setupProfessionals()`, `setupPersonal()`, `setupSavedLibrary()`, `setupEditorial()`, `setupPublicNavigation()`), ejecutándose en el mismo tick de `turbo:load`.
   - En `resources/views/pages/professionals/index.blade.php`, `#professional-directory-filters` ahora incluye `@if (! $hasActiveFilters) style="display: none;" @endif` desde el servidor, evitando cualquier cambio de layout al hidratar.

4. **Ciclo de vida `onBeforeVisit` / `isVisiting` y feedback instantáneo de navegación (`resources/js/ui/page.js`, `resources/js/ui/navigation.js`):**
   - Se añadieron los helpers `onBeforeVisit(callback)` e `isVisiting()` en `resources/js/ui/page.js`, conectados al evento `turbo:visit`.
   - Se implementó `setupNavigationFeedback()`:
     - Deduplica clics rápidos repetidos hacia el mismo destino en vuelo.
     - Evita recargas redundantes al hacer clic sobre la sección ya activa (y en móvil dentro de `Mensajes`, cambia limpiamente del panel `list` al `chat`).
     - Aplica `data-nav-pending="true"` instantáneamente (`0ms`) sobre el enlace pulsado y lo limpia en `turbo:before-cache`, `turbo:load`, `turbo:fetch-request-error` y `popstate` (restauración de historial y BFCache).
   - Se redujo `Turbo.config.drive.progressBarDelay` de `120ms` a `80ms`.

5. **Cancelación determinista en Mensajes, LEXA y controladores secundarios (`resources/js/messages.js`, `resources/js/lexa.js`, `resources/js/home.js`, `resources/js/personal.js`, `resources/js/saved/library.js`, `resources/js/profiles/professional.js`):**
   - **Mensajes (`resources/js/messages.js`):** Se añadió un `AbortController` dedicado (`pollRequests`) que aborta inmediatamente cualquier petición de polling cuando se dispara `onBeforeVisit`, al cambiar de conversación o al desmontar la página. Además, `recoverFromIncrementalFailure` verifica `if (!isVisiting())` antes de invocar `window.location.assign(...)`.
   - **LEXA (`resources/js/lexa.js`):** Se migraron todos los listeners a `listen(...)` (limpieza automática por `AbortController`), se implementó `abortActiveThreadLoad()` para cancelar cargas de hilos obsoletas al cambiar de sesión (`selectSession`), iniciar `Nueva consulta` (`newSession`) o salir de la página (`onBeforeVisit`), y se implementó el seguimiento de generación por sesión (`generatingSessions = new Set()`) para que una respuesta en curso en la Sesión A no bloquee el composer de la Sesión B ni sobrescriba su hilo al terminar.
   - **Inicio, Perfil y Guardados:** Todos los listeners ahora usan `listen(...)` y todas las llamadas a `window.location.reload()` / `window.location.assign()` están protegidas por `if (!isVisiting())`.

---

## 4. Mejoras de rendimiento y mediciones reales

| Métrica / Vector | Antes (UX-00) | Después (UX-01) | Impacto medido |
| :--- | :--- | :--- | :--- |
| **Descargas de `<link rel="stylesheet">` al cambiar de pestaña en Turbo Drive** | 1 descarga bloqueante por cada nueva vista visitada (`@yield('page-assets')`) | **0 descargas en navegación** (`<head>` idéntico en todas las vistas internas) | Eliminación total del bloqueo `copyNewHeadStylesheetElements` y de recálculos de cascada CSS al navegar |
| **Retardo de hidratación de controladores en `turbo:load`** | Asíncrono vía `import()` dinámico post-pintado | **Síncrono (`0 ms`, mismo tick de `turbo:load`)** | Cero parpadeos post-pintado (`#professional-directory-filters`, tabs móviles de Mensajes y LEXA) |
| **Bloqueo de sesión por polling de Mensajes (`GET /mensajes/{id}/actualizaciones`)** | `Route::block(60, 10)` (serializaba navegaciones concurrentes hasta 10s) | **`locksFor() === null` (0 ms de bloqueo de sesión)** | Las navegaciones fuera de `Mensajes` responden de inmediato aunque haya un poll activo |
| **Animación GPU de fondo en LEXA (`.lexa-atmosphere::before`)** | `animation: lexa-drift 28s infinite alternate` con `filter: blur(18px)` | **Estática (`animation: none`)** | **0% de composición continua de GPU en reposo** dentro de LEXA |
| **Tiempo de build de producción (`npm run build`)** | 1.65 s (chunks fragmentados por vista) | **1.16 s** (`99 modules transformed`, `public/build/assets/app-*.js` `135.75 kB` / `38.68 kB` gzip) | Carga única en caché con transición instantánea entre todas las rutas |
| **Ejecución de suite de pruebas frontend (`node --test`)** | 139 pruebas | **154 pruebas en `293.5 ms`** (`0` fallos) | Cobertura completa de ciclo de vida de navegación, BFCache, polling y concurrencia en LEXA |

---

## 5. Cambios visuales (Mensajes y LEXA)

### 5.1. Rediseño de Mensajes (`resources/css/messages.css`, `resources/views/pages/messages/*`, `resources/views/components/messages/*`)
- **Identidad visual de mensajería social ligera:** Superficies claras y cálidas (`--msg-surface`, `--msg-canvas`), con soporte nativo para modo claro y oscuro (`color-scheme: light dark`).
- **Acentos de marca contenidos (`#9E1F52` / `#7F1842`):** El color institucional se utiliza como acento de identidad (indicadores de conversación activa, pill de no leídos, botón primario de envío y borde sutil) en lugar de bloques oscuros pesados.
- **Burbujas diferenciadas y legibles:**
  - **Entrantes (`.message-card--incoming`):** Superficie limpia blanca/elevada con borde neutro sutil (`1px solid var(--msg-border)`), radio asimétrico (`18px 18px 18px 6px`) y sombra suave.
  - **Salientes (`.message-card--outgoing`):** Tinte rosa-borgona suave de alta legibilidad (`color-mix(in srgb, #9E1F52 9%, #ffffff)` en modo claro; `color-mix(in srgb, #9E1F52 24%, #171b24)` en modo oscuro) con texto oscuro/claro de alto contraste, borde `color-mix(in srgb, #9E1F52 22%, transparent)` y radio asimétrico (`18px 18px 6px 18px`).
- **Separadores temporales por fecha (`.messages-date-divider`):** Agrupación automática de mensajes por día (`Hoy`, `Ayer` o fecha localizada mediante `data-message-time="day"` en `resources/js/messages/dates.js` y `resources/views/pages/messages/partials/history.blade.php`).
- **Cabecera translúcida con fallback sólido:** `.messages-thread-header` aplica `@supports (backdrop-filter: blur(10px))` con `backdrop-filter: blur(12px) saturate(140%)` y fallback sólido automático cuando el navegador no lo soporta.
- **Composer estilo cápsula e integración de adjuntos:**
  - El selector de archivos adjuntos se integró como un botón de apertura discreto (`[data-attachment-toggle]`, `36px` en escritorio y `44px` en móvil/táctil) dentro de la barra del composer (`.messages-composer-bar`), manteniendo el área de adjuntos colapsada por defecto y abriéndola automáticamente al pulsar el botón o seleccionar archivos.
- **Microanimaciones discretas:** Solo los mensajes recién insertados (`.message-card.is-new`) ejecutan una transición suave de entrada (`180ms cubic-bezier(0.22, 1, 0.36, 1)`) condicionada a `@media (prefers-reduced-motion: no-preference)`, sin reanimar la lista existente durante el polling.

### 5.2. Rediseño de LEXA (`resources/css/lexa.css`, `resources/views/pages/lexa/index.blade.php`, `resources/views/components/lexa/composer.blade.php`)
- **Identidad visual propia (`#6C4ACB`):** Claramente diferenciada del chat social de `Mensajes`. Utiliza una paleta violeta jurídico (`--lexa-primary: #6c4acb`, `--lexa-primary-strong: #5636b0`, `--lexa-primary-soft`) con soporte completo para modo claro y oscuro.
- **Identidad integrada en el toolbar superior (`.lexa-toolbar`):** Incluye emblema `.lexa-toolbar-mark`, título `LEXA`, subtítulo contextual de la sesión activa y badge `.lexa-toolbar-mode-pill` (`Investigación normativa`).
- **Diferenciación entre consulta del usuario y dictamen/análisis jurídico de LEXA:**
  - **Consulta del usuario (`.lexa-msg--user`):** Tarjeta estructurada con acento violeta lateral/borde suave y cabecera de consulta.
  - **Respuesta de LEXA (`.lexa-msg--assistant`):** Tarjeta editorial amplia tipo briefing jurídico con barra superior en gradiente `#6C4ACB → #8E72E8`, tipografía cómoda para lectura extensa (`max-width: 72ch`), listas y bloques estructurados.
- **Citas jurídicas verificables y avisos de sustento (`.lexa-citations`, `.lexa-grounding-notice`):**
  - Las fuentes verificables se presentan como fichas normativas interactivas (`.lexa-citation-item`) con badge de rango normativo, estado de vigencia y extracto limpio.
  - Los avisos de alcance o falta de fuente verificada (`.lexa-grounding-notice`) tienen jerarquía visual clara sin alarmismo desproporcionado.
- **Barra de alcance jurídico integrada en el composer (`.lexa-scope-bar`):**
  - Los selectores de `Materia` y `Entidad / Jurisdicción` se integraron de forma compacta y elegante encima de la cápsula de consulta (`.lexa-composer-bar`), reduciendo el ruido visual y manteniendo el foco en la pregunta jurídica.
- **Estado de espera y accesibilidad:**
  - El indicador de síntesis jurídica (`.lexa-banner-indicator`) utiliza un spinner indeterminado que desacelera a `6s` bajo `@media (prefers-reduced-motion: reduce)` y conserva visibilidad de bordes bajo `@media (forced-colors: active)`.

---

## 6. Componentes reutilizados

Se preservaron y evolucionaron los componentes Blade y módulos JS existentes sin romper ningún contrato backend ni selector `data-*`:
- `resources/views/layouts/app.blade.php` y `resources/views/components/layout/user-header.blade.php`
- `resources/views/pages/messages/index.blade.php` y `resources/views/pages/messages/partials/history.blade.php`
- `resources/views/components/messages/conversations.blade.php` y `resources/views/components/messages/composer.blade.php`
- `resources/views/pages/lexa/index.blade.php` y `resources/views/components/lexa/composer.blade.php`
- `resources/js/ui/page.js`, `resources/js/ui/navigation.js`, `resources/js/messages.js`, `resources/js/messages/dates.js`, `resources/js/lexa.js`

---

## 7. Archivos modificados

### Vistas Blade y Rutas
- `routes/web.php`
- `resources/views/layouts/app.blade.php`
- `resources/views/pages/professionals/index.blade.php`
- `resources/views/pages/messages/index.blade.php`
- `resources/views/pages/messages/partials/history.blade.php`
- `resources/views/components/messages/conversations.blade.php`
- `resources/views/components/messages/composer.blade.php`
- `resources/views/pages/lexa/index.blade.php`
- `resources/views/components/lexa/composer.blade.php`

### JavaScript Frontend
- `resources/js/app.js`
- `resources/js/ui/page.js`
- `resources/js/ui/navigation.js`
- `resources/js/messages.js`
- `resources/js/messages/dates.js`
- `resources/js/lexa.js`
- `resources/js/home.js`
- `resources/js/personal.js`
- `resources/js/saved/library.js`
- `resources/js/profiles/professional.js`

### Hojas de Estilo (Design System, Mensajes y LEXA)
- `resources/css/app.css`
- `resources/css/components/userHeader.css`
- `resources/css/messages.css`
- `resources/css/lexa.css`

### Pruebas
- `tests/Feature/Ux01NavigationMessagingLexaTest.php` (nuevo)
- `tests/frontend/navigation-lifecycle.test.mjs` (nuevo)
- `tests/frontend/lexa-controller.test.mjs` (nuevo)
- `tests/frontend/messages-recovery.test.mjs` (ampliado)
- `tests/frontend/messages-dates.test.mjs` (ampliado)

---

## 8. Pruebas

### 8.1. Suite Frontend (`node --test tests/frontend/*.test.mjs`)
- **Total:** **154 pruebas aprobadas, 0 fallos** (`293.5 ms`).
- Casos nuevos verificados:
  - `tests/frontend/navigation-lifecycle.test.mjs`:
    - Limpieza de listeners en `turbo:before-cache` y prevención de doble inicialización en `turbo:load` repetidos.
    - Ejecución de hooks `onBeforeVisit` e indicador `isVisiting() === true` inmediatamente en `turbo:visit`.
    - Deduplicación de clics rápidos al mismo destino en vuelo, prevención de recarga redundante del tab activo, cambio de panel móvil `list` → `chat` en `Mensajes`, y limpieza de `[data-nav-pending]` en `popstate` / BFCache.
  - `tests/frontend/messages-recovery.test.mjs`:
    - Preservación del borrador y supresión de `window.location.assign` cuando una navegación (`isVisiting: true`) ya está en curso al fallar una petición incremental.
  - `tests/frontend/messages-dates.test.mjs`:
    - Formateo de separadores temporales de día (`Hoy`, `Ayer`, fecha localizada) para `[data-message-time="day"]`.
  - `tests/frontend/lexa-controller.test.mjs`:
    - Cancelación de petición obsoleta (`AbortError`) al cambiar rápidamente de la Sesión A a la Sesión B o iniciar `Nueva consulta`.
    - Aislamiento de generación concurrente: generar respuesta en la Sesión 101 y cambiar a la Sesión 202 mantiene habilitado el composer de la Sesión 202 y evita que la respuesta tardía de la Sesión 101 sobrescriba el DOM de la Sesión 202.
    - Cancelación de cargas en vuelo en `turbo:visit` y ausencia de listeners duplicados tras múltiples ciclos `turbo:before-cache` + `turbo:load`.

### 8.2. Suite Backend / Integración Laravel (`plataforma_legal_v2_antigravity_test`)
- **Total:** **56 pruebas aprobadas, 677 assertions, 0 fallos, 0 omitidas** (`21.22 s`), ejecutadas exclusivamente sobre la base aislada `plataforma_legal_v2_antigravity_test` y con `LARAVEL_STORAGE_PATH` aislado:
  - `tests/Feature/Ux01NavigationMessagingLexaTest.php` (4 pruebas: consistencia de `<head>` y `<meta name="color-scheme" content="light dark">` en las 7 rutas internas, ausencia de bloqueo de sesión `locksFor() === null` en `messages.updates`, separadores `.messages-date-divider` por día, ocultación SSR de `#professional-directory-filters`, e identidad `.lexa-toolbar` + `.lexa-scope-bar` en LEXA)
  - `tests/Feature/FrontendMergeIntegrationTest.php`
  - `tests/Feature/MessagingIntegrationTest.php`
  - `tests/Feature/LexaConversationTest.php`
  - `tests/Feature/LexaGenerationFlowTest.php`
  - `tests/Feature/LexaRagIntegrationTest.php`

---

## 9. Validaciones en navegador (UX-01-B)

- **Entorno de validación:** Servidor local dedicado en `http://127.0.0.1:8095` conectado exclusivamente a la base de pruebas aislada `plataforma_legal_v2_antigravity_test` (`LEXA_GENERATION_ENABLED=false`, `LEXA_PROVIDER=null`, sin llamadas reales a Gemini ni datos jurídicos modificados).
- **Navegación repetida e historial (`1280x800`):**
  - Secuencia completa `Inicio -> Comunidad -> Profesionales -> Mensajes -> LEXA -> Guardados -> Perfil -> Mensajes -> LEXA`: todas las transiciones completaron sin regresos inesperados (*snap-back*) y con `0` errores de consola o respuestas HTTP 4xx/5xx.
  - Tiempos reales medidos de clic a `turbo:load`: `Inicio -> Comunidad` (`936 ms`), `Comunidad -> Profesionales` (`687 ms`), `Profesionales -> Mensajes` (`753 ms`), `Mensajes -> LEXA` (`756 ms`), `LEXA -> Guardados` (`1098 ms`), `Guardados -> Perfil` (`739 ms`), `Perfil -> Mensajes` (`753 ms`), `Mensajes -> LEXA` (`763 ms`).
  - Historial del navegador (`Back` / `Forward`): `LEXA -> Mensajes` (`145 ms`), `Mensajes -> Perfil` (`147 ms`), `Perfil -> Mensajes` (`164 ms`), `Mensajes -> LEXA` (`163 ms`).
  - En `/profesionales`, `#professional-directory-filters` permaneció oculto desde el primer pintado (`isVisible() === false`) y tras 500 ms sin parpadeo de apertura/cierre.
- **Mensajes y polling:**
  - Navegación limpia entre conversaciones (`/mensajes/299` y `/mensajes/298`), burbujas entrantes/salientes diferenciadas, separadores de fecha, cabecera translúcida y toggle de adjuntos colapsado.
  - Espera de `11.2 s` sobre `/mensajes/299` con ciclo de polling activo: `listHtmlUnchanged: true`, `0` errores de consola/red, y salida inmediata a `/lexa` en `744 ms` sin bloqueo ni regreso.
- **LEXA (sin generación real) y corrección móvil (`390x844`):**
  - Cambio fluido entre sesiones (`Despido injustificado y prestaciones` y `Cláusula de confidencialidad en servicios`), barra de alcance integrada y diferenciación visual `#6C4ACB`.
  - **Defectos móviles reproducidos y corregidos en `resources/css/lexa.css`:** en `390x844`, `.lexa-session-title` se truncaba a `"C..."` y el composer ocupaba más de la mitad vertical. Tras ocultar `.lexa-toolbar-mode` y `.lexa-scope-note` y compactar el composer bajo `@media (max-width: 639px)`, el título amplió a `149 px` legibles (`"Cláusula de confiden..."`) y `.lexa-thread` creció de `~280 px` a `342.22 px` (`0` desbordamiento horizontal y `0` errores).
- **Limitación observada en sandbox:**
  - Las capturas en escritorio (`1280x800`) y móvil (`390x844`) validaron el tema **oscuro** del sistema operativo; el modo **claro** no pudo emularse programáticamente porque `page.emulateMedia()` no está soportado en el sandbox del navegador y el selector interno de tema en `/configuracion` está marcado como `"Próximamente"`. Se recomienda revisarlo directamente en navegador local con tema claro del SO.

---

## 10. Riesgos restantes

1. **Tamaño único de bundle CSS/JS interno (`app.js` + hojas de estilo consolidadas):**
   - Consolidar las hojas de estilo e importar síncronamente los controladores internos añade ~20 kB gzip a la primera carga autenticada a cambio de eliminar por completo las descargas bloqueantes de CSS/JS en cada transición de Turbo Drive. Dado que todos los módulos se cachean con hash inmutable de Vite en la primera visita, el balance favorece ampliamente la fluidez de navegación.
2. **Polling HTTP cada 10s en Mensajes:**
   - Aunque el polling ya no bloquea la sesión de Laravel (`locksFor() === null`) y se aborta instantáneamente en `turbo:visit`, sigue siendo un esquema de short-polling cada 10 segundos. Para escalar a miles de conexiones simultáneas en el futuro, convendrá evaluar Laravel Reverb / WebSockets o Server-Sent Events (SSE).

---

## 11. Antes / después de las interacciones

| Interacción | Antes | Después |
| :--- | :--- | :--- |
| **Navegar entre `Inicio` → `Profesionales` → `Mensajes` → `LEXA`** | Turbo esperaba la descarga de la hoja de estilo de cada página antes de intercambiar el `<body>`, y luego descargaba el chunk JS dinámicamente provocando un segundo ajuste visual. | El `<head>` es idéntico en todas las páginas internas; Turbo intercambia el `<body>` al instante y el controlador se monta síncronamente en `0 ms` sin parpadeos. |
| **Salir de `Mensajes` mientras ocurre un ciclo de polling** | La ruta `messages.updates` mantenía `Route::block(60, 10)`, bloqueando la nueva navegación en el servidor y permitiendo que un error de red tardío llamara a `window.location.assign(...)` y regresara al usuario a `Mensajes`. | `messages.updates` no bloquea la sesión (`locksFor() === null`), `turbo:visit` aborta el `fetch` de polling de inmediato y `isVisiting()` impide cualquier redirección tardía. |
| **Entrar al directorio de `Profesionales` sin filtros activos** | El panel de filtros aparecía desplegado en el HTML inicial y colapsaba brusco al cargar `professionals.js`. | El servidor renderiza `style="display: none;"` cuando `!$hasActiveFilters`; cero desplazamiento de layout. |
| **Cambiar de conversación en `LEXA` mientras otra sesión carga o genera respuesta** | Las peticiones anteriores seguían vivas, el composer global quedaba bloqueado y la respuesta tardía podía inyectarse en la sesión equivocada. | `selectSession` y `newSession` abortan cualquier carga de hilo previa; el estado de generación se aísla por `sessionId` (`generatingSessions`), manteniendo libre el composer de otras sesiones. |
| **Lectura y redacción en `Mensajes` y `LEXA`** | En `Mensajes`, burbujas salientes oscuras pesadas, sin separadores de fecha y con adjuntos ocupando espacio fijo. En `LEXA`, fondo con animación infinita en blur y alcance jurídico separado del composer. | En `Mensajes`, burbujas suaves de alto contraste, separadores por día (`Hoy`, `Ayer`, fecha), cabecera translúcida y composer en cápsula con adjuntos bajo demanda. En `LEXA`, identidad violeta `#6C4ACB`, fondo estático eficiente, citas jurídicas tipo ficha normativa y alcance jurídico integrado en el composer. |

---

## 12. Recomendaciones posteriores

1. **Verificación visual rápida en navegador local antes del merge a `main`:**
   - Una vez que concluyan las tareas paralelas de `MEDIA` y `DEPLOY`, abrir el worktree en navegador para confirmar visualmente las curvas de color en modo claro y oscuro (`prefers-color-scheme: dark`).
2. **Evaluar `<meta name="turbo-prefetch" content="true">` o prefetch selectivo en hover:**
   - Ahora que las rutas de lectura no tienen bloqueos de sesión y el `<head>` está unificado, Turbo puede pre-cargar vistas internas al pasar el cursor con seguridad.
3. **Transición futura de short-polling a WebSockets / SSE en Mensajes:**
   - Reemplazar el intervalo de 10 segundos por eventos push cuando la infraestructura de producción lo habilite.
