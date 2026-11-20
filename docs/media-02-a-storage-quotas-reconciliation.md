# MEDIA-02-A — Cuotas y reconciliación diagnóstica

Fecha: 20 de noviembre de 2026. Base efc4e5425d384223a44fc8f9d0baaba35e472c02. Worktree D:/projects/plataforma-legal-V2-media-audit, rama audit/media-storage.

## Estado de entrega

Validación pendiente COMPLETADA en DEV-01-B: nueve pruebas MEDIA (56 assertions), 231 de integración (2468 assertions) y 77 de Editorial/retenciones (832 assertions), exclusivamente en plataforma_legal_v2_codex_test. Se conserva abajo el historial de la interferencia anterior. MEDIA-02-B prepara una rama basada en main 52e5c08, sin el bootstrap local Codex como requisito general. Los documentos locales DEV-01-B/propuesta permanecen en el worktree original; no son dependencias de ejecución.

MEDIA-01 se conserva sin sobrescribir y sin commit. Su hash SHA-256 al cerrar la revisión estática es 407E5381E2F37FF285FB4B0DB8E9EF167F594D989E32670BBAFFE3C62FFD5EF3. No se trabajó sobre archivos fuente de main ni sobre el conector SIDOF. Para las pruebas iniciales se leyó únicamente la conexión testing ya configurada, sin imprimir credenciales ni copiar/modificar .env; la aplicación, vendor, caches y storage usados pertenecen al worktree/ejecución aislados.

## Auditoría focalizada de caminos

| Camino | Reserva/almacenamiento | Asociación/desvinculación | Retención/borrado |
| --- | --- | --- | --- |
| Comunidad | Community/PostSubmission llama AttachmentService::store antes de transacción del post | PostService sincroniza post_attachments | Compensación solicita eliminación; referencias de publicaciones se comprueban antes de purgar |
| Mensajes | MessageUploadService valida conversación/bloqueo, store por archivo y revalida contexto | MessageService asocia message_attachments; retirada de uploads vía controlador | Mensajes vigentes e historial accesible conservan sus controles, sin cambio de permisos |
| Editorial | EditorialSubmission llama store antes de mutar contenido | EditorialService asocia al contenido y snapshots de revisiones | article_attachment, legal_resource_attachment y content_revision_attachments impiden liberar objetos retenidos |
| Perfil | ProfilePhotoController usa store y ProfileService valida la relación | Sustituir/quitar foto sólo modifica photo_attachment_id | Desvincular no confirma borrado físico y no libera cuota |
| Verificación | VerificationWorkflow usa store y luego el servicio de verificación | verification_evidences conserva asociación/fingerprint | verification_retained_at y evidencias presentadas impiden purga |
| Base | Única creación de Attachment de producción encontrada: AttachmentService::store | No hay cuota por pivote ni por pantalla | requestDeletion conserva intención; purge/cleanupFailedUpload confirman estado deleted tras éxito de Storage |

Se mantienen AttachmentValidator (MIME real, tamaño/hash y extensión), AttachmentPolicy, UUID, visibilidad privada, máquina de estados, reglas de asociación, autorización por recurso y retenciones existentes. No se habilitan endpoints públicos ni procesos de limpieza automáticos.

## Cuota acumulada

Se añade AttachmentQuota, resuelto por DI en AttachmentService. Configuración en config/attachments.php:

| Clave | Valor inicial |
| --- | --- |
| attachments.quota.enabled / ATTACHMENTS_QUOTA_ENABLED | true |
| attachments.quota.per_user_bytes / ATTACHMENTS_QUOTA_BYTES | 1,073,741,824 bytes (1 GiB) |

No se modificó ningún .env. Cero bytes habilitado significa no permitir nuevas cargas; para desactivar se debe usar enabled=false. limitFor(User) es el punto de extensión confiable para políticas futuras por cuenta. No consulta un plan enviado por el cliente ni implementa facturación/roles nuevos.

La cuenta total usa las filas del propietario en TODOS los discos, independientemente de las relaciones. Incluye pending_upload, available y pending_deletion; excluye sólo deleted confirmado por el ciclo existente. Un archivo compartido por varios contextos se cuenta una vez. Una evidencia retenida sigue contando. Quitar la foto, desasociar del post, ocultar un mensaje o solicitar eliminación NO libera cuota.

No hay contador incremental ni tabla nueva: se usa el catálogo existente como fuente de verdad. No hay migración de MEDIA-02-A ni backfill de datos. Esto evita drift de un contador al fallar I/O o rollback. El hash de un archivo no produce deduplicación de cuota ni concede acceso a otros usuarios.

### Reserva atómica

