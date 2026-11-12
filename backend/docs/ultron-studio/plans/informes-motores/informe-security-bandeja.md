# Informe de security · La bandeja asistida vuelve a mirar la queja (M-5), la simulación usa la configuración (M-7) y el Centro de supervisión (`36e8a472`)

**12-11-2026 04:53 UTC (11-11, 23:53 de Bogotá) · `security` · solo lectura.** Árbol `_worktrees/ultron-h8/Iron-Body/backend`, rama `feat/ultron-studio-h9`, HEAD `36e8a472`. Reviso dos commits locales, sin subir ni desplegar, encima de `4277f128`: `155e7adc` (backend) y `36e8a472` (lead). No he tocado código, pruebas, contratos, guiones de `ops/`, producción ni la red. Las sondas están fuera del repositorio, en una copia del árbol en el directorio temporal de la sesión. `git status` cambió durante la revisión solo por ficheros del agente devops (`RUNBOOK_PRIMERA_LLAMADA.md` e `informe-devops-apertura-arneses.md`) y por este informe.

Marcas: **[C]** comprobado ejecutando · **[L]** leído en el código · **[S]** supuesto.

## Veredicto

| Qué | Veredicto |
|---|---|
| **M-5** (la bandeja no volvía a mirar la queja) | **CERRADO CON CONDICIONES** |
| **M-7** (la simulación usaba constantes) | **CERRADO** |
| **`36e8a472`** (Supervisión) | **APTO** para desplegar esta noche |

- **M-5.** El hueco que describí está cerrado: un trabajo **sin reclamar** de alguien que se quejó después de salir ya no se ofrece y no se reclama. Es el único camino a `person` y a `script`, y falla cerrado con todas las entradas raras que he probado. Queda abierta la otra mitad del mismo riesgo: la queja que llega **después de reclamar** (R-1). Además, el trabajo retenido vuelve a ofrecerse a los 30 días sin mirar su vencimiento (H-1). Las dos cosas, y un hueco adyacente que he encontrado (los frenos no retiran nada de la bandeja, §1.4), tienen que estar resueltas **antes de publicar la primera estrategia con canal asistido en vivo** (§4). No bloquean el despliegue de `155e7adc`: hoy no hay trabajos asistidos en vivo [S, según L9-114; no lo he verificado en producción], y el cambio solo retiene, nunca abre.
- **M-7.** Comparé simulación y ejecución con 4 860 combinaciones de configuración, incluidas las inválidas: ninguna diferencia [C]. La excepción nueva no es alcanzable desde una ruta del producto [C].
- **`36e8a472`.** Nadie que lee estas rutas gana información: todo lector de `supervision/state` ya podía leer `GET admin/marketing/agent`, que enseña el alcance de ULTRON, el freno y la pausa [C]. El id del canario no sale [C]. El `reason` de texto libre sale igual que antes [C], y no hay entrada del usuario que llegue a una consulta o a una etiqueta. `whoResponds()` dice «responde» sin que pase nada en tres configuraciones raras (menor, §2.4); en el sentido peligroso, decir «nadie responde» mientras algo responde, no he encontrado ningún caso.

---

## Hallazgos por gravedad

### BLOQUEANTES
Ninguno.

### IMPORTANTES (condición previa al asistido en vivo; no bloquean esta noche)

**I-1 · R-1: la queja que llega DESPUÉS de reclamar no retira nada.**
- **Ubicación:** `app/Services/Outreach/Run/AssistedQueue.php:91` (solo se vuelven a mirar las filas sin reclamar), `:122-125` (la repetición de `claim()` va antes de la comprobación) y `:295-296` (`person` y `script` para quien reclamó).
- **Escenario [C, sonda P4, en SQLite y en PG 14]:**
  1. Ana reclama el trabajo.
  2. La IA marca una queja en la conversación de esa persona.
  3. `queue('mine')` le sigue devolviendo a Ana `person: {kind: member, id}` y el guion (60 caracteres). Repetir `claim()` también devuelve `person`.
  4. Ana puede abrir la ficha y llamar o escribir con finalidad comercial o de cobranza a quien acaba de quejarse.
