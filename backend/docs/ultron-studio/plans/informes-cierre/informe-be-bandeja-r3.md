# Informe de backend · Cierre de la bandeja asistida: R3-1, R3-2, R3-3 y m-1

**22-11-2026 · `backend`.** Rama `cierre/bandeja` (sale de `0c57d7c4` = `main`), árbol `_worktrees/cierre-bandeja`. Sin push, sin producción, sin red.
Commits, ronda 1: `26685ce9` (código y pruebas) · `60e691b7` (carrera PG) · `3755aeb5` (banco de carga) · `7ec75388` (informe).
Commits, ronda 2 (§9): `866e856e` · `4828f48a` · `df195258` · `62d44d98` · `2484057a` · este informe.
Marcas: **[C]** comprobado ejecutando · **[L]** leído · **[S]** supuesto. Lo de la ronda 1 que la ronda 2 dejó viejo está marcado con «→ §9».

## Veredicto

| | Estado |
|---|---|
| **R3-1** revocar la voz después de salir | **CERRADO** [C]. La revocación que consta retiene con `opted_out`; el contacto retirado, con `policy_unknown` (→ §9) |
| **R3-2** lo que llega entre la comprobación y el `UPDATE` | **CERRADO** [C], SQLite y PG 14 con dos procesos |
| **R3-3** menor y RNE caducado | **CERRADO** [C] (directiva del 21-11) |
| **m-1** consultas por fila | **CERRADO** [C]: 44 consultas por página con 50, 500 y 5 000 trabajos (→ §9) |
| **F-1, F-2, F-3** | **CERRADOS** en la ronda 2 [C] (§9) |

## 1 · Qué cambió (`backend/app/Services/Outreach/Run/AssistedQueue.php`)

- `:104`, `:107`, `:115`: dos motivos nuevos, `subject_minor` y `rne_check_missing`. Van detrás de `complaint_recent` y delante de `policy_unknown`: lo sabido gana a lo desconocido.
- `:486-488` `personSelection()`: la foto pide `subject.is_minor` y `subject.has_voice_contact`, los dos de `CoreProjection::POLICY_FACTS` que mira el despachador. Sin `has_voice_contact`, `policyInput()` nunca da el RNE por vigente.
- `:508-520` `optedOut()` (R3-1): por voz, un consentimiento que no sea `granted` retiene (`null` → `policy_unknown`). Por WhatsApp no cambia nada, como pide el informe.
- `:551` `ineligible()` (R3-3), pura y con la misma entrada que N1:
  - `subject_minor` si `isMinor === true`, en cualquier medio. La edad desconocida no retiene, igual que la escalera (D9-15).
  - Por voz, `rne_check_missing` si `voice.rne_check_missing === true`.
  - En otro caso, `false`; si no se puede comprobar, `null`.
- `:448`, `:465` `personReasons()`: aplica esa prioridad.
- `:277-280` `claim()` (R3-2): después del `UPDATE` relee la fila y recalcula `withholdReasons()` entero: frenos, cadena, foto y libro, todo con lecturas nuevas. Antes solo releía los frenos.
- `:739` `dtos()` y `:794` `reasonsOf()` (m-1): el objetivo se lee una vez por versión, el guion una vez por plantilla y la traza en una sola consulta (`odec_job_idx`). `dto()` delega en `dtos()`. La salida es la misma [C].
- **Sin tocar:** `AssistedChannel.php`, el controlador, `Facts/**`, `Core/**`, `Voice/**` y `Marketing/**`. Los códigos HTTP tampoco cambian: el 409 sigue siendo `not_in_queue`.

**Pruebas nuevas:** `tests/Feature/Outreach/Run/H9AssistedEligibilityTest.php` (11: R3-1, R3-3 con su prioridad, R3-2 en una conexión y m-1 «sin consultas por fila»); `H9AssistedClaimRaceTest.php` (3, solo PG) con el trabajador `Support/assisted-claim-worker.php`; la unitaria `AssistedQueueOptedOutTest` pasa a 44 casos.

