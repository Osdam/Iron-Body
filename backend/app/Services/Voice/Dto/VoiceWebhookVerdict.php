<?php

namespace App\Services\Voice\Dto;

use InvalidArgumentException;
use SensitiveParameterValue;

/**
 * El veredicto de un webhook post-llamada (contrato H8, D8-3, D8-8 «Después»,
 * D8-10 capa 4, D8-13 y D8-16): firma + sobre → un evento NEUTRO con lo que
 * el contrato enumera y nada más.
 *
 * Resultados:
 *  - `accepted`: la firma vale. `kind` dice qué evento es; `unknown` = un sobre
 *    válido que no se entiende (D8-16: 200 y se ignora).
 *  - `rejected`: la firma no vale (`reason` = el resultado de la firma) o el
 *    cuerpo pasa del tope (`too_large`). D8-16: 401.
 *  - `not_configured`: no hay secreto del webhook. Ninguna firma se acepta.
 *
 * LO QUE NUNCA LLEVA: la transcripción de la persona, el audio, los teléfonos
 * (los del sobre de Twilio incluidos), el análisis, el texto de
 * `termination_reason` ni las variables dinámicas (salvo `call_ref`, que es un
 * uuid opaco). Los ids que no tienen su forma se descartan (null).
 *
 * LO QUE SOLO VIVE EN MEMORIA: los turnos del AGENTE y los destinos a los que
 * pidió transferir, para la guarda posterior (D8-10 capa 4: «se recorre en
 * memoria … se guarda solo el veredicto»). Van envueltos en
 * `SensitiveParameterValue`: `dump()`, `var_dump()` y `print_r()` no los
 * enseñan, `serialize()` se niega y {@see toArray()} no los incluye. Quien los
 * lee los pasa por la guarda y los descarta.
 */
final class VoiceWebhookVerdict
{
    public const OUTCOME_ACCEPTED = 'accepted';

    public const OUTCOME_REJECTED = 'rejected';

    public const OUTCOME_NOT_CONFIGURED = 'not_configured';

    public const OUTCOMES = [self::OUTCOME_ACCEPTED, self::OUTCOME_REJECTED, self::OUTCOME_NOT_CONFIGURED];

    public const KIND_POST_CALL_TRANSCRIPTION = 'post_call_transcription';

    public const KIND_POST_CALL_AUDIO = 'post_call_audio';

    public const KIND_CALL_INITIATION_FAILURE = 'call_initiation_failure';

    public const KIND_UNKNOWN = 'unknown';

    public const KINDS = [self::KIND_POST_CALL_TRANSCRIPTION, self::KIND_POST_CALL_AUDIO, self::KIND_CALL_INITIATION_FAILURE, self::KIND_UNKNOWN];

    /** Los motivos: el resultado de la firma (`ok` solo con `accepted`), el tope y la falta de secreto. */
    public const REASON_OK = 'ok';

    public const REASON_TOO_LARGE = 'too_large';

    public const REASON_NOT_CONFIGURED = 'not_configured';

    public const SIGNATURE_FAILURES = ['missing_signature', 'malformed_signature', 'expired', 'from_future', 'mismatch'];

    /** `call_initiation_failure` (documentación oficial del 03-11-2026). */
    public const FAILURE_REASONS = ['busy', 'no-answer', 'unknown'];

    /** @var SensitiveParameterValue<list<string>> */
    private readonly SensitiveParameterValue $agentTurns;

    /** @var SensitiveParameterValue<list<string>> */
    private readonly SensitiveParameterValue $transferTargets;

