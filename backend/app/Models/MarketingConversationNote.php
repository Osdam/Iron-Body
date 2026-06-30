<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Nota interna de una conversación (no se envía a WhatsApp). */
class MarketingConversationNote extends Model
{
    protected $fillable = [
        'conversation_id', 'author_admin_id', 'body',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(MarketingConversation::class, 'conversation_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'author_admin_id');
    }
}
