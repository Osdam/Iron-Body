# ULTRON STUDIO: auditoría de la arquitectura real

**Fecha:** 17-10-2026. **Hito A del encargo.** Todo lo que sigue sale de leer el código y la documentación del repositorio; nada se ejecutó contra producción ni contra servicios externos. Lo que no pudo verificarse está marcado.

**Rutas.** `‹B›` = `Iron-Body/backend`; `‹U›` = `‹B›/app/Services/Marketing/Ultron`; `‹M›` = `‹B›/app/Services/Marketing`; `‹CRM›` = `Iron-Body_Front`.

## 1. Estado de los repositorios al empezar

| Repositorio | Rama de trabajo | Base | Árbol |
|---|---|---|---|
| `Iron-Body` (backend) | `feat/ultron-studio` | `origin/main` `a406972` | limpio |
| `Iron-Body_Front` (CRM) | `feat/ultron-studio` | `origin/main` `4334dd8` (lo publicado en producción) | limpio |

- Los worktrees están en `_worktrees/thermal-receipts/`. El checkout principal del CRM tiene un cambio ajeno sin confirmar (`member-details-modal.ts`) que no se toca.
- Hay otros 15 worktrees de trabajos anteriores; ninguno se usa aquí.
- **Correcciones a la premisa del encargo:** el backend es **Laravel 12** (12.58.0 en `composer.lock`), no 11; y el razonamiento de ULTRON **no corre en Laravel ni en Python**: los modelos corren en **n8n**, y Laravel es la autoridad (contexto, controles, commit y envío).

## 2. Qué es ULTRON hoy

Un agente comercial de **texto por WhatsApp** (Meta Cloud API) para prospectos. No hace llamadas, no transcribe notas de voz y no usa Twilio. Atiende **una sola conversación** (el canario), no un porcentaje de tráfico.

**Recorrido real de un turno:**

1. **Meta → Laravel.** `POST /api/webhooks/meta` (`WebhookMetaController:60`): tamaño, firma `X-Hub-Signature-256`, evento crudo deduplicado, 200 inmediato y cola `whatsapp-high`.
2. **Registro y enrutado.** `ProcessMetaWebhookEvent::handleMessage` (:229): lead, conversación, atribución, cerrojo por conversación, mensaje deduplicado por `wamid`, y `MarketingInboundMessageRouter::route` (:45). Lo no soportado (medios sin texto, reacciones) va a una persona o se ignora.
3. **Puerta de ULTRON.** `UltronEventEmitter` (`‹U›/UltronEventEmitter.php:42-123`) comprueba **13 motivos** en orden: interruptor, freno, pausa, canario, lead, canal, entrante, autor, no-contactar, toma manual, `ai_enabled`, reacción, texto legible. Si pasa, crea el evento (único por `wamid`) y encola `SendUltronEventToN8n`.
4. **Laravel → n8n.** `POST` a `MARKETING_ULTRON_WEBHOOK_URL` con Bearer y HMAC (`N8N_WEBHOOK_SECRET`), `X-Idempotency-Key` = `wamid`; 45 s de espera porque n8n responde al final; 3 intentos. El payload no lleva teléfono.
5. **n8n (workflow `Iron Body - ULTRON - Commercial Advisor`, no está en el repo):** «Pedir Contexto al CRM» (`POST /api/internal/marketing/ai/decide`) → **Strategist** (LLM) → «Validar Estrategia» (código) → **Composer** (LLM) → **Critic Comercial** (LLM) → si falla, **Composer Reintento** y **Critic 2** → «Ejecutar en el CRM» (`POST /ai/commit`). Si el commit falla, «Rendirse?» reintenta una vez con `recovery`; el Error Handler abre incidentes en IRON GUARD.
6. **Decide en Laravel** (`‹U›/UltronDecideService.php:334`): llama al **cerebro legado** `SalesAgentOrchestratorService::analyze()`, calcula fase y transiciones legales, memoria, cliente, pago (GET a Wompi solo si la persona dice «ya pagué»), membresía, pistas y herramientas permitidas; firma un **`decide_token`** (APP_KEY, 120 s) con fase, transiciones, `knowledge_version` y estado de pago. El contexto sale **sin precios**.
7. **Commit en Laravel** (`‹U›/UltronCommitService.php:170`): la cadena de **28 controles** (§6). Después persiste (punto de no retorno), aplica la fase, ejecuta herramientas, compone el texto final (marcadores, realineado, respaldos) y lo envía.
8. **Salida.** `MarketingMessageDispatcher::dispatchWhatsapp` (:32): consentimiento, canal, otros leads con el mismo teléfono que pidieron no contacto, pausa; sin `META_ENABLED`, `dry_run`. El outbox entrega por la Graph API y reintenta (máx. 4).
9. **Asíncrono.** El webhook de Wompi actualiza fase y memoria (solo con enlaces de pago, hoy apagados). El vigía `ultron:turn-watchdog` corre cada 5 min.

