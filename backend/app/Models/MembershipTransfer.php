<?php

namespace App\Models;

use App\Services\Membership\MembershipTransfers;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un traspaso de membresía: la titular cedió a un beneficiario lo que le
 * quedaba del periodo de un cobro. Es un libro, no un estado: deshacerlo marca
 * `reverted_at` y no borra nada. Ver la migración y {@see MembershipTransfers}.
 */
class MembershipTransfer extends Model
{
    protected $fillable = [
        'holder_user_id',
        'beneficiary_user_id',
        'source_payment_id',
        'plan_id',
        'plan_name',
        // El plan COMPRADO (el del cobro) y el ítem de traslado que explica
        // que B reciba otro. Ver add_source_plan_to_membership_transfers_table.
        'source_plan_id',
        'source_plan_name',
        'plan_transfer_item_id',
        'effective_date',
        'end_date',
        'days',
        'holder_plan_before',
        'holder_start_before',
        'holder_end_before',
        'holder_status_before',
        'beneficiary_plan_before',
        'beneficiary_start_before',
        'beneficiary_end_before',
        'beneficiary_status_before',
        'reason',
        'evidence',
        'holder_consent_at',
        'beneficiary_acceptance_at',
        'created_by',
        'created_by_name',
        'client_request_id',
        'reverted_at',
        'reverted_by',
        'reverted_by_name',
        'revert_reason',
    ];

    protected function casts(): array
    {
        return [
            'holder_user_id' => 'integer',
            'beneficiary_user_id' => 'integer',
            'source_payment_id' => 'integer',
            'plan_id' => 'integer',
            'source_plan_id' => 'integer',
            'plan_transfer_item_id' => 'integer',
            'days' => 'integer',
            'effective_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'holder_start_before' => 'date:Y-m-d',
            'holder_end_before' => 'date:Y-m-d',
            'beneficiary_start_before' => 'date:Y-m-d',
            'beneficiary_end_before' => 'date:Y-m-d',
            'holder_consent_at' => 'datetime',
            'beneficiary_acceptance_at' => 'datetime',
            'created_by' => 'integer',
            'reverted_at' => 'datetime',
            'reverted_by' => 'integer',
        ];
    }

    public function holder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'holder_user_id');
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(User::class, 'beneficiary_user_id');
    }

    public function sourcePayment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'source_payment_id');
    }

    public function isLive(): bool
    {
        return $this->reverted_at === null;
    }

    /** Los que siguen en pie: ni revertidos ni deshechos. */
    public function scopeLive(Builder $q): Builder
    {
        return $q->whereNull('reverted_at');
    }

    /** Donde el socio es titular o beneficiario. */
    public function scopeInvolving(Builder $q, int $userId): Builder
    {
        return $q->where(fn (Builder $w) => $w->where('holder_user_id', $userId)->orWhere('beneficiary_user_id', $userId));
    }

    /** Un día guardado como `Y-m-d`, o null. */
    public function day(string $attribute): ?string
    {
        $valor = $this->attributes[$attribute] ?? null;

        return $valor ? substr((string) $valor, 0, 10) : null;
    }
}
