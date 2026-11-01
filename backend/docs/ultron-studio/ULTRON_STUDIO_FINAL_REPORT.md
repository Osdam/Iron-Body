# ULTRON STUDIO: informe de avance

**Fecha:** 17-10-2026 (se actualiza con cada fase; última actualización: 01-11-2026: Hito 4 desplegado en fases 1–2 el 25-10-2026, Hito 5 desplegado en la fase 1 el 01-11-2026 e Hito 6 implementado en rama, sin desplegar). **Rama:** `feat/ultron-studio` en el CRM (`Iron-Body_Front`) y en el backend (`Iron-Body`, solo documentación); el Hito G, en `feat/ultron-studio-g` de los dos repos; el Hito 4, en `feat/ultron-studio-h4` de los dos repos; el Hito 5, en `feat/ultron-studio-h5` de los dos repos; el Hito 6, en `feat/ultron-studio-h6` de los dos repos.

## Estado

| Hito | Estado |
|---|---|
| A. Auditoría de arquitectura | **HECHO** → `ARCHITECTURE_APPROVED_FOR_BUILD` (17-10) |
| B. Diseño del Studio (capas, modelo, grafo) | **HECHO** |
| C. Frontend visual con datos de demostración | **HECHO** → `VISUAL_IMPLEMENTATION_IN_PROGRESS` → terminado y revisado |
| D. Vista previa protegida publicada | **PUBLICADA** el 18-10-2026 a las 02:56 UTC (ver «Publicación» al final) → `ULTRON_VISUAL_PREVIEW_READY` |
| F. Persistencia (borradores, versiones con estados, reglas, aprobaciones, auditoría, permisos `ultron.*`, catálogo servido por el backend) | **DESPLEGADO Y VERIFICADO** (18-10-2026, 15:24–15:28 UTC; backend `9acddc3`, CRM `020db86`): ver «Hito F» más abajo, `ULTRON_STUDIO_BACKEND_REPORT.md` y `ULTRON_STUDIO_SECURITY_REPORT.md` |
| G. Control funcional de las diez capas (configuración real por capa, lecturas del motor, reglas con condiciones, historial y comparación) | **DESPLEGADO Y VERIFICADO** (19-10-2026 06:00–06:04 UTC; backend `e651dd9`, código `dfb30f5`; CRM `44c0e8c`). Revisiones de seguridad, de pruebas y final (APROBADO CON OBSERVACIONES, todas cerradas); E2E con la pila real 126/126; smoke en producción 29/29. Detalle en «Hito G» más abajo. **`HITO_3_COMPLETED`** |
| Hito 4. Laboratorio de IA (escenarios sintéticos, simulaciones del Studio, evaluación asistida con OpenAI y clave propia, veredicto humano) | **DESPLEGADO EN FASES 1–2** (25-10-2026, 01:15–01:25 UTC; backend `fef7c87`, código `18dae99`; CRM `5c1dbd7`): laboratorio encendido en ensayo sin IA, presupuesto 0 y sin clave. **`HITO_4_IMPLEMENTED_WITH_EXTERNAL_DEPENDENCY`**: la fase 3 espera la clave y el proyecto de OpenAI del propietario. Revisiones cerradas (`reviewer`: APROBADO CON OBSERVACIONES, cerradas en las Enmiendas 4–6); E2E con la pila real 122/122; smoke en producción 20/20 y 17/17. Detalle en «Hito 4» más abajo. *(Antes del despliegue decía «implementado y verificado en rama, no desplegado».)* |
| Hito 5. Gobierno y versionado (publicación firmada como declaración, sello por turno en el motor, canario, promoción, reversión y Legacy) | **DESPLEGADO EN LA FASE 1 (todo apagado) el 01-11-2026; fases 2 y 3 pendientes** (implementado el 24/25-10-2026; backend `96397ea`, CRM `a29bcc8`; fusionados con `origin/main` el 31-10-2026 en `cdb9dc8` y `9fe1817`, y en el backend, `cf59cef` con m-2 del reviewer). Está en `origin/main` (backend `7ba0ce8`, CRM `ab78a6a`) y en producción desde el 01-11-2026 01:12–01:16 UTC (fase 1). Revisiones de `security`, `api-security-specialist`, `tester` (backend y E2E) y DevOps hechas, sin bloqueantes; sus hallazgos medios, corregidos. Revisión final del `reviewer` (31-10-2026): **APROBADO CON OBSERVACIONES**; queda abierta I-1 (CRM) antes de la fase 2. Tras la fusión: puerta PostgreSQL 1447 OK sobre `cf59cef` y E2E con la pila real 408/408 sobre `cf59cef` + `9fe1817` (lead, 31-10-2026). [PENDIENTE: segunda pasada del reviewer sobre esta documentación.] Detalle en «Hito 5» más abajo |
| Hito 6. Integraciones comerciales (Meta Ads en lectura y asociación campaña ↔ versión, panel del OTP de Twilio y sonda GET, números canarios como registro, configuración autorizada por huellas y voz con ElevenLabs preparada sin activar) | **IMPLEMENTADO EN RAMA, PENDIENTE DE E2E, REVIEWER Y DESPLIEGUE** (31-10/01-11-2026; `feat/ultron-studio-h6`, backend `e3a0fdd`; en el CRM, la capa de datos en `ede716b` y la página «Integraciones», terminada y verificada en `f38699d`: 1096/1096 y build en verde). Sin empujar ni desplegar. El backend está completo y revisado sin bloqueantes por `security` y `api-security-specialist` (Enmiendas 3 y 4); el tester de backend, 45/45 carreras en PostgreSQL; las puertas finales del lead sobre `e3a0fdd`, SQLite 2879 OK y PostgreSQL 2455 OK. Clasificación real: Meta Ads conectada en lectura, Twilio solo para OTP, Twilio Voice no configurada, canarios como registro y ElevenLabs `ELEVENLABS_NOT_CONFIGURED`. Detalle en «Hito 6» más abajo |
| Siguientes (rotuladas «G–R» antes de que G pasara a ser el control funcional): Meta, Twilio, canarios, ElevenLabs, vínculo versión↔conversación (el laboratorio y la evaluación con IA son el Hito 4; el sello de la versión declarada en cada turno, el Hito 5) | **NO EMPEZADOS** (autorizados como construcción, no como activación). *(Actualización del 01-11-2026: Meta, Twilio, canarios y ElevenLabs son el Hito 6, en la fila de arriba. Las llamadas, T2 y T3 siguen sin empezar.)* |

## Qué hay (clasificación honesta)

*Clasificación del 17-10-2026, de la vista previa visual. Lo implementado después está en las secciones de F, de G, del Hito 4, del Hito 5 y del Hito 6 (la publicación, el canario y la reversión de versiones, que abajo figuran como no implementados, existen desde el Hito 5, desplegado en la fase 1 el 01-11-2026; las campañas Meta, el control de Twilio, los números canarios en tabla y ElevenLabs existen desde el Hito 6 en la rama `feat/ultron-studio-h6`, sin desplegar, con su clasificación honesta en «Hito 6»).*

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

El runbook está en `ULTRON_STUDIO_DEPLOYMENT_PLAN.md` («Hito 4: runbook por fases») y la vuelta atrás en `ULTRON_STUDIO_ROLLBACK.md` («Hito 4»). **Fase 1 ejecutada el 01-11-2026 (evidencia más abajo); fases 2 y 3 pendientes.** *(Así se escribió. **Actualización del 31-10-2026:** las fases 1 y 2 se ejecutaron el 25-10-2026; la 3, no. Ver «Hito 4: despliegue y verificación».)*

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
- **Después:** `git diff HEAD` vacío; el fichero de entorno solo difiere del respaldo en las 4 variables del laboratorio; sin clave de OpenAI; 13 workers RUNNING y `ironbody-lab` RUNNING; 0 colas fallidas (anotación del 31-10-2026: ese recuento se hizo con `queue:failed`, que en Laravel 12 ya no pinta `| id |` y daba siempre 0, así que no probaba nada; desde el Hito 5 se cuenta por SQL, D-2 del informe de DevOps); 0 errores en `laravel.log`; libro del laboratorio con `system.lab_catalog_imported` 1 y `scenario.catalog_imported` 11; 0 ejecuciones. El motor no lee nada del Studio; publicar sigue en 409.

**Estado: `HITO_4_IMPLEMENTED_WITH_EXTERNAL_DEPENDENCY`.** El laboratorio está desplegado y operativo en modo de ensayo sin IA. `HITO_4_COMPLETED` exige la fase 3: el propietario crea un proyecto dedicado en OpenAI con presupuesto, confirma la retención de datos con `store:false`, fija modelo y precios oficiales, escribe `ULTRON_STUDIO_LAB_OPENAI_API_KEY`, `…_MODEL`, `…_PRICE_INPUT_USD_PER_MTOK`, `…_PRICE_OUTPUT_USD_PER_MTOK`, `…_DAILY_BUDGET_USD=2` y `ULTRON_STUDIO_LAB_DRIVER=openai` en el servidor (nunca por chat), se regenera la caché y se reinicia `ironbody-lab`; después, 2 escenarios sobre la línea base (coste real ≈ panel de uso), una versión y un veredicto de una segunda persona.
*(Marcador anterior al despliegue, obsoleto desde el 25-10-2026: «**No ejecutado.** Esta sección se rellena con la evidencia de cada fase cuando se despliegue». La evidencia de las fases 1 y 2 es la de arriba; la fase 3 no se ha ejecutado.)*

## Hito 5: gobierno y versionado (24/25-10-2026, implementado en rama; fusionado con origin/main el 31-10-2026; pendiente de despliegue)

**Resumen ejecutivo.** Hasta el Hito 4, una versión aprobada no salía del Studio: publicar respondía 409 siempre. El Hito 5 cierra el ciclo (borrador → validación → laboratorio → revisión → aprobación → canario → publicación) sin tocar al agente:
- **publicar** convierte la versión aprobada en la **declaración oficial y firmada** de cómo está configurado ULTRON: una entrada nueva en una cadena de solo inserción, firmada con una subclave derivada de la `APP_KEY`;
- el **motor** lee la cabeza de esa cadena con una sola clase de solo lectura, detrás de su propia llave de entorno (`off` por defecto), y **anota en cada turno** qué declaración regía (`marketing_ai_actions.metadata.studio`);
- toda publicación empieza en el **canario** (la conversación canaria del motor) y solo se **promueve** a todas con evidencia medida en el servidor: 24 horas, 3 turnos sellados con esa versión, ningún freno, el acta del canario a cero y sin deriva;
- **revertir** y **volver a Legacy** son inmediatos (una entrada nueva; el lector no tiene caché) y no dependen de las llaves que permiten publicar.

