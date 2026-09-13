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

    /**
     * Dispara el envío del código por SMS. Devuelve true si Twilio aceptó.
     *
     * [$rateLimitKey] alimenta los Service Rate Limits del propio Twilio, que
     * son una segunda barrera independiente de la nuestra. Se envía SIEMPRE un
     * valor derivado (hash), nunca el teléfono en claro: ese valor queda
     * almacenado en Twilio y no tiene por qué ser un dato personal.
     *
     * Sólo viaja si `otp.twilio.rate_limit_unique_name` está configurado, porque
     * referenciar un límite que no existe en el servicio hace que Twilio
     * rechace la petición entera. Se activa después de crearlo allí.
     */
    public function start(?string $phone, ?string $rateLimitKey = null): bool
    {
        $to = $this->toE164($phone);
        if ($to === null) {
            return false;
        }

        $payload = ['To' => $to, 'Channel' => 'sms'];

        $limite = (string) config('otp.twilio.rate_limit_unique_name', '');
        if ($limite !== '' && $rateLimitKey !== null && $rateLimitKey !== '') {
            $payload['RateLimits'] = json_encode([$limite => $rateLimitKey]);
        }

        try {
            $resp = $this->postVerification($payload);

            // Red de seguridad: la llave de rate limit depende de que exista en
            // el servicio de Twilio, que es configuración REMOTA. Si alguien la
            // borra allí, Twilio devuelve 400 y se caería el login de todo el
            // mundo por algo que sólo era un refuerzo. Ante ese 400 concreto se
            // reintenta UNA vez sin la llave: el 400 significa que no se envió
            // nada y no se facturó nada, así que no duplica ningún SMS.
            if (! $resp->successful() && $resp->status() === 400 && isset($payload['RateLimits'])) {
                Log::warning('TwilioVerify: rate limit remoto rechazado, se reintenta sin él', [
                    'twilio_code' => $resp->json('code'),
                ]);
                unset($payload['RateLimits']);
                $resp = $this->postVerification($payload);
            }

            if (! $resp->successful()) {
                Log::warning('TwilioVerify: start no exitoso', [
                    'status' => $resp->status(),
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

    private function postVerification(array $payload): \Illuminate\Http\Client\Response
    {
        return Http::asForm()
            ->withBasicAuth($this->sid(), $this->token())
            ->timeout(15)
            ->post($this->endpoint('Verifications'), $payload);
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
        $svc = (string) config('otp.twilio.verify_service_sid');

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
