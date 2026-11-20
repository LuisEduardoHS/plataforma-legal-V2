# MEDIA-01 — Auditoría multimedia y evolución del almacenamiento

Fecha: 20 de noviembre de 2026. Corte: main efc4e5425d384223a44fc8f9d0baaba35e472c02.
Worktree: D:/projects/plataforma-legal-V2-media-audit. Rama: audit/media-storage.

## Método y aislamiento

Únicamente se consultó Git en el repositorio principal antes de crear este worktree. Se detectaron cambios pendientes de Antigravity en config/services.php y archivos de LEXA-02-G/H; no se copiaron, leyeron ni modificaron. El informe describe el HEAD confirmado, no ese trabajo pendiente. No se ejecutaron Laravel, migraciones, seeders, pruebas con escrituras ni conexiones a MySQL. No se inspeccionaron documentos privados, .env, objetos almacenados ni logs de usuarios. Único archivo creado en el proyecto: este documento. No hay commit/push.

Auditoría estática de código: escenarios reproducibles propuestos, no exploits ejecutados. No se confirma infraestructura efectiva, buckets, enlaces simbólicos reales, configuración PHP/nginx, capacidad de disco, rendimiento medido ni restauración de backups. No confundir configuración versionada con configuración efectiva de producción.

## Estado real

| Área | Implementado | Pendiente / limitación |
| --- | --- | --- |
| Adjuntos base | MIME servidor, extensiones, tamaños, hash, UUID, reserva durable, estados y descarga privada | Cuarentena, antivirus, cuotas acumuladas y reconciliación automática |
| Comunidad | Asociación a publicaciones y descarga según autorización del post, incluso pública cuando corresponde | No es un pipeline de imágenes/video inline con derivados, streaming ni CDN |
| Mensajes | Upload autenticado, asociación al mensaje y descarga por participante/visibilidad del historial | Sin transcodificación, range/streaming multimedia ni cuota de almacenamiento por usuario |
| Perfil | Subir, quitar y descargar fotografía propia privada | Tarjeta usa inicial; foto pública/avatar servido inline y portada personalizable no implementados |
| Editorial | Adjuntos de artículos/recursos y retención de revisiones; acceso por contenido | Sin optimización de imágenes ni entrega CDN pública |
| Verificación profesional | Evidencias privadas, fingerprint/hash y permiso diferenciado de lectura | Retención física necesita política operacional explícita y backups |
| LEXA | Interfaz anuncia adjuntos futuros y file input está deshabilitado | No hay flujo funcional de upload privado, extracción/OCR o entrega de documentos al modelo |
| Demo frontend | Helpers de Mensajes conservan fallback demo, ObjectURL y validación cliente | No confundir helper disponible con backend demo activo: el flujo HTTP de adjuntos sí existe |
| Video | Se aceptan MP4/WebM como adjuntos descargables | No hay FFmpeg, thumbnails, variantes, HLS ni validación de codecs/duración |

Límites versionados: imágenes 10 MiB, videos 100 MiB, documentos/otros 20 MiB. MIME admitidos: JPEG/PNG/GIF/WebP, MP4/WebM, PDF/TXT y MP3. Los inputs que anuncian DOCX no implican soporte servidor: config/attachments.php no lo admite. Los límites de PHP/proxy podrían impedir llegar a esos tamaños: no se verificaron.

## Inventario de implementación

