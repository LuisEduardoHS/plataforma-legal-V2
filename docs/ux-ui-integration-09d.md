# ETAPA 09D — Accesibilidad y componentes compartidos

Fecha: 18 de noviembre de 2026. Base: `main`, HEAD
`52ff593db77a8151cec7fe792aa30b1f2f9cc662`, inicialmente limpio y sin diferencias
con `origin/main`. La implementación se entregó sin staging, commit ni push;
la revisión posterior para su respaldo autorizado se registra al final.

## Alcance y diagnóstico previo

Se revisó el informe externo `audit09a-20261116/informe-auditoria-09A.md`, los
componentes Blade, layouts, formularios, tokens por módulo y ciclo `onPage`/Turbo.
Antes de editar se guardó un inventario de problemas y una línea base externa de
archivos, assets, bases, almacenamiento privado y otros worktrees.

| Hallazgo verificable | Clasificación | Resultado |
| --- | --- | --- |
| 09A-07: Configuración simulaba un modal con `x-show`; permitía foco detrás y no restituía el disparador | P1, teclado de una acción sensible | Corregido con `dialog.showModal()` nativo |
| Botón de borrar búsqueda de Configuración sin nombre accesible | P2 | Nombre explícito y `type="button"` |
| 09A-06: texto `#848484` sobre blanco y `#EEEEEE` | P2 | Token secundario corregido; variantes locales consolidadas |
| Límites débiles de campos editoriales y perfil/Operaciones; borde de Mensajes dependiente del token de texto | P2 | Token de borde funcional y variante oscura |
| Autenticación/recuperación: campos identificados solo por fondo tenue y borde débil de Recordarme | P2, detectado en comprobación final | Mismo token de borde en `login-input` y checkbox funcional; términos deshabilitados intactos |
| 09A-08: editorial y edición de mensajes sin asociación individual de errores | P2 | Etiquetas, IDs, mensajes y resumen enlazado |
| Dos formularios de Operaciones repoblaban el mismo `old('reason')` | P2, separación de formularios | Errores y valores limitados al formulario enviado |
| `ui.input` podía emitir `id`/ARIA duplicados al recibir atributos explícitos; perdía la ayuda al mostrar un error | P2, contrato compartido | Atributos emitidos una vez; ayuda y error combinados |
| 09A-09: layouts interno y de autenticación sin salto al contenido | P2 | Primer enlace y destino enfocable |
| Espaciados, tarjetas y radios diferentes sin perjuicio funcional demostrado | P3, estética | Conservados |

No se modificaron reglas del dominio, controladores, Form Requests, permisos,
estados, modelos, rutas, migraciones, dependencias ni funcionalidades pendientes.
Los 14 archivos de deuda Pint global siguen fuera del alcance.

## Reutilización y contratos

- Se amplía el componente existente `ui.input`; no se crea otro sistema de campos.
  Admite input y textarea, etiqueta explícita y los atributos nativos del consumidor.
  Conserva `maxlength`, `minlength`, `required`, `rows` y tipo. Un `id` explícito se
  emite una sola vez. Los valores escalares recuperables se escapan; arrays no se
  convierten a texto. Password y file no emiten valores recuperados ni prefijados.
- `ui.field-error` presenta errores exactos y errores de elementos de un array,
  escapados. `active` permite separar formularios con nombres de campo repetidos.
- `ui.error-summary` conserva todos los mensajes, con enlaces solo cuando el
  consumidor proporciona un destino visible real. Los errores generales, de
  intención o versión siguen como texto; no se enlazan campos ocultos. Se enfoca
  cuando el foco está en body/main, sin desplazar a quien está escribiendo.
- El consumidor debe proporcionar IDs únicos y ayudas existentes. El componente
  no adivina IDs de otros formularios ni crea referencias a ayudas ausentes.
- Editorial conserva artículo/recurso, clasificaciones, archivos actuales y nuevos,
  intención, versión, autorización de publicación y POST/PUT/CSRF. Los fieldsets
  de clasificaciones y adjuntos son destinos enfocables, incluso sin opciones.
  Los archivos nuevos deben seleccionarse de nuevo después del error.
- Operaciones identifica el envío mediante los campos existentes exclusivos
  `status` o `permission`, sin introducir marcadores rechazados por el servidor.
  Si llegan ambos, no atribuye el error compartido arbitrariamente. Solo el
  formulario enviado recupera motivo, selección y versión; el otro permanece
  limpio. Una versión inválida no escalar se conserva como vacío, nunca se cambia
  silenciosamente por un token vigente. Se añadió regresión de este caso.
