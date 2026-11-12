# Informe de database-specialist · Registrador comercial (I-1), `commercial:scan` y supresión 1581 de lo comercial

**11-11-2026 · `database-specialist`.** Árbol `_worktrees/ultron-h8/Iron-Body/backend`, rama `feat/ultron-studio-h9`. Empecé en HEAD `1ef1d72e`. El lead confirmó las partes 1 y 1b en `181075b3`, la 2 en `2fc75a1f` y la 1c en `0d4ed88a` (mis ficheros, sin cambios); encima está su `9a6e3b1c` (observador de facturas). La parte 3 y la consulta de pagos están sin confirmar, sobre `9a6e3b1c`. Yo no he hecho commit ni push. No he tocado producción, la red ni el CRM. No he tocado `config/*.php`, `routes/*`, migraciones, `composer.*` ni `bootstrap/`.
Motor: **PostgreSQL 14.24** (producción y el clúster local desechable `127.0.0.1:55432`, base `ultron_studio_db_comercial`) y **SQLite** (la batería). Laravel 12.58.0, PHP 8.3.33.
Marcas: **[C]** comprobado ejecutando · **[L]** leído en el código · **[S]** supuesto.

## Resumen para el lead

1. **Parte 1 (I-1), CERRADA.** `CommercialEventRecorder::record()` ya no puede dejar abortada la transacción de quien lo llama. Con transacción abierta, la lectura previa y el INSERT van dentro de un `SAVEPOINT` propio; cualquier fallo vuelve a él antes de llegar a la captura. Sin transacción abierta, todo igual que antes.
2. **Rojo en HEAD [C].** Por las clases reales en PG 14 (un pago aprobado dentro de `DB::transaction`): 5 de 5 escenarios pierden el pago en silencio. Son el INSERT inválido, la carrera de `dedupe_key` con dos sesiones, el `lock_timeout` en el INSERT, el `lock_timeout` en la lectura y el interbloqueo. `DB::transaction` vuelve sin excepción y el pago queda `created`. Con el cambio: verde 11/11 en PG.
3. **No uso `DB::transaction` anidado [C].** Laravel 12, ante un interbloqueo o un fallo de serialización en un nivel anidado, no hace `ROLLBACK TO SAVEPOINT`. La mutación que lo usa pierde el pago en la prueba del interbloqueo.
4. **Mutaciones [C]:** 6 de 7 mueren. Sobrevive la que quita el `RELEASE` en el éxito: es un mutante equivalente (higiene), lo explico abajo.
5. **No cambia** qué se registra ni cuándo: mismos eventos, claves, payload y logs. El despacho sigue colgado del COMMIT de quien llama (probado con la cola `database`).
6. **Regresión.** SQLite: 448 en Commercial y pagos y 1646 en Outreach, todo OK. Guardas: 138, con 1 fallo esperado (la puerta de diff). PG: `tests/Feature/Commercial` da los mismos 31 errores antiguos antes y después (no los mueve), y las pruebas de pagos dan las mismas 15 fallas antes y después.
7. **La puerta de diff lista DOS ficheros, no uno.** Uno es `CommercialEventRecorder.php` (este cambio). El otro, `MarketingAiActionCommercialObserver.php`, viene del commit `1b91dc5b` (las quejas), confirmado pero aún no en `origin/main`.
8. **Parte 1b (autorizada), CERRADA.** `ElectronicInvoiceCommercialObserver::subjectFor()` tenía el mismo patrón: una consulta dentro de la transacción de la factura cuyo fallo se tragaba. Ahora va por `CommercialEventRecorder::isolated()`, que he hecho público. Rojo antes [C]: la factura desaparece tras un `DB::transaction` que no lanza, y la siguiente sentencia da 25P02. Verde con el cambio. Los otros 8 observadores no tienen ninguna consulta propia tragada. **`ToolExecutor` queda como hallazgo, sin tocar** (§1.8; corregir antes de encender `commercial.enabled`).
9. **Coste [C]:** +0,05 ms por `record()` dentro de una transacción. Riesgo residual: más de 64 hechos insertados en una sola transacción desbordan la caché de subtransacciones de PG. No he encontrado ningún camino que lo haga.
10. **Parte 2, CERRADA.** `commercial:scan` recorre a TODOS los candidatos de cada familia, por id y en trozos de 500. `--limit` pasa a ser un techo de hechos NUEVOS por familia y pasada: lo que no cabe se registra en la siguiente, y la orden lo dice. La última entrada se pide una vez por trozo, no una vez por socio. Rojo en HEAD: 5/5 en SQLite y en PG. Verde con el cambio. 4 de 4 mutaciones mueren. **En la primera pasada tras desplegar saldrán como mucho 91 `inactivity_detected` nuevos**; la cifra exacta la da una consulta de solo lectura (§2.7) que en volumen sintético acierta (predice 91 y la pasada registra 91). Falta un índice en `attendances (member_id, …)`: lo propongo y NO lo creo (§2.5). Ficheros: `app/Console/Commands/ScanCommercialFacts.php` (M) y `tests/Feature/Commercial/ScanCommercialFactsCeilingTest.php` (nuevo).
11. **Parte 1c, CERRADA.** El sujeto se resuelve dentro del punto de guardado: `record()` acepta un cierre (y un array sigue valiendo), los 7 observadores que consultan lo usan y el recuento de asistencias va por `isolated()`. Rojas con `181075b3` y verdes con el cambio: 13 en total. Lo que pasaba de verdad, medido: por Wompi el pago quedaba **aprobado sin membresía o sin factura** (en silencio), no perdido. El diagnóstico del activador (§1c.6) es **grave y queda sin corregir**: un fallo propio deja el pago **cobrado sin servicio para siempre** (Wompi y Nequi) o **pierde la aprobación en silencio** (Nequi). Lo decide el propietario.
11b. **Parte 3, CERRADA.** `supresion-1581.sql` gana un bloque al final (+165 líneas, 0 cambiadas), por `:subject`. **Borra** los `commercial_events` de la persona y **redacta** sus `marketing_ai_actions`: la fila se queda; `reason` pasa a `[suprimido]` y la metadata conserva solo sus sellos operativos. Exige el ORDEN de security: con frenos N6 y sin «no contactar», en seco avisa y en real aborta. Prueba en PG de punta a punta (361 aserciones; 6 de 7 mutaciones mueren). A/B en seco en 4 bases: 0 líneas distintas fuera del bloque. A/B real sobre copias: solo cambian las 2 tablas y +1 fila del libro. Nada ejecutado en producción.
12. **Ficheros de las partes 1 + 1b, una sola unidad** (1b toca el mismo registrador y las mismas pruebas): `app/Services/Commercial/CommercialEventRecorder.php` (M), `app/Observers/Commercial/ElectronicInvoiceCommercialObserver.php` (M), `tests/Feature/Commercial/CommercialEventRecorderContainmentTest.php` (nuevo) y `tests/Feature/Commercial/CommercialEventRecorderPostgresTest.php` (nuevo). Vuelta atrás: revertir los dos `app/`.

> En el árbol hay dos cambios que **no son míos** y no he tocado: `docs/ultron-studio/ULTRON_STUDIO_H9_CONTRACT.md` (modificado a las 20:13, la enmienda N6 `complaint_recent`) y `docs/ultron-studio/plans/informes-motores/informe-tester-voz-abierta.md` (nuevo). Quedan fuera de mis listas de ficheros.

---

## Parte 1 · El registrador de hechos ya no puede abortar la transacción de quien lo llama (I-1)

### 1.1 Ficheros (lista exacta, para confirmar y desplegar por separado)

| Fichero | Estado |
|---|---|
| `app/Services/Commercial/CommercialEventRecorder.php` | modificado |
| `tests/Feature/Commercial/CommercialEventRecorderContainmentTest.php` | nuevo |
| `tests/Feature/Commercial/CommercialEventRecorderPostgresTest.php` | nuevo |

Es solo código: no hay migración, configuración, rutas ni dependencias. Se despliega como corrección puntual.

### 1.2 Diagnóstico

**El mecanismo [L + C].** `record()` hacía `exists()` y después `CommercialEvent::create()` dentro de la transacción de quien llama: lo llaman 9 observadores (`CommercialEventServiceProvider::OBSERVERS`; el docblock dice 8, pero son 9 con la factura). En PostgreSQL, una sentencia que falla deja la transacción en estado abortado (25P02) aunque PHP capture la excepción:
- si quien llama ejecuta otra sentencia, esa falla;
- si no ejecuta ninguna, `COMMIT` no lanza: el servidor responde ROLLBACK y lo de quien llama se pierde en silencio.

**Rojo en HEAD, por las clases reales [C].** Prueba `CommercialEventRecorderPostgresTest` en PG 14.24, con el código de HEAD. Cada caso es un `PaymentTransaction` real que se aprueba dentro de `DB::transaction`, con `PaymentTransactionCommercialObserver` como última sentencia; el estado se relee desde otra sesión:

```
FFFFF.                                                              6 / 6 (100%)
1) …::test_si_el_insert_del_hecho_falla_el_pago_queda_aprobado_de_verdad
el COMMIT se tragó el cambio de estado del pago
-'approved'
+'created'
2) …::test_la_carrera_de_dedupe_key_con_otra_sesion_no_pierde_el_pago            -'approved' +'created'
3) …::test_un_lock_timeout_en_el_insert_no_pierde_el_pago                         -'approved' +'created'
4) …::test_un_lock_timeout_en_la_lectura_previa_no_pierde_el_pago                 -'approved' +'created'
5) …::test_un_interbloqueo_dentro_del_registrador_no_pierde_el_pago               -'approved' +'created'
FAILURES!
Tests: 6, Assertions: 49, Failures: 5.
```

En ninguno de los cinco lanza `DB::transaction`. Es el caso silencioso de I-1 que security no había podido llevar a un pago real de la aplicación («qué observador de pagos deja el INSERT como última sentencia»). Respuesta: `PaymentTransactionCommercialObserver::updated` cuando el cambio de estado es lo último de la transacción, que es el patrón de la prueba.

`CommercialEventRecorderContainmentTest` en PG con HEAD: 3 rojas.
- Dos con `SQLSTATE[25P02] … current transaction is aborted` en la sentencia siguiente de quien llama.
- Una porque el hecho siguiente de la misma transacción ya no se registra.

En SQLite todo pasa con HEAD, como cabe esperar: SQLite no aborta la transacción por una sentencia que falla y no valida `uuid`.

**Por qué NO `DB::transaction()` anidado [L + C].** `ManagesTransactions::handleTransactionException` (vendor, Laravel 12.58):
- Si la excepción es «de concurrencia» (`ConcurrencyErrorDetector`: 40001, «deadlock detected», «database is locked»…) y el nivel es mayor que 1, **no hace `ROLLBACK TO SAVEPOINT`**: baja su contador y lanza `DeadlockException`. Lo hace así porque en MySQL un interbloqueo revierte la transacción entera. En PG la transacción de fuera quedaría abortada. La mutación M2, que usa `$db->transaction($work)`, lo demuestra: el pago se pierde en la prueba del interbloqueo (`-'approved' +'created'`).
- Si la vuelta al punto de guardado falla por una conexión perdida, `rollBack()` → `handleRollBackException` deja el contador de Laravel a 0. Las sentencias siguientes de quien llama se reconectarían en autocommit, fuera de su transacción. Esto lo he leído; no lo he provocado.

**`ON CONFLICT DO NOTHING` (`insertOrIgnore`): lo valoré y no lo adopto.**
- El punto de guardado ya contiene la carrera. `ON CONFLICT` no evita el punto de guardado, porque siguen haciendo falta los demás fallos: `lock_timeout`, FK, tipo, interbloqueo.
- Obligaría a dejar `CommercialEvent::create()`, con sus marcas de tiempo, casts y el modelo con su id que devuelve `record()`. Eso supone reconstruir el INSERT a mano y una lectura más para el id: es una vía directa a cambiar «lo que se registra».
- En SQLite, `INSERT OR IGNORE` también se traga NOT NULL y CHECK, así que los dos motores divergirían.
- Lo único que ganaría es una línea de ERROR menos en el log del servidor de PG en una carrera rara.

