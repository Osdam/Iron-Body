# Informe de backend · Día de Bogotá en `commercial:scan`, O-1 y quejas que frenan el contacto

**11-11-2026 · backend · rama `feat/ultron-studio-h9`, árbol `_worktrees/ultron-h8/Iron-Body/backend`.** Empecé sobre HEAD `863788e2`. Durante la sesión otros agentes subieron `0f824a38` y `9502884a`, solo con documentación y guiones de operación (`git diff --stat 863788e2 HEAD`: 15 ficheros de `docs/`); `origin/main` está en `0f824a38`. Sin commit ni push. No he tocado producción, el CRM, `config/*.php`, `routes/*`, las migraciones ni los documentos de contrato.

Marcas: **[C]** comprobado ejecutando · **[L]** leído en el código, sin ejecutar · **[S]** suposición.

## Resumen para el lead

1. **D-1 y A-1, corregidos.** `commercial:scan` calcula el hoy, el ayer, los cortes y las claves con el día del gimnasio (`Member::BUSINESS_TZ`) en las cuatro familias. La prueba roja del tester pasa a verde sin tocarla.
2. **Las claves no cambian de forma.** Renovación y vencidas no pueden duplicarse. Inactividad y enlaces sí podrían, una vez, si se despliega entre las 19:00 y las 23:59 de Bogotá. **Desplegar fuera de esa franja.**
3. **O-1.** Nuevo recuento `repeated_subject`; lo que se evalúa no cambia.
4. **Q1-1.** Hay emisor real de `complaint_created` (`MarketingAiActionCommercialObserver`): una queja al día de Bogotá por conversación, sin texto ni teléfono.
   - **Registra DESPUÉS del commit** de quien crea la fila (`DB::afterCommit`), con la contención dentro del callback. Si esa transacción se revierte, no queda evento; sin transacción, se registra en el acto.
   - Motivo: en PostgreSQL, un INSERT fallido dentro del turno lo dejaría abortado (25P02) aunque el registrador se trague la excepción.
5. **Efecto lateral en el motor:** con el NBA apagado, como en producción, no se encola nada; aunque estuviera encendido, no se ejecutaría nada. Lo comprueba una sonda con el `commit` real de ULTRON.
6. **Q1-2.** Catálogo `outreach-catalog/2026-11-11.2` (huella `49b7d57e…`). `complaint_created` queda disponible y no disparador. Fixtures regenerados con `build.py`, que antes reproducía byte a byte los vigentes.
7. **Q1-3.** N6 `complaint_recent` con efecto `exit_complaint`: la inscripción sale. Niega en sombra y sin datos, y ninguna estrategia lo quita. Por defecto, 30 días (los de `human_requested`) para comercial y cobranza; el servicio no; 0 = apagado. Sin clave nueva en `config/`.
8. **Fuera del plan:** también la simulación del Studio (`StudioStrategySimulator` y `StudioStrategySimulations`). Hacía falta: arma su propia entrada de la escalera, que ahora la exige. Y el encargo pide que tampoco salga «en la simulación».
9. **Pruebas [C].** SQLite: `Unit/Outreach` 993, `Feature/Outreach` 651, `Commercial` 166 y estrategias del Studio 46, todo en verde. PostgreSQL: `Feature/Outreach` 651 en verde; en `Commercial`, mis 11 + 6 pasan y quedan 31 errores que ya estaban en HEAD (comprobado).
10. **Guardas:** 138, con 1 fallo: la puerta de diff, que lista **solo el observador nuevo**. La puerta no vigila `Console/Commands` ni `Providers`.
11. **Mutaciones [C]:** 46 de 46 muertas (10 de la parte 1 y 36 de la parte 2), en una copia fuera del árbol, con el sha256 restaurado. Entre ellas, «volver a la llamada directa», que pone en rojo la prueba (a) en SQLite y en PostgreSQL. Un primer intento de mutar `replay` sobrevivió por un error mío al elegirlo: era equivalente. Lo cuento en §2.9.
12. **Pendiente de otros:**
    - el CRM: `sync-h9-fixtures.mjs` y los textos `complaint_recent` y `exit_complaint`;
    - `devops`: `h9-outreach-scope.sh:941`, la supresión 1581 de `commercial_events` y la clave de `config/outreach.php` en el siguiente despliegue;
    - `security`: Q1-6;
    - `outreach:replay` con una decisión anterior al cambio ya no se cae: dice `not_reproducible / ladder_input_outdated` (§2.5).
    - Hallazgo adyacente, sin tocar: los demás observadores registran dentro de la transacción del pago, con el mismo riesgo en PostgreSQL (§2.11).

---

## Parte 1 · D-1, A-1 y O-1

### 1.1 Ficheros (lista exacta)

| Fichero | Estado | Qué |
|---|---|---|
| `app/Console/Commands/ScanCommercialFacts.php` | modificado | D-1 y A-1: las cuatro familias cuentan con el día del gimnasio |
| `app/Services/Outreach/Run/OutreachPlanner.php` | modificado | O-1: recuento `repeated_subject` |
| `tests/Feature/Outreach/Run/OutreachPlannerTest.php` | modificado | prueba nueva `test_14b_…` (O-1) |
| `tests/Feature/Outreach/Run/H9MembershipExpiredBogotaDayTest.php` | **nuevo** | la gemela de D-1 para `membership_expired` |
| `tests/Feature/Commercial/ScanCommercialFactsBogotaDayTest.php` | **nuevo** | las cuatro familias del escáner en sus horas límite de Bogotá |

No he tocado los tres ficheros del tester. `H9RenewalWindowBogotaDayTest.php` pasa a verde sin cambios.

> **Árbol compartido.** Durante la sesión han aparecido en el árbol cambios que **no son míos** y que no he tocado: `app/Services/UltronStudio/StudioLabChecks.php` y `StudioRuleCoherence.php`, `tests/Feature/UltronStudio/UltronStudioDraftTest.php` y `UltronStudioVersionFormatTest.php`, `tests/Unit/UltronStudio/StudioRuleCoherenceTest.php`, `tests/fixtures/ultron-studio/coherence-cases.json` y, en `docs/ultron-studio/`, `ULTRON_STUDIO_G_CONTRACT.md`, `ULTRON_STUDIO_CHECKLIST_OPERATIVO.md`, `ULTRON_STUDIO_H8_COMPLETITUD.md` y `ULTRON_STUDIO_ACTIVACION_POR_ETAPAS.md`. Hay otro agente trabajando a la vez. Las listas de este informe son solo las mías.

### 1.2 Qué cambió (D-1 y A-1)

**La zona.** El sistema ya tiene una constante para «el día del negocio»: `Member::BUSINESS_TZ = 'America/Bogota'` (`app/Models/Member.php:44`). La usan `MembershipService::endsAt()`, `MembershipPeriod`, `ReportRange`, `BusinessClock` de ULTRON y `GrantTesterDemoAccess`. El H9 usa `RelativeTime::ZONE`, con el mismo valor. Uso `Member::BUSINESS_TZ`: es la que define cuándo vence una membresía, y el comando ya importaba `Member`. No he añadido ninguna configuración.

**Una sola definición del «hoy».** `ScanCommercialFacts.php:256-259` añade `gymToday()`, que devuelve `Carbon::today(Member::BUSINESS_TZ)` (la medianoche de hoy en Bogotá). Las cuatro familias la usan:

