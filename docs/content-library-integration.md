# INTEGRACIÓN-04 — Artículos, recursos jurídicos y biblioteca personal

## Base y alcance

Trabajo iniciado en `main`, HEAD `d9928821323679c22f3349cf5fb11f20921ca661`,
con árbol e índice limpios. Se conectan BACK-07, Comunidad, perfiles y sesiones a
HTTP/Blade. No se añadieron tablas, migraciones, dependencias ni permisos nuevos.
No se ejecutaron migraciones ni seeders en desarrollo. No se preparó staging,
commit, push, merge ni cambio de rama. Los otros worktrees permanecen fuera del alcance.

## Arquitectura y servicios

- `EditorialController`: artículos y recursos seleccionados por defaults internos
  de rutas. El cliente no elige clases, tablas, propietario ni permisos.
- `ContentController`: renderiza Blade dentro de una transacción, con
  `Cache-Control: private, no-store`; devuelve respuestas de escritura explícitas.
- `EditorialQuery`: listados, detalle, espacio propio/de revisión e historial.
  Añade capacidades calculadas con policies y nombre/extensión de archivos al DTO
  existente, sin serializar modelos completos ni paths/hashes internos.
- `EditorialStatus::targets()`: única matriz de transiciones estructurales,
  compartida por `EditorialService` y los controles visibles. Las policies y el
  servicio siguen decidiendo la autorización definitiva de cada escritura.
- `EditorialSubmission`: comprueba autorización y versión antes de subir; reserva
  archivos mediante `AttachmentService` fuera de transacciones exteriores; ejecuta
  creación/edición y publicación en una sola transacción del contenido. El servicio
  vuelve a autorizar con usuarios, permisos y versión vigentes bajo sus bloqueos.
- `EditorialMediaService`: descarga del archivo actual por contexto editorial;
  conserva autorización, integridad, propietario y restricciones de verificación.
- `SavedItemService` y `SavedLibraryQuery`: escrituras idempotentes y biblioteca
  privada vigente. `savedIds()` consulta en lote referencias del propio actor para
  decorar contenido que las consultas de dominio ya autorizaron; no es un endpoint
  ni concede acceso al destino.
- `HomeContentController`: muestra una lectura realmente publicada o un estado vacío.
  Conserva las demás áreas demo de Inicio que pertenecen a integraciones posteriores.

No se modifica `ContentAccess`, las policies, el esquema ni las reglas de grants.
Las consultas públicas, de biblioteca y de historial no devuelven revisiones por
medio del detalle público. El render transaccional mantiene juntas la página y sus
relaciones. Se conserva la lectura actual con bloqueos de BACK-07 y AUDIT-02.

## Rutas HTTP

Los dos conjuntos editoriales usan las mismas operaciones, con ámbitos separados:
`/articulos` y `/recursos`, nombres `articles` y `resources`. Los identificadores
son positivos, de hasta 18 dígitos; no hay model binding público de instancias.

| Método | Ruta bajo cada prefijo | Nombre por prefijo | Acceso |
| --- | --- | --- | --- |
| GET/HEAD | `/` | `articles` / `resources` | Listado publicado público |
| GET/HEAD | `/gestion` | `.workspace` | Sesión; propio o todos con manage |
| GET/HEAD | `/crear` | `.create` | Sesión y create o manage |
| GET/HEAD | `/{entry}` | `.show` | Policy view actual |
| GET/HEAD | `/{entry}/editar` | `.edit` | Sesión; update y publish si publicado |
| GET/HEAD | `/{entry}/historial` | `.history` | Sesión y audit |
| GET/HEAD | `/{entry}/archivos/{attachment}` | `.media` | Policy view y asociación actual |
| POST | `/` | `.store` | Sesión y permisos explícitos |
| PUT | `/{entry}` | `.update` | Sesión, permisos y versión |
| POST | `/{entry}/estado` | `.transition` | Sesión, policy de acción y versión |

| Método | Ruta | Nombre | Función |
| --- | --- | --- | --- |
| GET/HEAD | `/guardados` | `saved` | Biblioteca del actor autenticado |
| PUT | `/guardados/{type}/{target}` | `saved.store` | Guardar idempotente |
| DELETE | `/guardados/{type}/{target}` | `saved.destroy` | Quitar idempotente |
| GET/HEAD | `/inicio` | `home` | Inicio existente con lectura publicada real |

`type` solo admite `post`, `article` o `resource`. No se acepta `user_id`,
`owner_id`, URL de retorno arbitraria ni selección de otra biblioteca.

