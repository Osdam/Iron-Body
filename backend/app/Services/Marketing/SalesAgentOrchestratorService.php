<?php

namespace App\Services\Marketing;

use App\Models\MarketingAiAction;
use App\Models\MarketingConversation;
use App\Models\MarketingFollowup;
use App\Models\MarketingLead;
use App\Models\Plan;

/**
 * Orquestador del CEREBRO comercial IA (Fase 2). Convierte un mensaje del lead
 * en una DECISIÓN estructurada (intención, temperatura, etapa, riesgos, acción
 * recomendada, respuesta sugerida) y, si se pide `auto_execute`, ejecuta SOLO
 * acciones seguras. Nunca activa membresías ni marca pagos como aprobados; el
 * envío real está gated por META_ENABLED + flags.
 */
class SalesAgentOrchestratorService
{
    public function __construct(
        private readonly SalesIntentClassifierService $classifier,
        private readonly SalesLeadScoringService $scoring,
        private readonly SalesConversationReplyService $replies,
        private readonly SalesEscalationService $escalation,
        private readonly SalesAgentGuardrailService $guardrail,
        private readonly SalesPaymentGuardrailService $paymentGuardrail,
        private readonly MarketingMessageDispatcher $dispatcher,
        private readonly MarketingKnowledgeBaseService $knowledge,
        private readonly SalesConversationMemoryService $memory,
        private readonly SalesPaymentReadinessService $paymentReadiness,
    ) {
    }

    /**
     * Flujo completo compartido (controlador + webhook entrante): analiza,
     * persiste la decisión (auditoría) y, SOLO si auto_execute, ejecuta acciones
     * seguras. Nunca activa membresía ni aprueba pagos.
     *
     * @return array{decision:array, ai_action_id:int, executed:array, auto_execute:bool}
     */
    public function handle(
        MarketingLead $lead,
        MarketingConversation $conversation,
        ?int $messageId,
        string $body,
        ?Plan $plan,
        bool $autoExecute,
    ): array {
        $decision = $this->analyze($lead, $body, [
            'lead' => $lead, 'channel' => $conversation->channel,
            'conversation' => $conversation, 'plan' => $plan,
        ]);

        $action   = $this->persist($lead, $conversation->id, $messageId, $decision, $autoExecute);
        $this->memory->remember($conversation, $decision, $body);
        $executed = $autoExecute ? $this->execute($lead->fresh(), $conversation, $decision, $plan, $action) : [];

        return [
            'decision'     => $decision,
            'ai_action_id' => $action->id,
            'executed'     => $executed,
            'auto_execute' => $autoExecute,
        ];
    }

