# ULTRON STUDIO: informe de avance

**Fecha:** 17-10-2026 (se actualiza con cada fase; última actualización: 19-10-2026, noche en hora de Bogotá: Hito 4 implementado en rama y sin desplegar). **Rama:** `feat/ultron-studio` en el CRM (`Iron-Body_Front`) y en el backend (`Iron-Body`, solo documentación); el Hito G, en `feat/ultron-studio-g` de los dos repos; el Hito 4, en `feat/ultron-studio-h4` de los dos repos.

## Estado

| Hito | Estado |
|---|---|
| A. Auditoría de arquitectura | **HECHO** → `ARCHITECTURE_APPROVED_FOR_BUILD` (17-10) |
| B. Diseño del Studio (capas, modelo, grafo) | **HECHO** |
| C. Frontend visual con datos de demostración | **HECHO** → `VISUAL_IMPLEMENTATION_IN_PROGRESS` → terminado y revisado |
| D. Vista previa protegida publicada | **PUBLICADA** el 18-10-2026 a las 02:56 UTC (ver «Publicación» al final) → `ULTRON_VISUAL_PREVIEW_READY` |
| F. Persistencia (borradores, versiones con estados, reglas, aprobaciones, auditoría, permisos `ultron.*`, catálogo servido por el backend) | **DESPLEGADO Y VERIFICADO** (18-10-2026, 15:24–15:28 UTC; backend `9acddc3`, CRM `020db86`): ver «Hito F» más abajo, `ULTRON_STUDIO_BACKEND_REPORT.md` y `ULTRON_STUDIO_SECURITY_REPORT.md` |
| G. Control funcional de las diez capas (configuración real por capa, lecturas del motor, reglas con condiciones, historial y comparación) | **DESPLEGADO Y VERIFICADO** (19-10-2026 06:00–06:04 UTC; backend `e651dd9`, código `dfb30f5`; CRM `44c0e8c`). Revisiones de seguridad, de pruebas y final (APROBADO CON OBSERVACIONES, todas cerradas); E2E con la pila real 126/126; smoke en producción 29/29. Detalle en «Hito G» más abajo. **`HITO_3_COMPLETED`** |
| Hito 4. Laboratorio de IA (escenarios sintéticos, simulaciones del Studio, evaluación asistida con OpenAI y clave propia, veredicto humano) | **IMPLEMENTADO Y VERIFICADO EN RAMA, NO DESPLEGADO** (19-10-2026; backend `6dbf485`, CRM `3e630eb`). Revisiones de `security`, `api-security-specialist` y `tester` cerradas; E2E con la pila real 122/122; pendientes: la Enmienda 5 (ejecuciones huérfanas y cobertura, sin implementar), la revisión final del `reviewer` y el despliegue por fases. Detalle en «Hito 4» más abajo |
| Siguientes (rotuladas «G–R» antes de que G pasara a ser el control funcional): Meta, Twilio, canarios, ElevenLabs, vínculo versión↔conversación (el laboratorio y la evaluación con IA son el Hito 4) | **NO EMPEZADOS** (autorizados como construcción, no como activación) |

## Qué hay (clasificación honesta)

*Clasificación del 17-10-2026, de la vista previa visual. Lo implementado después está en las secciones de F, de G y del Hito 4.*

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

**EJECUTADO el 19-10-2026, 06:00–06:04 UTC**, por el lead, con la autorización del usuario para el despliegue progresivo de los componentes terminados, verificados y seguros.

