# INTEGRACIÓN-08 — Operaciones esenciales

Base inspeccionada: `main`, `3f154023f43d84d366ff1b3b08cb2261fd69eb1f`, árbol limpio.

## Capacidades existentes y trabajo necesario

| Área | Existe | Integración necesaria |
| --- | --- | --- |
| Recuperación | PasswordBroker nativo, password_reset_tokens con hash y caducidad de 60 minutos, throttle de 60 segundos | Formularios, correo seguro, respuestas uniformes y consumo transaccional |
| Sesiones | SessionGuard guarda password_hash_web al hacer login; remember token; EnsureActiveAccount recarga estado | Activar AuthenticateSession para invalidar sesiones cuando cambia la contraseña |
| Estados | UserStatus y User::save validan transiciones bajo bloqueo; pending_deletion terminal | Servicio autorizado con motivo, versión e historial; no exponer borrado |
| Permisos | Spatie; editorial y verification.* separados; seeders respetan revocaciones | Capacidades operativas explícitas, delegación por lista cerrada, sin autoescalamiento |
| Moderación | PostService y CommunityInteractionService, policies y versiones; bandeja de CommunityQuery | Acceso operativo y auditoría de las operaciones HTTP existentes |
| Editorial/verificación | Workspaces editoriales y bandeja profesional funcionales | Accesos según sus permisos actuales, sin concederlos al operador |
| Auditoría | verification_events depende obligatoriamente de un expediente; revisiones editoriales dependen de su contenido | Ninguna tabla puede registrar cambios de cuenta, permisos o recuperación sin falsear su recurso |

Se revisaron los contratos de BACK-01–07 y de las integraciones/auditorías previas.
El rol admin conserva únicamente sus concesiones reales: no implica capacidades
operativas nuevas ni revisión profesional.

## Justificación del único cambio de esquema previsto

Se necesita una tabla aditiva `operations_events`: actor, destinatario, acción,
recurso, permiso cuando corresponda, resultado mínimo, motivo, origen y fecha.
Las tablas existentes de auditoría no admiten estos recursos y tokens/sesiones son
efímeros. No se modifican migraciones históricas ni datos funcionales. La migración
se aplicó exclusivamente en testing después de verificar aislamiento; desarrollo
queda pendiente. La reversión sólo puede retirar una tabla nueva vacía y debe
rechazar destruir auditoría persistida.

## Decisiones de seguridad previas a implementar

- Usar el broker y notificación/correo de Laravel, sin otro repositorio de tokens.
  En local/testing capturar correo con array; nunca enviar enlaces al mailer log.
  La solicitud pública no confirmará la existencia de una cuenta ni una entrega.
- Los enlaces llevarán token/correo en fragmento, no en ruta/query de acceso del
  servidor. JavaScript progresivo rellena el formulario y retira el fragmento; sin
  JavaScript se permite copiar el código del correo. No reflashear tokens/contraseñas.
- Capacidades nuevas de operación explícitas, sin asignación automática a roles.
  HTTP sólo administrará permisos editoriales, profesionales y de moderación de una
  lista fija; nunca capacidades operativas ni roles. Prohibir cambios sobre sí mismo
  y separar al operador administrativo del revisor profesional.
- Bootstrap confiable por consola, sin endpoint público, con selección explícita,
  simulación por defecto y auditoría; no ejecutarlo en desarrollo durante esta tarea.
- Reutilizar transiciones de User y servicios de moderación, con escritura y evento
  en la misma transacción. No crear otro motor de moderación ni acceso a Mensajería.

## Arquitectura implementada

- `PasswordRecovery` coordina el broker nativo, correo y consumo transaccional.
  `PasswordRecoveryRequest` reutiliza normalización, política de 12 caracteres y
  límite de 72 bytes de `AuthFormRequest`. No hay identidad ni tokens paralelos.
- `EnsureActiveAccount` mantiene la recarga de cuenta y delega la comprobación de
  credenciales a `Illuminate\Session\Middleware\AuthenticateSession`. Los logins
  normales de `SessionGuard` ya guardaban `password_hash_web`; no se duplican.
