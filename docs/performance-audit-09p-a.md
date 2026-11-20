# 09P-A — Auditoría de rendimiento previa a optimización

## Estado y método

Repositorio `D:/projects/plataforma-legal-V2`, `main`, HEAD
`ca1967b8ce1ccafc5f6ccb1dab43fb484c162d37`. Árbol inicialmente limpio. Solo se crea
este informe: no se optimiza código, no se recompilan assets, no se cambian
configuración/dependencias ni se intervienen otros worktrees. No se ejecuta la suite.

Medición local mediante el Kernel HTTP real de Laravel en procesos PHP CLI.
Antes de fixtures, **dentro de cada proceso**, se verifica entorno `testing`,
driver MySQL, base configurada y `SELECT DATABASE()` exactamente
`plataforma_legal_v2_test`, caché/sesiones `array` y almacenamiento privado externo.
Un GET de Ayuda de preflight se ejecuta con conexión de solo lectura antes de
habilitar la transacción de fixtures. No se utiliza una cuenta o documento real.
Todo fixture de esta auditoría queda dentro de una transacción exterior revertida
al finalizar cada proceso; no se ejecutan migraciones ni seeders. Como es normal
en MySQL, rollback no garantiza retroceder contadores AUTO_INCREMENT de testing;
no se reinician esos contadores ni se pretende que su metadato sea inmutable.

Se crean 1, 12 y 24 autores/perfiles profesionales/publicaciones/conversaciones
adicionales, más tres cuentas sintéticas y una conversación con un mensaje. Usuario
activo sin permisos administrativos. Perfiles públicos sin fotografía, municipio
ni especialidades; publicaciones cortas sin adjuntos/comentarios/reacciones.
El perfil propio es mínimo. El chat seleccionado tiene un mensaje: el escalado de
Mensajes aumenta la bandeja, no el historial seleccionado. Las páginas conservan
sus límites de paginación (Comunidad 20, directorio 12 y bandeja 20).

63 peticiones: siete escenarios por tres tamaños por tres procesos. Un calentamiento
adicional de Inicio se excluye. Compilación Blade reutilizada en almacenamiento
externo; la mediana reduce el efecto de la primera compilación de otras vistas.
Una petición adicional comprueba duplicados SQL usando hashes de parámetros.
Todos los escenarios devolvieron 200. No hay prueba de carga concurrente.

**El tiempo es manejo de Kernel, no TTFB de navegador:** excluye arranque de PHP,
bootstrap, preparación de fixtures, red y descargas. Auth se establece antes del
cronómetro; sesiones/caché en memoria difieren del despliegue. La transacción
exterior y el hardware local también limitan la extrapolación. Tres muestras no
permiten p95/p99, capacidad de producción ni comparaciones inferiores a pocos ms.
Las sumas SQL provienen de QueryExecuted; el resto del tiempo no equivale por sí
solo a CPU Blade. No se han medido LCP, INP, long tasks, memoria JS ni CPU real de
navegador. Quedan pendientes, sin inventar cifras.

## Tabla breve antes de cambios

Escenario poblado de 24 elementos por tipo. Bytes sin comprimir. CSS/JS son archivos
locales únicos declarados por el HTML (incluidos modulepreload); no son transferencia
real medida por red. El navegador puede reutilizar caché y deduplicar URLs. Los
assets compilados existentes se leen de `public/build/manifest.json`, sin rebuild.

| Pantalla | Mediana Kernel ms | Consultas / SQL ms | HTML o JSON bytes | CSS bytes | JS bytes | Assets locales |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Inicio | 96.01 | 17 / 7.05 | 30890 | 229973 | 246485 | 13 |
| Comunidad | 164.02 | 20 / 8.14 | 105598 | 221915 | 246485 | 12 |
| Profesionales | 87.87 | 13 / 4.73 | 50693 | 222892 | 246485 | 12 |
| Perfil propio | 53.74 | 12 / 3.62 | 17711 | 201427 | 246485 | 11 |
| Editor de perfil | 55.97 | 10 / 2.97 | 19553 | 222892 | 246485 | 12 |
| Mensajes | 90.46 | 28 / 9.84 | 36588 | 223062 | 246485 | 12 |
| Polling JSON | 48.97 | 23 / 8.26 | 17145 | 0 | 0 | 0 |

Inicio: 17 consultas con 1/12/24 elementos. Comunidad: 20; Profesionales: 13;
Perfil: 12; editor: 10; Mensajes: 28; polling: 23, constantes en los tres tamaños.
No se confirmó N+1 creciente en estas variantes. Faltan variantes con adjuntos,
ubicación, especialidades, muchos mensajes y capacidades editoriales/moderadoras;
esta conclusión no demuestra ausencia universal de N+1 ni de consultas costosas.

## Cinco costes prioritarios verificables

Prioridad provisional por alcance, peso, frecuencia y escalado observados; no
constituye un ranking de tiempos de carga reales, todavía sin trazas de navegador.

### 1. Doble base CSS en las páginas autenticadas

**Medido:** se declaran `app.css` (98,077 bytes; gzip nivel 9: 18,439) y
`userHeader.css` (103,350; gzip 19,237) en las cinco pantallas. Solo esas dos hojas
suman 201,427 bytes sin comprimir, más las específicas. La segunda vuelve a importar
Tailwind y `../app.css`: duplicación estructural comprobada, no dos descargas de
una misma URL. Afecta al primer acceso y al parseo de estilos; el coste real en
caché caliente no se midió.

**Archivos:** `resources/views/layouts/app.blade.php:24`,
`resources/css/components/userHeader.css:1`, `resources/css/app.css`.

**Propuesta:** compilar/cargar la base una sola vez y dejar las reglas específicas
del header separadas, resolviendo sus dependencias de utilidades en el build.
**Impacto esperado:** reducir bytes y reglas duplicadas en todas las páginas; el
ahorro exacto requiere construir la variante, no se promete toda la suma anterior.
**Riesgo:** pérdida de utilidades, variables, estados responsive o tema oscuro.
Verificar los cinco destinos, ambos temas y el header antes de publicar.

### 2. Entrada JS común con funcionalidades de páginas ajenas

**Medido:** las cinco pantallas declaran la misma entrada de 140,907 bytes y ocho
fragmentos compartidos; suma única 246,485 bytes, gzip por archivo nivel 9: 80,053.
El fragmento `navigation` pesa 99,197 bytes. `app.js` importa estáticamente módulos
de Inicio, Comunidad, Mensajes, profesionales, perfil y otras funcionalidades;
se revisaron esas dependencias únicamente para explicar esta carga común.
`onPage` evita montar una función en la página equivocada, pero no evita cargar su
código estático. Esto prueba peso disponible/cargado por el grafo, no milisegundos
de ejecución ni que todo byte sea prescindible. Turbo/Alpine son compartidos útiles.

**Archivos:** `resources/js/app.js:1`, `resources/js/bootstrap.js`,
`resources/js/ui/page.js`, `resources/js/ui/navigation.js`, layout principal.

**Propuesta:** conservar el núcleo compartido y cargar módulos específicos por
página mediante entradas o importación diferida, con montaje/desmontaje Turbo.
**Impacto esperado:** menos bytes y evaluación inicial fuera de la pantalla que
los necesita; medir una variante para cuantificar ahorro. **Riesgo:** listeners
duplicados, pérdida de montaje tras Atrás/Turbo, carreras de importación y diálogos.
No quitar dependencias ni reemplazar Turbo sin evidencia adicional.

### 3. Polling de Mensajes reconstruye tres fragmentos completos aunque no cambie contenido

