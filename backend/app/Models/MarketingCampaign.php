<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingCampaign extends Model
{
    protected $fillable = [
        'meta_campaign_id', 'name', 'status', 'objective', 'spend', 'impressions',
        'reach', 'clicks', 'leads', 'conversations', 'ctr', 'cpc', 'cpm',
        'date_start', 'date_stop', 'raw_metrics', 'synced_at',
    ];

    protected $casts = [
        'spend'        => 'decimal:2',
        'impressions'  => 'integer',
        'reach'        => 'integer',
        'clicks'       => 'integer',
        'leads'        => 'integer',
        'conversations'=> 'integer',
        'ctr'          => 'decimal:4',
        'cpc'          => 'decimal:4',
        'cpm'          => 'decimal:4',
        'date_start'   => 'date',
        'date_stop'    => 'date',
        'raw_metrics'  => 'array',
        'synced_at'    => 'datetime',
    ];

    public function leads(): HasMany
    {
        return $this->hasMany(MarketingLead::class, 'campaign_id');
    }

    public function attributions(): HasMany
    {
        return $this->hasMany(MarketingAttribution::class, 'campaign_id');
    }

    /** ROAS = ingreso atribuido / gasto (null si no hay gasto). */
    public function roas(): ?float
    {
        $spend = (float) $this->spend;
        if ($spend <= 0) {
            return null;
        }
        return round((float) $this->attributions()->sum('sale_amount') / $spend, 2);
    }
}
