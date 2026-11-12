# Informe de backend · La bandeja asistida vuelve a mirar la queja (M-5) y la simulación usa la configuración (M-7)

**12-11-2026 · `backend` · rama `feat/ultron-studio-h9`, árbol `_worktrees/ultron-h8/Iron-Body/backend`, HEAD `4277f128`.** Sin commit ni push. No he tocado producción, el CRM, `config/*.php`, `routes/*`, las migraciones, el contrato ni los guiones de `ops/`.

Marcas: **[C]** comprobado ejecutando · **[L]** leído en el código, sin ejecutar · **[S]** suposición.

## 0 · Decisiones de diseño (escritas antes de codificar)

### 0.1 · M-5: dónde se vuelve a mirar

| Sitio | ¿Mira la queja? | Por qué |
|---|---|---|
| `claim()` (E12) | **Sí, siempre.** Después de la repetición idempotente de quien ya lo reclamó y de `enrollment_closed`, antes del `UPDATE` condicionado | Es la puerta: sin reclamar no hay `person` ni `script` (`dto()`, REV9 m-2). Va después de la repetición para que quien ya lo tenía siga (requisito 3) |
| `queue()` (E11) | **Sí, solo las filas SIN reclamar de la página.** Las reclamadas se quedan | Quien ya lo reclamó tiene que verlo para registrar el resultado (requisito 3); otra persona no puede reclamar uno ya reclamado (`already_claimed`) |
| `show()` | No | No lo llama ningún controlador ni ruta [L: `grep -rn AssistedQueue app routes`]; sin `viewerId`, `dto()` ya pone `person` y `script` a null |
| `recordOutcome()` (E13) | No | Requisito 3: quien reclamó registra su resultado aunque la persona se haya quejado después |

**Una sola fuente de verdad.** El mismo camino que el despachador (`OutreachDispatcher.php:267-293`): `BatchSnapshotBuilder::build()` → `CoreProjection::snapshot($s)->events` → `LadderInputBuilder::complaintRecently($events, $ahora, $purpose)`. La finalidad es la de la inscripción (`e.purpose`, que la base ata a la de la estrategia con `oenr_purpose_matches_strategy`). La bandeja se queda con el trabajo si la función devuelve `true` **o `null`** (sin datos: falla cerrado, como N6). Con `service` o con `complaint_days = 0`, la función devuelve `false` sin leer nada, y el trabajo sigue en la bandeja, igual que en la escalera.

**`human_requested` no se mira**, a propósito (L9-66, REV9 m-7): `QUEUE_ENROLLMENT_STATUSES` incluye `handed_off` («una derivada a persona sigue viva en la bandeja») [L: `AssistedQueue.php:51-57` en HEAD; `:65-71` después del cambio]. El cambio solo llama a `complaintRecently`.

### 0.2 · M-5: qué pasa con el trabajo y la inscripción

**Se quedan como están. La bandeja no escribe nada nuevo.** Razones:
1. El motor no tiene camino para cancelar un trabajo `started`: `JOB_LIVE_STATUSES` es `planned`/`claimed`/`dispatching` [L: `OutreachTables.php:49`] y ni `opted_out` lo cancela (`OutcomeRecorder::cancelLiveJobsOf`, `:161-177`, solo `planned`/`claimed`). Habría que inventar una transición.
2. Cerrar la inscripción desde la API (`exited`, `complaint_recent`) exigiría escribir la decisión `exit` de N6 con su traza (REV9 m-9) sin haber pasado la escalera: un camino de escritura nuevo en una ruta del CRM.
3. `queue()` es una lectura (GET): no escribe.

**Consecuencias**, que dejo escritas:
- El trabajo queda `started` y la inscripción abierta, sin reevaluarse (`next_due_at = NULL`), hasta que (a) quien ya lo reclamó registra el resultado, o (b) acaba la ventana de la inscripción: `OutreachReconciler::expireWindows()` la cierra `expired` / `no_response`. Mientras la queja esté dentro de su ventana, o falten datos, el trabajo no se ofrece ni se reclama.
- Si la ventana de la queja (30 días) acaba antes que la de la inscripción (`enrollmentDays`, hasta 60), el trabajo **vuelve a ofrecerse**. Eso es coherente con N6, que solo frena las quejas recientes, pero el motor habría sacado la inscripción. Pasa lo mismo hoy con cualquier `started` antiguo: la bandeja no mira `expires_at`. Lo dejo como hallazgo **H-1** (§6), no lo cambio.

