# Informe V9 · la voz por estrategia (Hito 9): la decisión de una estrategia publicada llega a la ÚNICA puerta de salientes del H8, sin ninguna llamada real

**Paquete:** V9 (`backend`). **Fecha:** 10-11-2026. **Árbol:** `_worktrees/ultron-h8/Iron-Body/backend`, rama `feat/ultron-studio-h9` (= `origin/main` `d701b0c8` al empezar), **sin commit**. **Contrato:** H9 D9-9.4, D9-10, §9.3, §10 (fila V9), §11 (V9), P9-16; H8 D8-12, D8-13, D8-18, Enmienda 16 (E16-21: 28 motivos, E16-25: idempotencia antes de la política). Todo apagado y sin red: las dos constantes de código siguen en `false` en el árbol compartido; la vía abierta se ensaya solo en una copia del árbol.

---

## 0. Resumen en cifras

| Qué | Resultado |
|---|---|
| Ficheros del H8 tocados | **1 existente** (`OutboundGate.php`: +270, **0 líneas quitadas**) y **1 migración nueva** (`2026_11_13_100001`). Nada más en `app/Services/{Telephony,Voice}`, `app/Jobs/Voice`, `config/voice.php` ni pruebas del H8 |
| Ficheros del H9 | 1 reescrito (la puerta vacía `VoiceStrategyChannel`), 3 nuevos (`Run/VoiceCallOutcomes`, `Facts/VoiceCallStates`, orden `outreach:voice-outcomes`) |
| Pruebas nuevas | `tests/Feature/Outreach/V9/`: 6 ficheros + 1 rasgo; **71 pruebas** |
| V9 en el árbol compartido | SQLite **71 · 0 fallos** (21 729 aserciones; 28 omitidas: 25 de la vía abierta + 3 solo PG) · PostgreSQL **71 · 0 fallos** (21 768; 26 omitidas: 25 de la vía abierta + 1 solo SQLite), corrida ANTES del último ajuste del normalizador (saltar los trabajos ya cerrados); después, su fichero en PG: 8/8 |
| Vía abierta en una COPIA (`v9-abierta.sh`) | SQLite **25/25** (220 aserciones) · PostgreSQL **25/25** (220) |
| Telefonía del H8 (`tests/Feature/Telephony` + `tests/Unit/Telephony`) | SQLite **455 · 0 fallos** (131 omitidas, solo PG). PostgreSQL **471 · 2 fallos**: `VoiceSchemaTest.php:455` (esta migración, G-8) y `VoiceCommandsTest.php:61` (de otro paquete que está añadiendo `webhookHealth` al estado de la voz; no es de V9) |
| Guardas pedidas (tanda final) | `tests/Feature/Outreach/Guards` + `OutreachIsolationTest` + `UltronEngineIsolationTest` + `TelephonyIsolationTest`: **44 pruebas, 1 fallo**: `OutreachStaticGuardsTest.php:182`, la importación de `OutboundGate` que D9-2 manda «en V9» (§6, G-1). Lo demás, en verde |
| Llamadas reales, red, n8n, OTP, Legacy, producción | **Ninguna** |

---

## 1. Diseño

```
Despachador (etapa 6: reevalúa TODO, escalera N1–N7)
   │  solo si la escalera deja salir por `voice_call` (hoy NUNCA: §4)
   ▼
VoiceStrategyChannel::attempt()          ← app/Services/Outreach/Run/Channels
   0. ¿voz declarada en OutreachSendGate::LIVE_CHANNELS?   no → failed_to_start `channel_not_live`   (hoy: no)
   1. trabajo `voice_call` y en vivo                        no → `channel_mismatch`
   2. maestra en `live` · COMMERCIAL_AUTOMATION_VOICE_ENABLED   → `mode_off` · `channel_disabled`
   3. OutreachSendGate::SENDS_ENABLED_IN_CODE               no → `sends_disabled_in_code`          (hoy: false)
   4. cupo y presupuesto RESERVADOS y no liberados          no → `budget_not_reserved`
   5. persona resuelta · consentimiento de voz del HECHO para la finalidad → `unknown_subject` · `no_consent_for_purpose_channel`
   ▼  UNA petición, sin reintentos
OutboundGate::requestForStrategy(jobUuid, purposeKey, memberIds, leadIds, canaryPart, strategyKey)   ← app/Services/Telephony
   · validación de forma (422 `strategy_request_invalid`, sin tocar nada)
   · transacción · VoiceLocks::gate() (el cerrojo de la puerta, primera sentencia)
   · DESTINO (lo elige la puerta): el contacto de voz ACTIVO de esas fichas con consentimiento para la finalidad
     (el más reciente); canario SOLO en la parte canaria de la estrategia + alcance `canary` (4a) + consentimiento
   · IDEMPOTENCIA por trabajo, antes de la política (como H-4): mismo trabajo → 200 misma fila; otro destino → 409
   · LOS MISMOS HECHOS → OutboundPolicy::decide() (los 28 motivos del H8, intactos)
   · 202: fila `requested` (solicitante `strategy`, sello, reserva), evento `outbound_requested`, PlaceOutboundVoiceCall (al confirmar)
   ▼
PlaceOutboundVoiceCall (H8, SIN CAMBIOS): vuelve a mirarlo todo y es el ÚNICO que marca (worker `ironbody-voice`)
   ▼
VoiceCallOutcomes (normalizador, D9-10)  ← lee voice_calls/eventos/exclusiones por Facts\VoiceCallStates
   → OutcomeRecorder (fuente `voice_call`): sent · answered · no_answer · failed · opted_out · human_requested
```

