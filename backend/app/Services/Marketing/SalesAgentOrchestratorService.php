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
        $executed = $autoExecute ? $this->execute($lead->fresh(), $conversation, $decision, $plan) : [];

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

        $temperature = $this->scoring->temperature($intent);
        $stage       = $this->scoring->salesStage($intent);

        // Escalado: por reglas (palabras/intención) o forzado por el validador del
        // modelo (intento prohibido / claim inseguro). Laravel deriva esto, no el
        // modelo: la IA solo aporta la señal.
        $esc            = $this->escalation->evaluate($intent, $body);
        $forceEscalate  = (bool) ($cls['force_escalate'] ?? false);
        $shouldEscalate = $esc['should_escalate'] || $forceEscalate;
        $escReason      = $esc['escalation_reason']
            ?? ($forceEscalate ? ($cls['escalation_reason'] ?? 'unsafe_content') : null);
        $riskFlags      = array_values(array_unique(array_merge(
            $esc['risk_flags'], (array) ($cls['risk_flags'] ?? []),
        )));

        // Respuesta: la del modelo (ya saneada) si aplica; si no, la curada.
        $reply = $this->resolveReply($intent, $cls['reply'] ?? null, $shouldEscalate, $context, $body);

        $isPayment      = in_array($intent, SalesIntents::PAYMENT_INTENTS, true) && ! $shouldEscalate;
        $shouldSchedule = $this->scoring->shouldScheduleFollowup($temperature) && ! $shouldEscalate;
        $delay          = $shouldSchedule ? $this->scoring->followupDelayMinutes($temperature) : null;

        $tools = [];
        if ($isPayment) {
            $tools[] = SalesIntents::TOOL_PAYMENT_LINK_SEND;
        }
        if ($shouldSchedule) {
            $tools[] = SalesIntents::TOOL_SCHEDULE_FOLLOWUP;
        }

        $decision = [
            'ok'                            => true,
            'intent'                        => $intent,
            'confidence'                    => $cls['confidence'],
            'temperature'                   => $temperature,
            'sales_stage'                   => $stage,
            'should_reply'                  => $reply !== null,
            'should_generate_payment_link'  => $isPayment,
            'should_send_message'           => $reply !== null,
            'should_schedule_followup'      => $shouldSchedule,
            'followup_delay_minutes'        => $delay,
            'should_escalate'               => $shouldEscalate,
            'escalation_reason'             => $escReason,
            'risk_flags'                    => $riskFlags,
            'extracted_fields'              => $cls['extracted_fields'],
            'missing_fields'                => $cls['missing_fields'],
            'recommended_action'            => $this->recommendedAction($intent, $shouldEscalate),
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
                'sales_stage'        => $decision['sales_stage'],
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

        // Refleja la temperatura en el lead (CRM Mercadeo) salvo do_not_contact.
        if ($lead->isContactable()) {
            $lead->forceFill(['temperature' => $this->crmTemperature($decision['temperature'])])->save();
        }

        return $action;
    }

    /**
     * Ejecuta SOLO acciones seguras (auto_execute=true). Nunca envía mensajes de
     * venta normales (esos quedan como recomendación); solo el flujo de link va a
     * dispatch (dry_run si Meta off). Nunca activa membresía.
     *
     * @return array<int, array<string,mixed>>
     */
    public function execute(MarketingLead $lead, MarketingConversation $conversation, array $decision, ?Plan $plan): array
    {
        $executed = [];
        foreach ($decision['tools_requested'] as $tool) {
            $executed[] = match ($tool) {
                SalesIntents::TOOL_MARK_DNC          => $this->execMarkDoNotContact($lead, $conversation),
                SalesIntents::TOOL_HUMAN_TAKEOVER    => $this->execHumanTakeover($lead, $conversation, $decision),
                SalesIntents::TOOL_SCHEDULE_FOLLOWUP => $this->execScheduleFollowup($lead, $decision),
                SalesIntents::TOOL_PAYMENT_LINK_SEND => $this->execPaymentLink($lead, $conversation, $plan),
                default                              => ['tool' => $tool, 'status' => 'skipped', 'reason' => 'unknown_tool'],
            };
        }
        return $executed;
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

    private function execHumanTakeover(MarketingLead $lead, MarketingConversation $conversation, array $decision): array
    {
        $conversation->update(['human_takeover' => true, 'ai_enabled' => false]);
        $lead->forceFill([
            'status'                 => MarketingLead::STATUS_NEEDS_HUMAN,
            'last_human_takeover_at' => now(),
            'human_takeover_reason'  => $decision['escalation_reason'] ?? 'escalation',
        ])->save();

        return ['tool' => SalesIntents::TOOL_HUMAN_TAKEOVER, 'status' => 'executed', 'human_takeover' => true];
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
    private function resolveReply(string $intent, ?string $modelReply, bool $shouldEscalate, array $context, string $body = ''): ?string
    {
        if ($shouldEscalate) {
            return in_array($intent, SalesIntents::ESCALATION_INTENTS, true)
                ? $this->replies->replyFor($intent, $context)
                : $this->replies->escalationReply();
        }
        if ($intent === SalesIntents::DO_NOT_CONTACT_REQUEST) {
            return null;
        }
        // Precio: DETERMINISTA desde la DB. Si el plan está identificado
        // (plan_id o nombre claro), el reply incluye el precio REAL; si no, no se
        // inventa (se pregunta objetivo/plan). No se confía en el modelo para esto.
        if ($intent === SalesIntents::PRICING_QUESTION) {
            return $this->replies->pricingReply($this->resolvePricingPlan($context, $body));
        }
        if ($modelReply !== null && trim($modelReply) !== '') {
            return $modelReply;
        }
        return $this->replies->replyFor($intent, $context);
    }

    /**
     * Resuelve el plan para una pregunta de precio: por plan_id del request o por
     * nombre claro de un plan activo en el mensaje. Devuelve null si no hay un
     * plan inequívoco (entonces NO se inventa precio).
     */
    private function resolvePricingPlan(array $context, string $body): ?Plan
    {
        $plan = $context['plan'] ?? null;
        if ($plan instanceof Plan && (bool) $plan->active) {
            return $plan;
        }

        $needle = $this->normalizeText($body);
        if ($needle === '') {
            return null;
        }

        $matches = Plan::where('active', true)->get()->filter(function (Plan $p) use ($needle) {
            $name = $this->normalizeText((string) $p->name);
            return strlen($name) >= 4 && str_contains($needle, $name);
        });

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function normalizeText(string $s): string
    {
        $lower = mb_strtolower(trim($s));
        return strtr($lower, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);
    }

    private function recommendedAction(string $intent, bool $escalate): string
    {
        if ($escalate) {
            return SalesIntents::ACTION_ESCALATE_HUMAN;
        }
        return match ($intent) {
            SalesIntents::PAYMENT_LINK_REQUEST, SalesIntents::HIGH_INTENT_CLOSE => SalesIntents::ACTION_GENERATE_PAYMENT_LINK,
            SalesIntents::PRICE_OBJECTION        => SalesIntents::ACTION_REGISTER_OBJECTION,
            SalesIntents::DO_NOT_CONTACT_REQUEST => SalesIntents::ACTION_MARK_DNC,
            default                              => SalesIntents::ACTION_REPLY,
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
