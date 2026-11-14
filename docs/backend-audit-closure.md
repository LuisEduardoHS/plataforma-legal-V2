# AUDIT-02 — Cierre técnico de BACK-01 a BACK-07

Fecha: 14 de noviembre de 2026. Rama de trabajo: `main`, base `bc3c496`.
Cambios sin staging, commit, push, merge ni cambio de rama. No se modificaron
frontend, rutas, worktrees, dependencias ni migraciones anteriores.

## Diagnóstico confirmado y correcciones

| Hallazgo de AUDIT-01 | Evidencia contra el código inicial | Resolución |
| --- | --- | --- |
| Alta: purga dentro de transacciones externas | `purge` borraba Storage dentro de una transacción anidable; un rollback exterior podía restaurar `available` sin objeto | Rechazo inmediato cuando el nivel transaccional es distinto de cero. La intención de borrado debe estar confirmada antes de la purga física |
| Alta: información privada en errores SQL | `QueryException::getMessage()` contiene SQL y bindings; los servicios reportaban la excepción completa | Reporte central estructurado y terminal para QueryException, sin mensaje, SQL, bindings, trace, instancia de excepción ni datos de conexión |
| Alta condicionada: autorización con snapshots anteriores | Lecturas ordinarias de cuentas, posts, perfiles y bandeja podían ver estados previos bajo REPEATABLE READ | Lecturas actuales con bloqueo compartido en datos de autorización, relaciones sensibles y subconsultas. Regresiones reales con dos conexiones y controles que siguen mostrando el snapshot antiguo |
| Media: revisiones editoriales silenciosas | Los eventos de modelo no se ejecutan con saveQuietly/deleteQuietly | Protección en performUpdate, performDeleteOnModel e incrementOrDecrement, también para operaciones silenciosas |
| Media: resembrar restaura permisos revocados | givePermissionTo aplicaba de nuevo la lista base a todos los roles existentes | La concesión base se hace exclusivamente al crear un rol; las reejecuciones conservan concesiones adicionales y revocaciones |
| Baja: posiciones arbitrarias en adjuntos de Comunidad | La validación array aceptaba claves textuales y dispersas que terminaban como position | attachment_ids exige list; se rechazan también listas anidadas |
| Baja: estados arbitrarios de cuenta | Columna string sin CHECK y sin validación de transiciones en el modelo | Enum de dominio, validación de instancias incluso sin eventos, comparación con estado persistido bajo bloqueo y CHECK incremental |
| Baja: ruta /inicio duplicada | routes/web.php registra dos veces la misma vista y nombre | Pendiente explícito para INTEGRACIÓN-01; no se modificó ese archivo por la instrucción de coordinación |

Todos los hallazgos permanecían presentes al comenzar; ninguno se descartó como
ya corregido. El de snapshots, inicialmente condicionado por inspección, cuenta
ahora con reproducción controlada en MySQL y regresiones de comportamiento.

## Contratos resultantes

### Archivos y transacciones

- `store`: requiere nivel transaccional cero para que la reserva sea durable.
- `requestDeletion`: puede incluirse en una transacción del consumidor; no realiza
  borrado físico. Si esa transacción revierte, el objeto sigue existiendo.
- `purge`: requiere nivel cero, incluso con intención previamente confirmada o
  archivo ya eliminado. Debe llamarse después del commit del consumidor.
- Un fallo SQL después de borrar físicamente conserva la intención confirmada;
  el reintento completa los metadatos aunque el objeto ya no exista.
- No se liberaron retenciones ni se relajaron comprobaciones de Verificación,
  Comunidad, Mensajería o Biblioteca. Las referencias editoriales históricas siguen
  impidiendo la purga. Eliminar posts/mensajes puede liberar sus referencias activas.

### Reporte SQL

`DatabaseFailureReporter`, registrado en `bootstrap/app.php`, emite únicamente:

```json
{"message":"database.operation_failed","context":{"sqlstate":"HY000","driver_code":1205,"operation":"SELECT"}}
```

SQLSTATE se valida contra cinco caracteres alfanuméricos; el código requiere tipo
entero; la operación pertenece a una lista fija o es OTHER. Los valores inesperados
se descartan. El callback detiene el reporte predeterminado de QueryException.
Esto protege tanto `report($error)` de los servicios como excepciones SQL no manejadas.

Se revisó `config/logging.php`: cambiar el nivel o el canal no elimina bindings.
La sanitización se realiza antes de todos los canales configurados; por eso no fue
necesario modificar sus opciones. Los consumidores futuros no deben registrar
peticiones, SQL, excepciones originales o payloads por su cuenta. Esta corrección
no promete que cualquier otro tipo de excepción o logger añadido en el futuro
esté libre de datos personales.

Las cuatro pruebas de concurrencia anteriores observan ahora el código sanitizado
mediante MessageLogged; ya no dependen de callbacks posteriores que reciban una
QueryException original después de detener su reporte.

