<?php

namespace App\Enums;

/**
 * Acciones registradas en electronic_invoice_logs (traza de integración).
 * El payload de cada log SIEMPRE va saneado (sin secretos ni datos sensibles).
 */
enum InvoiceLogAction: string
{
    case ENQUEUE     = 'enqueue';
    case TOKEN       = 'token';
    case EMIT        = 'emit';
    case CALLBACK    = 'callback';
    case RETRY       = 'retry';
    case SYNC        = 'sync';
    case CREDIT_NOTE = 'credit_note';
    case DOWNLOAD    = 'download';

    // Envío PROPIO (SMTP) del comprobante al cliente — fallback al envío nativo
    // de Factus. Sin datos sensibles: solo el correo destino enmascarado.
    case EMAIL_QUEUED = 'email_queued';
    case EMAIL_SENT   = 'email_sent';
    case EMAIL_FAILED = 'email_failed';

    /** @return string[] */
    public static function values(): array
    {
        return array_map(static fn (self $c) => $c->value, self::cases());
    }
}
