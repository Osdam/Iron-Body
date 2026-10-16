<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Un socio dentro de un lote de traslado: cómo estaba antes y cómo quedó.
 *
 * `before.plan` es el texto ORIGINAL de `users.plan`; revertir lo devuelve
 * tal cual, variante de escritura incluida. Ver la migración.
 */
class PlanTransferItem extends Model
{
    public const STATUS_APPLIED = 'applied';

    public const STATUS_REVERTED = 'reverted';

    protected $fillable = [
        'batch_id',
        'user_id',
        'user_name',
        'before',
        'after',
        'warnings',
        'status',
        'reverted_at',
        'reverts_item_id',
    ];

    protected function casts(): array
    {
        return [
            'batch_id' => 'integer',
            'user_id' => 'integer',
            'before' => 'array',
            'after' => 'array',
            'warnings' => 'array',
            'reverted_at' => 'datetime',
            'reverts_item_id' => 'integer',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(PlanTransferBatch::class, 'batch_id');
    }

    /** El ítem de reversión que deshizo a este, si lo hubo. */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverts_item_id');
    }

    public function isApplied(): bool
    {
        return $this->status === self::STATUS_APPLIED;
    }
}
