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
