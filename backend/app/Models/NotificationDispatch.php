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

    /** Fuera de la franja horaria que permite el gimnasio (hora de Bogotá). */
    public const REASON_OUTSIDE_WINDOW = 'outside_window';

    public const REASON_DAILY_LIMIT = 'daily_limit';

    public const REASON_WEEKLY_LIMIT = 'weekly_limit';

    public const REASON_NO_TOKEN = 'no_active_token';

    public const REASON_NOT_ELIGIBLE = 'not_eligible';

    public const REASON_DUPLICATE = 'duplicate';

    public const REASON_INCOMPLETE = 'incomplete_content';

    public const REASON_FCM_DISABLED = 'fcm_disabled';

    /** Ya salió una notificación de bienestar hace muy poco. */
    public const REASON_MIN_INTERVAL = 'min_interval';

    /** No queda contenido que el socio no haya visto en los últimos 14 días. */
    public const REASON_RECENT_TEMPLATE = 'recent_template';

    /** Todos los dispositivos del socio los rechazó el proveedor por caducados. */
    public const REASON_INVALID_TOKEN = 'invalid_token';

    /** El proveedor (FCM/APNs) no aceptó el envío. */
    public const REASON_PROVIDER_FAILED = 'delivery_failed';

    protected $fillable = [
        'member_id',
        'category',
        'slot',
        'selection_reason',
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
