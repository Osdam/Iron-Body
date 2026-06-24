<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Perfil fiscal reutilizable del adquiriente (datos DIAN). Ligado 1:1 a
 * `identities`; opcionalmente a user/member. Lo consume FiscalProfileResolver.
 */
class FiscalProfile extends Model
{
    protected $fillable = [
        'identity_id', 'user_id', 'member_id',
        'doc_type', 'doc_number', 'dv', 'person_type', 'legal_name',
        'tax_responsibilities', 'email', 'phone', 'address',
        'city_code', 'department_code',
    ];

    protected $casts = [
        'tax_responsibilities' => 'array',
    ];

    public function identity(): BelongsTo
    {
        return $this->belongsTo(Identity::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** ¿Tiene los mínimos para facturar de forma nominativa (no consumidor final)? */
    public function isComplete(): bool
    {
        return ! empty($this->doc_type) && ! empty($this->doc_number) && ! empty($this->legal_name ?: $this->user?->name);
    }
}
