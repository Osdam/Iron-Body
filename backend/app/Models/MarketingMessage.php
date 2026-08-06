<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingMessage extends Model
{
    public const DIRECTION_INBOUND = 'inbound';

    public const DIRECTION_OUTBOUND = 'outbound';

    public const SENDER_LEAD = 'lead';

    public const SENDER_AI = 'ai';

    public const SENDER_HUMAN = 'human';

    public const SENDER_SYSTEM = 'system';

    protected $fillable = [
        'conversation_id', 'direction', 'sender_type', 'sender_user_id', 'body',
        'meta_message_id', 'status', 'metadata',
    ];

    protected $casts = ['metadata' => 'array'];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(MarketingConversation::class, 'conversation_id');
    }

    /** Archivos que acompañan al mensaje. El binario vive fuera de la BD. */
    public function attachments(): HasMany
    {
        return $this->hasMany(MarketingMessageAttachment::class, 'message_id');
    }

    /**
     * Historial de callbacks de entrega. Incluye los que llegaron fuera de
     * orden y no movieron el estado: son evidencia, no ruido.
     */
    public function statuses(): HasMany
    {
        return $this->hasMany(MarketingMessageStatus::class, 'message_id');
    }
}
