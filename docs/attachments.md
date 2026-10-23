# Archivos y adjuntos (BACK-02)

## API interna

Resolver `App\Services\Attachments\AttachmentService` con el contenedor de Laravel.
Los métodos reciben el usuario autenticado del servidor, nunca un ID de usuario
proporcionado por el cliente:

- `store(User, UploadedFile): Attachment`: valida, reserva metadatos, escribe por stream y publica el registro.
- `metadata(User, int): array`: entrega metadatos autorizados sin disco, ruta, nombre interno ni hash.
- `download(User, int): StreamedResponse`: descarga autorizada como attachment, sin URL pública y sin caché.
- `requestDeletion(User, int): void`: bloquea inmediatamente nuevas consultas y descargas.
- `purge(User, int): void`: elimina físicamente un archivo pendiente y conserva su registro.

No se añadieron rutas HTTP. Los futuros controladores deben usar autenticación,
limitación de solicitudes, protección CSRF cuando corresponda y este servicio.
Deben mapear AuthorizationException a una respuesta genérica 403, ValidationException
a 422 y AttachmentException a un error genérico 503. Nunca devolver el modelo completo
con relaciones cargadas ni detalles de excepciones, y usar APP_DEBUG=false en producción.
Escapar siempre el nombre original al presentarlo en HTML.

## Almacenamiento y autorización

La configuración `attachments.disk` elige el disco desde el servidor. El disco
predeterminado `attachments` usa `storage/app/attachments`, fuera del directorio
público y del enlace `public/storage`; no habilita rutas para servir archivos.
Todos los objetos se escriben con visibilidad privada. La fase actual solo admite
visibilidad `private`; el campo permite evolucionar la política sin cambiar rutas.
La política se descubre mediante la convención nativa Attachment/AttachmentPolicy.

Solo el propietario con cuenta activa puede cargar, consultar, descargar o eliminar.
El servicio vuelve a consultar el estado persistido para rechazar usuarios obsoletos,
suspendidos, deshabilitados o pendientes de eliminación. Ningún rol, incluido admin,
otorga acceso automático a archivos privados. No se modifican los permisos existentes.

Un UUID genera el nombre físico; el nombre original se conserva solo como metadato
acotado y sin separadores ni controles. Se comprueba MIME mediante Fileinfo, extensión
compatible, tamaño real no vacío y límite configurable por categoría. El SHA-256
permite comprobar identidad del contenido, sin deduplicación global que revele
la existencia de archivos ajenos. Los índices únicos protegen nombre generado y
combinación disco/ruta. La FK del propietario restringe su borrado físico.

La lista inicial admite JPEG, PNG, GIF, WebP, MP4, WebM, PDF, TXT y MP3. HTML, SVG,
ejecutables, archivos comprimidos y documentos Office no están admitidos en esta fase.
Los límites predeterminados son 10 MiB para imágenes, 100 MiB para videos y 20 MiB
para documentos y otros tipos. PHP y el servidor web también deben permitir esos
tamaños (`upload_max_filesize`, `post_max_size` y límites del proxy).

## Ciclo de vida y fallos

`pending_upload -> available -> pending_deletion -> deleted`.

Antes de tocar el disco se persiste una reserva con la clave de almacenamiento.
La escritura y transición a disponible usan un bloqueo de fila. Un fallo intenta
borrar cualquier objeto parcial y marca el registro eliminado. Si falla el borrado,
queda pendiente; si también falla la base, la reserva original sigue rastreable.
Los errores internos se reportan al sistema de logs, nunca al mensaje del consumidor.

`store` rechaza transacciones externas: una transacción del consumidor podría
revertir la reserva después de haber escrito el objeto y generar un huérfano.
Los módulos consumidores deben completar la carga antes de su propia transacción
para asociar el adjunto; si la asociación falla deben solicitar su eliminación.
Las pruebas de carga usan filas exclusivas del usuario de cada prueba y limpieza
selectiva en la base de pruebas, sin migraciones destructivas.

La eliminación lógica conserva el objeto hasta `purge`. Un fallo de borrado
mantiene `pending_deletion`; el reintento también funciona si el objeto ya no existe.
Si el objeto se borra y falla la actualización de MySQL, el estado sigue pendiente
y el siguiente intento puede completar el registro. No se elimina el historial.
Una descarga que ya abrió su stream puede finalizar aunque después se solicite
eliminación; se bloquean las solicitudes nuevas.

No hay proceso automático de limpieza en esta fase. Operación deberá revisar reservas
`pending_upload` antiguas (p. ej., tras un proceso interrumpido), solicitar su
eliminación con el propietario activo y ejecutar `purge`. Antes de habilitar cargas
en producción deben definirse un mantenimiento con permisos de sistema auditados,
retención y atención a cuentas inactivas. No se debe habilitar un bypass administrativo
implícito para resolver estas tareas.

## Integraciones y producción

Comunidad, comentarios, Mensajes, artículos, Profesionales y LEXA podrán asociar IDs
de adjuntos mediante tablas de asociación futuras y validar propiedad, disponibilidad
y contexto. No se agregan FKs hacia módulos inexistentes ni un polimorfismo sin consumidores.

Cambiar a S3 requiere instalar el adaptador Flysystem correspondiente, configurar un
disco dedicado con `visibility=private`, sin `serve` ni `url`, y seleccionar su nombre
con ATTACHMENTS_DISK. El bucket debe bloquear acceso público y usar credenciales con
permisos mínimos. La lógica de archivos usa Storage y streams, sin rutas locales
para leer objetos almacenados. No se configuraron nube ni credenciales.

MIME y extensión NO garantizan que el contenido sea seguro. Antes de un despliegue
comercial se requiere cuarentena y análisis antimalware, validación estructural de
documentos, límites de dimensiones/duración y, si procede, reprocesamiento de imágenes
y videos. Los archivos PDF pueden contener contenido activo. `available` significa
que el objeto fue almacenado y cumple la validación básica, no que pasó antimalware.
No publicar las rutas internas ni servir contenido sin los controles anteriores.

También quedan por definir cuotas por usuario, política de retención y borrado legal,
auditoría de accesos, cifrado y gestión de llaves, y reconciliación periódica entre
metadatos y almacenamiento. Los logs y metadatos pueden contener información sensible
y necesitan controles de acceso y retención.
