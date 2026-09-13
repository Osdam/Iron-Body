<?php

namespace App\Services\Trainer;

use App\Exceptions\OtpException;
use App\Models\Trainer;
use App\Models\TrainerAuthChallenge;
use App\Services\Otp\OtpPolicy;
use App\Services\Sms\SmsSenderFactory;
use App\Services\Sms\TwilioVerifyService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * OTP por SMS para el acceso profesional. REUSA el motor existente: el envío se
 * delega en {@see SmsSenderFactory}/{@see TwilioVerifyService} y todos los topes
 * (TTL, intentos, reenvíos, cooldown) salen de `config/otp.php`. No es un sistema
 * OTP paralelo: solo cambia el sujeto (entrenador) y la tabla de retos.
 *
 * El OTP confirma posesión del teléfono; NO concede el rol. La autorización vive
 * en los permisos del entrenador (ver Trainer::hasPermission()).
 */
class TrainerOtpService
{
    private function usesTwilioVerify(): bool
    {
        return app(TwilioVerifyService::class)->isActive();
    }

    public function resolvePhone(Trainer $trainer): ?string
    {
        $phone = $trainer->phone ? trim((string) $trainer->phone) : null;

        return $phone === '' ? null : $phone;
    }

    /**
     * El código solo puede exponerse con el driver `dev`, jamás en producción ni
     * con proveedor real. Misma defensa en profundidad que el OTP de miembros.
     */
    public function exposeCode(): bool
    {
        return (bool) config('otp.expose_code', false)
            && config('otp.driver') === 'dev'
            && ! app()->environment('production');
    }

    /**
     * Crea un reto y envía el código al teléfono del entrenador. Vence cualquier
     * reto pendiente del mismo propósito (un solo reto vivo a la vez).
     *
     * @return array{challenge: TrainerAuthChallenge, code: string, sent: bool}
     */
    public function startChallenge(
        Trainer $trainer,
        array $context,
        string $purpose = TrainerAuthChallenge::PURPOSE_LOGIN,
    ): array {
        $phone = $this->resolvePhone($trainer);
        $ip = $context['ip_address'] ?? null;
        $policy = $this->policy();

        $policy->event('otp.requested', [
            'scope' => OtpPolicy::SCOPE_TRAINER, 'subject_id' => $trainer->id, 'purpose' => $purpose,
            'phone_hash' => $policy->phoneHash($phone), 'ip_hash' => $policy->ipHash($ip),
        ]);

        if ($vivo = $this->retoReutilizable($trainer, $purpose, $phone)) {
            $policy->event('otp.reused', [
                'scope' => OtpPolicy::SCOPE_TRAINER, 'subject_id' => $trainer->id, 'purpose' => $purpose,
                'phone_hash' => $policy->phoneHash($phone), 'challenge' => $vivo->uuid,
                'decision' => 'reuse_active_verification',
            ]);

            return ['challenge' => $vivo, 'code' => '', 'sent' => true, 'reused' => true];
        }

        $lock = $policy->lock($purpose, $phone);
        $adquirido = false;
        try {
            $lock->block($policy->lockWaitSeconds());
            $adquirido = true;
        } catch (LockTimeoutException) {
            // Otro proceso ya está pidiéndole el código a Twilio.
        }

        try {
            if ($vivo = $this->retoReutilizable($trainer, $purpose, $phone)) {
                return ['challenge' => $vivo, 'code' => '', 'sent' => true, 'reused' => true];
            }

            if (! $adquirido) {
                throw new OtpException(
                    'Ya estamos enviando tu código. Espera unos segundos.',
                    429,
                    ['code' => 'in_progress', 'retry_after' => 10],
                );
            }

            $policy->assertStartAllowed(
                OtpPolicy::SCOPE_TRAINER, $trainer->id, $purpose, $phone, $ip,
                $this->segundosDesdeElUltimoEnvioSinUsar($trainer, $purpose, $phone),
            );

            TrainerAuthChallenge::query()
                ->where('trainer_id', $trainer->id)
                ->where('purpose', $purpose)
                ->where('status', TrainerAuthChallenge::STATUS_PENDING)
                ->update(['status' => TrainerAuthChallenge::STATUS_EXPIRED]);

            $code = $this->generateCode();

            $challenge = TrainerAuthChallenge::create([
                'trainer_id' => $trainer->id,
                'purpose' => $purpose,
                'code_hash' => Hash::make($code),
                'channel' => 'sms',
                'destination' => $phone,
                'device_id' => $context['device_id'] ?? null,
                'device_name' => $context['device_name'] ?? null,
                'platform' => $context['platform'] ?? null,
                'ip_address' => $context['ip_address'] ?? null,
                'user_agent' => isset($context['user_agent']) ? mb_substr((string) $context['user_agent'], 0, 500) : null,
                'status' => TrainerAuthChallenge::STATUS_PENDING,
                'last_sent_at' => now(),
                'expires_at' => now()->addSeconds($this->ttl()),
            ]);

            $sent = $this->enviar($trainer->id, $purpose, $phone, $code, $ip, $challenge->uuid);

            return ['challenge' => $challenge, 'code' => $code, 'sent' => $sent, 'reused' => false];
        } finally {
            if ($adquirido) {
                $lock->release();
            }
        }
    }

