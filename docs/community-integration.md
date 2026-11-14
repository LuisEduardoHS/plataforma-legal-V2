# INTEGRACIÓN-03 — Comunidad funcional

Base: `main`, `3a607bddf03891b1aaeef6817ae695e868115a82`. Fecha: 2026-11-14.

## 1. Inspección y arquitectura

Git estaba limpio en la rama y commit esperados. Se revisaron rutas, vistas,
componentes, JavaScript, CSS, modelos, enums, políticas, servicios y documentación
de Comunidad, adjuntos privados, perfiles y autenticación antes de implementar.

HTTP → Form Requests → controladores → servicios de BACK-05/BACK-02 → MySQL.
`PostService`, `CommunityInteractionService`, `CommunityMediaService`,
`CommunityAccess`, `AttachmentService`, políticas y estados conservan su lógica
de escritura auditada. No hay migraciones, dependencias nuevas ni segundo almacén
de publicaciones. Permanecen las 32 migraciones existentes.

- `FeedController`: recientes, siguiendo, propias, revisión y publicaciones por autor.
- `PostController`: detalle, formularios, borrador, edición, publicación, moderación
  y borrado lógico. `CommentController`: creación, respuesta, edición y moderación.
- `InteractionController`: Me gusta, seguimiento y conexiones. `MediaController`:
  descarga por contexto; no descarga genérica por ID de adjunto.
- `PostSubmission`: coordinación de carga fuera de transacción, escritura atómica
  mediante servicios y compensación de los archivos nuevos ante un fallo.
- `CommunityPresentation`: dos consultas por lote para estado de reacción y seguimiento.
- `CommunityQuery`: se amplía de forma compatible para gestión de publicaciones
  propias/revisión por ID, revisión autorizada de comentarios, resumen de conexiones,
  metadatos de archivo y capacidades de acción. No duplica las escrituras del dominio.

Diferencias comprobadas respecto a la demo:

| Interfaz anterior | Integración real |
| --- | --- |
| Para ti, recientes y sin respuesta, filtrados en memoria | Recientes, siguiendo y propias; categoría, especialidad y etiqueta en SQL. No existe ranking ni filtro sin respuesta en BACK-05 |
| Título, biografía y verificación profesional simulados | Texto único de publicación, identidad pública permitida; sin insignias inventadas |
| Categorías/actividad/conteos de ejemplo | Catálogos existentes, conteos SQL, sidebar con navegación real |
| Publicar deshabilitado y borrador solo local | Borrador persistente y publicación autorizada |
| DOCX y videos de 50 MB; total local de 80 MB | Solo formatos reales; videos de 100 MiB, imágenes de 10 MiB, PDF/TXT de 20 MiB y cuatro archivos combinados |
| Previews inline y blob URLs | Tarjetas de archivo con metadatos y descarga por contexto |
| Diálogo local de detalle y guardado simulado | Detalle enlazable y comentarios persistentes. No se conecta Guardados en esta fase |

Se preservan layout, navegación, paleta, DM Sans, superficies, tarjetas, compositor
progresivo con diálogo, responsive, modo oscuro y transiciones. Los mocks de otras
secciones no se modifican; los fixtures demo de Comunidad quedan fuera del flujo activo.
El header reconoce las subrutas de Comunidad. En perfiles se añaden únicamente
los enlaces a publicaciones/conexiones; no se cambia su privacidad.

## 2. Rutas HTTP

