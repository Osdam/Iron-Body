# Plan · Quejas, consentimiento de socios y cuatro ojos por regla

**Fecha:** 11-11-2026. **Agente:** `architect`. **Árboles:** backend `_worktrees/ultron-h8/Iron-Body/backend` (`feat/ultron-studio-h9`, `3161d3a3`), CRM `_worktrees/ultron-h8/Iron-Body_Front` (`85c62d2`). Todo en solo lectura: `grep`, `sed` y `git log`. No ejecuté pruebas ni toqué producción. [C] = comprobado leyendo el código; [P] = lo dice un documento de producción o de cierre y no lo he repetido; [SUPOSICIÓN] = sin verificar.

Contexto común: los envíos automáticos siguen cerrados por `OutreachSendGate::SENDS_ENABLED_IN_CODE = false` (contrato §3.2). Ninguno de los tres huecos afecta a la sombra. Los tres son **condición previa para abrir W9**.

## 0 · Resumen para decidir la semana

| # | Hueco | Tamaño | ¿Esta semana? | Bloqueado por |
|---|---|---|---|---|
| 3 | Cuatro ojos por regla (B-2) | **S–M** | **Sí** | D13–D15 del propietario (S/N) |
| 1 | `complaint_created` con efecto | **M** | **Sí** | Autorización del lead para la puerta de diff del H9 + D1–D3 |
| 2 | Consentimiento de socios (C9) | **L** | **No**: solo el cuestionario legal y el borrador del esquema | Asesoría legal (P9-14 y P9-15) |

Lo que más cambia el plan:
1. **Queja:** el emisor debe engancharse a la fila `marketing_ai_actions`, no a la conversación. El agente de ventas marca la conversación con `update()` del constructor de consultas (`SalesAgentOrchestratorService.php:231-236`), que no dispara observadores.
2. **Consentimiento:** sin el camino de identidad, C9 casi no sirve. La puerta de plantillas solo acepta un prospecto, y también el canal asistido de WhatsApp exige identidad de WhatsApp en N4. Además, el `data_processing` que firman los socios no sirve para mensajes comerciales, en mi lectura: la política de privacidad excluye WhatsApp de forma expresa.
3. **Cuatro ojos:** el control debe basarse en el libro de auditoría, no en «el último editor». Quien escribió la frase en la revisión 1 deja de ser el último editor si otra persona cambia la prioridad en la revisión 2.

---

## 1 · Quejas: que una estrategia pueda excluir a quien se quejó

### 1.1 Hechos

**El evento.** `complaint_created` está en el vocabulario (`app/Services/Commercial/CommercialVocabulary.php:214`, `:241`). Nadie lo emite, y desde el cierre está marcado `available:false` (informe-be-h9-cierre §2; `OutreachCatalogEmittersTest` ata `available` a la existencia de un emisor). Ningún nivel de la escalera lo lee.

**Dónde se registra hoy una queja:**

| Fuente | Qué guarda | Quién la escribe | Fiabilidad |
|---|---|---|---|
| `marketing_conversations.staff_review_pending/_reason/_resolved_at/_resolved_by` (`app/Models/MarketingConversation.php:24-25`) | `reason = 'complaint'` | El backend, a partir de la **intención que propone el modelo**: `UltronCommitService.php:551` toma `$proposal['intent']`. La intención `complaint` escala **sin corroborar**; solo `human_request` se contrasta con el texto (`SalesAgentDecisionValidator.php:80-111`). El motivo sale de `StaffReviewAuthority.php:74-80` | **Baja como memoria.** Es un único valor que la siguiente revisión **sobrescribe** (`UltronCommitService.php:2068-2072`, `:2792-2795`). Resolverla solo pone `pending=false` y una nota libre, sin categoría (`MarketingStaffReviewService.php:14-27`; `MarketingInboxController.php:461-477`) |
| `marketing_ai_actions`, una fila por turno | ULTRON: `reason = staff_review_reason ?? intent` y `metadata.intent` (`UltronCommitService.php:2545-2556`), idempotente por `idempotency_key`. Agente de ventas: fila `staff_review` con `reason` (`SalesAgentOrchestratorService.php:375-395`) | Laravel, con `MarketingAiAction::create`, que **sí dispara eventos de Eloquent** | **La mejor fuente que existe.** Es histórica y sin datos personales en el motivo. Pero sigue siendo la clasificación del modelo |
| Palabras clave (`SalesEscalationService.php:17-24`) | «queja», «reclamo», «demanda»… dan `sensitive_topic`, **no** `complaint` (`:60-67`; el `??=` deja el primer motivo) | Laravel | Una queja que el modelo no clasifica como queja **no** queda como `complaint` |
| Toma manual del Inbox (`MarketingManualTakeoverService.php:28-37`, el motivo `conflict` = «Conflicto o queja») | `marketing_ai_actions.reason` | Una persona | **No es fiable como señal:** el endpoint acepta texto libre de hasta 500 caracteres (`MarketingInboxController.php:292`) y no lo valida contra `REASONS` |
| `member_support_tickets` (`2026_06_18_000001…:21-23`) | `type` ∈ error, payment, classes, routine, other, más texto libre | El socio, desde la app | No tiene categoría de queja |
| `trainer_reviews` (`2026_05_18_000011…:15`) | Nota de 1 a 5 de un entrenador | El socio | No es una queja comercial |
| PQR, valoraciones de satisfacción, quejas presenciales o por teléfono | — | — | **No hay ningún registro** [C: busqué complaint, queja, reclamo, pqr, grievance, feedback, rating, survey y ticket en `app/` y en las migraciones] |