### 0.3 · M-5: el código que recibe el CRM

**409 `not_in_queue`, el código que ya existe.** Razones:
- No rompe el contrato y no exige cambios en el CRM: el CRM ya lo conoce (`CA_ERROR_CODES`, `commercial-automation-api.types.ts:1296`) y tiene su texto, «Ese trabajo ya no está en la bandeja.» (`STATE_409_TEXTS`, `commercial-automation-text.ts:345`).
- Es literalmente cierto: la bandeja ya no lo ofrece.
- Cubre el caso sin datos (`null`) sin decir algo falso: «se quejó» no sería verdad si solo falta la fuente.
- No le cuenta a quien no lo ha reclamado un hecho sensible de una persona.

El motivo real va al registro de la aplicación: `outreach.assisted.claim_withheld` con el uuid del trabajo, `reason: complaint_recent` y `known` (si la fuente tenía datos). Sin teléfono, nombre ni texto. Un código propio (`complaint_recent`) queda como decisión del propietario (§5).

**Nivel `warning`, no `info`** (corrección tras el aviso del lead: producción corre con `LOG_LEVEL=warning` y un `info` no se vería). Es una persona del personal intentando reclamar el trabajo de alguien que se quejó, o la fuente de eventos sin datos: tiene que poder leerse en producción. Solo se registra al **reclamar**, no al listar: la bandeja se consulta a menudo y un aviso por cada fila retenida en cada lectura sería ruido.

### 0.4 · M-5: coste por página

Una sola foto por página, y solo si hay filas sin reclamar. Pide **solo** `complaint_created` y nada de política: `CoreProjection::selectionFor(['events' => ['complaint_created']], false)`. Son las consultas fijas del resolutor más **una** a `commercial_events`, las mismas con 1 que con 50 filas. Medido en §3.3. Coste aceptado: una página puede salir con menos de `limit` filas si alguna se retiene (la API no pagina por cursor).

### 0.5 · M-7

`StudioStrategySimulations::simulate()`, que no es pura, pone en el contexto de la simulación los valores efectivos: `complaint: {days: OutreachConfig::complaintDays(), purposes: OutreachConfig::complaintPurposes()}`. Es el mismo contexto por el que ya pasan `studio`, `templates` y `validation`. `StudioStrategySimulator`, que es puro, los lee de `$context` y no de constantes. Si faltan, lanza `InvalidArgumentException` en lugar de caer en un valor por defecto en silencio, que es justo el defecto de M-7. Aplica también `days = 0 → false`, que la ejecución tenía y la simulación no. Las constantes `COMPLAINT_DAYS` y `COMPLAINT_PURPOSES` desaparecen.

### 0.6 · M-7: quién llama al simulador [C: `grep -rn StudioStrategySimulator app routes config database bootstrap tests` y `grep -rn -e '->simulate(' -e StudioStrategySimulations app routes`]

| Llamador | Qué usa | ¿Pasa `complaint`? |
|---|---|---|
| `StudioStrategySimulations::simulate()` (`:127`) | `StudioStrategySimulator::run()`, el **único** llamador de `run()` en el producto | **Sí, siempre.** `$simContexto` se arma dentro de `simulate()` (`:107-112`). El parámetro `?array $context` de `simulate()` es el contexto del VALIDADOR (`StudioStrategyValidator::context()`), no el del simulador: ni las pruebas que lo pasan pueden quitar `complaint` |
| `StudioStrategySimulations::simulate()` (`:185`) | la constante `MAX_TRACES` | no aplica |
| `UltronStudioStrategyController::simulateVersion()` (`:289`) y `simulateDraft()` (`:300`) | `simulate()` | sí, por `simulate()` |
| Uso interno del simulador: `person()` → `complaintRecently()` (`:159`) y `ladderInput()` (`:166`) | — | `complaintRecently` recibe `$context['complaint']`, ya validado en `run()` (`:97`); `ladderInput` no lo lee |
| Pruebas: `UltronStudioStrategyComplaintSimulationTest` (`complaintRecently` con el contexto de `complaintContext()` o uno malo a propósito) y las que simulan por la API o por `simulate()` (`OutreachFourEyesGuardTest:66`, `UltronStudioStrategySimulationTest`, `…FourEyesTest`, `…LifecycleTest`, `…RollbackTest`, `…ValidationTest`) | — | en verde (§3.4) |