Límites: `content.read`, 120/min por IP; `content.write`, 60/min por cuenta
(transiciones y guardados); `content.upload`, 10/min por cuenta (crear/editar,
incluso sin archivos). Todos los cambios usan web/CSRF. Se mantiene el middleware
persistido de cuenta activa de INTEGRACIÓN-01; no hay bypass por rol.

## Formularios, lectura y respuestas

Lecturas: páginas HTML Blade, incluso al solicitar JSON; no se añade una API pública
alternativa de modelos. Las escrituras aceptan formularios HTML o JSON. HTML exitoso
redirige con `status`; JSON devuelve `message`, `redirect` y solo los campos del
resultado: `id`, `version`, `status` editorial, o `saved` para guardados. Crear devuelve
201 en JSON; el resto, 200. No se devuelve el modelo Eloquent.

Los Form Requests rechazan campos adicionales. Artículos admiten título, resumen,
cuerpo, categoría y listas de etiquetas, especialidades y archivos; recursos cambian
resumen/cuerpo por descripción, tipo y URL HTTPS. `intent` es `save` o `publish`.
En edición, `version` es obligatoria; en creación está prohibida. No se admite slug
manual por HTTP. Las listas de asociaciones son reemplazos completos: omitir un grupo
de checkboxes lo deja vacío. `uploads[]` contiene únicamente archivos nuevos.

Errores ordinarios: JSON 422 con errores de validación; HTML vuelve al formulario
con valores acotados y listas seguras. Se preserva la versión originalmente enviada,
incluso si otro campo falló; nunca se convierte texto viejo en una edición contra
la versión actual sin consentimiento. Los grupos vacíos se conservan también si la
validación falla dentro del dominio. Una versión obsoleta devuelve 409 y no guarda;
una versión mal formada devuelve 422. Las lecturas con filtros/cursores inválidos
devuelven 422, incluyendo HEAD. Autenticación: redirect a login o JSON 401.
Autorización: 403 en gestión y 404 en contenido/historial/descargas no accesibles y
al guardar un destino no disponible. Los errores SQL, de dominio o almacenamiento
controlados devuelven 503 genérico, incluso con debug. Se conserva el reporte SQL
estructurado de AUDIT-02, sin SQL/bindings en la respuesta.

## Artículos, recursos y clasificación

El listado muestra publicaciones accesibles de autores activos, por
`published_at DESC, id DESC`, 20 por página con cursor estable. No se presentan
popularidad o recomendaciones ficticias. Búsqueda por prefijo literal de título,
con comodines escapados; filtros por autor, categoría, etiqueta y especialidad,
y tipo en recursos. No se simula búsqueda del cuerpo completo.

Se conserva la tarjeta con portada decorativa, paleta, tipografía, animaciones y
modo oscuro existentes. La lectura completa tiene URL propia y texto escapado;
no interpreta HTML, scripts ni un supuesto editor enriquecido. Los metadatos de
perfil privado se presentan como Usuario; nunca se muestra correo/licencia ni
credenciales privadas. El menú principal reconoce las rutas nuevas.

El espacio editorial muestra contenido propio o todo con `*.manage`, y filtra por
estado. Se conectan borradores, edición, publicación, despublicación, archivo,
ocultación/restablecimiento autorizado, eliminación lógica e historial paginado.
Los eliminados no reaparecen en el workspace; el historial sigue consultable por
su URL/ID para un actor con audit. No hay restauración de eliminados.

Categoría principal y listas de etiquetas/especialidades usan catálogos existentes.
Se pueden conservar asociaciones ya inactivas, retirarlas y consultar su clasificación
histórica; no se pueden añadir nuevas asociaciones inactivas. No se inventa un CRUD
global de catálogos ni se crean opciones para rellenar la pantalla.

Recursos: guide, document, template, institutional_link y educational_material.
Documento/plantilla requieren archivo al publicar; otros tipos admiten URL o archivo
según el servicio. La descripción es obligatoria al publicar. Solo enlaces HTTPS
públicos validados por el dominio; institucionales restringidos a hosts configurados.
Los enlaces llevan noopener/noreferrer/nofollow y no envían referrer. No se consulta
ni descarga el destino desde el servidor. Se muestra Recurso aportado por usuario;
no se certifica oficialidad, exactitud ni vigencia jurídica. No se generó contenido
legal en desarrollo ni se conectó actualización legislativa automatizada.

## Permisos editoriales

Se conservan los ocho permisos preexistentes, SIN asignaciones automáticas:

- `articles.create`, `articles.update.own`, `articles.publish.own`, `articles.manage`.
- `resources.create`, `resources.update.own`, `resources.publish.own`, `resources.manage`.

Create o manage permite borradores; update.own permite editar lo propio; publicar
requiere además publish.own, o manage. La edición de publicados exige permiso de
publicación. Ocultar/restablecer hidden requiere manage. El historial exige audit:
update.own del autor o manage. Los roles user/admin/moderator y el perfil profesional
no conceden capacidades editoriales por sí mismos. No hay grants desde el registro,
las vistas, los controladores ni la nueva documentación ejecutable.

La asignación real queda a un procedimiento administrativo explícito, con actor
responsable, cuenta destinataria, permisos mínimos aprobados y registro de la decisión.
No se construyó una pantalla nueva de gestión de privilegios ni se ejecutaron grants
en desarrollo. Para validar se otorgaron permisos únicamente a fixtures de testing,
revertidos o eliminados selectivamente al terminar.

## Biblioteca y Comunidad

Persistencia MySQL por cuenta, disponible entre sesiones/equipos. Save conserva
ID/fecha al repetirse; remove repetido es seguro y solo afecta al actor. Las tarjetas
y detalles muestran el estado obtenido del servidor. La biblioteca filtra por tipo,
ordena por `saved_at DESC, id DESC` y pagina con cursor. El contador mostrado es de
la página, no un total ficticio. No se ofrecen búsquedas/orden antiguo no soportados
por BACK-07, ni un Deshacer local que restaure referencias obsoletas.

Las lecturas vuelven a comprobar estado, autor activo y autorización actual. Al
ocultar/despublicar/eliminar o revocar acceso, la referencia puede conservarse en
saved_items pero no se muestra. Quitar sigue permitido sin acceso al contenido.
Posts privados solo si PostPolicy autoriza al autor/moderador vigente; seguir una
cuenta no basta. Guardar nunca concede acceso, descarga ni copia permanente del texto.

Comunidad cambia únicamente la decoración en lote del estado y el botón compartido
de guardar en tarjetas/detalle. Reacciones, comentarios, feeds y follows conservan
sus controladores y servicios. Inicio enlaza la lectura publicada real; sus tarjetas
demo de Comunidad ya no pueden producir guardados ficticios. Ayuda describe la
persistencia por cuenta. `store.js` ya no lee/escribe localStorage; los consumidores
anteriores fueron retirados. No se importan guardados demo con IDs/URLs ficticios ni
se borran automáticamente los datos locales antiguos del navegador.

El botón funciona con formulario nativo y CSRF. JavaScript progresivo solicita JSON,
espera confirmación antes de cambiar el marcador, bloquea envíos simultáneos del
mismo objetivo y muestra errores de sesión/acceso/límite/red sin aplicar cambios
optimistas falsos. Al quitar desde la biblioteca recarga la página del servidor
para recalcular visibilidad, conteos y cursores. Las solicitudes se cancelan al salir
mediante el ciclo de vida existente de Turbo.

## Adjuntos, retención y errores parciales

Hasta cuatro archivos actuales. Fileinfo, extensión y bytes reales los valida
AttachmentService: JPEG/PNG/GIF/WebP 10 MiB; MP4/WebM 100 MiB; PDF/TXT/MP3 20 MiB.
No se admite SVG, HTML u Office. El frontend solo anticipa errores de formato,
tamaño y cantidad; no sustituye la validación del dominio. El PHP inspeccionado
aplica `upload_max_filesize=40M` y `post_max_size=40M`: un envío HTTP puede ser
rechazado antes del dominio aunque su límite teórico admita videos mayores. Se debe
configurar explícitamente PHP/proxy y el total multipart antes de habilitar esos
máximos en producción; no se cambiaron esos parámetros aquí.

Los archivos deben ser propios del autor, privados, disponibles y ajenos a
verificación. Manage no permite adjuntar archivos propios a contenido de otro autor;
puede conservar/retirar asociaciones del autor. No hay subida independiente sin
contexto editorial ni preview inline. Se muestran nombre escapado, extensión,
tamaño y descarga por contenido actual. No hay storage:link ni URL pública directa.
Descargas: octet-stream, attachment, no-store, nosniff y CSP sandbox; tamaño/hash
verificados por el servicio preexistente antes de enviar bytes.

