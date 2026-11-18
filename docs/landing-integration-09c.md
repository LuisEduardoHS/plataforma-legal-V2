# ETAPA 09C.2 — Landing pública

Base: main, HEAD 6d80333a6d9b96a16e3105a108b272f3bb6aa38a.
Implementación pendiente de revisión; sin staging, commit ni push.

## Arquitectura y contrato HTTP

La raíz conserva el nombre `landing` y reemplaza la vista welcome por un
LandingController pequeño. GET/HEAD anónimo devuelve la Landing; una cuenta
autenticada activa recibe 302 a `/inicio`. La redirección es directa a home y
no consume intended. No se duplicó el Inicio ni se crearon rutas: continúan
109 registros, sin duplicados de dominio/método/URI.

La ruta sigue en web. EnsureActiveAccount y AuthenticateSession conservan la
resolución de estado persistido y credencial revocada, invalidación y retorno
a login. No se añadió guest a la raíz ni autorización alternativa por rol.
Las respuestas de la raíz son `Cache-Control: no-store, private` y
`Pragma: no-cache`, también para visitantes. HEAD no devuelve cuerpo.

El layout public es independiente de app y guest. Carga app.css para reutilizar
tokens/reset y landing.css; no carga app.js, login.css, userHeader.css, Axios,
Alpine, Turbo ni el workspace de LEXA. El límite con páginas internas usa
`turbo-visit-control=reload` y navegación HTML ordinaria. No cambian los shells
de Comunidad, Directorio, Biblioteca, autenticación o Ayuda.

Header y footer tienen responsabilidades propias. El hero es un componente de
presentación separado; las secciones editoriales permanecen en la vista de
Landing. No hay un servicio de consultas nuevo ni previews de MySQL.

## Experiencia y contenido aprobado

El header contiene marca tipográfica enlazada a `/`, un details/summary nativo
para explorar secciones y módulos, Ayuda y accesos de cuenta. En móvil, los
accesos se encuentran dentro del mismo desplegable; en escritorio se muestran
login/registro directamente. No se ofrecen Inicio, Mensajes o perfil privados
como navegación de visitante. No se modificó AccountMenu de 09B.

Hero:

- Identidad: «LEXA · Demostración».
- H1: «Un punto de partida para tus dudas legales.»
- Secundario: «Conoce la experiencia de LEXA y explora información y
  profesionales en México.»
- Ejemplo: «¿Qué información debería reunir antes de consultar a un profesional?»
- CTA: «Conocer la demostración», enlace real a route('lexa').
- Segundo acceso: «Explorar la plataforma».
- Aviso: «IA aún no conectada. Las respuestas actuales son ejemplos y no
  analizan tu caso.» Se indica que la demo requiere iniciar sesión.

La pregunta es texto editorial en blockquote, no un campo editable. No hay
formularios, textarea, micrófono, adjuntos, envío simulado, data-lexa o scripts
de chat. No hay preguntas que trasladar o guardar en URL, cookies, storage o
logs. El flujo protegido existente conduce a login y recupera `/lexa` mediante
intended, comprobado por HTTP real en las pruebas de integración.

Después del hero: presentación de posibilidades; Comunidad; Profesionales;
Artículos y Recursos diferenciados; cómo funciona; confianza/transparencia;
registro y footer. Todo es editorial con enlaces a módulos reales. No se
inventaron personas, artículos, actividad, cifras, testimonios o sellos.
Se explica que la verificación documental es interna, no gubernamental
automática ni garantía del servicio. No se prometen IA, análisis de casos,
cifrado E2E o servicios operativos de correo inexistentes.

El footer incluye Comunidad, Profesionales, Artículos, Recursos jurídicos,
Ayuda, acceso y registro. No hay enlaces de Términos, Privacidad o contacto
institucional sin destino aprobado, ni href="#".

## Diseño, responsive y accesibilidad

DM Sans continúa como fuente. Se usa la familia existente de Google Fonts con
pesos normales 400–700; no se descargaron fuentes al repositorio ni se añadieron
dependencias. La paleta reutiliza los tokens guinda/violeta y superficies de V2.
El halo estático se limita al hero. Radio de 10px, bordes discretos y sombra
suave del ejemplo; sin imágenes raster, vídeos, partículas o animación continua.

El documento utiliza contenedor máximo de 1200px, lectura limitada, grids
adaptables y scroll normal. Header con wrap y texto capaz de reflow; el menú
limita su alto y permite scroll interno cuando es necesario. No se heredan
los offsets/columnas de la pantalla LEXA. Claro/oscuro siguen prefers-color-scheme.