### 1.3 Qué cambió y por qué

`app/Services/Commercial/CommercialEventRecorder.php` (líneas del fichero final, con 1b):

- **`:28-42`, docblock de clase, propiedad 1.** Ahora dice «Nunca lanza, y nunca deja rota la transacción de quien llama», y explica por qué capturar no basta en PG (25P02, el COMMIT que responde ROLLBACK, I-1). Las propiedades 2 y 3 no cambian.
- **`:55`, `SAVEPOINT = 'commercial_event_record'`.** Un nombre propio, distinto de los `trans<N>` de Laravel.
- **`:119-156`, `doRecord`.** La lectura previa (`exists()`) y `CommercialEvent::create()` pasan, sin cambios, a un cierre que ejecuta `isolated()`.
  - La captura de la unicidad queda FUERA del cierre, así que la excepción sale y vuelve al punto de guardado antes de que se trague.
  - Si `isolated()` devuelve `null` (el duplicado normal), se sale con `null` como antes.
  - Los valores (`sanitize`, `now()`, `correlationId()`) se siguen calculando solo cuando no hay duplicado, igual que antes.
- **`:169-236`, `isolated()` (nuevo; público desde 1b).**
  - Si la conexión **del modelo** no tiene transacción (`transactionLevel() === 0` y `PDO::inTransaction()` falso; en pgsql, esto último es `PQtransactionStatus`), ejecuta el trabajo tal cual.
  - Si la tiene: `SAVEPOINT`, el trabajo y `RELEASE`. Ante cualquier `Throwable`: `ROLLBACK TO SAVEPOINT`, `RELEASE` y relanza.
  - Va por `getPdo()->exec()`, igual que los puntos de guardado internos de Laravel (`createSavepoint`): no toca el contador de niveles ni el gestor de `afterCommit`.
- **Sin cambios:**
  - `record()` sigue capturando todo y registrando `commercial.event.record_failed` con el mismo contenido.
  - `commercial.event.recorded` y `queueEvaluation()` corren después del `RELEASE`, así que el despacho `afterCommit` sigue colgado del COMMIT de quien llama.
  - Las claves, `normalizeKey`, `sanitize`, `isUniqueViolation` y el vocabulario no cambian.
- **No he tocado ningún observador.** No ha hecho falta.

### 1.4 Pruebas (sobre las clases reales, sin dobles del registrador)

`CommercialEventRecorderContainmentTest` corre en SQLite y en PG, dentro de la transacción de `RefreshDatabase`:

| Prueba | Qué fija |
|---|---|
| `test_dentro_de_una_transaccion_un_insert_que_falla_devuelve_null_y_la_transaccion_sigue_sana` | (a) Un INSERT que falla en los dos motores (FK de `marketing_lead_id` a un prospecto que no existe) devuelve `null`; otra sentencia funciona; lo de quien llama queda; el nivel de transacción no cambia. En PG, además, `record_failed` |
| `test_un_correlation_id_que_no_es_uuid_no_aborta_la_transaccion_de_quien_llama` | (a) La forma determinista que pediste: `Context` con un `correlation_id` que no es uuid. El log `record_failed` sale con el mismo contenido (`event`, `exception=QueryException`, mensaje). **Se omite en SQLite**: no valida `uuid` |
| `test_el_duplicado_normal_se_para_en_la_lectura_sin_intentar_el_insert_ni_registrar_errores` | (d) La segunda llamada no llega a intentar el INSERT (`DB::listen`), sin `record_failed` y con un único `recorded` |
| `test_el_hecho_va_con_la_transaccion_de_quien_llama_y_su_evaluacion_espera_al_commit` | Con la cola `database` real (`Queue::fake()` apunta el trabajo en el acto y no respeta `afterCommit`): si quien llama revierte, no hay hecho ni trabajo; si confirma, 1 trabajo, y solo después del COMMIT |
| `test_tras_un_fallo_el_siguiente_hecho_de_la_misma_transaccion_se_registra` | El punto de guardado se libera también al fallar |

`CommercialEventRecorderPostgresTest` usa COMMIT reales, sin `RefreshDatabase`, con una segunda sesión `pg_connect` (asíncrona con `pg_send_query`) y una tercera que relee lo confirmado. Las cinco primeras **se omiten en SQLite** con su motivo escrito; la (c) corre en los dos:

| Prueba | Escenario |
|---|---|
| `test_si_el_insert_del_hecho_falla_el_pago_queda_aprobado_de_verdad` | I-1 exacto: el INSERT falla (uuid) y el pago queda `approved` tras el COMMIT |
| `test_la_carrera_de_dedupe_key_con_otra_sesion_no_pierde_el_pago` | (b) **La carrera real con dos sesiones.** La otra tiene el mismo hecho sin confirmar; esta pasa `exists()`, llega al INSERT y **espera** (≥ 0,3 s medidos); la otra confirma; esta recibe 23505 y se calla. El pago queda `approved`, hay 1 hecho y no hay `record_failed` |
| `test_un_lock_timeout_en_el_insert_no_pierde_el_pago` | El INSERT espera al hecho sin confirmar de la otra sesión y cae por `lock_timeout` (55P03) |
| `test_un_lock_timeout_en_la_lectura_previa_no_pierde_el_pago` | La otra sesión tiene `LOCK TABLE commercial_events IN ACCESS EXCLUSIVE MODE` (una migración); el `exists()` cae por `lock_timeout` |
| `test_un_interbloqueo_dentro_del_registrador_no_pierde_el_pago` | Un 40P01 real. Esta sesión tiene la fila del pago y espera el hecho de la otra; la otra espera la fila del pago. `deadlock_timeout` es de 100 ms aquí frente a 10 s allí, así que esta es la víctima. El pago queda `approved` y hay 1 hecho, el de la otra |
| `test_sin_transaccion_abierta_registra_en_el_acto_y_un_fallo_no_deja_nada_roto` | (c) Nivel 0: confirmado en el acto (otra sesión lo ve), despacho inmediato, el duplicado da `null`, el fallo (uuid, solo PG) da `null` + `record_failed` y la conexión sigue sirviendo. Un `SAVEPOINT` a nivel 0 es un error en PG; lo mata la mutación M5 |

**Salida con el cambio [C]:**
```
PG  · CommercialEventRecorderPostgresTest     OK (6 tests, 65 assertions)
PG  · CommercialEventRecorderContainmentTest  OK (5 tests, 35 assertions)
SQLite · las dos clases                       OK, but some tests were skipped! Tests: 11, Assertions: 32, Skipped: 6
```

### 1.5 Mutaciones (copia fuera del árbol, con autoloader propio; cada una parte del código corregido) [C]

| Mutación | Resultado en PG |
|---|---|
| M1 · el código de HEAD | **muere**: 5/6 + 3/5 |
| M2 · `return $db->transaction($work)` (el `DB::transaction` anidado de Laravel) | **muere**: el interbloqueo pierde el pago |
| M3 · `exists()` fuera del punto de guardado | **muere**: el `lock_timeout` de la lectura pierde el pago |
| M4 · sin `ROLLBACK TO SAVEPOINT` | **muere**: 5/6 + 3/5 |
| M5 · punto de guardado también sin transacción | **muere**: la (c) |
| M6 · la unicidad capturada dentro del cierre | **muere**: la carrera pierde el pago |
| M7 · sin `RELEASE` en el éxito | **sobrevive**: mutante equivalente. Sin `RELEASE` los puntos de guardado se apilan hasta el COMMIT, pero el comportamiento visible es el mismo (`ROLLBACK TO` va al más reciente con ese nombre). Lo mantengo por higiene |

### 1.6 Regresión [C]

- **SQLite**, en 40 s: `tests/Feature/Commercial`, `tests/Feature/Wompi`, `tests/Feature/Payments` (incluye `PlanUpgrade*`, que arman los observadores), `tests/Feature/Cycle` (`CommercialCycleTestCase` los arma), `tests/Feature/Subscriptions`, `Chaos/ChaosCommercialTest`, `Chaos/ChaosPaymentsTest`, `Marketing/Torture/PaymentFactRegressionTest`, `Marketing/UltronPaymentApprovedTest` y `UltronStudio/Strategies/UltronStudioStrategyComplaintSimulationTest`. Resultado: `Tests: 448, Assertions: 2074, PHPUnit Deprecations: 1, Skipped: 6`. Las 6 omitidas son las mías de solo PG.
- **SQLite:** `tests/Unit/Outreach` + `tests/Feature/Outreach`. Resultado: `Tests: 1646, Assertions: 93615, Skipped: 164`, todo OK. No he marcado como omitida ninguna prueba existente.
- **Las 15 guardas:** `Tests: 138, Assertions: 178695, Failures: 1`, la puerta de diff. Lista **dos** ficheros:
  - `backend/app/Services/Commercial/CommercialEventRecorder.php`: este cambio (`git diff --name-only HEAD`);
  - `backend/app/Observers/Commercial/MarketingAiActionCommercialObserver.php`: ya está en `git diff --name-only origin/main HEAD` por el commit `1b91dc5b` (quejas), que no ha llegado a `origin/main`. No es de este cambio.
- **PG, `tests/Feature/Commercial` sin mis dos ficheros**, en HEAD y con el cambio: `Tests: 166, Assertions: 351, Errors: 31` en los dos, con **las mismas 31 pruebas** (`diff` vacío). **El cambio no los mueve.** Salen de los INSERT en `commercial_tool_invocations`, no del registrador:
  - 29 son `22P02` (uuid de las pruebas);
  - 2 son `25P02`, en `ToolExecutorTest::test_a_rejection_is_audited` y `…does_not_burn_the_idempotency_key`. Ver 1.8.
- **PG, pruebas de pagos:** Cycle, Payments, Wompi, Subscriptions, los dos Chaos, `PaymentFactRegressionTest`, `UltronPaymentApprovedTest` y la simulación de quejas. **Las mismas 15 fallas en HEAD y con el cambio** (`diff` vacío): 6 `ChaosCommercialTest` + 1 `CycleMemoryAuditTest` por `22P02` (uuid de las pruebas), y 1 `ChaosPaymentsTest`, 2 `RecurringBillingTest`, 4 `WompiFailureInjectionTest` y 1 `WompiPaymentFlowTest` por `25P02`. Estas son pruebas de inyección de fallos pensadas para SQLite, con los observadores comerciales desarmados. Preexistentes.
- `vendor/bin/pint --test` sobre los tres ficheros: `passed`.

### 1.7 Impacto en producción

- **Lo que mejora:** ningún fallo de la base dentro de `record()` deja abortada la transacción de un pago, una membresía, una asistencia, una factura, una cita, una conversación o un prospecto. En ninguno de esos casos un COMMIT puede perder ya lo de quien escribe.
- **Lo que no cambia:** qué hechos se registran y con qué clave, payload, `occurred_at` y `correlation_id`; los dos logs; el despacho después del COMMIT; el comportamiento sin transacción (`commercial:scan`, el registro de quejas después del commit).
- **Coste [C]**, sonda de 500 `record()` dentro de una transacción en el PG local, la mejor de 3:

  | | HEAD | Con el cambio |
  |---|---|---|
  | Hecho nuevo | 0,423 ms/llamada | 0,479 ms/llamada |
  | Duplicado | 0,131 ms/llamada | 0,175 ms/llamada |

  Son dos idas y vueltas más (`SAVEPOINT` y `RELEASE`). Sin transacción, coste cero.
- **Riesgo residual (PG):** cada hecho **insertado** dentro de una transacción es una subtransacción con su XID. Más de 64 en una misma transacción desbordan la caché de subxids del proceso, y mientras dure esa transacción las instantáneas de las demás sesiones consultan `pg_subtrans`, que es más lento. Los duplicados no cuentan, porque no escriben. No he encontrado ningún camino que inserte más de 64 hechos en una transacción [L]:
  - `PlanTransfers` solo cambia `plan`, no `membership_end_date`;
  - `MembershipAdjustments` toca un socio;
  - `commercial:scan` no abre transacción.

  No lo he buscado de forma exhaustiva, así que tómalo como supuesto [S].
