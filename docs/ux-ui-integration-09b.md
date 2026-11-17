# ETAPA 09B — Navegación de cuenta y Ayuda

## Alcance

Base: main, HEAD efc6ef67c808d2dc544ca5c51ce4d5352a25271a.
Atiende 09A-02 y 09A-03. Atiende parcialmente 09A-15 (contexto de cuenta y enlaces
de Ayuda) y 09A-21 (cuenta esencial sin JavaScript). No implementa Landing,
LEXA real, opciones futuras de Configuración ni cambios del dominio.

La ausencia móvil provenía del contenedor lg:flex hidden del único menú de
cuenta; el avatar móvil solo enlazaba a Perfil. La ayuda conservaba textos de
las antiguas demostraciones, aunque los módulos ya tenían integración real.

## Arquitectura y contrato

Se conserva x-header.account-menu, ahora respaldado por
App\View\Components\Header\AccountMenu. El componente calcula una sola lista de
destinos para todos los tamaños. No hay un menú alternativo móvil ni una ruta
administrativa nueva. Las políticas, servicios, controladores y rutas conservan
la autorización de cada operación.

El disparador es summary y el contenedor details nativo. Sin scripts se pueden
abrir los enlaces y enviar el formulario POST de salida con CSRF. No existen
x-cloak ni un panel condicionado a Alpine para esas funciones.

| Contexto | Opciones |
|---|---|
| Visitante | Iniciar sesión, Crear cuenta y Ayuda |
| Cuenta activa | Perfil, Guardados, Configuración, Ayuda y Cerrar sesión |
| Profesional | Las opciones de su cuenta; presentación y verificación propias siguen accesibles desde Perfil; ningún privilegio por ser profesional |
| Operador/moderador | Operaciones solo cuando OperationsAccess.enter lo permite; cada herramienta conserva su capacidad |
| Revisor | Revisión profesional con verification.review; no equivale a capacidad operativa o documental |
| Editor | Gestionar artículos/recursos por separado: create/manage según Gate, update.own/publish.own o existencia de contenido propio |
| Autor con permisos revocados | Conserva el enlace a su espacio propio si tiene contenido no eliminado; no recupera permisos de creación/edición por ver el enlace |
| Sesión no disponible | El middleware vigente invalida/redirige; un renderizado sin cuenta activa no expone accesos personales ni privilegiados |

OperationsAccess sigue leyendo concesiones efectivas y conservando la separación
operador/revisor. Un moderador puede entrar a moderación, sin que ello habilite
administración de cuentas. El nombre admin, los permisos históricos users.manage
o una presentación profesional no se traducen en capacidades nuevas.

Las rutas editoriales de gestión ya admiten la consulta propia de una cuenta
activa. La regla del menú es de descubrimiento: no introduce una autorización
adicional ni impide acceder por URL a un destino ya permitido por el contrato.

No se conceden permisos, no se aprovisionan operadores y no se muestran correos,
cédulas, evidencias ni conversaciones privadas en el menú. La identificación
visual usa una inicial, sin publicar la fotografía privada.

## Interacción, responsive y accesibilidad

- Un solo componente en el encabezado para 390, 768 y 1440 px.
- Orden HTML de foco acorde al contexto: navegación principal y LEXA antes de la
  cuenta en escritorio; cuenta antes del acceso móvil a LEXA.
- Detalles inicialmente cerrados; estado expanded nativo de summary, sin copiar
  ese estado en otro atributo o variable.
- Enter/Espacio/clic conservan el comportamiento nativo.
- Flechas arriba/abajo abren y recorren los enlaces; Home/End van a extremos.
- Escape cierra y restaura foco en summary.
- Tab sigue el orden HTML, sin atrapar foco; salir del componente lo cierra.
- Clic fuera cierra sin robar foco; seguir un enlace o enviar el formulario cierra
  sin cancelar la navegación o el POST.
- onPage registra listeners con AbortController y limpieza al reemplazar el DOM;
  turbo:before-cache y pageshow cierran estados abiertos.
- Navegación principal nombrada y enlace activo con aria-current.
- Panel con ancho acotado, scroll propio y altura que reserva espacio para la
  navegación inferior. Tokens de marca y estados claro/oscuro existentes.
- El menú es una navegación desplegable, no un diálogo ni un role=menu que imponga
  semántica de aplicación a enlaces y un formulario ordinarios.
- Los controles de búsqueda/filtro de Ayuda solo se muestran cuando su mejora JS
  se inicializa; sin scripts permanecen disponibles todas las preguntas mediante
  details/summary, sin filtros que aparenten funcionar.

## Ayuda actualizada

Se sustituyen las 12 preguntas antiguas por 23 preguntas verificadas contra las
integraciones vigentes, organizadas en primeros pasos, cuenta, perfiles,
Comunidad, Biblioteca, Mensajes, Verificación, LEXA y personal autorizado.