- Base: app/Models/Attachment.php, app/Enums/AttachmentStatus.php, app/Policies/AttachmentPolicy.php, app/Services/Attachments/AttachmentValidator.php y AttachmentService.php; config/attachments.php y filesystems.php.
- Tabla attachments: propietario FK restrictiva, nombre original y UUID de almacenamiento, disk/path únicos, MIME/extensión/categoría, size_bytes, sha256, visibilidad, estados y marcas de borrado. Migración 2026_10_23_090000. Índices propietario/estado/fecha y estado/updated_at facilitan reconciliación; hash indexado no constituye deduplicación ni autorización.
- Comunidad: Post, PostService, CommunityAccess, CommunityMediaService, MediaController; pivote post_attachments y community_attached_at. Vistas components/community/post-card.blade.php y páginas Comunidad; resources/js/community/files.js.
- Mensajes: Message, Conversation, ConversationParticipant; MessageUploadService, MessageService, MessagingAccess y MessagingMediaService; Messaging/AttachmentController, MessagingRequest; message_attachments y messaging_attached_at. resources/js/messages.js y messages/attachments.js.
- Perfiles: UserProfile.photo_attachment_id; ProfileService valida propiedad/categoría/disponibilidad, ProfilePhotoController y ProfilePhotoRequest. pages/profile/edit.blade.php y components/profiles/card.blade.php. La portada gráfica SVG es decoración, no archivo subido por el usuario.
- Editorial: Article, LegalResource, EditorialEntry y revisiones; EditorialService, EditorialMediaService, ContentAccess y EditorialController; article_attachment, legal_resource_attachment, content_revision_attachments y editorial_attached_at.
- Verificación: VerificationRequest, VerificationEvidence, VerificationService, VerificationWorkflow, VerificationIntegrity y VerificationRequestPolicy; verification_evidences conserva hash/fingerprint/tamaño/MIME y attachments.verification_retained_at impide purga tras presentación.
- LEXA: components/lexa/composer.blade.php, resources/js/lexa.js: adjuntos no habilitados. La ingesta jurídica local de archivos de texto no equivale a documentos privados adjuntos por usuarios al chat.

### Rutas relevantes (routes/web.php)

| Ruta | Control |
| --- | --- |
| GET /comunidad/publicaciones/{post}/archivos/{attachment} | MediaController → CommunityMediaService; autorización de publicación y relación de adjunto |
| GET /articulos/{entry}/archivos/{attachment}, /recursos/{entry}/archivos/{attachment} | EditorialController::media → EditorialMediaService; contenido vigente/autorizado |
| POST /mensajes/{conversation}/adjuntos | MessageUploadService; usuario activo, conversación, bloqueo y validación |
| DELETE /mensajes/{conversation}/adjuntos/{attachment} | Retirada de upload con controles de Mensajes |
| GET /mensajes/{conversation}/mensajes/{message}/archivos/{attachment} | Participación, mensaje visible, relación del adjunto y estado |
| GET/POST/DELETE /perfil/fotografia | Propietario autenticado; GET devuelve descarga, no imagen pública |
| POST /verificacion/solicitudes/{dossier}/evidencias | Carga de evidencia propia autorizada |
| DELETE /verificacion/solicitudes/{dossier}/evidencias/{evidence} | Desasociación según estado; no implica borrar archivo retenido |
| GET /verificacion/solicitudes/{dossier}/evidencias/{evidence}/descargar | Propietario o revisor autorizado; fingerprint y contenido |

Los formularios de Comunidad y Editorial enlazan sus cargas/adjuntos a la creación y edición de contenidos; no se debe crear un endpoint de descarga genérico que eluda estas relaciones. Los nombres/rutas completos siguen en routes/web.php del corte; no se ejecutó route:list.

## Seguridad: controles presentes

AttachmentValidator.php:15-40 utiliza finfo sobre el archivo temporal y filesize real, no confía en MIME del navegador; exige extensión permitida para ese MIME y calcula SHA-256. El nombre original se limita y limpia de separadores/control; no se usa como clave física. AttachmentService.php:34-58 genera UUID y extensión canónica.

AttachmentPolicy restringe el acceso base al propietario activo, archivo disponible y privado. Cada servicio de contexto comprueba además publicación, contenido o conversación y pertenencia del archivo. Mensajes aplica visibilidad del historial y no concede acceso por conocer un ID. Las evidencias requieren permisos propios/revisor y comprobación de fingerprint. No se encontró un bypass confirmado de acceso entre usuarios en los caminos revisados; no equivale a una prueba exhaustiva de producción.

Las descargas usan nombre generado, application/octet-stream, Content-Disposition de descarga, nosniff, no-store y CSP sandbox. No se sirve contenido subido como HTML ejecutable desde las rutas revisadas. Esto reduce ejecución en navegador, pero no vuelve seguro un PDF al abrirlo en una aplicación local.

El disco attachments es privado, serve=false y raíz storage/app/attachments; storage:link apunta sólo a app/public. Con esa configuración el enlace estándar no publica adjuntos privados. AttachmentService::assertPrivateDisk también rechaza serve=true, URL pública y drivers fuera de local/s3. Una configuración incorrecta de root dentro del webroot o una política de bucket pública puede eludir la intención: el chequeo de flags no audita el servidor/bucket real.

