<?php

namespace App\Models;

use App\Services\Billing\PricingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Producto del gimnasio (inventario CRM + tienda de la app).
 *
 * Ver App\Models\ProductSale para las ventas. El stock NUNCA se escribe desde
 * aquí: la única puerta es App\Services\Inventory\InventoryService, que valida
 * existencias y deja traza en `inventory_movements`.
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
        // Facturación electrónica (aditivo).
        'tax_rate_id',
        'price_includes_tax',
        'unspsc_code',
        // Pricing V2.
        'pricing_mode',
        'billing_enabled',
    ];

    protected $casts = [
        'sale_price' => 'decimal:2',
        'cost_price' => 'decimal:2',
        'stock' => 'integer',
        'min_stock' => 'integer',
        'visible_in_app' => 'boolean',
        'active' => 'boolean',
        'price_includes_tax' => 'boolean',
        'billing_enabled' => 'boolean',
    ];

    protected $appends = ['stock_status', 'in_app'];

    protected static function booted(): void
    {
        static::creating(function (Product $p): void {
            $p->uuid ??= (string) Str::uuid();
        });
    }

    /** Tarifa de IVA del producto (facturación electrónica). */
    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }

    /**
     * ¿Este producto genera comprobante fiscal? Un producto de precio 0 o
     * marcado como no facturable no exige tratamiento tributario.
     *
     * `null` cuenta como facturable (default de la columna): un producto recién
     * creado no debe saltarse el control tributario.
     */
    public function isBillable(): bool
    {
        return ($this->billing_enabled ?? true) && (float) $this->sale_price > 0;
    }

    /** Semántica del precio configurado (ver App\Services\Billing\PricingMode). */
    public function pricingMode(): PricingMode
    {
        return PricingMode::fromValue($this->pricing_mode);
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

    /**
     * ¿Alcanza el stock para esta cantidad? Comprobación de LECTURA.
     *
     * Sustituye a `decrementStock()`, que escribía existencias devolviendo un
     * bool que su único llamador descartaba: una venta con stock insuficiente
     * quedaba cobrada y el saldo intacto. Escribir el stock es ahora
     * responsabilidad exclusiva de App\Services\Inventory\InventoryService, que
     * bloquea la fila, falla en voz alta y deja movimiento.
     */
    public function hasStockFor(int $qty): bool
    {
        return $qty <= 0 || $this->stock >= $qty;
    }

    /** Movimientos de existencias del producto (más recientes primero). */
    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class)->latest('id');
    }

    /** Forma para la tienda de la app (sin datos de costo/proveedor). */
    public function toStoreArray(): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'category' => $this->category,
            'description' => $this->description,
            'image_url' => $this->image_url,
            'price' => (float) $this->sale_price,
            'stock' => $this->stock,
            'available' => $this->stock > 0,
        ];
    }
}