- `OperationsAccess` define cinco capacidades y tres catálogos cerrados. Para las
  decisiones administrativas consulta concesiones directas y heredadas desde SQL
  con lecturas actuales; no confía en relaciones cargadas ni en caché de roles.
- `AccountOperations` aplica las invariantes del modelo `User`, bloquea las cuentas
  por ID en orden, comprueba versión y autorización y escribe auditoría en la misma
  transacción. No cambia roles, modelos de perfil ni dominios privados.
- `ModerationOperations` envuelve las operaciones ya existentes de BACK-05 y añade
  motivo y evento. Las policies, bloqueos y versiones de Comunidad siguen siendo
  obligatorias. No es un segundo motor de moderación.
- `OperationsController` entrega DTO mínimos a Blade; no incluye contraseñas,
  tokens, cédulas, evidencias, conversaciones ni exportaciones.
- Las pantallas utilizan `layouts.app`, formularios `profile-field`, paneles y
  paleta existentes. No se agregaron CSS, paquetes, dependencias ni configuración
  frontend. El formulario de recuperación usa el layout público existente.

## Rutas HTTP

Se añaden **11 rutas**, de 98 a **109**, sin duplicados de método/dominio/URI.
Todas pasan por el grupo `web`, CSRF, sesiones y comprobación de cuenta vigente.

| Método | URI | Nombre | Autorización adicional |
| --- | --- | --- | --- |
| GET/HEAD | `/recuperar-contrasena` | `password.request` | Pública; límite de lectura |
| POST | `/recuperar-contrasena` | `password.email` | Pública; CSRF y límite de solicitud |
| GET/HEAD | `/restablecer-contrasena` | `password.reset` | Pública; límite de lectura |
| POST | `/restablecer-contrasena` | `password.update` | Pública; CSRF y límite de solicitud |
| GET/HEAD | `/operaciones` | `operations.index` | Cuenta activa y entrada operativa explícita |
| GET/HEAD | `/operaciones/usuarios` | `operations.users` | Cuentas o administración de algún catálogo |
| GET/HEAD | `/operaciones/usuarios/{account}` | `operations.user` | Igual, con DTO y catálogo acotados |
| POST | `/operaciones/usuarios/{account}/estado` | `operations.state` | `operations.accounts` |
| POST | `/operaciones/usuarios/{account}/permisos` | `operations.permission` | Capacidad del catálogo solicitado |
| GET/HEAD | `/operaciones/auditoria` | `operations.audit` | `operations.audit` |
| GET/HEAD | `/operaciones/moderacion` | `operations.moderation` | `posts.moderate` o `comments.moderate` |

Las cuatro rutas de recuperación también admiten usuarios con sesión, para que
Configuración pueda enlazar el flujo real sin perder el fragmento en una redirección
de middleware `guest`. Nunca autentican automáticamente después del cambio.

Se conservan las dos rutas POST de moderación de Comunidad. Su contrato ahora
requiere `reason` (10–2,000 caracteres) además de `version` y `action=hide|show`.
Los formularios y pruebas existentes se actualizaron sin relajar sus expectativas.

Límites: recuperación GET 60/minuto/IP; POST 15/minuto/IP y 5/minuto por correo
normalizado+IP (claves hasheadas). El broker limita además generación a 60 segundos
por cuenta. Operaciones: lecturas 90/minuto/actor, escrituras 20/minuto/actor.
Moderación conserva los límites de Comunidad. No hay bootstrap HTTP.

## Recuperación de contraseña y sesiones

1. La solicitud válida encola el mismo tipo de job para cualquier correo. La
   respuesta no consulta públicamente si existe la cuenta ni confirma entrega.
2. `SendPasswordRecoveryLink` usa `ShouldBeEncrypted`: el correo queda cifrado en
   la carga de cola. El job no contiene token ni contraseña y tiene un intento.
3. El worker llama al broker únicamente para `status=active`. Laravel genera un
   token aleatorio, almacena su hash y aplica caducidad de 60 minutos. La dirección
   procede de `config('app.url')`, nunca del encabezado Host de la petición.
