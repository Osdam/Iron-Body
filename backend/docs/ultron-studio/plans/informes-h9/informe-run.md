# Informe B9-run · la ejecución del motor de autonomía comercial (Hito 9)

**Paquete:** B9-run (`backend`). **Contrato:** `docs/ultron-studio/ULTRON_STUDIO_H9_CONTRACT.md` (D9-8, D9-9, D9-10, D9-14, §6.2, §9, §13.1 y L9-1..L9-44). **Árbol:** `_worktrees/ultron-h8/Iron-Body` (rama `feat/ultron-studio-h8-voice`, H9 sin confirmar). **Fecha:** 09/10-11-2026. **Sin commit, sin red, sin tocar producción.**

## 0. Resumen en cifras

| Qué | Cifra |
|---|---|
| Ficheros nuevos de código | 47 (`app/Services/Outreach/Run/**` 36, `app/Jobs/Outreach` 1, `app/Console/Commands/Outreach` 7, `app/Http/Controllers/Crm/UltronStudio/UltronStudioOutreach*` 2, `config/outreach.php`), ≈ 7 000 líneas con su PHPDoc |
| Ficheros de prueba | 9 (`tests/Feature/Outreach/Run/**` 8 + `Support/RunWorld.php`), ≈ 2 200 líneas |
| Pruebas | **45 en SQLite** (643 aserciones; 1 omitida: la carrera, que es solo PG) · **45 en PG** (`UNIT_DB=ultron_studio_h9_run`, 672 aserciones, la carrera #16 incluida; 0 filas sobrantes al acabar) |
| Pruebas de §13.1 cubiertas | **Corregido por TEST9-c (10-11-2026, REV9 M-3):** lo que esta fila afirmaba no era exacto (#23/#25 solo tenían el gobierno; #6, #7, el freno por umbral de #18 y #13 a nivel de ejecución no tenían prueba). La correspondencia número a número, con el fichero y el método de cada una, está en §0.1; las pruebas nuevas de TEST9-c viven en `tests/Feature/Outreach/Run/H9*Test.php` y su informe es `informe-tester-c.md` |
| Ficheros ajenos tocados | **Ninguno** (ni `AuthorizationMap`, ni rutas, ni `.env.example`, ni `routes/console.php`, ni `Core/**`, ni `Facts/**`) |
| Red | Cero: `Http::preventStrayRequests()` en las 8 suites; el espacio `Outreach\Run` no nombra `Http::`, Meta, Twilio, ElevenLabs ni n8n |
| Pint | En verde en todo lo mío |
| Mutantes por muestreo | 8 a mano, uno a uno, restaurando el fichero por bytes tras cada uno: **8/8 cazados** (§5.1) |

### 0.1 Cobertura de §13.1, número a número (TEST9-c, 10-11-2026; sustituye a la fila de arriba)

Cada fila nombra la prueba que ejercita el número EN LA EJECUCIÓN (B9-run) y, cuando la hay, la del gobierno (S9), la pura (B9-core) o la de la base (DB9). «PG» = solo PostgreSQL (`pgunit.sh`). Las dos pruebas de regresión de D-1 (`informe-tester-c.md`, L9-65) son `H9LeadFollowupTest::test_6_una_autorizacion_que_llega_tarde…` y `::test_6_la_llamada_pedida_por_el_personal…`: rojas sobre el código del 09-11, verdes con la corrección del lead del 10-11.

| # | Prueba del propietario | Dónde está | Estado |
|---|---|---|---|
| 1 | Vence en N días | `OutreachPlannerTest::test_1_la_ventana_de_vencimiento_configurable…` | ✓ |
| 2 | Vence hoy | `OutreachPlannerTest::test_2_dias_before_cero_inscribe_a_quien_vence_hoy…` | ✓ |
| 3 | Vencido hace N días | `OutreachPlannerTest::test_3_la_ventana_de_vencida_respeta_el_intervalo…` | ✓ |
| 4 | Renueva antes del contacto | `OutreachDispatcherTest::test_4_quien_renueva_entre_planificar_y_despachar…` | ✓ |
| 5 | Dos campañas simultáneas | `OutreachPolicyTest::test_5_dos_estrategias_para_la_misma_persona…` (ejecución, los dos motores) · `OutreachConcurrencyTest::test_dos_estrategias_planifican_a_la_misma_persona…` (base, PG) | ✓ |
| 6 | Prospecto con solicitud expresa de seguimiento | **`H9LeadFollowupTest`** (TEST9-c): `test_6_el_prospecto_con_seguimiento_vencido_y_autorizacion_expresa…` y `test_6_sin_autorizacion_expresa_n1_niega…` (barrido + despacho en sombra) · pura: `H9PolicyLadderN1Test::test_prospecto_sin_autorizacion_expresa_no_se_contacta` · hechos: `LeadAndVoiceFactsTest::test_opt_out_comercial_vale_el_ultimo_gesto_y_seguimientos_vencidos` | ✓ (+ las dos de regresión de D-1) |
| 7 | Invitación a evento con segmento autorizado | **`H9EventSegmentTest`** (TEST9-c): clase con cupo, `converted` al rechequear y `valuation.outdated` · hechos: `AgendaEngineHistoryFactsTest::test_ocurrencias_con_cupo…`, `::test_prefiltro_de_valoracion_caducada` | ✓ |
| 8 | Sin consentimiento para llamadas | `H9PolicyLadderN1Test` (4 métodos) | ✓ (pura) |
| 9 | WhatsApp sí, voz no | `H9ChannelSelectorTest` (2) | ✓ (pura) |
| 10 | No recibir más contactos | `OutreachOutcomesTest::test_10_opted_out_sale_cancela_todo_lo_pendiente…` | ✓ |
| 11 | Gestionado por otro canal | `OutreachPolicyTest::test_11_tras_una_llamada_hace_dos_dias…` | ✓ |
| 12 | Reglas contradictorias | `H9ConflictingStrategiesTest` (3) | ✓ (pura) |
| 13 | Dos workers, la misma oportunidad | **`H9IdempotencyRaceTest::test_13_dos_trabajadores_reales…`** (TEST9-c, PG: dos procesos del planificador tras una barrera) · base: `OutreachConcurrencyTest::test_dos_trabajadores_inscriben_la_misma_ocurrencia…` (PG) · secuencial: `OutreachPlannerTest::test_14` (`already_enrolled`) | ✓ |
| 14 | Evento duplicado | `OutreachPlannerTest::test_14_la_misma_ocurrencia_entregada_dos_veces…` | ✓ |
| 15 | Cambio de estado mientras el job espera | `OutreachDispatcherTest::test_15_si_la_audiencia_deja_de_cumplirse…` | ✓ |
| 16 | Presupuesto diario agotado | `OutreachRaceTest::test_16_con_techo_3_cinco_reservas_simultaneas…` (PG) · base: `OutreachConcurrencyTest::test_dos_reservas…` (PG) | ✓ |
| 17 | Horario o día no permitido | `H9ScheduleTest` (2, con casos) | ✓ (pura) |
| 18 | Error de Twilio (y el freno por umbral, P9-20) | `OutreachDispatcherTest::test_18_19_un_error_del_proveedor…` (resultado `failed` con el detalle, sin reintento, reserva liberada) · **`H9MonitorThresholdsTest`** (TEST9-c): `opt_out_spike`, `failure_spike`, `budget_breach` | ✓ |
| 19 | Error de ElevenLabs | `OutreachDispatcherTest::test_18_19…` (el trabajo no queda vivo) | ✓ |
| 20 | n8n no disponible | `OutreachDispatcherTest::test_20_las_puertas_de_whatsapp_y_voz_estan_vacias…` | ✓ |
| 21 | Respuesta ambigua | `H9OutcomeTableTest` (3, pura) · ejecución: `OutreachOutcomesTest::test_el_veredicto_needs_review_para_la_secuencia…` | ✓ |
| 22 | Revisión humana | `OutreachOutcomesTest::test_22_la_bandeja_reclama_una_vez…` · `OutreachDispatcherTest::test_22_en_vivo_el_trabajo_asistido…` · `OutreachApiTest::test_la_bandeja_asistida_por_http…` | ✓ |
| 23 | Cancelación inmediata de una campaña | **`H9PauseCancelTest`** (TEST9-c, 3: etapa 6 `strategy_paused`, la reserva en vivo, la planificación) · gobierno: `UltronStudioStrategyRollbackTest::test_23` | ✓ |
| 24 | Restauración tras una falla | `OutreachDispatcherTest::test_la_conciliacion_cierra_lo_que_quedo_a_medias…` · `OutreachPolicyTest::test_t12_replay…` · `H9RollbackTest::test_13_3_una_reversion_durante_dispatching…` y `::test_25_la_conciliacion_cancela…` (`canceledByChain`, M-4/L9-66) (todos también en PG) | ✓ |
| 25 | Reversión de una versión aprobada | **`H9RollbackTest`** (TEST9-c, 5: etapa 6 `strategy_moved`, la conciliación de las abiertas sin trabajo vivo frente a una pausa (M-4/L9-66), la planificación, la restaurada con sello nuevo, `dispatching`) · **`H9ChainOrphansTest`** (TEST9-c, 5; L9-69 y L9-72): firma rota, reversión falsificada en la cabeza y rotación de `APP_KEY` (nadie cancela, todo espera), contenido ilegible (`catalog_incompatible`), promoción canario → resto (Q-M4, `strategy_moved`) · gobierno: `UltronStudioStrategyRollbackTest::test_25` | ✓ |
| 26 | Nueva estrategia desde el CRM (E2E) | — (TEST9-b, `e2e/h9-nueva-estrategia`) | pendiente |

## 1. Qué entrega, por fichero

### 1.1 `app/Services/Outreach/Run/` (la ejecución, D9-2)

| Fichero | Qué es |
|---|---|
| `OutreachConfig` | El único lector de `config('outreach.*')`: maestra, modo (`off`/`shadow`/`live`, otro = `off`), programador, monitor, canales, cupos, presupuesto (micros), horario propio (`OutboundSchedule::windows`, mal escrito = `invalid` = todo cerrado), límites efectivos (`ContactBudget::effectiveLimits`), umbral de cartera (P9-7), `human_requested` 30 días (L9-41), lotes y carril |
| `OutreachSendGate` | **`SENDS_ENABLED_IN_CODE = false`** (un literal, una vez; el mando lo busca en `app/Services/Outreach/*/OutreachSendGate.php`); `channelFlags()` da `channelEnabled`/`channelLive`/`sendsEnabledInCode` a N4; `channelLive` solo `simulated` y `assisted` el 15-11 |
| `OutreachPublishedStrategies` | **El puente** (D9-2): lee por SQL `ultron_studio_strategies/_strategy_versions/_strategy_publications/_message_templates/_person_bindings` e `identities`; verifica cada entrada con `Core\StrategyChainSignature` (+ `row_mismatch` como S9); relee el contenido con `StrategyContent::fromStored`; `liveStrategies()`, `runnableStrategies()`, `liveVersion()`, `contentOfVersion()`, `headVerified()`, `verifyChain()`, `personOf()`/`samePerson()` (L9-7), `templateState()`, `assistedScript()`. Importa del Studio SOLO `StudioStrategyValidator::studioReference` (N3, integridad sin estado, D9-11.4) y las ayudas puras de `StudioMessageTemplates` (Q-1) |
| `LiveStrategy` | La versión viva como DTO: sello, contenido canónico, condiciones (`audience`/`exclusions`/`success`/`guard`), `uses()`, `runnable()`, `paused()` |
| `OutreachStop` | PARADA GLOBAL y frenos: abrir (persona o consola; `openBySystem` para el monitor, con incidente IRON GUARD fuente `outreach`), soltar a cuatro ojos (otra cuenta **y** otra persona, L9-7; nota `PlainText` estricta sin teléfonos ni correos), `openScopes()`, `ladderBrakes()`, `list()` |
| `OutreachMonitor` | Solo apaga (D9-14): cadena ilegible → global / estrategia; deriva (trabajos vivos de una versión no activa) → estrategia; opt-out > 5 ‰·10 (50 ‰) en 24 h → estrategia; fallos > 200 ‰ → canal; gasto reservado > techo → canal. `unknown_send` queda para W9/V9 (exige leer mensajes y llamadas) |
| `OutreachRuns` | Las pasadas: una fila por pasada, cerrada una vez, `counts` SIEMPRE un objeto JSON (≤ 4 KB) |
| `OutreachStore` | El almacén de filas: inscripciones (UNIQUE → null), trabajos (idempotency sha256(uuid|paso|intento), `ojob_one_live` → null), decisiones (**traza como lista `[traza]`**, hechos ≤ 4 KB con recorte determinista), transiciones condicionadas, cursores solo hacia delante, lecturas de apoyo |
| `TriggerPrefilters` | **La única correspondencia disparador → prefiltro (L9-41)** sobre los adaptadores de B9-facts; `membership.expiring_window` = [hoy, hoy + N]; sin prefiltro para `crm_fact` |
| `BudgetReservations` | Reserva **solo** por `UPDATE … WHERE reserved + n <= cap AND reserved + n <= <techo actual>` (`reserveSql()` público para la carrera); parcelas `channel:`, `strategy:<uuid>`, `phone:<ref>` (L9-2, L9-30); liberar; usado hoy |
| `SubjectSuppressions` | La supresión derivada del libro (hasta C9): `opted_out` → (finalidad, medio); `wrong_person` → medio |
| `LadderInputBuilder` | La entrada de `PolicyLadder::SPEC` completa: `CoreProjection::policyInput` + banderas, frenos propios, supresión, N3, plantilla, cartera, presupuesto, estrategia, contexto, N7 y **`phone` siempre** (L9-33: `refs: []` si desconocido) |
| `OutreachPlanner` | Etapas 1–5: `ingest()` (cursor `commercial_events:<modo>`, `appointment_created` → `appointment_booked`), `sweep()` (cadencia del catálogo, ≤ 500/pasada, PARADA GLOBAL → pasada `aborted`), `evaluateCandidates()` (clave de ciclo, puerta pura, cupo diario de inscripciones, enfriamiento, recuentos), `planDue()` (arbitraje por persona, escalera por canal, selector, hueco + desfase determinista, reserva atómica, trabajo `planned`; sombra = `simulated` sin reserva) |
| `OutreachDispatcher` | Etapas 6 y 7: reclamación `FOR UPDATE OF j SKIP LOCKED` (PG) + UPDATE condicionado; reevaluación TOCTOU; canales; `cancel` con decisión y reserva liberada |
| `Channels/*` | `OutreachChannel`, `ChannelAttempt`, `SimulatedChannel`, `AssistedChannel`, puertas vacías `WhatsappTemplateChannel` y `VoiceStrategyChannel` (`failed_to_start`/`channel_not_live`) |
| `OutcomeRecorder` | Etapa 8 (D9-10): `OutcomeTable::resolve`, `insertOrIgnore`, duplicado sin avanzar, desconocido → incidente, efectos (`opted_out` cancela todo lo vivo de la persona), `needs_review` → `handed_off` guardado como `human_requested` + `detail_code = needs_review` (Q-5) |
| `FollowUpScheduler` | Etapa 9 con `FollowUpMachine`; `blocked` + `wait` se re-mira como pronto a las 24 h |
| `OutreachReconciler` | Durabilidad: `claimed`/`dispatching` > 10 min → `ambiguous` + incidente; `planned` vencidos → `expired` + reserva liberada; ventanas → `expired` + `no_response` |
| `OutreachReplay` | D9-12: reevalúa con la foto, el instante y las ENTRADAS de la escalera guardadas en la traza; `same`/`different`/`not_reproducible` |
| `AssistedQueue` | La bandeja: cola (sin teléfonos; `person: {kind, id}` para la ficha), reclamar una vez, resultado `staff` idempotente, solo quien reclamó |
| `OutreachAccess` | Persona + llave (`CrmPermission::allows`), sin `ultron.view` implícito; las 6 llaves de D9-13 |
| `OutreachReadModels` | `status`, `metrics`, listas paginadas (≤ 50) y detalles por uuid; historial por `subjectRef` (`SubjectTimeline`) |
| `Stream/Outreach{Runs,Jobs,Outcomes,Brakes}Topic` | Los temas SSE `outreach.*` (interfaz `StreamTopic` de SSE9-b, incluido `listRoute()`) |
| `OutreachException`, `OutreachTables`, `BudgetExhausted`, `JobCollision` | Apoyo |

### 1.2 Consola (`app/Console/Commands/Outreach/`)

`outreach:stop --all|--strategy=|--channel= --reason= [--by=]`, `outreach:monitor`, `outreach:sweep [--only=ingest|sweep|plan] [--force]`, `outreach:dispatch [--limit=] [--inline] [--no-reconcile]`, `outreach:reconcile`, `outreach:replay <uuid>`, `outreach:suppress-subject <m:id|l:id> [--apply]`. Todas admiten `--json`; apagadas no escriben y salen con 0 diciéndolo.

### 1.3 Cola y API

`app/Jobs/Outreach/DispatchOutreachJob` (carril `commercial`, `tries = 1`, `timeout = 60`); `UltronStudioOutreachController` (E1–E10 + `ROUTES`, `routes()`, `authorizationMap()`) y `UltronStudioOutreachAssistedController` (E11–E13).

## 2. Para el lead (los traspasos de §11)

### 2.1 Rutas: montar con una línea al FINAL de `routes/ultron-studio.php`, dentro del grupo `admin/ultron-studio`

```php
// Hito 9 · B9-run: la ejecución (13 rutas outreach/**; contrato §7 E1–E13, L9-43).
\App\Http\Controllers\Crm\UltronStudio\UltronStudioOutreachController::routes();
```

### 2.2 `AuthorizationMap::OVERRIDES` (bloque literal; lo genera `UltronStudioOutreachController::authorizationMap()`)

```php
'GET api/admin/ultron-studio/outreach/status' => 'commercial_automation.view',
'GET api/admin/ultron-studio/outreach/metrics' => 'commercial_automation.view',
'GET api/admin/ultron-studio/outreach/enrollments' => 'commercial_automation.view',
'GET api/admin/ultron-studio/outreach/enrollments/{uuid}' => 'commercial_automation.view',
'GET api/admin/ultron-studio/outreach/decisions/{uuid}' => 'commercial_automation.view',
'GET api/admin/ultron-studio/outreach/jobs' => 'commercial_automation.view',
'GET api/admin/ultron-studio/outreach/brakes' => 'commercial_automation.view',
'POST api/admin/ultron-studio/outreach/brakes' => 'commercial_automation.stop',
'POST api/admin/ultron-studio/outreach/brakes/{uuid}/release' => 'commercial_automation.release',
'GET api/admin/ultron-studio/outreach/people/{subjectRef}/timeline' => 'commercial_automation.timeline_view',
'GET api/admin/ultron-studio/outreach/assisted/queue' => 'commercial_automation.assisted_work',
'POST api/admin/ultron-studio/outreach/assisted/{uuid}/claim' => 'commercial_automation.assisted_work',
'POST api/admin/ultron-studio/outreach/assisted/{uuid}/outcome' => 'commercial_automation.assisted_work',
```

### 2.3 Permisos (D9-13) para `PermissionCatalog` y `CrmPermission`

Llaves: `commercial_automation.view`, `commercial_automation.simulate_real` (ya la usa S9), `commercial_automation.timeline_view`, `commercial_automation.assisted_work`, `commercial_automation.stop`, `commercial_automation.release`. Reparto P9-21: `view`, `simulate_real`, `timeline_view`, `assisted_work`, `stop` → Super Admin y Administrador; `release` → solo Super Admin. El dominio `commercial_automation` entra en `SHARED_TOKEN_EXCLUDED_DOMAINS`. Los temas SSE `outreach.*` declaran `listRoute()` = el GET de lista de su módulo (`status`, `jobs`, `enrollments`, `brakes`) y permiso `commercial_automation.view`.

### 2.4 Variables del ejemplo de entorno (bloque del H9, todo apagado)

```
# ULTRON · Automatización comercial (Hito 9, B9-run): TODO APAGADO. Solo true/1/on/yes enciende.
COMMERCIAL_AUTOMATION_ENABLED=false
COMMERCIAL_AUTOMATION_MODE=off
COMMERCIAL_AUTOMATION_SCHEDULER_ENABLED=false
COMMERCIAL_AUTOMATION_MONITOR_ENABLED=false
COMMERCIAL_AUTOMATION_ASSISTED_ENABLED=false
COMMERCIAL_AUTOMATION_ASSISTED_DAILY_CAP=0
COMMERCIAL_AUTOMATION_WHATSAPP_ENABLED=false
COMMERCIAL_AUTOMATION_WHATSAPP_DAILY_CAP=0
COMMERCIAL_AUTOMATION_WHATSAPP_DAILY_BUDGET_USD=0
COMMERCIAL_AUTOMATION_VOICE_ENABLED=false
COMMERCIAL_AUTOMATION_CLASSIFIER_ENABLED=false
COMMERCIAL_AUTOMATION_OWN_HOURS=
# Opcionales (defectos del contrato): COMMERCIAL_AUTOMATION_PER_WEEK, COMMERCIAL_AUTOMATION_MIN_HOURS_BETWEEN (solo endurecen),
# COMMERCIAL_AUTOMATION_COLLECTIONS_BLOCK_DAYS=15, COMMERCIAL_AUTOMATION_OPT_OUT_PERMILLE=50, COMMERCIAL_AUTOMATION_FAILURE_PERMILLE=200,
# COMMERCIAL_AUTOMATION_MONITOR_MIN_SAMPLE=5, OUTREACH_WHATSAPP_PRICE_MARKETING_USD, OUTREACH_WHATSAPP_PRICE_UTILITY_USD (W9).
```

### 2.5 El bloque del programador (OPS9, `routes/console.php`): 2 tareas + 1 — YA EN EL ÁRBOL, COMENTADO

OPS9 ya dejó el bloque en `routes/console.php` dentro de `/* … */`, a la espera de que B9-run confirme los tres nombres. **Confirmados** (`php artisan list outreach`: las 7 órdenes registradas por autodescubrimiento): `outreach:sweep` (ingesta por cursor + barrido con la cadencia del catálogo + planificación), `outreach:dispatch` (conciliación + reclamación ≤ 50 + despacho) y `outreach:monitor`. OPS9 puede quitar el comentario. Su cadencia (10 min / 1 min / 10 min, `withoutOverlapping(10)`, `onOneServer()`) me vale: la promesa de la PARADA GLOBAL (≤ 60 s) la cumple el despacho de cada minuto y el barrido se mide en días; con el bloque comentado, `schedule:list` con las dos banderas encendidas no lista nada (inerte, comprobado).

**Un punto para OPS9 antes de encender (Q-9):** `outreach:dispatch` sin `--inline` ENCOLA cada trabajo reclamado como `DispatchOutreachJob` en el carril `commercial` (`config('outreach.queue.lane')`). Si ningún worker consume esa cola, los trabajos se quedan `claimed` y a los 10 min la conciliación los marca `ambiguous` con incidente. O los workers de producción añaden `commercial` a sus colas, o la tarea del programador lleva `--inline` (procesa en la misma pasada, ≤ 50 trabajos, cada uno en su transacción: suficiente para la sombra y el asistido).

### 2.6 Para SSE9-b

Sustituir en `StreamTopics::defaults()` los cuatro `PendingTopic('outreach.*', …)` por `new OutreachRunsTopic`, `new OutreachJobsTopic`, `new OutreachOutcomesTopic`, `new OutreachBrakesTopic` (`App\Services\Outreach\Run\Stream\*`, sin argumentos). Cursores: `<unix de updated_at>-<id>` en pasadas, trabajos y frenos (cambian en su sitio); `id` en resultados. Permiso `commercial_automation.view`; `data` sin seudónimos ni nombres (probado).

### 2.7 Para S9

`TriggerPrefilters::page($trigger, $now, 500, 0)` es la correspondencia única (L9-41) que la simulación sobre muestra real puede usar en lugar de la suya; la ventana de vencimiento es [hoy, hoy + `daysBefore`] (las dos ya coinciden).

### 2.8 Tabla `waitsForRelease`/`effect` (L9-25): qué trabajo se cancela y cuál espera

| Lo que decide | En la planificación (etapas 3–5) | En el despacho (etapa 6, ya reclamado) |
|---|---|---|
| `defer` (temporal) | decisión `defer`; la inscripción vuelve a tocar en `retryAt` (si `retryAt` ≥ fin de ventana: `expired` + `no_response`) | el trabajo se cancela con el motivo; la inscripción vuelve a tocar en `retryAt` (otro intento = otra fila) |
| N2 (`waitsForRelease`: frenos, banderas, latch, agente, voz, persona al mando, legado) | decisión `deny`; la inscripción espera 15 min y se vuelve a mirar | con el freno echado NO se reclama (los `planned` esperan); si cae tras reclamar: cancelado con el motivo y la inscripción espera 15 min |
| N2 `global_suppression` | `blocked` → `end_exit`: la inscripción sale (`global_suppression`) | ídem, cancelado |
| N6 `success_reached` (`exit_converted`) | `converted` (`system`), inscripción `completed` | cancelado `success_reached` + `converted` |
| N6 `human_requested_recently` (`handoff`) | decisión `handoff`, inscripción `handed_off` | cancelado + `handed_off` |
| N6 `context_changed` | `blocked` con `end_exit`, inscripción `exited` (`context_changed`) | cancelado `context_changed` + ídem |
| Negativa permanente (N1 consentimiento, N3, N4, N5) | `blocked` con el motivo; `on.blocked` (defecto `wait`: se re-mira ≥ 24 h, hasta la ventana) | cancelado con el motivo + el mismo `blocked` |
| Cadena: versión fuera (`strategy_moved`) | inscripción `canceled` | trabajo cancelado `strategy_moved` + inscripción `canceled` |
| Cadena: pausa | la inscripción espera 15 min (no se cancela) | trabajo cancelado `strategy_paused`; la inscripción espera (#23) |

## 3. Decisiones estrictas fuera de la letra (para que el lead las confirme)

1. **Traza como lista.** `odec_trace_chk` (DB9) exige un ARRAY JSON; `DecisionTrace::build()` (B9-core) devuelve un objeto. Guardo `[traza]` y `trace_hash = sha256(bytes guardados)`; `replay` rehace el mismo envoltorio.
2. **`needs_review` se guarda como `human_requested` + `detail_code = needs_review`.** Un `ambiguous` del clasificador no cabe en PG (`oout_next_action_chk` pide `next_action` nulo y `oout_classifier_brakes_chk` lo pide no nulo). L9-12 dice que es un veredicto: la fila dice la derivación.
3. **Consola sin cuenta.** `outreach:stop` sin `--by=` queda a nombre de la cuenta centinela `0` (`consola:outreach:stop`): la base exige persona en `manual`. Soltarlo exige igualmente una persona con la llave y una nota.
4. **Negativa permanente ≠ muerte de la inscripción.** `blocked` con `on.blocked` (defecto `wait`) y re-mira como pronto a las 24 h: una autorización que llegue después (C9) sí se aprovecha dentro de la ventana; `context_changed` y `global_suppression` sí salen.
5. **Contactos asistidos sin resultado.** `ContactHistoryFacts` cuenta el asistido cuando el personal registra; hasta entonces la ejecución añade al historial de la escalera los `started` en vivo de los últimos 8 días sin resultado `staff` (sin duplicar).
6. **Cursor por modo** (`commercial_events:shadow` / `:live`): una sombra no consume las ocurrencias del vivo.
7. **El cupo del contador se eleva, nunca se baja:** la reserva comprueba el techo de la fila Y el de la configuración de ahora.
8. **En la etapa 6, una reanudación (seq nueva) NO cancela:** solo cancela si la versión/parte ya no está viva o está pausada; el sello es inmutable y la reinscripción reproduciría la cancelación en bucle.
9. **`inCanaryScope` siempre es el del medio** (también en la parte `rest`): N3 `canary_only_declared` lo necesita cuando la línea base del Studio declara «solo canario» (hoy, siempre).

## 4. Q-n para el lead

| Q | Duda | Lo que hice (lo más estricto) |
|---|---|---|
| Q-1 | D9-2 limita `Outreach` al puente; el puente importa `StudioStrategyValidator::studioReference` (N3) y `StudioMessageTemplates::usesPaymentLink/violationsOf` (la única implementación de cada regla) | Importados SOLO en el puente; SEC9 debe admitirlo en su lista blanca o pedir que S9 exponga una fachada pura |
| Q-2 | Los temas SSE importan `UltronStudio\Stream\StreamTopic/StreamEvent` (orden del lead) | Hecho; anotado para SEC9 |
| Q-3 | `human_requested` → «en live puede abrir una tarea asistida si la estrategia lo pide»: el contenido no tiene ese campo | No se abre ninguna tarea; la inscripción queda `handed_off` (el Inbox es el camino) |
| Q-4 | El acceso al historial «queda en el libro» (D9-13); el libro es del Studio (S9) | Queda en el log de la aplicación (`outreach.timeline.viewed` con cuenta y seudónimo); si debe ir al libro, S9 expone un método y lo llamo desde el puente |
| Q-5 | §13.1 #13 (dos trabajadores) y #5 (trabajo vivo) en PG | Las carreras DB-nivel están en `OutreachConcurrencyTest` (DB9) sobre las mismas tablas; la ejecución las recoge (`already_enrolled`, `JobCollision`) y lo pruebo en SQLite y PG de forma secuencial; la carrera real de la reserva (#16) es mía (`OutreachRaceTest`, solo PG) |
| Q-6 | `unknown_send` del monitor | Para W9/V9: leer `marketing_messages`/`voice_calls` es de `Facts` (D9-2) |
| Q-7 | `COMMERCIAL_EVENTS_ENABLED=false` (producción hoy) hace que N6 niegue TODO por `human_requested_recently` desconocido (L9-26) | Fiel a L9-26; P9-24 antes de la sombra. Las pruebas lo encienden |
| Q-8 | Permisos `commercial_automation.*` no están en `PermissionCatalog` | No toco `app/Support/Access/**` (orden del lead): bloque en §2.3; las pruebas los conceden por `role_permissions` como S9 |
| Q-9 | El despacho encola en el carril `commercial`; no sé si en producción hay worker para esa cola | Para OPS9: worker con `commercial` o `outreach:dispatch --inline` en el programador (§2.5); sin una de las dos, `claimed` → `ambiguous` a los 10 min |

## 5. Qué verifiqué, con comandos

```
php -d memory_limit=2G vendor/bin/phpunit --no-logging tests/Feature/Outreach/Run
  → OK (45 tests, 643 assertions, 1 skipped)           # SQLite
UNIT_DB=ultron_studio_h9_run docs/ultron-studio/ops/pgunit.sh --no-logging tests/Feature/Outreach/Run
  → OK (45 tests, 672 assertions)                      # PostgreSQL 14 desechable, carrera #16 incluida
php -d memory_limit=2G vendor/bin/phpunit --no-logging tests/Unit/Outreach tests/Feature/Outreach/Facts tests/Feature/UltronStudio/UltronEngineIsolationTest.php
  → 1 fallo esperado y ajeno: el recuento de rutas del Studio (108 ≠ 77). La propia guarda lo dice en su
    cabecera: «77 → 107 con las 30 de S9 y 108 con el canal SSE … se adaptan EN LA RAMA DEL H9 (L9-36,
    L9-38, L9-43), no aquí». Las otras dos pruebas de la guarda (nadie fuera del Studio nombra sus clases;
    los lectores y el puente solo leen sus tablas) pasan con mis 47 ficheros.
php -d memory_limit=2G vendor/bin/phpunit --no-logging tests/Feature/UltronStudio/Stream tests/Feature/UltronStudio/Strategies
  → 64 pruebas, 2 fallos ajenos, los dos en `UltronStudioStrategyRoutesTest`: «actual size 108 matches
    expected size 107» (la constante de S9 no cuenta la ruta del canal SSE: L9-38, se ajusta en la rama) y
    `test_authorization_map_clasifica_cada_ruta_con_su_llave` (las rutas `strategies/**` de S9 → null en el
    mapa: el bloque que pega el lead). Nada mío aparece en ninguno de los dos; `routes/ultron-studio.php` y
    `routes/console.php` figuran modificados en el árbol por otros paquetes, sin una sola línea `outreach`
php -d memory_limit=2G vendor/bin/phpunit --no-logging tests/Feature/Outreach/Schema/OutreachLifecycleTest.php → OK (232)
vendor/bin/pint app/Services/Outreach/Run app/Jobs/Outreach app/Console/Commands/Outreach \
  app/Http/Controllers/Crm/UltronStudio/UltronStudioOutreach*.php config/outreach.php tests/Feature/Outreach/Run → passed
```

Lo que la puerta PG cazó y SQLite no: el CHECK de la traza (array), el de `counts` (objeto), `version_id` nulo en pausas y la regla de cuatro ojos de los vínculos (quien verifica ≠ quien crea).

### 5.1 Mutantes por muestreo (a mano, uno a uno; el fichero se restaura por bytes tras cada uno)

| # | Mutante | Prueba que lo caza (`--filter`) | Resultado |
|---|---|---|---|
| M1 | `channelFlags()` dice `sendsEnabledInCode = true` | `OutreachStopAndGateTest` `test_todo_nace_apagado` | cazado |
| M2 | soltar un freno comparando solo cuentas, no personas | `OutreachStopAndGateTest` `test_soltar_exige` | cazado |
| M3 | la reclamación ignora el freno de estrategia | `OutreachDispatcherTest` `test_15t` | cazado |
| M4 | la reserva deja de mirar el techo de la fila (`cap_units + 100`) | `OutreachRaceTest` (PG) | **sobrevivió** a la primera pasada: los dos techos valían 3 y el de la configuración tapaba al de la fila. La prueba ahora los separa (fila 3 / configuración 10 niega; fila 10 / configuración 3 niega; 10/10 concede una) → cazado; con el mutante salta además el CHECK `obud_limits_chk` de la base, la red de debajo |
| M5 | resultados con `insert` en vez de `insertOrIgnore` | `OutreachOutcomesTest` `test_t14_cada_fuente` (el duplicado) | cazado |
| M6 | la supresión no llega a N2 (`globalSuppression = false`) | `OutreachOutcomesTest` `test_10` | cazado |
| M7 | la deriva no echa el freno | `OutreachStopAndGateTest` `deriva\|ya_no_esta_activa` | cazado |
| M8 | el planificador no mira la ocurrencia ya inscrita | `OutreachPlannerTest` `test_2\|test_14` | cazado |

## 6. Qué NO verifiqué

- `outreach:suppress-subject`: probada la forma del SQL del dueño por lectura; no ejecutada como dueño (OPS9, con orden escrita).
- La cola real (`DispatchOutreachJob` por `commercial`): las pruebas despachan en línea (`--inline` / `QUEUE_CONNECTION=sync`).
- Rendimiento (PERF9).

## 7. Supresión 1581 (`outreach:suppress-subject`)

La aplicación para lo vivo (cancela trabajos `suppressed_1581`, libera reservas, cierra inscripciones abiertas). La forma suprimida (`x:<id>`, `subject_ref` = 64 ceros, sin socio ni lead, `phone_ref` NULL, `redacted_at`, contadores `phone:` borrados) la escribe el DUEÑO con los disparadores apagados: la orden imprime el SQL exacto. Nunca desde la aplicación (columnas inmutables, `REVOKE`).

## 8. Estado al cierre

Nada abierto de mi lado: lo que falta depende del lead (§2: la línea de rutas, el bloque del mapa, las llaves, las variables, el programador y los cuatro temas en SSE9-b) y de las Q-n (§4).

Trampa que costó una hora y que conviene saber para cualquier prueba PG con conexión de apoyo: `RefreshDatabase` deja la conexión por defecto dentro de UNA transacción. Un UPDATE hecho por ella sobre una fila que la conexión de apoyo va a tocar después (aquí, el `release()` del contador justo antes del DELETE del `tearDown`) deja la fila bloqueada hasta que la prueba acaba… y la prueba no acaba porque el `tearDown` espera el candado. PHPUnit muere con «Maximum execution time of 0+2 seconds exceeded» y la puerta PG entera se queda colgada (`pg_stat_activity`: la de la prueba «idle in transaction», la de apoyo esperando `Lock transactionid`). Regla: en estas pruebas, TODO lo que toque filas confirmadas va por la conexión de apoyo; y la puerta se lanza con `perl -e 'alarm 600; exec @ARGV' env UNIT_DB=… pgunit.sh …` (en macOS no hay `timeout`).