| Familia | Línea | Antes (UTC) | Ahora (día de Bogotá) |
|---|---|---|---|
| Ventana de renovación (D-1) | `:91` | `$today = Carbon::today()` | `self::gymToday()` |
| | `:104` | `Carbon::parse($fin)` (medianoche UTC) | `Carbon::parse($fin, Member::BUSINESS_TZ)->startOfDay()`. Sin esto, `days_to_expiry` saldría como 10 días menos 5 horas (9,79): lo mata la mutación P1-M2 |
| Vencidas (A-1) | `:132` | `Carbon::yesterday()` | `self::gymToday()->subDay()` |
| Inactividad (A-1) | `:168-175` | corte `Carbon::today()->subDays(14)` y vigencia `>= Carbon::today()` | `$today = self::gymToday()`, con su corte y su vigencia |
| | `:201`, `:205` | `days_inactive` hasta la medianoche UTC; semana `Carbon::today()->format('o-\WW')` | hasta la medianoche de Bogotá; semana ISO de Bogotá |
| Enlaces abandonados | `:248` | clave `tx:<id>:abandoned:` + `now()->format('Y-m-d')` (día UTC) | + `gymToday()->format('Y-m-d')` (día de Bogotá) |

- Los cortes de los enlaces (`now()->subHours(6)` y `now()->subDays(7)`) son **intervalos de tiempo transcurrido**, no días de calendario. No dependen de la zona y no los he tocado.
- La cuarta familia: el encargo dice «las CUATRO familias», y la de los enlaces solo tenía un «hoy» en la clave, que es el que cambio. El docblock de la clase (`:31-37`) explica el porqué.
- `pint` añadió `use App\Services\MembershipService;` (`:12`). Lo hizo por la referencia `{@see}` del docblock de `scanExpired()`; no añade ninguna dependencia en ejecución.

### 1.3 Las claves de deduplicación

**No cambian de forma.** Siguen siendo `renewal:<usuario>:<Y-m-d>`, `expired:<usuario>:<Y-m-d>`, `inactive:<socio>:<o-\WW>` y `tx:<id>:abandoned:<Y-m-d>`. Las pruebas comparan las claves completas.

¿Puede emitirse dos veces un hecho el día del cambio? [L, razonado sobre el código; las claves sí las fijan las pruebas]:

| Familia | ¿Depende la clave del «hoy»? | Riesgo el día del despliegue |
|---|---|---|
| Renovación | No: usa la fecha de fin | **Ninguno.** Quien ya recibió el evento con 11 días no lo recibe otra vez, ni con 10. Nadie se queda sin evento: quien entra en la ventana después del cambio lo recibe a las 00:00 de Bogotá |
| Vencidas | No: «ayer» = la fecha de fin | **Ninguno.** Con los dos cálculos la fecha de fin da la misma clave. El cálculo viejo cubría la fecha D entre las 19:00 de D y las 18:59 de D+1; el nuevo, entre las 00:00 y las 23:59 de D+1. Se solapan: no hay ni hueco ni duplicado |
| Inactividad | Sí: la semana ISO | La semana solo difiere **el domingo entre las 19:00 y las 23:59 de Bogotá**. Si se despliega ahí, un socio que el cálculo viejo cogió a las 19:00 con la semana N+1 puede recibir otro evento con la semana N, menos de cinco horas después. Es el socio cuyo último ingreso cae en las 5 horas en que se mueve el corte |
| Enlaces abandonados | Sí: el día | El día solo difiere **de 19:00 a 23:59 de Bogotá, cualquier día**. Un enlace que cumplió su plazo de cortesía entre las 19:00 y el despliegue lleva la clave del día UTC siguiente; tras el despliegue, recibe otra con el día de Bogotá |

**Propuesta (sin código):** desplegar fuera de las **19:00–23:59 de Bogotá** (00:00–04:59 UTC). Así ninguna de las cuatro familias puede duplicar. Si hubiera que desplegar en esa franja, el peor caso es un evento repetido por persona, una vez. En producción hoy hay 0 estrategias, así que el H9 no lo convertiría en contactos. El motor NBA está apagado (§2), así que tampoco decidiría nada.

### 1.4 Quién más consume estos eventos y qué cambia para cada uno [L]

| Consumidor | Qué lee | Qué cambia |
|---|---|---|
| Motor comercial: `CommercialEventRecorder::queueEvaluation()` → `EvaluateCommercialSubject` → `NextBestActionEngine` | El evento solo dispara la evaluación del **estado** de la persona; el motor no mira el tipo de evento ni el payload (en `app/Services/Commercial` nadie lee `->event`) | Solo con `commercial.enabled`, que en producción está apagado: nada. Encendido, la reevaluación llegaría 5 horas más tarde y con un estado coherente (la membresía ya vencida, la ventana con 10 días) |
| `commercial:evaluate-pending` | Igual, y solo con `commercial.enabled` | Nada en producción |
| Supervisión (`SupervisionController.php:174-193`) | `occurred_at`, `event` y la etiqueta | Estos hechos aparecen a las 00:00 de Bogotá y no a las 19:00 del día anterior. La renovación aparece un día más tarde, con 10 días y no con 11. El recordatorio del enlace sale una vez por día de Bogotá |
| Bandeja (`InboxContextService.php:479-488`) | Los 20 últimos eventos de la persona (`occurred_at`, `event`) | Lo mismo que en supervisión |
| Analítica (`CampaignAnalyticsService::topObjections()`) | Solo `objection_raised` | Nada |
| H9, ingesta (`OutreachPlanner::ingest()`) | `id`, `event`, `member_id`, `marketing_lead_id` y `occurred_at` (sin payload) | **Lo que corrige D-1 y A-1.** El hecho llega el día de Bogotá en que es verdad: la audiencia `days_to_expiry ≤ 10` lo acepta y `membership.active = false` también |
| H9, hechos (`EngineFacts::events()` y `transitionsFrom()`) | `occurred_at`, y `plan_id` solo en los eventos de pago | El `at` de estos eventos se mueve a la medianoche de Bogotá. La transición `membership: active → expired` coincide ahora con la de `MembershipFacts::lastTransitions()`, que ya la ponía a las 00:00 de Bogotá del día siguiente (`MembershipFacts.php:89-96`). En las ventanas de «hace N días», el hecho cuenta en su día de Bogotá correcto |
| Payload | `days_to_expiry`, `expired_at`, `days_inactive` | Ningún lector en `app/` [L: `grep`]. `days_to_expiry` vale ahora los días de Bogotá: 10 donde antes daba 11 en la pasada de las 19:00. `days_inactive` sigue siendo un real de Carbon 3 y crece 5/24 |

**Observación, no la cambio.** El corte de la inactividad del motor sigue siendo «último ingreso ≤ medianoche de hoy − 14 días». El `member.inactive` del H9 cuenta «≤ 14 días de calendario» (el borde de 14 días justos sí es inactivo para el H9). Por eso, para un mismo socio, el evento `inactivity_detected` puede llegar un día después de que el H9 lo considere inactivo. Es la semántica del umbral, no la de la zona, y el encargo no la incluía.

### 1.5 O-1 · `repeated_subject`

- `OutreachPlanner.php:251` añade el recuento al resultado y `:255` lo cuenta.
- La segunda aparición, y las siguientes, de la misma persona en la misma página o lote suma `repeated_subject` y **sigue sin evaluarse**: no cambia lo que se evalúa.
- El nombre sigue la forma de `unknown_subject`.
- El docblock (`:237-243`) deja escrito el reparto: `candidates` = `repeated_subject` + `no_cycle_key` + `unknown_subject` + `already_enrolled` + `open_enrollment` + `cooldown` + `evaluated`. La excepción es la inserción que pierde la carrera, que ya contaba en `evaluated` y en `already_enrolled`.
- Los recuentos se guardan en `outreach_runs.counts`, un JSON libre acotado a 4 KB (`OutreachRuns::boundedCounts()`). Las pruebas del tester comparan solo las claves que eligen (`self::pick()`), así que añadir una clave no rompe nada.
- **CRM:** si pinta los recuentos con una tabla de textos, falta la etiqueta de `repeated_subject` (por ejemplo, «repetido en la misma página»). No lo he comprobado.

