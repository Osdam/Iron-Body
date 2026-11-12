# Informe del reviewer · Ola 6 (bandeja asistida y simulador, Centro de supervisión, `commercial:scan`, guiones de la apertura, CRM y pruebas `T2`/`T3`)

**12-11-2026 · `reviewer`.** Árbol del backend `_worktrees/ultron-h8/Iron-Body` (HEAD `4cc17457`, encima de `origin/main` = `4277f128`; producción en `9a6e3b1c`). CRM `_worktrees/ultron-h8/Iron-Body_Front` (HEAD `6d778b4`). Pruebas de la apertura en `_worktrees/ultron-voz-abierta/Iron-Body` (rama local `prep/voice-calls-open-tests`, solo lectura). No he cambiado código, pruebas ni documentos; este informe es el único fichero nuevo. Las sondas y las mutaciones se hicieron en clones de mi scratchpad (`cp -c -R`, sin `.env`), que he borrado al terminar.

Marcas: **[C]** comprobado ejecutando · **[L]** leído en el código · **[S]** supuesto, sin comprobar.

## Veredicto

| Commit | Qué | Veredicto |
|---|---|---|
| `155e7adc` | Bandeja asistida (M-5) y simulador (M-7) | **APROBADO** |
| `36e8a472` + `1de7f7ca` | Centro de supervisión: estado y actividad | **APROBADO**, con un importante y menores para la próxima tanda (ninguno cambia un envío ni una decisión) |
| `bbe9582f` | Aviso del techo de `commercial:scan` en seco | **APROBADO** |
| `5d0a63c8` | Guiones (`hotfix-backend.sh`, `h8-e2e.sh`, `v9-abierta.sh`) y runbook | **APROBADO** |
| `4cc17457` | Documentación y parches de la apertura | **APROBADO** |
| CRM `6d778b4` | Cabecera y ficha del Centro de supervisión | **APROBADO CON CONDICIONES**: se despliega **después** del backend (C-1) |
| `T2` `216b0f1b` + `T3` `33ba6299` (rama local) | Pruebas de la apertura de llamadas | **APROBADO** (I-1 y M-1, M-2, M-3 y M-5 del informe anterior, cerrados) |

**¿Se puede subir y desplegar esta noche? Sí.** No hay bloqueantes. La única condición es de orden: primero el backend (con `hotfix-backend.sh`) y después el CRM (C-1). Los hallazgos de abajo son de pantalla o de pruebas y pueden ir en la próxima tanda. Ninguno abre un envío, una llamada ni una escritura.

## Lo que he ejecutado