**El hecho incómodo (opción A del contrato): publicar no cambia lo que ULTRON dice ni hace.** De los 21 parámetros del catálogo, los 4 con mando real son banderas de entorno del propietario, y la versión tiene que declarar exactamente lo que lee hoy el motor para poder publicarse (`must_match`): una declaración que contradijera al motor sería falsa. El resto se reconoce de forma explícita como «sin efecto», y las reglas S-NNN siguen sin aplicarse. Lo que el Studio sí hace es vigilar la **deriva** entre lo declarado y lo que el motor tiene de verdad.

**Dónde está:** la rama `feat/ultron-studio-h5` de los dos repos. **Está en `origin/main` (backend `7ba0ce8`, CRM `ab78a6a`) y en producción desde el 01-11-2026 01:12–01:16 UTC (fase 1).** El 31-10-2026 se fusionó en las dos ramas el `origin/main` de ese día: backend `7318122` (fusión `cdb9dc8`) y CRM `4105016` (fusión `9fe1817`); las dos fusiones solo traen trabajo de otros módulos (caja, pagos, biometría, miembros, planes e IRON IA) y no tocan el código del Studio (en el CRM cambian el catálogo de permisos y su enumeración, fuera del módulo). Encima, en el backend, `cf59cef` corrige m-2 del reviewer y fija `PREVIO=7318122` en el script de la fase 1. Contrato: `ULTRON_STUDIO_H5_CONTRACT.md` (A1–A13 y Enmiendas 1 y 2). Plan del architect: `plans/ULTRON_STUDIO_H5_PLAN.md`. Informes de los agentes y decisiones del lead: `plans/informes-h5/`.

| Repo | Commit | Qué |
|---|---|---|
| Backend | `3735b58` | Hoja de ruta: Hito 5 en desarrollo |
| | `f4d64b8` | Migraciones: la cadena de publicaciones y sus guardas (`database-specialist`) |
| | `02d1cf5` | Fixture de publicación (231 casos escritos a mano) y Enmienda 1 |
| | `ea04d3f` | El lector del motor con el sello por turno y el núcleo puro de publicación |
| | `044f70b` | Scripts de operación (fase 1 y alcance del motor) |
| | `5df1b1d` | m-3 del Hito 4: umbral de desatención por carril |
| | `2bc053b` | La API de publicación: 38 rutas; el motor ya lee versiones |
| | `61048ff` | La fase 1 escribe explícitas las variables A13; decisiones del lead del paso 3 |
| | `d98bbf9` | Informe del paso 4 (CRM), con capturas |
| | `d24c512` | Revisión de seguridad: la llave de publicar solo se enciende con `true` (M-1) y scripts endurecidos (M-2, M-3, B-1, N-5, N-7, N-8) |
| | `602c4b9` | Revisión de la API: promover comparte el limitador de la evidencia (AS-2) |
| | `5772bdf` | Scripts reconstruidos sobre las propuestas de DevOps, smoke de la fase 1 e informes de las revisiones |
| | `96397ea` | H-1 del tester (el reintento recibe 200 `replayed`), carreras reales en PostgreSQL, pruebas de cobertura y Enmienda 2 |
| | `cdb9dc8` | Fusión de `origin/main` (`7318122`), 31-10-2026 |
| | `cf59cef` | m-2 del reviewer: un contenido que el catálogo actual no sabe leer se rechaza con el hallazgo `contenido-ilegible` (con su prueba), y la fase 1 parte de `PREVIO=7318122`; 31-10-2026 |
| | `db1d8f6` | Revisión final del reviewer (APROBADO CON OBSERVACIONES) y cierre de I-2, I-3, m-3 a m-6 y m-8 a m-10 en los scripts y la documentación de apoyo; 31-10-2026 |
| | `940b0c2` | La comprobación de privilegios de la cadena enseña también UPDATE y DELETE de `iron_app`, como información; 31-10-2026 |
| CRM | `3989f90` | Arnés E2E con la pila real como activo del repo (A10.5) |
| | `9b70337` | Capa de datos de la publicación y arreglo de la carrera de «Validar en el servidor» (A10.3) |
| | `e7ba41b` | Integración de la publicación en el CRM (A11) |
| | `33821e4` | Vista previa con las formas reales del paso 3 (puerta dispensada y bloqueos sin fila) |
| | `24a691d` | D-1 (`publication_head_invalid`) y paridad con las formas exportadas por el backend |
| | `a29bcc8` | E2E del Hito 5 con la pila real (`h5-governance`, 276 comprobaciones) y arreglos del arnés |
| | `9fe1817` | Fusión de `origin/main` (`4105016`), 31-10-2026 |

**IMPLEMENTADO Y PROBADO** (en rama):
- **La cadena de publicaciones** (`ultron_studio_publications`): de solo inserción (disparadores en PostgreSQL y SQLite, y el modelo), con `seq` único (una carrera la gana una sola escritura), firma HMAC sobre los bytes exactos que lee el motor (contrato `ultron-engine-config/1`) e idempotencia por `clientRequestId`, atada a persona, acción, versión y cuerpo.
- **La máquina de estados de F más H5:** `APPROVED → CANARY → PUBLISHED`, con `ROLLED_BACK` y `RETIRED` («Sustituida»). Como mucho una versión en `CANARY` y una en `PUBLISHED` (índices únicos parciales en la base), y la cabeza de la cadena coincide con ellas.
- **Las cuatro escrituras y sus puertas (A5):** publicar (siempre en canario), promover, revertir y volver a Legacy. Publicar y promover exigen cuatro ojos por identidad (nadie publica ni promueve lo que creó), la ventana de 07:00 a 19:00 de Bogotá y el freno suelto; publicar exige además la puerta del laboratorio, los 4 `must_match`, el reconocimiento exacto de lo «sin efecto» y la misma vista previa que vio la persona (`previewHash`).
- **El lector del motor** (`UltronPublishedConfig`): una sentencia por commit, solo con su llave distinta de `off`; nunca lanza (cualquier fallo sale como `fallback` y el turno sigue) y sella todas las filas que crea el commit. Con `off`, el motor es Legacy exacto: ninguna consulta y ningún sello.
- **La evidencia del canario** (A6), calculada en el servidor con su propio limitador, y el comando `ultron-studio:publication-check`, que sale con 1 ante deriva, una cabeza que no verifica o una lectura fallida.
- **Lecturas:** la vista previa (`publication-preview`), el historial paginado (`publications`), `engine.publication` en `status` y la lectura `governance.studioPublication`.
- **Heredados que absorbe** (A10): `integrityOk` por lado en `compare` (I-2 de G), las reglas archivadas paginadas (I-5 de G), la carrera de «Validar en el servidor» (CRM), el umbral de desatención por carril (m-3 del Hito 4) y READ COMMITTED en `StudioAudit::recordDenied`.
- **CRM:** botones según `actions` del servidor, los diálogos de publicar (con la vista previa) y de promover (con la evidencia), la barra de la publicación con Legacy y el historial, la insignia «Huella alterada» al comparar y el texto obligatorio de A11.
- **El arnés E2E con la pila real**, como activo del repo (`Iron-Body_Front/e2e/ultron-studio/`), con un simulador de n8n y turnos reales firmados.
- **Correcciones de las revisiones:** M-1/AS-1 (`d24c512`), AS-2 (`602c4b9`), H-1 del tester (`96397ea`) y los scripts de operación endurecidos (M-2, M-3, B-1, N-5, N-7 y N-8).

**QUÉ NO HACE** (por diseño):
- **No cambia lo que ULTRON dice ni hace.** No enciende ni apaga ULTRON, no mueve el canario ni toca el cobro, la pausa, el freno, los planes, la base de conocimiento ni n8n. Ninguna regla S-NNN se aplica.
- **El motor lee una tabla del Studio, con una clase y una sentencia por commit, y solo con `MARKETING_ULTRON_STUDIO_CONFIG` distinta de `off`.** No escribe en `ultron_studio_*` ni importa clases del Studio, y el Studio no escribe en el motor. El diff del motor frente a `origin/main` son 3 ficheros (`UltronPublishedConfig.php`, nuevo; `UltronCommitService.php` y `config/marketing.php`), comprobado el 31-10-2026 frente a `7318122`.
- **El Studio no frena ni revierte solo** por deriva o por un sello que falta: lo enseña, bloquea la promoción y `publication-check` sale con 1. El incidente de IRON GUARD por deriva es del Hito 7.
- **Sin llamadas, sin Meta, sin ElevenLabs y sin gasto.**
- **Diferido al Hito 7** (Enmienda 2 §E2.5): marcar en el historial las entradas que no verifican (B-2), el `seq` envenenado con DML (AS-3/N-3) y `CHECK (seq >= 1)`, el envoltorio de migración que corre como root y los caracteres invisibles que acaban en la cadena (AS-5).