## 3. Las diez capas del encargo frente a lo que existe

| # | Capa | Existe como | Dónde | Configurable hoy sin código |
|---|---|---|---|---|
| 1 | Conocimiento | tablas + código | `marketing_knowledge_items` (solo activos, vigentes y aprobados), `plans` (sin precio hasta el commit), `GymFactsProvider`, catálogo de la app fijo | base de conocimiento por seeder, consola o API interna (propone borradores); **no hay panel en el CRM**; planes y clases desde sus módulos |
| 2 | Personalidad y tono | repartida | prompts de «voz» en n8n (Composer), `tone.*`/`brand_copy` en la base de conocimiento, `UltronSalesPlaybook`, `ComposerStyleGuard`, `BusinessClock` | base de conocimiento (con aprobación) y prompts en n8n |
| 3 | Comprensión de intención | código + n8n | cerebro legado (27 intenciones, driver hermes→openai→reglas), saludo puro, `ReferenceResolver`, detectores por regex; el Strategist recibe la `base_decision` | driver y parámetros (entorno), prompt del Strategist |
| 4 | Contexto | código | últimos 10 mensajes (cifras y URLs redactadas), `ConversationMemory` (JSON), hora de negocio, novedad (0,85 / 3 salidas en 24 h) | `ai_enabled` y toma manual por conversación |
| 5 | Memoria comercial | tablas + código | `marketing_leads.metadata.ultron_profile`, `CustomerIntelligenceService`, hechos de pago y membresía, `marketing_appointments`. **Contiene datos personales** | nada |
| 6 | Estrategia | n8n + código | Strategist con enums cerrados (`StrategyContract`), 18 fases (`CommercialPhaseMachine`), plan obligatorio (`CommercialTurnPolicy`, insignia = `is_recommended`), pistas firmadas | `Plan.is_recommended` y `sort_order` (cambian lo que se cotiza) |
| 7 | Reglas | código | la cadena del commit, `OutboundContentGuard`, `PaymentFactGuard`, `MembershipFactGuard`, `PromiseAuthority`, autoridades de derivación, consentimiento, cortesías y canario de pago | banderas de entorno; horario estructurado y `brand_copy` en la base (actúan como regla) |
| 8 | Construcción de respuesta | n8n + código | Composer y Critic (LLM) con reintento; marcadores `PLAN_PRICE`, `PLAN_NAME`, `PLAN_DURATION`, `APP_LINKS`; realineado y respaldos en el commit | modelo del cerebro legado (entorno); prompts (n8n) |
| 9 | Integraciones | código | Meta (webhook y envío), puertas de entrada, n8n (decide/commit/incidentes), Wompi (lectura y webhook), CRM | credenciales (entorno), el workflow (en n8n) |
| 10 | Control y evolución | piezas sueltas | `MARKETING_ULTRON_ENABLED`, pausa (CRM, auditada), freno `ultron_aborts` (se suelta con firma), canario por id de conversación (entorno), vigía, acta `ultron:canary-report`, copias de prompts por fecha, tabla de compatibilidad n8n↔backend a mano | pausa (CRM); interruptor, canario y freno (entorno y consola) |

**Conclusión para el grafo.** Las capas son reales pero no son componentes separables: están repartidas entre tablas, código, prompts en n8n y variables de entorno. El grafo del Studio las representa con sus aristas reales (§5) y marca como obligatorias las que son controles.

## 4. Reglas comerciales existentes

