<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Fuente de pago tokenizada de Wompi para cobros recurrentes (pago automático).
 * Guarda SOLO referencias seguras (`wompi_payment_source_id`, marca, últimos 4,
 * expiración). NUNCA PAN/CVC/token sensible. Ver [[iron-body-recurring-subscriptions]].
 */
class WompiPaymentSource extends Model
{
    // Estados de la fuente de pago.
    public const STATUS_PENDING   = 'pending';
    public const STATUS_AVAILABLE = 'available';
    public const STATUS_DECLINED  = 'declined';
    public const STATUS_EXPIRED   = 'expired';
    public const STATUS_REVOKED   = 'revoked';
    public const STATUS_FAILED    = 'failed';

    // Tipos soportados por Wompi para fuentes de pago.
    public const TYPE_CARD  = 'CARD';
    public const TYPE_NEQUI = 'NEQUI';

    protected $fillable = [
        'uuid', 'member_id', 'user_id', 'provider', 'wompi_payment_source_id',
        'type', 'status', 'status_message', 'three_ds_status',
        'card_brand', 'card_last_four', 'exp_month', 'exp_year',
        'customer_email', 'environment', 'is_default',
        'last_used_at', 'revoked_at', 'metadata',
    ];

    protected $casts = [
        'is_default'   => 'boolean',
        'last_used_at' => 'datetime',
        'revoked_at'   => 'datetime',
        'metadata'     => 'array',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(MembershipSubscription::class, 'payment_source_id');
    }

    /** ¿Se puede cobrar con esta fuente (disponible y no revocada)? */
    public function isChargeable(): bool
    {
        return $this->status === self::STATUS_AVAILABLE && $this->revoked_at === null;
    }

    /** Representación pública para la app: método enmascarado, sin secretos. */
    public function toPublicArray(): array
    {
        return [
            'id'          => $this->id,
            'uuid'        => $this->uuid,
            'type'        => $this->type,
            'status'      => $this->status,
            'brand'       => $this->card_brand,
            'last_four'   => $this->card_last_four,
            'exp_month'   => $this->exp_month,
            'exp_year'    => $this->exp_year,
            'is_default'  => (bool) $this->is_default,
            // Etiqueta lista para UI: "VISA •••• 4242".
            'label'       => trim(($this->card_brand ?: strtoupper((string) $this->type))
                .($this->card_last_four ? ' •••• '.$this->card_last_four : '')),
        ];
    }
}