**Evidencia.** Cada cifra lleva su informe. **Las de la lista son anteriores a la fusión del 31-10-2026 y a `cf59cef`.** Tras la fusión, el reviewer comprobó (31-10-2026, `informe-reviewer.md`): en el backend `cf59cef`, 103/103 pruebas en SQLite (`UltronStudioPublicationLifecycleTest`, `UltronStudioPublicationRollbackTest` y `UltronEngineIsolationTest`), 9/9 carreras en PostgreSQL, 38 rutas, `auth:coverage --unmapped` vacío, las 5 exportaciones idénticas a las del CRM y `permissions:export` idéntico al catálogo del CRM `9fe1817`, con **101 permisos** (96 en el hito; la fusión añade `cash.products.refund`, `members.transfer_membership`, `payments.correct`, `payments.refund` y `plans.archive`); en el CRM `9fe1817`, módulo 878/878, `tsc` y `check:literals` limpios, y la suite completa con 3131 pruebas y los mismos 16 fallos de la línea base. **Las puertas completas, del lead (31-10-2026), tras la fusión:** Studio + Access + Chaos en SQLite, **1697 OK** (56 omitidas, las de solo PostgreSQL) sobre `cdb9dc8`; la puerta PostgreSQL, **1446 OK** sobre `cdb9dc8` y **1447 OK** sobre `cf59cef`, con las 38 carreras; el Studio en SQLite, **1447 OK** sobre `cf59cef`; Marketing (Feature + Unit), **4977 OK**; `auth:coverage --unmapped` vacío y **38 rutas**. Y el **E2E de cierre con la pila real, 408/408** sobre `cf59cef` + `9fe1817` (ver abajo). [PENDIENTE: el build de producción del CRM sobre el commit final, que llevará la corrección de I-1 (lead).]
- **Backend:**
  - API (`informe-api.md` §0 y §6, verificado de primera mano por el lead): Studio + Unit + Access + Chaos en SQLite, 1654 pruebas y 20 038 aserciones, con 18 omitidas que solo corren en PostgreSQL; Studio + Unit en PostgreSQL 14, 1403/1403; Marketing, 4977/4977; `auth:coverage --unmapped` vacío; 38 rutas; `permissions:export` idéntico al catálogo del CRM (96 permisos, sha256 `aeb9699c…`); 15 mutaciones, todas con el resultado esperado.
  - Motor (`informe-motor.md`): las suites de ULTRON, 4977/4977 con la llave en `off` y otra vez en `all`; la prueba del lector, 217/217 en SQLite y en PostgreSQL; los 87 casos del fixture de firma y alcance; 12 mutaciones cazadas.
  - Núcleo (`informe-nucleo.md`): paridad estricta con el fixture (classify 34/34, effectiveJson 11/11, compose 10/10, evidence 18/18 y las puertas puras 30/30); 53 mutaciones cazadas.
  - Migraciones (`informe-db.md`): en PostgreSQL 14.24, de 27 a 30 disparadores y de 24 a 27 CHECK; en SQLite, de 18 a 20 disparadores. `migrate:rollback --step=2` deja el esquema idéntico, `down()` se niega con datos de H5 y un rol dueño sin superusuario migra sin ningún GRANT nuevo.
  - Fixture (`informe-fixture.md`): 231 casos escritos a mano desde el contrato; una segunda derivación independiente da 0 discrepancias y 29 implementaciones erróneas lo tumban.
- **CRM** (`informe-front-integracion.md`): módulo 878/878 en `24a691d`; suite completa de 2920 pruebas con los mismos 16 fallos de la línea base, nombre a nombre; `tsc` y `check:literals` limpios; build de producción sin avisos de las hojas nuevas; 18 mutaciones, todas tumbadas; Chrome con la API simulada, 71/71 a 1440 y 390 px; `ultron-studio.scss` sin un byte más (sha256 `fc558002…`).
- **E2E con la pila real:** **404/408** en la ejecución definitiva sobre `2bc053b` + `e7ba41b`, y 405/408 con el CRM `33821e4` (ver abajo).

**Decisiones del lead** (contrato, Enmiendas 1 y 2 y `decisiones-lead-h5.md`):
1. **Opción A:** declaración verificada y sello por turno. Un «apagado» aplicado (opción B) queda para un contrato futuro `ultron-engine-config/2`, si el propietario lo pide.
2. **Puerta del laboratorio `required` por defecto**: hace falta un veredicto `approved` sobre la misma huella y con la versión de prompts vigente. El propietario puede poner `waived` en el entorno; queda escrito en cada publicación. Camino recomendado: cerrar la fase 3 del Hito 4 antes de la fase 2 de este hito.
3. **Canario obligatorio:** 24 horas y 3 turnos sellados por defecto, configurables con mínimos duros de 1 hora y 1 turno.
4. **Criterio de cierre:** `HITO_5_COMPLETED` solo con las fases 1–3 ejecutadas y su evidencia; si lo impide una dependencia externa, `HITO_5_IMPLEMENTED_WITH_EXTERNAL_DEPENDENCY`.
5. **`RETIRED` se reutiliza** con la etiqueta «Sustituida»; no se añade `SUPERSEDED`.
6. **Dos personas bastan:** quien publica tiene que ser distinto del autor, no también de quien aprobó.
7. **Ventana de 07:00 a 19:00 (Bogotá)** para publicar y promover; revertir y Legacy, siempre.
8. **Revertir no depende de `ULTRON_STUDIO_PUBLISHING_ENABLED`:** deshacer no puede depender de la llave que permite cambiar.
9. **READ COMMITTED en `StudioAudit::recordDenied`**, con su prueba en PostgreSQL (pendiente de F).
10. **Estado del motor** leído por el lead en producción (25-10-2026, 02:05 UTC, solo lectura): ULTRON encendido, cobro apagado, canario de conversación y canario del cobro configurados, driver `openai` y 3 turnos de ULTRON en 7 días. La primera versión publicable declarará `governance.enabled: true`, `governance.canaryOnly: true`, `rules.paymentLinksEnabled: false` e `intent.driver: entorno`.
- **Enmienda 1:** la subclave de firma (Q-1: la `APP_KEY` es la clave del HMAC y la etiqueta, el dato); revertir la publicada con otra versión en canario conserva el canario (Q-2); al promover, la parte se copia igual (Q-3); qué entra en `previewHash` (Q-4); y una lectura caída cuenta como deriva (Q-5).
- **Enmienda 2:** el 409 nuevo `publication_head_invalid` (D-1), las formas de la cadena (D-2 a D-5), qué rechazos se auditan (D-6), el 422 antes que las llaves (D-13), la ventana (D-18), la paginación (AS-8), M-1 y AS-2, las decisiones del CRM (D-F1 a D-F9), la corrección del riesgo R6 del plan sobre la rotación de la `APP_KEY` (B-3) y lo diferido al Hito 7.
- **Durante la implementación:** H-1 del tester, corregido por el lead; B-2 y AS-3, diferidos al Hito 7; `deploy-h4-backend-fase1.sh`, marcado obsoleto; y dos ajustes de pruebas de F y G (la sonda de inmutabilidad pasa de `PUBLISHED` a `VALIDATING`, porque el CHECK de 9 estados admite `PUBLISHED`, y el umbral de `UltronStudioHardeningTest` sube de 50 a 150 ms, porque fallaba también sobre la base).

### Hito 5: revisiones de seguridad y de la API

Detalle en `ULTRON_STUDIO_SECURITY_REPORT.md` («Hito 5»).
- **`security`** (sobre el backend `61048ff` y el CRM `24a691d`): sin bloqueantes ni altos. M-1 (la llave de publicar se abría con `off`), corregido en `d24c512`; M-2 (el script de la fase 1 no pasaba de la línea 11) y B-1 (añadir al fichero de entorno sin salto final), corregidos en los scripts; M-3 (git como root sobre un `.git` de `www-data`), primero con `git` como `www-data` y, desde el 31-10-2026, resuelto de otra forma: el árbol y `.git` resultaron ser de root desde el 25-10 (I-3 del reviewer) y los scripts ejecutan `git` como root con `umask 022`; B-2 (el historial no verifica cada entrada), diferido al Hito 7; B-3 (la rotación de la `APP_KEY`), llevado al runbook.
- **`api-security-specialist`** (sobre `d98bbf9`): sin bloqueantes ni altos. AS-1 (= M-1) y AS-2 (promover sin el limitador de la evidencia), corregidos; AS-3 (`seq` envenenado con DML deja Legacy en 500), diferido al Hito 7 con su salida operativa en el runbook; AS-4 (= H-1 del tester), corregido; AS-5 a AS-8, notas.

### Hito 5: validación del backend por el tester

**Hecha el 25-10-2026** sobre `61048ff`/`d98bbf9`, con dos sesiones reales de PostgreSQL 14 por carrera (`informe-tester-backend.md`).
- **38 carreras:** 29 verdes desde el principio (20 publicaciones a la vez, la «tormenta» de 19 transacciones abortadas por el 23505, promover contra revertir, publicar contra Legacy, `recordDenied` en REPEATABLE READ…) y 9 rojas, todas de **H-1** (media, de contrato): el reintento de la misma petición que cruzaba la confirmación de la primera recibía 409 en vez de 200 `replayed`, en las cuatro escrituras. Sin daño a datos. **Corregido por el lead en `96397ea`:** las 38 pasan contra el arreglo (`decisiones-lead-h5.md`). En ninguna carrera una transacción abortada dejó nada a medias, y con todas las sesiones en REPEATABLE READ el resultado fue el mismo.
- **Suite completa en SQLite frente a `origin/main` (`0366009`), el mismo día:** 10 841 pruebas frente a 10 137, con 57 rojos de nombres idénticos en los dos lados (55 en `tests/baseline-failures.txt` y 2 del entorno) y **0 nuevos**. En PostgreSQL: Studio, 1441 pruebas con 9 rojas, las de H-1; Marketing, los mismos 10 rojos por nombre en los dos lados.
- **Mutaciones:** 22; 19 caen y 3 sobreviven (C2, I1 y J1: huecos de cobertura de severidad baja, B-1 a B-3), que `96397ea` cierra con tres pruebas nuevas.
- **No verificado por el tester:** un servidor web real entre las sesiones (lo cubre el E2E), el rol `iron_app` de producción (lo comprueba el script de la fase 1) y SERIALIZABLE.

### Hito 5: E2E con la pila real

**Hecho por el tester el 25-10-2026, de 14:45 a 17:00 UTC, en local. Nada de producción** (`informe-tester-e2e.md`). La pila: instantánea del backend con 315 migraciones y PostgreSQL 14 desechable; `artisan serve` y los tres workers reales (laboratorio, WhatsApp y comercial); simuladores de OpenAI y de n8n; una valla de red; `ng serve`; Chrome con login real. Los turnos son reales: entrante firmado como Meta → worker de WhatsApp → worker comercial → simulador de n8n → `ai/decide` → `ai/commit`.

| Ejecución | Refs | Resultado |
|---|---|---|
| R1″, definitiva | backend `2bc053b` + CRM `e7ba41b` | **404/408**: F 40/40, G 25/25, H4 27/27, turno 30/30, `h5-governance` 272/276 y cierre 10/10 |
| R4 | `2bc053b` + `33821e4` | 405/408 |
| R2, línea base | `0366009` + `5c1dbd7` | 131/131 |
| R3, el CRM nuevo con el backend anterior | `0366009` + `e7ba41b` | 131/131: desplegar el CRM antes que el backend no rompe F, G ni H4 |
| Mutaciones (`mutations.sh`) | `2bc053b` + `e7ba41b` | 9/9 tumbadas |
| **Pasada de cierre** (lead, 31-10-2026, 21:26–21:30 UTC) | `cf59cef` + `9fe1817` | **408/408**: F 40, G 25, H4 27, turno 30, `h5-governance` 276 y cierre 10, en 257 s |