Las revisiones conservan sus FKs a archivos retirados y sobreviven al borrado lógico.
El historial HTTP muestra texto/clasificación y número de archivos retenidos, sin
links de descarga histórica ni paths/hashes. Se conservan las marcas editoriales y
la exclusión mutua con evidencia profesional. Retirar asociación no borra archivo.

Crear/editar y publicar revierten juntos texto, asociaciones, versión, revisiones y
marcas si falla alguna parte. Las reservas físicas nuevas son durables; ante fallo
se solicita su eliminación, quedando pending_deletion para el proceso de purga
existente. No se purgan inmediatamente ni se tocan archivos anteriores. Un fallo
al solicitar compensación se reporta para reconciliación; no se promete atomicidad
física de Storage+MySQL. Si cambia versión/permisos durante la carga, las comprobaciones
finales rechazan la edición y compensan solo las nuevas reservas. POST de creación
no incorpora una clave de idempotencia nueva; la idempotencia de esta entrega es la
del servicio de guardados. Los conflictos editoriales usan versiones, sin reintento
que sobrescriba contenido concurrente.

## Aislamiento y validación

Antes de crear fixtures se confirmó mediante bootstrap testing: mysql,
base configurada y SELECT DATABASE() = plataforma_legal_v2_test. `Tests/TestCase`
hace esa comprobación antes de cada fixture. Las pruebas HTTP de medios usan
Storage::fake('attachments') y comprueban su ruta efectiva tanto al preparar la
prueba como dentro de la petición, justo antes de crear cada reserva de Attachment.
No se da por supuesto el aislamiento a partir de variables de un proceso padre.

La revisión visual utilizó un router temporal fuera del repositorio, sin endpoint
de diagnóstico en producción. El propio proceso HTTP confirmó environment=testing,
SELECT DATABASE()=plataforma_legal_v2_test y attachment_root bajo el directorio de
artefactos temporal (PID 11600). No hubo cargas desde el navegador. Se comprobaron
login sintético, guardado, persistencia tras recarga, retirada y edición HTML en
escritorio y móvil 390x844, sin desbordamiento horizontal. Solo se observó una
cancelación de View Transition al cambiar el viewport durante la prueba; no un fallo
de las operaciones de la aplicación. El viewport fue restablecido y el tab cerrado.

Se detuvo el servidor propio; los IDs concretos de usuario, artículo, recurso,
revisiones, guardados y permisos de esa revisión visual se eliminaron selectivamente
en testing. Los seis conteos de control volvieron exactamente al baseline cero.
Las pruebas transaccionales revierten fixtures; las durables eliminan únicamente
sus propios IDs y objetos del disco fake, respetando el orden de FKs. No se ejecutó
ninguna prueba contra la base de desarrollo.

Se conservó intacto el PNG privado preexistente de desarrollo, con SHA-256 antes y
después `FE57B91CAE20E3EE77AC211A634FF67D627E7A6B6C6536B59E3A4B73977C51F8`.
El TXT de la incidencia de INTEGRACIÓN-03 no se recreó; esta entrega usa discos fake
y un root HTTP comprobado dentro del proceso. No hubo limpieza adicional de privados.
Los scripts, sesiones y capturas de validación se guardaron fuera del repositorio.

Cobertura nueva: catálogos/detalles públicos, borradores/ocultos/eliminados, autores
inactivos, DTO/escape, filtros literales y cursores, HEAD, grants explícitos y denegación
a roles, creación/edición/publicación/despublicación/moderación, historial paginado,
clasificación inactiva, versión obsoleta y conservación de old input, formularios
nativos, guardados de los tres tipos, privacidad entre cuentas, idempotencia,
revocación de visibilidad/moderación, cuentas inactivas, CSRF, límites, entradas
hostiles/URLs, errores SQL, rollback de revisiones, compensación de cargas parciales,
retención histórica, archivos ajenos/retenidos y descargas/integridad contextual.

Resultados finales sobre el código terminado:

- Suite Laravel completa: **490 pruebas, 12,189 assertions**, sin fallos (86.79 s).
  Incremento: 41 casos HTTP frente a las 449 pruebas / 11,688 assertions previas.
- Frontend: **30 pruebas aprobadas**, incluyendo confirmación del servidor, fallos
  sin falso éxito y mensajes sanitizados del nuevo botón; las pruebas del almacén
  demo se sustituyen por las del contrato real, sin conservar persistencia ficticia.