- **Lo que se pierde (como antes, ahora sin daño colateral):** si el INSERT falla por algo que no es la unicidad, el hecho no se registra y queda `commercial.event.record_failed` en el log.

### 1.8 Quién más escribe en `commercial_events` o llama al registrador

| Quién | Dentro de la transacción de otro | ¿Protegido? |
|---|---|---|
| `CommercialEventRecorder::record` (INSERT) | sí | **sí** (este cambio) |
| 8 observadores que llaman a `record()` dentro de la transacción (Payment, Membership/User, Member, MarketingLead, Appointment, Conversation, Attendance, ElectronicInvoice) | sí | el registro sí. **Ojo con sus consultas previas**, fuera de `record()`: `CommercialSubjectResolver` (`find`, `value`, `Schema::hasTable`) y `AttendanceCommercialObserver::entriesFor` (`count`). No se tragan: si fallan, la excepción sube y quien escribe falla **en voz alta**, no en silencio. Es preexistente y contradice el «best-effort» de esos docblocks, pero no pierde datos |
| `ElectronicInvoiceCommercialObserver::subjectFor` (`:107-111`) | sí | **NO. Hallazgo adyacente.** `try { $class::query()->find($invoice->source_id) } catch (\Throwable) { return $empty; }` se traga un fallo de consulta dentro de la transacción de la factura: es el mismo patrón que I-1, y en PG el COMMIT de la factura respondería ROLLBACK. Probabilidad baja (`source_type` sin tabla, `source_id` de otro tipo). Corrección propuesta: un punto de guardado alrededor, o dejar que suba. **No lo toco:** es un observador y no me lo has autorizado |
| `MarketingAiActionCommercialObserver` | no (`DB::afterCommit`) | no lo necesita; ahora además estaría protegido |
| `commercial:scan` (`ScanCommercialFacts`) | no (sin transacción) | igual que antes |
| `EvaluateCommercialSubject` (UPDATE `evaluated_at`) y `commercial:evaluate-pending` (UPDATE de los viejos) | no (job y comando propios) | no se tragan errores; detrás de `commercial.enabled`, apagado en producción |
| El borrado de `marketing_leads` (FK `cascadeOnDelete`) | — | borra los hechos del prospecto |
| `ToolExecutor` (`commercial_tool_invocations`, no `commercial_events`) | depende de quien llame | **Mismo patrón, adyacente.** `auditRejection` (`:299-329`, la captura en `:323`) se traga cualquier `\Throwable` de un INSERT; `runAudited` (`:171-178`), tras un 23505, hace `resultOfExisting()`, otra consulta. En PG, dentro de una transacción, 25P02: lo enseñan las 2 pruebas de arriba. Detrás de `commercial.enabled` (NBA apagado). No lo toco (está bajo la puerta de diff y fuera del encargo) |

### 1.9 Vuelta atrás

Revertir `app/Services/Commercial/CommercialEventRecorder.php` a `1ef1d72e`. No hay esquema ni datos que deshacer: el punto de guardado vive dentro de cada transacción y desaparece con ella. Las dos pruebas nuevas, con el código anterior, quedan en rojo en PG (es lo que demuestran).

### 1.10 Lo que NO he verificado

- **Producción, nada** (ni frecuencia real de `record_failed` ni volumen por transacción).
- **El camino de la conexión perdida** durante `ROLLBACK TO SAVEPOINT`: solo leído en el código de Laravel; no lo he provocado.
- **La conexión que no es la de por defecto:** `CommercialEvent` no declara `$connection`, así que hoy es la de por defecto. El código usa la del modelo (`(new CommercialEvent)->getConnection()`, la misma que usan `query()` y `create()`). Sin prueba con otra conexión.
- **Si algún camino masivo inserta más de 64 hechos en una transacción** (1.7): búsqueda no exhaustiva.
- **PHP 8.4 con SQLite:** ahí Laravel abre con `exec('BEGIN')` y `PDO::inTransaction()` no lo ve. Por eso la condición mira también `transactionLevel()`. Producción es PG, y en PG `inTransaction()` lee el estado real de libpq.

---

## Parte 1b · La consulta propia del observador de facturas (autorizada por el lead)

### 1b.1 Ficheros (lista exacta)

Las partes 1 y 1b **se confirman juntas**: 1b cambia el mismo registrador (hace público `isolated()`) y añade pruebas a los mismos dos ficheros. Lista combinada de 1 + 1b:

| Fichero | Estado | De qué parte |
|---|---|---|
| `app/Services/Commercial/CommercialEventRecorder.php` | modificado | 1 y 1b |
| `app/Observers/Commercial/ElectronicInvoiceCommercialObserver.php` | modificado | 1b |
| `tests/Feature/Commercial/CommercialEventRecorderContainmentTest.php` | nuevo | 1 y 1b |
| `tests/Feature/Commercial/CommercialEventRecorderPostgresTest.php` | nuevo | 1 y 1b |

La puerta de diff del H9 listará los dos ficheros de `app/` (autorizado), además de `MarketingAiActionCommercialObserver.php`, que viene del commit `1b91dc5b`.

### 1b.2 Diagnóstico [L + C]

- `subjectFor()` (`:107-111` en HEAD) hacía `try { $class::query()->find($invoice->source_id) } catch (\Throwable) { return $empty; }`. Corre en `created`/`updated` de `ElectronicInvoice`, dentro de la transacción de quien guarda la factura (`InvoicingService` con `lockForUpdate`, `ManualInvoiceBundleService`).
- En producción `source_type` es el nombre de la clase (`$source->getMorphClass()` sin mapa de morph: `App\Models\Payment`, `App\Models\ProductSale`, el lote). Así que la consulta corre de verdad cuando `events_enabled` está encendido.
- **Rojo con el observador de HEAD**, con el registrador ya corregido para aislar la causa. Es PG 14 con COMMIT reales: otra sesión tiene `payments` con ACCESS EXCLUSIVE (una migración sobre pagos durante un despliegue) y `lock_timeout = 300ms`:

```
1) …CommercialEventRecorderPostgresTest::test_tras_el_fallo_de_la_consulta_del_observador_la_transaccion_de_la_factura_sigue_sana
Illuminate\Database\QueryException: SQLSTATE[25P02]: In failed sql transaction: 7 ERROR:  current transaction is aborted, commands ignored until end of transaction block
1) …CommercialEventRecorderPostgresTest::test_una_factura_cuyo_origen_hace_fallar_la_consulta_del_observador_queda_confirmada
el COMMIT se tragó la factura
Failed asserting that null is identical to 'pending'.
Tests: 2, Assertions: 9, Errors: 1, Failures: 1.

1) …CommercialEventRecorderContainmentTest::test_una_factura_cuyo_origen_hace_fallar_la_consulta_del_observador_se_guarda_y_la_transaccion_sigue_sana
Illuminate\Database\QueryException: SQLSTATE[25P02]: In failed sql transaction: …
Tests: 1, Assertions: 0, Errors: 1.
```

  Es el caso silencioso: `DB::transaction(fn () => ElectronicInvoice::create(...))` no lanza y **la factura no existe** después del COMMIT.

### 1b.3 Qué cambió

- **`app/Services/Commercial/CommercialEventRecorder.php:169-236`.** `isolated()` pasa de privado a **público**: `isolated(\Closure $work, ?Connection $db = null): mixed`, con plantilla de tipos.
  - Si no se le pasa conexión, usa la de `CommercialEvent`, como en la parte 1. `record()` lo llama igual que antes.
  - No captura nada: devuelve lo que devuelve el trabajo, o relanza lo que lanza ya con la transacción de quien llama devuelta a su punto de guardado.
  - El docblock dice para quién es público.
- **`app/Observers/Commercial/ElectronicInvoiceCommercialObserver.php:107-116`.** La consulta pasa por `$this->recorder->isolated(fn () => $query->find(...), $query->getConnection())`. Lo hace en la conexión **de la consulta** (la del modelo de origen), que es donde tiene que estar el punto de guardado. El `catch (\Throwable) { return $empty; }` se queda: se sigue tragando, pero ya sin dejar la transacción rota. Si `$class` no es un modelo, `query()` lanza antes de tocar la base, como antes.
- No cambia qué se registra ni cuándo: si la consulta falla, el sujeto queda vacío y no se registra nada, como antes.

### 1b.4 Pruebas [C]

| Prueba | Motor | Escenario |
|---|---|---|
| `CommercialEventRecorderPostgresTest::test_una_factura_cuyo_origen_hace_fallar_la_consulta_del_observador_queda_confirmada` | PG (en SQLite, omitida con su motivo) | Origen `App\Models\Payment`, `payments` bloqueada por otra sesión y `lock_timeout`; la factura es lo último de la transacción. Queda confirmada (releída desde otra sesión) |
| `…::test_tras_el_fallo_de_la_consulta_del_observador_la_transaccion_de_la_factura_sigue_sana` | PG (en SQLite, omitida) | Lo mismo, con una sentencia más de quien guarda la factura, que funciona y queda confirmada |
| `CommercialEventRecorderContainmentTest::test_una_factura_cuyo_origen_hace_fallar_la_consulta_del_observador_se_guarda_y_la_transaccion_sigue_sana` | SQLite y PG | El origen es un modelo cuya tabla no existe (falla en los dos motores). La factura se guarda, otra sentencia funciona y no se registra ningún hecho |

Con el cambio [C]:
- PG `CommercialEventRecorderPostgresTest`: `OK (8 tests, 78 assertions)`.
- PG `CommercialEventRecorderContainmentTest`: `OK (6 tests, 38 assertions)`.
- SQLite, las dos clases: `Tests: 14, Assertions: 35, Skipped: 8`, las 8 de solo PG con su motivo.

Mutación [C]: el observador de HEAD da las 3 en rojo (arriba). Sin probar: pasar la conexión equivocada a `isolated()`. Hoy todos los modelos usan la conexión por defecto, así que esa mutación sobreviviría; el código usa la de la consulta.

Regresión en SQLite [C]: `tests/Feature/Billing` + `tests/Feature/Commercial` dan `Tests: 563, Failures: 1, Skipped: 8`. La única falla es `Billing/PricingV2SalesAndPaymentsTest::test_manual_payment_uses_backend_quote` (422, «El plan «Mensual» cuesta 80.000 y el cobro es de 95.200»), y **falla igual con el registrador y el observador de HEAD**: es preexistente y no tiene relación.

### 1b.5 Los otros 8 observadores [L]

`grep -n "catch" app/Observers/Commercial/*.php` da tres sitios en total:

| Observador | Consultas propias | ¿Alguna tragada? |
|---|---|---|
| `PaymentTransactionCommercialObserver` | `CommercialSubjectResolver::fromMember` | no |
| `MembershipCommercialObserver` (`User`) | `fromUser` (`Schema::hasTable`, `Member::where()->value`) | no. Su `catch` (`:86`) envuelve `Carbon::parse` y no toca la base |
| `MemberCommercialObserver` | `fromMember` | no |
| `MarketingLeadCommercialObserver` | ninguna fuera del registrador | no |
| `MarketingAppointmentCommercialObserver` | resolución del sujeto | no |
| `MarketingConversationCommercialObserver` | resolución del sujeto | no |
| `MarketingAiActionCommercialObserver` | `fromLead`, **después del commit** (`DB::afterCommit`) | su `catch` (`:85`) corre fuera de la transacción de quien escribe; no aplica |
| `AttendanceCommercialObserver` | `entriesFor()` (`count`) y `fromMember` | no |

Las consultas que no se tragan, si fallan, hacen fallar **en voz alta** a quien escribe: es preexistente, no pierde datos y contradice el «best-effort» de sus docblocks. No he cambiado ninguna.

### 1b.6 `ToolExecutor`: hallazgo, sin tocar (por indicación del lead)

**Corregir antes de encender `commercial.enabled`**, hoy apagado en producción:
- `app/Services/Commercial/Tools/ToolExecutor.php:299-329` (`auditRejection`, la captura en `:323`) se traga cualquier `\Throwable` del INSERT de auditoría.
- `:171-178` (`runAudited`): tras un 23505, `resultOfExisting()` hace otra consulta en la misma transacción.

