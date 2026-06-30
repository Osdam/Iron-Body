<?php

namespace App\Services\Marketing;

use App\Models\MarketingAiAction;
use App\Models\MarketingConversation;

/**
 * ÚNICO punto de escritura del takeover manual desde el CRM. Centraliza la
 * regla crítica: `human_takeover=true` SOLO nace aquí (CRM), siempre marcado
 * como 'manual' para que el router NO lo recupere. La IA jamás se apaga sola.
 *
 * - takeover(): pausa la IA (manual). Idempotente.
 * - release():  reactiva la IA. Idempotente.
 */
class MarketingManualTakeoverService
{
    /** Pausa la IA por acción manual de un asesor/administrador. */
    public function takeover(MarketingConversation $conversation, ?int $adminId, ?string $reason = null): MarketingConversation
    {
        $conversation->forceFill([
            'human_takeover'        => true,
            'human_takeover_source' => 'manual',
            'ai_enabled'            => false,
            'manual_takeover_at'    => now(),
            'manual_takeover_by'    => $adminId,
        ])->save();

        MarketingAiAction::create([
            'lead_id'         => $conversation->lead_id,
            'conversation_id' => $conversation->id,
            'action_type'     => 'human_takeover',
            'reason'          => $reason,
            'status'          => 'executed',
            'metadata'        => ['source' => 'manual', 'admin_id' => $adminId],
        ]);

        return $conversation;
    }

    /** Reactiva la IA. El router vuelve a responder automáticamente si aplica. */
    public function release(MarketingConversation $conversation, ?int $adminId): MarketingConversation
    {
        $conversation->forceFill([
            'human_takeover'        => false,
            'human_takeover_source' => null,
            'ai_enabled'            => true,
        ])->save();

        MarketingAiAction::create([
            'lead_id'         => $conversation->lead_id,
            'conversation_id' => $conversation->id,
            'action_type'     => 'reactivate',
            'status'          => 'executed',
            'metadata'        => ['source' => 'manual', 'admin_id' => $adminId],
        ]);

        return $conversation;
    }
}