- **Cuánto dura la ventana:** no tiene límite, por H-1. Quien reclamó conserva el trabajo hasta registrar el resultado o hasta que cierra la inscripción (hasta 60 días). La carrera entre la comprobación y el `UPDATE` de `claim()` (§1.3) es un caso particular de esto.
- **Gravedad:** importante. Es el mismo daño que M-5, cambiando solo el momento. Hoy no aplica: no hay trabajos asistidos en vivo [S].
- **Corrección propuesta**, aditiva, sin quitarle al reclamante su resultado (requisito 3):
  1. En `queue()`, pasar también las filas reclamadas por `withheldByComplaint()`. Coste: 0 consultas si la página ya tenía filas sin reclamar; si no, las mismas 4 fijas.
  2. Si la queja da `true` o `null`, la fila se queda, pero con `person: null`, `script: null` y un campo nuevo `contactWithheld: 'complaint_recent' | 'complaint_unknown'`. Lo mismo en la respuesta de la repetición de `claim()`.
  3. `recordOutcome()` no cambia.
  4. El CRM ya trata `person` y `script` como opcionales. Solo tiene que enseñar el aviso: «Se quejó después de que lo reclamaras: no lo contactes; registra el resultado que corresponda».
- **Riesgo que queda:** lo que el reclamante ya vio antes de la queja. Es aceptable.

**I-2 · H-1: la bandeja no mira el vencimiento; un trabajo retenido vuelve a ofrecerse con la decisión de hace un mes.**
- **Ubicación:** `AssistedQueue.php:75-82` (`queue()`) y `:118-131` (`claim()`). Ninguno de los dos mira `j.expires_at`.
- **Escenario [C, sonda P5, en SQLite y en PG]:**
  1. La persona se queja justo después de que el trabajo salga, y el trabajo queda retenido.
  2. A los 31 días la inscripción sigue `active` (`window_ends_at` 01-12).
  3. El trabajo se ofrece otra vez con `dueBy` = 18-11, vencido hace 29 días. Se reclama (`replayed: false`) y entrega `person`.
- **Por qué importa:** la política (horario, frecuencia, consentimiento del medio) se aplicó al despachar, hace un mes, y antes de la queja. En cobranza, el horario de la Ley 2300 de 2023, art. 3, no se vuelve a mirar al reclamar. La escalera, si reevaluara, habría sacado la inscripción con `exit_complaint`.
- **Gravedad:** importante. Agranda R-1 y, además, mantiene los retenidos en cabeza de la página (I-4).
- **Corrección propuesta:**
  - `->where('j.expires_at', '>', now)` en `queue()` y en `claim()`, con 409 `not_in_queue`;
  - opcionalmente, que el reclamante deje de ver `person` y `script` pasado `expires_at` más un margen;
  - y que el conciliador cierre los `started` asistidos vencidos.

  El plazo exacto es decisión del propietario (D-3 del informe de backend). Desde seguridad, lo que pido es que exista uno.

**I-3 · Adyacente, preexistente: la PARADA GLOBAL y el freno del canal `assisted` (incluido `legal_hold`) no retiran nada de la bandeja.**
- **Ubicación:** `AssistedQueue.php` no consulta `OutreachStop` en ningún punto [L: `grep`]. El controlador solo mira la bandera maestra (`UltronStudioOutreachAssistedController.php:26-31`).
- **Escenario [C, sonda P8, en SQLite]:**
  1. Se abren un freno `channel/assisted` con motivo `legal_hold` y la PARADA GLOBAL (`openScopes()` = `{global: true, channels: [assisted]}`).
  2. Beto sigue viendo el trabajo en la bandeja y lo reclama. Recibe `person`.
- **Por qué importa:** D9-14 promete que con la parada «no se reclama ni sale nada nuevo». Para el canal humano, el contacto real ocurre **después** de reclamar. Si se frena por un error de audiencia, un pico de *opt-out* (lo abre el monitor) o una retención legal, el personal puede seguir llamando a quien ya está en la bandeja.
- **No lo introduce `155e7adc`.** Lo incluyo porque el encargo pide otros caminos al contacto, y porque es condición del asistido en vivo.
- **Corrección propuesta:**
  - en `queue()` y en `claim()`, leer `OutreachStop::openScopes()`: global, `channel=assisted` o la estrategia del trabajo;
  - con un freno que aplica, no ofrecer ni reclamar (409 `brake_open`, o `not_in_queue` si no se quiere tocar el contrato), y fallar cerrado si los frenos no se pueden leer, como N2;
  - para las filas ya reclamadas, el mismo tratamiento que R-1 (`person`/`script` en null y el aviso).