- `npm run build`: aprobado (Vite 6.4.4; sin cambios de dependencias).
- Pint `--test`: aprobado para los 22 archivos PHP nativos del alcance.
- `php -l`: aprobado para los 37 archivos PHP/Blade nuevos y modificados.
- `git diff --check`: aprobado. Inventario contrastado con HEAD e índice vacío.
- Se mantienen aprobadas las regresiones de las tres integraciones HTTP anteriores
  y de BACK-01 a BACK-07, incluidas pruebas preexistentes de concurrencia.

## Limitaciones y preparación para producción

- Sin editor enriquecido, restauración de revisiones, programación editorial,
  certificación oficial, sincronización legislativa ni CRUD global de catálogos.
- Sin preview/miniaturas/reproducción inline. Antimalware, cuarentena y procesamiento
  estructural seguro de documentos/multimedia siguen pendientes antes de operar
  archivos comerciales; available no certifica ausencia de malware.
- Definir cuotas, retención/liberación auditada, reconciliación y ejecución operativa
  de purgas pendientes; la historia no se borra automáticamente.
- SQL directo/builders/privilegios de DBA pueden eludir reglas de dominio e historia.
  La integración no convierte la auditoría en evidencia criptográfica inviolable.
  Mantener mínimo privilegio, backups y procedimientos administrativos auditados.
- Revisar límites HTTP/PHP/proxy, temporales, backups y retención de logs en despliegue.
- Biblioteca: solo filtros por tipo, cursor y orden reciente de BACK-07. Búsqueda
  completa, orden alternativo, colecciones y migración de mocks no se agregaron.
- No se integraron LEXA, mensajería, pagos ni áreas demo ajenas de Inicio. Las suites
  previas se mantienen como regresión de autenticación, perfiles y Comunidad.

## Inventario exacto frente a HEAD

Total: **47 archivos: 23 nuevos y 24 modificados**. Sin archivos eliminados.

### Nuevos (23)

- `app/Http/Controllers/ContentLibrary/ContentController.php`
- `app/Http/Controllers/ContentLibrary/EditorialController.php`
- `app/Http/Controllers/ContentLibrary/HomeContentController.php`
- `app/Http/Controllers/ContentLibrary/SavedController.php`
- `app/Http/Requests/ContentLibrary/ContentFormRequest.php`
- `app/Http/Requests/ContentLibrary/ContentReadRequest.php`
- `app/Http/Requests/ContentLibrary/EditorialRequest.php`
- `app/Http/Requests/ContentLibrary/SavedRequest.php`
- `app/Http/Requests/ContentLibrary/TransitionRequest.php`
- `app/Services/ContentLibrary/EditorialSubmission.php`
- `docs/content-library-integration.md`
- `lang/es/content.php`
- `resources/js/editorial.js`
- `resources/views/components/content/pagination.blade.php`
- `resources/views/components/content/save.blade.php`
- `resources/views/pages/articles/edit.blade.php`
- `resources/views/pages/articles/error.blade.php`
- `resources/views/pages/articles/history.blade.php`
- `resources/views/pages/articles/layout.blade.php`
- `resources/views/pages/articles/show.blade.php`
- `resources/views/pages/articles/workspace.blade.php`
- `tests/Feature/ContentLibraryIntegrationTest.php`
- `tests/Feature/EditorialMediaIntegrationTest.php`

### Modificados (24)

- `app/Enums/EditorialStatus.php`
- `app/Providers/AppServiceProvider.php`
- `app/Services/Community/CommunityPresentation.php`
- `app/Services/ContentLibrary/EditorialQuery.php`
- `app/Services/ContentLibrary/EditorialService.php`
- `app/Services/ContentLibrary/SavedItemService.php`
- `bootstrap/app.php`
- `resources/css/app.css`
- `resources/css/articles.css`
- `resources/js/app.js`
- `resources/js/home.js`
- `resources/js/personal.js`
- `resources/js/saved/library.js`
- `resources/js/saved/store.js`
- `resources/views/components/articles/card.blade.php`
- `resources/views/components/community/post-card.blade.php`
- `resources/views/components/home/bookmark.blade.php`
- `resources/views/layouts/partials/header.blade.php`
- `resources/views/pages/articles/index.blade.php`
- `resources/views/pages/help/questions.php`
- `resources/views/pages/home/home.blade.php`
- `resources/views/pages/saved/index.blade.php`
- `routes/web.php`
- `tests/frontend/saved-store.test.mjs`

El inventario incluye este documento; excluye artefactos ignorados de build, temporales
fuera del proyecto y cualquier otro worktree. No se modificaron migraciones, seeders,
.env, Vite ni manifiestos de dependencias. El índice permanece vacío.
