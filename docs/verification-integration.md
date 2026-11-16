# INTEGRACIÓN-07 — Verificación profesional funcional

Base: `main`, `5e36835741318d4c01fdf87394632d67200f42ff`, árbol limpio.

## Inventario de capacidades previo a HTTP

Inspeccionados BACK-04, BACK-02, BACK-03, sus migraciones, modelos, policies,
permisos, pruebas y contratos de las integraciones anteriores.

| Capacidad real | Fuente existente | Contrato |
| --- | --- | --- |
| Crear borrador | VerificationService::createDraft | Propietario activo; un expediente activo por perfil |
| Asociar/retirar evidencia | addEvidence/removeEvidence | Sólo draft; tipo enumerado, propiedad e integridad |
| Presentar | submit | Título, cédula declarada, especialidad activa y professional_license; sellos y retención |
| Consulta propia | view | Propietario o revisor autorizado; sin acceso público |
| Bandeja pendiente | pending | verification.review; excluye solicitudes propias |
| Abrir revisión | review | Expediente presentado; registra review_opened; datos materiales sólo si coinciden |
| Aprobar/rechazar | approve/reject | Sólo pending; review + decide; motivo comunicable de 10–2000 caracteres |
| Revocar | revoke | Sólo approved; review + revoke; motivo comunicable |
| Historial | history | Propietario o verification.audit |
| Descargar evidencia | downloadEvidence | Propietario o review + evidence.read; contexto exacto, integridad, auditoría |
| Invalidar por edición | invalidateForProfileChange desde ProfileService | Pending/approved pasa a invalidated; limpia insignia en la misma transacción |

Estados de solicitud: `draft`, `pending`, `approved`, `rejected`, `revoked`,
`invalidated`. Estados de perfil: `unverified`, `pending`, `verified`, `rejected`,
`revoked`. No hay caducidad automática, reapertura de expedientes resueltos, notas
internas separadas, OCR ni consulta oficial de cédulas. Tras rechazo, revocación o
invalidación se puede iniciar un borrador nuevo; el expediente anterior permanece.

No existe columna de versión en BACK-04. HTTP añade un token opaco de estado
comprobado bajo los mismos bloqueos antes de delegar operaciones al servicio.
No se requiere migración ni se sustituye el motor de transiciones.

## Arquitectura conectada

`VerificationFormRequest` valida un contrato estricto y autoriza el recurso antes
de procesar archivos. `VerificationController` devuelve páginas HTML y respuestas
mínimas de confirmación de escrituras. `VerificationWorkflow` coordina lecturas,
DTO explícitos, versión HTTP, carga/asociación y compensación. Las escrituras de
expedientes, evidencias, decisiones y eventos siguen entrando exclusivamente por
`VerificationService`; la reserva y los bytes siguen entrando por `AttachmentService`.
No se modifican esos servicios auditados, las policies, enums, modelos ni migraciones.

La bandeja HTTP usa una consulta paginada de 15 expedientes presentados, excluye
el propio perfil y vuelve a autorizar cada fila mediante la policy existente. Es
una proyección acotada de lectura, no un segundo sistema de estados. `pending()`
del dominio sigue disponible con su contrato original sin paginación.

Se reutilizan `ProfileService`, `VerificationIntegrity`, los estilos de Perfiles,
el layout, menú de cuenta y navegación principal existentes. Las nuevas pantallas
son progresivas y no requieren JavaScript propio. No se añadieron dependencias.

## Rutas HTTP (13)

Todas requieren autenticación y pasan por `EnsureActiveAccount`, que vuelve a leer
la cuenta persistida. Las escrituras usan CSRF del grupo web.

