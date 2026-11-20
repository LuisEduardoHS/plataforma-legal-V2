# Integración aislada de dev/frontend

Fecha: 20 de noviembre de 2026.

## Base y autorización

- Main y origin/main: `fef515ec9d98564f66aa40355ceeb7bd07072fc1`.
- Origen integrado: `origin/dev/frontend`, `c53c51aa4a3b4fb508ffb3e21073e1716d47e0d5`.
- Ancestro común: `00d45497723fb00a156ba2e939b020fe8e15fe2f`.
- 18 commits exclusivos de main y dos del origen: `bc999e5` (branding) y `c53c51a` (perfiles/directorio).
- Trabajo realizado en `D:\projects\plataforma-legal-V2-frontend-integracion`, rama `integracion/frontend-dev`.
- Excepción autorizada expresamente por el usuario: main conserva intactas sus dos modificaciones pendientes en `LexaConversationTest.php` y `LexaGenerationFlowTest.php`. Se copiaron las mismas regresiones al worktree de integración. No se limpió ni se cambió el checkout principal.
- Merge con dos padres para conservar el historial. Sin merge ni push a main; sin modificar la rama original dev/frontend ni los worktrees de diseño/integración excluidos.

## Comparación y conflictos

Desde la divergencia, main cambia 114 rutas y frontend 23: 107 exclusivas de main, 16 exclusivas del origen y siete solapadas. «Exclusiva» aquí significa que sólo una rama cambió esa ruta desde el ancestro; no implica que sea un archivo nuevo.

Los siete solapamientos:

- `resources/css/app.css`.
- `resources/css/components/publicHeader.css`.
- `resources/views/components/public/header.blade.php`.
- `resources/views/layouts/app.blade.php`.
- `resources/views/layouts/partials/header.blade.php`.
- `resources/views/layouts/public.blade.php`.
- `resources/views/pages/professionals/index.blade.php`.

Sólo hubo dos conflictos textuales: `publicHeader.css` y `components/public/header.blade.php`. Se combinaron el logo, las dimensiones y los espaciados del origen con los estados de landing, modo oscuro, enlaces funcionales y contexto de retorno de main. Los otros cinco solapamientos fueron revisados después del merge automático. No se usó una selección global de ours/theirs.

Cambios exclusivos de frontend desde el ancestro:

- `app/Http/Requests/Profiles/DirectoryRequest.php`.
- `app/Services/Profiles/ProfessionalDirectory.php`.
- `package-lock.json`.
- `public/img/authbanner.png`.
- `public/img/loginbanner.png`.
- `resources/css/views/login.css`.
- `resources/img/loginbanner.png`.
- `resources/js/professionals.js`.
- `resources/svg/lefenda-logo.svg`.
- `resources/views/components/profiles/card.blade.php`.
- `resources/views/layouts/guest.blade.php`.
- `resources/views/layouts/operations.blade.php`.
- `resources/views/layouts/verification.blade.php`.
- `resources/views/pages/auth/login.blade.php`.
- `resources/views/pages/auth/register.blade.php`.
- `resources/views/pages/profile/show.blade.php`.

Los exclusivos de main incluyen persistencia LEXA, flujo de generación y proveedor Gemini, esquema/retriever de fuentes legales, recuperación de navegación y Mensajes, carga JS dinámica, CSS base sin duplicar y SVG compartidos de Comunidad. Se conservan sus implementaciones.

## Diseño incorporado y decisiones

- Identidad visual Lefenda: SVG usado como máscara CSS en cabeceras pública y autenticada, títulos por defecto de layouts y banners de autenticación. No se añadieron pantallas ni componentes Blade nuevos en el origen; se rediseñaron componentes existentes.
- Login y registro: banner lateral, adaptación a alturas pequeñas y feedback de envío con Alpine, manteniendo POST, CSRF, validación, recuperación de contraseña y contexto de retorno. Se corrigieron un href repetido del origen y el contraste insuficiente de «Volver».
- Perfil: portada, tarjeta de privacidad y distribución de información profesional/actividad. Se preservan el recorrido profesional, revisión por capacidades, acceso operativo autorizado, enlaces públicos y edición. Se repuso un único acceso a las publicaciones propias dentro del diseño nuevo. La tarjeta pública recibe el estado real disponible: un perfil personal privado no aparece etiquetado como público.
- Directorio: filtro plegable, categorías mediante selección múltiple, chips y layout nuevo. Se retiró x-cloak para que los filtros y el botón de envío sean utilizables sin JS. Se mantuvo un H1 accesible y la apertura inicial con filtros aplicados.
- El origen también cambia backend: `specialty_ids` acepta como máximo 20 IDs activos y distintos; la consulta aplica OR entre esas especialidades. `specialty_id` anterior sigue funcionando. Si llegan ambos parámetros, se aplican ambos filtros (intersección); no se alteraron reglas de privacidad ni autorización.
- JS del directorio usa el ciclo onPage/listen existente y conserva la gestión del submitter legado. No se añaden listeners globales fuera de ese ciclo.
- Se preservó íntegra la optimización de userHeader. Además, login.css referencia app.css mediante @reference: mantiene sus reglas locales sin volver a emitir la base de Tailwind cargada por el layout guest.
- package-lock conserva las actualizaciones que traía el origen: Vite 6.4.6 y follow-redirects 1.16.3. npm ci y build validados; sin cambio de package.json ni dependencias PHP.
- Se conservaron los tres PNG aportados y el SVG. authbanner.png y la copia en resources/img no están usados actualmente por estas vistas; se mantienen como material visual del equipo, sin cargarlos artificialmente.