**Cambian a propósito (está en el commit):** `AssistedQueueOptedOutTest:23` (la persona «limpia» tenía el consentimiento de voz en `false`) y `:58` (el caso del RNE se mueve a `ineligible()` y ahora retiene); `H9AssistedOptOutStrategyTest:291` (decía «la voz no pide su consentimiento»); la lista de `WITHHELD_REASONS` en las dos baterías.

## 2 · Señal por señal (N1–N6 de `PolicyLadder.php` frente a la bandeja)

| Lo que la escalera niega | Bandeja | Motivo | Prueba |
|---|---|---|---|
| N1 `do_not_contact` · `do_not_contact_sibling` · `commercial_opt_out` (comercial) · `consent_denied` | Sí | `opted_out` (si no se sabe, `policy_unknown`) | `H9AssistedOptOutStrategyTest::test_n1_la_senal…` [dnc, sibling_dnc, commercial_opt_out, consent_denied]; unitaria |
| N1 `no_consent_for_purpose_channel`, por **voz** | **Sí (R3-1)** | `opted_out` si consta la revocación; `policy_unknown` si no (contacto retirado) | `H9AssistedEligibilityTest::test_r3_1_revocar…`, `test_r3_1_el_contacto_de_voz_retirado…`; unitaria «R3-1: …» |
| N1 `no_consent_for_purpose_channel`, por WhatsApp | No, por instrucción (ventana de 24 h; la baja llega como `consent_denied`) | — | unitaria «R3-1: WhatsApp…»; `test_r3_1` (el de WhatsApp sigue) |
| N1 `voice_excluded` | Sí, por voz | `opted_out` | `test_n1_la_exclusion_de_voz…` |
| N1 `rne_check_missing` | **Sí (R3-3)**, por voz | `rne_check_missing` / `policy_unknown` | `test_r3_3_la_consulta_al_rne…`, `test_r3_3_el_rne_caducado_no_retiene…whatsapp` |
| N1 `subject_minor` | **Sí (R3-3)** | `subject_minor` | `test_r3_3_el_menor…`, `test_r3_3_la_edad_que_no_se_sabe…`, `test_r3_3_la_prioridad…` |
| N1 `unknown_subject` | Sí | `policy_unknown` | `test_n1_sin_poder_comprobarlo…` |
| N2 `master_flag_off` | Sí (409 `outreach_disabled` en toda la ruta) | — | `OutreachIsolationTest` (banderas por defecto) |
| N2 `outreach_brake_global/channel/strategy` | Sí | `brake` | `H9AssistedConditionsTest::test_i3_la_parada_global…`, `test_m2_…`, `test_mr1_…` |
| N2 `mode_off` (y la sombra) · N4 `channel_disabled` del asistido | **Sí (F-1)** | `brake` | `test_f1_…` [sombra, off, canal apagado] |
| N3 `canary_only_declared` · N5 `canary_scope_mismatch` | **Sí (F-2)**, por medio | `canary_scope_mismatch` | `test_f2_el_canario…` [desactivado, revocado], `test_f2_con_la_version_solo_canario…` |
| N4 `template_not_approved` | **Sí (F-3)** | `template_not_approved` | `test_f3_la_plantilla_retirada…` |
| N2 `global_suppression` | Sí | `opted_out` | `test_n1_la_senal…` [opt_out_word]; `test_un_trabajo_de_una_persona_suprimida…` |
| N2 `human_in_control` | No: no aplica (ya hay una persona) | — | — |
| N2 `ultron_abort_latch` · `marketing_agent_paused` · `voice_braked` · `legacy_autonomy_on` · `legacy_followups_active` | No: **D-3** | — | — |
| N5 `strategy_not_published` · `strategy_paused` · `chain_unreadable` | Sí | `strategy_stopped` | `test_n2_la_estrategia_pausada…`, `test_n2_…revertida_retirada_archivada_o_ilegible`; carrera [pause] |
| N6 `complaint_recent` | Sí | `complaint_recent` / `complaint_unknown` | `H9AssistedComplaintTest` (4); `test_i1_…` |
| N6 `human_requested_recently` | No, a propósito (L9-66); solo retiene con `opt_out_word` | — | `test_n1_la_senal…` [opt_out_word] |
| Plazo del trabajo | Sí | `expired` | `test_i2_vencido…` |
| R3-2: lo que llega antes del `UPDATE` (DNC, pausa, menor) | Sí | el que toque | `test_r3_2_…` (SQLite y PG) y `H9AssistedClaimRaceTest` (PG) |

