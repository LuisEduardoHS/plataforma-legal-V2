# 09E.2-C — Perfil como centro profesional

## Inspección y contratos de partida

Partida comprobada: `main`, HEAD `42ae250dec6ebdc5ddf01c791b9c875005b2756d`,
árbol y staging limpios, `origin/main` sincronizado. Alcance: 09E1-04, 05 y 06.

- `/perfil` proyecta por separado los perfiles personal y profesional del actor.
  La presentación pública no sustituye un perfil personal privado por el nombre
  de cuenta. La cédula está cifrada y no se recupera en formularios ni old input.
- `SaveProfessionalProfileRequest` es estricto. `update_specialties` distingue
  reemplazar (incluida selección vacía) de omitir/conservar asociaciones.
- `ProfileService` bloquea cuenta y perfil, valida especialidades activas y hace
  `sync` solo ante una selección explícita. Al reemplazar, no acepta IDs inactivos,
  aunque estuvieran asociados. Conservarlos al mismo tiempo que se reemplazan
  otras asociaciones requeriría cambiar ese contrato; no se hará silenciosamente.
- Los cambios materiales son los de `VerificationIntegrity::MATERIAL_FIELDS`
  y el cambio efectivo de asociaciones. `ProfileService` sigue siendo el único
  responsable de guardar e invocar la invalidación existente de BACK-04.
- Los formularios de Perfil no tenían tokens de versión HTTP. El dominio sí
  serializa sus escrituras mediante transacciones y bloqueos.
- `VerificationWorkflow::own` proporciona requisitos mínimos, expedientes y
  `can_create`. Los requisitos del perfil para presentar son título, cédula y
  especialidad activa; el expediente exige además evidencia privada de cédula.
  Se puede preparar un borrador antes de completarlos.
- Perfil: `unverified`, `pending`, `verified`, `rejected`, `revoked`.
  Expediente: `draft`, `pending`, `approved`, `rejected`, `revoked`, `invalidated`.
  No se añaden estados ni se infiere aprobación a partir de un expediente histórico.

## Diseño acotado

La intención de especialidades se expresará mediante acciones de guardado con
nombre explícito. Aplicar una selección retira las asociaciones que no contiene;
retirar inactivas requiere una confirmación adicional calculada por el servidor.
Guardar otros datos conserva todas las asociaciones. No se conceden permisos ni
se amplía la elegibilidad de especialidades inactivas.

La coordinación HTTP de edición revisará cambios bajo los mismos bloqueos antes
de escribir. Si requiere confirmación, devolverá categorías, nunca valores
sensibles, y una propuesta cifrada autenticada de cinco minutos, ligada a actor,
sesión y versión de perfil/asociaciones/expedientes. El PUT final solo ejecutará
esa propuesta si sigue vigente. La escritura material cambia la versión e impide
reutilizar la confirmación. El dominio conserva la validación final e invalidación.

La revisión y el guardado sin necesidad de confirmación usan POST; la confirmación
material realiza un único PUT. Con JavaScript se usará diálogo nativo y sin él una
página de confirmación. Un PUT directo no podrá eludir una confirmación requerida.

Perfil será el centro principal. Se evaluará Inicio al medir el coste; no se
añadirá una segunda implementación de la lógica de verificación.

## Centro profesional y próximos pasos — 09E1-05

La sección profesional existente de Perfil incorpora una proyección pequeña
(`ProfessionalHub`) que reutiliza `VerificationWorkflow::own`. No se añade un
dashboard, identidad, rol, permiso ni transición. No se transportan motivos de
resolución, evidencias, nombres de documentos ni números de cédula a la vista.

| Situación real | Orientación y siguiente paso |
| --- | --- |
| Sin presentación | Crear presentación profesional |
| Incompleta | Enumerar título, cédula declarada o especialidad activa faltantes; editar |
| Datos completos, sin solicitud | Preparar solicitud en Mi verificación |
| Borrador | Continuar el expediente; completar datos si todavía faltan |
| Presentada, pendiente | Consultar solicitud, sin prometer plazo |
| Aprobada vigente | Consultar expediente e insignia actual |
| Rechazada | Consultar resolución y preparar los pasos disponibles |
| Revocada | Revisar el estado actual en Mi verificación |
| Invalidada por edición material | Explicar el estado y volver a Mi verificación |
| Historia incoherente con el estado actual | Consultar situación; no inferir una insignia del histórico |

