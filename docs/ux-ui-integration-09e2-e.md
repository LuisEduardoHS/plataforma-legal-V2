# 09E.2-E — Errores, recuperación de sesión y Mensajes

## Inspección y contratos de partida

Antes de modificar código se comprobó `main`, HEAD
`00d45497723fb00a156ba2e939b020fe8e15fe2f`, árbol y staging limpios y remoto
sincronizado. Alcance exclusivo: hallazgos 09E1-07 y 09E1-16 del informe externo
de 09E.1. No se cambian permisos, políticas, reglas del dominio ni rutas de negocio.

La sanitización SQL existente es correcta y se conserva. Los módulos ya tienen
handlers con mensajes seguros, pero sus vistas cargan el shell normal y algunos
errores globales/perfiles quedan en una página genérica o texto sin recuperación.
La recuperación debe ser HTML estático, sin consultar cuentas, capacidades o
recursos privados para decidir qué enlaces mostrar.

| Estado | Contrato que se conserva |
| --- | --- |
| 401 | Autenticación JSON y cuenta inactiva; HTML protegido redirige a login. No se inventa un flujo HTML 401. |
| 403 | Denegación real de acciones; los módulos conservan sus ocultaciones deliberadas mediante 404. |
| 404 | Página/recurso no disponible; no se informa si una conversación ajena existe. |
| 409 | Conflicto de versión/estado existente; consultar una lectura actual, sin reenviar la mutación. |
| 419 | Rechazo CSRF/sesión del framework; se mantiene el rechazo y se obtiene acceso/formulario actual manualmente. |
| 429 | Rate limiting vigente y sus encabezados, incluido Retry-After. |
| 503 | Mensajes sanitizados de indisponibilidad, sin SQL, trazas ni datos privados. |

Los errores de validación de formularios mantienen sus redirecciones y bolsas de
errores. Los 422 de lectura de módulos mantienen su status. Las descargas privadas
conservan sus contratos; no se convierten en páginas exitosas ni se relaja acceso.
Las respuestas que esperan JSON no deben recibir una página HTML de recuperación.

## Mensajes antes del cambio

El sondeo actual usa una petición de lectura cada diez segundos cuando la vista
está activa, enfocada y al final del historial, sin peticiones simultáneas. 401,
403 y 404 paran el sondeo y vacían parte del contenido, pero comparten un aviso sin
acción contextual. Permanecen otras superficies privadas, incluido el borrador;
419 no usa esa recuperación. Los fallos temporales siguen intentando lecturas.

El envío ya es idempotente: `createSendAttempt` conserva token, texto e IDs
originales ante una respuesta incierta. Solo una confirmación válida rota el token.
No hay reenvío automático y no se modificará ese contrato ni el servicio de envío.
El estado `can_send=false` puede conservar legítimamente un historial de lectura;
no se interpretará por sí solo como pérdida de autorización para leer.

## Decisión acotada

Reutilizar las vistas de errores de Laravel y una presentación estática compartida,
con acciones GET fijas según el módulo y sin datos privados del request. Mantener
los handlers del dominio y sus status/JSON. Mensajes distinguirá recuperación
definitiva de una pausa temporal de lecturas: borrado de superficies privadas y
cancelación definitiva cuando se pierde acceso, consulta manual para recuperar una
actualización temporal y cero reenvíos automáticos. La recuperación de sesión
usará la entrada protegida de Mensajes para que el middleware existente establezca
el retorno normal a la bandeja; no se modifica `ReadingReturn` ni se transporta
un borrador o una conversación privada entre identidades.

## Implementación

Se utiliza `resources/views/errors/layout.blade.php` con las vistas convencionales
403, 404, 409, 419, 429 y 503 de Laravel. Los cinco módulos con vistas de error
propias reutilizan esta presentación y conservan sus mensajes sanitizados y códigos.
No se usa el mensaje de la excepción para construir el HTML. La marca, DM Sans,
paleta, tarjeta y foco proceden de los componentes y estilos existentes.

Los enlaces de recuperación son destinos GET fijos por nombre de ruta. No copian
parámetros, identificadores de conversaciones, URL de origen, datos del formulario
ni contenido privado. Se ofrecen listados del módulo, perfil propio, portada o
Ayuda; 419 también permite iniciar sesión manualmente. No hay formularios de
reenvío, dependencia de historial del navegador ni scripts propios del error.
Los metadatos de Turbo exigen una lectura nueva y evitan almacenar estas páginas.

