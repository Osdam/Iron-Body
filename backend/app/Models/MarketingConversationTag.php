<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tag (slug) de una conversación. Único por conversación. */
class MarketingConversationTag extends Model
{
    protected $fillable = [
        'conversation_id', 'tag', 'created_by',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(MarketingConversation::class, 'conversation_id');
    }
}
