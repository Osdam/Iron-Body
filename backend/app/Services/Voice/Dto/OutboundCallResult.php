<?php

namespace App\Services\Voice\Dto;

use InvalidArgumentException;

/**
 * El resultado de pedir UNA llamada saliente (contrato H8, D8-3 y D8-12). Solo
 * la clase del resultado, un motivo de un conjunto cerrado, los dos ids que
 * correlacionan la llamada y el estado HTTP: nunca el cuerpo, una cabecera, la
 * clave, el número ni el mensaje de una excepción.
 *
 * Resultados (y lo que hace con ellos la telefonía, D8-13):
 *  - `started`: el proveedor devolvió `conversation_id` y `callSid` → `dialing`.
 *  - `rejected`: el proveedor dijo que NO la inició (4xx, o 200 con
 *    `success = false`) → `failed_to_start`; la reserva se libera.
 *  - `ambiguous`: no se sabe si se marcó (tiempo agotado, sin conexión, 5xx,
 *    408, una respuesta 2xx ilegible o sin ids, o un fallo inesperado con la
 *    petición ya en marcha) → `ambiguous`; NUNCA se reintenta: la conciliación
 *    lo resuelve antes de cualquier reintento manual.
 *  - `not_attempted`: no salió nada a la red (las llamadas cerradas en el
 *    código o la configuración incompleta).
 *
 * D8-3 nombraba también `error`. No existe aquí a propósito: un fallo que no
 * prueba que la petición no salió no se puede tratar como «no se marcó», y uno
 * que lo prueba es `not_attempted`. Todo lo demás es `ambiguous`.
 */
final class OutboundCallResult
{
    public const OUTCOME_STARTED = 'started';

    public const OUTCOME_REJECTED = 'rejected';

    public const OUTCOME_AMBIGUOUS = 'ambiguous';

    public const OUTCOME_NOT_ATTEMPTED = 'not_attempted';

    public const OUTCOMES = [self::OUTCOME_STARTED, self::OUTCOME_REJECTED, self::OUTCOME_AMBIGUOUS, self::OUTCOME_NOT_ATTEMPTED];

    // not_attempted
    public const REASON_CALLS_DISABLED_IN_CODE = 'calls_disabled_in_code';

    public const REASON_NOT_CONFIGURED = 'not_configured';

    // rejected
    public const REASON_PROVIDER_DECLINED = 'provider_declined';

    public const REASON_CLIENT_ERROR = 'client_error';

    // ambiguous
    public const REASON_CONNECTION = 'connection';

    public const REASON_SERVER_ERROR = 'server_error';

    public const REASON_UNREADABLE_RESPONSE = 'unreadable_response';

    public const REASON_MISSING_IDS = 'missing_ids';

    public const REASON_UNEXPECTED = 'unexpected';

    /** Los motivos que admite cada resultado (`started` no lleva ninguno). */
    public const REASONS_BY_OUTCOME = [
        self::OUTCOME_STARTED => [],
        self::OUTCOME_REJECTED => [self::REASON_PROVIDER_DECLINED, self::REASON_CLIENT_ERROR],
        self::OUTCOME_AMBIGUOUS => [self::REASON_CONNECTION, self::REASON_SERVER_ERROR, self::REASON_UNREADABLE_RESPONSE, self::REASON_MISSING_IDS, self::REASON_UNEXPECTED],
        self::OUTCOME_NOT_ATTEMPTED => [self::REASON_CALLS_DISABLED_IN_CODE, self::REASON_NOT_CONFIGURED],
    ];

    /** @throws InvalidArgumentException */
    public function __construct(
        public readonly string $outcome,
        public readonly ?string $reason,
        public readonly ?string $conversationId,
        public readonly ?string $callSid,
        public readonly ?int $httpStatus,
    ) {
        if (! in_array($outcome, self::OUTCOMES, true)) {
            throw new InvalidArgumentException('Resultado de saliente desconocido.');
        }
        $admitidos = self::REASONS_BY_OUTCOME[$outcome];
        if ($admitidos === [] ? $reason !== null : ! in_array($reason, $admitidos, true)) {
            throw new InvalidArgumentException('El motivo no casa con el resultado de la saliente.');
        }
        if ($conversationId !== null && ! VoiceIds::isProviderId($conversationId)) {
            throw new InvalidArgumentException('conversationId no tiene la forma de un id del proveedor.');
        }
        if ($callSid !== null && ! VoiceIds::isCallSid($callSid)) {
            throw new InvalidArgumentException('callSid no tiene la forma CA + 32 hexadecimales.');
        }
        if ($httpStatus !== null && ($httpStatus < 100 || $httpStatus > 599)) {
            throw new InvalidArgumentException('Estado HTTP fuera de rango.');
        }
        if ($outcome === self::OUTCOME_STARTED && ($conversationId === null || $callSid === null)) {
            throw new InvalidArgumentException('Una saliente iniciada lleva conversationId y callSid.');
        }
        if ($outcome === self::OUTCOME_NOT_ATTEMPTED && ($httpStatus !== null || $conversationId !== null || $callSid !== null)) {
            throw new InvalidArgumentException('Sin red no hay estado HTTP ni ids.');
        }
    }

    /** @return array{outcome:string, reason:?string, conversationId:?string, callSid:?string, httpStatus:?int} */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome,
            'reason' => $this->reason,
            'conversationId' => $this->conversationId,
            'callSid' => $this->callSid,
            'httpStatus' => $this->httpStatus,
        ];
    }
}
