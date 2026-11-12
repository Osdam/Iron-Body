# Informe de security · Quejas que frenan el contacto (Q1-1, Q1-2, Q1-3), replay y día de Bogotá del escáner

**12-11-2026 00:58 UTC (11-11, 19:58 de Bogotá) · `security` · solo lectura.** Árbol `_worktrees/ultron-h8/Iron-Body/backend`, rama `feat/ultron-studio-h9`, HEAD `9502884a`; el cambio revisado está sin commit. No he tocado código, pruebas, contratos, producción ni la red. `git status --porcelain` es igual antes y después (comprobado con `diff`), salvo este informe. Las sondas están fuera del repositorio, en el directorio temporal de la sesión.

Marcas: **[C]** comprobado ejecutando · **[L]** leído en el código · **[S]** supuesto.

## Veredicto

**APTO CON CONDICIONES para desplegar esta noche.** El cambio no tiene ningún hallazgo bloqueante. No he encontrado ningún camino por el que una persona con una queja dentro de la ventana llegue a `allow` en una finalidad comercial o de cobranza, con la configuración por defecto, que es la que se despliega. Si la fuente no tiene datos, el freno falla cerrado.

Condiciones:
1. **La hora.** Desplegar a partir de las **00:00 de Bogotá (05:00 UTC del 12-11)**. Ahora son las 19:58 de Bogotá, dentro de la franja 19:00–23:59 en la que la familia de enlaces abandonados puede emitir un `payment_pending` de más por transacción (§6). Hoy el daño es nulo: 0 estrategias y el NBA apagado. Aun así, la condición es barata.
2. **Antes de publicar la primera estrategia comercial o de cobranza** (no esta noche): I-2 (Ley 1581), M-1 (valores de configuración que apagan el freno) y M-5 (bandeja asistida).
3. **Adyacente, sin relación con este cambio pero vivo desde hoy (I-1).** Con `commercial.events_enabled` encendido, los observadores que registran dentro de la transacción de un pago pueden perder en silencio el cambio de estado del pago en PostgreSQL. Lo he reproducido en PG 14 local. No bloquea este despliegue (el emisor nuevo no tiene el problema), pero conviene corregirlo pronto.

---

## Hallazgos por gravedad

### BLOQUEANTES
Ninguno.

### IMPORTANTES

**I-1 · Preexistente, vivo desde que se encendió `events_enabled`: ROLLBACK silencioso de la transacción de pago en PostgreSQL.**
- **Ubicación:** `app/Services/Commercial/CommercialEventRecorder.php:59-70` (contención) y `:111-132` (INSERT y captura de la violación de unicidad). Lo llaman los 8 observadores de `CommercialEventServiceProvider` dentro de la transacción de quien escribe. Por ejemplo `PaymentTransactionCommercialObserver.php:52` y `:78`, según el informe de backend §2.11 [L].
- **Impacto:** si el INSERT del evento falla dentro de la transacción del pago, PHP se traga la excepción pero PostgreSQL deja la transacción abortada (25P02). Puede fallar por la carrera de `dedupe_key`, un `lock_timeout` o un `statement_timeout`.
  - Si el pago ejecuta otra sentencia después, falla, y el pago se revierte con error: el webhook devuelve 500 y Wompi o la reconciliación reintentan.
  - **Si el INSERT era la última sentencia, `commit()` devuelve `true` y el servidor hace ROLLBACK.** El cambio de estado del pago se pierde en silencio y la aplicación cree que lo confirmó.
- **Cómo lo comprobé [C]:** sonda PDO contra el PostgreSQL 14.24 local (`probe_pg_commit.php`, en el directorio temporal), con tablas temporales:
  1. `BEGIN`;
  2. `UPDATE pago SET estado='approved'`;
  3. un INSERT duplicado, capturado (SQLSTATE 23505);
  4. `commit()`.

  Salida: `commit() devolvió: true` · `estado del pago tras el commit: pending`.