En PostgreSQL, dentro de una transacción, las dos dejan la transacción abortada. Evidencia [C]: en PG, `tests/Feature/Commercial/ToolExecutorTest::test_a_rejection_is_audited` y `::test_a_rejection_does_not_burn_the_idempotency_key` dan `SQLSTATE[25P02]`, en HEAD y con este cambio.

Corrección propuesta: el mismo `CommercialEventRecorder::isolated()` alrededor de los dos INSERT, y la consulta de `resultOfExisting()` fuera del punto de guardado ya revertido.

### 1b.7 Vuelta atrás

Revertir los dos ficheros de `app/` a `1ef1d72e`. Sin esquema ni datos.

---

## Parte 2 · `commercial:scan` mira a todos sus candidatos

### 2.1 Ficheros (lista exacta)

| Fichero | Estado |
|---|---|
| `app/Console/Commands/ScanCommercialFacts.php` | modificado |
| `tests/Feature/Commercial/ScanCommercialFactsCeilingTest.php` | nuevo |

Es solo código, independiente de las partes 1 y 1b: no toca el registrador. No está bajo la puerta de diff del H9. Las pruebas protegidas siguen igual: no he tocado ni `ScanCommercialFactsBogotaDayTest`, ni `H9*BogotaDay*Test`, ni `H9CrmEventTriggersEndToEndTest`.

### 2.2 Diagnóstico

**[L]** Cada familia hacía `->limit($limit)` (500) sin `ORDER BY` y sin excluir lo ya registrado. El techo cortaba la lista de SUJETOS antes de mirarlos:
- En `scanInactivity()` se aplicaba a los socios vigentes antes de mirar su última entrada, y además había un `max(captured_at)` por socio.
- Con 591 vigentes (lo has medido hoy en producción), PostgreSQL devuelve siempre los mismos 500 (los que dé el plan). Los otros 91 no tienen nunca `inactivity_detected`.
- La ventana de renovación (195), las vencidas y los enlaces tenían el mismo defecto de forma.

**Rojo en HEAD [C]**, prueba nueva con el escáner de HEAD:

```
SQLite y PG (idéntico):
1) …::test_con_mas_socios_vigentes_que_el_techo_los_inactivos_del_final_tienen_su_hecho
-Array &0 [ 'inactivity_detected:inactive:6:2026-W47', …:7:…, …:8:… ]
+Array &0 []
2) …::test_con_mas_inactivos_que_el_techo_todos_acaban_con_su_hecho_y_ninguno_dos_veces
3) …::test_las_otras_tres_familias_tambien_llegan_a_todos_por_encima_del_techo
-    3 => 'renewal_window_opened:renewal:10:2026-11-25',
4) …::test_la_inactividad_consulta_las_asistencias_una_vez_por_trozo_y_no_una_por_socio
Failed asserting that actual size 6 matches expected size 1.
5) …::test_en_seco_el_techo_tambien_corta_y_no_registra_nada
Tests: 5, Assertions: 15, Failures: 5.
```

**En volumen [C]**, en PG local con datos **sintéticos**. Los tamaños son supuestos, no de producción [S]: 8.000 usuarios y socios, 591 vigentes (195 en la ventana, 150 inactivos con ids 442-591), 622.830 asistencias y 30.000 pagos. La pasada de HEAD encuentra **59** inactivos; la nueva, **150**. HEAD miraba 500 de 591, como en producción.

### 2.3 Qué cambió (`app/Console/Commands/ScanCommercialFacts.php`)

- **`:41-47`, docblock de clase:** explica el cambio de criterio.
- **`:52`, `--limit`:** el texto dice ahora «techo de hechos NUEVOS por familia y pasada». El valor por defecto sigue siendo 500.
- **`:58`, `CHUNK = 500`:** el tamaño del trozo, independiente del techo.
- **`:61`, `:82`, `:95-103`, recortes:**
  - `$truncated` se reinicia en cada `handle()`: la instancia se reutiliza entre llamadas del mismo proceso, y lo cazó la prueba.
  - Si alguna familia recorta, la orden escribe `Techo de N hechos nuevos alcanzado en: …. Quedan candidatos sin mirar; la siguiente pasada sigue con ellos (lo ya registrado no cuenta para el techo).`
  - Registra además `commercial.scan.truncated` (warning) con `families`, `limit` y `dry_run`.
  - Las líneas por familia y `commercial.scan.completed` no cambian.
- **`:117-146`, `:155-174`, `:247-274` (renovación, vencidas, enlaces):** la misma consulta, con `select([...])` en lugar de `get([...])` y sin `limit`, recorrida con `walk()`. El evento, el payload y la clave son idénticos.
- **`:184-238`, inactividad:**
  - Los socios vigentes se recorren por id.
  - Por cada trozo, **una** consulta: `select member_id, max(captured_at) as last_entry from attendances where member_id in (…) and action = 'entry' group by member_id` (`:214-219`).
  - Los criterios siguen igual: sin visitas, no es inactividad; y se compara `Carbon::parse($lastEntry)->greaterThan($cutoff)` en PHP, como antes. Así no cambia ningún borde de Bogotá.
- **`:276-319`, `walk()`:**
  - `chunkById(500)` en orden de id ascendente.
  - El techo se mira **antes de cada emisión** y cuenta lo que `emit()` registra de verdad (1); lo ya registrado devuelve 0.
  - Si para con candidatos sin mirar, marca la familia como recortada.

**Por qué nadie se queda atrás para siempre.** La clave de deduplicación lleva el hecho y su fecha, y el registrador para los repetidos en su lectura previa. Así que la pasada siguiente atraviesa lo que ya emitió la anterior **sin gastar techo** y llega a los que no cupieron. Con un conjunto estable de C candidatos, todos tienen su hecho en ⌈C / limit⌉ pasadas. Solo podría quedarse alguien atrás si entraran más de `limit` (500) candidatos nuevos por familia **cada hora**. En ese caso la orden lo avisa en cada pasada (consola y `commercial.scan.truncated`).

Las ventanas de cada clave dejan margen de sobra:

| Familia | Ventana de la clave | Pasadas para emitir (cada hora) |
|---|---|---|
| Inactividad | semanal | 168 |
| Renovación | por fecha de fin, unos 10 días | unas 240 |
| Vencidas | diaria | 24 |
| Enlaces | diaria | 24 |

**Lo que no he cambiado.** Por cada candidato que llega a `emit()`, la resolución del sujeto (`CommercialSubjectResolver`: `Schema::hasTable` + una búsqueda) y la lectura previa del registrador siguen siendo consultas una a una, como antes. Están acotadas por el número de candidatos, no por la tabla. Quitarlas exigiría construir la clave fuera del registrador (que hoy la normaliza en privado) o resolver sujetos por lotes. Eso duplicaría lógica y tocaría ficheros de las partes 1 y 1b; queda como mejora posible.

### 2.4 Planes de cada consulta (`EXPLAIN (ANALYZE, BUFFERS)` en el PG 14.24 local, volumen sintético) [C]

| Consulta (nueva) | Plan e índice | Tiempo |
|---|---|---|
| Renovación, primer trozo | `Bitmap Index Scan on users_membership_end_date_h9_idx` → Sort por id, 195 filas | 0,39 ms |
| Vencidas | `Bitmap Index Scan on users_membership_end_date_h9_idx`. `membership_end_date` es `date`, así que el `::date` de `whereDate` no impide el índice | 0,06 ms |
| Socios vigentes, trozos 1 y 2 | `Hash Join`: `Seq Scan on members` (8.000) con `Bitmap Index Scan on users_membership_end_date_h9_idx` (591) | 4,5 ms / 2,7 ms |
| **Última entrada por trozo (500 socios)** | **`Parallel Seq Scan on attendances`**: no hay índice por `member_id` | 108 ms en frío / 18-20 ms en caliente |
| Enlaces abandonados | `Index Scan using payment_transactions_created_at_index` (filtra estado y enlace) | 0,07 ms |
| Lectura previa del registrador | `Index Only Scan using commercial_events_dedupe_key_unique` | 0,2 ms |
| HEAD: `max(captured_at)` de UN socio (se repetía 500 veces) | `Index Scan Backward using attendances_captured_at_index` + Filter `member_id`: 0,15 ms si viene (666 filas descartadas) y 1,8 ms si es inactivo (9.697 descartadas). Crece con el tiempo que lleva sin venir | 500 × ≈ 105 ms en total |

Pasada completa sobre la misma base, con la sonda de la orden real [C]:

| | Sentencias | Tiempo en la base |
|---|---|---|
| HEAD, pasada en régimen (sin hechos nuevos) | 1.886 | 283 ms |
| Nueva, pasada en régimen | 1.662 | 296 ms |
| Nueva, primera pasada tras desplegar | 1.753 | 275 ms, con 91 hechos nuevos |

Coste parecido: los 500 `max()` por socio se convierten en 2 consultas por trozo, pero cada una hace un recorrido secuencial de `attendances`. Escala con el tamaño de la tabla, no con el número de socios.

### 2.5 Índice que falta (NO creado: sería una migración)

`attendances` solo tiene índices por `(user_id, captured_at)`, `captured_at` y `(access_via, captured_at)`; ninguno empieza por `member_id`. Propuesta:

```sql
-- Migración (con el rol dueño), fuera de transacción, sin bloquear escrituras:
CREATE INDEX CONCURRENTLY attendances_member_entry_captured_idx
    ON attendances (member_id, captured_at) WHERE action = 'entry';
-- Vuelta atrás:
DROP INDEX CONCURRENTLY attendances_member_entry_captured_idx;
```

Medido en una copia local de la base sintética [C]:

| Consulta | Sin índice | Con índice |
|---|---|---|
| Última entrada por trozo | `Parallel Seq Scan`, 20 ms | `Index Only Scan`, 4,9 ms |
| `AttendanceCommercialObserver::entriesFor` (`count(distinct date(captured_at)) … where member_id = ? and action = 'entry'`) | `Parallel Seq Scan`, 13 ms | `Index Only Scan`, 0,08 ms |

**Hallazgo adyacente, vivo desde que se encendió `events_enabled`:** `entriesFor` corre **en cada entrada al gimnasio**, dentro de la transacción de la asistencia, y hoy es un recorrido secuencial de toda la tabla. El índice lo arregla también.

Coste del índice: 12 MB frente a los 65 MB de la tabla (415 mil entradas sintéticas), más una entrada de índice por cada `entry` insertada. Con `CONCURRENTLY` no bloquea escrituras, pero no puede ir dentro de la transacción de la migración. Conviene comprobar antes cómo lo resuelven las migraciones del repo, y su duración con el tamaño real.

### 2.6 Pruebas [C]

`ScanCommercialFactsCeilingTest`, con la base en blanco de `RefreshDatabase`; corre en SQLite y en PG:

| Prueba | Qué fija |
|---|---|
| `test_con_mas_socios_vigentes_que_el_techo_los_inactivos_del_final_tienen_su_hecho` | **El caso de producción.** 8 vigentes, techo 5, los 3 inactivos con los ids más altos: tienen su hecho y no se avisa de recorte |
| `test_con_mas_inactivos_que_el_techo_todos_acaban_con_su_hecho_y_ninguno_dos_veces` | 7 inactivos con techo 3 → 3 + 3 + 1 + 0. Los siete, en orden de id, una vez cada uno. Aviso en consola y en log solo en las dos pasadas que recortan |
| `test_las_otras_tres_familias_tambien_llegan_a_todos_por_encima_del_techo` | Renovación, vencidas y enlaces: 4 candidatos cada una con techo 3. Todos con su clave exacta, y las tres avisan en la primera pasada |
| `test_la_inactividad_consulta_las_asistencias_una_vez_por_trozo_y_no_una_por_socio` | 6 vigentes → 1 sola consulta a `attendances` (`DB::listen`) |
| `test_en_seco_el_techo_tambien_corta_y_no_registra_nada` | `--dry-run` con techo 2: avisa y no escribe |

Salidas [C]:
- Con el cambio: `OK (5 tests, 28 assertions)` en SQLite y en PG.
- Pruebas protegidas, sin tocar: SQLite `OK (12 tests, 168 assertions)`. PG: `ScanCommercialFactsBogotaDayTest` OK 6/43, `H9MembershipExpiredBogotaDayTest` OK 1/28, `H9RenewalWindowBogotaDayTest` OK 1/19, `H9CrmEventTriggersEndToEndTest` OK 4/78.