    /**
     * Verifica un código de un reto. Devuelve el reto al pasar; lanza
     * {@see OtpException} con mensajes neutrales (no revelan si el entrenador
     * existe) en cualquier fallo.
     */
    public function verify(string $challengeUuid, string $code, string $purpose = TrainerAuthChallenge::PURPOSE_LOGIN): TrainerAuthChallenge
    {
        $challenge = TrainerAuthChallenge::query()
            ->where('uuid', $challengeUuid)
            ->where('purpose', $purpose)
            ->first();

        if (! $challenge) {
            throw new OtpException('El código no es válido o expiró. Solicita uno nuevo.', 422);
        }

        $this->assertCodeAccepted($challenge, $code);

        return $challenge;
    }

    /**
     * Reenvía el código respetando cooldown y tope. Mensajes neutrales.
     *
     * @return array{challenge: TrainerAuthChallenge, code: string, sent: bool}
     */
    public function resend(string $challengeUuid, string $purpose = TrainerAuthChallenge::PURPOSE_LOGIN): array
    {
        $challenge = TrainerAuthChallenge::query()
            ->where('uuid', $challengeUuid)
            ->where('purpose', $purpose)
            ->first();

        if (! $challenge || ! $challenge->isVerifiable()) {
            throw new OtpException('No hay una verificación activa. Inicia el acceso nuevamente.', 410);
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
            throw new OtpException('Alcanzaste el límite de reenvíos. Inicia el acceso nuevamente.', 429);
        }

        $code = $this->generateCode();
        $phone = $challenge->destination;
        $policy = $this->policy();

        // El reenvío también gasta: interruptor, techo de gasto y cupos.
        $policy->assertStartAllowed(
            OtpPolicy::SCOPE_TRAINER, $challenge->trainer_id, $challenge->purpose, $phone,
        );

        $lock = $policy->lock($challenge->purpose, $phone);
        try {
            $lock->block($policy->lockWaitSeconds());
        } catch (LockTimeoutException) {
            throw new OtpException(
                'Ya estamos enviando tu código. Espera unos segundos.',
                429,
                ['code' => 'in_progress', 'retry_after' => 10],
            );
        }

        try {
            $challenge->update([
                'code_hash' => Hash::make($code),
                'last_sent_at' => now(),
                'resend_count' => $challenge->resend_count + 1,
                'expires_at' => now()->addSeconds($this->ttl()),
                'attempts' => 0,
            ]);

            $sent = $this->enviar(
                $challenge->trainer_id, $challenge->purpose, $phone, $code, null, $challenge->uuid,
            );
        } finally {
            $lock->release();
        }

        return ['challenge' => $challenge, 'code' => $code, 'sent' => $sent, 'reused' => false];
    }

    /**
     * Núcleo de validación: estados terminales, vigencia e intentos. Marca el
     * reto verificado al pasar. Lanza {@see OtpException} en cualquier fallo.
     */
    private function assertCodeAccepted(TrainerAuthChallenge $challenge, string $code): void
    {
        if ($challenge->status === TrainerAuthChallenge::STATUS_VERIFIED) {
            throw new OtpException('Este código ya fue usado. Solicita uno nuevo.', 409);
        }

        if ($challenge->status === TrainerAuthChallenge::STATUS_BLOCKED) {
            throw new OtpException('Verificación bloqueada por demasiados intentos. Solicita un código nuevo.', 423);
        }

        if ($challenge->isExpired()) {
            $challenge->update(['status' => TrainerAuthChallenge::STATUS_EXPIRED]);
            throw new OtpException('El código expiró. Solicita uno nuevo.', 410);
        }

        $accepted = $this->usesTwilioVerify()
            ? app(TwilioVerifyService::class)->check($challenge->destination, $code)
            : Hash::check($code, $challenge->code_hash);

        if (! $accepted) {
            $this->policy()->event('otp.invalid', [
                'scope' => OtpPolicy::SCOPE_TRAINER,
                'subject_id' => $challenge->trainer_id,
                'purpose' => $challenge->purpose,
                'challenge' => $challenge->uuid,
                'decision' => 'wrong_code',
            ]);

            $challenge->increment('attempts');
            $remaining = max($this->maxAttempts() - $challenge->attempts, 0);

            if ($remaining <= 0) {
                $challenge->update(['status' => TrainerAuthChallenge::STATUS_BLOCKED]);
                throw new OtpException('Demasiados intentos fallidos. Solicita un código nuevo.', 423);
            }

            throw new OtpException(
                "Código incorrecto. Te quedan {$remaining} intento".($remaining === 1 ? '' : 's').'.',
                422,
                ['remaining' => $remaining],
            );
        }

        $challenge->update([
            'status' => TrainerAuthChallenge::STATUS_VERIFIED,
            'consumed_at' => now(),
        ]);

        $policy = $this->policy();
        $policy->forgetLive(OtpPolicy::SCOPE_TRAINER, $challenge->trainer_id, $challenge->purpose);
        $policy->registerVerification();
        $policy->event('otp.verified', [
            'scope' => OtpPolicy::SCOPE_TRAINER,
            'subject_id' => $challenge->trainer_id,
            'purpose' => $challenge->purpose,
            'challenge' => $challenge->uuid,
            'phone_hash' => $policy->phoneHash($challenge->destination),
            'decision' => 'approved',
        ]);
    }

