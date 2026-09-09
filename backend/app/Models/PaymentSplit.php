<?php

namespace App\Models;

use App\Support\Caja\PaymentMethodKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una parte de un cobro repartido entre varios medios de pago.
 *
 * No tiene sentido por sí sola: vive colgada de un {@see Payment} y la suma de
 * las hermanas tiene que dar exactamente `payments.amount`. Quien valida ese
 * invariante es PaymentController; aquí solo se guarda.
 *
 * @property int $payment_id
 * @property string $method
 * @property string $amount
 * @property string|null $reference
 */
class PaymentSplit extends Model
{
    protected $fillable = ['payment_id', 'method', 'amount', 'reference'];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** Medio canónico para arqueo y reportes (efectivo, tarjeta, …). */
    public function kind(): PaymentMethodKind
    {
        return PaymentMethodKind::normalize($this->method);
    }

    /** Fila lista para el CRM y la app. */
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