### 1.6 Pruebas nuevas

| Prueba | Qué fija |
|---|---|
| `H9MembershipExpiredBogotaDayTest::test_membership_expired_llega_tras_la_medianoche_…` | Gemela de D-1, con el emisor real y las clases reales del H9. Pasadas a las 18:59:59, 19:00:00 y 23:59:59 de Bogotá del 17-11, a las 00:00:00 y 10:00 del 18-11 y a las 00:00 del 19-11. En cada una: `MembershipService::isActive()` (activa hasta las 23:59:59), el número de eventos y el de inscripciones. El hecho llega a las 05:00:00Z y la persona se inscribe en esa pasada (`{candidates 1, enrolled 1, not_in_audience 0}`). Fija también la clave `membership_expired:expired:<usuario>:2026-11-17` y `expired_at` |
| `ScanCommercialFactsBogotaDayTest` (6 pruebas) | Renovación: nada a las 18:59:59, 19:00 ni 23:59:59; a las 00:00 sale la clave con `days_to_expiry = 10`. Vencidas: lo mismo, y no se repite a las 23:59:59 del día siguiente ni a las 00:00 del otro. Semana de la inactividad: W47 todo el domingo de Bogotá y W48 a las 00:00 del lunes. Corte: la visita de las 21:00 de Bogotá del sábado sale a las 00:00 del domingo, con `days_inactive = 14.125`. Vigencia: un socio cuyo último día es hoy cuenta como vigente a las 19:00. Enlace: una clave por día de Bogotá |
| `OutreachPlannerTest::test_14b_…` | Cuatro eventos en un lote, tres de Ana: `{candidates 4, evaluated 2, enrolled 2, repeated_subject 2}`. Se cumple el reparto. Se inscribe la **primera** aparición (`ce:<id del primer evento>`). En otro lote, la misma persona cuenta como `open_enrollment` y `repeated_subject` queda en 0 |

### 1.7 Ejecuciones [C]

`nice -n 10 php -d memory_limit=2G vendor/bin/phpunit --no-logging …`, una cada vez:

| Batería | Motor | Resultado |
|---|---|---|
| Los 3 ficheros del tester (D-1 y sus vecinos) tras la corrección | SQLite | **OK, 11 pruebas, 212 aserciones** (D-1 en verde sin tocarla) |
| `ScanCommercialFactsBogotaDayTest` | SQLite / PostgreSQL 14 | **OK, 6 / 43** en los dos |
| `H9MembershipExpiredBogotaDayTest` | SQLite / PostgreSQL 14 | **OK, 1 / 28** en los dos |
| `H9RenewalWindowBogotaDayTest` (D-1) | PostgreSQL 14 | **OK, 1 / 19** |
| `OutreachPlannerTest` | SQLite / PostgreSQL 14 | **OK, 8 / 89** en los dos |
| `H9CrmEventTriggersEndToEndTest` y `H9SweepTriggersEndToEndTest` | PostgreSQL 14 | **OK, 4 / 78** y **OK, 6 / 115** |
| `tests/Feature/Outreach/Run` + `tests/Feature/Commercial` | SQLite | **OK, 253 pruebas, 1 958 aserciones, 2 omitidas** (las dos «Solo PostgreSQL» de siempre) |
| Guardas estáticas (la orden del encargo, 15 ficheros) | SQLite | **OK, 138 pruebas, 178 690 aserciones** |
| `vendor/bin/pint --test` sobre los 5 ficheros | — | `passed` |

PostgreSQL: `UNIT_DB=ultron_studio_quejas_unit` (creada en el clúster desechable 127.0.0.1:55432; PostgreSQL 14.24).

**La puerta de diff no lista `ScanCommercialFacts.php`, y está bien.** `OutreachIsolationTest::DIFF_GATE` (`:160-165`) vigila `app/Services/Commercial`, `app/Jobs/Commercial`, `app/Observers/Commercial` y `config/commercial.php`. No vigila `app/Console/Commands` ni `app/Providers`. Por eso, tras la parte 1, las 138 guardas pasan. La parte 2 sí la pone en rojo (§2.7).

### 1.8 Mutaciones [C]

- **Dónde:** en un clon APFS del backend (`cp -c -R`, con `vendor`) en el directorio temporal de la sesión, fuera de cualquier árbol de git. Así el otro agente que trabaja en el árbol no ve código mutado.
- **Cómo:** el guion `mutate.py` aplica cada mutante, pasa `php -l`, ejecuta las pruebas y restaura el fichero comprobando su sha256.
- **Al final:** `diff -rq` entre el `app/` del clon y el del árbol da idéntico.

| Mutante | La matan | sha256 al restaurar |
|---|---|---|
| P1-M1 · la ventana vuelve a `Carbon::today()` | `test_la_ventana_…` y **D-1 del tester** | = original |
| P1-M2 · fin de membresía sin zona (9,79 días) | `test_la_ventana_…` (`days_to_expiry`) | = original |
| P1-M3 · vencidas con `Carbon::yesterday()` | `test_membership_expired_no_llega_…` y la gemela H9 | = original |
| P1-M4 · inactividad entera con el «hoy» UTC | las 3 de inactividad | = original |
| P1-M5 · solo la semana en UTC | `test_la_semana_…` | = original |
| P1-M6 · solo la vigencia en UTC | `test_la_inactividad_cuenta_la_membresia_vigente_…` | = original |
| P1-M10 · solo el corte en UTC | `test_el_corte_…` | = original |
| P1-M7 · clave del enlace con el día UTC | `test_el_enlace_abandonado_…` | = original |
| P1-M8 · O-1: la repetición no se cuenta | `test_14b_…` | = original |
| P1-M9 · O-1: se cuenta y además se evalúa | `test_14b_…` | = original |

**10 de 10 muertas.** P1-M1 a P1-M7 se aplicaron antes de que `pint` añadiera el `use` y reflujera el docblock de `scanExpired()`. Por eso la pasada final (§2.9) las repitió las diez sobre los ficheros definitivos, y las diez volvieron a morir.

---

## Parte 2 · Que una queja frene el contacto automático

Lo implementado es Q1-1, Q1-2 y Q1-3 del plan (`plan-quejas-consentimiento-cuatro-ojos.md` §1, opción B), con estas diferencias, que señalo donde aparecen:
- Hay dos ficheros del Studio que el plan no nombra (§2.4).
- `source` es un código cerrado y no `metadata.origin`: esa clave no existe en las filas de la IA (§2.2).
- No hay clave en `config/outreach.php`, por orden del lead.

### 2.1 Ficheros (lista exacta, por pieza)

**Q1-1 · el emisor** (los dos primeros son los que autorizó el lead):

| Fichero | Estado |
|---|---|
| `app/Observers/Commercial/MarketingAiActionCommercialObserver.php` | **nuevo** |
| `app/Providers/CommercialEventServiceProvider.php` | modificado (+3 líneas: dos `use` y la entrada de `OBSERVERS`) |
| `tests/Feature/Commercial/ComplaintEventWiringTest.php` | **nuevo** |

**Q1-2 · el catálogo:**

| Fichero | Estado |
|---|---|
| `app/Services/Outreach/Core/OutreachCatalog.php` | modificado |
| `tests/fixtures/outreach/tools/build/sec_catalog.py` | modificado |
| `tests/fixtures/outreach/catalog-snapshot.json` | regenerado |
| `tests/fixtures/outreach/conditions-cases.json` | regenerado (solo la cabecera) |
| `tests/fixtures/outreach/strategy-content-cases.json` | regenerado (la cabecera y DF-K4) |
| `tests/Unit/Outreach/Core/OutreachCatalogEmittersTest.php` | modificado |
| `tests/Unit/Outreach/Core/OutreachCatalogTest.php` | modificado |

