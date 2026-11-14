# INTEGRACIÓN-01 — Autenticación y sesiones

Base: `main`, commit `c4cd25e`. Integración sobre las interfaces existentes, sin
rediseñar los módulos ni implementar sus operaciones HTTP. No hay migraciones ni
dependencias nuevas; se reutilizan el guard `web`, el proveedor Eloquent, el hashing,
las sesiones, CSRF y RateLimiter de Laravel y los roles de Spatie ya auditados.

## Registro

GET `/registro` muestra el formulario y POST `/registro` crea una cuenta convencional.
Los campos permitidos son `name`, `email`, `password` y `password_confirmation`, además
del token CSRF. Se normaliza el correo a minúsculas sin espacios exteriores y se
comprueba unicidad. La restricción única de MySQL también protege carreras de registro.

El nombre es obligatorio, con máximo de 255 caracteres y sin controles. Las nuevas
contraseñas requieren al menos 12 caracteres y confirmación. Se rechazan bytes nulos
y valores de más de 72 bytes para evitar truncamiento de bcrypt. El hash lo produce
el cast existente de User; no se guarda texto plano ni se cambia el algoritmo/coste
configurado. El login sigue aceptando las contraseñas existentes, sin imponerles
retroactivamente el mínimo de longitud de registro.

La creación de User y su asociación al rol `user`, guard `web`, comparten transacción.
El estado activo procede del modelo; no se acepta desde el cliente. No se crean
perfiles, solicitudes profesionales, permisos directos ni roles adicionales. Los
perfiles existentes siguen siendo opcionales. Cualquier campo adicional se rechaza,
incluidos roles, permisos, estado, IDs y supuesta verificación.

El rol convencional debe estar aprovisionado mediante las operaciones de BACK-01.
Si falta, el registro falla con 503 sin dejar una cuenta ni crear privilegios.
No se ejecutan seeders desde peticiones HTTP. Los errores SQL internos se reportan
mediante el mecanismo sanitizado de AUDIT-02; no se devuelven al formulario.

Tras confirmar la transacción se emite Registered, se inicia sesión y se regeneran
el identificador y token CSRF. El usuario vuelve al destino privado que había
solicitado, si existe, o a `/inicio`. No se implementaron verificación de correo ni
verificación profesional como efecto del registro.

## Login, logout y estado persistido

GET `/iniciar-sesion` y POST `/iniciar-sesion` usan credenciales reales, `attemptWhen`
y la opción `remember`. Un error de contraseña, correo inexistente o cuenta inactiva
produce el mismo mensaje genérico. El ID y CSRF se regeneran tras autenticar.

POST `/cerrar-sesion`, protegido por autenticación y CSRF, ejecuta logout, invalida
la sesión y regenera CSRF. Laravel expira la cookie de recuerdo y rota su token.
GET sobre esa ruta devuelve 405. La salida redirige a Landing.

EnsureActiveAccount se ejecuta en el grupo web después de iniciar la sesión y antes
del control de autenticación. Recarga el usuario persistido con una lectura actual,
sustituye el modelo del guard y no confía en un usuario cacheado o en su rol.
Una cuenta suspendida, deshabilitada, pendiente de eliminación o inexistente pierde
la sesión actual, incluido el recuerdo. La respuesta HTML redirige al login con un
mensaje; para JSON se devuelve 401. No hay bypass administrativo. Las otras sesiones
también encontrarán la comprobación en su siguiente petición; no se implementó un
panel de revocación global ni la gestión administrativa de estados.

El middleware también cubre páginas públicas y de invitados cuando reciben una
sesión inactiva: la limpia una vez y evita bucles de redirección. Después de salir,
el visitante conserva acceso a las lecturas públicas. Las respuestas de sesión y
formularios de autenticación usan `Cache-Control: no-store, private`. No se promete
revocación retroactiva de una respuesta ya autorizada ni de sus píxeles en pantalla.

## Límites y errores