- **Respaldo verificado:** `/var/backups/ironbody/ultron-studio-g-20261019-060047/` (`ironbody.dump` 30 MB con 209 tablas con datos, las 5 del Studio incluidas; `app-crm.tgz` 76 MB, 1 703 entradas; `env.backup` 600; `bootstrap-cache/`; `api-commit.txt` = `9acddc3`; `crm-main.txt` = `main-7A6NMSXZ.js`). CRM: `/var/backups/ironbody/ultron-studio-g-crm-20261019-060251/` (`index.html` y `version.json` anteriores).
- **Push por fast-forward:** backend `origin/main` = `e651dd9` (código `dfb30f5`), CRM `origin/main` = `44c0e8c`; sin trabajo ajeno, lockfiles sin cambios.
- **Backend:** `/var/www/api` en `e651dd9` sin modificados; `ironbody-migrate` con `lock_timeout` 5 s → `2026_10_19_100001_add_conditions_to_ultron_studio_rules` en 8,74 ms; cachés de configuración y rutas regeneradas como `www-data` con modo 600; `queue:restart`; 11 disparadores y 11 CHECK; columna `conditions json` nullable; **21 rutas**; `ultron-studio:import-baseline` (simulación y real): «0 nuevas, 8 sincronizadas, 4 sin cambios» (R-01, 02, 04, 05, 07, 09, 10 y 12 en revisión 2; R-03, 06, 08 y 11 en revisión 1); `auth:coverage --unmapped` vacío; `status`, `engine/readings` y `compare` sin token → 401; 12 workers RUNNING, 0 en BACKOFF/FATAL.
- **CRM:** build sellado `44c0e8c` (`dirty: false`), `main-JNV5BHFD.js`, chunk del Studio `chunk-W3AZFF3A.js` sin nombres del motor; `rsync` sin `--delete` y `chown root:root`; el bundle anterior `main-7A6NMSXZ.js` sigue servido (pestañas abiertas); `/marketing/ultron-studio` → 200.
- **Smoke autenticado en producción: 29/29** (`smoke-g.py` con la cuenta temporal admin id 32, reactivada con contraseña nueva y desactivada al terminar; su token → 403): catálogo `2026-10-19.1` con 21 `bindings`, 5 `readings` y `legacyConditions`; `engine/readings` con `paymentLinksEnabled = no` y driver `openai`, sin secretos; borrador con `uuid`, sin hallazgos de reglas (0 S-NNN vivas); `compare` línea base→borrador `same: true`, 422 con lados iguales, 404 con versión inexistente; 12 reglas del código con condiciones en R-01/R-02/R-07/R-12 y `analysis`; tres `POST rules` → 422 (control, forma en dos claves, tamaño de lista) sin dejar fila; R-xx → 409 `legacy_rule_read_only`; publicar → 404 en ruta protegida; descartar conserva el `uuid`; auditoría sin IP ni user agent. Solo escrituras reversibles en el borrador de esa cuenta; 0 versiones y 0 reglas del Studio en producción.
- **Después:** `git diff HEAD` vacío; fichero de entorno idéntico al respaldo (`MARKETING_ULTRON_ENABLED` sin cambios); 0 colas fallidas; 0 errores en `laravel.log`; auditoría reciente: `system.baseline_imported` 1, `rule.legacy_synced` 8, `draft.saved` 1, `draft.discarded` 1. El motor no lee nada del Studio: `ENGINE_READS_VERSIONS = false`, publicar y revertir siguen en 409.

Runbook en `ULTRON_STUDIO_DEPLOYMENT_PLAN.md` («Hito G: runbook», ejecutado) y vuelta atrás en `ULTRON_STUDIO_ROLLBACK.md` («Hito G»). **Estado: `HITO_3_COMPLETED`** (F y G desplegados y verificados).

## Hito 4: laboratorio de IA (19-10-2026, implementado en rama, sin desplegar)

**Resumen ejecutivo.** El laboratorio deja probar una versión del Studio antes de que nadie la apruebe, sin tocar al agente. Una persona elige la versión (o su borrador, o la línea base del código) y unos escenarios, conversaciones **sintéticas** escritas por el personal. Por cada escenario:
- un **actor de laboratorio** (un modelo de IA con la clave propia del laboratorio) contesta como lo haría un asesor que siguiera esa configuración y esas reglas;
- los **filtros del motor** (los mismos que usa ULTRON, como funciones de solo lectura) y las comprobaciones del Studio marcan lo que el motor marcaría;
- otro modelo, el **juez**, opina criterio a criterio sin ver lo anterior;
- el código **propone** (aprobar, revisar o rechazar) y una **persona distinta del autor** emite el veredicto.

Antes de gastar, la persona ve cuánto costará como mucho y lo acepta; el servidor reserva ese dinero contra un tope diario y no deja pasar de él. Hoy **no gasta nada**: el laboratorio está apagado por defecto, en modo de ensayo sin IA y con presupuesto 0.

**Dónde está:** backend `6dbf485` (más la documentación, hasta `403a12b`) y CRM `3e630eb`, en la rama `feat/ultron-studio-h4` de los dos repos, **sin push ni despliegue**. Contrato: `ULTRON_STUDIO_H4_CONTRACT.md`, con las Enmiendas 1–5 y sus precisiones. **La Enmienda 5 (ejecuciones huérfanas y cobertura) está pendiente de implementar:** lo que sigue describe `6dbf485`.