- Mensajería mantiene el contenido opcional cuando el contrato permite conservar
  archivos, su límite de 10,000 caracteres, versión, permisos y cancelación.
- Botones, tarjetas, insignias, estados vacíos, notificaciones existentes y menú
  nativo de cuenta se conservan. No se cambian vínculos por botones ni se activan
  controles futuros de Configuración.

Autenticación/recuperación, perfiles, Comunidad, verificación y moderación ya tenían
asociaciones locales: se inspeccionaron y se conservaron. Sus regresiones previas,
incluidas las tarjetas repetidas de moderación y sus campos `reason`, pasan en la
suite completa. No se afirma uniformidad completa de todos los formularios.

## Diálogos y overlays localizados

| Superficie | Comportamiento y decisión |
| --- | --- |
| Configuración / logout | Se sustituye el div modal por `dialog` nativo con título y descripción. Cancelar recibe foco inicial; Escape y Cancelar cierran y restituyen el disparador. El fondo es inerte por `showModal`, sin trampa artesanal. Se restaura overflow al cerrar y en limpieza Turbo incluso si los listeners ya fueron abortados. Panel desplazable y botones envolventes en móvil. Sin efectos de movimiento nuevos. |
| Comunidad / crear publicación | Dialog nativo existente: foco en contenido, Escape, retorno al enlace, bloqueo/restauración de scroll y limpieza `onPage`. Comprobado en navegador; se conserva el enlace a la página completa como alternativa. |
| Mensajería / información | Dialog nativo existente en móvil y aside en escritorio; título enfocable, Escape y retorno al botón. Layout y contenedor tienen scroll propio. Comprobado en navegador; no se reescribe el módulo. |
| LEXA demo / historial | Dialog nativo existente; búsqueda inicial, Escape, retorno al disparador y restauración de scroll comprobados en navegador. Sigue siendo demostración. |
| LEXA demo / preview de archivo | Inspección de título, cierre, retorno y CSS desplazable existentes. La apertura con archivo no se ejercitó en este QA; queda pendiente la revisión completa de ese recorrido de demo, incluida su limpieza específica de listeners. |
| `professionals/profile-dialog.blade.php` | Componente legado sin invocaciones actuales ni controlador JS activo. Se documenta; no se convierte en funcionalidad ni se elimina. |
| Cuenta, adjuntos, acciones y FAQ | Disclosures/details y menús ordinarios, no modales. Se preservan sus contratos. |

Sin JavaScript, el disparador de salida de Configuración es un formulario real
POST/CSRF con `data-turbo="false"`, igual que el contrato de salida del encabezado.
Cierra directamente la sesión; la confirmación modal es una mejora progresiva.
Con JavaScript el click se intercepta y la confirmación conserva otro POST/CSRF
real. Sin soporte de `showModal`, tampoco se cancela el fallback.

La utilidad nueva de diálogo tiene un contrato limitado: un disparador y un
dialog por raíz `data-dialog-root`. Se usa únicamente en Configuración. No se
reescribieron los controladores de diálogos de otros módulos.

## Contraste y foco

Valores calculados con luminancia relativa sRGB y colores CSS efectivos. Se usa
4.5:1 para texto normal y 3:1 para límites esenciales, sin exigirlos a bordes
decorativos o controles inactivos. No se declara certificación WCAG 2.2 AA.

| Combinación | Antes | Después |
| --- | ---: | ---: |
| Texto secundario sobre blanco | `#848484`: 3.74:1 | `#68616F`: 5.95:1 |
| Texto secundario sobre `#EEEEEE` | 3.22:1 | 5.13:1 |
| Texto secundario sobre `#E6E6E6` | — | 4.77:1 |
| Texto secundario oscuro `#B6B3BA` sobre `#242424` | 7.50:1 | Conservado |
| Límite funcional `#827987` sobre blanco / `#EEEEEE` | Bordes grises pálidos locales | 4.17:1 / 3.60:1 |
| Límite oscuro `#9C969F` sobre `#242424` | Variantes locales | 5.38:1 |
| Error `#B42318` sobre blanco | — | 6.57:1 |
| Error oscuro `#FDA4AF` sobre `#242424` | — | 8.21:1 |
| Blanco sobre `#7F1842` / `#9E1F52` / `#6C4ACB` | 9.94 / 7.58 / 6.04:1 | Identidad conservada |