| Qué | Resultado |
|---|---|
| `phpunit` SQLite: `H9AssistedComplaintTest`, `UltronStudioStrategyComplaintSimulationTest`, `SupervisionStateTruthTest`, `SupervisionActivityLabelsTest`, `ScanCommercialFactsCeilingTest` (uno a uno, `nice -n 10`) | `OK (4, 39)`, `OK (7, 267)`, `OK (14, 52)`, `OK (4, 416)`, `OK (7, 40)` [C] |
| CRM: `npx tsc -p tsconfig.app.json --noEmit` | salida 0 [C] |
| CRM: `node scripts/check-literals.mjs` | «417 ficheros revisados · literales correctos» [C] |
| CRM: `ng test --include='src/app/modules/supervision/**/*.spec.ts'` (con `IRON_BODY_BACKEND_DIR`) | `1 passed`, `19 passed` [C] |
| Detectores de `hotfix-backend.sh` (`estado_vcp`, `estado_osg`, copiados LITERALES del guion a un banco) sobre commits reales | `9a6e3b1c`, `4277f128` y `4cc17457`: llamadas **cerrada**, envíos **cerrada**. La rama de la apertura (`33ba6299` y sus tres padres): llamadas **abierta**, envíos **cerrada** [C] |
| Diff neto `9a6e3b1c..4cc17457` (lo de esta noche) | 33 ficheros. Ninguno en `database/`, `routes/`, `config/`, `bootstrap/` ni `composer.*`. No toca ni `VoiceCallPolicy.php` ni `OutreachSendGate.php`. No borra ninguno y no hay nombres con espacios ni comodines [C] |
| R6 de la revisión anterior (la puerta encola dos veces) en un clon de la rama de la apertura | camino de las estrategias (`OutboundGate.php:517` duplicada): `V9StrategyGateTest` **3 de 24 en rojo**, «actual size 2 matches expected size 1». Camino del CRM (`:193` duplicada): `VoiceCrmRoutesTest` **1 de 8 en rojo** (`VoiceFixtures.php:316`). Restaurado: `OK (32 tests, 406 assertions)` [C] |
| El recorrido nuevo de `VoiceCallPolicyClosedCopyTest` con un llamador nuevo de `decide(` | literal (`use …\VoiceCallPolicy;` + `VoiceCallPolicy::decide(`): **rojo**, «quien llama a la política del H6 necesita su copia cerrada». Con alias (`use …\VoiceCallPolicy as Politica;` + `Politica::decide(`): **verde** (ver m-6) [C] |
| Parches de `ops/apertura-llamadas/` frente a los commits (`git patch-id --stable`) | `0001` = `3287769b`, `0002` = `216b0f1b`, `0003` = `33ba6299` [C]. De `e61ef7a2` a `33ba6299` no cambia nada fuera de `backend/tests` [C] |
| Sonda de la actividad y del estado (clon del backend, una prueba fuera del repositorio) | ver I-1, m-1 y m-2 [C] |
| `set -e` en el bucle `for … [ -f ] && echo` de `hotfix-backend.sh:91` con un fichero borrado | no aborta (bash 3.2 local; bash ≥ 4 documenta lo mismo) [C para 3.2; S para la versión del servidor] |

**No he ejecutado:** la batería completa (por indicación del lead), PostgreSQL (lo hicieron backend y el lead), `hotfix-backend.sh` contra un servidor ni el CRM en un navegador.

## Bloqueantes

Ninguno.

## Importantes

**I-1 · La línea más frecuente de la actividad contradice a la cabecera en la conversación del canario.** `SupervisionController.php:615` y `:643` [L]; el enrutador: `MarketingInboundMessageRouter.php:133` (`emit`) y `:142` → `:148-149` (`skip('auto_analyze_disabled')`) [L].
- Qué pasa: con ULTRON encendido y `auto_analyze` apagado (producción), cada mensaje de texto entrante hace las dos cosas. Se le cuenta a ULTRON (`emit`) y, justo después, el cerebro local escribe `inbound_skipped` con motivo `auto_analyze_disabled`. También en el canario, donde ULTRON sí lo analiza y responde.
- Lo que sale en pantalla: «Sistema · **No analizó el mensaje entrante** · **El análisis automático del agente está apagado.**». Mientras, la cabecera dice «ULTRON · solo canario» y la ficha llama «agente» a ULTRON.
- Escenario: una supervisora abre la línea de tiempo filtrada por la conversación del canario. Ve, por cada mensaje, que «no se analizó» porque «el análisis del agente está apagado», y una línea después, «Agente · Preparó una respuesta». Según el commit, son 3 242 de las 3 262 decisiones de 30 días. Es la misma clase de contradicción que este commit venía a quitar («Agente apagado» con ULTRON atendiendo).
- No bloquea: es solo lectura, y sigue siendo mejor que la clave cruda.
- Corrección propuesta (lead): que el texto diga de quién habla. Por ejemplo, `inbound_skipped` → «El agente anterior no analizó el mensaje» y `auto_analyze_disabled` → «El agente anterior (análisis local) está apagado; ULTRON va por su propio camino.». Si se quiere afinar más, `decisionEvents()` puede poner `reason` = «Lo atiende ULTRON» cuando la conversación es la del canario (o ULTRON está en `all`) y `ultron.enabled` está puesto. Añadir el caso a `SupervisionActivityLabelsTest`.

## Menores

