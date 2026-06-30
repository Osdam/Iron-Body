<?php

namespace App\Services\Marketing;

use App\Models\MarketingConversation;
use App\Models\MarketingMessage;

/**
 * Envío de una respuesta manual (humana) desde el Inbox CRM. Reutiliza el
 * dispatcher existente (dry_run cuando Meta está off; envío real cuando está
 * configurado) — NO duplica lógica de Meta.
 *
 * Regla crítica: enviar un mensaje manual NO apaga la IA por defecto. Solo
 * pausa la IA si `pause_ai=true`, delegando en {@see MarketingManualTakeoverService}.
 */
class MarketingManualReplyService
{
    public function __construct(
        private readonly MarketingMessageDispatcher $dispatcher,
        private readonly MarketingManualTakeoverService $takeover,
    ) {
    }

    /**
     * @return array{ok:bool,dispatch:array,ai_paused:bool}
     */
    public function send(MarketingConversation $conversation, string $body, bool $pauseAi, ?int $adminId): array
    {
        $lead = $conversation->lead;

        // Envío por el dispatcher como mensaje HUMANO con autor.
        $dispatch = $this->dispatcher->dispatchWhatsapp(
            $lead,
            $conversation->channel,
            $body,
            ['kind' => 'manual_reply', 'admin_id' => $adminId],
            MarketingMessage::SENDER_HUMAN,
            $adminId,
        );

        // Solo si el asesor lo pidió explícitamente: pausa manual de la IA.
        $aiPaused = false;
        if ($pauseAi) {
            $this->takeover->takeover($conversation->fresh() ?? $conversation, $adminId, 'manual_reply_pause');
            $aiPaused = true;
        }

        return [
            'ok'        => (bool) ($dispatch['ok'] ?? false),
            'dispatch'  => $dispatch,
            'ai_paused' => $aiPaused,
        ];
    }
}
