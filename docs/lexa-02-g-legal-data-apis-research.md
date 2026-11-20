# LEXA-02-G — Investigación de APIs y Fuentes Jurídicas Mexicanas

**Fecha:** 20 de noviembre de 2026  
**Repositorio:** `D:\projects\plataforma-legal-V2` (rama `main`, `HEAD` `efc4e5425d384223a44fc8f9d0baaba35e472c02`)  
**Alcance:** Investigación técnica, comprobaciones HTTP de sólo lectura y diseño de integración; sin cambios de código ni de base de datos.

---

## 1. Resumen ejecutivo

La infraestructura actual de LEXA (`LegalSourceIngestion` con `articles-v2`, `LexaSourceRetriever` sobre MySQL `FULLTEXT`, `LexaLegalContext`, `GeminiAiProvider` y las tablas `lexa_legal_sources` / `lexa_legal_chunks`) ya resuelve el almacenamiento versionado, la trazabilidad por `document_hash` / `content_hash`, la separación entre texto normativo no confiable e instrucciones privilegiadas, y las citas históricas inmutables. En la base de desarrollo `plataforma_legal_v2` coexisten actualmente 6 fuentes sin verificar (`3` representaciones `articles-v1` y `3` representaciones `articles-v2` de LFPDPPP, LGMASC y LOCFCRL).

El cuello de botella no es el motor RAG, sino la **alimentación y detección continua de cambios**:
1. **SIDOF / DOF** (`sidof.segob.gob.mx`) ofrece una **API JSON pública, gratuita y sin autenticación** demostrada en vivo (`HTTP 200`), ideal para monitorear publicaciones diarias y decretos de reforma, pero publica *decretos aislados*, no textos consolidados vigentes.
2. **Cámara de Diputados** (`diputados.gob.mx/LeyesBiblio`) no tiene API JSON oficial, pero mantiene el catálogo consolidado más completo de legislación federal en `.doc`/`.pdf` con índice HTML y fichas `ref/*.htm` verificables por SHA-256.
3. **SCJN / Semanario Judicial de la Federación** (`sjf2.scjn.gob.mx`) protege su portal web mediante Imperva/Incapsula WAF (bloquea peticiones automatizadas simples), aunque dispone de mecanismos institucionales de datos abiertos (CSV/JSON) que requieren descarga manual o conector específico fuera del flujo HTTP directo.
4. **Legislación estatal (Nuevo León — `hcnl.gob.mx`)** carece de API pública de legislación; expone un catálogo HTML con enlaces directos a `.doc` y `.pdf` (177 documentos detectados).
5. **Terceros evaluados:** `baja-labs/leyes-federales-mx` (MIT/CC0-1.0, 319 leyes federales con SHA-256 oficial) aporta un acelerador valioso de estructura/gramática; `usecroma.com` y `tlaloc.sh` ofrecen APIs comerciales operativas; `lexapi.mx` devolvió `HTTP 503` en todas las pruebas.

**Recomendación principal:** Adoptar la **Arquitectura B — Infraestructura híbrida controlada (fuentes oficiales como autoridad + aceleradores estructurados verificados por SHA-256)**, utilizando **SIDOF** para detección diaria de reformas, **Cámara de Diputados** (y los patrones de `baja-labs/leyes-federales-mx` como referencia técnica abierta) para textos consolidados verificados por hash, y manteniendo la **aprobación humana obligatoria (`lexa:sources:verify`)** antes de activar cualquier versión en LEXA.

---

## 2. Inventario de fuentes evaluadas

