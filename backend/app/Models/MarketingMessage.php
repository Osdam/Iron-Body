<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingMessage extends Model
{
    public const DIRECTION_INBOUND = 'inbound';
    public const DIRECTION_OUTBOUND = 'outbound';

    public const SENDER_LEAD = 'lead';
    public const SENDER_AI = 'ai';
    public const SENDER_HUMAN = 'human';
    public const SENDER_SYSTEM = 'system';

    protected $fillable = [
        'conversation_id', 'direction', 'sender_type', 'body',
        'meta_message_id', 'status', 'metadata',
    ];

    protected $casts = ['metadata' => 'array'];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(MarketingConversation::class, 'conversation_id');
    }
}
