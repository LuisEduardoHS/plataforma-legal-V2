# ETAPA 09E.2-A — Continuidad del borrador al cargar municipios

Fecha: 19 de noviembre de 2026. Rama: `main`. Base: `54370532733604db9541772ddb0a64f0e20d44e8`.

Alcance exclusivo: hallazgo **09E1-01 (P1)** de la auditoría 09E.1. Sin staging, commit ni push. El resto de los hallazgos, incluido **09E1-04**, queda pendiente.

## 1. Causa y contrato anterior

Los editores `/perfil/editar` y `/perfil/profesional/editar` comparten `pages/profile/edit.blade.php` y `ProfileController::editForm`. El catálogo se cargaba enviando un formulario GET separado con `state_id`. La respuesta reconstruía toda la página consultando los perfiles guardados.

Por ello se perdían nombre público, alias, biografía, presentación, descripción, experiencia, modalidad, contacto, privacidad, sitio web, selecciones e intención de especialidades y cualquier cédula escrita pero no enviada. También se descartaba la selección de fotografía del formulario separado. La advertencia anterior describía la pérdida, pero no la evitaba.

El guardado continúa siendo POST con `_method=PUT`, CSRF y los mismos Form Requests y servicios. El estado del catálogo es una selección de interfaz: se persiste únicamente `municipality_id`.

## 2. Solución con JavaScript

- Se reutilizan **las dos rutas GET existentes**. Con `Accept: application/json` devuelven solo `state_id` y `municipalities: [{id, name}]`, ordenados por nombre. No se incorporan rutas ni una API genérica.
- El identificador es obligatorio, entero y existente para este contrato JSON; los parámetros de consulta adicionales se rechazan con 422 y un mensaje fijo. El GET HTML conserva su contrato previo de estado opcional.
- La rama JSON termina antes de `ownData`: no consulta perfiles, cédulas, especialidades del usuario ni adjuntos. Se mantienen autenticación y cuenta activa; visitante o cuenta inactiva no obtiene el catálogo protegido por estas rutas.
- `profiles/location.js` se monta con `onPage`, desde `personal.js`. Cambiar estado carga automáticamente sus municipios sin navegar ni reemplazar el formulario. El botón permite cargar o reintentar.
- Solo se reemplazan los elementos `option` del municipio. Los demás inputs, textarea, checkboxes y el input de archivo permanecen en el DOM. Los nombres se insertan como texto, sin interpretar HTML.
- El municipio incompatible queda vacío. El aviso accesible permite elegir un municipio válido o guardar explícitamente sin ubicación. Un estado sin municipios también permite guardar sin ubicación.
- Durante carga o error se deshabilitan municipio y guardado del perfil. También se intercepta el submit incompleto: un select deshabilitado no debe omitirse silenciosamente del PUT. Los formularios separados de fotografía no se bloquean.
- `role=status`, `aria-live=polite`, `aria-atomic`, `aria-busy` y `aria-describedby` comunican carga, éxito, vacío y error. Los errores de `municipality_id` se asocian además al select con `aria-invalid` y un ID propio.
- Un HTTP fallido, desconexión o respuesta incoherente conserva los demás campos y ofrece reintento. No se navega automáticamente hacia login, ni se inserta texto de errores del servidor en el formulario.

### Concurrencia, montaje tardío y Turbo

Cada petición tiene `AbortController` y una generación. Una respuesta antigua no puede sustituir el catálogo actual, aunque el transporte ignore la cancelación. Se comprueba también que el estado devuelto coincida y que los identificadores sean enteros positivos y únicos.

Se deduplican cargas del mismo estado en curso y se reutilizan catálogos públicos ya cargados en memoria del controlador. No hay polling ni temporizadores periódicos. Los listeners usan el ciclo de vida abortable existente; la limpieza cancela solicitudes y bloquea sus resultados tardíos.

