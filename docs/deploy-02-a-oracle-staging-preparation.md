<!-- Operaciones productivas de MySQL, backup, permisos y migraciones: usar DEPLOY-02-C; los ejemplos anteriores de comandos independientes quedan sustituidos. -->
# DEPLOY-02-A â€” PreparaciÃ³n tÃ©cnica Oracle staging $0

Fecha 2026-11-20. Base main 52e5c0812a191be8973038a35bfaf4d2c668b534, limpio/sincronizado al inspeccionar. Rama codex/deploy-02-a, worktree D:/projects/plataforma-legal-V2-oracle-preparation, creado limpio desde ese commit. MEDIA integracion/media-02-b en 1eb72d4 solo inspeccionada, no integrada. DEPLOY-01 se consultÃ³ en el worktree deploy-audit sin modificarlo; aÃºn es un informe sin commit, no estÃ¡ en este HEAD.

Estado actualizado: Docker Linux amd64 disponible e imagen ARM64 confirmada; se repitieron PHP 8.3.6 y requisitos Composer satisfactoriamente. El usuario reporta build y ensayo estructural aprobados. Se prepara y ejecuta una prueba adicional del esquema Laravel (seccion 9). Todo ARM64 se ejecuta bajo emulacion en host amd64; no es una VM Oracle ni un benchmark nativo. Sin recursos cloud, exportaciones de bases existentes, commit, merge o push.

## 1. Archivos y alcance

Todos nuevos, sin cambios al cÃ³digo funcional:

- ops/oracle/.gitattributes: fuerza LF en los archivos de operación; se corrigió un CRLF detectado al repetir bash -n.
- ops/oracle/nginx.conf.template: public/, ACME HTTP, HTTPS, solo index.php ejecutable, dotfiles y backups denegados; log omite query string.
- ops/oracle/php-fpm.conf: pool lefenda, socket restringido, ondemand hasta cuatro procesos, 256 MB/proceso, lÃ­mites de ejecuciÃ³n/upload conservadores.
- ops/oracle/mysql.cnf: loopback para MySQL/mysqlx, sin local_infile, 40 conexiones, buffer pool 512 MB.
- ops/oracle/lefenda-worker.service: un worker database, un intento, lÃ­mite 384 MB/50% CPU; sin root, filesystem mayormente solo lectura.
- ops/oracle/lefenda.cron: schedule:run con flock, sin activar tareas SIDOF por sÃ­ mismo.
- ops/oracle/logrotate.conf: diario, siete rotaciones, umbral 10 MB, modo 0640. copytruncate puede perder unas lÃ­neas: aceptable para staging, no auditorÃ­a durable.
- ops/oracle/staging.env.example: plantilla sin secretos; producciÃ³n/debug false, cookies seguras, cache/session database, queue database, correo array y Gemini deshabilitado.
- ops/oracle/preflight.php: consulta de solo lectura que exige lefenda_staging, MySQL local efectivo, environment production, debug off, llave/password presentes, Gemini apagado y queue database. No imprime DSN/password/key.
- ops/oracle/arm64.Dockerfile: prueba Ubuntu 24.04 ARM64 con assets Node 22, PHP 8.3 y herramientas MySQL/age; imagen de QA, no servidor productivo ni recursos cloud.
- ops/oracle/backup.sh: dump Ã­ntegro + objetos + hashes, cifrado age, destino nuevo/vacÃ­o y cnf protegido; exige ventana de quiescencia confirmada.
- ops/oracle/rehearse-restore.sh: dos mysqld desechables sin TCP dentro de contenedor, fixture sintÃ©tico con FK/JSON/view/trigger/procedure/event, backup/cifrado/restore y comprobaciones. No usa bases o sockets del host.
- Este documento.

No hay contraseÃ±as, certificados privados ni IP de una VM inventada. 127.0.0.1 es loopback, no direcciÃ³n de un servidor contratado. STAGING_HOST es un marcador que debe sustituirse explÃ­citamente, no un dominio reservado o supuesto disponible.

## 2. Compatibilidad: evidencia y pendientes

