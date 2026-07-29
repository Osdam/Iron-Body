<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Se intentó eliminar físicamente un Payment que ya tiene comprobante
 * electrónico asociado.
 *
 * Un comprobante debe poder explicarse siempre contra su origen: borrar el pago
 * deja la factura huérfana y la conciliación deja de ser verificable. La vía
 * correcta para revertir una operación facturada es la nota crédito.
 */
class PaymentHasInvoiceException extends RuntimeException {}
