# Hito 9 · paquete DB9 · informe del `database-specialist`

**Fecha:** 09-11-2026 (Bogotá). **Árbol:** `_worktrees/ultron-h8/Iron-Body/backend`, rama `feat/ultron-studio-h8-voice`, HEAD `ab54a256`. El árbol ya traía unos 60 ficheros del H8 modificados y los ficheros nuevos del H9 de otros paquetes, todo sin confirmar; no son míos y no los he tocado. **Sin commit:** el propietario lo prohíbe hasta nueva orden, y el H9 irá a su propia rama `feat/ultron-studio-h9` (L9-34). Dejo mis 10 ficheros y este informe en el árbol de trabajo. **Producción:** no la he tocado, ni para leer.

**Contrato:** `ULTRON_STUDIO_H9_CONTRACT.md`. Mandan §6, D9-8, D9-10, D9-11, D9-14, §9, la «Congelación» y las decisiones del lead L9-1…L9-7 y L9-29…L9-31, más lo que me afecta de L9-3, L9-30 y L9-33. La transversal **T7** es mía. Precedente que sigo: DB8 (sus migraciones `2026_11_10_*`, sus pruebas y su informe).

## 0. Resumen

- **Tres migraciones, cada una atómica salvo la de índices (va fuera de transacción a propósito):**
  - `2026_11_12_100001_create_ultron_studio_strategy_tables.php` — **gobierno**: 7 tablas `ultron_studio_*` y el libro pasa de 7 a **11 sujetos**;
  - `2026_11_12_100002_create_outreach_tables.php` — **ejecución**: 8 tablas `outreach_*`;
  - `2026_11_12_100003_add_outreach_read_indexes.php` — **4 índices de lectura** (L9-29/L9-31) con `CREATE INDEX CONCURRENTLY`, fuera de transacción e idempotentes. El de `voice_contacts` **no está**: se pospone a V9 (L9-35).
- **Inertes:** solo crean. Las únicas tablas existentes que tocan son `identities` (la FK del vínculo), el CHECK del libro y los 4 índices nuevos de `users`, `payment_transactions`, `class_reservations` y `receivables`. No nombran el motor comercial, Marketing, la voz ni n8n, y no encienden nada.
- **Reglas duras en la base** (§3.4). Todas valen también contra SQL escrito a mano, y cada una tiene su prueba:
  - **solo inserción:** cadena, revisiones, simulaciones, decisiones y resultados;
  - **solo hacia delante:** versiones, plantillas, pasadas, inscripciones y trabajos;
  - **«una vez»:** aprobaciones, verificación, reclamación, referencias del canal, liberación de la reserva y del freno, y supresión;
  - **la cadena** encadenada por huella y con `seq` único;
  - **el cursor** solo crece;
  - **la huella** se comprueba contra los bytes;
  - **una ocurrencia, una inscripción;**
  - **un trabajo vivo** por persona y modo;
  - **la reserva no pasa del techo:** la comprobación va en el mismo `UPDATE` y debajo hay un CHECK;
  - **cuatro ojos por persona verificada**, y en el freno, por cuenta y por persona;
  - **seudónimos** (`subject_ref`, `phone_ref`) y **ningún dato personal**;
  - **supresión 1581**: la forma suprimida se exige por CHECK;
  - **REVOKE** comprobado (42501), **guarda de producción**, **`lock_timeout`** y un `down()` que se niega a revertir si hay filas.
- **Recuentos para OPS9** (PG, medidos con las migraciones aplicadas por un dueño sin superusuario):

  | | H8 → H9 |
  |---|---|
  | Studio · disparadores | 68 → **123** |
  | Studio · CHECK | 84 → **137** |
  | Studio · parciales | 6 → **9** |
  | Studio · tablas | 18 → **25** |
  | `outreach_%` · disparadores / CHECK / parciales / tablas | **51 / 75 / 3 / 8** |
  | Funciones nuevas | **33**, ninguna SECURITY DEFINER |
  | Índices de lectura válidos | **4** |

  En SQLite, los disparadores del Studio pasan de 55 a **108** y los de `outreach_%` son **46**. Detalle y consulta de solo lectura en §5.
- **Mis pruebas (6 ficheros, 78 métodos, 531 casos), en el árbol compartido:**
  - **SQLite: 531 / 2 008 aserciones**, 209 omitidas (solo PG, lo dicen), **0 fallos**;
  - **PG: 531 / 3 443**, 1 omitida (la ida y vuelta de índices, que es de SQLite), **0 fallos**.
- **Mutaciones:** **66 mutantes**, cada uno en el motor donde existe su camino, en una copia aislada; **mueren los 66**. En la primera pasada sobrevivía G24 (quitar el `lock_timeout` del gobierno), porque la prueba no distinguía qué migración lo ponía. La reforcé (§9).
- **Ida y vuelta:**
  - **PG:** `pg_dump -s` idéntico tras `rollback --step=3` y tras volver a migrar; la única línea que de verdad cambia es el CHECK del libro.
  - **SQLite:** +183 / −0 objetos, idéntico tras volver a migrar, y `fresh` sale igual que el incremental.
- **Operación (PG, con `artisan migrate` de verdad):**
  - la guarda de producción falla sin crear nada;
  - con un escritor en `identities`, con un lector del libro y con una transacción larga abierta, la migración cae limpia con 55P03 a los 5–7 s y se reintenta;
  - **100003 deja un índice INVÁLIDO si la tumba el `lock_timeout`, y el reintento lo rehace** (ensayado);
  - **la supresión 1581 se puede ejecutar con el rol dueño sin superusuario** (ensayada, §8.5).
- **Volumen sintético** (20 000 personas, 300 000 cobros y 300 000 reservas): los índices de lectura pasan cuatro prefiltros de recorrer la tabla entera a usar un índice (40 → 2,6 ms el peor caso), con 20 MB de índices a ese volumen (§8.6). Es una medida sobre datos sintéticos, no la latencia de producción.
- **Para el lead** (bloquea la puerta mientras no decida):
  - con mis migraciones caen **las 4 pruebas ajenas que cuentan el Studio** → el parche validado está en el **Anexo A**, sin aplicar;
  - la **prueba de aislamiento** del Studio no conoce las migraciones del H9 → el parche está en el **Anexo B**, sin aplicar;
  - fuera de mi alcance hay **3 pruebas de B9-facts** que dan por hecho que DB9 no existe (§10), y la misma prueba de aislamiento ya fallaba **antes** de mis ficheros por código de B9-facts.

## 1. Motor, versión y entorno

| | Versión | Dónde |
|---|---|---|
| PostgreSQL | 14.24 (Homebrew). Producción es PG 14 según los informes H5–H8; no lo he comprobado allí | Clúster desechable `127.0.0.1:55432`, usuario `ironbody`. Bases mías: `ultron_studio_h9_db` (pgunit), `…_h9_db_roles` (modelo dueño/aplicación), `…_h9_db_mut` (mutaciones), `…_h9_db_rt` (ida y vuelta y volumen), `…_h9_db_op` (operación). La `ultron_studio_h8_lead` es del lead y no la he tocado |
| SQLite | 3.54.1 (PDO de PHP 8.3.33) | `:memory:` en la suite; ficheros en el scratchpad para `migrate`, `rollback` y `fresh` |
| Laravel / PHPUnit | 12.58 / 11.5.55 | Sin fichero de entorno: mandan las variables `DB_*` del proceso |

Roles de ensayo en el clúster local:
- `ultron_studio_h9_owner`: el dueño, LOGIN, sin superusuario;
- `ultron_studio_h9_app`: la aplicación, LOGIN, NOINHERIT.

**Trabajo aislado:** desarrollé y probé en clones APFS del backend dentro del scratchpad: `bk`, `bk_mut` para las mutaciones, `bk_cruce` para cruzar con B9-facts, y `wt_base`/`wt_db9`, que son el árbol sin y con mis ficheros. Con `RefreshDatabase` cada prueba aplica todas las migraciones, así que una migración a medias en el árbol compartido habría roto las suites de los demás paquetes. Copié al árbol solo los 10 ficheros finales.

## 2. Ficheros (lista exacta)

Todos son nuevos y van sin seguimiento. `vendor/bin/pint --test` sobre los 10 PHP da `passed`.

| Fichero | Líneas | Qué |
|---|---|---|
| `database/migrations/2026_11_12_100001_create_ultron_studio_strategy_tables.php` | 1 312 | **Cabecera** 1–150 · **Constantes** 152–350: tablas, estados y grafos 206–240, patrones 242–248, clasificación de columnas 252–292, CHECK de dominio 317 y parciales 346 · **Métodos:** `up()` 352, `down()` 372, `rowChecks()` 427, `rowTriggers()` 536, `sqliteOnlyRowTriggers()` 612, `crossTriggers()` 638, `createTables()` 744, `upPostgres()` 965, `downPostgres()` 1051, `upSqlite()` 1074, `boundLockWait()` 1121, `assertColumnsClassified()` 1142, `replaceSubjectCheck()` 1184, `revokeAppWrites()` 1199 y `refuseWithRows()` 1258 |
| `database/migrations/2026_11_12_100002_create_outreach_tables.php` | 1 375 | **Cabecera** 1–135 · **Constantes** 137–440: estados y grafos 189–250, resultados y matrices 254–304, frenos 306–314, sello y columnas 329–374, CHECK de dominio 388 y parciales 435 · **Métodos:** `up()` 441, `down()` 459, `rowChecks()` 502, `rowTriggers()` 641, `crossTriggers()` 742, `createTables()` 825, `sealColumns()` 1067, `upPostgres()` 1085, `boundLockWait()` 1202, `assertColumnsClassified()` 1216, `revokeAppWrites()` 1265 y `refuseWithRows()` 1323 |
| `database/migrations/2026_11_12_100003_add_outreach_read_indexes.php` | 166 | `$withinTransaction = false`, los 4 índices (56), `createConcurrently()` 117 (rehace un índice inválido), `assertAllValid()` 133 y `withBoundedLockWait()` 147 |
| `tests/Feature/UltronStudio/UltronStudioStrategySchemaTest.php` | 1 405 | 34 métodos: columnas, recuentos, funciones, fronteras y FKs, libro con 11 sujetos, arranque y verificación de vínculos, la matriz de versiones (80 casos) y la de plantillas (20), huella, cuatro ojos, puerta de la simulación, la cadena (con entradas rotas y tabla temporal impostora), unos 75 CHECK de forma, roles y 42501, comprobación posterior, guarda de producción, `lock_timeout` por migración y `down()` |
| `tests/Feature/Outreach/Schema/OutreachSchemaFixtures.php` | 1 110 | Rasgo: constructores de filas válidas de las 15 tablas (sin datos personales), la sonda de CHECK `violatedChecks()`, la reserva atómica `reserve()` y el modelo dueño/aplicación |
| `tests/Feature/Outreach/Schema/OutreachSchemaTest.php` | 606 | 10 métodos: columnas, recuentos, funciones, FKs, ningún dato personal, REVOKE y 42501, comprobación posterior, guarda de producción, ciclo completo como la aplicación y `down()` |
| `tests/Feature/Outreach/Schema/OutreachLifecycleTest.php` | 536 | 14 métodos: pasadas, cursor, inscripciones (matriz de 72 casos), trabajos (90 casos, en sombra y en vivo), lo que se fija, «una vez», el sello, únicos y parciales, CHECK de forma y la forma suprimida |
| `tests/Feature/Outreach/Schema/OutreachLedgerSchemaTest.php` | 385 | 8 métodos: decisiones, resultados (la matriz fuente × resultado con su transición, L9-3), frenos a cuatro ojos y contadores (también por teléfono, L9-30) |
| `tests/Feature/Outreach/Schema/OutreachConcurrencyTest.php` | 371 | 7 carreras reales en PG con dos conexiones (§8.1) |
| `tests/Feature/Outreach/Schema/OutreachReadIndexesTest.php` | 135 | 5 métodos: los 4 índices con sus columnas y válidos, nada sobre `voice_*`, fuera de transacción y concurrente, `up()` idempotente con su `lock_timeout` (PG) y la ida y vuelta (SQLite) |
| `docs/ultron-studio/plans/informes-h9/informe-db.md` | — | Este informe, que sustituye al parcial |