- **Los escenarios del encargo, en verde:** sellos `applied`, `legacy` y `out_of_scope` en turnos reales; la evidencia del canario; publicar, promover, revertir y Legacy desde el CRM; cuatro ojos y el Administrador sin botones; la deriva con `publication-check` a 1; un oráculo independiente de la firma (7/7 entradas); y 230 respuestas barridas sin el id del canario ni firmas.
- **Los 4 rojos de R1″:** 3 de **B-1** (= M-1/AS-1: `ULTRON_STUDIO_PUBLISHING_ENABLED=off|no|disabled` abría publicar), corregido en `d24c512`; y **C-1** (el CRM decía dos veces que la puerta del laboratorio estaba dispensada), corregido en `33821e4`.
- **La pasada de cierre del lead** (31-10-2026, 21:26–21:30 UTC, sobre `cf59cef` + `9fe1817`) da **408/408**, con las comprobaciones de B-1 ya en verde: sin 5xx en 465 peticiones del CRM, `laravel.log` sin ERROR, colas vacías, 0 violaciones de los simuladores de OpenAI y de n8n, la valla de red vacía y ninguno de los 4 secretos de la ejecución en 19 registros ni en 9 tablas. Es la mejor pasada registrada del arnés.
- **El arnés:** 6 defectos propios, corregidos (`mutations.sh` no había corrido nunca), y la huella de 17 tablas del motor y de las colas, comprobada.
- **Desviaciones documentadas:** un veredicto `approved` no existe en `fake` (Hito 4, §A6), así que la puerta `required` se cerró con una ejecución `openai` contra el simulador; y, con el canario del motor puesto, las demás conversaciones no llegan al commit (O-3), así que `rest` y `out_of_scope` solo se ven quitando el canario del entorno de la pila desechable.
- **Qué no cubre:** revertir una `PUBLISHED` que restaura otra `RETIRED`, `restore_integrity_mismatch` y `publication_head_invalid` provocados (lo cubre PHPUnit), las carreras de dos sesiones (las cubre el tester de backend), el 429 de la evidencia (`CACHE_STORE=array`), la pila de producción con `config:cache`, un p95 del commit con muestra suficiente y el teclado y los 390 px de los diálogos nuevos (los probó el CRM con la API simulada).

### Hito 5: operación (DevOps)

**Hecho el 25-10-2026, todo en local**, sin SSH ni peticiones a producción (`informe-devops-h5.md`).
- **Smoke de solo lectura** para las tres fases (`ops/smoke-h5.py` y `ops/smoke-run-h5.sh`). Contra la API real en local: fase 1, 35 + 14 OK (36 + 14 con una versión aprobada); fases 2 y 3, 34 + 14 cada una; las 217 tablas de la base, idénticas antes y después. Se niega a correr con la configuración de otra fase. El detector de fugas pasa 22/22 casos sin red.
- **Hallazgos en los scripts:** D-1 (bloqueante, = M-2: el script de la fase 1 no pasaba de la línea 11) y D-2 (el recuento de colas fallidas por `queue:failed` daba siempre 0 en Laravel 12, también en el registro del despliegue del Hito 4), más D-3 a D-12. Todos corregidos en los scripts que reconstruyó el lead (`5772bdf`). DevOps ensayó sus propuestas en un banco local sobre `fef7c87`: el ensayo, el despliegue, la reanudación y tres negativas. El 31-10-2026 el lead aplicó a los scripts las observaciones de operación del reviewer (I-2, I-3 y m-3 a m-6) en `db1d8f6` y `940b0c2`.
- **Ensayado en local:** la rotación de la `APP_KEY` con la cadena viva (`fallback` → revertir da 409 `publication_head_invalid` → Legacy 201 → `publication-check` 0) y la vuelta del código a `fef7c87` con datos de H5 (smoke de H4 17/17, 33 rutas y las tablas en su sitio).
- **Runbook:** `ULTRON_STUDIO_DEPLOYMENT_PLAN.md` («Hito 5») y `ULTRON_STUDIO_ROLLBACK.md` («Hito 5»).

### Hito 5: veredicto del reviewer

**APROBADO CON OBSERVACIONES** (31-10-2026, hacia las 21:30 UTC, sobre el backend `cf59cef` y el CRM `9fe1817`; `plans/informes-h5/informe-reviewer.md`, confirmado en `db1d8f6`). Sin bloqueantes en el código del backend ni del CRM: cumplen los criterios 1 a 6 y 9 en todo lo que pudo ejecutar; los scripts (criterio 7) y la documentación (criterio 8) quedaron parciales, con estas observaciones:
- **Antes de la fase 1, cerradas por el lead el 31-10-2026:** I-2 (un tercer argumento mal escrito convertía el ensayo en el despliegue real) e I-3 (el `merge` como `www-data` habría fallado a medias: el árbol y `.git` son de root, y `git` va ahora como root con `umask 022` y comprobación de lectura por `www-data`), en los scripts (`db1d8f6`); e I-4 (la vuelta atrás del código apuntaba a `fef7c87` y habría deshecho en producción el trabajo de otros módulos), corregida en `ULTRON_STUDIO_ROLLBACK.md`: el destino se lee de `api-commit.txt`.
- **Antes de la fase 2: I-1 (CRM).** En la misma pestaña, un segundo «Volver a Legacy» con el mismo motivo reenvía la misma `clientRequestId` y recibe la entrada vieja: si entretanto otra persona publicó, la salida no se ejecuta y la barra dice que ya estaba en Legacy. No afecta a la fase 1. Lo está corrigiendo `frontend` (31-10-2026), previsiblemente antes del build de la fase 1. **Corregido** en el CRM `ab78a6a` (31-10-2026, `frontend`): la clave de idempotencia se olvida tras cualquier respuesta definitiva (2xx legible, 4xx) y se conserva solo tras 429, 5xx, sin red o 2xx ilegible; 31 specs nuevas que fallaban sin la corrección; `ab78a6a` es el build desplegado en la fase 1.
- **Menores:** m-1, decidida (Enmienda 2 §E2.2: `true`, `1`, `on` o `yes` encienden la llave de publicar); m-2, cerrada en `cf59cef`; m-3 a m-6, m-9 y m-10, cerradas por el lead en `db1d8f6`; m-8, cerrada en `.env.example` por el lead y en `ops/README.md` y el runbook por esta documentación, igual que m-7.
- **Segunda pasada del reviewer sobre la documentación del Hito 5 (31-10-2026, `informe-reviewer.md`, «Segunda pasada»): APROBADO CON OBSERVACIONES**, sin bloqueantes; D2-1 (la vuelta atrás del código comprueba que producción sigue en el commit de la fase 1), D2-2 (`h5-schema-check.sql` por la entrada estándar), D2-3 (`ironbody-migrate` y la caché de www-data), D2-4 (+49/−9) y D2-5 (N-2 comprobado) cerrados en `7ba0ce8`.

Mientras mantenga un hallazgo bloqueante, el hito no se despliega.

### Hito 5: despliegue por fases

El runbook está en `ULTRON_STUDIO_DEPLOYMENT_PLAN.md` («Hito 5: runbook por fases») y la vuelta atrás, en `ULTRON_STUDIO_ROLLBACK.md` («Hito 5»). **Fase 1 ejecutada el 01-11-2026 (evidencia más abajo); fases 2 y 3 pendientes.**

| Fase | Qué queda en producción | Coste | Quién decide |
|---|---|---|---|
| 1. Código desplegado con todo apagado | 38 rutas, la cadena vacía y sus guardas, las 7 variables apagadas y el CRM nuevo. El motor no consulta la cadena y publicar responde 409 | 0 | El usuario autoriza el despliegue |
| 2. Motor en `canary` | El motor sella los turnos de la conversación canaria y se puede publicar, siempre en canario. La primera publicación es una versión que refleja el motor tal como está | 0 | El propietario, con su decisión escrita sobre la puerta del laboratorio |
| 3. Motor en `all` y promoción | La versión del canario pasa a regir en todas las conversaciones, con la evidencia del canario suficiente | 0 | El propietario |

- **La puerta del laboratorio, antes de la fase 2.** Con `required`, publicar exige un veredicto `approved`, y un veredicto solo existe sobre una ejecución del laboratorio en modo `openai`. Mientras el laboratorio siga en `fake` y sin clave (el 31-10-2026), toda publicación responde 409 `lab_verdict_required`. Las salidas: cerrar antes la fase 3 del Hito 4 o que el propietario decida `waived` por escrito. Con `waived`, cada publicación lo lleva escrito en la cadena (`lab_gate.mode = waived`) y el CRM lo pinta en rojo. **Decisión retirada (01-11-2026).** El 31-10 el lead había previsto arrancar la fase 2 con `ULTRON_STUDIO_PUBLISH_LAB_GATE=waived` al amparo de la autorización del 25-10. El 01-11-2026 el propietario pidió expresamente respetar la puerta del laboratorio y no conceder excepciones por cuenta del lead, así que **la puerta sigue en `required`** y la primera publicación queda a la espera de UNA de estas dos decisiones del propietario: (a) cerrar la fase 3 del Hito 4 (clave y proyecto de OpenAI en el servidor, una ejecución real del laboratorio sobre la versión y un veredicto `approved` de una segunda persona), o (b) escribir él mismo `waived` en el entorno (procedimiento en el runbook, fase 2), que queda registrado en cada publicación. Con `required` y el laboratorio en `fake`, publicar responde 409 `lab_verdict_required`: es el comportamiento correcto.
- **Poco tráfico.** Con unos 3 turnos de ULTRON por semana (decisión 10 del contrato), la evidencia del canario tardará días en reunirse, y sin turnos no hay promoción: es lo correcto.
- **Criterio de cierre:** **`HITO_5_COMPLETED`** solo con las tres fases y su evidencia; si lo impide una dependencia externa (autorización, tráfico del canario o puerta del laboratorio), **`HITO_5_IMPLEMENTED_WITH_EXTERNAL_DEPENDENCY`**.

### Hito 5: despliegue y verificación

