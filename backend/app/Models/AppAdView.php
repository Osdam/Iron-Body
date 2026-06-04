<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppAdView extends Model
{
    protected $fillable = ['app_ad_id', 'member_id', 'seen_at'];

    protected function casts(): array
    {
        return ['seen_at' => 'datetime'];
    }

    public function ad(): BelongsTo
    {
        return $this->belongsTo(AppAd::class, 'app_ad_id');
    }
}
