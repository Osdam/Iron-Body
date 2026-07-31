<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evento de auditoría de una MembershipSubscription. Registro append-only: nunca
 * se borra al cancelar (histórico legal). Sin datos sensibles; `context` guarda
 * solo metadatos auditables (estados, códigos de la pasarela, correlación).
 */
class SubscriptionEvent extends Model
{
    public const TYPE_CREATED = 'created';

    public const TYPE_SOURCE_ATTACHED = 'source_attached';

    public const TYPE_FIRST_CHARGE_APPROVED = 'first_charge_approved';

    public const TYPE_CHARGE_APPROVED = 'charge_approved';

    public const TYPE_CHARGE_DECLINED = 'charge_declined';

    public const TYPE_CHARGE_ERROR = 'charge_error';

    public const TYPE_RETRY_SCHEDULED = 'retry_scheduled';

    public const TYPE_PAST_DUE = 'past_due';

    public const TYPE_PAUSED = 'paused';

    public const TYPE_RESUMED = 'resumed';

    public const TYPE_CANCELLED = 'cancelled';

    public const TYPE_EXPIRED = 'expired';

    public const ACTOR_MEMBER = 'member';

    public const ACTOR_ADMIN = 'admin';

    public const ACTOR_SYSTEM = 'system';

    protected $fillable = [
        'uuid', 'subscription_id', 'member_id', 'type', 'actor',
        'reference', 'amount', 'message', 'context',
    ];

    protected $casts = [
        'amount' => 'float',
        'context' => 'array',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(MembershipSubscription::class, 'subscription_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
