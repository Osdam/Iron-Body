<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class AppAd extends Model
{
    public const FREQ_ONCE = 'once';
    public const FREQ_DAILY = 'daily';
    public const FREQ_ALWAYS = 'always';

    protected $fillable = [
        'uuid', 'title', 'description', 'image_url', 'image_path', 'target_url',
        'placement', 'frequency_rule', 'priority', 'starts_at', 'ends_at',
        'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
            'priority' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AppAd $ad): void {
            if (empty($ad->uuid)) {
                $ad->uuid = (string) Str::uuid();
            }
        });
    }

    public function views(): HasMany
    {
        return $this->hasMany(AppAdView::class);
    }

    /** Anuncios vigentes: activos y dentro de la ventana de fechas. */
    public function scopeActive(Builder $q): Builder
    {
        $now = Carbon::now();
        return $q->where('is_active', true)
            ->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>=', $now));
    }

    /**
     * ¿Debe mostrarse a este miembro según la frecuencia y lo ya visto?
     * once = una vez por campaña; daily = una vez por día; always = siempre.
     */
    public function shouldShowTo(int $memberId): bool
    {
        if ($this->frequency_rule === self::FREQ_ALWAYS) {
            return true;
        }
        $view = $this->views()->where('member_id', $memberId)->first();
        if (! $view) {
            return true;
        }
        if ($this->frequency_rule === self::FREQ_DAILY) {
            return ! $view->seen_at?->isToday();
        }
        // once
        return false;
    }

    public function toAppArray(): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'title' => $this->title,
            'description' => $this->description,
            'image_url' => $this->image_url,
            'target_url' => $this->target_url,
            'placement' => $this->placement,
            'frequency_rule' => $this->frequency_rule,
            'priority' => $this->priority,
        ];
    }
}
