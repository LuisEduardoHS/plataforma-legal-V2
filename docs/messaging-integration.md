# INTEGRACIÓN-05 — Mensajería privada funcional

Base: `main`, `1cffaefa5e81619a8eeceb02e2f36108997955a9`, árbol e índice inicialmente
limpios. Implementación HTTP/Blade sobre BACK-06, sin migraciones, tablas nuevas,
dependencias, WebSockets, Redis, Reverb, permisos nuevos ni seeders de desarrollo.
No se hizo staging, commit, push, merge o cambio de rama. Otros worktrees intactos.

## Inspección y diferencias respecto de la demo

Se revisaron rutas, modelos, enums, policies, servicios y consultas de BACK-06;
BACK-02 y sus retenciones; auditoría, autenticación, perfiles, directorio y Comunidad;
vistas, componentes, CSS, messages.js, attachments.js y sus consumidores.

La demo mantenía conversaciones/mensajes en memoria, simulaba leído al seleccionar,
admitía DOCX, límites de video/total diferentes y previews mediante URLs locales.
El flujo activo ahora consulta MySQL, confirma escrituras HTTP, aplica la lectura
explícita, admite únicamente formatos del dominio y solo descarga por contexto.
No simula entrega a dispositivos, especialidad ni verificación de un contacto.
No se añade búsqueda global de identidades o texto privado no soportada por BACK-06.
Activas/Archivadas sustituyen el filtro local de todas/no leídas; los no leídos son
conteos reales del dominio. Los demos ajenos no se modifican.

LEXA consume las exportaciones antiguas de attachments.js, incluida su validación.
Se conserva esa compatibilidad únicamente para su demo: los formularios de Mensajes
siempre pasan reglas reales del servidor, rechazan DOCX y no importan las funciones
de creación/liberación de previews. Se agregó regresión para ese consumidor.

## Arquitectura y servicios reutilizados

- `InboxController`: bandeja, chat y sondeo con snapshots de DTO explícitos. La
  bandeja se materializa en su propia transacción, liberando sus bloqueos compartidos
  antes de adquirir los del par seleccionado para estado/historial/compositor.
  Cada snapshot conserva las lecturas actuales de las consultas auditadas; no se
  mantiene una transacción amplia con bloqueos de varios interlocutores y del chat.
- `ConversationController`: start, archive, hide, markRead y block/unblock de
  `ConversationService`; nunca inserta participantes ni decide transiciones por SQL.
- `MessageController`: send, edit, deleteForEveryone y hideForMe de `MessageService`.
  Comprueba la correspondencia conversación/mensaje antes de mutaciones por ID.
- `AttachmentController` y `MessageUploadService`: preparación privada antes del
  envío, coordinación de cargas fuera de transacciones exteriores, compensación y
  descarga mediante `AttachmentService` y `MessagingMediaService`.
- `MessagingRequest`: campos admitidos por acción, UUID obligatorio, límites,
  validación de filtros/cursores y conservación segura del texto/versión enviados.
- `MessagingQuery`: ampliación de lectura para estado de conversación y detalle
  individual autorizado; incorpora nombre/extensión de adjuntos al DTO de archivo.
  El DTO de participante conserva sus tres claves: id, display_name y username.
  La capacidad de enlace público se devuelve solo en el nuevo estado del chat;
  las cuentas inactivas no presentan identidad pública ni enlace al perfil.

`ConversationService`, `MessageService`, `MessagingAccess`, `MessagingMediaService`,
policies, modelos, enums, esquema y reglas de escritura de BACK-06 se conservan.
El estado de la UI orienta controles; los servicios autorizan nuevamente bajo sus
bloqueos, usando cuentas y preferencias vigentes.

Las 17 rutas de Mensajes usan bloqueo de sesión Laravel (`block(60, 10)`) para
serializar sus peticiones y proteger token/adjuntos preparados frente al sondeo u
otra pestaña de esa sesión. Usa el cache store existente con locks atómicos; no se
introduce infraestructura nueva. Un timeout devuelve 503 sanitizado. Es un bloqueo
del transporte, distinto de los bloqueos de usuarios/conversación de BACK-06.

