# DEPLOY-02-C — Endurecimiento MySQL, migraciones y permisos Linux

2026-11-20. Worktree exclusivo D:/projects/plataforma-legal-V2-oracle-preparation, codex/deploy-02-a, base 52e5c0812a191be8973038a35bfaf4d2c668b534. No main/MEDIA/UX modificados. No recursos Oracle, servicios pagos, merge, push o commit. No migraciones de Lefenda ni exportaciones/usuarios/grants en bases existentes. Se aplico UNA migracion artificial en MySQL desechable, no las 39 migraciones del proyecto.

## 1. Resultado y alcance

Los gates SQL/permisos de 02-B tienen implementacion y ensayo positivo/negativo local. No equivale a acreditar la VM real ni autorizar staging. Ubuntu ARM64 emulado sobre amd64, Docker --network none sin puertos ni mounts; dos limites independientes: Unix socket y grants de servidor. Gemini deshabilitado, MySQL conservado, no R2/Redis gestionado/proveedores pagos.

Implementado:
- Identidad local acreditada con raiz de confianza del administrador Linux y evidencia del servidor, no un UUID escrito por el operador.
- Conexion SQL con esquema exacto y socket explicito; rechazo de host remoto, DB_URL, read/write replicas, debug, entorno o perfil inseguro.
- Migracion con plan/hash y verificacion dentro del mismo proceso y conexion, sin reconexion automatica.
- Perfiles SQL cerrados y permisos Linux probados con usuarios efectivos.
- Backup con raiz de confianza comun y exportacion SQL bajo usuario restringido; root solo orquesta lectura de objetos 0600 y cifrado.

## 2. Identidad y confianza

mysql-peer.py enroll requiere root y lee server-uuid de auto.cnf del datadir real, propiedad del UID mysql, sin escritura de grupo/otros. Obtiene PID/UID del daemon mediante SO_PEERCRED de un socket Unix real (no symlink), cuyo propietario es mysql. Padres del socket solo root/mysql y no escribibles por grupo/otros. No usa hostnames, nombres de instancia inventados ni un argumento --uuid.

Genera /etc/lefenda/mysql-identity.json, root:root 0644, padres root sin escritura de terceros. Registra socket/datadir, UUID derivado de auto.cnf, PID, UID, dispositivo e inode. O_EXCL impide sobrescribir una acreditacion anterior. Python corre con /usr/bin/python3 -I -S, sin importar PYTHONPATH/user site de un operador.

StagingDatabase.php revalida archivo y peer antes de SQL y despues; exige PDO mysql, host localhost Y socket exacto, base lefenda_staging, DATABASE()/@@server_uuid/@@datadir/CURRENT_USER reales. Socket/pid/inode/UID deben continuar coincidiendo. No se admite URL que seleccione otro transporte ni replicas. Cuenta autenticada exacta y grants deben cumplir perfil. UUID no es secreto ni por si solo prueba criptografica: se combina con evidencia OS y custodia root.

Raiz de confianza y limites: root, el administrador MySQL y el release revisado son confiables. No protege contra root comprometido, un daemon mysql comprometido o una acreditacion deliberadamente equivocada por el administrador. Antes de enroll se debe verificar por consola/SSH autenticado que es el nuevo servidor dedicado de staging, sin datos de desarrollo/produccion; no cohostear otras bases privadas. La herramienta no puede adivinar esa intencion ni demostrar que esta en Oracle. Tampoco es un guard automatico de cada request HTTP: es gate obligatorio de las operaciones y de activacion del release.

Un reinicio/reemplazo del socket invalida PID/inode y planes previos aunque UUID se conserve. Reacreditacion manual: administrador revisa identidad anterior, mismo UUID/datadir esperado, socket/UID y nuevo proceso; archiva registro anterior con permisos protegidos y genera uno nuevo. Cambio de UUID o datadir exige investigar, no actualizarlo para silenciar error. No auto-enroll al migrar ni al hacer backup. Hasta entonces operaciones fallan cerradas; alertar backups fallidos y ensayar este ciclo en VM.

## 3. Guard de configuracion

