# BACK-06 — Mensajería privada

## Alcance y uso

Backend para conversaciones directas, sin endpoints, Blade, WebSockets, Redis, Reverb,
servicios externos ni cambios de permisos. Los profesionales utilizan su usuario
existente. No hay cifrado de extremo a extremo ni confirmación de entrega a dispositivos.

```php
$conversations = app(\App\Services\Messaging\ConversationService::class);
$messages = app(\App\Services\Messaging\MessageService::class);
$queries = app(\App\Services\Messaging\MessagingQuery::class);

$chat = $conversations->start($actor, $recipientId);
$message = $messages->send($actor, $chat->id, [
    'content' => 'Hola, quisiera consultar un asunto de arrendamiento.',
    'attachment_ids' => [],
    'client_token' => (string) \Illuminate\Support\Str::uuid(),
]);
$page = $queries->history($actor, $chat->id, size: 20);
$conversations->markRead($actor, $chat->id, $message->id);
```

Los servicios reciben un User autenticado por la futura capa HTTP. No aceptan IDs
de actor del cliente. Se recarga y comprueba su estado persistido en cada operación.
Los servicios devuelven modelos internos para operaciones de dominio; las consultas
producen DTO explícitos. No serializar usuarios, participantes o relaciones crudas
desde futuros controladores.

## Esquema, índices y relaciones

| Tabla | Campos y restricciones principales | Índices adicionales |
| --- | --- | --- |
| conversations | id; user_low_id y user_high_id FK users; UNIQUE(par); CHECK(low < high); activity_at y timestamps | activity_at/id, low/activity_at/id, high/activity_at/id |
| conversation_participants | PK(conversation_id,user_id); FKs a conversación/usuario; archived_at; hidden_at; hidden_through_message_id; last_read_message_id; last_read_at; timestamps | user_id/hidden_at/archived_at/conversation_id |
| messages | id; conversation_id; sender_id; content nullable; status sent/deleted; version; client_token UUID nullable; request_hash; sent_at; edited_at; deleted_at; timestamps | UNIQUE(conversation_id,id), UNIQUE(conversation_id,sender_id,client_token), conversation_id/status/id, sender_id/id |
| message_attachments | PK(message_id,attachment_id); FKs; position; timestamps; UNIQUE(message_id,position) | attachment_id/message_id |
| message_user_hides | PK(message_id,user_id); conversation_id; created_at | FKs compuestas de pertenencia a mensaje y participante |
| user_blocks | PK(blocker_id,blocked_id); FKs users; CHECK(blocker <> blocked); timestamps | blocked_id/blocker_id |
| attachments (ampliación) | messaging_shared_at timestamp nullable, oculto en serialización | Acceso por PK |

Todas las FKs usan RESTRICT para conservar metadatos e historial; este módulo no
ofrece borrado físico de conversaciones, mensajes o usuarios con referencias. MySQL
agrega índices de soporte de FKs. No se modificaron migraciones anteriores.

La FK compuesta messages(conversation_id,sender_id) exige que el remitente sea un
participante registrado. Las FKs compuestas de marcadores de lectura/ocultamiento
solo aceptan mensajes de su misma conversación. message_user_hides exige tanto
mensaje como participante de esa conversación. Un CHECK mantiene la coherencia de
status/deleted_at/content en mensajes eliminados.

El par canónico define los únicos dos usuarios autorizados. ConversationService crea
los dos participantes en la misma transacción; las operaciones de historial y dominio
rechazan conversaciones que no tengan exactamente esos dos registros. La cardinalidad
de participantes es una regla del servicio, no un trigger SQL. Una fila participante
inyectada por SQL nunca concede acceso a alguien fuera del par.

Los modelos ConversationParticipant y UserBlock representan claves compuestas; sus
actualizaciones se hacen mediante consultas explícitas del servicio, no con save/delete
de una instancia sin PK simple. Todos los modelos nuevos protegen sus atributos de
asignación masiva. Las relaciones de User incorporan participantes, mensajes enviados
y usuarios bloqueados.

## Conversaciones, autorización y bloqueo

ConversationService::start normaliza el par, bloquea ambos usuarios en orden de ID y
recupera la conversación existente sin duplicarla. Una nueva conversación requiere
ambas cuentas activas y ausencia de bloqueo en cualquiera de los sentidos. La
restricción única y el CHECK de MySQL impiden duplicar o invertir el par. Un chat
existente puede recuperarse aunque el destino se haya desactivado o exista bloqueo;
eso no permite enviar mensajes nuevos ni restaura historial ocultado.

