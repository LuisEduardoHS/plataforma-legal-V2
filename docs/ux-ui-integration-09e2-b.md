# 09E.2-B — Conservar el contexto después de iniciar sesión

## Alcance y estado

Implementación exclusiva del hallazgo 09E1-03, sobre `main`, HEAD de partida
`15ce4265c9d7f5e567cda152ffd60a3c2ce74b16`. Inspección inicial: árbol limpio,
staging vacío y cero commits por delante/detrás de `origin/main`.

Implementado y validado el 19 de noviembre de 2026. Pendiente de revisión del
usuario: no se realizó staging, commit ni push. No se inició otro lote.

## Defecto y contrato anterior

Los componentes públicos de Contactar y Guardar enlazaban al login sin contexto.
Login y registro usaban `redirect()->intended('/inicio')`. Laravel capturaba un
`url.intended` cuando su middleware rechazaba una lectura protegida, como LEXA,
pero visitar voluntariamente el login desde contenido público no lo establecía.
Por eso se perdía la lectura y se terminaba normalmente en Inicio.

Se inspeccionaron ambos controladores, middleware de cuenta activa y sesión,
formularios estrictos, rutas, políticas y servicios actuales antes del cambio.

## Solución

`ReadingReturn` adapta exclusivamente la elección de la redirección posterior a
la autenticación nativa. Los componentes de invitado generan un enlace firmado
temporal hacia el login, con tipo de lectura e identificador público. No admiten
una URL de retorno libre.

| Tipo | Destino de lectura | Recuperación si deja de ser público |
| --- | --- | --- |
| `professional` | `professionals.show` | `professionals` |
| `user` | `profiles.show` | `professionals` |
| `post` | `community.show` | `community` |
| `article` | `articles.show` | `articles` |
| `resource` | `resources.show` | `resources` |

No hay un listado general de usuarios públicos: su recuperación utiliza el
directorio existente, sin añadir rutas. Las tarjetas del directorio y de los
tres catálogos apuntan a su detalle concreto, igual que sus vistas individuales.

El GET del login verifica la firma y guarda únicamente `{type, id, expires}` en
sesión. El enlace incluye una etiqueta aleatoria de flujo de invitado, vinculada
a esa sesión; no es el ID de sesión ni una credencial de autenticación. La
duración es de diez minutos desde la generación del enlace. Cambiar entre login
y registro o fallar las credenciales no amplía ese plazo.

Después de autenticar o registrar se consumen el contexto, la etiqueta de flujo
y el intended. Los enlaces firmados antiguos no pueden reactivar el contexto
después de autenticar, salir o cambiar de sesión. Un enlace inválido no sustituye
un contexto válido anterior. Entre dos selecciones públicas válidas prevalece
la última; no hay una cola de acciones.

Login y registro conservan sus formularios POST, CSRF, validación, regeneración
de sesión y reglas de cuenta. El registro conserva exclusivamente el rol `user`
existente y no concede capacidades operativas, editoriales, de moderación ni
verificación. No se modificaron las políticas ni los servicios del dominio.

## Precedencia y seguridad

1. `url.intended` nativo, si corresponde a una ruta GET local registrada y segura.
2. Contexto público firmado, vigente y perteneciente al flujo de invitado actual.
3. Inicio cuando no hay ninguno válido.

Capturar una lectura pública nunca sobrescribe `url.intended`. Si este gana, el
contexto público también se consume. Se conservan sus filtros internos legítimos
(por ejemplo, Mensajes archivados), pero no se admite un origen externo.

El intended se comprueba como cadena local del mismo origen, sin credenciales
en URL, fragmentos, controles, barras invertidas, destinos protocol-relative ni
esquemas ajenos a HTTP/HTTPS. Debe resolver una ruta GET registrada. Se descartan
login, registro, logout, recuperación de contraseña y rutas exclusivamente de
mutación, evitando ciclos. La autorización de las rutas protegidas sigue en su
middleware/controlador vigente; el adaptador no reconstruye credenciales ni
reproduce peticiones anteriores.