**Lo que N1–N6 decide al salir y la bandeja no vuelve a mirar:** presupuesto y cupos, horario, ventana, enfriamiento, guarda, Studio y catálogo, `success`, `audience` y cartera. No son una petición de la persona. Lo que sí puede cambiar después de salir: F-1 a F-3, cerrados en la ronda 2 (§9); F-4 queda como nota de diseño (§6).

**D-3, la pregunta exacta para el propietario:** «Los frenos ajenos al H9 que N2 aplica por medio son el cerrojo de abortos de ULTRON y la pausa del agente de mercadeo en WhatsApp, el freno de voz en la voz, y las dos banderas del motor anterior. ¿Deben retener también en la bandeja los asistidos que ya salieron? Retener significa no ofrecerlos, no dejarlos reclamar y quitar el enlace y el guion a quien ya los reclamó. ¿O se deja escrito en D9-14 que, para parar a las personas, sirven la PARADA GLOBAL y el freno del canal `assisted`, y no esos frenos?»

## 3 · Motivos para el CRM

→ §9.4, la lista definitiva tras la ronda 2.

## 4 · Pruebas ejecutadas (línea final)

**SQLite:**
- `tests/Unit/Outreach`: `OK (1038 tests, 53252 assertions)`.
- `tests/Feature/Outreach`: `OK, but some tests were skipped! Tests: 708, Assertions: 41509, Skipped: 167`. Todos los saltos son de diseño: solo PG, o la copia `v9-abierta`. 3 son de la carrera nueva.
- Guardas (`*Isolation*`, `*ReadOnly*`, `*StaticGuards*`, `SecretExposure*`): `OK (141 tests, 182975 assertions)`. La lista cerrada sigue en 9 lectores y no he editado ninguna guarda.

**PostgreSQL 14** (`ultron_studio_cierre_bandeja`):
- `H9AssistedEligibilityTest`: `OK (11 tests, 115 assertions)`.
- `AssistedQueueOptedOutTest`: `OK (44 tests, 45 assertions)`.
- `H9AssistedOptOutStrategyTest`: `OK (27 tests, 309 assertions)`.
- `H9AssistedConditionsTest`: `OK (11 tests, 184 assertions)`.
- `H9AssistedComplaintTest`: `OK (4 tests, 39 assertions)`.
- `H9AssistedClaimRaceTest`: `OK (3 tests, 84 assertions)`.
- Banco: `OK (3 tests, 95 assertions)`.
- Las mismas cuatro baterías de la bandeja en SQLite dan los mismos números.

**Que las pruebas cubren el fallo** [C]:
- Con el `AssistedQueue.php` de `0c57d7c4`, `H9AssistedEligibilityTest` da `Failures: 8` de 10. Las 2 que pasan son los controles negativos.
- En la carrera, la relectura de antes (solo frenos) falla en `dnc` y en `pause`; el control pasa.
- m-1 mata las mutaciones «objetivo por fila» y «guion por fila».
- Todos los ficheros restaurados se compararon byte a byte con `cmp`.

## 5 · Coste de la página y del reclamo (PG 14 local, por HTTP, mediana de 5)

Banco: `docs/ultron-studio/ops/carga-bandeja/AssistedQueueLoadBench.php`. El comando de uso está en su cabecera.
- 5 estrategias y socios resolubles.
- Ana tiene 20 trabajos reclamados.
- Cada celda es «consultas · ms de pared», **antes (`0c57d7c4`) → ahora**.

| N | `unclaimed` (50) | `mine` (50) | `all` (20) | `claim` 201 | `unclaimed` + 20 000 DNC ajenos | Peor caso (cabeza retenida + 20 000 DNC) |
|---|---|---|---|---|---|---|
| 50 | 92·31.5 → **42·23.4** | 92·24.6 → **43·23.4** | 72·22.6 → **42·23.9** | 30·11.8 → 55·21.8 | 82·43.5 → **42·37.6** | 37·35.4 → 41·37.2 |
| 500 | 132·38.8 → **42·29.2** | 92·25.1 → **43·23.9** | 72·23.7 → **42·25.8** | 30·12.3 → 55·25.8 | 132·51.8 → **42·43.3** | 85·114.5 → 101·127.6 |
| 5 000 | 132·56.5 → **42·46.5** | 92·28.3 → **43·26.9** | 72·51.5 → **42·42.0** | 30·22.8 → 55·26.1 | 132·79.5 → **42·60.2** | 85·180.3 → 101·172.6 |