**m-1 · Un estado que el código escribe sale sin etiqueta: `created`.** Escribe `created` `SalesAgentOrchestratorService.php:384` (la alerta `staff_review` del agente anterior); `resultLabel()` (`SupervisionController.php:697-710`) no lo conoce.
- Escenario [C, sonda]: una fila `staff_review` con estado `created` sale `["Pidió que lo revise el personal","Queja.","created","created"]`. El resultado queda en crudo.
- La propia prueba nueva escribe ese estado (`H9AssistedComplaintTest.php:94`), y `STATUSES` (`SupervisionActivityLabelsTest.php:50`) no lo incluye.
- Corrección: `'created' => 'Creada'` (o «Alerta creada») y añadirlo a `STATUSES`.

**m-2 · Motivos que escribe el camino de ULTRON y que salen en crudo.**
- ULTRON escribe `reason = staff_review_reason ?? intent` (`UltronCommitService.php:2549`) [L]. `SalesAgentDecisionValidator.php:105-111` produce `forbidden_action_attempt`, `unsafe_claim` y `escalation` (`StaffReviewAuthority::MOTIVO_GENERICO`), y `StaffReviewAuthority` admite `team_only_operation` [L]. Ninguno de los cuatro está en `REASON_LABELS` (`SupervisionController.php:641-685`).
- Escenario [C, sonda]: los cuatro salen tal cual en `reason`.
- Corrección: añadirlos a `REASON_LABELS` (y, si se puede, derivar `OPERATIONAL_REASONS` de las constantes en lugar de una lista escrita a mano).

**m-3 · La prueba de las etiquetas promete más de lo que comprueba.** `SupervisionActivityLabelsTest.php:31-33` dice que «un hecho, una acción, un motivo o un estado nuevos sin texto ponen esta prueba en rojo» [L].
- Es verdad para los hechos (`V::EVENTS` con `assertCount(27)`), para las acciones del modelo (`RECOMMENDED_ACTIONS`) y para las intenciones (por reflexión).
- **No** es verdad para `OTHER_ACTION_TYPES` (`:40`), `OPERATIONAL_REASONS` (`:43-47`) ni `STATUSES` (`:50`): son listas escritas a mano. Por eso m-1 y m-2 están en verde.
- Corrección: o rebajar el docblock a lo que es, o derivar las listas: un recorrido de `app/` por `'action_type' => '…'` y `'status' => '…'` en las escrituras de `MarketingAiAction::create`, como hace `VoiceCallPolicyClosedCopyTest` con los lectores.

**m-4 · Con ULTRON en `all`, «Las atiende el agente» vuelve a contar de más.** En `SupervisionService.php:306`, `ultron_all` (y `legacy_auto`) usa `by_ai`, que no aplica las barreras del emisor que `1de7f7ca` sí aplicó al canario.
- `by_ai` cuenta las conversaciones de Instagram (`channel_not_supported`) y las de quien pidió no contactar (`do_not_contact`). Y no cuenta las que tomó el propio agente al escalar, que ULTRON sí atiende.
- Escenario [C, sonda]: con ULTRON en `all`, una de WhatsApp atendible, una de Instagram, una de un prospecto «no contactar» y una escalada por el agente, la pantalla dice `attended_by_agent = 3` cuando ULTRON atiende 2.
- Hoy producción está en canario, así que no se ve. Conviene arreglarlo **antes de pasar ULTRON a todas**.
- Corrección: para `ultron_all`, contar con las mismas condiciones que `canaryIsAttended()`: `channel = 'whatsapp'`, `ai_enabled`, no `(human_takeover AND source = 'manual')` y prospecto con `canReplyReactively()` (un `whereHas` o un `join` con las columnas de consentimiento).

**m-5 · CRM: los dos estados nuevos de `1de7f7ca` se pintan como «información».** `supervision-labels.ts:142` solo pone en aviso `paused`, `brake` y `unknown`. `ultron_unconfigured` («ULTRON sin conexión») y `ultron_canary_missing` («Canario mal configurado») salen en gris [L]. El comentario de `:134-137` justifica el gris porque «es lo configurado», y en esos dos casos es una avería: ULTRON está encendido y no le responde a nadie. El comentario de `supervision.service.ts:21-22` tampoco los nombra.
- Corrección: añadir los dos a la lista de `warn` y a la prueba «en pausa, frenado o sin poder leerlo es un aviso».

