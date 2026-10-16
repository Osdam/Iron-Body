<?php

namespace App\Models;

use App\Services\Membership\PlanTransfers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un traslado de socios entre planes, ya confirmado. O su reversión.
 *
 * Es un libro, no un estado: nada se borra. Deshacer un traslado crea otro
 * lote (`kind = reversal`) que apunta a este, y este pasa a `reverted` o a
 * `partially_reverted`. Ver la migración y {@see PlanTransfers}.
 */
class PlanTransferBatch extends Model
{
    public const KIND_TRANSFER = 'transfer';

    public const KIND_REVERSAL = 'reversal';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_PARTIALLY_REVERTED = 'partially_reverted';

    public const STATUS_REVERTED = 'reverted';

    protected $fillable = [
        'kind',
        'reverts_batch_id',
        'status',
        'from_plan_id',
        'to_plan_id',
        'from_plan_name',
        'to_plan_name',
        'from_conditions',
        'to_conditions',
        'reason',
        'fingerprint',
        'client_request_id',
        'members_count',
        'excluded',
        'business_day',
        'executed_at',
        'created_by',
        'created_by_name',
        'created_by_role',
    ];

    protected function casts(): array
    {
        return [
            'reverts_batch_id' => 'integer',
            'from_plan_id' => 'integer',
            'to_plan_id' => 'integer',
            'from_conditions' => 'array',
            'to_conditions' => 'array',
            'excluded' => 'array',
            'members_count' => 'integer',
            'business_day' => 'date:Y-m-d',
            'executed_at' => 'datetime',
            'created_by' => 'integer',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PlanTransferItem::class, 'batch_id');
    }

    /** Las reversiones que se hicieron sobre este traslado. */
    public function reversals(): HasMany
    {
        return $this->hasMany(self::class, 'reverts_batch_id');
    }

    /** El traslado que deshace esta reversión. */
    public function revertsBatch(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverts_batch_id');
    }

    public function isTransfer(): bool
    {
        return $this->kind === self::KIND_TRANSFER;
    }
}
