# Informe de security · Cierre de la bandeja asistida (R3-1, R3-2, R3-3, F-1, F-2, F-3) en `cierre/bandeja`

**22-11-2026 · `security` · un pase sobre `git diff 0c57d7c4..cd735ff7` (10 commits), solo lectura.** Árbol `_worktrees/cierre-bandeja`. Vara de medir: `informe-security-bandeja-ronda3.md` y `informe-be-bandeja-r3.md` (§1-§9).
- **No he tocado** código, pruebas, CRM ni producción. Las sondas van en una copia clonada del `backend/` (`sec-bandeja-r3/copia/…/SecProbeR3Test.php`, en el directorio temporal). Lo único escrito en el repositorio es este informe.
- **Sin la skill `security-review`:** revisa los cambios pendientes del árbol actual, no un rango de commits de otro árbol. La lectura ha sido manual.

Marcas: **[C]** comprobado ejecutando · **[L]** leído · **[S]** supuesto.

## Veredicto

| | Veredicto |
|---|---|
| **(a)** Integrar y desplegar con el modo asistido APAGADO (como en producción) | **APTO** |
| **(b)** Encender el modo asistido en vivo | **APTO CON CONDICIONES**: C-1 y C-2 |

**Por qué (a):**
- solo retiene más que `0c57d7c4`, nunca menos [L, `withholdReasons()`; C, 7 baterías en SQLite y PG];
- no cambia ninguna decisión de la escalera ni nada de lo que se guarda (pregunta 8) [C];
- con el asistido apagado, F-1 retiene todo como `brake` (`AssistedQueue.php:378-380`) [C].

**Bloqueantes:** ninguno. La regla del propietario («deja de poder ofrecerse, reclamarse o ejecutarse») se cumple en todos los caminos que entregan `person` o el guion. Quedan dos huecos: la ventana inherente a una respuesta (R3-2 residual) y m-1, que no es de elegibilidad.

## Hallazgos

### CONDICIONES (antes del asistido en vivo)

**C-1 · El CRM y el contrato no conocen los cuatro motivos nuevos.**
- **Dónde:** el CRM que he leído (`_worktrees/subsanacion-20209/Iron-Body_Front`, `ce695d7`) admite 7 (`commercial-automation-api.types.ts:1148-1150`). El contrato dice «los siete motivos» (`ULTRON_STUDIO_H9_CONTRACT.md:1071-1072`, E11/E12).
- **Escenario:** `template_not_approved`, `subject_minor`, `rne_check_missing` y `canary_scope_mismatch` llegan como desconocidos y se pintan con el texto genérico (`ca-assisted.ts:251`).
- **Impacto:** el falla-cerrado aguanta (el servidor ya manda `person` y `script` en null [C], y el CRM los oculta si hay motivo, `ca-assisted.ts:242-247` [L]). Pero el reclamante no sabe qué hacer (renovar el RNE, reactivar el canario, publicar otra plantilla): retiene de más sin saber por qué (pregunta 4).
- **Corrección:** los 4 códigos y los textos de §9.4 del autor en el tipo y en `ASSISTED_WITHHELD_TEXTS`; E11, E12 y D9-9.5, a once motivos.

**C-2 · D-3 sigue abierta (es del propietario).**
- La pregunta del §2 del autor está bien formulada.
- Que la misma decisión escrita cubra también F-4 (`success_reached`, `context_changed`, `collections_overdue_blocks_commercial`).
- Y que la hora legal de la ejecución manual (N1 `calendar_*`) queda en manos del personal, como dice `ULTRON_STUDIO_H9_CONTRACT.md:651`: el trabajo vive hasta `JOB_TTL_HOURS` después de su hueco (`OutreachPlanner.php:596`).

### MENORES

**m-1 · La relectura tras el `UPDATE` no vuelve a mirar la inscripción ni el estado del trabajo** (`AssistedQueue.php:299-303`).
- **Escenario [C, P-C, SQLite y PG]:** la inscripción se cierra (`completed`/`success_reached`) entre la comprobación y el `UPDATE`. El 201 lleva `person` y el guion; después, `mine` ya no la enseña y la repetición da 409 `enrollment_closed`.
- **Impacto bajo:** el cierre que viene de la persona (`opted_out` desde otra estrategia) va en la MISMA transacción que el resultado que alimenta el libro (`OutcomeRecorder.php:128-176`). La relectura lo ve como `opted_out` [L].
- **Corrección:** tras `:299`, `requireOpenEnrollment((int) $reclamado->enrollment_id)` y exigir `status === 'started'`. Es +1 consulta y el mismo 409 que daría la repetición.

