<?php

namespace App\Services;

use App\Exceptions\OtpException;
use App\Models\Member;
use App\Models\MemberAuthChallenge;
use App\Models\MemberSecurityEvent;
use App\Services\Sms\SmsSenderFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * Verificación en dos pasos (OTP por SMS) para el login de miembros.
 *
 * Reglas: un único reto pendiente por miembro a la vez; código hasheado y con
 * vigencia (config otp.ttl); intentos y reenvíos acotados; el canal de envío es
 * pluggable ({@see SmsSenderFactory}). En desarrollo el código puede devolverse
 * en la respuesta (otp.expose_code) para probar sin SMS real.
 */
class OtpService
{
    public function __construct(private SecurityEventService $security)
    {
    }

    /**
     * Crea un reto y envía el código. Devuelve el reto, el código en claro (sólo
     * para exponerlo en dev) y si el envío fue aceptado por el proveedor.
     *
     * @return array{challenge: MemberAuthChallenge, code: string, sent: bool}
     */
    public function startChallenge(
        Member $member,
        array $context,
        string $purpose = MemberAuthChallenge::PURPOSE_LOGIN,
        ?string $destinationOverride = null,
    ): array {
        // Un solo reto vivo por miembro y propósito: vence los pendientes del
        // MISMO propósito (no interfiere con un eventual reto de login activo).
        MemberAuthChallenge::query()
            ->where('member_id', $member->id)
            ->where('purpose', $purpose)
            ->where('status', MemberAuthChallenge::STATUS_PENDING)
            ->update(['status' => MemberAuthChallenge::STATUS_EXPIRED]);

        // Para cambio de número el OTP va al teléfono NUEVO; en el resto, al del
        // titular ya registrado.
        $phone = $destinationOverride !== null
            ? trim($destinationOverride)
            : $this->resolvePhone($member);
        $code  = $this->generateCode();

        $challenge = MemberAuthChallenge::create([
            'member_id'    => $member->id,
            'purpose'      => $purpose,
            'code_hash'    => Hash::make($code),
            'channel'      => 'sms',
            'destination'  => $phone,
            'device_id'    => $context['device_id'] ?? null,
            'device_name'  => $context['device_name'] ?? null,
            'platform'     => $context['platform'] ?? null,
            'ip_address'   => $context['ip_address'] ?? null,
            'user_agent'   => isset($context['user_agent']) ? mb_substr((string) $context['user_agent'], 0, 500) : null,
            'status'       => MemberAuthChallenge::STATUS_PENDING,
            'last_sent_at' => now(),
            'expires_at'   => now()->addSeconds($this->ttl()),
        ]);

        $sent = $this->dispatch($phone, $code);

        $this->security->record($member, MemberSecurityEvent::TYPE_OTP_SENT, $context, [
            'challenge' => $challenge->uuid,
            'channel'   => 'sms',
            'sent'      => $sent,
            'masked'    => MemberAuthChallenge::maskPhone($phone),
        ]);

        $this->flagSuspicious($member, $context);

        return ['challenge' => $challenge, 'code' => $code, 'sent' => $sent];
    }

    /**
     * Verifica un código. Devuelve el miembro y el reto si todo va bien; lanza
     * {@see OtpException} en cualquier fallo de negocio.
     *
     * @return array{member: Member, challenge: MemberAuthChallenge}
     */
    public function verify(string $challengeUuid, string $code, array $context): array
    {
        $challenge = MemberAuthChallenge::query()
            ->where('uuid', $challengeUuid)
            ->first();

        if (! $challenge) {
            throw new OtpException('No encontramos esta verificación. Solicita un código nuevo.', 404);
        }

        $this->assertCodeAccepted($challenge, $code, $context);

        $member = $challenge->member;
        if ($member) {
            $this->security->record($member, MemberSecurityEvent::TYPE_LOGIN_VERIFIED, $context, [
                'challenge' => $challenge->uuid,
            ]);
        }

        return ['member' => $member, 'challenge' => $challenge];
    }