El handler SQL global conserva JSON con un único mensaje seguro y 503; su variante
HTML ahora ofrece recuperación. Los tres controladores de lectura de perfiles que
capturaban SQL también utilizan esta vista y responden JSON si se solicita JSON,
en lugar de texto o catálogo HTML de error. No se alteran las reglas del catálogo,
validación ni privacidad. Las descargas/fotografía y el handler especial de
recuperación de contraseña conservan sus respuestas deliberadas, incluidos texto,
JSON y `Referrer-Policy`; no se convierten indiscriminadamente en páginas visuales.

El último callback de excepciones HTTP mantiene JSON mínimo en los estados
previstos, incluso con depuración local activada. Los handlers de los módulos
tienen prioridad. Se conservan encabezados del framework, incluido `Retry-After`,
y se añade `private, no-store` a esos errores. Autenticación nativa sigue devolviendo
401 JSON o redirección HTML según el contrato. No se cambian middleware, políticas,
CSRF, limitadores, archivos, reglas de cuenta activa ni `ReadingReturn`.

## Recuperación de Mensajes

| Respuesta real | Comportamiento |
| --- | --- |
| 401, 403, 404 o 419 | Parada irreversible del sondeo, cancelación de solicitudes y retirada de las superficies privadas. |
| 409 | Pausa de lecturas y aviso de consultar el estado actual; ninguna mutación se repite. |
| 429 | Pausa y explicación de esperar antes de volver a consultar; el servidor conserva su limitador. |
| Fallo temporal, red o respuesta de lectura inválida | Pausa y botón manual que solo hace GET. Se conserva el contexto todavía autorizado. |
| Lectura válida con `can_send=false` | Continúan el historial autorizado y el sondeo normal; se deshabilita el envío. |

La retirada incluye historial, bandeja, alias de participantes, información y
diálogo abierto, archivos preparados, borrador, token de envío, formularios y URL
del sondeo. Ante 401/419 también se retira la navegación de la identidad anterior.
El aviso recibe foco y nivel principal de encabezado, con acceso manual a la
bandeja protegida o a la portada. No informa si existe una conversación ajena.
Un error definitivo de envío se procesa al recibir los encabezados, sin esperar
a que termine de descargarse su cuerpo.

Los fallos temporales no descartan el intento de envío congelado. Token, texto e
IDs originales siguen reutilizándose únicamente si la persona decide reintentar
ese mismo envío. Consultar el historial no ejecuta POST, PUT, DELETE ni uploads.
Solo una confirmación válida libera el intento y rota el token. No se modifica el
servicio de idempotencia ni el tratamiento de validación 422.

El sondeador añade pausa y reanudación, manteniendo diez segundos, una petición
simultánea y sus condiciones anteriores de actividad. Una lectura manual válida
reanuda un único temporizador. Enfoque o visibilidad no evitan una pausa por error.
Una generación de lectura impide que una respuesta anterior reanude el sondeo tras
un envío incierto. Los callbacks ignoran respuestas posteriores a cancelación,
pérdida de acceso o desmontaje Turbo. Se conserva la protección de BFCache.

No existe actualmente una acción de dominio de eliminación o revocación completa
de conversación. No se inventa: ausencia y pérdida de pertenencia producen el 404
del contrato vigente. Bloquear o desactivar al otro participante puede conservar
lectura y no se interpreta como revocación. La QA de indisponibilidad elimina
exclusivamente fixtures sintéticos dentro de una transacción revertida; las pruebas
HTTP también cubren acceso ajeno, ausencia y eliminación sintética.

## Pruebas y aislamiento

Antes de fixtures se comprobó en el mismo proceso HTTP: entorno testing, driver
MySQL, nombre configurado y resultado de `SELECT DATABASE()` exactamente
`plataforma_legal_v2_test`, caché aislada y almacenamiento externo al repositorio.
Las pruebas nuevas repiten una precondición HTTP sobre Ayuda antes de la transacción.
La suite usa sesiones/caché en memoria; la regresión preexistente de sesiones de
base de datos usa expresamente la conexión de testing y rollback. El navegador
usa cookie propia `qa09e2e_session`, sesiones en archivos externos y caché en memoria.

Los fixtures de recuperación y QA se revierten por transacción. Las pruebas
preexistentes de concurrencia requieren operaciones entre conexiones de testing
y tienen limpieza explícita de sus propios IDs; las huellas finales comprueban
que no dejan fixtures persistidos. Ningún servidor de QA conecta a desarrollo ni
utiliza conversaciones reales. Los escenarios se inyectan mediante rutas/controlador de
pruebas en memoria y un servidor externo temporal, sin modificar rutas de negocio,
configuración permanente ni proveedores. El servidor y la pestaña temporal se
cerraron y se restableció el tamaño del navegador.

