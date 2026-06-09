<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Producto del gimnasio (inventario CRM + tienda de la app).
 *
 * Ver App\Models\ProductSale para las ventas. El stock se descuenta vía
 * {@see Product::decrementStock()} desde la confirmación de pago.
 */
class Product extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'sku',
        'name',
        'category',
        'description',
        'image_url',
        'sale_price',
        'cost_price',
        'stock',
        'min_stock',
        'supplier',
        'visible_in_app',
        'active',
    ];

    protected $casts = [
        'sale_price'     => 'decimal:2',
        'cost_price'     => 'decimal:2',
        'stock'          => 'integer',
        'min_stock'      => 'integer',
        'visible_in_app' => 'boolean',
        'active'         => 'boolean',
    ];

    protected $appends = ['stock_status', 'in_app'];

    protected static function booted(): void
    {
        static::creating(function (Product $p): void {
            $p->uuid ??= (string) Str::uuid();
        });
    }

    /** Disponible (catálogo). */
    public function scopeActive(Builder $q): Builder
    {
        return $q->where('active', true);
    }

    /** Visible en la tienda de la app: activo, marcado visible y con stock. */
    public function scopeForStore(Builder $q): Builder
    {
        return $q->where('active', true)
            ->where('visible_in_app', true)
            ->where('stock', '>', 0);
    }

    public function getStockStatusAttribute(): string
    {
        if ($this->stock <= 0) {
            return 'out';
        }
        if ($this->min_stock > 0 && $this->stock <= $this->min_stock) {
            return 'low';
        }
        return 'ok';
    }

    /** Alias claro para la app (si está visible en tienda). */
    public function getInAppAttribute(): bool
    {
        return (bool) $this->visible_in_app;
    }

    /** Descuenta stock de forma segura (no baja de 0). Devuelve true si alcanzó. */
    public function decrementStock(int $qty): bool
    {
        if ($qty <= 0) {
            return true;
        }
        if ($this->stock < $qty) {
            return false;
        }
        $this->decrement('stock', $qty);
        return true;
    }

    /** Forma para la tienda de la app (sin datos de costo/proveedor). */
    public function toStoreArray(): array
    {
        return [
            'id'          => $this->id,
            'uuid'        => $this->uuid,
            'name'        => $this->name,
            'category'    => $this->category,
            'description' => $this->description,
            'image_url'   => $this->image_url,
            'price'       => (float) $this->sale_price,
            'stock'       => $this->stock,
            'available'   => $this->stock > 0,
        ];
    }
}
