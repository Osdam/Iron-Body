<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una conexión de WhatsApp Business establecida desde el CRM (Embedded Signup).
 *
 * Solo puede haber UNA vigente: `current()` devuelve la conectada más reciente.
 * El histórico se conserva —las desconectadas no se borran— porque «cuándo se
 * cambió de número» es justo la pregunta que se hace cuando algo dejó de llegar.
 *
 * El token NUNCA sale de aquí. `toPublicArray()` es lo único que se le entrega
 * al CRM y no incluye la credencial ni por asomo; si algún día alguien añade un
 * campo nuevo, tiene que añadirlo ahí a mano, que es exactamente la fricción que
 * se quiere para que no se escape un secreto por descuido de un `toArray()`.
 */
class WhatsappBusinessIntegration extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CONNECTED = 'connected';

    public const STATUS_DISCONNECTED = 'disconnected';

    public const STATUS_ERROR = 'error';

    protected $fillable = [
        'meta_app_id',
        'business_id',
        'waba_id',
        'phone_number_id',
        'display_phone_number',
        'verified_name',
        'business_name',
        'quality_rating',
        'platform_type',
        'status',
        'access_token',
        'token_type',
        'token_expires_at',
        'granted_scopes',
        'connected_by',
        'disconnected_by',
        'connected_at',
        'disconnected_at',
        'last_synced_at',
        'last_error_code',
        'last_error_message',
    ];

    /**
     * El token va cifrado en reposo y oculto en cualquier serialización. Las dos
     * cosas: `encrypted` protege el volcado de la base de datos, `$hidden`
     * protege el `toArray()` que alguien escriba sin pensarlo.
     */
    protected $hidden = [
        'access_token',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'granted_scopes' => 'array',
            'token_expires_at' => 'datetime',
            'connected_at' => 'datetime',
            'disconnected_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'connected_by');
    }

    public function scopeConnected(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_CONNECTED);
    }

    /** La conexión vigente, o null si el canal nunca se conectó desde el CRM. */
    public static function current(): ?self
    {
        return static::query()->connected()->latest('connected_at')->first();
    }

    public function isConnected(): bool
    {
        return $this->status === self::STATUS_CONNECTED;
    }

    /**
     * ¿Sirve para hablar con Graph API?
     *
     * Estar «conectada» no basta: hace falta el token y el id del número. Una
     * fila conectada sin token es el resultado de un intercambio a medias, y
     * dejarla ganar sobre el `.env` apagaría un canal que funcionaba.
     */
    public function isUsable(): bool
    {
        return $this->isConnected()
            && (string) $this->phone_number_id !== ''
            && (string) $this->access_token !== ''
            && ! $this->tokenExpired();
    }

    /** Un token sin caducidad declarada (larga duración) NO está caducado. */
    public function tokenExpired(): bool
    {
        return $this->token_expires_at !== null && $this->token_expires_at->isPast();
    }

    /**
     * Lo que ve el CRM. Sin token, sin secretos y sin el `access_token` ni
     * siquiera enmascarado: que exista se deduce de `has_access_token`.
     *
     * @return array<string,mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'business_id' => $this->business_id,
            'business_name' => $this->business_name,
            'waba_id' => $this->waba_id,
            'phone_number_id' => $this->phone_number_id,
            'display_phone_number' => $this->display_phone_number,
            'verified_name' => $this->verified_name,
            'quality_rating' => $this->quality_rating,
            'platform_type' => $this->platform_type,
            'granted_scopes' => $this->granted_scopes ?? [],
            'has_access_token' => (string) $this->access_token !== '',
            'token_expires_at' => optional($this->token_expires_at)->toIso8601String(),
            'connected_at' => optional($this->connected_at)->toIso8601String(),
            'disconnected_at' => optional($this->disconnected_at)->toIso8601String(),
            'last_synced_at' => optional($this->last_synced_at)->toIso8601String(),
            'connected_by' => $this->connectedBy?->only(['id', 'name', 'email']),
            'last_error_code' => $this->last_error_code,
            'last_error_message' => $this->last_error_message,
        ];
    }
}