    /**
     * Verifica el OTP de una ACCIÓN SENSIBLE (eliminar cuenta, desvincular
     * dispositivos, cambio de número…). A diferencia del login, exige que el
     * reto pertenezca al miembro autenticado y tenga el propósito esperado, de
     * modo que un challenge_id de login no pueda reutilizarse para otra acción.
     *
     * @throws OtpException
     */
    public function verifyAction(Member $member, string $purpose, string $challengeUuid, string $code, array $context): MemberAuthChallenge
    {
        $challenge = MemberAuthChallenge::query()
            ->where('uuid', $challengeUuid)
            ->where('member_id', $member->id)
            ->where('purpose', $purpose)
            ->first();

        if (! $challenge) {
            throw new OtpException('No encontramos esta verificación. Solicita un código nuevo.', 404);
        }

        $this->assertCodeAccepted($challenge, $code, $context);

        return $challenge;
    }

    /**
     * Núcleo compartido de validación de un código: estados terminales, vigencia
     * e intentos. Al pasar, marca el reto como verificado/consumido. Lanza
     * {@see OtpException} en cualquier fallo.
     */
    private function assertCodeAccepted(MemberAuthChallenge $challenge, string $code, array $context): void
    {
        $member = $challenge->member;

        if ($challenge->status === MemberAuthChallenge::STATUS_VERIFIED) {
            throw new OtpException('Este código ya fue usado. Solicita uno nuevo.', 409);
        }

        if ($challenge->status === MemberAuthChallenge::STATUS_BLOCKED) {
            throw new OtpException('Verificación bloqueada por demasiados intentos. Solicita un código nuevo.', 423);
        }

        if ($challenge->isExpired()) {
            $challenge->update(['status' => MemberAuthChallenge::STATUS_EXPIRED]);
            throw new OtpException('El código expiró. Solicita uno nuevo.', 410);
        }

        if (! Hash::check($code, $challenge->code_hash)) {
            $challenge->increment('attempts');
            $remaining = max($this->maxAttempts() - $challenge->attempts, 0);

            if ($remaining <= 0) {
                $challenge->update(['status' => MemberAuthChallenge::STATUS_BLOCKED]);
                if ($member) {
                    $this->security->record($member, MemberSecurityEvent::TYPE_OTP_BLOCKED, $context, [
                        'challenge' => $challenge->uuid,
                        'purpose'   => $challenge->purpose,
                    ]);
                }
                throw new OtpException('Demasiados intentos fallidos. Solicita un código nuevo.', 423);
            }

            if ($member) {
                $this->security->record($member, MemberSecurityEvent::TYPE_LOGIN_FAILED, $context, [
                    'challenge' => $challenge->uuid,
                    'purpose'   => $challenge->purpose,
                    'remaining' => $remaining,
                ]);
            }

            throw new OtpException(
                "Código incorrecto. Te quedan {$remaining} intento" . ($remaining === 1 ? '' : 's') . '.',
                422,
                ['remaining' => $remaining],
            );
        }

        $challenge->update([
            'status'      => MemberAuthChallenge::STATUS_VERIFIED,
            'consumed_at' => now(),
        ]);
    }