**IMPLEMENTADO Y PROBADO** (en rama):
- **Escenarios:** el catálogo de 11 (`C-01` a `C-10` y `C-12`, versión `2026-10-20.1`; C-11 retirado por la Enmienda 6 porque contradecía R-11) y los del personal (`E-001…`, hasta 100 vivos), con idempotencia, conflicto 409, archivo con motivo y un filtro que rechaza correos, teléfonos, documentos y caracteres invisibles en todo texto que sale hacia el proveedor.
- **Ejecuciones** sobre una versión, el borrador propio o la línea base: estimación aceptada de forma explícita, reserva atómica contra el tope diario (día de Bogotá) y por ejecución, corte por coste real, cancelación, caducidad, plazo y paradas por errores del proveedor, un caso a la vez en un worker propio.
- **Evaluación:** 5 comprobaciones del Studio (entre ellas `lab-invisibles`) y las guardas puras del motor a través de un único adaptador; un juez con salida estricta; una agregación determinista en la que un hallazgo bloqueante gana siempre a la IA.
- **Cliente de OpenAI** (Responses API) con `store: false` siempre, URL fija en producción, sin reintentos y con la clave del laboratorio, que nunca sale del entorno del servidor; un **modo de ensayo** sin red ni coste.
- **Veredicto humano:** cuatro ojos respecto al autor de la versión, uno por ejecución, inmutable, con la huella de lo que la persona vio y el reconocimiento exacto de los casos que no pasaron.
- **Libro propio** de solo inserción, y 5 tablas con sus guardas en PostgreSQL y en SQLite.
- **CRM:** la página `marketing/ultron-studio/laboratorio` con escenarios, lanzador con estimación, vista de la ejecución y de cada caso, veredicto, comparación con la línea base e historial; enlaces desde el tablero y desde cada versión.
- **Correcciones de las revisiones** (Enmienda 4): tope por ejecución sobre el coste real, `lab-invisibles`, archivados con tope, limitador por persona, cancelación releída bajo el cerrojo, READ COMMITTED en la deduplicación y avisos de datos personales al archivar y al rechazar.

**QUÉ NO HACE** (por diseño; las protecciones siguen intactas):
- **No es ULTRON.** Es una simulación del Studio: no usa n8n, ni sus prompts, ni `decide`/`commit`, y la pantalla lo dice en cada ejecución («Simulación del Studio · no es ULTRON»). Los filtros del motor se aplican a un texto sintético con hechos sintéticos: el laboratorio dice lo que el filtro **marcaría**, nunca lo que el motor haría.
- **El motor no lee nada del Studio** (`StudioPublishing::ENGINE_READS_VERSIONS = false`) ni del laboratorio, y el laboratorio no escribe en el motor: 0 bytes de diff en sus zonas, y una prueba que escucha cada sentencia SQL lo vigila.
- **Publicar y revertir siguen respondiendo 409 siempre.** El veredicto no cambia la versión ni el motor, y en este hito no condiciona `submit` ni `approve` (podrá hacerlo el Hito 5).
- **Nada se aprueba solo:** la propuesta del laboratorio no es un veredicto, y ningún código del runner, del job ni de los comandos escribe uno (lo vigila una prueba estática; la Enmienda 5 la amplía a las escrituras directas en la tabla de veredictos).
- **Sin llamadas, sin Meta, sin ElevenLabs.** No reproduce conversaciones reales, no simula varios turnos con un prospecto simulado y el modelo nunca escribe escenarios.
- **Sin coste hasta la fase 3.** En las fases 1 y 2 no sale ninguna petición a OpenAI: no hay clave, el driver es `fake` y el presupuesto es 0.

**Evidencia** (el detalle, en `ULTRON_STUDIO_BACKEND_REPORT.md`, `ULTRON_STUDIO_SECURITY_REPORT.md` y `ULTRON_STUDIO_VISUAL_REPORT.md`):
- **Backend:**
  - Unit del Studio: 702/702 en el núcleo; paridad con el fixture del tester, 207/207 (206 casos escritos a mano desde el contrato);
  - Studio + Unit + Access + Chaos en SQLite: 1185, OK (11 omitidas que solo corren en PG);
  - Studio + Unit en PostgreSQL 14: 934/934 en `6dbf485`;
  - suite completa: 10 055 pruebas, 0 fallos nuevos frente a la línea base;
  - `auth:coverage --unmapped` vacío y 33 rutas;
  - el motor, sin cambios (`git diff origin/main 6dbf485`: 0 bytes en sus zonas);
  - 26 mutaciones del backend, todas cazadas (5 del núcleo, 12 de la API y 9 de las correcciones), más las 14 del encargo del tester sobre `6dbf485`, también cazadas.
