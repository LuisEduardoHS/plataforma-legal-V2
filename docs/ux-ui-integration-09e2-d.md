# 09E.2-D — Navegación pública coherente

## Inspección previa y contrato de partida

Comprobado antes de modificar código: `main`, HEAD
`f122c6766b368b34b76eaddbc990eb4ffe6158c7`, árbol y staging limpios.
El informe externo de 09E.1, hallazgo 09E1-02 (P2), confirma el cambio de contrato
al salir de Landing: los catálogos muestran Inicio, Mensajes y LEXA sin explicar
el requisito de cuenta, la marca interna no enlaza a portada y Recursos lleva a
Artículos. El menú de cuenta de 09B sí distingue correctamente al visitante.

- Landing usa `layouts.public` y `components.public.header`; no carga el JS de la
  aplicación, deshabilita Turbo y funciona con HTML nativo. Su menú contiene
  anclas exclusivas de portada que no se pueden copiar a los catálogos.
- Comunidad, Profesionales, Artículos, Recursos y Ayuda usan `layouts.app` y la
  cabecera interna fija de `layouts.partials.header`, también para visitantes.
  Los detalles públicos heredan ese mismo layout.
- Rutas existentes: `landing` `/`, `community` `/comunidad`, `professionals`
  `/profesionales`, `articles` `/articulos`, `resources` `/recursos`, `help`
  `/ayuda`, `login` `/iniciar-sesion` y `register` `/registro`.
- `EnsureActiveAccount` recarga la cuenta, verifica su estado y credenciales y
  cierra sesiones inactivas antes de renderizar. Landing redirige las cuentas
  activas a Inicio. Ninguno de esos comportamientos debe cambiar.
- La cabecera autenticada y `AccountMenu` conservan capacidades efectivas y POST
  de logout con CSRF. No corresponde ejecutar sus consultas editoriales para
  construir la cabecera del visitante.
- `ReadingReturn::loginUrl` ya firma retornos a detalles públicos. Su captura
  mantiene la prioridad de `url.intended`; autenticar no ejecuta Guardar ni
  Contactar. Se reutilizará sin modificar el servicio ni contratos de autenticación.
- 09C aporta los tokens guinda `#7F1842`, acento `#9E1F52`, violeta `#6C4ACB` y
  DM Sans. 09D/09B aportan disclosure nativo, foco y limpieza de eventos; `onPage`
  monta por cuerpo y elimina listeners al navegar con Turbo.

## Decisión de arquitectura

Reutilizar una única cabecera pública Blade en ambos layouts y mantener intacta
la cabecera interna para cuentas activas. Una sola lista de destinos del servidor
alimenta las presentaciones de escritorio y móvil. El disclosure móvil funciona
sin scripts; una entrada JS pequeña reutiliza teclado/cierre de 09B y sincroniza
su estado ARIA, sin incorporar Alpine, Turbo ni el bundle privado a Landing.
Los estilos de cabecera se extraerán del CSS de Landing al CSS compartido para
evitar cargar el diseño completo de portada en los catálogos. No se añadirán
rutas, políticas, permisos, dependencias ni lógica de autorización.

## Implementación

`components.public.header` mantiene la marca enlazada a portada y ofrece Comunidad,
Profesionales, Artículos, Recursos, Ayuda, Iniciar sesión y Crear cuenta. Artículos
y Recursos tienen destinos distintos. Los detalles públicos marcan su módulo
mediante `aria-current`; portada marca la marca. No se trasladan anclas exclusivas
de Landing a los catálogos ni se muestran accesos privados en esta cabecera.
La promoción de LEXA en el contenido de portada conserva sus avisos de demostración
y autenticación; no se incorpora LEXA a la navegación pública principal.

`layouts.app` selecciona la cabecera interna únicamente para una cuenta activa,
después del middleware existente. Conserva exactamente el partial interno,
`AccountMenu`, capacidades, logout y rutas protegidas. Para visitantes selecciona
el componente público y omite el CSS exclusivo de la cabecera interna. La clase
`public-shell` elimina únicamente el hueco superior reservado para la cabecera
interna fija. No se modifica ningún controlador, middleware, política o servicio.
Una cuenta inactiva sigue cerrando sesión y redirigiendo a login antes de renderizar;
la siguiente lectura anónima presenta navegación pública.

