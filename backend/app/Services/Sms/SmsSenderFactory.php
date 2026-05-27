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

        return match ($driver) {
            'twilio'     => new TwilioSmsSender(),
            'labsmobile' => new LabsMobileSmsSender(),
            default      => new DevSmsSender(),
        };
    }
}