Cuatro formas distintas, y solo la primera tiene fuerza de ley:

1. **Autoridad determinista en código** (constantes, regex, clases): el precio solo del catálogo mediante marcador; ningún descuento («gratis», «2x1», «precio especial» incluidos); nunca URLs ni datos de tarjeta; no afirmar pagos ni aceptar capturas; no afirmar membresías sin hechos; no vender el plan que ya se tiene; el plan nombrado manda, si no el insignia; saludo sin vender; no prometer; cortesía como solicitud; derivar solo corroborado; superlativos solo de `brand_copy`; sin presión.
2. **Datos en base de datos:** plan vendible, insignia, plan por defecto, ítems de la base de conocimiento (informativos salvo dos que actúan como regla: `schedule.metadata.windows` y `brand_copy`).
3. **Prompts de n8n:** redacción y estrategia, documentados como ANTES/DESPUÉS en `cambios-prompts.md`. Son sugerencias: el código vuelve a aplicar lo crítico.
4. **`Clausulas`** no es un almacén de reglas: analiza negaciones y correcciones del texto de la persona.

**Versionado:** no existe para reglas en base de datos. El código, con git; los prompts, con el `versionId` de n8n y copias fechadas (`activo.json`, `nuevo3.json`, `nuevo4.json` publicado el 30-09); la base de conocimiento no tiene historial (su «versión» es un sello «n.º de ítems : última modificación»). **No se registra por turno la versión del workflow, del prompt ni del modelo.** Es el hueco principal de la capa 10.

## 5. Dependencias reales entre capas (el grafo)

**Hacia decide:** conocimiento → `knowledge_base`, `active_plans` (sin precio), `gym`, `app`; contexto → `recent_messages`, `memory`, referencia, novedad; memoria → `customer`, `payment` (firmado), `membership`; intención → `decision` (base para el Strategist); estrategia/reglas → `allowed_transitions`, `allowed_tools`, política del turno, playbook.

**En n8n:** Strategist (todo el contexto + decisión base) → Composer (estrategia, contexto, pregunta sugerida) → Critic (estrategia, contexto, borrador) → commit (todo con el token).

**En el commit:** token contra fase/transiciones/`knowledge_version`; estado de pago → `PaymentFactGuard`; membresía → `MembershipFactGuard`; `brand_copy` → `ComposerStyleGuard`; planes → plan y precio; horario → cortesías; perfil → reventa; lo enviado → memoria y ficha.

**Tras el turno:** Wompi → fase y memoria (apagado); vigía → IRON GUARD y freno (solo el canario); pausa → emisor, job, decide, commit, cortesía, despachador y outbox.

El catálogo del Studio (`studio-catalog.ts`) codifica estas aristas: obligatorias las que son controles o sin las que el turno no existe; opcionales solo las dos que hoy están apagadas por bandera (enlace de pago y resultado de Wompi); «abre el turno» la entrada del canal y «tras el turno» las de memoria y vigía, que no cuentan como ciclos.

## 6. Cadena obligatoria del commit (28 controles, en orden)

**Entrada:** firma y tamaño (403/413) → deduplicación por cuerpo y `wamid` → 13 puertas → evento único → interruptor, URL, secreto y pausa → decide: elegibilidad / relevo (422 / `superseded`).

**Commit, antes de persistir (si falla no queda fila):** forma y `unexpected_fields` → idempotencia (409) → interruptor / freno / canario (403) → elegibilidad de ahora / relevo → transiciones y token (`stale_decision`) → hora ambigua de la visita → rendición / Critic fallido → borrador vacío → autoridad de derivación → transición legal y marcadores → tarjeta, URL, pago, membresía → `inspect()` (tarjeta, traspaso, horario aplazado, acción prohibida, afirmación insegura, precio, descuento, URL) → saneado de intención y saludo puro → plan activo y vendible, precio real → tono (presión) → novedad → política del turno y plan forzado → precio ligado al plan → reventa → guardarraíles → invariante de promesas.

**`persist()` (`UltronCommitService:1214`): punto de no retorno.** Después: herramientas (con sus autoridades) → `safe_to_send` → checkout y enlaces de la app → reescrituras de verdad → violaciones de política (texto curado) → despachador → enlace de pago aparte (apagado).