| Método | Ruta | Nombre | Acceso |
| --- | --- | --- | --- |
| GET | `/verificacion` | `verification` | Cuenta activa; estado propio o invitación a crear perfil |
| POST | `/verificacion/solicitudes` | `verification.store` | Propietario con perfil profesional |
| GET | `/verificacion/solicitudes/{dossier}` | `verification.show` | Propietario exclusivamente |
| POST | `/verificacion/solicitudes/{dossier}/evidencias` | `verification.evidence.store` | Propietario, draft |
| DELETE | `/verificacion/solicitudes/{dossier}/evidencias/{evidence}` | `verification.evidence.destroy` | Propietario, draft; sólo desasocia |
| POST | `/verificacion/solicitudes/{dossier}/presentar` | `verification.submit` | Propietario, draft completo |
| GET | `/verificacion/solicitudes/{dossier}/evidencias/{evidence}/descargar` | `verification.download` | Propietario o review + evidence.read |
| GET | `/verificacion/solicitudes/{dossier}/historial` | `verification.history` | Propietario o audit |
| GET | `/revision-profesional` | `verification.review.index` | review; bandeja paginada |
| GET | `/revision-profesional/{dossier}` | `verification.review.show` | review; presentado y ajeno; registra apertura |
| POST | `/revision-profesional/{dossier}/aprobar` | `verification.approve` | review + decide; pending ajeno |
| POST | `/revision-profesional/{dossier}/rechazar` | `verification.reject` | review + decide; pending ajeno |
| POST | `/revision-profesional/{dossier}/revocar` | `verification.revoke` | review + revoke; approved ajeno |

Los IDs de ruta son enteros positivos acotados. Los limitadores por cuenta son
90/minuto para lecturas, 20/minuto para escrituras, 5/minuto para cargas y 20/minuto
para descargas. No son cuotas de almacenamiento ni sustituyen los permisos.

## Contratos de formularios y respuestas

- Crear borrador: ningún dato de perfil, actor o estado en el payload; el servidor
  obtiene el perfil a partir de la sesión.
- Añadir evidencia: `version`, `type` y un único `file`. `type` admite sólo
  `professional_license`, `certificate` y `supporting_document`.
- Retirar evidencia o presentar: `version`; la evidencia pertenece al contexto de ruta.
- Aprobar/rechazar/revocar: `version` y `reason`, texto comunicable de 10–2000
  caracteres, sin controles no admitidos. La operación viene de una ruta fija,
  no de un campo manipulable. No se aceptan estados finales ni IDs de revisor.
- Listado propio: `page`. Bandeja: `page` y `status` en pending/approved/rejected/
  revoked/invalidated. Se rechazan campos adicionales, arrays inesperados y filtros
  de búsqueda por datos privados. `_token` y `_method` son los controles web admitidos.

El número de cédula se captura únicamente en el formulario profesional ya existente,
se cifra en su modelo y nunca se copia a una nueva tabla. La pantalla de solicitud
indica los datos faltantes y enlaza al formulario correspondiente.

Las páginas de consulta son HTML con DTO explícitos; no hay API JSON de expedientes
ni serialización de modelos Eloquent. Si una escritura solicita JSON, devuelve sólo
`id` del expediente, `message` y `redirect`; crear y cargar responden 201. En HTML
se redirige al detalle con un mensaje real. Todas las respuestas propias usan
`Cache-Control: private, no-store`.

Errores de formulario normales: redirect accesible con errores e input acotado,
o 422 en JSON. El old input específico excluye binarios, cédulas y campos ajenos.
Una versión mal formada produce 422; un token bien formado pero obsoleto produce
409 y exige consultar el estado actual. Recursos inexistentes y no autorizados
devuelven el mismo 404 genérico. Una cuenta sin review recibe 403 en la bandeja;
una cuenta inactiva pierde la sesión conforme al middleware existente. SQL global
conserva la sanitización de INTEGRACIÓN-06B; errores de archivos/verificación devuelven
503 genérico. Los errores de programación ajenos no se ocultan como fallos SQL.

## Flujo, versiones y concurrencia

1. Mi perfil enlaza a Mi verificación; también se puede entrar sin perfil y consultar
   qué falta. Consultar no crea perfiles ni solicitudes.