- Login: cinco fallos por correo normalizado + IP durante 60 segundos. El éxito
  limpia ese contador. El bloqueo muestra el tiempo restante; para JSON es 429.
- Adicionalmente, máximo 30 POST de login por minuto/IP, incluso malformados.
- Registro: máximo diez POST por minuto/IP, incluso inválidos.
- Los límites de transporte devuelven 429 y Retry-After. Las claves no almacenan
  correos o IP en texto plano: se derivan mediante SHA-256 y el mecanismo nativo.
- Validación HTML: regreso al formulario y mensajes en español. Solo se conservan
  nombre/correo como strings acotados y la opción booleana de recuerdo. Contraseñas,
  confirmación, tokens, arrays y campos internos no se conservan en old input.
- Los valores conservados se escapan con Blade. No se usa localStorage ni
  sessionStorage para autenticación, contraseñas o tokens.

## Rutas y alcance

| Acceso | Rutas |
| --- | --- |
| Público | GET `/`, `/comunidad`, `/profesionales`, `/articulos`, `/ayuda` |
| Invitados | GET/POST `/iniciar-sesion`; GET/POST `/registro` |
| Usuario activo | GET `/inicio`, `/mensajes`, `/lexa`, `/guardados`, `/perfil`, `/configuracion` |
| Usuario autenticado, con CSRF | POST `/cerrar-sesion` |

Se conserva el nombre `home` y una única definición de `/inicio`. Los nombres GET
`login`/`register` se conservan para enlaces; sus formularios usan `login.store` y
`register.store`. Los invitados se redirigen al login y los autenticados que intentan
abrir formularios de invitados se redirigen a Inicio. Landing ya no enlaza al
inexistente `/dashboard`. La ruta técnica `/up` nativa de Laravel no cambia.

Comunidad, Mensajes, LEXA, artículos, profesionales, guardados y perfiles siguen
mostrando sus demos. Esta integración no convierte sus acciones en persistencia real
ni publica endpoints de esos dominios.

## Cambios de interfaz

Se conservan tipografía, paleta, banner, clases de campos, estructura responsive y
animaciones. El registro sustituye usuario/fecha de nacimiento por nombre y añade
confirmación; esos datos anteriores no tenían contrato con users. Las tarjetas
mantienen su altura mínima y pueden crecer para mostrar errores sin recortarlos.

Los formularios incluyen CSRF, autocomplete apropiado, errores asociados al campo,
password seguro incluso sin JavaScript y un estado de envío que evita doble submit.
Turbo no intercepta los POST de autenticación. El menú distingue invitados y usuarios;
su salida y el modal existente de Configuración usan el POST real de logout.

El CSS de login ya estaba referenciado por el layout pero faltaba en las entradas
de Vite: se añadió esa entrada, sin cambiar estilos ni dependencias.

OAuth y recuperación de contraseña siguen pospuestos, marcados como no disponibles.
Los términos aún apuntaban a `#`, sin documento publicado. Se conserva su espacio
visual como pendiente deshabilitado; no se solicita ni se registra una aceptación
de un texto inexistente. Publicar información legal y definir consentimiento real
son condiciones de producto pendientes antes de producción.

## Pruebas y verificación

- Las pruebas de BD heredan el guard de Tests\TestCase, que confirma testing/mysql,
  nombre configurado y SELECT DATABASE() igual a `plataforma_legal_v2_test` antes
  de crear fixtures. Datos, roles y sesiones de prueba se revierten por transacción.
- AuthenticationTest cubre registro válido/inválido, unicidad y normalización, hash,
  perfil opcional, rechazo de privilegios, rollback parcial, login correcto/incorrecto,
  remember, logout, destinos, públicos/privados, estados inactivos y modelo obsoleto.
- Se comprueban límites por identidad/IP, expiración y limpieza de contadores,
  escape de old input y defensa ante arrays y entradas sobredimensionadas.
