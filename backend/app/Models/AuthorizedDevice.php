<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Un dispositivo vinculado por un administrador y que prueba su identidad con
 * una clave privada no exportable. Ver `create_authorized_devices_tables`.
 *
 * Separa lo DECLARADO (plataforma, modelo, versión: lo que dice la app) de lo
 * VERIFICADO (la firma con la clave registrada y, en Android, la atestación de
 * esa clave). Solo lo verificado autoriza.
 */
class AuthorizedDevice extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'uuid', 'name', 'location', 'status',
        'platform', 'manufacturer', 'model', 'os_version', 'app_version', 'key_storage_declared',
        'key_algorithm', 'public_key', 'key_fingerprint',
        'attestation_status', 'attestation_security_level', 'attestation_summary',
        'registered_at', 'registered_by_admin_id', 'registered_by_name',
        'last_seen_at', 'last_ip',
        'revoked_at', 'revoked_by_admin_id', 'revoked_by_name', 'revoked_reason',
    ];

    protected $hidden = ['public_key'];

    protected function casts(): array
    {
        return [
            'attestation_summary' => 'array',
            'registered_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AuthorizedDevice $device): void {
            $device->uuid ??= (string) Str::uuid();
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** Forma corta para jornadas y clases: `{id, name}`. */
    public function toBrief(): array
    {
        return ['id' => $this->uuid, 'name' => $this->name];
    }
}
