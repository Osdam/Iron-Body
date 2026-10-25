<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una operación cobrada (pago de plan, venta, piso de entrenador) dentro de un
 * lote de factura multiconcepto. Mientras `active`, la operación no puede
 * respaldar otra factura (índice único parcial).
 */
class InvoiceBundleItem extends Model
{
    public const ORIGIN_PAYMENT = 'payment';

    public const ORIGIN_PRODUCT_SALE = 'product_sale';

    public const ORIGIN_COMMISSION_CHARGE = 'commission_charge';

    public const ORIGINS = [self::ORIGIN_PAYMENT, self::ORIGIN_PRODUCT_SALE, self::ORIGIN_COMMISSION_CHARGE];

    protected $fillable = [
        'invoice_bundle_id', 'origin_type', 'origin_id', 'description', 'occurred_at', 'amount', 'active',
        'replaces_invoice_id',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'amount' => 'decimal:2',
            'active' => 'boolean',
        ];
    }

    public function bundle(): BelongsTo
    {
        return $this->belongsTo(InvoiceBundle::class, 'invoice_bundle_id');
    }

    public function key(): string
    {
        return $this->origin_type.':'.$this->origin_id;
    }
}
