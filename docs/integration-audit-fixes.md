# INTEGRACIÓN-06B — Correcciones de auditoría funcional

Fecha de validación: 2026-11-15. Proyecto: Plataforma Legal V2.

Rama de trabajo: `main`. HEAD inicial y conservado:
`798f92c5e1c61cdc997a95e03142eb2b326c239d`.
Se partió de un árbol limpio. Esta intervención implementa las siete correcciones
solicitadas después de INTEGRACIÓN-06; no inicia otra integración.

## 1. Hallazgos y correcciones

### 1. Alto: diagnóstico SQL expuesto por autenticación y middleware

Un error de consultas en validadores, registro, login o comprobación persistida de
cuenta podía llegar al renderer de depuración. `bootstrap/app.php` ahora registra
un único renderer global de `QueryException`, antes de los renderers por módulo.
Devuelve HTTP 503, texto plano o JSON mínimo según la petición, con
`Cache-Control: private, no-store`, sin renderizar vistas ni consultar otra vez la
base para construir el error. No incluye SQL, bindings ni el texto de la excepción.

Se conserva el mensaje genérico ya contratado por Perfiles. Los otros módulos
reciben un mensaje genérico de indisponibilidad. Se conserva el reporte
estructurado de `DatabaseFailureReporter` y su interrupción del reporte ordinario
para excepciones SQL. No se agregan reportes duplicados. Registro vuelve a lanzar
los errores SQL no recuperables hacia este mecanismo; la colisión de correo 1062
conserva su validación de negocio. Validaciones ordinarias y errores de programación
ajenos a SQL mantienen su tratamiento original.

Regresión: 16 combinaciones de cuatro puntos de fallo, debug activado/desactivado
y respuesta HTML/JSON. Se comprueba código, caché y ausencia de información sensible
en respuesta y reporte, además de casos separados de validación y RuntimeException.

### 2. Alto: Configuración simulaba cambios de seguridad

`settings/index.blade.php` muestra nombre y correo reales, escapados, del usuario
autenticado y enlaces a las rutas existentes de perfil personal, profesional y
consulta del perfil. Se retiraron identidades, dispositivos y estados de seguridad
inventados, mensajes de éxito ficticios y variables que simulaban persistencia.

Las 19 acciones futuras quedan deshabilitadas y explicadas como pendientes. No se
activa 2FA, OAuth, cambios de privacidad ni revocación de otras sesiones. Se mantiene
el POST real de logout con CSRF y su confirmación. Se preservan navegación lateral,
tarjetas, clases visuales, comportamiento móvil y modo oscuro. No se crean campos
de preferencias ni se serializa el modelo del usuario al cliente.

### 3. Medio: especialidades vacías se restauraban después de un error

`ProfileFormRequest` normaliza la ausencia de `specialty_ids` a `[]` únicamente
cuando `update_specialties` indica una sustitución explícita en la ruta profesional.
Conserva la intención y el conjunto vacío en el old input, tanto al fallar el Form
Request como cuando la validación posterior del servicio utiliza la petición
original. Si la bandera no se envía, conserva la semántica previa de campo ausente.

Además, el catch de errores de dominio en `ProfileController` conserva únicamente
input validado y excluye `license_number`. La vista profesional existente ya consume
correctamente `old('specialty_ids', ...)`; no requirió modificaciones. Las pruebas
cubren especialidades activas e históricas, error de formulario, fallo SQL durante
la persistencia y una validación real del servicio tras cambiar un catálogo entre
las dos validaciones. No se persisten cambios al fallar.

### 4. Medio: etiquetas y adjuntos vacíos se restauraban después de un error

`CommunityFormRequest` aplica la misma distinción entre ausencia y conjunto vacío
con `replace_tags`/`replace_attachments`, limitada a crear/editar publicaciones.
Las listas normalizadas se trasladan a la petición original para errores posteriores
del servicio. Se conservan banderas válidas y la versión original de la publicación.

Las regresiones verifican fallos del Form Request y del servicio, estado de las
asociaciones y existencia del archivo anterior. Se añadió el caso real de un upload
con extensión PNG y contenido de texto: el rechazo del validador de adjuntos conserva
ambos grupos vacíos y la versión. No se alteran servicios, reglas de retención ni
compensaciones; retirar una asociación no borra físicamente el archivo.

### 5. Medio: Inicio presentaba actividad y profesionales ficticios

`HomeContentController` deja de cargar `home/demo.php`. Reutiliza la consulta
editorial existente y `CommunityQuery::recent` con DTO públicos; consulta los
guardados en lote para las dos publicaciones, sin recrear filtros de dominio.
La vista muestra autor, contenido escapado, fecha UTC y número real de comentarios,
enlaces contextuales y el botón de guardado existente. Si no hay contenido público,
muestra un estado vacío explícito.