- **CRM:** 739/739 specs del módulo en `3e630eb`; suite completa con 2786 pruebas y los 16 fallos de la línea base (`564cf14`); build de producción sin errores de presupuesto; chunk del laboratorio de 187,58 kB sin nombres del motor ni secretos; `ultron-studio.scss` sin un byte más; 27 mutaciones cazadas (18 + 6 + 3) y Chrome real con la API simulada 40/40, 19/19 y 13/13.
- **E2E con la pila real:** **122/122** sobre `6dbf485` y `3e630eb` (ver abajo).
- **Revisiones:**
  - `api-security-specialist`: sin críticos, altos ni medios. B-1, B-2, B-3 e I-3, corregidos; el resto, aceptado y documentado;
  - `security`: sin críticos, altos ni medios. B-1 y B-2, corregidos; la retención de OpenAI y el proyecto dedicado, pendientes del propietario;
  - `tester` de backend: carreras de creación sin un solo resultado incorrecto; HIP-1 (cancelar frente al reclamo) confirmada y corregida; HIP-2, corregida. Sobre `6dbf485`, las correcciones aguantan con el worker real; quedan **abiertos H-P2-1** (ejecución huérfana, sin gasto) y dos huecos de cobertura, con su corrección decidida en la Enmienda 5 y pendiente de implementar (ver abajo);
  - `reviewer`: APROBADO CON OBSERVACIONES para las fases 1 y 2, sin bloqueantes; cuatro observaciones importantes cerradas antes de la fase 3 (Enmienda 6).

**Decisiones del lead** (contrato y enmiendas):
1. **Simulación del Studio**, etiquetada en datos y pantalla, con las guardas puras del motor desde un único adaptador; el veredicto lo emite una persona.
2. **Llaves:** `ultron.lab_manage` (escenarios; Administrador por defecto) y `ultron.lab_run` (lanzar y cancelar, que gastan; **solo Super Admin por defecto**); el veredicto con `ultron.approve` y cuatro ojos.
3. **Topes:** presupuesto diario 0 por defecto. Los valores propuestos para cuando llegue la clave (US$ 2 al día, US$ 0,50 por ejecución, 20 ejecuciones al día, 12 casos) y el modelo y los precios los fija el propietario con la página oficial.
4. **Worker dedicado `ironbody-lab`** desde la fase 1, con la bandera apagada.
5. **El smoke de producción no crea filas positivas**; antes de la fase 3, el propietario confirma la retención de OpenAI, crea un proyecto con presupuesto propio y escribe la clave en el servidor.
6. **Enmienda 4:** corregir B-1, B-2, B-3, el limitador por persona, la cancelación y READ COMMITTED antes de desplegar; aceptar y documentar el resto.

### Hito 4: veredicto del reviewer

**APROBADO CON OBSERVACIONES** para las fases 1 (apagado) y 2 (`fake`), sin bloqueantes (20-10-2026, sobre backend `6dbf485` y CRM `3e630eb`; informe en `scratchpad/hito-4/informe-reviewer.md`). Verificado por el reviewer: diff del motor vacío y las 21 rutas de F/G intactas; Studio + Access + Chaos 1 185 en SQLite y Studio 934 en PG 14; suite completa igual que `origin/main` en el mismo entorno; migraciones con rol sin superusuario (27 disparadores / 24 CHECK, `rollback --step=2` limpio); `auth:coverage` vacío; permisos y 4 fixtures idénticos a los del CRM; CRM 739 specs, build limpio, hojas < 8 kB, `ultron-studio.scss` intacto; 24 mutaciones cazadas salvo M16. Cuatro observaciones importantes **a cerrar antes de la fase 3** (todas decididas en la Enmienda 6 y aplicadas en el segundo lote de correcciones): I-1 el escenario C-11 contradecía R-11 (el motor no responde a quien pide que no le escriban) → retirado, catálogo de 11; I-2 `lab-invisibles` dejaba pasar 116 caracteres invisibles → misma clase que el filtro de entrada menos el ZWJ; I-3 `budgetCap` no se exponía → expuesto en `RunSummary`/`RunDetail` y redactado por tipo en el CRM; I-4 ninguna prueba vigilaba que una ejecución `fake` no use OpenAI si el driver cambia a mitad → el cliente se elige por el `mode` de la ejecución, con prueba. Menores: m-1 ejecución huérfana (= H-P2-1 del tester, corregida en `7113f9f`), m-2 aislamiento de `UltronStudioLabCancelLockTest` (corregido en el segundo lote), m-3 posible falsa alarma `queue_unattended` de IRON GUARD con pasos largos en la fase 3 (umbral por carril si la observabilidad lo admite; si no, pendiente de la fase 3), m-4 constante duplicada del aviso en el CRM (unificada).

