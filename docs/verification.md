# Verificación profesional (BACK-04)

## Alcance y fuentes de verdad

Es un flujo de revisión interna, sin endpoints, interfaces ni consultas oficiales.
Ni el formato de una cédula ni la aprobación de este flujo prueban autenticidad
oficial. Antes de operar comercialmente deben definirse procedimientos y fuentes
apropiadas para contrastar las credenciales, responsables y criterios de decisión.

ProfessionalProfile guarda el estado actual y verified_at. VerificationRequest
guarda el expediente, su compromiso de integridad y la última resolución.
VerificationEvent conserva el historial cronológico de acciones y decisiones.
La aprobación original permanece en el historial después de una revocación o
invalidación; el estado aprobado del expediente no se conserva artificialmente
cuando ya no respalda una verificación actual.

## Tablas e integridad

- verification_requests: perfil, estado, fechas de presentación/resolución,
  reviewed_by, motivo comunicable, profile_fingerprint y evidence_fingerprint.
- verification_evidences: solicitud, adjunto, tipo, SHA-256, tamaño, MIME y
  attachment_fingerprint. No almacena binarios ni rutas duplicadas.
- verification_events: solicitud, actor, acción, estados anterior/nuevo, fecha,
  motivo y adjunto relacionado cuando corresponde.
- attachments.verification_retained_at: retención de objetos presentados.
- professional_profiles.verification_status: añade revoked mediante migración nueva.

Las FKs usan RESTRICT para preservar perfiles, actores, documentos y decisiones.
Un índice único sobre una columna generada permite como máximo un expediente draft,
pending o approved por perfil. Los expedientes rejected, revoked e invalidated
liberan esa plaza y permiten un nuevo borrador, con ID e historial nuevos.
El mismo adjunto y el mismo contenido SHA-256 no pueden repetirse en un expediente,
aunque el contenido se haya cargado con nombres e IDs distintos.

Los CHECK de MySQL protegen la coherencia entre estado, fechas, responsable y motivo.
Se conserva el CHECK previo del perfil: verified exige verified_at; los demás
estados exigen fecha nula. No se modificaron migraciones anteriores de BACK-02/03.
La migración que introduce retención y revoked se declara sin reversión automática:
retirar esas garantías requiere un plan de datos explícito, no remapear decisiones
ni liberar documentos silenciosamente.

## Flujo de dominio

Resolver App\Services\Verification\VerificationService con el contenedor:

1. createDraft(actor, profileId): solo el propietario activo, sin otro expediente activo.
2. addEvidence(actor, requestId, attachmentId, VerificationEvidenceType): solo en borrador.
   removeEvidence permite corregir el borrador y registra el retiro.
3. submit(actor, requestId): exige título, cédula declarada, alguna especialidad activa
   y un documento de tipo professional_license. Comprueba nuevamente todas las
   evidencias, registra la huella del perfil y retiene los objetos. Marca el perfil pending.
4. pending(actor) lista la cola sin cédulas ni documentos. view devuelve el estado
   autorizado; review muestra datos materiales del perfil solo si aún coinciden con
   la presentación y registra la apertura de revisión.
5. approve/reject(actor, requestId, reason): resuelve solo pending. Aprobar exige
   propietario activo, huella vigente y documentos íntegros; actualiza perfil y fecha.
6. revoke(actor, requestId, reason): solo sobre approved; deja perfil revoked sin fecha.
7. history(actor, requestId) entrega eventos autorizados. downloadEvidence ofrece
   una descarga específica por expediente/evidencia y registra su lectura.

Toda decisión exige una justificación de 10 a 2000 caracteres. Los motivos son
comunicables al solicitante: no incluir cédulas, secretos, documentos completos ni
anotaciones internas sensibles. No hay notas internas separadas en esta fase.
No se solicita identificación oficial adicional como requisito obligatorio.

## Autorización

VerificationPermissionSeeder crea, sin conceder a ningún rol:

- verification.review: cola y consulta detallada de expedientes presentados.
- verification.evidence.read: lectura documental, además de review.
- verification.decide: aprobación/rechazo, además de review.
- verification.revoke: revocación, además de review.
- verification.audit: acceso al historial.

Los permisos deben otorgarse explícitamente por operación administrativa confiable.
Ser profesional, moderador o admin no los concede. Ningún revisor puede revisar,
aprobar, rechazar o revocar su propia solicitud; el propietario sí puede consultar
su estado, su historial comunicable y sus documentos. Los borradores no se abren a
revisores; un auditor con audit puede consultar su historial mínimo.