2. El propietario crea un draft y añade documentos privados de uno en uno. Puede
   retirar evidencias del borrador; no se borran físicamente los archivos.
3. Presentar delega las comprobaciones completas y la retención a BACK-04. Un error
   no presenta parcialmente la solicitud ni retiene sólo una parte del conjunto.
4. El revisor encuentra solicitudes ajenas en la bandeja. Abrir el detalle registra
   `review_opened`. Los datos materiales sólo se muestran si coinciden con la huella
   presentada. La interfaz no suplanta el perfil original con datos posteriores.
5. El revisor autorizado decide con motivo; una aprobación válida actualiza el perfil
   y la insignia. Rechazo y revocación conservan historia y documentos. El solicitante
   consulta el estado, motivo comunicable e historial desde su propia sección.
6. Cambios materiales realizados por ProfileService invalidan pending/approved y
   retiran la verificación en la misma transacción. No se habilita una reapertura
   artificial: después de rejected/revoked/invalidated se inicia otro expediente.

Transiciones reales: `draft → pending`; `pending → approved | rejected | invalidated`;
`approved → revoked | invalidated`. Los estados terminales no se reabren.

La versión HTTP es un token HMAC opaco del ID/estado/actualización de la solicitud,
huella actual de campos materiales y conjunto actual de evidencias. No expone esas
huellas internas ni hashes de archivos. La comparación se realiza bajo bloqueos de
usuarios ordenados por ID, perfil y solicitud, antes de llamar al dominio dentro
de la misma transacción. No se introduce una columna ni una segunda transición.
Abrir una revisión o descargar evidencia no invalida formularios idénticos; cambiar
documentos, datos materiales o estado sí los invalida.

La carga autoriza estado, cupo y versión antes de I/O. BACK-02 guarda una reserva
durable fuera de cualquier transacción exterior; después se repiten autorización
y versión bajo bloqueo al asociar. Si hay una carrera o fallo de asociación, se
solicita eliminación lógica únicamente del objeto recién creado. No se purga ni
se compensa una evidencia anterior. Si falla la compensación, se reporta el error
y el registro durable permanece para reconciliación operativa. Repetir una petición
con token obsoleto no añade otra evidencia ni sobrescribe decisiones.

## Evidencias, privacidad, auditoría y retención

Fileinfo, extensión y tamaño real se validan por `AttachmentValidator`; BACK-04
comprueba nuevamente categoría, disponibilidad, propiedad, disco privado y bytes.
Los formatos se derivan de `attachments.types` para image/document: JPEG, PNG, GIF,
WebP, PDF y TXT en la configuración actual. Se aplica el mínimo entre el límite
por categoría de BACK-02 y `verification.max_evidence_bytes`: imágenes 10 MiB,
documentos 20 MiB, hasta 10 evidencias. No se aceptan audio/video ni IDs arbitrarios
de adjuntos preparados o de otros módulos en HTTP.

La consulta expone sólo ID de evidencia, tipo, nombre escapado y tamaño. No entrega
ruta, disco, UUID interno, hash de contenido, sellos de integridad ni binarios en
JSON. La descarga contextual existente comprueba solicitud/evidencia exacta, huellas
y bytes antes de transmitir un buffer como attachment, con no-store, nosniff y CSP
restrictiva. No hay URL pública, preview inline, `storage:link` ni descarga documental
desde el perfil público. Un permiso review no basta para descargar: hace falta
evidence.read, salvo el propietario.

Presentar marca `verification_retained_at`; rechazo, revocación e invalidación no
liberan la retención. Se conservan evidencias históricas y eventos. No se añade una
ruta HTTP de purga, liberación o eliminación física. La separación de evidencias
respecto de Comunidad, Mensajería y editorial conserva sus controles existentes.

