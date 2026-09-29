<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    protected $fillable = [
        'user_id',
        'member_id',
        'action',
        'source',
        'confidence',
        'note',
        'captured_at',
        // Entró cuando las reglas de su plan no se lo permitían. Se registra
        // igual: la persona está adentro. Ver la migración.
        'over_limit',
        'limit_reason',
    ];

    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
            'confidence' => 'float',
            'over_limit' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
