<?php

namespace App\Models;

use App\Enums\InvoiceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Str;

/**
 * Lote de operaciones cobradas de un mismo cliente que respalda UNA factura
 * electrónica multiconcepto. Es el `source` de esa factura.
 */
class InvoiceBundle extends Model
{
    protected $fillable = [
        'uuid', 'member_id', 'idempotency_key', 'final_consumer', 'total', 'preview_hash',
        'created_by_admin_id', 'created_by_name',
    ];

    protected function casts(): array
    {
        return [
            'final_consumer' => 'boolean',
            'total' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (InvoiceBundle $bundle): void {
            $bundle->uuid ??= (string) Str::uuid();
        });
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceBundleItem::class);
    }

    public function invoice(): MorphOne
    {
        return $this->morphOne(ElectronicInvoice::class, 'source')
            ->where('type', InvoiceType::INVOICE->value);
    }
}