Resultados finales:

- Laravel focalizado nuevo: **31 pruebas, 853 assertions**.
- Focalizado con regresiones textuales anteriores: **33 pruebas, 871 assertions**.
- Laravel completo: **838 pruebas, 17,835 assertions**, incluidas 09E.2-A/B/C/D.
- Frontend de recuperación: **24 pruebas**, incluidas nueve de la corrección P1;
  suite completa: **108 pruebas**.
- Build Vite correcto; Pint de los **19 archivos PHP/Blade afectados** correcto.
- Sintaxis: **278 archivos PHP** y **101 vistas Blade compiladas**, sin errores.
- **110 rutas**, sin duplicados de nombre ni de método y URI.
- Whitespace de cambios versionados y archivos nuevos correcto; staging vacío.

Las regresiones HTTP renderizan HTML real y verifican JSON, status, cabeceras,
CSRF nativo, limitador real, SQL con depuración, destinos GET, ausencia de filtraciones
y consultas privadas al renderizar. Incluyen cuenta activa/inactiva, sesión
caducada, conversación ajena/ausente/eliminada, envío no autorizado, conflicto real
de versión sin sobrescritura e historial permitido con envío deshabilitado.
Las pruebas frontend ejecutan el controlador y sondeador reales, incluyendo abortos,
respuestas tardías, desconexión, temporizadores, consulta manual, identidad del envío
reintentado, desmontaje Turbo y ausencia de reenvíos automáticos.

La primera ejecución completa detectó dos expectativas de textos anteriores.
Se conservaron «Conflicto de versión» y «No pudimos cargar los perfiles» en la
presentación correspondiente; no se debilitaron las pruebas existentes. La ejecución
completa posterior es la aprobada que se reporta arriba.

## QA de navegador y accesibilidad

Se comprobaron **320, 390, 768, 1024 y 1440 px**, claro y oscuro:

- 60 combinaciones de errores HTML 403/404/409/419/429/503, sin desbordamiento
  horizontal y sin scripts; 409 se repitió en diez combinaciones con texto al 200 %.
- Texto extenso, foco visible, salto al contenido, acciones GET, recuperación sin
  JavaScript y navegación desde Ayuda mediante Turbo y vuelta con Atrás.
- Diez combinaciones de Mensajes con 503: aviso visible, borrador conservado,
  compositor accesible y sin desbordamiento horizontal. Reanudación manual por GET.
- 404 de conversación no disponible y 401 durante sondeo: retirada de información
  y formularios, cierre de diálogo, foco seguro y ausencia de nuevas lecturas.
- Recuperación manual a login y entrada con otra cuenta sintética: bandeja propia
  vacía, sin conversación o borrador de la identidad anterior.
- Fallo de conexión a un puerto local sin servicio, provocado solo en el HTML del
  harness: pausa, contexto autorizado conservado y consulta manual disponible.
- Historial con participante desactivado y envío deshabilitado, con y sin scripts.
  Las lecturas siguen autorizadas; los formularios nativos mantienen POST y CSRF.
- Aviso y acciones nuevos con texto al 200 %, desplazamiento vertical y teclado.
  En todo el registro de navegador hubo **cero POST de Mensajería**; únicamente
  se realizaron login/logout sintéticos. Los reenvíos explícitos idempotentes se
  cubren en las suites de servidor y cliente.

Limitación visual observada: a 390 px y texto raíz al 200 %, las etiquetas de la
barra móvil preexistente se solapan. Sus archivos y estilos permanecen intactos.
Las acciones nuevas pueden leerse y enfocarse con desplazamiento vertical. No se
declara aprobada globalmente toda la navegación de la aplicación al 200 % ni se
amplía este lote para rediseñarla. No se ha realizado una auditoría con lector de
pantalla físico.

Las pruebas visuales fuerzan el tema y la ampliación solo en las respuestas CSS
del harness externo. Evidencias, métricas y diagnósticos se conservan fuera del
repositorio, en `recovery09e2e-1795134661` dentro del directorio de visualizaciones
de esta sesión. No se incluyen capturas, fixtures ni sesiones en el inventario.

## Rendimiento

Medianas de tres peticiones HTTP sintéticas por escenario, con rollback. Los
tiempos son orientativos y no equivalen a un benchmark ni a un objetivo de latencia.

