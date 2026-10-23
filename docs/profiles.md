# Perfiles de usuarios y profesionales (BACK-03)

## Identidad y datos

Una cuenta de autenticación en users puede tener un user_profile y un
professional_profile independientes, cada uno con user_id único. Crear un perfil
profesional no crea otra cuenta, no concede roles y no acredita verificación.
Los seeders y permisos actuales permanecen sin cambios.

UserProfile tiene display_name opcional (alias público, no copia del nombre legal),
username opcional y único, biography, website, municipality_id, photo_attachment_id
y visibility. El nombre público usa users.name como fallback cuando display_name
es nulo; el correo de autenticación nunca se publica.

El username se normaliza a minúsculas y se recortan espacios exteriores. Admite
de 3 a 30 caracteres ASCII, empieza por letra y continúa con letras, dígitos o
guion bajo. Vacío se convierte en null; varios perfiles incompletos pueden no tener
username. Los perfiles se crean privados y pueden completarse gradualmente; hacerlos
públicos requiere una decisión explícita, no un efecto de la creación.

ProfessionalProfile tiene headline (título de presentación), description,
license_number, years_experience, municipality_id, attention_mode, contact_email,
contact_phone, website, contact_visible, visibility, verification_status y verified_at.
El contacto profesional es independiente del correo de autenticación y solo se
incluye en la proyección pública con consentimiento explícito contact_visible.
Un perfil público puede estar incompleto: los valores ausentes son null.

La cédula es declarativa: se acepta como cadena de 5 a 12 dígitos por una regla
interna inicial, que conserva ceros iniciales y no afirma validez oficial.
Se almacena cifrada con el cast encrypted de Laravel en una columna TEXT y nunca
se serializa ni se incluye en la proyección pública. No es un campo único ni
consultable por igualdad en SQL. Proteger APP_KEY, sus respaldos y su futura rotación
es imprescindible; no registrar solicitudes que contengan la cédula.
La experiencia admite años enteros entre 0 y 80, sin afirmar que fueron comprobados.
La atención puede ser presencial, remota, ambas o null durante el registro.

## Integridad y ubicación

Ambos perfiles solo guardan municipality_id opcional. El municipio determina su
estado y país mediante las relaciones existentes; no hay IDs redundantes que puedan
contradecirse. Los perfiles pueden estar sin ubicación. Esto admite países nuevos
cuando existan sus catálogos de estados y municipios. Seleccionar únicamente un
país o estado, sin municipio, queda fuera de esta primera fase.

Las FKs de usuarios, municipios y fotografía usan RESTRICT para evitar pérdidas
silenciosas. La futura eliminación de cuentas deberá definir retención o anonimización.
La tabla professional_profile_specialties tiene PK compuesta, FKs y timestamps.
El borrado de un perfil limpia su pivote mediante CASCADE; borrar especialidades
en uso se restringe. No se borran catálogos para desasignar una especialidad.

Solo se pueden asignar especialidades existentes y activas, sin repeticiones,
con un límite inicial de 20. syncSpecialties reemplaza la selección; [] la vacía.
Si una especialidad se desactiva después, se conserva la relación histórica, no se
impide editar otros datos y la proyección pública la excluye.

## Servicio y autorización

Resolver App\Services\Profiles\ProfileService mediante el contenedor:

- saveUserProfile(actor, userId, data): crea o actualiza únicamente campos permitidos.
- saveProfessionalProfile(actor, userId, data): guarda datos y specialty_ids atómicamente.
- syncSpecialties(actor, userId, ids): reemplaza las especialidades mediante la misma transacción.
- userProfile / professionalProfile: consulta privada autorizada para backend.
- publicUserProfile / publicProfessionalProfile: proyecciones públicas explícitas.