composer.json requiere PHP ^8.2, Laravel ^12 y fija platform 8.2.12 para resoluciÃ³n; PHP 8.3 de Ubuntu 24.04 es candidato, no equivalencia probada. No cambiar composer.lock. Composer 2 con check-platform-reqs --no-dev debe validar runtime real. Requisitos: ctype, curl, dom/xml, fileinfo, filter, hash, mbstring, openssl, pcre, PDO/pdo_mysql, session y tokenizer; zip/unzip para Composer, intl/bcmath/gd conservadores, OPcache para FPM, pcntl en worker. Validar php -m tanto CLI como FPM y permisos efectivos.

Dependencias PHP principales (framework, permission, iconos, Tinker) no declaran binarios ARM propios en composer.json; eso no prueba cada dependencia transitiva. Revisar composer audit y scripts; no ejecutar composer create-project/update para desplegar. El script post-create-project puede migrar: no utilizarlo aquÃ­.

package-lock contiene @esbuild/linux-arm64 0.25.12, @tailwindcss/oxide-linux-arm64-gnu y -musl 4.3.3, lightningcss-linux-arm64-gnu y -musl 1.32.0. Rollup estÃ¡ sustituido por @rollup/wasm-node 4.64.12. Turbo/Alpine son JS. La presencia de paquetes ARM solo acredita intenciÃ³n del lock: ejecutar npm ci dentro de Linux ARM glibc, sin copiar node_modules de Windows ni deshabilitar optional deps; Node 22 es candidato compatible con Vite 6. No copiar vendor/binarios de Windows. Compilar sobre arquitectura destino o emulaciÃ³n, no confundir el build de Windows con ARM.

MySQL se mantiene: paquete Oracle MySQL Community provisto por Ubuntu, confirmar SELECT VERSION(), charset/collation y que no es MariaDB antes de migrar. Probar CHECK/FK, JSON, Ã­ndices/full-text del RAG y concurrencia de adjuntos. No elegir Autonomous Database de Oracle como sustituto MySQL. Sin DB administrada ni Redis adicional.