- **Probabilidad [L/S]:** baja. La carrera típica (el webhook frente a la reconciliación) la serializa el bloqueo de la fila del pago: el segundo proceso espera al `UPDATE` y su `exists()` ya ve el evento confirmado. Quedan otros errores de la base y los observadores que registran el mismo hecho desde filas distintas. Los `correlation_id` (columna `uuid`) los genera el servidor [L: `WebhookMetaController.php:67-68`, jobs], así que el error `invalid input syntax for type uuid` de las pruebas antiguas no se da en producción.
- **Este cambio no lo empeora:** el emisor de quejas registra después del commit. Lo he comprobado en las sondas S1–S3 y en `ComplaintEventWiringTest` en PG (§2).
- **Corrección propuesta** (para `database-specialist` y `backend`; no la implemento):
  - en `doRecord`, que el INSERT no pueda abortar la transacción de fuera: `insertOrIgnore`, que en PG es `ON CONFLICT (dedupe_key) DO NOTHING`, para la carrera, y además un punto de guardado (`DB::transaction(fn () => CommercialEvent::create(...))` anidado = `SAVEPOINT`) para cualquier otro error;
  - corregir el docblock (`:27-30`): «nunca lanza» no basta en PostgreSQL;
  - prueba de regresión en PG: un pago cuyo último paso es el observador y cuyo INSERT falla debe quedar `approved`.
- **Mientras tanto:** es decisión del lead y del propietario mantener encendido `events_enabled`. Mi lectura es que el riesgo es bajo y tolerable unos días, no semanas.

**I-2 · Ley 1581: «se quejó» queda fuera de la supresión.**
- **Ubicación:** `docs/ultron-studio/ops/supresion-1581.sql`. Lo que hace hoy:
  - Por texto o teléfono, redacta las tablas `ultron_studio_*`, `voice_*` y la autoría del personal en `outreach_*`.
  - Su bloque B, por persona (`:875`, `:975-985`), pseudonimiza `outreach_enrollments`, `outreach_jobs`, `outreach_decisions` y `outreach_outcomes`: `subject_key → 'x:'||id`, `subject_ref → 64 ceros` y, en las inscripciones, `member_id`/`lead_id → NULL`. También borra `outreach_budget_counters` `phone:…`.
- **Lo que NO cubre** [C: `grep -n 'commercial_events\|marketing_ai_actions'` da 0 líneas]:
  1. `commercial_events`. La fila `complaint_created` queda con `marketing_lead_id`, `member_id`, `occurred_at` y un payload `{source, conversation_id, ai_action_id}`: el dato «esta persona se quejó tal día» sigue unido a la persona.
  2. `marketing_ai_actions`. Ya guardaba antes de este cambio `reason='complaint'`, `metadata.intent` y, en la ruta interna de n8n y en la toma manual, un `reason` de texto libre de hasta 1000 o 500 caracteres.
  3. `outreach_decisions.facts_json` y `trace_json` no se tocan. Al pseudonimizar la fila queda el evento sin sujeto. Pero `trace_json` guarda en la entrada de la escalera `phone.refs`, los seudónimos HMAC del teléfono (`LadderInputBuilder.php:141-142`), y con la `APP_KEY` se puede volver a ligar. Es **preexistente**, no lo introduce la queja, pero es la misma fila.
- **Otras vías de borrado, por contraste [L]:**
  - El FK `commercial_events.marketing_lead_id` y `marketing_ai_actions.lead_id` son `cascadeOnDelete`: borrar el prospecto borra los dos (`2026_08_06_000001…:143`; `2026_06_02_000007…:25`).
  - `commercial_events.member_id` no tiene FK.
  - La anonimización de la cuenta del socio (`MemberAccountController.php:270-300`) no toca ni `commercial_events` ni las tablas `marketing_*`.
- **Qué habría que añadir** (`devops` y `database-specialist`; no lo implemento). En el bloque B, con `:subject` = `m:<id>`, contando `l:<id>` y los prospectos con `member_id = <id>`:
  - `commercial_events` de esa persona, por `member_id` o por `marketing_lead_id` ∈ sus prospectos: borrar o redactar (`payload → '{}'`, `member_id/marketing_lead_id → NULL`). En seco, contar.
  - `marketing_ai_actions` de sus prospectos: `reason → '[suprimido]'` y `metadata → '{}'`. Si se prefiere borrar, el borrado del prospecto ya los arrastra en cascada.
  - **Orden:** primero la supresión global (opt-out) de la persona y después borrar la queja. Si no, al desaparecer la queja se levanta el freno.
  - Opcional, preexistente: `outreach_decisions.trace_json` → quitar `phone.refs` de cada `policy.*.input`. Tiene huella: decidir antes cómo convive con `outreach:replay`.