    /**
     * Decisión PURA (sin side effects). Ensambla clasificación + scoring +
     * escalado + respuesta y aplica los guardrails del agente.
     */
    public function analyze(MarketingLead $lead, string $body, array $context = []): array
    {
        $cls    = $this->classifier->classify($body, $context);
        $intent = $cls['intent'];

        // Override determinista: si el MENSAJE ACTUAL pregunta precio de forma
        // explícita, pricing_question gana sobre el objetivo histórico (que pudo
        // venir del historial vía OpenAI). El objetivo queda en extracted_fields.
        [$intent, $cls] = $this->applyPricingKeywordOverride($intent, $cls, $body);

        $temperature = $this->scoring->temperature($intent);
        $stage       = $this->scoring->salesStage($intent);

        // Caso SENSIBLE (needs_staff_review): por reglas/intención o forzado por el
        // validador. La IA NO se apaga: responde seguro y deja una marca interna.
        $esc             = $this->escalation->evaluate($intent, $body);
        $forceEscalate   = (bool) ($cls['force_escalate'] ?? false);
        $needsStaffReview = $esc['should_escalate'] || $forceEscalate;
        $escReason       = $esc['escalation_reason']
            ?? ($forceEscalate ? ($cls['escalation_reason'] ?? 'unsafe_content') : null);
        $riskFlags       = array_values(array_unique(array_merge(
            $esc['risk_flags'], (array) ($cls['risk_flags'] ?? []),
        )));

        // Preparación de pago: si Wompi NO es productivo, el bot NO entrega ni
        // MENCIONA un link (sandbox o sin configurar). Regla incondicional.
        $paymentState   = $this->paymentReadiness->state();
        $canLink        = $this->paymentReadiness->canGenerateAutomaticLink();
        $paymentBlocked = ! $canLink;
        $context['can_link']        = $canLink;
        $context['payment_blocked'] = $paymentBlocked;

        // Intención de pago SIN Wompi productivo → NO se apaga la IA: responde que
        // deja la solicitud lista para que el equipo confirme el medio, y marca
        // staff_review (payment_requested). Sigue conversando con normalidad.
        if (in_array($intent, SalesIntents::PAYMENT_INTENTS, true) && ! $canLink) {
            $needsStaffReview = true;
            $escReason ??= 'payment_requested';
            $riskFlags[] = 'payment';
        }

        $staffReason = $needsStaffReview ? $this->staffReviewReason($intent, $escReason) : null;

        // Scoring comercial (0-100), etapa del lead y temperatura simplificada.
        $score     = $this->scoring->score($intent, [
            'objective'        => $lead->objective,
            'extracted_fields' => $cls['extracted_fields'],
        ]);
        $leadStage = $this->scoring->leadStage($intent, $needsStaffReview);
        $crmTemp   = $this->scoring->crmTemperature($intent);

        // Respuesta SIEMPRE presente y segura (la IA nunca queda en silencio).
        $reply = $this->resolveReply($intent, $cls['reply'] ?? null, $needsStaffReview, $context, $body);

        // Guardrail Wompi: si no es productivo, ninguna respuesta menciona un link.
        if (! $canLink && ! in_array($intent, SalesIntents::PAYMENT_INTENTS, true)
            && $this->replies->offersLink($reply)) {
            $reply = $this->replies->scrubLinkOffer($reply);
        }

        // Post-procesamiento de CTA: ubicación/objeciones NUNCA empujan pago.
        if ($intent === SalesIntents::LOCATION_QUESTION && $this->replies->mentionsPaymentCta($reply)) {
            $reply = $this->replies->scrubPaymentCta($reply, '¿Vas a ir por primera vez?');
        } elseif (in_array($intent, SalesIntents::OBJECTION_INTENTS, true)
            && $this->replies->mentionsPaymentCta($reply)) {
            $reply = $this->replies->scrubPaymentCta($reply, '¿Quieres que te asesore según tu objetivo?');
        }

        // Pago AUTOMÁTICO solo si Wompi es productivo. Si no, sin tool de pago.
        $isPayment      = in_array($intent, SalesIntents::PAYMENT_INTENTS, true) && $canLink;
        $shouldSchedule = $this->scoring->shouldScheduleFollowup($temperature);
        $delay          = $shouldSchedule ? $this->scoring->followupDelayMinutes($temperature) : null;

        $tools = [];
        if ($isPayment) {
            $tools[] = SalesIntents::TOOL_PAYMENT_LINK_SEND;
        }
        if ($shouldSchedule) {
            $tools[] = SalesIntents::TOOL_SCHEDULE_FOLLOWUP;
        }
        if ($needsStaffReview) {
            $tools[] = SalesIntents::TOOL_STAFF_REVIEW; // alerta interna, NO apaga IA.
        }

        $decision = [
            'ok'                            => true,
            'intent'                        => $intent,
            'confidence'                    => $cls['confidence'],
            'temperature'                   => $temperature,
            'crm_temperature'               => $crmTemp,
            'lead_score'                    => $score,
            'lead_stage'                    => $leadStage,
            'sales_stage'                   => $stage,
            'payment_readiness'             => $paymentState,
            'should_reply'                  => $reply !== null,
            'should_generate_payment_link'  => $isPayment,
            'should_send_message'           => $reply !== null,
            'should_schedule_followup'      => $shouldSchedule,
            'followup_delay_minutes'        => $delay,
            // La IA NUNCA se apaga sola: should_escalate queda false (lo fija el
            // guardrail). El caso sensible se marca en needs_staff_review.
            'should_escalate'               => false,
            'needs_staff_review'            => $needsStaffReview,
            'staff_review_reason'           => $staffReason,
            'escalation_reason'             => $needsStaffReview ? $escReason : null,
            'risk_flags'                    => $riskFlags,
            'extracted_fields'              => $cls['extracted_fields'],
            'missing_fields'                => $cls['missing_fields'],
            'recommended_action'            => $this->recommendedAction($intent, $needsStaffReview, $canLink),
            'reply'                         => $reply,
            'tools_requested'               => $tools,
            'safe_to_send'                  => false, // lo fija el guardrail
            'responder'                     => $cls['responder'],
        ];

        return $this->guardrail->apply($decision, $lead);
    }

