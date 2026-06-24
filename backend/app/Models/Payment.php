<?php

namespace App\Models;

use App\Enums\InvoiceType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Pago registrado en la tabla `payments` (fuente de verdad del CRM).
 *
 * Tanto los pagos creados manualmente por el admin (efectivo, etc.) como los
 * aprobados por ePayco terminan aquí (ver EpaycoPaymentService::onApproved),
 * por lo que el historial del miembro en la app sale de esta tabla.
 */
class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'member_id', 'plan_id', 'amount', 'method', 'reference', 'status', 'paid_at'
    ];

    protected $casts = [
        'amount' => 'float',
        'paid_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class, 'reference', 'reference');
    }

    /** Comprobantes electrónicos (factura + posibles notas crédito) de este pago. */
    public function electronicInvoices(): MorphMany
    {
        return $this->morphMany(ElectronicInvoice::class, 'source');
    }

    /** La factura electrónica (tipo invoice) de este pago, si existe. */
    public function electronicInvoice(): MorphOne
    {
        return $this->morphOne(ElectronicInvoice::class, 'source')
            ->where('type', InvoiceType::INVOICE->value);
    }

    /**
     * Normaliza el status del CRM al vocabulario que consume la app
     * (approved|pending|processing|failed|cancelled|expired|refunded).
     * El CRM usa "paid" como sinónimo de "approved"; el resto pasa tal cual.
     */
    public static function normalizeStatus(?string $status): string
    {
        $status = strtolower((string) $status);

        return match ($status) {
            '', 'paid' => $status === '' ? 'pending' : 'approved',
            default    => $status,
        };
    }

    /**
     * Representación pública para la app (historial de pagos del miembro).
     *
     * NO incluye datos sensibles: ni número de tarjeta, ni CVV, ni tokens de
     * pasarela, ni llaves privadas, ni payload crudo de ePayco. La transacción
     * `payment_transactions` se usa solo para enriquecer campos visibles
     * (descripción, proveedor, customer público) ya que la legada `payments`
     * no los almacena.
     */
    public function toPublicArray(): array
    {
        $tx     = $this->relationLoaded('transaction') ? $this->transaction : null;
        $plan   = $this->relationLoaded('plan') ? $this->plan : null;
        $member = $this->relationLoaded('member') ? $this->member : null;
        $user   = $this->relationLoaded('user') ? $this->user : null;

        $customer = is_array($tx?->customer) ? $tx->customer : [];

        $userName = $member?->full_name
            ?? $user?->name
            ?? ($customer['name'] ?? null);
        $document = $member?->document_number
            ?? $user?->document
            ?? ($customer['doc_number'] ?? null);
        $email = $member?->email
            ?? $user?->email
            ?? ($customer['email'] ?? null);
        $phone = $member?->phone
            ?? $user?->phone
            ?? ($customer['phone'] ?? null);

        $description = $tx?->description
            ?? ($plan ? 'Plan '.$plan->name.' Iron Body' : null);

        $membershipExpiry = optional($user?->membership_end_date)
            ? (is_string($user->membership_end_date)
                ? \Carbon\Carbon::parse($user->membership_end_date)->toIso8601String()
                : $user->membership_end_date->toIso8601String())
            : null;

        return [
            'reference'         => $this->reference,
            'status'            => self::normalizeStatus($this->status),
            'amount'            => (float) $this->amount,
            'currency'          => $tx?->currency ?: 'COP',
            'provider'          => $tx?->provider ?: ($this->method === 'epayco' ? 'epayco' : 'manual'),
            'method'            => $tx?->method ?: $this->method,
            'provider_ref'      => $tx?->provider_ref,
            'description'       => $description,
            'product'           => $plan?->name ?? $description,
            'user_name'         => $userName,
            'document'          => $document,
            'email'             => $email,
            'phone'             => $phone,
            'reason'            => $tx?->failure_reason,
            'paid_at'           => optional($this->paid_at)->toIso8601String(),
            'created_at'        => optional($this->created_at)->toIso8601String(),
            'updated_at'        => optional($this->updated_at)->toIso8601String(),
            'membership_expiry' => $membershipExpiry,
        ];
    }
}