<!-- nota: el texto siguiente era el marcador original -->
La revisión final independiente (`reviewer`) sobre el backend `6dbf485` y el CRM `3e630eb` terminó el 20-10-2026. Mientras mantenga un hallazgo bloqueante, el hito no se despliega.

### Hito 4: E2E con la pila real

**Hecho el 19-10-2026, en local. Nada de producción y ninguna llamada real a OpenAI.**

**La pila:**
- el backend, en una instantánea de `6dbf485`, con `artisan serve --no-reload` y PostgreSQL 14;
- el **worker real**, `queue:work lab --queue=studio-lab --tries=1 --timeout=150`;
- un simulador local de OpenAI, al que apunta `ULTRON_STUDIO_LAB_OPENAI_BASE_URL` (solo fuera de producción), que registra cada petición y marca como violación todo lo que incumpla el contrato;
- el CRM `3e630eb` con `ng serve`, y Chrome con login por el formulario y tres cuentas (Super Admin, Administradora y Recepción).

**Resultado final: 122/122 en 183 s** (`scratchpad/e2e-studio/h4-run.sh 6dbf485 3e630eb`, salida en `h4-run-final-out.txt`). La pasada anterior, sobre `4fbce0a` con el CRM `32c03d3` o `564cf14`, dio 121/122 por H-1, corregido en `3e630eb`.

| # | Escenario | Resultado |
|---|---|---|
| E1 | Bandera apagada: la página dice «apagado» sin error; `GET lab/status` 200 y las otras 11 rutas 409 `lab_disabled`; el simulador no recibe nada | 5/5 |
| E2 | Encendido: cabecera y presupuesto; la Administradora crea E-001 (un correo da 422 junto a su turno y no crea nada); Recepción recibe 403 y no ve el laboratorio | 17/17 |
| E3 | Estimar y ejecutar sobre la versión de la Administradora con el worker real: estimar no escribe; «Ejecutar (máx. US$ 0,008)» envía ese máximo; progreso en vivo; C-02 falla por el filtro del motor (la frase, una sola vez) y la propuesta es `reject`; 4 llamadas, todas con la clave del laboratorio | 28/28 |
| E4 | Veredicto: la autora no puede (403 `four_eyes_required`); el Super Admin emite `needs_changes`; un segundo veredicto da 409 `verdict_exists` | 9/9 |
| E5 | Cancelar en cola y en curso (el caso en vuelo termina y se cobra; el siguiente no se envía); `insufficient_quota` para tras 1 llamada; `incomplete` en todos para al segundo caso; el ensayo sin IA termina sin llamadas, sin propuesta y sin veredicto posible | 15/15 |
| E6 | Presupuesto: con el tope diario bajo, la estimación sale rechazada y `POST lab/runs` da 409; al subir los precios, `estimate_changed` obliga a reconfirmar el nuevo máximo | 8/8 |
| E7 | Texto hostil (`<img onerror>`, `<svg onload>` y un `<script>` en la respuesta del actor): literal, sin crear elementos ni abrir diálogos | 7/7 |
| E8 | Teclado (pestañas, diálogo de archivar con su aviso) y tres tamaños (1440, 820 y 390 px) sin desplazamiento horizontal | 21/21 (+1 esperado tras las correcciones) |
| E9 | Cierre: la huella de 16 tablas del motor y del Studio de F/G, de `jobs` fuera de `studio-lab` y de `failed_jobs` es idéntica; sin 5xx fuera de los reinicios del arnés; `laravel.log` sin ERROR; el simulador, 0 violaciones en 15 peticiones; la clave no aparece en respuestas, DOM, libro ni log | 12/12 |

El E2E detectó las 4 mutaciones que se le probaron sobre `4fbce0a` (`store: true`, sin cuatro ojos, el adaptador del motor sin hallazgos y la respuesta como `[innerHTML]`).

**Qué no cubre:** la pila de producción (nginx con FPM, cachés y el build de producción); el cupo de 10/h (el arnés usa `CACHE_STORE=array`); `excludedRules` con más de 20 S-NNN; la caducidad, el plazo de 30 min, los 5xx y el fallo del juez (los cubre la parte 2 del tester con la pila real); `rate_limited`, `refusal` y el timeout del job (solo las pruebas Feature); Safari, Firefox y lectores de pantalla.

