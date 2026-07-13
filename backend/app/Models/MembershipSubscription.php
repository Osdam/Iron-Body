<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Suscripción de membresía con cobro automático (pago recurrente tipo Netflix).
 * NO cobra por sí sola: el cobro real es una PaymentTransaction que fluye por el
 * activador de membresía existente. Aquí vive la AUTORIZACIÓN recurrente, el
 * precio congelado y la programación del próximo cobro.
 *
 * Ver [[iron-body-recurring-subscriptions]].
 */
class MembershipSubscription extends Model
{
    // Estados de la suscripción.
    public const STATUS_PENDING_FIRST_PAYMENT = 'pending_first_payment';
    public const STATUS_ACTIVE                = 'active';
    public const STATUS_PAST_DUE              = 'past_due';
    public const STATUS_PAUSED               = 'paused';
    public const STATUS_CANCELLED            = 'cancelled';
    public const STATUS_EXPIRED              = 'expired';
    public const STATUS_FAILED               = 'failed';

    /** Estados en los que la suscripción sigue "viva" (una sola por miembro). */
    public const LIVE_STATUSES = [
        self::STATUS_PENDING_FIRST_PAYMENT,
        self::STATUS_ACTIVE,
        self::STATUS_PAST_DUE,
        self::STATUS_PAUSED,
    ];

    /** Estados que el scheduler puede cobrar automáticamente. */
    public const CHARGEABLE_STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_PAST_DUE,
    ];

    protected $fillable = [
        'uuid', 'member_id', 'user_id', 'plan_id', 'payment_source_id',
        'status', 'price_snapshot', 'currency', 'interval_days', 'method',
        'next_charge_at', 'current_period_start', 'current_period_end',
        'last_charged_at', 'failed_attempts', 'retry_stage', 'last_charge_reference',
        'cancel_at_period_end', 'cancelled_at', 'cancelled_by', 'cancel_reason',
        'paused_at', 'metadata',
    ];

    protected $casts = [
        'price_snapshot'       => 'float',
        'interval_days'        => 'integer',
        'next_charge_at'       => 'datetime',
        'current_period_start' => 'date',
        'current_period_end'   => 'date',
        'last_charged_at'      => 'datetime',
        'failed_attempts'      => 'integer',
        'retry_stage'          => 'integer',
        'cancel_at_period_end' => 'boolean',
        'cancelled_at'         => 'datetime',
        'paused_at'            => 'datetime',
        'metadata'             => 'array',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function paymentSource(): BelongsTo
    {
        return $this->belongsTo(WompiPaymentSource::class, 'payment_source_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SubscriptionEvent::class, 'subscription_id');
    }

    /** Intentos de cobro (PaymentTransactions) de esta suscripción. */
    public function charges(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class, 'subscription_id');
    }

    /** ¿Sigue viva (cuenta para el límite de una activa por miembro)? */
    public function isLive(): bool
    {
        return in_array($this->status, self::LIVE_STATUSES, true);
    }

    /** ¿El scheduler puede cobrarla automáticamente ahora? */
    public function isChargeable(): bool
    {
        return in_array($this->status, self::CHARGEABLE_STATUSES, true);
    }

    /** Representación pública para la app (sin secretos). */
    public function toPublicArray(): array
    {
        return [
            'id'                 => $this->id,
            'uuid'               => $this->uuid,
            'status'             => $this->status,
            'plan_id'            => $this->plan_id,
            'amount'             => (float) $this->price_snapshot,
            'currency'           => $this->currency,
            'interval_days'      => $this->interval_days,
            'method'             => $this->method,
            'next_charge_at'     => optional($this->next_charge_at)->toIso8601String(),
            'current_period_end' => optional($this->current_period_end)->toDateString(),
            'cancel_at_period_end' => (bool) $this->cancel_at_period_end,
            'payment_source'     => $this->relationLoaded('paymentSource') && $this->paymentSource
                ? $this->paymentSource->toPublicArray()
                : null,
        ];
    }
}