El nuevo contexto solo acepta los cinco tipos cerrados, IDs positivos canónicos,
firma relativa válida y caducidad. Parámetros añadidos o manipulados invalidan la
firma. No contiene correo, contraseña, cédula, mensajes, adjuntos ni datos de
formularios. No utiliza localStorage, nuevas tablas, dependencias o permisos.

Antes de redirigir se comprueba otra vez la disponibilidad pública actual:
perfil público y cuenta activa; o contenido publicado, autor activo y política
`view` para la identidad recién autenticada. Incluso el propietario u operador
debe cumplir el carácter público del contexto original. Se consultan solo los
campos necesarios. El controlador del GET final vuelve a aplicar su contrato
actual. Un destino eliminado, oculto o con autor inactivo lleva al listado con
un aviso genérico, sin incluir el identificador ni describir contenido privado.

## Experiencia y acciones voluntarias

Login y registro indican que se intentará volver al contenido y que Guardar o
Contactar todavía no se han realizado. Ese mensaje no promete un retorno público
cuando ya tiene prioridad un intended protegido. La recuperación al listado usa
un aviso escapado con `role="status"`.

Al volver al detalle se presentan las acciones vigentes. No se crea ningún
guardado ni conversación automáticamente. Cualquier acción posterior mantiene
su POST/PUT/DELETE, CSRF y autorización actual. Se comprobó que un cambio de
visibilidad o suspensión posterior impide guardar o contactar.

## Aislamiento y conservación

Antes de fixtures se realizó un GET HTTP de solo lectura dentro del propio
proceso PHPUnit, verificando `APP_ENV=testing`, MySQL configurado y efectivo
`plataforma_legal_v2_test`, 33 migraciones, `operations_events`, sesiones/caché
`array` y almacenamiento privado externo al repositorio. La consulta efectiva
fue `SELECT DATABASE()`; no se dedujo el aislamiento del nombre del entorno.
El `Tests\TestCase` existente también verifica MySQL antes de cada fixture.

El laboratorio de navegador hizo un preflight HTTP equivalente antes de
escrituras, con sesiones en archivos externos y cookie exclusiva
`qa09e2b_session`, caché `array`, correo `array` y almacenamiento externo. Usó
autenticación y CSRF reales, sin `actingAs` ni reemplazar el usuario autenticado.
Los fixtures sintéticos de cada petición quedaron dentro de una transacción
revertida. El laboratorio bloqueó mutaciones de dominio y el POST de registro;
el registro completo de los cinco destinos se probó por HTTP en PHPUnit.

Huella anterior/posterior: las 51 tablas de testing conservan cantidad y hash de
sus registros. Las estructuras son iguales; avanzaron contadores AUTO_INCREMENT
de 26 tablas por fixtures revertidos, efecto normal no transaccional de MySQL.
No se restauraron ni reiniciaron contadores. En desarrollo, las 51 estructuras
y todos los datos salvo la actividad de sesión descrita abajo se conservaron.
Roles, permisos, cuentas, contenidos y eventos permanecen iguales.

Se preservaron `.env`, `.env.testing`, `public/hot` y el adjunto privado existente:
mismo hash, tamaño y fecha de modificación. No se leyeron ni copiaron mensajes
privados reales. Los otros dos worktrees conservan sus HEAD y estados previos;
no se ejecutaron acciones de modificación en ellos.

### Variación observada de sesión en desarrollo

La tabla `sessions` conservó seis filas, pero cambió su huella. Una lectura del
binlog, filtrada en memoria y sin guardar IDs, payloads, IP ni agentes de usuario,
identificó dos UPDATE de `last_activity` sobre una misma sesión anónima:
18:34:56 UTC y 18:42:59 UTC del 19 de noviembre. En ambos casos el payload fue
idéntico y la sesión siguió anónima. No hubo INSERT/DELETE ni otros eventos de
datos de desarrollo en el intervalo inspeccionado (18:31:19–18:54:43 UTC).