**m-2 · N3 `studio_reference_invalid` no se mira:** `runningParts()` (`:653`) no exige `$l->studio['referenceValid']`.
- **Escenario [L]:** la referencia del Studio pierde la integridad (`version_not_found`, `content_hash_mismatch`, `baseline_mismatch` tras un despliegue que cambie `StudioCatalog::hash()`, o `unreadable`: `OutreachPublishedStrategies.php:579-597`). La escalera niega a todos (`PolicyLadder.php:330`); la bandeja sigue entregando.
- **Corrección:** `&& $l->studio['referenceValid'] === true` en `:653`. Da `strategy_stopped` y 0 consultas.

**m-3 · `subject_minor` le cuenta al reclamante que la persona es menor** (Ley 1581, art. 7), y con `assisted_work` no se tiene por qué poder ver la ficha. Basta con que el CRM lo pinte como «no elegible por este canal», o que lo acepte el propietario.

**m-4 · Hueco de prueba:** `assertWithheldEverywhere()` (`H9AssistedEligibilityTest.php:244`) busca el número de voz (`555010`), pero no el de la ficha que siembra `canaryMember()` (`31…`). Tampoco sale [C, P-E]; conviene añadirlo a la aserción.

## Las diez preguntas

1. **R3-1: ningún camino entrega tras una revocación que consta** [C]: `unclaimed`, `mine`, `all`, la repetición y el resultado (`recordOutcome()` no devuelve contacto).
   - El historial por persona solo da el seudónimo (`OutreachReadModels.php:256`), y el tiempo real `outreach.*` sigue siendo `PendingTopic` [L].
   - Motivos: revocado → `opted_out` (`VoiceConsentFacts.php:128-130`, `AssistedQueue.php:574`); contacto retirado → `policy_unknown`; foto sin la clave → `policy_unknown` [C, unitaria].
   - Revocar un número mientras otro contacto activo la mantiene concedida no retiene, igual que la escalera (un contacto vivo por número, `vct_one_live`) [L].
2. **R3-2:** la relectura cierra lo que llega antes del `UPDATE` [C: carrera del autor, PG 3/84; P-A, revocación → `opted_out`].
   - Lo residual es una respuesta: lo que llega tras la relectura sale en ese 201, y ya no en `mine` ni en la repetición [C, P-B]. Aceptable; es la semántica del despachador.
   - Dos reclamos → 409 `already_claimed`, sin contacto [C, P-D].
   - La prueba de dos procesos prueba lo que dice: barrera tras la última lectura y antes del `UPDATE`, sin transacción envolvente. Falta m-1.
3. **R3-3:** el menor retiene en cualquier medio [C]. La edad desconocida no retiene, coherente con N1 (`PolicyLadder.php:265-266`) y con el contrato (`:880`, «no se infiere; lo decide la asesoría»). Si el RNE no se puede comprobar → `policy_unknown` [C].
4. **F-1, F-2 y F-3:** retienen en todos los caminos de 1 [C, `assertWithheldEverywhere`].
   - F-2 en la parte publicada «solo canario» es N3 exacto (`PolicyLadder.php:342-344`, la misma `studio.canaryOnly`, que falla en `true`): no retiene nada que la escalera dejara salir [L].
   - Que el motivo no llegue legible al CRM es C-1.
5. **La tabla**, abajo.
6. **Autorización:** solo el reclamante ve `person`, el guion y el motivo (`:820-839`) [C].
   - Ningún oráculo nuevo [C, P-E]: para otra cuenta, la fila y los dos `withheld` quedan idénticos tras revocar, volverse menor o perder el canario.
   - Lo libre que desaparece suma 1 a `withheld`, sin motivo (I-4, aceptado). Ningún teléfono [C].
7. **Falla cerrado:** con `voice_consents` ilegible, `QueryException` en `mine` y en la repetición, nunca entrega [C, P-F]. Medio fuera del conjunto o foto incompleta → `policy_unknown` [C]. Ningún `catch` en `AssistedQueue` [L].
8. **La clave nueva:** solo la lee `:574` [L, grep].
   - `OutreachCatalog::hash()` y `StudioCatalog::hash()` coinciden entre los ficheros de `0c57d7c4` y los de HEAD [C]; `replay`, 22/178 OK [C].
   - Es un booleano de eventos ya leídos: no trae datos personales nuevos, y el registro sigue siendo `{job, reason}` [L].
