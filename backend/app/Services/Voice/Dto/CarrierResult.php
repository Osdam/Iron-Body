<?php

namespace App\Services\Voice\Dto;

use InvalidArgumentException;

/**
 * El resultado de colgar una llamada en el operador (contrato H8, D8-3 y D8-20
 * nivel 0b). Solo la clase del resultado, el SID, el estado que dice el
 * operador, el estado HTTP y su código de error numérico: nunca el cuerpo, los
 * números de la llamada, la credencial ni el mensaje de una excepción.
 *
 * Resultados:
 *  - `requested`: el operador aceptó el cambio a `completed` (`providerStatus`
 *    es el que devolvió; el final llega por la conciliación). Pedirlo otra vez
 *    no hace daño: colgar es idempotente.
 *  - `not_found` (404), `rejected` (otro 4xx, con `providerErrorCode`),
 *    `auth_failed` (401/403), `unreachable` (sin conexión o tiempo agotado),
 *    `error` (5xx, otro estado o una respuesta ilegible: se puede repetir).
 *  - `not_configured`: sin la credencial completa; SIN RED.
 *  - `invalid_request`: el SID no tiene forma; SIN RED.
 */
final class CarrierResult
{
    public const OUTCOME_REQUESTED = 'requested';

    public const OUTCOME_NOT_FOUND = 'not_found';

    public const OUTCOME_REJECTED = 'rejected';

    public const OUTCOME_AUTH_FAILED = 'auth_failed';

    public const OUTCOME_UNREACHABLE = 'unreachable';

    public const OUTCOME_ERROR = 'error';

    public const OUTCOME_NOT_CONFIGURED = 'not_configured';

    public const OUTCOME_INVALID_REQUEST = 'invalid_request';

    public const OUTCOMES = [
        self::OUTCOME_REQUESTED, self::OUTCOME_NOT_FOUND, self::OUTCOME_REJECTED, self::OUTCOME_AUTH_FAILED,
        self::OUTCOME_UNREACHABLE, self::OUTCOME_ERROR, self::OUTCOME_NOT_CONFIGURED, self::OUTCOME_INVALID_REQUEST,
    ];

    /** Los estados de una llamada en el operador (recurso Call, documentación oficial del 03-11-2026). */
    public const PROVIDER_STATUSES = ['queued', 'ringing', 'in-progress', 'completed', 'busy', 'failed', 'no-answer', 'canceled'];

    /** @throws InvalidArgumentException */
    public function __construct(
        public readonly string $outcome,
        public readonly ?string $callSid,
        public readonly ?string $providerStatus,
        public readonly ?int $httpStatus,
        public readonly ?int $providerErrorCode,
    ) {
        if (! in_array($outcome, self::OUTCOMES, true)) {
            throw new InvalidArgumentException('Resultado del operador desconocido.');
        }
        if ($callSid !== null && ! VoiceIds::isCallSid($callSid)) {
            throw new InvalidArgumentException('callSid no tiene la forma CA + 32 hexadecimales.');
        }
        if ($providerStatus !== null && ! in_array($providerStatus, self::PROVIDER_STATUSES, true)) {
            throw new InvalidArgumentException('Estado de llamada del operador desconocido.');
        }
        if ($httpStatus !== null && ($httpStatus < 100 || $httpStatus > 599)) {
            throw new InvalidArgumentException('Estado HTTP fuera de rango.');
        }
        if ($providerErrorCode !== null && ($providerErrorCode < 0 || $providerErrorCode > 999999)) {
            throw new InvalidArgumentException('Código de error del operador fuera de rango.');
        }
        if (in_array($outcome, [self::OUTCOME_NOT_CONFIGURED, self::OUTCOME_INVALID_REQUEST], true) && ($httpStatus !== null || $providerStatus !== null)) {
            throw new InvalidArgumentException('Sin red no hay estado HTTP ni del operador.');
        }
    }

    /** @return array{outcome:string, callSid:?string, providerStatus:?string, httpStatus:?int, providerErrorCode:?int} */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome,
            'callSid' => $this->callSid,
            'providerStatus' => $this->providerStatus,
            'httpStatus' => $this->httpStatus,
            'providerErrorCode' => $this->providerErrorCode,
        ];
    }
}