**Medido:** 23 consultas y JSON de 4,974 / 12,313 / 17,145 bytes con 1 / 12 / 24
conversaciones adicionales, respectivamente. En el caso poblado, mediana 48.97 ms
(8.26 ms SQL). El controlador siempre renderiza historial, bandeja e información;
el cliente analiza e introduce fragmentos mediante replaceChildren cuando el foco
lo permite, sin comprobar igualdad de contenido. La frecuencia normal es diez
segundos tras terminar la lectura: hasta aproximadamente seis consultas HTTP por
minuto activo, 138 sentencias SQL y 102,870 bytes JSON/minuto en este fixture.
Son proyecciones aritméticas de una pestaña activa, no tráfico observado de usuarios.
No se agrega el peso de los assets de una página completa a cada poll.

**Archivos:** `app/Http/Controllers/Messaging/InboxController.php:24` y `:49`,
`resources/js/messages.js:126`, `resources/js/messages/polling.js`.

**Propuesta:** evitar reemplazar fragmentos idénticos y evaluar una respuesta mínima
sin cambios o actualización parcial, después de revalidar identidad y acceso.
**Impacto esperado:** reducir renderizado, transferencia y mutaciones DOM periódicas;
cuantificar con historial y bandejas representativos. **Riesgo alto de privacidad:**
no reutilizar estado entre identidades ni saltarse denegaciones por optimizar una
respuesta sin cambios. Mantener las cuatro regresiones P1, abortos, pausa temporal,
idempotencia y lectura autorizada con can_send=false. No se propone tiempo real.

### 4. HTML y coste de renderizado del feed de Comunidad crecen por tarjeta

**Medido:** con texto corto y sin adjuntos, 1/12/24 publicaciones disponibles
producen 23,851 / 71,012 / 105,598 bytes HTML. La última respuesta contiene la
página limitada a 20. Medianas Kernel 108.27 / 134.69 / 164.02 ms, mientras SQL se
mantiene en 20 sentencias y 6.57 / 7.69 / 8.14 ms. El crecimiento no procede de un
N+1 confirmado. El incremento no SQL es medido; atribuirlo exclusivamente a Blade,
SVG, políticas o DOM exige un perfil adicional. Hay componentes, acciones y
formularios repetidos por tarjeta, pero no se midió su CPU individual.

**Archivos:** `resources/views/components/community/post-card.blade.php:1`,
`resources/views/pages/community/index.blade.php`,
`app/Services/Community/CommunityQuery.php:152`, `:182` y `:223`.

**Propuesta:** medir un perfil de renderizado y número de nodos por tarjeta; probar
una primera página más acotada y reducir marcado repetido donde preserve acceso
sin JavaScript. **Impacto esperado:** menos HTML/DOM y trabajo de renderizado en
Inicio/Comunidad, principalmente Comunidad. Inicio ya limita su feed a dos tarjetas:
no extrapolar los 105 KB a Inicio. **Riesgo:** paginación, foco, formularios CSRF,
IDs únicos, accesibilidad y acciones de moderación. No eliminar controles de seguridad.

### 5. Dos recorridos de acceso/bloqueo para el mismo chat en cada lectura

**Medido:** en una petición adicional de polling con 24 elementos se confirmaron
seis familias SQL ejecutadas dos veces con los mismos parámetros (comparación por
hash, sin guardar sus valores). Incluyen consulta del chat, pareja de usuarios,
participantes y comprobación de pertenencia. Hay SELECT FOR UPDATE incluso en la
lectura. El origen se ve en las llamadas consecutivas a state() e history(), ambas
a través de MessagingAccess::conversation(). No es un N+1 creciente con filas.

**Archivos:** `app/Http/Controllers/Messaging/InboxController.php:32`,
`app/Services/Messaging/MessagingQuery.php:23` y `:82`,
`app/Services/Messaging/MessagingAccess.php:32` y `:43`.

**Propuesta:** estudiar un único contexto transaccional de acceso para construir
estado e historial, preservando lecturas actuales y orden de locks. **Impacto
esperado:** eliminar parte de seis repeticiones evitables por petición; seis es un
techo de sentencias repetidas observado, no una reducción garantizada. La espera
por contención y capacidad concurrente NO se midieron: son riesgos probables, no
un cuello de concurrencia demostrado. **Riesgo alto:** deadlocks, autoría, bloqueos,
privacidad y contenido redactado. No quitar locks indiscriminadamente ni cachear
el acceso. Se solapa con el coste del punto 3: no sumar ambos ahorros como independientes.

## Lecturas descartadas y pendientes

- Profesionales usa eager loading y paginación; sus 13 consultas no crecen en estas
  muestras. Búsqueda LIKE con comodín inicial y filtros geográficos son hipótesis
  para otro escenario, no entran en los cinco problemas sin EXPLAIN/medición de búsqueda.
- Perfil mantiene 12 consultas y editor 10. Se observaron dos evaluaciones de
  permisos directos/heredados en Perfil; su coste medido no supera los cinco
  anteriores. No se propone cachear autorización entre usuarios para ahorrar consultas.
- Turbo conserva módulos ES ya evaluados y assets de igual URL; no se afirma que
  descargue de nuevo todo el JS en cada visita. `turbo-prefetch=false` y no-cache de
  snapshots son decisiones actuales de privacidad; no se recomienda retirarlas.
- El polling respeta foco, visibilidad, posición del historial, pausas y abortos;
  no se encontró una razón para multiplicar temporizadores o acortar el intervalo.
- Quedan pendientes trazas reales de navegador frío/caliente y Turbo, compresión
  HTTP efectiva, fuentes/imágenes, CPU, LCP/INP, dispositivos móviles, búsquedas y
  usuarios con adjuntos/permisos distintos. No se inventan esas mediciones.

## Orden de optimización y criterios de aceptación

1. Base CSS única, comparando bytes y regresiones visuales.
2. JS por página, midiendo grafo, requests y trabajo del hilo principal con Turbo.
3. Poll sin reconstrucción innecesaria, preservando seguridad P1 y reintento manual.
4. Feed de Comunidad, a partir de perfil de renderizado y una prueba de tamaño de página.
5. Contexto de acceso del chat, solo tras revisar garantías transaccionales y privacidad.

Cada cambio debe medirse de forma aislada contra estas condiciones, sin objetivos
porcentuales inventados. Mantener autorización, CSRF, límites de página, retención
privada y ausencia de reenvíos automáticos. Este informe no implementa ninguno.

## Conservación y evidencias

Los scripts y métricas de diagnóstico están fuera del repositorio, en
`performance09pa-1795151519` bajo el directorio de visualizaciones de esta sesión.
Solo incluyen datos sintéticos y consultas sin bindings; la comprobación adicional
guarda hashes de parámetros sintéticos. No se exportan filas de desarrollo ni
sesiones, cuentas o documentos reales. No se accede al worktree de diseño.
No hubo build, instalaciones, suite completa, staging, commit o push. El único
archivo nuevo del proyecto es este informe. Las huellas de los archivos versionados
se contrastan al terminar; los fixtures de testing se revierten, con la salvedad
normal de contadores MySQL descrita al principio. No se afirma inmutabilidad global
de las bases ni se atribuye actividad concurrente ajena.

## Resultados de 09P-B.1 — Base CSS única (2026-11-19)

Esta sección registra una intervención posterior; las métricas y conclusiones
originales de 09P-A se conservan arriba. Se confirmó `main` en
`ca1967b8ce1ccafc5f6ccb1dab43fb484c162d37`, con staging vacío. Al comenzar,
el único archivo sin seguimiento era este informe. No se accedió al worktree
de diseño ni se consultó MySQL en esta intervención.

### Causa y cambio mínimo

`resources/views/layouts/app.blade.php:24-25` carga `app.css` y, para la
navegación autenticada, `components/userHeader.css`. Esta última importaba
Tailwind y además `../app.css`, volviendo a emitir la base, tokens, utilidades
y estilos compartidos dentro de su propia salida de Vite.

En `resources/css/components/userHeader.css:1` se sustituyeron ambos imports
por `@reference "../app.css"`. Tailwind puede resolver los tokens, variantes
y `@apply` de la cabecera sin volver a emitir la hoja referenciada. Se conserva
íntegro el resto de las reglas de cabecera. No se cambian `app.css`, el layout,
`vite.config.js`, JavaScript, PHP, dependencias ni configuración.

