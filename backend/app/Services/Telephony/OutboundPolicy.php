<?php

namespace App\Services\Telephony;

use App\Services\Voice\VoiceCallContext;
use App\Services\Voice\VoiceCallPolicy;
use InvalidArgumentException;

/**
 * La política de las SALIENTES: la única puerta (contrato H8, D8-12; fixture
 * `outbound-gate-cases.json` §decide). PURA: los hechos los calcula la puerta
 * (`OutboundGate`) dentro de su transacción; aquí no se lee configuración,
 * base, reloj ni red.
 *
 * COMPONE la del H6 sin tocarla: de `VoiceCallPolicy::decide()` toma SOLO las
 * puertas del código y del proveedor ({@see FROM_H6}): la constante, Twilio
 * Voice, el número y los tres estados de ElevenLabs. El destino, el origen y
 * el cupo tienen aquí su semántica del H8 (el origen `voice_contacts` es un
 * cambio deliberado del conjunto del H6, que sigue diciendo
 * `source_not_allowed` para todo lo que no es el registro de canarios).
 *
 * Acumula TODOS los motivos que apliquen, en el orden cerrado de
 * {@see REASONS} (DF-G1):
 *  - el código y el proveedor; `voice_number_is_whatsapp` solo si hay número;
 *    y con número, su IDENTIDAD vigilada (B8-g, SEC8-c B-1 y la decisión del
 *    lead): sin una conciliación del número importado de menos de 2 h que
 *    cuadre con `TWILIO_VOICE_FROM`, `number_unverified` (falla cerrado, como
 *    `elevenlabs_unverified` sin la marca de E4-6); si la reciente dice que
 *    no es ese número, `number_mismatch`;
 *  - la publicación (`agent_drift` solo con la publicación legible);
 *  - el destino: un origen fuera de {canary_registry, voice_contacts} da solo
 *    `source_not_allowed` (DF-G2); en `canary`, un canario de voz `active`;
 *    en `contacts`, un contacto vivo con consentimiento y el RNE vigente; las
 *    exclusiones, en los dos (DF-P6);
 *  - el calendario, los cupos (`daily_cap_reached` solo con un cupo > 0,
 *    DF-G3) y `duplicate_request` el último (DF-G5).
 *
 * `allowed` es exactamente «sin motivos». HTTP: 202 permitida; 200 si el único
 * motivo es `duplicate_request` (la misma fila); 409 en cualquier otro caso.
 */
final class OutboundPolicy
{
    public const REASONS = [
        'calls_disabled_in_code', 'outbound_disabled', 'twilio_voice_disabled', 'no_voice_number', 'voice_number_is_whatsapp', 'number_unverified', 'number_mismatch',
        'elevenlabs_not_configured', 'elevenlabs_disabled', 'elevenlabs_unverified',
        'no_publication', 'publication_unreadable', 'agent_drift', 'voice_braked',
        'source_not_allowed', 'not_an_active_voice_canary', 'contact_not_authorized', 'no_voice_consent', 'excluded', 'rne_check_missing',
        'outside_hours', 'holiday',
        'frequency_exceeded', 'concurrency_exceeded', 'daily_cap_zero', 'daily_cap_reached', 'budget_exhausted',
        'duplicate_request',
    ];

    /** Lo único que se toma de la política del H6: el código y el proveedor. */
    public const FROM_H6 = ['calls_disabled_in_code', 'twilio_voice_disabled', 'no_voice_number', 'elevenlabs_not_configured', 'elevenlabs_disabled', 'elevenlabs_unverified'];

    /**
     * B8-g: lo que la puerta sabe del número importado (el que de verdad llama),
     * por la última conciliación del número (`VoiceNumberGuard::policyCheck()`):
     * reciente y es `TWILIO_VOICE_FROM`; reciente y no lo es; o sin vigilar
     * (sin conciliación de menos de 2 h, o sin poder comparar).
     */
    public const NUMBER_VERIFIED = 'verified';

    public const NUMBER_MISMATCH = 'mismatch';

