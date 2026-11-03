<?php

namespace App\Services\Voice\Dto;

use InvalidArgumentException;
use SensitiveParameterValue;

/**
 * Lo que se observa al LEER un agente (contrato H8, D8-3, D8-7 paso 3 y D8-21):
 * la proyección de lo que controla el compilador, con la misma forma que la de
 * {@see VoiceAgentPayload::projection()}, para detectar deriva comparando
 * `projectionHash` con el de la carga compilada.
 *
 *  - `observed`: el agente existe y se pudo leer; lleva la proyección y su
 *    huella, y la versión y la rama que dice el proveedor.
 *  - `not_found` (404), `auth_failed` (401/403), `unreachable` (sin conexión o
 *    tiempo agotado), `error` (otro estado, un cuerpo que no se puede leer o
 *    más herramientas de las que admite el catálogo).
 *  - `not_configured`: sin la bandera o sin todas las piezas; SIN RED.
 *
 * La proyección lleva en claro el prompt y los destinos de transferencia: va
 * envuelta en `SensitiveParameterValue` y {@see toArray()} no la incluye.
 */
final class VoiceAgentObservation
{
    public const OUTCOME_OBSERVED = 'observed';

    public const OUTCOME_NOT_FOUND = 'not_found';

    public const OUTCOME_AUTH_FAILED = 'auth_failed';

    public const OUTCOME_UNREACHABLE = 'unreachable';

    public const OUTCOME_ERROR = 'error';

    public const OUTCOME_NOT_CONFIGURED = 'not_configured';

    public const OUTCOMES = [
        self::OUTCOME_OBSERVED, self::OUTCOME_NOT_FOUND, self::OUTCOME_AUTH_FAILED, self::OUTCOME_UNREACHABLE,
        self::OUTCOME_ERROR, self::OUTCOME_NOT_CONFIGURED,
    ];

    public const REASON_INVALID_REQUEST = 'invalid_request';

    public const REASON_INVALID_RESPONSE = 'invalid_response';

    /** El agente apunta a más herramientas de las que admite el catálogo: deriva por sí misma, sin leerlas. */
    public const REASON_TOO_MANY_TOOLS = 'too_many_tools';

    /** Falló la lectura de una de sus herramientas. */
    public const REASON_TOOL_STEP = 'tool_step';

    public const REASONS = [self::REASON_INVALID_REQUEST, self::REASON_INVALID_RESPONSE, self::REASON_TOO_MANY_TOOLS, self::REASON_TOOL_STEP];

    public readonly ?string $projectionHash;

    /** @var SensitiveParameterValue<array<string, mixed>|null> */
    private readonly SensitiveParameterValue $projection;

    /**
     * @param  array<string, mixed>|null  $projection
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        public readonly string $outcome,
        public readonly ?string $agentId,
        public readonly ?string $versionId,
        public readonly ?string $branchId,
        #[\SensitiveParameter] ?array $projection,
        public readonly ?int $httpStatus,
        public readonly ?string $reason,
    ) {
        if (! in_array($outcome, self::OUTCOMES, true)) {
            throw new InvalidArgumentException('Resultado de observación desconocido.');
        }
        if ($reason !== null && ! in_array($reason, self::REASONS, true)) {
            throw new InvalidArgumentException('Motivo de observación desconocido.');
        }
        foreach (['agentId' => $agentId, 'versionId' => $versionId, 'branchId' => $branchId] as $campo => $id) {
            if ($id !== null && ! VoiceIds::isProviderId($id)) {
                throw new InvalidArgumentException("{$campo} no tiene la forma de un id del proveedor.");
            }
        }
        if ($httpStatus !== null && ($httpStatus < 100 || $httpStatus > 599)) {
            throw new InvalidArgumentException('Estado HTTP fuera de rango.');
        }
        if (($outcome === self::OUTCOME_OBSERVED) !== ($projection !== null)) {
            throw new InvalidArgumentException('Hay proyección si y solo si el agente se observó.');
        }
        if ($outcome === self::OUTCOME_OBSERVED && ($agentId === null || $reason !== null)) {
            throw new InvalidArgumentException('Un agente observado lleva su id y ningún motivo.');
        }
        if ($outcome === self::OUTCOME_NOT_CONFIGURED && ($httpStatus !== null || $agentId !== null)) {
            throw new InvalidArgumentException('Sin red no hay estado HTTP ni id.');
        }

        $this->projectionHash = $projection === null ? null : VoiceCanonicalJson::hash($projection);
        $this->projection = new SensitiveParameterValue($projection);
    }

    /** @return array<string, mixed>|null EN CLARO: para comparar con la carga compilada, nunca para guardarla */
    public function projection(): ?array
    {
        return $this->projection->getValue();
    }

    /** ¿Coincide con la proyección compilada? false si no se observó. */
    public function matches(VoiceAgentPayload $payload): bool
    {
        return $this->projectionHash !== null && hash_equals($payload->projectionHash, $this->projectionHash);
    }

    /** @return array{outcome:string, agentId:?string, versionId:?string, branchId:?string, projectionHash:?string, httpStatus:?int, reason:?string} sin la proyección */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome,
            'agentId' => $this->agentId,
            'versionId' => $this->versionId,
            'branchId' => $this->branchId,
            'projectionHash' => $this->projectionHash,
            'httpStatus' => $this->httpStatus,
            'reason' => $this->reason,
        ];
    }
}
