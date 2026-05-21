<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberGuardian extends Model
{
    protected $fillable = [
        'member_id',
        'guardian_full_name',
        'guardian_document_number',
        'guardian_phone',
        'guardian_email',
        'guardian_relationship',
        'guardian_accepts_responsibility',
    ];

    protected function casts(): array
    {
        return [
            'guardian_accepts_responsibility' => 'boolean',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