| Método | Ruta | Nombre | Acceso |
| --- | --- | --- | --- |
| GET | `/comunidad` | `community` | Recientes públicos; otras pestañas autenticadas |
| GET | `/comunidad/crear` | `community.create` | `posts.create` |
| POST | `/comunidad/publicaciones` | `community.posts.store` | `posts.create` |
| GET | `/comunidad/publicaciones/{post}` | `community.show` | `PostPolicy::view` |
| GET | `/comunidad/publicaciones/{post}/editar` | `community.edit` | `PostPolicy::update` |
| PUT | `/comunidad/publicaciones/{post}` | `community.posts.update` | `PostPolicy::update` |
| POST | `/comunidad/publicaciones/{post}/publicar` | `community.posts.publish` | Reglas existentes de publicación |
| DELETE | `/comunidad/publicaciones/{post}` | `community.posts.destroy` | `PostPolicy::delete` |
| POST | `/comunidad/publicaciones/{post}/moderacion` | `community.posts.moderate` | `posts.moderate` |
| GET | `/comunidad/publicaciones/{post}/archivos/{attachment}` | `community.media` | Asociación y publicación accesibles |
| POST | `/comunidad/publicaciones/{post}/comentarios` | `community.comments.store` | `comments.create` e interacción autorizada |
| GET | `/comunidad/comentarios/{comment}/editar` | `community.comments.edit` | Edición e interacción autorizadas |
| PUT | `/comunidad/comentarios/{comment}` | `community.comments.update` | Propietario o moderador de comentarios |
| DELETE | `/comunidad/comentarios/{comment}` | `community.comments.destroy` | `CommentPolicy::delete` |
| POST | `/comunidad/comentarios/{comment}/moderacion` | `community.comments.moderate` | `comments.moderate` y acceso al post |
| PUT | `/comunidad/publicaciones/{post}/reaccion` | `community.react` | Cuenta activa y acceso para interactuar |
| DELETE | `/comunidad/publicaciones/{post}/reaccion` | `community.unreact` | Cuenta activa y acceso para interactuar |
| GET | `/comunidad/usuarios/{user}` | `community.user` | Publicaciones públicas; propias privadas si corresponde |
| GET | `/comunidad/usuarios/{user}/conexiones` | `community.connections` | Cuenta activa; destino propio o con perfil personal público |
| PUT | `/comunidad/usuarios/{user}/seguir` | `community.follow` | Otra cuenta activa |
| DELETE | `/comunidad/usuarios/{user}/seguir` | `community.unfollow` | Relación propia; permite destino desactivado |

Todos los writes requieren autenticación, cuenta activa y CSRF. Los IDs de recurso
son enteros decimales positivos de hasta 18 dígitos en estas rutas; no son permisos.
El middleware de INTEGRACIÓN-01 vuelve a comprobar la cuenta persistida y revoca
sesiones de cuentas inactivas. No hay bypass automático de administrador.

## 3. Contratos de formularios y respuestas

Publicaciones: `content` (hasta 10,000 caracteres), `visibility=private|public`,
`content_category_id`, `legal_specialty_id`, `tag_ids[]` (hasta 20),
`attachment_ids[]` y `uploads[]`. Hasta cuatro archivos entre conservados y nuevos.

`intent=draft|publish` en creación; `intent=save|publish` en edición. La creación
siempre pasa por un borrador; publicar exige texto no vacío o archivos válidos.
Se publica la versión devuelta por la escritura que ya comprobó la versión del
cliente, dentro de la misma transacción. No se refresca una versión para saltar
un conflicto. Editar contenido oculto mantiene ese estado.

`replace_tags=1` y `replace_attachments=1` son controles de formulario: si se envían
sin selección, se vacían esas relaciones. Si se omiten, se conservan salvo que se
mande explícitamente un array nuevo. Cargas nuevas sin reemplazo conservan los
adjuntos existentes y añaden los nuevos, sujeto al mismo límite. Desmarcar un archivo
lo desasocia. Los IDs nuevos o históricos pasan siempre por las reglas del servicio.
Una persona moderadora puede editar texto autorizado, pero no asociar archivos propios
a un post ajeno: la propiedad del archivo debe coincidir con el autor.

Edición, publicación, borrado y moderación requieren `version`, entero positivo
hasta 2,147,483,647. Es exclusivamente la versión esperada. La UI conserva esa versión
original incluso tras un error de validación de otro campo, también al recuperar
texto de old input; evita sobrescribir una edición concurrente en el reintento.
Una versión de formato inválido devuelve 422 y exige reabrir el contenido; un
conflicto del servicio devuelve 409, sin escritura ni actualización silenciosa.

Comentarios: `content` hasta 5,000 caracteres; `parent_id` opcional solo al crear.
Edición requiere `version`. Moderación de post/comentario admite únicamente la
acción `hide|show` y una versión esperada; no permite asignar estados arbitrarios.
Reacción y seguimiento no aceptan datos de negocio en el body: el tipo es solo like.
Los campos internos, autor, permisos, roles, contadores y marcadores arbitrarios
se rechazan. `_token` y `_method` son exclusivamente controles HTTP.