El servicio vuelve a leer el estado persistido del actor. Los cambios bloquean las
filas de usuario y perfil para serializar la creación uno a uno. Las operaciones
transaccionales revierten conjuntamente datos, verificación y asociaciones ante fallos.
La política registrada para ambos modelos permite al propietario activo administrar
su perfil, o a un actor activo con el permiso explícito existente users.manage.
Un rol por sí solo no es verificación. Los actores suspendidos, deshabilitados o
pendientes de eliminación no pueden modificar ni consultar datos privados.

Los modelos bloquean la asignación masiva. El servicio rechaza campos desconocidos,
incluidos user_id, roles, permisos, estados de cuenta y campos de verificación.
No se deben usar forceFill, queries directas ni relaciones sync desde consumidores
que procesen datos no confiables: son mecanismos de backend y omiten las reglas
de dominio. Usar siempre este servicio y pasar el actor autenticado del servidor.

Las proyecciones públicas requieren visibility=public y cuenta propietaria activa;
un perfil privado o de una cuenta inactiva se trata como inexistente. No devuelven
el usuario completo, cédula, fotografía, IDs de adjuntos ni rutas de almacenamiento.
Nunca publicar modelos Eloquent completos desde futuros controladores. El frontend
deberá escapar texto, no interpretar biografías como HTML y usar únicamente URLs
http/https validadas. No se agregaron endpoints ni se modificó la autenticación.

## Fotografías y BACK-02

La fotografía de identidad vive en UserProfile y se referencia por attachment_id.
Antes de asignarla, el servicio bloquea el adjunto y comprueba que sea una imagen
disponible, privada y propiedad del usuario del perfil mediante AttachmentPolicy.
Incluso un administrador debe elegir una foto del propietario, no un documento suyo
o ajeno. Reemplazar o quitar una foto no borra el objeto: puede tener otros usos.

La carga de archivos se realiza primero con AttachmentService, fuera de una
transacción externa, como exige BACK-02. Después se asocia el ID al perfil en su
propia transacción. Los adjuntos pueden quedar pendientes de eliminación después
de asociarse; cualquier lectura futura de fotos debe volver a comprobar disponibilidad
y autorización. Una FK no implica que el objeto siga disponible.

BACK-02 solo permite descargas privadas del propietario. Por eso este módulo NO
ofrece fotos públicas, ni cambia políticas, discos o visibilidad de los archivos.
La presentación pública futura necesitará una derivación de imagen aprobada,
reprocesada y servida con una política específica. Los documentos de verificación
no se asocian como fotos ni se publican.

## Verificación y BACK-04

Estados preparados: unverified, pending, verified y rejected. Todo perfil nuevo es
unverified, sin verified_at. MySQL exige que verified tenga fecha y que los otros
estados no la tengan. BACK-03 no ofrece ninguna operación para aprobar o rechazar.

Cambiar título, descripción, cédula, experiencia, ubicación, modalidad o la selección
de especialidades devuelve el perfil a unverified y limpia verified_at. Un cambio
idéntico no invalida la revisión, ni la cambian preferencias de visibilidad o contacto.
En esta fase no se asegura que el contacto esté comprobado. Modificar datos de una
solicitud pending también la invalida; BACK-04 deberá aplicar su propio bloqueo,
versión o instantánea para impedir aprobar datos distintos a los revisados.

BACK-04 debe implementar expedientes y evidencias privadas, historial de decisiones,
actores y motivos de revisión, permisos específicos, comprobaciones documentales,
retención, revalidación y decisiones de moderación. El perfil guarda solo el estado
actual, no sustituye el expediente. Una regex de cédula nunca prueba autenticidad.
No se implementan consultas a autoridades ni se muestran distintivos verificados
por la sola existencia de un perfil, un rol o un identificador con formato válido.

## Validación

Las pruebas de perfiles usan únicamente MySQL plataforma_legal_v2_test, verifican
el nombre real de la conexión antes de escribir y revierten datos con transacciones.
Las pruebas de asociación preparan metadatos de adjuntos dentro de la transacción;
la validación y almacenamiento de contenido real están cubiertos por BACK-02.
No se crean perfiles o usuarios ficticios en desarrollo.