Las respuestas distinguen Disponible, Requiere configuración de entrega,
Demostración y Funcionalidad futura. Los enlaces a funciones con autenticación
indican ese requisito a visitantes y mantienen el destino protegido para que
el middleware gestione el acceso/intended. La pregunta sobre herramientas
autorizadas no publica enlaces administrativos indiscriminados.

Cambios principales:

- Registro e inicio de sesión son reales; redes sociales siguen pendientes.
- Cuenta en todos los tamaños, salida real y guía ante sesión caducada.
- Recuperación implementada; recibir correo exige transporte y worker operativos.
  No se promete entrega ni se confirma existencia de cuentas.
- Visibilidad personal/profesional independiente, contacto voluntario y foto privada.
- Recientes, Siguiendo y Mis publicaciones; no se inventa un filtro Sin respuesta.
- Artículos/recursos publicados, filtro por inicio del título y límites de enlaces.
- Guardados privados MySQL entre sesiones; retirar no elimina el original.
- Mensajes reales entre cuentas; Guardado no confirma entrega al dispositivo.
  Se aclara que no se ofrece cifrado de extremo a extremo.
- Verificación humana interna, sin validación oficial automatizada ni garantía
  de calidad; evidencia privada, retención y estado vigente.
- LEXA demo sin IA real ni historial persistente; también se corrigen sus tarjetas.
- Configuración describe opciones futuras deshabilitadas sin simular protección
  o persistencia.
- Operador, moderador, revisor y editor se distinguen por capacidades reales.

## Validaciones e aislamiento

Antes de fixtures, el proceso HTTP externo de QA verificó entorno testing,
driver MySQL, base configurada/efectiva plataforma_legal_v2_test, 33 migraciones
y operations_events existente. Caché/sesiones array, cookie exclusiva de QA y
almacenamiento externo al proyecto. El servidor solo permite GET/HEAD.

Los actores de navegador son fixtures SQL de testing dentro de una transacción
por petición, revertida al terminar. Se usan controladores y políticas reales;
no se simula autorización mediante la visibilidad del botón. Las concesiones
afectan exclusivamente esos actores efímeros; no hay operadores ni grants
persistidos. Descargas y escrituras de navegación están bloqueadas en ese servidor.

AccountNavigationIntegrationTest verifica nuevamente el aislamiento desde un
middleware de una petición HTTP real antes de crear cada fixture. No añade
rutas permanentes. Sus registros se revierten al terminar. La ejecución de
la suite usa LARAVEL_STORAGE_PATH externo: Storage::fake de los tests queda fuera
del almacenamiento funcional y no limpia adjuntos privados existentes.

Las pruebas PHP analizan DOM de Blade real, destinos y formularios; comprueban
cuentas convencionales/profesionales, capacidades editoriales/operativas/de
revisión, rol histórico, revocación, sesión suspendida, logout válido/CSRF faltante,
fallback HTML y contenido/clasificación de Ayuda. Las 7 pruebas frontend nuevas
cubren foco/teclado, no atrapar Tab, cierre fuera, acciones nativas y ciclo de
Turbo/historial. La QA de navegador complementa las pruebas unitarias de eventos.

### Resultados finales

| Comprobación | Resultado final |
|---|---|
| Laravel completo | 648 pruebas, 14,132 assertions; 20 pruebas nuevas, 221 assertions adicionales |
| Frontend completo | 51 pruebas aprobadas; 7 nuevas |
| Build Vite | Aprobado; 95 módulos |
| Pint de archivos PHP de 09B | Aprobado |
| Pint global | No aprobado: 14 archivos preexistentes sin modificar, reproducido sobre HEAD; detalle abajo |
| Sintaxis PHP | 265 archivos sin errores; las vistas modificadas también compilan en las pruebas HTTP |
| Rutas | 109 registros; cero duplicados método/URI; ninguna ruta añadida o modificada |
| Whitespace versionado y cinco archivos nuevos | Aprobado; git diff --check y --no-index --check sin staging |
| Inventario | Exactamente 13 archivos: 5 nuevos, 8 modificados, 0 eliminados |

