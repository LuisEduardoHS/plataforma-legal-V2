# DEV-01 — Aislamiento de Testing entre Antigravity y Codex

## 1. Diagnóstico de interferencia

Anteriormente, tanto Antigravity como Codex ejecutaban PHPUnit sobre la misma base MySQL (`plataforma_legal_v2_test`), debido a que:
- `tests/TestCase.php` fijaba `plataforma_legal_v2_test` de forma rígida.
- `.env.testing` apuntaba a `DB_DATABASE=plataforma_legal_v2_test` (además de `SESSION_DRIVER=database`, `QUEUE_CONNECTION=database`, `CACHE_STORE=database` fuera de PHPUnit).
- El trait `RefreshDatabase` ejecuta `migrate:fresh` al iniciar la suite, eliminando y recreando todas las tablas (`users`, `attachments`, etc.) mientras otro agente podía estar ejecutando pruebas en paralelo.

---

## 2. Bases por agente y entorno

| Agente / Entorno | Base de datos MySQL | Uso |
|---|---|---|
| **Desarrollo** | `plataforma_legal_v2` | Base local de desarrollo (intacta; nunca ejecutar tests aquí) |
| **Antigravity (testing)** | `plataforma_legal_v2_antigravity_test` | Base exclusiva de pruebas de Antigravity |
| **Codex (testing)** | `plataforma_legal_v2_codex_test` | Base exclusiva de pruebas de Codex |
| **Legacy compartida** | `plataforma_legal_v2_test` | Preservada sin cambios hasta confirmar que ningún proceso la usa |

---

## 3. Guardia estricta en `tests/TestCase.php` y variables de entorno

`tests/TestCase.php` exige antes de crear cualquier fixture:
1. `APP_ENV=testing` y driver `mysql`.
2. `EXPECTED_TEST_DB` (o `DB_DATABASE` como respaldo) no vacío, distinto de `plataforma_legal_v2` y terminado en `_test`.
3. Coincidencia exacta entre `EXPECTED_TEST_DB`, `DB::connection()->getDatabaseName()` y `SELECT DATABASE()`.
4. `CACHE_STORE=array`, `SESSION_DRIVER=array` y `QUEUE_CONNECTION=sync`.
5. Si se define `LARAVEL_STORAGE_PATH`, redirige `storage_path()` mediante `$this->app->useStoragePath(...)`.

No se realizan llamadas reales a Gemini ni a SIDOF en pruebas automatizadas (`Http::fake()` y `Http::preventStrayRequests()`).

---

## 4. Configuración de storage aislado

Cuando se defina `LARAVEL_STORAGE_PATH` fuera del repositorio (en `$env:TEMP`), deben existir sus subdirectorios requeridos por Laravel/Blade antes de arrancar Artisan o PHPUnit (`framework\views`, `framework\cache\data`, `framework\sessions`, `framework\testing`, `logs`, `app\public`).

---

## 5. Comandos seguros

### Antigravity (`D:\projects\plataforma-legal-V2`)
El `.env.testing` local (ignorado por Git) apunta a `plataforma_legal_v2_antigravity_test` con `EXPECTED_TEST_DB=plataforma_legal_v2_antigravity_test`, `CACHE_STORE=array`, `SESSION_DRIVER=array`, `QUEUE_CONNECTION=sync`:

```powershell
$env:APP_ENV="testing"
$env:DB_DATABASE="plataforma_legal_v2_antigravity_test"
$env:EXPECTED_TEST_DB="plataforma_legal_v2_antigravity_test"
$env:CACHE_STORE="array"
$env:SESSION_DRIVER="array"
$env:QUEUE_CONNECTION="sync"
$sp = Join-Path $env:TEMP "plataforma_legal_v2_antigravity_storage"
$env:LARAVEL_STORAGE_PATH=$sp
foreach ($sub in @("framework\views","framework\cache\data","framework\sessions","framework\testing","logs","app\public")) {
    New-Item -ItemType Directory -Force -Path (Join-Path $sp $sub) | Out-Null
}
php artisan test tests/Feature/SidofPublicationReviewTest.php tests/Feature/SidofConnectorTest.php
```

### Instrucciones para Codex (en su propio worktree)
1. En el `.env.testing` local de su worktree (sin modificar `.env` de desarrollo ni el worktree de Antigravity), configurar:
   - `DB_DATABASE=plataforma_legal_v2_codex_test`
   - `EXPECTED_TEST_DB=plataforma_legal_v2_codex_test`
   - `CACHE_STORE=array`
   - `SESSION_DRIVER=array`
   - `QUEUE_CONNECTION=sync`
2. Definir `LARAVEL_STORAGE_PATH` en una carpeta temporal independiente (p. ej. `$env:TEMP\plataforma_legal_v2_codex_storage` con sus subdirectorios `framework\*`, `logs`, `app\public`).
3. Ejecutar `php artisan migrate --force` (con `APP_ENV=testing` y `DB_DATABASE=plataforma_legal_v2_codex_test`) o dejar que `RefreshDatabase` inicialice `plataforma_legal_v2_codex_test`.

---

## 6. Revisión del respaldo de LEXA-02-I

El archivo `lexa-02-i-dev-backup.json` (`17,393` bytes) generado antes de la migración `000006` contiene:
- Los 6 registros completos de `lexa_legal_sources` y sus metadatos.
- Los registros de `migrations`.
- **Limitación detectada:** para `lexa_legal_chunks` únicamente almacenó el conteo (`lexa_legal_chunks_count: 569`) y **no** las 569 filas de texto/hashes. Por ello, es **incompleto** para restaurar `lexa_legal_chunks`.

**Recomendación para futuras migraciones:** generar un respaldo SQL completo recuperable antes de cualquier migración en desarrollo:

```powershell
mysqldump --single-transaction --routines --triggers -h 127.0.0.1 -u root -p plataforma_legal_v2 lexa_legal_sources lexa_legal_chunks lexa_sidof_publications lexa_sidof_publication_reviews migrations > lexa-backup.sql
```

---

## 7. Resultado de validaciones

- Bases creadas: `plataforma_legal_v2_antigravity_test` (`58` tablas migradas) y `plataforma_legal_v2_codex_test` (`0` tablas, lista para Codex).
- Pruebas ejecutadas en `plataforma_legal_v2_antigravity_test`: `SidofPublicationReviewTest` y `SidofConnectorTest` (**15 passed, 72 assertions**).
- Bases preservadas sin cambios: `plataforma_legal_v2` (`sources=6`, `verified=0`, `chunks=569`) y `plataforma_legal_v2_test` (`58` tablas).