    public const NUMBER_UNVERIFIED = 'unverified';

    public const NUMBER_CHECKS = [self::NUMBER_VERIFIED, self::NUMBER_MISMATCH, self::NUMBER_UNVERIFIED];

    public const SCOPE_CANARY = 'canary';

    public const SCOPE_CONTACTS = 'contacts';

    public const SCOPES = [self::SCOPE_CANARY, self::SCOPE_CONTACTS];

    /** Los orígenes permitidos (D8-12). `marketing_calls` sigue prohibido. */
    public const ALLOWED_SOURCES = [VoiceCallContext::SOURCE_CANARY_REGISTRY, VoiceCallContext::SOURCE_VOICE_CONTACTS];

    public const REASON_DUPLICATE = 'duplicate_request';

    /** Los motivos del destino y del calendario ({@see targetReasons()}): los que el job vuelve a mirar antes de marcar. */
    public const TARGET_REASONS = [
        'source_not_allowed', 'not_an_active_voice_canary', 'contact_not_authorized', 'no_voice_consent', 'excluded', 'rne_check_missing',
        'outside_hours', 'holiday',
    ];

    /**
     * @param  array{outboundEnabled: bool, twilioVoiceEnabled: bool, voiceFromConfigured: bool, voiceNumberCollides: bool, numberCheck: string, providerState: string,
     *               publication: string, agentDrift: bool, braked: bool,
     *               scope: string, source: string, canary: ?string, contactAuthorized: bool, voiceConsent: bool, excluded: bool, rneCheckMissing: bool,
     *               outsideHours: bool, holiday: bool,
     *               frequencyExceeded: bool, activeOrDialing: int, maxConcurrent: int, dailyCap: int, placedToday: int, budgetFits: bool,
     *               duplicate: bool}  $f
     * @return array{allowed: bool, reasons: list<string>, http: int}
     *
     * @throws InvalidArgumentException con un hecho fuera de su conjunto cerrado
     */
    public static function decide(array $f): array
    {
        if (! in_array($f['scope'], self::SCOPES, true)) {
            throw new InvalidArgumentException('Alcance de salientes desconocido.');
        }
        if (! in_array($f['publication'], InboundPolicy::PUBLICATION_STATES, true)) {
            throw new InvalidArgumentException('Estado de publicación desconocido.');
        }
        if (! in_array($f['numberCheck'] ?? null, self::NUMBER_CHECKS, true)) {
            throw new InvalidArgumentException('Estado de la vigilancia del número desconocido.');
        }

        // 1. El código y el proveedor, de la política del H6 (que valida sus conjuntos cerrados).
        $h6 = VoiceCallPolicy::decide(new VoiceCallContext(
            twilioVoiceEnabled: $f['twilioVoiceEnabled'],
            twilioVoiceFromConfigured: $f['voiceFromConfigured'],
            providerState: $f['providerState'],
            canary: $f['canary'] ?? VoiceCallContext::CANARY_NOT_REGISTERED,
            source: $f['source'],
            dailyCap: $f['dailyCap'],
        ));
        $motivos = array_values(array_intersect($h6->reasons, self::FROM_H6));

        if ($f['outboundEnabled'] !== true) {
            $motivos[] = 'outbound_disabled';
        }
        if ($f['voiceFromConfigured'] === true && $f['voiceNumberCollides'] === true) {
            $motivos[] = 'voice_number_is_whatsapp';
        }
        // B8-g: ninguna saliente sin vigilar el número que de verdad llama (falla cerrado).
        if ($f['voiceFromConfigured'] === true && $f['numberCheck'] === self::NUMBER_UNVERIFIED) {
            $motivos[] = 'number_unverified';
        } elseif ($f['voiceFromConfigured'] === true && $f['numberCheck'] === self::NUMBER_MISMATCH) {
            $motivos[] = 'number_mismatch';
        }

        // 2. La publicación.
        if ($f['publication'] === InboundPolicy::PUBLICATION_NONE) {
            $motivos[] = 'no_publication';
        } elseif ($f['publication'] === InboundPolicy::PUBLICATION_UNREADABLE) {
            $motivos[] = 'publication_unreadable';
        } elseif ($f['agentDrift'] === true) {
            $motivos[] = 'agent_drift';
        }
        if ($f['braked'] === true) {
            $motivos[] = 'voice_braked';
        }

        // 3 y 4. El destino y el calendario (las mismas reglas que repite el job antes de marcar).
        array_push($motivos, ...self::targetReasons($f));

        // 5. Los cupos.
        if ($f['frequencyExceeded'] === true) {
            $motivos[] = 'frequency_exceeded';
        }
        if ($f['activeOrDialing'] >= $f['maxConcurrent']) {
            $motivos[] = 'concurrency_exceeded';
        }
        if ($f['dailyCap'] <= 0) {
            $motivos[] = 'daily_cap_zero';
        } elseif ($f['placedToday'] >= $f['dailyCap']) {
            $motivos[] = 'daily_cap_reached';
        }
        if ($f['budgetFits'] !== true) {
            $motivos[] = 'budget_exhausted';
        }

        // 6. La idempotencia, la última.
        if ($f['duplicate'] === true) {
            $motivos[] = self::REASON_DUPLICATE;
        }

        $motivos = array_values(array_intersect(self::REASONS, $motivos));

        return ['allowed' => $motivos === [], 'reasons' => $motivos, 'http' => self::httpStatus($motivos)];
    }