**I-4 · El `limit` se aplica ANTES de retener: los retenidos ocupan la página y esconden trabajo ofrecible.**
- **Ubicación:** `AssistedQueue.php:82` (`->limit(...)` en SQL) frente a `:89-96` (el filtro en PHP).
- **Escenario [C, sonda P1, en SQLite y en PG]:** tres trabajos, y se quejan las dos personas de los dos primeros en el orden de la bandeja. `queue('all', 2)` devuelve `[]`; `queue('all', 3)` devuelve el tercero.
- **Por qué crece:** el orden es `expires_at, id`. Los retenidos tienen el `expires_at` más antiguo, y por H-1 se quedan en cabeza hasta 30 días. Con 20 retenidos (el `limit` por defecto de la API) la bandeja sale vacía aunque haya trabajo detrás. Pasa lo mismo, en bloque, con `commercial.events_enabled` apagado. No hay cursor ni aviso.
- **Gravedad:** de seguridad es menor, porque falla del lado seguro. Lo dejo aquí porque es operativamente serio: oculta trabajo legítimo sin decir por qué.
- **Corrección propuesta:**
  - leer por tandas hasta llenar `limit` o hasta un tope (p. ej. 4 × `limit`, siempre ≤ `BatchSnapshot::MAX_SUBJECTS` = 500);
  - y añadir `meta.withheld` (cuántos se retuvieron, sin decir de quién) para que el CRM pueda decir «hay trabajos retenidos».

  Arreglar H-1 lo alivia, pero no lo resuelve.

### MENORES

**m-1 · Oráculo del 409: no expone nada nuevo.**
- **Lo que se ve:**
  - El cuerpo es idéntico al de un trabajo que no está `started`: `{"ok":false,"code":"not_in_queue","message":"Ese trabajo ya no está en la bandeja."}` 409 [C, sonda P6, SQLite y PG].
  - `started` es un estado final: el disparador `ojob_closed_is_final` impide cambiarlo [C, al intentarlo en la sonda]. Por eso, para un trabajo que alguien vio en la bandeja, `not_in_queue` al reclamar (en lugar de `enrollment_closed` o `already_claimed`) significa «queja reciente o sin datos».
- **Por qué no expone nada nuevo:**
  - Sin reclamar, la fila no lleva `person` (seudónimo `subjectRef`, no enumerable).
  - Quien tiene `commercial_automation.view` ya ve `complaint_recent` en las decisiones por `subjectRef` (`OutreachReadModels.php:187-226`), y quien atiende el Inbox ve la queja en la conversación.
  - Una diferencia de tiempos (la retención hace 4 consultas más) diría lo mismo y no añade nada.
- **El registro** `outreach.assisted.claim_withheld` (`AssistedQueue.php:221`) lleva `{job: uuid, reason, known}`, sin datos personales [C, en la prueba]. Ligar el uuid a la persona exige leer `outreach_jobs.subject_key` en la base. Quien lee los registros de producción (devops) ya tiene ese acceso.
- **Aceptable.**

**m-2 · La carrera entre la comprobación y el `UPDATE` de `claim()` (`:129` → `:131`).**
- Una queja confirmada en esos milisegundos (los de una foto: 4 consultas) no se ve. El resultado es idéntico al de una queja que llega justo después de reclamar.
- No añade riesgo propio: es R-1 en pequeño. La corrección de R-1 la cubre, porque `mine` vuelve a mirar en cada lectura. No hace falta un bloqueo.

**m-3 · `commercial.events_enabled` apagado: desaparecen todos los asistidos sin reclamar de comercial y cobranza.**
- **Lo comprobado [C]:** la prueba existente `test_sin_datos…` lo cubre. Es el mismo lado seguro que N6: con la fuente apagada, el despachador tampoco saca nada nuevo (N6 = `null` → deny).
- **Aceptable.**
- **Pero es silencioso:** la bandeja sale vacía (y, por I-4, entera) sin explicación. `GET outreach/status` ya dice `sources.commercialEvents: false` (`OutreachReadModels.php:61-62`) [L]. Basta con que el CRM lo enseñe junto a la bandeja, o con el `meta.withheld` de I-4.