No he tocado ningún fichero ajeno: ni `ops/`, ni las pruebas que cuentan el Studio (Anexo A), ni la de aislamiento (Anexo B), ni nada de B9-core, B9-facts o TEST9-a.

## 3. El esquema (lo que S9, B9-run, B9-facts y TEST9 tienen que usar)

Convenciones de todas las tablas:
- `id` bigint y `uuid` único;
- quien actúa se guarda como instantánea `*_admin_id` + `*_name`, **sin FK** a `admins` (como F–H8), más `*_person_id` (el id de `identities`, sin FK salvo en el vínculo, L9-6);
- la persona del motor es `subject_key` (`m:<id>` | `l:<id>`, o `x:<id>` suprimida) más `subject_ref`, 64 hex del HMAC `outreach-subject/1` (L9-4);
- los motivos son códigos de forma cerrada (p. ej., `reason_code` `^[a-z][a-z0-9_:]{0,63}$`), nunca texto libre;
- `json`, no `jsonb`; el contenido canónico va en `text`, con su huella `sha256` de los bytes;
- las FKs son RESTRICT y tienen nombre corto explícito.

### 3.1 Gobierno (`100001`, 7 tablas)

| Tabla | Ciclo | Reglas que pone la base |
|---|---|---|
| `ultron_studio_person_bindings` | `crm` (pendiente → verificado) o `bootstrap` (verificado al nacer); se revoca una vez | FK `identities` (la única, L9-6). **Arranque solo por consola y solo mientras haya menos de 2 vínculos vivos verificados.** Un vínculo `crm` lo verifica otra persona ya vinculada, distinta de la vinculada y de quien lo creó. Uno sin revocar por cuenta (`uspb_one_active`). No se borra |
| `ultron_studio_strategies` | Viva → archivada (una vez) | `key` (`^[a-z0-9][a-z0-9_-]{2,47}$`) y `purpose` son inmutables. Cada escritura del borrador sube `draft_revision` en exactamente 1. Solo se archiva sin versiones vivas |
| `ultron_studio_message_templates` | `DRAFT` → `APPROVED` \| `REJECTED`; `APPROVED` → `RETIRED` | Contenido congelado al nacer y comprobado contra su huella. La revisa otra persona vinculada. **En WhatsApp, la categoría casa con la finalidad** (cobranza solo `utility`, comercial solo `marketing`) **y lleva `optOutLine: true`**, por CHECK sobre el JSON (PG) |
| `ultron_studio_strategy_versions` | Nace `VALIDATED`/`REVIEW_REQUIRED` → `APPROVED`/`REJECTED`; `APPROVED` → `CANARY`; `CANARY` → `PUBLISHED`/`ROLLED_BACK`; `PUBLISHED` → `RETIRED`/`ROLLED_BACK`; `RETIRED` → `PUBLISHED` | Contenido inmutable con la huella de sus bytes. `catalog_version` (`^outreach-catalog/AAAA-MM-DD.N$`, de modo que `2026-11-10.2` de L9-33 cabe sin cambios) y `evaluator_version` con su forma. Autor: una persona vinculada. **Aprueba otra persona y otra cuenta, las dos vinculadas, y cita una simulación de esa versión y esa huella sin bloqueantes.** Una `CANARY` y una `PUBLISHED` por estrategia (parciales) |
| `ultron_studio_strategy_simulations` | Solo inserción | Recuentos y hallazgos, nunca trazas por persona |
| `ultron_studio_strategy_reviews` | Solo inserción | Una por versión, de otra persona vinculada y de la huella exacta |
| `ultron_studio_strategy_publications` | Solo inserción (la cadena) | `prev_hash` es el `effective_hash` de la cabeza (64 ceros en la primera); `effective_hash = sha256(bytes)`; `seq >= 1` y único (una carrera perdida da 23505, para devolver 409). Acciones: `publish`, `promote`, `pause`, `resume`, `rollback` y `retire`, cada una con su forma. Publica y promueve una persona vinculada distinta de la autora; reanuda una vinculada |

**El libro** (`ultron_studio_audit_events.subject_type`) pasa de 7 a 11 sujetos: suma `strategy`, `strategy_version`, `message_template` y `person_binding`. Se hace al final de `up()`, en una sola sentencia `ALTER TABLE … DROP/ADD CONSTRAINT`, como hizo el H6, y solo en PG: en SQLite ese CHECK no existe.

### 3.2 Ejecución (`100002`, 8 tablas)

Cada fila de inscripciones, trabajos, decisiones y resultados lleva **el sello** de §5:
- estrategia, versión, huella y `publication_seq` (FK a la cadena por `seq`);
- `part`, la referencia del Studio, `catalog_version`, `evaluator_version` y `mode` (`shadow` | `canary` | `live`).

Un disparador entre tablas comprueba que el sello es el de una versión **viva** de su estrategia.

| Tabla | Ciclo | Reglas que pone la base |
|---|---|---|
| `outreach_runs` | `running` → `completed` \| `failed` \| `aborted` (una vez) | Inmutable al cerrarse |
| `outreach_cursors` | — | PK `source`; `last_id` **solo crece**; no se borra |
| `outreach_enrollments` | Estados de D9-10; `active`/`waiting` abiertas | **Una ocurrencia, una inscripción:** `oenr_occurrence_unique (strategy_id, subject_key, cycle_key, mode)` (L9-1, Q-1). Como mucho **una abierta** por estrategia, persona y modo (`oenr_one_open`). El paso y los contactos solo crecen. Exige una versión viva y la finalidad de su estrategia |
| `outreach_jobs` | `planned` → `claimed` → `dispatching` → `started` \| `simulated` \| `failed_to_start` \| `ambiguous`; desde `planned`/`claimed`: `canceled` \| `expired`; `claimed` → `ambiguous` | **Un trabajo vivo por persona y modo** (`ojob_one_live`). Un intento por fila (`ojob_attempt_unique`); `idempotency_key` única. Se fija al planificarse: persona, paso, canal, plantilla, reserva, sello y **`phone_ref`** (64 hex o NULL, L9-30). Son «una vez» la reclamación, las referencias del canal (`marketing_message_id`, `voice_call_id`) y la liberación de la reserva. **En sombra o por el canal simulado no sale nada ni se reserva nada.** Necesita una inscripción abierta y una plantilla aprobada de su canal |
| `outreach_decisions` | Solo inserción | Motivos del conjunto cerrado (códigos), `trace_hash`, foto de hechos derivados y el sello |
| `outreach_outcomes` | Solo inserción | Ver detalle debajo de la tabla |
| `outreach_brakes` | Abierto → suelto (una vez) | Uno abierto por alcance (`obrk_one_open`). **Lo suelta otra cuenta Y otra persona, con nota** (L9-7, P9-20) |
| `outreach_budget_counters` | Contadores | Día de Bogotá × alcance (`channel:…`, `strategy:<uuid>` o `phone:<64 hex>`, L9-2 y L9-30) × modo. La reserva va en un solo `UPDATE … WHERE reserved + n <= cap`, con `CHECK (reserved <= cap)` debajo. No se borra (salvo en la supresión, por el dueño) |

**`outreach_outcomes`:**
- Los 18 resultados de D9-10, con lo que necesita el historial (L9-3): `strategy_id` (FK), `subject_key`, `subject_ref`, `channel`, `reason_code`, `next_action` y `next_action_at`.
- La **matriz fuente → resultado** y la de **resultado → transición** son las de `OutcomeTable::TABLE`.
- **El clasificador solo frena o deriva** (P9-6).
- Si la fuente no es `crm_fact` ni `system`, el resultado lleva su trabajo y el canal de ese trabajo.
- Un `blocked` lleva motivo.
- `UNIQUE (enrollment_id, source, source_key)`.
- Su estrategia y su persona son las de su inscripción (disparador).

**Índices del historial** (L9-3), todos `(subject_key, fecha, id)`: `odec_subject_idx`, `ojob_subject_idx` y `oout_subject_idx`. Para el tope por teléfono, `ojob_phone_idx (phone_ref, budget_day)` (L9-30).

### 3.3 Índices de lectura (`100003`, L9-29/L9-31)

| Índice | Para | Antes |
|---|---|---|
| `users_membership_end_date_h9_idx (membership_end_date)` | `MembershipFacts::endingBetween()`/`endedBetween()` | Ninguno sobre la columna. Sin parcial: `candidatesForOccurrence()` busca también `membership_end_date IS NULL` |
| `payment_transactions_user_status_h9_idx (user_id, status)` | La foto por lotes (`member_id IN … OR user_id IN …`) | Solo el lado `member_id` tenía índice (`pt_member_status_seq_idx`): sin el otro no hay BitmapOr |
| `class_reservations_member_date_h9_idx (member_id, session_date)` | `ClassFacts::facts()` | `member_id` era la segunda columna del único |
| `receivables_status_due_at_h9_idx (status, due_at)` | `ReceivableFacts::overdueBetween()` | El índice con `due_at` empieza por `type` |

En PG, `CREATE INDEX CONCURRENTLY IF NOT EXISTS`, uno por sentencia:
- si el índice ya existe y es válido, no hace nada;
- si una construcción anterior lo dejó inválido, lo borra con `DROP INDEX CONCURRENTLY` y lo vuelve a crear;
- al final comprueba que los cuatro son válidos y, si no, falla;
- si la sesión trae `lock_timeout` 0, pone 5 s mientras dura y después restaura el valor.

`down()` borra solo esos cuatro, por su nombre con `_h9_`. En SQLite, `CREATE INDEX IF NOT EXISTS` dentro de una transacción.

### 3.4 Las reglas duras del encargo: dónde vive cada una y qué prueba la tumba

| Regla | Mecanismo (PG / SQLite) | Prueba (y mutante que muere) |
|---|---|---|
| Solo inserción | Disparador «solo inserción» más `REVOKE UPDATE, DELETE` (PG) / disparador | Ciclo como la aplicación (42501); G21, E21 |
| Hacia delante | `forwardOnly()` por grafo; las filas cerradas, con el disparador «definitivo» | Matrices de 80, 20, 72 y 90 casos; G09, G10, E04, E05, E24 |
| Una vez | `BEFORE UPDATE OF` con `OLD … IS NOT NULL` | Varias pruebas de «una vez»; E06, E14, E15 |
| Cadena | `ultron_studio_strategy_publication_chain()` / WHEN: cabeza + 1, `prev_hash` de la cabeza, `seq` único | Entradas rotas y la carrera 23505; G14, G15 |
| Cursor | `last_id` solo crece | E19 |
| Huella | `content_hash = sha256(bytes)` (CHECK PG) | G13 |
| Una ocurrencia, una inscripción | `oenr_occurrence_unique` | Carrera PG; E01, E27 |
| Un trabajo vivo | Parcial `ojob_one_live` | Carrera PG; E03, E28 |
| Techo de la reserva | `UPDATE … WHERE reserved + n <= cap` más CHECK (PG) / disparador (SQLite) | Carrera PG (exactamente N); E17, E18 |
| Cuatro ojos por persona | Vínculo verificado, CHECK y disparadores | G01–G06, G16, G17, E14, E15 |
| Seudónimos y ningún dato personal | CHECK 64 hex y prueba de nombres de columna | E34, E35 |
| Supresión 1581 | `redacted_at` «una vez» y CHECK de la forma suprimida | Forma suprimida; ensayo como dueño (§8.5) |
| REVOKE (42501) | `revokeAppWrites()` y comprobación posterior | Roles; G21 |
| Guarda de producción | `APP_ENV=production` sin el rol de la aplicación → error | G22; ensayo `artisan` (§8.5) |
| `lock_timeout` | `SET LOCAL '5s'` si es 0 (100001/100002); `SET`/restaurar (100003) | Una prueba por migración; G24, E37, I01 |
| Espejo SQLite | Mismos nombres y textos; WHEN en lugar de funciones | Toda la suite en los dos motores |
| Ida y vuelta | `down()` exacto | §8.3 |
| `down()` que se niega | `refuseWithRows()` con los cerrojos tomados | G23, E22 |

