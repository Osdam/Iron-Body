<?php

namespace App\Services\Telephony;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * La guarda del número de voz (contrato H8, D8-5; fixture
 * `outbound-gate-cases.json` §whatsappGuard): el número dedicado de la voz
 * (`TWILIO_VOICE_FROM`) nunca puede ser el número del gimnasio en la
 * aplicación de mensajería del motor (`WHATSAPP_DISPLAY_PHONE`) ni uno de los
 * protegidos (`WHATSAPP_PROTECTED_NUMBERS`). Si coincide, la política dice
 * el motivo por nombre y el doctor también.
 *
 * Se comparan solo los DÍGITOS, como la lista de `config/meta.php`; un número
 * nacional de 10 dígitos (3… o 60…) gana el 57 delante (DF-G10). Es el ÚNICO
 * fichero de la telefonía que lee esas dos claves de Meta (D8-5), en solo
 * lectura; no lee nada más del motor ni de Meta.
 *
 * B8-g (SEC8-c B-1): la llamada sale con el número IMPORTADO en ElevenLabs (el
 * del `agent_phone_number_id`), no con la configuración. La conciliación
 * (`voice:reconcile --numbers`) lo observa, lo compara aquí con
 * `TWILIO_VOICE_FROM` y con los de la mensajería, frena si no cuadra y deja su
 * VEREDICTO en la caché ({@see rememberObservation()}; sin el número, ni su
 * huella). La política lee la configuración Y ese veredicto
 * ({@see collidesForPolicy()}) y, decisión del lead, FALLA CERRADO sin una
 * observación de menos de 2 h que cuadre ({@see policyCheck()}:
 * `number_unverified`): ninguna saliente sin vigilar el número que llama.
 *
 * B8-h (REV8-c M-1): la observación solo vale para el número que llama HOY. La
 * llamada sale con el `ELEVENLABS_AGENT_PHONE_NUMBER_ID` de la configuración
 * (lo lee el job al marcar), así que una observación de OTRO id (el mando lo
 * cambió y la caché aún guarda la de antes) o sin id no vigila nada:
 * `unverified` con su motivo ({@see policyVerdict()}). Así el id se puede
 * cambiar sin invalidar la caché: hasta la siguiente conciliación, la puerta y
 * el job dicen `number_unverified`.
 */
final class VoiceNumberGuard
{
    /** B8-h: no hay observación, o la que hay no se puede leer como tal (`lastObservation()` = null). */
    public const CHECK_REASON_NO_OBSERVATION = 'no_observation';

    /** B8-h: la observación tiene la forma del instante, pero no es una fecha real. */
    public const CHECK_REASON_UNREADABLE = 'unreadable';

    /** B8-h (REV8-c M-1): la observación es de otro `phone_number_id` que el configurado, o falta uno de los dos. */
    public const CHECK_REASON_OTHER_NUMBER_ID = 'other_number_id';

    /** B8-h: la observación dice ser de más allá de la holgura de relojes. */
    public const CHECK_REASON_FUTURE = 'future';

    /** B8-h: la observación tiene 2 h o más. */
    public const CHECK_REASON_STALE = 'stale';

    /** B8-h: reciente, pero sin poder comparar (el proveedor no dio el número o faltaba `TWILIO_VOICE_FROM`). */
    public const CHECK_REASON_UNCOMPARED = 'uncompared';

    /** B8-h: reciente, y el importado NO es `TWILIO_VOICE_FROM` (`mismatch`). */
    public const CHECK_REASON_NOT_VOICE_FROM = 'not_voice_from';

    /** Los motivos de {@see policyVerdict()}, en el orden en que se comprueban (cerrado; null = `verified`). */
    public const CHECK_REASONS = [self::CHECK_REASON_NO_OBSERVATION, self::CHECK_REASON_UNREADABLE, self::CHECK_REASON_OTHER_NUMBER_ID,
        self::CHECK_REASON_FUTURE, self::CHECK_REASON_STALE, self::CHECK_REASON_UNCOMPARED, self::CHECK_REASON_NOT_VOICE_FROM];

