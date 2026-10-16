<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Envío real vía Twilio (API REST Messages). Sólo requiere credenciales en
 * config/otp.php (twilio.sid/token/from). Si faltan, no intenta enviar.
 *
 * CERRADO POR DEFECTO. La auditoría de costes del 12/09/2026 comprobó que este
 * camino no se usa: `Messages.json` devolvió 0 mensajes en 30 días y el 100 % del
 * gasto viene de Twilio Verify. Quedaba vivo sólo porque `TWILIO_FROM` está
 * vacío, que es una protección por accidente: el día que alguien rellene ese
 * número, Ironbody tendría dos caminos facturables sin que nadie lo decidiera.
 *
 * Hoy hace falta decirlo a propósito con OTP_ALLOW_LEGACY_SMS_SENDER=true.
 *
 * Única excepción, también a propósito: el código LIGADO A SU RETO (vincular la
 * ficha de socio desde el CRM, {@see SmsSenderFactory::forChallengeBoundCode()}).
 * Ese código no puede ir por Twilio Verify —Verify valida teléfono + código y
 * no el reto, así que serviría para un login del socio—, y el camino solo
 * existe si el propietario enciende el vínculo (WORKFORCE_MEMBER_LINK_ENABLED).
 * Esa decisión es la que habilita este emisor para ese código, y SOLO para él:
 * el login y el acceso profesional siguen necesitando la bandera heredada.
 */
class TwilioSmsSender implements SmsSender
{
    /**
     * @param  bool  $challengeBoundCode  true solo para el código ligado a su reto
     *                                    (no exige OTP_ALLOW_LEGACY_SMS_SENDER).
     */
    public function __construct(private readonly bool $challengeBoundCode = false) {}

    public function send(string $to, string $message): bool
    {
        if (! $this->enabled()) {
            Log::warning('TwilioSmsSender: emisor heredado deshabilitado, no se envía SMS.', [
                'hint' => 'El único camino facturable es Twilio Verify. Actívalo a propósito si de verdad hace falta.',
            ]);

            return false;
        }

        $sid = (string) config('otp.twilio.sid');
        $token = (string) config('otp.twilio.token');
        $from = (string) config('otp.twilio.from');
        $base = rtrim((string) config('otp.twilio.base'), '/');

        if ($sid === '' || $token === '' || $from === '') {
            Log::warning('TwilioSmsSender: credenciales incompletas, no se envía SMS.');

            return false;
        }

        // La API de Messages exige E.164; la ficha guarda el celular de 10
        // dígitos. La misma normalización que usa Verify.
        $e164 = app(TwilioVerifyService::class)->toE164($to);
        if ($e164 === null) {
            Log::warning('TwilioSmsSender: destino sin dígitos, no se envía SMS.');

            return false;
        }

        try {
            $response = Http::asForm()
                ->withBasicAuth($sid, $token)
                ->timeout(15)
                ->post("{$base}/2010-04-01/Accounts/{$sid}/Messages.json", [
                    'To' => $e164,
                    'From' => $from,
                    'Body' => $message,
                ]);

            if ($response->successful()) {
                return true;
            }

            // Sin el cuerpo: el error de Twilio repite el número de destino.
            Log::warning('TwilioSmsSender: respuesta no exitosa', [
                'status' => $response->status(),
                'twilio_code' => $response->json('code'),
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::warning('TwilioSmsSender: excepción al enviar', ['error' => $e->getMessage()]);

            return false;
        }
    }

    public function isConfigured(): bool
    {
        return $this->enabled()
            && (string) config('otp.twilio.sid') !== ''
            && (string) config('otp.twilio.token') !== ''
            && (string) config('otp.twilio.from') !== '';
    }

    public function name(): string
    {
        return 'twilio';
    }

    private function enabled(): bool
    {
        return $this->challengeBoundCode || (bool) config('otp.legacy_sms_sender_enabled', false);
    }
}