## Hallazgos priorizados y escenarios

### A1 — Alto: consumo acumulado sin cuota de almacenamiento

Evidencia: AttachmentPolicy::create permite a toda cuenta activa; AttachmentValidator limita cada archivo, no bytes acumulados. MessageUploadService::upload admite tandas, con control de contexto pero sin reserva global de cuota. No se encontró un contabilizador de bytes por cuenta ni reconciliador de reservas.
Escenario propuesto: cuenta autorizada repite cargas válidas dentro de los límites/rate limits hasta agotar almacenamiento. Desasociar/cancelar no garantiza liberación física inmediata. Riesgo de disponibilidad/costo, no bypass de privacidad demostrado. Aceptación: reservar cuota atómicamente y liberar según estado, con pruebas concurrentes y límites diarios/globales; rate limiting solo no sustituye cuota.

### A2 — Alto a escala: I/O remoto bajo locks y copia completa por descarga

Evidencia: CommunityMediaService.php:24-64, MessagingMediaService.php:24-65 y EditorialMediaService.php:23-62 abren el objeto, lo copian a php://temp (umbral RAM 2 MiB) y verifican hash dentro de operaciones transaccionales con locks. AttachmentService::store/purge mantiene también I/O de storage en transacciones. VerificationIntegrity::buffer usa copia e integridad y es llamado desde workflow bloqueado.
Escenario: almacenamiento lento o varias descargas válidas de videos de 100 MiB prolongan locks, ocupan workers y disco temporal; se bloquean operaciones concurrentes. No se midió latencia real ni memoria/IOPS. No proponer quitar autorización/integridad para resolverlo. Aceptación: diseñar reserva/finalización y objetos inmutables verificados; mantener transacciones cortas y autorización antes de entregar bytes, con pruebas de revocación/fallos/remoto lento. Una URL firmada duradera introduce una ventana de acceso que debe decidirse explícitamente.

### A3 — Medio: archivo permitido no equivale a archivo inocuo

Evidencia: AttachmentValidator verifica firma MIME/extensión y tamaño, pero no hay escaneo, cuarentena ni decodificación/re-encode de imagen; búsqueda dirigida sin pipeline AV/FFmpeg/thumbnails. PDF puede tener contenido activo; JPEG puede contener GPS/EXIF; video puede ser patológico para un decodificador futuro.
Escenario: subir un PDF malicioso con MIME válido y conseguir que un destinatario autorizado lo abra, o publicar una foto con metadatos personales. No se ejecutó muestra maliciosa ni se confirma ejecución remota. Aceptación: cuarentena/escaneo con motor aislado, política de fallos cerrada y derivados sanitizados sin metadatos para exhibición pública. No enviar documentos sensibles a servicios externos de análisis sin decidir esa política.

### A4 — Medio: huérfanos/retención sin operación automatizada completa

Evidencia: AttachmentService reserva pending_upload y compensa fallos; requestDeletion/purge son idempotentes y protegen referencias. MessageUploadService sólo solicita borrado al compensar tandas. ProfilePhotoController::destroy sólo desvincula; una sustitución no elimina automáticamente el archivo anterior. No se encontró comando/scheduler de reconciliación multimedia en app/Console/routes/console.php.
Escenario: caída entre reserva y upload, formularios abandonados o cambios frecuentes de foto acumulan objetos/filas. No son pérdidas confirmadas de archivos. Aceptación: reporte dry-run de huérfanos y purga por lotes revalidando todas las referencias, antigüedad mínima, retención y estados; métricas y reintentos. No eliminar por edad o por ausencia en el perfil solamente.

### A5 — Medio: preparación cloud incompleta

Evidencia: filesystems.php tiene plantilla s3 genérica con url y sin visibilidad privada declarada; AttachmentService la rechazará tal como está para adjuntos. composer.json no declara league/flysystem-aws-s3-v3. No hay pruebas de contrato S3/R2, backups ni restore verificados por esta auditoría.
Escenario: cambiar ATTACHMENTS_DISK=s3 sin preparar un disco privado y el adaptador ocasiona errores; hacerlo público para evitar el error expone documentos. Es un riesgo de despliegue, no evidencia de exposición actual.