**m-4 · `whoResponds()` dice «responde» sin que el emisor deje pasar nada (§2.4).** Tres casos comprobados y uno leído. Cosmético en la pantalla, sin efecto en ningún envío.

**m-5 · El simulador conserva otros valores fijos que la ejecución lee de la configuración** (H-4 del informe de backend, fuera de M-7) [L]:
- `COLLECTIONS_BLOCK_DAYS = 15` frente a `COMMERCIAL_AUTOMATION_COLLECTIONS_BLOCK_DAYS` (`config/outreach.php:141`), configurable por entorno: puede divergir en los dos sentidos;
- `HUMAN_REQUESTED_DAYS = 30` frente a `outreach.human_requested_days`, que hoy es un literal (`config/outreach.php:145`): no puede divergir sin cambiar código.

Corrección: la misma de M-7, un contexto.

---

## 1 · M-5 en detalle

### 1.1 · ¿Hay otro camino a `person` o a `script` de un trabajo retenido sin reclamarlo?

**No** [L, rutas una a una; C donde se indica].

| Camino | ¿Llega a `person` / `script`? | Evidencia |
|---|---|---|
| `GET outreach/assisted/queue` (`all`, `unclaimed`) | No: las filas sin reclamar ya iban con los dos en null (`dto()`, `:282`, `:295-296`), y ahora además el retenido no sale | [L] + tests [C] |
| `GET …/queue?filter=mine` | Solo lo que reclamó quien mira (R-1) | [C] P4 |
| `POST …/{uuid}/claim` | Es la puerta: retiene con queja, sin datos o con `x:<id>` | [C] tests + P2 |
| `POST …/{uuid}/outcome` sin reclamar | 403 `not_claimer` (`:175-177`), y no devuelve `person` | [L] |
| `show()` | Sin ruta (`grep`) y sin `viewerId`: `person`/`script` en null | [L] |
| `outreach/jobs`, `enrollments/{uuid}`, `decisions/{uuid}` (`view`) | `subjectRef`, estados y trazas, nunca la clave interna ni el guion (`OutreachReadModels.php:187-314`) | [L] |
| `outreach/people/{subjectRef}/timeline` (`timeline_view`) | Códigos e instantes, sin persona ni guion (`SubjectTimeline.php:10-31`, `:105-140`) | [L] |
| SSE (`Run/Stream/*`) | `OutreachJobsTopic` emite canal, uuid, estado y fecha; nunca `subject_ref` | [L] `:36-40` |
| `assistedScript()` desde otro sitio | Solo lo llama `AssistedQueue::dto()` | [C] `grep` |
| Simulación con muestra real | Seudónimo y traza; ligarlo exige otro permiso (M-6 del informe anterior, sin cambios) | [L] |

### 1.2 · Falla cerrado: ¿alguna entrada con la que se ofrezca o se reclame el trabajo de alguien que SÍ se quejó?

**Ninguna nueva.** Solo las que ya tiene N6.

| Entrada | Resultado | Evidencia |
|---|---|---|
| Fuente de eventos apagada | `null` → retenido; 409; registro `known: false` | [C] test existente |
| Persona sin resolver (borrada, sin ficha) | `EngineFacts::events` da `null` → retenido | [L] `EngineFacts.php:103-110` |
| Clave suprimida `x:<id>` (1581) | No pasa `SubjectKey::isValid`, no se pide en la foto → `null` → retenido en comercial y cobranza; 409 | [C] P2 (SQLite y PG); [L] `:243-251` |
| Finalidad `service` | No frena, por diseño (D3), igual que la escalera | [L] |
| `complaint_days = 0` | No retiene: el interruptor deliberado, el mismo que N6 | [C] test existente |
| `complaint_days` negativo, texto, decimal, > 365 | El valor por defecto (30) o 365, nunca 0 (M-1 ya corregido, `OutreachConfig.php:233-238`) | [C] P7 |
| `complaint_purposes` vacía, sin `commercial`, mal escrita, anidada | Las por defecto (todas menos `service`) (`OutreachConfig.php:253-262`) | [C] P7 |
| Queja de hace más de la ventana | No frena: es la regla de N6. La lectura cubre 730 días, más que el máximo de 365 configurable | [L] `OutreachCatalog.php:105`, `EngineFacts.php:121` |
| Muchos eventos (truncado M-8) | No aplica aquí: la foto pide solo `complaint_created`, en orden de más reciente a más antiguo | [L] `:247` |
| Página > tope de la foto | 50 ≤ 500 (`BatchSnapshot::MAX_SUBJECTS`) | [L] |
| Socio y prospecto no vinculados | No frena: el riesgo conocido de N6 (plan §1.4). La bandeja usa el mismo resolutor que el despachador | [L] |
| Queja no registrada (fallo del emisor, eventos apagados durante un incidente, anteriores al despliegue sin *backfill*) | No frena: M-3 del informe anterior, igual en N6 | [L] |
| Queja después de reclamar | No frena: R-1 (I-1) | [C] P4 |

