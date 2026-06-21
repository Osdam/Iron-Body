<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberHiddenRoutine extends Model
{
    protected $fillable = [
        'member_id',
        'routine_id',
        'routine_type',
        'reason',
        'hidden_at',
    ];

    protected $casts = [
        'member_id'  => 'integer',
        'routine_id' => 'integer',
        'hidden_at'  => 'datetime',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function routine(): BelongsTo
    {
        return $this->belongsTo(Routine::class);
    }
}