No se identificó al autor de esas peticiones. No se atribuyen a una persona ni
se afirma que hubo un logout. Los procesos de prueba y el laboratorio acreditan
testing; su cookie y almacenamiento están separados. No se modificaron ni
restauraron esas sesiones. Esta observación impide afirmar que la huella completa
de desarrollo permaneció idéntica, aunque sí se conservaron sus datos funcionales.

## Pruebas y validación

Nueva suite `AuthenticationContextIntegrationTest`, con solicitudes HTTP y
renderizado reales de controladores, middleware y Blade. Todos los fixtures se
revierten. No se basa únicamente en buscar cadenas de archivos fuente.

| Regresión | Cobertura |
| --- | --- |
| Login desde profesional, usuario, publicación, artículo y recurso | Cinco destinos y GET final autorizado |
| Registro desde los cinco contextos | Regreso, sesión y privilegios de cuenta nueva |
| Tarjetas y detalle | Destino específico de cada contenido |
| Login directo | Inicio sin contexto |
| LEXA e intended anterior | Captura real del middleware, prioridad y consumo |
| Filtros del intended protegido | Mensajes archivados preservado |
| Dos contextos públicos | Prevalece la última selección válida |
| Credenciales fallidas y cambio login/registro | Contexto conservado sin extender vigencia |
| Caducidad y enlaces manipulados | Rechazo y recuperación segura |
| URLs inseguras | 17 variantes, incluidos esquemas, origen, rutas de mutación y bucles |
| Contenido eliminado, oculto o autor inactivo | Quince combinaciones con aviso genérico |
| Cambio de identidad y logout | Consumo y rechazo de enlaces de flujo anterior |
| Cuenta inactiva | Autenticación rechazada, sin ejecutar acciones |
| CSRF exigido | POST sin token 419; POST nativo con token funciona |
| Guardado y conversación | Ninguna creación automática; denegación con permisos actuales |
| Sin JavaScript | Formularios nativos y ningún retorno oculto aportado por el cliente |
| Coste de capturar contexto | Cero consultas SQL en el GET de login |

Resultados finales:

- Laravel focalizado: **56 pruebas, 747 assertions**, 9.25 s.
- Laravel completo: **755 pruebas, 16,084 assertions**, 156.17 s.
- Frontend completo: **69 pruebas aprobadas**.
- Build Vite: aprobado, 99 módulos.
- Pint de los cuatro archivos PHP afectados: aprobado mediante `--test`.
- Sintaxis: **363 archivos PHP/Blade sin errores**.
- Registro real de rutas: **109**, sin nombres ni pares método/URI duplicados.
- Whitespace de versionados y tres nuevos, y `git diff --check`: aprobados.

No se formatearon los 14 archivos de deuda Pint global preexistente; este lote
no afirma que Pint global esté aprobado.

## QA de navegador

Navegador real con servidor HTTP externo apuntando exclusivamente a testing:

- Escritorio: profesional → login → mismo perfil, con Enviar mensaje disponible.
- Escritorio: usuario público → login → mismo perfil de usuario.
- Móvil 390 × 844: publicación → login → mismo detalle, Guardar sin activar.
- Móvil: artículo → login → mismo artículo, sin scripts del producto.
- Escritorio: recurso → login → mismo recurso, sin scripts del producto.
- Navegación entre login y registro, aviso conservado y regreso del navegador.
- Turbo: entrada al login desde contenido, navegación posterior y botón Atrás;
  la publicación se restaura y Guardar continúa sin activar.
- LEXA protegida seguida de un contexto público: LEXA conserva la prioridad.
- Logout POST real al terminar cada escenario; no se pulsó Guardar ni Contactar.

La modalidad sin JavaScript se obtuvo retirando las etiquetas script de las
respuestas del laboratorio y verificando cero scripts en el DOM; no se modificó
una preferencia global del navegador. El motor de inspección del navegador siguió
disponible. Formularios y enlaces fueron los del producto. En móvil no se observó
desbordamiento horizontal (390 px de contenido y viewport).