- **Gravedad:** IMPORTANTE, no bloqueante esta noche. Las solicitudes 1581 son manuales y raras, y el dato ya existía en `marketing_ai_actions`. Tiene que estar resuelto antes de abrir W9 o de la siguiente solicitud.

### MENORES

**M-1 · Valores de configuración que apagan o recortan el freno sin avisar.**
- **Ubicación:** `app/Services/Outreach/Run/OutreachConfig.php:228-250` y `:325-330`.
- **Lo que se ve [C]** (sonda `SecQuejasConfigProbeTest`: queja de hace 1 día, finalidad `commercial`):

| Configuración | Ventana / finalidades | ¿Frena? |
|---|---|---|
| `complaint_days = -1` | 0 días | **no** (un negativo se recorta a 0 = apagado) |
| `complaint_days = 0` | 0 | no (apagado deliberado) |
| `complaint_days = '30'` (texto) | 30, pero por el valor por defecto | sí |
| `human_requested_days = 1` | **1 día** (la ventana de la queja la sigue) | sí, solo 1 día |
| `complaint_purposes = []` | `[]` | **no** |
| `complaint_purposes = ['service']` | `['service']` | **no** (lo comercial y la cobranza quedan sin freno) |
| `complaint_purposes = ['commercial ']` o `'commercial'` | valor por defecto | sí |
| `complaint_purposes = [['commercial']]` | `ErrorException: Array to string conversion` | la pasada del planificador o del despachador se cae (cerrado, pero ruidoso) |

- **Quién puede ponerlos:** solo quien despliega `config/outreach.php` o el `.env`. Las claves todavía no existen, la aplicación no hace `config()->set('outreach…')` [C: `grep`] y la configuración va cacheada. No hay vía en tiempo de ejecución desde el CRM.
- **Corrección propuesta:**
  - un negativo o un no entero → el valor por defecto, no 0;
  - darle a `complaintDays()` su propio valor por defecto de 30 en vez de heredar `human_requested_days`;
  - invertir la lista: **todas las finalidades frenan salvo una lista de exentas (`['service']`)**, para que una finalidad nueva del catálogo falle cerrada; hoy una cuarta finalidad quedaría sin freno;
  - no aceptar una lista sin `commercial`, salvo un interruptor explícito de apagado;
  - `array_diff` con elementos que no son textos → el valor por defecto.

**M-2 · `ladder_input_outdated` es demasiado amplio y puede tapar una alteración.**
- **Ubicación:** `app/Services/Outreach/Run/OutreachReplay.php:113-118`. Captura **cualquier** `InvalidArgumentException` de `PolicyLadder::decide`.
- **Análisis [L]:**
  - Una alteración que conserva la forma (cambiar la salida, un motivo o un valor) sigue dando `different`. Lo único que cambia de clase es una entrada con la forma rota: antes hacía caer la orden, de forma ruidosa, y la pasada quedaba en `running`; ahora sale `not_reproducible / ladder_input_outdated` con código 2.
  - Quien altere una decisión posterior al despliegue puede borrar además `context.complaintRecently` para que se lea como «antigua» en vez de «distinta».
  - El listón no baja de verdad: alterar `outreach_decisions` exige el rol dueño (solo inserción por disparador, `supresion-1581.sql:77-78`), y con él se puede recalcular `trace_hash`, un sha256 sin clave, y obtener `same`.
- **Corrección propuesta:** dar `ladder_input_outdated` solo si la excepción es exactamente la de la clave que falta (`! array_key_exists('complaintRecently', $p['input']['context'] ?? [])`) **y** la decisión es anterior al corte del despliegue (`evaluated_at` o `id` < el primero con la clave). En otro caso, `ladder_input_invalid`. `catalog_version` no sirve de discriminador: es la de la versión de la estrategia, no la de la escalera.

**M-3 · Los modos de pérdida del emisor no se vigilan (falla abierto, en silencio).**
- **Ubicación:** `MarketingAiActionCommercialObserver.php:62-64` y `:84-98`.
- **Los tres modos [L]:**
  1. un fallo del registro solo deja `ChannelLog::error('commercial.complaint.record_failed')`;
  2. con `commercial.events_enabled` apagado durante un incidente, las quejas de esa franja no se registran nunca, y al volver a encenderlo la fuente «tiene datos» y no frena;
  3. las quejas anteriores al despliegue no existen (D4).