**Lo que ya frena hoy** [C]:
- Con la conversación abierta y la revisión pendiente, N2 niega `human_in_control` (`LeadFacts.php:217`).
- Si el personal **toma** la conversación, `MarketingConversationCommercialObserver.php:29-53` emite `human_requested`, y N6 niega `human_requested_recently` durante 30 días (`LadderInputBuilder.php:152-165`, `OutreachConfig.php:214-217`, `config/outreach.php:145`; el evento se lee siempre por `LiveStrategy.php:222`).
- **Hueco real:** una queja que el personal **resuelve sin tomar** la conversación no deja ninguna memoria.

**Fronteras:**
- En producción, `commercial.events_enabled` está activo desde el 11-11 [P: `estado-produccion-20261111-1842Z.txt:22-23`].
- La puerta de diff del H9 prohíbe cualquier byte nuevo en `app/Observers/Commercial`, `app/Services/Commercial` y `app/Services/Marketing/Ultron` (`tests/Feature/UltronStudio/OutreachIsolationTest.php:160-164`).
- `CommercialEventRecorder` no lanza nunca, deduplica por clave y descarta los eventos que no están en el vocabulario (`CommercialEventRecorder.php:54-131`).

### 1.2 Opciones

| | Qué es | Esfuerzo | Riesgo | Reversibilidad |
|---|---|---|---|---|
| **A · Solo emisor + catálogo** | Emitir `complaint_created` y abrirlo en el catálogo. Así funcionan las exclusiones que escriba cada autora | S–M | **Alto:** es optativo por estrategia. Si una autora olvida la exclusión, se contacta igual | Alta |
| **B · Emisor + catálogo + freno global con ventana en N6** | Como A, y además `complaint_recent` en N6, que ninguna estrategia puede quitar, igual que `human_requested_recently` | **M** | Bajo. Un falso positivo solo silencia durante N días (el lado seguro) | Alta (configuración y versión) |
| **C · Tabla de supresiones con memoria** | `outreach_suppressions` de solo inserción, con supresión y levantamiento a cuatro ojos, alimentando N2 `global_suppression` (ya está en el contrato: `ULTRON_STUDIO_H9_CONTRACT.md:502`; `PolicyLadder.php:69`, `:314-316`) | L | Bajo | Media: hace falta una persona para levantarla |

**→ Recomendación: B.** Reutiliza el patrón de `human_requested`, que ya está probado. Pasar a C solo si el propietario contesta «hasta que una persona la levante» en D6. El orden N6 con efecto de salida es mejor que N2: en el código, una negativa de N2 hace **esperar** el trabajo (`waitsForRelease`, `PolicyLadder.php:226`), y la secuencia volvería a empezar tras la ventana, desfasada.

### 1.3 Piezas