El estado correspondiente a las opciones renderizadas se identifica mediante `data-location-catalog-state`. No se confunde con un estado elegido antes de que termine de cargar JavaScript. Una instantánea Turbo con catálogo pendiente o fallido queda marcada como no resuelta y vuelve a solicitarlo al montar, en vez de interpretar las opciones vacías como un catálogo válido. El layout existente mantiene su política Turbo `no-cache`.

## 3. Validación y recuperación de old input

La revisión de cierre encontró dos defectos adicionales en la recuperación. El estado de la URL tenía prioridad aunque hubiera quedado obsoleto tras cargar otro catálogo con JavaScript. Además, `ProfileFormRequest::failedValidation` solo conservaba strings: el middleware convierte un municipio vacío en `null`, por lo que se perdía la presencia del campo y se restauraba el municipio persistido. Ambos se reprodujeron en los dos editores antes de corregirlos.

La corrección fue autorizada ampliando el inventario a ocho archivos e incluyendo `ProfileFormRequest.php`. Dentro de su bucle de reglas se conserva `municipality_id => null` únicamente cuando ese campo existe en la petición y en las reglas del formulario. No se incorporan campos desconocidos ni se amplía la recuperación del formulario de fotografía; la cédula sigue excluida.

Regla efectiva de recuperación:

| Caso | Estado y selección |
| --- | --- |
| GET ordinario, sin errores de recuperación | Estado explícito válido del GET; si falta, estado del perfil. Old input sin errores no cambia esa precedencia. |
| Error y municipio válido presente en old input | Su estado real consultado en el catálogo prevalece sobre el estado obsoleto de la URL. Se conserva el municipio y se excluyen las opciones de otros estados. |
| Error y municipio explícitamente vacío | La clave con `null` se conserva y se detecta con `array_key_exists`. El municipio sigue vacío; nunca se sustituye por el persistido. El estado del catálogo puede provenir de la URL o del perfil. |
| Municipio inválido, inexistente o con error de validación | Selección vacía, errores conservados y catálogo coherente con el estado válido del GET o del perfil. No se reconstruye el identificador inválido ni se restaura silenciosamente el municipio guardado. |
| Campo realmente ausente, sin error de municipio | Se mantiene el contrato opcional: ubicación guardada, siempre que pertenezca al catálogo que se muestra. Ausencia no equivale a borrado. |

Se considera recuperación cuando existe un bag de errores. La presencia de la clave se comprueba independientemente de su valor: `hasOldInput(key)` considera `null` como ausencia y no sirve para esta distinción. Blade recibe la selección efectiva resuelta por el controlador y marca explícitamente también la opción vacía. No se cambia la redirección de validación ni se ignora globalmente el `state_id` del GET.

No se añade `state_id` al PUT ni se amplían los campos permitidos. La cédula sigue excluida de old input. Tampoco se modifica la recuperación preexistente de errores de dominio de otros flujos.

## 4. Contrato sin JavaScript y límites

Sin scripts se conserva el GET nativo. **Esta variante no conserva el borrador al recargar y no se declara completamente resuelta.**

Antes de navegar se muestra que la recarga descarta cambios sin guardar, cédula escrita y archivos seleccionados. Es obligatorio marcar una aceptación sin atributo `name`; esta confirmación no viaja en la URL. El botón se llama **Cargar municipios y recargar**.

**Guardar primero mis cambios** pertenece al formulario principal mediante `form=profile-edit-form`: utiliza su validación, CSRF, PUT y reglas de dominio habituales. No guarda implícitamente ni envía datos por GET. Se recomienda guardar, volver al editor y cargar el catálogo antes de continuar. La fotografía conserva su formulario de guardado separado.

Con JavaScript se oculta este aviso y se desactiva la aceptación nativa. Si la mejora no puede montarse, permanece disponible la alternativa HTML.

Otros límites deliberados:

- Recargar o abandonar la página todavía descarta lo no guardado. No hay recuperación persistente de borradores.
- Una sesión expirada puede impedir cargar o guardar; el error no abandona el formulario. La orientación sugiere copiar privadamente el texto antes de volver a entrar. No recopila ni copia automáticamente texto, credenciales o archivos.
- Si el municipio recuperado es vacío o inválido, no puede inferirse el último estado transitorio elegido: el catálogo utiliza el estado válido de la URL o del perfil. La selección permanece vacía y los demás campos recuperables se conservan.
- Volver a un catálogo en memoria puede requerir elegir nuevamente municipio; la interfaz lo indica. El catálogo público se renueva al abrir otra página del editor.
- No cambia la intención de especialidades ni se resuelve 09E1-04. Tampoco se cambia el significado material de la ubicación al guardar una presentación verificada.

## 5. Seguridad y preservación de contratos

La petición del catálogo es GET del mismo origen, sin cuerpo y con un único parámetro `state_id`. No se serializa el formulario, no se lee el archivo y no se transmiten biografía, contacto, cédula ni otros campos privados. Las cookies de autenticación siguen el comportamiento normal del middleware web existente; el controlador no añade escrituras de sesión ni persistencia propia.

Las respuestas JSON incluyen `Cache-Control: private, no-store` y `Vary: Accept`; el middleware autenticado conserva además su protección de caché. Las respuestas inválidas no reflejan valores recibidos. Los errores SQL conservan la sanitización existente.

No se utilizan localStorage, sessionStorage, cookies ni tablas para almacenar borradores. La caché en memoria contiene exclusivamente identificadores y nombres públicos de municipios.

No se modifican servicios, reglas de validación de los Form Requests, políticas, permisos, roles, propiedad, privacidad independiente, especialidades activas/inactivas, límites de archivos ni campos materiales de verificación. El único ajuste al Form Request conserva la presencia del municipio vacío en el mecanismo existente de old input. Cargar el catálogo no guarda ni invalida una verificación.

## 6. Aislamiento y conservación

Antes de fixtures, un bootstrap externo ejecutó una petición HTTP real de lectura a `/ayuda`, con SQL de sesión en modo solo lectura, y verificó dentro del proceso:

| Control | Resultado |
| --- | --- |
| APP_ENV | `testing` |
| Driver | MySQL |
| Base configurada y `SELECT DATABASE()` | `plataforma_legal_v2_test` |
| Caché y sesiones | `array`, aisladas de desarrollo |
| Almacenamiento y disco privado | Directorio externo de QA, fuera del repositorio |
| Migraciones | 33; sin ejecutar migraciones ni seeders por consola |
| Preflight HTTP | 200; sin fixtures ni escrituras SQL |

Los nuevos tests HTTP crean exclusivamente datos sintéticos dentro de transacciones revertidas. La suite completa usa su aislamiento y fixtures existentes. No se ejecutaron comandos de migración, seeding ni bootstrap operativo.

Para navegador se inició un servidor externo en loopback con los controladores, middleware, vistas y build reales. Un preflight HTTP separado confirmó los mismos controles **antes** de crear fixtures. Cada petición de página/catálogo creó su escenario sintético dentro de una transacción y lo revirtió al terminar. No se importaron cookies de desarrollo; POST, PUT y DELETE quedaron bloqueados. La fotografía seleccionada fue un PNG sintético externo, nunca enviado.

El servidor de laboratorio y la pestaña temporal se cerraron y el viewport se restableció. Los helpers, capturas, métricas y fixtures de QA permanecen fuera del repositorio.

## 7. Regresiones y validaciones

### Laravel: renderizado y HTTP reales

`ProfileLocationIntegrationTest.php` contiene **26 pruebas focalizadas (481 assertions)**: las nueve iniciales y 17 regresiones adicionales de recuperación. Las nueve iniciales cubren:

1. Ambas rutas sirven exclusivamente el catálogo público, sin consultas a tablas de perfiles, especialidades del perfil ni adjuntos y sin cambios en perfiles persistidos.
2. Orden alfabético y estado sin municipios.
3. Identificadores inválidos, ausentes, arrays, inexistentes y parámetros adicionales rechazados, sin reflejar datos privados.
4. Visitantes y cuentas inactivas denegados.
5. GET HTML sin municipio incompatible, aceptación nativa y botón de guardar asociado al PUT/CSRF real.
6. PUT personal inválido seguido de GET: biografía y municipio del nuevo estado recuperados; datos persistidos intactos.
7. PUT profesional inválido seguido de GET: descripción, municipio, especialidades e intención recuperados; cédula nunca flasheada ni renderizada con valor.
8. Errores de municipio asociados al select real.
9. Catálogo seguido de PUT válido conserva el contrato y guarda el municipio elegido.

Las nuevas regresiones permanentes cubren, parametrizadas para ambos editores:

- `?state_id=A` → consulta de B → PUT con municipio de B y error ajeno a ubicación → GET de retorno con B y su municipio seleccionados. Ausencia de opciones de A, borrador completo, especialidades, intención y contacto conservados; cédula excluida de old input y del HTML. Después se corrige el error y se guarda B mediante el PUT real. Una navegación normal posterior vuelve a respetar el estado explícito.
- Municipio enviado vacío → error de otro campo → clave presente con `null` en old input → opción vacía seleccionada sin restaurar el municipio persistido. El PUT corregido guarda efectivamente `null`.
- Campo verdaderamente ausente → error → clave ausente y ubicación persistida conservada; el PUT posterior sin el campo tampoco la borra.
- GET ordinario con estado explícito válido, tanto sin old input como con old input sin errores, respeta el catálogo solicitado y excluye municipios incompatibles.
- Identificador negativo, inexistente, texto y array: selección vacía, error asociado mediante ARIA y sin restauración automática de la ubicación guardada.
- El consumidor compartido `ProfilePhotoRequest` no recupera `municipality_id` fuera de sus propias reglas ni altera la ubicación.

Se comprobaron respuestas HTML reales, selección única incluida la opción vacía, coherencia de `data-location-catalog-state`, etiquetas, referencias ARIA e IDs únicos. Antes de aplicar la lógica, el conjunto focalizado registró 14 fallidas y 12 aprobadas; después, **26 aprobadas, 481 assertions, 5.40 s**. Las reproducciones que bloquearon el cierre ya no se consideran pendientes ni se dan por aprobadas por sus resultados anteriores: ahora tienen cobertura permanente y pasan.

### Frontend

`profile-location.test.mjs` añade doce pruebas de comportamiento: conservación de controles y referencia del archivo, petición mínima, vacío, cambios rápidos, respuestas fuera de orden aunque no se respete abort, deduplicación, reutilización de catálogo, HTTP fallido, desconexión, reintento, validación de respuesta, bloqueo de submit incompleto, limpieza, montaje tardío y ciclo real de `onPage` con instantánea no resuelta. No se limitan a buscar cadenas en Blade.

### Resultados finales

| Validación | Resultado |
| --- | --- |
| Laravel completa | 699 pruebas, 15,337 assertions, aprobadas; última ejecución 160.95 s |
| Laravel focalizada de ubicación | 26 pruebas, 481 assertions, aprobadas antes de la suite completa |
| Frontend completa | 69 pruebas, aprobadas; referencia anterior 57 |
| Build Vite | Aprobado, 99 módulos; sin dependencias nuevas |
| Pint afectado | Aprobado en controlador, `ProfileFormRequest` y test PHP afectados |
| Sintaxis PHP | 361 archivos incluidos PHP y plantillas Blade, sin errores |
| Rutas | 109; ninguna ruta añadida ni duplicada |
| Whitespace | Versionados y nuevos comprobados, sin incidencias |

La deuda preexistente de Pint global (14 archivos ajenos) no se formateó. El resultado anterior corresponde únicamente al PHP afectado; no constituye aprobación global de Pint.

## 8. QA de navegador