- **Corrección propuesta:** alerta sobre `commercial.complaint.record_failed`, y Q1-7 (`commercial:backfill-complaints`, idempotente con la misma clave) como remedio, que se ejecuta también después de reencender `events_enabled`.

**M-4 · Quejas falsas en masa: efecto acotado, sin capacidad nueva.**
- **Quién puede crear una fila «queja» [L]:**
  - el modelo (ULTRON `UltronCommitService.php:2545`, el agente de ventas `SalesAgentOrchestratorService.php:203,379`);
  - la ruta interna `POST /api/internal/marketing/ai-action` (`routes/api.php:1893-1894`, firma HMAC `automation.internal` y `throttle:120,1`): acepta **cualquier `lead_id` existente** con `intent='complaint'` o `reason='complaint'` y sin conversación (`InternalMarketingController.php:119-147`);
  - la toma manual con `reason` libre exactamente `complaint` (`MarketingManualTakeoverService.php:90-97`; CRM `MarketingInboxController.php:292`).
- **Lo comprobado [C]** (sondas S4 y S5 en SQLite y PG):
  - la ruta sin conversación deja **una** queja por prospecto y día (clave `lead:<id>:complaint:<Ymd>`);
  - `takeover(..., 'complaint')` registra una;
  - `'Complaint '` no registra nada.
- **Efecto:** silenciar a una persona 30 días en lo comercial y la cobranza, que es el lado seguro. Con la clave interna de n8n se podría silenciar toda la base en ~1,5 h por cada 10 000 prospectos. Pero esa misma clave ya permite `human-takeover` de cualquier conversación (`InternalMarketingController.php:433-457`), que silencia igual por `human_requested` y además apaga la IA: **no hay capacidad nueva.** Quien puede tomar una conversación desde el CRM también puede silenciarla ya.
- **Recomendación:** **no poner una cota** (una cota deja pasar quejas reales: fallaría abierto). Sí vigilar: un recuento diario de `complaint_created` en `eventos medir` y una alerta por umbral, por ejemplo más de 3 veces la mediana de 14 días o más de N por hora desde la ruta interna (`source=sales_agent` sin `conversation_id`). Q1-8(a), descartar una queja, sigue haciendo falta para los falsos positivos.

**M-5 · La bandeja asistida no vuelve a mirar N6 después de despachar.**
- **Ubicación:** `app/Services/Outreach/Run/AssistedQueue.php:60-120`. Muestra y deja reclamar los trabajos `assisted` en `started` (modo `live`) sin volver a evaluar.
- **Impacto:** si la queja llega después del despacho, una persona del personal puede llamar o escribir a quien acaba de quejarse. Ya pasa igual con `human_requested`. Hoy no aplica: la sombra no deja trabajos `live`.
- **Corrección propuesta**, antes de abrir lo asistido en vivo: al reclamar, volver a comprobar `complaintRecently`/`humanRequestedRecently`, o cancelar los trabajos asistidos abiertos de la persona cuando entra un `complaint_created`.

**M-6 · La simulación con muestra real enseña quién se quejó, con su seudónimo.**
- **Ubicación:** `StudioStrategySimulations.php:91` (exige `commercial_automation.simulate_real`, **no basta `ultron.view`**), `:173-184` (devuelve por persona `ref`, `decision.reasons` con `complaint_recent`, `text` y `trace` con la hora del evento) y `:380-389`.
- **Valoración:**
  - `ref` es el `subjectRef` HMAC, no enumerable.
  - Para ligarlo a una persona hace falta otro permiso: `assisted_work`, que devuelve `subjectRef` + `person {kind,id}` (`AssistedQueue.php:225`), o `timeline_view`.
  - Lo guardado (`GET …/simulations`) son recuentos, sin trazas por persona (`UltronStudioStrategyController.php:305-311`).
  - Es la misma exposición que ya tienen `human_requested` y la deuda vencida.
- **Aceptable.** Conviene mantener `simulate_real` en pocas manos.