preflight.php exige produccion, debug off, APP_KEY presente, URL HTTPS, cache/sesiones database, cookies Secure/HttpOnly y SameSite lax/strict, queue database, generacion apagada y disco attachments local privado sin serve. APP_KEY solo se excluye del perfil backup, que no necesita la llave de cifrado de la aplicacion; su custodia es independiente.

staging.env.example ahora selecciona DB_HOST=localhost y DB_SOCKET=/run/mysqld/mysqld.sock. No hay contrasenas reales. El socket debe coincidir con la acreditacion real, no con un ejemplo copiado sin revisar. partial_revokes=ON se agrega a mysql.cnf y se comprueba efectivamente: evita que `_` de lefenda_staging actue como comodin en grants. No cambiar esta variable en un servidor existente durante esta intervencion.

## 4. Migraciones aprobadas en un proceso

migrate-approved.php acepta solo plan o apply; parser cerrado, sin forwarding de flags Artisan. No admite fresh/reset/refresh/rollback ni rutas alternativas. Conecta y verifica identidad/cuenta migrador, impide reconexion mediante callback que lanza error, toma GET_LOCK exclusivo de despliegue (advisory), lee ledger y hashes SHA256 de TODOS los ficheros pendientes. El plan incluye identidad del servidor, socket/PID, release actual, fingerprint de configuracion no secreta, existencia del repositorio y hash del ledger.

plan no ejecuta DDL. Si el repositorio no existe se registra esa situacion en el plan: su creacion por Laravel forma parte del apply aprobado. apply recalcula plan, exige igualdad exacta con archivo y SHA aprobado y QUIESCED_STAGING=YES; revalida identidad en el mismo app/Connection/PDO y pasa SOLO las rutas aprobadas a migrate --database=mysql --force. El parametro force es interno, no autoriza por si solo: primero son obligatorios todos los gates. Revalida despues. Una alteracion de archivo, configuracion, ledger, identidad o reutilizacion del plan se rechaza.

Sesion viva y reconexion bloqueada cierran la ventana entre comprobacion y migracion ante cambios externos de env/cache. Los grants limitan tambien a staging aunque una operacion intentara DDL fuera. El lock solo coordina operadores que lo respetan: congelar escrituras HTTP, workers, cron, DDL y cambios de release/config durante toda la ventana. No cambia ni bloquea a Antigravity.

Limites: no es un sandbox de PHP malicioso. Las migraciones y dependencias del release se revisan por semantica y deben permanecer inmutables durante la ventana. Quien tenga credenciales migradoras puede usar herramientas SQL fuera de este gate: limitar acceso humano y secretos. No se garantizan DDL atomicos MySQL; fallo parcial requiere inspeccion/restauracion reconciliada, nunca auto-down ni reintento ciego. Salida segura: hash/count del plan y estado generico; sin SQL, bindings, password o excepcion completa.

### Procedimiento posterior propuesto (NO ejecutado en servidor)

1. Revisar commit/migraciones, crear release por SHA, precompilar manifests, aplicar permisos y congelar ventana con backup recuperable acreditado.
2. Instalar herramientas privilegiadas revisadas en /usr/local/libexec/lefenda, root:root sin escritura de deploy/runtime. NO ejecutar como root un backup.sh modificable por deploy dentro del release. Copiar backup.sh, backup-export.php, StagingDatabase.php, mysql-peer.py y permissions.sh tras revision/hash; padres root. Mantener preflight/migrate-approved tambien en ubicacion revisada protegida. El ensayo usa /app root-owned dentro de imagen, no un directorio de usuario.
3. Root acredita socket/datadir reales con mysql-peer.py enroll. No aceptar datos de una cuenta existente para completar esta etapa.
4. Como deploy, desde release: cargar sin set -x un perfil migrador protegido root:deploy 0640 con credencial unica y APP_CONFIG_CACHE apuntando a un cache de migracion inexistente. No guardar/cachar credencial migradora en runtime. La APP_KEY y configuracion runtime provienen del .env protegido; no generarlas por release.
5. Ejecutar php /usr/local/libexec/lefenda/migrate-approved.php plan > archivo protegido; revisar cada fichero/hash/DDL y SHA256 del JSON exacto. Autorizacion explicita del responsable; no generar el hash y aprobarlo automaticamente en un script de despliegue normal.
6. Ejecutar QUIESCED_STAGING=YES php /usr/local/libexec/lefenda/migrate-approved.php apply ARCHIVO_PLAN SHA256_APROBADO. No invocar php artisan migrate por separado. Guardar resultado sanitizado.
7. Retirar perfil/entorno migrador del proceso de activacion, generar caches con cuenta runtime y ejecutar preflight.php lefenda_staging con identidad y grants runtime; activar symlink, reiniciar FPM/worker y smoke. Un cache equivocado falla cerrado. Validar de nuevo tras restart/reacreditacion.

