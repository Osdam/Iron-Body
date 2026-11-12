# Informe de security · Las condiciones de la bandeja asistida (I-1, I-2, I-3, I-4) en `4a3d7eea`

**12-11-2026 17:05 UTC (12:05 de Bogotá) · `security` · solo lectura.**
- **Árbol:** `_worktrees/ultron-h8/Iron-Body/backend`, rama `feat/ultron-studio-h9`, HEAD `306b83e3`.
- **Qué reviso:** el commit LOCAL `4a3d7eea` (sin subir ni desplegar). `306b83e3`, que va encima, solo toca pruebas E2E de la voz y documentos: `git diff --stat 4a3d7eea HEAD -- backend/app backend/routes backend/config` sale vacío [C].
- **Qué no he tocado:** código, pruebas, contratos, el CRM ni producción. Las sondas están fuera del repositorio, en una copia del árbol del directorio temporal de la sesión (`…/scratchpad/backend-copia/tests/Feature/SecProbes/SecCondicionesProbeTest.php`).
- **Lo único que he escrito** es este informe. En el árbol ha aparecido además `informe-fe-bandeja-condiciones.md`, que es del agente frontend.

La vara de medir es `informe-security-bandeja.md` (I-1…I-4, m-3 y §4). Contrasto el commit con ella, no con lo que dice el informe de backend.

Marcas: **[C]** comprobado ejecutando · **[L]** leído en el código · **[S]** supuesto.

## Veredicto

| Condición | Veredicto |
|---|---|
| **I-1** (la queja después de reclamar) | **CERRADO** |
| **I-2** (el plazo) | **CERRADO** |
| **I-3** (los frenos) | **CERRADO CON CONDICIONES** |
| **I-4** (la página y `meta.withheld`) | **CERRADO** |
| **`4a3d7eea`** | **APTO** para desplegar hoy |

**Por qué es apto para desplegar hoy:**
- solo retiene más, nunca menos: no hay ninguna entrada con la que ofrezca o entregue algo que `155e7adc` no entregaba [L + C];
- las tres baterías del cambio pasan en SQLite y en PostgreSQL 14, y las guardas del H9 también [C];
- hoy no hay trabajos asistidos en vivo [S: según el encargo; no lo he mirado en producción].

**Cada condición:**
- **I-1.** Ninguna ruta le devuelve a quien reclamó el enlace o el guion de alguien que se quejó después [C, barrido HTTP de las 11 rutas de lectura y de las dos de escritura de la bandeja, en los dos motores]. La persona suprimida de comercial o cobranza queda en `complaint_unknown` [C].
- **I-2.** El borde es coherente entre el SQL y el PHP, con microsegundos y con el reloj en otra zona. `expires_at` no puede ser nulo. No hay ningún camino para reclamar ni recibir el contacto de un trabajo vencido [C].
- **I-3.** Lo que pedí está hecho [C]:
  - la PARADA GLOBAL, el freno del canal `assisted` (incluido `legal_hold`) y el de la estrategia retienen;
  - un freno que se suelta y se vuelve a abrir, también;
  - con los frenos ilegibles falla cerrado de verdad, sin `catch`, y por HTTP da 500 sin SQL.

  **La condición:** la pausa de la estrategia en el Studio es un «freno» que `openScopes()` no ve. Pausada, la bandeja sigue ofreciendo y entregando el contacto (N-2). Además, el propietario tiene que decidir por escrito D-3.
- **I-4.** La página se llena por detrás de lo retenido. El cursor no salta ni repite. `meta.withheld` no dice nada de una persona que no se supiera ya por el 409. La peor página son 21 consultas [C]. Quedan dos defectos de operación, no de seguridad: m-3 y m-8.

**Nuevo y preexistente, condición antes del asistido en vivo (N-1):** la bandeja no vuelve a mirar el «no me contacten» que llega **después** de la salida. Es el `do_not_contact` que marcan ULTRON o el agente cuando la persona lo pide por WhatsApp, más el consentimiento denegado. Pasa lo mismo que con la queja antes de M-5: el trabajo se ofrece, se reclama y entrega el contacto [C].

