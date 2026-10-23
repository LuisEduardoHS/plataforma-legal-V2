# BACK-05 — Comunidad y publicaciones

## Alcance y entrada al módulo

Backend sin rutas HTTP, controladores ni cambios de Blade, JavaScript, CSS o Vite.
Los servicios se resuelven con el contenedor de Laravel. PostPolicy y CommentPolicy
usan el descubrimiento convencional de políticas. Los consumidores deben usar los
servicios para escribir y CommunityQuery para obtener datos destinados a presentación;
no serializar relaciones Eloquent sin filtrar.

```php
$posts = app(\App\Services\Community\PostService::class);
$draft = $posts->create($actor, [
    'content' => '¿Qué documentos necesito para una reclamación laboral?',
    'visibility' => 'public',
    'content_category_id' => $categoryId,
    'legal_specialty_id' => $specialtyId,
    'tag_ids' => [$tagId],
    'attachment_ids' => [],
]);
$published = $posts->publish($actor, $draft->id, $draft->version);
$page = app(\App\Services\Community\CommunityQuery::class)->recent(
    viewer: $actor, filters: ['specialty_id' => $specialtyId], size: 20,
);
```

## Esquema

Todas las tablas usan IDs compatibles con users y los catálogos existentes.

| Tabla | Columnas y restricciones principales | Índices adicionales |
| --- | --- | --- |
| posts | id; user_id obligatorio; content nullable; content_category_id y legal_specialty_id opcionales; status draft/published/hidden/deleted; visibility public/private; version; published_at; edited_at; timestamps; deleted_at | status/visibility/published_at/id; user_id/status/published_at/id; content_category_id/status/published_at/id; legal_specialty_id/status/published_at/id |
| post_tag | post_id/tag_id PK compuesta; timestamps | tag_id/post_id |
| post_attachments | post_id/attachment_id PK compuesta; position; timestamps; UNIQUE(post_id,position) | attachment_id/post_id |
| comments | id; post_id; user_id; parent_id nullable; content; status visible/hidden/deleted; version; edited_at; timestamps; deleted_at | UNIQUE(id,post_id); post_id/status/id; user_id/id |
| post_reactions | id; post_id; user_id; type varchar(20), inicialmente like; timestamps; UNIQUE(post_id,user_id,type) | user_id/post_id |
| user_follows | follower_id/followed_id PK compuesta; timestamps; CHECK(follower_id <> followed_id) | followed_id/follower_id |
| attachments (ampliación) | community_published_at nullable; marca persistente de publicación previa | Sin índice nuevo: acceso por PK |

FK restrict: autor y catálogos de posts; autor/post de comments; usuario de
reacciones; etiqueta de post_tag; adjunto de post_attachments. Los pivotes y las
reacciones usan cascade al borrar físicamente el post; los vínculos de seguimiento
usan cascade al borrar físicamente cualquiera de los usuarios. El borrado de posts
por el servicio es lógico y conserva comentarios, reacciones y vínculos para revisión.

La FK compuesta de comments(parent_id,post_id) a comments(id,post_id) impide respuestas
a otra publicación, incluso con SQL directo. CHECKs mantienen la coherencia entre
estado, deleted_at y published_at de posts y comments. MySQL agrega los índices de
soporte de claves foráneas que resulten necesarios.

## Publicaciones, catálogos y concurrencia

create crea siempre un borrador privado, salvo visibilidad explícita. Puede estar
vacío mientras sea borrador. publish exige texto no vacío o al menos un archivo
válido; solo acepta borradores. update permite editar borradores, publicados y
ocultos; editar un oculto conserva su estado. moderate oculta o vuelve a mostrar
publicaciones publicadas/ocultas. delete realiza borrado lógico y es idempotente.
No se implementó restauración ni borrado definitivo.

update, publish, moderate y delete reciben expectedVersion. Las operaciones bloquean
usuarios en orden de ID y después el post; el estado se vuelve a leer bajo bloqueo.
Una versión obsoleta produce ValidationException sin cambios parciales. Las versiones
de comentarios siguen el mismo contrato. Las transacciones abarcan metadatos y
pivotes; no realizan cargas físicas dentro de transacciones externas.

Se reutilizan content_categories, tags y legal_specialties. Nuevas asociaciones
requieren registros activos. Se conservan y pueden reenviarse asociaciones históricas
que se desactivaron; eliminarlas impide volver a agregarlas mientras estén inactivas.
Los filtros permiten consultar esa historia. No se sembraron catálogos ni usuarios
adicionales, y no se modificaron DatabaseSeeder ni los seeders de permisos.

El texto se conserva como texto no confiable: se recortan espacios Unicode extremos,
se rechazan controles y se validan longitudes. HTML no se interpreta ni se considera
seguro. La integración futura debe escapar con Blade o textContent; nunca usar
salidas HTML sin escapar. Campos desconocidos se rechazan y los modelos nuevos
protegen todos sus atributos contra asignación masiva.

## Acceso y permisos