Los tokens secundarios de Inicio, editorial, directorio, Mensajes y biblioteca/
Ayuda apuntan ahora al token compartido; sus variantes oscuras permanecen. Por
ejemplo, `#717176` sobre `#F5F5F5` daba 4.45:1: revisar solo el blanco habría
ocultado esa diferencia. Comunidad conserva su variante legible existente y
Landing su propia paleta. No se oscurecieron indiscriminadamente todas las líneas.

Se verificaron consumidores de cabeceras, formularios, botones, enlaces, errores,
alertas, tarjetas, estados vacíos y badges de las superficies revisadas. Los
controles futuros siguen deshabilitados y no se presentan como disponibles.

Los layouts app/guest incorporan salto al contenido, destino `main-content` con
`tabindex=-1` y foco visible del enlace. Los destinos de errores tienen margen de
scroll de 6rem para evitar cabeceras/navegación superpuestas. Se preservan menú de
09B, navegación Turbo y arquitectura de Landing sin JavaScript de producto.

## Pruebas y QA

Validación final:

- Laravel: **673 pruebas, 14,856 assertions**, frente a 663/14,411 de base.
- Frontend: **57 pruebas**, frente a 51 de base.
- Build Vite aprobado: 98 módulos transformados; sin nuevas dependencias.
- Pint del PHP afectado aprobado. Los Blade se comprueban mediante renderizado;
  Pint no certifica su HTML. No se declara Pint global aprobado.
- Sintaxis: **268 archivos PHP no Blade**, sin errores.
- Rutas: **109**, sin duplicados al expandir método/dominio/URI; no se modificaron.
- Whitespace de cambios versionados y archivos nuevos aprobado.

Las diez regresiones efectivas de Laravel cubren contratos renderizados de campo,
IDs/atributos únicos, ayuda y error combinados, escapes y campos privados, entrada
array, dos formularios repetidos, artículo y recurso con errores anidados, POST
reales inválidos de estado/permiso y su versión, versión malformada, edición de
mensaje y denegación al destinatario, diálogo/POST/CSRF y saltos de navegación.
No son únicamente búsquedas en fuentes Blade.

Las seis pruebas JS nuevas comprueban foco inicial/retorno, cierre nativo,
restauración de scroll, limpieza después de AbortController, fallback sin soporte,
foco del resumen y ciclo real de la utilidad `onPage` con Turbo/remontaje/pagehide.
Los mocks no certifican inercia ni Tab nativos: esos aspectos se comprobaron en
navegador.

QA de navegador externo, con GET/HEAD exclusivamente y SQL read-only:

- Configuración/dialog: 320, 390, 768, 1024 y 1440px, ambos temas, diez casos.
- Editorial, Operaciones y edición de mensaje con errores largos: mismos cinco
  anchos y temas, treinta casos. Sin desbordamiento horizontal, IDs duplicados ni
  referencias `aria-describedby` inexistentes.
- Dieciocho casos adicionales de consumidores en móvil, más diez de login,
  registro, recuperación, directorio y Ayuda, ambos temas. Revisión posterior de
  catorce casos tras consolidar los tokens locales.
- Autenticación/registro/recuperación: treinta casos finales, cinco anchos y ambos
  temas, tras reforzar sus bordes; colores efectivos y ausencia de overflow
  comprobados. Texto ampliado/reduced-motion de login comprobado además en escritorio.
- Texto al 200% simulado sobre las respuestas CSS externas, cuatro superficies
  corregidas a 320px en ambos temas; ocho casos sin desbordamiento. No equivale
  a certificar zoom nativo ni todos los ajustes del sistema operativo.
- Escape, Cancelar, Tab/Shift+Tab, foco inicial y retorno. El Tab nativo puede pasar
  por el chrome del navegador (activeElement=body); no enfoca controles detrás del
  modal. La inercia se confirmó también con `:modal` y el árbol accesible.
- Tres viajes Turbo Inicio/Configuración, un dialog por documento, sin scroll
  bloqueado ni handlers perdidos. El router externo remapea únicamente esos
  enlaces de QA; no modifica rutas de producto.
- Fallback sin scripts en Configuración: POST, CSRF y enlaces presentes; salto al
  contenido operable por Tab/Enter. No se enviaron POST de negocio en el navegador.
