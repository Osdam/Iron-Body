# ULTRON STUDIO · Hito 6 · Infraestructura del E2E (paso 1f del contrato)

**Fecha:** 01-11-2026 (UTC). **Autor:** `tester`. **Repositorio tocado:** solo `Iron-Body_Front/e2e/ultron-studio/**`
(worktree `_worktrees/ultron-h6/Iron-Body_Front`, rama `feat/ultron-studio-h6`). **Sin commit** (los dos simuladores
ya los confirmó el lead como WIP en `037a2cd`; el resto queda en el árbol de trabajo). Nada del backend tocado salvo
este informe. Nada de producción ni de red real.

## Resultado

| Pasada | Órdenes | Resultado |
|---|---|---|
| **Verificación obligatoria** | `bash e2e/ultron-studio/run.sh 7ba0ce8 ab78a6a` | **410/410**, salida 0, 247 s. Son las **408 de la regresión del H5** (F 40, G 25, H4 27, turno 30, H5 276, cierre 10) **+ 2 comprobaciones nuevas del cierre** (0 violaciones en Twilio y en ElevenLabs). Valla con solo la sonda del arranque; los dos simuladores arrancados, con **0 peticiones** en toda la regresión, y parados; los 8 puertos libres al final |
| Desarrollo (H6) | `E2E_HOLD=1 bash e2e/ultron-studio/run.sh 7ba0ce8 ab78a6a h6-integrations cierre` | **22/22** (esqueleto 10/10, cierre 12/12), 65 s; luego, con la pila en pie, las 4 roturas a propósito de abajo |
| Simuladores aislados | `node <scratchpad>/test-mocks.cjs` (puertos 18095/18096) | **64/64** (la primera vuelta dio 63/64 por un error de MI aserción: esperaba 4 violaciones y hay 5 legítimas; corregida la prueba, no el simulador) |

Puertos comprobados libres antes de cada pasada: 8080, 8092, 8093, 8094, 8095, 8096, 4300 y 8099 (y nadie conectado a
`ultron_studio_e2e`; el PostgreSQL de :55432 es el clúster desechable del 24-10, que el arnés usa y no para).

### Salida real de la verificación (`7ba0ce8` / `ab78a6a`)

```
== ULTRON STUDIO E2E · backend 7ba0ce8 · CRM ab78a6a · 2026-11-01T01:24:08Z
   specs: f-versions g-inspector-compare h4-lab-fake ultron-turn h5-governance cierre
make-env: 56 sustituidas, 19 añadidas, 1 entrecomilladas (FACTUS_CONSUMER_FINAL_NAME)
   migraciones: 324 aplicadas
== 5. Pila
   · fence · mock-openai · mock-n8n · mock-twilio (pid 93038) · mock-elevenlabs (pid 93041) · serve · worker-lab · worker-whatsapp · worker-commercial · proxy
   artisan serve: 8 workers de php -S (esperados 8)
   valla de red: la sonda https quedó registrada y bloqueada ✓ (el cierre no la cuenta)
   simuladores de Twilio y ElevenLabs: PHP los alcanza sin pasar por la valla ✓
  → f-versions: 40/40 · g-inspector-compare: 25/25 · h4-lab-fake: 27/27 · ultron-turn: 30/30 · h5-governance: 276/276
== spec: cierre
  ✓ huella: las 17 tablas de la huella existen en la base
  ✓ huella: 17 tablas del motor y colas idénticas tras F, G y H4
  ✓ proxy: sin 5xx en 466 peticiones del CRM
  ✓ laravel.log sin ERROR
  ✓ failed_jobs vacío
  ✓ jobs: no queda ningún trabajo en ninguna cola
  ✓ simulador de OpenAI: 0 violaciones del contrato
  ✓ simulador de n8n: 0 violaciones (evento mínimo) y todo commit que llegó, aceptado
  ✓ simulador de Twilio: 0 violaciones (solo GET a las 5 rutas de la sonda, con la credencial de la instantánea y sin seguir páginas)
  ✓ simulador de ElevenLabs: 0 violaciones (solo el GET de verificación del agente con xi-api-key; ninguna llamada)
  ✓ valla de red: ningún intento de salir en toda la ejecución
  ✓ secretos: ninguno de los 12 aparece en 23 registros ni en 9 tablas
   TOTAL 410/410
== Duración: 247 s
== Parada
   procesos parados; registros en …/run-20261101T012408Z-7ba0ce8-ab78a6a/logs
exit=0
```