**Fase 1 EJECUTADA el 01-11-2026, 01:12–01:16 UTC (20:12–20:16 de Bogotá), por el lead con la autorización del 25-10-2026.**
- Respaldo: `/var/backups/ironbody/ultron-studio-h5-20261101-011245/` (222 tablas con datos, `ironbody.dump` 38 MB, `api-commit.txt` = `7318122`, modos 700/600); el anterior de las 21:56 UTC del 31-10 quedó fuera del plazo de 2 h y se rehízo.
- Ensayo `--solo-comprobar` con el commit publicado: «COMPROBACIONES PREVIAS EN VERDE» (HEAD `7318122` limpio, esquema 27/24 de `iron`, sin cerrojos, 7 claves ausentes, colas fallidas 2).
- Backend: `bash /root/deploy-h5-backend-fase1.sh 7ba0ce8 $BK` (registro en `/root/h5-fase1-20261101-011404.log`): HEAD `7ba0ce8`; los 141 ficheros del merge legibles por `www-data`; 7 claves añadidas (fichero de entorno `www-data 600`, parsea); migraciones `2026_10_25_100001` (62,80 ms) y `100002` (24,04 ms) DONE; cachés `www-data 600`; SQL `30 27 3 3 2 true iron iron 0`, huella de `ultron_studio_forbid` igual (`4ad748b5…`), `iron_app` SELECT t · INSERT t · TRUNCATE f · secuencia t; 38 rutas y `auth:coverage` vacío; configuración efectiva `{"engine_scope":"off","publishing_enabled":false,"lab_gate":"required","canary_min":[24,3],"window":"07:00-19:00","lab_unattended_s":300}`; 4 × 401; colas fallidas 2 (antes 2); 0 sellos; 0 ERROR; `security:check-secret-exposure` OK; `publication-check` 0. **Único desvío: el recuento de workers 6 s después del `queue:restart` dio 12 RUNNING** (uno todavía arrancando; el script salió con 1 por diseño); comprobado dos veces a continuación: **13 RUNNING, 0 BACKOFF/FATAL**, `queue:health` con todos los carriles al día, `h5-schema-check.sql` tras migrar con los valores esperados y el fichero de entorno igual al respaldo salvo las 7 claves.
- CRM: `deploy-crm.sh ab78a6a 4105016` → `main-ESWP7UG3.js` (antes `main-5VXOT43E.js`), `version.json` `ab78a6a` limpio, 200 en los dos `main` y en `/marketing/ultron-studio`; respaldo `/var/backups/ironbody/crm-pre-ab78a6a-20261101-011457/`. «CRM PUBLICADO Y VERIFICADO».
- Smoke: `SMOKE_PHASE=1 bash /root/smoke-run-h5.sh` (01:15:12 UTC; registro `/root/h5-smoke-fase1-*.log`): configuración efectiva de la fase 1; superadmin **35 OK · 0 FALLO · 0 OMITIDO** (sin APPROVED real: línea INFO), administrador **14 OK**, base 3 OK, cuenta 32 en reposo (inactiva, Super Admin, 2 sesiones borradas), **código de salida 0**.
- **Verificación a los 15 minutos (01-11-2026 01:30:35 UTC, solo lectura):** 13 workers RUNNING; `ironbody-billing-worker` active; `failed_jobs` 2 (igual que antes); 0 sellos `studio` en `marketing_ai_actions` (motor en `off`); 58 turnos en 24 h (ULTRON sigue atendiendo); 0 frenos activos; 0 publicaciones; `publication-check` 0; `queue:health` con todos los carriles al día; `security:check-secret-exposure` OK; `laravel.log` con 345 ERROR (la misma línea base que antes del despliegue); 0 señales (`publication_state_unavailable`, `unmapped_route`, `canary_acta_unavailable`); 0 respuestas 5xx de la API desde las 01:14. Las 40 líneas `upstream` de las últimas 200 del `error.log` de nginx son del servidor `n8n.ironbodyneiva.cloud` (`/mcp-server/http` del MCP de n8n, tiempos de espera agotados) y aparecen con la misma frecuencia desde antes del despliegue (00:03, 00:18, 00:25…): ajenas a la API y al Studio.
- Estado: **Hito 5 desplegado en la fase 1 (todo apagado), estable a los 15 minutos**. [PENDIENTE: verificación a las 24 horas (§5 del runbook; ≈ 02-11-2026 01:15 UTC) antes de la fase 2.]

**Producción de partida** (lectura del lead por SSH, solo lectura, 31-10-2026): API en `7318122` y CRM en `4105016` (build del 29-10). El Studio, en el estado del Hito 4: 33 rutas, 27 disparadores y 24 CHECK, 10 tablas `ultron_studio_*`, el worker `ironbody-lab` en marcha (13 workers RUNNING) y el laboratorio encendido en `fake`, con presupuesto 0 y sin clave. Ninguna de las 7 variables del Hito 5 en el entorno; 1 borrador, 0 versiones y 0 ejecuciones del laboratorio. ULTRON, encendido con el canario configurado y el cobro apagado.

## Hito 6: integraciones comerciales (31-10/01-11-2026, implementado en rama, pendiente de E2E, reviewer y despliegue)

**Resumen ejecutivo.** El Hito 6 conecta el Studio con lo comercial que ya rodea a ULTRON, **en lectura y como registro**:
- **Meta Ads:** el Studio lee las campañas del espejo local que llena la sincronización existente, distingue «En Meta» de «En ULTRON Studio» y registra la asociación de cada campaña con la línea base o con una versión;
- **Twilio:** un panel de solo lectura del OTP, una sonda de 5 GET apagada por bandera y la configuración autorizada por huellas (también la de Meta y la de la voz);
- **números canarios:** un registro auditado (huella HMAC y 4 dígitos; cifrado solo en voz), con cuatro ojos para autorizar y activar, comparado en cada lectura con la conversación canaria del motor;
- **voz y ElevenLabs:** el adaptador preparado sin activar, con su `doctor`, la firma del webhook post-llamada sin ruta pública y una política que hoy niega toda llamada.

**Lo que no cambia** (contrato, P1–P4): **nada de esto cambia lo que ULTRON dice o hace.** El motor no lee nada nuevo (T0 y T1), el Studio no escribe fuera de `ultron_studio_*`, ninguna ruta sale a la red con las banderas por defecto, no se escribe en Meta y no se llama a nadie. Las asociaciones, los números canarios y la configuración autorizada son **registro**, nunca autoridad.

**Dónde está:** la rama `feat/ultron-studio-h6` de los dos repos, que nace de la del Hito 5; en el backend lleva fusionado `origin/main` (`d2c04a0`, con `7ba0ce8` como ancestro). Backend `e3a0fdd` (el código es el de `4e2e8cf`; lo posterior es documentación); CRM `f38699d`, con la página «Integraciones». **No está en `origin/main` ni en producción.** Contrato: `ULTRON_STUDIO_H6_CONTRACT.md` (I1–I13 y Enmiendas 1–4). Plan del architect: `plans/ULTRON_STUDIO_H6_PLAN.md`. Informes de los agentes: `plans/informes-h6/`.

| Repo | Commit | Qué |
|---|---|---|
| Backend | `01ad03e` → `da1617e` | Paquete DB: las 3 tablas, sus guardas, los dos CHECK de cuatro ojos y el CHECK del libro ampliado (`database-specialist`) |
| | `7241ad8` → `0014292` | Paquete V: el puerto de voz, el proveedor nulo, el adaptador de ElevenLabs tras bandera, la firma del webhook post-llamada y `voice:doctor` |
| | `b647a8e` → `df1241c` | Paquete TW: el cliente de solo lectura de Twilio y el saneador de la sonda |
| | `41b6470` | Matriz de credenciales del VPS (auditoría de solo lectura del 01-11-2026, sin valores) |
| | `7d69b77` → `7df9ed9` | El fixture oráculo de integraciones, escrito a mano desde el contrato, con su comprobador independiente (`tester`) |
| | `89184d7` | Enmienda 1 del contrato (decisiones del bloque 1) |
| | `836b6fd` | Enmienda 2: el fixture congelado como oráculo; Q-4 a Q-13 y O-1 a O-6 |
| | `3995b01` | `last4` del saneador con el E.164 del contrato (Q-13) |
| | `5967f69` | Informes del arnés E2E y de la capa de datos del CRM |
| | `4e6f5fe` | Paquete S: las 18 rutas (56 en total), los 4 permisos, los servicios, los modelos, los comandos `integrations-doctor` y `integrations-snapshot`, la exportación de fixtures y las pruebas |
| | `34ab7b3` | Paquete OPS: scripts de la fase 1, alcance de escrituras y sonda, comprobación de esquema y smoke de las fases 1–2 |
| | `d2c04a0` | Fusión de `origin/main` (H-2 de DevOps) |
| | `8de30ed`, `f549c50` | Banco de ensayo en el repo (`ops/banco-h6/`) y ensayo del smoke contra el arnés E2E |
| | `109735b`, `6241758` | Revisión de la API (`api-security-specialist`) y su AS-5 en el mando de alcance |
| | `dd57c12`, `0b7b41d` | Revisión de seguridad transversal (`security`) y Enmienda 3 |
| | `bd1c7af`, `fb152fe` | N-5 (el patrón del `agent_id` con `D`) y N-4 (el mando exige el fichero de entorno en 600) |
| | `3e1ee20` | Paquete S, tanda final: paridad con las 22 secciones del fixture, Q-6, O-2, B-2, AS-1, AS-2, N-1 y N-3 |
| | `2582f10` → `4e2e8cf` | Enmienda 4 en curso y su cierre: el `agent_id` sin recorte, el alias con dato de contacto (722 casos) y el orden `permission` antes que `shape` |
| | `cf36b48` | Tester de backend: carreras reales en PostgreSQL, guardas como rol de aplicación, centinelas y mutaciones |
| | `d5da123` | Capturas de la página «Integraciones» con el arnés de Chrome, en curso |
| | `e3a0fdd` | Enmienda 4 del contrato |
| | `7d2c6d8` | Informe de la integración de la página «Integraciones» en el CRM (`informe-front-integracion.md`). Solo documentación: el código del backend es el de `4e2e8cf` |
| CRM | `0a83d74` → `ede716b` | Capa de datos de las integraciones: tipos, lectores, textos, el servicio de las 18 rutas, el fixture con el formato del exportador, 101 specs nuevas y los 4 permisos |
| | `037a2cd` → `e8386dc` | Infraestructura del E2E del Hito 6: simuladores de Twilio (:8095) y ElevenLabs (:8096), semilla de Meta Ads y del canario, y el esqueleto de `h6-integrations` |
| | `f38699d` | Página «Integraciones»: la página, la sesión, los ocho componentes, sus hojas y sus specs. El lead la confirmó como WIP «sin verificar» (el agente se cortó antes de correr la suite y el build); después, `frontend` la verificó sin cambiarla (`informe-front-integracion.md`) |

