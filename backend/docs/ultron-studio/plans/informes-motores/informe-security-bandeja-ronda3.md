# Informe de security · Bandeja asistida, ronda 3 (N-1, N-2, m-1, m-2, m-3, m-8) en `298b855a`

**12-11-2026 · `security` · solo lectura.**
- **Árbol:** `_worktrees/ultron-h8/Iron-Body/backend`, rama `feat/ultron-studio-h9`, HEAD `298b855a` (LOCAL, sin subir), encima de `d9243a97`. Producción sirve `306b83e3`.
- **Vara de medir:** `informe-security-bandeja-condiciones.md` (N-1, N-2, m-1, m-2, m-3, m-8 y §7).
- **Qué no he tocado:** ni el código, ni las pruebas, ni el CRM, ni producción. Las sondas se ejecutaron en una copia (`cp -c -R`) del `backend/` dentro del directorio temporal de la sesión, nunca en `backend/tests/`.
- **Lo único que he escrito** es este informe.

Marcas: **[C]** comprobado ejecutando · **[L]** leído en el código · **[S]** supuesto.

## Veredicto

| | Veredicto |
|---|---|
| **N-1** (el «no me contacten» posterior) | **CERRADO CON CONDICIONES**: falta un caso, R3-1 |
| **N-2** (la estrategia parada) | **CERRADO** |
| **m-1, m-2, m-3, m-8** | Hechos. m-2 cubre solo los frenos (R3-2, menor) |
| **Código compartido** (`isSuppressed()`, `liveStrategies()`) | Equivalente para el despachador, el planificador, el conciliador y el monitor |
| **`298b855a`** | **APTO** para desplegar hoy |

**Por qué es APTO:**
- solo retiene más, nunca menos que `306b83e3`; no hay ninguna entrada con la que ofrezca o entregue algo que `306b83e3` no entregaba [L + C];
- `isSuppressed()` lanza la misma consulta y aplica la misma regla [C, sonda P2 + 4 baterías de sus llamadores], y `liveStrategies()` sin argumento no cambia [L];
- hoy no hay trabajos asistidos en vivo [S, según el encargo].

**La condición (R3-1), antes del asistido en vivo:** cuando una persona **revoca su consentimiento de voz** después de que el trabajo salió, la escalera lo negaría hoy en N1 (`no_consent_for_purpose_channel`). La bandeja, en cambio, sigue ofreciendo el trabajo `assisted:voice` y entrega `person` y el guion [C, sonda P1, en SQLite y en PostgreSQL 14].

---

## Hallazgos

### BLOQUEANTES
Ninguno.

### IMPORTANTE · pendiente antes del asistido en vivo

**R3-1 · La revocación del consentimiento de voz no retiene el asistido por voz.**
- **Ubicación:** `app/Services/Outreach/Run/AssistedQueue.php:484`, en `optedOut()`. La señal del consentimiento solo cuenta si `consent[medium] === 'denied'`.
- **Por qué se escapa [L]:**
  - en `CoreProjection::policyInput()` (`app/Services/Outreach/Facts/CoreProjection.php:150-161`), `'denied'` solo sale de `lead.consent_denied`, que es el estado del prospecto de WhatsApp;
  - la revocación de voz es un evento `revoked` en `voice_consents`. La registra el personal con `POST voice/contacts/{uuid}/revoke`, que llega a `VoiceContacts::revoke()` (`routes/voice.php:71`);
  - con esa revocación, `consent.voice_call.commercial` pasa a `false` (`VoiceConsentFacts.php:119-121`, `VoiceConsentRule::hasConsent()`), y por tanto `consent.voice` pasa a `'missing'`, no a `'denied'`;
  - la escalera niega todo lo que no sea `granted` (`PolicyLadder.php:241-246`). La bandeja solo niega `'denied'`.
- **Escenario [C, sonda P1, SQLite y PG 14]:**
  1. Un socio tiene un contacto de voz con el consentimiento concedido y confirmado por otra persona.
  2. Sale un trabajo `assisted:voice`. Antes de la revocación, `consent.voice=granted` y el trabajo se ofrece.
  3. Se registra `revoked`. Ahora `consent.voice=missing` y `excluded=false`.
  4. El trabajo **se sigue ofreciendo** a Beto. Beto lo reclama y recibe `person={"kind":"member","id":2}`, el guion y `contactWithheld=null`.
