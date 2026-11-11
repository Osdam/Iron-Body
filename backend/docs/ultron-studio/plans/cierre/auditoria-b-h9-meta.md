# Auditoría de cierre B · Automatización comercial (Hito 9) y Meta

**11-11-2026 · architect · solo lectura.** El código leído es el de producción: `git diff 2687119 HEAD -- app routes config` sale vacío [C]. El CRM se leyó en `5f2a454`; en producción está `b07eda5`, y el commit de encima solo toca pruebas (CIERRE §0.1).

Marcas: **[C]** comprobado en el código o con una ejecución · **[P]** hecho de producción dado por el lead (`estado-produccion-20261111-1842Z.txt`, CIERRE §0, L9-101) · **[S]** suposición.

Rutas: las del CRM, `/marketing/automatizaciones?pestana=<id>` (`app.routes.ts:285`); las del API, bajo `api/admin/ultron-studio/`. FE = `Iron-Body_Front/src/app/modules/`.

---

## 1. Matriz

| # | Funcionalidad | Frontend | Backend | Tablas | Dep. externa | Bandera efectiva [P] | Estado | Causa demostrada | Corrección | Prueba | Resultado esperado |
|---|---|---|---|---|---|---|---|---|---|---|---|
| 1 | Panel: estado del servidor, canales, presupuestos, cadena, fuentes y pasadas | `ca-panel.ts` · `panel` | `GET outreach/status` · `UltronStudioOutreachController` → `OutreachConfig::status()` (`OutreachConfig.php:270-291`), `OutreachReadModels` | `outreach_runs`, `outreach_cursors`, `outreach_brakes` | — | maestra sí · `shadow` | **OPERATIVO Y PROBADO** (lectura) | Smoke del H9 en producción: 30 OK (CIERRE §0.3). Sale vacío porque no hay estrategias, y lo dice bien (`ca-panel.ts:227`) | — | `OutreachApiTest` | Sombra, constante cerrada, 0 trabajos, «Ninguna estrategia publicada» |
| 2 | Estado de activación (7 filas) | `ca-activation.ts`, `ca-activation-panel.ts` | Se deriva de `strategies/catalog`, `outreach/status`, `person-bindings` y `stream` | — | — | — | **INCOMPLETO** (textos) | Dos textos engañan: WhatsApp sale «Pendiente de proveedor externo» (`ca-activation.ts:244` y precedencia en `activation-state.ts:58`) sin que nada esté en Meta; el programador dice «todavía no ha terminado ninguna» (`ca-activation.ts:312`) con 809 despachos completados | §4.8 (S) | spec de `ca-activation` | Cada fila nombra su primer bloqueo real |
| 3 | Estrategias: crear y listar | `ca-strategies.ts` · `estrategias` | `POST/GET strategies` · `StudioStrategies::create()` (`StudioStrategies.php:47-90`, no pide vínculo) | `ultron_studio_strategies` | — | gobierno sí | **LISTO PARA ACTIVACIÓN AUTORIZADA** | Se puede usar hoy. Hay 0 estrategias [P] y nunca se ha usado en producción | §3, paso T3 | `UltronStudioStrategyLifecycleTest` | Estrategia en borrador; no ejecuta nada |
| 4 | Constructor: borrador y validar | `ca-builder.ts`, `ca-conditions-editor.ts` · `constructor` | `PUT strategies/{uuid}/draft`, `POST strategies/{uuid}/validate` · `StudioStrategies::saveDraft()`, `StudioStrategyValidator::validate()` | ídem | — | gobierno sí | **LISTO PARA ACTIVACIÓN AUTORIZADA** | Se puede usar hoy, sin vínculo. Validar da dos bloqueantes: `template_not_found` mientras no haya plantilla aprobada (`StudioStrategyValidator.php:236-247`) y `studio_baseline_superseded` si referencia la línea base, porque hay una versión del Studio APPROVED [P] (`:221-222`) | §3, T3 y P4 | `UltronStudioStrategyValidationTest` | Sin bloqueantes, con plantilla aprobada y la versión del Studio |
| 5 | Simulación del borrador y de la versión | `ca-simulation.ts` · `simulacion` | `POST strategies/{uuid}/simulations`, `POST strategies/versions/{uuid}/simulations` · `StudioStrategySimulations::simulate()` (`:88-135`) | `ultron_studio_strategy_simulations` | — | puerta `required` | **LISTO PARA ACTIVACIÓN AUTORIZADA** | La del borrador se puede hacer hoy sin vínculo (sintética: `ultron.strategies_edit`; muestra real: `commercial_automation.simulate_real`, solo SA). La de la versión, cuando se pueda congelar | §3, T4 y P6 | `UltronStudioStrategySimulationTest` | Recuentos por nivel; 0 bloqueantes para poder aprobar |
| 6 | Plantillas del Studio: crear versión, aprobar y rechazar | `ca-templates.ts`, `ca-tpl-editor.ts`, `ca-tpl-detail.ts` · `plantillas` | `POST strategies/templates`, `POST strategies/templates/{uuid}/approve`, `…/reject` · `StudioMessageTemplates::create()` (pide persona en `:688`) y revisión (otra persona, `:750-751`) | `ultron_studio_message_templates` | — | gobierno sí | **LISTO PARA ACTIVACIÓN AUTORIZADA** | Con 0 vínculos, crear da 403 `person_binding_required`, pero el CRM ofrece el botón igual (`ca-templates.ts:50`, `:102`) | P1 y texto (§4.8) | Plantillas, 196 en verde (CIERRE §0.3) | Plantilla APPROVED con cuatro ojos |
| 7 | Gobierno: congelar, aprobar, rechazar, publicar en canario, promover, pausar, reanudar, revertir y archivar | `ca-governance.ts` · `gobierno` | `POST strategies/{uuid}/versions`; `strategies/versions/{uuid}/approve`, `reject`, `publish`, `promote`; `strategies/{uuid}/pause`, `resume`, `rollback`, `archive` · `StudioStrategyVersions` (`:78`, `:142-162`, `:433-441`), `StudioStrategyPublishing` (`:88-124`, `:129-172`) | `ultron_studio_strategy_versions`, `…_reviews`, `…_publications` | — | publicar sí · ventana 07:00-19:00 · canario ≥ 24 h y ≥ 3 contactos · simulación `required` | **LISTO PARA ACTIVACIÓN AUTORIZADA** | Congelar (`:78`), aprobar (`:435`), publicar (`StudioStrategyPublishing.php:97`) y pausar (`:184`) piden persona vinculada, y hay 0 [P]. En sombra no se puede promover: la evidencia del canario solo cuenta trabajos en vivo (`StudioStrategyEvidence.php:112-115`) | P1–P8 | `UltronStudioStrategyLifecycleTest`, `…FourEyesTest`, `H9RollbackTest`, `H9PauseCancelTest` | Versión CANARY con su entrada firmada en la cadena |
| 8 | Vínculos de personas | `ca-bindings.ts` · `vinculos` | `GET/POST strategies/person-bindings`, `…/{uuid}/verify`, `…/{uuid}/revoke` · `StudioPersonBindings`; consola `ultron-studio:person-bindings` | `ultron_studio_person_bindings`, `identities`, `staff_profiles` | — | — | **LISTO PARA ACTIVACIÓN AUTORIZADA** | Los dos primeros vínculos solo se crean por consola y con autorización escrita (`PersonBindingsCommand.php:20-23`, `StudioPersonBindings.php:290-311`). Hoy hay 0 [P] | P1 | `UltronStudioStrategyPersonBindingsTest::test_el_arranque_solo_por_consola_con_autorizacion_y_una_sola_vez` | 2 vínculos `bootstrap` verificados |
| 9 | **Motor en sombra**: detección, evaluación, prioridad, escalera y trabajo simulado | Panel (pasadas, métricas) | Programador: `outreach:sweep` cada 10 min, que hace ingesta, barrido y plan (`routes/console.php:509`), y `outreach:dispatch --inline` cada minuto (`:536`) · `OutreachPlanner`, `OutreachDispatcher`, `SimulatedChannel` | `outreach_runs`, `…_enrollments`, `…_decisions`, `…_jobs`, `…_outcomes` | — | maestra sí · `shadow` · programador sí | **INCOMPLETO** | (a) Hay 0 estrategias en la cadena firmada, así que el barrido y la ingesta recorren una lista vacía (§4.1). (b) Aunque hubiera una, N3 niega `canary_only_declared` en sombra a toda persona que no sea canaria (I-1, comprobado con una ejecución). (c) Los socios sin prospecto de WhatsApp se niegan en N1 por falta de consentimiento (I-2) | P1–P8 más I-1 e I-2 (o empezar por prospectos) | `OutreachPlannerTest::test_en_sombra_la_inscripcion_se_planifica_como_un_trabajo_simulado_sin_reserva_y_con_su_decision` (con un canario: `RunWorld.php:286-299`) | Pasadas `sweep` con recuentos; decisiones `enroll` y `plan` o `deny`; trabajos `simulated` |
| 10 | Consentimiento de los socios por finalidad y canal (C9) | — (no hay pantalla) | No existe `outreach_consents` (contrato C9:960). Hoy solo `LeadFacts.php:236-253`: el prospecto con conversación de WhatsApp y `consent_status = granted` | `marketing_leads`, `marketing_conversations` | Base legal P9-14 | — | **INCOMPLETO** | El contrato dice, en C9:861: «Socios sin lead: **ninguna** hasta [P9-14] y C9». Ejecución pura: N1 `no_consent_for_purpose_channel` | I-2 (L) | `H9LeadFollowupTest::test_6_sin_autorizacion_expresa_n1_niega_no_consent_for_purpose_channel_…` (demuestra la negativa) | Un socio con su autorización registrada pasa N1 |
| 11 | Monitor del H9 | Panel (frenos) | `outreach:monitor` cada 10 min (`routes/console.php:548`) · `OutreachMonitor` | `outreach_brakes`, incidentes de IRON GUARD | — | monitor sí | **EN OBSERVACIÓN** | 80 pasadas `shadow/completed` [P], sin nada que vigilar | — | `OutreachStopAndGateTest::test_el_monitor_apagado_no_escribe_y_encendido_echa_el_freno_global_con_la_cadena_ilegible`, `H9MonitorThresholdsTest` | Frena ante picos de bajas o de fallos, o con la cadena ilegible |
| 12 | PARADA GLOBAL y frenos | `ca-panel.ts` (secciones «PARADA GLOBAL» y «Frenos») | `POST outreach/brakes`, `POST outreach/brakes/{uuid}/release`; `outreach:stop --all` · `OutreachStop` | `outreach_brakes` | — | maestra sí | **LISTO PARA ACTIVACIÓN AUTORIZADA** | Implementada y probada en automático; nunca ejercitada en producción (CIERRE §5 #15) | Simulacro autorizado en sombra | `OutreachStopAndGateTest` (9), `OutreachPlannerTest::test_con_la_parada_global_echada_no_nacen_inscripciones_y_la_pasada_queda_aborted` | La pasada siguiente queda `aborted` con `outreach_brake_global` |
| 13 | Bandeja asistida (canal asistido) | `ca-assisted.ts` · `bandeja` | `GET outreach/assisted/queue`, `POST outreach/assisted/{uuid}/claim`, `…/outcome` · `AssistedQueue`, `AssistedChannel` | `outreach_jobs`, `outreach_outcomes` | — | asistido no · cupo 0 | **LISTO PARA ACTIVACIÓN AUTORIZADA** | Espera una sombra con contenido (P9-11), P9-12 y `asistido on --cupo=N` | — | `OutreachDispatcherTest` | Trabajos asistidos con su guion |
| 14 | Lecturas: historial por persona, inscripciones, trabajos, decisiones y métricas | `ca-timeline.ts` · `historial`; `ca-panel.ts` | `GET outreach/people/{subjectRef}/timeline`, `outreach/enrollments`, `outreach/jobs`, `outreach/decisions/{uuid}`, `outreach/metrics` | las de `outreach_*` | — | — | **OPERATIVO Y PROBADO** (lectura) | Vacías porque no hay estrategias. Smoke [P] | — | `OutreachApiTest`, `SubjectTimelineTest` | — |
| 15 | Tiempo real (SSE) | `StudioStreamService` | `GET admin/ultron-studio/stream` · `UltronStudioStreamController` | — | — | stream sí | **OPERATIVO Y PROBADO** | El smoke del H9 cubre la ruta (`smoke-h9.py:21-22`) [S: no releí su salida] | — | `OutreachStreamTest` | — |
| 16 | **WhatsApp automático (W9)** | Panel, fila «Canal WhatsApp» | `OutreachSendGate` (`:39`, `:46`, `:89-96`) → `WhatsappTemplateChannel` → `MarketingMessageDispatcher::dispatchWhatsappTemplate`; `outreach:whatsapp-outcomes` cada minuto | `marketing_messages` y su outbox, `ultron_studio_template_meta_checks`, `outreach_budget_counters` | Meta (plantilla aprobada) | W9 no · cupo 0 · presupuesto 0 · precios 0 · constante cerrada | **LISTO PARA ACTIVACIÓN AUTORIZADA** para prospectos con consentimiento; para socios, fila 10 | Cerrado a propósito: la constante (commit `outreach(sends-open):` con autorización y visto bueno legal), el modo `live` (después del asistido), los cupos y precios en 0 y ninguna plantilla aprobada por Meta | — | `WhatsappTemplateChannelTest` (14), `WhatsappTemplateOutcomesTest` (9), `OutboxTemplateRetryRecheckTest`, `OptOutWordHardeningTest` | Un trabajo, un mensaje en el outbox con su correlación |
| 17 | **Voz automática (V9)** | Panel, fila «Canal voz» | `VoiceStrategyChannel` → `OutboundGate::requestForStrategy()`; `outreach:voice-outcomes` | `voice_calls`, `voice_contacts`, `voice_consents` | Twilio Voice (claves y número), ElevenLabs (número importado) | V9 no · cupo, techo y reserva en 0 · `CALLS_ENABLED_IN_CODE` cerrada · `VOICE_CONTACTS_ENABLED` no | **BLOQUEADO EXTERNAMENTE** | Faltan las claves `TWILIO_VOICE_*` y la cuenta tiene 0 números propios; ElevenLabs no tiene número importado [P, estado de la voz]. Además, dos constantes de código cerradas | Decisión de Twilio (CIERRE §0.4.5) | `V9VoiceChannelTest`, `V9OpenGateTest`, `V9StrategyGateTest`, `V9VoiceOutcomesTest`, `VoiceBudgetTest` | Una llamada por trabajo, por la puerta del H8 |
| 18 | Clasificador de respuestas (AI9) | Panel: «Clasificador de respuestas (AI9)» (`ca-panel.ts:66`) | Solo existe la bandera `OutreachConfig::classifierEnabled()` (`:144-147`); su único lector es `status()` (`:286`) [C] | — | OpenAI | no | **INCOMPLETO** (no implementado) | No hay ninguna clase que clasifique. `classifier` no está en las fuentes disponibles (`OutcomeTable.php:65`). El mando se niega a encenderlo si no hay una clase `*Classifier` (`h9-outreach-scope.sh:637`) | I-7 (L) | Solo la regla asimétrica (`H9OutcomeTableTest`) | — |
| 19 | Eventos especiales del gimnasio y campañas como disparador (EV9) | Constructor (catálogo) | `OutreachCatalog` (`:173-181`, `:467-489`) | `commercial_events` | — | — | **INCOMPLETO** | No hay eventos especiales ni un hecho de campaña o anuncio. Los `integration.*` tienen `available: false` (`:484-486`). Seis eventos del catálogo no los emite nadie (I-5) | I-5 (S–M), I-8 (L) | `OutreachCatalogTest` | — |
| 20 | Meta Ads: panel «Mercadeo digital (Meta)» | `components/marketing-digital-panel.ts` en `/marketing` | `GET admin/marketing/meta-dashboard`, `…/campaigns`, `…/leads`; `POST …/sync` · `MetaDashboardController`, `MetaAdsSync`, `MetaAdsApiClient` (solo GET: `MetaAdsApiClient.php:13-15`, `:568-571`) | `meta_ad_entities`, `meta_ad_insights_daily`, `meta_sync_runs` | Marketing API (token de anuncios) | pasada horaria [S: memoria del 06-10, no releído hoy] | **OPERATIVO Y PROBADO** (consulta) | Solo lee Meta; escribe en su espejo local | — | (no ejecutadas aquí) | — |
| 21 | Campañas de Meta en el Studio | `studio-integrations-page.ts` (`/marketing/ultron-studio/integraciones`) | `GET integrations/meta/campaigns` · `StudioMetaCampaigns` (sin red: lee el espejo, `StudioMetaCampaigns.php:14-35`) | `meta_ad_entities`, `meta_sync_runs` | — | escrituras del H6 sí (L9-101) | **OPERATIVO Y PROBADO** (consulta local) | — | — | pruebas de integraciones del H6 | — |
| 22 | Asociar una campaña al Studio | `studio-campaign-link-dialog.ts` | `POST integrations/meta/links`, `…/{uuid}/deactivate` · `StudioCampaignLinks` | `ultron_studio_campaign_links` | — | escrituras del H6 sí | **LISTO PARA ACTIVACIÓN AUTORIZADA** (solo registra) | Nadie la lee fuera del Studio: ni el motor ni el H9 (grep [C]). El CRM lo dice (`studio-integrations-text.ts:56`) | — | — | Un registro con su historial |
| 23 | Plantillas de Meta: estado y sincronización | `ca-templates.ts` (bloque «Conexión con Meta») | `GET strategies/templates/meta/status`; `ultron-studio:meta-templates-sync` cada 5 min · `StudioMetaTemplates::sync()` | `ultron_studio_meta_template_submissions`, `ultron_studio_template_meta_checks` | Graph v25.0 | `full` · `META_ENABLED` | **LISTO PARA ACTIVACIÓN AUTORIZADA** | La sincronización corre, pero **nunca ha hablado con Meta**: sin envíos ni registros sale `nothing_to_watch`, «Sin red» (`MetaTemplatesSyncCommand.php:44`) | P1 → §4.7 | `MetaTemplateSyncTest` | — |
| 24 | Plantillas de Meta: «Comprobar permisos» (sonda) | botón en `ca-templates.ts:63` | `POST strategies/templates/meta/probe` · `StudioMetaTemplates::probe()`, que pide persona vinculada (`:316-318`) → `WhatsappTemplateProbe` (dos GET) | — | Meta, permiso `whatsapp_business_management` | `full` | **LISTO PARA ACTIVACIÓN AUTORIZADA** | Con 0 vínculos da 403. El botón se enseña igual: solo mira `ultron.integrations_manage` (`StudioMetaTemplates.php:301`) | P1 | `MetaTemplateRoutesTest::test_la_sonda_con_su_enfriamiento_su_libro_y_su_llave` | `ok` o el motivo |
| 25 | Plantillas de Meta: «Enviar a Meta» y «Comprobar ahora» | `ca-tpl-detail.ts` | `POST strategies/templates/{uuid}/meta/submit` y `…/refresh` · `StudioMetaTemplates::submit()` (persona en `:390`); el ÚNICO POST a Meta está en `GraphWhatsappTemplateClient.php:324-325` | envíos y comprobaciones | Meta (revisión) | `full` | **LISTO PARA ACTIVACIÓN AUTORIZADA** | Hay 0 vínculos y 0 plantillas | P1–P3 y enviar | `MetaTemplateSubmitTest` | `SUBMITTED`; después, el estado de Meta por la sincronización |
| 26 | Plantillas de Meta: registro manual | consola `ultron-studio:template-meta-check` | `StudioTemplateMetaChecks` | `ultron_studio_template_meta_checks` | — | — | **LISTO PARA ACTIVACIÓN AUTORIZADA** | `--por` exige una persona verificada con `ultron.approve` (`TemplateMetaCheckCommand.php:29-34`) | — | `TemplateMetaCheckCommandTest` (10) | Solo para plantillas sin componentes enviadas a mano |

---

## 2. INCOMPLETO, ordenado por lo que más acerca a «el motor detecta oportunidades reales y decide solo, en sombra, con evidencia»

**La causa raíz, en una frase.** La sombra no produce nada porque en la cadena firmada no hay ninguna estrategia. No la hay porque congelar, aprobar y publicar exigen dos personas vinculadas, y hay 0. Eso no es código: es el paso P1 del propietario (§3). Pero, resuelto P1, la sombra seguiría negando a casi todo el mundo por dos huecos técnicos (I-1 e I-2) **[C]**.

**I-1 · N3 niega en sombra a todo el que no es canario.**
- **Qué pasa.**
  - `PolicyLadder.php:337-339` niega `canary_only_declared` cuando la versión del Studio declara `governance.canaryOnly`.
  - No está en los avisos de sombra (`:92-95`). La parte canaria de N5 sí lo está (`:432-434`).
  - Toda versión del Studio referenciable lleva `canaryOnly = true`: la regla R-12 es bloqueante (`StudioCatalog.php:527`, aplicada en `StudioGraphValidator.php:134-137`), y la línea base también la lleva (`StudioCatalog.php:565`).
  - Las pruebas lo esquivan registrando a cada socio como canario (`tests/Feature/Outreach/Run/Support/RunWorld.php:286-299`).
  - El contrato dice lo contrario para la sombra: «en sombra se evalúa la audiencia entera» (D9-11.6, recogido en la línea 4 del generador de la fixture).
- **Ejecución pura [C]:** en sombra, con `canaryOnly = true` y la persona fuera del canario, sale `deny N3 canary_only_declared`; con `false`, `allow` con avisos.
- **Corrección (S):**
  - Añadir `canary_only_declared` a `SHADOW_WARNINGS` (`PolicyLadder.php:92-95`).
  - Añadir un caso «shadow» en `tests/fixtures/outreach/tools/build/sec_ladder.py`.
  - Regenerar `tests/fixtures/outreach/policy-ladder-cases.json` y su copia del CRM (`FE/commercial-automation/testing/fixtures/`, con `sync-h9-fixtures.mjs`).
  - Paridad: `PolicyAndChannelParityTest` y la del CRM.
  - En vivo no cambia nada. En sombra lo notan el plan y el despacho, que repite la escalera (`OutreachDispatcher.php:309`), y también las simulaciones (`StudioStrategySimulator.php:227` usa `shadow`).
- **Quién:** backend, y **security** revisa (toca la escalera). Decide el lead: es una contradicción del contrato.
- **Cuándo:** antes de la primera publicación, para que no haya decisiones históricas cuya repetición (`outreach:replay`) cambie.
- **Alternativa sin código:** registrar como canarios a personas que consienten. Solo esas pasarían N3.

**I-2 · Los socios no tienen fuente de consentimiento (C9).**
- **Qué pasa.** `CoreProjection.php:152-160` lee `consent.whatsapp.<finalidad>`. `LeadFacts.php:236-253` solo lo da a un prospecto con conversación de WhatsApp y `granted`. Para cobranza vale `false` siempre (`:253`; P9-15).
- **Consecuencia:** renovación, vencidas, inactivos y clases se niegan en N1 para todo socio sin ese prospecto, y además en N4 `no_whatsapp_identity` (contrato :600). C9 incluye también «el camino de identidad de WhatsApp para socios sin lead» (contrato :1185). El volumen está sin medir [S]; T4 lo mide.
- **Corrección (L):**
  - Tabla `outreach_consents` (contrato C9:960): de solo inserción, con cuatro ojos cuando la carga una persona. La hace **database-specialist**.
  - Su adaptador en `app/Services/Outreach/Facts/` y su lectura en `CoreProjection`.
  - La pantalla de alta en el CRM.
  - La base legal P9-14.
- **Mientras tanto:** la primera estrategia, sobre prospectos (`lead.followup_due`, `lead_created`, `lead_qualified`).

**I-3 · Textos del CRM que no dicen la verdad.** Están en §4.8. Tamaño S; frontend.

**I-4 · El barrido mira solo la primera página.**
- **Qué pasa.** `OutreachPlanner.php:137` pide siempre `page(…, 0)`; si hay más de 500, anota `more` y no guarda cursor. Las personas con id alto nunca se evalúan.
- **Dónde se nota:** más probable en `member.inactive` y `class.upcoming_with_capacity`. El volumen está sin medir [S].
- **Corrección (S–M):** un cursor por estrategia y disparador en `outreach_cursors`. Backend y tester.

**I-5 · Seis eventos del catálogo no los emite nadie.**
- **Cuáles:** `customer_satisfied`, `objection_raised`, `offer_presented`, `offer_rejected`, `referral_opportunity` y `complaint_created` (este último no abre inscripciones).
- **Comprobación [C]:** buscados en `app/Observers/Commercial/*`, `ScanCommercialFacts` y el resto de `app/`. `EV_OBJECTION_RAISED` solo aparece como etiqueta (`SupervisionController.php:614`). Los otros 21 sí tienen emisor.
- **Consecuencia:** el constructor los ofrece (filtra solo por `triggerable && available`, `FE/commercial-automation/commercial-automation-catalog.ts:232`); una estrategia con ese disparador se puede crear y nunca se dispara.
- **Corrección:** marcarlos `available: false` (`OutreachCatalog.php:458-466`), lo que sube `VERSION` y obliga a regenerar `catalog-snapshot.json` en los dos repos (S–M), o escribir su emisor.

**I-6 · Faltan pruebas de extremo a extremo de algunos disparadores.**
- `member.inactive`, `payment_link.abandoned` y `receivable.overdue` solo tienen prueba del prefiltro (`MoneyAndAttendanceFactsTest`), no del recorrido barrido → inscripción → decisión.
- `lead_created`, `lead_qualified` y `renewal_window_opened` solo pasan por el camino genérico de la ingesta, probado con `membership_expired` (`OutreachPlannerTest:145-186`).
- Tamaño S; tester.

**I-7 · El clasificador (AI9) no está implementado.** Tamaño L. No hace falta para la sombra (§4.5).

**I-8 · Eventos especiales del gimnasio y campañas como disparador (EV9): no implementados.** Tamaño L.

**I-9 · Documentos que contradicen el código.** Tamaño S; documentation-specialist.
- CIERRE §0.2 «C · Motor en sombra · OPERATIVO Y PROBADO» (`ULTRON_STUDIO_CIERRE_9_HITOS.md:42`): corre, pero en vacío.
- CIERRE §0.4, punto 6 (`:70`): «Comprobar permisos» exige un vínculo, así que depende del punto 2 (`:66`).
- `RUNBOOK_H9.md:117`: dice que el mando no escribe los precios, y sí los escribe (`h9-outreach-scope.sh:20`).
- `RUNBOOK_H9.md:144`, P3 «H9-E2E-1 abierto»: ya está en producción (`5758db9c` es antecesor de `2687119` [C]).
- `VoiceStrategyChannel.php:28-30`: dice «V9 no lo declara», y sí está declarado (`OutreachSendGate.php:46`).

**No es código, pero cambia el calendario: el reloj de la sombra.** La sombra se encendió el 11-11 05:23 UTC con 0 estrategias. Los «7 días sin hallazgos» (P9-11) no han observado nada. Propongo contarlos desde la primera publicación en canario. Lo decide el propietario.

---

## 3. Secuencia mínima del propietario (pregunta 1)

### 3.0 Lo que se deja PREPARADO sin ninguna persona vinculada (equipo técnico, hoy)

| # | Qué | Quién y dónde | Por qué no necesita vínculo |
|---|---|---|---|
| T1 | I-1, si el lead lo aprueba, desplegado **antes de P8** | backend + security | Código |
| T2 | Ubicar las cuentas y las identidades, en solo lectura (orden debajo) | devops, en el servidor | Lectura |
| T3 | Crear la estrategia y su borrador: CRM → Automatizaciones → Estrategias → crear; después, Constructor (detalle debajo) | Cuenta con `ultron.strategies_edit` | `StudioStrategies.php:47-130` no pide persona |
| T4 | Simular el borrador con una **muestra real**: Simulación → «Muestra real» | Super Admin (`commercial_automation.simulate_real`) | Solo lectura más su fila (`StudioStrategySimulations.php:88-135`). Mide la audiencia y cuántos caen en N1 (consentimiento) y N3 (canario) |
| T5 | Redactar fuera del sistema el guion de la plantilla asistida | — | Guardarla sí exige vínculo |
| T6 | Solo si no se hace I-1: registrar y activar números canarios de WhatsApp de personas que consienten (CRM → Studio → Integraciones → canarios) | Dos **cuentas** distintas: una registra; otra autoriza (`ultron.canary_authorize`, solo SA por defecto) y activa (`StudioAccess.php:179-182` compara cuentas) | El registro del H6 pide cuatro ojos por cuenta, no un vínculo de persona |

**Orden de T2.** Imprime nombres y uuids, nunca documentos:

```bash
cd /var/www/api/backend
sudo -u www-data php artisan tinker --execute='foreach (DB::table("admins")->whereIn("id",[27,31,38])->get(["id","name","role"]) as $a) echo "$a->id $a->name $a->role\n";'
sudo -u www-data php artisan tinker --execute='foreach (DB::table("staff_profiles as s")->join("identities as i","i.id","=","s.identity_id")->where("s.status","active")->get(["s.full_name","i.uuid"]) as $r) echo "$r->uuid $r->full_name\n";'
```

- L9-101 dice que hoy hay tres perfiles laborales con identidad (una administrativa y dos recepcionistas) y que las cuentas del Studio son 27 «Desarrolladores», 38 «QA ULTRON Revisor» y 31 «Fredy».
- Si una de las dos personas no tiene perfil, se le crea antes en CRM → Personal, con su documento. No hay columna que una `admins` con `identities`: la correspondencia la declara el propietario [C, `StudioPersonBindings.php:429-442`].

**Contenido de T3.**
- Finalidad: `commercial`.
- Paso con canal `assisted:whatsapp`: no depende de Meta y en sombra es un trabajo `simulated`.
- Referencia del Studio: **la versión APPROVED**, no la línea base (si no, bloqueante `studio_baseline_superseded`).
- Plantilla: de momento, una referencia provisional; validar dirá `template_not_found` hasta P4.
- Límites con `contactsPerDay × enrollmentDays` ≥ la audiencia que dé T4; si no, `audience_saturation` bloquea (`StudioStrategySimulator.php:355-357`).

### 3.1 Pasos con personas (A = autora, B = revisora)

| Paso | Quién | Dónde u orden exacta | ¿Exige vínculo? | No puede ser la misma persona que… |
|---|---|---|---|---|
| **P1** | root, con la **autorización escrita del propietario** | La orden de debajo de esta tabla | Lo crea | A ≠ B por cuenta y por identidad (`StudioPersonBindings.php:292-307`) |
| P2 | A | CRM → Automatizaciones → Plantillas → «Nueva versión de plantilla»: Asistido · WhatsApp · Comercial · guion | Sí (`StudioMessageTemplates.php:688`) | — |
| P3 | B | Plantillas → la versión → «Aprobar» | Sí | La autora de la plantilla (`:750-751`) |
| P4 | A (o quien edite) | Constructor → elegir la plantilla en el paso → «Validar» sin bloqueantes → «Guardar» | No | — |
| P5 | A | Gobierno → «Congelar versión» (`POST strategies/{uuid}/versions`). **Quien congela es la autora** | Sí (`StudioStrategyVersions.php:78`) | — |
| P6 | A o B | Simulación → la **versión** → sintética, con al menos una persona que entra y con `granted`; si no, `audience_empty` o `all_denied_by_consent` bloquean (`StudioStrategySimulator.php:351-362`) | No | — |
| P7 | B (`ultron.approve`) | Gobierno → «Aprobar», reconociendo exactamente los avisos (`canary_only_declared` siempre) | Sí | La autora de la versión (`StudioStrategyVersions.php:433-441`); exige la simulación de esa huella (`:159-162`) |
| P8 | B, Super Admin (`ultron.strategies_publish`), **entre 07:00 y 19:00 de Bogotá** | Gobierno → «Publicar en canario» | Sí | La autora. **Quien aprobó sí puede publicar** (`StudioStrategyPublishing.php:97-100`, `:358-367`). Con un paso `voice_call`, además `telephony.call_place` (`:99`) |

Orden de P1:

```bash
cd /var/www/api/backend
sudo -u www-data php artisan ultron-studio:person-bindings --list
sudo -u www-data php artisan ultron-studio:person-bindings --bootstrap \
  --admin-a=<id cuenta A> --identity-a=<uuid identidad A> \
  --admin-b=<id cuenta B> --identity-b=<uuid identidad B> \
  --authorization="<referencia de la orden escrita, 10-160 caracteres, una línea>"
sudo -u www-data php artisan ultron-studio:person-bindings --list
```

**Qué comprueba P1:**
- Ids enteros, uuids bien formados y una autorización de 10 a 160 caracteres sin controles (`PersonBindingsCommand.php:61-72`).
- Dos cuentas distintas, que existan y estén activas.
- Dos identidades distintas que existan (vale cualquier fila de `identities`).
- **Que no haya ningún vínculo vivo** (`StudioPersonBindings.php:292-311`).

**Resultado esperado de P1:** dos filas de origen `bootstrap`, verificadas.

**Dos personas bastan.** A escribe la plantilla y congela; B aprueba las dos cosas y publica.
- Si A es una persona real del equipo técnico con su propia identidad, el propietario (B) solo da **tres clics: P3, P7 y P8**.
- **security:** A y B tienen que ser cuentas **personales**. Vincular la cuenta compartida 27 «Desarrolladores» convierte los cuatro ojos en una ficción.

**Después de P8:**
- El barrido de esa estrategia corre en la pasada siguiente: cada 10 min, con la cadencia del disparador de 15 o 60 min (`OutreachCatalog.php:473-482`).
- A mano y en sombra, sin contactos pero escribiendo filas: `sudo -u www-data php artisan outreach:sweep --force --json`, con autorización.
- **Evidencia que debe aparecer:**
  - En `h9-outreach-scope.sh estado`: «personas vinculadas y verificadas 2» y «versiones en canario o publicadas 1».
  - En el Panel: pasadas `sweep` con candidatos, evaluados, inscritos y fuera.
  - Decisiones `enroll`, y después `plan` (trabajo `simulated`) o `deny` con su nivel y motivo.
  - Historial por persona con la traza.
- **Sin I-1**, toda persona no canaria acabará en `deny N3 canary_only_declared`. Sigue siendo una decisión auditable, pero no ejercita N4–N7.

---

## 4. Respuestas

### 4.1 Por qué la sombra no produce nada (pregunta 1)

1. Las únicas pasadas que crean filas recorren las estrategias **vivas en la cadena firmada**.
   - `sweep()` (`OutreachPlanner.php:118`) e `ingest()` (`:179`) iteran `runnableStrategies()`. Esa lista sale de `ultron_studio_strategy_publications` (`OutreachPublishedStrategies.php:68-111`, `:135-138`).
   - Con 0 estrategias, el barrido no abre ni una pasada (solo abre una por estrategia, `:133`). La ingesta lee `commercial_events`, no tiene a quién dárselos y **avanza el cursor igual** (`:186-210`): esos eventos ya no dispararán nada, por diseño (no retroactivo).
   - `dispatch` y `reconcile` corren sobre 0 trabajos.
   - Eso explica los números: 809 `dispatch`, 809 `reconcile`, 89 `ingest`, 80 `monitor`, 0 `sweep` y 0 de todo lo demás [P].
2. **No existen `outreach_opportunities` ni `outreach_events`**, y no es un fallo: la detección que no inscribe solo deja recuentos en `outreach_runs.counts` (P9-9, `OutreachPlanner.php:282-285`). La evidencia de una oportunidad es la pasada con sus recuentos, la inscripción y su decisión `enroll`.
3. No hay estrategias porque **cada eslabón exige una persona vinculada**, y hay 0 [P]:
   - el paso necesita una plantilla aprobada (`StudioStrategyValidator.php:236-247`);
   - la plantilla se crea con vínculo (`StudioMessageTemplates.php:688`);
   - congelar exige vínculo (`StudioStrategyVersions.php:78`);
   - aprobar, otra persona (`:433-441`);
   - publicar, alguien distinto de la autora (`StudioStrategyPublishing.php:97-98`).
4. Aunque hubiera una estrategia, la sombra negaría casi todo: **I-1 (N3)** e **I-2 (N1)**.

### 4.2 Detección de oportunidades (pregunta 2)

| Tipo | Disparador o hecho | Adaptador | Prueba | Estado |
|---|---|---|---|---|
| Membresías por vencer | `membership.expiring_window` (60 min) | `MembershipFacts::endingBetween` (`:167`) | `OutreachPlannerTest::test_1_…`, `test_2_…`; `MembershipFactsTest::test_vence_en_n_dias_entra_y_en_n_mas_1_no`, `test_vence_hoy_es_el_dia_de_bogota_a_las_0450_utc` | Implementado y probado de extremo a extremo |
| Vencidas | `membership.expired_window`; evento `membership_expired` | `MembershipFacts::endedBetween` (`:181-204`); ingesta | `OutreachPlannerTest::test_3_…`, `test_14_…` (ingesta) | Implementado y probado |
| Renovaciones | `membership.expiring_window` y el evento `renewal_window_opened`; éxito por `membership.days_to_expiry` | `MembershipFacts::renewedSince` (`:132`) | `MembershipFactsTest::test_renovo_desde_un_instante_por_un_cobro_de_membresia` | Implementado. El evento no tiene prueba propia (I-6) |
| Miembros inactivos | `member.inactive` (60 min); evento `inactivity_detected` | `AttendanceFacts::inactiveSince` (`:92-109`) | `MoneyAndAttendanceFactsTest::test_prefiltro_de_inactividad_excluye_a_quien_nunca_vino_y_respeta_el_borde` | Implementado, solo con prueba del prefiltro (I-6); puede pasar de 500 (I-4) |
| Prospectos interesados | eventos `lead_created`, `lead_qualified`; hechos `lead.temperature`, `lead.commercial_phase` | `LeadFacts` | `LeadAndVoiceFactsTest::test_temperatura_y_fase_solo_del_dominio_del_catalogo` | Implementado. Los dos eventos no tienen prueba propia (I-6) |
| Seguimientos pendientes | `lead.followup_due` (15 min) | `LeadFacts::followupsDue` (`:115-123`) | `H9LeadFollowupTest` (4) | Implementado y probado de extremo a extremo. N2 lo niega si el seguimiento heredado está encendido (`PolicyLadder.php:309-311`); hoy está apagado [P] |
| Eventos y campañas | 21 de 27 eventos `crm_fact` tienen emisor; los `integration.*` no están disponibles | ingesta por cursor | genérica (`membership_expired`) | **INCOMPLETO**: 6 eventos sin emisor (I-5); sin eventos especiales ni campañas (I-8) |
| Clases o servicios | `class.upcoming_with_capacity` (60 min), `valuation.outdated`; eventos `appointment_*` | `ClassFacts` (`:113`, `:198`), `ValuationFacts::outdated` (`:104-131`) | `H9EventSegmentTest` (3) | Implementado y probado |
| Cartera o pagos | `payment_link.abandoned` (15 min), `receivable.overdue` (cobranza); bloqueo comercial por cartera de más de 15 días (`PolicyLadder.php:478-480`, `:531-539`) | `PaymentFacts::abandonedLinks` (`:130-142`), `ReceivableFacts::overdueBetween` (`:84-98`) | `MoneyAndAttendanceFactsTest` (prefiltros); fixture de la escalera, N6 (`PolicyAndChannelParityTest`) | La detección y el bloqueo están implementados. **Contactar por cobranza es imposible**: `consent.whatsapp.collections = false` (`LeadFacts.php:253`, P9-15) → **BLOQUEADO EXTERNAMENTE** (base legal), además de C9 |

### 4.3 Las 15 comprobaciones de la decisión (pregunta 3)

| # | Comprobación | Dónde | Prueba |
|---|---|---|---|
| 1 | Sujeto | `OutreachPlanner.php:257-261` (`unknown_subject`); N1 `PolicyLadder.php:264-266` | `SubjectAndSnapshotTest`, `FactsCoreUnionTest` |
| 2 | Elegibilidad | `EnrollmentGate` (audiencia → exclusiones → éxito), `OutreachPlanner.php:280-286`; se vuelve a mirar en N6 (`PolicyLadder.php:472-474`) | `OutreachPlannerTest` 1–3, `ConditionsParityTest` |
| 3 | Consentimiento por canal | N1 `PolicyLadder.php:237-242`; `CoreProjection.php:152-171` (`consent.whatsapp.*` y `consent.voice_call.*` son claves **separadas**: el de WhatsApp no vale para la voz) | `H9LeadFollowupTest::test_6_sin_autorizacion…`, `LeadAndVoiceFactsTest::test_autorizacion_de_whatsapp_por_finalidad_y_senales_negativas`, `V9VoiceChannelTest::test_sin_consentimiento_de_voz_niega_n1_antes_de_llegar` |
| 4 | Exclusiones | Las de la estrategia (`EnrollmentGate`); bajas, `do_not_contact` y hermanos (`PolicyLadder.php:243-252`); voz y RNE (`:253-260`); menores (`:261`); supresión global N2 (`:312-314`, `SubjectSuppressions`) | `LeadAndVoiceFactsTest::test_el_opt_out_de_un_hermano…`, `OptOutAcrossMediaTest`, fixture N1 |
| 5 | Horario | `ContactCalendar` (ley, horario propio y el de la estrategia): N1 `calendar_*`, N5 `:435-441`, hueco en `OutreachPlanner.php:556` | `H9ScheduleTest`, `CalendarAndBudgetParityTest`, `OutreachStopAndGateTest::test_el_horario_propio_solo_estrecha_y_mal_escrito_cierra` |
| 6 | Frecuencia | `ContactBudget::check` en N1 (`:233`); `OutreachConfig::effectiveLimits()` (`:195-206`); tope diario por teléfono | `CalendarAndBudgetParityTest` |
| 7 | Últimos contactos | Historial (`ContactHistoryFacts`) más los iniciados sin resultado (`OutreachPlanner.php:459`) | `AgendaEngineHistoryFactsTest::test_historial_cuenta_salientes_proactivos…` |
| 8 | Presupuesto | N4 `budget_zero`, `budget_exhausted` y `channel_daily_cap` (`PolicyLadder.php:395-411`); reserva atómica (`OutreachPlanner.php:597-602`) | `H9ZeroCostBudgetTest`, `VoiceBudgetTest`, `OutreachConcurrencyTest` |
| 9 | Estrategia | N5: publicada, pausa, cadena, toques, tope diario, ventana, enfriamiento y guarda (`PolicyLadder.php:417-461`) | `H9PauseCancelTest`, `H9RollbackTest`, `H9ChainOrphansTest` |
| 10 | Versión aprobada | Solo lo que publica la cadena firmada (`OutreachPublishedStrategies.php:68-111`); N3 comprueba la referencia y el catálogo (`PolicyLadder.php:320-342`) | `UltronStudioStrategyLifecycleTest`, `StrategyContentAndChainParityTest` |
| 11 | Canal | `ChannelSelector` + N4: banderas y puerta (`PolicyLadder.php:366-374`) | `H9ChannelSelectorTest`, `OutreachStopAndGateTest::test_todo_nace_apagado_y_la_constante_de_envio_cerrada` |
| 12 | Plantilla o guion | `templateState()`: aprobada, misma huella, del canal y de la categoría (`OutreachPublishedStrategies.php:249-290`); N3 R-xx; W9 `metaApproved()` (`:363-385`) | `WhatsappTemplateChannelTest::test_sin_correspondencia_aprobada_por_meta…`, `…test_la_ultima_comprobacion_de_meta_manda…` |
| 13 | Idempotencia | Ocurrencia única por estrategia × persona × ciclo × modo (`OutreachPlanner.php:264`, `:292-301`); un trabajo vivo por persona (`JobCollision`); puerta de W9 idempotente por trabajo | `OutreachPlannerTest::test_14_…`, `H9IdempotencyRaceTest`, `OutreachRaceTest`, `WhatsappTemplateChannelTest::test_la_puerta_de_marketing_es_idempotente_por_trabajo` |
| 14 | Resultado esperado | `success` y `goal` de la estrategia; N6 `success_reached` → `exit_converted`; tabla D9-10; `appointment_booked` por la ingesta (`OutreachPlanner.php:676-687`) | `OutreachOutcomesTest`, `H9OutcomeTableTest`, `H9EventSegmentTest::test_7_quien_reserva_entre_planificar_y_despachar_sale_converted…` |
| 15 | Registro auditable | `outreach_decisions` con la traza y los hechos, sus huellas y el sello de la versión (`DecisionTrace`); `outreach:replay` | `DecisionTraceAndPurityTest`, `OutreachPlannerTest` (huellas de la traza), `OutreachLedgerSchemaTest` |

### 4.4 W9 y V9 (pregunta 4)

**W9: completo en código.**
- Sale por la única puerta de WhatsApp, `MarketingMessageDispatcher::dispatchWhatsappTemplate`, y el canal nunca nombra a Meta.
- El canal vuelve a mirar ocho cerrojos propios (cabecera de `WhatsappTemplateChannel.php`): la constante, el modo, la bandera, la reserva, la plantilla del sello, la aprobación de Meta con sus componentes, el consentimiento y las palabras de baja seguras o posibles, y los marcadores con R-xx.
- Reintentos: los hace el outbox. El canal no reintenta; un caso ambiguo se concilia por su correlación.
- Duplicados: un trabajo, un mensaje.
- El normalizador de estados y respuestas corre cada minuto.
- El mando ya escribe precios, cupos y presupuesto (`h9-outreach-scope.sh:20`, `:66-68`).

**W9: lo que lo cierra.**
- La constante (commit `outreach(sends-open):` con autorización escrita y visto bueno legal).
- El modo `live`, que llega después del asistido.
- Las banderas, cupos, presupuestos y precios en 0.
- Ninguna plantilla aprobada por Meta.
- El consentimiento: solo prospectos (I-2).

**W9: pruebas en condiciones controladas.**
- `WhatsappTemplateChannelTest::test_con_la_puerta_abierta_solo_en_la_prueba_el_mensaje_queda_en_el_outbox_con_su_correlacion_una_sola_vez`
- `…test_cada_cerrojo_del_canal_cierra_por_su_cuenta`
- `WhatsappTemplateOutcomesTest::test_un_trabajo_ambiguo_se_concilia_por_su_correlacion`
- `…test_la_palabra_de_baja_es_opted_out_con_supresion_y_cancela_lo_pendiente_de_todas_las_estrategias`
- `OutboxTemplateRetryRecheckTest`

**V9: completo en código.**
- `OutboundGate::requestForStrategy()` y una reserva por llamada.
- El consentimiento de voz se confirma a cuatro ojos, con RNE (cabecera de `VoiceConsentFacts.php`).
- `voice-outcomes` normaliza el final de las llamadas.

**V9: lo que lo cierra.** Las dos constantes (`SENDS_ENABLED_IN_CODE` y `CALLS_ENABLED_IN_CODE`), Twilio Voice sin configurar, el número de ElevenLabs sin importar y `VOICE_CONTACTS_ENABLED` apagado: hoy no se puede ni registrar un consentimiento de voz [P].

**V9: pruebas.** `V9VoiceChannelTest`, `V9OpenGateTest::test_la_peticion_llega_a_la_puerta_y_queda_requested_hasta_el_worker_falso` y `V9NoBypassStaticTest`.

**Ninguno de los dos se ha ejercitado contra un proveedor real** (CIERRE §5 #13, #14 y #16).

### 4.5 Clasificador (pregunta 5)

- **No está implementado** [C]. `COMMERCIAL_AUTOMATION_CLASSIFIER_ENABLED` solo se lee para enseñarla en el panel (`OutreachConfig.php:144-147`, `:286`).
- Encenderlo no llamaría a ningún modelo, no costaría nada y no escribiría nada. Solo haría que el panel dijera «sí» sobre algo que no existe.
- El mando lo impide: niega `clasificador on` si en el código desplegado no hay una clase `*Classifier` (`h9-outreach-scope.sh:637`).
- Lo que sí existe es la regla asimétrica de la tabla de resultados: un resultado `classifier` solo frena, deriva o espera, con umbrales de 50 y 90 (`OutcomeTable.php:73-95`, `:162-192`).

**«No volver a contactar» se trata hoy sin IA:**
- Una baja segura de la lista cerrada → `opted_out`, supresión y se cancela lo pendiente de todas las estrategias.
- Una posible baja («no me escriban», «no me llamen»…) → no se envía y la revisa una persona (`WhatsappSignals.php:28-44`).
- En voz, `no_volver_a_llamar` → `opted_out` (V9).

**Prudencia:** no tiene sentido encenderlo. Implementarlo es AI9 (L): OpenAI con `store: false`, solo el texto de la respuesta, su presupuesto y revisión de security (contrato §855).

### 4.6 Meta Ads (pregunta 6)

| Función del CRM | Qué hace en Meta | ¿Dice la verdad el CRM? |
|---|---|---|
| Mercadeo digital (Meta): gasto, campañas y prospectos | **Consulta**: solo GET a la Marketing API (`MetaAdsApiClient.php:13-15`, `:568-571`) | Sí |
| «Sincronizar» (`POST meta-dashboard/sync`) | Lee de Meta y escribe solo el espejo local | Sí |
| Studio → Integraciones → campañas | Consulta el espejo **local**, sin red | Sí |
| Asociar o desactivar una campaña en el Studio | **Solo registra**; nadie la lee fuera del Studio | Sí (`studio-integrations-text.ts:56`, `:138`, `:324`) |
| Plantillas: comprobar, sincronizar, «Comprobar ahora» | Consulta (GET) | En parte: dice «Conectada» antes de comprobar nada (§4.8) |
| Plantillas: «Enviar a Meta» | **Escribe**: crea la plantilla en la cuenta de WhatsApp (el único POST, `GraphWhatsappTemplateClient.php:324-325`) | Sí |

Ninguna función crea, pausa ni cambia campañas, anuncios o presupuestos en Meta [C: ninguna ruta de escritura en `routes/marketing.php` ni en `routes/ultron-studio.php`].

### 4.7 Plantillas de Meta: qué falta para la primera prueba real (pregunta 7)

1. **P1 (vínculos).** Sin ellos nadie puede: la sonda pide una persona (`StudioMetaTemplates.php:316-318`). Por eso el punto 6 de CIERRE §0.4 depende del punto 2. Hoy nada del módulo ha hablado con Meta: la sincronización sale `nothing_to_watch`, sin red.
2. **«Comprobar permisos».** La pulsa una cuenta con `ultron.integrations_manage` (solo Super Admin por defecto, `CrmPermission.php:316`) y vínculo.
   - Esperado: `ok`.
   - Si sale `missing_permission` o `no_waba_access`: en el Administrador de Meta hay que asignar la cuenta de WhatsApp y el permiso `whatsapp_business_management` al usuario del sistema. Eso es externo.
3. **Una plantilla de WhatsApp.**
   - La crea A: categoría `marketing` para comercial, ejemplos de las variables (`meta_examples_required`), la línea de baja y un nombre libre en Meta.
   - La aprueba B.
4. **«Enviar a Meta».** La envía cualquiera con `ultron.meta_templates_submit` y vínculo; el servidor vuelve a mirar que autora ≠ revisora (`StudioMetaTemplates.php:1352-1361`).
   - **Es una escritura real en Meta, e irreversible** según el propio código (`CrmPermission.php:332-335`); por eso el permiso es solo de Super Admin por defecto.
5. **Meta la revisa** (externo). La sincronización registra el estado cada 5 min.

**Con 0 vínculos no la puede dar nadie.**

### 4.8 Textos del CRM que no dicen la verdad (pregunta 8)

| Fichero:línea | Dice | Debería decir |
|---|---|---|
| `commercial-automation/commercial-automation-text.ts:29` | «…el envío automático está cerrado en el código (OutreachSendGate) y la automatización nace apagada. Solo se simula o lo ejecuta una persona.» | Que esté hoy en **sombra**, leído del estado: «Hoy no sale ningún mensaje ni llamada: el envío automático está cerrado en el código. La automatización está en sombra: decide y simula; el asistido está apagado.» |
| `commercial-automation/ca-activation.ts:72` | «Ninguna estrategia publicada: falta que otra persona vinculada publique en canario una estrategia aprobada (cuatro ojos).» | La cadena real, con recuentos: «Ninguna estrategia publicada. Antes hacen falta dos personas vinculadas (hoy 0), una plantilla aprobada y una versión aprobada con su simulación.» |
| `commercial-automation/ca-activation.ts:244` y `ultron-studio/activation-state.ts:58` | Canal WhatsApp: «Pendiente de proveedor externo» (Meta), que gana por precedencia | «Bloqueada por condición contractual» mientras no haya ninguna plantilla enviada; «proveedor» solo con un envío pendiente en Meta |
| `commercial-automation/ca-activation.ts:312` | «Programa las pasadas de barrido y despacho; todavía no ha terminado ninguna.» | «Programa barrido, despacho y monitor. No hay barridos porque no hay estrategias publicadas; último despacho: …» |
| `commercial-automation/ca-activation.ts:76` | W9: «hoy su puerta está vacía y falla cerrada» (rama muerta, porque el canal ya es `live`) | Quitarlo o actualizarlo |
| `commercial-automation/ca-panel.ts:66` | «Clasificador de respuestas (AI9)» · «No» | «Clasificador de respuestas (AI9): no implementado; la bandera no hace nada» |
| `commercial-automation/ca-meta-text.ts:381` | «Conectada · Graph v25.0 · …» sin haber comprobado nada | «Configurada, sin comprobar con Meta», y en el detalle: «Comprobar permisos exige una cuenta con persona vinculada» |
| `commercial-automation/ca-templates.ts:50`, `:102` y `:63` | Ofrece «Nueva versión de plantilla», «Crear una versión» y «Comprobar permisos» a cuentas sin vínculo, que reciben 403 | Desactivarlos con «Necesitas persona vinculada» (`session.personBound()`, `commercial-automation-session.ts:180`) |
| `commercial-automation/ca-strategies.ts:58` | «Crea la primera: nace en borrador y no hace nada hasta que se publique.» | Añadir: «Congelar, aprobar y publicar exigen persona vinculada (tu cuenta: no).» |
| `ultron-studio/studio-integrations-text.ts:59` y `:145` | Números canarios: «El motor no los lee en este hito» | Falso para el H9, que lee los activos (`CanaryFacts.php:60-63`) para N3 y N5: «ULTRON por WhatsApp no los lee; la automatización comercial (H9) sí: solo los números activos aquí están en el alcance canario de una estrategia.» |
| Constructor (el catálogo) | Ofrece como disparadores 5 eventos que nadie emite (`complaint_created`, el sexto, no es disparador) | I-5 |

---

## 5. Riesgos y puntos de no retorno

- **P1 se hace una sola vez.** Si se vinculan mal las identidades, se revoca desde el CRM, por otra persona vinculada, y los vínculos siguientes se crean en el CRM. El arranque no se repite mientras quede un vínculo vivo.
- **La cadena solo crece.** Una publicación equivocada se deshace con otra entrada (revertir), nunca borrando.
- **I-1 cambia la escalera.** Hay que hacerlo antes de que existan decisiones. Si se hace después, la repetición (`outreach:replay`) de decisiones antiguas de sombra daría otro resultado. Lo revisa **security**.
- **«Enviar a Meta» es una escritura externa:** reserva el nombre en Meta y la deja visible para su revisión.
- **`outreach:sweep --force`** escribe filas en producción (en sombra, sin contactos).
- **Cuatro ojos con cuentas compartidas** (la 27 «Desarrolladores»): es un control nominal, no real. **security**.

---

## 6. Pruebas ejecutadas en esta auditoría

- **Ejecución pura de la escalera** (sin base ni red; guion en el directorio temporal de la sesión):
  - sombra + `canaryOnly = true` + no canario → `deny N3 canary_only_declared`;
  - con `false` → `allow` con avisos `channel_disabled`, `budget_zero` y `canary_scope_mismatch`;
  - socio sin consentimiento de WhatsApp → `deny N1 no_consent_for_purpose_channel`.
- **PHPUnit** (11-11, 14:11 hora local; una cada vez, con `nice -n 10 php -d memory_limit=2G vendor/bin/phpunit --no-logging`), todo en verde:
  - `OutreachPlannerTest`: 7 pruebas, 83 aserciones;
  - `H9LeadFollowupTest`: 4 pruebas, 67 aserciones;
  - `WhatsappTemplateChannelTest`: 14 pruebas, 121 aserciones;
  - `V9VoiceChannelTest`: 6 pruebas, 75 aserciones.
  - Corren sobre SQLite, no sobre PostgreSQL, y no las repetí sobre la batería completa.
- **No verificado:**
  - las volumetrías reales de I-2 e I-4 (las medirá T4);
  - si la cuenta del propietario es Super Admin;
  - si las dos personas tienen identidad;
  - el contenido de `ultron_studio_canary_numbers` en producción.

## 7. Preguntas abiertas

1. ¿Quiénes son A y B (cuenta e identidad), y B es Super Admin?
2. ¿Se aprueba I-1 (la sombra evalúa a toda la audiencia) o se trabaja con números canarios (T6)?
3. ¿La primera estrategia va sobre prospectos (el consentimiento ya existe) o sobre socios (necesita C9 y P9-14)?