    /** La última observación del número importado (la deja la conciliación), sin el número. */
    public const OBSERVED_CACHE_KEY = 'voice:number:observed';

    /** Lo que se guarda la observación (para el doctor, también vieja). La puerta solo admite una reciente. */
    public const OBSERVED_CACHE_SECONDS = 86400;

    /** Dos pasadas de la conciliación horaria del número: más vieja, la puerta dice `number_unverified`. */
    public const OBSERVED_FRESH_SECONDS = 7200;

    /** Holgura de relojes hacia delante, como la marca de E4-6. */
    public const FUTURE_SKEW_SECONDS = 300;

    /** Pura (DF-G10). Sin número de voz no hay con qué chocar: false. */
    public static function collides(?string $voiceFrom, ?string $displayPhone, array|string|null $protectedNumbers): bool
    {
        $voz = self::digits((string) $voiceFrom);
        if ($voz === '') {
            return false;
        }
        $lista = is_array($protectedNumbers) ? $protectedNumbers : explode(',', (string) $protectedNumbers);
        $lista[] = (string) $displayPhone;
        foreach ($lista as $numero) {
            if (is_string($numero) && self::digits($numero) !== '' && hash_equals(self::digits($numero), $voz)) {
                return true;
            }
        }

        return false;
    }

    /** Con la configuración real: `voice.twilio.from` frente a los dos de Meta. */
    public static function collidesWithConfig(): bool
    {
        $desde = config('voice.twilio.from');

        return self::collidesWithMessaging(is_string($desde) ? $desde : null);
    }

