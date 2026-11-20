# LEXA-01-A — Auditoría de arquitectura existente y propuesta de evolución

- **Fecha:** 2026-11-20
- **Repositorio:** `D:\projects\plataforma-legal-V2` (`main` en `dbde3ae36b3daa9dadb046dd090427beb01e3d5d`)
- **Alcance:** Auditoría de solo lectura de la implementación actual de LEXA y diseño incremental (`LEXA-01` a `LEXA-05`) sobre Laravel 12, MySQL y Vite.

---

## 1. Inventario de la implementación actual

### Rutas, middleware y seguridad
- **`routes/web.php:150`**: `Route::view('/lexa', 'pages.lexa.index')->name('lexa');` dentro del grupo `Route::middleware('auth')` y con middleware global `EnsureActiveAccount`.
- **Autorización y límites**: exige sesión autenticada y cuenta `active`; **no existen** permisos Spatie específicos, políticas de conversación, rate limiters `throttle:lexa.*` ni endpoints `POST`/`DELETE` de API para LEXA.
- **Configuración y dependencias**: `config/services.php` no tiene claves de proveedores de IA; `composer.json` no incluye SDKs externos de IA (Laravel 12 ya provee `Illuminate\Support\Facades\Http` / Guzzle).

### Vistas, datos simulados y JavaScript
- **`resources/views/pages/lexa/demo.php`**: define únicamente `modes` (`orientation`, `contract`, `organize`), `suggestions`, `example_responses` (3 textos estáticos) y `sessions => []` (vaciado en `09F`).
- **`resources/views/pages/lexa/index.blade.php`**: inyecta el JSON de `demo.php` en `<script type="application/json" data-lexa-data>` y define `<template>`s DOM (`session`, `message`, `file`, `remove`, `preview-link`).
- **Componentes Blade**:
  - `resources/views/components/lexa/sidebar.blade.php`: búsqueda local, historial vacío y aviso de privacidad.
  - `resources/views/components/lexa/composer.blade.php`: `textarea[maxlength=5000]`, selector de modo, input `[data-attach]` (`.jpg,.jpeg,.png,.webp,.pdf,.docx`, hasta 4 archivos / 80 MB en UI), botón de voz deshabilitado y botón enviar.
  - `resources/views/components/lexa/context.blade.php`: panel lateral derecho de archivos adjuntos de la sesión.
  - `resources/views/components/landing/lexa-hero.blade.php`: vista previa estática en landing pública.
  - `resources/views/pages/home/home.blade.php` + `resources/js/home.js`: handoff desde `/inicio` hacia `/lexa` mediante `sessionStorage` (`writeHandoff('lexa', { text, mode, context })`).
- **JavaScript (`resources/js/lexa.js` + `resources/js/lexa/state.js`)**:
  - Ciclo de vida `onPage('[data-lexa]', ...)` compatible con Turbo.
  - Estado 100 % efímero en memoria del navegador (`sessions`, `welcomeDraft`); al enviar, `appendExchange()` inserta la respuesta fija `data.example_responses[mode]`.
  - Los adjuntos usan `createAttachment()` (`URL.createObjectURL`) solo para vista previa local, sin subida al servidor ni extracción de texto.
  - Cubierto por `tests/frontend/lexa-state.test.mjs`.

### Base de datos y componentes reutilizables disponibles
- **No existen** migraciones, modelos ni tablas para LEXA.
- **Infraestructura reutilizable existente**:
  - `App\Models\Attachment` + `AttachmentService` + `AttachmentValidator` (almacenamiento privado fuera de `public/`, validación MIME/magic-bytes y marcadores de retención).
  - Catálogos y contenido interno: `ContentCategory`, `LegalSpecialty`, `Tag`, `EditorialEntry` (`Article` / `LegalResource`), `ProfessionalProfile`, `State`, `Municipality`.
  - Patrones de concurrencia y seguridad: `EnsureActiveAccount`, `Cache-Control: private, no-store`, `RateLimiter`, `DB::transaction` + `lockForUpdate` / `sharedLock` y versiones optimistas.

---

## 2. Arquitectura propuesta y flujo de solicitudes