**Clasificación honesta, por área** (lo que el Studio afirma, con la evidencia de producción al congelar el contrato, 31-10-2026, y de la matriz de credenciales del 01-11-2026; ningún valor impreso):

| Área | Clasificación | Qué hay en la rama | Qué no hay |
|---|---|---|---|
| **A · Meta Ads** | **Conectada en lectura** (`connected_read`): token validado (504 pasadas `ok` en 7 días), 3 cuentas, sincronización horaria | Lectura del espejo sin red, ids opacos, «En Meta» frente a «En ULTRON Studio», asociación campaña ↔ línea base o versión, con sustitución y desactivación auditadas | Ninguna llamada a Graph ni escritura en Meta; ningún efecto en el motor; sin datos de demostración |
| **B · Twilio (OTP)** | **Conectada solo para OTP** (`connected_otp_only`): Verify por SMS y Messages solo para el código de vínculo; la misma credencial con dos nombres | Panel de solo lectura (OTP, coste, credenciales por huella, webhook, llamadas sin datos del prospecto), sonda de 5 GET tras bandera y configuración autorizada | Ningún cambio en el OTP; ningún mensaje ni llamada; los avisos de `usage-trigger` siguen solo en el log |
| **B · Twilio Voice** | **No configurada** (`not_configured`): sin variables, número, webhook ni código | Tres variables apagadas que solo leen el panel y la política | Ni TwiML, ni webhook de voz, ni subcuenta, ni marcador |
| **C · Números canarios** | **Registro** (`registry`): el canario del motor sigue siendo el id de una conversación, en el entorno | Registro, autorización y activación con cuatro ojos, desactivación, revocación, versión esperada y coherencia con el canario del motor | El motor no lee el registro (T0/T1); T2 y T3, fuera, con su autorización |
| **D · ElevenLabs** | **No configurada** (`ELEVENLABS_NOT_CONFIGURED`): cero credenciales | Puerto, proveedor nulo, adaptador tras bandera con verificación por consola, `voice:doctor`, firma del webhook post-llamada y política de llamadas | Ninguna ruta pública ni llamada; `CALLS_ENABLED_IN_CODE = false` |

**IMPLEMENTADO Y PROBADO** (en rama):
- **Las 3 tablas, de solo inserción o con transiciones condicionadas** (`ultron_studio_campaign_links`, `ultron_studio_canary_numbers` y `ultron_studio_integration_snapshots`): disparadores en los dos motores, CHECK en PostgreSQL (entre ellos, los dos de cuatro ojos), índices únicos parciales (una asociación activa por campaña; una fila viva por canal y teléfono) y el libro ampliado a 7 sujetos.
- **18 rutas nuevas** (`/api/admin/ultron-studio/integrations/*`, 56 en el Studio), cada una con su llave en `AuthorizationMap` y la segunda puerta en el servicio; **4 permisos** (`ultron.campaigns_manage` y `ultron.canary_manage`, que el Administrador tiene por defecto; `ultron.canary_authorize` y `ultron.integrations_manage`, solo del Super Admin); una **sola bandera de escrituras** para todo el hito (reconocer la configuración queda fuera de ella) y otra para la sonda.
- **Idempotencia por `clientRequestId`** atada a la persona y al cuerpo, el evento del libro en la misma transacción que el cambio y los topes bajo cerrojo consultivo con READ COMMITTED.
- **La frontera, probada:** el motor no nombra `App\Services\Voice`, `App\Services\Twilio` ni `PhoneFingerprint`; con las banderas por defecto, ninguna de las 18 rutas hace una petición saliente; una centinela con valores reconocibles en los secretos y en los teléfonos no los encuentra en ninguna respuesta, en el libro, en `facts`, en la consola ni en el log.
- **Comandos:** `ultron-studio:integrations-doctor [--json] [--expect=…]` (la clasificación por consola; lo usan el despliegue y el smoke), `ultron-studio:integrations-snapshot [--verify-voice] [--json]` (la instantánea de sistema, solo si cambió la huella) y `voice:doctor [--json] [--verify]`.
- **CRM, capa de datos** (`ede716b`): tipos, lectores estrictos o tolerantes, los textos obligatorios literales, el servicio de las 18 rutas, el fixture con el formato del exportador del backend y la compatibilidad con un backend del Hito 5 (404 → «El servidor todavía no tiene integraciones (Hito 6)»).
- **CRM, página «Integraciones»** (`f38699d`): `marketing/ultron-studio/integraciones`, diferida y con su enlace en la barra del tablero, con cinco pestañas (Estado, Campañas de Meta, Números canarios, Twilio y Voz); los botones salen solo de `can`, `flags` y `actions` del servidor, y sin botón el motivo se lee en texto; el teléfono solo vive en el diálogo de registro y nunca queda en pantalla.
- **Operación:** el script de la fase 1, el mando de alcance de las dos banderas, la comprobación de esquema, el smoke de las fases 1–2 con su detector de fugas, y un banco de ensayo en el repo.

**QUÉ NO HACE** (por diseño): no cambia lo que ULTRON dice o hace; no escribe en Meta ni llama a la API Graph; no envía mensajes ni hace llamadas; no toca el OTP, el motor, n8n, el cobro, la pausa ni el freno; no activa ElevenLabs; no persiste los avisos de `usage-trigger`; no lista campañas sin gasto; no programa `integrations-snapshot` (Hito 7). **Fuera de este hito, con autorización expresa aparte:** T2 y T3, cualquier llamada, cualquier escritura en Meta y encender ElevenLabs.

**Evidencia por paquete.** Cada cifra lleva su informe; las de backend son de su árbol del momento. **Las puertas completas sobre el código final** las hizo el lead sobre `e3a0fdd` (el código es el de `4e2e8cf`) el 01-11-2026, de 18:43 a 18:49 UTC: Studio + Access + Chaos + Voice + Twilio + OTP en SQLite, **2879 OK** (102 omitidas, las de solo PostgreSQL); la puerta PostgreSQL, **2455 OK** en 4 minutos (base `ultron_studio_h6_lead`); **56 rutas**, `auth:coverage --unmapped` vacío y la puerta de diff del motor vacía; Marketing, **4977 OK** sobre `d2c04a0`.
- **DB** (`informe-db.md`, sobre PostgreSQL 14.24 y SQLite 3.54.1): disparadores del Studio en PostgreSQL de **30 a 43** y CHECK de **27 a 49**; en SQLite, de 20 a 30 disparadores; índices parciales de 2 a 4; tablas `ultron_studio_*` de 11 a 14; `ultron_studio_forbid()` sin cambiar un byte (`4ad748b5…`). `UltronStudioIntegrationsSchemaTest`: 22 pruebas, 473 aserciones en PostgreSQL (en SQLite, 321 con 5 omitidas, las de solo PostgreSQL). `migrate:rollback --step=2` deja el esquema idéntico, `down()` se niega con datos del Hito 6 y un dueño sin superusuario migra sin ningún GRANT nuevo. Con un lector del libro abierto, la 100002 cae a los 5,36 s por 55P03 y deja las 3 tablas sin guardas hasta reintentar.
- **V** (`informe-voz.md`; `fb152fe`): **72 pruebas** del paquete de voz en verde tras N-5; 26 de 26 mutantes cazados por `VoiceIsolationTest` y los 3 de `VoiceBoundaryTest`.
- **TW** (`informe-twilio.md`): **31 pruebas** y 5782 aserciones; 22 de 22 mutaciones cazadas; cero red.
- **Fixture del contrato** (`informe-fixture.md`; Enmiendas 2 y 4): `tests/fixtures/ultron-studio/integrations-cases.json`, **722 casos en 22 secciones** (recontados el 01-11-2026 sobre `e3a0fdd`), escritos a mano desde el contrato. La segunda derivación independiente, con openssl, rehace 704 casos con 0 discrepancias y caza 116 implementaciones equivocadas (`4e2e8cf`). Si PHP o TS discrepan, se corrige la implementación, nunca el fixture.
- **S** (`informe-api.md`, tanda final en `3e1ee20`): **56 rutas** y `auth:coverage --unmapped` vacío; `permissions:export`, 105 permisos, idéntico al catálogo del CRM; paridad con las 22 secciones del fixture contra las clases reales (408 pruebas puras; 466 por HTTP con 1475 aserciones; `errorOrder` paso a paso, 170 pasos); Studio + Access + Chaos + Voice + Twilio en SQLite, **2806 pruebas**; la puerta PostgreSQL, **2452 pruebas**; OTP, 82 OK; Marketing, 4977 OK; 19 de 19 mutaciones de la tanda final cazadas (9 de 9 en la primera). Los 2 rojos de esa corrida no eran de S (el `agent_id` de V con recorte y un caso de alias del fixture desfasado por AS-1) y los cerró la Enmienda 4 (`4e2e8cf`: Voice + paridad + canarios, **965/965**); el tercero de PostgreSQL fue un umbral de 150 ms de una prueba del H5, rebasado en 7 ms con la máquina compartida; ejecutada sola, esa prueba pasa 3 de 3.
- **Tester de backend** (`informe-tester-backend.md`; `cf36b48`): carreras reales en PostgreSQL entre procesos, naturales y forzadas (29 pruebas, 37 casos): **45/45** pruebas con 1526 aserciones en la corrida final (148 sesiones en las 37 carreras); guardas contra un rol de aplicación sin superusuario (P0001, 23514, 23505 y 42501); centinela ampliada al exportador de fixtures, a las filas del registro y a la configuración calculada de verdad desde el entorno; y **33 de 35 mutaciones cazadas** (las 2 que sobreviven son equivalentes y sus variantes sin la segunda comprobación caen).
- **DevOps** (`informe-devops-h6.md`; `34ab7b3`, `8de30ed` y `f549c50`): en el banco local, despliegue con un 55P03 provocado → `FASE 1 (H6) COMPLETADA` en 45–46 s, reanudación sin duplicados y 6 negativas del paso 0; detector de fugas del smoke, **54/54**, con sus 7 mutaciones cazadas; funciones del fichero de entorno, 21/21 (y `prueba-entorno.sh`, 23/23); smoke contra el banco y contra el arnés E2E: fase 1 **78 + 26 OK**, fase 2 con la sonda apagada y encendida **73 + 26 OK**, las 14 tablas idénticas en cada pasada y 0 peticiones a los simuladores; cierre del arnés, 12/12.
- **Infraestructura del E2E** (`informe-e2e-arnes.md`; CRM `e8386dc`): la regresión del H5 con los simuladores nuevos, **410/410** sobre `7ba0ce8` + `ab78a6a` (las 408 de siempre más 2 comprobaciones: 0 violaciones en Twilio y en ElevenLabs); el esqueleto del H6, 22/22; los simuladores aislados, 64/64.
- **CRM, capa de datos** (`informe-front-datos.md`; `ede716b`): módulo del Studio y modelos, **1005/1005** en 60 ficheros (101 specs nuevas); `tsc` y `check:literals` limpios; 3 de 3 mutaciones cazadas.
- **CRM, página** (`informe-front-integracion.md`; sobre `f38699d`): **1096/1096** en 70 ficheros; build de producción en verde sin avisos nuevos; 12/12 mutaciones; Chrome con la API simulada, 93/93 a 1440 y 390 px.
- [PENDIENTE: la línea base por nombre contra `origin/main` (quedó corriendo al cortarse la sesión del tester, §7 de su informe; las puertas finales salen en verde) y F2, la validación de la corrección propuesta para H-2, que tampoco consta.]