**Q1-3 · el freno en N6:**

| Fichero | Estado |
|---|---|
| `app/Services/Outreach/Core/PolicyLadder.php` | modificado |
| `app/Services/Outreach/Core/DecisionTraceText.php` | modificado |
| `app/Services/Outreach/Run/LadderInputBuilder.php` | modificado |
| `app/Services/Outreach/Run/OutreachConfig.php` | modificado |
| `app/Services/Outreach/Run/OutreachPlanner.php` | modificado (**también lleva O-1 de la parte 1**; ver la nota) |
| `app/Services/Outreach/Run/OutreachDispatcher.php` | modificado |
| `app/Services/Outreach/Run/LiveStrategy.php` | modificado |
| `app/Services/Outreach/Run/OutreachReplay.php` | modificado (blindaje pedido por el lead: `ladder_input_outdated`, §2.5) |
| `app/Services/UltronStudio/StudioStrategySimulator.php` | modificado (no está en el plan; §2.4) |
| `app/Services/UltronStudio/StudioStrategySimulations.php` | modificado (no está en el plan; §2.4) |
| `tests/fixtures/outreach/tools/build/sec_ladder.py` | modificado |
| `tests/fixtures/outreach/policy-ladder-cases.json` | regenerado |
| `tests/Unit/Outreach/Core/TraceTextCases.php` | modificado |
| `tests/Unit/Outreach/Core/fixtures/trace-text-cases.json` | exportado |
| `tests/Feature/Outreach/Run/H9ComplaintRecentTest.php` | **nuevo** |
| `tests/Feature/UltronStudio/Strategies/UltronStudioStrategyComplaintSimulationTest.php` | **nuevo** |

**Compartido por Q1-2 y Q1-3:** `tests/fixtures/outreach/README.md` (índice, recuentos y la nota de regeneración).

> **Nota para confirmar por separado.**
> - `OutreachPlanner.php` mezcla las dos partes. O-1 son las líneas `:237-243` (docblock), `:251` y `:255`. Q1-3 son `:448`, `:472` y `:532-538`. Se separan con `git add -p`.
> - Q1-2 y Q1-3 comparten `README.md` y `trace-text-cases.json`; este lleva el `catalogVersion` de Q1-2 y los textos de Q1-3.
> - El plan pide que Q1-1 y Q1-2 vayan juntos: `OutreachCatalogEmittersTest` obliga a abrir el catálogo en el mismo commit que el emisor.

### 2.2 Q1-1 · el emisor

**Qué hace.** `MarketingAiActionCommercialObserver::created()` actúa cuando se cumplen las tres cosas:
- `commercial.events_enabled` está encendido;
- la fila trae `lead_id`;
- es una queja: `reason === 'complaint'` o `metadata.intent === 'complaint'`, comparación exacta (`isComplaint()`).

Entonces registra `complaint_created` por `CommercialEventRecorder`:
- **Sujeto:** `CommercialSubjectResolver::fromLead()`; trae el socio si el prospecto está vinculado.
- **Payload:** `{source, conversation_id, ai_action_id}`. Sin texto, sin motivo libre y sin teléfono; la prueba lo comprueba sobre la fila cruda y la mutación P2-M8 lo mata.
- **Clave:** `conversation:<id>:complaint:<Ymd de Bogotá>`. Si la fila no trae conversación (la ruta interna de n8n la tiene opcional), `lead:<id>:complaint:<Ymd>`.
- **`source`:** `ultron` si la fila trae `source_type` (las filas de ULTRON v1) y `sales_agent` en otro caso. Es un código cerrado que sale de las columnas.
  - El plan pedía `metadata.origin ?? 'sales_agent'`, pero `origin` solo existe en la metadata de los **mensajes**, no en las filas de la IA [L: `grep` en `UltronCommitService`].
  - Usarla habría dejado entrar texto libre si alguien la rellenara.

**Se registra DESPUÉS del commit de quien crea la fila** (cambio pedido por el lead).
- `created()` hace primero las comprobaciones baratas: la bandera, `lead_id` y `isComplaint`.
- Después fija en el cierre lo que hace falta, en el momento de la fila: los ids, `source`, la hora y el día de Bogotá de la clave. El registro va en `DB::afterCommit(...)` y no relee el modelo.
- **Por qué.** `CommercialEventRecorder::record` se traga a propósito la violación de unicidad de `dedupe_key` y cualquier otro fallo del INSERT (`CommercialEventRecorder.php:59-70` y `:122-131`). En PostgreSQL, una sentencia que falla DENTRO de una transacción la deja abortada (25P02) aunque PHP se trague la excepción, y todo lo que el turno haga después falla. Un `try/catch` en el observador no se entera. SQLite no lo ve.
- **Quién crea la fila dentro de una transacción.** `MarketingManualTakeoverService` lo hace dentro de `DB::transaction` (`:124` y `:170`) [C: `grep`]. En `UltronCommitService` y `SalesAgentOrchestratorService` no hay `DB::transaction` en el propio fichero, pero quien los llama podría abrirla. Con el cambio da igual.
- **Comportamiento:**
  - dentro de una transacción, nada hasta que se confirma; al confirmar, uno;
  - si se revierte, nada: la fila no existe;
  - sin transacción, en el acto, así que fuera de una transacción todo queda igual que antes;
  - `occurred_at` es la hora de la fila, la misma con la que se calcula el día de la clave.
- **La contención va DENTRO del callback**, con `try/catch (\Throwable)` y `ChannelLog::error('commercial.complaint.record_failed', …)`.
  - Una excepción después del commit le llegaría a quien llamó a `DB::transaction`, con los datos ya confirmados.
  - Sin transacción, el callback corre dentro de `MarketingAiAction::create`, que ULTRON envuelve en un `try/catch (QueryException)` que la traduciría a «esta decisión ya se ejecutó» y un 409 (`UltronCommitService.php:2544-2569`).
- **Pruebas:**
  - (a) `test_dentro_de_una_transaccion_la_queja_se_registra_al_confirmar_y_no_antes`: con dos filas con queja en el mismo turno, 0 dentro y 1 al confirmar; la transacción sigue sana;
  - (b) `test_si_la_transaccion_se_revierte_no_queda_ninguna_queja`, con `rollBack` y con una excepción dentro de `DB::transaction`;
  - (c) `test_fuera_de_una_transaccion_se_registra_en_el_acto`;
  - y el fallo del resolvedor, también con `DB::transaction`.
  - Todas en SQLite y en PostgreSQL. Las mutaciones, en §2.9.

**Concurrencia [L].**
- ULTRON serializa los turnos de una conversación con un cerrojo de caché (`UltronCommitService.php:190-191`).
- Si dos procesos registraran la misma queja a la vez, el índice único de `dedupe_key` haría de árbitro. Con el registro después del commit, esa violación ocurre fuera de la transacción del turno y no envenena nada.

**El efecto lateral que el plan dejaba como suposición [C + L].**
- `CommercialEventRecorder::queueEvaluation()` encola `EvaluateCommercialSubject` solo con `commercial.enabled` (el NBA) encendido.
- **En producción (NBA y autonomía apagados) no se encola nada.** El hecho queda con `evaluated_at` nulo; `commercial:evaluate-pending` tampoco hace nada con el NBA apagado.
- Con el NBA encendido, se encolaría la reevaluación del **estado** de la persona, igual que con cualquier hecho:
  - el motor no lee el tipo de evento (en `app/Services/Commercial` nadie mira `->event`);
  - sin autonomía no ejecuta ninguna herramienta.
