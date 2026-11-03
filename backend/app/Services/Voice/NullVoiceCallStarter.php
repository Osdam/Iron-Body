<?php

namespace App\Services\Voice;

use App\Services\Voice\Dto\OutboundCallRequest;
use App\Services\Voice\Dto\OutboundCallResult;

/** El iniciador por defecto (contrato H8, D8-3): nunca marca. `not_attempted` (`not_configured`), sin red. */
final class NullVoiceCallStarter implements VoiceCallStarter
{
    public function startOutbound(OutboundCallRequest $request): OutboundCallResult
    {
        return new OutboundCallResult(OutboundCallResult::OUTCOME_NOT_ATTEMPTED, OutboundCallResult::REASON_NOT_CONFIGURED, null, null, null);
    }
}