Inventario de esta intervención: un archivo fuente modificado,
`resources/css/components/userHeader.css`, y esta sección añadida al informe
`docs/performance-audit-09p-a.md`, que continúa sin seguimiento y sin staging.
El build regenera únicamente artefactos ignorados bajo `public/build`.

### Comparación antes/después

Se comparó el build existente usado en 09P-A, copiado fuera del repositorio antes
de editar, con `npm run build` de Vite 6.4.4 (103 módulos). Tamaños en bytes;
gzip se calcula por archivo con Python, `gzip.compress`, nivel 9, `mtime=0`,
zlib 1.3.1, igual para ambas muestras. No equivale necesariamente a la compresión
configurada en el servidor; el estimador gzip de Vite/Node puede dar otras cifras.

| CSS | Antes | Después | Antes gzip 9 | Después gzip 9 |
| --- | ---: | ---: | ---: | ---: |
| `app.css` | 98,077 | 98,077 | 18,439 | 18,439 |
| `userHeader.css` | 103,350 | 5,938 | 19,237 | 1,779 |
| Par de hojas autenticadas | 201,427 | 104,015 | 37,676 | 20,218 |

La diferencia del par es **97,412 bytes sin comprimir y 17,458 bytes gzip 9**.
Es una reducción de los recursos de ese par en una carga sin caché, no un ahorro
garantizado de toda la página: permanecen otras hojas por módulo, caché HTTP,
compresión y navegación Turbo. Las páginas públicas solo cargan `app.css` y
no reducen sus bytes con este cambio. No se midió una mejora de latencia,
LCP ni tiempo de renderizado y no se atribuye una cifra a esas métricas.

El grafo mantiene dos entradas CSS independientes y el mismo orden de carga:
`app.css` aporta la base; `userHeader.css` aporta sus reglas locales. El manifiesto
cambia únicamente la entrada de cabecera, de `assets/userHeader-DlZ2TxDk.css`
a `assets/userHeader-9prKqyeB.css`. Todas las demás entradas y sus archivos
conservan exactamente sus bytes, incluido `assets/app-qQnc0pYe.css`.
No se agregan solicitudes ni se elimina todavía la solicitud propia de cabecera.

La inspección con PostCSS encuentra 1,108 reglas en `app.css`, sin variaciones;
la salida de cabecera pasa de 1,149 a 43. Ya no contiene capas `theme`, `base`,
`components` ni `utilities`; conserva inicialización de propiedades de Tailwind
y sus reglas locales. No quedan directivas `@import`, `@reference` o `@apply`
sin resolver en ambas salidas. Las reglas compartidas, incluida la navegación
móvil, permanecen en `app.css`. Algunos `@apply` incorporan valores de respaldo
en `var()` al compilar mediante referencia; no se exige igualdad textual entre
esas declaraciones, sino conservación de su resultado con los tokens de la base.

### Validaciones y límites

- Build Vite aprobado.
- 13/13 pruebas relacionadas aprobadas mediante
  `node --test tests/frontend/account-menu.test.mjs tests/frontend/public-navigation.test.mjs tests/frontend/navigation-lifecycle.test.mjs`.
  Cubren el menú nativo, teclado, cierre, navegación y ciclo de vida Turbo/BFCache.
- QA estático seguro en navegador: cabecera pública y autenticada, anchos de
  390 y 1,440 px, temas claro y oscuro, ocho comparaciones antes/después.
  Se compararon geometría y 12 propiedades calculadas de cada elemento de
  cabecera/navegación móvil: 104 elementos autenticados y 27 públicos por caso.
  Ninguna comparación presentó diferencias. Incluye los estados seleccionados
  de Mensajes y Ayuda y los menús desplegados donde son visibles.
- Apertura con Enter, foco visible y cierre con Escape del menú autenticado
  comprobados; sin desbordamiento horizontal a 390 px. Captura móvil oscura
  guardada fuera del repositorio.
- `git diff --check` aprobado; se verifica también el whitespace del informe
  sin seguimiento. No se agregan pruebas permanentes para este cambio de imports.

El QA usa snapshots HTML previamente renderizados con cuentas sintéticas,
servidos con los assets antes/después mediante un servidor estático temporal
en `127.0.0.1:8123`, sin Laravel, MySQL, sesiones o fixtures nuevos. POST se
rechaza y no se siguen enlaces hacia el backend. Para comparar ambos temas se
adapta únicamente la respuesta CSS del servidor temporal, sin editar archivos
del proyecto. No se incorpora la fuente remota de Google en este montaje;
la comparación usa la misma fuente disponible en ambos builds. No sustituye
pruebas de autenticación, autorización, páginas completas ni una medición real
en dispositivos. El servidor y la pestaña se cierran al terminar.

No se ejecuta la suite Laravel: no cambiaron contratos PHP y no apareció una
regresión que la justificara. No hubo conexión a MySQL de desarrollo ni escrituras
de datos funcionales, migraciones, seeders o modificaciones de adjuntos.

### Integración futura con Antigravity

`resources/css/components/userHeader.css` pertenece al área visual de cabecera y
puede solaparse con el trabajo de Antigravity en `diseno/ajustes-ui`. No se
inspeccionó `D:\projects\plataforma-legal-V2-diseno`, por lo que no se afirma que
ese archivo haya sido modificado allí ni que exista un conflicto confirmado.
Al integrar diseño se debe conservar la referencia a la base y combinar las
reglas visuales sin reintroducir los imports duplicados. La superficie de esta
optimización se limita a la primera línea de esa hoja; `app.css`, layout y Vite
permanecen intactos. No se fusiona ni modifica el otro worktree.

Evidencias de 09P-B.1 fuera del repositorio: `css09pb1-1795152095` en el mismo
directorio de visualizaciones de la sesión; contiene copia del build anterior,
tamaños, análisis de manifiesto/reglas y resultados de QA. No hubo staging,
commit ni push. No se inicia 09P-B.2 ni se modifican los otros cuellos de botella.

## Resultados de 09P-B.2 — JavaScript por pantalla (2026-11-19)

Intervención posterior sobre `main`, HEAD inicial
`581e43e09d0c50b18c82b1492eaf81c684ea06f0`, árbol limpio y staging vacío.
Se conservan íntegros los apartados y métricas de 09P-A y 09P-B.1. No se accede
al worktree de Antigravity, Laravel ni MySQL durante esta intervención.

### Arquitectura y alcance

`app.js` conserva estáticos `bootstrap`/Axios, Turbo, Alpine, navegación pública,
menú de cuenta, diálogos compartidos, resumen de validación y recuperación de
contraseña. Esta última debe limpiar fragmentos sensibles incluso después de
una redirección a una pantalla distinta del formulario de recuperación.

Ocho imports de módulos de pantalla pasan a funciones `import()` literales,
resueltas por Vite. `loadOnPage` comprueba marcadores del DOM al comenzar y en
cada `turbo:load`; no decide por texto de URL ni por una lista de rutas backend.

| Marcador presente | Módulo solicitado |
| --- | --- |
| `data-home` | `home.js` |
| `data-community` | `community.js` |
| `data-messaging` | `messages.js` |
| `data-lexa` | `lexa.js`, sin cambios funcionales |
| `data-professional-directory` | `professionals.js` |
| `data-profile-location` o `data-help` | `personal.js` |
| `data-save-form` | `saved/library.js` |
| `data-editorial-form` | `editorial.js` |

Se mantiene la granularidad existente de `personal.js`, que agrupa editor y Ayuda;
no se reorganizan sus funciones. El perfil de lectura no necesita ese módulo.
Guardados se carga también en Inicio/Comunidad cuando hay formularios reales de
guardado. Una página vacía o sin esos formularios no solicita el módulo.