Nada más nombra el simulador: ni órdenes, ni trabajos de cola, ni rutas, ni n8n, ni el CRM. `StudioVersions::simulate()` (`UltronStudioVersionController.php:140`) es la simulación de las versiones del Studio de prompts y no lo usa. **Con el código de hoy, la excepción no puede salir en producción.** Si saliera, por un llamador nuevo, sería un 500 en una ruta del Studio, nunca una simulación con valores equivocados.

## Veredicto

**M-5 y M-7, cerrados en el código, con pruebas que fallan con HEAD y pasan con el arreglo, en SQLite y en PostgreSQL 14.**
- **M-5.** Un trabajo asistido sin reclamar de una persona con una queja dentro de la ventana, o sin datos de eventos, ya no se ofrece en la bandeja y no se puede reclamar: 409 `not_in_queue`. Quien ya lo había reclamado lo sigue viendo y registra su resultado. `human_requested` no retira nada. La bandeja no escribe nada nuevo.
- **M-7.** La simulación del Studio usa la ventana y las finalidades efectivas de la ejecución, que le llegan por el contexto. Si faltan, falla en voz alta.

Sin migraciones, sin configuración nueva, sin cambios de catálogo ni de fixtures del CRM. El CRM no necesita cambios para funcionar (§4).

## 1 · Ficheros tocados (lista exacta)

| Fichero | Qué | Líneas (después) |
|---|---|---|
| `app/Services/Outreach/Run/AssistedQueue.php` | modificado | `:9-10`, `:14` (usos); `:31-40` (docblock: la regla); `:55` (`BatchSnapshotBuilder` inyectable); `:89-96` (`queue()`: retiene las filas sin reclamar); `:107-108` (docblock de `claim()`); `:129` (`claim()` llama a `requireNoRecentComplaint`); `:213-258` (`requireNoRecentComplaint` y `withheldByComplaint`) |
| `app/Services/UltronStudio/StudioStrategySimulator.php` | modificado | `:18` (uso); fuera `COMPLAINT_DAYS` y `COMPLAINT_PURPOSES` (antes `:51-59`); `:90-97` (`run()`: el contexto `complaint` es obligatorio); `:159` (lo pasa); `:304-332` (`complaintRecently($foto, $now, $purpose, $complaint)`, con `days = 0 → false`); `:334-353` (`complaintSettings`) |
| `app/Services/UltronStudio/StudioStrategySimulations.php` | modificado | `:30` (uso de `OutreachConfig`); `:111` (`'complaint' => self::complaintContext()`); `:190-200` (`complaintContext()`, público y estático) |
| `tests/Feature/Outreach/Run/H9AssistedComplaintTest.php` | **nuevo** (229 líneas, 4 pruebas) | — |
| `tests/Feature/UltronStudio/Strategies/UltronStudioStrategyComplaintSimulationTest.php` | modificado: 4 pruebas nuevas y **una existente cambiada** | `test_los_valores_de_la_simulacion_son_los_de_la_ejecucion_por_defecto` comparaba las dos constantes que desaparecen. Ahora compara `complaintContext()` con `OutreachConfig` y conserva la aserción de `HUMAN_REQUESTED_DAYS`. No relaja nada: la atadura fuerte está en las pruebas nuevas, con valores distintos de los de por defecto |

**No he tocado** el controlador (no hacía falta), `OutreachCatalog`, el diccionario de trazas, los fixtures del H9, `config/*`, `routes/*`, las migraciones ni los documentos. **No hay hashes nuevos que espejar.**

En el árbol hay además `docs/ultron-studio/ops/v9-abierta.sh` modificado; durante la sesión también lo estuvo `h8-e2e.sh`. Son del agente devops: no los he tocado.

## 2 · Los requisitos, uno a uno

