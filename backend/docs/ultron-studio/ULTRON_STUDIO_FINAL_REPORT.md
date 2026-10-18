# ULTRON STUDIO: informe de avance

**Fecha:** 17-10-2026 (se actualiza con cada fase). **Rama:** `feat/ultron-studio` en el CRM (`Iron-Body_Front`) y en el backend (`Iron-Body`, solo documentación).

## Estado

| Hito | Estado |
|---|---|
| A. Auditoría de arquitectura | **HECHO** → `ARCHITECTURE_APPROVED_FOR_BUILD` (17-10) |
| B. Diseño del Studio (capas, modelo, grafo) | **HECHO** |
| C. Frontend visual con datos de demostración | **HECHO** → `VISUAL_IMPLEMENTATION_IN_PROGRESS` → terminado y revisado |
| D. Vista previa protegida publicada | **PENDIENTE DE PUBLICAR** (ver «Publicación» al final) → `ULTRON_VISUAL_PREVIEW_READY` |
| F. Persistencia (borradores, versiones con estados, reglas, aprobaciones, auditoría, permisos `ultron.*`, catálogo servido por el backend) | **EN RAMA, NO DESPLEGADO** (18-10): backend implementado y revisado (`ULTRON_STUDIO_BACKEND_REPORT.md`, `ULTRON_STUDIO_SECURITY_REPORT.md`); integración del CRM en curso |
| G–R. Laboratorio, evaluación IA, Meta, Twilio, canarios, ElevenLabs, vínculo versión↔conversación | **NO EMPEZADOS** (autorizados como construcción, no como activación) |

## Qué hay (clasificación honesta)

**IMPLEMENTADA Y PROBADA** (en el CRM, con pruebas unitarias y E2E en Chrome real):
- Navegación: submenú **Mercadeo › ULTRON Studio** (etiqueta «preview», orbe iridiscente) y ruta propia `/marketing/ultron-studio`, ambas tras la bandera `ultronStudioPreviewEnabled` y el permiso `marketing.agent.manage`.
- Identidad visual exclusiva (negro profundo, violeta / azul / cian / magenta), indicador animado que respeta `prefers-reduced-motion`.
- Tablero Foblex Flow con las **diez capas** como nodos, puertos tipados de entrada y salida, **conexiones** que reproducen el turno real del motor (19 aristas; 17 obligatorias, 2 opcionales por bandera; el commit recibe también de Memoria y Conocimiento), arrastrar y soltar con rejilla, zoom (rueda, botones, teclado), pan, minimapa, ajustar y centrar, deshacer/rehacer, atenuado de capas no relacionadas, conexiones animadas al seleccionar.
- Selector de versión con `LEGACY_BASELINE_V1` como versión publicada de demostración: solo lectura real (todo gesto se rechaza con aviso), el borrador local se conserva.
- **Inspector**: resumen (qué implementa hoy cada capa, qué es configurable sin tocar código, qué está fijo), configuración de borrador con confirmación en los parámetros importantes, reglas reales transcritas (R-01…R-12), dependencias.
- **Validación** del grafo y de la configuración: capas críticas, aristas obligatorias, ciclos dentro del turno, caminos obligatorios, aislamiento, valores que el motor no admite y coherencia bandera ↔ conexiones de cobro; con niveles ok / aviso / bloqueo y «Publicar» deshabilitado.
- Conexiones **tipadas y validadas**: solo se crean las que el motor tiene; las obligatorias no se quitan; cada rechazo explica el motivo.
- Borrador local por persona (`localStorage`), con saneado estricto de lo que se vuelve a leer.
- Accesibilidad: teclado completo (Foblex a11y con mensajes en español), anuncios para lector de pantalla, foco visible, contraste verificado.
- Responsive: escritorio (inspector plegable), tableta (inspector superpuesto), móvil (tira de capas e inspector como hoja).

