# Hito 9 · REV9 · Revisión independiente (`reviewer`)

**Fecha:** 10-11-2026 (UTC). **Árboles:** backend `_worktrees/ultron-h8/Iron-Body/backend` y CRM `_worktrees/ultron-h8/Iron-Body_Front`, rama `feat/ultron-studio-h8-voice`, con el Hito 9 integrado por el lead (L9-59/L9-60) y **sin commit**. **Contrato:** `ULTRON_STUDIO_H9_CONTRACT.md` congelado (L9-1..L9-62). **Alcance:** solo lectura, pruebas puntuales y órdenes de consola en local; sin red, sin commit, sin producción. No toqué `OutreachIsolationTest.php` (TEST9-b), `docs/` (DOC9) ni `ops/perf-h9` (PERF9).

## 0. Veredicto

**APROBADO CON CONDICIONES.**

- **Ningún bloqueante de código para la fase 1 (todo apagado).** Las 44 rutas nuevas están clasificadas una a una, el dominio `commercial_automation` queda fuera del token compartido, la constante `OutreachSendGate::SENDS_ENABLED_IN_CODE` es `false`, todas las banderas nacen apagadas o en 0, el programador no agenda nada por defecto, el espacio `Outreach` no nombra red ni proveedores, los hechos solo leen, el puente es el único que importa S9, los cuatro ojos son por persona verificada y la regresión completa del lead (L9-62) no deja ningún rojo nuevo.
- **Tres hallazgos MAYORES** que condicionan el cierre: (1) `outreach:stop` por consola contradice la decisión L9-50; (2) los tres scripts de OPS9 están desalineados con el árbol integrado y **el paso 0 del despliegue aborta tal cual** (`routes/console.php` pierde 4 líneas por un retoque de Pint fuera del bloque del H9); (3) la cobertura de §13.1 que declara `informe-run.md` no es la que hay: la cancelación por pausa/reversión en la etapa 6 (#23/#25), #6, #7, los umbrales del monitor (#18) y el E2E #26 no tienen prueba.
- Ninguno de los tres abre una vía hacia una persona: (1) y (3) son de gobierno y de evidencia; (2) es de operación. Las condiciones y sus dueños están en §1 y §10.

## 1. Hallazgos por gravedad

### BLOQUEANTES

Ninguno.

### MAYORES

**M-1 · `outreach:stop` por consola registra una cuenta centinela, contra L9-50.**
- **Dónde:** `app/Services/Outreach/Run/OutreachStop.php:87` (`CONSOLE_ADMIN_ID = 0`) y `:208-216` (`open()`: `opened_by_type => 'person'`, `opened_by_admin_id => $by === null ? CONSOLE_ADMIN_ID : $by->id`, `opened_by_person_id => personOf(by)`); `app/Console/Commands/Outreach/OutreachStopCommand.php:72` (`$stop->open(…, $cuenta, …)`); `informe-run.md:141` lo declara como «decisión estricta» (§3.3).
- **Qué pasa:** L9-50 dice «registra la parada como **actor de sistema** (la misma vía que el monitor; **nunca una cuenta centinela**), con el motivo y el `--by` informativo». El código hace lo contrario: tipo `person`, cuenta `0` (`consola:outreach:stop`) y, con `--by=<cuenta>`, esa cuenta y su identidad quedan como **quien abrió**, es decir, `--by` no es informativo: decide los cuatro ojos de la suelta (`release()` `:327`).
- **Reproducir:** `php artisan outreach:stop --all --reason="prueba"` → fila en `outreach_brakes` con `opened_by_type='person'`, `opened_by_admin_id=0`, `opened_by_person_id=NULL`. Lo fija la prueba `OutreachStopAndGateTest::test_outreach_stop_echa_la_parada_global_desde_la_consola_y_es_idempotente`.
- **Impacto:** no es un agujero (la consola es de root; soltarla exige igualmente `commercial_automation.release`, nota y otra cuenta: el centinela nunca coincide), pero el libro de frenos miente sobre el actor y el contrato tiene dos verdades. Pasar a actor de sistema exige que DB9 admita `opened_by_type='system'` con causa `manual` (hoy `obrk_cause_scope_chk` ata las causas de sistema a la lista cerrada del monitor) o que el lead enmiende L9-50 aceptando el centinela.
- **Dueño:** lead (decisión) → B9-run + DB9 si se implementa.

**M-2 · Los scripts de OPS9 no conocen el árbol integrado: el paso 0 de la fase 1 aborta y el resto marca desvíos.**
- **(a) «Solo añade» en `routes/console.php`.** `docs/ultron-studio/ops/deploy-h9-backend-fase1.sh:111` (`SOLO_ANADEN="backend/routes/console.php …"`) y `:412-415`: **`aborta`** si el fichero pierde una línea. Hoy `git diff --numstat HEAD -- routes/console.php` da **`59 4`**: Pint sustituyó cuatro nombres cualificados por `use` (`RetryElectronicInvoiceJob`, `SyncFactusInvoiceStatusJob`, `SyncMetaAdsInsights`, `CommercialAlertService`; `routes/console.php:3-6`, `:232`, `:238`, `:349`, `:383`), **fuera del bloque del H9**. El contrato (§11, traspasos) da a OPS9 «solo el bloque del H9»; el `informe-devops.md` §0 dice expresamente que no aplicó esos fixers. Es un diff fuera de alcance que además rompe la puerta del despliegue. Corrección mínima: revertir esas 8 líneas (vuelven los FQCN) y dejar solo el bloque `:473-523`.
- **(b) Prefijo y recuento de la ejecución.** `deploy-h9-backend-fase1.sh:171-173` cuenta la ejecución bajo `/api/admin/commercial-automation` y `:605` espera el Studio en `STUDIO_ROUTE_COUNT_WITH_STRATEGIES + 1 = 108`; con las 13 rutas bajo `admin/ultron-studio/outreach/**` (L9-57) el resultado real es **`121 30 0 0 0 1`** frente a `108 30 13 7 6 1` → `desvio`. `:635` consulta `admin/commercial-automation/status` (404). `smoke-h9.py:11`, `:71` (`C = '/admin/commercial-automation'`) y `:86`; `h9-outreach-scope.sh:643-646`: lo mismo (bloque OMITIDO y desvío). Esperado: `StudioRouteCount::TOTAL` (121) y el prefijo `admin/ultron-studio/outreach`.
- **(c) Tareas del programador.** `h9-outreach-scope.sh:87-88` (`TAREAS_PLANIFICADOR=2`, `TAREAS_MONITOR=1`; lógica `:624-636`) frente al bloque real `routes/console.php:491-523`: el monitor va **plano** dentro del bloque (L9-60c), así que con el programador encendido se agendan **3** tareas siempre; `programador on` con el monitor apagado dará desvío (3 ≠ 2). `smoke-run-h9.sh:57` ya espera 3.
- **(d) Puerta de diff con entradas viejas.** `deploy-h9-backend-fase1.sh:107-109` admite `routes/api.php` y `app/Http/Controllers/Api/Admin/UltronStudio/UltronStudioStreamController.php` (B-2 de SEC9, aún abierto); `:604` comenta que el canal vive en `routes/api.php`.
- **Reproducir:** `bash docs/ultron-studio/ops/banco-h9/ensayar.sh "$B" todo` sobre un origen con el árbol integrado (el ensayo de `informe-devops.md` §2.3 es anterior a L9-59).
- **Dueño:** OPS9 (b, c, d) y el lead (a: decidir si se revierte el retoque de Pint). Condición: banco re-ensayado en verde **antes** de fijar `PREVIO` (H-6) y de la fase 1.

**M-3 · La matriz §13.1 no está cubierta como dice `informe-run.md`.**
- **#23 y #25 (lado de la ejecución).** La cancelación en la etapa 6 por pausa (`strategy_paused`) o por versión fuera de la cadena (`strategy_moved`) existe (`app/Services/Outreach/Run/OutreachDispatcher.php:204-211`; en el barrido, `OutreachPlanner.php:345-352`), pero **ninguna prueba de `tests/Feature/Outreach/Run` la ejercita**: `grep -rn 'strategy_moved\|strategy_paused' tests/Feature/Outreach/Run` está vacío; `RunWorld::rollbackStrategy()` no se llama desde ninguna prueba y `RunWorld::pauseStrategy()` solo desde la deriva del monitor (`OutreachStopAndGateTest.php:284`). `informe-run.md` §0 afirma «#23 (cancelación por pausa/reversión en la etapa 6)»: no es cierto. La prueba de `test_15t` cubre **frenos**, no la cadena.
- **#6 y #7.** Solo la parte pura (`H9PolicyLadderN1Test`) y los adaptadores (`LeadAndVoiceFactsTest`, `AgendaEngineHistoryFactsTest`); ningún barrido de punta a punta con `lead.followup_due`, `class.upcoming_with_capacity` ni `valuation.outdated` (`grep` en `Run/` vacío). `H9LeadFollowupTest.php` y `H9EventSegmentTest.php` no existen (B9-core lo dejó en su Q-9).
- **#18, «freno si supera el umbral (P9-20)».** `OutreachMonitor` implementa `opt_out_spike`, `failure_spike` y `budget_breach`, pero solo `chain_unreadable` y `drift` tienen prueba (`OutreachStopAndGateTest.php:237`, `:275`); los tres umbrales solo los cubre el CHECK de DB9 (`OutreachLedgerSchemaTest.php:210`, `:339`).
- **#13.** La carrera de dos trabajadores es de nivel base (`OutreachConcurrencyTest`, DB9, PG); no pasa por el planificador.
- **#26.** El E2E `e2e/h9-nueva-estrategia` **no existe** en el CRM (`git status`: solo `e2e/h9-ui/` de FE9). `UltronStudioStrategyLifecycleTest` cubre la parte de la API.
- **`OutreachIsolationTest::exercise()`** sigue sin las 13 rutas ni las 5 órdenes (pendiente de L9-59; TEST9-b en curso).
- **Impacto:** no bloquea la fase 1 (apagado), pero la fase 2 (sombra) se apoya justo en #23/#25 (pausar y revertir como nivel 1 de la vuelta atrás, §9.2). **Dueño:** TEST9-b, antes de la fase 2. B9-run corrige la afirmación de su informe.

### MENORES

**m-1 · 43 de las 44 rutas no pasan por la puerta real del mapa en ninguna prueba HTTP.** `tests/Feature/UltronStudio/Strategies/StrategyFixtures.php:73` y `tests/Feature/Outreach/Run/OutreachApiTest.php:52` mantienen `withoutMiddleware(EnforceAdminAuthorization::class)` («hasta que B9-run clasifique las rutas»); ya están clasificadas. Solo el canal (L9-59(8)) y la guarda estática (`OutreachRoutesMapGuardTest`) prueban el mapa. Quitar las dos líneas (como en `StreamTestCase`) y confirmar el verde.

**m-2 · La bandeja asistida entrega a quien no reclamó el seudónimo junto con la identidad y el guion.** `app/Services/Outreach/Run/AssistedQueue.php:196-198`: `subjectRef` + `person: {kind, id}` (= el `subject_key`) + `script`, también en `filter=all|unclaimed`. Por diseño (D9-9.5, L9-61 Q-5) el enlace a la ficha necesita el id, pero (a) une seudónimo e identidad para cualquiera con `assisted_work` (Administrador tiene además `timeline_view` por defecto) y (b) el modelo de amenazas pedía el guion solo para quien reclamó (`ULTRON_STUDIO_H9_AMENAZAS.md:96`). Propuesta: `person` y `script` solo en `mine` o tras reclamar. Decisión del lead.

**m-3 · Comentario engañoso en el programador.** `routes/console.php:476` sigue diciendo «INERTE por defecto y, además, COMENTADO hasta que B9-run entregue las órdenes» con el bloque vivo (`:491-523`). Quien opere en producción lo leerá.

**m-4 · Dos verdades sobre `commercial_automation.simulate_real`.** El contrato (D9-13, `:780`) la reparte a «Super Admin y Administrador»; L9-59(5) y `app/Support/Access/CrmPermission.php:328-331` la dejan **solo en Super Admin** (más estricto). Verificado con `tinker`: Administrador recibe `ultron.strategies_edit`, `ultron.strategies_rollback`, `commercial_automation.{view,timeline_view,assisted_work,stop}`. Enmendar la tabla o el código.

**m-5 · Supresión 1581 y rotación de `APP_KEY` sin runbook para el H9.** La guarda lo imprime en cada pasada («supresion-1581.sql todavía no cubre: outreach_decisions, outreach_enrollments, outreach_jobs, outreach_outcomes»); `docs/ultron-studio/ops/supresion-1581.sql` no está tocado (R-3 de SEC9, L9-4, H-8 de OPS9). Antes de la fase 2 con datos reales.

**m-6 · Observación.** `OutreachStop::dto` y `AssistedQueue::dto` devuelven nombres del **personal** (`openedBy.name`, `releasedBy`, `claimedBy.name`): es el patrón del libro del H5 y no son datos de socios; lo anoto porque la consigna era «ningún GET devuelva nombre».

## 2. Lo que verifiqué ejecutando (salida real)

| Orden | Resultado |
|---|---|
| `php artisan route:list --path=ultron-studio` | **121** rutas bajo `api/admin/ultron-studio` (30 `strategies/**`, 13 `outreach/**`, 1 `stream`) = `StudioRouteCount::TOTAL` (77 + 30 + 1 + 13) |
| `php artisan auth:coverage --unmapped` | vacío, salida 0 |
| `php artisan schedule:list` (banderas por defecto) | 0 tareas `outreach:*` |
| `php artisan list outreach` | las 7 órdenes (`sweep`, `dispatch`, `monitor`, `reconcile`, `replay`, `stop`, `suppress-subject`) |
| `tinker`: `PermissionCatalog::all()` ∩ 10 llaves nuevas; `CrmPermission::byRole()`; `SHARED_TOKEN_EXCLUDED_DOMAINS` | 10/10 en el catálogo; Super Admin las 10; Administrador 6 (§1 m-4); Administrativo/Recepción/Entrenador ninguna; `commercial_automation` excluido del token |
| `php -d memory_limit=2G vendor/bin/phpunit --no-logging tests/Feature/Outreach/Guards tests/Feature/Outreach/Run/OutreachApiTest.php tests/Feature/Outreach/Run/OutreachStopAndGateTest.php tests/Feature/UltronStudio/Strategies/UltronStudioStrategyRoutesTest.php tests/Feature/UltronStudio/Stream/UltronStudioStreamFlagTest.php tests/Feature/UltronStudio/UltronStudioPermissionsTest.php tests/Feature/UltronStudio/UltronStudioVoiceRoutesTest.php tests/Feature/Access/AuthorizationCoverageTest.php` | **OK (69 tests, 10 172 assertions)**; la guarda de datos personales imprime el aviso de la supresión (m-5) |
| `php -d memory_limit=2G vendor/bin/phpunit --no-logging tests/Feature/Outreach/Run tests/Feature/UltronStudio/Strategies tests/Feature/UltronStudio/Stream` | **OK (109 tests, 1 575 assertions, 1 skipped)** — la omitida es `OutreachRaceTest` (#16, solo PG). La antigua «roja a propósito» de S9 (`test_authorization_map_clasifica_cada_ruta_con_su_llave`) pasa |
| `git diff --numstat HEAD -- routes/console.php` | `59 4` (M-2a) |
| `git diff` de `AuthorizationMap`, `CrmPermission`, `PermissionCatalog`, `StudioAuditEvent`, `routes/ultron-studio.php`, `routes/console.php`, `config/ultron_studio.php`, `.env.example` | lo descrito en L9-59: +44 entradas del mapa (+ `simulate_real` en `CONTROLLER_ENFORCED`), +10 llaves con etiqueta y descripción, dominio excluido, +4 sujetos del libro, bloque `strategies`, 19 variables apagadas |
| `grep` de aislamiento en `app/Services/Outreach`, `app/Console/Commands/Outreach`, `app/Jobs/Outreach`, `Stream/**`, `StudioStrateg*` | Importaciones del Studio desde `Outreach`: solo el puente (`StudioMessageTemplates`, `StudioStrategyValidator`), `Run/Stream/OutreachTopic` → `Stream\StreamEvent`/`StreamTopic` (L9-57 Q-2) y `Facts/MembershipFacts` → `PaymentController::PAID_STATUSES` (L9-28). Cero `Http::`, Guzzle, `curl`, sockets, Meta, outbox, `MarketingMessageDispatcher`, `OutboundGate`, ElevenLabs, Twilio, OpenAI, n8n, `otp.*`, `voice.twilio`. `Marketing\Ultron` solo `Facts\OperationalBrakes` (`UltronAbortLatch`). Ninguna escritura (`insert/update/delete/save/forceFill/lockForUpdate`) en `Facts/**` |
| CRM: `npx tsc -p tsconfig.app.json --noEmit` | salida 0 (el árbol real compila con el módulo de FE9) |
| CRM: `grep 'console\.' src/app/modules/commercial-automation --include='*.ts'` (sin specs) | nada |
| `git status` de los dos árboles | backend: 21 modificados + lo nuevo del H9; `UltronStudioVoiceController.php` **no** figura modificado (el 77 del H8 intacto). CRM: `app.html`, `app.routes.ts`, `e2e/h9-ui/`, `modules/commercial-automation/` |

## 3. Lo que solo leí

- **Puente y lectores (L9-51).** `OutreachPublishedStrategies` verifica cada entrada con `Core\StrategyChainSignature` (+ `row_mismatch`); la lista cerrada de 9 lectores de `ultron_studio_strategies` la fija `OutreachIsolationTest` (SEC9; no la corrí: TEST9-b la está ampliando).
- **Cuatro ojos por persona en S9.** `StudioPersonBindings::samePerson()` (`:130`: misma cuenta **o** misma identidad); `requirePerson()` en congelar (`StudioStrategyVersions.php:78`), aprobar/rechazar (`:435-440`), publicar/promover/pausar/reanudar/revertir (`StudioStrategyPublishing.php:80`, `:120`, `:165`, `:186`, `:212`, `:339`), plantillas (`StudioMessageTemplates.php:275`, `:337-342`). L9-39 cumplida.
- **Frenos.** `OutreachStop::release()` (`:296-339`): `lockForUpdate`, una vez, nota `PlainText` estricta sin teléfonos ni correos, 403 `four_eyes_required` si misma cuenta o misma identidad; `openBySystem()` solo con causas de la lista cerrada e incidente IRON GUARD; `OutreachMonitor` (`:47`) exige su bandera **y** la maestra, y solo abre (`:192`), nunca suelta.
- **Órdenes.** `sweep`, `dispatch`, `reconcile`, `monitor` revalidan la maestra y el modo (`OutreachConfig::enabled()`/`active()`); apagadas, salen con 0 y lo dicen (`OutreachPlannerTest::test_la_orden_sweep_apagada…` en verde).
- **API de la ejecución.** `OutreachAccess::require()` exige persona (`AdminActor::from()`) y la llave otra vez dentro; `timeline()` valida 64 hex, responde **200 con lista vacía** ante un seudónimo desconocido (sin oráculo) y anota `outreach.timeline.viewed` con `admin_id` y `subject_ref` (L9-57 Q-4); `enrollmentDto`/`jobDto`/`decision` exponen `subjectRef`, nunca `subject_key`, `member_id`, `lead_id`, teléfono ni importe; `AssistedQueue::claim()` reclama con `UPDATE … WHERE status='started' AND assisted_claimed_by_admin_id IS NULL` (una vez) y `recordOutcome()` exige `assisted_claimed_by_admin_id = cuenta` (403 `not_claimer`), conjunto cerrado del personal e idempotencia por `clientRequestId`.
- **Rutas y cerraduras.** Las 30 de S9 y las 13 de la ejecución están **dentro** del grupo `admin/ultron-studio` (tope de 64 KB, limitadores `ultron-studio-read/-write/-evidence`, `whereUuid`); el canal en su propio grupo con el mismo tope y `throttle:studio-stream`; el fichero se monta desde `bootstrap/app.php:41-44` con el grupo `api` (ProtectAdminPaths + EnforceAdminAuthorization). `StudioAccess::requirePerson()` exige persona y `ultron.view` antes de buscar nada.
- **Canal SSE.** El controlador exige puerta → persona → bandera (409 `stream_disabled` solo a quien ya entró) → por tema `access->can($topic->permission())` (+ `StreamViewerGate`); el tema sin permiso se omite y queda en `Log::notice`; sin ninguno, 403 `stream_no_topics`. Los 4 `outreach.*` (`Run/Stream/OutreachTopic`) declaran `commercial_automation.view` y `listRoute()` = su GET de lista; `StreamEvent` impone la lista blanca.
- **Migraciones de DB9.** `down()` se niega con filas (`100001:1262-1267`, `100002:1327-1331`), `REVOKE UPDATE, DELETE` en las de solo inserción y `REVOKE DELETE` en las de estado con comprobación posterior y guarda de producción, `lock_timeout` 5 s, ninguna `SECURITY DEFINER`, la 100003 fuera de transacción con `CONCURRENTLY` y reintento de índices inválidos. Anexo A aplicado (123/137/9 en PG, 108 en SQLite, 11 sujetos).
- **Guardas del H4–H8 adaptadas sumando.** Los recuentos comparan con `StudioRouteCount::TOTAL`; los recorridos del motor (`UltronStudioEngineReadOnlyTest`, `UltronStudioIntegrationsParityHttpTest`, `UltronStudioLabNoEngineEffectsTest`, `UltronStudioPermissionsTest`) excluyen `strategies/**`, `stream` y `outreach/**`; `UltronStudioVoiceController::STUDIO_ROUTE_COUNT` sigue en 77 sin tocar el fichero.
- **CRM.** Ruta `marketing/automatizaciones` tras `environment.ultronStudioPreviewEnabled` (true en `environment.prod.ts`, como el Studio) y `AuthGuard` con `Permission.ULTRON_VIEW` (`app.routes.ts:280-291`); entrada de menú con la misma condición (`app.html:132-138`); `toCaApiError()` convierte un 404 sin código en `strategies_unavailable`/`execution_unavailable` y reconoce `strategies_disabled`, `outreach_disabled`, `stream_disabled` como estados; `ca-live-indicator` consume `StudioStreamService.refreshes()` de `outreach.*` con sondeo de 30 s y nunca pinta el evento; la bandeja muestra `subjectRef` y «Socio #id» sin teléfono; el historial valida 64 hex antes de pedir.

## 4. Contrato ↔ código

### 4.1 Las 26 pruebas literales de §13.1

| # | Dónde está | Estado |
|---|---|---|
| 1, 2, 3, 14 | `OutreachPlannerTest::test_1/test_2/test_3/test_14` | ✓ (ejecutadas) |
| 4, 15 | `OutreachDispatcherTest::test_4`, `test_15` | ✓ |
| 5, 11 | `OutreachPolicyTest::test_5`, `test_11` | ✓ (PG de nivel base en `OutreachConcurrencyTest`) |
| 6 | `H9PolicyLadderN1Test` (parte pura) + `LeadAndVoiceFactsTest` (adaptador) | **parcial** (M-3) |
| 7 | `AgendaEngineHistoryFactsTest` (adaptadores) | **parcial** (M-3) |
| 8, 9, 12, 17, 21 | `tests/Unit/Outreach/Core/H9*Test.php` | ✓ |
| 10 | `OutreachOutcomesTest::test_10` | ✓ |
| 13 | `OutreachConcurrencyTest` (DB9, solo PG) | **parcial** (nivel base) |
| 16 | `OutreachRaceTest::test_16` (solo PG; omitida en SQLite) | ✓ (no la corrí en PG) |
| 18, 19, 20 | `OutreachDispatcherTest::test_18_19`, `test_20` | ✓ salvo el freno por umbral (M-3) |
| 22 | `OutreachDispatcherTest::test_22`, `OutreachOutcomesTest::test_22`, `OutreachApiTest` | ✓ |
| 23 | `UltronStudioStrategyRollbackTest::test_23` (gobierno) | **sin el lado de la ejecución** (M-3) |
| 24 | `OutreachDispatcherTest::test_la_conciliacion…`, `OutreachPolicyTest::test_t12_replay…` | ✓ (no PG) |
| 25 | `UltronStudioStrategyRollbackTest::test_25` (gobierno) | **sin el lado de la ejecución** (M-3) |
| 26 | — | **no existe** (TEST9-b) |

### 4.2 T10–T23

T10 `ConditionsParityTest`; T11 `UltronStudioStrategyValidationTest::test_t11_*` y `CorePrimitivesTest`; T12 `UltronStudioStrategySimulationTest::test_t12_*`, `OutreachPolicyTest::test_t12_replay…`, `DecisionTraceAndPurityTest`; T13 `H9ChannelSelectorTest`; T14 `OutreachOutcomesTest::test_t14_*`; T15 `OutreachStopAndGateTest` + `OutreachDispatcherTest::test_15t`; T16 `H9PolicyLadderN1Test` + fixture n1; T17 `OutreachPolicyTest::test_t17_*`; T18 `UltronStudioStrategyValidationTest::test_t18_*`; T19 fixture n4 (`PolicyAndChannelParityTest`); T20/T21 `UltronStudioStrategySimulationTest::test_t20_t21…`, `OutreachPolicyTest::test_t21…`; T22 fixture n7 (`PolicyAndChannelParityTest`); T23 `OutreachOutcomesTest::test_t23…`. Todas existen; T19 y T22 solo por paridad de fixtures (sin fichero propio), suficiente para el núcleo puro.

### 4.3 Decisiones estrictas de los informes frente al contrato

- B9-run §3.1 (traza como lista `[traza]` por `odec_trace_chk`): coherente con L9-54. §3.2 (`needs_review` → `human_requested` + `detail_code`): coherente con L9-12/L9-57 Q-3. **§3.3 (cuenta centinela): contradice L9-50 → M-1.** §3.4–§3.9: dentro del contrato.
- B9-facts: `receivable.has_overdue = false` para un prospecto sin ficha (no `unknown`), por el lado seguro de la escalera; L9-20/L9-26 lo admiten. `ContactHistoryFacts` memoriza por conexión (L9-45).
- S9: `Crm/UltronStudio/` y todo bajo `strategies/**` (L9-37); persona verificada también para pausar/revertir/congelar/plantillas (L9-39); evidencia que falla cerrado sin envíos (L9-40); `Facts` en solo lectura desde la simulación (L9-40, lista blanca de SEC9).
- Hechos de solo lectura: `FactsReadOnlyTest` (B9-facts) + `OutreachIsolationTest` (SEC9) + mi `grep` (§2). El puente como único importador de S9: `grep` (§2). La ejecución nunca escribe tablas del Studio: lista cerrada de 9 lectores en `OutreachIsolationTest` (L9-51, L9-56); por lectura, `Run/` toca `ultron_studio_strategies` solo en joins por `OutreachTables::STRATEGIES`.

## 5. Seguridad de las 44 rutas (T6)

- **Mapa.** 30 + 1 + 13 en `AuthorizationMap::OVERRIDES`, con las llaves de D9-13 e `informe-studio.md` §2; `commercial_automation.simulate_real` en `CONTROLLER_ENFORCED` (L9-60e) y exigido en `StudioStrategySimulations.php:91` para `sample`. `auth:coverage --unmapped` vacío.
- **Persona y token.** Las 44 pasan por `StudioAccess::requirePerson()` o `OutreachAccess::require()`: el token compartido (`AdminActor::from()` → null) recibe `person_required`; además `ultron` y `commercial_automation` están en `SHARED_TOKEN_EXCLUDED_DOMAINS` y `readOnly()` no incluye `commercial_automation.view`.
- **BOLA/IDOR.** Recursos por `uuid` (`whereUuid`), historial por `subjectRef` (HMAC, 64 hex, 200 vacío si no existe), reclamación una vez, resultado solo de quien reclamó, suelta a cuatro ojos por cuenta **y** persona. Lo que sí revela la bandeja está en m-2.
- **Cuerpos y cupos.** 64 KB en los tres grupos; `ultron-studio-read/-write/-evidence` en S9 y en la ejecución; `studio-stream` por huella del tique en el canal.
- **Datos personales en GET.** Ningún DTO de la ejecución ni del gobierno expone `subject_key`, teléfono, documento, cuerpo ni importe (`OutreachReadModels`, `AssistedQueue`, `OutreachStop`, los DTO de S9 y `OutreachPersonalDataGuardTest`); nombres solo del personal (m-6); el id del socio/prospecto solo en la bandeja (m-2, por diseño D9-9.5).
- **SSE.** El tema sin permiso se omite (no se revela) y los `outreach.*` exigen `commercial_automation.view`; el `ready` enseña solo los temas aceptados.
- **Herencia no del H9.** El tique del canal en la URL (R-1 de SEC9, P9-25) sigue siendo recomendación fuera del hito.

## 6. Todo apagado y reversible

`config/outreach.php` (maestra `false`, `mode` `off`, programador/monitor/asistido/WhatsApp/voz/clasificador `false`, cupos y presupuesto 0, `filter_var` estricto, un modo desconocido es `off`, horario propio ilegible = todo cerrado); `config/ultron_studio.php` bloque `strategies` (`enabled` y `publishing_enabled` `false`, ventana `07:00-19:00`, `simulation_gate` `required`); `config/ultron_studio_stream.php` (`enabled` `false`); `.env.example` con las 19 variables apagadas; `routes/console.php:491` solo agenda bajo `outreach.scheduler.enabled` (0 tareas con los defectos); `OutreachSendGate::SENDS_ENABLED_IN_CODE = false` (`automaticSendAllowed()` lo exige junto con `isLive()`, la bandera del canal y la puerta implementada; `LIVE_CHANNELS = ['simulated','assisted']`); PARADA GLOBAL por API (`POST outreach/brakes`, `scope: global`, `commercial_automation.stop`) y por consola (`outreach:stop --all --reason=`), ambas probadas; soltar solo a cuatro ojos. Con todo apagado: `OutreachFlagsGuardTest` y `OutreachStaticGuardsTest` en verde (ejecutadas); cero red y cero escrituras ajenas las demuestra `OutreachIsolationTest` (SEC9, no ejecutada por mí).

## 7. La integración del lead (L9-59/L9-60)

Verificado: mapa +44, catálogo +10 con etiqueta y descripción (orden de acciones nuevo), exclusión del dominio **en el mismo cambio** (R-2), `StudioRouteCount::TOTAL` = 121 y las 6 guardas de recuento comparando con él (`UltronStudioVoiceRoutesTest`, `UltronEngineIsolationTest`, `UltronStudioEarlyBodyCapTest`, `UltronStudioIntegrationsPermissionsTest`, `UltronStudioLabNoEngineEffectsTest`, `UltronStudioPermissionsTest`), `StudioAuditEvent::SUBJECT_TYPES` +4 por constantes de `StudioStrategyConfig`, Anexo A aplicado (recuentos y 9 parciales), `PendingTopic` sustituidos por los 4 adaptadores reales, el canal probando la puerta del mapa, el monitor plano con su propia bandera, programador descomentado tras las banderas. **Efectos colaterales en H4–H8:** solo las 4 sustituciones de Pint en `routes/console.php` fuera del bloque (M-2a); los 13 ficheros de prueba del H4–H8 cambian sumando, nunca el 77. `routes/api.php`, `UltronStudioVoiceController.php`, `Marketing/**`, `Commercial/**`, `Telephony/**` y `Voice/**` no figuran modificados.

## 8. CRM

Ruta y menú tras la bandera y `ultron.view` (§3); 409 `strategies_disabled` → estado «apagado», 404 sin código → `*_unavailable` (no error); llaves en `CA_PERMISSIONS` como espejo literal de D9-13 (Q-1 de FE9 resuelta por el lead: enum cuando se regenere el catálogo); cliente del canal sobre `StudioStreamService` con los temas `outreach.*` y sondeo de 30 s sin canal; sin `console.*`; `tsc` del árbol real en 0 (la incompatibilidad que SSE9-f vio en `commercial-automation-canonical.ts` ya no está). No ejecuté `ng test` ni `ng build` (cifras de FE9: 440/440 y build en verde).

## 9. No verificado (y por qué)

- La puerta de PostgreSQL (CHECK, disparadores, carreras #13/#16/#24): solo lectura de las migraciones y de los informes de DB9 y B9-run; no levanté un PG desechable.
- `OutreachIsolationTest` (cero red con la configuración de producción, escrituras por tramos, los 9 lectores): en edición por TEST9-b; me apoyo en SEC9 (L9-55/L9-56) y en los `grep` de §2.
- La regresión completa: la del lead (L9-62, `regresion-h9-integrada.txt`: 15 763 pruebas, 55 E + 1 F todos de la línea base). No la repetí (prohibido por carga).
- Mutaciones, E2E del CRM, `ng build`, y el banco de OPS9 contra el árbol integrado (M-2).
- Producción: nada.

## 10. Preguntas al lead

1. **M-1:** ¿se enmienda L9-50 (aceptar la cuenta centinela `0` y que `--by` sea quien abre) o se implementa el actor de sistema para `manual` (B9-run + un CHECK de DB9)?
2. **M-2a:** ¿se revierten las 8 líneas de Pint en `routes/console.php` para que el diff sea solo el bloque del H9 y la puerta «solo añade» vuelva a pasar?
3. **M-2:** ¿OPS9 re-ensaya `banco-h9` con el árbol integrado (prefijo `ultron-studio/outreach`, 121 rutas, 3 tareas) antes de fijar `PREVIO`? Es condición de la fase 1.
4. **M-3:** ¿la cobertura de #23/#25 en la etapa 6, #6, #7, los umbrales del monitor, `exercise()` y el E2E #26 queda como condición de la fase 2 (sombra), sin bloquear la fase 1?
5. **m-4:** `simulate_real`, ¿tabla D9-13 o código?
6. **m-2:** ¿`person` y `script` de la bandeja solo para quien reclamó?
7. **m-1:** ¿quito yo nada? No: lo pido a TEST9-b (dos líneas) para que las 44 rutas pasen por la puerta real en HTTP.

## 11. Segunda mirada parcial (10-11-2026, tras L9-63: lo corregido por el lead)

Alcance: M-2a, m-1, m-2, m-3, m-4 y la coherencia documental de M-1. **OPS9-b (M-2 b–d, m-5) y TEST9-c (M-3) siguen abiertos** y tendrán su tercera mirada.

| Punto | Comprobado (ejecutando salvo que diga «leído») | Estado |
|---|---|---|
| **M-2a** | `git diff --numstat HEAD -- routes/console.php` = **`48 0`**; `git diff HEAD … \| grep '^-[^-]'` vacío (ninguna línea borrada: el retoque de Pint está revertido); el bloque añadido agenda `outreach:sweep` (10 min), `outreach:dispatch --inline` (1 min) y `outreach:monitor` plano (10 min), los tres con `withoutOverlapping(10)->onOneServer()` bajo `config('outreach.scheduler.enabled')`; `php artisan schedule:list` con las banderas por defecto → 0 tareas `outreach` | **Cerrado** |
| **m-1** | `grep withoutMiddleware` vacío en `tests/Feature/UltronStudio/Strategies/StrategyFixtures.php` y `tests/Feature/Outreach/Run/OutreachApiTest.php`; `OutreachApiTest.php:96` espera **403 `forbidden` del mapa** para el token compartido (antes del `person_required` del controlador) y `:97` el 403 con `required_permission` para una cuenta sin la llave; `php -d memory_limit=2G vendor/bin/phpunit --no-logging tests/Feature/UltronStudio/Strategies tests/Feature/Outreach/Run tests/Feature/Outreach/Guards` → **OK (96 tests, 9 109 assertions, 1 skipped)** — la omitida es `OutreachRaceTest` (solo PG). Las 44 rutas pasan ya por la puerta real en HTTP | **Cerrado** |
| **m-2** | `app/Services/Outreach/Run/AssistedQueue.php:188-204`: `dto(object $j, ?int $viewerId = null)` calcula `$reclamante` (quien mira es quien reclamó) y devuelve `person` y `script` **solo** entonces; `queue()` (`:67`) pasa a quien mira y `claim()` (`:94`, `:109`) a quien reclama; `show()` (`:73`) sin visor → nulos (no tiene ruta); `recordOutcome()` no devuelve el DTO. `tests/Feature/Outreach/Run/OutreachOutcomesTest.php:71-84` (#22): nulos antes de reclamar, presentes para Ana tras reclamar, nulos para Beto. CRM (leído): `commercial-automation-api.types.ts:967-968` admite `person … \| null` y `script: string \| null`; `commercial-automation-parse.ts:1100-1112` los lee con `objetoONulo`/`textoONulo`; `ca-assisted.ts:71` (`@if (j.person; as p)`) y `:85` (`@if (j.script)`); `npx tsc -p tsconfig.app.json --noEmit` → 0 | **Cerrado** |
| **m-3** | `routes/console.php:473-490` (leído): ya no dice «COMENTADO»; explica que el bloque está activado (L9-58, L9-60), que el mando cuenta **3** tareas con el programador encendido y que el monitor va plano y comprueba su propia bandera | **Cerrado** |
| **m-4** | Contrato `:780` (leído): `commercial_automation.simulate_real` → «Super Admin por defecto (… enmendado en L9-63, m-4)», igual que `CrmPermission.php:328-331` y que el reparto que medí con `tinker` | **Cerrado** |
| **M-1** | L9-63 en el contrato (`:1522`); `informe-run.md:141` (§3.3) y el docblock de `OutreachStop.php:43-49` dicen exactamente lo que hace el código (cuenta de `--by` o centinela 0; soltar exige otra cuenta real con `release` y nota). **La única frase que sigue diciendo «actor de sistema … nunca una cuenta centinela» es la propia fila L9-50 (`:1509`), sin marca de enmienda:** quien la lea sola no sabe que L9-63 la sustituye. Propuesta: añadir «(enmendada por L9-63)» al final de esa fila. Nota documental, no de código | **Cerrado en código; nota doc MENOR** |
| **M-3** | `informe-run.md:12` y `:135` siguen afirmando la cobertura de #23 (cancelación por pausa/reversión en la etapa 6) sin prueba que la ejercite; lo corrige TEST9-c | **Pendiente (TEST9-c)** |
| **M-2 b–d, m-5** | Scripts de OPS9 (prefijo `ultron-studio/outreach`, 121 rutas, 3 tareas, `PREVIO`, B-2, re-ensayo del banco) y la supresión 1581 / rotación de `APP_KEY` | **Pendiente (OPS9-b)** |

**Veredicto tras la segunda mirada parcial:** sin cambios: APROBADO CON CONDICIONES, 0 bloqueantes; las condiciones vivas son M-2 (b–d) y m-5 (OPS9-b, antes de fijar el despliegue de la fase 1) y M-3 (TEST9-c, antes de la fase 2). No toqué ningún fichero fuera de este informe.

## 12. Tercera mirada, adelanto: el defecto D-1 de TEST9-c y la corrección L9-65 (10-11-2026)

Encargo del lead: comprobar la corrección de D-1 (una inscripción `waiting` no volvía a tocar) y que ningún otro camino deje una inscripción abierta **sin `next_due_at` ni trabajo vivo** (huérfana), y que el conciliador siga venciéndolas al final de la ventana. OPS9-b y TEST9-c siguen abiertos.

### 12.1 La corrección (verificada ejecutando y leyendo)

- `OutreachStore::dueEnrollments()` (`:133-144`): `status IN ENROLLMENT_OPEN_STATUSES` (`active`, `waiting`; `OutreachTables.php:48`), `next_due_at` no nulo y `<= now`, sin trabajo vivo. ✓
- `OutreachPlanner::planJob()` (`:585-597`): dentro de **una** transacción, reserva → `insertJob` (si choca con `ojob_one_live` lanza `JobCollision` y la transacción se deshace) → decisión `plan` → `next_due_at => null` y `waiting → active`. El NULL solo se escribe con el trabajo ya insertado; en `JobCollision` (`:604-606`) la inscripción queda a `+15 min` y en `BudgetExhausted` (`:599-602`) a la medianoche de Bogotá. Sin huérfanas por este camino. ✓
- `php -d memory_limit=2G vendor/bin/phpunit --no-logging tests/Feature/Outreach/Run` → **OK (63 tests, 987 assertions, 2 skipped)** (las omitidas, solo PG). Las dos pruebas de D-1 en `H9LeadFollowupTest.php:197` y `:227` (autorización tardía a las 24 h; `callback_requested` a su hora) en verde.

### 12.2 Auditoría de huérfanas: todos los escritores de estado y `next_due_at`

| Camino | Qué deja | Huérfana | Quién la cierra |
|---|---|---|---|
| `OutreachStore::insertEnrollment` (`:58-61`) | `active`, `next_due_at = now` | no | — |
| Planificador: `defer` (`:470`, `:686`), N2/pausa/freno (`:354`, `:503`, `:603`), cupo diario (`:598`), colisión (`:605`), presupuesto (`:601`) | abierta con `next_due_at` futuro | no | el propio barrido |
| Planificador: `handed_off` (`:491`), `attempts_exhausted` (`:568`), `sequence_done` (`:422`), ventana (`:710`), `strategy_moved`/`catalog_incompatible` (`:347`, `:701`) | cerrada | no | — |
| Despachador: ventana del trabajo vencida al reclamar (`:214-220`) | trabajo `expired`, reserva liberada, `next_due_at = now` | no | el barrido re-planifica |
| Despachador: `cancel(wait: true)` (`:191`, `:199`, `:211` → `:402`) | `next_due_at = +15 min` | no | el barrido |
| Despachador: `cancel(wait: false)` (`:205`, `:226`, `:234`) | cerrada en la línea siguiente | no | — |
| Despachador: `defer` (`:351`), N2 (`:380`), `handed_off` (`:369`) | con `next_due_at` o cerrada | no | — |
| Conciliador: `expirePlanned` (`:86-99`) | trabajo `expired`, `next_due_at = now` | no | el barrido |
| `FollowUpScheduler::apply` (`:84-97`) con transición (`next_step`, `retry_step`, `wait`, incluido `blocked` + `wait` a 24 h) | `waiting` con `next_due_at = notBefore` | no (ya con L9-65) | el barrido |
| `FollowUpScheduler::apply` con estado final | cerrada | no | — |
| `FollowUpScheduler::apply` **sin transición** (`nextJob = null`): `not_attempted` (sombra), `started` asistido a la espera del personal, `ambiguous`, y en W9 `sent`/`delivered`/`read` | **abierta, `next_due_at = NULL`, sin trabajo vivo** | sí, **por diseño** (D9-8: «la inscripción espera su ventana») | `OutreachReconciler::expireWindows` (`:104-120`): toda abierta (`active` o `waiting`) con `window_ends_at <= now` → trabajos `planned`/`claimed` a `expired`, resultado `no_response` (`system`) y cierre `expired`. ✓ |
| `FollowUpScheduler::decide` con la secuencia ilegible (`:42-48`: contenido no releíble) | `waiting` con `nextJob = null` → `next_due_at = NULL` | sí (misma clase) | el conciliador, al final de la ventana |

Conclusión: no hay huérfanas **accidentales**; las únicas abiertas sin `next_due_at` son las que esperan la ventana o una conciliación, y `expireWindows` las vence. Pero esa espera destapa dos cosas:

### 12.3 Hallazgos nuevos

**M-4 · Las inscripciones que esperan la ventana (`next_due_at = NULL`) no se cancelan al revertir o retirar la versión: quedan `active` bajo una versión `ROLLED_BACK` hasta 60 días y bloquean ciclos nuevos de la misma persona.**
- **Dónde:** el barrido solo recorre estrategias vivas (`OutreachPlanner::sweep` `:118`, `runnableStrategies()`) y solo toca inscripciones **vencidas** (`planDue` `:333` → `dueEnrollments`, que exige `next_due_at` no nulo); la cancelación `strategy_moved`/`catalog_incompatible` vive ahí (`:345-347`). El despachador cancela **trabajos** (`:204-211`), y el monitor solo mira trabajos vivos (deriva). Nadie recorre las inscripciones abiertas de una versión que dejó de estar viva.
- **Qué pasa:** en sombra **toda** inscripción, tras su primer paso simulado (`not_attempted`), queda `active` con `next_due_at = NULL` (fila anterior). Tras un `rollback`/`retire`, esas inscripciones no pasan a `canceled` como exige D9-8 («Las inscripciones de la versión revertida pasan a `canceled`») ni §13.1 #25 («Revertir cancela las inscripciones y trabajos de la versión revertida»): siguen `active` hasta `window_ends_at` (≤ 60 días) y salen como `no_response`. Mientras tanto, el índice parcial `oenr_one_open` (`2026_11_12_100002:436`: una `active`/`waiting` por `strategy_id`, `subject_key`, `mode`) impide que la versión restaurada o una nueva de la **misma estrategia** inscriba a esa persona en un ciclo nuevo (otra semana de inactividad, otro vencimiento). Las métricas enseñan inscripciones activas de una versión revertida.
- **Lo que no pasa:** nada sale: no hay trabajo vivo y uno nuevo exige `runnable()`. No es un riesgo de contacto; es una desviación del contrato y un freno a la reinscripción que se nota justo en la fase 2.
- **La prueba nueva lo fija al revés:** `tests/Feature/Outreach/Run/H9RollbackTest.php:147-148` afirma que, tras revertir, la inscripción del trabajo ya simulado sigue `'active'` («no toca lo enviado»). El contrato dice que lo que no se deshace es **lo enviado** (el trabajo y su resultado), no la inscripción.
- **Reproducir:** sombra; una estrategia publicada; un barrido que inscriba y simule el paso 1 (`not_attempted`, `next_due_at = NULL`); entrada `rollback` en la cadena; cualquier número de pasadas de `outreach:sweep`/`outreach:dispatch` → la inscripción sigue `active`; un barrido de la versión restaurada con otro `cycle_key` para la misma persona → `already_enrolled` (no inscribe) hasta `window_ends_at`.
- **Propuesta:** un paso del barrido (o del conciliador) que recorra las inscripciones abiertas cuya versión/parte ya no esté viva en la cadena (o sea `catalog_incompatible`) y las cierre `canceled: strategy_moved` (con su decisión `cancel`), sin depender de `next_due_at`; `H9RollbackTest:148` pasa a esperar `canceled` y añade el caso con `next_due_at = NULL`. Si el lead prefiere la lectura de B9-run, hay que enmendar D9-8 y #25 y aceptar el bloqueo de `oenr_one_open`. **Dueño:** lead (decisión) → B9-run + TEST9-c; condición de la fase 2.

**m-7 · La bandeja sigue listando trabajos `started` de inscripciones ya vencidas, y admite su resultado después del `no_response`.**
- `AssistedQueue::queue()` (`:53-58`) filtra `status = started` sin resultado `staff`, sin mirar el estado de la inscripción; `expireWindows` solo vence trabajos `planned`/`claimed` (`OutreachReconciler.php:111`), así que un asistido `started` al que nadie puso resultado sigue en la bandeja tras cerrarse su inscripción como `expired`/`no_response`. `recordOutcome` lo acepta: `OutcomeRecorder::record` inserta con `insertOrIgnore` (`:129`) sin comprobar que la inscripción esté abierta y `FollowUpScheduler::apply` queda en nada sobre una cerrada. Resultado: trabajo rancio en la bandeja y un resultado del personal registrado después del `no_response` del sistema (el historial enseña los dos). Sin riesgo de contacto. Propuesta: la cola excluye inscripciones cerradas (o las marca «vencida») y `recordOutcome` responde 409 sobre una inscripción cerrada. **Dueño:** B9-run; fase 3.

### 12.4 Pruebas nuevas de TEST9-c vistas en disco (pendientes de su cierre para la tercera mirada)

`H9EventSegmentTest.php`, `H9IdempotencyRaceTest.php`, `H9LeadFollowupTest.php`, `H9MonitorThresholdsTest.php`, `H9PauseCancelTest.php`, `H9RollbackTest.php` en `tests/Feature/Outreach/Run/` (cubren #6, #7, #13, #18 umbrales, #23 y #25 en la ejecución; M-3). Las revisaré una a una cuando TEST9-c cierre, junto con OPS9-b.

## 13. Lectura preliminar de L9-66 (M-4 y m-7 implementados por el lead), antes de la tercera mirada formal

Solo lectura (TEST9-c y OPS9-b están editando; no corrí suites). Lo que está bien y lo que no, con fichero:línea.

### 13.1 Lo que cuadra

- **Orden y condiciones de `OutreachReconciler::cancelOrphanedByChain()`** (`:114-142`): va tras `expirePlanned` y antes de `expireWindows` (`run()` `:54-57`), cuenta en `canceledByChain` y en `complete`/`fail` (`:58`, `:60`); con trabajo vivo deja la inscripción al despachador (`:127-136`); una estrategia **pausada** sigue `runnable()` (`LiveStrategy.php:68-72` no mira `paused()`), así que la pausa no cancela. ✓
- **`AssistedQueue`** (m-7): `QUEUE_ENROLLMENT_STATUSES = ['active','waiting','handed_off']` (`:57`), `queue()` filtra por ellos (`:65`); `claim()` comprueba la inscripción abierta (`:107`, `requireOpenEnrollment`) **después** de la repetición idempotente (`:101-104`) y antes del UPDATE; `recordOutcome()` repite primero (`:161-164`) y solo después lanza 409 `enrollment_closed` (`:166-168`), antes de `outcome_already_recorded` (`:169-171`). Incluir `handed_off` es correcto: la derivada a persona sigue siendo trabajo del personal. ✓

### 13.2 Hallazgos nuevos

**M-5 · El conciliador cancela inscripciones cuando la entrada de la cadena deja de verificar (`chain_unreadable`), con la etiqueta equivocada, cada minuto y sin mirar frenos.**
- **Cadena de causas:** `cancelOrphanedByChain()` salta solo si `$live !== null && $live->runnable()` (`:124`); `runnable()` = `chainReadable() && content !== null && estado/parte` (`LiveStrategy.php:68-72`) y `chainReadable()` = `chainVerified === 'ok'` (`:57-60`). `liveStrategies()` devuelve **también** las entradas que no verifican (docblock `OutreachPublishedStrategies.php:60-64`; `signature_mismatch` por `verifyRow` `:335`, `row_mismatch` `:41`/`:350`). Por tanto, una entrada con la firma rota llega como `$live` no nulo y no `runnable` → la inscripción se cierra `canceled` con `exit_reason = 'catalog_incompatible'` (`:137`: `$live === null ? 'strategy_moved' : 'catalog_incompatible'`), aunque la causa sea la cadena.
- **Qué manda el contrato:** D9-11.3: el puente «nunca lanza; si falla, esa estrategia queda en `chain_unreadable` (N5) y se abre un incidente; si la cabeza no verifica, se niegan **todas** y se abre el freno global» — **negar**, no cancelar; D9-3: `catalog_incompatible` = «denegadas, no reinterpretadas, hasta que se reaprueben» — también negar. Cancelar es irreversible (la ocurrencia queda consumida en la UNIQUE de ciclo).
- **Alcance:** `outreach:dispatch` ejecuta la conciliación en cada pasada salvo `--no-reconcile` (`OutreachDispatchCommand.php:37-38`), es decir, **cada minuto** con el programador; `run()` solo mira `OutreachConfig::active()` (`:47`), no la PARADA GLOBAL ni los frenos. Escenario concreto: rotar `APP_KEY` (R-3 de SEC9, L9-4) o un `row_mismatch` transitorio → toda la cadena deja de verificar → en ≤ 60 s **todas** las inscripciones abiertas sin trabajo vivo (en sombra, toda la audiencia) quedan `canceled: catalog_incompatible`, antes de que el freno global del monitor sirva de nada. Nada sale hacia nadie (no es riesgo de contacto), pero el daño sobre el estado es masivo e irreversible.
- **Ya venía de antes, con menos alcance:** el planificador (`OutreachPlanner.php:345-347`, solo las vencidas) y el despachador (`OutreachDispatcher.php:204-206`, solo el trabajo reclamado) hacen la misma conflación; L9-66 la extiende a todas las abiertas y a cada minuto.
- **Sin prueba:** ninguna de `tests/Feature/Outreach/Run` fija qué pasa con una inscripción abierta cuando su entrada deja de verificar (solo `OutreachStopAndGateTest.php:258-261`, el freno del monitor).
- **Reproducir:** sombra; una inscripción abierta con `next_due_at = NULL`; un `UPDATE` crudo de `signature` en la cabeza de su estrategia (SQLite en pruebas) o rotar `APP_KEY`; `outreach:dispatch --inline` → `canceled` / `catalog_incompatible`.
- **Propuesta:** en el conciliador (y en el planificador y el despachador, por coherencia): `$live === null` → `strategy_moved` (cancelar: la versión/parte salió de la cadena); `! $live->chainReadable()` → **no tocar** (N5 `chain_unreadable` niega, el monitor abre el freno; el estado se conserva hasta que la cadena vuelva a leerse); `content === null` → `catalog_incompatible` (si se decide cancelar, con esa etiqueta solo en ese caso); estado de la versión ≠ parte → `strategy_moved`. Y una prueba de TEST9-c con una firma rota que afirme que la inscripción **sigue abierta**. **Dueño:** lead → B9-run/TEST9-c. **Condición antes de encender la conciliación en sombra (fase 2).**

**m-8 · Coste de `cancelOrphanedByChain()` por pasada.** Carga **todas** las inscripciones abiertas del modo (`:121`) en cada conciliación (cada minuto) y recorre en PHP; con la sombra en marcha son todas las que esperan su ventana. El filtro cabe en SQL con el índice `oenr_version_idx (strategy_version_id, status)` (`2026_11_12_100002:890`): `whereNotIn('strategy_version_id', ids vivos)` más la parte, de modo que la pasada normal no lea ni una fila. PERF9 mide (D9-16: pasada del despachador < 10 s). **Dueño:** B9-run.

**m-9 · Las cancelaciones del conciliador y del planificador no dejan decisión.** `cancelOrphanedByChain()` (`:137`) y `OutreachPlanner::cancelEnrollment()` (`:699-702`) solo cierran la inscripción (`exit_reason`); el despachador sí escribe la decisión `cancel` (`:432`). D9-8 (etapa 6) y el historial por persona (decisiones, trabajos y resultados; L9-45) no la verán: la persona desaparece de la línea de tiempo sin explicación. Propuesta: una decisión `cancel` (N5, `[strategy_moved]`) como la del despachador. **Dueño:** B9-run.

**Q-M4 (pregunta):** `$vivas` se indexa por `versionId:part`. Tras **promover** (canario → resto), si el estado de la cadena deja `canary = null`, las inscripciones abiertas de la parte `canary` de esa misma versión quedan sin clave viva y se cancelan `strategy_moved` (el despachador ya hacía lo mismo con sus trabajos, B9-run §3.8). D9-8 solo nombra pausa y reversión. ¿Es lo que quieres (el canario termina al promover) o deben seguir bajo `rest`?

**Veredicto:** sin cambios (APROBADO CON CONDICIONES, 0 bloqueantes para la fase 1); M-5 se suma a M-4 como condición de la fase 2.

## 14. Tercera mirada (10-11-2026): TEST9-c, L9-66, L9-68 y OPS9-b · VEREDICTO FINAL

Lo único que queda fuera es el re-ensayo del banco con el smoke adaptado al 409 (H-15 de OPS9-b), que el lead señalará; el fichero ya lo trae (§14.4).

### 14.1 TEST9-c (M-3): verificado

- **Ficheros:** `tests/Feature/Outreach/Run/{H9PauseCancelTest (3), H9RollbackTest (5), H9LeadFollowupTest (4), H9EventSegmentTest (3), H9MonitorThresholdsTest (3), H9IdempotencyRaceTest (1, solo PG)}.php` + `Support/h13-worker.php`; `informe-tester-c.md`; tabla §0.1 de `informe-run.md` número a número (la fila 12 antigua queda corregida y #26 marcado «pendiente, TEST9-b»).
- **Qué cubren (leído método a método):** #23 en la ejecución (el reclamado se cancela `strategy_paused` con decisión `cancel` N5, la inscripción espera; en vivo libera las tres parcelas; en la planificación pausa y freno hacen esperar sin cancelar); #25 (el reclamado `strategy_moved` y su inscripción `canceled`; **la conciliación cancela las abiertas sin trabajo vivo de la versión revertida y no las de una pausada** —M-4, `H9RollbackTest:172`—, idempotente; la vencida en la planificación; la restaurada con sello nuevo sin repetir la ocurrencia; §13.3 reversión durante `dispatching`); #6 de punta a punta (barrido con `lead.followup_due`, N1 sin autorización, la autorización tardía a las 24 h y la llamada pedida a su hora: la regresión de D-1); #7 (clase con cupo solo al segmento elegible, `converted` al rechequear, `valuation.outdated`); #18 umbrales (`opt_out_spike` por estrategia con incidente y «nunca se suelta», `failure_spike` por canal con lo planificado esperando, `budget_breach` con el techo actual); #13 con dos procesos reales del planificador tras una barrera (PG).
- **Hallazgos de TEST9-c:** D-1 (corregido, L9-65; §12.1 de este informe); H-1 menor (`OutreachMonitor::run()` sin argumento usa el reloj real: en producción correcto, en pruebas hay que pasarle `now()`); O-1/O-3/O-4 confirmadas por el lead y O-2 superada por M-4 (L9-67).
- **Lo que TEST9-c no cubre:** M-5 (§13.2): ninguna prueba fija que una inscripción abierta **siga abierta** cuando su entrada de la cadena deja de verificar (`H9MonitorThresholdsTest:38` solo lo cita en un comentario; `OutreachStopAndGateTest:258-261` prueba el freno del monitor).
- **Ejecutado:** `tests/Feature/Outreach/Run tests/Feature/Outreach/Guards tests/Feature/UltronStudio/OutreachIsolationTest.php tests/Feature/UltronStudio/Strategies` → **OK (120 tests, 10 455 assertions, 2 skipped)**, las omitidas solo PG. Coincide con la pasada del lead.

### 14.2 L9-66 (M-4 y m-7): verificado, con M-5 abierto

- M-4: `OutreachReconciler::cancelOrphanedByChain()` (`:114-142`) como en §13.1; `H9RollbackTest:172` lo prueba (incluida la pausada, que no se cancela). ✓
- m-7: `AssistedQueue` (`:57`, `:65`, `:107`, `:161-168`, `:186-187`) como en §13.1. ✓
- **M-5 sigue tal cual:** `:124` decide por `runnable()` y `:137` etiqueta `catalog_incompatible`; `OutreachPlanner.php:345-347` y `OutreachDispatcher.php:204-206` igual. Pendiente de la decisión del lead (§13.2). **Condición de la fase 2.** Q-M4 (promoción y la parte canario) sin respuesta.

### 14.3 L9-68 (la puerta de L9-47): verificado

`self::requireEnabled()` en **9** métodos de `UltronStudioOutreachController` y **3** de `UltronStudioOutreachAssistedController` (12 = las 13 rutas menos `status`, que sigue 200 siempre), siempre **después** de `OutreachAccess::require()` (401/403 antes) y **antes** de validar o buscar; `OutreachException::disabled()` (`:74-77`) → 409 `outreach_disabled`. `OutreachApiTest` (5) en verde dentro de los 120. El CRM ya trataba `outreach_disabled` como estado (`commercial-automation-api.types.ts:1094`).

### 14.4 OPS9-b: verificado (ficheros, evidencia y sintaxis)

- `bash -n` de `deploy-h9-backend-fase1.sh`, `smoke-run-h9.sh`, `h9-outreach-scope.sh` y `python3 -m py_compile smoke-h9.py` → OK.
- **Despliegue:** `PREVIO=a608450` (`:88`); `SOLO_ANADEN` solo `routes/console.php` (`:119`; `git diff --numstat HEAD -- routes/console.php` = `48 0`); `PERMITIDOS_EN_PUERTA` = `console.php` + `app/Jobs/Outreach/DispatchOutreachJob.php` por ruta exacta (`:114-116`; B-2 retirado: ni `routes/api.php` ni el controlador viejo); `rutas()` cuenta la ejecución bajo `/api/admin/ultron-studio/outreach/` (`:184`) y el total es `studio_routes_total` = `StudioRouteCount::TOTAL` del código desplegado (`:613-618`: `121 30 13 9 4 1`); programador esperado `… 0` con la bandera apagada (`:631-632`); 401/413 sobre `admin/ultron-studio/outreach/{status,brakes}` (`:647`, `:662-663`).
- **Mando:** `TAREAS_PROGRAMADOR=3` (`:89`; el esperado depende solo del programador, `:623`); 401 sobre `admin/ultron-studio/outreach/status` (`:640-643`); `commercial-automation` desaparece de los cuatro guiones.
- **Smoke:** `C = '/admin/ultron-studio/outreach'` (`:76`); el bloque de la ejecución ya espera **409 `outreach_disabled`** en las 12 con la maestra apagada (`:450-520`, `:508`, `:520`) y `status` 200; las listas paginadas (`data.data`) corregidas (banco A, primera pasada). Falta solo el re-ensayo del banco con esta versión (el lead lo señala).
- **Supresión 1581:** sección del H9 en `supresion-1581.sql` (+276/−16): bloque A con las 7 tablas del gobierno y la autoría del personal de `outreach_*`; bloque B por persona sobre `outreach_enrollments/jobs/decisions/outcomes` (`subject_key → x:<id>`, `subject_ref` 64 ceros, `phone_ref` NULL, `redacted_at`, contadores `phone:` borrados, `DISABLE TRIGGER USER` transaccional, `lock_timeout` 5 s, `SET LOCAL ROLE :owner`, en seco por defecto, una fila `system.personal_data_redacted` en el libro). `OutreachPersonalDataGuardTest::SUPPRESSION_PENDING_OPS9 = []` (`:78`) y la guarda en verde.
- **Rotación de `APP_KEY`:** `ops/ROTACION_APP_KEY_H9.md` (95 líneas): qué deriva de la clave (`outreach-subject/1`, `outreach-phone/1`, `ultron-studio-strategy-chain`), la regla «PARADA GLOBAL y nivel 0 primero, después la clave, nunca al revés», y los pendientes con dueño (R-1 `outreach:rederive-refs`, el re-anclaje de la cadena como enmienda). Honesto: hoy rotar deja la cadena cerrada.
- **Evidencia:** `ops/evidencia-h9/2026-11-10/ops9b/` (`RESUMEN.md`, `banco-a/` 00–16, `banco-a-primera-pasada/`, `banco-b/`, `sql/`); `shasum -a 256 --check SHA256SUMS` → todo OK. Banco A (`PREVIO=HEAD`): paso 0 en verde, despliegue 0 desvíos, esquema 14/14, smoke 28 OK · 0 FALLO · 1 OMITIDO (presupuestos de PERF9), mando `programador on` 27 0 0 0 → 30 0 0 3 y `apagar` → 27 0 0 0; banco B (`PREVIO=a608450`): código 3 sin `--acepta-main` (los dos commits de docs del H8) y en verde con él, `121 30 13 9 4 1`, 27 0 0 0, 0 ERROR.
- L9-67 en el contrato (`:1526`) y §11 de `informe-devops.md` dicen lo mismo que los ficheros.

### 14.5 Puerta de diff y árbol

`git status --short` sobre `app/Services/{Marketing,Commercial,Telephony,Voice}`, `app/Jobs/Commercial`, `app/Observers/Commercial`, `app/Http/Controllers/Api`, `routes/{api,marketing,voice}.php`, `config/{commercial,marketing,automation,otp,voice}.php`, `StudioCatalog.php`, `composer.{json,lock}` → **vacío**. `routes/console.php` solo añade (48/0). `UltronStudioVoiceController.php` sin modificar (el 77 intacto).

### 14.6 Lo que sigue abierto (con dueño)

- **TEST9-b:** `OutreachIsolationTest::exercise()` sigue sin las 13 rutas de la ejecución ni `outreach:sweep|dispatch|reconcile|replay|suppress-subject` (solo `monitor` y `stop`, `:262`, `:413-414`): la garantía dinámica de «cero red con la configuración de producción» no recorre hoy el barrido ni el despacho (la guarda estática sí cierra `Http::`); y el E2E #26 (`e2e/h9-nueva-estrategia`) no existe (solo `e2e/h9-ui/`).
- **M-5** (lead → B9-run/TEST9-c), **Q-M4** (lead), **m-8** y **m-9** (B9-run), **H-1 de TEST9-c** (menor).
- **P9-24** (encender `COMMERCIAL_EVENTS_ENABLED` antes de la sombra), **PERF9** (presupuestos: el smoke los deja OMITIDO), **R-1 del runbook de rotación** (`outreach:rederive-refs`).
- Nota documental: la fila L9-50 (`:1509`) sin la marca «(enmendada por L9-63)».

### 14.7 VEREDICTO FINAL (la tabla de condiciones queda SUSTITUIDA por §16 tras L9-69 y el cierre de OPS9-b)

**APROBADO CON CONDICIONES. Bloqueantes: ninguno.** El Hito 9 puede ir a la **fase 1 (todo apagado)** cuando el lead reciba el re-ensayo del banco con el smoke del 409 (H-15) y el propietario dé la orden escrita; nada de lo abierto abre una vía hacia una persona.

| Fase | Condiciones antes de entrar | Dueño |
|---|---|---|
| **1 · Todo apagado** | (a) Re-ensayo del banco con el smoke adaptado al 409 (H-15), en verde; (b) orden escrita del propietario (§14 del contrato); (c) opcional, doc: marca de enmienda en la fila L9-50 | OPS9-b · propietario · lead |
| **2 · Sombra** | (a) **M-5**: no cancelar por `chain_unreadable` (ni etiquetar `catalog_incompatible` lo que es cadena) en conciliador, planificador y despachador, con su prueba de firma rota; (b) decisión Q-M4 (promoción y la parte canario); (c) TEST9-b: `exercise()` con las 13 rutas y las 5 órdenes, y el E2E #26; (d) m-8 (filtro en SQL del paso del conciliador) y m-9 (decisión `cancel` en las cancelaciones del conciliador y del planificador); (e) H-1 de TEST9-c; (f) P9-24 (`COMMERCIAL_EVENTS_ENABLED`) y las dos primeras personas vinculadas por consola; (g) PERF9 con los presupuestos de D9-16; (h) R-1 del runbook de rotación antes de cualquier rotación de clave | lead · B9-run · TEST9-b/c · propietario · PERF9 |
| **3 · Asistido** | m-7 ya hecho; P9-12 (quién trabaja la bandeja y cuántos al día) | propietario |
| **4+ · WhatsApp/voz** | W9/C9/V9 con sus enmiendas, la constante abierta en su commit, plantillas aprobadas y visto bueno legal (fuera de este hito) | — |

Verificado en esta mirada ejecutando: las cuatro suites (120 OK), `bash -n`/`py_compile`, `shasum --check`, `git status` de las zonas protegidas, `git diff --numstat` de `console.php`, los 12 `requireEnabled()`. Leído: las 19 pruebas nuevas, los dos informes, los cinco guiones, el runbook, la evidencia y el contrato (L9-65..L9-68). No ejecutado: la puerta PG, el banco de OPS9-b (solo su evidencia), el E2E, mutaciones.

## 15. L9-69 (M-5 corregido, Q-M4 decidida, m-8 hecho): verificado, con un hueco residual MENOR

**Ejecutado:** `tests/Feature/Outreach/Run tests/Feature/Outreach/Guards` → **OK (83 tests, 8 889 assertions, 2 skipped)**, igual que el lead.

**Leído y conforme.** Una sola regla, `LiveStrategy::orphanReason()` (`:82-93`): cadena que no verifica → `null` (nada se cancela); versión fuera del estado vivo de su parte → `strategy_moved`; cadena que verifica y `content === null` → `catalog_incompatible`. La usan los tres sitios: el conciliador (`OutreachReconciler.php:116-150`: pares `(strategy_version_id, part)` agregados sobre `oenr_version_idx`, filas solo de los huérfanos —m-8 hecho—, y con trabajo vivo lo deja al despachador), el planificador (`OutreachPlanner.php:343-360`: huérfana → cancela con su motivo; cadena que no verifica → espera +15 min) y el despachador (`OutreachDispatcher.php:203-215`: huérfana → cancela trabajo e inscripción; cadena que no verifica → `cancel(…, 'chain_unreadable', 'N5', wait: true)`: el trabajo se cancela y la inscripción espera). Q-M4: la promoción deja sin vida la parte canario → `strategy_moved` (coherente en los tres sitios; L9-1 evita el doble toque). El caso principal de M-5 —rotar `APP_KEY` o un fallo transitorio de firma con el estado intacto— queda cerrado: todas las entradas vienen con `chainVerified ≠ ok` y `orphanReason()` devuelve `null`.

**m-10 · Hueco residual (MENOR, solo por manipulación de la cadena): el par ausente cancela sin mirar si la cabeza verifica.**
- `OutreachPublishedStrategies::chainStates()` (`:292-317`) toma el `state` de la cabeza de cada estrategia **aunque `verifyRow` haya fallado** (`:307-313`); `liveStrategies()` (`:68-113`) construye los pares vivos desde ese estado. Si la cabeza es una entrada falsificada de `rollback`/`retire` (inserción cruda del dueño de la base con una firma que no verifica, o un `row_mismatch`), su estado vacío hace que el par de la versión que sí estaba viva **no exista**, y en los tres sitios `$live === null` → `strategy_moved` (`OutreachReconciler.php:127`, `OutreachPlanner.php:345`, `OutreachDispatcher.php:204`) sin pasar por `orphanReason()`. Lo mismo si la cabeza nombra una versión inexistente o de otra estrategia: `unreadable()` (`:387-397`) devuelve `versionId: 0`, así que el par real queda ausente.
- **Impacto:** cancelación irreversible (la ocurrencia del ciclo se consume) provocada justo por el tipo de manipulación que la firma existe para neutralizar; D9-11.3 manda no reinterpretar. Exige acceso de dueño a la base (la aplicación tiene `REVOKE` y el disparador de la cadena): por eso MENOR y no MAYOR.
- **Propuesta:** cuando el par esté ausente, consultar si la cabeza de **esa estrategia** verifica (exponer desde el puente, p. ej., `unverifiedStrategyIds()` a partir de `chainStates()`); si no verifica, tratar como cadena ilegible (esperar, no cancelar) en los tres sitios. Y que la prueba de «firma rota» de TEST9-c tenga la variante que importa: una entrada `rollback` falsificada (como la «entrada manipulada» de `UltronStudioStrategyFourEyesTest`) → las inscripciones abiertas **siguen abiertas**; romper la firma de una entrada que conserva la versión pasa ya con L9-69 y no demuestra nada nuevo.
- **Dueño:** lead → B9-run/TEST9-c; fase 2.

**Sigue pendiente de señal del lead:** las pruebas de TEST9-c (firma rota, contenido ilegible, promoción) y el re-ensayo del smoke de OPS9-b (último punto de la fase 1). m-9 queda para B9-run en la fase 2 (decisión del lead).

## 16. Cierre de la tercera mirada (10-11-2026): L9-69 re-verificado y el último punto de la fase 1 (H-15) cerrado

### 16.1 L9-69 (re-verificación que pidió el lead)

Ya está en §15, con fichero:línea: `LiveStrategy::orphanReason()` (`:82-93`) y su uso en el conciliador (`:116-150`, con el prefiltro por pares de m-8), el planificador (`:343-360`: cadena ilegible → espera +15 min) y el despachador (`:203-215`: cadena ilegible → `chain_unreadable` N5 con `wait: true`); Q-M4 decidida (promoción → `strategy_moved`). La fila L9-50 del contrato lleva ya «(enmendada por L9-63)» (verificado con `grep`). **Lo que en §14.7 figuraba como (a), (b) y (d/m-8) de la fase 2 está resuelto**; queda m-10 (§15), que es MENOR y solo por manipulación de la cadena.

### 16.2 El re-ensayo del banco con el smoke del 409 (OPS9-b, H-15): verificado

- **Sintaxis:** `bash -n` de los tres guiones y `py_compile` del smoke → OK.
- **`smoke-h9.py`:** la verdad de la fase la toma de `data.config.enabled` de `GET status` (`:474-486`); `EXEC_ROUTES` (`:452-459`) admite `{404, 409}`/`{409, 422}` y con la maestra apagada exige **409 `outreach_disabled` en las 12** (`:508`, `:520`: «también las lecturas; nada se lee ni se escribe»); `status` 200 siempre; los presupuestos de PERF9 con 409 pasan a OMITIDO (`:632-635`). **`h9-outreach-scope.sh`:** `estado` enseña en el nivel 0 que las 12 rutas responden 409 (`:299`) y lo avisa al encender la maestra (`:659`, `:681`).
- **Evidencia** `ops/evidencia-h9/2026-11-10/ops9b/banco-a-L9-68/` (13 ficheros: `01-montar` … `10-parar`, `correr.log`, `correr.sh`): `ops9b/SHA256SUMS` con **60 entradas** (13 de este re-ensayo) y `shasum -a 256 --check` → **todo OK**; `ops9b/RESUMEN.md` con la sección «Re-ensayo con la puerta de L9-68 (04:45–04:47 UTC)». En los registros: «COMPROBACIONES PREVIAS EN VERDE», «DESPLIEGUE H9 FASE 1 (BACKEND) COMPLETADO», rutas **`121 30 13 9 4 1`** (esperado igual), programador **`27 0 0 0`**, esquema **14 OK · 0 FALLO**, smoke **`RESUMEN (superadmin): 27 OK · 0 FALLO · 1 OMITIDO`** con «las otras 12 rutas de la ejecución con sesión y la maestra apagada → 409 outreach_disabled en todas», las 30 del gobierno → 409 `strategies_disabled`, el canal → 409 `stream_disabled` con tique y 401 sin él; mando `parada-global` **APLICADO** sin tocar el fichero de entorno y `estado` con el 409 en el nivel 0; cierre 2 OK; «ENSAYO DEL BANCO DEL H9: EN VERDE». §11 de `informe-devops.md` lo recoge (`:230`) y cierra H-15 (`:250`).
- **Pasada final sobre el árbol actual:** `tests/Feature/Outreach/Run tests/Feature/Outreach/Guards tests/Feature/UltronStudio/Strategies` → **OK (115 tests, 9 490 assertions, 2 skipped)**, las omitidas solo PG.

### 16.3 VEREDICTO FINAL y condiciones (lista definitiva; sustituye a §14.7)

**APROBADO CON CONDICIONES. Bloqueantes: ninguno.** Nada de lo abierto abre una vía hacia una persona.

| Fase | Condiciones antes de entrar | Dueño |
|---|---|---|
| **1 · Todo apagado** | **Solo la orden escrita del propietario** (§14 del contrato). Lo demás está: código, migraciones, 44 rutas clasificadas, token excluido, constante cerrada, banderas apagadas, programador inerte, guiones de OPS9 re-ensayados en verde con el 409 | Propietario |
| **2 · Sombra** | (a) TEST9-b: `OutreachIsolationTest::exercise()` con las 13 rutas y las 5 órdenes (`sweep`, `dispatch`, `reconcile`, `replay`, `suppress-subject`) para que la garantía dinámica de cero red cubra el barrido y el despacho, y el E2E #26; (b) las pruebas de TEST9-c sobre L9-69 (firma rota, contenido ilegible, promoción), con la variante de una entrada `rollback` falsificada → las abiertas siguen abiertas (m-10); (c) m-10 en los tres sitios (par ausente con cabeza que no verifica → esperar, no cancelar); (d) m-9 (decisión `cancel` en las cancelaciones del conciliador y del planificador); (e) H-1 de TEST9-c (menor); (f) P9-24 (`COMMERCIAL_EVENTS_ENABLED`) y las dos primeras personas vinculadas por consola; (g) PERF9 con los presupuestos de D9-16 (el smoke los deja OMITIDO); (h) R-1 del runbook de rotación (`outreach:rederive-refs`) antes de cualquier rotación de clave | TEST9-b/c · B9-run · propietario · PERF9 |
| **3 · Asistido** | m-7 hecho; P9-12 (quién trabaja la bandeja y cuántos al día) | Propietario |
| **4+ · WhatsApp/voz** | W9/C9/V9 con sus enmiendas, la constante abierta en su commit, plantillas aprobadas y visto bueno legal (fuera de este hito) | — |

**Verificado ejecutando a lo largo de las tres miradas:** `route:list` (121), `auth:coverage --unmapped` (vacío), `schedule:list` (0), `list outreach` (7), `tinker` (catálogo y reparto), las suites del H9 en cinco pasadas (69, 109, 96, 120, 115 pruebas en verde, con las omitidas solo de PG), `tsc` del CRM (0), `bash -n`/`py_compile`, `shasum --check` de las dos evidencias de OPS9-b, `git status` de las zonas protegidas (vacío) y `git diff --numstat` de `routes/console.php` (48/0). **No ejecutado:** la puerta PG, el banco de OPS9-b (solo su evidencia), el E2E del CRM, las mutaciones, producción.
