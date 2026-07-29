<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Línea de una venta/pedido. Snapshot de nombre y precio. */
class ProductSaleItem extends Model
{
    protected $fillable = [
        'product_sale_id',
        'product_id',
        'name',
        'unit_price',
        'quantity',
        'subtotal',
        // Snapshot fiscal por línea (Pricing V2). Congela el tratamiento con el
        // que se cobró: editar el producto después ya no altera el comprobante.
        'base_unit_amount', 'tax_unit_amount', 'gross_unit_amount',
        'base_amount', 'tax_amount', 'gross_amount',
        'tax_rate_id', 'tax_rate', 'pricing_mode', 'pricing_rules_version',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'quantity' => 'integer',
        'subtotal' => 'decimal:2',
    ];

    /** ¿La línea trae el snapshot fiscal congelado? */
    public function hasFinancialSnapshot(): bool
    {
        return $this->gross_amount !== null;
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(ProductSale::class, 'product_sale_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