`fence.jsonl`: 1 línea (la sonda `e2e-fence-probe.invalid:443`). `mock-twilio.jsonl` + `mock-elevenlabs.jsonl`: 0 líneas.

### Las comprobaciones nuevas caen cuando se rompe lo que cubren (pila del H6 en pie, `cierre` repetido)

Acumulativo: cada rotura añade exactamente su rojo.

| Rotura | Resultado del cierre |
|---|---|
| A. `POST …/Accounts/{sid}/Calls.json` al simulador de Twilio (crear una llamada) → 405 | 11/12: ✗ simulador de Twilio |
| B. `POST /v1/convai/twilio/outbound-call` al de ElevenLabs → 405 | 10/12: + ✗ simulador de ElevenLabs |
| C. PHP de la instantánea, con el entorno de la pila, pide `https://api.twilio.com/2010-04-01/Accounts.json` → `ConnectionException`; la valla anota `{"kind":"connect","host":"api.twilio.com:443"}` | 9/12: + ✗ valla de red |
| D. El token de Twilio de la ejecución escrito en `laravel.log` en una línea INFO | 8/12: + ✗ secretos (el token nuevo está en el barrido) |

## Ficheros (todos en `Iron-Body_Front/e2e/ultron-studio/`)

| Fichero | Estado | Qué es |
|---|---|---|
| `mock-twilio.cjs` | nuevo (en `037a2cd`, sin cambios después) | Simulador de la API de Twilio :8095 |
| `mock-elevenlabs.cjs` | nuevo (en `037a2cd`, sin cambios después) | Simulador de ElevenLabs :8096 + emisor del webhook post-llamada |
| `specs/h6-seed.php` | nuevo | Semilla del H6 con las clases reales de la instantánea |
| `specs/h6-lib.cjs` | nuevo | Utilidades de la spec del H6 |
| `specs/h6-integrations.cjs` | nuevo | Esqueleto de la spec del H6 |
| `run.sh` | modificado | Arranque/parada de los simuladores, puertos, entorno, semilla, contexto |
| `specs/cierre.cjs` | modificado | +2 comprobaciones; las 3 tablas del H6 en el barrido |
| `README.md` | modificado | Piezas, entorno, specs, trampas 31–34, «Qué no cubre» |

`specs/h5-stack.sh` **no** necesitó cambios: reinicia servidor y workers con otras llaves y no toca simuladores ni
valla; la spec del H6 encenderá escrituras y sonda con `h5-stack.sh set ULTRON_STUDIO_INTEGRATIONS_WRITES_ENABLED=true …`
como hace `h5-governance` (los valores pasan su lista blanca). `mutations.sh` tampoco (no enumera puertos).

## Cómo se usa

```bash
bash e2e/ultron-studio/run.sh 7ba0ce8 ab78a6a                         # regresión (con un backend del H6, añade h6-integrations sola)
bash e2e/ultron-studio/run.sh <backend> <crm> h6-integrations cierre   # el esqueleto + cierre con cualquier backend
E2E_HOLD=1 bash e2e/ultron-studio/run.sh <backend> <crm> none          # pila en pie; parar con SIGTERM al proceso «bash e2e/ultron-studio/run.sh …» (trampa 34)
```

Desde una spec (`specs/h6-lib.cjs`):