**M-7 · La simulación usa constantes, no la configuración.** `StudioStrategySimulator.php:51-59` (`COMPLAINT_DAYS = 30`, finalidades fijas). Si M-1 se configura, la simulación dirá «contactable» a quien la ejecución frena, o al revés. La prueba solo ata los valores por defecto. Corrección: pasar los valores efectivos por `$context`, como ya se pasan otros.

**M-8 · Truncado silencioso de eventos (no explotable hoy) [L].** `EngineFacts.php:140-146` corta a 500 eventos por persona y 101 por tipo, de más reciente a más antiguo, sin devolver `null`. Para que la queja quedara fuera harían falta 500 eventos más recientes, dentro de la ventana, de al menos 5 tipos que la estrategia lea. Recomendación de robustez: cargar `complaint_created` y `human_requested` antes del corte, o dar `null` si se corta.

---

## 1 · ¿Se puede saltar el freno?

**No, con la configuración por defecto.** Camino a camino:

| Camino | Resultado | Evidencia |
|---|---|---|
| Que una estrategia lo quite | **No.** N6 añade `complaint_recent` siempre que `complaintRecently !== false` (`PolicyLadder.php:484-488`). No depende de la audiencia ni de las exclusiones, y N6 devuelve `warnings => []` | [L] + `policy-ladder-cases.json` (+13 casos) |
| Rama de sombra | **No.** `SHADOW_WARNINGS` (`PolicyLadder.php:96-99`) solo afecta a N3, N4 y N5; N6 no lo lee | [L]; casos `shadow` y prueba de extremo a extremo [C: `H9ComplaintRecentTest` 8/8 en SQLite] |
| Orden de N6 | Con `success_reached`, `context_changed` o `human_requested_recently` a la vez, el efecto es el del primero, pero el veredicto sigue siendo `deny`. Ninguna combinación da `allow`. Con `cancel_context_changed`, el planificador niega y reevalúa; la queja sigue en la ventana y vuelve a negar | [L] `OutreachPlanner.php:508-545` |
| Finalidad mal escrita o nueva | **Mal escrita: imposible.** `usst_purpose_chk`, inmutable (`2026_11_12_100001…:322`), firmada en la cadena (`OutreachPublishedStrategies.php:506`); `StrategyContent.php:195` valida el dominio. **Nueva:** quedaría sin freno (M-1) | [L] |
| `service` | Sin freno, por diseño (D3). `service` solo admite `appointment_*` e `invoice_*` como disparador (DF-K4) | [L] |
| Configuración | `complaint_days ≤ 0`, `complaint_purposes` = `[]` o sin la finalidad. Solo quien despliega (M-1) | [C] sonda |
| Despacho | La queja que llega entre planificar y despachar frena: el despachador rehace la foto en el instante (`OutreachDispatcher.php:265-293`) con `$live->uses()` (+`complaint_created`, `LiveStrategy.php:222-223`) y cierra con `exit_complaint` (`:430-435`) | [L] + prueba de despacho [C] |
| W9 y V9 | Pasan por el mismo `process()`: escalera → `ChannelSelector` → `attempt` en la misma llamada (`OutreachDispatcher.php:305-329`). La ventana que queda son milisegundos | [L] |
| Bandeja asistida | **Hueco después del despacho** (M-5); hoy no aplica | [L] |
| Dos identidades | **Frena en los dos sentidos.** El sujeto `m:` reúne todos los prospectos con `member_id` (`SubjectResolver.php:145-148`, `:211-212`). Los eventos se casan por `member_id` **o** `marketing_lead_id` (`EngineFacts.php:134-139`, `:195-205`), también si el prospecto se vinculó después de la queja. Lo que no frena es un socio y un prospecto **no vinculados** (riesgo conocido, plan §1.4) | [L] + «la queja del prospecto frena a su socio» [C] |
| Fuente sin datos | **Falla cerrado.** `EngineFacts::events` da `null` con `events_enabled !== true` o con la persona sin resolver (`EngineFacts.php:103-113`) → `complaintRecently = null` → deny. `LadderInputBuilder::build` también da `null` por defecto si falta la clave (`:134`) | [L] + prueba «sin datos» y «fuente apagada» [C] |
| Llamadores de la escalera | Solo cuatro: planificador, despachador, replay y simulación del Studio [C: `grep PolicyLadder::decide`]. Todos pasan la entrada nueva; si faltara, `InvalidArgumentException` (cerrado) | [C] |

## 2 · El emisor

