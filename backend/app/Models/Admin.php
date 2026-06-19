<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Str;

/**
 * Cuenta del panel/CRM. Login por email + contraseña. Los valores de `role`
 * coinciden con el enum `UserRole` del front Angular ('Super Admin',
 * 'Administrador', ...) para que el CRM derive permisos sin transformar.
 */
class Admin extends Authenticatable
{
    public const ROLE_SUPER_ADMIN = 'Super Admin';
    public const ROLE_ADMINISTRADOR = 'Administrador';
    public const ROLE_ADMINISTRATIVO = 'Administrativo';
    public const ROLE_RECEPCION = 'Recepción';

    public const ROLES = [
        self::ROLE_SUPER_ADMIN,
        self::ROLE_ADMINISTRADOR,
        self::ROLE_ADMINISTRATIVO,
        self::ROLE_RECEPCION,
    ];

    protected $fillable = [
        'uuid',
        'name',
        'email',
        'password',
        'role',
        'status',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'last_login_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Admin $admin): void {
            $admin->uuid ??= (string) Str::uuid();
        });
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(AdminSession::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function hasRole(string $role): bool
    {
        return $this->role === $role;
    }

    /** Representación pública para el CRM (login / me). Sin secretos. */
    public function toPublicArray(): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
        ];
    }
}