**m-6 · El recorrido nuevo de `T3` solo ve la forma literal.** `VoiceCallPolicyClosedCopyTest.php:118` y `:126` buscan el texto `VoiceCallPolicy::decide(`.
- Escenario [C]: un llamador nuevo con `use App\Services\Voice\VoiceCallPolicy as Politica;` y `Politica::decide(…)` deja la prueba en verde sin copia cerrada. El recorrido anterior, el de `CALLS_ENABLED_IN_CODE`, tiene el mismo límite.
- Hoy nadie usa alias [L], y la copia misma depende del `use` sin alias (`ClosedPolicyCopy::replacements()`), así que es un cable trampa razonable, no una prueba total.
- Corrección, si se quiere cerrar: listar los ficheros de `app/` que importan o nombran `App\Services\Voice\VoiceCallPolicy` (tokens, sin comentarios) y exigir la lista exacta, separada en «deciden» (con copia) y «solo lo dicen».

**m-7 · CRM: la frase del vacío de la actividad afirma algo que no se mira.** `supervision-page.ts:228` dice «No hubo mensajes, decisiones ni hechos comerciales en ese periodo.». `activity()` lee herramientas, `marketing_ai_actions` y `commercial_events`, no `marketing_messages` [L].
- Escenario: un día con solo mensajes salientes del equipo, o solo reacciones, sale «No hubo mensajes».
- Es un caso raro: con ULTRON encendido, cada entrante de texto deja una fila `inbound_skipped`.
- Corrección: «No hubo decisiones del agente, herramientas ni hechos comerciales registrados en ese periodo.».

## Condiciones

**C-1 · Orden del despliegue: el backend antes que el CRM.** `supervision-page.ts:135` cae a `handled_by_ai` si la API no manda `attended_by_agent` [L]. Con el backend de producción de hoy (`9a6e3b1c`), el CRM nuevo diría «**Las atiende el agente: 855**»: la misma cifra falsa con otro nombre. Pasa igual si se vuelve atrás el backend y no el CRM.
- Esta noche basta con el orden.
- Mejora opcional para la próxima tanda: con la API anterior, enseñar «—» en lugar de `handled_by_ai`.

## Respuestas a lo que pidió el lead

### `155e7adc`
- **`queue()`** [L]: la consulta SQL no cambia (mismo `orderBy`, mismo `limit`). El filtro es posterior y conserva el orden (`array_filter` + `array_values`).
  - Lo reclamado no se mira, y `mine` no hace ninguna consulta de más. Lo fija `test_una_pagina…`, y lo he leído.
  - Lo único que cambia es que una página puede salir más corta. Es el I-4 de security (`informe-security-bandeja.md:71-77`), que ya está anotado como condición del asistido en vivo. No lo repito.
  - Con 0 trabajos asistidos en vivo (L9-114 [S]), `withheldByComplaint([])` vuelve sin consultar nada: hoy el riesgo en producción es nulo.
- **`claim()`** [L]: la repetición idempotente va antes de la comprobación nueva (`AssistedQueue.php:122-129`); `recordOutcome()` no cambia. Lo comprueba `test_quien_ya_lo_reclamo…` [C].
- **El contexto del simulador**: el único llamador de `run()` es `StudioStrategySimulations::simulate()` (`:127`) [L: `grep` en `app`, `routes`, `config`, `database` y `tests`]. `complaint` se arma en `:111`, antes de separar sintética de muestra, así que no falta en ningún camino real. Si faltara, `run()` lanza `InvalidArgumentException` (un 500), nunca una simulación con valores por defecto.
- **¿Fallarían las pruebas sin cada comprobación?** No repetí las 16 mutaciones de backend; leí las pruebas.
  - Comprueban el valor exacto (lista de uuids, `[409, 'not_in_queue']`, el contexto del registro con `known`) y la ausencia de escrituras (`rowsSnapshot`).
  - La finalidad fija (M5-8) es equivalente con lo que puede llegar hoy a `started` (lo explica su informe); la regla por finalidad la cubre `test_la_regla_de_la_queja…` (225 combinaciones contra `LadderInputBuilder`) [L].