| # | Agente | Qué | Tamaño |
|---|---|---|---|
| Q1-1 | backend | Fichero nuevo `app/Observers/Commercial/MarketingAiActionCommercialObserver.php`. En `created`, si `commercial.events_enabled` está activo, `lead_id` no es nulo y (`reason === 'complaint'` o `metadata.intent === 'complaint'`), llama a `record(EV_COMPLAINT_CREATED, resolver->fromLead(lead_id), payload {source: metadata.origin ?? 'sales_agent', conversation_id, ai_action_id}, dedupeKey "conversation:{id}:complaint:{Ymd Bogotá}")`. **Sin texto ni teléfono en el evento.** Una queja al día por conversación: la ventana cuenta desde la última. Se registra en `app/Providers/CommercialEventServiceProvider.php:50`. **Exige la autorización escrita del lead para la puerta de diff** (precedente D-1). Ponerlo fuera de `Observers/Commercial` para esquivar la puerta sería entrar por la puerta de atrás | S |
| Q1-2 | backend (B9-core) | Quitar `complaint_created` de `EVENTS_WITHOUT_EMITTER`: `available:true` y **`triggerable:false`** (una queja nunca dispara un contacto). Subir `VERSION`, `sec_catalog.py` (`NO_EMITTER`), `build.py`, añadir el emisor a la lista cerrada de `OutreachCatalogEmittersTest` y sincronizar el CRM (`sync-h9-fixtures.mjs`). Precedente: I-5 | S–M |
| Q1-3 | backend (B9-core + B9-run) | N6 `complaint_recent`: motivo en `PolicyLadder::REASONS['N6']`, entrada `complaintRecently ?bool` (nulo = fuente sin datos → niega, como L9-26), `EFFECTS` → salida (cierra la inscripción con `exit_reason = complaint_recent`), `LadderInputBuilder::complaintRecently()`, las llamadas de `OutreachPlanner.php:461` y del despachador (`OutreachDispatcher.php:424`), `LiveStrategy.php:222` (+`complaint_created`), `OutreachConfig::complaintDays()` y `config/outreach.php`, las finalidades a las que aplica (D3), `DecisionTraceText`, casos de `sec_ladder.py` y `policy-ladder-cases.json` en los dos repos | M |
| Q1-4 | frontend | Espejo del texto de la traza, la instantánea del catálogo y que la exclusión «se quejó» deje de ser papel mojado | S |
| Q1-5 | tester | Fila de la IA con `complaint` → un evento. Dos filas el mismo día → uno. Fila sin `complaint` → ninguno. Socio con prospecto vinculado → su `m:` lo ve (`EngineFacts.php:134-139`). N6 dentro y fuera de la ventana. Fuente sin datos → niega. `service` no afectado si D3 lo deja fuera. Mutaciones del predicado y de la ventana | M |
| Q1-6 | security | Revisa la escalera (cambia un nivel) y el dato personal nuevo («esta persona se quejó» en `commercial_events`) | S |
| Q1-7 (opcional) | backend | `commercial:backfill-complaints --since=… --dry-run` desde `marketing_ai_actions`, con la misma clave (idempotente) y `occurred_at` = `created_at` de la fila. Solo si D4 = sí | S |
| Q1-8 (fase 2) | backend + frontend + api-security | (a) Categoría cerrada al resolver una revisión (`complaint_confirmed`, `not_a_complaint`, `other`). (b) Botón «Registrar queja» (presencial o teléfono) en la ficha. Cada uno es un emisor nuevo de la lista cerrada. Solo si D5 = sí | M + M |

**Dependencias:** Q1-1 → Q1-2 (la prueba de emisores obliga a abrir el catálogo en el mismo commit) → Q1-3 → Q1-4. Q1-5 empieza con Q1-3. **Primer verde:** Q1-1 + Q1-2 juntos. Q1-3 hay que desplegarlo **antes de la primera publicación de estrategias**: hoy hay 0 decisiones, así que `outreach:replay` no cambia nada histórico.

### 1.4 Riesgos

- **Falsos positivos.** El modelo clasifica sin corroborar (`SalesAgentDecisionValidator.php:88-90`). Con B solo silencian el contacto (el lado seguro). Sin Q1-8(a) no hay forma de descartar una queja que no lo era.
- **Falsos negativos.** Quedan fuera: la palabra «queja» con otra intención (`sensitive_topic`), las quejas presenciales, por teléfono o por la voz del H8, y los socios sin prospecto. B no los cubre. Q1-8(b) cubre lo presencial si hay un proceso humano detrás.
- **Cobranza.** Una queja sobre un cobro («me cobraron doble») es justo cuando un mensaje de cobranza hace más daño. Hoy la cobranza no tiene ninguna fuente de consentimiento (P9-15), así que en la práctica no cambia nada hasta C9.
- **Efecto lateral en el motor comercial.** `queueEvaluation` encola `EvaluateCommercialSubject` si `commercial.enabled` (`CommercialEventRecorder.php:154-161`). [SUPOSICIÓN] Con NBA apagado no actúa; backend lo comprueba antes de Q1-1.
- **Ley 1581.** [SUPOSICIÓN] No he comprobado que la supresión general cubra `commercial_events`. `docs/ultron-studio/ops/supresion-1581.sql` no la nombra. Lo comprueban devops y database-specialist.
- **Adyacente, no lo arreglo.** `SalesEscalationService.php:19` incluye `'sic'` con `str_contains`, que casa con «física», «básico» y «música» → revisiones `sensitive_topic` falsas. Para `debugger`.