---

## Hallazgos por gravedad

### BLOQUEANTES
Ninguno, ni para desplegar `4a3d7eea` ni como defecto del commit.

### IMPORTANTES (condición previa a publicar la primera estrategia con canal asistido en vivo; no bloquean el despliegue de hoy)

**N-1 · La bandeja no mira el «no me contacten» posterior a la salida: `do_not_contact`, consentimiento denegado, *opt-out* comercial y supresión global.**
- **Ubicación:**
  - `app/Services/Outreach/Run/AssistedQueue.php:354`: la foto solo pide `complaint_created`;
  - `:311-332`: `withholdReasons()` no tiene ningún motivo de N1;
  - `:191-198`: `claim()`.
- **De dónde viene el cambio [L]:**
  - Cuando la persona pide que no la contacten, el agente marca `do_not_contact` (`SalesAgentOrchestratorService.php:358-367`, `execMarkDoNotContact`). También lo hace ULTRON (`UltronCommitService.php`, herramienta `mark_dnc`, `:2820-2826`).
  - Las dos escriben `marketing_leads.do_not_contact = true` y `consent_status = denied`, y nada más.
  - `MarketingLeadCommercialObserver::updated()` solo emite eventos cuando cambia `status`.
  - **Ninguna inscripción del H9 se entera.** El *opt-out* que llega como resultado del H9 sí cierra las inscripciones de la persona (`OutcomeRecorder.php:157-176`), y el trabajo sale de la bandeja. El que llega por la conversación normal de WhatsApp, no.
- **Escenario [C, sonda Q3, SQLite y PG 14]:**
  1. Salen dos trabajos asistidos. Ana reclama uno.
  2. Las dos personas quedan con `do_not_contact = true`.
  3. Ana sigue recibiendo `person: {kind: member, id}` y el guion.
  4. El otro trabajo se le sigue ofreciendo a Beto, que lo reclama y recibe `person` y el guion.
- **Ventana:** hasta `expires_at`, es decir, como mucho 24 h desde el hueco (`OutreachPlanner.php:596`). I-2 ya la acota.
- **Por qué importa:**
  - es el mismo daño que M-5 e I-1, con una señal más fuerte: la persona dijo con sus palabras que no la contacten (Ley 2300 de 2023 y Ley 1581);
  - la escalera lo niega en N1 y N2 (`do_not_contact`, `commercial_opt_out`, `consent_denied`, `global_suppression`; `PolicyLadder.php:235-318`), pero la bandeja no vuelve a mirarlo.
- **Gravedad:** importante. No lo introduce `4a3d7eea`.
- **Corrección propuesta:**
  1. Un motivo nuevo, p. ej. `opted_out`, con prioridad `brake` > `opted_out` > `complaint_recent` > `complaint_unknown` > `expired`.
  2. La regla, la misma de la escalera:
     - `lead.do_not_contact` o `lead.do_not_contact_sibling` distinto de `false`;
     - `lead.commercial_opt_out` en finalidad comercial;
     - el consentimiento del medio del trabajo `denied`;
     - `SubjectSuppressions::isSuppressed(clave, finalidad, medio)`.
  3. Que falle cerrado como N1: `null` retiene.
  4. Los hechos de política, en la MISMA foto de la queja (`CoreProjection::selectionFor` con esos hechos): ninguna consulta más, salvo la supresión.
  5. Es aditivo para el CRM: un valor desconocido ya cuenta como retenido (`commercial-automation-parse.ts:1366-1369`). Aun así, conviene darle su texto.
- **Prueba:** el DNC llega después de salir → no se ofrece, 409 `not_in_queue`, y el reclamante pierde `person` y `script`.

**N-2 (la condición de I-3) · Pausar la estrategia en el Studio no retiene la bandeja.**
- **Ubicación:**
  - `StudioStrategyPublishing::pause()` (`app/Services/UltronStudio/StudioStrategyPublishing.php:177`) no abre ningún freno de `outreach_brakes`. Fuera de `Services/Outreach/Run`, a `OutreachStop` solo lo nombran el controlador de la ejecución y la consola [L: `grep`].
  - `AssistedQueue` no mira el estado de la cadena.
