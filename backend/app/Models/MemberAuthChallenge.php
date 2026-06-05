<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Reto de verificación en dos pasos (OTP por SMS) para el login de un miembro.
 * El código real nunca se persiste: se guarda su hash en {@see $code_hash}.
 */
class MemberAuthChallenge extends Model
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_VERIFIED  = 'verified';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_EXPIRED   = 'expired';
    public const STATUS_BLOCKED   = 'blocked';

    /** Propósito del reto: el login es el por defecto; el resto son acciones sensibles. */
    public const PURPOSE_LOGIN                = 'login';
    public const PURPOSE_ACCOUNT_DELETE       = 'account_delete';
    public const PURPOSE_DEVICE_REVOKE        = 'device_revoke';
    public const PURPOSE_DEVICE_REVOKE_OTHERS = 'device_revoke_others';
    public const PURPOSE_DEVICE_UNBIND        = 'device_unbind';
    public const PURPOSE_PHONE_CHANGE         = 'phone_change';
    // Ticket de un solo uso para recuperación de número desde el login (sin
    // sesión): se emite tras validar dispositivo confiable y se canjea en la app
    // tras la biometría LOCAL. No envía SMS ni actualiza nada por sí mismo.
    public const PURPOSE_PHONE_RECOVERY_TICKET = 'phone_recovery_ticket';

    /** Nivel del login adaptativo (Bloque 3b). Null = login clásico. */
    public const TIER_LOCAL    = 'local';
    public const TIER_OTP      = 'otp';
    public const TIER_OTP_FACE = 'otp_face';

    protected $fillable = [
        'uuid',
        'member_id',
        'purpose',
        'risk_tier',
        'code_hash',
        'channel',
        'destination',
        'device_id',
        'device_name',
        'platform',
        'ip_address',
        'user_agent',
        'attempts',
        'resend_count',
        'status',
        'last_sent_at',
        'expires_at',
        'consumed_at',
    ];

    protected $hidden = [
        'code_hash',
    ];

    protected function casts(): array
    {
        return [
            'attempts'     => 'integer',
            'resend_count' => 'integer',
            'last_sent_at' => 'datetime',
            'expires_at'   => 'datetime',
            'consumed_at'  => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (MemberAuthChallenge $challenge): void {
            $challenge->uuid ??= (string) Str::uuid();
        });
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Sólo se puede verificar un reto pendiente y no vencido. */
    public function isVerifiable(): bool
    {
        return $this->isPending() && ! $this->isExpired();
    }

    /** Teléfono enmascarado para mostrar al usuario: 300****789. */
    public function maskedDestination(): ?string
    {
        return self::maskPhone($this->destination);
    }

    public static function maskPhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        $len = strlen($digits);
        if ($len < 4) {
            return str_repeat('*', max($len, 1));
        }
        $head = substr($digits, 0, min(3, $len - 2));
        $tail = substr($digits, -2);
        $maskCount = max($len - strlen($head) - strlen($tail), 2);

        return $head . str_repeat('*', $maskCount) . $tail;
    }
}
