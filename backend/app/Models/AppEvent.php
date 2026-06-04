<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class AppEvent extends Model
{
    protected $fillable = [
        'uuid', 'title', 'description', 'image_url', 'image_path',
        'starts_at', 'ends_at', 'location', 'cta_label', 'cta_url',
        'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AppEvent $event): void {
            if (empty($event->uuid)) {
                $event->uuid = (string) Str::uuid();
            }
        });
    }

    /** Eventos vigentes para la app: activos y que no hayan terminado. */
    public function scopeVisible(Builder $q): Builder
    {
        $now = Carbon::now();
        return $q->where('is_active', true)
            ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>=', $now));
    }

    public function toAppArray(): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'title' => $this->title,
            'description' => $this->description,
            'image_url' => $this->image_url,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'location' => $this->location,
            'cta_label' => $this->cta_label,
            'cta_url' => $this->cta_url,
        ];
    }
}