| # | Requisito | Cómo queda | Evidencia |
|---|---|---|---|
| 1 | No se reclama un asistido de quien se quejó, y deja de ofrecerse; sin datos, se niega | `claim()` → 409 `not_in_queue`; `queue()` no lo lista; `null` también retiene | [C] `test_la_queja_que_llega_despues…`, `test_sin_datos_de_eventos_falla_cerrado…` |
| 2 | Una sola fuente de verdad | `LadderInputBuilder::complaintRecently()` sobre `CoreProjection::snapshot($s)->events` de `BatchSnapshotBuilder` (el lector de `OutreachDispatcher.php:267-293`). Sin otra implementación de «queja reciente» | [L] diff §1; [C] la ventana configurada (7 y 0 días) cambia lo que retiene la bandeja |
| 3 | Quien ya reclamó sigue y registra su resultado | La comprobación va **después** de la repetición idempotente; las filas reclamadas no se filtran; `recordOutcome()` no cambia | [C] `test_quien_ya_lo_reclamo…`; mutaciones M5-4 y M5-5 |
| 4 | `human_requested` fuera | [L] `QUEUE_ENROLLMENT_STATUSES = ['active', 'waiting', 'handed_off']` (`AssistedQueue.php:65-71`, con el comentario de L9-66). La foto pide **solo** `complaint_created`. **Comprobado**, no solo leído: con un `human_requested` reciente y la inscripción `handed_off` por `OutreachStore::closeEnrollment`, el trabajo sigue en la bandeja | [C] primera parte de `test_la_queja_que_llega…`; mutación M5-7 |
| 5 | Sin teléfonos, nombres ni texto | Respuesta: el mismo `dto()` de siempre. Registro: `outreach.assisted.claim_withheld`, nivel `warning`, con `{job, reason, known}` | [C] `assertStringNotContainsString('300123456', …)`; el contexto del registro, exacto; `OutreachStaticGuardsTest` (claves de registro) en verde |

## 3 · Lo que ejecuté [C]

Salidas completas en el directorio temporal de la sesión (`…/scratchpad/evidencia/`), fuera del repositorio.

### 3.1 · Rojo con HEAD

Prueba nueva sobre el código de `4277f128`.

| Orden | Motor | Resultado |
|---|---|---|
| `phpunit --no-logging tests/Feature/Outreach/Run/H9AssistedComplaintTest.php` | SQLite | **`Tests: 4, Assertions: 16, Failures: 4.`** Las cuatro caen en la aserción nueva: «ya no se ofrece» (`:140`), «el reclamado se queda; el libre ya no se ofrece» (`:166`), «sin datos, retenido» (`:191`), «el de la persona con queja no se ofrece» (`:220`). La primera parte de la prueba 1, la de `human_requested`, ya pasaba con HEAD |
| ídem `UltronStudioStrategyComplaintSimulationTest.php` | SQLite | **`Tests: 7, Assertions: 25, Errors: 2, Failures: 3.`** Las dos con error llaman a `complaintContext()`, que no existe en HEAD. Las tres que fallan: `complaint_days=7, hace10` (la simulación negaba con 30 días fijos); «y la simulación tampoco» (la cobranza seguía frenada con `complaint_purposes = ['commercial']`); `Sin el contexto … []` (HEAD ignoraba el contexto y no lanzaba). Las dos antiguas, en verde |
| las dos, con `UNIT_DB=ultron_studio_lead_ola5 bash docs/ultron-studio/ops/pgunit.sh --no-logging …`. Cambié temporalmente los tres ficheros por los de HEAD y los restauré (comprobado con `cmp`) | **PostgreSQL 14.24** | Los mismos números: `Tests: 4, Assertions: 16, Failures: 4.` y `Tests: 7, Assertions: 25, Errors: 2, Failures: 3.` |

### 3.2 · Verde con el arreglo

| Orden | Motor | Resultado |
|---|---|---|
| `H9AssistedComplaintTest.php` | SQLite | `OK (4 tests, 39 assertions)` |
| ídem | PG 14 | `OK (4 tests, 39 assertions)` |
| `UltronStudioStrategyComplaintSimulationTest.php` | SQLite | `OK (7 tests, 267 assertions)` |
| ídem | PG 14 | `OK (7 tests, 267 assertions)` |
| Sonda `SondaMotorTest` con `pgunit.sh`, fuera del repositorio | — | `motor=pgsql base=ultron_studio_lead_ola5 version=PostgreSQL 14.24 (Homebrew)`. La ejecución de `pgunit.sh` va de verdad contra PG |