Mutaciones en la copia fuera del árbol [C], todas mueren:

| Mutación | La mata |
|---|---|
| Escáner de HEAD | 5/5 |
| El techo cuenta lo mirado, no lo emitido | 2 pruebas |
| `max()` por socio | la prueba de consultas |
| Sin reinicio de `$truncated` | la de «ninguno dos veces» |
| Techo comprobado por trozo y no por candidato | la de «ninguno dos veces» |

**Regresión final de las partes 1, 1b y 2 [C]:**
- **SQLite**, `tests/Feature/Commercial` + `Billing` + `Wompi` + `Payments` + `Cycle` + `Subscriptions` + los dos Chaos + `PaymentFactRegressionTest` + `UltronPaymentApprovedTest` + la simulación de quejas: `Tests: 839, Failures: 1, Skipped: 8`. La falla es `PricingV2SalesAndPaymentsTest::test_manual_payment_uses_backend_quote`, preexistente: falla igual con HEAD (§1b.4). Las 8 omitidas son las de solo PG.
- **SQLite**, `tests/Unit/Outreach` + `tests/Feature/Outreach`: `Tests: 1646, Assertions: 93615, Skipped: 164`, todo OK.
- **Guardas (15):** `Tests: 138, Failures: 1`. Es la puerta de diff y lista exactamente `ElectronicInvoiceCommercialObserver.php` (1b), `MarketingAiActionCommercialObserver.php` (commit `1b91dc5b`) y `CommercialEventRecorder.php` (1).
- **PG**, `tests/Feature/Commercial` completo: `Tests: 185, Errors: 31`, **las mismas 31 pruebas que en HEAD** (`diff` de nombres vacío) y ninguna nueva.
- **PG**, `tests/Feature/Outreach` + `tests/Unit/Outreach`: `Tests: 1646, Assertions: 94727, Skipped: 29`, todo OK.

### 2.7 Impacto en producción: hechos nuevos en la primera pasada

**Lo que se ve en Supervisión.**
- **Inactividad.** La primera pasada tras desplegar registra un `inactivity_detected` (clave de la semana ISO en curso, hoy `2026-W46`) por cada socio vigente que lleve 14 días o más sin venir y no tenga ya el de esta semana. Son los que HEAD nunca miraba: **91 como mucho** (591 − 500). Después, cada lunes a las 00:00 de Bogotá, la tanda semanal incluye a todos los inactivos, no solo a los que caían entre los 500.
- **Renovación (195), vencidas y enlaces:** hoy caben en 500, así que no deberían cambiar [S: no he medido producción].
- **Efecto en el contacto:** con 0 estrategias publicadas y el NBA apagado, ninguno de estos hechos dispara un mensaje. El planificador del H9 los ingiere por cursor. Si se publica una estrategia con disparador `inactivity_detected`, los considerará.

**La cifra exacta: consulta de SOLO LECTURA** para ejecutar en producción antes de desplegar, por quien tenga acceso; yo no lo tengo. Reproduce los criterios de `scanInactivity()`:

```sql
with p as (
  select (now() at time zone 'America/Bogota')::date as hoy
), c as (
  select hoy,
         ((hoy - 14)::timestamp at time zone 'America/Bogota') at time zone 'UTC' as corte_utc,
         to_char(hoy, 'IYYY-"W"IW') as semana
  from p
), ultima as (
  select m.id as member_id, max(a.captured_at) as ultima
  from members m
  join users u on u.id = m.user_id
  join attendances a on a.member_id = m.id and a.action = 'entry'
  where u.membership_end_date is not null and u.membership_end_date >= (select hoy from c)
  group by m.id
)
select count(*)                             as inactivos_vigentes,
       count(*) filter (where e.id is null) as nuevos_en_la_primera_pasada,
       (select semana from c)               as semana
from ultima l
left join commercial_events e
  on e.dedupe_key = 'inactivity_detected:inactive:' || l.member_id || ':' || (select semana from c)
where l.ultima <= (select corte_utc from c);
```

Con `14` = `commercial.thresholds.at_risk_days`; si en producción vale otra cosa, hay que cambiarlo. Hace un recorrido secuencial de `attendances` (sin el índice de §2.5): de decenas a cientos de milisegundos. Es solo lectura.

**Validada en local [C]**, sobre la base sintética, después de dos pasadas de HEAD (el estado de producción):
- antes de desplegar: `inactivos_vigentes = 150`, `nuevos_en_la_primera_pasada = 91`, `semana = 2026-W46`;
- la primera pasada nueva registra `inactivity 91`;
- la segunda, `0`;
- después la consulta da `0`.

**Coste por pasada:** del mismo orden que hoy (§2.4). La primera pasada inserta los hechos que faltan, como mucho 91 si se despliega esta semana.

### 2.8 Vuelta atrás

Revertir `app/Console/Commands/ScanCommercialFacts.php` a `181075b3`. Los hechos que haya registrado de más son hechos ciertos, con su clave semanal o diaria; no hay que borrarlos. Si se quisiera, salen por `dedupe_key`/`created_at`, pero no lo recomiendo.

### 2.9 Lo que NO he verificado

- **Producción, nada:** ni la cifra de §2.7 (la consulta está para ejecutarla quien tenga acceso), ni los planes reales con los tamaños reales, ni el valor de `at_risk_days`.
- **Los tamaños de §2.4 son sintéticos.** El plan puede cambiar con otra distribución, sobre todo el de los socios vigentes (`Seq Scan on members` + `Hash Join`), que con pocas filas es lo correcto.
- **Si alguna familia supera hoy los 500 candidatos fuera de la inactividad.** Si fuera así, la primera pasada también registraría lo que le faltaba.

---

## Parte 1c · El sujeto se resuelve dentro de la protección (sale de la revisión de `informe-reviewer-pagos.md`)

### 1c.1 Ficheros (lista exacta; diff contra `2fc75a1f`)

| Fichero | Estado |
|---|---|
| `app/Services/Commercial/CommercialEventRecorder.php` | modificado |
| `app/Observers/Commercial/PaymentTransactionCommercialObserver.php` | modificado |
| `app/Observers/Commercial/MembershipCommercialObserver.php` | modificado |
| `app/Observers/Commercial/MemberCommercialObserver.php` | modificado |
| `app/Observers/Commercial/MarketingAppointmentCommercialObserver.php` | modificado |
| `app/Observers/Commercial/MarketingConversationCommercialObserver.php` | modificado |
| `app/Observers/Commercial/AttendanceCommercialObserver.php` | modificado |
| `app/Observers/Commercial/ElectronicInvoiceCommercialObserver.php` | modificado |
| `tests/Feature/Commercial/CommercialEventRecorderContainmentTest.php` | modificado (pruebas nuevas) |
| `tests/Feature/Commercial/CommercialEventRecorderPostgresTest.php` | modificado (pruebas nuevas) |

Es solo código. La puerta de diff del H9 lista los 8 de `app/` (autorizado), más `MarketingAiActionCommercialObserver.php`, que viene del commit `1b91dc5b`. No he tocado `MarketingAiActionCommercialObserver` (registra después del commit) ni `MarketingLeadCommercialObserver` (no consulta: el sujeto sale del propio modelo).

### 1c.2 Diagnóstico [C], con las clases reales en PG 14 y comparado con `181075b3`

**El hueco.** Las lecturas de `CommercialSubjectResolver` (`Schema::hasTable` y los SELECT a `members` y `marketing_leads`) corrían en los observadores ANTES de `record()` y fuera de `isolated()`.

**Qué pasa de verdad depende de dónde caiga el fallo.** Lo he medido. Bloqueo `marketing_leads` desde otra sesión con `lock_timeout = 300ms`; así solo falla la lectura del resolver y no las consultas propias del activador:

| Escenario (con `181075b3`) | Lo que pasa | Prueba |
|---|---|---|
| Wompi `transitionTo()` → `activate()` → `MembershipPeriod::apply()` → `$user->save()` → resolver de la membresía | El fallo cae dentro del `DB::transaction(…, 3)` anidado de `MembershipPeriod`. Laravel vuelve a su punto de guardado (un `lock_timeout` no es un error «de concurrencia») y `activate()` se lo traga. **Pago `approved` SIN membresía, sin excepción.** La activación solo corre al ENTRAR en `approved`, así que ningún reintento la repite | `…membresia_aplicada`: `la membresía no se aplicó · Failed asserting that null is not null` |
| Wompi, sin plan: el resolver del observador de la factura que encola el activador | El `firstOrCreate` de la factura (`createOrFirst`, con su punto de guardado de Laravel) vuelve atrás y `InvoicingService::enqueue()` se lo traga. **Pago `approved` SIN factura**, en silencio | `…resolver_de_la_factura…`: `la factura pendiente se encoló · Failed asserting that 0 is identical to 1` |
| Lo mismo por Nequi (`NequiPushPaymentService::transition()`) | Igual: aprobado y sin factura | ídem |
| Wompi, pago con socio: el resolver del observador del PAGO | La excepción sale por `$fresh->save()`: **ruidoso**. El pago no se aprueba y Wompi o la reconciliación reintentan | `QueryException: SQLSTATE[55P03] … lock timeout … select "id" from "marketing_leads"` |

**Corrección a la lectura del reviewer:** en el camino de Wompi que describe, el pago NO se pierde. Lo que se pierde, en silencio y para siempre, es la membresía (o la factura). No lo impide su análisis, sino dos puntos de guardado que ya pone Laravel (`MembershipPeriod` y `createOrFirst`). Si el fallo cayera FUERA de esos puntos, `transitionTo()` lo haría ruidoso con su `return $fresh->fresh()` final (`WompiTransactionService.php:428`). En cambio, Nequi (`NequiPushPaymentService.php:459-497`) no tiene ninguna sentencia propia después de activar, y ahí la pérdida sería silenciosa (§1c.5).

### 1c.3 Qué cambió

- **`CommercialEventRecorder.php`**
  - `:57-70` y `:75-78`: `record()` acepta `array|\Closure $subject`. Un array sigue valiendo y la firma es compatible.
  - `:110-115`: con un array, «sin sujeto» se sigue sabiendo sin tocar la base, como antes.
  - `:118-157`: con un cierre, el sujeto se resuelve **dentro** de `isolated()` (`:124`), y la clave, la lectura previa y el INSERT siguen detrás, en el mismo orden que antes. Si resolver falla: vuelta al punto de guardado, `commercial.event.record_failed` (el mismo log) y `null`. No se registra nada.
  - `:188-205`: `subjectIds()` saca los ids de un sujeto ya resuelto; es el mismo cálculo que antes estaba en línea.
  - El log `commercial.event.recorded` usa esos mismos ids.
- **Observadores: el sujeto pasa como cierre** `fn (): array => $this->resolver->…`.
  - `PaymentTransactionCommercialObserver.php:87`
  - `MembershipCommercialObserver.php:66`
  - `MemberCommercialObserver.php:46`
  - `MarketingAppointmentCommercialObserver.php:52`
  - `MarketingConversationCommercialObserver.php:42` (con `+ ['opportunity_id' => null]`, como antes)
  - `AttendanceCommercialObserver.php:94`
  - `ElectronicInvoiceCommercialObserver.php:73`, todo `subjectFor()`. Una factura de mostrador sin ficha resuelve un sujeto vacío y no registra nada, como antes.
- **`AttendanceCommercialObserver.php:48-62`.** La consulta propia `entriesFor()` (el recuento de visitas que decide primera visita o hito) va por `$this->recorder->isolated()`. Si falla, `commercial.event.record_failed` con `event = first_checkin|attendance_milestone` y no se registra nada.
- **No cambia qué se registra ni cuándo.** Lo único distinto es que un fallo del resolver ya no sale por el `save()` de quien escribe: se queda en el log. Antes era ruidoso o, con una captura por encima, silencioso.
- **Pint:** en `MemberCommercialObserver` y `AttendanceCommercialObserver` reescribía estilo ajeno al cambio (un `{@see}` con nombre completo y un `DB::raw` con nombre completo). Lo he devuelto a su forma original para que el diff sea solo lo mío. Esos dos ficheros ya fallaban `pint --test` en HEAD; los demás pasan.

