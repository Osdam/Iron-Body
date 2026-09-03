<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Catálogo de roles del CRM. Ver la migración para el porqué del diseño.
 *
 * El nombre es la clave de negocio: `admins.role` y `role_permissions.role` lo
 * referencian por valor, no por id. Es lo que permitió añadir roles dinámicos
 * sin tocar la resolución de permisos, que ya trabajaba con cadenas.
 */
class AdminRole extends Model
{
    protected $fillable = ['name', 'description', 'is_system', 'archived_at', 'created_by', 'created_by_name'];

    protected $casts = [
        'is_system' => 'boolean',
        'archived_at' => 'datetime',
    ];

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** ¿Hay administradores usando este rol ahora mismo? */
    public function adminsCount(): int
    {
        return Admin::where('role', $this->name)->count();
    }

    /**
     * Nombres de rol asignables. Los archivados no aparecen: siguen existiendo
     * para que los admins que aún los tengan conserven su política, pero no se
     * ofrecen para asignaciones nuevas.
     *
     * @return list<string>
     */
    public static function assignableNames(): array
    {
        return self::query()->active()->orderBy('name')->pluck('name')->all();
    }

    /**
     * Todos los nombres de rol conocidos, archivados incluidos.
     *
     * Lo usa la matriz de permisos: un rol archivado con gente asignada sigue
     * necesitando política, y ocultarlo dejaría permisos vigentes invisibles.
     *
     * @return list<string>
     */
    public static function allNames(): array
    {
        return self::query()->orderBy('name')->pluck('name')->all();
    }

    public function toCrmArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'is_system' => $this->is_system,
            'archived' => $this->isArchived(),
            'archived_at' => optional($this->archived_at)->toIso8601String(),
            'admins_count' => $this->adminsCount(),
            'created_by_name' => $this->created_by_name,
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