- **Está asumido en las pruebas del commit:**
  - `H9AssistedOptOutStrategyTest.php:291` dice «la voz no pide aquí su consentimiento, solo que no esté denegado»;
  - `AssistedQueueOptedOutTest.php:23` toma como «limpia» una persona con `consent.voice_call.commercial = false`.
- **Por qué importa:** revocar el consentimiento de llamadas es la forma más explícita de «no me llamen» (Ley 2300, art. 2; D8-18: «revocar no espera a nadie»). Es exactamente lo que N-1 pedía cubrir: una señal de «no contactar» que la escalera niega hoy.
- **Gravedad:** importante. No empeora nada respecto a `306b83e3`, que no miraba ninguna señal. Mientras no haya un asistido por voz en vivo, la exposición es nula.
- **Corrección propuesta (mínima):**
  - en `optedOut()`, con `medium === voice`, tratar `consent['voice'] !== 'granted'` como retención. La voz solo admite la finalidad comercial (D9-15) y no depende de ventanas de tiempo, así que no hay falsos positivos que dependan del reloj;
  - el motivo: `opted_out` si el último evento válido es `revoked`, y `policy_unknown` en otro caso (por ejemplo, el contacto desactivado);
  - **no** extenderlo a WhatsApp con la regla «≠ granted»: `consent.whatsapp.service` depende de la ventana de 24 h (`LeadFacts.php:251`) y retendría trabajos de servicio que siguen siendo válidos. La revocación por WhatsApp ya llega como `consent_denied` y ya está cubierta [C, `senales()` `consent_denied`];
  - prueba: revocar después de salir. El trabajo libre no se ofrece (409 `not_in_queue`) y el reclamante pierde `person` y `script`.

### MENORES · recomendado

**R3-2 · m-2 relee solo los frenos tras el `UPDATE`.**
- **Ubicación:** `AssistedQueue.php:260-263`.
- **Qué queda abierto:** una pausa de la estrategia o un DNC que lleguen entre `requireNotWithheld()` y el `UPDATE` entregan el contacto una vez, en la respuesta 201. La siguiente lectura ya retiene [L].
- **Ventana:** de milisegundos. Es lo que pedía m-2 (solo el freno).
- **Corrección barata:** tras el `UPDATE`, recalcular `withholdReasons([$trabajo], …)` completo en lugar de solo `openScopes()`. Son unas 4 consultas más, solo en el 201.

**R3-3 · N1 niega dos entradas que la bandeja no mira y que no son una petición de la persona.**
- **Las dos:** `rne_check_missing` (la consulta al RNE caduca dentro de la ventana de ≤ 24 h) y `subject_minor`.
- **Es deliberado:** `AssistedQueueOptedOutTest.php:58` fija que el RNE sin consulta vigente no retiene. Un RNE vigente que dice `listed` sí retiene, por `voice.excluded` [L].
- **Qué hacer:** que lo decida el propietario por escrito junto a D-3. No lo considero condición.

---

## 1 · N-1: señal por señal frente a `PolicyLadder.php:235-318`

| Señal que la escalera niega hoy | ¿La mira `optedOut()` / `personReasons()`? | Evidencia |
|---|---|---|
| `do_not_contact` (`!== false`) | Sí: `true` da `opted_out`; `null` da `policy_unknown` | [C] `senales()` `dnc`, SQLite y PG |
| `do_not_contact_sibling` (mismo número) | Sí | [C] `sibling_dnc` |
| `commercial_opt_out` (solo comercial) | Sí, solo en comercial, igual que N1 | [L] `:487-489`; [C] `commercial_opt_out` |
| `consent_denied` (`consent[medio] === denied`) | Sí; con `lead.consent_denied` desconocido, `policy_unknown` | [C] `consent_denied`; [L] |
| `no_consent_for_purpose_channel` (consentimiento ≠ granted) | **No.** En voz es la revocación: **R3-1** | [C] sonda P1 |
| `voice_excluded` (solo voz) | Sí, solo en trabajos por voz | [C] `test_n1_la_exclusion_de_voz…` |
| `rne_check_missing`, `subject_minor` | No; deliberado (R3-3) | [L] |
| `unknown_subject` | Sí: persona sin resolver o `x:<id>` dan `policy_unknown` | [C] `test_n1_sin_poder_comprobarlo…` |
| `global_suppression` (N2, por finalidad y medio, con clave canónica) | Sí: `areSuppressed()` con `canonicalKey ?? key`, la finalidad de la inscripción y el medio del trabajo, como `LadderInputBuilder.php:98` | [L] `:430`; [C] `opt_out_word` |
| `human_in_control`, frenos ajenos (D-3) | No aplican al asistido o quedan para la decisión D-3 | [L] |

