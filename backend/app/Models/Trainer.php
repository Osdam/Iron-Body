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

    public function roleAssignments(): HasMany
    {
        return $this->hasMany(TrainerRole::class);
    }

    /**
     * Roles profesionales vigentes del entrenador (`trainer_floor`,
     * `trainer_functional`). Solo válidos según el catálogo.
     *
     * @return list<string>
     */
    public function roleNames(): array
    {
        return $this->roleAssignments
            ->pluck('role')
            ->filter(fn (string $role): bool => TrainerRole::isValid($role))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Permisos efectivos = UNIÓN de los permisos de todos sus roles, según el
     * catálogo central de `config/trainer.php`. Autoridad única de permisos.
     *
     * @return list<string>
     */
    public function permissions(): array
    {
        $catalog = (array) config('trainer.permissions', []);

        $permissions = [];
        foreach ($this->roleNames() as $role) {
            foreach ((array) ($catalog[$role] ?? []) as $permission) {
                $permissions[$permission] = true;
            }
        }

        return array_keys($permissions);
    }

    /**
     * ¿El entrenador tiene un permiso? Un entrenador inactivo no tiene ninguno:
     * la desactivación en el CRM corta el acceso profesional de inmediato.
     */
    public function hasPermission(string $permission): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        return in_array($permission, $this->permissions(), true);
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roleNames(), true);
    }

    /**
     * Sincroniza el conjunto de roles del entrenador con los indicados. Ignora
     * roles inválidos. Idempotente. Devuelve los roles finales.
     *
     * @param  iterable<string>  $roles
     * @return list<string>
     */
    public function syncRoles(iterable $roles): array
    {
        $desired = collect($roles)
            ->filter(fn ($role): bool => is_string($role) && TrainerRole::isValid($role))
            ->unique()
            ->values();

        $this->roleAssignments()->whereNotIn('role', $desired)->delete();

        foreach ($desired as $role) {
            $this->roleAssignments()->firstOrCreate(['role' => $role]);
        }

        $this->load('roleAssignments');

        return $desired->all();
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
