# BACK-07 — Artículos, recursos jurídicos y guardados

## Alcance y arquitectura

Backend sin endpoints HTTP, Blade, Vite ni cambios de frontend. articles y
legal_resources son tablas independientes: sus campos específicos no se mezclan en
una tabla con columnas irrelevantes. EditorialEntry, EditorialService y EditorialPolicy
comparten clasificación, archivos, permisos, revisiones y transacciones. EditorialKind
selecciona exclusivamente modelos conocidos; nunca se aceptan nombres de clase del
cliente. EditorialStatus y LegalResourceType delimitan los estados y tipos.

Los guardados usan tres FKs explícitas y un CHECK de objetivo único, sin referencias
polimórficas huérfanas. Reutilizan posts y su autorización existente. No se añadieron
catálogos, carpetas, colecciones, motores externos ni funcionalidades de recomendaciones.

## Esquema e índices

Todas las FKs usan RESTRICT; el borrado del dominio es lógico y no elimina historia.
Las FKs e índices adicionales de soporte se verificaron con MySQL 8.

| Tabla | Campos y restricciones | Índices principales |
| --- | --- | --- |
| articles | id, user_id, title(200), slug(190) único, summary, body longtext, content_category_id nullable, status, version, published_at, editorial_updated_at, timestamps, deleted_at | status/publicación/id; autor/status/publicación/id; categoría/status/publicación/id; título |
| legal_resources | campos editoriales comunes, description, resource_type enum, external_url(2048) nullable | índices anteriores y tipo/status/publicación/id |
| article_tag / legal_resource_tag | FK contenido + tag_id, timestamps, PK del par | etiqueta/contenido |
| article_specialty / legal_resource_specialty | FK contenido + legal_specialty_id, timestamps, PK del par | especialidad/contenido |
| article_attachment / legal_resource_attachment | FK contenido + attachment_id, position, timestamps, PK del par, UNIQUE(contenido,position) | archivo/contenido |
| content_revisions | id, article_id o legal_resource_id, recorded_by FK users, version, snapshot JSON, created_at | UNIQUE(article_id,version), UNIQUE(legal_resource_id,version); CHECK exactamente un objetivo |
| content_revision_attachments | FK revisión + attachment_id, position, PK del par, posición única por revisión | archivo/revisión |
| saved_items | id, user_id, post_id/article_id/legal_resource_id nullable, created_at | UNIQUE(usuario,cada objetivo), usuario/fecha/id; CHECK exactamente un objetivo |
| attachments (ampliación) | editorial_published_at nullable, oculto en serialización | acceso por PK |

CHECKs de artículos y recursos mantienen coherencia entre status y deleted_at, y
exigen fecha para published. Los slugs se reservan por tabla incluso después del
borrado lógico; las URLs futuras deben distinguir /articles y /resources. Se generan
con título normalizado más ULID, o se acepta un slug explícito ASCII minúsculo con
guiones. No cambian al editar y se rechaza modificarlos por entrada del usuario.

## Contratos de dominio

```php
use App\Enums\EditorialKind as Kind;
use App\Enums\EditorialStatus as Status;
use App\Enums\SavedContentType;

$editorial = app(\App\Services\ContentLibrary\EditorialService::class);
$article = $editorial->create(Kind::Article, $actor, [
    'title' => 'Documentos para una reclamación laboral',
    'summary' => 'Una introducción al tema.',
    'body' => 'Texto informativo que deberá escaparse al mostrarlo.',
    'content_category_id' => $categoryId,
    'tag_ids' => [$tagId], 'specialty_ids' => [$specialtyId],
    'attachment_ids' => [],
]);
$article = $editorial->transition(Kind::Article, $actor, $article->id, Status::Published, $article->version);
$editorial->update(Kind::Article, $actor, $article->id, ['body' => 'Nueva versión.'], $article->version);

$saved = app(\App\Services\ContentLibrary\SavedItemService::class);
$saved->save($viewer, SavedContentType::Article, $article->id);
$page = app(\App\Services\ContentLibrary\SavedLibraryQuery::class)->library($viewer, size: 20);
$saved->remove($viewer, SavedContentType::Article, $article->id);
```

create produce siempre draft, versión 1, con revisión inicial. update y transition
requieren expectedVersion; bloquean usuarios en orden de ID y después contenido,
relaciones y archivos. Una versión obsoleta falla sin cambios parciales. Toda edición
y cambio de estado crea una revisión en la misma transacción; los fallos de revisión
revierten texto, catálogos, pivotes, versión, estado y marcas de archivo.