1. Validar archivo y rechazar transacciones ambientales como antes.
2. Abrir transacción corta, bloquear la fila User con FOR UPDATE y revalidar cuenta/policy.
3. Leer con locks actuales las filas de adjuntos no eliminados de ese propietario, sumar size_bytes y comparar contra la cuota. No depender de un snapshot RR anterior.
4. Crear pending_upload en la MISMA transacción y confirmar antes de escribir el objeto.
5. Realizar almacenamiento/finalización existente. Se añade lock compartido del propietario ANTES del lock del adjunto en finalización y compensación para mantener orden usuario → adjunto frente a la reserva.

Las reservas concurrentes de un mismo propietario se serializan mediante su fila User aunque no existan adjuntos todavía, evitando el problema del conjunto vacío. Propietarios diferentes no comparten un contador global. El rechazo por cuota es ValidationException con mensaje fijo en file; no devuelve consumo, nombres, rutas ni información de otras cuentas.

Una primera prueba de concurrencia real detectó deadlock al finalizar el objeto mientras otra reserva tomaba locks en orden distinto. Se corrigió el orden antes de considerar la prueba aprobada. Con dos procesos PHP/MySQL iniciados mediante barrera, cuota para un solo archivo y espera artificial dentro de creating: un proceso completa la reserva/carga y el otro recibe cuota; la suma final no supera el límite.

### Fallos e idempotencia

Una reserva fallida en MySQL revierte sin escribir Storage ni cobrar filas inexistentes. Tras fallar I/O, la reserva durable queda contabilizada hasta que la compensación confirme ausencia/borrado físico y estado deleted. Si esa compensación falla, pending_upload o pending_deletion conserva el cargo. Si el objeto se borró pero falló la actualización SQL, se cobra conservadoramente hasta reintento confirmado.

requestDeletion y purge repetidos no descuentan dos veces: no se resta un contador. Cada upload independiente crea un objeto/reserva propios, incluso si el contenido coincide; no se añadió idempotency key de subida ni deduplicación automática. Los controles de idempotencia de mensajes y retención de documentos se conservan.

## media:reconcile

```console
php artisan media:reconcile --dry-run
php artisan media:reconcile --dry-run --enumerate --limit=500 --older-hours=24
```

Sin --dry-run se rechaza la ejecución. No hay --apply ni método de reparación. --limit se acota a 1..5000, older-hours a 0..87600; la operación es un diagnóstico potencialmente parcial, no inventario consistente en una transacción global.

Comprueba reservas antiguas, eliminación pendiente, metadatos inválidos, objeto disponible ausente, diferencia de tamaño, objeto presente pese a deleted, referencias/retención incompatibles con deleted y estados no disponibles retenidos. Usa SELECT/exists y metadatos de Storage; no lee ni descarga contenido ni compara hashes completos del objeto.

Considera post_attachments, message_attachments, article_attachment, legal_resource_attachment, content_revision_attachments, verification_evidences y user_profiles, sin descartar relaciones históricas por no verse en una pantalla. Cero relaciones genera únicamente unlinked_candidate_not_proven_orphan: no autoriza borrado. Un marcador de retención profesional excluye esa clasificación.

Enumeración opt-in: sólo disco local privado configurado, prefijo attachments, iterador de archivos y límite. No lista buckets remotos ni otros discos arbitrariamente. Cada clave sin fila conocida se identifica mediante hash opaco truncado, sin imprimir ruta. Si se alcanza el límite, informa truncamiento; no garantiza cubrir más de 5000 registros ni que una ejecución repetida avance como un cursor.

La salida muestra IDs internos, códigos y conteos. Nunca nombres originales, usuarios/emails, rutas, URL de descarga o errores completos de Storage. Un root local inexistente se informa sin construir el adaptador, evitando que éste cree el directorio al inicializarse. No hay escritura de filas, objetos, carpetas ni cambios de estado por parte del comando.

El diagnóstico puede observar una subida o asociación a medio completar; sus candidatos requieren revisión posterior. Los objetos sin fila, mutaciones por SQL directo, objetos recreados externamente tras deleted y tamaños físicos alterados no pueden atribuirse a una cuota con certeza. Se reportan; no se ajusta cuota automáticamente.

## Validación realizada y pendiente

Aislamiento inicial confirmado dentro del proceso: APP_ENV=testing, driver MySQL y SELECT DATABASE() = plataforma_legal_v2_test; caché/sesiones array, storage externo C:/Users/La Chepocompu/.codex/visualizations/media-02-a-1795201327/storage; caches bootstrap/PHPUnit externos. Se instaló vendor local sin scripts de Composer. El build local se generó para resolver un manifiesto Vite ausente en las pruebas de vistas, sin modificar frontend fuente.

