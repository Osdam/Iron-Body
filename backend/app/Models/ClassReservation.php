<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClassReservation extends Model
{
    protected $fillable = ['class_id', 'member_id', 'session_date', 'reserved_at'];

    protected function casts(): array
    {
        return [
            'reserved_at'  => 'datetime',
            'session_date' => 'date',
        ];
    }

    public function gymClass(): BelongsTo
    {
        return $this->belongsTo(MyClass::class, 'class_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