---

## 2 · Consentimiento de los socios (I-2 / C9)

### 2.1 Qué decide ya el contrato y qué no

**Decidido:**
- Se niega por defecto y gana cualquier señal negativa de cualquier fuente (`ULTRON_STUDIO_H9_CONTRACT.md:857`).
- Matriz finalidad × canal (`:859-865`): socios sin prospecto, **ninguna**; cobranza, **ninguna** («la autorización comercial no vale para cobranza»).
- Tener el teléfono no autoriza nada (`:865`).
- La voz solo por `voice_consents` (`:180-181`).
- Tabla `outreach_consents`: finalidad × canal, solo inserción, cuatro ojos cuando la carga una persona (`:960`).
- Paquete C9 = backend + database-specialist + security + frontend, con «el camino de identidad de WhatsApp para socios sin lead» (`:1185`).
- N2 `global_suppression (C9)` (`:502`).
- El H9 **nunca escribe** `marketing_leads` ni `marketing_conversations` (`:980-982`).

**Sin decidir:**
- P9-14: cómo se recoge la autorización y cómo se crea la identidad «sin tocar la atribución de Meta» (`:1397`).
- P9-15: qué fuentes son válidas, con asesoría (`:1398`).
- El esquema concreto y si los cuatro ojos son por cuenta o por persona.

### 2.2 Consentimientos que existen hoy

| Fuente | Dónde | Qué dice | ¿Sirve para WhatsApp? (mi lectura, no asesoría) |
|---|---|---|---|
| Prospecto de WhatsApp | `marketing_leads.consent_status`, `consent_source`, `consent_at` (`2026_06_27_000001…:20-22`); historial en `marketing_consent_events` (`2026_09_20_210000…:30-56`: de/a estado, `source`, `reason`, `actor_admin_id`, `evidence`), **sin finalidad ni canal**. 86 de 837 en `granted` [P: contrato `:1452`] | El estado de la conversación | Hoy es la única fuente para comercial (`LeadFacts.php:236-253`). No dice la finalidad |
| Contrato del socio (app o recepción) | `member_contracts.acceptance_snapshot` con el **texto exacto** de cada casilla y `template_version` (`2026_06_02_000002…:30`, `:51`; `config/contracts.php:165-167`). Copia «legacy» mutable en `member_legal_consents`, una fila por socio (`2026_05_18_000003…:11-25`, `MemberContractService.php:452-456`) | Casillas (`config/contracts.php:172-192`): `truthfulness`, `terms_and_conditions`, **`data_processing` (obligatoria)**, `health_data`, `inform_injuries`, `commercial_policies` (no reembolso; **no** es contacto), `image_use` (imagen con fines promocionales; **no** es contacto) | **Ninguna casilla autoriza mensajes comerciales.** `data_processing` es obligatoria (Ley 2300, art. 5, par. 2: «no se puede obligar»), y la política que cita **excluye WhatsApp y recepción**: «No cubre lo que hagas fuera de la app: si nos escribes por WhatsApp… o te inscribes presencialmente en recepción» (`LegalController.php:118-120`). Su finalidad más cercana es «notificaciones operativas y recordatorios que puedes desactivar» (`:256`), pensada para avisos de la app. Si cubre recordatorios de **servicio** por WhatsApp lo decide la asesoría |
| Voz (H8) | `voice_consents`: `channel = voice_call` y `purpose = commercial` por CHECK; fuentes `written_form`, `recorded_inbound_request` y `admin_with_evidence`; cuatro ojos **por cuenta** (`2026_11_10_100002…:320-322`, `:425`, `:782-804`; `VoiceContacts.php:162-240`) | Autorización de voz comercial | No (D8-18). **Es el patrón que hay que copiar** |
| IRON IA, imagen, biometría | Consentimientos de otras finalidades | — | No [SUPOSICIÓN: solo leí los nombres de las tablas] |
| Opt-in de Meta | No hay ninguna tabla | — | Contrato `:1431`: «por confirmar» en W9 |

**Opciones de base legal para que elija el propietario con su asesor** (no las decido):

