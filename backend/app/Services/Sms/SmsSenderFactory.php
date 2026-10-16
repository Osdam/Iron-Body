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
        return self::build($driver, challengeBoundCode: false);
    }

    /**
     * Emisor de SMS normal para un código LIGADO A SU RETO: el del vínculo de la
     * ficha de socio con el personal. Nunca Twilio Verify, ni como reserva: un
     * código de Verify vale para cualquier reto abierto para ese teléfono.
     *
     * Con el driver `twilio` es la API de Messages (exige TWILIO_FROM) y no pide
     * OTP_ALLOW_LEGACY_SMS_SENDER: lo habilita encender el vínculo. Que esté
     * listo lo dice {@see SmsSender::isConfigured()}.
     */
    public static function forChallengeBoundCode(?string $driver = null): SmsSender
    {
        return self::build($driver, challengeBoundCode: true);
    }

    private static function build(?string $driver, bool $challengeBoundCode): SmsSender
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
            'twilio' => new TwilioSmsSender($challengeBoundCode),
            'labsmobile' => new LabsMobileSmsSender,
            default => new DevSmsSender,
        };
    }
}
