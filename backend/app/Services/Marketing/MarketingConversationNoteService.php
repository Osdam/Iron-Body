<?php

namespace App\Services\Marketing;

use App\Models\MarketingConversation;
use App\Models\MarketingConversationNote;

/** Notas internas: anotaciones privadas del equipo. Nunca salen por WhatsApp. */
class MarketingConversationNoteService
{
    public function add(MarketingConversation $conversation, string $body, ?int $authorAdminId): MarketingConversationNote
    {
        return MarketingConversationNote::create([
            'conversation_id' => $conversation->id,
            'author_admin_id' => $authorAdminId,
            'body'            => trim($body),
        ]);
    }
}