**PREPARADA** (tipos y contratos definidos, sin motor ni persistencia):
- Modelo de datos del Studio en el frontend: `GraphLayout`, `GraphDefinition`, `LayerConfiguration`, `PublishedAgentVersion`, `StudioRule` (estructura verificable), estados de versión `DRAFT … ROLLED_BACK`.
- Versiones: `local-draft` y `legacy-baseline-v1` como demostración; selector en la barra superior.
- Diseño del backend (`ULTRON_STUDIO_BACKEND_REPORT.md`): tablas, permisos `ultron.*`, reglas que el backend no dejará cambiar.
- Diseño de la evaluación con OpenAI, de Twilio/canarios, de Meta (solo lectura) y del adaptador de ElevenLabs (`ELEVENLABS_NOT_CONFIGURED`).

**NO IMPLEMENTADA** (no existe código; no se simula que exista):
- Persistencia en el servidor de borradores, versiones, aprobaciones y auditoría; permisos `ultron.*` en Laravel.
- Motor de reglas en lenguaje natural e importación automática de `LEGACY_BASELINE_V1`.
- Laboratorio de simulaciones, evaluación asistida por OpenAI, comparador de versiones.
- Campañas Meta, atribución de leads en el Studio, control de Twilio, números canarios en tabla, pruebas telefónicas, ElevenLabs.
- Publicación, canario, retiro y reversión de versiones de ULTRON.

## Evidencia

| Comprobación | Resultado |
|---|---|
| Pruebas del módulo (`ng test --include=ultron-studio/*.spec.ts`) | **46 de 46** |
| Suite completa del CRM | **2066 pasan; 16 fallan: los mismos 16 de la línea base** (moderación y tiempo real), ninguno nuevo |
| `check:literals` | 272 ficheros, correcto |
| `test:agenda-tz` | 394 de 394 (sin cambios en lo que cubre) |
| `tsc` de la aplicación | sin errores |
| Build de producción | correcto; aviso de presupuesto de estilos del componente (27,3 kB sobre 8 kB de aviso, bajo los 32 kB de error) |
| E2E en Chrome 155 real | **44 de 44** (`scratchpad/e2e-studio/studio.cjs`) |
| Revisión de seguridad | sin bloqueantes; H1 (media) y H2 (baja) **corregidos** y cubiertos por pruebas; ver `ULTRON_STUDIO_SECURITY_REPORT.md` |
| Revisión final (`reviewer`) | veredicto inicial **BLOQUEADO** (transición CSS que dejaba las conexiones a 300 px del nodo tras deshacer) + 2 importantes + 13 menores: **todo corregido** y cubierto por pruebas; tabla completa en `ULTRON_STUDIO_VISUAL_REPORT.md` |
| Chunks de producción | sin `app/Services`, `app/Http`, `config/marketing`, `HMAC`, `wamid`, `NO-GO`, `php artisan` |

## Hito F: persistencia (18-10-2026, en rama, sin desplegar)

**IMPLEMENTADO Y PROBADO** (backend `b4e07af`, `f18680a` y siguientes; CRM `038b2f5`, `40a2dd8`, `227e9d2`, `e77496f`; todo local, sin push):
- Catálogo del motor servido por el backend (`GET catalog`, `StudioCatalog` versión `2026-10-18.1`): el chunk público del CRM ya no lo contiene.
- Borrador por persona en el servidor con revisión y conflicto 409; descartar; importación única del borrador de la vista previa.
- Versiones inmutables con estados `DRAFT → VALIDATED | REVIEW_REQUIRED → APPROVED | REJECTED`, validación del grafo y de la configuración repetida en el servidor (paridad con el CRM garantizada por 51 casos generados desde la implementación de referencia), envío por quien la creó, aprobación de cuatro ojos reconociendo exactamente los avisos vigentes, rechazo con motivo, idempotencia por `clientRequestId`.
- Reglas: las 12 del código importadas como `LEGACY_BASELINE_V1` (solo lectura); reglas del Studio con estructura verificable, en borrador y sin aplicación, con tope de 100.
- Auditoría de solo inserción (disparadores en PostgreSQL y SQLite), sin IP ni user agent, con intentos denegados deduplicados.
- Permisos `ultron.view / edit_draft / manage_rules / approve / publish / rollback` validados ruta a ruta en Laravel; Super Admin con las seis, Administrador sin publicar ni revertir; token compartido excluido; toda escritura exige `ultron.view`.
- CRM: página sobre `StudioSession` con fases (carga / sin permiso / error con reintento), selector Mi borrador / línea base / versiones, paneles de versiones (tubería de estados, aprobar/rechazar), reglas (crear, editar, archivar) y auditoría; solo lectura sin `ultron.edit_draft`; aviso al cerrar con cambios sin guardar.