## 5. Matriz MySQL

Usuarios locales distintos, secretos fuertes distintos por rol en produccion; fixture usa un secreto aleatorio sintetico, nunca versionado. Sin cuenta root en Laravel ni WITH GRANT OPTION/FILE/PROCESS/SUPER/SYSTEM_VARIABLES_ADMIN/CREATE USER.

| Perfil | Alcance permitido | Denegaciones verificadas |
|---|---|---|
| lefenda_runtime@localhost | SELECT, INSERT, UPDATE, DELETE en lefenda_staging.* | CREATE/ALTER/DROP tablas, CREATE USER, lectura fuera de esquema |
| lefenda_migrator@localhost | DML + CREATE, ALTER, DROP, INDEX, REFERENCES solo en staging | DDL en otros esquemas, GRANT |
| lefenda_backup@localhost | SELECT, SHOW VIEW, TRIGGER, EVENT solo en staging; SHOW_ROUTINE global excepcional | INSERT/DELETE/CREATE tabla y lectura de datos de otros esquemas |

El catalogo rechaza grants de mas o de menos y perfiles desconocidos, roles SQL/proxy/restricciones no previstos. No confundir roles MySQL con roles Spatie. grants.sql.template NO crea cuentas ni contiene passwords; DBA las aprovisiona en servidor nuevo por canal protegido. Verificar alcance literal con partial_revokes ON antes de conceder. Tambien se comprobo denegacion en lefendaXstaging (nombre que coincidiria con un `_` comodin).

Tradeoff nativo explicito: SHOW_ROUTINE permite leer definiciones de rutinas de TODO el servidor (MySQL >=8.0.20); no da DML global. TRIGGER/EVENT permiten tambien modificar esos objetos en staging, no son privilegios exclusivamente de lectura. Son necesarios para el dump nativo completo, por lo que backup es una cuenta offline confiable con secreto 0600, sin web/login y servidor dedicado. No se ocultan como permisos inocuos. Si se exige cero metadatos globales, redisenar exportacion separada por DBA antes de desplegar; no quitar rutinas silenciosamente del respaldo.