    /** Registra la decisión en marketing_ai_actions (auditoría obligatoria). */
    public function persist(MarketingLead $lead, ?int $conversationId, ?int $messageId, array $decision, bool $autoExecute): MarketingAiAction
    {
        $action = MarketingAiAction::create([
            'lead_id'         => $lead->id,
            'conversation_id' => $conversationId,
            'action_type'     => $decision['recommended_action'],
            'reason'          => $decision['escalation_reason'] ?? $decision['intent'],
            'confidence'      => $decision['confidence'],
            'status'          => $autoExecute ? 'executed' : 'proposed',
            'metadata'        => [
                'message_id'         => $messageId,
                'intent'             => $decision['intent'],
                'temperature'        => $decision['temperature'],
                'lead_score'         => $decision['lead_score'] ?? null,
                'lead_stage'         => $decision['lead_stage'] ?? null,
                'sales_stage'        => $decision['sales_stage'],
                'payment_readiness'  => $decision['payment_readiness'] ?? null,
                'recommended_action' => $decision['recommended_action'],
                'risk_flags'         => $decision['risk_flags'],
                'tools_requested'    => $decision['tools_requested'],
                'safe_to_send'       => $decision['safe_to_send'],
                'responder'          => $decision['responder'] ?? null,
                // Auditoría del conocimiento usado al decidir (Fase 3.5).
                'knowledge_items_count' => $this->knowledge->activeItemsCount(),
                'knowledge_version'     => $this->knowledge->version(),
            ],
        ]);

        // Marca denormalizada para la bandeja del Inbox: si la decisión pide
        // revisión del equipo, deja la conversación señalada (NO apaga la IA).
        if ($conversationId !== null && ($decision['needs_staff_review'] ?? false)) {
            MarketingConversation::whereKey($conversationId)->update([
                'staff_review_pending' => true,
                'staff_review_reason'  => $decision['staff_review_reason'] ?? null,
            ]);
        }

        // Refleja temperatura y objetivo detectado en el lead (CRM Mercadeo)
        // salvo do_not_contact. El objetivo solo se fija si aún no había uno.
        if ($lead->isContactable()) {
            $changes   = ['temperature' => $decision['crm_temperature'] ?? $this->crmTemperature($decision['temperature'])];
            $objective = $decision['extracted_fields']['objective'] ?? null;
            if (is_string($objective) && $objective !== '' && empty($lead->objective)) {
                $changes['objective'] = $objective;
            }
            $lead->forceFill($changes)->save();
        }

        return $action;
    }

    /**
     * Ejecuta acciones seguras (auto_execute=true): herramientas (link/followup/
     * takeover/dnc) y el ENVÍO REAL de la respuesta conversacional `reply` por
     * WhatsApp (crea el outbound y actualiza el estado de la acción IA según el
     * resultado del envío). Nunca activa membresía ni aprueba pagos.
     *
     * @return array<int, array<string,mixed>>
     */
    public function execute(MarketingLead $lead, MarketingConversation $conversation, array $decision, ?Plan $plan, ?MarketingAiAction $action = null): array
    {
        $executed = [];
        foreach ($decision['tools_requested'] as $tool) {
            $executed[] = match ($tool) {
                SalesIntents::TOOL_MARK_DNC          => $this->execMarkDoNotContact($lead, $conversation),
                SalesIntents::TOOL_STAFF_REVIEW      => $this->execStaffReview($lead, $conversation, $decision),
                SalesIntents::TOOL_SCHEDULE_FOLLOWUP => $this->execScheduleFollowup($lead, $decision),
                SalesIntents::TOOL_PAYMENT_LINK_SEND => $this->execPaymentLink($lead, $conversation, $plan),
                default                              => ['tool' => $tool, 'status' => 'skipped', 'reason' => 'unknown_tool'],
            };
        }

        // Envío REAL de la respuesta conversacional (lo que faltaba): crea el
        // outbound y ajusta el estado de la acción IA según el envío.
        $reply = $this->maybeSendReply($lead, $conversation, $decision, $executed, $action);
        if ($reply !== null) {
            $executed[] = $reply;
        }

        return $executed;
    }