`ui/page.js` mantiene el contrato síncrono `onPage(selector, setup)` de los módulos:

- Comparte una fase de ciclo de vida por documento. Durante `turbo:before-render`
  o después de `pagehide` no monta registros que lleguen tarde. Al activar el DOM
  actual monta una sola vez por raíz y aborta los listeners del controlador anterior.
- Coalesce el import pendiente de cada módulo y conserva sus registros tras
  evaluarlo. Los ES modules reutilizan el mismo URL en visitas posteriores.
- Impide duplicar registros con el mismo selector y función, necesario porque
  las entradas `public.js` y `app.js` pueden registrar la navegación pública al
  cruzar layouts. Distintas funciones sobre una misma raíz siguen siendo válidas.
- Los errores de limpieza/montaje se aíslan entre controladores para no dejar
  activos los listeners de otras pantallas. Los imports fallidos se notifican
  en consola y liberan el pendiente para otra visita; no se repiten escrituras.
- BFCache conserva controladores ya montados sin reinstalarlos. Si se congela
  el documento con un import pendiente, la restauración recarga desde el servidor:
  ese módulo todavía puede no haber instalado sus salvaguardas de privacidad.
  No se interpreta ese caso como una autorización nueva para mostrar contenido.

`messages.js`, su polling, intentos idempotentes, purga de contenido y las reglas
de lectura/autorización permanecen sin editar. Su manejador existente de BFCache
sigue recargando los chats ya inicializados. No se cambian contratos HTTP ni PHP.

Se retiraron de `vite.config.js` las cinco entradas JS de pantalla que ahora son
destinos de imports dinámicos. Ninguna vista las declara directamente con `@vite`;
mantener ambos papeles producía salidas redundantes. Siguen presentes en el
manifiesto como `isDynamicEntry`; CSS, `app.js` y `public.js` conservan sus entradas.
No se añade `manualChunks`, otra librería, un router ni un sistema de eventos nuevo.

### Métricas antes/después

Referencia: copia externa del build anterior, inmediatamente antes de editar.
Resultado: build Vite 6.4.4, 99 módulos transformados. Bytes exactos de JS; gzip
por archivo con Python/zlib 1.3.1, nivel 9 y `mtime=0`, igual que las mediciones
anteriores. No se suman dependencias compartidas dos veces ni se incluyen CSS,
HTML, fuentes o metadatos de transferencia HTTP.

En las seis variantes, antes se declaraban **246,485 bytes / 80,053 gzip**, nueve
URLs JS únicas (entrada más ocho dependencias). Ahora el grafo estático inicial
común tiene **213,984 bytes / 68,608 gzip**, dos URLs (entrada más una dependencia).
Son **32,501 bytes / 11,445 gzip menos en ese grafo estático**, no todo el ahorro
de la visita: el módulo de la pantalla se solicita inmediatamente después.

| Pantalla | JS diferido adicional | Gzip diferido | Total JS tras cargar su mejora | Total gzip | URLs JS antes → después |
| --- | ---: | ---: | ---: | ---: | ---: |
| Inicio con Guardados | 3,964 | 2,236 | 217,948 | 70,844 | 9 → 6 |
| Comunidad con Guardados | 5,720 | 3,128 | 219,704 | 71,736 | 9 → 7 |
| Profesionales | 408 | 272 | 214,392 | 68,880 | 9 → 3 |
| Perfil propio de lectura | 0 | 0 | 213,984 | 68,608 | 9 → 2 |
| Editor de Perfil | 7,476 | 3,198 | 221,460 | 71,806 | 9 → 4 |
| Mensajes | 14,211 | 5,690 | 228,195 | 74,298 | 9 → 5 |

Todos los totales posteriores incluyen el núcleo inicial; las columnas diferidas
son incrementos sobre ese núcleo, no tamaños adicionales que deban sumarse otra
vez al total. Si Inicio o Comunidad no tienen guardados, su grafo es menor.
Son variantes por marcadores de vistas actuales, no una nueva medición SQL ni de
HTML poblado. Una visita posterior a otra pantalla añade únicamente sus chunks
todavía no evaluados/descargados; una sesión que recorra muchas pantallas terminará
cargando más código que una sola fila. Las URLs no equivalen a requests nuevas
con caché caliente. Gzip real depende de la compresión del servidor.

El manifiesto pasa de ocho dependencias estáticas de `app` a una compartida y
ocho destinos dinámicos. En HTML se requieren un script y un modulepreload inicial,
frente al script y ocho dependencias anteriores. Vite prepara las dependencias de
un import dinámico cuando se ejecuta ese import; no precarga todas las pantallas
por aparecer sus URLs en el mapa del helper. Algunos chunks reutilizan exports
de `app` (navegación/diálogo); la misma URL se reutiliza mediante el registro ESM.

En Inicio ya no se solicita Mensajes, LEXA, directorio, editor/Ayuda ni editorial.
Comunidad carga su módulo y Guardados; directorio carga solo su módulo; perfil de
lectura usa el núcleo; editor carga `personal`/store; Mensajes carga su controlador,
fechas y validación de adjuntos, sin LEXA ni los otros controladores de pantalla.
El código pequeño de validación de archivos sigue compartido con Comunidad/LEXA;
eso no implica descargar los controladores de esas pantallas.

Las hojas CSS compiladas son idénticas a las del build anterior, conservando
09P-B.1. No se afirma mejora de LCP, INP, CPU ni latencia: esas métricas no se midieron.

### Validaciones

- `node --test tests/frontend/*.test.mjs`: **118/118 aprobadas**.
- Focalizadas de carga y recuperación real de Mensajes: **34/34 aprobadas**.
  Incluyen las cuatro regresiones P1 401/403/404/419, sin cambios en sus casos.
- Ocho pruebas nuevas de carga/ciclo de vida: selección por marcador, import
  compartido durante cargas concurrentes, retorno Turbo, finalización tardía
  durante render/otra pantalla, BFCache listo/pendiente, fallo de import,
  aislamiento de limpieza y registros públicos compartidos.
- Dos regresiones adicionales usan `setupMessages` real con import diferido:
  no inicia polling al terminar en una pantalla ajena, monta un único timer al
  retornar, aborta lecturas al salir, descarta respuestas tardías y no inicia
  polling al restaurar un documento congelado antes de completar la carga.
- Build Vite aprobado, sin entradas JS de pantalla duplicadas; manifiesto y
  cierres transitivos de imports revisados. `git diff --check` aprobado.
- QA estático con JS compilado: Inicio → Comunidad → Profesionales → Perfil →
  Mensajes, arranques independientes, retornos Turbo y Atrás/Adelante del editor.
  Sugerencia de Inicio una sola vez, reset del municipio del directorio, diálogos
  y restauración de scroll, Alpine tras cambiar el body y montaje de Mensajes
  comprobados. Sin errores JS observados durante ese recorrido.

El QA se realizó con marcado sintético mínimo y un snapshot anterior de chat
sintético, servidor estático local en `127.0.0.1:8124`, assets del build y POST
rechazado. No ejecuta Laravel ni se conecta con bases de datos, sesiones, caché
de aplicación, archivos privados o cuentas reales. Los logs confirman las mismas
familias y cantidades de chunks de las cinco filas de lectura; en el recorrido
Turbo cada chunk se solicitó una vez y no se solicitó LEXA/editorial. La revisión
posterior añadió aislamiento de excepciones y deduplicación compartida, cubiertos
por las pruebas finales; los hashes finales y tamaños son los de la tabla.
Se cierra servidor y pestaña, dejando las evidencias fuera del repositorio.

El historial probado en navegador fue Turbo; no se acredita una entrada real en
BFCache nativo. Sus eventos, carrera con imports y protección de Mensajes se
verifican con pruebas deterministas. Este QA no sustituye autenticación real,
APIs, publicaciones/envíos, adjuntos, catálogos HTTP ni una sesión completa con
usuarios de distintos permisos. No se ejecutó Laravel completo ni fixtures DB.