## 4. Correspondencia con el contrato

- **T7 (esquema en PG):** cubierta por las pruebas de §3.4 en PG y por el modelo dueño/aplicación.
- **§13.1:** lo que de esas pruebas vive en la base tiene carrera real en PG en `OutreachConcurrencyTest`; las pruebas de extremo a extremo son de B9-run y TEST9:
  - #5 (un trabajo vivo);
  - #13 (dos workers: una inscripción y un trabajo);
  - #14 (`occurrence_key` único);
  - #16 (exactamente N reservas);
  - #13 y #24 (reclamación con `SKIP LOCKED`, sin doble reclamación);
  - #25 (la cadena solo crece).

| Decisión del lead | Cómo queda |
|---|---|
| L9-1 | Sin tabla de ocurrencias. La unicidad es `oenr_occurrence_unique`; no hay una columna llamada `occurrence_key` (Q-1) |
| L9-2 | `outreach_budget_counters`, con la comprobación en el mismo `UPDATE` y el CHECK |
| L9-3 | Sin tablas nuevas. Evidencia en la cadena (`effective_json`), `catalog_version` con CHECK de forma, historial como modelo de lectura (los tres índices `(subject_key, fecha, id)`) y resultados con estrategia, canal, motivo, resultado y próxima acción |
| L9-4 | `subject_ref` 64 hex, con índice, en inscripciones, trabajos, decisiones y resultados. Rotar `APP_KEY` lo deja obsoleto, **y también `phone_ref`** (para el runbook de OPS9) |
| L9-5 | `redacted_at` «una vez»; el CHECK solo admite la forma suprimida (`x:<id>`, 64 ceros, sin socio ni lead, sin `phone_ref`). Cómo la aplica OPS9, en §8.5 y §13 |
| L9-6 | FK solo en `person_bindings.identity_id`. 55P03 limpio con un escritor en `identities` (§8.5) |
| L9-7 | `obrk_four_eyes_chk` (cuentas Y personas) y su disparador en SQLite |
| L9-29/L9-31 | La 100003 con 4 de los 5 índices; el de `voice_contacts` se pospone a V9 (**L9-35**, a partir de Q-2) |
| L9-30 | `outreach_jobs.phone_ref` inmutable, ámbito `phone:<64 hex>` en los contadores e índice `(phone_ref, budget_day)` |
| L9-33 | Sin cambio de esquema: la base comprueba la forma del catálogo, no la lista de versiones |

## 5. Recuentos antes y después (para OPS9)

Medido en `ultron_studio_h9_db_roles`, migrada entera por el dueño sin superusuario con el rol de la aplicación revocado, como en producción, y en ficheros SQLite nuevos. La columna «Antes» es la del H8: la confirma la ida y vuelta (§8.3) y la resta de los recuentos del H9.

| | Antes (H8) | Con el H9 | Tras `rollback --step=3` |
|---|---|---|---|
| PG · disparadores `ultron_studio_%` | 68 | **123** (+55) | 68 |
| PG · CHECK `ultron_studio_%` | 84 | **137** (+53) | 84 |
| PG · parciales `ultron_studio_%` | 6 | **9** (`uspb_one_active`, `ussv_one_canary`, `ussv_one_published`) | 6 |
| PG · tablas `ultron_studio_%` | 18 | **25** | 18 |
| PG · disparadores / CHECK / parciales / tablas `outreach_%` | 0 / 0 / 0 / 0 | **51 / 75 / 3 / 8** (`oenr_one_open`, `ojob_one_live`, `obrk_one_open`) | 0 |
| PG · funciones nuevas | 0 | **33**: 20 del gobierno (`ultron_studio_chk_*` y `ultron_studio_strategy_publication_chain`) y 13 de la ejecución (`outreach_forbid` y `outreach_chk_*`). Ninguna SECURITY DEFINER | 0 |
| PG · disparadores apagados del H9 | — | 0 | — |
| PG · md5 de `ultron_studio_forbid()` | `4ad748b5f17f09b398f7f641c197d7ee` | el mismo | el mismo |
| PG · sujetos del libro | 7 | **11** | 7 |
| PG · índices `%_h9_idx` válidos | 0 | **4** | 0 |
| SQLite · disparadores `ultron_studio_%` / `outreach_%` | 55 / 0 | **108 / 46** | 55 / 0 |
| SQLite · parciales `ultron_studio_%` / `outreach_%` | 6 / 0 | 9 / 3 | 6 / 0 |
| Migraciones registradas | 330 | **333** | 330 |
| Aplicación en las 5 de solo inserción del H9 (cadena, revisiones, simulaciones, decisiones y resultados) | — | **`ra---`** | — |
| Aplicación en las 10 con estado del H9 | — | **`raw--`** (sin DELETE) | — |

Disparadores por tabla en PG:
- gobierno: vínculos 8, estrategias 7, plantillas 11, versiones 12, simulaciones 4, revisiones 6, cadena 7;
- ejecución: pasadas 6, cursor 4, inscripciones 10, trabajos 11, decisiones 7, resultados 5, frenos 5, contadores 3.

CHECK por tabla:
- gobierno: 5, 6, 12, 11, 5, 4 y 10;
- ejecución: 7, 2, 12, 19, 13, 11, 8 y 3.

**La consulta de solo lectura** está en §15. `ops/h8-schema-check.sql` es el registro del despliegue del H8 y **no se reescribe**: el `h9-schema-check.sql` es de OPS9 y lleva estos números.

## 6. Decisiones fuera del contrato (a confirmar por el lead)

En cada duda elegí la opción más estricta.

| # | Decisión | Por qué y evidencia |
|---|---|---|
| D-1 | **REVOKE DELETE también en las 10 tablas con estado**, no solo UPDATE/DELETE en las de solo inserción. Es más estricto que D-19 de DB8 | Ninguna fila de gobierno ni de ejecución se borra en la aplicación. La supresión la hace el dueño (§8.5). Comprobado: `raw--` y 42501 |
| D-2 | El autor de una versión (`created_by_person_id`) es NOT NULL y tiene que tener un vínculo verificado y vivo | Sin autor vinculado, los cuatro ojos por persona (P9-4) no se pueden comprobar |
| D-3 | El arranque por consola (`bootstrap`) solo vale mientras haya menos de 2 vínculos vivos verificados | Lo justo para que existan las dos primeras personas; después, todo vínculo pasa por el CRM y a cuatro ojos (G04) |
| D-4 | La aprobación cita una simulación **de esa versión y esa huella, sin bloqueantes** | D9-11.1 pide simular antes de aprobar; la base lo asegura (G12) |
| D-5 | Publicar y promover lo hace una persona vinculada distinta de la autora; reanudar exige una persona vinculada | Cuatro ojos por persona (P9-4) también en la cadena (G16) |
| D-6 | Categoría y `optOutLine` de WhatsApp, por CHECK sobre el JSON (PG) | P9-7 y Ley 2300 en la tercera capa (G18, G19) |
| D-7 | Una inscripción exige una versión viva; un trabajo exige una inscripción abierta y una plantilla aprobada de su canal; en sombra nunca se sale ni se reserva | D9-8 y protección 11 (E07–E11) |
| D-8 | La regla asimétrica de la IA (P9-6) tiene su propio CHECK aunque la matriz ya la implique | Redundancia a propósito: si alguien amplía la matriz, P9-6 sigue en pie (E30) |
| D-9 | Un resultado lleva su trabajo y el canal de ese trabajo, salvo `crm_fact` y `system`. `next_action` es obligatoria si el resultado tiene transiciones y prohibida si no | El historial (L9-3) no puede quedar con huecos; T14 y T23 (E31–E33, E36) |
| D-10 | Los contadores `phone:` los borra la supresión; `phone_ref` del trabajo pasa a NULL | Un `phone_ref` es un seudónimo de un dato personal (L9-5) |
| D-11 | `users(membership_end_date)` **sin** parcial | Una de las dos consultas busca también `IS NULL`; medido en §8.6 |
| D-12 | La 100003 rehace un índice inválido y comprueba la validez al final | Un `CONCURRENTLY` fallido deja un índice inválido que `IF NOT EXISTS` se saltaría (ensayado: §8.5) |

## 7. Q-n para el lead (abiertas)

| # | Duda | Propuesta (la más estricta) |
|---|---|---|
| Q-1 | L9-1 dice «`occurrence_key` UNIQUE». Yo lo implementé como el UNIQUE compuesto `(strategy_id, subject_key, cycle_key, mode)`, sin columna `occurrence_key` | Mantenerlo: es la misma garantía sin duplicar datos. Si B9-run o TEST9 necesitan la columna literal, se añade **generada** a partir de esas cuatro (no editable). El `mode` va dentro para que una sombra no bloquee la inscripción en vivo de la misma ocurrencia |
| Q-2 · **resuelta: L9-35** (se pospone a V9 con su enmienda) | L9-31 pide `voice_contacts (source_type, source_id)` | **Fuera de la 100003.** La protección 9 (§3, intocable) dice que «las tablas `voice_*` no cambian el 15-11». **Comprobado en una copia:** con el índice en la 100003, la propia prueba de DB8 `VoiceSchemaTest::test_down_sin_filas_borra_las_siete_tablas_y_sus_funciones_y_up_deja_el_mismo_esquema` falla («up() no dejó lo mismo que la primera vez»), porque el `down()`/`up()` de la telefonía recrea `voice_contacts` sin él. En SQLite: 273 pruebas, 1 fallo. Hoy la tabla está vacía (la fase 4b no ha empezado), así que el índice no da nada. Propuesta: que lo añada V9 en su propia migración, con su enmienda |
| Q-3 | Cómo aplica OPS9 la supresión 1581 | Comprobado (§8.5): en PG 14 el dueño sin superusuario **no puede** usar `session_replication_role` (42501). Sí puede, dentro de una transacción, `ALTER TABLE … DISABLE TRIGGER USER` → `UPDATE` a la forma suprimida → `ENABLE TRIGGER USER`. Mientras dura, nadie más escribe en esas tablas (SHARE ROW EXCLUSIVE), las FKs siguen vigentes y los CHECK solo admiten la forma suprimida de cada fila. Propuesta: ese procedimiento, con orden escrita por solicitud y el SQL de §13. La alternativa (una función SECURITY DEFINER de supresión) metería la primera función definer del H9; no la recomiendo sin SEC9 |
| Q-4 | El H6 (`2026_11_01_100002`) reescribe el CHECK del libro con **sus** 7 sujetos en su `down()`/`up()` | Revertir el H6 con el H9 aplicado no está soportado (protección 11: en producción no se revierte). El parche del Anexo A lo deja dicho en la prueba del H6 |
| Q-5 | ¿La base debe fijar la **lista** de versiones del catálogo? | No: solo la forma (L9-3). Una lista obligaría a migrar con cada catálogo (L9-33 ya pasó a `.2`) |

## 8. Qué verifiqué ejecutando

### 8.1 Pruebas propias