Se vuelve a leer y bloquear el estado persistido del actor. Los usuarios suspendidos,
deshabilitados o pendientes de eliminación no operan. Un revisor activo puede
rechazar o revocar un expediente de un propietario inactivo, pero no aprobarlo.
Los modelos bloquean asignación masiva y las operaciones no reciben estados finales
ni IDs arbitrarios de revisores en datos del cliente.

RolePermissionSeeder ahora añade sus permisos base en lugar de sincronizar una
lista cerrada: resembrar no retira concesiones explícitas posteriores. Los cinco
permisos nuevos se integran en DatabaseSeeder sin crear usuarios o roles privilegiados.

## Documentos privados e integridad

AttachmentPolicy permanece intacta: un revisor no puede usar AttachmentService
para leer archivos privados ajenos en general. El acceso de verificación exige
permiso, expediente presentado y pertenencia exacta de la evidencia al expediente.
Al asociar se comprueban propietario, visibilidad privada, estado available,
categoría image/document y contenido almacenado real. El máximo inicial es de
10 evidencias de hasta 20 MiB cada una; no se admiten video/audio como evidencias.

La huella HMAC del adjunto liga propietario, identificador, clave de almacenamiento,
nombre, categoría, extensión, MIME, tamaño y SHA-256. La huella HMAC del perfil liga
su ID, campos materiales y especialidades. No duplica la cédula en texto plano ni
guarda un hash simple del número, susceptible de enumeración.
La cédula sigue cifrada únicamente en ProfessionalProfile.

evidence_fingerprint sella por HMAC el conjunto exacto de evidencias: ID de solicitud,
IDs, tipos y metadatos de cada documento, ordenados por ID. Se comprueba al revisar,
aprobar y descargar, para detectar también eliminaciones, inserciones o cambios de
tipo mediante SQL directo. La huella del perfil es independiente: una edición que
invalida el perfil no impide auditar sus documentos originales intactos.
La migración 110005 añade el sello sin firmar automáticamente solicitudes antiguas.
Un expediente presentado sin este sello falla de forma cerrada y requiere una
reconciliación auditada y una nueva presentación; no se presume íntegro.

Se verifican las huellas y el SHA-256 de los bytes al presentar, aprobar y descargar.
La descarga comprueba primero un buffer temporal acotado y después transmite esos
mismos bytes como attachment, sin caché ni URL pública. El buffer usa hasta 2 MiB
de memoria y puede usar el directorio temporal del servidor; se cierra al terminar.
No retorna rutas, claves ni errores internos de almacenamiento.

Presentar activa verification_retained_at bajo bloqueo del adjunto. AttachmentService
rechaza desde entonces requestDeletion y purge, también después de rechazo,
revocación o invalidación, para preservar evidencia de las decisiones. En borrador
todavía puede eliminarse un archivo: la presentación posterior detectará que dejó
de estar disponible. Retirar una evidencia de borrador conserva un evento mínimo.
El servicio comprueba además la relación con expedientes presentados: borrar solo
la marca de retención por SQL no permite purgar el archivo. Las actualizaciones
normales de instancias Attachment bloquean cambios a metadatos de documentos
retenidos, incluso si el modelo se cargó antes de presentar la solicitud.
La compensación de una carga fallida vuelve a leer el registro bajo bloqueo y no
borra objetos cuyo registro ya quedó disponible o retenido.

La retención actual no tiene vencimiento ni liberación automática. Antes de producción
se necesita una política de conservación y eliminación debidamente justificada y un
flujo autorizado y auditado para liberar retenciones. No extender retención indefinida
a producción sin ese diseño.

Los cambios directos en la base, en el bucket o en el disco quedan fuera del servicio.
Una alteración o eliminación externa falla de forma cerrada al comprobar el objeto;
no hay escáner periódico que la descubra de inmediato ni revocación automática por
un fallo externo. Se requiere reconciliación operativa y procedimientos de incidentes.
MIME y hash no sustituyen antimalware ni la validación documental.

## Ediciones, transacciones y auditoría

ProfileService comparte la definición de campos materiales con VerificationIntegrity.
Editar esos campos o cambiar especialidades invalida un expediente pending/approved,
registra actor y motivo, limpia verified_at y deja el perfil unverified, todo dentro
de la misma transacción. Un cambio idéntico o de preferencias/contacto no lo invalida.
Un borrador recoge los datos vigentes al presentarse; el historial de expedientes
rechazados/revocados no se reabre al editar.

Los bloqueos siguen usuarios en orden de ID, perfil, solicitud y documentos en
orden de adjunto. El bloqueo de perfil serializa decisiones y ediciones. Los índices
únicos complementan las comprobaciones de dominio. Si falla la auditoría se revierte
la decisión y su estado en ProfessionalProfile; si falla una evidencia al presentar,
se revierte también la retención parcial.

