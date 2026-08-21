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

    public static function reviewModeDisabled(): self
    {
        return new self(
            'El modo de demostración para la revisión de Meta no está habilitado en el servidor.',
            'review_mode_disabled',
            403,
        );
    }

    /**
     * El onboarding devolvió un número que no se puede conectar desde el CRM.
     *
     * Es el número que el personal usa en la app WhatsApp Business. Registrarlo
     * en Cloud API se lo quita y pierden WhatsApp Web; ya pasó una vez y hubo
     * que deshacerlo. Antes que guardar eso, se falla.
     */
    public static function protectedNumber(string $display): self
    {
        return new self(
            'El número '.$display.' está protegido y no puede conectarse desde el CRM. '
            .'Es la línea que el equipo usa en la aplicación WhatsApp Business. '
            .'Vuelve a intentarlo eligiendo un número de prueba.',
            'protected_number',
            422,
        );
    }

    /**
     * Ese WABA + número ya existe con OTRO propósito.
     *
     * Se rechaza en vez de reescribirlo. Si una demostración pudiera adoptar el
     * par que opera el canal, la conexión productiva perderia sus credenciales
     * sin que nadie hubiera pedido desconectarla.
     */
    public static function purposeConflict(string $existing, string $requested): self
    {
        return new self(
            'Esa cuenta y ese número ya están registrados como conexión de '
            .($existing === 'review' ? 'demostración' : 'producción')
            .', y no pueden reutilizarse como conexión de '
            .($requested === 'review' ? 'demostración' : 'producción')
            .'. Desconéctala primero, o usa una cuenta de prueba distinta.',
            'purpose_conflict',
            409,
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