### 3.3 · Coste por página

Sonda `SondaBandejaConsultasTest`, fuera del repositorio: `queue('all', 50)` con N trabajos sin reclamar, contando todas las consultas.

| N filas | HEAD | Con el arreglo | Diferencia |
|---|---|---|---|
| 1 | 5 | 9 | **+4** |
| 5 | 13 | 17 | **+4** |
| 20 | 43 | 47 | **+4** |

- Las 4 de más son `members` ×2, `marketing_leads` ×1 y `commercial_events` ×1: el resolutor y los eventos, **fijas por página**, sin consultas por fila.
- La vista `mine`, o una página toda reclamada, no lee quejas: 0 consultas de más. Lo fija la prueba 4.
- Lo que crece por fila ya venía de antes: `dto()` hace 2 consultas por fila (`reasonOf` → `outreach_decisions` y `contentOfVersion` → `ultron_studio_strategy_versions`). Ver H-3.

### 3.4 · Vecinas, guardas y estilo

| Orden | Motor | Resultado |
|---|---|---|
| `tests/Feature/Outreach/Run/OutreachApiTest.php` | SQLite / PG | `OK (7 tests, 127 assertions)` / ídem |
| `…/OutreachOutcomesTest.php` | SQLite / PG | `OK (6 tests, 101 assertions)` / ídem |
| `…/H9LeadFollowupTest.php` | SQLite / PG | `OK (4 tests, 67 assertions)` / ídem |
| `…/H9ComplaintRecentTest.php` | SQLite / PG | `OK (9 tests, 79 assertions)` / ídem |
| `…/H9MonitorThresholdsTest.php` (usa la bandeja) | SQLite / PG | `OK (3 tests, 94 assertions)` / ídem |
| `tests/Feature/Commercial/ComplaintEventWiringTest.php` | SQLite | `OK (11 tests, 36 assertions)` |
| `tests/Feature/UltronStudio/Strategies/UltronStudioStrategySimulationTest.php` | SQLite / PG | `OK (6 tests, 66 assertions)` / ídem |
| `…/UltronStudioStrategyFourEyesTest.php`, `…LifecycleTest`, `…RollbackTest`, `…ValidationTest` | SQLite | `OK` (4/48, 1/64, 3/45, 10/72) |
| `tests/Feature/UltronStudio/UltronStudioStrategySchemaTest.php` | SQLite | `OK, but some tests were skipped!` 212 / 872, 78 omitidas (las de solo PG) |
| `tests/Feature/Outreach/Run` (el directorio, un proceso) | SQLite | `OK, but some tests were skipped!` **111 / 1666**, 2 omitidas: `H9IdempotencyRaceTest::test_13…` y `OutreachRaceTest::test_16…`, «Solo PostgreSQL» |
| Guardas estáticas (la orden del encargo) | SQLite | **`OK (138 tests, 178703 assertions)`**. Incluye `OutreachIsolationTest::test_la_puerta_de_diff…` (no hay lista que ampliar), los lectores de `ultron_studio_strategies` (los mismos 9), `FactsReadOnlyTest` (179 sentencias, 0 escrituras) y las claves de registro |
| `vendor/bin/pint --test` sobre los 5 ficheros | — | `{"tool":"pint","result":"passed"}` |

### 3.5 · Mutaciones

Cada una, con reemplazo exacto sobre el fichero real, una prueba y vuelta atrás comprobada con `cmp` (guion `mut/correr.sh`, fuera del repositorio).