4. El correo usa la notificación de Laravel con enlace a la ruta limpia y valores
   en fragmento `#token=…&email=…`. El fragmento no se envía en la petición HTTP de
   lectura. El módulo del navegador valida estructura/duplicados, rellena valores
   mediante propiedades DOM y lo retira con `history.replaceState`; no usa storage,
   HTML sin escapar ni logs. Sin JavaScript se puede copiar el código del correo.
5. La contraseña y el token llegan exclusivamente en el cuerpo POST necesario para
   verificar el cambio. No se reflejan en JSON, HTML, old input ni errores.
   Las páginas declaran `Cache-Control: private, no-store` y `Referrer-Policy:
   no-referrer`. El token nunca se precarga desde query string en el servidor.
6. El consumo bloquea cuenta y fila de token, vuelve a exigir cuenta activa y usa
   el broker nativo. Contraseña hasheada explícitamente, rotación de remember token,
   eliminación del token y auditoría comparten transacción. Un fallo de auditoría
   deshace el cambio y permite reintentar el token. Sólo un consumo puede ganar.
7. Tokens incorrectos, vencidos, consumidos y cuentas inactivas reciben el mismo
   rechazo. El cliente cierra su sesión, regenera CSRF y vuelve a iniciar sesión.

`AuthenticateSession` rechaza sesiones cuyo sello corresponde a la contraseña
anterior en el siguiente acceso. La rotación también invalida cookies remember.
No se borran físicamente todas las filas de `sessions` ni se crea un administrador
de dispositivos: una fila anterior puede permanecer hasta expirar, pero su sesión
normal queda rechazada al utilizarla. Sesiones externas o históricas creadas sin el
sello nativo sólo pueden compararse después de su inicialización; no se afirma
revocación retroactiva de formatos ajenos a `SessionGuard`.

### Correo y cola: configuración pendiente

- Testing fuerza mailer `array`, incluso si otra configuración apunta a SMTP. Las
  pruebas inspeccionan el correo real generado por Laravel, sin enviarlo fuera.
- En local, transportes no aptos (`log`, `array`, failover u otros) se sustituyen por
  `array`. La pantalla avisa que no hay entrega externa. El correo en memoria es una
  herramienta de pruebas y no un buzón consultable por una persona tras terminar el
  proceso. Con cola `database`, la captura ocurre cuando el worker procesa el job.
- Producción exige un transporte explícito SMTP/API/sendmail admitido y una cola
  asíncrona `database`, `redis`, `sqs` o `beanstalkd`. Con transporte inseguro o cola
  síncrona devuelve un 503 genérico antes de consultar cuentas.
- Para entrega real hacen falta proveedor/configuración de correo, remitente,
  `APP_URL` público HTTPS correcto y worker supervisado. No se modificaron `.env`,
  credenciales ni proveedores. La aplicación y workers deben compartir `APP_KEY`
  estable para descifrar jobs; ninguna clave está documentada aquí.
- Operación posterior, una vez configurado el entorno: un worker Laravel habitual
  (`php artisan queue:work --tries=1`) procesa la cola. No se ejecutó un worker de
  desarrollo durante esta integración. El job de recuperación no reintenta envíos
  automáticamente; la persona puede solicitar otro enlace tras el throttle.
- El mailer `log` nunca recibe enlaces de recuperación. Fallos SMTP se reportan sólo
  por clase de excepción, sin mensaje, traza, cuerpo ni URL. SQL conserva el
  sanitizador global. La pantalla no promete un envío real exitoso por haber encolado. Los errores HTTP
  inesperados también se sanitizan con debug activo; 419/429 conservan código y
  cabeceras sin reflejar entradas. Las pruebas inspeccionan respuestas y logs.
- Debe excluirse el cuerpo de estos POST de cualquier APM/proxy/debug externo que se
  habilite después. SMTP/cola reales y entrega de extremo a extremo siguen pendientes.

## Administración de cuentas