## Rutas HTTP

Todas requieren autenticación, estado persistido activo y autorización de contexto.
Los IDs de ruta son positivos, de hasta 18 dígitos. No hay bypass por admin/moderator.

| Método | Ruta | Nombre |
| --- | --- | --- |
| GET/HEAD | `/mensajes` | `messages` |
| POST | `/mensajes/conversaciones/{recipient}` | `messages.start` |
| GET/HEAD | `/mensajes/{conversation}` | `messages.show` |
| GET/HEAD | `/mensajes/{conversation}/actualizaciones` | `messages.updates` |
| POST | `/mensajes/{conversation}/mensajes` | `messages.send` |
| GET/HEAD | `/mensajes/{conversation}/mensajes/{message}/editar` | `messages.edit` |
| PUT | `/mensajes/{conversation}/mensajes/{message}` | `messages.update` |
| DELETE | `/mensajes/{conversation}/mensajes/{message}` | `messages.destroy` |
| POST | `/mensajes/{conversation}/mensajes/{message}/ocultar` | `messages.message.hide` |
| POST | `/mensajes/{conversation}/lectura` | `messages.read` |
| POST | `/mensajes/{conversation}/archivo` | `messages.archive` |
| POST | `/mensajes/{conversation}/ocultar` | `messages.hide` |
| PUT / DELETE | `/mensajes/{conversation}/bloqueo` | `messages.block` / `messages.unblock` |
| POST | `/mensajes/{conversation}/adjuntos` | `messages.upload` |
| DELETE | `/mensajes/{conversation}/adjuntos/{attachment}` | `messages.upload.remove` |
| GET/HEAD | `/mensajes/{conversation}/mensajes/{message}/archivos/{attachment}` | `messages.download` |

Límites por cuenta/minuto: consultas y sondeo 90; creación/recuperación de pares 15;
escrituras 60; cargas 10; descargas 30. Mutaciones con CSRF, incluidas las progresivas
mediante FormData. Se mantienen las sesiones y el middleware de INTEGRACIÓN-01.

## Contratos de envío, lectura y respuestas

Los listados/detalles/edición son HTML Blade. El sondeo devuelve JSON con conversation_id,
can_send e history_html/inbox_html/information_html. Son fragmentos de las mismas
vistas Blade, con DTO escapados; no se duplican plantillas ni reglas en JavaScript.

Escrituras nativas: redirect conocido con status. Con Accept JSON: resultado mínimo
message/redirect más IDs, versión/estado o datos específicos de la acción. Send
responde 200 tanto al crear como al recuperar un envío idempotente; devuelve id,
version, status y next_token propio del compositor. Upload devuelve 201 con metadatos
explícitos y la ruta de retirada del borrador. No se serializan modelos completos,
paths, hashes, contraseñas, correos privados ni tokens de mensajes ajenos.
Todas las respuestas del módulo usan private/no-store.

- Start no acepta participantes en el cuerpo: solo el destinatario de ruta. El
  servicio normaliza el par y recupera el mismo chat en ambos sentidos, sin duplicar.
  Rechaza self-chat, destinatario inexistente y nuevos pares bloqueados/inactivos.
- Send acepta content nullable, attachment_ids como lista ordenada de hasta cuatro
  IDs distintos y client_token UUID obligatorio. Debe existir texto o archivos.
  Se rechazan sender_id, estado, participantes, uploads y cualquier campo adicional.
- Edit acepta solo content y version original. Conserva archivos; solo el remitente
  puede editar mensajes sent accesibles, con ambos activos y sin bloqueos.
- Delete requiere version y permiso de remitente. Hide individual no requiere versión.
- Read acepta message_id de esa conversación y accesible al actor; el servicio
  avanza el marcador sin retroceder ni reescribir la fecha de un marcador repetido.
- Archive recibe archived boolean. Block/unblock deriva el otro usuario del par
  autorizado, sin aceptar un blocked_id o actor elegido por el cliente.