```js
const H6 = require('./h6-lib.cjs');
await H6.twilio(ctx).mode('hostile');                 // ok | unauthorized | hostile | full | monitor_forbidden | server_error | garbage | slow
const n = (await H6.twilio(ctx).stats()).requests;    // y luego .requests(n) → las peticiones nuevas, saneadas
await H6.eleven(ctx).mode('pcm');                     // ok | pcm | mixed | no_audio_fields | unauthorized | forbidden | not_found | server_error | garbage | slow
const w = await H6.eleven(ctx).postCall('expired');   // {t, header, rawBody, expected: 'expired', expectedType}
H6.seed(ctx, ['verify-signature'], JSON.stringify({ rawBody: w.rawBody, header: w.header, secret: ctx.secretsData.E2E_ELEVENLABS_WEBHOOK_SECRET, now: w.now }));
H6.seed(ctx, ['sync-run', ctx.meta.adAccounts[2], 'ok', 'schedule', '1', '--touch']);   // refresca la cuenta vieja
H6.artisanEnv(ctx, ['voice:doctor', '--verify'], { ELEVENLABS_ENABLED: 'true', ELEVENLABS_API_KEY: ctx.secretsData.E2E_ELEVENLABS_KEY, ELEVENLABS_AGENT_ID: ctx.elevenlabs.agentId, ELEVENLABS_WEBHOOK_SECRET: ctx.secretsData.E2E_ELEVENLABS_WEBHOOK_SECRET });
const sa = await H6.superAdmin(ctx);                  // {token, permissions…}: autorizar/activar canarios y la sonda
```

## Las piezas

### `mock-twilio.cjs` (:8095)

- Los 5 pasos de I5.4 con la **forma pública** de Twilio (2010-04-01 con `incoming_phone_numbers`/`outgoing_caller_ids`/
  `calls` y su sobre de página; Monitor `v1/Alerts` con `meta`). La misma base sirve API y Monitor, como prevé
  `TwilioReadOnlyClient` fuera de `production`.
- Basic **obligatorio** con la credencial del OTP (SID + token) o la clave restringida (`SK…` + secreto), las dos
  generadas por ejecución. La cuenta, con la del OTP, devuelve `auth_token` **con el token real de la ejecución**, como el
  recurso de verdad: si la sonda lo guardara, el barrido de secretos del cierre lo caza.
- **Violación** (y el cierre exige 0): verbo ≠ GET (405, también en control), ruta u otro SID (404), sin Basic,
  credenciales mezcladas, `PageSize` ≠ 20/20/50/50, seguir páginas (`Page` ≠ 0, `PageToken`, `AfterSid`), otro parámetro,
  `calls` sin `StartTime>` = hoy−7 d UTC (admite hoy−8 por la medianoche; fecha o instante ISO), `alerts` sin
  `StartDate` a ±15 min de ahora−24 h, GET con cuerpo. Un token equivocado NO es violación (401 20003 y `authRejected`):
  puede ser una prueba.
- Modos en caliente (`GET /__mode?set=`): `ok` (lo de producción al congelar: Full activa, 1 número solo SMS,
  1 caller ID, 2 llamadas, 3 alertas), `unauthorized` (401 en todo), `hostile` (teléfonos, correos, `<script>`, el
  centinela, `request_variables`/`response_body`, un estado y un código de alerta inventados y `next_page_uri`/
  `next_page_url` de cebo; la de Monitor apunta a `monitor.twilio.com`, así que seguirla la caza la valla), `full`
  (páginas llenas → «N+»), `monitor_forbidden`, `server_error`, `garbage`, `slow` (`delayMs`/`delayStep`; un cliente que
  se va queda como `client_gone`).
- Registro: método, **plantilla** de ruta (`{sid}`), paso, parámetros con forma validada, tipo de credencial, estado y
  violaciones. Nunca cabeceras, credenciales ni cuerpos (comprobado: ni SID ni token ni centinela en el registro).

### `mock-elevenlabs.cjs` (:8096)

- `GET /v1/convai/agents/{agent_id}` con `xi-api-key` y `Accept: application/json` (I6.1). Respuesta con
  `conversation_config.tts.agent_output_audio_format` y `…asr.user_input_audio_format` (`ulaw_8000` en `ok`), el centinela
  y un teléfono en el cuerpo (la verificación no puede registrarlo ni devolverlo).
- **Violación**: verbo ≠ GET y, si la ruta inicia o registra llamadas (`outbound-call`, `register-call`,
  `batch-calling`), además `call_attempt`; otra ruta; `agent_id` fuera de `^[A-Za-z0-9_-]{1,64}$`; sin clave, clave en la
  consulta o cabecera `Authorization`; `Accept` sin JSON; GET con cuerpo. Clave equivocada → 401 `invalid_api_key` sin
  violación; otro agente → 404 `agent_not_found` sin violación.