- **Escenario [C, sondas Q4 y Q4b, SQLite y PG 14]:**
  - **Pausada, con el conciliador real después:**
    - las inscripciones siguen `active`;
    - el trabajo libre se ofrece y Beto lo reclama con `person`;
    - Ana conserva `person` y el guion.
  - **Revertida:**
    - hasta la pasada del conciliador, lo mismo (Q4);
    - después, las inscripciones quedan `canceled` y la fila sale (Q4b);
    - pero la repetición de `claim()` sigue entregando el contacto (m-1).
- **Por qué importa:** ante un error de audiencia, lo natural para el propietario es pulsar «Pausar» en el Studio. Los canales automáticos se paran (N3/N5), pero el humano sigue contactando hasta 24 h. Es exactamente la asimetría que I-3 corrigió para `outreach_brakes`.
- **Gravedad:** importante.
- **Corrección propuesta (una de las dos antes del asistido en vivo):**
  - **(a) Preferida:** en `page()` y en `claim()`, leer una vez el estado de la cadena de las estrategias de la página, con lo que ya da `LiveStrategy`: `paused()`, `runnable()` y `chainReadable()`. Tratar como `brake` la estrategia pausada, apagada, revertida, archivada o con la cadena ilegible (falla cerrado).
  - **(b) Mínima:** que pausar en el Studio eche también el freno de la estrategia, o que el diálogo de pausa del CRM y el runbook digan sin rodeos: «Pausar no para la bandeja asistida; para eso, el freno de la estrategia».

### MENORES

**m-1 · La repetición de `claim()` no mira si la inscripción sigue abierta.**
- **Ubicación:** `AssistedQueue.php:191-194`. La repetición va antes de `requireOpenEnrollment()` (`:197`). Es preexistente.
- **Escenario [C, sondas Q2 y Q4b, SQLite y PG]:** la inscripción se cierra (un `opted_out` en otra estrategia, la 1581 con `--apply` antes del SQL del dueño o la reversión). Entonces:
  - `mine` ya no enseña la fila y el resultado da 409 `enrollment_closed`;
  - pero `POST …/claim`, con cualquier `clientRequestId`, devuelve `person` y el guion, con `contactWithheld: null`.
- **Gravedad:** menor. Solo le llega a quien ya lo tuvo, y hay que llamar a la API a mano. Pero es justo el caso del *opt-out*.
- **Corrección:** en la repetición, con la inscripción fuera de `QUEUE_ENROLLMENT_STATUSES`, `person` y `script` en null y un motivo `closed`, o 409 `enrollment_closed`. Mejor aún: que `mine` conserve esa fila con el aviso, en vez de hacerla desaparecer sin explicación (m-4).

**m-2 · La carrera entre la lectura de frenos y el `UPDATE` de `claim()` (`:189` → `:199`).**
- **Escenario [C, sonda Q5, SQLite y PG]:** la PARADA GLOBAL se echa después de leer los frenos y antes del `UPDATE`.
  - La respuesta es 201, con `person` y el guion.
  - La siguiente lectura de `mine` ya da `brake`.
  - Es m-2 del informe anterior, ahora para el freno.
- **Corrección barata:** tras el `UPDATE`, volver a leer `openScopes()` (una consulta) y, si frena, responder con `contactWithheld: 'brake'`. El trabajo queda reclamado; no hay que deshacer nada.

**m-3 · Con el tope de 200 la página puede salir vacía con trabajo ofrecible detrás, y el CRM dice que no hay.**
- **Lo comprobado [C, sonda Q9]:**
  - 200 retenidos por queja en cabeza y 60 ofrecibles detrás;
  - la página sale con `items: 0` y `withheld: 200`, en 21 consultas;
  - el CRM pinta «Ahora no hay trabajos asistidos que se puedan ofrecer» (`ca-assisted.ts:86-87`).