### Riesgos y límites de la solución incremental

La mejora JS aparece al completar una segunda fase de red; con red lenta puede
haber un intervalo con el comportamiento HTML nativo antes del montaje. No se
bloquean formularios ni se reproducen eventos o envíos para compensarlo. Un error
de carga conserva ese comportamiento nativo y se registra; la recuperación de
errores de evaluación cacheados puede requerir recarga manual. No se recarga una
página activa automáticamente ante un error de chunk, para no perder borradores.

El despliegue debe publicar manifiesto y assets de forma coherente y conservar
chunks antiguos durante sesiones abiertas; eliminar un asset referenciado por un
documento anterior puede causar un import fallido. El caso BFCache con import
pendiente fuerza recarga, una decisión conservadora que puede descartar estado
transitorio al restaurar; las páginas ya montadas conservan su contrato previo.
El núcleo sigue incluyendo Axios, Turbo, Alpine y los diálogos compartidos: no se
han retirado dependencias ni optimizado esos componentes. La entrada pública
comparte ahora un registro de ciclo de vida mayor; no se promete reducir la landing.

### Inventario y conservación

Se modifican exactamente cinco archivos versionados y se añade una prueba:

1. `resources/js/app.js`: selección de imports dinámicos.
2. `resources/js/ui/page.js`: registro compartido de ciclo de vida y cargador.
3. `vite.config.js`: retirar entradas JS que ahora administra el cargador.
4. `tests/frontend/messages-recovery.test.mjs`: dos regresiones de montaje diferido.
5. `docs/performance-audit-09p-a.md`: únicamente esta sección añadida.
6. Nuevo `tests/frontend/page-loading.test.mjs`: ocho regresiones del cargador.

Las huellas iniciales de los demás archivos versionados se contrastan al cerrar,
incluidos `messages.js`, polling, LEXA, vistas y backend. No se modifican permisos,
migraciones, configuración de entorno, dependencias, datos ni adjuntos privados.
Evidencias externas en `js09pb2-1795152770` bajo las visualizaciones de esta sesión:
build anterior, métricas por cierre de imports, logs de requests y captura de QA.
Sin staging, commit o push; no se inicia 09P-B.3 ni otra optimización.

## Diseño 09P-B.3-A — Polling seguro sin cambios (2026-11-19)

Análisis focalizado sobre `main`, HEAD
`60b82e3c79ce740998c6192618db12c73c57686c`, con árbol limpio y staging vacío
al comenzar. Esta sección es una propuesta: no implementa ninguna optimización.
Se inspeccionaron controlador, consultas, acceso, cliente/poller y pruebas de
Mensajes. Las lecturas auxiliares se limitaron a sus vistas, Request, política,
mutaciones y middleware de sesión, necesarios para identificar invalidaciones.
No se ejecutaron pruebas, HTTP, builds, consultas MySQL, migraciones ni seeders.

### 1. Funcionamiento actual y fronteras de seguridad

- `InboxController.php:24-55`: cada GET de `updates` construye la bandeja mediante
  una transacción, libera sus locks y construye estado/historial en otra.
  `state()` e `history()` vuelven a recorrer el acceso del chat. El poll ignora
  los cursores de página y consulta las ventanas recientes; conserva los filtros
  de archivado y participante. No prepara un nuevo compositor en cada poll.
  Siempre renderiza tres parciales Blade y devuelve `conversation_id`,
  `can_send`, `history_html`, `inbox_html` e `information_html` con
  `Cache-Control: private, no-store`.
- `MessagingQuery.php:22-100,127-160`: los DTO incluyen lectura/ocultamiento por
  participante, versiones, texto redactado, adjuntos elegibles y alias públicos.
  La bandeja incluye otras conversaciones, orden, extractos y no leídos.
  `can_send=false` puede proceder de cualquiera de los bloqueos o de una cuenta
  destinataria inactiva; no significa que se haya revocado la lectura.
- `MessagingAccess.php:19-80`: recarga cuentas, comprueba pertenencia inicial y
  nuevamente bajo locks, exige exactamente los dos participantes y aplica Gate.
  `EnsureActiveAccount` revalida cuenta y sello de credenciales de sesión; las
  rutas mantienen auth, throttle y bloqueo de sesión. Una decisión de igualdad
  nunca debe ejecutarse en lugar de estos controles ni evitar sus errores.
- `messages.js:126-160`: una lectura por controlador; tras recibir cabeceras
  descarta respuestas de un controlador revocado/dispuesto/abortado. Dentro del
  contexto vivo, 401/403/404/419 tienen prioridad sobre una generación de lectura
  pausada: las cuatro P1 impiden ocultar una revocación detrás de un POST incierto.
  Después valida generación, HTTP y payload; sustituye cada fragmento si su
  foco/details lo permiten, localiza fechas y actualiza recuperación/capacidad.
- `messages.js:162-177` y `messages/polling.js:1-23`: intervalo normal de diez
  segundos después del tick, sin solapamientos. El automático solo consulta con
  documento visible/en foco, historial reciente al final, sin foco/details dentro
  del historial. El reintento manual y la actualización tras un envío usan también
  `update()`. Los errores temporales pausan; un éxito actual puede reanudar.
  Turbo desmonta/aborta y BFCache retira el chat privado y recarga al restaurar.

Las pruebas existentes separan estos contratos: `MessagingIntegrationTest`
comprueba que leer/pollear no marca como leído, bloqueos mantienen historial,
actor inactivo no puede pollear, cursores/filtros privados y consultas acotadas;
`MessagingTest`, `MessagingConcurrencyTest` y `MessagingMediaIntegrationTest`
cubren versiones, locks, idempotencia, ocultamientos y descargas privadas.
`messages-recovery.test.mjs` cubre las cuatro P1, errores definitivos/temporales,
respuesta tardía, cambio de contexto/identidad, envío incierto y montaje diferido;
`messages-transport.test.mjs` cubre intentos y temporizadores. Se inspeccionaron,
pero no se ejecutaron en esta fase de diseño; no hay un nuevo resultado de suite.

### 2. Alternativas

| Aspecto | A: comparar HTML en cliente | B: respuesta mínima negociada |
| --- | --- | --- |
| HTTP | Conserva endpoint y JSON completos | Añade modo opt-in; clientes anteriores reciben JSON completo |
| Beneficio esperado | Evita parsear/reemplazar fragmentos idénticos ya aplicados | Evita además render Blade, serialización de HTML y transferencia cuando hay igualdad |
| SQL y locks | Sin reducción | Inicialmente sin reducción: se reconstruyen los DTO autorizados antes de comparar |
| Autorización | Misma lectura completa en cada petición | Misma lectura completa; ninguna salida temprana anterior al acceso |
| Invalidación | Igualdad exacta del HTML completo, por fragmento | Huella de todos los insumos de representación y contexto; mucho más delicada |
| Cliente | Tres referencias de HTML en memoria por controlador | Además negociación, contexto, revisión y ramas full/unchanged |
| Riesgo principal | Confundir recibido con aplicado, o comparar contra DOM localizado | Omitir una dependencia, contestar sin revalidar o adelantar una revisión con DOM pendiente |
| Complejidad | Baja y verificable de forma aislada | Media; exige pruebas backend y de compatibilidad antes de activarse |

**Elegida: A como primera implementación, seguida de evaluación de B.** A produce
un beneficio comprobable en parseo y sustituciones, con cero cambios de contrato
HTTP o autorización. No cumple por sí sola el objetivo de evitar renderizar en
servidor: Blade, bytes de respuesta y SQL seguirán igual. B queda como segunda
etapa condicionada a medir el beneficio y completar su cobertura de invalidación;
no se presenta A como una solución de ahorro de red/SQL ni se autoriza B aquí.
No se propone tiempo real, tablas de revisiones, caché compartida ni modificar locks.

