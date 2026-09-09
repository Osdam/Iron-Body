<?php

namespace App\Models;

use App\Enums\CashShiftType;
use App\Services\Billing\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Una obligación pendiente: lo que alguien debe al gimnasio.
 *
 * Ver {@see \App\Services\Caja\ReceivableService}, que es quien la crea y la
 * abona; aquí solo vive la forma del dato y el cálculo del saldo.
 *
 * EL SALDO SE DERIVA, NUNCA SE GUARDA. `paidAmount()` suma los abonos aplicados
 * y `balance()` resta. Guardar el saldo en una columna significa tener dos
 * números que pueden discrepar, y con dinero eso no es un detalle: cuando
 * discrepan, nadie sabe cuál es el bueno.
 */
class Receivable extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PARTIALLY_PAID = 'partially_paid';

    public const STATUS_PAID = 'paid';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PARTIALLY_PAID,
        self::STATUS_PAID,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'member_id', 'type', 'concept', 'total_amount', 'status',
        'source_type', 'source_id', 'created_by', 'created_by_name',
        'notes', 'cancelled_at',
    ];

    protected $casts = [
        'type' => CashShiftType::class,
        'total_amount' => 'decimal:2',
        'cancelled_at' => 'datetime',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }

    /** La venta o el cobro que originó la deuda, si lo hubo. */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ReceivablePayment::class);
    }

    /** Los abonos que cuentan. Un abono revertido no reduce la deuda. */
    public function appliedPayments(): HasMany
    {
        return $this->payments()->where('status', ReceivablePayment::STATUS_APPLIED);
    }

    // ── El invariante: TOTAL = PAGADO + SALDO ───────────────────────────────

    /**
     * Lo abonado hasta ahora.
     *
     * Se suma en la base de datos, no en PHP: acumular decimales en float hace
     * que 0.1 + 0.2 no sea 0.3, y un saldo descuadrado por céntimos es un saldo
     * que alguien tendrá que cuadrar a mano.
     */
    public function paidAmount(): Money
    {
        $suma = $this->appliedPayments()->sum('amount');

        return Money::fromAmount($suma);
    }

    /** Lo que falta por pagar. Nunca negativo: el sobrepago se rechaza antes. */
    public function balance(): Money
    {
        $saldo = $this->total()->minus($this->paidAmount());

        return $saldo->isNegative() ? Money::zero() : $saldo;
    }

    public function total(): Money
    {
        return Money::fromAmount($this->total_amount);
    }

    public function isSettled(): bool
    {
        return $this->balance()->isZero();
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /**
     * El estado que le corresponde por su saldo.
     *
     * Se calcula, no se decide: el estado es una consecuencia del dinero, y
     * dejar que alguien lo fije a mano permitiría marcar como pagada una cuenta
     * que no lo está.
     */
    public function statusForBalance(): string
    {
        if ($this->isCancelled()) {
            return self::STATUS_CANCELLED;
        }

        if ($this->isSettled()) {
            return self::STATUS_PAID;
        }

        return $this->paidAmount()->isZero()
            ? self::STATUS_PENDING
            : self::STATUS_PARTIALLY_PAID;
    }

    // ── Consultas ───────────────────────────────────────────────────────────

    public function scopeOutstanding(Builder $q): Builder
    {
        return $q->whereIn('status', [self::STATUS_PENDING, self::STATUS_PARTIALLY_PAID]);
    }

    public function scopeOfType(Builder $q, CashShiftType $type): Builder
    {
        return $q->where('type', $type->value);
    }

    // ── Presentación ────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    public function toCrmArray(bool $withPayments = false): array
    {
        $pagado = $this->paidAmount();
        $saldo = $this->balance();

        $data = [
            'id' => $this->id,
            'member_id' => $this->member_id,
            'member_name' => $this->member?->full_name,
            'member_document' => $this->member?->document_number,
            'is_staff' => (bool) ($this->member?->is_staff),
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'concept' => $this->concept,
            'total_amount' => $this->total()->toFloat(),
            'paid_amount' => $pagado->toFloat(),
            'balance' => $saldo->toFloat(),
            'status' => $this->status,
            'status_label' => self::statusLabel($this->status),
            'source_type' => $this->source_type ? class_basename($this->source_type) : null,
            'source_id' => $this->source_id,
            'created_by_name' => $this->created_by_name,
            'notes' => $this->notes,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'cancelled_at' => optional($this->cancelled_at)->toIso8601String(),
        ];

        if ($withPayments) {
            $data['payments'] = $this->payments()
                ->orderBy('id')
                ->get()
                ->map(fn (ReceivablePayment $p) => $p->toCrmArray())
                ->all();
        }

        return $data;
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_PENDING => 'Pendiente',
            self::STATUS_PARTIALLY_PAID => 'Abonada',
            self::STATUS_PAID => 'Pagada',
            self::STATUS_CANCELLED => 'Anulada',
            default => $status,
        };
    }
}