Fuentes: [privilegios MySQL 8.0](https://dev.mysql.com/doc/refman/8.0/en/privileges-provided.html), [partial revokes](https://dev.mysql.com/doc/refman/8.4/en/partial-revokes.html), [option files/login path](https://dev.mysql.com/doc/refman/8.4/en/option-files.html). No se presupone compatibilidad con MariaDB.

## 6. Usuarios y permisos Linux

permissions.sh --apply SHA requiere root, release canonico /srv/lefenda/releases/<40hex> existente y public real, sin symlinks en ubicaciones persistentes. Provisionar usuarios/grupo por administrador, NO ejecutado en host: deploy, lefenda, www-data, lefenda-backup y lefenda-public; runtime y Nginx miembros de lefenda-public. Worker corre como lefenda; no tiene un conjunto de permisos mas amplio que PHP.

| Ruta | Custodia / acceso |
|---|---|
| /srv/lefenda | root:root 0755, ningun write runtime |
| releases y release | deploy:lefenda 0750; Nginx solo travesia ACL en ancestros; backup rX al codigo sin escritura |
| current | symlink al release aprobado; permisos los determina el destino |
| public/ | deploy:lefenda-public dirs 0750/files 0640; Nginx lee estaticos sin acceso al backend |
| shared/ | root:lefenda 0710; no travesia de Nginx o backup |
| shared/.env | deploy:lefenda 0640, runtime lee, Nginx/backup no leen, runtime no modifica |
| shared/storage/ | lefenda:lefenda 0750; runtime/worker escriben; privados pueden ser 0600 |
| bootstrap/cache/ | deploy controla/precompila; runtime y backup leen manifests, no escriben codigo/cache |
| /var/backups/lefenda | root:lefenda-backup 0750; solo root crea; backup lee cifrados 0640 pero no altera |
| /etc/lefenda/mysql-identity.json | root 0644, directorio root 0755; lectores no lo modifican |
| backup.cnf | lefenda-backup 0600, solo usuario/password; no opciones de transporte |
| backup.env | root:lefenda-backup 0640, perfil no secreto de entorno; no APP_KEY runtime |

No ampliar permisos de los originales a Nginx ni chmod 777. El backupuser no lee directamente objetos 0600 o .env. Un orquestador root instalado/revisado lee ruta fija de objetos y ejecuta PHP/exporter como lefenda-backup con env limpio; el exporter usa perfil propio y cuenta SQL minima. ROOT es parte explicita de la confianza de archivado, no se concede sudo general a backup. Un servicio/timer root futuro debe usar estas copias root-owned, filesystem restringido y rutas fijas; no esta habilitado aqui.

backup.sh verifica procedencia root/no escritura de terceros de su script/padres, destino nuevo vacio bajo /var/backups/lefenda y ventana declarada. Exporter usa CNF 0600 de backup, parser cerrado user/password, password fuerte sin escapes ambiguos, socket forzado y /usr/bin/mysqldump. Se elimina --no-login-paths (no disponible en MySQL 8.0 observado); se aisla login file mediante MYSQL_TEST_LOGIN_FILE=/dev/null y entorno hijo cerrado. Antes/despues del dump se revalida identidad; no comparte PDO del dump, pero endpoint esta protegido por root/mysql, transporte explicito y grants; un cambio invalida el resultado. No garantiza seguridad frente a root/mysql comprometidos.

APP_KEY/configuracion runtime permanecen bajo custodia cifrada independiente; no se incluyeron en un backup.sql ni en perfil backup. Dump completo conserva tablas/objetos de DB y se empaqueta con objetos privados/hashes y age. Si algo falla, no se marca copia como recuperable ni se borra copia anterior. La consistencia SQL/Storage sigue requiriendo parada de escritores; el flag no la implementa.

## 7. Ensayo reproducible

```bash
docker buildx build --platform linux/arm64 --load -f ops/oracle/security-arm64.Dockerfile -t lefenda-security-arm64-qa .
docker run --rm --platform linux/arm64 --network none -e APP_ENV=testing -e DISPOSABLE_RESTORE=YES lefenda-security-arm64-qa
```

Requiere imagen base lefenda-laravel-arm64-qa construida segun 02-A; python3/acl se agregan al contenedor. Sin -v/--mount, -p, red host o credenciales reales. Se verificaron network=none, mounts=[], ports={}; MySQL solo socket, data dir nuevo en /tmp. El contenedor inicial testing simula APP_ENV=production en procesos CLI para ejercitar el guard real; esto NO cambia APP_ENV de repositorios existentes. Solo una migracion artificial reemplaza ficheros de migracion DENTRO del contenedor, nunca en el host. No se ejecutan las 39 migraciones de Lefenda.

Resultados observados bajo ARM64 emulado, PHP 8.3.6/MySQL 8.0.46:
- Identidad root acreditada; preflight positivo, incluido ejecutado como usuario Linux lefenda.
- Roles runtime/migrador/backup: 7, 4 y 6 denegaciones respectivamente (incluye reconexion bloqueada y esquema de nombre similar); DML/DDL permitidos de cada perfil aprobados.
- Cambio de configuracion global no cambia la conexion ya acreditada; desconexion impide reconexion automatica.
- Rechazo de entorno local, debug, DB distinta, host remoto, socket distinto, cola/generacion/cookie/key incorrectas, registro escribible, PID/UUID incorrectos y grants excedidos/incompletos.
- Parser de migracion rechaza fresh, hash incorrecto, fichero cambiado, replay y cuenta runtime; antes del apply autorizado no existe approved_probe. Un apply sintetico autorizado crea solo ese fixture y su repositorio.
- Accesos reales mediante runuser/cat/escritura: Nginx lee public y no secretos/privados/backend; runtime lee .env y escribe logs, no codigo/.env/cache; deploy controla codigo; backup lee codigo/cifrado, no .env/objetos raw ni altera archivos de backup; runtime/deploy no escriben identidad protegida.
- Exportacion con usuario SQL backup y archivado root cifrados completados; age descifra y manifests SQL/objetos verifican. CNF con host remoto rechazado.
- Mensaje final: SECURITY QA PASSED: isolated MySQL identities/grants, synthetic migration, Linux denials, accredited encrypted backup.
- Parsers Nginx/FPM/MySQL, sintaxis PHP/Bash/Python, Pint de PHP afectado y whitespace verificados.

Incidencias del desarrollo conservadas como evidencia externa: opcion mysqldump no soportada corregida; primera ejecucion bajo UID PHP requirio manifests precalculados legibles, corregido en fixture. No se relajaron guards ni se uso root como cuenta SQL de la aplicacion para pasar pruebas. Las denegaciones esperadas no se presentan como fallos de suite. No se repitio suite Laravel/frontend completa: no cambian contratos funcionales; resultados anteriores siguen siendo evidencia de su etapa, no nuevos resultados de C.

El ensayo C comprueba exportacion/cifrado/hashes, no una nueva restauracion completa de todo el dominio ni una VM real. El ensayo 02-A previo acreditaba restauracion sintetica de 58 tablas/39 migraciones y citas/autorizacion; no sumar ambas evidencias como una recuperacion operativa real.

## 8. Archivos y estado

Nuevos C: StagingDatabase.php, mysql-peer.py, migrate-approved.php, backup-export.php, grants.sql.template, permissions.sh, backup.env.example, security-arm64.Dockerfile, security-checks.php, rehearse-security.sh, rehearse-permissions.sh (todos en ops/oracle), y este informe.

Actualizados de preparacion previa: preflight.php, backup.sh, mysql.cnf, staging.env.example y rehearse-restore.sh. El ultimo queda como fixture estructural independiente; no intenta invocar backup productivo sin acreditacion. Documentos 02-A/02-B conservan historia con aviso de sustitucion de gates por 02-C.

No se modificaron migraciones del proyecto, config Laravel, MEDIA, UX ni datos existentes. Todos los archivos de preparacion siguen pendientes sin staging. No se prepara commit hasta revision del conjunto y de las excepciones de privilegios/operacion root.

## 9. Riesgos y proxima intervencion

Pendientes antes de integrar: revision del codigo de gates y perfil de privilegios, empaquetado root-owned y politica de reacreditacion; integracion aislada con MEDIA y snapshot UX aprobado; regresiones combinadas sobre el commit definitivo. Los grants actuales corresponden a tablas/migraciones vigentes: futura DDL sobre vistas/rutinas requiere nueva revision, no ampliar permisos automaticamente.

Pendientes antes de VM/staging: verificar Always Free por SKU sin PAYG/cobros opcionales, capacidad ARM y volumen; provisionar cuentas/ACL en servidor nuevo y probarlas alli; simular reboot y reenroll; FPM/Nginx/systemd/cron en funcionamiento, HTTPS real, smoke y copia externa restaurada con RPO/RTO medidos. root/libexec, claves y backups no se promueven desde datos de desarrollo. La independencia real de custodios y cuenta Oracle no queda acreditada en Docker.

Siguiente intervencion recomendada: revisar este hardening y preparar snapshot/commit local de DEPLOY, luego ensayo de integracion y recuperacion operativa sobre el codigo final, sin crear Oracle todavia. No hay licencia, servicio, API o suscripcion de pago introducida. Si no se pueden garantizar custodia root/dedicacion del MySQL y minimos de operacion, detener el despliegue.