- En el H9, `complaint_created` no tiene finalidad, así que **no abre ninguna inscripción**: la ingesta solo reparte eventos a estrategias cuyo disparador sea ese evento, y ninguna puede tenerlo (`triggerable: false`).
- Prueba: `test_con_el_motor_nba_apagado_la_queja_no_encola_ninguna_evaluacion`, que también fija que con el NBA encendido se encola una evaluación.

**Fuera del alcance, no lo arreglo.** La supervisión (`SupervisionController::factLabel()`, `:601-616`) no tiene etiqueta para `complaint_created` y la pinta como el código crudo.

### 2.3 Q1-2 · el catálogo

- `complaint_created` sale de `EVENTS_WITHOUT_EMITTER` (`OutreachCatalog.php:207`): queda `available: true`, `triggerable: false` y `purposes: []`.
- `VERSION` sube a **`outreach-catalog/2026-11-11.2`** (`:52`), con la huella **`49b7d57e1097d7b930bc58b4816830b139e9042dbb504159f627f4f7bbca39c7`**, anotada en `OutreachCatalogTest::HUELLAS`.
- **Generador.** `sec_catalog.py` (`CATALOG_VERSION`, `NO_EMITTER`, la nota del evento y DF-K4) y `build.py` con `python3 -I`.
  - Antes de tocar nada comprobé que el generador reproduce **byte a byte** los 11 fixtures vigentes.
  - Después de los cambios, solo cambian los cuatro ficheros de la lista.
- **Guarda.** `OutreachCatalogEmittersTest` añade el observador a la lista cerrada (22 eventos con emisor) y exige `complaint_created` entre los emitidos. `OutreachCatalogTest` fija `[available, triggerable, purposes, conditionUsable] = [true, false, [], true]`.
- **Compatibilidad.** Las versiones de estrategia guardadas siguen compatibles (DF-K6: `available` no cuenta, y la cadena de versión no decide sola).

### 2.4 Q1-3 · el freno en N6 (`complaint_recent`)

**La escalera** (`PolicyLadder.php`), con el patrón exacto de `human_requested_recently`:
- `complaint_recent` entra en `REASON_ORDER['N6']` justo después de `human_requested_recently` (`:83`).
- Su efecto es **`exit_complaint`** (`EFFECTS`, `:104`).
- Hay una entrada nueva obligatoria, `context.complaintRecently: ?bool` (`SPEC`, `:151`).
- La regla, en `:484-488`: `!== false` niega. Con `true` hay una queja en la ventana; con `null` la fuente no tiene datos (falla cerrado, como L9-26).
- **No es temporal ni aviso de sombra:** en sombra niega igual (casos `shadow` del fixture y prueba de extremo a extremo). Ninguna estrategia lo quita: no depende de su audiencia ni de sus exclusiones.
- **Precedencia:** N1–N5 deciden antes (4 casos `precedence` y una prueba de extremo a extremo de N1).
- **Si coincide con otros motivos de N6,** manda el efecto del que va antes:
  - `success_reached` → `exit_converted`;
  - `context_changed` → `cancel_context_changed`;
  - `human_requested_recently` → `handoff`.

**Las finalidades y la ventana las aplica quien arma la entrada,** porque la escalera es pura y no lee la configuración. `LadderInputBuilder::complaintRecently($events, $now, $purpose)` (`:167-192`) devuelve:
- `false` si la finalidad no es de `OutreachConfig::complaintPurposes()` o si la ventana es 0;
- `null` sin datos;
- `true` si hay un `complaint_created` en los últimos N días de Bogotá (hoy cuenta; con `RelativeTime::withinLast`, la misma definición que `human_requested`).

**Parámetros, sin clave nueva en `config/outreach.php`** (`OutreachConfig.php:220-250`):
- `complaintDays()`:
  - lee `outreach.complaint_days` si existe y es entero, limitado a [0, 365];
  - si no, toma `humanRequestedDays()`, que es configurable (`outreach.human_requested_days` = 30 en `config/outreach.php:145`). Hoy son **30 días**;
  - **0 = freno apagado.**
- `complaintPurposes()`:
  - lee `outreach.complaint_purposes` si es una lista solo de finalidades del catálogo (vacía = no frena ninguna);
  - cualquier otra cosa, como un texto o un nombre mal escrito, se ignora y queda el valor por defecto **`['collections', 'commercial']`**. Ante una mala configuración, el lado seguro es frenar, no dejar pasar;
  - **`service` no se frena.**

**Qué hace cada sitio que lo aplica:**
- **Planificador** (`OutreachPlanner.php:472`, `:532-538`): pasa la entrada. Con el efecto `exit_complaint`, deja una decisión `exit` (nivel N6, `['complaint_recent']`), cierra la inscripción `exited / complaint_recent` y no crea ningún trabajo.
- **Despacho** (`OutreachDispatcher.php:293`, `:430-435`): si la queja llega entre planificar y despachar, el trabajo se cancela (`cancel_reason = complaint_recent`, con la decisión `cancel` de siempre) y la inscripción sale. Devuelve `canceled`, como las otras salidas del despacho.
- `exit_reason` es un código libre de hasta 48 caracteres en la base: sin migración.
- **`LiveStrategy::uses()`** (`:222-223`) carga siempre `complaint_created`, igual que `human_requested`.
- **Textos** (`DecisionTraceText.php:154`, `:159`):
  - `complaint_recent`: «La persona se quejó hace poco: se para el contacto automático.»
  - `exit_complaint`: «La inscripción sale por la queja reciente.»

**Dos ficheros del Studio que el plan no nombra,** y por qué hacían falta:
- La simulación del Studio (`StudioStrategySimulator`) arma su propia entrada de la escalera, con su propio `humanRequestedRecently` (`:154`).
- Con la entrada nueva obligatoria, sin tocarla, la escalera lanzaría `InvalidArgumentException` en cada simulación.
- El encargo, además, pide que quien se quejó no salga como contactable «ni en la simulación».
- Por eso:
  - `StudioStrategySimulator.php` gana `COMPLAINT_DAYS = 30`, `COMPLAINT_PURPOSES` y `complaintRecently()` (`:51-59`, `:165`, `:309-329`). La simulación es pura y no lee la configuración; una prueba ata estos valores a los de la ejecución.
  - `StudioStrategySimulations.php:388-389` carga `complaint_created` en la muestra real.
- El otro agente que trabaja en el árbol toca `StudioLabChecks.php` y `StudioRuleCoherence.php`, no estos dos.

**El fixture** (`sec_ladder.py`):
- `N_ORDER`, `BASE.context.complaintRecently = False`, la referencia de N6 (`is not False`, igual que PHP) y su efecto.
- DF-L8 se amplía y DF-L14 es nuevo.
- 13 casos nuevos: `n6` +7, `precedence` +4 y `shadow` +2. En total, 136.
- Los 123 casos anteriores solo ganan `complaintRecently: false` en su entrada; **ninguna salida cambia** [C: comparación caso a caso].
- `trace-text-cases.json` se exporta con la herramienta del repositorio; `dictionaryHash` pasa de `e83bce27…` a **`178ff1f0fc914274b84a75f55c253a8075cab7e9a060a6a974dc92a0647d66b1`**.

### 2.5 Riesgos y decisiones que dejo escritas