- Movimiento reducido inspeccionado en CSS nativo y simulado en respuestas de QA;
  los componentes añadidos no animan. Errores largos y estados sin datos incluidos.
- Landing: diez casos, cinco anchos/ambos temas, sin overflow ni JS de producto.

Se guardaron capturas y mediciones fuera del repositorio en
`C:\Users\La Chepocompu\.codex\visualizations\2026\11\14\01a1d919-931b-7ef2-868f-a30ec62d0b8a\integration09d-1795017999`.
Las previews privadas renderizan Blade de producción con objetos sintéticos en
memoria: no son usuarios ni concesiones persistidas y no sustituyen las pruebas
HTTP transaccionales de autorización. El viewport se restauró, la pestaña temporal
se cerró y se detuvo exclusivamente el servidor QA propio.

## Aislamiento y conservación

Antes de fixtures se verificó dentro del proceso PHPUnit mediante petición HTTP:
APP_ENV testing, mysql, base configurada y `SELECT DATABASE()` exactamente
`plataforma_legal_v2_test`, cache/session array y almacenamiento privado bajo una
carpeta externa de testing. Testing tiene 33 migraciones y `operations_events`.
La conexión del preflight fue read-only y se desconectó antes de los tests.
El servidor de QA repite las comprobaciones, impone SQL read-only, bloquea HTTP
con escritura y archivos privados, descarta cookies de desarrollo y evita hot Vite.

Comparación de las 51 tablas antes/después:

- Desarrollo: las 51 estructuras coinciden; contenido/recuento de 50 tablas
  coincide exactamente, incluidos usuarios, permisos, roles y auditoría.
- **Incidencia observada:** `sessions` conserva cuatro filas y el mismo esquema,
  pero su hash de contenido cambió durante la intervención. No se conservaron ni
  publicaron payloads de sesión. La evidencia no determina el autor, la petición
  ni el mecanismo de esa variación; no se atribuye a logout ni a un navegador.
  Las garantías de aislamiento de nuestros procesos no constituyen un registro
  de toda actividad concurrente. No se restauraron ni alteraron esas sesiones.
- Testing: todas las filas/recuentos vuelven a su estado inicial tras rollback.
  Los hashes de SHOW CREATE TABLE de tablas con IDs automáticos cambian por el
  avance de AUTO_INCREMENT de las pruebas. Al quitar esa opción de SHOW CREATE,
  las 51 estructuras actuales de testing y desarrollo coinciden. Ambas bases
  conservan 33 filas de migraciones; no se ejecutaron comandos de migración aquí.
- Archivos privados: mismo conjunto, contenido, tamaño y mtime. `.env`,
  `.env.testing` y `public/hot` conservan sus hashes. Estados de los otros dos
  worktrees idénticos a la línea base. No se concedieron privilegios reales.

Por esa variación observada de sesiones no se afirma igualdad total de todos los
datos de desarrollo ni ausencia absoluta de escrituras concurrentes.

## Rendimiento y pendientes delimitados

Medición reproducible de artefactos con Python gzip nivel 9, mtime=0; no mezclar
sus tamaños con la estimación gzip de Vite:

| Artefacto | Base gzip | Final gzip | Diferencia |
| --- | ---: | ---: | ---: |
| app.css | 17,001 B | 17,496 B | +495 B |
| app.js | 46,812 B | 47,034 B | +222 B |
| landing.css | 3,133 B | 3,133 B | 0 B |
| login.css | 17,318 B | 17,839 B | +521 B |
| userHeader.css | 17,954 B | 18,405 B | +451 B |
| Total de assets del build | 165,043 B | 166,751 B | +1,708 B |

Landing carga app.css + landing.css: **20,629 B gzip (20.15 KiB)**, antes 20,134 B;
sin JavaScript de producto y dentro del presupuesto de 60 KiB documentado en 09C.
El CSS propio de Landing no cambió. No se afirma una mejora de velocidad o de
Core Web Vitals: se midieron peso y comportamiento, no una comparación de red
controlada. Las utilidades nuevas usan listeners abortables y no hacen polling.

La repetición preexistente de base Tailwind en entradas CSS app/login/header se
observa en los artefactos grandes y sus incrementos similares; investigar la
composición de entradas y waterfall corresponde a 09P. No se refactorizó Vite.