- Bandeja: archived, cursor y filtro exacto opcional participant_id sobre chats propios.
  Historial: history_cursor. La UI no convierte el filtro exacto en buscador global.

Validación JSON 422; HTML vuelve al formulario con texto acotado y listas seguras.
La versión original se conserva tras otro error de validación. Conflicto de edición:
409 sin reintento que reemplace automáticamente la versión. Versión/token mal formado:
422. Filtros/cursores inválidos: 422. Falta de sesión: login/401. Acceso denegado:
404 genérico. Fallos SQL, de dominio, almacenamiento o lock de sesión: 503 genérico,
incluso con debug. Se conserva el reporte SQL estructurado de AUDIT-02.

## Idempotencia y reintentos

El formulario genera un UUID seguro en el servidor para un intento lógico y lo
conserva en su estado de sesión por actor/conversación. No se usa una fecha, contador
ni Math.random. No se regenera al sondear, validar o reintentar un fallo de red.
El formulario HTML conserva su valor y la repetición de la misma petición reenvía
ese token. No se incluye en URLs, previews de mensajes, historial ni logs de transporte.

El envío progresivo congela token, texto e IDs de archivos en su orden original.
Ante resultado ambiguo mantiene ese mismo intento y ofrece reintento, sin permitir
que una edición accidental transforme el payload. Solo una confirmación válida del
servidor permite limpiar el texto y adoptar next_token para otro envío. Una validación
rechazada permite corregir datos, conservando el identificador; un token usado con
datos diferentes sigue siendo rechazado por el dominio.

La restricción única y request_hash de BACK-06 son la garantía definitiva: mismo par,
remitente, token y payload recupera el mismo mensaje, incluso editado o eliminado.
No se añade una segunda tabla de idempotencia ni se cambia ese contrato. Si el mensaje
se confirma en MySQL pero falla la respuesta, reenviar el payload original no duplica.
El formulario no promete persistir borradores privados en localStorage ni recuperarlos
entre computadoras; los mensajes guardados sí persisten en la cuenta.

## Edición, eliminación, ocultamiento y archivo

Las acciones por mensaje tienen formularios reales. Editar usa versión optimista y
solo cambia texto. Eliminar para todos quita texto, deja tombstone y conserva metadatos
según el servicio; no ofrece restauración ni versiones históricas de texto.
Ocultar un mensaje afecta solo al solicitante y recalcula su último mensaje visible.

Archive/desarchive afecta solo la bandeja propia. Ocultar conversación afecta solo
el historial del actor hasta el último mensaje actual; se advierte que esa API no lo
restaura. Un mensaje nuevo puede volver a mostrar el chat, preservando el límite de
historial ocultado y el archivado individual. No se borra evidencia por bloquear.

## Lectura y bloqueos

Abrir bandeja, detalle, preview o actualizaciones HTTP no marca leído. La interfaz
ofrece «Marcar como leído hasta el último mensaje mostrado»: una confirmación explícita
al final de la página de historial. Se advierte que avanza también sobre mensajes
anteriores accesibles, conforme al marcador monotónico de BACK-06. Los indicadores
Leído de mensajes propios y conteos de no leídos proceden de consultas vigentes.
Guardado significa persistido en el servidor; no se inventa un estado delivered.

Bloqueo en cualquier dirección impide nuevos envíos y ediciones. La UI deshabilita
controles al recibir ese estado, y el servidor lo vuelve a comprobar. Ambos conservan
historial y descargas históricas autorizadas; el remitente puede eliminar para todos.
Cada uno controla su bloqueo: quitar uno no elimina el del otro. Una cuenta activa
puede recuperar un chat existente con un interlocutor inactivo, sin habilitar envío.
Una cuenta inactiva no puede operar y su sesión obsoleta se invalida. La UI no expone
la dirección privada del bloqueo ajeno ni preferencias o marcadores crudos del otro.

## Adjuntos, privacidad y fallos parciales