Se muestran separadamente visibilidad profesional, presentación, especialidades,
edición y acceso público cuando corresponde. La creación no duplica su enlace.
La cuenta convencional sigue pudiendo crear una presentación sin obtener permisos.

El resumen consulta siempre la primera página de solicitudes, aunque Perfil reciba
`?page=99`. El parámetro opcional de paginación añadido a `VerificationWorkflow`
solo afecta esta proyección HTTP; Mi verificación conserva su paginación habitual.
El expediente vigente y los históricos se distinguen usando los estados existentes.
No se sustituye la autorización final de las pantallas enlazadas.

Perfil adquiere primero los bloqueos de cuenta/perfil del workflow y después hace
sus lecturas. El editor obtiene primero su versión y luego sus lecturas. Así se
evitan ascensos de bloqueo compartido a exclusivo en un orden contrario al flujo
de escritura. Las escrituras permanecen íntegramente en `ProfileService` y BACK-04.

## Privacidad

- Visibilidad personal y profesional independientes, sin hacer público el perfil
  por estar verificado ni requerir insignia para elegir presentación pública.
- El nombre personal privado no se reemplaza públicamente por el nombre de cuenta.
  Se conserva la denominación genérica del contrato anterior.
- Contacto solo conforme al opt-in vigente. La cédula y las evidencias son privadas.
- Perfil indica solo si falta declarar cédula; no muestra el número ni documentos.
- La insignia representa revisión humana interna vigente, sin validación oficial
  automática, garantía de calidad ni promesa de aprobación o plazo.

## Especialidades — 09E1-04

Se eligió una intención explícita reconocible, en lugar de la segunda casilla:

1. **Guardar datos y aplicar selección** envía `update_specialties=1`. Sustituye la
   selección y permite vaciarla deliberadamente. Es la acción habitual del formulario.
2. **Guardar otros datos y conservar especialidades** envía `update_specialties=0`.
   Omite el reemplazo y conserva todas las asociaciones, incluidas las inactivas.

Se diferencian las opciones activas disponibles de las inactivas registradas,
identificando estas últimas por nombre. Si aplicar la selección retira alguna
inactiva, el servidor exige revisión y confirmación incluso sin verificación vigente.
Cancelar no modifica asociaciones. No se permite añadir IDs inactivos o inexistentes.

Se conserva la garantía de `update_specialties`: ausencia significa conservar;
intención verdadera sin casillas significa selección vacía. El botón externo
«Guardar primero mis cambios» de ubicación conserva especialidades al no enviarla.
La recuperación muestra la selección y la intención efectiva. Después de errores,
la primera acción respeta también la intención de conservar, tanto si old input
contiene el booleano `false` como la cadena válida `"0"` de la capa de dominio.

Limitación explícita del contrato actual: aplicar una selección solo de activas
reemplaza también las inactivas, previa confirmación. Para conservarlas se debe
guardar los otros datos sin reemplazar asociaciones. Permitir combinar nuevas
activas con inactivas retenidas requeriría ampliar el contrato del dominio; no se
introdujo esa ampliación ni se eliminan inactivas silenciosamente.

## Confirmación material — 09E1-06

El editor profesional envía una revisión POST a la única ruta nueva:
`profile.professional.prepare` (`/perfil/profesional/revisar`). Usa autenticación,
cuenta activa, CSRF, el Form Request estricto y el limitador de escritura existentes.
No crea una API paralela de verificación.

`ProfessionalEditor` coordina HTTP bajo bloqueo de cuenta y perfil. Recibe los
datos validados, aplica la intención de especialidades y conserva la cédula cuando
el campo de reemplazo está vacío. Compara un clon del modelo mediante `isDirty`
con `VerificationIntegrity::MATERIAL_FIELDS`; compara además los IDs efectivos.
Así usa la misma equivalencia de casts del modelo que el servicio, incluida una
cédula sin cambio real, números, nulos y modalidades. No copia reglas a JavaScript.

- Sin cambios sensibles que confirmar: el POST guarda una vez mediante el servicio
  existente y vuelve a Perfil. Contacto, visibilidad y un guardado sin cambios no
  invalidan ni exigen confirmación de verificación.
- Con cambios materiales sobre estado `verified` o `pending`: la revisión no escribe.
  Devuelve únicamente categorías y el efecto correspondiente al estado actual.
