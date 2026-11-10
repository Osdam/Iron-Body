# Fixtures del Hito 9: autonomía comercial (TEST9-a, paso 1)

Escritos **desde el contrato y antes del código**: `docs/ultron-studio/ULTRON_STUDIO_H9_CONTRACT.md`, congelado por el lead el 09-11-2026 a las 20:30 UTC (con §13.2 renumerado T1–T23). Mandan D9-3 a D9-11, §5, §6, §13 y la «Congelación». Son el oráculo de las pruebas puras de B9-core (`app/Services/Outreach/Core/**`), y los leen también B9-facts, B9-run, S9 y FE9 (el espejo TypeScript del CRM).

**Regla de uso (la del H8).** Si una implementación discrepa de un caso con `basis` `contrato`, `ley` o `codigo-existente`, se corrige la implementación. Si el caso es `decision-fixture`, lo congela el lead (§Decisiones); si decide otra cosa, se cambia aquí con el generador. **Nunca se dobla un caso para que dé verde.**

**No hay código del motor aquí.** Lo comprueba `tests/Unit/Outreach/FixturesSanityTest.php` (forma, unicidad, decisiones, huellas, firmas y cruces entre ficheros), que solo usa `StudioContentHasher` del H5 y `hash_hmac`.

## Cómo se generan y se comprueban

```
python3 -I tests/fixtures/outreach/tools/build/build.py                       # reescribe los 11 JSON (reproducible byte a byte)
php -d memory_limit=2G vendor/bin/phpunit --no-logging tests/Unit/Outreach/FixturesSanityTest.php
```

- **El generador** está en `tools/build/`: `build.py` tiene las primitivas (JSON canónico de `StudioContentHasher` byte a byte como PHP, sha256, HMAC, el día de Bogotá) y un `sec_*.py` por fichero con los casos **a mano**.
- **Cada veredicto es un literal** escrito desde el contrato: errores, valores `true/false/unknown`, el resultado de cada hoja, motivos, `retryAt`, canal elegido… El generador lleva una **referencia propia** por pieza (validador y evaluador de condiciones, calendario, presupuesto, `ContactPolicy`, escalera, selector, árbitro, tabla de resultados, máquina de seguimientos, lector de estrategias y verificador de la cadena) y **se para** si un literal no cuadra con ella. Las trazas completas, las huellas y las firmas las calcula la referencia.
- **Cruces con el árbol:** los festivos se comparan fecha a fecha con `tests/fixtures/voice/holidays-co-2026-2028.json` del H8; los conjuntos cerrados se copian de `CommercialVocabulary`, `CommercialPhaseMachine`, `SalesAgentDecisionSchema` y `MarketingLead`; la paridad modela `ContactPolicy.php:92-172` (con Carbon 3.11.4).
- **`FixturesSanityTest`** rehace en PHP cada huella (`StudioContentHasher::canonicalJson`), cada `traceHash`, la huella del catálogo, cada firma y subclave de la cadena (la misma expresión que `UltronPublishedConfig::subkey()`), la hora de Bogotá de cada instante con `DateTimeZone`, el `input` de la escalera como `baseInput` + `patch`, y las invariantes de cada fichero. `OUTREACH_FIXTURES_DIR` la pasa sobre una copia.

## Forma común de los 11 ficheros

**Cabecera:** `fixture`, `version`, `contract`, `contractSections`, `generatedBy`, `checkedBy`, `readme`, `basisLegend`, `sources` (cada una con `what`, `url`, `consulted` y `how`), `syntheticData`, `decisions` (solo las que usan sus casos), `counts` y lo propio de cada fichero (`interface`, `catalog`, `baseInput`, `reasonOrder`, `table`…). Después, una lista de casos por sección.

**Cada caso:** `name` único con la forma `<sección> · <kind> · <descripción>`, `kind`, `contractRef`, `basis` (`contrato`, `ley`, `codigo-existente`, `supuesto-lead` o `decision-fixture`), `decision` (DF-…) cuando depende de una, sus entradas y `expected`.