9. **Coste:** `claim` va en `throttle:ultron-studio-write` (30/min por credencial, 120/min por red) y la página en `read` (120 y 600) (`UltronStudioOutreachController.php:78-95`, `AppServiceProvider.php:611-620`). Con el peor caso del autor (102 consultas · 180 ms), el techo de red ocupa ≈ 1,8 núcleos de BD y exige `assisted_work`. Aceptable [L, tiempos sin repetir].
10. **Pruebas cambiadas:** ninguna aserción queda más floja [L]. `LIMPIA`, el RNE y `canaryMember()` acercan la siembra a lo que la escalera deja salir, y las cuentas exactas suben (+1, la plantilla). El único hueco es m-4.

## Señal por señal (`PolicyLadder.php` N1–N6 frente a la bandeja; rehecha)

| La escalera niega | ¿Bandeja? | Nota |
|---|---|---|
| N1 `consent_denied`, `do_not_contact`(`_sibling`), `commercial_opt_out`, `voice_excluded`, `unknown_subject` | Sí | [C] |
| N1 `no_consent_for_purpose_channel`, voz | Sí (R3-1) | [C] |
| N1 `no_consent_for_purpose_channel`, WhatsApp | No, por instrucción | Sin hueco [L]: quien escribe `consent_status` solo pone `granted` o `denied` (`MarketingConsentService.php:87`, `SalesAgentOrchestratorService.php:362`, `UltronCommitService.php:3347`) |
| N1 `rne_check_missing`, `subject_minor` | Sí (R3-3) | [C] |
| N1 `ContactBudget` (frecuencia, `calendar_*`) | No | Se reserva al salir; la hora de ejecución es del personal (C-2) |
| N2 frenos, `mode_off`/sombra, `global_suppression` | Sí (F-1) | [C] |
| N2 `human_in_control`, cerrojo, pausa, freno de voz, banderas Legacy | No | Fuera del encargo (D-3) |
| N3 `canary_only_declared` · N5 `canary_scope_mismatch` | Sí (F-2) | [C] |
| N3 `studio_reference_invalid` | **No** | **m-2** |
| N3 `catalog_incompatible` | Sí | `runnable()` exige contenido [L] |
| N3 `payment_links_disabled`, `template_violates_restriction` · N4 `template_category_mismatch` | No | Contenido inmutable (`usmt_forbid_immutable_update`); solo cambia con un despliegue [L] |
| N4 `channel_disabled`, `template_not_approved` | Sí (F-1, F-3) | [C] |
| N4 `no_voice_contact` | Sí, indirecto | Sin contacto activo no hay consentimiento → `policy_unknown` [C] |
| N4 `no_whatsapp_identity` | **No** | Perder el número tras salir no es una petición de la persona; anotarlo junto a F-4 |
| N4 `budget_zero`, `assisted_capacity_reached` | No | Cupo: ponerlo a 0 no para lo que ya salió (para eso, el freno `assisted`) |
| N5 cadena (`not_published`, `paused`, `chain_unreadable`) | Sí | [C] |
| N5 horario, ventana, toques, cupos, enfriamiento, guarda | No | Se miran al salir; `expires_at` acota |
| N6 `complaint_recent` | Sí | [C] |
| N6 `human_requested_recently` | No, a propósito (L9-66) | — |
| N6 `success_reached`, `context_changed`, `collections_overdue_blocks_commercial`, `purpose_conflict_today`, `lower_priority` | No (F-4) | A la decisión de C-2 |

## Lo que ejecuté

| Qué | Resultado |
|---|---|
| `H9AssistedEligibilityTest`, `AssistedQueueOptedOutTest`, `H9AssistedOptOutStrategyTest`, `H9AssistedConditionsTest`, `H9AssistedComplaintTest`, `LeadAndVoiceFactsTest` | SQLite y PG 14: 20/271, 48/49, 27/309, 11/185, 4/39 y 9/74 en los dos |
| `H9AssistedClaimRaceTest` | PG 3/84; en SQLite, 3 saltadas (de diseño) |
| `replay` (4 baterías), `FixturesSanityTest`, guardas (16 ficheros) | SQLite: 22/178, 78/13805 y 141/182977 |
| Sondas P-A a P-F (`SecProbeR3Test`, en la copia) | SQLite y PG 14: 6/6; resultados citados arriba |
| Hashes de los dos catálogos, ficheros de `0c57d7c4` frente a HEAD | Idénticos |

## Lo que no he podido verificar

- **Producción:** nada (sin ssh). Ni que hoy no haya asistidos en vivo [S].
- **El CRM desplegado:** he leído una copia (`subsanacion-20209`, `ce695d7`), no lo que sirve producción.
- **La carrera de la revocación con dos procesos:** solo en una conexión (P-A). El mecanismo es la misma relectura que cubren `dnc` y `pause` con dos procesos.
- **Una versión `canaryOnly=false`, las rutas HTTP de los canarios y los tiempos del banco:** solo por lectura. La batería completa no la he lanzado.