| | Resultado |
|---|---|
| SQLite, en el árbol compartido | `phpunit tests/Feature/Outreach/Schema tests/Feature/UltronStudio/UltronStudioStrategySchemaTest.php` → **OK, 531 pruebas, 2 008 aserciones, 209 omitidas** (las de solo PG), 51 s |
| PG, en el árbol compartido | `UNIT_DB=ultron_studio_h9_db bash docs/ultron-studio/ops/pgunit.sh …` → **OK, 531 pruebas, 3 443 aserciones, 1 omitida (la ida y vuelta de los índices, solo SQLite), 0 fallos, 2 min 11 s** |
| Con las guardas vecinas (aislamiento del motor y esquema de la telefonía del H8) | SQLite 714 / 4 692 y PG 714 / 6 883: **1 fallo, el de aislamiento**, que ya fallaba sin mis ficheros (§10). Ninguna prueba de la telefonía cae |

Las 7 carreras de PG usan dos conexiones reales (`pg_send_query_params` y `pg_blocking_pids`):
1. dos inscripciones de la misma ocurrencia → una;
2. dos trabajos vivos de la misma persona → uno;
3. N reservas a la vez contra un techo de N → exactamente N. Con la guarda en el `UPDATE`, la que sobra afecta 0 filas; sin ella, choca con el CHECK (23514);
4. dos workers con `FOR UPDATE SKIP LOCKED` → nadie reclama dos veces;
5. dos entradas de la cadena con el mismo `seq` → 23505;
6. el mismo resultado dos veces con `ON CONFLICT DO NOTHING` → uno;
7. dos que sueltan el mismo freno → uno.

### 8.2 Cruce con B9-facts (lectura, sin editar sus ficheros)

- **Sus lecturas funcionan sobre mi esquema en los dos motores.** Lo comprobé con una prueba de cruce solo en la copia `bk_cruce`, que no se entrega. Con una inscripción, un trabajo asistido en vivo, una decisión y dos resultados reales:
  - `SubjectTimeline::forSubject()` devuelve `outcome, outcome, decision, job`;
  - la foto de `BatchSnapshotBuilder` da `outreach_history = true` y el contacto `outreach` con su estrategia.
- **Sus pruebas sin mis ficheros:** OK, 50 / 915. **Con mis ficheros caen 3**, en SQLite y en PG (50 / 955, 1 error y 2 fallos), siempre las mismas y todas por suposiciones de sus pruebas (§10).

### 8.3 Ida y vuelta

**PG** (`ultron_studio_h9_db_rt`):
- `migrate` H8 → H9 en 632 ms; `rollback --step=3` en 429 ms;
- `pg_dump -s` (sin las líneas `\restrict`, que son aleatorias) **idéntico al H8**, y la segunda ida **idéntica al H9**;
- del H8 al H9 la única línea que de verdad desaparece es la de `usae_subject_type_chk`: es el libro, que pasa de 7 a 11 sujetos.

**SQLite:**
- +183 / −0 objetos: 99 disparadores, 6 parciales y 15 tablas;
- `rollback --step=3` deja el H8 idéntico, la segunda ida da el H9 idéntico y **`migrate:fresh` sale igual que el incremental**.

### 8.4 Modelo dueño/aplicación con roles reales (`ultron_studio_h9_db_roles`)

- **El dueño sin superusuario** migra las 333 migraciones. Los tiempos de esa pasada (10 s, 12 s y 0,4 s para las tres del H9) los medí con otras suites corriendo a la vez: no son representativos. En reposo, las tres del H9 tardan menos de 1 s (§8.3).
- **Privilegios:** la aplicación queda en `ra---` en las 5 de solo inserción y `raw--` en las 10 con estado.
- **Como la aplicación** (`psql` con el rol de verdad, todo en ROLLBACK):
  - el ciclo completo funciona: personas, versión, aprobación, cadena, inscripción, trabajo simulado, decisión, resultado, freno, reserva y cursor;
  - lo prohibido falla así:
    - **21 × 42501**: UPDATE, DELETE, `FOR UPDATE` y `ON CONFLICT DO UPDATE` en las de solo inserción (6); DELETE en las 6 de estado; 2 TRUNCATE; 4 de DDL (apagar o borrar un disparador, quitar un CHECK, borrar un parcial); redefinir `outreach_forbid()` o una `ultron_studio_chk_*` (2); y `session_replication_role` (1);
    - **4 × P0001**: un trabajo final que cambia, aprobar dos veces, soltar un freno dos veces y **suplantar el vínculo con una tabla temporal del mismo nombre** (la guarda lee `public.`);
    - **1 × 23514**: reservar sin la guarda del `UPDATE` (el CHECK del techo);
    - el `GRANT` que intenta la aplicación da el aviso 01007 y no concede nada.

### 8.5 Operación (PG, `artisan migrate` de verdad, base `ultron_studio_h9_db_op`)

| Caso | Resultado |
|---|---|
| `APP_ENV=production` sin el rol de la aplicación en el clúster | Sale con código 1: «el rol de la aplicación … no existe en este clúster y APP_ENV es production». **0 tablas del H9**, 3 migraciones pendientes |
| Un escritor en `identities` con la transacción abierta | 55P03 a los **5 971 ms**; 0 tablas; 3 pendientes |
| Un lector del libro con la transacción abierta | 55P03 a los **6 422 ms**; 0 tablas; 3 pendientes |
| Un lector de `identities` con la transacción abierta | La 100001 y la 100002 **migran** (no chocan). **La 100003 cae con 55P03** a los 7 291 ms: `CONCURRENTLY` espera a que terminen las transacciones con instantáneas anteriores. Quedan 15 tablas y 1 pendiente |
| Sin nadie | Migra; 0 pendientes; los 4 índices válidos |
| 100003 con un índice **inválido** (como el que deja una construcción fallida) | Lo borra y lo rehace: los 4 válidos y la migración registrada |
| 100003 con un índice ya válido | Lo respeta (mismo oid) |
| 100003 con una transacción `REPEATABLE READ` abierta | Sale con código 1 en 5 295 ms (55P03), sin registrarse y con un índice INVÁLIDO; **el reintento lo repara** |

**Supresión 1581 con el dueño sin superusuario** (base de roles, todo en ROLLBACK). Antes: 1 inscripción, 1 trabajo con `phone_ref`, 1 decisión, 1 resultado y 1 contador `phone:`.
1. **La aplicación no puede:** P0001 (el trabajo fija su persona), 42501 al hacer UPDATE de resultados y al borrar contadores, «must be owner» con `DISABLE TRIGGER` y 42501 con `session_replication_role`.
2. **El dueño tampoco puede usar `session_replication_role`** en PG 14 (42501).
3. **El dueño, con `DISABLE TRIGGER USER` en su transacción** sobre inscripciones, trabajos, decisiones, resultados y contadores:
   - los CHECK siguen rechazando las formas mal hechas: `ojob_subject_chk` con `x:5`, `ojob_phone_chk` si se deja el teléfono y `oenr_subject_chk` si se deja el socio;
   - la buena deja 0 filas con `m:9001`, 0 `phone_ref`, 0 contadores `phone:` y 4 filas con `redacted_at`;
   - al terminar quedan **0 disparadores apagados**.
4. **Una segunda supresión**, ya con las guardas encendidas, es rechazada (los resultados son de solo inserción).

Si el procedimiento se olvida de `outreach_budget_counters`, el `DELETE` de los contadores falla («las reservas del día no se borran»); por eso está en el SQL de §13.

### 8.6 Volumen sintético: planes antes y después de los índices

Montaje:
- **esquema desechable `vol`** dentro de `ultron_studio_h9_db_rt`: copias `LIKE … INCLUDING INDEXES` de `users`, `members`, `classes`, `class_reservations`, `payment_transactions` y `receivables`;
- **datos uniformes:** 20 000 personas, 299 958 reservas, 300 000 cobros y 40 000 cuentas por cobrar;
- **consultas:** el texto de B9-facts con valores, y lotes de 30 claves;
- **medición:** tercera ejecución en caché caliente, `EXPLAIN (ANALYZE, BUFFERS)`;
- al acabar borré el esquema.

| Consulta | Sin el índice del H9 | Con él |
|---|---|---|
| `MembershipFacts::endingBetween` (vence en 7 días) | Seq Scan sobre `users` (descarta 19 880), **5,3 ms**, 519 búferes | Bitmap Index Scan, **0,95 ms**, 351 |
| Foto de cobros (30 + 30 claves) | Parallel Seq Scan, **40,5 ms**, 5 081 búferes (4 104 leídos de disco) | BitmapOr de los dos índices, **2,6 ms**, 2 032 |
| Agregados de cobro del lote | Parallel Seq Scan, **43,4 ms** | BitmapOr, **1,2 ms** |
| `ClassFacts::facts` (30 personas) | Index Only Scan del único, clase por clase, **3,5 ms**, 4 865 búferes | BitmapOr del índice nuevo, **0,28 ms**, 188 |
| `ClassFacts::candidatesForOccurrence` | 2,6 ms (usa el índice que ya había) | 2,6 ms: **no cambia** |
| `ReceivableFacts::overdueBetween` (1–30 días) | Seq Scan (descarta 39 447), **10,4 ms** | Bitmap Index Scan, **1,2 ms** |

**Coste a ese volumen:** 256 kB (`users`), 11 MB (`class_reservations`), 8,6 MB (`payment_transactions`) y 560 kB (`receivables`), unos **20 MB** en total. Cada inserción en esas tablas escribe una entrada más.

El único cambio en la forma de escribir es este: una renovación que cambie `users.membership_end_date` **deja de poder ser HOT**. En las otras tres, las columnas nuevas ya estaban en algún índice.

**Hecho:** el plan cambia y el tiempo baja en estos datos. **Hipótesis:** en producción el efecto será menor, porque las tablas reales son de unos pocos miles de personas. No he medido producción, como pide el encargo, y PERF9 no ha medido con volumen.

### 8.7 Suite completa del backend (árbol sin y con mis ficheros): **no concluida**

Lancé `phpunit` completo en SQLite sobre dos copias del árbol: `wt_base`, sin mis ficheros, con 15 073 pruebas, y `wt_db9`, con ellos, con 15 603. **El sistema las paró por falta de memoria** (la máquina estaba saturada por los E2E y las suites de otros agentes) tras más de una hora, al **93 %** y al **91 %**. El JUnit solo se escribe al terminar, así que **no hay nombres de las pruebas que fallaron**.

Lo único que queda son las marcas de progreso hasta el corte, contando solo las líneas de progreso de PHPUnit:

| | Resultados | `.` | F | E | S |
|---|---|---|---|---|---|
| Sin DB9 | 14 134 | 13 815 | 5 | 55 | 259 |
| Con DB9 | 14 254 | 13 834 | 13 | 56 | 351 |

**No son comparables una a una:** cada recorrido se cortó en un punto distinto y con DB9 hay 530 pruebas más. Lo esperado con DB9, por las ejecuciones parciales, es +11 F y +1 E:
- **9 F** de las pruebas de recuentos (§10.1);
- **2 F y 1 E** de B9-facts (§10.3);
- la de aislamiento ya fallaba sin DB9.

Lo observado (+8 F, +1 E) **es compatible con eso, pero no lo prueba**. Siguiendo la instrucción del sistema, no las he vuelto a lanzar. Para repetirlas sin perderlo todo con un corte, propongo hacerlo por directorios, con un JUnit por tramo y con la máquina libre.

**Lo que sí está verificado** en el árbol compartido y en los dos motores, salvo donde se indica:
- mis 6 ficheros (531 casos, 0 fallos);
- las 4 pruebas de recuentos sin el parche (PG 10 fallos, SQLite 9) y con él (0 fallos);
- la de aislamiento (1 fallo previo a DB9, más mis dos migraciones; el Anexo B quita estas);
- el esquema de la telefonía del H8 (0 fallos);
- B9-core (`tests/Unit/Outreach`, 0 fallos en SQLite);
- B9-facts (las mismas 3 en SQLite y en PG).

### 8.8 El Anexo A, validado contra las migraciones finales

Copia del árbol con el parche aplicado: **SQLite 158 / 997** (80 omitidas) y **PG 158 / 1 672**, 0 fallos. Las cuatro pruebas del árbol son idénticas a los originales sobre los que se hizo el parche, y `git apply --check` desde la raíz del repo pasa limpio.