**La misma regla que la ejecución:** `LadderInputBuilder::complaintRecently()` sobre `CoreProjection::snapshot($s)->events`, con la finalidad de la inscripción (`e.purpose`, atada a la estrategia por `oenr_purpose_matches_strategy`) [L]. En `queue()` se vuelven a mirar solo las filas sin reclamar; en `claim()`, después de la repetición idempotente, como se diseñó.

### 1.3 · La carrera

Ver m-2. No importa más allá de R-1.

### 1.4 · Lo que el agente dejó abierto, con mi gravedad

| | Gravedad | ¿Antes del asistido en vivo? |
|---|---|---|
| **R-1** (queja después de reclamar) | Importante (I-1) | **Sí.** Es el mismo daño que M-5. La corrección es barata y no rompe el contrato |
| **H-1** (sin vencimiento) | Importante (I-2) | **Sí.** Sin ella, R-1 no tiene plazo y lo retenido vuelve a ofrecerse con datos de hace un mes |
| Frenos (I-3, nuevo) | Importante | **Sí.** Una `legal_hold` que no frena al canal humano no es una retención |
| Página corta (I-4) | Menor de seguridad, serio de operación | Recomendado |
| `events_enabled` apagado (m-3) | Menor, aceptable | Basta con enseñarlo |

## 2 · `36e8a472` en detalle

### 2.1 · Quién lee

- **Dos cerraduras [L]:** la puerta (`AuthorizationMap`: `SupervisionController` → dominio `marketing` → GET = `marketing.view`, `AuthorizationMap.php:259`, `:1051-1065`) y, dentro, `guardView()` (`SupervisionController.php:758-771`): `MarketingInboxAuthorizationService::CAP_VIEW`, que tienen solo los roles completos y los comerciales (`MarketingInboxAuthorizationService.php:72-95`).
- **Quién pasa [C, sonda S1]:**

| Rol | `state` | `GET admin/marketing/agent` | `activity` |
|---|---|---|---|
| Super Admin, Administrador | 200 | 200 | 200 |
| Recepción, Administrativo, Entrenador y «Asesor comercial», por defecto | 403 | 403 | 403 |
| Recepción con `marketing.view` concedido en Roles | 200 | 200 | 200 |
| Administrativo con `marketing.view` | **403** (`inbox_forbidden`) | 200 | 403 |

**Todo lector de `state` puede leer `agent`.** Supervisión es igual de estricta o más.

### 2.2 · ¿Los campos nuevos dan algo que no se tenía?

**No** [C + L].

| Campo nuevo | Dónde estaba ya |
|---|---|
| `agent.status` (`ultron_canary`, `ultron_all`, `brake`, `paused`, `legacy_*`, `off`, `unknown`), `label`, `short_label` | `GET admin/marketing/agent` → `MarketingAgentSwitch::present()` (`:213-231`): `paused`, `ultron.enabled`, `ultron.scope` (canary/all) y `ultron.brake_engaged`. S1 lo enseña con Recepción: `{"enabled":true,"scope":"canary","brake_engaged":false}` |
| `legacy_enabled` | Era el antiguo `agent.enabled` |
| `conversations.attended_by_agent` | Un recuento derivado de `handled_by_ai` y de la conversación del canario: 0 o 1 en canario, sin id |
| **El id del canario** | **No sale:** la sonda S1 lo busca en el JSON de `state` (987654) y en la clave `canary_conversation` y no aparece. `activity` ya devolvía antes el `conversation_id` de cada decisión, así que la conversación de ULTRON se podía deducir; eso no cambia |

### 2.3 · El `reason` de texto libre y la entrada del usuario