- **Quién lo provoca:** 200 quejas reales dentro del plazo, o `commercial.events_enabled` apagado (todo comercial y cobranza «sin datos» tapa lo de servicio).
- **Gravedad:** de operación, no de seguridad.
- **Corrección:** un `meta.scanCapped: true` al llegar a `MAX_SCAN`, y que el CRM diga «puede haber más trabajo detrás».

**m-4 · El CRM no relee la bandeja solo.**
- **Ubicación:** `commercial-automation-session.ts:388-391` y `:702-713`. Solo relee tras reclamar, registrar o un 409 [L].
- **Qué pasa:** el reclamante sigue viendo el enlace y el guion que ya tenía pintados hasta que recarga. La garantía del servidor no cambia, pero el aviso llega tarde. Y una fila que sale de `mine` porque se cerró la inscripción desaparece sin un «no lo contactes».
- **Lo que ya hace bien el CRM [L]:**
  - `persona()` y `guion()` dan null con cualquier motivo (`ca-assisted.ts:203-210`);
  - un valor desconocido cuenta como retenido.
- **Corrección:** releer al volver el foco a la pestaña, o con el tema SSE de trabajos. Junto con m-1, enseñar el aviso de inscripción cerrada.

**m-5 · D-3, los frenos ajenos al H9 (cerrojo de ULTRON, pausa del agente, freno de voz).**
- **Lo que dice el contrato [L]:** N2 aplica `ultron_abort_latch` y `marketing_agent_paused` **por medio** WhatsApp, y `voice_braked` por medio voz (`ULTRON_STUDIO_H9_CONTRACT.md:502`; `PolicyLadder.php:297-305`). `assisted:whatsapp` y `assisted:voice` tienen esos medios (`OutreachCatalog.php:146-153`).
- **Consecuencia:** el despachador **no** saca un asistido con esos frenos echados, pero la bandeja no retiene los que ya salieron. La afirmación «la misma regla que N2» del commit es, por tanto, incompleta.
- **No es un hueco de exposición:** esos frenos protegen sistemas automáticos (el agente de IA, la telefonía del H8) que el humano no usa.
- **Gravedad:** menor. Es una decisión del propietario, por escrito, antes del asistido en vivo.
- **Mi recomendación:** no añadirlos a la bandeja, pero dejar escrito en D9-14 que, para parar a las personas, sirve la PARADA GLOBAL o el freno del canal `assisted`, no esos tres.
- **De N2 quedan además** `human_in_control`, que para el asistido no aplica (ya hay una persona), y `global_suppression`, que cubre N-1.

**m-6 · H-A, `GET outreach/status` → `live.assistedQueue` (`OutreachReadModels.php:69-70`).**
- Cuenta todo `started` asistido sin resultado del personal: vencidos, frenados, retenidos y de inscripciones cerradas.
- Es un número, sin datos personales y sin oráculo: no dice más que `meta.withheld` y es agregado.
- Con D-2 (nada los cierra) solo crece. Un operador podría creer que el freno no funcionó.
- **No es condición de seguridad.** Recomendado: alinearlo con la bandeja, o rotularlo «sin resultado del personal».

**m-7 · El oráculo de `meta.withheld` con `limit`: aceptable.**
- **Lo comprobado [C, sonda Q8, SQLite y PG]:** Beto había visto A, B y C.
  - Con `limit=1` recibe `[A]` y `withheld: 0`.
  - Con `limit=2` recibe `[A, C]` y `withheld: 1`.
  - Así sabe que B se retuvo.
- **Por qué es aceptable:** es lo mismo que ya daba el 409 de reclamar (m-1 del informe anterior):
  - B es una fila seudónima, sin `person`;
  - el número no distingue queja de «sin datos»;
  - los frenos se cuentan aparte y en bloque;
  - quien reclamó ya ve su motivo en `contactWithheld`.

  No es un hallazgo nuevo.