| | Base | Qué permitiría | Qué hay que decidir |
|---|---|---|---|
| L1 | Casilla **opcional y separada** por finalidad y canal en el contrato (versión nueva de la plantilla, en la app y en recepción) | Comercial y/o servicio por WhatsApp para quien la marque desde esa versión | El texto, las finalidades y si se pide también a los socios actuales al renovar |
| L2 | Formulario firmado o constancia en recepción, cargado en el CRM a cuatro ojos | Lo mismo, también para los socios actuales | El texto del formulario, quién lo carga y quién lo confirma |
| L3 | Petición que hace el propio socio («avísenme por WhatsApp») | Servicio, quizá comercial | Si vale como autorización expresa |
| L4 | El `data_processing` actual | En mi lectura, **nada** comercial. Quizá servicio | Lo dice la asesoría |
| L5 | Cobranza | — | La Ley 2300, art. 2: si el contrato debe nombrar los canales de cobranza |

### 2.3 Rebanada mínima implementable

**Lo que hay que saber antes de dimensionar:** sin identidad, C9 no aporta casi nada.
- La puerta de plantillas exige un `MarketingLead` (`MarketingMessageDispatcher.php:229`) y nunca crea prospectos (`WhatsappRecipient.php`, cabecera).
- N4 niega `no_whatsapp_identity` también al **asistido** de WhatsApp (`PolicyLadder.php:385-387`: mira el medio, no el canal).
- Un socio solo «tiene» WhatsApp si un prospecto con conversación lo enlaza con `marketing_leads.member_id` (`SubjectResolver.php:145-148`, `:211`).
- Por tanto C9 sin C9-5 solo ayuda a socios **ya enlazados** a un prospecto que no está en `granted`. El volumen está sin medir [SUPOSICIÓN].

| # | Agente | Qué | Tamaño |
|---|---|---|---|
| C9-0 | propietario + asesoría | D7–D12 (§4). **Bloquea C9-3 en adelante** | — |
| C9-1 | database-specialist | `outreach_consents`, **solo inserción** (`outreach_forbid()`, REVOKE UPDATE y DELETE, espejo en SQLite, `down()` que se niega con datos). Columnas: `uuid`, `client_request_id` UNIQUE, `subject_key` (CHECK `^(m\|l):[0-9]+$`), `member_id` y `lead_id` como referencias sin FK, `purpose` ∈ commercial/collections/service, `channel` ∈ whatsapp (la voz se queda en `voice_consents`: sin duplicar), `kind` ∈ requested/granted/rejected/revoked, `request_uuid` (granted/rejected apuntan a su `requested`, UNIQUE), `source` (conjunto cerrado de D7), `evidence` (≤ 300, PlainText, sin teléfonos), `legal_text_version` + `legal_text_hash` (el texto exacto aceptado), `phone_ref` (HMAC, **nunca el número**: la autorización es para ese número), `recorded_by_*` / `confirmed_by_*` con CHECK de que son distintos (como `vcs_four_eyes_chk`), `redacted_at` para la supresión 1581 (L9-5). **Recomiendo el libro puro** (`requested` como fila) frente al patrón del H8 de la petición pendiente en columnas mutables | M |
| C9-2 | backend (B9-facts) | `app/Services/Outreach/Facts/OutreachConsentFacts.php` (solo lectura). **Cuidado:** `BatchSnapshotBuilder.php:91` y `:97` mezclan la política con `[...$a, ...$b]`, así que gana el último adaptador. **No** debe escribir `consent.whatsapp.<p>`. Que dé `c9.whatsapp.<p>` ∈ granted/revoked/none (el último evento por finalidad y canal; `revoked` gana), y que `CoreProjection::policyInput` (`:150-160`) combine: **denied** si el prospecto está denegado o C9 dice `revoked`; **granted** si el prospecto o C9 dicen `granted`, `phone_ref` coincide con el número de destino y no hay `do_not_contact`; si no, `missing`. Cobranza solo por su propia fila | M |
| C9-3 | backend (B9-run) | Endpoints en `routes/commercial-automation.php`: listar por persona, pedir, confirmar (otra cuenta), rechazar, revocar (sin cuatro ojos: retirar siempre es seguro). +2 llaves (`…consents_record`, `…consents_confirm`) en `PermissionCatalog`, `CrmPermission` y `AuthorizationMap`. Idempotencia por `client_request_id`. Sección de la supresión 1581 (devops) | M |
| C9-4 | frontend | Pantalla «Autorizaciones de contacto» en `commercial-automation/`, copiando el patrón de `studio-voice-contacts.ts`: registrar (A), pendientes, confirmar (B), revocar, con el texto legal que se aceptó a la vista | M |
| C9-5 | backend (dueño de Marketing) + security | **Identidad de socios sin prospecto.** Opción (i), recomendada: al confirmar una autorización, Marketing (no el H9) crea o enlaza un prospecto `source = 'member_optin'` con `member_id` y **lo excluye de la atribución**. Hoy contaría como real: `LeadUniverse::isReal` solo excluye `TEST_SOURCES`. Opción (ii): que la puerta de W9 acepte un socio sin prospecto, lo que cambia la puerta de Marketing (R9-2) | M |
| C9-6 | backend | Importación inicial: **nada importable hoy** (§2.2). Solo si se adopta L1: un relleno desde `member_contracts.acceptance_snapshot` con `source = contract_checkbox`, actor del sistema y el hash del texto | S–M |
| C9-7 | flutter-specialist + backend | Solo con L1: una casilla opcional nueva en `config/contracts.php` (otra versión de la plantilla y del PDF) y en la app | M |
| C9-8 | tester | Socio con C9 `granted` → pasa N1. `revoked` posterior → `consent_denied`. Prospecto denegado más socio `granted` → denegado. Teléfono cambiado → `missing`. Comercial `granted` → cobranza `missing`. Cuatro ojos en base de datos y en la API. Paridad del fixture de la escalera | M |
| C9-9 | security + api-security-specialist | Datos personales, BOLA sobre la persona, cuatro ojos, evidencia sin teléfonos, supresión | S–M |