Se verificaron **20 combinaciones finales**: los dos editores × 320, 390, 768, 1024 y 1440 px × claro/oscuro. Se conservaron texto y controles, con el municipio incompatible vacío, guardado disponible al terminar, sin IDs duplicados y sin desbordamiento horizontal. En profesional se comprobaron también experiencia, modalidad, presentación, especialidad, intención y el campo de cédula sin enviar.

La matriz anterior corresponde a la implementación inicial. Tras corregir la recuperación se verificaron **40 combinaciones adicionales**: dos editores × municipio válido recuperado/vacío explícito × los cinco anchos × ambos temas. En todos los casos se comprobó la selección inicial correcta, coherencia del estado, ausencia de IDs duplicados y desbordamiento; luego se cambió el catálogo con JavaScript conservando borrador, cédula recién escrita, especialidad e intención. Se revisaron las cuarenta capturas y cuatro hojas de contacto.

Para estas comprobaciones visuales el router externo creó exclusivamente old input y errores sintéticos en la sesión de memoria y ejecutó el GET real del controlador. Los ciclos completos de PUT inválido → redirección → GET → PUT corregido se probaron en PHPUnit sobre HTTP/Blade reales; **no se atribuye al navegador la ejecución de esos PUT**. El servidor visual continuó bloqueando escrituras HTTP y revirtiendo sus fixtures de cada petición.

Se comprobaron además municipio inválido y campo ausente en ambos editores, cuatro flujos de recuperación sin scripts (válido/vacío, ambos editores, 390 px y tema oscuro), tres regresos por enlaces Turbo con una petición por cambio, y carga/error 503/reintento con teclado tras recuperar el formulario. El texto y la selección del PNG sintético permanecieron intactos durante el fallo y el reintento. Los controles nativos exigieron aceptación antes de recargar; Guardar primero respetó la validación del formulario principal. El viewport y la pestaña temporal se cerraron/restablecieron al terminar.

Comprobaciones adicionales:

- Biografía, nombre público, alias y sitio web no enviados; fotografía sintética seleccionada antes y después de cargar otro estado (`C:\fakepath\synthetic.png`).
- Especialidad e intención tanto marcadas como desmarcadas, sin cambios por cargar municipios.
- Flechas del select, Tab y Enter; foco visible de 2 px; carga con `aria-live=polite`, `aria-busy=true`, municipio y guardado deshabilitados.
- Error HTTP 503 introducido por el servidor de laboratorio; borrador intacto y reintento exitoso con teclado. No se modificó el backend de producto para inyectar fallos.
- Estado vacío y regreso rápido al catálogo anterior; las respuestas fuera de orden se verifican además de forma determinista en frontend, porque el servidor PHP de laboratorio procesa solicitudes secuencialmente.
- Tres regresos por enlaces de Perfil/Turbo: **1 petición de catálogo por cambio en cada regreso**, sin listeners duplicados. Dos submits sobre un catálogo ya cargado: **0 peticiones adicionales**.
- Ambos editores servidos sin scripts de producto: aviso visible, navegación bloqueada antes de aceptar, recarga aceptada enviando solo `state_id` y pérdida explícitamente esperada del borrador. Guardar primero respeta la validación HTML del formulario principal: una URL inválida no genera petición.

Las capturas finales se revisaron individualmente y en hojas de contacto. Los elementos fijos de navegación pueden aparecer en medio de capturas de página completa realizadas después de desplazar el viewport; no implican que el formulario haya sido reemplazado.

Incidencias de QA resueltas fuera del producto: el forzado inicial del tema oscuro no alcanzaba URLs absolutas de CSS; se corrigió el router externo y se repitió la matriz. También se dejó de variar la URL del JavaScript entre temas para no evaluar dos veces el mismo bundle durante Turbo. Las veinte combinaciones finales utilizan el build definitivo y el tema comprobado por estilo calculado.

## 9. Rendimiento de laboratorio

