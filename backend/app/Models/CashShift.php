<?php

namespace App\Models;

use App\Enums\CashShiftStatus;
use App\Enums\CashShiftType;
use App\Services\Caja\CashShiftTotalsService;
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
        'type',
        'status',
        'opened_by', 'opened_by_name', 'opened_at', 'opening_amount', 'opening_notes',
        'closed_by', 'closed_by_name', 'closed_at',
        'sales_total', 'cash_sales_total', 'expected_amount', 'counted_amount',
        'transfer_total', 'card_total', 'wompi_total', 'other_total',
        'operations_count', 'auto_observation', 'opening_policy',
        'difference', 'closing_notes', 'forced', 'forced_reason',
    ];

    protected $casts = [
        'type' => CashShiftType::class,
        'status' => CashShiftStatus::class,
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'opening_amount' => 'decimal:2',
        'sales_total' => 'decimal:2',
        'cash_sales_total' => 'decimal:2',
        'expected_amount' => 'decimal:2',
        'counted_amount' => 'decimal:2',
        'difference' => 'decimal:2',
        'transfer_total' => 'decimal:2',
        'card_total' => 'decimal:2',
        'wompi_total' => 'decimal:2',
        'other_total' => 'decimal:2',
        'operations_count' => 'integer',
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

    public function scopeOfType(Builder $q, CashShiftType $type): Builder
    {
        return $q->where('type', $type->value);
    }

    /** Turnos abiertos de un tipo. Como máximo hay uno (índice único parcial). */
    public static function openOfType(CashShiftType $type): Builder
    {
        return self::query()->open()->ofType($type);
    }

    public function isOpen(): bool
    {
        return $this->status === CashShiftStatus::OPEN;
    }

    /** El turno abierto de un tipo, o null. */
    public static function currentOfType(CashShiftType $type): ?self
    {
        return self::openOfType($type)->latest('id')->first();
    }

    /**
     * Totales del turno EN CURSO, delegados al servicio que es su única fuente.
     *
     * Vive aquí solo por comodidad de lectura; el cálculo real —y el que se
     * congela al cerrar— está en CashShiftTotalsService, para que no existan
     * dos sitios capaces de dar cifras distintas.
     *
     * @return array<string,mixed>
     */
    public function computeTotals(): array
    {
        return app(CashShiftTotalsService::class)->for($this);
    }

    /** Forma que consume el CRM. */
    public function toCrmArray(bool $withTotals = false): array
    {
        $data = [
            'id' => $this->id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'opened_by' => $this->opened_by,
            'opened_by_name' => $this->opened_by_name,
            'opened_at' => optional($this->opened_at)->toIso8601String(),
            'opening_amount' => (float) $this->opening_amount,
            'opening_policy' => $this->opening_policy,
            'opening_notes' => $this->opening_notes,
            'closed_by' => $this->closed_by,
            'closed_by_name' => $this->closed_by_name,
            'closed_at' => optional($this->closed_at)->toIso8601String(),
            // Congelados al cerrar. En un turno abierto son null y los totales
            // vivos llegan por `withTotals`.
            'gross_total' => $this->sales_total !== null ? (float) $this->sales_total : null,
            'cash_total' => $this->cash_sales_total !== null ? (float) $this->cash_sales_total : null,
            'transfer_total' => $this->transfer_total !== null ? (float) $this->transfer_total : null,
            'card_total' => $this->card_total !== null ? (float) $this->card_total : null,
            'wompi_total' => $this->wompi_total !== null ? (float) $this->wompi_total : null,
            'other_total' => $this->other_total !== null ? (float) $this->other_total : null,
            'operations_count' => $this->operations_count,
            'expected_cash' => $this->expected_amount !== null ? (float) $this->expected_amount : null,
            'counted_amount' => $this->counted_amount !== null ? (float) $this->counted_amount : null,
            'difference' => $this->difference !== null ? (float) $this->difference : null,
            'auto_observation' => $this->auto_observation,
            'closing_notes' => $this->closing_notes,
            'forced' => (bool) $this->forced,
            'forced_reason' => $this->forced_reason,
        ];

        // En el turno abierto los totales se calculan al vuelo: es lo que ve el
        // operador antes de cerrar. Los de un turno cerrado son los congelados.
        if ($withTotals && $this->isOpen()) {
            $t = $this->computeTotals();
            $data = array_merge($data, [
                'gross_total' => (float) $t['gross_total'],
                'cash_total' => (float) $t['cash_total'],
                'transfer_total' => (float) $t['transfer_total'],
                'card_total' => (float) $t['card_total'],
                'wompi_total' => (float) $t['wompi_total'],
                'other_total' => (float) $t['other_total'],
                'expected_cash' => (float) $t['expected_cash'],
                'operations_count' => (int) $t['operations_count'],
            ]);
        }

        return $data;
    }
}