**Dependencias:** C9-0 → C9-1 → (C9-2 ∥ C9-3) → C9-4. C9-5 depende de D11 y del diseño de W9. **Primer verde útil:** C9-1 + C9-2 inertes (sin filas no cambia nada; se puede desplegar antes de la decisión legal), aunque yo no los desplegaría antes de C9-0.

### 2.4 Riesgos

- **R9-1** (contrato `:1352`): una autorización mal recogida es irreversible en cuanto sale un mensaje. Por eso C9-3 y C9-4 esperan al texto legal.
- La fusión de la política (C9-2) es el punto donde un error haría pasar a alguien denegado: tiene que haber una prueba de la negativa que gana.
- C9-5 toca la atribución de Meta y el número compartido (R9-2).
- Cuatro ojos por persona verificada: hoy hay **0 vínculos** [P: auditoría B §2 `:46`]. Pedirlos bloquearía la carga igual que bloquea las estrategias.

---

## 3 · Cuatro ojos por regla (B-2 de seguridad)

### 3.1 Hechos

- Los cuatro ojos comparan solo con quien **creó la versión**, por id de cuenta (`StudioAccess.php:168-172`). Se aplican al aprobar o rechazar (`StudioVersions.php:470-482`, `:502-513`), al publicar o promover (`StudioPublications.php:1322`, `:2027-2040`; requisito de la vista previa `:954`, bloqueo `:976`) y al **publicar la voz** (`StudioVoicePublishing.php:1078-1090`). La voz también compila las frases: `StudioVoiceCompiler.php:773`.
- La versión copia todas las reglas vivas con su `code` y su `revision`, **sin autor** (`StudioRules.php:340-360`). `StudioRulePhrases::byRule` da `code` y `phrases`, sin la revisión (`StudioRulePhrases.php:46-67`).
- **De dónde sale la autoría:**
  - `ultron_studio_rules.created_by_admin_id` y `updated_by_admin_id` (`StudioRules.php:168-178`, `:274-279`) solo dicen **el último editor de la revisión vigente**. Si la regla se edita después de crear la versión, apunta a otra revisión. **No sirve.**
  - El libro `ultron_studio_audit_events` (`2026_10_18_100001…:161-188`; solo inserción por disparador, `2026_10_18_100002…:97-108`) tiene `rule.created` (revisión 1, `actor_admin_id`, `StudioRules.php:181`), `rule.updated` (`diff.fromRevision/toRevision`, `:281-284`) y `rule.archived` (`:317-321`), por `subject_uuid` de la regla. `code` es UNIQUE (`2026_10_18_100001…:127`), así que el paso de código a uuid es fiable. Cada escritura de regla y su línea del libro van en la misma transacción. **Sí sirve.**
- **«El último editor» no basta:** A escribe la frase en la revisión 1, B cambia la prioridad en la revisión 2 → el último editor es B, y A podría aprobar su propia frase.
- **Reparto por defecto:** el Administrador tiene `manage_rules`, `edit_draft` y `approve`. **`publish` solo el Super Admin** (`CrmPermission.php:289-305`).
- **En producción:** 0 reglas S-NNN y 0 publicaciones [P: informe-security-reglas B-1].
- **Contrato del H5:** «Cuatro ojos (quien publica ≠ autor) → 403 `four_eyes_required`», en la posición 4 del orden de comprobaciones (`ULTRON_STUDIO_H5_CONTRACT.md:163`, `:334`). El fixture `tests/fixtures/ultron-studio/publication-cases.json` fija ese código.

