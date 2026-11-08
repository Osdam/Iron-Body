<?php

namespace Tests\Feature\UltronStudio;

use App\Services\Voice\Dto\VoiceAgentObservation;
use App\Services\Voice\Dto\VoiceAgentPayload;
use App\Services\Voice\Dto\VoiceDeployResult;
use App\Services\Voice\VoiceAgentDeployer;
use Closure;

/**
 * Un desplegador de agentes FALSO para las pruebas del gobierno de la voz (S8-b):
 * implementa la interfaz de D8-3 sin red y apunta cada llamada en {@see $calls}.
 * Por defecto todo sale bien: crea un agente por carga (ids `agent_h8s_0001`…),
 * lo describe con LA MISMA proyección que la carga (sin deriva) y asigna el
 * número. Cada paso se puede torcer por separado.
 */
final class FakeVoiceDeployer implements VoiceAgentDeployer
{
    /** @var list<array{0:string,1?:string|null,2?:string|null}> */
    public array $calls = [];

    /** `created`, o el resultado con el que falla la creación. */
    public string $createOutcome = VoiceDeployResult::OUTCOME_CREATED;

    /** `observed`, `unreachable` o `drift` (observa una proyección distinta). */
    public string $describeOutcome = 'observed';

    /** `assigned` o el resultado con el que falla asignar un agente (dejar sin agente siempre sale). */
    public string $assignOutcome = VoiceDeployResult::OUTCOME_ASSIGNED;

    /** Agentes que, a partir de ahora, el proveedor enseña cambiados (deriva). @var list<string> */
    public array $drifted = [];

    /** Se llama al asignar, antes de responder (para colar una carrera). */
    public ?Closure $onAssign = null;

    /** @var array<string, array<string, mixed>> agentId → proyección creada */
    private array $projections = [];

    private int $n = 0;

    public function createAgent(VoiceAgentPayload $payload): VoiceDeployResult
    {
        $this->calls[] = ['create', $payload->payloadHash];
        if ($this->createOutcome !== VoiceDeployResult::OUTCOME_CREATED) {
            return match ($this->createOutcome) {
                VoiceDeployResult::OUTCOME_NOT_CONFIGURED => new VoiceDeployResult(VoiceDeployResult::OUTCOME_NOT_CONFIGURED, null, [], null, null),
                VoiceDeployResult::OUTCOME_AMBIGUOUS => new VoiceDeployResult(VoiceDeployResult::OUTCOME_AMBIGUOUS, null, [], null, VoiceDeployResult::REASON_AGENT_STEP),
                default => new VoiceDeployResult($this->createOutcome, null, [], 400, VoiceDeployResult::REASON_AGENT_STEP),
            };
        }
        $id = 'agent_h8s_'.str_pad((string) ++$this->n, 4, '0', STR_PAD_LEFT);
        $this->projections[$id] = $payload->projection();

        return new VoiceDeployResult(VoiceDeployResult::OUTCOME_CREATED, $id, [], 200, null);
    }

    public function describeAgent(string $agentId): VoiceAgentObservation
    {
        $this->calls[] = ['describe', $agentId];
        if ($this->describeOutcome === 'unreachable') {
            return new VoiceAgentObservation(VoiceAgentObservation::OUTCOME_UNREACHABLE, $agentId, null, null, null, null, null);
        }
        $proyeccion = $this->projections[$agentId] ?? null;
        if ($proyeccion === null) {
            return new VoiceAgentObservation(VoiceAgentObservation::OUTCOME_NOT_FOUND, $agentId, null, null, null, 404, null);
        }
        if ($this->describeOutcome === 'drift' || in_array($agentId, $this->drifted, true)) {
            // Lo que haría un cambio en el panel: un campo controlado distinto.
            $proyeccion['panel_change'] = 'cambiado en el panel';
        }

        return new VoiceAgentObservation(VoiceAgentObservation::OUTCOME_OBSERVED, $agentId, 'ver_h8s_1', 'branch_h8s_1', $proyeccion, 200, null);
    }

    public function assignNumber(string $providerNumberId, ?string $agentId): VoiceDeployResult
    {
        $this->calls[] = ['assign', $providerNumberId, $agentId];
        if ($this->onAssign !== null) {
            ($this->onAssign)($providerNumberId, $agentId);
        }
        if ($agentId !== null && $this->assignOutcome !== VoiceDeployResult::OUTCOME_ASSIGNED) {
            return match ($this->assignOutcome) {
                VoiceDeployResult::OUTCOME_NOT_ATTEMPTED => new VoiceDeployResult(VoiceDeployResult::OUTCOME_NOT_ATTEMPTED, null, [], null, VoiceDeployResult::REASON_CALLS_DISABLED_IN_CODE),
                VoiceDeployResult::OUTCOME_AMBIGUOUS => new VoiceDeployResult(VoiceDeployResult::OUTCOME_AMBIGUOUS, null, [], null, null),
                default => new VoiceDeployResult($this->assignOutcome, null, [], 400, null),
            };
        }

        return new VoiceDeployResult(VoiceDeployResult::OUTCOME_ASSIGNED, $agentId, [], 200, null);
    }

    /** @return list<array{0:string,1?:string|null,2?:string|null}> las llamadas de un tipo */
    public function callsOf(string $kind): array
    {
        return array_values(array_filter($this->calls, static fn (array $c): bool => $c[0] === $kind));
    }
}