**Lo que dice la puerta, en el trabajo** (`VoiceStrategyChannel::fromGate()`, pura):
- 202 (o 200, la misma llamada del trabajo) → `started` con `voice_call_id` (la referencia, una vez); sin `callId`, `ambiguous` (`voice_call_ref_missing`), nunca `failed` (liberaría la reserva de algo que puede existir);
- cualquier negativa (409 con motivos, 409 de carrera o de identificador reutilizado, 422) → `failed_to_start` con el **primer** motivo como `detail_code` (el orden cerrado de `OutboundPolicy::REASONS`: lo más de raíz primero); el despachador (sin cambios) libera la reserva y registra `failed` (fuente `system`) con ese detalle; nadie reintenta;
- una excepción (no se sabe si la fila llegó a confirmarse: p. ej. el `afterCommit` del job) → `ambiguous` (`voice_gate_error`); el normalizador la resuelve por el uuid del trabajo (§1.2).

### 1.1 El destino (D9-9.4, «nunca un canario salvo en modo canario»)

`OutboundGate::strategyTarget()` (`OutboundGate.php:536`): contactos `voice_contacts` ACTIVOS con `source_type`/`source_id` de las fichas de la persona (las que resuelve `Facts\SubjectResolver` desde la clave del trabajo), del más reciente al más antiguo; el primero con `VoiceContacts::hasConsent()` (la regla de cuatro ojos del H8). Solo `commercial` tiene consentimiento de voz en el H8 (`vcs_purpose_chk`): para `collections` y `service` el destino sale **sin consentimiento** y la política dice `no_voice_consent` (D9-15). Sin contacto, la política dice `contact_not_authorized` + `no_voice_consent` + `rne_check_missing`. En la parte canaria **y** con el alcance `canary`: el canario de voz activo que es **ese mismo número** (`VoiceCanaryDirectory::lookup`), y solo si el contacto tiene consentimiento; si no, el contacto, y la 4a responde `not_an_active_voice_canary`. El job del H8 vuelve a mirar ese destino antes de marcar con `targetOf()` (sin cambios).

### 1.2 El normalizador (D9-10, sin interpretar transcripciones)

`Run\VoiceCallOutcomes::run()` (orden `outreach:voice-outcomes`, **sin programar**): trabajos `voice_call` en vivo `started`/`ambiguous` de los últimos 8 días que aún NO tienen su resultado terminal de la voz (≤ 200 por pasada: el lote es siempre de llamadas pendientes). Un `ambiguous` sin referencia se enlaza por su uuid (la llamada lo lleva como `client_request_id`; `voice_call_id` una vez); si pasados 15 min no hay llamada, nunca la habrá (la puerta confirma o deshace en una transacción) → `failed` `voice_call_absent`. El mapa (`map()`, puro; claves `vc:<uuid>:sent` y `vc:<uuid>:end`, una de cada por llamada):

| Llamada (H8) | Resultado H9 (fuente `voice_call`) | Detalle |
|---|---|---|
| el proveedor la aceptó (conversación o SID) | `sent` (no terminal) | — |
| exclusión `spoken_opt_out` de ESA llamada (`no_volver_a_llamar`) | `opted_out` **en el acto**, aunque siga viva; gana a todo | `no_volver_a_llamar` |
| final + herramienta `solicitar_humano` | `human_requested` | `solicitar_humano` |
| `completed` (post-llamada procesada) | `answered` | `guard_clean` / `guard_violation` / nulo |
| `busy` · `no_answer` | `no_answer` | `busy` · `no_answer` |
| `failed` · `failed_to_start` | `failed` | su `failure_code` |
| `canceled` (el job del H8 la canceló antes de marcar) | `failed` | el `policy_reason` del H8 |
| `hung_up` (corte de emergencia) | `failed` | `hung_up` |
| `requested`, `ambiguous`, `dialing`, `active` | nada (se espera) | — |