- Si se retiran inactivas, lo indica como categoría y exige confirmación deliberada.
- El aviso distingue invalidar una verificación vigente, invalidar una solicitud
  presentada o retirar inactivas sin atribuir una verificación a quien no la tiene.
- El PUT final ejecuta una vez la propuesta confirmada. La invalidación y su evento
  transaccional siguen siendo responsabilidad del servicio existente.

### Integridad, versiones y repetición

Los formularios nuevos envían siempre una versión HMAC ligada al actor y a los
atributos actuales del perfil, asociaciones y estado del expediente más reciente.
Los catálogos asociados incluyen su actividad. Los expedientes cerrados son
inmutables por el contrato vigente; se consulta solo la última fila, sin cargar
todo el historial para generar un token. Una nueva solicitud también cambia la
versión aunque no cambien los campos del perfil.

La propuesta se cifra y autentica con Laravel Crypt. Contiene propósito, actor,
HMAC de la sesión nativa, caducidad de cinco minutos, versión y datos validados.
No se muestran cédula, contacto ni otros valores de la propuesta en la confirmación:
el formulario lleva únicamente el sobre cifrado, CSRF, PUT e intención de confirmar.
No se guardan propuestas en tablas, localStorage, URLs o logs.

El sobre tiene un límite de 256 KiB para admitir los campos válidos incluso con
descripciones Unicode de 10,000 caracteres y el crecimiento del cifrado/base64.
La serialización evita escapes Unicode innecesarios. No se amplían los límites
de los datos profesionales. Una regresión confirma el guardado íntegro de la
descripción máxima de caracteres de cuatro bytes a través de revisión y PUT.

Se comprueban de nuevo cuenta, autorización, sesión, vigencia y versión bajo los
mismos bloqueos antes de invocar el servicio. Este vuelve a validar los datos y
especialidades vigentes. Un estado obsoleto, otra identidad/sesión o un sobre
manipulado se rechazan; no se aceptan campos adicionales para alterar la propuesta.
Una escritura material cambia la versión e impide repetir su confirmación. El
cliente también deduplica la revisión y el envío final; no hay reintentos automáticos.

Un PUT directo no puede omitir la confirmación de una edición material vigente ni
de una retirada de inactivas. Por compatibilidad, el PUT anterior conserva sus
actualizaciones no sensibles sin versión si el cliente antiguo no la aporta;
los formularios nuevos siempre usan revisión con versión. No se presenta esa
compatibilidad como un API general de edición optimista.

Ante un fallo de validación del dominio después de confirmar, la transacción se
revierte. Se recuperan datos no sensibles y la intención efectiva, excluyendo
cédula y propuesta cifrada; no se invalida la verificación por un guardado fallido.
Los conflictos de versión requieren abrir los datos actuales, sin actualizar
silenciosamente la versión de un borrador obsoleto.

### Con y sin JavaScript

Con JavaScript, una revisión del servidor abre el diálogo nativo de 09D. Las
categorías y el aviso se insertan como texto. Cancelar o Escape conservan el
borrador y devuelven foco al botón que inició la revisión. La edición posterior
descarta la propuesta anterior. Turbo elimina listeners, aborta la revisión
pendiente y descarta respuestas tardías antes de montar el nuevo formulario.

Sin scripts, el POST muestra una página de confirmación accesible. Confirmar
realiza un único PUT nativo con CSRF. Cancelar abre los datos guardados sin aplicar
la propuesta; la página explica que no conservará esa propuesta. Si no hace falta
confirmación, el POST guarda directamente y no introduce un segundo paso.

La carga dinámica de municipios de 09E.2-A no cambia. Si bloquea un envío pendiente,
el módulo profesional no inicia una revisión. Descripción y selección sobreviven
a un cambio de catálogo; la ruta JSON sigue recibiendo solo `state_id`.

## Inicio y rendimiento

Se priorizó Perfil y se pospuso el resumen de Inicio, como permite el alcance.
Añadirlo allí requeriría consultar estado profesional y expedientes en cada visita
o introducir otra proyección. Inicio conserva sus enlaces existentes y no adquiere
consultas adicionales; no se creó un segundo conjunto de reglas de verificación.

Comparación en el laboratorio HTTP con controlador y plantillas originales de HEAD
cargados desde archivos externos, mismos fixtures, sin cambiar el repositorio:

| Lectura | Consultas HEAD | Consultas nuevas | HTML HEAD → nuevo |
| --- | --- | --- | --- |
| Perfil convencional | 13–16 | 15 | 16,854 → 17,633 bytes antes de quitar el enlace repetido |
| Perfil con verificación | 19 | 25 | 17,667 → 18,437 bytes |
| Inicio, convencional y profesional | 11 | 11 | 26,637 → 26,637 bytes |

Las seis consultas adicionales de Perfil profesional reutilizan el workflow
existente con paginación acotada a 15 solicitudes; no hay consultas por cada fila,
documentos descargados ni polling. La versión del editor consulta un solo expediente
y las asociaciones del perfil, acotadas por el contrato actual. No se optimiza MySQL
o Vite de forma general en este lote.

Medianas locales de las dos últimas de tres lecturas por caso: Perfil convencional
78.81 → 147.72 ms; Perfil verificado 75.05 → 155.83 ms; Inicio convencional
98.41 → 160.10 ms y profesional 91.47 → 160.87 ms. El mismo Inicio conserva SQL y
HTML pero también varía en tiempo: estas pocas muestras incluyen instrumentación,
carga de vistas y variación del laboratorio; no prueban una regresión causal de
latencia de Inicio ni constituyen un benchmark de producción.

Bundle principal: 136,827 → 139,186 bytes (**+2,359 bytes**); gzip nivel 9:
48,143 → 48,795 (**+652 bytes**). La regla de reflujo añade 39 bytes al CSS
profesional (+6 gzip). La generación de utilidades añade 44 bytes a cada entrada
app/header/login (+10 gzip cada una). Sin dependencias, polling, widgets periódicos
ni rutas nuevas de lectura. Se mantienen 100 módulos transformados en el build.

## Pruebas y QA

Se añaden pruebas HTTP/Blade reales en `ProfessionalManagementIntegrationTest`,
con fixtures sintéticos, transacciones revertidas y controlador/middleware reales.
Cubren los diez estados, privacidad independiente, próximos pasos autorizados,
campos materiales, contacto, no-op, cédula idéntica, rechazo de PUT sin confirmación,
cancelación, datos cifrados, sesión/cuenta, CSRF, expiración, manipulación, repetición,
edición intermedia, nuevo expediente, catálogos inactivos y recuperación tras fallo.

Se mantienen las regresiones previas de Perfil, ubicación y validación del dominio.
Las pruebas que buscaban la casilla anterior ahora comprueban acciones e intención.
La prueba de rollback del servicio pasa por la confirmación antes de inducir el
fallo, para seguir comprobando la reversión real y no una denegación anticipada.
La intención de conservar se prueba en ambas capas de validación.

Frontend: diez pruebas nuevas para revisión única, intención del submitter,
confirmación final única, cancelación, foco, invalidación de propuesta al editar,
fallback sin diálogo, coordinación con ubicación, errores escapados, conflicto,
respuesta ilegible, Turbo y respuestas tardías.

QA real en navegador, con sesión y CSRF nativos:

- Veinte vistas de los diez estados, en ambos temas, repartidas entre 320, 390,
  768, 1024 y 1440 px. Sin desbordamiento horizontal ni IDs duplicados.
- Diálogo en los cinco anchos y ambos temas: categorías de descripción,
  especialidades y retirada de inactivas, foco inicial en Cancelar y Escape con
  retorno al botón original. Navegación Tab y Shift+Tab entre las acciones.
- Diez confirmaciones nativas sin scripts, cinco anchos y ambos temas. Se verificó
  cero scripts de producto en el DOM; el motor de inspección siguió disponible.
  Guardado confirmado real a 320 px y regreso a estado invalidado.
- Cambios de municipio conservando borrador y especialidades, sin revisión duplicada.
- Regreso con Turbo: un POST de revisión y cero PUT al cancelar.
- Borrador accesible desde Perfil incluso con `?page=99`, mediante su ruta real.
- Presentación pública con perfil personal privado conserva el nombre genérico.
- Texto al 200 % y errores de 732 caracteres, incluida una palabra larga, a 320 px
  en ambos temas. `overflow-wrap` se limita al editor, sin rediseñar otras pantallas.

Las capturas iniciales del diálogo podían mostrar la animación en progreso; se
comprobó también su superficie opaca una vez asentada. La barra inferior fija
puede aparecer a mitad de una captura de página completa por la posición del
viewport, sin que eso constituya un desbordamiento del documento.