| Categoría | Fuente / Servicio | URL principal | Ámbito | Estado al 2026-11-20 |
| --- | --- | --- | --- | --- |
| Oficial | SIDOF / Diario Oficial de la Federación | `https://sidof.segob.gob.mx/datos_abiertos` | Decretos y publicaciones federales | **Operativa (`HTTP 200`, JSON público)** |
| Oficial | Cámara de Diputados — LeyesBiblio | `https://www.diputados.gob.mx/LeyesBiblio/index.htm` | Leyes federales consolidadas | **Operativa (`HTTP 200`, HTML + `.doc`/`.pdf`)** |
| Oficial | Orden Jurídico Nacional (SEGOB) | `https://www.ordenjuridico.gob.mx/leyes.php` | Índice federal/estatal de contraste | **Operativa (`HTTP 200`, HTML con rezago en reformas)** |
| Oficial | SCJN — Semanario Judicial de la Federación | `https://sjf2.scjn.gob.mx/` | Tesis y jurisprudencia | **Protegida por WAF Incapsula en acceso HTTP directo** |
| Oficial estatal | H. Congreso del Estado de Nuevo León | `https://www.hcnl.gob.mx/trabajo_legislativo/leyes/` | Leyes estatales de Nuevo León | **Operativa (`HTTP 200`, HTML + `.doc`/`.pdf`)** |
| Dataset abierto | `baja-labs/leyes-federales-mx` | `https://github.com/baja-labs/leyes-federales-mx` | 319 leyes federales consolidadas | **Disponible (`HTTP 200`, MIT / CC0-1.0)** |
| Comercial | Croma (`usecroma.com`) | `https://usecroma.com/es/mx` | DOF, Diputados, SCJN, reguladores, SIEM | **Operativa (`HTTP 200`, Freemium/Pago)** |
| Comercial | Tlaloc Web Services (`tlaloc.sh`) | `https://www.tlaloc.sh/es/services/dof.html` | Diario Oficial de la Federación (2002–hoy) | **Operativa (`HTTP 200`, Prepago MXN)** |
| Tercero | LexAPI MX (`lexapi.mx`) | `https://lexapi.mx/` | Legislación federal | **No disponible (`HTTP 503`)** |

---

## 3. APIs y fuentes oficiales confirmadas

### A. SIDOF / Diario Oficial de la Federación (`sidof.segob.gob.mx`)

- **API pública operativa:** Sí, documentada en `/datos_abiertos` con respuestas JSON (`messageCode: 200`) y sin autenticación requerida para consulta de datos abiertos.
- **Endpoints documentados / observados:**
  - `GET /dof/sidof/diarios/porFecha/{DD-MM-AAAA}` (consulta ejemplares Matutina/Vespertina/Extraordinaria del día).
  - `GET /datos_abiertos/getJSON/{id}` y `GET /datos_abiertos/getPDF/{id}` (metadatos y manuales de webservices; ej. `65`, `43`, `61`, `57`).
  - `GET /ejemplares/{anio}`, `GET /buscarNotas/titulo/{criterio}/{pagina}/{tamanio}/{campo}/{orden}`, `GET /indicadores/{idIndicador}/{DD-MM-AAAA}`, `GET /catalogos/cat_tipos_indicadores`.
- **Diferencia crítica:** SIDOF publica **decretos diarios** (reformas parciales, avisos, acuerdos), **no textos consolidados**. Es la fuente óptima para detectar *qué día se publicó una reforma*, pero no reemplaza la consolidación de la ley completa ni acredita por sí sola la entrada en vigor cuando hay *vacatio legis* o declaratorias graduales.

### B. Cámara de Diputados (`www.diputados.gob.mx/LeyesBiblio/`)

- **API pública:** No existe API JSON/REST oficial.
- **Formatos y metadatos:** Índice HTML (`index.htm`, ~636 KB) con clave numérica por ordenamiento, fecha de publicación original, fecha de última reforma DOF, enlace a ficha cronológica (`ref/<clave>.htm`), texto consolidado en Word binario (`.doc`), PDF (`pdf/*.pdf`) y PDF móvil.
- **Automatización permitida:** Lectura periódica respetuosa (p. ej. 1 vez al día mediante `ETag` / `Last-Modified` / SHA-256 sobre `index.htm` y sólo descarga selectiva de `.doc` cuyo hash o fecha haya cambiado).

### C. SCJN — Semanario Judicial de la Federación (`sjf2.scjn.gob.mx`)

- **Acceso técnico observado:** La petición automatizada directa a `https://sjf2.scjn.gob.mx/` devolvió un desafío JavaScript de **Imperva / Incapsula WAF** (`/_Incapsula_Resource`), y `https://www.scjn.gob.mx/datos-abiertos` devolvió `HTTP 404` y `https://datosabiertos.scjn.gob.mx/` no resolvió (`HTTP 000`).
- **Contenido y metadatos oficiales:** Tesis jurisprudenciales y aisladas, precedentes/sentencias, votos y acuerdos identificados por **Registro Digital**, Época (incluida Duodécima Época), Instancia/Órgano emisor, Materia, Tipo de tesis, Rubro, Texto, Precedentes y Fecha de publicación en el SJF.
- **Integración viable:** No depender de scraping directo desde Laravel; utilizar paquetes oficiales de Datos Abiertos descargados por operador (CSV/ZIP) o bien evaluar un conector de pago puntual (como Croma) si se requiere búsqueda en vivo sin evadir WAF.