Los efectos y seguimientos son los de `OutcomeRecorder` (sin cambios): `opted_out` cancela todo lo pendiente de la persona en todas las estrategias y la suprime; `human_requested` → `handed_off`; `no_answer`/`failed` siguen el `on` del paso (reintento de negocio = otra fila, otro trabajo, otro uuid). **El coste**: el real lo escribe el H8 en la llamada (`provider_cost_micros` + `carrier_cost_micros`) y el trabajo lo alcanza por `voice_call_id`; la pasada devuelve la suma (`costMicros`). `outreach_jobs` no tiene columna de coste real (§5, D-V9-8).

---

## 2. Diff enumerado del H8 — **Enmienda 17 (propuesta)**

| Fichero | Cambio | Qué NO cambia |
|---|---|---|
| `app/Services/Telephony/OutboundGate.php` | **Solo añadido** (`git diff`: +270, −0): constantes `REQUESTER_STRATEGY`, `STRATEGY_PURPOSES`, `VOICE_CONSENT_PURPOSES`, `STRATEGY_KEY_PATTERN`, `STRATEGY_REQUESTER_LABEL`, `STRATEGY_MAX_SOURCES` (`:55-79`); métodos `requestForStrategy()` (`:370`), `strategyRequestErrors()` (`:397`), `decideForStrategy()` (`:430`), `strategyTarget()` (`:536`) | `request()`, `decide()`, `targetFacts()`, `targetOf()`, `canaryTarget()`, `contactTarget()`, `currentScope()` byte a byte. Pint pasa sobre el fichero sin tocar nada |
| `database/migrations/2026_11_13_100001_allow_strategy_requester_on_voice_calls.php` | **Nueva**. Solo PostgreSQL: `vc_requester_chk` DROP + ADD en una sentencia (las dos ramas de DB8 literales + la rama `strategy`: sin cuenta ni rol, `client_request_id` = el trabajo, nombre `^Estrategia [a-z0-9][a-z0-9_-]{2,47}$`, `attempt = 1`); `lock_timeout` 5 s si la sesión no trae uno; recuento antes/después (51 CHECK en `voice_%`, uno con ese nombre); se niega a ampliar dos veces. `down()` se niega con filas `strategy` y, sin ellas, restaura el CHECK EXACTO de DB8 (lo lee de `2026_11_10_100002::rowChecks()`). SQLite: sin efecto (DB8: los CHECK de forma son solo de PG) | Ninguna columna, índice, disparador ni privilegio de `voice_*` |
| `OutboundPolicy`, `REASONS` (28), `VoiceCallPolicy::CALLS_ENABLED_IN_CODE`, `PlaceOutboundVoiceCall`, `InboundGate`, `VoiceCallsQuery`, `config/voice.php`, plantilla y compilador | **Sin cambios** | — |

**Lo que la Enmienda 17 cambia de principio:** «una automatización no llama» pasa a «solo una estrategia publicada a cuatro ojos llama, POR LA PUERTA» (D9-9.4). El token compartido sigue sin poder llamar (`telephony` sigue excluido; ninguna ruta nueva). El comentario de `CrmPermission.php:254-266` que cita el contrato (§10) **no lo toqué** (`app/Support/Access/**` es de B9-run/lead): sigue siendo cierto mientras la voz no esté declarada (§4).

---

## 3. Diff del H9

| Fichero | Qué |
|---|---|
| `app/Services/Outreach/Run/Channels/VoiceStrategyChannel.php` | La puerta vacía de B9-run, ahora real (§1). Importa de la telefonía **solo** `OutboundGate` (D9-2: «en V9, `OutboundGate::requestForStrategy`») |
| `app/Services/Outreach/Run/VoiceCallOutcomes.php` | Nuevo: el normalizador (§1.2). Escribe solo por `OutcomeRecorder` y el enlace `voice_call_id` (una vez) de un `ambiguous` |
| `app/Services/Outreach/Facts/VoiceCallStates.php` | Nuevo adaptador de SOLO LECTURA (`voice_calls`, `voice_call_events`, `voice_exclusions`): códigos, instantes, cifras; nunca huellas, 4 dígitos ni textos |
| `app/Console/Commands/Outreach/OutreachVoiceOutcomesCommand.php` | Nuevo: `outreach:voice-outcomes [--json]`; apagada la maestra, no hace nada y lo dice |
| `tests/Feature/Outreach/V9/**` | 6 pruebas + `Support/V9World.php` (§8) |
| `docs/ultron-studio/ops/v9-abierta.sh` | Nuevo: la vía abierta en una COPIA del árbol (patrón `h8-e2e.sh`, §8) |