- **`outreach:replay` de decisiones anteriores al cambio: blindado** (pedido por el lead).
  - Una traza guardada antes no trae `context.complaintRecently`, y la escalera la rechaza por su forma.
  - `OutreachReplay.php:113-118` envuelve SOLO esa llamada: con `InvalidArgumentException` devuelve `['reason' => 'ladder_input_outdated'] + $salida` (`not_reproducible`, sin huella reevaluada), como `facts_unreadable`, `ladder_input_missing` y `rebuild_failed`. El docblock de la clase lo recoge.
  - **Antes** la excepción se escapaba de la orden (`InvalidArgumentException: Falta context.complaintRecently en la entrada de la escalera.`, la salida de la mutación P2-M34) y la pasada `replay` de `outreach_runs` se quedaba en `running`: la orden solo cierra su pasada con `OutreachException`.
  - Prueba: `H9ComplaintRecentTest::test_replay_de_una_decision_anterior_al_cambio_dice_ladder_input_outdated_sin_caerse`.
    - Una decisión real de plan copiada sin `complaintRecently`, como fila nueva porque la tabla es de solo inserción, da `not_reproducible / ladder_input_outdated`.
    - La orden dice `no reproducible: ladder_input_outdated`, sale con 2 y su pasada queda `completed`.
    - La decisión nueva sigue saliendo `same`.
  - **Dónde se ve el motivo:**
    - la orden `outreach:replay`, en su línea «Resultado» y en `--json`, sin cambios;
    - `outreach_runs.counts` de la pasada `replay` solo guarda `result`, no el motivo;
    - no hay ruta de la API que llame a `OutreachReplay` [C: `grep`];
    - el monitor no la usa;
    - el CRM no nombra ninguno de estos motivos [C: `grep` en `Iron-Body_Front/src`, 0 resultados].
  - En producción había 0 decisiones de sombra el 11-11 a las 22:57 UTC [P: L9-113 del contrato].
- **Divergencia previa en el generador, no la toco.**
  - La referencia de Python trata `humanRequestedRecently = None` como «no pidió», al revés que PHP (`sec_ladder.py:233`: `if cx["humanRequestedRecently"]:`).
  - Y con `context_changed` y `human_requested_recently` a la vez, Python elige `handoff` y PHP elige `cancel_context_changed` (el primero en `REASON_ORDER`).
  - Ningún caso del fixture ejercita ninguna de las dos.
  - Para `complaint_recent` las dos implementaciones coinciden en todas las combinaciones (lo comprueban los casos nuevos).
- **Dato personal nuevo (para `security`, Q1-6).**
  - «Esta persona se quejó» queda en `commercial_events` (tipo de evento + `ai_action_id` + `conversation_id`).
  - Cuando una estrategia evalúa a la persona, queda también en `outreach_decisions.facts_json` (clave y hora del evento, como ya pasa con `human_requested`).
  - `docs/ultron-studio/ops/supresion-1581.sql` no nombra `commercial_events` ni `marketing_ai_actions` [C: `grep`]: la supresión de la Ley 1581 no los cubre. Es de `devops` y `database-specialist`.
- **Falsos positivos.** El modelo clasifica la queja sin corroborar. Con esta opción solo silencian el contacto durante 30 días, que es el lado seguro.
  - No hay forma de descartar una queja que no lo era: es Q1-8(a) del plan.
  - Tampoco se recuperan las quejas anteriores al despliegue: es D4 y Q1-7.

### 2.6 Lo que tiene que hacer el CRM (no lo he hecho)

- Ejecutar `sync-h9-fixtures.mjs` para traer:
  - `catalog-snapshot.json` (`outreach-catalog/2026-11-11.2`, `complaint_created` disponible y no disparador);
  - `policy-ladder-cases.json` (136 casos, `baseInput.context.complaintRecently`, `reasonOrder.N6` y el efecto `exit_complaint`);
  - `conditions-cases.json` y `strategy-content-cases.json` (cabeceras);
  - `trace-text-cases.json` (diccionario `178ff1f0…`, con los textos de `complaint_recent` y `exit_complaint` y el caso «N6 sale: se quejó hace poco (Q1-3)»).
- El espejo de los textos de la traza (`commercial-automation-trace-text.ts`): los dos códigos nuevos y, si el tipo del efecto es una unión cerrada, `exit_complaint`.
- El texto de `exit_reason = complaint_recent` en la vista de inscripciones, y la etiqueta del recuento `repeated_subject` (parte 1) si los recuentos de las pasadas se pintan con una tabla de textos.
- En el constructor: `complaint_created` sigue sin ofrecerse como disparador. La exclusión «se quejó» deja de ser papel mojado, aunque N6 ya frena sin que la estrategia la escriba (Q1-4 del plan).

### 2.7 Configuración y operación

- **Clave para el próximo despliegue normal** (no la he añadido), en `config/outreach.php`:
  ```php
  // Q1-3 (N6 complaint_recent): días de Bogotá (0 = apagado) y finalidades a las que frena una queja.
  'complaint_days' => (int) env('OUTREACH_COMPLAINT_DAYS', 30),
  'complaint_purposes' => ['collections', 'commercial'],
  ```
  Al añadirla, la ventana deja de seguir a `human_requested_days`. Si el propietario quiere que vayan juntas, basta con no añadir `complaint_days`.
- **Sin variables de entorno nuevas, sin migraciones y sin cambios de rutas.** El despliegue cabe en `hotfix-backend.sh`. La lista permitida son los ficheros de `app/` de las dos partes, también `OutreachReplay.php`; los de `tests/` y `docs/` también viajan en el diff, así que hay que pasarlos.
- **Franja de despliegue:** fuera de las **19:00–23:59 de Bogotá** (00:00–04:59 UTC), por la parte 1 (§1.3).
- **Ficheros de operación que conviene tocar** (no los he tocado; son de `devops`):
  - `docs/ultron-studio/ops/h9-outreach-scope.sh:941` dice «los 8 observadores»: ahora son 9;
  - `eventos medir` no cuenta las quejas por día. Hoy es una fila por conversación con queja y día de Bogotá;
  - `supresion-1581.sql` (§2.5).
- **La puerta de diff del H9.** Tras la parte 2 queda en rojo con **exactamente** `app/Observers/Commercial/MarketingAiActionCommercialObserver.php`, el fichero autorizado.
  - Hoy sale en la comprobación de «ficheros nuevos sin seguimiento»; tras el commit, saldrá en el diff frente a `origin/main`; tras el push, vuelve a verde.
  - `ScanCommercialFacts.php` y `CommercialEventServiceProvider.php` no salen porque la puerta no vigila `app/Console/Commands` ni `app/Providers` (`OutreachIsolationTest.php:160-165`).

### 2.8 Pruebas y ejecuciones [C]

**Pruebas nuevas, sobre las clases reales:**