Se implementaron skip link visible al foco, main enfocable, un H1, secciones
nombradas, navegación con nombres accesibles e iconos decorativos ocultos a
tecnologías de asistencia. CTAs nativos, controles principales de al menos
44px y foco sólido de 2px. El desplegable se maneja con Enter/Espacio y Tab;
no necesita un focus trap ni JavaScript para navegar.

QA final: 320, 390, 768, 1024 y 1440px, ambos temas (10 combinaciones), sin
scroll horizontal, IDs duplicados, scripts de producto o formularios. CTA
dentro del ancho y aviso demo disponible. DM Sans cargada en esas muestras.
Se verificaron skip link → main, apertura por teclado y Tab al primer enlace,
menú móvil dentro del viewport, CTA → login y regreso a Landing.

Se comprobó texto ampliado al 200% a 320/768px en ambos temas (4 combinaciones),
duplicando tamaños tipográficos en la copia CSS servida por QA: sin overflow
de documento ni de los textos/controladores inspeccionados. Es una simulación
de ampliación de texto, no una certificación de zoom nativo en todos los agentes.
Se inspeccionaron reglas de reduced-motion y se verificó una respuesta de QA
con movimiento/transiciones anulados; no se cambió el SO. El halo ya es estático.
Los temas se forzaron únicamente en copias de respuesta CSS, sin editar fuentes.

Pares sólidos revisados: secundario claro sobre superficie 5.91:1; secundario
oscuro 7.50:1; blanco sobre violeta 6.04:1 y sobre guinda 9.94:1; foco oscuro
8.49:1. No equivalen a auditar todos los píxeles del halo o certificar WCAG.
La semántica se comprobó mediante DOM/árbol accesible; queda pendiente una
auditoría manual con lectores de pantalla y zoom nativo adicionales.

En build la Landing no sirve JavaScript: sus enlaces y details se probaron
como HTML nativo, sin mejora JS de aplicación. No se alteró la configuración
global de JavaScript del navegador. HMR puede añadir el cliente Vite en
desarrollo; ese entorno no es el usado para medir el build.

## Rendimiento medido y límites

Assets finales del build local:

| Asset | Sin comprimir | Gzip offline, nivel 9 |
|---|---:|---:|
| app.css, tokens/reset compartidos | 91,909 bytes | 17,001 bytes |
| landing.css | 14,274 bytes | 3,133 bytes |
| JS de producto | 0 | 0 |
| Total inicial CSS + JS first party | 106,183 bytes | 20,134 bytes (19.66 KiB) |

Cumple el presupuesto de aproximadamente 60KiB gzip de CSS/JS propio. No incluye
HTML, fuentes o cabeceras. No hay polling, IA, imágenes hero o consultas nuevas
de contenido; las consultas de sesión/vigencia siguen sus contratos existentes.
El manifest conserva las entradas privadas; no se reestructuraron los bundles
globales. El CSS compartido y el grafo JS privado conservaron sus hashes de build.

Se utilizó un servidor externo de QA en loopback, con MySQL testing, SQL de
solo lectura, sesiones/caché array, almacenamiento externo y hot file alternativo
inexistente. `public/hot` de desarrollo conservó su contenido. HTTP de escritura
y descargas privadas estuvieron bloqueados; cookies entrantes de autenticación
de desarrollo se descartaron. El servidor propio de QA fue detenido al terminar.

Transferencia HTTP observada de cuerpos CSS gzip: 17,120 + 3,155 = 20,275 bytes;
Resource Timing mostró 20,875 bytes incluyendo overhead informado por el
navegador. La diferencia con gzip offline incluye nivel de compresión y las
condiciones de tema reescritas solo en la respuesta QA. Las repeticiones
utilizaron caché y reportaron transferencia cero de esos CSS.

Medición local de navegador a 390×844, build sin HMR:

| Muestra | TTFB | LCP | CLS | Tareas largas observadas |
|---|---:|---:|---:|---:|
| Primera transferencia de CSS | 298.1ms | 352ms | 0 | 0 |
| Repetición 1 | 241.6ms | 292ms | 0 | 0 |
| Repetición 2 | 249.1ms | 296ms | 0 | 0 |

Es laboratorio local sin throttling de red/CPU; servidor y fuentes ya estaban
calientes. No es una primera visita completamente fría ni p75 de usuarios
reales. Se observaron una solicitud CSS de Google y un WOFF2 DM Sans de 36,932
bytes codificados, ya en caché en estas mediciones. No demuestra su latencia
con red móvil real. El script PerformanceObserver existió exclusivamente en
la respuesta externa qa_measure, no en código/producto.

