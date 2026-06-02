<?php

namespace App\Services\Meta;

use App\Models\MarketingConversation;
use App\Models\MarketingLead;

/**
 * Alta/actualización de leads desde mensajería entrante. Laravel es la fuente
 * de verdad. No depende de Graph API: funciona aunque META_ENABLED=false (los
 * datos llegan por webhook real). No inventa datos.
 */
class MetaLeadService
{
    /**
     * Localiza o crea el lead por (canal, meta_user_id) y asegura su
     * conversación abierta. Idempotente a nivel de lead.
     */
    public function resolveLead(string $channel, ?string $metaUserId, ?string $name = null): MarketingLead
    {
        $lead = MarketingLead::query()
            ->where('channel', $channel)
            ->when($metaUserId !== null, fn ($q) => $q->where('meta_user_id', $metaUserId))
            ->first();

        if ($lead === null) {
            $lead = MarketingLead::create([
                'channel'          => $channel,
                'source'           => 'inbound',
                'meta_user_id'     => $metaUserId,
                'name'             => $name,
                'status'           => MarketingLead::STATUS_NEW,
                'temperature'      => MarketingLead::STATUS_COLD,
                'first_message_at' => now(),
                'last_message_at'  => now(),
            ]);
        } else {
            $lead->forceFill([
                'name'            => $lead->name ?: $name,
                'last_message_at' => now(),
            ])->save();
        }

        return $lead;
    }

    public function ensureConversation(MarketingLead $lead, string $channel): MarketingConversation
    {
        return MarketingConversation::firstOrCreate(
            ['lead_id' => $lead->id, 'channel' => $channel],
            ['status' => 'open', 'ai_enabled' => true, 'human_takeover' => false, 'last_message_at' => now()],
        );
    }
}