Listado de 20 cuentas por página, búsqueda por nombre/correo limitada a 100
caracteres (comodines tratados literalmente), filtro de estado y paginación acotada.
El detalle sólo muestra ID, nombre, correo, estado, versión y el catálogo que el
actor puede administrar. No expone datos profesionales ni documentos.

Las transiciones siguen `UserStatus::canTransitionTo` y `User::save`: activa permite
suspender/desactivar, suspendida permite reactivar/desactivar y desactivada permite
reactivar. `pending_deletion` es terminal y no se ofrece su modificación ni creación
desde este panel. No se permite cambiar el estado propio ni el de otra cuenta con
capacidades operativas: esos casos requieren mantenimiento confiable independiente.

El cambio requiere motivo, rota remember token y genera `account_status_changed`.
`EnsureActiveAccount` hace efectiva la suspensión en el siguiente acceso. El control
de cuenta no concede ningún permiso de lectura sobre Mensajería ni evidencias.

La versión es un HMAC de ID/estado/fecha/concesiones actuales y último evento auditado
del destinatario. Esto evita reutilizar una versión antigua tras un ciclo de cambios
que vuelve al mismo estado dentro del mismo segundo. Una operación distinta
con versión obsoleta falla con 409. Repetir el mismo estado ya vigente o concesión
ya efectiva es una respuesta idempotente sin segunda escritura/evento; primero se
revalidan autorización, separación de funciones y demás invariantes.

## Permisos administrativos y separación de funciones

| Capacidad explícita | Alcance |
| --- | --- |
| `operations.accounts` | Consulta y transiciones limitadas de cuentas |
| `operations.permissions.editorial` | Ocho permisos existentes de artículos/recursos |
| `operations.permissions.verification` | Cinco permisos existentes `verification.*` |
| `operations.permissions.moderation` | `posts.moderate` y `comments.moderate` |
| `operations.audit` | Historial operativo mínimo |

Catálogo editorial: `articles.create`, `articles.update.own`, `articles.publish.own`,
`articles.manage`, `resources.create`, `resources.update.own`, `resources.publish.own`,
`resources.manage`. Catálogo profesional: `verification.review`,
`verification.evidence.read`, `verification.decide`, `verification.revoke`,
`verification.audit`.

HTTP no crea definiciones, no acepta nombres arbitrarios, no concede capacidades
`operations.*`, no toca roles y no llama a `syncPermissions`. Cada cambio directo
tiene motivo, versión y evento `permission_granted`/`permission_revoked`. No se
conceden permisos a cuentas inactivas. Una revocación de permiso heredado se rechaza
expresamente: retirar la concesión directa no fingiría retirar el permiso efectivo.
La caché Spatie se invalida después de commit; las decisiones administrativas
sensibles ya se basan en SQL actual y se prueban también con relaciones obsoletas.

No se permite modificar las propias concesiones. Un operador con cualquier permiso
de revisión profesional no puede utilizar capacidades administrativas nuevas. HTTP
rechaza conceder revisión a una cuenta operadora y bootstrap rechaza revisores.
Los roles `admin`/`moderator` existentes pueden conservar moderación por sus permisos
reales, sin adquirir consulta de usuarios, delegación o auditoría nuevas.

### Aprovisionamiento inicial: procedimiento no ejecutado en desarrollo

Requiere acceso confiable al servidor/consola y una cuenta activa ya existente,
seleccionada y verificada fuera del formulario público. Ningún seeder concede las
capacidades nuevas ni restaura concesiones revocadas.

1. Revisar y aplicar por el procedimiento normal de despliegue la única migración
   aditiva. En este trabajo se aplicó sólo a `plataforma_legal_v2_test`; desarrollo
   mantiene 32 migraciones y todavía no tiene `operations_events`.
2. Elegir el ID numérico y exclusivamente las capacidades necesarias, separando a
   la persona administradora de quien revisará credenciales profesionales.