- **[C, S3]:** el `reason` de una toma manual (`'<b>texto libre</b> 3001234567'`) sale tal cual, igual que antes, cuando el código devolvía `$r->reason` sin tocarlo. Un código conocido (`complaint`) sale traducido («Queja.»). No gana exposición.
- **Preexistente, sin cambios:** el texto libre puede llevar un teléfono que haya escrito el personal. Es I-2 del informe anterior, ya atendido en la supresión 1581 (`supresion-1581.sql`, 18 referencias a `commercial_events`/`marketing_ai_actions`) [C, `grep -c`]. Angular escapa la interpolación [S: no lo he comprobado en el CRM].
- **Sin entrada nueva [L]:**
  - `activity` valida `hours`, `limit` y `conversation_id` como antes (`:98-102`);
  - las etiquetas son constantes del servidor (`REASON_LABELS`, `match`);
  - `state` no lee nada de la petición;
  - el `canary_conversation_id` de `conversationCounters()` viene de la configuración y se convierte a `int` (`SupervisionService.php:275-279`).
- **`decisionActor()`** lee `metadata.source === 'manual'`, que solo escribe el servidor (`MarketingManualTakeoverService.php:96`, `:143`). La ruta interna de n8n **no** puede ponerlo: arma `metadata` solo con `intent`, `objection` y `temperature` (`InternalMarketingController.php:140-144`) [L]. No se puede firmar como «empleado» desde n8n. n8n sí puede escribir un `action_type` libre (≤ 60), que sale con su clave, igual que antes.

### 2.4 · `whoResponds()` frente a `UltronEventEmitter::ineligibleReason()`

**En el sentido peligroso son coherentes [L]:**
- **El orden es el mismo:** interruptor → freno → pausa → canario (`SupervisionService.php:73-97`; emisor `:47-87`).
- **Las fuentes son las mismas:** `UltronAbortLatch::engaged()` y `MarketingAgentSwitch`.
- **Error de lectura:** `unknown`, `responds: false`.
- **ULTRON y el análisis local:** en producción son excluyentes al arrancar (`AppServiceProvider.php:269-289`).
- **No he encontrado** un estado que diga `off`, `paused`, `brake` o `legacy_propose` mientras algo responde.

**Al revés, decir «responde» sin que pase nada (menor, m-4)** [C, sonda S2, salvo el último caso, que es L]:

| Caso | `state` (status, enabled, attended) | Emisor | Corrección |
|---|---|---|---|
| a. `MARKETING_ULTRON_CANARY_CONVERSATION_ID=0` o un texto (`config/marketing.php:83-86` → `(int)` = 0) | `ultron_canary`, true, 0 | `not_canary_conversation` en todas | Con canario ≤ 0 o una conversación que no existe: `responds: false` y «canario mal configurado». **No** convertir 0 en null en la configuración: abriría ULTRON a todas |
| b. Canario = conversación cuyo prospecto pidió no contactar | `ultron_canary`, true, **1** | `do_not_contact` | Que el recuento del canario mire también `lead.canReplyReactively()`, el canal `whatsapp` y que haya prospecto |
| c. (al revés) Canario tomado de forma **no manual** | `ultron_canary`, true, **0** | `null` (pasa) | Contar `human_takeover_source <> 'manual'` como atendida |
| d. `marketing.ultron.webhook_url` o `automation.webhook_secret` vacíos | `ultron_*`, true | El evento nace, pero el job lo salta (`SendUltronEventToN8n.php:61-71`) | Opcional: `responds: false` si falta la configuración de n8n |

Ninguno cambia un envío: es la pantalla.

## 3 · M-7 en detalle

- **Simulación ≡ ejecución [C, sonda P7, en SQLite y en PG]:**
  - El barrido: 15 valores de `complaint_days` (`null`, -1, 0, 1, 7, 30, `'30'`, `'0'`, 400, 30.0, `true`, `false`, `[]`, 365, 366) × 12 de `complaint_purposes` (`null`, `[]`, `['service']`, `['commercial']`, `['collections']`, las dos, con `'foo'`, anidada, un texto, `'commercial '` con espacio, asociativa, las tres) × 3 finalidades × 9 historiales de eventos (`null`, vacío, queja de hace 1, 8, 30, 31 y 366 días, una queja futura, solo `human_requested`).
  - Para cada combinación, `StudioStrategySimulator::complaintRecently($foto, …, complaintContext())` frente a `LadderInputBuilder::complaintRecently($foto->events, …)`.
  - **4 860 combinaciones, 0 diferencias**, y ninguna excepción: `complaintContext()` siempre devuelve un entero de 0 a 365 y una lista de textos.