### `36e8a472` + `1de7f7ca`
- **¿«Nadie responde» mientras algo responde?** No he encontrado ningún caso [L].
  - `paused`: el emisor (`UltronEventEmitter.php:69`), el trabajo (`SendUltronEventToN8n.php:80`) y la bandeja de salida (`WhatsappOutboxService.php:140`, salientes de la IA) frenan.
  - `ultron_unconfigured`: el trabajo salta con la misma condición (`SendUltronEventToN8n.php:61-64`).
  - `ultron_canary_missing`: el emisor compara `(int)` contra cada id (`:84-87`).
  - `off` y `legacy_propose`: el agente anterior necesita `auto_analyze` (`AnalyzeInboundMessage.php:166`) y, para enviar, `agent_enabled` y `auto_execute` (`MarketingInboundMessageRouter.php:172-173`).
  - ULTRON junto con `auto_analyze` es fatal al arrancar en producción (`AppServiceProvider.php:269-290`), así que dar prioridad a ULTRON sobre el agente anterior es correcto.
- **¿Una excepción que tumbe la pantalla?** No [L]. `whoResponds()` va en `safe()` con `WHO_UNKNOWN` (que trae todas las claves que lee `agentState()`). `canaryIsAttended()` va dentro del `safe()` de `conversationCounters()`. Si este último falla, la ficha pierde todos los contadores y no solo el nuevo, igual que antes con cualquier fallo de esa consulta.
- **`canaryIsAttended()` frente a `ineligibleReason()`** [L]: coinciden en prospecto, WhatsApp, `canReplyReactively()`, toma solo MANUAL y `ai_enabled`.
  - `status = 'open'` lo exige solo la supervisión. Es coherente con el contador, que cuenta abiertas.
  - Las barreras de forma del mensaje (dirección, remitente, reacción, texto vacío) no son de la conversación.
  - El orden freno/pausa es distinto (la supervisión mira antes la pausa), pero en los dos casos dice «no responde».
- **Consumidores de `agent.enabled`** [L, `grep` en el backend y el CRM]:
  - Backend: solo `SupervisionController::state()`. `smoke-production.sh:114` solo mira el 200, y `PhaseFHardeningTest` solo busca credenciales.
  - CRM: solo `supervision-labels.ts`, a través de las funciones nuevas. El «IA activa» del Inbox (`inbox-page.ts:89`) es otra ruta y otro contador, y queda fuera de esta tanda.
- **Etiquetas de la actividad**:
  - `action_type`: todos los que escribe el código tienen texto [L: los 9 de `RECOMMENDED_ACTIONS` y los 6 de las escrituras en `MarketingAiAction::create`]. La excepción es la ruta interna `POST internal/marketing/ai-action`, que admite cualquier texto (`InternalMarketingController.php:124`) y que, sin etiqueta, sale tal cual, como se pretende.
  - Sin etiqueta: el estado `created` (m-1) y cuatro motivos de ULTRON (m-2).
  - **`reactivate`** y **`release_to_ai`** dicen la verdad (`MarketingManualTakeoverService.php:116` (`release()`) escribe el resumen; `:166` (`releaseToAi()`) cierra `needs_human`) y van a nombre del equipo (`metadata.source = manual`).
  - **`staff_review`**: «Pidió que lo revise el personal» es cierto.
  - **`inbound_skipped`**: ver I-1.
- **`actor`, `reason` y `result`**: el único consumidor es la línea de tiempo del CRM (`supervision-page.ts:232-247`), que los pinta tal cual. `actorLabel` ya conocía `empleado` (`supervision-labels.ts:160`). Nada más los lee [L].

### `bbe9582f`
En seco, `emit()` devuelve 1 por cada candidato con ficha (`ScanCommercialFacts.php:345-349`), también por el que ya tiene su hecho: el aviso nuevo es cierto. En real, el texto y el `ChannelLog::warning` son los de antes (`dry_run` ahora es siempre `false`, que en esa rama ya lo era). `h9-outreach-scope.sh:181` lee los totales por familia, no esa línea [L].