    /**
     * EL DESTINO Y EL CALENDARIO (los bloques 3 y 4 de {@see decide()}), con
     * su propia entrada para que el job de la saliente los repita justo antes
     * de marcar con EXACTAMENTE las mismas reglas (revisión de seguridad,
     * TOCTOU: entre encolar y marcar pueden revocarse el consentimiento,
     * añadirse una exclusión, caducar la consulta al RNE, cerrarse la ventana
     * o empezar un festivo). Pura.
     *
     * @param  array{scope: string, source: string, canary: ?string, contactAuthorized: bool, voiceConsent: bool, excluded: bool,
     *               rneCheckMissing: bool, outsideHours: bool, holiday: bool}  $f
     * @return list<string> los de {@see TARGET_REASONS}, en el orden de {@see REASONS}
     *
     * @throws InvalidArgumentException con un alcance desconocido
     */
    public static function targetReasons(array $f): array
    {
        if (! in_array($f['scope'], self::SCOPES, true)) {
            throw new InvalidArgumentException('Alcance de salientes desconocido.');
        }
        $motivos = [];

        // 3. El destino.
        if (! in_array($f['source'], self::ALLOWED_SOURCES, true)) {
            $motivos[] = 'source_not_allowed';
        } else {
            if ($f['scope'] === self::SCOPE_CANARY) {
                if ($f['source'] !== VoiceCallContext::SOURCE_CANARY_REGISTRY || $f['canary'] !== VoiceCallContext::CANARY_ACTIVE_VOICE) {
                    $motivos[] = 'not_an_active_voice_canary';
                }
            } else {
                if ($f['contactAuthorized'] !== true) {
                    $motivos[] = 'contact_not_authorized';
                }
                if ($f['voiceConsent'] !== true) {
                    $motivos[] = 'no_voice_consent';
                }
                if ($f['rneCheckMissing'] === true) {
                    $motivos[] = 'rne_check_missing';
                }
            }
            if ($f['excluded'] === true) {
                $motivos[] = 'excluded';
            }
        }

        // 4. El calendario.
        if ($f['outsideHours'] === true) {
            $motivos[] = 'outside_hours';
        }
        if ($f['holiday'] === true) {
            $motivos[] = 'holiday';
        }

        return array_values(array_intersect(self::REASONS, $motivos));
    }

    /** @param  list<string>  $reasons */
    public static function httpStatus(array $reasons): int
    {
        return match (true) {
            $reasons === [] => 202,
            $reasons === [self::REASON_DUPLICATE] => 200,
            default => 409,
        };
    }
}
