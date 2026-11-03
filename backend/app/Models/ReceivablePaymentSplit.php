<?php

namespace App\Models;

use App\Support\Caja\PaymentMethodKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una parte de un abono cobrado entre varios medios.
 *
 * Espejo de {@see PaymentSplit} y {@see ProductSaleSplit}. Ver la migración
 * para por qué son tres tablas y no una polimórfica.
 */
class ReceivablePaymentSplit extends Model
{
    protected $fillable = ['receivable_payment_id', 'method', 'amount', 'reference'];

    protected $casts = ['amount' => 'decimal:2'];

    public function receivablePayment(): BelongsTo
    {
        return $this->belongsTo(ReceivablePayment::class);
    }

    /** Medio canónico para arqueo y reportes (efectivo, tarjeta, …). */
    public function kind(): PaymentMethodKind
    {
        return PaymentMethodKind::normalize($this->method);
    }

    /** Fila lista para el CRM. */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'method' => $this->method,
            'method_label' => $this->kind()->label(),
            'amount' => (float) $this->amount,
            'reference' => $this->reference,
        ];
    }
}