**Para ULTRON STUDIO:** este segmento se dibuja sellado. Una UI no puede insertar nodos entre `composer → rules → integrations` ni quitar esas aristas.

## 7. Canario, interruptores y vigía

- **Canario:** `MARKETING_ULTRON_CANARY_CONVERSATION_ID` (`config/marketing.php:83-86`): **un id de conversación**, en el `.env`, sin auditoría. Se comprueba en el emisor, en el commit (403) y en el vigía; **no en `/ai/decide`**. El runbook del 18-09 lo fija en la conversación 19; una memoria del 21-09 dice que se creó otra y se reapuntó. **Cuál rige hoy no se puede verificar desde el repo.** No hay canario por número ni administración desde el CRM.
- **Canario del cobro:** `MARKETING_ULTRON_PAYMENT_CANARY_CONVERSATION_ID`, independiente; exige Wompi en producción. Los enlaces de pago (`MARKETING_ULTRON_PAYMENT_LINKS_ENABLED`) están **apagados**: certificación vigente, **cobro NO-GO**.
- **Interruptores:** `MARKETING_ULTRON_ENABLED`; freno (`php artisan ultron:abort`, soltarlo exige firma); pausa desde el CRM (`PUT admin/marketing/agent`, permiso `marketing.agent.manage`, solo apaga, auditada); `ai_enabled` y toma manual por conversación; `META_ENABLED` (sin él, todo `dry_run`). Producción no arranca si ULTRON y `auto_analyze` están activos a la vez.
- **Vigía:** cada 5 min, 10 de gracia, ventana de 6 h; abre incidentes en IRON GUARD; solo frena si el turno mudo es del canario.
- **Acta:** `ultron:canary-report {conv} --since=ISO`, con cinco contadores que deben dar 0.
- **Certificación:** canario GO; tráfico general **NO-GO**; cobro **NO-GO**.

## 8. Modelos

- **n8n:** cinco nodos OpenAI (3 llamadas por turno normal, 5 si el Critic tumba el borrador). Modelo, parámetros, esquema y credencial **no están en el repo**. Tamaño de los prompts (`nuevo4.json`): Strategist 29.190 caracteres, Composer 27.036, Critic 15.550, Reintento 24.931, Critic 2 15.202. Los enums del Critic deben coincidir con `CriticContract` (22 dimensiones, 16 fallos duros).
- **Cerebro legado (Laravel, dentro de decide):** Chat Completions con `json_object` (no `json_schema`), modelo por defecto `gpt-4.1-mini`, temperatura 0,2, 1200 tokens, 20 s, 1 reintento, `fail_closed`. `MARKETING_OPENAI_ENABLED` viene en `false`; si de verdad llama o usa reglas depende del driver en producción (lo diría `GET internal/marketing/ai/doctor`; no verificado).
- **Hermes:** inerte (14.000 tokens por llamada frente a 30.000 TPM de la organización, dato de agosto). Tiene el único cortacircuitos del sistema.
- **No hay cortacircuitos ni límite de coste para n8n. ULTRON no registra tokens, modelo ni coste.** Añadirlos exige un cambio coordinado n8n↔backend.

## 9. Registro y trazas

- `marketing_messages` (texto, autor, `wamid`, metadata con el teléfono normalizado en `recipient`), `marketing_automation_events` (uno por entrante atendible, con payload y `decide_outcome`), `marketing_ai_actions` (una fila por desenlace: intención, fases, riesgos, herramientas, `knowledge_version`, veredicto del Critic, estrategia, salida, violaciones, respaldos, rendición). Un 422 no deja fila.
- Auditoría: pausa, `ultron_aborts`, consentimientos, `release_to_ai`, revisión de la base de conocimiento, incidentes de IRON GUARD.
- **No se guarda por turno:** versión del workflow ni del prompt, modelo, tokens, id de ejecución de n8n ni el contexto que entregó decide.

## 10. Integraciones externas

