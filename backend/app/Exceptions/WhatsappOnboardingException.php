<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Algo impidió completar la conexión de WhatsApp Business desde el CRM.
 *
 * Lleva un código estable además del mensaje. El mensaje es para la persona que
 * está mirando la pantalla; el código es para el frontend, que decide con él si
 * ofrece reintentar, mandar a revisar la configuración de Meta o simplemente
 * enseñar el error. Un `502 "algo falló"` obliga a abrir los logs del servidor
 * para saber cuál de las dos cosas pasó, y quien conecta el canal no tiene
 * acceso a esos logs.
 */
class WhatsappOnboardingException extends RuntimeException
{
    public function __construct(
        string $message,
        /** Se llama `errorCode` y no `code` porque Exception ya usa ese nombre. */
        public readonly string $errorCode = 'whatsapp_onboarding_failed',
        public readonly int $status = 422,
        /** Detalle saneado de Meta (nunca credenciales) para el diagnóstico. */
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public static function notConfigured(): self
    {
        return new self(
            'La conexión con Meta no está configurada en el servidor. Falta META_EMBEDDED_SIGNUP_APP_ID, META_EMBEDDED_SIGNUP_CONFIG_ID o META_EMBEDDED_SIGNUP_APP_SECRET.',
            'meta_app_not_configured',
            503,
        );
    }

    public static function invalidState(): self
    {
        return new self(
            'La sesión de conexión caducó o no corresponde a este usuario. Vuelve a pulsar "Conectar".',
            'invalid_signup_state',
            422,
        );
    }

    public static function exchangeFailed(string $detail, array $context = []): self
    {
        return new self(
            'Meta rechazó el código de autorización: '.$detail,
            'code_exchange_failed',
            502,
            $context,
        );
    }

    public static function noConnection(): self
    {
        return new self(
            'No hay ninguna cuenta de WhatsApp Business conectada.',
            'whatsapp_not_connected',
            404,
        );
    }
}