### `5d0a63c8` · `hotfix-backend.sh`
- **¿Deja pasar una apertura?** Girar `CALLS_ENABLED_IN_CODE` no: si `VoiceCallPolicy.php` está en el diff neto, solo admite «abierta → cerrada» en una línea (`:46-55`). Girar `SENDS_ENABLED_IN_CODE` tampoco: solo pasan `cerrada/cerrada` y `abierta/cerrada`, y con `abierta/abierta` el fichero no puede cambiar (`:70-75`). Un estado ilegible aborta [L; detectores en un banco: C].
  - **Límite de diseño, no un hallazgo:** con la constante de envíos cerrada a los dos lados, `OutreachSendGate.php` puede cambiar por esta vía (el comentario lo dice). Tampoco se miran los lectores de la constante de llamadas. Un cambio que dejara de consultar la constante, en vez de girarla, pasaría si el operador lista esos ficheros. La red ahí es la revisión del commit, no el guion.
- **¿Puede abortar el despliegue de esta noche?** Con el diff real, no [C: estados `cerrada`/`cerrada`, ningún fichero prohibido]. Hay dos maneras de que aborte, las dos SIN tocar nada:
  - (a) pasar hashes de 8 caracteres (el guion compara con `rev-parse --short=7`, `:19` y `:22`). La orden del runbook ya calcula los dos con `--short=7`.
  - (b) que un prefijo de 7 sea ambiguo en uno de los dos repositorios. Git alarga entonces el hash a 8 y la comparación falla. Es improbable, y se arreglaría con `rev-parse HEAD` completo y `git rev-parse "$PREVIO^{commit}"`.
- **`set -euo pipefail`** [L]: los `grep -c` van con `|| true`; `grep -qxF` lee de `<<<` (sin el SIGPIPE de una tubería); el recuento de `ERROR` ya no muere cuando ningún registro casa. El bucle de `:91` no aborta con un fichero borrado [C, bash 3.2], y esta noche no se borra ninguno.
  - Lo que queda es de antes y no es de esta tanda: si `queue:restart` (`:92`) fallara, el guion moriría DESPUÉS del `merge`, sin imprimir la vuelta atrás. En ese caso, la vuelta atrás es la de `:100` con `$PREVIO`.
- **`h8-e2e.sh` y `v9-abierta.sh`**: la detección de los dos estados no afloja nada [L].
  - Antes se exigía «una cerrada»; ahora, «una cerrada y ninguna abierta» o «una abierta y ninguna cerrada».
  - Cualquier otra forma sigue abortando.
  - El diff entre la copia cerrada y la abierta sigue limitado a una línea, y `estado_ok` comprueba además cada copia.

### `T2` y `T3`
- Ninguna aserción se afloja [L]:
  - `Queue::assertPushed(…)` (al menos uno) pasa a `assertCount(1, …)` dos veces, una con `connection`/`queue` = `voice` y otra sin filtro.
  - `ClosedPolicyCopy::load()` añade la exigencia de exactamente 2 líneas cambiadas.
  - `T3` solo añade el recorrido y cambia comentarios.
- R6 muere en los dos caminos [C].
- El recorrido nuevo exige la copia a quien llame a `VoiceCallPolicy::decide(` escrito así [C]; con alias, no (m-6).
- Los docblocks de `T3` citan pruebas que existen (`PlaceOutboundVoiceCallClosedCopyTest.php`, `ElevenLabsClosedCopyTest.php`) [C].

## Notas

- Lo que está bien y no repito: M-5, M-7 y la supervisión ya los revisó security (`informe-security-bandeja.md`). Sus condiciones para el asistido en vivo (R-1, H-1, I-4 y los frenos) siguen abiertas y no son de esta noche.
- La guarda `OutreachIsolationTest::test_la_puerta_de_diff…` en rojo hasta el push: no la he lanzado (dicho por el lead).
- Los docs de `4cc17457` y `5d0a63c8`: solo he verificado los parches y las órdenes del runbook que llaman a `hotfix-backend.sh` (P y E con `--short=7`, la lista de ficheros sacada de `git diff --name-only`). No he leído la sección E entera.