| Integración | Estado real | Para el Studio |
|---|---|---|
| **Twilio** | Solo **Verify por SMS** (OTP del login de socios), sin SDK. Messages API cerrada salvo el código de vínculo socio↔personal. Un webhook (`usage-trigger`) con firma HMAC-SHA1 e idempotencia. **Sin Voice, TwiML ni Media Streams.** La tabla `marketing_calls` es andamiaje: nadie llama. Palancas de coste solo para OTP: **ningún mecanismo propio frenaría un gasto de voz** | «Control de Twilio» y «pruebas telefónicas» parten de cero: no hay nada que mostrar aún salvo el estado del OTP |
| **Meta WhatsApp** | Un solo número (`phone_number_id`); credenciales desde la fila conectada de `whatsapp_business_integrations` (token cifrado) o del entorno; **qué número rige hoy no se puede verificar desde el repo** | Estado por `MetaDoctorService` (sin valores) |
| **Meta Ads** | **Solo lectura**, token propio con solo `ads_read` (el comando de comprobación falla si trae otro permiso), guardado en `.env`. Cliente GET: insights por anuncio, alcance, campañas/conjuntos/anuncios con `effective_status`. Sincronización horaria a `meta_ad_entities`, `meta_ad_insights_daily`, `meta_ad_reach_snapshots`. El CRM ya tiene el panel «Mercadeo digital». **No hay escritura en Meta Ads** | Falta el concepto «campaña autorizada para ULTRON» y la tabla de vínculo campaña→plan/versión. El estado es el de la última pasada (viejo a los 180 min) |
| **Atribución** | Solo el `referral` de Click-to-WhatsApp (`ad_id`, `ctwa_clid`); confianza alta con anuncio y clic; todo lo demás `unknown`. La campaña se resuelve al leer contra las cuentas conectadas; `campaign_name` en atribuciones nunca se rellena (la analítica dice «Desconocido») | Mostrar «origen desconocido» cuando no hay referral; nunca adivinar por teléfono |
| **OpenAI** | Chat Completions (no Responses) sin `json_schema` estricto; clave compartida con IRON IA; límite TPM de la organización | La evaluación del Studio debe ir con clave propia, Responses API con `json_schema` estricto, registro de tokens y coste, y entradas redactadas |
| **n8n** | Orquesta cada turno; el workflow de ULTRON **no está exportado** en el repo (hay cinco de otros flujos). Autenticación con `AUTOMATION_INTERNAL_SECRET` (abre todo `internal/marketing/*`) y `N8N_WEBHOOK_SECRET`. n8n guarda el bearer en cada ejecución | El Studio no debe reutilizar rutas internas ni mostrar ejecuciones de n8n |
| **ElevenLabs** | **Ninguna referencia en el código, sin credenciales.** Documentación oficial consultada: integración nativa con Twilio, «register call», Custom LLM, server tools, webhooks post-llamada firmados (`ElevenLabs-Signature`), TTS/STT, Twilio Media Streams y ConversationRelay | Arquitectura propuesta: adaptador `VoiceAgentProvider` con proveedor nulo, ElevenLabs tras bandera, «register call» con Twilio en manos de Laravel (opción A). Estado: `ELEVENLABS_NOT_CONFIGURED` |

## 11. Decisión arquitectónica (Hito B, resumen)

- **Python / FastAPI / LangGraph: no se incorpora.** El razonamiento ya corre fuera de Laravel (n8n) con un contrato firmado; un segundo motor crearía dos cerebros que pueden contradecirse, exactamente lo que el encargo prohíbe. Latencia, coste y despliegue no mejorarían: el cuello de botella son las 3–5 llamadas LLM por turno (7–13 s), no PHP. La comparación detallada va en `ULTRON_STUDIO_TECH_STACK.md` cuando se diseñe la evaluación.
- **Laravel conserva la autoridad** (autenticación, RBAC, configuraciones, aprobaciones, reglas). El Studio es un cliente de administración del CRM.
- **ULTRON STUDIO empieza en solo lectura**, con el grafo y los datos de demostración (Hito C), y crece hacia: manifiesto generado desde el código y estado no secreto (fase 1), base de conocimiento con aprobación a cuatro ojos (fase 2) y, solo cuando el commit registre la versión de cada turno, edición de prompts con prueba previa (fase 3). Editar prompts en n8n desde el Studio antes de eso es riesgo alto: no hay versión por turno, el contrato está acoplado (un desajuste deja todos los commits en 422) y el MCP de n8n ha confirmado escrituras que no hizo.