- **Por fila:** antes había 2 consultas por fila (traza y versión), más el guion en `mine`. Ahora no hay ninguna.
- **R3-2 encarece el reclamo:** +25 consultas por la relectura completa (la cadena unas 9, la foto unas 15 y el libro 1). El reclamo pasa de 22.8 a 26.1 ms con 5 000. Security había estimado unas 4.
- **R3-3 encarece cada tanda:** `subject.is_minor` mete `MembershipFacts` en la foto, +4 consultas por tanda. Por eso el peor caso pasa de 85 a 101.
- **Lo que crece con N no es por fila** [C, las sentencias más lentas del banco], con 5 000 trabajos:
  - `queueRows()`: unos 10 ms por tanda;
  - `viewParts()` (`select distinct`): unos 7 ms;
  - `LeadFacts::optedOutPhones()`, con 20 000 DNC: unos 8,5 ms por tanda, hasta 4 por página.

## 6 · Hallazgos fuera de alcance (no los he tocado)

- **F-1** (modo o canal asistido apagados), **F-2** (alcance canario) y **F-3** (plantilla que deja de estar aprobada): cerrados en la ronda 2 (§9).
- **F-4 · N6 `success_reached` / `context_changed` / `collections_overdue_blocks_commercial`** [L]: nadie vuelve a evaluarlos mientras la inscripción espera el resultado asistido. Es así por diseño; queda como nota.
- **F-5 · Para `database-specialist`:** `queueRows()` y `viewParts()` (§5) se ejecutan 1 + ≤ 4 veces por página sobre `outreach_jobs ⨝ enrollments ⨝ strategies` (`channel`, `mode`, `status`, `expires_at`, `id`). Con 5 000 asistidos tardan 7-10 ms. El CRM relee cada ≤ 15 s por pestaña.
- **F-6 · Para el dueño de `Facts`:** `optedOutPhones()` lee todos los DNC en cada tanda. Se puede memorizar por foto o filtrar por los números de la tanda (m-1 del revisor).

## 7 · El cambio de `Facts` que pedía R3-1

Hecho en la ronda 2, tal como se describía aquí (`866e856e`, §9.1).

## 8 · Lo que no he podido verificar

- **Producción:** nada (sin ssh). No sé cuántos prospectos DNC hay ni si existen asistidos por voz en vivo [S].
- **La batería completa:** no la he lanzado, como pide el encargo. Ni el CRM con los motivos nuevos.
- **Los tiempos del banco:** son de un Mac con PG local, dentro de la transacción de la prueba y sin red. No son los de producción.

## 9 · Ronda 2 (22-11-2026): el motivo de R3-1, F-1, F-2 y F-3

### 9.1 · Qué cambió

| Commit | Qué | Dónde |
|---|---|---|
| `866e856e` | **R3-1, motivo verdadero.** Clave nueva `voice.consent_revoked`: algún contacto de voz ACTIVO cuyo último evento válido es `revoked`. Sale de los eventos ya leídos, sin consultas nuevas. Con ella, revocado → `opted_out`; contacto retirado u otro caso → `policy_unknown` | `Facts/VoiceConsentFacts.php:37`, `:52`, `:128-130`; `Facts/FactNames.php:94`; `Run/AssistedQueue.php:574` |
| `4828f48a` | **F-1.** `openBrakes()`: los frenos de siempre (`openScopes()`, la primera consulta) y, si `! isLive() \|\| ! channelEnabled('assisted')`, `global`. Funciona como la PARADA GLOBAL: SQL, `withheld`, `brake`. Se lee en cada petición, así que al volver a encender vuelve. `OutreachConfig` no se modifica | `Run/AssistedQueue.php:375-382` |
| `df195258` | **F-2.** Fuera del alcance canario retiene. Aplica en la parte `canary` (N5) y en toda versión «solo canario», que es la línea base (N3). La entrada es `canary.{medio}` de la foto, sin consultas nuevas; `runningParts()` lleva el `canaryOnly` de cada parte. Motivo nuevo | `Run/AssistedQueue.php:120`, `:447`, `:518`, `:628`, `canaryOnly` en `runningParts()` |
| `62d44d98` | La carrera de R3-2 siembra el canario de su socio. Es una consecuencia de F-2 que no vi al cerrarlo: en SQLite esa prueba se salta | `tests/…/H9AssistedClaimRaceTest.php` |
| `2484057a` | **F-3.** `assistedScript()` solo da el guion si la plantilla sigue `APPROVED`, es íntegra y casa con la huella del sello. Esa huella es opcional, así que la llamada de `OutreachIsolationTest:499` no cambia. La bandeja retiene con motivo propio, detrás de la estrategia; lee una vez por (plantilla, huella) y por petición (`scriptOf()`) | `Run/OutreachPublishedStrategies.php:327`; `Run/AssistedQueue.php:107`, `:164`, `:441`, `:856` |