### 3. Contrato propuesto para A

1. Mantener todos los GET, credenciales, `redirect: error`, errores y validación
   actuales. El comparador queda después de los guards de acceso, generación y
   payload. El `conversation_id` ya enviado puede verificarse contra el contexto
   capturado del poll antes de usar la representación; una discordancia es una
   respuesta inválida, sin aplicarla ni repintar otra conversación.
2. Guardar, dentro de la closure de `setupMessages`, el último HTML **aplicado**
   para cada uno de los tres contenedores y su identidad de nodo/contexto.
   No usar un singleton, sesión JS compartida, URL, storage, service worker o logs.
   No deducir autorización ni identidad de la coincidencia de esas cadenas.
3. Inicializar los tres valores como desconocidos. El primer poll apto aplicará
   su HTML, aunque el SSR parezca igual. No comparar contra `innerHTML`: el
   navegador normaliza markup y `localizeMessageTimes` ya transforma texto/title.
   Comparar cadenas originales recibidas, sin normalizar, truncar ni quitar CSRF.
4. Por fragmento: si coincide exactamente con el último aplicado al mismo nodo,
   no crear template ni llamar a `replaceChildren`; si difiere, respetar los
   guards actuales de foco/details y aplicar solo donde esté permitido. Avanzar
   su referencia únicamente después de aplicar con éxito. Si se omite por foco,
   no marcarlo como aplicado: el siguiente poll idéntico debe poder actualizarlo.
5. No hacer un `return` global por igualdad. Validar y procesar siempre
   `can_send`, `controls()`, recuperación y reanudación de una lectura manual
   válida. Un chat bloqueado sigue leyendo y un éxito antiguo no reanuda una
   generación pausada por incertidumbre de envío. No tocar `attempt`/tokens/POST.
6. Conservar el scroll al omitir historial y el comportamiento actual al aplicar
   un historial distinto. Mantener inicialmente la localización de fechas tal
   como funciona: todavía puede escribir nodos `time` incluso con fragmentos
   iguales. No prometer cero mutaciones DOM ni ampliar el alcance a ese formateo.
7. Soltar las tres referencias al revocar, desmontar, cambiar contexto y ocultar
   mediante BFCache; una raíz nueva empieza sin referencias. Una pausa temporal
   puede conservar las de su contexto vivo para compararlas solo después de otra
   respuesta completa y autorizada. Las cadenas no son persistencia ni cachean
   decisiones de acceso; soltar referencias no garantiza borrado físico de memoria
   en JavaScript. Si un contenedor se reconstruye por otra vía, invalidar su valor.

Los guards de foco son una decisión de presentación, nunca autorización para
acciones: los endpoints de edición, bloqueo, borrado y descarga continúan
revalidando. No ampliar aquí los aplazamientos ni relajar la purga definitiva.

### Contrato incremental de B, si se autoriza posteriormente

Mismo GET y mismo camino de acceso/DTO, con negociación explícita por cabeceras
(por ejemplo `X-Messaging-Poll-Version: 1`, contexto y revisión conocidos).
No añadir esos parámetros arbitrariamente al query: `MessagingRequest::after()`
rechaza campos que no están permitidos. No incluir texto, identidad legible,
CSRF o secretos en URL. No usar ETag/304 ni caché HTTP para este protocolo.

- Sin opt-in, mantener exactamente las cinco claves actuales y HTML completo.
  Con opt-in, responder 200 JSON `mode: full`, contexto/revisión opacos y todas
  las claves actuales cuando no hay revisión válida o cambió la representación.
- Solo después de acceso autorizado y DTO actuales, una revisión coincidente
  permite 200 JSON `mode: unchanged`, mismo contexto/revisión, `conversation_id`
  y `can_send`, sin los tres HTML. Mantener `private, no-store` en todos los modos
  y errores. Evitar 204: obligaría a otra interpretación sin cuerpo/estado explícito.
- La revisión se calcularía antes de Blade, sobre una serialización canónica de
  todos sus insumos: DTO completos, actor/contexto de sesión, filtros, metadatos
  de paginación/URLs, CSRF de formularios, locale/config relevante y versión de
  plantillas/despliegue. Usar una huella opaca autenticada, no IDs/timestamps como
  sustituto ni un hash público de texto privado. Un hash calculado después de
  renderizar ahorra red, pero no Blade; no se contabilizaría como el beneficio B.
- Contexto y revisión son pistas de representación, jamás credenciales. El
  contexto debe vincular actor efectivo, sesión, conversación y filtros y
  arrancar desde el render autenticado, incluso antes del primer poll. Un cambio
  de identidad/sesión no puede validar una revisión previa, aunque ambos usuarios
  participen en el mismo chat. Un contexto retirado del cliente opt-in debe
  conducir a recuperación/purga compatible con sesión no vigente (419 genérico),
  tras los controles normales; una conversación ajena/inexistente conserva 404.
  El cliente antiguo no se obliga a enviar estos nuevos datos.
- El cliente acepta `unchanged` solo para el contexto vivo, una revisión que
  realmente tiene aplicada y una generación actual. Si algún fragmento quedó
  aplazado por foco, no adelantar la revisión global: debe seguir recibiendo full
  hasta aplicar todos. Una respuesta mínima inesperada sin base es error de
  protocolo/pausa, no permiso para reutilizar datos ajenos ni un éxito vacío.
- Sesión/autorización y los DTO se leen cada vez. No responder por un token
  recibido antes de entrar a `MessagingAccess`, ni almacenar resultados de Gate.
  Conservar las dos transacciones y sus lecturas actuales; esta fase no pretende
  un snapshot atómico entre toda la bandeja y el chat ni reducir recorridos SQL.

### 4. Invalidaciones que no puede omitir la solución

| Cambio | Representación/estado afectado |
| --- | --- |
| Envío nuevo, incluso en otro chat de la bandeja | Ventana reciente, extracto, orden, no leídos y paginación; envío puede volver a mostrar una conversación oculta |
| Edición o eliminación de un mensaje anterior | Texto, versión de formularios, lápida, adjuntos, extracto e información, aunque el último ID no cambie |
| Ocultar un mensaje o el historial para un participante | Filas visibles, límites de ocultamiento, extracto/no leídos, cursores y archivos; no trasladar ese resultado al otro participante |
| Confirmación explícita de lectura de cualquiera de los dos | No leídos propios, botón de confirmación y marcas Leído/Guardado, incluyendo ocultamientos de la otra persona |
| Archivar/desarchivar o cambiar filtros | Pertenencia/orden de bandeja, links y botones; una preferencia propia no modifica la ajena |
| Bloquear/desbloquear cualquiera de los dos | `can_send`, enlaces Editar y controles; `blocked_by_me` cambia la acción propia; retirar solo uno de dos bloqueos no habilita envío |
| Perfil/alias/privacidad o estado del destinatario | Nombre público o Usuario, username, enlace de perfil, bandeja/información y capacidad de envío; historial puede seguir autorizado |
| Adjunto deja de ser elegible, se retiene o cambia su metadato | Listas de archivos/metadatos visibles en los DTO; descarga sigue revalidando por separado, incluida corrupción o ausencia física |
| Cuenta propia inactiva, credenciales/sesión retiradas, membresía inválida | Revalidación y error definitivo; nunca unchanged ni conservación por igualdad |
| Nuevo contexto, cursores, idioma/config, CSRF o versión de vistas | Descartar base/revisión o forzar representación completa según corresponda |

`activity_at` se actualiza al enviar (`MessageService.php:70`), pero editar,
borrar, ocultar, leer, bloquear o cambiar perfiles no dependen de ese reloj.
Tampoco basta `MAX(message.id)`, `unread_count` o `can_send` aislados. B debe
probar cada dependencia antes de sustituir una respuesta completa por mínima.

### 5. Riesgos de privacidad y recuperación

