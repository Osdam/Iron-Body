# ULTRON STUDIO: informe de avance

**Fecha:** 17-10-2026 (se actualiza con cada fase; última actualización: 19-10-2026, Hito G implementado y sin desplegar). **Rama:** `feat/ultron-studio` en el CRM (`Iron-Body_Front`) y en el backend (`Iron-Body`, solo documentación); el Hito G, en `feat/ultron-studio-g` de los dos repos.

## Estado

| Hito | Estado |
|---|---|
| A. Auditoría de arquitectura | **HECHO** → `ARCHITECTURE_APPROVED_FOR_BUILD` (17-10) |
| B. Diseño del Studio (capas, modelo, grafo) | **HECHO** |
| C. Frontend visual con datos de demostración | **HECHO** → `VISUAL_IMPLEMENTATION_IN_PROGRESS` → terminado y revisado |
| D. Vista previa protegida publicada | **PUBLICADA** el 18-10-2026 a las 02:56 UTC (ver «Publicación» al final) → `ULTRON_VISUAL_PREVIEW_READY` |
| F. Persistencia (borradores, versiones con estados, reglas, aprobaciones, auditoría, permisos `ultron.*`, catálogo servido por el backend) | **DESPLEGADO Y VERIFICADO** (18-10-2026, 15:24–15:28 UTC; backend `9acddc3`, CRM `020db86`): ver «Hito F» más abajo, `ULTRON_STUDIO_BACKEND_REPORT.md` y `ULTRON_STUDIO_SECURITY_REPORT.md` |
| G. Control funcional de las diez capas (configuración real por capa, lecturas del motor, reglas con condiciones, historial y comparación) | **IMPLEMENTADO, REVISADO Y VERIFICADO EN RAMA; DESPLIEGUE EN CURSO** (18/19-10-2026; backend código `dfb30f5`, CRM `44c0e8c`). Revisiones de seguridad, de pruebas y final (APROBADO CON OBSERVACIONES, todas cerradas) incorporadas. E2E con la pila real: 126/126 sobre el código final. Smoke de producción validado en local 29/29. **Pendiente:** el despliegue y su verificación (ver «Hito G» más abajo) |
| Siguientes (rotuladas «G–R» antes de que G pasara a ser el control funcional): laboratorio, evaluación IA, Meta, Twilio, canarios, ElevenLabs, vínculo versión↔conversación | **NO EMPEZADOS** (autorizados como construcción, no como activación) |

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

## Hito G: implementado (18/19-10-2026, en rama, sin desplegar)

**Resumen ejecutivo.** Hasta F, el inspector enseñaba 21 parámetros como si fueran mandos del agente, y casi ninguno lo es. G hace que el Studio diga la verdad sobre el agente sin tocarlo:
- cada parámetro declara de dónde sale su valor real y quién lo cambia de verdad;
- el Studio lee en vivo (solo lee) cinco parámetros y cinco lecturas más del motor;
- las reglas del Studio ganan condiciones verificables («si… entonces…»), y el servidor rechaza las que contradicen un control del motor u otra regla;
- el historial se lee en frases, y se pueden comparar versiones.

**El hecho incómodo:** de los 21 parámetros, **4 son mandos reales del motor** (y se cambian en el entorno del servidor, no en el Studio), **1 es un dato del motor** (los planes), **11 están fijos** en el código o en n8n y **5 no tienen equivalente** en el motor.

**Dónde está:** backend `391cffb` (el código, en `dfb30f5`) y CRM `7d3ad2a`, en la rama `feat/ultron-studio-g` de los dos repos, **sin push ni despliegue**. Contrato: `ULTRON_STUDIO_G_CONTRACT.md`, con las Enmiendas 1 y 2.

**IMPLEMENTADO Y PROBADO** (en rama):
- **Configuración real por capa.** El catálogo `2026-10-19.1` trae `bindings` (origen, valor declarado o en vivo, efecto y quién lo cambia de verdad), y la fila de parámetro del inspector enseña «Motor: …», «≠ motor» y «Usar el valor del motor».
- **Lecturas del motor** (`GET engine/readings`, solo lectura): el driver efectivo, la bandera del cobro, el interruptor, el canario (si existe, nunca cuál), los planes vendibles (solo nombres), la pausa, el freno, el canario del cobro (si existe) y la base de conocimiento (versión y recuentos, sin contenido). Una fuente caída sale como no disponible, y el Studio sigue.
- **Reglas con condiciones:**
  - una gramática verificable, canonicalizada en el servidor;
  - 422 contra los controles del motor y 409 `rule_contradiction` contra otras reglas del Studio;
  - el análisis en cada regla, y los hallazgos de reglas en la validación del borrador y de las versiones;
  - el mismo núcleo en PHP y en TS, con paridad sobre 123 casos escritos a mano.