ConversationPolicy autoriza creación, lectura, envío y gestión individual.
MessagingAccess vuelve a comprobar membresía canónica, participantes y cuenta activa
antes del historial, lectura, archivos o cambios. MessagePolicy permite editar o
eliminar para todos únicamente al remitente. No hay bypass administrativo, ni acceso
automático por roles admin/moderator o por verificación profesional. Los permisos
generales existentes no abren conversaciones privadas.

block/unblock son idempotentes y solo gestionan la dirección del actor. Bloquear en
cualquiera de los dos sentidos impide crear otro chat, enviar y editar mensajes en
ambos sentidos. No elimina mensajes, adjuntos, lectura ni preferencias. Ambos pueden
seguir consultando el historial accesible y el remitente puede eliminar mensajes
propios para todos. Solo quien estableció el bloqueo puede quitarlo. Se puede bloquear
o desbloquear una cuenta inactiva; se requiere que el actor esté activo.

Los usuarios suspendidos, deshabilitados o pendientes de eliminación no operan ni
consultan el módulo. El interlocutor activo conserva acceso privado al historial y
gestión individual, pero no puede enviar/editar mientras el otro esté inactivo.

## Mensajes, edición e idempotencia

MessageService::send acepta exclusivamente content, attachment_ids y client_token.
Un mensaje requiere texto no vacío o archivos válidos. Se recortan espacios Unicode
extremos, se rechazan controles y se limita el texto a 10,000 caracteres por defecto.
HTML se conserva como texto no confiable: la presentación futura debe escapar con
Blade/textContent, sin interpretar HTML del usuario.

sent significa almacenado por el servidor. No existe un estado delivered. edit
requiere expectedVersion, contenido accesible y no bloqueado; modifica solo texto,
conserva adjuntos e incrementa versión/edited_at. Una edición no vacía un mensaje que
no tenga archivos. Las versiones obsoletas fallan sin modificarlo.

client_token es opcional; un UUID estable por intento lógico permite reintentar sin
crear mensajes duplicados. La unicidad se aplica por conversación/remitente/token.
request_hash interno vincula el token al texto normalizado y orden de archivos;
reutilizarlo con otros datos se rechaza. Un reintento devuelve el estado actual del
mismo mensaje, aunque se haya editado o eliminado. Sin token no se promete
idempotencia de envíos. No registrar tokens, hashes ni contenido en logs de transporte.

## Lectura

markRead avanza last_read_message_id/last_read_at únicamente hacia un mensaje existente
y accesible de esa conversación, sin retroceder ni reescribir la fecha al repetir el
mismo marcador. Marcar un mensaje implica haber leído hasta ese punto del historial
visible; la futura UI debe invocarlo solo al mostrar efectivamente ese contenido.

Los no leídos son mensajes sent de otra persona, posteriores al marcador y al límite
de historial visible, sin ocultamiento individual. unreadCount incluye archivados y
excluye chats ocultos. inbox devuelve el conteo de cada chat seleccionado; history
acepta unreadOnly. Los mensajes eliminados y los propios no cuentan. Se calcula por
SQL, sin contadores persistidos ni lectura completa del historial en memoria.

history incluye read_by_other para mensajes propios: compara el marcador del otro
participante y excluye mensajes que el otro ocultó individualmente o en su historial.
No expone las preferencias privadas ni el marcador crudo del otro. participants
devuelve ambos alias públicos y únicamente el marcador/fecha del solicitante.
Los DTO no exponen correo, contraseña, licencia profesional, rutas, tokens o hashes.
Un perfil privado se muestra como «Usuario», sin username privado.

## Archivado, ocultamiento y eliminación

- archive(actor,chatId,true/false) cambia solo archived_at del actor. No altera
  lectura ni elimina historial; se consulta con inbox(archived: true).
- hide(actor,chatId) oculta el chat de la bandeja y el historial propio hasta el último
  mensaje actual, mediante hidden_through_message_id. No marca nada como leído ni
  afecta al interlocutor. Un mensaje nuevo reaparece el chat para ambos, conservando
  el límite de historial y el archivado de cada uno. No hay restauración del historial
  ocultado por esta API.
- hideForMe(actor,messageId) oculta ese mensaje solo al actor, idempotentemente.
  Se recalcula el último mensaje visible; no cambia el mensaje ni las preferencias
  ajenas, y no libera sus archivos.
- deleteForEveryone(actor,messageId,expectedVersion) cambia a deleted, borra el texto,
  registra deleted_at e incrementa versión. Deja un tombstone accesible a los
  participantes que no lo hayan ocultado. No ofrece restauración ni historial de
  versiones de texto. Los vínculos de archivos quedan como metadatos; ya no se sirven
  por ese mensaje. La operación es idempotente una vez eliminado.

