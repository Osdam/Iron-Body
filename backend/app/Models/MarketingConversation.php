<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingConversation extends Model
{
    protected $fillable = [
        'lead_id', 'channel', 'status', 'last_message_at', 'human_takeover', 'ai_enabled',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'human_takeover'  => 'boolean',
        'ai_enabled'      => 'boolean',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(MarketingLead::class, 'lead_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(MarketingMessage::class, 'conversation_id');
    }
}