### D. Legislación estatal (Nuevo León y estrategia multi-estado)

- **Nuevo León (`hcnl.gob.mx/trabajo_legislativo/leyes/`):** No ofrece API pública; publica una página HTML con `177` enlaces directos a archivos `.doc` y `.pdf` oficiales (p. ej. `/trabajo_legislativo/leyes/pdf/...doc`).
- **Estrategia escalable para otras entidades:** Debido a que cada Congreso estatal en México publica en formatos heterogéneos (HTML, `.doc`, `.docx`, `.pdf`) y casi ninguno expone API REST estándar, nuestra arquitectura debe abstraer un contrato `LegalFeedAdapter` por `state_id` con: (1) catálogo de URLs oficiales permitidas por estado en configuración de servidor, (2) detector de cambios por hash/ETag, y (3) ingesta explícita con `jurisdiction_scope = 'state'` y `state_id` validado antes de activar.

---

## 4. Datasets y servicios de terceros

### 1. `baja-labs/leyes-federales-mx` (`https://github.com/baja-labs/leyes-federales-mx`)

- **Tipo:** Repositorio abierto en GitHub + releases con base SQLite FTS5 (`290 MB` descomprimida), `.blocks.jsonl`, Markdown y CSV.
- **Cobertura y actualización:** `319` leyes federales mexicanas (`59,549` artículos: `42,721` sustantivos y `16,828` transitorios; `203,339` partes). Captura de `2026-08-26` con recarga de CPEUM/LIGIE al `2026-11-10`. Es una **fotografía periódica**, no un feed en vivo.
- **Licencia:** Código bajo **MIT**; datos bajo **CC0 1.0 (dominio público)**, citando el Art. 14 fr. VIII de la LFDA. Permite uso comercial.
- **Calidad y procedencia:** Cada ley está anclada con URL oficial y **SHA-256 del `.doc`/`.pdf` de Cámara de Diputados**. Separa notas editoriales (`annotations`) del texto normativo y soporta sufijos complejos (`15-bis`, `195-z-1`, `77-bis-35-a`). Requiere `textutil` de macOS para convertir `.doc` desde cero, aunque el reprocesamiento desde `blocks.jsonl` funciona en cualquier sistema operativo.
- **Riesgos:** Mantenimiento comunitario reciente (creado el `2026-11-10`, 1 estrella); no sustituye el cotejo contra el `.doc` oficial de Diputados.

### 2. Croma (`https://usecroma.com/es/mx`)

- **Tipo:** API REST comercial + servidor MCP.
- **Cobertura:** `18` fuentes oficiales en México (`30` endpoints): DOF por fecha/publicación, Cámara de Diputados (leyes, reglamentos y bitácora de reformas), SCJN (búsqueda y consulta de tesis por registro digital), CJF sentencias, Banxico, CNBV, CNSF, SIEM y fiscalías estatales.
- **Precios públicos:** Plan Free (`$0 USD/mes`, `1,500` créditos), Hobby (`$20 USD/mes`, `20,000` créditos), Standard (`$99 USD/mes`, `100,000` créditos), Pro (`$299 USD/mes`), Scale (`$799 USD/mes`). Consultas en vivo = `10` créditos; consultas a dataset = `1` crédito.
- **Autenticación y riesgos:** API Key en planos pagos (herramientas web gratuitas con límite por hora). Dependencia de un intermediario privado para disponibilidad y vigencia de datasets.

### 3. Tlaloc Web Services (`https://www.tlaloc.sh/es/services/dof.html`)