| Fichero | Pruebas | Qué fija |
|---|---|---|
| `tests/Feature/Commercial/ComplaintEventWiringTest.php` | 11 / 36 aserciones | Una fila con queja da un evento, con el socio del prospecto, la clave y el payload exactos y sin teléfono ni nombre en la fila cruda. El motivo o la intención, y nada más (cinco casos de no-queja, también `COMPLAINT` o «Conflicto o queja»). Una al día de Bogotá: 10:00, 18:59, 19:00 y 23:59:59 dan uno, y 00:00 da otro; otra conversación, otro. Sin conversación, la clave del prospecto. La fila de ULTRON lleva `source: ultron`. Con el interruptor apagado, nada. Con el NBA apagado no se encola; encendido, una evaluación. Un fallo del resolvedor no rompe la creación de la fila, ni dentro de `DB::transaction`. **Después del commit:** (a) 0 dentro de la transacción y 1 al confirmar; (b) con `rollBack` o una excepción en la transacción, 0; (c) fuera de una transacción, en el acto |
| `tests/Feature/Outreach/Run/H9ComplaintRecentTest.php` | 8 / 69 | En sombra, la queja del emisor real hace que la inscripción salga al planificar: decisión `exit` N6, efecto `exit_complaint`, 0 trabajos, `replay` igual en las dos decisiones. Si la queja llega entre planificar y despachar, el trabajo se cancela con `complaint_recent`, la inscripción sale y `replay` da lo mismo. La ventana: el día 30 de Bogotá entra y el 31 no; la queja del prospecto frena a su socio; una posterior a «ahora» no cuenta. Comercial y cobranza sí, servicio no. Sin datos, `null`. Con la fuente apagada, `[human_requested_recently, complaint_recent]` y `handoff`. La configuración (días y finalidades, también valores raros) y 0 días = no frena. N1 decide antes (N6 queda `not_evaluated`). `replay` de una decisión con la entrada antigua: `ladder_input_outdated`, sin excepción, y la orden cierra su pasada |
| `tests/Feature/UltronStudio/Strategies/UltronStudioStrategyComplaintSimulationTest.php` | 3 / 15 | Simulación sintética: quien se quejó ayer, deny N6; hace 31 días, plan; sin datos, deny con los dos motivos. Muestra real leída de la base: la persona con queja, deny N6, y la otra, plan. Los valores de la simulación son los de la ejecución |
| `policy-ladder-cases.json` (vía `PolicyAndChannelParityTest`) | +13 casos | N6 (dentro, sin datos, cobranza, con `human_requested`, con éxito, con `context_changed`, con cartera), precedencia (N1, N1 en domingo, N2, N7) y sombra (niega; con «solo canario», los avisos de N3 y N5 y deny en N6) |
| `OutreachCatalogTest`, `OutreachCatalogEmittersTest`, `TraceTextCases` | modificadas | Huella de la `.2`; `complaint_created` con `[available, triggerable, purposes, conditionUsable] = [true, false, [], true]`; 22 eventos con emisor; el caso de `explain` de la queja |

**Sonda con el `commit` real de ULTRON** (en la copia; no está en el repositorio). La repetí con el observador definitivo (después del commit), en SQLite y en PostgreSQL: el mismo resultado. `UltronCommitTest::test_a_complaint_marks_the_conversation_without_the_model_asking` con `commercial.events_enabled = true`:
- fila de la IA `[1, "complaint", "complaint", "inbound_message"]`;
- un `complaint_created` con la clave `conversation:1:complaint:20261111` y el payload `{"source":"ultron","conversation_id":1,"ai_action_id":1}`.

**Baterías** (`nice -n 10 php -d memory_limit=2G vendor/bin/phpunit --no-logging …`, una cada vez):

| Batería | SQLite | PostgreSQL 14 (`ultron_studio_quejas_unit`) |
|---|---|---|
| `tests/Unit/Outreach` | **OK, 993 / 53 199** | — (puras) |
| `tests/Feature/Outreach` | **OK, 651 / 40 385**, 164 omitidas («solo PostgreSQL») | **OK, 651 / 41 497**, 29 omitidas |
| `tests/Feature/Commercial` | **OK, 166 / 446** | 166 pruebas, **31 errores ajenos**: `CommercialE2ETest` 5, `OperationalToolsTest` 20, `ToolExecutorTest` 6 |
| `tests/Feature/UltronStudio/Strategies` | **OK, 46 / 748** | **OK, 45 / 736**, más el fichero de la queja: **OK, 3 / 15** |
| Mis ficheros de `Commercial` en PostgreSQL | — | `ComplaintEventWiringTest` **OK, 11 / 36**; `ScanCommercialFactsBogotaDayTest` **OK, 6 / 43** |
| `H9ComplaintRecentTest` en PostgreSQL | — | **OK, 8 / 69** |
| `UltronCommitTest`, `StaffReviewAuthorityTest` y `CommercialEventWiringTest` | **OK, 84 / 353** | — |
| Guardas estáticas (15 ficheros) | **138, 1 fallo**: la puerta de diff con solo `app/Observers/Commercial/MarketingAiActionCommercialObserver.php` | — |
| `vendor/bin/pint --test` | `passed` en 21 de mis 22 ficheros PHP | — |

- **Los 31 errores de `Commercial` en PostgreSQL** son `invalid input syntax for type uuid: "test"`, `"e2e"` y `"test-correlation"`: pruebas antiguas que meten un `correlation_id` que no es un uuid. Con el código de HEAD en una copia dan los mismos 31 errores sobre 149 pruebas [C].
- **El fichero 22, `CommercialEventServiceProvider.php`, no pasa `pint --test`,** por el orden de dos `use` y el nombre completo de `ToolRegistry`. Con la versión de HEAD falla igual [C, en la copia]. No lo he reformateado para que el diff de la corrección puntual sean solo mis 3 líneas.

### 2.9 Mutaciones [C]

Mismo procedimiento que en §1.8, en otra copia APFS (`mutate.py`: `php -l`, pruebas y restauración con el sha256 comprobado). Al terminar, `diff -rq` del `app/` de la copia frente al del árbol: idéntico.

| Mutante | La mata |
|---|---|
| P2-M1 · predicado: motivo **y** intención | `ComplaintEventWiringTest` (motivo o intención) |
| P2-M2 · predicado: solo el motivo | ídem |
| P2-M3 · predicado: cualquier fila con prospecto | ídem |
| P2-M4 · clave con el día UTC | `…una_queja_al_dia_de_bogota…` |
| P2-M5 · clave por prospecto y no por conversación | 4 pruebas del emisor |
| P2-M6 · sin el interruptor `events_enabled` | `…registro_de_hechos_apagado…` |
| P2-M7 · sin la contención (el fallo se propaga) | `…un_fallo_al_registrar…` |
| P2-M8 · el payload lleva el motivo libre | la prueba de la fila cruda |
| P2-M9 · `complaint_created` vuelve a no tener emisor | `OutreachCatalogEmittersTest` y 3 de `OutreachCatalogTest` |
| P2-M10 · N6: sin datos deja pasar (`=== true`) | el caso del fixture, la fuente apagada y la simulación |
| P2-M11 · N6 sin efecto de salida | 12 (fixture, extremo a extremo, simulación y textos) |
| P2-M12 · N6: la queja después de la cartera | el caso del fixture con los dos motivos y el conjunto `reasonOrder` |
| P2-M13 · la queja es temporal (aplaza) | 13 |
| P2-M14 · la queja es aviso de sombra (la constante) | `…los_conjuntos_del_fixture_son_los_del_codigo`. N6 no lee esa constante; lo que hace en sombra lo fijan los casos `shadow` y la prueba de extremo a extremo |
| P2-M15, M16 y M17 · ventana −1 día, +1 día y en horas | `…la_ventana_cuenta_dias_de_bogota…` |
| P2-M18 · 0 días no apaga | `…con_cero_dias…` |
| P2-M19 y M20 · finalidades sin cobranza / con servicio | `…aplica_a_lo_comercial…` y `…con_cero_dias…` |
| P2-M21 · las finalidades no se comprueban | `…aplica_a_lo_comercial…` |
| P2-M22 y M23 · la ventana ignora `human_requested_days` / la clave propia | `…con_cero_dias…` |
| P2-M24 y M26 · el planificador no aplica el efecto / no pasa la queja | extremo a extremo (planificar) |
| P2-M25 y M27 · el despacho no aplica el efecto / pasa siempre `false` | extremo a extremo (despachar) |
| P2-M28 · `LiveStrategy` no carga `complaint_created` | extremo a extremo |
| P2-M29 · la simulación no carga `complaint_created` (muestra real) | `…en_la_muestra_real…` |
| P2-M30 y M31 · la simulación no mira la queja / sin datos deja pasar | `…la_simulacion_niega…` |
| P2-M32 · el constructor: sin datos deja pasar | `…aplica_a_lo_comercial…` y la fuente apagada |
| **P2-M0 · volver a la llamada directa** (`DB::afterCommit(` → `call_user_func(`) | **(a)**, en SQLite y en PostgreSQL. Salida: «1) …ComplaintEventWiringTest::test_dentro_de_una_transaccion_la_queja_se_registra_al_confirmar_y_no_antes — dentro de la transacción de quien crea la fila, todavía nada. Failed asserting that actual size 1 matches expected size 0. … FAILURES! Tests: 11, Assertions: 33, Failures: 1.» (b) no lo ve, como era de esperar: con la llamada directa, el evento se revierte junto con la fila |
| P2-M33 · la contención fuera del callback (la excepción llega a quien confirma) | `…un_fallo_al_registrar…` |
| P2-M1' a P2-M8' · las mutaciones del emisor repetidas sobre el código nuevo | las mismas pruebas que P2-M1 a P2-M8 |
| P2-M34 · `replay` sin la captura (`catch (\RuntimeException)`) | `…replay_de_una_decision_anterior…`. Salida: «InvalidArgumentException: Falta context.complaintRecently en la entrada de la escalera.» |
| P2-M35 · `replay` con otro motivo (`ladder_input_missing`) | ídem |