Referencias: [Laravel 12 deployment](https://laravel.com/docs/12.x/deployment), [Docker multiplataforma/emulaciÃ³n](https://docs.docker.com/build/building/multi-platform/), [MySQL backup](https://dev.mysql.com/doc/refman/8.4/en/mysqldump.html). ImÃ¡genes/tags y paquetes apt flotan: al completar ensayo registrar digests, versiÃ³n de Ubuntu, PHP/MySQL/Node/Composer y hashes de lock en el acta; todavÃ­a no se promete reproducciÃ³n binaria con tags sin digest.

## 3. Prueba ARM64 reproducible (baseline completada)

Desde la raÃ­z de este worktree, con motor Docker Linux listo, sin montar .env, datos ni sockets:

```bash
docker buildx build --platform linux/arm64 --load -f ops/oracle/arm64.Dockerfile -t lefenda-arm64-qa .
docker run --rm --network none lefenda-arm64-qa
docker run --rm --network none -e APP_ENV=testing -e DISPOSABLE_RESTORE=YES lefenda-arm64-qa bash ops/oracle/rehearse-restore.sh
```

El Dockerfile copia fuentes seleccionadas explÃ­citamente; no copia .env, storage, vendor o node_modules locales. npm/composer/apt necesitan red durante build; el ensayo posterior no. No hay puertos publicados ni volÃºmenes del host. No aÃ±adir --privileged o montar /var/run/docker.sock al contenedor. Acreditar uname -m=aarch64 en el contenedor; si emulado, registrar QEMU y host x86_64. No equivale a medir la VM Oracle.

Antes del gate final tambiÃ©n ejecutar frontend en Node ARM y pruebas Laravel en contenedor de testing dedicado con config y SELECT DATABASE verificados, sesiones/cache array y storage externo; no heredar .env.testing de Antigravity. Este QA de compatibilidad no incluye suite funcional completa ni acredita la autorizaciÃ³n de todos los mÃ³dulos.

## 4. VM gratuita: preflight manual exacto

Consultar [Always Free](https://docs.oracle.com/en-us/iaas/Content/FreeTier/freetier_topic-Always_Free_Resources.htm) y [condiciones](https://www.oracle.com/cloud/free/faq/) el dÃ­a del alta. DEPLOY-01 registra 2 OCPU/12 GB agregados, 1500 OCPU-h/9000 GB-h, 200 GB boot/block y cinco backups incluidos; confirmar en la cuenta real, no usar cifras histÃ³ricas ni crÃ©ditos 300 USD/30 dÃ­as como financiaciÃ³n recurrente.

Gates antes de confirmar creaciÃ³n: cuenta Free sin upgrade PAYG; tarjeta solo para verificaciÃ³n aceptada por titular, posibles autorizaciones temporales; home region con capacidad A1 elegible; Ubuntu ARM64 elegible sin licencia pagada; propuesta 2 OCPU/12 GB y boot 50 GB + datos 50 GB dentro de agregados. EstimaciÃ³n mensual cero por cada shape, imagen, volumen, rendimiento, IP/red y backup. No crear balanceador, NAT gateway, disco de rendimiento adicional, DB gestionada, marketplace de pago ni R2. Si un recurso no estÃ¡ acreditado gratuito o exige upgrade, parar. Guardar evidencia de la selecciÃ³n sin IDs/secretos pÃºblicos. Quotas/IAM limitan recursos; budgets solo alertan, no garantizan corte de factura.

SSH por llaves, usuario de despliegue distinto de root y de lefenda (runtime sin login), MFA en OCI; preservar sesiÃ³n SSH durante ensayo de firewall. Security List/NSG y UFW: permitir primero SSH solo desde origen autorizado, luego 80/443; denegar resto incluyendo 3306/33060. No exponer artisan serve, Vite, MySQL ni panel de depuraciÃ³n. No habilitar firewall a ciegas desde consola remota. Subdominio gratuito controlado por el titular y ACME, sin compra obligatoria de dominio. Capacidad/reclamaciÃ³n por inactividad son riesgos aceptados: no generar carga artificial ni crear cuentas mÃºltiples para evadirlos.

## 5. Preparar sistema y persistencia (no ejecutado)

En Ubuntu aprobado instalar paquetes php8.3-{fpm,cli,mysql,mbstring,xml,curl,zip,intl,bcmath,gd}, nginx, mysql-server/client, unzip/git/ca-certificates, age, certbot y plugin nginx; validar disponibilidad ARM/versiones. Node 22 y Composer 2 desde distribuciÃ³n oficial verificada por checksum/firma, sin curl-pipe-shell. OPcache y pcntl comprobar, no asumir extensiones por nombre de paquete.

Estructura:

```text
/srv/lefenda/releases/<SHA>/    propietario deploy, cÃ³digo solo lectura para runtime
/srv/lefenda/current           symlink a release aprobado
/srv/lefenda/shared/.env       0640 deploy:lefenda; no web, no Git
/srv/lefenda/shared/storage/   0750 lefenda:lefenda; volumen persistente
/var/backups/lefenda/          0700 backup; fuera de public y de releases
```

Crear framework/{views,sessions,cache/data}, logs, app/attachments y app/private. Symlink storage de cada release hacia shared/storage y .env hacia shared/.env. bootstrap/cache pertenece a cada release; deploy genera cachÃ©s, runtime solo lectura. Nunca compartir cachÃ©s entre releases/entornos ni ejecutar key:generate en cada release. Custodiar APP_KEY fuera del servidor, cifrada con destinatario distinto del backup de datos.

Instalar pool FPM en /etc/php/8.3/fpm/pool.d/lefenda.conf y MySQL en /etc/mysql/mysql.conf.d/lefenda.cnf; comparar con defaults y probar php-fpm8.3 -t y mysqld --validate-config antes de reiniciar. No sustituir /etc global sin revisiÃ³n. Instalar unidad 0644 root, cron 0644 root y logrotate; runtime crea logs 0640. FPM sockets www-data 0660; no chmod 777. Ajustar RAM tras mediciÃ³n, no prometer capacidad de cuatro FPM con cualquier carga. queue retry_after debe superar timeout=60 y tiempo de parada; comprobar valor real de config/queue.php.

TLS inicial: configurar solo vhost HTTP que sirva /.well-known/acme-challenge desde /var/www/acme y rechace el resto, obtener certificado ACME con el hostname real; luego renderizar STAGING_HOST en plantilla y activar HTTPS. No activar bloque TLS antes de existir certificado. No envsubst sin lista (romperÃ­a $uri/$query_string de Nginx). nginx -t antes de reload; certbot renew --dry-run y monitor de expiraciÃ³n. Probar cookies Secure/HttpOnly/SameSite y renovaciÃ³n. HSTS despuÃ©s de validar dominio, no incluir subdominios ajenos. staging restringido por VPN/allowlist antes de datos, no confiar en robots.txt.

## 6. Despliegue por commit, sin automatizar DDL

1. Confirmar SHA completo aprobado y repositorio de origen; descargar git archive/checkout separado por SHA en releases, no git pull sobre current. No integrar MEDIA de oficio. Registrar hash del artefacto y de locks.
2. Preparar entorno .env desde plantilla con secretos fuera de Git. APP_ENV=production incluso staging pÃºblico, APP_DEBUG=false, APP_URL HTTPS, DB lefenda_staging. Crear DB vacÃ­a y usuarios separados: migrador DDL local y runtime DML mÃ­nimo. No se incluyen passwords ejemplo ni comandos que los impriman.
3. En release: composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-scripts; luego Composer platform check y package:discover con entorno staging listo; npm ci y npm run build en ARM. Revisar scripts antes. No copiar artifacts de Windows a ciegas.
4. Enlazar storage/.env persistentes, generar manifest y permisos. Ejecutar php ops/oracle/preflight.php lefenda_staging y php artisan migrate:status. Fallo del guard, nombre, host o entorno: parar. La CLI exige runtime real; no es un guard de testing. No ejecutarlo aquÃ­ contra desarrollo.
5. Revisar migraciones pendientes por archivo y plan de compatibilidad. No fresh/refresh/reset/rollback/wipe, no seed global automÃ¡tico ni operadores reales. CatÃ¡logos solo por aprobaciÃ³n independiente. Generar backup completo y exigir restauraciÃ³n previamente acreditada.
6. Ventana de mantenimiento: rechazar escrituras HTTP, parar worker y cron, esperar solicitudes/transacciones activas y evitar DDL concurrente. artisan down solo no para workers/cron ni conexiones externas. DespuÃ©s de respaldo, migrador temporal exclusivamente local; repetir guard con esa conexiÃ³n, ejecutar migrate --force Ãºnicamente con lista/plan aprobado, retirar credenciales DDL y volver a runtime. No almacenar cache con credencial migradora.
7. Ejecutar config:cache, route:cache, view:cache con runtime/entorno definitivo, repetir guard (detecta cache equivocada). Registrar quÃ© cachÃ© es release-local y vistas en storage compartido; serializar activaciÃ³n y limpiar/recompilar vistas despuÃ©s de cualquier cambio de release.
8. Con servicios detenidos y smoke previo: crear current.next al SHA validado y moverlo atÃ³micamente a current; current.next no debe preexistir, no sobrescribir directorios. Guardar SHA anterior. Reload FPM y reiniciar worker (la unidad usa current), activar cron y up tras smoke. No asumir que queue:restart entre symlinks cambia cwd de procesos existentes.
9. Smoke: /up y landing HTTPS; login/logout CSRF, sesiones, perfiles, Comunidad, Mensajes/polling, lectura/denegaciÃ³n entre usuarios de adjuntos, LEXA GET/historial con generaciÃ³n apagada; cuenta y datos solo sintÃ©ticos. Verificar 401/403/404/419/503 sin privados, permisos editor/revisor/operador y errores JS/Turbo. No Gemini/SIDOF real.
10. Rollback: si esquema sigue compatible, detener escrituras/worker/cron, cambiar symlink al release anterior aprobado, reconstruir cachÃ©s/vistas, reload FPM/reiniciar worker y smoke antes de abrir. Si esquema incompatible, no hacer down() automÃ¡tico ni restaurar dump sobre escrituras recientes: incidente, ventana cerrada y restauraciÃ³n reconciliada por responsable. Retener release y backup previos.

El lÃ­mite HTTP 10 MB es deliberadamente inferior a algunos mÃ¡ximos de dominio (PDF/vÃ­deo), no elimina reglas: staging restringido debe comunicar lÃ­mites y probar 413/422. Este main no tiene cuotas MEDIA/globales; no abrir uploads/registro pÃºblico hasta integrar y validar controles, o restringir a testers y cupo sintÃ©tico monitorizado.

## 7. Respaldo integral y restauraciÃ³n

backup.sh debe ejecutarse como usuario backup autorizado, sin shell tracing, con archivo cnf 0600 fuera de repo que contenga host/socket/user/password (sin database en [client], incompatible con algunas herramientas). Destino existente vacÃ­o Ãºnico; no sobrescribe copias. Gate QUIESCED_STAGING=YES no prueba por sÃ­ mismo ausencia de escritores: responsable debe acreditarla. --single-transaction protege InnoDB, no DDL concurrente ni consistencia con Storage. Revisar privilegios TRIGGER/EVENT/rutinas y versiones cliente/servidor antes del dump. La cuenta de backup no es runtime.

El bundle contiene esquema/datos de todas las tablas de lefenda_staging, rutinas/eventos/triggers, SQL Ã­ntegro y todos los objetos attachments, manifiestos SHA256, cifrado age y hash del ciphertext. Tablas de metadata, chunks/citas/historial no se sustituyen por conteos/JSON. Incluir ademÃ¡s otros discos privados si el despliegue los habilita: el script actual solo cubre attachments, segÃºn el uso actual; ampliar inventario antes de llamarlo backup completo con nuevos discos. No enlaces simbÃ³licos dentro de objetos.

APP_KEY y configuraciÃ³n necesaria se respaldan cifradas aparte bajo custodia independiente (dos destinatarios/custodios, sin llaves privadas dentro del backup). Registrar SHA release, versiones DB/PHP, charset, disco, fecha y punto de quiescencia como acta cifrada. Script no exporta automÃ¡ticamente .env: custodia es paso obligatorio manual. Asegurar que la copia completa cifra ambos conjuntos; conservar hash y evidencia fuera de OCI con capacidad propia existente. No R2 ni bucket pagado. Cupo backup 5 GB propuesto, RPO 24 h, RTO mejor esfuerzo; no rotar Ãºltima copia vÃ¡lida, no eliminar evidencia para ahorrar. Pausar altas si no cabe.

Ensayo desechable preparado: dos mysqld aislados con --skip-networking, sin secretos reales; importaciÃ³n en servidor destino distinto, comparaciÃ³n de datos/DDL/vista, presencia de trigger/rutina/evento, hash de objetos y recuperaciÃ³n de configuraciÃ³n cifrada. Este ensayo NO se ejecutÃ³ por indisponibilidad del motor Docker; no constituye prueba de recuperaciÃ³n. DespuÃ©s debe aÃ±adirse ensayo con esquema completo de Laravel y datos sintÃ©ticos para autorizaciÃ³n de descargas y citas, no solo fixture estructural. No emplear desarrollo para completarlo.

## 8. ValidaciÃ³n realizada y gates

| VerificaciÃ³n | Resultado local |
|---|---|
| Git / aislamiento | Nuevo worktree desde main; otros sin modificar |
| PHP preflight sintaxis | php -l aprobado, sin conectar DB |
| Bash backup/restore | bash -n aprobado bajo WSL x86_64 |
| Dependencias ARM del lock | Paquetes linux-arm64 presentes, no ejecutados |
| Docker ARM build/runtime | Baseline APROBADA, ARM64 emulado; fallo anterior de motor Docker resuelto |
| Nginx / FPM / MySQL templates | Parsers ARM64 aprobados en contenedor; servicios/reboot/HTTPS publico pendientes |
| systemd-analyze verify | Incompleto: falta /usr/bin/php; avisos por permisos NTFS, no certificado como aprobado |
| Dump + descifrado + restauracion estructural | APROBADA segun resultado comunicado por usuario; no equivale a toda Lefenda |
| Frontend ARM | 149 pruebas y Vite aprobados; Laravel y restauracion ampliados en seccion 9 |
| Whitespace | Comprobado incluyendo archivos nuevos antes de entrega |

RestricciÃ³n definitiva: no PAYG, no opciones de pago, no R2, no Redis administrado, no dominio comprado ni API paga; Gemini off por defecto. Alertas no son tope de gasto, operaciÃ³n y backups exigen responsable. El software autogestionado mantiene costo de servicios $0 solo con recursos elegibles y almacenamiento disponible; no garantiza continuidad ni ausencia de cambios comerciales.

PrÃ³xima acciÃ³n manual: habilitar el motor Docker Linux local o facilitar un host ARM64 desechable sin datos reales, ejecutar los tres comandos QA y revisar fallos. Completar ensayo de esquema Laravel/restore, config y smoke antes de autorizar creaciÃ³n de VM. DespuÃ©s el titular verifica elegibilidad/capacidad Oracle y confirma un plan de creaciÃ³n $0; esta entrega no crea nada ni acredita readiness final.

## 9. Continuacion: ARM64 real emulado y ensayo Laravel desechable

Fecha: 2026-11-20. Evidencia comunicada por el usuario: build linux/arm64, runtime PHP 8.3.6, Composer y comando original rehearse-restore.sh completado con mensaje `Synthetic full SQL + objects + independent encrypted configuration restoration verified.` Se registra como ensayo de fixture estructural SQL/objetos/cifrado, NO restauracion completa de Lefenda ni garantia de recuperacion en Oracle.

Evidencia repetida directamente en esta continuacion: Docker engine linux/amd64 (info: x86_64), imagen base arquitectura arm64, runtime `uname -m` = aarch64, PHP 8.3.6 y check-platform-reqs --no-dev satisfactorios. Imagen base inspeccionada: sha256:a4a2e12133c511f2dc320bedc9f54206764757dfd3c430fe938fd9a95049902e. Se usa emulacion ARM64 sobre host amd64; no se mide rendimiento nativo.

### Nuevos archivos de QA

- ops/oracle/laravel-arm64.Dockerfile: etapa Node ARM con suite frontend completa y Vite; imagen PHP extendida con require-dev, tests y bootstrap oficial, sin copiar secretos del host.
- ops/oracle/laravel-qa-bootstrap.php: exige contenedor, flags testing/deschable, base lefenda_arm64_test, socket MySQL y storage temporales, caché/sesiones array y queue sync; SELECT DATABASE antes de migraciones/tests; bloqueo de peticiones HTTP externas.
- ops/oracle/laravel-qa-state.php: migra solo esquema inicialmente vacio; captura hash del DDL, conteo y hash de todas las filas ordenadas para cada tabla. No imprime contenidos ni credenciales.
- ops/oracle/rehearse-laravel.sh: dos mysqld con datadir nuevo y sockets separados, sin TCP; migra esquema completo de main, ejecuta suites relacionadas, crea fixture persistente, exporta solo esta base sintetica, cifra/restaura en segunda instancia, compara todas las tablas y prueba acceso tras recuperacion. Dos claves age distintas para datos y config; no simula custodios humanos independientes.
- ops/oracle/RestoredApplicationTest.php: fase seed/restore con dos cuentas sinteticas, fuente/chunk artificial, generacion FakeAiProvider y cita validada por servidor, adjunto privado, lectura autorizada y rechazo entre usuarios. No hace llamadas Gemini/SIDOF. La fuente artificial no es legislacion real ni aprobacion de un corpus humano.

No se modifican TestCase.php, tests existentes, dependencias, migraciones ni servicios de negocio. MEDIA no se integra. Parte de la suite de main fija el antiguo nombre de DB; no se cambia ese contrato en esta tarea ni se usa la base compartida para sortearlo. Se eligen clases con guard configurable: Lexa*, CommunityMediaIntegrationTest, MessagingIntegrationTest, EditorialMediaIntegrationTest y ProfileIntegrationTest. Es suite focalizada, no suite Laravel completa.

### Comandos reproducibles

Con Docker Linux en ejecucion, desde la raiz de este worktree (o su ruta equivalente en WSL):

```bash
# Baseline desde este mismo checkout; sin importar vendor/node_modules del host.
docker buildx build --platform linux/arm64 --load -f ops/oracle/arm64.Dockerfile -t lefenda-arm64-qa .
docker run --rm --platform linux/arm64 --network none lefenda-arm64-qa
# Ampliacion: dependencias/test frontend se compilan bajo ARM64.
docker buildx build --platform linux/arm64 --load -f ops/oracle/laravel-arm64.Dockerfile -t lefenda-laravel-arm64-qa .
docker run --rm --platform linux/arm64 --network none -e APP_ENV=testing -e DISPOSABLE_RESTORE=YES lefenda-laravel-arm64-qa
```

Build usa red para apt/npm/Composer; runtime no tiene red. No agregar -v/--mount, --network host, puertos -p ni socket Docker. Se inspecciono el contenedor activo: network=none, mounts=[], ports={}. mysqld escucha solo sockets internos y port=0; nunca recibe credenciales ni direccion de desarrollo, Codex o Antigravity. El nombre lefenda_arm64_test es exclusivo del namespace desechable, no una BD creada en el servidor local existente. Cache, sesiones, almacenamiento y claves quedan en /tmp/lefenda-laravel.<aleatorio>; la eliminacion del contenedor y traps limpian solo esos datos. No se detienen procesos o contenedores ajenos.

### Alcance y limites de la recuperacion ampliada

Se comparan los DDL y todas las filas de las tablas migradas de esta version, incluidas FK/indices y snapshots de citas; los ficheros privados se cifran con manifiesto SHA256. Despues se recuperan usuarios/roles/datos sinteticos y se revalida mediante kernel HTTP Laravel el historial/cita y acceso propietario/tercero. Eso acredita un ensayo sintetico de esta version, no un dump real, no cobertura exhaustiva de cada relacion poblada ni todos los estados de negocio.

El fixture estructural anterior cubre view/trigger/routine/event; el esquema Laravel se cubre separadamente con este ensayo. No copiar datos reales para ampliar cobertura. El propio sistema de adjuntos se prueba con sus servicios/policies, sin asumir que un hash prueba autorizacion. La prueba HTTP usa el kernel de testing, no navegador con Nginx/FPM/TLS ni sesion persistente production.

Gates restantes antes de VM: validar plantillas Nginx/FPM/MySQL efectivamente instaladas y systemd/cron, permisos del volumen, rutas y servicios tras reboot, HTTPS/renovacion y navegador, modelo de cuenta OCI y recursos Always Free, copia externa real y restauracion operativa/RPO/RTO, integracion posterior de cuotas y limites globales. No se autoriza creacion de recursos ni se promete SLA/$0 fuera de franquicias.

### Validacion de plantillas y seleccion de suites

Se detecto y corrigio en la plantilla Nginx la necesidad de server_names_hash_bucket_size 64 para el hostname de QA. Debe definirse una sola vez en contexto http, coordinando nginx.conf existente. Se verificaron nginx -t, php-fpm8.3 -t y mysqld --validate-config bajo ARM64 con certificado sintetico efimero para lefenda-qa.invalid (dominio reservado, no un servidor real). No se obtuvo certificado Let's Encrypt ni se abrieron listeners. El script nuevo ops/oracle/check-server-templates.sh reproduce el ensayo; no monta archivos del host.

```bash
docker run --rm --platform linux/arm64 --network none -e APP_ENV=testing -e DISPOSABLE_RESTORE=YES lefenda-laravel-arm64-qa bash ops/oracle/check-server-templates.sh
```

La primera seleccion por substring Lexa incluyo dos pruebas ajenas de Landing y termino con 114 pruebas, 1146 assertions y un fallo por guard del nombre legado de DB. No se considera pasada ni una regresion demostrada de ARM. Se reemplazo el filtro por un XML temporal con archivos explicitos; no se alteraron pruebas existentes ni se conecto a la base legacy para satisfacerlas. Los resultados definitivos se registran al finalizar el ensayo corregido.

### Resultados finales observados en esta continuacion

- Host Docker Linux amd64/x86_64; contenedor uname -m=aarch64: ARM64 emulado, no maquina ARM fisica.
- PHP 8.3.6 y requisitos Composer production/dev aprobados. MySQL de los contenedores: 8.0.46-0ubuntu0.24.04.5.
- Frontend bajo ARM64: 149 pruebas aprobadas y Vite 6.4.6 build aprobado.
- Laravel por archivos explicitos: 112 pruebas, 1121 assertions aprobadas. No suite completa del proyecto.
- Fixture previo al backup: 1 prueba, 12 assertions aprobadas; despues de restaurar: 1 prueba, 9 assertions aprobadas.
- Migracion normal sobre esquema desechable vacio: 39 migraciones/58 tablas; mismas 58 tablas y 39 registros de migraciones despues del restore.
- Comparacion de conteos y hashes de TODAS las filas, DDL canonico y metadatos efectivos de columnas aprobada; objetos privados/hashes, configuracion cifrada, citas persistidas y autorizacion propietario/tercero comprobados. Resultado: Full Laravel synthetic schema/data/objects/citations/authorization restore verified on aarch64.
- Nginx/FPM/MySQL: parsers de las plantillas aprobados con TLS sintetico interno. Selftest del comparador DDL: cuatro checks aprobados en Windows y ARM64. Pint de los tres PHP QA, sintaxis PHP/Bash y whitespace comprobados.

Se separaron ejecuciones para no repetir innecesariamente las 112 pruebas ya aprobadas: el restore final se ejecuto con QA_SKIP_RELATED=YES despues de corregir solo el comparador; las pruebas existentes y el codigo funcional no cambiaron. Comando de esa repeticion segura:

```bash
docker run --rm --platform linux/arm64 --network none -e APP_ENV=testing -e DISPOSABLE_RESTORE=YES -e QA_SKIP_RELATED=YES lefenda-laravel-arm64-qa
```

El comando normal sin QA_SKIP_RELATED sigue incluyendo suite focalizada y restore. No sumar fases como si fueran casos unicos de la suite.

Incidencia de comparacion, resuelta: MySQL imprime CHARACTER SET explicitamente tras importacion, aunque antes se heredaba del COLLATE. El diagnostico encontro 39 DDL con SOLO esa diferencia redundante, sin diferencias de conteos o datos. No se eliminaron tipos, collations, FK, CHECK ni AUTO_INCREMENT del comparador. Se conserva DDL raw para diagnostico, se normaliza solo esa declaracion redundante y se comparan tambien COLUMN_TYPE, defaults, nullability, extra, charset/collation y expresiones generadas efectivos. El selftest exige distinguir collation distinta, AUTO_INCREMENT distinto y CHECK distinto. No se declara igualdad byte a byte de SHOW CREATE TABLE, ni un parser SQL universal.

Se agrego .dockerignore para excluir .env, storage, vendor/node_modules, caches, dumps, claves, certificados, logs y artefactos locales del contexto de build. Se agrego check-server-templates.sh para reproducir el parsing con certificados sinteticos; nunca se montaron archivos privados del host. Datos de ensayo borrados al terminar los contenedores; imagenes QA locales conservadas para repeticion, sin borrar otros recursos Docker.

Evidencia externa al repositorio: logs deploy-arm-runtime, deploy-full-build, deploy-full-rehearsal-final, deploy-restore-diagnostic, deploy-verified-restore y deploy-server-templates-final en el directorio de evidencia local de Codex. El log historico inicial contiene el fallo del filtro y el primer fallo de comparacion: no confundirlos con el resultado final. No se versionan backups ni archivos de configuracion secreta.

Inventario actual: 20 archivos nuevos en este worktree (documento, .dockerignore y 18 archivos en ops/oracle). Sin modificaciones a main, MEDIA, TestCase, dependencias o migraciones. Sin staging, commit, merge, push o recursos cloud.

Pendientes de Lefenda completo: poblar y restaurar mas estados de negocio (Comunidad, Mensajes, expedientes retenidos, revisiones, permisos administrativos y versiones del corpus) en un ensayo integrado de mayor cobertura; ejecutar suite general con guards compatibles sin modificar DBs existentes. Las suites relacionadas cubren parte de esos contratos, pero no se afirma que cada relacion poblada haya sido restaurada en este fixture. Tambien faltan Nginx/FPM en ejecucion, systemd/cron, cookies production, navegador real/HTTPS ACME, reinicio, backup externo real y RPO/RTO bajo condiciones operativas. Antes de Oracle sigue siendo obligatorio verificar cuenta no-PAYG, elegibilidad gratuita y capacidad, limites agregados e indisponibilidad aceptada.