### 3.2 Opciones

| | Qué es | Dónde | ¿Rompe a un equipo de dos? | Vista previa | Contrato del H5 |
|---|---|---|---|---|---|
| **A · Excluir a los autores de frases** | Aprobar y publicar (WhatsApp y voz) se niegan si quien actúa es autor de **cualquier revisión ≤ la copiada** de una S-NNN que aporta frases a la versión. Si falta la línea del libro de alguna revisión → se niega (falla cerrado) | Un servicio nuevo `StudioRulePhraseAuthors` (lee el libro) y los tres sitios de cuatro ojos | Funciona si **quien escribe las frases crea la versión** y la otra persona aprueba y publica. Se bloquea si (a) el Super Admin escribió frases y nadie más tiene `publish`, o (b) los dos escribieron frases. Hasta que llegue una tercera persona o se archive la regla | Requisito nuevo `rulePhraseAuthor` con los `code` y las revisiones de las que eres autor | **Aditivo:** el mismo 403 `four_eyes_required` en la misma posición, con `detail: rule_phrase_author`, más casos en el fixture. Una enmienda de una línea a A5.1, paso 4 |
| B · Revisión propia de cada regla | Cada revisión de una S-NNN con frases necesita la aprobación de otra persona antes de que sus frases compilen | Ciclo nuevo de reglas, endpoints, CRM; la versión tiene que copiar «revisada», así que **cambia `versionRuleFields` y el formato del hash** | No se bloquea: cada uno revisa la regla del otro | Estado por regla | **Cambio mayor** (H5, G y el hash del contenido) |
| C · Solo organizativo y de detección | La regla E-4 más un **aviso** no bloqueante en la vista previa | Vista previa | No | Aviso | Ninguno |

**→ Recomendación: A.**
- Cierra B-2 con un diff pequeño y aditivo, y el dato que usa es fiable.
- B es más cómoda para un equipo de dos, pero cambia el formato del hash de las versiones. Es desproporcionado con 0 reglas en producción.
- El caso que bloquea a dos personas (el Super Admin autor de frases) es una decisión de gobierno, no un fallo: D13 y D14.
- **Sí** dejar rechazar al autor de frases (D15): rechazar va en la dirección segura y evita el bloqueo.

### 3.3 Piezas

| # | Agente | Qué | Tamaño |
|---|---|---|---|
| B2-1 | backend (S9) | `app/Services/UltronStudio/StudioRulePhraseAuthors.php`: de `content.rules`, las S-NNN no archivadas que aportan frases (mismo filtro que `byRule`), con la `revision` copiada → `code` → `uuid` → autores de `rule.created` y `rule.updated` con revisión ≤ la copiada. Una sola consulta por versión, por `usae_subject_idx` | S–M |
| B2-2 | backend (S9) | Aplicarlo en `StudioVersions` (`actions()` aprobar; rechazar permitido según D15), `StudioPublications` (`fourEyes` del hecho `:1322`, `assertFourEyes` `:2029`, requisito `:954`) y `StudioVoicePublishing::assertFourEyes` (`:1080`). Mismo código 403 y mismo evento del libro `*.four_eyes_denied` con `diff.rulePhraseAuthor = [codes]` | S |
| B2-3 | frontend | El diálogo de publicar y el panel de la versión pintan el requisito y el motivo («Escribiste S-003, revisión 2: tiene que aprobarla otra persona») | S |
| B2-4 | tester | Autor de la revisión 1 bloqueado aunque la revisión 2 sea de otro. Autor de una regla **sin** frases, no bloqueado. Regla archivada, no cuenta. Revisión sin línea del libro → se niega. Voz igual que WhatsApp. Flujo de dos personas que funciona y flujo que se bloquea. Casos en `publication-cases.json`. Mutaciones (quitar el `≤`, usar `updated_by`) | M |
| B2-5 | api-security-specialist | Revisa la autorización (BFLA) y que la vista previa no revele más que lo que ya ve `ultron.view` | S |

**Dependencias:** B2-1 → B2-2 → (B2-3 ∥ B2-4) → B2-5. Es independiente de las preguntas 1 y 2. **Primer verde:** B2-1 + B2-2 con sus pruebas. [SUPOSICIÓN] No he comprobado si `requirements` entra en `previewHash`. Si entra, la huella cambia, cosa inocua porque las dos partes las calcula el servidor; S9 lo confirma.