| Escenario | HTML antes → después, bytes | Consultas antes → después | Scripts antes → después |
| --- | --- | --- | --- |
| 403 genérico | 6,674 → 2,311 | 0 → 0 | 0 → 0 |
| 404 genérico | 6,659 → 2,338 | 0 → 0 | 0 → 0 |
| 409 genérico | 991 → 2,408 | 0 → 0 | 0 → 0 |
| 419 CSRF | 6,665 → 2,585 | 0 → 0 | 0 → 0 |
| 429 | 6,675 → 2,315 | 0 → 0 | 0 → 0 |
| SQL 503 HTML | 69 de texto → 2,425 de HTML | 0 → 0 | 0 → 0 |
| Mensajería, recurso privado ajeno 404 | 14,473 → 2,440 | 9 → 4 | 1 → 0 |
| Chat autorizado | 24,006 → 24,716 | 28 → 28 | 2 → 2 |

401 JSON mantiene 30 bytes y cero consultas. El segundo script contado en chat es
el bloque JSON de reglas de archivos. La presentación del error no consulta
servicios privados: las cuatro consultas del 404 pertenecen a autenticación y
autorización existentes. Las comprobaciones de renderizado directo verifican cero.
Las medianas del tiempo de manejo HTTP fueron 6.59 → 6.79 ms para 403,
60.51 → 36.56 ms para el 404 privado y 91.13 → 127.23 ms para chat. No incluyen el
arranque de PHP y tienen variación ambiental; tres muestras no permiten atribuir
causalidad ni declarar una mejora general de latencia. El chat conserva 28 consultas.

Los errores nuevos enlazan la hoja principal y la hoja de DM Sans; la fuente puede
requerir otra descarga según caché. Los errores de Laravel anteriores llevaban
estilos embebidos, por lo que menor HTML no implica menor coste total en caché fría.
No se añade un archivo CSS, módulo de ejecución o dependencia para errores estáticos.
La presentación reutiliza los assets ya compartidos de la plataforma.

Cambios de bundles minificados frente al respaldo inicial, gzip nivel 9:

| Entrada | Variación sin comprimir | Variación gzip |
| --- | --- | --- |
| CSS principal | +274 bytes | +68 bytes |
| CSS de Mensajes | +722 bytes | +145 bytes |
| JS principal existente, incluida P1 | +2,562 bytes | +690 bytes |
| Entrada JS de Mensajes, incluida P1 | +2,571 bytes | +727 bytes |

Las dos entradas JavaScript ya existían; no deben sumarse como si se descargaran
ambas en cada página. El fragmento compartido generado con nombre `dates` también
contiene el sondeador, de modo que su cambio no implica modificar el módulo fuente
de fechas. Se mantiene el número de recursos del chat. Se observaron intervalos
normales cercanos a diez segundos y una sola lectura fallida seguida de pausa;
la consulta manual reanudó el mismo comportamiento. No se inicia 09P ni tiempo real.

## Conservación e incidencias de la implementación inicial

Este apartado corresponde al primer intervalo de implementación. La comparación
del intervalo posterior de corrección P1 se detalla al final, y registra cambios
concurrentes de desarrollo; no debe interpretarse este apartado como una afirmación
de que desarrollo permaneció idéntico durante toda la corrección P1.

Comparación de huellas de las 51 tablas de cada base antes/después:

- Desarrollo: estructura idéntica en las 51; contenido idéntico en las 50 tablas
  distintas de `sessions`. Usuarios, roles, permisos, concesiones, operaciones y
  datos funcionales no cambiaron.
- Testing: contenido idéntico en las 51; las únicas diferencias de DDL son avances
  de AUTO_INCREMENT en 26 tablas por fixtures revertidos. Se comprobó reconstruyendo
  en memoria las definiciones anteriores; no se reiniciaron contadores ni se
  ejecutaron migraciones o cambios de esquema.
- Adjunto privado existente: inventario, SHA-256, tamaño y fecha de modificación
  idénticos. `.env`, `.env.testing` y `public/hot` conservan sus huellas.

En desarrollo `sessions` pasó de una a tres filas. El binlog, leído en un intervalo
acotado y sin volcar valores privados, contiene 29 UPDATE y dos INSERT de sesiones
anónimas: actividad, dos cambios de URL/ruta anterior y otro de URL anterior.
No hay usuarios autenticados, claves de autenticación o credenciales en esos
eventos, ni cambios de otras tablas de desarrollo en el intervalo. Las referencias
enmascaradas no coinciden con las sesiones de archivo de QA. El registro no permite
identificar al autor o proceso de las peticiones; no se atribuyen a una persona ni
se afirma que desarrollo permaneciera idéntico en su totalidad. No se restauraron,
eliminaron ni alteraron esas sesiones. El aislamiento de nuestras peticiones y
fixtures se comprobó antes de las escrituras y queda en las evidencias externas.