Se propone un núcleo desacoplado en `App\Services\Lexa\`:

1. **Adaptador de proveedor (`Contracts\AiProvider`)**:
   - Interfaz única `generate(LexaPrompt $prompt): LexaCompletion` (y futura `stream(...)`).
   - Implementación inicial sobre `Http::timeout(25)->connectTimeout(5)` sin reintentos automáticos en escrituras, con DTO de salida (`content`, `provider`, `model`, `promptTokens`, `completionTokens`, `finishReason`, `latencyMs`).
2. **Orquestador (`LexaConversationService`)**:
   - Recibe petición autenticada + CSRF + `throttle:lexa.write`.
   - Adquiere `DB::transaction` + `lockForUpdate` sobre `lexa_conversations` (propiedad `user_id === $actor->id` y usuario `active`) para validar presupuesto/estado antes de llamar al proveedor.
   - Construye ventana de contexto acotada (system prompt jurídico mexicano + últimos `N=10` mensajes o `summary` acumulado + fragmentos recuperados si aplica), **libera la transacción DB antes de la llamada HTTP externa** (evitando retener locks MySQL durante 5–25 s de red) y guarda la respuesta en una segunda transacción breve.
3. **Preservación de UX actual**:
   - `/inicio` sigue enviando por `handoff.js` hacia `/lexa`.
   - `lexa.js` reemplaza la inserción de `example_responses` por `fetch` `POST` con `X-CSRF-TOKEN`, manejo de errores (`401`/`419`/`429`/`503`) y estado de carga sin romper Turbo ni BFCache.

---

## 3. Qué reutilizar y qué construir

| Reutilizar del proyecto | Construir nuevo |
| --- | --- |
| `auth` + `EnsureActiveAccount` + CSRF + `RateLimiter` | `config/lexa.php` + bloque en `config/services.php` |
| `Attachment`, `AttachmentService`, `AttachmentValidator` (en `LEXA-04`) | Tablas `lexa_conversations`, `lexa_messages` (y luego `lexa_legal_sources`, `lexa_legal_chunks`) |
| `EditorialEntry`, `ProfessionalProfile`, `State`, `LegalSpecialty` (en `LEXA-03`) | `LexaController`, `LexaConversationPolicy`, `LexaPromptRequest` |
| `lexa.blade.php`, `composer`, `sidebar`, `context`, `lexa.css`, `handoff.js` | `Contracts\AiProvider`, `LexaConversationService`, `LexaContextBuilder` |
| `resources/js/messages/attachments.js` (validación local) | Adaptador HTTP del proveedor + manejo de `429`/`503`/timeouts en `lexa.js` |

---

## 4. Opciones de modelos y proveedores

| Opción | Ventajas | Desventajas y riesgos | Costo inicial / Requisitos |
| --- | --- | --- | --- |
| **API externa (ej. Gemini Flash / OpenAI Mini / Anthropic Haiku)** | Sin infraestructura GPU; buena comprensión en español y salida estructurada; multimodal nativo en etapas posteriores. | Costo variable por token; dependencia de red/latencia externa; requiere política estricta de no enviar PII innecesaria. | Cercano a cero en desarrollo/piloto con límites diarios; **no asumir cuota gratuita ilimitada** en producción. |
| **Modelo local vía Ollama (ej. `qwen2.5:7b` / `llama3.1:8b`)** | Costo por token = 0; datos nunca salen del servidor. | Exige hardware dedicado (`8–16+ GB` VRAM/RAM rápida); baja concurrencia en CPU; menor precisión en citas jurídicas mexicanas en modelos pequeños. | Alto costo de servidor/GPU si se aloja en la nube; viable solo si ya existe hardware dedicado. |
| **Arquitectura híbrida (recomendada a mediano plazo)** | Interfaz `AiProvider` permite usar API externa en producción para razonamiento/citas y Ollama local en desarrollo o tareas auxiliares (títulos/resúmenes). | Mayor superficie de configuración y pruebas entre dos adaptadores. | Bajo al inicio (empezar con un adaptador HTTP externo + mock/fake determinista en tests). |

**Recomendación inicial**: diseñar tras `Contracts\AiProvider` e iniciar `LEXA-01` con un adaptador HTTP externo económico de nivel *Flash/Mini* con topes duros de tokens y presupuesto, manteniendo un `FakeAiProvider` determinista para `APP_ENV=testing` (cero llamadas pagadas en CI/tests). La selección definitiva del proveedor comercial debe confirmarse antes de `LEXA-01-B` según disponibilidad de clave, región y política de privacidad de datos.

---

## 5. Riesgos y controles de seguridad

- **Privacidad y aislamiento**: cada conversación pertenece estrictamente a `user_id`; respuestas `403`/`404` indistinguibles para IDs ajenos o eliminados; `Cache-Control: private, no-store` en todas las respuestas.
- **Control de costos y abuso**:
  - `RateLimiter` dedicado (`lexa.read` y `lexa.write`, p. ej. 10–12 mensajes/minuto por usuario).
  - Límite duro de entrada (`maxlength="5000"`), ventana de contexto acotada (p. ej. últimos 10 turnos) y `max_output_tokens` configurado en servidor.
  - Cuota diaria/mensual por usuario o global antes de invocar al proveedor.
- **Alucinación jurídica**: disclaimer visible permanente; en `LEXA-02`, grounding obligatorio sobre fuentes oficiales mexicanas con jurisdicción (`federal` o `state_id`) y vigencia explícita; prohibición en prompt de inventar artículos no recuperados.
- **Inyección de prompt y XSS**: separar estrictamente `system` instructions del texto del usuario y del contenido de documentos; renderizar respuestas siempre como texto seguro (`textContent` o Markdown sanitizado sin HTML crudo).
- **Timeouts y concurrencia**: nunca mantener locks MySQL (`FOR UPDATE`) abiertos durante la llamada HTTP al proveedor; timeouts acotados (`<= 25s`) y errores `503` sanitizados sin exponer claves ni trazas.

---

## 6. Plan incremental (LEXA-01 a LEXA-05)

- **LEXA-01 — Núcleo conversacional real (texto + persistencia)**:
  - Migraciones `lexa_conversations` y `lexa_messages`, `LexaConversationPolicy`, `Contracts\AiProvider` + primer adaptador HTTP + `FakeAiProvider` para tests, `LexaController`, rate limiters y conexión real en `lexa.js` (conservando modo `orientation|contract|organize`).
- **LEXA-02 — Fuentes jurídicas oficiales mexicanas y citas verificables**:
  - Tablas `lexa_legal_sources` y `lexa_legal_chunks` (CPEUM, LFT, códigos federales/estatales, DOF/SCJN) con `jurisdiction`, `valid_from`, `valid_to`, `source_url` y búsqueda `FULLTEXT` MySQL + filtros de vigencia; renderizado de citas verificables en la UI.
- **LEXA-03 — Herramientas internas autorizadas**:
  - Consultas de solo lectura a `EditorialEntry` (publicado) y `ProfessionalProfile` (público y usuario activo) según especialidad/ubicación, respetando políticas y permisos existentes.
- **LEXA-04 — Adjuntos y capacidades multimodales**:
  - Persistencia privada vía `Attachment` + `AttachmentService` (`lexa_retained_at`), extracción de texto de PDF/DOCX en servidor y soporte de imágenes en adaptador multimodal, con validación de tamaño/páginas.
- **LEXA-05 — Streaming, resúmenes automáticos y telemetría de costos**:
  - Respuesta progresiva (SSE), compresión automática de historial largo en `summary` y tablero interno de consumo/latencia.

---

## 7. Primera implementación mínima recomendada (`LEXA-01-B`)

- **Objetivo**: sustituir el mock local de texto por conversaciones persistentes privadas y un adaptador `AiProvider` real (con `FakeAiProvider` en testing), dejando archivos adjuntos para `LEXA-04` (mantener aviso explícito en UI si adjuntos aún no se envían al modelo en `LEXA-01`).
- **Archivos previstos**:
  - `database/migrations/*_create_lexa_conversations_and_messages_tables.php`
  - `app/Models/LexaConversation.php`, `app/Models/LexaMessage.php`, `app/Policies/LexaConversationPolicy.php`
  - `config/lexa.php`, `config/services.php`
  - `app/Services/Lexa/Contracts/AiProvider.php`, `app/Services/Lexa/Providers/FakeAiProvider.php`, adaptador HTTP inicial, `app/Services/Lexa/LexaConversationService.php`
  - `app/Http/Controllers/Lexa/LexaController.php`, `app/Http/Requests/Lexa/*`
  - `routes/web.php`, `app/Providers/AppServiceProvider.php` (rate limiters `lexa.read`/`lexa.write`)
  - `resources/views/pages/lexa/index.blade.php`, `resources/js/lexa.js`
  - `tests/Feature/LexaIntegrationTest.php`
- **Criterios de aceptación**:
  1. Invitados redirigidos a `/iniciar-sesion`; cuentas no activas bloqueadas por `EnsureActiveAccount`.
  2. Conversaciones privadas aisladas por `user_id` (acceso ajeno o eliminado indistinguible con 404/403).
  3. Persistencia real de historial y recarga tras navegar/refrescar, manteniendo `handoff` desde `/inicio`.
  4. Rate limit, CSRF, límites de longitud/tokens, timeouts y errores `503` sanitizados verificados con `FakeAiProvider` en `plataforma_legal_v2_test`.