Comandos de referencia: php artisan test --compact, node --test
tests/frontend/*.test.mjs, npm run build, php vendor/bin/pint --test y php artisan
route:list --json. Para aislar los archivos de las suites se configuró únicamente
en el proceso LARAVEL_STORAGE_PATH a una carpeta externa preparada con
framework/views, framework/sessions, framework/cache/data y logs. No se alteró
.env ni la configuración persistente del proyecto.

Durante la implementación inicial, la comparación de las 51 tablas de desarrollo conservó los
recuentos, hashes de todas las filas y esquemas originales. En testing se
restauran las filas/hashes y las concesiones originales tras los fixtures; los
contadores autoincrementales de pruebas pueden avanzar. Ambas bases conservan
33 migraciones aplicadas. .env, .env.testing y el adjunto privado conservan sus
hashes; el adjunto también conserva tamaño y mtime. Los otros worktrees conservan
HEAD y estado previo. El servidor exclusivo de QA fue detenido.

La QA comprobó 42 combinaciones (7 contextos × 3 anchos × 2 temas), con un solo
menú, destinos esperados, ningún ID repetido ni desbordamiento horizontal. Además,
se verificaron 6 combinaciones sin scripts (3 anchos × 2 temas), navegación HTML
nativa a Guardados y lectura de una respuesta de LEXA sin scripts.

En navegador se comprobó búsqueda sin resultados, reset y filtro Mensajes con dos
preguntas; recorridos Turbo entre Ayuda, Guardados y Perfil con foco en el main,
reinicialización del menú, flechas/Home/End/Escape/Tab y cierre por clic exterior.
Los enlaces editoriales, Operaciones y Revisión profesional llegaron a sus vistas
reales, incluidas bandejas vacías. Logout válido y rechazo por CSRF ausente se
verificaron por HTTP en PHPUnit; el navegador de QA no envía POST de negocio.

Los temas se fuerzan únicamente en las copias CSS servidas por el proceso externo;
no se cambian fuentes ni preferencias del sistema. La variante sin scripts omite
las etiquetas script en esa respuesta para simular fallo de carga de la mejora JS.
No se declara certificación WCAG integral ni prueba de todas las plataformas.

### Corrección de whitespace e investigación posterior al cierre

La revisión posterior de cierre detectó una línea vacía sobrante al final de
resources/js/ui/account-menu.js, tests/frontend/account-menu.test.mjs y este
documento. git diff --check no inspeccionaba esos archivos sin seguimiento.
Se eliminó únicamente el LF adicional en los dos archivos JavaScript; este
documento conserva un único salto final y registra el diagnóstico autorizado.
No se cambió lógica, contenido de pruebas ni otro archivo del inventario.
Se comprueban los cinco archivos nuevos con git diff --no-index --check contra
un archivo vacío externo, sin staging; su código 1 por contenido distinto es
normal cuando no existen diagnósticos de whitespace. Los cambios versionados
se comprueban con git diff --check. No se corrige whitespace heredado de HEAD.

Las instantáneas del cierre tomadas el 16 de noviembre entre 19:43:10 y
19:49:08 UTC sí difieren en users y sessions. Son hashes por tabla, sin imágenes
anteriores de cada columna; por sí solos no permiten identificar diferencias.
La investigación confirmó antes de consultar: entorno local, driver mysql,
base configurada y SELECT DATABASE() iguales a plataforma_legal_v2.

MySQL tenía binlog ROW con imágenes FULL y general_log desactivado. Mediante
lectura del binlog LACHEPOCOMPU-bin.000045 se identificaron los cambios dentro
del intervalo, en hora de Ciudad de México (UTC-6):

- 13:43:13: una sesión de users.id=1 actualizó payload y last_activity; la ruta
  previa pasó de Configuración a Ayuda y conservó su marca de autenticación.
- 13:43:16: UPDATE de users.id=1 exclusivamente en remember_token. No cambiaron
  password, name, email, email_verified_at, status, created_at ni updated_at.
- En la misma conexión MySQL: DELETE de esa sesión autenticada e INSERT de
  una sesión anónima con otro identificador; desaparecieron las marcas de
  autenticación y de comprobación de contraseña de la sesión.
- Una conexión posterior actualizó solamente el payload de la sesión anónima.
  Las otras dos sesiones permanecieron intactas. Los recuentos siguieron en
  un usuario y tres sesiones; hubo actualización, eliminación e inserción,
  no solamente una actualización de filas existentes.

Se decodificaron las imágenes únicamente en memoria. La reconstrucción inversa
reprodujo exactamente los hashes iniciales de ambas tablas, y el estado actual
reprodujo sus hashes finales. Esa reconstrucción no se ejecutó como SQL ni
restauró datos. No se guardaron valores de contraseñas, tokens, cookies,
identificadores completos ni payloads en las evidencias del diagnóstico.

El servidor Laravel de desarrollo en 127.0.0.1:8090 estaba iniciado desde las
13:41:31 y Chrome desde antes del intervalo. El historial local de Chrome,
consultado en modo de solo lectura, contiene Configuración y Ayuda y una visita
a / a las 13:43:16 en ese puerto. Es evidencia correlacionada de navegación real;
no constituye un access log HTTP ni identifica a la persona que hizo la petición.
La secuencia coincide con AuthenticatedSessionController.destroy y el logout de
SessionGuard: rotación de remember_token, invalidación y sesión anónima nueva.
La atribución a logout es una inferencia sustentada por esos datos y el código.

Clasificación A: variación compatible con uso normal y respaldada por evidencia.
No hay evidencia en ese intervalo de cambios del dominio ni de escrituras de
las pruebas o del servidor QA sobre desarrollo. Las otras 49 tablas conservan
filas y esquemas, incluidos roles, concesiones y operations_events. El QA
archivado verificaba plataforma_legal_v2_test, sesiones/caché array y fixtures
transaccionales revertidos; esa evidencia no equivale a auditar cada petición
histórica. No se declara conservación íntegra de users/sessions ni corrupción.

La revalidación utiliza almacenamiento externo, MySQL testing efectivo y
sesiones/caché array, comprobados también mediante HTTP dentro del propio
proceso PHPUnit antes de fixtures. La primera ejecución de la instrumentación
externa dejó handlers PHP activos y produjo avisos risky; se corrigió únicamente
ese instrumento externo y se repitió la validación. No requirió cambios de la
aplicación. El inventario permanece en 13 archivos: 5 nuevos y 8 modificados.
La corrida final pasó 648 pruebas y 14,132 assertions sin avisos risky; frontend
pasó 51 pruebas, build y Pint acotado aprobados, 265 fuentes PHP sin errores y
109 rutas sin duplicados. La comparación anterior/posterior de esta intervención
conserva exactamente filas y esquemas de las 51 tablas de desarrollo y las filas
de testing; los contadores autoincrementales de testing pueden avanzar.
La intervención termina sin staging, commit, push, restauraciones ni escrituras
directas sobre desarrollo. Los resultados finales se registran en la tabla de
validaciones de este documento.

### Incidencia previa de Pint global

Pint de los tres archivos PHP nuevos/modificados pasa. La comprobación global
detecta avisos en 14 archivos ajenos a 09B. Se verificó git diff HEAD vacío para
esos archivos y se reprodujeron los mismos avisos sobre copias externas obtenidas
directamente de HEAD. No se formatearon ni se incluyeron en esta integración:

- bootstrap/providers.php
- config/auth.php
- database/factories/UserFactory.php
- database/seeders/MunicipalitySeeder.php
- database/seeders/StateSeeder.php
- resources/views/pages/articles/demo.php
- resources/views/pages/home/demo.php
- resources/views/pages/lexa/demo.php
- resources/views/pages/messages/demo.php
- resources/views/pages/professionals/demo.php
- tests/Feature/CommunityMediaIntegrationTest.php
- tests/Feature/GeographicRelationsTest.php
- tests/Feature/RolePermissionTest.php
- tests/Feature/UserDatabaseTest.php

Por tanto, no se afirma Pint global aprobado. Es deuda de formato preexistente
que requiere un alcance separado, sin bloquear el diagnóstico de las funciones
probadas ni justificar cambios ajenos en este trabajo.

No se modificaron rutas de producción: se conservan 109 registros sin duplicados.
No hay endpoints de salida GET, migraciones, cambios de modelo, configuración de
correo ni modificaciones de .env.

## Riesgos y pendientes

09A-15 queda parcial: siguen pendientes Landing, footer e información pública y
la revisión completa del shell de visitante. 09A-21 queda parcial: el fallback
resuelve cuenta y lectura de Ayuda, no convierte todas las secciones Alpine de
Configuración ni la demo LEXA en interfaces sin JavaScript.

El diálogo antiguo de salida de Configuración (09A-07), otros contrastes/errores
de campo, fechas, límites de carga y preparación operativa mantienen su alcance
anterior. El menú ofrece una salida nativa directa, pero no altera ese diálogo.

Correo/worker, antimalware/retención y el aprovisionamiento inicial requieren sus
intervenciones autorizadas. Las pruebas de navegación no certifican accesibilidad
AA integral ni la operación real de LEXA.

## Inventario exacto

13 archivos: 5 nuevos, 8 modificados y ninguno eliminado.

### Nuevos (5)

- app/View/Components/Header/AccountMenu.php
- resources/js/ui/account-menu.js
- tests/Feature/AccountNavigationIntegrationTest.php
- tests/frontend/account-menu.test.mjs
- docs/ux-ui-integration-09b.md

### Modificados (8)

- resources/css/components/userHeader.css
- resources/css/library-help.css
- resources/js/app.js
- resources/js/personal.js
- resources/views/components/header/account-menu.blade.php
- resources/views/layouts/partials/header.blade.php
- resources/views/pages/help/index.blade.php
- resources/views/pages/help/questions.php

Build y artefactos de QA no forman parte del inventario versionable. No hay
staging, commit, push, merge ni cambio de rama; los demás worktrees no se alteran.