- Modos: `ok`, `pcm`, `mixed`, `no_audio_fields` (→ `agentAudioUlaw8000` true/false/false/null), `unauthorized`,
  `forbidden`, `not_found`, `server_error`, `garbage`, `slow`.
- **Emisor** del webhook post-llamada (`GET /__post-call?variant=&type=&now=`, o `require(...).postCall()` sin servidor):
  `ElevenLabs-Signature: t=…,v0=HMAC-SHA256(secreto, "t.cuerpo")` con el secreto de la ejecución y el **resultado que
  exige I6.4** como oráculo independiente del PHP. 21 variantes: `valid`, `edge_past_ok` (t = now−1800), `expired`
  (now−1801), `edge_future_ok` (now+300), `from_future` (now+301), `mismatch_secret`, `mismatch_body`, `null_header`,
  `missing`, `blank`, `no_t`, `two_t`, `bad_t`, `t_13_digits`, `no_v0`, `short_v0`, `part_without_eq`, `multi_v0`,
  `uppercase`, `empty_parts`, `unknown_keys`; tipos `post_call_transcription`, `post_call_audio`,
  `call_initiation_failure` y, con `expectedType: null`, `unknown_type`, `no_type`, `invalid_json`. La firma de `valid`
  y de `mismatch_body` se recalcula en la prueba aislada.

### `specs/h6-seed.php`

Arranque de `lib/boot.php` (se niega fuera de `ultron_studio_*` de 127.0.0.1 y con `APP_ENV=production`). Una línea JSON,
sin tokens, secretos, teléfonos completos ni textos.

- `meta-ads <3 cuentas>` (lo corre `run.sh` **antes de la huella del principio**, solo si la lista trae
  `h6-integrations`): se niega si las cuentas no son exactamente `META_AD_ACCOUNT_IDS`, si `configurationProblem()` ≠ null
  o si ya está sembrado. Cuentas sintéticas `990000000001001`, `…2002`, `…3003` (la 3, **vieja**: último éxito hace 6 h
  > 180 min). 12 campañas: ACTIVE, PAUSED, ARCHIVED, IN_PROCESS, WITH_ISSUES, DELETED, sin estado y `PENDING_REVIEW`;
  nombres con series de dígitos (`3004821`, `573009990001`), uno hostil (`<img src=x onerror=alert(1)>`), uno de 255
  caracteres con emoji; `a5` posiblemente eliminada por fecha y `a6` por `synced_at` nulo; un conjunto y un anuncio (no se
  listan), una campaña de una cuenta NO conectada y un **relleno (`backfill`) más reciente** que la última pasada, que no
  debe contar. Escribe en `<run>/h6-seed.json` el **oráculo** (`possiblyDeleted`, `stale`, clasificación esperada
  `degraded/stale`), calculado desde lo sembrado y no con el código del Studio, más ids opacos y los ids de Meta (para el
  barrido de fugas).
- `canary-lead <wa_id>` (también en `run.sh`): el teléfono del lead canario por el **mismo camino que la ingesta**
  (`MarketingMessageDispatcher::normalizePhone`, solo si está vacío, como `ProcessMetaWebhookEvent::handleMessage`). Sin
  esto, la coherencia del H6 saldría `engine_canary_phone_unusable` cuando la spec corre sin `ultron-turn` delante.
- `sync-run …` (refrescar o fallar una cuenta; `--touch` actualiza `synced_at`), `marketing-calls` (3 filas hostiles con
  el centinela por stdin, para la ruta 44), `verify-signature` (verificador y sobre reales de ElevenLabs, secreto por
  stdin; `{available:false}` sin el paquete V) y `presence` (migración, tablas, clases, rutas y permisos del H6; con
  `7ba0ce8`: 0 rutas `integrations/*`).

### `specs/h6-integrations.cjs` (esqueleto) — **decisión: corre, con comprobaciones reales, y no rellena verdes**