La carga es una fase separada del envío. AttachmentService reserva y escribe fuera
de transacciones exteriores; MessageUploadService comprueba acceso/ausencia de bloqueo
antes y después. Ante fallo parcial o revocación durante la carga solicita eliminación
solo de reservas nuevas completas; los fallos físicos conservan su reserva trazable.
Nunca compensa archivos anteriores ni elimina referencias ajenas.

Los IDs preparados se conservan en la sesión del compositor del actor. El POST de
mensaje usa esos IDs explícitos: un reintento NO vuelve a subir archivos ni cambia
el request_hash por IDs distintos. Si falla el envío, los archivos preparados siguen
reutilizables y se revierten mensaje, pivotes, marcas y actividad. Si se pierde una
confirmación de carga, la UI pide recargar para recuperar lo preparado antes de subir
otra vez; no presenta el upload como idempotente ni lo reintenta automáticamente.

Formatos reales: JPEG/PNG/GIF/WebP (10 MiB), MP4/WebM (100 MiB), PDF/TXT/MP3 (20 MiB),
hasta cuatro archivos. Fileinfo, bytes, extensión, propiedad, estado y retención los
valida BACK-02/BACK-06. La UI anticipa límites a partir de configuración, sin confiar
en MIME del navegador. DOCX, HTML, SVG, ejecutables y comprimidos no se admiten.
PHP actual limita archivo/envío a 40M: configurar PHP/proxy y total multipart antes
de habilitar máximos superiores. No se modificó esa configuración.

Retirar del borrador solo admite IDs preparados de esa sesión, comprueba contexto y
solicita eliminación al servicio. Si una referencia/retención o un fallo conserva el
archivo, se retira de ese compositor sin destruir la referencia y se reporta para
reconciliación. No hay purga física automática. Abandonar la sesión puede dejar
archivos preparados sin enviar: requieren cuotas y mantenimiento explícito futuro.

Editar no desasocia adjuntos de mensajes existentes. Archivar u ocultar no los libera.
Eliminar para todos revoca la descarga por ese mensaje pero conserva los pivotes como
metadatos. Otras referencias o retenciones bloquean eliminación; se conserva el flujo
del propietario y las exclusiones con verificación. No hay acceso directo público.

Descargas con conversación+mensaje+archivo exactos, participante autorizado y mensaje
visible no eliminado. Se verifica propietario, disponibilidad, retención, disco privado,
tamaño y SHA-256. Octet-stream/attachment, private/no-store, nosniff y CSP sandbox.
No storage:link, URLs públicas ni previews inline. Solo nombre escapado, tipo, tamaño
y opción de descarga. Archivos compartidos se limita expresamente a la página visible.

## Actualización HTTP e interfaz

Sondeo cada 10 s, como máximo una consulta en vuelo. Solo en historial reciente,
pestaña visible/con foco y al final de la lectura; se pausa mientras se leen páginas
anteriores, se consulta la parte superior del historial o se administran acciones.
Al recuperar foco/conexión vuelve a consultar; salir cancela solicitudes y temporizador.
Si el navegador conserva el documento en su caché de historial, se retira el contenido
privado y la restauración obliga a cargar de nuevo antes de volver a usar el chat.
El compositor y sus datos pendientes no se reemplazan con los fragmentos recibidos.
Un 401/403/404 retira el contenido privado mostrado y deshabilita escrituras.

Cada snapshot sustituye la página visible en orden ascendente de ID, sin concatenar
copias. Así se reflejan edición, tombstones y ocultamientos; las páginas anteriores
usan cursores descendentes del dominio y enlaces nativos. La bandeja muestra hora
compacta, con fecha completa en title/datetime. Su actividad puede reordenarse cuando
hay nuevos mensajes, límite ya documentado por BACK-06.

Latencia habitual de recepción: hasta unos 10 s más red/servidor. No hay entrega en
tiempo real ni notificaciones push. Sondeo carga las dos páginas acotadas de chat y
bandeja (20 filas cada una), con consultas de cantidad fija. Evaluar volumen antes de
reducir bloqueos o introducir transporte/eventos. No requiere infraestructura externa.

