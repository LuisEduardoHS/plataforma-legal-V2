# MEDIA-02-B — Auditoría y preparación de integración

Base: main 52e5c0812a191be8973038a35bfaf4d2c668b534. Rama integracion/media-02-b, worktree D:/projects/plataforma-legal-V2-media-integration. Original MEDIA preservado en efc4e54; DEPLOY sin modificaciones. No merge/push a main.

## Clasificación del inventario original (29 modificados, 9 nuevos)

A Funcional: AttachmentService.php y config/attachments.php modificados; AttachmentQuota.php y ReconcileMedia.php nuevos. Se incorporan sin cambios de dominio adicionales.

B Pruebas MEDIA: MediaQuotaReconciliationTest.php, nueve escenarios incluido dos procesos reales. Se sustituye requisito de bootstrap externo Codex por tests/Support/media-worker-bootstrap.php portable.

C Seguridad general: 26 archivos Feature existentes cambian referencias rígidas de la base compartida por TestingIsolation::database(), configurable por EXPECTED_TEST_DB con fallback DB_DATABASE y validación _test. Conservan guards existentes y conexiones secundarias. Se incorpora helper general Tests/Support/TestingIsolation.php, sin nombre Codex ni rutas personales. El TestCase oficial de DEV-01 se mantiene byte a byte, incluyendo guard antes de traits. No se duplica ni sustituye por el antiguo createApplication exclusivo.

D Local Codex excluido: tests/codex-bootstrap.php y versión exclusiva del helper y TestCase. .env.testing ignorado, credenciales y launcher externo no se versionan. La ejecución normal usa phpunit.xml con bootstrap vendor/autoload.php y configuración de entorno propia del operador. El worker usa Laravel normal y el guard general, sin depender de MEDIA_TEST_BOOTSTRAP externo.

E Documentación: se preserva MEDIA-01 íntegro y se incorpora MEDIA-02-A con estado inicial actualizado; propuesta/local DEV-01-B quedan solo en original como evidencia histórica, no se incorporan a esta rama. Este informe documenta decisiones de integración.

## Compatibilidad y conflictos

Desde efc4e54: 3912cda conector SIDOF, 9f54bee seguimiento de publicaciones, 52e5c08 DEV-01. No modifican los cuatro archivos funcionales MEDIA. Se parte directamente de main confirmado y se traslada el inventario permitido; no se hace merge/reset del original. Conflicto semántico TestCase resuelto conservando versión oficial, no por ours/theirs global. Ningún código SIDOF, LEXA, frontend ni migración reemplazado.

## Auditoría funcional

Reserva: usuario persistido bloqueado exclusivo, validación de capacidad y pending_upload en misma transacción; commit antes de subir. Lectura current/locking evita snapshot obsoleto RR. Suma todos los discos y estados excepto deleted, sin duplicar por pivots. Falta de asociación no libera cuota. Retención profesional/editorial e historial siguen por reglas existentes. Desactivar cuota es configuración del servidor, no del cliente.

Orden usuario→adjunto en reserva, finalización y compensación evita inversión observada en prueba anterior. No se afirma ausencia universal de deadlocks con cualquier operación; operaciones multiusuario/roles y I/O remoto requieren futuros ensayos. Un deadlock falla de forma segura sin exceder cuota; no se añaden reintentos de I/O automáticos.

Fallo físico: reserva durable conserva key; cleanup fallido mantiene pending_upload/pending_deletion contabilizados; deleted solo tras confirmación de borrado. Un fallo DB tras I/O mantiene contabilización conservadora. Caída del proceso puede dejar reserva pendiente: diagnóstico, no liberación por edad. No hay idempotencia de uploads completos por token; cargas nuevas se cobran separadas. Purga/repetición de borrado conservan máquina existente.

media:reconcile exige --dry-run, solo SELECT y exists/size/listContents; no descarga contenido, no modifica filas/objetos, no automatiza reparaciones. Considera todas las relaciones, incluso revisiones/historial; cero relaciones es candidato, no prueba de orfandad. Salida con IDs y hashes opacos, sin rutas/nombres. Root local ausente no se crea. Enumeración solo local/acotada, no listado cloud masivo. No es snapshot atómico y puede advertir carreras de una subida legítima.

Riesgos aceptados: suma O(n) y locks por propietario; I/O aún bajo locks; diagnóstico hasta 5000 filas sin cursor y varias consultas por fila; metadatos corruptos/objetos desconocidos no son una cuota exacta de disco, requieren operación posterior autorizada. No antivirus/cloud/derivados ni endpoints nuevos. No migración MEDIA. Default 1 GiB por usuario necesita política operativa antes de producción.

## Ejecución reproducible sin configuración Codex obligatoria

