# 09F — QA final de integración visual

- **Fecha:** 2026-11-20
- **Worktree:** `D:\projects\plataforma-legal-V2-integracion`
- **Rama:** `integracion/diseno-09f` (base `853c1f7`, sobre `main` `c95662c958ef537fd5e0a28204bb53dcf60ef8bd`)

---

## 1. Preflight de contrato y comportamiento

- `git diff --stat main..853c1f7 -- app/ routes/ config/ database/` sin cambios: no se alteraron rutas, controladores, modelos, políticas, permisos ni contratos de API.
- Revisión de `resources/js/community.js`, `resources/js/public.js`, `resources/js/saved/library.js`, `resources/views/pages/lexa/demo.php` y componentes Blade interactivos (`post-card.blade.php`, `home/post.blade.php`, `post-form.blade.php`, `composer.blade.php`, `conversations.blade.php`):
  - `community.js` mantiene limpieza de URLs de previsualización en `onCleanup` y sincronización de `DataTransfer` con `input.files` para el envío nativo.
  - `public.js` y `saved/library.js` respetan el ciclo de vida Turbo (`onPage`, `onCleanup`, `turbo:load`, `turbo:before-render`).
  - `post-card.blade.php` y `home/post.blade.php` mantienen los controles interactivos por encima de `.community-post-stretched-link` / `.home-post-stretched-link`.

---

## 2. Resultado de QA visual real (navegador)

Ejecutado contra servidor PHP local aislado (`http://127.0.0.1:8019`, `APP_ENV=testing`, `DB_DATABASE=plataforma_legal_v2_test`, `SESSION_DRIVER=array`, `CACHE_STORE=array`, `LARAVEL_STORAGE_PATH` externo al repositorio).

### Pantallas comprobadas
- **Landing (`/`)** (`1280x800` y `390x844`): HTTP 200, sin desbordamiento horizontal (`375px` de ancho útil en `390x844`, `horizontalScrollMoved: false`), menú móvil `"Explorar"` abre y cierra correctamente (`aria-expanded`), sin errores JS en consola.
- **Comunidad (`/comunidad`)** (`1280x800` y `390x844`): HTTP 200, barra de filtros, compositor de invitado, estado vacío y barra lateral sin solapamientos ni desbordamiento horizontal (`375px`), menú móvil operativo, cero errores JS.
- **Profesionales (`/profesionales`)** (`1280x800` y `390x844`): HTTP 200, buscador (`Estado`/`Municipio`/`Especialidad`) y estado `0 perfiles públicos` legibles en modo oscuro sin desbordamiento (`375px`), cero errores JS.
- **Ayuda (`/ayuda`)** (`1280x800` y `390x844`): HTTP 200, buscador, píldoras de categoría, 3 tarjetas de acceso rápido, 23 acordeones FAQ y barra lateral sin desbordamiento (`375px`), cero errores JS. *(Ver sección 4 sobre el ajuste aplicado a la tarjeta LEXA en `390 px`).*
- **Iniciar sesión (`/iniciar-sesion`)** y redirección de **`/lexa`**, **`/inicio`** y **`/mensajes`** como invitado: HTTP 200 tras redirección esperada, sin desbordamiento (`390px`), cero errores JS.
- **Navegación Turbo y Atrás/Adelante**: 10 transiciones entre `/`, `/comunidad`, `/profesionales` y `/ayuda` (ida, `goBack()` y `goForward()`) con actualización correcta de URL, título y contenido, sin errores JS.
- **Modo oscuro (`prefers-color-scheme: dark`)**: activo de forma nativa en todas las pantallas visitadas, con contraste legible y sin superficies rotas.

---

## 3. Pruebas y cifras

