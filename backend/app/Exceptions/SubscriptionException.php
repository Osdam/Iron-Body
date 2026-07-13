<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Error de negocio del módulo de pago automático (suscripciones recurrentes).
 * Lleva un `code` estable para que la capa API/tests distingan el motivo sin
 * depender del texto, y el HTTP a devolver. NUNCA transporta datos sensibles.
 */
class SubscriptionException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'subscription_error',
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    /** El módulo de pago automático está apagado (WOMPI_RECURRING_ENABLED=false). */
    public static function recurringDisabled(): self
    {
        return new self(
            'El pago automático no está habilitado.',
            'recurring_disabled',
            503,
        );
    }

    /** El método solicitado no soporta cobro automático (PSE/DaviPlata/Bancolombia). */
    public static function unsupportedMethod(string $method): self
    {
        return new self(
            'Este método de pago no admite pago automático. Usa una tarjeta.',
            'unsupported_autopay_method',
            422,
        );
    }

    /** Faltan datos o el plan no es válido/activo. */
    public static function invalidPlan(): self
    {
        return new self('El plan seleccionado no es válido para pago automático.', 'invalid_plan', 422);
    }

    /** La fuente de pago no quedó disponible (declinada/pendiente 3DS/error). */
    public static function paymentSourceUnavailable(string $reason = ''): self
    {
        return new self(
            'No pudimos validar el método de pago para cobros automáticos.'.($reason !== '' ? ' '.$reason : ''),
            'payment_source_unavailable',
            422,
        );
    }
}