3. Simular en el entorno correcto, sustituyendo `123` por el ID comprobado:

   ```console
   php artisan operations:bootstrap 123 --capability=operations.accounts --capability=operations.permissions.editorial --capability=operations.permissions.verification --capability=operations.permissions.moderation --capability=operations.audit --reason="Aprovisionamiento inicial autorizado y verificado."
   ```

4. Revisar ID y selección. Sólo la misma orden con `--apply --confirm-user=123`
   persiste las capacidades explícitas y el evento `operator_bootstrapped`.
   Sin ambos parámetros no se concede nada. No existe una opción para todos los
   permisos del producto ni una concesión implícita de revisión o moderación.
5. El comando rechaza cuentas inactivas/revisoras, cualquier operador previo y
   cualquier bootstrap histórico. Una columna generada con índice único permite
   sólo un bootstrap persistido, incluso ante carreras entre objetivos distintos.

El actor de bootstrap es la consola confiable (`actor_id=NULL`, `source=console`),
sin suplantar a un usuario web; destinatario, selección, motivo y fecha quedan
registrados. Identificar al humano que usa la consola corresponde al control de
acceso y auditoría del servidor. Administración de operadores adicionales,
recuperación de un operador perdido y cambios de roles quedan fuera de HTTP y
requieren un procedimiento de mantenimiento confiable posterior; no se ofrece una
ruta de escalamiento ni repetir bootstrap borrando su historial.

Las únicas concesiones aplicadas al validar fueron a cuentas sintéticas de testing,
revertidas o eliminadas selectivamente por sus pruebas. Desarrollo no tiene ninguna
definición/concesión `operations.*` nueva.

## Editorial, revisión profesional y moderación

El resumen enlaza a workspaces editoriales sólo con los permisos editoriales reales
ya requeridos. El menú de cuenta y el perfil propio (también en móvil) muestran
Operaciones sólo con entrada autorizada y el menú muestra
Revisión profesional únicamente con `verification.review`; esa entrada es
independiente del panel administrativo. Administrar un catálogo no otorga sus
permisos de ejecución, lectura de evidencias, decisión ni aprobación propia.

La bandeja operativa usa `CommunityQuery::moderation` para `posts.moderate` y el feed
público para quien sólo modera comentarios. Consultar comentarios ocultos requiere
`comments.moderate` y permiso de ver la publicación. Las acciones están en Comunidad,
con sus versiones y policies existentes; las publicaciones borrador no ofrecen un
botón de moderación de estado no admitido. El wrapper vuelve a comprobar el permiso
efectivo actual antes de confirmar, bajo los bloqueos del servicio de dominio.
Un fallo de auditoría revierte también estado y versión.

Los servicios originales BACK-05 conservan sus contratos internos. La auditoría
operativa se incorpora en las entradas HTTP mediante `ModerationOperations`; futuras
integraciones que moderen contenido deben llamar a este wrapper. Una llamada directa
de código confiable a los métodos originales no produce este evento automáticamente.

## Auditoría durable y límites

`operations_events` conserva actor/destinatario con FK restrictiva, acción, recurso,
permiso aplicable, estados mínimos anterior/posterior, motivo, origen y fecha UTC.
Índices por destinatario/ID, actor/ID y acción/fecha facilitan lecturas acotadas.
No contiene token, contraseña, hash de contraseña, dirección IP ni evidencia.
El historial requiere su capacidad específica y pagina 20 eventos.

El modelo rechaza actualizar, incrementar o eliminar eventos incluso silenciosamente.
El escritor exige una transacción activa. Escritura de negocio y evento se confirman
o revierten juntos. El `down()` sólo elimina esta tabla si está vacía; rechaza borrar
auditoría persistida. No se ejecutó rollback de esquema.

Como en el backend previo, SQL directo, Query Builder o una cuenta de base de datos
con privilegios amplios pueden eludir protecciones de modelos. No se introdujeron
triggers, otro motor de auditoría ni promesas de inmutabilidad frente al DBA. En
producción hacen falta credenciales de mínimo privilegio, respaldos y una política
explícita de conservación. La FK restrictiva evita perder referencias por borrado
físico de usuarios; esta integración no ofrece borrados físicos.

## Validación y evidencia