No toqué `OutreachDispatcher.php`, `OutcomeRecorder.php`, `OutreachConfig.php`, `config/outreach.php`, `WhatsappTemplateChannel.php`, `app/Services/Marketing/**`, `OutreachSendGate.php` (W9 la editó: no la toqué), `LadderInputBuilder.php`, `OutreachPlanner.php`, guardas, rutas, n8n, OTP ni Legacy.

---

## 4. Por qué HOY nada llega a la puerta (y qué falta para que llegue)

Cuatro cerrojos de código, independientes, y todos cerrados en el árbol:
1. `OutreachSendGate::LIVE_CHANNELS` no declara `voice_call` → la escalera niega `channel_not_live` (N4) y el canal también;
2. `OutreachSendGate::SENDS_ENABLED_IN_CODE = false` → N4 `sends_disabled_in_code` y el canal también;
3. la voz no tiene presupuesto ni cupo en la configuración del H9 → N4 `budget_zero` y la reserva del planificador (techo 0) se agota;
4. `VoiceCallPolicy::CALLS_ENABLED_IN_CODE = false` → la puerta del H8 niega `calls_disabled_in_code`.

`V9OpenGateTest::test_el_despachador_no_llega_al_canal_sin_presupuesto_ni_cupo_de_voz_en_el_h9` lo demuestra en la copia: aun con 1, 2 y 4 abiertos, el despachador cancela por `budget_zero`.

---

## 5. Decisiones y desviaciones (para que el lead las confirme)

| Id | Decisión | Por qué |
|---|---|---|
| **D-V9-1 · `purposeKey`** | **No toqué la plantilla `ultron-voice-template/2` ni el compilador.** La finalidad viaja como `purposeKey` a la puerta (lista cerrada `commercial`, `collections`, `service`, `OutboundGate.php:63`), se valida (422), decide el consentimiento (solo `commercial` lo tiene) y queda en el evento `outbound_requested` (`codes.purposeKey`). **Desviación del encargo: lo decide el lead.** | Compilar una variable dinámica nueva NO es «solo añadir»: `VoiceAgentSpec::VARIABLES` es un conjunto cerrado (`app/Services/Voice/Dto/VoiceAgentSpec.php:97`) que `OutboundCallRequest` exige EXACTO (`OutboundCallRequest.php:74`), así que cambia el job (`PlaceOutboundVoiceCall.php:243`), la respuesta de la iniciación (`InboundGate.php:219`), la plantilla (`StudioVoiceTemplate::DYNAMIC_VARIABLES`, `:138`; su `hash()` y su regla de subir `VERSION`, que arrastra `VoiceTranscriptAllowlist::TEMPLATE_VERSION`, `:46`), las 882 cajas de `tests/fixtures/voice/compiler-cases.json` y los cuerpos de ElevenLabs, el banco (simuladores) y el espejo del CRM. Es un paquete S8 + B8 + TEST8 + FE8 con regeneración de fixtures. Mientras tanto, D9-9.4 ya dice qué pasa: «hasta entonces, las estrategias de voz se simulan», y la única finalidad con consentimiento de voz es la comercial, que es lo que el agente actual ya dice («te atiendo para informarte sobre nuestros planes y servicios»). Ojo: la plantilla de VOZ del H9 de S9 valida `purposeKey` contra `OutreachCatalog::GOALS` (`StudioMessageTemplates.php:591`, L9-41), no contra las finalidades; no la cambié |
| **D-V9-2 · sin `voice_calls.outreach_job_uuid`** | Solo reescribí `vc_requester_chk`; la referencia del trabajo es `client_request_id` y la idempotencia por trabajo se resuelve bajo el cerrojo de la puerta (`VoiceLocks::gate()`, primera sentencia) | Una columna (y su UNIQUE) cambia el esquema de `voice_calls` en los DOS motores y rompe `VoiceSchemaTest` (columnas exactas e ida y vuelta) también en SQLite; además las columnas inmutables de DB8 van por LISTA en disparadores (habría que rehacer `vc_forbid_immutable_update`). El respaldo en la base sigue: `vc_one_live_outbound` (una viva por número), y en el H9 el trabajo único (`ojob_idempotency_unique`), `ojob_voice_call_unique` y la reclamación condicionada. Si el lead quiere el UNIQUE en la base, es trabajo de `database-specialist` con su anexo de pruebas del H8 |
| **D-V9-3 · la voz NO declarada** | El canal comprueba `OutreachSendGate::channelLive('voice_call')` primero y responde `channel_not_live` | Declararla es UNA línea en `OutreachSendGate.php:41` (que W9 acaba de editar: no la toqué) con su guarda (`OutreachStaticGuardsTest.php:110`) y los ganchos de presupuesto (§6). Es un cerrojo de código más hasta la Enmienda 17, y mantiene la parte de voz de `OutreachDispatcherTest::test_20` |
| **D-V9-4 · el código** | Uso `sends_disabled_in_code` | El encargo dice `sends_closed_in_code`; el conjunto cerrado de N4 (`PolicyLadder.php:68`), los fixtures y `DecisionTraceText` dicen `sends_disabled_in_code` |
| **D-V9-5 · el detalle** | `detail_code` = el PRIMER motivo de la puerta; la lista entera queda en el registro `voice` (`voice.outbound.denied`, sin teléfonos) | `outreach_outcomes.detail_code` es UN código `^[a-z][a-z0-9_]{0,47}$`; `voice_policy:<motivo>` no cabe y el despachador (que no puedo tocar) solo pasa `detailCode` |
| **D-V9-6 · excepciones** | `ambiguous` (`voice_gate_error`), nunca `failed_to_start` | Tras el `commit` puede fallar el `afterCommit` del job: la fila existiría y liberar la reserva sería mentir. El normalizador la enlaza o, pasados 15 min sin fila, la cierra `failed` (`voice_call_absent`) |
| **D-V9-7 · la vía abierta** | Una COPIA del árbol (`v9-abierta.sh`) que gira las dos constantes y declara la voz, y comprueba que el giro cambió solo esas 3 líneas | La puerta del H8 lee la constante sin costura posible sin cambiar código del H8 (`VoiceCallPolicy::decide()`). W9 eligió otro camino (una costura `?Closure $sendsEnabledInCode` en `LadderInputBuilder`); para la voz no la añadí: no abro un desvío de la protección 2 |
| **D-V9-8 · el coste** | Alcanzable desde el trabajo por `voice_call_id` y sumado en la pasada; sin columna | `outreach_jobs` no tiene coste real y añadirlo es una enmienda de DB9 |
| **D-V9-9 · el normalizador** | Orden propia sin programar y sin fila en `outreach_runs` | `routes/console.php` es de OPS9; `outreach_runs.kind` es un conjunto cerrado (`ingest`… `replay`) |
| **D-V9-10 · el actor** | La puerta confía en su único llamador (el canal, tras la escalera); no puede comprobar que el trabajo sea de una estrategia publicada sin leer tablas del H9 (frontera D8-2) | Lo vigila `V9NoBypassStaticTest` (`requestForStrategy` solo desde el canal). Riesgo residual para SEC: alguien con consola puede llamar al servicio directamente (como cualquier servicio) |