Los estilos compartidos de `publicHeader.css` usan los tokens existentes y se
importan desde `app.css`, ya cargado por ambos layouts. Se retiran de `landing.css`
las reglas anteriores de la cabecera. No se crea una hoja adicional descargada
por la Landing ni se carga el diseño completo de portada en los catálogos.
La marca genérica existente y DM Sans se conservan, sin nueva identidad comercial.

### Móvil, teclado y funcionamiento sin scripts

Hasta 1279 px se usa un único `details/summary` con todos los destinos, login y
registro. Desde 1280 px se muestran los enlaces en línea. Ambas presentaciones
comparten el mismo array Blade; la variante no visible se excluye del árbol
accesible mediante CSS. No se duplican IDs ni se crean reglas de rutas en JS.

El disclosure nativo permite abrir/cerrar y navegar con Enter, Espacio y Tab sin
JavaScript. `aria-controls` identifica su panel; el estado expandido nativo sigue
siendo accesible sin añadir un atributo estático que pudiera quedar obsoleto.
Con scripts, `setupPublicNavigation` reutiliza `setupAccountMenu` de 09B para
flechas, Home/End, Escape con retorno de foco, cierre al salir del panel o seguir
un enlace, y restauración desde el historial. Su listener `toggle` sincroniza
`aria-expanded`; la limpieza sincroniza también el estado cerrado.

El foco visible utiliza el violeta existente y su variante legible oscura. Los
enlaces tienen al menos 44 px de altura; el panel limita altura y permite scroll
interno. Tipografía en rem y flex con reflujo mantienen utilizables los controles
con texto al 200 %. No se usa hover como requisito ni se atrapa Tab.

### Turbo y presupuesto de Landing

La entrada `resources/js/public.js` carga solamente el ciclo `onPage`, la mejora
de disclosure y el código compartido de 09B. No importa Alpine ni Turbo. La entrada
principal de la aplicación importa esa misma entrada pública para los catálogos,
sin montar un segundo controlador ni mantener dos sistemas de navegación.
`onPage` elimina listeners antes de sustituir el cuerpo, cierra el panel y evita
montajes repetidos; `pageshow` y `turbo:before-cache` cierran el disclosure.

Landing mantiene `data-turbo="false"` y `turbo-visit-control=reload`: sus enlaces y
contenido funcionan sin scripts, y la vuelta desde la aplicación conserva la
frontera de recarga existente. Los catálogos conservan Turbo. No se añade polling,
fetch de navegación propio, framework, dependencia ni bundle privado a portada.

### Compatibilidad con 09E.2-B

La cabecera reutiliza `ReadingReturn::loginUrl` en los cinco tipos de detalle
público: profesional, perfil de usuario, publicación, artículo y recurso. Las
listas ofrecen login normal. Solo se proporcionan tipo e ID tomados de la ruta
existente; no se aceptan orígenes ni destinos de redirección del cliente.
No se modifica `ReadingReturn`, su caducidad, captura, registro ni autenticación.

Las regresiones existentes de Contactar y Guardar siguen pasando. Las nuevas
comprueban el login firmado de la cabecera del perfil público, retorno al detalle,
prioridad de `url.intended` protegido, CSRF y ausencia de conversaciones/guardados
automáticos. Los formularios de login y registro son accesibles desde los seis
destinos públicos. En navegador se recorrió directorio → perfil → login de la
cabecera → mismo perfil con la cuenta sintética; no se envió ningún mensaje.

## Pruebas y QA

Se incorporan 17 casos HTTP/Blade en `PublicNavigationIntegrationTest`: seis
entradas de visitante, cinco catálogos con cuenta activa, tres estados inactivos,
dos precedencias de retorno y render de cabecera sin SQL. Comprueban HTML real,
destinos, privacidad de cabecera, indicador actual, disclosure nativo, IDs únicos,
formularios disponibles y preservación de capacidades. El preflight HTTP verifica
el aislamiento antes de crear fixtures y cada petición vuelve a comprobarlo.

Las pruebas de 09B actualizan únicamente sus expectativas para visitante después
de logout/inactivación; las pruebas de capacidades de cuentas activas permanecen.
La regresión de assets de Landing exige una sola entrada pública y rechaza el JS
privado y Turbo, en lugar de exigir cero scripts de cualquier tipo. Las tres hojas
de estilo declaradas siguen siendo tokens/app, Landing y DM Sans.

