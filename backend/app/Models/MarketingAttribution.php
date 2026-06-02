<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingAttribution extends Model
{
    protected $fillable = [
        'lead_id', 'member_id', 'campaign_id', 'sale_amount',
        'membership_id', 'payment_id', 'converted_at',
    ];

    protected $casts = [
        'sale_amount'  => 'decimal:2',
        'converted_at' => 'datetime',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(MarketingLead::class, 'lead_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaign::class, 'campaign_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }
}