Las instancias VerificationEvent impiden actualizar o eliminar incluso mediante
saveQuietly, deleteQuietly o incrementos. Las instancias VerificationEvidence
rechazan actualizaciones y el borrado de documentos presentados, también sin eventos.
Las instancias VerificationRequest protegen la pertenencia, las transiciones y los
sellos presentados; esto no concede autorización para resolverlas fuera del servicio.
Las FKs protegen
contra borrados en cascada. Esto no es un registro inviolable frente a administradores
de base o SQL directo: en producción hay que separar permisos de INSERT/SELECT de
auditoría, respaldar el historial y controlar el acceso operativo. No se añaden
triggers, servicios externos ni mecanismos criptográficos de no repudio.
La huella permite detectar cambios, pero no reconstruye versiones completas de
datos del perfil; las evidencias y eventos son el soporte histórico preservado.

Límite de confianza: Eloquent where(...)->update/delete/upsert, consultas con
toBase() y DB::table/statement omiten los controles de instancia. Pueden modificar
auditoría o escribir estados válidos por CHECK sin permisos de usuario ni eventos.
forceFill/save de solicitudes tampoco reemplaza la autorización de VerificationService,
y saveQuietly de Attachment omite sus eventos. Quien controle SQL y los sellos o
APP_KEY puede alterar más de una protección a la vez. No hay triggers ni una cuenta
SQL diferenciada por actor de negocio: solo el servicio es una entrada autorizada.
Los consumidores no deben recibir modelos mutables o builders como API de escritura.
El acceso administrativo a BD y Storage necesita privilegios mínimos y auditoría externa.

APP_KEY se usa para cifrar y como clave de HMAC. Su rotación necesita un plan explícito
para las huellas de expedientes vigentes y las claves de descifrado previas; no
rehashar expedientes automáticamente ni perder el acceso a documentos históricos.

## Pruebas y despliegue

Las pruebas funcionales usan Storage::fake y transacciones, exclusivamente en
plataforma_legal_v2_test. La prueba de concurrencia usa dos conexiones reales a
esa misma base, espera MySQL 1205 al competir por una decisión y verifica que el
reintento obsoleto no la sobrescriba. Sus fixtures confirmados se eliminan por IDs
específicos al finalizar; no usa migraciones destructivas.

Antes de exponer HTTP faltan autenticación de endpoints, límites de solicitudes,
protección CSRF cuando corresponda, mapeo de errores y APP_DEBUG=false. También
faltan antimalware, procedimientos oficiales de contraste, retención, monitoreo,
copias de seguridad, cifrado operativo y aprovisionamiento controlado de revisores.

## Archivos del módulo

Inventario explícito contra HEAD (32 archivos; 23 nuevos y 9 modificados):

- `app/Enums/ProfessionalVerificationStatus.php`
- `app/Enums/VerificationAction.php`
- `app/Enums/VerificationEvidenceType.php`
- `app/Enums/VerificationRequestStatus.php`
- `app/Exceptions/VerificationException.php`
- `app/Models/Attachment.php`
- `app/Models/ProfessionalProfile.php`
- `app/Models/VerificationEvent.php`
- `app/Models/VerificationEvidence.php`
- `app/Models/VerificationRequest.php`
- `app/Policies/VerificationRequestPolicy.php`
- `app/Services/Attachments/AttachmentService.php`
- `app/Services/Profiles/ProfileService.php`
- `app/Services/Verification/VerificationIntegrity.php`
- `app/Services/Verification/VerificationService.php`
- `config/verification.php`
- `database/migrations/2026_10_23_110000_prepare_professional_verification.php`
- `database/migrations/2026_10_23_110001_create_verification_requests_table.php`
- `database/migrations/2026_10_23_110002_create_verification_evidences_table.php`
- `database/migrations/2026_10_23_110003_create_verification_events_table.php`
- `database/migrations/2026_10_23_110004_add_verification_evidence_content_unique.php`
- `database/migrations/2026_10_23_110005_add_verification_evidence_fingerprint.php`
- `database/seeders/DatabaseSeeder.php`
- `database/seeders/RolePermissionSeeder.php`
- `database/seeders/VerificationPermissionSeeder.php`
- `docs/attachments.md`
- `docs/profiles.md`
- `docs/verification.md`
- `tests/Feature/VerificationConcurrencyTest.php`
- `tests/Feature/VerificationTest.php`
- `tests/Support/VerificationFixtures.php`
- `tests/Unit/VerificationRequestStatusTest.php`

Excluidos: resources/, public/, routes/web.php, vite.config.js, package.json,
package-lock.json y cualquier otro trabajo concurrente ajeno al inventario.
