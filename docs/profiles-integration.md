# INTEGRACIÓN-02 — Perfiles y directorio profesional

Fecha: 2026-11-14. Base: `main`, `c604921` (INTEGRACIÓN-01).

## 1. Arquitectura y alcance

Conecta HTTP → Form Requests → controladores → servicios auditados → MySQL.
Se conservan `ProfileService`, `ProfilePolicy`, `AttachmentService`,
`AttachmentPolicy`, `VerificationService`, enums, catálogos y las 32 migraciones.
No se modifica el esquema, no se agregan dependencias, roles ni permisos.

- `ProfileController`: consulta propia, formularios y creación/actualización opcional.
  Siempre toma el propietario de la sesión; ningún endpoint de escritura acepta un ID ajeno.
- `PublicProfileController`: llama a las proyecciones explícitas de `ProfileService`.
  Una identidad personal privada o ausente no impide una presentación profesional pública.
- `ProfessionalDirectoryController` y `ProfessionalDirectory`: consulta paginada y DTO
  de tarjetas. La consulta adicional es de lectura y no duplica reglas de escritura.
- `ProfilePhotoController`: coordina carga, asociación, compensación y descarga privada.
- Form Requests específicos validan tipos, tamaños y campos permitidos. El servicio
  mantiene la validación final, unicidad, autorización, bloqueo y transacciones.

Los perfiles siguen siendo opcionales e independientes. Consultarlos o abrir un
formulario no crea filas. La fotografía cargada puede crear un perfil personal
mínimo privado, a través del servicio. No se copian automáticamente nombre ni correo
de la cuenta: `display_name` es una elección pública opcional.

## 2. Rutas

| Método | Ruta | Nombre | Acceso |
| --- | --- | --- | --- |
| GET | `/perfil` | `profile` | Cuenta activa autenticada |
| GET | `/perfil/editar` | `profile.edit` | Cuenta activa autenticada |
| PUT | `/perfil` | `profile.update` | Propietario de sesión |
| GET | `/perfil/profesional/editar` | `profile.professional.edit` | Cuenta activa autenticada |
| PUT | `/perfil/profesional` | `profile.professional.update` | Propietario de sesión |
| GET | `/perfil/fotografia` | `profile.photo` | Propietario del perfil y del archivo disponible |
| POST | `/perfil/fotografia` | `profile.photo.store` | Carga privada propia |
| DELETE | `/perfil/fotografia` | `profile.photo.destroy` | Desasociación propia |
| GET | `/usuarios/{userId}` | `profiles.show` | Perfil personal público y cuenta activa |
| GET | `/profesionales` | `professionals` | Directorio público |
| GET | `/profesionales/{userId}` | `professionals.show` | Perfil profesional público y cuenta activa |

Se reemplazan las vistas de demostración de `/perfil` y `/profesionales`.
Los IDs de las rutas son identificadores públicos de navegación, nunca permisos.
IDs ausentes, privados, fuera del rango entero o de cuentas inactivas producen 404.
No hay ruta para editar otra cuenta, incluso si el actor tiene `users.manage`.

## 3. Campos editables y presentación propia

Personal: `display_name`, `username`, `biography`, `municipality_id`, `website`,
`visibility`. El alias se normaliza en minúsculas y el servicio comprueba unicidad.
El nombre de cuenta es el fallback visible; el formulario explica su publicación
si se hace público el perfil sin un nombre alternativo.

Profesional: `headline`, `description`, `years_experience`, `attention_mode`,
`municipality_id`, `website`, `visibility`, `contact_email`, `contact_phone`,
`contact_visible` y reemplazo opcional de `license_number`.

La cédula no se envía a Blade, JSON, atributos ni old input. Solo se muestra si
existe una registrada. El campo de reemplazo está vacío y enmascarado; vacío
conserva la credencial actual. La eliminación de una cédula no está expuesta por HTTP.
La protección `dontFlash` también cubre excepciones de validación del servicio.

Las especialidades activas se seleccionan con `specialty_ids[]`. El control
`update_specialties` requiere intención explícita antes de reemplazar el conjunto;
sin él se conserva la relación histórica, incluidas especialidades desactivadas.
Con él, una selección vacía elimina todas las asociaciones y cualquier ID inválido,
inactivo o repetido es rechazado por el servicio. Este control no es una columna.