**36 de 36 muertas** (P2-M0 a P2-M35, con P2-M1' a P2-M8' sobre el observador definitivo). Y P1-M1 a P1-M10 repetidas sobre los ficheros definitivos: **10 de 10**.
- P2-M14 y P2-M8' no se aplicaron a la primera porque el patrón no casaba o salía dos veces; corregido el patrón, murieron.
- **Un mutante que sobrevivió por un error mío:** la primera versión de P2-M34 cambiaba el `catch` a `\LogicException`, que es la clase madre de `InvalidArgumentException`. Seguía capturando, así que era un mutante equivalente, no un hueco de las pruebas. Con `\RuntimeException`, muere.

### 2.10 Lo que NO he verificado

- **Producción, nada.**
  - El estado de `COMMERCIAL_EVENTS_ENABLED`, `COMMERCIAL_NBA_ENABLED` y `COMMERCIAL_AUTONOMY_ENABLED` lo tomo del plan y del contrato [P].
  - Tampoco he comprobado que siga habiendo 0 decisiones del H9 ni 0 estrategias publicadas.
  - Ni cuántas quejas al día marcaría la IA: el volumen de `commercial_events` nuevo.
- **La batería completa del backend:** la lanza el lead. Yo he lanzado las de §2.8.
- **El CRM:** ni la sincronización de fixtures ni sus textos.
- **El camino del agente de ventas antiguo** (`SalesAgentOrchestratorService`, dos filas por turno con queja): lo cubre el razonamiento (la misma clave del día y la conversación lo deduplica) y la prueba de dos filas, no una ejecución de ese servicio.
- **Despliegues dentro de la franja de 19:00 a 23:59 de Bogotá:** razonado sobre el código (§1.3), no ensayado con dos versiones del comando a la vez.
- **Lo que hace el motor NBA encendido con un `complaint_created`:** sé que encola la evaluación del estado y que el motor no mira el tipo de evento [L]. No he ejecutado `EvaluateCommercialSubject` con el NBA encendido.

### 2.11 Adyacente (no lo he arreglado)

- `SalesEscalationService.php:19`: `'sic'` con `str_contains` casa con «física», «básico» y «música» y abre revisiones `sensitive_topic` falsas (lo cita el plan). Es para `debugger`.
- `SupervisionController::factLabel()` no tiene texto para `complaint_created`.
- En `sec_ladder.py`, la referencia de N6 diverge de PHP con `humanRequestedRecently = None` y con `context_changed` + `human_requested_recently` (§2.5).
- `CommercialEventServiceProvider.php` no pasa `pint` desde antes de este cambio.
- **Los demás observadores del motor tienen el mismo riesgo latente en PostgreSQL.** Lo pidió el lead; no los toco.
  - Los ocho de `CommercialEventServiceProvider` (pagos, membresía, socio, prospecto, cita, conversación, asistencia y factura) llaman a `CommercialEventRecorder::record` directamente, dentro de la transacción de quien escribe la fila. Por ejemplo, `PaymentTransactionCommercialObserver.php:52` y `:78`, dentro de la transacción del pago.
  - El docblock del registrador (`CommercialEventRecorder.php:27-30`, «1. Nunca lanza. Se le llama desde observers que corren DENTRO de la transacción de un pago») cree que no lanzar basta. En PostgreSQL no: si el INSERT del evento falla (la carrera de `dedupe_key` entre el webhook de Wompi y la reconciliación, u otro error de la base), el registrador se traga la excepción pero la transacción del pago queda abortada (25P02), y lo que el pago haga después falla.
  - Arreglo probable: el mismo `DB::afterCommit` en el registrador, o un punto de guardado alrededor del INSERT. Es del motor comercial y de `database-specialist`.
- El corte de inactividad del motor («≤ medianoche − 14 días») y el `member.inactive` del H9 («≤ 14 días») difieren en un día (§1.4).

---

## Nota del lead (11-11-2026, tras las revisiones de `security` y `reviewer`)

Cambios hechos por el lead sobre lo que describe este informe, después de cerrar al agente. Donde este informe diga otra cosa, manda esta nota.

1. **La configuración del freno falla cerrada (M-1 de `informe-security-quejas.md`).** `OutreachConfig::complaintDays()` tiene su propio valor por defecto (`COMPLAINT_DAYS_DEFAULT = 30`) y **ya no sigue a `human_requested_days`** (§2.4 y §2.7 decían que sí): acortar una ventana no acorta la otra. Solo `0` apaga el freno; un negativo, un texto o nada dejan los 30. `complaintPurposes()` devuelve por defecto todas las finalidades del catálogo menos el servicio (una finalidad nueva nace frenada) y solo acepta una lista configurada si son textos del catálogo e incluye la comercial; una lista vacía, sin la comercial o con elementos que no son textos deja el valor por defecto, sin lanzar. La prueba `test_con_cero_dias_la_queja_no_frena_y_la_configuracion_se_lee_sin_clave_nueva` fija los casos nuevos.
2. **`outreach:replay` distingue una entrada antigua de una mal formada (menor 3 del reviewer, M-2 de security).** `ladder_input_outdated` solo si a la entrada guardada le falta una clave que la escalera de ahora exige (`OutreachReplay::lacksLadderKey` contra `PolicyLadder::SPEC`); cualquier otro rechazo es `ladder_input_invalid`. Prueba nueva: `test_replay_de_una_entrada_con_un_tipo_mal_dice_ladder_input_invalid_y_no_la_da_por_antigua`.
3. **El servidor rechaza la queja como disparador, con prueba propia (hueco que señaló security, §4).** `StrategyContentAndChainParityTest::test_una_queja_nunca_es_disparador_aunque_el_evento_tenga_emisor`, en las finalidades con casos válidos del fixture.
4. **Mutaciones del lead** (en una copia fuera del árbol, con el sha256 restaurado): 9 de 9 muertas — las dos etiquetas del replay, la ventana con un negativo y siguiendo a `human_requested_days`, las finalidades sin la comercial, sin comprobar textos y con la lista literal, la queja como disparador, y la frase del laboratorio de la Enmienda 3 de G.
5. **Para cuando se añadan las claves a `config/outreach.php`:** la simulación del Studio usa constantes (`StudioStrategySimulator::COMPLAINT_DAYS` y `COMPLAINT_PURPOSES`); hay que pasarle los valores efectivos en ese mismo cambio (M-7 de security), o la simulación y la ejecución dirán cosas distintas.
6. **Lo que queda escrito y no se hace esta noche:** la bandeja asistida no vuelve a mirar N6 después de despachar (M-5: antes de abrir lo asistido en vivo), la vigilancia de las quejas perdidas o en masa (M-3 y M-4), el corte a 500 eventos por persona (M-8) y la supresión 1581 de `commercial_events` y `marketing_ai_actions` (I-2, encargada a `database-specialist`).
