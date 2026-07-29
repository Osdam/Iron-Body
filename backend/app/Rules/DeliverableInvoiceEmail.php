<?php

namespace App\Rules;

use App\Services\Billing\InvoiceEmail;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * El correo debe poder RECIBIR la factura, no sólo estar bien escrito.
 *
 * `email` de Laravel acepta `socio-1033751057@ironbody.local`, que este sistema
 * genera para socios sin correo real. Enviar ahí un comprobante fiscal equivale
 * a no enviarlo, pero el pago quedaría marcado como facturado y notificado.
 *
 * Se aplica en TODOS los flujos que aceptan un correo de facturación (app,
 * caja, venta de productos, pagos manuales) para que la regla sea una sola.
 */
class DeliverableInvoiceEmail implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Vacío lo resuelve `nullable`; aquí sólo importa el contenido.
        if (blank($value)) {
            return;
        }

        if (! InvoiceEmail::esEntregable((string) $value)) {
            $fail(InvoiceEmail::mensajeDeRechazo());
        }
    }
}