- **Tipo:** API REST comercial (`https://api.tlaloc.sh/mx/v1/dof/...`) + servidor MCP (`/mx/mcp`).
- **Cobertura:** Exclusivamente **Diario Oficial de la Federación** (`~160,000` publicaciones desde `2002` al presente, ingesta diaria) con búsqueda semántica (BGE-M3) + léxica (BM25) + NER (`~6.6M` entidades).
- **Precios públicos:** Prepago vía SPEI sin suscripción (`1 u = $1 MXN` IVA incluido): búsqueda/entidades `0.05 u`, documento completo `0.10 u`, documento con offsets NER `1.00 u`.
- **Autenticación y límites:** Requiere `Authorization: Bearer tlmx_...`. No incluye leyes consolidadas ni jurisprudencia de SCJN.

### 4. LexAPI MX (`https://lexapi.mx/`)

- **Estado real al 2026-11-20:** Tanto `https://lexapi.mx/` como `https://lexapi.mx/docs` devolvieron **`HTTP 503 Server Error`** (*"The service you requested is not available yet"*). No se pudo verificar documentación, precios ni licencia operativa; se descarta como dependencia viable en su estado actual.

---

## 5. Comparativa técnica

| Criterio | SIDOF (Oficial) | Cámara de Diputados (Oficial) | SCJN / SJF (Oficial) | `baja-labs/leyes-federales-mx` | Croma (`usecroma.com`) | Tlaloc DOF (`tlaloc.sh`) | `lexapi.mx` |
| --- | --- | --- | --- | --- | --- | --- | --- |
| **Oficialidad** | Máxima (SEGOB) | Máxima (Congreso) | Máxima (PJF/SCJN) | Derivada (pin SHA-256 oficial) | Intermediario privado | Intermediario privado | No verificable |
| **Cobertura** | Decretos DOF | Leyes federales consolidadas | Tesis y precedentes | 319 leyes federales | DOF, Diputados, SCJN, reguladores | Sólo DOF (2002–hoy) | Federal (reportada) |
| **Disponibilidad** | Alta (`HTTP 200`) | Alta (`HTTP 200`) | Bloqueo WAF en HTTP directo | Alta (GitHub/SQLite) | Alta (`HTTP 200`) | Alta (`HTTP 200`) | Caída (`HTTP 503`) |
| **Actualización** | Diaria (tiempo real) | Por reforma publicada | Continua en portal | Snapshot manual | Vivo / Dataset diario | Diaria | Desconocida |
| **Precisión artículos** | Decretos (sin consolidar) | Requiere parser `.doc` | N/A (tesis) | Alta (59,549 arts + notas) | Alta (según endpoint) | Subsecciones DOF | No verificable |
| **Jurisprudencia** | No | No | Sí (fuente primaria) | No | Sí (SCJN + CJF) | No | No |
| **Integración** | Muy fácil (JSON REST) | Media (`.doc` → UTF-8) | Difícil (WAF / CSV manual) | Fácil (SQLite / JSONL) | Muy fácil (REST JSON) | Muy fácil (REST JSON) | Bloqueada (`503`) |
| **Licencia** | Datos abiertos públicos | Dominio público (LFDA Art. 14) | Datos abiertos SCJN | MIT / CC0-1.0 | Términos comerciales | Términos comerciales | No verificada |
| **Costos** | \$0 | \$0 | \$0 | \$0 | \$0–\$799 USD/mes | \$0.05–\$1.00 MXN/req | Desconocido |
| **Escalabilidad** | Alta | Alta (con caché local) | Baja sin descarga bulk | Alta (local en MySQL) | Media (depende cuota) | Media (depende saldo) | Nula |
| **Riesgos** | Sólo decretos aislados | Cambios HTML o `.doc` | WAF bloquea bots | Proyecto joven/snapshot | Lock-in y costo por tráfico | Sólo DOF, no consolidado | Indisponibilidad |

---

## 6. Hallazgos de comprobaciones HTTP (2026-11-20)

Todas las comprobaciones se ejecutaron en modo lectura (1 GET por URL/endpoint), sin credenciales privadas ni descarga masiva:

| Servicio | Documentación / URL consultada | Endpoint / Recurso probado | Estado HTTP | Resumen sanitizado de respuesta | Evidencia |
| --- | --- | --- | --- | --- | --- |
| **SIDOF (SEGOB)** | `https://sidof.segob.gob.mx/datos_abiertos` | `GET /dof/sidof/diarios/porFecha/20-11-2026` | `200` | JSON (`263 B`): `messageCode: 200`, `response: "OK"`, `Matutina: [{codDiario: 330965, codSeccion: "UNICA", paginaMax: "350"}]`. | **Demostrado en vivo** |
| **SIDOF (SEGOB)** | `https://sidof.segob.gob.mx/datos_abiertos` | `GET /datos_abiertos/getJSON/65` | `200` | JSON con `ListaDiarios` y `FechasSinPublicacion` (ejemplo oficial de estructura). | **Demostrado en vivo** |
| **GitHub `baja-labs`** | `https://github.com/baja-labs/leyes-federales-mx` | `GET api.github.com/repos/baja-labs/leyes-federales-mx` | `200` | JSON (`6,870 B`): `license.spdx_id: "MIT"`, `pushed_at: "2026-11-10T09:21:59Z"`, `archived: false`. | **Demostrado en vivo** |
| **HCNL (Nuevo León)** | `https://www.hcnl.gob.mx/trabajo_legislativo/leyes/` | `GET /trabajo_legislativo/leyes/` | `200` | HTML (`307,020 B`) con `177` enlaces directos a `.doc` y `.pdf` estatales. | **Demostrado en vivo** |
| **Croma** | `https://usecroma.com/es/mx` | `GET /es/mx` y `GET /es/pricing` | `200` | HTML con inventario de 18 fuentes MX, planes de \$0 a \$799 USD/mes y sistema de créditos. | **Demostrado en vivo** |
| **Tlaloc DOF** | `https://www.tlaloc.sh/es/services/dof.html` | `GET /es/services/dof.html` | `200` | HTML con 6 endpoints bajo `/mx/v1/dof/*`, Bearer auth (`tlmx_...`) y tarifa \$0.05–\$1.00 MXN. | **Demostrado en vivo** |
| **SCJN / SJF** | `https://sjf2.scjn.gob.mx/` | `GET /` | `200` | Shell HTML (`212 B`) con script `/_Incapsula_Resource` (WAF anti-bot). `datos-abiertos` dio `404`. | **Demostrado en vivo** |
| **LexAPI MX** | `https://lexapi.mx/` | `GET /` y `GET /docs` | `503` | HTML (`302 B`): `503 Server Error - The service you requested is not available yet`. | **Demostrado en vivo** |

*Nota:* Las llamadas autenticadas a los endpoints internos de Croma y Tlaloc (`api.tlaloc.sh/mx/v1/dof/...`) se infieren exclusivamente de su documentación pública, al no haberse creado cuentas ni llaves de API en esta fase.

---

## 7. Comparativa de arquitecturas y recomendación

- **Opción A — Infraestructura propia 100 % con fuentes oficiales (SIDOF + Diputados + Congresos estatales):**
  - *Ventajas:* Costo \$0 por consulta, máxima soberanía y trazabilidad oficial directa, sin dependencia comercial.
  - *Desventajas:* Exige mantener conectores de extracción `.doc`/`.pdf` y ampliar nuestro parser para cubrir leyes complejas (`CCF`, `LFT`, `LFPC`, `CPEUM`).
- **Opción B — Infraestructura híbrida (Fuentes oficiales como autoridad + aceleradores estructurados verificados por SHA-256) [RECOMENDADA]:**
  - *Cómo funciona:* Usamos **SIDOF** (API JSON gratuita oficial) para detectar diariamente decretos de reforma; descargamos los `.doc`/`.pdf` oficiales de **Cámara de Diputados** y **HCNL** verificando su SHA-256; y reutilizamos las reglas de gramática/estructura probadas de **`baja-labs/leyes-federales-mx`** (MIT/CC0-1.0) o su salida `blocks.jsonl` **siempre cotejada contra el SHA-256 del `.doc` oficial en nuestro propio servidor**. Opcionalmente, `Tlaloc` o `Croma` quedan como conectores enchufables sólo si se requiere consulta semántica de históricos DOF o tesis SCJN en vivo.
  - *Ventajas:* Elimina la fragilidad de parsear 319 leyes desde cero, mantiene costo marginal cercano a \$0 en MySQL local, garantiza que ninguna respuesta dependa de la disponibilidad o cuota de una API de terceros durante la conversación del usuario, y conserva 100 % el control de citas inmutables y revisión humana.
  - *Desventajas:* Requiere adaptar un importador/normalizador en Laravel o Python offline y mantener la puerta humana de `lexa:sources:verify` para cambios normativos.