El catálogo de ubicación se carga por estado mediante GET antes de editar. Solo se
guarda municipio; estado y país se derivan de sus relaciones. Cambiar el catálogo
recarga el formulario y descarta cambios sin guardar, con aviso visible. La ubicación
actual permanece seleccionable aunque se consulte otro catálogo. Se evita cargar
los miles de municipios de todos los estados en cada página.

## 4. Datos públicos y privacidad

- Personal: nombre público (o nombre de cuenta con perfil personal público), alias,
  biografía, sitio web y ubicación normalizada.
- Profesional: presentación, descripción, experiencia, modalidad, sitio web,
  ubicación, especialidades activas y estado real de verificación.
- Contacto profesional: solo aparece en el detalle cuando `contact_visible=true`;
  nunca se toma automáticamente del correo de autenticación. El contacto elegido
  puede coincidir con otro correo por decisión del propietario.
- Directorio: nombre público permitido o «Profesional», inicial, presentación breve,
  especialidades activas, localidad, modalidad, estado y enlace al detalle.

No se serializan modelos completos ni se entrega un catálogo JSON oculto con todos
los perfiles. La consulta del directorio ni siquiera selecciona cédula o contactos.
Un nombre/alias personal privado tampoco participa en búsquedas. Un perfil profesional
público sin perfil personal sigue apareciendo como «Profesional» y puede encontrarse
por su información profesional pública.

Cada perfil comprueba su propia visibilidad y la cuenta activa; no hay bypass público
por rol. La consulta usa relaciones precargadas y transacciones de lectura con bloqueos
compartidos en identidad/perfiles para coordinar cambios por los servicios.
Las respuestas públicas llevan `Cache-Control: private, no-store`; Turbo tampoco
conserva instantáneas de estas páginas (layout existente). El texto se escapa con Blade
incluyendo biografías; los sitios web admiten HTTP/HTTPS y enlaces externos protegidos.

## 5. Profesionales y verificación

Crear una presentación profesional no otorga roles, acceso especial ni acreditación.
Puede ser pública estando sin verificar. Solo el estado `verified` muestra la insignia
de verificación; los demás estados tienen texto explícito sin ese icono.

Las escrituras pasan por `saveProfessionalProfile`: cambios materiales de presentación,
experiencia, cédula, municipio, modalidad o especialidades invalidan la verificación
atómicamente mediante el servicio existente. Contacto, visibilidad y sitio web no la
invalidan por sí solos. Se conserva el aviso en el formulario y la respuesta de guardado.
No se agregan solicitudes, carga de evidencias, aprobación o rechazo por HTTP.

## 6. Directorio, filtros y eficiencia

Incluye cualquier perfil profesional público de cuenta activa; no exige rol profesional,
perfil personal ni verificación, conforme a BACK-03.

GET admite `q` (máximo 160 caracteres), `specialty_id` activo, `state_id`,
`municipality_id` y `page` entre 1 y 10000. Los filtros se combinan. Un municipio
específico sin estado en la URL permite derivar el estado para reflejarlo en los controles.
Si se envían ambos deben ser consistentes. La lista de municipios se carga tras aplicar
el estado. Los formularios funcionan sin JavaScript; para cambiar estado sin JS debe
quitarse un municipio anterior antes de aplicar el nuevo estado. JavaScript lo limpia
automáticamente y respeta el ciclo de navegación de Turbo.

La búsqueda coincide con nombre/alias públicos, presentación breve, especialidades
activas, municipio o estado. Se parametrizan todos los valores y se escapan `%`, `_`
y `!` como caracteres literales. No se buscan cédulas, correos ni información privada.
El comportamiento de mayúsculas/acentos corresponde a la collation MySQL existente.
No hay ranking, opiniones, idiomas ni popularidad inventados.