## 9. Mutaciones

Arnés `mutantes.py`, en la copia `bk_mut` y con la base `ultron_studio_h9_db_mut`. Cada mutante sustituye **un** fragmento, que tiene que aparecer exactamente una vez, y corre solo las pruebas de su camino en el motor donde existe. Los controles sin mutar pasan: SQLite en 47 s y PG en 464 s.

- **Gobierno (G01–G25):** cuatro ojos solo por cuenta (PG y SQLite), vínculo sin verificar, tres por consola, verifica cualquiera, la misma persona verifica su otra cuenta, el borrador salta revisiones, archivar con una publicada, `CANARY` → `APPROVED`, `ROLLED_BACK` deja de ser final, nacer `DRAFT`, simulación con bloqueantes, huella que no es la de los bytes, cadena sin `prev_hash` (PG y SQLite), publica la otra cuenta de la autora, revisa la autora, cobranza de marketing, WhatsApp sin línea de baja, dos en canario, sin REVOKE DELETE, sin guarda de producción, `down()` que ignora el libro, espera sin límite y libro sin `person_binding`.
- **Ejecución (E01–E37):** la ocurrencia sin el modo; `waiting` que no cuenta como abierta; `dispatching` y `planned` que no cuentan como vivos (este último con la carrera); `dispatching` → `canceled`; `ambiguous` que deja de ser final; reclamar dos veces; en sombra sale o reserva; inscribir una versión no viva; plantilla sin aprobar; planificar una inscripción cerrada; motivos en texto libre; el clasificador da `answered`; la otra cuenta suelta su freno (PG y SQLite); dos PARADAS GLOBALES (NULL distinto); el techo sin comprobar (PG con la carrera, y SQLite); el cursor baja; una pasada cerrada cambia; decisiones con UPDATE; `down()` que ignora las reservas; los contactos bajan; se adelanta un trabajo; ventana de 90 días; clasificador determinista; la ocurrencia sin UNIQUE (carrera); tras una baja la secuencia sigue (prueba 10); sin la regla asimétrica (P9-6); un resultado del simulado sin su trabajo; resultado de otra persona o estrategia; resultado con otro canal; teléfono en claro en el trabajo y en el contador; `ambiguous` con transición; y la ejecución espera sin límite.
- **Índices (I01–I04):** esperar sin límite, rehacer índices válidos en el reintento, columnas al revés y `CONCURRENTLY` dentro de una transacción.

**Resultado: mueren los 66.** Dos ajustes en el camino:
- **G24 sobrevivía:** la prueba de `lock_timeout` aplicaba las dos migraciones en la misma transacción, y si el gobierno dejaba de poner el límite, lo ponía la ejecución. La rehíce para comprobar **cada migración por separado**. Al mismo tiempo añadí E37 (la ejecución) e I01 (la 100003); mueren los tres.
- **E14** no encontraba su fragmento, porque Pint había cambiado las comillas; corregido el fragmento, muere.

## 10. Impacto en pruebas y guardas ajenas

1. **Las 4 pruebas que cuentan el Studio** caen con mis migraciones, por sus recuentos absolutos (los 68/84/6/18 del H8 y los 7 sujetos del libro): `UltronStudioIntegrationsSchemaTest`, `UltronStudioHardeningH7Test`, `UltronStudioPublicationSchemaTest` y `UltronStudioVoiceSchemaTest`. En el árbol, sin el parche, en **PG: 158 pruebas, 10 fallos**, y en **SQLite: 158 pruebas, 9 fallos** (80 omitidas; la décima es solo de PG, la de los siete sujetos del libro):
   - **H7:** recuentos de la cadena y `down`/`up`;
   - **H6:** recuentos, los siete sujetos del libro, los dos `down` y la clasificación de columnas;
   - **H5:** recuentos y `down`/`up`;
   - **H8:** recuentos de la voz. Parche en el **Anexo A**, sin aplicar, validado (§8.8). Líneas: +23/−19, +9/−9, +8/−8 y +7/−7.
2. **`UltronEngineIsolationTest::test_fuera_del_studio_nadie_nombra…`:**
   - mis dos migraciones nombran tablas `ultron_studio_*`. El parche del **Anexo B** las añade por **ruta exacta**, como REV8 m-2 con las de DB8. Con él aplicado, la prueba deja de citarlas;
   - **aun así sigue en rojo, y ya lo estaba sin mis ficheros**, por tres ficheros de B9-facts que leen `ultron_studio_*`: `CanaryFacts` (`ultron_studio_canary_numbers`), `ContactHistoryFacts` y `SubjectTimeline` (estrategias y versiones). Esa lista permitida la decide SEC9 con el lead (L9-28 y §6.3); no es mía.
3. **B9-facts, 3 pruebas** que suponen que DB9 no existe (las arregla el lead con §12):
   - `SubjectTimelineTest::test_compone…`: su `esquemaProvisional()` no crea nada si las tablas existen, y luego inserta filas mínimas que no cumplen el esquema real (`NOT NULL` de `client_request_id` en estrategias, y así con lo demás). Tiene que construir filas reales; los constructores de `OutreachSchemaFixtures` sirven para eso;
   - `AgendaEngineHistoryFactsTest::test_historial_cuenta_solo…`: afirma `outreach_history = false`, y con DB9 es `true`;
   - `SubjectAndSnapshotTest::test_una_consulta_por_fuente…`: con DB9 la foto hace **43 consultas por lote, frente al tope de 40** (sin DB9, 39). No es un N+1, porque son las mismas con 3 y con 45 claves. Son **4 de más**: 3 `Schema::hasTable` (la memoización de `ContactHistoryFacts::hasOutreachSource()` es por instancia y cada lote crea una nueva) y 1 la consulta real de resultados. Propuesta para B9-facts: memoizar la existencia de las tablas por proceso, o ajustar el tope.

## 11. Impacto, riesgos y reversibilidad

- **Qué mejora:**
  - las reglas del H9 valen aunque falle el servicio o alguien escriba SQL a mano;
  - el historial y el tope por teléfono tienen índice;
  - los prefiltros de B9-facts dejan de recorrer tablas enteras (§8.6).
- **Qué cuesta:**
  - cada escritura en una tabla del H9 pasa por los disparadores de esa tabla (de 3 a 12, casi todos de comprobación por fila);
  - los 4 índices de lectura van sobre tablas vivas: unos 20 MB a 5 veces el volumen real, y una entrada de índice más por cada fila nueva;
  - las renovaciones de `users` dejan de ser HOT por el índice de `membership_end_date`.
- **Cerrojos en producción (fase 1):**

  | Migración | Qué pide | Con qué choca |
  |---|---|---|
  | 100001 | SHARE ROW EXCLUSIVE sobre `identities` (la FK) y ACCESS EXCLUSIVE sobre `ultron_studio_audit_events` (el CHECK) | Con un escritor de identidades o cualquier transacción que haya tocado el libro; falla en 5 s y se reintenta |
  | 100002 | Cerrojos solo sobre tablas nuevas | Nada existente |
  | 100003 | SHARE UPDATE EXCLUSIVE | No bloquea lecturas ni escrituras, pero espera a toda transacción con una instantánea anterior y cae en 5 s si la hay |

- **Riesgo de la 100003:** `artisan migrate` aplica las tres seguidas, y si la 100003 cae, sale con código 1 con la 100001 y la 100002 ya aplicadas. No es un problema de datos: los índices son reintentables e independientes. Pero un guion de despliegue que trate ese código como «revertir» haría daño. Por eso OPS9 debería aplicarla como **paso separado** (§13).
- **Vuelta atrás:** `down()` existe y está probado en los dos motores. Se niega si hay filas (o eventos del libro del H9) y nunca borra filas. En producción **no se revierte** (protección 11): la vuelta atrás operativa son las banderas (§9 del contrato). La 100003 sí se puede revertir sin riesgo (`DROP INDEX CONCURRENTLY`).
- **Lo que la base no cubre** está escrito en la cabecera de cada migración. Lo garantizan el servicio y el monitor:
  - que el contador sea la suma de las reservas vivas;
  - que la traza sea la del evaluador;
  - que la escalera N1–N7 se respete;
  - que la firma de la cadena sea buena.

## 12. Hallazgos para otros dueños

- **B9-facts:**
  - las 3 pruebas de §10.3;
  - `SubjectTimeline::outcomes()` filtra por `e.subject_key` con un JOIN a inscripciones. Desde L9-3, `outreach_outcomes` lleva su `subject_key`, y `oout_subject_idx (subject_key, occurred_at, id)` sirve esa consulta tal cual, sin el JOIN. Funciona igual (§8.2), pero no usa el índice que se creó para eso;
  - la entrada `outcome` puede leer `next_action`/`next_action_at` del resultado (L9-3) en lugar de deducirlos de la inscripción.
- **SEC9:**
  - la lista permitida de la prueba de aislamiento para `app/Services/Outreach/Facts` (§10.2);
  - la supresión como dueño con `DISABLE TRIGGER USER` (Q-3);
  - que rotar `APP_KEY` deja obsoletos `subject_ref` y `phone_ref` (L9-4).
- **OPS9:**
  - los recuentos (§5) para `h9-schema-check.sql`;
  - la 100003 como paso aparte y en hora de poco tráfico;
  - el SQL de la supresión (§13);
  - en el runbook de L9-4, también `phone_ref`.
- **B9-run:**
  - la reserva **solo** por `UPDATE … WHERE reserved + n <= cap` (0 filas = sin presupuesto; nunca leer y luego escribir);
  - un resultado se registra con `insertOrIgnore` (ON CONFLICT DO NOTHING; `DO UPDATE` da 42501);
  - `phone_ref` va **al crear** el trabajo (después es inmutable);
  - los resultados que no vienen de `crm_fact` ni de `system` llevan `job_id` y el `channel` de ese trabajo.
