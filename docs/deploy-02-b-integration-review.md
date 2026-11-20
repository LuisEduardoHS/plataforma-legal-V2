# DEPLOY-02-B — Revision de integracion

2026-11-20. Rama codex/deploy-02-a, HEAD 52e5c0812a191be8973038a35bfaf4d2c668b534. Se revisaron los 20 archivos nuevos de DEPLOY-02-A; este informe agrega un archivo documental (21 pendientes). No commit: quedan gates de seguridad/operacion abiertos. No merge/push/despliegue ni modificaciones de otros worktrees.

## Inventario auditado

.dockerignore excluye secretos, dumps, storage y artefactos; ops/oracle/.gitattributes fija LF. Dockerfiles copian fuentes seleccionadas y paquetes, sin env del host. Las rutas /app y /tmp son contrato interno del contenedor; /srv/lefenda y /etc son layout de servidor documentado, no rutas personales. El documento 02-A contiene rutas Windows historicas de evidencia, no requisitos ejecutables. No se identificaron claves, passwords reales o certificados privados en el inventario; las plantillas tienen campos vacios. Esta inspeccion no equivale a un escaner universal de secretos.

Los PHP QA/fixture/state, dos rehearsals y check-server-templates estan limitados al contenedor desechable. El ensayo exige testing, nombre exacto lefenda_arm64_test, socket temporal, cache/sesiones array y queue sync. Migraciones solo sobre esquema inicialmente vacio. Network none, mounts vacios, ningun puerto publicado y mysqld sin TCP fueron comprobados en 02-A. Las protecciones dependen tambien de invocar Docker como documentado: /.dockerenv por si solo no garantiza aislamiento ante un operador que cambie mounts/network.

nginx.conf.template, php-fpm.conf, mysql.cnf, worker, cron, logrotate y staging.env.example son propuestas de servidor; parsing no prueba accesos/arranque. backup.sh y preflight.php son herramientas de staging, no deben ejecutarse contra bases locales para validarlas.

## Hallazgos y gates

ALTO — ops/oracle/backup.sh, seleccion de conexion: comprueba SELECT DATABASE() tras seleccionar lefenda_staging, pero cnf puede apuntar a cualquier servidor que tenga ese nombre. No garantiza identidad de staging ni socket/host aprobado. Requerir cnf validado con transporte local explicito, identidad de instancia aprobada y grants solo sobre staging antes de considerarlo protegido frente a destino accidental. No se ejecuto exportacion de ninguna base existente.

ALTO — procedimiento y preflight.php: el nombre/loopback/config no identifican un servidor univocamente y el guard es comando separado de migrate. Cambiar env/cache/credencial entre ambos introduce una ventana de error. Preparar un gate de migracion que compruebe destino y plan aprobado dentro del mismo proceso; no llamar migrate a ciegas. Mantener prohibidos fresh/reset/rollback automaticos.

MEDIO — permisos del layout: documento 02-A seccion 5 propone directorios 0750 lefenda:lefenda pero Nginx corre www-data. Si se aplica tambien a releases/ancestros, Nginx no podra servir assets. Definir ACL/grupo de solo lectura/travesia en codigo y public, sin dar a www-data acceso a shared/.env ni attachments; probar como cada usuario. El socket FPM 0660 www-data permite FastCGI, no resuelve acceso a ficheros estaticos.

MEDIO — MySQL: se describen runtime/migrador/backup pero no hay manifiesto de grants comprobado. Runtime: DML minimo y sin FILE/GRANT/DDL; migrador: DDL solo en staging por ventana; backup: SELECT/SHOW VIEW/TRIGGER/EVENT/rutinas segun version, sin privilegios globales innecesarios. Validar permisos reales y errores de dump; no inventar un conjunto universal sin probar MySQL elegido.

MEDIO — backup.sh cubre attachments y SQL; custodia de APP_KEY/config y copia externa son pasos manuales. El rehearsal ampliado ejecuta su propio pipeline, no demuestra que backup.sh capture todos los futuros discos ni que exista backup externo recuperable. Quiesced flag es declaracion del operador, no bloqueo de escritores. Exigir inventario de discos, parada HTTP/worker/cron, restauracion externa y custodia separada real.

MEDIO — worker: ProtectSystem estricto y acceso de escritura limitado a storage; validar manifests/caches preconstruidos, cwd tras symlink y reinicio. Parsing de plantillas no inicia systemd/cron, no comprueba reboot ni usuarios efectivos. Logs copytruncate pueden perder lineas; auditoria durable debe permanecer en DB/eventos y backup.