La cédula declarada se muestra sólo al revisor explícitamente autorizado en el
detalle HTML privado y sólo si los datos materiales coinciden con la presentación.
No se muestra en listas, perfiles públicos, Comunidad ni confirmaciones JSON.
Los nombres de cuenta y correos privados de solicitantes/revisores no se copian
al historial de presentación: el DTO distingue Tú/Solicitante/Personal autorizado.

La auditoría del dominio conserva actores reales y motivos, incluidos created,
evidence_added/removed, submitted, review_opened, evidence_read, approved, rejected,
revoked e invalidated. Un fallo de persistencia de auditoría revierte la decisión
y el perfil. Las razones son comunicables: no hay campo de notas internas. La UI
advierte no incluir cédulas, secretos ni contenido documental en ellas. No se
registra el contenido de archivos en logs de esta integración.

## Permisos y aprovisionamiento administrativo

No se concede ningún permiso desde registro, perfil, solicitud ni decisión. Admin
no tiene excepción implícita. Los permisos reales siguen siendo review, evidence.read,
decide, revoke y audit bajo el prefijo `verification.`. Revisar, aprobar, rechazar o
revocar un expediente propio está prohibido aunque se posean los cinco permisos;
el acceso de propietario a su estado/documentos/historia no equivale a revisión.
Un auditor con audit puede leer el historial mínimo conforme a la policy, incluso
sin permiso review; eso no abre el detalle material ni los documentos.

No se modificaron grants persistidos de desarrollo. Para habilitar operación real,
un operador confiable debe identificar una cuenta activa y autorizada, verificar
que existen los cinco permisos del catálogo y conceder individualmente sólo los
necesarios mediante el mecanismo administrativo controlado de Spatie. Review es
base para bandeja/detalle; agregar evidence.read sólo si leerá documentos, decide
si resolverá, revoke si revocará y audit si consultará historial. Registrar la
autorización administrativa y comprobar el acceso con esa identidad; no conceder
en masa al rol admin ni insertar usuarios privilegiados con seeders. Retirar los
permisos por el mismo procedimiento cuando cambie la función. Esta entrega no
ejecuta ese procedimiento ni requiere modificar tablas manualmente para tramitar
solicitudes: una vez aprovisionado, todo el flujo se realiza por HTTP.

Las pruebas crean revisores sintéticos con concesiones explícitas exclusivamente
en testing y limpian sus propios registros.

## Representación pública

Perfil público y directorio ya consumen el estado actual de ProfileService/
ProfessionalDirectory, actualizado por VerificationService. Se conserva ese contrato:
sólo `verified` muestra insignia, no la existencia del perfil, cédula, rol, upload o
solicitud pending. La aprobación revocada o invalidada pierde la insignia mediante
el estado actual. No existe caducidad automática en este dominio; no se inventa.

Comunidad e Inicio añaden referencias profesionales de sus autores sólo cuando
la cuenta está activa y la presentación profesional es pública. La relación se
carga en lote con columnas mínimas y filtro de visibilidad. Se distingue el enlace
Perfil profesional de la insignia Perfil verificado; no se publican datos privados
ni estados de expedientes. El perfil personal mantiene su privacidad independiente.
La insignia tiene una explicación de revisión interna y el perfil público incluye
un aviso visible sobre su alcance, sin promesas de consulta oficial o calidad.

## Pruebas y QA

Resultados finales, 2026-11-15:

| Comprobación | Resultado |
| --- | --- |
| Suite Laravel completa, `php artisan test --compact` | **590 aprobadas, 13,344 assertions**, 126.55 s |
| Regresiones nuevas de esta integración | 31 casos aprobados en la suite completa; incremento de 337 assertions |
| Frontend, `node --test tests/frontend/*.test.mjs` | **41 aprobadas**, sin nueva lógica JavaScript |
| `npm run build` | Correcto; Vite 6.4.4, 92 módulos |
| Pint `--test` de PHP nativo afectado | Correcto; 9 archivos |
| Sintaxis `php -l` de PHP/Blade afectados | Correcta; 23 archivos |
| Registro efectivo de rutas | 98 rutas, 13 de Verificación; sin nombres ni combinaciones método/dominio/URI duplicados |
| `git diff --check` | Correcto |
| Inventario documental frente a Git | Coincidencia exacta: 24 archivos, 14 nuevos y 10 modificados |