| Id | Mutación | Resultado |
|---|---|---|
| M5-1 | `claim()` sin `requireNoRecentComplaint` | **muerta** (3 de 4) |
| M5-2 | `queue()` sin filtrar | **muerta** (4 de 4) |
| M5-3 | sin datos (`null`) no retiene: `!== false` → `=== true` | **muerta** (`test_sin_datos…`) |
| M5-4 | también se filtran las filas reclamadas | **muerta** (`test_quien_ya_lo_reclamo…`) |
| M5-5 | la comprobación antes de la repetición idempotente | **muerta** (`test_quien_ya_lo_reclamo…`) |
| M5-6 | sin la línea del registro | **muerta** (2 de 4) |
| M5-7 | retener también por `human_requested`: lee el evento y lo mira | **muerta**, en «human_requested no retira el trabajo (L9-66)». La primera versión de esta mutación no leía `human_requested` en la foto y sobrevivía porque era equivalente; la rehice para que lo lea |
| M5-8 | la finalidad fija en `'commercial'` | **sobrevive.** Es equivalente con estas pruebas: todas las estrategias de la ejecución que llegan a `started` son comerciales. La cobranza no puede salir hoy (N1 la niega sin autorización de cobranza, P9-15) y no hay estrategia de servicio montada en `RunWorld`. El efecto de esta mutación solo iría hacia el lado seguro: retendría trabajos de servicio. La regla por finalidad está probada en `H9ComplaintRecentTest` |
| M5-9 | una foto por fila (N+1) | **muerta** (`test_una_pagina…`) |
| M5-10 | el registro vuelve a `info` (no se vería en producción) | **muerta** (2 de 4). Tras el cambio a `warning` repetí M5-6 y M5-10: las dos mueren, y los ficheros quedan restaurados (`cmp`) |
| M7-1 | la ventana vuelve a 30 fijos | **muerta** (2) |
| M7-2 | sin «0 días apaga» | **muerta**, en la prueba de la regla entera. El extremo a extremo no la ve: con 0 días, `withinLast` ya da `false` con eventos, pero con `null` da `null` frente al `false` de la ejecución |
| M7-3 | las finalidades vuelven a ser fijas | **muerta** (2) |
| M7-4 | `complaintContext()` devuelve los valores por defecto | **muerta** (3) |
| M7-5 | sin contexto → valor por defecto en silencio | **muerta** (`…falla_en_voz_alta`) |
| M7-6 | `simulate()` no pasa `complaint` | **muerta** (4: también las antiguas, por la excepción de `run()`) |

## 4 · Impacto en el CRM (`Iron-Body_Front/src/app/modules/commercial-automation/`)

**Nada obligatorio.** `not_in_queue` ya está en `CA_ERROR_CODES` (`commercial-automation-api.types.ts:1296`) y tiene su texto en `STATE_409_TEXTS` (`commercial-automation-text.ts:345`): «Ese trabajo ya no está en la bandeja.». La respuesta de la bandeja no cambia de forma. Lo que sí se notará [L]:
1. **La fila que el CRM ya tenía pintada** sigue en pantalla hasta recargar: el 409 de `claimAssisted` solo olvida la clave (`commercial-automation-session.ts:703`) y no recarga la bandeja. **Recomendado**, opcional: recargar la bandeja (`void this.loadAssistedQueue()`) también en el 409 de reclamar. Pasa igual hoy con `already_claimed`.
2. **Si el propietario elige un código propio** (§5, D-1), habría que añadir `complaint_recent` a `CA_ERROR_CODES` y a `STATE_409_TEXTS`. Texto propuesto: «La persona se quejó hace poco o no se puede comprobar: este trabajo no se reclama.».
3. **Preexistente, no lo introduce este cambio:** `enrollment_closed` (L9-66) **no está** en `CA_ERROR_CODES`. El CRM enseña el mensaje del servidor, «La inscripción de ese trabajo ya se cerró.», que está en español y no filtra nada.

## 5 · Decisiones que necesitan al propietario

- **D-1 · El código del 409.** He elegido `not_in_queue`: no cambia el contrato y no cuenta nada de la persona. La alternativa es un código propio (`complaint_recent`), más explicativo para el personal, que obliga a tocar el CRM. Ojo: con `null` (sin datos) diría «queja» sin saberlo.
- **D-2 · El riesgo que queda (R-1): la queja que llega DESPUÉS de reclamar.** Quien ya reclamó conserva `person` y `script` y puede llamar. Lo exige el requisito 3, pero el hueco existe. Propuesta, aditiva: un campo `contactWithheld: 'complaint_recent' | null` en las filas reclamadas de la vista `mine`, con la misma función y la misma foto (+0 consultas si la página ya tiene filas sin reclamar; si no, +4). El CRM enseñaría «Se quejó después de que lo reclamaras: no lo contactes; registra el resultado que corresponda». No lo he hecho: cambia el contrato de E11 y el CRM.
- **D-3 · H-1 (abajo):** si un trabajo `started` vencido (`dueBy` pasado) debe seguir ofreciéndose y reclamándose.