### 1c.4 Pruebas [C]

Nuevas en `CommercialEventRecorderPostgresTest` (PG; en SQLite, omitidas con su motivo):

| Prueba | Con `181075b3` | Con el cambio |
|---|---|---|
| `test_por_wompi_si_la_lectura_del_resolver_falla_el_pago_queda_aprobado_y_la_membresia_aplicada`: la cadena exacta del reviewer, con el pago y `users.membership_end_date` releídos desde otra sesión y el periodo del pago legado igual al de la membresía | **roja**: membresía nula | verde |
| `test_por_wompi_si_la_lectura_del_resolver_de_la_factura_falla_el_pago_queda_aprobado` | **roja**: 0 facturas | verde |
| `test_por_nequi_si_la_lectura_del_resolver_de_la_factura_falla_el_pago_queda_aprobado` (subclase de prueba que solo expone `transition()`) | **roja**: 0 facturas | verde |
| `test_por_wompi_con_socio_si_la_lectura_del_resolver_del_pago_falla_el_pago_queda_aprobado` | **roja**: `QueryException 55P03` | verde |
| `test_una_transaccion_abierta_por_fuera_de_laravel_tambien_queda_protegida` (**MENOR 1**): `getPdo()->exec('BEGIN')`, `correlation_id` inválido, sentencia después, `exec('COMMIT')`, lo de quien llama confirmado | verde (ya lo hacía; fija la rama) | verde |

Nuevas en `CommercialEventRecorderContainmentTest` (SQLite y PG):

| Prueba | Con `181075b3` | Con el cambio |
|---|---|---|
| `test_en_el_camino_feliz_el_punto_de_guardado_se_libera` (**MENOR 2**): tras un `record()` con éxito y un duplicado, `ROLLBACK TO SAVEPOINT commercial_event_record` falla con «no existe», dentro de un punto de guardado de la prueba | verde (fija la rama) | verde |
| `test_un_sujeto_que_no_se_puede_resolver_no_rompe_la_transaccion_de_quien_llama`: un cierre que consulta una tabla inexistente da `null` + `record_failed`, la transacción sigue y un cierre que sí resuelve registra | **roja** (`TypeError`: la firma no admitía cierre) | verde |
| `test_un_recuento_de_visitas_que_falla_no_rompe_la_transaccion_de_la_asistencia`: error REAL del servidor inyectado justo antes del recuento (`beforeExecuting` + una sentencia sobre una tabla inexistente, en la misma conexión) | **roja**: el error sale por el `create()` de la asistencia | verde |
| `test_si_el_resolver_falla_el_observador_no_lanza_y_no_registra`, × 7 (pago, membresía, ficha enlazada, cita, conversación, asistencia, factura), con un resolver que lanza: fija DÓNDE se resuelve | **rojas las 7**: la excepción sale por el `save()` | verdes |

Salidas [C]:

| Clase | Motor | `181075b3` | Con el cambio |
|---|---|---|---|
| `CommercialEventRecorderPostgresTest` | PG | `Tests: 13, Errors: 1, Failures: 3` | `OK (13 tests, 126 assertions)` |
| `CommercialEventRecorderContainmentTest` | PG | `Tests: 16, Errors: 9` | `OK (16 tests, 79 assertions)` |
| las dos | SQLite | `Tests: 29, Errors: 9, Skipped: 13` | `Tests: 29, Assertions: 76, Skipped: 13`, todo OK |

**Mutaciones** en la copia fuera del árbol; mueren las 7:

| Mutación | La mata |
|---|---|
| C1 · el cierre se resuelve antes de `isolated()` | 4 de PG + la del cierre |
| C2 · `entriesFor()` sin `isolated()` | la del recuento |
| C3 · la guarda solo mira `transactionLevel()` (el M4 del reviewer) | la del `BEGIN` directo |
| C4 · sin `RELEASE` en el éxito (el M5 del reviewer) | la del `RELEASE` |
| C5 · el pago resuelve fuera | la de PG con socio + «pago» × 7 |
| C6 · la factura resuelve fuera | Wompi y Nequi de factura + «factura» × 7 |
| C7 · la membresía resuelve fuera | la cadena del reviewer + «membresía» × 7 |

**Regresión [C]:**
- **SQLite**, `tests/Feature/Commercial`, `Billing`, `Wompi`, `Payments`, `Cycle`, `Subscriptions`, los dos Chaos, `PaymentFactRegressionTest`, `UltronPaymentApprovedTest`, la simulación de quejas y `AgendaComercialUltronTest`: `Tests: 931, Failures: 1, Skipped: 13`. La falla es la preexistente `PricingV2SalesAndPaymentsTest::test_manual_payment_uses_backend_quote`.
- **SQLite**, `tests/Unit/Outreach` + `tests/Feature/Outreach`: `Tests: 1646, Skipped: 164`, OK.
- **Guardas:** `Tests: 138, Failures: 1`. Es la puerta de diff y lista exactamente los 9 ficheros de arriba.
- **PG**, `tests/Feature/Commercial`: `Tests: 200, Errors: 31`, **las mismas 31 de siempre** (`diff` de nombres vacío).
- **PG**, pagos (Cycle, Payments, Wompi, Subscriptions, Chaos, …): **las mismas 15 fallas de la línea base** (`diff` vacío).

### 1c.5 Capturas que se tragan errores de base dentro de una transacción con un observador comercial dentro [L, y C donde se indica]

| Sitio | ¿Dentro de una transacción? | ¿Se traga errores de base? | Estado |
|---|---|---|---|
| `InvoicingService::enqueue()` (`:607-660`, `catch (Throwable)`) | **Sí**, cuando lo llama el activador dentro de la transacción del pago (Wompi y Nequi). Las ventas de caja (`CajaController.php:370`, `:444`, `:472`) y los cobros de mostrador (`PaymentController.php:663`, `:1068`) la llaman DESPUÉS de su transacción: autocommit | Sí: el perfil fiscal, el `firstOrCreate` y el log de la factura. Su observador comercial ya queda protegido (1b + 1c). Sus consultas PROPIAS, no | Hallazgo adyacente (facturación); no lo toco |
| Alta de asistencias (`AttendanceController::store`, `:63-197`) | **No**: no abre transacción, así que el observador corre en autocommit | Su `catch` final devuelve 500; el de la racha (`:160`) va en autocommit | Sin riesgo de I-1 |
| `RealtimeEvents::emit()` (`RealtimeEvents.php:56-82`, `catch (Throwable)` en un INSERT y un DELETE) | **Sí**: `transitionTo()` y Nequi lo llaman tres veces dentro de la transacción del pago | Sí | Hallazgo adyacente; mismo patrón |
| `MarketingPaymentOutcomeService::handle()` (`:43-50`, `catch (Throwable)`), desde `MarketingPaymentOutcomeObserver`, **siempre armado** | **Sí**: en cada cambio de estado de un pago con clave `mkt-lead-…` | Sí: lecturas y `save()` de prospecto, conversación y pago | Hallazgo adyacente; mismo patrón |
| `PaymentMembershipActivator::activate()` (`:116`) | Sí | Sí | §1c.6 (solo diagnóstico) |

### 1c.6 Diagnóstico (NO corregido): el `catch (Throwable)` de `PaymentMembershipActivator::activate()`

**¿Puede un fallo de base del PROPIO activador perder en silencio el pago aprobado en PostgreSQL? Sí, por dos vías distintas.** Lo he comprobado con una sonda en PG, fuera del repositorio, con los observadores comerciales APAGADOS (`commercial.events_enabled = false`) para que solo cuente el activador. La sonda está en `scratchpad/mut/backend/tests/Probe/ActivatorSwallowProbeTest.php`; la otra sesión bloquea una fila y `lock_timeout = 300ms`:

| Fallo provocado | Camino | 1.ª aprobación | Reintento sin bloqueo |
|---|---|---|---|
| Fila del **usuario** `FOR UPDATE`: el INSERT del pago legado (FK a `users`) o el bloqueo de `MembershipPeriod` caen por `lock_timeout` DENTRO de un punto de guardado de Laravel (`createOrFirst` / `MembershipPeriod`) | Wompi | sin excepción; **pago `approved`, 0 filas en `payments`, membresía nula** | **igual: nunca se activa** |
| ídem | Nequi | igual | igual |
| Ficha del **socio** `FOR NO KEY UPDATE`: el `UPDATE members SET status` del activador falla FUERA de cualquier punto de guardado → la transacción del pago queda abortada | Wompi | **`QueryException 25P02`** (ruidoso, por el `fresh()` final); pago `pending` | aprobado y membresía aplicada |
| ídem | Nequi | **sin excepción; pago `pending`: el COMMIT respondió ROLLBACK y la aprobación se perdió en silencio** | aprobado y membresía aplicada (si alguien vuelve a consultar) |

**Los hechos:**
1. **Cobrado sin servicio, en silencio y para siempre**, por Wompi y por Nequi: el pago queda aprobado sin fila en `payments` y sin membresía. Solo queda `Log::warning('Activación de membresía post-pago falló')`. La activación solo corre al ENTRAR en `approved`, la reconciliación de Wompi solo retoma lo que sigue en vuelo, y no hay ningún barrido que repare los aprobados sin activar.
2. **Aprobación perdida en silencio**, solo por Nequi: no hay ninguna sentencia propia después de `activate()` (las de `RealtimeEvents` se tragan su 25P02). No hay ninguna tarea programada que vuelva a consultar Nequi (`routes/console.php`: solo `payments:wompi-reconcile`), así que depende de un webhook o de una consulta posterior. Por Wompi la misma causa es ruidosa: 500 y reintento.

**Probabilidad:** baja, pero no nula. Hace falta un error de base dentro del activador, durante la transacción del pago:
- un `lock_timeout` o un `statement_timeout`, si el rol los tiene en producción (no lo puedo ver desde el repositorio);
- un **interbloqueo** con otra operación sobre el mismo socio (un cobro de mostrador, un ajuste o un traslado), que no necesita ningún parámetro;
- un ALTER durante un despliegue.

El interbloqueo, además, no lo deshace el punto de guardado de Laravel (`DeadlockException` en el nivel anidado), así que por Wompi sería ruidoso y por Nequi silencioso.

**Impacto:** alto: dinero cobrado sin servicio, o una aprobación que desaparece.

**Corrección que propondría** (núcleo de pagos: decide el propietario):
1. **Que el activador no pueda dejar abortada la transacción del pago.** Su cuerpo, dentro de un punto de guardado propio, como `CommercialEventRecorder::isolated()`. Arregla la pérdida silenciosa de Nequi y el 500 de Wompi.
2. **Que lo que falle se reintente.** Un barrido programado (sin migración) que busque `payment_transactions` en `approved` sin fila `payments` de origen `gateway` con su `reference`, o con `period_start` nulo y plan, y vuelva a llamar a `activate()`, que ya es idempotente por referencia. Junto con el punto 1, convierte el «cobrado sin servicio» en un retraso de minutos.
3. **Alternativa más estricta:** quitar el `catch` y que la activación sea todo o nada con la aprobación (500 y reintento de la pasarela). Es coherente, pero un fallo persistente del activador dejaría pagos cobrados en `pending` hasta arreglarlo.

**Mismo patrón al lado (§1c.5):** `RealtimeEvents::emit()`, `MarketingPaymentOutcomeService::handle()`, `InvoicingService::enqueue()` y los `catch` de suscripciones de `transitionTo()` (`:383`, `:399`, `:416`). Cualquiera de ellos puede ser el primero en abortar la transacción del pago.

### 1c.7 Impacto y vuelta atrás

- **Producción.** Un fallo al resolver el sujeto o al contar visitas ya no puede abortar la transacción de quien escribe ni salir por su `save()`. El hecho se pierde y queda en el log. Antes, según el camino:
  - el pago se aprobaba sin membresía o sin factura (silencioso);
  - o la petición fallaba (ruidoso).