### Hito 4: validación del backend por el tester sobre `6dbf485`

**Hecha el 19/20-10-2026 (de 23:40 a ~00:50 UTC)** sobre el commit `6dbf485`, con la pila real (`artisan serve --no-reload` y PostgreSQL 14), el worker real (`queue:work lab --once`) y el simulador; árbol limpio al empezar y al terminar (`scratchpad/hito-4/informe-tester-backend.md`, «Parte 2»).
- **Regresión:** Studio + Unit en PostgreSQL 14, 934/934 (0 omitidas), y 934 en SQLite (11 omitidas que solo corren en PG); el smoke de G contra su servidor, 29/29; `migrate:rollback --step=2` y `migrate` con un dueño sin superusuario: 27/24 → 11/11 → 27/24, y la pila sigue funcionando tras reimportar el catálogo.
- **Carreras con el worker real**, cada una con 10 repeticiones válidas de 10 en READ COMMITTED y otras tantas en REPEATABLE READ, todas correctas: cancelar frente al reclamo, en dos montajes (`cancelled`, un solo caso empezado, 2 llamadas); dos pasos duplicados del job (un solo caso procesado); dos veredictos a la vez (200 + 409 `verdict_exists`, una fila); y dos veredictos denegados a la vez (un solo `run.verdict_denied`). Cada carrera cae con la mutación que rompe su garantía.
- **Mutaciones:** las 14 del encargo, cazadas (entre ellas: sin cerrojo de presupuesto, `tries = 3`, juez que anula un bloqueante, sin `store: false`, leer la clave del motor, sin filtro de borradores ajenos, sin disparador terminal, el runner escribiendo en el motor, `failed()` que no cobra, la racha sin `incomplete`, sin la relectura de la cancelación y sin el corte por tope de ejecución).
- **21 casos límite, todos correctos:** el día de Bogotá con el reloj en UTC (a las 23:30 de Bogotá sigue el día 19: sin reinicio prematuro del presupuesto), la caducidad en cola presentada sin escribir y sus bordes, el plazo de 30 min, los tres topes en tiempo de ejecución (`daily`, `run` y `accepted`), un timeout HTTP real del proveedor, las rachas de errores, `auth` en el acto, `incomplete` en todos, 16 KB exactos y +1, 20 casos, más de 200 archivados y `lab-invisibles` con el precio al revés y con emojis compuestos.

**Hallazgos abiertos en `6dbf485`** (la Enmienda 5 del contrato, `403a12b`, decide su corrección, **pendiente de implementar y de verificar**):
- **H-P2-1 (producto, media-baja): ejecución huérfana.** Si un paso falla antes de reclamar un caso, la ejecución queda `running` sin ningún paso en cola: cancelar responde 200 pero no la cierra, el plazo y la caducidad no se aplican y su reserva cuenta en el presupuesto del día hasta que cambia el día de Bogotá. No gasta dinero ni bloquea otras ejecuciones. Enmienda 5: cancelar la cerrará en el acto, el plazo vencido se presentará como `stopped: deadline` y `failed()` sin caso reclamado reencolará un paso. Hasta entonces, la salida operativa (pedir la cancelación y reencolar solo ese paso) está en el runbook.
- **H-P2-2 y H-P2-3 (cobertura):** dos variantes de mutación sobreviven a PHPUnit (veredictos insertados por SQL directo desde un comando, y el «ocupado» dentro del reclamo, que solo caza la carrera real).

**No verificado por el tester:** la muerte real del worker con un caso en curso y el timeout del job de 150 s; `rate_limited`, `quota`, `bad_request`, `refusal` e `invalid_output` con la pila real (solo PHPUnit; `quota` sí está en el E2E); las carreras de creación sobre `6dbf485` (se hicieron sobre `4fbce0a`); y la suite completa.

### Hito 4: despliegue por fases

El runbook está en `ULTRON_STUDIO_DEPLOYMENT_PLAN.md` («Hito 4: runbook por fases») y la vuelta atrás en `ULTRON_STUDIO_ROLLBACK.md` («Hito 4»). **Ninguna fase se ha ejecutado.**