    /**
     * @param  list<string>  $toolCalls  los nombres de las herramientas que el agente llamó, en orden
     * @param  list<string>  $agentTurns  el texto de cada turno del agente, en orden (solo en memoria)
     * @param  list<string>  $transferTargets  los números a los que pidió transferir (solo en memoria)
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        public readonly string $outcome,
        public readonly string $reason,
        public readonly ?string $kind,
        public readonly ?int $eventTimestamp,
        public readonly ?string $agentId,
        public readonly ?string $conversationId,
        public readonly ?string $versionId,
        public readonly ?string $branchId,
        public readonly ?string $callSid,
        public readonly ?string $callRef,
        public readonly ?int $durationSeconds,
        public readonly ?int $cost,
        public readonly ?string $failureReason,
        public readonly ?bool $hasAudio,
        public readonly array $toolCalls,
        #[\SensitiveParameter] array $agentTurns = [],
        #[\SensitiveParameter] array $transferTargets = [],
    ) {
        if (! in_array($outcome, self::OUTCOMES, true)) {
            throw new InvalidArgumentException('Resultado de webhook desconocido.');
        }
        $motivos = match ($outcome) {
            self::OUTCOME_ACCEPTED => [self::REASON_OK],
            self::OUTCOME_REJECTED => [...self::SIGNATURE_FAILURES, self::REASON_TOO_LARGE],
            default => [self::REASON_NOT_CONFIGURED],
        };
        if (! in_array($reason, $motivos, true)) {
            throw new InvalidArgumentException('El motivo no casa con el resultado del webhook.');
        }
        if (($outcome === self::OUTCOME_ACCEPTED) !== ($kind !== null) || ($kind !== null && ! in_array($kind, self::KINDS, true))) {
            throw new InvalidArgumentException('Hay tipo de evento si y solo si el webhook se acepta, y del conjunto cerrado.');
        }
        foreach (['agentId' => $agentId, 'conversationId' => $conversationId, 'versionId' => $versionId, 'branchId' => $branchId] as $campo => $id) {
            if ($id !== null && ! VoiceIds::isProviderId($id)) {
                throw new InvalidArgumentException("{$campo} no tiene la forma de un id del proveedor.");
            }
        }
        if ($callSid !== null && ! VoiceIds::isCallSid($callSid)) {
            throw new InvalidArgumentException('callSid no tiene la forma CA + 32 hexadecimales.');
        }
        if ($callRef !== null && ! VoiceIds::isUuid($callRef)) {
            throw new InvalidArgumentException('callRef tiene que ser un uuid.');
        }
        if (($durationSeconds !== null && $durationSeconds < 0) || ($cost !== null && $cost < 0) || ($eventTimestamp !== null && $eventTimestamp < 0)) {
            throw new InvalidArgumentException('Duración, coste y hora no pueden ser negativos.');
        }
        if ($failureReason !== null && ($kind !== self::KIND_CALL_INITIATION_FAILURE || ! in_array($failureReason, self::FAILURE_REASONS, true))) {
            throw new InvalidArgumentException('failureReason solo en call_initiation_failure y del conjunto cerrado.');
        }
        if (! array_is_list($toolCalls)) {
            throw new InvalidArgumentException('toolCalls tiene que ser una lista.');
        }
        foreach ($toolCalls as $nombre) {
            if (! is_string($nombre) || preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $nombre) !== 1) {
                throw new InvalidArgumentException('toolCalls lleva un nombre sin forma.');
            }
        }
        foreach ([$agentTurns, $transferTargets] as $lista) {
            if (! array_is_list($lista)) {
                throw new InvalidArgumentException('Los turnos y los destinos son listas.');
            }
            foreach ($lista as $texto) {
                if (! is_string($texto)) {
                    throw new InvalidArgumentException('Los turnos y los destinos son textos.');
                }
            }
        }
        if ($kind !== self::KIND_POST_CALL_TRANSCRIPTION && ($agentTurns !== [] || $transferTargets !== [] || $toolCalls !== [])) {
            throw new InvalidArgumentException('Solo una transcripción lleva turnos, herramientas o destinos.');
        }

        $this->agentTurns = new SensitiveParameterValue($agentTurns);
        $this->transferTargets = new SensitiveParameterValue($transferTargets);
    }

    public function accepted(): bool
    {
        return $this->outcome === self::OUTCOME_ACCEPTED;
    }

    /** @return list<string> EN MEMORIA: para la guarda posterior; nunca a la base, un registro ni una respuesta */
    public function agentTurns(): array
    {
        return $this->agentTurns->getValue();
    }

    /** @return list<string> EN MEMORIA: los números que el agente pidió a `transfer_to_number` */
    public function transferTargets(): array
    {
        return $this->transferTargets->getValue();
    }

    /** @return array<string, mixed> el evento neutro, sin turnos ni destinos */
    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome,
            'reason' => $this->reason,
            'kind' => $this->kind,
            'eventTimestamp' => $this->eventTimestamp,
            'agentId' => $this->agentId,
            'conversationId' => $this->conversationId,
            'versionId' => $this->versionId,
            'branchId' => $this->branchId,
            'callSid' => $this->callSid,
            'callRef' => $this->callRef,
            'durationSeconds' => $this->durationSeconds,
            'cost' => $this->cost,
            'failureReason' => $this->failureReason,
            'hasAudio' => $this->hasAudio,
            'toolCalls' => $this->toolCalls,
            'agentTurnCount' => count($this->agentTurns->getValue()),
        ];
    }
}
