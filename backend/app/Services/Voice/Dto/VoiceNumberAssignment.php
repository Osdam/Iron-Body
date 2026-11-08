<?php

namespace App\Services\Voice\Dto;

use InvalidArgumentException;

/**
 * A qué agente apunta el número importado en el proveedor (contrato H8, D8-21;
 * Enmienda 2, E2-3; Enmienda 4, E4-8: la DERIVA DEL NÚMERO). Solo ids del
 * proveedor: el número de teléfono que trae la respuesta no entra aquí.
 *
 *  - `observed`: el número existe y se pudo leer; `agentId` es el agente
 *    asignado, o null si está sin asignar.
 *  - `not_found` (404), `auth_failed` (401/403), `unreachable` (sin conexión o
 *    tiempo agotado), `error` (otro estado o un cuerpo que no se puede leer).
 *  - `not_configured`: sin la bandera o sin todas las piezas; SIN RED. Es lo
 *    que da el nulo: «desconocido».
 *
 * {@see state()} lo resume: `assigned`, `unassigned` o `unknown` (todo lo que
 * no es `observed`).
 */
final class VoiceNumberAssignment
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

    public const REASONS = [self::REASON_INVALID_REQUEST, self::REASON_INVALID_RESPONSE];

    public const STATE_ASSIGNED = 'assigned';

    public const STATE_UNASSIGNED = 'unassigned';

    public const STATE_UNKNOWN = 'unknown';

    /** @throws InvalidArgumentException */
    public function __construct(
        public readonly string $outcome,
        public readonly ?string $phoneNumberId,
        public readonly ?string $agentId,
        public readonly ?int $httpStatus,
        public readonly ?string $reason,
    ) {
        if (! in_array($outcome, self::OUTCOMES, true)) {
            throw new InvalidArgumentException('Resultado de la lectura del número desconocido.');
        }
        if ($reason !== null && ! in_array($reason, self::REASONS, true)) {
            throw new InvalidArgumentException('Motivo de la lectura del número desconocido.');
        }
        foreach (['phoneNumberId' => $phoneNumberId, 'agentId' => $agentId] as $campo => $id) {
            if ($id !== null && ! VoiceIds::isProviderId($id)) {
                throw new InvalidArgumentException("{$campo} no tiene la forma de un id del proveedor.");
            }
        }
        if ($httpStatus !== null && ($httpStatus < 100 || $httpStatus > 599)) {
            throw new InvalidArgumentException('Estado HTTP fuera de rango.');
        }
        if ($outcome === self::OUTCOME_OBSERVED && ($phoneNumberId === null || $reason !== null)) {
            throw new InvalidArgumentException('Un número observado lleva su id y ningún motivo.');
        }
        if ($outcome !== self::OUTCOME_OBSERVED && $agentId !== null) {
            throw new InvalidArgumentException('Solo un número observado dice a qué agente apunta.');
        }
        if ($outcome === self::OUTCOME_NOT_CONFIGURED && ($httpStatus !== null || $phoneNumberId !== null)) {
            throw new InvalidArgumentException('Sin red no hay estado HTTP ni id.');
        }
    }

    /** Lo que da el nulo y todo lo que no se pudo leer sin red: «desconocido». */
    public static function unknown(): self
    {
        return new self(self::OUTCOME_NOT_CONFIGURED, null, null, null, null);
    }

    public function state(): string
    {
        return match (true) {
            $this->outcome !== self::OUTCOME_OBSERVED => self::STATE_UNKNOWN,
            $this->agentId === null => self::STATE_UNASSIGNED,
            default => self::STATE_ASSIGNED,
        };
    }

    /** @return array{outcome:string, state:string, phoneNumberId:?string, agentId:?string, httpStatus:?int, reason:?string} */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome,
            'state' => $this->state(),
            'phoneNumberId' => $this->phoneNumberId,
            'agentId' => $this->agentId,
            'httpStatus' => $this->httpStatus,
            'reason' => $this->reason,
        ];
    }
}