Formularios HTML usan POST/method spoofing y redirección con mensajes de sesión.
Los enlaces de crear/detalle funcionan sin JavaScript; con JS, crear abre un diálogo
con el mismo formulario real. No hay fetch obligatorio ni estado persistente en
localStorage para estas operaciones. El texto recibido del handoff existente solo
rellena un formulario disponible; nunca se publica automáticamente.

Los errores de validación conservan únicamente campos acotados y arrays planos de
IDs permitidos, sin archivos. Los archivos deben seleccionarse de nuevo. La UI usa
texto escapado/textContent y no interpreta HTML del usuario. JavaScript proporciona
contador, validación temprana de archivos, nombres sin preview y bloqueo de doble
envío en la misma página sin eliminar el intent del submitter.

GET entrega Blade sobre DTO explícitos. Para `Accept: application/json`, las
mutaciones devuelven 201 al crear post/comentario y 200 en las demás acciones:
`message`, `redirect` y solo los escalares necesarios (`id`, `version`, `status`,
`liked` o `following`). Nunca devuelven un modelo Eloquent completo. Los conteos
se leen de SQL al volver a la página. Los errores JSON son 401/403/404/409/422/429/503
según el caso; HTML muestra una respuesta controlada o errores de formulario.

## 4. Privacidad, autorización y aprovisionamiento

Recientes y siguiendo solo incluyen públicos publicados de cuentas activas. Seguir
no abre borradores, ocultos ni privados. Los privados pertenecen al autor y a quien
ya tenga `posts.moderate`, según BACK-05. Propias incluye borradores, ocultos y privados
solo de la sesión; revisión requiere explícitamente `posts.moderate` y está separada
del feed público. El borrado es lógico, sin restauración ni eliminación física HTTP.

Los DTO de autores contienen ID público de navegación, nombre/alias permitido,
username, indicador de perfil público y actividad. Un perfil personal privado o ausente
se presenta como «Usuario», incluso en una publicación pública; una cuenta inactiva
no obtiene enlace público ni publica identidad desde una revisión autorizada.
No se exponen correo de autenticación, contraseña, cédula, documentos de verificación,
paths, disco, hash o nombres físicos. Los booleanos de acción provienen de políticas,
no son listas de roles ni decisiones aceptadas del cliente. Las escrituras reautorizan
bajo los bloqueos del dominio aunque la UI hubiera mostrado una acción previamente.

Conexiones y sus conteos se consultan autenticado para uno mismo o para un destino
activo con perfil personal público. Las cuentas inactivas quedan fuera del conteo y
la lista. Las identidades privadas se anonimizan. El servicio permite dejar de seguir
un destino desactivado con un ID ya conocido, aunque las listas solo muestran activos.

Permisos existentes: `posts.create`, `posts.update.own`, `posts.delete.own`,
`posts.moderate`, `comments.create`, `comments.moderate`. Los comentarios propios
se editan/eliminan según su política; no se inventan permisos de comentarios propios.
Reaccionar y seguir solo requieren actividad y acceso al destino.

Se comprobó por SELECT en desarrollo, sin escrituras: `user` ya posee create/update.own/
delete.own de posts y comments.create; `moderator` y `admin` poseen además posts.moderate
y comments.moderate. INTEGRACIÓN-01 asigna el rol convencional existente al registrar.
No se invocan seeders ni se conceden permisos desde HTTP. El seeder auditado aprovisiona
roles nuevos y respeta concesiones/revocaciones previas; no se usa para restaurar permisos
automáticamente. Si falta un permiso, la operación falla y requiere administración explícita.

Las respuestas de lectura y errores controlados llevan `private, no-store`; el layout
existente impide caché de instantáneas Turbo. No se promete revocación retroactiva de
píxeles o descargas ya autorizadas. Las denegaciones de detalle, archivos y conexiones
se muestran como contenido no disponible (404); escrituras prohibidas como 403.
SQL, errores del dominio y del almacenamiento producen mensajes genéricos 503 incluso
con debug, usando el reportero SQL sanitizado existente. Los manejadores añadidos se
limitan a las rutas de Comunidad y no alteran errores de otros módulos.

Límites: lecturas públicas/contextuales 120/min/IP; escrituras 60/min/cuenta; además,
creación/edición de posts 10/min/cuenta, con o sin archivos. Las pruebas verifican el
bloqueo sin escrituras adicionales. El caché/almacén de límites sigue la configuración
existente y debe ser compartido entre instancias en producción.