Las falsas conversaciones y borradores se sustituyen por tres accesos a Mensajes,
publicaciones propias/borradores y guardados, sin aparentar actividad personal.
El falso profesional recomendado se sustituye por un acceso al directorio. Se
elimina el JSON demo del flujo activo y su JavaScript de continuaciones ficticias.
Los archivos demo que no intervienen en este flujo no se borran en esta fase.
Inicio y LEXA identifican expresamente la demostración local de LEXA.

La comprobación visual detectó que el CSS heredado ocultaba los párrafos directos
de la tarjeta de Comunidad. El extracto real usa el encabezado ya estilizado del
componente, con límite de 250 caracteres y una regresión de DOM. No se cambió CSS
ni se rediseñó Inicio.

### 6. Bajo: errores de Comunidad sin asociación accesible

Se agrega un componente de error de campo con IDs estables y texto escapado.
Contenido, categoría, especialidad, visibilidad, etiquetas, adjuntos y uploads
incluyen sus mensajes y asociaciones `aria-describedby`/`aria-invalid` cuando
corresponde. Se contemplan errores anidados de listas y el error de dominio `file`
del validador de adjuntos, sin cambiar el contrato de ese servicio.

El resumen general tiene `role="alert"`, nombre accesible y foco programático.
El script lo enfoca al volver con errores si no existe otro control enfocado.
Se conserva el envío progresivo de los formularios. Comentarios, respuestas y
edición de comentarios tienen asociaciones específicas; el error se muestra sólo
en el formulario de la respuesta enviada, con IDs únicos. También se conserva un
`parent_id` numérico válido para identificar ese contexto después del redirect.

### 7. Bajo: fechas de Mensajería sin zona horaria explícita

El servidor mantiene almacenamiento y contratos UTC. Los `<time>` del inbox y del
historial conservan `datetime` ISO con offset y un fallback visible y title en UTC.
El nuevo módulo `messages/dates.js` usa `Intl.DateTimeFormat` en la zona del navegador
y siempre parte de `datetime`, nunca del texto previamente convertido. Se ejecuta
al entrar al inbox y después del reemplazo de fragmentos del sondeo.

Fechas inválidas, sin offset o errores de Intl conservan el fallback. Las pruebas
frontend cubren México frente a UTC, horario de verano, cambio de día, entradas
inválidas y localización repetida de nodos iniciales y nuevos. Las pruebas HTTP
comprueban fallback, ISO, fragmentos y conservación del instante/marcador de lectura.

## 2. Inventario exacto

24 archivos: **5 nuevos y 19 modificados**. No hay eliminaciones.

### Nuevos (5)

1. `docs/integration-audit-fixes.md`
2. `resources/js/messages/dates.js`
3. `resources/views/components/community/field-error.blade.php`
4. `tests/Feature/IntegrationAuditFixesTest.php`
5. `tests/frontend/messages-dates.test.mjs`

### Modificados (19)

1. `app/Http/Controllers/Auth/RegisteredUserController.php`
2. `app/Http/Controllers/ContentLibrary/HomeContentController.php`
3. `app/Http/Controllers/Profiles/ProfileController.php`
4. `app/Http/Requests/Community/CommunityFormRequest.php`
5. `app/Http/Requests/Profiles/ProfileFormRequest.php`
6. `bootstrap/app.php`
7. `resources/js/community.js`
8. `resources/js/home.js`
9. `resources/js/messages.js`
10. `resources/views/components/community/comment-form.blade.php`
11. `resources/views/components/community/feedback.blade.php`
12. `resources/views/components/community/post-form.blade.php`
13. `resources/views/components/home/post.blade.php`
14. `resources/views/pages/community/comment-edit.blade.php`
15. `resources/views/pages/home/home.blade.php`
16. `resources/views/pages/lexa/index.blade.php`
17. `resources/views/pages/messages/partials/history.blade.php`
18. `resources/views/pages/messages/partials/inbox.blade.php`
19. `resources/views/pages/settings/index.blade.php`

No se modifican rutas, migraciones, seeders, dependencias, permisos, modelos,
servicios de dominio, configuración de despliegue ni otros worktrees. Los cambios
de Blade/JavaScript anteriores corresponden exclusivamente a los hallazgos solicitados.
El build genera artefactos ignorados, excluidos del inventario.

## 3. Aislamiento y conservación

