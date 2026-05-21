<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberSignature extends Model
{
    protected $fillable = [
        'member_id',
        'kind',
        'signature_path',
        'signature_mime',
        'signature_size',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