### A6 — Bajo/funcional: foto privada servida como descarga y multimedia no reproducible inline

ProfilePhotoController::show delega a streamDownload octet-stream; components/profiles/card muestra inicial. Community/Messaging/Editorial también fuerzan descarga. No prometer avatar público, galería o reproductor basándose en que se aceptan bytes. Debe diseñarse un endpoint de derivados seguros con MIME de imagen y autorización propia antes de usarlo en img/video.

No se han demostrado vulnerabilidades críticas de fuga entre usuarios o ejecución servidor. Los escenarios anteriores se basan en lectura, no en explotación o benchmark. SQL directo puede romper ownership/marcadores/retención; conservar controles de aplicación y restringir acceso DB.

## Consistencia y retención

Mantener la máquina de estados existente: pending_upload → available → pending_deletion → deleted. No borrar físicamente dentro de una transacción exterior cuyo rollback restaure una fila disponible: la base ya prohíbe esa composición para upload/purge. El objeto y MySQL no comparten una transacción distribuida; reservar clave antes del I/O y reconciliar fallos es necesario incluso con S3.

Las evidencias presentadas se retienen, Comunidad y Mensajes consultan referencias vigentes, y Editorial conserva referencias de revisiones. No acortar esas retenciones al cambiar de proveedor. Definir plazos y legal holds por clase con responsables; sin inventar períodos legales universales. Una revocación no recupera copias ya descargadas. Cifrado del proveedor no sustituye autorización ni backups.

## Arquitectura incremental recomendada

Mantener un monolito Laravel, MySQL como catálogo y Storage/Flysystem como frontera de objetos. Reutilizar AttachmentService y políticas de dominio. Local en desarrollo, un disco privado S3-compatible en producción; separar físicamente el bucket privado del bucket/origen de derivados públicos. No mover originales privados al disco public por tener una publicación pública.

Original privado inmutable y con hash; referencias del dominio en MySQL. Agregar gradualmente estado de análisis y tabla de derivados (parent attachment, variante, disk/key, MIME, tamaño, hash y estado). Trabajo asíncrono idempotente en cola Laravel para antivirus/normalización, miniaturas y más tarde video; outbox/registro durable sólo donde haga falta confirmar una tarea tras commit. No introducir microservicios ni duplicar el modelo de adjuntos por módulo.

Para público, sólo derivados aprobados y sin EXIF, claves versionadas y CDN; al retirar contenido, invalidación/TTL acordado y ventanas de caché explícitas. Para privado, ruta autorizada por recurso con stream/proxy; firmas cortas sólo después de evaluar revocación y headers. La política privada debe bloquear lectura anónima del bucket independientemente de flags Laravel. Upload directo futuro: prefijo/reserva propia, tamaño/tipo/checksum verificados después del upload y antes de available; una firma de subida no autoriza asociación a otro usuario.

Imágenes: límites de píxeles y tiempo, orientación normalizada, variantes thumbnail/feed/perfil, procesamiento aislado y sin preservar metadatos sensibles en público. Videos: límite de duración/resolución, miniatura, codecs permitidos, cola con timeout y presupuesto; HLS/ABR sólo si la demanda lo justifica. Documentos de LEXA: relación explícita con conversación/propietario, extracción aislada y limitada, nunca URLs privadas públicas ni instrucciones del documento en system prompt; no enviar al proveedor hasta autorización y estado de seguridad apto.

Backups: coordinar instantánea MySQL, objetos/versiones y manifiesto de hashes; cifrar, separar credenciales, probar restauración selectiva y desastre. Lifecycle/versioning del bucket debe respetar legal holds y contemplar borrado de versiones anteriores; no asumir que papelera o replicación son backup. Medir cuotas, temporales, fallos, cola, objetos pendientes y bytes por clase.

## Comparación conceptual de proveedores (sin cotización)