**PREPARADO (no activo):** `publish`/`rollback` (responden 409 siempre: `StudioPublishing::ENGINE_READS_VERSIONS = false` y bandera `ULTRON_STUDIO_PUBLISHING_ENABLED` apagada); los estados `CANARY`, `PUBLISHED`, `RETIRED`, `ROLLED_BACK` existen en el ciclo de vida como inalcanzables, con su motivo.

**NO IMPLEMENTADO:** `ultron.manage_settings` (pospuesto: sin ajustes reales no hay ruta que lo exija), vínculo versión ↔ conversación, laboratorio, evaluación IA, Meta, Twilio, canarios, ElevenLabs.

**Evidencia:** backend Unit 144 + Feature 92 + Access 150 en SQLite (1 omitida a propósito, solo PG) y Studio+Unit 236 en PostgreSQL 14 sin omisiones; `auth:coverage --unmapped` vacío; suite completa 9357 pruebas con los mismos 58 fallos preexistentes de la línea base del día; carreras reales (tester, pila real + PG): 57 + repeticiones forzadas sin un resultado incorrecto, cupos en la frontera exacta, SQL directo con P0001/23514. CRM: 238 specs (módulo 216 + models 22) en verde; suite completa 2265 pruebas con los mismos 16 fallos de línea base; `check:literals` correcto; build de producción sin error de presupuesto (hoja de la página 31,04 de 32 kB); chunk del Studio sin catálogo ni nombres del motor; E2E en Chrome real 32/32 (`ULTRON_STUDIO_VISUAL_REPORT.md`). Revisión de seguridad de la API: sin hallazgos críticos/altos/medios, bajos corregidos (`ULTRON_STUDIO_SECURITY_REPORT.md`). Revisión final independiente (`reviewer`): **APROBADO CON OBSERVACIONES**, sin bloqueantes; 32 de 33 mutaciones deliberadas detectadas (la que sobrevivía, la guarda de `conditionalWrite`, tiene ya su prueba); la observación importante (el inspector enseñaba las reglas de hoy en vez de las copiadas en la versión abierta) está corregida con prueba.