Se conserva distribución de tres paneles, paleta, tipografía, modo oscuro, animaciones
y navegación. En móvil se alternan bandeja/chat y se abre información en el panel
modal existente. Envío, edición, archivo, ocultamiento, bloqueo, carga y lectura
funcionan con formularios HTML; JavaScript aporta envío/carga progresivos y sondeo.

## Integración con perfiles y directorio

Los perfiles públicos personal/profesional ofrecen Enviar mensaje; el directorio usa
el user_id real del DTO, distinto del ID de perfil profesional. No se deduce de nombres
ni fixtures demo. No se ofrece autochat; invitados van a login. La creación posterior
vuelve a comprobar usuario activo y bloqueo. Los enlaces de contacto no publican correos,
licencias ni datos personales privados. Los interlocutores privados se muestran como
Usuario y sin enlace a un perfil no público. No se implementa una agenda global.

## Validación, aislamiento e incidencias resueltas

Antes de fixtures: bootstrap testing, driver mysql, DB configurada y SELECT DATABASE()
exclusivamente plataforma_legal_v2_test. Tests/TestCase detiene cualquier otro entorno.
Las pruebas HTTP de archivos usan Storage::fake y verifican el root efectivo antes
incluso de cada reserva, dentro de la petición. Las pruebas durables limpian solo sus
IDs/objetos; las demás revierten su transacción, sin migraciones destructivas.

Revisión con navegador: router temporal fuera del repo, proceso HTTP PID 33004 con
MySQL testing y attachment_root bajo integration05-uploads en artefactos temporales,
confirmados mediante su propio diagnóstico ANTES de cargar. No se confió en variables
del padre. Se verificaron login sintético, bandeja/chat, carga TXT real, envío,
descarga, lectura explícita, recepción por sondeo sin marcar leído, edición con archivo
conservado, bloqueo/desbloqueo, panel móvil y navegación. Escritorio y 390x844 en modo
oscuro; ancho de documento 390 px. Consola sin errores en la revisión final. Capturas,
router, sesiones y scripts fuera del repositorio; servidor detenido y viewport/tab
restablecidos/cerrados al finalizar.

La limpieza selectiva devolvió los diez conteos de control de fixtures al baseline
cero. Ninguna prueba escribió en desarrollo. Se conservó el PNG privado preexistente
con SHA-256 FE57B91CAE20E3EE77AC211A634FF67D627E7A6B6C6536B59E3A4B73977C51F8;
no hubo archivos de testing nuevos en el almacenamiento privado de desarrollo.

Incidencias corregidas durante la implementación: compatibilidad de exports y
validación de adjuntos usada por LEXA; preservación de las tres claves originales del
DTO de participante; presentación compacta de actividad y navegación activa del chat.
No se relajaron las pruebas previas para ocultar incompatibilidades.

Resultados finales:

- Laravel completo: **531 pruebas, 12,663 assertions**, aprobado (101.35 s).
  Incremento: 41 casos HTTP y 474 assertions frente a 490 / 12,189.
- Frontend: **37 pruebas aprobadas**, incluidas solicitud estable ante confirmación
  perdida, acknowledgements inválidos, pausa/reanudación y no solapamiento de sondeo,
  formatos reales y compatibilidad del consumidor LEXA.
- Build Vite: aprobado, sin dependencias ni cambios de Vite.
- Pint --test: 14 PHP nativos; sintaxis php -l: 27 PHP/Blade del alcance, aprobados.
- git diff --check: aprobado; índice vacío. Suites anteriores conservadas/aprobadas.

La cobertura HTTP incluye par canónico, contexto manipulado/admin ajeno, perfiles,
estados de cuenta, texto/XSS, idempotencia tras edición/eliminación, fallo de respuesta
tras commit, versiones viejas/old input, ocultamiento/archivo individual, lectura
monotónica explícita, bloqueos en ambas direcciones, cursores/orden/consultas acotadas,
archivos propios/ajenos/retenidos, límites/MIME, fallos de escritura y cargas parciales,
revocación durante upload, integridad/descargas y CSRF/rate limiting/errores SQL.
Las regresiones preexistentes de concurrencia de BACK-06 siguen aprobadas.

