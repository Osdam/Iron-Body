<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tag (slug) de una conversación. Único por conversación. */
class MarketingConversationTag extends Model
{
    protected $fillable = [
        'conversation_id', 'tag', 'tag_id', 'created_by',
        'assigned_kind', 'evidence', 'removed_at', 'removed_by',
    ];

    protected $casts = [
        'evidence' => 'array',
        'removed_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(MarketingConversation::class, 'conversation_id');
    }
}