Cinco pruebas frontend nuevas comprueban toggles nativos y ARIA, Enter nativo,
flechas/Home/End/Escape y foco, enlaces/Tab, restauración de historial, cierre y
limpieza. Ejecutan el ciclo real `onPage` a través de cinco cuerpos Turbo y prueban
que los listeners anteriores ya no responden y cada cuerpo se monta una vez.

Los primeros fallos focalizados eran del test nuevo: su XPath incluía una cabecera
del contenido y el fixture intentaba asignar masivamente `status`, que el modelo
protege. Se acotó el XPath a la cabecera pública y se preparó el estado sintético
mediante `forceFill/save`, sin alterar la protección del modelo ni el middleware.

QA con sesión y CSRF nativos, fixtures sintéticos y rollback por petición:

- Recorrido público Landing → Comunidad → Profesionales → Artículos → Recursos →
  Ayuda en 320, 390, 768, 1024 y 1440 px, ambos temas, y regreso a portada mediante
  la marca. Artículos y Recursos se distinguen correctamente.
- El mismo recorrido sin scripts de producto en móvil oscuro y escritorio claro;
  se comprobó cero elementos `script`, apertura nativa, Tab y cierre con Espacio.
  El motor de inspección del navegador permaneció habilitado.
- Texto de la cabecera al 200 % en los cinco anchos; también se repitió el recorrido
  nativo a 320 px con texto ampliado. Esta comprobación amplía la raíz tipográfica
  del laboratorio, sin cambiar preferencias del usuario ni CSS de producto.
- Recorrido autenticado Inicio → Comunidad → Profesionales → Artículos → Recursos
  → Ayuda → Inicio en los cinco anchos y ambos temas; también sin scripts en móvil
  y escritorio. Una sola cabecera interna, sin accesos privilegiados para la cuenta
  convencional y sin conceder permisos.
- Login firmado de la cabecera devuelve al perfil; una cuenta sintética suspendida
  se redirige a login y la siguiente lectura es pública.
- Teclado: flechas hasta Comunidad, End hasta Crear cuenta, Escape con foco en
  Explorar, Shift+Tab hasta la marca con outline de 2 px; menús cerrados al navegar
  y después de Atrás. Tras sucesivas visitas Turbo una flecha enfoca una sola vez
  el primer enlace. Se abrió el formulario de registro sin enviar un alta.
- 199 observaciones DOM guardadas fuera del repositorio: 127 públicas y 72 internas,
  sin scroll horizontal ni IDs duplicados. Capturas finales con la vista asentada
  confirman superficie opaca y contraste en ambos temas.

La sesión sintética quedó revocada por la prueba de inactividad. Se cerraron la
pestaña y el servidor propios y se restauró el viewport al terminar el QA.

## Rendimiento

Mediciones antes de modificar código y después del build de implementación: mismo laboratorio
HTTP, mismos fixtures transaccionales, tres lecturas por ruta, sin desarrollo.
Las consultas no incluyen la creación de fixtures. El render aislado de la
cabecera pública ejecuta **cero consultas SQL antes y después**, sin consultar
proyecciones privadas ni capacidades editoriales.

| Página | SQL antes → después | HTML bytes antes → después | Mediana local ms antes → después |
| --- | --- | --- | --- |
| Landing | 0 → 0 | 20,121 → 20,353 | 20.25 → 22.15 |
| Comunidad | 4 → 4 | 18,972 → 10,210 | 59.34 → 44.64 |
| Profesionales | 7 → 7 | 17,842 → 9,080 | 50.50 → 33.17 |
| Artículos | 4 → 4 | 15,084 → 6,322 | 46.32 → 29.81 |
| Recursos | 4 → 4 | 15,451 → 6,689 | 47.37 → 32.16 |
| Ayuda | 0 → 0 | 55,854 → 47,159 | 34.75 → 19.49 |
| Perfil profesional público | 4 → 4 | 15,928 → 7,564 | 34.17 → 18.89 |

Son pocas muestras instrumentadas y locales, no un benchmark de producción ni
prueba de mejora causal de latencia. La reducción de HTML de los catálogos sí es
directamente observable al retirar los SVG y el shell privado de su cabecera.

- CSS app: 94,111 → 97,738 bytes; gzip nivel 9: 17,506 → 18,344.
- CSS Landing: 14,274 → 11,941 bytes; gzip: 3,133 → 2,677.
- CSS conjunto de Landing: **+1,294 bytes, +382 gzip**, sin nueva petición CSS.
- JS público, incluidas sus dos dependencias compartidas: **1,799 bytes, 1,048
  gzip**, tres archivos; la entrada propia ocupa 124 bytes. No contiene Alpine,
  Turbo ni el bundle privado. Este es el coste de Escape/foco/ARIA progresivos.