**Convenciones:** instantes en UTC con `Z` y al segundo; «día» = día de Bogotá (UTC−05:00 todo el año); personas por `subject_key` sintético; ningún teléfono, nombre, documento ni importe; la `APP_KEY` es la de los fixtures del H6 y H8 (bytes 00..1f); ni invisibles literales (van como `\uXXXX`).

## Índice

| Fichero | Para | Secciones (casos) | Contrato | Casos |
|---|---|---|---|---|
| `catalog-snapshot.json` | B9-core `OutreachCatalog` (exportación y huella), API #1, FE9 | `compatibility` 6, `cycleKeys` 15; y `catalog` (27 + 8 + 3 + 3 eventos, 42 hechos), `catalogHash`, `annotations`, `policyInputs` | D9-3, D9-4, D9-5, D9-10 | 21 |
| `conditions-cases.json` | B9-core `ConditionCanonicalizer` y `ConditionEvaluator`; FE9 `commercial-automation-conditions.ts` (solo la forma) | `canonicalize` 49, `equivalence` 10, `reject` 79, `evaluate` 80, `bogotaDays` 8 | D9-5, T10, T11, T12 | 226 |
| `calendar-cases.json` | B9-core (N1, N5 del horario propio y el primer hueco), B9-run (`not_before`) | `holidays` 3, `window` 27, `nextSlot` 18 | D9-7 N1, L9-15, #17, §13.3 | 48 |
| `contact-budget-cases.json` | B9-core `ContactBudget` (y la paridad T9 con `ContactPolicy`) | `effectiveLimits` 9, `check` 26, `parity` 50 (10 a mano + 40 generados con semilla) | D9-7, P9-8, T9 | 85 |
| `policy-ladder-cases.json` | B9-core `PolicyLadder` | `n1` 24, `n2` 17, `n3` 7, `n4` 13, `n5` 16, `n6` 17, `n7` 6, `precedence` 9, `shadow` 7 | D9-7, D9-8, T16–T22, L9-15, L9-20, L9-21 | 116 |
| `channel-selection-cases.json` | B9-core `ChannelSelector` | `choose` 18 | D9-9.2, #8, #9, T13 | 18 |
| `priority-cases.json` | B9-core `PriorityArbiter` | `arbitrate` 14 | D9-8 etapa 3, P9-7, #5, #12 | 14 |
| `outcome-transitions.json` | B9-core `OutcomeTable`; B9-run (ingesta); S9 (validar `on`) | `matrix` 108 (18 × 6), `resolve` 41 | D9-10, #10, #21, #22, T14 | 149 |
| `followup-cases.json` | B9-core `FollowUpMachine` | `apply` 22 | D9-8 etapa 9, D9-10, T23 | 22 |
| `strategy-content-cases.json` | B9-core `StrategyContent`; S9 `StrategyValidator` | `valid` 13, `invalid` 40, `versions` 5 | D9-6, D9-11.4, L9-20 | 58 |
| `strategy-chain-vectors.json` | B9-core `StrategyChainSignature`; B9-run `OutreachPublishedStrategies`; S9 | `chain` 7, `subkey` 4, `verify` 35 | D9-11.3, T1 | 46 |

**Total: 803 casos.** Por base: `contrato` 376, `decision-fixture` 379, `supuesto-lead` 22, `ley` 21, `codigo-existente` 5. Hay 89 decisiones DF-… escritas en las cabeceras; las que el lead ratificó citan su L9-n (L9-8 a L9-25 del contrato), y los casos que dependen de los tres ajustes del lead (L9-15, L9-20, L9-21) van como `supuesto-lead`.

**Nombres (L9-8).** El encargo pedía `outcome-cases.json` y `chain-vectors.json`; el contrato (D9-10 y §11) los llama `outcome-transitions.json` y `strategy-chain-vectors.json`, y así se entregan (B9-core lee el contrato). `followup-cases.json` es del encargo (el contrato lo incluía dentro de `outcome-transitions.json`) y `calendar-cases.json` es del contrato (§11).

## Cómo se consume cada uno

