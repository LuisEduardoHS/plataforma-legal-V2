# LEXA-02-C — RAG y referencias documentales verificables

Fecha: 20 de noviembre de 2026. Base: main `128bc3c810d9feb25181bd04f55cad25f9eb7079`, inicialmente limpio. Sin commit ni push.

## Arquitectura

Se reutilizan LexaSourceRetriever, LegalSourceIngestion, LexaConversationService, LexaPrompt y GeminiAiProvider. No hay servicios externos nuevos, embeddings, colas ni dependencias nuevas.

1. La transacción de reserva existente autoriza al usuario, bloquea conversación/mensaje y reserva un único intento. Conserva tokens e idempotencia.
2. Fuera de esa transacción, LexaLegalContext consulta FULLTEXT sobre fuentes elegibles. A continuación una transacción corta revalida usuario, conversación e intento y guarda retrieval_json en la generación pendiente.
3. El proveedor recibe el historial acotado y la instantánea documental. La llamada HTTP ocurre fuera de transacciones SQL. No se cachean decisiones de autorización.
4. Antes de confirmar la respuesta, cada chunk_id solicitado por el proveedor debe pertenecer al contexto recuperado de ese intento. Una referencia inventada, de tipo incorrecto o ajena al contexto hace fallar el intento sin mensaje de asistente. Las referencias repetidas se deduplican. Los campos de fuente/URL/artículo aportados por el proveedor se ignoran: se reconstruyen desde la instantánea del servidor.
5. La transacción final conserva la revalidación de identidad, estado activo, token y último mensaje. Guarda citas y metadatos junto a la respuesta. No se vuelven a resolver las citas históricas consultando el corpus actual.

Un fallo de recuperación o proveedor marca el intento como fallido siguiendo el mecanismo existente. No hay reenvío ni retry automático. Un nuevo intento manual puede volver a recuperar fuentes; la instantánea de una generación fallida representa su último intento, no un diario inmutable de todos los intentos fallidos. Las respuestas confirmadas conservan su propia instantánea/citas.

## Recuperación y jurisdicción

El recuperador mantiene filtros de is_verified, superseded_by y fechas de aplicabilidad. Las importaciones nuevas requieren verified_at y valid_from. Se amplía el DTO con source_id, estado/nombre, versión, hashes, publicación, consulta e intervalo de aplicabilidad.

LexaLegalContext admite como máximo cuatro fragmentos; cada extracto procede de hasta 1,200 caracteres del chunk original. El presupuesto documental predeterminado es 6,000 caracteres de JSON serializado, incluyendo metadatos y separadores; la configuración está limitada entre 100 y 12,000. El historial del proveedor conserva su límite de 24,000 caracteres y mensajes, más este presupuesto documental separado. La salida mantiene el límite previo de tokens.

Se exige HTTPS y host federal oficial exacto también a filas legacy; no se aceptan credenciales ni puertos en enlaces. No se descargan esas URL. Corpus estatal y federal se filtran por la entidad explícita; no se mezclan entidades.

GenerateLexaReplyRequest acepta state_id opcional validado contra el catálogo. La interfaz ofrece «Sólo federal» como valor inicial y permite elegir «Federal y entidad». Sin selección no se deduce entidad del texto, perfil o historial: se limita explícitamente al ámbito federal y el proveedor debe pedir aclaración cuando el asunto necesite legislación local. El estado y nombre aplicados quedan en la respuesta histórica, aunque el selector cambie después.

La ingesta de 02-B continúa siendo federal. Las fuentes estatales existentes son soportadas por el recuperador y se prueban con fixtures; esta etapa no añade un importador estatal ni autoriza poblarlo mediante SQL directo. Antes de un corpus estatal real se requiere su flujo de procedencia/revisión correspondiente.

## Contrato y seguridad del contexto