Se cumple el presupuesto de assets. No se declara cumplimiento de LCP móvil
real ni INP: faltan condiciones representativas/medición válida de campo o
interacciones suficientes. Quedan pendientes cold start completo, dispositivos
reales, redes móviles y entorno de despliegue con compresión/caché reales.

## Pruebas y validaciones

LandingIntegrationTest confirma desde middleware de una petición HTTP:
APP_ENV testing, driver MySQL, base configurada y SELECT DATABASE() exactamente
plataforma_legal_v2_test, caché/sesiones array y attachments en almacenamiento
externo. Ese GET ocurre antes de crear fixtures. Las cuentas de prueba se crean
dentro de una transacción y se revierten. El bootstrap externo de la suite
verificó también aislamiento HTTP antes de ejecutar las pruebas completas.

Se añadieron 15 casos efectivos: documento visitante/cache/semántica; destinos
header/footer; hero honesto; IDs/anclas; privacidad y ausencia de consultas de
contenido; HTML real con assets de build y sin JS; HEAD; GET/HEAD activo;
tres estados inactivos persistidos; credencial revocada; login/intended a LEXA;
y lectura de destinos públicos. AuthenticationTest ajusta únicamente la
expectativa antigua de la raíz autenticada a su nuevo contrato 302/no-store.

| Comprobación | Resultado |
|---|---|
| Laravel completo | 663 pruebas, 14,411 assertions aprobadas; +15 y +279 frente a referencia |
| Landing/autenticación focalizadas | 54 pruebas, 739 assertions aprobadas |
| Frontend completo | 51 pruebas aprobadas; sin nuevos tests JS porque no se añadió JS de producto |
| Vite build final | Aprobado, 96 módulos; nueva entrada CSS pública |
| Pint de PHP afectados | Aprobado; controlador, prueba nueva y AuthenticationTest |
| Sintaxis PHP | 267 fuentes sin errores; Blade nuevo renderizado en pruebas HTTP |
| Rutas | 109, sin duplicados; solo cambia la acción de landing |
| Whitespace | Comprobación de cambios versionados y archivos nuevos, sin staging |
| QA visual | 10 combinaciones ancho/tema y 4 de texto ampliado, más teclado/navegación |

La comparación de las 51 tablas de desarrollo antes/después conserva filas y
esquemas exactos. Las filas de testing se restauraron; pueden avanzar sus
contadores autoincrementales. .env, .env.testing, public/hot, adjuntos privados
y otros worktrees conservaron contenido/estado. No se hicieron migraciones,
seeders de desarrollo, concesiones reales ni aprovisionamiento.

Pint global mantiene deuda conocida en 14 archivos preexistentes ajenos.
No se formatearon ni se afirma que Pint global esté aprobado.

## Cobertura de 09A y pendientes

- 09A-01: resuelto en alcance de portada; la raíz deja de mostrar Laravel.
- 09A-14: identidad de raíz y continuidad DM Sans alineadas con aprobación.
  No se rediseñaron títulos o tipografía de otras pantallas.
- 09A-15: parcial; Landing tiene descubrimiento público propio. Los shells de
  catálogos siguen intactos por instrucción y necesitan una intervención separada.
- 09A-04: parcial; footer y transparencia implementados, pero los textos/rutas
  legales e institucionales necesitan contenido aprobado antes de publicarse.
- 09A-21: Landing funciona con HTML nativo; no cambia pendientes de Configuración
  u otras demos sin JavaScript.

LEXA real/RAG, archivos/voz, transporte o persistencia de preguntas, previews
MySQL, ampliación del shell público y optimizaciones generales quedan fuera.

## Inventario exacto

12 archivos afectados: 8 nuevos, 4 modificados, ninguno eliminado.

### Nuevos (8)

- app/Http/Controllers/LandingController.php
- resources/views/layouts/public.blade.php
- resources/views/components/public/header.blade.php
- resources/views/components/public/footer.blade.php
- resources/views/components/landing/lexa-hero.blade.php
- resources/css/landing.css
- tests/Feature/LandingIntegrationTest.php
- docs/landing-integration-09c.md

### Modificados (4)

- routes/web.php
- resources/views/pages/landing/index.blade.php
- vite.config.js
- tests/Feature/AuthenticationTest.php

Build, capturas, logs, instrumentación y almacenamiento QA están fuera del
inventario versionable. Artefactos de validación: directorio externo
landing09c2-1794925987. Se conserva main y el HEAD inicial, sin staging, commit,
push, merge o cambios de otros worktrees. El resultado queda para revisión.
