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
                'channel' => $channel,
                'source' => 'inbound',
                'meta_user_id' => $metaUserId,
                'name' => $name,
                'status' => MarketingLead::STATUS_NEW,
                'temperature' => MarketingLead::STATUS_COLD,
                'first_message_at' => now(),
                'last_message_at' => now(),
            ]);
        } else {
            $lead->forceFill([
                'name' => $lead->name ?: $name,
                'last_message_at' => now(),
            ])->save();
        }

        return $lead;
    }

    /**
     * La conversación a la que pertenece este mensaje entrante.
     *
     * Antes esto era un `firstOrCreate(['lead_id', 'channel'])`, y ahí había un
     * fallo real: esa pareja NO es única —un lead puede arrastrar varias
     * conversaciones del mismo canal— así que `first()` sin `ORDER BY` devolvía
     * la que el motor encontrase primero. En PostgreSQL ese orden es el físico,
     * y cambia cuando se actualiza una fila: bastó un `UPDATE` sobre una de las
     * dos conversaciones de un lead para que sus mensajes empezaran a caer en
     * la otra. Dos mensajes seguidos de la misma persona terminaban en hilos
     * distintos, y el CRM mostraba la conversación incompleta.
     *
     * El orden de aquí es el contrato, y es explícito:
     *
     *   1. una conversación ABIERTA gana siempre a una cerrada;
     *   2. entre varias, la que habló más recientemente;
     *   3. y si empatan, el id mayor, que no empata nunca.
     *
     * Las tres cláusulas son deterministas y portables: el resultado no depende
     * de cómo la base guarde las filas ni de cuándo se actualizó una.
     */
    public function ensureConversation(MarketingLead $lead, string $channel): MarketingConversation
    {
        $existente = MarketingConversation::query()
            ->where('lead_id', $lead->id)
            ->where('channel', $channel)
            ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")
            ->orderByRaw('CASE WHEN last_message_at IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->first();

        if ($existente !== null) {
            return $existente;
        }

        return MarketingConversation::create([
            'lead_id' => $lead->id,
            'channel' => $channel,
            'status' => 'open',
            'ai_enabled' => true,
            'human_takeover' => false,
            'last_message_at' => now(),
        ]);
    }
}