El cliente solo puede indicar campos admitidos para ese tipo. user_id, status,
version, roles, marcas internas y supuestos is_official se rechazan. Los modelos
nuevos protegen todos sus atributos contra asignación masiva. La futura autenticación
debe proporcionar el User real al servicio, no un actor_id elegido por el cliente.

config/content-library.php define máximos: título 200, resumen 1,000, cuerpo 100,000,
descripción 5,000, cuatro archivos, 20 etiquetas/especialidades y 50 resultados/página.
El cuerpo usa longtext para admitir Unicode sin exceder el límite de TEXT de MySQL.
Se recortan espacios Unicode extremos y se rechazan controles. El texto, incluido
HTML, se considera no confiable y debe escaparse al presentarlo; no se interpreta,
no se promete sanitización HTML y no se implementa un editor de HTML enriquecido.

## Estados, permisos e historia

Transiciones permitidas:

| Origen | Destinos |
| --- | --- |
| draft | published, archived, deleted |
| published | draft, hidden, archived, deleted |
| hidden | published solo con manage; deleted |
| archived | draft, deleted |
| deleted | sin restauración; repetir deleted es idempotente |

Editar hidden conserva hidden; no puede pasarse por archived/draft para eludir una
ocultación. Published requiere cuerpo en artículos, y descripción más enlace/archivo
en recursos. La primera fecha published_at se conserva al volver a publicar;
editorial_updated_at y version registran cambios posteriores. Un borrador puede tener
cuerpo/descripción pendientes. No se implementó programación de publicaciones.

EditorialPermissionSeeder crea ocho permisos sin asignarlos automáticamente a roles:
articles.create, articles.update.own, articles.publish.own, articles.manage y sus
equivalentes resources.*. DatabaseSeeder incorpora ese seeder conservando los demás.
Los grants existentes y los IDs se preservan. Ni admin, moderator ni un profesional
verificado adquieren capacidades editoriales por ese hecho.

create admite create o manage; editar y retirar/archivar/eliminar contenido propio
requiere update.own, o manage para contenido ajeno. Publicar, editar directamente
un publicado o devolver un publicado a draft requiere también publish.own o manage.
Ocultar y restaurar hidden requiere manage. El autor activo puede consultar sus
borradores/archivados aunque no tenga permiso de edición; manage permite revisión
editorial ajena. El historial exige update.own del autor o manage, incluso después
del borrado lógico. Todos los actores deben estar activos y se recarga su estado.
El público solo ve published de autores activos. Un editor con manage puede revisar
contenido no público de un autor inactivo, sin otorgar acceso público.

Las revisiones conservan texto, slug, estado, fechas y una copia de la clasificación
(IDs, nombres, slugs, activación) para no depender del estado futuro del catálogo.
Los archivos históricos tienen FKs propias ordenadas. ContentRevision impide update
y delete de instancias Eloquent; no hay modificación ni restauración pública de
revisiones. La retención no es una firma criptográfica contra cambios de un DBA.

Categoría principal, etiquetas múltiples y especialidades múltiples reutilizan los
catálogos existentes. Nuevas asociaciones requieren registros activos; las ya
asociadas pueden conservarse o reenviarse cuando se desactivan. Si se retiran, no se
pueden agregar de nuevo mientras sigan inactivas. Los filtros admiten historia inactiva.

## Recursos y enlaces

Tipos: guide, document, template, institutional_link y educational_material.
Documento y plantilla requieren archivo al publicar; los demás admiten un enlace
sin archivo. institutional_link exige URL y dominio configurado como institucional.
La configuración inicial admite gob.mx, unam.mx e inegi.org.mx y sus subdominios.
El cambio de tipo también revalida el dominio correspondiente.

Solo URLs HTTPS, sin credenciales ni puertos distintos de 443, con hostname DNS
válido; se rechazan direcciones IP, localhost y TLDs locales/de prueba. Los esquemas
javascript/data/file/http no se aceptan. Se valida longitud, sintaxis y host; no se
realizan solicitudes, descargas, DNS ni extracción de metadatos de la URL. El usuario
puede cambiar el contenido de un destino externo: esto no certifica su seguridad.

El DTO identifica todo recurso como «Recurso aportado por usuario». La autoría y la
lista de hosts no certifican un documento como oficial ni validan su vigencia legal.
Una futura certificación editorial requeriría reglas y permisos adicionales.

## Adjuntos, retención y compatibilidad

Se reutiliza AttachmentService para subir primero los archivos fuera de transacciones
externas. EditorialService solo vincula archivos existentes. Valida propietario,
disponibilidad, disco/visibilidad privados, coherencia MIME/extensión/categoría y
ausencia de verification_retained_at o cualquier evidencia, incluso en borradores.
Hay listas ordenadas sin duplicados y límites configurables.