Solo cuentas activas pueden realizar operaciones o consultar como usuario identificado.
Los servicios recargan la cuenta persistida para rechazar modelos desactualizados.
Un invitado solo ve publicaciones públicas publicadas de autores activos.

- posts.create: crear y publicar contenido propio.
- posts.update.own / posts.delete.own: edición y eliminación propias.
- posts.moderate: consultar borradores, privados u ocultos ajenos y gestionar contenido
  ajeno; permite moderar incluso si el autor está suspendido. No habilita interacciones
  en contenido oculto ni de autores inactivos.
- comments.create: nuevos comentarios y respuestas.
- comments.moderate: edición, eliminación y moderación de comentarios ajenos.
- Reaccionar y seguir requieren cuenta activa y acceso al destino; no agregan roles.

Privado significa acceso exclusivo al autor y a cuentas con posts.moderate. Seguir
al autor no amplía permisos. Ser profesional o estar verificado tampoco los amplía.
Los roles base existentes ya contienen los permisos necesarios.

Los DTO públicos incluyen solo ID, alias y username público del autor. Un perfil
privado o inexistente aparece como «Usuario», sin username. No incluyen correo,
credenciales profesionales, contraseñas ni rutas de archivos.

## Adjuntos privados y compatibilidad BACK-02/BACK-04

post_attachments solo acepta archivos propios, disponibles, privados y de categorías
image/video/document; aplica los límites configurables, evita duplicados y conserva
el orden del usuario. Reordenar reemplaza los pivotes en una transacción. Un archivo
puede pertenecer a más de un post del mismo autor.

Se rechazan tanto verification_retained_at como cualquier asociación a evidencia,
incluidas solicitudes en borrador. En sentido inverso, VerificationService rechaza
archivos vinculados a Comunidad. Al publicar se fija community_published_at una sola
vez, incluso con visibilidad privada. Esa marca impide reutilizar posteriormente el
mismo archivo como evidencia aunque se retire de todos los posts; no abre el disco
ni cambia su visibilidad. El modelo Attachment protege su borrado/cambio accidental.
La migración de la marca es deliberadamente irreversible sin un plan específico.

AttachmentService bloquea solicitud de borrado y purge mientras cualquier post no
eliminado conserve una referencia, incluidos borradores y ocultos. Eliminar un post
no borra físicamente archivos. Una vez liberadas todas las referencias activas, el
propietario puede solicitar su borrado con el flujo existente, sujeto a retención de
verificación. Un post eliminado no es restaurable con los servicios de esta entrega.

CommunityMediaService::download(viewer,postId,attachmentId) autoriza por contexto y
asociación; valida disponibilidad, propiedad, retención, disco privado y hash/tamaño.
Lee el objeto bajo bloqueo, crea una copia temporal de streaming (hasta 2 MiB RAM,
resto temporal del sistema), y responde como descarga octet-stream, no-store,
nosniff y CSP sandbox. No expone paths ni URLs públicas y no altera AttachmentPolicy.
La consulta de feed también excluye archivos retenidos o asociados a evidencias.

La futura capa HTTP debe conectar este servicio con autenticación y rate limiting.
No existe URL descargable en esta entrega. La reproducción inline, rangos de video,
miniaturas, antivirus/cuarentena y transformación multimedia quedan pendientes.
available conserva el significado de BACK-02; no certifica ausencia de malware.

## Comentarios, reacciones y seguidores

CommunityInteractionService expone comment, editComment, deleteComment,
moderateComment, react, unreact, follow y unfollow.

Los comentarios admiten raíz y una sola respuesta; la raíz debe estar visible, tener
autor activo y pertenecer al mismo post. No se comenta en borradores, ocultos,
eliminados o privados inaccesibles. Los comentarios ocultos conservan su estado al
editarse. Ocultar/eliminar una raíz o desactivar su autor oculta también sus respuestas
en consultas y conteos, conservándolas en la base. El propietario puede eliminar su
comentario incluso si después se ocultó/eliminó la publicación principal.

Las reacciones iniciales son like. La columna string y la unicidad por tipo permiten
ampliarlas agregando valores al enum. react/unreact son idempotentes; se usan lecturas
con bloqueo para reconocer confirmaciones recientes bajo REPEATABLE READ. Los
conteos se calculan por SQL y excluyen cuentas inactivas.

follow/unfollow son idempotentes y no modifican fechas de relaciones existentes.
No se admite seguirse ni seguir cuentas inactivas. Se permite dejar de seguir una
cuenta que se desactivó. Desactivarla conserva las relaciones históricas, pero las
consultas excluyen sus publicaciones y conexiones. Las listas de conexiones se
consultan por el propio usuario o cuando el perfil del destino es público; se filtran
campos privados de cada usuario mostrado.

## Consultas y límites

CommunityQuery: recent, following, profile, detail, comments y connections.
recent/following aceptan category_id, specialty_id y tag_id; filtros desconocidos se
rechazan. following solo incluye públicos de cuentas seguidas. profile incluye
publicados públicos y, para el propio autor, también privados; no mezcla borradores
ni ocultos. detail permite al autor/moderador revisar esos estados por autorización.