Fecha: 2026-11-15. Antes de escribir se confirmó `APP_ENV=testing`, MySQL configurado
y efectivo `plataforma_legal_v2_test`, y disco privado efectivo en
`storage/framework/testing/disks/attachments` dentro del proceso. `Tests\TestCase`
mantiene el guard de base configurada y real antes de crear fixtures. Las pruebas
de medios conservan `Storage::fake`; las nuevas pruebas también lo verifican.
No se usaron migraciones destructivas ni seeders de desarrollo.

| Verificación | Resultado |
| --- | --- |
| Suite Laravel completa `php artisan test --compact` | **628 aprobadas, 13,911 assertions**, 128.51 s |
| Frontend `node --test tests/frontend/*.test.mjs` | **44 aprobadas**, 0 fallos |
| `npm run build` | Correcto; Vite 6.4.4, 94 módulos |
| `php vendor/bin/pint --dirty --test` | Aprobado |
| Sintaxis PHP de archivos nuevos/modificados (incluye fuentes Blade) | 43 archivos sin error; render real de pantallas y tests HTTP |
| Registro de rutas | **109**, 0 duplicados de método/dominio/URI |
| `git diff --check` | Sin errores |

Las 38 pruebas PHP añadidas cubren recuperación uniforme, correo real en `array`,
hash del token, caducidad, token incorrecto/reusado, cuentas inactivas, política de
contraseña, no reflashear secretos, revocación de sesión y remember, contraseñas con
forma de hash, CSRF, límites, configuración productiva indisponible, payload de cola
real cifrado, acceso administrativo, roles sin concesión implícita, scopes separados,
autoescalamiento, grants/revokes, herencia, estados, idempotencia, versiones,
autorización con caché obsoleta, acceso privado ajeno denegado, bootstrap sintético,
modelo de auditoría y rollback de cuenta/permiso/moderación/recuperación ante SQL
inyectado. Las regresiones de las siete integraciones anteriores permanecen verdes.

La prueba de concurrencia utiliza dos conexiones MySQL independientes y fixtures
comprometidos exclusivamente en testing, con limpieza selectiva en `finally`.
Confirma bloqueo (1205), transacciones perdedoras cerradas, 409 para versiones
obsoletas y un único evento/cambio por token consumido. No sólo repite secuencialmente
peticiones sobre una conexión.

La revisión visual utilizó snapshots de Blade renderizados en testing con fixtures
dentro de transacciones revertidas; servidor estático de QA sin Laravel ni conexión
de base de datos. Se comprobaron cuenta/resumen y recuperación en móvil y escritorio,
etiquetas asociadas, controles de 44/48 px y ausencia de desbordamiento horizontal.
Modo oscuro nativo y copia visual clara que cambia únicamente la condición CSS en
el servidor estático externo al repositorio. Se corrigió reutilización de clases
`profile-field` y se comprobó render de las transiciones admitidas. El perfil propio ofrece además
acceso autorizado a Operaciones para la navegación móvil. No se enviaron
formularios de credenciales ni concesiones por navegador. Esto complementa las
pruebas HTTP; no se presenta como prueba de entrega SMTP real.

Al terminar se comprobaron **48 tablas funcionales de testing vacías** (excluyendo
migrations/cache/cache_locks), con 33 migraciones. Desarrollo conserva 32 migraciones,
1 cuenta, 1 perfil, 1 adjunto, 3 roles, 25 permisos, 26 concesiones de rol y catálogos
1/32/2,478; sin perfiles profesionales, contenidos ni conversaciones. No tiene tabla
de auditoría nueva ni permisos `operations.*`. El SHA-256 del adjunto privado
preexistente coincide con el registrado al iniciar. Los otros worktrees conservan
su HEAD `c51224ffd14d438be000cdfc1bc0515942f94bde` y no se operó sobre ellos.

## Corrección puntual previa al commit

La revisión de cierre detectó dos invocaciones consecutivas de
`x-community.moderation-reason` en el mismo formulario POST de la tarjeta de
publicación. Ambas usaban `post-{id}`, produciendo dos textarea obligatorios
`name="reason"` y los mismos IDs para textarea/ayuda. No eran formularios separados
ni una instancia de publicación y otra de comentario.