| Fase | Qué queda en producción | Coste | Quién decide |
|---|---|---|---|
| 1. Código desplegado con la bandera **apagada** | Migraciones, 33 rutas (las 12 nuevas en 409 salvo `lab/status`), worker `ironbody-lab` durmiendo, 11 escenarios importados y el CRM nuevo con «Laboratorio apagado» | 0 | El usuario autoriza el despliegue |
| 2. Bandera **encendida en `fake`** | El laboratorio usable en ensayo sin IA: escenarios, ejecuciones sin red, sin propuesta ni veredicto | 0 | El propietario da el visto bueno |
| 3. **OpenAI** | Ejecuciones reales con el modelo y los precios del propietario, dentro de sus topes | El que fije el propietario | Solo con la clave dedicada del propietario y sus condiciones confirmadas |

**Criterio de cierre:**
- **`HITO_4_COMPLETED`** solo con las tres fases hechas: desplegado, **una ejecución real con OpenAI validada** (el coste registrado coincide aproximadamente con el panel de uso de OpenAI) y **un veredicto de una segunda persona** sobre una versión.
- Sin la clave del propietario, el hito queda en **`HITO_4_IMPLEMENTED_WITH_EXTERNAL_DEPENDENCY`**: desplegado en la fase 1 (o en la 2) y a la espera de la clave, el proyecto, la retención, el modelo y los precios.

### Hito 4: despliegue y verificación

**Estado al 24-10-2026, antes del despliegue.** Segundo lote de correcciones en el backend: `7113f9f` (Enmienda 5: ejecuciones huérfanas, escáner de veredictos, pasos duplicados en PG) y `bc662b0` (Enmienda 6: C-11 retirado → catálogo de 11 escenarios `2026-10-20.1`, `lab-invisibles` con la clase completa menos el ZWJ, `budgetCap` expuesto, cliente elegido por el `mode` de la ejecución, `error_code` en casos saltados, aislamiento de `UltronStudioLabCancelLockTest`). CRM: `564cf14`, `3e630eb`, `e837184`, `2b6375b` (textos por tipo de tope, una sola definición del aviso) y `5c1dbd7` (fixture de 11 escenarios). Entre el 20 y el 24-10 `origin/main` recibió 19 commits ajenos en el backend (facturación multiconcepto, biometría, LocalGym) y 9 en el CRM, ya desplegados: se fusionaron en las ramas del Hito 4 (`18dae99` y `625fa6e`, un solo conflicto trivial en el orden de acciones de `PermissionCatalog`) y se volvió a verificar: Studio + Access + Chaos 1 197 en SQLite, Studio 946 en PostgreSQL 14, `auth:coverage` vacío, 33 rutas, diff del motor vacío frente al nuevo `origin/main`, catálogo de permisos (96) y 4 fixtures idénticos al CRM, módulo del CRM 742 specs. **Pérdida registrada:** la limpieza automática de `/tmp` borró el scratchpad de la sesión (informes de los agentes, arnés del E2E `h4-run.sh` con su simulador de OpenAI, smoke y runbooks). El E2E con la pila real quedó ejecutado y documentado con 122/122 sobre `6dbf485` + `3e630eb`; las correcciones posteriores se verificaron con las suites Unit/Feature/PG y los specs del CRM, y el arnés se reconstruirá como activo del repo durante el Hito 5. Los scripts operativos se recrearon en `docs/ultron-studio/ops/` (`pgunit.sh`, `smoke-h4.py`, `smoke-run-h4.sh`, `deploy-crm.sh`).

**EJECUTADO el 25-10-2026, 01:15–01:25 UTC**, por el lead, con la autorización del usuario para el despliegue progresivo (fases 1 y 2; la fase 3 queda para cuando el propietario aporte la clave).

