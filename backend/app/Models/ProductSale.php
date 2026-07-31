<?php

namespace App\Models;

use App\Enums\InvoiceType;
use App\Services\Billing\InvoiceEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
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
        // Snapshot fiscal de la venta (Pricing V2). `subtotal`/`total` conservan
        // su semántica histórica de mostrador.
        'base_amount', 'tax_amount', 'gross_amount',
        'pricing_mode', 'pricing_rules_version', 'priced_at',
        // Solicitud EXPRESA de factura electrónica (ver migración
        // 2026_07_29_000003). Una venta de mostrador no crea transacción de
        // pasarela, así que la intención de facturar se guarda aquí.
        'invoice_requested', 'invoice_email', 'invoice_requested_at',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'discount' => 'decimal:2',
        'total' => 'decimal:2',
        'paid_at' => 'datetime',
        'delivered_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'priced_at' => 'datetime',
        'invoice_requested' => 'boolean',
        'invoice_requested_at' => 'datetime',
    ];

    /**
     * Marca la solicitud de factura conservando la PRIMERA fecha. Idempotente:
     * reintentos y doble clic no generan una segunda solicitud.
     */
    public function marcarFacturaSolicitada(?string $email = null): bool
    {
        $email = InvoiceEmail::normalizar($email);

        if ($this->invoice_requested && $this->invoice_requested_at !== null) {
            if ($email !== null && blank($this->invoice_email)) {
                $this->forceFill(['invoice_email' => $email])->save();
            }

            return false;
        }

        $this->forceFill([
            'invoice_requested' => true,
            'invoice_email' => $email ?: $this->invoice_email,
            'invoice_requested_at' => $this->invoice_requested_at ?? now(),
        ])->save();

        return true;
    }

    /** ¿La venta trae el snapshot fiscal congelado (Pricing V2)? */
    public function hasFinancialSnapshot(): bool
    {
        return $this->gross_amount !== null;
    }

    /**
     * Total bruto congelado de la venta. Con snapshot usa `gross_amount`;
     * sin snapshot cae a `total`, que siempre fue el bruto cobrado en caja.
     */
    public function grossAmountValue(): float
    {
        return (float) ($this->gross_amount ?? $this->total);
    }

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

    /** Comprobantes electrónicos (factura + posibles notas crédito) de la venta. */
    public function electronicInvoices(): MorphMany
    {
        return $this->morphMany(ElectronicInvoice::class, 'source');
    }

    /** La factura electrónica (tipo invoice) de esta venta, si existe. */
    public function electronicInvoice(): MorphOne
    {
        return $this->morphOne(ElectronicInvoice::class, 'source')
            ->where('type', InvoiceType::INVOICE->value);
    }

    /** Resumen compacto de la factura electrónica para el CRM admin (o null). */
    public function getInvoiceSummaryAttribute(): ?array
    {
        if (! $this->relationLoaded('electronicInvoice') || $this->electronicInvoice === null) {
            return null;
        }
        $inv = $this->electronicInvoice;

        return [
            'id' => $inv->id,
            'status' => $inv->status->value,
            'full_number' => $inv->full_number,
            'cufe' => $inv->cufe,
        ];
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

        return 'V-'.str_pad((string) $n, 6, '0', STR_PAD_LEFT);
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
                'status' => 'paid',
                'payment_status' => 'paid',
                'payment_method' => $method ?? $this->payment_method,
                'payment_reference' => $reference ?? $this->payment_reference,
                'paid_at' => now(),
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
            'code' => $this->code,
            'uuid' => $this->uuid,
            'channel' => $this->channel,
            'status' => $this->status,
            'customer_name' => $this->customer_name ?? $this->member?->full_name,
            'payment_method' => $this->payment_method,
            'payment_status' => $this->payment_status,
            'subtotal' => (float) $this->subtotal,
            'discount' => (float) $this->discount,
            'total' => (float) $this->total,
            // Desglose fiscal congelado (null en ventas legacy sin snapshot).
            'base_amount' => $this->base_amount !== null ? (float) $this->base_amount : null,
            'tax_amount' => $this->tax_amount !== null ? (float) $this->tax_amount : null,
            'gross_amount' => $this->gross_amount !== null ? (float) $this->gross_amount : null,
            'pricing_mode' => $this->pricing_mode,
            'paid_at' => optional($this->paid_at)->toIso8601String(),
            'created_at' => optional($this->created_at)->toIso8601String(),
            'items' => $this->items->map(fn (ProductSaleItem $i) => [
                'name' => $i->name,
                'unit_price' => (float) $i->unit_price,
                'quantity' => $i->quantity,
                'subtotal' => (float) $i->subtotal,
                'base_amount' => $i->base_amount !== null ? (float) $i->base_amount : null,
                'tax_amount' => $i->tax_amount !== null ? (float) $i->tax_amount : null,
                'tax_rate' => $i->tax_rate !== null ? (float) $i->tax_rate : null,
                'gross_amount' => $i->gross_amount !== null ? (float) $i->gross_amount : null,
            ])->all(),
        ];
    }
}
