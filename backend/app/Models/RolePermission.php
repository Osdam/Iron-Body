<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Concesión o revocación explícita de un permiso para un rol.
 *
 * Capa SOBRE los valores por defecto del código: la ausencia de fila significa
 * «lo que diga CrmPermission». Ver la migración para el porqué del diseño.
 */
class RolePermission extends Model
{
    protected $fillable = ['role', 'permission', 'granted', 'updated_by', 'updated_by_name'];

    protected $casts = ['granted' => 'boolean'];

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'updated_by');
    }
}