- EnforcedCsrfToken reactiva únicamente en pruebas la comprobación real que Laravel
  omite normalmente en testing. Se prueban tokens ausentes, incorrectos y correctos
  en los tres POST. La aplicación conserva el middleware CSRF nativo, sin exclusiones.
- Una prueba usa el driver database, transporta cookies entre peticiones y reconstruye
  los servicios de sesión/guard para comprobar persistencia y destrucción del ID previo.
- Verificación visual de login de escritorio y registro a 390 × 844: mensajes reales
  de credenciales/confirmación, old input y ausencia de recorte horizontal. Sin errores
  de consola observados. El servidor temporal usó testing, sesiones cookie y caché
  array; no creó usuarios ni sesiones MySQL. Quedó detenido al finalizar.
- Resultado y controles finales: ver sección de cierre al final de este documento.

## Pendientes posteriores

Recuperación y verificación de correo, OAuth, textos legales y consentimiento,
gestión administrativa de cuentas y la integración HTTP de otros módulos no forman
parte de INTEGRACIÓN-01. El registro profesional debe pasar por los servicios y
permisos específicos auditados, nunca por campos adicionales de este formulario.

En producción se requieren HTTPS, cookies seguras, APP_DEBUG=false, una sesión y
caché persistentes compartidas entre instancias y aprovisionamiento revisado del rol
convencional. El driver database existente cubre sesiones y límites sin exigir Redis.
Configurar proxies confiables según la infraestructura para que la IP de los límites
sea correcta. Mantener las condiciones de retención, archivos, logs y operación de
AUDIT-02. No envolver toda la petición HTTP en una transacción externa.

## Inventario exacto

Creados (9):

- app/Http/Controllers/Auth/AuthenticatedSessionController.php
- app/Http/Controllers/Auth/RegisteredUserController.php
- app/Http/Middleware/EnsureActiveAccount.php
- app/Http/Requests/Auth/AuthFormRequest.php
- app/Http/Requests/Auth/LoginRequest.php
- app/Http/Requests/Auth/RegisterRequest.php
- docs/authentication.md
- tests/Feature/AuthenticationTest.php
- tests/Support/EnforcedCsrfToken.php

Modificados (9):

- app/Providers/AppServiceProvider.php
- bootstrap/app.php
- resources/views/components/header/account-menu.blade.php
- resources/views/pages/auth/login.blade.php
- resources/views/pages/auth/register.blade.php
- resources/views/pages/settings/index.blade.php
- resources/views/welcome.blade.php
- routes/web.php
- vite.config.js

## Cierre de validación

- Autenticación: 39 pruebas aprobadas, incluidas las comprobaciones reales de CSRF
  y de persistencia con sesiones MySQL.
- Suite Laravel completa final: **348 pruebas aprobadas, 10,572 assertions**, sin
  regresiones en las 309 pruebas de BACK-01 a BACK-07 y AUDIT-02.
- Sintaxis: 11 archivos PHP de aplicación/pruebas nuevos o modificados, sin errores;
  Pint aplicado y comprobación --test aprobada. Las vistas se renderizaron en pruebas.
- npm run build aprobado, incluida la entrada CSS del login; 25 pruebas frontend
  existentes aprobadas. No se modificaron archivos JavaScript, CSS ni dependencias.
- 16 rutas de aplicación, sin nombres ni pares método/dominio/URI duplicados;
  una sola definición fuente de /inicio.
- git diff --check aprobado. Inventario: nueve archivos nuevos y nueve modificados.
- Comprobación posterior: las 49 tablas de testing distintas de migrations quedaron
  vacías. Desarrollo conserva cero usuarios/perfiles, tres roles, 25 permisos y 26
  concesiones a roles; ambos entornos mantienen sus 32 migraciones anteriores.

No se preparó staging ni se realizó commit, push, merge o cambio de rama. Los
artefactos de build son ignorados por Git. No se crearon datos funcionales de
desarrollo; los otros worktrees se conservaron y el servidor temporal quedó detenido.