- **Historial legible** (del borrador, de cada regla y de cada versión) y **comparación** de una versión con la línea base, con el propio borrador o con otra versión (`GET compare`).
- **CRM:** inspector de cinco pestañas, editor de condiciones, prevención al proponer valores y un aviso fijo de datos personales en las reglas.
- **Versiones de formato 2**, con las condiciones copiadas, y compatibilidad completa con las de F.
- **Correcciones de las revisiones** (`dfb30f5`):
  - tope de cuerpo de 64 KB y validación en dos fases en las 21 rutas, que también cierra la clase de M-2 en las vías de F;
  - análisis neutro de las reglas archivadas;
  - READ COMMITTED fijado en las escrituras de reglas;
  - repetición idempotente con el tope de reglas lleno;
  - freno y pausa «no disponibles» si su consulta falla;
  - pruebas de aislamiento que escuchan cada sentencia SQL.

**QUÉ NO HACE** (por diseño; las protecciones siguen intactas):
- **El motor no lee nada del Studio** (`StudioPublishing::ENGINE_READS_VERSIONS = false`). Ninguna ruta de G escribe en tablas, configuración o ficheros del motor. Una prueba recorre las 21 rutas con escrituras reales, escucha cada sentencia SQL y exige la misma huella de todas las tablas ajenas al Studio y de la configuración.
- **Publicar y revertir siguen respondiendo 409 siempre.** Una versión `APPROVED` no cambia al agente.
- **Las reglas del Studio no se aplican** (`enforcement = none`): aprobar una versión no las hace cumplir. Por eso, toda versión con alguna S-NNN queda en `REVIEW_REQUIRED` (`reglas-sin-aplicacion`).
- **Los 4 mandos reales no se cambian desde el Studio.** Se cambian en el entorno del servidor, con el procedimiento que exija cada uno. G no escribe ajustes: `ultron.manage_settings` sigue pospuesto.
- **Las afirmaciones de texto** se guardan, pero no se evalúan: eso llegará con el laboratorio (Hito 4).
- **Sin llamadas, sin Meta, sin ElevenLabs y sin consumo pagado.**

**Evidencia** (el detalle, en `ULTRON_STUDIO_BACKEND_REPORT.md`, `ULTRON_STUDIO_SECURITY_REPORT.md` y `ULTRON_STUDIO_VISUAL_REPORT.md`):
- **Backend:**
  - Studio + Unit + Access en SQLite: 618 pruebas, OK, con 5 omitidas que solo corren en PG (repetido en `391cffb` al escribir este informe);
  - Studio + Unit en PostgreSQL 14: 468/468;
  - suite completa: 9589 pruebas, con los mismos 58 fallos de la línea base;
  - `auth:coverage --unmapped` vacío y 21 rutas;
  - el motor, sin cambios (`git diff origin/main`: 0 líneas);
  - carreras reales del tester: 17 escenarios, cada uno con 20 repeticiones naturales y 20 forzadas, sin un resultado incorrecto salvo H-3, ya corregido.
- **CRM:**
  - 489 specs del módulo, y la suite completa con 2522 en verde y los 16 fallos de la línea base;
  - build de producción sin errores de presupuesto, con `ultron-studio.scss` sin un byte más;
  - el chunk, sin nombres del motor;
  - 15 mutaciones, todas detectadas;
  - Chrome real, 20/20 con la API simulada.
- **E2E con la pila real:** 126/126 sobre el código final (backend `dfb30f5`, CRM `44c0e8c`); las pasadas previas dieron 124/126 por H-1 del E2E, corregido (ver abajo).
- **Revisiones:**
  - `security`: sin críticos, altos ni medios. B-1 se resolvió con un aviso; el procedimiento de redacción queda pendiente del propietario;
  - `api-security-specialist`: sin críticos ni altos. M-1, M-2 y B-2, corregidos; B-1, aceptado;
  - `tester` de backend: sus H-1 y H-3, corregidos; su H-2 pasa a ser una puerta del despliegue. Los hallazgos del E2E se numeran aparte.

**Decisiones del lead** (contrato y Enmienda 2):
1. Incumplir una regla del Studio es un **aviso**, no un bloqueo: las S-NNN son propuestas, y el aviso obliga a que otra persona lo reconozca al aprobar.
2. Las lecturas del motor las ve todo `ultron.view` (Super Admin y Administrador).
3. Una regla del Studio puede proponer encender el cobro, como aviso.
4. Correcciones obligatorias antes de desplegar: el análisis neutro de las archivadas, el tope de cuerpo de 64 KB, la validación en dos fases y READ COMMITTED. Además: la puerta de PostgreSQL y el aviso de datos personales. Se acepta sin cambio el coste acotado del análisis (B-1 de api-security).

### Veredicto del reviewer

