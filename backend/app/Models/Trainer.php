<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Trainer extends Model
{
    protected $fillable = [
        'identity_id',
        'full_name',
        'document',
        'phone',
        'email',
        'birth_date',
        'main_specialty',
        'specialties',
        'experience_years',
        'contract_type',
        'status',
        'rating',
        'bio',
        'certifications',
        'avatar_url',
        'banner_url',
        'availability',
        'assigned_classes',
        'assigned_members',
    ];

    protected $casts = [
        'specialties' => 'array',
        'availability' => 'array',
        'birth_date' => 'date:Y-m-d',
        'experience_years' => 'integer',
        'assigned_classes' => 'integer',
        'assigned_members' => 'integer',
        'rating' => 'float',
    ];

    protected $appends = ['name'];

    /**
     * Identidad central de la persona. Aditivo y nullable: un entrenador puede
     * existir sin perfil de miembro y viceversa. La baja como entrenador no
     * elimina la identidad ni el perfil de miembro asociado.
     */
    public function identity(): BelongsTo
    {
        return $this->belongsTo(Identity::class);
    }

    public function classes(): HasMany
    {
        return $this->hasMany(MyClass::class, 'trainer_id');
    }

    public function memberAssignments(): HasMany
    {
        return $this->hasMany(MemberTrainerAssignment::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(TrainerReview::class);
    }

    // Alias para TrainerResource y el endpoint de calificaciones de la app
    public function ratings(): HasMany
    {
        return $this->hasMany(TrainerReview::class);
    }

    public function getIsActiveAttribute(): bool
    {
        return $this->isActive();
    }

    public function getNameAttribute(): string
    {
        return (string) ($this->attributes['full_name'] ?? '');
    }

    public function isActive(): bool
    {
        $status = Str::lower((string) ($this->status ?? 'active'));

        return in_array($status, ['active', 'activo'], true);
    }

    public function certificationsArray(): array
    {
        $value = $this->certifications;

        if (is_array($value)) {
            return $this->cleanList($value);
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $this->cleanList($decoded);
        }

        return $this->cleanList(preg_split('/\r\n|\r|\n|,/', $value) ?: []);
    }

    public function publicPhotoUrl(): ?string
    {
        $url = $this->avatar_url ?: $this->banner_url;

        if (! $url) {
            return null;
        }

        if (Str::startsWith($url, ['http://', 'https://'])) {
            return $url;
        }

        if (Str::startsWith($url, ['/'])) {
            return url($url);
        }

        return Storage::disk('public')->url($url);
    }

    private function cleanList(array $items): array
    {
        return array_values(array_filter(array_map(
            fn (mixed $item): string => trim((string) $item),
            $items
        )));
    }
}
