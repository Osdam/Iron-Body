<?php

namespace App\Services\Telephony;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * ¿Hay consentimiento de VOZ? (contrato H8, D8-18; Ley 2300, art. 2;
 * Enmienda 3, E3-4; fixture `policy-tables.json` §consent). Pura.
 *
 *  - Manda el último evento VÁLIDO por (`at`, `id`) (DF-P5).
 *  - `revoked` vale siempre: revocar nunca espera a nadie.
 *  - `granted` vale con `recorded_inbound_request` solo si la grabación está
 *    encendida [P-7, apagada en el H8]; con `written_form` o
 *    `admin_with_evidence`, solo si lo confirmó OTRA persona (E3-4: cuatro
 *    ojos en todo consentimiento cargado por una persona; sin confirmar no
 *    habilita salientes). Un `granted` que no vale no tapa uno anterior.
 *  - El consentimiento de WhatsApp del lead no es de voz: no entra aquí.
 *
 * DIFERENCIA DELIBERADA CON EL FIXTURE (pre-E3-4): sus casos con
 * `written_form` sin segunda persona esperan «sí»; E3-4 los supera. La
 * prueba de paridad los nombra.
 */
final class VoiceConsentRule
{
    public const KIND_GRANTED = 'granted';

    public const KIND_REVOKED = 'revoked';

    public const SOURCE_WRITTEN_FORM = 'written_form';

    public const SOURCE_RECORDED_INBOUND = 'recorded_inbound_request';

    public const SOURCE_ADMIN_WITH_EVIDENCE = 'admin_with_evidence';

    public const SOURCES = [self::SOURCE_WRITTEN_FORM, self::SOURCE_RECORDED_INBOUND, self::SOURCE_ADMIN_WITH_EVIDENCE];

    /** Las que carga una persona: necesitan cuatro ojos (E3-4). */
    public const PERSON_SOURCES = [self::SOURCE_WRITTEN_FORM, self::SOURCE_ADMIN_WITH_EVIDENCE];

    /**
     * @param  list<array{id: int, kind: string, source: string, at: DateTimeInterface|string, actorId: ?int, approvedBy: ?int}>  $events
     */
    public static function hasConsent(array $events, bool $recordingEnabled): bool
    {
        $validos = array_values(array_filter($events, static fn (array $e): bool => self::valid($e, $recordingEnabled)));
        usort($validos, static function (array $a, array $b): int {
            $porHora = self::instant($a['at']) <=> self::instant($b['at']);

            return $porHora !== 0 ? $porHora : ((int) $a['id'] <=> (int) $b['id']);
        });
        $ultimo = end($validos);

        return $ultimo !== false && $ultimo['kind'] === self::KIND_GRANTED;
    }

    /** @param  array{kind: string, source: string, actorId: ?int, approvedBy: ?int}  $e */
    public static function valid(array $e, bool $recordingEnabled): bool
    {
        if ($e['kind'] === self::KIND_REVOKED) {
            return true;
        }
        if ($e['kind'] !== self::KIND_GRANTED) {
            return false;
        }

        return match ($e['source']) {
            self::SOURCE_RECORDED_INBOUND => $recordingEnabled,
            self::SOURCE_WRITTEN_FORM, self::SOURCE_ADMIN_WITH_EVIDENCE => $e['approvedBy'] !== null && $e['approvedBy'] !== $e['actorId'],
            default => false,
        };
    }

    private static function instant(DateTimeInterface|string $at): DateTimeImmutable
    {
        return $at instanceof DateTimeInterface ? DateTimeImmutable::createFromInterface($at) : new DateTimeImmutable($at);
    }
}