Incidencias del laboratorio: se ajustó la continuidad externa de fixtures para
reconocer el método PUT simulado por Laravel y reflejar el guardado tras el rollback.
Tras una pausa se recibió un 419 y, al recargar, se exigió login; se reautenticó
mediante el flujo nativo y se repitió la comprobación, sin omitir CSRF. PHPUnit
transporta explícitamente cookies en las peticiones JSON para comprobar la sesión
real de las confirmaciones, en vez de relajar su vínculo con el ID de sesión.

Las matrices y capturas se guardan fuera del repositorio. Se repitió el aviso final
en los cinco anchos y ambos temas: superficie opaca, sin desbordamiento, con texto
específico para verificación vigente. También se comprobaron los avisos de solicitud
pendiente y retirada de inactivas sin atribuir una verificación inexistente, y la
confirmación nativa final sin scripts. El servidor y las pestañas temporales se
cerraron, se ejecutó logout de la cuenta sintética y se restauró el viewport.

## Aislamiento y conservación

Antes de fixtures, dentro del proceso PHPUnit y de HTTP, se verificaron entorno
testing, driver MySQL, base configurada y efectiva `plataforma_legal_v2_test`, caché
y sesiones aisladas, y almacenamiento privado externo. El preflight HTTP de solo
lectura pasó con SQL READ ONLY. El TestCase repite el guard de MySQL antes de fixtures.

PHPUnit usa sesiones/caché array. El navegador usa caché array, sesiones en archivos
externos y cookie exclusiva `qa09e2c_session`. La conexión efectiva se comprueba
antes de las escrituras de cada petición. Sus fixtures quedan dentro de una
transacción que siempre se revierte. Para reflejar el resultado entre peticiones
de QA se conserva solo el estado de esos fixtures sintéticos en un archivo externo;
no se crean expedientes, documentos ni cuentas persistentes en MySQL.

No se usan expedientes ni archivos reales en QA. Comparación final:

- Desarrollo: las 51 estructuras coinciden. Todas las cantidades y hashes de datos
  coinciden salvo la actividad anónima de `sessions` descrita abajo. Cuentas,
  roles, permisos, contenidos, expedientes y eventos permanecen iguales.
- Testing: las 51 tablas conservan sus cantidades y hashes de registros, sin
  fixtures persistidos. Las estructuras coinciden; avanzaron contadores
  AUTO_INCREMENT de 26 tablas por fixtures revertidos. Se comprobó por reconstrucción
  de DDL solo en memoria, sin ejecutar ALTER ni restaurar contadores.
- Las 33 migraciones aplicadas se conservan; no se crea ni ejecuta ninguna.
- `.env`, `.env.testing` y `public/hot` conservan sus hashes.
- El conjunto de adjuntos privados conserva hash, tamaño y fecha de modificación.
- Los otros dos worktrees conservan HEAD y sus estados originales, sin operaciones
  de escritura sobre ellos.

No se ejecutan seeders de desarrollo, aprovisionamiento, concesiones reales,
correo o workers. No se restauran sesiones ni contadores.

### Actividad anónima observada en desarrollo

`sessions` pasó de seis a siete filas. Una lectura de binlog filtrada en memoria,
sin persistir IDs reales, payloads, IP ni agentes de usuario, identificó 22 eventos
entre 2026-11-19 19:24:30 UTC y 22:27:11 UTC: **21 UPDATE exclusivamente de
`last_activity`, todos con payload idéntico, y un INSERT de sesión anónima**.
No hubo eventos autenticados ni cambios en otras tablas de desarrollo dentro de
ese intervalo. El log inspeccionado fue `LACHEPOCOMPU-bin.000045`.

No se identificó autor ni proceso de origen, y no se infiere un logout ni se
atribuyen los eventos a las pruebas o a una persona. Los procesos de pruebas y QA
acreditan testing y aislamiento de cookies/almacenamiento. No se modificaron ni
restauraron esas sesiones. Por ello no se afirma que la huella completa de
desarrollo fuera idéntica, aunque sí se conservaron sus datos funcionales.

## Limitaciones y decisiones

- Inicio se pospone dentro del hallazgo 09E1-05: Perfil es el punto central funcional.
- No se permite mezclar una sustitución por activas con inactivas retenidas sin
  ampliar el contrato del dominio. La interfaz ofrece conservarlas o reemplazar
  deliberadamente, confirmando su retirada.
