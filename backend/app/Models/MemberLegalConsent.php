<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberLegalConsent extends Model
{
    protected $fillable = [
        'member_id',
        'accepted_at',
        'contract_version',
        'terms_and_conditions',
        'data_processing',
        'truthfulness',
        'service_contract',
        'physical_risk_waiver',
        'guardian_authorization',
    ];

    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
            'terms_and_conditions' => 'boolean',
            'data_processing' => 'boolean',
            'truthfulness' => 'boolean',
            'service_contract' => 'boolean',
            'physical_risk_waiver' => 'boolean',
            'guardian_authorization' => 'boolean',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
