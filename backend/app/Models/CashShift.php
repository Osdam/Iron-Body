<?php

namespace App\Models;

use App\Enums\CashShiftStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Turno de caja. Ver App\Services\Caja\CashShiftService, que es quien lo abre
 * y lo cierra: aquí solo vive la forma del dato y las consultas.
 */
class CashShift extends Model
{
    protected $fillable = [
        'status',
        'opened_by', 'opened_by_name', 'opened_at', 'opening_amount', 'opening_notes',
        'closed_by', 'closed_by_name', 'closed_at',
        'sales_total', 'cash_sales_total', 'expected_amount', 'counted_amount',
        'difference', 'closing_notes', 'forced', 'forced_reason',
    ];

    protected $casts = [
        'status' => CashShiftStatus::class,
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'opening_amount' => 'decimal:2',
        'sales_total' => 'decimal:2',
        'cash_sales_total' => 'decimal:2',
        'expected_amount' => 'decimal:2',
        'counted_amount' => 'decimal:2',
        'difference' => 'decimal:2',
        'forced' => 'boolean',
    ];

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'opened_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'closed_by');
    }

    /** Ventas registradas durante el turno. */
    public function sales(): HasMany
    {
        return $this->hasMany(ProductSale::class);
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->where('status', CashShiftStatus::OPEN->value);
    }

    public function isOpen(): bool
    {
        return $this->status === CashShiftStatus::OPEN;
    }

    /** El turno abierto, o null. Como máximo hay uno (índice único parcial). */
    public static function current(): ?self
    {
        return self::open()->latest('id')->first();
    }

    /**
     * Totales del turno EN CURSO, calculados sobre sus ventas cobradas.
     *
     * Solo el efectivo entra en el arqueo: una venta con tarjeta o Nequi no
     * pone dinero en el cajón, y contarla haría que la caja «faltara» siempre.
     *
     * @return array{sales_total: float, cash_sales_total: float, expected_amount: float, sales_count: int}
     */
    public function computeTotals(): array
    {
        $paid = $this->sales()
            ->whereIn('status', ['paid', 'delivered'])
            ->get(['total', 'payment_method']);

        $sales = (float) $paid->sum('total');
        $cash = (float) $paid->where('payment_method', 'cash')->sum('total');

        return [
            'sales_total' => round($sales, 2),
            'cash_sales_total' => round($cash, 2),
            'expected_amount' => round((float) $this->opening_amount + $cash, 2),
            'sales_count' => $paid->count(),
        ];
    }

    /** Forma que consume el CRM. */
    public function toCrmArray(bool $withTotals = false): array
    {
        $data = [
            'id' => $this->id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'opened_by' => $this->opened_by,
            'opened_by_name' => $this->opened_by_name,
            'opened_at' => optional($this->opened_at)->toIso8601String(),
            'opening_amount' => (float) $this->opening_amount,
            'opening_notes' => $this->opening_notes,
            'closed_by' => $this->closed_by,
            'closed_by_name' => $this->closed_by_name,
            'closed_at' => optional($this->closed_at)->toIso8601String(),
            'sales_total' => $this->sales_total !== null ? (float) $this->sales_total : null,
            'cash_sales_total' => $this->cash_sales_total !== null ? (float) $this->cash_sales_total : null,
            'expected_amount' => $this->expected_amount !== null ? (float) $this->expected_amount : null,
            'counted_amount' => $this->counted_amount !== null ? (float) $this->counted_amount : null,
            'difference' => $this->difference !== null ? (float) $this->difference : null,
            'closing_notes' => $this->closing_notes,
            'forced' => (bool) $this->forced,
            'forced_reason' => $this->forced_reason,
        ];

        // Para el turno abierto los totales se calculan al vuelo; los de un
        // turno cerrado son los congelados al arquear.
        if ($withTotals && $this->isOpen()) {
            $data = array_merge($data, $this->computeTotals());
        }

        return $data;
    }
}