- **La excepción nueva no sale por el producto [C + L]:**
  - el único llamador de `run()` es `StudioStrategySimulations::simulate()` (`:127`) [C: `grep`];
  - `simulate()` pone `'complaint' => self::complaintContext()` siempre (`:111`), y el `?array $context` de `simulate()` es el del validador, no el del simulador [L];
  - si un llamador nuevo la provocara, sería un 500 en una ruta del Studio, nunca una simulación con valores equivocados [L].
- **Las mismas filas de entrada:** la muestra real pide siempre `complaint_created` y `human_requested` (`StudioStrategySimulations.php:402-403`), igual que `LiveStrategy::uses()` (`:222-223`) [L].
- **Fuera de M-7:** m-5 (otros valores fijos) y H-5 del informe de backend (`inputHash` sin la configuración de la queja): menores.

## 4 · Qué tiene que estar hecho antes de publicar la primera estrategia con canal asistido en vivo

1. **R-1 (I-1):**
   - en el servidor, `person`/`script` en null y `contactWithheld` para las filas reclamadas con queja `true` o `null`, también en la repetición de `claim()`;
   - en el CRM, el aviso;
   - pruebas: la queja después de reclamar quita `person` y `script` y deja registrar el resultado.
2. **H-1 (I-2):**
   - `expires_at > now` en `queue()` y en `claim()`, o la regla de plazo que decida el propietario;
   - prueba: un retenido que sale de la ventana de la queja con `dueBy` vencido no se ofrece.
3. **Frenos (I-3):**
   - la bandeja y `claim()` respetan los frenos global, de canal `assisted` y de estrategia, y fallan cerrado si no se pueden leer;
   - prueba con `legal_hold`.
4. **Página (I-4):** llenar la página a pesar de los retenidos, o `meta.withheld`. Recomendado.
5. **Que el CRM enseñe la fuente de eventos apagada** junto a la bandeja (m-3). Recomendado.
6. **Pedir a `tester`** casos negativos de lo anterior, más uno de `x:<id>` en la bandeja (hoy solo lo cubre mi sonda P2).

## Lo que ejecuté

| Qué | Motor | Resultado |
|---|---|---|
| `tests/Feature/Outreach/Run/H9AssistedComplaintTest.php` | SQLite / PG 14 (`ultron_studio_1581_voz`, desde la copia) | OK 4/39 / OK 4/39 |
| `tests/Feature/UltronStudio/Strategies/UltronStudioStrategyComplaintSimulationTest.php` | SQLite / PG 14 | OK 7/267 / OK 7/267 |
| `tests/Feature/Commercial/SupervisionStateTruthTest.php` | SQLite / PG 14 | OK 12/37 / OK 12/37 |
| `tests/Feature/Commercial/SupervisionActivityLabelsTest.php` | SQLite / PG 14 | OK 4/416 / OK 4/416 |
| Sonda `SecBandejaProbeTest` P1, P2, P4, P5, P6, P7 (fuera del repositorio) | SQLite y PG 14 | OK 6/22 en los dos; las salidas citadas arriba |
| Sonda `SecBandejaProbeTest` P8 (frenos) | SQLite | OK; bandeja y reclamación con los dos frenos abiertos |
| Sonda `SecSupervisionProbeTest` S1, S2, S3 | SQLite | OK; las tablas de §2 |

## Lo que no he podido verificar

- **Producción, nada:** ni que no haya trabajos asistidos en vivo, ni las banderas, ni el canario real [S].
- **El CRM:** ni cómo pinta el 409, ni el `limit` que pide a la bandeja, ni que escape el `reason` libre [S].
- **La sonda de frenos (P8) y las de Supervisión,** solo en SQLite. La lógica no depende del motor [L].
- **La batería completa:** no la he lanzado, por indicación del lead.
- **Las sondas** viven en la copia del árbol del directorio temporal de la sesión (`backend-copia/tests/Feature/SecProbes/`) y no van al repositorio.