Paginación de 12 perfiles, orden estable por PK ascendente y filtros conservados en
los enlaces. Las relaciones se cargan por lote; una prueba con 13 perfiles verifica
la distribución 12/1, orden y un máximo de 15 consultas de la respuesta completa.
Una página válida fuera del total muestra el estado vacío. Filtros inválidos vuelven
a la URL limpia con aviso; JSON recibe 422. Un fallo SQL muestra 503 con reintento.

## 7. Fotografías, consistencia y errores

Solo imágenes JPG/PNG/GIF/WebP de hasta 10 MiB. Form Request limita el upload y
`AttachmentService` verifica MIME real con Fileinfo, extensión, bytes, propiedad,
disco privado y estado. No se acepta `photo_attachment_id` del cliente.

La carga confirma su reserva fuera de una transacción ambiental. Después el servicio
de perfiles asocia el ID recién generado en su propia transacción. Si falla la
asociación se solicita eliminación lógica únicamente de esa carga; el perfil/foto
anterior permanecen intactos. No hay borrado físico improvisado en el controlador.
Si también falla la compensación, se reporta y queda la reserva durable para la
conciliación operativa del almacenamiento; no se expone ni se considera éxito.

Descargar vuelve a autorizar la asociación actual y el archivo disponible; no acepta
IDs o rutas de archivos arbitrarios. Responde como descarga, `application/octet-stream`,
`nosniff`, CSP sandbox y sin caché. Quitar o reemplazar solo desasocia, sin eliminar
objetos que puedan estar retenidos o tener otros usos; quitar repetidamente es seguro.
Los uploads repetidos crean objetos independientes, sin deduplicación automática.

Se conserva la restricción BACK-02/BACK-03: no existen imágenes públicas, URLs de
almacenamiento ni preview inline. Los perfiles muestran una inicial. La fotografía
propia se puede cargar y descargar. Publicarla exige un flujo futuro de derivados
reprocesados y autorizados; no se debilita `AttachmentPolicy` ni se crea un symlink.

El middleware existente revisa el estado persistido de la sesión. Todas las mutaciones
usan CSRF y rechazan campos internos/desconocidos. Límites: lectura 90/min/IP,
escrituras 20/min/cuenta (compartido entre formularios y desasociación), cargas 5/min/cuenta.
Los errores esperados se muestran sin SQL; el render de fallos SQL se limita a estas
rutas y también cubre validación y middleware, incluso con debug habilitado. El reportero
auditado existente conserva únicamente la información estructural de fallos de base.

## 8. Interfaz y compatibilidad

Se reutilizan layout, navegación, DM Sans, paleta, banner, superficies, tarjetas,
componentes Lucide, responsive y modo oscuro por preferencia del sistema.
El perfil propio sustituye estadísticas, publicaciones y recomendaciones ficticias
por información real y acciones de edición. El directorio conserva búsqueda,
especialidades y tarjetas, con filtros geográficos y paginación del servidor.
El detalle público reemplaza el diálogo de demostración por una página enlazable.

No se conectan mensajería, LEXA, pagos ni operaciones de comunidad; tampoco se editan
sus mocks. Los datos/helper/componentes de demostración no usados por estas rutas
se conservan para no alterar otras fases. Los antiguos hashes ficticios desde Home
llevan al directorio sin abrir un perfil simulado. La navegación principal no cambia.

BACK-01/02/03/04 se reutilizan sin migraciones ni nuevas concesiones. BACK-05/06/07
permanecen intactos; la suite de regresión incluye sus pruebas de concurrencia e
integridad. INTEGRACIÓN-01 conserva registro, login, sesión, logout, CSRF y revisión
de cuentas inactivas. No se modificaron archivos de dependencias ni configuración Vite.

## 9. Validación y límites pendientes

Antes de fixtures se comprobó `APP_ENV=testing`, driver `mysql`, nombre configurado y
`SELECT DATABASE()` = `plataforma_legal_v2_test`. `Tests\TestCase` impide escribir
fixtures si difieren. No se ejecutaron migraciones ni seeders de desarrollo.
Las pruebas ordinarias revierten transacciones; las cargas necesitan reservas
confirmadas y limpian exclusivamente las filas creadas por su propio caso.