La causa fue reintentar una edición por sustitución tras un fallo parcial de
codificación al leer otro archivo. La primera edición de la tarjeta ya se había
guardado y la sustitución no era idempotente: el reintento insertó de nuevo el
componente. Las pruebas anteriores cubrían HTTP, autorización y versiones, pero no
comprobaban la unicidad del motivo/IDs en el HTML de la tarjeta. No se creó commit
ni se realizó push al detectar el defecto.

Se retiró únicamente la invocación redundante, manteniendo la instancia dentro del
POST de moderación y conservando CSRF, `version`, `action` y motivo obligatorio.
El formulario de comentarios ya tenía una sola instancia. No se cambiaron permisos,
estados, servicios de BACK-05, transacciones ni auditoría.

La comprobación de accesibilidad mostró dos ajustes relacionados necesarios:

- El componente compartido vincula ayuda y error mediante IDs con su sufijo de
  recurso, `aria-describedby`, `aria-invalid` y mensaje con `role="alert"`. El motivo
  rechazado se conserva escapado sólo en el formulario enviado; los otros no
  heredan sus errores ni valores.
- `VersionRequest` identifica ese formulario con contexto de sesión derivado de la
  ruta autorizada. La validación HTML de comentarios vuelve al detalle del post con
  `review=1`, usando el post ya autorizado, en lugar de perder el formulario en el
  feed. Para publicaciones se abre su sección de administración cuando contiene el
  error. No cambian las reglas ni las respuestas JSON de moderación.

Se añadieron cinco regresiones a `OperationsIntegrationTest`, dentro del inventario
existente. Renderizan Blade real con DTO autorizados y páginas HTTP; analizan el
DOM, sin limitarse a buscar texto en los archivos fuente:

1. Tarjeta autorizada con un solo motivo dentro del POST correcto.
2. Tres tarjetas distintas con IDs únicos y un motivo por formulario; el feed real
   conserva su comportamiento sin habilitar nuevas acciones de moderación.
3. Detalle HTTP con una publicación y dos comentarios, con formularios e IDs
   independientes.
4. Extracción de CSRF/versión/acción del formulario renderizado y envío HTML real de
   motivos de publicación/comentario a las rutas existentes, comprobando resultado,
   incremento de versión y motivo persistido en auditoría.
5. Errores de motivo tras POST y redirección real: asociación sólo al campo enviado,
   conservación escapada del valor, alerta accesible, detalles abiertos, ausencia
   de IDs duplicados y ausencia de escrituras/versiones nuevas por validación.

La primera prueba se ejecutó antes de corregir y reprodujo el defecto: seis IDs en
el HTML, sólo cuatro distintos. La validación completa posterior se registra en la
tabla de resultados de este documento. La referencia previa era 623 pruebas y
13,661 assertions; tras la corrección son 628 pruebas y 13,911 assertions. La suite
dirigida de Comunidad/Operaciones aprobó 61 pruebas y 941 assertions. Frontend
conserva 44 pruebas; build, Pint, 43 comprobaciones de sintaxis PHP, 109 rutas sin
duplicados y `git diff --check` también aprobaron.

Esta corrección afecta sólo cinco archivos ya inventariados: tarjeta de publicación,
componente de motivo, `VersionRequest`, `OperationsIntegrationTest` y este documento.
El inventario continúa en **48 archivos: 32 nuevos y 16 modificados**, sin archivos
adicionales ni cambios en migraciones. No se realizó staging, commit ni push.

## Riesgos y pendientes operativos

- Antes de habilitar esta versión en desarrollo/producción, aplicar la migración
  aditiva y aprovisionar conscientemente al primer operador. Hasta entonces, el
  cambio de contraseña/moderación que necesita auditoría falla de forma atómica
  sin su tabla; no se afirma que ya esté desplegado.