- **Vite build (`npm run build`)**: exitoso en `1.32s` inicial y `2.60s` tras la corrección CSS (`99` módulos transformados; `userHeader` sin CSS base duplicado).
- **Suite frontend (`node --test tests/frontend/*.test.mjs`)**: **141 / 141 aprobadas** (`0` fallos, `0` omitidas).
- **Suite Laravel completa (`php artisan test` con `APP_ENV=testing`, `plataforma_legal_v2_test`, caché/sesión `array` y `LARAVEL_STORAGE_PATH` externo)**: **838 / 838 aprobadas** (`17,834` aserciones, `202.69s`).
- **`git diff --check main..HEAD`**: sin errores en código ni CSS/JS/Blade; únicamente 2 saltos de línea markdown (`  `) preexistentes en `docs/visual-worktree-inventory.md:3-4`.

---

## 4. Problemas encontrados y corregidos

- **Tarjeta `"Conoce la demo de LEXA"` en `/ayuda` a `390x844` (introducido en 09F)**:
  - *Causa:* en `@media (max-width: 639px)` de `resources/css/library-help.css`, `.help-guide` aplicaba `grid-template-columns: 28px minmax(0,1fr)` esperando un `<svg>` con `grid-row: span 2`. Al sustituirse el icono por `<span class="landing-lexa-wordmark">LEXA</span>`, el wordmark quedaba apretado contra el título y empujaba el subtítulo a la columna de `28px`.
  - *Corrección:* añadido `.help-guide:has(> .landing-lexa-wordmark) { grid-template-columns: minmax(0,1fr); gap: 6px; }` en `resources/css/library-help.css`.
  - *Verificación:* recompilado Vite y reverificado en navegador real en `/ayuda` a `390x844` (`343px × 118.88px`, apilado limpio sin solapamientos ni desbordamiento).

---

## 5. Limitaciones pendientes (no comprobadas en navegador real)

1. **Vistas autenticadas en navegador**: `/inicio` (tarjetas de Inicio), `/mensajes` (banner de recuperación de sesión), `/lexa` autenticado y el compositor/modal autenticado de `/comunidad` redirigen a `/iniciar-sesion` sin sesión iniciada; quedaron cubiertos por las suites automatizadas (`838` tests Laravel y `141` tests frontend), pero **pendientes de QA visual autenticado**.
2. **Modo claro (`prefers-color-scheme: light`) en navegador**: el sandbox del navegador operó en modo oscuro por defecto sin soporte de `page.emulateMedia()` ni selector de tema en la UI; **pendiente de verificación visual en modo claro**.
3. **Escalado de texto al 200 % en navegador**: `page.evaluate()` no está disponible en el sandbox del navegador para inyectar `fontSize = '200%'`; **pendiente de comprobación manual/navegador al 200 %**.
4. **Notas menores preexistentes observadas (fuera de alcance 09F)**: en `/profesionales` hay 2 landmarks `<header>`/`banner`; en `/iniciar-sesion` hay un `<main>` anidado y dos enlaces de recuperación de contraseña.

---

## 6. Archivos modificados en este QA

- `resources/css/library-help.css` (corrección responsive de `.help-guide` con `.landing-lexa-wordmark` en `<= 639px`)
- `docs/visual-integration-09f-qa.md` (este informe)

---

## 7. Estado de Git

- `D:\projects\plataforma-legal-V2` (`main`): intacto en `c95662c958ef537fd5e0a28204bb53dcf60ef8bd`, árbol de trabajo limpio.
- `D:\projects\plataforma-legal-V2-integracion` (`integracion/diseno-09f`): listo para commit local de QA (`library-help.css` + `docs/visual-integration-09f-qa.md`) sin merge ni push a `main`.

---

## 8. Recomendación

**Aprobar con QA visual acotado**: la integración visual (`853c1f7` + corrección en `library-help.css`) pasa el `100 %` de las pruebas automatizadas (`838` Laravel + `141` frontend + Vite build) y el QA en navegador real para rutas públicas en modo oscuro (`1280px` y `390px`) y navegación Turbo. Antes o inmediatamente después de incorporar a `main`, se recomienda completar únicamente la revisión visual rápida de las 3 limitaciones pendientes (vistas autenticadas `/inicio` y `/mensajes`, modo claro y zoom de texto al `200 %`).