- **Coste.** Con el sujeto como cierre, el `SAVEPOINT` se abre antes de las lecturas del resolver: las mismas consultas de antes, ahora dentro del punto de guardado, y ninguna ida y vuelta nueva. Los observadores que antes salían sin llamar a `record()` (la factura de mostrador sin ficha) ahora abren y liberan un punto de guardado más.
- **Vuelta atrás:** revertir los 8 ficheros de `app/` a `2fc75a1f`. Sin esquema ni datos.

### 1c.8 Lo que NO he verificado

- Si el rol de producción tiene `lock_timeout` o `statement_timeout`.
- Si el proveedor Nequi directo está activo en producción (`services.payments.nequi_provider = direct`).
- La frecuencia real de interbloqueos en pagos.
- El `MarketingAiActionCommercialObserver`, que sigue como estaba: registra después del commit, fuera de la transacción.

---

## Consulta de solo lectura: ¿ha pasado ya el «cobrado sin servicio» de §1c.6?

**Fichero:** `docs/ultron-studio/ops/pagos-aprobados-sin-activar.sql` (nuevo). Es solo `SELECT` y está pensada para `PGOPTIONS='-c default_transaction_read_only=on -c statement_timeout=20s' psql -At -F '|' -f …`.

**Criterio, leído en `PaymentMembershipActivator::activate()` y `MembershipPeriod::apply()`:**
- El usuario es `payment_transactions.user_id` o, si falta, `members.user_id` de la ficha. Sin usuario existente, el activador no hace nada.
- Con usuario, crea **siempre** la fila `payments` con `reference` = la de la transacción y `origin = 'gateway'` (índice único parcial `payments_gateway_reference_unique`), tenga plan o no.
- Con plan, el periodo queda en `payments.period_start`/`period_end`. `revert()` lo vacía al dejar de estar cobrado (`status <> 'paid'`), y no se aplica si el plan no existe o tiene 0 días.
- En la pasarela no hay ventas de producto ni abonos: van por caja (`origin = 'counter'`).

**Columnas** (15, en este orden): `bloque` (0 = recuento por clase, 1 = detalle) · `clase` · `debio_activar` (`si` | `revisar` | `no`) · `n_clase` · `tx_id` · `proveedor` · `aprobada_bogota` (`YYYY-MM-DD HH24:MI`, hora de Bogotá) · `fecha_de` (`approved_at` | `paid_at` | `updated_at`, la primera no nula) · `importe` · `moneda` · `tiene_plan` · `plan_dias` · `fila_payments` · `periodo` · `ref_fin6` (los 6 últimos caracteres de la referencia). Sin nombre, teléfono, correo, documento, referencia completa ni ids de persona.

**Clases:**

| Clase | `debio_activar` | Significado |
|---|---|---|
| `FALLO_SIN_PAGO_NI_MEMBRESIA` | `si` | Con plan y sin la fila de la pasarela: **cobrado sin servicio** |
| `FALLO_CON_PLAN_SIN_PERIODO` | `si` | Fila cobrada con plan válido y sin periodo: membresía no aplicada |
| `FALLO_SIN_REGISTRO_DE_PAGO` | `si` | Sin plan y sin la fila: falta el registro del pago; no hay membresía en juego |
| `REVISAR_PLAN_SIN_DIAS` | `revisar` | El plan ya no existe o tiene 0 días: es así por diseño, pero hay que mirarlo |
| `NORMAL_SIN_USUARIO`, `NORMAL_SIN_PLAN`, `NORMAL_PAGO_REVERTIDO` | `no` | Solo se cuentan |
| `OK_ACTIVADO` | `no` | Solo se cuentan |

**Probada [C]** en el PG 14 local (`ultron_studio_db_comercial_pagos`), con `default_transaction_read_only=on` y `statement_timeout=20s`:
- 2 aprobaciones REALES de «cobrado sin servicio», producidas por el código con la sonda de §1c.6 (Wompi y Nequi);
- 2 activaciones REALES correctas, con el usuario resuelto por la ficha;
- 10 casos sembrados: uno por clase, más el usuario resuelto por la ficha, un cobro de CAJA con la misma referencia (no cuenta como fila de la pasarela), una aprobación sin `approved_at` y una transacción `pending` (queda fuera).

Salida:

```
0|FALLO_CON_PLAN_SIN_PERIODO|si|1|…
0|FALLO_SIN_PAGO_NI_MEMBRESIA|si|5|…        (las 2 reales + 9302 + 9308 por la ficha + 9311 con fila de caja)
0|FALLO_SIN_REGISTRO_DE_PAGO|si|1|…
0|REVISAR_PLAN_SIN_DIAS|revisar|1|…
0|NORMAL_PAGO_REVERTIDO|no|1|… · 0|NORMAL_SIN_PLAN|no|1|… · 0|NORMAL_SIN_USUARIO|no|2|… · 0|OK_ACTIVADO|no|3|…
1|FALLO_SIN_PAGO_NI_MEMBRESIA|si|5|1|wompi|2026-11-11 21:58|approved_at|80000.00|COP|t|30|f|f|a52ed0
1|FALLO_SIN_PAGO_NI_MEMBRESIA|si|5|2|nequi|2026-11-11 21:58|paid_at|80000.00|COP|t|30|f|f|abce2c
…
```

**No verificado:** el plan de ejecución con el volumen de producción. Los dos `left join` van por la clave primaria de `members` y `plans`, y por `payments_gateway_reference_unique`, cuyo predicado (`origin = 'gateway' and reference is not null`) está en la condición del `join` [S: no medido con volumen].

### Ajuste tras ejecutarla en producción (12-11): `NORMAL_ANTERIOR_AL_PERIODO`

**Producción (12-11, solo lectura, ejecutada por el lead):** 74 aprobadas = 9 `FALLO_CON_PLAN_SIN_PERIODO` + 1 `NORMAL_SIN_PLAN` + 55 `NORMAL_SIN_USUARIO` + 9 `OK_ACTIVADO`. Las 9 «fallo» son de Wompi, aprobadas del 12-06 al 14-09-2026 17:26 de Bogotá, **anteriores a la columna `period_start`** (migración `2026_09_14_000002`, lote 86). Desde que existe el periodo, ningún pago de pasarela con plan se ha quedado sin activar.

**Clase nueva:** `NORMAL_ANTERIOR_AL_PERIODO` (`debio_activar = 'no'`): una fila de pasarela con plan y sin periodo creada antes del corte.

**El corte se deduce de los datos, no se fija a mano.** Es el `created_at` más antiguo de una fila `payments` con `origin` `gateway` o `counter` y `period_start`. Se excluyen las `legacy`, porque la propia migración (`backfillLegacyPeriods`) y `ImportLegacyCrmCommand` les rellenaron periodos antiguos [L]. En las de pasarela y caja, el periodo solo lo escribe `MembershipPeriod`, al cobrar. El corte sale en la columna nueva `corte_bogota` (ahora son 16) de las filas del bloque 0.

**Límites** (escritos también en la cabecera):
1. Lo creado entre el despliegue y el primer periodo escrito cuenta como anterior. Con el tráfico de caja ese hueco es de minutos u horas.
2. Si una fila anterior recibiera su periodo después (una reconfirmación), el corte retrocedería y saldrían falsos positivos visibles, nunca fallos escondidos.

**Probada en local [C]:**
- El corte lo marca una fila de caja con periodo a las 2026-09-14 22:30 UTC (17:30 de Bogotá). Una fila `legacy` de 2025 con periodo **no** lo mueve.
- `NORMAL_ANTERIOR_AL_PERIODO = 2`: una de 2026-09-10 y otra creada 1 s antes del corte, en el hueco.
- Una posterior al corte sin periodo sigue en `FALLO_CON_PLAN_SIN_PERIODO`.
- El resto de clases, igual que antes.

Con los datos del 12-11, en producción deberían salir las 9 como `NORMAL_ANTERIOR_AL_PERIODO` y `FALLO_*` en 0. Cualquier `FALLO_*` posterior al corte es real.

---

## Parte 3 · La supresión 1581 alcanza `commercial_events` y lo que `marketing_ai_actions` guarda de la persona (I-2)

### 3.1 Ficheros (lista exacta; diff contra `9a6e3b1c`)

| Fichero | Estado |
|---|---|
| `docs/ultron-studio/ops/supresion-1581.sql` | modificado: **+165 líneas, 0 quitadas**. sha256 `99205e4731f2fcc00e20a88faa1bf004ec4b6bfaeead58dafea8c866a24b64b4`; el de HEAD era `6c4af2f5…`, el que pasó la prueba de la voz |
| `tests/Feature/Commercial/CommercialSuppression1581Test.php` | nuevo. sha256 `c609dabac4a921380ec00a86c015a788027cbb25a13e9aa18b3a7df35947a17e`. Pint: pasa |

Es solo un guion de operación y su prueba: no hay migración ni código de la aplicación. **No se ha ejecutado en producción.**

### 3.2 Diagnóstico [L + C]

**Cobertura previa.** En HEAD, `grep -n 'commercial_events\|marketing_ai_actions' supresion-1581.sql` da 0 líneas: una supresión no tocaba ninguna de las dos tablas.

**`commercial_events`** (catálogo del PG local):
- Ninguna clave foránea apunta a ella, y no tiene disparadores ni CHECK (solo el único de `dedupe_key`).
- Sus claves foráneas salientes: `marketing_lead_id` en cascada y `commercial_opportunity_id` con `SET NULL`.
- No es de solo inserción: el motor escribe `evaluated_at`.
- Lectores:
  - el planificador del H9, por cursor; un hecho sin sujeto lo salta (`OutreachPlanner`: `if ($clave === null) continue;`);
  - `EngineFacts` (los frenos N6), la bandeja (`InboxContextService`), Supervisión y la analítica de campañas, todos por persona;
  - `EvaluateCommercialSubject`, por id; sin persona, lo marca evaluado.

**`marketing_ai_actions`:**
- Ninguna clave foránea apunta a ella, y no tiene disparadores ni CHECK (salvo el único de `idempotency_key`).
- `lead_id` es NOT NULL, en cascada desde el prospecto.
- `reason` es un código (`complaint`, el `intent`, motivos de salto) o texto libre (n8n hasta 1000 caracteres; la toma manual, 500).
- `metadata` guarda sobre todo lo que la IA infiere de la persona: `intent`, `temperature`, `objection`, `lead_score`, `main_barrier`, `payment_readiness`, `staff_review_reason`, `next_best_action`, …
- `metadata` guarda también sellos operativos que LEEN otros procesos:

| Clave | Lector |
|---|---|
| `message_id` | `AnalyzeInboundMessage` (idempotencia del análisis) y `UltronCanaryReport` |
| `studio`, `studio_prompts` | `StudioCanaryMeasures` (mediciones del canario) |
| `recovery`, `outbound`, `blocked_reason` | `UltronTurnWatchdog` (decide si un turno quedó sin responder) |

  Son códigos e ids internos de forma cerrada (`UltronController.php:275-295`, `UltronCommitService.php:3887`).

**El ORDEN de security, en concreto:**
- N6 frena con `complaint_created` (`complaint_recent`) y `human_requested` (`human_requested_recently`), leídos de `commercial_events` (`PolicyLadder.php:83`, `EngineFacts`). Borrarlos levanta el freno.
- La baja que la escalera lee de `outreach_outcomes` (`SubjectSuppressions`, N2 `global_suppression`) va por `subject_key`, y el bloque del H9 la reescribe a `x:<id>`: después de suprimir, esa baja **ya no casa con la persona**.
- La baja que sobrevive es `marketing_leads.do_not_contact` («no contactar» en el CRM). N1 niega con `do_not_contact` mientras no sea explícitamente `false` (`PolicyLadder.php:247-250`), así que un socio sin prospecto ya falla cerrado.

### 3.3 Qué hace el bloque nuevo (redactar frente a borrar)

Es un bloque C al final (`supresion-1581.sql:1057-1193`), con su sección en la cabecera (`:138-163`). Va en la misma transacción que los otros dos, con la misma `:subject`; sin ella no hace nada. No hay variables nuevas. **No cambia ni una línea de lo que había** (165 inserciones, 0 borrados).