**Decisiones del lead** (contrato, decisiones 1–14, y Enmiendas 1–4):
1. **Sonda B2:** bandera apagada en la fase 1; credencial, la API key restringida si el propietario la crea, si no la del OTP; solo GET, hosts fijos en `production`, primera página, enfriamiento de 5 minutos, cupo propio y evento en el libro.
2. **«Asociar una versión» a un número canario es una expectativa auditada**, comparada con la cadena del Hito 5; nunca autoridad.
3. **Transición del canario:** el Hito 6 implementa T0 (el registro existe y se compara) y T1 (el número de la conversación canaria registrado, autorizado y activado por dos personas: `in_sync`). T2 y T3, fases futuras, cada una con su autorización.
4. **Cuatro llaves**; activar exige además una persona distinta de quien registró (con su CHECK en la base).
5. **Minimización:** WhatsApp, solo huella y 4 dígitos; voz, cifrado y borrado al revocar; consentimiento declarado.
6. **Una sola bandera de escrituras** para todo el hito; reconocer la configuración queda fuera de ella.
7. **Sin ruta pública** para el webhook post-llamada de ElevenLabs; sin persistir los avisos de `usage-trigger`; sin campañas sin gasto.
8. **Criterio de cierre:** `HITO_6_COMPLETED` con las fases 1 y 2 hechas y ElevenLabs en `ELEVENLABS_NOT_CONFIGURED`; `HITO_6_IMPLEMENTED_WITH_EXTERNAL_DEPENDENCY` si falta la segunda persona del T1 o el despliegue del Hito 5.
- **Enmienda 1:** importes como texto con dos decimales en `facts` y mapas vacíos como `[]`; las etiquetas de versión del servidor; PCRE con `D` y `[0-9]` sin `/u`; `secretFingerprints()` como única vía a las huellas de los secretos de voz; D-1 y D-2 del esquema (la sustitución de una asociación era imposible con la guarda literal; `usis_outcome_chk` dejaba pasar una sonda sin resultado) con los recuentos 43/49; las convenciones del saneador y del cliente de Twilio.
- **Enmienda 2:** el fixture queda congelado como oráculo; Q-4 (`ELEVENLABS_VERIFIED` exige que la **última** verificación sea `ok` y de menos de 30 días) a Q-13 (`last4` con un E.164 de 8 a 15 cifras); O-1 a O-6.
- **Enmienda 3:** las revisiones de seguridad y de la API, sin bloqueantes; **I13 corregido** (la huella del teléfono se recupera con la `APP_KEY` y los 4 dígitos; revocar no suprime); AS-1, AS-2, B-2, N-1 y N-3 para S; N-4 y AS-5 para OPS; N-5 para V; lo diferido al Hito 7.
- **Enmienda 4:** en las escrituras, la llave (403) va antes que la forma (422), porque la exige la puerta de `AuthorizationMap`; el alias rechaza datos de contacto (correo, u 8 o más dígitos con separadores cortos; el fixture pasa a 722 casos); el `agent_id` no se recorta; Q-6 (`activeWhatsapp` cuenta las filas activas y es `null` solo si falla el recuento); B-2, AS-2, N-1 y N-3 cerrados; H-2 del tester diferido al Hito 7.

### Hito 6: revisiones de seguridad y de la API

Detalle en `ULTRON_STUDIO_SECURITY_REPORT.md` («Hito 6»).
- **`security`** (01-11-2026, sobre el backend de `4e6f5fe`/`34ab7b3` y el CRM en curso; `informe-security.md`): **sin bloqueantes, altas ni medias.** B-1 (la huella del teléfono se recupera con la `APP_KEY` y los 4 dígitos; I13 decía lo contrario, y revocar no suprime), corregida en el texto del contrato (Enmienda 3) y con la supresión diferida al Hito 7; B-2 (revocar anotaba en el libro el estado leído fuera de la transacción), corregida en `3e1ee20`; N-1, N-3 y N-5, corregidas (`3e1ee20`, `bd1c7af`, `4e2e8cf`); N-2 = AS-5, el mando de la sonda comprueba `production` (`6241758`); N-4, el mando exige el fichero de entorno en 600 (`bd1c7af`), con dos puntos pendientes de comprobar en el servidor.
- **`api-security-specialist`** (01-11-2026, sobre `8de30ed`, código de S en `4e6f5fe`; `informe-api-security.md`): **APROBADO CON OBSERVACIONES**, sin bloqueantes ni altas. AS-1 (motivos, notas y alias con teléfonos o correos) y AS-2 (`page` enorme → 500), corregidas en `3e1ee20`; AS-3 (oráculos de pertenencia de un teléfono adivinado), nota para el Hito 7; AS-4 = B-1; AS-5 = N-2; AS-6, nota de documentación.
- **`security`, segunda pasada sobre la página del CRM** (contrato, «Orden», paso 5): **Hecho (01-11-2026, `security`, apartado «Segunda pasada» de `plans/informes-h6/informe-security.md`, confirmado en `8c3eb7e`): sin bloqueantes, críticas, altas, medias ni bajas; dos notas: N-6 (el lector de las bases de Twilio descarta un host IPv6 o con guion bajo y deja el panel sin leer; hoy no ocurre con las bases oficiales) y N-7 (fixture del CRM desfasado, cerrada en `3bac1e2`). Cero sumideros de HTML en los 22 ficheros, ninguna URL con datos del servidor, teléfono solo enmascarado y solo en la ruta 49, nada en almacenamiento ni consola, botones solo de `can`/`flags`/`actions`, foco atrapado y sin autofoco en el teléfono; specs del módulo 1074 + 60 en verde.

### Hito 6: validación del backend por el tester

**Hecha el 01-11-2026** sobre `f549c50` con la tanda final de S sin confirmar entonces, en PostgreSQL 14.24 local (`informe-tester-backend.md`; confirmada en `cf36b48`).
- **Las carreras de I11 y más:** dos registros del mismo número, dos asociaciones de la misma campaña, autorizar frente a revocar, 4 activaciones contra un tope de 3, reconocer frente a un cambio de configuración, el tope de vivos, el tope global de asociaciones, la sustitución concurrente, el doble clic en las cinco escrituras con `clientRequestId` y la sonda con la caché compartida (4 pulsaciones → una sola salida de 5 GET). **45/45.**
- **H-1 (= B-2):** la carrera forzada lo reprodujo de forma determinista y verifica la corrección de S (`3e1ee20`). **H-2 (baja, nueva):** la huella del teléfono llega al `DETAIL` del error 23505 del log del servidor PostgreSQL en cada registro duplicado; diferida al Hito 7 (Enmienda 4). **H-3 (= B-1):** medido, unos 705 000 HMAC por segundo y núcleo: con la `APP_KEY`, el espacio de WhatsApp (10⁹) se recorre en unos 24 minutos con un núcleo.
- **O-1:** la carrera del H5 deja sin esquema a las clases de SQLite que corren después si es la primera del proceso (corrección de una línea, para el dueño de esa prueba). **O-2:** con `default_transaction_isolation = repeatable read` en el rol, 7 de las 37 carreras responderían 500 (40001); el rol de producción tiene que estar en READ COMMITTED (comprobación del runbook antes de la fase 2).
- **No verificado por el tester:** producción, los roles reales, la configuración de log del PostgreSQL de producción, el 429 de la sonda entre procesos y la línea base contra `origin/main` (pendiente al cortarse su sesión).

### Hito 6: E2E con la pila real

**Pendiente.** La infraestructura está lista (`informe-e2e-arnes.md`; CRM `e8386dc`): simuladores de Twilio (:8095) y ElevenLabs (:8096) dentro de `e2e/ultron-studio/run.sh`, la semilla de Meta Ads (3 cuentas sintéticas, 12 campañas, una cuenta vieja) y del lead canario, y el cierre con dos comprobaciones nuevas (0 violaciones en cada simulador). La regresión del H5 con todo eso, **410/410** sobre `7ba0ce8` + `ab78a6a`. `specs/h6-integrations.cjs` es un **esqueleto que falla a propósito** en cuanto la instantánea trae las rutas del H6, para que ninguna pasada verde se lea como cobertura del hito. [PENDIENTE: los 15 escenarios E1–E15 (I11 1–9 más la configuración autorizada, la matriz, el barrido de fugas, la frontera, la firma y CRM↔H5), con la página del CRM (`f38699d` o el commit final), y la regresión del H5 sobre el commit final, con la valla vacía.]

DevOps sí ejecutó su smoke contra el arnés con el backend `d2c04a0` y el CRM `ede716b` (ver «Evidencia»). Encontró una trampa del arnés: con `artisan serve --no-reload`, el proceso web fija el entorno al arrancar y no ve un cambio del fichero de entorno; el smoke lo cazó (16 FALLO, con la base intacta) y se resolvió con `config:cache`, como en producción.

### Hito 6: operación (DevOps)

