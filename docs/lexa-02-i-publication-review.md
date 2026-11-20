# LEXA-02-I — Seguimiento Persistente y Revisión Humana de Publicaciones SIDOF

## 1. Objetivo y alcance

**LEXA-02-I** extiende el conector oficial SIDOF (`LEXA-02-H`) para persistir de forma idempotente las publicaciones potencialmente relevantes detectadas en el DOF y habilitar un flujo trazable de revisión humana.

**Restricciones preservadas:**
- Nunca confirma reformas jurídicas automáticamente (`confirmed_reform` permanece `false`).
- Nunca modifica ni activa registros en `lexa_legal_sources` ni `lexa_legal_chunks`.
- No incluye jobs programados ni llamadas a Gemini.

---

## 2. Arquitectura y reutilización

Se reutilizan sin duplicación `SidofClient` y `SidofReformDetector` (`LEXA-02-H`), incorporando:

1. **Persistencia aditiva:** migración `database/migrations/2026_11_20_000006_create_lexa_sidof_publications_table.php` con dos tablas:
   - `lexa_sidof_publications`
   - `lexa_sidof_publication_reviews`
2. **Modelos:**
   - `app/Models/LexaSidofPublication.php` (`$guarded = ['*']`)
   - `app/Models/LexaSidofPublicationReview.php` (`$guarded = ['*']`)
3. **Autorización:**
   - `app/Policies/LexaSidofPublicationPolicy.php` (`viewAny` y `review` exigen `$actor->isActive() && $actor->can('lexa.sources.review')`).
4. **Servicio de seguimiento y revisión:**
   - `app/Services/Lexa/Sources/SidofPublicationTracker.php` (`syncFromReport` y `transitionReviewStatus`).
5. **CLI Artisan:**
   - `app/Console/Commands/CheckSidofPublications.php` ampliado con la bandera explícita `--record`.

---

## 3. Esquema de tablas e idempotencia

### `lexa_sidof_publications`
- **Identidad y unicidad:** `cod_nota` (`unsignedBigInteger`, índice único `lexa_sidof_pub_cod_nota_unique`) garantiza que consultar dos veces la misma fecha o detectar un mismo `cod_nota` en distintas ediciones nunca genere duplicados.
- **Metadatos mínimos:** `cod_diario`, `published_at`, `edition`, `section`, `title` (máx. 500), `official_url` (HTTPS en `allowed_hosts`), `preliminary_type`, `classification`, `matched_instrument_keys` (JSON), `agency_scope`, `agency`. No almacena textos completos de decretos ni datos personales innecesarios.
- **Seguimiento y revisión:** `review_status` (`pending_review` por defecto), `reviewed_by_user_id`, `reviewed_at`, `review_notes`, `first_detected_at`, `last_detected_at`, `detection_count`, `confirmed_reform` (`false`).
- **Índices:** `(published_at, classification)` y `(review_status, published_at)`.

### `lexa_sidof_publication_reviews`
- Registro *append-only* de cada transición humana: `lexa_sidof_publication_id`, `user_id`, `from_status`, `to_status`, `notes`, `timestamps`.

---

## 4. Estados del flujo de revisión

Estados permitidos en `LexaSidofPublication::REVIEW_STATUSES`:
- `pending_review`: estado inicial al detectar una publicación candidata (`possible_match` o `needs_human_review`).
- `relevant`: marcada por un revisor autorizado como relevante para seguimiento/ingesta manual posterior.
- `not_relevant`: descartada por el revisor.
- `requires_legal_analysis`: requiere análisis jurídico adicional (p. ej. revisar transitorios o vigencia en texto completo oficial).

**Reglas invariantes:**
- Al volver a sincronizar una fecha con `--record`, si la publicación ya fue revisada por un humano (`review_status !== 'pending_review'`), se actualiza `last_detected_at` y `detection_count`, pero **nunca** se sobrescribe la decisión humana.
- `confirmed_reform` permanece siempre `false`.

---

## 5. Comando Artisan (`lexa:sources:sidof`)

```powershell
# Sólo lectura (comportamiento por defecto compatible con LEXA-02-H)
php artisan lexa:sources:sidof 2025-11-14 --dry-run --json

# Registro idempotente explícito de candidatos (possible_match y needs_human_review)
php artisan lexa:sources:sidof 2025-11-14 --record --json
```

- Combinar `--dry-run` y `--record` falla explícitamente con código `1`.
- Con `--record`, el reporte incluye `tracking: { created, existing, pending_review, skipped_unmatched, skipped_incomplete }`.

---

## 6. Diseño de interfaz administrativa (pospuesta deliberadamente)

Tras inspeccionar `routes/web.php`, `app/Http/Controllers/` y `resources/js/`, se confirmó que **no existe actualmente un módulo ni layout administrativo reutilizable** en el repositorio.

Para evitar introducir rutas o vistas improvisadas fuera de una arquitectura administrativa consolidada, se pospone la interfaz web y se deja listo el contrato de autorización (`LexaSidofPublicationPolicy` + permiso Spatie `lexa.sources.review`) y el servicio transaccional (`SidofPublicationTracker::transitionReviewStatus`).

Cuando exista el módulo administrativo, bastará exponer:
- `GET /admin/lexa/publicaciones-sidof` (`can:viewAny,App\Models\LexaSidofPublication`) con filtro por `review_status` y `published_at`, mostrando `cod_nota`, título, clasificación, enlaces oficiales DOF y botón hacia revisión.
- `PATCH /admin/lexa/publicaciones-sidof/{publication}` (`can:review,publication`) delegando en `SidofPublicationTracker::transitionReviewStatus`.

---

## 7. Pruebas, validaciones y limitaciones

- **Pruebas (`APP_ENV=testing`, `DB=plataforma_legal_v2_test`, almacenamiento aislado en `$env:TEMP`):**
  - `SidofPublicationReviewTest`: **5 passed**.
  - Suite combinada (`SidofPublicationReviewTest`, `SidofConnectorTest`, `LegalSourceIngestionTest`, `LexaSourceRetrievalTest`, `LexaRagIntegrationTest`): **40 passed (324 assertions)**.
- **Limitaciones:**
  - Las publicaciones `unmatched` (p. ej. avisos administrativos o registros religiosos) se omiten por defecto al registrar candidatos para evitar ruido en la bandeja de revisión.
  - Actualmente no existe una bitácora persistente por fecha consultada (`unmatched` = total menos candidatos), por lo que una fecha con 0 candidatos y N publicaciones `unmatched` no deja rastro en `lexa_sidof_publications`.
  - **Solución mínima recomendada (futura, sin ampliar alcance ahora):** agregar una tabla ligera `lexa_sidof_sync_runs` (`published_at` único, `total_publications`, `possible_match_count`, `needs_human_review_count`, `unmatched_count`, `last_checked_at`, `status`) para evitar puntos ciegos sobre qué fechas ya fueron consultadas.
  - La confirmación de reformas e ingesta de nuevas versiones normativas sigue siendo un proceso humano controlado mediante `LegalSourceIngestion` (`articles-v2`).