## 5. Adjuntos y fallos parciales

`AttachmentService` valida MIME real Fileinfo, extensión, tamaño y almacenamiento
privado. Comunidad admite image/video/document: JPG/JPEG, PNG, GIF, WebP, MP4, WebM,
PDF y TXT. MP3/otros tipos de BACK-02 no se asocian a este módulo. No se crean formatos,
límites o un total de 80 MB que el backend no defina. PHP, servidor y proxy pueden
imponer límites menores; deben dimensionarse para el despliegue antes de aceptar
los tamaños máximos. No se modificaron php.ini ni configuraciones del servidor.

Se autoriza antes de la carga física. Las reservas se confirman fuera de una transacción
ambiental; luego create/update/publish y pivotes comparten una transacción. Fallar al
publicar no deja un borrador parcial nuevo. Fallar una segunda carga o la asociación
solicita eliminación lógica solo de objetos nuevos; los conservados permanecen intactos.
La prueba de edición concurrente confirma que tampoco se sobrescribe el contenido actual.
Si también falla la compensación, queda la reserva durable para conciliación operativa.

No se aceptan archivos ajenos, pendientes/no disponibles, retenidos ni asociados a
evidencias de verificación. Las marcas `community_published_at` y el bloqueo de evidencia
se conservan. Quitar un archivo o borrar lógicamente un post no purga objetos; la retención
y referencias de otros módulos siguen aplicándose mediante los servicios auditados.

El nombre original se incluye como metadato textual escapado; el formulario advierte
que se comparte según la visibilidad del post. `CommunityMediaService` vuelve a autorizar
post y asociación, comprueba propietario, estado, retención, disco privado y hash/tamaño;
crea un buffer temporal de streaming y entrega `application/octet-stream`, disposición
de descarga, `nosniff`, CSP sandbox y `private, no-store`. Un adjunto no adquiere acceso
público fuera de su post. No se debilita AttachmentPolicy ni se ejecuta storage:link. Se añade una exclusión
Git específica para storage/app/attachments: los uploads privados no deben aparecer
como candidatos a versionar, independientemente de su autorización HTTP.

No hay img/video/iframe/blob inline, miniaturas, reproductores, antivirus/cuarentena,
procesamiento de derivados ni rangos de video. available no certifica ausencia de malware.
Son pendientes de una fase explícita de multimedia segura.

## 6. Comentarios, reacciones y seguimiento

Raíz y una sola respuesta. El padre debe ser visible, activo, del mismo post y sin
padre propio. No hay relaciones entre publicaciones. Edición mantiene ocultamiento;
borrado y moderación respetan permisos y versión. Ocultar/eliminar una raíz o desactivar
su autor oculta las respuestas y las excluye del contador público.

La lectura normal solo expone comentarios visibles. `review=1` requiere comments.moderate
y acceso al post, e incluye ocultos para poder revisarlos y restaurar su visibilidad.
No restaura borrados. Los comentarios se ordenan por ID ascendente y paginan por cursor;
las respuestas llevan sangría e identifican la raíz, que puede estar en otra página.
No se carga un árbol completo ni se hacen consultas por comentario. El propietario puede
eliminar por HTTP un comentario propio aunque su post ya no sea accesible, como permite
el servicio; no se añade un historial personal de comentarios ajeno a las consultas actuales.

PUT Me gusta y DELETE retirarlo son idempotentes; no existe un toggle que invierta el
resultado ante un reintento ni otros tipos de reacción. Lo mismo ocurre con seguir/dejar
de seguir; no se permite seguirse ni seguir un destino inactivo. Los conteos excluyen
cuentas inactivas. Crear posts/comentarios no tiene clave de idempotencia persistente
en BACK-05: JS evita doble clic local, pero no se promete deduplicación de reintentos
independientes. Los archivos nuevos tampoco se deduplican por hash global.

## 7. Cursores, filtros y concurrencia

