<?php

namespace App\Services\Voice;

use App\Services\Voice\Dto\OutboundCallRequest;
use App\Services\Voice\Dto\OutboundCallResult;

/**
 * Iniciar UNA llamada saliente (contrato H8, D8-3 y D8-12). Solo la usa el job
 * de la puerta de salientes de la telefonía, con la fila ya sellada.
 *
 * UNA petición, sin reintentos ni redirecciones. Un tiempo agotado, un 5xx o
 * cualquier fallo que no pruebe que no se marcó es `ambiguous`, nunca un
 * «no se marcó». Con las llamadas cerradas en el código
 * (`VoiceCallPolicy::CALLS_ENABLED_IN_CODE = false`) la respuesta es
 * `not_attempted` y no sale nada a la red.
 */
interface VoiceCallStarter
{
    public function startOutbound(OutboundCallRequest $request): OutboundCallResult;
}