### Lecturas, privacidad y concurrencia

Se utilizan lecturas compartidas actuales en los puntos sensibles de Comunidad,
Perfiles, Mensajería y Biblioteca, incluyendo las relaciones de autor/perfil,
ocultamientos, archivos y pertenencias de roles/permisos. Adjuntos y la comprobación
del actor de Verificación también recargan el estado mediante lectura actual.
Se conserva el contrato string de `User::status` para los consumidores existentes.

No se introdujeron conexiones alternativas de lectura en producción, cambios de
aislamiento global, bloqueos exclusivos para los nuevos listados, tablas, cachés
o dependencias. Los lectores compartidos son compatibles entre sí, comprobado con
dos conexiones. No aumentó el número de consultas de las regresiones de N+1 existentes.

Con autocommit los bloqueos terminan al finalizar cada sentencia; si un consumidor
abre una transacción exterior, permanecen hasta su commit/rollback. Esa transacción
debe ser corta: no envolver una petición completa, esperas de usuario o transmisión
de respuestas. Los nuevos current reads pueden esperar ante escrituras no confirmadas;
no se promete ausencia de deadlocks ni una revocación retroactiva de respuestas ya
autorizadas/en curso. Se conservan los bloqueos exclusivos del dominio existente.

### Aprovisionamiento de roles

El seeder crea permisos faltantes y aprovisiona permisos base solamente para roles
nuevos, dentro de una transacción. Si un rol ya existe, incluso vacío, no reconstruye
su lista de concesiones. Tampoco retira permisos ni reasigna usuarios. Reparar o
reaprovisionar un rol existente requiere una operación administrativa explícita y
revisable; ejecutar DatabaseSeeder no debe usarse para ello.

Los seeders de verificación y editorial siguen creando permisos sin concederlos.
No se ejecutaron seeders en desarrollo durante AUDIT-02.

### Estados de cuenta

| Origen | Destinos permitidos, además de conservar el mismo estado |
| --- | --- |
| active | suspended, disabled, pending_deletion |
| suspended | active, disabled, pending_deletion |
| disabled | active, pending_deletion |
| pending_deletion | ninguno; terminal en este contrato |

Se validan inserciones, save/saveQuietly y cambios persistidos desde instancias
obsoletas; los incrementos no pueden asignar status como efecto adicional. No se
implementó una interfaz de administración de cuentas ni autorización para cambiar
estados: eso debe incorporarse en la integración, con actor autorizado y auditoría.

SQL directo, actualizaciones masivas y builders pueden eludir transiciones y
protecciones de instancia. El CHECK valida los valores posibles, no una transición
entre valores válidos. Del mismo modo, los guards de ContentRevision no convierten
la BD en un historial inviolable ante SQL directo o personal con privilegios.

## Migración incremental

`2026_11_14_000001_add_user_status_check.php` añade `users_status_check` con comparación
binaria: se rechazan estados desconocidos, mayúsculas y espacios adicionales.

Antes del DDL revisa datos incompatibles y aborta con un mensaje sin datos personales;
no corrige ni reescribe usuarios. Se confirmó cero incompatibilidades en ambos
entornos, se revisó el SQL con migrate --pretend y se aplicó únicamente esta migración
por --path en testing y desarrollo. No se ejecutaron reconstrucciones ni rollbacks.

Resultado: 32 migraciones por entorno. Los datos funcionales de desarrollo se
conservaron; el cambio allí fue de esquema y registro de la migración.

## Validación y regresiones

- Aislamiento: bootstrap real de Laravel confirmó testing/mysql y SELECT DATABASE()
  igual a plataforma_legal_v2_test. Tests\TestCase ahora aborta antes de fixtures
  cuando el entorno, driver, nombre configurado o base efectiva no coinciden.
- Sintaxis: 31 archivos PHP cambiados/nuevos sin errores; Pint aplicado y --test aprobado.
- Nuevas regresiones: 26 casos adicionales respecto a las 283 pruebas originales;
  ejecución filtrada final aprobada con 175 assertions.
- Pruebas de Adjuntos, Comunidad y Mensajería: 83 aprobadas, 962 assertions.
- Suite completa final: 309 pruebas aprobadas, 10,112 assertions. Incluye los siete
  módulos, pruebas de concurrencia existentes y verificaciones de consultas acotadas.
- git diff --check sin errores. Índice de Git sin cambios preparados.
- Verificación posterior a todas las pruebas: las 49 tablas de testing distintas
  de migrations quedaron vacías. Ambos entornos tienen 32 migraciones, ninguna
  pendiente y users_status_check habilitado. Los catálogos y las concesiones de
  desarrollo conservan sus cantidades previas; no existen usuarios funcionales allí.