## 6 · Hallazgos adyacentes (no los he arreglado)

- **H-1 · La bandeja no mira `expires_at`** [L: `AssistedQueue.php:73-82`, `claim()`].
  - Un `started` sin resultado del personal se ofrece y se reclama hasta que se cierra su inscripción (`expireWindows`, `enrollmentDays` ≤ 60), aunque `dueBy` (24 h, `OutreachPlanner::JOB_TTL_HOURS`) haya pasado hace semanas.
  - Con M-5 esto tiene una consecuencia más: un trabajo retenido por una queja **vuelve a ofrecerse** cuando la queja sale de su ventana de 30 días si la inscripción sigue abierta. Lo comprueba la última parte de la prueba 1: con la queja a 31 días, se ofrece y se reclama.
  - Es coherente con N6, que solo frena las quejas recientes, pero el motor habría sacado la inscripción.
  - Propuesta: `j.expires_at > now` en `queue()` y en `claim()`, o cerrar los `started` vencidos en el conciliador. Es una decisión de producto.
- **H-2 · R-1**, en D-2.
- **H-3 · N+1 preexistente en `dto()`** [C: §3.3]. 2 consultas por fila: 100 para una página de 50. Nadie lo ha reportado como lento, así que no lo toco ni lo escalo a `performance-specialist`. Si hiciera falta: cargar las decisiones `plan` de la página con un `whereIn('job_id', …)` y cachear `contentOfVersion` por versión.
- **H-4 · La simulación del Studio tiene más valores fijos que la ejecución lee de la configuración** (la misma clase que M-7) [L]:
  - `COLLECTIONS_BLOCK_DAYS = 15` frente a `OutreachConfig::collectionsBlockDays()`, que lee `COMMERCIAL_AUTOMATION_COLLECTIONS_BLOCK_DAYS` (`config/outreach.php:141`);
  - `ContactBudget::effectiveLimits()` sin configuración frente a `OutreachConfig::effectiveLimits()`, que lee `COMMERCIAL_MAX_PROACTIVE_WEEK`, `COMMERCIAL_MIN_HOURS_BETWEEN`, `COMMERCIAL_AUTOMATION_PER_WEEK`, `COMMERCIAL_AUTOMATION_MIN_HOURS_BETWEEN` y `voice.outbound.frequency.per_week`;
  - `HUMAN_REQUESTED_DAYS = 30` frente a `outreach.human_requested_days`. Este hoy es un literal sin `env` y no se separa sin tocar el fichero.

  Los límites solo pueden endurecerse, así que la simulación dirá «contactable» a quien la ejecución frena por frecuencia. El bloqueo por cartera puede desviarse en los dos sentidos. Corrección: la misma de M-7, un contexto. Es fuera del alcance de este encargo.
- **H-5 · Reproducibilidad (T12).** La huella de la entrada de la simulación (`inputHash`) no incluye la configuración efectiva de la queja. Dos simulaciones con la misma entrada y distinta `complaint_days` darán resultados distintos con la misma `inputHash`. Ya pasaba con el estado de las plantillas y de la referencia del Studio. Es menor.

## 7 · Texto propuesto para la enmienda del contrato (no lo he aplicado)

**D9-9.5 (Asistido), una viñeta nueva tras «Una persona lo reclama (una vez)…»:**
> - **La bandeja vuelve a mirar N6 `complaint_recent` (enmienda aditiva del 12-11-2026, M-5 de security, L9-116).** Una inscripción que espera el resultado asistido no se reevalúa. Por eso, al ofrecer (E11) y al reclamar (E12), la bandeja aplica la misma regla que la escalera, con la misma fuente: `LadderInputBuilder::complaintRecently()` sobre los eventos de la foto, con la ventana y las finalidades de `OutreachConfig`.
>   - Un trabajo SIN reclamar de una persona con una queja dentro de la ventana, o sin datos de eventos (falla cerrado), no se ofrece y no se reclama: 409 `not_in_queue`.
>   - Quien ya lo reclamó lo sigue viendo y registra su resultado.
>   - No se escribe nada: el trabajo y la inscripción siguen hasta el resultado o el fin de la ventana.
>   - `human_requested` no retira nada (L9-66).
>   - El motivo queda en el registro de la aplicación (`outreach.assisted.claim_withheld`, nivel `warning`: trabajo, motivo y si había datos), sin datos personales.