---

## 6. Ganchos que necesito de otros dueños (fichero:línea y cambio mínimo)

| # | Dónde | Cambio mínimo | Dueño |
|---|---|---|---|
| G-1 | `tests/Feature/Outreach/Guards/OutreachStaticGuardsTest.php:70-72`, la condición de importaciones (`:176`) y la aserción `:182` | Con el MISMO patrón que la guarda ya da a W9 (excepción por fichero): `V9_GATE_CALLER = 'app/Services/Outreach/Run/Channels/VoiceStrategyChannel.php'`, `V9_GATE_IMPORTS = ['App\\Services\\Telephony\\OutboundGate']` y, como `:144` hace con W9, exigir en ese fichero una sola llamada `->requestForStrategy(` y ninguna `->request(` (D9-2 lo manda «en V9»). Hoy, con su versión actual, `:182` cae SOLO por `VoiceStrategyChannel.php: App\Services\Telephony\OutboundGate` (lo mismo lo prueba ya `V9NoBypassStaticTest`) | SEC9 / lead |
| G-2 | `app/Services/Outreach/Run/OutreachSendGate.php:41` + `OutreachStaticGuardsTest.php:110` | Declarar la voz: `LIVE_CHANNELS = [..., 'voice_call']` y la guarda con la lista de cuatro | Lead, con la Enmienda 17 aprobada |
| G-3 | `config/outreach.php:102-104` + `OutreachConfig.php:101` | `voice.daily_cap` (`COMMERCIAL_AUTOMATION_VOICE_DAILY_CAP`, 0, recorte 0–20 como `TWILIO_VOICE_DAILY_CALL_CAP`), `voice.daily_budget_usd` (`…_VOICE_DAILY_BUDGET_USD`, 0) y `voice.reserve_usd_per_call` (`…_VOICE_RESERVE_USD`, 0; la reserva del H8 por llamada, `VoiceCostGuard::reserveMicros`, pide ≥ 0,97 US$ con los precios de E16-8) y sus lectores | B9-run / lead |
| G-4 | `app/Services/Outreach/Run/LadderInputBuilder.php:174-177` | Para `voice_call`: `dailyBudgetMicros`, `costMicros`, `channelDailyCap` de G-3 (hoy 0 → `budget_zero`) | B9-run / lead |
| G-5 | `app/Services/Outreach/Run/OutreachPlanner.php:644-645` | La parcela `channel:voice_call` con la reserva y los techos de G-3 (hoy techo 0) | B9-run / lead |
| G-6 | `app/Services/Outreach/Run/OutreachMonitor.php:171-172` | El techo de gasto de `voice_call` en el freno por `budget_breach` | B9-run / lead |
| G-7 | `routes/console.php:499-523` (bloque del H9) | `outreach:voice-outcomes` cada 5 min tras su bandera (o llamarlo desde `outreach:dispatch` como la conciliación) | OPS9 |
| G-8 | `tests/Feature/Telephony/Schema/VoiceSchemaTest.php:454-455` | Tras `$m->up();`, volver a aplicar la ampliación de V9 antes de comparar, como un `migrate` real: `(require database_path('migrations/2026_11_13_100001_allow_strategy_requester_on_voice_calls.php'))->up();`. Solo cae en PostgreSQL (en SQLite la migración no toca nada). Es un fichero del H8: sumar, no cambiar el 51 | Lead (L9-36/L9-38) |
| G-9 | `docs/ultron-studio/ops/deploy-h9-backend-fase1.sh:98-106` | La puerta de diff exige CERO diff en `Voice`/`Telephony`: V9 necesita su propio guion o una excepción enumerada (`OutboundGate.php` y la migración) | OPS |
| G-10 | `tests/Feature/Outreach/Run/OutreachDispatcherTest.php:267-276` (`test_20`) | Su parte de voz sigue verde (`channel_not_live`); cuando G-2 declare la voz, pasará a `channel_mismatch` con el trabajo asistido que usa: la expectativa de la voz cambia con la declaración | Lead (con G-2) |
| G-11 | `app/Services/Telephony/OutboundGate.php:19-41` (cabecera de la clase) y `app/Support/Access/CrmPermission.php:254-266` | Los dos comentarios dicen que la única forma de pedir una llamada es una persona / «una automatización no llama». Con la Enmienda 17 aprobada: «… o una estrategia publicada a cuatro ojos, por `requestForStrategy()`». No los toqué (código existente del H8 / `Access` de B9-run) | Lead |

