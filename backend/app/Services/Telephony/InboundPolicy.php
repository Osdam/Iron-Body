<?php

namespace App\Services\Telephony;

use App\Services\Voice\Dto\VoiceAgentSpec;
use App\Services\Voice\VoiceCallContext;
use App\Services\Voice\VoiceCallPolicy;
use InvalidArgumentException;

/**
 * La política de las ENTRANTES (contrato H8, D8-11; fixture
 * `policy-tables.json` §inboundPolicy). PURA: los hechos los calcula quien
 * pregunta (la puerta de iniciación) y aquí no se lee configuración, base,
 * reloj ni red.
 *
 * Acumula TODOS los motivos que apliquen, en el orden cerrado de
 * {@see REASONS} (DF-P8, como `VoiceCallPolicy::decide()` del H6):
 *  - `inbound_disabled` (`VOICE_INBOUND_ENABLED=false`, o un alcance que no
 *    sea `canary`: nunca abre a todos por un valor mal escrito, DF-P11) y
 *    `calls_disabled_in_code` (la constante del H6: mientras sea false, nadie
 *    está autorizado);
 *  - `voice_braked`, `no_publication` y `publication_unreadable` (un fallo de
 *    la cadena DENIEGA, D8-8);
 *  - `not_an_active_voice_canary` (el «T3 de voz»: solo los canarios de voz
 *    `active`; un caller ID oculto no es un canario, DF-P9);
 *  - `concurrency_exceeded` (en curso ≥ el máximo) y `daily_cap_reached` (las
 *    autorizadas de hoy ≥ el cupo; con cupo 0, siempre: D8-11 no tiene
 *    `daily_cap_zero`, DF-P10).
 *
 * `authorized` es exactamente «sin motivos», y entonces `callerAuthorized` es
 * `si`; si no, `no`.
 */
final class InboundPolicy
{
    public const REASONS = ['inbound_disabled', 'calls_disabled_in_code', 'voice_braked', 'no_publication', 'publication_unreadable',
        'not_an_active_voice_canary', 'concurrency_exceeded', 'daily_cap_reached'];

    public const SCOPE_CANARY = 'canary';

    public const PUBLICATION_OK = 'ok';

    public const PUBLICATION_NONE = 'none';

    public const PUBLICATION_UNREADABLE = 'unreadable';

    public const PUBLICATION_STATES = [self::PUBLICATION_OK, self::PUBLICATION_NONE, self::PUBLICATION_UNREADABLE];

    /** El llamante: los tres estados de canario del H6 y el caller ID oculto o ausente. */
    public const CALLER_WITHHELD = 'withheld';

    public const CALLER_STATES = [...VoiceCallContext::CANARY_STATES, self::CALLER_WITHHELD];

    /**
     * @param  array{inboundEnabled: bool, scope: string, braked: bool, publication: string, caller: string, activeOrDialing: int, maxConcurrent: int, inboundToday: int, inboundDailyCap: int}  $facts
     * @return array{authorized: bool, reasons: list<string>, callerAuthorized: string}
     *
     * @throws InvalidArgumentException con un hecho fuera de su conjunto cerrado
     */
    public static function decide(array $facts): array
    {
        if (! in_array($facts['publication'], self::PUBLICATION_STATES, true)) {
            throw new InvalidArgumentException('Estado de publicación desconocido.');
        }
        if (! in_array($facts['caller'], self::CALLER_STATES, true)) {
            throw new InvalidArgumentException('Estado del llamante desconocido.');
        }

        $motivos = [];
        if ($facts['inboundEnabled'] !== true || $facts['scope'] !== self::SCOPE_CANARY) {
            $motivos[] = 'inbound_disabled';
        }
        if (VoiceCallPolicy::CALLS_ENABLED_IN_CODE !== true) {
            $motivos[] = 'calls_disabled_in_code';
        }
        if ($facts['braked'] === true) {
            $motivos[] = 'voice_braked';
        }
        if ($facts['publication'] === self::PUBLICATION_NONE) {
            $motivos[] = 'no_publication';
        }
        if ($facts['publication'] === self::PUBLICATION_UNREADABLE) {
            $motivos[] = 'publication_unreadable';
        }
        if ($facts['caller'] !== VoiceCallContext::CANARY_ACTIVE_VOICE) {
            $motivos[] = 'not_an_active_voice_canary';
        }
        if ($facts['activeOrDialing'] >= $facts['maxConcurrent']) {
            $motivos[] = 'concurrency_exceeded';
        }
        if ($facts['inboundToday'] >= $facts['inboundDailyCap']) {
            $motivos[] = 'daily_cap_reached';
        }

        $motivos = array_values(array_intersect(self::REASONS, $motivos));

        return [
            'authorized' => $motivos === [],
            'reasons' => $motivos,
            'callerAuthorized' => $motivos === [] ? VoiceAgentSpec::CALLER_AUTHORIZED : VoiceAgentSpec::CALLER_NOT_AUTHORIZED,
        ];
    }
}