401/403/404/419 del contexto vivo revocan con prioridad aun si el GET es de una
generación anterior a un envío incierto. Una respuesta de controlador dispuesto
por Turbo/cambio de identidad se ignora, sin purgar el DOM nuevo. 409/429/503,
fallo de red, JSON inválido o error de protocolo mantienen la pausa y recuperación
manual actuales; ninguna coincidencia reanuda un envío ni rota un token idempotente.
No confundir igualdad con entrega/lectura ni marcar mensajes como leídos por poll.

Toda revisión, cadena o base es local al controlador de ese usuario/chat. No
registrar payloads, huellas de texto privado o tokens en logs/analítica; no usar
localStorage/sessionStorage. B podría revelar existencia/cambios de un chat si
compara antes de autorizar: evitarlo manteniendo los mismos errores genéricos y
sin devolver contexto/revisión al ajeno. Ni A ni B deben reutilizar decisiones de
autorización, abrir accesos a administradores ni cambiar retención/descargas.

### 6. Pruebas necesarias antes de implementar/publicar

Para A: pruebas del controlador JS real con respuestas sucesivas iguales y
distintas, contando parseos/reemplazos por contenedor; primera respuesta sin base;
cambio de solo un fragmento; igualdad con fechas localizadas; foco/details impiden
aplicar y la misma respuesta se aplica cuando se liberan; scroll y borrador
conservados; nodo reconstruido invalida referencia; cambio exclusivo de
`can_send`; recuperación manual con HTML igual; malformed/temporales sin reanudar.
Comprobar liberación de referencias y ausencia de repintado tras revoke/Turbo/
BFCache, identidad/chat nuevos y cargas dinámicas pendientes de 09P-B.2.

Reejecutar las cuatro P1 401/403/404/419 después de un envío incierto, con un
comparador preparado para saltar todos los fragmentos; respuestas definitivas
simultáneas POST/GET; éxito antiguo; error tardío de contexto retirado; mantener
`can_send=false` con GET autorizado y cero POST automáticos. Suite frontend
completa, build y whitespace después de implementar. A no requiere modificar PHP.

Para B, además: pruebas HTTP de cada invalidación de la tabla, actor suspendido/
sesión invalidada con revisión coincidente, ajeno e inexistente indistinguibles,
token/contexto de otro usuario/chat/filtro, entrada malformada, compatibilidad
legacy, CSRF/config/despliegue y revisión no adelantada por foco. Instrumentar
Blade para acreditar cero render de los tres parciales en unchanged, sin omitir
acceso/SQL. Concurrencia: editar/ocultar/bloquear durante lecturas, conservando
locks y contenido redactado. Todo fixture futuro exige dentro del proceso
`APP_ENV=testing`, MySQL efectivo `plataforma_legal_v2_test`, sesiones/caché y
almacenamiento aislados; nunca usar desarrollo ni documentos reales.

### 7. Medición futura y criterio de aceptación

No hay métricas posteriores en esta intervención. Referencia histórica 09P-A:
poll poblado de 24 conversaciones adicionales, 23 consultas, 48.97 ms de Kernel
(8.26 ms SQL) y 17,145 bytes JSON. No son TTFB/LCP ni una nueva medición de HEAD.

| Medida | A que habrá que medir | B que habrá que medir |
| --- | --- | --- |
| Consultas/locks y tiempo SQL | Sin reducción esperada; revalidación íntegra | Sin reducción esperada en este contrato inicial |
| Render/tiempo Kernel y CPU Blade | Misma renderización de tres parciales | Comparar full/unchanged y coste de calcular huella; cero render de parciales solo en unchanged |
| Bytes JSON sin comprimir/gzip y bytes de cabeceras | Sin reducción esperada | Medir cuerpo mínimo y cabeceras de negociación/revisión; no inventar un tamaño objetivo |
| Templates creados y `replaceChildren` por poll | Tras primera aplicación y con tres HTML iguales: objetivo cero de ambos; baseline instrumentado pendiente | Cero en unchanged; en full aprovechar el comparador de A |
| Otras mutaciones, CPU JS, layout y foco | Medir por separado, especialmente escritura de fechas; no afirmar cero DOM ni beneficio CPU sin traza | Medir validación del protocolo y UI conservada; sin deducir LCP/INP de bytes |
| Red/frecuencia/recuperación | Mismo intervalo y requests GET; cero reenvíos automáticos | Mismo intervalo; incluir coste del primer full y de revisiones invalidadas |

Usar fixtures equivalentes pequeños/poblados, polls estables y cada invalidación,
con/sin foco y con conversación en solo lectura. Separar primera respuesta,
estable, actualizada, error y restauración. Documentar muestras y dispersión;
comparar antes/después del cambio aislado, sin sumar ahorros de SQL del punto 5
de la auditoría. A se acepta con cero sustituciones/parseos redundantes en el caso
estable y garantías intactas; la mejora de CPU debe demostrarse y podría ser baja.
El objetivo de no renderizar en servidor sigue pendiente hasta aprobar y medir B.

### 8. Alcance probable de implementación y estado de esta intervención

Primera etapa A: `resources/js/messages.js` y
`tests/frontend/messages-recovery.test.mjs`; un helper/prueba focalizada solo si
reduce el tamaño de la lógica. Actualizar este informe con resultados reales.
No se prevén cambios en `polling.js`, `attempt.js`, servicios, permisos, rutas,
cadencia, archivos ni contrato HTTP. No cambiar `ui/page.js` salvo regresión
demostrada que requiera una autorización de alcance distinta.

Etapa B separada: `InboxController.php`, una representación/revisión junto a
`MessagingQuery.php` si hace falta, cliente y pruebas HTTP/frontend. El contexto
inicial podría requerir `pages/messages/index.blade.php`; cualquier validación de
cabeceras debe ser explícita. No rediseñar `MessagingAccess`, mutaciones ni
duplicación de consultas dentro de esta optimización. Ese trabajo requiere su
propia autorización y medición.

Único cambio realizado ahora: añadir esta sección a
`docs/performance-audit-09p-a.md`, conservando su contenido anterior. Sin cambios
en PHP, JS, pruebas, configuración, datos ni otros worktrees; staging vacío,
sin commit ni push. La implementación se detiene en este diseño.

## Resultados 09P-B.3-B — Reconstrucciones DOM selectivas (2026-11-20)

Se implementó únicamente A sobre `main`, HEAD inicial
`60b82e3c79ce740998c6192618db12c73c57686c`, con staging vacío. El único cambio
previo era este informe con el diseño 09P-B.3-A; su contenido se conserva junto
con las métricas históricas de 09P-A, 09P-B.1 y 09P-B.2. No se implementa la
respuesta mínima B ni se cambia contrato HTTP, PHP, SQL o autorización.

### Implementación y contexto

`resources/js/messages.js` mantiene, dentro de cada `setupMessages`, como máximo
tres registros de HTML aplicado: historial, bandeja e información. Cada registro
incluye la identidad del nodo destino y la cadena original recibida. La primera
lectura apta establece la base; no se compara con el SSR ni con `innerHTML`, que
puede haber sido normalizado o localizado por el navegador.

Después de validar acceso/vigencia y el payload completo, cada fragmento apto
compara nodo y cadena por igualdad exacta. Si coinciden, no crea un template ni
llama a `replaceChildren`. Si difieren, aplica y solo entonces actualiza su
registro. El foco/details conservan los aplazamientos anteriores: un fragmento
recibido pero no aplicado nunca adelanta la comparación. Un nodo de información
o bandeja reconstruido no hereda la comparación del nodo anterior.

Se captura la URL de poll de ese montaje, incluida su conversación/filtros. Antes
de iniciar una lectura y después de sus esperas se comprueba conexión de la raíz,
misma URL, compositor/historial originales y token CSRF local sin variaciones.
Se valida `conversation_id`, ya presente en el JSON vigente, contra la conversación
de esa URL. Una respuesta de otro chat o sin ese campo se trata como payload
inválido y pausa, sin parseos ni actualizaciones parciales. No se añade ningún
campo ni cabecera al transporte. La ruta utilizada sigue siendo la actual.