**m-8 · Operación (preexistente): en la vista `all`, que es la que abre el CRM por defecto, los reclamados de otra persona ya vencidos ocupan la página.**
- **Lo comprobado [C, sonda Q11, SQLite y PG]:**
  - 20 trabajos reclamados por Ana y vencidos, y 4 ofrecibles detrás;
  - `page(beto, 'all', 20)` devuelve las 20 de Ana, ninguna ofrecible y `withheld: 0`;
  - `unclaimed` da las 4.
- **Por qué:** `queueRows()` (`:396`) admite cualquier reclamado en `all`, vencido o no. Con D-2 se quedan ahí hasta que se acaba la ventana de la inscripción (≤ 60 días).
- **Gravedad:** no expone nada: `person` va en null y el motivo no se le dice a Beto. Pero esconde trabajo legítimo sin aviso, el mismo defecto que I-4.
- **Corrección:** en `all`, dejar fuera los reclamados por otra persona y vencidos, u ordenar primero lo sin reclamar.

---

## 1 · I-1 en detalle: ¿algún camino a `person` o `script` tras la queja?

**No** [C, sonda Q1: Ana reclama, la IA marca la queja con el emisor real y luego se recorre todo por HTTP, en SQLite y en PG 14].

| Camino | Resultado tras la queja | Evidencia |
|---|---|---|
| `GET assisted/queue` (`all`, `mine`, `unclaimed`) | Sin `person`, sin guion, sin `m:<id>`, sin teléfono; `contactWithheld: complaint_recent` en la fila de Ana | [C] Q1 |
| `POST assisted/{uuid}/claim` (repetición) | Igual, con `replayed: true` | [C] Q1 y pruebas |
| `POST assisted/{uuid}/outcome` | `{result, outcome, transition, replayed}`, sin `person` | [C] Q1 |
| `outreach/status`, `metrics`, `jobs`, `enrollments`, `enrollments/{uuid}`, `decisions/{uuid}`, `people/{ref}/timeline` | Sin `person`, sin guion, sin `m:<id>`, sin teléfono | [C] Q1 |
| SSE (`OutreachJobsTopic`) y los modelos de lectura | Sin cambios desde `155e7adc`: `git diff 155e7adc HEAD` no toca `OutreachReadModels`, `SubjectTimeline` ni `Run/Stream` | [L] |
| `show()` | Sin ruta; el único que usa `AssistedQueue` es su controlador | [L] `grep` |
| Persona suprimida `x:<id>` | En comercial y cobranza, `complaint_unknown`: ni enlace ni guion. En servicio la queja no aplica por diseño (D3); la orden 1581 cierra la inscripción y solo queda m-1 (guion = texto de la plantilla, sin datos; `person` en null porque la clave no es válida) | [C] prueba; [L] `OutreachSuppressSubjectCommand.php:74-82` |
| La vista de otra persona | `person` y `script` en null y `contactWithheld: null`: no se le cuenta la queja | [C] prueba |
| El CRM | Con un motivo no pinta la ficha ni el guion aunque llegaran; un valor desconocido retiene | [L] `ca-assisted.ts:203-210`, `parse.ts:1366-1369` |

**Lo que queda, y es aceptable:**
- lo que el reclamante ya vio antes de la queja;
- la página que no se relee sola (m-4);
- la repetición con la inscripción cerrada (m-1), que no es el caso de la queja: con queja, la repetición ya retiene [C].

## 2 · I-2 en detalle: el borde del plazo

| Comprobación | Resultado | Evidencia |
|---|---|---|
| `expires_at` nulo | Imposible: `timestamp('expires_at')` sin `nullable`, y `ojob_window_chk expires_at > not_before` | [L] migración `:915`, `:561` |
| Medio segundo antes del plazo | Se ofrece | [C] Q7 |
| Medio segundo después | No se ofrece; reclamar → 409 `not_in_queue` | [C] Q7 |
| En el plazo exacto, con el reloj de la aplicación en `America/Bogota` | No se ofrece; 409 | [C] Q7 |
| SQL frente a PHP | El SQL compara `expires_at > sql(ahora)`, con los segundos truncados; el PHP, `instant(expires_at) <= ahora`. Coinciden porque `expires_at` tiene segundos enteros (`timestamp(0)` sin zona, escrito en UTC por `OutreachStore::sql`, `:463-466`) | [L] + [C] Q7 |
| Reclamado y vencido | Se queda con `expired`, sin enlace ni guion, y admite el resultado | [C] prueba |

