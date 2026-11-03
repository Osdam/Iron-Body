<?php

namespace App\Services\Voice\Dto;

use InvalidArgumentException;

/**
 * El resultado de crear un agente o de asignar el número (contrato H8, D8-3 y
 * D8-7). Solo lleva la clase del resultado, los ids, el estado HTTP y un
 * motivo de un conjunto cerrado: NUNCA el cuerpo de la respuesta, una
 * cabecera, la clave ni el mensaje de una excepción.
 *
 * Resultados:
 *  - `created`: el agente existe (`agentId`), con sus herramientas (`toolIds`).
 *  - `assigned`: el número quedó con el agente pedido (`agentId`; null = sin
 *    agente), comprobado en la respuesta.
 *  - `rejected` (4xx), `not_found` (404), `auth_failed` (401/403).
 *  - `ambiguous`: tiempo agotado, sin conexión o 5xx con la petición ya
 *    enviada: el recurso puede existir. Nunca se reintenta a ciegas.
 *  - `error`: una respuesta 2xx que no se puede leer o que no casa.
 *  - `not_configured`: sin la bandera o sin todas las piezas; SIN RED.
 *  - `not_attempted`: una regla del código lo impide (p. ej. asignar un agente
 *    con las llamadas cerradas en el código); SIN RED.
 *
 * Si la creación falla a mitad, `toolIds` dice qué herramientas sí se crearon
 * (huérfanas, sin agente, inertes) y `reason` en qué paso falló.
 */
final class VoiceDeployResult
{
    public const OUTCOME_CREATED = 'created';

    public const OUTCOME_ASSIGNED = 'assigned';

    public const OUTCOME_REJECTED = 'rejected';

    public const OUTCOME_NOT_FOUND = 'not_found';

    public const OUTCOME_AUTH_FAILED = 'auth_failed';

    public const OUTCOME_AMBIGUOUS = 'ambiguous';

    public const OUTCOME_ERROR = 'error';

    public const OUTCOME_NOT_CONFIGURED = 'not_configured';

    public const OUTCOME_NOT_ATTEMPTED = 'not_attempted';

    public const OUTCOMES = [
        self::OUTCOME_CREATED, self::OUTCOME_ASSIGNED, self::OUTCOME_REJECTED, self::OUTCOME_NOT_FOUND, self::OUTCOME_AUTH_FAILED,
        self::OUTCOME_AMBIGUOUS, self::OUTCOME_ERROR, self::OUTCOME_NOT_CONFIGURED, self::OUTCOME_NOT_ATTEMPTED,
    ];

    /** Las llamadas están cerradas en el código (`VoiceCallPolicy::CALLS_ENABLED_IN_CODE`). */
    public const REASON_CALLS_DISABLED_IN_CODE = 'calls_disabled_in_code';

    /** Lo pedido no tiene forma válida (un id que no casa); no salió nada. */
    public const REASON_INVALID_REQUEST = 'invalid_request';

    /** Falló al crear una herramienta (el agente no se llegó a pedir). */
    public const REASON_TOOL_STEP = 'tool_step';

    /** Falló al crear el agente (las herramientas sí existen). */
    public const REASON_AGENT_STEP = 'agent_step';

    /** 2xx con un cuerpo que no se puede leer o sin el id esperado. */
    public const REASON_INVALID_RESPONSE = 'invalid_response';

    /** 2xx pero el número quedó con otro agente del pedido. */
    public const REASON_ASSIGNMENT_MISMATCH = 'assignment_mismatch';

    public const REASONS = [
        self::REASON_CALLS_DISABLED_IN_CODE, self::REASON_INVALID_REQUEST, self::REASON_TOOL_STEP, self::REASON_AGENT_STEP,
        self::REASON_INVALID_RESPONSE, self::REASON_ASSIGNMENT_MISMATCH,
    ];

    /**
     * @param  array<string, string>  $toolIds  nombre de la herramienta → id del proveedor
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        public readonly string $outcome,
        public readonly ?string $agentId,
        public readonly array $toolIds,
        public readonly ?int $httpStatus,
        public readonly ?string $reason,
    ) {
        if (! in_array($outcome, self::OUTCOMES, true)) {
            throw new InvalidArgumentException('Resultado de despliegue desconocido.');
        }
        if ($reason !== null && ! in_array($reason, self::REASONS, true)) {
            throw new InvalidArgumentException('Motivo de despliegue desconocido.');
        }
        if ($agentId !== null && ! VoiceIds::isProviderId($agentId)) {
            throw new InvalidArgumentException('agentId no tiene la forma de un id del proveedor.');
        }
        if ($toolIds !== [] && array_is_list($toolIds)) {
            throw new InvalidArgumentException('toolIds tiene que ser un mapa nombre → id.');
        }
        foreach ($toolIds as $nombre => $id) {
            if (! is_string($nombre) || ! in_array($nombre, VoiceAgentSpec::TOOLS, true) || ! VoiceIds::isProviderId($id)) {
                throw new InvalidArgumentException('toolIds lleva una herramienta fuera del catálogo o un id sin forma.');
            }
        }
        if ($httpStatus !== null && ($httpStatus < 100 || $httpStatus > 599)) {
            throw new InvalidArgumentException('Estado HTTP fuera de rango.');
        }
        if ($outcome === self::OUTCOME_CREATED && ($agentId === null || $reason !== null)) {
            throw new InvalidArgumentException('Un agente creado lleva su id y ningún motivo.');
        }
        if ($outcome === self::OUTCOME_ASSIGNED && $reason !== null) {
            throw new InvalidArgumentException('Una asignación hecha no lleva motivo.');
        }
        if (in_array($outcome, [self::OUTCOME_NOT_CONFIGURED, self::OUTCOME_NOT_ATTEMPTED], true) && ($httpStatus !== null || $toolIds !== [] || $agentId !== null)) {
            throw new InvalidArgumentException('Sin red no hay estado HTTP ni ids.');
        }
        if ($outcome === self::OUTCOME_NOT_ATTEMPTED && $reason === null) {
            throw new InvalidArgumentException('not_attempted dice por qué.');
        }
    }

    /** @return array{outcome:string, agentId:?string, toolIds:array<string,string>, httpStatus:?int, reason:?string} */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome,
            'agentId' => $this->agentId,
            'toolIds' => $this->toolIds,
            'httpStatus' => $this->httpStatus,
            'reason' => $this->reason,
        ];
    }
}