**Siembras de las pruebas, cambiadas a propósito (F-2):** creaban socios sin número en la parte canario, un estado que la escalera nunca deja salir. Ahora se siembran como canarios del medio con `RunWorld::canaryMember()`, aditivo (`Support/RunWorld.php:288`). No ha cambiado ninguna aserción de las baterías anteriores. La única cuenta exacta que cambia es `H9AssistedConditionsTest`: +1 consulta, la de la plantilla, con su propia aserción.

### 9.2 · Lo que pediste comprobar sobre `voice.consent_revoked`

- **(a) Fixtures del CRM:** el CRM no tiene que regenerar nada [C/L].
  - Ningún fichero de `tests/fixtures/outreach/**` nombra las claves crudas de la foto: `policyInputs` del catálogo son las entradas de la escalera.
  - El catálogo y su `VERSION` no cambian.
  - `FixturesSanityTest`: `OK (78 tests, 13805 assertions)`.
- **(b) `outreach:replay`:**
  - Ni `facts_json` (la foto de condiciones) ni la traza (la entrada de la escalera, `CoreProjection::policyInput()`) llevan la clave. Ninguna decisión guardada la contiene, así que ninguna cambia [L].
  - Prueba nueva, `test_r3_1_la_clave_nueva_no_entra_en_lo_que_se_guarda…`: ninguna decisión la lleva y todas dan `same` [C].
  - Una foto sin la clave se lee como «no consta»: `policy_unknown`, nunca `opted_out` (unitaria «R3-1: la revocación sin leer») [C].
  - Las pruebas de `replay` (`OutreachPolicyTest`, `H9ComplaintRecentTest`, `CancelDecisionTimelineTest`, `H9ShadowCanaryOnlyTest`): `OK (22 tests, 178 assertions)`.
- **(c) `LeadAndVoiceFactsTest`:** `OK (9 tests, 74 assertions)` en SQLite y en PG. Hay un caso nuevo:
  - revocó → `true`;
  - volvió a conceder, confirmado → `false`;
  - una concesión que no vale no tapa la revocación → `true`;
  - nunca concedió → `false`;
  - revocó en un contacto retirado → `false`;
  - los eventos se leen **una** vez para todo el lote.
- **El resto de `tests/Feature/Outreach/Facts`:** `OK (52 tests, 959 assertions)`.

### 9.3 · Pruebas (línea final) y mutaciones

**SQLite:**
- `tests/Unit/Outreach`: `OK (1042 tests, 53256 assertions)`.
- `tests/Feature/Outreach`: `OK, but some tests were skipped! Tests: 718, Assertions: 41711, Skipped: 167`. Son los mismos 167 saltos de diseño.
- Guardas: `OK (141 tests, 182977 assertions)`.

**PG 14:**
- `H9AssistedEligibilityTest`: `OK (20 tests, 271 assertions)`.
- `H9AssistedConditionsTest`: `OK (11, 185)`.
- `H9AssistedOptOutStrategyTest`: `OK (27, 309)`.
- `H9AssistedComplaintTest`: `OK (4, 39)`.
- `H9AssistedClaimRaceTest`: `OK (3, 84)`.
- `LeadAndVoiceFactsTest`: `OK (9, 74)`.
- `AssistedQueueOptedOutTest`: `OK (48, 49)`.
- Banco: `OK (3, 95)`.

