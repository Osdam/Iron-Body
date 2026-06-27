<?php

namespace App\Services\Marketing;

use App\Models\MarketingAiAction;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;

/**
 * Enruta un mensaje ENTRANTE (ya registrado) hacia el cerebro comercial, con
 * gating de seguridad (Fase 4-A). Laravel es la autoridad:
 *
 *   - do_not_contact=true        → NO llama al cerebro; registra bloqueo.
 *   - human_takeover / !ai_enabled → NO llama al cerebro; registra skipped.
 *   - inbound.auto_analyze=false → NO analiza.
 *   - auto_execute = agent_enabled && inbound.auto_execute (ambos false hoy) →
 *     la decisión queda 'proposed'; no se ejecutan herramientas ni se envía nada.
 *
 * Nunca activa membresías ni aprueba pagos. El envío real sigue gated por META.
 */
class MarketingInboundMessageRouter
{
    public function __construct(private readonly SalesAgentOrchestratorService $orchestrator)
    {
    }

    /** Analiza (dry_run) un mensaje de texto entrante. Devuelve resultado/omisión. */
    public function analyze(MarketingLead $lead, MarketingConversation $conversation, MarketingMessage $message): array
    {
        if (! (bool) config('marketing.inbound.auto_analyze', true)) {
            return $this->skip($lead, $conversation, 'auto_analyze_disabled');
        }

        // Respeta do_not_contact (no contactar nunca).
        if (! $lead->isContactable()) {
            return $this->skip($lead, $conversation, 'do_not_contact');
        }

        // Conversación tomada por un humano → la IA no interviene.
        if ($conversation->human_takeover || ! $conversation->ai_enabled) {
            return $this->skip($lead, $conversation, 'skipped_human_takeover');
        }

        // Ejecutar herramientas requiere AMBOS flags (hoy false → proposed).
        $autoExecute = (bool) config('marketing.agent_enabled', false)
            && (bool) config('marketing.inbound.auto_execute', false);

        return $this->orchestrator->handle(
            $lead, $conversation, $message->id, (string) $message->body, null, $autoExecute,
        );
    }

    /**
     * Mensaje de tipo NO soportado (audio/imagen/doc/sticker/location/interactive):
     * se registra para revisión humana, sin llamar a OpenAI (conservador).
     */
    public function recordUnsupported(MarketingLead $lead, MarketingConversation $conversation, MarketingMessage $message, string $type): array
    {
        MarketingAiAction::create([
            'lead_id'         => $lead->id,
            'conversation_id' => $conversation->id,
            'action_type'     => 'unsupported_message',
            'reason'          => 'unsupported_message_type',
            'status'          => 'proposed',
            'metadata'        => ['message_type' => $type, 'message_id' => $message->id, 'recommended_action' => 'escalate_human'],
        ]);

        return ['skipped' => true, 'reason' => 'unsupported_message_type', 'message_type' => $type];
    }

    /** Registra una omisión de análisis (auditoría) y devuelve el motivo. */
    private function skip(MarketingLead $lead, MarketingConversation $conversation, string $reason): array
    {
        MarketingAiAction::create([
            'lead_id'         => $lead->id,
            'conversation_id' => $conversation->id,
            'action_type'     => 'inbound_skipped',
            'reason'          => $reason,
            'status'          => 'skipped',
            'metadata'        => ['reason' => $reason],
        ]);

        return ['skipped' => true, 'reason' => $reason];
    }
}