Pendientes fuera de este cierre: recuperación global 403/404/419/429/503, recorridos
de eliminación y confirmaciones específicas, borrador de perfil al cambiar
municipio, experiencias editoriales/profesionales particulares, presentación de
fechas, preview/ciclo completo de archivos de LEXA demo, límites operativos de
uploads, antimalware/retención y evaluación con lectores de pantalla reales y
otros motores de navegador. No se habilitan preferencias futuras ni LEXA real.
No se declaran resueltos todos los hallazgos 09A ni toda la accesibilidad.

## Inventario exacto

**24 archivos: 7 nuevos, 17 modificados, ninguno eliminado.**

Nuevos:

1. `docs/ux-ui-integration-09d.md`
2. `resources/js/ui/dialog.js`
3. `resources/js/ui/validation.js`
4. `resources/views/components/ui/error-summary.blade.php`
5. `resources/views/components/ui/field-error.blade.php`
6. `tests/Feature/AccessibilityComponentsIntegrationTest.php`
7. `tests/frontend/dialog.test.mjs`

Modificados:

1. `resources/css/app.css`
2. `resources/css/articles.css`
3. `resources/css/home.css`
4. `resources/css/library-help.css`
5. `resources/css/messages.css`
6. `resources/css/professionals.css`
7. `resources/js/app.js`
8. `resources/views/components/ui/input.blade.php`
9. `resources/views/layouts/app.blade.php`
10. `resources/views/layouts/guest.blade.php`
11. `resources/views/layouts/operations.blade.php`
12. `resources/views/pages/articles/edit.blade.php`
13. `resources/views/pages/articles/layout.blade.php`
14. `resources/views/pages/messages/edit.blade.php`
15. `resources/views/pages/operations/user.blade.php`
16. `resources/views/pages/settings/index.blade.php`
17. `resources/css/views/login.css`

El inventario incluye solo fuentes, pruebas y esta documentación. Capturas,
instrumentación, dumps de hashes y runtimes de QA permanecen fuera del repositorio.

Verificación de la entrega de implementación: el inventario coincide exactamente con `git status --short`;
staging vacío, rama `main`, HEAD inicial intacto y hash remoto idéntico, cero
commits por delante/detrás. El árbol contiene exclusivamente estos cambios
pendientes de revisión. No se realizó staging, commit, push ni merge.

## Revisión final para respaldo autorizado — 18/11/2026

La nueva intervención autoriza exclusivamente el commit y push de estos 24
archivos con el mensaje `fix: improve accessibility and shared UI components`.
La inspección inicial confirma main, HEAD esperado, remoto idéntico, staging
vacío y coincidencia exacta de 7 nuevos y 17 modificados, sin eliminados ni
cambios ajenos. No se cambia código durante este cierre; esta sección documenta
la investigación y la revalidación solicitadas.

### Sesiones: clasificación A, actividad normal con evidencia

Se confirmó mediante consultas de solo lectura: entorno local, driver mysql,
base configurada y efectiva `plataforma_legal_v2`. Se verificaron las instantáneas
originales de 09D y la instantánea inicial de este cierre. El binlog está activo,
en formato ROW e imágenes FULL; general_log está desactivado.

El acceso directo al archivo binlog fue denegado por permisos del sistema. Se
utilizó la lectura remota nativa de mysqlbinlog con las credenciales obtenidas
en memoria, sin contraseñas en argumentos ni salida cruda almacenada. La lectura
se limitó a `LACHEPOCOMPU-bin.000045`, base de desarrollo y el intervalo UTC del
18/11/2026 desde 16:06:40 hasta 16:34:54, límite final exclusivo. El cliente usó
TZ=UTC0; los timestamps de los eventos coinciden con los epochs de last_activity.

Resultados:

- Nueve UPDATE entre las instantáneas originales, todos sobre una misma sesión
  anónima, cambiando exclusivamente `last_activity`.
- Una décima actualización de esa actividad a las 16:34:53 UTC, posterior a la
  última instantánea original y ya reflejada al iniciar este cierre.
- En las diez operaciones, `payload` permanece idéntico; tampoco cambian ID,
  usuario, dirección IP ni user agent. No hay INSERT/DELETE ni escrituras en otras
  tablas de desarrollo dentro de ese intervalo.
- Se reprodujeron exactamente los hashes de las dos instantáneas originales
  sustituyendo solo el valor de last_activity en memoria. No se restauraron filas.
  En esa correlación inicial, las cuatro sesiones coincidían con el inicio del cierre;
  la variación adicional posterior se registra a continuación.