- Landing pasa de dos CSS locales a esos dos CSS y tres JS pequeños. El HTML
  declara 3 → 6 URLs únicas de recursos, incluida la hoja de fuentes externa;
  los logs del navegador confirman cinco assets locales en la carga inicial nueva.
  No se incluyen aquí los archivos woff2 solicitados por la hoja externa.
- En catálogos se elimina la hoja exclusiva `userHeader` (antes 99,384 bytes),
  compensando las nuevas dependencias compartidas: permanecen 12 URLs únicas
  declaradas, 11 en el detalle profesional. Los módulos se reutilizan durante Turbo.
- La entrada principal app pasa de 139,186 a 138,345 bytes por extraer código
  compartido. No representa por sí sola el coste total del grafo.
- Build: 103 módulos transformados, sin dependencias nuevas. No se amplían Inicio,
  consultas de dominio, polling ni optimizaciones generales de 09P.

## Aislamiento y conservación

Antes de fixtures, dentro de PHPUnit y HTTP se comprobaron APP_ENV testing, MySQL
configurado y efectivo `plataforma_legal_v2_test`, caché array y almacenamiento
privado externo. PHPUnit usa sesiones array. El navegador usa sesiones en archivos
externos y cookie exclusiva `qa09e2d_session`; se descartan cookies ajenas. El
preflight HTTP de solo lectura pasó con SQL READ ONLY. Las peticiones posteriores
mantienen fixtures sintéticos bajo una transacción exterior que siempre revierte.
No se usan cuentas, mensajes ni adjuntos reales como fixtures.

Comparación posterior por lectura segura:

- Desarrollo: las 51 estructuras y cantidades coinciden. Los hashes de cuentas,
  roles, permisos, contenidos, expedientes y eventos se conservan. La única
  variación de datos es la actividad anónima de `sessions` descrita abajo.
- Testing: las 51 tablas conservan cantidades y hashes de registros, sin fixtures
  persistidos. Solo avanzaron los AUTO_INCREMENT de 26 tablas; se reconstruyó el
  DDL anterior en memoria para comprobarlo, sin ejecutar ALTER ni restaurar contadores.
- `.env`, `.env.testing`, `public/hot` y adjuntos privados conservan sus hashes;
  los adjuntos conservan además tamaños y fechas. Los otros worktrees conservan
  HEAD y sus estados iniciales, sin operaciones de escritura sobre ellos.
- No se crean ni ejecutan migraciones, seeders de desarrollo, correo, workers,
  operadores ni concesiones reales. Los artefactos y harness de QA son externos.

### Actividad anónima de sesiones

Entre 2026-11-19 23:22:06 UTC y 23:47:19 UTC (extremo final excluido), la lectura
filtrada del binlog en memoria identificó **seis UPDATE exclusivamente de
`sessions.last_activity`**, todos anónimos y con payload idéntico antes/después.
Las siete filas se conservan, sin INSERT ni DELETE ni eventos en otras tablas de
desarrollo. No se guardaron IDs reales, payloads, IP ni agentes de usuario.
No se identificó autor ni proceso de origen y no se infiere un logout. No se
restauran sesiones ni se afirma identidad byte a byte de todos los datos de desarrollo.

## Validaciones finales

- Laravel focalizado de navegación, Landing, retorno y accesibilidad: **118 pruebas,
  2,045 assertions**, 21.87 s.
- Laravel completo: **807 pruebas, 16,982 assertions**, 154.84 s.
- Frontend completo: **84 pruebas**, cinco nuevas.
- Build Vite: aprobado. Pint de los tres PHP afectados: aprobado.
- Sintaxis: **368 archivos PHP/Blade**, sin errores; las tres vistas afectadas
  se compilaron y el PHP resultante pasó lint.
- Rutas: **110**, sin incorporaciones; nombres y pares método/URI sin duplicados.
- Whitespace de modificados y nuevos, y `git diff --check`: aprobado.
- Se conserva separadamente la deuda Pint global preexistente; no se formatea.

## Revisión final de cierre

Se reconfirmaron las 118 pruebas focalizadas con 2,045 assertions (35.12 s),
las 807 pruebas completas con 16,982 assertions (161.19 s), las 84 de frontend,
el build de 103 módulos, Pint afectado, 368 archivos de sintaxis y tres vistas
compiladas. Se conserva el inventario de 15 archivos y no se modifica código
durante el cierre. Las variaciones nuevas observadas son las siguientes.

