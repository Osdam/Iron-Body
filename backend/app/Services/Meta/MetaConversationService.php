<?php

namespace App\Services\Meta;

use App\Models\MarketingConversation;
use App\Models\MarketingMessage;

/**
 * Registro de mensajes en una conversación comercial. Idempotente por
 * meta_message_id (un webhook reentregado no duplica el mensaje).
 */
class MetaConversationService
{
    /**
     * Guarda un mensaje entrante. Devuelve el mensaje (nuevo o existente).
     * Idempotente: si meta_message_id ya existe, no duplica.
     */
    public function recordInbound(
        MarketingConversation $conversation,
        ?string $metaMessageId,
        ?string $body,
        array $metadata = [],
    ): ?MarketingMessage {
        if ($metaMessageId !== null) {
            $existing = MarketingMessage::where('meta_message_id', $metaMessageId)->first();
            if ($existing !== null) {
                return $existing; // ya procesado
            }
        }

        $message = MarketingMessage::create([
            'conversation_id' => $conversation->id,
            'direction'       => MarketingMessage::DIRECTION_INBOUND,
            'sender_type'     => MarketingMessage::SENDER_LEAD,
            'body'            => $body,
            'meta_message_id' => $metaMessageId,
            'metadata'        => $metadata ?: null,
        ]);

        $conversation->update(['last_message_at' => now()]);

        return $message;
    }

    /** Registra el estado de entrega de un mensaje saliente (WhatsApp). */
    public function recordStatus(?string $metaMessageId, string $status): void
    {
        if ($metaMessageId === null) {
            return;
        }
        MarketingMessage::where('meta_message_id', $metaMessageId)->update(['status' => $status]);
    }
}