**Despliegue (autorizado y ejecutado el 18-10-2026, 15:24–15:28 UTC) → `HITO_F_DEPLOYED_AND_VERIFIED`.**
- Respaldo verificable previo: `/var/backups/ironbody/ultron-studio-f-20261018-151812/` (dump de 204 tablas, CRM publicado, `.env`, caché de configuración, commit del backend).
- Push con avance rápido: backend `origin/main` = `9acddc3` (incluye `c951237` del Hito F y la conexión `pgsql_owner`), CRM `origin/main` = `020db86`.
- Backend: `/var/www/api` en `9acddc3`; caché de configuración y de **rutas** regeneradas como `www-data` (19 rutas del Studio); migraciones `2026_10_18_100001/100002` aplicadas con el rol dueño y `lock_timeout` 5 s (81 ms y 37 ms); verificación SQL: **11 disparadores, 11 CHECK**, 5 tablas y la función con dueño `iron`; `ultron-studio:import-baseline`: 12 reglas (13 eventos de auditoría); `auth:coverage --unmapped` vacío; permisos efectivos: Super Admin 6 llaves, Administrador 4, Recepción/Entrenador/Administrativo 0, sin overrides, token compartido sin acceso.
- **Modelo de roles de PostgreSQL (S1) aplicado:** nuevo rol de ejecución `iron_app` (solo DML, sin `CREATE` en `public`; `REVOKE CREATE ON SCHEMA public FROM PUBLIC`); la app corre con él (12 workers RUNNING tras `queue:restart`, `health` 200); el rol dueño `iron` solo migra, mediante `/usr/local/bin/ironbody-migrate`, que inyecta sus credenciales desde `/root/.ironbody-owner.env` (600, root) con la caché de configuración limpia y la regenera después. La contraseña del dueño **no** está en el `.env` ni en la caché (la revisión automática del commit señaló ese riesgo y así se evitó).
- `TrustProxies`: no hay CDN ni proxies (`api.ironbodyneiva.cloud` apunta al VPS; nginx sin `real_ip`); `$request->ip()` es el par TCP y el cupo por red no es falsificable. Sin cambios.
- CRM: build sellado `020db86` (`main-7A6NMSXZ.js`, chunk `chunk-VW4TCRMU.js`, sin catálogo ni nombres del motor), `rsync` sin `--delete`, `version.json` público verificado, `main` anterior sigue en 200.
- **Smoke autenticado en producción, 22/22**, con una cuenta temporal `studio-smoke@ironbodyneiva.cloud` (admin id 32, Super Admin; creada para el smoke, escrituras limitadas a su propio borrador y desactivada al terminar; su token responde 403): login real, 6 llaves `ultron.*`, `status` (motor no lee versiones, publicar bloqueado), catálogo con versión y huella iguales a `status` y sin rutas del servidor, borrador que se guarda en Laravel y sobrevive a la recarga, 409 con revisión vieja, validación del servidor de configuración (`config-descuentos`) y de conexiones (E99 → 422), 422 `validation_blocked` al crear versión con bloqueos, descartar, 12 reglas del código de solo lectura (409 al editarlas), versiones vacías, publicar → 404/409, auditoría con `draft.saved`/`draft.discarded` y sin IP, 401 sin token.
- ULTRON Legacy: diff de las zonas del motor entre `84319be` y `9acddc3` vacío; `MARKETING_ULTRON_ENABLED` sigue como estaba; colas sin fallos; ninguna llamada, campaña ni regla real tocada.
- Lo que no se verificó en producción con sesión real: el flujo de versiones y aprobación (crear → enviar → aprobar por otra persona) se validó con la pila real y PostgreSQL en local (tester, E2E 32/32) y no en producción para no dejar versiones inmutables de prueba en el libro; se recomienda que el propietario lo haga con dos cuentas reales (runbook, paso 8).

## Decisiones tomadas (y por qué)

1. **Laravel conserva la autoridad; sin Python.** El motor es Laravel + n8n con contrato firmado; un segundo motor podría contradecir los 28 controles del commit.
2. **El grafo representa el motor real, no un diagrama libre.** Solo existen las conexiones que el motor tiene; los controles son obligatorios; el segmento `Construcción → Reglas → Integraciones` está sellado; las aristas que abren el turno o actúan tras él no cuentan como ciclo.
3. **El Studio nace de solo lectura y demostración.** Nada escribe en el motor; «Publicar» deshabilitado con explicación; aviso permanente de vista previa.
4. **Foblex Flow 19.3.0 con versión exacta** (MIT, cinco paquetes, sin scripts de instalación, sin red). Del tema se incluye solo lo esencial por presupuesto de estilos.
5. **Bandera de vista previa y permiso existente** (`marketing.agent.manage`, Super Admin y Administrador) para no inventar permisos antes de que exista la fase de backend que los valide.
6. **El catálogo no cartografía el servidor**: nombres de clases sin rutas ni líneas (hallazgo H1), con prueba que lo vigila; en la fase con persistencia vendrá del backend.

