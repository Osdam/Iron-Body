<?php

namespace App\Services\Voice;

use App\Services\Voice\Dto\VoiceWebhookVerdict;

/** El verificador por defecto (contrato H8, D8-3): sin secreto no hay firma que valga. Nunca acepta. */
final class NullVoiceEventVerifier implements VoiceEventVerifier
{
    public function verifyPostCall(string $rawBody, ?string $signatureHeader, int $now): VoiceWebhookVerdict
    {
        return new VoiceWebhookVerdict(
            VoiceWebhookVerdict::OUTCOME_NOT_CONFIGURED, VoiceWebhookVerdict::REASON_NOT_CONFIGURED,
            null, null, null, null, null, null, null, null, null, null, null, null, [],
        );
    }
}
