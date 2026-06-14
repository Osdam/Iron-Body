<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Member extends Model
{
    public const STATUS_PENDING_REGISTRATION = 'pending_registration';
    public const STATUS_INCOMPLETE = 'incomplete';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_FAILED = 'failed';
    // Cuenta eliminada/anonimizada por solicitud del usuario: bloquea el login.
    public const STATUS_DELETED = 'deleted';
    // Cuenta suspendida por seguridad (manual desde el CRM o automática si se
    // activa): bloquea login + sesiones hasta que el CRM la desbloquee.
    public const STATUS_SUSPENDED = 'suspended';

    // Estado de inscripción biométrica facial (la biometría es OPCIONAL).
    public const BIOMETRIC_PENDING = 'pending';
    public const BIOMETRIC_REGISTERED = 'registered';
    public const BIOMETRIC_SKIPPED = 'skipped';
    public const BIOMETRIC_MANUAL_REQUIRED = 'manual_required';

    protected $fillable = [
        'member_uuid',
        'user_id',
        'identity_id',
        'access_hash',
        'full_name',
        'email',
        'document_number',
        'phone',
        'gender',
        'goal',
        'training_level',
        'injuries',
        'birth_date',
        'is_minor',
        'is_staff',
        'biometric_status',
        'status',
        'anonymized_at',
        'profile_photo_url',
        'profile_photo_path',
        'profile_photo_updated_at',
    ];

    protected $hidden = [
        'access_hash',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date:Y-m-d',
            'is_minor' => 'boolean',
            'is_staff' => 'boolean',
            'anonymized_at' => 'datetime',
            'profile_photo_updated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Member $member): void {
            $member->member_uuid ??= (string) Str::uuid();
            $member->access_hash ??= self::makeAccessHash($member->member_uuid);
        });
    }

    public static function makeAccessHash(string $memberUuid): string
    {
        return hash_hmac('sha256', $memberUuid, Config::get('app.key'));
    }

    /**
     * Resuelve el miembro a partir del bearer entrante. Primero por
     * `session_token` de dispositivo (2FA / sesiones revocables), y como
     * compatibilidad por el `access_hash` permanente. Lo usan los endpoints que
     * resuelven al miembro fuera del middleware `auth.member` (notificaciones,
     * IRON IA, clases).
     */
    public static function resolveByToken(?string $token): ?self
    {
        if ($token === null || $token === '') {
            return null;
        }

        $session = MemberDeviceSession::query()
            ->whereNull('revoked_at')
            ->where('token_hash', MemberDeviceSession::hashToken($token))
            ->first();

        if ($session) {
            return $session->member;
        }

        return self::where('access_hash', $token)->first();
    }

    public static function normalizeDocumentNumber(?string $documentNumber): ?string
    {
        if ($documentNumber === null) {
            return null;
        }

        $normalized = trim($documentNumber);
        $normalized = preg_replace('/[\s\.\-]+/', '', $normalized);

        return $normalized === '' ? null : $normalized;
    }

    public function isRegistrationResumable(): bool
    {
        return $this->status !== self::STATUS_ACTIVE;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Identidad central de la persona. Aditivo: puede ser null en datos previos
     * al backfill; el resto del flujo del miembro no depende de esta relación.
     */
    public function identity(): BelongsTo
    {
        return $this->belongsTo(Identity::class);
    }

    // ── Seguridad / sesiones (2FA, dispositivos) ─────────────────────────────

    public function deviceSessions(): HasMany
    {
        return $this->hasMany(MemberDeviceSession::class);
    }

    public function authChallenges(): HasMany
    {
        return $this->hasMany(MemberAuthChallenge::class);
    }

    public function securityEvents(): HasMany
    {
        return $this->hasMany(MemberSecurityEvent::class);
    }

    public function riskLocks(): HasMany
    {
        return $this->hasMany(MemberRiskLock::class);
    }

    /** Bloqueo de seguridad vivo (suspensión activa) si existe. */
    public function activeRiskLock(): ?MemberRiskLock
    {
        return $this->riskLocks()->live()->latest('id')->first();
    }

    /** ¿La cuenta está suspendida por seguridad (estado o bloqueo vivo)? */
    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED || $this->activeRiskLock() !== null;
    }

    public function deleteStoredFiles(): void
    {
        $this->loadMissing(['identityDocument', 'signature', 'biometric']);

        foreach ([
            $this->identityDocument?->front_path,
            $this->identityDocument?->back_path,
            $this->signature?->signature_path,
            $this->biometric?->face_path,
        ] as $path) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
        }

        Storage::disk('local')->deleteDirectory("members/{$this->member_uuid}");
    }

    public function identityDocument(): HasOne
    {
        return $this->hasOne(MemberIdentityDocument::class);
    }

    public function legalConsent(): HasOne
    {
        return $this->hasOne(MemberLegalConsent::class);
    }

    public function guardian(): HasOne
    {
        return $this->hasOne(MemberGuardian::class);
    }

    public function signature(): HasOne
    {
        return $this->hasOne(MemberSignature::class);
    }

    public function biometric(): HasOne
    {
        return $this->hasOne(MemberBiometric::class);
    }

    public function trainerAssignments(): HasMany
    {
        return $this->hasMany(MemberTrainerAssignment::class);
    }

    /** Contratos / consentimientos (borrador, pendientes o firmados) del miembro. */
    public function contracts(): HasMany
    {
        return $this->hasMany(MemberContract::class);
    }

    /** Asignación de entrenador vigente (status=active), la más reciente. */
    public function activeTrainerAssignment(): HasOne
    {
        return $this->hasOne(MemberTrainerAssignment::class)
            ->where('status', MemberTrainerAssignment::STATUS_ACTIVE)
            ->latestOfMany();
    }

    /**
     * Devuelve los feature flags resueltos para este miembro.
     * Delega en el Plan del User vinculado; si no hay plan activo o
     * la membresía venció, solo workouts queda en true.
     */
    public function resolvedFeatures(): array
    {
        $this->loadMissing('user');
        $user = $this->user;

        if (! $user) {
            return array_merge(
                array_map(fn () => false, Plan::defaultFeatures()),
                ['workouts' => true],
            );
        }

        $plan      = $user->plan ? Plan::where('name', $user->plan)->first() : null;
        $expiresAt = $user->membershipEndDate
            ? Carbon::parse($user->membershipEndDate)->endOfDay()
            : null;
        $isExpired = $expiresAt && $expiresAt->isPast();

        return ($isExpired || ! $plan)
            ? array_merge(array_map(fn () => false, Plan::defaultFeatures()), ['workouts' => true])
            : $plan->resolvedFeatures();
    }
}