- Sin scripts, cancelar la revisión vuelve a los datos guardados y descarta la
  propuesta, según el aviso; no persiste un borrador privado en almacenamiento nuevo.
- Las confirmaciones caducan a los cinco minutos y cualquier cambio de versión
  obliga a revisar de nuevo. No se hacen reintentos automáticos de escrituras.
- La protección optimista se aplica a los formularios nuevos y a cualquier PUT
  que aporte versión. El contrato legado no sensible sin versión se conserva.
- El resumen presenta orientación, no habilita presentar un expediente incompleto
  ni sustituye políticas, requisitos documentales o decisiones humanas del dominio.
- SQL directo puede saltarse los invariantes del dominio, limitación ya documentada.
  La proyección no convierte historia incoherente en una insignia pública.
- Se conserva la deuda Pint global ajena; solo se comprueban los PHP afectados.

## Inventario exacto

**18 archivos: 11 modificados y 7 nuevos; ninguno eliminado.**

Modificados:

1. `app/Http/Controllers/Profiles/ProfileController.php`
2. `app/Http/Requests/Profiles/SaveProfessionalProfileRequest.php`
3. `app/Services/Verification/VerificationWorkflow.php` (solo paginación de lectura)
4. `resources/css/professionals.css`
5. `resources/js/personal.js`
6. `resources/views/pages/profile/edit.blade.php`
7. `resources/views/pages/profile/show.blade.php`
8. `routes/web.php`
9. `tests/Feature/IntegrationAuditFixesTest.php`
10. `tests/Feature/ProfileIntegrationTest.php`
11. `tests/Feature/ProfileLocationIntegrationTest.php`

Nuevos:

1. `app/Services/Profiles/ProfessionalEditor.php`
2. `app/Services/Profiles/ProfessionalHub.php`
3. `resources/js/profiles/professional.js`
4. `resources/views/pages/profile/confirm-professional.blade.php`
5. `tests/Feature/ProfessionalManagementIntegrationTest.php`
6. `tests/frontend/professional-editor.test.mjs`
7. `docs/ux-ui-integration-09e2-c.md`

## Corrección de recuperación de especialidades vacías

La revisión de cierre reprodujo una regresión del formulario nativo: enviar
`update_specialties=1` sin ninguna casilla marcada omite por completo la clave
`specialty_ids`. Si otro campo fallaba, el POST `profile.professional.prepare`
no incorporaba esa clave a old input y Blade recuperaba las asociaciones guardadas.
El mismo Form Request atiende ambas rutas; no había una diferencia deliberada de
contrato. La normalización heredada en `ProfileFormRequest` reconocía únicamente
`profile.professional.update`.

Se añadió `prepareForValidation` al Form Request profesional. Primero ejecuta la
preparación heredada y, exclusivamente para el POST de revisión con intención de
aplicar y clave ausente, incorpora `specialty_ids=[]` antes de validar. Así el
error conserva una selección vacía explícita. No se modifica el Form Request base,
las reglas, `ProfileService`, las autorizaciones, los campos materiales ni los
controles de sesión, versión, cifrado o invalidación. Conservar no fabrica una
selección; una selección no vacía mantiene su validación anterior.

La reproducción externa que bloqueó el cierre se ejecutó antes y después:

- Antes: **1 fallo, 12 assertions**; se recuperaba una casilla y el reenvío
  conservaba una asociación contra la intención de vaciar.
- Después: **1 prueba aprobada, 13 assertions**; old input contiene el array vacío,
  se recuperan cero casillas y el reenvío corregido deja cero asociaciones.

Dentro de `ProfessionalManagementIntegrationTest` se agregaron **cinco casos
permanentes**: selección vacía con error ajeno en el POST y PUT legado, perfil
verificado, asociación inactiva y conservación explícita de una asociación
inactiva tras un error. Ejecutan HTTP, CSRF y Blade real; inspeccionan DOM e
intención y reenvían el formulario. Comprueban además cédula excluida de old input,
ausencia de escrituras durante el error, confirmación obligatoria cuando procede,
rechazo del PUT sin confirmar y una única invalidación efectiva. Se mantienen
las pruebas existentes de selección no vacía, IDs inválidos/inactivos y los lotes
09E.2-A y 09E.2-B.

QA de esta corrección, con fixtures sintéticos transaccionales:

- Sin scripts de producto: selección vacía, error de URL, redirección con cero
  casillas e intención de aplicar conservada; corregir y reenviar deja cero
  asociaciones. También se recuperó y guardó una selección activa alternativa.