El bloqueo nunca elimina evidencia automáticamente. La eliminación para todos es una
acción expresa del remitente. No existe todavía un régimen de conservación legal de
mensajes, auditoría completa de cambios o acceso administrativo de investigación;
deben definirse antes de un despliegue que los requiera.

## Archivos y compatibilidad BACK-02/BACK-04/BACK-05

Solo archivos disponibles, privados, propios y admitidos por config/attachments.php.
Se admiten image/video/document/other (por ejemplo mp3) con MIME/extensión/categoría
coherentes. No se confía en nombres proporcionados al enviar. attachment_ids es una
lista ordenada sin duplicados, con un máximo configurable de cuatro.

Se rechazan verification_retained_at y cualquier asociación a evidencia, incluida una
solicitud en borrador. Al enviar se fija messaging_shared_at una sola vez; el modelo
protege su modificación accidental. VerificationService e Integrity rechazan la marca
o vínculos de mensajería, incluso después de eliminar el mensaje. La migración de esa
marca requiere un plan explícito para revertirse. No detecta copias subidas como
otros archivos. Los controles y huellas de verificación anteriores se conservan.

Los adjuntos pueden reutilizarse en mensajes propios. Publicarlos después en Comunidad
es una decisión expresa de su propietario mediante el servicio de posts; enviar por
chat no los publica. AttachmentService bloquea requestDeletion y purge mientras
exista cualquier mensaje no eliminado que los referencie, además de las restricciones
de Comunidad y verificación. Archivar u ocultar mensajes para ambos no libera archivos.
Eliminar todos los mensajes que los usen permite el flujo de borrado del propietario
si tampoco hay otras referencias o retenciones. No hay borrado físico automático.

MessagingMediaService::download(actor,conversationId,messageId,attachmentId) exige
participación y mensaje visible/no eliminado, comprueba la asociación exacta y vuelve
a validar propiedad, disponibilidad, retención y disco privado. Comprueba tamaño y
SHA-256 del contenido antes de responder. La copia para streaming usa hasta 2 MiB
de memoria y después espacio temporal del sistema. Devuelve octet-stream, no-store,
nosniff y CSP sandbox. Un bloqueo conserva acceso al archivo histórico; ocultar el
mensaje o el historial revoca ese acceso por contexto para ese participante.

No se relaja AttachmentPolicy ni se generan URLs públicas o acceso permanente.
La carga física se realiza antes mediante AttachmentService::store, fuera de
transacciones externas; send solo vincula archivos existentes. Las pruebas usan
Storage::fake, no el almacenamiento real.

## Consultas, rendimiento y concurrencia

MessagingQuery::inbox pagina por activity_at DESC, id DESC; history por id DESC.
Aceptan cursores validados y tamaños entre 1 y 50. Inbox permite filtro exacto de
participante mediante participantId sobre las propias conversaciones; no implementa
búsqueda global de nombres privados. No hay indexadores de texto privado.

El último mensaje visible y los no leídos usan subconsultas indexadas que respetan
el ocultamiento individual. Eager loading carga interlocutor/perfil y adjuntos del
último mensaje en cantidad fija de consultas. Una prueba compara bandejas de uno y
cinco chats y exige el mismo número de consultas (máximo seis). No carga todo el
historial ni ejecuta una consulta PHP por chat o archivo.

Las escrituras bloquean ambos usuarios en orden, luego conversación/participantes,
mensajes y archivos ordenados. Así se serializan creación, envío, edición, lectura y
bloqueo. Las lecturas de idempotencia usan lockForUpdate para ver confirmaciones
recientes incluso con un snapshot REPEATABLE READ previo. Los errores SQL revierten
la transacción y se traducen a MessagingException sin detalles privados. No hay
reintentos automáticos de operaciones físicas.

La serialización por usuario prioriza integridad en esta fase y puede limitar el
rendimiento para cuentas de alto volumen. Medir con datos reales antes de reducir
bloqueos o añadir particionamiento, cachés o contadores. Los cursores son deterministas
para un conjunto estable; nuevas actividades pueden reordenar una bandeja abierta.

La revisión final añadió lecturas actuales con bloqueo compartido al historial y
a sus subconsultas de ocultamiento. Un snapshot REPEATABLE READ anterior no debe
volver a mostrar texto eliminado para todos ni mensajes ocultados individualmente.
La misma condición de visibilidad protege las descargas de adjuntos. Una regresión
entre dos conexiones verifica ambos casos después de confirmar los cambios.

## Límites y evolución