El bloque focalizado de pruebas HTTP/cargas se ejecutó durante el desarrollo;
la suite final incluye además la regresión pública añadida después de ese bloque.
Se conservaron todas las pruebas anteriores y las protecciones de INTEGRACIÓN-06B.

Las nuevas pruebas HTTP cubren acceso convencional/profesional, alta real del perfil
y solicitud, duplicados, campos manipulados, transiciones, listas/lecturas ajenas,
permisos separados, admin sin bypass, revisión propia, auditoría, razones escapadas,
revocación, invalidación, insignias públicas y privacidad profesional, cuentas
inactivas, SQL, CSRF y rate limiting. Las pruebas de cargas usan el servicio y el
almacenamiento reales sobre un disco falso privado: formatos inválidos, tamaño,
duplicado de bytes, reserva durable, fallo de asociación/auditoría, fallo de Storage,
carrera entre carga y presentación y competencia real de dos conexiones MySQL.
La inyección puntual de errores no sustituye operaciones normales del dominio.

Antes de escribir se confirmó entorno testing, driver mysql, base configurada y
`SELECT DATABASE()` iguales a `plataforma_legal_v2_test`. Tests\TestCase conserva su
guard antes de fixtures. Las pruebas de carga verifican base efectiva y raíz fake
al crear cada adjunto; no usan una transacción exterior que viole BACK-02. Las filas
confirmadas de esos tests se retiran selectivamente por IDs en tearDown, incluyendo
permisos sintéticos; no se ejecutan migraciones ni seeders de desarrollo. Después
de la suite, una consulta de sólo lectura confirmó las 47 tablas funcionales de
testing vacías, sin limpiar su caché técnica y conservando sus 32 migraciones.

QA visual: servidor separado 127.0.0.1:8108 con base efectiva de testing y raíces
aisladas de adjuntos, caché (incluida la de permisos), sesiones, vistas y logs antes
de providers. Se comprobaron login/solicitud incompleta, borrador, upload PDF real,
presentación, descarga de bytes idénticos, revisión separada, aprobación, perfil y
directorio con insignia, revocación, retirada de insignia e historial durable.
Las observaciones eran sintéticas y no acreditaban una cédula real.

Se revisaron escritorio 1280×900 y móvil 390×844 en el modo oscuro de la preferencia
actual del navegador. En móvil el documento midió 375 px, sin desbordamiento
horizontal; no hubo errores/advertencias de consola al terminar. Las clases y
estructura de modo claro se conservan; no se alteró la preferencia del sistema
para emularlo. El viewport se restableció y la pestaña se cerró. El servidor propio
se detuvo y las filas sintéticas de QA se retiraron por IDs específicos. Los
archivos sintéticos y capturas quedan como evidencias externas, fuera del repo;
no se eliminaron archivos privados funcionales ni se detuvo el servidor de desarrollo.
Una transacción de sólo lectura confirmó los recuentos de desarrollo originales:
1 usuario, 1 perfil personal, 1 adjunto; 0 perfiles profesionales, publicaciones,
comentarios, artículos, recursos, guardados, conversaciones o mensajes; 3 roles,
25 permisos, 26 concesiones de rol, 1 país, 32 estados y 2478 municipios, con 32
migraciones. El PNG privado preexistente conserva su SHA-256 y no aparecieron otros
archivos en los discos funcionales. No se cambian concesiones de desarrollo.

Evidencias en
`C:/Users/La Chepocompu/.codex/visualizations/2026/11/14/01a1d919-931b-7ef2-868f-a30ec62d0b8a/verification07/`:

- `reviewer-desktop.jpg`
- `reviewer-mobile.jpg`
- `public-verified-mobile.jpg`
- `public-revoked-mobile.jpg`

