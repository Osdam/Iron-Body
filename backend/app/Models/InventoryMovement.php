<?php

namespace App\Models;

use App\Enums\InventoryMovementOrigin;
use App\Enums\InventoryMovementType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Movimiento de existencias (append-only).
 *
 * NO se crea directamente: pasa siempre por {@see App\Services\Inventory\InventoryService},
 * que es el único punto que escribe `products.stock`. Así el saldo y su historia
 * no pueden divergir.
 */
class InventoryMovement extends Model
{
    protected $fillable = [
        'product_id',
        'type',
        'origin',
        'quantity',
        'stock_before',
        'stock_after',
        'unit_amount',
        'reference_type',
        'reference_id',
        'user_id',
        'user_name',
        'reason',
        'notes',
    ];

    protected $casts = [
        'type' => InventoryMovementType::class,
        'origin' => InventoryMovementOrigin::class,
        'quantity' => 'integer',
        'stock_before' => 'integer',
        'stock_after' => 'integer',
        'unit_amount' => 'decimal:2',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Documento origen (ProductSale en las ventas; null en lo administrativo). */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeEntries(Builder $q): Builder
    {
        return $q->where('type', InventoryMovementType::IN->value);
    }

    public function scopeExits(Builder $q): Builder
    {
        return $q->where('type', InventoryMovementType::OUT->value);
    }

    /** Salidas por venta de cafetería (las que sí son ingreso comercial). */
    public function scopeSales(Builder $q): Builder
    {
        return $q->where('origin', InventoryMovementOrigin::SALE_CAFETERIA->value);
    }

    /** Forma que consume el CRM. */
    public function toCrmArray(): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product_name' => $this->product?->name,
            'product_sku' => $this->product?->sku,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'origin' => $this->origin->value,
            'origin_label' => $this->origin->label(),
            'automatic' => $this->origin->isAutomatic(),
            'quantity' => $this->quantity,
            'stock_before' => $this->stock_before,
            'stock_after' => $this->stock_after,
            'unit_amount' => $this->unit_amount !== null ? (float) $this->unit_amount : null,
            'total_amount' => $this->unit_amount !== null
                ? round((float) $this->unit_amount * $this->quantity, 2)
                : null,
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            // Comprobante legible de la venta que originó la salida, si aplica.
            'reference_code' => $this->reference instanceof ProductSale
                ? $this->reference->code
                : null,
            'user_id' => $this->user_id,
            'user_name' => $this->user_name,
            'reason' => $this->reason,
            'notes' => $this->notes,
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