Preparar .env.testing propio ignorado: APP_ENV=testing, DB_CONNECTION=mysql, DB_DATABASE=<base propia _test>, EXPECTED_TEST_DB mismo valor, CACHE_STORE=array, SESSION_DRIVER=array, QUEUE_CONNECTION=sync, LARAVEL_STORAGE_PATH externo y directorios framework/cache/data, framework/sessions, framework/views, logs y app/attachments. Aislar también APP_CONFIG_CACHE/APP_SERVICES_CACHE/APP_PACKAGES_CACHE/APP_ROUTES_CACHE/APP_EVENTS_CACHE. No usar cachés generadas en otro entorno. Aplicar migraciones solo tras guard de destino efectivo. Ejecutar php vendor/phpunit/phpunit/phpunit --filter MediaQuotaReconciliationTest con bootstrap oficial. proc_open requerido para concurrencia; no omitir silenciosamente la prueba.

Esta intervención utiliza exclusivamente plataforma_legal_v2_codex_test y launcher local externo (sin secretos en Git), que inyecta configuración y guard estricto Codex antes de CLI. No llama Gemini/SIDOF reales. Al integrar mantener EXPECTED_TEST_DB configurable: la suite del resto del equipo no debe necesitar nombres o rutas de Codex.

## Validaciones

Resultados definitivos se añaden al completar ejecuciones. Frontend: 149 pruebas aprobadas; Vite build aprobado. No se ejecutó despliegue.
La compatibilidad del helper se verifica además con tests/Unit/TestingDatabaseTargetTest.php: 1 prueba / 4 assertions sin framework ni DB, usando nombres alternativos solo como strings. No conecta a Antigravity. TestCase.php y phpunit.xml oficiales sin cambios. El launcher local inicial se corrigió por inicializar el manejador PHPUnit antes de su configuración; ese fallo ocurrió antes de los tests, no fue un defecto de MEDIA. La ejecución final usa bootstrap oficial vendor/autoload.php.


## Inventario final (28 modificados, 9 nuevos)

- app/Console/Commands/ReconcileMedia.php
- app/Services/Attachments/AttachmentQuota.php
- app/Services/Attachments/AttachmentService.php
- config/attachments.php
- docs/media-01-storage-audit.md
- docs/media-02-a-storage-quotas-reconciliation.md
- docs/media-02-b-integration-review.md
- tests/Feature/AccessibilityComponentsIntegrationTest.php
- tests/Feature/AccountNavigationIntegrationTest.php
- tests/Feature/AttachmentTest.php
- tests/Feature/BackendAuditConcurrencyTest.php
- tests/Feature/CommunityConcurrencyTest.php
- tests/Feature/CommunityTest.php
- tests/Feature/ContentLibraryConcurrencyTest.php
- tests/Feature/ContentLibraryTest.php
- tests/Feature/ErrorRecoveryIntegrationTest.php
- tests/Feature/GeographicRelationsTest.php
- tests/Feature/GeographicSeederTest.php
- tests/Feature/IntegrationAuditFixesTest.php
- tests/Feature/LandingIntegrationTest.php
- tests/Feature/LegalSpecialtyTest.php
- tests/Feature/MediaQuotaReconciliationTest.php
- tests/Feature/MessagingConcurrencyTest.php
- tests/Feature/MessagingMediaIntegrationTest.php
- tests/Feature/MessagingTest.php
- tests/Feature/OperationsConcurrencyTest.php
- tests/Feature/ProfileTest.php
- tests/Feature/PublicNavigationIntegrationTest.php
- tests/Feature/RolePermissionTest.php
- tests/Feature/SharedCatalogTest.php
- tests/Feature/UserDatabaseTest.php
- tests/Feature/VerificationConcurrencyTest.php
- tests/Feature/VerificationMediaIntegrationTest.php
- tests/Feature/VerificationTest.php
- tests/Support/media-worker-bootstrap.php
- tests/Support/TestingIsolation.php
- tests/Unit/TestingDatabaseTargetTest.php

## Resultados finales
337 pruebas Laravel / 3566 assertions aprobadas, incluidos MEDIA, Attachment, Comunidad, Mensajes, Editorial, Perfiles, retenciones y Lexa*. Prueba unitaria de target configurable: 1 / 4 assertions aprobada con PHPUnit estándar. Frontend 149/149; Vite build, Pint de 34 PHP, sintaxis y whitespace aprobados. No se ejecutó suite Laravel global. La migración SIDOF pendiente de main se aplicó únicamente en Codex, después de preflight efectivo; MEDIA no añade migraciones. Las escrituras de fixtures son exclusivamente sintéticas en Codex y storage temporal externo; no Gemini/SIDOF real.

Comprobación posterior: Codex tiene 39 migraciones y 58 tablas (incluido SIDOF de main). Hashes de columnas/conteos de desarrollo, shared legacy y Antigravity idénticos a la referencia previa. Solo SELECT sobre esas bases; no comparación byte a byte de filas. Original MEDIA conserva 29 modificados/9 nuevos sin staging; DEPLOY solo su informe; main limpio y sincronizado.