---

## 7. Contratos, variables y migración

- **Nuevo (PHP, servidor):** `OutboundGate::requestForStrategy(string $jobUuid, string $purposeKey, list<int> $memberIds, list<int> $leadIds, bool $canaryPart, string $strategyKey): array{http, body, callId}`. `http`: 202 (aceptada, `body.call` = `VoiceCallsQuery::row()`), 200 (misma llamada del trabajo, `body.duplicate = true`), 409 `outbound_denied` + `reasons` (los de `OutboundPolicy`), 409 `outbound_race` / `client_request_reused`, 422 `strategy_request_invalid` + `reasons` ⊂ {`job_uuid_invalid`, `purpose_key_invalid`, `subject_invalid`, `strategy_key_invalid`}. Nunca lanza por la política.
- **Base:** `voice_calls.requested_by_type` admite `strategy` (PG). La lista de llamadas del CRM mostrará `requestedBy` = «Estrategia <clave>» sin cambios en `VoiceCallsQuery` (lee `requested_by_name`). Ningún cambio de API HTTP, ruta ni permiso.
- **Orden:** `outreach:voice-outcomes [--json]`.
- **Variables de entorno nuevas:** ninguna en V9 (las de G-3 las añadiría su dueño, apagadas).
- **Migración a ejecutar:** `2026_11_13_100001_allow_strategy_requester_on_voice_calls` (en PG, un `ALTER TABLE voice_calls` con ACCESS EXCLUSIVE breve; `voice_calls` está vacía en producción a la fecha; `lock_timeout` 5 s; en SQLite no hace nada). Nunca `migrate:rollback` en producción; `down()` existe para el banco y se niega con filas `strategy`.

---

## 8. Qué verifiqué (comando → resultado)