Incidencias corregidas durante el desarrollo: formato asociativo del where del
grupo de rutas, variable de contexto del historial y callback de inyección de
carrera que debía admitir modelos con columnas parciales. Se corrigieron antes
de la ejecución final, conservando las expectativas funcionales de las pruebas.

## Límites y pendientes deliberados

- No hay validación oficial automática de cédulas, OCR, IA, pagos, panel general,
  alta de revisores desde formularios ni integración nueva con LEXA.
- Antes de operar verificaciones reales se deben definir responsables, criterios
  de contraste documental y fuentes oficiales confiables. Una eventual integración
  gubernamental requerirá un canal real y autorizado, disponibilidad, términos y
  fiabilidad comprobados; no se presume una API ni una respuesta oficial.
- BACK-04 no conserva versiones completas del perfil original ni notas internas
  separadas; cuando cambia su huella no se muestran los datos nuevos como originales.
  No hay caducidad ni renovación automática; definir una política antes de agregarla.
- Retención actual sin vencimiento ni liberación automática: siguen pendientes una
  política legal explícita, liberación auditada, reconciliación y mantenimiento de
  preparados abandonados/pending_deletion. Retirar evidencia de draft sólo desasocia.
- La validación de MIME/extensión/hash no sustituye antimalware, cuarentena ni
  procesamiento estructural. No se añadieron previews ni ejecución de documentos.
- Alinear límites PHP/proxy/temporales con los límites de aplicación y proteger
  disco privado, logs, backups y restauración. Los temporales de descarga requieren
  controles operativos. El despliegue debe usar APP_DEBUG=false.
- Los expedientes propios y la bandeja están paginados; history conserva la lectura
  completa del contrato auditado. Paginación específica de eventos a gran escala
  queda para una evolución de consulta, sin alterar su carácter append-only.
- SQL directo/builders o acceso privilegiado a disco/APP_KEY pueden eludir las
  invariantes del dominio; esta interfaz no crea una frontera contra un DBA. No se
  cambian los límites de confianza de BACK-04 ni se reafirman sellos históricos.
  La rotación de APP_KEY/HMAC exige un plan para cifrado y expedientes existentes.

## Inventario exacto de archivos

24 archivos: **14 nuevos y 10 modificados**, sin eliminaciones.

Nuevos (14):

1. `app/Http/Controllers/Verification/VerificationController.php`
2. `app/Http/Requests/Verification/VerificationFormRequest.php`
3. `app/Services/Verification/VerificationWorkflow.php`
4. `docs/verification-integration.md`
5. `resources/views/components/profiles/public-professional.blade.php`
6. `resources/views/components/verification/status.blade.php`
7. `resources/views/layouts/verification.blade.php`
8. `resources/views/pages/verification/error.blade.php`
9. `resources/views/pages/verification/history.blade.php`
10. `resources/views/pages/verification/index.blade.php`
11. `resources/views/pages/verification/queue.blade.php`
12. `resources/views/pages/verification/show.blade.php`
13. `tests/Feature/VerificationIntegrationTest.php`
14. `tests/Feature/VerificationMediaIntegrationTest.php`

Modificados (10):

1. `app/Providers/AppServiceProvider.php`
2. `app/Services/Community/CommunityQuery.php`
3. `bootstrap/app.php`
4. `resources/views/components/community/post-card.blade.php`
5. `resources/views/components/home/post.blade.php`
6. `resources/views/components/profiles/verification.blade.php`
7. `resources/views/pages/profile/edit.blade.php`
8. `resources/views/pages/profile/public.blade.php`
9. `resources/views/pages/profile/show.blade.php`
10. `routes/web.php`

No se modifican migraciones, configuración de almacenamiento/despliegue, seeders,
dependencias, concesiones funcionales ni otros worktrees. Build y artefactos de
prueba no forman parte del inventario. La entrega queda local en main con el HEAD
inicial: sin staging, commit, push, merge ni cambio de rama.
