# LEXA-02-H — Primer Conector Oficial SIDOF (DOF)

## 1. Objetivo y alcance

**LEXA-02-H** implementa un prototipo profesional, desacoplado y de **solo lectura** para consultar publicaciones del Diario Oficial de la Federación (DOF) mediante la API abierta oficial de **SIDOF** (`sidof.segob.gob.mx`) y detectar posibles coincidencias con instrumentos jurídicos federales registrados en LEXA.

Este componente **no modifica** la base de datos, **no actualiza** leyes ni versiones automáticamente, **no activa** fuentes jurídicas y **no realiza** llamadas a Gemini.

---

## 2. Contrato oficial verificado de SIDOF

Documentación oficial consultada: `https://sidof.segob.gob.mx/datos_abiertos` (especificación JSON verificada en `datos_abiertos/getJSON/57`).

- **Endpoint por fecha:** `GET https://sidof.segob.gob.mx/dof/sidof/notas/{DD-MM-YYYY}`
- **Campos raíz relevantes:**
  - `messageCode` (`int`, esperado `200`)
  - `response` (`string`, esperado `"OK"`)
  - `NotasMatutinas` (`array`)
  - `NotasVespertinas` (`array`)
  - `NotasExtraordinarias` (`array`)
- **Campos por nota:** `codNota`, `titulo`, `codSeccion`, `fecha`, `codDiario`, `existeHtml`, `existeDoc`, `existeImagen`, `tipoNota`, `estado`, `pagina`, `paginaHasta`, `nombreCodOrgaUno`, `codOrgaUno`, `codOrgaDos`, `orden`.

---

## 3. Componentes implementados

1. **`config/services.php` (`services.sidof`)**
   - `base_url`: `https://sidof.segob.gob.mx` (configurable por `SIDOF_BASE_URL`)
   - `timeout`: `10` s (`SIDOF_TIMEOUT`)
   - `connect_timeout`: `5` s (`SIDOF_CONNECT_TIMEOUT`)
   - `max_response_bytes`: `2_097_152` bytes (2 MB)
   - `allowed_hosts`: `['sidof.segob.gob.mx', 'www.dof.gob.mx', 'dof.gob.mx']`

2. **`app/Services/Lexa/Sources/SidofClient.php`**
   - Valida fecha de entrada `YYYY-MM-DD` no futura (`ValidationException` en caso contrario).
   - Valida esquema `https`, ausencia de credenciales/puerto/fragmento y pertenencia a `allowed_hosts`.
   - Usa el cliente `Http` de Laravel con timeouts explícitos y límite de tamaño de respuesta.
   - Maneja errores de conexión, HTTP 4xx/5xx, JSON inválido o `messageCode !== 200` mediante `App\Exceptions\LexaUnavailableException`.
   - Normaliza `NotasMatutinas` (`MAT`), `NotasVespertinas` (`VES`) y `NotasExtraordinarias` (`EXT`), deduplica por `codNota` y descarta registros de portada/índice sin `codNota` o sin `titulo`, así como URLs fuera de `allowed_hosts`.
   - Clasifica el tipo preliminar de publicación (`decree_or_reform`, `decree`, `agreement_or_rule`, `notice`, `other`).

3. **`app/Services/Lexa/Sources/SidofReformDetector.php`**
   - Método `inspectDate(string $isoDate, ?array $knownInstruments = null, bool $dryRun = true): array`.
   - Consulta los instrumentos federales registrados en `lexa_legal_sources` (solo lectura) o un catálogo explícito.
   - Siempre devuelve `writes_performed: false`, `confirmed_reform: false` y `effective_date_confirmed: false`.

4. **`app/Console/Commands/CheckSidofPublications.php`**
   - Comando Artisan de solo lectura: `php artisan lexa:sources:sidof {date} {--dry-run} {--json}`.

---

## 4. Seguridad y reglas conservadoras de detección

- **Sin escrituras en BD:** ni `SidofClient`, ni `SidofReformDetector`, ni `CheckSidofPublications` ejecutan operaciones de escritura sobre `lexa_legal_sources` ni `lexa_legal_chunks`.
- **Clasificación conservadora:**
  - `possible_match`: coincidencia léxica/siglas contra algún instrumento federal conocido; marca `requires_human_review = true` y **nunca** afirma reforma confirmada ni vigencia.
  - `needs_human_review`: publicación normativa (`decree_or_reform` o `decree`) sin coincidencia directa con el catálogo actual.
  - `unmatched`: acuerdos, circulares, avisos u otros actos no clasificados como posible coincidencia.

---

## 5. Demostración controlada en vivo

1. **Diagnóstico previo:**
   - `/dof/sidof/notas/porFecha/14-11-2025` devolvió **HTTP `404`** (`latency_ms: 365`).
   - `/dof/sidof/notas/14-11-2025` con `Accept: application/json` (`->acceptJson()`) devolvió **HTTP `406`** (*Not Acceptable*, `latency_ms: 387`).
2. **Consulta en vivo tras ajustar `Accept: application/json, text/plain, */*`:**
   - Comando: `php artisan lexa:sources:sidof 2025-11-14 --dry-run --json`
   - Endpoint: `GET /dof/sidof/notas/14-11-2025`
   - Resultado: **HTTP `200`** (`messageCode: 200`), salida con código `0`, `dry_run: true`, `writes_performed: false`.
   - Conteo real: **`107` publicaciones** (`possible_match: 0`, `needs_human_review: 4`, `unmatched: 103`).

---

## 6. Validaciones ejecutadas

### Pruebas automatizadas (`APP_ENV=testing`, `DB_DATABASE=plataforma_legal_v2_test`, almacenamiento externo en `$env:TEMP`)

- `tests/Feature/SidofConnectorTest.php`: **10 passed** usando `Http::fake()` y `Http::preventStrayRequests()` (incluyendo regresión de cabecera `Accept: application/json, text/plain, */*` y rechazo de HTTP 200 sin colecciones `Notas*` válidas).
- Suite combinada (`SidofConnectorTest`, `LegalSourceIngestionTest`, `LexaSourceRetrievalTest`, `LexaRagIntegrationTest`): **35 passed (292 assertions)**.

### Calidad de código y estado del entorno de desarrollo

- `./vendor/bin/pint --test` sobre los archivos creados/modificados: **passed**.
- `php -l` sobre los 5 archivos PHP nuevos/modificados: **sin errores de sintaxis**.
- `git diff --check`: **limpio**.
- Base de desarrollo (`plataforma_legal_v2`): `sources=6`, `verified=0` (intacta).
- Documento previo `docs/lexa-02-g-legal-data-apis-research.md`: preservado íntegramente sin modificaciones.

---

## 7. Exclusiones deliberadas y siguientes pasos

- **Fuera de alcance en LEXA-02-H:** descarga o ingesta automática de decretos, verificación automática, llamadas a Gemini, jobs programados (`Schedule`), panel administrativo, migraciones y commits/push.
- **Siguiente paso sugerido:** diseñar en una fase posterior el flujo de revisión humana asistida (cola de candidatos a reforma) previa a cualquier ingesta controlada con `LegalSourceIngestion`.