    // ── Política de coste (la MISMA que la de socios) ─────────────────────────

    private function policy(): OtpPolicy
    {
        return app(OtpPolicy::class);
    }

    /** Reto cuyo SMS salió de verdad y sigue vigente para este entrenador. */
    private function retoReutilizable(Trainer $trainer, string $purpose, ?string $phone): ?TrainerAuthChallenge
    {
        if ($phone === null || $phone === '') {
            return null;
        }

        $uuid = $this->policy()->liveChallengeUuid(OtpPolicy::SCOPE_TRAINER, $trainer->id, $purpose);
        if ($uuid === null) {
            return null;
        }

        $challenge = TrainerAuthChallenge::query()
            ->where('uuid', $uuid)
            ->where('trainer_id', $trainer->id)
            ->where('purpose', $purpose)
            ->where('status', TrainerAuthChallenge::STATUS_PENDING)
            ->first();

        if (! $challenge || $challenge->isExpired()) {
            $this->policy()->forgetLive(OtpPolicy::SCOPE_TRAINER, $trainer->id, $purpose);

            return null;
        }

        return $challenge;
    }

    /** Segundos desde el último envío que no terminó en verificación. */
    private function segundosDesdeElUltimoEnvioSinUsar(Trainer $trainer, string $purpose, ?string $phone): ?int
    {
        $ultimo = TrainerAuthChallenge::query()
            ->where('trainer_id', $trainer->id)
            ->where('purpose', $purpose)
            ->where('channel', 'sms')
            ->orderByDesc('id')
            ->first();

        $mismo = static function (?string $a, ?string $b): bool {
            $limpia = static fn (?string $v) => ltrim(preg_replace('/[^\d]/', '', (string) $v) ?? '', '0');

            return $limpia($a) !== '' && $limpia($a) === $limpia($b);
        };

        if (! $ultimo
            || $ultimo->status === TrainerAuthChallenge::STATUS_VERIFIED
            || ! $ultimo->last_sent_at instanceof Carbon
            || ! $mismo($ultimo->destination, $phone)) {
            return null;
        }

        return (int) abs($ultimo->last_sent_at->diffInSeconds(now()));
    }

    /** Único punto del motor profesional que habla con el proveedor. */
    private function enviar(
        int|string $trainerId,
        string $purpose,
        ?string $phone,
        string $code,
        ?string $ip,
        string $challengeUuid,
    ): bool {
        $policy = $this->policy();

        if ($phone === null || $phone === '') {
            $policy->event('otp.provider_failed', [
                'scope' => OtpPolicy::SCOPE_TRAINER, 'subject_id' => $trainerId,
                'purpose' => $purpose, 'challenge' => $challengeUuid, 'decision' => 'no_phone',
            ]);

            return false;
        }

        $policy->registerProviderCall(OtpPolicy::SCOPE_TRAINER, $trainerId, $phone, $ip);

        $sent = $this->usesTwilioVerify()
            ? app(TwilioVerifyService::class)->start($phone, $policy->phoneHash($phone))
            : $this->dispatch($phone, $code);

        $policy->event($sent ? 'otp.provider_called' : 'otp.provider_failed', [
            'scope' => OtpPolicy::SCOPE_TRAINER,
            'subject_id' => $trainerId,
            'purpose' => $purpose,
            'phone_hash' => $policy->phoneHash($phone),
            'ip_hash' => $policy->ipHash($ip),
            'challenge' => $challengeUuid,
            'decision' => $sent ? 'sent' : 'provider_rejected',
        ]);

        if ($sent) {
            $policy->markLive(OtpPolicy::SCOPE_TRAINER, $trainerId, $purpose, $challengeUuid, $this->ttl());
        }

        return $sent;
    }

    // ── Internos (reusan config/otp.php) ─────────────────────────────────────

    private function dispatch(?string $phone, string $code): bool
    {
        if ($phone === null) {
            return false;
        }

        $brand = config('otp.brand', 'Iron Body');
        $mins = (int) ceil($this->ttl() / 60);
        $message = "{$brand}: tu código de acceso profesional es {$code}. Vence en {$mins} min. No lo compartas.";

        return SmsSenderFactory::make()->send($phone, $message);
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