### CSS reconstruido después de completar la documentación

Tailwind detecta la palabra `outline` de este documento y añade su utilidad al
CSS compartido. La reconstrucción aumenta 65 bytes en app, cabecera interna y
login, con el reordenamiento equivalente de su propiedad CSS registrada. Un build
diagnóstico exclusivamente externo, que excluye esa utilidad, reproduce
exactamente el hash anterior del CSS app. No se modifica la configuración de
escaneo ni se elimina ninguna regla del producto para igualar hashes.

El CSS app reconstruido mide 97,803 bytes y 18,371 gzip; el incremento conjunto
de CSS de Landing frente al punto de partida pasa de 382 a 409 bytes gzip.
Landing conserva la misma hoja propia. El grafo JS público sigue en 1,799 bytes,
1,048 gzip y tres archivos; los demás JS mantienen sus hashes. No aparece una
dependencia, ruta, consulta o funcionalidad nueva. Las cifras anteriores quedan
como mediciones históricas de implementación, no como tamaños rígidos del cierre.

### Sesiones anónimas durante el cierre

La comparación de desarrollo conserva las 51 estructuras, cantidades y hashes
funcionales; únicamente varía `sessions`. Se inspeccionó por lectura segura el
intervalo 2026-11-19 23:55:44 UTC a 2026-11-20 00:02:38 UTC (final excluido).
Hubo 12 UPDATE anónimos: nueve de payload y actividad, dos solo de actividad y
uno solo de payload. La inspección en memoria, sin habilitar clases al deserializar,
confirma que los diez cambios de payload afectan exclusivamente `_previous.url`
y `_previous.route`. No cambian tokens, credenciales ni otros atributos, no hay
claves de autenticación y no se crean o eliminan filas. Las siete sesiones se
conservan. No se exportan los valores de URL, datos personales ni payloads.

Esta observación es adicional a las seis actualizaciones históricas documentadas
arriba. No se identifica autor o proceso, no se atribuye la actividad a las pruebas
y no se restauran sesiones. Testing conserva cantidades y hashes de sus 51 tablas,
sin fixtures persistidos; sus únicas variaciones estructurales son los contadores
AUTO_INCREMENT de 26 tablas, comprobadas en memoria. Configuración, adjuntos y
otros worktrees conservan sus huellas iniciales.

## Limitaciones

- No se rediseñan los contenidos ni la navegación interna. Su acceso «Recursos»
  conserva el catálogo de Artículos y la pestaña de Recursos jurídicos existente.
  La distinción directa se resuelve en la cabecera del visitante, objeto de este lote.
- Login y registro mantienen su layout existente; no se crea un segundo sistema
  de autenticación. El correo de recuperación conserva sus limitaciones documentadas.
- Sin scripts, Escape no sustituye la activación nativa de summary: se cierra con
  Enter/Espacio. No hay ARIA estático que contradiga el estado nativo.
- Las mediciones de latencia son locales; la ampliación tipográfica de QA no es
  una certificación de todos los navegadores o tecnologías de asistencia.
- No se comienza 09E.2-E, 09F, 09P ni LEXA real.

## Inventario exacto y Git

**15 archivos: nueve modificados y seis nuevos; ninguno eliminado.**

Modificados:

1. `resources/css/app.css`
2. `resources/css/landing.css`
3. `resources/js/app.js`
4. `resources/views/components/public/header.blade.php`
5. `resources/views/layouts/app.blade.php`
6. `resources/views/layouts/public.blade.php`
7. `tests/Feature/AccountNavigationIntegrationTest.php`
8. `tests/Feature/LandingIntegrationTest.php`
9. `vite.config.js`

Nuevos:

1. `resources/css/components/publicHeader.css`
2. `resources/js/public.js`
3. `resources/js/ui/public-navigation.js`
4. `tests/Feature/PublicNavigationIntegrationTest.php`
5. `tests/frontend/public-navigation.test.mjs`
6. `docs/ux-ui-integration-09e2-d.md`

Git permanece en `main`, HEAD `f122c6766b368b34b76eaddbc990eb4ffe6158c7`,
sin commits por delante/detrás de `origin/main`, staging vacío y únicamente este
inventario pendiente. No se realizó staging, commit, push, merge ni cambio de rama.