- **Con y sin transacción, anidada y revertida [C].** Laravel 12.58: `DB::afterCommit` → `DatabaseTransactionsManager::addCallback` (`vendor/…/DatabaseTransactionsManager.php:205-212`): sin transacción, en el acto; con ella, al confirmar la raíz. Mi sonda `SecQuejasProbeTest`, fuera del repositorio, **5/5 en SQLite y 5/5 en PostgreSQL 14** (`pgunit.sh`, `UNIT_DB=ultron_studio_sec_quejas`):
  - S1: savepoint revertido y transacción exterior confirmada → 0 quejas y 0 filas;
  - S2: anidada confirmada y exterior revertida → 0;
  - S3: anidada confirmada → 0 dentro y 1 al confirmar la exterior;
  - S4 y S5, en §M-4.

  Y `ComplaintEventWiringTest` **11/11 en PostgreSQL** [C], que incluye «0 dentro, 1 al confirmar, y la transacción sigue sana».
- **Si el callback falla:** `try/catch (\Throwable)` dentro del callback (`:84-98`). No llega a quien confirma ni a `MarketingAiAction::create`. Lo que corre antes del callback (`config`, `isComplaint`, `Carbon`) no lanza [L].
- **Retraso del turno [L]:** sin transacción, el turno de ULTRON paga en línea `fromLead` (unas consultas), `exists()`, un INSERT y una línea de log: milisegundos. Con transacción, lo paga quien confirma, después del commit. Con el NBA encendido se suma `dispatch()->afterCommit()`: si Redis no responde, el turno espera el tiempo de conexión. Está contenido (`CommercialEventRecorder.php:160-170`) y es un patrón preexistente.
- **Turnos mudos:** no. Una fila que no se crea (por ejemplo `idempotency_key` duplicada en ULTRON) no dispara `created`.
- **Log, sin datos personales [L]:**
  - `commercial.complaint.record_failed`: `ai_action_id` + la clase de la excepción.
  - `commercial.event.record_failed` del registrador (`:63-67`) incluye `$e->getMessage()`, y en un `QueryException` eso es el SQL con sus valores: ids, `{source, conversation_id, ai_action_id}`, la clave y el `correlation_id`. Sin teléfono ni texto; además pasa por `LogRedactor::scrub`.
  - `commercial.event.recorded`: `lead_id` y `member_id`.
- **Payload:** sin texto ni teléfono; `source` es un código cerrado (`:76`).

## 3 · Dato personal nuevo: quién lo lee

| Superficie | Qué enseña | Permiso | ¿Nuevo? |
|---|---|---|---|
| Supervisión (`SupervisionController.php:174-193`, `routes/marketing.php:229-242`) | `lead_id` + `complaint_created`, con la etiqueta en crudo (sin `factLabel`) | Acceso al inbox | **No:** la misma pantalla ya enseña `marketing_ai_actions.reason = 'complaint'` (`:160-170`) |
| Bandeja (`InboxContextService.php:479-488`) | Los 20 últimos eventos de la persona | Inbox | No: quien tiene el inbox lee la propia conversación, con el texto de la queja |
| Panel de la automatización (`OutreachReadModels.php:195-220`) | `facts` (con `complaint_created` y su hora), `trace`, `reasons` y `text` («se quejó hace poco») por `subjectRef` | `commercial_automation.view` | Mismo contenedor que `human_requested` y la deuda vencida. Seudónimo. Ligarlo exige `assisted_work` o `timeline_view` |
| Simulación con muestra real | Ver M-6 | `commercial_automation.simulate_real` (no `ultron.view`) | Igual que arriba |
| SSE (`Run/Stream/*Topic.php`) | `OutreachJobsTopic` publica `cancel_reason = complaint_recent` con el uuid del trabajo, sin sujeto. Ningún tema lee `commercial_events` [C: `grep`] | `commercial_automation.view` | Sin persona |

Conclusión: no se filtra a nadie que antes no pudiera saberlo. Lo que falta es la supresión (I-2).

## 4 · Catálogo

