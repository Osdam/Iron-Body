# Informe de backend · Cierre de la bandeja asistida: R3-1, R3-2, R3-3 y m-1

**22-11-2026 · `backend`.** Rama `cierre/bandeja` (sale de `0c57d7c4` = `main`), árbol `_worktrees/cierre-bandeja`. Sin push, sin producción, sin red.
Commits: `26685ce9` (código y pruebas) · `60e691b7` (carrera PG) · `3755aeb5` (banco de carga) · este informe.
Marcas: **[C]** comprobado ejecutando · **[L]** leído · **[S]** supuesto.

## Veredicto

| | Estado |
|---|---|
| **R3-1** revocar la voz después de salir | **CERRADO en la retención** [C]. **Salvedad:** el motivo es `policy_unknown`, no `opted_out`: la foto no distingue revocar de un contacto desactivado y eso vive en `Facts/**` (cambio exacto en §7) |
| **R3-2** lo que llega entre la comprobación y el `UPDATE` | **CERRADO** [C], SQLite y PG 14 con dos procesos |
| **R3-3** menor y RNE caducado | **CERRADO** [C] (directiva del 21-11) |
| **m-1** consultas por fila | **CERRADO** [C]: 42 consultas por página con 50, 500 y 5 000 trabajos |

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
| N1 `no_consent_for_purpose_channel`, por **voz** | **Sí (R3-1)** | `policy_unknown` | `H9AssistedEligibilityTest::test_r3_1_…`; unitaria «R3-1: …» |
| N1 `no_consent_for_purpose_channel`, por WhatsApp | No, por instrucción (ventana de 24 h; la baja llega como `consent_denied`) | — | unitaria «R3-1: WhatsApp…»; `test_r3_1` (el de WhatsApp sigue) |
| N1 `voice_excluded` | Sí, por voz | `opted_out` | `test_n1_la_exclusion_de_voz…` |
| N1 `rne_check_missing` | **Sí (R3-3)**, por voz | `rne_check_missing` / `policy_unknown` | `test_r3_3_la_consulta_al_rne…`, `test_r3_3_el_rne_caducado_no_retiene…whatsapp` |
| N1 `subject_minor` | **Sí (R3-3)** | `subject_minor` | `test_r3_3_el_menor…`, `test_r3_3_la_edad_que_no_se_sabe…`, `test_r3_3_la_prioridad…` |
| N1 `unknown_subject` | Sí | `policy_unknown` | `test_n1_sin_poder_comprobarlo…` |
| N2 `master_flag_off` | Sí (409 `outreach_disabled` en toda la ruta) | — | `OutreachIsolationTest` (banderas por defecto) |
| N2 `outreach_brake_global/channel/strategy` | Sí | `brake` | `H9AssistedConditionsTest::test_i3_la_parada_global…`, `test_m2_…`, `test_mr1_…` |
| N2 `global_suppression` | Sí | `opted_out` | `test_n1_la_senal…` [opt_out_word]; `test_un_trabajo_de_una_persona_suprimida…` |
| N2 `human_in_control` | No: no aplica (ya hay una persona) | — | — |
| N2 `ultron_abort_latch` · `marketing_agent_paused` · `voice_braked` · `legacy_autonomy_on` · `legacy_followups_active` | No: **D-3** | — | — |
| N5 `strategy_not_published` · `strategy_paused` · `chain_unreadable` | Sí | `strategy_stopped` | `test_n2_la_estrategia_pausada…`, `test_n2_…revertida_retirada_archivada_o_ilegible`; carrera [pause] |
| N6 `complaint_recent` | Sí | `complaint_recent` / `complaint_unknown` | `H9AssistedComplaintTest` (4); `test_i1_…` |
| N6 `human_requested_recently` | No, a propósito (L9-66); solo retiene con `opt_out_word` | — | `test_n1_la_senal…` [opt_out_word] |
| Plazo del trabajo | Sí | `expired` | `test_i2_vencido…` |
| R3-2: lo que llega antes del `UPDATE` (DNC, pausa, menor) | Sí | el que toque | `test_r3_2_…` (SQLite y PG) y `H9AssistedClaimRaceTest` (PG) |

**Lo que N1–N6 decide al salir y la bandeja no vuelve a mirar:** presupuesto y cupos, horario, ventana, enfriamiento, guarda, Studio y catálogo, plantilla, canario, `success`, `audience` y cartera. No son una petición de la persona. Los que pueden cambiar después de salir están en §6: F-1 a F-4.

**D-3, la pregunta exacta para el propietario:** «Los frenos ajenos al H9 que N2 aplica por medio son el cerrojo de abortos de ULTRON y la pausa del agente de mercadeo en WhatsApp, el freno de voz en la voz, y las dos banderas del motor anterior. ¿Deben retener también en la bandeja los asistidos que ya salieron? Retener significa no ofrecerlos, no dejarlos reclamar y quitar el enlace y el guion a quien ya los reclamó. ¿O se deja escrito en D9-14 que, para parar a las personas, sirven la PARADA GLOBAL y el freno del canal `assisted`, y no esos frenos?»

