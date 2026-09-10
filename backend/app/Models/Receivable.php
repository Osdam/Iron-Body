<?php

namespace App\Models;

use App\Enums\CashShiftType;
use App\Enums\DebtorType;
use App\Services\Caja\DebtorDirectory;
use App\Models\ProductSale;
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
        'member_id', 'debtor_type', 'debtor_id', 'type', 'concept', 'total_amount', 'status',
        'source_type', 'source_id', 'created_by', 'created_by_name',
        'notes', 'cancelled_at',
    ];

    protected $casts = [
        'type' => CashShiftType::class,
        'debtor_type' => DebtorType::class,
        'debtor_id' => 'integer',
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

    // ── Quién debe ──────────────────────────────────────────────────────────

    /**
     * La ficha del deudor: nombre, documento y de qué tipo es.
     *
     * Se resuelve contra la tabla que le corresponde, no contra el nombre que
     * se copió al crear la deuda. Si la persona cambió de nombre o el CRM lo
     * escribió mal, aquí sale el actual; y si dejó de existir, sale null en vez
     * de un nombre que ya no responde por nadie.
     *
     * @return array<string, mixed>|null
     */
    public function debtor(): ?array
    {
        if ($this->debtor_type === null || $this->debtor_id === null) {
            return null;
        }

        return app(DebtorDirectory::class)->resolve($this->debtor_type, $this->debtor_id);
    }

    // ── Las cuatro vistas de Caja ───────────────────────────────────────────

    /**
     * Lo que todavía se debe. Es «Todas».
     *
     * Se filtra por `status`, que es columna real. `paidAmount()` y `balance()`
     * se derivan de los abonos y NO existen como columnas: filtrarlos en SQL
     * exigiría una subconsulta por fila, y el estado ya lo resume.
     */
    public function scopeOutstanding(Builder $q): Builder
    {
        return $q->whereIn('status', [self::STATUS_PENDING, self::STATUS_PARTIALLY_PAID]);
    }

    /** Deuda viva nacida de una venta a crédito. Es «Créditos». */
    public function scopeFromCreditSale(Builder $q): Builder
    {
        return $q->outstanding()->where('source_type', ProductSale::class);
    }

    /**
     * Deuda viva que YA recibió dinero. Es «Abonos».
     *
     * No lista abonos sueltos: lista a quién le están pagando a plazos, que es
     * lo que se busca desde el mostrador. El detalle de la cuenta sí enseña
     * todos sus abonos.
     *
     * Se comprueba que exista al menos un abono APLICADO en vez de fiarse de
     * `status = partially_paid`: si un abono se anula, el saldo vuelve a estar
     * entero y la cuenta deja de ser «a plazos», y así las dos cosas no pueden
     * discrepar.
     */
    public function scopeWithInstallments(Builder $q): Builder
    {
        return $q->outstanding()->whereHas(
            'payments',
            fn (Builder $p) => $p->where('status', ReceivablePayment::STATUS_APPLIED),
        );
    }

    /** Deuda liquidada. Es «Pagadas», y es histórico: no vuelve a cobrarse. */
    public function scopeSettled(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_PAID);
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
        // Si la consulta ya trajo la suma con `withSum('appliedPayments')`, se
        // usa esa. Preguntar de nuevo por cada fila convierte un listado de
        // veinte deudas en veintiuna consultas, y el número sería el mismo.
        if (array_key_exists('applied_payments_sum_amount', $this->attributes)) {
            return Money::fromAmount($this->attributes['applied_payments_sum_amount'] ?? 0);
        }

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

    public function scopeOfType(Builder $q, CashShiftType $type): Builder
    {
        return $q->where('type', $type->value);
    }

    // ── Presentación ────────────────────────────────────────────────────────

    /**
     * @param  array<string,mixed>|null  $debtor  La ficha ya resuelta, para no
     *   consultarla una vez por fila al pintar un listado.
     * @return array<string,mixed>
     */
    public function toCrmArray(bool $withPayments = false, ?array $debtor = null): array
    {
        $pagado = $this->paidAmount();
        $saldo = $this->balance();
        $deudor = $debtor ?? $this->debtor();

        $data = [
            'id' => $this->id,
            'member_id' => $this->member_id,
            'member_name' => $this->member?->full_name,
            'member_document' => $this->member?->document_number,
            'is_staff' => (bool) ($this->member?->is_staff),
            // Quién debe, resuelto contra su tabla. `member_*` se queda para no
            // romper a nadie, pero solo dice algo cuando el deudor es socio.
            'debtor_type' => $this->debtor_type?->value,
            'debtor_type_label' => $this->debtor_type?->label(),
            'debtor_id' => $this->debtor_id,
            'debtor_name' => $deudor['name'] ?? $this->member?->full_name,
            'debtor_document' => $deudor['document'] ?? null,
            'debtor_contact' => $deudor['contact'] ?? null,
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