    /**
     * Si la decisión es una respuesta conversacional segura (no escalado, no link,
     * no opt-out), la ENTREGA por WhatsApp creando el outbound. Devuelve el detalle
     * del envío o null si no aplica. Actualiza la acción IA:
     *   - outbound creado/enviado (sent o dry_run) → status executed
     *   - creado pero el proveedor falló            → status failed
     *   - no se creó (do_not_contact / sin teléfono)→ status skipped
     *
     * @return array<string,mixed>|null
     */
    private function maybeSendReply(MarketingLead $lead, MarketingConversation $conversation, array $decision, array $executed, ?MarketingAiAction $action): ?array
    {
        $reply = $decision['reply'] ?? null;

        // Se entrega cualquier respuesta segura: respuestas conversacionales Y los
        // acuses de escalamiento (p. ej. "te paso con alguien del equipo", "un
        // asesor te comparte el medio de pago"). NO se entregan: opt-out (reply
        // null) ni el flujo de link (que despacha su propio mensaje).
        $sendable = (bool) ($decision['safe_to_send'] ?? false)
            && (bool) ($decision['should_send_message'] ?? false)
            && is_string($reply) && trim($reply) !== '';

        // No reenviar si una herramienta de pago ya despachó su propio mensaje.
        $alreadyDispatched = collect($executed)->contains(
            fn ($e) => in_array($e['tool'] ?? null, [SalesIntents::TOOL_PAYMENT_LINK_SEND], true)
                && in_array($e['status'] ?? null, ['executed', 'deferred_to_human'], true),
        );

        if (! $sendable || $alreadyDispatched) {
            return null;
        }

        $send = $this->dispatcher->dispatchWhatsapp($lead, $conversation->channel, (string) $reply, ['kind' => 'reply']);

        $created = $send['message_id'] !== null;
        $status  = ($send['sent'] || $send['dry_run'])
            ? 'executed'
            : ($created ? 'failed' : 'skipped');

        $this->updateActionSendStatus($action, $status, $send);

        return [
            'tool'                => 'reply_send',
            'status'              => $status,
            'sent'                => $send['sent'],
            'dry_run'             => $send['dry_run'],
            'message_id'          => $send['message_id'],
            'provider_message_id' => $send['provider_message_id'],
            'reason'              => $send['reason'],
        ];
    }

    /** Refleja en la acción IA el resultado REAL del envío del outbound. */
    private function updateActionSendStatus(?MarketingAiAction $action, string $status, array $send): void
    {
        if ($action === null) {
            return;
        }

        $meta = is_array($action->metadata) ? $action->metadata : [];
        $meta['outbound'] = array_filter([
            'message_id'          => $send['message_id'],
            'sent'                => $send['sent'],
            'dry_run'             => $send['dry_run'],
            'provider_message_id' => $send['provider_message_id'],
            'reason'              => $send['reason'],
        ], fn ($v) => $v !== null);

        $action->forceFill(['status' => $status, 'metadata' => $meta])->save();
    }

    // ── Ejecutores de herramientas seguras ────────────────────────────────────

    private function execMarkDoNotContact(MarketingLead $lead, MarketingConversation $conversation): array
    {
        $lead->forceFill([
            'do_not_contact' => true,
            'consent_status' => MarketingLead::CONSENT_DENIED,
            'consent_source' => $conversation->channel,
            'consent_at'     => now(),
        ])->save();

        return ['tool' => SalesIntents::TOOL_MARK_DNC, 'status' => 'executed', 'do_not_contact' => true];
    }

    /**
     * Deja una ALERTA INTERNA de revisión para el equipo (notify_human) SIN apagar
     * la IA: NO toca human_takeover ni ai_enabled ni cierra la conversación. El bot
     * sigue respondiendo. Solo el CRM (acción manual) puede apagar la IA.
     */
    private function execStaffReview(MarketingLead $lead, MarketingConversation $conversation, array $decision): array
    {
        $reason = $decision['staff_review_reason'] ?? ($decision['escalation_reason'] ?? 'staff_review');

        MarketingAiAction::create([
            'lead_id'         => $lead->id,
            'conversation_id' => $conversation->id,
            'action_type'     => SalesIntents::ACTION_STAFF_REVIEW,
            'reason'          => $reason,
            'status'          => 'created',
            'metadata'        => array_filter([
                'needs_staff_review' => true,
                'staff_review_reason' => $reason,
                'intent'             => $decision['intent'] ?? null,
                // Señal CRM para que un humano lo revise; NO apaga la IA.
                'ai_enabled'         => true,
                'human_takeover'     => false,
            ], fn ($v) => $v !== null),
        ]);

        return [
            'tool'                => SalesIntents::TOOL_STAFF_REVIEW,
            'status'              => 'created',
            'reason'              => $reason,
            'ai_disabled'         => false,
            'human_takeover'      => false,
        ];
    }