BackendAuditTest cubre logs y valores diagnósticos adversos, revisiones silenciosas,
conservación de revocaciones, listas inválidas, estados estrictos en modelo/MySQL y
transiciones terminales. UserStatusTest comprueba las 16 combinaciones de la matriz.

BackendAuditConcurrencyTest usa dos conexiones MySQL, snapshots previos reales y
fixtures confirmados exclusivos. Cubre privatización, hilos de comentarios, alias,
perfiles públicos/privados, suspensión de autores, tres estados de cuenta inactiva,
revocación de permisos directos y pertenencia a roles, bandeja, ocultamiento,
redacción del último mensaje, no leídos, listados y workspace editorial.

AttachmentTest añade rollback externo, intención confirmada, reintentos y una purga
sin transacción exterior que verifica las cuatro retenciones y archivos históricos.
Se mantienen las pruebas previas de fallos parciales de Storage y SQL. Dos pruebas
transaccionales de Comunidad/Mensajería se adaptaron al contrato de purga; la prueba
de purga real conserva su cobertura física sin confirmar los fixtures compartidos.

Las pruebas con datos confirmados limpian únicamente sus IDs en finally/tearDown;
los archivos usan Storage::fake. No se ejecutó ninguna prueba contra desarrollo.

## Pendientes y límites de producción

- INTEGRACIÓN-01 debe resolver la ruta /inicio duplicada y conectar autenticación,
  middleware de cuenta activa, CSRF, rate limiting y respuestas HTTP explícitas.
- No serializar modelos internos ni aceptar actor_id del cliente. Escapar texto en
  Blade; adaptar demos/DTO, fechas, versiones, cursores, guardados y tokens de envío.
- Definir retención y liberación auditada para evidencias y archivos históricos;
  mantenimiento de reservas y atención a propietarios inactivos.
- Cuarentena/antimalware, validación estructural y límites multimedia antes de
  operar archivos reales comercialmente. available no certifica ausencia de malware.
- Mínimo privilegio en BD/Storage, acceso y retención de logs, backups con pruebas de
  restauración, protección de temporales y plan de rotación de APP_KEY/HMAC.
- Auditoría administrativa y de moderación, reconciliación de almacenamiento,
  monitoreo y procedimientos de incidentes antes de operar esas capacidades.
- Tiempo real, grupos, notificaciones, búsqueda avanzada, miniaturas y reproducción
  inline siguen pospuestos. No se añadieron funcionalidades para resolver la auditoría.

## Estado final por módulo

BACK-01: base y catálogos conservados; estados y aprovisionamiento endurecidos.
BACK-02: contrato físico/transaccional corregido; retenciones preservadas.
BACK-03: lecturas sensibles actuales; contratos de perfiles conservados.
BACK-04: auditoría/huellas/retención preservadas y suite de verificación aprobada.
BACK-05: privacidad actual y listas ordenadas; escrituras y concurrencia conservadas.
BACK-06: bandeja y autorización actuales; mensajes privados e idempotencia conservados.
BACK-07: revisiones silenciosas protegidas y consultas actuales; guardados conservados.

Los siete módulos están listos para iniciar integración funcional controlada,
sujetos a revisión de estos cambios y a los controles HTTP indicados. Este cierre
no autoriza producción ni elimina las limitaciones operativas documentadas.

## Inventario exacto de fuentes

Creados (6):

- app/Enums/UserStatus.php
- app/Support/DatabaseFailureReporter.php
- database/migrations/2026_11_14_000001_add_user_status_check.php
- docs/backend-audit-closure.md
- tests/Feature/BackendAuditConcurrencyTest.php
- tests/Feature/BackendAuditTest.php

Modificados (27):

- app/Models/Comment.php
- app/Models/ContentRevision.php
- app/Models/EditorialEntry.php
- app/Models/Post.php
- app/Models/User.php
- app/Services/Attachments/AttachmentService.php
- app/Services/Community/CommunityAccess.php
- app/Services/Community/CommunityQuery.php
- app/Services/Community/PostService.php
- app/Services/ContentLibrary/ContentAccess.php
- app/Services/ContentLibrary/EditorialQuery.php
- app/Services/Messaging/MessagingAccess.php
- app/Services/Messaging/MessagingQuery.php
- app/Services/Profiles/ProfileService.php
- app/Services/Verification/VerificationService.php
- bootstrap/app.php
- database/seeders/RolePermissionSeeder.php
- docs/attachments.md
- tests/Feature/AttachmentTest.php
- tests/Feature/CommunityConcurrencyTest.php
- tests/Feature/CommunityTest.php
- tests/Feature/ContentLibraryConcurrencyTest.php
- tests/Feature/MessagingConcurrencyTest.php
- tests/Feature/MessagingTest.php
- tests/Feature/VerificationConcurrencyTest.php
- tests/TestCase.php
- tests/Unit/UserStatusTest.php