## Riesgos y pendientes deliberados

- Sin grupos, llamadas, videollamadas, WebSockets, notificaciones push, LEXA ni pagos.
- Sin cifrado de extremo a extremo ni confirmación de entrega al dispositivo.
- Sin auditoría completa/versiones de texto, restauración de eliminados ni recuperación
  del historial ocultado; definir conservación legal, backups y acceso administrativo
  auditado si se requiere, sin otorgar acceso implícito a conversaciones ajenas.
- Cuotas, antimalware/cuarentena, procesamiento seguro, reconciliación, mantenimiento
  de preparados abandonados y ejecución operativa de purgas quedan pendientes.
  available no certifica ausencia de malware. No se reproducen archivos sin procesar.
- Verificar límites PHP/proxy, almacenamiento temporal protegido, cifrado en reposo,
  gestión de llaves, retención de logs y cache store con locks atómicos en despliegue.
- El bloqueo de sesión protege las rutas de este módulo; no es una garantía de
  transacción distribuida entre todas las pantallas o dispositivos. La garantía de
  no duplicar mensajes está en el token/payload y la unicidad del dominio/MySQL.
- SQL directo/DBA puede eludir reglas de dominio. Las FKs no sustituyen autorización,
  marcadores o retención; no se ofrece una frontera frente a privilegios SQL.
- Búsqueda global privada, rango de medios, previews y borradores sincronizados no
  se inventan sobre un backend que no los ofrece. Medir sondeo/bloqueos con carga real.

## Inventario exacto frente a HEAD

Total: **35 archivos: 19 nuevos y 16 modificados**, sin eliminaciones.

### Nuevos (19)

- `app/Http/Controllers/Messaging/AttachmentController.php`
- `app/Http/Controllers/Messaging/ConversationController.php`
- `app/Http/Controllers/Messaging/InboxController.php`
- `app/Http/Controllers/Messaging/MessageController.php`
- `app/Http/Controllers/Messaging/MessagingController.php`
- `app/Http/Requests/Messaging/MessagingRequest.php`
- `app/Services/Messaging/MessageUploadService.php`
- `docs/messaging-integration.md`
- `resources/js/messages/attempt.js`
- `resources/js/messages/polling.js`
- `resources/views/components/messages/start.blade.php`
- `resources/views/pages/messages/edit.blade.php`
- `resources/views/pages/messages/error.blade.php`
- `resources/views/pages/messages/partials/history.blade.php`
- `resources/views/pages/messages/partials/inbox.blade.php`
- `resources/views/pages/messages/partials/information.blade.php`
- `tests/Feature/MessagingIntegrationTest.php`
- `tests/Feature/MessagingMediaIntegrationTest.php`
- `tests/frontend/messages-transport.test.mjs`

### Modificados (16)

- `app/Providers/AppServiceProvider.php`
- `app/Services/Messaging/MessagingQuery.php`
- `app/Services/Profiles/ProfessionalDirectory.php`
- `bootstrap/app.php`
- `resources/css/messages.css`
- `resources/js/messages.js`
- `resources/js/messages/attachments.js`
- `resources/views/components/messages/composer.blade.php`
- `resources/views/components/messages/conversations.blade.php`
- `resources/views/components/messages/information.blade.php`
- `resources/views/components/professionals/card.blade.php`
- `resources/views/layouts/partials/header.blade.php`
- `resources/views/pages/messages/index.blade.php`
- `resources/views/pages/profile/public.blade.php`
- `routes/web.php`
- `tests/frontend/messages-attachments.test.mjs`

El inventario incluye este documento y excluye artefactos ignorados de build y
temporales de revisión externos. No se modificaron migraciones, seeders, .env,
configuraciones de almacenamiento, manifiestos de dependencias ni otros worktrees.
El código queda sin staging, commit ni push para revisión.