```
php -d memory_limit=2G vendor/bin/phpunit --no-logging tests/Feature/Outreach/V9
  → OK, but some tests were skipped! Tests: 71, Assertions: 21725, Skipped: 28            # SQLite
UNIT_DB=ultron_studio_v9 docs/ultron-studio/ops/pgunit.sh --no-logging tests/Feature/Outreach/V9
  → OK, but some tests were skipped! Tests: 71, Assertions: 21768, Skipped: 26            # PostgreSQL 14 desechable
docs/ultron-studio/ops/v9-abierta.sh sqlite   → copia, 3 líneas giradas · OK (25 tests, 220 assertions)
docs/ultron-studio/ops/v9-abierta.sh pg       → copia, 3 líneas giradas · OK (25 tests, 220 assertions)   # ultron_studio_v9_abierta
php -d memory_limit=2G vendor/bin/phpunit --no-logging tests/Feature/Telephony tests/Unit/Telephony
  → Tests: 455, Assertions: 195894, Skipped: 131 · 0 fallos                                 # SQLite
UNIT_DB=ultron_studio_v9 pgunit.sh --no-logging tests/Feature/Telephony tests/Unit/Telephony
  → Tests: 471, Assertions: 205197, Failures: 2: VoiceSchemaTest.php:455 (G-8, por esta migración: la ida y vuelta de DB8
    restaura su CHECK) y VoiceCommandsTest.php:61 (otro paquete, en curso, añade `webhookHealth` a VoiceTelephonyStatus).
    Antes, en una corrida solo de Schema, cayó además VoiceSuppression1581Test (fichero NUEVO de ese paquete, sin seguimiento,
    sobre `supresion-1581.sql` mientras lo editaba: filas de `voice_exclusions`, que V9 no toca); en la corrida completa ya no
UNIT_DB=ultron_studio_v9 pgunit.sh --no-logging tests/Feature/Outreach/V9/V9VoiceOutcomesTest.php → OK (8 tests, 89 assertions)
php -d memory_limit=2G vendor/bin/phpunit --no-logging tests/Feature/Outreach/Guards tests/Feature/UltronStudio/OutreachIsolationTest.php \
    tests/Feature/UltronStudio/UltronEngineIsolationTest.php tests/Feature/Telephony/TelephonyIsolationTest.php
  → Tests: 44, Assertions: 151625, Failures: 1 → OutreachStaticGuardsTest.php:182, la importación de OutboundGate (G-1)
    (una primera corrida, antes de que W9 adaptara sus guardas, dio además 2 rojos de W9 en UltronEngineIsolationTest y 2 en Guards)
php -d memory_limit=2G vendor/bin/phpunit --no-logging tests/Feature/Outreach/Run → Tests: 69, Assertions: 1168, Skipped: 2 · 0 fallos (las cifras de L9-74)
vendor/bin/pint --test <mis ficheros> → passed (OutboundGate.php pasa sin que Pint toque nada)
```

**Qué prueba cada fichero de `tests/Feature/Outreach/V9`:**
- `V9StrategyGateTest` (24): con todo en regla la única negativa es `calls_disabled_in_code` y no se crea nada; **paridad uno a uno con la entrada de las personas** en 20 mundos (todo en regla, sin publicación y 18 motivos provocables sin llamada previa, incluida una cabeza de la cadena de voz FALSIFICADA → `publication_unreadable`); destino y consentimiento por finalidad; nunca un canario fuera de la parte canaria ni sin consentimiento; 422 sin tocar nada; idempotencia por trabajo y 409 con otro destino.
- `V9VoiceChannelTest` (6): en sombra nada se pide (planificador + despachador de verdad: trabajo simulado, `not_attempted`, cero filas de voz); en vivo con la constante cerrada la escalera cancela por `sends_disabled_in_code` (N4) y no llega a la puerta; sin consentimiento, N1 `no_consent_for_purpose_channel` antes que nada; el canal se niega con `channel_not_live`; los 28 motivos → `failed_to_start` con su detalle; el despachador asienta la referencia o libera la reserva con `failed` y el detalle.
- `V9VoiceOutcomesTest` (8): el mapa puro; cada estado final una sola vez con su seguimiento; `opted_out` en el acto y cancela lo pendiente en otra estrategia; `solicitar_humano` → `handed_off`; ambiguo enlazado / ausente a los 15 min; coste; solo lee la voz (escuchador de consultas); apagada la maestra no hace nada.
- `V9RequesterCheckTest` (4): en SQLite la migración no toca el esquema; en PG el CHECK admite la estrategia y rechaza 9 formas malas (con `violatedChecks`: solo `vc_requester_chk`), conserva DB8, `down()` se niega con filas y la ida y vuelta deja lo mismo; no amplía dos veces.
- `V9NoBypassStaticTest` (4): de la telefonía, el H9 solo nombra `OutboundGate` en el canal y solo llama a `requestForStrategy`; ningún identificador del job, el iniciador, los adaptadores, la política o los modelos de voz en el H9, ni escrituras de `voice_*` (constructor o SQL crudo); `requestForStrategy` solo desde el canal; la entrada lleva el cerrojo primero, `OutboundPolicy::decide()` una vez, los mismos 16 hechos que `decide()`, `targetFacts`, el job al confirmar y nunca marca; `requested_by_type` y el job de la saliente solo en la puerta.
- `V9OpenGateTest` (25, **solo en la copia**): la petición llega a la puerta (202, fila `requested` con el solicitante `strategy`, evento, job en cola; repetirla es la misma llamada); sin worker sigue `requested`; con el worker FALSO pasa a `dialing` y el normalizador registra `sent`; 22 motivos → `failed_to_start` + detalle, sin fila, reserva liberada y `failed`; sin consentimiento el canal no pide; el despachador no llega sin presupuesto de voz (G-3..G-5).