| Paso | Qué hace |
|---|---|
| **Persona** | Con `m:<id>`: el socio y sus prospectos. Con `l:<id>`: el prospecto y, si tiene socio, el socio y los demás prospectos de ese socio |
| **`commercial_events`** | **Se BORRAN** las filas de la persona (por `member_id` o por `marketing_lead_id` de sus prospectos). Motivos: (1) nada la referencia; (2) borrar el prospecto ya hace exactamente eso en cascada; (3) redactar dejaría solo `(event, occurred_at)`, porque `dedupe_key` y `payload` llevan ids de la persona (socio, usuario, pago, conversación, factura) y `correlation_id` la religa con los logs; (4) sus lectores toleran la ausencia. Minimización (Ley 1581) |
| **`marketing_ai_actions`** | **La fila SE QUEDA.** Su `idempotency_key` impide que ULTRON repita una decisión (borrarla abriría esa puerta), `lead_id` es NOT NULL y Supervisión y el canario cuentan acciones. `reason` → `[suprimido]`. `metadata` → **solo** `message_id`, `studio`, `studio_prompts`, `recovery`, `outbound` y `blocked_reason`; todo lo demás se quita (lista blanca: una clave nueva que alguien añada mañana se quita por defecto). Una metadata que no es objeto pasa a `{}` |
| **Orden** | Con frenos N6 y algún prospecto sin «no contactar»: en seco avisa «SIN BAJA GLOBAL»; con `:dry 0`, **ABORTA** (55000) sin tocar nada. Sin frenos, o socio sin prospecto: no hace falta |
| **Se informa, no se toca** | `commercial_opportunities`, `commercial_segments` y `commercial_tool_invocations` de la persona (el motor NBA, apagado): recuentos en la salida y en el libro (`commercial_informed`) |
| **Comprobación final** | 0 hechos de la persona y 0 acciones con su texto; si no cuadra, se deshace todo |
| **Libro** | Una fila `system.personal_data_redacted` (`scope` = `commercial`, `fields`, `commercial_informed`, `kept_metadata_keys`) solo si borra o redacta algo. Nunca la clave ni los ids |
| **Cerrojos** | Ninguno de tabla: `DELETE` y `UPDATE` toman cerrojos de fila. No hay disparadores que apagar |

### 3.4 Pruebas [C]

`CommercialSuppression1581Test` (PG; en SQLite se salta con su motivo). Ejecuta el `.sql` del repo con `psql`, con el modelo de roles de producción: dueño y aplicación sin superusuario y `migrate:fresh` con el rol de la aplicación. Una sola prueba de punta a punta, 7 pasos:

| Paso | Qué fija |
|---|---|
| 0 · en seco | `3 hecho(s), 2 de ellos freno N6`, `2 fila(s)` de la IA, lo informado `1 · 1 · 0` y `SIN BAJA GLOBAL`; ninguna tabla cambia; la salida no lleva datos |
| 1 · real sin la baja | rc 3, «primero la baja global»; nada cambia; los disparadores, igual |
| 2 · la aplicación marca «no contactar» | — |
| 3 · real, conectado COMO el dueño | Se borran los 3 hechos de la persona: por socio y por cada uno de sus dos prospectos. Los de las otras personas no cambian, fila a fila. De las acciones de la IA solo cambian las 2 con texto: `[suprimido]` y metadata `{message_id, studio}` o `{outbound, recovery, blocked_reason}`. Las 2 sin texto (o con solo sellos) y las de otras personas no cambian. **Fuera de las dos tablas nada cambia** (md5 de las 254 tablas), salvo **1** fila del libro con `fields {3, 2}` y `commercial_informed {1, 1, 0}`. Ni la clave ni los textos en la salida ni en el libro. Los mismos disparadores y restricciones |
| 4 · repetir | 0 y 0; ni una fila del libro |
| 5 · `l:<id>` | El prospecto, su socio y el otro prospecto del socio: «2 prospecto(s) y su socio». Sin frenos no pide la baja. Borra el hecho del otro prospecto y el del socio |
| 6 · socio sin prospecto, con queja | Procede sin baja (N1 ya falla cerrado) |

```
UNIT_DB=ultron_studio_1581_comercial docs/ultron-studio/ops/pgunit.sh --no-logging tests/Feature/Commercial/CommercialSuppression1581Test.php
OK (1 test, 361 assertions)
```

**Mutaciones del guion** (copia fuera del árbol); mueren 6 de 7:

| Mutación | La mata |
|---|---|
| S1 · sin la guarda de la baja | paso 1 |
| S2 · hechos solo por socio | paso 0 |
| S3 · metadata entera a `{}` | paso 3 |
| S4 · solo el motivo | paso 3 |
| S5 · `l:` sin su socio | paso 5 |
| S6 · freno solo la queja (sin `human_requested`) | paso 0 |
| S7 · sin la comprobación final | **sobrevive**: es defensa en profundidad. Solo salta si el borrado o la redacción no cuadran con el inventario, y con sentencias correctas en una transacción no se puede provocar sin otra sesión (como el M2 de la voz) |

**A/B en seco** (HEAD frente al nuevo, mismos parámetros y misma base, en el clúster local; `scratchpad/ab-1581-comercial.sh`):

| Base (dueño) | `:subject` | rc | Líneas del anterior que faltan | Líneas nuevas |
|---|---|---|---|---|
| `h9_e2e` (`h9e2e_owner`) | `m:1` | 0/0 | **0** | 5, todas del bloque comercial |
| `h9_e2e` | — | 0/0 | **0** | 0 (salida idéntica) |
| `1581_voz` (`ultron_studio_1581_owner`) | `m:1` | 0/0 | **0** | 5, del bloque |
| `1581_voz` | — | 0/0 | **0** | 0 |
| `banco_h8_ops8d` (`h5_owner`) | — | 0/0 | **0** | 0 |
| `e2e` (`ironbody`) | — | 0/0 | **0** | 0 |
| `1581_comercial` | `m:1` | 0/0 | **0** | 5, del bloque |

**A/B REAL** sobre dos copias desechables de una copia preparada de `h9_e2e`:
- **Preparación de la copia:** la aplicación paró lo vivo de `m:1` con la orden real `outreach:suppress-subject m:1 --apply`; además sembré hechos y una acción de la IA de esa persona, con «no contactar».
- **Parámetros:** término `Canario`, `admin_id` 1, la huella del canario y `:subject` `m:1`.
- **Resultado:** rc 0/0 y 254 tablas comparadas. **Solo difieren `commercial_events` y `marketing_ai_actions`**. El libro tiene +2 filas con el anterior (texto y `outreach`) y +3 con el nuevo (+`commercial`), y **la metadata de las filas que no son del bloque nuevo es idéntica**. 0 disparadores apagados en las dos.
- **Las demás copias:** sobre `h9_e2e` sin preparar (claves con trabajos o inscripciones abiertas) y sobre `e2e` (sin las tablas del H9), las dos versiones abortan igual en el bloque del H9, que no cambia: 0 tablas distintas.

**Lo que ejecuta o vigila el guion, en verde con el nuevo [C]:**
- `VoiceSuppression1581Test` en PG: `OK (1 test, 1122 assertions)`.
- `OutreachPersonalDataGuardTest`: PG `OK (4 tests, 1627 assertions)`; SQLite, las 3 clases con 2 omitidas (las de solo PG).
- Las 15 guardas: `OK (138 tests, 178698 assertions)`. La puerta de diff ya no falla: `origin/main` = HEAD = `9a6e3b1c`.

### 3.5 Impacto, riesgos y vuelta atrás

- **Mejora:** una solicitud Ley 1581 ya alcanza «se quejó», «pidió hablar con una persona» y el resto de hechos comerciales de la persona, y lo que la IA infirió de ella. Antes, cero filas.
- **Lo demás:** sin cambios de comportamiento en lo que ya hacía el guion (A/B arriba). Sin `:subject`, la salida es idéntica byte a byte.
- **Irreversible por diseño**, como el resto del guion: solo el respaldo previo devuelve lo borrado. El runbook ya exige un respaldo fresco antes de `:dry 0`.
- **Todo o nada:** una transacción. La guarda del orden, la comprobación final o cualquier error la deshacen entera.
- **Concurrencia:**
  - Un hecho nuevo de la persona que otra sesión confirme durante la transacción quedaría después. Con la baja global marcada y el bloque del H9 ya aplicado, es improbable; una pasada en seco posterior lo enseñaría (debe dar 0).
  - Solo hay cerrojos de fila: interbloqueo posible con un observador que escriba la misma fila de `marketing_ai_actions` (casi imposible: son filas históricas).
- **Rendimiento:** no medido.
  - `commercial_events` tiene índices `(member_id, occurred_at)` y `(marketing_lead_id, occurred_at)`.
  - `marketing_ai_actions` tiene `(lead_id, created_at)`.
  - Las búsquedas por persona van por índice [S: no medido con volumen].
- **Vuelta atrás del cambio:** `git checkout` del fichero `.sql` y borrar la prueba. Sin esquema.

### 3.6 Operaciones pendientes de autorización

Ninguna para cerrar I-2. Cuando llegue una solicitud real la ejecuta el operador con la orden del propietario, como siempre (runbook §10), **en este orden**:

1. **«No contactar»** en los prospectos de la persona (CRM). Es la baja global que sobrevive a la redacción.
2. `php artisan outreach:suppress-subject <clave> --apply` (como `www-data`): para lo vivo del H9.
3. El guion **en seco** con `:subject`. Revisar las líneas «Automatización comercial (motor e IA)»: si sale «SIN BAJA GLOBAL», volver al paso 1.
4. **Respaldo fresco** y `\set dry 0`.
5. Después: la misma pasada en seco (`commercial_events · 0`, `marketing_ai_actions · 0`).

### 3.7 Hallazgos para otros dueños (no corregidos)

| # | Hallazgo | Dueño |
|---|---|---|
| P3-A | **Tras el bloque del H9, la baja que la persona dejó en `outreach_outcomes` ya no casa con ella** (`subject_key` → `x:<id>`; `SubjectSuppressions` lee por clave). Si la persona sigue existiendo como prospecto o socio con consentimiento, la automatización podría volver a inscribirla. `do_not_contact` lo cubre; el bloque nuevo lo exige solo cuando hay frenos N6. Conviene decidir si la supresión debe EXIGIR siempre «no contactar» o dejar una marca de baja que no dependa de la clave | security + architect |
| P3-B | `outreach_decisions.facts_json` y `trace_json` siguen sin tocarse: I-2 #3 de security, preexistente (`phone.refs`, y el hecho «se quejó» dentro de los facts) | database-specialist + security, con su decisión sobre `outreach:replay` |
| P3-C | `commercial_opportunities`, `commercial_segments` y `commercial_tool_invocations` (motivo, argumentos, mensaje de error) solo se informan. Antes de encender el motor NBA habría que decidir su política | backend (motor) + security |
| P3-D | El guion no toca los datos de contacto de la persona en `marketing_leads`, `marketing_conversations` y `marketing_messages` (nombre, teléfono, mensajes): no son de este encargo. ¿Qué procedimiento los suprime hoy? Borrar el prospecto arrastra en cascada sus hechos y acciones, pero no lo hace este guion | lead + security |
| P3-E | El runbook (§10 de `informe-devops-h7.md`) y `ops/README.md` tienen que recoger el bloque nuevo y el ORDEN de §3.6 | devops / DOC |
| P3-F | La prueba deja en el clúster local la base `ultron_studio_1581_comercial` y los roles `ultron_studio_1581c_owner` y `ultron_studio_1581c_app` (sin superusuario), como la de la voz | informativo |

### 3.8 Lo que NO he verificado

- **Producción:** nada (volúmenes, roles reales, `log_statement`).
- **Concurrencia real** con escrituras de la aplicación durante la transacción: solo por diseño.
- **El plan de ejecución** con volumen.
- **El ensayo como `ironbody-migrate`:** la prueba demuestra que basta el dueño sin superusuario, conectado como él.
- **La valoración jurídica** de borrar los hechos frente a conservarlos: no hay obligación de retención conocida para `commercial_events`, pero lo decide el propietario con su asesoría.