- Antes de las pruebas se verificó entorno `testing`, driver MySQL, base configurada
  y resultado real de `SELECT DATABASE()`: `plataforma_legal_v2_test`. El guard de
  `Tests\TestCase` impide continuar antes de fixtures si no se cumple este contrato.
- Después de la suite completa y la ejecución focalizada final, una consulta de
  sólo lectura confirmó las 47 tablas funcionales de testing vacías y 32 migraciones.
  Se conservó la caché técnica; no se ejecutaron migraciones ni seeders en desarrollo.
- Las nuevas regresiones usan transacciones con rollback y disco falso privado en
  `storage/framework/testing/disks/attachments`; comprueban base y raíz efectiva al
  crear metadatos de adjuntos. Los seeders de la suite se ejecutan sólo en testing.
- La revisión visual utilizó un servidor HTTP separado en el puerto 8107. Se
  verificaron base real y raíces efectivas de adjuntos, caché, sesiones, vistas y
  logs dentro de ese proceso antes de escribir fixtures. Se aislaron fuera del repo
  bajo el directorio de evidencias indicado abajo, antes de arrancar los providers.
- Se utilizaron usuarios, catálogos y contenido sintéticos, además de servicios
  reales de perfiles, publicaciones, adjuntos y mensajes. No se sustituyeron los
  módulos funcionales por mocks. La inyección de fallos SQL se limita a regresiones
  que necesitan provocar indisponibilidad o rollback.
- Se retiraron selectivamente los fixtures propios de la comprobación visual. Su
  TXT sintético externo se verificó por ruta y huella antes de retirarlo. No se
  borraron archivos privados preexistentes ni se ejecutó limpieza general de caché.
  El servidor propio quedó detenido y la pestaña de QA cerrada; no se detuvo el
  servidor de desarrollo existente del puerto 8000.
- Una transacción de sólo lectura confirmó que los recuentos de desarrollo coinciden
  con la línea base: 1 usuario, 1 perfil personal, 1 adjunto, 0 perfiles profesionales,
  publicaciones, comentarios, artículos, recursos, guardados, conversaciones o
  mensajes; 3 roles, 25 permisos, 26 concesiones de rol, 1 país, 32 estados y 2478
  municipios. Se mantienen 32 migraciones en desarrollo y testing.
- El único PNG privado preexistente conserva SHA-256
  `FE57B91CAE20E3EE77AC211A634FF67D627E7A6B6C6536B59E3A4B73977C51F8`.
  No aparecieron archivos adicionales en los discos funcionales de desarrollo.

## 4. Validación automatizada

| Comprobación | Resultado final |
| --- | --- |
| `php artisan test --compact --filter=IntegrationAuditFixesTest` | 28 aprobadas, 344 assertions |
| `php artisan test --compact` | 559 aprobadas, 13,007 assertions; 118.03 s |
| `node --test tests/frontend/*.test.mjs` | 41 aprobadas, 0 fallos |
| `npm run build` | Correcto; Vite 6.4.4, 92 módulos |
| Pint `--test` sobre PHP nativo afectado | Correcto; 7 archivos |
| `php -l` sobre PHP y Blade afectados | Correcto; 18 archivos |
| Inspección del registro efectivo de rutas | 85 rutas; 17 de Mensajería; sin nombres ni combinaciones método/dominio/URI duplicados |
| `git diff --check` | Correcto |
| Inventario documental frente a `git status --short --untracked-files=all` | Coincidencia exacta: 24 archivos; 5 nuevos, 19 modificados |

Son 28 pruebas Laravel y 4 frontend adicionales a las referencias de 531/37. La
suite completa aprobó los contratos anteriores, además de las regresiones nuevas.
El barrido de los archivos afectados no detectó marcadores de conflictos, claves
privadas/tokens ni errores de codificación UTF-8. La revisión manual del diff no
detectó cambios ajenos. Estos resultados no sustituyen los límites operativos
documentados en el apartado de riesgos.

## 5. Revisión visual

Se comprobaron escritorio (1280 px), móvil (390 × 844) y modo oscuro en navegador:

- Inicio: artículo y publicación públicos reales, comentario real, ausencia de
  actividad inventada, extracto visible y aviso de demostración de LEXA.
- Configuración: identidad del usuario sintético autenticado, enlaces funcionales,
  acciones futuras deshabilitadas y logout POST real seguido de acceso protegido
  redirigido al login.
- Perfil profesional: desmarcar todo y provocar error de URL mantiene cero
  especialidades seleccionadas y la bandera de sustitución; corregir el dato y
  reenviar guarda el conjunto vacío.
