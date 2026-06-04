<?php

namespace App\Services\Sms;

/**
 * Resuelve el {@see SmsSender} concreto según config/otp.php → driver. Driver
 * desconocido cae a `dev` (nunca envía SMS reales por error de config).
 */
class SmsSenderFactory
{
    public static function make(?string $driver = null): SmsSender
    {
        $driver ??= (string) config('otp.driver', 'dev');

        // Producción: el driver `dev` (no envía SMS reales) NO se permite. Falla
        // cerrado para forzar una configuración segura (twilio/labsmobile) y que
        // nunca quede un OTP "de mentira" en producción.
        if (app()->environment('production')
            && ! in_array($driver, ['twilio', 'labsmobile'], true)) {
            throw new \RuntimeException(
                "OTP_DRIVER='{$driver}' no está permitido en producción. "
                .'Configura OTP_DRIVER=twilio (o labsmobile) con sus credenciales.'
            );
        }

        return match ($driver) {
            'twilio'     => new TwilioSmsSender(),
            'labsmobile' => new LabsMobileSmsSender(),
            default      => new DevSmsSender(),
        };
    }
}
