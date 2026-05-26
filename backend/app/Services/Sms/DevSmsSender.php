<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;

/**
 * Driver de desarrollo: NO envía SMS reales. Registra el mensaje en el log para
 * poder probar el flujo 2FA de punta a punta sin proveedor. El código también
 * puede devolverse en la respuesta de la API si `otp.expose_code` está activo.
 */
class DevSmsSender implements SmsSender
{
    public function send(string $to, string $message): bool
    {
        Log::info('OTP SMS (driver dev) — no se envía SMS real', [
            'to'      => $to,
            'message' => $message,
        ]);

        return true;
    }

    public function name(): string
    {
        return 'dev';
    }
}