Cada revisión conserva sus referencias a archivos, incluso cuando se retiran del
contenido actual o se elimina lógicamente. AttachmentService impide requestDeletion
y purge ante referencias editoriales actuales o históricas, además de conservar las
restricciones de Comunidad, Mensajería y Verificación. No hay borrado físico automático
ni política de purga de revisiones en esta entrega. Esta decisión conserva historia
y requiere una política explícita de retención/cuotas antes de producción.

Al publicar se fija editorial_published_at, persistente y protegida por el modelo.
VerificationService rechaza la marca y también vínculos actuales o históricos, aun
sin publicación previa. VerificationIntegrity impide usar archivos publicados como
evidencias. La migración de la marca requiere un plan explícito para revertirse.
Una copia subida como otro adjunto no se detecta mediante esta marca.

EditorialMediaService::download(kind,viewer,id,attachmentId) autoriza por estado y
contenido actual; nunca concede acceso por un guardado o por una revisión antigua.
Los invitados solo descargan de publicados accesibles; autor/editor pueden consultar
adjuntos de sus contenidos privados autorizados. Valida asociación exacta, retención,
disco privado, tamaño y SHA-256 antes de responder como octet-stream con no-store,
nosniff y CSP sandbox. Usa hasta 2 MiB RAM y después almacenamiento temporal para
preparar la respuesta. No expone paths, nombres internos, hashes o URLs públicas.
AttachmentPolicy no cambia. No hay descarga HTTP de archivos históricos: la consulta
autorizada de revisiones devuelve sus IDs, no acceso público al almacenamiento.

## Guardados e integración con Comunidad

SavedItemService::save exige contenido publicado, autor activo y autorización view
actual. Para posts privados aplica PostPolicy: autor o moderador autorizado; seguir
al autor no basta. No se guardan borradores ni ocultos, aunque el solicitante pueda
revisarlos. La unicidad por usuario/objetivo y bloqueos hacen save idempotente y
conservan ID/fecha originales. remove es idempotente y solo afecta al solicitante;
puede retirar una referencia aunque el contenido ya no sea accesible.

SavedLibraryQuery::library solo acepta el actor autenticado, sin owner_id ni consultas
de bibliotecas ajenas. Filtra por estado, autor activo y acceso actual; las referencias
se conservan en saved_items al despublicar, ocultar, privatizar o eliminar el destino,
pero no aparecen en resultados. Volver a publicar puede hacerlas reaparecer. Revocar
posts.moderate retira los privados ajenos de la biblioteca. Guardar nunca amplía
acceso ni habilita descargas independientes de su contenido original.

La biblioteca usa lecturas actuales con bloqueo compartido, incluidas las subconsultas
de visibilidad y los autores, para no mostrar contenido despublicado por otra conexión
aunque exista un snapshot REPEATABLE READ previo. La página y su eager loading se
resuelven en una transacción. Los DTO son resúmenes: tipo, objetivo, título/slug o
preview de post, y fecha de guardado; no hay copias permanentes de texto o archivos.

## Consultas y rendimiento

EditorialQuery::recent admite autor, categoría, etiqueta, especialidad, título y,
para recursos, tipo. El título se busca por prefijo literal, escapando comodines;
no es un buscador sobre todo el cuerpo. Orden published_at DESC/id DESC con cursor.
workspace lista contenido propio, o todo con manage, por updated_at DESC/id DESC;
acepta estado. detail incluye cuerpo; listados de artículos incluyen resumen sin
cuerpo. revisions es privado, paginado por id DESC. Los cursores validan forma, fecha,
ID y dirección. SavedLibraryQuery pagina por saved_at DESC/id DESC y filtra por tipo.

Eager loading carga autor/perfil, categoría, etiquetas, especialidades y archivos en
consultas acotadas. Los DTO no exponen correo, contraseña, licencias ni alias privados;
un perfil privado se presenta como «Usuario». Las pruebas comparan páginas de uno y
cinco elementos y exigen cantidad fija de consultas (máximo siete en los casos probados).
No se carga el historial entero en memoria. Medir índices/planes con datos reales
antes de introducir búsquedas externas o modificar el bloqueo por usuario.

## Integridad, límites e integración futura

La prueba con dos conexiones MySQL comprueba slugs concurrentes, publicación,
ediciones obsoletas, guardados simultáneos y revocación de visibilidad bajo snapshot
previo. Un deadlock/timeout revierte la operación y devuelve una excepción de dominio
sin SQL. No se reintentan automáticamente operaciones con efectos físicos.

SQL directo, query builder y forceFill pueden omitir permisos, transiciones, revisiones
obligatorias y validaciones entre tablas. Las FKs/CHECKs no sustituyen los servicios;
no dar acceso SQL al cliente. La carga y la escritura del almacenamiento físico
siguen los límites básicos de BACK-02; available no significa análisis antimalware.