Pint global mantiene la deuda preexistente de 14 archivos ajenos. No se formatearon;
únicamente se declara aprobado Pint de los archivos afectados. No se cambiaron
permisos, datos funcionales, adjuntos ni configuración. No hay funciones nuevas de
revocación, notificaciones, autenticación o recuperación de borradores entre cuentas.

## Inventario exacto

**24 archivos: 13 modificados y 11 nuevos; ninguno eliminado.**

Modificados:

1. `app/Http/Controllers/Profiles/ProfessionalDirectoryController.php`
2. `app/Http/Controllers/Profiles/ProfileController.php`
3. `app/Http/Controllers/Profiles/PublicProfileController.php`
4. `bootstrap/app.php`
5. `resources/css/messages.css`
6. `resources/js/messages.js`
7. `resources/js/messages/polling.js`
8. `resources/views/pages/articles/error.blade.php`
9. `resources/views/pages/community/error.blade.php`
10. `resources/views/pages/messages/error.blade.php`
11. `resources/views/pages/messages/index.blade.php`
12. `resources/views/pages/operations/error.blade.php`
13. `resources/views/pages/verification/error.blade.php`

Nuevos:

1. `resources/views/errors/layout.blade.php`
2. `resources/views/errors/403.blade.php`
3. `resources/views/errors/404.blade.php`
4. `resources/views/errors/409.blade.php`
5. `resources/views/errors/419.blade.php`
6. `resources/views/errors/429.blade.php`
7. `resources/views/errors/503.blade.php`
8. `resources/views/pages/messages/partials/recovery.blade.php`
9. `tests/Feature/ErrorRecoveryIntegrationTest.php`
10. `tests/frontend/messages-recovery.test.mjs`
11. `docs/ux-ui-integration-09e2-e.md`

## Estado de Git

`main`, HEAD `00d45497723fb00a156ba2e939b020fe8e15fe2f`, igual a `origin/main`
y al hash remoto consultado. Cero commits por delante o detrás. Los 24 archivos
anteriores quedan sin staging para revisión; no se realizó commit, push, merge ni
cambio de rama. Los dos worktrees preexistentes conservaron sus estados durante
la implementación inicial. El usuario confirmó posteriormente que
`D:/projects/plataforma-legal-V2-diseno`, rama `diseno/ajustes-ui`, fue creado
deliberadamente para trabajar el diseño de forma independiente. Su existencia y avance son
actividad autorizada, no una incidencia. No se exige que esté limpio ni se
inspecciona o modifica su contenido durante la corrección P1. Tampoco se modifican
los otros worktrees. No hay cambios ajenos en el árbol principal. No se modifican
rutas, dependencias, Vite, migraciones, middleware ni reglas de dominio. No se
comienza 09E.2-F, 09F, 09P ni LEXA.

## Corrección P1 de concurrencia detectada en la revisión de cierre

La revisión independiente detuvo el cierre antes del staging: las quince pruebas
frontend originales pasaban, pero cuatro casos nuevos reproducían una pérdida de
privacidad. Había un GET de sondeo pendiente; un envío perdía conectividad y
`pauseReads()` incrementaba `readGeneration`. Si después llegaba el 401, 403, 404
o 419 del GET, el filtro de generación retornaba antes de `revoke()`. El historial
privado permanecía visible. Los cuatro casos se volvieron a ejecutar y fallaron
antes de aplicar esta corrección.

La corrección en `messages.js` conserva primero el rechazo de respuestas de un
controlador revocado, desmontado o con solicitudes canceladas. Para un controlador
todavía vigente, atiende los cuatro estados definitivos mediante el `revoke()`
local existente antes de comprobar la generación. Después descarta los resultados
obsoletos y mantiene el tratamiento de errores temporales y de respuestas válidas.
No se modifica `polling.js`, la idempotencia, autenticación, políticas ni servidor.

Una generación identifica la vigencia de resultados de lectura, no una identidad
de seguridad nueva. Cada montaje de `onPage` posee su raíz DOM, conversación,
solicitudes y estado de revocación. Turbo desmonta el controlador anterior y aborta
su trabajo antes de renderizar otro cuerpo. Cambiar de conversación crea otro
montaje; un cambio de identidad mediante autenticación también requiere otra
navegación/documento. No hay un contrato para cambiar la identidad dentro de la
misma raíz. Por eso una respuesta del controlador desmontado se ignora antes de
examinar su status, incluso si la nueva vista utiliza la misma URL de conversación.
No se añade una revocación global, un identificador de cuenta al DOM ni nuevos
metadatos privados. `revoke()` conserva su ejecución única y su retirada centralizada.

