<?php

namespace App\Models;

use App\Support\Access\CrmPermission;
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

    /**
     * Lo que el CRM necesita saber de quien ha iniciado sesión.
     *
     * `permissions` es la lista EFECTIVA que ya calcula el sistema de
     * autorización —los valores por defecto del rol más lo que se haya
     * concedido o revocado en `role_permissions`—, la misma que aplica
     * `EnforceAdminAuthorization` en cada petición.
     *
     * Antes no viajaba, y el navegador se la inventaba a partir del rol con una
     * política guardada en `localStorage`. El resultado: conceder un permiso
     * desde la pantalla de Roles no habilitaba nada en pantalla, porque el CRM
     * nunca preguntaba. Enviarla aquí deja una sola fuente de verdad.
     *
     * No es autorización: el servidor revalida cada acción igual que antes.
     * Sirve para que la interfaz no ofrezca lo que va a ser rechazado, ni
     * esconda lo que sí está permitido.
     *
     * Solo lo consumen `login` y `me`; no se expone en ningún listado.
     */
    public function toPublicArray(): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'permissions' => CrmPermission::forAdmin($this),
        ];
    }
}