Incidencia solo del laboratorio: su primer hash sintético tenía coste distinto al
configurado. Laravel lo recalculaba durante el login, y el rollback producía una
discrepancia de sello de sesión en el siguiente GET. Se corrigió exclusivamente el
fixture externo con un hash compatible y se repitieron los flujos satisfactoriamente.
No se cambió ni deshabilitó el control de sesiones del producto.

Se guardaron capturas y métricas fuera del repositorio. El servidor temporal se
detuvo, la pestaña se cerró y el viewport se restauró. Los 54 registros de métricas
del laboratorio mostraron cero guardados y cero conversaciones. No se registraron
credenciales, cuerpos de formularios ni valores de firma/etiqueta de flujo.

## Rendimiento

- Selección del enlace y GET de login: sin redirección adicional y cero SQL para
  capturar el contexto. El HMAC por enlace no consulta la base de datos.
- POST de autenticación satisfactorio: una redirección 302 y GET final 200.
  No se observaron ciclos en los flujos corregidos.
- Revalidar un perfil añade una consulta `exists`; revalidar contenido añade dos
  consultas (entrada pública y autor). En el laboratorio, login con intended LEXA
  ejecutó dos consultas; con perfil tres y con contenido cuatro. Se excluye de
  esa comparación el primer ensayo con rehash del fixture.
- Capturar y comprobar un intended usa el registro de rutas en memoria, sin SQL.
- Los 24 archivos CSS/JS del manifiesto conservan exactamente su tamaño bruto y
  gzip nivel 9 frente a la referencia: **cero incremento de bundles**. El módulo
  principal sigue en 136,827 bytes (48,143 gzip nivel 9).
- Sin JavaScript, dependencias, rutas ni listeners nuevos; el menú no cambia.

Los tiempos del servidor incluyen un laboratorio local con fixtures y compilación
de vistas; no constituyen un benchmark de producción. No se inició 09P.

## Limitaciones deliberadas

- Se conserva una lectura, no el intento de acción, formularios ni borradores.
- La sesión mantiene un contexto público pendiente compartido entre pestañas;
  la última selección válida reemplaza la anterior, con intended nativo prioritario.
- Un enlace caducado o de un flujo anterior requiere volver al contenido para
  obtener uno nuevo. No se reviven enlaces de la caché de otra sesión.
- La disponibilidad puede cambiar entre la comprobación y el GET final. El
  controlador vuelve a autorizar y puede devolver su respuesta habitual de error;
  no se debilitan sus políticas para forzar el retorno.
- Una cuenta autenticada inactiva o revocada conserva el tratamiento existente
  de invalidación de sesión. No se restauran destinos tras esa invalidación.
- El registro completo se validó por PHPUnit; en navegador se comprobó su
  navegación y continuidad del contexto, sin persistir una nueva cuenta.

## Inventario exacto

**11 archivos: 8 modificados y 3 nuevos; ninguno eliminado.**

Modificados:

1. `app/Http/Controllers/Auth/AuthenticatedSessionController.php`
2. `app/Http/Controllers/Auth/RegisteredUserController.php`
3. `resources/views/components/content/save.blade.php`
4. `resources/views/components/messages/start.blade.php`
5. `resources/views/layouts/app.blade.php`
6. `resources/views/pages/auth/login.blade.php`
7. `resources/views/pages/auth/register.blade.php`
8. `resources/views/pages/profile/public.blade.php`

Nuevos:

1. `app/Services/Auth/ReadingReturn.php`
2. `tests/Feature/AuthenticationContextIntegrationTest.php`
3. `docs/ux-ui-integration-09e2-b.md`

Sin cambios en rutas, migraciones, seeders, configuración, dependencias,
JavaScript, CSS, políticas, servicios de dominio ni archivos de otros lotes.
Git termina en `main`, con el mismo HEAD, staging vacío y 0/0 respecto a
`origin/main`; únicamente están pendientes los once archivos de este inventario.
