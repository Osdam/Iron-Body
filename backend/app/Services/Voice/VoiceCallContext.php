<?php

namespace App\Services\Voice;

use InvalidArgumentException;

/**
 * Lo que {@see VoiceCallPolicy::decide()} necesita saber, ya calculado por
 * quien llama (contrato H6 §I6.5 y §I6.6): la política es pura y no lee la
 * configuración, la base ni el reloj.
 *
 * El constructor rechaza cualquier valor fuera de sus conjuntos cerrados: un
 * texto desconocido no puede colarse como «otro» y abrir nada.
 */
final class VoiceCallContext
{
    /** Los cuatro estados de §I2 (`ELEVENLABS_VERIFIED` incluido: lo calcula quien guarda la evidencia). */
    public const PROVIDER_STATES = [
        VoiceProviderStatus::STATE_NOT_CONFIGURED,
        VoiceProviderStatus::STATE_DISABLED,
        VoiceProviderStatus::STATE_UNVERIFIED,
        VoiceProviderStatus::STATE_VERIFIED,
    ];

    public const CANARY_ACTIVE_VOICE = 'active_voice';

    public const CANARY_NOT_ACTIVE = 'not_active';

    public const CANARY_NOT_REGISTERED = 'not_registered';

    public const CANARY_STATES = [self::CANARY_ACTIVE_VOICE, self::CANARY_NOT_ACTIVE, self::CANARY_NOT_REGISTERED];

    /** El único origen que podrá alimentar un marcador: el registro de canarios. */
    public const SOURCE_CANARY_REGISTRY = 'canary_registry';

    /** Las filas pendientes de llamadas que crea el motor (T-7): nunca alimentan un marcador. */
    public const SOURCE_MARKETING_CALLS = 'marketing_calls';

    public const SOURCE_OTHER = 'other';

    public const SOURCES = [self::SOURCE_CANARY_REGISTRY, self::SOURCE_MARKETING_CALLS, self::SOURCE_OTHER];

    public function __construct(
        public readonly bool $twilioVoiceEnabled,
        public readonly bool $twilioVoiceFromConfigured,
        public readonly string $providerState,   // ELEVENLABS_NOT_CONFIGURED | _DISABLED | _UNVERIFIED | _VERIFIED
        public readonly string $canary,          // active_voice | not_active | not_registered
        public readonly string $source,          // canary_registry | marketing_calls | other
        public readonly int $dailyCap,
    ) {
        if (! in_array($providerState, self::PROVIDER_STATES, true)) {
            throw new InvalidArgumentException('Estado del proveedor de voz desconocido.');
        }
        if (! in_array($canary, self::CANARY_STATES, true)) {
            throw new InvalidArgumentException('Estado de canario de voz desconocido.');
        }
        if (! in_array($source, self::SOURCES, true)) {
            throw new InvalidArgumentException('Origen de llamada desconocido.');
        }
    }
}
