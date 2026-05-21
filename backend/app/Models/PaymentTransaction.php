<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Transacción de pasarela de pago (ePayco). Fuente de verdad del estado del
 * pago para la app. `raw_response` guarda el último payload recibido del
 * proveedor (sin datos de tarjeta — ePayco nunca los envía).
 */
class PaymentTransaction extends Model
{
    public const STATUS_PENDING    = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_APPROVED   = 'approved';
    public const STATUS_FAILED     = 'failed';
    public const STATUS_CANCELLED  = 'cancelled';
    public const STATUS_EXPIRED    = 'expired';

    protected $fillable = [
        'reference', 'idempotency_key', 'order_id', 'member_id', 'user_id', 'plan_id',
        'amount', 'currency', 'status', 'provider', 'method', 'provider_ref',
        'checkout_url', 'description', 'failure_reason', 'customer',
        'raw_response', 'paid_at',
    ];

    protected $casts = [
        'amount'       => 'float',
        'customer'     => 'array',
        'raw_response' => 'array',
        'paid_at'      => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }

    /** Estado finalizado: no admite reintento sobre la misma referencia. */
    public function isSettled(): bool
    {
        return in_array($this->status, [
            self::STATUS_APPROVED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
            self::STATUS_EXPIRED,
        ], true);
    }

    /** Hay un intento en curso: se reutiliza la misma transacción/checkout. */
    public function isInFlight(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING,
            self::STATUS_PROCESSING,
        ], true);
    }

    /** Respuesta segura para la app (sin secretos, sin datos sensibles). */
    public function toPublicArray(): array
    {
        return [
            'ok'           => !in_array($this->status, [self::STATUS_FAILED, self::STATUS_CANCELLED, self::STATUS_EXPIRED], true),
            'transaction_id' => $this->provider_ref ?: (string) $this->id,
            'reference'    => $this->reference,
            'status'       => $this->status,
            'member_id'    => $this->member_id,
            'user_id'      => $this->user_id,
            'plan_id'      => $this->plan_id,
            'amount'       => $this->amount,
            'currency'     => $this->currency,
            'provider'     => $this->provider,
            'method'       => $this->method,
            'provider_ref' => $this->provider_ref,
            'checkout_url' => $this->checkout_url,
            // URL del portal del banco (PSE) para autorizar DENTRO de la app
            // (WebView interno). No es un secreto; no se loguea completa.
            'authorization_url' => is_array($this->raw_response)
                ? ($this->raw_response['urlbanco'] ?? null)
                : null,
            'description'  => $this->description,
            'product'      => $this->description,
            'user_name'    => is_array($this->customer)
                ? ($this->customer['name'] ?? null)
                : null,
            'reason'       => $this->failure_reason,
            'paid_at'      => optional($this->paid_at)->toIso8601String(),
            'created_at'   => optional($this->created_at)->toIso8601String(),
            'updated_at'   => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