---

## 9. Qué exige el propietario antes de que una estrategia llame de verdad

1. **La Enmienda 17 del H8** aprobada (este diff), con la revisión de `security` + `api-security-specialist` y `reviewer`.
2. **Fases 4a (con evidencia) y 4b del H8** activas (E16-16, P9-16): `voice(calls-open)` desplegado, worker `ironbody-voice`, cupo y presupuesto de voz, publicación canaria, número vigilado, ElevenLabs verificado (la lista de `ULTRON_STUDIO_H8_PREPARACION_PLAN_PAGO.md`).
3. **Consentimientos de voz** reales por persona (`voice_contacts` + `voice_consents`, cuatro ojos; hoy además tras `VOICE_CONTACTS_ENABLED`, del paquete que trabaja en paralelo) y consultas al RNE vigentes.
4. **El visto bueno legal** (Ley 1581, Ley 2300 arts. 2, 3 y 5, RNE; P9-15, P9-22).
5. **La constante del H9 abierta** (`outreach(sends-open):`, commit de una línea con la autorización escrita) y **G-2 a G-7** hechos por sus dueños; la variable `COMMERCIAL_AUTOMATION_VOICE_ENABLED` y los topes de G-3.
6. **D-V9-1** decidido (variable `purposeKey` en el agente, o voz solo comercial con el agente actual).

---

## 10. Qué NO verifiqué

- La telefonía en SQLite se corrió ANTES de que el otro paquete añadiera sus pruebas (455); la de PostgreSQL, después (471).
- La suite completa del backend (regresión por nombre): no la corrí (máquina cargada por otros agentes; L9-49).
- Mutaciones: ninguna pasada.
- Nada contra Twilio, ElevenLabs, el banco del H8 (`h8-e2e.sh`) ni producción; ninguna llamada real.
- El efecto en la evidencia del canario del H8: las salientes de estrategia en la parte canaria cuentan como llamadas selladas de esa publicación (`VoiceEvidence`); no lo medí.

## 11. Para `security` y `api-security-specialist`

- **El principio cambia** (R9-4): con la Enmienda 17 una automatización SÍ puede pedir una saliente, solo por `requestForStrategy()`; revisad que la puerta recorre exactamente la política del H8 (`V9StrategyGateTest`: paridad en 20 mundos; `V9NoBypassStaticTest`: los mismos 16 hechos, el cerrojo primero, el job al confirmar).
- **D-V9-10:** la puerta no puede comprobar que el trabajo sea de una estrategia publicada (no lee tablas del H9); confía en su único llamador, el canal, que solo corre tras la escalera N1–N7. Un operador con consola puede llamar al servicio a mano (como a cualquier servicio).
- **Sin superficie HTTP nueva:** ni rutas, ni permisos, ni el token compartido (`telephony` sigue excluido).
- **Datos personales:** el teléfono solo se descifra en memoria (lo hace `contactTarget()`, sin cambios); en la base, huella y 4 dígitos; los registros nuevos (`voice.outbound.denied/requested` con `requester`, `job_uuid`, `target_source`; `outreach.voice.gate_error`, `outreach.voice.outcome`) llevan códigos y uuids, nunca números, nombres ni textos. `requested_by_name` guarda «Estrategia <clave>», no una persona.
- **Consentimiento:** dos veces antes de la puerta (N1 de la escalera y el hecho en el canal) y otra en la puerta y en el job (`VoiceContacts::hasConsent`, cuatro ojos). Cobranza y servicio, nunca por voz hoy.

## 12. Casos límite para `tester`

Una estrategia que se revierte entre `requestForStrategy` (202) y el job del H8 (el job vuelve a mirar la cadena de VOZ, no la del H9: la llamada sale si la voz sigue publicada); dos trabajos de la misma persona en dos estrategias el mismo día (lo para el presupuesto de contacto del H9 y la frecuencia del H8); una persona con dos contactos con consentimiento (el más reciente); el contacto retirado entre la puerta y el job (`contact_not_authorized` en el job: `canceled` → `failed` en el H9); `no_volver_a_llamar` en una llamada de otra estrategia de la misma persona; una post-llamada que llega antes de que el job escriba `dialing` (DF-S2 del H8: el normalizador lo ve igual).
