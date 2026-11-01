<?php

namespace App\Services\Voice;

use InvalidArgumentException;

/**
 * El resultado de comprobar la clave y el agente (contrato H6 §I6.1). Solo
 * lleva la clase del resultado, el estado HTTP, si el agente habla en μ-law
 * 8000 (null si la respuesta no lo dice) y la latencia: NUNCA la clave, el
 * cuerpo de la respuesta, una cabecera ni el mensaje de una excepción.
 *
 * El constructor rechaza un `outcome` fuera de §I2, una latencia negativa,
 * un estado HTTP imposible y un dato de audio en un resultado que no es
 * `credentials_ok` (sin respuesta buena no hay nada que leer).
 */
final class VoiceCheckResult
{
    public const OUTCOME_CREDENTIALS_OK = 'credentials_ok';

    public const OUTCOME_INVALID_CREDENTIALS = 'invalid_credentials';

    public const OUTCOME_AGENT_NOT_FOUND = 'agent_not_found';

    public const OUTCOME_NOT_CONFIGURED = 'not_configured';

    public const OUTCOME_UNREACHABLE = 'unreachable';

    public const OUTCOME_ERROR = 'error';

    /** Conjunto cerrado de §I2, en su orden. */
    public const OUTCOMES = [
        self::OUTCOME_CREDENTIALS_OK,
        self::OUTCOME_INVALID_CREDENTIALS,
        self::OUTCOME_AGENT_NOT_FOUND,
        self::OUTCOME_NOT_CONFIGURED,
        self::OUTCOME_UNREACHABLE,
        self::OUTCOME_ERROR,
    ];

    public function __construct(
        public readonly string $outcome,              // credentials_ok | invalid_credentials | agent_not_found | not_configured | unreachable | error
        public readonly ?int $httpStatus,
        public readonly ?bool $agentAudioUlaw8000,    // null si la respuesta no lo dice
        public readonly int $latencyMs,
    ) {
        if (! in_array($outcome, self::OUTCOMES, true)) {
            throw new InvalidArgumentException('Resultado de verificación de voz desconocido.');
        }
        if ($httpStatus !== null && ($httpStatus < 100 || $httpStatus > 599)) {
            throw new InvalidArgumentException('Estado HTTP fuera de rango.');
        }
        if ($latencyMs < 0) {
            throw new InvalidArgumentException('La latencia no puede ser negativa.');
        }
        if ($agentAudioUlaw8000 !== null && $outcome !== self::OUTCOME_CREDENTIALS_OK) {
            throw new InvalidArgumentException('El formato de audio del agente solo se conoce con credentials_ok.');
        }
    }

    /** @return array{outcome:string, httpStatus:?int, agentAudioUlaw8000:?bool, latencyMs:int} */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome,
            'httpStatus' => $this->httpStatus,
            'agentAudioUlaw8000' => $this->agentAudioUlaw8000,
            'latencyMs' => $this->latencyMs,
        ];
    }
}
