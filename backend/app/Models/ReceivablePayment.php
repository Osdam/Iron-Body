<?php

namespace App\Models;

use App\Services\Billing\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Support\Caja\PaymentMethodKind;

/**
 * Un abono: dinero real recibido contra una cuenta por cobrar.
 *
 * Es la TERCERA fuente de dinero del arqueo, junto a `product_sales` y
 * `payments`. Lleva turno y medio de pago por eso: un abono en efectivo deja
 * billetes en el cajón y el cierre tiene que poder explicarlos.
 *
 * NO ES UNA VENTA. No crea membresía, no descuenta inventario, no genera otra
 * obligación. Solo reduce una que ya existía.
 */
class ReceivablePayment extends Model
{
    public const STATUS_APPLIED = 'applied';

    public const STATUS_REVERSED = 'reversed';

    protected $fillable = [
        'receivable_id', 'amount', 'method', 'cash_shift_id',
        'balance_before', 'balance_after',
        'client_request_id', 'status',
        'created_by', 'created_by_name', 'reference', 'notes',
        'reversed_at', 'reversed_by', 'reversal_reason',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_before' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'reversed_at' => 'datetime',
    ];

    public function receivable(): BelongsTo
    {
        return $this->belongsTo(Receivable::class);
    }

    public function cashShift(): BelongsTo
    {
        return $this->belongsTo(CashShift::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }

    /** Quién lo anuló. */
    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'reversed_by');
    }

    /**
     * Número del recibo térmico de cada abono, si se emitió.
     *
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    public static function receiptNumbersFor(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return \Illuminate\Support\Facades\DB::table('receipt_sources')
            ->join('receipts', 'receipts.id', '=', 'receipt_sources.receipt_id')
            ->where('receipt_sources.source_type', ReceiptSource::TYPE_RECEIVABLE_PAYMENT)
            ->whereIn('receipt_sources.source_id', $ids)
            ->pluck('receipts.number', 'receipt_sources.source_id')
            ->mapWithKeys(fn ($numero, $id) => [(int) $id => (string) $numero])
            ->all();
    }

    public function scopeApplied(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_APPLIED);
    }

    public function amountMoney(): Money
    {
        return Money::fromAmount($this->amount);
    }

    /** Medio canónico para arqueo y reportes. Solo CASH deja billetes. */
    public function kind(): PaymentMethodKind
    {
        return PaymentMethodKind::normalize($this->method);
    }

    public function isApplied(): bool
    {
        return $this->status === self::STATUS_APPLIED;
    }

    /**
     * @param  string|null  $receiptNumber  Ya resuelto por quien pinta la lista
     *   (ver receiptNumbersFor()); si no llega, se busca este solo.
     * @return array<string,mixed>
     */
    public function toCrmArray(?string $receiptNumber = null): array
    {
        $turno = $this->relationLoaded('cashShift') ? $this->cashShift : null;

        return [
            'id' => $this->id,
            'receivable_id' => $this->receivable_id,
            'amount' => (float) $this->amount,
            'method' => $this->method,
            'method_label' => $this->kind()->label(),
            'cash_shift_id' => $this->cash_shift_id,
            'balance_before' => (float) $this->balance_before,
            'balance_after' => (float) $this->balance_after,
            'status' => $this->status,
            'created_by_name' => $this->created_by_name,
            'reference' => $this->reference,
            'notes' => $this->notes,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'reversed_at' => optional($this->reversed_at)->toIso8601String(),
            'reversal_reason' => $this->reversal_reason,
            'reversed_by_name' => $this->reversed_by === null ? null
                : ($this->relationLoaded('reversedBy') ? $this->reversedBy?->name : $this->reversedBy()->value('name')),
            'cash_shift' => $this->cash_shift_id === null ? null : [
                'id' => $this->cash_shift_id,
                'status' => ($turno ?? $this->cashShift()->first(['id', 'status']))?->status?->value,
            ],
            'receipt_number' => $receiptNumber ?? (self::receiptNumbersFor([(int) $this->id])[(int) $this->id] ?? null),
        ];
    }
}
