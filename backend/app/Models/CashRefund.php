<?php

namespace App\Models;

use App\Enums\CashShiftType;
use App\Support\Caja\PaymentMethodKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Dinero que SALE de la caja porque se le devuelve a un cliente lo que pagó.
 *
 * Es la contraparte de una devolución: el cobro o la venta devueltos SIGUEN
 * contando en el turno en que entraron —el dinero entró de verdad— y esta fila
 * lo resta del turno ABIERTO en que se devuelve, por su medio. Nunca se edita ni
 * se borra: una devolución mal hecha no se «deshace», se explica.
 *
 * Sin turno solo en la pasarela: la devolución se hizo en el panel de Wompi, del
 * cajón no salió nada y esta fila es la constancia, con su referencia externa.
 *
 * Ver docs/ops/CORRECCIONES-ADMINISTRATIVAS.md §1.
 */
class CashRefund extends Model
{
    protected $fillable = [
        'cash_type', 'cash_shift_id', 'refundable_type', 'refundable_id',
        'amount', 'method', 'reason', 'items', 'restocked', 'external_reference',
        'client_request_id', 'created_by', 'created_by_name',
    ];

    protected $casts = [
        'cash_type' => CashShiftType::class,
        'amount' => 'decimal:2',
        'items' => 'array',
        'restocked' => 'boolean',
    ];

    /** El cobro o la venta devueltos. */
    public function refundable(): MorphTo
    {
        return $this->morphTo();
    }

    public function cashShift(): BelongsTo
    {
        return $this->belongsTo(CashShift::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }

    public function kind(): PaymentMethodKind
    {
        return PaymentMethodKind::normalize($this->method);
    }

    /** @return array<string, mixed> */
    public function toCrmArray(): array
    {
        return [
            'id' => $this->id,
            'cash_type' => $this->cash_type?->value,
            'cash_shift_id' => $this->cash_shift_id,
            'refundable_type' => $this->refundable_type === ProductSale::class ? 'product_sale' : 'payment',
            'refundable_id' => $this->refundable_id,
            'amount' => (float) $this->amount,
            'method' => $this->method,
            'method_label' => $this->kind()->label(),
            'reason' => $this->reason,
            'items' => $this->items,
            'restocked' => (bool) $this->restocked,
            'external_reference' => $this->external_reference,
            'created_by_name' => $this->created_by_name,
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