    private function execScheduleFollowup(MarketingLead $lead, array $decision): array
    {
        $delay = (int) ($decision['followup_delay_minutes'] ?? 0);
        $dueAt = $delay > 0 ? now()->addMinutes($delay) : null;

        $followup = MarketingFollowup::firstOrCreate(
            [
                'lead_id' => $lead->id,
                'type'    => 'message',
                'status'  => MarketingFollowup::STATUS_PENDING,
            ],
            ['due_at' => $dueAt],
        );

        return [
            'tool'        => SalesIntents::TOOL_SCHEDULE_FOLLOWUP,
            'status'      => $followup->wasRecentlyCreated ? 'executed' : 'exists',
            'followup_id' => $followup->id,
            'due_at'      => optional($followup->due_at)->toIso8601String(),
        ];
    }

    private function execPaymentLink(MarketingLead $lead, MarketingConversation $conversation, ?Plan $plan): array
    {
        if ($plan === null) {
            return ['tool' => SalesIntents::TOOL_PAYMENT_LINK_SEND, 'status' => 'skipped', 'reason' => 'missing_plan_id'];
        }

        // Gate de producción: nunca generar ni entregar un link no productivo
        // (sandbox/sin configurar). En su lugar, un asesor comparte el medio de
        // pago. Regla incondicional: aplica incluso en dry_run.
        if (! $this->paymentReadiness->isProductionReady()) {
            $body = $this->replies->paymentPendingReply();
            $send = $this->dispatcher->dispatchWhatsapp($lead, $conversation->channel, $body, [
                'kind' => 'payment_pending',
            ]);

            return [
                'tool'          => SalesIntents::TOOL_PAYMENT_LINK_SEND,
                'status'        => 'deferred_to_human',
                'reason'        => 'wompi_not_production',
                'payment_state' => $this->paymentReadiness->state(),
                'sent'          => $send['sent'],
                'dry_run'       => $send['dry_run'],
                'prepared_body' => $body,
            ];
        }

        // Guardrail de pago (do_not_contact / plan activo / precio válido).
        try {
            $this->paymentGuardrail->assertCanGeneratePaymentLink($lead, $plan, []);
        } catch (SalesGuardrailException $e) {
            return ['tool' => SalesIntents::TOOL_PAYMENT_LINK_SEND, 'status' => 'skipped', 'reason' => $e->errorCode];
        }

        $link = WompiPaymentLinkService::make()->generateForLead($lead, $plan, [
            'channel' => $conversation->channel,
        ]);

        if (($link['configured'] ?? false) === false) {
            return ['tool' => SalesIntents::TOOL_PAYMENT_LINK_SEND, 'status' => 'skipped', 'reason' => 'wompi_checkout_not_configured'];
        }
        if (($link['already_paid'] ?? false) === true || empty($link['payment_url'])) {
            return ['tool' => SalesIntents::TOOL_PAYMENT_LINK_SEND, 'status' => 'skipped', 'reason' => 'link_not_safe_to_send'];
        }

        $body = $this->replies->paymentLinkMessage($plan, (float) $link['amount'], $link['payment_url']);
        $send = $this->dispatcher->dispatchWhatsapp($lead, $conversation->channel, $body, [
            'kind'      => 'payment_link',
            'reference' => $link['reference'] ?? null,
        ]);

        return array_merge(['tool' => SalesIntents::TOOL_PAYMENT_LINK_SEND, 'status' => 'executed'], [
            'payment_url'         => $link['payment_url'],
            'reference'           => $link['reference'] ?? null,
            'sent'                => $send['sent'],
            'dry_run'             => $send['dry_run'],
            'provider_message_id' => $send['provider_message_id'],
            'prepared_body'       => $body,
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Decide la respuesta final: si se escala, mensaje de espera neutro; si el
     * lead pide no ser contactado, no se responde; si el modelo aportó un reply
     * (ya saneado), se usa; si no, la respuesta curada por intención.
     */
    private function resolveReply(string $intent, ?string $modelReply, bool $needsStaffReview, array $context, string $body = ''): ?string
    {
        // Pago sin Wompi productivo: la IA responde que deja la solicitud lista
        // para que el equipo confirme el medio (NUNCA link sandbox). Sigue activa.
        if (in_array($intent, SalesIntents::PAYMENT_INTENTS, true) && ($context['payment_blocked'] ?? false)) {
            return $this->replies->paymentPendingReply();
        }
        // Caso sensible: respuesta SEGURA y útil que mantiene la conversación viva
        // (deja la marca para el equipo pero la IA sigue ayudando). Nunca silencio.
        if ($needsStaffReview) {
            return in_array($intent, SalesIntents::STAFF_REVIEW_INTENTS, true)
                ? $this->replies->replyFor($intent, $context)
                : $this->replies->staffReviewReply();
        }
        if ($intent === SalesIntents::DO_NOT_CONTACT_REQUEST) {
            return null;
        }
        // Precio: DETERMINISTA desde la DB (precio REAL, tono natural, sin link).
        // Si no hay plan activo, no se inventa: se pregunta el objetivo.
        if ($intent === SalesIntents::PRICING_QUESTION) {
            return $this->replies->pricingReply($this->resolvePricingPlan($context, $body));
        }
        // Despedida: cierre suave con valor (precio + ubicación) y puerta abierta.
        if ($intent === SalesIntents::GOODBYE) {
            return $this->replies->goodbyeReply($this->resolvePricingPlan($context, $body));
        }
        if ($modelReply !== null && trim($modelReply) !== '') {
            return $modelReply;
        }
        return $this->replies->replyFor($intent, $context);
    }

    /**
     * Resuelve el plan para una pregunta de precio: por plan_id del request, por
     * nombre claro de un plan activo en el mensaje o, si la pregunta es genérica,
     * el plan mensual/ancla activo. Devuelve null SOLO si no hay ningún plan
     * activo (entonces NO se inventa precio: se pregunta el objetivo).
     */
    private function resolvePricingPlan(array $context, string $body): ?Plan
    {
        $plan = $context['plan'] ?? null;
        if ($plan instanceof Plan && (bool) $plan->active) {
            return $plan;
        }

        $needle = $this->normalizeText($body);
        if ($needle !== '') {
            $matches = Plan::where('active', true)->get()->filter(function (Plan $p) use ($needle) {
                $name = $this->normalizeText((string) $p->name);
                return strlen($name) >= 4 && str_contains($needle, $name);
            });
            if ($matches->count() === 1) {
                return $matches->first();
            }
        }

        // Pregunta de precio genérica ("precio", "cuánto vale"): se cotiza el plan
        // mensual/ancla REAL (no se inventa). null si no hay planes activos.
        return $this->knowledge->defaultMonthlyPlan();
    }

    /** Palabras que indican una pregunta de precio EXPLÍCITA en el mensaje actual. */
    private const PRICING_KEYWORDS = [
        'precio', 'precios', 'valor', 'valores', 'cuanto vale', 'cuanto cuesta',
        'cuanto sale', 'cuanto es', 'mensualidad', 'planes', 'tarifa',
    ];

    /** Señales EXPLÍCITAS de objeción de precio en el mensaje actual. */
    private const OBJECTION_SIGNALS = [
        'caro', 'costoso', 'carisim', 'no me alcanza', 'presupuesto', 'mucha plata',
    ];

    /**
     * Override determinista de intención (Laravel tiene la última palabra):
     *
     *  - Si el MENSAJE ACTUAL es una pregunta de precio explícita (precio, valor,
     *    cuánto vale/cuesta, mensualidad, planes…) y NO trae señales de objeción
     *    ("caro", "costoso", "no me alcanza"…), se fuerza pricing_question aunque
     *    el modelo (por el historial) haya dicho price_objection / goal_* / etc.
     *  - NUNCA pisa intenciones de mayor prioridad: pedir humano / médico / queja /
     *    fraude-pago / opt-out / pago. Esas mandan sobre la pregunta de precio.
     *
     * El objetivo histórico (si venía de un goal_*) se conserva en
     * extracted_fields.goal. Orden de prioridad respetado:
     *   1) humano/médico/queja/factura/problema de pago  2) intención de pago
     *   3) pregunta de precio  4) ubicación  5) horario  6) objetivos  7) objeciones
     *
     * @return array{0:string,1:array}
     */
    private function applyPricingKeywordOverride(string $intent, array $cls, string $body): array
    {
        // El mensaje actual debe ser una pregunta de precio SIN señal de objeción.
        if (! $this->mentionsPricing($body) || $this->mentionsObjection($body)) {
            return [$intent, $cls];
        }

        // Intenciones de mayor prioridad que la pregunta de precio: no se tocan.
        $protected = array_merge(
            SalesIntents::PAYMENT_INTENTS,
            SalesIntents::ESCALATION_INTENTS,
            [SalesIntents::DO_NOT_CONTACT_REQUEST, SalesIntents::SPAM_LOW_QUALITY],
        );
        if (in_array($intent, $protected, true)) {
            return [$intent, $cls];
        }

        // Preserva el objetivo histórico que traía una intención de goal.
        if ($intent === SalesIntents::GOAL_FAT_LOSS) {
            $cls['extracted_fields']['goal'] = 'fat_loss';
        } elseif ($intent === SalesIntents::GOAL_MUSCLE_GAIN) {
            $cls['extracted_fields']['goal'] = 'muscle_gain';
        }

        return [SalesIntents::PRICING_QUESTION, $cls];
    }

    private function mentionsPricing(string $body): bool
    {
        $needle = $this->normalizeText($body);
        foreach (self::PRICING_KEYWORDS as $kw) {
            if (str_contains($needle, $kw)) {
                return true;
            }
        }
        return false;
    }

    private function mentionsObjection(string $body): bool
    {
        $needle = $this->normalizeText($body);
        foreach (self::OBJECTION_SIGNALS as $kw) {
            if (str_contains($needle, $kw)) {
                return true;
            }
        }
        return false;
    }

    private function normalizeText(string $s): string
    {
        $lower = mb_strtolower(trim($s));
        return strtr($lower, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);
    }

    private function recommendedAction(string $intent, bool $needsStaffReview, bool $canLink = true): string
    {
        // Caso sensible: la IA RESPONDE (recommended_action=reply) y deja la marca
        // staff_review aparte. NUNCA escalate_human (eso apagaría la IA).
        if ($needsStaffReview) {
            return SalesIntents::ACTION_REPLY;
        }
        return match ($intent) {
            // Pago: solo se genera link si Wompi es productivo; si no, ya quedó como
            // caso sensible (arriba) y la IA responde.
            SalesIntents::PAYMENT_LINK_REQUEST, SalesIntents::HIGH_INTENT_CLOSE =>
                $canLink ? SalesIntents::ACTION_GENERATE_PAYMENT_LINK : SalesIntents::ACTION_REPLY,
            SalesIntents::PRICE_OBJECTION        => SalesIntents::ACTION_REGISTER_OBJECTION,
            SalesIntents::DO_NOT_CONTACT_REQUEST => SalesIntents::ACTION_MARK_DNC,
            default                              => SalesIntents::ACTION_REPLY,
        };
    }

    /** Razón de la marca de revisión para el equipo (no apaga la IA). */
    private function staffReviewReason(string $intent, ?string $fallback): string
    {
        return match ($intent) {
            SalesIntents::PAYMENT_LINK_REQUEST, SalesIntents::HIGH_INTENT_CLOSE => 'payment_requested',
            SalesIntents::FRAUD_OR_PAYMENT_CLAIM  => 'payment_requested',
            SalesIntents::INVOICE_REQUEST         => 'invoice_requested',
            SalesIntents::MEDICAL_RISK_ESCALATION => 'medical_caution',
            SalesIntents::COMPLAINT               => 'complaint',
            SalesIntents::HUMAN_REQUEST           => 'human_requested',
            default                               => $fallback ?: 'staff_review',
        };
    }

    /** Mapea la temperatura rica del agente a la del CRM (hot/warm/cold). */
    private function crmTemperature(string $temperature): string
    {
        return match ($temperature) {
            SalesIntents::TEMP_VERY_HOT, SalesIntents::TEMP_HOT => 'hot',
            SalesIntents::TEMP_WARM => 'warm',
            default => 'cold',
        };
    }
}
