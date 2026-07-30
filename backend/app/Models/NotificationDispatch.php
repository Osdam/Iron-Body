<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un intento de envío. Enviado, suprimido o fallido — los tres se guardan.
 */
class NotificationDispatch extends Model
{
    public const STATUS_SENT = 'sent';

    public const STATUS_SUPPRESSED = 'suppressed';

    public const STATUS_FAILED = 'failed';

    // Motivos de supresión. Son el vocabulario con el que el sistema explica
    // por qué NO envió algo; el CRM y los tests dependen de estos literales.
    public const REASON_OPTED_OUT = 'category_disabled';

    public const REASON_QUIET_HOURS = 'quiet_hours';

    public const REASON_DAILY_LIMIT = 'daily_limit';

    public const REASON_WEEKLY_LIMIT = 'weekly_limit';

    public const REASON_NO_TOKEN = 'no_active_token';

    public const REASON_NOT_ELIGIBLE = 'not_eligible';

    public const REASON_DUPLICATE = 'duplicate';

    public const REASON_INCOMPLETE = 'incomplete_content';

    public const REASON_FCM_DISABLED = 'fcm_disabled';

    protected $fillable = [
        'member_id',
        'category',
        'supplement_kind',
        'template_key',
        'title',
        'body',
        'action_route',
        'idempotency_key',
        'status',
        'reason',
        'tokens_targeted',
        'tokens_delivered',
        'campaign_id',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'tokens_targeted' => 'integer',
            'tokens_delivered' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function scopeSent($q)
    {
        return $q->where('status', self::STATUS_SENT);
    }
}