**§7, notas de E11 y E12:**
> | E11 | `GET assisted/queue` [O] | `commercial_automation.assisted_work` | Sin teléfonos; con un enlace a la ficha; sin los trabajos sin reclamar de quien se quejó hace poco (N6, D9-9.5) |
> | E12 | `POST assisted/{jobUuid}/claim` [O] | Ídem | Una vez; 409 `not_in_queue` también si la persona se quejó hace poco o no hay datos de eventos (D9-9.5) |

**La fila N6 (dentro del paréntesis de L9-114), al final:**
> …; la bandeja asistida lo vuelve a mirar al ofrecer y al reclamar (M-5, L9-116), y la simulación del Studio usa la misma ventana y las mismas finalidades efectivas que la ejecución (M-7, L9-116).

**L9-116 (o el número que toque), en el registro:**
> | L9-116 | backend (be-bandeja-quejas) y lead | M-5 y M-7 de `informe-security-quejas.md` | **M-5:** `AssistedQueue` vuelve a mirar N6 `complaint_recent` en `queue()`, solo en las filas sin reclamar y con una foto por página (+4 consultas fijas), y en `claim()`, tras la repetición idempotente. Usa la misma función y el mismo lector que el despachador. `true` o `null` retienen: 409 `not_in_queue`. Quien reclamó sigue. Sin escrituras. `human_requested` fuera (L9-66). **M-7:** `StudioStrategySimulations::complaintContext()` pasa `OutreachConfig::complaintDays()` y `complaintPurposes()` al simulador puro, que ya no tiene constantes y falla en voz alta sin ellas. Pruebas: `H9AssistedComplaintTest` (4) y 4 nuevas en `UltronStudioStrategyComplaintSimulationTest`, en rojo con `4277f128` y en verde con el arreglo, en SQLite y en PG 14. 16 mutaciones: 15 muertas y 1 equivalente (la finalidad fija). El registro va en `warning` (producción corre con `LOG_LEVEL=warning`). Pendiente: D-1, D-2 (R-1) y H-1, del propietario |

## 8 · Lo que NO he podido comprobar

- **La batería completa:** no la he lanzado, por indicación del lead. Solo lo de §3.
- **El CRM:** solo lo he leído. No he ejecutado sus pruebas ni la pantalla con un 409 `not_in_queue` de verdad [S: muestra el texto de `STATE_409_TEXTS`].
- **Producción:** nada. Ni el volumen real de la bandeja, ni si hay trabajos `started` asistidos en vivo. Según L9-114 la sombra no deja trabajos `live` y hay 0 estrategias [S, no lo he verificado].
- **Una estrategia de servicio o de cobranza que llegue a `started`:** no existe en los ayudantes de prueba. Por eso M5-8 sobrevive (§3.5).
- **La carrera entre la comprobación y el `UPDATE` de `claim()`:** una queja registrada en esos milisegundos no se ve. Es la misma ventana que acepta el despachador (L9-114, «W9 y V9»). No la he provocado.
- **Las sondas** (`SondaBandejaConsultasTest`, `SondaMotorTest`, `mut/*`) están en el directorio temporal de la sesión y no van al repositorio.

## 9 · Casos límite para `tester`

- **La queja que llega entre que la bandeja lista y alguien reclama:** está cubierta, porque `claim()` vuelve a mirar.
- **La queja que llega después de reclamar:** el reclamante sigue (R-1, D-2).
- **Persona suprimida por 1581** (`subject_key = 'x:<id>'`): no se lee y cuenta como sin datos. En comercial y cobranza retiene; en servicio, no. No hay prueba propia [L].
- **Una página de 50 con todas las filas retenidas:** la API devuelve una lista vacía aunque haya más trabajos detrás. No pagina por cursor, así que es una página corta, no un error [L].
- **`commercial.events_enabled` apagado** en un incidente: todos los asistidos sin reclamar de comercial y cobranza desaparecen de la bandeja hasta que se vuelva a encender. Es el lado seguro, como N6 (Q-7) [C].