Pendientes: endpoints autenticados, CSRF/rate limiting/cuotas, escape de texto en UI,
enlaces externos con rel apropiado, antivirus y procesamiento multimedia, política
de conservación/purga histórica, reconciliación de archivos, métricas sin datos
privados, auditoría criptográfica si se requiere y operaciones de borrado legal.
No se modificó el frontend ni se concedieron roles automáticamente.

## Inventario exacto frente a HEAD

Creado (28 archivos):

- app/Enums/EditorialKind.php
- app/Enums/EditorialStatus.php
- app/Enums/LegalResourceType.php
- app/Enums/SavedContentType.php
- app/Exceptions/ContentLibraryException.php
- app/Models/Article.php
- app/Models/ContentRevision.php
- app/Models/EditorialEntry.php
- app/Models/LegalResource.php
- app/Models/SavedItem.php
- app/Policies/ArticlePolicy.php
- app/Policies/EditorialPolicy.php
- app/Policies/LegalResourcePolicy.php
- app/Policies/SavedItemPolicy.php
- app/Services/ContentLibrary/ContentAccess.php
- app/Services/ContentLibrary/EditorialMediaService.php
- app/Services/ContentLibrary/EditorialQuery.php
- app/Services/ContentLibrary/EditorialService.php
- app/Services/ContentLibrary/SavedItemService.php
- app/Services/ContentLibrary/SavedLibraryQuery.php
- config/content-library.php
- database/migrations/2026_10_23_140000_create_editorial_content.php
- database/migrations/2026_10_23_140001_create_content_revisions_and_saved_items.php
- database/migrations/2026_10_23_140002_add_editorial_attachment_marker.php
- database/seeders/EditorialPermissionSeeder.php
- docs/content-library.md
- tests/Feature/ContentLibraryConcurrencyTest.php
- tests/Feature/ContentLibraryTest.php

Modificado (7 archivos):

- app/Models/Attachment.php
- app/Models/User.php
- app/Services/Attachments/AttachmentService.php
- app/Services/Verification/VerificationIntegrity.php
- app/Services/Verification/VerificationService.php
- database/seeders/DatabaseSeeder.php
- tests/Feature/VerificationTest.php

VerificationTest conserva la comprobación de IDs/permisos previos y reconoce los
ocho permisos nuevos de DatabaseSeeder sin concederlos al administrador. El resto
son integraciones backend necesarias. Al iniciar, BACK-06 estaba en HEAD y el índice
vacío. El trabajo concurrente preexistente corresponde a recursos frontend, rutas,
Vite, package files y pruebas frontend, excluidos de este inventario.

No se preparó staging ni se hizo commit, push, merge o cambio de rama.

## Migraciones, seeders y validación

Se confirmó con bootstrap Laravel y SELECT DATABASE(): local/mysql en
plataforma_legal_v2 y testing/mysql en plataforma_legal_v2_test, ambos MySQL 8.0.46.
Se ejecutó migrate --pretend en ambos antes de aplicar únicamente las migraciones
incrementales 140000, 140001 y 140002. No se modificaron migraciones anteriores ni
se ejecutaron comandos destructivos de base de datos.

EditorialPermissionSeeder se ejecutó en desarrollo para registrar ocho permisos,
sin asignaciones de roles ni usuarios nuevos. En pruebas se ejecuta dentro de
transacciones o fixtures aislados; no se dejan permisos/datos de prueba persistentes.

Las pruebas transaccionales revierten sus fixtures. Las de concurrencia confirman
solo fixtures mínimos en testing y los eliminan selectivamente en finally. Los
archivos de prueba usan Storage::fake, fuera del almacenamiento real.

Resultados finales:

- Pruebas del módulo: 23 aprobadas, 337 assertions.
- Suite completa: 283 aprobadas, 9,935 assertions, incluyendo BACK-01 a BACK-06.
- Sintaxis PHP y Pint --test: 34 archivos PHP del alcance aprobados.
- migrate:status: las tres migraciones Ran, batch 16 local y batch 15 testing.
- Inventario contrastado con HEAD: 28 archivos creados y siete modificados.
- Formato y espacios finales: sin errores; índice de Git vacío.
- Verificación posterior a la suite: tablas editoriales, revisiones, pivotes, guardados,
  usuarios y adjuntos de prueba vacíos en testing; permisos y roles de prueba también
  revertidos. Las tablas editoriales de desarrollo permanecen vacías.
- Desarrollo: ocho permisos editoriales registrados, cero asignaciones automáticas a
  usuarios o roles.
