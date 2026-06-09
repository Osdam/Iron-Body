<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Venta/pedido de productos (caja POS y pedidos de la app).
 *
 * Estados: pending → paid → delivered (cancelled antes de delivered).
 * El stock se descuenta al confirmar el pago ({@see ProductSale::markPaid()}).
 */
class ProductSale extends Model
{
    public const CHANNELS = ['pos', 'app'];
    public const STATUSES = ['pending', 'paid', 'delivered', 'cancelled'];
    public const PAYMENT_METHODS = ['cash', 'card', 'online', 'nequi', 'transfer'];

    protected $fillable = [
        'uuid',
        'code',
        'channel',
        'status',
        'member_id',
        'cashier_user_id',
        'customer_name',
        'payment_method',
        'payment_status',
        'payment_reference',
        'receipt_url',
        'subtotal',
        'discount',
        'total',
        'notes',
        'paid_at',
        'delivered_at',
        'cancelled_at',
    ];

    protected $casts = [
        'subtotal'     => 'decimal:2',
        'discount'     => 'decimal:2',
        'total'        => 'decimal:2',
        'paid_at'      => 'datetime',
        'delivered_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (ProductSale $s): void {
            $s->uuid ??= (string) Str::uuid();
            $s->code ??= self::nextCode();
        });
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProductSaleItem::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_user_id');
    }

    public function scopePos(Builder $q): Builder
    {
        return $q->where('channel', 'pos');
    }

    public function scopeApp(Builder $q): Builder
    {
        return $q->where('channel', 'app');
    }

    /** Código de comprobante legible y secuencial: V-000123. */
    public static function nextCode(): string
    {
        $n = (int) (self::max('id') ?? 0) + 1;
        return 'V-' . str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Confirma el pago y descuenta el stock de cada ítem (transacción).
     * Idempotente: si ya está `paid`/`delivered` no vuelve a descontar.
     */
    public function markPaid(?string $method = null, ?string $reference = null): void
    {
        if (in_array($this->status, ['paid', 'delivered'], true)) {
            return;
        }

        DB::transaction(function () use ($method, $reference): void {
            foreach ($this->items as $item) {
                $item->product?->decrementStock($item->quantity);
            }

            $this->update([
                'status'            => 'paid',
                'payment_status'    => 'paid',
                'payment_method'    => $method ?? $this->payment_method,
                'payment_reference' => $reference ?? $this->payment_reference,
                'paid_at'           => now(),
            ]);
        });
    }

    public function markDelivered(): void
    {
        $this->update(['status' => 'delivered', 'delivered_at' => now()]);
    }

    public function cancel(): void
    {
        if ($this->status === 'delivered') {
            return;
        }
        $this->update(['status' => 'cancelled', 'cancelled_at' => now()]);
    }

    public function toReceiptArray(): array
    {
        return [
            'code'           => $this->code,
            'uuid'           => $this->uuid,
            'channel'        => $this->channel,
            'status'         => $this->status,
            'customer_name'  => $this->customer_name ?? $this->member?->full_name,
            'payment_method' => $this->payment_method,
            'payment_status' => $this->payment_status,
            'subtotal'       => (float) $this->subtotal,
            'discount'       => (float) $this->discount,
            'total'          => (float) $this->total,
            'paid_at'        => optional($this->paid_at)->toIso8601String(),
            'created_at'     => optional($this->created_at)->toIso8601String(),
            'items'          => $this->items->map(fn (ProductSaleItem $i) => [
                'name'       => $i->name,
                'unit_price' => (float) $i->unit_price,
                'quantity'   => $i->quantity,
                'subtotal'   => (float) $i->subtotal,
            ])->all(),
        ];
    }
}
