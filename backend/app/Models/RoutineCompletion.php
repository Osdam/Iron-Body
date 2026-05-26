<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoutineCompletion extends Model
{
    protected $fillable = [
        'member_id',
        'routine_id',
        'completed_at',
        'source',
        'notes',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
            'metadata'     => 'array',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function routine(): BelongsTo
    {
        return $this->belongsTo(Routine::class);
    }
}
