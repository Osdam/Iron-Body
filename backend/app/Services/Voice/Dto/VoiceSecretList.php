<?php

namespace App\Services\Voice\Dto;

use InvalidArgumentException;

/**
 * Los secretos guardados en el workspace del proveedor (auditoría B-T5;
 * contrato H8, D8-16 y D8-21, decisión (f)): SOLO sus ids, para comprobar que
 * los que cita el agente (las referencias de Laravel) existen. Ni nombres ni
 * valores: el proveedor no devuelve el valor y el nombre no hace falta.
 *
 * Los resultados son los de {@see VoiceWebhookList}. `truncated`: la lista
 * tenía más páginas de las que se leyeron (un id que no aparece no se puede
 * dar por ausente).
 */
final class VoiceSecretList
{
    public const OUTCOME_OBSERVED = VoiceWebhookList::OUTCOME_OBSERVED;

    public const OUTCOME_NOT_FOUND = VoiceWebhookList::OUTCOME_NOT_FOUND;

    public const OUTCOME_AUTH_FAILED = VoiceWebhookList::OUTCOME_AUTH_FAILED;

    public const OUTCOME_UNREACHABLE = VoiceWebhookList::OUTCOME_UNREACHABLE;

    public const OUTCOME_ERROR = VoiceWebhookList::OUTCOME_ERROR;

    public const OUTCOME_NOT_CONFIGURED = VoiceWebhookList::OUTCOME_NOT_CONFIGURED;

    public const REASON_INVALID_RESPONSE = VoiceWebhookList::REASON_INVALID_RESPONSE;

    /**
     * @param  list<string>  $ids
     *
     * @throws InvalidArgumentException
     */
    public function __construct(
        public readonly string $outcome,
        public readonly array $ids,
        public readonly bool $truncated,
        public readonly ?int $httpStatus,
        public readonly ?string $reason,
    ) {
        if (! in_array($outcome, VoiceWebhookList::OUTCOMES, true)) {
            throw new InvalidArgumentException('Resultado de la lectura de los secretos desconocido.');
        }
        if ($reason !== null && ! in_array($reason, VoiceWebhookList::REASONS, true)) {
            throw new InvalidArgumentException('Motivo de la lectura de los secretos desconocido.');
        }
        if ($httpStatus !== null && ($httpStatus < 100 || $httpStatus > 599)) {
            throw new InvalidArgumentException('Estado HTTP fuera de rango.');
        }
        if ($outcome !== self::OUTCOME_OBSERVED && ($ids !== [] || $truncated)) {
            throw new InvalidArgumentException('Solo una lista observada trae ids o dice que tiene más páginas.');
        }
        if ($outcome === self::OUTCOME_OBSERVED && $reason !== null) {
            throw new InvalidArgumentException('Una lista observada no lleva motivo.');
        }
        if ($outcome === self::OUTCOME_NOT_CONFIGURED && $httpStatus !== null) {
            throw new InvalidArgumentException('Sin red no hay estado HTTP.');
        }
        if (! array_is_list($ids)) {
            throw new InvalidArgumentException('Los ids van en una lista.');
        }
        foreach ($ids as $id) {
            if (! VoiceIds::isProviderId($id)) {
                throw new InvalidArgumentException('Un id de secreto no tiene la forma de un id del proveedor.');
            }
        }
    }

    /** Lo que da el nulo y todo lo que no se pudo leer sin red: «desconocido». */
    public static function unknown(): self
    {
        return new self(self::OUTCOME_NOT_CONFIGURED, [], false, null, null);
    }

    /** ¿Está ese id entre los leídos? (false también si la lista no se pudo leer). */
    public function has(string $id): bool
    {
        foreach ($this->ids as $leido) {
            if (hash_equals($leido, $id)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{outcome: string, count: int, truncated: bool, httpStatus: ?int, reason: ?string} */
    public function toArray(): array
    {
        return ['outcome' => $this->outcome, 'count' => count($this->ids), 'truncated' => $this->truncated, 'httpStatus' => $this->httpStatus, 'reason' => $this->reason];
    }
}