MEDIO — limites: main aun no contiene MEDIA; plantilla HTTP limita a 10/12 MB y no coincide con todos los maximos de dominio. No abrir registro/uploads publicos. Cuota por usuario MEDIA no sustituye cupo global ni retencion. Alertas OCI no son hard cap; sin PAYG/R2/servicios pagos ni Gemini habilitado.

BAJO — tags Docker/apt flotantes y referencias personales historicas en docs: fijar digests/versiones del artefacto aprobado y sustituir ubicaciones historicas por enlaces de repo al preparar entrega publica. No hay ruta personal obligatoria en codigo operativo.

## Ramas y conflictos

main confirmado 52e5c08; codex/deploy-02-a parte del mismo commit con 20 nuevos sin commit. MEDIA 1eb72d470c2245c969baca16bd06803d09fd7933 agrega 37 archivos sobre main, sin modificar migraciones. No hay solapamiento de rutas con DEPLOY: integracion textual prevista sencilla, pero no se hizo merge ni se probo combinacion.

UX-01 tiene HEAD 52e5c08, sin commits divergentes; inspeccion de solo lectura de su worktree encontro 26 modificados y 3 nuevos (CSS, JS, Blade, routes/web.php y pruebas). No se modifico ni integro. Un git diff entre refs por si solo omitiria TODO ese trabajo. Es inventario provisional, puede cambiar mientras trabaja Antigravity.

No hay solapamiento textual actual entre archivos funcionales MEDIA y UX ni entre DEPLOY y UX. Si hay dependencias semanticas: UX modifica Messages/LEXA/Turbo, rutas y tests frontend; QA de 02-A fue con frontend de main, no certifica UX. MEDIA cambia guards distribuidos y adjuntos; QA 02-A no ejecuta cuotas MEDIA, requiere repetir suites de adjuntos/retencion/concurrencia tras incorporarlo. No resolver silenciosamente guards usando la base compartida.

## Validaciones

Se conservan como evidencia de 02-A: 149 frontend y build ARM; 112 Laravel/1121 assertions; seed 12 y restore 9 assertions; 58 tablas/39 migraciones con datos sinteticos, objetos, citas y autorizacion. NO es suite completa ni datos reales.

En esta revision se repitieron en ARM64 emulado, --network none y sin mounts: selftest del comparador (4 checks) y parsing Nginx/FPM/MySQL, aprobados. git diff --check aprobado. No repetir suites completas sin cambios funcionales; ningun resultado combinado con MEDIA/UX se da por aprobado. No se hicieron migraciones/exportaciones sobre bases existentes.

## Orden recomendado

1. Revisar y aprobar MEDIA 1eb72d4 por separado: atomismo/cuotas y guards generales; incorporar a main solo con autorizacion posterior y regresiones de adjuntos/concurrencia/retencion.
2. Cerrar gates de DEPLOY identificados aqui; preparar commit local revisable, actualizar baseline tras MEDIA en rama aislada y ejecutar QA combinado desechable. DEPLOY-01 sigue en otro worktree sin commit: preparar su incorporacion documental separada, sin copiarlo o modificarlo de oficio.
3. Esperar snapshot/commit autorizado por Antigravity de UX; revisar diff final y probar frontend, rutas, Mensajes/LEXA/Turbo y privacidad sobre baseline elegido. Puede entregarse despues del staging si se mantiene frontend actual; no es requisito artificial para infraestructura.
4. Solo despues autorizar integracion a main y primer staging, mediante intervenciones explicitas. No se creo nueva rama porque main no avanzo y no se autorizo incorporar automaticamente MEDIA/UX.

## Falta exacta para autorizar staging gratuito

Cerrar identidad de conexion y gate DDL; matriz/grants/ACL probados por usuarios reales; restauracion integral desde copia externa y claves bajo custodia independiente; smoke Nginx/FPM/HTTPS/cookies, systemd/cron/reboot; decidir commit exacto con MEDIA y opcion UX y ejecutar regresion conjunta. Aprobar recursos elegibles en home region, presupuesto cero por SKU, cuenta sin PAYG, capacidad disponible y responsabilidad de operacion. Mantener sinteticidad/acceso restringido, limites globales pendientes y Gemini apagado. La revision no autoriza crear VM ni afirma SLA o facturacion cero fuera de franquicia.

## Seguimiento DEPLOY-02-C
Los gates SQL/permisos descritos en esta auditoria tienen implementacion y ensayo local en docs/deploy-02-c-security-hardening.md. La historia anterior no acredita la VM real. Usar el procedimiento nuevo de identidad y apply en un proceso; no el preflight seguido de Artisan independiente. No se integraron MEDIA/UX ni se autorizo staging.