- Configurar transporte de correo real, HTTPS/APP_URL y worker supervisado. Verificar
  entrega y observabilidad sin cuerpos/secretos; no se enviaron correos reales.
- El arranque está limitado al primer operador. Más operadores, recuperación de sus
  capacidades y cambios de roles requieren mantenimiento confiable; no hay un CRUD
  genérico de seguridad en HTTP.
- No hay catálogo de sesiones de dispositivos ni cambio directo con contraseña
  actual; Configuración enlaza la recuperación real y mantiene honestamente
  deshabilitadas 2FA, alertas y administración de otras sesiones.
- Denuncias/reportes completos, automatizaciones de moderación, notificaciones,
  exportaciones masivas, retención administrativa definitiva y auditoría frente a
  SQL/DBA quedan para una fase específica. No se hicieron LEXA, OAuth, pagos ni 2FA.
- Se preserva el almacenamiento privado y sus limitaciones ya documentadas. Ser
  operador no permite leer Mensajería ni evidencia profesional ajena.

No se realizó staging, commit, push, merge ni cambio de rama. HEAD permanece en
`3f154023f43d84d366ff1b3b08cb2261fd69eb1f`. Los cambios quedan para revisión.

## Inventario exacto de archivos

**48 archivos: 32 nuevos y 16 modificados; ninguno eliminado.** Sin dependencias, configuraciones de entorno, archivos privados, artefactos o fixtures versionables añadidos.

### Nuevos (32)

- `app/Console/Commands/BootstrapOperations.php`
- `app/Exceptions/PasswordRecoveryUnavailable.php`
- `app/Http/Controllers/Auth/PasswordRecoveryController.php`
- `app/Http/Controllers/Operations/OperationsController.php`
- `app/Http/Requests/Auth/PasswordRecoveryRequest.php`
- `app/Http/Requests/Operations/OperationsRequest.php`
- `app/Jobs/SendPasswordRecoveryLink.php`
- `app/Models/OperationsEvent.php`
- `app/Notifications/AccountPasswordReset.php`
- `app/Services/Auth/PasswordRecovery.php`
- `app/Services/Auth/RecoveryMailer.php`
- `app/Services/Operations/AccountOperations.php`
- `app/Services/Operations/ModerationOperations.php`
- `app/Services/Operations/OperationsAccess.php`
- `app/Services/Operations/OperationsAudit.php`
- `database/migrations/2026_11_15_000001_create_operations_events_table.php`
- `docs/operations-integration.md`
- `resources/js/auth/reset-fragment.js`
- `resources/js/password-reset.js`
- `resources/views/components/community/moderation-reason.blade.php`
- `resources/views/layouts/operations.blade.php`
- `resources/views/pages/auth/recovery.blade.php`
- `resources/views/pages/operations/audit.blade.php`
- `resources/views/pages/operations/error.blade.php`
- `resources/views/pages/operations/index.blade.php`
- `resources/views/pages/operations/moderation.blade.php`
- `resources/views/pages/operations/user.blade.php`
- `resources/views/pages/operations/users.blade.php`
- `tests/Feature/OperationsConcurrencyTest.php`
- `tests/Feature/OperationsIntegrationTest.php`
- `tests/Feature/PasswordRecoveryTest.php`
- `tests/frontend/reset-fragment.test.mjs`

### Modificados (16)

- `app/Http/Controllers/Community/CommentController.php`
- `app/Http/Controllers/Community/PostController.php`
- `app/Http/Middleware/EnsureActiveAccount.php`
- `app/Http/Requests/Community/VersionRequest.php`
- `app/Providers/AppServiceProvider.php`
- `bootstrap/app.php`
- `resources/js/app.js`
- `resources/views/components/community/post-card.blade.php`
- `resources/views/components/header/account-menu.blade.php`
- `resources/views/pages/auth/login.blade.php`
- `resources/views/pages/community/show.blade.php`
- `resources/views/pages/profile/show.blade.php`
- `resources/views/pages/settings/index.blade.php`
- `routes/web.php`
- `tests/Feature/CommunityIntegrationTest.php`
- `tests/TestCase.php`
