<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingAiAction extends Model
{
    protected $fillable = [
        'lead_id', 'conversation_id', 'action_type', 'reason',
        'confidence', 'status', 'metadata',
        // Procedencia e idempotencia (ULTRON v1). idempotency_key es UNIQUE en BD.
        'source_type', 'source_event_id', 'idempotency_key',
    ];

    protected $casts = [
        'confidence' => 'decimal:4',
        'metadata' => 'array',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(MarketingLead::class, 'lead_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(MarketingConversation::class, 'conversation_id');
    }
}