### Regresiones permanentes P1

Dentro de `tests/frontend/messages-recovery.test.mjs` se añadieron nueve pruebas:

- Cuatro casos separados para 401/403/404/419 después del envío incierto y del
  cambio de generación. Verifican GET pendiente, pausa, retirada de historial,
  bandeja, información, formularios, borrador y token; aborto de otro envío
  explícito pendiente, revocación única y ausencia de nuevas lecturas/reenvíos.
  Una confirmación tardía de ese envío no recupera contenido privado.
- Respuestas definitivas después del desmontaje real de `onPage` por el evento
  Turbo, cubriendo los cuatro estados.
- Cambio de conversación y cambio de identidad como montajes distintos, incluido
  el segundo caso con la misma URL protegida. El error antiguo no vacía el nuevo
  contexto ni su navegación.
- Errores definitivos simultáneos en POST y GET en ambos órdenes: una retirada,
  un foco y un aborto, sin solicitudes adicionales.
- Fallo tardío 503 o de red tras un envío incierto: historial y borrador autorizados
  conservados y sondeo pausado.

Se fortaleció la prueba existente de GET 200 obsoleto para comprobar también que
bandeja, información y navegación no cambian. Continúan aprobadas las pruebas
existentes de envío idempotente, confirmación válida, `can_send=false`, errores
temporales y cancelación. El test ejecuta el controlador y el ciclo de vida reales,
con respuestas controladas que pueden llegar incluso después de abortar el fetch.

### Validación y QA posteriores a P1

Las cuatro regresiones P1 pasan por separado. El archivo de recuperación completo
pasa sus **24 pruebas**, y frontend completo pasa **108**. Laravel focalizado vuelve
a aprobar **31 pruebas y 853 assertions**; Laravel completo aprueba **838 pruebas
y 17,835 assertions**. Build, Pint afectado (19 archivos), sintaxis (278 PHP y
101 Blade compiladas), 110 rutas sin duplicados y whitespace quedan aprobados.
El aislamiento HTTP previo y dentro del proceso confirma testing, MySQL efectivo
`plataforma_legal_v2_test`, caché/sesiones aisladas y almacenamiento privado externo.

Se realizó QA con navegador real, sesiones nativas y dos conversaciones sintéticas.
Un proxy HTTP externo retiene únicamente la entrega de la respuesta GET, después
de que Laravel haya completado la petición y revertido su transacción. Así el GET
sigue pendiente en el cliente y el servidor puede atender otra petición. El corte
de conexión del POST se hace en el proxy antes de entregarlo a Laravel; no se
persisten mensajes. Las solicitudes nativas de login/logout conservan CSRF.
No se cambian rutas, configuración ni datos de desarrollo para estos escenarios.

Escenarios registrados, cada uno con exactamente un envío incierto explícito entre
la retención y la liberación del GET:

| GET retenido | Resultado verificado |
| --- | --- |
| 401 real por cuenta sintética inactiva | Tras el envío incierto, la respuesta retira historial, participantes, borrador, formularios y navegación; enfoca el aviso y permite llegar a login con teclado. No hay sondeos posteriores antes de la recuperación manual. |
| 404 real de conversación sintética ausente | Se cambia mediante Turbo a otra conversación antes de liberar el error. El controlador anterior cancela el fetch; el historial, borrador y navegación nuevos permanecen intactos. |
| 503 temporal | El error tardío conserva el contexto autorizado. Se observaron más de 19 segundos sin nuevas solicitudes; el reintento manual solo hace GET y conserva el envío congelado. |
| 200 válido pero obsoleto | Después de otro reintento de envío explícito e incierto, no sustituye resultados ni reanuda el sondeo. Se observaron más de 50 segundos sin solicitudes adicionales. |

Los registros contienen cuatro cortes de POST, correspondientes a las cuatro
acciones explícitas de QA, sin reenvíos automáticos. No hay POST de Mensajería
entregados al backend de QA. Se guardaron evidencias visuales y metadatos seguros
en el directorio externo `p1-recovery09e2e-1795138684`. Pestaña, viewport, proxy y
servidor temporal quedaron cerrados/restablecidos al terminar. La limitación de
barra móvil al 200 % permanece pendiente de 09F; no se declara accesibilidad global
aprobada ni se comienza ese trabajo.

### Conservación durante el intervalo de P1