**APROBADO CON OBSERVACIONES** (19-10-2026, sobre backend `dfb30f5` y CRM `7d3ad2a`; informe en `scratchpad/hito-g/informe-reviewer.md`). Sin bloqueantes en el código. Verificado por el reviewer: 0 líneas en el motor; Studio + Access 618 en SQLite y Studio 468/468 en PG 14; suite completa con los mismos 58 fallos de línea base; `auth:coverage --unmapped` vacío; 21 rutas; `permissions:export` y los 3 fixtures idénticos a los del CRM; Pint en los 42 ficheros PHP de G; migración reversible (7 disparadores en SQLite, 11 + 11 CHECK en PG); CRM 2 522 con los 16 de base, build sin error de presupuesto, `ultron-studio.scss` intacto, sin `innerHTML`; 16 mutaciones cazadas (11 backend, 5 CRM); transcripción A3 fiel a la auditoría. Observaciones y su cierre:
- **I-1 (importante, CRM):** tras crear, editar o archivar una regla no se revalidaba el borrador y aparecía una discrepancia falsa → **corregido en el CRM `44c0e8c`** (relectura simultánea de reglas y borrador, con guarda de revisión; prueba de regresión a partir de la sonda del reviewer).
- **I-2 (importante, lead):** el smoke de producción tenía un caso que la primera fase de validación cortaba y asumía que no hay reglas S-NNN vivas → **corregido** (`smoke-g.py` lee las reglas vivas antes de asumir nada; validado contra la pila local).
- Menores: la cifra de reglas resincronizadas (8 de 12) y el texto del 413 en la Enmienda 2, corregidos en la documentación; las pruebas con umbrales de tiempo (50 ms, 1 s) están entre 3 y 40 veces por encima de lo medido y se repiten si la máquina está cargada; una versión con formato desconocido solo puede existir por inserción manual en la base (aceptado). El 413 sí consume cupo (el límite de peticiones corre antes que el tope de cuerpo).

### E2E con pila real

**Hecho por el tester el 18/19-10-2026, en local. Nada de producción.**

**La pila:**
- el backend, en instantáneas de `c8d9cf4` y de `391cffb`, con `artisan serve --no-reload` y PostgreSQL 14;
- el CRM `7d3ad2a` con `ng serve`;
- Chrome real, con login por el formulario y tres cuentas reales (Super Admin, Administrador y Recepción).

**Resultado final: 126/126 en 91 s** (ejecutado por el lead el 19-10-2026 sobre la instantánea `45eb958`, código `dfb30f5`, con el CRM `44c0e8c`; `scratchpad/e2e-studio/g-run.sh 45eb958`). Pasadas previas del tester: 124/126 en `391cffb` y 122/126 en `c8d9cf4` (las dos de diferencia son las del freno sin tabla, que el código corregido ya da como no disponible; las dos restantes, H-1 del E2E, corregidas en el CRM `38b238b`).

**Qué pasa en los nueve escenarios:**
- E1, carga: lecturas reales del motor y una fuente caída sin tumbar el Studio;
- E2, inspector: «≠ motor», «Usar el valor del motor» y la prevención;
- E3, reglas con condiciones: 201, 409, 422 y quitar condiciones;
- E4, borrador: hallazgos de reglas en vivo, y «el servidor manda»;
- E5, versiones de formato 2 y cuatro ojos: publicar sigue en 409;
- E6, permisos: 403 sin llave, y Recepción sin acceso;
- E7, texto hostil: literal en todas las vistas;
- E8, teclado y móvil;
- E9, cierre: **la huella de las tablas del motor y de las colas es igual al empezar y al terminar**, sin errores de JS ni 5xx.

El E2E detecta las 6 mutaciones que se le probaron.

**Hallazgos del E2E, cerrados en el CRM `38b238b`:** H-1 (medio): tras el 409, «Ver S-NNN» no llevaba el foco ni la vista a la regla porque `ib-dialog` devuelve el foco al cerrarse después del desplazamiento; el desplazamiento y el foco se encolan ahora detrás (prueba con 51 reglas y el `ib-dialog` real; en Chrome con 63 reglas el foco queda en la regla y visible). H-2 (bajo, ya presente en F): con el inspector abierto la cabecera se encogía (`min-height` anulaba el mínimo por contenido) y los botones tapaban el aviso a 1440–1920 px; `flex: none` en la cabecera (+10 bytes compilados, hoja en 27,88 kB). Detalle en `ULTRON_STUDIO_VISUAL_REPORT.md`, «E2E con la pila real».

**Qué no cubre:** la pila de producción (nginx con FPM, cachés y el build de producción), los cupos, el 413, la tableta, Safari, Firefox ni los lectores de pantalla.

**Cerrado por el lead:** H-1 y H-2 corregidos (`38b238b`), I-1 del reviewer corregido (`44c0e8c`), spec de texto hostil confirmado en el CRM (`88a6dc5`, 6 pruebas). Suite completa del CRM en `44c0e8c`: 16 fallos, idénticos nombre a nombre a la línea base; módulo del Studio 499 specs.

### Despliegue y verificación

<!-- PENDIENTE: despliegue y verificación -->

**Pendiente de autorización y de ejecución.** El runbook está en `ULTRON_STUDIO_DEPLOYMENT_PLAN.md` («Hito G: runbook») y la vuelta atrás en `ULTRON_STUDIO_ROLLBACK.md` («Hito G»).

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