| Opción | Ventajas | Costos a evaluar | Dependencia / integración |
| --- | --- | --- | --- |
| Cloudflare R2 | Almacenamiento de objetos compatible con API S3; candidato para originales y derivados | GB-mes, operaciones, lecturas/listados, CDN/dominio y procesamiento aparte; comprobar condiciones vigentes de transferencia | Laravel mediante adaptador S3 y endpoint; probar diferencias API, IAM/firma/lifecycle. No aporta por sí mismo transformación de imágenes/video |
| Amazon S3 | Objetos privados y controles de acceso/lifecycle dentro de ecosistema AWS | GB-mes por clase, requests, transferencia, recuperación/retención mínima según clase, KMS, replicación y observabilidad | Adaptador S3; integración IAM y Block Public Access. Evitar acoplar reglas del dominio a servicios propietarios si se requiere portabilidad |
| Cloudinary | Servicio especializado en transformación y entrega de imágenes/video | Originales/derivados, transformaciones, procesamiento, almacenamiento y entrega según plan | Mayor dependencia de IDs/URLs y sintaxis de transformación; integrar como procesador opcional de media pública, no reemplazo automático del almacén de documentos jurídicos privados |

No se comparan precios ni se recomienda contratar sin volumen, región y requisitos de privacidad. Preferencia arquitectónica inicial: interfaz S3-compatible privada reutilizable; decidir R2/S3 mediante prueba de contrato y costo de carga real. Cloudinary sólo si el ahorro operacional de transformaciones compensa costo/acoplamiento.

Fuentes primarias consultadas: [S3 Block Public Access](https://docs.aws.amazon.com/AmazonS3/latest/userguide/access-control-block-public-access.html), [Cloudinary transformaciones](https://cloudinary.com/documentation/image_transformations). La consulta automatizada a la página de buckets públicos de R2 no estuvo disponible; su compatibilidad y condiciones deben revalidarse antes de aprovisionar, sin atribuirle precios o garantías no verificadas aquí.

## Roadmap y aceptación

| Etapa | Alcance y riesgo principal | Pruebas / criterio de aceptación |
| --- | --- | --- |
| MEDIA-02 | Seguridad/base: cuotas, cuarentena, reconciliación dry-run y disco privado configurable. Riesgo: purgar referencias o introducir exposición cloud | Testing aislado, fallos de upload/DB/delete, carreras de cuota y asociación, retención, acceso anónimo/ajeno denegado; ningún cambio de política de descarga implícito |
| MEDIA-03 | Derivados de imágenes de Comunidad y perfiles; conservar originales privados. Riesgo: EXIF/decompression bomb y caché pública tras retiro | Límites de píxeles, re-encode, ausencia EXIF, MIME real, permisos de derivados, tamaños/responsive y revocación; portada sólo con contrato backend propio |
| MEDIA-04 | Endurecer documentos/Mensajes y entrega privada sin I/O largo bajo locks. Riesgo: historial/bloqueo/revocación | Dos usuarios, cuenta suspendida, ocultamiento/archivo, integridad, fallo remoto, no exposición en CDN ni URLs largas; no rehacer la mensajería ya funcional |
| MEDIA-05 | Video: inspección, thumbnails y transcodificación asíncrona acotada; HLS opcional | Upload truncado, codecs/duración, timeout, cola/retry sin duplicados, eliminación de derivados, presupuesto y playback/range documentado |
| MEDIA-06 | Documentos privados de LEXA, extracción y contexto con consentimiento | Propiedad por conversación, límites páginas/tokens, archivos maliciosos/inyección, limpieza y revocación; nunca confundir corpus jurídico público con uploads privados |

### Primera implementación concreta recomendada

MEDIA-02 debe empezar por un inventario de objetos/estados con dry-run y reserva atómica de cuota, manteniendo el almacenamiento privado actual. Añadir pruebas de reconciliación/retención y fallos antes de automatizar borrado. Después preparar un disco privado de objeto y prueba de contrato local/S3 compatible. No comenzar por hacer públicos los archivos ni por reemplazar todas las descargas con URLs firmadas.

## Pendientes de verificación operacional

Políticas efectivas de bucket/webroot y storage:link real, PHP upload_max_filesize/post_max_size, capacidad/ubicación de temporales, ACL/credenciales, TLS, límites de red, cargas concurrentes y restauración de backup. Requieren una intervención autorizada en el entorno correspondiente; no se infieren del repositorio. No se inicia MEDIA-02.