No hay camino para reclamar ni recibir el contacto de un trabajo vencido. D-1 (sin margen) me parece bien desde seguridad.

## 3 · I-3 en detalle: los frenos

| Pregunta del encargo | Resultado | Evidencia |
|---|---|---|
| ¿Hay un alcance que `openScopes()` no devuelva? | La base solo admite `global`, `strategy` (uuid en minúsculas) y `channel` (los tres de `CHANNELS`). `openScopes()` pone la estrategia en minúsculas y `ladderBrakes()` también. Si el SQL y el PHP no coincidieran, el PHP retiene igual (falla seguro) | [L] migración `:612-613`; `OutreachStop.php:114-139` |
| ¿Un freno de otra estrategia u otro canal? | No retiene, por diseño | [C] prueba |
| ¿Un freno soltado y vuelto a abrir? | Retiene otra vez: es una fila nueva con `released_at IS NULL` | [C] Q10 |
| ¿El canal con otro nombre? | La base no lo admite (`obrk_scope_ref_chk`) | [L] |
| ¿La pausa del Studio? | **No retiene: N-2** | [C] Q4 y Q4b |
| ¿Los frenos ajenos al H9? | No retienen: m-5 (D-3) | [L] |
| ¿Falla cerrado de verdad? | Ningún `catch`, valor por defecto ni `safe()` en `AssistedQueue` ni en el controlador. Con `outreach_brakes` ilegible, `queue`, `mine`, `claim` y la repetición dan **500** con `{"message":"Server Error"}`, sin SQL (`app.debug = false`), y nada se reclama | [L]; [C] Q6 (los dos motores) y prueba |
| ¿Se leen antes de decidir y de escribir? | Sí: una lectura por petición, antes de las filas, de la foto y del `UPDATE` | [C] prueba |
| ¿La carrera lectura → `UPDATE`? | Existe y entrega el contacto una vez: m-2 | [C] Q5 |

## 4 · I-4 en detalle: la página

| Pregunta | Resultado | Evidencia |
|---|---|---|
| ¿Se llena por detrás de lo retenido? | Sí. Lo vencido y lo frenado sin reclamar no se leen (SQL); la queja va por tandas de 50 | [C] pruebas (55 retenidos que cruzan una tanda) |
| ¿El cursor salta u ofrece dos veces? | No: `(expires_at, id)` estricto e igual al `ORDER BY`. Probado con 55 filas de igual `expires_at` que cruzan el corte. No hay cursor de cliente, así que no se duplica entre peticiones | [L] `:409-414`; [C] pruebas, en SQLite y PG |
| ¿`meta.withheld` es un oráculo? | No añade nada al 409 (m-7) | [C] Q8 |
| ¿El tope esconde trabajo ofrecible? | Sí, sin señal: m-3 (operación). La vista `all` tiene además m-8 | [C] Q9, Q11 |
| ¿Una petición cara? | La peor página son 21 consultas (4 tandas × foto + frenos + recuento), 15–45 ms en local. Con 50 filas devueltas se suman las 2 × 50 de `dto()` (H-C, preexistente). La ruta tiene un límite de 120/min por credencial y 600/min por red (`AppServiceProvider.php:610-613`) | [C] Q9; [L] |

## 5 · Regresiones de `155e7adc`

| Qué | Resultado | Evidencia |
|---|---|---|
| La queja sin reclamar sigue reteniendo (409) y falla cerrado con la fuente apagada | Sí | [C] `H9AssistedComplaintTest` 4/4 en SQLite y PG |
| `human_requested` sigue fuera (L9-66) | Sí: el trabajo se ofrece | [C] misma prueba, `:132-134` |
| `outreach.assisted.claim_withheld` sin datos personales | `{job, reason, known}` para la queja, y `{job, reason}` para el freno y el plazo; las pruebas lo fijan con aserción exacta | [C] pruebas; [L] `:294-297` |