**Por las vistas [C, mismas pruebas, SQLite y PG]:**
- con cualquier señal sabida, lo sin reclamar no sale en `unclaimed` ni en `all`, `claim()` da 409 `not_in_queue` y `withheld` lo cuenta;
- el reclamante, en `mine`, en `all` y en la repetición, recibe `person: null`, `script: null` y `contactWithheld: opted_out`;
- la otra persona ve `contactWithheld: null`;
- no sale ningún teléfono.

**El falla-cerrado es real [L + C]:**
- sin foto, sin persona resuelta, con un medio fuera del conjunto o con un valor `null`, la fila queda en `policy_unknown`;
- con las tablas de la cadena o de la política ilegibles, la excepción sube sin ningún `catch` (`grep` sin resultados en `AssistedQueue` y en el controlador) [C, `test_si_la_cadena_o_la_politica_no_se_pueden_leer…`].

## 2 · N-2: la lista blanca en SQL frente al PHP

- **Las dos dicen lo mismo [L].**
  - `runningParts()` (`:509`) construye un único conjunto `(versión, parte)` con `runnable() && !paused() && chainStatus === active`. Son las mismas entradas de N5 que `LadderInputBuilder.php:114-116` (`published`, `paused`, `chainReadable`).
  - El SQL (`inRunningPart()`, `:531`) filtra lo sin reclamar por `e.strategy_version_id` y `e.part` contra ese conjunto, agrupado entre paréntesis. Con el conjunto vacío queda `whereIn('e.id', [])`, que no deja pasar nada.
  - El PHP marca `strategy_stopped` con la misma clave (`enrollment_version_id:enrollment_part`).
  - Una parte que aparece después de leer la cadena no está en el conjunto, así que se retiene: falla cerrado.
- **Una parte parada y la otra en marcha:** la clave es por (versión, parte), así que solo se retiene la parada [L].
- **Archivada:** `liveStrategies($ids)` excluye `archived_at`, no devuelve nada y la fila se retiene [L; C `test_n2_…archivada…`].
- **`liveStrategies($ids)` con un subconjunto:** `chainStates()` verifica cada cabeza contra la entrada global `seq-1`, que es independiente del filtro, así que da el mismo veredicto que con todas [L, `OutreachPublishedStrategies.php:461-486`].
- **Lo comprobado [C]:** pausada, reanudada, revertida, retirada, archivada, ilegible y la reversión seguida del conciliador, en `H9AssistedOptOutStrategyTest` (SQLite y PG).
- **La cabeza global de la cadena** no se mira, igual que en la escalera: el monitor abre el freno global [L].

## 3 · El código compartido (el punto que bloquearía)

- **`SubjectSuppressions::isSuppressed()`**: la misma consulta [C, sonda P2: el SQL nuevo y el viejo solo cambian el orden de los dos términos del `AND`, con el `OR` entre paréntesis en los dos; el mismo `limit 200`, sin `ORDER` en ninguno de los dos].
  - El bucle se movió literalmente a `suppresses()`.
  - Su único llamador de producción es `LadderInputBuilder.php:98`, la N2 del planificador y del despachador, y su llamada no cambia [L, `grep`].
  - Las baterías de los llamadores pasan: `OptOutAcrossMediaTest` 3/71, `OptOutWordHardeningTest` 7/1209 y `OutreachOutcomesTest` 6/101 [C, SQLite].
- **`areSuppressed()`** es nuevo, solo lo usa la bandeja (`AssistedQueue.php:430`) y no tiene tope, que es correcto: un tope fallaría abierto [L].
- **`liveStrategies(?array $ids = null)`:** con `null` construye la misma consulta (`whereNull('archived_at')->orderBy('id')`, las mismas columnas) [L].
  - La llaman sin argumento el planificador (`OutreachPlanner.php:359`), el conciliador (`OutreachReconciler.php:122`), el monitor (`OutreachMonitor.php:84` y `:103`), los modelos de lectura (`OutreachReadModels.php:38`), y `runnableStrategies()` / `liveVersion()` (el despachador) [L, `grep`].
  - Solo `AssistedQueue.php:512` pasa ids.