- **TEST9:** la carrera de dos workers (#13) y la de exactamente N (#16) tienen su versión de esquema en `OutreachConcurrencyTest`, que pueden reutilizar.

## 13. Operaciones pendientes de autorización (no he ejecutado ninguna)

1. **Migrar en producción (fase 1, OPS9, con orden escrita del propietario):**
   - con el rol `ironbody-migrate` (que ya trae `lock_timeout` 5 s) y `ULTRON_STUDIO_DB_APP_ROLE=iron_app`;
   - **dos pasos**: primero las 100001 y 100002 y después, aparte, la 100003, en hora de poco tráfico y tras comprobar que no hay transacciones largas abiertas.

   ```bash
   php artisan migrate --force --path=database/migrations/2026_11_12_100001_create_ultron_studio_strategy_tables.php
   php artisan migrate --force --path=database/migrations/2026_11_12_100002_create_outreach_tables.php
   # comprobar que no hay transacciones largas (solo lectura):
   #   SELECT pid, now() - xact_start AS dura, state FROM pg_stat_activity WHERE xact_start < now() - interval '30 seconds' ORDER BY 2 DESC;
   PGOPTIONS='-c lock_timeout=60s' php artisan migrate --force --path=database/migrations/2026_11_12_100003_add_outreach_read_indexes.php
   ```

   Esperar más en la 100003 no frena a nadie: `CONCURRENTLY` no bloquea lecturas ni escrituras mientras espera. Si aun así cae, se reintenta: rehace el índice inválido. Después, `h9-schema-check.sql` con los números de §5.
2. **Supresión 1581 de una persona (OPS9, una orden por solicitud).** Se ejecuta como el dueño, en una sola transacción, después de convertirla en `SELECT` y contar:

   ```sql
   -- Antes: contar lo que se va a suprimir (solo lectura).
   SELECT (SELECT count(*) FROM outreach_enrollments WHERE subject_key = :'clave') AS inscripciones,
          (SELECT count(*) FROM outreach_jobs        WHERE subject_key = :'clave') AS trabajos,
          (SELECT count(*) FROM outreach_decisions   WHERE subject_key = :'clave') AS decisiones,
          (SELECT count(*) FROM outreach_outcomes    WHERE subject_key = :'clave') AS resultados,
          (SELECT count(*) FROM outreach_budget_counters WHERE scope = ANY (:'telefonos'::text[])) AS contadores;
   BEGIN;
   ALTER TABLE outreach_enrollments DISABLE TRIGGER USER;
   ALTER TABLE outreach_jobs DISABLE TRIGGER USER;
   ALTER TABLE outreach_decisions DISABLE TRIGGER USER;
   ALTER TABLE outreach_outcomes DISABLE TRIGGER USER;
   ALTER TABLE outreach_budget_counters DISABLE TRIGGER USER;
   UPDATE outreach_enrollments SET subject_key = 'x:' || id, subject_ref = repeat('0', 64), member_id = NULL, lead_id = NULL, redacted_at = now() WHERE subject_key = :'clave';
   UPDATE outreach_jobs        SET subject_key = 'x:' || id, subject_ref = repeat('0', 64), phone_ref = NULL, redacted_at = now() WHERE subject_key = :'clave';
   UPDATE outreach_decisions   SET subject_key = 'x:' || id, subject_ref = repeat('0', 64), redacted_at = now() WHERE subject_key = :'clave';
   UPDATE outreach_outcomes    SET subject_key = 'x:' || id, subject_ref = repeat('0', 64), redacted_at = now() WHERE subject_key = :'clave';
   DELETE FROM outreach_budget_counters WHERE scope = ANY (:'telefonos'::text[]);   -- 'phone:<ref>' de la persona
   ALTER TABLE outreach_enrollments ENABLE TRIGGER USER;
   ALTER TABLE outreach_jobs ENABLE TRIGGER USER;
   ALTER TABLE outreach_decisions ENABLE TRIGGER USER;
   ALTER TABLE outreach_outcomes ENABLE TRIGGER USER;
   ALTER TABLE outreach_budget_counters ENABLE TRIGGER USER;
   -- Comprobar antes de COMMIT: 0 filas con la clave y 0 disparadores apagados en outreach_%; si no, ROLLBACK.
   COMMIT;
   ```

   Ensayado tal cual en local (§8.5). Que el procedimiento sea este lo decide el lead con SEC9 (Q-3).
3. **Los parches de los Anexos A y B:** los aplica el lead en la rama del H9 (`git apply` desde la raíz del repo), no yo.
4. **El índice de `voice_contacts`:** se pospone a V9 (L9-35). En el H9 no hay nada que hacer.

## 14. Qué no verifiqué, y por qué

- **Producción:** ni versión, ni volúmenes, ni roles, ni cerrojos reales. No estoy autorizado y el encargo lo prohíbe. Los tiempos de §8.5 y §8.6 son del clúster local.
- **El efecto de los índices con los datos reales:** solo con datos sintéticos (§8.6).
- **El servicio:** no he probado S9, B9-run ni el CRM sobre este esquema. B9-run no existía en el árbol cuando cerré, y las pruebas de §13.1 de extremo a extremo son suyas.
- **La firma de la cadena:** la base comprueba la huella de los bytes, no la firma (lo hace el lector, D9-11.3).
- **Que `iron_app` y `ironbody-migrate` sean como mis roles de ensayo:** lo supongo por el H8. OPS9 lo comprueba en el paso 0.
- **La suite completa:** en SQLite, el sistema la paró por falta de memoria al 93 % y al 91 % y no se pudo comparar fallo a fallo (§8.7); en PG no la lancé. En los dos motores corrí mis pruebas, las de la telefonía, la de aislamiento, B9-facts y las 4 de recuentos (con y sin el parche); B9-core, solo en SQLite.

## 15. Reproducir

```bash
cd Iron-Body/backend
php -d memory_limit=2G vendor/bin/phpunit tests/Feature/Outreach/Schema tests/Feature/UltronStudio/UltronStudioStrategySchemaTest.php
PGGSSENCMODE=disable UNIT_DB=ultron_studio_h9_db bash docs/ultron-studio/ops/pgunit.sh tests/Feature/Outreach/Schema tests/Feature/UltronStudio/UltronStudioStrategySchemaTest.php
# Modelo de roles: migrar como el dueño revocando al rol de la aplicación
env ULTRON_STUDIO_DB_APP_ROLE=<rol app> APP_ENV=local PGOPTIONS='-c lock_timeout=5s' DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55432 \
    DB_DATABASE=<base> DB_USERNAME=<rol dueño> DB_PASSWORD= DB_URL= php artisan migrate --force
```

La consulta de recuentos (solo lectura; `psql -v app=<rol app> -f`):

```sql
-- Recuentos del esquema del H9 (solo lectura). Variable: :app (el rol de la aplicación).
SELECT 'disparadores_studio', count(*) FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid WHERE NOT t.tgisinternal AND c.relname LIKE 'ultron\_studio\_%'
UNION ALL SELECT 'check_studio', count(*) FROM pg_constraint k JOIN pg_class c ON c.oid=k.conrelid WHERE k.contype='c' AND c.relname LIKE 'ultron\_studio\_%'
UNION ALL SELECT 'parciales_studio', count(*) FROM pg_index i JOIN pg_class t ON t.oid=i.indrelid WHERE t.relname LIKE 'ultron\_studio\_%' AND i.indpred IS NOT NULL
UNION ALL SELECT 'tablas_studio', count(*) FROM pg_tables WHERE schemaname='public' AND tablename LIKE 'ultron\_studio\_%'
UNION ALL SELECT 'disparadores_gobierno_h9', count(*) FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid WHERE NOT t.tgisinternal AND c.relname IN ('ultron_studio_person_bindings','ultron_studio_strategies','ultron_studio_message_templates','ultron_studio_strategy_versions','ultron_studio_strategy_simulations','ultron_studio_strategy_reviews','ultron_studio_strategy_publications')
UNION ALL SELECT 'check_gobierno_h9', count(*) FROM pg_constraint k JOIN pg_class c ON c.oid=k.conrelid WHERE k.contype='c' AND c.relname IN ('ultron_studio_person_bindings','ultron_studio_strategies','ultron_studio_message_templates','ultron_studio_strategy_versions','ultron_studio_strategy_simulations','ultron_studio_strategy_reviews','ultron_studio_strategy_publications')
UNION ALL SELECT 'disparadores_outreach', count(*) FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid WHERE NOT t.tgisinternal AND c.relname LIKE 'outreach\_%'
UNION ALL SELECT 'check_outreach', count(*) FROM pg_constraint k JOIN pg_class c ON c.oid=k.conrelid WHERE k.contype='c' AND c.relname LIKE 'outreach\_%'
UNION ALL SELECT 'parciales_outreach', count(*) FROM pg_index i JOIN pg_class t ON t.oid=i.indrelid WHERE t.relname LIKE 'outreach\_%' AND i.indpred IS NOT NULL
UNION ALL SELECT 'tablas_outreach', count(*) FROM pg_tables WHERE schemaname='public' AND tablename LIKE 'outreach\_%'
UNION ALL SELECT 'disparadores_apagados_h9', count(*) FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid WHERE NOT t.tgisinternal AND t.tgenabled <> 'O' AND (c.relname LIKE 'outreach\_%' OR c.relname IN ('ultron_studio_person_bindings','ultron_studio_strategies','ultron_studio_message_templates','ultron_studio_strategy_versions','ultron_studio_strategy_simulations','ultron_studio_strategy_reviews','ultron_studio_strategy_publications'))
UNION ALL SELECT 'funciones_h9', count(*) FROM pg_proc WHERE proname LIKE 'outreach\_%' OR proname LIKE 'ultron\_studio\_chk\_%' OR proname = 'ultron_studio_strategy_publication_chain'
UNION ALL SELECT 'funciones_h9_definer', count(*) FROM pg_proc WHERE (proname LIKE 'outreach\_%' OR proname LIKE 'ultron\_studio\_chk\_%' OR proname = 'ultron_studio_strategy_publication_chain') AND prosecdef
UNION ALL SELECT 'indices_lectura_h9_validos', count(*) FROM pg_index i JOIN pg_class c ON c.oid=i.indexrelid WHERE c.relname LIKE '%\_h9\_idx' AND i.indisvalid AND i.indisready
UNION ALL SELECT 'libro_11_sujetos', (pg_get_constraintdef(oid) LIKE '%person_binding%')::int FROM pg_constraint WHERE conname='usae_subject_type_chk'
UNION ALL SELECT 'forbid_f_md5_'||md5(pg_get_functiondef(oid)), 1 FROM pg_proc WHERE proname='ultron_studio_forbid';
SELECT 'privilegios_app '||c.relname||' '||
       CASE WHEN has_table_privilege(:'app', c.oid, 'SELECT') THEN 'r' ELSE '-' END||
       CASE WHEN has_table_privilege(:'app', c.oid, 'INSERT') THEN 'a' ELSE '-' END||
       CASE WHEN has_any_column_privilege(:'app', c.oid, 'UPDATE') THEN 'w' ELSE '-' END||
       CASE WHEN has_table_privilege(:'app', c.oid, 'DELETE') THEN 'd' ELSE '-' END||
       CASE WHEN has_table_privilege(:'app', c.oid, 'TRUNCATE') THEN 'D' ELSE '-' END
  FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
 WHERE n.nspname = 'public' AND c.relkind = 'r' AND (c.relname LIKE 'outreach\_%' OR c.relname IN ('ultron_studio_person_bindings','ultron_studio_strategies','ultron_studio_message_templates','ultron_studio_strategy_versions','ultron_studio_strategy_simulations','ultron_studio_strategy_reviews','ultron_studio_strategy_publications'))
 ORDER BY c.relname;
```

## Anexo A · El parche de las 4 pruebas que cuentan el Studio (§10.1), sin aplicar

Validado contra las migraciones finales (§8.8): SQLite 158 / 997 con 80 omitidas y PG 158 / 1 672, 0 fallos. Se aplica con `git apply` desde la raíz del repo; `git apply --check` pasa limpio sobre el árbol actual. Qué cambia:

- **`UltronStudioIntegrationsSchemaTest`:** los 11 sujetos del libro. `assertH6SchemaPresent()` recibe la lista que espera: 11 con el H9 aplicado y 7 tras el `down()`/`up()` del H6, que reescribe el CHECK con los suyos (Q-4).
- **`UltronStudioHardeningH7Test`**, **`UltronStudioPublicationSchemaTest`** y **`UltronStudioVoiceSchemaTest`:** los recuentos absolutos del Studio con el H9 (disparadores 123 y CHECK 137 en PG, 108 disparadores en SQLite), la lista de los 9 parciales (`uspb_one_active`, `ussv_one_canary` y `ussv_one_published` además de los 6 del H8) y los 11 sujetos del libro.

```diff
--- a/backend/tests/Feature/UltronStudio/UltronStudioIntegrationsSchemaTest.php
+++ b/backend/tests/Feature/UltronStudio/UltronStudioIntegrationsSchemaTest.php
@@ -67,17 +67,17 @@
      * aplicado: `*_H5` es el esquema sin el H6 (el down() del H6 no toca las
      * guardas del H7, que son de la cadena) y `*_H6`, con él.
      */
-    private const PG_TRIGGERS_H5 = 55;   // F 11 + laboratorio 16 + H5 3 + H7 1 + H8 24
+    private const PG_TRIGGERS_H5 = 110;  // F 11 + laboratorio 16 + H5 3 + H7 1 + H8 24 + H9 55
 
-    private const PG_TRIGGERS_H6 = 68;   // + asociaciones 5, canarios 5, instantáneas 3
+    private const PG_TRIGGERS_H6 = 123;  // + asociaciones 5, canarios 5, instantáneas 3
 
-    private const PG_CHECKS_H5 = 62;     // F 11 + laboratorio 13 + H5 3 + H7 1 + H8 34
+    private const PG_CHECKS_H5 = 115;    // F 11 + laboratorio 13 + H5 3 + H7 1 + H8 34 + H9 53
 
-    private const PG_CHECKS_H6 = 84;     // + 4, 12 y 6 (usae_subject_type_chk se sustituye, no se suma)
+    private const PG_CHECKS_H6 = 137;    // + 4, 12 y 6 (usae_subject_type_chk se sustituye, no se suma)
 
-    private const SQLITE_TRIGGERS_H5 = 45; // F 7 + laboratorio 11 + H5 2 + H7 2 + H8 23
+    private const SQLITE_TRIGGERS_H5 = 98; // F 7 + laboratorio 11 + H5 2 + H7 2 + H8 23 + H9 53
 
-    private const SQLITE_TRIGGERS_H6 = 55; // + 4, 4 y 2
+    private const SQLITE_TRIGGERS_H6 = 108; // + 4, 4 y 2
 
     /** Contrato §I3.1, en orden: columna → [tipo en PG, tipo en SQLite, NULL-able]. */
     private const LINK_COLUMNS = [
@@ -186,6 +186,9 @@
 
     private const SEVEN_SUBJECTS = ['draft', 'version', 'rule', 'system', 'campaign_link', 'canary_number', 'integration'];
 
+    /** Con el Hito 9 (DB9, 2026_11_12_100001) el libro admite 4 más. El up() del H6 los reescribe a sus 7: en producción, el orden del rollback lo impide. */
+    private const ELEVEN_SUBJECTS = [...self::SEVEN_SUBJECTS, 'strategy', 'strategy_version', 'message_template', 'person_binding'];
+
     /** Contrato §I8: los eventos nuevos y su sujeto (caben en `event` varchar(48) y `subject_type` varchar(16)). */
     private const H6_EVENTS = [
         'campaign_link.created' => 'campaign_link',
@@ -334,7 +337,7 @@
 
     public function test_recuentos_de_disparadores_check_e_indices_parciales_del_studio(): void
     {
-        $this->assertSame(['uscl_one_active', 'uscn_one_live', 'usv_one_canary', 'usv_one_published', 'usva_one_canary', 'usva_one_rest'], $this->studioPartialIndexes());
+        $this->assertSame(['uscl_one_active', 'uscn_one_live', 'uspb_one_active', 'ussv_one_canary', 'ussv_one_published', 'usv_one_canary', 'usv_one_published', 'usva_one_canary', 'usva_one_rest'], $this->studioPartialIndexes());
 
         if ($this->isPostgres()) {
             foreach (self::PG_TRIGGERS as $tabla => $nombres) {
@@ -343,8 +346,8 @@
             foreach (self::PG_CHECKS as $tabla => $nombres) {
                 $this->assertSame($nombres, $this->checksOn($tabla), $tabla);
             }
-            $this->assertSame(self::PG_TRIGGERS_H6, $this->studioTriggerCount(), 'F 11 + laboratorio 16 + H5 3 + H6 13 + H7 1 + H8 24');
-            $this->assertSame(self::PG_CHECKS_H6, $this->studioCheckCount(), 'F 11 + laboratorio 13 + H5 3 + H6 22 + H7 1 + H8 34');
+            $this->assertSame(self::PG_TRIGGERS_H6, $this->studioTriggerCount(), 'F 11 + laboratorio 16 + H5 3 + H6 13 + H7 1 + H8 24 + H9 55');
+            $this->assertSame(self::PG_CHECKS_H6, $this->studioCheckCount(), 'F 11 + laboratorio 13 + H5 3 + H6 22 + H7 1 + H8 34 + H9 53');
 
             return;
         }
@@ -352,7 +355,7 @@
         foreach (self::PG_TRIGGERS as $tabla => $nombres) {
             $this->assertSame(array_values(array_filter($nombres, static fn (string $n): bool => ! str_ends_with($n, '_truncate'))), $this->triggersOn($tabla), $tabla);
         }
-        $this->assertSame(self::SQLITE_TRIGGERS_H6, $this->studioTriggerCount(), 'F 7 + laboratorio 11 + H5 2 + H6 10 + H7 2 + H8 23');
+        $this->assertSame(self::SQLITE_TRIGGERS_H6, $this->studioTriggerCount(), 'F 7 + laboratorio 11 + H5 2 + H6 10 + H7 2 + H8 23 + H9 53');
     }
 
     public function test_los_disparadores_reutilizan_la_funcion_de_f_con_su_mismo_cuerpo(): void
@@ -735,8 +738,8 @@
     {
         $this->requirePostgres('El CHECK de sujetos solo existe en PostgreSQL (F); en SQLite lo vigila `StudioAuditEvent::SUBJECT_TYPES`.');
 
-        $this->assertSame(self::SEVEN_SUBJECTS, $this->checkValues('usae_subject_type_chk', self::AUDIT));
-        foreach (self::SEVEN_SUBJECTS as $sujeto) {
+        $this->assertSame(self::ELEVEN_SUBJECTS, $this->checkValues('usae_subject_type_chk', self::AUDIT));
+        foreach (self::ELEVEN_SUBJECTS as $sujeto) {
             $this->insertEvent($sujeto, "{$sujeto}.prueba");
         }
         // Los nombres de §I8 caben en sus columnas.
@@ -746,7 +749,7 @@
         foreach (['campaign', 'canary', 'integrations', 'campaign_links', ''] as $inventado) {
             $this->assertRejected(fn () => $this->insertEvent($inventado, 'x.y'), '23514', 'usae_subject_type_chk', "«{$inventado}»");
         }
-        $this->assertSame(7 + count(self::H6_EVENTS), DB::table(self::AUDIT)->count());
+        $this->assertSame(11 + count(self::H6_EVENTS), DB::table(self::AUDIT)->count());
     }
 
     // ── Índices parciales ───────────────────────────────────────────────────
@@ -823,7 +826,7 @@
         foreach ([self::LINKS, self::CANARIES, self::SNAPSHOTS] as $tabla) {
             $this->assertFalse(Schema::hasTable($tabla), $tabla);
         }
-        $this->assertSame(['usv_one_canary', 'usv_one_published', 'usva_one_canary', 'usva_one_rest'], $this->studioPartialIndexes());
+        $this->assertSame(['uspb_one_active', 'ussv_one_canary', 'ussv_one_published', 'usv_one_canary', 'usv_one_published', 'usva_one_canary', 'usva_one_rest'], $this->studioPartialIndexes());
         $this->assertSame($libro, $this->sqliteObjectsOf(self::AUDIT), 'el libro cambió en SQLite');
         if ($this->isPostgres()) {
             $this->assertSame(['draft', 'version', 'rule', 'system'], $this->checkValues('usae_subject_type_chk', self::AUDIT));
@@ -841,7 +844,8 @@
         $this->migration(self::CREATE)->up();
         $this->migration(self::GUARDS)->up();
 
-        $this->assertH6SchemaPresent();
+        // El up() del H6 deja sus 7 sujetos: los 4 del H9 vuelven con la migración del H9.
+        $this->assertH6SchemaPresent(self::SEVEN_SUBJECTS);
         $this->assertSame($libro, $this->sqliteObjectsOf(self::AUDIT), 'el libro cambió en SQLite');
         $this->assertSame($this->isPostgres() ? self::PG_TRIGGERS_H6 : self::SQLITE_TRIGGERS_H6, $this->studioTriggerCount());
         if ($this->isPostgres()) {
@@ -895,20 +899,20 @@
         }
     }
 
-    /** Tablas, disparadores, parciales y (en PG) CHECK y 7 sujetos: nada se deshizo. */
-    private function assertH6SchemaPresent(): void
+    /** Tablas, disparadores, parciales y (en PG) CHECK y los sujetos del libro: nada se deshizo. @param  list<string>  $sujetos */
+    private function assertH6SchemaPresent(array $sujetos = self::ELEVEN_SUBJECTS): void
     {
         foreach (self::PG_TRIGGERS as $tabla => $nombres) {
             $this->assertTrue(Schema::hasTable($tabla), $tabla);
             $this->assertSame($this->isPostgres() ? $nombres : array_values(array_filter($nombres, static fn (string $n): bool => ! str_ends_with($n, '_truncate'))),
                 $this->triggersOn($tabla), $tabla);
         }
-        $this->assertSame(['uscl_one_active', 'uscn_one_live', 'usv_one_canary', 'usv_one_published', 'usva_one_canary', 'usva_one_rest'], $this->studioPartialIndexes());
+        $this->assertSame(['uscl_one_active', 'uscn_one_live', 'uspb_one_active', 'ussv_one_canary', 'ussv_one_published', 'usv_one_canary', 'usv_one_published', 'usva_one_canary', 'usva_one_rest'], $this->studioPartialIndexes());
         if ($this->isPostgres()) {
             foreach (self::PG_CHECKS as $tabla => $nombres) {
                 $this->assertSame($nombres, $this->checksOn($tabla), $tabla);
             }
-            $this->assertSame(self::SEVEN_SUBJECTS, $this->checkValues('usae_subject_type_chk', self::AUDIT));
+            $this->assertSame($sujetos, $this->checkValues('usae_subject_type_chk', self::AUDIT));
         }
     }
 
--- a/backend/tests/Feature/UltronStudio/UltronStudioHardeningH7Test.php
+++ b/backend/tests/Feature/UltronStudio/UltronStudioHardeningH7Test.php
@@ -70,17 +70,17 @@
     private const SEQ_MESSAGE = 'ultron_studio: seq empieza en 1 (INSERT en ultron_studio_publications)';
 
     /** Recuentos de todo el Studio (tablas `ultron_studio_%`). */
-    private const PG_TRIGGERS_H6 = 67;   // con el H8 (+24)
+    private const PG_TRIGGERS_H6 = 122;  // con el H8 (+24) y el H9 (+55)
 
-    private const PG_TRIGGERS_H7 = 68;   // + uspub_chain_next
+    private const PG_TRIGGERS_H7 = 123;  // + uspub_chain_next
 
-    private const PG_CHECKS_H6 = 83;     // con el H8 (+34)
+    private const PG_CHECKS_H6 = 136;    // con el H8 (+34) y el H9 (+53)
 
-    private const PG_CHECKS_H7 = 84;     // + uspub_seq_chk
+    private const PG_CHECKS_H7 = 137;    // + uspub_seq_chk
 
-    private const SQLITE_TRIGGERS_H6 = 53; // con el H8 (+23)
+    private const SQLITE_TRIGGERS_H6 = 106; // con el H8 (+23) y el H9 (+53)
 
-    private const SQLITE_TRIGGERS_H7 = 55; // + uspub_chain_next y uspub_seq_min
+    private const SQLITE_TRIGGERS_H7 = 108; // + uspub_chain_next y uspub_seq_min
 
     /** md5 de `pg_get_functiondef` de la función de F: el mismo desde el H5. */
     private const FORBID_MD5 = '4ad748b5f17f09b398f7f641c197d7ee';
@@ -109,8 +109,8 @@
             $this->assertSame(['uspub_chain_next', 'uspub_forbid_delete', 'uspub_forbid_truncate', 'uspub_forbid_update'], $this->triggersOn(self::TABLE));
             $this->assertSame(['uspub_action_chk', 'uspub_engine_contract_chk', 'uspub_reason_chk', 'uspub_seq_chk'], $this->checksOn(self::TABLE));
             $this->assertSame('CHECK ((seq >= 1))', DB::scalar("SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = 'uspub_seq_chk'"));
-            $this->assertSame(self::PG_TRIGGERS_H7, $this->studioTriggerCount(), 'F 11 + laboratorio 16 + H5 3 + H6 13 + H7 1 + H8 24');
-            $this->assertSame(self::PG_CHECKS_H7, $this->studioCheckCount(), 'F 11 + laboratorio 13 + H5 3 + H6 22 + H7 1 + H8 34');
+            $this->assertSame(self::PG_TRIGGERS_H7, $this->studioTriggerCount(), 'F 11 + laboratorio 16 + H5 3 + H6 13 + H7 1 + H8 24 + H9 55');
+            $this->assertSame(self::PG_CHECKS_H7, $this->studioCheckCount(), 'F 11 + laboratorio 13 + H5 3 + H6 22 + H7 1 + H8 34 + H9 53');
 
             // El disparador de la cadena: BEFORE INSERT, por fila, con su función; los tres de H5 siguen con la de F.
             $def = (string) DB::scalar("SELECT pg_get_triggerdef(oid) FROM pg_trigger WHERE tgname = 'uspub_chain_next'");
@@ -133,7 +133,7 @@
         }
 
         $this->assertSame(['uspub_chain_next', 'uspub_forbid_delete', 'uspub_forbid_update', 'uspub_seq_min'], $this->triggersOn(self::TABLE));
-        $this->assertSame(self::SQLITE_TRIGGERS_H7, $this->studioTriggerCount(), 'F 7 + laboratorio 11 + H5 2 + H6 10 + H7 2 + H8 23');
+        $this->assertSame(self::SQLITE_TRIGGERS_H7, $this->studioTriggerCount(), 'F 7 + laboratorio 11 + H5 2 + H6 10 + H7 2 + H8 23 + H9 53');
     }
 
     // ── La cadena: la siguiente, encadenada ─────────────────────────────────
--- a/backend/tests/Feature/UltronStudio/UltronStudioPublicationSchemaTest.php
+++ b/backend/tests/Feature/UltronStudio/UltronStudioPublicationSchemaTest.php
@@ -174,9 +174,9 @@
     {
         if ($this->isPostgres()) {
             $this->assertSame(['uspub_chain_next', 'uspub_forbid_delete', 'uspub_forbid_truncate', 'uspub_forbid_update'], $this->triggersOn(self::TABLE));
-            $this->assertSame(68, $this->studioTriggerCount(), 'F 11 + laboratorio 16 + H5 3 + H6 13 + H7 1 + H8 24');
+            $this->assertSame(123, $this->studioTriggerCount(), 'F 11 + laboratorio 16 + H5 3 + H6 13 + H7 1 + H8 24 + H9 55');
             $this->assertSame(['uspub_action_chk', 'uspub_engine_contract_chk', 'uspub_reason_chk', 'uspub_seq_chk'], $this->checksOn(self::TABLE));
-            $this->assertSame(84, $this->studioCheckCount(), 'F 11 + laboratorio 13 + H5 3 + H6 22 + H7 1 + H8 34 (usv_status_chk y usae_subject_type_chk se sustituyen, no se suman)');
+            $this->assertSame(137, $this->studioCheckCount(), 'F 11 + laboratorio 13 + H5 3 + H6 22 + H7 1 + H8 34 + H9 53 (usv_status_chk y usae_subject_type_chk se sustituyen, no se suman)');
             // Los disparadores usan la función de F; H5 no crea otra ni una sobrecarga.
             $this->assertSame(1, (int) DB::selectOne("SELECT count(*) AS n FROM pg_proc WHERE proname = 'ultron_studio_forbid'")->n);
             $this->assertSame(3, (int) DB::selectOne(
@@ -186,7 +186,7 @@
         }
 
         $this->assertSame(['uspub_chain_next', 'uspub_forbid_delete', 'uspub_forbid_update', 'uspub_seq_min'], $this->triggersOn(self::TABLE));
-        $this->assertSame(55, $this->studioTriggerCount(), 'F 7 + laboratorio 11 + H5 2 + H6 10 + H7 2 + H8 23');
+        $this->assertSame(108, $this->studioTriggerCount(), 'F 7 + laboratorio 11 + H5 2 + H6 10 + H7 2 + H8 23 + H9 53');
     }
 
     // ── La cadena es de solo inserción ──────────────────────────────────────
@@ -363,13 +363,13 @@
         $this->assertSame([], $this->partialIndexesOnVersions());
         if ($this->isPostgres()) {
             $this->assertSame(['DRAFT', 'VALIDATED', 'REVIEW_REQUIRED', 'APPROVED', 'REJECTED'], $this->statusCheckValues());
-            $this->assertSame(64, $this->studioTriggerCount());   // con el H8 (+24)
-            $this->assertSame(80, $this->studioCheckCount());     // con el H8 (+34)
+            $this->assertSame(119, $this->studioTriggerCount());  // con el H8 (+24) y el H9 (+55)
+            $this->assertSame(133, $this->studioCheckCount());    // con el H8 (+34) y el H9 (+53)
             $this->assertRejected(fn () => $this->insertVersion('CANARY'), '23514', 'usv_status_chk');
             // La función es de F: down() no la toca.
             $this->assertSame(1, (int) DB::selectOne("SELECT count(*) AS n FROM pg_proc WHERE proname = 'ultron_studio_forbid'")->n);
         } else {
-            $this->assertSame(51, $this->studioTriggerCount());   // con el H8 (+23)
+            $this->assertSame(104, $this->studioTriggerCount());  // con el H8 (+23) y el H9 (+53)
         }
         $this->assertSame(4, DB::table('ultron_studio_versions')->count());
 
@@ -377,9 +377,9 @@
         $this->migration(self::GUARDS)->up();
 
         $this->assertH5SchemaPresent();
-        $this->assertSame($this->isPostgres() ? 67 : 53, $this->studioTriggerCount());   // con el H8
+        $this->assertSame($this->isPostgres() ? 122 : 106, $this->studioTriggerCount());   // con el H8 y el H9
         if ($this->isPostgres()) {
-            $this->assertSame(83, $this->studioCheckCount());
+            $this->assertSame(136, $this->studioCheckCount());
         }
     }
 
--- a/backend/tests/Feature/UltronStudio/UltronStudioVoiceSchemaTest.php
+++ b/backend/tests/Feature/UltronStudio/UltronStudioVoiceSchemaTest.php
@@ -202,14 +202,14 @@
     ];
 
     /** Todos los del Studio, con el H8: 44 + 24 y 50 + 34 en PG; 32 + 23 en SQLite. */
-    private const PG_STUDIO_TRIGGERS = 68;
+    private const PG_STUDIO_TRIGGERS = 123;
 
-    private const PG_STUDIO_CHECKS = 84;
+    private const PG_STUDIO_CHECKS = 137;
 
-    private const SQLITE_STUDIO_TRIGGERS = 55;
+    private const SQLITE_STUDIO_TRIGGERS = 108;
 
     /** Los parciales de todo el Studio: los 4 de H5/H6 y los 2 de los agentes. */
-    private const STUDIO_PARTIAL_INDEXES = ['uscl_one_active', 'uscn_one_live', 'usv_one_canary', 'usv_one_published', 'usva_one_canary', 'usva_one_rest'];
+    private const STUDIO_PARTIAL_INDEXES = ['uscl_one_active', 'uscn_one_live', 'uspb_one_active', 'ussv_one_canary', 'ussv_one_published', 'usv_one_canary', 'usv_one_published', 'usva_one_canary', 'usva_one_rest'];
 
     private const FORBID_MD5 = '4ad748b5f17f09b398f7f641c197d7ee';
 
@@ -315,8 +315,8 @@
             }
             $this->assertSame(24, $this->countLike('trigger', 'ultron\\_studio\\_voice\\_%'), 'perfiles 6 + agentes 7 + cadena 5 + sesiones 6');
             $this->assertSame(34, $this->countLike('check', 'ultron\\_studio\\_voice\\_%'), 'perfiles 11 + agentes 10 + cadena 8 + sesiones 5');
-            $this->assertSame(self::PG_STUDIO_TRIGGERS, $this->countLike('trigger', 'ultron\\_studio\\_%'), 'F 11 + laboratorio 16 + H5 3 + H6 13 + H7 1 + H8 24');
-            $this->assertSame(self::PG_STUDIO_CHECKS, $this->countLike('check', 'ultron\\_studio\\_%'), 'F 11 + laboratorio 13 + H5 3 + H6 22 + H7 1 + H8 34');
+            $this->assertSame(self::PG_STUDIO_TRIGGERS, $this->countLike('trigger', 'ultron\\_studio\\_%'), 'F 11 + laboratorio 16 + H5 3 + H6 13 + H7 1 + H8 24 + H9 55');
+            $this->assertSame(self::PG_STUDIO_CHECKS, $this->countLike('check', 'ultron\\_studio\\_%'), 'F 11 + laboratorio 13 + H5 3 + H6 22 + H7 1 + H8 34 + H9 53');
 
             return;
         }
@@ -327,7 +327,7 @@
             $this->assertSame($esperados, $this->triggersOn($tabla), $tabla);
         }
         $this->assertSame(23, $this->countLike('trigger', 'ultron_studio_voice_%'), 'perfiles 6 + agentes 6 + cadena 5 + sesiones 6');
-        $this->assertSame(self::SQLITE_STUDIO_TRIGGERS, $this->countLike('trigger', 'ultron_studio_%'), 'F 7 + laboratorio 11 + H5 2 + H6 10 + H7 2 + H8 23');
+        $this->assertSame(self::SQLITE_STUDIO_TRIGGERS, $this->countLike('trigger', 'ultron_studio_%'), 'F 7 + laboratorio 11 + H5 2 + H6 10 + H7 2 + H8 23 + H9 53');
     }
 
     public function test_solo_en_postgres_las_funciones_de_la_cadena_son_propias_y_la_de_f_no_cambia(): void
```

## Anexo B · El parche de la prueba de aislamiento del motor (§10.2), sin aplicar

Añade las dos migraciones de DB9 por **ruta exacta**, como REV8 m-2 hizo con las de DB8. La 100003 no nombra el Studio y no hace falta. Con el parche, la prueba deja de citar mis migraciones; **sigue en rojo por los tres ficheros de B9-facts** (`CanaryFacts`, `ContactHistoryFacts` y `SubjectTimeline`), igual que sin mis ficheros, y eso lo deciden SEC9 y el lead. `git apply --check` pasa limpio y Pint da `passed` sobre el fichero parcheado.

```diff
diff --git a/backend/tests/Feature/UltronStudio/UltronEngineIsolationTest.php b/backend/tests/Feature/UltronStudio/UltronEngineIsolationTest.php
index 6ba1c71..09a492f 100644
--- a/backend/tests/Feature/UltronStudio/UltronEngineIsolationTest.php
+++ b/backend/tests/Feature/UltronStudio/UltronEngineIsolationTest.php
@@ -99,6 +99,17 @@ class UltronEngineIsolationTest extends TestCase
         'database/migrations/2026_11_10_100002_create_voice_tables.php',
     ];
 
+    /**
+     * Hito 9 (contrato H9, DB9): la migración del gobierno de las estrategias
+     * (sus tablas `ultron_studio_*` y la ampliación del libro) y la de la
+     * ejecución `outreach_*` (sus FKs al gobierno del H9). Rutas exactas, sin
+     * comodín, como REV8 m-2.
+     */
+    private const ALLOWED_H9_MIGRATIONS = [
+        'database/migrations/2026_11_12_100001_create_ultron_studio_strategy_tables.php',
+        'database/migrations/2026_11_12_100002_create_outreach_tables.php',
+    ];
+
     /**
      * Hito 8 (Enmienda 8, E8-1; Enmienda 9, E9-1): el arranque de la aplicación
      * registra el oyente del evento `voice.lab.post_call` de la telefonía hacia
@@ -631,7 +642,8 @@ class UltronEngineIsolationTest extends TestCase
             }
         }
 
-        return false;
+        // Hito 9: las dos migraciones de DB9, por ruta exacta.
+        return in_array($relativa, self::ALLOWED_H9_MIGRATIONS, true);
     }
 
     /** Hito 8: lo que vive en una zona del motor sin ser del motor (los trabajos de la voz). */
```