## 6 · Lo que el agente dejó como decisión (§7 de su informe)

| | Mi lectura | ¿Antes del asistido en vivo? |
|---|---|---|
| **D-1** (sin margen tras el plazo) | Bien | No |
| **D-2** (cerrar los `started` vencidos) | No hace falta para la seguridad: ya no entregan el contacto. Pero origina m-6 y m-8 | No; recomendado |
| **D-3** (frenos ajenos) | Incoherente con la letra de N2 por medio, sin exposición (m-5) | **Sí, como decisión escrita** |
| **D-4** (`withheld` es una cota inferior) | Bien; m-3 es su cara operativa | No |
| **H-A** (`live.assistedQueue`) | m-6 | No |
| **H-B** (el guion de la persona suprimida) | Cerrado, en comercial y cobranza | — |

## 7 · Qué tiene que estar hecho antes de publicar la primera estrategia con canal asistido en vivo

1. **N-1**, la bandeja vuelve a mirar el «no me contacten»:
   - motivo de retención con `do_not_contact`, `do_not_contact_sibling`, *opt-out* comercial, consentimiento denegado del medio y supresión global, fallando cerrado;
   - prueba con el DNC que llega después de salir, en la vista sin reclamar, en la del reclamante y en la repetición.
2. **N-2**, la pausa y la reversión del Studio retienen la bandeja (opción a), o como mínimo pausar echa el freno de la estrategia o el CRM y el runbook lo dicen sin rodeos (opción b).
3. **D-3**, decidida por escrito por el propietario en D9-14.
4. **Recomendados** (baratos, no condición):
   - m-1: la repetición con la inscripción cerrada;
   - m-2: releer los frenos tras el `UPDATE`;
   - m-4: que el CRM relea la bandeja;
   - m-3 y m-8: la página que no oculte trabajo.
5. **Pedir a `tester`** los casos negativos:
   - DNC o *opt-out* por WhatsApp después de salir;
   - estrategia pausada y revertida con trabajos `started`;
   - un freno echado entre leer y reclamar;
   - la repetición con la inscripción cerrada;
   - `all` con reclamados ajenos vencidos.

## 8 · Lo que ejecuté

| Qué | Motor | Resultado |
|---|---|---|
| `tests/Feature/Outreach/Run/H9AssistedConditionsTest.php` | SQLite / PG 14 (`ultron_studio_lead_ola5`, desde la copia) | `OK (11 tests, 182 assertions)` / ídem. El informe de backend dice 10 pruebas y 174 aserciones; el commit tiene 11 |
| `tests/Feature/Outreach/Run/H9AssistedComplaintTest.php` | SQLite / PG 14 | `OK (4 tests, 39 assertions)` / ídem |
| `tests/Feature/Outreach/Run/OutreachApiTest.php` | SQLite / PG 14 | `OK (7 tests, 137 assertions)` / ídem |
| `tests/Feature/Outreach/Guards` | SQLite | `OK (19 tests, 12168 assertions)` |
| Sonda `SecCondicionesProbeTest`, Q1–Q11 y Q4b (fuera del repositorio) | SQLite y PG 14 | `OK (12 tests, 72 assertions)` en los dos; las salidas citadas arriba. Q6 en PG, con un punto de guardado por petición |

## 9 · Lo que no he podido verificar

- **Producción:** nada. Ni que no haya trabajos asistidos en vivo, ni las banderas, ni que el 500 de producción salga sin detalle. Se supone `APP_DEBUG=false` [S].
- **La carrera m-2 con dos conexiones reales:** la simulé en una sola conexión, con un oyente que echa el freno entre las dos sentencias.
- **El CRM:** solo lo leí, sin ejecutarlo. Lo está cambiando otro agente ahora mismo.
- **La batería completa:** no la he lanzado, por indicación del encargo.