## 12. Lo que una UI nunca debe poder cambiar

- **Dinero:** lectura de `Plan::price` y vendibilidad en el commit, patrones de precio y descuento, `PaymentFactGuard`, el embudo de Wompi, la bandera de enlaces de pago y el canario del cobro (exigen la frase del propietario), aceptación de reclamos (`payments.create`).
- **Consentimiento:** `canReplyReactively`, reapertura, `mark_do_not_contact`, opt-out entre leads con el mismo teléfono.
- **Derivación:** `HumanHandoffAuthority::MODEL_PROPOSABLE` y `release-to-ai`.
- **Salida:** el orden de la cadena del commit, `PromiseAuthority`, el guard de tono, los enums del contrato, `unexpected_fields` y el token.
- **Interruptores:** encender ULTRON, soltar el freno o mover el canario. El Studio solo debería poder **apagar**.
- **Secretos:** internos, n8n, APP_KEY, OpenAI, Wompi, Meta. Y los teléfonos en claro.

**Se puede leer sin riesgo:** un manifiesto generado del código, los booleanos y el estado de pausa y freno, el doctor de conocimiento, `GymFactsProvider::forPrompt`, el acta del canario, los incidentes con origen ULTRON y las trazas redactadas.

## 13. Hallazgos previos (no son del Studio; quedan anotados)

1. **El precio llega al Strategist** vía `base_decision.reply` del cerebro legado (`SalesConversationReplyService:201`); el cliente no lo ve porque el commit lo para, pero rompe la invariante «el modelo nunca ve una cifra». El test `UltronDecideTest:305` busca «80000» y no «80.000».
2. **`/ai/decide` no comprueba interruptor, freno ni canario**: con el secreto compartido devuelve el contexto completo (nombre, mensajes, ficha, membresía, pago) de cualquier conversación.
3. **La respuesta del commit devuelve a n8n el precio y la URL de pago** (inactivo hoy; n8n guarda ejecuciones).
4. Señales contradictorias del canario del cobro (`can_offer_link` global frente a `allowed_tools` por conversación).
5. Listas de categorías divergentes entre prompt, seeder y modelo (`gym_info`, `plans`, `pricing_policy`).
6. La metadata de un ítem aprobado no reabre la revisión (cambiar el horario no la reabriría).
7. Dirección duplicada entre código y base.
8. Sin canario, el vigía nunca frena.
9. No hay versión por turno.
10. Deriva documental en `ULTRON_AUTHORITY.md` (11 puertas en vez de 13, casos ya cerrados, 3 marcadores en vez de 4, timeout de 10 s en vez de 45) y en el runbook.
11. `send-message` interno no comprueba interruptor ni canario (ya anotado).
12. `TwilioUsageTriggerController` valida la firma con `fullUrl()` sin `trustProxies` configurado (funciona si nginx y php-fpm comparten host; no verificado).
13. `.env.example` no incluye `MARKETING_ULTRON_*`, `META_ADS_*` ni `TWILIO_SEND_ENABLED`.

## 14. Preguntas abiertas para el propietario

1. ¿«Sin tocar el motor» permite añadir al commit `workflow_version_id` y `execution_id`? Sin eso, un turno solo se atribuye a una versión de prompt cruzando fechas.
2. ¿«Números canarios» son a quién responde ULTRON por WhatsApp, a quién puede llamar Twilio Voice, o ambos? ¿La lista en base de datos sustituye al canario del `.env` o solo lo estrecha?
3. ¿Hay un número de voz de Twilio o solo un caller ID verificado? ¿Se acepta una subcuenta de voz?
4. En voz, ¿puede el agente decir precios? Hoy ULTRON nunca pasa precios al modelo.
5. ¿La evaluación con OpenAI usará una clave y un proyecto separados? El límite de 30.000 TPM es de agosto y hay que verificarlo.
6. ¿Entra en el alcance que el Studio escriba prompts en n8n, o solo la base de conocimiento?

**Por verificar en el servidor (solo lectura, cuando se autorice):** el id vigente del canario, el driver del cerebro legado, qué número de WhatsApp rige y si la redacción de ejecuciones está activa en n8n.