## Errores encontrados al verificar

La primera suite completa produjo siete fallos. Los mismos siete casos también fallan al ejecutarlos sobre main, con su aislamiento de testing: no se atribuyen al frontend.

- Expectativas anteriores sobre el texto del vacío de Inicio y el h4 del autor: se adaptaron al texto vigente y al h3 del componente real, conservando las comprobaciones de contenido público, escape y ausencia de datos privados/demo.
- Expectativas de «Demostración local» y hero estático previas a LEXA persistente: se actualizaron al historial privado y al compositor de traspaso vigente. No se revirtió LEXA al demo.
- Dos casos revelaron que el layout editorial había dejado de emitir library-title. Se restauró el H1 condicional: el historial vuelve a identificar su propósito, sin quitar la prueba ni alterar sus seis revisiones esperadas.
- Inspección del compositor público: se eliminó name=question del textarea para que un envío nativo GET no serialice el borrador en la URL. El JS sigue leyendo el mismo data-attribute y conserva el traspaso de main. Se añadió el texto asociado a aria-describedby.

Regresiones permanentes:

- Render real público/autenticado: IDs únicos, sin formularios anidados, un CSRF por POST y sin href duplicados; filtros nativos y un único enlace a publicaciones propias.
- Un perfil profesional público con perfil personal privado no revela su nombre privado ni usa una etiqueta de perfil personal público.
- Selección múltiple: unión de resultados, compatibilidad con filtro individual y rechazo de duplicados, inactivos y desconocidos.
- Hero de landing: destino LEXA, borrador sin name serializable y descripción accesible válida.
- Se incluyen las dos regresiones LEXA pendientes autorizadas: carga de página/historial independiente de generación y del proveedor.

## Aislamiento y QA

Antes de crear fixtures, un bootstrap externo verificó dentro del mismo proceso APP_ENV=testing, driver MySQL y SELECT DATABASE() exactamente plataforma_legal_v2_test; caché/sesiones array y almacenamiento privado externo al checkout. TestCase vuelve a comprobar la conexión antes de cada fixture. No se ejecutaron migraciones ni seeders, ni se conectó a la base de desarrollo.

QA de navegador sobre once snapshots SSR generados por el kernel con usuarios, publicación, conversación y mensaje sintéticos dentro de una transacción revertida. Servidor estático temporal en localhost:8126: ningún POST ni API real, ningún Gemini. Assets del build final de esta rama. Temas forzados únicamente en la respuesta CSS del servidor externo de QA, sin modificar archivos del proyecto.

Revisados: portada, login, registro, Inicio, Comunidad, directorio, Perfil, Mensajes, LEXA y Ayuda. Escritorio 1440x900, móvil 390x844 y comprobación de portada a 320px. Sin desbordamiento horizontal en las comprobaciones dirigidas. Se comprobó el menú público móvil, cuenta, selección/chips de especialidades, navegación Turbo Perfil/Comunidad y Atrás, así como apertura/cierre y foco del diálogo de información de Mensajes. Se restableció el viewport y cerró el servidor temporal al terminar.

Límite: los snapshots validan render/assets/interacciones locales, no sustituyen un E2E con servidor HTTP de testing vivo. Envíos, autorización, historial persistente, revocación y generación se verifican por las pruebas Laravel/frontend; no se realizaron envíos desde el navegador estático. Las APIs de ese servidor responden 503 deliberadamente, sin reenviar tráfico. No se midieron métricas de rendimiento ni se afirma mejora de LCP/INP.

## Revisión visual pendiente

- Aprobar editorialmente la marca Lefenda y uniformidad de títulos frente a Plataforma Legal/LEXA; se conservaron los textos específicos de main.
- El panel de actividad del perfil es una composición visual del origen, no un feed nuevo. Se conserva el enlace real a Comunidad; no se inventaron registros ni se eliminó el recorrido funcional.
- Revisar conjuntamente los textos de demostración que aún quedan en landing frente a LEXA real y su disponibilidad por configuración. No se cambió el contrato del proveedor para resolver diferencias visuales.
- Afinar estilo sólo después de revisar este merge; no se importaron cambios de Antigravity ni se leyó su worktree.