Feeds recientes/siguiendo: published_at DESC, id DESC. Propias/revisión: id DESC,
para incluir borradores sin fecha de publicación. Publicaciones por autor mantienen
el contrato de BACK-05 (publicadas públicas y, para el propietario, privadas).
Comentarios/conexiones: id ASC. Páginas de 20, dentro del máximo de 50 existente;
no se usa OFFSET ni se cuenta/carga toda la tabla. Se validan tamaño/tipo/forma de
cursores; cambiar el cursor nunca elimina restricciones de autorización.

Filtros: category_id, specialty_id y tag_id. Las asociaciones nuevas exigen catálogos
activos; los históricos retenidos se pueden conservar y filtrar. El formulario incluye
sus opciones históricas para que editar otro campo no las elimine accidentalmente.
Los enlaces Anterior/Siguiente preservan filtros; comentarios usan comment_cursor.

Eager loading para autor/perfil/catálogos/etiquetas/adjuntos, subconsultas para conteos
y consultas por lote para estados. La prueba HTTP compara 1 y 21 posts y mantiene
el mismo número de consultas, con máximo 15 en ese escenario. Las nuevas inserciones
no duplican la siguiente página. No se añaden cachés de publicaciones que puedan
servir privacidad obsoleta ni motores de ranking/búsqueda paralelos.

Los servicios conservan locks y comparación optimista. No hay reintentos automáticos
que repitan I/O físico ni refrescos de versión después de un conflicto. Deadlock/timeout
revierte la escritura y muestra error controlado. El usuario debe reabrir y revisar
la versión actual tras un 409. Se prueba también el reintento tras validación fallida.

## 8. Validación

Antes de fixtures: testing + MySQL + nombre configurado y SELECT DATABASE() iguales a
plataforma_legal_v2_test. El guard central Tests\TestCase se conserva. No se ejecutaron
migraciones ni seeders de desarrollo; el aprovisionamiento de permisos se inspeccionó
solo con SELECT. Las pruebas usan transacciones o fixtures confirmados para uploads,
con limpieza selectiva de sus propias filas y Storage::fake.

- Laravel completo: **449 pruebas aprobadas, 11,688 assertions**, 102.81 s (`php artisan test --compact`). Referencia anterior: 396 pruebas y 11,096 assertions.
- Nuevas pruebas HTTP: 53 casos, 592 assertions en ejecución focalizada, incluidos
  datasets: feed/visibilidad/anonimato, borrador/publicación/edición/borrado,
  categorías/etiquetas, privados/moderación/inactividad, permisos/campos internos,
  versionado y reintento validado, comentarios/respuestas/profundidad/hilos ocultos,
  reacciones y follows idempotentes, conteos/conexiones, todos los cursores, N+1,
  CSRF, límites, SQL y fallos parciales.
- Adjuntos HTTP: formatos/tamaños/conteo, metadatos escapados, descarga por contexto,
  propiedad/retención, compensación de segunda carga y asociación, conflicto con
  upload, desasociación y corrupción del objeto. Se conservan las pruebas backend
  existentes de evidencias y concurrencia.
- Frontend: 30 aprobadas (25 anteriores + 5 de archivos de Comunidad).
- Build Vite: aprobado; sin cambios de configuración ni dependencias.
- Pint --test, sintaxis PHP, inventario y git diff --check: aprobados.
- Navegador: feed de visitante y autenticado, login temporal, creación de borrador
  con TXT mediante multipart nativo, publicación posterior, edición, descarga real,
  comentario/edición/respuesta, Me gusta, follow, feed Siguiendo, filtro por etiqueta,
  conteos de conexiones y enlaces a perfiles. Diálogo progresivo y detalle a 390×844
  sin desbordamiento horizontal; revisión de escritorio y tema oscuro; sin errores
  ni warnings de consola. El guardado fue mediante formularios HTML, sin fetch obligatorio.