## Preguntas abiertas para el propietario

Las de `ULTRON_STUDIO_ARCHITECTURE_AUDIT.md` §14: versionado de cada turno en el commit; qué significan «números canarios» (hoy el canario es UNA conversación de WhatsApp); número de voz de Twilio o solo caller ID verificado, y si se acepta una subcuenta; si una voz puede decir precios; clave de OpenAI separada; quién escribe los prompts en n8n.

## Autorizaciones

- **Usadas:** auditoría, diseño, implementación, dependencias compatibles, pruebas, documentación, construcción del frontend, commits locales, publicación protegida de la vista previa.
- **No usadas (y no se usarán sin orden expresa):** cambiar ULTRON en producción, llamadas reales, campañas o presupuestos Meta, publicar reglas, reemplazar el motor, Python, ElevenLabs, APIs de pago, migraciones en producción, **push a `main`**.

## Publicación

Vista previa protegida publicada el **18-10-2026 02:56 UTC** siguiendo `ULTRON_STUDIO_DEPLOYMENT_PLAN.md` (respaldo → comprobación de que producción seguía en `4334dd8` → `rsync` sin `--delete` → verificación pública). No hay entorno de staging; la vista previa vive en el CRM de producción detrás de la bandera y del permiso `marketing.agent.manage`.

- **URL REAL:** `https://ironbodyneiva.cloud/marketing/ultron-studio` (submenú **Mercadeo › ULTRON Studio**, etiqueta «preview»)
- **COMMIT:** `4f7d774` (rama `feat/ultron-studio` del CRM; sobre `fc48775`, base de producción `4334dd8`)
- **VERSIÓN:** `version.json` público `{"commit":"4f7d774","dirty":false,"builtAt":"2026-10-18T02:55:57.291Z"}` · `main-GKAYY7VU.js` · chunk del Studio `chunk-JQQ2A6ZK.js` (488,95 kB en bruto, 104,2 kB transferidos) · Foblex Flow 19.3.0
- **ESTADO:** `ULTRON_VISUAL_PREVIEW_READY`

**Verificación pública tras publicar:** `index.html` apunta al `main` nuevo; `version.json` con el commit nuevo; chunk del Studio 200; `main` anterior (`main-VR37CLFV.js`) sigue en 200 para las pestañas abiertas; `/marketing/ultron-studio` 200; el chunk público no contiene rutas del backend ni textos operativos (comprobado con `curl`).

**Respaldo para revertir:** `/var/backups/ironbody/crm-pre-ultron-studio-20261018-025639/` (`index.html` y `version.json` de `4334dd8`); procedimiento en `ULTRON_STUDIO_ROLLBACK.md`.

**Acceso:** solo Super Admin y Administrador (los roles con `marketing.agent.manage`). Recepción, Entrenador y Administrativo no ven el submenú y el guard los devuelve al inicio. Al abrirlo: mover capas, conectar o quitar las opcionales (E14/E16), editar parámetros de borrador (los importantes piden confirmación), Validar, Guardar borrador (solo en ese navegador), cambiar a `LEGACY_BASELINE_V1` para ver la línea base en solo lectura. «Publicar» está deshabilitado a propósito.

**Limitaciones conocidas:** datos de demostración; el borrador vive en `localStorage` de cada persona; sin backend del Studio (llega con la fase de persistencia); probado en Chrome (escritorio y emulación móvil), no en Safari/Firefox ni con gestos táctiles reales; el asistente IRON del CRM flota en la esquina inferior derecha también aquí.

**Push a `main`:** autorizado y hecho el 18-10-2026 (`origin/main` del CRM en `4f7d774`; del backend, en los commits de esta documentación). Publicar el CRM desde `origin/main` conserva el Studio.