- Comunidad: rechazar una imagen inválida conserva cero etiquetas/adjuntos marcados,
  banderas y versión; el foco queda en el resumen y el upload referencia su error.
  La publicación conservó versión 2 y ambas asociaciones durante el fallo. Reenviar
  sin el archivo inválido guardó versión 3 y ambos conjuntos vacíos; el archivo
  sintético anterior conservó su huella, sin eliminación física por el formulario.
- Mensajería: el instante `07:49 UTC` aparece como `01:49 a. m.` en México. Un segundo
  mensaje creado desde el otro participante mediante el servicio real se recibió
  por sondeo y apareció como `02:10 a. m.` desde `08:10 UTC`. Los ISO permanecieron
  iguales y el sondeo no marcó lectura automáticamente.
- No se observó desbordamiento horizontal en las cuatro pantallas móviles ni
  errores/advertencias de consola al finalizar.

Evidencias fuera del repositorio, bajo
`C:/Users/La Chepocompu/.codex/visualizations/2026/11/14/01a1d919-931b-7ef2-868f-a30ec62d0b8a/audit06b/`:

- `home-desktop.png`
- `settings-desktop.png`
- `community-errors-mobile.png`
- `messages-local-mobile.png`

## 6. Incidencias resueltas durante la validación

1. La primera suite detectó que la respuesta global SQL había cambiado el mensaje
   contratado por Perfiles. Se preservó ese mensaje en el renderer global y se
   repitieron las comprobaciones; no se debilitó la prueba existente.
2. La recuperación del formulario profesional también perdía input al capturar
   errores de dominio. Se corrigió el catch, excluyendo la cédula del reflasheo.
3. El validador de adjuntos usa la clave `file`; se enlazó ese error con el control
   de upload. La revisión de respuestas detectó además el contexto numérico de
   `parent_id`, ahora conservado. Ambos casos tienen regresión.
4. El harness HTTP externo configuraba inicialmente la caché después del arranque
   de providers, produciendo un 403 en permisos ya otorgados al usuario sintético.
   Se corrigió exclusivamente el router externo para aplicar el aislamiento antes
   de providers y se verificó la raíz efectiva. Los recorridos reales se repitieron
   sin cambiar permisos ni lógica de autorización del producto.
5. El extracto de Inicio quedaba oculto por una regla CSS heredada. Se resolvió
   usando el elemento ya estilizado de la tarjeta y se verificó en navegador.

## 7. Pendientes deliberados y riesgos restantes

No se implementan LEXA real, 2FA, OAuth, administración de otras sesiones,
preferencias de privacidad ni revisión/verificación profesional por HTTP. No se
inventan estados ni acreditaciones para suplirlos. Los controles futuros deben
permanecer deshabilitados hasta una integración explícita y autorizada.

Continúan los límites documentados en `attachments.md`, `backend-audit-closure.md`
y las integraciones anteriores: SQL directo/builders o privilegios de DBA pueden
eludir reglas de dominio; la auditoría no es inviolable ante esos accesos. Se deben
mantener mínimo privilegio, acceso restringido al almacenamiento privado, protección
y retención de logs, backups y restauración probada. Esta corrección sanitiza la
respuesta SQL, pero no permite habilitar debug en producción como práctica operativa.

Antes de producción siguen pendientes antivirus/cuarentena y procesamiento seguro
multimedia, cuotas, política legal de retención/liberación, reconciliación de objetos,
ejecución operativa de purgas y gestión de archivos preparados abandonados. No se
relajan las retenciones existentes. Los límites PHP/proxy y el total multipart deben
alinearse con las cargas permitidas: el entorno actual de 40M no cubre todas las
combinaciones admitidas por la aplicación. No se modificó PHP ni infraestructura.

La presentación de fechas depende de Intl y la zona configurada por el navegador;
sin soporte JavaScript/Intl se muestra UTC explícito. No se agregó una preferencia
persistida de zona horaria. La nueva accesibilidad cubre los formularios indicados,
sin pretender certificar accesibilidad integral del producto.

## 8. Recomendación de cierre y Git

Se recomienda cerrar los siete hallazgos de INTEGRACIÓN-06 tras la revisión de
estas correcciones y continuar después con el plan de la Fase 1. No se declara
preparación completa para producción ni se inicia un módulo posterior.

La entrega conserva `main` y el HEAD inicial, con los 24 archivos enumerados como
cambios locales. El índice quedó vacío: no se realiza staging, commit, push,
merge ni cambio de rama. Los otros dos worktrees conservan sus HEAD y no se operó
en ellos. La revisión del inventario y diff excluye credenciales, uploads privados,
fixtures y artefactos de QA.