La validación visual usó cuentas/filas sintéticas solo de testing, sesiones en cookie,
caché array y LARAVEL_STORAGE_PATH temporal fuera del repositorio. La reserva creada
por CLI se escribió en ese disco aislado. La revisión final detectó que la carga TXT
del navegador fue escrita por el subproceso PHP en la ruta privada predeterminada;
la configuración del proceso padre no aisló esa operación física. No fue una escritura
en la base de desarrollo ni se sobrescribió un objeto existente: fue un UUID nuevo.
Se identificó el archivo exacto de 76 bytes por fecha y coincidencia SHA-256 con el
fixture sintético (731cf995d1a8ff00668671c9c846847d8bb098c06735ed898cc2350e0e7addf2)
y se retiró únicamente ese archivo. No se ejecutó borrado recursivo. Los servicios
limpiaron los demás objetos y la limpieza selectiva retiró los registros temporales.
Se verificaron cero usuarios, posts, comentarios y adjuntos en testing. El servidor
temporal se detuvo; los procesos, datos y adjuntos funcionales de desarrollo se
conservaron. Antes de repetir uploads visuales debe verificarse la ruta efectiva
sobre el subproceso HTTP; no basta inspeccionar la configuración de CLI. La captura
entregada corresponde a ese escenario de testing, no a datos persistidos de desarrollo.

## 9. Riesgos y pendientes

Procesamiento seguro/preview multimedia, antivirus/cuotas, protección adicional frente
a abuso distribuido y límites globales de transferencia; auditoría durable de moderación,
denuncias, restauración, notificaciones y tiempo real; ranking y filtro sin respuesta;
interacción con Guardados, Artículos, LEXA, Mensajería y pagos. Ninguno se improvisa aquí.
Las transferencias grandes necesitan revisar límites PHP/proxy y coste de validar/bufferizar
archivos por descarga. La conciliación/purga operativa y una política transversal de
retención siguen siendo necesarias; una compensación fallida no equivale a limpieza completa.

SQL directo/forceFill/sync fuera de los servicios puede omitir invariantes del dominio.
Las restricciones estructurales de MySQL se conservan pero no sustituyen autorización,
propiedad, validación, versionado ni auditoría. No se exponen credenciales ni SQL al cliente.

## 10. Inventario exacto y estado Git

44 archivos: 28 nuevos y 16 modificados.

### Nuevos

- `app/Http/Controllers/Community/CommentController.php`
- `app/Http/Controllers/Community/CommunityController.php`
- `app/Http/Controllers/Community/FeedController.php`
- `app/Http/Controllers/Community/InteractionController.php`
- `app/Http/Controllers/Community/MediaController.php`
- `app/Http/Controllers/Community/PostController.php`
- `app/Http/Requests/Community/CommentRequest.php`
- `app/Http/Requests/Community/CommunityFormRequest.php`
- `app/Http/Requests/Community/FeedRequest.php`
- `app/Http/Requests/Community/InteractionRequest.php`
- `app/Http/Requests/Community/PostRequest.php`
- `app/Http/Requests/Community/VersionRequest.php`
- `app/Services/Community/CommunityPresentation.php`
- `app/Services/Community/PostSubmission.php`
- `docs/community-integration.md`
- `resources/js/community/files.js`
- `resources/views/components/community/comment-form.blade.php`
- `resources/views/components/community/feedback.blade.php`
- `resources/views/components/community/pagination.blade.php`
- `resources/views/components/community/post-form.blade.php`
- `resources/views/pages/community/comment-edit.blade.php`
- `resources/views/pages/community/connections.blade.php`
- `resources/views/pages/community/edit.blade.php`
- `resources/views/pages/community/error.blade.php`
- `resources/views/pages/community/show.blade.php`
- `tests/Feature/CommunityIntegrationTest.php`
- `tests/Feature/CommunityMediaIntegrationTest.php`
- `tests/frontend/community-files.test.mjs`

### Modificados

- `.gitignore`
- `app/Http/Controllers/Profiles/PublicProfileController.php`
- `app/Providers/AppServiceProvider.php`
- `app/Services/Community/CommunityQuery.php`
- `bootstrap/app.php`
- `resources/css/community.css`
- `resources/js/community.js`
- `resources/views/components/community/composer.blade.php`
- `resources/views/components/community/create-modal.blade.php`
- `resources/views/components/community/post-card.blade.php`
- `resources/views/components/community/sidebar.blade.php`
- `resources/views/layouts/partials/header.blade.php`
- `resources/views/pages/community/index.blade.php`
- `resources/views/pages/profile/public.blade.php`
- `resources/views/pages/profile/show.blade.php`
- `routes/web.php`

Sin staging, commit, push, merge ni cambio de rama. HEAD permanece en 3a607bd.
No se tocaron worktrees ajenos ni datos funcionales de desarrollo. No se modificaron
migraciones, dependencias, Vite, Mensajería, Artículos, LEXA ni pagos.