## 3 · Motivos para el CRM (sin tocar su repositorio)

- **Nuevos** en `contactWithheld` y en `outreach.assisted.claim_withheld.reason`:
  - `subject_minor`: la persona es menor y no se le contacta por este canal.
  - `rne_check_missing`: falta una consulta vigente al RNE. Se arregla renovándola (`POST voice/rne-checks`).
- **Cambia el alcance de `policy_unknown`:** ahora incluye también el consentimiento de voz que ya no está concedido, revocado incluido, y el RNE o el contacto de voz que no se pueden leer.
- **El 201 de `claim`** puede traer ahora cualquier motivo de la lista. Antes solo podía traer `brake`.
- **Los códigos 409** no cambian.
- Hoy el CRM trata un motivo desconocido como «no contactes» (revisor, ronda 3). Con eso es seguro, pero conviene darles texto propio.

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

- **F-1 · `mode_off` (N2) y `channel_disabled` / `channel_not_live` (N4) del asistido** [L]:
  - si se cambia la configuración después de salir, la bandeja sigue ofreciendo; solo la maestra apagada da 409;
  - el camino operativo para parar a las personas (la PARADA GLOBAL y el freno `assisted`) sí retiene;
  - propuesta para decidir: en `withholdReasons()`, `! isLive() || ! assistedEnabled()` → `brake`.
- **F-2 · El alcance canario** (N5 `canary_scope_mismatch`, N3 `canary_only_declared`) [L]:
  - un número canario desactivado después de salir no retiene en la parte `canary`;
  - `canary.{medio}` ya viene en la foto.
- **F-3 · El guion de una plantilla que dejó de estar aprobada** [L]:
  - `OutreachPublishedStrategies::assistedScript()` (`:323`) mira el canal, pero no el estado `APPROVED`;
  - una plantilla rechazada después de salir sigue dando su guion.
- **F-4 · N6 `success_reached` / `context_changed` / `collections_overdue_blocks_commercial`** [L]: nadie vuelve a evaluarlos mientras la inscripción espera el resultado asistido. Es así por diseño; queda como nota.
- **F-5 · Para `database-specialist`:** `queueRows()` y `viewParts()` (§5) se ejecutan 1 + ≤ 4 veces por página sobre `outreach_jobs ⨝ enrollments ⨝ strategies` (`channel`, `mode`, `status`, `expires_at`, `id`). Con 5 000 asistidos tardan 7-10 ms. El CRM relee cada ≤ 15 s por pestaña.
- **F-6 · Para el dueño de `Facts`:** `optedOutPhones()` lee todos los DNC en cada tanda. Se puede memorizar por foto o filtrar por los números de la tanda (m-1 del revisor).

## 7 · El cambio exacto que pide R3-1 para decir `opted_out` (fuera de mis ficheros)

- **`app/Services/Outreach/Facts/VoiceConsentFacts.php`:**
  - `:44` `POLICY`: añadir `'voice.consent_revoked'`.
  - En el bucle de `:117`, junto a `:119`: si `$eventos[$id]` tiene algún evento `VoiceConsentRule::valid($e, false)` y `! VoiceConsentRule::hasConsent($eventos[$id], false)`, entonces `$politica['voice.consent_revoked'] = true`. Eso quiere decir que el último evento válido es `revoked`. No hace ninguna consulta nueva.
- **`app/Services/Outreach/Facts/FactNames.php:89-95`:** añadir la clave. Si no, `BatchSnapshotBuilder.php:151` la filtra.
- **Por qué no vale otra vía:** la foto no expone los ids de las fichas de la persona, así que leer `voice_consents` desde `Run` duplicaría el emparejamiento persona ↔ contacto de `VoiceConsentFacts`.
- **Riesgo:** cambia la forma de `policy` en la foto (`SubjectFacts::toArray()`). Hay que revisar `LeadAndVoiceFactsTest` y las trazas.
- **Después**, en `AssistedQueue.php:520`, poner `$s->policy('voice.consent_revoked') === true ? true : null`. Además:
  - `test_r3_1` pasa a esperar `opted_out`;
  - la unitaria «R3-1» gana un caso con la clave a `true`.

## 8 · Lo que no he podido verificar

- **Producción:** nada (sin ssh). No sé cuántos prospectos DNC hay ni si existen asistidos por voz en vivo [S].
- **La batería completa:** no la he lanzado, como pide el encargo. Ni el CRM con los motivos nuevos.
- **Los tiempos del banco:** son de un Mac con PG local, dentro de la transacción de la prueba y sin red. No son los de producción.
- **R3-1 con el motivo `opted_out`:** depende de §7.