LexaPrompt añade legalSources, stateId y stateName con valores por defecto compatibles. Los documentos se adjuntan como otra parte de contenido de usuario serializada en JSON, nunca dentro de systemInstruction. Las reglas privilegiadas son constantes: no obedecer instrucciones del documento, no inventar artículos/fechas/citas, reconocer evidencia insuficiente y no extrapolar jurisdicción ni vigencia.

Con documentos, GeminiAiProvider utiliza el contrato REST existente generateContent con generationConfig.responseMimeType=application/json y responseSchema sencillo: answer y citation_ids enteros. Se valida la estructura de la respuesta y posteriormente la pertenencia de IDs en el servicio. Sin documentos conserva su contrato de texto, con instrucciones de orientación general y ausencia de evidencia; no se presupone que haya un corpus cargado.

Referencia del formato estructurado: [documentación oficial de Gemini](https://ai.google.dev/gemini-api/docs/structured-output). Se probó con Http::fake; no se realizó validación real contra un modelo ni se migró de API.

Las reglas reducen exposición a prompt injection, pero no demuestran que un modelo real nunca siga un documento malicioso. No existen herramientas de ejecución para el modelo. La validación del servidor acredita pertenencia y procedencia documental, no equivalencia semántica entre cada afirmación y el artículo citado; el texto libre todavía puede contener errores. El renderer no convierte enlaces escritos por el modelo en referencias oficiales.

## Citas y trazabilidad persistente

Se reutiliza citations_json del mensaje. Cada cita contiene source_id, chunk_id, chunk_hash, document_hash/version_key cuando existen, instrumento, tipo de norma, artículo, parte, encabezado disponible, jurisdicción/estado, editor/URL, publicación, reforma, consulta, intervalo de aplicabilidad y fecha de recuperación. No se inventa una sección o versión ausente.

La nueva migración aditiva 2026_11_20_000005 añade:

- lexa_generations.retrieval_json: contexto exacto recuperado, alcance y fecha, vinculado al intento mediante la generación/token existentes.
- lexa_messages.grounding_json: alcance/estado y contadores de fragmentos recuperados/citados.

Son columnas JSON anulables; no cambian filas anteriores. La reversión se bloquea si existe trazabilidad persistida para evitar su pérdida. Las referencias guardan copias de los metadatos, evitando cambios retroactivos por reformas o revisiones posteriores del corpus. No se presenta este registro como una auditoría inviolable ante SQL directo.

Las fuentes legacy pueden carecer de versión, hash de documento o prueba temporal de revisión. Conservan el contrato de elegibilidad de 02-B, pero se muestran con vigencia no confirmada. El identificador de fuente/chunk y su hash siguen identificando el fragmento. El primer corpus operativo debe usar la ingesta verificada con metadatos completos; no se certifican retrospectivamente filas legacy.

## Interfaz

renderLegalCitations crea nodos DOM mediante textContent, con lista y región accesible. Presenta norma/artículo/parte, jurisdicción, versión, reforma, intervalo y fecha de recuperación. Los enlaces se restringen de nuevo a HTTPS/hosts oficiales y usan noopener noreferrer. Se advierte que la aplicabilidad fue revisada al recuperar y no confirma vigencia actual; la carencia de confirmación se señala expresamente.

Una respuesta sin citas validadas se presenta como orientación general sin fundamento documental suficiente. Las respuestas históricas sin grounding se identifican como carentes de trazabilidad registrada. Se mantiene el Markdown seguro ya existente y el ciclo de montaje/desmontaje de navegación; no hay almacenamiento compartido de citas en el cliente.

## Verificación y aislamiento

Antes de cualquier fixture se verificó dentro del proceso APP_ENV=testing, MySQL efectivo plataforma_legal_v2_test, sesiones/caché array y almacenamiento privado externo. La migración 000005 se aplicó sólo en testing y por su ruta exacta; 000004 ya estaba aplicada allí. No se aplicaron migraciones ni se importaron fuentes reales en desarrollo.

- Laravel: 47 pruebas focalizadas, 380 assertions, aprobadas. Incluyen ingesta/recuperación existentes, persistencia/generación, errores de proveedor y Gemini simulado; además ocho escenarios RAG nuevos y dos escenarios del adaptador Gemini.
- Frontend: 148 pruebas aprobadas, incluidas tres nuevas sobre citas seguras, enlaces y advertencias.
- Build Vite aprobado; Pint y sintaxis de los PHP afectados aprobados; whitespace revisado también en archivos nuevos.
- QA de navegador con servidor HTTP de testing y FakeAiProvider: login sintético, mensaje/resultado/cita, recarga y apertura del historial móvil; una referencia persistida, sin desbordamiento a 390px ni errores de consola. Servidor detenido y fixtures propios retirados al terminar. Sin Gemini real.

Las pruebas cubren fuentes no verificadas/vencidas, alcance federal/estatal explícito, presupuesto, versiones importadas y sustituidas, referencias inventadas, inmutabilidad histórica, ausencia de locks durante el proveedor, protección de otro usuario, errores y reintentos. Las regresiones existentes de concurrencia/idempotencia siguen aprobadas. Una regresión adicional revoca al actor durante la recuperación y comprueba revalidación antes del proveedor, ausencia de respuesta y conservación del 403. Las pruebas de inyección verifican la separación entre instrucciones y datos, no la conducta de un modelo real.

## Primer corpus real — intervención posterior

1. Revisar/resguardar el esquema y comprobar explícitamente entorno, conexión y SELECT DATABASE() antes de cualquier migración de desarrollo.
2. Revisar y aplicar por ruta exacta las migraciones pendientes 000004 (02-B) y 000005 (02-C), en orden. No están aplicadas en desarrollo por esta tarea. El código de recuperación necesita estas columnas antes de utilizarse allí; no activar el flujo nuevo con esquema incompleto.
3. Seleccionar un corpus federal pequeño y localizar los documentos en Cámara de Diputados/DOF. Cotejar publicación, reformas, alcance del texto y aplicabilidad. No inferir vigencia de la URL ni realizar descargas masivas.
4. Preparar manifiesto y texto UTF-8 fuera del repositorio según docs/lexa-02-b-legal-source-ingestion.md; utilizar lexa:sources:import con --apply. El documento sigue no verificado.
5. Revisar artículos/fragmentos, hash, fuente y fechas. Usar lexa:sources:verify con ID, hash, revisor, nota y --apply. Conservar versiones anteriores y respaldos originales bajo el procedimiento de corpus.
6. Probar recuperación local y citas sin proveedor real primero. Una validación E2E real posterior requiere autorización expresa y un límite de llamadas; no se hizo en esta intervención.

## Inventario

- `app/Http/Controllers/Lexa/LexaConversationController.php`
- `app/Http/Requests/Lexa/GenerateLexaReplyRequest.php`
- `app/Models/LexaGeneration.php`
- `app/Models/LexaMessage.php`
- `app/Services/Lexa/Data/LexaPrompt.php`
- `app/Services/Lexa/LexaConversationService.php`
- `app/Services/Lexa/LexaLegalContext.php`
- `app/Services/Lexa/LexaQuery.php`
- `app/Services/Lexa/LexaSourceRetriever.php`
- `app/Services/Lexa/Providers/GeminiAiProvider.php`
- `config/lexa.php`
- `database/migrations/2026_11_20_000005_add_lexa_grounding_snapshots.php`
- `docs/lexa-02-c-rag-citations.md`
- `resources/css/lexa.css`
- `resources/js/lexa.js`
- `resources/js/lexa/citations.js`
- `resources/views/components/lexa/composer.blade.php`
- `resources/views/pages/lexa/index.blade.php`
- `tests/Feature/GeminiAiProviderTest.php`
- `tests/Feature/LexaRagIntegrationTest.php`
- `tests/frontend/lexa-citations.test.mjs`