El intervalo de comparación incluye la pausa y reanudación del trabajo, desde
2026-11-20 01:37:54 UTC hasta 04:44:23 UTC. Testing conserva exactamente el contenido
de sus 51 tablas, sin fixtures persistidos. En 26 tablas avanzaron únicamente los
contadores AUTO_INCREMENT por pruebas revertidas; las estructuras se comprobaron
reconstruyendo las definiciones anteriores en memoria, sin alterar contadores.
Adjuntos privados, `.env`, `.env.testing` y `public/hot` mantienen sus huellas.

**No se afirma que los datos funcionales de desarrollo permanecieran idénticos en
este intervalo.** La comparación detecta estas variaciones de filas:

| Tabla de desarrollo | Antes → después |
| --- | --- |
| users | 2 → 3 |
| user_profiles | 1 → 2 |
| model_has_roles | 2 → 3 |
| posts | 0 → 1 |
| post_tag | 0 → 1 |
| post_reactions | 0 → 2 |
| saved_items | 0 → 1 |
| user_follows | 0 → 1 |
| cache | 19 → 33 |
| sessions | 3 → 2 |

La inspección acotada del binlog se realizó en solo lectura y sin volcar contenido,
credenciales, IDs reales, URLs privadas, IP o agentes de usuario. Confirma esas
operaciones y actividad transitoria de caché/bloqueos. Las altas de contenido y
cuentas registradas ocurrieron desde las 02:21:52 UTC; la suite Laravel había
terminado a las 01:43:30 UTC. Las 51 estructuras de desarrollo permanecen iguales:
sus cinco diferencias de DDL son únicamente avances AUTO_INCREMENT.

No se puede identificar al autor o proceso con esta evidencia y no se atribuyen
los cambios al usuario, Copilot ni a estas pruebas. Nuestra ejecución documenta
MySQL testing efectivo antes de fixtures y transacciones revertidas, y no ejecutó
escrituras, migraciones, seeders ni restauraciones sobre desarrollo. No se alteró
o eliminó ninguno de los registros observados. Se conserva esta salvedad para la
revisión posterior del cierre.

### Alcance y Git después de P1

Solo se modifican respecto al inicio de esta intervención `resources/js/messages.js`,
`tests/frontend/messages-recovery.test.mjs` y este documento. Los otros 21 archivos
del inventario conservan sus huellas anteriores. El inventario continúa siendo
**24 archivos, 13 modificados y 11 nuevos**, sin eliminaciones o ampliaciones.
`main` conserva HEAD `00d45497723fb00a156ba2e939b020fe8e15fe2f`; staging vacío,
sin commit, push, merge ni cambio de rama. No se inspeccionaron ni modificaron los
worktrees de diseño u otros agentes durante esta corrección.

## Revisión definitiva previa al respaldo

Se volvió a comprobar `main`, HEAD de partida
`00d45497723fb00a156ba2e939b020fe8e15fe2f`, remoto coincidente, staging vacío e
inventario exacto de 24 archivos: 13 modificados y 11 nuevos, sin eliminaciones.
Esta revisión no introduce cambios de código; únicamente completa este documento.
Se conserva íntegra la corrección P1 y no se amplía el inventario.

La revisión del controlador y las regresiones permanentes confirman que las guardas
de cancelación/desmontaje preceden al procesamiento de respuestas, que la denegación
definitiva del contexto activo precede al filtro de generación y que la revocación
local ocurre una sola vez. Se mantienen el intento idempotente, los resultados
exitosos obsoletos descartados, la protección del contexto nuevo y el reintento de
lectura manual. No se modifican autenticación, servicio de envío, permisos o políticas.

Se repitieron las cuatro regresiones P1, las 24 pruebas del controlador frontend,
las 108 pruebas frontend completas, Laravel focalizado (31/853) y Laravel completo
(838/17,835). También se comprobaron build Vite, Pint de 19 archivos afectados,
278 PHP y 101 Blade compiladas, 110 rutas sin duplicados y whitespace. Se revisaron
JSON/HTML, 401/403/404/409/419/429/503, ocultación de recursos ajenos, SQL sanitizado,
CSRF, rate limiting, cabeceras y destinos GET sin reenvío automático. La suite
completa mantiene las regresiones de 09E.2-A/B/C/D.

### Cambios concurrentes: clasificación y certeza

Se releyó en modo exclusivamente de lectura el binlog del intervalo P1 ya
documentado, 2026-11-20 01:37:54–04:44:23 UTC. El informe externo conserva solo
contadores, tablas, categorías y conclusiones; no incluye valores de cuentas,
contraseñas, tokens, sesiones, contenidos, IDs reales ni datos de conexión.