    /**
     * Reenvía el código de un reto pendiente respetando cooldown y tope.
     *
     * @return array{challenge: MemberAuthChallenge, code: string, sent: bool}
     */
    public function resend(string $challengeUuid, array $context): array
    {
        $challenge = MemberAuthChallenge::query()
            ->where('uuid', $challengeUuid)
            ->first();

        if (! $challenge || ! $challenge->isVerifiable()) {
            throw new OtpException('No hay una verificación activa. Inicia sesión nuevamente.', 410);
        }

        $cooldown = $this->resendCooldown();
        if ($challenge->last_sent_at instanceof Carbon) {
            $elapsed = $challenge->last_sent_at->diffInSeconds(now());
            if ($elapsed < $cooldown) {
                $wait = $cooldown - $elapsed;
                throw new OtpException("Espera {$wait} s para reenviar el código.", 429, ['retry_after' => $wait]);
            }
        }

        if ($challenge->resend_count >= $this->maxResends()) {
            throw new OtpException('Alcanzaste el límite de reenvíos. Inicia sesión nuevamente.', 429);
        }

        $code  = $this->generateCode();
        $phone = $challenge->destination ?: ($challenge->member ? $this->resolvePhone($challenge->member) : null);

        $challenge->update([
            'code_hash'    => Hash::make($code),
            'destination'  => $phone,
            'last_sent_at' => now(),
            'resend_count' => $challenge->resend_count + 1,
            'expires_at'   => now()->addSeconds($this->ttl()),
            'attempts'     => 0,
        ]);

        $sent = $this->dispatch($phone, $code);

        if ($challenge->member) {
            $this->security->record($challenge->member, MemberSecurityEvent::TYPE_OTP_RESENT, $context, [
                'challenge' => $challenge->uuid,
                'sent'      => $sent,
            ]);
        }

        return ['challenge' => $challenge, 'code' => $code, 'sent' => $sent];
    }

    /** ¿Se puede generar OTP para este miembro? (necesita teléfono). */
    public function canChallenge(Member $member): bool
    {
        return $this->resolvePhone($member) !== null;
    }

    public function exposeCode(): bool
    {
        // Defensa: aunque la config lo active, NUNCA se expone el código en
        // producción (solo en desarrollo local/testing).
        return (bool) config('otp.expose_code', false)
            && ! app()->environment('production');
    }

    public function resolvePhone(Member $member): ?string
    {
        $phone = $member->phone ?: $member->user?->phone;
        $phone = $phone ? trim((string) $phone) : null;

        return $phone === '' ? null : $phone;
    }

    // ── Internos ─────────────────────────────────────────────────────────────

    private function dispatch(?string $phone, string $code): bool
    {
        if ($phone === null) {
            return false;
        }

        $brand = config('otp.brand', 'Iron Body');
        $mins  = (int) ceil($this->ttl() / 60);
        $message = "{$brand}: tu código de verificación es {$code}. Vence en {$mins} min. No lo compartas con nadie.";

        return SmsSenderFactory::make()->send($phone, $message);
    }

    private function flagSuspicious(Member $member, array $context): void
    {
        $window = (int) config('otp.suspicious.window', 600);
        $max    = (int) config('otp.suspicious.max_devices', 3);

        $distinctDevices = MemberAuthChallenge::query()
            ->where('member_id', $member->id)
            ->where('created_at', '>=', now()->subSeconds($window))
            ->whereNotNull('device_id')
            ->distinct()
            ->count('device_id');

        if ($distinctDevices > $max) {
            $this->security->record($member, MemberSecurityEvent::TYPE_SUSPICIOUS, $context, [
                'distinct_devices' => $distinctDevices,
                'window_seconds'   => $window,
            ], 'Múltiples dispositivos intentando acceder en poco tiempo.');
        }
    }

    private function generateCode(): string
    {
        $len = max($this->length(), 4);
        $max = (10 ** $len) - 1;

        return str_pad((string) random_int(0, $max), $len, '0', STR_PAD_LEFT);
    }

    private function length(): int
    {
        return (int) config('otp.length', 6);
    }

    private function ttl(): int
    {
        return (int) config('otp.ttl', 300);
    }

    private function maxAttempts(): int
    {
        return (int) config('otp.max_attempts', 5);
    }

    private function maxResends(): int
    {
        return (int) config('otp.max_resends', 3);
    }

    private function resendCooldown(): int
    {
        return (int) config('otp.resend_cooldown', 60);
    }
}