- **Opción C — Dependencia de una API jurídica comercial en vivo (Croma / Tlaloc):**
  - *Ventajas:* Time-to-market mínimo para búsquedas en DOF o tesis SCJN.
  - *Desventajas:* Costo recurrente proporcional al tráfico (ej. 10 créditos por consulta en vivo), latencia externa adicional antes de llamar a Gemini, riesgo de *vendor lock-in* y pérdida de control sobre el *snapshot* determinista si la API cambia su formato.

---

## 8. Diseño de integración con Lefenda / LEXA (Stack Laravel + MySQL + Gemini)

Sin modificar las tablas ni los contratos de citas existentes (`LEXA-02-C` / `02-E`), la integración se acopla limpiamente mediante el patrón de **puente de ingesta asíncrona**:

1. **Conectores desacoplados (`App\Services\Lexa\Sources\`):**
   - Interfaz `LegalSourceConnector` con implementaciones: `SidofDofConnector` (JSON diario), `DiputadosLeyesBiblioConnector` (índice + `.doc`/`.pdf` con verificación SHA-256) y `StateCongressConnector` (por `state_id`, iniciando con Nuevo León).
2. **Jobs programados (`SyncSidofDailyJob`, `CheckDiputadosUpdatesJob`):**
   - Ejecución diaria vía Laravel Scheduler fuera del ciclo HTTP de usuario. Almacenan los binarios originales en almacenamiento privado (`storage/app/legal-sources/`) con su SHA-256 y `ETag` antes de cualquier conversión.
3. **Detección de reformas y versionado seguro:**
   - Si el SHA-256 del `.doc` oficial cambia respecto a la última versión en `lexa_legal_sources`, se crea una nueva fila con `version_key = "dof-YYYY-MM-DD.articles-v2"` y **`is_verified = false`** (nunca sustituye automáticamente la versión activa).
   - **Regla de vigencia:** La publicación en SIDOF genera una alerta/candidato pendiente de revisión; nunca se infiere `valid_from` definitivo ni se activa sin revisión humana cuando existan transitorios de *vacatio legis* o declaratorias graduales.
4. **Revisión, historial y citas inmutables:**
   - El revisor inspecciona el diff artículo por artículo y ejecuta `lexa:sources:verify` (que atómicamente activa la nueva versión y enlaza `superseded_by` en la anterior).
   - `LexaConversationService` y `LexaLegalContext` siguen operando exclusivamente contra MySQL local (`lexa_legal_chunks` verificados), por lo que una caída de SIDOF, Diputados o cualquier API externa **nunca degrada la disponibilidad del chat ni las citas históricas**.
5. **Ampliación futura a jurisprudencia (SCJN):**
   - Mediante tabla aditiva o `norm_type = 'jurisprudence'` (ya soportado en el enum de `lexa_legal_sources`), indexando `Registro Digital`, `Época`, `Instancia`, `Rubro` y `Texto` procedentes de descargas oficiales de Datos Abiertos SCJN.
6. **Observabilidad y seguridad:**
   - Registrar en logs estructurados únicamente `connector`, `status`, `latency_ms`, `instrument_key`, `version_key` y `document_hash` (sin exponer credenciales ni payloads de usuarios).

---

## 9. Riesgos legales y técnicos

1. **Falsa vigencia por publicación vs entrada en vigor:** Como demostró la auditoría de `LFPDPPP` (`DOF 14-11-2025`), una reforma publicada puede depender de declaratorias graduales posteriores. Automatizar `is_verified = true` sin filtro humano induciría errores jurídicos graves.
2. **Compilaciones informativas vs DOF oficial:** Cámara de Diputados advierte expresamente que su compilación es informativa y que el órgano oficial es el DOF. Conservar el hash del archivo fuente y el vínculo al decreto DOF mitiga este riesgo.
3. **Bloqueos WAF y fragilidad de HTML estatal:** El WAF Incapsula de `sjf2.scjn.gob.mx` y los cambios de plantilla en congresos estatales impiden depender de scraping en tiempo real.
4. **Disponibilidad de terceros:** La caída `HTTP 503` observada en `lexapi.mx` demuestra que delegar el corpus crítico en proveedores externos sin copia local verificada introduce un punto único de fallo.

---

## 10. Roadmap priorizado

| Etapa | Alcance | Requisitos | Riesgos | Pruebas | Criterio de finalización |
| --- | --- | --- | --- | --- | --- |
| **1. Primera integración oficial (SIDOF)** | Conector de lectura diario a SIDOF JSON para registrar boletines/decretos detectados | `Http` client con timeout/reintentos seguros | Cambios en estructura JSON de SEGOB | `Http::fake` en `plataforma_legal_v2_test` | Job detecta publicaciones del día sin llamadas en tests |
| **2. Sincronización incremental Diputados** | Monitor de `index.htm` y detección de cambios por SHA-256 en `.doc` | Almacenamiento privado externo + tabla/registro de manifiestos | Falsos positivos por encoding HTML | Tests con fixtures HTML/hash sintéticos | Detecta exactamente qué leyes cambiaron |
| **3. Ampliación del parser (`articles-v2+`)** | Soporte de notas `Artículo reformado DOF...` y sufijos `15-A`, `Bis 1`, `Quintus` | Reglas inspiradas en `baja-labs/leyes-federales-mx` + verificación estricta | Regresiones en citas existentes | Suite `LegalSourceIngestionTest` ampliada | `LFT`, `CCF`, `LFPC` y `CPEUM` parsean sin pérdida |
| **4. Flujo de revisión asistida (CLI/Admin)** | Diff artículo por artículo entre versión activa y candidata antes de `verify` | Permisos administrativos estrictos | Aprobación accidental sin cotejo | Tests de autorización y concurrencia | Revisor aprueba/rechaza con nota obligatoria |
| **5. Jurisprudencia SCJN** | Ingesta offline desde paquetes CSV/JSON oficiales de Datos Abiertos SCJN | Mapeo a `Registro Digital`, `Época`, `Órgano` | Desactualización entre descargas | Tests de recuperación y citas `jurisprudence` | Citas de tesis muestran registro digital y época |
| **6. Legislación estatal (Piloto Nuevo León)** | Conector `HcnlConnector` + ingesta con `jurisdiction_scope = 'state'` | Catálogo de URLs permitidas por estado | Formatos `.doc`/`.pdf` heterogéneos | Tests de aislamiento federal vs estatal | Consultas con `state_id` de NL recuperan leyes locales |
| **7. Calidad, monitoreo y alertas** | Métricas de cobertura, frescura de índice y alertas de fallo de conectores | Logs sanitizados sin secretos | Ruido de alertas por días inhábiles | Pruebas de indisponibilidad HTTP 429/503 | Tablero operativo y runbook documentado |

---

## 11. Próxima implementación concreta

Para la siguiente intervención de código (**LEXA-03-A**), se recomienda implementar exclusivamente la **Etapa 1 (Monitor diario de SIDOF)** junto con la **Etapa 2 (Detector incremental de Cámara de Diputados por SHA-256)** como comando Artisan de sólo lectura/descarga aislada (`lexa:sources:check-updates`), sin activar fuentes automáticamente ni ampliar el parser todavía, validado al 100 % con `Http::fake` en `plataforma_legal_v2_test`.

---

## 12. Referencias y enlaces a documentación

- SIDOF — Datos abiertos (SEGOB): `https://sidof.segob.gob.mx/datos_abiertos`
- Cámara de Diputados — Leyes Federales Vigentes: `https://www.diputados.gob.mx/LeyesBiblio/index.htm`
- Orden Jurídico Nacional (SEGOB): `https://www.ordenjuridico.gob.mx/leyes.php`
- Semanario Judicial de la Federación (SCJN): `https://sjf2.scjn.gob.mx/`
- H. Congreso del Estado de Nuevo León — Leyes: `https://www.hcnl.gob.mx/trabajo_legislativo/leyes/`
- Baja Labs — `leyes-federales-mx` (MIT / CC0-1.0): `https://github.com/baja-labs/leyes-federales-mx`
- Croma — México API & Precios: `https://usecroma.com/es/mx` y `https://usecroma.com/es/pricing`
- Tlaloc Web Services — API del DOF: `https://www.tlaloc.sh/es/services/dof.html`
- LexAPI MX (en estado HTTP 503 al 2026-11-20): `https://lexapi.mx/`