## 4 · m-1, m-2, m-3, m-8

| | Resultado | Evidencia |
|---|---|---|
| **m-1** | La repetición pasa por `requireOpenEnrollment()` (`:241-242`) antes de construir la fila: 409 `enrollment_closed` y sin contacto | [C] `test_m1_…`, `test_n2_tras_la_reversion…`, `test_por_http…` |
| **m-2** | Relee `openScopes()` tras el `UPDATE`; con freno responde `contactWithheld: brake`. Solo el freno: R3-2 | [C] `test_m2_…`; [L] |
| **m-3** | `scanCapped` = `leidas >= MAX_SCAN && filas < limite`. Solo un booleano, sin oráculo nuevo | [C] `test_m3_…`, `test_por_http…` |
| **m-8** | Lo reclamado por otra persona solo sale con `expires_at > ahora`; el reclamante sigue viendo lo suyo vencido. `person` y `script` siguen en null para quien no lo reclamó | [C] `test_m8_…`; [L] `queueRows()` |

## 5 · Regresiones de `155e7adc` y `4a3d7eea`

**Siguen [C, `H9AssistedComplaintTest` 4/39, `H9AssistedConditionsTest` 11/184, `OutreachApiTest` 7/137, SQLite; `H9AssistedOptOutStrategyTest` 27/309 en SQLite y PG]:**
- los frenos se leen lo primero, en `page()` y en `claim()`, sin ningún `catch`;
- la queja y su falla-cerrado;
- el plazo;
- `human_requested` sigue fuera; solo retiene con el detalle `opt_out_word`, que es la supresión SC-2 y la escalera también lo niega;
- `outreach.assisted.claim_withheld` registra `{job, reason[, known]}`, sin datos personales;
- `contactWithheld` solo lo ve quien reclamó.

## 6 · Antes de publicar la primera estrategia con canal asistido en vivo

1. **R3-1:** la revocación de voz retiene el asistido por voz, con su prueba.
2. **D-3** y **R3-3**, decididos por escrito por el propietario.
3. Recomendado: **R3-2**, y lo que quedó de la ronda anterior (m-4, que el CRM relea la bandeja; m-6, `live.assistedQueue`).
4. Pedir a `tester` los casos negativos: la revocación de voz después de salir, y un DNC o una pausa entre la comprobación y el `UPDATE` de `claim()`.

## 7 · Lo que ejecuté

| Qué | Motor | Resultado |
|---|---|---|
| `H9AssistedOptOutStrategyTest.php` | SQLite / PG 14 (`ultron_studio_lead_ola5`) | `OK (27 tests, 309 assertions)` / ídem |
| `H9AssistedConditionsTest.php` | SQLite | `OK (11 tests, 184 assertions)` |
| `H9AssistedComplaintTest.php` | SQLite | `OK (4 tests, 39 assertions)` |
| `OutreachApiTest.php` | SQLite | `OK (7 tests, 137 assertions)` |
| `tests/Unit/Outreach/Run/AssistedQueueOptedOutTest.php` | SQLite | `OK (21 tests, 21 assertions)` |
| `W9/OptOutAcrossMediaTest.php`, `W9/OptOutWordHardeningTest.php`, `Run/OutreachOutcomesTest.php` (los llamadores de `isSuppressed()`) | SQLite | `OK (3/71)`, `OK (7/1209)`, `OK (6/101)` |
| Sonda P1 (la revocación de voz), en la copia | SQLite y PG 14 | Se reproduce en los dos: el trabajo se ofrece y el 201 lleva `person` y el guion |
| Sonda P2 (el SQL de `isSuppressed()`, el nuevo frente al viejo), en la copia | SQLite | Equivalente |

## 8 · Lo que no he podido verificar

- **Producción:** nada. Ni que no haya asistidos en vivo, ni las banderas [S].
- **La batería completa:** no la he lanzado, por indicación del encargo. Del planificador, el despachador y el monitor no he ejecutado pruebas propias; la equivalencia de `liveStrategies()` sin argumento es por lectura [L].
- **La carrera de R3-2 con dos conexiones reales:** sin ejecutar [L].