- Lo que comprueba ya (10 con `7ba0ce8`): la fase 1 escrita explícita y apuntada a los simuladores; ElevenLabs sin
  piezas; Twilio/Meta Ads sintéticos coherentes (sin valores en el detalle); **los simuladores sin una sola petición ni
  violación hasta ahí** (P2 sobre todo lo anterior); espejo y oráculo sembrados; lead canario con teléfono; la cuenta
  Super Admin de la spec (login real, todas las llaves `ultron.*`); el Studio abre como Administrador sin errores de JS
  ni 5xx (y se anota si el CRM ya tiene «Integraciones»); y, con un backend **sin** el H6, las 8 lecturas de
  `integrations/*` → 404 (lo que el CRM traduce, I10).
- Los 15 escenarios (I11 1–9 más configuración autorizada, matriz, barrido de fugas, frontera, firma y CRM↔H5) quedan
  listados con su plan y lo que falta (S, V, FE o T) en consola y en `evidence/h6-integrations.json`; **no** se cuentan.
- **En cuanto la instantánea trae rutas del H6, registra un fallo explícito** («ESQUELETO: los 15 escenarios de I11 siguen
  sin implementar»): ninguna pasada verde con el backend del H6 puede leerse como cobertura del hito hasta el paso 4.
- `run.sh` la mete en la lista por defecto (antes de `cierre`) solo si la instantánea tiene
  `database/migrations/2026_11_01_100001_create_ultron_studio_integration_tables.php` (nombre fijo por contrato). Con
  `7ba0ce8` no entra: por eso la verificación da 410 y no más.

### `run.sh` y `cierre`

- Puertos 8095/8096 en la comprobación previa y en la de la parada; `mock-twilio` y `mock-elevenlabs` arrancados tras la
  valla y parados con el resto (`stop_one`, árbol enumerado antes de matar).
- Entorno: Twilio OTP **como en producción** (`OTP_DRIVER=twilio`, `TWILIO_SID`=`TWILIO_ACCOUNT_SID` y
  `TWILIO_TOKEN`=`TWILIO_AUTH_TOKEN`, Verify, `TWILIO_FROM=+15005550006` —número de prueba de Twilio—) con las **bases
  oficiales** del OTP; Meta Ads con token sintético, las 3 cuentas y `META_ADS_SYNC_ENABLED=true` (no hay planificador);
  y la fase 1 del H6 explícita: `ULTRON_STUDIO_INTEGRATIONS_WRITES_ENABLED=false`, `ULTRON_STUDIO_TWILIO_PROBE_ENABLED=false`,
  `ULTRON_STUDIO_CANARY_MAX_ACTIVE=3`, `ELEVENLABS_ENABLED=false`, `TWILIO_VOICE_ENABLED=false`,
  `TWILIO_VOICE_DAILY_CALL_CAP=0`, con `ULTRON_STUDIO_TWILIO_PROBE_BASE=http://127.0.0.1:8095` y
  `ELEVENLABS_BASE_URL=http://127.0.0.1:8096`. Ninguna pieza de ElevenLabs → `ELEVENLABS_NOT_CONFIGURED`.
- Secretos nuevos en `secrets.env` (0600) y por tanto en el barrido del cierre: token de Twilio, secreto de la clave
  restringida, token de Meta Ads, clave y secreto del webhook de ElevenLabs y tres **centinelas** (Twilio, ElevenLabs,
  llamadas). Los SID, el de Verify y el `agent_id` van en `context.json` (`twilio`, `elevenlabs`, `meta.adAccounts`).
- Sonda nueva al arrancar: PHP de la instantánea pide `/__stats` a los dos simuladores (200 los dos) y la valla no puede
  haber visto tráfico local. Junto a la sonda que ya había, prueba las dos mitades: lo de fuera va a la valla, lo local no.
- `cierre`: «simulador de Twilio: 0 violaciones» y «simulador de ElevenLabs: 0 violaciones»; el barrido de secretos suma
  las 3 tablas del H6 **si existen** y el texto cuenta solo las tablas que existen (con `7ba0ce8`, las 9 de siempre).
  `marketing_calls` no se barre a propósito: es donde la semilla pone su centinela.

## Decisiones y por qué