- Laravel: **396 pruebas aprobadas, 11,096 assertions**, suite completa (`php artisan test --compact`), 73.31 s. Referencia anterior: 348 pruebas y 10,572 assertions.
- Nuevas pruebas HTTP: 48 casos y 524 assertions, incluyendo datasets; validan opcionalidad, visitas,
  creación/upsert, validación, campos prohibidos, perfiles ajenos/inactivos, visibilidad
  independiente, contacto consentido, XSS, cédula cifrada/no expuesta/no reflasheada,
  especialidades históricas/activas, ubicación, búsqueda literal, paginación/N+1,
  invalidación material, CSRF, límites, upload/MIME/tamaño, autorización/retención,
  compensación, reversión si falla la invalidación y respuestas sanitizadas.
- Frontend: 25 pruebas aprobadas (`node --test` sobre `tests/frontend/*.test.mjs`).
  Las pruebas heredadas de selección demo permanecen, aunque el directorio real ya
  resuelve filtros en MySQL; el navegador comprobó el flujo conectado.
- `npm run build`: aprobado, incluyendo reconstrucción tras ajustar estilos oscuros.
- Pint sobre PHP creado/modificado y `git diff --check`: aprobados.
- Navegador local aislado: directorio con datos de testing, filtro de estado y
  especialidad, detalle público, login temporal, guardado personal y profesional;
  móvil de 390×844 sin desbordamiento, revisión en escritorio y modo oscuro,
  sin errores/warnings de consola. Los datos sintéticos se limpiaron y se detuvo
  únicamente el servidor temporal; se conservó el servidor de desarrollo existente.

Pendientes deliberados: publicación/preview de fotos con derivados seguros, alta y
revisión HTTP de evidencias profesionales, borrado/retención de cédula y cuenta,
conciliación y purga operativa de adjuntos huérfanos, integración de publicaciones,
seguimientos y contacto/mensajería. El SQL directo y accesos al disco fuera de los
servicios siguen omitiendo invariantes, como advierten las auditorías previas.
No se añade una auditoría durable de todas las lecturas/ediciones: depende del futuro
flujo transversal de auditoría. A mayor volumen, medir LIKE/COUNT y considerar índices
compuestos/búsqueda dedicada; no se añaden ahora cachés que puedan servir privacidad
obsoleta ni un motor de búsqueda paralelo.

## 10. Inventario exacto y Git

26 archivos: 18 nuevos y 8 modificados.

### Nuevos

- `app/Http/Controllers/Profiles/ProfessionalDirectoryController.php`
- `app/Http/Controllers/Profiles/ProfileController.php`
- `app/Http/Controllers/Profiles/ProfilePhotoController.php`
- `app/Http/Controllers/Profiles/PublicProfileController.php`
- `app/Http/Requests/Profiles/DirectoryRequest.php`
- `app/Http/Requests/Profiles/ProfileFormRequest.php`
- `app/Http/Requests/Profiles/ProfilePhotoRequest.php`
- `app/Http/Requests/Profiles/SaveProfessionalProfileRequest.php`
- `app/Http/Requests/Profiles/SaveUserProfileRequest.php`
- `app/Services/Profiles/ProfessionalDirectory.php`
- `docs/profiles-integration.md`
- `resources/views/components/profiles/card.blade.php`
- `resources/views/components/profiles/field.blade.php`
- `resources/views/components/profiles/verification.blade.php`
- `resources/views/pages/profile/edit.blade.php`
- `resources/views/pages/profile/public.blade.php`
- `tests/Feature/ProfileIntegrationTest.php`
- `tests/Feature/ProfilePhotoIntegrationTest.php`

### Modificados

- `app/Providers/AppServiceProvider.php`
- `bootstrap/app.php`
- `resources/css/professionals.css`
- `resources/js/professionals.js`
- `resources/views/components/professionals/card.blade.php`
- `resources/views/pages/professionals/index.blade.php`
- `resources/views/pages/profile/show.blade.php`
- `routes/web.php`

No se realizó staging, commit, push, merge ni cambio de rama. HEAD sigue en
`c604921`; los cambios quedan disponibles para revisión. No se tocaron los worktrees
externos de otros módulos. No se modificaron datos funcionales de desarrollo;
los únicos datos de prueba creados se usaron en la base de testing y se limpiaron.
