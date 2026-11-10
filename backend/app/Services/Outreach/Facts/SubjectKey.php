<?php

namespace App\Services\Outreach\Facts;

use InvalidArgumentException;

/**
 * La clave INTERNA de una persona en el H9 (contrato H9, D9-2, §3.10 y §6.2):
 * `m:<member_id>` si es socio, `l:<lead_id>` si solo es prospecto. Es la forma
 * del CHECK `^(m|l):[0-9]+$` de `outreach_enrollments.subject_key`.
 *
 * Nunca sale del servidor: fuera, la persona se nombra por su seudónimo
 * ({@see SubjectRef}). Quién es «la» persona lo decide {@see SubjectResolver}
 * (el socio manda si existe, como `EvaluateCommercialSubject::lockKey()`).
 */
final class SubjectKey
{
    public const MEMBER = 'm';

    public const LEAD = 'l';

    /** Estricta: sin ceros a la izquierda ni el id 0; con `D`, un salto de línea final no pasa. */
    public const PATTERN = '/^(m|l):[1-9][0-9]{0,18}$/D';

    public static function member(int $memberId): string
    {
        self::assertId($memberId);

        return self::MEMBER.':'.$memberId;
    }

    public static function lead(int $leadId): string
    {
        self::assertId($leadId);

        return self::LEAD.':'.$leadId;
    }

    public static function isValid(mixed $key): bool
    {
        return is_string($key) && preg_match(self::PATTERN, $key) === 1;
    }

    /**
     * @return array{kind: string, id: int}
     *
     * @throws InvalidArgumentException si no tiene la forma estricta
     */
    public static function parse(string $key): array
    {
        if (! self::isValid($key)) {
            // Sin el valor en el mensaje: puede acabar en un log.
            throw new InvalidArgumentException('La clave de persona no tiene la forma m:<id> o l:<id>.');
        }
        [$tipo, $id] = explode(':', $key, 2);

        return ['kind' => $tipo, 'id' => (int) $id];
    }

    private static function assertId(int $id): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException('El id de una persona tiene que ser positivo.');
        }
    }
}
