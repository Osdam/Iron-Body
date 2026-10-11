<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

/**
 * Plantilla facial de una PERSONA (embedding MobileFaceNet 192-D).
 *
 * El nombre de la tabla es histórico: nació para el login de entrenadores en
 * tablet y desde 2026-10 es la plantilla de cualquier identidad que trabaja en
 * el gimnasio (ver la migración `make_face_references_belong_to_identities`).
 *
 * La plantilla viaja cifrada con la APP_KEY en `template`; el servidor compara
 * lo que manda el teléfono, así que en claro sería casi una credencial. Nunca
 * sale del servidor: ni API, ni CRM, ni SSE. Revocar la borra (template = NULL)
 * y conserva la fila como evidencia de quién, cuándo y por qué.
 */
class TrainerFaceReference extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVOKED = 'revoked';

    /** Vía de registro: kiosko con código de un administrador. */
    public const VIA_KIOSK = 'kiosk';

    /** Vía de registro: autoenrolamiento del portal del entrenador (app 2.0.x). */
    public const VIA_TRAINER_PORTAL = 'trainer_portal_legacy';

    protected $fillable = [
        'trainer_id',
        'identity_id',
        'enrolled_at',
        'status',
        'template_version',
        'enrolled_via',
        'enrolled_device_id',
        'enrolled_by_admin_id',
        'enrolled_by_name',
        'consent_version',
        'consent_accepted_at',
        'last_verified_at',
        'revoked_at',
        'revoked_by_admin_id',
        'revoked_by_name',
        'revoked_reason',
    ];

    protected $hidden = [
        'embedding',
        'template',
    ];

    protected function casts(): array
    {
        return [
            'enrolled_at' => 'datetime',
            'consent_accepted_at' => 'datetime',
            'last_verified_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class);
    }

    public function identity(): BelongsTo
    {
        return $this->belongsTo(Identity::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE)->whereNotNull('template');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE && $this->template !== null;
    }

    /**
     * ¿Sirve para verificar a la persona? Solo una plantilla registrada con
     * consentimiento y autorización (kiosko con código de un administrador).
     *
     * Las anteriores a 2026-10 se registraron desde sesiones del portal
     * abiertas con la cédula sola (las 4 de producción, comprobado el
     * 2026-10-11) y sin consentimiento: se conservan, pero quedan pendientes de
     * renovación hasta que la persona se registre de nuevo (decisión del
     * negocio 2026-10-11).
     */
    public function isValidated(): bool
    {
        return $this->isActive() && $this->consent_version !== null;
    }

    /**
     * El vector de la plantilla, o null si no hay (revocada).
     *
     * @return list<float>|null
     */
    public function vector(): ?array
    {
        if ($this->template === null) {
            return null;
        }

        $decoded = json_decode(Crypt::decryptString((string) $this->template), true);

        return is_array($decoded) ? array_map('floatval', array_values($decoded)) : null;
    }

    /** @param list<float> $vector */
    public function storeVector(array $vector): void
    {
        $this->template = Crypt::encryptString(json_encode(array_map('floatval', array_values($vector))));
        // La columna en claro solo existe para poder deshacer la migración.
        $this->embedding = null;
    }

    public function eraseVector(): void
    {
        $this->template = null;
        $this->embedding = null;
    }
}