Medición inicial: 59 respuestas JSON exitosas, catálogo sintético de un municipio o vacío, en MySQL testing local:

| Medida | Resultado |
| --- | --- |
| Catálogo con un municipio | 68 bytes de JSON, sin comprimir |
| Catálogo vacío | 35 bytes |
| Tiempo HTTP instrumentado | Mediana 89.39 ms; rango 85.87–99.25 ms |
| Consultas de catálogo/autenticación | 3 por respuesta exitosa; mediana SQL 1.02 ms |
| Consultas a perfiles/adjuntos desde el catálogo | 0 |
| Cuerpo de solicitud de catálogo | 0 bytes; único parámetro `state_id` |
| HTML completo de edición, como contraste local | Mediana 19,617 bytes y 137.64 ms |
| Solicitudes por nuevo estado no cargado | 1; sin polling |
| Repetir carga ya resuelta | 0 adicionales |

El cronómetro abarca el manejo HTTP después de bootstrap y creación de fixtures; excluye su coste y las demoras artificiales de fallo. No mide navegación completa, red pública, catálogos grandes ni producción y no representa un SLA. La comparación HTML combina ambos editores.

Bundle `resources/js/app.js`, compresión reproducible gzip nivel 9:

| | Antes | Después | Delta |
| --- | ---: | ---: | ---: |
| Sin comprimir | 133,415 B | 136,827 B | +3,412 B |
| gzip 9 | 47,034 B | 48,143 B | +1,109 B |

El CSS de profesionales permanece en 21,426 B sin comprimir y 4,079 B gzip 9. No se cambian dependencias, configuración Vite, CSS ni otras pantallas. Los tamaños gzip de Vite pueden diferir por nivel de compresión.

Recomprobación tras corregir la recuperación: **44 respuestas JSON exitosas**, todas de un municipio, 68 B; mediana HTTP instrumentada **90.45 ms**, rango 87.11–129.46 ms. Tres consultas por respuesta, cero consultas privadas, cuerpo de petición de cero bytes y únicamente `state_id` en la consulta. El bundle se mantiene exactamente en 136,827 B / 48,143 B gzip 9: la corrección de recuperación no añade JavaScript ni CSS. Siguen siendo mediciones de laboratorio con fixtures sintéticos, excluidos bootstrap, creación de fixtures y demora artificial.

## 10. Inventario exacto

**8 archivos: 4 modificados y 4 nuevos. Ningún eliminado.** La ampliación fue autorizada expresamente para preservar el municipio vacío en `ProfileFormRequest`.

| Estado | Archivo | Motivo |
| --- | --- | --- |
| Modificado | `app/Http/Controllers/Profiles/ProfileController.php` | Catálogo JSON mínimo y reconstrucción de estado desde old input |
| Modificado | `app/Http/Requests/Profiles/ProfileFormRequest.php` | Conservar municipio vacío sin confundirlo con un campo ausente |
| Modificado | `resources/js/personal.js` | Montaje del controlador de ubicación con el ciclo de vida existente |
| Modificado | `resources/views/pages/profile/edit.blade.php` | Mejora progresiva, alternativa nativa explícita y asociaciones accesibles |
| Nuevo | `resources/js/profiles/location.js` | Actualización localizada, concurrencia, errores y limpieza |
| Nuevo | `tests/Feature/ProfileLocationIntegrationTest.php` | Regresiones de HTTP y renderizado real |
| Nuevo | `tests/frontend/profile-location.test.mjs` | Regresiones de interacción, red y ciclo de vida |
| Nuevo | `docs/ux-ui-integration-09e2-a.md` | Contrato, evidencia, mediciones, resultados e inventario |

## 11. Estado final de Git y cierre

`main` conserva el HEAD base. Staging vacío: no se realizó `git add`, commit, push, merge ni cambio de rama. El árbol contiene exclusivamente los ocho archivos de este inventario. Los assets de build son salida local ignorada, no archivos del inventario.