- Las otras 50 tablas y los 51 esquemas de desarrollo coinciden con las
  instantáneas originales. Los datos de usuarios, roles, permisos y auditoría
  permanecen iguales.

La clasificación pasa de origen no determinado en la entrega original a **A:
variación normal de actividad de sesión respaldada por evidencia**. El mecanismo
de la variación queda explicado. No se identifica al autor, navegador o petición
concreta, ni se atribuye la actividad al proceso QA; el binlog no basta para esa
atribución. No se expusieron IDs completos, payloads, cookies, tokens ni secretos.
No hay evidencia de la categoría D que obligue a detener el respaldo.

### Variación adicional durante este cierre: clasificación C

La comparación posterior a las validaciones detectó una quinta sesión anónima.
Una segunda lectura de binlog, acotada al 19/11/2026 de 05:28:08 a 05:35:26 UTC,
identificó un único INSERT en sessions a las 05:34:20 UTC (18/11, 23:34:20 de
Ciudad de México). No hubo operaciones en otras tablas de desarrollo en ese tramo.

Las cuatro filas previas permanecen exactamente iguales: al excluir solo la nueva
fila en memoria se reproduce el hash inicial de este cierre; el conjunto de cinco
reproduce la instantánea posterior. La nueva fila no está asociada a una cuenta.
No se decodificó ni se expuso su payload. Usuarios, permisos, roles y auditoría
permanecen sin cambios y las garantías de aislamiento verificadas siguen vigentes.

El INSERT es posterior al cierre de la suite Laravel a las 05:33:18 UTC y a la
finalización de build/rutas a las 05:33:35 UTC, según los artefactos externos. Esa
secuencia no identifica al autor ni prueba qué petición creó la sesión. Se
clasifica conservadoramente como **C: origen no determinado, sin indicios de
afectación de otros datos ni fallas de aislamiento**. La variación original sigue
clasificada A; la limitación residual del cierre es C. La autorización permite
continuar en C si las demás comprobaciones son satisfactorias. No se restauró ni
eliminó la nueva sesión, ni se detuvieron servidores ajenos.

Un seguimiento acotado de 05:35:26 a 05:38:25 UTC confirmó además un UPDATE de
last_activity de esa nueva sesión a las 05:37:00 UTC, con payload idéntico y sin
otras columnas alteradas. Sus actualizaciones de actividad son normales; el
origen de su creación permanece sin determinar. No se exige igualdad de hashes
de una tabla de sesiones con actividad concurrente para cerrar en la categoría C.

### Revalidación y conservación

Se repitió la suite con un bootstrap externo que verifica aislamiento efectivo
mediante HTTP dentro del proceso PHPUnit antes de fixtures: APP_ENV testing,
mysql, base configurada y SELECT DATABASE() iguales a plataforma_legal_v2_test,
cache y sesiones array, almacenamiento privado externo. El preflight es SQL
read-only y desconecta su conexión antes de las escrituras transaccionales de tests.

- Laravel: **673 pruebas, 14,856 assertions**, aprobadas.
- Frontend: **57 pruebas**, aprobadas.
- Build Vite, Pint del PHP afectado y sintaxis de **268 fuentes PHP**, aprobados.
- **109 rutas**, sin duplicados por método/dominio/URI.
- Whitespace comprobado también con archivos nuevos sin seguimiento.
- Los 14 archivos de deuda Pint global siguen fuera del alcance.

La revisión del código, las regresiones de renderizado/HTTP y JS, y las evidencias
de navegador previas conservan los contratos de foco, Escape, Cancelar, scroll,
Turbo, POST/CSRF, fallback, campos y errores. El nuevo build mantiene los assets
de Landing sin JavaScript de producto y los tamaños documentados. No se repite
ni se amplía la intervención de QA visual; sus limitaciones siguen vigentes.

Las evidencias de cierre se guardan fuera del repositorio en
`C:\Users\La Chepocompu\.codex\visualizations\2026\11\14\01a1d919-931b-7ef2-868f-a30ec62d0b8a\closure09d-1795066086`.
No se modifican datos funcionales, sesiones, adjuntos, .env ni otros worktrees;
el inventario autorizado permanece en **24 archivos**. El hash del commit y la
confirmación remota se reportan en la entrega del cierre para evitar una
autorreferencia dentro del propio commit.
