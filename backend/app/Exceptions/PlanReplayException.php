<?php

namespace App\Exceptions;

use App\Models\ReceivablePayment;
use RuntimeException;

/**
 * La misma venta a plazos llegó dos veces.
 *
 * No es un error de nadie: es el doble clic, el reintento de red o la pestaña
 * duplicada. Se lanza DENTRO de la transacción, y para eso existe: al salir de
 * ella deshace el `Payment` y la cuenta que esta segunda petición había creado,
 * y deja en pie los de la primera. Sin ella, el índice único de
 * `client_request_id` evitaría el abono repetido pero no la deuda repetida, que
 * es la mitad cara del problema.
 *
 * Lleva dentro el abono original porque quien la captura tiene que responder lo
 * mismo que respondió la primera vez, no un error.
 */
class PlanReplayException extends RuntimeException
{
    public function __construct(public readonly ReceivablePayment $original)
    {
        parent::__construct('La venta a plazos ya se había registrado.');
    }
}