- **Respaldo verificado:** `/var/backups/ironbody/ultron-studio-h4-20261025-011511/` (`ironbody.dump` 34 MB, 211 tablas con datos, las 5 del Studio de F/G; `app-crm.tgz` 79 MB; `env.backup` 600; `bootstrap-cache/`; `supervisor-conf.d/`; `api-commit.txt` = `d86a2b5`; `crm-main.txt` = `main-Q4EO6ZCN.js`). CRM: `/var/backups/ironbody/crm-pre-5c1dbd7-20261025-012110/`.
- **Push por fast-forward** tras fusionar `origin/main` (19 commits ajenos en el backend y 9 en el CRM, ya desplegados el 24-10): backend `origin/main` = `fef7c87` (código `18dae99`, fusión de `bc662b0` con `d86a2b5`), CRM `origin/main` = `5c1dbd7` (fusión `625fa6e` de `2b6375b` con `2a5568f`). Suites completas comparadas por nombre con `origin/main` en el mismo entorno: backend 56 fallos frente a 57 (ninguno nuevo), CRM 16 frente a 16.
- **Fase 1, backend (`docs/ultron-studio/ops/deploy-h4-backend-fase1.sh`):** variables `ULTRON_STUDIO_LAB_ENABLED=false`, `ULTRON_STUDIO_LAB_DRIVER=fake`, `ULTRON_STUDIO_LAB_DAILY_BUDGET_USD=0`, `QUEUE_STUDIO_LAB=studio-lab` añadidas antes de migrar; `ironbody-migrate` con `lock_timeout` 5 s → `2026_10_19_100002_create_ultron_studio_lab_tables` 85,01 ms y `2026_10_19_100003_add_ultron_studio_lab_guards` 26,22 ms; cachés de configuración y rutas como `www-data` 600; **27 disparadores y 24 CHECK** del Studio, 5 tablas `ultron_studio_lab_*` propiedad de `iron`; **33 rutas**; `auth:coverage --unmapped` vacío; configuración efectiva `{enabled:false, driver:fake, daily_budget:0, key_present:false, queue:studio-lab}`; worker `ironbody-lab` instalado desde git en `/etc/supervisor/conf.d/` (13 procesos RUNNING, 0 BACKOFF); `ultron-studio:lab-import-scenarios` → «11 nuevos, 0 sincronizados, 0 sin cambios, 0 retirados»; `queue:health` ve el carril `lab` al día; `status` y `lab/status` sin token → 401.
- **CRM (`ops/deploy-crm.sh 5c1dbd7 2a5568f`):** build sellado `5c1dbd7` (`dirty: false`), `main-G6TGLBLN.js`, chunk del laboratorio `chunk-YQFS7JCH.js` sin nombres del motor ni claves; `rsync` sin `--delete`; el bundle anterior sigue servido; `/marketing/ultron-studio` → 200.
- **Smoke fase 1 (`ops/smoke-run-h4.sh`, cuenta temporal admin id 32 reactivada y desactivada, sesiones borradas, token → 403): 20/20** (F/G intactos; `lab/status` 200 apagado con `can` por llaves; las 11 rutas restantes → 409 `lab_disabled`; sin clave ni rutas en las respuestas; 401 sin token).
- **Fase 2 (`ops/deploy-h4-fase2.sh on`):** `ULTRON_STUDIO_LAB_ENABLED=true` con driver `fake` y presupuesto 0; `config:cache` como `www-data`; reinicio de `ironbody-lab` y `queue:restart`; configuración efectiva `{enabled:true, driver:fake, daily_budget:0, key_present:false}`. **Smoke fase 2: 17/17** (modo `fake`, presupuesto diario 0, 11 escenarios del catálogo activos, fidelidad «No es ULTRON», estimación en `fake` a coste 0 sin crear nada, filtro de datos personales 422 sin fila).
- **Después:** `git diff HEAD` vacío; el fichero de entorno solo difiere del respaldo en las 4 variables del laboratorio; sin clave de OpenAI; 13 workers RUNNING y `ironbody-lab` RUNNING; 0 colas fallidas; 0 errores en `laravel.log`; libro del laboratorio con `system.lab_catalog_imported` 1 y `scenario.catalog_imported` 11; 0 ejecuciones. El motor no lee nada del Studio; publicar sigue en 409.

**Estado: `HITO_4_IMPLEMENTED_WITH_EXTERNAL_DEPENDENCY`.** El laboratorio está desplegado y operativo en modo de ensayo sin IA. `HITO_4_COMPLETED` exige la fase 3: el propietario crea un proyecto dedicado en OpenAI con presupuesto, confirma la retención de datos con `store:false`, fija modelo y precios oficiales, escribe `ULTRON_STUDIO_LAB_OPENAI_API_KEY`, `…_MODEL`, `…_PRICE_INPUT_USD_PER_MTOK`, `…_PRICE_OUTPUT_USD_PER_MTOK`, `…_DAILY_BUDGET_USD=2` y `ULTRON_STUDIO_LAB_DRIVER=openai` en el servidor (nunca por chat), se regenera la caché y se reinicia `ironbody-lab`; después, 2 escenarios sobre la línea base (coste real ≈ panel de uso), una versión y un veredicto de una segunda persona.
**No ejecutado.** Esta sección se rellena con la evidencia de cada fase cuando se despliegue (fecha UTC, commits, respaldo, recuentos, smoke y estado final).

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
