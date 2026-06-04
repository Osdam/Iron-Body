<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Twilio Verify (Bloque 7 / integración real). Twilio genera, envía y valida el
 * código por su cuenta (no necesita un número `from`). El backend solo orquesta:
 *  - start()  → dispara el envío del SMS (Verifications).
 *  - check()  → valida el código que ingresó el usuario (VerificationCheck).
 *
 * Seguridad: el código NUNCA se loguea ni se devuelve. Solo se registran estados
 * y códigos de error de Twilio. Si la cuenta es trial y el número no está
 * verificado, Twilio responde con error y se trata como envío/código inválido
 * (degradación segura, sin crash).
 */
class TwilioVerifyService
{
    /** ¿Está activo el modo Verify? (driver twilio + credenciales + service sid). */
    public function isActive(): bool
    {
        return config('otp.driver') === 'twilio'
            && filled(config('otp.twilio.sid'))
            && filled(config('otp.twilio.token'))
            && filled(config('otp.twilio.verify_service_sid'));
    }

    /** Dispara el envío del código por SMS. Devuelve true si Twilio aceptó. */
    public function start(?string $phone): bool
    {
        $to = $this->toE164($phone);
        if ($to === null) {
            return false;
        }

        try {
            $resp = Http::asForm()
                ->withBasicAuth($this->sid(), $this->token())
                ->timeout(15)
                ->post($this->endpoint('Verifications'), ['To' => $to, 'Channel' => 'sms']);

            if (! $resp->successful()) {
                Log::warning('TwilioVerify: start no exitoso', [
                    'status'      => $resp->status(),
                    'twilio_code' => $resp->json('code'),
                ]);
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('TwilioVerify: excepción en start', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /** Valida el código contra Twilio. true solo si Twilio responde `approved`. */
    public function check(?string $phone, string $code): bool
    {
        $to = $this->toE164($phone);
        if ($to === null) {
            return false;
        }

        try {
            $resp = Http::asForm()
                ->withBasicAuth($this->sid(), $this->token())
                ->timeout(15)
                ->post($this->endpoint('VerificationCheck'), ['To' => $to, 'Code' => $code]);

            // 404 = verificación inexistente/vencida → código inválido (no crash).
            if (! $resp->successful()) {
                return false;
            }

            return $resp->json('status') === 'approved';
        } catch (\Throwable $e) {
            Log::warning('TwilioVerify: excepción en check', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /** Normaliza a E.164 anteponiendo el prefijo de país por defecto si falta. */
    public function toE164(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }
        $p = preg_replace('/[^\d+]/', '', $phone) ?? '';
        if ($p === '') {
            return null;
        }
        if (str_starts_with($p, '+')) {
            return $p;
        }
        $p = ltrim($p, '0');
        $cc = (string) config('otp.default_country_code', '57');
        if ($cc !== '' && strlen($p) > 10 && str_starts_with($p, $cc)) {
            return '+'.$p;
        }
        return '+'.$cc.$p;
    }

    private function endpoint(string $action): string
    {
        $base = rtrim((string) config('otp.twilio.verify_base', 'https://verify.twilio.com'), '/');
        $svc  = (string) config('otp.twilio.verify_service_sid');
        return "{$base}/v2/Services/{$svc}/{$action}";
    }

    private function sid(): string
    {
        return (string) config('otp.twilio.sid');
    }

    private function token(): string
    {
        return (string) config('otp.twilio.token');
    }
}