- Prueba focalizada inicial del nuevo código: 7 tests, 44 assertions, aprobados tras resolver el deadlock. Incluye concurrencia real con dos procesos, cuota por propietario, desactivación, reserva pendiente, eliminación/idempotencia, evidencia retenida y referencias compartidas, fallos I/O/DB y diagnóstico sin mutaciones/exposición.
- Se añadieron después dos regresiones diagnósticas (objeto ausente/tamaño/deleted y root inexistente). Están revisadas estáticamente y con sintaxis, pero NO ejecutadas por la pausa del usuario.
- La ejecución ampliada de 218 tests terminó con 9 errores y 3 fallos, 2272 assertions. Hubo desaparición/recreación de tablas en la BD compartida: users/attachments ausentes y users sin status. Los tests seleccionados no contienen DDL. No se considera una validación verde ni se atribuye un autor al proceso concurrente. Los fallos de integración quedan NO CONCLUYENTES hasta repetir en base exclusiva.
- No se reparó/reconstruyó la base compartida ni se repitieron escrituras después de la instrucción de pausa. No se eliminan por IDs antiguos fixtures potencialmente afectados por un reset ajeno, ya que esos IDs podrían haberse reutilizado.
- Pint, sintaxis y whitespace se comprueban sin conectar a MySQL. El build local pasó; no es evidencia de corrección de cuotas.

Pendiente: aislar runner/guards en plataforma_legal_v2_codex_test con preflight y permisos; repetir los nueve tests MEDIA actuales y las suites de Attachment, Comunidad, Mensajes, Editorial, Perfiles y retención. El cambio de nombre exige adaptar guards distribuidos; no basta un .env diferente. Ver propuesta separada.

## Limitaciones y siguiente etapa

La suma usa una lectura bloqueante de filas por cuenta: costo O(n) y memoria O(n) en número de adjuntos. Es una primera protección correcta en los escenarios focalizados probados, no un benchmark de escala. Más adelante medir agregación/counter durable e índices sin sacrificar atomicidad.

La finalización de Storage ya tenía I/O bajo lock de adjunto; el lock compartido del usuario puede retrasar otras reservas/operaciones de esa cuenta. Se conserva ese funcionamiento por seguridad. Antes de cloud/volúmenes altos, diseñar objetos inmutables y finalización corta fuera de I/O remoto, con pruebas de revocación/retención. No quitar locks para ocultar el problema.

No se implementan antivirus, cloud, planes de pago, borrado automático, thumbnails, video, firmas públicas ni adjuntos LEXA. No hay cambios de esquema ni migraciones pendientes propias de MEDIA. El comando es manual y diagnóstico; no se registró scheduler.

Próxima intervención: preflight y aislamiento de testing Codex, adaptación de guards y validación pendiente. Sólo después revisar MEDIA-02-A para respaldo/integración. DEPLOY-01 se limita a prerrequisitos estáticos documentados en la propuesta; no se aprovisionó ni desplegó nada.

## Inventario

Modificados:
- app/Services/Attachments/AttachmentService.php
- config/attachments.php

Nuevos de MEDIA-02-A:
- app/Services/Attachments/AttachmentQuota.php
- app/Console/Commands/ReconcileMedia.php
- tests/Feature/MediaQuotaReconciliationTest.php
- docs/media-02-a-storage-quotas-reconciliation.md
- docs/codex-testing-isolation-proposal.md

Previo conservado, sin commit:
- docs/media-01-storage-audit.md

Sin staging, commit, push ni merge. La rama sigue en el HEAD base de MEDIA-01; no se incorpora trabajo SIDOF pendiente.

## Reanudación con aislamiento DEV-01-B
La pausa anterior queda resuelta mediante plataforma_legal_v2_codex_test, con guard previo a traits y storage externo exclusivo. Se aplicaron 38 migraciones sobre la base Codex inicialmente vacía. Las nueve pruebas MEDIA pasan (56 assertions); integración ampliada: 231 pruebas, 2468 assertions aprobadas. La incidencia antigua se conserva como antecedente y no se presenta como ejecución aprobada. Detalles e inventario adicional de testing en docs/dev-01-b-codex-testing.md. Sin migraciones propias de MEDIA, staging, commit, merge ni push.


Validación complementaria EditorialMediaIntegrationTest, ContentLibraryTest y VerificationTest: 77 pruebas / 832 assertions aprobadas. Se incluyen retenciones editoriales y evidencia profesional. Las ejecuciones se reportan separadas porque algunos filtros podrían solaparse; no sumar como cobertura única.


## Preparación MEDIA-02-B
Integración sobre main 52e5c08: 337 pruebas Laravel / 3566 assertions aprobadas, prueba unitaria de configuración 1 / 4, frontend 149, build/Pint/sintaxis/whitespace aprobados. Se conserva TestCase oficial DEV-01 y se generalizan guards distribuidos; el runner Codex no es requisito versionado. Inventario final y decisiones en docs/media-02-b-integration-review.md. No merge a main ni push.