SQL directo, query builder o forceFill pueden omitir reglas de dominio (cardinalidad,
pertenencia canónica, marcadores monotónicos, bloqueo y autorización). Las FKs,
CHECKs e índices no sustituyen estos servicios. El cliente nunca debe tener acceso
SQL. No se ofrece una frontera frente a un administrador de base de datos.

Para chats grupales se necesita separar el identificador único del par del esquema
de conversaciones, permitir membresías con alta/baja y vigencia, definir permisos de
grupo y revisar acceso histórico, lectura y archivos. No basta insertar un tercer
participante en esta versión.

Los servicios no dependen de transporte. Notificaciones, escritura y Reverb podrán
consumir eventos después del commit; para entrega fiable se recomienda añadir un
outbox transaccional con IDs mínimos, canales privados autorizados y consumidores
idempotentes. No se han emitido eventos de entrega ni instalado esa infraestructura.

Pendientes de producción: endpoints autenticados, CSRF cuando corresponda, rate
limiting y cuotas, manejo HTTP de autorización/validación, antivirus/cuarentena,
límites de medios y reproducción/rangos, cifrado en reposo y gestión de llaves,
retención legal y backups, métricas sin contenido privado y reconciliación de
almacenamiento. available conserva el significado básico de BACK-02; no certifica
ausencia de malware. La futura UI debe escapar texto y marcar lectura correctamente.

## Inventario exacto frente a HEAD

Creado (20 archivos):

- app/Enums/MessageStatus.php
- app/Exceptions/MessagingException.php
- app/Models/Conversation.php
- app/Models/ConversationParticipant.php
- app/Models/Message.php
- app/Models/UserBlock.php
- app/Policies/ConversationPolicy.php
- app/Policies/MessagePolicy.php
- app/Services/Messaging/ConversationService.php
- app/Services/Messaging/MessageService.php
- app/Services/Messaging/MessagingAccess.php
- app/Services/Messaging/MessagingMediaService.php
- app/Services/Messaging/MessagingQuery.php
- config/messaging.php
- database/migrations/2026_10_23_130000_create_direct_conversations.php
- database/migrations/2026_10_23_130001_create_private_messages.php
- database/migrations/2026_10_23_130002_add_attachment_messaging_marker.php
- docs/messaging.md
- tests/Feature/MessagingConcurrencyTest.php
- tests/Feature/MessagingTest.php

Modificado (5 archivos, integración backend necesaria):

- app/Models/Attachment.php
- app/Models/User.php
- app/Services/Attachments/AttachmentService.php
- app/Services/Verification/VerificationIntegrity.php
- app/Services/Verification/VerificationService.php

Fuera del alcance: recursos frontend, public, routes/web.php, Vite, package files y
pruebas frontend concurrentes. BACK-05 ya estaba en HEAD al iniciar BACK-06; el índice
estaba vacío. La preparación final se limita a los 25 archivos de este inventario;
no se hizo commit, push o cambio de rama.

## Validación

Se confirmó mediante bootstrap Laravel y SELECT DATABASE(): local/mysql en
plataforma_legal_v2 y testing/mysql en plataforma_legal_v2_test, ambos MySQL 8.0.46.
Se ejecutó migrate --pretend antes de aplicar las tres migraciones incrementales
130000, 130001 y 130002 en ambas bases. No se sembraron datos de desarrollo ni se
ejecutaron comandos destructivos, rollback o reconstrucción de bases.

Las pruebas transaccionales revierten sus fixtures; las de concurrencia confirman
fixtures mínimos para dos conexiones a la base testing y los eliminan selectivamente
en finally. Se comprueban bloqueos reales, recuperación del par, unicidad de MySQL,
idempotencia con snapshot previo, versiones obsoletas, lectura monotónica y bloqueo
frente a envío. La prueba de fallo parcial revierte mensaje, pivote, marca de archivo
y estado de bandeja después de haber asociado archivos.

- Pruebas de mensajería: 22 aprobadas, 338 assertions.
- Suite completa de Laravel: 260 aprobadas, 9,596 assertions.
- Sintaxis PHP y Pint --test: 24 archivos PHP del alcance aprobados.
- migrate:status: las tres migraciones figuran Ran en ambas bases (batch 15 local,
  batch 14 testing).
- Comprobación posterior a las pruebas: sin usuarios, conversaciones, participantes,
  mensajes, pivotes, bloqueos, adjuntos ni expedientes de prueba persistentes en
  plataforma_legal_v2_test. Las tablas nuevas de desarrollo permanecen vacías.
- Inventario contrastado con HEAD: 20 archivos creados y cinco modificados. El staging
  final contiene exclusivamente esos 25 archivos, con formato verificado.