## Validación final

| Verificación | Resultado |
| --- | --- |
| Suite Laravel completa tras el último ajuste | 868 pruebas, 18,229 assertions; sin fallos |
| Focalizadas de compatibilidad (Perfiles, auth, LEXA, Comunidad, Mensajes y navegación) | 171 pruebas, 2,344 assertions aprobadas antes de ajustes finales |
| Focalizadas de Biblioteca, Landing, auditoría e integración | 77 pruebas, 1,155 assertions aprobadas; ampliación posterior de markup/privacidad: 3 pruebas, 166 assertions aprobadas |
| Suite frontend completa | 143/143 aprobadas |
| Recuperación de Mensajes | 49/49 aprobadas, incluidas las cuatro regresiones P1 |
| Vite 6.4.6 | Build final aprobado, 99 módulos |
| Pint | Aprobado en los nueve PHP/pruebas modificados; no se formateó deuda global ajena |
| Sintaxis PHP | Nueve archivos afectados sin errores |
| Whitespace | Diff de trabajo y staging sin errores |

CSS final: app.css 92,392 bytes (gzip 17,871); userHeader.css 7,936 (gzip 2,198); login.css 7,873 (gzip 1,958). Se comprobó que sólo app.css emite preflight; no se afirma mejora de tiempos sin medición.

Se comprobó por diff contra main que app.js, ui/page.js, messages.js, messages/polling.js, lexa.js, userHeader.css, CommunityCardIcons.php y post-card.blade.php siguen idénticos. No hay cambios en rutas, migraciones, permisos ni configuración del entorno. No se incorporaron .env, uploads, fixtures en archivos, snapshots, artefactos de build, vendor ni node_modules. Los fixtures de pruebas son creados por las pruebas, dentro de testing.

El HEAD, estado y hashes de las dos pruebas del checkout principal coinciden con la captura previa; los archivos copiados en esta rama son idénticos a los autorizados. No se asegura una inspección interna de otros worktrees: no se accedió a ellos ni se ejecutaron comandos dirigidos a sus rutas.

## Inventario final respecto a main

34 archivos: 28 modificados y seis nuevos. Ninguno eliminado. Los archivos nuevos del origen son cuatro assets; los otros dos son este documento y la prueba de integración.

- Modificado: `app/Http/Requests/Profiles/DirectoryRequest.php`.
- Modificado: `app/Services/Profiles/ProfessionalDirectory.php`.
- Nuevo: `docs/frontend-dev-integration.md`.
- Modificado: `package-lock.json`.
- Nuevo: `public/img/authbanner.png`.
- Nuevo: `public/img/loginbanner.png`.
- Modificado: `resources/css/app.css`.
- Modificado: `resources/css/components/publicHeader.css`.
- Modificado: `resources/css/views/login.css`.
- Nuevo: `resources/img/loginbanner.png`.
- Modificado: `resources/js/professionals.js`.
- Nuevo: `resources/svg/lefenda-logo.svg`.
- Modificado: `resources/views/components/landing/lexa-hero.blade.php`.
- Modificado: `resources/views/components/profiles/card.blade.php`.
- Modificado: `resources/views/components/public/header.blade.php`.
- Modificado: `resources/views/layouts/app.blade.php`.
- Modificado: `resources/views/layouts/guest.blade.php`.
- Modificado: `resources/views/layouts/operations.blade.php`.
- Modificado: `resources/views/layouts/partials/header.blade.php`.
- Modificado: `resources/views/layouts/public.blade.php`.
- Modificado: `resources/views/layouts/verification.blade.php`.
- Modificado: `resources/views/pages/articles/layout.blade.php`.
- Modificado: `resources/views/pages/auth/login.blade.php`.
- Modificado: `resources/views/pages/auth/register.blade.php`.
- Modificado: `resources/views/pages/professionals/index.blade.php`.
- Modificado: `resources/views/pages/profile/public.blade.php`.
- Modificado: `resources/views/pages/profile/show.blade.php`.
- Modificado: `tests/Feature/ContentLibraryIntegrationTest.php`.
- Nuevo: `tests/Feature/FrontendMergeIntegrationTest.php`.
- Modificado: `tests/Feature/IntegrationAuditFixesTest.php`.
- Modificado: `tests/Feature/LandingIntegrationTest.php`.
- Modificado: `tests/Feature/LexaConversationTest.php`.
- Modificado: `tests/Feature/LexaGenerationFlowTest.php`.
- Modificado: `tests/Feature/ProfileIntegrationTest.php`.