**Mutaciones** (todas mueren; los ficheros, restaurados con `cmp`):
- revocado → `null`;
- F-1 sin la lectura de la configuración (3/3 caen);
- F-2 nunca restringido (3/3) y solo la parte canario, sin N3 (cae la de la parte publicada);
- F-3 sin la rama que retiene, y `assistedScript()` sin mirar el estado.

**Por PostgreSQL** han salido dos cosas que SQLite no vio:
- un contacto de voz retirado tiene otra forma (`vct_cipher_chk`, `vct_retire_chk`): las pruebas lo retiran con ella;
- la carrera necesitaba el canario de su socio (`62d44d98`).

**Banco** (consultas · ms, N = 5 000, ahora):
- `unclaimed` 44·46.5;
- `mine` 44·28.4;
- `all` 44·43.4;
- `claim` 58·26.1;
- `+20 000 DNC` 44·61.0;
- peor caso 102·179.6.

Las consultas son las mismas con 50, 500 y 5 000 trabajos, así que sigue sin haber consultas por fila. Respecto a la ronda 1 hay **+1** consulta por la plantilla de F-3. La consulta de canarios no es código nuevo: aparece porque las personas sembradas ya tienen número.

### 9.4 · Motivos de `contactWithheld` y de `outreach.assisted.claim_withheld.reason` (lista definitiva, en orden de prioridad)

Los 409 siguen siendo `not_in_queue`, `enrollment_closed` y `already_claimed`. El 201 de `claim` puede traer cualquiera de estos motivos. Los nuevos de este cierre van en **negrita**.

| # | Código | Texto sugerido para el CRM (solo lo ve quien lo reclamó) |
|---|---|---|
| 1 | `brake` | «Está parado: la parada global, un freno o la configuración (el modo o el canal asistido apagados). No contactes.» |
| 2 | `strategy_stopped` | «La estrategia ya no está en marcha (pausada, retirada o revertida). No contactes.» |
| 3 | **`template_not_approved`** | «La plantilla de este trabajo ya no está aprobada: no hay guion. No contactes.» |
| 4 | `opted_out` | «La persona pidió que no se le contacte, o revocó su autorización de llamadas. No contactes.» |
| 5 | `complaint_recent` | «La persona se quejó hace poco. No contactes.» |
| 6 | **`subject_minor`** | «La persona es menor de edad: no se le contacta por este canal.» |
| 7 | **`rne_check_missing`** | «Falta una consulta vigente del Registro de Números Excluidos: no llames hasta renovarla.» |
| 8 | **`canary_scope_mismatch`** | «Su número ya no es un número de prueba (canario) activo para este medio. No contactes.» |
| 9 | `policy_unknown` | «No se puede comprobar que se le pueda contactar (por ejemplo, su contacto de voz se retiró). No contactes.» |
| 10 | `complaint_unknown` | «No se puede comprobar si se quejó hace poco. No contactes.» |
| 11 | `expired` | «El plazo de este trabajo ya pasó.» |

- **Cambio de semántica:** `opted_out` incluye ahora la revocación de voz que consta.
- **Un motivo desconocido** el CRM lo sigue tratando como «no contactes».

### 9.5 · Lo que no he podido verificar (ronda 2)

- **El camino HTTP de los canarios** (`POST integrations/canaries/{uuid}/deactivate|revoke`): las pruebas escriben la fila con las mismas columnas que `StudioCanaryNumbers::deactivate()` (`:329`) y `revoke()` (`:358`), y PG acepta esa forma. No he podido montar las rutas del Studio en la prueba de la bandeja: sus rasgos chocan.
- **La retirada de una plantilla:** no hay ningún servicio que la haga; es la transición `APPROVED → RETIRED` de la base.
- **Una versión que no declare «solo canario»:** debería dejar de restringir en la parte `rest`. No lo he probado, porque exige una referencia de Studio de tipo `version` [L].
- **El CRM:** no lo he leído. No he encontrado el espejo local para comprobar que no lleva las claves crudas de la foto.
