<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Envío real vía Twilio (API REST Messages). Sólo requiere credenciales en
 * config/otp.php (twilio.sid/token/from). Si faltan, no intenta enviar.
 */
class TwilioSmsSender implements SmsSender
{
    public function send(string $to, string $message): bool
    {
        $sid   = (string) config('otp.twilio.sid');
        $token = (string) config('otp.twilio.token');
        $from  = (string) config('otp.twilio.from');
        $base  = rtrim((string) config('otp.twilio.base'), '/');

        if ($sid === '' || $token === '' || $from === '') {
            Log::warning('TwilioSmsSender: credenciales incompletas, no se envía SMS.');
            return false;
        }

        try {
            $response = Http::asForm()
                ->withBasicAuth($sid, $token)
                ->timeout(15)
                ->post("{$base}/2010-04-01/Accounts/{$sid}/Messages.json", [
                    'To'   => $to,
                    'From' => $from,
                    'Body' => $message,
                ]);

            if ($response->successful()) {
                return true;
            }

            Log::warning('TwilioSmsSender: respuesta no exitosa', [
                'status' => $response->status(),
                'body'   => $response->body(),
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