- Sin scripts, perfil verificado con asociación inactiva: tras recuperar el vacío,
  la revisión exige confirmar el cambio material y la retirada de inactivas.
  Solo el PUT confirmado retira asociaciones e invalida el expediente una vez.
- Con scripts: el 422 conserva las casillas vacías y el reenvío corregido guarda;
  conservar mantiene las asociaciones activa e inactiva tras un error. La
  navegación Turbo entre perfil y editor mantiene los controles funcionales.
- Escritorio 1440×1000 y móvil 390×900, temas claro y oscuro; envío con Enter,
  Escape en el diálogo y retorno de foco al botón de aplicar, sin perder el vacío.
  No se modificaron JS, CSS ni Blade en esta corrección; el build conserva los
  hashes de todos los bundles respecto al inicio de esta intervención.

El preflight de esta intervención volvió a verificar dentro de PHPUnit y HTTP
APP_ENV testing, MySQL configurado y efectivo `plataforma_legal_v2_test`, caché
array y almacenamiento externo. PHPUnit usa sesiones array; el navegador usa
sesiones externas y cookie exclusiva `qa09e2cfix_session`. Los fixtures HTTP se
revirtieron por petición; el estado usado para mostrar el resultado entre
peticiones existe únicamente en archivos sintéticos externos. Se cerraron la
sesión, pestaña y servidor propios, y se restauró el viewport.

La comparación de datos posterior conserva las 51 estructuras de desarrollo,
todas sus cantidades y los hashes funcionales, incluidos cuentas, roles, permisos,
expedientes y eventos. La única variación es un UPDATE anónimo de `sessions` que
cambia exclusivamente `last_activity`, con payload idéntico y sin altas o bajas:
lectura de binlog en memoria del intervalo 2026-11-19 22:47:06 UTC a 22:58:50 UTC
(extremo final excluido). No se identifica ni atribuye autor o proceso de origen.
Esta observación es adicional al intervalo previo documentado arriba; no se
restauraron sesiones ni se afirma identidad total de la huella de desarrollo.

Testing conserva cantidades y hashes de registros de las 51 tablas, sin fixtures
persistidos. Solo avanzan los AUTO_INCREMENT de 26 tablas; el resto del DDL
coincide, comprobado en memoria sin alterar contadores. Se conservan las 33
migraciones, las configuraciones, hashes/tamaños/fechas de adjuntos privados y
HEAD/estado de los otros worktrees.

Esta corrección toca únicamente tres archivos del inventario existente: el Form
Request profesional, el test de gestión profesional y este documento. El
inventario sigue siendo **18 archivos: 11 modificados y 7 nuevos**, sin eliminados
ni incorporaciones adicionales. No se amplía el contrato de especialidades.

## Cierre de validación

Resultados finales del 19 de noviembre de 2026:

- Laravel focalizado (Perfil, ubicación, verificación, auditoría, gestión y
  contexto de autenticación): **200 pruebas, 2,773 assertions**, 53.01 s.
- Laravel completo: **790 pruebas, 16,627 assertions**, 165.43 s.
- Frontend completo: **79 pruebas**, incluidas diez nuevas del editor profesional.
- Build Vite: aprobado.
- Pint de los nueve PHP afectados del inventario: aprobado; no se afirma aprobación global de
  los archivos de deuda ajena.
- Sintaxis: **367 archivos PHP/Blade sin errores**; además se compilaron las tres
  vistas Blade afectadas y el PHP resultante pasó lint.
- Rutas: **110**, con 57 registros GET/HEAD y sin nombres o pares método/URI
  duplicados. La única incorporación es el POST de revisión.
- Whitespace de los once modificados y siete nuevos, y `git diff --check`: aprobado.

Se conservaron las regresiones de 09E.2-A y 09E.2-B en la suite completa. Las
regresiones nuevas comprueban también que la recuperación no cambie de conservar
a reemplazar especialidades tras un error del dominio y que la paginación de la
URL no oculte el expediente del centro profesional.

Git: `main`, HEAD `42ae250dec6ebdc5ddf01c791b9c875005b2756d`, staging vacío,
cero commits por delante/detrás de `origin/main`. Solo están pendientes los
18 archivos del inventario. No se realizó staging, commit, push ni merge.
No se inicia 09E.2-D, 09F, 09P ni LEXA real.