**Hecho el 31-10/01-11-2026, todo en local**, sin SSH ni peticiones a producción (`informe-devops-h6.md`; runbook en `ULTRON_STUDIO_DEPLOYMENT_PLAN.md`, «Hito 6», y vuelta atrás en `ULTRON_STUDIO_ROLLBACK.md`, «Hito 6»).
- **H-1:** la huella de la configuración de Twilio incluye `facts.probe.enabled`. Con el orden del contrato (reconocer y después `probe on`), Twilio quedaría en `drift`: el runbook de la fase 2 reconoce **después** de encender la sonda.
- **H-2:** la rama no contenía `7ba0ce8`; resuelto con la fusión `d2c04a0`.
- **H-3:** el smoke cabe 3 veces por hora: el cupo de la sonda (6/h por persona) corre antes de autenticar y cada smoke gasta 2.
- **H-4:** `MIN_TABLAS=223` en el respaldo (las 222 del volcado del H5 más la tabla de publicaciones).
- **No verificado:** GNU `stat` y `grep`, `sudo` real, el envoltorio `ironbody-migrate`, los dueños reales y el FPM viendo la caché nueva sin recargar. Lo cubre en parte el `--solo-comprobar` en el servidor, que no se ha ejecutado.

### Hito 6: veredicto del reviewer

[PENDIENTE: revisión final del `reviewer` sobre el backend y el CRM del Hito 6, tras el E2E y las revisiones de seguridad (contrato, «Orden», paso 7).] **El hito no se despliega con un bloqueante del `reviewer` abierto.**

### Hito 6: el CRM

Detalle en `ULTRON_STUDIO_VISUAL_REPORT.md` («Hito 6»). La capa de datos está en `ede716b` (`informe-front-datos.md`) y la página «Integraciones», **terminada y verificada en `f38699d`** por `frontend`, con el árbol limpio (`informe-front-integracion.md`, confirmado en `7d2c6d8`):
- módulo del Studio y modelos, **1096/1096** en 70 ficheros (antes, 1005 en 60); `tsc` y `check:literals` limpios;
- `npm run build:prod` en verde, con los mismos 36 avisos de presupuesto que en `ede716b`, nombre a nombre, y el chunk nuevo `studio-integrations-page` (162,46 kB; 38,03 kB transferidos);
- **12/12 mutaciones** tumbadas;
- Chrome con la API simulada, a 1440 y 390 px: **93/93**, sin errores de JavaScript, con 32 capturas (confirmadas en `d5da123`). No es el E2E con la pila real.

**Hecho (01-11-2026, `security`, apartado «Segunda pasada» de `plans/informes-h6/informe-security.md`, confirmado en `8c3eb7e`): sin bloqueantes, críticas, altas, medias ni bajas; dos notas: N-6 (el lector de las bases de Twilio descarta un host IPv6 o con guion bajo y deja el panel sin leer; hoy no ocurre con las bases oficiales) y N-7 (fixture del CRM desfasado, cerrada en `3bac1e2`). Cero sumideros de HTML en los 22 ficheros, ninguna URL con datos del servidor, teléfono solo enmascarado y solo en la ruta 49, nada en almacenamiento ni consola, botones solo de `can`/`flags`/`actions`, foco atrapado y sin autofoco en el teléfono; specs del módulo 1074 + 60 en verde. **Hecho (lead, 01-11-2026, CRM `3bac1e2`):** `backend-integrations.testing.ts` reexportado desde el backend final (`ultron-studio:export-fixtures` sobre `e3a0fdd`+docs) y copiado byte a byte; `permissions:export` idéntico al catálogo del CRM (105 llaves); specs de paridad y de canarios alineadas (texto general de O-2, `phoneNotMobileForWhatsapp`, `contactData` y `activeWhatsapp: 1` con coherencia ilegible, tolerando `null`); módulo + `models` 70/70 ficheros en verde. **D-F14 (decisión del lead):** la fila de más que añade el enlace «Integraciones» en la cabecera del tablero a 390 px se acepta como está (corregirla exige tocar `ultron-studio.scss`, que está congelado por contrato); queda en la lista del Hito 7 como ajuste visual opcional. [PENDIENTE: suite completa del CRM sobre el commit final (lead, en marcha).]

### Hito 6: despliegue por fases

El runbook está en `ULTRON_STUDIO_DEPLOYMENT_PLAN.md` («Hito 6») y la vuelta atrás, en `ULTRON_STUDIO_ROLLBACK.md` («Hito 6»). **Nada ejecutado.**

| Fase | Qué queda en producción | Coste | Quién decide |
|---|---|---|---|
| 1. Código con escrituras, sonda y voz apagadas | 56 rutas, las 3 tablas con sus guardas, las 8 variables, el CRM y la primera instantánea observada de los 3 proveedores. Escrituras y sonda en 409 | 0 | La autorización del 25-10-2026, con la puerta en verde |
| 2. Escrituras encendidas | El T1 en `in_sync` con dos identidades, la configuración de los 3 proveedores reconocida y una sonda ejecutada | 0 (5 GET) | La misma, con dos personas disponibles |

- **Criterio de cierre:** **`HITO_6_COMPLETED`** con las fases 1 y 2, el T1 en `in_sync`, la configuración reconocida, una sonda con su instantánea, el panel en vivo igual a la clasificación al congelar (o la diferencia explicada) y ElevenLabs en `ELEVENLABS_NOT_CONFIGURED`. **`HITO_6_IMPLEMENTED_WITH_EXTERNAL_DEPENDENCY`** si falta la segunda persona para el T1 o el despliegue previo del Hito 5 (este ya está hecho).

### Hito 6: despliegue y verificación

[PENDIENTE: fases 1 y 2 en producción, con la salida del script, la del smoke, el respaldo, la verificación a los 15 minutos y a las 24 horas, la evidencia del T1, la de la sonda y la del reconocimiento de la configuración.]

## Decisiones tomadas (y por qué)

1. **Laravel conserva la autoridad; sin Python.** El motor es Laravel + n8n con contrato firmado; un segundo motor podría contradecir los 28 controles del commit.
2. **El grafo representa el motor real, no un diagrama libre.** Solo existen las conexiones que el motor tiene; los controles son obligatorios; el segmento `Construcción → Reglas → Integraciones` está sellado; las aristas que abren el turno o actúan tras él no cuentan como ciclo.
3. **El Studio nace de solo lectura y demostración.** Nada escribe en el motor; «Publicar» deshabilitado con explicación; aviso permanente de vista previa.
4. **Foblex Flow 19.3.0 con versión exacta** (MIT, cinco paquetes, sin scripts de instalación, sin red). Del tema se incluye solo lo esencial por presupuesto de estilos.
5. **Bandera de vista previa y permiso existente** (`marketing.agent.manage`, Super Admin y Administrador) para no inventar permisos antes de que exista la fase de backend que los valide.
6. **El catálogo no cartografía el servidor**: nombres de clases sin rutas ni líneas (hallazgo H1), con prueba que lo vigila; en la fase con persistencia vendrá del backend.

## Preguntas abiertas para el propietario

Las de `ULTRON_STUDIO_ARCHITECTURE_AUDIT.md` §14: versionado de cada turno en el commit; qué significan «números canarios» (hoy el canario es UNA conversación de WhatsApp); número de voz de Twilio o solo caller ID verificado, y si se acepta una subcuenta; si una voz puede decir precios; clave de OpenAI separada; quién escribe los prompts en n8n.

*(Actualización del 01-11-2026: el sello de la versión en cada turno lo trajo el Hito 5. Con el Hito 6, los «números canarios» son un registro que se compara con la conversación canaria sin sustituirla (T0/T1), y la pregunta del número de voz la responderá la primera sonda de Twilio en la fase 2. Siguen abiertas, para el hito de voz, la subcuenta, si una voz puede decir precios y la grabación y su retención; y del Hito 6, si el envío por Messages del código de vínculo está en uso, porque `TWILIO_FROM` está vacía en producción según la matriz de credenciales.)*

## Autorizaciones

*Así se escribió el 17/18-10-2026, para la vista previa:*
- **Usadas:** auditoría, diseño, implementación, dependencias compatibles, pruebas, documentación, construcción del frontend, commits locales, publicación protegida de la vista previa.
- **No usadas (y no se usarán sin orden expresa):** cambiar ULTRON en producción, llamadas reales, campañas o presupuestos Meta, publicar reglas, reemplazar el motor, Python, ElevenLabs, APIs de pago, migraciones en producción, **push a `main`**. *(Después, el push a `main` y las migraciones en producción se usaron, con la autorización del usuario, en los despliegues de F (18-10), G (19-10) y el Hito 4 (25-10).)*

**Desde el 25-10-2026:** el propietario concedió autorización completa para commits, push, despliegues por fases y cierre de agentes de ULTRON STUDIO. Los límites absolutos siguen intactos:
- sin llamadas reales;
- sin Meta;
- sin ElevenLabs;
- sin gasto sin control;
- sin sustituir Legacy;
- sin publicar reglas sin validación;
- sin `down()` en producción;
- sin `push --force`, `reset --hard` ni `clean`.

El contrato del Hito 5 pide además una orden expresa para cada fase de despliegue y la autorización del propietario para levantar el 409 de publicar (fase 2) y para la fase 3 («Protecciones intocables»). Según el informe de recuperación del lead (`plans/informes-h5/informe-recuperacion-2026-10-31.md` §6), esa autorización permite decidir `waived` para la puerta del laboratorio si queda documentado por escrito en cada publicación y en este informe. **Confirmado por el lead (31-10-2026):** la autorización del 25-10-2026 («no me pidas permisos, te los concedo completos») cubre las fases 2 y 3 del Hito 5 cuando cumplan sus criterios (fase 2: fase 1 verificada y smoke en verde; fase 3: evidencia del canario con al menos 24 h y 3 turnos sellados), documentando cada paso en este informe; siguen exigiendo autorización expresa aparte: llamadas reales, escribir en Meta, ElevenLabs, gasto, sustituir Legacy y `down()` en producción.

**Hito 6** (contrato, «Autorizaciones», 31-10-2026): bajo la autorización del 25-10-2026, **las fases 1 y 2 del Hito 6 y la sonda GET de Twilio (coste cero) se ejecutan cuando cumplan sus criterios**, documentando cada paso: la fase 1, con la puerta en verde y el Hito 5 en su fase 1; la fase 2, con la fase 1 verificada y dos personas disponibles para el T1. Push a `main` solo con avance rápido y nunca `--force`. **Siguen exigiendo autorización expresa aparte:** T2 y T3 (que el motor lea el registro de números canarios), cualquier llamada, cualquier escritura en Meta y encender ElevenLabs. Y siguen intactos los límites absolutos de arriba: sin gasto sin control, sin tocar ni sustituir el motor y sin `down()` en producción.

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

**Estado del 31-10-2026** (lectura del lead por SSH, solo lectura): la URL es la misma. Producción sirve el CRM `4105016` y la API `7318122`, con el Studio en el estado del Hito 4 (fases 1–2). El Hito 5 no está publicado ni en `origin/main`.