1. **Credenciales sintéticas de Twilio y Meta Ads en el entorno BASE**, no solo en la spec del H6. El simulador exige el
   SID/secreto de la instantánea, y la clasificación de I7.2 (`connected_otp_only`, `connected_read`/`degraded`) necesita
   la configuración de producción. Con ellas, la regresión del H5 corre más parecida a producción, y la verificación
   demuestra que no la alteran: 0 peticiones a los simuladores y la valla vacía en los 408. Comprobado antes en el código:
   el login del personal no pasa por OTP (`AdminAuthController`), `MetaDashboardService` no llama a Graph y la
   sincronización de Meta solo la programa el planificador, que el arnés no arranca.
2. **El token de Meta Ads es sintético y no vacío** (aunque «sin tokens» en la semilla): sin token,
   `configurationProblem()` = `missing_access_token` y el escenario 2 (campañas) sería imposible (409
   `meta_not_configured`). Si algo intentara llamar a Graph con él, la valla lo cazaría.
3. **La semilla del H6 corre en `run.sh` y solo si la lista trae `h6-integrations`**, antes de la huella del principio:
   las ejecuciones sin H6 tienen exactamente los datos de siempre, y con H6 el cierre sigue probando que los flujos del
   Studio no tocan las tablas del motor (el teléfono del lead es una tabla del motor).
4. **El Super Admin lo crea la spec** (como `autorapub` en H5), no `accounts.mjs`: la regresión del H5 conserva sus 5 cuentas.
5. **Esqueleto que falla en cuanto existen las rutas del H6** (ver arriba).
6. **Violación ≠ 401**: una credencial o clave equivocada es una prueba legítima (401 y `authRejected`); mezclar las
   credenciales, omitirlas o mandarlas por otro canal sí es violación.

## Dudas para el lead / para el fixture (no las he decidido yo)

1. **I6.4, «uno o más `v0` que casen con `^[0-9a-fA-F]{64}$`»**: ¿un `v0` mal formado junto a otro válido es
   `malformed_signature` o se ignora y vale el bueno? ¿Y `t = 0123` (ceros a la izquierda) o espacios alrededor del `=`
   (`t = 123`)? El emisor NO incluye esas variantes; el README del fixture `integrations-cases` las declarará para enmienda.
2. **I5.4, filtro de `calls`**: el contrato deja «se confirma con la documentación oficial» si `StartTime>` va con fecha o
   con instante. El simulador acepta los dos y exige el nombre `StartTime>` (en la URL, `StartTime>=AAAA-MM-DD`).
3. **Valores hostiles en `byStatus`/`byCode`/`byLevel`** (un estado `<img …>`, un código `abc<script>`, un nivel
   `ERROR' OR 1=1 --`): el contrato no dice si el saneador los descarta, los agrupa o los pasa tal cual. El modo `hostile`
   los envía; la spec afirmará lo que fije el fixture `twilioProbeSanitize`.
4. **Exit 144 de la herramienta**: no es del arnés; fue mi SIGTERM al envoltorio (trampa 34). Con SIGTERM al `run.sh`
   real, la parada fue la de siempre.

## Qué sigue sin cubrir y por qué

- **Los 15 escenarios del H6** (E1–E15 del esqueleto): no hay rutas (S), voz (V) ni pantalla (FE) en las instantáneas de
  hoy. Son el paso 4 del contrato (tester, tras S y FE).
- **Carreras, CHECK, disparadores y `down()` del H6**: son de `ops/pgunit.sh` (puerta obligatoria), no de este arnés.
- **429 de la sonda** (`ultron-studio-probe`): `CACHE_STORE=array` anula los limitadores (trampa 10).
- **El presupuesto de 30 s de la sonda** solo se puede ver con `slow` y `delayStep=all`: preparado, sin ejercitar (no hay
  sonda todavía).
- **`member_auth_challenges`/`trainer_auth_challenges`**: la semilla no siembra retos verificados, así que
  `otp.verified` saldrá 0 en el E2E; los recuentos los cubren las pruebas de S.
- **El vector oficial de `X-Twilio-Signature`** (T-1) va al fixture, no al E2E.
- Las pruebas de los simuladores son un guion del scratchpad, no un fichero del repo; si el lead lo quiere en el arnés, se
  añade como `e2e/ultron-studio/lib/test-mocks.cjs`.
