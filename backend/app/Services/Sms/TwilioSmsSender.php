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
 */
class TwilioSmsSender implements SmsSender
{
    public function send(string $to, string $message): bool
    {
        if (! (bool) config('otp.legacy_sms_sender_enabled', false)) {
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

        try {
            $response = Http::asForm()
                ->withBasicAuth($sid, $token)
                ->timeout(15)
                ->post("{$base}/2010-04-01/Accounts/{$sid}/Messages.json", [
                    'To' => $to,
                    'From' => $from,
                    'Body' => $message,
                ]);

            if ($response->successful()) {
                return true;
            }

            Log::warning('TwilioSmsSender: respuesta no exitosa', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::warning('TwilioSmsSender: excepción al enviar', ['error' => $e->getMessage()]);

            return false;
        }
    }

    public function name(): string
    {
        return 'twilio';
    }
}
