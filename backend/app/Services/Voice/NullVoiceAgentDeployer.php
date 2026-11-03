<?php

namespace App\Services\Voice;

use App\Services\Voice\Dto\VoiceAgentObservation;
use App\Services\Voice\Dto\VoiceAgentPayload;
use App\Services\Voice\Dto\VoiceDeployResult;

/** El desplegador por defecto (contrato H8, D8-3): no habla con nadie. Todo es `not_configured`, sin red. */
final class NullVoiceAgentDeployer implements VoiceAgentDeployer
{
    public function createAgent(VoiceAgentPayload $payload): VoiceDeployResult
    {
        return new VoiceDeployResult(VoiceDeployResult::OUTCOME_NOT_CONFIGURED, null, [], null, null);
    }

    public function describeAgent(string $agentId): VoiceAgentObservation
    {
        return new VoiceAgentObservation(VoiceAgentObservation::OUTCOME_NOT_CONFIGURED, null, null, null, null, null, null);
    }

    public function assignNumber(string $providerNumberId, ?string $agentId): VoiceDeployResult
    {
        return new VoiceDeployResult(VoiceDeployResult::OUTCOME_NOT_CONFIGURED, null, [], null, null);
    }
}
