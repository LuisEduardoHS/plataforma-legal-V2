# DEPLOY-01 — Preparación profesional para hosting y primer staging

Fecha: 2026-11-20. Base inspeccionada: 52e5c0812a191be8973038a35bfaf4d2c668b534, rama audit/deployment-readiness. Auditoría estática; no se desplegó, exportó desarrollo ni crearon recursos. Único archivo producido en este worktree: este informe. No se ejecutaron pruebas HTTP ni escrituras SQL aquí. La instrucción antigua del encargo que menciona plataforma_legal_v2_test queda sustituida por la autorización posterior de utilizar exclusivamente plataforma_legal_v2_codex_test para MEDIA.

## 1. Estado real y evidencia

| Área | Estado | Evidencia y alcance |
|---|---|---|
| Runtime | Parcial | composer.json exige PHP ^8.2 y fija resolución 8.2.12; Laravel ^12.0. Falta probar imagen Linux real con composer check-platform-reqs --no-dev. |
| Assets | Implementado localmente | package-lock.json y Vite 6/Tailwind 4; npm ci y npm run build deben producir public/build y manifest en CI. No ejecutar npm run dev en servidor. |
| MySQL | Parcial | Migraciones versionadas, incluyendo SIDOF en este HEAD; contar archivos y ejecutar migrate:status por ambiente antes de cada release. No trasladar la BD de trabajo a staging. |
| Seguridad web | Implementación parcial | routes/web.php usa auth, throttles y servicios/policies; bootstrap/app.php añade EnsureActiveAccount, CSRF del grupo web y sanitización de errores SQL. No demuestra configuración efectiva HTTPS/proxy/cookies de un servidor futuro. |
| Archivos | Privados locales | config/filesystems.php: attachments privado, serve=false, throw=true; public separado. El disco local genérico tiene serve=true: no utilizarlo para evidencias. |
| Correo | Pendiente operativo | config/mail.php usa log por defecto: tokens/enlaces de recuperación podrían quedar en logs. Configurar proveedor transaccional real o sandbox para staging. |
| Colas/caché | Parcial | queue/cache/session configurables, defaults database. routes/web.php usa block() en Mensajes: requiere caché con locks compartidos reales. Array es únicamente testing. |
| Scheduler | Sin tareas de negocio registradas aquí | routes/console.php contiene inspire; SIDOF es comando manual. No programar importaciones o aprobación jurídica automáticamente. |
| Gemini | Parcial | config/lexa.php deshabilita generación por defecto; config/services.php modelo default gemini-3.8-flash: no asumir disponibilidad. Seleccionar modelo validado explícitamente por entorno. Logs del proveedor contienen clase/status/latencia, no prompt ni key. |
| SIDOF | Parcial | SidofClient limita hosts/bytes/timeouts; tracker guarda candidatos y revisión autorizada. Revisar aviso no confirma reforma ni verifica automáticamente el corpus. |
| CI/CD | Ausente en este HEAD | No existe .github; propuesta, no pipeline habilitado. |
| Backups | Bloqueante | No hay evidencia de restauración integral verificada. Un JSON de fuentes y conteo de chunks no es recuperable. |

Extensiones que debe comprobar la imagen: ctype, curl, dom/xml, fileinfo, filter, hash, mbstring, openssl, pcre, PDO/pdo_mysql, session, tokenizer y zip según dependencias; OPcache en producción, pcntl para workers Linux. No es un inventario obtenido del servidor futuro. Usar PHP soportado compatible con lock y verificar versiones con Composer; Node LTS compatible con engines del lock, compilado en CI, no requerido por PHP-FPM.

## 2. Bloqueantes y prioridades

P0 antes de internet: secretos por entorno; APP_DEBUG=false; document root public/; HTTPS/cookies y proxy probado; backup completo restaurado; permisos mínimos del servidor/BD; staging con datos sintéticos; ausencia de originales públicos; correo sin registro de tokens; plan de incidentes y responsable.

P1 antes de usuarios reales: integrar y validar MEDIA-02-A (aún fuera de main), cuotas y capacidad de disco; límites de gasto Gemini globales además del throttle por usuario; monitoreo de 5xx, disco, locks, latencia y backup; procedimiento jurídico de revisión con identidad y revocación; prueba de carga pequeña de polling y streams privados. El throttle por minuto no equivale a presupuesto económico ni protección distribuida si la caché no es compartida.