- **Catálogo.** `OutreachCatalog::export()` tiene que devolver **exactamente** `catalog` y su `sha256(canonicalJson)` es `catalogHash` (reglas de forma en `exportRules`: listas ordenadas por bytes, claves uniformes, dominios dinámicos como `{"dynamic": …}`). `compatibility`: `used {catalogVersion, facts, events[{key, keyVersion}]}` → `compatible` o `catalog_incompatible` con lo que falta. `cycleKeys`: `(event, occurrence, now)` → la clave de ciclo (o null: no se inscribe). `annotations` y `policyInputs` no entran en la huella.
- **Condiciones.** `canonicalize`: `input` (o `inputText`, que se decodifica con `json_decode(…, true)`) + `dynamicDomains` → `canonical`, `canonicalJson`, `hash`, o el 422 con `errors` (conjunto de `{key, code}`; raíz `conditions`). `equivalence`: misma huella o no. `evaluate`: `canonical` + `snapshot` + `now` → `value` (`'true'|'false'|'unknown'`), `leafResults`, `trace` (pre-orden, rutas del árbol canónico) y `traceHash`. `bogotaDays`: `(now, at)` → días de Bogotá.
- **Calendario.** `window`: `(now, ownHours)` → `inside`, `n1` (la ley: `calendar_outside_legal_hours`, `calendar_sunday`, `calendar_holiday`) y `n5` (`calendar_outside_own_hours`, solo con horario propio y si la ley se cumple, L9-15). `nextSlot`: `(from, ownHours, schedule)` → el primer instante permitido (el horario propio sí cuenta).
- **Presupuesto.** `check`: `(history, now, purpose, medium, limits)` (solo ley y frecuencia; el horario propio es N5) → `allowed`, `reasons` (en `reasonOrder`), `releaseAt`, `retryAt`, `counts`. `parity`: lo que `contactPolicy` niega, `contactBudget` también.
- **Escalera.** Cada caso trae el `input` entero (= `baseInput` + `patch`) y la salida `{verdict, allowed, level, reasons, retryAt, waitsForRelease, effect, levels, warnings}`. La prueba de B9-core traduce el DTO neutro a sus clases sin cambiar el caso. Entradas de los tres ajustes del lead: `ownHours` (N5), `receivable {known, overdueDaysMax}` y `collectionsBlock {serverDays, strategyDays}` (N6), `studio.catalogCompatible` (N3).
- **Selector.** `(channels, perChannel, preferredMedium)` → `{verdict: selected|defer|deny, channel, retryAt, order, annotations, perChannel}`; `ladderPatchByChannel` permite rehacer cada veredicto con la escalera.
- **Prioridad.** `(candidates, todayPurpose)` → `{winner, ranking, deferred[{uuid, reason}]}`.
- **Resultados.** `matrix`: `(outcome, transition)` → `allowed`, `isDefault`. `resolve`: `(outcome, source, confidence, on, attempt)` → `{verdict, outcome, transition, enrollmentStatus, effects, incident}`.
- **Seguimientos.** `(enrollment, steps, outcome, source, now, on, callbackAt, waitFor, ownHours, schedule)` → `{resolution, status, stepIndex, attempt, nextJob, reason}`.
- **Contenido de estrategia.** `valid`: `input` → `canonical`, `canonicalJson`, `contentHash`. `invalid`: 422 con `errors` (raíz `content`; las condiciones con su clave: `content.audience…`). `versions`: misma huella o no.
- **Cadena.** `chain`: cada `entry` → `message` (JSON canónico), `entryHash`, `signature`, enlazadas por `prevHash` (cadena global). `subkey`: `(appKey, label)` → `subkeyHex`. `verify`: `(message, signature, previous)` → `result` (`ok`, `malformed`, `not_canonical`, `signature_malformed`, `signature_mismatch`, `seq_gap` o `chain_broken`) y `n5Reason` (`chain_unreadable`).

## Decisiones del fixture (las congela el lead)

El texto exacto de cada una está en la cabecera `decisions` del fichero que la usa. Las que más tocan el código:

- **Catálogo (DF-K, DF-T):** `membership.expiring_window` es la ventana `[0, daysBefore]` con `daysBefore ∈ [0, 60]` (DF-K1); `daysAfterMin ≥ 1` (DF-K2); disparadores y finalidades por evento (DF-K4); claves de ciclo, con la semana ISO **del día de Bogotá** (DF-K5); tipos de los hechos y tres hechos nuevos `date_relative` (`membership.end_date`, `valuation.next_date`, `lead.last_inbound_at`) para que la gramática tenga fechas relativas (DF-T1, DF-T2); `decimal` = entero en centésimas porque `StudioContentHasher` rechaza decimales (DF-T4); dominios dinámicos `plans` y `class_types` (DF-T5); la cartera en tramos en la traza (DF-T6).
- **Gramática (DF-G, DF-C):** el 422 como conjunto `{key, code}` de 25 códigos (DF-G1); `{}` = `[]` como en PHP (DF-G2); qué comprobación impide otra (DF-G3); límites sobre el árbol recibido y tamaño sobre el canónico (DF-G4); canonicalización: claves y valores de `in` ordenados, hijos **no**, unidades tal cual, `not(not X)` tal cual (DF-G5); ventanas ≥ 1 y ≤ 730 días (DF-G6); `between` con las cuatro claves y sin intervalo vacío (DF-G7); tres valores sin cortocircuito, `neq` sobre desconocido = `unknown` (DF-C1, DF-C2); días de Bogotá en las ventanas (DF-C3); fuente sin datos = `unknown`, también con `absent` (DF-C4).
- **Calendario y presupuesto (DF-W, DF-B):** ventana de N1 = Ley 2300 ∩ silencio de `ContactPolicy` = L–V [08:00, 19:00) y S [08:00, 15:00) (DF-W1); el horario propio es N5 con su código (DF-W5, L9-15); semana = 7 días móviles **cerrados** `[now − 7 d, now]` como `ContactPolicy` (DF-B3); dos códigos nuevos, `weekly_contact_limit` y `min_hours_between_contacts` (DF-B4); la regla de un canal por medio y para cualquier finalidad (DF-B5); `retryAt` = primer hueco tras lo último que se suelta (DF-B6).
- **Escalera (DF-L):** se acumulan los motivos del nivel que decide (DF-L1); `defer` solo si todos son temporales (DF-L2); N2 retiene (`waitsForRelease`) (DF-L4); avisos de sombra (DF-L5); `callbackAt` y `enrollment_window_over` en N7 (DF-L9); el menor desconocido no se infiere (DF-L10); `calendar_outside_own_hours` en N5 (DF-L11, L9-15); `collections_overdue_blocks_commercial` en N6 con umbral `outreach.collections.commercial_block_days` = 15 que la estrategia solo puede bajar (DF-L12, L9-20); `catalog_incompatible` en N3 (DF-L13, L9-21).
- **Resultados y seguimientos (DF-O, DF-F):** fuentes traducidas a los 8 códigos (DF-O1); umbrales 50/90 del clasificador y `needs_review` (DF-O3); reintentos 1 y 2 (DF-O6); estados `opted_out` y `expired` (DF-O7); la máquina nunca acorta (DF-F2).
- **Contenido y cadena (DF-E, DF-V):** `format: "strategy-content/1"` (DF-E1); límites de `limits`, con `commercialBlockOverdueDays` opcional y solo en commercial (DF-E2, L9-18, L9-20); `on` y `waitFor` (DF-E6); horario fuera de la ley = bloqueante (DF-E7); la cadena global, `approvedByPerson` null en pausa/reversión/retiro y `status ∈ {active, paused, off}` (DF-V1, DF-V3).

## Decisiones del lead (09-11-2026, L9-8 a L9-25 del contrato)

No queda ninguna pregunta abierta de TEST9-a. El lead aceptó tal cual las propuestas de las Q-1 a Q-18 del informe, salvo tres ajustes que cambiaron el fixture y quedan aquí como decisiones:

| L9 | Era | Qué fija | DF |
|---|---|---|---|
| L9-8 | Q-1 | Nombres `outcome-transitions.json` y `strategy-chain-vectors.json` | — |
| L9-9 | Q-2 | `weekly_contact_limit` y `min_hours_between_contacts` en N1 | DF-B4 |
| L9-10 | Q-3 | `cross_channel_week_rule` (el literal de #11 se corrige en el contrato) | DF-B5 |
| L9-11 | Q-4 | `daysBefore` de 0 a 60 | DF-K1 |
| L9-12 | Q-5 | Error del proveedor = `failed`; `needs_review` es veredicto, no resultado | DF-O1, DF-O3 |
| L9-13 | Q-6 | Dominios dinámicos con instantánea | DF-T5 |
| L9-14 | Q-7 | Tipos de los hechos y los tres `date_relative` (`catalogHash` `4580c447…` en `2026-11-10.1`; `ce5fcdab…` en `2026-11-10.2` tras L9-29/L9-33, regenerado por el lead el 09-11 a las 23:10 UTC) | DF-T1, DF-T2 |
| **L9-15** | Q-8 | **Ajuste:** el horario propio tiene su código, `calendar_outside_own_hours`, en **N5** | **DF-W5, DF-L11** |
| L9-16 | Q-9 | `channel_not_live` es aviso en sombra | DF-L5 |
| L9-17 | Q-10 | Socio sin fecha: `unknown`, ni se infiere ni se niega | DF-L10 |
| L9-18 | Q-11, Q-12 | Umbrales 50/90 (AI9) y 500 inscripciones nuevas al día | DF-O3, DF-E2 |
| L9-19 | Q-13 | «Otro orden» = claves y valores de `in`; los hijos no | DF-G5 |
| **L9-20** | Q-14 | **Ajuste:** `collections_overdue_blocks_commercial` en **N6**; umbral `outreach.collections.commercial_block_days` = 15, la estrategia solo lo baja (`limits.commercialBlockOverdueDays`) | **DF-L12, DF-E2** |
| **L9-21** | Q-15 | **Ajuste:** `catalog_incompatible` en **N3** | **DF-L13, DF-K6** |
| L9-22 | Q-16 | «Contacto directo» = cualquier contacto contado | DF-B5 |
| L9-23 | Q-17 | Se firma el canónico ordenado; `approvedByPerson` null fuera de publicar y promover; `status ∈ {active, paused, off}` | DF-V3 |
| L9-24 | Q-18 | `enrollment_window_over` sale de N7 | DF-L9 |
| L9-25 | O-3 a O-5 | Textos de traza (B9-core exporta `trace-text-cases.json`), `subject_ref` (B9-facts aporta sus vectores) y consecuencias de la escalera (B9-run) | — |

## Lo que no cubre, y por qué

- **Lo que no es puro:** esquema, CHECK, disparadores y `REVOKE` (DB9); carreras en PostgreSQL, reserva atómica, un trabajo vivo por persona, idempotencia por ocurrencia y duplicados de resultados (B9-run + TEST9-b/c); RBAC, BOLA y datos personales en respuestas (SEC9); adaptadores de hechos (B9-facts).
- **`DecisionTraceText`** (los textos en español de la traza) y el seudónimo `subject_ref` (`outreach-subject/1`): por L9-25 los fija cada dueño. B9-core exporta `trace-text-cases.json` desde su implementación y B9-facts aporta los vectores de `subject_ref` (64 hex completos, L9-4). No están en este directorio de TEST9-a.
- **Las tarifas y el estado de Meta, las llamadas (V9) y el clasificador (AI9):** fuera del 15-11; la tabla de resultados y la escalera los nombran, pero sin integración.
- **La ley:** la Ley 2578 sigue «por confirmar» (demandada) y la lectura de la Ley 2300 es del `architect`, no de asesoría legal.

**Regeneración del lead (09-11-2026 23:10 UTC, L9-29/L9-33):** la clave de ciclo de las clases pasa a `cls:{class_id}:{session_date}` con `keyVersion` 2 y el catálogo a `outreach-catalog/2026-11-10.2` (huella `ce5fcdab1e1265b70a1e7133b3eeccbd62bb2ab690acfa11a700ad6779a13039`, la que fija `OutreachCatalog::VERSION`); cambian `catalog-snapshot.json`, `conditions-cases.json` y `strategy-content-cases.json`; los 803 casos se mantienen. Generador: `sec_catalog.py` (`EV(..., key_version=)`, `CK` de clases con `class_id` y `session_date`).