- **Actividad funcional compatible con uso ordinario, autoría no identificable:**
  alta de una cuenta, perfil, publicación/etiqueta, reacciones, seguimiento y
  guardados. La única asociación nueva de rol corresponde a la cuenta creada en
  ese intervalo y al rol base `user`/`web`, que es el contrato existente de registro.
  El rol no contiene capacidades `operations.*`, `verification.*` ni comodín global;
  tampoco se encontraron concesiones directas sensibles a esa cuenta.
- **Credenciales y autorización:** no se registraron actualizaciones sensibles de
  cuentas preexistentes ni eliminaciones de cuentas. La creación de una credencial
  para una cuenta nueva es inherente al registro y no equivale a una modificación
  de credenciales existentes. No hubo DML del catálogo de roles/permisos, de sus
  relaciones de capacidades, de concesiones directas o de tokens de recuperación.
  Sus huellas y las de eventos operativos permanecen iguales entre las fotografías
  P1 y la revisión actual. No se detectó elevación operativa o de revisión.
- **Actividad técnica concurrente:** mantenimiento de caché y bloqueos, y ciclo de
  sesiones. No se atribuye a una persona, herramienta o proceso concreto. El binlog
  permite clasificar operaciones, pero no demostrar qué interfaz o actor las originó.

La certeza sobre el aislamiento de nuestras ejecuciones es alta: el bootstrap
comprueba dentro del proceso entorno testing, driver MySQL y base configurada y
efectiva exacta antes de fixtures; `Tests/TestCase.php` repite el control por caso.
Los probes HTTP preceden las escrituras y la QA verifica cada petición antes de
crear fixtures. Las conexiones adicionales de concurrencia clonan la configuración
de testing y verifican también `SELECT DATABASE()`. Las sesiones/caché son aisladas;
la prueba específica de sesiones SQL usa la conexión de testing. El almacenamiento
privado es externo. El backend de QA no recibió los POST de Mensajería cuyo corte
de conexión se simuló en el proxy.

La secuencia temporal aporta evidencia adicional: el contenido/cuenta nuevos del
intervalo P1 se registraron después de terminar la suite Laravel. Esto no identifica
al autor y, por sí solo, tampoco excluiría toda causa posible. En conjunto con los
controles por proceso, la revisión de conexiones y las huellas finales, no se
encontraron indicios de escrituras de QA en desarrollo ni de fallas de aislamiento.
Se clasifica la variación histórica como actividad concurrente compatible con uso
ordinario, sin atribución demostrable, y se mantiene expresamente la salvedad.

En la repetición de cierre, intervalo 2026-11-20 04:57:47–05:03:07 UTC, el binlog
contiene únicamente 56 actualizaciones de sesiones y 50 operaciones de caché.
No aparecen operaciones funcionales, cambios de cuentas, credenciales o permisos.
Las 51 estructuras de desarrollo son idénticas y las únicas huellas de contenido
distintas corresponden a `cache` y `sessions`. Esto no borra ni contradice las
variaciones funcionales del intervalo P1 anterior.

Testing conserva el contenido de sus 51 tablas; 26 contadores AUTO_INCREMENT
avanzaron por las pruebas, sin diferencias estructurales. Configuración y adjunto
privado mantienen huellas, tamaño y fecha de modificación. No se restauraron datos,
sesiones ni contadores, ni se ejecutaron migraciones o seeders de desarrollo.

### Condiciones del respaldo

La salvedad de actividad concurrente no demuestra una alteración de seguridad y no
impide el cierre bajo las instrucciones recibidas. No se afirma conservación
idéntica de todo desarrollo durante el intervalo histórico. El respaldo se limita
al inventario exacto, después de comprobar el diff staged y su whitespace; no
incluye evidencias externas, fixtures, archivos privados ni secretos.

El usuario y Antigravity trabajan de forma independiente en
`D:/projects/plataforma-legal-V2-diseno`, rama `diseno/ajustes-ui`. No se inspeccionó
su contenido, no se modificó ese worktree y no se exige que esté limpio. Tampoco se
intervino en los demás worktrees. El avance de esa rama no se integra en `main`.

Permanecen pendientes la deuda de Pint global ajena y la limitación móvil a 390 px
con texto al 200 %. No se declara accesibilidad global aprobada. No se inicia
09E.2-F, 09F, 09P-A, LEXA ni una optimización general. Este apartado registra la
revisión previa al commit; el hash definitivo del respaldo se verifica y comunica
mediante Git al terminar.
