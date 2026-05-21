<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberIdentityDocument extends Model
{
    protected $fillable = [
        'member_id',
        'document_type',
        'document_number',
        'birth_date',
        'ocr_full_name',
        'ocr_confidence',
        'identity_status',
        'front_path',
        'front_mime',
        'front_size',
        'back_path',
        'back_mime',
        'back_size',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date:Y-m-d',
            'ocr_confidence' => 'decimal:2',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