P2 al crecer: volver a evaluar recursos gratuitos y carga medida; MySQL administrado u otros servicios de pago quedan fuera del alcance y presupuesto autorizado. No son necesarios Kubernetes ni microservicios para primer staging.

## 3. Restricción definitiva: primer staging de $0 MXN

Actualización investigada el 2026-11-20. Sustituye la recomendación inicial de pago. Se descartan VPS comerciales de pago, Cloudways y Laravel Cloud para este primer despliegue. No se utiliza crédito promocional para sostener un servicio recurrente. $0 se refiere a facturación de servicios, no a trabajo de administración ni electricidad/conectividad ya existentes. La disponibilidad de recursos gratis y la continuidad no están garantizadas.

### Comparativa gratuita

| Opción | Naturaleza / costo objetivo | Recursos y persistencia | Tarjeta, límites y decisión |
|---|---|---|---|
| Oracle Always Free A1 | Sin vencimiento prefijado; $0 dentro de franquicia | ARM; 2 OCPU/12 GB agregados; 1500 OCPU-h/9000 GB-h mensuales; volúmenes persistentes | Tarjeta para identidad, retenciones temporales; capacidad no garantizada. Candidato principal, sin upgrade PAYG. |
| Oracle E2 Micro | Always Free; $0 | Hasta dos micro de 1 GB, CPU fraccionaria; persistencia en volúmenes | Alternativa reducida si A1 no disponible, sometida a ensayo de RAM/latencia. |
| Render Free web | Franquicia recurrente; $0 sin medio de pago y dentro de límites | PHP mediante Docker; filesystem efímero; no disco persistente gratis | 750 h/mes compartidas, duerme tras 15 min. Rechazado como stack completo MySQL/adjuntos/worker permanente. |
| alwaysdata Free | Oferta de uso personal sin plazo; 0 EUR = $0 MXN | 1 GB SSD, 256 MB RAM, 1/4 CPU; backups 3 días | Registro sin tarjeta; PHP y MariaDB. No homologado para Lefenda/MySQL; solo candidato a evaluación de demo mínima. |
| Google Cloud Free Tier | Franquicia recurrente, no confundir con crédito inicial | e2-micro, 30 GB disco estándar, 1 GB salida/mes; regiones elegibles | Requiere cuenta de billing; recursos extra pueden facturarse. No seleccionado para garantía $0 por riesgo de IP/red/disco y facturación. |

