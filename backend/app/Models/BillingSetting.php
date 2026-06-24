<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ajustes de facturación NO sensibles, editables desde el CRM (clave/valor).
 * Credenciales y ambiente jamás viven aquí: solo en .env / config/billing.
 */
class BillingSetting extends Model
{
    protected $fillable = ['key', 'value', 'updated_by_admin_id'];

    protected $casts = ['value' => 'array'];

    /** Lee un ajuste por clave con default. */
    public static function get(string $key, mixed $default = null): mixed
    {
        return static::query()->where('key', $key)->value('value') ?? $default;
    }
}