En la implementación inicial, antes de las correcciones de recuperación, se compararon hashes de todas las filas y estructuras de las 51 tablas de cada base, sin extraer contenido privado. Se conserva este resultado histórico:

- Desarrollo: estructuras y recuentos de las 51 tablas iguales; contenido de 50 tablas idéntico, incluidos perfiles, cuentas, permisos, roles, migraciones y auditoría.
- **Incidencia observada:** cambió el hash de `sessions`, conservando su recuento. Lectura acotada del binlog ROW/FULL entre 06:47:43 y 07:11:30 UTC del 19 de noviembre encontró exactamente tres UPDATE sobre la misma sesión anónima: 06:49:00, 06:51:41 y 07:01:53 UTC. Cambió exclusivamente `last_activity`; el payload permaneció igual. No se observaron INSERT, DELETE, cambios de autenticación ni escrituras funcionales de desarrollo en ese intervalo. Esta evidencia **no identifica al usuario, proceso o navegador que originó la actividad**. Los procesos de pruebas acreditaron MySQL testing y sesiones/caché `array`. No se restauró ni alteró la sesión.
- Testing: filas y hashes de contenido idénticos en las 51 tablas, sin fixtures persistidos. Las 51 estructuras se comprobaron conservadas: en 26 tablas la diferencia del `SHOW CREATE TABLE` se explicó exclusivamente por avance de AUTO_INCREMENT, contrastando el hash original al sustituir únicamente ese contador en memoria. No se ejecutó SQL para restablecerlo.
- Ambas bases conservan 33 registros de migraciones. `.env`, `.env.testing`, `public/hot` y el adjunto privado existente conservan hashes; el adjunto conserva también tamaño y fecha de modificación. Lista/HEAD de worktrees y estado de los otros worktrees sin cambios.

La variación de actividad se informa expresamente; no se afirma una igualdad total de datos de desarrollo. Tampoco se atribuye a las pruebas ni se oculta como una conservación exacta de sesiones.

### Conservación tras la corrección final autorizada

En esta intervención se obtuvo una nueva instantánea antes de editar y ejecutar fixtures. La comparación posterior conserva exactamente las estructuras de las 51 tablas de desarrollo y el contenido de las otras 50 tablas, incluidos usuarios, perfiles, permisos, roles, auditoría y migraciones. Se conserva también el contenido persistido de las 51 tablas de testing; las únicas diferencias de su DDL son avances de 26 contadores AUTO_INCREMENT, contrastados contra los hashes originales sin ejecutar SQL de restauración. Ambas bases mantienen 33 registros de migraciones.

Se detectó una nueva variación de actividad de desarrollo: `sessions` pasó de **5 a 6 filas**. La lectura acotada del binlog ROW/FULL entre **17:46:35 y 18:00:57 UTC del 19 de noviembre de 2026** muestra un INSERT de sesión anónima a las **17:48:04**, seguido por dos UPDATE exclusivamente de `last_activity` a las **17:48:47** y **17:52:17**, con payload igual entre actualizaciones. No aparecen cambios en las cinco sesiones preexistentes ni en tablas funcionales, credenciales o permisos en ese intervalo. La evidencia no identifica qué usuario, proceso o navegador originó esa actividad; no se atribuye ni se restaura. Los eventos preceden al primer preflight HTTP del servidor visual de esta ronda; los procesos de pruebas acreditaron testing y sesiones/caché `array` antes de escribir fixtures.

El adjunto privado existente conserva hash, tamaño y fecha de modificación. `.env`, `.env.testing`, `public/hot`, la lista/HEAD de los worktrees y los estados de los otros dos worktrees permanecen intactos. No se afirma que todos los datos de desarrollo sean idénticos: la variación operativa de sesiones se informa como excepción. No se modificaron archivos ajenos al inventario ni los 14 archivos de deuda Pint.

La entrega se detiene en revisión de resultados. No se inicia otra etapa.