Los feeds ordenan por published_at DESC e id DESC; comentarios y conexiones por id
ASC. Los cursores validan forma, tipos y fecha antes de utilizarlos. La paginación no
cuenta ni carga toda la tabla. Se cargan autor/perfil, categoría, especialidad,
etiquetas y archivos por eager loading, y conteos por subconsultas. Una prueba verifica
que aumentar los elementos no aumenta el número de consultas (máximo 8 en ese caso).

config/community.php define longitudes 10,000/5,000 caracteres para posts/comentarios,
máximo 4 adjuntos, 20 etiquetas y 50 resultados por página. La carga de archivos
mantiene los límites y MIME de config/attachments.php.

## Límites de integridad

FKs, índices únicos y CHECKs cubren integridad estructural. Autorización, profundidad,
propiedad de archivos, catálogos activos, texto no vacío al publicar y versionado son
reglas de los servicios: SQL directo, Eloquent query builder o forceFill pueden
saltarlas. No se debe dar acceso SQL al cliente ni escribir desde futuros controladores
sin estos servicios. Los eventos Eloquent no son una frontera contra un administrador
de base de datos. La marca persistente no detecta copias subidas como otros adjuntos.

Las transacciones pueden recibir deadlocks o lock timeout: se revierten y se devuelve
CommunityException genérica; no hay reintentos automáticos con efectos físicos. La
prueba de concurrencia verifica timeout entre conexiones independientes, lectura
actual de reacciones con snapshot previo y rechazo posterior de versiones obsoletas.
No hay auditoría completa de moderación, denuncias, cuotas, notificaciones ni ranking.
La retención y auditoría profesional previas se conservan.

## Inventario exacto de BACK-05 frente a HEAD

Creado (23 archivos):

- app/Enums/CommentStatus.php
- app/Enums/PostStatus.php
- app/Enums/PostVisibility.php
- app/Enums/ReactionType.php
- app/Exceptions/CommunityException.php
- app/Models/Comment.php
- app/Models/Post.php
- app/Models/PostReaction.php
- app/Policies/CommentPolicy.php
- app/Policies/PostPolicy.php
- app/Services/Community/CommunityAccess.php
- app/Services/Community/CommunityInteractionService.php
- app/Services/Community/CommunityMediaService.php
- app/Services/Community/CommunityQuery.php
- app/Services/Community/PostService.php
- config/community.php
- database/migrations/2026_10_23_120000_create_posts_and_catalog_relations.php
- database/migrations/2026_10_23_120001_create_comments_table.php
- database/migrations/2026_10_23_120002_create_community_interactions.php
- database/migrations/2026_10_23_120003_add_community_publication_marker.php
- docs/community.md
- tests/Feature/CommunityConcurrencyTest.php
- tests/Feature/CommunityTest.php

Modificado (8 archivos; integración backend necesaria):

- app/Models/Attachment.php
- app/Models/ContentCategory.php
- app/Models/LegalSpecialty.php
- app/Models/Tag.php
- app/Models/User.php
- app/Services/Attachments/AttachmentService.php
- app/Services/Verification/VerificationIntegrity.php
- app/Services/Verification/VerificationService.php

Fuera del inventario: recursos frontend, public, routes/web.php, Vite, package files
y pruebas frontend concurrentes. La preparación final de Git se limita a estos 31
archivos. No se realizó commit ni push.

## Validación

Entornos confirmados con bootstrap Laravel y SELECT DATABASE(): local/mysql en
plataforma_legal_v2 y testing/mysql en plataforma_legal_v2_test, MySQL 8.0.46.
Se ejecutó migrate --pretend antes de aplicar las cuatro migraciones nuevas en ambas
bases mediante migrate incremental. No se ejecutaron comandos de reconstrucción,
rollback ni borrado global. No se sembraron datos en desarrollo.

Las pruebas transaccionales revierten sus fixtures. La prueba de dos conexiones
requiere fixtures confirmados y los elimina selectivamente en finally, incluidos
permisos de prueba que no existieran previamente. Storage::fake mantiene las pruebas
físicas fuera del almacenamiento real y limpia sus archivos.

Resultados finales:

- php -l: 30 archivos PHP del alcance sin errores.
- Pint --test: aprobado en los mismos 30 archivos.
- php artisan test --compact --filter=Community: 26 pruebas, 329 assertions, aprobadas.
- php artisan test --compact: 238 pruebas, 9,258 assertions, aprobadas.
- migrate --pretend posterior: Nothing to migrate en ambas bases.
- git diff --check y git diff --cached --check: sin errores. El staging final contiene
  exclusivamente los 23 archivos creados y ocho modificados de este inventario.
- Tablas nuevas vacías en desarrollo. Verificación posterior a la suite: sin usuarios,
  publicaciones, comentarios, reacciones, asociaciones, seguimientos, adjuntos ni
  solicitudes/evidencias/eventos de prueba persistentes en la base testing.
