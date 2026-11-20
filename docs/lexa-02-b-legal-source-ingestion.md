# LEXA-02-B — Ingesta controlada de legislación federal

20 de noviembre de 2026. Base: main `1bb0d4b7651b3d5046eec8f5cb86088855a3d9c5`, inicialmente limpio.

## Fuentes oficiales verificadas por consulta web

- [Cámara de Diputados — Biblioteca de leyes federales](https://www.diputados.gob.mx/LeyesBiblio/index.htm): índice HTML con publicación original, última reforma y enlaces a textos consolidados y páginas de reformas. El portal ofrece documentos descargables; se recomienda partir del texto consolidado y cotejar sus reformas en DOF. Un enlace que permanece igual puede cambiar de contenido.
- [DOF/SIDOF — datos abiertos](https://sidof.segob.gob.mx/datos_abiertos): consulta de diarios, documentos y notas, con servicios documentados en PDF y respuestas JSON. Permite localizar publicaciones y contrastar decretos. Una publicación aislada no equivale a una versión consolidada ni acredita por sí sola vigencia actual.
- [Orden Jurídico Nacional — leyes y reglamentos federales](https://www.ordenjuridico.gob.mx/leyes.php): índice HTML con publicación y reforma, útil para contraste. Se observaron fechas de reforma distintas entre índices para algunos ordenamientos: no se elige automáticamente una fecha ni se asume sincronización.

No se descargaron documentos jurídicos ni se importaron normas reales durante esta intervención. Las URL usadas por fixtures corresponden a páginas oficiales existentes pero sus textos son deliberadamente sintéticos; no son evidencia del contenido de esas páginas.

## Arquitectura y límites de confianza

Se reutilizan LexaLegalSource, LexaLegalChunk y LexaSourceRetriever. Una migración aditiva añade identidad y versión únicas, hash normalizado del documento, procedencia JSON, revisor, momento/motivo de verificación y enlace a versión sustituta; los chunks incorporan posición y parte. Las columnas son anulables para no reinterpretar ni destruir datos preexistentes. La reversión se niega si existen importaciones con procedencia.

LegalSourceIngestion separa dos operaciones:

1. Importar: valida manifiesto federal y texto local UTF-8 (máximo 2 MB), identifica editor por host HTTPS exacto permitido y fragmenta artículos. Siempre crea una versión no verificada. Conserva hash del archivo de entrada, hash del texto con saltos normalizados, fecha de importación, fecha de consulta, nota de extracción y versión de parser.
2. Verificar: un operador de consola confiable coteja previamente la fuente oficial, contenido, referencias y aplicabilidad. Debe suministrar ID, SHA-256 esperado, identificación de revisor y nota de al menos 20 caracteres. La operación comprueba integridad de contenido, reconstrucción del documento, referencias/posiciones, jurisdicción y vigencia explícita. No se admite fecha inicial desconocida, futura o final vencida. La URL oficial por sí sola nunca activa una fuente.

No se hacen peticiones HTTP desde el importador. Los hosts permitidos restringen procedencia declarada; no comprueban existencia ni equivalencia del documento. Esa comprobación corresponde al cotejo humano. El importador no convierte PDF/Word/HTML, no hace OCR, no evalúa scripts y no utiliza LLM. Las instrucciones que aparezcan en un texto importado siguen siendo texto inerte. No se conectó el recuperador con Gemini ni se modificó su interfaz.

## Manifiesto y uso de consola

Preparar fuera del repositorio un JSON UTF-8 con estas claves:

| Campo | Contrato |
| --- | --- |
| instrument_key | Identidad estable interna, letras minúsculas, números y guiones; máximo 100 |
| version_key | Versión explícita de la edición; máximo 100, letras/números/punto/guion/guion bajo |
| instrument, short_name | Título cotejado y abreviatura, máximo 180 y 40 |
| norm_type | constitution, federal_law o regulation |
| jurisdiction_scope | federal; no admite state_id |
| source_url | URL HTTPS exacta del documento cotejado, máximo 500 |
| published_at | Publicación original, YYYY-MM-DD, obligatoria |
| last_reformed_at | Última reforma de esta edición; clave obligatoria, null sólo si se ha comprobado que no hay reforma |
| consulted_at | Fecha real de consulta, obligatoria y no futura |
| valid_from, valid_to | Intervalo de aplicabilidad revisado, claves obligatorias, anulables al importar |
| extraction_note | Cómo se obtuvo/cotejó el texto y limitaciones, máximo 2000 |

No incluir is_verified. El nombre del editor se deriva del host; no se acepta una identidad arbitraria suministrada por el archivo. Hosts habilitados: www.diputados.gob.mx, dof.gob.mx, www.dof.gob.mx, sidof.segob.gob.mx y www.ordenjuridico.gob.mx. No se aceptan credenciales, puertos ni fragmentos en la URL. No se siguen redirecciones ni se accede a rutas remotas desde consola.

Comandos (no ejecutados sobre desarrollo):

```console
php artisan lexa:sources:import RUTA_MANIFIESTO_JSON RUTA_TEXTO_UTF8 --apply
php artisan lexa:sources:verify ID --hash=SHA256_NORMALIZADO --reviewer="IDENTIFICACION_REVISOR" --note="EVIDENCIA_DEL_COTEJO_Y_APLICABILIDAD" --apply
```

Sin --apply no hay escritura. El comando import acepta sólo archivos locales legibles y acotados; el manifiesto tiene límite de 32 KB. El texto debe empezar con un encabezado «Artículo N.-», «Artículo N.» o equivalente admitido. Se reconocen números y sufijos o/º/° y Bis/Ter/Quáter. Las referencias repetidas se rechazan. El contenido completo se conserva sin truncarlo; cada artículo puede dividirse en partes de hasta 12,000 bytes sin cortar UTF-8. La cita conserva artículo, parte, versión y hash de documento; no se inventa una página o sección.

No se soportan aún preámbulos, transitorios, referencias ordinales ni todas las variantes tipográficas del universo legal. Los transitorios detectados se rechazan para no atribuirlos al artículo anterior. Se requiere preparar un texto acotado de artículos, documentando claramente su alcance en extraction_note, y verificarlo manualmente. No afirmar que un extracto es una norma completa.

## Versiones, consistencia y recuperación

La clave única instrument_key/version_key impide duplicar una versión, incluso ante reintentos concurrentes. La misma versión con contenido o metadatos diferentes se rechaza. Cambiar el texto exige otra versión; no se actualizan chunks históricos. No se deduplican instrumentos distintos sólo por tener textos coincidentes.

Importar una nueva versión no retira la actual. La verificación bloquea las versiones de la identidad en orden estable, activa la nueva y retira la elegibilidad de las anteriores dentro de la misma transacción. Se conserva su contenido, revisor, fechas y vínculo superseded_by; nunca se eliminan versiones. Una versión sustituida no puede reactivarse con el comando. Se impide desplazar una versión por otra con fecha de reforma o de aplicabilidad anterior. Los errores revierten toda la operación, sin fragmentos parciales.

El recuperador mantiene sus filtros federal/estado y fechas. Además excluye versiones sustituidas y exige verified_at y valid_from para las importaciones nuevas. Las fuentes legacy sin instrument_key conservan el contrato anterior de is_verified: requieren un trabajo posterior de revisión/backfill, no se certifican retrospectivamente con esta migración. Un estado/reglamento local no puede introducirse mediante este flujo federal.

La fecha de publicación no se confunde con reforma, consulta, importación o vigencia. El intervalo es una declaración humana de aplicabilidad del texto importado, no una conclusión jurídica automática. Vacatio legis, reformas escalonadas, derogación parcial y variantes territoriales requieren revisión específica y no se infieren del host o del reloj.

## Seguridad operacional

La consola es una frontera de confianza: no hay rutas HTTP nuevas ni permisos concedidos automáticamente. verified_by identifica al revisor declarado, no autentica una identidad humana; el acceso al sistema operativo debe restringirse. Las escrituras por SQL directo pueden evadir estas garantías y mutar hashes, metadatos o elegibilidad; no se presenta esto como un registro de auditoría inviolable.

La aplicación de la migración en desarrollo y la importación real se dejan pendientes. Antes de usarlas: comprobar base efectiva, respaldo, revisión del documento y del esquema. No ejecutar migraciones generales, seeders ni importaciones masivas como parte de este cierre.

## Validación

La migración 2026_11_20_000004 se aplicó exclusivamente en plataforma_legal_v2_test mediante ruta exacta después de verificar dentro del proceso APP_ENV=testing, MySQL efectivo, caché/sesiones array y almacenamiento privado externo. Las pruebas de ingesta confirman filas sintéticas para comprobar FULLTEXT de forma no vacía y las eliminan por identidad aleatoria exclusiva al terminar; no usan normas reales ni datos de desarrollo.

Pruebas focalizadas: importación, reintento, versión alterada, actualización preservando historia, fragmentos Unicode, referencias falsas/duplicadas/transitorios, metadatos ausentes, URL no oficial, jurisdicción incorrecta, documento no verificado, fechas desconocidas/futuras, hash incorrecto y comandos sin --apply. Se incluyen las regresiones existentes de recuperador y persistencia/generación LEXA con proveedor simulado.

Resultado: 28 pruebas, 200 assertions; Pint y sintaxis de los siete PHP afectados aprobados. Whitespace de archivos versionados y nuevos aprobado. La migración permanece pendiente en desarrollo. Sin suite global, llamadas a Gemini, staging, commit ni push.

## Inventario exacto

Modificados:
- app/Models/LexaLegalSource.php
- app/Services/Lexa/LexaSourceRetriever.php

Nuevos:
- app/Services/Lexa/LegalSourceIngestion.php
- app/Console/Commands/ImportLegalSource.php
- app/Console/Commands/VerifyLegalSource.php
- database/migrations/2026_11_20_000004_add_legal_source_ingestion_metadata.php
- tests/Feature/LegalSourceIngestionTest.php
- docs/lexa-02-b-legal-source-ingestion.md


## Corrección previa al cierre — encabezados no reconocidos

La revisión en main `98b1c105f2d040442ff9b49ee4b6a21c746cb2df` reprodujo que «Artículo 2 A.-» quedaba dentro del artículo 1. El parser sólo buscaba los marcadores admitidos y su comprobación posterior descartaba encabezados no numéricos, pero no variantes numéricas no soportadas. Recalcular los mismos fragmentos en verificación repetía el defecto, incluso con hashes correctos.

Ahora se comprueban los inicios de línea antes de calcular fragmentos. Una línea que comienza con la etiqueta de artículo y una referencia potencial pero no coincide con el formato admitido rechaza el documento completo con un mensaje fijo, sin interpolar texto importado. Importación y verificación usan exactamente fragment(). No se añadieron formatos con letras: A, Bis A, Quinquies, referencias compuestas y ordinales no admitidos se rechazan. Se conservan Bis, Ter, Quáter, dígitos y sufijos ordinales admitidos; se aceptan sangrías horizontales. Se sustituyó el espacio general por espacio/tabulación para que una referencia no tome su separador de la siguiente línea.

Las menciones dentro del párrafo —por ejemplo «Se menciona el Artículo 2 A.- dentro del párrafo» o «Conforme al artículo 3 Bis»— permanecen como texto, sin cortes ni referencias inventadas. Un inicio de línea que parezca un encabezado pero sea ambiguo se remite a revisión manual; este parser no reconstruye párrafos partidos por extracción PDF ni pretende cubrir todos los estilos legislativos.

Regresiones nuevas: doce variantes no soportadas/malformadas, rechazo sin versiones o fragmentos parciales, conservación del texto/estado de la versión previa verificada, Bis/Ter/Quáter y sangría, menciones ordinarias, y una versión mal fragmentada por el parser anterior con todos sus hashes coherentes. Esta última no se puede aprobar ni sustituye la versión activa.

Cierre: 14 pruebas focalizadas de ingesta y recuperación, 119 assertions aprobadas. Se conservaron las expectativas anteriores; se añadieron tres métodos de regresión. Inventario sin ampliación: ocho archivos, dos modificados y seis nuevos. No se ejecutaron migraciones ni importaciones en desarrollo.