Fuentes: [Oracle recursos](https://docs.oracle.com/en-us/iaas/Content/FreeTier/freetier_topic-Always_Free_Resources.htm), [Oracle condiciones](https://www.oracle.com/cloud/free/faq/), [Render](https://render.com/docs/free), [alwaysdata oferta](https://www.alwaysdata.com/en/offers/) y [registro/motores](https://www.alwaysdata.com/en/), [Google Free](https://docs.cloud.google.com/free/docs/free-cloud-features). La tabla no es una prueba de aprovisionamiento ni confirma cuotas de una cuenta concreta. Render no expuso CPU/RAM del plan Free en la página de precios recuperada: pendiente confirmar en consola; no asumir capacidad suficiente.

Oracle incluye 200 GB combinados boot/block, cinco backups de volumen, 10 TB/mes de salida; Object Storage ofrece 20 GB combinados y 50000 operaciones/mes después del trial. La región debe ser la home region. A1 puede recuperarse por inactividad: ventana de siete días con CPU p95, red y memoria bajo 20%; las cuentas abandonadas también tienen condiciones de suspensión. No usar tráfico artificial para evadirlo. Datos según documentación citada; no asumir las cifras históricas 4 OCPU/24 GB.

Oracle exige identidad/tarjeta válida y puede retener una autorización temporal. Always Free no expira como una promoción, pero no tiene SLA equiparable a pago. El trial de 300 USD/30 días no cuenta como infraestructura gratuita permanente. No actualizar a PAYG para obtener capacidad; si una pantalla exige pago, detenerse. Los límites y etiquetas efectivos de la cuenta tienen que coincidir antes de crear nada.

Render pierde archivos en reinicio/despliegue/suspensión, Postgres Free caduca en 30 días, y no ofrece MySQL persistente gratuito. Sin tarjeta, exceder transferencia suspende servicios y exceder build impide nuevas compilaciones; con tarjeta puede cobrar excedentes. No hay workers/cron Free equivalentes a un Linux permanente; SMTP está restringido. HTTPS/subdominio del proveedor son útiles para una demo con servicios externos, pero no resuelven persistencia. La conexión externa puede sufrir límites adicionales. No se recomienda cambiar a Postgres para acomodarlo.

alwaysdata anuncia MariaDB, no MySQL idéntico: exigir ensayo de migraciones, CHECK/FK, JSON, collation, locks y aislamiento, full-text del recuperador, errores y concurrencia antes de considerar cambio. Verificar PHP 8.2+, extensiones, compatibilidad Laravel 12, límites de procesos/cron y términos de uso personal. Esos puntos y el tráfico máximo no quedaron acreditados; no presentar como reemplazo listo ni migrar motor en esta tarea.

### Arquitectura recomendada $0

Una VM Oracle A1 elegible, propuesta 2 OCPU/12 GB, Ubuntu ARM compatible; Nginx + PHP-FPM 8.2+ con extensiones comprobadas + OPcache + MySQL Community local persistente. Mantener motor y contratos. Colas database con un worker systemd/Supervisor; cron del sistema schedule:run por minuto. Caché y sesiones database con locks compartidos reales. Ningún Redis, balanceador, NAT gateway, MySQL administrado, WAF o observabilidad de pago necesario para primer staging.

Propuesta de disco: boot 50 GB y volumen de datos 50 GB, agregado 100 GB dentro del límite documentado, dejando margen para recuperación. Confirmar ambos como elegibles gratuitos y rendimiento sin extras. MySQL ligado a loopback, usuario runtime sin DDL, migrador separado; storage adjuntos privado fuera de public/ y releases. Compilar assets/dependencias en entorno compatible o ventana de build controlada, probar ARM y composer check-platform-reqs; no copiar binarios x86 a ARM. No se ha medido rendimiento ni disponibilidad de esta VM.

DNS: subdominio gratuito de [DuckDNS](https://www.duckdns.org/faqs.jsp) y certificado gratuito [Let's Encrypt](https://letsencrypt.org/about/), renovación automatizada y comprobada. No comprar dominio para staging. Comprobar elegibilidad y costo cero de la configuración de red/IP concreta en OCI antes del alta; no dar por gratuito cualquier SKU. Solo 80/443 públicos, SSH restringido, 3306 cerrado; document root public/, debug apagado, cookies seguras y proxy confiable según topología. RTO es mejor esfuerzo: pérdida de capacidad Oracle puede impedir cumplir cuatro horas aunque exista backup.

### R2: evaluado, opcional y fuera del núcleo inicial

[Franquicia R2 Standard](https://developers.cloudflare.com/r2/pricing/): 10 GB-mes, un millón de operaciones A y diez millones B al mes; egress sin cargo de R2. Infrequent Access no recibe esa franquicia. Es uso medido con excedentes facturables, no un almacenamiento con corte duro gratis. [Alta](https://developers.cloudflare.com/r2/get-started/) requiere checkout/suscripción y [política de billing](https://developers.cloudflare.com/billing/understand/billing-policy/) contempla medio de pago/preautorización. El requisito exacto de tarjeta/medio aceptado depende del checkout; no se verificó una cuenta ni se garantiza alta sin tarjeta.

Los [límites de gasto publicados](https://developers.cloudflare.com/billing/manage/spend-management/) cubren Workers y Durable Objects; no acreditar un tope R2 por configurar el de Workers. No prometer factura máxima cero con alertas o cuotas locales. Por esta razón, primer staging usa Storage local privado; R2 se deja desactivado. Si posteriormente se autoriza su riesgo, usar buckets privados sin r2.dev público, credencial S3 limitada, adaptador Laravel pendiente y descargas bajo policy. Límite interno propuesto 5 GB totales (incluye backups), 100000 A y 1000000 B/mes con corte preventivo; medidores son propuesta por implementar, no controles ya disponibles. Métadatos, HEAD, listados y multipart también consumen operaciones.

### Gemini gratuito con alcance explícito

La [tabla API vigente](https://ai.google.dev/gemini-api/docs/pricing#gemini-3.5-flash-lite) ofrece entrada/salida gratuitas para gemini-3.5-flash-lite Standard; caching y grounding Search/Maps no disponibles en ese tier. No confundir acceso a la app Gemini/AI Pro con API gratuita ni habilitar modelos caros por fallback. La selección final debe comprobar disponibilidad del modelo para el proyecto/región. No se hicieron llamadas ni se autenticó en AI Studio.

Proyecto sin billing vinculado ni prepago para evitar escalamiento a pago; [límites](https://ai.google.dev/gemini-api/docs/rate-limits) RPM/TPM/RPD por proyecto dependen de modelo/cuenta y se consultan en AI Studio, no se inventan cifras. Propuesta inicial: cinco testers, una generación simultánea, máximo 20 generaciones/día globales y tres por usuario, sin reintento automático; usar el menor valor entre esa política y cuota real. Mantener contexto/salida actuales acotados y kill switch; 429 deshabilita temporalmente generación, conservando conversación/historial. Estos límites diarios/globales todavía requieren implementación y prueba; el throttle actual por usuario no los sustituye.

Los [términos unpaid](https://ai.google.dev/gemini-api/terms) contemplan uso de entradas/respuestas para mejora y advierten sobre información sensible; también condicionan edad/regiones y uso de servicios gratuitos. Para staging usar únicamente preguntas sintéticas y testers autorizados, sin expedientes, nombres, mensajes privados ni documentos reales. No habilitarlo públicamente para datos legales sensibles bajo la idea de que gratis equivale a privacidad. Mientras no se validen condiciones y cuotas, generación deshabilitada; historial y flujo sin IA siguen disponibles. RAG MySQL propio no necesita Search grounding ni embeddings pagados y las fuentes sin revisión siguen bloqueadas.

### Presupuesto y límites operativos propuestos

| Componente seleccionado | Factura mensual objetivo | Protección / condición |
|---|---|---|
| OCI compute + volúmenes + red elegibles | $0 MXN | Cuenta sin upgrade; revisar agregados, etiquetas, estimador y cuotas; no usar recursos solo cubiertos por trial |
| MySQL/PHP/Nginx/worker/cron | $0 MXN de licencia/servicio adicional | Autogestionados en misma VM; sujeto a recursos y operación humana |
| Adjuntos locales privados | $0 MXN adicional | Cupo global inicial 2 GB, por usuario 100 MB propuestos; margen para pendientes y retención |
| Backup OCI + copia externa propia | $0 MXN adicional si hay espacio/equipo existente | Backups cifrados con cupo y prueba de restauración; no contratar espacio |
| DNS y HTTPS gratuitos | $0 MXN | Subdominio/ACME, sin compra de dominio |
| Gemini Free opcional | $0 MXN | Sin billing, modelo elegible, datos sintéticos y corte por cuota |
| R2 | No activado | Evita añadir suscripción de uso medido en el despliegue inicial |

Propuesta: invitar cinco testers, cerrar registro abierto, sin vídeo, limitar uploads a los formatos/flujo actuales y no aumentar máximos. MEDIA permite cuota por usuario, pero no implementa cupo global: añadirlo/revisarlo en una intervención posterior antes de habilitar uploads abiertos. Alarmas al 60/75%, cierre de nuevas cargas al 80% de presupuesto local; no borrar evidencia retenida para liberar espacio. Rotar logs acotados sin cuerpos privados, caché de assets públicos, límite de conexiones y polling sin amplificar peticiones. Los límites son diseño; no se modificó código/configuración aquí.

OCI budgets/alertas no son un interruptor de facturación. IAM debe restringir creación, cuotas impedir recursos fuera de catálogo aprobado y el responsable revisar consumos; no activar extras para resolver lentitud. Ante cuota, reducir uso o suspender funcionalidad antes de pagar. El principal control es no convertir la cuenta a pago y no depender de créditos temporales; aun así comprobar condiciones comerciales exactas antes del alta. No puedo garantizar perpetuidad, capacidad o ausencia de cambios de términos por investigación pública.

### Si Oracle no ofrece capacidad

1. Reintentar manualmente más tarde u otro availability domain permitido de la home region, sin automatizar solicitudes masivas ni abrir múltiples cuentas.
2. Evaluar E2 Micro elegible, reducir FPM/worker y ensayar MySQL/colas con datos sintéticos; no certificar capacidad con 1 GB sin medir.
3. Mantener staging local en equipo existente con acceso restringido y HTTPS/subdominio gratuito solo si hay conectividad y seguridad suficientes; no equivale a hosting con disponibilidad garantizada y puede tener costo incremental de energía/red.
4. Render solo preview efímero si ya existe un backend MySQL persistente gratuito seguro; no publicar MySQL doméstico a internet para completarlo. alwaysdata exige plan de compatibilidad MariaDB y límites antes de elegirlo.
5. Si ninguna satisface motor, persistencia, HTTPS y recuperación a $0, posponer staging público. No recurrir a una suscripción ni sustituir MySQL silenciosamente. AWS/Azure/créditos iniciales de otros proveedores no prueban continuidad gratuita de este stack.

## 4. Configuración y seguridad de producción

Inventario de nombres, sin secretos:
- APP_NAME, APP_ENV=production, APP_DEBUG=false, APP_URL HTTPS, APP_KEY única; preservar llave al cambiar release y respaldarla cifrada aparte. No copiar la llave local.
- DB_CONNECTION=mysql, DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD; usuario runtime sin DDL y usuario migrador separado. TLS si BD remota; validar parámetros SSL en config/database.php.
- SESSION_DRIVER=database o redis, SESSION_SECURE_COOKIE=true, SESSION_HTTP_ONLY=true, SESSION_SAME_SITE=lax, SESSION_COOKIE distinto por entorno, SESSION_DOMAIN restringido, SESSION_LIFETIME y SESSION_ENCRYPT según política.
- CACHE_STORE=database o redis; prefijo/conexión separados; QUEUE_CONNECTION=database/redis si se habilitan jobs; REDIS_HOST/PORT/PASSWORD y DB separados cuando aplique. No usar caché array en despliegue.
- LOG_CHANNEL/LOG_LEVEL y retención; MAIL_MAILER/MAIL_HOST/MAIL_PORT/MAIL_USERNAME/MAIL_PASSWORD/MAIL_SCHEME/MAIL_FROM_ADDRESS/MAIL_FROM_NAME o tokens del transport elegido.
- ATTACHMENTS_DISK; ATTACHMENTS_QUOTA_ENABLED/BYTES solo tras integrar MEDIA; FILESYSTEM_DISK; AWS_ACCESS_KEY_ID/AWS_SECRET_ACCESS_KEY/AWS_DEFAULT_REGION/AWS_BUCKET/AWS_ENDPOINT/AWS_USE_PATH_STYLE_ENDPOINT si se adopta S3.
- LEXA_GENERATION_ENABLED, LEXA_PROVIDER, GEMINI_API_KEY, GEMINI_MODEL, GEMINI_BASE_URL, GEMINI_TIMEOUT, GEMINI_CONNECT_TIMEOUT; SIDOF_BASE_URL/TIMEOUT/CONNECT_TIMEOUT/MAX_RESPONSE_BYTES. No exponer estos secretos en variables VITE_*.

bootstrap/app.php no configura trustProxies explícitamente: resolver IP/scheme según proxy real y confiar únicamente en sus rangos, no en cualquier Forwarded enviado por el cliente. Verificar generación de URLs HTTPS, rate limits por IP y cookies detrás del balanceador. No activar CORS comodín con credenciales; interfaz actual es same-origin. Nginx niega dotfiles, backups, PHP fuera de public/index.php; disable autoindex, sin phpinfo. TLS, redirect HTTP y HSTS después de comprobar todos los subdominios. PHP display_errors Off; logs restringidos y rotados. Solo storage y bootstrap/cache escribibles por runtime; código y secretos no world-writable, nunca chmod 777. Ajustar upload_max_filesize, post_max_size y client_max_body_size coordinadamente con límites de AttachmentValidator; no aumentar a ciegas por el máximo teórico de vídeo.

Revisar excepciones no SQL: la sanitización SQL en DatabaseFailureReporter no garantiza que todos los logs sean inocuos. No almacenar cuerpos HTTP, prompts, mensajes, tokens de reset, cookies o documentos. Probar redacción de errores y retención antes de exposición pública. /up acredita proceso; añadir monitor privado de DB/storage/colas sin publicar secretos.

## 5. Storage y MEDIA

MEDIA-01 se conserva en el worktree de media. MEDIA-02-A incorpora reservas mediante filas attachments y lock por usuario; suma pending_upload/available/pending_deletion hasta borrado físico confirmado. Sin migración propia. Es trabajo pendiente de integración: este HEAD de deployment todavía no tiene esas cuotas ni media:reconcile.

Para primer staging usar volumen persistente privado fuera de public/ y de releases. storage:link enlaza solo derivados expresamente públicos; nunca app/attachments. Para crecer, definir disco attachments compatible S3 y agregar adaptador league/flysystem-aws-s3-v3 en intervención futura (no declarado como dependencia directa aquí); no basta cambiar FILESYSTEM_DISK porque AttachmentService usa attachments.disk.

Separar buckets o políticas/prefijos y credenciales: originales privados; evidencias retenidas; documentos privados futuros de LEXA (no afirmar que ya existen uploads); temporales con TTL controlado; derivados aprobados públicos; backups con credenciales distintas. Acceso privado mediado por policy, no URL pública persistente. Preservar metadatos/status/pivots/hash al migrar y comprobar tamaño/hash por objeto con manifiesto; ensayo antes de cortar, no eliminar origen hasta restauración comprobada. No introducir lifecycle que borre evidencia retenida o historial compartido.

R2 se evaluó en la sección 3; no se activa en la arquitectura inicial de costo cero. Adjuntos permanecen privados en volumen persistente y backups separados.

## 6. MySQL, backups y recuperación

Staging empieza vacío con migraciones del commit aprobado y catálogos seguros revisados; no exportar desarrollo. DatabaseSeeder contiene catálogos y roles con permisos de negocio, no crea operador. Revisar individualmente seeders; los nombres admin/moderator no sustituyen operations.* o verification.*. Aprovisionamiento inicial mediante procedimiento operations:bootstrap con simulación, cuenta verificada, capacidades mínimas y confirmación por responsable; no ejecutar en esta auditoría. Revisores y operadores separados.

Backup recuperable obligatorio: dump íntegro consistente de todas las tablas InnoDB, estructura, índices, restricciones, migraciones, triggers/rutinas/eventos si existen, charset/collation y versión; objetos privados completos con manifiesto de hashes; configuración necesaria y APP_KEY cifradas bajo custodia independiente. Credenciales por fichero protegido/secret store, nunca argumento de comando ni Git. Snapshot de VM no sustituye consistencia de SQL y objetos. Coordinar pausa breve de escrituras/purgas o snapshot lógico/objetos versionados para un punto común; dump --single-transaction no cubre DDL concurrente ni objetos externos.

El JSON anterior LEXA omitía contenido de chunks: un conteo no reconstruye textos, relaciones ni hashes. No considerarlo respaldo recuperable. No se creó/exportó backup local en esta auditoría.

Política inicial $0 a aprobar: RPO 24 h para staging sintético, recuperación objetivo 4 h cuando haya VM disponible, sin garantía si OCI reclama instancia o falta capacidad. Mantener un dump íntegro y copia de objetos coherente cifrados, más versiones dentro de un cupo total de backup propuesto de 5 GB en OCI Object Storage; máximo tres snapshots de volumen dentro de la franquicia de cinco, sin confiar solo en snapshots. Antes de reemplazar una copia verificar la nueva y conservar al menos una recuperación anterior válida. Replicar diariamente a equipo propio existente con almacenamiento suficiente, fuera de la cuenta OCI, mediante conexión segura de salida/pull; esto es prerrequisito, no copia ya existente. Si no cabe SQL + objetos + retención, detener nuevas altas/cargas o reducir alcance del staging, no borrar evidencias ni contratar ampliación. Los 20 GB de OCI Object Storage son compartidos por cuenta/clases y la fase trial tiene particularidades: confirmar franquicia efectiva antes de subir backups. No aplicar la antigua retención 7 diarias/4 semanales/6 mensuales sin calcular espacio real. Binlog/PITR de producción no queda acreditado ni se activa como dependencia de este staging.

Ensayo mensual aislado: crear BD/objetos temporales sin rutas al proveedor real; restaurar dump+objetos+llave con red/correo/Gemini bloqueados; comprobar todas las tablas/conteos/FK, chunks completos/hashes/versiones/citas, permisos y decisiones históricas, descarga autorizada y rechazo entre usuarios, sesiones descartadas; ejecutar smoke con cuenta sintética y registrar tiempos/RPO real. Probar también recuperación ante pérdida de credencial/servidor. Aprobación de salida requiere acta de restauración, no solo archivo existente.

Rollback de app mediante releases inmutables y cambio de symlink hacia release previo compatible. Migraciones expand/contract, revisar down() (operations_events protege eventos persistidos). Nunca rollback ciego ni migrate:fresh para reparar despliegue; cambios incompatibles requieren plan de recuperación y reconciliación de escrituras posteriores. Mantener backups protegidos frente al usuario runtime.

## 7. Revisión jurídica humana

Dato de contexto recibido: seis representaciones locales sin verificación humana. No se consultó ni modificó su contenido en esta auditoría. Validación técnica = formato/hash/parser/procedencia; cotejo documental = correspondencia completa con publicación oficial, artículos/transitorios y versión; revisión jurídica = vigencia, jurisdicción/aplicabilidad, reformas y límites. Ninguna sustituye las demás.

Estado existente: LegalSourceIngestion::verify requiere ID/hash/reviewer/note, revalida fragmentación y cambia elegibilidad de versiones; reviewer es texto recibido por CLI, no identidad autenticada por sí mismo. VerifyLegalSource requiere --apply y los datos, pero no constituye panel profesional con firma, rechazo/revocación y auditoría inmutable. No usar la revisión de publicaciones SIDOF como aprobación del texto normativo.

Procedimiento propuesto: expediente con URL oficial, editor, archivo original/hash, extracción/hash de texto y chunks, fechas diferenciadas de publicación/reforma/consulta/vigencia, jurisdicción y transitorios; revisor nominal autorizado coteja fuente y registra discrepancias, alcance y evidencia. Segundo control para normas críticas. Aprobación ligada a versión/hash y actor autenticado, fecha, observaciones y responsabilidad; rechazo conserva expediente, no habilita recuperación. Reforma/error abre nueva versión, suspende elegibilidad conforme revisión y registra revocación sin borrar evidencia o citas históricas. Acceso separado de operations.*; no conceder permisos por ser admin nominal. Implementar trazabilidad de decisiones y revocación explícita antes de ofrecer corpus como validado públicamente.

Mientras corpus vacío: LEXA debe identificarse como orientación general sin citas verificadas, reconocer falta de evidencia y recomendar profesional. No generar artículos/fechas ni presentar enlaces inventados como evidencia. Conservar citas históricas con snapshots; no reemplazarlas retroactivamente por nueva norma. Gate de aceptación: ninguna fuente no aprobada recuperable, aprobaciones vinculadas al hash exacto y decisión atribuible/revocable. No se ejecutó lexa:sources:verify.

## 8. Runbook del primer staging gratuito (propuesto, no ejecutado)

1. Confirmar Oracle Always Free y capacidad, estimación $0 sin PAYG, responsable, home region y subdominio gratuito; acceso restringido por VPN/allowlist o control perimetral sin sustituir auth de la app. No indexación y correo sandbox sin salida ni tokens en logs públicos; entrega real pendiente hasta verificar un transporte gratuito compatible.
2. Provisionar Linux soportado con Nginx/PHP-FPM/OPcache/MySQL, firewall y SSH por llaves; TLS ACME; BD en red privada, sin 3306 público. Dimensionar FPM para dejar RAM a MySQL.
3. CI construye release por SHA: composer install --no-dev --prefer-dist --optimize-autoloader, composer check-platform-reqs --no-dev; npm ci y npm run build; empaquetar vendor y public/build. No composer update en servidor.
4. Inyectar secretos staging fuera de release; APP_ENV=production/APP_DEBUG=false también en staging externo; storage persistente privado, caché/sesiones propias y config cache específica del entorno, nunca copiar cache local.
5. Antes de DDL: comprobar dentro de proceso SELECT DATABASE(), host/entorno y migrate:status; aprobación del diff de migraciones y backup recuperable. Ejecutar php artisan migrate --force exclusivamente en staging autorizado; catálogos permitidos explícitos, sin datos privados.
6. php artisan config:cache, route:cache y view:cache en release; si fallan, no activar. Probar comandos en staging, no asumir que pasan por inspección. Symlink atómico después de smoke, preservar storage.
7. Worker systemd/Supervisor solo si hay jobs habilitados, usuario no root, timeout/tries acordes, log sin datos y reinicio con queue:restart tras release. No sustituir llamadas Gemini síncronas por jobs sin diseñarlo. Cron schedule:run cada minuto bajo mismo usuario; actualmente no programa negocio y no activa SIDOF por sí solo.
8. Smoke sintético: login/logout POST+CSRF, recuperación sandbox, perfiles/autorización, Comunidad, Mensajes y polling/revocación, adjuntos privados, LEXA sin proveedor y fake, citas y bloqueo de fuentes no aprobadas; móvil/desktop, Turbo/BFCache, errores JS, secure cookies y respuesta 419. Llamada Gemini real separada con presupuesto/autorización explícita.
9. Monitorear mediante herramientas locales gratuitas 5xx/latencia/429, FPM/DB locks/disco, colas fallidas, franquicias y backups; alertas sin cuerpos privados. Probar restauración y rollback antes de aceptar staging.
10. Producción requiere autorización independiente, infraestructura/llaves/DB/buckets/correo separados y ventana de release; no promover datos sintéticos ni usuarios personales por dump.

## 9. CI/CD propuesto

GitHub Actions solo dentro de la franquicia gratuita efectiva del repositorio, con gasto adicional deshabilitado; si no hay minutos, ejecutar CI local y publicar artefactos manualmente sin pagar. No usar runners ARM de pago por defecto; construir dependencias compatibles y comprobarlas en la VM. GitHub Actions con permisos mínimos, checkout por SHA, lockfiles, artefacto trazable y concurrency por entorno. Jobs: PHP/extensions+MySQL dedicado desechable, guard previo a traits, APP_ENV testing/EXPECTED_TEST_DB exacto, cache/session array/queue sync/storage externo; Laravel focalizadas y completas en CI; node --test tests/frontend/*.test.mjs y Vite; Pint --test (registrar deuda preexistente sin autoformatear ajenos); composer audit/npm audit con triage de producción/build; secret scanning y revisión humana. Nunca copiar .env a artefactos, tampoco logs de tests con contenido real.

Deploy staging con environment protegido y aprobación, SSH/OIDC limitado al release; producción con aprobación manual adicional y backup/restauración acreditados. No activar despliegue automático sobre main todavía. Rollback publica artefacto anterior compatible y registra incidente, no revierte BD automáticamente.

## 10. Roadmap y aceptación de lanzamiento

- [ ] P0: acreditar franquicia permanente y estimación $0, sin upgrade de pago ni créditos temporales, capacidad y responsable; HTTPS/secretos/permisos probados.
- [ ] P0: staging vacío y aislado, backup completo restaurado, copia externa gratuita efectiva y RPO/RTO medidos; aceptar indisponibilidad si no hay capacidad gratuita.
- [ ] P0: recuperación de contraseña real sin tokens en logs; política de privacidad/retención validada por responsable.
- [ ] P1: integrar MEDIA validada tras revisión; cupos por usuario y global, retención/storage privado y alarmas de disco. MEDIA-02-B está preparada en integracion/media-02-b (1eb72d4), todavía no incorporada en este HEAD.
- [ ] P1: probar límite económico Gemini, modelo disponible, fallback y redacción de logs sin llamadas masivas.
- [ ] P1: proceso jurídico atribuible con rechazo/revocación; corpus no aprobado bloqueado.
- [ ] P1: CI y smoke en servidor Linux; polling, autorizaciones y accesibilidad conservados.
- [ ] P2: separar servicios y adoptar almacenamiento remoto tras ensayar I/O/retención/restauración.

Resultado: arquitectura gratuita propuesta y condicionada a capacidad Always Free y controles pendientes; no se garantiza disponibilidad ni $0 al exceder franquicias de servicios medidos. No existe evidencia suficiente para declarar producción lista. Permanecen pendientes validación en servidor, presupuesto final, proveedor de correo, ensayo de recuperación, integración MEDIA y controles operativos/jurídicos indicados. No se contrataron servicios, crearon cuentas, aprobaron fuentes ni modificaron código o datos.
