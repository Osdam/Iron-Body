<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Perfil laboral de una persona que no es (solo) entrenador: recepción,
 * administración, mantenimiento. Cuelga de su identidad, como el perfil de
 * socio y el de entrenador. Ver `create_staff_profiles_table`.
 */
class StaffProfile extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'uuid',
        'identity_id',
        'full_name',
        'phone',
        'positions',
        'status',
        'deactivated_at',
        'created_by',
        'created_by_name',
    ];

    protected function casts(): array
    {
        return [
            'positions' => 'array',
            'deactivated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (StaffProfile $profile): void {
            $profile->uuid ??= (string) Str::uuid();
        });
    }

    public function identity(): BelongsTo
    {
        return $this->belongsTo(Identity::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** @return list<string> */
    public function positionKeys(): array
    {
        return array_values(array_filter((array) $this->positions, 'is_string'));
    }
}