### 3.4 Riesgos

- Que el equipo se bloquee (D14).
- B-1 (el resto `/1` arrastrado con frases) sigue abierto y **A no lo cubre**: las frases del resto arrastrado no tienen revisor. La condición E-3 sigue vigente.

---

## 4 · Decisiones que solo puede tomar el propietario (cada una se contesta en una línea)

**Quejas**
- **D1.** ¿Una queja que detecta la IA en WhatsApp excluye del contacto automático aunque nadie la confirme? (sí/no · recomendado: sí)
- **D2.** ¿Durante cuántos días excluye una queja? (número · recomendado: 30, como `human_requested`)
- **D3.** ¿Excluye solo de lo comercial, o también de la cobranza? (solo comercial / comercial y cobranza · recomendado: las dos; los recordatorios de servicio no)
- **D4.** ¿Se cuentan las quejas anteriores al despliegue, recuperándolas del historial de la IA? (sí/no)
- **D5.** ¿Quiere un botón en el CRM para registrar quejas presenciales o por teléfono? (sí/no; si sí, ¿qué rol lo usa?)
- **D6.** ¿Basta con la ventana de días, o una queja debe excluir hasta que una persona lo levante? (ventana / hasta levantarla)

**Consentimiento (C9)**
- **D7.** ¿Cómo se recoge la autorización de los socios? (casilla opcional en el contrato / formulario en recepción / las dos)
- **D8.** (asesoría) ¿El «tratamiento de datos» actual cubre recordatorios de **servicio** por WhatsApp? (sí/no)
- **D9.** (asesoría) ¿La cobranza por WhatsApp necesita una autorización propia? (sí/no)
- **D10.** ¿Los cuatro ojos al cargar una autorización son por cuenta, como la voz del H8, o por persona verificada, como las estrategias? (cuenta / persona · recomendado: cuenta, porque hoy hay 0 vínculos)
- **D11.** ¿Se puede crear un prospecto de WhatsApp para un socio que autoriza, marcado fuera de la atribución de Meta? (sí/no)
- **D12.** ¿Redacta la asesoría el texto exacto de la autorización antes de construir la pantalla? (sí/no · recomendado: sí)

**Cuatro ojos por regla**
- **D13.** ¿Acepta que quien escribió frases de una S-NNN no pueda aprobar ni publicar ninguna versión que las copie, aunque sea el Super Admin? (sí/no)
- **D14.** Si las dos únicas personas que revisan escribieron frases, ¿se acepta el bloqueo hasta que haya una tercera o se archive una regla? (sí/no)
- **D15.** ¿Puede quien escribió las frases **rechazar** la versión? (sí/no · recomendado: sí)

## 5 · Preguntas para el lead (cambian el plan)

1. ¿Autoriza el lead por escrito un fichero nuevo en `app/Observers/Commercial/` frente a la puerta de diff del H9 (Q1-1)? Sin eso, la pregunta 1 queda en la opción A sin emisor, es decir, en nada.
2. ¿Firma el lead las enmiendas aditivas: el H9 D9-7 (N6 `complaint_recent`) y el H5 A5.1, paso 4 (cuatro ojos ampliados)?

## 6 · Seguridad y pruebas

**Seguridad:**
- `security` revisa Q1-3 (cambia la escalera) y Q1-6 (dato personal nuevo: «se quejó»).
- `security` y `api-security-specialist` revisan todo C9, que toca datos personales, autorización y cuatro ojos.
- `api-security-specialist` revisa B2-5 (BFLA en aprobar y publicar).

**Tester, casos límite:**
- Q1: quejas repetidas el mismo día; un socio que solo tiene un prospecto enlazado.
- C9: la negativa siempre gana; el número de teléfono cambió.
- B2: el autor de la revisión 1 frente al último editor; una regla archivada; una revisión sin línea en el libro.

**Fuera de alcance, pero conviene arreglarlo:** `'sic'` en `SalesEscalationService.php:19` marca como tema sensible mensajes que dicen «física» o «básico». Es para `debugger`.

## 7 · Lo que no verifiqué

- Que `EvaluateCommercialSubject` no actúe con NBA apagado (Q1).
- Que la supresión 1581 general cubra `commercial_events`.
- Cuántos socios tienen un prospecto enlazado (el volumen de C9 sin C9-5).
- Si `requirements` entra en `previewHash`.
- Qué envía el CRM como `reason` al tomar una conversación.
- El estado actual de producción, más allá de los ficheros de cierre del 11-11.