Un contexto DOM retirado se dispone, detiene y aborta sin repintar/purgar una
raíz que podría representar ya otro contexto. La comprobación compartida se
aplica también al resultado de POST para que una respuesta anterior no confirme
un intento ni revoque el contexto nuevo. No se cambian datos enviados, rotación
de tokens, idempotencia ni reglas de reintento; no se crean POST automáticos.

La igualdad no termina globalmente `update()`: se siguen procesando `can_send`,
controles, estado de recuperación y reanudación manual vigente. Por eso
`can_send=false` conserva lectura y un éxito idéntico puede recuperar una pausa
temporal sin reconstruir HTML. El scroll del historial idéntico permanece intacto;
cuando se reemplaza conserva el comportamiento de scroll previo.

401/403/404/419 del contexto vivo siguen revocando antes de descartar una generación
de lectura antigua. La igualdad nunca puede ocultar esa denegación. Un controlador
dispuesto o un contexto distinto no procesa resultados tardíos. En revocación,
desmontaje, retiro de contexto y BFCache se vacían los tres registros; BFCache
conserva la purga del contenido privado y recarga al volver. Las pausas temporales
conservan solo la representación ya aplicada de su propio montaje, y solo otra
respuesta completa/actual puede volver a utilizarla para comparar.

El servidor continúa revalidando cuenta/sesión, membresía y autorización en cada
lectura completa. Los guards locales detectan el contexto del controlador y el
DOM, no sustituyen esa identidad efectiva del servidor ni cachean decisiones de
Gate. Los flujos de login/logout/cambio de usuario y Turbo deben seguir creando
su nuevo montaje. No hay caché global, storage, service worker ni registro de
HTML, CSRF o contenidos privados en logs. Soltar referencias no es una garantía
de borrado físico inmediato de memoria JavaScript.

### Medición reproducida antes/después

Se ejecutó primero la nueva prueba de medición contra `messages.js` anterior a la
corrección: reprodujo tres parseos y tres reemplazos en las seis lecturas. Después
se contrastó con el controlador corregido. Los contadores de la prueba observan
asignaciones a `template.innerHTML` y llamadas a reemplazar los tres contenedores.

Además se midió DOM nativo en el navegador con el controlador real: versión
`git show HEAD:resources/js/messages.js` como antes y fuente actual como después,
mismos módulos auxiliares, mismo marcado mínimo, contenido sintético y respuestas
JSON completas equivalentes. Un servidor estático local en `127.0.0.1:8125` sirvió
las fuentes y el montaje; `fetch` dentro del fixture devolvió respuestas sintéticas
sin llamar al backend y rechazó escrituras. El runner esperó a terminar cada
lectura antes de la siguiente. No se utilizó Laravel, MySQL, sesión de aplicación
ni cuentas/documentos reales. Servidor y pestaña se cerraron al terminar.

En cada variante se realizaron estas seis lecturas secuenciales. La tabla muestra
`parseos / replaceChildren / registros childList` por lectura:

| Lectura | Antes | Después |
| --- | ---: | ---: |
| Primera aplicación H1/I1/F1 | 3 / 3 / 3 | 3 / 3 / 3 |
| Idéntica a la anterior | 3 / 3 / 3 | 0 / 0 / 0 |
| Solo cambia historial a H2 | 3 / 3 / 3 | 1 / 1 / 1 |
| Solo cambia bandeja a I2 | 3 / 3 / 3 | 1 / 1 / 1 |
| Solo cambia información a F2 | 3 / 3 / 3 | 1 / 1 / 1 |
| Idéntica a la anterior | 3 / 3 / 3 | 0 / 0 / 0 |
| Total del fixture | 18 / 18 / 18 | 6 / 6 / 6 |

`MutationObserver` observó únicamente `childList` directo de esos tres destinos.
En este fixture se evitaron **12 parseos, 12 reemplazos y 12 registros de mutación
estructural**, 66.7% de esas operaciones para esta secuencia concreta. No representa
un porcentaje de rendimiento de la aplicación ni del número total de mutaciones.
Los HTML son párrafos mínimos, no un historial poblado representativo de producción.
La comparación de cadenas y sus referencias también tienen coste/memoria.

Se mantiene `localizeMessageTimes(root)` y la actualización de controles/status,
que pueden seguir escribiendo DOM incluso si no cambian los tres HTML. El fixture
de conteo no contiene fechas en sus respuestas; esas escrituras, estilos, atributos
y cualquier mutación fuera de los tres destinos no están incluidas. No se midieron
CPU, layout, latencia, LCP o INP. No se promete reducir render Blade, consultas,
locks, número/frecuencia de GET ni bytes HTTP: el servidor sigue enviando el JSON
completo. Las cifras históricas SQL/Kernel/red del informe no se midieron de nuevo.

### Regresiones y validación

`tests/frontend/messages-recovery.test.mjs` conserva sus casos anteriores y añade
23 pruebas/casos, usando `setupMessages` real y transporte/DOM sintéticos:

- Conteos por lectura y actualización exclusiva del fragmento distinto.
- HTML localizado, scroll y borrador conservados con respuesta idéntica;
  `can_send` y recuperación manual siguen actualizándose sin reemplazo.
- Foco/details aplazan aplicación sin adelantar la referencia; al liberar esos
  controles se aplica la misma respuesta pendiente. Nodo destino nuevo sin base.
- ID de conversación ausente/distinto rechazado antes de aplicar; cambio de URL,
  token de sesión local, compositor, historial o conexión de raíz retira el contexto.
- Contexto revisado también después de leer JSON, y POST tardío sin confirmación
  o purga del contexto nuevo. Éxito antiguo no adelanta la base tras envío incierto.
- Nuevo controlador/chat comienza sin comparación anterior; Turbo/BFCache abortan
  y bloquean resultados tardíos con base ya aplicada.
- 409/429/503 no actualizan la base y permiten recuperación manual con HTML igual.
- Cuatro casos adicionales con base aplicada y envío incierto verifican que
  401/403/404/419 siguen purgando. Se conservan también las cuatro P1 originales.

Resultados finales:

- Suite frontend completa: **141/141 aprobadas**.
- Recuperación/polling del controlador: **49/49 aprobadas**, incluidas las P1.
- Build Vite aprobado; `git diff --check` aprobado.
- Medición DOM nativa completada sin errores JS observados.
- No se ejecutó Laravel completo ni se accedió a MySQL.

### Inventario, límites y cierre de esta intervención

Únicamente tres archivos del proyecto modificados:

1. `resources/js/messages.js`: comparador local, validación de contexto y limpieza.
2. `tests/frontend/messages-recovery.test.mjs`: instrumentación y 23 regresiones.
3. `docs/performance-audit-09p-a.md`: esta sección, además del diseño previo ya
   pendiente; se conserva todo el contenido anterior.

`messages/polling.js`, `attempt.js`, backend, vistas, configuración y LEXA no
cambian. Los artefactos compilados ignorados se regeneran por el build. Las
evidencias de DOM nativo y el harness están fuera del repositorio, en
`dom09pb3-1795154439` bajo las visualizaciones de la sesión. No se modifican datos
ni adjuntos y no se accede al worktree de Antigravity. No se añaden archivos al
proyecto, no hay staging, commit o push y no se comienza 09P-B.4.

Pendiente por diseño: el primer poll apto reconstruye los tres fragmentos; no hay
deduplicación inicial con SSR. La respuesta mínima B, el ahorro de servidor/red,
pruebas de despliegue con sesiones reales y medidas de CPU siguen fuera de este
alcance. El cambio mantiene los guards de foco previos y las autorizaciones de
cada acción; no promete actualización de un fragmento mientras esté aplazado.