    /** B8-g (B-1): un número cualquiera (el importado, observado) frente a los dos de Meta. */
    public static function collidesWithMessaging(#[\SensitiveParameter] ?string $number): bool
    {
        $visible = config('meta.whatsapp_display_phone');
        $protegidos = config('meta.protected_numbers');

        return self::collides($number, is_string($visible) ? $visible : null, is_array($protegidos) || is_string($protegidos) ? $protegidos : null);
    }

    /** B8-g (B-1): ¿son el mismo número? Por sus dígitos (DF-G10); con alguno vacío, no se sabe: null. */
    public static function sameNumber(#[\SensitiveParameter] ?string $a, #[\SensitiveParameter] ?string $b): ?bool
    {
        $x = self::digits((string) $a);
        $y = self::digits((string) $b);

        return $x === '' || $y === '' ? null : hash_equals($x, $y);
    }

    /**
     * B8-g (B-1): lo que vio la conciliación del número importado: si es el de
     * `TWILIO_VOICE_FROM` (null si no se pudo comparar) y si choca con la
     * mensajería. Sin el número ni su huella.
     */
    public static function rememberObservation(string $phoneNumberId, ?bool $matchesFrom, bool $collides): void
    {
        Cache::put(self::OBSERVED_CACHE_KEY, ['at' => now()->utc()->format('Y-m-d\TH:i:s\Z'), 'phoneNumberId' => $phoneNumberId,
            'matchesFrom' => $matchesFrom, 'collides' => $collides], self::OBSERVED_CACHE_SECONDS);
    }

    /** @return array{at: string, phoneNumberId: string, matchesFrom: ?bool, collides: bool}|null la última observación, o null si no hay una reciente */
    public static function lastObservation(): ?array
    {
        $o = Cache::get(self::OBSERVED_CACHE_KEY);

        return is_array($o) && is_string($o['at'] ?? null) && preg_match(VoiceProviderState::MARK_PATTERN, $o['at']) === 1
            && is_string($o['phoneNumberId'] ?? null) && is_bool($o['collides'] ?? null)
            && (($o['matchesFrom'] ?? null) === null || is_bool($o['matchesFrom'])) ? $o : null;
    }

    /**
     * B8-g (decisión del lead): lo que la puerta sabe del número importado,
     * para `OutboundPolicy` (`numberCheck`) y para la recomprobación del job.
     * Es `policyVerdict()['check']`.
     */
    public static function policyCheck(?CarbonInterface $now = null): string
    {
        return self::policyVerdict($now)['check'];
    }

    /**
     * Lo que la puerta concluye de la última observación, con su MOTIVO (uno de
     * {@see CHECK_REASONS}; null solo con `verified`), para el doctor y
     * `GET admin/voice/status` (`number.checkReason`). FALLA CERRADO, en este
     * orden:
     *  - sin observación (o ilegible): `unverified`;
     *  - con un instante que no es una fecha real: `unverified`;
     *  - B8-h (REV8-c M-1): de OTRO `phone_number_id` que el configurado ahora
     *    (`ELEVENLABS_AGENT_PHONE_NUMBER_ID`, sin espacios a los lados y
     *    comparado exacto), o con uno de los dos vacío: `unverified`. La
     *    conciliación guarda el id que observó; el job marca con el de la
     *    configuración: si no son el mismo, nadie vigiló el número que llama;
     *  - del futuro (más allá de la holgura) o de hace 2 h o más: `unverified`;
     *  - reciente sin poder comparar (el proveedor no dio el número o faltaba
     *    `TWILIO_VOICE_FROM`): `unverified`;
     *  - reciente y no es `TWILIO_VOICE_FROM`: `mismatch`;
     *  - reciente y es `TWILIO_VOICE_FROM`: `verified` (el choque con la
     *    mensajería lo dice aparte {@see collidesForPolicy()}).
     *
     * @return array{check: string, reason: ?string}
     */
    public static function policyVerdict(?CarbonInterface $now = null): array
    {
        $sinVigilar = static fn (string $motivo): array => ['check' => OutboundPolicy::NUMBER_UNVERIFIED, 'reason' => $motivo];
        $o = self::lastObservation();
        if ($o === null) {
            return $sinVigilar(self::CHECK_REASON_NO_OBSERVATION);
        }
        $en = CarbonImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $o['at'], 'UTC');
        if (! $en instanceof CarbonImmutable || $en->format('Y-m-d\TH:i:s\Z') !== $o['at']) {
            return $sinVigilar(self::CHECK_REASON_UNREADABLE);
        }
        $configurado = config('voice.elevenlabs.agent_phone_number_id');
        $configurado = is_string($configurado) ? trim($configurado) : '';
        if ($configurado === '' || $o['phoneNumberId'] !== $configurado) {
            return $sinVigilar(self::CHECK_REASON_OTHER_NUMBER_ID);
        }
        $edad = CarbonImmutable::instance($now ?? now())->getTimestamp() - $en->getTimestamp();
        if ($edad < -self::FUTURE_SKEW_SECONDS) {
            return $sinVigilar(self::CHECK_REASON_FUTURE);
        }
        if ($edad >= self::OBSERVED_FRESH_SECONDS) {
            return $sinVigilar(self::CHECK_REASON_STALE);
        }

        return match ($o['matchesFrom']) {
            true => ['check' => OutboundPolicy::NUMBER_VERIFIED, 'reason' => null],
            false => ['check' => OutboundPolicy::NUMBER_MISMATCH, 'reason' => self::CHECK_REASON_NOT_VOICE_FROM],
            default => $sinVigilar(self::CHECK_REASON_UNCOMPARED),
        };
    }

    /**
     * La guarda de la POLÍTICA (D8-5 + B-1): la configuración choca, o el número
     * importado que observó la conciliación choca. Un número importado que la
     * conciliación vio en WhatsApp cuenta aunque `TWILIO_VOICE_FROM` diga otro.
     */
    public static function collidesForPolicy(): bool
    {
        return self::collidesWithConfig() || (self::lastObservation()['collides'] ?? false) === true;
    }

    /** Los dígitos; un nacional de Colombia de 10 dígitos (3… o 60…) gana el 57. */
    public static function digits(string $raw): string
    {
        $digitos = (string) preg_replace('/\D+/', '', $raw);

        return preg_match('/^(3[0-9]{9}|60[0-9]{8})$/D', $digitos) === 1 ? '57'.$digitos : $digitos;
    }
}
