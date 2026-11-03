<?php

namespace App\Services\Voice;

use App\Services\Voice\Dto\VoiceLabSession;

/** El emisor por defecto (contrato H8, D8-3): no hay laboratorio hablado sin proveedor. `not_configured`, sin red. */
final class NullVoiceLabSessionIssuer implements VoiceLabSessionIssuer
{
    public function signedSessionUrl(string $agentId): VoiceLabSession
    {
        return new VoiceLabSession(VoiceLabSession::OUTCOME_NOT_CONFIGURED, null, null, null);
    }
}