- **El servidor la rechaza [C].** Sonda `probe_trigger.php` sobre la estrategia válida del fixture, cambiando solo el disparador: `StrategyContent::fromArray` con `trigger.event = complaint_created` da `trigger_not_allowed` en `commercial`, `collections` y `service` (`StrategyContent.php:244-247`). El catálogo da `{available: true, triggerable: false, purposes: []}` [C].
- **Las versiones guardadas** se releen con `StrategyContent::fromStored` al ejecutarlas (`OutreachPublishedStrategies.php:166`, `:515`): ninguna puede colar el disparador.
- **Un cambio de comportamiento, en el lado seguro:** una versión que ya excluía `complaint_created` en sus condiciones empieza a excluir de verdad. La compatibilidad no cambia (DF-K6: `available` no cuenta) [L].
- **Hueco de pruebas, menor:** `strategy-content-cases.json` no tiene un caso explícito «disparador `complaint_created` → `trigger_not_allowed`» [C: 0 casos]. Convendría añadirlo para que el CRM lo herede.

## 5 · Replay

Lo explica M-2: el listón no baja, pero el motivo es más amplio de lo necesario. Recomiendo acotarlo.

## 6 · Fechas del escáner

Coincido con el informe de backend (§1.3), con dos precisiones [L sobre `ScanCommercialFacts.php:85-259`; el escáner corre cada hora, `routes/console.php:328-331`]:

| Familia | ¿De más? | ¿De menos? | ¿Dos veces? |
|---|---|---|---|
| Renovación | No (la clave lleva la fecha de fin) | No | No |
| Vencidas | No | No (los dos cálculos se solapan) | No |
| Inactividad | No | No | Solo si se despliega un **domingo** de 19:00 a 23:59. Hoy es miércoles: **no aplica esta noche** |
| Enlaces abandonados | — | No | **Sí, esta noche, si se despliega antes de las 00:00 de Bogotá.** Un `payment_pending` de más por cada transacción que pasó las 6 h entre las 19:00 y el despliegue. Sin efecto hoy (0 estrategias, NBA apagado) |

- **Preexistente, no lo cambia el diff:** cada familia hace `->limit(500)` sin orden ni exclusión de lo ya emitido. Con más de 500 candidatos, los mismos 500 se reintentan y el resto no se emite nunca. Lo dejo para `backend`.

## 7 · Adyacente: los observadores dentro de la transacción

Ver I-1. Gravedad hoy: impacto alto (la pérdida silenciosa del cambio de estado de un pago), probabilidad baja. Es real desde que se encendió `events_enabled`. Recomiendo corregir el registrador (savepoint + `insertOrIgnore`) en la siguiente ventana, con su prueba en PostgreSQL, y no esperar a abrir W9.

---

## Lo que ejecuté

| Qué | Motor | Resultado |
|---|---|---|
| `tests/Feature/Commercial/ComplaintEventWiringTest.php` | PostgreSQL 14 (`ultron_studio_sec_quejas`) | OK, 11 / 36 |
| `tests/Feature/Outreach/Run/H9ComplaintRecentTest.php` | SQLite | OK, 8 / 69 |
| Sonda `SecQuejasProbeTest` (S1–S5, fuera del repositorio) | SQLite y PostgreSQL 14 | OK, 5 / 9 en los dos |
| Sonda `SecQuejasConfigProbeTest` (valores de configuración) | SQLite | la tabla de M-1 |
| Sonda `probe_trigger.php` (`StrategyContent::fromArray`) | — | `trigger_not_allowed` × 3 |
| Sonda `probe_catalog.php` | — | `{available: true, triggerable: false, purposes: []}` |
| Sonda `probe_pg_commit.php` (PDO, tablas temporales) | PostgreSQL 14.24 local | `commit()` = `true`, el pago queda `pending` |

## Lo que no he podido verificar

- **Producción, nada:** ni las banderas, ni las 0 estrategias, ni el volumen de quejas que marcaría la IA, ni si el flujo activo de n8n (`29de5119`) sigue llamando a `/internal/marketing/ai-action` con `intent` [S].
- **El camino real del agente de ventas** (`SalesAgentOrchestratorService`, dos filas por turno): solo lo he leído.
- **Qué observador de pagos deja el INSERT como última sentencia antes del COMMIT** (el caso silencioso de I-1). La sonda demuestra el mecanismo, no que ocurra con un pago real de la aplicación.
- **El CRM:** ni los textos de `complaint_recent` y `exit_complaint`, ni la sincronización de fixtures.
- **La batería completa:** solo lo de la tabla de arriba.
